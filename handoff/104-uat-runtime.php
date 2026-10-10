<?php
/**
 * Phase 104 UAT (FND-02, FND-03): runtime mode + tiered module loading.
 *
 * Read-only. Run on a deployed install:
 *   GS104_EXPECT_MODE=hub wp --allow-root --path=/var/www/html eval-file /tmp/104-uat-runtime.php
 *   wp eval-file 104-uat-runtime.php standalone
 *
 * The expected mode comes from the GS104_EXPECT_MODE env var, else the first
 * positional arg. Prints PASS/FAIL per assert and the mode, source and
 * loaded/skipped counts; ends with ALL PASS or halts with exit 1.
 *
 * Asserts:
 *   - gend_society_runtime_mode() === expected; GEND_SOCIETY_RUNTIME_MODE agrees
 *   - hub: the mode came from the env var or the constant (a fallback source
 *     on the hub means the Deployment env is missing)
 *   - no loaded module has a tier outside gend_society_mode_tiers( mode )
 *   - standalone: no hub/container-tier file is included; no gend-pm-sync/v1
 *     and no gs/v1/web-shell routes
 *   - hub: every hub/container-tier module present on disk was loaded (allowed
 *     skips: 'missing', or 'needs' admin outside wp-admin); a gs/v1/web-shell
 *     route exists; gend_society_is_hub() is true
 *   - every mode: the 9 former private hub checks (via reflection) and
 *     gs_markets_is_main_node() agree with gend_society_is_hub(). The 2 inline
 *     sites are enforced statically by bin/check-manifest.php (9 + 1 + 2 = 12).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs104_expect = getenv( 'GS104_EXPECT_MODE' );
if ( ! is_string( $gs104_expect ) || '' === $gs104_expect ) {
	$gs104_expect = ( isset( $args[0] ) && '' !== $args[0] ) ? (string) $args[0] : '';
}
$gs104_expect = strtolower( trim( $gs104_expect ) );
$gs104_fail   = 0;

$gs104 = function ( $ok, $label, $detail = '' ) use ( &$gs104_fail ) {
	if ( $ok ) {
		echo "PASS {$label}\n";
	} else {
		$gs104_fail++;
		echo "FAIL {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
	}
};

$gs104_finish = function () use ( &$gs104_fail ) {
	if ( $gs104_fail ) {
		echo "{$gs104_fail} FAILED\n";
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::halt( 1 );
		}
		exit( 1 );
	}
	echo "ALL PASS\n";
};

$gs104( in_array( $gs104_expect, array( 'hub', 'container', 'standalone' ), true ), 'expected mode given (GS104_EXPECT_MODE or arg)', 'got "' . $gs104_expect . '"' );

$gs104_api = function_exists( 'gend_society_runtime_mode' ) && function_exists( 'gend_society_runtime_mode_source' )
	&& function_exists( 'gend_society_is_hub' ) && function_exists( 'gend_society_mode_tiers' )
	&& function_exists( 'gend_society_loaded_modules' );
$gs104( $gs104_api, 'runtime-mode API present (gend-society >= 1.1.6 loaded)' );
if ( ! $gs104_api ) {
	$gs104_finish();
	return;
}

// ── Mode + source ──
$gs104_mode   = gend_society_runtime_mode();
$gs104_source = gend_society_runtime_mode_source();
$gs104_state  = gend_society_loaded_modules();
echo "mode={$gs104_mode} source={$gs104_source} loaded=" . count( $gs104_state['loaded'] ) . ' skipped=' . count( $gs104_state['skipped'] ) . "\n";

$gs104( $gs104_mode === $gs104_expect, "runtime mode === {$gs104_expect}", "mode={$gs104_mode}" );
if ( 'hub' === $gs104_expect ) {
	$gs104(
		in_array( $gs104_source, array( 'env', 'constant' ), true ),
		'hub mode set explicitly (source env|constant)',
		"source={$gs104_source} (a fallback on the hub means GEND_SOCIETY_RUNTIME is missing from the Deployment env)"
	);
}
$gs104(
	defined( 'GEND_SOCIETY_RUNTIME_MODE' ) && GEND_SOCIETY_RUNTIME_MODE === $gs104_mode,
	'GEND_SOCIETY_RUNTIME_MODE defined and equal to the mode',
	defined( 'GEND_SOCIETY_RUNTIME_MODE' ) ? 'constant=' . GEND_SOCIETY_RUNTIME_MODE : 'undefined'
);

// ── Manifest tier map ──
$gs104_manifest = require GEND_SOCIETY_DIR . 'inc/bootstrap/manifest.php';
$gs104_tier     = array(); // file => tier (modules + group members)
$gs104_needs    = array(); // file => needs
foreach ( $gs104_manifest['modules'] as $e ) {
	$list = isset( $e['group'] ) ? $e['modules'] : array( $e );
	foreach ( $list as $m ) {
		$gs104_tier[ $m['file'] ]  = $m['tier'];
		$gs104_needs[ $m['file'] ] = isset( $m['needs'] ) ? (array) $m['needs'] : array();
	}
}
foreach ( $gs104_manifest['partials'] as $p ) {
	if ( ! isset( $gs104_tier[ $p['file'] ] ) ) {
		$gs104_tier[ $p['file'] ] = $p['tier'];
	}
}
$gs104_allowed = gend_society_mode_tiers( $gs104_mode );

// ── Loaded tiers ⊆ allowed tiers ──
$gs104_bad = array();
foreach ( $gs104_state['loaded'] as $f ) {
	$t = isset( $gs104_tier[ $f ] ) ? $gs104_tier[ $f ] : '(unlisted)';
	if ( ! in_array( $t, $gs104_allowed, true ) ) {
		$gs104_bad[] = "{$f} [{$t}]";
	}
}
$gs104( ! $gs104_bad, 'no loaded module outside the mode\'s tiers (' . implode( ',', $gs104_allowed ) . ')', implode( '; ', $gs104_bad ) );

// ── Routes ──
$gs104_routes   = array_keys( rest_get_server()->get_routes() );
$gs104_prefixed = function ( $prefix ) use ( $gs104_routes ) {
	return array_values( array_filter( $gs104_routes, function ( $r ) use ( $prefix ) {
		return 0 === strpos( $r, $prefix );
	} ) );
};

if ( 'standalone' === $gs104_mode ) {
	$gs104_inc_dir = wp_normalize_path( GEND_SOCIETY_DIR );
	$gs104_leak    = array();
	foreach ( get_included_files() as $inc ) {
		$inc = wp_normalize_path( $inc );
		if ( 0 !== strpos( $inc, $gs104_inc_dir ) ) {
			continue;
		}
		$rel = substr( $inc, strlen( $gs104_inc_dir ) );
		if ( isset( $gs104_tier[ $rel ] ) && in_array( $gs104_tier[ $rel ], array( 'hub', 'container' ), true ) ) {
			$gs104_leak[] = "{$rel} [{$gs104_tier[ $rel ]}]";
		}
	}
	$gs104( ! $gs104_leak, 'standalone: no hub/container-tier file included', implode( '; ', $gs104_leak ) );
	$r = $gs104_prefixed( '/gend-pm-sync/v1' );
	$gs104( ! $r, 'standalone: no /gend-pm-sync/v1 routes', implode( ', ', $r ) );
	$r = $gs104_prefixed( '/gs/v1/web-shell' );
	$gs104( ! $r, 'standalone: no /gs/v1/web-shell routes', implode( ', ', $r ) );
}

if ( 'hub' === $gs104_mode ) {
	$gs104_missing = array();
	foreach ( $gs104_tier as $f => $t ) {
		if ( ! in_array( $t, array( 'hub', 'container' ), true ) ) {
			continue;
		}
		$is_module = isset( $gs104_needs[ $f ] );
		if ( ! $is_module || ! file_exists( GEND_SOCIETY_DIR . $f ) ) {
			continue; // partials are not loader-owned; absent files are an allowed skip.
		}
		if ( in_array( $f, $gs104_state['loaded'], true ) ) {
			continue;
		}
		$why = isset( $gs104_state['skipped'][ $f ] ) ? $gs104_state['skipped'][ $f ] : 'not loaded';
		if ( 'needs' === $why && in_array( 'admin', $gs104_needs[ $f ], true ) && ! is_admin() ) {
			continue;
		}
		$gs104_missing[] = "{$f} [{$t}] ({$why})";
	}
	$gs104( ! $gs104_missing, 'hub: every hub/container-tier module on disk was loaded', implode( '; ', $gs104_missing ) );
	$r = $gs104_prefixed( '/gs/v1/web-shell' );
	$gs104( count( $r ) > 0, 'hub: /gs/v1/web-shell routes registered (' . count( $r ) . ')' );
	$gs104( true === gend_society_is_hub(), 'hub: gend_society_is_hub() is true' );
}

// ── The replaced hub checks agree with gend_society_is_hub() ──
$gs104_hub     = gend_society_is_hub();
$gs104_methods = array(
	array( 'Gend_GS_Collab_Contract', 'is_hub' ),
	array( 'Gend_GS_Collab_Deck', 'is_hub' ),
	array( 'Gend_GS_Collab_Market_Mirror', 'is_hub' ),
	array( 'Gend_GS_Collab_Market', 'is_hub' ),
	array( 'Gend_GS_Collab_Resolver', 'is_hub' ),
	array( 'Gend_GS_Collab_REST', 'is_hub' ),
	array( 'Gend_GS_Collab_Sync', 'is_hub' ),
	array( 'Gend_GS_Collab_Market_REST', 'is_main_node' ),
	array( 'Gend_GS_Collab_Portfolio_REST', 'is_main_node' ),
);
$gs104_checked = 0;
foreach ( $gs104_methods as $cm ) {
	list( $class, $method ) = $cm;
	if ( ! class_exists( $class, false ) ) {
		echo "SKIP {$class}::{$method}() -- class not loaded in this mode\n";
		continue;
	}
	try {
		$rm = new ReflectionMethod( $class, $method );
		$rm->setAccessible( true );
		$val = (bool) $rm->invoke( null );
		$gs104( $val === $gs104_hub, "{$class}::{$method}() === gend_society_is_hub()", 'method=' . var_export( $val, true ) . ' helper=' . var_export( $gs104_hub, true ) );
		$gs104_checked++;
	} catch ( ReflectionException $e ) {
		$gs104( false, "{$class}::{$method}() reflectable", $e->getMessage() );
	}
}
if ( function_exists( 'gend_society_markets_is_main_node' ) ) {
	$val = (bool) gend_society_markets_is_main_node();
	$gs104( $val === $gs104_hub, 'gs_markets_is_main_node() === gend_society_is_hub()', 'fn=' . var_export( $val, true ) );
	$gs104_checked++;
} else {
	echo "SKIP gs_markets_is_main_node() -- not defined in this mode\n";
}
if ( 'standalone' !== $gs104_mode ) {
	$gs104( 10 === $gs104_checked, 'all 10 runtime-reachable hub checks were exercised', "checked={$gs104_checked}" );
}

$gs104_finish();
