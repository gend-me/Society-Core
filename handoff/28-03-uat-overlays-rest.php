<?php
/**
 * Phase 28-03 UAT — /calendar/overlays REST contract.
 * AVAIL-04 acceptance probe.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-03-uat-overlays-rest.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 28-03 overlays REST UAT ===\n";

if ( ! class_exists( 'Gend_GS_Availability_REST' ) )   { gs_uat_fail( 'availability REST class missing' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) { gs_uat_fail( 'schema class missing' ); return; }

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
	echo "CLEAN · deleted availability row + cache for user_id={$test_user_id}\n";
} );

$wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );
wp_set_current_user( $test_user_id );

// Seed availability: tz=America/Toronto, mon=09:00→17:00 + a blocked range on 2026-06-15 13:00→14:00 UTC
$put = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$put->set_body_params( array(
	'timezone'       => 'America/Toronto',
	'working_hours'  => array( 'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ) ),
	'blocked_ranges' => array( array( 'start_utc' => '2026-06-15T13:00:00Z', 'end_utc' => '2026-06-15T14:00:00Z', 'reason' => 'lunch' ) ),
) );
$resp = rest_do_request( $put );
if ( $resp->get_status() !== 200 ) { gs_uat_fail( 'PUT seed', 'status=' . $resp->get_status() ); return; }
gs_uat_pass( 'PUT seed: tz + mon 09-17 + blocked range stored' );

// GET /calendar/overlays for the week of Mon 2026-06-15 (EDT — UTC-4)
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/overlays' );
$req->set_query_params( array(
	'from' => '2026-06-14T04:00:00Z', // Sun midnight EDT
	'to'   => '2026-06-21T04:00:00Z', // following Sun midnight EDT
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
( is_array( $data ) && isset( $data['member_tz'] ) && $data['member_tz'] === 'America/Toronto' )
	? gs_uat_pass( 'overlays response: member_tz=America/Toronto' )
	: gs_uat_fail( 'overlays response shape', wp_json_encode( $data ) );

// Find Monday 2026-06-15 working hour: 09:00 EDT = 13:00 UTC, 17:00 EDT = 21:00 UTC
$found_mon = false;
foreach ( ( $data['working_hours'] ?? array() ) as $o ) {
	if ( ( $o['start_utc'] ?? '' ) === '2026-06-15T13:00:00Z' && ( $o['end_utc'] ?? '' ) === '2026-06-15T21:00:00Z' && ( $o['kind'] ?? '' ) === 'working' ) {
		$found_mon = true; break;
	}
}
$found_mon ? gs_uat_pass( 'EDT Mon working: 09:00 EDT = 13:00 UTC, 17:00 EDT = 21:00 UTC' )
		   : gs_uat_fail( 'EDT Mon working expansion', wp_json_encode( $data['working_hours'] ?? array() ) );

// Blocked range round-trip
$found_blk = false;
foreach ( ( $data['blocked_ranges'] ?? array() ) as $o ) {
	if ( ( $o['start_utc'] ?? '' ) === '2026-06-15T13:00:00Z' && ( $o['end_utc'] ?? '' ) === '2026-06-15T14:00:00Z' && ( $o['kind'] ?? '' ) === 'blocked' && ( $o['reason'] ?? '' ) === 'lunch' ) {
		$found_blk = true; break;
	}
}
$found_blk ? gs_uat_pass( 'blocked range round-trip with kind=blocked, reason=lunch' )
		   : gs_uat_fail( 'blocked range round-trip', wp_json_encode( $data['blocked_ranges'] ?? array() ) );

// Bad range: from >= to → 400
$bad = new WP_REST_Request( 'GET', '/gs/v1/calendar/overlays' );
$bad->set_query_params( array( 'from' => '2026-06-20T00:00:00Z', 'to' => '2026-06-15T00:00:00Z' ) );
$resp_bad = rest_do_request( $bad );
( $resp_bad->get_status() === 400 )
	? gs_uat_pass( 'overlays from >= to rejected with 400' )
	: gs_uat_fail( 'bad range status', 'got ' . $resp_bad->get_status() );

// 90-day cap (range_too_large)
$cap = new WP_REST_Request( 'GET', '/gs/v1/calendar/overlays' );
$cap->set_query_params( array( 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-06-01T00:00:00Z' ) );
$resp_cap = rest_do_request( $cap );
( $resp_cap->get_status() === 400 )
	? gs_uat_pass( '90-day cap enforced (152-day range_too_large rejected)' )
	: gs_uat_fail( '90-day cap', 'got ' . $resp_cap->get_status() );

echo "=== done — cleanup runs at shutdown ===\n";
