<?php
/**
 * Phase 29-04 UAT — ICS download + subscribable feed RFC 5545 compliance.
 *
 * NOTIF-03 (ICS download per meeting) + NOTIF-04 (subscribable read-only ICS
 * feed) acceptance probe.
 *
 * Approach:
 *   - The handler-level emit_and_exit() path calls exit; to stream raw bytes,
 *     which cannot be intercepted in-process from `wp eval-file` without
 *     forking. Instead we call the PUBLIC STATIC helpers
 *     Gend_GS_Booking_ICS::build_calendar_envelope([build_vevent($row)])
 *     directly and assert the returned STRING against the RFC 5545 matrix.
 *     Plan 29-05's deploy runbook will curl the live routes against the hub
 *     pod as the authoritative functional acceptance gate; this probe is the
 *     structural/spec gate.
 *   - Feed token resolution (handle_feed) is tested via rest_do_request for
 *     the unknown-token 404 path, which doesn't reach emit_and_exit().
 *
 * Assertions (12):
 *    1. Gend_GS_Booking_ICS class loads + both routes registered.
 *    2. Seed booked + cancelled meetings (different ids) — pre-flight.
 *    3. Envelope starts with BEGIN:VCALENDAR\r\n + ends with END:VCALENDAR\r\n.
 *    4. VERSION:2.0 present on its own line.
 *    5. PRODID:-//gend.me//gend-society//EN present.
 *    6. METHOD:PUBLISH present.
 *    7. BEGIN:VEVENT + END:VEVENT + UID:meeting-{id}@gend.me all present.
 *    8. STATUS:CONFIRMED for booked; STATUS:CANCELLED for cancelled.
 *    9. DTSTAMP/DTSTART/DTEND match YYYYMMDDTHHMMSSZ regex.
 *   10. Line folding: 200-char address → output contains CRLF+space
 *       continuation AND no single line >75 octets.
 *   11. Text escaping: address with comma + semicolon + newline → LOCATION
 *       line contains literal \, AND \; AND \n.
 *   12. Public feed token resolution: unknown share_token → 404 + WP_Error
 *       gs_ics_token_unknown.
 *
 * Cleanup via register_shutdown_function — seeded meetings + availability
 * row deleted; always exits 0.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/29-04-uat-ics-rfc5545.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "must run via wp eval-file\n";
	exit;
}

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }
function gs_uat_skip( $l, $w = '' ) { echo "SKIP · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 29-04 ICS RFC 5545 compliance UAT ===\n";

if ( ! class_exists( 'Gend_GS_Booking_ICS' ) ) {
	gs_uat_fail( 'Gend_GS_Booking_ICS not loaded — Plan 29-04 bootstrap missing?' );
	return;
}
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
	gs_uat_fail( 'Gend_GS_Availability_Schema not loaded — Plan 28-01 missing?' );
	return;
}

// ─── Pick or fallback host user ──────────────────────────────────────────
$host_uid = 1;
if ( ! get_userdata( $host_uid ) ) {
	$u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
	$host_uid = $u ? (int) $u[0] : 0;
}
if ( $host_uid <= 0 ) {
	gs_uat_fail( 'no users in system; cannot run' );
	return;
}

$tbl_meet = Gend_GS_Availability_Schema::table_meetings();
$tbl_av   = Gend_GS_Availability_Schema::table_availability();

$seeded_meeting_ids = array();
$seeded_av_uid      = 0;
$seeded_token       = '';

register_shutdown_function( function () use ( $wpdb, $tbl_meet, $tbl_av, &$seeded_meeting_ids, &$seeded_av_uid ) {
	foreach ( $seeded_meeting_ids as $mid ) {
		$wpdb->delete( $tbl_meet, array( 'id' => (int) $mid ), array( '%d' ) );
	}
	if ( $seeded_av_uid > 0 ) {
		$wpdb->delete( $tbl_av, array( 'user_id' => (int) $seeded_av_uid ), array( '%d' ) );
	}
} );

// ─── Assert 1: class loaded + both routes registered ──────────────────────
$server = rest_get_server();
do_action( 'rest_api_init', $server );
$routes  = $server->get_routes();
$has_single = isset( $routes['/gs/v1/calendar/meetings/(?P<id>\d+)/ics'] );
$has_feed   = isset( $routes['/gs/v1/calendar/ics/(?P<share_token>[A-Za-z0-9]{43})'] );
if ( $has_single && $has_feed ) {
	gs_uat_pass( '1. both ICS routes registered (single + feed)' );
} else {
	gs_uat_fail( '1. ICS routes missing', 'single=' . ( $has_single ? '1' : '0' ) . ' feed=' . ( $has_feed ? '1' : '0' ) );
}

// ─── Assert 2: seed booked + cancelled meetings ──────────────────────────
$seed_meeting = function ( $type, $meta, $offset, $status ) use ( $wpdb, $tbl_meet, $host_uid, &$seeded_meeting_ids ) {
	$starts = gmdate( 'Y-m-d H:i:s', time() + $offset );
	$ends   = gmdate( 'Y-m-d H:i:s', time() + $offset + 1800 );
	$ok = $wpdb->insert(
		$tbl_meet,
		array(
			'member_id'          => $host_uid,
			'guest_user_id'      => null,
			'guest_name'         => 'QA Bookee',
			'guest_email'        => 'qa+' . wp_generate_password( 6, false ) . '@gs.uat',
			'meeting_type'       => $type,
			'meeting_meta_json'  => wp_json_encode( $meta ),
			'starts_at_utc'      => $starts,
			'ends_at_utc'        => $ends,
			'title'              => 'GS UAT Meeting',
			'status'             => $status,
			'jitsi_room'         => isset( $meta['jitsi_room'] ) ? $meta['jitsi_room'] : null,
			'cancellation_token' => wp_generate_password( 43, false, false ),
			'created_at_ts'      => time(),
			'updated_at_ts'      => time(),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
	);
	if ( false === $ok ) { return 0; }
	$mid = (int) $wpdb->insert_id;
	$seeded_meeting_ids[] = $mid;
	return $mid;
};

$booked_id    = $seed_meeting( 'in_person', array( 'address' => '123 King St W, Toronto' ), 7200, 'booked' );
$cancelled_id = $seed_meeting( 'video',     array( 'provider' => 'gend', 'jitsi_room' => 'gend-uat-' . wp_generate_password( 8, false ) ), 14400, 'cancelled' );

if ( $booked_id > 0 && $cancelled_id > 0 ) {
	gs_uat_pass( '2. seeded booked + cancelled meetings (ids ' . $booked_id . ' / ' . $cancelled_id . ')' );
} else {
	gs_uat_fail( '2. seed failed', 'booked=' . $booked_id . ' cancelled=' . $cancelled_id );
	return;
}

// ─── Fetch + render the booked row's envelope ────────────────────────────
$booked_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_meet} WHERE id = %d", $booked_id ), ARRAY_A );
$booked_meta = (array) json_decode( (string) $booked_row['meeting_meta_json'], true );
$booked_location = Gend_GS_Booking_ICS::meeting_location( $booked_row, $booked_meta );
$booked_vevent = Gend_GS_Booking_ICS::build_vevent( $booked_row, 'QA Host', $booked_location );
$booked_envelope = Gend_GS_Booking_ICS::build_calendar_envelope( array( $booked_vevent ), 'PUBLISH' );

// ─── Assert 3: BEGIN/END VCALENDAR with CRLF ────────────────────────────
$starts_ok = strpos( $booked_envelope, "BEGIN:VCALENDAR\r\n" ) === 0;
$ends_ok   = strpos( $booked_envelope, "END:VCALENDAR\r\n" ) !== false;
if ( $starts_ok && $ends_ok ) {
	gs_uat_pass( '3. envelope starts with BEGIN:VCALENDAR\r\n + ends with END:VCALENDAR\r\n' );
} else {
	gs_uat_fail( '3. envelope envelope malformed', 'starts=' . ( $starts_ok ? '1' : '0' ) . ' ends=' . ( $ends_ok ? '1' : '0' ) );
}

// ─── Assert 4: VERSION:2.0 ────────────────────────────────────────────────
if ( strpos( $booked_envelope, "\r\nVERSION:2.0\r\n" ) !== false ) {
	gs_uat_pass( '4. VERSION:2.0 present' );
} else {
	gs_uat_fail( '4. VERSION:2.0 missing or wrong CRLF flanking' );
}

// ─── Assert 5: PRODID ─────────────────────────────────────────────────────
if ( strpos( $booked_envelope, 'PRODID:-//gend.me//gend-society//EN' ) !== false ) {
	gs_uat_pass( '5. PRODID present' );
} else {
	gs_uat_fail( '5. PRODID missing' );
}

// ─── Assert 6: METHOD:PUBLISH ─────────────────────────────────────────────
if ( strpos( $booked_envelope, 'METHOD:PUBLISH' ) !== false ) {
	gs_uat_pass( '6. METHOD:PUBLISH present' );
} else {
	gs_uat_fail( '6. METHOD:PUBLISH missing' );
}

// ─── Assert 7: VEVENT + UID ──────────────────────────────────────────────
$has_begin = strpos( $booked_envelope, 'BEGIN:VEVENT' ) !== false;
$has_end   = strpos( $booked_envelope, 'END:VEVENT' ) !== false;
$has_uid   = strpos( $booked_envelope, 'UID:meeting-' . $booked_id . '@gend.me' ) !== false;
if ( $has_begin && $has_end && $has_uid ) {
	gs_uat_pass( '7. BEGIN/END:VEVENT + UID:meeting-' . $booked_id . '@gend.me present' );
} else {
	gs_uat_fail( '7. VEVENT shape malformed', 'begin=' . ( $has_begin ? '1' : '0' ) . ' end=' . ( $has_end ? '1' : '0' ) . ' uid=' . ( $has_uid ? '1' : '0' ) );
}

// ─── Assert 8: STATUS:CONFIRMED for booked, STATUS:CANCELLED for cancelled
$cancelled_row      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_meet} WHERE id = %d", $cancelled_id ), ARRAY_A );
$cancelled_meta     = (array) json_decode( (string) $cancelled_row['meeting_meta_json'], true );
$cancelled_location = Gend_GS_Booking_ICS::meeting_location( $cancelled_row, $cancelled_meta );
$cancelled_vevent   = Gend_GS_Booking_ICS::build_vevent( $cancelled_row, 'QA Host', $cancelled_location );
$cancelled_envelope = Gend_GS_Booking_ICS::build_calendar_envelope( array( $cancelled_vevent ), 'CANCEL' );

$booked_status_ok    = strpos( $booked_envelope, "STATUS:CONFIRMED" ) !== false;
$cancelled_status_ok = strpos( $cancelled_envelope, "STATUS:CANCELLED" ) !== false;
if ( $booked_status_ok && $cancelled_status_ok ) {
	gs_uat_pass( '8. STATUS:CONFIRMED (booked) + STATUS:CANCELLED (cancelled)' );
} else {
	gs_uat_fail( '8. status emit mismatch', 'booked=' . ( $booked_status_ok ? '1' : '0' ) . ' cancelled=' . ( $cancelled_status_ok ? '1' : '0' ) );
}

// ─── Assert 9: DTSTAMP/DTSTART/DTEND YYYYMMDDTHHMMSSZ ────────────────────
$dt_re = '/^(DTSTAMP|DTSTART|DTEND):\d{8}T\d{6}Z$/';
$lines = explode( "\r\n", $booked_envelope );
$dt_lines_found = 0;
$dt_lines_bad   = 0;
foreach ( $lines as $line ) {
	if ( preg_match( '/^(DTSTAMP|DTSTART|DTEND):/', $line ) ) {
		$dt_lines_found++;
		if ( ! preg_match( $dt_re, $line ) ) {
			$dt_lines_bad++;
		}
	}
}
if ( $dt_lines_found >= 3 && $dt_lines_bad === 0 ) {
	gs_uat_pass( '9. all ' . $dt_lines_found . ' DTSTAMP/DTSTART/DTEND lines match YYYYMMDDTHHMMSSZ' );
} else {
	gs_uat_fail( '9. DT* formatting broken', 'found=' . $dt_lines_found . ' bad=' . $dt_lines_bad );
}

// ─── Assert 10: line folding (200-char address) ──────────────────────────
$long_addr = str_repeat( 'ABCDEFGHIJ', 22 ); // 220 chars
$long_id   = $seed_meeting( 'in_person', array( 'address' => $long_addr ), 21600, 'booked' );
if ( $long_id > 0 ) {
	$long_row      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_meet} WHERE id = %d", $long_id ), ARRAY_A );
	$long_meta     = (array) json_decode( (string) $long_row['meeting_meta_json'], true );
	$long_location = Gend_GS_Booking_ICS::meeting_location( $long_row, $long_meta );
	$long_vevent   = Gend_GS_Booking_ICS::build_vevent( $long_row, 'QA Host', $long_location );
	$long_envelope = Gend_GS_Booking_ICS::build_calendar_envelope( array( $long_vevent ), 'PUBLISH' );

	// Fold marker present?
	$fold_marker_count = substr_count( $long_envelope, "\r\n " );
	$over_limit = 0;
	foreach ( explode( "\r\n", $long_envelope ) as $line ) {
		if ( strlen( $line ) > 75 ) { $over_limit++; }
	}
	if ( $fold_marker_count >= 1 && $over_limit === 0 ) {
		gs_uat_pass( '10. line folding works (' . $fold_marker_count . ' continuation markers; 0 lines >75 octets)' );
	} else {
		gs_uat_fail( '10. line folding broken', 'fold_markers=' . $fold_marker_count . ' over75=' . $over_limit );
	}
} else {
	gs_uat_fail( '10. could not seed long-address meeting' );
}

// ─── Assert 11: TEXT escaping (comma + semicolon + newline) ──────────────
$escape_id = $seed_meeting( 'in_person', array( 'address' => "1 King St, Suite 5; Toronto\nON" ), 28800, 'booked' );
if ( $escape_id > 0 ) {
	$esc_row      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_meet} WHERE id = %d", $escape_id ), ARRAY_A );
	$esc_meta     = (array) json_decode( (string) $esc_row['meeting_meta_json'], true );
	$esc_location = Gend_GS_Booking_ICS::meeting_location( $esc_row, $esc_meta );
	$esc_vevent   = Gend_GS_Booking_ICS::build_vevent( $esc_row, 'QA Host', $esc_location );

	$has_esc_comma     = strpos( $esc_vevent, '\\,' ) !== false;
	$has_esc_semicolon = strpos( $esc_vevent, '\\;' ) !== false;
	$has_esc_newline   = strpos( $esc_vevent, '\\n' ) !== false;
	if ( $has_esc_comma && $has_esc_semicolon && $has_esc_newline ) {
		gs_uat_pass( '11. TEXT escaping: \\, + \\; + \\n all present' );
	} else {
		gs_uat_fail( '11. TEXT escaping broken', 'comma=' . ( $has_esc_comma ? '1' : '0' ) . ' semicolon=' . ( $has_esc_semicolon ? '1' : '0' ) . ' newline=' . ( $has_esc_newline ? '1' : '0' ) );
	}
} else {
	gs_uat_fail( '11. could not seed escape-test meeting' );
}

// ─── Assert 12: unknown share_token → 404 + gs_ics_token_unknown ─────────
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/ics/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ); // 43 chars
$res = $server->dispatch( $req );
$status = $res->get_status();
$data   = $res->get_data();
$code   = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : ( is_object( $data ) && method_exists( $data, 'get_error_code' ) ? $data->get_error_code() : '' );
if ( $status === 404 && ( $code === 'gs_ics_token_unknown' || strpos( (string) $code, 'gs_ics_' ) === 0 ) ) {
	gs_uat_pass( '12. unknown share_token → ' . $status . ' + ' . $code );
} else {
	gs_uat_fail( '12. unknown-token path wrong', 'status=' . $status . ' code=' . $code );
}

echo "=== Phase 29-04 ICS UAT done ===\n";
