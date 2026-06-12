<?php
/**
 * Web Shell — gcloud per-user OAuth + creds escrow (Phase 23).
 *
 * Lets a user link their Google account so `gcloud` inside the
 * Web Shell terminal runs AS THEM, not as a shared service account.
 *
 * Flow:
 *   1. Browser hits /wp-json/gs/v1/web-shell/gcloud/start
 *      → returns { authorize_url } with the Google OAuth URL.
 *   2. User redirects; Google bounces back to
 *      https://gend.me/wp-json/gs/v1/web-shell/gcloud/callback
 *      with `code` + `state`.
 *   3. Callback exchanges code → refresh_token + access_token,
 *      encrypts with openssl AES-256-GCM under GS_GCLOUD_CREDS_KEY,
 *      stores in user_meta `_gs_gcloud_creds` as base64.
 *   4. Terminal session-start (Phase 22) calls
 *      gs_web_shell_gcloud_get_adc_for_user( $user_id ) which
 *      decrypts the refresh token + builds an
 *      application_default_credentials.json payload for the PTY
 *      service to drop into /home/gend/.config/gcloud/.
 *
 * Required constants (define in wp-config.php or as env vars):
 *   GS_GCLOUD_OAUTH_CLIENT_ID      OAuth client (web app) id from GCP console
 *   GS_GCLOUD_OAUTH_CLIENT_SECRET  matching client secret
 *   GS_GCLOUD_CREDS_KEY            32-byte hex key used to encrypt refresh
 *                                  tokens at rest (openssl rand -hex 32).
 *                                  ROTATING this nukes everyone's stored
 *                                  creds — they'll need to reconnect.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'gs_gcloud_cfg' ) ) {

    function gs_gcloud_cfg( $key ) {
        $const = 'GS_GCLOUD_' . strtoupper( $key );
        if ( defined( $const ) ) return (string) constant( $const );
        $envk = 'GS_GCLOUD_' . strtoupper( $key );
        $v = getenv( $envk );
        return $v === false ? '' : (string) $v;
    }

    function gs_gcloud_redirect_uri() {
        return rest_url( 'gs/v1/web-shell/gcloud/callback' );
    }

    function gs_gcloud_scopes() {
        // Same scope bundle gcloud's own auth flow asks for so any
        // command the user runs in the Web Shell terminal has the
        // perms they'd have logging in via the CLI directly.
        return implode( ' ', array(
            'https://www.googleapis.com/auth/cloud-platform',
            'https://www.googleapis.com/auth/appengine.admin',
            'https://www.googleapis.com/auth/compute',
            'https://www.googleapis.com/auth/accounts.reauth',
            'openid',
            'email',
            'profile',
        ) );
    }
}

/* ────────────────── encryption ────────────────── */

if ( ! function_exists( 'gs_gcloud_encrypt' ) ) {

    function gs_gcloud_encrypt( $plain ) {
        $keyHex = gs_gcloud_cfg( 'creds_key' );
        if ( $keyHex === '' || ! ctype_xdigit( $keyHex ) || strlen( $keyHex ) !== 64 ) {
            return new WP_Error( 'gs_gcloud_no_key', 'GS_GCLOUD_CREDS_KEY missing or not 32 bytes hex.' );
        }
        $key = hex2bin( $keyHex );
        $iv  = random_bytes( 12 ); // GCM nonce
        $tag = '';
        $ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( $ct === false ) return new WP_Error( 'gs_gcloud_encrypt', 'openssl_encrypt failed.' );
        return base64_encode( $iv . $tag . $ct );
    }

    function gs_gcloud_decrypt( $b64 ) {
        $keyHex = gs_gcloud_cfg( 'creds_key' );
        if ( $keyHex === '' || strlen( $keyHex ) !== 64 ) return new WP_Error( 'gs_gcloud_no_key', 'GS_GCLOUD_CREDS_KEY missing.' );
        $key = hex2bin( $keyHex );
        $bin = base64_decode( (string) $b64, true );
        if ( $bin === false || strlen( $bin ) < 28 ) return new WP_Error( 'gs_gcloud_bad_cipher', 'Cipher blob malformed.' );
        $iv  = substr( $bin, 0, 12 );
        $tag = substr( $bin, 12, 16 );
        $ct  = substr( $bin, 28 );
        $pt  = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $pt === false ) return new WP_Error( 'gs_gcloud_decrypt', 'openssl_decrypt failed (key rotated?).' );
        return $pt;
    }
}

/* ────────────────── REST routes ────────────────── */

if ( ! function_exists( 'gs_gcloud_register_rest' ) ) {

    function gs_gcloud_register_rest() {
        register_rest_route( 'gs/v1', '/web-shell/gcloud/start', array(
            'methods'             => 'GET',
            'callback'            => 'gs_gcloud_rest_start',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );
        register_rest_route( 'gs/v1', '/web-shell/gcloud/callback', array(
            'methods'             => 'GET',
            'callback'            => 'gs_gcloud_rest_callback',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );
        register_rest_route( 'gs/v1', '/web-shell/gcloud/status', array(
            'methods'             => 'GET',
            'callback'            => 'gs_gcloud_rest_status',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );
        register_rest_route( 'gs/v1', '/web-shell/gcloud/disconnect', array(
            'methods'             => 'POST',
            'callback'            => 'gs_gcloud_rest_disconnect',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );
        // Internal: PTY service fetches a user's ADC at session start.
        // Auth = shared GS_SHELL_JWT_SECRET (re-used so we don't mint a
        // separate service-to-service secret). The PTY service signs
        // its own JWT with `{ aud: "wp-hub", iss: "web-shell-pty", user_id, exp }`
        // and we verify here.
        register_rest_route( 'gs/v1', '/web-shell/gcloud/adc', array(
            'methods'             => 'POST',
            'callback'            => 'gs_gcloud_rest_adc',
            'permission_callback' => '__return_true', // verified inside via service JWT
        ) );
    }
    add_action( 'rest_api_init', 'gs_gcloud_register_rest' );
}

if ( ! function_exists( 'gs_gcloud_rest_start' ) ) {

    function gs_gcloud_rest_start() {
        $client_id = gs_gcloud_cfg( 'oauth_client_id' );
        if ( $client_id === '' ) {
            return new WP_Error( 'gs_gcloud_no_client', 'GS_GCLOUD_OAUTH_CLIENT_ID not configured on the hub.', array( 'status' => 503 ) );
        }
        $state = wp_generate_password( 24, false, false );
        set_transient( 'gs_gcloud_state_' . get_current_user_id(), $state, 600 );
        $authorize = add_query_arg( array(
            'client_id'              => $client_id,
            'redirect_uri'           => gs_gcloud_redirect_uri(),
            'response_type'          => 'code',
            'scope'                  => gs_gcloud_scopes(),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',           // force refresh-token reissue
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ), 'https://accounts.google.com/o/oauth2/v2/auth' );
        return array( 'authorize_url' => $authorize, 'state' => $state );
    }
}

if ( ! function_exists( 'gs_gcloud_rest_callback' ) ) {

    function gs_gcloud_rest_callback( WP_REST_Request $req ) {
        $code  = (string) $req->get_param( 'code' );
        $state = (string) $req->get_param( 'state' );
        $uid   = get_current_user_id();
        if ( $code === '' ) return new WP_Error( 'no_code', 'Missing authorization code.', array( 'status' => 400 ) );
        $expected = get_transient( 'gs_gcloud_state_' . $uid );
        if ( ! $expected || ! hash_equals( (string) $expected, $state ) ) {
            return new WP_Error( 'bad_state', 'State mismatch — restart the flow.', array( 'status' => 400 ) );
        }
        delete_transient( 'gs_gcloud_state_' . $uid );

        $client_id     = gs_gcloud_cfg( 'oauth_client_id' );
        $client_secret = gs_gcloud_cfg( 'oauth_client_secret' );
        $resp = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body'    => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => gs_gcloud_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ),
        ) );
        if ( is_wp_error( $resp ) ) return $resp;
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) || empty( $body['refresh_token'] ) ) {
            // Google only returns refresh_token on the FIRST consent;
            // re-grant by sending prompt=consent (we already do) usually
            // forces it. If it's still missing, surface a clear error.
            return new WP_Error( 'no_refresh', 'Google did not return a refresh_token. Try revoking the app at myaccount.google.com/permissions and reconnect.', array( 'status' => 502, 'google' => $body ) );
        }

        // Fetch the user's Google email/sub so we can show "connected as
        // foo@bar.com" in the UI without storing extra calls.
        $google_email = '';
        if ( ! empty( $body['access_token'] ) ) {
            $ui = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', array(
                'timeout' => 8,
                'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
            ) );
            if ( ! is_wp_error( $ui ) ) {
                $u = json_decode( wp_remote_retrieve_body( $ui ), true );
                if ( is_array( $u ) && ! empty( $u['email'] ) ) $google_email = (string) $u['email'];
            }
        }

        $payload = wp_json_encode( array(
            'refresh_token' => $body['refresh_token'],
            'scope'         => $body['scope'] ?? gs_gcloud_scopes(),
            'token_type'    => $body['token_type'] ?? 'Bearer',
            'google_email'  => $google_email,
            'connected_at'  => gmdate( 'c' ),
        ) );
        $cipher = gs_gcloud_encrypt( $payload );
        if ( is_wp_error( $cipher ) ) return $cipher;
        update_user_meta( $uid, '_gs_gcloud_creds', $cipher );
        update_user_meta( $uid, '_gs_gcloud_email', $google_email );

        // Bounce back to the Web Shell terminal tab; the SPA detects the
        // ?gcloud_connected=1 query string and refreshes its status pill.
        wp_safe_redirect( home_url( '/web-shell/terminal/?gcloud_connected=1' ) );
        exit;
    }
}

if ( ! function_exists( 'gs_gcloud_rest_status' ) ) {

    function gs_gcloud_rest_status() {
        $uid   = get_current_user_id();
        $cur   = get_user_meta( $uid, '_gs_gcloud_creds', true );
        $email = get_user_meta( $uid, '_gs_gcloud_email', true );
        return array(
            'connected'         => ! empty( $cur ),
            'google_email'      => $email ?: null,
            'has_oauth_client'  => gs_gcloud_cfg( 'oauth_client_id' ) !== '',
            'has_encryption_key'=> gs_gcloud_cfg( 'creds_key' ) !== '',
        );
    }
}

if ( ! function_exists( 'gs_gcloud_rest_disconnect' ) ) {

    function gs_gcloud_rest_disconnect() {
        $uid = get_current_user_id();
        $cur = get_user_meta( $uid, '_gs_gcloud_creds', true );
        // Best-effort revoke at Google so the refresh token is dead
        // even if our copy were leaked. Failures are non-fatal —
        // wiping our copy is the security-critical step.
        if ( $cur ) {
            $dec = gs_gcloud_decrypt( $cur );
            if ( ! is_wp_error( $dec ) ) {
                $payload = json_decode( $dec, true );
                if ( is_array( $payload ) && ! empty( $payload['refresh_token'] ) ) {
                    wp_remote_post( 'https://oauth2.googleapis.com/revoke', array(
                        'timeout' => 6,
                        'body'    => array( 'token' => $payload['refresh_token'] ),
                    ) );
                }
            }
        }
        delete_user_meta( $uid, '_gs_gcloud_creds' );
        delete_user_meta( $uid, '_gs_gcloud_email' );
        return array( 'connected' => false );
    }
}

if ( ! function_exists( 'gs_gcloud_rest_adc' ) ) {

    /**
     * Internal: PTY service → wp-hub call to fetch a user's ADC at
     * session start. Auth via service JWT signed with the same
     * GS_SHELL_JWT_SECRET (re-used to avoid a second secret).
     *
     * Request:  POST { token: <service-JWT containing {user_id, exp, iss: 'web-shell-pty'}> }
     * Response: { ok: true, adc: {...json...} } or { ok: false, ... }
     */
    function gs_gcloud_rest_adc( WP_REST_Request $req ) {
        $shell_secret = defined( 'GS_SHELL_JWT_SECRET' ) ? (string) GS_SHELL_JWT_SECRET
                       : ( getenv( 'GS_SHELL_JWT_SECRET' ) ?: '' );
        if ( $shell_secret === '' ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_shared_secret' ), 503 );
        }
        $raw = $req->get_json_params();
        if ( ! is_array( $raw ) ) $raw = $req->get_params();
        $tok = (string) ( $raw['token'] ?? '' );
        $decoded = gs_jwt_decode( $tok, $shell_secret );
        if ( is_wp_error( $decoded ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => $decoded->get_error_code() ), 401 );
        }
        if ( ( $decoded['iss'] ?? '' ) !== 'web-shell-pty' || empty( $decoded['user_id'] ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_claims' ), 401 );
        }
        $adc = gs_web_shell_gcloud_get_adc_for_user( (int) $decoded['user_id'] );
        if ( is_wp_error( $adc ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $adc->get_error_code() ), 404 );
        return array( 'ok' => true, 'adc' => $adc );
    }
}

if ( ! function_exists( 'gs_web_shell_gcloud_get_adc_for_user' ) ) {

    /**
     * Build the JSON the gcloud CLI expects at
     * ~/.config/gcloud/application_default_credentials.json so any
     * `gcloud …` call from the user's pod auto-authenticates without
     * a separate `gcloud auth login` step.
     */
    function gs_web_shell_gcloud_get_adc_for_user( $user_id ) {
        $blob = get_user_meta( (int) $user_id, '_gs_gcloud_creds', true );
        if ( ! $blob ) return new WP_Error( 'no_creds', 'User has no stored gcloud creds.' );
        $dec = gs_gcloud_decrypt( $blob );
        if ( is_wp_error( $dec ) ) return $dec;
        $data = json_decode( $dec, true );
        if ( ! is_array( $data ) || empty( $data['refresh_token'] ) ) {
            return new WP_Error( 'corrupt_creds', 'Stored creds are corrupt.' );
        }
        return array(
            'type'          => 'authorized_user',
            'client_id'     => gs_gcloud_cfg( 'oauth_client_id' ),
            'client_secret' => gs_gcloud_cfg( 'oauth_client_secret' ),
            'refresh_token' => $data['refresh_token'],
        );
    }
}

if ( ! function_exists( 'gs_jwt_decode' ) ) {

    /**
     * Minimal HS256 JWT decoder paired with gs_web_shell_jwt_encode.
     * Verifies signature + exp; returns the decoded payload or
     * WP_Error on any failure. Used by /gs/v1/web-shell/gcloud/adc to
     * authenticate the PTY service.
     */
    function gs_jwt_decode( $token, $secret ) {
        $parts = explode( '.', (string) $token );
        if ( count( $parts ) !== 3 ) return new WP_Error( 'bad_token', 'Malformed JWT.' );
        list( $h, $b, $sig ) = $parts;
        $signing  = $h . '.' . $b;
        $expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $signing, $secret, true ) ), '+/', '-_' ), '=' );
        if ( ! hash_equals( $expected, $sig ) ) return new WP_Error( 'bad_sig', 'Bad signature.' );
        $payload = json_decode( base64_decode( strtr( $b, '-_', '+/' ), true ), true );
        if ( ! is_array( $payload ) ) return new WP_Error( 'bad_payload', 'Unparseable payload.' );
        if ( isset( $payload['exp'] ) && (int) $payload['exp'] < time() - 5 ) return new WP_Error( 'expired', 'Token expired.' );
        return $payload;
    }
}

// Allowlist past the network-wide REST gate (matches the other
// /gs/v1/web-shell/* + /gs/v1/desktop/* allowlists).
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! is_wp_error( $result ) ) return $result;
    $route = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( $route !== '' && strpos( $route, '/gs/v1/web-shell/gcloud/' ) !== false ) return null;
    return $result;
}, 100 );
