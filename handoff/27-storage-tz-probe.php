<?php
/**
 * Phase 27 — Storage-timezone probe.
 *
 * Purpose: confirm (or refute) the two storage-tz claims this phase is built on
 * BEFORE Plan 27-02 codes against bm_*:
 *   1. wp_pm_tasks.due_date + start_at are DATE-ONLY naive (not tz-aware DATETIME)
 *   2. wp_bm_social_schedule_v2.scheduled_at is TRUE UTC DATETIME
 *
 * Run on the hub:
 *   wp eval-file wp-content/plugins/gend-society/handoff/27-storage-tz-probe.php --allow-root
 *
 * Exit is always 0 — output is informational, parsed by the operator
 * (and by Plan 27-02 Task 3 as a go/no-go gate for the bm_* adapter).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via `wp eval-file` (ABSPATH undefined).\n" );
	exit( 1 );
}

global $wpdb;

echo "=== Phase 27 storage-tz probe ===\n";
echo "Server clock (PHP local): " . date( 'c' )                        . "\n";
echo "Server clock (UTC):       " . gmdate( 'c' )                       . "\n";
echo "WP gmt_offset option:     " . (string) get_option( 'gmt_offset' )  . "\n";
echo "WP timezone_string:       " . (string) get_option( 'timezone_string' ) . "\n";
echo "MySQL NOW():              " . (string) $wpdb->get_var( 'SELECT NOW()' )           . "\n";
echo "MySQL UTC_TIMESTAMP():    " . (string) $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' ) . "\n";
echo "MySQL @@session.time_zone:" . (string) $wpdb->get_var( 'SELECT @@session.time_zone' ) . "\n\n";

// ---- pm_tasks: expect date-only naive ('YYYY-MM-DD 00:00:00' form) ----
$pm_tasks = $wpdb->prefix . 'pm_tasks';
$pm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_tasks ) );
if ( $pm_exists ) {
	echo "Sample {$pm_tasks} rows (due_date, start_at — EXPECT 'YYYY-MM-DD 00:00:00'):\n";
	$rows = $wpdb->get_results(
		"SELECT id, title, start_at, due_date FROM {$pm_tasks}
		 WHERE due_date IS NOT NULL AND due_date != '0000-00-00 00:00:00'
		 ORDER BY id DESC LIMIT 3",
		ARRAY_A
	);
	print_r( $rows );
} else {
	echo "{$pm_tasks} table NOT present on this blog — projects plugin absent.\n";
}

echo "\n";

// ---- bm_social_schedule_v2: expect true UTC DATETIME ----
$bm_sched = $wpdb->prefix . 'bm_social_schedule_v2';
$bm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bm_sched ) );
if ( $bm_exists ) {
	echo "Sample {$bm_sched} rows (scheduled_at — EXPECT true UTC; compare to UTC_TIMESTAMP above):\n";
	$rows = $wpdb->get_results(
		"SELECT id, user_id, scheduled_at, status FROM {$bm_sched}
		 ORDER BY id DESC LIMIT 3",
		ARRAY_A
	);
	print_r( $rows );
} else {
	echo "{$bm_sched} table NOT present on this blog — blog-manager absent.\n";
}

echo "\n";

// ---- pm_projects.est_completion_date: expect date-only naive ----
$pm_projects = $wpdb->prefix . 'pm_projects';
$pp_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_projects ) );
if ( $pp_exists ) {
	echo "Sample {$pm_projects} rows (est_completion_date — EXPECT 'YYYY-MM-DD' or NULL):\n";
	$rows = $wpdb->get_results(
		"SELECT id, title, est_completion_date FROM {$pm_projects}
		 WHERE est_completion_date IS NOT NULL
		 ORDER BY id DESC LIMIT 3",
		ARRAY_A
	);
	print_r( $rows );
} else {
	echo "{$pm_projects} table NOT present on this blog.\n";
}

echo "\n=== End probe ===\n";
echo "INTERPRETATION:\n";
echo " - pm_* sample rows with HH:MM:SS == 00:00:00 → date-only confirmed (Pitfall A holds).\n";
echo " - bm_* scheduled_at within minutes of UTC_TIMESTAMP() above → true-UTC confirmed.\n";
echo " - If pm_* shows non-zero time components OR bm_* drifts from UTC_TIMESTAMP by hours,\n";
echo "   STOP and revisit RESEARCH.md §Timezone Normalization before Plan 27-02 ships.\n";
