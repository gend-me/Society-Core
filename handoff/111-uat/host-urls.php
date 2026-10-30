<?php
/**
 * Phase 111-06 battery: the Host-keyed home / siteurl of a customer container (P7, MIG-10).
 *
 *   wp eval-file host-urls.php <section>[,<section>...]     (C:/tmp/livepull/phase111/tools/sc-uat-111.sh bed host-urls <sections>)
 *
 * Sections: host-keyed-home unknown-host-untouched stored-option-unchanged wporg-excluded no-frontend-network
 *
 * The bed runs standalone; the filter is forced on with gend_society_host_urls_enabled (test hook), and every section sets
 * HTTP_HOST by hand. Every outbound request is caught by a pre_http_request spy (nothing leaves the bed). Each section puts
 * back what it changed. Last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * 111-13 asserts the HTTP behaviour of a real container (200 on the custom host, no Location to the temporary host).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs111hu'] = array( 'pass' => 0, 'fail' => 0, 'cleanup' => array(), 'http' => array() );
function gs111hu_assert( $c, $id, $t = '', $d = '' ) {
	if ( $c ) {
		$GLOBALS['gs111hu']['pass']++;
		echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
	} else {
		$GLOBALS['gs111hu']['fail']++;
		echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . ( '' !== $d ? ' -- ' . $d : '' ) . "\n";
	}
	return (bool) $c;
}
function gs111hu_on_cleanup( callable $fn ) {
	$GLOBALS['gs111hu']['cleanup'][] = $fn;
}
function gs111hu_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs111hu']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo 'INFO: cleanup failed: ' . $e->getMessage() . "\n";
		}
	}
	$GLOBALS['gs111hu']['cleanup'] = array();
}

/** Force the filter on, seed the verified record, set the request host. Restores everything on cleanup. */
function gs111hu_setup( array $domains, $host, $stored_home = null ) {
	$on = function () {
		return true;
	};
	add_filter( 'gend_society_host_urls_enabled', $on );
	$old_seed = get_option( GEND_SOCIETY_VERIFIED_DOMAINS_OPTION, null );
	$old_host = $_SERVER['HTTP_HOST'] ?? null;
	update_option( GEND_SOCIETY_VERIFIED_DOMAINS_OPTION, array( 'domains' => $domains, 'live' => false, 'canonical_host' => 'tmp-host.gend.test', 'synced_at' => time() ), true );
	$_SERVER['HTTP_HOST'] = $host;
	$old_home = null;
	if ( null !== $stored_home ) {
		global $wpdb;
		$old_home = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='home'" );
		$wpdb->update( $wpdb->options, array( 'option_value' => $stored_home ), array( 'option_name' => 'home' ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'home', 'options' );
	}
	gs111hu_on_cleanup(
		function () use ( $on, $old_seed, $old_host, $old_home ) {
			remove_filter( 'gend_society_host_urls_enabled', $on );
			if ( null === $old_seed ) {
				delete_option( GEND_SOCIETY_VERIFIED_DOMAINS_OPTION );
			} else {
				update_option( GEND_SOCIETY_VERIFIED_DOMAINS_OPTION, $old_seed, true );
			}
			if ( null === $old_host ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $old_host;
			}
			if ( null !== $old_home ) {
				global $wpdb;
				$wpdb->update( $wpdb->options, array( 'option_value' => $old_home ), array( 'option_name' => 'home' ) );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'home', 'options' );
			}
		}
	);
}
function gs111hu_raw( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
}
/** The value the same call returns with this module's filters removed. */
function gs111hu_without_filter( $name ) {
	remove_filter( 'pre_option_' . $name, 'gend_society_host_urls_filter', 20 );
	wp_cache_delete( 'alloptions', 'options' );
	$v = get_option( $name );
	add_filter( 'pre_option_' . $name, 'gend_society_host_urls_filter', 20, 3 );
	return $v;
}

$S = array();

$S['host-keyed-home'] = function () {
	gs111hu_setup( array( 'www.shop-gs111.example.test', 'shop-gs111.example.test' ), 'shop-gs111.example.test', 'https://tmp-host.gend.test' );
	gs111hu_assert( 'https://shop-gs111.example.test' === get_option( 'home' ), 'hk-home', 'home follows a verified request host', (string) get_option( 'home' ) );
	gs111hu_assert( 'https://shop-gs111.example.test' === get_option( 'siteurl' ), 'hk-siteurl', 'siteurl follows it too', (string) get_option( 'siteurl' ) );
	gs111hu_assert( 'https://shop-gs111.example.test/about/' === home_url( '/about/' ), 'hk-home-url', 'home_url() builds links on the custom host', home_url( '/about/' ) );
	$_SERVER['HTTP_HOST'] = 'WWW.Shop-GS111.Example.Test:443';
	gs111hu_assert( 'https://www.shop-gs111.example.test' === get_option( 'home' ), 'hk-case-port', 'host is lower-cased and the port dropped', (string) get_option( 'home' ) );
	$_SERVER['HTTP_HOST'] = 'tmp-host.gend.test';
	gs111hu_assert( 'https://tmp-host.gend.test' === get_option( 'home' ), 'hk-temp-host', 'the temporary host keeps answering with its stored address', (string) get_option( 'home' ) );
	// The stored path is kept.
	global $wpdb;
	$wpdb->update( $wpdb->options, array( 'option_value' => 'https://tmp-host.gend.test/sub' ), array( 'option_name' => 'home' ) );
	wp_cache_delete( 'alloptions', 'options' );
	$_SERVER['HTTP_HOST'] = 'shop-gs111.example.test';
	gs111hu_assert( 'https://shop-gs111.example.test/sub' === get_option( 'home' ), 'hk-path', 'the stored path is kept on the custom host', (string) get_option( 'home' ) );
	// Not in container mode: nothing changes.
	remove_all_filters( 'gend_society_host_urls_enabled' );
	add_filter( 'gend_society_host_urls_enabled', '__return_false' );
	gs111hu_assert( 'https://tmp-host.gend.test/sub' === get_option( 'home' ), 'hk-mode-off', 'outside container mode a verified host changes nothing', (string) get_option( 'home' ) );
	gs111hu_on_cleanup( function () {
		remove_filter( 'gend_society_host_urls_enabled', '__return_false' );
	} );
};

$S['unknown-host-untouched'] = function () {
	gs111hu_setup( array( 'shop-gs111.example.test' ), 'x.example.test', 'https://tmp-host.gend.test' );
	$base_home = gs111hu_without_filter( 'home' );
	$base_site = gs111hu_without_filter( 'siteurl' );
	$hosts     = array( 'evil.example.test', 'shop-gs111.example.test.evil.test', 'xshop-gs111.example.test', 'localhost', '', 'a b.test', '<script>.test', str_repeat( 'a', 70 ) . '.test', str_repeat( 'a.', 130 ) . 'test', 'shop-gs111.example.test@evil.test' );
	$bad       = 0;
	foreach ( $hosts as $h ) {
		$_SERVER['HTTP_HOST'] = $h;
		if ( get_option( 'home' ) !== $base_home || get_option( 'siteurl' ) !== $base_site ) {
			$bad++;
			echo 'INFO: changed for host [' . substr( $h, 0, 40 ) . "]\n";
		}
	}
	gs111hu_assert( 0 === $bad, 'uh-unknown', count( $hosts ) . ' unknown / malformed hosts leave home and siteurl untouched', (string) $bad );
	unset( $_SERVER['HTTP_HOST'] );
	gs111hu_assert( get_option( 'home' ) === $base_home, 'uh-no-host', 'no HTTP_HOST (cron / CLI) leaves home untouched' );
	// Other options are never touched.
	$_SERVER['HTTP_HOST'] = 'shop-gs111.example.test';
	gs111hu_assert( get_option( 'blogname' ) === gs111hu_raw( 'blogname' ) && 'https://shop-gs111.example.test' === get_option( 'home' ), 'uh-other-options', 'only home / siteurl are keyed' );
	// A garbage record in the durable option is ignored, not trusted.
	update_option( GEND_SOCIETY_VERIFIED_DOMAINS_OPTION, array( 'domains' => array( 'ok-gs111.example.test', '<b>', 5, array(), 'shop gs111.test' ) ), true );
	$st = gend_society_host_urls_state();
	gs111hu_assert( array( 'ok-gs111.example.test' ) === $st['domains'], 'uh-garbage', 'invalid entries in the stored record are dropped', wp_json_encode( $st['domains'] ) );
};

$S['stored-option-unchanged'] = function () {
	gs111hu_setup( array( 'shop-gs111.example.test' ), 'shop-gs111.example.test', 'https://tmp-host.gend.test' );
	$before = array( gs111hu_raw( 'home' ), gs111hu_raw( 'siteurl' ) );
	for ( $i = 0; $i < 50; $i++ ) {
		$_SERVER['HTTP_HOST'] = ( 0 === $i % 2 ) ? 'shop-gs111.example.test' : 'r' . $i . '.example.test';
		get_option( 'home' );
		get_option( 'siteurl' );
		home_url( '/' );
		site_url( '/' );
	}
	$_SERVER['HTTP_HOST'] = 'shop-gs111.example.test';
	gend_society_host_urls_schedule();
	wp_cache_delete( 'alloptions', 'options' );
	$after = array( gs111hu_raw( 'home' ), gs111hu_raw( 'siteurl' ) );
	gs111hu_assert( $before === $after && 'https://tmp-host.gend.test' === $after[0], 'so-db', 'the stored home / siteurl rows are byte-identical after 50 keyed requests', wp_json_encode( array( $before, $after ) ) );
	$src = (string) file_get_contents( GEND_SOCIETY_DIR . 'inc/container-host-urls.php' );
	gs111hu_assert( ! preg_match( '/(update_option|add_option|delete_option)\s*\(\s*[\'"](home|siteurl)[\'"]/', $src ), 'so-no-writes', 'the module has no write to home / siteurl' );
};

$S['wporg-excluded'] = function () {
	$ex = (string) file_get_contents( GEND_SOCIETY_DIR . 'bin/dist/wporg.exclude' );
	$ok = (bool) preg_grep( '#^inc/container-host-urls\.php$#', array_map( 'trim', explode( "\n", $ex ) ) );
	gs111hu_assert( $ok, 'wx-exclude', 'bin/dist/wporg.exclude lists inc/container-host-urls.php' );
	$m     = require GEND_SOCIETY_DIR . 'inc/bootstrap/manifest.php';
	$entry = null;
	foreach ( $m['modules'] as $e ) {
		if ( 'inc/container-host-urls.php' === $e['file'] ) {
			$entry = $e;
		}
	}
	gs111hu_assert( $entry && 'container' === $entry['tier'], 'wx-tier', 'the manifest lists it in the container tier (the wordpress.org build ships core,customer only)', wp_json_encode( $entry ) );
	gs111hu_assert( file_exists( GEND_SOCIETY_DIR . 'inc/container-host-urls.php' ), 'wx-file', 'the file exists' );
};

$S['no-frontend-network'] = function () {
	gs111hu_setup( array( 'shop-gs111.example.test' ), 'x.example.test', 'https://tmp-host.gend.test' );
	$spy = function ( $pre, $args, $url ) {
		$GLOBALS['gs111hu']['http'][] = $url;
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'domains' => array( 'synced-gs111.example.test' ), 'live' => true, 'canonical_host' => 'tmp-host.gend.test' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $spy, 1, 3 );
	gs111hu_on_cleanup( function () use ( $spy ) {
		remove_filter( 'pre_http_request', $spy, 1 );
	} );
	// Positive control: the spy sees a request.
	wp_remote_get( 'https://control-gs111.example.test/' );
	gs111hu_assert( 1 === count( $GLOBALS['gs111hu']['http'] ), 'nf-control', 'positive control: the spy records a request' );
	$GLOBALS['gs111hu']['http'] = array();
	// 500 front-end requests with random Host values, results compared with the filter removed.
	$diff  = 0;
	$base  = gs111hu_without_filter( 'home' );
	$basesu = gs111hu_without_filter( 'siteurl' );
	mt_srand( 111 );
	for ( $i = 0; $i < 500; $i++ ) {
		$_SERVER['HTTP_HOST'] = 'h' . mt_rand() . '.rnd' . mt_rand( 1, 9 ) . '.example.test';
		$a = get_option( 'home' );
		$b = get_option( 'siteurl' );
		gend_society_host_urls_schedule();
		gend_society_host_urls_admin_sync(); // not an admin request: must do nothing
		if ( $a !== $base || $b !== $basesu ) {
			$diff++;
		}
	}
	gs111hu_assert( 0 === count( $GLOBALS['gs111hu']['http'] ), 'nf-zero-http', '500 front-end requests with random Host values made ZERO outbound HTTP calls', (string) count( $GLOBALS['gs111hu']['http'] ) );
	gs111hu_assert( 0 === $diff, 'nf-identical', 'their responses are identical to the same requests without the filter', (string) $diff );
	// A verified host is served from the stored record, still without any network call.
	$_SERVER['HTTP_HOST'] = 'shop-gs111.example.test';
	gs111hu_assert( 'https://shop-gs111.example.test' === get_option( 'home' ) && 0 === count( $GLOBALS['gs111hu']['http'] ), 'nf-verified-offline', 'a seeded verified host gets the host-keyed URLs with no network call' );
	// The sync: explicit call (cron / admin) fetches from the STORED hub address with the stored token, never the request.
	add_filter( 'gend_society_remote_allowed', '__return_true' );
	$o = array( 'gend_society_install_id' => get_option( 'gend_society_install_id', null ), 'gend_society_install_token' => get_option( 'gend_society_install_token', null ), 'gend_society_gend_base_url' => get_option( 'gend_society_gend_base_url', null ) );
	update_option( 'gend_society_install_id', 'gs111-install-id' );
	update_option( 'gend_society_install_token', 'gs111-token' );
	update_option( 'gend_society_gend_base_url', 'https://hub-gs111.example.test' );
	gs111hu_on_cleanup( function () use ( $o ) {
		remove_filter( 'gend_society_remote_allowed', '__return_true' );
		foreach ( $o as $k => $v ) {
			if ( null === $v ) {
				delete_option( $k );
			} else {
				update_option( $k, $v );
			}
		}
	} );
	$_SERVER['HTTP_HOST'] = 'attacker-gs111.example.test';
	$GLOBALS['gs111hu']['http'] = array();
	$ok = gend_society_host_urls_sync();
	gs111hu_assert( $ok && 1 === count( $GLOBALS['gs111hu']['http'] ) && 0 === strpos( $GLOBALS['gs111hu']['http'][0], 'https://hub-gs111.example.test/wp-json/gdc-app-manager/v1/install/gs111-install-id/domains/verified' ), 'nf-sync-url', 'the sync calls the stored hub address, whatever the request Host is', wp_json_encode( $GLOBALS['gs111hu']['http'] ) );
	$st = gend_society_host_urls_state();
	gs111hu_assert( array( 'synced-gs111.example.test' ) === $st['domains'] && $st['live'] && $st['synced_at'] > 0, 'nf-sync-store', 'the answer is stored in the durable option', wp_json_encode( $st ) );
	gs111hu_assert( false !== has_action( GEND_SOCIETY_VERIFIED_DOMAINS_HOOK, 'gend_society_host_urls_sync' ), 'nf-cron-hook', 'the cron hook runs the sync' );
	$sch = wp_get_schedules();
	gs111hu_assert( 300 === (int) ( $sch['gend_society_five_minutes']['interval'] ?? 0 ), 'nf-cron-interval', 'a 5-minute schedule exists' );
	// A failed fetch keeps the stored record (no lock, no wipe).
	remove_filter( 'pre_http_request', $spy, 1 );
	$fail = function () {
		return new WP_Error( 'down', 'down' );
	};
	add_filter( 'pre_http_request', $fail, 1 );
	$res = gend_society_host_urls_sync();
	remove_filter( 'pre_http_request', $fail, 1 );
	gs111hu_assert( false === $res && array( 'synced-gs111.example.test' ) === gend_society_host_urls_state()['domains'], 'nf-fail-keeps', 'a failed fetch keeps the stored record' );
};

$gs111hu_want = array();
foreach ( isset( $args ) ? (array) $args : array() as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs111hu_want[] = trim( $s );
		}
	}
}
// The bed runs standalone, where the loader skips container-tier modules: load the file by hand (a container does it through the manifest).
if ( ! function_exists( 'gend_society_host_urls_filter' ) && defined( 'GEND_SOCIETY_DIR' ) && file_exists( GEND_SOCIETY_DIR . 'inc/container-host-urls.php' ) ) {
	require_once GEND_SOCIETY_DIR . 'inc/container-host-urls.php';
}
$gs111hu_ok = function_exists( 'gend_society_host_urls_filter' );
echo 'INFO: host-urls on ' . home_url() . ' php=' . PHP_VERSION . ' sections=' . implode( ',', $gs111hu_want ) . "\n";
gs111hu_assert( $gs111hu_ok, 'module', 'inc/container-host-urls.php is loaded' );
foreach ( $gs111hu_ok ? $gs111hu_want : array() as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs111hu_assert( false, $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs111hu_assert( false, $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs111hu_cleanup();
	}
}
echo ( 0 === (int) $GLOBALS['gs111hu']['fail'] && (int) $GLOBALS['gs111hu']['pass'] > 0 ) ? 'ALL PASS (' . (int) $GLOBALS['gs111hu']['pass'] . ")\n" : 'FAILURES: ' . (int) $GLOBALS['gs111hu']['fail'] . "\n";
