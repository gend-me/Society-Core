<?php
/**
 * Section nolock (plan 109-04; PLAN-03, D9, wordpress.org Guideline 5).
 *
 * standalone: paired with the fixture hub (scenarios connected and store-owner), no admin screen ever
 *   redirects to gs-feature-upgrade: gend_society_features_enforce_redirect() called directly AND the whole
 *   admin_init action, for every slug the gate knows, the pages registered by admin_menu and core screens.
 *   Control: the 1.4.0 gate (baseline copy) does redirect the store-owner fixture on gs-social, so the
 *   section can fail.
 * hub / container: the gate's decision (redirect location or none) equals the 1.4.0 gate's decision for the
 *   same inputs (features cache payloads x slugs x tab signals), and plan-access.php is not loaded.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

gs109p_setup();

if ( ! gs109_assert( gs109p_load_140(), 'nolock-baseline', '1.4.0 feature-gates loaded side by side (gs109old_features_*)' ) ) {
	return;
}

// ------------------------------------------------------------------------------------------------ standalone
if ( 'standalone' === gs109_mode() ) {
	// The gate does not need plan-access.php: pair, load what exists, start from empty caches.
	$fx = gs109_fixture_pair( 'connected' );
	if ( ! $fx ) {
		return;
	}
	gs109p_require_modules();
	gs109p_clear_caches();
	$slugs = gs109p_admin_slugs();
	gs109_info( 'slugs tried: ' . count( $slugs ) );
	$admin = gs109p_admin_id();

	foreach ( array( 'connected', 'store-owner' ) as $sc ) {
		gs109p_scenario( $sc );
		wp_set_current_user( $admin );
		$features = gend_society_features_get_cached();
		$expect   = gs109_fixture_json( $sc, 'features.json' );
		gs109_assert( is_array( $features ) && isset( $expect['plan_slug'] ) && ( $features['plan_slug'] ?? '' ) === $expect['plan_slug'], "nolock-{$sc}-cache", "features cache holds the {$sc} fixture (plan " . ( $features['plan_slug'] ?? '?' ) . ')' );

		$direct = array();
		$full   = array();
		$errors = array();
		foreach ( $slugs as $slug ) {
			foreach ( array( array(), array( 'tab' => 'x' ) ) as $extra ) {
				$d = gs109p_gate_decision( 'gend_society_features_enforce_redirect', $slug, $extra );
				if ( 'none' !== $d ) {
					$direct[] = $slug . ' -> ' . $d;
				}
			}
			// The whole admin_init action (every plugin callback), only redirects to the upgrade page count.
			$get = gs109p_admin_screen( $slug );
			wp_set_current_user( $admin );
			$r = gs109p_call(
				function () use ( &$errors, $slug ) {
					try {
						do_action( 'admin_init' );
					} catch ( GS109P_Stop $e ) {
						throw $e;
					} catch ( \Throwable $t ) {
						$errors[ get_class( $t ) . ': ' . $t->getMessage() ] = $slug;
					}
				},
				'GET',
				$get
			);
			unset( $GLOBALS['current_screen'] );
			if ( 'redirect' === $r['kind'] && false !== strpos( $r['location'], 'gs-feature-upgrade' ) ) {
				$full[] = $slug . ' -> ' . $r['location'];
			}
		}
		foreach ( $errors as $msg => $slug ) {
			gs109_info( "admin_init in CLI threw (first on {$slug}): {$msg}" );
		}
		gs109_assert( ! $direct, "nolock-{$sc}-direct", 'enforce_redirect(): no redirect on any of ' . count( $slugs ) . ' screens (x2 with a tab signal)', implode( ' | ', array_slice( $direct, 0, 5 ) ) );
		gs109_assert( ! $full, "nolock-{$sc}-admin-init", 'do_action(admin_init): no redirect to gs-feature-upgrade on any screen', implode( ' | ', array_slice( $full, 0, 5 ) ) );
	}

	// Control: the same store-owner cache through the 1.4.0 gate redirects gs-social (the section can fail).
	$old = gs109p_gate_decision( 'gs109old_features_enforce_redirect', 'gs-social' );
	gs109_assert( false !== strpos( $old, 'gs-feature-upgrade' ), 'nolock-control-140', '1.4.0 gate redirects gs-social for store-owner (control): ' . $old );

	// The upgrade page itself stays reachable and still renders (informational, no lock).
	gs109_assert( false !== has_action( 'admin_init', 'gend_society_features_enforce_redirect' ), 'nolock-hook-kept', 'admin_init hook still registered (the gate returns early on standalone)' );
	return;
}

// ------------------------------------------------------------------------------------------- hub / container
gs109_assert( ! function_exists( 'gend_society_plan_view_model' ) && ! has_action( 'admin_post_gend_society_plan_checkout' ), 'nolock-module-absent', 'plan-access.php not loaded on ' . gs109_mode() );
gs109_assert( function_exists( 'gend_society_features_enforce_redirect' ), 'nolock-gate-present', 'feature-gates.php loaded on ' . gs109_mode() );
if ( ! function_exists( 'gend_society_features_enforce_redirect' ) ) {
	return;
}
gs109_snapshot_options();
// No real hub traffic: a cache miss fetch answers WP_Error for both versions alike.
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		return false !== strpos( (string) $url, '/install/' ) ? new WP_Error( 'gs109_offline', 'nolock offline' ) : $pre;
	},
	1,
	3
);

$inputs = array(
	'no-cache'        => null,
	'empty'           => array(),
	'app-write'       => array( 'app', 'write' ),
	'store-owner'     => array( 'app', 'write', 'store' ),
	'social'          => array( 'app', 'write', 'store', 'social', 'reward' ),
	'granular'        => array( 'store.sales_team', 'features.shortcodes', 'app.theme_builder' ),
	'hub-areas-only'  => array( 'groups', 'feature-suite' ),
);
$slugs = gs109p_admin_slugs();
$cases = 0;
$diff  = array();
$redirects = 0;
foreach ( $inputs as $label => $areas ) {
	delete_option( 'gend_society_features_cache' );
	delete_option( 'gend_society_features_cache_expires' );
	if ( null !== $areas ) {
		update_option( 'gend_society_features_cache', array( 'plan_slug' => 'gs109-' . $label, 'allowed_areas' => $areas, 'learn_more_pages' => array() ), false );
		update_option( 'gend_society_features_cache_expires', time() + 600, false );
	}
	foreach ( $slugs as $slug ) {
		foreach ( array( array(), array( 'tab' => 'payouts' ), array( 'postType' => 'wp_block' ) ) as $extra ) {
			$new = gs109p_gate_decision( 'gend_society_features_enforce_redirect', $slug, $extra );
			$old = gs109p_gate_decision( 'gs109old_features_enforce_redirect', $slug, $extra );
			++$cases;
			if ( 'none' !== $old ) {
				++$redirects;
			}
			if ( $new !== $old ) {
				$diff[] = "{$label}/{$slug}" . ( $extra ? '?' . http_build_query( $extra ) : '' ) . ": new={$new} old={$old}";
			}
		}
	}
}
gs109_info( "decisions compared: {$cases} (1.4.0 redirects in {$redirects} of them)" );
gs109_assert( $redirects > 0, 'nolock-parity-nontrivial', 'the input set makes the 1.4.0 gate redirect at least once' );
gs109_assert( ! $diff, 'nolock-parity', gs109_mode() . ': every decision equals 1.4.0 (' . $cases . ' cases)', implode( ' | ', array_slice( $diff, 0, 5 ) ) );
