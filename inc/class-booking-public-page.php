<?php
/**
 * Phase 29-04 — Gend_GS_Booking_Public_Page
 *
 * The public-facing booking UI at /calendar-book/{share_token}/.
 *
 * Implementation:
 *   - Hooks template_redirect (high priority so we intercept BEFORE 404
 *     handlers fire).
 *   - Matches REQUEST_URI against /calendar-book/{43-char-alphanumeric}/?
 *     via preg_match (NOT WP rewrite rules — avoids flush_rules requirement
 *     per CONTEXT.md §decisions §"NEW route via template_redirect hook").
 *   - Resolves the host via Gend_GS_Availability_Schema::table_availability()
 *     SELECT WHERE share_token = ... ; 404 (with minimal HTML body) on
 *     unknown/revoked tokens.
 *   - Streams a full standalone HTML document (NOT a WP theme template) so the
 *     public page is independent of whatever theme the multisite uses. This
 *     IS the booking UI.
 *
 * Pitfall 1: include guard handled by gend-society.php's file_exists block.
 *
 * Pitfall 7: identity exclusively from the URL path; never get_current_user_id
 * for any authorization decision (the page is public — there may BE no current
 * user).
 *
 * Pitfall 2: try/catch \Throwable around the render path so a malformed
 * booking_settings_json row never fatals the entire request.
 *
 * @package Gend_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-page handler — template_redirect interceptor for /calendar-book/{share_token}.
 */
class Gend_GS_Booking_Public_Page {

	const PATH_PREFIX = '/calendar-book/';

	/**
	 * Wire up template_redirect.
	 *
	 * Priority 5 (default 10) so we run BEFORE WP's 404 handling.
	 */
	public static function init() : void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_template_redirect' ), 5 );
	}

	/**
	 * template_redirect handler.
	 *
	 * Inspects REQUEST_URI; if it matches /calendar-book/{43-char-alphanumeric}
	 * we hand off to render_booking_page() (which exits). Otherwise no-op so
	 * normal WP routing continues.
	 */
	public static function handle_template_redirect() : void {
		try {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$path = parse_url( $uri, PHP_URL_PATH );
			if ( ! is_string( $path ) || $path === '' ) {
				return;
			}
			$pattern = '#^' . preg_quote( self::PATH_PREFIX, '#' ) . '([A-Za-z0-9]{43})/?$#';
			if ( ! preg_match( $pattern, $path, $m ) ) {
				return;
			}
			$share_token = $m[1];
			self::render_booking_page( $share_token );
			// render_booking_page exits.
		} catch ( \Throwable $e ) {
			error_log( 'GS_PUBLIC_PAGE_FAIL :: ' . $e->getMessage() );
			// Fall through silently — let WP serve its native 404 rather than
			// auto-deactivating the plugin via a clean fatal.
		}
	}

	/**
	 * Render and stream the full standalone booking-page HTML document.
	 *
	 * Resolves host via share_token, builds the inline JSON payload the
	 * booking-public.js controller hydrates from, enqueues booking-public.css
	 * + booking-public.js, exits.
	 *
	 * @param string $share_token 43-char alphanumeric token.
	 */
	public static function render_booking_page( string $share_token ) : void {
		try {
			if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
				self::send_error( 500, 'Availability schema not loaded' );
				return;
			}
			global $wpdb;
			$a_tbl = Gend_GS_Availability_Schema::table_availability();
			$row   = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT user_id, timezone, booking_settings_json FROM {$a_tbl} WHERE share_token = %s LIMIT 1",
					$share_token
				)
			);
			if ( ! $row ) {
				self::send_error( 404, 'Booking link invalid or revoked' );
				return;
			}

			$host_user = get_userdata( (int) $row->user_id );
			if ( ! $host_user ) {
				self::send_error( 404, 'Host account not found' );
				return;
			}
			$host_name = $host_user->display_name ? $host_user->display_name : $host_user->user_login;
			$member_tz = (string) ( isset( $row->timezone ) && $row->timezone ? $row->timezone : 'UTC' );

			$booking_settings = (array) json_decode( (string) ( isset( $row->booking_settings_json ) ? $row->booking_settings_json : '{}' ), true );
			$named_durations  = ( ! empty( $booking_settings['named_durations'] ) && is_array( $booking_settings['named_durations'] ) )
				? $booking_settings['named_durations']
				: array(
					array( 'label' => 'Quick Sync',     'minutes' => 15 ),
					array( 'label' => 'Discovery Call', 'minutes' => 30 ),
					array( 'label' => 'Workshop',       'minutes' => 60 ),
				);
			$meeting_types = ( ! empty( $booking_settings['enabled_meeting_types'] ) && is_array( $booking_settings['enabled_meeting_types'] ) )
				? array_values( array_intersect( array( 'in_person', 'phone', 'video' ), $booking_settings['enabled_meeting_types'] ) )
				: array( 'in_person', 'phone', 'video' );
			$meeting_type_options = array();
			$mt_labels = array(
				'in_person' => 'In person',
				'phone'     => 'Phone',
				'video'     => 'Video',
			);
			foreach ( $meeting_types as $mt ) {
				if ( isset( $mt_labels[ $mt ] ) ) {
					$meeting_type_options[] = array( 'value' => $mt, 'label' => $mt_labels[ $mt ] );
				}
			}

			$payload = array(
				'restUrl'        => esc_url_raw( rest_url( 'gs/v1/' ) ),
				'shareToken'     => $share_token,
				'hostName'       => $host_name,
				'memberTz'       => $member_tz,
				'namedDurations' => array_values( $named_durations ),
				'meetingTypes'   => $meeting_type_options,
			);

			$js_url  = plugins_url( 'assets/booking-public.js', GS_DIR . 'gend-society.php' );
			$css_url = plugins_url( 'assets/booking-public.css', GS_DIR . 'gend-society.php' );
			$ver     = defined( 'GS_VERSION' ) ? GS_VERSION : '1.0.0';

			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );

			echo '<!doctype html><html lang="en"><head>';
			echo '<meta charset="utf-8">';
			echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
			echo '<title>' . esc_html( 'Book a meeting with ' . $host_name ) . '</title>';
			echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '?v=' . esc_attr( $ver ) . '">';
			echo '</head><body>';
			echo '<div id="gs-booking-root" class="gs-booking-root">';
			echo '<noscript><p>This booking page requires JavaScript.</p></noscript>';
			echo '</div>';
			echo '<script>window.gsBookingData=' . wp_json_encode( $payload ) . ';</script>';
			echo '<script src="' . esc_url( $js_url ) . '?v=' . esc_attr( $ver ) . '" defer></script>';
			echo '</body></html>';

			// We are owning the entire response — exit so WP doesn't try to
			// render a theme template on top.
			exit;
		} catch ( \Throwable $e ) {
			error_log( 'GS_PUBLIC_PAGE_RENDER_FAIL token=' . substr( $share_token, 0, 8 ) . ' :: ' . $e->getMessage() );
			self::send_error( 500, 'Booking page render failed' );
		}
	}

	/**
	 * Emit a minimal standalone HTML error response and exit.
	 *
	 * @param int    $code HTTP status (404 / 500).
	 * @param string $msg  Human-readable message rendered in the body.
	 */
	private static function send_error( int $code, string $msg ) : void {
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
			. esc_html( $msg )
			. '</title><style>html,body{margin:0;padding:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0a0518;color:#fff}h1{font-size:20px;font-weight:500}</style></head><body><h1>'
			. esc_html( $msg )
			. '</h1></body></html>';
		exit;
	}
}
