<?php
/**
 * GenD Society: container-side agent provisioning rail.
 *
 * The hub cannot call email-manager's manage_options-only create endpoint
 * directly, so the hub signs a request body with its Ed25519 keypair and POSTs
 * it here; this container-local route verifies the signature (mirroring
 * support-access.php) and then provisions a real WP user + mailbox in-process
 * via email-manager's em_inbox_provision_user(), and registers the agent as a
 * container-side `ai_agent`.
 *
 * Inbound REST routes:
 *   POST /wp-json/gs/v1/agent/provision    — verify hub signature, create the
 *                                            agent's WP user + mailbox keyed on
 *                                            agent-<slug>@<container-domain>.
 *   POST /wp-json/gs/v1/agent/deactivate   — verify hub signature, demote the
 *                                            agent user + destroy sessions +
 *                                            flag disabled (NEVER deletes — the
 *                                            mailbox + mail history are kept).
 *
 * Auth model: the Ed25519 detached signature IS the authentication
 * (permission_callback => '__return_true'), exactly like support-access.php.
 * Both routes are anonymous, so a pri-100 rest_authentication_errors filter
 * allow-lists the /gs/v1/agent/ prefix past gend-society's pri-99 blanket
 * logged-out REST 401 (see project_rest_auth_gate_allowlist).
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

const GS_AGENT_REPLAY_TTL = 5 * MINUTE_IN_SECONDS;

add_action('init', 'gs_agent_register_role');
add_action('rest_api_init', 'gs_agent_register_routes');

/**
 * Register a container-side `ai_agent` role on EVERY request (idempotent).
 *
 * MUST be on `init` (not activation-only): `init` fires before rest_api_init
 * dispatch, so the role exists in-process when gs_agent_provision() — and
 * email-manager's em_inbox_provision_user() role check — runs later in the same
 * request. An activation-only add_role would leave the role missing on
 * containers that never re-activated the plugin, and em_inbox_provision_user()'s
 * get_role('ai_agent') validation would then fail.
 */
function gs_agent_register_role() {
    if (!get_role('ai_agent')) {
        add_role('ai_agent', __('AI Agent', 'gend-society'), array('read' => true));
    }
}

function gs_agent_register_routes() {

    register_rest_route('gs/v1', '/agent/provision', array(
        'methods'             => 'POST',
        'callback'            => 'gs_agent_provision',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('gs/v1', '/agent/deactivate', array(
        'methods'             => 'POST',
        'callback'            => 'gs_agent_deactivate',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Verify the inbound request was signed by the paired gend.me hub.
 * Mirrors gs_support_access_verify_signed_request() (support-access.php).
 * Returns the decoded payload array on success, WP_Error on failure.
 */
function gs_agent_verify_signed_request(\WP_REST_Request $request) {

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
    if ($issued_at <= 0 || $issued_at > $now + 60 || $issued_at < $now - GS_AGENT_REPLAY_TTL) {
        return new \WP_Error('replay', __('Request is outside the accepted issued_at window.', 'gend-society'), array('status' => 401));
    }

    // Install ID match check — prevents a signature meant for one container
    // being replayed against another.
    $expected_install = (string) get_option('gs_install_id', '');
    if (!empty($payload['install_id']) && $expected_install !== '' && (string) $payload['install_id'] !== $expected_install) {
        return new \WP_Error('install_mismatch', __('install_id does not match this site.', 'gend-society'), array('status' => 401));
    }

    return $payload;
}

/**
 * Resolve the container mail domain, auto-setting em_inbox_default_domain from
 * home_url() ONLY when empty (never clobber an operator's deliberate value).
 * Returns the lowercase host, or '' if it can't be resolved.
 */
function gs_agent_resolve_domain() {

    $domain = function_exists('em_inbox_default_domain') ? (string) em_inbox_default_domain() : (string) get_option('em_inbox_default_domain', '');

    if ($domain === '') {
        $host = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        if ($host !== '') {
            update_option('em_inbox_default_domain', $host, false);
            $domain = $host;
        }
    }

    return $domain;
}

/**
 * POST /gs/v1/agent/provision
 *
 * Signed-by-hub request → create/ensure a container WP user + mailbox for the
 * agent, keyed on agent-<slug>@<container-domain>, carrying persona meta + the
 * container `ai_agent` role.
 */
function gs_agent_provision(\WP_REST_Request $request) {

    $payload = gs_agent_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $slug = sanitize_title((string) ($payload['slug'] ?? ''));
    if ($slug === '') {
        return new \WP_Error('gs_agent_bad_slug', __('slug required', 'gend-society'), array('status' => 400));
    }

    $domain = gs_agent_resolve_domain();
    if ($domain === '') {
        return new \WP_Error('gs_agent_no_domain', __('container has no resolvable mail domain', 'gend-society'), array('status' => 500));
    }

    $email   = 'agent-' . $slug . '@' . $domain;
    $display = sanitize_text_field((string) ($payload['name'] ?? ('Agent ' . $slug)));

    if (!function_exists('em_inbox_provision_user')) {
        return new \WP_Error('gs_agent_no_em', __('email-manager not available on this container', 'gend-society'), array('status' => 501));
    }

    $res = em_inbox_provision_user($email, $display, 'ai_agent');
    if (is_wp_error($res)) {
        return $res;
    }

    $uid = (int) $res['user_id'];

    // Stamp persona meta on the container user (sanitize each).
    update_user_meta($uid, '_aipa_is_agent', 1);
    update_user_meta($uid, '_aipa_agent_slug', $slug);

    if (isset($payload['persona'])) {
        update_user_meta($uid, '_aipa_agent_persona', wp_kses_post((string) $payload['persona']));
    }
    if (isset($payload['system_prompt'])) {
        update_user_meta($uid, '_aipa_agent_system_prompt', sanitize_textarea_field((string) $payload['system_prompt']));
    }
    if (isset($payload['default_model'])) {
        update_user_meta($uid, '_aipa_agent_default_model', sanitize_text_field((string) $payload['default_model']));
    }
    if (isset($payload['avatar'])) {
        update_user_meta($uid, '_aipa_agent_avatar', esc_url_raw((string) $payload['avatar']));
    }

    // Clear any prior disabled flag (re-provision = re-activate).
    delete_user_meta($uid, '_aipa_agent_disabled');

    return rest_ensure_response(array(
        'ok'              => true,
        'address'         => $email,
        'container_user_id' => $uid,
        'created'         => !empty($res['created']),
    ));
}

/**
 * POST /gs/v1/agent/deactivate
 *
 * Signed-by-hub request → revoke the agent's container access (strip role,
 * destroy sessions, set disabled flag) WITHOUT deleting the user, its mailbox
 * (em_inbox_address), or its mail history (wp_gdc_inbox_raw).
 */
function gs_agent_deactivate(\WP_REST_Request $request) {

    $payload = gs_agent_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $slug = sanitize_title((string) ($payload['slug'] ?? ''));
    if ($slug === '') {
        return new \WP_Error('gs_agent_bad_slug', __('slug required', 'gend-society'), array('status' => 400));
    }

    // Resolve the user by email first; fall back to login (the
    // vendor-app-manager fix_user_query rewrite can make get_user_by('email')
    // return false for users lacking wp_{site_id}_capabilities meta).
    $domain = function_exists('em_inbox_default_domain') ? (string) em_inbox_default_domain() : (string) get_option('em_inbox_default_domain', '');
    $user   = false;
    if ($domain !== '') {
        $user = get_user_by('email', 'agent-' . $slug . '@' . $domain);
    }
    if (!$user) {
        $user = get_user_by('login', 'agent-' . $slug);
    }

    if (!$user) {
        // Idempotent: nothing to deactivate.
        return rest_ensure_response(array('ok' => true, 'state' => 'absent'));
    }

    wp_destroy_other_sessions_for_user($user->ID);
    $user->set_role('subscriber');           // strip ai_agent; keep user for audit
    update_user_meta($user->ID, '_aipa_agent_disabled', 1);

    return rest_ensure_response(array(
        'ok'              => true,
        'state'           => 'deactivated',
        'container_user_id' => (int) $user->ID,
    ));
}

/**
 * Allow-list the anonymous gs/v1/agent/* routes past gend-society's pri-99
 * rest_authentication_errors gate (which blanket-401s ALL /wp-json/ for
 * logged-out users). The Ed25519 signature inside each callback is the auth, so
 * letting the request reach the callback is safe.
 * Precedent: web-shell-sites.php:303-310.
 */
add_filter('rest_authentication_errors', function ($result) {
    if (!is_wp_error($result)) {
        return $result;
    }
    $route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($route !== '' && strpos($route, '/gs/v1/agent/') !== false) {
        return null;   // signature is the auth; let the route run
    }
    return $result;
}, 100);
