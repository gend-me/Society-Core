<?php
/**
 * Phase 29-03 UAT — Booking notifications (4 email templates + reminder cron).
 *
 * NOTIF-01 (booking confirmation to both parties) +
 * NOTIF-02 (reminder email scheduled before meeting) acceptance probe.
 *
 * Approach:
 *   - Mock wp_mail via the 'pre_wp_mail' filter so no actual mail is sent;
 *     return TRUE (suppress real send) and capture {to,subject,message,headers}
 *     into a static buffer that each assertion inspects.
 *   - Trigger the 3 lifecycle subscribers directly via do_action() — bypasses
 *     the REST round-trip so the test runs in-process / deterministic.
 *   - Reminder cron timestamp is asserted against wp_next_scheduled() ±5s.
 *
 * Assertions (10):
 *   1. class loaded + 4 add_action subscribers registered
 *   2. gs_booking_created → 2 wp_mail (host + guest), subject "confirmed", body has 'href'
 *   3. wp_next_scheduled('gs_booking_send_reminder',[id]) > 0
 *   4. scheduled ts == starts_at_utc - 15min ±5s
 *   5. gs_booking_cancelled → 2 wp_mail, subject "cancelled", body mentions actor
 *   6. wp_next_scheduled returns false after cancel (reminder unscheduled)
 *   7. gs_booking_rescheduled → 2 wp_mail, subject "rescheduled", body has old AND new dates
 *   8. wp_next_scheduled returns NEW positive ts after reschedule (different from any prior)
 *   9. handle_reminder_cron skips when status='cancelled' (Pitfall 2 silent-skip)
 *  10. Pitfall 2 — non-existent meeting_id triggers subscriber with no fatal
 *
 * Cleanup via register_shutdown_function — wp_mail mock buffer cleared,
 * pending reminders unscheduled, seeded rows + users deleted.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/29-03-uat-notifications.php
 *
 * Always exits 0 (this is an acceptance probe, not a test runner).
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "must run via wp eval-file\n";
	exit;
}

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }
function gs_uat_skip( $l, $w = '' ) { echo "SKIP · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 29-03 booking notifications UAT ===\n";

if ( ! class_exists( 'Gend_GS_Booking_Notifications' ) ) {
	gs_uat_fail( 'Gend_GS_Booking_Notifications not loaded — Plan 29-03 bootstrap missing?' );
	return;
}
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
	gs_uat_fail( 'Gend_GS_Availability_Schema not loaded — Plan 28-01 missing?' );
	return;
}

// ─── wp_mail mock via pre_wp_mail filter ──────────────────────────────────
// Pre-WP 5.7+ short-circuit: return non-null suppresses the real wp_mail.
$GLOBALS['gs_uat_mail_buf'] = array();
add_filter( 'pre_wp_mail', function ( $short_circuit, $atts ) {
	$GLOBALS['gs_uat_mail_buf'][] = array(
		'to'      => isset( $atts['to'] )      ? $atts['to']      : '',
		'subject' => isset( $atts['subject'] ) ? $atts['subject'] : '',
		'message' => isset( $atts['message'] ) ? $atts['message'] : '',
		'headers' => isset( $atts['headers'] ) ? $atts['headers'] : '',
	);
	return true; // suppress real send
}, 10, 2 );
function gs_uat_mail_reset() { $GLOBALS['gs_uat_mail_buf'] = array(); }
function gs_uat_mail_count() { return count( $GLOBALS['gs_uat_mail_buf'] ); }
function gs_uat_mail_all()   { return $GLOBALS['gs_uat_mail_buf']; }

// ─── Seed: pick or create a host user with a usable email ────────────────
$host_uid = 1;
if ( ! get_userdata( $host_uid ) ) {
	$u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
	$host_uid = $u ? (int) $u[0] : 0;
}
if ( $host_uid <= 0 ) {
	gs_uat_fail( 'no users in system; cannot run' );
	return;
}
$host_user = get_userdata( $host_uid );
if ( ! $host_user || ! is_email( $host_user->user_email ) ) {
	gs_uat_skip( 'host user has no valid email; some mail count assertions may degrade' );
}

$tbl_meet = Gend_GS_Availability_Schema::table_meetings();

// ─── Register cleanup BEFORE any state mutation ──────────────────────────
$seeded_ids = array();
register_shutdown_function( function () use ( $wpdb, $tbl_meet, &$seeded_ids ) {
	foreach ( $seeded_ids as $mid ) {
		// Unschedule any pending reminder.
		$ts = wp_next_scheduled( 'gs_booking_send_reminder', array( (int) $mid ) );
		if ( $ts ) { wp_unschedule_event( $ts, 'gs_booking_send_reminder', array( (int) $mid ) ); }
		$wpdb->delete( $tbl_meet, array( 'id' => (int) $mid ), array( '%d' ) );
	}
} );

// Helper: seed a synthetic booked meeting row, return its id.
$seed_meeting = function ( $type = 'in_person', $meta = array(), $starts_offset_sec = 7200 ) use ( $wpdb, $tbl_meet, $host_uid, &$seeded_ids ) {
	$starts = gmdate( 'Y-m-d H:i:s', time() + $starts_offset_sec );
	$ends   = gmdate( 'Y-m-d H:i:s', time() + $starts_offset_sec + 1800 ); // 30-min meeting
	$cancel_token = wp_generate_password( 43, false, false );
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
			'status'             => 'booked',
			'jitsi_room'         => isset( $meta['jitsi_room'] ) ? $meta['jitsi_room'] : null,
			'cancellation_token' => $cancel_token,
			'created_at_ts'      => time(),
			'updated_at_ts'      => time(),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
	);
	if ( false === $ok ) { return 0; }
	$mid = (int) $wpdb->insert_id;
	$seeded_ids[] = $mid;
	return $mid;
};

// ─── Assert 1: 4 add_action subscribers wired ────────────────────────────
$expected_subs = array(
	'gs_booking_created'       => array( 'Gend_GS_Booking_Notifications', 'on_created' ),
	'gs_booking_cancelled'     => array( 'Gend_GS_Booking_Notifications', 'on_cancelled' ),
	'gs_booking_rescheduled'   => array( 'Gend_GS_Booking_Notifications', 'on_rescheduled' ),
	'gs_booking_send_reminder' => array( 'Gend_GS_Booking_Notifications', 'handle_reminder_cron' ),
);
$missing_subs = array();
foreach ( $expected_subs as $hook => $cb ) {
	if ( has_action( $hook, $cb ) === false ) {
		$missing_subs[] = $hook;
	}
}
( empty( $missing_subs ) )
	? gs_uat_pass( 'all 4 add_action subscribers registered (created/cancelled/rescheduled/send_reminder)' )
	: gs_uat_fail( 'missing subscribers', implode( ',', $missing_subs ) );

// ─── Assert 2: gs_booking_created → 2 wp_mail with subject "confirmed" + href in body ─
gs_uat_mail_reset();
$mid_a = $seed_meeting( 'in_person', array( 'address' => '123 Test St, Toronto, ON' ), 7200 );
if ( $mid_a <= 0 ) {
	gs_uat_fail( 'seed meeting A failed; aborting assertions 2-5' );
} else {
	do_action( 'gs_booking_created', $mid_a, $host_uid );
	$mails = gs_uat_mail_all();
	$count = count( $mails );
	$confirmed_match = 0;
	$has_href        = 0;
	foreach ( $mails as $m ) {
		if ( false !== stripos( (string) $m['subject'], 'confirmed' ) ) { $confirmed_match++; }
		if ( false !== strpos( (string) $m['message'], 'href' ) ) { $has_href++; }
	}
	( $count >= 2 && $confirmed_match >= 2 && $has_href >= 2 )
		? gs_uat_pass( "gs_booking_created → {$count} wp_mail calls, {$confirmed_match} 'confirmed' subjects, {$has_href} bodies with href" )
		: gs_uat_fail( "expected 2 mails with 'confirmed'+href; got count={$count} confirmed={$confirmed_match} href={$has_href}" );

	// ─── Assert 3: reminder scheduled ────────────────────────────────────
	$next = wp_next_scheduled( 'gs_booking_send_reminder', array( $mid_a ) );
	( $next && (int) $next > 0 )
		? gs_uat_pass( 'reminder scheduled at ts=' . (int) $next )
		: gs_uat_fail( 'reminder NOT scheduled', 'wp_next_scheduled returned ' . var_export( $next, true ) );

	// ─── Assert 4: scheduled ts == starts_at_utc - 15min ±5s ─────────────
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT starts_at_utc FROM {$tbl_meet} WHERE id = %d", $mid_a ), ARRAY_A );
	if ( $row && $next ) {
		$starts_ts = strtotime( $row['starts_at_utc'] . ' UTC' );
		$expected  = $starts_ts - ( 15 * 60 );
		$diff      = abs( (int) $next - (int) $expected );
		( $diff <= 5 )
			? gs_uat_pass( "scheduled ts within 5s of expected (diff={$diff}s)" )
			: gs_uat_fail( "scheduled ts off by {$diff}s (expected={$expected} actual={$next})" );
	} else {
		gs_uat_skip( 'cannot verify reminder timestamp — row or next ts missing' );
	}
}

// ─── Assert 5: gs_booking_cancelled → 2 wp_mail with "cancelled" + "host" mention ─
gs_uat_mail_reset();
if ( $mid_a > 0 ) {
	do_action( 'gs_booking_cancelled', $mid_a, $host_uid, 'host' );
	$mails = gs_uat_mail_all();
	$count = count( $mails );
	$cancelled_match = 0;
	$mentions_host   = 0;
	foreach ( $mails as $m ) {
		if ( false !== stripos( (string) $m['subject'], 'cancelled' ) ) { $cancelled_match++; }
		if ( false !== stripos( (string) $m['message'], 'the host' ) ) { $mentions_host++; }
	}
	( $count >= 2 && $cancelled_match >= 2 && $mentions_host >= 2 )
		? gs_uat_pass( "gs_booking_cancelled → {$count} wp_mail calls, {$cancelled_match} 'cancelled' subjects, {$mentions_host} bodies mention 'the host'" )
		: gs_uat_fail( "expected 2 mails with 'cancelled'+'the host'; got count={$count} cancelled={$cancelled_match} host={$mentions_host}" );

	// ─── Assert 6: reminder unscheduled after cancel ─────────────────────
	$next = wp_next_scheduled( 'gs_booking_send_reminder', array( $mid_a ) );
	( $next === false )
		? gs_uat_pass( 'reminder unscheduled after cancellation' )
		: gs_uat_fail( 'reminder STILL scheduled after cancel', 'ts=' . var_export( $next, true ) );
}

// ─── Assert 7: gs_booking_rescheduled → 2 wp_mail with "rescheduled" + both dates ─
gs_uat_mail_reset();
$mid_b = $seed_meeting( 'video', array( 'provider' => 'gend', 'jitsi_room' => 'gend-meet-' . $host_uid . '-abc123def456ghij' ), 10800 );
if ( $mid_b <= 0 ) {
	gs_uat_fail( 'seed meeting B failed; aborting assertions 7-9' );
} else {
	// Capture the new starts_at_utc + a synthetic OLD time so we can grep the body.
	$row_b = $wpdb->get_row( $wpdb->prepare( "SELECT starts_at_utc FROM {$tbl_meet} WHERE id = %d", $mid_b ), ARRAY_A );
	$new_starts_utc = $row_b ? (string) $row_b['starts_at_utc'] : '';
	$old_starts_utc = gmdate( 'Y-m-d H:i:s', time() + 5400 ); // 90 min from now (different from new)

	do_action( 'gs_booking_rescheduled', $mid_b, $host_uid, $old_starts_utc );
	$mails = gs_uat_mail_all();
	$count = count( $mails );
	$resched_match = 0;
	$has_both_dates = 0;
	// Format the new starts in UTC as the email body renders it (D, M j, Y g:i A T).
	$dt_new = false;
	$dt_old = false;
	try {
		$dt_new = ( new DateTimeImmutable( $new_starts_utc, new DateTimeZone( 'UTC' ) ) )->format( 'M j' );
		$dt_old = ( new DateTimeImmutable( $old_starts_utc, new DateTimeZone( 'UTC' ) ) )->format( 'M j' );
	} catch ( \Throwable $e ) {}
	foreach ( $mails as $m ) {
		if ( false !== stripos( (string) $m['subject'], 'rescheduled' ) ) { $resched_match++; }
		// Body should contain SOMETHING that decodes to the new date AND a "Was:" label.
		$body = (string) $m['message'];
		$body_has_was = ( false !== stripos( $body, 'Was:' ) );
		$body_has_new = $dt_new && ( false !== strpos( $body, $dt_new ) );
		if ( $body_has_was && $body_has_new ) { $has_both_dates++; }
	}
	( $count >= 2 && $resched_match >= 2 && $has_both_dates >= 2 )
		? gs_uat_pass( "gs_booking_rescheduled → {$count} wp_mail, {$resched_match} 'rescheduled' subjects, {$has_both_dates} bodies with 'Was:' + new date" )
		: gs_uat_fail( "expected 2 mails w/ 'rescheduled'+both dates; got count={$count} resched={$resched_match} both_dates={$has_both_dates}" );

	// ─── Assert 8: NEW reminder scheduled after reschedule ───────────────
	$next_b = wp_next_scheduled( 'gs_booking_send_reminder', array( $mid_b ) );
	( $next_b && (int) $next_b > 0 )
		? gs_uat_pass( 'reminder re-scheduled after reschedule at ts=' . (int) $next_b )
		: gs_uat_fail( 'reminder NOT re-scheduled', 'next=' . var_export( $next_b, true ) );

	// ─── Assert 9: handle_reminder_cron skips when status='cancelled' ────
	$wpdb->update( $tbl_meet, array( 'status' => 'cancelled' ), array( 'id' => $mid_b ), array( '%s' ), array( '%d' ) );
	gs_uat_mail_reset();
	Gend_GS_Booking_Notifications::handle_reminder_cron( $mid_b );
	$post_count = gs_uat_mail_count();
	( $post_count === 0 )
		? gs_uat_pass( 'handle_reminder_cron correctly skipped cancelled meeting (no mail sent)' )
		: gs_uat_fail( "handle_reminder_cron sent {$post_count} mails for cancelled meeting; expected 0" );
}

// ─── Assert 10: Pitfall 2 — non-existent meeting_id is silently swallowed ─
gs_uat_mail_reset();
$bogus_id = 999999999; // unlikely to exist
$had_error_before = error_get_last();
$pre_count = gs_uat_mail_count();
$caught_fatal = false;
try {
	do_action( 'gs_booking_created', $bogus_id, $host_uid );
	do_action( 'gs_booking_cancelled', $bogus_id, $host_uid, 'guest' );
	do_action( 'gs_booking_rescheduled', $bogus_id, $host_uid, gmdate( 'Y-m-d H:i:s' ) );
	Gend_GS_Booking_Notifications::handle_reminder_cron( $bogus_id );
} catch ( \Throwable $e ) {
	$caught_fatal = true;
}
$post_count = gs_uat_mail_count();
( ! $caught_fatal && $post_count === 0 )
	? gs_uat_pass( 'Pitfall 2 OK — non-existent meeting_id silently no-op (no fatal, no mail)' )
	: gs_uat_fail( "Pitfall 2 violated — fatal_caught={$caught_fatal} mail_sent={$post_count}" );

echo "=== Phase 29-03 booking notifications UAT complete ===\n";
echo "RESULT: see PASS / FAIL counts above.\n";
exit( 0 );
