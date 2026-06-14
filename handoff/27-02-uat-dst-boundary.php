<?php
/**
 * Phase 27 Plan 02 UAT — AGG-07 (timezone / DST correctness).
 *
 * Two probes:
 *   1. bm DST flip-day probe — INSERT into wp_bm_social_schedule_v2 a row
 *      with scheduled_at = '2026-11-01 06:00:00' (06:00 UTC on the EDT→EST
 *      flip day = 02:00 EDT, the last hour of EDT before fall-back). Assert
 *      the REST response returns start='2026-11-01T06:00:00Z' VERBATIM — NO
 *      tz conversion, just an appended Z. (Frontend converts to viewer-tz
 *      via Intl.DateTimeFormat, proven in Plan 26-02 dayKeyInTz.)
 *   2. pm date-only probe — if pm_tasks exists AND the test member has at
 *      least one assignee row, INSERT a probe wp_pm_tasks.due_date='2026-06-15'
 *      assigned to the member. Assert returned event has all_day=true,
 *      start='2026-06-15T00:00:00Z', end='2026-06-15T23:59:59Z' (Pitfall A —
 *      date-only must NOT shift by viewer offset).
 *
 * BOTH probes clean up their inserted rows in a register_shutdown_function
 * block so this UAT leaves ZERO residual test data.
 *
 * Run on hub (writes! — requires real member):
 *   MEMBER_USER_ID=<id> wp eval-file wp-content/plugins/gend-society/handoff/27-02-uat-dst-boundary.php --allow-root
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

$bm_social   = $wpdb->prefix . 'bm_social_schedule_v2';
$pm_tasks    = $wpdb->prefix . 'pm_tasks';
$pm_assignees = $wpdb->prefix . 'pm_assignees';

$bm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bm_social ) );
$pm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_tasks ) );

// ---- Resolve member ----
$env_uid = (int) getenv( 'MEMBER_USER_ID' );
$user_id = $env_uid > 0 ? $env_uid : 0;
if ( $user_id <= 0 && $bm_exists ) {
	$user_id = (int) $wpdb->get_var( "SELECT user_id FROM {$bm_social} ORDER BY id LIMIT 1" );
}
if ( $user_id <= 0 && $pm_exists ) {
	$user_id = (int) $wpdb->get_var( "SELECT assigned_to FROM {$pm_assignees} ORDER BY id LIMIT 1" );
}
if ( $user_id <= 0 ) {
	gs_uat_skip( 'no member resolvable (set MEMBER_USER_ID or seed pm_assignees / bm_social_schedule_v2)' );
	exit( 0 );
}
wp_set_current_user( $user_id );

// ---- Cleanup hooks (always-fire even on PHP fatal/exit) ----
$probe_bm_id = 0;
$probe_pm_id = 0;
register_shutdown_function( function () use ( &$probe_bm_id, &$probe_pm_id, $bm_social, $pm_tasks, $pm_assignees ) {
	global $wpdb;
	if ( $probe_bm_id > 0 ) {
		$wpdb->delete( $bm_social, array( 'id' => $probe_bm_id ), array( '%d' ) );
	}
	if ( $probe_pm_id > 0 ) {
		$wpdb->delete( $pm_assignees, array( 'task_id' => $probe_pm_id ), array( '%d' ) );
		$wpdb->delete( $pm_tasks,     array( 'id'      => $probe_pm_id ), array( '%d' ) );
	}
} );

$problems = array();
$summary  = array();

// =========================================================================
// PROBE 1: bm DST flip-day
// =========================================================================
if ( ! $bm_exists ) {
	$summary[] = 'bm-probe SKIPPED (table absent)';
} else {
	$ok = $wpdb->insert(
		$bm_social,
		array(
			'user_id'           => $user_id,
			'platform'          => 'twitter',
			'scheduled_at'      => '2026-11-01 06:00:00',  // EDT→EST flip day, 06:00 UTC = 02:00 EDT
			'status'            => 'scheduled',
			'copy_per_platform' => wp_json_encode( array( 'twitter' => 'DST probe — REST-UAT' ) ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);
	if ( ! $ok ) {
		$problems[] = 'bm probe INSERT failed: ' . $wpdb->last_error;
	} else {
		$probe_bm_id = (int) $wpdb->insert_id;

		$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
		$req->set_param( 'from', '2026-10-30T00:00:00Z' );
		$req->set_param( 'to',   '2026-11-03T00:00:00Z' );
		$res  = rest_do_request( $req );
		$data = $res->get_data();

		$found = null;
		foreach ( ( $data['events'] ?? array() ) as $e ) {
			if ( ( $e['id'] ?? '' ) === 'bm-social-' . $probe_bm_id ) {
				$found = $e;
				break;
			}
		}
		if ( ! $found ) {
			$problems[] = 'bm probe event bm-social-' . $probe_bm_id . ' NOT in response (status=' .
				$res->get_status() . ')';
		} else {
			if ( $found['start'] !== '2026-11-01T06:00:00Z' ) {
				$problems[] = "bm probe start='" . $found['start'] .
					"' (expected '2026-11-01T06:00:00Z' — VERBATIM Z append, NO tz shift)";
			}
			if ( $found['all_day'] !== false ) {
				$problems[] = 'bm probe all_day=' . var_export( $found['all_day'], true ) . ' (expected false)';
			}
			if ( $found['busy'] !== false ) {
				$problems[] = 'bm probe busy=' . var_export( $found['busy'], true ) . ' (expected false — display-only)';
			}
			$summary[] = "bm-probe OK (id={$probe_bm_id}, start={$found['start']})";
		}
	}
}

// =========================================================================
// PROBE 2: pm date-only
// =========================================================================
if ( ! $pm_exists ) {
	$summary[] = 'pm-probe SKIPPED (table absent)';
} else {
	// pm_tasks schema requires a project_id — find any project the member is
	// already assigned to (so we don't pollute project_id=0).
	$project_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT t.project_id FROM {$pm_tasks} AS t
		 INNER JOIN {$pm_assignees} AS a ON a.task_id = t.id AND a.assigned_to = %d
		 LIMIT 1",
		$user_id
	) );
	if ( $project_id <= 0 ) {
		$summary[] = 'pm-probe SKIPPED (no existing project_id for member ' . $user_id . ')';
	} else {
		// Insert probe task with due_date='2026-06-15' (date-only naive — Pitfall A).
		$ok = $wpdb->insert(
			$pm_tasks,
			array(
				'title'       => 'P27-02 DST UAT probe (date-only)',
				'project_id'  => $project_id,
				'due_date'    => '2026-06-15 00:00:00',
				'status'      => 1,
				'parent_id'   => 0,
				'created_by'  => $user_id,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			$summary[] = 'pm-probe SKIPPED (insert failed: ' . $wpdb->last_error . ')';
		} else {
			$probe_pm_id = (int) $wpdb->insert_id;
			$wpdb->insert(
				$pm_assignees,
				array(
					'task_id'     => $probe_pm_id,
					'assigned_to' => $user_id,
					'project_id'  => $project_id,
				),
				array( '%d', '%d', '%d' )
			);

			$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
			$req->set_param( 'from', '2026-06-13T00:00:00Z' );
			$req->set_param( 'to',   '2026-06-17T00:00:00Z' );
			$res  = rest_do_request( $req );
			$data = $res->get_data();

			$found = null;
			foreach ( ( $data['events'] ?? array() ) as $e ) {
				if ( ( $e['id'] ?? '' ) === 'pm-task-' . $probe_pm_id ) {
					$found = $e;
					break;
				}
			}
			if ( ! $found ) {
				$problems[] = 'pm probe event pm-task-' . $probe_pm_id . ' NOT in response (status=' .
					$res->get_status() . ')';
			} else {
				if ( $found['all_day'] !== true ) {
					$problems[] = 'pm probe all_day=' . var_export( $found['all_day'], true ) . ' (expected true — Pitfall A)';
				}
				if ( $found['start'] !== '2026-06-15T00:00:00Z' ) {
					$problems[] = "pm probe start='" . $found['start'] . "' (expected '2026-06-15T00:00:00Z')";
				}
				if ( $found['end'] !== '2026-06-15T23:59:59Z' ) {
					$problems[] = "pm probe end='" . $found['end'] . "' (expected '2026-06-15T23:59:59Z')";
				}
				$summary[] = "pm-probe OK (id={$probe_pm_id}, all_day=true, start={$found['start']}, end={$found['end']})";
			}
		}
	}
}

if ( ! empty( $problems ) ) {
	gs_uat_fail( 'DST/all_day correctness drift: ' . implode( ' | ', $problems ) .
		' [' . implode( '; ', $summary ) . ']' );
	exit( 0 );
}

gs_uat_pass( implode( '; ', $summary ) . ' — both probes preserve UTC verbatim (NO tz conversion server-side)' );
