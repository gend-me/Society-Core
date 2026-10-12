<?php
/**
 * Connection health without cron (Phase 108, CON-06).
 *
 * Manifest: tier customer, needs ['standalone', 'paired'], so nothing here
 * loads on the gend.me hub, on gend.me containers, or before a connection.
 *
 * How it works:
 *   - On wp-admin pages, for users with manage_options, a small footer script
 *     is enqueued. After the window `load` event (never during the page load)
 *     it POSTs admin-ajax `gend_society_connection_health`, but only when the
 *     last check is older than the interval (300 s, filter
 *     `gend_society_connection_health_interval`).
 *   - The AJAX handler asks gend.me `GET /install/{id}/health` with the
 *     install token. 200 -> ok, 401 -> unauthorized, anything else -> error.
 *   - On unauthorized a dismissible "Reconnect" notice is injected right away
 *     and rendered on the next admin loads until the admin dismisses it. The
 *     dismissal is stored per user and keyed to the check's time, so a later
 *     401 check shows it again.
 *   - The features and remote-membership fetchers report the HTTP status they
 *     see through gend_society_connection_health_note_status() (guarded by
 *     function_exists there, so hub and container behave as before).
 *
 * Never schedules cron events. Never deletes connection options on a 401:
 * the admin decides (Reconnect, or Disconnect locally).
 *
 * Option gend_society_connection_health {status: ok|unauthorized|error, code, checked_at}
 * (cleared by gend_society_connect_store() and by Disconnect).
 * User meta gend_society_health_dismissed = checked_at of the dismissed check.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', 'gend_society_connection_health_enqueue' );
add_action( 'admin_notices', 'gend_society_connection_health_render_notice' );
add_action( 'wp_ajax_gend_society_connection_health', 'gend_society_connection_health_ajax' );
add_action( 'wp_ajax_gend_society_connection_health_dismiss', 'gend_society_connection_health_ajax_dismiss' );

if ( ! function_exists( 'gend_society_connection_health_get' ) ) {
	/**
	 * The stored health result, normalised.
	 *
	 * @return array{status:string, code:int, checked_at:int}
	 */
	function gend_society_connection_health_get(): array {
		$h = get_option( 'gend_society_connection_health', array() );
		$h = is_array( $h ) ? $h : array();
		return array(
			'status'     => isset( $h['status'] ) ? (string) $h['status'] : '',
			'code'       => isset( $h['code'] ) ? (int) $h['code'] : 0,
			'checked_at' => isset( $h['checked_at'] ) ? (int) $h['checked_at'] : 0,
		);
	}
}

if ( ! function_exists( 'gend_society_connection_health_save' ) ) {
	/**
	 * Store a health result (checked now).
	 *
	 * @param string $status ok | unauthorized | error.
	 * @param int    $code   HTTP status (0 for a network error).
	 * @return array The stored value.
	 */
	function gend_society_connection_health_save( string $status, int $code ): array {
		$value = array(
			'status'     => $status,
			'code'       => $code,
			'checked_at' => time(),
		);
		update_option( 'gend_society_connection_health', $value, false );
		return $value;
	}
}

if ( ! function_exists( 'gend_society_connection_health_interval' ) ) {
	/**
	 * Seconds between two background checks (filterable; 0 checks on every admin load).
	 */
	function gend_society_connection_health_interval(): int {
		return max( 0, (int) apply_filters( 'gend_society_connection_health_interval', 300 ) );
	}
}

if ( ! function_exists( 'gend_society_connection_health_is_connected' ) ) {
	/**
	 * True when an install token is stored (the only state a check makes sense in).
	 */
	function gend_society_connection_health_is_connected(): bool {
		return '' !== (string) get_option( 'gend_society_install_token', '' )
			&& '' !== (string) get_option( 'gend_society_install_id', '' );
	}
}

if ( ! function_exists( 'gend_society_connection_health_is_due' ) ) {
	/**
	 * True when the last check is older than the interval.
	 */
	function gend_society_connection_health_is_due(): bool {
		$h = gend_society_connection_health_get();
		return ( time() - $h['checked_at'] ) >= gend_society_connection_health_interval();
	}
}

if ( ! function_exists( 'gend_society_connection_health_on_connect_screen' ) ) {
	/**
	 * The Connect screen shows its own Reconnect warning and button.
	 */
	function gend_society_connection_health_on_connect_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'dashboard_page_gs-portal-connect' === $screen->id;
	}
}

if ( ! function_exists( 'gend_society_connection_health_enqueue' ) ) {
	/**
	 * Enqueue the throttled ping for administrators of a connected site.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	function gend_society_connection_health_enqueue( $hook_suffix = '' ): void {
		if ( ! current_user_can( 'manage_options' ) || ! gend_society_connection_health_is_connected() ) {
			return;
		}
		$base = defined( 'GEND_SOCIETY_URL' ) ? GEND_SOCIETY_URL : plugin_dir_url( dirname( __DIR__ ) . '/gend-society.php' );
		wp_enqueue_script(
			'gend-society-connection-health',
			$base . 'assets/gs-connection-health.js',
			array(),
			defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '1',
			true
		);
		$allowed = ! function_exists( 'gend_society_remote_allowed' ) || gend_society_remote_allowed( 'health' );
		wp_localize_script(
			'gend-society-connection-health',
			'gendSocietyConnectionHealth',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gend_society_connection_health' ),
				'due'     => $allowed && gend_society_connection_health_is_due(),
				'inject'  => ! gend_society_connection_health_on_connect_screen(),
			)
		);
	}
}

if ( ! function_exists( 'gend_society_connection_health_check' ) ) {
	/**
	 * Ask gend.me whether the install token is still accepted and store the answer.
	 *
	 * @return array{status:string, code?:int, checked_at?:int}
	 */
	function gend_society_connection_health_check(): array {
		if ( function_exists( 'gend_society_remote_allowed' ) && ! gend_society_remote_allowed( 'health' ) ) {
			return array( 'status' => 'skipped' );
		}
		$install_id = (string) get_option( 'gend_society_install_id', '' );
		$token      = (string) get_option( 'gend_society_install_token', '' );
		$base       = (string) get_option( 'gend_society_gend_base_url', '' );
		if ( '' === $install_id || '' === $token || '' === $base ) {
			return array( 'status' => 'skipped' );
		}
		$response = wp_remote_get(
			trailingslashit( $base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/health',
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$status = 'ok';
		} elseif ( 401 === $code ) {
			$status = 'unauthorized';
		} else {
			$status = 'error';
		}
		return gend_society_connection_health_save( $status, $code );
	}
}

if ( ! function_exists( 'gend_society_connection_health_note_status' ) ) {
	/**
	 * Record an HTTP status another gend.me call saw with the install token.
	 * 401 -> unauthorized, 200 -> ok, anything else leaves the stored result alone.
	 *
	 * A 401 while the status is already unauthorized changes nothing: failed
	 * fetches are not cached, so they repeat on every admin load, and a new
	 * checked_at each time would undo the admin's dismissal on the next page.
	 * Only the throttled health check starts a new "check".
	 *
	 * @param int $code HTTP status.
	 */
	function gend_society_connection_health_note_status( int $code ): void {
		if ( 401 === $code ) {
			if ( 'unauthorized' !== gend_society_connection_health_get()['status'] ) {
				gend_society_connection_health_save( 'unauthorized', 401 );
			}
		} elseif ( 200 === $code ) {
			gend_society_connection_health_save( 'ok', 200 );
		}
	}
}

if ( ! function_exists( 'gend_society_connection_health_notice_html' ) ) {
	/**
	 * The Reconnect notice markup for a health result.
	 *
	 * @param array $health Result from gend_society_connection_health_get().
	 */
	function gend_society_connection_health_notice_html( array $health ): string {
		$url = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?page=gs-portal-connect' );
		return '<div class="notice notice-warning is-dismissible gs-connection-health-notice" data-gs-health-checked="' . esc_attr( (string) (int) ( $health['checked_at'] ?? 0 ) ) . '"><p>'
			. esc_html__( "gend.me no longer recognises this site's connection.", 'gend-society' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Reconnect', 'gend-society' ) . '</a></p></div>';
	}
}

if ( ! function_exists( 'gend_society_connection_health_notice_visible' ) ) {
	/**
	 * True when the current user should see the Reconnect notice.
	 */
	function gend_society_connection_health_notice_visible(): bool {
		if ( ! current_user_can( 'manage_options' ) || ! gend_society_connection_health_is_connected() ) {
			return false;
		}
		$h = gend_society_connection_health_get();
		if ( 'unauthorized' !== $h['status'] ) {
			return false;
		}
		$dismissed = (string) get_user_meta( get_current_user_id(), 'gend_society_health_dismissed', true );
		return (string) $h['checked_at'] !== $dismissed;
	}
}

if ( ! function_exists( 'gend_society_connection_health_render_notice' ) ) {
	/**
	 * admin_notices: the Reconnect notice (not on the Connect screen, which has its own).
	 */
	function gend_society_connection_health_render_notice(): void {
		if ( gend_society_connection_health_on_connect_screen() || ! gend_society_connection_health_notice_visible() ) {
			return;
		}
		echo gend_society_connection_health_notice_html( gend_society_connection_health_get() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the builder.
	}
}

if ( ! function_exists( 'gend_society_connection_health_ajax' ) ) {
	/**
	 * admin-ajax gend_society_connection_health: run a check now.
	 */
	function gend_society_connection_health_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'status' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'gend_society_connection_health', 'nonce' );
		$result = gend_society_connection_health_check();
		$out    = array( 'status' => $result['status'] );
		if ( 'unauthorized' === $result['status'] ) {
			$out['notice_html'] = gend_society_connection_health_notice_html( $result );
		}
		wp_send_json( $out );
	}
}

if ( ! function_exists( 'gend_society_connection_health_ajax_dismiss' ) ) {
	/**
	 * admin-ajax gend_society_connection_health_dismiss: hide the notice for this check.
	 */
	function gend_society_connection_health_ajax_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'status' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'gend_society_connection_health', 'nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$checked = isset( $_POST['checked_at'] ) ? absint( wp_unslash( $_POST['checked_at'] ) ) : 0;
		if ( $checked <= 0 ) {
			$checked = gend_society_connection_health_get()['checked_at'];
		}
		update_user_meta( get_current_user_id(), 'gend_society_health_dismissed', (string) $checked );
		wp_send_json( array( 'status' => 'dismissed' ) );
	}
}
