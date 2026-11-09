<?php
/**
 * GenD Society: web app access for gend.me specialists (Execution Contracts).
 *
 * When a Project Consultant on gend.me approves a specialist for a service task
 * on this web app, gend.me signs a request (Ed25519, same rail as
 * support-access.php / agent-provision.php) and POSTs it here. Sign-in on a
 * container is gend.me SSO matched by email, so access = a local account with
 * that email holding the task's role (set per service in gend.me's plan
 * builder). When the task is completed or cancelled gend.me revokes it.
 *
 * Inbound REST routes:
 *   POST /wp-json/gs/v1/member-access/grant   { email, display_name, role, ref }
 *   POST /wp-json/gs/v1/member-access/revoke  { email, ref }
 *
 * Grants are remembered per ref (task) in user meta `gend_society_member_grants`.
 * A role the member already held before any grant is never removed, the site
 * owner (GDC_OWNER_EMAIL) is never touched, and accounts are never deleted.
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('gs/v1', '/member-access/grant', array(
        'methods'             => 'POST',
        'callback'            => 'gend_society_member_access_grant',
        'permission_callback' => '__return_true', // the Ed25519 signature is the auth
    ));
    register_rest_route('gs/v1', '/member-access/revoke', array(
        'methods'             => 'POST',
        'callback'            => 'gend_society_member_access_revoke',
        'permission_callback' => '__return_true', // the Ed25519 signature is the auth
    ));
});

function gend_society_member_access_roles()
{
    return array('administrator', 'editor', 'author', 'contributor', 'subscriber', 'shop_manager');
}

function gend_society_member_access_verify(\WP_REST_Request $request)
{
    if (!function_exists('gend_society_support_access_verify_signed_request')) {
        return new \WP_Error('unavailable', 'Signed requests are not available on this site.', array('status' => 503));
    }
    $payload = gend_society_support_access_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }
    $email = sanitize_email((string) ($payload['email'] ?? ''));
    $ref   = substr(sanitize_key((string) ($payload['ref'] ?? '')), 0, 60);
    if ($email === '' || $ref === '') {
        return new \WP_Error('bad_request', 'email and ref are required.', array('status' => 400));
    }
    $owner = function_exists('gend_society_oauth_config_value') ? strtolower(trim((string) gend_society_oauth_config_value('GDC_OWNER_EMAIL', ''))) : '';
    if ($owner !== '' && strtolower($email) === $owner) {
        return new \WP_Error('owner', 'The site owner\'s account is managed by the owner.', array('status' => 409));
    }
    $payload['email'] = $email;
    $payload['ref']   = $ref;
    return $payload;
}

function gend_society_member_access_grant(\WP_REST_Request $request)
{
    $p = gend_society_member_access_verify($request);
    if (is_wp_error($p)) {
        return $p;
    }
    $role = sanitize_key((string) ($p['role'] ?? 'editor'));
    if (!in_array($role, gend_society_member_access_roles(), true) || !get_role($role)) {
        $role = 'editor';
    }
    $user = get_user_by('email', $p['email']);
    $created = false;
    if (!$user) {
        $base  = sanitize_user(strstr($p['email'], '@', true) ?: 'member', true) ?: 'member';
        $login = $base;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        $uid = wp_insert_user(array(
            'user_login'   => $login,
            'user_email'   => $p['email'],
            'user_pass'    => wp_generate_password(32, true, true),
            'display_name' => sanitize_text_field((string) ($p['display_name'] ?? '')) ?: $login,
            'role'         => '',
        ));
        if (is_wp_error($uid)) {
            return new \WP_Error('create_failed', $uid->get_error_message(), array('status' => 500));
        }
        $user = get_user_by('id', $uid);
        $created = true;
    }
    $grants = get_user_meta($user->ID, 'gend_society_member_grants', true);
    $grants = is_array($grants) ? $grants : array();
    $had = in_array($role, (array) $user->roles, true) && !in_array($role, array_column($grants, 'role'), true);
    $grants[$p['ref']] = array('role' => $role, 'had' => $had ? 1 : 0, 'at' => time());
    update_user_meta($user->ID, 'gend_society_member_grants', $grants);
    if ($created) {
        update_user_meta($user->ID, 'gend_society_member_created_by_grant', 1);
    }
    if (!in_array($role, (array) $user->roles, true)) {
        $user->add_role($role);
    }
    return rest_ensure_response(array('ok' => true, 'user_id' => (int) $user->ID, 'created' => $created, 'role' => $role, 'admin_url' => admin_url()));
}

function gend_society_member_access_revoke(\WP_REST_Request $request)
{
    $p = gend_society_member_access_verify($request);
    if (is_wp_error($p)) {
        return $p;
    }
    $user = get_user_by('email', $p['email']);
    if (!$user) {
        return rest_ensure_response(array('ok' => true, 'removed' => false));
    }
    $grants = get_user_meta($user->ID, 'gend_society_member_grants', true);
    $grants = is_array($grants) ? $grants : array();
    if (!isset($grants[$p['ref']])) {
        return rest_ensure_response(array('ok' => true, 'removed' => false));
    }
    $g = $grants[$p['ref']];
    unset($grants[$p['ref']]);
    update_user_meta($user->ID, 'gend_society_member_grants', $grants);
    $still = in_array($g['role'], array_column($grants, 'role'), true);
    $removed = false;
    if (empty($g['had']) && !$still && in_array($g['role'], (array) $user->roles, true)) {
        $user->remove_role($g['role']);
        $removed = true;
        if (!$grants && get_user_meta($user->ID, 'gend_society_member_created_by_grant', true) && empty($user->roles)) {
            if (class_exists('WP_Session_Tokens')) {
                WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            }
        }
    }
    return rest_ensure_response(array('ok' => true, 'removed' => $removed));
}

/**
 * Allow-list the anonymous gs/v1/member-access/* routes past gend-society's
 * pri-99 logged-out REST gate; the signature inside each callback is the auth.
 */
add_filter('rest_authentication_errors', function ($result) {
    if (!is_wp_error($result)) {
        return $result;
    }
    $route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($route !== '' && strpos($route, '/gs/v1/member-access/') !== false) {
        return null;
    }
    return $result;
}, 100);
