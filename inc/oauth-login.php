<?php
/**
 * Replace wp-login.php with a "Sign in with gend.me" OAuth flow.
 *
 * Works on every site that has the gend-society plugin active EXCEPT
 * gend.me itself (the hub authenticates users locally).
 *
 * Flow:
 *   1. Customer hits /wp-login.php on their site (e.g. block-migration.gend.me).
 *   2. We render a styled "Sign in with gend.me" button instead of the
 *      stock username/password form.
 *   3. Button click opens a popup to gend.me/oauth/authorize with PKCE.
 *   4. User authorizes on gend.me. gend.me redirects to /oauth-bridge/
 *      which postMessages the {code, state} back to the popup opener.
 *   5. The opener POSTs {code, code_verifier} to our REST endpoint
 *      /wp-json/gend-society/v1/oauth/login.
 *   6. The endpoint exchanges the code for an access token at gend.me/oauth/token,
 *      pulls the user's email from gend.me's userinfo endpoint, finds the
 *      matching local wp_users row (by email), saves the token to user_meta
 *      so other plugins (Leo, contracts-and-payments, etc.) inherit it,
 *      and wp_set_auth_cookie's the user in.
 *   7. The browser redirects to wp-admin (or whatever redirect_to was).
 *
 * Security: the local user MUST already exist with the same email as
 * the gend.me account. We don't auto-provision. That stops "anyone with a
 * gend.me account can log into any customer site as admin" — a customer
 * with WP user "owner@gend.me" can log in; some random gend.me user
 * "stranger@gend.me" cannot.
 *
 * Lost password: native WP lost-password flow uses local wp_users +
 * site SMTP, which on container installs is unconfigured. Redirect the
 * "Lost your password?" link to gend.me's recovery so customers always
 * have a working path.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ────────────────────────────────────────────────────────────────────────
// Configuration helpers
// ────────────────────────────────────────────────────────────────────────

/**
 * Hub URL — defaults to https://gend.me. Override via the
 * GDC_OAUTH_HUB_URL constant (in wp-config.php) or the
 * aipa_central_hub_url site option (set by the Leo plugin).
 */
function gs_oauth_hub_url(): string {
    if ( defined( 'GDC_OAUTH_HUB_URL' ) && GDC_OAUTH_HUB_URL ) {
        return rtrim( (string) GDC_OAUTH_HUB_URL, '/' );
    }
    return rtrim( (string) get_site_option( 'aipa_central_hub_url', 'https://gend.me' ), '/' );
}

function gs_oauth_client_id(): string {
    if ( defined( 'GDC_OAUTH_CLIENT_ID' ) && GDC_OAUTH_CLIENT_ID ) {
        return (string) GDC_OAUTH_CLIENT_ID;
    }
    return (string) get_site_option( 'aipa_oauth_client_id', '' );
}

function gs_oauth_client_secret(): string {
    if ( defined( 'GDC_OAUTH_CLIENT_SECRET' ) && GDC_OAUTH_CLIENT_SECRET ) {
        return (string) GDC_OAUTH_CLIENT_SECRET;
    }
    return (string) get_site_option( 'aipa_oauth_client_secret', '' );
}

/**
 * True when this WordPress install IS the gend.me hub itself. Compared
 * by host so that custom-domain mappings on the hub still match.
 */
function gs_oauth_is_hub_site(): bool {
    $hub_host  = (string) wp_parse_url( gs_oauth_hub_url(), PHP_URL_HOST );
    $self_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    if ( $hub_host === '' || $self_host === '' ) return false;
    return strtolower( $hub_host ) === strtolower( $self_host );
}

/**
 * Master switch — only intercept wp-login when:
 *   - We're NOT the hub
 *   - A client_id is actually configured (otherwise the page would just
 *     show a non-functional button)
 *   - The native form isn't explicitly requested via ?gs_native=1 — the
 *     escape hatch lets a super-admin recover access if the OAuth flow
 *     itself is broken.
 */
function gs_oauth_should_intercept(): bool {
    if ( gs_oauth_is_hub_site() ) return false;
    if ( gs_oauth_client_id() === '' ) return false;
    if ( isset( $_GET['gs_native'] ) ) return false;
    return true;
}

// ────────────────────────────────────────────────────────────────────────
// Login form replacement
// ────────────────────────────────────────────────────────────────────────

/**
 * Replace the default wp-login.php form with our OAuth button.
 * Hooks into login_form_login so it only fires for action=login (default).
 * action=lostpassword / action=register are still handled by core (we
 * filter the lost-password URL separately).
 */
add_action( 'login_form_login', 'gs_oauth_render_login_page' );

function gs_oauth_render_login_page() {
    if ( ! gs_oauth_should_intercept() ) {
        return; // fall through to native form
    }
    if ( is_user_logged_in() ) {
        wp_safe_redirect( admin_url() );
        exit;
    }

    $redirect_to  = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();
    $hub_url      = gs_oauth_hub_url();
    $client_id    = gs_oauth_client_id();
    $rest_login   = esc_url_raw( rest_url( 'gend-society/v1/oauth/login' ) );

    // Render the login page chrome via WP's login_header so theme/plugin
    // login_enqueue_scripts hooks fire (login-style.php's CSS still applies).
    login_header( __( 'Sign in', 'gend-society' ), '' );
    ?>
    <style>
        body.login #loginform,
        body.login #login_error,
        body.login #nav,
        body.login #backtoblog { display: none !important; }
        .gs-oauth-shell {
            background: rgba(255,255,255,0.03) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1) !important;
            border-radius: 24px !important;
            padding: 36px 32px 28px !important;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,.5) !important;
            text-align: center;
            color: #fff;
        }
        .gs-oauth-shell h2 {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .gs-oauth-shell p.gs-oauth-sub {
            margin: 0 0 24px;
            color: rgba(255,255,255,.7);
            font-size: 13.5px;
            line-height: 1.55;
        }
        .gs-oauth-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px 22px;
            border-radius: 14px;
            background: linear-gradient(135deg, #b608c9 0%, #7e058a 100%);
            color: #fff !important;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            border: 0;
            cursor: pointer;
            box-shadow: 0 8px 20px -6px rgba(182,8,201,.4);
            transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
        }
        .gs-oauth-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px -6px rgba(182,8,201,.6);
        }
        .gs-oauth-btn[disabled] { opacity: .55; cursor: wait; transform: none; }
        .gs-oauth-foot {
            margin-top: 22px;
            font-size: 12px;
            color: rgba(255,255,255,.5);
        }
        .gs-oauth-foot a { color: rgba(255,255,255,.7); text-decoration: none; }
        .gs-oauth-foot a:hover { color: #fff; text-decoration: underline; }
        .gs-oauth-error {
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(204,0,0,.12);
            border: 1px solid rgba(204,0,0,.35);
            color: #ff8888;
            font-size: 13px;
            text-align: left;
            display: none;
        }
        .gs-oauth-error.is-visible { display: block; }
    </style>

    <div class="gs-oauth-shell">
        <h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
        <p class="gs-oauth-sub"><?php esc_html_e( 'Sign in with your gend.me account to continue.', 'gend-society' ); ?></p>
        <button id="gs-oauth-btn" type="button" class="gs-oauth-btn">
            <?php esc_html_e( 'Sign in with gend.me', 'gend-society' ); ?>
            <span aria-hidden="true">→</span>
        </button>
        <p class="gs-oauth-foot">
            <a href="<?php echo esc_url( $hub_url . '/wp-login.php?action=lostpassword' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Forgot your password?', 'gend-society' ); ?></a>
        </p>
        <div id="gs-oauth-error" class="gs-oauth-error" role="alert" aria-live="polite"></div>
    </div>

    <script>
    (function () {
        var hubUrl       = <?php echo wp_json_encode( $hub_url ); ?>;
        var clientId     = <?php echo wp_json_encode( $client_id ); ?>;
        var restLoginUrl = <?php echo wp_json_encode( $rest_login ); ?>;
        var redirectTo   = <?php echo wp_json_encode( $redirect_to ); ?>;

        var btn   = document.getElementById('gs-oauth-btn');
        var errEl = document.getElementById('gs-oauth-error');

        function showError(msg) {
            errEl.textContent = msg;
            errEl.classList.add('is-visible');
            btn.disabled = false;
            btn.textContent = '⚠  Try again';
        }

        function base64url(bytes) {
            var s = '';
            for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
            return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }

        async function pkce() {
            var arr = new Uint8Array(32);
            crypto.getRandomValues(arr);
            var verifier = base64url(arr);
            var hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
            return { verifier: verifier, challenge: base64url(new Uint8Array(hash)) };
        }

        btn.addEventListener('click', async function () {
            errEl.classList.remove('is-visible');
            btn.disabled = true;
            btn.textContent = 'Connecting…';

            try {
                var p = await pkce();
                var state = base64url(crypto.getRandomValues(new Uint8Array(16)));

                var authUrl = hubUrl + '/oauth/authorize?' + new URLSearchParams({
                    response_type:         'code',
                    client_id:             clientId,
                    redirect_uri:          hubUrl + '/oauth-bridge/',
                    // WP-OAuth Server only knows 'basic' out of the box;
                    // 'read', 'profile', 'email' would need to be registered
                    // under WP-OAuth → Scopes first. Basic is enough — the
                    // /oauth/me userinfo endpoint returns the user's email
                    // + display_name regardless, and that's all we need to
                    // match the local wp_users row.
                    scope:                 'basic',
                    state:                 state,
                    code_challenge:        p.challenge,
                    code_challenge_method: 'S256'
                }).toString();

                var w = 480, h = 720;
                var x = (window.screen.width  - w) / 2;
                var y = (window.screen.height - h) / 2;
                var popup = window.open(authUrl, 'gs_oauth', 'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
                if (!popup) {
                    showError('Popup blocked. Allow popups from this site and try again.');
                    return;
                }

                var gotMessage = false;
                function onMessage(ev) {
                    if (ev.origin !== hubUrl) return;
                    var d = ev.data;
                    if (!d || d.type !== 'gend_oauth') return;
                    if (d.state && d.state !== state) return;
                    gotMessage = true;
                    if (typeof watchdog !== 'undefined') clearInterval(watchdog);
                    window.removeEventListener('message', onMessage);
                    try { popup.close(); } catch (_) {}

                    if (d.error) {
                        showError(d.error_description || d.error);
                        return;
                    }

                    fetch(restLoginUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: d.code, code_verifier: p.verifier })
                    }).then(function (r) {
                        return r.json().then(function (j) { return { status: r.status, body: j }; });
                    }).then(function (res) {
                        if (res.status >= 200 && res.status < 300 && res.body && res.body.success) {
                            window.location.href = res.body.redirect_to || redirectTo;
                        } else {
                            showError((res.body && res.body.message) || ('Login failed (HTTP ' + res.status + ')'));
                        }
                    }).catch(function (e) {
                        showError(e && e.message ? e.message : 'Network error during login.');
                    });
                }
                window.addEventListener('message', onMessage);

                // Watchdog: if the popup is closed without postMessage, surface
                // a friendly state instead of a permanently-spinning button.
                var watchdog = setInterval(function () {
                    if (popup.closed) {
                        clearInterval(watchdog);
                        if (btn.disabled && !gotMessage) {
                            window.removeEventListener('message', onMessage);
                            showError('Login window was closed before authorization completed.');
                        }
                    }
                }, 600);
            } catch (e) {
                showError(e && e.message ? e.message : 'Could not start login.');
            }
        });
    })();
    </script>
    <?php
    login_footer();
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// REST endpoint: exchange code → token → log local user in
// ────────────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'gend-society/v1', '/oauth/login', array(
        'methods'             => 'POST',
        'callback'            => 'gs_oauth_login_rest',
        'permission_callback' => '__return_true', // exchange happens server-to-server
    ) );
} );

/**
 * Bypass site-wide REST auth filters that some hardening plugins install.
 * Same defensive pattern we use for /backups/recorded — our own
 * authentication happens INSIDE the handler (signed code + email match),
 * so a blanket "must be logged in" filter would block legitimate
 * pre-login OAuth exchange.
 */
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! is_wp_error( $result ) ) return $result;
    $route = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( $route !== '' && strpos( $route, '/gend-society/v1/oauth/login' ) !== false ) {
        return null;
    }
    return $result;
}, 99 );

function gs_oauth_login_rest( WP_REST_Request $req ) {
    $code     = (string) $req->get_param( 'code' );
    $verifier = (string) $req->get_param( 'code_verifier' );
    if ( $code === '' ) {
        return new WP_Error( 'missing_code', 'Authorization code required.', array( 'status' => 400 ) );
    }
    if ( $verifier !== '' && ! preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', $verifier ) ) {
        return new WP_Error( 'bad_verifier', 'Invalid PKCE verifier format.', array( 'status' => 400 ) );
    }

    $hub_url       = gs_oauth_hub_url();
    $client_id     = gs_oauth_client_id();
    $client_secret = gs_oauth_client_secret();
    if ( $client_id === '' ) {
        return new WP_Error( 'no_client', 'OAuth client not configured. Set GDC_OAUTH_CLIENT_ID.', array( 'status' => 503 ) );
    }

    // ── Exchange code → token ───────────────────────────────────────────
    // RFC 6749 §2.3.1 prefers HTTP Basic Auth for confidential clients;
    // many OAuth servers (including some WP-OAuth Server versions) only
    // accept credentials this way and return invalid_client when they're
    // sent as body params. Send via Basic Auth header AND keep client_id
    // in the body — some servers route by client_id from the body even
    // when the secret comes from the header.
    $token_body = array(
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $hub_url . '/oauth-bridge/',
        'client_id'     => $client_id,
    );
    if ( $verifier !== '' ) $token_body['code_verifier'] = $verifier;

    $token_headers = array();
    if ( $client_secret !== '' ) {
        $token_headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
    }

    $resp = wp_remote_post( $hub_url . '/oauth/token', array(
        'timeout' => 15,
        'headers' => $token_headers,
        'body'    => $token_body,
    ) );
    if ( is_wp_error( $resp ) ) {
        return new WP_Error( 'exchange_failed', $resp->get_error_message(), array( 'status' => 502 ) );
    }
    $http_code = (int) wp_remote_retrieve_response_code( $resp );
    $raw_body  = (string) wp_remote_retrieve_body( $resp );
    // gend.me's WP-OAuth Server prefixes its JSON responses with a
    // UTF-8 BOM (\xEF\xBB\xBF) — likely a stray echo / BOM-saved file
    // somewhere on the server. PHP's json_decode returns null on BOM-
    // prefixed input. Strip + retry. Same workaround Leo's
    // aipa_oauth_exchange already uses for the same endpoint.
    $clean_body = trim( str_replace( "\xEF\xBB\xBF", '', $raw_body ) );
    $token      = json_decode( $clean_body, true );
    // Belt-and-suspenders: if there's still leading garbage, regex out
    // the first {…} JSON object.
    if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $clean_body, $m ) ) {
        $token = json_decode( $m[1], true );
    }
    if ( ! is_array( $token ) || empty( $token['access_token'] ) ) {
        // Surface the gend.me-side error verbatim so the on-page toast
        // shows e.g. "invalid_client: client authentication failed"
        // instead of a generic "Token exchange failed."
        $msg = '';
        if ( is_array( $token ) ) {
            if ( ! empty( $token['error_description'] ) ) $msg = (string) $token['error_description'];
            elseif ( ! empty( $token['error'] ) )         $msg = (string) $token['error'];
        }
        if ( $msg === '' ) {
            $snip = substr( trim( $raw_body ), 0, 200 );
            $msg  = 'Token exchange failed (HTTP ' . $http_code . ')' . ( $snip !== '' ? ': ' . $snip : '.' );
        } else {
            $msg = 'Token exchange failed (HTTP ' . $http_code . '): ' . $msg;
        }
        return new WP_Error( 'exchange_failed', $msg, array( 'status' => 502 ) );
    }
    $access_token  = (string) $token['access_token'];
    $refresh_token = (string) ( $token['refresh_token'] ?? '' );
    $expires_in    = (int) ( $token['expires_in'] ?? 3600 );

    // ── Resolve user via gend.me userinfo ──────────────────────────────
    // gend.me's WP-OAuth server exposes /oauth/me. Override via
    // GDC_OAUTH_USERINFO_URL if a different endpoint is canonical
    // (e.g. /wp-json/aipa/v1/me).
    $userinfo_url = defined( 'GDC_OAUTH_USERINFO_URL' ) && GDC_OAUTH_USERINFO_URL
        ? (string) GDC_OAUTH_USERINFO_URL
        : ( $hub_url . '/oauth/me' );
    $info_resp = wp_remote_get( $userinfo_url, array(
        'timeout' => 10,
        'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
    ) );
    if ( is_wp_error( $info_resp ) ) {
        return new WP_Error( 'userinfo_failed', $info_resp->get_error_message(), array( 'status' => 502 ) );
    }
    // Same BOM workaround as the token exchange — gend.me's WP-OAuth
    // userinfo endpoint emits the same prefix and json_decode chokes
    // on BOM-prefixed input.
    $info_raw   = (string) wp_remote_retrieve_body( $info_resp );
    $info_clean = trim( str_replace( "\xEF\xBB\xBF", '', $info_raw ) );
    $info       = json_decode( $info_clean, true );
    if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $info_clean, $m ) ) {
        $info = json_decode( $m[1], true );
    }
    $email = '';
    if ( is_array( $info ) ) {
        // Different OAuth servers shape userinfo differently — try the
        // common keys in priority order.
        foreach ( array( 'email', 'user_email', 'preferred_email' ) as $k ) {
            if ( ! empty( $info[ $k ] ) ) { $email = (string) $info[ $k ]; break; }
        }
        if ( $email === '' && ! empty( $info['data']['user']['email'] ) ) {
            $email = (string) $info['data']['user']['email'];
        }
    }
    $email = sanitize_email( $email );
    if ( $email === '' ) {
        return new WP_Error( 'no_email', 'gend.me did not return an email address. Reach out to support.', array( 'status' => 502 ) );
    }

    // ── Look up local user by email ────────────────────────────────────
    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        return new WP_Error( 'no_local_user',
            sprintf( 'No account on this site for %s. Contact the site owner to grant you access.', $email ),
            array( 'status' => 403 )
        );
    }

    // ── Save tokens for downstream plugins (Leo, contracts, etc.) ──────
    update_user_meta( $user->ID, 'gend_oauth_token', $access_token );
    if ( $refresh_token !== '' ) {
        update_user_meta( $user->ID, 'gend_oauth_refresh_token', $refresh_token );
    }
    update_user_meta( $user->ID, 'gend_oauth_token_expires_at', time() + max( 60, $expires_in ) );

    // ── Log in ─────────────────────────────────────────────────────────
    wp_set_current_user( $user->ID, $user->user_login );
    wp_set_auth_cookie( $user->ID, true );
    do_action( 'wp_login', $user->user_login, $user );

    /**
     * Filter the post-OAuth-login redirect destination.
     *
     * @param string  $redirect_to Default: admin_url().
     * @param WP_User $user        The logged-in user.
     */
    $redirect_to = apply_filters( 'gs_oauth_login_redirect_to',
        isset( $_REQUEST['redirect_to'] ) && $_REQUEST['redirect_to'] ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url(),
        $user
    );

    return rest_ensure_response( array(
        'success'     => true,
        'user_id'     => $user->ID,
        'redirect_to' => $redirect_to,
    ) );
}

// ────────────────────────────────────────────────────────────────────────
// Lost-password redirect
// ────────────────────────────────────────────────────────────────────────

/**
 * Native lost-password mails through wp_mail(), which on container
 * installs (no SMTP yet) silently drops. Send customers to gend.me's
 * recovery flow where their account actually lives anyway.
 */
add_action( 'login_form_lostpassword', function () {
    if ( ! gs_oauth_should_intercept() ) return;
    wp_safe_redirect( gs_oauth_hub_url() . '/wp-login.php?action=lostpassword' );
    exit;
}, 1 );

/**
 * Same redirect for any "Lost your password?" link rendered in any
 * other theme/plugin context.
 */
add_filter( 'lostpassword_url', function ( $url ) {
    if ( ! gs_oauth_should_intercept() ) return $url;
    return gs_oauth_hub_url() . '/wp-login.php?action=lostpassword';
}, 99 );
