<?php
/**
 * GenD Society: "Switch to this Agent" — same-origin identity swap + a
 * site-wide "Switch back" floating pill while switched.
 *
 * Unlike support-access.php (a cross-domain magic-link grant consumed via a
 * URL token, because a hub-minted grant needs a same-origin hit on the
 * TARGET container to set cookies there), this feature is entirely
 * same-origin: a Group Admin / Project Consultant is already authenticated on
 * THIS hub and wants to become a hub-local agent-<slug> user that ALSO lives
 * on this hub. So the whole flow is one REST-triggered identity swap, with a
 * server-side-only token/transient pair remembering "who was I before" so a
 * floating pill (rendered on every page while switched) can restore them.
 *
 * Inbound REST route:
 *   POST /wp-json/gs/v1/agent-switch/restore — one-shot consume the pending
 *                                               switch state and restore the
 *                                               original user.
 *
 * Entry point: gs_agent_switch_activate() is called (function_exists-guarded)
 * from projects/includes/group-members-screen.php::psoo_rest_agents_switch_to(),
 * which has already verified the caller may manage the agent's group.
 *
 * Tamper-proofing: a 256-bit unguessable token lives ONLY in an HttpOnly
 * cookie (never in a URL, never client-readable); the {original_user_id,
 * agent_user_id} mapping it points to lives ONLY server-side in a transient
 * (the client never sees or supplies it); restoring additionally requires the
 * transient's bound agent_user_id to equal the CURRENTLY logged-in user, so a
 * copied cookie is useless from any browser not already authenticated as that
 * exact agent (this is what makes per-browser-session state safe even when
 * multiple admins switch into the same agent concurrently); consumption is
 * one-shot (transient deleted on restore); TTL caps exposure at 8 hours.
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

const GEND_SOCIETY_AGENT_SWITCH_COOKIE = 'gs_agent_switch';
const GEND_SOCIETY_AGENT_SWITCH_TTL    = 8 * HOUR_IN_SECONDS;

add_action('rest_api_init', 'gend_society_agent_switch_register_routes');
add_action('wp_footer', 'gend_society_agent_switch_render_pill');
add_action('admin_footer', 'gend_society_agent_switch_render_pill');

function gend_society_agent_switch_register_routes() {
    register_rest_route('gs/v1', '/agent-switch/restore', array(
        'methods'             => 'POST',
        'callback'            => 'gend_society_agent_switch_rest_restore',
        // The real check is inside the callback via gs_agent_switch_read_state()
        // — the current user (the agent) has no useful WP capability to gate
        // on, so we only require SOME logged-in session here.
        'permission_callback' => 'is_user_logged_in',
    ));
}

/**
 * Activate a switch: original_user_id becomes agent_user_id.
 *
 * @param int    $original_user_id The admin/consultant switching in.
 * @param int    $agent_user_id    The agent-<slug> user to become.
 * @param string $return_url       Where to send the browser back to.
 * @return array|\WP_Error array( 'redirect_url' => string ) on success.
 */
function gend_society_agent_switch_activate($original_user_id, $agent_user_id, $return_url = '') {
    $original_user_id = (int) $original_user_id;
    $agent_user_id    = (int) $agent_user_id;

    if ($original_user_id <= 0 || $agent_user_id <= 0 || $original_user_id === $agent_user_id) {
        return new \WP_Error('gs_agent_switch_bad_users', __('Invalid switch request.', 'gend-society'), array('status' => 400));
    }
    if (!get_userdata($original_user_id) || !get_userdata($agent_user_id)) {
        return new \WP_Error('gs_agent_switch_missing_user', __('User not found.', 'gend-society'), array('status' => 404));
    }

    $token = bin2hex(random_bytes(32));
    set_transient(
        'gend_society_agent_switch_' . wp_hash($token),
        array(
            'original_user_id' => $original_user_id,
            'agent_user_id'    => $agent_user_id,
            'return_url'       => (string) $return_url,
            'created_at'       => time(),
        ),
        GEND_SOCIETY_AGENT_SWITCH_TTL
    );

    gend_society_agent_switch_set_cookie($token, time() + GEND_SOCIETY_AGENT_SWITCH_TTL);
    // setcookie() only affects the NEXT request — make it visible to this one
    // too in case anything downstream in this same request checks state.
    $_COOKIE[GEND_SOCIETY_AGENT_SWITCH_COOKIE] = $token;

    wp_clear_auth_cookie();
    wp_set_current_user($agent_user_id);
    wp_set_auth_cookie($agent_user_id, false);

    return array('redirect_url' => $return_url !== '' ? $return_url : home_url('/'));
}

/**
 * Read the pending switch state for the CURRENTLY logged-in user, or null.
 * Rejects unless the transient's agent_user_id matches get_current_user_id()
 * — this is the session-binding check (see file doc header).
 *
 * @return array|null
 */
function gend_society_agent_switch_read_state() {
    $token = isset($_COOKIE[GEND_SOCIETY_AGENT_SWITCH_COOKIE]) ? (string) $_COOKIE[GEND_SOCIETY_AGENT_SWITCH_COOKIE] : '';
    if ($token === '') {
        return null;
    }

    // 1.2.0: read-once fallback to a pre-rename gs_agent_switch_* transient (key-migration.php).
    $state = function_exists('gend_society_get_agent_switch_transient')
        ? gend_society_get_agent_switch_transient(wp_hash($token))
        : get_transient('gend_society_agent_switch_' . wp_hash($token));
    if (!is_array($state) || empty($state['agent_user_id']) || empty($state['original_user_id'])) {
        return null;
    }

    if ((int) $state['agent_user_id'] !== get_current_user_id()) {
        return null;
    }

    return $state;
}

/**
 * POST /gs/v1/agent-switch/restore — one-shot consume + restore.
 *
 * @param \WP_REST_Request $request Request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function gend_society_agent_switch_rest_restore(\WP_REST_Request $request) {
    $state = gend_society_agent_switch_read_state();
    if (!$state) {
        return new \WP_Error('gs_agent_switch_no_state', __('No active agent switch to restore.', 'gend-society'), array('status' => 409));
    }

    $token = (string) $_COOKIE[GEND_SOCIETY_AGENT_SWITCH_COOKIE];
    delete_transient('gend_society_agent_switch_' . wp_hash($token));
    gend_society_agent_switch_clear_cookie();

    $original_user_id = (int) $state['original_user_id'];
    if (!get_userdata($original_user_id)) {
        return new \WP_Error('gs_agent_switch_missing_user', __('Original user no longer exists.', 'gend-society'), array('status' => 404));
    }

    wp_clear_auth_cookie();
    wp_set_current_user($original_user_id);
    wp_set_auth_cookie($original_user_id, false);

    return rest_ensure_response(array(
        'ok'           => true,
        'redirect_url' => !empty($state['return_url']) ? $state['return_url'] : home_url('/'),
    ));
}

/**
 * Set the switch-state cookie: HttpOnly + Secure(if SSL) + SameSite=Lax,
 * mirroring the flags WP's own auth cookie uses.
 *
 * @param string $token   Raw token value.
 * @param int    $expires Unix timestamp.
 */
function gend_society_agent_switch_set_cookie($token, $expires) {
    setcookie(
        GEND_SOCIETY_AGENT_SWITCH_COOKIE,
        $token,
        array(
            'expires'  => (int) $expires,
            'path'     => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        )
    );
}

function gend_society_agent_switch_clear_cookie() {
    gend_society_agent_switch_set_cookie('', time() - HOUR_IN_SECONDS);
    unset($_COOKIE[GEND_SOCIETY_AGENT_SWITCH_COOKIE]);
}

/**
 * Render the floating "Switch back" pill on EVERY front-end and wp-admin page
 * while a switch is active. Self-contained inline markup/script — no enqueued
 * asset, since this must render unconditionally site-wide.
 */
function gend_society_agent_switch_render_pill() {
    $state = gend_society_agent_switch_read_state();
    if (!$state) {
        return;
    }

    $original = get_userdata((int) $state['original_user_id']);
    $label    = $original ? $original->display_name : __('admin', 'gend-society');
    $nonce    = wp_create_nonce('wp_rest');
    $restore_url = esc_url_raw(rest_url('gs/v1/agent-switch/restore'));
    ?>
    <div id="gs-agent-switch-pill" style="position:fixed;top:14px;left:50%;transform:translateX(-50%);z-index:999999;background:#0b0e14;color:#fff;border:1px solid rgba(0,242,255,.4);border-radius:100px;padding:10px 18px;font-family:'Inter',system-ui,sans-serif;font-size:13px;font-weight:700;box-shadow:0 12px 30px rgba(0,0,0,.4);display:flex;align-items:center;gap:10px;">
        <span><?php
            printf(
                /* translators: %s: original admin display name */
                esc_html__('Switch back to %s', 'gend-society'),
                esc_html($label)
            );
        ?></span>
        <button type="button" id="gs-agent-switch-restore-btn" style="background:#00f2ff;color:#0b0e14;border:none;border-radius:100px;padding:6px 14px;font-weight:800;cursor:pointer;">
            <?php esc_html_e('Switch back', 'gend-society'); ?>
        </button>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('gs-agent-switch-restore-btn');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = <?php echo wp_json_encode(__('Switching back…', 'gend-society')); ?>;
            fetch(<?php echo wp_json_encode($restore_url); ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': <?php echo wp_json_encode($nonce); ?> }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.location.href = (data && data.redirect_url) || '/';
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = <?php echo wp_json_encode(__('Switch back', 'gend-society')); ?>;
                    window.alert(<?php echo wp_json_encode(__('Could not switch back. Please try again.', 'gend-society')); ?>);
                });
        });
    })();
    </script>
    <?php
}
