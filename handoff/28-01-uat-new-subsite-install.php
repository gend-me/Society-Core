<?php
/**
 * Phase 28-01 UAT — wp_initialize_site auto-install verification (Pitfall 3).
 *
 * MULTISITE-ONLY. Creates a synthetic subsite via wp_insert_site(), asserts
 * BOTH wp_*_gs_member_availability + wp_*_gs_member_meetings auto-installed
 * on the new blog (proves the wp_initialize_site hook fired), then cleans up
 * via register_shutdown_function -> wp_delete_site() so ZERO residual data.
 *
 * Hub-only — single-site / non-multisite installs SKIP-as-PASS (AVAIL-05 is
 * still satisfied via the sibling 28-01-uat-schema-install.php probe).
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-01-uat-new-subsite-install.php --allow-root
 *
 * Exits 0 always; lines starting PASS / FAIL / SKIP indicate result.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via `wp eval-file` (ABSPATH undefined).\n" );
	exit( 1 );
}

function gs_uat_pass( $l ) { echo 'PASS · ' . $l . "\n"; }
function gs_uat_fail( $l, $w = '' ) { echo 'FAIL · ' . $l . ( $w !== '' ? ' :: ' . $w : '' ) . "\n"; }
function gs_uat_skip( $l, $w = '' ) { echo 'SKIP · ' . $l . ( $w !== '' ? ' :: ' . $w : '' ) . "\n"; }

if ( ! is_multisite() ) {
	gs_uat_skip( 'is_multisite()=false — this UAT is hub-only; AVAIL-05 still passes via 28-01-uat-schema-install.php on a single-site install' );
	return;
}
if ( ! function_exists( 'wp_insert_site' ) ) {
	gs_uat_skip( 'wp_insert_site not available — WP version < 5.1' );
	return;
}

echo "=== Phase 28-01 new-subsite UAT — Pitfall 3 ===\n";

$synthetic_slug = 'gs-uat-28-01-' . substr( wp_generate_password( 8, false, false ), 0, 8 );
$network        = get_network();
$site_data      = array(
	'domain' => $network ? $network->domain : 'gend.me',
	'path'   => '/' . $synthetic_slug . '/',
	'title'  => 'gs uat 28-01 (auto-deleted)',
	'public' => 0,
);

$new_site_id = wp_insert_site( $site_data );
if ( is_wp_error( $new_site_id ) || ! $new_site_id ) {
	gs_uat_fail( 'wp_insert_site failed', is_wp_error( $new_site_id ) ? $new_site_id->get_error_message() : 'unknown' );
	return;
}
gs_uat_pass( "synthetic subsite created (blog_id={$new_site_id}, slug={$synthetic_slug})" );

// CRITICAL: register cleanup BEFORE any assertion that might bail.
register_shutdown_function(
	function () use ( $new_site_id, $synthetic_slug ) {
		if ( ! function_exists( 'wp_delete_site' ) ) {
			echo "WARN · wp_delete_site missing — manual cleanup required for blog_id={$new_site_id} ({$synthetic_slug})\n";
			return;
		}
		$r = wp_delete_site( $new_site_id );
		is_wp_error( $r )
			? printf( "WARN · wp_delete_site failed for blog_id=%d: %s\n", $new_site_id, $r->get_error_message() )
			: printf( "CLEAN · synthetic subsite deleted (blog_id=%d)\n", $new_site_id );
	}
);

// Assert on the NEW blog: both tables auto-installed via wp_initialize_site hook.
switch_to_blog( (int) $new_site_id );
global $wpdb;
$tbl_a = $wpdb->prefix . 'gs_member_availability';
$tbl_m = $wpdb->prefix . 'gs_member_meetings';
$ea    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_a ) );
$em    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_m ) );
$ea
	? gs_uat_pass( $tbl_a . ' auto-installed on new subsite' )
	: gs_uat_fail( $tbl_a . ' NOT installed — wp_initialize_site hook broken?' );
$em
	? gs_uat_pass( $tbl_m . ' auto-installed on new subsite' )
	: gs_uat_fail( $tbl_m . ' NOT installed — wp_initialize_site hook broken?' );

$ver = get_option( 'gs_calendar_db_version' );
$ver === '1.0.0'
	? gs_uat_pass( 'gs_calendar_db_version set on new subsite (=1.0.0)' )
	: gs_uat_fail( 'gs_calendar_db_version not set on new subsite', "got '" . var_export( $ver, true ) . "'" );
restore_current_blog();

echo "=== done — synthetic subsite cleanup runs at shutdown ===\n";
