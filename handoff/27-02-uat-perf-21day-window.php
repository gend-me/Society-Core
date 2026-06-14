<?php
/**
 * Phase 27 Plan 02 UAT — perf smoke (21-day window, post-warm).
 *
 * Goal: prove a 21-day calendar fetch round-trips in < 1500ms on a warm hub
 * pod. The 15-25s hub cold-start (memory: project_hub_probe_timeouts) is
 * EXCLUDED from this budget — the script PREWARMS with one throwaway dispatch,
 * then measures the second dispatch.
 *
 * Run on hub:
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-02-uat-perf-21day-window.php --allow-root
 *
 * Optional env: MEMBER_USER_ID=<id>  (defaults to first admin)
 *
 * Exits 0 always.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via `wp eval-file` (ABSPATH undefined).\n" );
	exit( 1 );
}

function gs_uat_pass( $msg ) { echo 'PASS: ' . $msg . "\n"; }
function gs_uat_fail( $msg ) { echo 'FAIL: ' . $msg . "\n"; }
function gs_uat_skip( $msg ) { echo 'SKIP: ' . $msg . "\n"; }

// ---- Resolve member ----
$env_uid = (int) getenv( 'MEMBER_USER_ID' );
$user_id = $env_uid > 0 ? $env_uid : 0;
if ( $user_id <= 0 ) {
	$u = get_user_by( 'login', 'admin' );
	$user_id = $u ? (int) $u->ID : 0;
}
if ( $user_id <= 0 ) {
	gs_uat_skip( 'no member resolvable (set MEMBER_USER_ID or have admin user)' );
	exit( 0 );
}
wp_set_current_user( $user_id );

$from = gmdate( 'c', strtotime( '-21 days' ) );
$to   = gmdate( 'c' );

// ---- PREWARM dispatch (timing discarded; absorbs class autoload + opcache) ----
$prewarm = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$prewarm->set_param( 'from', $from );
$prewarm->set_param( 'to',   $to );
rest_do_request( $prewarm );

// ---- MEASURE dispatch ----
$t0  = microtime( true );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_param( 'from', $from );
$req->set_param( 'to',   $to );
$res = rest_do_request( $req );
$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

if ( $res->get_status() !== 200 ) {
	gs_uat_fail( 'HTTP ' . $res->get_status() . ' from REST dispatch — cannot measure perf cleanly' );
	exit( 0 );
}

$data         = $res->get_data();
$event_count  = is_array( $data['events'] ?? null ) ? count( $data['events'] ) : 0;
$sources_str  = wp_json_encode( $data['sources_available'] ?? array() );

if ( $ms >= 1500 ) {
	gs_uat_fail( "21-day window dispatched in {$ms}ms (exceeds 1500ms budget); events=$event_count; sources_available=$sources_str" );
	exit( 0 );
}

gs_uat_pass( "21-day window dispatched in {$ms}ms (budget 1500ms); events=$event_count; sources_available=$sources_str; user_id=$user_id" );
