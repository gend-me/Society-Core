<?php
/**
 * Phase 29-01 UAT — Atomic FOR UPDATE booking race (Pitfall 6).
 *
 * Proves exactly-one-201 + exactly-one-409 under contention by:
 *   1. Seed host + share_token + availability row (Mon-Fri 09-17 America/Toronto).
 *   2. Pick a slot 24h in the future (avoid min-notice).
 *   3. Manually INSERT a "winning" booked row directly into wp_gs_member_meetings
 *      for that exact (member_id, starts_at_utc) — simulates the FIRST concurrent
 *      handle_book having already committed before our second dispatch lands.
 *   4. Dispatch handle_book via rest_do_request for the SAME slot — MUST return
 *      WP_Error slot_taken (409). Its internal SELECT ... FOR UPDATE will see the
 *      committed row and short-circuit before INSERT.
 *   5. Assert: COUNT(*) = 1 for that (member_id, starts_at_utc) window after both
 *      operations. The 409 path is the load-bearing invariant.
 *
 * Cleanup via register_shutdown_function.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/29-01-uat-atomic-booking-race.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit( 1 ); }

$_pass = 0; $_fail = 0; $_skip = 0;
function gs_uat_pass( $msg ) { global $_pass; $_pass++; echo "  PASS  {$msg}\n"; }
function gs_uat_fail( $msg, $why = '' ) { global $_fail; $_fail++; echo "  FAIL  {$msg}" . ( $why ? " :: {$why}" : '' ) . "\n"; }
function gs_uat_skip( $msg, $why = '' ) { global $_skip; $_skip++; echo "  SKIP  {$msg}" . ( $why ? " :: {$why}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 29-01 atomic FOR UPDATE booking race UAT (Pitfall 6) ===\n";

if ( ! class_exists( 'Gend_GS_Booking_Public_REST' ) || ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
    gs_uat_fail( 'classes not loaded' );
    echo "\n=== RESULT: 0 passed, 1 failed, 0 skipped ===\n";
    return;
}

$test_email = 'gs-uat-29-01-race-' . wp_generate_password( 8, false, false ) . '@test.local';
$test_user_id = wp_insert_user( array(
    'user_login' => 'gs_uat_29_01_race_' . substr( md5( $test_email ), 0, 8 ),
    'user_pass'  => wp_generate_password( 24, true, true ),
    'user_email' => $test_email,
    'role'       => 'subscriber',
) );
if ( is_wp_error( $test_user_id ) || ! $test_user_id ) {
    gs_uat_fail( 'could not seed user' );
    echo "\n=== RESULT: 0 passed, 1 failed, 0 skipped ===\n";
    return;
}
$test_user_id = (int) $test_user_id;
$tbl_avail = Gend_GS_Availability_Schema::table_availability();
$tbl_meet  = Gend_GS_Availability_Schema::table_meetings();
$share_token = wp_generate_password( 43, false, false );

register_shutdown_function( function () use ( $wpdb, $tbl_avail, $tbl_meet, $test_user_id, $share_token ) {
    $wpdb->delete( $tbl_meet,  array( 'member_id' => $test_user_id ), array( '%d' ) );
    $wpdb->delete( $tbl_avail, array( 'user_id'   => $test_user_id ), array( '%d' ) );
    wp_delete_user( $test_user_id );
    delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
    delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );
    echo "CLEAN  removed seeded race-test user + rows + transients\n";
} );

$now_ts = time();
$wpdb->insert(
    $tbl_avail,
    array(
        'user_id'             => $test_user_id,
        'timezone'            => 'America/Toronto',
        'working_hours_json'  => wp_json_encode( array(
            'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'tue' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'wed' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'thu' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'fri' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
        ) ),
        'blocked_ranges_json' => wp_json_encode( array() ),
        'share_token'         => $share_token,
        'created_at_ts'       => $now_ts,
        'updated_at_ts'       => $now_ts,
    ),
    array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
);

// Pick a slot 24h in the future to clear min-notice (default 60min). Normalize to :00 minute.
$future_ts = $now_ts + 86400;
$slot_start_utc_iso   = gmdate( 'Y-m-d\TH:00:00\Z', $future_ts );
$slot_start_utc_mysql = gmdate( 'Y-m-d H:00:00', $future_ts );
$slot_end_utc_mysql   = gmdate( 'Y-m-d H:30:00', $future_ts );

// Step 3: directly INSERT a "winning" booked row.
$winning_ct = wp_generate_password( 43, false, false );
$ok = $wpdb->insert(
    $tbl_meet,
    array(
        'member_id'          => $test_user_id,
        'guest_user_id'      => null,
        'guest_name'         => 'Race Winner',
        'guest_email'        => 'race.winner@test.local',
        'guest_phone'        => '',
        'starts_at_utc'      => $slot_start_utc_mysql,
        'ends_at_utc'        => $slot_end_utc_mysql,
        'meeting_type'       => 'in_person',
        'meeting_meta_json'  => wp_json_encode( array( 'address' => 'Race Winner Addr' ) ),
        'status'             => 'booked',
        'cancellation_token' => $winning_ct,
        'created_at_utc'     => gmdate( 'Y-m-d H:i:s' ),
        'updated_at_utc'     => gmdate( 'Y-m-d H:i:s' ),
    ),
    array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
);
if ( $ok === false ) {
    gs_uat_fail( 'could not seed winning booked row', $wpdb->last_error );
    echo "\n=== RESULT: 0 passed, 1 failed, 0 skipped ===\n";
    return;
}
gs_uat_pass( 'seeded winning booked row (simulating first concurrent POST already committed)' );

// Step 4: dispatch handle_book for the SAME slot — MUST return slot_taken 409.
delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/' . $share_token . '/book' );
$req->set_param( 'slot_start_utc', $slot_start_utc_iso );
$req->set_param( 'duration_min', 30 );
$req->set_param( 'meeting_type', 'in_person' );
$req->set_param( 'meeting_meta', array( 'address' => 'Loser Addr' ) );
$req->set_param( 'guest_name', 'Race Loser' );
$req->set_param( 'guest_email', 'race.loser@test.local' );
$resp = rest_do_request( $req );

$err = $resp->as_error();
$code = $err ? $err->get_error_code() : '';
$status = $resp->get_status();
echo "  SECOND BOOK ATTEMPT → status={$status} code={$code}\n";

if ( $status === 409 && $code === 'slot_taken' ) {
    gs_uat_pass( 'second handle_book returned 409 slot_taken (FOR UPDATE overlap re-check fired)' );
} else {
    gs_uat_fail( 'second handle_book should be 409 slot_taken', "status={$status} code={$code}" );
}

// Step 5: assert exactly ONE booked row for the exact slot.
$count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$tbl_meet}
      WHERE member_id = %d
        AND status   = 'booked'
        AND starts_at_utc = %s",
    $test_user_id, $slot_start_utc_mysql
) );
if ( $count === 1 ) {
    gs_uat_pass( 'exactly ONE booked row exists for (member_id, starts_at_utc) → Pitfall 6 atomic invariant proven' );
} else {
    gs_uat_fail( 'expected exactly 1 booked row for the slot', "count={$count}" );
}

echo "\n=== RESULT: {$_pass} passed, {$_fail} failed, {$_skip} skipped ===\n";
exit( 0 );
