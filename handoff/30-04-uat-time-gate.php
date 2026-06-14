<?php
/**
 * Phase 30 / Plan 30-04 — UAT 2: server-clock time gate (too_early + too_late + happy)
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-time-gate.php
 *
 * Seeds 3 meetings in wp_gs_member_meetings:
 *  - Meeting A: starts in +1h, ends +2h    (caller hits NOW -> expect 403 too_early)
 *  - Meeting B: started -3h, ended -2h      (caller hits NOW past +30min grace -> expect 403 too_late)
 *  - Meeting C: started -5min, ends +25min  (caller hits NOW within window -> expect 200 with JWT)
 *
 * Asserts each REST response status + code via rest_do_request(WP_REST_Request).
 * Cleans up all seeded rows at the end.
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }
global $wpdb;
$table = $wpdb->prefix . 'gs_member_meetings';

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 2: server-clock time gate ===\n";

// Preflight
$assert( class_exists( 'Gend_GS_Jitsi_REST' ), 'Gend_GS_Jitsi_REST class loaded' );
if ( ! class_exists( 'Gend_GS_Jitsi_REST' ) ) { echo "ABORT: REST class missing\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }

// Use admin user 1 (must exist on hub)
$caller_id = 1;
$user = get_userdata( $caller_id );
if ( ! $user ) { echo "ABORT: user_id=1 not present\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }
wp_set_current_user( $caller_id );

// Seed 3 meetings. C_happy carries the DEPLOYED Phase-29 meta.jitsi_room so the happy path
// proves response.room === meta.jitsi_room (the value already emailed to guests).
$happy_room = 'gend-meet-' . $caller_id . '-TimeGateHappy01';
$now = time();

$ids = array();
foreach ( array(
	'A_too_early' => array( '+1 hour',   '+2 hour',    wp_json_encode( array( 'provider' => 'gend' ) ) ),
	'B_too_late'  => array( '-3 hour',   '-2 hour',    wp_json_encode( array( 'provider' => 'gend' ) ) ),   // ended 2h ago -> past +30m grace
	'C_happy'     => array( '-5 minute', '+25 minute', wp_json_encode( array( 'provider' => 'gend', 'jitsi_room' => $happy_room ) ) ),
) as $tag => $row ) {
	list( $start_off, $end_off, $meta_json ) = $row;
	$wpdb->insert( $table, array(
		'member_id'         => $caller_id,
		'guest_user_id'     => 0,
		'meeting_type'      => 'video',
		'meeting_meta_json' => $meta_json,
		'starts_at_utc'     => gmdate( 'Y-m-d H:i:s', strtotime( $start_off, $now ) ),
		'ends_at_utc'       => gmdate( 'Y-m-d H:i:s', strtotime( $end_off,   $now ) ),
		'status'            => 'booked',
	) );
	$ids[ $tag ] = (int) $wpdb->insert_id;
	if ( $ids[ $tag ] < 1 ) { echo "ABORT: insert $tag failed: " . $wpdb->last_error . "\n"; echo "\n=== FAIL === insert failed\n"; return; }
}

$dispatch = function ( $meeting_id ) {
	$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings/' . $meeting_id . '/jitsi-jwt' );
	$req->set_url_params( array( 'id' => $meeting_id ) );
	$resp = rest_do_request( $req );
	return array(
		'status' => $resp->get_status(),
		'data'   => $resp->get_data(),
	);
};

// A: too_early
$rA = $dispatch( $ids['A_too_early'] );
$assert( 403 === $rA['status'], 'Meeting A (starts +1h) -> HTTP 403' );
$assert( ( $rA['data']['code'] ?? '' ) === 'too_early', 'Meeting A -> code=too_early' );
$assert( isset( $rA['data']['data']['starts_at_utc'] ), 'Meeting A -> starts_at_utc surfaced for countdown UI' );

// B: too_late
$rB = $dispatch( $ids['B_too_late'] );
$assert( 403 === $rB['status'], 'Meeting B (ended -2h) -> HTTP 403' );
$assert( ( $rB['data']['code'] ?? '' ) === 'too_late', 'Meeting B -> code=too_late' );

// C: happy
$rC = $dispatch( $ids['C_happy'] );
$assert( 200 === $rC['status'], 'Meeting C (in-window) -> HTTP 200' );
$assert( isset( $rC['data']['jwt'] ) && is_string( $rC['data']['jwt'] ) && '' !== $rC['data']['jwt'],
         'Meeting C -> JWT returned' );
$assert( ( $rC['data']['room'] ?? '' ) === $happy_room,
         'Meeting C -> room === meta.jitsi_room (DEPLOYED Phase-29 contract; matches the emailed join link)' );
$assert( ( $rC['data']['domain'] ?? '' ) === 'meet.gend.me',
         'Meeting C -> domain=meet.gend.me' );

// Cleanup
foreach ( $ids as $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); }

if ( 0 === $fails ) { echo "\n=== SUCCESS === server-clock time gate enforces too_early + too_late + happy\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed\n"; }
