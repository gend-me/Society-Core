<?php
/**
 * Phase 108 plugin UAT dispatcher (connect to a business group, gend-society 1.4.0).
 *
 *   wp eval-file 108-uat-connect.php <mode> <section> [<section> ...]
 *   modes:    hub | container | standalone
 *   sections: consent start return challenge pairing disconnect owner health output glue
 *   GS108_READONLY=1 wp eval-file 108-uat-connect.php hub <section>      (any run on a live install)
 *
 * One file per section under handoff/108-uat/<section>.php, so plans never edit the same battery file.
 * A section prints PASS:/FAIL:/INFO: lines and may branch on gs108_mode(); this dispatcher prints the last
 * line "ALL PASS (<n>)" or "FAILURES: <n>". A missing section file prints "FAIL: <section> not implemented".
 * Under GS108_READONLY a section is only included when its first 2 KB contain "gs108-readonly: yes",
 * otherwise "FAIL: <section> has no readonly mode"; the run ends with "NO WRITES: PASS" or
 * "FAIL: write attempted ...".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs108_dir = __DIR__ . '/108-uat';
require_once $gs108_dir . '/_lib.php';

$gs108_argv     = isset( $args ) ? array_values( array_filter( array_map( 'strval', (array) $args ) ) ) : array();
$gs108_mode     = strtolower( (string) array_shift( $gs108_argv ) );
$gs108_sections = $gs108_argv;

if ( '1' === (string) getenv( 'GS108_READONLY' ) ) {
	gs108_readonly_install();
	gs108_info( 'GS108_READONLY=1: writes are blocked and recorded' );
}

if ( ! in_array( $gs108_mode, array( 'hub', 'container', 'standalone' ), true ) ) {
	gs108_fail( 'dispatcher', 'first argument must be the mode: hub | container | standalone (got "' . $gs108_mode . '")' );
	$gs108_sections = array();
}
$GLOBALS['gs108_uat']['mode'] = $gs108_mode;

$gs108_runtime = function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '(none)';
gs108_info( 'mode=' . $gs108_mode . ' runtime=' . $gs108_runtime . ' version=' . ( defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '(none)' ) . ' site=' . home_url( '/' ) . ' sections=' . implode( ',', $gs108_sections ) );
if ( '' !== $gs108_mode && $gs108_runtime !== $gs108_mode ) {
	gs108_fail( 'mode', "runtime mode is {$gs108_runtime}, battery started as {$gs108_mode}" );
}
if ( ! $gs108_sections ) {
	gs108_fail( 'dispatcher', 'no section given' );
}

foreach ( $gs108_sections as $gs108_section ) {
	if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $gs108_section ) ) {
		gs108_fail( $gs108_section, 'bad section name' );
		continue;
	}
	$gs108_file = $gs108_dir . '/' . $gs108_section . '.php';
	if ( ! is_readable( $gs108_file ) ) {
		gs108_fail( $gs108_section, 'not implemented' );
		continue;
	}
	if ( gs108_is_readonly() && false === strpos( (string) file_get_contents( $gs108_file, false, null, 0, 2048 ), 'gs108-readonly: yes' ) ) {
		gs108_fail( $gs108_section, 'has no readonly mode' );
		continue;
	}
	echo "== section {$gs108_section} ({$gs108_mode})\n";
	try {
		( function () use ( $gs108_file ) {
			include $gs108_file;
		} )();
	} catch ( \Throwable $e ) {
		gs108_fail( $gs108_section, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs108_cleanup();
	}
}

if ( gs108_is_readonly() ) {
	gs108_readonly_verdict();
}

$gs108_f = (int) $GLOBALS['gs108_uat']['fail'];
$gs108_p = (int) $GLOBALS['gs108_uat']['pass'];
if ( 0 === $gs108_f && $gs108_p > 0 ) {
	echo "ALL PASS ({$gs108_p})\n";
} else {
	echo 'FAILURES: ' . max( 1, $gs108_f ) . "\n";
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		exit( 1 );
	}
}
