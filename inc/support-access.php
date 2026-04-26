<?php
/**
 * GenD Society: receive support-access grant/revoke calls from gend.me
 * and handle the resulting magic-link admin login.
 *
 * Inbound REST routes:
 *   POST /wp-json/gs/v1/grant-admin-access   — verify gend.me signature,
 *                                              mint a one-time login URL
 *                                              for the gend_support user.
 *   POST /wp-json/gs/v1/revoke-admin-access  — invalidate any active grant
 *                                              and remove the gend_support user.
 *
 * Front-end magic-link handler:
 *   /?gs_support_login=<token>  —  consumes the token and logs the visitor
 *                                  in as gend_support (admin), then redirects
 *                                  to wp-admin.
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

const GS_SUPPORT_USER_LOGIN = 'gend_support';
const GS_SUPPORT_USER_EMAIL = 'support@gend.me';

const GS_SUPPORT_OPTION_TOKEN     = 'gs_support_grant_token_hash';
const GS_SUPPORT_OPTION_EXPIRES   = 'gs_support_grant_expires_at';
const GS_SUPPORT_OPTION_ISSUED_BY = 'gs_support_grant_issued_by';
const GS_SUPPORT_REPLAY_TTL       = 5 * MINUTE_IN_SECONDS;

add_action('rest_api_init', 'gs_support_access_register_routes');
add_action('init', 'gs_support_access_consume_magic_link', 1);

function gs_support_access_register_routes() {

    register_rest_route('gs/v1', '/grant-admin-access', array(
        'methods'             => 'POST',
        'callback'            => 'gs_support_access_grant',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('gs/v1', '/revoke-admin-access', array(
        'methods'             => 'POST',
        'callback'            => 'gs_support_access_revoke',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Verify the inbound request was signed by the paired gend.me portal.
 * Returns the decoded payload on success, WP_Error on failure.
 */
function gs_support_access_verify_signed_request(\WP_REST_Request $request) {

    $body      = (string) $request->get_body();
    $signature = (string) $request->get_header('x_gend_signature');

    if ($body === '' || $signature === '') {
        return new \WP_Error('missing_signature', __('Missing signature header.', 'gend-society'), array('status' => 401));
    }

    $pub_b64 = (string) get_option('gs_gend_pubkey', '');
    if ($pub_b64 === '') {
        return new \WP_Error('not_paired', __('This site has not been paired with gend.me.', 'gend-society'), array('status' => 412));
    }

    $pub = base64_decode($pub_b64, true);
    $sig = base64_decode($signature, true);
    if (!is_string($pub) || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return new \WP_Error('bad_pubkey', __('Stored gend.me public key is invalid.', 'gend-society'), array('status' => 500));
    }
    if (!is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return new \WP_Error('bad_signature', __('Signature is not a valid Ed25519 detached signature.', 'gend-society'), array('status' => 401));
    }

    try {
        $verified = sodium_crypto_sign_verify_detached($sig, $body, $pub);
    } catch (\Throwable $e) {
        return new \WP_Error('verify_failed', $e->getMessage(), array('status' => 401));
    }
    if (!$verified) {
        return new \WP_Error('verify_failed', __('Signature did not verify.', 'gend-society'), array('status' => 401));
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return new \WP_Error('bad_json', __('Body is not valid JSON.', 'gend-society'), array('status' => 400));
    }

    // Replay guard: reject payloads issued > 5 minutes ago or in the future.
    $issued_at = isset($payload['issued_at']) ? (int) $payload['issued_at'] : 0;
    $now       = time();
    if ($issued_at <= 0 || $issued_at > $now + 60 || $issued_at < $now - GS_SUPPORT_REPLAY_TTL) {
        return new \WP_Error('replay', __('Request is outside the accepted issued_at window.', 'gend-society'), array('status' => 401));
    }

    // Install ID match check (best-effort — gend.me always sends ours).
    $expected_install = (string) get_option('gs_install_id', '');
    if (!empty($payload['install_id']) && $expected_install !== '' && (string) $payload['install_id'] !== $expected_install) {
        return new \WP_Error('install_mismatch', __('install_id does not match this site.', 'gend-society'), array('status' => 401));
    }

    return $payload;
}

function gs_support_access_grant(\WP_REST_Request $request) {

    $payload = gs_support_access_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $ttl_seconds = isset($payload['ttl_seconds']) ? (int) $payload['ttl_seconds'] : 30 * MINUTE_IN_SECONDS;
    $ttl_seconds = max(60, min(2 * HOUR_IN_SECONDS, $ttl_seconds));

    $user = gs_support_access_ensure_support_user();
    if (!$user) {
        return new \WP_Error('user_failed', __('Could not create or load support user.', 'gend-society'), array('status' => 500));
    }

    $raw_token = bin2hex(random_bytes(32));
    $expires   = time() + $ttl_seconds;

    update_option(GS_SUPPORT_OPTION_TOKEN, wp_hash($raw_token), false);
    update_option(GS_SUPPORT_OPTION_EXPIRES, $expires, false);
    update_option(GS_SUPPORT_OPTION_ISSUED_BY, isset($payload['requested_by_login']) ? (string) $payload['requested_by_login'] : '', false);

    $login_url = add_query_arg('gs_support_login', $raw_token, home_url('/'));

    return rest_ensure_response(array(
        'login_url'  => $login_url,
        'expires_at' => $expires,
        'user_login' => $user->user_login,
    ));
}

function gs_support_access_revoke(\WP_REST_Request $request) {

    $payload = gs_support_access_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    delete_option(GS_SUPPORT_OPTION_TOKEN);
    delete_option(GS_SUPPORT_OPTION_EXPIRES);
    delete_option(GS_SUPPORT_OPTION_ISSUED_BY);

    // Demote the support user so even a leaked cookie can't sign in as admin.
    $user = get_user_by('login', GS_SUPPORT_USER_LOGIN);
    if ($user) {
        wp_destroy_other_sessions_for_user($user->ID);
        // Strip admin role; keep the user for audit history.
        $user->set_role('subscriber');
    }

    return rest_ensure_response(array('state' => 'revoked'));
}

/**
 * Ensure a `gend_support` user exists with the administrator role.
 *
 * @return \WP_User|null
 */
function gs_support_access_ensure_support_user() {

    $user = get_user_by('login', GS_SUPPORT_USER_LOGIN);
    if (!$user) {
        $user_id = wp_insert_user(array(
            'user_login' => GS_SUPPORT_USER_LOGIN,
            'user_email' => GS_SUPPORT_USER_EMAIL,
            'user_pass'  => wp_generate_password(64, true, true),
            'role'       => 'administrator',
            'first_name' => 'GenD',
            'last_name'  => 'Support',
        ));
        if (is_wp_error($user_id)) {
            return null;
        }
        $user = get_user_by('id', $user_id);
    } else {
        // Re-promote in case revoke previously demoted; rotate the password
        // so any prior credential is invalidated.
        $user->set_role('administrator');
        wp_set_password(wp_generate_password(64, true, true), $user->ID);
        $user = get_user_by('id', $user->ID);
    }

    return $user instanceof \WP_User ? $user : null;
}

/**
 * Front-end handler: when ?gs_support_login=<token> is in the URL, validate
 * the token and log the visitor in as the gend_support user.
 */
function gs_support_access_consume_magic_link() {

    if (empty($_GET['gs_support_login']) || is_admin()) {
        return;
    }

    $token = (string) $_GET['gs_support_login'];

    $hash    = (string) get_option(GS_SUPPORT_OPTION_TOKEN, '');
    $expires = (int) get_option(GS_SUPPORT_OPTION_EXPIRES, 0);

    if ($hash === '' || $expires <= 0) {
        wp_die(esc_html__('No active support access grant.', 'gend-society'), '', array('response' => 403));
    }
    if (time() > $expires) {
        delete_option(GS_SUPPORT_OPTION_TOKEN);
        delete_option(GS_SUPPORT_OPTION_EXPIRES);
        wp_die(esc_html__('This support access link has expired.', 'gend-society'), '', array('response' => 410));
    }
    if (!hash_equals($hash, wp_hash($token))) {
        wp_die(esc_html__('Invalid support access token.', 'gend-society'), '', array('response' => 403));
    }

    $user = get_user_by('login', GS_SUPPORT_USER_LOGIN);
    if (!$user) {
        wp_die(esc_html__('Support user is missing.', 'gend-society'), '', array('response' => 500));
    }

    // One-shot consumption: clear the token after it's used.
    delete_option(GS_SUPPORT_OPTION_TOKEN);
    delete_option(GS_SUPPORT_OPTION_EXPIRES);

    wp_clear_auth_cookie();
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false);

    wp_safe_redirect(admin_url());
    exit;
}
