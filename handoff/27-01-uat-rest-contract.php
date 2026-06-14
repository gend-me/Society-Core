<?php
/**
 * Phase 27 Plan 01 UAT — AGG-01 (REST contract envelope + per-event shape).
 *
 * Goal: prove gs/v1/calendar/events returns the LOCKED Phase 26 Plan 02
 * 11-field event contract verbatim, wrapped in the {sources_available, events}
 * envelope, with status 200 for a logged-in member.
 *
 * Run on hub or container WP:
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-01-uat-rest-contract.php --allow-root
 *
 * Override member via env: WP_USER_ID=<id>
 *
 * Exits 0 always; lines starting `PASS` / `FAIL` / `SKIP` indicate result.
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
$env_uid = getenv( 'WP_USER_ID' );
$user_id = $env_uid ? (int) $env_uid : 0;
if ( $user_id <= 0 ) {
	$u = get_user_by( 'login', 'admin' );
	$user_id = $u ? (int) $u->ID : 0;
}
if ( $user_id <= 0 ) {
	gs_uat_skip( 'no member resolved (set WP_USER_ID=<id>)' );
	exit( 0 );
}
wp_set_current_user( $user_id );

// ---- Dispatch ----
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_param( 'from', gmdate( 'c', strtotime( '-30 days' ) ) );
$req->set_param( 'to',   gmdate( 'c', strtotime( '+30 days' ) ) );
$res = rest_do_request( $req );

if ( $res->get_status() !== 200 ) {
	gs_uat_fail( 'HTTP ' . $res->get_status() . ' (expected 200): ' . wp_json_encode( $res->get_data() ) );
	exit( 0 );
}

$data = $res->get_data();

// ---- Envelope shape ----
if ( ! is_array( $data ) ) {
	gs_uat_fail( 'response is not an array' );
	exit( 0 );
}
if ( ! array_key_exists( 'sources_available', $data ) || ! is_array( $data['sources_available'] ) ) {
	gs_uat_fail( 'sources_available missing or not an array' );
	exit( 0 );
}
if ( ! array_key_exists( 'events', $data ) || ! is_array( $data['events'] ) ) {
	gs_uat_fail( 'events missing or not an array' );
	exit( 0 );
}

// ---- Per-event shape (LOCKED 11-field contract) ----
$required = array( 'id', 'source', 'type', 'title', 'start', 'end', 'all_day', 'color', 'status', 'url', 'busy' );
$iso_re   = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
$sources  = array( 'pm', 'bm', 'mtg' );

$drift = array();
foreach ( $data['events'] as $i => $evt ) {
	if ( ! is_array( $evt ) ) {
		$drift[] = "event[$i] is not an array";
		continue;
	}
	foreach ( $required as $key ) {
		if ( ! array_key_exists( $key, $evt ) ) {
			$drift[] = "event[$i] missing key '$key'";
		}
	}
	if ( isset( $evt['source'] ) && ! in_array( $evt['source'], $sources, true ) ) {
		$drift[] = "event[$i] source='" . $evt['source'] . "' not in ['pm','bm','mtg']";
	}
	if ( isset( $evt['start'] ) && ! preg_match( $iso_re, (string) $evt['start'] ) ) {
		$drift[] = "event[$i] start='" . $evt['start'] . "' is not ISO Z";
	}
	if ( isset( $evt['end'] ) && ! preg_match( $iso_re, (string) $evt['end'] ) ) {
		$drift[] = "event[$i] end='" . $evt['end'] . "' is not ISO Z";
	}
	if ( isset( $evt['all_day'] ) && ! is_bool( $evt['all_day'] ) ) {
		$drift[] = "event[$i] all_day is not bool (got " . gettype( $evt['all_day'] ) . ')';
	}
	if ( isset( $evt['busy'] ) && ! is_bool( $evt['busy'] ) ) {
		$drift[] = "event[$i] busy is not bool (got " . gettype( $evt['busy'] ) . ')';
	}
}

$n_events  = count( $data['events'] );
$n_sources = count( $data['sources_available'] );

if ( ! empty( $drift ) ) {
	gs_uat_fail( 'contract drift across ' . $n_events . ' events: ' . implode( ' | ', array_slice( $drift, 0, 5 ) ) );
	exit( 0 );
}

gs_uat_pass( "envelope + $n_events events conform to LOCKED contract (sources_available=" .
	wp_json_encode( $data['sources_available'] ) . ', user_id=' . $user_id . ')' );
