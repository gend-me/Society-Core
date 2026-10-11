<?php
/**
 * Phase 106 UAT (RUN-01, RUN-02, RUN-04, RUN-05, RUN-07, RUN-08): standalone first run
 * + consent invariants, and the hub/container "nothing changed" invariants.
 *
 * Read-only: writes no option, no transient, no user meta. Run on a deployed install:
 *   wp eval-file 106-uat-first-run.php                      (mode auto-detected)
 *   wp eval-file 106-uat-first-run.php standalone           (also assert the expected mode)
 *   GS106_EXPECT_MODE=hub wp --allow-root --path=/var/www/html eval-file /tmp/106-uat-first-run.php
 *
 * Output: one line per assert prefixed exactly "PASS:" or "FAIL:" (INFO:/SKIP: lines are
 * context only), then "ALL PASS" or "FAILED n" (exit 1 under WP-CLI).
 *
 * standalone:
 *   - inc/hub-url.php, consent.php, readiness.php, pages/welcome.php, admin-experience.php,
 *     theme-download-notice.php loaded; oauth-login.php, frontend-bar.php and every
 *     container/hub-tier file NOT loaded (loader state + get_included_files())
 *   - gend_society_oauth_is_hub_site() false
 *   - no /gend-society/v1/oauth/login, /gend-pm-sync/v1 or /gs/v1/web-shell routes
 *   - gend_society_remote_allowed() false before consent (skipped, with a SKIP: line,
 *     when the bed is already connected / consented)
 *   - admin skin off by default; login-style.php and pages/dashboard.php skipped with
 *     reason 'needs' (when the owner opted in, the inverse is asserted instead)
 *   - admin page 'gend-society' registered (admin_menu hook + sandboxed registration)
 *   - readiness returns ids php, sodium, rest, loopback, basic_auth, public_dns
 *     (gend_society_readiness_collect(): self-host requests only, nothing stored)
 * hub / container:
 *   - hub-url.php, consent.php, readiness.php, oauth-login.php loaded;
 *     admin-experience.php, pages/welcome.php, theme-download-notice.php NOT loaded
 *   - consent gate true; skin on (hub always; container unless option = native)
 *   - no 'gend-society' admin page
 *   - no gend_society_consent / gend_society_admin_experience / gend_society_welcome_dismissed
 *     option written by the plugin
 * every mode:
 *   - GEND_SOCIETY_VERSION equals the plugin header Version
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs106_expect = getenv( 'GS106_EXPECT_MODE' );
if ( ! is_string( $gs106_expect ) || '' === $gs106_expect ) {
	$gs106_expect = ( isset( $args[0] ) && '' !== $args[0] ) ? (string) $args[0] : '';
}
$gs106_expect = strtolower( trim( $gs106_expect ) );
$gs106_fail   = 0;

$gs106 = function ( $ok, $label, $detail = '' ) use ( &$gs106_fail ) {
	if ( $ok ) {
		echo "PASS: {$label}\n";
	} else {
		++$gs106_fail;
		echo "FAIL: {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
	}
};

$gs106_finish = function () use ( &$gs106_fail ) {
	if ( $gs106_fail ) {
		echo "FAILED {$gs106_fail}\n";
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::halt( 1 );
		}
		exit( 1 );
	}
	echo "ALL PASS\n";
};

$gs106_api = function_exists( 'gend_society_runtime_mode' ) && function_exists( 'gend_society_loaded_modules' )
	&& function_exists( 'gend_society_mode_tiers' ) && function_exists( 'gend_society_admin_skin_enabled' )
	&& defined( 'GEND_SOCIETY_DIR' ) && defined( 'GEND_SOCIETY_VERSION' );
$gs106( $gs106_api, 'runtime-mode + admin-experience API present (gend-society >= 1.2.1 loaded)' );
if ( ! $gs106_api ) {
	$gs106_finish();
	return;
}

// ── Mode ──
$gs106_mode  = gend_society_runtime_mode();
$gs106_state = gend_society_loaded_modules();
$gs106_src   = function_exists( 'gend_society_runtime_mode_source' ) ? gend_society_runtime_mode_source() : '?';
echo "INFO: mode={$gs106_mode} source={$gs106_src} version=" . GEND_SOCIETY_VERSION . ' loaded=' . count( $gs106_state['loaded'] ) . ' skipped=' . count( $gs106_state['skipped'] ) . "\n";
if ( '' !== $gs106_expect ) {
	$gs106( $gs106_mode === $gs106_expect, "runtime mode === {$gs106_expect} (GS106_EXPECT_MODE / arg)", "mode={$gs106_mode}" );
}
$gs106( in_array( $gs106_mode, array( 'hub', 'container', 'standalone' ), true ), 'runtime mode is hub|container|standalone', "mode={$gs106_mode}" );

// ── Version (every mode) ──
$gs106_header = get_file_data( GEND_SOCIETY_DIR . 'gend-society.php', array( 'Version' => 'Version' ) );
$gs106(
	isset( $gs106_header['Version'] ) && GEND_SOCIETY_VERSION === $gs106_header['Version'],
	'GEND_SOCIETY_VERSION equals the plugin header Version',
	'constant=' . GEND_SOCIETY_VERSION . ' header=' . ( isset( $gs106_header['Version'] ) ? $gs106_header['Version'] : '(none)' )
);

// ── Helpers ──
$gs106_loaded = function ( $file ) use ( $gs106_state ) {
	return in_array( $file, $gs106_state['loaded'], true );
};
$gs106_skip_reason = function ( $file ) use ( $gs106_state ) {
	return isset( $gs106_state['skipped'][ $file ] ) ? $gs106_state['skipped'][ $file ] : ( in_array( $file, $gs106_state['loaded'], true ) ? 'loaded' : 'absent' );
};
$gs106_expect_loaded = function ( array $files, $want ) use ( $gs106, $gs106_loaded, $gs106_skip_reason, $gs106_mode ) {
	foreach ( $files as $f ) {
		$is = $gs106_loaded( $f );
		$gs106( $want === $is, "{$gs106_mode}: {$f} " . ( $want ? 'loaded' : 'NOT loaded' ), 'state=' . $gs106_skip_reason( $f ) );
	}
};

$gs106_manifest = require GEND_SOCIETY_DIR . 'inc/bootstrap/manifest.php';
$gs106_tier     = array();
foreach ( $gs106_manifest['modules'] as $e ) {
	$list = isset( $e['group'] ) ? $e['modules'] : array( $e );
	foreach ( $list as $m ) {
		$gs106_tier[ $m['file'] ] = $m['tier'];
	}
}
foreach ( $gs106_manifest['partials'] as $p ) {
	if ( ! isset( $gs106_tier[ $p['file'] ] ) ) {
		$gs106_tier[ $p['file'] ] = $p['tier'];
	}
}

$gs106_routes   = array_keys( rest_get_server()->get_routes() );
$gs106_prefixed = function ( $prefix ) use ( $gs106_routes ) {
	return array_values(
		array_filter(
			$gs106_routes,
			function ( $r ) use ( $prefix ) {
				return 0 === strpos( $r, $prefix );
			}
		)
	);
};

/*
 * Sandboxed admin-menu registration: run the GenD page registration against a copy of
 * the menu globals and restore them afterwards (nothing persists; wp eval-file has no
 * admin_menu pass of its own).
 */
$gs106_page_registered = function () {
	if ( ! function_exists( 'gend_society_welcome_register_page' ) ) {
		return false;
	}
	if ( ! function_exists( 'add_menu_page' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$keys   = array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', 'gend_society_welcome_hook' );
	$backup = array();
	foreach ( $keys as $k ) {
		$backup[ $k ] = array_key_exists( $k, $GLOBALS ) ? $GLOBALS[ $k ] : null;
	}
	if ( ! isset( $GLOBALS['menu'] ) || ! is_array( $GLOBALS['menu'] ) ) {
		$GLOBALS['menu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
	}
	if ( ! isset( $GLOBALS['admin_page_hooks'] ) || ! is_array( $GLOBALS['admin_page_hooks'] ) ) {
		$GLOBALS['admin_page_hooks'] = array();
	}
	gend_society_welcome_register_page();
	$ok = isset( $GLOBALS['admin_page_hooks']['gend-society'] );
	foreach ( $keys as $k ) {
		if ( null === $backup[ $k ] ) {
			unset( $GLOBALS[ $k ] );
		} else {
			$GLOBALS[ $k ] = $backup[ $k ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
		}
	}
	return $ok;
};

$gs106_opt_exp = get_option( 'gend_society_admin_experience', null );

if ( 'standalone' === $gs106_mode ) {
	// ── Modules ──
	$gs106_expect_loaded(
		array( 'inc/hub-url.php', 'inc/consent.php', 'inc/readiness.php', 'inc/pages/welcome.php', 'inc/admin-experience.php', 'inc/theme-download-notice.php' ),
		true
	);
	$gs106_expect_loaded( array( 'inc/oauth-login.php', 'inc/frontend-bar.php' ), false );

	$gs106_bad = array();
	foreach ( $gs106_state['loaded'] as $f ) {
		if ( isset( $gs106_tier[ $f ] ) && in_array( $gs106_tier[ $f ], array( 'hub', 'container' ), true ) ) {
			$gs106_bad[] = "{$f} [{$gs106_tier[ $f ]}]";
		}
	}
	$gs106_dir = wp_normalize_path( GEND_SOCIETY_DIR );
	foreach ( get_included_files() as $inc ) {
		$inc = wp_normalize_path( $inc );
		if ( 0 !== strpos( $inc, $gs106_dir ) ) {
			continue;
		}
		$rel = substr( $inc, strlen( $gs106_dir ) );
		if ( isset( $gs106_tier[ $rel ] ) && in_array( $gs106_tier[ $rel ], array( 'hub', 'container' ), true ) ) {
			$gs106_bad[] = "{$rel} [{$gs106_tier[ $rel ]}] (included)";
		}
	}
	$gs106( ! $gs106_bad, 'standalone: no container/hub-tier file loaded or included', implode( '; ', array_unique( $gs106_bad ) ) );

	// ── Hub URL helper ──
	$gs106(
		function_exists( 'gend_society_oauth_is_hub_site' ) && false === gend_society_oauth_is_hub_site(),
		'standalone: gend_society_oauth_is_hub_site() is false',
		function_exists( 'gend_society_oauth_is_hub_site' ) ? 'returned true' : 'undefined (hub-url.php missing)'
	);

	// ── Routes ──
	$r = $gs106_prefixed( '/gend-society/v1/oauth/login' );
	$gs106( ! $r, 'standalone: no /gend-society/v1/oauth/login route', implode( ', ', $r ) );
	$r = $gs106_prefixed( '/gend-pm-sync/v1' );
	$gs106( ! $r, 'standalone: no /gend-pm-sync/v1 routes', implode( ', ', $r ) );
	$r = $gs106_prefixed( '/gs/v1/web-shell' );
	$gs106( ! $r, 'standalone: no /gs/v1/web-shell routes', implode( ', ', $r ) );

	// ── Consent gate ──
	$gs106_has_consent_state = null !== get_option( 'gend_society_consent', null )
		|| '' !== (string) get_option( 'gend_society_install_token', '' )
		|| false !== get_option( 'gend_society_remote_consent', false );
	if ( ! function_exists( 'gend_society_remote_allowed' ) ) {
		$gs106( false, 'standalone: gend_society_remote_allowed() defined' );
	} elseif ( ! $gs106_has_consent_state ) {
		$gs106( false === gend_society_remote_allowed( 'features' ), 'standalone: gend_society_remote_allowed() false before consent' );
		$gs106( false === gend_society_consent_given(), 'standalone: gend_society_consent_given() false before consent' );
	} else {
		echo "SKIP: standalone: pre-consent gate check -- this install has consent/pairing state (connected bed)\n";
		$gs106(
			gend_society_remote_allowed( 'features' ) === gend_society_consent_given(),
			'standalone: remote_allowed() follows consent_given() on a consented install'
		);
	}

	// ── Admin skin ──
	if ( null === $gs106_opt_exp ) {
		$gs106( false === gend_society_admin_skin_enabled(), 'standalone: admin skin off by default (no gend_society_admin_experience option)' );
		foreach ( array( 'inc/login-style.php', 'inc/pages/dashboard.php' ) as $f ) {
			$gs106( 'needs' === $gs106_skip_reason( $f ), "standalone: {$f} skipped with reason 'needs'", 'state=' . $gs106_skip_reason( $f ) );
		}
	} else {
		$gs106_on = 'gend' === $gs106_opt_exp;
		echo 'INFO: standalone: owner set gend_society_admin_experience=' . wp_json_encode( $gs106_opt_exp ) . "; asserting that state instead of the default\n";
		$gs106( $gs106_on === gend_society_admin_skin_enabled(), 'standalone: admin skin follows the owner\'s choice' );
		foreach ( array( 'inc/login-style.php', 'inc/pages/dashboard.php' ) as $f ) {
			$want = $gs106_on ? 'loaded' : 'needs';
			$gs106( $want === $gs106_skip_reason( $f ), "standalone: {$f} state {$want}", 'state=' . $gs106_skip_reason( $f ) );
		}
	}

	// ── GenD admin page ──
	$gs106( false !== has_action( 'admin_menu', 'gend_society_welcome_register_page' ), "standalone: GenD page hooked on admin_menu" );
	$gs106( $gs106_page_registered(), "standalone: admin page 'gend-society' registered" );

	// ── Readiness ──
	if ( function_exists( 'gend_society_readiness_collect' ) ) {
		$gs106_ready = gend_society_readiness_collect();
		$gs106_ids   = array();
		foreach ( (array) $gs106_ready['checks'] as $c ) {
			if ( is_array( $c ) && isset( $c['id'] ) ) {
				$gs106_ids[] = $c['id'];
				echo "INFO: readiness {$c['id']}=" . ( isset( $c['status'] ) ? $c['status'] : '?' ) . ( ! empty( $c['blocker'] ) ? ' (blocker)' : '' ) . "\n";
			}
		}
		$gs106_want = array( 'php', 'sodium', 'rest', 'loopback', 'basic_auth', 'public_dns' );
		$gs106(
			array() === array_diff( $gs106_want, $gs106_ids ),
			'standalone: readiness returns ids php, sodium, rest, loopback, basic_auth, public_dns',
			'got ' . implode( ',', $gs106_ids )
		);
	} else {
		$gs106( false, 'standalone: gend_society_readiness_collect() defined' );
	}
} else {
	// ── hub / container ──
	$gs106_expect_loaded( array( 'inc/hub-url.php', 'inc/consent.php', 'inc/readiness.php', 'inc/oauth-login.php' ), true );
	$gs106_expect_loaded( array( 'inc/admin-experience.php', 'inc/pages/welcome.php', 'inc/theme-download-notice.php' ), false );

	$gs106(
		function_exists( 'gend_society_remote_allowed' ) && true === gend_society_remote_allowed( 'features' ) && true === gend_society_consent_given(),
		"{$gs106_mode}: consent gate open (remote_allowed + consent_given true)"
	);

	if ( 'hub' === $gs106_mode ) {
		$gs106( true === gend_society_admin_skin_enabled(), 'hub: admin skin on (always)' );
	} else {
		$gs106_skin_want = 'native' !== $gs106_opt_exp;
		$gs106(
			$gs106_skin_want === gend_society_admin_skin_enabled(),
			'container: admin skin ' . ( $gs106_skin_want ? 'on' : 'off (option = native)' ),
			'option=' . wp_json_encode( $gs106_opt_exp )
		);
	}

	$gs106(
		! function_exists( 'gend_society_welcome_register_page' ) && false === has_action( 'admin_menu', 'gend_society_welcome_register_page' ),
		"{$gs106_mode}: no 'gend-society' admin page"
	);

	$gs106( null === get_option( 'gend_society_consent', null ), "{$gs106_mode}: no gend_society_consent option" );
	$gs106( null === get_option( 'gend_society_welcome_dismissed', null ), "{$gs106_mode}: no gend_society_welcome_dismissed option" );
	if ( 'hub' === $gs106_mode || null === $gs106_opt_exp ) {
		$gs106( null === $gs106_opt_exp, "{$gs106_mode}: no gend_society_admin_experience option", 'option=' . wp_json_encode( $gs106_opt_exp ) );
	} else {
		// Container: the option is a documented operator override; the plugin has no writer here.
		echo 'INFO: container: gend_society_admin_experience=' . wp_json_encode( $gs106_opt_exp ) . " (operator override)\n";
		$gs106(
			! function_exists( 'gend_society_admin_experience_set' ) && false === has_action( 'admin_post_gend_society_set_admin_experience' ),
			'container: gend_society_admin_experience not writable by the plugin (no setter, no handler)'
		);
	}
}

$gs106_finish();
