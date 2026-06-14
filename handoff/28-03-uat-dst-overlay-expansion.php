<?php
/**
 * Phase 28-03 UAT — DST-safe overlay expansion (Pitfall 5 + Pitfall 12).
 *
 * Asserts: a recurring "Mon 09:00 America/Toronto" stays 9 AM LOCAL across the
 * 2026-11-01 EDT → EST transition. UTC translation correctly drifts +1h after DST end.
 *
 * - 2026-10-26 (EDT Mon, UTC-4): 09:00 EDT → 13:00 UTC, 17:00 EDT → 21:00 UTC
 * - 2026-11-02 (EST Mon, UTC-5): 09:00 EST → 14:00 UTC, 17:00 EST → 22:00 UTC
 *
 * The +1h drift in UTC is CORRECT — it preserves 9 AM LOCAL across DST.
 * A naive strtotime + offset implementation would emit both Mondays as 13:00 UTC
 * (which would show as 9 AM EDT before DST and 8 AM EST after — wrong, 1 hour early).
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-03-uat-dst-overlay-expansion.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 28-03 DST overlay expansion UAT ===\n";

if ( ! class_exists( 'Gend_GS_Availability_REST' ) ) { gs_uat_fail( 'REST class missing' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) { gs_uat_fail( 'schema missing' ); return; }

$test_user_id = 1;
if ( ! get_userdata( $test_user_id ) ) {
	$u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
	$test_user_id = $u ? (int) $u[0] : 0;
}
if ( $test_user_id <= 0 ) { gs_uat_fail( 'no users' ); return; }

$table = Gend_GS_Availability_Schema::table_availability();
register_shutdown_function( function () use ( $wpdb, $table, $test_user_id ) {
	$wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );
	wp_cache_delete( Gend_GS_Availability_REST::tz_cache_key( $test_user_id ), Gend_GS_Availability_REST::CACHE_GROUP );
	echo "CLEAN · deleted availability + cache for user_id={$test_user_id}\n";
} );

$wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );
wp_set_current_user( $test_user_id );

// Seed: America/Toronto, mon 09:00→17:00
$put = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$put->set_body_params( array(
	'timezone'      => 'America/Toronto',
	'working_hours' => array( 'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ) ),
) );
$resp = rest_do_request( $put );
if ( $resp->get_status() !== 200 ) { gs_uat_fail( 'PUT seed', 'status=' . $resp->get_status() ); return; }

// Request overlays spanning the DST flip (2026-10-25 → 2026-11-08 — 2 weeks bracketing EDT→EST on Sun 2026-11-01)
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/overlays' );
$req->set_query_params( array(
	'from' => '2026-10-25T00:00:00Z',
	'to'   => '2026-11-08T00:00:00Z',
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
if ( ! is_array( $data ) || ! isset( $data['working_hours'] ) ) {
	gs_uat_fail( 'overlays response', wp_json_encode( $data ) );
	return;
}

// Build lookup by start_utc for assertions.
$by_start = array();
foreach ( $data['working_hours'] as $o ) { $by_start[ $o['start_utc'] ] = $o; }

// Assert 1: 2026-10-26 (EDT Mon): 09:00 EDT = 13:00 UTC, 17:00 EDT = 21:00 UTC
$edt_mon = $by_start['2026-10-26T13:00:00Z'] ?? null;
( $edt_mon && $edt_mon['end_utc'] === '2026-10-26T21:00:00Z' )
	? gs_uat_pass( 'EDT Mon 2026-10-26: 09:00 EDT=13:00 UTC → 17:00 EDT=21:00 UTC' )
	: gs_uat_fail( 'EDT Mon expansion', 'expected start=2026-10-26T13:00:00Z + end=2026-10-26T21:00:00Z; got ' . wp_json_encode( $edt_mon ) );

// Assert 2: 2026-11-02 (EST Mon — first Mon after DST end): 09:00 EST = 14:00 UTC (NOT 13:00), 17:00 EST = 22:00 UTC (NOT 21:00)
$est_mon = $by_start['2026-11-02T14:00:00Z'] ?? null;
( $est_mon && $est_mon['end_utc'] === '2026-11-02T22:00:00Z' )
	? gs_uat_pass( 'EST Mon 2026-11-02: 09:00 EST=14:00 UTC → 17:00 EST=22:00 UTC (DST-correct +1h UTC drift)' )
	: gs_uat_fail( 'EST Mon expansion — DST drift bug?', 'expected start=2026-11-02T14:00:00Z + end=2026-11-02T22:00:00Z; got ' . wp_json_encode( $est_mon ) );

// Negative assert: 2026-11-02T13:00:00Z must NOT exist (that would be a DST-naive strtotime bug)
$dst_bug = $by_start['2026-11-02T13:00:00Z'] ?? null;
( ! $dst_bug )
	? gs_uat_pass( 'no DST-naive entry at 2026-11-02T13:00:00Z (would indicate offset arithmetic bug)' )
	: gs_uat_fail( 'DST-naive bug detected — found 2026-11-02T13:00:00Z (should be 14:00 UTC after EST flip)', wp_json_encode( $dst_bug ) );

echo "=== done — cleanup runs at shutdown ===\n";
