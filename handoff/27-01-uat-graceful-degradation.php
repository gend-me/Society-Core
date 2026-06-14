<?php
/**
 * Phase 27 Plan 01 UAT — AGG-05 (graceful degradation on missing source tables).
 *
 * Goal: prove the endpoint returns HTTP 200 and `sources_available` exactly
 * reflects which underlying tables are present on this blog. NEVER 500.
 *
 * Run on hub or a bare customer subsite (the latter is the high-value scenario —
 * pm_tasks + bm_social_schedule_v2 + gs_member_meetings all absent should still
 * return 200 with sources_available = []).
 *
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-01-uat-graceful-degradation.php --allow-root
 *
 * Override member via env: WP_USER_ID=<id>
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

global $wpdb;

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

// ---- Snapshot table existence (truth-on-disk) ----
$tables = array(
	'pm'  => $wpdb->prefix . 'pm_tasks',
	'bm'  => $wpdb->prefix . 'bm_social_schedule_v2',
	'mtg' => $wpdb->prefix . 'gs_member_meetings',
);
$present = array();
foreach ( $tables as $key => $table ) {
	$present[ $key ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

// ---- Dispatch ----
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_param( 'from', gmdate( 'c', strtotime( '-7 days' ) ) );
$req->set_param( 'to',   gmdate( 'c', strtotime( '+7 days' ) ) );
$res = rest_do_request( $req );

// AGG-05 core: NEVER 500.
if ( $res->get_status() !== 200 ) {
	gs_uat_fail( 'HTTP ' . $res->get_status() . ' (expected 200 even with missing sources): ' .
		wp_json_encode( $res->get_data() ) );
	exit( 0 );
}

$data = $res->get_data();
if ( ! is_array( $data['sources_available'] ?? null ) ) {
	gs_uat_fail( 'sources_available missing or not an array' );
	exit( 0 );
}

$problems = array();

// ---- Membership must be subset of ['pm','bm','mtg'] ----
foreach ( $data['sources_available'] as $key ) {
	if ( ! in_array( $key, array( 'pm', 'bm', 'mtg' ), true ) ) {
		$problems[] = "sources_available contains unknown key '$key'";
	}
}

// ---- Every key present MUST have its underlying table on disk ----
// (Pitfall 4: a key in sources_available WITHOUT the matching table = bug.)
// NOTE: Plan 27-01 only registers 'pm' + 'mtg' in the adapter array; 'bm'
// is wired in Plan 27-02. So if 'bm' shows up here in 27-01, that's a
// premature registration — flag it.
$plan_01_registered = array( 'pm', 'mtg' );
foreach ( $data['sources_available'] as $key ) {
	if ( ! in_array( $key, $plan_01_registered, true ) ) {
		$problems[] = "sources_available contains '$key' but Plan 27-01 only registers " .
			wp_json_encode( $plan_01_registered );
		continue;
	}
	if ( empty( $present[ $key ] ) ) {
		$problems[] = "sources_available contains '$key' but {$tables[ $key ]} is missing on disk";
	}
}

// ---- Every Plan-01-registered key whose table IS present SHOULD appear ----
foreach ( $plan_01_registered as $key ) {
	if ( ! empty( $present[ $key ] ) && ! in_array( $key, $data['sources_available'], true ) ) {
		$problems[] = "{$tables[ $key ]} is present on disk but '$key' missing from sources_available " .
			'(adapter is_available() returned false or read_events() threw)';
	}
}

if ( ! empty( $problems ) ) {
	gs_uat_fail( implode( ' | ', $problems ) );
	exit( 0 );
}

$present_str = array();
foreach ( $present as $k => $v ) { $present_str[] = "$k=" . ( $v ? 'present' : 'absent' ); }
gs_uat_pass( 'sources_available=' . wp_json_encode( $data['sources_available'] ) .
	' matches table existence on disk (' . implode( ',', $present_str ) . '); HTTP 200 with no fatals' );
