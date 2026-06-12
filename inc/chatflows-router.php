<?php
/**
 * gend-society — Chatflow router.
 *
 * Owns the gs/v1 read endpoint for chatflow definitions so customer
 * subsites can load chatflows without LEO installed locally. The handler
 * branches by environment:
 *
 *   • Hub (LEO present)  → reads directly from wp_leo_chatflows via the
 *     Leo_Chatflow model. Same data the legacy /leo/v1/chatflows route
 *     serves — this is a thin gend-society-namespaced alias.
 *   • Subsite (no LEO)   → forwards to the hub's /leo/v1/chatflows/<slug>
 *     with the user's OAuth bearer. The hub still owns the canonical
 *     storage + admin-edit surface; subsites are pure consumers.
 *
 * Long-term migration target: relocate the wp_leo_chatflows table + the
 * Leo_Chatflow model + the Leo_Chatflows_API admin routes into gend-society
 * so LEO can be deleted from the hub entirely. This router is the bridge
 * that lets JS callers switch to /gs/v1 first; the underlying storage can
 * move later without changing the engine-side callsite again.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GS_Chatflows_Router {

    const NS = 'gs/v1';
    const CACHE_TTL = 5 * MINUTE_IN_SECONDS; // subsite-side transient cache for forwarded reads

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/chatflows/(?P<slug>[a-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'route_get' ),
            'permission_callback' => array( __CLASS__, 'require_logged_in' ),
            'args'                => array(
                'slug' => array( 'sanitize_callback' => 'sanitize_key' ),
            ),
        ) );
    }

    public static function require_logged_in() {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'gs_chatflow_unauth', 'Sign in required.', array( 'status' => 401 ) );
    }

    /**
     * GET /gs/v1/chatflows/<slug>
     *
     * Returns the chatflow definition shape Leo_Chatflows_API::get_chatflow
     * returns, so the LEO flow engine doesn't need a separate parser for
     * the two endpoints.
     */
    public static function route_get( WP_REST_Request $req ) {
        $slug = (string) $req['slug'];
        if ( $slug === '' ) {
            return new WP_REST_Response( array( 'error' => 'slug_required' ), 400 );
        }

        // Hub path: serve directly from the local DB. Same shape the
        // legacy /leo/v1/chatflows/<slug> route serves.
        if ( class_exists( 'Leo_Chatflow' ) ) {
            $flow = Leo_Chatflow::find_by_slug( $slug );
            if ( ! $flow ) {
                return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
            }
            return new WP_REST_Response( $flow->to_array(), 200 );
        }

        // Subsite path: forward to hub with the user's OAuth bearer.
        return self::forward_to_hub( $slug );
    }

    /**
     * Subsite-side fetch. Caches successful responses in a short-lived
     * transient keyed by slug+user so repeated mounts of the same widget
     * on a page don't re-hit the hub on every render. Cache is busted by
     * a slug-only key bumping on the hub when admins edit the flow —
     * which we don't currently propagate, so 5 minutes is the eventual-
     * consistency window for prompt edits on subsites.
     */
    protected static function forward_to_hub( $slug ) {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return new WP_REST_Response( array( 'error' => 'unauth' ), 401 );
        }

        $bearer = '';
        if ( class_exists( 'Gend_CP_OAuth_Client' ) && method_exists( 'Gend_CP_OAuth_Client', 'bearer_for' ) ) {
            $bearer = (string) Gend_CP_OAuth_Client::bearer_for( $uid );
        }
        if ( $bearer === '' ) {
            return new WP_REST_Response( array(
                'error'   => 'not_connected',
                'message' => 'Connect your gend.me account to load this chatflow.',
            ), 402 );
        }

        $cache_key = 'gs_chatflow_' . md5( $slug . ':' . $uid );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return new WP_REST_Response( $cached, 200 );
        }

        $hub  = self::hub_base();
        $url  = $hub . '/wp-json/leo/v1/chatflows/' . rawurlencode( $slug );

        $resp = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer,
                'X-Gend-Token'  => $bearer,
                'Accept'        => 'application/json',
            ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return new WP_REST_Response( array(
                'error'   => 'hub_unreachable',
                'message' => $resp->get_error_message(),
            ), 502 );
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code !== 200 || ! is_array( $body ) ) {
            return new WP_REST_Response(
                is_array( $body ) ? $body : array( 'error' => 'hub_error' ),
                $code ?: 502
            );
        }

        set_transient( $cache_key, $body, self::CACHE_TTL );
        return new WP_REST_Response( $body, 200 );
    }

    protected static function hub_base() {
        if ( class_exists( 'GS_AI_Proxy' ) && method_exists( 'GS_AI_Proxy', 'hub_base' ) ) {
            return untrailingslashit( GS_AI_Proxy::hub_base() );
        }
        $base = get_option( 'gs_gend_base_url', 'https://gend.me' );
        return untrailingslashit( $base );
    }
}

GS_Chatflows_Router::init();
