<?php
/**
 * Customer-side dashboard membership panel.
 *
 * Renders the same "membership details" UI on every site type
 * (networked subsite, server-hosted, self-hosted, container) once
 * the user is OAuth-paired with gend.me. Replaces the old four
 * separate cards (App Builder Membership, Account Owner, Domain,
 * Plan Management) with the unified popup layout used on
 * gend.me's /my-account/membership/{id}/ page:
 *
 *   ┌── Header: site title • status badge • Open App • 5-stage progress
 *   ┌── 2-card grid: Membership ▪ Integration Hub (Feature Access now sits atop the Feature Suite tab)
 *   └── Tabs: Orders ▪ Domain ▪ Backups (lazy-loaded inline AJAX)
 *
 * Inline actions:
 *   - Plan upgrade: opens gend.me's checkout in a popup window. On
 *     close (or post-message), the container refreshes the cached
 *     membership payload so plan/price changes appear immediately.
 *   - Domain add/verify/remove: container-side AJAX → install-token
 *     REST proxy → gend.me's /install/{id}/domains/{action}.
 *   - Backup now / restore: same proxy pattern.
 *
 * Auth model: install_token (set by oauth-login.php's auto-pair
 * handshake on the owner's first OAuth login) is the bearer for all
 * gend.me-bound traffic. Container-side AJAX is gated by the standard
 * WP nonce + manage_options capability.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_gs_compute_gas_devices', function () {
    check_ajax_referer( 'gs_membership_action' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You are not authorized to view connected devices.', 'gend-society' ) ), 403 );
    }
    if ( ! function_exists( 'psoo_device_get_records' ) ) {
        wp_send_json_error( array( 'message' => __( 'The connected-device service is unavailable.', 'gend-society' ) ), 503 );
    }
    $owner_ids = array( get_current_user_id() );
    $site = function_exists( 'wu_get_site' ) ? wu_get_site( get_current_blog_id() ) : null;
    $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    if ( ! $group_id ) {
        $group_id = $site && method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_bp_group_id', 0 ) : 0;
    }
    if ( $group_id > 0 && function_exists( 'psoo_group_device_owner_uids' ) ) {
        $owner_ids = psoo_group_device_owner_uids( $group_id );
    }
    $devices = array();
    foreach ( $owner_ids as $owner_id ) {
        foreach ( psoo_device_get_records( (int) $owner_id ) as $record ) {
            if ( function_exists( 'psoo_device_online' ) ) {
                $record['online'] = psoo_device_online( $record );
            }
            $record['owner'] = (int) $owner_id;
            $devices[ (string) ( $record['device_id'] ?? wp_generate_uuid4() ) ] = $record;
        }
    }
    wp_send_json_success( array( 'devices' => array_values( $devices ) ) );
} );

const GS_REMOTE_MEMBERSHIP_CACHE_OPTION         = 'gs_remote_membership_cache';
const GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION = 'gs_remote_membership_cache_expires';
const GS_REMOTE_MEMBERSHIP_DEFAULT_TTL          = 5 * MINUTE_IN_SECONDS;

// ────────────────────────────────────────────────────────────────────────
// Remote fetch + caching
// ────────────────────────────────────────────────────────────────────────

function gs_remote_membership_get_cached() {
    $expires = (int) get_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION, 0 );
    if ( $expires > time() ) {
        $cached = get_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, null );
        if ( is_array( $cached ) ) return $cached;
    }
    $fresh = gs_remote_membership_fetch();
    if ( is_array( $fresh ) ) {
        $ttl = isset( $fresh['cache_seconds'] ) ? max( 60, (int) $fresh['cache_seconds'] ) : GS_REMOTE_MEMBERSHIP_DEFAULT_TTL;
        update_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, $fresh, false );
        update_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION, time() + $ttl, false );
        return $fresh;
    }
    $cached = get_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, null );
    return is_array( $cached ) ? $cached : null;
}

function gs_remote_membership_fetch() {
    $install_id    = (string) get_option( 'gs_install_id', '' );
    $install_token = (string) get_option( 'gs_install_token', '' );
    $gend_base     = (string) get_option( 'gs_gend_base_url', '' );
    if ( $install_id === '' || $install_token === '' || $gend_base === '' ) return null;

    $endpoint = trailingslashit( $gend_base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/membership';
    $response = wp_remote_get( $endpoint, array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $install_token,
            'Accept'        => 'application/json',
        ),
    ) );
    if ( is_wp_error( $response ) ) return null;
    if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
    $raw   = (string) wp_remote_retrieve_body( $response );
    $clean = trim( str_replace( "\xEF\xBB\xBF", '', $raw ) );
    $data  = json_decode( $clean, true );
    if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $clean, $m ) ) {
        $data = json_decode( $m[1], true );
    }
    return is_array( $data ) ? $data : null;
}

function gs_remote_membership_invalidate() {
    delete_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION );
}
add_action( 'wp_login', 'gs_remote_membership_invalidate' );
add_action( 'gs_remote_membership_invalidate', 'gs_remote_membership_invalidate' );

// ────────────────────────────────────────────────────────────────────────
// Container-side AJAX proxies → gend.me install-token REST endpoints
// ────────────────────────────────────────────────────────────────────────

/**
 * Proxy a POST or GET to gend.me with the local install_token. Used by
 * every gs_membership_* AJAX action so we don't expose the token to
 * the browser.
 *
 * @param string $path  Path under /wp-json/gdc-app-manager/v1/install/{install_id}/
 *                      e.g. "backups/now", "domains/add"
 * @param array  $body  Body params (POST). Empty for GET.
 * @param string $method "POST" or "GET"
 * @return array|\WP_Error
 */
function gs_remote_membership_call( string $path, array $body = array(), string $method = 'POST' ) {
    $install_id    = (string) get_option( 'gs_install_id', '' );
    $install_token = (string) get_option( 'gs_install_token', '' );
    $gend_base     = (string) get_option( 'gs_gend_base_url', '' );
    if ( $install_id === '' || $install_token === '' || $gend_base === '' ) {
        return new WP_Error( 'not_paired', __( 'This install is not paired with gend.me. Sign in via OAuth first.', 'gend-society' ) );
    }
    $endpoint = trailingslashit( $gend_base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/' . ltrim( $path, '/' );

    $args = array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $install_token,
            'Accept'        => 'application/json',
        ),
    );
    if ( $method === 'POST' ) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body']                    = wp_json_encode( $body );
        $resp = wp_remote_post( $endpoint, $args );
    } else {
        // $body was previously silently dropped for GET calls - every
        // existing caller passes an empty array, so this is a no-op for
        // them and only takes effect for a caller that actually needs a
        // query param (e.g. membership/plan-options?resource=server).
        $resp = wp_remote_get( $body ? add_query_arg( $body, $endpoint ) : $endpoint, $args );
    }
    if ( is_wp_error( $resp ) ) return $resp;

    $raw   = (string) wp_remote_retrieve_body( $resp );
    $clean = trim( str_replace( "\xEF\xBB\xBF", '', $raw ) );
    $data  = json_decode( $clean, true );
    $code  = (int) wp_remote_retrieve_response_code( $resp );
    if ( $code < 200 || $code >= 300 ) {
        $msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : substr( $clean, 0, 200 );
        return new WP_Error( 'remote_' . $code, $msg, array( 'status' => $code ) );
    }
    return is_array( $data ) ? $data : array();
}

function gs_membership_ajax_authorize() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
    }
    check_ajax_referer( 'gs_membership_action', 'nonce' );
}

add_action( 'wp_ajax_gs_membership_backup_now', function () {
    gs_membership_ajax_authorize();
    $r = gs_remote_membership_call( 'backups/now' );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_backup_restore', function () {
    gs_membership_ajax_authorize();
    $bid = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
    if ( $bid <= 0 ) wp_send_json_error( array( 'message' => __( 'backup_id required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'backups/restore', array( 'backup_id' => $bid ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_add', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'domains/add', array( 'domain' => $domain ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_verify', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'domains/verify', array( 'domain' => $domain ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_remove', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'domains/remove', array( 'domain' => $domain ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

// ─── Native (no-iframe) change-plan picker: list options + resolve the
// checkout URL for a switch. Both proxy to gend.me's install-token REST
// routes (gdc-self-hosted-handshake.php). Dashboard-group plans by
// default; an optional resource= (server/media/database/codebase/
// backups) switches to that hosting-group resource-upgrade type's
// plans instead - same hub endpoint, now hosting-aware (see
// gdc_self_hosted_rest_plan_options() on the hub). Replaces the old
// popup-window + embedded-iframe checkout, which blanked because a
// cross-origin iframe can't carry gend.me's session cookie.
add_action( 'wp_ajax_gs_membership_plan_options', function () {
    gs_membership_ajax_authorize();
    $resource = isset( $_POST['resource'] ) ? sanitize_key( wp_unslash( $_POST['resource'] ) ) : '';
    $params   = $resource !== '' ? array( 'resource' => $resource ) : array();
    $r = gs_remote_membership_call( 'membership/plan-options', $params, 'GET' );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_change_plan', function () {
    gs_membership_ajax_authorize();
    $plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
    if ( ! $plan_id ) wp_send_json_error( array( 'message' => __( 'Please select a plan.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'membership/change-plan', array( 'plan_id' => $plan_id ), 'POST' );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

// ─── Phase 72-02: Connect-a-Domain wizard AJAX proxies ─────────────────
// Each handler wraps gs_remote_membership_call to the new hosting/domains/* REST
// routes from Phase 72-01. Error responses preserve code+status so wizard JS can
// distinguish e.g. 409 import_review_required from generic 500.

add_action( 'wp_ajax_gs_membership_domain_connect', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) {
        wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ), 'code' => 'missing_domain' ), 400 );
    }
    $r = gs_remote_membership_call( 'hosting/domains/connect', array( 'host' => $domain ), 'POST' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_import_records', function () {
    gs_membership_ajax_authorize();
    $zone_id = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    if ( $zone_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'zone_id required.', 'gend-society' ), 'code' => 'missing_zone_id' ), 400 );
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/import-records', array(), 'POST' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_records', function () {
    gs_membership_ajax_authorize();
    $zone_id = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    if ( $zone_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'zone_id required.', 'gend-society' ), 'code' => 'missing_zone_id' ), 400 );
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/records', array(), 'GET' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    // ── Phase 73-02 last_change enrichment ─────────────────────────────
    // Gated behind include_last_change=1 so the Phase 72 wizard's Step 2 records-list
    // call (which does NOT set this param) keeps receiving the bare-array shape it
    // expects. Only records-editor.js (73-02) sets include_last_change=1 and gets
    // the wrapped shape {records:[...], last_change:{...}|null}.
    if ( ! empty( $_POST['include_last_change'] ) && is_array( $r ) ) {
        global $wpdb;
        $audit_table = $wpdb->base_prefix . 'gend_domain_audit';
        $install_id  = (string) get_option( 'gs_install_id', '' );
        $zone_host   = isset( $_POST['zone_host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['zone_host'] ) ) : '';
        $last_change = null;
        if ( $install_id !== '' && $zone_host !== '' ) {
            // Gend_Domain_Audit::ACTION_RECORD_CHANGED = 'record.changed' (73-01 SUMMARY).
            // Cross-tenant guard at SQL WHERE — install_id + host match in the SELECT itself,
            // defense in depth on top of Repository::get_zone_by_id IDOR check.
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, action, before_json, after_json, created_at FROM {$audit_table}
                 WHERE install_id = %s AND host = %s AND action = %s
                 ORDER BY created_at DESC LIMIT 1",
                $install_id, $zone_host, 'record.changed'
            ), ARRAY_A );
            if ( is_array( $row ) ) {
                $after = json_decode( isset( $row['after_json'] ) ? (string) $row['after_json'] : '', true );
                $last_change = array(
                    'audit_id'   => (int) $row['id'],
                    'op'         => is_array( $after ) && isset( $after['op'] ) ? (string) $after['op'] : '',
                    'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
                );
            }
        }
        $r = array(
            'records'     => $r,
            'last_change' => $last_change,
        );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_get_nameservers', function () {
    gs_membership_ajax_authorize();
    $zone_id = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    if ( $zone_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'zone_id required.', 'gend-society' ), 'code' => 'missing_zone_id' ), 400 );
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/nameservers', array(), 'GET' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        // CRITICAL: 409 import_review_required surfaces verbatim to wizard JS which jumps UI back to Step 2.
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_get_status', function () {
    gs_membership_ajax_authorize();
    $zone_id = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    if ( $zone_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'zone_id required.', 'gend-society' ), 'code' => 'missing_zone_id' ), 400 );
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/status', array(), 'GET' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_list', function () {
    gs_membership_ajax_authorize();
    $r = gs_remote_membership_call( 'hosting/domains', array(), 'GET' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

// ─── Phase 73-02: DNS records CRUD + Undo AJAX proxies ──────────────────
// Each handler mirrors the Phase 72 6-handler pattern verbatim (nonce → param
// sanitize → gs_remote_membership_call → WP_Error passthrough → success). Pipes
// to the 4 new REST routes from Phase 73-01 under gdc-app-manager/v1/install/{id}/
// hosting/domains/{zone_id}/records[/{record_id}[/{audit_id}]]. Force-bypass for
// destructive-edit 409s: delete uses ?force=true query param (DELETE bodies are
// unreliable); update accepts force in the body (some clients can't add query
// params on PUT).
//
// Error codes preserved verbatim so records-editor.js can switch on them:
//   validation_failed / proxied_not_supported / txt_too_long → 400
//   delete_mx_breaks_email / delete_apex_a_breaks_site       → 409 (force=true bypasses)
//   undo_unsupported_event                                    → 400
//   cf_auth_missing                                           → 401
//   cf_rate_limit                                             → 429
//   cf_network / cf_api_error                                 → 502
//   not_found                                                 → 404 (IDOR OR missing record/audit)

add_action( 'wp_ajax_gs_membership_domain_record_create', function () {
    gs_membership_ajax_authorize();
    $zone_id = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    if ( $zone_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'zone_id required.', 'gend-society' ), 'code' => 'missing_zone_id' ), 400 );
    }
    $body = array(
        'type'    => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : '',
        'name'    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
        'content' => isset( $_POST['content'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['content'] ) ) : '',
        'ttl'     => isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : 1,
        'proxied' => ! empty( $_POST['proxied'] ),
    );
    if ( isset( $_POST['priority'] ) && $_POST['priority'] !== '' ) {
        $body['priority'] = (int) $_POST['priority'];
    }
    if ( isset( $_POST['data'] ) && is_array( $_POST['data'] ) ) {
        $body['data'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['data'] ) );
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/records', $body, 'POST' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_record_update', function () {
    gs_membership_ajax_authorize();
    $zone_id   = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    $record_id = isset( $_POST['record_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['record_id'] ) ) : '';
    if ( $zone_id <= 0 || $record_id === '' ) {
        wp_send_json_error( array( 'message' => __( 'zone_id or record_id missing.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    $body = array(
        'type'    => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : '',
        'name'    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
        'content' => isset( $_POST['content'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['content'] ) ) : '',
        'ttl'     => isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : 1,
        'proxied' => ! empty( $_POST['proxied'] ),
    );
    if ( isset( $_POST['priority'] ) && $_POST['priority'] !== '' ) {
        $body['priority'] = (int) $_POST['priority'];
    }
    if ( isset( $_POST['data'] ) && is_array( $_POST['data'] ) ) {
        $body['data'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['data'] ) );
    }
    if ( ! empty( $_POST['force'] ) ) {
        $body['force'] = true;
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/records/' . rawurlencode( $record_id ), $body, 'PUT' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_record_delete', function () {
    gs_membership_ajax_authorize();
    $zone_id   = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    $record_id = isset( $_POST['record_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['record_id'] ) ) : '';
    if ( $zone_id <= 0 || $record_id === '' ) {
        wp_send_json_error( array( 'message' => __( 'zone_id or record_id missing.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    // Delete uses query-param force=true (DELETE bodies are not universally serialized per 73-01 decision).
    $path = 'hosting/domains/' . $zone_id . '/records/' . rawurlencode( $record_id );
    if ( ! empty( $_POST['force'] ) ) {
        $path .= '?force=true';
    }
    $r = gs_remote_membership_call( $path, array(), 'DELETE' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_record_undo', function () {
    gs_membership_ajax_authorize();
    $zone_id  = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
    $audit_id = isset( $_POST['audit_id'] ) ? (int) $_POST['audit_id'] : 0;
    if ( $zone_id <= 0 || $audit_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'zone_id or audit_id missing.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    $r = gs_remote_membership_call( 'hosting/domains/' . $zone_id . '/records/undo/' . $audit_id, array(), 'POST' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        // undo_unsupported_event (422 per Phase 73-01 error map — actual is 400 per 73-01 SUMMARY) surfaces verbatim so
        // JS can toast "Change cannot be undone" without retrying.
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

// ────────────────────────────────────────────────────────────────────────
// Phase 74-02: Point-to-App + SSL status + advanced SSL mode override.
// 3 new proxies that pipe to Phase 74-01 host-scoped REST routes:
//   POST /install/{install_id}/hosting/domains/{host}/point-to-app
//   GET  /install/{install_id}/hosting/domains/{host}/ssl-status
//   POST /install/{install_id}/hosting/domains/{host}/point-to-app  (with ssl_mode_only=1)
// NOTE: routes are HOST-scoped (verified against 74-01 SUMMARY cross-plan constants),
// hence the URL uses rawurlencode($host) — NOT $zone_id like Phase 73 record CRUD.
// gs_remote_membership_call signature: ($path, $body, $method) with install_id
// auto-injected from get_option('gs_install_id') inside the helper.
// ────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_gs_membership_domain_point_to_app', function () {
    gs_membership_ajax_authorize();
    $host  = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '';
    $force = ! empty( $_POST['force'] );
    if ( $host === '' ) {
        wp_send_json_error( array( 'message' => __( 'host required.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    $r = gs_remote_membership_call(
        'hosting/domains/' . rawurlencode( $host ) . '/point-to-app',
        array( 'force' => $force ),
        'POST'
    );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_ssl_status', function () {
    gs_membership_ajax_authorize();
    $host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '';
    if ( $host === '' ) {
        wp_send_json_error( array( 'message' => __( 'host required.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    // GET — body is ignored by wp_remote_get; pass empty array().
    $r = gs_remote_membership_call( 'hosting/domains/' . rawurlencode( $host ) . '/ssl-status', array(), 'GET' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_ssl_mode_set', function () {
    gs_membership_ajax_authorize();
    $host  = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '';
    $mode  = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mode'] ) ) : '';
    $force = ! empty( $_POST['force'] );
    $allowed = array( 'off', 'flexible', 'full', 'strict', 'origin_pull' );
    if ( ! in_array( $mode, $allowed, true ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid SSL mode.', 'gend-society' ), 'code' => 'invalid_mode' ), 400 );
    }
    if ( $host === '' ) {
        wp_send_json_error( array( 'message' => __( 'host required.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    // Client-side Flexible SSL destructive-warning shim — front-of-door UX guard requiring force=true.
    // Server (74-01) does not yet honor this branch explicitly; this early-return guarantees the modal
    // fires even if the operator's Wave 1 backend build lands before the SSL-mode-only param handler.
    if ( $mode === 'flexible' && ! $force ) {
        wp_send_json_error( array(
            'code'    => 'flexible_ssl_destructive',
            'message' => __( 'Flexible SSL is plaintext to origin (MITM-able). Confirm with force=true to proceed.', 'gend-society' ),
        ), 409 );
    }
    // Route through 74-01's route_point_to_app handler with ssl_mode_only body param.
    // NOTE: 74-01 was not shipped with an explicit ssl_mode_only branch (per plan file interface note);
    // interim behavior — this becomes a full idempotent re-point (writes same apex A + wildcard CNAME +
    // sets requested SSL mode). Non-destructive because Phase 73 records are already the target values.
    // A future 74-01 patch can short-circuit on ssl_mode_only=true to skip the DNS writes.
    $body = array(
        'ssl_mode_only' => true,
        'mode'          => $mode,
        'force'         => $force,
    );
    $r = gs_remote_membership_call(
        'hosting/domains/' . rawurlencode( $host ) . '/point-to-app',
        $body,
        'POST'
    );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), $status );
    }
    wp_send_json_success( $r );
} );

// ────────────────────────────────────────────────────────────────────────
// Phase 75-02: Email (MX) preset apply + list.
// 2 new proxies that pipe to Phase 75-01 host-scoped REST routes:
//   POST /install/{install_id}/hosting/domains/{host}/email-preset
//   GET  /install/{install_id}/hosting/domains/{host}/email-presets
// gs_remote_membership_call signature: ($path, $body, $method) with install_id
// auto-injected from get_option('gs_install_id') inside the helper.
// Forwards backend response verbatim (200 success, 400 preset_validation_failed,
// 409 spf_conflict_detected, 500 rolled_back — all pass-through so records-editor.js
// can render per-code UX).
// ────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_gs_membership_domain_email_preset_apply', function () {
    gs_membership_ajax_authorize();

    $host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '';
    if ( $host === '' ) {
        wp_send_json_error( array( 'message' => __( 'host required.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }

    $body = array(
        'preset' => isset( $_POST['preset'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['preset'] ) ) : '',
        'force'  => ! empty( $_POST['force'] ),
    );
    // Optional overrides — accept only known keys.
    foreach ( array( 'mx_host', 'dkim_selector', 'dkim_value', 'dmarc_policy' ) as $k ) {
        if ( isset( $_POST[ $k ] ) ) {
            $body[ $k ] = sanitize_text_field( wp_unslash( (string) $_POST[ $k ] ) );
        }
    }
    if ( isset( $_POST['spf_includes'] ) ) {
        $raw_spf = is_array( $_POST['spf_includes'] )
            ? wp_unslash( $_POST['spf_includes'] )
            : (array) json_decode( wp_unslash( (string) $_POST['spf_includes'] ), true );
        $body['spf_includes'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $raw_spf ) ) );
    }
    if ( isset( $_POST['spf_replace'] ) ) {
        $body['spf_replace'] = ! empty( $_POST['spf_replace'] );
    }

    $r = gs_remote_membership_call(
        'hosting/domains/' . rawurlencode( $host ) . '/email-preset',
        $body,
        'POST'
    );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 502;
        wp_send_json_error( array_merge( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), (array) $data ), $status );
    }
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_email_preset_list', function () {
    gs_membership_ajax_authorize();
    $host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '';
    if ( $host === '' ) {
        wp_send_json_error( array( 'message' => __( 'host required.', 'gend-society' ), 'code' => 'missing_params' ), 400 );
    }
    $r = gs_remote_membership_call(
        'hosting/domains/' . rawurlencode( $host ) . '/email-presets',
        array(),
        'GET'
    );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 502;
        wp_send_json_error( array_merge( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), (array) $data ), $status );
    }
    wp_send_json_success( $r );
} );

// Real WooCommerce "Media Storage" tier catalog (size + price) for the
// gend-media-optimizer Media tab's Upgrade dropdown. Cached 1h (server-
// side, via the same option-cache shape gs_remote_membership_get_cached
// uses) since pricing/tiers change rarely — avoids a remote round trip
// on every Media tab page load.
add_action( 'wp_ajax_gs_get_media_storage_plans', function () {
    gs_membership_ajax_authorize();

    $cache_key     = 'gs_media_storage_plans_cache';
    $cache_expires = 'gs_media_storage_plans_cache_expires';
    $expires       = (int) get_option( $cache_expires, 0 );
    if ( $expires > time() ) {
        $cached = get_option( $cache_key, null );
        if ( is_array( $cached ) ) {
            wp_send_json_success( $cached );
        }
    }

    $r = gs_remote_membership_call( 'media-storage-plans', array(), 'GET' );
    if ( is_wp_error( $r ) ) {
        $data   = $r->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 502;
        // Fall back to a stale cache rather than a hard error, if we have one.
        $stale = get_option( $cache_key, null );
        if ( is_array( $stale ) ) {
            wp_send_json_success( $stale );
        }
        wp_send_json_error( array_merge( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), (array) $data ), $status );
    }

    $ttl = isset( $r['cache_seconds'] ) ? max( 300, (int) $r['cache_seconds'] ) : HOUR_IN_SECONDS;
    update_option( $cache_key, $r, false );
    update_option( $cache_expires, time() + $ttl, false );
    wp_send_json_success( $r );
} );

// Refresh-cache hook — used after the plan-upgrade popup closes so
// the new plan appears immediately without waiting out the TTL.
add_action( 'wp_ajax_gs_membership_refresh', function () {
    gs_membership_ajax_authorize();
    gs_remote_membership_invalidate();
    $fresh = gs_remote_membership_get_cached();
    wp_send_json_success( array( 'data' => $fresh ) );
} );

// ────────────────────────────────────────────────────────────────────────
// Renderer
// ────────────────────────────────────────────────────────────────────────

/**
 * Render the unified membership panel. Accepts EITHER a remote payload
 * (from /install/{id}/membership) OR null — when null and a payload is
 * cached we use that, otherwise emit a friendly "not paired" message.
 *
 * @param array|null $payload Remote membership payload, or null to use cache.
 * @return string
 */
function gs_render_membership_panel( $payload = null ) {

    if ( ! is_array( $payload ) ) {
        $payload = gs_remote_membership_get_cached();
    }
    if ( ! is_array( $payload ) ) {
        return '<div class="notice notice-warning gs-membership-not-paired" style="padding:16px; background:rgba(255,255,255,0.04); border-radius:12px; color:var(--gs-muted);"><p>' .
            esc_html__( 'Membership data unavailable. Sign in with gend.me to load your plan details.', 'gend-society' ) .
            '</p></div>';
    }

    $hub_url        = isset( $payload['hub_url'] ) ? rtrim( (string) $payload['hub_url'], '/' ) . '/' : '';
    $membership_url = isset( $payload['membership_url'] ) ? (string) $payload['membership_url'] : '';
    $app_url        = isset( $payload['app_url'] ) ? (string) $payload['app_url'] : home_url( '/' );
    $app_title      = isset( $payload['app_title'] ) && $payload['app_title'] !== '' ? (string) $payload['app_title'] : (string) get_bloginfo( 'name' );

    $status         = isset( $payload['status'] ) ? (string) $payload['status'] : '';
    $status_label   = isset( $payload['status_label'] ) ? (string) $payload['status_label'] : ucfirst( $status );

    $billing        = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
    $dates          = isset( $payload['dates'] )   && is_array( $payload['dates'] )   ? $payload['dates']   : array();
    $migration      = isset( $payload['migration'] ) && is_array( $payload['migration'] ) ? $payload['migration'] : array();

    $dash_plan      = isset( $payload['dashboard_plan'] ) && is_array( $payload['dashboard_plan'] ) ? $payload['dashboard_plan'] : null;
    $host_plan      = isset( $payload['hosting_plan'] )   && is_array( $payload['hosting_plan'] )   ? $payload['hosting_plan']   : null;
    $customer       = isset( $payload['customer'] )       && is_array( $payload['customer'] )       ? $payload['customer']       : null;
    $group          = isset( $payload['group'] )          && is_array( $payload['group'] )          ? $payload['group']          : null;

    $orders         = isset( $payload['orders'] )  && is_array( $payload['orders'] )  ? $payload['orders']  : array();
    $backups        = isset( $payload['backups'] ) && is_array( $payload['backups'] ) ? $payload['backups'] : array();
    $domains        = isset( $payload['domains'] ) && is_array( $payload['domains'] ) ? $payload['domains'] : array();

    ob_start();
    ?>
    <style>
        .gs-mship-card { background: rgba(11,14,20,0.6); border: 1px solid var(--gs-border, rgba(255,255,255,0.08)); border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); backdrop-filter: blur(20px); }
        .gs-mship-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
        .gs-mship-title { display: flex; align-items: center; gap: 16px; }
        .gs-mship-title h2 { margin: 0; font-size: 1.5rem; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.04em; }
        .gs-mship-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; background: rgba(0,180,80,0.15); border: 1px solid rgba(0,180,80,0.4); color: #4ee68a; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .gs-mship-open-app { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 999px; background: linear-gradient(135deg, #00b450, #008c3a); color: #fff !important; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; text-decoration: none; box-shadow: 0 6px 16px -4px rgba(0,180,80,0.4); }
        .gs-mship-open-app:hover { transform: translateY(-1px); }
        .gs-mship-progress { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin: 16px 0 8px; padding: 16px 8px; background: rgba(255,255,255,0.02); border: 1px solid rgba(0,180,80,0.3); border-radius: 14px; }
        .gs-mship-step { display: flex; flex-direction: column; align-items: center; gap: 8px; position: relative; }
        .gs-mship-step::before { content: ''; position: absolute; top: 8px; left: 50%; right: -50%; height: 2px; background: rgba(255,255,255,0.06); z-index: 0; }
        .gs-mship-step:last-child::before { display: none; }
        .gs-mship-step.is-done::before { background: #00b450; }
        .gs-mship-step .dot { width: 16px; height: 16px; border-radius: 50%; background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.15); position: relative; z-index: 1; }
        .gs-mship-step.is-done .dot { background: #00b450; border-color: #00b450; box-shadow: 0 0 12px rgba(0,180,80,0.5); }
        .gs-mship-step .label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gs-muted); }
        .gs-mship-step.is-done .label { color: #4ee68a; }
        .gs-mship-grid { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 24px; }
        .gs-mship-cell { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 18px; }
        .gs-mship-cell--group[style*="background-image"] { background-color: rgba(255,255,255,0.03); }
        .gs-mship-group-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .gs-mship-group-head h3 { margin: 0; }
        .gs-mship-cell--group h3 { display: inline-block; background: rgba(11,14,20,.72); color: #fff; padding: 5px 16px; border-radius: 999px; border: 1px solid rgba(255,255,255,.14); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); box-shadow: 0 2px 10px rgba(0,0,0,.35); }
        .gs-mship-hub-visit-btn { padding: 6px 14px; font-size: 0.72rem; white-space: nowrap; flex-shrink: 0; box-shadow: 0 2px 10px rgba(0,0,0,.35); }
        .gs-mship-plan-card--link { text-decoration: none; cursor: pointer; border-radius: 14px; transition: transform .15s ease, background .15s ease; }
        .gs-mship-plan-card--link:hover { transform: translateY(-2px); background: rgba(255,255,255,.05); }
        .gs-mship-plan-card--link:hover .gs-mship-plan-name { text-decoration: underline; }
        .gs-mship-cell h3 { margin: 0 0 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gs-muted); text-align: center; }
        .gs-mship-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; font-size: 0.85rem; }
        .gs-mship-row .label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gs-muted); font-weight: 600; }
        .gs-mship-row .value { color: #fff; font-weight: 500; }
        .gs-mship-row .pill { display: inline-block; padding: 2px 10px; background: rgba(255,180,0,0.15); border: 1px solid rgba(255,180,0,0.35); color: #ffd166; border-radius: 999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .gs-mship-plan-card { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 16px 8px; }
        .gs-mship-plan-img { width: 96px; height: 96px; border-radius: 16px; background: rgba(255,255,255,0.04); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .gs-mship-plan-img img { width: 100%; height: 100%; object-fit: cover; }
        .gs-mship-plan-img .dashicons { font-size: 48px; color: rgba(255,255,255,0.3); }
        .gs-mship-plan-name { color: #fff; font-weight: 700; font-size: 1rem; }
        .gs-mship-plan-price { color: #4eaaff; font-size: 0.9rem; font-weight: 600; }
        /* Dashboard Plan card — horizontal layout on desktop (image left,
           name/price stacked to the right), stays centered/stacked on
           narrower screens where a row would feel cramped. */
        .gs-mship-plan-card--dashboard .gs-mship-plan-text { display: flex; flex-direction: column; gap: 4px; }
        @media (min-width: 821px) {
            .gs-mship-plan-card--dashboard { flex-direction: row; text-align: left; justify-content: flex-start; }
            .gs-mship-plan-card--dashboard .gs-mship-plan-img { flex-shrink: 0; }
        }
        /* Status / Dashboard Plan — 2 equal columns on desktop, stacked on
           narrower screens (Membership title removed, so these sit right
           at the top of the cell). */
        .gs-mship-status-plan-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
        @media (max-width: 820px) { .gs-mship-status-plan-row { grid-template-columns: 1fr; } }
        /* Gas Used — History / Connected Devices popups. Same dark-glass
           dialog language as the Upgrade popup (gs_hosting_render_storage_resource_cards()
           in dashboard-hosting.php) so this reads as the same design system. */
        .gs-gas-modal { position: fixed; inset: 0; z-index: 999999; display: none; align-items: center; justify-content: center; padding: 24px; }
        .gs-gas-modal.is-open { display: flex; }
        .gs-gas-modal__overlay { position: absolute; inset: 0; background: rgba(2, 6, 23, .78); backdrop-filter: blur(18px) saturate(160%); -webkit-backdrop-filter: blur(18px) saturate(160%); }
        .gs-gas-modal__dialog { position: relative; width: 100%; max-width: 720px; max-height: 84vh; background: linear-gradient(160deg, rgba(15,23,42,.97), rgba(15,23,42,.90)); border: 1px solid rgba(125, 211, 252, .28); border-radius: 20px; box-shadow: 0 40px 80px rgba(0,0,0,.6); display: flex; flex-direction: column; overflow: hidden; }
        .gs-gas-modal__header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .gs-gas-modal__header h3 { margin: 0; color: #f8fafc; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        .gs-gas-modal__close { background: none; border: 0; color: #e6edf7; font-size: 22px; cursor: pointer; line-height: 1; padding: 0 4px; }
        .gs-gas-modal__close:hover { color: #fff; }
        .gs-gas-modal__body { padding: 20px 22px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
        .gs-gas-history-filters { display: flex; gap: 8px; margin-bottom: 14px; }
        .gs-gas-filter { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #cbd5f5; border-radius: 999px; padding: 6px 16px; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
        .gs-gas-filter.is-active { background: rgba(78,170,255,0.18); border-color: rgba(78,170,255,0.4); color: #4eaaff; }
        .gs-gas-devices-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .gs-gas-devices-stat { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 16px; }
        .gs-gas-devices-stat .k { color: var(--gs-muted, #94a3b8); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .gs-gas-devices-stat .v { color: #fff; font-size: 1.15rem; font-weight: 700; margin-top: 4px; }
        .gs-gas-devices-stat .hint { color: var(--gs-muted, #94a3b8); font-size: 0.72rem; margin-top: 2px; }
        .gs-gas-device-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; margin-bottom: 8px; }
        .gs-gas-device-name { color: #fff; font-weight: 700; font-size: 0.92rem; }
        .gs-gas-device-meta { color: var(--gs-muted, #94a3b8); font-size: 0.78rem; margin-top: 2px; }
        /* Blockchain Compute Gas — bespoke "crypto ledger" hero, matching
           the real Compute Gas sub-tab's own gradient/glow visual language
           (.gs-compute-gas__hero, dashboard-hosting.php) rather than the
           plain shared analytics-hero used by Media/Codebase/Tables/Backups. */
        .gs-bcg-hero { position: relative; overflow: hidden; background: linear-gradient(135deg, rgba(78,170,255,0.16) 0%, rgba(168,85,247,0.14) 55%, rgba(11,14,20,0.5) 100%); border: 1px solid rgba(168,85,247,0.28); border-radius: 20px; padding: 28px 30px; }
        .gs-bcg-hero::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(circle at 88% 8%, rgba(168,85,247,.22), transparent 55%), radial-gradient(circle at 6% 95%, rgba(34,211,238,.16), transparent 50%); pointer-events: none; }
        .gs-bcg-badge { position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 999px; background: rgba(168,85,247,.16); border: 1px solid rgba(168,85,247,.4); color: #d8b4fe; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 16px; }
        .gs-bcg-badge .dashicons { font-size: 14px; width: 14px; height: 14px; }
        .gs-bcg-head { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; flex-wrap: wrap; margin-bottom: 24px; }
        .gs-bcg-title { margin: 0 0 6px; font-size: 1.55rem; line-height: 1.35; padding-bottom: 2px; font-weight: 900; letter-spacing: -0.01em; background: linear-gradient(90deg, #fff, #d8b4fe); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .gs-bcg-sub { margin: 0; color: rgba(226,232,240,.65); font-size: 0.9rem; max-width: 460px; }
        .gs-bcg-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .gs-bcg-btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 18px; border-radius: 10px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14); color: #e6edf7; font-size: 0.8rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: background .18s ease, border-color .18s ease, transform .18s ease; }
        .gs-bcg-btn .dashicons { font-size: 15px; width: 15px; height: 15px; }
        .gs-bcg-btn:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,.28); transform: translateY(-1px); color: #fff; }
        .gs-bcg-btn--cta { background: linear-gradient(135deg, #22d3ee, #7dd3fc); color: #0b0e14 !important; border: none; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 8px 22px rgba(34,211,238,.32); }
        .gs-bcg-btn--cta:hover { filter: brightness(1.08); transform: translateY(-2px); box-shadow: 0 10px 26px rgba(34,211,238,.4); }
        .gs-bcg-stats { position: relative; z-index: 1; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; }
        .gs-bcg-stat { position: relative; background: rgba(11,14,20,.55); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 16px 18px 14px 20px; overflow: hidden; }
        .gs-bcg-stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--gs-bcg-accent, #4eaaff); }
        .gs-bcg-stat .icon { color: var(--gs-bcg-accent, #4eaaff); font-size: 18px; width: 18px; height: 18px; display: block; margin-bottom: 10px; }
        .gs-bcg-stat .k { color: rgba(226,232,240,.55); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.07em; font-weight: 700; }
        .gs-bcg-stat .v { color: #fff; font-size: 1.3rem; font-weight: 800; margin-top: 5px; line-height: 1.25; }
        @media (max-width: 640px) { .gs-bcg-hero { padding: 22px 20px; } .gs-bcg-head { flex-direction: column; } }

        /* Backups — same bespoke hero treatment as Blockchain Compute Gas
           above (.gs-bcg-*), own accent (emerald/teal instead of purple/blue)
           and own class prefix so the two sections stay independently
           editable. */
        .gs-bk-hero { position: relative; overflow: hidden; background: linear-gradient(135deg, rgba(16,185,129,0.16) 0%, rgba(34,211,238,0.12) 55%, rgba(11,14,20,0.5) 100%); border: 1px solid rgba(16,185,129,0.28); border-radius: 20px; padding: 28px 30px; display: flex; justify-content: space-between; align-items: flex-start; gap: 28px; flex-wrap: wrap; }
        .gs-bk-hero::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(circle at 88% 8%, rgba(16,185,129,.20), transparent 55%), radial-gradient(circle at 6% 95%, rgba(34,211,238,.16), transparent 50%); pointer-events: none; }
        .gs-bk-main { position: relative; z-index: 1; flex: 1 1 280px; min-width: 220px; }
        .gs-bk-badge { position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 999px; background: rgba(16,185,129,.16); border: 1px solid rgba(16,185,129,.4); color: #6ee7b7; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 16px; }
        .gs-bk-badge.is-warn { background: rgba(245,158,11,.16); border-color: rgba(245,158,11,.4); color: #fcd34d; }
        .gs-bk-badge.is-neutral { background: rgba(148,163,184,.14); border-color: rgba(148,163,184,.35); color: #cbd5e1; }
        .gs-bk-badge .dashicons { font-size: 14px; width: 14px; height: 14px; }
        .gs-bk-title { margin: 0 0 6px; font-size: 1.55rem; line-height: 1.35; padding-bottom: 2px; font-weight: 900; letter-spacing: -0.01em; background: linear-gradient(90deg, #fff, #6ee7b7); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .gs-bk-sub { margin: 0; color: rgba(226,232,240,.65); font-size: 0.9rem; max-width: 460px; }
        .gs-bk-actions { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 10px; flex-shrink: 0; width: 210px; }
        .gs-bk-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; width: 100%; padding: 10px 18px; border-radius: 10px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14); color: #e6edf7; font-size: 0.8rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: background .18s ease, border-color .18s ease, transform .18s ease; }
        .gs-bk-btn .dashicons { font-size: 15px; width: 15px; height: 15px; }
        .gs-bk-btn:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,.28); transform: translateY(-1px); color: #fff; }
        .gs-bk-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .gs-bk-btn:disabled:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.14); transform: none; color: #e6edf7; }
        .gs-bk-btn--cta { background: linear-gradient(135deg, #22d3ee, #7dd3fc); color: #0b0e14 !important; border: none; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 8px 22px rgba(34,211,238,.32); }
        .gs-bk-btn--cta:hover { filter: brightness(1.08); transform: translateY(-2px); box-shadow: 0 10px 26px rgba(34,211,238,.4); }
        @media (max-width: 640px) { .gs-bk-hero { padding: 22px 20px; flex-direction: column; } .gs-bk-actions { width: 100%; } }
        .gs-plan-picker { margin-top: 16px; }
        .gs-plan-picker__status { color: var(--gs-muted); font-size: 0.85rem; padding: 8px 2px; }
        .gs-plan-picker__grid { display: flex; flex-direction: column; gap: 10px; }
        .gs-plan-picker__card { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-radius: 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--gs-border, rgba(255,255,255,0.08)); cursor: pointer; text-align: left; width: 100%; color: #fff; font: inherit; transition: border-color .15s ease, background .15s ease; }
        .gs-plan-picker__card:hover:not([disabled]) { border-color: rgba(182,8,201,0.6); background: rgba(182,8,201,0.08); }
        .gs-plan-picker__card[disabled] { opacity: 0.5; cursor: wait; }
        .gs-plan-picker__card.is-current { border-color: rgba(78,230,138,0.4); background: rgba(0,180,80,0.06); }
        .gs-plan-picker__card-name { font-weight: 700; font-size: 0.95rem; }
        .gs-plan-picker__card-price { color: #4eaaff; font-size: 0.85rem; font-weight: 600; }
        .gs-plan-picker__card-badge { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #4ee68a; font-weight: 700; }
        .gs-mship-tabs { margin-top: 28px; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 22px; }
        .gs-mship-tabs-nav { display: flex; justify-content: center; gap: 14px; border-bottom: 0; margin-bottom: 24px; flex-wrap: wrap; padding-bottom: 4px; }
        /* Glassmorphic, "3D" tab buttons: translucent + blurred glass body,
           an animated gradient-ring border (mask-composite cuts out the
           interior so only the stroke shows) that fades/sweeps in on
           hover, plus a lift + layered glow for a raised, tactile feel. */
        .gs-mship-tab-btn {
            position: relative;
            padding: 14px 26px;
            background: rgba(255,255,255,0.045);
            backdrop-filter: blur(16px) saturate(140%);
            -webkit-backdrop-filter: blur(16px) saturate(140%);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 14px;
            color: var(--gs-muted);
            font-size: 0.82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            overflow: hidden;
            isolation: isolate;
            transition: color 0.25s ease, transform 0.25s cubic-bezier(0.2,0.9,0.3,1), box-shadow 0.25s cubic-bezier(0.2,0.9,0.3,1), border-color 0.25s ease, background 0.25s ease;
            box-shadow: 0 1px 0 rgba(255,255,255,0.06) inset, 0 6px 14px rgba(0,0,0,0.18);
        }
        .gs-mship-tab-btn::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 14px;
            padding: 1.5px;
            background: linear-gradient(130deg, transparent 20%, rgba(78,170,255,0.6), rgba(182,8,201,0.6), transparent 80%);
            background-size: 220% 220%;
            background-position: 0% 50%;
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            opacity: 0;
            transition: opacity 0.3s ease, background-position 0.8s ease;
            z-index: -1;
            pointer-events: none;
        }
        .gs-mship-tab-btn:hover {
            color: #fff;
            transform: translateY(-3px);
            border-color: rgba(255,255,255,0.22);
            background: rgba(255,255,255,0.07);
            box-shadow: 0 1px 0 rgba(255,255,255,0.10) inset, 0 14px 30px -6px rgba(78,170,255,0.28), 0 4px 14px rgba(0,0,0,0.30);
        }
        .gs-mship-tab-btn:hover::before { opacity: 1; background-position: 100% 50%; }
        .gs-mship-tab-btn:active { transform: translateY(-1px) scale(0.98); }
        .gs-mship-tab-btn.is-active {
            color: #fff;
            background: linear-gradient(160deg, rgba(78,170,255,0.16), rgba(182,8,201,0.12));
            border-color: rgba(78,170,255,0.45);
            box-shadow: 0 1px 0 rgba(255,255,255,0.12) inset, 0 10px 26px -6px rgba(78,170,255,0.35), 0 2px 10px rgba(0,0,0,0.25);
        }
        .gs-mship-tab-btn.is-active::before { opacity: 0.85; }
        .gs-mship-tab-icon { font-size: 17px; width: 17px; height: 17px; line-height: 17px; opacity: 0.85; transition: transform 0.25s cubic-bezier(0.2,0.9,0.3,1), opacity 0.2s ease; }
        .gs-mship-tab-btn:hover .gs-mship-tab-icon { transform: translateY(-1px) scale(1.12) rotate(-4deg); opacity: 1; }
        .gs-mship-tab-btn.is-active .gs-mship-tab-icon { opacity: 1; filter: drop-shadow(0 0 6px rgba(78,170,255,0.6)); }
        .gs-mship-tab-panel { display: none; }
        .gs-mship-tab-panel.is-active { display: block; }

        /* ── Futuristic dashboard build-up animations ──────────────────
           Each layer of the membership card fades up and into place with
           a staggered delay so the dashboard "constructs itself" when
           the page (or a tab) becomes visible. Tab-panel children also
           re-animate on tab switch — the .is-active class flip restarts
           the keyframes because the selector match changes. */
        @keyframes gs-mship-rise {
            from { opacity: 0; transform: translateY(12px); filter: blur(2px); }
            to   { opacity: 1; transform: none; filter: none; }
        }
        @keyframes gs-mship-fade {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .gs-mship-card { animation: gs-mship-rise 0.55s cubic-bezier(0.2, 0.9, 0.3, 1) 0.02s both; }
        .gs-mship-card .gs-mship-header   { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.10s both; }
        .gs-mship-card .gs-mship-progress { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.18s both; }
        .gs-mship-card .gs-mship-grid     { animation: gs-mship-fade 0.5s ease-out 0.20s both; }
        .gs-mship-card .gs-mship-cell:nth-child(1) { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.26s both; }
        .gs-mship-card .gs-mship-cell:nth-child(2) { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.34s both; }
        .gs-mship-card .gs-mship-cell:nth-child(3) { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.42s both; }
        .gs-mship-card .gs-mship-cell:nth-child(4) { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.50s both; }
        .gs-mship-card .gs-mship-tabs     { animation: gs-mship-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) 0.58s both; }

        /* Tab nav buttons sweep in one after another. Each becomes the
           reveal cue for its icon. */
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn { animation: gs-mship-rise 0.4s cubic-bezier(0.2, 0.9, 0.3, 1) both; }
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn:nth-child(1) { animation-delay: 0.62s; }
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn:nth-child(2) { animation-delay: 0.68s; }
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn:nth-child(3) { animation-delay: 0.74s; }
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn:nth-child(4) { animation-delay: 0.80s; }
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn:nth-child(5) { animation-delay: 0.86s; }
        .gs-mship-card .gs-mship-tabs-nav .gs-mship-tab-btn:nth-child(6) { animation-delay: 0.92s; }

        /* Each active tab panel: its direct children stagger in.
           Re-fires on tab switch because removing/re-adding .is-active
           toggles the selector match and CSS restarts the keyframes. */
        .gs-mship-tab-panel.is-active > * { animation: gs-mship-rise 0.42s cubic-bezier(0.2, 0.9, 0.3, 1) both; }
        .gs-mship-tab-panel.is-active > *:nth-child(1)  { animation-delay: 0.04s; }
        .gs-mship-tab-panel.is-active > *:nth-child(2)  { animation-delay: 0.10s; }
        .gs-mship-tab-panel.is-active > *:nth-child(3)  { animation-delay: 0.16s; }
        .gs-mship-tab-panel.is-active > *:nth-child(4)  { animation-delay: 0.22s; }
        .gs-mship-tab-panel.is-active > *:nth-child(5)  { animation-delay: 0.28s; }
        .gs-mship-tab-panel.is-active > *:nth-child(6)  { animation-delay: 0.34s; }
        .gs-mship-tab-panel.is-active > *:nth-child(7)  { animation-delay: 0.40s; }
        .gs-mship-tab-panel.is-active > *:nth-child(8)  { animation-delay: 0.46s; }
        .gs-mship-tab-panel.is-active > *:nth-child(n+9) { animation-delay: 0.52s; }

        /* Hosting sidebar items: same idea, stagger one after another. */
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav {
            animation: gs-mship-rise 0.4s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(1) { animation-delay: 0.10s; }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(2) { animation-delay: 0.16s; }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(3) { animation-delay: 0.22s; }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(4) { animation-delay: 0.28s; }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(5) { animation-delay: 0.34s; }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(6) { animation-delay: 0.40s; }
        .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav:nth-child(7) { animation-delay: 0.46s; }

        /* Hosting sub-panel: stagger the cards / stat tiles inside. */
        .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > * {
            animation: gs-mship-rise 0.4s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > *:nth-child(1) { animation-delay: 0.20s; }
        .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > *:nth-child(2) { animation-delay: 0.26s; }
        .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > *:nth-child(3) { animation-delay: 0.32s; }
        .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > *:nth-child(4) { animation-delay: 0.38s; }
        .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > *:nth-child(n+5) { animation-delay: 0.44s; }

        /* Hosting sub-panel switch — restart child animations when a
           hosting nav button activates a different sub-panel. */
        .gs-hosting__panel.is-active .gs-hosting__stat-grid > *,
        .gs-hosting__panel.is-active .gs-hosting__card,
        .gs-hosting__panel.is-active .gs-hosting__section-title,
        .gs-hosting__panel.is-active .gs-hosting__section-sub {
            animation: gs-mship-rise 0.35s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .gs-hosting__panel.is-active .gs-hosting__stat-grid > *:nth-child(1) { animation-delay: 0.04s; }
        .gs-hosting__panel.is-active .gs-hosting__stat-grid > *:nth-child(2) { animation-delay: 0.10s; }
        .gs-hosting__panel.is-active .gs-hosting__stat-grid > *:nth-child(3) { animation-delay: 0.16s; }
        .gs-hosting__panel.is-active .gs-hosting__stat-grid > *:nth-child(4) { animation-delay: 0.22s; }

        /* Respect a user's reduced-motion preference — disables every
           one of the build-up animations above so the dashboard just
           appears instantly. */
        @media (prefers-reduced-motion: reduce) {
            .gs-mship-card, .gs-mship-card *, .gs-mship-tab-panel.is-active > *,
            .gs-mship-tab-panel.is-active .gs-hosting__sidebar .gs-hosting__nav,
            .gs-mship-tab-panel.is-active .gs-hosting__panel.is-active > *,
            .gs-hosting__panel.is-active .gs-hosting__stat-grid > *,
            .gs-hosting__panel.is-active .gs-hosting__card { animation: none !important; }
        }
        .gs-mship-empty { padding: 24px; text-align: center; color: var(--gs-muted); background: rgba(255,255,255,0.02); border-radius: 12px; }
        .gs-mship-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: linear-gradient(135deg, #b608c9, #7e058a); color: #fff !important; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; border: 0; cursor: pointer; text-decoration: none; }
        .gs-mship-action-btn[disabled] { opacity: 0.5; cursor: wait; }
        .gs-mship-action-btn.is-secondary { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); }
        .gs-mship-action-btn.is-danger { background: rgba(255,40,40,0.15); border: 1px solid rgba(255,40,40,0.4); color: #ff8888 !important; }
        .gs-mship-list { list-style: none; margin: 0; padding: 0; }
        .gs-mship-list li { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; margin-bottom: 6px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); border-radius: 10px; gap: 12px; flex-wrap: wrap; }
        .gs-mship-list li .meta { font-size: 0.75rem; color: var(--gs-muted); }
        .gs-mship-form { display: flex; gap: 8px; margin-bottom: 16px; }
        .gs-mship-form input { flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.25); color: #fff; font-size: 0.9rem; }
        .gs-mship-form input:focus { border-color: #b608c9; outline: none; }
        .gs-mship-toast { position: fixed; bottom: 30px; right: 30px; padding: 14px 22px; background: rgba(11,14,20,0.95); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #fff; font-size: 0.9rem; box-shadow: 0 12px 32px -4px rgba(0,0,0,0.6); z-index: 99999; opacity: 0; transition: opacity 0.2s, transform 0.2s; transform: translateY(10px); }
        .gs-mship-toast.is-visible { opacity: 1; transform: translateY(0); }
        .gs-mship-toast.is-success { border-color: rgba(0,180,80,0.4); }
        .gs-mship-toast.is-error { border-color: rgba(255,80,80,0.4); }
    </style>

    <div class="gs-mship-card" id="gs-mship-root" data-app-title="<?php echo esc_attr( $app_title ); ?>">

        <!-- ── Header: title + status + Open App ───────────────────── -->
        <div class="gs-mship-header">
            <div class="gs-mship-title">
                <h2><?php echo esc_html( $app_title ); ?></h2>
                <?php if ( ! empty( $migration['live'] ) ) : ?>
                    <span class="gs-mship-status-badge">● <?php esc_html_e( 'Container Live', 'gend-society' ); ?></span>
                <?php elseif ( $status_label !== '' ) : ?>
                    <span class="gs-mship-status-badge" style="background:rgba(255,180,0,0.15); border-color:rgba(255,180,0,0.4); color:#ffd166;"><?php echo esc_html( $status_label ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $app_url !== '' ) : ?>
                <a class="gs-mship-open-app" href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Open App', 'gend-society' ); ?> →
                </a>
            <?php endif; ?>
        </div>

        <!-- ── 5-stage migration progress ────────────────────────────
             Only renders while a container migration is actively in
             flight. Hidden once the site is live (final stage reached)
             AND on hub / self-hosted installs where no migration ever
             happens (no migration payload from gend.me). -->
        <?php
        $steps    = array( 'prepare' => __('Prepare', 'gend-society'), 'export' => __('Export', 'gend-society'), 'provision' => __('Provision', 'gend-society'), 'verify' => __('Verify', 'gend-society'), 'live' => __('Live', 'gend-society') );
        $stage_ix = isset( $migration['index'] ) ? (int) $migration['index'] : 0;
        $show_migration_progress =
            ! empty( $migration )
            && empty( $migration['live'] )
            && $stage_ix > 0
            && $stage_ix < count( $steps );
        ?>
        <?php if ( $show_migration_progress ) : ?>
        <div class="gs-mship-progress">
            <?php $i = 0; foreach ( $steps as $key => $label ) : $i++; $done = $i <= max( 1, $stage_ix ); ?>
                <div class="gs-mship-step<?php echo $done ? ' is-done' : ''; ?>">
                    <span class="dot"></span>
                    <span class="label"><?php echo esc_html( $label ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── 2-card grid: Membership / Integration Hub. Feature Access moved to the top of the
             Feature Suite tab (see below) so it sits with the feature cards it controls. ── -->
        <div class="gs-mship-grid">

            <!-- Membership cell — now full width (see .gs-mship-grid above).
                 Order: Integration Hub / Dashboard Plan (2-col row, was a
                 separate row above), Activated / Next Renewal, Storage
                 (moved here from Hosting → Storage), Backup plan, Gas Used
                 / Server Costs. -->
            <div class="gs-mship-cell">
                <div class="gs-mship-status-plan-row">
                    <div class="gs-mship-status-col">
                        <?php
                            $g_link = ( $group && ! empty( $group['id'] ) && $hub_url !== '' ) ? trailingslashit( $hub_url ) . 'groups/' . sanitize_title( $group['slug'] ?: $group['id'] ) . '/' : '';
                        ?>
                        <!-- Integration Hub (was "Business Group") — moved here from its own row above. -->
                        <div class="gs-mship-cell gs-mship-cell--group"<?php echo ( $group && ! empty( $group['cover'] ) ) ? ' style="background-image:linear-gradient(180deg, rgba(11,14,20,.35), rgba(11,14,20,.92) 78%), url(' . esc_url( $group['cover'] ) . ');background-size:cover;background-position:center;"' : ''; ?>>
                            <div class="gs-mship-group-head">
                                <h3><?php esc_html_e( 'Integration Hub', 'gend-society' ); ?></h3>
                                <?php if ( $g_link !== '' ) : ?>
                                    <a class="gs-mship-action-btn gs-mship-hub-visit-btn" href="<?php echo esc_url( $g_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Visit Hub', 'gend-society' ); ?></a>
                                <?php endif; ?>
                            </div>
                            <?php if ( $group && ! empty( $group['id'] ) ) : ?>
                                <a class="gs-mship-plan-card gs-mship-plan-card--link" href="<?php echo esc_url( $g_link ); ?>" target="_blank" rel="noopener">
                                    <div class="gs-mship-plan-img">
                                        <?php if ( ! empty( $group['avatar'] ) ) : ?>
                                            <img src="<?php echo esc_url( $group['avatar'] ); ?>" alt="" />
                                        <?php else : ?>
                                            <span class="dashicons dashicons-groups"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="gs-mship-plan-name"><?php echo esc_html( $group['name'] ?? '' ); ?></div>
                                </a>
                            <?php else : ?>
                                <div class="gs-mship-empty"><?php esc_html_e( 'No group linked.', 'gend-society' ); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="gs-mship-plan-col">
                        <?php if ( ! empty( $dates['expires'] ) || ! empty( $dates['renews'] ) ) : ?>
                            <!-- Next Renewal — moved up here from its own full-width row below the grid. -->
                            <div class="gs-mship-renewal" style="max-width: 420px; margin: 10px auto 0; display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-radius: 14px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);">
                                <span class="dashicons dashicons-calendar-alt" style="color: #4eaaff; font-size: 26px; width: 26px; height: 26px; flex-shrink: 0;"></span>
                                <div style="min-width: 0;">
                                    <div style="color: var(--gs-muted, #94a3b8); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;"><?php esc_html_e( 'Next Renewal / Expiration', 'gend-society' ); ?></div>
                                    <div style="color: #fff; font-size: 1.05rem; font-weight: 700; margin-top: 2px;"><?php echo esc_html( ! empty( $dates['expires'] ) ? $dates['expires'] : $dates['renews'] ); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ( $dash_plan && ! empty( $dash_plan['name'] ) ) : ?>
                            <div class="gs-mship-plan-card gs-mship-plan-card--dashboard" style="max-width: 420px; margin: 10px auto 0;">
                                <div class="gs-mship-plan-img">
                                    <?php if ( ! empty( $dash_plan['image'] ) ) : ?>
                                        <img src="<?php echo esc_url( $dash_plan['image'] ); ?>" alt="" />
                                    <?php else : ?>
                                        <span class="dashicons dashicons-admin-users"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="gs-mship-plan-text">
                                    <div class="gs-mship-plan-name"><?php echo esc_html( $dash_plan['name'] ); ?></div>
                                    <?php if ( ! empty( $dash_plan['amount_label'] ) ) : ?>
                                        <div class="gs-mship-plan-price"><?php echo esc_html( $dash_plan['amount_label'] ); ?> <?php echo ! empty( $dash_plan['duration_unit'] ) ? esc_html( sprintf( __( 'every %s', 'gend-society' ), $dash_plan['duration_unit'] ) ) : ''; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="gs-mship-action-btn" data-gs-mship="upgrade-plan" data-group="dashboard" style="display: block; width: 100%; max-width: 420px; margin: 12px auto 0;" aria-expanded="false" aria-controls="gs-plan-picker-membership-top">
                                <?php esc_html_e( 'Upgrade', 'gend-society' ); ?>
                            </button>
                            <div class="gs-plan-picker" id="gs-plan-picker-membership-top" style="max-width: 420px; margin: 0 auto;" hidden></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ( ! empty( $dates['activated'] ) ) : ?>
                    <div class="gs-mship-row"><span class="label"><?php esc_html_e( 'Activated', 'gend-society' ); ?></span><span class="value"><?php echo esc_html( $dates['activated'] ); ?></span></div>
                <?php endif; ?>

                <!-- ── Gas Used / Server Costs / Backup Plan — 3-column row.
                     Moved above Storage. Gas Used now reads the real
                     vendor-app-manager gdc_gas_ledger total for this site
                     (gs_hosting_compute_gas_real_data() - same real query
                     the Hosting → Compute Gas sub-tab's own breakdown uses),
                     rather than the honest-placeholder it used to be before
                     that ledger/billing system existed.
                     Server Costs pulls the real vendor-app-manager 'server'
                     plan-attach products: whichever real server-subgroup
                     product is attached to this membership, else the
                     cheapest available real server tier ("From $X") - same
                     gs_hosting_server_price() source the Servers sub-tab's
                     own Upgrade flow uses. Backup Plan is real WP Ultimo
                     plan(s) under the 'backups' plan-attach type
                     (gdc_register_backups_plan_product(),
                     gdc_plan_attach_types()), sits last. ── -->
                <!-- ── Blockchain Compute Gas — bespoke "crypto ledger" hero,
                     NOT the shared gs_hosting_render_analytics_hero() other
                     sub-tabs (Media/Codebase/Tables/Backups) use - this one
                     gets its own richer treatment (gradient hero, glow accents,
                     color-coded stat cards) matching the visual language
                     already established for the real Compute Gas sub-tab's
                     own hero (.gs-compute-gas__hero in dashboard-hosting.php)
                     since this IS that same subject matter surfaced here on
                     the membership card. Data is unchanged from before:
                     gs_hosting_compute_gas_real_data() for consumption/
                     balance/invoices, gs_hosting_gas_earned_summary() for
                     real Gas Station device-owner earnings (station_user_id
                     on gdc_gas_ledger, paid out in real DGEN via
                     gdc_gas_credit_gcp_payee()), gs_hosting_server_price()
                     for Server Costs. ── -->
                <div style="margin-top: 28px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.08);">
                    <?php
                    $gs_gas_data   = function_exists( 'gs_hosting_compute_gas_real_data' ) ? gs_hosting_compute_gas_real_data() : array();
                    $gs_gas_earned = function_exists( 'gs_hosting_gas_earned_summary' ) ? gs_hosting_gas_earned_summary( get_current_user_id() ) : array();

                    $gs_gas_pending_payment = null;
                    foreach ( (array) ( $gs_gas_data['payments'] ?? array() ) as $gs_gp ) {
                        if ( ( $gs_gp['status'] ?? '' ) === 'pending' && ! empty( $gs_gp['pay_url'] ) ) {
                            $gs_gas_pending_payment = $gs_gp;
                            break;
                        }
                    }

                    $gs_server_price_membership = function_exists( 'gs_dashboard_get_membership' ) ? gs_dashboard_get_membership() : null;
                    $gs_server_price = function_exists( 'gs_hosting_server_price' ) ? gs_hosting_server_price( $gs_server_price_membership ) : array( 'current' => null, 'starting' => null );
                    $gs_server_count_label = '';
                    if ( ! empty( $gs_server_price['current']['label'] ) ) {
                        $gs_server_price_label = $gs_server_price['current']['label'] . ( ! empty( $gs_server_price['current']['unit'] ) ? ' / ' . $gs_server_price['current']['unit'] : '' );
                        $gs_server_count       = (int) ( $gs_server_price['current']['count'] ?? 0 );
                        if ( $gs_server_count > 0 ) {
                            $gs_server_count_label = sprintf( _n( '%d server attached', '%d servers attached', $gs_server_count, 'gend-society' ), $gs_server_count );
                        }
                    } elseif ( ! empty( $gs_server_price['starting']['label'] ) ) {
                        $gs_server_price_label = sprintf( __( 'From %s', 'gend-society' ), $gs_server_price['starting']['label'] );
                        $gs_server_count_label = __( 'No servers attached', 'gend-society' );
                    } else {
                        $gs_server_price_label = __( 'Not available yet', 'gend-society' );
                    }

                    // Real this-month-vs-last-month GAS consumption trend, off
                    // the same real gdc_gas_ledger table gs_gas_data totals
                    // all-time - see gs_hosting_gas_month_over_month().
                    $gs_bcg_mom = function_exists( 'gs_hosting_gas_month_over_month' ) ? gs_hosting_gas_month_over_month() : array( 'direction' => 'flat', 'change_label' => __( 'No usage yet', 'gend-society' ) );
                    $gs_bcg_mom_dir = (string) ( $gs_bcg_mom['direction'] ?? 'flat' );
                    $gs_bcg_mom_accent = array( 'up' => '#ef4444', 'down' => '#22c55e', 'new' => '#4eaaff', 'flat' => '#94a3b8' )[ $gs_bcg_mom_dir ] ?? '#94a3b8';
                    $gs_bcg_mom_icon   = array( 'up' => 'dashicons-arrow-up-alt', 'down' => 'dashicons-arrow-down-alt', 'new' => 'dashicons-chart-line', 'flat' => 'dashicons-minus' )[ $gs_bcg_mom_dir ] ?? 'dashicons-minus';

                    // Same capability gate + embed-url mechanism the Storage
                    // cards' own Upgrade buttons use (gs_hosting_render_storage_resource_cards()
                    // below already renders the shared upgrade modal + its
                    // document-scoped click handler on this page, so this
                    // button just needs the matching data-gs-upgrade-open
                    // attributes - no new modal/JS required).
                    $gs_server_can_upgrade = current_user_can( 'manage_options' )
                        || is_super_admin()
                        || ( function_exists( 'gs_group_tabs_user_has_access' ) && gs_group_tabs_user_has_access() );
                    $gs_server_upgrade_attrs = ( $gs_server_can_upgrade && function_exists( 'gs_hosting_resource_upgrade_data_attrs' ) )
                        ? gs_hosting_resource_upgrade_data_attrs( 'server' )
                        : '';
                    // Raw same-origin embed URL for the Gas Station "Add a
                    // Device" modal's always-on inline Server-tab iframe
                    // below (a separate, non-click-triggered widget, unlike
                    // the data-gs-upgrade-open button above) - empty on a
                    // cross-origin install, where that pane falls back to
                    // its existing "not available" message rather than
                    // trying to drive an always-on iframe through the
                    // click-triggered proxy/picker flow.
                    $gs_server_same_origin = function_exists( 'gs_oauth_is_hub_site' ) ? gs_oauth_is_hub_site() : true;
                    $gs_server_embed_url = ( $gs_server_can_upgrade && $gs_server_same_origin && function_exists( 'gdc_plan_attach_resource_embed_url' ) )
                        ? gdc_plan_attach_resource_embed_url( 'server' )
                        : '';

                    $gs_bcg_next_bill = $gs_gas_pending_payment
                        ? __( 'Ready to pay', 'gend-society' )
                        : ( ! empty( $dates['renews'] ) ? $dates['renews'] : __( 'Not scheduled', 'gend-society' ) );
                    ?>
                    <div class="gs-bcg-hero">
                        <span class="gs-bcg-badge"><span class="dashicons dashicons-superhero-alt"></span><?php esc_html_e( 'Live Ledger', 'gend-society' ); ?></span>
                        <div class="gs-bcg-head">
                            <div>
                                <h2 class="gs-bcg-title"><?php esc_html_e( 'Blockchain Compute Gas', 'gend-society' ); ?></h2>
                                <p class="gs-bcg-sub"><?php esc_html_e( 'Compute gas consumed by this install.', 'gend-society' ); ?></p>
                            </div>
                            <div class="gs-bcg-actions">
                                <button type="button" class="gs-bcg-btn" data-gs-gas-open="history"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'History', 'gend-society' ); ?></button>
                                <button type="button" class="gs-bcg-btn" data-gs-gas-open="devices"><span class="dashicons dashicons-database-view"></span><?php esc_html_e( 'Connected Devices', 'gend-society' ); ?></button>
                                <?php if ( $gs_server_upgrade_attrs !== '' ) : ?>
                                    <button type="button"
                                            class="gs-bcg-btn gs-bcg-btn--cta"
                                            data-gs-upgrade-open
                                            data-resource="server"
                                            data-resource-label="<?php esc_attr_e( 'Server', 'gend-society' ); ?>"
                                            <?php echo $gs_server_upgrade_attrs; ?>>
                                        <span class="dashicons dashicons-networking"></span>
                                        <?php esc_html_e( 'Add Server', 'gend-society' ); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="gs-bcg-stats">
                            <div class="gs-bcg-stat" style="--gs-bcg-accent:#22c55e;">
                                <span class="dashicons dashicons-awards icon"></span>
                                <div class="k"><?php esc_html_e( 'Earned', 'gend-society' ); ?></div>
                                <div class="v"><?php echo esc_html( $gs_gas_earned['total_earned_dgen_label'] ?? '0.00 DGEN' ); ?></div>
                            </div>
                            <div class="gs-bcg-stat" style="--gs-bcg-accent:#a855f7;">
                                <span class="dashicons dashicons-networking icon"></span>
                                <div class="k"><?php esc_html_e( 'Server Costs', 'gend-society' ); ?></div>
                                <div class="v" style="<?php echo strlen( $gs_server_price_label ) > 16 ? 'font-size:1.05rem;' : ''; ?>"><?php echo esc_html( $gs_server_price_label ); ?></div>
                                <?php if ( $gs_server_count_label !== '' ) : ?>
                                    <div style="color: rgba(226,232,240,.5); font-size: 0.7rem; margin-top: 4px;"><?php echo esc_html( $gs_server_count_label ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="gs-bcg-stats" style="margin-top: 14px;">
                            <div class="gs-bcg-stat" style="--gs-bcg-accent:#f59e0b;">
                                <span class="dashicons dashicons-money-alt icon"></span>
                                <div class="k"><?php esc_html_e( 'Owed Balance', 'gend-society' ); ?></div>
                                <div class="v"><?php echo esc_html( $gs_gas_data['unbilled_gas_label'] ?? '0.000000 GAS' ); ?></div>
                                <?php if ( $gs_gas_pending_payment ) : ?>
                                    <a href="<?php echo esc_url( $gs_gas_pending_payment['pay_url'] ); ?>" class="gs-bcg-btn gs-bcg-btn--cta" style="margin-top: 10px; padding: 6px 14px; font-size: 0.72rem;">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <?php echo esc_html( sprintf( __( 'Pay %s', 'gend-society' ), $gs_gas_pending_payment['total_label'] ) ); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="gs-bcg-stat" style="--gs-bcg-accent:#94a3b8;">
                                <span class="dashicons dashicons-calendar-alt icon"></span>
                                <div class="k"><?php esc_html_e( 'Next Bill', 'gend-society' ); ?></div>
                                <div class="v" style="<?php echo strlen( $gs_bcg_next_bill ) > 14 ? 'font-size:1.05rem;' : ''; ?>"><?php echo esc_html( $gs_bcg_next_bill ); ?></div>
                            </div>
                            <div class="gs-bcg-stat" style="--gs-bcg-accent:<?php echo esc_attr( $gs_bcg_mom_accent ); ?>;">
                                <span class="dashicons <?php echo esc_attr( $gs_bcg_mom_icon ); ?> icon"></span>
                                <div class="k"><?php esc_html_e( 'Change', 'gend-society' ); ?></div>
                                <div class="v"><?php echo esc_html( $gs_bcg_mom['change_label'] ?? __( 'No usage yet', 'gend-society' ) ); ?></div>
                                <div style="color: rgba(226,232,240,.5); font-size: 0.7rem; margin-top: 4px;"><?php esc_html_e( 'vs last month', 'gend-society' ); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Backups — full-width bespoke hero (2-column: badge/
                     title/sub on the left, action buttons in their own
                     column on the right), placed BEFORE Storage below since
                     backup plans protect those containers. Usage stats live
                     on the real Hosting-tab Backups sub-tab's own hero
                     instead (gs_hosting_render_backups_section()) - this
                     summary card only needs plan status + actions. Backup
                     Plan pricing tiers are real WP Ultimo plan(s) under the
                     'backups' plan-attach type
                     (gdc_register_backups_plan_product(),
                     gdc_plan_attach_types()). ── -->
                <div style="margin-top: 28px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.08);">
                    <?php
                    // Backup Plan pricing tiers — real WP Ultimo plan(s) under the
                    // 'backups' plan-attach type (gdc_register_backups_plan_product(),
                    // gdc_plan_attach_types()). Folded into this hero's cta row
                    // (Upgrade/Current Plan/Coming Soon) + a stats2 row (name+price)
                    // instead of a separate floating card below it.
                    $gs_backup_plans     = function_exists( 'gdc_plan_attach_get_plans' ) ? gdc_plan_attach_get_plans( 'backups' ) : array();

                    // Which backups-subgroup product (if any) is already attached to
                    // this membership - same get_all_products()/subgroup pattern
                    // gs_hosting_server_price() uses for Server Costs above, so an
                    // already-purchased tier reads as "Current Plan" rather than
                    // just another Upgrade button.
                    $gs_backup_membership = function_exists( 'gs_dashboard_get_membership' ) ? gs_dashboard_get_membership() : null;
                    $gs_backup_current_id = 0;
                    if ( $gs_backup_membership && is_object( $gs_backup_membership ) && method_exists( $gs_backup_membership, 'get_all_products' ) ) {
                        foreach ( (array) $gs_backup_membership->get_all_products() as $gs_bp_row ) {
                            $gs_bp_prod = is_array( $gs_bp_row ) && isset( $gs_bp_row['product'] ) ? $gs_bp_row['product'] : null;
                            if ( $gs_bp_prod && is_object( $gs_bp_prod ) && method_exists( $gs_bp_prod, 'get_subgroup' )
                                && strtolower( (string) $gs_bp_prod->get_subgroup() ) === 'backups' && method_exists( $gs_bp_prod, 'get_id' ) ) {
                                $gs_backup_current_id = (int) $gs_bp_prod->get_id();
                                break;
                            }
                        }
                    }

                    $gs_bk_multi_plan     = count( $gs_backup_plans ) > 1;
                    $gs_bk_has_plan       = $gs_backup_current_id > 0;
                    $gs_bk_current_name   = '';
                    foreach ( $gs_backup_plans as $gs_bp ) {
                        if ( $gs_backup_current_id > 0 && (int) ( $gs_bp['id'] ?? 0 ) === $gs_backup_current_id ) {
                            $gs_bk_current_name = (string) ( $gs_bp['name'] ?? '' );
                            break;
                        }
                    }
                    ?>
                    <div class="gs-bk-hero">
                        <div class="gs-bk-main">
                            <?php if ( empty( $gs_backup_plans ) ) : ?>
                                <span class="gs-bk-badge is-neutral"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'No Backup Plans Configured', 'gend-society' ); ?></span>
                            <?php elseif ( $gs_bk_has_plan ) : ?>
                                <span class="gs-bk-badge"><span class="dashicons dashicons-backup"></span><?php echo esc_html( sprintf( __( 'Active: %s', 'gend-society' ), $gs_bk_current_name ) ); ?></span>
                            <?php else : ?>
                                <span class="gs-bk-badge is-warn"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'No Active Plan', 'gend-society' ); ?></span>
                            <?php endif; ?>
                            <h2 class="gs-bk-title"><?php esc_html_e( 'Backups', 'gend-society' ); ?></h2>
                            <p class="gs-bk-sub"><?php esc_html_e( 'Daily automatic snapshots plus on-demand backups, protecting the storage containers below.', 'gend-society' ); ?></p>
                        </div>
                        <div class="gs-bk-actions">
                            <?php foreach ( $gs_backup_plans as $gs_bp ) :
                                $gs_bp_is_current = $gs_backup_current_id > 0 && (int) ( $gs_bp['id'] ?? 0 ) === $gs_backup_current_id;
                                $gs_bp_available  = ! empty( $gs_bp['available'] );
                                $gs_bp_upgrade_attrs = ( ! $gs_bp_is_current && $gs_bp_available && function_exists( 'gs_hosting_plan_upgrade_data_attrs' ) )
                                    ? gs_hosting_plan_upgrade_data_attrs( 'backups', $gs_bp['id'] ?? 0 )
                                    : '';
                                $gs_bp_label      = $gs_bk_multi_plan ? sprintf( __( 'Upgrade — %s', 'gend-society' ), $gs_bp['name'] ?? '' ) : __( 'Upgrade', 'gend-society' );
                            ?>
                                <?php if ( ! $gs_bp_is_current && $gs_bp_upgrade_attrs !== '' ) : ?>
                                    <button type="button"
                                            class="gs-bk-btn gs-bk-btn--cta gs-upgrade-cta"
                                            data-gs-upgrade-open
                                            data-resource="backups"
                                            data-resource-label="<?php echo esc_attr( $gs_bp['name'] ?? __( 'Backups', 'gend-society' ) ); ?>"
                                            <?php echo $gs_bp_upgrade_attrs; ?>>
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <?php echo esc_html( $gs_bp_label ); ?>
                                    </button>
                                <?php elseif ( ! $gs_bp_is_current && ! empty( $gs_bp['name'] ) ) : ?>
                                    <button type="button" class="gs-bk-btn" disabled>
                                        <?php esc_html_e( 'Coming Soon', 'gend-society' ); ?>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <button type="button" class="gs-bk-btn" data-gs-mship="backup-now"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Backup now', 'gend-society' ); ?></button>
                            <button type="button" class="gs-bk-btn" data-gs-hosting-goto="backups"><span class="dashicons dashicons-list-view"></span><?php esc_html_e( 'Manage Backups', 'gend-society' ); ?></button>
                        </div>
                    </div>
                </div>

                <!-- ── Storage — real usage cards, moved here from Hosting →
                     Storage. Same function the Hosting tab itself calls for
                     the section_group=all (BuddyPress group) case, so this
                     stays in sync automatically. ── -->
                <?php if ( function_exists( 'gs_hosting_render_storage_resource_cards' ) ) : ?>
                <div style="margin-top: 28px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.08);">
                    <?php
                    $gs_top_media_data  = function_exists( 'gs_hosting_collect_media' )  ? gs_hosting_collect_media()  : array();
                    $gs_top_tables_data = function_exists( 'gs_hosting_collect_tables' ) ? gs_hosting_collect_tables() : array();
                    gs_hosting_render_storage_resource_cards( $gs_top_media_data, $gs_top_tables_data, $billing, array(
                        'media'    => 'media-library',
                        'database' => 'tables',
                        'codebase' => 'codebase',
                    ) );
                    ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Tabs: Hosting / Feature Suite / Project Contracts ──
             Settings and User Access now live inside Hosting (Dashboard / User Access sub-tabs).
             Compute Gas also now lives inside Hosting (Hosting → Compute Gas sub-tab). -->
        <?php
        $gs_can_manage     = current_user_can( 'manage_options' );
        $gs_can_list_users = current_user_can( 'list_users' );
        // Hosting is the first (default) tab, so saving App Settings / an application password
        // (redirect + flag) lands back on it; the User Access form (POST) returns to its
        // sub-tab, and the Permalinks save redirects with gs_section=permalinks.
        $gs_hosting_section = 'dashboard';
        if ( isset( $_GET['gs_section'] ) && 'permalinks' === $_GET['gs_section'] ) {
            $gs_hosting_section = 'permalinks';
        }
        // Feature Suite tab removed - Dashboards moved to Hosting -> Codebase,
        // User Access moved to Project Contracts -> User Access (its own
        // independent selector there, separate from pm-admin.js's own tabs).
        $gs_project_contracts_section = 'workspace';
        $gs_default_tab     = $gs_can_manage ? 'app' : 'project-contracts';
        // ?gs_tab= deep link (e.g. a chatflow's "Open the sequence project" redirect lands on
        // ?gs_tab=project-contracts&pm_project=<id>, and pm-admin.js opens that project). App/Hosting stay
        // manager-only, exactly as their tab buttons are.
        $gs_tab_req = isset( $_GET['gs_tab'] ) ? sanitize_key( wp_unslash( $_GET['gs_tab'] ) ) : '';
        if ( 'project-contracts' === $gs_tab_req || ( $gs_can_manage && in_array( $gs_tab_req, array( 'app', 'hosting' ), true ) ) ) {
            $gs_default_tab = $gs_tab_req;
        }
        if ( isset( $_POST['gs_feature_access_nonce'] ) ) {
            // feature-access.php posts to itself (action=""); on reload, reopen
            // Project Contracts on its User Access panel instead of losing place.
            $gs_project_contracts_section = 'user-access';
            $gs_default_tab               = 'project-contracts';
        }
        // Front-end membership popup's "Open Full Hosting Tools" button loads
        // this page inside an iframe (?gdc_dash_embed=1, see
        // gs_dashboard_allow_embed_framing() in pages/dashboard.php) and wants
        // to land straight on the Hosting tab (Compute Gas/Servers/Storage/
        // Tables) instead of App - App's own Dashboard sub-view otherwise just
        // duplicates the popup's own hosting-grid summary cards.
        if ( $gs_can_manage && ! empty( $_GET['gdc_dash_embed'] ) ) {
            $gs_default_tab = 'hosting';
        }
        ?>
        <div class="gs-mship-tabs">
            <div class="gs-mship-tabs-nav" role="tablist">
                <?php if ( $gs_can_manage ) : ?>
                    <button type="button" class="gs-mship-tab-btn<?php echo 'app' === $gs_default_tab ? ' is-active' : ''; ?>" data-tab="app" role="tab"><span class="dashicons dashicons-admin-home gs-mship-tab-icon"></span><?php esc_html_e( 'App', 'gend-society' ); ?></button>
                    <button type="button" class="gs-mship-tab-btn<?php echo 'hosting' === $gs_default_tab ? ' is-active' : ''; ?>" data-tab="hosting" role="tab"><span class="dashicons dashicons-cloud gs-mship-tab-icon"></span><?php esc_html_e( 'Hosting', 'gend-society' ); ?></button>
                <?php endif; ?>
                <button type="button" class="gs-mship-tab-btn<?php echo $gs_default_tab === 'project-contracts' ? ' is-active' : ''; ?>" data-tab="project-contracts" role="tab"><span class="dashicons dashicons-portfolio gs-mship-tab-icon"></span><?php esc_html_e( 'Projects', 'gend-society' ); ?></button>
                <?php // Domain + Backups removed from the top tab strip — both
                      // now live inside Hosting (Hosting → Domains / Backups).
                ?>
            </div>

            <?php // Feature Suite tab removed - Plans is already covered by the
                  // Membership card's own Dashboard Plan Membership card at the
                  // top of the page, Dashboards moved to Hosting -> Codebase,
                  // and User Access moved to Project Contracts -> User Access.
            ?>

            <?php if ( $gs_can_manage ) : ?>
            <!-- App tab (Dashboard [App Settings on top] / Domains / Permalinks / Logs) -->
            <div class="gs-mship-tab-panel<?php echo 'app' === $gs_default_tab ? ' is-active' : ''; ?>" data-panel="app" role="tabpanel">
                <?php
                if ( function_exists( 'gs_render_hosting_tab' ) ) {
                    gs_render_hosting_tab( $payload, array(
                        'with_settings'    => true,
                        'with_permalinks'  => true,
                        'active_section'   => $gs_hosting_section,
                        'section_group'    => 'app',
                    ) );
                }
                ?>
            </div>

            <!-- Hosting tab (Compute Gas / Servers / Storage / Tables) -->
            <div class="gs-mship-tab-panel<?php echo 'hosting' === $gs_default_tab ? ' is-active' : ''; ?>" data-panel="hosting" role="tabpanel">
                <?php
                if ( function_exists( 'gs_render_hosting_tab' ) ) {
                    gs_render_hosting_tab( $payload, array(
                        'with_compute_gas' => true,
                        'section_group'    => 'hosting',
                    ) );
                }
                ?>
            </div>

            <?php endif; ?>

            <!-- Project Contracts tab — this install's own Workspace/Projects dashboard: the
                 SAME render the front-end group tab uses at /groups/{slug}/projects/ (Consult /
                 Developers / Proposals / Projects / Sequences / Calendar / Applications / My Tasks),
                 for the BuddyPress group this install is paired to ($group['id'], from the
                 gdc_bp_group_id site meta — see gs_membership_payload_from_local()). Full render
                 (not the AJAX "light" mode), so every sub-tab matches the live group page exactly. -->
            <div class="gs-mship-tab-panel<?php echo $gs_default_tab === 'project-contracts' ? ' is-active' : ''; ?>" data-panel="project-contracts" role="tabpanel">
                <style>
                /* A legacy stylesheet (projects/assets/psoo-front.css) still loaded on this page
                   redefines .psoo-pm-panel with opacity/visibility instead of display:none, and
                   loads after pm-admin.css — so every sub-tab of the Workspace below (Consult /
                   Developers / Proposals / Projects / Sequences / Calendar / Applications / My
                   Tasks) stacks at full height simultaneously instead of only the active one
                   showing. Restore pm-admin.js's own .psoo-pm-panel--active class as the one
                   source of truth for this embed. */
                [data-panel="project-contracts"] .psoo-pm-panel:not(.psoo-pm-panel--active),
                [data-panel="project-contracts"] .psoo-pm-subpanel:not(.psoo-pm-panel--active),
                [data-panel="project-contracts"] .psoo-pm-tertiary-panel:not(.psoo-pm-panel--active) { display: none !important; }
                [data-panel="project-contracts"] .psoo-pm-panel.psoo-pm-panel--active { display: block !important; opacity: 1 !important; visibility: visible !important; transform: none !important; pointer-events: auto !important; }

                /* Restyle the Consult/Developers/Proposals/.../My Tasks tab row to match
                   the Hosting and Feature Suite tabs' own sidebar (.gs-hosting__sidebar /
                   .gs-hosting__nav), instead of psoo-front.css's rounded-pill/gradient look
                   (which loads after pm-admin.css and wins the cascade otherwise). */
                [data-panel="project-contracts"] .psoo-pm-wrap { display: flex !important; align-items: stretch !important; gap: 24px !important; min-height: 0 !important; background: transparent !important; }
                [data-panel="project-contracts"] .psoo-pm-tabs { flex: 0 0 220px !important; display: flex !important; flex-direction: column !important; gap: 4px !important; padding: 0 16px 0 0 !important; margin: 0 !important; background: transparent !important; border-bottom: 0 !important; border-right: 1px solid rgba(255,255,255,0.08) !important; overflow: visible !important; position: sticky !important; top: 32px !important; }
                [data-panel="project-contracts"] .psoo-pm-tab { position: static !important; display: flex !important; align-items: center !important; gap: 10px !important; width: 100% !important; padding: 10px 14px !important; border-radius: 8px !important; background: transparent !important; border: 0 !important; color: var(--gs-muted, #94a3b8) !important; font-size: 0.9rem !important; font-weight: 600 !important; text-transform: none !important; letter-spacing: normal !important; backdrop-filter: none !important; box-shadow: none !important; transform: none !important; }
                [data-panel="project-contracts"] .psoo-pm-tab::after { content: none !important; }
                [data-panel="project-contracts"] .psoo-pm-tab:hover { background: rgba(255,255,255,0.04) !important; color: #fff !important; transform: none !important; box-shadow: none !important; }
                [data-panel="project-contracts"] .psoo-pm-tab.psoo-pm-tab--active,
                [data-panel="project-contracts"] .psoo-pm-tab.is-active { background: rgba(78,170,255,0.12) !important; color: #4eaaff !important; border: 0 !important; box-shadow: none !important; }
                [data-panel="project-contracts"] .psoo-pm-panels { flex: 1 1 auto !important; min-width: 0 !important; padding: 0 !important; }
                @media (max-width: 820px) {
                    [data-panel="project-contracts"] .psoo-pm-wrap { flex-direction: column !important; }
                    [data-panel="project-contracts"] .psoo-pm-tabs { position: static !important; flex: none !important; flex-direction: row !important; flex-wrap: wrap !important; border-right: 0 !important; border-bottom: 1px solid rgba(255,255,255,0.08) !important; padding: 0 0 12px !important; }
                }
            </style>

            <?php
                // See the comment above psoo_render_group_proposals_screen()'s call for why this
                // is needed: psoo-bp.css/.js normally only load via a wp_enqueue_scripts closure
                // that never runs on this admin page.
                if ( defined( 'PSOO_PATH' ) && defined( 'PSOO_URL' ) && defined( 'PSOO_VER' ) && ! wp_style_is( 'psoo-bp', 'enqueued' ) ) {
                    $gs_bp_css = PSOO_PATH . 'assets/psoo-bp.css';
                    $gs_bp_js  = PSOO_PATH . 'assets/psoo-bp.js';
                    if ( file_exists( $gs_bp_css ) || file_exists( $gs_bp_js ) ) {
                        $gs_bp_ver = PSOO_VER . '-' . max(
                            file_exists( $gs_bp_css ) ? filemtime( $gs_bp_css ) : 0,
                            file_exists( $gs_bp_js ) ? filemtime( $gs_bp_js ) : 0,
                            1
                        );
                        wp_enqueue_style( 'psoo-bp', PSOO_URL . 'assets/psoo-bp.css', array(), $gs_bp_ver );
                        if ( ! wp_style_is( 'psoo-bp-header', 'enqueued' ) && file_exists( PSOO_PATH . 'assets/psoo-bp-header.css' ) ) {
                            wp_enqueue_style( 'psoo-bp-header', PSOO_URL . 'assets/psoo-bp-header.css', array( 'psoo-bp' ), $gs_bp_ver );
                        }
                        wp_enqueue_script( 'psoo-bp', PSOO_URL . 'assets/psoo-bp.js', array( 'jquery' ), $gs_bp_ver, true );
                        wp_localize_script( 'psoo-bp', 'PSOOBP', array(
                            'nonce' => wp_create_nonce( 'psoo_bp' ),
                            'ajax'  => admin_url( 'admin-ajax.php' ),
                            'i18n'  => array(
                                'loading_order'   => __( 'Loading order details?', 'psoo' ),
                                'order_error'     => __( 'Unable to load order.', 'psoo' ),
                                'assign_loading'  => __( 'Loading project managers?', 'psoo' ),
                                'assign_none'     => __( 'No matching project managers were found.', 'psoo' ),
                                'assign_error'    => __( 'We could not load project managers right now. Please try again.', 'psoo' ),
                                'assign_success'  => __( 'Project Manager assigned successfully.', 'psoo' ),
                                'assign_confirm'  => __( 'Assign', 'psoo' ),
                            ),
                            'no_perm' => __( 'You must be an Administrator to view this page.', 'psoo' ),
                        ) );
                    }
                }
                $gs_pm_group_id = isset( $group['id'] ) ? (int) $group['id'] : 0;
                if ( $gs_pm_group_id > 0 && class_exists( 'PSOO_Project_Manager_Group_Extension' ) ) {
                    // Same guard/assets as psoo_enqueue_applications_assets_on_workspace_tab()
                    // (group-project-manager-tab.php) - that function is hooked to bp_actions,
                    // which (like wp_enqueue_scripts) never fires on this admin page, so the
                    // Applications sub-tab's CSS/JS (email-manager-admin.css, em-app-support,
                    // em-postings, the Inbox SPA) never loaded here, leaving it unstyled.
                    if ( function_exists( 'psoo_enqueue_email_manager_tab_assets' ) && defined( 'EMAIL_MANAGER_URL' ) ) {
                        $gs_pc_site_id = (int) groups_get_groupmeta( $gs_pm_group_id, 'gdc_site_id', true );
                        if ( $gs_pc_site_id > 0 ) {
                            psoo_enqueue_email_manager_tab_assets( $gs_pm_group_id, $gs_pc_site_id );
                        }
                    }

                    $gs_pc_html = PSOO_Project_Manager_Group_Extension::render_legacy_content( $gs_pm_group_id );
                    // Strip the "Dive Into High-Velocity Execution" marketing header - redundant
                    // in this dense admin dashboard (the Integration Hub card above already
                    // gives context). Front-end only, so the real Workspace group tab (which
                    // calls the same function through display(), not through here) keeps it.
                    $gs_pc_html = preg_replace( '#<section class="workspace-header-section">.*?</section>#s', '', $gs_pc_html, 1 );
                    // Its now-orphaned CSS rules (target a class no longer in the DOM, harmless
                    // but dead weight) - strip those too rather than leave inert CSS behind.
                    $gs_pc_html = preg_replace( '#\.workspace-header-section\{[^}]*\}\s*\.workspace-header-section::before\{[^}]*\}#s', '', $gs_pc_html, 1 );
                    echo $gs_pc_html; // phpcs:ignore — already escaped internally
                } else {
                    echo '<div class="gs-mship-empty">' . esc_html__( 'This site is not linked to a project group yet.', 'gend-society' ) . '</div>';
                }
                ?>

            <?php if ( $gs_can_list_users ) : ?>
            <!-- ── User Access, injected as a real sibling of Consult / Developers /
                 Proposals / Projects / ... rather than a separate outer wrapper.
                 $gs_pc_html above is a THIRD-PARTY plugin's own rendered string
                 (PSOO_Project_Manager_Group_Extension), so its .psoo-pm-tabs /
                 .psoo-pm-panels markup isn't something this file can safely
                 string-patch — instead this content is rendered here, off to
                 the side (hidden), and the script below moves it into that
                 same real list client-side once the DOM exists, reusing the
                 exact .psoo-pm-tab / .psoo-pm-panel / *--active classes that
                 system's own CSS (above) and click handling already key off. ── -->
            <div id="gs-pc-user-access-source" hidden>
                <span class="dashicons dashicons-admin-users"></span>
                <span><?php esc_html_e( 'User Access', 'gend-society' ); ?></span>
                <div data-gs-pc-user-access-body>
                    <?php
                    if ( defined( 'GS_DIR' ) && file_exists( GS_DIR . 'inc/pages/feature-access.php' ) ) {
                        ( static function () {
                            require GS_DIR . 'inc/pages/feature-access.php';
                        } )();
                    }
                    ?>
                </div>
            </div>
            <script>
            (function () {
                var pcRoot = document.querySelector('[data-panel="project-contracts"]');
                if (!pcRoot || pcRoot.dataset.pcUaInited === '1') return;
                var src = document.getElementById('gs-pc-user-access-source');
                var tabsList  = pcRoot.querySelector('.psoo-pm-tabs');
                var panelList = pcRoot.querySelector('.psoo-pm-panels');
                if (!src || !tabsList || !panelList) return; // group not linked / workspace didn't render — nothing to attach to
                pcRoot.dataset.pcUaInited = '1';

                var label = src.querySelector('span:nth-child(2)').textContent;
                var iconHtml = src.querySelector('.dashicons').outerHTML;
                var body = src.querySelector('[data-gs-pc-user-access-body]');
                var reopenOnUserAccess = <?php echo wp_json_encode( 'user-access' === $gs_project_contracts_section ); ?>;

                var tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'psoo-pm-tab';
                tab.setAttribute('data-gs-pc-user-access-tab', '1');
                tab.setAttribute('role', 'tab');
                tab.innerHTML = iconHtml + ' <span>' + label + '</span>';
                tabsList.appendChild(tab);

                var panel = document.createElement('div');
                panel.className = 'psoo-pm-panel';
                panel.setAttribute('data-gs-pc-user-access-panel', '1');
                panel.appendChild(body);
                panelList.appendChild(panel);
                src.remove();

                function activateUserAccess() {
                    tabsList.querySelectorAll('.psoo-pm-tab').forEach(function (t) {
                        t.classList.toggle('psoo-pm-tab--active', t === tab);
                        t.classList.toggle('is-active', t === tab);
                    });
                    panelList.querySelectorAll('.psoo-pm-panel').forEach(function (p) {
                        p.classList.toggle('psoo-pm-panel--active', p === panel);
                    });
                }
                tab.addEventListener('click', activateUserAccess);
                if (reopenOnUserAccess) { activateUserAccess(); }

                // Defensive: whichever REAL tab the operator clicks, make sure
                // this injected panel/tab step back down even if pm-admin.js's
                // own handler doesn't already sweep every .psoo-pm-panel (it
                // most likely does, via a live querySelectorAll, but this
                // costs nothing to also guarantee).
                tabsList.addEventListener('click', function (e) {
                    var clicked = e.target.closest('.psoo-pm-tab');
                    if (!clicked || clicked === tab) return;
                    tab.classList.remove('psoo-pm-tab--active', 'is-active');
                    panel.classList.remove('psoo-pm-panel--active');
                });
            })();
            </script>
            <?php endif; ?>

            <?php // Domain + Backups panels removed from the top tab strip.
                  // Equivalent UI lives at Hosting → Domains and Hosting → Backups.
                  // The AJAX endpoints (gs_membership_domain_*, gs_membership_backup_*)
                  // are still registered and used by the Hosting sub-panels.
            ?>

            <!-- ── Gas Used: History popup — real gdc_gas_ledger rows
                 (gs_hosting_gas_history_rows()), rendered server-side and
                 filtered client-side (used/earned/all), same pattern the
                 Backups/Logs filter rows already use elsewhere. ── -->
            <div class="gs-gas-modal" id="gs-gas-history-modal" hidden aria-hidden="true">
                <div class="gs-gas-modal__overlay" data-gs-gas-close></div>
                <div class="gs-gas-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gs-gas-history-title">
                    <header class="gs-gas-modal__header">
                        <h3 id="gs-gas-history-title"><span class="dashicons dashicons-backup" style="color:#4eaaff;"></span><?php esc_html_e( 'GAS History', 'gend-society' ); ?></h3>
                        <button type="button" class="gs-gas-modal__close" data-gs-gas-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                    </header>
                    <div class="gs-gas-modal__body">
                        <div class="gs-gas-history-filters" role="tablist">
                            <button type="button" class="gs-gas-filter is-active" data-gs-gas-filter="all"><?php esc_html_e( 'All', 'gend-society' ); ?></button>
                            <button type="button" class="gs-gas-filter" data-gs-gas-filter="used"><?php esc_html_e( 'Used', 'gend-society' ); ?></button>
                            <button type="button" class="gs-gas-filter" data-gs-gas-filter="earned"><?php esc_html_e( 'Earned', 'gend-society' ); ?></button>
                        </div>
                        <?php $gs_gas_history = function_exists( 'gs_hosting_gas_history_rows' ) ? gs_hosting_gas_history_rows( 100 ) : array(); ?>
                        <?php if ( empty( $gs_gas_history ) ) : ?>
                            <p style="color: var(--gs-muted, #94a3b8); font-style: italic; text-align: center; padding: 24px 0;"><?php esc_html_e( 'No GAS activity recorded yet.', 'gend-society' ); ?></p>
                        <?php else : ?>
                            <table class="gs-hosting__table" id="gs-gas-history-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Date', 'gend-society' ); ?></th>
                                        <th><?php esc_html_e( 'Type', 'gend-society' ); ?></th>
                                        <th><?php esc_html_e( 'Detail', 'gend-society' ); ?></th>
                                        <th style="text-align: right;"><?php esc_html_e( 'Amount', 'gend-society' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'gend-society' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $gs_gas_history as $gs_h ) : ?>
                                        <tr data-direction="<?php echo esc_attr( $gs_h['direction'] ); ?>">
                                            <td><?php echo esc_html( $gs_h['created_at'] ); ?></td>
                                            <td><span class="gs-hosting__pill <?php echo $gs_h['direction'] === 'earned' ? 'is-ok' : 'is-warn'; ?>"><?php echo esc_html( ucfirst( $gs_h['direction'] ) ); ?></span></td>
                                            <td><?php echo esc_html( $gs_h['label'] ); ?></td>
                                            <td style="text-align: right;"><?php echo esc_html( $gs_h['amount_label'] ); ?></td>
                                            <td><?php echo esc_html( ucfirst( $gs_h['status'] ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Gas Used: Connected Devices popup — real devices via the
                 existing gs_compute_gas_devices AJAX action (already shipped
                 for the Compute Gas → Gas Stations sub-tab; reused here, not
                 duplicated). "Reconnect" isn't a real action a web page can
                 trigger on someone else's desktop app, so this is honestly
                 labeled "Refresh" (re-fetches the real device list). ── -->
            <div class="gs-gas-modal" id="gs-gas-devices-modal" hidden aria-hidden="true">
                <div class="gs-gas-modal__overlay" data-gs-gas-close></div>
                <div class="gs-gas-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gs-gas-devices-title">
                    <header class="gs-gas-modal__header">
                        <h3 id="gs-gas-devices-title"><span class="dashicons dashicons-database-view" style="color:#4eaaff;"></span><?php esc_html_e( 'Gas Station', 'gend-society' ); ?></h3>
                        <button type="button" class="gs-gas-modal__close" data-gs-gas-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                    </header>
                    <div class="gs-gas-modal__body">
                        <div id="gs-gas-devices-stats" class="gs-gas-devices-stats">
                            <p style="color: var(--gs-muted, #94a3b8); font-style: italic;"><?php esc_html_e( 'Loading devices…', 'gend-society' ); ?></p>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin: 4px 0 12px;">
                            <h4 style="margin: 0; color: #fff; font-size: 0.95rem;"><?php esc_html_e( 'Connection', 'gend-society' ); ?></h4>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" class="gs-hosting__btn" id="gs-gas-devices-refresh" style="background: rgba(255,255,255,0.08); font-size: 0.75rem; padding: 6px 14px;"><?php esc_html_e( 'Refresh', 'gend-society' ); ?></button>
                                <button type="button" class="gs-hosting__btn gs-upgrade-cta" id="gs-gas-devices-add" style="background: linear-gradient(135deg, #22d3ee, #7dd3fc); color: #0b0e14; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; font-size: 0.75rem; padding: 6px 14px;"><?php esc_html_e( 'Add New', 'gend-society' ); ?></button>
                            </div>
                        </div>
                        <div id="gs-gas-devices-list"></div>
                    </div>
                </div>
            </div>

            <!-- ── Gas Used: Add New Device popup — 3 real tabs (Mobile /
                 Desktop / Server). Mobile + Desktop point at gend.me's own
                 real, already-published Gas Station landing pages
                 (gas_station_mobile_url / gas_station_desktop_url, the same
                 URLs the front-end group Gas Stations tab already uses -
                 group-app-tabs.php). The Mobile QR code is a real scannable
                 code (api.qrserver.com, no API key, standard free service)
                 encoding that same real URL - not a fabricated deep link,
                 since no real Expo project slug exists anywhere in this
                 codebase. Server reuses the exact real "Add Server" checkout
                 (gdc_plan_attach_resource_embed_url('server')) already wired
                 to the shared .gs-upgrade-modal elsewhere on this page,
                 rather than duplicating a second checkout flow. ── -->
            <div class="gs-gas-modal" id="gs-gas-add-device-modal" hidden aria-hidden="true">
                <div class="gs-gas-modal__overlay" data-gs-gas-close></div>
                <div class="gs-gas-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gs-gas-add-device-title">
                    <header class="gs-gas-modal__header">
                        <h3 id="gs-gas-add-device-title"><span class="dashicons dashicons-plus-alt2" style="color:#4eaaff;"></span><?php esc_html_e( 'Add a Device', 'gend-society' ); ?></h3>
                        <button type="button" class="gs-gas-modal__close" data-gs-gas-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                    </header>
                    <div class="gs-gas-modal__body">
                        <div class="gs-gas-history-filters" role="tablist" style="margin-bottom: 18px;">
                            <button type="button" class="gs-gas-filter is-active" data-gs-gas-device-tab="mobile" role="tab" aria-selected="true"><?php esc_html_e( 'Mobile', 'gend-society' ); ?></button>
                            <button type="button" class="gs-gas-filter" data-gs-gas-device-tab="desktop" role="tab" aria-selected="false"><?php esc_html_e( 'Desktop', 'gend-society' ); ?></button>
                            <button type="button" class="gs-gas-filter" data-gs-gas-device-tab="server" role="tab" aria-selected="false"><?php esc_html_e( 'Server', 'gend-society' ); ?></button>
                        </div>

                        <div data-gs-gas-device-pane="mobile">
                            <h4 style="margin: 0 0 6px; color: #fff;"><?php esc_html_e( 'Run Gas Station on your phone', 'gend-society' ); ?></h4>
                            <p style="color: var(--gs-muted, #94a3b8); font-size: 0.88rem; line-height: 1.6;">
                                <?php esc_html_e( '1. Install Expo Go from the App Store or Google Play.', 'gend-society' ); ?><br>
                                <?php esc_html_e( '2. Open Expo Go and scan the QR code below.', 'gend-society' ); ?><br>
                                <?php esc_html_e( '3. Sign in with this account and enable Gas Station mode to register your phone.', 'gend-society' ); ?>
                            </p>
                            <div style="display: flex; justify-content: center; padding: 16px 0;">
                                <img src="<?php echo esc_url( 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data=' . rawurlencode( 'https://gend.me/gas-station/mobile/' ) ); ?>" alt="<?php esc_attr_e( 'Scan to open the GenD mobile app', 'gend-society' ); ?>" width="220" height="220" style="border-radius: 12px; background: #fff; padding: 8px;">
                            </div>
                            <p style="text-align: center;">
                                <a href="https://gend.me/gas-station/mobile/" target="_blank" rel="noopener" style="color: #8ab4f8; font-size: 0.82rem;"><?php esc_html_e( 'Or open gend.me/gas-station/mobile on this device', 'gend-society' ); ?></a>
                            </p>
                        </div>

                        <div data-gs-gas-device-pane="desktop" hidden>
                            <h4 style="margin: 0 0 6px; color: #fff;"><?php esc_html_e( 'Run Gas Station on this computer', 'gend-society' ); ?></h4>
                            <p style="color: var(--gs-muted, #94a3b8); font-size: 0.88rem; line-height: 1.6;">
                                <?php esc_html_e( '1. Download the GenD Desktop App below.', 'gend-society' ); ?><br>
                                <?php esc_html_e( '2. Sign in with this account.', 'gend-society' ); ?><br>
                                <?php esc_html_e( '3. Enable Gas Station mode to register this computer — it contributes unused CPU/RAM to the network whenever your machine is idle.', 'gend-society' ); ?>
                            </p>
                            <div style="display: flex; justify-content: center; padding: 8px 0 4px;">
                                <a href="https://gend.me/gas-station/desktop/" target="_blank" rel="noopener" class="gs-hosting__btn gs-upgrade-cta" style="background: linear-gradient(135deg, #22d3ee, #7dd3fc); color: #0b0e14; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px;">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e( 'Download Desktop App', 'gend-society' ); ?>
                                </a>
                            </div>
                        </div>

                        <div data-gs-gas-device-pane="server" hidden>
                            <h4 style="margin: 0 0 6px; color: #fff;"><?php esc_html_e( 'Run Gas Station on a dedicated server', 'gend-society' ); ?></h4>
                            <p style="color: var(--gs-muted, #94a3b8); font-size: 0.88rem; line-height: 1.6;">
                                <?php esc_html_e( 'Every server plan you run earns real GAS for the network and lets your own web apps ride 0-fee. Pick a plan below — it registers as a Gas Station automatically.', 'gend-society' ); ?>
                            </p>
                            <?php if ( $gs_server_embed_url !== '' ) : ?>
                                <!-- Real checkout widget, embedded inline (same iframe + lazy-load
                                     pattern the Hosting → Servers sub-tab already uses -
                                     [data-gs-hosting-servers-frame] in dashboard-hosting.php), not a
                                     button that hands off to a separate popup. -->
                                <div style="border-radius: 12px; overflow: hidden; margin-top: 4px;">
                                    <iframe data-gs-gas-server-frame data-src="<?php echo esc_url( $gs_server_embed_url ); ?>" src="about:blank" title="<?php esc_attr_e( 'Server plan checkout', 'gend-society' ); ?>" loading="lazy" style="width: 100%; height: 560px; border: 0; display: block; background: #0b0e14;"></iframe>
                                </div>
                            <?php else : ?>
                                <p style="color: var(--gs-muted, #94a3b8); font-style: italic; text-align: center;"><?php esc_html_e( 'Server plans are not available for this account right now.', 'gend-society' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var root = document.getElementById('gs-mship-root');
        if (!root) return;
        var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ) ); ?>;
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;
        var memberUrl = <?php echo wp_json_encode( $membership_url ); ?>;

        function toast(msg, kind) {
            var el = document.createElement('div');
            el.className = 'gs-mship-toast' + (kind ? ' is-' + kind : '');
            el.textContent = msg;
            document.body.appendChild(el);
            requestAnimationFrame(function () { el.classList.add('is-visible'); });
            setTimeout(function () { el.classList.remove('is-visible'); setTimeout(function () { el.remove(); }, 300); }, 3500);
        }

        function ajaxAction(action, data, btn) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce', nonce);
            Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
            if (btn) { btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Working...'; }
            return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (btn) { btn.disabled = false; btn.textContent = orig; }
                    if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'Request failed'); }
                    return j.data;
                })
                .catch(function (e) {
                    if (btn) { btn.disabled = false; btn.textContent = orig; }
                    throw e;
                });
        }

        // Tabs
        root.querySelectorAll('.gs-mship-tab-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var tab = b.dataset.tab;
                root.querySelectorAll('.gs-mship-tab-btn').forEach(function (x) { x.classList.toggle('is-active', x === b); });
                root.querySelectorAll('.gs-mship-tab-panel').forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === tab); });
            });
        });

        // Jump-to-Hosting-sub-tab — used by "Manage Backups" on the Overview's
        // Backups hero AND the Storage cards' per-resource settings icons
        // (Media → media-library, Database → tables, Codebase → codebase).
        // Drives the SAME .gs-mship-tab-btn / .gs-hosting__nav click handlers
        // real users use (rather than reimplementing panel-switching here),
        // then scrolls once both are visible.
        root.querySelectorAll('[data-gs-hosting-goto]').forEach(function (b) {
            b.addEventListener('click', function () {
                var section = b.dataset.gsHostingGoto;
                if (!section) return;
                var hostingTabBtn = root.querySelector('.gs-mship-tab-btn[data-tab="hosting"]');
                if (hostingTabBtn) hostingTabBtn.click();
                var hostingRoot = document.getElementById('gs-hosting-root-hosting');
                if (!hostingRoot) return;
                var nav = hostingRoot.querySelector('.gs-hosting__nav[data-section="' + section + '"]');
                if (nav) nav.click();
                requestAnimationFrame(function () { hostingRoot.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
            });
        });

        // Compute Gas (now a Hosting sub-tab — see dashboard-hosting.php) — lazy-load the cost
        // breakdown via the existing gs_hosting_compute_gas AJAX action. Exposed on window so
        // dashboard-hosting.php's own script (a separate IIFE with its own sidebar click handler)
        // can trigger it the first time that sub-tab opens.
        function loadComputeGas() {
            var body = root.querySelector('[data-gs-compute-gas-body]');
            if (!body) return;
            body.innerHTML = '<div style="color: var(--gs-muted, #94a3b8); font-style: italic; padding: 18px; text-align: center;">Loading cost breakdown…</div>';
            var form = new URLSearchParams({ action: 'gs_hosting_compute_gas', nonce: nonce });
            fetch(ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() })
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    if (resp && resp.success && resp.data) {
                        body.innerHTML = renderComputeGas(resp.data);
                        activateComputeGasAnimations(body);
                    } else {
                        body.innerHTML = '<p style="color:var(--gs-muted,#94a3b8); padding:14px;">' +
                            ((resp && resp.data && resp.data.message) || 'Compute Gas data unavailable.') + '</p>';
                    }
                })
                .catch(function(){
                    body.innerHTML = '<p style="color:#fca5a5; padding:14px;">Network error loading Compute Gas.</p>';
                });
        }

        // Real animated count-up - eased, respects prefers-reduced-motion
        // by just jumping straight to the final value.
        function gsCgAnimateCount(el, target, decimals, suffix) {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduce || !target) { el.textContent = target.toFixed(decimals) + suffix; return; }
            var duration = 900;
            var startTime = null;
            function step(ts) {
                if (!startTime) startTime = ts;
                var progress = Math.min((ts - startTime) / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = (target * eased).toFixed(decimals) + suffix;
                if (progress < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }

        // Triggers the count-up numbers and the bar-fill width transitions -
        // separate pass after the HTML lands in the DOM, so the bars start
        // painted at width:0 and the CSS transition actually has something
        // to animate toward rather than snapping straight to their real pct.
        function activateComputeGasAnimations(body) {
            body.querySelectorAll('[data-gs-cg-count]').forEach(function(el) {
                var value = parseFloat(el.dataset.value) || 0;
                var decimals = parseInt(el.dataset.decimals, 10) || 0;
                gsCgAnimateCount(el, value, decimals, el.dataset.suffix || '');
            });
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    body.querySelectorAll('[data-gs-cg-bar]').forEach(function(el) {
                        el.style.width = (parseFloat(el.dataset.pct) || 0) + '%';
                    });
                });
            });
        }

        function renderComputeGas(d) {
            function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]; }); }
            var period = esc(d.period || 'All time');
            var totalVal    = parseFloat(d.total_gas_label) || 0;
            var billedVal   = parseFloat(d.billed_gas_label) || 0;
            var unbilledVal = parseFloat(d.unbilled_gas_label) || 0;

            var html = '<div class="gs-compute-gas__stats">' +
                '<div class="gs-compute-gas__stat" style="animation-delay:.02s;">' +
                    '<div class="gs-compute-gas__stat-label">Total GAS Used</div>' +
                    '<div class="gs-compute-gas__stat-value" data-gs-cg-count data-value="' + totalVal + '" data-decimals="6" data-suffix=" GAS">0 GAS</div>' +
                    '<div style="color:var(--gs-muted,#94a3b8); font-size:.75rem; margin-top:4px;">' + period + '</div>' +
                '</div>' +
                '<div class="gs-compute-gas__stat" style="animation-delay:.10s;">' +
                    '<div class="gs-compute-gas__stat-label">Billed</div>' +
                    '<div class="gs-compute-gas__stat-value" data-gs-cg-count data-value="' + billedVal + '" data-decimals="6" data-suffix=" GAS">0 GAS</div>' +
                    '<div style="color:var(--gs-muted,#94a3b8); font-size:.75rem; margin-top:4px;">On real invoices</div>' +
                '</div>' +
                '<div class="gs-compute-gas__stat gs-compute-gas__stat--total" style="animation-delay:.18s;">' +
                    '<div class="gs-compute-gas__stat-label" style="color:#a5b4fc;">Pending</div>' +
                    '<div class="gs-compute-gas__stat-value" data-gs-cg-count data-value="' + unbilledVal + '" data-decimals="6" data-suffix=" GAS">0 GAS</div>' +
                    '<div style="color:#a5b4fc; font-size:.75rem; margin-top:4px;">Not yet invoiced</div>' +
                '</div>' +
            '</div>';

            if (Array.isArray(d.breakdown) && d.breakdown.length) {
                html += '<div class="gs-compute-gas__bars">';
                d.breakdown.forEach(function(b, i){
                    html += '<div class="gs-compute-gas__bar-row" style="animation-delay:' + (0.05 * i) + 's;">' +
                        '<div class="gs-compute-gas__bar-label" title="' + esc(b.label || '') + '">' + esc(b.task_id || '') + ' &middot; ' + esc(b.label || '') + '</div>' +
                        '<div class="gs-compute-gas__bar-track"><div class="gs-compute-gas__bar-fill" data-gs-cg-bar data-pct="' + (b.pct || 0) + '"></div></div>' +
                        '<div class="gs-compute-gas__bar-amount">' + esc(b.amount_label || '') + ' &middot; ' + esc(b.qty || 0) + 'x</div>' +
                    '</div>';
                });
                html += '</div>';
            } else {
                html += '<p style="color:var(--gs-muted, #94a3b8); font-style:italic; padding:14px 0; margin:0;">' +
                    (d.message ? esc(d.message) : 'No itemized breakdown available yet for this billing period.') +
                    '</p>';
            }

            if (Array.isArray(d.payments) && d.payments.length) {
                html += '<div class="gs-compute-gas__payments"><h4>GAS Usage Invoices</h4>';
                d.payments.forEach(function(p, i){
                    var isPending = p.status === 'pending';
                    var badgeClass = isPending ? 'gs-compute-gas__payment-badge--pending' : 'gs-compute-gas__payment-badge--completed';
                    html += '<div class="gs-compute-gas__payment-row" style="animation-delay:' + (0.05 * i) + 's;">' +
                        '<div>' +
                            '<span class="gs-compute-gas__payment-badge ' + badgeClass + '">' + esc(p.status || '') + '</span>' +
                            '&nbsp; <strong style="color:#fff;">' + esc(p.total_label || '') + '</strong>' +
                            '&nbsp; <span style="color:var(--gs-muted,#94a3b8); font-size:.8rem;">' + esc(p.date || '') + '</span>' +
                        '</div>' +
                        '<div class="gs-compute-gas__payment-links">' +
                            (p.pay_url ? '<a href="' + esc(p.pay_url) + '" target="_blank" rel="noopener">Pay Now</a>' : '') +
                            (p.invoice_url ? '<a href="' + esc(p.invoice_url) + '" target="_blank" rel="noopener">View Invoice</a>' : '') +
                        '</div>' +
                    '</div>';
                });
                html += '</div>';
            }

            return html;
        }
        window.gsLoadComputeGasBreakdown = loadComputeGas;
        root.addEventListener('click', function(e){
            if (e.target.closest('[data-gs-compute-gas="refresh"]')) {
                loadComputeGas();
            }
        });

        // Compute Gas admin subtabs and Gas Station device management. (Gas Station earnings are
        // queried here, in gs_render_membership_panel()'s own scope, rather than reusing the
        // $gs_admin_earnings gs_render_hosting_tab() computes for its own — now nested — Compute
        // Gas panel markup: that's a separate function call, and its locals don't survive the
        // return back into this one.)
        <?php
        global $wpdb;
        $gs_mship_gas_ledger   = $wpdb->base_prefix . 'gdc_gas_ledger';
        $gs_mship_gas_earnings = $wpdb->get_results(
            "SELECT station_id, SUM(units) AS units, SUM(owner_amount) AS owner_amount, MAX(created_at) AS last_earned
             FROM {$gs_mship_gas_ledger} WHERE station_id <> '' GROUP BY station_id ORDER BY owner_amount DESC",
            ARRAY_A
        );
        ?>
        var cgAdminTab = root.querySelector('[data-panel="compute-gas"]');
        var cgAdminDevices = cgAdminTab && cgAdminTab.querySelector('[data-gs-cg-admin-devices]');
        var cgAdminEditor = cgAdminTab && cgAdminTab.querySelector('[data-gs-cg-admin-editor]');
        var cgAdminAddPanel = cgAdminTab && cgAdminTab.querySelector('[data-gs-cg-admin-add-panel]');
        var cgAdminTitle = cgAdminTab && cgAdminTab.querySelector('[data-gs-cg-admin-editor-title]');
        var cgAdminStatus = cgAdminTab && cgAdminTab.querySelector('[data-gs-cg-admin-node-status]');
        var cgAdminDeviceId = '';
        var cgAdminGroupId = <?php echo (int) ( $group['id'] ?? 0 ); ?>;
        var cgAdminRestRoot = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gend-cp/v1' ) ) ); ?>;
        var cgAdminRestNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        var cgAdminEarnings = <?php echo wp_json_encode( array_map( function ( $row ) {
            return array(
                'station_id' => (string) ( $row['station_id'] ?? '' ),
                'units' => (float) ( $row['units'] ?? 0 ),
                'owner_amount' => (float) ( $row['owner_amount'] ?? 0 ),
                'last_earned' => (string) ( $row['last_earned'] ?? '' ),
            );
        }, (array) $gs_mship_gas_earnings ) ); ?>;
        function cgAdminEsc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function(c) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];
            });
        }
        function loadCgAdminDevices() {
            if (!cgAdminDevices) return;
            var deviceForm = new URLSearchParams({ action: 'gs_compute_gas_devices', _ajax_nonce: nonce, group_id: String(cgAdminGroupId || '') });
            fetch(<?php echo wp_json_encode( esc_url_raw( admin_url( 'admin-ajax.php' ) ) ); ?>, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: deviceForm.toString()
            }).then(function(r) { if (!r.ok) throw new Error('request_failed'); return r.json(); })
            .then(function(payload) {
                var result = payload && payload.data ? payload.data : payload;
                var devices = Array.isArray(result) ? result : (Array.isArray(result.devices) ? result.devices : []);
                var names = {};
                devices.forEach(function(d) { var id = d.id != null ? d.id : d.device_id; names[String(id)] = d.label || d.name || id; });
                if (!cgAdminEarnings.length) {
                    cgAdminTab.querySelector('[data-gs-cg-admin-earnings]').innerHTML = '<p>No GAS fees have been recorded for connected devices yet.</p>';
                } else {
                    cgAdminTab.querySelector('[data-gs-cg-admin-earnings]').innerHTML = '<table class="gs-compute-gas__device-table"><thead><tr><th>Device</th><th>GAS units</th><th>Owner commission</th><th>Last earned</th></tr></thead><tbody>' +
                        cgAdminEarnings.map(function(row) { return '<tr><td><strong>' + cgAdminEsc(names[row.station_id] || row.station_id) + '</strong><br><code>' + cgAdminEsc(row.station_id) + '</code></td><td>' + cgAdminEsc(Number(row.units || 0).toLocaleString()) + '</td><td><strong>' + cgAdminEsc(Number(row.owner_amount || 0).toFixed(8)) + ' GAS</strong></td><td>' + cgAdminEsc(row.last_earned || '—') + '</td></tr>'; }).join('') +
                        '</tbody></table>';
                }
                if (!devices.length) { cgAdminDevices.innerHTML = '<p>No connected devices were found.</p>'; return; }
                cgAdminDevices.innerHTML = '<table class="gs-compute-gas__device-table"><thead><tr><th>Device</th><th>Type</th><th>Status</th><th>Last seen</th><th></th></tr></thead><tbody>' +
                    devices.map(function(d, i) {
                        var id = d.id != null ? d.id : d.device_id, label = d.label || d.name || d.device_id || d.id || ('Device ' + (i + 1));
                        var status = d.online === false || d.status === 'offline' ? 'Offline' : 'Connected';
                        return '<tr><td><strong>' + cgAdminEsc(label) + '</strong><br><code>' + cgAdminEsc(id) + '</code></td><td>' + cgAdminEsc(d.type || d.platform || 'device') + '</td><td>' + status + '</td><td>' + cgAdminEsc(d.last_seen || d.last_seen_at || d.updated_at || '—') + '</td><td><button type="button" class="gs-mship-action-btn gs-cg-admin-edit" data-id="' + cgAdminEsc(id) + '" data-label="' + cgAdminEsc(label) + '">Edit</button></td></tr>';
                    }).join('') + '</tbody></table>';
            }).catch(function() { cgAdminDevices.innerHTML = '<p>Connected devices could not be loaded.</p>'; });
        }
        root.querySelectorAll('[data-gs-cg-admin-tab]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var key = btn.getAttribute('data-gs-cg-admin-tab');
                root.querySelectorAll('[data-gs-cg-admin-tab]').forEach(function(b) { b.classList.toggle('is-active', b === btn); b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
                root.querySelectorAll('[data-gs-cg-admin-panel]').forEach(function(p) { p.classList.toggle('is-active', p.getAttribute('data-gs-cg-admin-panel') === key); });
                if (key === 'gas-stations') loadCgAdminDevices();
                if (key === 'servers') {
                    var serverFrame = root.querySelector('[data-gs-hosting-servers-frame]');
                    if (serverFrame && serverFrame.dataset.src && serverFrame.getAttribute('src') === 'about:blank') {
                        serverFrame.src = serverFrame.dataset.src;
                    }
                }
            });
        });

        // Codebase sub-tabs — Dashboards / Code Packages / File Breakdown,
        // same simple show/hide pattern as the Compute Gas sub-tabs above
        // (no lazy-loaded AJAX data here, everything is already rendered
        // server-side, so no extra branch is needed beyond the toggle).
        root.querySelectorAll('[data-gs-codebase-tab]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var key = btn.getAttribute('data-gs-codebase-tab');
                root.querySelectorAll('[data-gs-codebase-tab]').forEach(function(b) { b.classList.toggle('is-active', b === btn); b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
                root.querySelectorAll('[data-gs-codebase-panel]').forEach(function(p) { p.classList.toggle('is-active', p.getAttribute('data-gs-codebase-panel') === key); });
            });
        });
        root.addEventListener('click', function(e) {
            var add = e.target.closest && e.target.closest('[data-gs-cg-admin-add]');
            if (add && cgAdminAddPanel) { cgAdminAddPanel.hidden = !cgAdminAddPanel.hidden; return; }
            var connect = e.target.closest && e.target.closest('[data-gs-cg-connect-type]');
            var connectModal = cgAdminTab && cgAdminTab.querySelector('[data-gs-cg-connect-modal]');
            if (connect && connectModal) {
                var type = connect.getAttribute('data-gs-cg-connect-type');
                var labels = { server: 'Add a server', desktop: 'Add a desktop', mobile: 'Add a mobile device' };
                var copy = {
                    server: 'Install the GenD node package on your server, sign in with this account, and it will register here automatically.',
                    desktop: 'Install the GenD Desktop App, sign in with this account, and enable Gas Station mode to register this computer.',
                    mobile: 'Install the GenD mobile app, sign in with this account, and enable Gas Station mode to register your phone.'
                };
                cgAdminTab.querySelector('[data-gs-cg-connect-title]').textContent = labels[type] || 'Add a device';
                cgAdminTab.querySelector('[data-gs-cg-connect-copy]').textContent = copy[type] || '';
                connectModal.hidden = false;
                return;
            }
            if (e.target.closest && e.target.closest('[data-gs-cg-connect-close]') && connectModal) { connectModal.hidden = true; return; }
            var edit = e.target.closest && e.target.closest('.gs-cg-admin-edit');
            if (edit) { cgAdminDeviceId = edit.dataset.id || ''; cgAdminTitle.textContent = 'Run as a node — ' + (edit.dataset.label || cgAdminDeviceId); cgAdminEditor.hidden = false; return; }
            var node = e.target.closest && e.target.closest('[data-gs-cg-admin-node]');
            if (!node || !cgAdminDeviceId) return;
            node.disabled = true;
            fetch(cgAdminRestRoot + '/node/' + node.dataset.gsCgAdminNode, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cgAdminRestNonce, 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_id: cgAdminDeviceId })
            }).then(function(r) { if (!r.ok) throw new Error('node_request_failed'); return r.json(); })
                .then(function() { cgAdminStatus.textContent = ' Updated.'; })
                .catch(function() { cgAdminStatus.textContent = ' Could not update node participation.'; })
                .finally(function() { node.disabled = false; });
        });

        // Plan upgrade — native, on-page picker (no popup, no iframe: a
        // cross-origin iframe can't carry gend.me's session cookie, which
        // is why the old embedded checkout rendered blank). Selecting a
        // plan resolves the real checkout URL over the install-token REST
        // proxy, then does a normal top-level redirect to gend.me to
        // complete/confirm the price difference — gend.me renders that
        // page fully since it's a real, first-party navigation.
        // Now appears twice on this page (the top Membership card AND the
        // Feature Suite → Plans tab), so the picker is found relative to
        // each button's own parent rather than a single shared element id.
        // Bound document-wide, not just inside #gs-mship-root: the Hosting
        // sub-tab heroes (dashboard-hosting.php) carry the same button but
        // render outside this root, so they were silently never wired up.
        document.querySelectorAll('[data-gs-mship="upgrade-plan"]').forEach(function (btn) {
            var picker = btn.parentElement ? btn.parentElement.querySelector('.gs-plan-picker') : null;
            if (!picker) return;
            var loaded = false;
            // data-group="hosting" buttons ask the hub for that resource
            // type's hosting-group plans (data-resource, e.g. "database")
            // instead of the default dashboard-tier list - the hub's
            // plan-options route switches catalogs on ?resource=.
            var planParams = (btn.dataset.group === 'hosting' && btn.dataset.resource)
                ? { resource: btn.dataset.resource }
                : {};

            function renderPlans(plans) {
                picker.innerHTML = '';
                var grid = document.createElement('div');
                grid.className = 'gs-plan-picker__grid';
                (plans || []).forEach(function (p) {
                    var card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'gs-plan-picker__card' + (p.is_current ? ' is-current' : '');
                    card.dataset.planId = p.id;
                    if (p.is_current) { card.disabled = true; }
                    var left = document.createElement('span');
                    left.className = 'gs-plan-picker__card-name';
                    left.textContent = p.name || ('Plan #' + p.id);
                    var right = document.createElement('span');
                    if (p.is_current) {
                        right.className = 'gs-plan-picker__card-badge';
                        right.textContent = 'Current plan';
                    } else {
                        right.className = 'gs-plan-picker__card-price';
                        right.textContent = p.price_label || '';
                    }
                    card.appendChild(left);
                    card.appendChild(right);
                    card.addEventListener('click', function () {
                        if (card.disabled) return;
                        grid.querySelectorAll('.gs-plan-picker__card').forEach(function (c) { c.disabled = true; });
                        var origText = right.textContent;
                        right.textContent = 'Working...';
                        ajaxAction('gs_membership_change_plan', { plan_id: p.id }).then(function (data) {
                            if (!data || !data.checkout_url) { throw new Error('No checkout URL returned.'); }
                            window.location.href = data.checkout_url;
                        }).catch(function (e) {
                            toast(e.message, 'error');
                            grid.querySelectorAll('.gs-plan-picker__card').forEach(function (c) { c.disabled = !!c.classList.contains('is-current'); });
                            right.textContent = origText;
                        });
                    });
                    grid.appendChild(card);
                });
                picker.appendChild(grid);
            }

            btn.addEventListener('click', function () {
                var opening = picker.hasAttribute('hidden');
                if (opening) {
                    picker.removeAttribute('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    picker.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                    return;
                }
                if (loaded) return;
                loaded = true;
                var status = document.createElement('div');
                status.className = 'gs-plan-picker__status';
                status.textContent = 'Loading plans...';
                picker.appendChild(status);
                ajaxAction('gs_membership_plan_options', planParams).then(function (data) {
                    if (!data || !data.plans || !data.plans.length) {
                        picker.innerHTML = '';
                        var empty = document.createElement('div');
                        empty.className = 'gs-plan-picker__status';
                        empty.textContent = 'No other plans are available right now.';
                        picker.appendChild(empty);
                        return;
                    }
                    renderPlans(data.plans);
                }).catch(function (e) {
                    loaded = false;
                    picker.innerHTML = '';
                    var err = document.createElement('div');
                    err.className = 'gs-plan-picker__status';
                    err.textContent = e.message || 'Could not load plans.';
                    picker.appendChild(err);
                });
            });
        });

        // Add domain
        var addForm = root.querySelector('[data-gs-mship="add-domain"]');
        if (addForm) {
            addForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var input = addForm.querySelector('input[name="domain"]');
                var domain = (input.value || '').trim().toLowerCase();
                if (!domain) return;
                ajaxAction('gs_membership_domain_add', { domain: domain }, addForm.querySelector('button'))
                    .then(function () { toast('Domain added.', 'success'); setTimeout(function(){ location.reload(); }, 800); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        }

        // Verify / remove domain
        root.addEventListener('click', function (ev) {
            var b = ev.target.closest && ev.target.closest('[data-gs-mship]');
            if (!b) return;
            var act = b.dataset.gsMship;

            if (act === 'verify-domain') {
                ajaxAction('gs_membership_domain_verify', { domain: b.dataset.domain }, b)
                    .then(function (d) { toast('Verify: ' + (d.stage || 'ok'), 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            } else if (act === 'remove-domain') {
                if (!confirm('Remove ' + b.dataset.domain + '?')) return;
                ajaxAction('gs_membership_domain_remove', { domain: b.dataset.domain }, b)
                    .then(function () { var li = b.closest('li'); if (li) li.remove(); toast('Domain removed.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            } else if (act === 'backup-now') {
                ajaxAction('gs_membership_backup_now', {}, b)
                    .then(function () { toast('Backup started. Reload in ~2 minutes.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            } else if (act === 'restore-backup') {
                if (!confirm('Restore this backup? The site will rebuild from this snapshot.')) return;
                ajaxAction('gs_membership_backup_restore', { backup_id: b.dataset.id }, b)
                    .then(function () { toast('Restore started. Site will rebuild in 1-3 minutes.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            }
        });
    })();
    </script>

    <script>
    (function () {
        var root = document.getElementById('gs-mship-root');
        if (!root) return;
        var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ) ); ?>;
        var groupId = <?php echo (int) ( $group['id'] ?? 0 ); ?>;

        var historyModal = document.getElementById('gs-gas-history-modal');
        var devicesModal = document.getElementById('gs-gas-devices-modal');
        var addDeviceModal = document.getElementById('gs-gas-add-device-modal');
        [ historyModal, devicesModal, addDeviceModal ].forEach(function (modal) {
            if (modal && modal.parentElement !== document.body) { document.body.appendChild(modal); }
        });

        function openGasModal(modal) {
            if (!modal) return;
            modal.removeAttribute('hidden');
            modal.classList.add('is-open');
        }
        function closeGasModals() {
            [ historyModal, devicesModal, addDeviceModal ].forEach(function (modal) {
                if (!modal) return;
                modal.classList.remove('is-open');
                modal.setAttribute('hidden', '');
            });
        }

        function escGasHtml(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c];
            });
        }

        var gasDevicesLoading = false;
        function loadGasDevices() {
            var statsEl = document.getElementById('gs-gas-devices-stats');
            var listEl  = document.getElementById('gs-gas-devices-list');
            if (!statsEl || !listEl || gasDevicesLoading) return;
            gasDevicesLoading = true;
            statsEl.innerHTML = '<p style="color: var(--gs-muted, #94a3b8); font-style: italic;">Loading devices…</p>';
            listEl.innerHTML = '';
            var form = new URLSearchParams({ action: 'gs_compute_gas_devices', _ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>, group_id: String(groupId || '') });
            fetch(ajax, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: form.toString()
            }).then(function (r) { return r.json(); }).then(function (payload) {
                var devices = (payload && payload.success && payload.data && Array.isArray(payload.data.devices)) ? payload.data.devices : [];
                renderGasDevices(devices);
            }).catch(function () {
                statsEl.innerHTML = '<p style="color:#fca5a5;">Connected devices could not be loaded.</p>';
            }).finally(function () { gasDevicesLoading = false; });
        }

        function renderGasDevices(devices) {
            var statsEl = document.getElementById('gs-gas-devices-stats');
            var listEl  = document.getElementById('gs-gas-devices-list');
            if (!statsEl || !listEl) return;

            var total = devices.length;
            var online = devices.filter(function (d) { return d.online !== false; }).length;
            var desktopDevices = devices.filter(function (d) { return d.type === 'desktop'; });
            var desktopOnline = desktopDevices.filter(function (d) { return d.online !== false; }).length;
            var integrations = [];
            devices.forEach(function (d) {
                (d.ai_integrations || []).forEach(function (i) {
                    if (i && i.available && i.displayName && integrations.indexOf(i.displayName) === -1) integrations.push(i.displayName);
                });
            });

            statsEl.innerHTML =
                '<div class="gs-gas-devices-stat"><div class="k">Desktop</div><div class="v">' + desktopOnline + ' / ' + desktopDevices.length + '</div><div class="hint">online now</div></div>' +
                '<div class="gs-gas-devices-stat"><div class="k">AI Integration</div><div class="v">' + (integrations.length ? escGasHtml(integrations.join(', ')) : 'None') + '</div><div class="hint">available on your devices</div></div>' +
                '<div class="gs-gas-devices-stat"><div class="k">Group Devices</div><div class="v">' + total + '</div><div class="hint">' + online + ' online now</div></div>';

            if (!total) {
                listEl.innerHTML = '<p style="color: var(--gs-muted, #94a3b8); font-style: italic; text-align:center; padding: 20px 0;">No connected devices yet. Install the GenD Desktop App or mobile app and sign in with this account.</p>';
                return;
            }

            listEl.innerHTML = devices.map(function (d) {
                var label = d.label || d.device_id || 'Device';
                var isOnline = d.online !== false;
                var models = (d.ai_integrations || []).filter(function (i) { return i && i.available; }).map(function (i) { return i.displayName; });
                var integText = models.length ? escGasHtml(models.join(', ')) : 'no AI integrations reported';
                var lastSeen = d.last_seen ? new Date(d.last_seen * 1000).toLocaleString() : '—';
                return '<div class="gs-gas-device-row">' +
                        '<div>' +
                            '<div class="gs-gas-device-name">' + escGasHtml(label) + '</div>' +
                            '<div class="gs-gas-device-meta">' + integText + '</div>' +
                            '<div class="gs-gas-device-meta">' + escGasHtml(d.type || 'device') + (d.app_version ? ' · v' + escGasHtml(d.app_version) : '') + ' · last seen ' + lastSeen + '</div>' +
                        '</div>' +
                        '<span class="gs-hosting__pill ' + (isOnline ? 'is-ok' : 'is-warn') + '">' + (isOnline ? 'ONLINE' : 'OFFLINE') + '</span>' +
                    '</div>';
            }).join('');
        }

        document.addEventListener('click', function (e) {
            var openBtn = e.target.closest && e.target.closest('[data-gs-gas-open]');
            if (openBtn) {
                var which = openBtn.getAttribute('data-gs-gas-open');
                if (which === 'history') { openGasModal(historyModal); }
                if (which === 'devices') { openGasModal(devicesModal); loadGasDevices(); }
                return;
            }
            if (e.target.closest && e.target.closest('[data-gs-gas-close]')) { closeGasModals(); return; }
            if (e.target.closest && e.target.closest('#gs-gas-devices-refresh')) { loadGasDevices(); return; }
            if (e.target.closest && e.target.closest('#gs-gas-devices-add')) { openGasModal(addDeviceModal); return; }
            var deviceTabBtn = e.target.closest && e.target.closest('[data-gs-gas-device-tab]');
            if (deviceTabBtn && addDeviceModal) {
                var tabKey = deviceTabBtn.getAttribute('data-gs-gas-device-tab');
                addDeviceModal.querySelectorAll('[data-gs-gas-device-tab]').forEach(function (b) {
                    b.classList.toggle('is-active', b === deviceTabBtn);
                    b.setAttribute('aria-selected', b === deviceTabBtn ? 'true' : 'false');
                });
                addDeviceModal.querySelectorAll('[data-gs-gas-device-pane]').forEach(function (p) {
                    p.hidden = p.getAttribute('data-gs-gas-device-pane') !== tabKey;
                });
                // Lazy-load the embedded server checkout only once it's
                // actually shown - same pattern as the real Hosting →
                // Servers sub-tab's own [data-gs-hosting-servers-frame].
                if (tabKey === 'server') {
                    var serverFrame = addDeviceModal.querySelector('[data-gs-gas-server-frame]');
                    if (serverFrame && serverFrame.dataset.src && serverFrame.getAttribute('src') === 'about:blank') {
                        serverFrame.src = serverFrame.dataset.src;
                    }
                }
                return;
            }
            var filterBtn = e.target.closest && e.target.closest('[data-gs-gas-filter]');
            if (filterBtn && historyModal) {
                var dir = filterBtn.getAttribute('data-gs-gas-filter');
                historyModal.querySelectorAll('[data-gs-gas-filter]').forEach(function (b) { b.classList.toggle('is-active', b === filterBtn); });
                historyModal.querySelectorAll('#gs-gas-history-table tbody tr').forEach(function (tr) {
                    tr.style.display = (dir === 'all' || tr.getAttribute('data-direction') === dir) ? '' : 'none';
                });
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && [ historyModal, devicesModal, addDeviceModal ].some(function (m) { return m && m.classList.contains('is-open'); })) {
                closeGasModals();
            }
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

/**
 * Backwards-compat wrapper. The dashboard page used to call this
 * function with a $remote payload to render the old "Account Overview"
 * + App Management cards. It now produces the unified panel.
 *
 * @param array $remote /install/{id}/membership payload.
 * @return string
 */
function gs_get_remote_account_overview_html( array $remote ) {
    return gs_render_membership_panel( $remote );
}

// ────────────────────────────────────────────────────────────────────────
// Local payload builder for networked subsites
// ────────────────────────────────────────────────────────────────────────

/**
 * Build a /install/{id}/membership-shaped payload from a local WP
 * Ultimo membership object. Lets networked subsites (where WP Ultimo
 * is loaded and $membership is resolvable directly) render the same
 * unified panel without an HTTP roundtrip.
 *
 * Mirrors the shape of gdc_self_hosted_rest_membership's response.
 * Defensive: any unavailable field returns null/empty so the renderer
 * can fall through cleanly.
 *
 * @param mixed $membership WP_Ultimo\Models\Membership or compatible.
 * @return array|null
 */
/**
 * A BuddyPress group's avatar + cover photo URL, for the Integration Hub card.
 * BuddyPress groups (and their attachments) live on the network's root blog, which isn't
 * necessarily the current site (a subsite's own gdc_bp_group_id points at a group hosted on
 * the hub) — switches there and back, exactly like dashboard-overview.php's own group-avatar
 * lookup, so this works correctly regardless of which site is rendering the dashboard.
 *
 * @return array{avatar:string,cover:string}
 */
function gs_group_avatar_and_cover( $gid ) {
    $gid    = (int) $gid;
    $avatar = '';
    $cover  = '';
    if ( $gid <= 0 ) {
        return array( 'avatar' => $avatar, 'cover' => $cover );
    }
    $bp_root_id = function_exists( 'bp_get_root_blog_id' ) ? (int) bp_get_root_blog_id() : get_current_blog_id();
    $switched   = false;
    if ( is_multisite() && get_current_blog_id() !== $bp_root_id ) {
        switch_to_blog( $bp_root_id );
        $switched = true;
    }
    if ( function_exists( 'bp_core_fetch_avatar' ) ) {
        $avatar_url = bp_core_fetch_avatar( array(
            'item_id'       => $gid,
            'object'        => 'group',
            'type'          => 'full',
            'html'          => false,
            'force_default' => false,
        ) );
        if ( ! empty( $avatar_url ) && is_string( $avatar_url ) ) {
            $avatar = $avatar_url;
        }
    }
    if ( function_exists( 'bp_attachments_get_attachment' ) ) {
        $cover_url = bp_attachments_get_attachment( 'url', array( 'object_dir' => 'groups', 'item_id' => $gid ) );
        if ( ! empty( $cover_url ) && is_string( $cover_url ) ) {
            $cover = $cover_url;
        }
    }
    if ( $switched ) {
        restore_current_blog();
    }
    return array( 'avatar' => $avatar, 'cover' => $cover );
}

function gs_membership_payload_from_local( $membership ) {

    if ( ! $membership || ! is_object( $membership ) || ! method_exists( $membership, 'get_id' ) ) {
        return null;
    }

    // Pick the first site associated with this membership as the
    // primary install — same logic gend.me's listener uses.
    $site = null;
    if ( method_exists( $membership, 'get_sites' ) ) {
        $sites = (array) $membership->get_sites();
        $site = ! empty( $sites ) ? array_shift( $sites ) : null;
    }

    $fmt_date = function ( $s ) {
        if ( $s === '' || $s === '0000-00-00 00:00:00' || $s === null ) return '';
        $ts = strtotime( $s );
        if ( ! $ts ) return '';
        return wp_date( get_option( 'date_format' ) . ' g:i a', $ts );
    };

    // Plans — for hosting we pick the highest-priced product as the
    // main tier (multi-product memberships have main tier + storage
    // upgrades; the main tier almost always has the higher amount).
    $dash_plan = null;
    $host_plan = null;
    $all = method_exists( $membership, 'get_all_products' ) ? (array) $membership->get_all_products() : array();
    foreach ( $all as $row ) {
        $prod = is_array( $row ) && isset( $row['product'] ) ? $row['product'] : null;
        if ( ! $prod || ! is_object( $prod ) || ! method_exists( $prod, 'get_group' ) ) continue;
        $g = (string) $prod->get_group();
        if ( $g === 'dashboard' && ! $dash_plan ) $dash_plan = $prod;
        if ( $g === 'hosting' ) {
            if ( ! $host_plan ) {
                $host_plan = $prod;
            } elseif ( method_exists( $prod, 'get_amount' ) && method_exists( $host_plan, 'get_amount' ) && (float) $prod->get_amount() > (float) $host_plan->get_amount() ) {
                $host_plan = $prod;
            }
        }
    }
    if ( ! $dash_plan && method_exists( $membership, 'get_plan' ) ) {
        $dash_plan = $membership->get_plan();
    }

    $serialize_plan = function ( $plan ) use ( $membership ) {
        if ( ! $plan || ! is_object( $plan ) ) return null;
        $amount = 0.0;
        if ( method_exists( $membership, 'get_amount_for_product' ) ) {
            try { $amount = (float) $membership->get_amount_for_product( $plan ); } catch ( \Throwable $e ) {}
        }
        if ( $amount <= 0 && method_exists( $plan, 'get_amount' ) ) {
            $amount = (float) $plan->get_amount();
        }
        return array(
            'id'           => method_exists( $plan, 'get_id' )          ? (int) $plan->get_id()          : 0,
            'name'         => method_exists( $plan, 'get_name' )        ? (string) $plan->get_name()    : '',
            'slug'         => method_exists( $plan, 'get_slug' )        ? (string) $plan->get_slug()    : '',
            'description'  => method_exists( $plan, 'get_description' ) ? wp_strip_all_tags( (string) $plan->get_description() ) : '',
            'image'        => method_exists( $plan, 'get_featured_image' ) ? (string) $plan->get_featured_image( 'thumbnail' ) : '',
            'amount'       => $amount,
            'amount_label' => $amount > 0 && function_exists( 'wu_format_currency' ) ? wu_format_currency( $amount, method_exists( $plan, 'get_currency' ) ? (string) $plan->get_currency() : '' ) : '',
            'duration_unit'=> method_exists( $plan, 'get_duration_unit' ) ? (string) $plan->get_duration_unit() : 'month',
        );
    };

    // Customer
    $customer_payload = null;
    $customer = method_exists( $membership, 'get_customer' ) ? $membership->get_customer() : null;
    if ( $customer ) {
        $user_id  = method_exists( $customer, 'get_user_id' ) ? (int) $customer->get_user_id() : 0;
        $username = method_exists( $customer, 'get_username' ) ? (string) $customer->get_username() : '';
        $customer_payload = array(
            'user_id'  => $user_id,
            'username' => $username,
            'name'     => method_exists( $customer, 'get_display_name' ) ? (string) $customer->get_display_name() : $username,
            'email'    => method_exists( $customer, 'get_email_address' ) ? (string) $customer->get_email_address() : '',
            'avatar'   => function_exists( 'get_avatar_url' ) && method_exists( $customer, 'get_email_address' ) ? (string) get_avatar_url( $customer->get_email_address(), array( 'size' => 64 ) ) : '',
        );
    }

    // Group
    $group_payload = null;
    $gid = $site && method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_bp_group_id', 0 ) : 0;
    if ( $gid > 0 ) {
        $g_name = sprintf( __( 'Group #%d', 'gend-society' ), $gid );
        $g_slug = '';
        global $wpdb;
        $tbl = $wpdb->base_prefix . 'bp_groups';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT name, slug FROM {$tbl} WHERE id = %d", $gid ) );
        if ( $row ) {
            if ( ! empty( $row->name ) ) $g_name = (string) $row->name;
            if ( ! empty( $row->slug ) ) $g_slug = (string) $row->slug;
        }
        $art          = gs_group_avatar_and_cover( $gid );
        $group_payload = array(
            'id'             => $gid,
            'name'           => $g_name,
            'slug'           => $g_slug,
            'avatar'         => $art['avatar'],
            'cover'          => $art['cover'],
            'members_count'  => 0,
            'projects_count' => 0,
            'files_count'    => 0,
            'messages_count' => 0,
        );
    }

    // Status / billing / dates
    $billing_amount   = method_exists( $membership, 'get_amount' )        ? (float) $membership->get_amount()        : 0.0;
    $billing_currency = method_exists( $membership, 'get_currency' )      ? (string) $membership->get_currency()     : '';
    $billing_unit     = method_exists( $membership, 'get_duration_unit' ) ? (string) $membership->get_duration_unit() : 'month';
    $status           = method_exists( $membership, 'get_status' )        ? (string) $membership->get_status()       : '';

    $membership_id  = (int) $membership->get_id();
    $hub_url        = trailingslashit( (string) network_home_url( '/' ) );
    $membership_url = $membership_id > 0 ? $hub_url . 'my-account/membership/' . $membership_id . '/' : $hub_url . 'my-account/memberships/';

    $app_url = '';
    $app_title = '';
    if ( $site ) {
        if ( method_exists( $site, 'get_active_site_url' ) ) {
            try { $app_url = (string) $site->get_active_site_url(); } catch ( \Throwable $e ) {}
        }
        if ( method_exists( $site, 'get_title' ) ) $app_title = (string) $site->get_title();
    }

    return array(
        'install_id'     => $site && method_exists( $site, 'get_meta' ) ? (string) $site->get_meta( 'gdc_install_id', '' ) : '',
        'membership_id'  => $membership_id,
        'membership_url' => $membership_url,
        'embed_url'      => $membership_id > 0 ? add_query_arg( 'ui', 'embed', $membership_url ) : '',
        'hub_url'        => $hub_url,
        'app_url'        => $app_url,
        'app_title'      => $app_title,
        'status'         => $status,
        'status_label'   => function_exists( 'wu_get_membership_status_label' ) ? (string) wu_get_membership_status_label( $status ) : ucfirst( $status ),
        'billing'        => array(
            'amount'   => $billing_amount,
            'currency' => $billing_currency,
            'unit'     => $billing_unit,
            'label'    => $billing_amount > 0 && function_exists( 'wu_format_currency' ) ? wu_format_currency( $billing_amount, $billing_currency ) : '',
        ),
        'dates' => array(
            'created'   => method_exists( $membership, 'get_date_created' )    ? $fmt_date( (string) $membership->get_date_created() )    : '',
            'activated' => method_exists( $membership, 'get_date_activated' )  ? $fmt_date( (string) $membership->get_date_activated() )  : '',
            'expires'   => method_exists( $membership, 'get_date_expiration' ) ? $fmt_date( (string) $membership->get_date_expiration() ) : '',
            'renews'    => method_exists( $membership, 'get_date_renewed' )    ? $fmt_date( (string) $membership->get_date_renewed() )    : '',
        ),
        'migration' => array(
            'stage' => '',
            'index' => 0,
            'live'  => false,
            'steps' => array( 'prepare', 'export', 'provision', 'verify', 'live' ),
        ),
        'dashboard_plan' => $serialize_plan( $dash_plan ),
        'hosting_plan'   => $serialize_plan( $host_plan ),
        'customer'       => $customer_payload,
        'group'          => $group_payload,
        'orders'         => array(),
        'backups'        => array(),
        'domains'        => array(),
        'cache_seconds'  => 0,
    );
}

/**
 * Fallback payload for a site that's linked to a BuddyPress group but has no
 * WP Ultimo membership of its own — gend.me's own site is exactly this case:
 * it's the hub, not a customer install, so gs_dashboard_get_membership() never
 * returns one, and gs_remote_membership_get_cached() has nothing to fetch
 * either (there's no install_id/token pointing gend.me at itself). Without
 * this, the whole Hosting / Feature Suite / Project Contracts panel never
 * renders for it and the dashboard falls back to the plain admin-users list.
 *
 * Same group-id resolution gend-society's other blog↔group lookups and
 * Gend_CP_App_Identity::read_local_binding() (contracts-and-payments) use:
 * WP Ultimo site meta first, then the gdc_bp_group_id blog option every
 * provisioned site is stamped with, then blog metadata as a last resort.
 *
 * Same output shape as gs_membership_payload_from_local() minus the fields
 * that only make sense with a real membership (billing/dates/plans/customer/
 * status all stay empty — no membership badge is shown, which is the honest
 * state here, rather than claiming a plan/status that doesn't exist).
 *
 * @return array|null
 */
function gs_membership_payload_group_only() {
    $blog_id = get_current_blog_id();

    $gid = 0;
    if ( function_exists( 'wu_get_site' ) ) {
        try {
            $wu_site = wu_get_site( $blog_id );
            if ( $wu_site && method_exists( $wu_site, 'get_meta' ) ) $gid = (int) $wu_site->get_meta( 'gdc_bp_group_id', 0 );
        } catch ( \Throwable $e ) {}
    }
    if ( ! $gid ) {
        $gid = (int) get_blog_option( $blog_id, 'gdc_bp_group_id', 0 );
    }
    if ( ! $gid && function_exists( 'get_metadata' ) ) {
        $gid = (int) get_metadata( 'blog', $blog_id, 'gdc_bp_group_id', true );
    }
    if ( ! $gid ) {
        return null;
    }

    $g_name = sprintf( /* translators: %d: group id */ __( 'Group #%d', 'gend-society' ), $gid );
    $g_slug = '';
    global $wpdb;
    $tbl = $wpdb->base_prefix . 'bp_groups';
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT name, slug FROM {$tbl} WHERE id = %d", $gid ) );
    if ( $row ) {
        if ( ! empty( $row->name ) ) $g_name = (string) $row->name;
        if ( ! empty( $row->slug ) ) $g_slug = (string) $row->slug;
    }

    return array(
        'install_id'     => '',
        'membership_id'  => 0,
        'membership_url' => '',
        'embed_url'      => '',
        'hub_url'        => trailingslashit( (string) network_home_url( '/' ) ),
        'app_url'        => home_url( '/' ),
        'app_title'      => (string) get_bloginfo( 'name' ),
        'status'         => '',
        'status_label'   => '',
        'billing'        => array( 'amount' => 0, 'currency' => '', 'unit' => '', 'label' => '' ),
        'dates'          => array( 'created' => '', 'activated' => '', 'expires' => '', 'renews' => '' ),
        'migration'      => array( 'stage' => '', 'index' => 0, 'live' => false, 'steps' => array() ),
        'dashboard_plan' => null,
        'hosting_plan'   => null,
        'customer'       => null,
        'group'          => array_merge(
            array(
                'id'             => $gid,
                'name'           => $g_name,
                'slug'           => $g_slug,
                'avatar'         => '',
                'cover'          => '',
                'members_count'  => 0,
                'projects_count' => 0,
                'files_count'    => 0,
                'messages_count' => 0,
            ),
            gs_group_avatar_and_cover( $gid )
        ),
        'orders'         => array(),
        'backups'        => array(),
        'domains'        => array(),
        'cache_seconds'  => 0,
    );
}
