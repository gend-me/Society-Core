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

add_action('admin_post_gend_society_portal_connect_submit', 'gend_society_portal_connect_handle_submit');
add_action('admin_enqueue_scripts', 'gend_society_portal_connect_enqueue');
// Phase 109-30 (D29): the "Connect to gend.me" page is gone; old links (bookmarks, the gend.me /pair short link)
// redirect to the dashboard, where the Connect popup on the Integration Hub card took its place. The hook is the one
// core fires (wp-admin/includes/menu.php) right before it refuses an unregistered page, which is before admin_init.
add_action('admin_page_access_denied', 'gend_society_portal_connect_legacy_redirect', 1);
add_action('init', 'gend_society_portal_connect_modal_hooks');

function gend_society_portal_connect_handle_submit() {

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden.', 'gend-society'));
    }

    $op = isset($_POST['op']) ? sanitize_text_field(wp_unslash((string) $_POST['op'])) : '';

    // Phase 108: standalone pairing, revoke-first disconnect and ownership; hub and container keep the code below.
    if (gend_society_portal_connect_standalone_ready()) {
        gend_society_portal_connect_handle_standalone($op);
        return;
    }

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

    $gend_base    = isset($_POST['gend_base_url']) ? esc_url_raw(wp_unslash((string) $_POST['gend_base_url'])) : '';
    $pairing_code = isset($_POST['pairing_code']) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', sanitize_text_field(wp_unslash((string) $_POST['pairing_code'])))) : '';

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
    $keypair_raw = $keypair_b64 !== '' ? base64_decode($keypair_b64, true) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stored Ed25519 key material, strict decode.
    if (!is_string($keypair_raw) || strlen($keypair_raw) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
        $keypair_raw = sodium_crypto_sign_keypair();
        update_option('gend_society_keypair', base64_encode($keypair_raw), false); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stores the binary Ed25519 keypair as text.
    }
    $pubkey_b64 = base64_encode(sodium_crypto_sign_publickey($keypair_raw)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key transport.

    $install_id = (string) get_option('gend_society_install_id', '');
    if ($install_id === '') {
        $install_id = wp_generate_uuid4();
    }

    $endpoint = trailingslashit($gend_base) . 'wp-json/gdc-app-manager/v1/connect';

    $connect_body = array(
        'pairing_code'   => $pairing_code,
        'install_url'    => home_url('/'),
        'install_id'     => $install_id,
        'society_pubkey' => $pubkey_b64,
    );
    // RUN-02: the standalone Connect screen discloses the admin email, so only that
    // request carries it; container/hub requests stay exactly as before Phase 106.
    if (gend_society_portal_connect_requires_consent()) {
        $connect_body['admin_email'] = (string) get_option('admin_email');
    }

    $response = wp_remote_post($endpoint, array(
        'timeout' => 20,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($connect_body),
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

/*
 * ---------------------------------------------------------------------------
 * Phase 108 (standalone installs only): Connect card (redirect connect with a
 * pairing-code fallback), Connected card, revoke-first Disconnect and
 * connection ownership. Everything below runs only when
 * gend_society_portal_connect_standalone_ready() is true, so the hub and
 * gend.me containers keep the 1.3.1 screen and handler byte for byte.
 * ---------------------------------------------------------------------------
 */

if (!function_exists('gend_society_portal_connect_standalone_ready')) {
	/**
	 * True on standalone installs with the connect-flow module loaded.
	 *
	 * @return bool
	 */
	function gend_society_portal_connect_standalone_ready() {
		return gend_society_portal_connect_requires_consent()
			&& function_exists('gend_society_connect_identity')
			&& function_exists('gend_society_connect_store')
			&& function_exists('gend_society_connect_abort')
			&& function_exists('gend_society_connect_pairing_prepare');
	}
}

if (!function_exists('gend_society_portal_connect_enqueue')) {
	/**
	 * Connect popup assets (local files, no inline script or style), only on the dashboard of a standalone
	 * install and only for administrators (who get the popup).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	function gend_society_portal_connect_enqueue($hook_suffix) {
		if ('index.php' !== $hook_suffix || !gend_society_portal_connect_standalone_ready() || !current_user_can('manage_options')) {
			return;
		}
		$dir = defined('GEND_SOCIETY_DIR') ? GEND_SOCIETY_DIR : plugin_dir_path(dirname(__DIR__) . '/gend-society.php');
		$url = defined('GEND_SOCIETY_URL') ? GEND_SOCIETY_URL : plugin_dir_url(dirname(__DIR__) . '/gend-society.php');
		$ver = defined('GEND_SOCIETY_VERSION') ? GEND_SOCIETY_VERSION : '1';
		$css = 'assets/gs-connect-modal.css';
		$js  = 'assets/gs-connect-modal.js';
		$css_ver = file_exists($dir . $css) ? $ver . '.' . filemtime($dir . $css) : $ver . '.0';
		$js_ver  = file_exists($dir . $js) ? $ver . '.' . filemtime($dir . $js) : $ver . '.0';
		wp_enqueue_style('gend-society-connect-modal', $url . $css, array(), $css_ver);
		wp_enqueue_script('gend-society-connect-modal', $url . $js, array(), $js_ver, true);
	}
}

if (!function_exists('gend_society_portal_connect_connection')) {
	/**
	 * The stored connection details (empty array for installs connected before 1.4.0).
	 *
	 * @return array
	 */
	function gend_society_portal_connect_connection() {
		$connection = get_option('gend_society_connection', array());
		return is_array($connection) ? $connection : array();
	}
}

if (!function_exists('gend_society_portal_connect_owner_id')) {
	/**
	 * The local connection owner (0 = not set).
	 *
	 * @return int
	 */
	function gend_society_portal_connect_owner_id() {
		$connection = gend_society_portal_connect_connection();
		return isset($connection['owner_user_id']) ? (int) $connection['owner_user_id'] : 0;
	}
}

if (!function_exists('gend_society_portal_connect_owner_valid')) {
	/**
	 * True when an owner is set and that user can still manage this site.
	 *
	 * @return bool
	 */
	function gend_society_portal_connect_owner_valid() {
		$owner = gend_society_portal_connect_owner_id();
		return $owner > 0 && false !== get_userdata($owner) && user_can($owner, 'manage_options');
	}
}

if (!function_exists('gend_society_portal_connect_can_manage_owner')) {
	/**
	 * Who may transfer (or claim) ownership: the owner, or any administrator
	 * when no owner is set or the owner can no longer manage the site.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	function gend_society_portal_connect_can_manage_owner($user_id) {
		$user_id = (int) $user_id;
		if ($user_id <= 0 || !user_can($user_id, 'manage_options')) {
			return false;
		}
		if (!gend_society_portal_connect_owner_valid()) {
			return true;
		}
		return gend_society_portal_connect_owner_id() === $user_id;
	}
}

if (!function_exists('gend_society_portal_connect_owner_candidates')) {
	/**
	 * Users who can manage this site, except the current owner.
	 *
	 * @return WP_User[]
	 */
	function gend_society_portal_connect_owner_candidates() {
		$owner = gend_society_portal_connect_owner_id();
		$users = get_users(
			array(
				'capability' => 'manage_options',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'number'     => 200,
			)
		);
		$out = array();
		foreach ((array) $users as $user) {
			if ($user instanceof WP_User && (int) $user->ID !== $owner && user_can($user, 'manage_options')) {
				$out[] = $user;
			}
		}
		return $out;
	}
}

if (!function_exists('gend_society_portal_connect_hub_base')) {
	/**
	 * The gend.me address this install holds its token for (no trailing slash).
	 *
	 * @return string
	 */
	function gend_society_portal_connect_hub_base() {
		$base = (string) get_option('gend_society_gend_base_url', '');
		if ('' === $base) {
			$base = gend_society_connect_hub();
		}
		return rtrim($base, '/');
	}
}

if (!function_exists('gend_society_portal_connect_install_endpoint')) {
	/**
	 * URL of a hub route for this install: {hub}/wp-json/gdc-app-manager/v1/install/{id}/{route}.
	 *
	 * @param string $route Route (disconnect, owner).
	 * @return string
	 */
	function gend_society_portal_connect_install_endpoint($route) {
		$install_id = (string) get_option('gend_society_install_id', '');
		return gend_society_portal_connect_hub_base() . '/wp-json/gdc-app-manager/v1/install/' . rawurlencode($install_id) . '/' . $route;
	}
}

if (!function_exists('gend_society_portal_connect_hub_post')) {
	/**
	 * POST to an install route with the install token.
	 *
	 * @param string $route Route.
	 * @param array  $body  JSON body.
	 * @return array{status:int, data:mixed, error:string} status 0 on a network error.
	 */
	function gend_society_portal_connect_hub_post($route, array $body = array()) {
		$response = wp_remote_post(
			gend_society_portal_connect_install_endpoint($route),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . (string) get_option('gend_society_install_token', ''),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => $body ? wp_json_encode($body) : '{}',
			)
		);
		if (is_wp_error($response)) {
			return array(
				'status' => 0,
				'data'   => null,
				'error'  => sanitize_text_field($response->get_error_message()),
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code($response),
			'data'   => json_decode((string) wp_remote_retrieve_body($response), true),
			'error'  => '',
		);
	}
}

if (!function_exists('gend_society_portal_connect_forget_local')) {
	/**
	 * Remove the connection from this site: token, hub address and key,
	 * connection details, health and cached hub answers, then the consent
	 * record. The install ID and keypair stay, so a later reconnect
	 * reactivates the same record on gend.me.
	 */
	function gend_society_portal_connect_forget_local() {
		foreach (array(
			'gend_society_install_token',
			'gend_society_gend_base_url',
			'gend_society_gend_pubkey',
			'gend_society_connected_at',
			'gend_society_features_cache',
			'gend_society_features_cache_expires',
			'gend_society_connection',
			'gend_society_connection_health',
			'gend_society_connect_pending',
			'gend_society_remote_membership_cache',
			'gend_society_remote_membership_cache_expires',
			'gend_society_media_storage_plans_cache',
			'gend_society_media_storage_plans_cache_expires',
		) as $option) {
			delete_option($option);
		}
		if (function_exists('gend_society_consent_clear')) {
			gend_society_consent_clear();
		}
		// @build-only-begin wporg
		// Optional sharing starts off again on the next connect.
		delete_option('gend_society_share_mail_relay');
		delete_option('gend_society_share_plugin_list');
		wp_clear_scheduled_hook('gend_society_mail_storage_report');
		wp_clear_scheduled_hook('gend_society_feature_state_daily');
		// @build-only-end wporg
	}
}

if (!function_exists('gend_society_portal_connect_standalone_redirect')) {
	/**
	 * Back to the Connect screen with a notice (and optional extra query args).
	 *
	 * @param string $status  success | error.
	 * @param string $message Plain text.
	 * @param array  $extra   Extra query args.
	 */
	function gend_society_portal_connect_standalone_redirect($status, $message, array $extra = array()) {
		$url = gend_society_connect_notice_url($status, $message);
		if ($extra) {
			$url = add_query_arg($extra, $url);
		}
		wp_safe_redirect($url);
		exit;
	}
}

if (!function_exists('gend_society_portal_connect_handle_standalone')) {
	/**
	 * admin-post gend_society_portal_connect_submit on a standalone install.
	 *
	 * @param string $op Operation.
	 */
	function gend_society_portal_connect_handle_standalone($op) {
		switch ($op) {
			case 'disconnect':
				gend_society_portal_connect_standalone_disconnect();
				return;
			case 'disconnect_local':
				check_admin_referer('gend_society_disconnect_local', 'gend_society_disconnect_local_nonce');
				gend_society_portal_connect_forget_local();
				gend_society_portal_connect_standalone_redirect('success', __('Disconnected on this site only. gend.me still holds this site\'s record, and its token stays valid there until you reconnect (which replaces it) or a gend.me admin removes it.', 'gend-society'));
				return;
			case 'transfer_owner':
			case 'claim_owner':
				gend_society_portal_connect_standalone_owner($op);
				return;
			// @build-only-begin wporg
			case 'data_sharing':
				gend_society_portal_connect_data_sharing_save();
				return;
			// @build-only-end wporg
			default:
				gend_society_portal_connect_standalone_pairing();
		}
	}
}

// @build-only-begin wporg
if (!function_exists('gend_society_portal_connect_data_sharing_save')) {
	/**
	 * admin-post op=data_sharing (wordpress.org build): save the owner's optional sharing choices from the
	 * Connected card. The caller checks manage_options; nonce gend_society_data_sharing.
	 */
	function gend_society_portal_connect_data_sharing_save() {
		check_admin_referer('gend_society_data_sharing', 'gend_society_data_sharing_nonce');
		if (!function_exists('gend_society_build_is_wporg') || !gend_society_build_is_wporg()) {
			gend_society_portal_connect_standalone_redirect('error', __('This build has no optional sharing settings.', 'gend-society'));
			return;
		}
		$plugin_list_was_on = gend_society_data_sharing_allowed('plugin_list');
		foreach (array_keys(gend_society_data_sharing_items()) as $item) {
			$field = 'gend_society_share_' . $item;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			$on = isset($_POST[$field]) && '1' === sanitize_text_field(wp_unslash((string) $_POST[$field]));
			update_option($field, $on ? '1' : '0', false);
		}
		if (!gend_society_data_sharing_allowed('mail_relay')) {
			wp_clear_scheduled_hook('gend_society_mail_storage_report');
		}
		if (!gend_society_data_sharing_allowed('plugin_list')) {
			wp_clear_scheduled_hook('gend_society_feature_state_daily');
		} elseif (!$plugin_list_was_on && function_exists('gend_society_feature_state_report_now')) {
			gend_society_feature_state_report_now();
		}
		gend_society_portal_connect_standalone_redirect('success', __('Sharing choices saved.', 'gend-society'));
	}
}

// @build-only-end wporg
if (!function_exists('gend_society_portal_connect_standalone_pairing')) {
	/**
	 * Pairing-code connect: consent first, then the hub's /connect, which
	 * challenges this site before it issues a token.
	 */
	function gend_society_portal_connect_standalone_pairing() {
		check_admin_referer('gs_portal_connect_submit', 'gs_portal_connect_nonce');

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$gend_base = isset($_POST['gend_base_url']) ? trim(esc_url_raw(wp_unslash((string) $_POST['gend_base_url']))) : '';
		$raw_code  = isset($_POST['pairing_code']) ? sanitize_text_field(wp_unslash((string) $_POST['pairing_code'])) : '';
		$consented = !empty($_POST['gend_society_consent']);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// The hub normalises codes the same way (uppercase, A-Z and 0-9 only); its request id uses this form.
		$pairing_code = preg_replace('/[^A-Z0-9]/', '', strtoupper($raw_code));
		$gend_base    = rtrim($gend_base, '/');

		if ('' === $gend_base || '' === gend_society_connect_url_origin($gend_base) || strlen($pairing_code) < 6) {
			gend_society_portal_connect_standalone_redirect('error', __('Please provide both the gend.me URL and a pairing code.', 'gend-society'));
			return;
		}
		if (!$consented) {
			gend_society_portal_connect_standalone_redirect('error', __('Please confirm you agree before connecting.', 'gend-society'));
			return;
		}

		// The consent values before this click: restored if the attempt fails while connected.
		$prior_consent = gend_society_connect_consent_snapshot();
		// Recorded before the first outbound request is built.
		gend_society_consent_record('pairing-code');

		$identity = gend_society_connect_identity();
		gend_society_connect_pairing_prepare($pairing_code, $prior_consent);

		$response = wp_remote_post(
			$gend_base . '/wp-json/gdc-app-manager/v1/connect',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'pairing_code'   => $pairing_code,
						'install_url'    => home_url('/'),
						'install_id'     => $identity['install_id'],
						'society_pubkey' => $identity['pubkey_b64'],
						'admin_email'    => (string) get_option('admin_email'),
						'admin_url'      => admin_url(),
						'plugin_version' => defined('GEND_SOCIETY_VERSION') ? (string) GEND_SOCIETY_VERSION : '',
					)
				),
			)
		);

		if (is_wp_error($response)) {
			gend_society_connect_abort('pairing_failed', $prior_consent);
			gend_society_portal_connect_standalone_redirect('error', sprintf(
				/* translators: %s error message */
				__('Connection failed: %s', 'gend-society'),
				sanitize_text_field($response->get_error_message())
			));
			return;
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$data   = json_decode((string) wp_remote_retrieve_body($response), true);

		if (200 !== $status || !is_array($data) || empty($data['install_token']) || !is_string($data['install_token'])
			|| (isset($data['install_id']) && (!is_string($data['install_id']) || !hash_equals($identity['install_id'], $data['install_id'])))) {
			gend_society_connect_abort('pairing_failed', $prior_consent);
			gend_society_portal_connect_standalone_redirect('error', sprintf(
				/* translators: 1: HTTP status, 2: error message */
				__('gend.me rejected the pairing (HTTP %1$d): %2$s', 'gend-society'),
				$status,
				gend_society_connect_hub_message($data, __('Unknown error from gend.me.', 'gend-society'))
			));
			return;
		}

		gend_society_connect_store($data, get_current_user_id(), 'pairing', $gend_base);
		delete_option('gend_society_connect_pending');

		$connection = gend_society_portal_connect_connection();
		$group_name = isset($connection['group']['name']) ? (string) $connection['group']['name'] : '';
		gend_society_portal_connect_standalone_redirect(
			'success',
			'' !== $group_name
				/* translators: %s: gend.me business group name */
				? sprintf(__('Connected to gend.me as part of %s.', 'gend-society'), $group_name)
				: __('Connected to gend.me.', 'gend-society')
		);
	}
}

if (!function_exists('gend_society_portal_connect_standalone_disconnect')) {
	/**
	 * Revoke-first Disconnect: gend.me must stop accepting the token (200, or
	 * 401 when it already does) before anything is deleted here.
	 */
	function gend_society_portal_connect_standalone_disconnect() {
		check_admin_referer('gs_portal_connect_disconnect', 'gs_portal_connect_nonce');

		if ('' === (string) get_option('gend_society_install_token', '') || '' === (string) get_option('gend_society_install_id', '')) {
			gend_society_portal_connect_forget_local();
			gend_society_portal_connect_standalone_redirect('success', __('Disconnected.', 'gend-society'));
			return;
		}

		$result = gend_society_portal_connect_hub_post('disconnect');
		if (200 === $result['status'] || 401 === $result['status']) {
			gend_society_portal_connect_forget_local();
			gend_society_portal_connect_standalone_redirect('success', __('Disconnected. gend.me no longer accepts this site\'s token.', 'gend-society'));
			return;
		}

		// Nothing deleted: the connection is still active on gend.me.
		gend_society_portal_connect_standalone_redirect(
			'error',
			__('Could not reach gend.me; the connection is still active there.', 'gend-society'),
			array('gs_disconnect_failed' => 1)
		);
	}
}

if (!function_exists('gend_society_portal_connect_standalone_owner')) {
	/**
	 * Transfer (or claim) the connection ownership.
	 *
	 * @param string $op transfer_owner | claim_owner.
	 */
	function gend_society_portal_connect_standalone_owner($op) {
		$claim = 'claim_owner' === $op;
		check_admin_referer($claim ? 'gend_society_claim_owner' : 'gend_society_transfer_owner', 'gend_society_owner_nonce');

		$current = get_current_user_id();
		if ('' === (string) get_option('gend_society_install_token', '')) {
			gend_society_portal_connect_standalone_redirect('error', __('This site is not connected to gend.me.', 'gend-society'));
			return;
		}
		if (!gend_society_portal_connect_can_manage_owner($current) || ($claim && gend_society_portal_connect_owner_valid())) {
			gend_society_portal_connect_standalone_redirect('error', __('Only the connection owner can transfer ownership.', 'gend-society'));
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$target_id = $claim ? $current : (isset($_POST['new_owner']) ? absint(wp_unslash($_POST['new_owner'])) : 0);
		$target    = $target_id > 0 ? get_userdata($target_id) : false;
		if (!$target || !user_can($target, 'manage_options')) {
			gend_society_portal_connect_standalone_redirect('error', __('The new owner must be an administrator of this site.', 'gend-society'));
			return;
		}
		if ((int) $target->ID === gend_society_portal_connect_owner_id()) {
			gend_society_portal_connect_standalone_redirect('error', __('That user already owns the connection.', 'gend-society'));
			return;
		}

		$connection                  = gend_society_portal_connect_connection();
		$connection['owner_user_id'] = (int) $target->ID;
		if (!isset($connection['hub'])) {
			$connection['hub'] = gend_society_portal_connect_hub_base();
		}
		update_option('gend_society_connection', $connection, false);

		// Best effort: gend.me shows the owner's display name to the business group.
		$result  = gend_society_portal_connect_hub_post('owner', array('owner_display' => (string) $target->display_name));
		$message = sprintf(
			/* translators: %s: user display name */
			__('%s now owns the connection to gend.me.', 'gend-society'),
			$target->display_name
		);
		if (200 !== $result['status']) {
			$message .= ' ' . sprintf(
				/* translators: %s: HTTP status or error */
				__('gend.me could not be told the new owner\'s name (%s).', 'gend-society'),
				$result['status'] ? 'HTTP ' . $result['status'] : $result['error']
			);
		}
		gend_society_portal_connect_standalone_redirect('success', $message);
	}
}

if (!function_exists('gend_society_portal_connect_render_notice')) {
	/**
	 * Status notice from the query string (set by the handlers above and by connect-flow). "inline" keeps
	 * core's common.js from moving it out of the Connect popup.
	 */
	function gend_society_portal_connect_render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if (!isset($_GET['gs_connect_status'])) {
			return;
		}
		$status  = sanitize_text_field(wp_unslash((string) $_GET['gs_connect_status']));
		$message = isset($_GET['gs_connect_message']) ? sanitize_text_field(wp_unslash((string) $_GET['gs_connect_message'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			esc_attr('success' === $status ? 'notice-success' : 'notice-error'),
			esc_html($message)
		);
	}
}

/*
 * ---------------------------------------------------------------------------
 * Phase 109-30 (D29, D32): the Connect / Connected cards above live in a popup
 * on the dashboard's Integration Hub card instead of their own page. The popup
 * is printed through 109-29's gend_society_integration_hub_card_slot (hidden),
 * opened by button[data-gs-connect-open] (assets/gs-connect-modal.js) and uses
 * the dashboard's own popup markup (gs-gas-modal: overlay, dialog, header,
 * close, body). Every form inside still posts to the 108 admin-post actions
 * with the 108 nonces. Standalone installs only; nothing here is requested
 * from gend.me while rendering.
 * ---------------------------------------------------------------------------
 */

if (!function_exists('gend_society_portal_connect_modal_hooks')) {
	/**
	 * init: hook the popup on standalone installs (connect-flow loads after this file).
	 */
	function gend_society_portal_connect_modal_hooks() {
		if (!gend_society_portal_connect_standalone_ready()) {
			return;
		}
		add_action('gend_society_integration_hub_card_slot', 'gend_society_portal_connect_modal_slot', 10);
		add_action('admin_footer', 'gend_society_portal_connect_modal_footer');
	}
}

if (!function_exists('gend_society_portal_connect_code_param')) {
	/**
	 * The pairing code from ?gend_society_code (the gend.me /pair short link) when it has the code
	 * pattern, else ''. It only pre-fills the field; consent and the click are still required.
	 *
	 * @return string
	 */
	function gend_society_portal_connect_code_param() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pre-fill only, validated by the pattern.
		$code = isset($_GET['gend_society_code']) && is_string($_GET['gend_society_code']) ? sanitize_text_field(wp_unslash($_GET['gend_society_code'])) : '';
		return preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $code) ? $code : '';
	}
}

if (!function_exists('gend_society_portal_connect_modal_html')) {
	/**
	 * The Connect popup: Connect card (not connected) or Connected card, with the handlers' status notice.
	 *
	 * @param string $context 'slot' (dashboard Integration Hub card: the dashboard's gs-gas-modal look) or
	 *                        'footer' (native WordPress dashboard: plain look).
	 * @return string '' for anyone but an administrator of a standalone install.
	 */
	function gend_society_portal_connect_modal_html($context = 'slot') {
		if (!gend_society_portal_connect_standalone_ready() || !current_user_can('manage_options')) {
			return '';
		}
		$dash = 'slot' === $context;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$auto = !empty($_GET['gs_connect']) || isset($_GET['gs_connect_status']) || '' !== gend_society_portal_connect_code_param();
		$cls  = function ($name) use ($dash) {
			return 'gs-connect-modal' . $name . ($dash ? ' gs-gas-modal' . $name : '');
		};
		ob_start();
		?>
<div id="gs-connect-modal" class="<?php echo esc_attr($cls('')); ?>" data-gs-connect-modal data-gs-connect-autoopen="<?php echo $auto ? '1' : '0'; ?>" hidden aria-hidden="true">
	<div class="<?php echo esc_attr($cls('__overlay')); ?>" data-gs-connect-close></div>
	<div class="<?php echo esc_attr($cls('__dialog')); ?>" role="dialog" aria-modal="true" aria-labelledby="gs-connect-modal-title" tabindex="-1">
		<header class="<?php echo esc_attr($cls('__header')); ?>">
			<h3 id="gs-connect-modal-title"><?php esc_html_e('Connect to gend.me', 'gend-society'); ?></h3>
			<button type="button" class="<?php echo esc_attr($cls('__close')); ?>" data-gs-connect-close aria-label="<?php esc_attr_e('Close', 'gend-society'); ?>">&times;</button>
		</header>
		<div class="<?php echo esc_attr($cls('__body')); ?> gend-society-connect">
			<?php
			gend_society_portal_connect_render_notice();
			if ('' !== (string) get_option('gend_society_install_token', '') && '' !== (string) get_option('gend_society_install_id', '')) {
				gend_society_portal_connect_render_connected();
			} else {
				gend_society_portal_connect_render_connect();
			}
			?>
		</div>
	</div>
</div>
		<?php
		return (string) ob_get_clean();
	}
}

if (!function_exists('gend_society_portal_connect_modal_slot')) {
	/**
	 * gend_society_integration_hub_card_slot (109-29, standalone dashboard): print the popup in the card.
	 */
	function gend_society_portal_connect_modal_slot() {
		echo gend_society_portal_connect_modal_html('slot'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}
}

if (!function_exists('gend_society_portal_connect_modal_footer')) {
	/**
	 * admin_footer on the dashboard when the GenD dashboard did not render (the admin chose standard
	 * WordPress): the same popup, so Connect stays reachable.
	 */
	function gend_society_portal_connect_modal_footer() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || 'dashboard' !== $screen->id || did_action('gend_society_integration_hub_card_slot')) {
			return;
		}
		echo gend_society_portal_connect_modal_html('footer'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}
}

if (!function_exists('gend_society_portal_connect_legacy_redirect')) {
	/**
	 * admin_page_access_denied: the removed pages' URLs (bookmarks, notices of older versions, the gend.me /pair short link
	 * index.php?page=gs-portal-connect&gend_society_code=...) go to the dashboard. The Connect page lands on the
	 * popup (standalone) keeping a valid code and the 108 status arguments; on standalone the removed
	 * Plan & features and hub area pages land on the dashboard itself.
	 */
	function gend_society_portal_connect_legacy_redirect() {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only, nothing is changed.
		if (!in_array($pagenow, array('admin.php', 'index.php'), true) || !isset($_GET['page']) || !is_string($_GET['page'])) {
			return;
		}
		$page       = sanitize_key(wp_unslash($_GET['page']));
		$standalone = gend_society_portal_connect_requires_consent();
		$connect    = 'gs-portal-connect' === $page;
		$other      = $standalone && in_array($page, array('gs-plan', 'gs-hub-groups', 'gs-hub-feature-suite', 'gs-hub-projects'), true);
		if ((!$connect && !$other) || !current_user_can('manage_options')) {
			return;
		}
		$args = array();
		$code = gend_society_portal_connect_code_param();
		if ('' !== $code) {
			$args['gend_society_code'] = $code;
		}
		$base = admin_url('index.php');
		if ($connect) {
			foreach (array('gs_connect_status', 'gs_connect_message', 'gs_disconnect_failed') as $key) {
				if (isset($_GET[$key]) && is_string($_GET[$key])) {
					$args[$key] = rawurlencode(sanitize_text_field(wp_unslash($_GET[$key])));
				}
			}
			if ($standalone && function_exists('gend_society_connect_url')) {
				$base = gend_society_connect_url();
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect($args ? add_query_arg($args, $base) : $base);
		exit;
	}
}

if (!function_exists('gend_society_portal_connect_render_links')) {
	/**
	 * Terms of Service and Privacy Policy links.
	 */
	function gend_society_portal_connect_render_links() {
		$terms   = function_exists('gend_society_terms_url') ? gend_society_terms_url() : 'https://gend.me/terms-of-service/';
		$privacy = function_exists('gend_society_privacy_url') ? gend_society_privacy_url() : 'https://gend.me/privacy-policy/';
		printf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a> &middot; <a href="%3$s" target="_blank" rel="noopener">%4$s</a>',
			esc_url($terms),
			esc_html__('Terms of Service', 'gend-society'),
			esc_url($privacy),
			esc_html__('Privacy Policy', 'gend-society')
		);
	}
}

if (!function_exists('gend_society_portal_connect_render_connect')) {
	/**
	 * Not connected: redirect connect (primary) and the pairing-code fallback.
	 * A code from the gend.me /pair short link only pre-fills the field; the
	 * consent box and the Connect click are still required, and the gend.me
	 * address never comes from the link.
	 */
	function gend_society_portal_connect_render_connect() {
		$disclosure = function_exists('gend_society_consent_disclosure_items') ? gend_society_consent_disclosure_items() : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pre-fill only, validated by the pattern.
		$code_param = isset($_GET['gend_society_code']) && is_string($_GET['gend_society_code']) ? sanitize_text_field(wp_unslash($_GET['gend_society_code'])) : '';
		$prefill    = preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $code_param) ? $code_param : '';
		$hub_url    = gend_society_connect_hub();
		?>
		<div class="gend-society-connect-card">
			<h2><?php esc_html_e('Connect this site to gend.me', 'gend-society'); ?></h2>
			<p><?php esc_html_e('You will log in to gend.me, choose a business group and approve. Nothing is sent until you click.', 'gend-society'); ?></p>
			<h3><?php esc_html_e('What is sent when you connect', 'gend-society'); ?></h3>
			<ul class="gend-society-connect-disclosure">
				<?php foreach ($disclosure as $label) : ?>
					<li><?php echo esc_html($label); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="gend-society-connect-links"><?php gend_society_portal_connect_render_links(); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gend-society-connect-primary">
				<?php wp_nonce_field('gend_society_connect_start'); ?>
				<input type="hidden" name="action" value="gend_society_connect_start" />
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e('Agree and connect to gend.me', 'gend-society'); ?></button>
			</form>
			<?php if (function_exists('gend_society_welcome_page_url')) : ?>
				<p><a href="<?php echo esc_url(gend_society_welcome_page_url()); ?>"><?php esc_html_e('Back to the GenD page', 'gend-society'); ?></a></p>
			<?php endif; ?>

			<details class="gend-society-connect-pairing"<?php echo '' !== $prefill ? ' open' : ''; ?>>
				<summary><?php esc_html_e('Have a pairing code?', 'gend-society'); ?></summary>
				<p><?php esc_html_e('Get a code from your business group\'s Hosting tab on gend.me and enter it here. The code you enter is sent together with the items listed above.', 'gend-society'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('gs_portal_connect_submit', 'gs_portal_connect_nonce'); ?>
					<input type="hidden" name="action" value="gend_society_portal_connect_submit" />
					<input type="hidden" name="op" value="connect" />
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th><label for="gs_gend_base_url"><?php esc_html_e('gend.me URL', 'gend-society'); ?></label></th>
								<td>
									<input type="url" id="gs_gend_base_url" name="gend_base_url" class="regular-text" value="<?php echo esc_attr($hub_url); ?>" required />
									<p class="description"><?php esc_html_e('Change this only if your agency runs its own gend.me portal.', 'gend-society'); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="gs_pairing_code"><?php esc_html_e('Pairing Code', 'gend-society'); ?></label></th>
								<td>
									<input type="text" id="gs_pairing_code" name="pairing_code" class="regular-text gend-society-connect-code" value="<?php echo esc_attr($prefill); ?>" maxlength="12" autocomplete="off" required />
								</td>
							</tr>
						</tbody>
					</table>
					<p>
						<label for="gend_society_consent">
							<input type="checkbox" id="gend_society_consent" name="gend_society_consent" value="1" required />
							<?php echo esc_html(function_exists('gend_society_consent_text') ? gend_society_consent_text() : __('I agree to send the items listed above to gend.me.', 'gend-society')); ?>
						</label>
					</p>
					<?php submit_button(__('Connect', 'gend-society'), 'secondary', 'submit', false); ?>
				</form>
			</details>
		</div>
		<?php
	}
}

if (!function_exists('gend_society_portal_connect_render_connected')) {
	/**
	 * Connected: hub, group, install ID, date, owner; Reconnect when gend.me
	 * no longer recognises the token; Disconnect; ownership transfer.
	 */
	function gend_society_portal_connect_render_connected() {
		$connection   = gend_society_portal_connect_connection();
		$hub          = gend_society_portal_connect_hub_base();
		$install_id   = (string) get_option('gend_society_install_id', '');
		$connected_at = (int) get_option('gend_society_connected_at', 0);
		$group        = isset($connection['group']) && is_array($connection['group']) ? $connection['group'] : array();
		$group_name   = isset($group['name']) ? (string) $group['name'] : '';
		$group_url    = isset($group['url']) ? (string) $group['url'] : '';
		$group_avatar = isset($group['avatar']) ? (string) $group['avatar'] : '';
		$approved_by  = isset($connection['approved_by']['display_name']) ? (string) $connection['approved_by']['display_name'] : '';
		$via          = isset($connection['via']) ? (string) $connection['via'] : '';
		$owner_id     = gend_society_portal_connect_owner_id();
		$owner        = $owner_id > 0 ? get_userdata($owner_id) : false;
		$health       = get_option('gend_society_connection_health', array());
		$unauthorized = is_array($health) && isset($health['status']) && 'unauthorized' === $health['status'];
		$can_owner    = gend_society_portal_connect_can_manage_owner(get_current_user_id());
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$disconnect_failed = !empty($_GET['gs_disconnect_failed']);
		$via_labels        = array(
			'redirect' => __('Approval on gend.me', 'gend-society'),
			'pairing'  => __('Pairing code', 'gend-society'),
		);
		?>
		<div class="gend-society-connect-card is-connected">
			<h2><?php esc_html_e('Connected to gend.me', 'gend-society'); ?></h2>

			<?php if ($unauthorized) : ?>
				<div class="gend-society-connect-warning">
					<p><strong><?php esc_html_e('gend.me no longer recognises this site.', 'gend-society'); ?></strong>
					<?php esc_html_e('Reconnect to restore the connection. This site keeps its install ID, so gend.me reactivates the same record.', 'gend-society'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('gend_society_connect_start'); ?>
						<input type="hidden" name="action" value="gend_society_connect_start" />
						<input type="hidden" name="reconnect" value="1" />
						<button type="submit" class="button button-primary"><?php esc_html_e('Reconnect to gend.me', 'gend-society'); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<table class="widefat striped gend-society-connect-details">
				<tbody>
					<tr><th><?php esc_html_e('gend.me', 'gend-society'); ?></th><td><a href="<?php echo esc_url($hub); ?>" target="_blank" rel="noopener"><?php echo esc_html($hub); ?></a></td></tr>
					<tr>
						<th><?php esc_html_e('Business group', 'gend-society'); ?></th>
						<td class="gend-society-connect-group">
							<?php if ('' !== $group_name) : ?>
								<?php if ('' !== $group_avatar) : ?>
									<img src="<?php echo esc_url($group_avatar); ?>" alt="<?php echo esc_attr($group_name); ?>" width="48" height="48" />
								<?php endif; ?>
								<?php if ('' !== $group_url) : ?>
									<a href="<?php echo esc_url($group_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($group_name); ?></a>
								<?php else : ?>
									<?php echo esc_html($group_name); ?>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e('Not recorded (connected before GenD Society 1.4.0)', 'gend-society'); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr><th><?php esc_html_e('Install ID', 'gend-society'); ?></th><td><code><?php echo esc_html($install_id); ?></code></td></tr>
					<tr><th><?php esc_html_e('Connected', 'gend-society'); ?></th><td><?php echo $connected_at ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $connected_at)) : '&mdash;'; ?></td></tr>
					<tr>
						<th><?php esc_html_e('Connection owner', 'gend-society'); ?></th>
						<td class="gend-society-connect-owner">
							<?php echo $owner ? esc_html($owner->display_name) : esc_html__('Not set', 'gend-society'); ?>
							<?php if ($can_owner && !gend_society_portal_connect_owner_valid()) : ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gend-society-connect-inline">
									<?php wp_nonce_field('gend_society_claim_owner', 'gend_society_owner_nonce'); ?>
									<input type="hidden" name="action" value="gend_society_portal_connect_submit" />
									<input type="hidden" name="op" value="claim_owner" />
									<button type="submit" class="button button-small"><?php esc_html_e('Make me the owner', 'gend-society'); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
					<tr><th><?php esc_html_e('Connected via', 'gend-society'); ?></th><td><?php echo isset($via_labels[$via]) ? esc_html($via_labels[$via]) : '&mdash;'; ?></td></tr>
					<?php if ('' !== $approved_by) : ?>
						<tr><th><?php esc_html_e('Approved on gend.me by', 'gend-society'); ?></th><td><?php echo esc_html($approved_by); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

<?php /* @build-only-begin wporg */ ?>
			<?php if (function_exists('gend_society_build_is_wporg') && gend_society_build_is_wporg()) : ?>
				<h3><?php esc_html_e('Optional sharing with gend.me', 'gend-society'); ?></h3>
				<p><?php esc_html_e('Both are off unless you turn them on here. Turning one off stops it straight away.', 'gend-society'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gend-society-connect-sharing">
					<?php wp_nonce_field('gend_society_data_sharing', 'gend_society_data_sharing_nonce'); ?>
					<input type="hidden" name="action" value="gend_society_portal_connect_submit" />
					<input type="hidden" name="op" value="data_sharing" />
					<?php foreach (gend_society_data_sharing_items() as $gend_society_item => $gend_society_share) : ?>
						<p>
							<label><input type="checkbox" name="gend_society_share_<?php echo esc_attr($gend_society_item); ?>" value="1"<?php checked(gend_society_data_sharing_allowed($gend_society_item)); ?> /> <?php echo esc_html($gend_society_share['label']); ?></label><br />
							<span class="description"><?php echo esc_html($gend_society_share['description']); ?></span>
						</p>
					<?php endforeach; ?>
					<?php submit_button(__('Save sharing choices', 'gend-society'), 'secondary', 'submit', false); ?>
				</form>
			<?php endif; ?>
<?php /* @build-only-end wporg */ ?>
			<h3><?php esc_html_e('Disconnect', 'gend-society'); ?></h3>
			<?php $billing = function_exists('gend_society_plan_disconnect_billing') ? gend_society_plan_disconnect_billing() : null; ?>
			<?php if ($billing) : ?>
				<div class="gend-society-connect-warning gend-society-connect-billing" data-gs-disconnect-billing>
					<p>
						<strong>
						<?php
						/* translators: %s: gend.me plan name, e.g. Store Owner. */
						echo esc_html(sprintf(__('Your %s plan on gend.me keeps billing after you disconnect.', 'gend-society'), $billing['plan_name']));
						?>
						</strong>
						<?php if ('' !== $billing['membership_url']) : ?>
							<a href="<?php echo esc_url($billing['membership_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Manage or cancel it on gend.me.', 'gend-society'); ?></a>
						<?php else : ?>
							<?php esc_html_e('Manage or cancel it on gend.me.', 'gend-society'); ?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e('gend.me is told to stop accepting this site\'s token first; only then is the connection removed here. This site keeps its install ID, so connecting again later restores the same record.', 'gend-society'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gend-society-connect-inline">
				<?php wp_nonce_field('gs_portal_connect_disconnect', 'gs_portal_connect_nonce'); ?>
				<input type="hidden" name="action" value="gend_society_portal_connect_submit" />
				<input type="hidden" name="op" value="disconnect" />
				<?php submit_button(__('Disconnect', 'gend-society'), 'delete', 'submit', false); ?>
			</form>
			<?php if ($disconnect_failed) : ?>
				<div class="gend-society-connect-warning gend-society-connect-local">
					<p><?php esc_html_e('If you disconnect on this site only, gend.me still holds this site\'s record, and its token stays valid there until you reconnect (which replaces it) or a gend.me admin removes it.', 'gend-society'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('gend_society_disconnect_local', 'gend_society_disconnect_local_nonce'); ?>
						<input type="hidden" name="action" value="gend_society_portal_connect_submit" />
						<input type="hidden" name="op" value="disconnect_local" />
						<?php submit_button(__('Disconnect locally anyway', 'gend-society'), 'delete', 'submit', false); ?>
					</form>
				</div>
			<?php endif; ?>

			<?php if ($can_owner) : ?>
				<?php $candidates = gend_society_portal_connect_owner_candidates(); ?>
				<h3><?php esc_html_e('Transfer ownership', 'gend-society'); ?></h3>
				<?php if ($candidates) : ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gend-society-connect-inline gend-society-connect-transfer">
						<?php wp_nonce_field('gend_society_transfer_owner', 'gend_society_owner_nonce'); ?>
						<input type="hidden" name="action" value="gend_society_portal_connect_submit" />
						<input type="hidden" name="op" value="transfer_owner" />
						<label for="gend_society_new_owner" class="screen-reader-text"><?php esc_html_e('New owner', 'gend-society'); ?></label>
						<select id="gend_society_new_owner" name="new_owner">
							<?php foreach ($candidates as $candidate) : ?>
								<option value="<?php echo esc_attr((string) $candidate->ID); ?>"><?php echo esc_html($candidate->display_name . ' (' . $candidate->user_login . ')'); ?></option>
							<?php endforeach; ?>
						</select>
						<?php submit_button(__('Transfer ownership', 'gend-society'), 'secondary', 'submit', false); ?>
					</form>
					<p class="description"><?php esc_html_e('gend.me is told the new owner\'s display name.', 'gend-society'); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e('There is no other administrator to transfer the connection to.', 'gend-society'); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
