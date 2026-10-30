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

if ( ! function_exists( 'gend_society_connection_health_migration' ) ) {
	/**
	 * The hub's additive migration block as stored by the last health check (111-12, MIG-11): live, migrated_to,
	 * migrated_at, cleanup. Empty array when the hub never sent one (old hub) or this install is not paired.
	 *
	 * @return array{live:bool, migrated_to:string, migrated_at:int, cleanup:bool}|array{}
	 */
	function gend_society_connection_health_migration(): array {
		$h = get_option( 'gend_society_connection_health', array() );
		$m = is_array( $h ) && isset( $h['migration'] ) && is_array( $h['migration'] ) ? $h['migration'] : array();
		if ( ! $m ) {
			return array();
		}
		return gend_society_connection_health_clean_migration( $m );
	}
}

if ( ! function_exists( 'gend_society_connection_health_clean_migration' ) ) {
	/**
	 * Sanitise the hub's migration block (never trust a stored or received shape).
	 *
	 * @param array $m Raw block.
	 * @return array{live:bool, migrated_to:string, migrated_at:int, cleanup:bool}
	 */
	function gend_society_connection_health_clean_migration( array $m ): array {
		$to = isset( $m['migrated_to'] ) && is_string( $m['migrated_to'] ) ? trim( $m['migrated_to'] ) : '';
		if ( '' !== $to && ! preg_match( '#^https?://#i', $to ) ) {
			// A bare host from the hub becomes https; anything with another scheme (javascript:, data:, ...) is dropped.
			$to = preg_match( '#^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?(/.*)?$#i', $to ) && ! preg_match( '#^[a-z][a-z0-9+.-]*:(?!\d{1,5}(/|$))#i', $to ) ? 'https://' . $to : '';
		}
		return array(
			'live'        => ! empty( $m['live'] ),
			'migrated_to' => '' === $to ? '' : (string) esc_url_raw( $to, array( 'http', 'https' ) ),
			'migrated_at' => isset( $m['migrated_at'] ) ? max( 0, (int) $m['migrated_at'] ) : 0,
			'cleanup'     => ! empty( $m['cleanup'] ),
		);
	}
}

if ( ! function_exists( 'gend_society_connection_health_save' ) ) {
	/**
	 * Store a health result (checked now).
	 *
	 * The hub's migration block (111-12) is kept across saves: a status note from another call (note_status) must not
	 * drop it. Pass an array to replace it; null (the default) carries the stored one over, so old callers and old hubs
	 * change nothing.
	 *
	 * @param string     $status    ok | unauthorized | error.
	 * @param int        $code      HTTP status (0 for a network error).
	 * @param array|null $migration Sanitised migration block to store, or null to keep the stored one.
	 * @return array The stored value.
	 */
	function gend_society_connection_health_save( string $status, int $code, ?array $migration = null ): array {
		$value = array(
			'status'     => $status,
			'code'       => $code,
			'checked_at' => time(),
		);
		$keep  = null === $migration ? gend_society_connection_health_migration() : $migration;
		if ( $keep ) {
			$value['migration'] = $keep;
		}
		update_option( 'gend_society_connection_health', $value, false );
		return $value;
	}
}

if ( ! function_exists( 'gend_society_origin_moved' ) ) {
	/**
	 * Has this ORIGIN site moved to a container (111-12, MIG-11)? True once the stored hub block says live with a
	 * migrated_to address that is not this site's own host. A container carries no block and, when it does see one,
	 * is recognised by host (its own host equals migrated_to), so every caller can use this on both runtimes.
	 *
	 * @return array{moved:bool, to:string, at:int}
	 */
	function gend_society_origin_moved(): array {
		$none = array(
			'moved' => false,
			'to'    => '',
			'at'    => 0,
		);
		if ( function_exists( 'gend_society_runtime_mode' ) && 'container' === gend_society_runtime_mode() ) {
			return $none;
		}
		$m = gend_society_connection_health_migration();
		if ( empty( $m['live'] ) || '' === (string) ( $m['migrated_to'] ?? '' ) ) {
			return $none;
		}
		$to   = strtolower( (string) wp_parse_url( $m['migrated_to'], PHP_URL_HOST ) );
		$here = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $to || $to === $here ) {
			return $none; // this IS the container
		}
		return array(
			'moved' => true,
			'to'    => (string) $m['migrated_to'],
			'at'    => (int) $m['migrated_at'],
		);
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
	 * The Connect popup shows its own Reconnect warning and button: true on the dashboard when the request
	 * opens it (gs_connect=1, the target of gend_society_connect_url()).
	 */
	function gend_society_connection_health_on_connect_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		return $screen && 'dashboard' === $screen->id && ! empty( $_GET['gs_connect'] );
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
		// 111-12 (MIG-11): the hub's additive migration block; an old hub sends none and nothing changes.
		$migration = null;
		if ( 200 === $code ) {
			$data = json_decode( trim( str_replace( "\xEF\xBB\xBF", '', (string) wp_remote_retrieve_body( $response ) ) ), true );
			if ( is_array( $data ) && isset( $data['migration'] ) && is_array( $data['migration'] ) ) {
				$migration = gend_society_connection_health_clean_migration( $data['migration'] );
			}
		}
		$saved = gend_society_connection_health_save( $status, $code, $migration );
		if ( null !== $migration ) {
			gend_society_origin_handover( $migration );
		}
		return $saved;
	}
}

if ( ! function_exists( 'gend_society_origin_handover' ) ) {
	/**
	 * What a fresh migration block does on the origin: parks WP-Cron once the site has moved (P5), restores it when the
	 * hub reports live=false again (rollback), and fires the cleanup once the hub asks for it. The origin's content,
	 * tables and uploads are never touched.
	 *
	 * @param array $migration Sanitised block.
	 */
	function gend_society_origin_handover( array $migration ): void {
		$moved = gend_society_origin_moved();
		if ( $moved['moved'] ) {
			gend_society_origin_park_cron( $moved['to'] );
			gend_society_origin_cleanup_maybe();
		} elseif ( empty( $migration['live'] ) ) {
			gend_society_origin_unpark_cron(); // the hub rolled back: the origin runs normally again
		}
	}
}

if ( ! function_exists( 'gend_society_origin_cleanup_maybe' ) ) {
	/**
	 * Fire gend_society_migration_cleanup once when the stored hub block asks for it (cleanup true, after Live). The
	 * existing handler removes the export staging and the job option (never origin data); the option
	 * gend_society_origin_cleanup_done records that it ran.
	 *
	 * @return bool True when the cleanup ran now.
	 */
	function gend_society_origin_cleanup_maybe(): bool {
		$m = gend_society_connection_health_migration();
		if ( empty( $m['cleanup'] ) || ! gend_society_origin_moved()['moved'] || false !== get_option( 'gend_society_origin_cleanup_done', false ) ) {
			return false;
		}
		do_action( 'gend_society_migration_cleanup' );
		update_option( 'gend_society_origin_cleanup_done', time(), false );
		return true;
	}
}

if ( ! function_exists( 'gend_society_origin_park_cron' ) ) {
	/**
	 * Park the origin's WP-Cron, reversibly (P5, mirrors gdc_container_migration_retired_cron_guard on the hub): the cron
	 * option is copied ONCE to gend_society_origin_cron_backup, replaced by an empty schedule, and a pre_schedule_event
	 * guard (active while gend_society_origin_moved is set) keeps plugins from rescheduling. Idempotent. Nothing but
	 * the cron option changes; no content, table or upload is touched.
	 *
	 * @param string $to The container address (stored as gend_society_origin_moved).
	 * @return bool True when this call parked it, false when it already was (or this is not an origin that moved).
	 */
	function gend_society_origin_park_cron( string $to = '' ): bool {
		$moved = gend_society_origin_moved();
		if ( ! $moved['moved'] ) {
			return false;
		}
		$to = '' !== $to ? $to : $moved['to'];
		update_option( 'gend_society_origin_moved', $to, true ); // first, so the guard is already active for this request
		if ( false !== get_option( 'gend_society_origin_cron_backup', false ) ) {
			return false; // parked already: the backup is the ORIGINAL schedule, never overwritten
		}
		$cron = get_option( 'cron', array() );
		add_option( 'gend_society_origin_cron_backup', is_array( $cron ) ? $cron : array( 'version' => 2 ), '', false );
		update_option( 'cron', array( 'version' => 2 ) );
		return true;
	}
}

if ( ! function_exists( 'gend_society_origin_unpark_cron' ) ) {
	/**
	 * Undo gend_society_origin_park_cron(): the saved schedule comes back, the two options go and the guard stops
	 * (it only acts while gend_society_origin_moved is set). Used by `wp gend-society origin-cron unpark`, by deleting
	 * option gend_society_origin_moved and by a hub rollback (live=false).
	 *
	 * @return bool True when a parked schedule was restored.
	 */
	function gend_society_origin_unpark_cron(): bool {
		$backup = get_option( 'gend_society_origin_cron_backup', false );
		delete_option( 'gend_society_origin_moved' ); // the guard is off from here
		if ( false === $backup ) {
			return false;
		}
		update_option( 'cron', $backup );
		delete_option( 'gend_society_origin_cron_backup' );
		delete_option( 'gend_society_origin_cleanup_done' ); // a later Live can clean up again
		return true;
	}
}

if ( ! function_exists( 'gend_society_origin_cron_guard' ) ) {
	/**
	 * pre_schedule_event while parked: nothing may be scheduled (false = not scheduled, the documented short circuit).
	 *
	 * @param null|bool $pre Short-circuit value.
	 */
	function gend_society_origin_cron_guard( $pre ) {
		return false !== get_option( 'gend_society_origin_moved', false ) ? false : $pre;
	}
}
add_filter( 'pre_schedule_event', 'gend_society_origin_cron_guard', 0 );

if ( ! function_exists( 'gend_society_origin_cron_reconcile' ) ) {
	/**
	 * Deleting option gend_society_origin_moved (by hand) unparks too: a leftover backup without the marker is
	 * restored on the next load.
	 */
	function gend_society_origin_cron_reconcile(): void {
		if ( false === get_option( 'gend_society_origin_moved', false ) && false !== get_option( 'gend_society_origin_cron_backup', false ) ) {
			gend_society_origin_unpark_cron();
		}
	}
}
add_action( 'init', 'gend_society_origin_cron_reconcile', 0 );

if ( ! function_exists( 'gend_society_origin_cron_cli' ) ) {
	/**
	 * wp gend-society origin-cron <unpark|status>  (the command is 'gend-society'; its first argument selects the topic)
	 *
	 * @param array $args Positional arguments: origin-cron, then unpark | status.
	 */
	function gend_society_origin_cron_cli( $args = array() ): void {
		if ( 'origin-cron' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Usage: wp gend-society origin-cron <unpark|status>' );
			return;
		}
		$verb = isset( $args[1] ) ? (string) $args[1] : 'status';
		if ( 'unpark' === $verb ) {
			$done = gend_society_origin_unpark_cron();
			\WP_CLI::success( $done ? 'WP-Cron restored from gend_society_origin_cron_backup.' : 'WP-Cron was not parked.' );
			return;
		}
		$parked = false !== get_option( 'gend_society_origin_cron_backup', false );
		\WP_CLI::line( $parked ? 'parked (moved to ' . (string) get_option( 'gend_society_origin_moved', '' ) . ')' : 'not parked' );
	}
}
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command( 'gend-society', 'gend_society_origin_cron_cli', array( 'shortdesc' => 'Origin hand-over tools (origin-cron unpark|status).' ) );
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
		$url = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?gs_connect=1' ) . '#gs-connect';
		return '<div class="notice notice-warning is-dismissible gs-connection-health-notice" data-gs-health-checked="' . esc_attr( (string) (int) ( $health['checked_at'] ?? 0 ) ) . '"><p>'
			. esc_html__( "gend.me no longer recognises this site's connection.", 'gend-society' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Reconnect', 'gend-society' ) . '</a></p></div>';
	}
}

if ( ! function_exists( 'gend_society_origin_activation_text' ) ) {
	/**
	 * MIG-15: "Store, Social and Projects now active" from the hub's features list (labels joined in the site language).
	 *
	 * @param array $features The /membership payload's features: [ { slug, label }, ... ].
	 * @return string '' when the list is empty.
	 */
	function gend_society_origin_activation_text( array $features ): string {
		$labels = array();
		foreach ( $features as $f ) {
			$l = is_array( $f ) && isset( $f['label'] ) && is_string( $f['label'] ) ? trim( wp_strip_all_tags( $f['label'] ) ) : '';
			if ( '' !== $l ) {
				$labels[] = $l;
			}
		}
		if ( ! $labels ) {
			return '';
		}
		/* translators: %s: list of feature names, e.g. "Store, Social and Projects". */
		return sprintf( __( '%s now active', 'gend-society' ), wp_sprintf_l( '%l', $labels ) );
	}
}

if ( ! function_exists( 'gend_society_origin_hub_features' ) ) {
	/**
	 * The activation list the origin shows after Live: the cached /membership payload's features (the stored cache only,
	 * no request; expiry ignored), and only those reported at or after migrated_at.
	 *
	 * @return array<int, array{slug:string,label:string}>
	 */
	function gend_society_origin_hub_features(): array {
		$moved = gend_society_origin_moved();
		if ( ! $moved['moved'] ) {
			return array();
		}
		$p = get_option( 'gend_society_remote_membership_cache', array() );
		if ( ! is_array( $p ) || ! is_array( $p['features'] ?? null ) ) {
			return array();
		}
		$at = isset( $p['features_reported_at'] ) ? (int) $p['features_reported_at'] : 0;
		if ( $at > 0 && $moved['at'] > 0 && $at < $moved['at'] ) {
			return array(); // an older report of the origin itself
		}
		$out = array();
		foreach ( $p['features'] as $f ) {
			if ( is_array( $f ) && isset( $f['slug'], $f['label'] ) && is_string( $f['slug'] ) && is_string( $f['label'] ) ) {
				$out[] = array(
					'slug'  => sanitize_key( $f['slug'] ),
					'label' => (string) $f['label'],
				);
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_origin_moved_notice_html' ) ) {
	/**
	 * The "This site has moved" notice (same dismissible slot and mechanism as the Reconnect notice).
	 *
	 * @param array $moved Result of gend_society_origin_moved().
	 */
	function gend_society_origin_moved_notice_html( array $moved ): string {
		$to   = (string) ( $moved['to'] ?? '' );
		$act  = gend_society_origin_activation_text( gend_society_origin_hub_features() );
		$tail = '' !== $act ? $act . '.' : __( 'We will list your active features here once your new site has reported them.', 'gend-society' );
		return '<div class="notice notice-info is-dismissible gs-connection-health-notice gs-moved-notice" data-gs-health-checked="' . esc_attr( 'moved:' . $to ) . '"><p>'
			. esc_html__( 'This site has moved to', 'gend-society' )
			. ' <a href="' . esc_url( $to ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $to ) . '</a>. '
			. esc_html__( 'Your original site has not been changed; to go back, switch your domain back.', 'gend-society' )
			. ' <span class="gs-moved-activation">' . esc_html( $tail ) . '</span></p></div>';
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
		$dismissed = (string) get_user_meta( get_current_user_id(), 'gend_society_health_dismissed', true );
		$moved     = gend_society_origin_moved();
		if ( $moved['moved'] ) {
			// 111-12 (MIG-11): the moved notice takes the slot; the Reconnect notice is suppressed while it applies.
			return 'moved:' . $moved['to'] !== $dismissed;
		}
		$h = gend_society_connection_health_get();
		if ( 'unauthorized' !== $h['status'] ) {
			return false;
		}
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
		$moved = gend_society_origin_moved();
		echo $moved['moved'] ? gend_society_origin_moved_notice_html( $moved ) : gend_society_connection_health_notice_html( gend_society_connection_health_get() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the builders.
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
		$moved = gend_society_origin_moved();
		if ( $moved['moved'] ) {
			$out['moved'] = true;
			if ( gend_society_connection_health_notice_visible() ) {
				$out['notice_html'] = gend_society_origin_moved_notice_html( $moved );
			}
		} elseif ( 'unauthorized' === $result['status'] ) {
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
		$raw = isset( $_POST['checked_at'] ) ? sanitize_text_field( wp_unslash( $_POST['checked_at'] ) ) : '';
		if ( 0 === strpos( $raw, 'moved:' ) ) {
			// 111-12: the moved notice is dismissed per migrated_to address (a different address shows it again).
			update_user_meta( get_current_user_id(), 'gend_society_health_dismissed', 'moved:' . esc_url_raw( substr( $raw, 6 ), array( 'http', 'https' ) ) );
			wp_send_json( array( 'status' => 'dismissed' ) );
		}
		$checked = absint( $raw );
		if ( $checked <= 0 ) {
			$checked = gend_society_connection_health_get()['checked_at'];
		}
		update_user_meta( get_current_user_id(), 'gend_society_health_dismissed', (string) $checked );
		wp_send_json( array( 'status' => 'dismissed' ) );
	}
}
