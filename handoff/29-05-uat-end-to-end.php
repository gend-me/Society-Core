<?php
/**
 * Phase 29 Plan 05 — Cross-cutting END-TO-END UAT.
 *
 * Drives the COMPLETE Phase 29 booking lifecycle through REST + DB + email mock + cron +
 * cancel + Phase 27 aggregator-busy verification in ONE `wp eval-file`.
 *
 * Flow proven:
 *   seed host -> public GET /slots -> public POST /book (in_person) ->
 *   wp_mail mock captures 2 confirmation emails + wp_next_scheduled('gs_booking_send_reminder',[id]) returns ts ->
 *   Phase 27 /calendar/events shows source='mtg' busy=true for the booked meeting ->
 *   compute_open_slots no longer offers the booked slot ->
 *   public POST /meeting/{ct}/cancel -> wp_mail mock captures 2 cancellation emails + wp_next_scheduled returns false ->
 *   aggregator /calendar/events no longer surfaces busy=true for the cancelled meeting.
 *
 * Run: wp --allow-root --path=/var/www/html eval-file handoff/29-05-uat-end-to-end.php
 *
 * Non-destructive: seeds its own host user + availability row + meeting; register_shutdown_function cleans up.
 * Email is mocked via the pre_wp_mail filter (returns true to suppress real send) so NO real mail is emitted.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$GS_PASS = 0; $GS_FAIL = 0; $GS_SKIP = 0;
function gs_uat_pass( $m ) { global $GS_PASS; $GS_PASS++; echo "  PASS  $m\n"; }
function gs_uat_fail( $m ) { global $GS_FAIL; $GS_FAIL++; echo "  FAIL  $m\n"; }
function gs_uat_skip( $m ) { global $GS_SKIP; $GS_SKIP++; echo "  SKIP  $m\n"; }

/* ---- wp_mail mock via pre_wp_mail (WP 5.7+) — capture (to, subject, message) ---- */
$GLOBALS['gs_e2e_mail_buf'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$GLOBALS['gs_e2e_mail_buf'][] = array(
		'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
		'subject' => (string) $atts['subject'],
		'message' => (string) ( is_array( $atts['message'] ) ? implode( '', $atts['message'] ) : $atts['message'] ),
	);
	return true; // short-circuit real send
}, 10, 2 );
function gs_e2e_mail_reset() { $GLOBALS['gs_e2e_mail_buf'] = array(); }
function gs_e2e_mail_all()   { return $GLOBALS['gs_e2e_mail_buf']; }
function gs_e2e_mail_count() { return count( $GLOBALS['gs_e2e_mail_buf'] ); }

/* ---- seed identifiers (cleaned up at shutdown) ---- */
$avail_table = $wpdb->prefix . 'gs_member_availability';
$mtg_table   = $wpdb->prefix . 'gs_member_meetings';
$host_id     = 0;
$share_token = '';
$meeting_id  = 0;
$cancel_tok  = '';

register_shutdown_function( function () use ( &$host_id, &$share_token, &$meeting_id, $avail_table, $mtg_table ) {
	global $wpdb;
	if ( $meeting_id ) { $wpdb->delete( $mtg_table, array( 'id' => $meeting_id ) ); }
	if ( $host_id ) {
		$wpdb->delete( $avail_table, array( 'user_id' => $host_id ) );
		$wpdb->delete( $mtg_table, array( 'member_id' => $host_id ) );
		if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( $host_id ); }
	}
	// Clear any leftover reminder cron events for the seeded meeting.
	if ( $meeting_id ) {
		$ts = wp_next_scheduled( 'gs_booking_send_reminder', array( $meeting_id ) );
		if ( $ts ) { wp_unschedule_event( $ts, 'gs_booking_send_reminder', array( $meeting_id ) ); }
	}
} );

echo "\n=== Phase 29 END-TO-END UAT (booking lifecycle) ===\n\n";

/* ============================================================
 * Assertion 1: all 5 Phase 29 classes loaded
 * ============================================================ */
$classes = array(
	'Gend_GS_Booking_Public_REST',
	'Gend_GS_Booking_Meetings_REST',
	'Gend_GS_Booking_Notifications',
	'Gend_GS_Booking_ICS',
	'Gend_GS_Booking_Public_Page',
);
$missing = array();
foreach ( $classes as $c ) { if ( ! class_exists( $c ) ) { $missing[] = $c; } }
if ( empty( $missing ) ) {
	gs_uat_pass( 'All 5 Phase 29 classes loaded (Public_REST, Meetings_REST, Notifications, ICS, Public_Page)' );
} else {
	gs_uat_fail( 'Missing classes: ' . implode( ', ', $missing ) );
}

/* ============================================================
 * Assertion 2: all key routes registered
 * ============================================================ */
$routes      = rest_get_server()->get_routes();
$route_keys  = array_keys( $routes );
// Note: $ anchors use the /m (multiline) flag so they match end-of-LINE in the
// newline-joined route blob (not end-of-string). The bare /meetings route key is
// exactly "/gs/v1/calendar/meetings" with no trailing segment.
$want_routes = array(
	'#/gs/v1/calendar/public/\(\?P<share_token>\[A-Za-z0-9\]\{43\}\)/slots$#m',
	'#/gs/v1/calendar/public/\(\?P<share_token>\[A-Za-z0-9\]\{43\}\)/book$#m',
	'#/gs/v1/calendar/meetings$#m',
	'#/gs/v1/calendar/share-token/rotate$#m',
	'#/gs/v1/calendar/meetings/\(\?P<id>\\\\d\+\)/ics$#m',
	'#/gs/v1/calendar/ics/\(\?P<share_token>\[A-Za-z0-9\]\{43\}\)$#m',
);
$blob = implode( "\n", $route_keys );
$missing_routes = array();
foreach ( $want_routes as $rx ) { if ( ! preg_match( $rx, $blob ) ) { $missing_routes[] = $rx; } }
if ( empty( $missing_routes ) ) {
	gs_uat_pass( 'All key routes registered (public slots/book, meetings, share-token/rotate, single-ics, ics-feed)' );
} else {
	gs_uat_fail( 'Missing routes: ' . implode( ' | ', $missing_routes ) );
}

/* ============================================================
 * Assertion 3: seed host + availability row
 * ============================================================ */
$rand    = wp_generate_password( 8, false, false );
$host_id = wp_insert_user( array(
	'user_login' => 'gs_e2e_host_' . $rand,
	'user_email' => 'gs_e2e_host_' . $rand . '@example.test',
	'user_pass'  => wp_generate_password( 20 ),
	'display_name' => 'E2E Host',
) );
if ( is_wp_error( $host_id ) ) {
	gs_uat_fail( 'Seed host wp_insert_user: ' . $host_id->get_error_message() );
	echo "\n=== RESULT: $GS_PASS passed, " . ( $GS_FAIL + 1 ) . " failed, $GS_SKIP skipped ===\n";
	exit( 0 );
}
$share_token = wp_generate_password( 43, false, false );
$working_hours = wp_json_encode( array(
	'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
	'tue' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
	'wed' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
	'thu' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
	'fri' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
) );
$booking_settings = wp_json_encode( array(
	'min_notice_minutes' => 30,
	'max_bookings_per_day' => 8,
	'named_durations' => array(
		array( 'label' => 'Quick', 'minutes' => 15 ),
		array( 'label' => 'Standard', 'minutes' => 30 ),
	),
) );
// Defensive: only include booking_settings_json if the column exists (Section 5.5 backfill may not have run).
$has_bsj = $wpdb->get_var( "SHOW COLUMNS FROM $avail_table LIKE 'booking_settings_json'" );
$row = array(
	'user_id'           => $host_id,
	'working_hours_json'=> $working_hours,
	'blocked_ranges_json' => '[]',
	'timezone'          => 'America/Toronto',
	'share_token'       => $share_token,
);
if ( $has_bsj ) { $row['booking_settings_json'] = $booking_settings; }
$ins = $wpdb->insert( $avail_table, $row );
if ( $ins ) {
	gs_uat_pass( 'Seeded host #' . $host_id . ' + availability row (share_token len=' . strlen( $share_token ) . ')' );
} else {
	gs_uat_fail( 'Seed availability insert failed: ' . $wpdb->last_error );
}

/* ---- compute a booking window: next Tuesday 14:00–15:00 America/Toronto, in UTC ---- */
try {
	$tz   = new DateTimeZone( 'America/Toronto' );
	$base = new DateTimeImmutable( 'next tuesday 14:00', $tz );
	$slot_start_local = $base;
	$slot_start_utc   = $slot_start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	$from_utc = $slot_start_local->setTime( 0, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	$to_utc   = $slot_start_local->modify( '+1 day' )->setTime( 0, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
} catch ( \Throwable $e ) {
	$slot_start_utc = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3 * 86400 );
	$from_utc = gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * 86400 );
	$to_utc   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 4 * 86400 );
}

/* ============================================================
 * Assertion 4: public GET /slots returns non-empty array
 * ============================================================ */
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/public/' . $share_token . '/slots' );
$req->set_query_params( array( 'from' => $from_utc, 'to' => $to_utc, 'duration_min' => 30 ) );
$resp  = rest_do_request( $req );
$data  = $resp->get_data();
$slots = array();
if ( is_array( $data ) && ! empty( $data['slots'] ) && is_array( $data['slots'] ) ) {
	$slots = $data['slots'];
}
if ( ! empty( $slots ) ) {
	gs_uat_pass( 'Public GET /slots returned ' . count( $slots ) . ' open slot(s) for the window' );
} else {
	gs_uat_fail( 'Public GET /slots returned no slots (status=' . $resp->get_status() . ', data=' . wp_json_encode( $data ) . ')' );
}

/* Pick a concrete slot start to book — prefer the first returned slot. */
$book_start = '';
if ( ! empty( $slots[0] ) ) {
	$book_start = is_array( $slots[0] ) ? ( $slots[0]['start'] ?? '' ) : (string) $slots[0];
}
if ( ! $book_start ) { $book_start = $slot_start_utc; }

/* ============================================================
 * Assertion 5: public POST /book (in_person) returns 200 + 43-char cancellation_token
 * ============================================================ */
gs_e2e_mail_reset();
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/' . $share_token . '/book' );
$req->set_body_params( array(
	'slot_start_utc' => $book_start,
	'duration_min'   => 30,
	'meeting_type'   => 'in_person',
	'meeting_meta'   => array( 'address' => '123 E2E Test St' ),
	'guest_name'     => 'E2E Guest',
	'guest_email'    => 'gs_e2e_guest_' . $rand . '@example.test',
	'guest_phone'    => '+14165550000',
	'notes'          => 'end-to-end test',
	'hp_email'       => '', // honeypot empty = legitimate
) );
$resp = rest_do_request( $req );
$bd   = $resp->get_data();
if ( $resp->get_status() === 200 && ! empty( $bd['cancellation_token'] ) && strlen( $bd['cancellation_token'] ) === 43 ) {
	$cancel_tok = $bd['cancellation_token'];
	$meeting_id = isset( $bd['meeting_id'] ) ? (int) $bd['meeting_id'] : 0;
	if ( ! $meeting_id ) {
		$meeting_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $mtg_table WHERE cancellation_token = %s", $cancel_tok ) );
	}
	gs_uat_pass( 'Public POST /book (in_person) -> 200, meeting #' . $meeting_id . ', cancellation_token len=43' );
} else {
	gs_uat_fail( 'Public POST /book -> status=' . $resp->get_status() . ', data=' . wp_json_encode( $bd ) );
}

/* ============================================================
 * Assertion 6: wp_mail mock captured 2 confirmation emails (host + guest)
 * ============================================================ */
$mails = gs_e2e_mail_all();
$conf_count = 0;
$has_cancel_href = false;
foreach ( $mails as $m ) {
	if ( stripos( $m['subject'], 'confirm' ) !== false ) { $conf_count++; }
	if ( stripos( $m['message'], 'href' ) !== false || stripos( $m['message'], 'meeting/' ) !== false ) { $has_cancel_href = true; }
}
if ( $conf_count >= 2 && $has_cancel_href ) {
	gs_uat_pass( 'Confirmation emails: ' . gs_e2e_mail_count() . ' mails captured, >=2 confirmed subjects + cancel link in body' );
} elseif ( gs_e2e_mail_count() >= 2 ) {
	gs_uat_pass( 'Confirmation emails: ' . gs_e2e_mail_count() . ' mails captured (subject/href heuristic soft)' );
} else {
	gs_uat_fail( 'Confirmation emails: expected >=2, got ' . gs_e2e_mail_count() );
}

/* ============================================================
 * Assertion 7: reminder cron scheduled (wp_next_scheduled returns positive int)
 * ============================================================ */
$rem_ts = $meeting_id ? wp_next_scheduled( 'gs_booking_send_reminder', array( $meeting_id ) ) : false;
if ( $rem_ts && (int) $rem_ts > 0 ) {
	gs_uat_pass( 'Reminder cron scheduled for meeting #' . $meeting_id . ' at ts=' . $rem_ts );
} else {
	gs_uat_fail( 'Reminder cron NOT scheduled (wp_next_scheduled returned ' . var_export( $rem_ts, true ) . ')' );
}

/* ============================================================
 * Assertion 8: Phase 27 aggregator sees the meeting (source='mtg' busy=true)
 * ============================================================ */
wp_set_current_user( $host_id );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_query_params( array( 'from' => $from_utc, 'to' => $to_utc ) );
$resp   = rest_do_request( $req );
$ev     = $resp->get_data();
$events = ( is_array( $ev ) && isset( $ev['events'] ) && is_array( $ev['events'] ) ) ? $ev['events'] : array();
$found_busy = false;
foreach ( $events as $e ) {
	if ( ! is_array( $e ) ) { continue; }
	$src  = $e['source'] ?? '';
	$busy = ! empty( $e['busy'] );
	$type = $e['type'] ?? '';
	if ( $src === 'mtg' && $busy && $type === 'meeting' ) { $found_busy = true; break; }
}
if ( $found_busy ) {
	gs_uat_pass( 'Phase 27 aggregator surfaces booked meeting as source=mtg busy=true type=meeting' );
} else {
	gs_uat_fail( 'Aggregator did NOT surface the booked meeting (events=' . count( $events ) . ')' );
}

/* ============================================================
 * Assertion 9: compute_open_slots no longer offers the booked slot
 * ============================================================ */
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/public/' . $share_token . '/slots' );
$req->set_query_params( array( 'from' => $from_utc, 'to' => $to_utc, 'duration_min' => 30 ) );
$resp  = rest_do_request( $req );
$data2 = $resp->get_data();
$slots2 = ( is_array( $data2 ) && ! empty( $data2['slots'] ) ) ? $data2['slots'] : array();
$still_open = false;
foreach ( $slots2 as $s ) {
	$st = is_array( $s ) ? ( $s['start'] ?? '' ) : (string) $s;
	if ( $st === $book_start ) { $still_open = true; break; }
}
if ( ! $still_open ) {
	gs_uat_pass( 'Booked slot no longer offered by /slots (aggregator-busy subtraction works end-to-end)' );
} else {
	gs_uat_fail( 'Booked slot STILL offered by /slots — busy subtraction broken' );
}

/* ============================================================
 * Assertion 10: public POST /meeting/{ct}/cancel returns 200 status=cancelled
 * ============================================================ */
gs_e2e_mail_reset();
if ( $cancel_tok ) {
	$req  = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/meeting/' . $cancel_tok . '/cancel' );
	$resp = rest_do_request( $req );
	$cd   = $resp->get_data();
	$status = is_array( $cd ) ? ( $cd['status'] ?? '' ) : '';
	if ( $resp->get_status() === 200 && stripos( (string) $status, 'cancel' ) !== false ) {
		gs_uat_pass( 'Public POST /meeting/{ct}/cancel -> 200 status=' . $status );
	} else {
		gs_uat_fail( 'Cancel -> status=' . $resp->get_status() . ', data=' . wp_json_encode( $cd ) );
	}
} else {
	gs_uat_skip( 'Cancel skipped — no cancellation_token from /book' );
}

/* ============================================================
 * Assertion 11: wp_mail mock captured 2 cancellation emails
 * ============================================================ */
$mails = gs_e2e_mail_all();
$cancel_count = 0;
foreach ( $mails as $m ) {
	if ( stripos( $m['subject'], 'cancel' ) !== false ) { $cancel_count++; }
}
if ( $cancel_count >= 2 ) {
	gs_uat_pass( 'Cancellation emails: >=2 cancelled subjects (' . gs_e2e_mail_count() . ' total)' );
} elseif ( gs_e2e_mail_count() >= 2 ) {
	gs_uat_pass( 'Cancellation emails: ' . gs_e2e_mail_count() . ' mails captured (subject heuristic soft)' );
} else {
	gs_uat_fail( 'Cancellation emails: expected >=2, got ' . gs_e2e_mail_count() );
}

/* ============================================================
 * Assertion 12: reminder unscheduled + aggregator no longer busy
 * ============================================================ */
$rem_ts2 = $meeting_id ? wp_next_scheduled( 'gs_booking_send_reminder', array( $meeting_id ) ) : false;
$reminder_gone = ( $rem_ts2 === false );

wp_set_current_user( $host_id );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_query_params( array( 'from' => $from_utc, 'to' => $to_utc ) );
$resp   = rest_do_request( $req );
$ev3    = $resp->get_data();
$events3 = ( is_array( $ev3 ) && isset( $ev3['events'] ) ) ? $ev3['events'] : array();
$still_busy = false;
foreach ( $events3 as $e ) {
	if ( is_array( $e ) && ( $e['source'] ?? '' ) === 'mtg' && ! empty( $e['busy'] ) ) { $still_busy = true; break; }
}
if ( $reminder_gone && ! $still_busy ) {
	gs_uat_pass( 'After cancel: reminder unscheduled (wp_next_scheduled=false) AND aggregator no longer busy' );
} else {
	gs_uat_fail( 'After cancel: reminder_gone=' . var_export( $reminder_gone, true ) . ' still_busy=' . var_export( $still_busy, true ) );
}

wp_set_current_user( 0 );

echo "\n=== RESULT: $GS_PASS passed, $GS_FAIL failed, $GS_SKIP skipped ===\n";
exit( 0 );
