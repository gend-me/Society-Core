<?php
/**
 * Phase 109 plugin battery helpers (loaded by handoff/109-uat-plan.php).
 *
 * Section files (handoff/109-uat/<section>.php) print PASS: <id> <text> / FAIL: <id> <text> / INFO: <text>;
 * the dispatcher prints the last line "ALL PASS (<n>)" or "FAILURES: <n>".
 * gs109_mode() is the mode the run was started with (hub | container | standalone).
 * Read-only mode (env GS109_READONLY=1): see gs109_readonly_install().
 * gs109_hub_mode() is GS109_HUB (fixture | integration). Phase 109 helpers (bottom of this file):
 *   gs109_fixture_pair( $scenario )      fake-pair this install and serve the hub from 109-fixtures/<scenario>/
 *   gs109_fixture_scenario( $scenario )  switch scenario mid-section
 *   gs109_fixture_json( $scenario, $f )  decoded fixture file (expected values for asserts)
 *   gs109_fixture_log_lines()            lines of wp-content/gs109-fixture.log written during this section
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['gs109_uat'] = array(
	'pass'     => 0,
	'fail'     => 0,
	'cleanup'  => array(),
	'writes'   => array(),
	'mode'     => '',
	'hub'      => 'fixture',
	'readonly' => false,
	'http'     => array(),
);

function gs109_pass( $id, $text = '' ) {
	$GLOBALS['gs109_uat']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $text ? ' ' . $text : '' ) . "\n";
	return true;
}

function gs109_fail( $id, $text = '' ) {
	$GLOBALS['gs109_uat']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $text ? ' ' . $text : '' ) . "\n";
	return false;
}

function gs109_info( $text ) {
	echo 'INFO: ' . $text . "\n";
}

function gs109_assert( $cond, $id, $text = '', $detail = '' ) {
	return $cond ? gs109_pass( $id, $text ) : gs109_fail( $id, $text . ( '' !== $detail ? ' -- ' . $detail : '' ) );
}

function gs109_assert_eq( $expected, $actual, $id, $text = '' ) {
	return gs109_assert( $expected === $actual, $id, $text, 'expected ' . wp_json_encode( $expected ) . ' got ' . wp_json_encode( $actual ) );
}

function gs109_mode() {
	return (string) $GLOBALS['gs109_uat']['mode'];
}

function gs109_is_readonly() {
	return ! empty( $GLOBALS['gs109_uat']['readonly'] );
}

function gs109_on_cleanup( callable $fn, $label = '' ) {
	$GLOBALS['gs109_uat']['cleanup'][] = array( $fn, $label );
}

function gs109_cleanup() {
	$list = array_reverse( $GLOBALS['gs109_uat']['cleanup'] );
	$GLOBALS['gs109_uat']['cleanup'] = array();
	foreach ( $list as $item ) {
		try {
			call_user_func( $item[0] );
		} catch ( \Throwable $e ) {
			gs109_info( 'cleanup ' . $item[1] . ' failed: ' . $e->getMessage() );
		}
	}
}

/**
 * Snapshot gend_society_* options (+ any extra names) and restore them on cleanup, so a section can
 * connect/disconnect freely and leave the install as it found it.
 */
function gs109_snapshot_options( array $extra = array() ) {
	global $wpdb;
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%'" );
	$names = array_unique( array_merge( (array) $names, $extra ) );
	$snap  = array();
	foreach ( $names as $n ) {
		$snap[ $n ] = get_option( $n, '__gs109_absent__' );
	}
	gs109_on_cleanup(
		function () use ( $snap ) {
			global $wpdb;
			$now = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%'" );
			foreach ( (array) $now as $n ) {
				if ( ! array_key_exists( $n, $snap ) ) {
					delete_option( $n );
				}
			}
			foreach ( $snap as $n => $v ) {
				if ( '__gs109_absent__' === $v ) {
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
function gs109_http_record() {
	$GLOBALS['gs109_uat']['http'] = array();
	$f = function ( $pre, $args, $url ) {
		$GLOBALS['gs109_uat']['http'][] = strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' ) . ' ' . $url;
		return $pre;
	};
	add_filter( 'pre_http_request', $f, -2000, 3 );
	gs109_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, -2000 );
		},
		'http recorder'
	);
}

function gs109_http_log() {
	return (array) $GLOBALS['gs109_uat']['http'];
}

/** READ-ONLY guard (same contract as the hub battery): block + record writes, define GS109_READONLY. */
function gs109_readonly_install() {
	$GLOBALS['gs109_uat']['readonly'] = true;
	if ( ! defined( 'GS109_READONLY' ) ) {
		define( 'GS109_READONLY', true );
	}
	$rec = function ( $what ) {
		$GLOBALS['gs109_uat']['writes'][] = substr( preg_replace( '/\s+/', ' ', (string) $what ), 0, 200 );
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

function gs109_readonly_verdict() {
	$w = array_values( array_unique( $GLOBALS['gs109_uat']['writes'] ) );
	if ( $w ) {
		foreach ( array_slice( $w, 5, 25 ) as $more ) {
			gs109_info( 'write attempted (more): ' . $more );
		}
		return gs109_fail( 'write', 'attempted ' . count( $w ) . ': ' . implode( ' | ', array_slice( $w, 0, 5 ) ) );
	}
	$GLOBALS['gs109_uat']['pass']++;
	echo "NO WRITES: PASS\n";
	return true;
}

/* ---------------------------------------------------------------------------------------------------
 * Phase 109 additions: hub mode, fixture pairing (bed mu-plugin gs109-hub-fixture.php).
 * ------------------------------------------------------------------------------------------------- */

/** GS109_HUB of this run: 'fixture' (default) or 'integration'. */
function gs109_hub_mode() {
	return (string) $GLOBALS['gs109_uat']['hub'];
}

/** Fixtures root on the bed (copied by standalone/uat.sh next to this dispatcher). */
function gs109_fixtures_dir() {
	return dirname( __DIR__ ) . '/109-fixtures';
}

/** Decoded fixture file (e.g. gs109_fixture_json( 'connected', 'features.json' )); null when missing. */
function gs109_fixture_json( $scenario, $file ) {
	if ( 'integration' === gs109_hub_mode() ) {
		return gs109_int_json( $scenario, $file );
	}
	$p = gs109_fixtures_dir() . '/' . basename( (string) $scenario ) . '/' . basename( (string) $file );
	return is_readable( $p ) ? json_decode( (string) file_get_contents( $p ), true ) : null;
}

/** Switch the fixture scenario served by the bed mu-plugin (restored by the gs109_fixture_pair cleanup). */
function gs109_fixture_scenario( $scenario ) {
	if ( 'integration' === gs109_hub_mode() ) {
		return gs109_int_scenario( $scenario );
	}
	if ( ! is_dir( gs109_fixtures_dir() . '/' . $scenario ) ) {
		return gs109_fail( 'fixture', "scenario {$scenario} not found under " . gs109_fixtures_dir() );
	}
	update_option( 'gs109_fixture_scenario', (string) $scenario, false );
	return true;
}

/**
 * Fixture mode: pair this install with a fake hub answered by gs109-hub-fixture.php. Snapshots every
 * gend_society_* option and the gs109_fixture_* options first (restored on section cleanup), then writes the
 * paired state through gend_society_connect_store() when available (raw options otherwise), consent included.
 * Returns the fixture identity [install_id, token, hub] or array() when refused (read-only / integration / no
 * mu-plugin).
 */
function gs109_fixture_pair( $scenario = 'connected' ) {
	if ( gs109_is_readonly() ) {
		gs109_fail( 'fixture', 'pair refused: GS109_READONLY' );
		return array();
	}
	if ( 'integration' === gs109_hub_mode() ) {
		return gs109_int_pair( $scenario );
	}
	if ( 'fixture' !== gs109_hub_mode() ) {
		gs109_fail( 'fixture', 'pair refused: GS109_HUB=' . gs109_hub_mode() );
		return array();
	}
	if ( ! function_exists( 'gs109_fixture_match' ) ) {
		gs109_fail( 'fixture', 'pair refused: mu-plugin gs109-hub-fixture.php not loaded on this install' );
		return array();
	}
	gs109_snapshot_options( array( 'gs109_fixture_scenario', 'gs109_fixture_dir', 'gs109_fixture_hub' ) );
	$hub   = 'http://localhost:8919';
	$token = 'gs109-fixture-token-' . wp_generate_password( 12, false, false );
	$conn  = gs109_fixture_json( 'connected', 'membership.json' );
	$group = is_array( $conn ) && isset( $conn['group'] ) ? (array) $conn['group'] : array();
	update_option( 'gs109_fixture_dir', gs109_fixtures_dir(), false );
	update_option( 'gs109_fixture_hub', $hub, false );
	if ( ! gs109_fixture_scenario( $scenario ) ) {
		return array();
	}
	if ( function_exists( 'gend_society_connect_store' ) ) {
		gend_society_connect_store(
			array(
				'install_token' => $token,
				'connected_at'  => time(),
				'group'         => array(
					'id'   => isset( $group['id'] ) ? (int) $group['id'] : 0,
					'name' => isset( $group['name'] ) ? (string) $group['name'] : 'GS109 Business Group',
					'slug' => isset( $group['slug'] ) ? (string) $group['slug'] : 'gs109-business-group',
					'url'  => $hub . '/groups/' . ( isset( $group['slug'] ) ? (string) $group['slug'] : 'gs109-business-group' ) . '/',
				),
				'approved_by'   => array( 'id' => 2, 'display_name' => 'GS109 Owner' ),
			),
			(int) get_current_user_id() ? (int) get_current_user_id() : 1,
			'redirect',
			$hub
		);
	} else {
		if ( '' === (string) get_option( 'gend_society_install_id', '' ) ) {
			update_option( 'gend_society_install_id', wp_generate_uuid4(), false );
		}
		update_option( 'gend_society_install_token', $token, false );
		update_option( 'gend_society_gend_base_url', $hub, false );
		update_option( 'gend_society_connected_at', time(), false );
	}
	if ( function_exists( 'gend_society_consent_given' ) && ! gend_society_consent_given() ) {
		update_option( 'gend_society_consent', array( 'at' => time(), 'by' => 1, 'source' => 'gs109-fixture' ), false );
	}
	return array(
		'install_id' => (string) get_option( 'gend_society_install_id', '' ),
		'token'      => $token,
		'hub'        => $hub,
	);
}

/** Lines appended to wp-content/gs109-fixture.log since the first call in this section. */
function gs109_fixture_log_lines() {
	if ( 'integration' === gs109_hub_mode() ) {
		return gs109_int_log_lines();
	}
	$p = WP_CONTENT_DIR . '/gs109-fixture.log';
	if ( ! isset( $GLOBALS['gs109_uat']['fixture_log_at'] ) ) {
		$GLOBALS['gs109_uat']['fixture_log_at'] = is_readable( $p ) ? filesize( $p ) : 0;
		return array();
	}
	clearstatcache( true, $p );
	if ( ! is_readable( $p ) ) {
		return array();
	}
	$all = (string) file_get_contents( $p, false, null, (int) $GLOBALS['gs109_uat']['fixture_log_at'] );
	return array_values( array_filter( explode( "\n", $all ) ) );
}

/* ---------------------------------------------------------------------------------------------------
 * Phase 109-10: integration mode (GS109_HUB=integration). The bed is really connected to the gs109hub clone
 * (108 redirect flow); tools/109-10-regress.sh saves that pairing in the bed option gs109_int_pairing and writes
 * /tmp/gs109-int.json {key, hub, install_id} for the clone-only control mu-plugin gs109-int-control.php.
 * ------------------------------------------------------------------------------------------------- */

/** {key, hub, install_id} of the integration run, or array() when missing. */
function gs109_int_cfg() {
	static $cfg = null;
	if ( null === $cfg ) {
		$raw = is_readable( '/tmp/gs109-int.json' ) ? json_decode( (string) file_get_contents( '/tmp/gs109-int.json' ), true ) : null;
		$cfg = is_array( $raw ) && ! empty( $raw['key'] ) && ! empty( $raw['install_id'] ) ? $raw : array();
	}
	return $cfg;
}

/** Raw curl (outside the WP HTTP API): [status, headers (lowercase), body]. */
function gs109_int_curl( $method, $url, array $headers = array(), $body = null ) {
	$ch  = curl_init( $url );
	$hdr = array();
	foreach ( $headers as $k => $v ) {
		$hdr[] = $k . ': ' . $v;
	}
	$resp_headers = array();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => $hdr,
			CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$resp_headers ) {
				$p = strpos( $line, ':' );
				if ( false !== $p ) {
					$resp_headers[ strtolower( trim( substr( $line, 0, $p ) ) ) ] = trim( substr( $line, $p + 1 ) );
				}
				return strlen( $line );
			},
		)
	);
	if ( null !== $body ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
	}
	$out  = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, $resp_headers, false === $out ? '' : (string) $out );
}

/** Call the clone-only control mu-plugin. Returns the decoded answer or array( 'error' => ... ). */
function gs109_int_control( $cmd, array $params = array() ) {
	$cfg = gs109_int_cfg();
	if ( ! $cfg ) {
		return array( 'error' => '/tmp/gs109-int.json missing (run tools/109-10-regress.sh integration)' );
	}
	$url = rtrim( $cfg['hub'], '/' ) . '/?' . http_build_query( array_merge( array( 'gs109_int' => $cmd, 'key' => $cfg['key'], 'iid' => $cfg['install_id'] ), $params ) );
	list( $code, , $body ) = gs109_int_curl( 'GET', $url );
	$j = json_decode( $body, true );
	if ( 200 !== $code || ! is_array( $j ) ) {
		return array( 'error' => 'control ' . $cmd . ' HTTP ' . $code . ' ' . substr( $body, 0, 200 ) );
	}
	return $j;
}

function gs109_int_current() {
	return isset( $GLOBALS['gs109_uat']['int_scenario'] ) ? (string) $GLOBALS['gs109_uat']['int_scenario'] : '';
}

/** Switch the REAL hub install to $scenario (no-op when it is already there). */
function gs109_int_scenario( $scenario ) {
	if ( gs109_int_current() === $scenario ) {
		return true;
	}
	$r = gs109_int_control( 'scenario', array( 's' => $scenario ) );
	if ( isset( $r['error'] ) ) {
		return gs109_fail( 'integration', 'hub scenario ' . $scenario . ': ' . $r['error'] );
	}
	$GLOBALS['gs109_uat']['int_scenario'] = $scenario;
	return true;
}

/** Re-apply the bed's real pairing + switch the hub to $scenario. Same return shape as fixture mode. */
function gs109_int_pair( $scenario = 'connected' ) {
	$cfg  = gs109_int_cfg();
	$pair = get_option( 'gs109_int_pairing', false );
	if ( ! $cfg || ! is_array( $pair ) || empty( $pair['gend_society_install_id'] ) ) {
		gs109_fail( 'integration', 'pair refused: no saved real pairing (gs109_int_pairing) or /tmp/gs109-int.json' );
		return array();
	}
	gs109_snapshot_options( array( 'gs109_fixture_scenario', 'gs109_fixture_dir', 'gs109_fixture_hub' ) );
	foreach ( $pair as $n => $v ) {
		update_option( $n, $v, false );
	}
	if ( ! isset( $GLOBALS['gs109_uat']['int_cleanup'] ) ) {
		$GLOBALS['gs109_uat']['int_cleanup'] = true;
		gs109_on_cleanup(
			function () {
				$GLOBALS['gs109_uat']['int_scenario'] = '';
				$r = gs109_int_control( 'scenario', array( 's' => 'connected' ) );
				unset( $GLOBALS['gs109_uat']['int_cleanup'] );
				if ( isset( $r['error'] ) ) {
					gs109_info( 'integration cleanup: hub back to connected failed: ' . $r['error'] );
				}
			},
			'integration: hub back to connected'
		);
	}
	$GLOBALS['gs109_uat']['int_scenario'] = '';
	if ( ! gs109_int_scenario( $scenario ) ) {
		return array();
	}
	$id = function_exists( 'gend_society_plan_identity' ) ? gend_society_plan_identity() : array();
	return array(
		'install_id' => (string) get_option( 'gend_society_install_id', '' ),
		'token'      => isset( $id['token'] ) ? (string) $id['token'] : (string) ( $cfg['token'] ?? '' ),
		'hub'        => rtrim( (string) $cfg['hub'], '/' ),
	);
}

/** Raw token of the real pairing (plugin accessor first, then the saved run config). */
function gs109_int_token() {
	$id = function_exists( 'gend_society_plan_identity' ) ? gend_society_plan_identity() : array();
	if ( ! empty( $id['token'] ) && (string) ( $id['install_id'] ?? '' ) === (string) ( gs109_int_cfg()['install_id'] ?? '' ) ) {
		return (string) $id['token'];
	}
	return (string) ( gs109_int_cfg()['token'] ?? '' );
}

/** The real hub's decoded answer for a fixture file name in $scenario (switches there and back when needed). */
function gs109_int_json( $scenario, $file ) {
	$cfg = gs109_int_cfg();
	if ( ! $cfg ) {
		return null;
	}
	$map = array(
		'features.json'          => array( 'GET', 'features' ),
		'membership.json'        => array( 'GET', 'membership' ),
		'catalog.json'           => array( 'GET', 'catalog' ),
		'hub-groups.json'        => array( 'GET', 'hub/groups' ),
		'hub-feature-suite.json' => array( 'GET', 'hub/feature-suite' ),
		'hub-projects.json'      => array( 'GET', 'hub/projects' ),
		'hub-projects-402.json'  => array( 'GET', 'hub/projects' ),
		'hub-409.json'           => array( 'GET', 'hub/store' ),
		'hub-404.json'           => array( 'GET', 'hub/no-such-area' ),
		'revoked-401.json'       => array( 'GET', 'features' ),
		'change-plan.json'       => array( 'POST', 'membership/change-plan' ),
		'change-plan-409.json'   => array( 'POST', 'membership/change-plan' ),
	);
	$file = basename( (string) $file );
	if ( ! isset( $map[ $file ] ) ) {
		gs109_info( "integration: no real route for {$file}" );
		return null;
	}
	$back = gs109_int_current();
	if ( $back !== $scenario && ! gs109_int_scenario( $scenario ) ) {
		return null;
	}
	list( $method, $route ) = $map[ $file ];
	$body = null;
	if ( 'POST' === $method ) {
		$plan = 20;
		if ( 'change-plan.json' === $file ) {
			list( , , $mb ) = gs109_int_curl( 'GET', rtrim( $cfg['hub'], '/' ) . '/wp-json/gdc-app-manager/v1/install/' . rawurlencode( $cfg['install_id'] ) . '/membership', array( 'Authorization' => 'Bearer ' . gs109_int_token() ) );
			$mj   = json_decode( $mb, true );
			$ids  = is_array( $mj ) ? array_map( 'intval', (array) ( $mj['checkout_allowed_plan_ids'] ?? array() ) ) : array();
			$plan = $ids ? $ids[0] : 0;
		}
		$body = wp_json_encode( array( 'plan_id' => $plan ) );
	}
	$h = array( 'Authorization' => 'Bearer ' . gs109_int_token(), 'Accept' => 'application/json' );
	if ( null !== $body ) {
		$h['Content-Type'] = 'application/json';
	}
	list( $code, , $raw ) = gs109_int_curl( $method, rtrim( $cfg['hub'], '/' ) . '/wp-json/gdc-app-manager/v1/install/' . rawurlencode( $cfg['install_id'] ) . '/' . $route, $h, $body );
	if ( '' !== $back && $back !== $scenario ) {
		gs109_int_scenario( $back );
	}
	$j = json_decode( $raw, true );
	return is_array( $j ) ? $j : null;
}

/** Hub answers since the last call, as fixture log lines ("<t> scenario=<s> GET features -> 304"). */
function gs109_int_log_lines() {
	$p = WP_CONTENT_DIR . '/gs109-http.log';
	clearstatcache( true, $p );
	$size = is_readable( $p ) ? (int) filesize( $p ) : 0;
	if ( ! isset( $GLOBALS['gs109_uat']['int_log_at'] ) ) {
		$GLOBALS['gs109_uat']['int_log_at'] = $size;
		return array();
	}
	$all = $size > (int) $GLOBALS['gs109_uat']['int_log_at'] ? (string) file_get_contents( $p, false, null, (int) $GLOBALS['gs109_uat']['int_log_at'] ) : '';
	$out = array();
	foreach ( array_filter( explode( "\n", $all ) ) as $l ) {
		$j = json_decode( $l, true );
		if ( ! is_array( $j ) || empty( $j['hub'] ) || ! preg_match( '#/wp-json/gdc-app-manager/v1/install/[^/]+/([^?]+)#', (string) ( $j['url'] ?? '' ), $m ) ) {
			continue;
		}
		$out[] = gmdate( 'c' ) . ' scenario=' . gs109_int_current() . ' ' . strtoupper( (string) ( $j['method'] ?? 'GET' ) ) . ' ' . trim( $m[1], '/' ) . ' -> ' . (int) ( $j['status'] ?? 0 );
	}
	return $out;
}
