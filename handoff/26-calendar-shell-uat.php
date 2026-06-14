<?php
/**
 * Phase 26 — Calendar Tab + Glassmorphic UI Shell — UAT / acceptance script.
 *
 * Project convention: each phase ships its own handoff/*.php, runnable on the
 * target site via:
 *
 *     wp eval-file wp-content/plugins/gend-society/handoff/26-calendar-shell-uat.php --allow-root
 *
 * This script is the PVC-ordering + auto-deactivation CANARY for the Phase 26
 * hub deploy (Plan 26-03). It prints [PASS]/[FAIL]/[SKIP] per check and a final
 * ALL CHECKS PASSED / SOME CHECKS FAILED line.
 *
 * What it asserts:
 *   1. The three new files (inc/member-calendar.php, assets/member-calendar.css,
 *      assets/member-calendar.js) exist on disk under GS_DIR. If any is missing,
 *      the entrypoint was copied BEFORE its dependencies — fail loud.
 *   2. The calendar tab functions are registered (function_exists).
 *   3. The 'calendar' nav icon resolves to a non-empty SVG string.
 *   4. gend-society is active (network + single-site) AND recently_activated does
 *      NOT list the gend-society entrypoint — WP silently auto-deactivates plugins
 *      that fatal in admin context (Pitfall 2). Raw recently_activated is printed.
 *   5. Best-effort nav position: if BuddyPress nav is built, assert the
 *      member-calendar primary item sits at groups.position + 1. In CLI the BP
 *      nav usually is not built — that case SKIPs (verified in the browser
 *      checkpoint instead).
 *   6. Informational: whether the source tables (wp_pm_tasks /
 *      wp_bm_social_schedule_v2) exist on THIS site, so the operator knows if the
 *      run is on the hub (sources present) or a bare subsite (sources absent).
 *      Phase 26 has no aggregation yet, so this is informational only — the tab
 *      must register regardless.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must be run via `wp eval-file` (ABSPATH undefined).\n" );
	exit( 1 );
}

$gs_uat_pass = 0;
$gs_uat_fail = 0;
$gs_uat_skip = 0;

/**
 * Emit a PASS/FAIL/SKIP line and tally it.
 */
function gs_uat_check( $label, $ok, $detail = '' ) {
	global $gs_uat_pass, $gs_uat_fail;
	if ( $ok ) {
		$gs_uat_pass++;
		echo "[PASS] {$label}";
	} else {
		$gs_uat_fail++;
		echo "[FAIL] {$label}";
	}
	if ( $detail !== '' ) {
		echo " — {$detail}";
	}
	echo "\n";
}

function gs_uat_skip( $label, $detail = '' ) {
	global $gs_uat_skip;
	$gs_uat_skip++;
	echo "[SKIP] {$label}";
	if ( $detail !== '' ) {
		echo " — {$detail}";
	}
	echo "\n";
}

echo "==============================================================\n";
echo " Phase 26 — Calendar Tab + Glassmorphic UI Shell — UAT\n";
echo "==============================================================\n\n";

/* ---------------------------------------------------------------------------
 * 0. Resolve GS_DIR (the entrypoint defines it; if undefined the plugin is not
 *    loaded — every later check would be meaningless, so derive a fallback).
 * ------------------------------------------------------------------------- */
if ( defined( 'GS_DIR' ) ) {
	$gs_dir = GS_DIR;
	echo "GS_DIR (from constant): {$gs_dir}\n";
} else {
	$gs_dir = trailingslashit( WP_PLUGIN_DIR ) . 'gend-society/';
	echo "GS_DIR not defined (plugin entrypoint may not be loaded); using fallback: {$gs_dir}\n";
}
echo "\n";

/* ---------------------------------------------------------------------------
 * CHECK 1 — new files present on disk (PVC-ordering canary).
 * ------------------------------------------------------------------------- */
echo "--- Check 1: new files present on disk (PVC-ordering canary) ---\n";
$gs_required_files = [
	'inc/member-calendar.php',
	'assets/member-calendar.css',
	'assets/member-calendar.js',
];
foreach ( $gs_required_files as $rel ) {
	$abs = $gs_dir . $rel;
	gs_uat_check( "file present: {$rel}", file_exists( $abs ), file_exists( $abs ) ? '' : "MISSING at {$abs} — entrypoint copied before dependency?" );
}
echo "\n";

/* ---------------------------------------------------------------------------
 * CHECK 2 — calendar functions registered.
 * ------------------------------------------------------------------------- */
echo "--- Check 2: calendar functions registered ---\n";
$gs_required_funcs = [
	'gs_add_calendar_profile_tab',
	'gs_calendar_profile_screen',
	'gs_calendar_profile_screen_content',
];
foreach ( $gs_required_funcs as $fn ) {
	gs_uat_check( "function_exists: {$fn}", function_exists( $fn ) );
}
echo "\n";

/* ---------------------------------------------------------------------------
 * CHECK 3 — 'calendar' nav icon resolves.
 * ------------------------------------------------------------------------- */
echo "--- Check 3: 'calendar' nav icon resolves ---\n";
if ( function_exists( 'gdc_get_profile_nav_icon' ) ) {
	$icon = gdc_get_profile_nav_icon( 'calendar' );
	$icon_ok = is_string( $icon ) && trim( $icon ) !== '';
	gs_uat_check( "gdc_get_profile_nav_icon('calendar') non-empty", $icon_ok, $icon_ok ? ( strlen( $icon ) . ' chars' ) : 'empty/non-string' );
} else {
	gs_uat_check( "gdc_get_profile_nav_icon available", false, 'function missing — member-profile-header.php not loaded' );
}
echo "\n";

/* ---------------------------------------------------------------------------
 * CHECK 4 — plugin health + auto-deactivation canary.
 * ------------------------------------------------------------------------- */
echo "--- Check 4: plugin health + auto-deactivation canary (Pitfall 2) ---\n";
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Locate the gend-society entrypoint plugin file ("gend-society/<entry>.php").
$gs_plugin_file = '';
$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];
foreach ( array_keys( $all_plugins ) as $pfile ) {
	if ( strpos( $pfile, 'gend-society/' ) === 0 ) {
		$gs_plugin_file = $pfile;
		break;
	}
}
if ( $gs_plugin_file === '' ) {
	// Fallback to the conventional path even if get_plugins() missed it.
	$gs_plugin_file = 'gend-society/gend-society.php';
}
echo "Resolved entrypoint: {$gs_plugin_file}\n";

$is_active         = is_plugin_active( $gs_plugin_file );
$is_network_active = function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $gs_plugin_file );
gs_uat_check(
	'gend-society active (single or network)',
	$is_active || $is_network_active,
	'single=' . ( $is_active ? 'yes' : 'no' ) . ' network=' . ( $is_network_active ? 'yes' : 'no' )
);

// recently_activated MUST NOT list the gend-society entrypoint (it would mean WP
// auto-deactivated it after a fatal in admin context).
$recently = get_option( 'recently_activated', [] );
if ( ! is_array( $recently ) ) {
	$recently = [];
}
echo 'Raw recently_activated: ' . ( empty( $recently ) ? '(empty)' : implode( ', ', array_keys( $recently ) ) ) . "\n";
$gs_in_recently = false;
foreach ( array_keys( $recently ) as $rk ) {
	if ( strpos( $rk, 'gend-society/' ) === 0 ) {
		$gs_in_recently = true;
		break;
	}
}
gs_uat_check(
	'gend-society NOT in recently_activated (no auto-deactivation)',
	! $gs_in_recently,
	$gs_in_recently ? 'PLUGIN WAS AUTO-DEACTIVATED — investigate fatal, then `wp plugin activate --network gend-society`' : ''
);
echo "\n";

/* ---------------------------------------------------------------------------
 * CHECK 5 — nav position sanity (best-effort; SKIP if BP nav not built).
 * ------------------------------------------------------------------------- */
echo "--- Check 5: nav position (calendar == groups + 1; best-effort) ---\n";
$bp_ready = function_exists( 'buddypress' )
	&& isset( buddypress()->members )
	&& isset( buddypress()->members->nav )
	&& is_object( buddypress()->members->nav )
	&& method_exists( buddypress()->members->nav, 'get_primary' );

if ( $bp_ready ) {
	$primary = buddypress()->members->nav->get_primary();
	$groups_pos   = null;
	$calendar_pos = null;
	if ( is_array( $primary ) ) {
		foreach ( $primary as $item ) {
			$slug = is_array( $item ) ? ( $item['slug'] ?? '' ) : ( $item->slug ?? '' );
			$posn = is_array( $item ) ? ( $item['position'] ?? null ) : ( $item->position ?? null );
			if ( $slug === 'groups' ) {
				$groups_pos = (int) $posn;
			} elseif ( $slug === 'member-calendar' ) {
				$calendar_pos = (int) $posn;
			}
		}
	}
	if ( $groups_pos === null || $calendar_pos === null ) {
		gs_uat_skip(
			'calendar position vs groups+1',
			'BP nav built but groups/member-calendar item not found in CLI context (groups=' . var_export( $groups_pos, true ) . ', calendar=' . var_export( $calendar_pos, true ) . ') — verify in browser checkpoint'
		);
	} else {
		gs_uat_check(
			'calendar position == groups + 1',
			$calendar_pos === $groups_pos + 1,
			"groups={$groups_pos}, calendar={$calendar_pos}"
		);
	}
} else {
	gs_uat_skip( 'calendar position vs groups+1', 'BP member nav not built in this (CLI) context — position is verified in the browser checkpoint (Task 3)' );
}
echo "\n";

/* ---------------------------------------------------------------------------
 * CHECK 6 — source-table presence (informational only).
 * ------------------------------------------------------------------------- */
echo "--- Check 6: source tables present? (informational — no aggregation yet) ---\n";
global $wpdb;
$source_tables = [
	$wpdb->prefix . 'pm_tasks',
	$wpdb->prefix . 'bm_social_schedule_v2',
];
$present_count = 0;
foreach ( $source_tables as $tbl ) {
	$found = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
	if ( $found ) {
		$present_count++;
	}
	echo '  ' . ( $found ? '[present]' : '[absent ]' ) . " {$tbl}\n";
}
if ( $present_count === count( $source_tables ) ) {
	echo "  => All source tables present — this looks like the HUB (or a fully-featured site).\n";
} elseif ( $present_count === 0 ) {
	echo "  => No source tables — this looks like a BARE SUBSITE. Phase 26 has no aggregation yet, so the calendar tab must STILL register (graceful degradation).\n";
} else {
	echo "  => Some source tables present — partial feature set. Calendar tab must register regardless.\n";
}
echo "\n";

/* ---------------------------------------------------------------------------
 * SUMMARY
 * ------------------------------------------------------------------------- */
echo "==============================================================\n";
echo " RESULT: {$gs_uat_pass} passed, {$gs_uat_fail} failed, {$gs_uat_skip} skipped\n";
if ( $gs_uat_fail === 0 ) {
	echo " ALL CHECKS PASSED\n";
} else {
	echo " SOME CHECKS FAILED\n";
}
echo "==============================================================\n";
