<?php
/**
 * Phase 27 Plan 01 UAT — AGG-02 + AGG-04 + AGG-06 partial
 *
 * Goal: prove pm_* rows surface for the requesting member ONLY (via
 * wp_pm_assignees.assigned_to JOIN — Pitfall B / AGG-06), every pm_* event is
 * all_day:true (Pitfall A / Pitfall 5), and meetings stub is absent from
 * sources_available until Phase 28/29 ships wp_gs_member_meetings.
 *
 * Run on hub:
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-01-uat-projects-adapter.php --allow-root
 *
 * Required env (member with real pm_assignees rows):
 *   MEMBER_A_USER_ID=<id>     # primary test member (must have assigned tasks)
 *   MEMBER_B_USER_ID=<id>     # cross-check member (any other user)
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

$pm_tasks_table = $wpdb->prefix . 'pm_tasks';
$pm_assignees   = $wpdb->prefix . 'pm_assignees';
$pm_table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_tasks_table ) );
if ( ! $pm_table_exists ) {
	gs_uat_skip( "$pm_tasks_table absent on this blog — projects plugin not installed; cannot UAT pm adapter" );
	exit( 0 );
}

$any_assignees = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm_assignees}" );
if ( $any_assignees === 0 ) {
	gs_uat_skip( "no pm_assignees rows on this site — populate test data before re-running" );
	exit( 0 );
}

// ---- Resolve members ----
$member_a = (int) getenv( 'MEMBER_A_USER_ID' );
$member_b = (int) getenv( 'MEMBER_B_USER_ID' );
if ( $member_a <= 0 ) {
	// Pick any user that has at least one pm_assignees row.
	$member_a = (int) $wpdb->get_var( "SELECT assigned_to FROM {$pm_assignees} LIMIT 1" );
}
if ( $member_a <= 0 ) {
	gs_uat_skip( 'cannot resolve MEMBER_A_USER_ID — set env var or seed pm_assignees' );
	exit( 0 );
}
if ( $member_b <= 0 ) {
	$member_b = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT assigned_to FROM {$pm_assignees} WHERE assigned_to != %d LIMIT 1",
		$member_a
	) );
}
if ( $member_b <= 0 ) {
	// Cross-check leak optional — proceed but skip the B comparison.
	echo 'INFO: no second member found — skipping cross-member leak comparison (only one user has assignees).' . "\n";
}

// ---- Dispatch as member A ----
wp_set_current_user( $member_a );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_param( 'from', gmdate( 'c', strtotime( '-365 days' ) ) );
$req->set_param( 'to',   gmdate( 'c', strtotime( '+365 days' ) ) );
$res = rest_do_request( $req );
if ( $res->get_status() !== 200 ) {
	gs_uat_fail( 'HTTP ' . $res->get_status() . ' for member A' );
	exit( 0 );
}
$data_a = $res->get_data();

// ---- Assert mtg correctly absent (Phase 27 hasn't shipped meetings table) ----
$mtg_table = $wpdb->prefix . 'gs_member_meetings';
$mtg_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mtg_table ) );
if ( ! $mtg_exists && in_array( 'mtg', $data_a['sources_available'], true ) ) {
	gs_uat_fail( "'mtg' in sources_available but $mtg_table table is missing" );
	exit( 0 );
}

// ---- Inspect pm_* events for member A ----
$pm_events = array_filter( $data_a['events'], function ( $e ) {
	return is_array( $e ) && isset( $e['source'] ) && $e['source'] === 'pm';
} );

$problems = array();

foreach ( $pm_events as $evt ) {
	// All pm_* events MUST be all_day:true (Pitfall A).
	if ( empty( $evt['all_day'] ) || $evt['all_day'] !== true ) {
		$problems[] = "event {$evt['id']} all_day != true";
	}

	// Verify assignment for tasks via direct SQL on wp_pm_assignees.
	if ( strpos( $evt['id'], 'pm-task-' ) === 0 ) {
		$task_id = (int) substr( $evt['id'], strlen( 'pm-task-' ) );
		$owned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$pm_assignees} WHERE task_id = %d AND assigned_to = %d",
			$task_id, $member_a
		) );
		if ( $owned === 0 ) {
			$problems[] = "task {$task_id} surfaced for member A but no pm_assignees row matches";
		}
	}
}

// ---- Cross-member leak check (if member_b resolvable) ----
$leak_check = '';
if ( $member_b > 0 ) {
	wp_set_current_user( $member_b );
	$req_b = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
	$req_b->set_param( 'from', gmdate( 'c', strtotime( '-365 days' ) ) );
	$req_b->set_param( 'to',   gmdate( 'c', strtotime( '+365 days' ) ) );
	$res_b  = rest_do_request( $req_b );
	$data_b = $res_b->get_data();

	$a_task_ids = array();
	foreach ( $data_a['events'] as $e ) {
		if ( strpos( $e['id'] ?? '', 'pm-task-' ) === 0 ) {
			$a_task_ids[ (int) substr( $e['id'], strlen( 'pm-task-' ) ) ] = true;
		}
	}
	foreach ( $data_b['events'] as $e ) {
		if ( strpos( $e['id'] ?? '', 'pm-task-' ) === 0 ) {
			$tid = (int) substr( $e['id'], strlen( 'pm-task-' ) );
			if ( isset( $a_task_ids[ $tid ] ) ) {
				// A task that BOTH members are assigned to is legitimate;
				// only flag if assignees table does NOT have member B for that task.
				$shared = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$pm_assignees} WHERE task_id = %d AND assigned_to = %d",
					$tid, $member_b
				) );
				if ( $shared === 0 ) {
					$problems[] = "leak: task $tid in member B response without pm_assignees row for B";
				}
			}
		}
	}
	$leak_check = "; cross-member checked against user $member_b";
}

if ( ! empty( $problems ) ) {
	gs_uat_fail( 'projects-adapter issues: ' . implode( ' | ', array_slice( $problems, 0, 5 ) ) );
	exit( 0 );
}

$pm_count = count( $pm_events );
gs_uat_pass( "$pm_count member-A pm events surfaced (all_day:true; assignee-scoped); mtg correctly " .
	( $mtg_exists ? 'present' : 'absent' ) . " from sources_available$leak_check" );
