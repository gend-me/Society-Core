<?php
/**
 * Phase 27 Plan 02 UAT — AGG-03 (BlogManager adapter surfaces social + drip).
 *
 * Goal: prove bm_* rows surface via the aggregator for the requesting member,
 * every event conforms to the LOCKED 11-field contract, type distinguishes
 * 'social' vs 'drip', timestamps are UTC-Z (NOT shifted), busy:false (display-
 * only per REQUIREMENTS Out-of-Scope), 'bm' appears in sources_available.
 *
 * Run on hub:
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-02-uat-bm-adapter.php --allow-root
 *
 * Optional env: MEMBER_USER_ID=<id>  (defaults to first user with bm_social rows)
 *
 * Exits 0 always; lines starting PASS / FAIL / SKIP indicate result.
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

global $wpdb;

$bm_table = $wpdb->prefix . 'bm_social_schedule_v2';
$bm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bm_table ) );
if ( ! $bm_exists ) {
	gs_uat_skip( "$bm_table absent on this blog — blog-manager not installed; cannot UAT bm adapter" );
	exit( 0 );
}

// ---- Resolve member ----
$env_uid = (int) getenv( 'MEMBER_USER_ID' );
$user_id = $env_uid > 0 ? $env_uid : 0;
if ( $user_id <= 0 ) {
	$user_id = (int) $wpdb->get_var( "SELECT user_id FROM {$bm_table} ORDER BY scheduled_at DESC LIMIT 1" );
}
if ( $user_id <= 0 ) {
	gs_uat_skip( "no rows in $bm_table to derive a test member — insert at least 1 row in wp_bm_social_schedule_v2 (or set MEMBER_USER_ID) to exercise this UAT" );
	exit( 0 );
}

wp_set_current_user( $user_id );

// ---- Dispatch ±60 day window ----
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_param( 'from', gmdate( 'c', strtotime( '-60 days' ) ) );
$req->set_param( 'to',   gmdate( 'c', strtotime( '+60 days' ) ) );
$res = rest_do_request( $req );

if ( $res->get_status() !== 200 ) {
	gs_uat_fail( 'HTTP ' . $res->get_status() . ' (expected 200): ' . wp_json_encode( $res->get_data() ) );
	exit( 0 );
}

$data = $res->get_data();

if ( ! is_array( $data ) || ! is_array( $data['events'] ?? null ) || ! is_array( $data['sources_available'] ?? null ) ) {
	gs_uat_fail( 'envelope shape invalid (sources_available / events not arrays)' );
	exit( 0 );
}

// ---- Assert 'bm' in sources_available (AGG-03 closure) ----
if ( ! in_array( 'bm', $data['sources_available'], true ) ) {
	gs_uat_fail( "'bm' missing from sources_available (got " . wp_json_encode( $data['sources_available'] ) .
		') — adapter is_available() may have returned false or read_events() threw' );
	exit( 0 );
}

// ---- Inspect bm_* events ----
$bm_events = array_values( array_filter( $data['events'], function ( $e ) {
	return is_array( $e ) && isset( $e['source'] ) && $e['source'] === 'bm';
} ) );

$iso_z_re   = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
$bm_types   = array( 'social', 'drip' );
$required   = array( 'id', 'source', 'type', 'title', 'start', 'end', 'all_day', 'color', 'status', 'url', 'busy' );

$problems    = array();
$count_social = 0;
$count_drip   = 0;

foreach ( $bm_events as $i => $evt ) {
	// LOCKED 11-field contract verbatim
	foreach ( $required as $k ) {
		if ( ! array_key_exists( $k, $evt ) ) {
			$problems[] = "bm[$i] missing key '$k'";
		}
	}
	// Type must be social or drip
	if ( isset( $evt['type'] ) && ! in_array( $evt['type'], $bm_types, true ) ) {
		$problems[] = "bm[$i] type='" . $evt['type'] . "' not in ['social','drip']";
	}
	// bm_* is timed — all_day must be false
	if ( isset( $evt['all_day'] ) && $evt['all_day'] !== false ) {
		$problems[] = "bm[$i] all_day must be false (got " . var_export( $evt['all_day'], true ) . ')';
	}
	// busy:false on every bm_* event (REQUIREMENTS Out of Scope)
	if ( isset( $evt['busy'] ) && $evt['busy'] !== false ) {
		$problems[] = "bm[$i] busy must be false (display-only — got " . var_export( $evt['busy'], true ) . ')';
	}
	// start/end ISO with Z (UTC-Z preserved verbatim)
	if ( isset( $evt['start'] ) && ! preg_match( $iso_z_re, (string) $evt['start'] ) ) {
		$problems[] = "bm[$i] start='" . $evt['start'] . "' is not 'YYYY-MM-DDTHH:MM:SSZ'";
	}
	if ( isset( $evt['end'] ) && ! preg_match( $iso_z_re, (string) $evt['end'] ) ) {
		$problems[] = "bm[$i] end='" . $evt['end'] . "' is not 'YYYY-MM-DDTHH:MM:SSZ'";
	}
	// ID prefix check
	if ( isset( $evt['type'], $evt['id'] ) ) {
		if ( $evt['type'] === 'social' && strpos( $evt['id'], 'bm-social-' ) !== 0 ) {
			$problems[] = "bm[$i] type=social but id='" . $evt['id'] . "' lacks 'bm-social-' prefix";
		}
		if ( $evt['type'] === 'drip' && strpos( $evt['id'], 'bm-drip-' ) !== 0 ) {
			$problems[] = "bm[$i] type=drip but id='" . $evt['id'] . "' lacks 'bm-drip-' prefix";
		}
	}
	if ( isset( $evt['type'] ) ) {
		if ( $evt['type'] === 'social' ) { $count_social++; }
		if ( $evt['type'] === 'drip'   ) { $count_drip++;   }
	}
}

if ( count( $bm_events ) === 0 ) {
	gs_uat_skip( "user $user_id has no bm_* events in ±60d window — insert a scheduled_at row to exercise the conformance assertions" );
	exit( 0 );
}

if ( ! empty( $problems ) ) {
	gs_uat_fail( 'bm-adapter conformance drift across ' . count( $bm_events ) . ' events: ' .
		implode( ' | ', array_slice( $problems, 0, 6 ) ) );
	exit( 0 );
}

gs_uat_pass( "bm surfaces $count_social social + $count_drip drip events for user $user_id, all conform " .
	'to LOCKED 11-field contract (UTC-Z preserved, busy:false, all_day:false); ' .
	"'bm' in sources_available=" . wp_json_encode( $data['sources_available'] ) );
