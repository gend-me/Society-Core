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

    register_rest_route('gs/v1', '/agent/send', array(
        'methods'             => 'POST',
        'callback'            => 'gs_agent_send',
        'permission_callback' => '__return_true',   // auth IS the Ed25519 signature
    ));

    register_rest_route('gs/v1', '/agent/run', array(
        'methods'             => 'POST',
        'callback'            => 'gs_agent_run',
        'permission_callback' => '__return_true',   // auth IS the Ed25519 signature
    ));

    register_rest_route('gs/v1', '/agent/credentials', array(
        'methods'             => 'POST',
        'callback'            => 'gs_agent_credentials',
        'permission_callback' => '__return_true',   // auth IS the Ed25519 signature
    ));

    register_rest_route('gs/v1', '/agent/avatar', array(
        'methods'             => 'POST',
        'callback'            => 'gs_agent_avatar_update',
        'permission_callback' => '__return_true',   // auth IS the Ed25519 signature
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

    // Destroy ALL of the agent's auth sessions (no core helper takes a user id —
    // wp_destroy_other_sessions() only acts on the current user, so use the
    // session-token manager directly for this specific account).
    WP_Session_Tokens::get_instance($user->ID)->destroy_all();
    $user->set_role('subscriber');           // strip ai_agent; keep user for audit
    update_user_meta($user->ID, '_aipa_agent_disabled', 1);

    return rest_ensure_response(array(
        'ok'              => true,
        'state'           => 'deactivated',
        'container_user_id' => (int) $user->ID,
    ));
}

/**
 * POST /gs/v1/agent/avatar
 *
 * Signed-by-hub request → set the profile photo for the LOCAL user matching
 * the hub identity (agent OR regular member — resolved by email first, then
 * login_hint) to the pushed image, via the same core BuddyPress avatar-crop
 * path the hub itself uses. Despite living under the /agent/ prefix (reusing
 * the already-allow-listed route group — see the rest_authentication_errors
 * filter at the bottom of this file), this route is generic: any hub user
 * pushing a new profile photo to a connected Web App lands here, not just
 * agent accounts.
 */
function gs_agent_avatar_update(\WP_REST_Request $request) {

    $payload = gs_agent_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $email      = sanitize_email((string) ($payload['email'] ?? ''));
    $login_hint = sanitize_user((string) ($payload['login_hint'] ?? ''), true);
    $raw        = (string) ($payload['image_base64'] ?? '');

    if ($raw === '') {
        return new \WP_Error('gs_avatar_bad', __('image_base64 required', 'gend-society'), array('status' => 400));
    }

    $user = false;
    if ($email !== '') {
        $user = get_user_by('email', $email);
    }
    if (!$user && $login_hint !== '') {
        $user = get_user_by('login', $login_hint);
    }
    if (!$user) {
        // Not an error — this member simply has no account on this container
        // yet (e.g. invited but never logged in). Idempotent no-op.
        return rest_ensure_response(array('ok' => true, 'state' => 'no_local_user'));
    }

    if (!function_exists('bp_core_avatar_handle_crop')) {
        return new \WP_Error('gs_avatar_unavailable', __('Avatar handling is not available on this site.', 'gend-society'), array('status' => 501));
    }

    $bytes = base64_decode($raw, true);
    if ($bytes === false || strlen($bytes) === 0) {
        return new \WP_Error('gs_avatar_bad', __('Could not decode image data.', 'gend-society'), array('status' => 400));
    }
    if (strlen($bytes) > 5 * MB_IN_BYTES) {
        return new \WP_Error('gs_avatar_too_large', __('Image must be under 5MB.', 'gend-society'), array('status' => 400));
    }

    // BP_Attachment_Avatar::crop() does NOT read from an arbitrary
    // 'original_file' path despite the docblock — it reconstructs an expected
    // path as {bp_core_avatar_upload_path()}/avatars/{item_id}/{basename(...)}
    // and bails with file_exists() false if the file isn't already sitting
    // there (confirmed against the live BP source). So the staged file must
    // be written directly into that exact folder, not a generic WP temp path.
    $avatar_dir = trailingslashit(bp_core_avatar_upload_path()) . 'avatars/' . $user->ID;
    if (!file_exists($avatar_dir) && !wp_mkdir_p($avatar_dir)) {
        return new \WP_Error('gs_avatar_write_failed', __('Could not prepare the avatar folder.', 'gend-society'), array('status' => 500));
    }
    $ext  = 'jpg';
    $info = @getimagesizefromstring($bytes);
    if ($info && isset($info['mime']) && $info['mime'] === 'image/png') {
        $ext = 'png';
    }
    $staged = trailingslashit($avatar_dir) . 'orig-' . time() . '-' . wp_generate_password(6, false) . '.' . $ext;
    if (file_put_contents($staged, $bytes) === false) {
        return new \WP_Error('gs_avatar_write_failed', __('Could not stage the uploaded image.', 'gend-society'), array('status' => 500));
    }

    $dims = @getimagesize($staged);
    if (!$dims || empty($dims[0]) || empty($dims[1])) {
        @unlink($staged);
        return new \WP_Error('gs_avatar_bad_image', __('That file is not a readable image.', 'gend-society'), array('status' => 400));
    }

    $side   = min((int) $dims[0], (int) $dims[1]);
    $crop_x = (int) (((int) $dims[0] - $side) / 2);
    $crop_y = (int) (((int) $dims[1] - $side) / 2);

    // bp_attachments_current_user_can('edit_avatar') requires
    // bp_loggedin_user_id() === item_id — there is no logged-in session on a
    // signed server-to-server call, so run AS the resolved user (mirrors the
    // same pattern gs_agent_run() already uses for this exact reason).
    if (function_exists('wp_set_current_user')) {
        wp_set_current_user($user->ID);
    }

    $cropped = bp_core_avatar_handle_crop(array(
        'object'        => 'user',
        'item_id'       => $user->ID,
        'original_file' => $staged,
        'crop_w'        => $side,
        'crop_h'        => $side,
        'crop_x'        => $crop_x,
        'crop_y'        => $crop_y,
    ));

    if (!$cropped) {
        @unlink($staged);
        return new \WP_Error('gs_avatar_crop_failed', __('Could not process that image.', 'gend-society'), array('status' => 500));
    }

    return rest_ensure_response(array(
        'ok'              => true,
        'container_user_id' => (int) $user->ID,
    ));
}

/**
 * POST /gs/v1/agent/credentials
 *
 * Signed-by-hub request → mint a fresh WP Application Password for the
 * agent's container user and return it directly in the response. Nothing is
 * persisted here beyond what WP core's own Application Passwords table
 * already holds (this route mints and forwards, once, per call) — the hub
 * broker (psoo_rest_agents_credentials) that calls this is itself NOT
 * best-effort, so a failure here must surface, never be swallowed.
 *
 * ROTATE, don't accumulate: any prior 'gend-desktop-agent'-named application
 * password for this user is deleted before minting a new one, so a Desktop
 * app always gets a live credential and old ones don't pile up in the user's
 * Application Passwords list.
 */
function gs_agent_credentials(\WP_REST_Request $request) {

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
        return new \WP_Error('gs_agent_no_user', __('Agent user not found', 'gend-society'), array('status' => 404));
    }

    if (get_user_meta($user->ID, '_aipa_agent_disabled', true)) {
        return new \WP_Error('gs_agent_disabled', __('Agent is deactivated and has no credentials.', 'gend-society'), array('status' => 403));
    }

    if (!function_exists('wp_is_application_passwords_available') || !wp_is_application_passwords_available()) {
        return new \WP_Error('gs_agent_app_passwords_unavailable', __('Application Passwords are not available on this site (requires HTTPS).', 'gend-society'), array('status' => 501));
    }

    $app_name = 'gend-desktop-agent';

    // Rotate: delete any prior credential of the same name before minting.
    if (class_exists('WP_Application_Passwords')) {
        $existing = \WP_Application_Passwords::get_user_application_passwords($user->ID);
        if (is_array($existing)) {
            foreach ($existing as $rec) {
                if (isset($rec['name'], $rec['uuid']) && $rec['name'] === $app_name) {
                    \WP_Application_Passwords::delete_application_password($user->ID, $rec['uuid']);
                }
            }
        }
    }

    // wp_create_application_password() is not a real function on this core
    // version (WP 6.7.9) — WP_Application_Passwords only exposes the static
    // create_new_application_password() method, confirmed via
    // get_class_methods(). The plain-function call was an undefined-function
    // fatal (500) on every call, live-verified 2026-09-30.
    $created = \WP_Application_Passwords::create_new_application_password($user->ID, array('name' => $app_name));
    if (is_wp_error($created)) {
        return $created;
    }
    list($new_password, $item) = $created;

    return rest_ensure_response(array(
        'ok'                 => true,
        'username'           => $user->user_login,
        'app_password'       => $new_password,
        'site_url'           => home_url('/'),
        'container_user_id'  => (int) $user->ID,
    ));
}

/**
 * POST /gs/v1/agent/send
 *
 * Signed-by-hub request → send an email AS the agent, from the agent's OWN
 * stored mailbox address. The hub operator (signature-verified) specifies the
 * recipients/subject/body; the From is read SERVER-SIDE from the resolved
 * agent user's stored em_inbox_address (NEVER from the payload), so there is no
 * from-override abuse surface. A deactivated agent (_aipa_agent_disabled) is
 * refused. This route only sends what the verified request specifies — there is
 * NO autonomous/unprompted send.
 *
 * Self-contained: depends only on functions known present on this (older)
 * container plus the email-manager send core (em_inbox_send_as), which is
 * function_exists-guarded so a not-yet-deployed core returns 501, never fatals.
 */
function gs_agent_send(\WP_REST_Request $request) {

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
        // A send has no idempotent "absent" — a missing agent is an error.
        return new \WP_Error('gs_agent_no_user', __('Agent user not found', 'gend-society'), array('status' => 404));
    }

    // Hard guard: a deactivated agent must not be able to send, even if a stale
    // hub card lingers (MAIL-03).
    if (get_user_meta($user->ID, '_aipa_agent_disabled', true)) {
        return new \WP_Error('gs_agent_disabled', __('Agent is deactivated and cannot send.', 'gend-society'), array('status' => 403));
    }

    // Authoritative From: the agent's STORED mailbox address — never the payload.
    $from = strtolower(trim((string) get_user_meta($user->ID, 'em_inbox_address', true)));
    if ($from === '' || !is_email($from)) {
        return new \WP_Error('gs_agent_no_address', __('Agent has no configured inbox address.', 'gend-society'), array('status' => 409));
    }

    if (!function_exists('em_inbox_send_as')) {
        return new \WP_Error('gs_agent_no_em', __('email-manager send core not available on this site.', 'gend-society'), array('status' => 501));
    }

    $to      = $payload['to'] ?? array();
    $subject = (string) ($payload['subject'] ?? '');

    $res = em_inbox_send_as(
        $from,
        $to,
        $subject,
        array(
            'body_html'  => (string) ($payload['body_html'] ?? ''),
            'body_plain' => (string) ($payload['body_plain'] ?? ''),
        ),
        array('undo_seconds' => 0)   // synchronous, immediate relay — no UI undo on the hub side
    );
    if (is_wp_error($res)) {
        return $res;   // surface validation errors (no recipient / empty body / no address)
    }

    // Ops audit trail (discretionary — not required by any MAIL requirement).
    update_user_meta($user->ID, '_aipa_agent_last_sent_at', time());

    return rest_ensure_response(array(
        'ok'              => (bool) ($res['ok'] ?? false),
        'from'            => $from,
        'message_id'      => $res['message_id'] ?? null,
        'raw_id'          => $res['raw_id'] ?? null,
        'delivery_status' => $res['delivery_status'] ?? null,
    ));
}

/**
 * POST /gs/v1/agent/run
 *
 * Signed-by-hub request → run a prompt AS the agent's container user through the
 * container's hub AI path (gend-society → hub aipa/v1/ai-proxy → LEO/Vertex),
 * using the agent's stored persona (_aipa_agent_system_prompt). The signature IS
 * the auth (permission_callback => '__return_true'); the prompt/model come from
 * the SIGNED payload. A deactivated agent (_aipa_agent_disabled) is refused.
 *
 * GRACEFUL DEGRADE: the agent container user is provisioned (Phase 32) but never
 * OAuth-connected to gend.me, so it has no bearer → the AI call cannot be
 * credit-attributed. Rather than fatal, we return a 402 not_connected proving
 * the plumbing reached the AI gate (same standard as Phases 33/34/35).
 *
 * Self-contained on the OLDER live container: every cross-symbol call
 * (Gend_CP_OAuth_Client, GS_AI_Proxy, em_inbox_default_domain, wp_set_current_user)
 * is function_exists/class_exists/method_exists-guarded. Hard dependencies are
 * core only (get_user_by, get_user_meta, wp_remote_post, get_option,
 * wp_json_encode, rest_ensure_response) plus same-file gs_agent_verify_signed_request.
 */
function gs_agent_run(\WP_REST_Request $request) {

    $payload = gs_agent_verify_signed_request($request);
    if (is_wp_error($payload)) {
        return $payload;   // 401 on missing/bad signature
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
        return new \WP_Error('gs_agent_no_user', __('Agent user not found', 'gend-society'), array('status' => 404));
    }

    // Hard guard: a deactivated agent must not be able to run.
    if (get_user_meta($user->ID, '_aipa_agent_disabled', true)) {
        return new \WP_Error('gs_agent_disabled', __('Agent is deactivated and cannot run.', 'gend-society'), array('status' => 403));
    }

    // Run inputs from the SIGNED payload.
    $prompt = (string) ($payload['prompt'] ?? '');
    if (trim($prompt) === '') {
        return new \WP_Error('gs_agent_no_prompt', __('prompt required', 'gend-society'), array('status' => 400));
    }
    $model = (string) ($payload['model'] ?? '');

    // Persona: the container-mirrored system prompt stamped at provision (Phase 32).
    $persona = (string) get_user_meta($user->ID, '_aipa_agent_system_prompt', true);

    // Run AS the agent user so any in-container session/identity is correct, then
    // resolve THAT user's bearer.
    if (function_exists('wp_set_current_user')) {
        wp_set_current_user($user->ID);
    }

    $bearer = '';
    if (class_exists('Gend_CP_OAuth_Client') && method_exists('Gend_CP_OAuth_Client', 'bearer_for')) {
        $bearer = (string) Gend_CP_OAuth_Client::bearer_for($user->ID);
    }

    if ($bearer === '') {
        // GRACEFUL DEGRADE: the agent user has no gend.me bearer. Prove the
        // plumbing reached the AI gate; defer real credit attribution. NOT fatal.
        return new \WP_REST_Response(array(
            'ok'       => false,
            'error'    => 'not_connected',
            'message'  => __('Agent has no connected gend.me account for AI; cannot run on this Web App yet.', 'gend-society'),
            'response' => '',
            'model'    => $model,
        ), 402);
    }

    // Call the container's hub AI path (mirror GS_AI_Proxy::route_chat, but inline
    // — bearer()/auth_headers() are protected). Guard GS_AI_Proxy for hub_base;
    // fall back to the stored option then gend.me so this route is self-contained
    // even if GS_AI_Proxy is older/absent on the container.
    $hub = (class_exists('GS_AI_Proxy') && method_exists('GS_AI_Proxy', 'hub_base'))
        ? GS_AI_Proxy::hub_base()
        : untrailingslashit((string) get_option('gs_gend_base_url', 'https://gend.me'));

    $body = array(
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'system'   => $persona,
        'model'    => $model,
    );

    $r = wp_remote_post($hub . '/wp-json/aipa/v1/ai-proxy', array(
        'timeout' => 60,
        'headers' => array(
            'Authorization' => 'Bearer ' . $bearer,
            'X-Gend-Token'  => $bearer,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($r)) {
        return new \WP_Error('gs_agent_run_upstream', $r->get_error_message(), array('status' => 502));
    }

    $code = (int) wp_remote_retrieve_response_code($r);
    $raw  = (string) wp_remote_retrieve_body($r);
    $data = json_decode($raw, true);

    // Surface a 402 from the hub verbatim (insufficient credits) so the operator
    // sees the credit gate.
    if ($code === 402) {
        return new \WP_REST_Response(array(
            'ok'       => false,
            'error'    => 'insufficient_credits',
            'message'  => (is_array($data) && isset($data['message'])) ? $data['message'] : __('Insufficient credits.', 'gend-society'),
            'response' => '',
            'model'    => $model,
        ), 402);
    }
    if ($code < 200 || $code >= 300) {
        return new \WP_Error('gs_agent_run_upstream', sprintf(__('AI upstream returned %d', 'gend-society'), $code), array('status' => 502));
    }

    // Extract the assistant text best-effort (the hub proxy's shape varies).
    $text = '';
    if (is_array($data)) {
        foreach (array('response', 'text', 'content') as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $text = $data[$k];
                break;
            }
        }
        if ($text === '' && isset($data['choices'][0]['message']['content'])) {
            $text = (string) $data['choices'][0]['message']['content'];
        }
    }

    // Ops audit trail (mirrors gs_agent_send's last_sent_at).
    update_user_meta($user->ID, '_aipa_agent_last_run_at', time());

    return rest_ensure_response(array(
        'ok'       => true,
        'response' => $text,
        'model'    => (is_array($data) && isset($data['model'])) ? $data['model'] : $model,
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
