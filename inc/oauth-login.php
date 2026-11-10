<?php
/**
 * The site's sign-in page: the site's own accounts plus "Continue with gend.me" (OAuth).
 *
 * Works on every site that has the gend-society plugin active EXCEPT
 * gend.me itself (the hub authenticates users locally).
 *
 * 1.9.24: a web app's customers and members sign in with accounts on that site (username or email + password,
 * the site's own password reset and registration); "Continue with gend.me" is offered alongside. Site option
 * gend_society_login_methods = both (default) | site | gendme (gend.me only, the earlier page).
 *
 * gend.me flow:
 *   1. Customer hits /wp-login.php on their site (e.g. block-migration.gend.me).
 *   2. We render a styled page: the site's own form and a "Continue with gend.me"
 *      button.
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

// gend_society_oauth_config_value(), gend_society_oauth_hub_url(),
// gend_society_oauth_client_id() and gend_society_oauth_is_hub_site() live in
// inc/hub-url.php (core tier, Phase 106) so core/customer files keep them on
// standalone installs, where this container-tier file never loads. Required
// here too so this file works whatever the manifest load order.
require_once __DIR__ . '/hub-url.php';

if ( ! function_exists( 'gend_society_oauth_client_secret' ) ) {
    function gend_society_oauth_client_secret(): string {
        return gend_society_oauth_config_value( 'GDC_OAUTH_CLIENT_SECRET', 'aipa_oauth_client_secret' );
    }
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
function gend_society_oauth_should_intercept(): bool {
    if ( gend_society_oauth_is_hub_site() ) return false;
    if ( gend_society_oauth_login_client_id() === '' ) return false;
    if ( isset( $_GET['gs_native'] ) ) return false;
    return true;
}

if ( ! function_exists( 'gend_society_login_methods' ) ) {
    /**
     * Which sign-ins the site's login page offers: 'both' (default: the site's own accounts first, then "Continue with
     * gend.me"), 'site' (own accounts only) or 'gendme' (gend.me accounts only, the pre-1.9.24 page). A site's
     * customers and members sign in with accounts on that site; nobody needs a gend.me profile to use it.
     * Site option gend_society_login_methods; filter gend_society_login_methods.
     */
    function gend_society_login_methods(): string {
        $m = strtolower( (string) get_option( 'gend_society_login_methods', 'both' ) );
        $m = (string) apply_filters( 'gend_society_login_methods', $m );
        return in_array( $m, array( 'both', 'site', 'gendme' ), true ) ? $m : 'both';
    }
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
add_action( 'login_form_login', 'gend_society_oauth_render_login_page' );

function gend_society_oauth_render_login_page() {
    if ( ! gend_society_oauth_should_intercept() ) {
        return; // fall through to native form
    }
    if ( is_user_logged_in() ) {
        wp_safe_redirect( admin_url() );
        exit;
    }

    $methods      = gend_society_login_methods();
    $redirect_to  = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();

    // The site's own sign-in: a password POST is handled here (same steps as wp-login.php: wp_signon(), so the
    // authenticate filters, two-factor and lockout plugins all apply) and an error re-renders this page with the form.
    $site_error = '';
    $site_login = '';
    if ( 'gendme' !== $methods && 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- wp-login.php has no nonce on sign-in either.
        $site_login = sanitize_user( wp_unslash( (string) $_POST['log'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $user       = wp_signon(
            array(
                'user_login'    => wp_unslash( (string) $_POST['log'] ), // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to core as wp-login.php does.
                'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is never sanitized.
                'remember'      => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification
            ),
            is_ssl()
        );
        if ( ! is_wp_error( $user ) ) {
            $requested = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( (string) $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- filtered and checked by wp_safe_redirect.
            $to        = apply_filters( 'login_redirect', $redirect_to, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, applied as wp-login.php does.
            // Members without wp-admin access land on the front page (as wp-login.php does for an empty redirect).
            if ( ( empty( $to ) || admin_url() === $to || admin_url( '/' ) === $to ) && ! $user->has_cap( 'edit_posts' ) ) {
                $to = home_url( '/' );
            }
            wp_safe_redirect( $to ?: admin_url() );
            exit;
        }
        $codes      = $user->get_error_codes();
        // Same wording for an unknown user and a wrong password: the page never says which accounts exist.
        $site_error = ( array_intersect( $codes, array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) || ! $codes )
            ? __( 'That username or password is not right. Try again, or reset your password.', 'gend-society' )
            : wp_strip_all_tags( $user->get_error_message() );
    }
    $hub_url      = gend_society_oauth_hub_url();
    $client_id    = gend_society_oauth_login_client_id();
    $rest_login   = esc_url_raw( rest_url( 'gend-society/v1/oauth/login' ) );
    // Where the popup goes and what it asks for: Hydra (PKCE, shared public client) or the hub's legacy OAuth server.
    // Both hand the code back through a page on the hub origin, which the opener checks below.
    if ( 'hydra' === gend_society_oauth_provider() ) {
        $flow = array(
            'authorize_url' => gend_society_oauth_hydra_url() . '/oauth2/auth',
            'redirect_uri'  => $hub_url . '/oauth2-bridge/callback',
            'scope'         => 'openid profile email offline_access', // offline_access = Hydra issues a refresh token
            // Where this visitor came from (gm_from) and the gend.me affiliate this site remembers (gm_ref): extra authorize
            // parameters Hydra ignores but records, so the hub can credit a new gend.me account to the right Sales team.
            'extra'         => function_exists( 'gend_society_nr_tag_args' ) && ! gend_society_oauth_is_hub_site() ? gend_society_nr_tag_args() : new stdClass(),
        );
    } else {
        $flow = array(
            'authorize_url' => $hub_url . '/oauth/authorize',
            'redirect_uri'  => $hub_url . '/oauth-bridge/',
            'scope'         => 'profile',
        );
    }

    // Render the login page chrome via WP's login_header so theme/plugin
    // login_enqueue_scripts hooks fire (login-style.php's CSS still applies).
    login_header( __( 'Sign in', 'gend-society' ), '' );
    // A member's site shows its own logo (custom logo, else site icon) above the card, or none: never gend.me's.
    $site_logo = '';
    if ( ! gend_society_oauth_is_hub_site() ) {
        $logo_id   = (int) get_theme_mod( 'custom_logo' );
        $site_logo = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        if ( '' === $site_logo ) {
            $site_logo = (string) get_site_icon_url( 192 );
        }
    }
    ?>
    <?php if ( ! gend_society_oauth_is_hub_site() ) : ?>
    <style>
        <?php if ( '' !== $site_logo ) : ?>
        body.login h1 a {
            background-image: url('<?php echo esc_url( $site_logo ); ?>') !important;
            background-size: contain !important; background-position: center !important; background-repeat: no-repeat !important;
            width: 100% !important; height: 72px !important; margin: 0 auto 24px !important; border-radius: 0 !important; filter: none !important;
        }
        <?php else : ?>
        body.login h1 { display: none !important; }
        <?php endif; ?>
    </style>
    <?php endif; ?>
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

    <style>
        /* Site sign-in. Core login.css paints every ".login form" white, so each rule here is pinned to #login. */
        body.login #login { box-sizing: border-box; width: 100% !important; max-width: 440px; padding: 24px 20px !important; margin: 0 auto !important; }
        #login .gs-oauth-shell { padding: 40px 36px 32px !important; }
        #login .gs-oauth-btn { white-space: nowrap; }
        #login .gs-oauth-shell h2 { font-size: 22px; letter-spacing: .02em; text-transform: none; margin-bottom: 6px; }
        #login .gs-oauth-shell p.gs-oauth-sub { margin-bottom: 28px; font-size: 14px; }
        #login form.gs-site-login {
            background: transparent !important; border: 0 !important; box-shadow: none !important;
            padding: 0 !important; margin: 0 !important; overflow: visible; text-align: left;
        }
        #login .gs-field { margin: 0 0 18px; }
        #login form.gs-site-login label.gs-label {
            display: block; margin: 0 0 8px !important; font-size: 12px !important; font-weight: 600; line-height: 1.3;
            letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.72) !important;
        }
        #login .gs-input-wrap { position: relative; }
        #login form.gs-site-login input.gs-input {
            box-sizing: border-box; display: block; width: 100%; min-height: 50px; margin: 0 !important;
            padding: 13px 16px !important; font-size: 15px !important; line-height: 1.4;
            background: rgba(255,255,255,.06) !important; color: #fff !important;
            -webkit-text-fill-color: #fff !important; caret-color: #fff !important;
            border: 1px solid rgba(255,255,255,.14) !important; border-radius: 14px !important;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04) !important;
            transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
        }
        #login form.gs-site-login input#gs-user-pass { padding-right: 76px !important; }
        #login form.gs-site-login input.gs-input:hover { border-color: rgba(255,255,255,.26) !important; }
        #login form.gs-site-login input.gs-input:focus {
            outline: none; background: rgba(255,255,255,.09) !important;
            border-color: var(--gs-accent, #b608c9) !important;
            box-shadow: 0 0 0 4px rgba(182,8,201,.22) !important;
        }
        #login form.gs-site-login input.gs-input:-webkit-autofill {
            -webkit-text-fill-color: #fff; caret-color: #fff;
            -webkit-box-shadow: 0 0 0 1000px #1d1726 inset !important; transition: background-color 9999s;
        }
        #login .gs-pass-toggle {
            position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
            padding: 7px 10px; border: 0; border-radius: 10px; cursor: pointer;
            background: transparent; color: rgba(255,255,255,.65); font-size: 12px; font-weight: 600; letter-spacing: .04em;
        }
        #login .gs-pass-toggle:hover, #login .gs-pass-toggle:focus-visible { background: rgba(255,255,255,.08); color: #fff; outline: none; }
        #login .gs-form-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 4px 0 24px; }
        #login form.gs-site-login label.gs-remember {
            display: inline-flex; align-items: center; gap: 10px; margin: 0 !important; cursor: pointer;
            font-size: 13.5px !important; font-weight: 500; letter-spacing: 0; text-transform: none; color: rgba(255,255,255,.8) !important;
        }
        #login form.gs-site-login label.gs-remember input[type=checkbox] {
            -webkit-appearance: none; appearance: none; flex: none; width: 18px !important; height: 18px !important; min-width: 0;
            margin: 0 !important; padding: 0 !important; border-radius: 6px !important; cursor: pointer;
            background: rgba(255,255,255,.06) !important; border: 1px solid rgba(255,255,255,.3) !important; box-shadow: none !important;
            display: grid; place-content: center;
        }
        #login form.gs-site-login label.gs-remember input[type=checkbox]::before { content: none !important; }
        #login form.gs-site-login label.gs-remember input[type=checkbox]:checked {
            background: var(--gs-accent, #b608c9) !important; border-color: var(--gs-accent, #b608c9) !important;
        }
        #login form.gs-site-login label.gs-remember input[type=checkbox]:checked::after {
            content: ""; width: 9px; height: 5px; margin-top: -2px; border: 2px solid #fff; border-top: 0; border-right: 0; transform: rotate(-45deg);
        }
        #login form.gs-site-login label.gs-remember input[type=checkbox]:focus-visible { outline: 2px solid var(--gs-accent, #b608c9); outline-offset: 2px; }
        #login .gs-form-row a { color: rgba(255,255,255,.75); font-size: 13.5px; text-decoration: none; }
        #login .gs-form-row a:hover, #login .gs-site-links a:hover { color: #fff; text-decoration: underline; }
        #login .gs-site-links { margin: 18px 0 0; text-align: center; font-size: 13.5px; color: rgba(255,255,255,.55); }
        #login .gs-site-links a { color: #fff; font-weight: 600; text-decoration: none; }
        /* The site's own button leads; Continue with gend.me sits below it as the second choice. */
        #login .gs-oauth-btn { min-height: 52px; font-size: 14px; border-radius: 14px; }
        #login .gs-oauth-btn.gs-btn-secondary {
            background: rgba(255,255,255,.05) !important; color: #fff !important;
            border: 1px solid rgba(255,255,255,.18) !important; box-shadow: none !important;
            text-transform: none; letter-spacing: .01em; font-size: 15px;
        }
        #login .gs-oauth-btn.gs-btn-secondary:hover { background: rgba(255,255,255,.1) !important; border-color: rgba(255,255,255,.3) !important; }
        #login .gs-gend-mark {
            display: inline-grid; place-content: center; width: 22px; height: 22px; border-radius: 7px;
            background: linear-gradient(135deg, #b608c9, #7e058a); font-size: 12px; font-weight: 800; letter-spacing: 0;
        }
        #login .gs-or { display: flex; align-items: center; gap: 14px; margin: 26px 0; color: rgba(255,255,255,.45); font-size: 12px; text-transform: uppercase; letter-spacing: .14em; }
        #login .gs-or::before, #login .gs-or::after { content: ""; flex: 1; height: 1px; background: rgba(255,255,255,.12); }
        #login .gs-site-error { display: block; margin: 0 0 20px; }
        @media (max-width: 480px) {
            #login { padding: 16px !important; }
            #login .gs-oauth-shell { padding: 30px 20px 24px !important; border-radius: 20px !important; }
        }
    </style>

    <div class="gs-oauth-shell">
        <h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
        <?php if ( 'gendme' !== $methods ) : ?>
            <p class="gs-oauth-sub"><?php esc_html_e( 'Sign in to your account.', 'gend-society' ); ?></p>
            <?php if ( '' !== $site_error ) : ?>
                <div class="gs-oauth-error gs-site-error" role="alert"><?php echo esc_html( $site_error ); ?></div>
            <?php endif; ?>
            <form class="gs-site-login" id="gs-site-login" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
                <div class="gs-field">
                    <label class="gs-label" for="gs-user-login"><?php esc_html_e( 'Username or email', 'gend-society' ); ?></label>
                    <input class="gs-input" type="text" name="log" id="gs-user-login" value="<?php echo esc_attr( $site_login ); ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required>
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gs-user-pass"><?php esc_html_e( 'Password', 'gend-society' ); ?></label>
                    <div class="gs-input-wrap">
                        <input class="gs-input" type="password" name="pwd" id="gs-user-pass" value="" autocomplete="current-password" required>
                        <button type="button" class="gs-pass-toggle" aria-controls="gs-user-pass" aria-pressed="false" data-show="<?php esc_attr_e( 'Show', 'gend-society' ); ?>" data-hide="<?php esc_attr_e( 'Hide', 'gend-society' ); ?>"><?php esc_html_e( 'Show', 'gend-society' ); ?></button>
                    </div>
                </div>
                <?php
                // Captcha, two-factor and similar plugins add their fields here, as on the stock form.
                do_action( 'login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired as on the stock form.
                ?>
                <div class="gs-form-row">
                    <label class="gs-remember"><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'Remember me', 'gend-society' ); ?></label>
                    <a href="<?php echo esc_url( wp_lostpassword_url( $redirect_to ) ); ?>"><?php esc_html_e( 'Forgot password?', 'gend-society' ); ?></a>
                </div>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
                <button type="submit" class="gs-oauth-btn gs-site-submit"><?php esc_html_e( 'Sign in', 'gend-society' ); ?></button>
            </form>
            <?php if ( get_option( 'users_can_register' ) ) : ?>
                <p class="gs-site-links"><?php esc_html_e( 'New here?', 'gend-society' ); ?> <a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create an account', 'gend-society' ); ?></a></p>
            <?php endif; ?>
            <script>
            (function () {
                var t = document.querySelector('#gs-site-login .gs-pass-toggle'), f = document.getElementById('gs-user-pass');
                if (!t || !f) { return; }
                t.addEventListener('click', function () {
                    var show = 'password' === f.type;
                    f.type = show ? 'text' : 'password';
                    t.textContent = show ? t.getAttribute('data-hide') : t.getAttribute('data-show');
                    t.setAttribute('aria-pressed', show ? 'true' : 'false');
                    f.focus();
                });
            })();
            </script>
        <?php endif; ?>
        <?php if ( 'both' === $methods ) : ?>
            <div class="gs-or"><?php esc_html_e( 'or', 'gend-society' ); ?></div>
        <?php endif; ?>
        <?php if ( 'site' !== $methods ) : ?>
            <?php if ( 'gendme' === $methods ) : ?>
                <p class="gs-oauth-sub"><?php esc_html_e( 'Sign in with your gend.me account to continue.', 'gend-society' ); ?></p>
            <?php endif; ?>
            <button id="gs-oauth-btn" type="button" class="gs-oauth-btn<?php echo 'both' === $methods ? ' gs-btn-secondary' : ''; ?>">
                <?php if ( 'both' === $methods ) : ?><span class="gs-gend-mark" aria-hidden="true">g</span><?php endif; ?>
                <?php echo esc_html( 'both' === $methods ? __( 'Continue with gend.me', 'gend-society' ) : __( 'Sign in with gend.me', 'gend-society' ) ); ?>
                <span aria-hidden="true">→</span>
            </button>
            <?php if ( 'gendme' === $methods ) : ?>
                <p class="gs-oauth-foot">
                    <a href="<?php echo esc_url( $hub_url . '/wp-login.php?action=lostpassword' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Forgot your password?', 'gend-society' ); ?></a>
                </p>
            <?php endif; ?>
            <div id="gs-oauth-error" class="gs-oauth-error" role="alert" aria-live="polite"></div>
        <?php endif; ?>
    </div>

    <?php if ( 'site' !== $methods ) : ?>
    <script>
    (function () {
        var hubUrl       = <?php echo wp_json_encode( $hub_url ); ?>;
        var clientId     = <?php echo wp_json_encode( $client_id ); ?>;
        var restLoginUrl = <?php echo wp_json_encode( $rest_login ); ?>;
        var flow         = <?php echo wp_json_encode( $flow ); ?>;
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

                var authUrl = flow.authorize_url + '?' + new URLSearchParams(Object.assign({}, flow.extra || {}, {
                    response_type:         'code',
                    client_id:             clientId,
                    redirect_uri:          flow.redirect_uri,
                    // Request 'profile' — the standard scope the GenD Core
                    // (and Container Sites Login / Leo) WP-OAuth clients
                    // whitelist. 'basic' is being retired: a client scoped to
                    // 'profile' only returns invalid_scope for 'basic' at
                    // /authorize. The /oauth/me userinfo endpoint returns the
                    // user's email + display_name under 'profile' too, which is
                    // all we need to match the local wp_users row.
                    scope:                 flow.scope,
                    state:                 state,
                    code_challenge:        p.challenge,
                    code_challenge_method: 'S256'
                })).toString();

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
    <?php endif; ?>
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
        'callback'            => 'gend_society_oauth_login_rest',
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
    if ( $route !== '' ) {
        // OAuth code-exchange must be reachable pre-login.
        if ( strpos( $route, '/gend-society/v1/oauth/login' ) !== false ) return null;
        // Public Desktop release lookup — the Connect Your Computer popup
        // + any "always-latest" bookmark links need to resolve without
        // an auth cookie. The /release POST has its own manage_network
        // gate inside the handler, so opening these is safe.
        if ( strpos( $route, '/gs/v1/desktop/info'   ) !== false ) return null;
        if ( strpos( $route, '/gs/v1/desktop/latest' ) !== false ) return null;
        // Member Calendar public booking (Phase 29) — a shared booking link
        // and the subscribable ICS feed must be reachable by logged-out
        // bookees / external calendar apps. Every handler self-gates on a
        // 43-char share_token or cancel_token, so opening these is safe.
        // NOTE: the AUTHED single-download /gs/v1/calendar/meetings/{id}/ics
        // is deliberately NOT allow-listed (it stays cookie-gated).
        if ( strpos( $route, '/gs/v1/calendar/public/' ) !== false ) return null;
        if ( strpos( $route, '/gs/v1/calendar/ics/'    ) !== false ) return null;
        // Phase 71 v10.0 — domain manager REST (gdc-app-manager/v1/install/{id}/hosting/domains/*)
        // is install-token-gated INSIDE the route's permission_callback (Gend_Domain_REST::check_install_ownership).
        // Allow-list it past the site-wide auth filter so the install-token check has a chance to run.
        // NARROW scope: BOTH strpos checks must match — won't allow-list /hosting/backups or other /hosting/* subtrees.
        if ( strpos( $route, '/gdc-app-manager/v1/install/' ) !== false && strpos( $route, '/hosting/domains' ) !== false ) return null;
        // Phase 76 v10.0 — admin bulk-reconcile (gdc-app-manager/v1/admin/hosting/domains/reconcile-all)
        // is manage_network-gated INSIDE the route's permission_callback. Allow-list past the
        // site-wide auth filter so the manage_network check has a chance to run.
        // NARROW scope: BOTH strpos checks must match — MUST NOT allow-list other /admin/* subtrees.
        if ( strpos( $route, '/gdc-app-manager/v1/admin/' ) !== false && strpos( $route, '/hosting/domains/reconcile-all' ) !== false ) return null;
        // Launch Triggers (email-manager chatflows → AI Sequences) — the
        // [gend_launch] link's click-through route is meant to be pasted
        // anywhere and clicked by logged-out visitors. The handler only
        // fires a fire-and-forget webhook POST + redirects; nothing in it
        // needs an authenticated caller.
        if ( strpos( $route, '/em/v1/launch/' ) !== false ) return null;
        // gend-pm-sync/v1 (contracts-and-payments) — container-to-hub federation
        // push (PM/task manifests + media-optimization + media_storage_report
        // payloads). Every container calling this is itself logged out (it's a
        // server-to-server POST, no WP session cookie at all), and every route
        // on this namespace is Ed25519-signature-gated INSIDE
        // Gend_CP_PM_Sync::verify_request() (permission_callback is
        // deliberately __return_true — auth happens post-routing via the
        // signature, not via this filter). Discovered empirically: this was
        // NEVER allow-listed, so every push to this namespace has always
        // 401'd at this gate before even reaching verify_request().
        if ( strpos( $route, '/gend-pm-sync/v1/' ) !== false ) return null;
    }
    return $result;
}, 99 );

function gend_society_oauth_login_rest( WP_REST_Request $req ) {
    $code     = (string) $req->get_param( 'code' );
    $verifier = (string) $req->get_param( 'code_verifier' );
    if ( $code === '' ) {
        return new WP_Error( 'missing_code', 'Authorization code required.', array( 'status' => 400 ) );
    }
    if ( $verifier !== '' && ! preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', $verifier ) ) {
        return new WP_Error( 'bad_verifier', 'Invalid PKCE verifier format.', array( 'status' => 400 ) );
    }

    $hub_url       = gend_society_oauth_hub_url();
    $hydra         = 'hydra' === gend_society_oauth_provider();
    $client_id     = gend_society_oauth_login_client_id();
    // Hydra: the shared client is public (PKCE, no secret) and PKCE is mandatory there.
    $client_secret = $hydra ? '' : gend_society_oauth_client_secret();
    if ( $hydra && $verifier === '' ) {
        return new WP_Error( 'missing_verifier', 'PKCE verifier required.', array( 'status' => 400 ) );
    }
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
        'redirect_uri'  => $hub_url . ( $hydra ? '/oauth2-bridge/callback' : '/oauth-bridge/' ),
        'client_id'     => $client_id,
    );
    if ( $verifier !== '' ) $token_body['code_verifier'] = $verifier;

    $token_headers = array();
    if ( $client_secret !== '' ) {
        $token_headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
    }

    $token_url = $hydra ? gend_society_oauth_hydra_url() . '/oauth2/token' : $hub_url . '/oauth/token';
    $resp = wp_remote_post( $token_url, array(
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
        : ( $hydra ? gend_society_oauth_hydra_url() . '/userinfo' : $hub_url . '/oauth/me' );
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
        // The membership owner (gend.me vouched for this email above; GDC_OWNER_EMAIL is baked in by the listener) gets an
        // administrator account on their own site when its database has none for them - a migrated site's users are the
        // source site's, not the gend.me account's.
        $owner_seed = strtolower( trim( gend_society_oauth_config_value( 'GDC_OWNER_EMAIL', '' ) ) );
        if ( $owner_seed !== '' && strtolower( $email ) === $owner_seed ) {
            $base  = sanitize_user( (string) strstr( $email, '@', true ), true );
            $login = $base !== '' ? $base : 'owner';
            for ( $i = 2; username_exists( $login ); $i++ ) {
                $login = ( $base !== '' ? $base : 'owner' ) . $i;
            }
            $new_id = wp_insert_user( array(
                'user_login' => $login,
                'user_email' => $email,
                'user_pass'  => wp_generate_password( 32, true, true ),
                'role'       => 'administrator',
            ) );
            if ( ! is_wp_error( $new_id ) ) {
                $user = get_user_by( 'id', $new_id );
            }
        }
    }
    if ( ! $user ) {
        return new WP_Error( 'no_local_user',
            sprintf( 'No account on this site for %s. Contact the site owner to grant you access.', $email ),
            array( 'status' => 403 )
        );
    }

    // ── Owner-email auto-promote ───────────────────────────────────────
    // GDC_OWNER_EMAIL is baked into the customer container by the gend.me
    // listener (membership customer's email). When the OAuth user matches,
    // ensure they have administrator — covers the migration case where
    // the source DB had bbp_keymaster but no administrator role, AND the
    // common case where a customer signs up fresh and wp_users hasn't
    // been seeded yet. The grant is idempotent (add_role no-ops if the
    // user already has it).
    $owner_email = strtolower( trim( gend_society_oauth_config_value( 'GDC_OWNER_EMAIL', '' ) ) );
    $is_owner    = $owner_email !== '' && strtolower( $email ) === $owner_email;
    if ( $is_owner ) {
        if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
            $user->add_role( 'administrator' );
            // Refresh the in-memory user so capability checks downstream
            // see the new role without a re-fetch.
            $user = get_user_by( 'id', $user->ID );
        }
    }

    // ── Auto-pair this install with gend.me ────────────────────────────
    // When the membership owner signs in, ask gend.me for an install
    // token via /connect-by-install. Replaces the manual pairing-code
    // paste step (portal-connect.php) for container sites: the OAuth
    // token already proves who the user is, and the install_id is
    // already known on both sides. After this, feature-gates.php and
    // dashboard membership sync work without any operator action.
    //
    // No-op when:
    //   - user isn't the owner (random admin with a matching email
    //     elsewhere shouldn't claim our install)
    //   - we're already paired (gs_install_token already populated)
    //   - we don't have a known install_id (constant or env)
    if ( $is_owner && function_exists( 'gend_society_oauth_autopair_install' ) ) {
        gend_society_oauth_autopair_install( $hub_url, $access_token );
    }

    // ── Save tokens for downstream plugins (Leo, contracts, etc.) ──────
    update_user_meta( $user->ID, 'gend_oauth_token', $access_token );
    if ( $refresh_token !== '' ) {
        update_user_meta( $user->ID, 'gend_oauth_refresh_token', $refresh_token );
    } elseif ( $hydra ) {
        // A refresh token left from the old server is not valid at Hydra; keeping it would only fail the next refresh.
        delete_user_meta( $user->ID, 'gend_oauth_refresh_token' );
    }
    update_user_meta( $user->ID, 'gend_oauth_token_expires_at', time() + max( 60, $expires_in ) );

    // ── Link this site account to the gend.me user who just signed in ──
    // (user-site-credentials.php: lets the desktop chat edit this site as them).
    if ( function_exists( 'gend_society_record_hub_link' ) ) {
        gend_society_record_hub_link( $user->ID, $info );
    }

    // ── Log in ─────────────────────────────────────────────────────────
    wp_set_current_user( $user->ID, $user->user_login );
    wp_set_auth_cookie( $user->ID, true );
    do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.

    /**
     * Filter the post-OAuth-login redirect destination.
     *
     * @param string  $redirect_to Default: admin_url().
     * @param WP_User $user        The logged-in user.
     */
    $redirect_to = apply_filters( 'gend_society_oauth_login_redirect_to',
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
// Token refresh (Hydra)
// ────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'gend_society_oauth_maybe_refresh' ) ) {
	/**
	 * Keep a signed-in user's hub token alive. Hydra access tokens last an hour and Hydra rotates refresh tokens, so when
	 * the stored token is within five minutes of expiry (or past it) the refresh token is exchanged for a new pair, and
	 * both are stored where Leo, Email Manager and the auto-pair read them (user meta gend_oauth_*). One refresh at a
	 * time per user (a second concurrent use of a rotated refresh token would be refused), and a failed refresh backs
	 * off instead of retrying on every request. Does nothing on the legacy provider or on the hub itself.
	 *
	 * @param int $user_id User id (0 = current user).
	 * @return bool True when a new token was stored.
	 */
	function gend_society_oauth_maybe_refresh( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 || 'hydra' !== gend_society_oauth_provider() || gend_society_oauth_is_hub_site() ) {
			return false;
		}
		$refresh = (string) get_user_meta( $user_id, 'gend_oauth_refresh_token', true );
		$expires = (int) get_user_meta( $user_id, 'gend_oauth_token_expires_at', true );
		if ( '' === $refresh || $expires <= 0 || $expires > time() + 300 ) {
			return false;
		}
		if ( (int) get_user_meta( $user_id, 'gend_oauth_refresh_retry_at', true ) > time() ) {
			return false;
		}
		$lock = 'gs_oauth_refresh_' . $user_id;
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, 30 );

		$resp = wp_remote_post( gend_society_oauth_hydra_url() . '/oauth2/token', array(
			'timeout' => 10,
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => gend_society_oauth_login_client_id(),
			),
		) );
		if ( is_wp_error( $resp ) ) {
			update_user_meta( $user_id, 'gend_oauth_refresh_retry_at', time() + 120 );
			delete_transient( $lock );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			// invalid_grant = this refresh token is spent or revoked: drop it, the user signs in again.
			if ( is_array( $body ) && 'invalid_grant' === ( $body['error'] ?? '' ) ) {
				delete_user_meta( $user_id, 'gend_oauth_refresh_token' );
			}
			update_user_meta( $user_id, 'gend_oauth_refresh_retry_at', time() + 600 );
			delete_transient( $lock );
			return false;
		}
		update_user_meta( $user_id, 'gend_oauth_token', (string) $body['access_token'] );
		if ( ! empty( $body['refresh_token'] ) ) {
			update_user_meta( $user_id, 'gend_oauth_refresh_token', (string) $body['refresh_token'] );
		}
		update_user_meta( $user_id, 'gend_oauth_token_expires_at', time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) ) );
		delete_user_meta( $user_id, 'gend_oauth_refresh_retry_at' );
		delete_transient( $lock );
		return true;
	}
	// After the user is known, before any plugin reads the token. Cheap when nothing is due: two user-meta reads.
	add_action( 'init', function () {
		if ( is_user_logged_in() ) {
			gend_society_oauth_maybe_refresh();
		}
	}, 20 );
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
    // Only a gend.me-only site sends resets to gend.me: with the site's own sign-in its accounts reset here
    // (web apps send mail through the gend.me relay).
    if ( ! gend_society_oauth_should_intercept() || 'gendme' !== gend_society_login_methods() ) return;
    // wp_redirect, not wp_safe_redirect: the target is the configured hub, never visitor input, and the hub host is not
    // on this site's allowed-redirect list (wp_safe_redirect fell back to wp-admin, so the link never reached gend.me).
    wp_redirect( gend_society_oauth_hub_url() . '/wp-login.php?action=lostpassword' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed configured host.
    exit;
}, 1 );

/**
 * Same redirect for any "Lost your password?" link rendered in any
 * other theme/plugin context.
 */
add_filter( 'lostpassword_url', function ( $url ) {
    if ( ! gend_society_oauth_should_intercept() || 'gendme' !== gend_society_login_methods() ) return $url;
    return gend_society_oauth_hub_url() . '/wp-login.php?action=lostpassword';
}, 99 );

// ────────────────────────────────────────────────────────────────────────
// Auto-pair install (Container sites)
// ────────────────────────────────────────────────────────────────────────

/**
 * Resolve the install_id this customer container was provisioned with.
 * Set by the migration mu-plugin / Composition env. Empty string when
 * we're not on a known container (e.g. dev box, gend.me hub itself).
 */
function gend_society_oauth_resolve_install_id(): string {
    if ( defined( 'GDC_CONTAINER_INSTALL_ID' ) && GDC_CONTAINER_INSTALL_ID ) {
        return (string) GDC_CONTAINER_INSTALL_ID;
    }
    $env = getenv( 'GDC_CONTAINER_INSTALL_ID' );
    if ( $env !== false && $env !== '' ) return (string) $env;

    // Fallback: site option set by the migration runner.
    $opt = (string) get_option( 'gdc_container_install_id', '' );
    if ( $opt !== '' ) return $opt;

    return '';
}

/**
 * One-shot pairing: POST {install_id, install_url, society_pubkey} to
 * gend.me's /connect-by-install with the user's OAuth access token,
 * then persist the returned install_token + signing pubkey under the
 * same gs_install_* options portal-connect.php uses. Re-runs are
 * idempotent — already-paired installs short-circuit at the top.
 *
 * @param string $hub_url      Hub URL (already has trailing-slash logic applied).
 * @param string $access_token OAuth access_token from the just-completed exchange.
 */
function gend_society_oauth_autopair_install( string $hub_url, string $access_token ): void {

    if ( $access_token === '' || $hub_url === '' ) return;

    // Already paired? Nothing to do — re-pairing would just rotate
    // the install token unnecessarily on every login.
    if ( (string) get_option( 'gend_society_install_token', '' ) !== '' ) return;

    $install_id = gend_society_oauth_resolve_install_id();
    if ( $install_id === '' ) return;

    // Generate or reuse our local Ed25519 keypair (same convention
    // portal-connect.php uses). Stable across reconnects so gend.me's
    // signature verification keeps working through token rotations.
    $keypair_b64 = (string) get_option( 'gend_society_keypair', '' );
    $keypair_raw = $keypair_b64 !== '' ? base64_decode( $keypair_b64, true ) : '';
    if ( ! is_string( $keypair_raw ) || strlen( $keypair_raw ) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES ) {
        $keypair_raw = sodium_crypto_sign_keypair();
        update_option( 'gend_society_keypair', base64_encode( $keypair_raw ), false );
    }
    $pubkey_b64 = base64_encode( sodium_crypto_sign_publickey( $keypair_raw ) );

    $endpoint = rtrim( $hub_url, '/' ) . '/wp-json/gdc-app-manager/v1/connect-by-install';

    $resp = wp_remote_post( $endpoint, array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'install_id'     => $install_id,
            'install_url'    => home_url( '/' ),
            'society_pubkey' => $pubkey_b64,
        ) ),
    ) );

    if ( is_wp_error( $resp ) ) {
        // Auto-pair is opportunistic — log and move on so we don't
        // block login on a transient gend.me hiccup.
        error_log( '[gs-oauth] auto-pair failed: ' . $resp->get_error_message() );
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $raw  = (string) wp_remote_retrieve_body( $resp );
    $clean = trim( str_replace( "\xEF\xBB\xBF", '', $raw ) );
    $data  = json_decode( $clean, true );
    if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $clean, $m ) ) {
        $data = json_decode( $m[1], true );
    }

    if ( $code !== 200 || ! is_array( $data ) || empty( $data['install_token'] ) ) {
        $msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : substr( $clean, 0, 200 );
        error_log( '[gs-oauth] auto-pair HTTP ' . $code . ': ' . $msg );
        return;
    }

    update_option( 'gend_society_install_id',     $install_id,                                                       false );
    update_option( 'gend_society_install_token',  (string) $data['install_token'],                                   false );
    update_option( 'gend_society_gend_base_url',  rtrim( $hub_url, '/' ),                                            false );
    update_option( 'gend_society_gend_pubkey',    isset( $data['gend_signing_pubkey'] ) ? (string) $data['gend_signing_pubkey'] : '', false );
    update_option( 'gend_society_connected_at',   time(),                                                            false );

    // Pre-warm feature gates so the menu update doesn't lag a render.
    if ( function_exists( 'gend_society_features_get_cached' ) ) {
        delete_option( 'gend_society_features_cache_expires' );
        gend_society_features_get_cached();
    }

    // Pre-warm remote-membership cache so the dashboard renders Plan
    // Details on the very first post-login page view.
    if ( function_exists( 'gend_society_remote_membership_get_cached' ) ) {
        delete_option( 'gend_society_remote_membership_cache_expires' );
        gend_society_remote_membership_get_cached();
    }
}
