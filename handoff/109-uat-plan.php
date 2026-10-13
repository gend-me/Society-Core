<?php
/**
 * Phase 109 plugin UAT dispatcher (dashboard plan + feature access, gend-society 1.5.0).
 *
 *   wp eval-file 109-uat-plan.php <mode> <section> [<section> ...]
 *   modes:    hub | container | standalone
 *   sections: nolock fetch refresh launcher viewmodel card compare page-assets embed upgrade-page output consent
 *   GS109_READONLY=1 wp eval-file 109-uat-plan.php hub <section>      (any run on a live install)
 *   GS109_HUB=fixture (default) | integration
 *     fixture:     the bed mu-plugin gs109-hub-fixture.php answers the hub routes from handoff/109-fixtures/<scenario>/
 *                  (sections call gs109_fixture_pair( '<scenario>' ); everything is restored on cleanup)
 *     integration: sections talk to the real gs109hub clone (http://localhost:8919); fixture mode is off
 *
 * Contract: handoff/109-fixtures/ (same shapes as Vendor-App-Manager handoff/109-contract/).
 * One file per section under handoff/109-uat/<section>.php, so plans never edit the same battery file.
 * A section prints PASS:/FAIL:/INFO: lines and may branch on gs109_mode(); this dispatcher prints the last
 * line "ALL PASS (<n>)" or "FAILURES: <n>". A missing section file prints "FAIL: <section> not implemented".
 * Under GS109_READONLY a section is only included when its first 2 KB contain "gs109-readonly: yes",
 * otherwise "FAIL: <section> has no readonly mode"; the run ends with "NO WRITES: PASS" or
 * "FAIL: write attempted ...".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs109_dir = __DIR__ . '/109-uat';
require_once $gs109_dir . '/_lib.php';

$gs109_argv     = isset( $args ) ? array_values( array_filter( array_map( 'strval', (array) $args ) ) ) : array();
$gs109_mode     = strtolower( (string) array_shift( $gs109_argv ) );
$gs109_sections = $gs109_argv;

if ( '1' === (string) getenv( 'GS109_READONLY' ) ) {
	gs109_readonly_install();
	gs109_info( 'GS109_READONLY=1: writes are blocked and recorded' );
}

if ( ! in_array( $gs109_mode, array( 'hub', 'container', 'standalone' ), true ) ) {
	gs109_fail( 'dispatcher', 'first argument must be the mode: hub | container | standalone (got "' . $gs109_mode . '")' );
	$gs109_sections = array();
}
$GLOBALS['gs109_uat']['mode'] = $gs109_mode;

$gs109_hubmode = strtolower( (string) getenv( 'GS109_HUB' ) );
if ( '' === $gs109_hubmode ) {
	$gs109_hubmode = 'fixture';
}
if ( ! in_array( $gs109_hubmode, array( 'fixture', 'integration' ), true ) ) {
	gs109_fail( 'dispatcher', 'GS109_HUB must be fixture | integration (got "' . $gs109_hubmode . '")' );
	$gs109_sections = array();
}
$GLOBALS['gs109_uat']['hub'] = $gs109_hubmode;
if ( 'integration' === $gs109_hubmode ) {
	// Fixture mode off in-process (no option write): the bed talks to the real hub clone.
	add_filter( 'pre_option_gs109_fixture_scenario', '__return_empty_string', PHP_INT_MAX );
}

$gs109_runtime = function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '(none)';
gs109_info( 'mode=' . $gs109_mode . ' hub=' . $gs109_hubmode . ' runtime=' . $gs109_runtime . ' version=' . ( defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '(none)' ) . ' site=' . home_url( '/' ) . ' sections=' . implode( ',', $gs109_sections ) );
if ( '' !== $gs109_mode && $gs109_runtime !== $gs109_mode ) {
	gs109_fail( 'mode', "runtime mode is {$gs109_runtime}, battery started as {$gs109_mode}" );
}
if ( ! $gs109_sections ) {
	gs109_fail( 'dispatcher', 'no section given' );
}

foreach ( $gs109_sections as $gs109_section ) {
	if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $gs109_section ) ) {
		gs109_fail( $gs109_section, 'bad section name' );
		continue;
	}
	$gs109_file = $gs109_dir . '/' . $gs109_section . '.php';
	if ( ! is_readable( $gs109_file ) ) {
		gs109_fail( $gs109_section, 'not implemented' );
		continue;
	}
	if ( gs109_is_readonly() && false === strpos( (string) file_get_contents( $gs109_file, false, null, 0, 2048 ), 'gs109-readonly: yes' ) ) {
		gs109_fail( $gs109_section, 'has no readonly mode' );
		continue;
	}
	echo "== section {$gs109_section} ({$gs109_mode})\n";
	try {
		( function () use ( $gs109_file ) {
			include $gs109_file;
		} )();
	} catch ( \Throwable $e ) {
		gs109_fail( $gs109_section, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs109_cleanup();
	}
}

if ( gs109_is_readonly() ) {
	gs109_readonly_verdict();
}

$gs109_f = (int) $GLOBALS['gs109_uat']['fail'];
$gs109_p = (int) $GLOBALS['gs109_uat']['pass'];
if ( 0 === $gs109_f && $gs109_p > 0 ) {
	echo "ALL PASS ({$gs109_p})\n";
} else {
	echo 'FAILURES: ' . max( 1, $gs109_f ) . "\n";
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		exit( 1 );
	}
}
