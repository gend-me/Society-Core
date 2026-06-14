<?php
/**
 * Phase 27 Plan 02 UAT — Pitfall 8 (N+1 query budget).
 *
 * Goal: prove per-call query count ≤ 6 DATA queries against the three source
 * tables (pm_tasks|pm_assignees|pm_boards|pm_meta|pm_projects|bm_social_schedule_v2|
 * bm_drip_queue|gs_member_meetings). SHOW TABLES guards excluded from the budget
 * (they are unavoidable per RESEARCH §N+1 Query Budget Analysis ledger).
 *
 * Per-source data budget:
 *   Projects: ≤ 3 (Q1 tasks JOIN, Q2a milestones IN-batch, Q2b est_completion IN-batch)
 *   BlogManager: ≤ 2 (Q1 social, Q2 drip)
 *   Meetings: 0 (stub returns [])
 *   TOTAL: ≤ 5 data queries (well under the ≤ 6 budget)
 *
 * Run on hub:
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-02-uat-n1-budget.php --allow-root
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

// SAVEQUERIES must be set before WordPress bootstrap normally — but `wp eval-file`
// loads WP first. If SAVEQUERIES is already true (e.g. wp-config has it on the
// staging environment), great. Otherwise the script can still set it now and
// the NEXT queries (including our dispatch) will be captured because $wpdb
// checks the constant at query time.
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

global $wpdb;

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

// ---- Dispatch a normal request and capture query delta ----
$before = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;

$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_param( 'from', gmdate( 'c', strtotime( '-30 days' ) ) );
$req->set_param( 'to',   gmdate( 'c', strtotime( '+30 days' ) ) );
$res = rest_do_request( $req );

$after = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
$delta = $after - $before;

if ( $delta === 0 || ! is_array( $wpdb->queries ) ) {
	gs_uat_skip( 'SAVEQUERIES not capturing — add `define(\'SAVEQUERIES\', true);` to wp-config.php and re-run' );
	exit( 0 );
}

// ---- Categorize the delta-window queries ----
$window = array_slice( $wpdb->queries, $before, $delta );

$data_tables_re = '/\b(pm_tasks|pm_assignees|pm_boards|pm_meta|pm_projects|bm_social_schedule_v2|bm_drip_queue|gs_member_meetings)\b/i';
$guards_re      = '/^\s*SHOW\s+TABLES\s+LIKE\b/i';

$data_queries   = array();
$guard_queries  = array();
$other_queries  = array();

foreach ( $window as $q ) {
	$sql = (string) ( $q[0] ?? '' );
	if ( preg_match( $guards_re, $sql ) ) {
		$guard_queries[] = $sql;
	} elseif ( preg_match( $data_tables_re, $sql ) ) {
		$data_queries[] = $sql;
	} else {
		$other_queries[] = $sql;
	}
}

// Per-source breakdown
$breakdown = array( 'pm' => 0, 'bm' => 0, 'mtg' => 0 );
foreach ( $data_queries as $sql ) {
	if ( preg_match( '/\b(pm_tasks|pm_assignees|pm_boards|pm_meta|pm_projects)\b/i', $sql ) ) {
		$breakdown['pm']++;
	} elseif ( preg_match( '/\b(bm_social_schedule_v2|bm_drip_queue)\b/i', $sql ) ) {
		$breakdown['bm']++;
	} elseif ( preg_match( '/\bgs_member_meetings\b/i', $sql ) ) {
		$breakdown['mtg']++;
	}
}

$data_count   = count( $data_queries );
$guard_count  = count( $guard_queries );
$total_window = count( $window );

if ( $res->get_status() !== 200 ) {
	gs_uat_fail( 'HTTP ' . $res->get_status() . ' from REST dispatch — cannot evaluate budget cleanly' );
	exit( 0 );
}

if ( $data_count > 6 ) {
	$sample = array();
	foreach ( array_slice( $data_queries, 0, 6 ) as $i => $sql ) {
		$sample[] = '[' . ( $i + 1 ) . '] ' . substr( preg_replace( '/\s+/', ' ', $sql ), 0, 120 );
	}
	gs_uat_fail( "data_count=$data_count exceeds budget of 6 (pm={$breakdown['pm']}, bm={$breakdown['bm']}, mtg={$breakdown['mtg']}, guards=$guard_count). Sample: " . implode( ' | ', $sample ) );
	exit( 0 );
}

gs_uat_pass( "data_count=$data_count <= 6 (pm={$breakdown['pm']}, bm={$breakdown['bm']}, mtg={$breakdown['mtg']}); " .
	"guards=$guard_count; total_window=$total_window; user_id=$user_id" );
