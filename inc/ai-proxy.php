<?php
/**
 * gend-society — AI proxy (subsite → gend.me LEO).
 *
 * Architecture: LEO no longer runs on customer subsites. The AI backend
 * (LLM calls + central AI-token balance + per-membership context) lives
 * in the LEO plugin on the gend.me hub. gend-society hosts the frontend
 * surfaces (chat widget, wireframe shortcode, block-editor panels, etc.)
 * and routes their requests through these LOCAL REST endpoints, which
 * forward server-side to the hub using the contracts-and-payments OAuth
 * bearer (the secret-free relay/pickup token already issued when the
 * member connected their gend.me account).
 *
 * Why server-side proxy (not direct browser → gend.me):
 *   - No CORS config needed on the hub.
 *   - The bearer token never touches browser JS.
 *   - Matches the existing Gend_CP_OAuth_Client pattern.
 *
 * Auth + balance model (per product decision):
 *   - Auth rides the contracts-and-payments OAuth (Gend_CP_OAuth_Client).
 *   - The AI-token balance is SEPARATE from the DGEN wallet — it lives in
 *     the hub user's `aipa_credits` meta and is debited by AIPA_Usage on
 *     the hub after each successful call.
 *
 * Routes (namespace gs/v1):
 *   POST /gs/v1/ai/chat     → hub POST /aipa/v1/ai-proxy
 *   GET  /gs/v1/ai/balance  → hub GET  /aipa/v1/ai-proxy/balance
 *   GET  /gs/v1/ai/status   → connection state (no hub round-trip)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GS_AI_Proxy {

    const NS = 'gs/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Base URL of the gend.me hub. Prefers the value the portal handshake
     * stored (gs_gend_base_url), falls back to the canonical host, and is
     * filterable for staging installs.
     */
    public static function hub_base() {
        $base = get_option( 'gs_gend_base_url' );
        if ( ! $base ) {
            $base = 'https://gend.me';
        }
        return untrailingslashit( apply_filters( 'gs_ai_hub_base', $base ) );
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/ai/chat', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'route_chat' ),
            'permission_callback' => array( __CLASS__, 'require_login' ),
        ) );
        register_rest_route( self::NS, '/ai/balance', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'route_balance' ),
            'permission_callback' => array( __CLASS__, 'require_login' ),
        ) );
        register_rest_route( self::NS, '/ai/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'route_status' ),
            'permission_callback' => array( __CLASS__, 'require_login' ),
        ) );

        // Catch-all aipa/v1 forwarder. Lets the migrated LEO frontend
        // surfaces (chat widget, [aipa_wireframe], content blocks, email
        // designer, blog manager, product manager) keep calling their
        // original aipa/v1/* endpoints unchanged — every request is relayed
        // to the hub with the OAuth bearer.
        //
        // GUARDED: only registers when LEO is NOT active locally and this
        // is NOT the gend.me hub. On the hub (and on any subsite still
        // running LEO) the real aipa/v1 routes own the namespace and this
        // catch-all must stay out of the way, or it would shadow them and
        // break AI network-wide.
        if ( self::should_register_aipa_forwarder() ) {
            register_rest_route( 'aipa/v1', '/(?P<gs_path>.+)', array(
                'methods'             => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
                'callback'            => array( __CLASS__, 'route_aipa_forward' ),
                'permission_callback' => array( __CLASS__, 'require_login' ),
            ) );
        }
    }

    protected static function should_register_aipa_forwarder() {
        // The sole reliable signal on path-based multisite (where hub and
        // subsites share the gend.me host) is whether LEO is loaded here.
        // On the hub LEO is network-active and present → forwarder stays
        // out of the way. On subsites a mu-plugin removes LEO from the
        // network-active list → LEO absent → forwarder takes over aipa/v1.
        if ( function_exists( 'aipa_widget_register_scripts' ) || class_exists( 'AIPA_REST_AI_Proxy' ) ) {
            return false;
        }
        return true;
    }

    public static function require_login() {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'gs_ai_login_required', 'Sign in required.', array( 'status' => 401 ) );
    }

    /**
     * Resolve the current user's gend.me bearer token via the
     * contracts-and-payments OAuth client. Returns '' when the plugin
     * isn't active or the user hasn't connected their gend.me account.
     */
    protected static function bearer() {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return '';
        }
        if ( class_exists( 'Gend_CP_OAuth_Client' ) && method_exists( 'Gend_CP_OAuth_Client', 'bearer_for' ) ) {
            return (string) Gend_CP_OAuth_Client::bearer_for( $uid );
        }
        return '';
    }

    public static function route_status( WP_REST_Request $request ) {
        $connected = self::bearer() !== '';
        return new WP_REST_Response( array(
            'connected' => $connected,
            'hub'       => self::hub_base(),
        ), 200 );
    }

    public static function route_chat( WP_REST_Request $request ) {
        $bearer = self::bearer();
        if ( '' === $bearer ) {
            return new WP_REST_Response( array(
                'error'   => 'not_connected',
                'message' => 'Connect your gend.me account to use AI features.',
            ), 402 );
        }

        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = json_decode( $request->get_body(), true );
        }
        if ( ! is_array( $payload ) ) {
            $payload = array();
        }

        $r = wp_remote_post( self::hub_base() . '/wp-json/aipa/v1/ai-proxy', array(
            'timeout' => 60,
            'headers' => self::auth_headers( $bearer ) + array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        return self::relay( $r );
    }

    public static function route_balance( WP_REST_Request $request ) {
        $bearer = self::bearer();
        if ( '' === $bearer ) {
            return new WP_REST_Response( array(
                'connected' => false,
                'balance'   => 0,
            ), 200 );
        }
        $r = wp_remote_get( self::hub_base() . '/wp-json/aipa/v1/ai-proxy/balance', array(
            'timeout' => 20,
            'headers' => self::auth_headers( $bearer ),
        ) );
        return self::relay( $r );
    }

    /**
     * Transparent forwarder for any aipa/v1/<path> request. Preserves
     * method, query string, body, and content-type; attaches the bearer.
     */
    public static function route_aipa_forward( WP_REST_Request $request ) {
        $bearer = self::bearer();
        if ( '' === $bearer ) {
            return new WP_REST_Response( array(
                'error'   => 'not_connected',
                'message' => 'Connect your gend.me account to use AI features.',
            ), 402 );
        }

        $path  = ltrim( (string) $request->get_param( 'gs_path' ), '/' );
        $query = $request->get_query_params();
        unset( $query['gs_path'], $query['rest_route'] );

        $url = self::hub_base() . '/wp-json/aipa/v1/' . $path;
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $method  = $request->get_method();
        $headers = self::auth_headers( $bearer );
        $ct      = $request->get_header( 'content_type' );
        if ( $ct ) {
            $headers['Content-Type'] = $ct;
        }

        $args = array(
            'method'  => $method,
            'timeout' => 90,
            'headers' => $headers,
        );
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            // Pass the raw body through so JSON, form, and multipart all relay intact.
            $args['body'] = $request->get_body();
        }

        $r = wp_remote_request( $url, $args );
        return self::relay( $r );
    }

    protected static function auth_headers( $bearer ) {
        return array(
            'Authorization'      => 'Bearer ' . $bearer,
            'X-Gend-Token'       => $bearer,
            'X-Gend-Source-Blog' => (string) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ),
            'X-Gend-Source-Host' => isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '',
        );
    }

    /**
     * Relay a wp_remote_* result back to our caller, preserving the hub's
     * status code and JSON body. Network failures surface as 502 so the
     * frontend can tell "hub down" from "hub said no".
     */
    protected static function relay( $r ) {
        if ( is_wp_error( $r ) ) {
            return new WP_REST_Response( array(
                'error'   => 'hub_unreachable',
                'message' => $r->get_error_message(),
            ), 502 );
        }
        $code = (int) wp_remote_retrieve_response_code( $r );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( null === $body ) {
            $body = array( 'raw' => wp_remote_retrieve_body( $r ) );
        }
        return new WP_REST_Response( $body, $code ?: 200 );
    }
}

GS_AI_Proxy::init();
