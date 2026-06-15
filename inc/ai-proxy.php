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

        if ( self::should_register_aipa_forwarder() ) {
            // OAuth endpoints must run LOCALLY (on the subsite), NOT be
            // forwarded to the hub: the authorize code is bound to a PKCE
            // challenge + redirect_uri created in the subsite browser, and
            // the exchange must establish the subsite session and store the
            // subsite user's token. Forwarding it to the hub produced
            // "invalid_grant: code invalid for the client". These concrete
            // routes are registered BEFORE the catch-all so they win, and
            // they mirror gend-society's proven wp-login OAuth exchange.
            register_rest_route( 'aipa/v1', '/oauth/exchange', array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'route_oauth_exchange' ),
                'permission_callback' => '__return_true', // server-to-server token exchange
            ) );
            register_rest_route( 'aipa/v1', '/oauth/status', array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'route_oauth_status' ),
                'permission_callback' => '__return_true',
            ) );
            register_rest_route( 'aipa/v1', '/oauth/sync', array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'route_oauth_sync' ),
                'permission_callback' => function () { return is_user_logged_in(); },
            ) );
            register_rest_route( 'aipa/v1', '/oauth/revoke', array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'route_oauth_revoke' ),
                'permission_callback' => function () { return is_user_logged_in(); },
            ) );

            // Catch-all forwarder for every OTHER aipa/v1 path → hub with
            // the bearer. Migrated LEO surfaces (chat, wireframe, content
            // blocks, email, campaigns, products) keep working unchanged.
            // GUARDED: only when LEO is absent locally (see
            // should_register_aipa_forwarder). The oauth/* paths above are
            // matched first, so they are NOT forwarded.
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

    /* -----------------------------------------------------------------
     *   Local OAuth handlers (NOT forwarded). Mirror gend-society's
     *   proven wp-login OAuth exchange so the widget's "Sign in with
     *   gend.me" works on the subsite: exchange runs here with the shared
     *   client + redirect_uri=hub/oauth-bridge/, the token is stored for
     *   the subsite user, and a guest session is established on this site.
     * ----------------------------------------------------------------- */

    /**
     * Resolve the OAuth client the AI WIDGET uses. The widget is an AIPA
     * (LEO) surface, so it authorizes with the AIPA client — site options
     * aipa_oauth_client_id / aipa_oauth_client_secret (e.g. W1MTC…), which
     * is a DIFFERENT WP-OAuth client than gend-society's own login client
     * (GDC_OAUTH_CLIENT_ID → gs_oauth_client_id, e.g. XuyL…). The exchange
     * MUST use the same client the authorize step used, or WP-OAuth Server
     * returns "code invalid for the client". Fall back to gend-society's
     * client only if the AIPA one isn't configured.
     */
    protected static function aipa_client() {
        $id     = (string) get_site_option( 'aipa_oauth_client_id', '' );
        $secret = (string) get_site_option( 'aipa_oauth_client_secret', '' );
        if ( $id === '' && function_exists( 'gs_oauth_client_id' ) ) {
            $id     = gs_oauth_client_id();
            $secret = function_exists( 'gs_oauth_client_secret' ) ? gs_oauth_client_secret() : '';
        }
        $hub = (string) get_site_option( 'aipa_central_hub_url', '' );
        if ( $hub === '' ) {
            $hub = function_exists( 'gs_oauth_hub_url' ) ? gs_oauth_hub_url() : self::hub_base();
        }
        return array(
            'id'     => $id,
            'secret' => $secret,
            'hub'    => untrailingslashit( $hub ),
        );
    }

    /**
     * Public accessor for the resolved OAuth client. The AI WIDGET must
     * AUTHORIZE with the exact same client_id (and hub) that this proxy will
     * later EXCHANGE the code with — if the authorize step uses one client
     * (e.g. gend-society's GDC_OAUTH_CLIENT_ID constant, XuyL…) and the
     * exchange uses another (e.g. the aipa_oauth_client_id site option,
     * W1MTC…), WP-OAuth Server rejects the redemption with invalid_grant
     * ("code invalid for the client"): observed as "click Sign in → popup
     * shows Authorizing… → closes → user still anonymous, no login."
     * ai-widget.php reads this so both halves stay in lockstep.
     */
    public static function widget_oauth_client() {
        return self::aipa_client();
    }

    public static function route_oauth_status( WP_REST_Request $request ) {
        $uid       = get_current_user_id();
        $connected = $uid && get_user_meta( $uid, 'gend_oauth_token', true ) !== '';
        $client    = self::aipa_client();
        return new WP_REST_Response( array(
            'connected'  => (bool) $connected,
            'hub_url'    => $client['hub'],
            'client_id'  => $client['id'],
            'user_id'    => $uid,
        ), 200 );
    }

    public static function route_oauth_sync( WP_REST_Request $request ) {
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        if ( $token === '' ) {
            return new WP_Error( 'missing_token', 'Token is required', array( 'status' => 400 ) );
        }
        update_user_meta( get_current_user_id(), 'gend_oauth_token', $token );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    public static function route_oauth_revoke( WP_REST_Request $request ) {
        delete_user_meta( get_current_user_id(), 'gend_oauth_token' );
        delete_user_meta( get_current_user_id(), 'gend_oauth_refresh_token' );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * POST /aipa/v1/oauth/exchange (local). Exchanges the authorization
     * code for a token at the hub using gend-society's client + the
     * canonical redirect_uri, stores it for the subsite user, logs guests
     * in, and returns the shape LEO's widget expects.
     */
    public static function route_oauth_exchange( WP_REST_Request $request ) {
        if ( ! function_exists( 'gs_oauth_hub_url' ) || ! function_exists( 'gs_oauth_client_id' ) ) {
            return new WP_REST_Response( array( 'error' => 'oauth_unavailable', 'message' => 'gend-society OAuth not loaded.' ), 503 );
        }

        $code     = sanitize_text_field( (string) $request->get_param( 'code' ) );
        $verifier = (string) $request->get_param( 'code_verifier' );
        if ( $code === '' ) {
            return new WP_REST_Response( array( 'error' => 'missing_code', 'message' => 'Authorization code is required' ), 400 );
        }
        if ( $verifier !== '' && ! preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', $verifier ) ) {
            return new WP_REST_Response( array( 'error' => 'invalid_verifier', 'message' => 'code_verifier has an invalid format.' ), 400 );
        }

        // Use the AIPA client the widget authorized with (NOT gend-society's
        // own login client), or the exchange fails with invalid_grant.
        $client        = self::aipa_client();
        $hub_url       = $client['hub'];
        $client_id     = $client['id'];
        $client_secret = $client['secret'];
        if ( $client_id === '' ) {
            return new WP_REST_Response( array( 'error' => 'no_client', 'message' => 'OAuth client not configured.' ), 503 );
        }

        $token_body = array(
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $hub_url . '/oauth-bridge/',
            'client_id'    => $client_id,
        );
        if ( $verifier !== '' ) {
            $token_body['code_verifier'] = $verifier;
        }
        $headers = array();
        if ( $client_secret !== '' ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
        }

        error_log( sprintf(
            '[GS OAuth DIAG] LOCAL exchange code_hash=%s code_len=%d verifier_len=%d redirect_uri=%s client_id=%s secret=%s',
            substr( hash( 'sha256', $code ), 0, 12 ),
            strlen( $code ),
            strlen( $verifier ),
            $token_body['redirect_uri'],
            $client_id,
            $client_secret !== '' ? 'present' : 'absent'
        ) );

        $resp = wp_remote_post( $hub_url . '/oauth/token', array(
            'timeout' => 15,
            'headers' => $headers,
            'body'    => $token_body,
        ) );
        if ( is_wp_error( $resp ) ) {
            return new WP_REST_Response( array( 'error' => 'exchange_failed', 'message' => $resp->get_error_message() ), 502 );
        }

        $status = (int) wp_remote_retrieve_response_code( $resp );
        $clean  = trim( str_replace( "\xEF\xBB\xBF", '', (string) wp_remote_retrieve_body( $resp ) ) );
        $body   = json_decode( $clean, true );
        if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $clean, $m ) ) {
            $body = json_decode( $m[1], true );
        }
        error_log( '[GS OAuth DIAG] LOCAL token endpoint HTTP ' . $status . ' body=' . substr( $clean, 0, 400 ) );

        if ( $status !== 200 || empty( $body['access_token'] ) ) {
            return new WP_REST_Response( array(
                'error'   => $body['error'] ?? 'exchange_failed',
                'message' => $body['error_description'] ?? $body['error'] ?? ( 'Token exchange failed (HTTP ' . $status . ')' ),
                'raw'     => $body ?: array( 'snippet' => substr( $clean, 0, 300 ) ),
            ), $status ?: 502 );
        }

        $access_token  = (string) $body['access_token'];
        $refresh_token = (string) ( $body['refresh_token'] ?? '' );
        $expires_in    = (int) ( $body['expires_in'] ?? 3600 );

        // Persist for the current (logged-in) user immediately.
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), 'gend_oauth_token', $access_token );
            if ( $refresh_token !== '' ) {
                update_user_meta( get_current_user_id(), 'gend_oauth_refresh_token', $refresh_token );
            }
            update_user_meta( get_current_user_id(), 'gend_oauth_token_expires_at', time() + max( 60, $expires_in ) );
        } else {
            // Guest sign-in: resolve the gend.me user by email and log them
            // into THIS subsite (the whole point of doing the exchange
            // locally rather than on the hub).
            $userinfo_url = ( defined( 'GDC_OAUTH_USERINFO_URL' ) && GDC_OAUTH_USERINFO_URL )
                ? (string) GDC_OAUTH_USERINFO_URL
                : ( $hub_url . '/oauth/me' );
            $info_resp = wp_remote_get( $userinfo_url, array(
                'timeout' => 10,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            ) );
            if ( ! is_wp_error( $info_resp ) ) {
                $iclean = trim( str_replace( "\xEF\xBB\xBF", '', (string) wp_remote_retrieve_body( $info_resp ) ) );
                $info   = json_decode( $iclean, true );
                if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $iclean, $mi ) ) {
                    $info = json_decode( $mi[1], true );
                }
                $email = '';
                if ( is_array( $info ) ) {
                    foreach ( array( 'email', 'user_email', 'preferred_email' ) as $k ) {
                        if ( ! empty( $info[ $k ] ) ) { $email = (string) $info[ $k ]; break; }
                    }
                    if ( $email === '' && ! empty( $info['data']['user']['email'] ) ) {
                        $email = (string) $info['data']['user']['email'];
                    }
                }
                $email = sanitize_email( $email );
                if ( $email !== '' ) {
                    $user = get_user_by( 'email', $email );
                    if ( $user ) {
                        update_user_meta( $user->ID, 'gend_oauth_token', $access_token );
                        if ( $refresh_token !== '' ) {
                            update_user_meta( $user->ID, 'gend_oauth_refresh_token', $refresh_token );
                        }
                        update_user_meta( $user->ID, 'gend_oauth_token_expires_at', time() + max( 60, $expires_in ) );
                        wp_set_current_user( $user->ID, $user->user_login );
                        wp_set_auth_cookie( $user->ID, true );
                        do_action( 'wp_login', $user->user_login, $user );
                    }
                }
            }
        }

        return new WP_REST_Response( array(
            'access_token' => $access_token,
            'token_type'   => $body['token_type'] ?? 'Bearer',
            'expires_in'   => $expires_in,
            'has_refresh'  => $refresh_token !== '',
        ), 200 );
    }

    /**
     * Transparent forwarder for any aipa/v1/<path> request. Preserves
     * method, query string, body, and content-type; attaches the bearer.
     */
    public static function route_aipa_forward( WP_REST_Request $request ) {
        // Defense in depth: if WP's route matching let this catch-all win
        // over the concrete /aipa/v1/oauth/* routes, delegate oauth paths
        // to the LOCAL handlers instead of forwarding to the hub. OAuth must
        // run on the subsite (the authorize code is bound to this site's
        // client + PKCE challenge); forwarding it caused "invalid_grant".
        $oauth_path = ltrim( (string) $request->get_param( 'gs_path' ), '/' );
        if ( strpos( $oauth_path, 'oauth/' ) === 0 ) {
            $sub = substr( $oauth_path, strlen( 'oauth/' ) );
            if ( $sub === 'exchange' ) return self::route_oauth_exchange( $request );
            if ( $sub === 'status' )   return self::route_oauth_status( $request );
            if ( $sub === 'sync' )     return self::route_oauth_sync( $request );
            if ( $sub === 'revoke' )   return self::route_oauth_revoke( $request );
        }

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
        $h = array(
            'Authorization'      => 'Bearer ' . $bearer,
            'X-Gend-Token'       => $bearer,
            'X-Gend-Source-Blog' => (string) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ),
            'X-Gend-Source-Host' => isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '',
        );
        // Forward chatflow + project-task linkage when the caller (chat
        // widget, desktop app, plugin) provides them. The hub records
        // them in aipa_usage.meta so Payments → AI Tokens can break
        // spend down by chatflow and deep-link to the project task.
        foreach ( array(
            'HTTP_X_GEND_CHATFLOW'   => 'X-Gend-Chatflow',
            'HTTP_X_GEND_TASK_ID'    => 'X-Gend-Task-Id',
            'HTTP_X_GEND_TASK_LABEL' => 'X-Gend-Task-Label',
        ) as $srv => $hdr ) {
            if ( ! empty( $_SERVER[ $srv ] ) ) {
                $h[ $hdr ] = sanitize_text_field( wp_unslash( $_SERVER[ $srv ] ) );
            }
        }
        return $h;
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
