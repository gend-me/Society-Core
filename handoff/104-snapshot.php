<?php
/**
 * Phase 104 before/after snapshot (FND-02 equivalence evidence).
 *
 * Read-only: no option, user or transient writes. Run per site, before and
 * after a deploy, and diff the JSON:
 *   wp --allow-root --path=/var/www/html [--url=<site>] eval-file /tmp/104-snapshot.php > before.json
 *
 * Works on the old code too ("before" run): mode/source are null when the
 * runtime-mode API does not exist yet.
 *
 * Output keys:
 *   mode, source            runtime mode + its source (null before 1.1.6)
 *   gs_version              GEND_SOCIETY_VERSION (1.2.0+), else GS_VERSION (1.1.x)
 *   rest_routes             sorted route => sorted methods
 *   gs_files                ordered get_included_files() under /plugins/gend-society/,
 *                           relative to the plugin dir
 *   hooks                   hook => priority => sorted callback identities
 *                           (function name, Class::method, or "closure xN": closures
 *                           are only counted, their file:line legitimately moves)
 *   bp_group_extensions     sorted declared subclasses of BP_Group_Extension
 *   cron_events             sorted cron hook names starting gs_ or gend_
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs104s = array(
	'mode'       => function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : null,
	'source'     => function_exists( 'gend_society_runtime_mode_source' ) ? gend_society_runtime_mode_source() : null,
	'gs_version' => defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : ( defined( 'GS_VERSION' ) ? GS_VERSION : null ),
	'site'       => home_url( '/' ),
);

// ── REST routes ──
$gs104s_routes = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	$methods = array();
	foreach ( (array) $handlers as $h ) {
		if ( is_array( $h ) && isset( $h['methods'] ) ) {
			foreach ( (array) $h['methods'] as $k => $v ) {
				$methods[] = is_string( $k ) ? $k : (string) $v;
			}
		}
	}
	$methods = array_values( array_unique( $methods ) );
	sort( $methods );
	$gs104s_routes[ $route ] = $methods;
}
ksort( $gs104s_routes );
$gs104s['rest_routes'] = $gs104s_routes;

// ── gend-society files, in include order ──
$gs104s_dir   = wp_normalize_path( WP_PLUGIN_DIR . '/gend-society/' );
$gs104s_files = array();
foreach ( get_included_files() as $f ) {
	$f = wp_normalize_path( $f );
	if ( 0 === strpos( $f, $gs104s_dir ) ) {
		$gs104s_files[] = substr( $f, strlen( $gs104s_dir ) );
	}
}
$gs104s['gs_files'] = $gs104s_files;

// ── Hook callback identities ──
$gs104s_id = function ( $cb ) {
	if ( is_string( $cb ) ) {
		return $cb;
	}
	if ( $cb instanceof Closure ) {
		return 'closure';
	}
	if ( is_array( $cb ) && 2 === count( $cb ) ) {
		$obj = $cb[0];
		$cls = is_object( $obj ) ? get_class( $obj ) : (string) $obj;
		return $cls . '::' . (string) $cb[1];
	}
	if ( is_object( $cb ) ) {
		return get_class( $cb ) . '::__invoke';
	}
	return gettype( $cb );
};
$gs104s_hooks = array(
	'plugins_loaded', 'init', 'wp_loaded', 'rest_api_init', 'admin_menu', 'network_admin_menu',
	'admin_init', 'bp_include', 'bp_setup_nav', 'bp_init', 'wp_enqueue_scripts',
	'admin_enqueue_scripts', 'template_redirect', 'wp_footer', 'admin_footer', 'cron_schedules',
	'gend_gs_collab_contracted', 'gend_gs_collab_outcome_recorded', 'gend_gs_collab_swiped',
);
$gs104s_out = array();
global $wp_filter;
foreach ( $gs104s_hooks as $hook ) {
	$by_prio = array();
	if ( isset( $wp_filter[ $hook ] ) && $wp_filter[ $hook ] instanceof WP_Hook ) {
		foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
			$ids      = array();
			$closures = 0;
			foreach ( $cbs as $entry ) {
				$id = $gs104s_id( $entry['function'] );
				if ( 'closure' === $id ) {
					$closures++;
					continue;
				}
				$ids[] = $id . '/' . (int) $entry['accepted_args'];
			}
			sort( $ids );
			if ( $closures ) {
				$ids[] = 'closure x' . $closures;
			}
			$by_prio[ (string) $prio ] = $ids;
		}
		ksort( $by_prio, SORT_NUMERIC );
	}
	$gs104s_out[ $hook ] = $by_prio;
}
$gs104s['hooks'] = $gs104s_out;

// ── BP group extensions ──
$gs104s_ext = array();
if ( class_exists( 'BP_Group_Extension', false ) ) {
	foreach ( get_declared_classes() as $c ) {
		if ( is_subclass_of( $c, 'BP_Group_Extension' ) ) {
			$gs104s_ext[] = $c;
		}
	}
}
sort( $gs104s_ext );
$gs104s['bp_group_extensions'] = $gs104s_ext;

// ── Cron events (read-only) ──
$gs104s_cron = array();
$gs104s_arr  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
foreach ( (array) $gs104s_arr as $ts => $events ) {
	foreach ( (array) $events as $hook => $unused ) {
		if ( 0 === strpos( $hook, 'gs_' ) || 0 === strpos( $hook, 'gend_' ) ) {
			$gs104s_cron[ $hook ] = true;
		}
	}
}
$gs104s_cron = array_keys( $gs104s_cron );
sort( $gs104s_cron );
$gs104s['cron_events'] = $gs104s_cron;

echo wp_json_encode( $gs104s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
