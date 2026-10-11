<?php
/**
 * Phase 107 UAT (UPD-01, UPD-04, UPD-05): the self-updater (inc/self-update.php) is inert
 * on hub/container, silent before consent on standalone, and offers the signed feed
 * after consent.
 *
 * Run on a deployed install, naming the expected mode:
 *   wp eval-file 107-uat-updater.php hub
 *   wp eval-file 107-uat-updater.php container
 *   wp eval-file 107-uat-updater.php standalone-noconsent
 *   wp eval-file 107-uat-updater.php standalone
 *   GS107_UAT_LIVE=1 wp eval-file 107-uat-updater.php standalone     (real https://updates.gend.me)
 *
 * Network: a pre_http_request filter at priority -1000 counts every request to
 * updates.gend.me. It answers them itself, so the battery never reaches the network:
 * hub/container/standalone-noconsent get a WP_Error, standalone gets a mocked manifest
 * (version = GS107_UAT_MOCK_VERSION or GEND_SOCIETY_VERSION with patch + 1). Only
 * standalone with GS107_UAT_LIVE=1 lets the request through to the live feed.
 *
 * Writes: none on hub/container/standalone-noconsent (option writes are recorded and
 * asserted). standalone deletes gend_society_update_cache to test a cold cache and
 * restores its original value (or absence) at the end.
 *
 * Output: "PASS:" / "FAIL:" / "INFO:" lines, then "UAT 107 <mode>: ALL PASS" or
 * "UAT 107 <mode>: FAIL (n)" (exit 1 under WP-CLI).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs107_mode   = strtolower( trim( isset( $args[0] ) ? (string) $args[0] : (string) getenv( 'GS107_UAT_MODE' ) ) );
$gs107_modes  = array( 'hub', 'container', 'standalone-noconsent', 'standalone' );
$gs107_fail   = 0;
$gs107_prefix = 'https://updates.gend.me/gend-society/';
$gs107_file   = 'gend-society/gend-society.php';

$gs107 = function ( $ok, $label, $detail = '' ) use ( &$gs107_fail ) {
	if ( $ok ) {
		echo "PASS: {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
	} else {
		++$gs107_fail;
		echo "FAIL: {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
	}
};
$gs107_info = function ( $msg ) {
	echo "INFO: {$msg}\n";
};
$gs107_finish = function () use ( &$gs107_fail, &$gs107_mode ) {
	$m = '' !== $gs107_mode ? $gs107_mode : '?';
	if ( $gs107_fail ) {
		echo "UAT 107 {$m}: FAIL ({$gs107_fail})\n";
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::halt( 1 );
		}
		exit( 1 );
	}
	echo "UAT 107 {$m}: ALL PASS\n";
};

if ( ! in_array( $gs107_mode, $gs107_modes, true ) ) {
	$gs107( false, 'mode argument is one of ' . implode( '|', $gs107_modes ), "got '{$gs107_mode}'" );
	$gs107_finish();
	return;
}
$gs107_family = 'standalone-noconsent' === $gs107_mode ? 'standalone' : $gs107_mode;
$gs107_live   = 'standalone' === $gs107_mode && '1' === getenv( 'GS107_UAT_LIVE' );

// ── Network guard + counter ──
$gs107_requests = array();
$gs107_mock_ver = (string) getenv( 'GS107_UAT_MOCK_VERSION' );
if ( '' === $gs107_mock_ver && defined( 'GEND_SOCIETY_VERSION' ) && preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', GEND_SOCIETY_VERSION, $gs107_m ) ) {
	$gs107_mock_ver = $gs107_m[1] . '.' . $gs107_m[2] . '.' . ( (int) $gs107_m[3] + 1 );
}
$gs107_mock = array(
	'slug'         => 'gend-society',
	'name'         => 'GenD Society',
	'version'      => $gs107_mock_ver,
	'requires'     => '6.7',
	'tested'       => '7.1',
	'requires_php' => '8.1',
	'last_updated' => '2026-10-12 12:00:00',
	'homepage'     => 'https://gend.me',
	'package'      => $gs107_prefix . 'gend-society-' . $gs107_mock_ver . '.zip',
	'sha256'       => str_repeat( '0', 64 ),
	'signature'    => base64_encode( str_repeat( "\0", 64 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- mock signature, never verified.
	'key_id'       => 'gs-2026a',
	'sections'     => array(
		'description' => '<p>GS107 UAT mock description.</p>',
		'changelog'   => '<h4>' . $gs107_mock_ver . '</h4><ul><li>GS107 UAT mock changelog.</li></ul>',
	),
);
$gs107_guard = function ( $pre, $r, $url ) use ( &$gs107_requests, $gs107_mode, $gs107_live, $gs107_mock ) {
	if ( ! is_string( $url ) || false === strpos( $url, 'updates.gend.me' ) ) {
		return $pre;
	}
	$gs107_requests[] = $url;
	if ( $gs107_live ) {
		return $pre;
	}
	if ( 'standalone' === $gs107_mode ) {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $gs107_mock ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return new WP_Error( 'gs107_uat_blocked', 'GS107 UAT: updates.gend.me request blocked' );
};
add_filter( 'pre_http_request', $gs107_guard, -1000, 3 );

// ── Option write recorder ──
$gs107_writes = array();
$gs107_record = function ( $option ) use ( &$gs107_writes ) {
	$gs107_writes[] = (string) $option;
};
foreach ( array( 'added_option', 'updated_option', 'deleted_option', 'add_site_option', 'update_site_option', 'delete_site_option', 'setted_site_transient', 'setted_transient' ) as $gs107_hook ) {
	add_action( $gs107_hook, $gs107_record, 10, 1 );
}
$gs107_cache_before = get_site_option( 'gend_society_update_cache', null );

echo 'INFO: mode=' . $gs107_mode . ( $gs107_live ? ' (live feed)' : '' ) . ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '(none)' ) . ' version=' . ( defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '(none)' ) . "\n";

// ── Every mode ──
if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$gs107_main = WP_PLUGIN_DIR . '/gend-society/gend-society.php';
$gs107_data = file_exists( $gs107_main ) ? get_plugin_data( $gs107_main, false, false ) : array();
$gs107(
	isset( $gs107_data['UpdateURI'] ) && $gs107_prefix === $gs107_data['UpdateURI'],
	'plugin header Update URI === ' . $gs107_prefix,
	'UpdateURI=' . ( isset( $gs107_data['UpdateURI'] ) ? var_export( $gs107_data['UpdateURI'], true ) : '(none)' ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- UAT output.
);
$gs107(
	defined( 'GEND_SOCIETY_VERSION' ) && version_compare( GEND_SOCIETY_VERSION, '1.3.0', '>=' ),
	'GEND_SOCIETY_VERSION >= 1.3.0',
	defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '(undefined)'
);
$gs107(
	! class_exists( 'Gend_Society_GitHub_Updater', false ) && ! class_exists( 'GenD_GitHub_Updater', false ),
	'legacy GitHub updater class not defined'
);
$gs107_dir = defined( 'GEND_SOCIETY_DIR' ) ? GEND_SOCIETY_DIR : WP_PLUGIN_DIR . '/gend-society/';
$gs107( ! file_exists( $gs107_dir . 'inc/class-gend-github-updater.php' ), 'inc/class-gend-github-updater.php absent' );
$gs107_runtime = function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '';
$gs107( $gs107_runtime === $gs107_family, "gend_society_runtime_mode() === {$gs107_family}", "runtime={$gs107_runtime}" );

$gs107_offer = function () use ( $gs107_data, $gs107_file ) {
	return apply_filters( 'update_plugins_updates.gend.me', false, $gs107_data, $gs107_file, array() );
};
$gs107_ut = get_site_transient( 'update_plugins' );

// ── hub / container: the updater is not loaded at all ──
if ( 'hub' === $gs107_mode || 'container' === $gs107_mode ) {
	$gs107( ! function_exists( 'gend_society_update_manifest' ), 'gend_society_update_manifest() not defined (inc/self-update.php not loaded)' );
	$gs107_hooked = has_filter( 'update_plugins_updates.gend.me' );
	$gs107( false === $gs107_hooked, "has_filter('update_plugins_updates.gend.me') === false" );
	if ( false === $gs107_hooked ) {
		$gs107_r = $gs107_offer();
		$gs107( false === $gs107_r, "apply_filters('update_plugins_updates.gend.me', false, ...) === false", 'got ' . gettype( $gs107_r ) );
	} else {
		// Calling it would run the updater (and write its cache) on a read-only install.
		$gs107( false, "apply_filters('update_plugins_updates.gend.me', ...) not called: a callback is registered" );
	}
	$gs107(
		! function_exists( 'gend_society_update_plugins_api' ) && false === has_filter( 'plugins_api', 'gend_society_update_plugins_api' ),
		'plugins_api has no gend-society short-circuit of ours'
	);
	$gs107( false === has_filter( 'upgrader_pre_download', 'gend_society_update_pre_download' ), 'upgrader_pre_download has no gend-society verifier hooked' );
	$gs107( null === get_site_option( 'gend_society_update_cache', null ), 'site option gend_society_update_cache absent' );
	if ( is_object( $gs107_ut ) ) {
		$gs107( empty( $gs107_ut->response[ $gs107_file ] ), 'update_plugins transient offers no gend-society update', isset( $gs107_ut->response[ $gs107_file ]->new_version ) ? 'offered ' . $gs107_ut->response[ $gs107_file ]->new_version : '' );
	} else {
		$gs107_info( 'update_plugins site transient absent (no update check cached yet)' );
	}
	$gs107( 0 === count( $gs107_requests ), '0 requests to updates.gend.me', implode( ', ', $gs107_requests ) );
}

// ── standalone without consent: loaded but silent ──
if ( 'standalone-noconsent' === $gs107_mode ) {
	$gs107( function_exists( 'gend_society_remote_allowed' ) && false === gend_society_remote_allowed( 'updates' ), "gend_society_remote_allowed('updates') === false" );
	$gs107( function_exists( 'gend_society_update_manifest' ), 'gend_society_update_manifest() defined (inc/self-update.php loaded on standalone)' );
	if ( function_exists( 'gend_society_update_manifest' ) ) {
		$gs107( null === gend_society_update_manifest(), 'gend_society_update_manifest() === null' );
		$gs107( null === gend_society_update_manifest( true ), 'gend_society_update_manifest(true) === null (fresh fetch still gated)' );
	}
	$gs107_r = $gs107_offer();
	$gs107( false === $gs107_r, 'update_plugins_updates.gend.me filter returns false', 'got ' . gettype( $gs107_r ) );
	$gs107( 0 === count( $gs107_requests ), '0 requests to updates.gend.me', implode( ', ', $gs107_requests ) );
	$gs107( get_site_option( 'gend_society_update_cache', null ) === $gs107_cache_before, 'gend_society_update_cache not written', null === $gs107_cache_before ? 'absent before and after' : 'unchanged' );
}

// ── standalone after consent: the feed is offered ──
if ( 'standalone' === $gs107_mode ) {
	$gs107( function_exists( 'gend_society_remote_allowed' ) && true === gend_society_remote_allowed( 'updates' ), "gend_society_remote_allowed('updates') === true (consent recorded)" );
	$gs107( false !== has_filter( 'update_plugins_updates.gend.me', 'gend_society_update_offer' ), 'update_plugins_updates.gend.me filter registered' );
	if ( function_exists( 'gend_society_update_manifest' ) ) {
		// Cold cache.
		delete_site_option( 'gend_society_update_cache' );
		if ( ! is_multisite() ) {
			delete_option( 'gend_society_update_cache' );
		}
		$gs107_requests = array();
		$gs107_r        = $gs107_offer();
		$gs107( 1 === count( $gs107_requests ), 'cold cache: exactly 1 request to updates.gend.me', count( $gs107_requests ) . ' requests' );
		$gs107_cache = get_site_option( 'gend_society_update_cache', null );
		$gs107( is_array( $gs107_cache ) && ! empty( $gs107_cache['checked_at'] ), 'gend_society_update_cache has checked_at' );
		$gs107_want = $gs107_live ? ( is_array( $gs107_cache ) && isset( $gs107_cache['manifest']['version'] ) ? $gs107_cache['manifest']['version'] : '(none)' ) : $gs107_mock_ver;
		$gs107(
			is_array( $gs107_r ) && isset( $gs107_r['version'], $gs107_r['package'] ) && $gs107_want === $gs107_r['version'] && 0 === strpos( $gs107_r['package'], $gs107_prefix ),
			'filter returns an offer for the ' . ( $gs107_live ? 'live' : 'mocked' ) . " manifest version {$gs107_want}",
			is_array( $gs107_r ) ? 'version=' . ( $gs107_r['version'] ?? '?' ) . ' package=' . ( $gs107_r['package'] ?? '?' ) : 'got ' . gettype( $gs107_r )
		);
		if ( $gs107_live && is_array( $gs107_r ) ) {
			$gs107_info( 'live feed version ' . $gs107_r['version'] . ( version_compare( $gs107_r['version'], GEND_SOCIETY_VERSION, '>' ) ? ' (newer than installed)' : ' (not newer than installed; core will not offer it)' ) );
		}

		// Warm cache.
		$gs107_requests = array();
		$gs107_r2       = $gs107_offer();
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$gs107_info_r = plugins_api( 'plugin_information', array( 'slug' => 'gend-society' ) );
		$gs107( 0 === count( $gs107_requests ), 'warm cache: 0 requests (offer + plugins_api)', implode( ', ', $gs107_requests ) );
		$gs107( is_array( $gs107_r2 ) && isset( $gs107_r2['version'] ) && $gs107_want === $gs107_r2['version'], 'warm offer still matches' );
		$gs107(
			is_object( $gs107_info_r ) && ! is_wp_error( $gs107_info_r ) && ! empty( $gs107_info_r->sections['changelog'] ),
			"plugins_api('plugin_information', gend-society) has a non-empty changelog section",
			is_wp_error( $gs107_info_r ) ? $gs107_info_r->get_error_code() : ''
		);
		if ( is_object( $gs107_info_r ) && ! is_wp_error( $gs107_info_r ) ) {
			$gs107( isset( $gs107_info_r->version ) && $gs107_want === $gs107_info_r->version, 'plugins_api version matches the manifest' );
		}
	} else {
		$gs107( false, 'gend_society_update_manifest() defined (inc/self-update.php loaded on standalone)' );
	}

	// Restore the cache exactly as it was (a mocked manifest must never stay cached).
	if ( null === $gs107_cache_before ) {
		delete_site_option( 'gend_society_update_cache' );
		if ( ! is_multisite() ) {
			delete_option( 'gend_society_update_cache' );
		}
	} else {
		update_site_option( 'gend_society_update_cache', $gs107_cache_before );
	}
	$gs107( get_site_option( 'gend_society_update_cache', null ) === $gs107_cache_before, 'gend_society_update_cache restored to its original state' );
}

// ── Writes (read-only modes) ──
remove_filter( 'pre_http_request', $gs107_guard, -1000 );
if ( 'standalone' !== $gs107_mode ) {
	// Safety net if the battery runs against the wrong mode: never leave a cache behind.
	if ( get_site_option( 'gend_society_update_cache', null ) !== $gs107_cache_before ) {
		$gs107_info( 'gend_society_update_cache changed during the run; restoring it' );
		if ( null === $gs107_cache_before ) {
			delete_site_option( 'gend_society_update_cache' );
			if ( ! is_multisite() ) {
				delete_option( 'gend_society_update_cache' );
			}
		} else {
			update_site_option( 'gend_society_update_cache', $gs107_cache_before );
		}
	}
	$gs107_ours  = array_values( array_unique( array_filter( $gs107_writes, function ( $o ) {
		return 0 === strpos( $o, 'gend_society' ) || 0 === strpos( $o, 'gend_' ) || false !== strpos( $o, 'update_plugins' );
	} ) ) );
	$gs107_other = array_values( array_diff( array_unique( $gs107_writes ), $gs107_ours ) );
	$gs107( empty( $gs107_ours ), 'no gend_*/update_plugins option or transient written by the battery', implode( ', ', $gs107_ours ) );
	if ( $gs107_other ) {
		$gs107_info( 'unrelated option writes during the run (other plugins): ' . implode( ', ', $gs107_other ) );
	}
}

$gs107_finish();
