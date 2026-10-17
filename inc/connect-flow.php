<?php
/**
 * Connect to a gend.me business group: the site side of the redirect flow
 * (Phase 108, CON-02 / CON-07).
 *
 * Manifest: tier customer, needs ['standalone'], so nothing here loads on the
 * gend.me hub or on gend.me containers.
 *
 * Flow (wire protocol v1):
 *   1. admin-post gend_society_connect_start (POST, manage_options, nonce):
 *      records consent FIRST, then POSTs a signed body to
 *      {hub}/wp-json/gdc-app-manager/v1/connect/start, saves a pending record
 *      (only the SHA-256 of the state) and redirects the browser to the hub's
 *      authorize_url + state.
 *   2. While the admin approves on gend.me, the hub calls
 *      POST /wp-json/gs/v1/connect/challenge on this site. It is answered only
 *      while a matching, unexpired pending request exists.
 *   3. admin-post gend_society_connect_return (GET): state, user, capability
 *      and expiry are checked before anything else; then the one-time code is
 *      exchanged at {hub}/wp-json/gdc-app-manager/v1/connect/complete with a
 *      signature by this install's key, and the connection is stored.
 *
 * The hub address always comes from gend_society_oauth_hub_url(), never from
 * a request parameter or a hub response.
 *
 * Options (all gend_society_ prefixed, removed by uninstall.php):
 *   gend_society_connect_pending  {state_hash, request_id, user_id, created_at,
 *                                  expires, via, hub, prior_consent}
 *   gend_society_connection       {hub, group, approved_by, connected_at,
 *                                  owner_user_id, via}
 *   gend_society_connect_rate     {window, count} challenge rate counter
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_CONNECT_PENDING_TTL' ) ) {
	/*
	 * Seconds a redirect connect may take from the Connect click to the
	 * return (matches the hub's signed request lifetime).
	 */
	define( 'GEND_SOCIETY_CONNECT_PENDING_TTL', 1800 );
}

if ( ! defined( 'GEND_SOCIETY_CONNECT_PAIRING_TTL' ) ) {
	/*
	 * Seconds the pairing-code flow keeps its pending record (the hub's
	 * challenge arrives while the pairing request is in flight).
	 */
	define( 'GEND_SOCIETY_CONNECT_PAIRING_TTL', 300 );
}

if ( ! defined( 'GEND_SOCIETY_CONNECT_CHALLENGE_RATE' ) ) {
	/*
	 * Challenge requests answered per minute (any outcome counts).
	 */
	define( 'GEND_SOCIETY_CONNECT_CHALLENGE_RATE', 20 );
}

add_action( 'admin_post_gend_society_connect_start', 'gend_society_connect_handle_start' );
add_action( 'admin_post_gend_society_connect_return', 'gend_society_connect_handle_return' );
add_action( 'rest_api_init', 'gend_society_connect_register_routes' );
add_action( 'admin_init', 'gend_society_connect_expire_pending' );

if ( ! function_exists( 'gend_society_connect_hub' ) ) {
	/**
	 * The gend.me hub this install connects to (no trailing slash). Only ever
	 * read from configuration, never from a request or a response.
	 */
	function gend_society_connect_hub(): string {
		$hub = function_exists( 'gend_society_oauth_hub_url' ) ? gend_society_oauth_hub_url() : 'https://gend.me';
		return rtrim( (string) $hub, '/' );
	}
}

if ( ! function_exists( 'gend_society_connect_url_origin' ) ) {
	/**
	 * Lowercase scheme://host[:port] of a URL (default ports dropped), '' when
	 * it is not an absolute http(s) URL.
	 *
	 * @param string $url URL.
	 */
	function gend_society_connect_url_origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ( $port ? ':' . $port : '' );
	}
}

if ( ! function_exists( 'gend_society_connect_install_url' ) ) {
	/**
	 * This site's address as the hub normalises it (lowercase scheme and host,
	 * no default port, trailing slash). Used in the challenge message.
	 */
	function gend_society_connect_install_url(): string {
		$home   = home_url( '/' );
		$origin = gend_society_connect_url_origin( $home );
		if ( '' === $origin ) {
			return $home;
		}
		$path = (string) wp_parse_url( $home, PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );
		return $origin . rtrim( $path, '/' ) . '/';
	}
}

if ( ! function_exists( 'gend_society_connect_identity' ) ) {
	/**
	 * This install's identity: the install id (UUID v4) and the Ed25519
	 * keypair. Both are created and stored on first use and reused afterwards
	 * (an existing install id or valid keypair is never replaced).
	 *
	 * @return array{install_id:string, keypair_raw:string, pubkey_b64:string}
	 */
	function gend_society_connect_identity(): array {
		$keypair_b64 = (string) get_option( 'gend_society_keypair', '' );
		$keypair_raw = '' !== $keypair_b64 ? base64_decode( $keypair_b64, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stored key material.
		if ( ! is_string( $keypair_raw ) || SODIUM_CRYPTO_SIGN_KEYPAIRBYTES !== strlen( $keypair_raw ) ) {
			$keypair_raw = sodium_crypto_sign_keypair();
			update_option( 'gend_society_keypair', base64_encode( $keypair_raw ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- key material.
		}

		$install_id = (string) get_option( 'gend_society_install_id', '' );
		if ( '' === $install_id ) {
			$install_id = wp_generate_uuid4();
			update_option( 'gend_society_install_id', $install_id, false );
		}

		return array(
			'install_id'  => $install_id,
			'keypair_raw' => $keypair_raw,
			'pubkey_b64'  => base64_encode( sodium_crypto_sign_publickey( $keypair_raw ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key.
		);
	}
}

if ( ! function_exists( 'gend_society_connect_sign' ) ) {
	/**
	 * Base64 detached Ed25519 signature of $message with the install key.
	 *
	 * @param string $message Message to sign.
	 */
	function gend_society_connect_sign( string $message ): string {
		$identity = gend_society_connect_identity();
		return base64_encode( sodium_crypto_sign_detached( $message, sodium_crypto_sign_secretkey( $identity['keypair_raw'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature transport.
	}
}

if ( ! function_exists( 'gend_society_connect_canonical_json' ) ) {
	/**
	 * Canonical JSON of a signed body: everything but 'sig', keys sorted,
	 * unescaped slashes and unicode (the hub re-encodes the same way).
	 *
	 * @param array $body Request body.
	 */
	function gend_society_connect_canonical_json( array $body ): string {
		unset( $body['sig'] );
		ksort( $body );
		return (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

if ( ! function_exists( 'gend_society_connect_pending' ) ) {
	/**
	 * The pending connect record, or an empty array.
	 *
	 * @return array
	 */
	function gend_society_connect_pending(): array {
		$pending = get_option( 'gend_society_connect_pending', array() );
		return is_array( $pending ) ? $pending : array();
	}
}

if ( ! function_exists( 'gend_society_connect_is_connected' ) ) {
	/**
	 * True when this install holds an install token from gend.me.
	 */
	function gend_society_connect_is_connected(): bool {
		return '' !== (string) get_option( 'gend_society_install_token', '' );
	}
}

if ( ! function_exists( 'gend_society_connect_consent_snapshot' ) ) {
	/**
	 * The exact stored consent values (null = option absent), taken before a
	 * Connect click changes them.
	 *
	 * @return array{consent:mixed, remote_consent:mixed}
	 */
	function gend_society_connect_consent_snapshot(): array {
		return array(
			'consent'        => get_option( 'gend_society_consent', null ),
			'remote_consent' => get_option( 'gend_society_remote_consent', null ),
		);
	}
}

if ( ! function_exists( 'gend_society_connect_consent_restore' ) ) {
	/**
	 * Put back a consent snapshot taken by gend_society_connect_consent_snapshot().
	 *
	 * @param array $snapshot Snapshot.
	 */
	function gend_society_connect_consent_restore( array $snapshot ): void {
		$map = array(
			'consent'        => 'gend_society_consent',
			'remote_consent' => 'gend_society_remote_consent',
		);
		foreach ( $map as $key => $option ) {
			$value = array_key_exists( $key, $snapshot ) ? $snapshot[ $key ] : null;
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
	}
}

if ( ! function_exists( 'gend_society_connect_abort' ) ) {
	/**
	 * End a connect attempt that did not complete (cancelled, refused, failed
	 * or expired). Always deletes the pending record.
	 *
	 * Not connected: consent is withdrawn (nothing is sent until the next
	 * Connect click). Connected (a reconnect from the Connected card): the
	 * hub-side token is unchanged, so the consent values from before the click
	 * are restored (pending.prior_consent, or $prior_consent when the attempt
	 * failed before the pending record was saved). Consent is never cleared
	 * while connected.
	 *
	 * @param string     $reason        Short reason id (start_failed, bad_state, cancelled, ...).
	 * @param array|null $prior_consent Snapshot to restore when no pending record holds one.
	 */
	function gend_society_connect_abort( string $reason, ?array $prior_consent = null ): void {
		$pending = gend_society_connect_pending();
		delete_option( 'gend_society_connect_pending' );

		if ( gend_society_connect_is_connected() ) {
			$snapshot = null;
			if ( isset( $pending['prior_consent'] ) && is_array( $pending['prior_consent'] ) ) {
				$snapshot = $pending['prior_consent'];
			} elseif ( null !== $prior_consent ) {
				$snapshot = $prior_consent;
			}
			if ( is_array( $snapshot ) && array_key_exists( 'consent', $snapshot ) ) {
				gend_society_connect_consent_restore( $snapshot );
			}
		} elseif ( function_exists( 'gend_society_consent_clear' ) ) {
			gend_society_consent_clear();
		}

		/**
		 * Fires after a connect attempt was abandoned.
		 *
		 * @param string $reason  Reason id.
		 * @param array  $pending The pending record that was removed (may be empty).
		 */
		do_action( 'gend_society_connect_aborted', $reason, $pending );
	}
}

if ( ! function_exists( 'gend_society_connect_notice_url' ) ) {
	/**
	 * The Connect popup URL (dashboard, popup open) with a status notice.
	 *
	 * @param string $status  'success' or 'error'.
	 * @param string $message Plain-text message.
	 */
	function gend_society_connect_notice_url( string $status, string $message ): string {
		$base = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?gs_connect=1' ) . '#gs-connect';
		return add_query_arg(
			array(
				'gs_connect_status'  => 'success' === $status ? 'success' : 'error',
				'gs_connect_message' => rawurlencode( $message ),
			),
			$base
		);
	}
}

if ( ! function_exists( 'gend_society_connect_finish' ) ) {
	/**
	 * Redirect to the Connect screen with a notice and stop.
	 *
	 * @param string $status  'success' or 'error'.
	 * @param string $message Plain-text message.
	 */
	function gend_society_connect_finish( string $status, string $message ): void {
		wp_safe_redirect( gend_society_connect_notice_url( $status, $message ) );
		exit;
	}
}

if ( ! function_exists( 'gend_society_connect_hub_message' ) ) {
	/**
	 * A short, plain-text error message from a hub JSON response.
	 *
	 * @param mixed  $data     Decoded body.
	 * @param string $fallback Message when the hub sent none.
	 */
	function gend_society_connect_hub_message( $data, string $fallback ): string {
		$message = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';
		if ( '' === $message ) {
			return $fallback;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 200 ) : substr( $message, 0, 200 );
	}
}

if ( ! function_exists( 'gend_society_connect_handle_start' ) ) {
	/**
	 * admin-post gend_society_connect_start: the Connect (or Reconnect) click.
	 */
	function gend_society_connect_handle_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			wp_die( esc_html__( 'Connect must be started from the Connect button.', 'gend-society' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'gend_society_connect_start' );

		$reconnect = ! empty( $_POST['reconnect'] );

		// The exact consent values before this click: restored if a reconnect is cancelled or fails.
		$prior_consent = gend_society_connect_consent_snapshot();

		// CON-07: consent is recorded before the first request to gend.me is built.
		if ( $reconnect ) {
			gend_society_consent_record( 'reconnect' );
		} else {
			gend_society_consent_record( 'redirect' );
		}

		$hub        = gend_society_connect_hub();
		$hub_origin = gend_society_connect_url_origin( $hub );
		$identity   = gend_society_connect_identity();
		$state      = bin2hex( random_bytes( 32 ) );

		$body = array(
			'install_id'     => $identity['install_id'],
			'install_url'    => home_url( '/' ),
			'admin_url'      => admin_url(),
			'society_pubkey' => $identity['pubkey_b64'],
			'admin_email'    => (string) get_option( 'admin_email' ),
			'plugin_version' => defined( 'GEND_SOCIETY_VERSION' ) ? (string) GEND_SOCIETY_VERSION : '',
			'ts'             => time(),
		);

		$body['sig'] = gend_society_connect_sign( 'gend-society-start|' . gend_society_connect_canonical_json( $body ) );

		$response = wp_remote_post(
			$hub . '/wp-json/gdc-app-manager/v1/connect/start',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			gend_society_connect_abort( 'start_failed', $prior_consent );
			gend_society_connect_finish(
				'error',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach gend.me: %s', 'gend-society' ),
					sanitize_text_field( $response->get_error_message() )
				)
			);
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $data ) ) {
			gend_society_connect_abort( 'start_failed', $prior_consent );
			gend_society_connect_finish(
				'error',
				sprintf(
					/* translators: 1: HTTP status, 2: message from gend.me */
					__( 'gend.me refused the connection request (HTTP %1$d): %2$s', 'gend-society' ),
					$status,
					gend_society_connect_hub_message( $data, __( 'Unknown error.', 'gend-society' ) )
				)
			);
			return;
		}

		$request       = isset( $data['request'] ) && is_string( $data['request'] ) ? $data['request'] : '';
		$request_id    = isset( $data['request_id'] ) && is_string( $data['request_id'] ) ? strtolower( $data['request_id'] ) : '';
		$authorize_url = isset( $data['authorize_url'] ) && is_string( $data['authorize_url'] ) ? $data['authorize_url'] : '';

		if ( '' === $request || ! preg_match( '/^[a-f0-9]{64}$/', $request_id ) || ! hash_equals( hash( 'sha256', $request ), $request_id )
			|| '' === $hub_origin || gend_society_connect_url_origin( $authorize_url ) !== $hub_origin ) {
			gend_society_connect_abort( 'start_failed', $prior_consent );
			gend_society_connect_finish( 'error', __( 'gend.me sent an unexpected answer. Nothing was connected; please try again.', 'gend-society' ) );
			return;
		}

		$now = time();
		update_option(
			'gend_society_connect_pending',
			array(
				'state_hash'    => hash( 'sha256', $state ),
				'request_id'    => $request_id,
				'user_id'       => get_current_user_id(),
				'created_at'    => $now,
				'expires'       => $now + GEND_SOCIETY_CONNECT_PENDING_TTL,
				'via'           => 'redirect',
				'hub'           => $hub,
				'prior_consent' => $prior_consent,
			),
			false
		);

		// Only the configured hub host may be redirected to; any other host falls back to admin_url().
		$hub_host = (string) wp_parse_url( $hub, PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $hub_host ) {
				$hosts   = is_array( $hosts ) ? $hosts : array();
				$hosts[] = $hub_host;
				return $hosts;
			}
		);
		wp_safe_redirect( add_query_arg( 'state', $state, $authorize_url ) );
		exit;
	}
}

if ( ! function_exists( 'gend_society_connect_handle_return' ) ) {
	/**
	 * admin-post gend_society_connect_return: the browser comes back from
	 * gend.me with ?code=&state= (approved) or ?error=&state= (cancelled).
	 */
	function gend_society_connect_handle_return(): void {
		// A user without the capability cannot change anything here.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'gend-society' ), '', array( 'response' => 403 ) );
		}

		$pending = gend_society_connect_pending();
		$hub     = gend_society_connect_hub();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed state below is this request's CSRF check.
		$state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$user_id = get_current_user_id();
		if ( empty( $pending['state_hash'] ) || ! is_string( $pending['state_hash'] )
			|| ! isset( $pending['via'] ) || 'redirect' !== $pending['via']
			|| $user_id <= 0 || (int) ( $pending['user_id'] ?? 0 ) !== $user_id
			|| time() > (int) ( $pending['expires'] ?? 0 )
			|| ! isset( $pending['hub'] ) || $pending['hub'] !== $hub
			|| '' === $state || ! hash_equals( $pending['state_hash'], hash( 'sha256', $state ) ) ) {
			gend_society_connect_abort( 'bad_state' );
			gend_society_connect_finish( 'error', __( 'This connection link is not valid any more. Nothing was connected; please click Connect again.', 'gend-society' ) );
			return;
		}

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- state verified above.
			gend_society_connect_abort( 'cancelled' );
			gend_society_connect_finish( 'error', __( 'Connection cancelled on gend.me.', 'gend-society' ) );
			return;
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $code ) ) {
			gend_society_connect_abort( 'bad_code' );
			gend_society_connect_finish( 'error', __( 'gend.me did not send a valid connect code. Nothing was connected; please try again.', 'gend-society' ) );
			return;
		}

		$identity = gend_society_connect_identity();
		$response = wp_remote_post(
			$hub . '/wp-json/gdc-app-manager/v1/connect/complete',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'install_id' => $identity['install_id'],
						'code'       => $code,
						'sig'        => gend_society_connect_sign( 'gend-society-complete|' . $code . '|' . $identity['install_id'] ),
					)
				),
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data   = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $data ) || empty( $data['install_token'] ) || ! is_string( $data['install_token'] )
			|| ! isset( $data['install_id'] ) || ! is_string( $data['install_id'] ) || ! hash_equals( $identity['install_id'], $data['install_id'] ) ) {
			gend_society_connect_abort( 'complete_failed' );
			$message = is_wp_error( $response )
				? sprintf(
					/* translators: %s: error message */
					__( 'Could not reach gend.me: %s', 'gend-society' ),
					sanitize_text_field( $response->get_error_message() )
				)
				: sprintf(
					/* translators: 1: HTTP status, 2: message from gend.me */
					__( 'gend.me did not accept the connection (HTTP %1$d): %2$s', 'gend-society' ),
					$status,
					gend_society_connect_hub_message( $data, __( 'Unknown error.', 'gend-society' ) )
				);
			gend_society_connect_finish( 'error', $message );
			return;
		}

		gend_society_connect_store( $data, (int) $pending['user_id'], 'redirect', $hub );
		delete_option( 'gend_society_connect_pending' );

		$connection = get_option( 'gend_society_connection', array() );
		$group_name = is_array( $connection ) && isset( $connection['group']['name'] ) ? (string) $connection['group']['name'] : '';
		gend_society_connect_finish(
			'success',
			'' !== $group_name
				? sprintf(
					/* translators: %s: gend.me business group name */
					__( 'Connected to gend.me as part of %s.', 'gend-society' ),
					$group_name
				)
				: __( 'Connected to gend.me.', 'gend-society' )
		);
	}
}

if ( ! function_exists( 'gend_society_connect_store' ) ) {
	/**
	 * Save a successful /connect/complete (or pairing) answer.
	 *
	 * @param array  $resp          Hub response.
	 * @param int    $owner_user_id Local user who connected (the connection owner).
	 * @param string $via           'redirect' or 'pairing'.
	 * @param string $hub           Hub base URL (from configuration).
	 */
	function gend_society_connect_store( array $resp, int $owner_user_id, string $via, string $hub ): void {
		$identity     = gend_society_connect_identity();
		$connected_at = isset( $resp['connected_at'] ) ? (int) $resp['connected_at'] : 0;
		if ( $connected_at <= 0 ) {
			$connected_at = time();
		}
		$group    = isset( $resp['group'] ) && is_array( $resp['group'] ) ? $resp['group'] : array();
		$approved = isset( $resp['approved_by'] ) && is_array( $resp['approved_by'] ) ? $resp['approved_by'] : array();

		update_option( 'gend_society_install_id', $identity['install_id'], false );
		update_option( 'gend_society_install_token', isset( $resp['install_token'] ) ? (string) $resp['install_token'] : '', false );
		update_option( 'gend_society_gend_base_url', $hub, false );
		update_option( 'gend_society_gend_pubkey', isset( $resp['gend_signing_pubkey'] ) && is_string( $resp['gend_signing_pubkey'] ) ? $resp['gend_signing_pubkey'] : '', false );
		update_option( 'gend_society_connected_at', $connected_at, false );

		update_option(
			'gend_society_connection',
			array(
				'hub'           => $hub,
				'group'         => array(
					'id'     => isset( $group['id'] ) ? (int) $group['id'] : 0,
					'name'   => isset( $group['name'] ) ? sanitize_text_field( (string) $group['name'] ) : '',
					'slug'   => isset( $group['slug'] ) ? sanitize_title( (string) $group['slug'] ) : '',
					'avatar' => isset( $group['avatar'] ) ? esc_url_raw( (string) $group['avatar'] ) : '',
					'url'    => isset( $group['url'] ) ? esc_url_raw( (string) $group['url'] ) : '',
				),
				'approved_by'   => array(
					'id'           => isset( $approved['id'] ) ? (int) $approved['id'] : 0,
					'display_name' => isset( $approved['display_name'] ) ? sanitize_text_field( (string) $approved['display_name'] ) : '',
				),
				'connected_at'  => $connected_at,
				'owner_user_id' => $owner_user_id,
				'via'           => 'pairing' === $via ? 'pairing' : 'redirect',
			),
			false
		);

		// Feature and membership answers cached before the connect are stale now.
		delete_option( 'gend_society_features_cache' );
		delete_option( 'gend_society_features_cache_expires' );
		delete_option( 'gend_society_remote_membership_cache' );
		delete_option( 'gend_society_remote_membership_cache_expires' );
		// A new token: an earlier "unauthorized" health result no longer applies.
		delete_option( 'gend_society_connection_health' );
	}
}

if ( ! function_exists( 'gend_society_connect_pairing_prepare' ) ) {
	/**
	 * Pending record for the pairing-code flow: lets the hub's challenge be
	 * answered while the pairing request is in flight.
	 *
	 * @param string     $code          The pairing code exactly as it is sent to gend.me.
	 * @param array|null $prior_consent Consent snapshot from before the click (taken now when null).
	 */
	function gend_society_connect_pairing_prepare( string $code, ?array $prior_consent = null ): void {
		$identity = gend_society_connect_identity();
		$now      = time();
		update_option(
			'gend_society_connect_pending',
			array(
				'state_hash'    => '',
				'request_id'    => hash( 'sha256', 'pair|' . $code . '|' . $identity['install_id'] ),
				'user_id'       => get_current_user_id(),
				'created_at'    => $now,
				'expires'       => $now + GEND_SOCIETY_CONNECT_PAIRING_TTL,
				'via'           => 'pairing',
				'hub'           => gend_society_connect_hub(),
				'prior_consent' => null !== $prior_consent ? $prior_consent : gend_society_connect_consent_snapshot(),
			),
			false
		);
	}
}

if ( ! function_exists( 'gend_society_connect_register_routes' ) ) {
	/**
	 * REST gs/v1/connect/challenge (the hub's proof that this site controls its address).
	 */
	function gend_society_connect_register_routes(): void {
		register_rest_route(
			'gs/v1',
			'/connect/challenge',
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_connect_rest_challenge',
				'permission_callback' => '__return_true',
			)
		);
	}
}

if ( ! function_exists( 'gend_society_connect_challenge_rate_ok' ) ) {
	/**
	 * Fixed one-minute window counter for the challenge endpoint.
	 */
	function gend_society_connect_challenge_rate_ok(): bool {
		$window = (int) floor( time() / 60 );
		$rate   = get_option( 'gend_society_connect_rate', array() );
		$count  = is_array( $rate ) && isset( $rate['window'], $rate['count'] ) && (int) $rate['window'] === $window ? (int) $rate['count'] : 0;
		++$count;
		update_option(
			'gend_society_connect_rate',
			array(
				'window' => $window,
				'count'  => $count,
			),
			false
		);
		return $count <= (int) apply_filters( 'gend_society_connect_challenge_rate', GEND_SOCIETY_CONNECT_CHALLENGE_RATE );
	}
}

if ( ! function_exists( 'gend_society_connect_rest_challenge' ) ) {
	/**
	 * POST gs/v1/connect/challenge {request_id, challenge}: signs
	 * "gend-society-challenge|request_id|challenge|install_url" only while a
	 * matching, unexpired pending connect exists. Never signs anything else.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	function gend_society_connect_rest_challenge( WP_REST_Request $request ) {
		if ( ! gend_society_connect_challenge_rate_ok() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests.', 'gend-society' ), array( 'status' => 429 ) );
		}

		$no_pending = new WP_Error( 'no_pending', __( 'No connect request is waiting on this site.', 'gend-society' ), array( 'status' => 404 ) );

		$request_id = $request->get_param( 'request_id' );
		$challenge  = $request->get_param( 'challenge' );
		if ( ! is_string( $request_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_id )
			|| ! is_string( $challenge ) || ! preg_match( '#^[A-Za-z0-9+/=_-]{16,128}$#', $challenge ) ) {
			return $no_pending;
		}

		$pending = gend_society_connect_pending();
		if ( empty( $pending['request_id'] ) || ! is_string( $pending['request_id'] )
			|| time() > (int) ( $pending['expires'] ?? 0 )
			|| ! hash_equals( $pending['request_id'], $request_id ) ) {
			return $no_pending;
		}

		return rest_ensure_response(
			array(
				'sig' => gend_society_connect_sign( 'gend-society-challenge|' . $request_id . '|' . $challenge . '|' . gend_society_connect_install_url() ),
			)
		);
	}
}

if ( ! function_exists( 'gend_society_connect_expire_pending' ) ) {
	/**
	 * Lazy cleanup on admin loads: an expired pending connect is abandoned
	 * (consent withdrawn when not connected, restored when it was a reconnect).
	 */
	function gend_society_connect_expire_pending(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pending = gend_society_connect_pending();
		if ( ! $pending || time() <= (int) ( $pending['expires'] ?? 0 ) ) {
			return;
		}
		gend_society_connect_abort( 'expired' );
	}
}
