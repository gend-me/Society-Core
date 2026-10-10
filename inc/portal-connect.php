<?php
/**
 * GenD Society: Connect this WordPress install to a gend.me portal.
 *
 * Pairing-code handshake. Stores the resulting install identity in
 * site options so the Society plugin can:
 *   - sign requests TO gend.me with our private key
 *   - verify requests FROM gend.me with the stored gend pubkey
 *   - read feature gates using the long-lived install token
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

// Site options used by the connect flow + downstream features.
//
//   gs_install_id           string  uuid assigned by us, sent to gend.me at /connect
//   gs_install_token        string  long-lived bearer token returned by gend.me
//   gs_gend_base_url        string  gend.me URL the customer paired against
//   gs_gend_pubkey          string  base64 Ed25519 pubkey for verifying inbound signatures
//   gs_keypair              string  base64 (concat) Ed25519 keypair for signing outbound
//   gs_connected_at         int     timestamp
//
// All under_score keys for grep-ability.

add_action('admin_menu', 'gend_society_portal_connect_register_menu', 50);
add_action('admin_post_gend_society_portal_connect_submit', 'gend_society_portal_connect_handle_submit');

function gend_society_portal_connect_register_menu() {
    // Add as a submenu under Settings since gend-society removes options-general,
    // we instead attach to the Dashboard top-level so it's findable before pairing.
    add_submenu_page(
        'index.php',
        __('Connect to gend.me', 'gend-society'),
        __('Connect to gend.me', 'gend-society'),
        'manage_options',
        'gs-portal-connect',
        'gend_society_portal_connect_render_page'
    );
}

function gend_society_portal_connect_render_page() {

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden.', 'gend-society'));
    }

    $install_id    = (string) get_option('gend_society_install_id', '');
    $install_token = (string) get_option('gend_society_install_token', '');
    $gend_base     = (string) get_option('gend_society_gend_base_url', '');
    $connected_at  = (int) get_option('gend_society_connected_at', 0);
    $is_connected  = $install_id !== '' && $install_token !== '';

    $notice_cls = '';
    $message    = '';
    if (isset($_GET['gs_connect_status'])) {
        $status = sanitize_text_field((string) $_GET['gs_connect_status']);
        $message = isset($_GET['gs_connect_message']) ? sanitize_text_field((string) $_GET['gs_connect_message']) : '';
        $notice_cls = $status === 'success' ? 'notice-success' : 'notice-error';
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Connect to gend.me', 'gend-society'); ?></h1>
        <?php if ($notice_cls !== '') : ?><div class="notice <?php echo esc_attr($notice_cls); ?>"><p><?php echo esc_html($message); ?></p></div><?php endif; ?>

        <?php if ($is_connected) : ?>
            <p><strong><?php esc_html_e('This site is connected.', 'gend-society'); ?></strong></p>
            <table class="widefat striped" style="max-width:780px;">
                <tbody>
                    <tr><th><?php esc_html_e('gend.me Portal', 'gend-society'); ?></th><td><a href="<?php echo esc_url($gend_base); ?>" target="_blank" rel="noopener"><?php echo esc_html($gend_base); ?></a></td></tr>
                    <tr><th><?php esc_html_e('Install ID', 'gend-society'); ?></th><td><code><?php echo esc_html($install_id); ?></code></td></tr>
                    <tr><th><?php esc_html_e('Connected At', 'gend-society'); ?></th><td><?php echo $connected_at ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $connected_at)) : '—'; ?></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('Disconnect', 'gend-society'); ?></h2>
            <p><?php esc_html_e('Disconnecting clears the local install identity. You will need a new pairing code from gend.me to reconnect.', 'gend-society'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gs_portal_connect_disconnect', 'gs_portal_connect_nonce'); ?>
                <input type="hidden" name="action" value="gend_society_portal_connect_submit" />
                <input type="hidden" name="op" value="disconnect" />
                <?php submit_button(__('Disconnect', 'gend-society'), 'delete', 'submit', false); ?>
            </form>

        <?php else : ?>
            <?php
            // RUN-02: disclose exactly what the Connect request sends (it matches the JSON body
            // built in gend_society_portal_connect_handle_submit()).
            $gend_society_disclosure = function_exists('gend_society_consent_disclosure_items') ? gend_society_consent_disclosure_items() : array();
            $gend_society_disclosure['pairing_code'] = __('The pairing code you paste below', 'gend-society');
            $gend_society_terms   = function_exists('gend_society_terms_url') ? gend_society_terms_url() : 'https://gend.me/terms-of-service/';
            $gend_society_privacy = function_exists('gend_society_privacy_url') ? gend_society_privacy_url() : 'https://gend.me/privacy-policy/';
            /*
             * The consent checkbox is rendered (and required by the handler) only on
             * standalone installs. Containers auto-pair through oauth-login and the hub
             * never pairs, so their form stays exactly as before Phase 106.
             */
            $gend_society_needs_consent = gend_society_portal_connect_requires_consent();
            ?>
            <p><?php esc_html_e('Pair this WordPress install to your gend.me account. From your gend.me dashboard, copy the pairing code shown for your Self-Hosted app and paste it below.', 'gend-society'); ?></p>

            <h2><?php esc_html_e('What is sent when you connect', 'gend-society'); ?></h2>
            <ul style="list-style:disc;padding-left:20px;">
                <?php foreach ($gend_society_disclosure as $gend_society_label) : ?>
                    <li><?php echo esc_html($gend_society_label); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <a href="<?php echo esc_url($gend_society_terms); ?>" target="_blank" rel="noopener"><?php esc_html_e('Terms of Service', 'gend-society'); ?></a>
                &middot;
                <a href="<?php echo esc_url($gend_society_privacy); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy Policy', 'gend-society'); ?></a>
            </p>
            <p>
                <?php esc_html_e('Prefer to look around first? Nothing is sent until you press Connect.', 'gend-society'); ?>
                <?php if (function_exists('gend_society_welcome_page_url')) : ?>
                    <a href="<?php echo esc_url(gend_society_welcome_page_url()); ?>"><?php esc_html_e('Back to the GenD page', 'gend-society'); ?></a>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gs_portal_connect_submit', 'gs_portal_connect_nonce'); ?>
                <input type="hidden" name="action" value="gend_society_portal_connect_submit" />
                <input type="hidden" name="op" value="connect" />

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th><label for="gs_gend_base_url"><?php esc_html_e('gend.me URL', 'gend-society'); ?></label></th>
                            <td>
                                <input type="url" id="gs_gend_base_url" name="gend_base_url" class="regular-text"
                                    value="<?php echo esc_attr($gend_base !== '' ? $gend_base : 'https://gend.me'); ?>"
                                    required />
                                <p class="description"><?php esc_html_e('The base URL of your gend.me portal.', 'gend-society'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gs_pairing_code"><?php esc_html_e('Pairing Code', 'gend-society'); ?></label></th>
                            <td>
                                <input type="text" id="gs_pairing_code" name="pairing_code" class="regular-text"
                                    style="font-size:1.5em;letter-spacing:0.2em;text-transform:uppercase;" maxlength="12" required />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php if ($gend_society_needs_consent) : ?>
                    <p>
                        <label for="gend_society_consent">
                            <input type="checkbox" id="gend_society_consent" name="gend_society_consent" value="1" required />
                            <?php echo esc_html(function_exists('gend_society_consent_text') ? gend_society_consent_text() : __('I agree to send the items listed above to gend.me.', 'gend-society')); ?>
                            (<a href="<?php echo esc_url($gend_society_terms); ?>" target="_blank" rel="noopener"><?php esc_html_e('Terms of Service', 'gend-society'); ?></a>,
                            <a href="<?php echo esc_url($gend_society_privacy); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy Policy', 'gend-society'); ?></a>)
                        </label>
                    </p>
                <?php endif; ?>
                <?php submit_button(__('Connect', 'gend-society')); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function gend_society_portal_connect_handle_submit() {

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden.', 'gend-society'));
    }

    $op = isset($_POST['op']) ? sanitize_text_field((string) $_POST['op']) : '';

    if ($op === 'disconnect') {
        check_admin_referer('gs_portal_connect_disconnect', 'gs_portal_connect_nonce');
        delete_option('gend_society_install_id');
        delete_option('gend_society_install_token');
        delete_option('gend_society_gend_base_url');
        delete_option('gend_society_gend_pubkey');
        delete_option('gend_society_keypair');
        delete_option('gend_society_connected_at');
        delete_option('gend_society_features_cache');
        delete_option('gend_society_features_cache_expires');
        // RUN-01: disconnecting also withdraws consent; nothing is sent until the next Connect.
        if (function_exists('gend_society_consent_clear')) {
            gend_society_consent_clear();
        }
        gend_society_portal_connect_redirect('success', __('Disconnected.', 'gend-society'));
        return;
    }

    check_admin_referer('gs_portal_connect_submit', 'gs_portal_connect_nonce');

    $gend_base    = isset($_POST['gend_base_url']) ? esc_url_raw((string) $_POST['gend_base_url']) : '';
    $pairing_code = isset($_POST['pairing_code']) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $_POST['pairing_code'])) : '';

    if ($gend_base === '' || strlen($pairing_code) < 6) {
        gend_society_portal_connect_redirect('error', __('Please provide both the gend.me URL and a pairing code.', 'gend-society'));
        return;
    }

    // RUN-01/RUN-02: on standalone the ticked box is the consent; no box, no request.
    if (gend_society_portal_connect_requires_consent()) {
        if (empty($_POST['gend_society_consent'])) {
            gend_society_portal_connect_redirect('error', __('Please confirm you agree before connecting.', 'gend-society'));
            return;
        }
        // Recorded before the first outbound request is built.
        if (function_exists('gend_society_consent_record')) {
            gend_society_consent_record('pairing-code');
        }
    }

    // Generate or reuse our own keypair so the install identity is stable across reconnects.
    $keypair_b64 = (string) get_option('gend_society_keypair', '');
    $keypair_raw = $keypair_b64 !== '' ? base64_decode($keypair_b64, true) : '';
    if (!is_string($keypair_raw) || strlen($keypair_raw) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
        $keypair_raw = sodium_crypto_sign_keypair();
        update_option('gend_society_keypair', base64_encode($keypair_raw), false);
    }
    $pubkey_b64 = base64_encode(sodium_crypto_sign_publickey($keypair_raw));

    $install_id = (string) get_option('gend_society_install_id', '');
    if ($install_id === '') {
        $install_id = wp_generate_uuid4();
    }

    $endpoint = trailingslashit($gend_base) . 'wp-json/gdc-app-manager/v1/connect';

    $response = wp_remote_post($endpoint, array(
        'timeout' => 20,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'pairing_code'   => $pairing_code,
            'install_url'    => home_url('/'),
            'install_id'     => $install_id,
            'society_pubkey' => $pubkey_b64,
            'admin_email'    => (string) get_option('admin_email'),
        )),
    ));

    if (is_wp_error($response)) {
        gend_society_portal_connect_redirect('error', sprintf(
            /* translators: %s error message */
            __('Connection failed: %s', 'gend-society'),
            $response->get_error_message()
        ));
        return;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code !== 200 || !is_array($data) || empty($data['install_token'])) {
        $msg = is_array($data) && !empty($data['message']) ? (string) $data['message'] : __('Unknown error from gend.me.', 'gend-society');
        gend_society_portal_connect_redirect('error', sprintf(
            /* translators: 1: HTTP status, 2: error message */
            __('gend.me rejected the pairing (HTTP %1$d): %2$s', 'gend-society'),
            $code,
            $msg
        ));
        return;
    }

    update_option('gend_society_install_id', $install_id, false);
    update_option('gend_society_install_token', (string) $data['install_token'], false);
    update_option('gend_society_gend_base_url', $gend_base, false);
    update_option('gend_society_gend_pubkey', isset($data['gend_signing_pubkey']) ? (string) $data['gend_signing_pubkey'] : '', false);
    update_option('gend_society_connected_at', time(), false);

    gend_society_portal_connect_redirect('success', __('Connected to gend.me.', 'gend-society'));
}

/**
 * Whether Connect needs the explicit consent checkbox (standalone installs only;
 * containers auto-pair through oauth-login and the hub never pairs).
 *
 * @return bool
 */
function gend_society_portal_connect_requires_consent() {
    return function_exists('gend_society_runtime_mode') && 'standalone' === gend_society_runtime_mode();
}

function gend_society_portal_connect_redirect($status, $message) {
    wp_safe_redirect(add_query_arg(array(
        'page'                => 'gs-portal-connect',
        'gs_connect_status'   => $status,
        'gs_connect_message'  => rawurlencode($message),
    ), admin_url('index.php')));
    exit;
}
