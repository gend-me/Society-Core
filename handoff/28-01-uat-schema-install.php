<?php
/**
 * Phase 28-01 UAT — Availability + Meetings schema install verification.
 *
 * AVAIL-05 acceptance probe. READ-ONLY. Safe to run on hub or any subsite.
 * Asserts both wp_*_gs_member_availability + wp_*_gs_member_meetings tables
 * exist on the current blog with the correct column shape + critical indexes
 * + the gs_calendar_db_version per-blog option is set + the Phase 27 meetings
 * adapter now reports is_available()=true (so 'mtg' joins sources_available).
 *
 * Run from inside wp-hub pod (or any subsite pod):
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-01-uat-schema-install.php --allow-root
 *
 * Exits 0 always; lines starting PASS / FAIL / SKIP indicate result.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via `wp eval-file` (ABSPATH undefined).\n" );
	exit( 1 );
}

function gs_uat_pass( $label ) { echo 'PASS · ' . $label . "\n"; }
function gs_uat_fail( $label, $why = '' ) { echo 'FAIL · ' . $label . ( $why !== '' ? ' :: ' . $why : '' ) . "\n"; }
function gs_uat_skip( $label, $why = '' ) { echo 'SKIP · ' . $label . ( $why !== '' ? ' :: ' . $why : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 28-01 schema-install UAT — blog_id=" . get_current_blog_id() . " prefix={$wpdb->prefix} ===\n";

// Assert 1: availability table exists
$tbl_a    = $wpdb->prefix . 'gs_member_availability';
$exists_a = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_a ) );
$exists_a ? gs_uat_pass( $tbl_a . ' exists' ) : gs_uat_fail( $tbl_a . ' missing' );

// Assert 2: meetings table exists (Phase 29 unblock)
$tbl_m    = $wpdb->prefix . 'gs_member_meetings';
$exists_m = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_m ) );
$exists_m ? gs_uat_pass( $tbl_m . ' exists' ) : gs_uat_fail( $tbl_m . ' missing' );

if ( $exists_a ) {
	// Assert 3: availability columns
	$cols_a     = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_a}", 0 );
	$required_a = array( 'id', 'user_id', 'timezone', 'working_hours_json', 'blocked_ranges_json', 'share_token', 'created_at_ts', 'updated_at_ts' );
	$missing_a  = array_diff( $required_a, $cols_a );
	empty( $missing_a )
		? gs_uat_pass( 'availability columns complete' )
		: gs_uat_fail( 'availability columns missing', implode( ',', $missing_a ) );

	// Assert 4: UNIQUE idx_user_id (CRITICAL — ON DUPLICATE KEY UPDATE in Plan 28-02 depends on this).
	$idx_a  = $wpdb->get_results( "SHOW INDEX FROM {$tbl_a} WHERE Key_name = 'idx_user_id'" );
	$unique = $idx_a && (int) $idx_a[0]->Non_unique === 0;
	$unique ? gs_uat_pass( 'idx_user_id is UNIQUE' ) : gs_uat_fail( 'idx_user_id missing or not UNIQUE' );

	// Assert 5: share_token CHAR(43)
	$st = $wpdb->get_row( "SHOW COLUMNS FROM {$tbl_a} WHERE Field = 'share_token'" );
	( $st && stripos( $st->Type, 'char(43)' ) !== false )
		? gs_uat_pass( 'share_token is CHAR(43)' )
		: gs_uat_fail( 'share_token wrong type', $st ? $st->Type : 'missing' );
}

if ( $exists_m ) {
	// Assert 6: meetings columns
	$cols_m     = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_m}", 0 );
	$required_m = array(
		'id', 'member_id', 'guest_user_id', 'guest_name', 'guest_email',
		'meeting_type', 'meeting_meta_json', 'starts_at_utc', 'ends_at_utc',
		'title', 'status', 'jitsi_room', 'cancellation_token',
		'created_at_ts', 'updated_at_ts',
	);
	$missing_m  = array_diff( $required_m, $cols_m );
	empty( $missing_m )
		? gs_uat_pass( 'meetings columns complete' )
		: gs_uat_fail( 'meetings columns missing', implode( ',', $missing_m ) );

	// Assert 7: KEY idx_member_starts (Phase 29 read perf)
	$idx_m = $wpdb->get_results( "SHOW INDEX FROM {$tbl_m} WHERE Key_name = 'idx_member_starts'" );
	$idx_m ? gs_uat_pass( 'idx_member_starts present' ) : gs_uat_fail( 'idx_member_starts missing' );
}

// Assert 8: version option set
$ver = get_option( 'gs_calendar_db_version' );
$ver === '1.0.0'
	? gs_uat_pass( 'gs_calendar_db_version = 1.0.0' )
	: gs_uat_fail( 'gs_calendar_db_version', "got '" . var_export( $ver, true ) . "'" );

// Assert 9: meetings adapter now reports available (Phase 27 integration).
// calendar-events-rest.php declares Gend_GS_Calendar_Source_Meetings whose
// is_available() does SHOW TABLES LIKE wp_*_gs_member_meetings — after this
// installer runs, 'mtg' should appear in sources_available.
if ( class_exists( 'Gend_GS_Calendar_Source_Meetings' ) ) {
	$adapter = new Gend_GS_Calendar_Source_Meetings();
	$adapter->is_available()
		? gs_uat_pass( 'meetings adapter is_available()=true (Phase 27 mtg source activates)' )
		: gs_uat_fail( 'meetings adapter still reports unavailable' );
} else {
	gs_uat_skip( 'Gend_GS_Calendar_Source_Meetings class not loaded — calendar-events-rest.php not on PVC?' );
}

echo "=== done ===\n";
