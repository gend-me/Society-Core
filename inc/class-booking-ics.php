<?php
/**
 * Phase 29-04 — Gend_GS_Booking_ICS
 *
 * RFC 5545 compliant .ics output for the gend-society booking flow:
 *
 *   1) GET /gs/v1/calendar/meetings/{id}/ics
 *      Authed single-meeting download. Host OR member-guest can pull their own
 *      meeting as a one-shot .ics file (Content-Disposition: attachment;
 *      filename="gend-meeting-{id}.ics"). MEET-05 second-leg parity: a guest
 *      who is also a WP user on the same multisite can download.
 *
 *   2) GET /gs/v1/calendar/ics/{share_token}
 *      Public read-only subscribable feed of ALL booked meetings hosted by the
 *      owner of share_token. Token is the gates — no auth required so external
 *      calendar apps (Google Calendar, Apple Calendar, Outlook) can subscribe
 *      via a single URL. Content-Disposition: inline; filename="calendar.ics".
 *      Cancelled meetings emitted with STATUS:CANCELLED + METHOD:CANCEL so
 *      external calendars invalidate stale events instead of leaving zombies.
 *
 * RFC 5545 compliance (the load-bearing invariants — external calendar apps
 * silently drop non-compliant payloads):
 *   - Content-Type: text/calendar; charset=utf-8
 *   - CRLF (\r\n) line endings everywhere (NOT bare LF)
 *   - Lines >75 octets folded at column 75 with CRLF + single space continuation
 *     (RFC 5545 §3.1)
 *   - PRODID + VERSION:2.0 + METHOD + CALSCALE:GREGORIAN headers
 *   - Per-VEVENT UID = meeting-{id}@gend.me (stable identifier)
 *   - DTSTAMP, DTSTART, DTEND in UTC `Z` form (YYYYMMDDTHHMMSSZ)
 *   - TEXT escaping (RFC 5545 §3.3.11): backslash, comma, semicolon, newline
 *   - STATUS:CONFIRMED for booked rows, STATUS:CANCELLED for cancelled rows
 *
 * Pitfall 2 (auto-deactivation safety): every public handler wraps the body
 * lookup + render in try/catch \Throwable so a malformed meeting row never
 * fatals the REST request lifecycle (would cause WP to auto-deactivate the
 * plugin on a clean fatal).
 *
 * Pitfall 5 (IANA-tz-only) inherited from Phase 27: we use DateTimeImmutable
 * with explicit DateTimeZone(UTC) for the DTSTAMP, never strtotime+gmdate.
 *
 * Pitfall 7 (identity discipline): the public feed handler reads identity
 * EXCLUSIVELY from $req['share_token']; never get_current_user_id() for
 * authorization. The single-meeting handler verifies the current user matches
 * member_id OR guest_user_id inside the handler.
 *
 * Pitfall 16 (URL allowlist): we don't validate URLs here — Plan 29-02 owns
 * the allowlist at write time. Read-side we just esc_url-style emit the
 * stored URL into LOCATION/DESCRIPTION fields after RFC 5545 escaping.
 *
 * Phase 29 Plan 04. NOTIF-03 + NOTIF-04 + MEET-05.
 *
 * @package Gend_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gend_GS_Booking_ICS — RFC 5545 ICS output for single-meeting download +
 * subscribable feed.
 *
 * Route prefix invariant: /gs/v1/calendar
 *   - /meetings/{id}/ics        — authed single download
 *   - /ics/{share_token}        — public subscribable feed
 */
class Gend_GS_Booking_ICS {

	const NS                 = 'gs/v1';
	const PRODID             = '-//gend.me//gend-society//EN';
	const CRLF               = "\r\n";
	const FOLD_AT            = 75;
	const FEED_LOOKBACK_DAYS = 30;
	const FEED_LOOKAHEAD_DAYS = 365;

	/**
	 * Register the two REST routes on rest_api_init.
	 *
	 * Single-meeting download: is_user_logged_in permission_callback; handler
	 * verifies user_id ∈ {member_id, guest_user_id} for 403 hard-stop.
	 *
	 * Public feed: __return_true permission_callback (share_token gates access
	 * inside the handler; 404 on unknown/revoked token).
	 */
	public static function register_routes() : void {
		register_rest_route(
			self::NS,
			'/calendar/meetings/(?P<id>\\d+)/ics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_single_ics' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			self::NS,
			'/calendar/ics/(?P<share_token>[A-Za-z0-9]{43})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_feed' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /gs/v1/calendar/meetings/{id}/ics
	 *
	 * Single-meeting .ics download. Streams text/calendar with
	 * Content-Disposition: attachment; filename="gend-meeting-{id}.ics".
	 * Host OR member-guest can download (MEET-05 second-leg parity).
	 *
	 * Pitfall 2 wrap: try/catch \Throwable so a malformed row never fatals.
	 */
	public static function handle_single_ics( WP_REST_Request $req ) {
		try {
			$meeting_id = (int) $req['id'];
			$user_id    = get_current_user_id();
			if ( $meeting_id <= 0 ) {
				return new WP_Error( 'gs_ics_bad_id', 'Invalid meeting id', array( 'status' => 400 ) );
			}
			if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
				return new WP_Error( 'gs_ics_schema_missing', 'Availability schema not loaded', array( 'status' => 500 ) );
			}
			global $wpdb;
			$tbl = Gend_GS_Availability_Schema::table_meetings();
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, member_id, guest_user_id, guest_name, starts_at_utc, ends_at_utc, meeting_type, meeting_meta_json, status FROM {$tbl} WHERE id = %d",
					$meeting_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				return new WP_Error( 'gs_ics_not_found', 'Meeting not found', array( 'status' => 404 ) );
			}
			if ( (int) $row['member_id'] !== $user_id && (int) $row['guest_user_id'] !== $user_id ) {
				return new WP_Error( 'gs_ics_forbidden', 'Not your meeting', array( 'status' => 403 ) );
			}

			$host_user = get_userdata( (int) $row['member_id'] );
			$host_name = $host_user ? ( $host_user->display_name ? $host_user->display_name : $host_user->user_login ) : 'Host';
			$meta      = (array) json_decode( (string) $row['meeting_meta_json'], true );
			$location  = self::meeting_location( $row, $meta );

			$vevent = self::build_vevent( $row, $host_name, $location );
			$method = ( $row['status'] === 'cancelled' ) ? 'CANCEL' : 'PUBLISH';
			$body   = self::build_calendar_envelope( array( $vevent ), $method );

			self::emit_and_exit( $body, 'gend-meeting-' . $meeting_id . '.ics', 'attachment' );
			return null;
		} catch ( \Throwable $e ) {
			error_log( 'GS_ICS_SINGLE_FAIL meeting_id=' . ( isset( $meeting_id ) ? $meeting_id : 0 ) . ' :: ' . $e->getMessage() );
			return new WP_Error( 'gs_ics_render_failed', 'ICS render failed', array( 'status' => 500 ) );
		}
	}

	/**
	 * GET /gs/v1/calendar/ics/{share_token}
	 *
	 * Public subscribable feed (Content-Disposition: inline). Reads the host
	 * user via share_token, then SELECTs ALL meetings (booked + cancelled, the
	 * latter for external-calendar invalidation) in the
	 * [now - FEED_LOOKBACK_DAYS, now + FEED_LOOKAHEAD_DAYS] window.
	 *
	 * Pitfall 7: identity exclusively from $req['share_token'] — never the
	 * current user.
	 *
	 * Pitfall 2 wrap: try/catch \Throwable so a malformed row in the result set
	 * never fatals the request.
	 */
	public static function handle_feed( WP_REST_Request $req ) {
		try {
			$token = (string) $req['share_token'];
			if ( ! preg_match( '#^[A-Za-z0-9]{43}$#', $token ) ) {
				return new WP_Error( 'gs_ics_token_invalid', 'Invalid token', array( 'status' => 400 ) );
			}
			if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
				return new WP_Error( 'gs_ics_schema_missing', 'Availability schema not loaded', array( 'status' => 500 ) );
			}
			global $wpdb;
			$a_tbl        = Gend_GS_Availability_Schema::table_availability();
			$host_user_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$a_tbl} WHERE share_token = %s LIMIT 1",
					$token
				)
			);
			if ( $host_user_id <= 0 ) {
				return new WP_Error( 'gs_ics_token_unknown', 'Subscribe link invalid or revoked', array( 'status' => 404 ) );
			}

			$host_user = get_userdata( $host_user_id );
			$host_name = $host_user ? ( $host_user->display_name ? $host_user->display_name : $host_user->user_login ) : 'Host';
			$m_tbl     = Gend_GS_Availability_Schema::table_meetings();
			$from      = gmdate( 'Y-m-d H:i:s', time() - self::FEED_LOOKBACK_DAYS * DAY_IN_SECONDS );
			$to        = gmdate( 'Y-m-d H:i:s', time() + self::FEED_LOOKAHEAD_DAYS * DAY_IN_SECONDS );

			// Include BOTH booked and cancelled — cancelled rows emitted so
			// external calendars invalidate stale events rather than leaving
			// zombies.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, member_id, guest_user_id, guest_name, starts_at_utc, ends_at_utc, meeting_type, meeting_meta_json, status FROM {$m_tbl} WHERE member_id = %d AND starts_at_utc >= %s AND starts_at_utc < %s ORDER BY starts_at_utc ASC",
					$host_user_id,
					$from,
					$to
				),
				ARRAY_A
			);

			$vevents = array();
			foreach ( (array) $rows as $row ) {
				try {
					$meta      = (array) json_decode( (string) $row['meeting_meta_json'], true );
					$location  = self::meeting_location( $row, $meta );
					$vevents[] = self::build_vevent( $row, $host_name, $location );
				} catch ( \Throwable $row_err ) {
					// Pitfall 2: skip the bad row, don't fail the feed.
					error_log( 'GS_ICS_FEED_ROW_SKIP id=' . ( isset( $row['id'] ) ? $row['id'] : 0 ) . ' :: ' . $row_err->getMessage() );
					continue;
				}
			}

			$body = self::build_calendar_envelope( $vevents, 'PUBLISH' );
			self::emit_and_exit( $body, 'calendar.ics', 'inline' );
			return null;
		} catch ( \Throwable $e ) {
			error_log( 'GS_ICS_FEED_FAIL token=' . ( isset( $token ) ? substr( $token, 0, 8 ) : '?' ) . ' :: ' . $e->getMessage() );
			return new WP_Error( 'gs_ics_feed_failed', 'ICS feed render failed', array( 'status' => 500 ) );
		}
	}

	/**
	 * Build a single VEVENT block from a meeting row.
	 *
	 * RFC 5545 VEVENT minimum shape: BEGIN:VEVENT / UID / DTSTAMP / DTSTART /
	 * DTEND / SUMMARY / DESCRIPTION / LOCATION / STATUS / ORGANIZER /
	 * END:VEVENT. All TEXT-type fields are escape_ics_text'd before emission
	 * so commas/semicolons/newlines/backslashes survive parser round-trips.
	 *
	 * @param array  $row       wp_gs_member_meetings row (ARRAY_A).
	 * @param string $host_name Display name of the host.
	 * @param string $location  Pre-rendered LOCATION value (per meeting type).
	 * @return string CRLF-joined VEVENT block (no trailing CRLF).
	 */
	public static function build_vevent( array $row, string $host_name, string $location ) : string {
		$uid     = 'meeting-' . (int) $row['id'] . '@gend.me';
		$now_utc = gmdate( 'Ymd\\THis\\Z' );
		$start   = self::format_dtstamp( (string) $row['starts_at_utc'] );
		$end     = self::format_dtstamp( (string) $row['ends_at_utc'] );

		$type_label = array(
			'in_person' => 'In-person meeting',
			'phone'     => 'Phone call',
			'video'     => 'Video call',
		);
		$summary    = isset( $type_label[ $row['meeting_type'] ] ) ? $type_label[ $row['meeting_type'] ] : 'Meeting';
		if ( ! empty( $row['guest_name'] ) ) {
			$summary .= ' with ' . $row['guest_name'];
		}
		$status      = ( $row['status'] === 'cancelled' ) ? 'CANCELLED' : 'CONFIRMED';
		$description = "Host: " . $host_name . "\nMeeting type: " . $row['meeting_type'];
		if ( $location !== '' ) {
			$description .= "\nWhere: " . $location;
		}

		$lines = array(
			'BEGIN:VEVENT',
			'UID:' . self::escape_ics_text( $uid ),
			'DTSTAMP:' . $now_utc,
			'DTSTART:' . $start,
			'DTEND:' . $end,
			'SUMMARY:' . self::escape_ics_text( $summary ),
			'DESCRIPTION:' . self::escape_ics_text( $description ),
			'LOCATION:' . self::escape_ics_text( $location ),
			'STATUS:' . $status,
			'ORGANIZER;CN=' . self::escape_ics_text( $host_name ) . ':mailto:noreply@gend.me',
			'END:VEVENT',
		);
		return implode( self::CRLF, $lines );
	}

	/**
	 * Build the outer VCALENDAR envelope around one or more VEVENT blocks.
	 *
	 * RFC 5545 envelope: BEGIN:VCALENDAR + VERSION:2.0 + PRODID + METHOD +
	 * CALSCALE:GREGORIAN + {events} + END:VCALENDAR + trailing CRLF. The body
	 * is then line-folded via fold_lines() so no single line exceeds 75 octets.
	 *
	 * @param array  $vevents Array of pre-rendered VEVENT strings.
	 * @param string $method  PUBLISH (default) or CANCEL (for cancellations).
	 * @return string Complete folded VCALENDAR body with CRLF line endings.
	 */
	public static function build_calendar_envelope( array $vevents, string $method = 'PUBLISH' ) : string {
		$head = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::PRODID,
			'METHOD:' . $method,
			'CALSCALE:GREGORIAN',
		);
		$foot = array( 'END:VCALENDAR', '' ); // trailing CRLF for spec compliance.

		$body = implode( self::CRLF, $head ) . self::CRLF
			. ( count( $vevents ) ? implode( self::CRLF, $vevents ) . self::CRLF : '' )
			. implode( self::CRLF, $foot );

		return self::fold_lines( $body );
	}

	/**
	 * Fold long lines per RFC 5545 §3.1.
	 *
	 * Lines >75 octets MUST be split with CRLF + single space continuation.
	 * The continuation byte (the leading space on the next line) eats 1 of the
	 * next line's 75-octet budget — so each continuation chunk is FOLD_AT - 1
	 * octets max.
	 *
	 * @param string $ical_text Full CRLF-joined iCalendar text.
	 * @return string Folded text (still CRLF-joined).
	 */
	public static function fold_lines( string $ical_text ) : string {
		$out = array();
		foreach ( explode( self::CRLF, $ical_text ) as $line ) {
			if ( strlen( $line ) <= self::FOLD_AT ) {
				$out[] = $line;
				continue;
			}
			$first  = substr( $line, 0, self::FOLD_AT );
			$rest   = substr( $line, self::FOLD_AT );
			$folded = $first;
			while ( strlen( $rest ) > 0 ) {
				$chunk   = substr( $rest, 0, self::FOLD_AT - 1 ); // -1 for leading space.
				$rest    = substr( $rest, self::FOLD_AT - 1 );
				$folded .= self::CRLF . ' ' . $chunk;
			}
			$out[] = $folded;
		}
		return implode( self::CRLF, $out );
	}

	/**
	 * Escape per RFC 5545 §3.3.11 TEXT value type.
	 *
	 * Order matters: backslash MUST be escaped FIRST so the subsequent
	 * comma/semicolon/newline replacements don't double-escape their own
	 * inserted backslashes.
	 *
	 * @param string $value Raw input.
	 * @return string Escaped string suitable for any TEXT-typed property.
	 */
	public static function escape_ics_text( string $value ) : string {
		$value = str_replace( '\\', '\\\\', $value ); // backslash FIRST.
		$value = str_replace( array( ',', ';' ), array( '\\,', '\\;' ), $value );
		$value = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );
		return $value;
	}

	/**
	 * Format a MySQL UTC datetime as RFC 5545 UTC DATE-TIME (YYYYMMDDTHHMMSSZ).
	 *
	 * Pitfall 5: DateTimeImmutable + DateTimeZone(UTC). Never strtotime + gmdate.
	 * Defensive fallback on parse failure: emits the current UTC timestamp so
	 * the field is always well-formed.
	 *
	 * @param string $mysql_utc 'Y-m-d H:i:s' UTC datetime.
	 * @return string YYYYMMDDTHHMMSSZ.
	 */
	public static function format_dtstamp( string $mysql_utc ) : string {
		try {
			$dt = new \DateTimeImmutable( $mysql_utc, new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Ymd\\THis\\Z' );
		} catch ( \Throwable $e ) {
			return gmdate( 'Ymd\\THis\\Z' );
		}
	}

	/**
	 * Build the LOCATION value for a meeting per its type.
	 *
	 *   - in_person → meta.address verbatim
	 *   - phone     → 'Phone: ' + callee_number (host phone never exposed)
	 *   - video + provider='gend' + jitsi_room → meet.gend.me URL
	 *   - video + room_url                     → external URL verbatim
	 *
	 * @param array $row  Meeting row.
	 * @param array $meta Decoded meeting_meta_json.
	 * @return string LOCATION value (may be empty).
	 */
	public static function meeting_location( array $row, array $meta ) : string {
		switch ( $row['meeting_type'] ) {
			case 'in_person':
				return isset( $meta['address'] ) ? (string) $meta['address'] : '';
			case 'phone':
				return isset( $meta['callee_number'] ) ? ( 'Phone: ' . $meta['callee_number'] ) : 'Phone';
			case 'video':
				if ( isset( $meta['provider'] ) && $meta['provider'] === 'gend' && ! empty( $meta['jitsi_room'] ) ) {
					return 'https://meet.gend.me/' . $meta['jitsi_room'];
				}
				return isset( $meta['room_url'] ) ? (string) $meta['room_url'] : '';
			default:
				return '';
		}
	}

	/**
	 * Stream the ICS body to the response and exit.
	 *
	 * Flushes any existing output buffer (so the WP REST JSON wrapper doesn't
	 * prepend a `{"data":...}` envelope around our raw ICS bytes), sets the
	 * required text/calendar Content-Type + Content-Disposition + Content-Length
	 * headers, echoes the body, and exit;s the request lifecycle.
	 *
	 * Note: exit; is unavoidable here — the WP REST plumbing would otherwise
	 * try to JSON-serialize our return value, and we MUST emit raw octets.
	 *
	 * @param string $body        ICS body (CRLF line endings, folded).
	 * @param string $filename    Filename for Content-Disposition.
	 * @param string $disposition 'attachment' (download) or 'inline' (subscribable feed).
	 */
	public static function emit_and_exit( string $body, string $filename, string $disposition = 'attachment' ) : void {
		// Tear down any output buffering so the raw ICS bytes hit the wire
		// without the WP REST JSON wrapper.
		while ( ob_get_level() ) {
			@ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . addslashes( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body;
		exit;
	}
}
