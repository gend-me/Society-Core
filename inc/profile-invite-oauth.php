<?php
/**
 * Profile Invite — OAuth flows for Google & Microsoft contacts import.
 *
 * Apple does NOT have a public Contacts API. "Sign in with Apple" only
 * returns the signed-in user's email + name, not their address book. The
 * Apple integration in the Invite UI is therefore a vCard (.vcf) upload
 * parsed client-side — Apple Contacts.app exports natively to .vcf.
 *
 * Storage:
 *   - Per-user OAuth tokens in user meta `gs_invite_oauth_<provider>` as
 *     an array { access_token, refresh_token, expires, scope }.
 *   - Site-wide credentials (client_id / client_secret) in wp_options.
 *
 * REST endpoints (all require auth):
 *   GET  gs/v1/invite/oauth/auth-url?provider=google|microsoft
 *        Returns { url } — frontend opens this in a popup.
 *   GET  gs/v1/invite/oauth/callback?provider=&code=&state=
 *        Provider redirects here. Exchanges code → tokens, stores them,
 *        renders a tiny HTML page that postMessages window.opener and
 *        closes itself.
 *   GET  gs/v1/invite/oauth/status?provider=
 *        Returns { connected: bool, expires: int|null }.
 *   POST gs/v1/invite/oauth/disconnect?provider=
 *        Clears stored tokens.
 *   GET  gs/v1/invite/contacts?provider=
 *        Returns { contacts: [{ email, name }, ...] } — the actual fetch.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Provider config. Endpoints, scopes, response shapes.
 */
function gs_invite_oauth_provider_config( $provider ) {
    $providers = array(
        'google' => array(
            'auth_url'  => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope'     => 'https://www.googleapis.com/auth/contacts.readonly',
            'extra'     => array( 'access_type' => 'offline', 'prompt' => 'consent' ),
        ),
        'microsoft' => array(
            'auth_url'  => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope'     => 'offline_access Contacts.Read User.Read',
            'extra'     => array( 'response_mode' => 'query' ),
        ),
    );
    return isset( $providers[ $provider ] ) ? $providers[ $provider ] : null;
}

/**
 * The redirect URI we register with each provider. Must match exactly.
 */
function gs_invite_oauth_redirect_uri() {
    return rest_url( 'gs/v1/invite/oauth/callback' );
}

/**
 * Site-wide credentials — entered by an admin in the Settings page.
 */
function gs_invite_oauth_credentials( $provider ) {
    $opts = get_option( 'gs_invite_oauth_credentials', array() );
    if ( ! is_array( $opts ) ) return null;
    $key = $provider;
    if ( ! isset( $opts[ $key ] ) || empty( $opts[ $key ]['client_id'] ) || empty( $opts[ $key ]['client_secret'] ) ) {
        return null;
    }
    return array(
        'client_id'     => (string) $opts[ $key ]['client_id'],
        'client_secret' => (string) $opts[ $key ]['client_secret'],
    );
}

/**
 * State-token helpers — HMAC-signed { user_id, provider, nonce, iat } that
 * we round-trip through the provider so the callback can prove the redirect
 * is for this WP user. 10-minute TTL.
 */
function gs_invite_oauth_pack_state( $user_id, $provider ) {
    $payload = array(
        'u'   => (int) $user_id,
        'p'   => (string) $provider,
        'n'   => wp_generate_password( 16, false ),
        'iat' => time(),
    );
    $json = wp_json_encode( $payload );
    $b64  = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
    $sig  = hash_hmac( 'sha256', $b64, wp_salt( 'auth' ) );
    return $b64 . '.' . $sig;
}
function gs_invite_oauth_unpack_state( $state ) {
    if ( ! is_string( $state ) || strpos( $state, '.' ) === false ) return null;
    list( $b64, $sig ) = explode( '.', $state, 2 );
    $expect = hash_hmac( 'sha256', $b64, wp_salt( 'auth' ) );
    if ( ! hash_equals( $expect, $sig ) ) return null;
    $json = base64_decode( strtr( $b64, '-_', '+/' ) );
    $data = json_decode( $json, true );
    if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['p'] ) || empty( $data['iat'] ) ) return null;
    if ( time() - (int) $data['iat'] > 600 ) return null; // 10 minute TTL
    return $data;
}

/**
 * Build the authorization URL the frontend opens in a popup.
 */
function gs_invite_oauth_auth_url( $user_id, $provider ) {
    $cfg  = gs_invite_oauth_provider_config( $provider );
    $cred = gs_invite_oauth_credentials( $provider );
    if ( ! $cfg || ! $cred ) return null;
    $params = array_merge( array(
        'client_id'     => $cred['client_id'],
        'redirect_uri'  => gs_invite_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope'         => $cfg['scope'],
        'state'         => gs_invite_oauth_pack_state( $user_id, $provider ),
    ), isset( $cfg['extra'] ) ? $cfg['extra'] : array() );
    return $cfg['auth_url'] . '?' . http_build_query( $params );
}

/**
 * Exchange authorization code for access + refresh tokens. Stores them in
 * user meta keyed by provider.
 */
function gs_invite_oauth_exchange_code( $user_id, $provider, $code ) {
    $cfg  = gs_invite_oauth_provider_config( $provider );
    $cred = gs_invite_oauth_credentials( $provider );
    if ( ! $cfg || ! $cred ) return new WP_Error( 'gs_invite_oauth_cfg', 'Provider not configured.' );

    $resp = wp_remote_post( $cfg['token_url'], array(
        'timeout' => 15,
        'headers' => array( 'Accept' => 'application/json' ),
        'body'    => array(
            'client_id'     => $cred['client_id'],
            'client_secret' => $cred['client_secret'],
            'redirect_uri'  => gs_invite_oauth_redirect_uri(),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
        ),
    ) );
    if ( is_wp_error( $resp ) ) return $resp;
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $body['access_token'] ) ) {
        return new WP_Error( 'gs_invite_oauth_token', 'Token exchange failed.', $body );
    }

    $tokens = array(
        'access_token'  => (string) $body['access_token'],
        'refresh_token' => isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '',
        'expires'       => time() + (int) ( $body['expires_in'] ?? 3600 ) - 30,
        'scope'         => isset( $body['scope'] ) ? (string) $body['scope'] : $cfg['scope'],
    );
    update_user_meta( $user_id, 'gs_invite_oauth_' . $provider, $tokens );
    return $tokens;
}

/**
 * Return a valid access token for $user_id/$provider, refreshing if expired.
 */
function gs_invite_oauth_get_access_token( $user_id, $provider ) {
    $tokens = get_user_meta( $user_id, 'gs_invite_oauth_' . $provider, true );
    if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
        return new WP_Error( 'gs_invite_oauth_none', 'Not connected.' );
    }
    if ( ! empty( $tokens['expires'] ) && time() < (int) $tokens['expires'] ) {
        return $tokens['access_token'];
    }
    if ( empty( $tokens['refresh_token'] ) ) {
        return new WP_Error( 'gs_invite_oauth_expired', 'Token expired and no refresh token.' );
    }

    $cfg  = gs_invite_oauth_provider_config( $provider );
    $cred = gs_invite_oauth_credentials( $provider );
    if ( ! $cfg || ! $cred ) return new WP_Error( 'gs_invite_oauth_cfg', 'Provider not configured.' );

    $resp = wp_remote_post( $cfg['token_url'], array(
        'timeout' => 15,
        'headers' => array( 'Accept' => 'application/json' ),
        'body'    => array(
            'client_id'     => $cred['client_id'],
            'client_secret' => $cred['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ),
    ) );
    if ( is_wp_error( $resp ) ) return $resp;
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $body['access_token'] ) ) {
        return new WP_Error( 'gs_invite_oauth_refresh', 'Token refresh failed.', $body );
    }
    $tokens['access_token'] = (string) $body['access_token'];
    $tokens['expires']      = time() + (int) ( $body['expires_in'] ?? 3600 ) - 30;
    if ( ! empty( $body['refresh_token'] ) ) $tokens['refresh_token'] = (string) $body['refresh_token'];
    update_user_meta( $user_id, 'gs_invite_oauth_' . $provider, $tokens );
    return $tokens['access_token'];
}

/**
 * Fetch contacts from the provider's API. Returns an array of
 * { email, name } items, deduplicated by email.
 */
function gs_invite_oauth_fetch_contacts( $user_id, $provider ) {
    $token = gs_invite_oauth_get_access_token( $user_id, $provider );
    if ( is_wp_error( $token ) ) return $token;

    $contacts = array();

    if ( $provider === 'google' ) {
        // Google People API. Paginate up to 1000 connections per call; one page is plenty for most users.
        $url = 'https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses&pageSize=1000';
        $resp = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        ) );
        if ( is_wp_error( $resp ) ) return $resp;
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) return new WP_Error( 'gs_invite_oauth_fetch', 'Google fetch failed.' );
        if ( ! empty( $body['error'] ) ) {
            return new WP_Error( 'gs_invite_oauth_fetch', $body['error']['message'] ?? 'Google fetch failed.' );
        }
        if ( ! empty( $body['connections'] ) && is_array( $body['connections'] ) ) {
            foreach ( $body['connections'] as $person ) {
                $name = '';
                if ( ! empty( $person['names'][0]['displayName'] ) ) {
                    $name = (string) $person['names'][0]['displayName'];
                }
                if ( empty( $person['emailAddresses'] ) ) continue;
                foreach ( $person['emailAddresses'] as $em ) {
                    if ( ! empty( $em['value'] ) ) {
                        $contacts[] = array( 'email' => (string) $em['value'], 'name' => $name );
                    }
                }
            }
        }
    } elseif ( $provider === 'microsoft' ) {
        // Microsoft Graph contacts. /me/contacts is the personal address book.
        $url = 'https://graph.microsoft.com/v1.0/me/contacts?$top=1000&$select=displayName,emailAddresses';
        $resp = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        ) );
        if ( is_wp_error( $resp ) ) return $resp;
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) return new WP_Error( 'gs_invite_oauth_fetch', 'Microsoft fetch failed.' );
        if ( ! empty( $body['error'] ) ) {
            return new WP_Error( 'gs_invite_oauth_fetch', $body['error']['message'] ?? 'Microsoft fetch failed.' );
        }
        if ( ! empty( $body['value'] ) && is_array( $body['value'] ) ) {
            foreach ( $body['value'] as $contact ) {
                $name = isset( $contact['displayName'] ) ? (string) $contact['displayName'] : '';
                if ( empty( $contact['emailAddresses'] ) ) continue;
                foreach ( $contact['emailAddresses'] as $em ) {
                    if ( ! empty( $em['address'] ) ) {
                        $contacts[] = array( 'email' => (string) $em['address'], 'name' => $name );
                    }
                }
            }
        }
    } else {
        return new WP_Error( 'gs_invite_oauth_provider', 'Unknown provider.' );
    }

    // Dedupe by email
    $seen = array();
    $out  = array();
    foreach ( $contacts as $c ) {
        $key = strtolower( trim( $c['email'] ) );
        if ( ! $key || isset( $seen[ $key ] ) ) continue;
        $seen[ $key ] = true;
        $out[] = $c;
    }
    return $out;
}

/* =============================================================
   REST routes
   ============================================================= */

add_action( 'rest_api_init', 'gs_invite_oauth_register_routes' );
function gs_invite_oauth_register_routes() {
    $auth = function () {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'gs_invite_auth', 'Login required.', array( 'status' => 401 ) );
    };

    register_rest_route( 'gs/v1', '/invite/oauth/auth-url', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'gs_invite_oauth_rest_auth_url',
        'permission_callback' => $auth,
        'args'                => array( 'provider' => array( 'required' => true, 'type' => 'string' ) ),
    ) );

    // Callback uses no auth — provider redirects user here, browser cookies
    // identify the WP user. We rely on the signed state token and the WP
    // session cookie to validate the request.
    register_rest_route( 'gs/v1', '/invite/oauth/callback', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'gs_invite_oauth_rest_callback',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'gs/v1', '/invite/oauth/status', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'gs_invite_oauth_rest_status',
        'permission_callback' => $auth,
        'args'                => array( 'provider' => array( 'required' => true, 'type' => 'string' ) ),
    ) );

    register_rest_route( 'gs/v1', '/invite/oauth/disconnect', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'gs_invite_oauth_rest_disconnect',
        'permission_callback' => $auth,
        'args'                => array( 'provider' => array( 'required' => true, 'type' => 'string' ) ),
    ) );

    register_rest_route( 'gs/v1', '/invite/oauth/contacts', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'gs_invite_oauth_rest_contacts',
        'permission_callback' => $auth,
        'args'                => array( 'provider' => array( 'required' => true, 'type' => 'string' ) ),
    ) );
}

function gs_invite_oauth_rest_auth_url( WP_REST_Request $req ) {
    $provider = sanitize_key( $req->get_param( 'provider' ) );
    $url = gs_invite_oauth_auth_url( get_current_user_id(), $provider );
    if ( ! $url ) {
        return new WP_Error( 'gs_invite_oauth_cfg', 'Provider not configured. An admin must add OAuth credentials.', array( 'status' => 400 ) );
    }
    return rest_ensure_response( array( 'ok' => true, 'url' => $url ) );
}

function gs_invite_oauth_rest_callback( WP_REST_Request $req ) {
    $provider = sanitize_key( $req->get_param( 'provider' ) );
    $code     = (string) $req->get_param( 'code' );
    $state    = (string) $req->get_param( 'state' );
    $error    = (string) $req->get_param( 'error' );

    if ( $error ) {
        return gs_invite_oauth_render_callback_page( false, "Provider returned error: $error" );
    }

    $st = gs_invite_oauth_unpack_state( $state );
    if ( ! $st ) {
        return gs_invite_oauth_render_callback_page( false, 'Invalid or expired state token.' );
    }
    $provider = $provider ?: $st['p'];
    $user_id  = (int) $st['u'];

    // WordPress REST endpoints don't auto-apply cookie authentication —
    // rest_cookie_check_errors() requires an X-WP-Nonce header, and a
    // top-level browser redirect from Google/Microsoft can't carry one.
    // So get_current_user_id() returns 0 here even though the browser IS
    // logged in. Manually validate the LOGGED_IN_COOKIE.
    $current_user_id = get_current_user_id();
    if ( ! $current_user_id ) {
        $cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
        if ( $cookie_user_id ) {
            $current_user_id = (int) $cookie_user_id;
            wp_set_current_user( $current_user_id );
        }
    }
    if ( ! $current_user_id || $current_user_id !== $user_id ) {
        return gs_invite_oauth_render_callback_page( false, 'Authentication mismatch — please retry from your profile.' );
    }
    if ( ! $code ) {
        return gs_invite_oauth_render_callback_page( false, 'No authorization code returned.' );
    }

    $tokens = gs_invite_oauth_exchange_code( $user_id, $provider, $code );
    if ( is_wp_error( $tokens ) ) {
        return gs_invite_oauth_render_callback_page( false, $tokens->get_error_message() );
    }
    return gs_invite_oauth_render_callback_page( true, "Connected to " . ucfirst( $provider ) . ".", $provider );
}

/**
 * Render a tiny self-closing HTML page that postMessages the parent and
 * closes itself. This is the popup window's terminal view.
 */
function gs_invite_oauth_render_callback_page( $success, $message, $provider = '' ) {
    $payload = array(
        'gs_invite_oauth' => true,
        'success'         => (bool) $success,
        'message'         => (string) $message,
        'provider'        => (string) $provider,
    );
    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    ?><!doctype html>
<meta charset="utf-8">
<title>OAuth — <?php echo esc_html( $success ? 'Success' : 'Failed' ); ?></title>
<style>
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
         background:#0b0e14;color:#e2e8f0;display:flex;align-items:center;justify-content:center;
         min-height:100vh;padding:24px;text-align:center;}
    .box{max-width:480px;}
    .icon{font-size:42px;margin-bottom:12px;}
    .ok{color:#34d399;} .err{color:#f87171;}
    h1{font-size:1.1rem;margin:0 0 8px;}
    p{color:#94a3b8;font-size:0.9rem;line-height:1.5;}
</style>
<div class="box">
    <div class="icon <?php echo $success ? 'ok' : 'err'; ?>"><?php echo $success ? '&#10003;' : '&#10007;'; ?></div>
    <h1><?php echo esc_html( $success ? 'Connected' : 'Connection failed' ); ?></h1>
    <p><?php echo esc_html( $message ); ?></p>
    <p style="font-size:0.8rem;opacity:0.7;">This window will close automatically.</p>
</div>
<script>
(function(){
    var payload = <?php echo wp_json_encode( $payload ); ?>;
    try {
        if ( window.opener && ! window.opener.closed ) {
            window.opener.postMessage( payload, window.location.origin );
        }
    } catch ( e ) {}
    setTimeout( function(){ window.close(); }, 1500 );
})();
</script>
<?php
    exit;
}

function gs_invite_oauth_rest_status( WP_REST_Request $req ) {
    $provider = sanitize_key( $req->get_param( 'provider' ) );
    $tokens   = get_user_meta( get_current_user_id(), 'gs_invite_oauth_' . $provider, true );
    $connected = is_array( $tokens ) && ! empty( $tokens['access_token'] );
    return rest_ensure_response( array(
        'ok'        => true,
        'connected' => $connected,
        'expires'   => $connected ? (int) ( $tokens['expires'] ?? 0 ) : null,
    ) );
}

function gs_invite_oauth_rest_disconnect( WP_REST_Request $req ) {
    $provider = sanitize_key( $req->get_param( 'provider' ) );
    delete_user_meta( get_current_user_id(), 'gs_invite_oauth_' . $provider );
    return rest_ensure_response( array( 'ok' => true ) );
}

function gs_invite_oauth_rest_contacts( WP_REST_Request $req ) {
    $provider = sanitize_key( $req->get_param( 'provider' ) );
    $contacts = gs_invite_oauth_fetch_contacts( get_current_user_id(), $provider );
    if ( is_wp_error( $contacts ) ) {
        return new WP_Error( $contacts->get_error_code(), $contacts->get_error_message(), array( 'status' => 400 ) );
    }
    return rest_ensure_response( array( 'ok' => true, 'contacts' => $contacts ) );
}
