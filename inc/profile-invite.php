<?php
/**
 * Profile Invite — Connections page "Invite" sub-tab
 *
 * Lets a logged-in member invite non-members to gend.me by email. Supports:
 *   - Manual email entry (email + optional name)
 *   - CSV upload (parsed client-side)
 *   - Editable subject + body template (with placeholders)
 *   - Auto-injected affiliate tracking URL (?ref=<sender_id>) on every link
 *   - Google / Outlook contacts buttons (placeholder for future OAuth wiring)
 *
 * REST endpoints (all require an authenticated user):
 *   POST gs/v1/invite/template          → save the user's custom template
 *   GET  gs/v1/invite/template          → fetch current template (custom or default)
 *   POST gs/v1/invite/send              → send invitations to a list of emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Build the affiliate / referral URL for a given user. Mirrors the format
 * used by the [affiliate_link] shortcode in sales-team/includes/shortcodes.php
 * (option `aas_tracking_url_param` defaults to "ref").
 *
 * @param int    $user_id   Sender's WP user ID.
 * @param string $base_url  Optional base URL to append the tracking param to.
 *                          Defaults to home_url('/').
 * @return string
 */
function gs_invite_get_affiliate_url( $user_id, $base_url = '' ) {
    $param = get_option( 'aas_tracking_url_param', 'ref' );
    $base  = $base_url ?: home_url( '/' );
    $sep   = ( strpos( $base, '?' ) !== false ) ? '&' : '?';
    return $base . $sep . rawurlencode( $param ) . '=' . (int) $user_id;
}

/**
 * Default invite email template. Placeholders are replaced per-recipient in
 * gs_invite_render_template(). Stored as user meta when the sender edits it.
 */
function gs_invite_default_template() {
    return array(
        'subject' => __( '{sender_name} invited you to join gend.me', 'gend-society' ),
        'body'    => __(
            '<p>Hi {name},</p>'
            . '<p><strong>{sender_name}</strong> thinks you\'d be a great addition to <a href="https://gend.me">gend.me</a> &mdash; a community for digital business builders, web app creators, and remote teams.</p>'
            . '<p><a href="{invite_link}" style="display:inline-block;background:#b608c9;color:#fff;padding:12px 22px;border-radius:8px;font-weight:700;text-decoration:none;">Join through their invite</a></p>'
            . '<p>Or paste this link into your browser:<br><a href="{invite_link}">{invite_link}</a></p>'
            . '<p>See you there!<br>&mdash; The gend.me team</p>',
            'gend-society'
        ),
    );
}

/**
 * Get the sender's saved template, falling back to the default.
 *
 * @param int $user_id
 * @return array { subject, body }
 */
function gs_invite_get_template( $user_id ) {
    $saved = get_user_meta( $user_id, 'gs_invite_template', true );
    if ( is_array( $saved ) && ! empty( $saved['subject'] ) && ! empty( $saved['body'] ) ) {
        return array(
            'subject' => (string) $saved['subject'],
            'body'    => (string) $saved['body'],
        );
    }
    return gs_invite_default_template();
}

/**
 * Replace placeholders for a specific recipient.
 *
 * @param string $template     Template string (subject or body).
 * @param array  $vars         { name, sender_name, sender_email, invite_link }
 * @return string
 */
function gs_invite_render_template( $template, $vars ) {
    $vars = wp_parse_args( $vars, array(
        'name'         => '',
        'sender_name'  => '',
        'sender_email' => '',
        'invite_link'  => '',
    ) );
    $replacements = array(
        '{name}'         => $vars['name'] ?: __( 'there', 'gend-society' ),
        '{sender_name}'  => $vars['sender_name'],
        '{sender_email}' => $vars['sender_email'],
        '{invite_link}'  => $vars['invite_link'],
    );
    return strtr( (string) $template, $replacements );
}

/**
 * HMAC-signed token round-tripped through email tracking pixels and
 * reminder URLs. Payload {sender_id, log_id, kind} packed base64.
 */
function gs_invite_make_token( $sender_id, $log_id, $kind = 'open' ) {
    $payload = array( 'u' => (int) $sender_id, 'l' => (string) $log_id, 'k' => (string) $kind );
    $b64 = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
    $sig = hash_hmac( 'sha256', $b64, wp_salt( 'auth' ) );
    return $b64 . '.' . substr( $sig, 0, 32 );
}
function gs_invite_verify_token( $token ) {
    if ( ! is_string( $token ) || strpos( $token, '.' ) === false ) return null;
    list( $b64, $sig ) = explode( '.', $token, 2 );
    $expect = substr( hash_hmac( 'sha256', $b64, wp_salt( 'auth' ) ), 0, 32 );
    if ( ! hash_equals( $expect, $sig ) ) return null;
    $data = json_decode( base64_decode( strtr( $b64, '-_', '+/' ) ), true );
    return is_array( $data ) ? $data : null;
}

/**
 * Site-wide pending-invite index (email → [{ sender_id, log_id }]) used by
 * the user_register hook to mark log entries as registered. Stored in
 * wp_options because it's keyed by recipient email, not sender.
 */
function gs_invite_index_add( $email, $sender_id, $log_id ) {
    $email = strtolower( trim( (string) $email ) );
    if ( ! $email ) return;
    $index = get_option( 'gs_invite_pending', array() );
    if ( ! is_array( $index ) ) $index = array();
    if ( ! isset( $index[ $email ] ) ) $index[ $email ] = array();
    $index[ $email ][] = array( 'u' => (int) $sender_id, 'l' => (string) $log_id );
    // Cap each email's pending list at 10 senders to keep option bounded.
    if ( count( $index[ $email ] ) > 10 ) $index[ $email ] = array_slice( $index[ $email ], -10 );
    update_option( 'gs_invite_pending', $index, false );
}

/**
 * On registration, mark every log entry pointing at the new user's email
 * as registered, then drop them from the pending index.
 */
add_action( 'user_register', 'gs_invite_handle_user_register' );
function gs_invite_handle_user_register( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->user_email ) ) return;
    $email = strtolower( trim( $user->user_email ) );
    $index = get_option( 'gs_invite_pending', array() );
    if ( empty( $index[ $email ] ) ) return;
    foreach ( $index[ $email ] as $ref ) {
        $log = get_user_meta( $ref['u'], 'gs_invite_log', true );
        if ( ! is_array( $log ) ) continue;
        foreach ( $log as &$entry ) {
            if ( isset( $entry['id'] ) && $entry['id'] === $ref['l'] && empty( $entry['registered_at'] ) ) {
                $entry['registered_at'] = time();
                $entry['registered_user_id'] = (int) $user_id;
            }
        }
        unset( $entry );
        update_user_meta( $ref['u'], 'gs_invite_log', $log );
    }
    unset( $index[ $email ] );
    update_option( 'gs_invite_pending', $index, false );
}

/**
 * Inject a 1×1 tracking pixel and rewrite the invite link to a tracker
 * redirect so we can record opens + clicks. Idempotent — only operates
 * on HTML bodies and only if no tracking elements already present.
 */
function gs_invite_inject_tracking( $html_body, $sender_id, $log_id ) {
    $token = gs_invite_make_token( $sender_id, $log_id, 'open' );
    $pixel_url = esc_url_raw( rest_url( 'gs/v1/invite/open?t=' . rawurlencode( $token ) ) );
    $pixel = '<img src="' . esc_attr( $pixel_url ) . '" width="1" height="1" alt="" style="display:none !important;width:1px;height:1px;">';
    // Append before </body> if present, otherwise at end of body.
    if ( stripos( $html_body, '</body>' ) !== false ) {
        return preg_replace( '#</body>#i', $pixel . '</body>', $html_body, 1 );
    }
    return $html_body . $pixel;
}

/**
 * Ensure the rich editor assets (TinyMCE + quicktags) are enqueued on the
 * BuddyPress profile page where our Invite tab lives. wp_editor() requires
 * these to be loaded; on the frontend they aren't enqueued by default.
 */
add_action( 'wp_enqueue_scripts', 'gs_invite_enqueue_editor_assets', 5 );
function gs_invite_enqueue_editor_assets() {
    if ( ! function_exists( 'bp_is_user_friends' ) || ! bp_is_user_friends() ) return;
    if ( function_exists( 'wp_enqueue_editor' ) ) wp_enqueue_editor();
}

/**
 * Render the Invite panel HTML. Called from the connections-tabs close hook
 * inside member-profile-pages.php.
 */
function gs_invite_render_panel() {
    if ( ! is_user_logged_in() ) {
        echo '<p class="psoo-pm-empty">' . esc_html__( 'Please log in to invite members.', 'gend-society' ) . '</p>';
        return;
    }
    $user_id      = get_current_user_id();
    $sender       = wp_get_current_user();
    $template     = gs_invite_get_template( $user_id );
    $invite_url   = gs_invite_get_affiliate_url( $user_id );
    $rest_root    = esc_url_raw( rest_url( 'gs/v1/invite' ) );
    $rest_nonce   = wp_create_nonce( 'wp_rest' );
    // Per-render unique ID so the inline IIFE can find its own wrap by id —
    // the script tag sometimes ends up parented outside .gs-invite-wrap when
    // surrounding plugins/templates juggle the DOM, which makes
    // document.currentScript.closest('.gs-invite-wrap') return null and
    // querySelector('.gs-invite-wrap') ambiguous.
    $wrap_id = 'gs-invite-' . wp_generate_uuid4();
    // wp_editor needs a stable, unique ID we can hand to JS so it can call
    // tinyMCE.get( ID ) to pull the body content before AJAX submit.
    $editor_id = 'gs_invite_body_' . str_replace( '-', '_', wp_generate_uuid4() );
    ?>
    <div id="<?php echo esc_attr( $wrap_id ); ?>" class="gs-invite-wrap" data-gs-invite
         data-rest-root="<?php echo esc_attr( $rest_root ); ?>"
         data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
         data-invite-link="<?php echo esc_attr( $invite_url ); ?>"
         data-sender-name="<?php echo esc_attr( $sender->display_name ); ?>"
         data-sender-email="<?php echo esc_attr( $sender->user_email ); ?>"
         data-editor-id="<?php echo esc_attr( $editor_id ); ?>">

        <div class="gs-invite-hero">
            <h2><?php esc_html_e( 'Grow Your Network', 'gend-society' ); ?></h2>
            <p><?php esc_html_e( 'Invite people who aren\'t on gend.me yet. Every link carries your affiliate tracking automatically — when invitees join and purchase, the commission flows back to you.', 'gend-society' ); ?></p>
            <div class="gs-invite-link-pill">
                <span class="gs-invite-link-label"><?php esc_html_e( 'Your invite link', 'gend-society' ); ?></span>
                <code><?php echo esc_html( $invite_url ); ?></code>
            </div>
        </div>

        <div class="gs-invite-grid">
            <section class="gs-invite-card">
                <h3><?php esc_html_e( 'Recipients', 'gend-society' ); ?></h3>

                <div class="gs-invite-row">
                    <div>
                        <label><?php esc_html_e( 'Email', 'gend-society' ); ?></label>
                        <input type="email" data-gs-email placeholder="name@example.com" autocomplete="off" />
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Name', 'gend-society' ); ?></label>
                        <input type="text" data-gs-name placeholder="<?php esc_attr_e( 'Add an optional name', 'gend-society' ); ?>" autocomplete="off" />
                    </div>
                </div>

                <div class="gs-invite-actions">
                    <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-gs-add-email><?php esc_html_e( 'Add Email', 'gend-society' ); ?></button>
                    <label class="gs-invite-csv">
                        <input type="file" accept=".csv" data-gs-csv hidden />
                        <span class="gs-invite-btn gs-invite-btn--ghost"><?php esc_html_e( 'Upload CSV', 'gend-society' ); ?></span>
                    </label>
                    <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-gs-oauth="google"><?php esc_html_e( 'Google Contacts', 'gend-society' ); ?></button>
                    <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-gs-oauth="microsoft"><?php esc_html_e( 'Outlook Contacts', 'gend-society' ); ?></button>
                    <label class="gs-invite-csv">
                        <input type="file" accept=".vcf,text/vcard" data-gs-vcf hidden />
                        <span class="gs-invite-btn gs-invite-btn--ghost" title="<?php esc_attr_e( 'Apple has no public Contacts API. Export Contacts.app via File → Export → Export vCard, then upload the .vcf here.', 'gend-society' ); ?>"><?php esc_html_e( 'Apple Contacts (.vcf)', 'gend-society' ); ?></span>
                    </label>
                </div>

                <div class="gs-invite-list" data-gs-list>
                    <div class="gs-invite-list-header">
                        <span><?php esc_html_e( 'Staged invitees', 'gend-society' ); ?></span>
                        <button type="button" class="gs-invite-link-btn" data-gs-clear><?php esc_html_e( 'Clear', 'gend-society' ); ?></button>
                    </div>
                    <div class="gs-invite-list-body" data-gs-list-body>
                        <p class="gs-invite-empty"><?php esc_html_e( 'Add emails or upload a CSV to stage recipients.', 'gend-society' ); ?></p>
                    </div>
                </div>

                <div class="gs-invite-send-inline">
                    <div class="gs-invite-status" data-gs-status aria-live="polite"></div>
                    <button type="button" class="gs-invite-btn gs-invite-btn--primary gs-invite-btn--full" data-gs-send><?php esc_html_e( 'Send Invites', 'gend-society' ); ?></button>
                </div>
            </section>

            <section class="gs-invite-card">
                <h3><?php esc_html_e( 'Email Template', 'gend-society' ); ?></h3>
                <p class="gs-invite-help">
                    <?php esc_html_e( 'Available placeholders:', 'gend-society' ); ?>
                    <code>{name}</code> <code>{sender_name}</code> <code>{sender_email}</code> <code>{invite_link}</code>
                </p>

                <label><?php esc_html_e( 'Subject', 'gend-society' ); ?></label>
                <input type="text" data-gs-subject value="<?php echo esc_attr( $template['subject'] ); ?>" />

                <label><?php esc_html_e( 'Body', 'gend-society' ); ?></label>
                <?php
                // wp_editor renders Visual + Text tabs natively. The unique
                // $editor_id is computed at the top of this function so the
                // wrap data-editor-id attribute references it.
                wp_editor(
                    $template['body'],
                    $editor_id,
                    array(
                        'media_buttons' => true,   // "Add Media" upload button above toolbar
                        'textarea_name' => 'gs_invite_body',
                        'textarea_rows' => 22,     // taller default (Text-mode height)
                        'editor_height' => 480,    // hard min-height in pixels for Visual mode
                        'tinymce'       => array(
                            'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,forecolor,backcolor,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink',
                            'toolbar2' => 'fontselect,fontsizeselect,outdent,indent,undo,redo,removeformat,fullscreen',
                            'wpautop'  => false,
                        ),
                        'quicktags'     => true,
                    )
                );
                ?>

                <div class="gs-invite-tpl-actions">
                    <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-gs-tpl-save><?php esc_html_e( 'Save Template', 'gend-society' ); ?></button>
                    <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-gs-tpl-reset><?php esc_html_e( 'Reset to Default', 'gend-society' ); ?></button>
                </div>

                <div class="gs-invite-test">
                    <label><?php esc_html_e( 'Send a test email', 'gend-society' ); ?></label>
                    <div class="gs-invite-test-row">
                        <input type="email" data-gs-test-email placeholder="<?php esc_attr_e( 'name@example.com', 'gend-society' ); ?>" />
                        <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-gs-test-send><?php esc_html_e( 'Send Test', 'gend-society' ); ?></button>
                    </div>
                    <p class="gs-invite-help"><?php esc_html_e( 'Sends the current Subject + Body (with placeholders rendered as if the test recipient were the invitee).', 'gend-society' ); ?></p>
                </div>
            </section>
        </div>



        <section class="gs-invite-sent" data-gs-sent>
            <div class="gs-invite-sent-head">
                <h3><?php esc_html_e( 'Sent Invitations', 'gend-society' ); ?></h3>
                <button type="button" class="gs-invite-link-btn" data-gs-sent-refresh><?php esc_html_e( 'Refresh', 'gend-society' ); ?></button>
            </div>
            <div class="gs-invite-sent-body" data-gs-sent-body>
                <p class="gs-invite-empty"><?php esc_html_e( 'Loading…', 'gend-society' ); ?></p>
            </div>
        </section>

        <div class="gs-invite-reminder" data-gs-reminder hidden>
            <div class="gs-invite-reminder__backdrop" data-rm-close></div>
            <div class="gs-invite-reminder__modal">
                <div class="gs-invite-reminder__head">
                    <h3 data-rm-title><?php esc_html_e( 'Send Reminder', 'gend-society' ); ?></h3>
                    <button type="button" class="gs-invite-picker__x" data-rm-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                </div>
                <div class="gs-invite-reminder__body">
                    <label><?php esc_html_e( 'To', 'gend-society' ); ?></label>
                    <input type="email" data-rm-to readonly />

                    <label><?php esc_html_e( 'Subject', 'gend-society' ); ?></label>
                    <input type="text" data-rm-subject />

                    <label><?php esc_html_e( 'Body (HTML)', 'gend-society' ); ?></label>
                    <textarea data-rm-body rows="10"></textarea>
                    <p class="gs-invite-help"><?php esc_html_e( 'Placeholders {name}, {sender_name}, {invite_link} render per recipient.', 'gend-society' ); ?></p>
                </div>
                <div class="gs-invite-reminder__footer">
                    <span class="gs-invite-status" data-rm-status></span>
                    <button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-rm-close><?php esc_html_e( 'Cancel', 'gend-society' ); ?></button>
                    <button type="button" class="gs-invite-btn gs-invite-btn--primary" data-rm-send><?php esc_html_e( 'Send Reminder', 'gend-society' ); ?></button>
                </div>
            </div>
        </div>
    </div>

    <style>
    .gs-invite-wrap { color:#e2e8f0; padding:24px 0; }
    .gs-invite-hero { background:linear-gradient(135deg, rgba(182,8,201,0.12), rgba(137,194,224,0.08)); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:24px 28px; margin-bottom:20px; }
    .gs-invite-hero h2 { margin:0 0 8px; font-size:1.4rem; color:#f8fafc; }
    .gs-invite-hero p { margin:0 0 14px; color:#cbd5e1; line-height:1.5; }
    .gs-invite-link-pill { display:inline-flex; align-items:center; gap:12px; background:rgba(11,14,20,0.6); border:1px solid rgba(255,255,255,0.1); padding:8px 14px; border-radius:999px; max-width:100%; overflow:hidden; }
    .gs-invite-link-label { font-size:0.7rem; letter-spacing:0.12em; text-transform:uppercase; color:#94a3b8; }
    .gs-invite-link-pill code { background:transparent; color:#89C2E0; font-size:0.85rem; word-break:break-all; }

    .gs-invite-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:16px; }
    @media (max-width:900px){ .gs-invite-grid{ grid-template-columns:1fr; } }
    .gs-invite-card { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:20px; }
    .gs-invite-card h3 { margin:0 0 14px; font-size:1rem; color:#f8fafc; letter-spacing:0.04em; text-transform:uppercase; }
    .gs-invite-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:12px; margin-bottom:12px; }
    @media (max-width:600px){ .gs-invite-row{ grid-template-columns:1fr; } }
    .gs-invite-row label, .gs-invite-card > label { display:block; font-size:0.7rem; color:#94a3b8; letter-spacing:0.12em; text-transform:uppercase; margin-bottom:6px; }
    .gs-invite-card input[type="email"], .gs-invite-card input[type="text"], .gs-invite-card textarea { width:100%; background:rgba(11,14,20,0.6); border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 12px; color:#f8fafc; font-family:inherit; font-size:0.9rem; box-sizing:border-box; }
    .gs-invite-card textarea { font-family:"SF Mono", Menlo, Consolas, monospace; line-height:1.5; resize:vertical; }
    .gs-invite-card > label + input,
    .gs-invite-card > label + textarea { margin-bottom:14px; }

    .gs-invite-actions { display:flex; flex-wrap:wrap; gap:8px; margin:6px 0 14px; }
    .gs-invite-btn { display:inline-flex; align-items:center; padding:8px 14px; border-radius:10px; border:1px solid rgba(255,255,255,0.16); background:rgba(255,255,255,0.04); color:#e2e8f0; font-size:0.78rem; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; cursor:pointer; transition:all 0.18s; }
    .gs-invite-btn:hover:not([disabled]) { background:rgba(182,8,201,0.16); border-color:rgba(182,8,201,0.45); color:#fff; }
    .gs-invite-btn[disabled] { opacity:0.45; cursor:not-allowed; }
    .gs-invite-btn--primary { background:#b608c9; border-color:#b608c9; color:#fff; }
    .gs-invite-btn--primary:hover:not([disabled]) { background:#cc1ee1; border-color:#cc1ee1; }
    .gs-invite-csv { display:inline-flex; }
    .gs-invite-link-btn { background:none; border:none; color:#89C2E0; font-size:0.75rem; cursor:pointer; padding:4px 8px; }

    .gs-invite-list { background:rgba(11,14,20,0.45); border:1px solid rgba(255,255,255,0.08); border-radius:10px; }
    .gs-invite-list-header { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid rgba(255,255,255,0.08); font-size:0.7rem; color:#94a3b8; letter-spacing:0.12em; text-transform:uppercase; }
    .gs-invite-list-body { padding:8px 4px; max-height:220px; overflow:auto; }
    .gs-invite-empty { color:#64748b; font-size:0.85rem; text-align:center; margin:18px 0; }
    .gs-invite-row-item { display:flex; justify-content:space-between; align-items:center; padding:6px 12px; border-radius:6px; }
    .gs-invite-row-item:hover { background:rgba(255,255,255,0.04); }
    .gs-invite-row-item .gs-invite-row-meta { display:flex; flex-direction:column; min-width:0; }
    .gs-invite-row-item .gs-invite-row-meta strong { color:#e2e8f0; font-size:0.85rem; font-weight:600; }
    .gs-invite-row-item .gs-invite-row-meta span { color:#94a3b8; font-size:0.78rem; }
    .gs-invite-row-item .gs-invite-row-remove { background:none; border:none; color:#f87171; cursor:pointer; font-size:1.2rem; line-height:1; }

    .gs-invite-tpl-actions { display:flex; gap:8px; }

    .gs-invite-send-bar { display:flex; align-items:center; gap:14px; justify-content:flex-end; margin-top:18px; padding:14px 0; border-top:1px solid rgba(255,255,255,0.08); }
    .gs-invite-status { color:#94a3b8; font-size:0.85rem; }
    .gs-invite-status.is-error { color:#f87171; }
    .gs-invite-status.is-success { color:#34d399; }

    /* ── Contact picker modal ─────────────────────────────────────────── */
    .gs-invite-picker { position: fixed; inset: 0; z-index: 999999; display: none; }
    .gs-invite-picker.is-open { display: block; }
    .gs-invite-picker__backdrop { position: absolute; inset: 0; background: rgba(3,7,18,0.78); backdrop-filter: blur(2px); }
    .gs-invite-picker__modal { position: relative; max-width: 600px; width: calc(100% - 32px); margin: 5vh auto 0; max-height: 80vh; background: #0b1220; border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; box-shadow: 0 24px 80px rgba(0,0,0,.55); display: flex; flex-direction: column; color: #e2e8f0; font-family: inherit; }
    .gs-invite-picker__head { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .gs-invite-picker__head h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #f8fafc; text-transform: capitalize; }
    .gs-invite-picker__x { background: none; border: 0; color: #94a3b8; font-size: 1.6rem; line-height: 1; cursor: pointer; padding: 0 4px; }
    .gs-invite-picker__x:hover { color: #f8fafc; }
    .gs-invite-picker__toolbar { display: flex; flex-direction: column; gap: 8px; padding: 12px 20px; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .gs-invite-picker__search { width: 100%; background: rgba(11,14,20,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; padding: 8px 12px; color: #f8fafc; font-size: 0.9rem; box-sizing: border-box; }
    .gs-invite-picker__bulk { display: flex; align-items: center; gap: 14px; font-size: 0.78rem; color: #94a3b8; }
    .gs-invite-picker__count { margin-left: auto; }
    .gs-invite-picker__list { flex: 1 1 auto; overflow-y: auto; padding: 4px 8px; }
    .gs-invite-picker__row { display: grid; grid-template-columns: auto minmax(0, 1fr) minmax(0, 1.4fr); align-items: center; gap: 12px; padding: 8px 12px; border-radius: 6px; cursor: pointer; }
    .gs-invite-picker__row:hover { background: rgba(255,255,255,0.04); }
    .gs-invite-picker__row input { accent-color: #b608c9; flex-shrink: 0; }
    .gs-invite-picker__row-name { color: #e2e8f0; font-size: 0.9rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .gs-invite-picker__row-email { color: #94a3b8; font-size: 0.82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .gs-invite-picker__footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.08); }

    /* ── Send-test row ────────────────────────────────────────────────── */
    .gs-invite-test { margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); }
    .gs-invite-test-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; margin-bottom: 8px; }

    /* ── Sent-invitations list ────────────────────────────────────────── */
    .gs-invite-sent { margin-top: 28px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.08); }
    .gs-invite-sent-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .gs-invite-sent-head h3 { margin: 0; font-size: 0.95rem; font-weight: 700; color: #f8fafc; letter-spacing: 0.04em; text-transform: uppercase; }
    .gs-invite-sent-row { display: grid; grid-template-columns: minmax(0,1.6fr) minmax(0,1fr) minmax(0,1fr) auto; gap: 14px; align-items: center; padding: 10px 14px; border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; background: rgba(255,255,255,0.02); margin-bottom: 8px; font-size: 0.85rem; }
    .gs-invite-sent-row > div { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .gs-invite-sent-meta { color: #94a3b8; font-size: 0.78rem; }
    .gs-invite-sent-meta strong { color: #e2e8f0; font-weight: 600; }
    .gs-invite-sent-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
    .gs-invite-sent-pill.is-sent      { background: rgba(125,211,252,0.16); color: #7dd3fc; }
    .gs-invite-sent-pill.is-opened    { background: rgba(168,85,247,0.18);  color: #c4b5fd; }
    .gs-invite-sent-pill.is-registered{ background: rgba(52,211,153,0.18);  color: #34d399; }

    /* ── Reminder popup ───────────────────────────────────────────────── */
    .gs-invite-reminder { position: fixed; inset: 0; z-index: 999998; }
    .gs-invite-reminder[hidden] { display: none; }
    .gs-invite-reminder__backdrop { position: absolute; inset: 0; background: rgba(3,7,18,0.78); backdrop-filter: blur(2px); }
    .gs-invite-reminder__modal { position: relative; max-width: 640px; width: calc(100% - 32px); margin: 5vh auto 0; background: #0b1220; border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; box-shadow: 0 24px 80px rgba(0,0,0,0.55); color: #e2e8f0; }
    .gs-invite-reminder__head { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .gs-invite-reminder__head h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #f8fafc; }
    .gs-invite-reminder__body { padding: 16px 20px; }
    .gs-invite-reminder__body label { display: block; font-size: 0.7rem; color: #94a3b8; letter-spacing: 0.12em; text-transform: uppercase; margin: 10px 0 6px; }
    .gs-invite-reminder__body input,
    .gs-invite-reminder__body textarea { width: 100%; background: rgba(11,14,20,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; padding: 10px 12px; color: #f8fafc; font-family: inherit; font-size: 0.9rem; box-sizing: border-box; }
    .gs-invite-reminder__body textarea { font-family: "SF Mono", Menlo, Consolas, monospace; line-height: 1.5; }
    .gs-invite-reminder__body input[readonly] { color: #94a3b8; }
    .gs-invite-reminder__footer { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
    .gs-invite-reminder__footer .gs-invite-status { margin-right: auto; }
    </style>

    <style id="gs-invite-polish">
    /* ── Typography reset & dashboard polish ─────────────────────────────
       Beats theme/Youzify CSS that turns <p>/<label>/<h2>/<h3> serif. We
       deliberately AVOID a universal selector here — `.gs-invite-wrap *`
       was clobbering TinyMCE's `tinymce` icon font and the dashicons font,
       leaving the toolbar with empty icon boxes. List the elements that
       actually need the override instead. TinyMCE/quicktags trees inherit
       through their own internal font-family rules. */
    .gs-invite-wrap {
        font-family: "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, sans-serif !important;
        box-sizing: border-box;
    }
    .gs-invite-wrap p,
    .gs-invite-wrap h1, .gs-invite-wrap h2, .gs-invite-wrap h3, .gs-invite-wrap h4, .gs-invite-wrap h5,
    .gs-invite-wrap label,
    .gs-invite-wrap a,
    .gs-invite-wrap span:not(.mce-ico):not([class*="mce-i-"]):not(.dashicons),
    .gs-invite-wrap div:not([class*="mce-"]):not(.quicktags-toolbar),
    .gs-invite-wrap button:not([class*="mce-"]):not(.qt-html):not([class*="ed_"]),
    .gs-invite-wrap input,
    .gs-invite-wrap small,
    .gs-invite-wrap strong, .gs-invite-wrap em {
        font-family: "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, sans-serif !important;
        box-sizing: border-box;
    }
    .gs-invite-wrap > * { box-sizing: border-box; }
    .gs-invite-wrap code, .gs-invite-wrap pre, .gs-invite-wrap textarea {
        font-family: "SF Mono", Menlo, Consolas, "Courier New", monospace !important;
    }
    .gs-invite-wrap h1, .gs-invite-wrap h2, .gs-invite-wrap h3, .gs-invite-wrap h4 {
        font-weight: 700 !important; line-height: 1.25 !important; letter-spacing: -0.005em !important;
    }
    .gs-invite-wrap p { margin: 0 0 10px !important; line-height: 1.55 !important; }
    .gs-invite-wrap p:last-child { margin-bottom: 0 !important; }

    /* Tighten root spacing & override the older gs-invite-wrap padding. */
    .gs-invite-wrap { padding: 28px 0 60px !important; opacity: 0; animation: gs-i-fade 0.4s ease-out 0.05s forwards; }

    /* Hero — bigger, animated sheen */
    .gs-invite-hero {
        position: relative !important;
        background: linear-gradient(135deg, rgba(182,8,201,0.18), rgba(137,194,224,0.10) 60%, rgba(0,0,0,0)) !important;
        border: 1px solid rgba(182,8,201,0.25) !important;
        border-radius: 18px !important;
        padding: 30px 34px !important;
        margin-bottom: 24px !important;
        overflow: hidden !important;
    }
    .gs-invite-hero::before {
        content: ""; position: absolute; inset: 0;
        background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,0.06) 50%, transparent 70%);
        background-size: 200% 100%;
        animation: gs-i-scan 7s ease-in-out infinite;
        pointer-events: none;
    }
    .gs-invite-hero h2 { font-size: 1.55rem !important; color: #f8fafc !important; margin: 0 0 10px !important; }
    .gs-invite-hero p { color: #cbd5e1 !important; font-size: 0.95rem !important; max-width: 720px; margin: 0 0 16px !important; }
    .gs-invite-link-pill { padding: 10px 18px !important; gap: 14px !important; }
    .gs-invite-link-label { font-size: 0.65rem !important; letter-spacing: 0.18em !important; font-weight: 600 !important; }
    .gs-invite-link-pill code { font-size: 0.85rem !important; }

    /* Two-column grid spacing */
    .gs-invite-grid { gap: 18px !important; }
    .gs-invite-card {
        background: rgba(11,14,20,0.45) !important;
        border: 1px solid rgba(255,255,255,0.08) !important;
        border-radius: 16px !important;
        padding: 24px !important;
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    }
    .gs-invite-card h3 {
        font-size: 0.78rem !important; color: #f8fafc !important;
        letter-spacing: 0.18em !important; text-transform: uppercase !important;
        margin: 0 0 18px !important; padding-bottom: 14px !important;
        border-bottom: 1px solid rgba(255,255,255,0.06) !important;
    }

    /* Labels — beat the WP frontend / Youzify default <label> styling that
       was rendering as serif large text. */
    .gs-invite-wrap label,
    .gs-invite-wrap .gs-invite-row label,
    .gs-invite-wrap .gs-invite-card > label,
    .gs-invite-wrap .gs-invite-test > label,
    .gs-invite-wrap .gs-invite-reminder__body label {
        display: block !important;
        font-size: 0.65rem !important;
        font-weight: 700 !important;
        color: #94a3b8 !important;
        letter-spacing: 0.18em !important;
        text-transform: uppercase !important;
        margin: 0 0 8px !important;
        line-height: 1.2 !important;
    }

    /* Inputs — focus ring, consistent height */
    .gs-invite-wrap input[type="email"],
    .gs-invite-wrap input[type="text"],
    .gs-invite-wrap input[type="search"],
    .gs-invite-wrap textarea {
        background: rgba(11,14,20,0.7) !important;
        border: 1px solid rgba(255,255,255,0.12) !important;
        border-radius: 10px !important;
        padding: 11px 14px !important;
        color: #f8fafc !important;
        font-size: 0.92rem !important;
        line-height: 1.4 !important;
        transition: border-color 0.18s, box-shadow 0.18s !important;
    }
    .gs-invite-wrap input:focus, .gs-invite-wrap textarea:focus {
        outline: none !important;
        border-color: rgba(182,8,201,0.6) !important;
        box-shadow: 0 0 0 3px rgba(182,8,201,0.18) !important;
    }
    .gs-invite-wrap input[readonly] { color: #94a3b8 !important; }

    /* Help text — fixes the "Available placeholders" serif overflow */
    .gs-invite-help {
        color: #64748b !important;
        font-size: 0.78rem !important;
        font-weight: 400 !important;
        margin: 8px 0 0 !important;
        line-height: 1.5 !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
    }
    .gs-invite-help code {
        background: rgba(137,194,224,0.12) !important;
        color: #89C2E0 !important;
        padding: 2px 6px !important;
        border-radius: 4px !important;
        font-size: 0.78rem !important;
        margin: 0 2px;
    }

    /* Buttons — gradient primary, lift on hover */
    .gs-invite-btn {
        padding: 10px 16px !important; border-radius: 10px !important;
        font-size: 0.72rem !important; font-weight: 700 !important;
        letter-spacing: 0.10em !important; gap: 8px !important;
        transition: all 0.2s ease !important;
        text-decoration: none !important;
    }
    .gs-invite-btn:hover:not([disabled]) {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(182,8,201,0.20);
    }
    .gs-invite-btn--primary {
        background: linear-gradient(135deg, #b608c9, #cc1ee1) !important;
        border-color: rgba(255,255,255,0.14) !important;
        box-shadow: 0 8px 24px rgba(182,8,201,0.32) !important;
    }
    .gs-invite-btn--primary:hover:not([disabled]) {
        background: linear-gradient(135deg, #cc1ee1, #d957ec) !important;
        box-shadow: 0 12px 36px rgba(182,8,201,0.45) !important;
    }
    .gs-invite-link-btn {
        font-size: 0.7rem !important; font-weight: 600 !important;
        letter-spacing: 0.1em !important; text-transform: uppercase !important;
    }

    /* Test-send row */
    .gs-invite-test { margin-top: 22px !important; padding-top: 18px !important; }
    .gs-invite-test-row { gap: 10px !important; margin: 8px 0 6px !important; }

    /* Inline send block — sits inside the Recipients card under the staged
       list so the recipient flow (add → stage → send) all lives in one column. */
    .gs-invite-send-inline {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid rgba(255,255,255,0.06);
        display: flex; flex-direction: column; gap: 10px;
    }
    .gs-invite-send-inline .gs-invite-status {
        margin: 0 !important;
        text-align: left;
    }
    .gs-invite-btn--full {
        width: 100%;
        justify-content: center;
        padding: 14px 18px !important;
        font-size: 0.78rem !important;
    }

    /* Sent invitations */
    .gs-invite-sent { margin-top: 32px !important; padding-top: 0 !important; border-top: 0 !important; }
    .gs-invite-sent-head h3 {
        font-size: 0.78rem !important;
        letter-spacing: 0.18em !important;
        margin: 0 0 14px !important;
    }
    .gs-invite-sent-row {
        background: rgba(11,14,20,0.45) !important;
        border: 1px solid rgba(255,255,255,0.08) !important;
        padding: 14px 18px !important;
        border-radius: 12px !important;
        transition: border-color 0.2s, transform 0.2s !important;
    }
    .gs-invite-sent-row:hover { border-color: rgba(182,8,201,0.32) !important; transform: translateY(-1px); }
    .gs-invite-sent-meta { font-size: 0.78rem !important; }

    /* TinyMCE / wp_editor — keep TinyMCE in its NATIVE light theme so toolbar
       icons render with proper contrast AND the editing surface looks like
       a real email inbox (white background, dark text). Hands off the
       internal layout: the Add Media row + Visual/Text tabs use slight
       negative-margin offsets that get clipped if we set overflow:hidden
       or a tight border on the wrap. */
    .gs-invite-wrap .wp-editor-wrap {
        margin-bottom: 14px;
    }
    /* Make the Visual mode iframe + Text mode textarea tall enough to
       comfortably read more than four lines at a time. */
    .gs-invite-wrap .mce-edit-area iframe,
    .gs-invite-wrap textarea.wp-editor-area {
        min-height: 380px !important;
    }
    /* Native MCE / Quicktags styling stays — no dark overrides. */

    /* ── Entrance animations ──────────────────────────────────────────── */
    @keyframes gs-i-rise {
        0%   { opacity: 0; transform: translateY(22px) scale(0.985); filter: blur(4px); }
        100% { opacity: 1; transform: translateY(0)    scale(1);     filter: blur(0); }
    }
    @keyframes gs-i-fade {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    @keyframes gs-i-scan {
        from { background-position: -100% 0; }
        to   { background-position: 200% 0; }
    }
    /* Per-section staggered build-in. Numeric prefix in the variable lets
       us cascade across the whole panel in one rule set. */
    .gs-invite-wrap > .gs-invite-hero      { --i: 1; opacity: 0; animation: gs-i-rise 0.7s cubic-bezier(0.16,1,0.3,1) both; animation-delay: calc(var(--i) * 80ms + 80ms); }
    .gs-invite-wrap > .gs-invite-grid      { opacity: 1; }
    .gs-invite-wrap > .gs-invite-grid > .gs-invite-card:nth-child(1) { --i: 3; opacity: 0; animation: gs-i-rise 0.7s cubic-bezier(0.16,1,0.3,1) both; animation-delay: calc(var(--i) * 80ms + 80ms); }
    .gs-invite-wrap > .gs-invite-grid > .gs-invite-card:nth-child(2) { --i: 5; opacity: 0; animation: gs-i-rise 0.7s cubic-bezier(0.16,1,0.3,1) both; animation-delay: calc(var(--i) * 80ms + 80ms); }
    .gs-invite-wrap > .gs-invite-sent      { --i: 7; opacity: 0; animation: gs-i-rise 0.7s cubic-bezier(0.16,1,0.3,1) both; animation-delay: calc(var(--i) * 80ms + 80ms); }
    /* Send Invites button is now inside the Recipients card; cascade it to
       fade in last among that card's children. */
    .gs-invite-card .gs-invite-send-inline { opacity: 0; animation: gs-i-rise 0.55s cubic-bezier(0.16,1,0.3,1) both; animation-delay: 600ms; }

    /* Inside the recipients card, stagger child rows */
    .gs-invite-card .gs-invite-row     { opacity: 0; animation: gs-i-rise 0.55s cubic-bezier(0.16,1,0.3,1) both; animation-delay: 360ms; }
    .gs-invite-card .gs-invite-actions { opacity: 0; animation: gs-i-rise 0.55s cubic-bezier(0.16,1,0.3,1) both; animation-delay: 440ms; }
    .gs-invite-card .gs-invite-list    { opacity: 0; animation: gs-i-rise 0.55s cubic-bezier(0.16,1,0.3,1) both; animation-delay: 520ms; }

    /* Inside the email-template card, stagger sub-blocks */
    .gs-invite-card > label,
    .gs-invite-card .gs-invite-help,
    .gs-invite-card input[type="text"],
    .gs-invite-card .wp-editor-wrap,
    .gs-invite-card .gs-invite-tpl-actions,
    .gs-invite-card .gs-invite-test {
        opacity: 0;
        animation: gs-i-rise 0.55s cubic-bezier(0.16,1,0.3,1) both;
    }
    .gs-invite-card .gs-invite-help                   { animation-delay: 540ms; }
    .gs-invite-card > label:nth-of-type(1)            { animation-delay: 580ms; }
    .gs-invite-card input[type="text"][data-gs-subject]{ animation-delay: 620ms; }
    .gs-invite-card .wp-editor-wrap                   { animation-delay: 680ms; }
    .gs-invite-card .gs-invite-tpl-actions            { animation-delay: 760ms; }
    .gs-invite-card .gs-invite-test                   { animation-delay: 820ms; }

    /* Sent-list rows fade in after the section header */
    .gs-invite-sent-row { opacity: 0; animation: gs-i-rise 0.45s ease-out both; }
    .gs-invite-sent-row:nth-child(1){ animation-delay: 80ms; }
    .gs-invite-sent-row:nth-child(2){ animation-delay: 140ms; }
    .gs-invite-sent-row:nth-child(3){ animation-delay: 200ms; }
    .gs-invite-sent-row:nth-child(4){ animation-delay: 260ms; }
    .gs-invite-sent-row:nth-child(n+5){ animation-delay: 320ms; }

    @media (prefers-reduced-motion: reduce) {
        .gs-invite-wrap *, .gs-invite-wrap { animation-duration: 0.01ms !important; animation-delay: 0ms !important; }
    }
    </style>

    <script>
    (function () {
        var WRAP_ID = <?php echo wp_json_encode( $wrap_id ); ?>;
        function init () {
        // Resolve the wrap by its unique ID so we always find OUR instance,
        // regardless of where the parser ended up putting this <script> tag.
        var wrap = document.getElementById( WRAP_ID );
        if ( ! wrap ) {
            console.warn('[gs-invite] wrap not found:', WRAP_ID);
            return;
        }

        var REST_ROOT  = wrap.getAttribute('data-rest-root');
        var REST_NONCE = wrap.getAttribute('data-rest-nonce');
        var senderName  = wrap.getAttribute('data-sender-name');
        var senderEmail = wrap.getAttribute('data-sender-email');

        var emailInput  = wrap.querySelector('[data-gs-email]');
        var nameInput   = wrap.querySelector('[data-gs-name]');
        var addBtn      = wrap.querySelector('[data-gs-add-email]');
        var csvInput    = wrap.querySelector('[data-gs-csv]');
        var clearBtn    = wrap.querySelector('[data-gs-clear]');
        var listBody    = wrap.querySelector('[data-gs-list-body]');
        var subjectInput= wrap.querySelector('[data-gs-subject]');
        var tplSaveBtn  = wrap.querySelector('[data-gs-tpl-save]');
        var tplResetBtn = wrap.querySelector('[data-gs-tpl-reset]');
        var sendBtn     = wrap.querySelector('[data-gs-send]');
        var statusEl    = wrap.querySelector('[data-gs-status]');
        var sentBody    = wrap.querySelector('[data-gs-sent-body]');
        var sentRefresh = wrap.querySelector('[data-gs-sent-refresh]');
        var testEmail   = wrap.querySelector('[data-gs-test-email]');
        var testSendBtn = wrap.querySelector('[data-gs-test-send]');

        var EDITOR_ID   = wrap.getAttribute('data-editor-id');
        // Body lives in TinyMCE in Visual mode and the textarea in Text mode.
        // tinymce.get(id) returns null in Text mode; the textarea always has
        // the latest value when in Text mode. Combine both reads.
        function getBody () {
            var ed = window.tinymce && tinymce.get( EDITOR_ID );
            if ( ed && ! ed.isHidden() ) { return ed.getContent(); }
            var ta = document.getElementById( EDITOR_ID );
            return ta ? ta.value : '';
        }
        function setBody ( html ) {
            var ed = window.tinymce && tinymce.get( EDITOR_ID );
            if ( ed ) { ed.setContent( html || '' ); }
            var ta = document.getElementById( EDITOR_ID );
            if ( ta ) { ta.value = html || ''; }
        }

        var staged = []; // { email, name }

        function setStatus( msg, type ) {
            statusEl.textContent = msg || '';
            statusEl.classList.remove('is-error','is-success');
            if ( type ) statusEl.classList.add('is-' + type);
        }

        function isValidEmail( e ) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String(e || '').trim() );
        }

        function renderList() {
            if ( ! staged.length ) {
                listBody.innerHTML = '<p class="gs-invite-empty">Add emails or upload a CSV to stage recipients.</p>';
                return;
            }
            listBody.innerHTML = '';
            staged.forEach(function ( row, idx ) {
                var item = document.createElement('div');
                item.className = 'gs-invite-row-item';
                item.innerHTML =
                    '<div class="gs-invite-row-meta">'
                    + '<strong></strong>'
                    + '<span></span>'
                    + '</div>'
                    + '<button type="button" class="gs-invite-row-remove" aria-label="Remove">&times;</button>';
                item.querySelector('strong').textContent = row.email;
                item.querySelector('span').textContent = row.name || '';
                item.querySelector('.gs-invite-row-remove').addEventListener('click', function () {
                    staged.splice( idx, 1 );
                    renderList();
                });
                listBody.appendChild( item );
            });
        }

        function addEmail( email, name ) {
            email = String(email || '').trim().toLowerCase();
            name  = String(name  || '').trim();
            if ( ! isValidEmail( email ) ) return false;
            if ( staged.some(function(r){ return r.email === email; }) ) return false;
            staged.push({ email: email, name: name });
            renderList();
            return true;
        }

        addBtn.addEventListener('click', function () {
            var email = emailInput.value;
            var name  = nameInput.value;
            if ( ! isValidEmail( email ) ) {
                setStatus( 'Enter a valid email address.', 'error' );
                return;
            }
            addEmail( email, name );
            emailInput.value = '';
            nameInput.value  = '';
            setStatus( 'Added.', 'success' );
        });

        clearBtn.addEventListener('click', function () {
            staged = [];
            renderList();
            setStatus( '' );
        });

        // CSV parser — assumes first row is headers OR a list of emails. Looks
        // for an "email" column; falls back to scanning every cell. XLSX is
        // accepted by the file picker but parsing it requires a library — we
        // tell the user to convert to CSV for now.
        csvInput.addEventListener('change', function () {
            var file = csvInput.files && csvInput.files[0];
            if ( ! file ) return;
            var name = file.name.toLowerCase();
            if ( name.endsWith('.xlsx') || name.endsWith('.xls') ) {
                setStatus( 'XLSX parsing isn\'t built in yet — please save as CSV and try again.', 'error' );
                csvInput.value = '';
                return;
            }
            var reader = new FileReader();
            reader.onload = function ( ev ) {
                var text = String( ev.target.result || '' );
                var lines = text.split(/\r?\n/).filter(Boolean);
                if ( ! lines.length ) { setStatus( 'CSV file is empty.', 'error' ); return; }

                // Detect header row
                var first = lines[0].toLowerCase();
                var emailCol = -1, nameCol = -1;
                if ( first.indexOf('email') !== -1 ) {
                    var headers = lines[0].split(',').map(function(h){ return h.trim().toLowerCase().replace(/^"|"$/g,''); });
                    emailCol = headers.indexOf('email');
                    nameCol  = headers.indexOf('name');
                    lines.shift();
                }

                var added = 0;
                lines.forEach(function ( line ) {
                    var cells = line.split(',').map(function(c){ return c.trim().replace(/^"|"$/g,''); });
                    var email = '', personName = '';
                    if ( emailCol !== -1 && cells[ emailCol ] ) {
                        email = cells[ emailCol ];
                        if ( nameCol !== -1 && cells[ nameCol ] ) personName = cells[ nameCol ];
                    } else {
                        // No header: take the first cell that looks like an email
                        for ( var i = 0; i < cells.length; i++ ) {
                            if ( isValidEmail( cells[i] ) ) { email = cells[i]; break; }
                        }
                    }
                    if ( email && addEmail( email, personName ) ) added++;
                });

                setStatus( 'Imported ' + added + ' email' + ( added === 1 ? '' : 's' ) + ' from CSV.', added ? 'success' : 'error' );
                csvInput.value = '';
            };
            reader.readAsText( file );
        });

        tplSaveBtn.addEventListener('click', function () {
            setStatus( 'Saving template…' );
            fetch( REST_ROOT + '/template', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
                body: JSON.stringify({ subject: subjectInput.value, body: getBody() })
            }).then(function(r){ return r.json(); }).then(function(d){
                if ( d && d.ok ) setStatus( 'Template saved.', 'success' );
                else setStatus( ( d && d.message ) || 'Save failed.', 'error' );
            }).catch(function(){ setStatus( 'Network error.', 'error' ); });
        });

        tplResetBtn.addEventListener('click', function () {
            setStatus( 'Resetting…' );
            fetch( REST_ROOT + '/template?reset=1', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
                body: JSON.stringify({ reset: true })
            }).then(function(r){ return r.json(); }).then(function(d){
                if ( d && d.ok ) {
                    subjectInput.value = d.subject || '';
                    setBody( d.body || '' );
                    setStatus( 'Reset to default.', 'success' );
                } else {
                    setStatus( 'Reset failed.', 'error' );
                }
            }).catch(function(){ setStatus( 'Network error.', 'error' ); });
        });

        sendBtn.addEventListener('click', function () {
            if ( ! staged.length ) { setStatus( 'No recipients staged.', 'error' ); return; }
            sendBtn.disabled = true;
            setStatus( 'Sending ' + staged.length + ' invite' + ( staged.length === 1 ? '' : 's' ) + '…' );
            fetch( REST_ROOT + '/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
                body: JSON.stringify({
                    recipients: staged,
                    subject: subjectInput.value,
                    body:    getBody()
                })
            }).then(function(r){ return r.json(); }).then(function(d){
                sendBtn.disabled = false;
                if ( d && d.ok ) {
                    setStatus( 'Sent ' + d.sent + ' / ' + d.total + ' invites.' + ( d.failed && d.failed.length ? ' Failed: ' + d.failed.join(', ') : '' ), d.failed && d.failed.length ? 'error' : 'success' );
                    if ( ! d.failed || ! d.failed.length ) {
                        staged = [];
                        renderList();
                    }
                } else {
                    setStatus( ( d && d.message ) || 'Send failed.', 'error' );
                }
            }).catch(function(){
                sendBtn.disabled = false;
                setStatus( 'Network error.', 'error' );
            });
        });

        // ── OAuth contacts (Google / Microsoft) ─────────────────────────
        // Click → POST to /auth-url to get the provider's authorize URL →
        // open it in a popup → wait for the callback page to postMessage
        // success → fetch contacts → stage them.
        function openOAuthPopup ( url, name ) {
            var w = 520, h = 640;
            var left = (window.screen.width  - w) / 2;
            var top  = (window.screen.height - h) / 2;
            return window.open(
                url,
                name || 'gs-invite-oauth',
                'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
            );
        }

        function fetchProviderContacts ( provider ) {
            setStatus( 'Loading ' + provider + ' contacts…' );
            return fetch( REST_ROOT + '/oauth/contacts?provider=' + encodeURIComponent( provider ), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': REST_NONCE }
            }).then(function(r){ return r.json(); }).then(function(d){
                if ( ! d || d.code ) {
                    setStatus( ( d && d.message ) || 'Could not load contacts.', 'error' );
                    return;
                }
                var contacts = d.contacts || [];
                if ( ! contacts.length ) {
                    setStatus( 'No contacts found in your ' + provider + ' account.', 'error' );
                    return;
                }
                openContactPicker( provider, contacts );
            }).catch(function(){ setStatus( 'Network error fetching contacts.', 'error' ); });
        }

        // Renders a modal that lets the user pick which contacts to stage.
        // Lazy-initialized — built once on first use, then reused.
        var pickerEl = null;
        function ensurePicker () {
            if ( pickerEl ) return pickerEl;
            pickerEl = document.createElement('div');
            pickerEl.className = 'gs-invite-picker';
            pickerEl.innerHTML =
                '<div class="gs-invite-picker__backdrop" data-pk-close></div>' +
                '<div class="gs-invite-picker__modal" role="dialog" aria-modal="true" aria-labelledby="gs-pk-title">'
                + '<div class="gs-invite-picker__head">'
                +   '<h3 id="gs-pk-title">Select contacts to invite</h3>'
                +   '<button type="button" class="gs-invite-picker__x" data-pk-close aria-label="Close">&times;</button>'
                + '</div>'
                + '<div class="gs-invite-picker__toolbar">'
                +   '<input type="search" class="gs-invite-picker__search" data-pk-search placeholder="Search by name or email…" />'
                +   '<div class="gs-invite-picker__bulk">'
                +     '<button type="button" data-pk-all class="gs-invite-link-btn">Select all</button>'
                +     '<button type="button" data-pk-none class="gs-invite-link-btn">None</button>'
                +     '<span class="gs-invite-picker__count" data-pk-count></span>'
                +   '</div>'
                + '</div>'
                + '<div class="gs-invite-picker__list" data-pk-list></div>'
                + '<div class="gs-invite-picker__footer">'
                +   '<button type="button" class="gs-invite-btn gs-invite-btn--ghost" data-pk-close>Cancel</button>'
                +   '<button type="button" class="gs-invite-btn gs-invite-btn--primary" data-pk-add>Add Selected</button>'
                + '</div>'
                + '</div>';
            document.body.appendChild( pickerEl );

            // Close handlers (backdrop click, X button, Cancel)
            pickerEl.querySelectorAll('[data-pk-close]').forEach(function ( el ) {
                el.addEventListener('click', function () { pickerEl.classList.remove('is-open'); });
            });
            return pickerEl;
        }

        function openContactPicker ( provider, contacts ) {
            var p = ensurePicker();
            var listEl   = p.querySelector('[data-pk-list]');
            var searchEl = p.querySelector('[data-pk-search]');
            var countEl  = p.querySelector('[data-pk-count]');
            var titleEl  = p.querySelector('#gs-pk-title');
            var addBtn   = p.querySelector('[data-pk-add]');
            var allBtn   = p.querySelector('[data-pk-all]');
            var noneBtn  = p.querySelector('[data-pk-none]');

            titleEl.textContent = 'Select ' + provider + ' contacts to invite';

            // De-dupe by email, sort by name (then email).
            var seen = {};
            var rows = [];
            contacts.forEach(function ( c ) {
                var key = String( c.email || '' ).toLowerCase().trim();
                if ( ! key || seen[ key ] ) return;
                seen[ key ] = true;
                rows.push({ email: key, name: String( c.name || '' ).trim() });
            });
            rows.sort(function ( a, b ) {
                var an = ( a.name || a.email ).toLowerCase();
                var bn = ( b.name || b.email ).toLowerCase();
                return an < bn ? -1 : an > bn ? 1 : 0;
            });

            // Render rows
            listEl.innerHTML = '';
            rows.forEach(function ( r, idx ) {
                var row = document.createElement('label');
                row.className = 'gs-invite-picker__row';
                row.innerHTML =
                    '<input type="checkbox" data-pk-row="' + idx + '" />' +
                    '<span class="gs-invite-picker__row-name"></span>' +
                    '<span class="gs-invite-picker__row-email"></span>';
                row.querySelector('.gs-invite-picker__row-name').textContent  = r.name || r.email;
                row.querySelector('.gs-invite-picker__row-email').textContent = r.name ? r.email : '';
                row.dataset.search = ( r.name + ' ' + r.email ).toLowerCase();
                listEl.appendChild( row );
            });

            function updateCount () {
                var sel = listEl.querySelectorAll('input[type="checkbox"]:checked').length;
                countEl.textContent = sel + ' of ' + rows.length + ' selected';
            }

            // Toolbar wiring (rebound each open since we replace contents)
            searchEl.value = '';
            searchEl.oninput = function () {
                var q = searchEl.value.toLowerCase().trim();
                listEl.querySelectorAll('.gs-invite-picker__row').forEach(function ( el ) {
                    el.style.display = ( ! q || el.dataset.search.indexOf( q ) !== -1 ) ? '' : 'none';
                });
            };
            allBtn.onclick = function () {
                listEl.querySelectorAll('.gs-invite-picker__row').forEach(function ( el ) {
                    if ( el.style.display === 'none' ) return;
                    el.querySelector('input[type="checkbox"]').checked = true;
                });
                updateCount();
            };
            noneBtn.onclick = function () {
                listEl.querySelectorAll('input[type="checkbox"]').forEach(function ( c ) { c.checked = false; });
                updateCount();
            };
            listEl.onchange = updateCount;
            addBtn.onclick = function () {
                var added = 0;
                listEl.querySelectorAll('input[type="checkbox"]:checked').forEach(function ( c ) {
                    var idx = parseInt( c.getAttribute('data-pk-row'), 10 );
                    var r = rows[ idx ];
                    if ( r && addEmail( r.email, r.name ) ) added++;
                });
                p.classList.remove('is-open');
                setStatus( 'Added ' + added + ' contact' + ( added === 1 ? '' : 's' ) + ' from ' + provider + '.', added ? 'success' : 'error' );
            };

            updateCount();
            p.classList.add('is-open');
            setStatus( '' );
        }

        // Single message listener — provider callback page postMessages us
        // back when auth completes (success or failure).
        var pendingProvider = null;
        window.addEventListener('message', function ( ev ) {
            if ( ev.origin !== window.location.origin ) return;
            var data = ev.data;
            if ( ! data || data.gs_invite_oauth !== true ) return;
            if ( data.success ) {
                fetchProviderContacts( data.provider || pendingProvider );
            } else {
                setStatus( data.message || 'OAuth connection failed.', 'error' );
            }
            pendingProvider = null;
        });

        wrap.querySelectorAll('[data-gs-oauth]').forEach(function ( btn ) {
            btn.addEventListener('click', function () {
                var provider = btn.getAttribute('data-gs-oauth');
                pendingProvider = provider;
                setStatus( 'Opening ' + provider + ' authorization…' );

                // CRITICAL: open the popup SYNCHRONOUSLY in this click handler
                // so the user-gesture token is captured. Browsers (Safari,
                // Firefox, recent Chrome) silently block window.open() calls
                // made later from inside fetch().then() callbacks. We open it
                // pointed at about:blank, then navigate it once the auth URL
                // arrives.
                var popup = openOAuthPopup( 'about:blank', 'gs-invite-' + provider );
                if ( ! popup ) {
                    setStatus( 'Popup blocked — please allow popups for this site and try again.', 'error' );
                    return;
                }

                // Status check first — if already connected, close the popup
                // and fetch contacts directly.
                fetch( REST_ROOT + '/oauth/status?provider=' + encodeURIComponent( provider ), {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': REST_NONCE }
                }).then(function(r){ return r.json(); }).then(function(d){
                    if ( d && d.connected ) {
                        try { popup.close(); } catch (e) {}
                        return fetchProviderContacts( provider );
                    }
                    return fetch( REST_ROOT + '/oauth/auth-url?provider=' + encodeURIComponent( provider ), {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': REST_NONCE }
                    }).then(function(r){ return r.json(); }).then(function(d2){
                        if ( ! d2 || ! d2.url ) {
                            try { popup.close(); } catch (e) {}
                            setStatus( ( d2 && d2.message ) || 'Provider not configured. An admin needs to add OAuth credentials in Social → Invite Settings.', 'error' );
                            return;
                        }
                        // Popup already open; just navigate it.
                        try { popup.location.href = d2.url; } catch (e) {
                            // Cross-origin assignment can throw on some browsers;
                            // fall back to opening a new window with the real URL.
                            openOAuthPopup( d2.url, 'gs-invite-' + provider );
                        }
                    });
                }).catch(function(){
                    try { popup.close(); } catch (e) {}
                    setStatus( 'Network error reaching the server.', 'error' );
                });
            });
        });

        // ── Apple vCard upload ──────────────────────────────────────────
        var vcfInput = wrap.querySelector('[data-gs-vcf]');
        if ( vcfInput ) {
            vcfInput.addEventListener('change', function () {
                var file = vcfInput.files && vcfInput.files[0];
                if ( ! file ) return;
                var reader = new FileReader();
                reader.onload = function ( ev ) {
                    var text = String( ev.target.result || '' );
                    // Split into individual VCARDs. vCard property values can
                    // be folded onto the next line if it starts with a space —
                    // unfold first.
                    text = text.replace( /\r\n[ \t]/g, '' ).replace( /\n[ \t]/g, '' );
                    var cards = text.split( /BEGIN:VCARD/i ).slice( 1 );
                    var added = 0;
                    cards.forEach(function ( card ) {
                        var endIdx = card.search( /END:VCARD/i );
                        if ( endIdx !== -1 ) card = card.slice( 0, endIdx );

                        var fnMatch    = card.match( /(?:^|\r?\n)FN[^:]*:([^\r\n]+)/i );
                        var emailMatch = card.match( /(?:^|\r?\n)EMAIL[^:]*:([^\r\n]+)/gi );
                        if ( ! emailMatch ) return;

                        var name = fnMatch ? String( fnMatch[1] ).trim() : '';
                        emailMatch.forEach(function ( raw ) {
                            var v = raw.split( ':' ).slice( 1 ).join( ':' ).trim();
                            // Some entries are URI-style "mailto:foo@bar"
                            v = v.replace( /^mailto:/i, '' );
                            if ( v && addEmail( v, name ) ) added++;
                        });
                    });
                    setStatus( 'Imported ' + added + ' contact' + ( added === 1 ? '' : 's' ) + ' from vCard.', added ? 'success' : 'error' );
                    vcfInput.value = '';
                };
                reader.readAsText( file );
            });
        }

        // ── Send test email ─────────────────────────────────────────────
        if ( testSendBtn ) {
            testSendBtn.addEventListener('click', function () {
                var to = ( testEmail.value || '' ).trim();
                if ( ! isValidEmail( to ) ) {
                    setStatus( 'Enter a valid test email address.', 'error' );
                    return;
                }
                testSendBtn.disabled = true;
                setStatus( 'Sending test to ' + to + '…' );
                fetch( REST_ROOT + '/send-test', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
                    body: JSON.stringify({ email: to, subject: subjectInput.value, body: getBody() })
                }).then(function(r){ return r.json(); }).then(function(d){
                    testSendBtn.disabled = false;
                    setStatus( ( d && d.ok ) ? ( 'Test sent to ' + to + '.' ) : ( ( d && d.message ) || 'Test send failed.' ), ( d && d.ok ) ? 'success' : 'error' );
                }).catch(function(){
                    testSendBtn.disabled = false;
                    setStatus( 'Network error.', 'error' );
                });
            });
        }

        // ── Sent-invitations list ──────────────────────────────────────
        function fmtDate ( ts ) {
            if ( ! ts ) return '—';
            try { return new Date( ts * 1000 ).toLocaleString(); } catch ( e ) { return String( ts ); }
        }
        function escapeHTML ( s ) {
            return String( s == null ? '' : s ).replace(/[&<>"]/g, function(c){
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c];
            });
        }
        function renderSent ( entries ) {
            if ( ! entries.length ) {
                sentBody.innerHTML = '<p class="gs-invite-empty">No invitations sent yet.</p>';
                return;
            }
            // Newest first
            entries.sort(function ( a, b ) { return ( b.sent_at || 0 ) - ( a.sent_at || 0 ); });
            var html = entries.map(function ( e ) {
                var status = 'sent';
                if ( e.registered_at ) status = 'registered';
                else if ( e.opened_at ) status = 'opened';
                var label = status === 'registered' ? 'Registered' : status === 'opened' ? 'Opened' : 'Sent';
                var canRemind = ! e.registered_at;
                return '<div class="gs-invite-sent-row">'
                    + '<div><strong>' + escapeHTML( e.name || e.email ) + '</strong>' + ( e.name ? '<br><span class="gs-invite-sent-meta">' + escapeHTML( e.email ) + '</span>' : '' ) + '</div>'
                    + '<div class="gs-invite-sent-meta"><strong>Sent</strong><br>' + escapeHTML( fmtDate( e.sent_at ) ) + '</div>'
                    + '<div class="gs-invite-sent-meta"><strong>' + ( e.registered_at ? 'Registered' : e.opened_at ? 'Opened' : '—' ) + '</strong><br>'
                    +   escapeHTML( fmtDate( e.registered_at || e.opened_at ) ) + '</div>'
                    + '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">'
                    +   '<span class="gs-invite-sent-pill is-' + status + '">' + label + '</span>'
                    +   ( canRemind ? '<button type="button" class="gs-invite-link-btn" data-gs-remind="' + escapeHTML( e.id ) + '">Send Reminder</button>' : '' )
                    + '</div>'
                    + '</div>';
            }).join('');
            sentBody.innerHTML = html;
            // Wire reminder buttons
            sentBody.querySelectorAll('[data-gs-remind]').forEach(function ( btn ) {
                btn.addEventListener('click', function () {
                    var id = btn.getAttribute('data-gs-remind');
                    var entry = entries.find(function ( e ) { return e.id === id; });
                    if ( entry ) openReminder( entry );
                });
            });
        }
        function refreshSent () {
            sentBody.innerHTML = '<p class="gs-invite-empty">Loading…</p>';
            fetch( REST_ROOT + '/log', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': REST_NONCE }
            }).then(function(r){ return r.json(); }).then(function(d){
                if ( d && d.ok ) renderSent( d.entries || [] );
                else sentBody.innerHTML = '<p class="gs-invite-empty">Could not load sent log.</p>';
            }).catch(function(){
                sentBody.innerHTML = '<p class="gs-invite-empty">Network error.</p>';
            });
        }
        if ( sentRefresh ) sentRefresh.addEventListener('click', refreshSent);
        refreshSent();

        // ── Reminder popup ─────────────────────────────────────────────
        var rmModal = wrap.querySelector('[data-gs-reminder]');
        var rmTo, rmSubj, rmBody, rmTitle, rmStatus, rmSendBtn;
        function openReminder ( entry ) {
            if ( ! rmModal ) return;
            rmTo     = rmTo     || rmModal.querySelector('[data-rm-to]');
            rmSubj   = rmSubj   || rmModal.querySelector('[data-rm-subject]');
            rmBody   = rmBody   || rmModal.querySelector('[data-rm-body]');
            rmTitle  = rmTitle  || rmModal.querySelector('[data-rm-title]');
            rmStatus = rmStatus || rmModal.querySelector('[data-rm-status]');
            rmSendBtn= rmSendBtn|| rmModal.querySelector('[data-rm-send]');

            rmTitle.textContent = 'Send Reminder to ' + ( entry.name || entry.email );
            rmTo.value   = entry.email;
            rmSubj.value = 'Reminder: ' + ( subjectInput.value || 'Join me on gend.me' );
            // Pre-fill with current body — sender can edit per-recipient.
            rmBody.value = getBody();
            rmStatus.textContent = '';
            rmStatus.className = 'gs-invite-status';
            rmModal.removeAttribute('hidden');
        }
        if ( rmModal ) {
            rmModal.querySelectorAll('[data-rm-close]').forEach(function ( el ) {
                el.addEventListener('click', function () { rmModal.setAttribute('hidden', ''); });
            });
            rmModal.querySelector('[data-rm-send]').addEventListener('click', function () {
                var to = ( rmTo.value || '' ).trim();
                rmSendBtn.disabled = true;
                rmStatus.textContent = 'Sending…';
                rmStatus.className = 'gs-invite-status';
                fetch( REST_ROOT + '/remind', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
                    body: JSON.stringify({ email: to, subject: rmSubj.value, body: rmBody.value })
                }).then(function(r){ return r.json(); }).then(function(d){
                    rmSendBtn.disabled = false;
                    if ( d && d.ok ) {
                        rmStatus.textContent = 'Reminder sent.';
                        rmStatus.className = 'gs-invite-status is-success';
                        refreshSent();
                        setTimeout(function(){ rmModal.setAttribute('hidden', ''); }, 1500);
                    } else {
                        rmStatus.textContent = ( d && d.message ) || 'Send failed.';
                        rmStatus.className = 'gs-invite-status is-error';
                    }
                }).catch(function(){
                    rmSendBtn.disabled = false;
                    rmStatus.textContent = 'Network error.';
                    rmStatus.className = 'gs-invite-status is-error';
                });
            });
        }
        } // end init
        // Run init now if DOM is ready, otherwise wait for it. If the wrap
        // got moved by a downstream script we still bind to the right one.
        if ( document.readyState === 'loading' ) {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
    </script>
    <?php
}

/**
 * REST routes — template save/get + send invites.
 */
add_action( 'rest_api_init', 'gs_invite_register_routes' );
function gs_invite_register_routes() {
    $auth = function () {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'gs_invite_auth', __( 'Login required.', 'gend-society' ), array( 'status' => 401 ) );
    };

    register_rest_route( 'gs/v1', '/invite/template', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'gs_invite_rest_get_template',
            'permission_callback' => $auth,
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'gs_invite_rest_save_template',
            'permission_callback' => $auth,
        ),
    ) );

    register_rest_route( 'gs/v1', '/invite/send', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'gs_invite_rest_send',
        'permission_callback' => $auth,
    ) );

    register_rest_route( 'gs/v1', '/invite/send-test', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'gs_invite_rest_send_test',
        'permission_callback' => $auth,
    ) );

    register_rest_route( 'gs/v1', '/invite/log', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'gs_invite_rest_log',
        'permission_callback' => $auth,
    ) );

    register_rest_route( 'gs/v1', '/invite/remind', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'gs_invite_rest_remind',
        'permission_callback' => $auth,
    ) );

    // Tracking pixel — anyone can hit this; the HMAC token validates it.
    register_rest_route( 'gs/v1', '/invite/open', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'gs_invite_rest_open',
        'permission_callback' => '__return_true',
    ) );
}

function gs_invite_rest_get_template( WP_REST_Request $req ) {
    $tpl = gs_invite_get_template( get_current_user_id() );
    return rest_ensure_response( array( 'ok' => true ) + $tpl );
}

function gs_invite_rest_save_template( WP_REST_Request $req ) {
    $user_id = get_current_user_id();
    $params  = $req->get_json_params();

    if ( ! empty( $params['reset'] ) ) {
        delete_user_meta( $user_id, 'gs_invite_template' );
        $tpl = gs_invite_default_template();
        return rest_ensure_response( array( 'ok' => true ) + $tpl );
    }

    $subject = isset( $params['subject'] ) ? trim( wp_unslash( $params['subject'] ) ) : '';
    $body    = isset( $params['body'] )    ? trim( wp_unslash( $params['body'] ) )    : '';
    if ( $subject === '' || $body === '' ) {
        return new WP_Error( 'gs_invite_template', __( 'Subject and body are required.', 'gend-society' ), array( 'status' => 400 ) );
    }
    update_user_meta( $user_id, 'gs_invite_template', array(
        'subject' => $subject,
        'body'    => $body,
    ) );
    return rest_ensure_response( array( 'ok' => true, 'subject' => $subject, 'body' => $body ) );
}

/**
 * Send a single invite email, append a log entry with tracking metadata,
 * and update the pending-email index. Returns array { ok, log_id }.
 */
function gs_invite_send_one( $sender, $email, $name, $subject_tpl, $body_tpl, $skip_member_check = false ) {
    $email = sanitize_email( $email );
    if ( ! $email || ! is_email( $email ) ) return array( 'ok' => false, 'reason' => 'invalid' );
    if ( ! $skip_member_check && email_exists( $email ) ) return array( 'ok' => false, 'reason' => 'exists' );

    $log_id      = wp_generate_password( 12, false );
    $invite_link = gs_invite_get_affiliate_url( $sender->ID );
    $vars = array(
        'name'         => $name,
        'sender_name'  => $sender->display_name,
        'sender_email' => $sender->user_email,
        'invite_link'  => $invite_link,
    );
    $subject = gs_invite_render_template( $subject_tpl, $vars );
    $body    = gs_invite_render_template( $body_tpl,    $vars );
    $body    = gs_invite_inject_tracking( $body, $sender->ID, $log_id );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . $sender->display_name . ' <' . $sender->user_email . '>',
    );

    $ok = wp_mail( $email, $subject, $body, $headers );
    if ( ! $ok ) return array( 'ok' => false, 'reason' => 'send_failed' );

    // Persist log entry on the sender. Skip logging for "test" sends to the
    // sender's own address — we don't want our own test mail in the history.
    if ( ! $skip_member_check ) {
        $log = get_user_meta( $sender->ID, 'gs_invite_log', true );
        if ( ! is_array( $log ) ) $log = array();
        $log[] = array(
            'id'             => $log_id,
            'email'          => $email,
            'name'           => $name,
            'sent_at'        => time(),
            'opened_at'      => 0,
            'registered_at'  => 0,
            'reminders'      => 0,
        );
        if ( count( $log ) > 500 ) $log = array_slice( $log, -500 );
        update_user_meta( $sender->ID, 'gs_invite_log', $log );
        gs_invite_index_add( $email, $sender->ID, $log_id );
    }

    return array( 'ok' => true, 'log_id' => $log_id );
}

function gs_invite_rest_send( WP_REST_Request $req ) {
    $sender   = wp_get_current_user();
    $params   = $req->get_json_params();

    $recipients = isset( $params['recipients'] ) && is_array( $params['recipients'] ) ? $params['recipients'] : array();
    if ( empty( $recipients ) ) {
        return new WP_Error( 'gs_invite_recipients', __( 'No recipients provided.', 'gend-society' ), array( 'status' => 400 ) );
    }

    $subject_tpl = isset( $params['subject'] ) ? wp_unslash( $params['subject'] ) : '';
    $body_tpl    = isset( $params['body'] )    ? wp_unslash( $params['body'] )    : '';
    if ( ! $subject_tpl || ! $body_tpl ) {
        $tpl         = gs_invite_get_template( $sender->ID );
        $subject_tpl = $subject_tpl ?: $tpl['subject'];
        $body_tpl    = $body_tpl    ?: $tpl['body'];
    }

    $sent = 0; $failed = array(); $skipped = array();
    $cap = (int) apply_filters( 'gs_invite_max_per_batch', 100 );
    $recipients = array_slice( $recipients, 0, $cap );

    foreach ( $recipients as $row ) {
        $email = isset( $row['email'] ) ? $row['email'] : '';
        $name  = isset( $row['name'] )  ? sanitize_text_field( $row['name'] ) : '';
        $r = gs_invite_send_one( $sender, $email, $name, $subject_tpl, $body_tpl );
        if ( $r['ok'] ) $sent++;
        else if ( $r['reason'] === 'exists' || $r['reason'] === 'invalid' ) $skipped[] = $email;
        else $failed[] = $email;
    }

    return rest_ensure_response( array(
        'ok'      => true,
        'sent'    => $sent,
        'failed'  => $failed,
        'skipped' => $skipped,
        'total'   => count( $recipients ),
    ) );
}

function gs_invite_rest_send_test( WP_REST_Request $req ) {
    $sender = wp_get_current_user();
    $params = $req->get_json_params();
    $email  = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
    if ( ! $email || ! is_email( $email ) ) {
        return new WP_Error( 'gs_invite_test', __( 'Invalid test email.', 'gend-society' ), array( 'status' => 400 ) );
    }
    $subject_tpl = isset( $params['subject'] ) ? wp_unslash( $params['subject'] ) : '';
    $body_tpl    = isset( $params['body'] )    ? wp_unslash( $params['body'] )    : '';
    if ( ! $subject_tpl || ! $body_tpl ) {
        $tpl         = gs_invite_get_template( $sender->ID );
        $subject_tpl = $subject_tpl ?: $tpl['subject'];
        $body_tpl    = $body_tpl    ?: $tpl['body'];
    }
    // Test sends bypass the email_exists check (so you can test against your
    // own address) and don't write to the log.
    $r = gs_invite_send_one( $sender, $email, '', $subject_tpl, $body_tpl, true );
    if ( ! $r['ok'] ) {
        return new WP_Error( 'gs_invite_test_send', __( 'Test send failed.', 'gend-society' ), array( 'status' => 500 ) );
    }
    return rest_ensure_response( array( 'ok' => true ) );
}

function gs_invite_rest_log( WP_REST_Request $req ) {
    $log = get_user_meta( get_current_user_id(), 'gs_invite_log', true );
    if ( ! is_array( $log ) ) $log = array();
    // Backfill missing fields on legacy entries that only had {email,name,ts}.
    $log = array_map( function ( $e ) {
        if ( ! isset( $e['id'] ) )            $e['id']            = isset( $e['ts'] ) ? md5( ($e['email'] ?? '') . $e['ts'] ) : wp_generate_password( 8, false );
        if ( ! isset( $e['sent_at'] ) )       $e['sent_at']       = isset( $e['ts'] ) ? (int) $e['ts'] : 0;
        if ( ! isset( $e['opened_at'] ) )     $e['opened_at']     = 0;
        if ( ! isset( $e['registered_at'] ) ) $e['registered_at'] = 0;
        if ( ! isset( $e['reminders'] ) )     $e['reminders']     = 0;
        return $e;
    }, $log );
    return rest_ensure_response( array( 'ok' => true, 'entries' => array_values( $log ) ) );
}

function gs_invite_rest_remind( WP_REST_Request $req ) {
    $sender = wp_get_current_user();
    $params = $req->get_json_params();
    $email  = isset( $params['email'] )   ? sanitize_email( $params['email'] ) : '';
    $subject_tpl = isset( $params['subject'] ) ? wp_unslash( $params['subject'] ) : '';
    $body_tpl    = isset( $params['body'] )    ? wp_unslash( $params['body'] )    : '';
    if ( ! $email || ! is_email( $email ) ) {
        return new WP_Error( 'gs_invite_remind', __( 'Invalid email.', 'gend-society' ), array( 'status' => 400 ) );
    }
    if ( email_exists( $email ) ) {
        return new WP_Error( 'gs_invite_remind_exists', __( 'That user is already a member.', 'gend-society' ), array( 'status' => 400 ) );
    }
    $r = gs_invite_send_one( $sender, $email, '', $subject_tpl, $body_tpl );
    if ( ! $r['ok'] ) {
        return new WP_Error( 'gs_invite_remind_send', __( 'Reminder send failed.', 'gend-society' ), array( 'status' => 500 ) );
    }
    // Increment reminder count on the most recent matching log entry.
    $log = get_user_meta( $sender->ID, 'gs_invite_log', true );
    if ( is_array( $log ) ) {
        for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
            if ( isset( $log[ $i ]['email'] ) && strtolower( $log[ $i ]['email'] ) === strtolower( $email ) ) {
                $log[ $i ]['reminders'] = (int) ( $log[ $i ]['reminders'] ?? 0 ) + 1;
                $log[ $i ]['last_reminded_at'] = time();
                break;
            }
        }
        update_user_meta( $sender->ID, 'gs_invite_log', $log );
    }
    return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Tracking pixel — recipient's email client requests this when images load.
 * We mark the corresponding log entry as opened (only the first time, so
 * later reloads don't churn user meta) and return a 1×1 transparent GIF.
 */
function gs_invite_rest_open( WP_REST_Request $req ) {
    $token = (string) $req->get_param( 't' );
    $data  = gs_invite_verify_token( $token );
    if ( $data && ! empty( $data['u'] ) && ! empty( $data['l'] ) ) {
        $sender_id = (int) $data['u'];
        $log_id    = (string) $data['l'];
        $log = get_user_meta( $sender_id, 'gs_invite_log', true );
        if ( is_array( $log ) ) {
            $changed = false;
            foreach ( $log as &$entry ) {
                if ( isset( $entry['id'] ) && $entry['id'] === $log_id && empty( $entry['opened_at'] ) ) {
                    $entry['opened_at'] = time();
                    $changed = true;
                    break;
                }
            }
            unset( $entry );
            if ( $changed ) update_user_meta( $sender_id, 'gs_invite_log', $log );
        }
    }
    // Return a 1x1 transparent GIF (43 bytes) regardless of token validity
    // so we don't reveal anything to a probe.
    $gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
    nocache_headers();
    header( 'Content-Type: image/gif' );
    header( 'Content-Length: ' . strlen( $gif ) );
    echo $gif;
    exit;
}
