<?php
/**
 * Phase 108 plugin battery helpers (loaded by handoff/108-uat-connect.php).
 *
 * Section files (handoff/108-uat/<section>.php) print PASS: <id> <text> / FAIL: <id> <text> / INFO: <text>;
 * the dispatcher prints the last line "ALL PASS (<n>)" or "FAILURES: <n>".
 * gs108_mode() is the mode the run was started with (hub | container | standalone).
 * Read-only mode (env GS108_READONLY=1): see gs108_readonly_install().
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['gs108_uat'] = array(
	'pass'     => 0,
	'fail'     => 0,
	'cleanup'  => array(),
	'writes'   => array(),
	'mode'     => '',
	'readonly' => false,
	'http'     => array(),
);

function gs108_pass( $id, $text = '' ) {
	$GLOBALS['gs108_uat']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $text ? ' ' . $text : '' ) . "\n";
	return true;
}

function gs108_fail( $id, $text = '' ) {
	$GLOBALS['gs108_uat']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $text ? ' ' . $text : '' ) . "\n";
	return false;
}

function gs108_info( $text ) {
	echo 'INFO: ' . $text . "\n";
}

function gs108_assert( $cond, $id, $text = '', $detail = '' ) {
	return $cond ? gs108_pass( $id, $text ) : gs108_fail( $id, $text . ( '' !== $detail ? ' -- ' . $detail : '' ) );
}

function gs108_assert_eq( $expected, $actual, $id, $text = '' ) {
	return gs108_assert( $expected === $actual, $id, $text, 'expected ' . wp_json_encode( $expected ) . ' got ' . wp_json_encode( $actual ) );
}

function gs108_mode() {
	return (string) $GLOBALS['gs108_uat']['mode'];
}

function gs108_is_readonly() {
	return ! empty( $GLOBALS['gs108_uat']['readonly'] );
}

function gs108_on_cleanup( callable $fn, $label = '' ) {
	$GLOBALS['gs108_uat']['cleanup'][] = array( $fn, $label );
}

function gs108_cleanup() {
	$list = array_reverse( $GLOBALS['gs108_uat']['cleanup'] );
	$GLOBALS['gs108_uat']['cleanup'] = array();
	foreach ( $list as $item ) {
		try {
			call_user_func( $item[0] );
		} catch ( \Throwable $e ) {
			gs108_info( 'cleanup ' . $item[1] . ' failed: ' . $e->getMessage() );
		}
	}
}

/**
 * Snapshot gend_society_* options (+ any extra names) and restore them on cleanup, so a section can
 * connect/disconnect freely and leave the install as it found it.
 */
function gs108_snapshot_options( array $extra = array() ) {
	global $wpdb;
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%'" );
	$names = array_unique( array_merge( (array) $names, $extra ) );
	$snap  = array();
	foreach ( $names as $n ) {
		$snap[ $n ] = get_option( $n, '__gs108_absent__' );
	}
	gs108_on_cleanup(
		function () use ( $snap ) {
			global $wpdb;
			$now = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%'" );
			foreach ( (array) $now as $n ) {
				if ( ! array_key_exists( $n, $snap ) ) {
					delete_option( $n );
				}
			}
			foreach ( $snap as $n => $v ) {
				if ( '__gs108_absent__' === $v ) {
					delete_option( $n );
				} else {
					update_option( $n, $v );
				}
			}
		},
		'options snapshot'
	);
	return $snap;
}

/** Record every outbound HTTP request (method + url, in order) for the rest of the section. */
function gs108_http_record() {
	$GLOBALS['gs108_uat']['http'] = array();
	$f = function ( $pre, $args, $url ) {
		$GLOBALS['gs108_uat']['http'][] = strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' ) . ' ' . $url;
		return $pre;
	};
	add_filter( 'pre_http_request', $f, -2000, 3 );
	gs108_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, -2000 );
		},
		'http recorder'
	);
}

function gs108_http_log() {
	return (array) $GLOBALS['gs108_uat']['http'];
}

/** READ-ONLY guard (same contract as the hub battery): block + record writes, define GS108_READONLY. */
function gs108_readonly_install() {
	$GLOBALS['gs108_uat']['readonly'] = true;
	if ( ! defined( 'GS108_READONLY' ) ) {
		define( 'GS108_READONLY', true );
	}
	$rec = function ( $what ) {
		$GLOBALS['gs108_uat']['writes'][] = substr( preg_replace( '/\s+/', ' ', (string) $what ), 0, 200 );
	};
	add_filter(
		'query',
		function ( $sql ) use ( $rec ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', (string) $sql ) ) {
				$rec( 'sql: ' . $sql );
				return '';
			}
			return $sql;
		},
		PHP_INT_MAX
	);
	add_filter(
		'pre_update_option',
		function ( $value, $option, $old ) use ( $rec ) {
			if ( $value !== $old ) {
				$rec( 'update_option ' . $option );
			}
			return $old;
		},
		PHP_INT_MAX,
		3
	);
	add_action( 'add_option', function ( $option ) use ( $rec ) { $rec( 'add_option ' . $option ); } );
	add_action( 'delete_option', function ( $option ) use ( $rec ) { $rec( 'delete_option ' . $option ); } );
	add_action( 'update_site_option', function ( $option ) use ( $rec ) { $rec( 'update_site_option ' . $option ); } );
	add_action( 'add_site_option', function ( $option ) use ( $rec ) { $rec( 'add_site_option ' . $option ); } );
	add_action( 'delete_site_option', function ( $option ) use ( $rec ) { $rec( 'delete_site_option ' . $option ); } );
	add_action( 'setted_transient', function ( $t ) use ( $rec ) { $rec( 'set_transient ' . $t ); } );
	add_action( 'setted_site_transient', function ( $t ) use ( $rec ) { $rec( 'set_site_transient ' . $t ); } );
}

function gs108_readonly_verdict() {
	$w = array_values( array_unique( $GLOBALS['gs108_uat']['writes'] ) );
	if ( $w ) {
		return gs108_fail( 'write', 'attempted ' . count( $w ) . ': ' . implode( ' | ', array_slice( $w, 0, 5 ) ) );
	}
	$GLOBALS['gs108_uat']['pass']++;
	echo "NO WRITES: PASS\n";
	return true;
}
