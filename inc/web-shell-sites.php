<?php
/**
 * Web Shell — site action endpoints (Phase 24).
 *
 * Per-site management calls exposed to the Sites tab in /web-shell/.
 * Permission for every write: the current user must be the site's
 * customer (wu_get_customer_by(user_id) returns the site's owner)
 * OR be a super-admin / manage_network user.
 *
 * Routes:
 *   GET    /gs/v1/web-shell/sites/{id}/status     → live status + last ping + meta
 *   POST   /gs/v1/web-shell/sites/{id}/clone      → spin up staging copy
 *   POST   /gs/v1/web-shell/sites/{id}/promote    → swap staging → live
 *   POST   /gs/v1/web-shell/sites/{id}/restart    → bounce the container
 *   DELETE /gs/v1/web-shell/sites/{id}/staging    → delete the staging copy
 *
 * Honesty about implementation state — only `status` + `staging delete`
 * fully execute today. `clone`, `promote`, and `restart` register their
 * routes + return a contract-shaped response so the UI shows a clear
 * "queued: connect <integration>" message instead of looking broken.
 * Wiring each into the matching WP Ultimo / GKE call is one PR per
 * action with its own security review.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'gs_ws_sites_register_rest' ) ) {

    function gs_ws_sites_register_rest() {
        $args_id = array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
                'sanitize_callback' => 'absint',
            ),
        );

        register_rest_route( 'gs/v1', '/web-shell/sites/(?P<id>\d+)/status', array(
            'methods'             => 'GET',
            'callback'            => 'gs_ws_sites_rest_status',
            'permission_callback' => 'gs_ws_sites_can_view',
            'args'                => $args_id,
        ) );

        register_rest_route( 'gs/v1', '/web-shell/sites/(?P<id>\d+)/clone', array(
            'methods'             => 'POST',
            'callback'            => 'gs_ws_sites_rest_clone',
            'permission_callback' => 'gs_ws_sites_can_write',
            'args'                => $args_id,
        ) );

        register_rest_route( 'gs/v1', '/web-shell/sites/(?P<id>\d+)/promote', array(
            'methods'             => 'POST',
            'callback'            => 'gs_ws_sites_rest_promote',
            'permission_callback' => 'gs_ws_sites_can_write',
            'args'                => $args_id,
        ) );

        register_rest_route( 'gs/v1', '/web-shell/sites/(?P<id>\d+)/restart', array(
            'methods'             => 'POST',
            'callback'            => 'gs_ws_sites_rest_restart',
            'permission_callback' => 'gs_ws_sites_can_write',
            'args'                => $args_id,
        ) );

        register_rest_route( 'gs/v1', '/web-shell/sites/(?P<id>\d+)/staging', array(
            'methods'             => 'DELETE',
            'callback'            => 'gs_ws_sites_rest_staging_delete',
            'permission_callback' => 'gs_ws_sites_can_write',
            'args'                => $args_id,
        ) );
    }
    add_action( 'rest_api_init', 'gs_ws_sites_register_rest' );
}

/* ────────────────── auth helpers ────────────────── */

if ( ! function_exists( 'gs_ws_sites_resolve' ) ) {

    function gs_ws_sites_resolve( $req ) {
        if ( ! is_user_logged_in() ) return null;
        $id = (int) ( is_object( $req ) ? $req->get_param( 'id' ) : $req );
        if ( $id <= 0 || ! function_exists( 'wu_get_site' ) ) return null;
        $site = wu_get_site( $id );
        return $site ?: null;
    }

    function gs_ws_sites_can_view( $req ) {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        if ( is_super_admin( $uid ) || user_can( $uid, 'manage_network' ) ) return true;
        $site = gs_ws_sites_resolve( $req );
        if ( ! $site ) return false;
        // Customer match
        if ( method_exists( $site, 'get_customer_id' ) ) {
            $owner = (int) $site->get_customer_id();
            if ( $owner > 0 && function_exists( 'wu_get_customer' ) ) {
                $cust = wu_get_customer( $owner );
                if ( $cust && method_exists( $cust, 'get_user_id' ) && (int) $cust->get_user_id() === $uid ) return true;
            }
        }
        // Linked BP group admin match
        $site_id = method_exists( $site, 'get_id' ) ? (int) $site->get_id() : 0;
        if ( $site_id > 0 && function_exists( 'groups_is_user_admin' ) ) {
            global $wpdb;
            $gm = $wpdb->base_prefix . 'bp_groups_groupmeta';
            $gid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT group_id FROM {$gm} WHERE meta_key = 'gdc_site_id' AND meta_value = %d LIMIT 1",
                $site_id
            ) );
            if ( $gid > 0 && groups_is_user_admin( $uid, $gid ) ) return true;
        }
        return false;
    }

    // Write perms are the same as view perms today — once we get
    // multi-tier roles (e.g. "deploy" capability), this is the seam.
    function gs_ws_sites_can_write( $req ) { return gs_ws_sites_can_view( $req ); }
}

/* ────────────────── status ────────────────── */

if ( ! function_exists( 'gs_ws_sites_rest_status' ) ) {

    function gs_ws_sites_rest_status( WP_REST_Request $req ) {
        $site = gs_ws_sites_resolve( $req );
        if ( ! $site ) return new WP_Error( 'no_site', 'Site not found.', array( 'status' => 404 ) );

        $hostname = method_exists( $site, 'get_meta' ) ? (string) $site->get_meta( 'gdc_container_hostname', '' ) : '';
        $install_id = method_exists( $site, 'get_meta' ) ? (string) $site->get_meta( 'gdc_install_id', '' ) : '';
        $staging_of = method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_staging_of', 0 ) : 0;
        $staging_id = method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_staging_site_id', 0 ) : 0;

        // HTTP probe: try the site's home_url with a short timeout.
        // Counts as "responding" when we get any 2xx/3xx/401/403 — those
        // all mean the container served bytes; only timeouts / 5xx are
        // real outages.
        $probe = array( 'reachable' => false, 'http_status' => null, 'ms' => null );
        if ( $hostname !== '' ) {
            $t0 = microtime( true );
            $resp = wp_remote_head( 'https://' . $hostname . '/', array( 'timeout' => 4, 'redirection' => 0, 'sslverify' => false ) );
            $probe['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
            if ( ! is_wp_error( $resp ) ) {
                $code = (int) wp_remote_retrieve_response_code( $resp );
                $probe['http_status'] = $code;
                $probe['reachable']   = ( $code > 0 && $code < 500 );
            }
        }

        return array(
            'site_id'    => method_exists( $site, 'get_id' ) ? (int) $site->get_id() : 0,
            'title'      => method_exists( $site, 'get_title' ) ? (string) $site->get_title() : '',
            'host'       => $hostname,
            'install_id' => $install_id,
            'url'        => $hostname !== '' ? 'https://' . $hostname . '/' : '',
            'admin_url'  => $hostname !== '' ? 'https://' . $hostname . '/wp-admin/' : '',
            'is_staging' => $staging_of > 0,
            'staging_of' => $staging_of ?: null,
            'staging_id' => $staging_id ?: null,
            'probe'      => $probe,
            'checked_at' => gmdate( 'c' ),
        );
    }
}

/* ────────────────── clone (scaffolded) ────────────────── */

if ( ! function_exists( 'gs_ws_sites_rest_clone' ) ) {

    function gs_ws_sites_rest_clone( WP_REST_Request $req ) {
        $site = gs_ws_sites_resolve( $req );
        if ( ! $site ) return new WP_Error( 'no_site', 'Site not found.', array( 'status' => 404 ) );

        $existing = method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_staging_site_id', 0 ) : 0;
        if ( $existing > 0 ) {
            return array(
                'status'          => 'exists',
                'staging_site_id' => $existing,
                'message'         => 'A staging copy already exists. Tear it down before cloning fresh.',
            );
        }

        // The full clone is a multi-step pipeline:
        //   1. wu_create_site() from the source's template
        //   2. rsync /wp-content + mysqldump source DB → staging DB
        //   3. WP-CLI search-replace live host → staging host in serialized data
        //   4. Stamp gdc_staging_of + gdc_staging_site_id meta on both sides
        // Each step is its own can of worms; the contract-shape response
        // here lets the UI surface a clear "queued — admin needs to wire
        // the Ultimo clone pipeline" message rather than silently failing.
        return array(
            'status'     => 'pending_integration',
            'integration'=> 'wp-ultimo-site-duplication',
            'message'    => 'Clone endpoint registered + permission-gated. Wire the Ultimo duplication pipeline (or the existing gdc-app-provisioning helper) into the body of gs_ws_sites_rest_clone() to fully execute.',
            'next_step'  => array(
                'description' => 'Call wu_create_site() from the source template, then run rsync + mysqldump + WP-CLI search-replace from a Cloud Build step. Return { status: "cloning", staging_site_id, queue_token } so the UI can poll.',
            ),
        );
    }
}

/* ────────────────── promote (scaffolded) ────────────────── */

if ( ! function_exists( 'gs_ws_sites_rest_promote' ) ) {

    function gs_ws_sites_rest_promote( WP_REST_Request $req ) {
        $site = gs_ws_sites_resolve( $req );
        if ( ! $site ) return new WP_Error( 'no_site', 'Site not found.', array( 'status' => 404 ) );
        $is_staging = method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_staging_of', 0 ) : 0;
        if ( $is_staging <= 0 ) {
            return new WP_Error( 'not_staging', 'Only staging sites can be promoted. This site is the live copy.', array( 'status' => 400 ) );
        }
        return array(
            'status'      => 'pending_integration',
            'integration' => 'wp-ultimo-domain-swap',
            'message'     => 'Promote endpoint registered + permission-gated. Implement the live↔staging hostname swap in the body of gs_ws_sites_rest_promote() (wu_update_site() on both rows + DNS record patch + container relabel).',
        );
    }
}

/* ────────────────── restart (scaffolded with hook) ────────────────── */

if ( ! function_exists( 'gs_ws_sites_rest_restart' ) ) {

    function gs_ws_sites_rest_restart( WP_REST_Request $req ) {
        $site = gs_ws_sites_resolve( $req );
        if ( ! $site ) return new WP_Error( 'no_site', 'Site not found.', array( 'status' => 404 ) );
        $hostname = method_exists( $site, 'get_meta' ) ? (string) $site->get_meta( 'gdc_container_hostname', '' ) : '';
        if ( $hostname === '' ) {
            return new WP_Error( 'no_container', 'Site is not hosted in a container — restart is a no-op.', array( 'status' => 400 ) );
        }

        // Fire a filter so a separate companion plugin can wire the
        // actual `kubectl delete pod` (or Ultimo container-bounce). The
        // filter MUST return the same array shape — we just pass it
        // through. Default behavior is to enqueue a "restart-requested"
        // event in site_option `gs_ws_site_restart_queue` for a cron
        // job (or the PTY service) to pick up.
        $queue = (array) get_site_option( 'gs_ws_site_restart_queue', array() );
        $queue[] = array(
            'site_id'      => method_exists( $site, 'get_id' ) ? (int) $site->get_id() : 0,
            'hostname'     => $hostname,
            'requested_by' => get_current_user_id(),
            'requested_at' => time(),
        );
        // Cap queue at 200 — older entries roll off so a misbehaving UI
        // can't infinitely grow the option.
        if ( count( $queue ) > 200 ) $queue = array_slice( $queue, -200 );
        update_site_option( 'gs_ws_site_restart_queue', $queue );

        $result = array(
            'status'   => 'queued',
            'message'  => 'Restart enqueued — a worker will bounce the container on its next tick. Hook gs_ws_site_restart filter to execute immediately.',
            'queue_at' => gmdate( 'c' ),
        );
        return apply_filters( 'gs_ws_site_restart', $result, $site, $req );
    }
}

/* ────────────────── staging teardown (real) ────────────────── */

if ( ! function_exists( 'gs_ws_sites_rest_staging_delete' ) ) {

    function gs_ws_sites_rest_staging_delete( WP_REST_Request $req ) {
        $site = gs_ws_sites_resolve( $req );
        if ( ! $site ) return new WP_Error( 'no_site', 'Site not found.', array( 'status' => 404 ) );

        // Two valid call shapes — the user might call it from the LIVE
        // site row (delete its staging) or from the STAGING site row
        // directly (delete this).
        $is_staging = method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_staging_of', 0 ) : 0;
        $staging_id = $is_staging > 0
            ? ( method_exists( $site, 'get_id' ) ? (int) $site->get_id() : 0 )
            : ( method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_staging_site_id', 0 ) : 0 );

        if ( $staging_id <= 0 ) {
            return new WP_Error( 'no_staging', 'No staging copy is linked to this site.', array( 'status' => 404 ) );
        }

        $live_id = $is_staging > 0 ? $is_staging : ( method_exists( $site, 'get_id' ) ? (int) $site->get_id() : 0 );

        if ( ! function_exists( 'wu_delete_site' ) ) {
            return new WP_Error( 'no_ultimo', 'WP Ultimo unavailable.', array( 'status' => 503 ) );
        }

        $deleted = (bool) wu_delete_site( $staging_id );
        if ( $deleted && $live_id > 0 && function_exists( 'wu_get_site' ) ) {
            $live = wu_get_site( $live_id );
            if ( $live && method_exists( $live, 'delete_meta' ) ) {
                $live->delete_meta( 'gdc_staging_site_id' );
            }
        }

        return array(
            'deleted'       => $deleted,
            'staging_id'    => $staging_id,
            'live_id'       => $live_id,
        );
    }
}

// Allowlist past the network REST gate.
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! is_wp_error( $result ) ) return $result;
    $route = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( $route !== '' && preg_match( '#/gs/v1/web-shell/sites/\d+/(status|clone|promote|restart|staging)$#', $route ) ) {
        return null;
    }
    return $result;
}, 100 );
