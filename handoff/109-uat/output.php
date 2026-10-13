<?php
/**
 * Section output (plan 109-08; D12 + regression guard for 109-10 / 109-14): hub and container output vs 1.4.0.
 * gs109-readonly: yes  (109-10: every features payload is served through pre_option filters and no options snapshot
 * is taken, so the section renders and compares only; it prints the md5 of every new render and, with env
 * GS109_OUTPUT_BASELINE=<md5>, asserts the combined md5 equals it. Usable under GS109_READONLY=1 on live.)
 *
 * hub / container:
 *   - every Phase 109 standalone module is skipped: plan-access.php, pages/plan.php, hub-embed.php (functions,
 *     admin pages, AJAX and admin-post handlers, refresh filters), and the standalone upgrade hooks
 *   - feature-gates.php: redirect decisions == 1.4.0 (features payloads x admin slugs x tab signals)
 *   - pages/feature-upgrade.php: rendered page == 1.4.0 except the declared EXPECTED classes D12 (learn-more
 *     iframe -> outbound link) and LISTENER (unchecked gs_membership_changed listener removed)
 *   - dashboard-remote-membership.php: membership panel == 1.4.0 (3 payloads; relative "N ago" times
 *     normalised, each copy rendered once first); its pre-existing frames are counted, not D12
 *   - media-storage-panel.php: popup script == 1.4.0 except LISTENER; the whole file == 1.4.0 apart from
 *     comments and that removal (PHP token comparison)
 *   - no <iframe> in the D12 output (upgrade page incl. the learn-more case, media popup script)
 * standalone: informational only (the hub / container comparison does not apply); asserts the same media
 *   popup script change, since that file is loaded in every mode.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_output.php';

gs109p_setup();

// Media popup script (every mode loads media-storage-panel.php).
$media = gs109o_media_parity();
gs109_assert( $media['js_equal'] && 1 === $media['removed'], 'output-media-js', gs109_mode() . ': media upgrade popup script == 1.4.0 except LISTENER (1 block removed)', wp_json_encode( $media ) );
gs109_assert( ! $media['new_has_listener'], 'output-media-nolistener', 'media popup script has no message listener (popup-close watchdog only)' );
gs109_assert( $media['tokens_equal'], 'output-media-file', 'media-storage-panel.php == 1.4.0 apart from comments and the LISTENER removal (PHP tokens)' );

if ( 'standalone' === gs109_mode() ) {
	gs109_info( 'standalone: hub/container parity does not apply (run the section in hub and container mode)' );
	return;
}

// ── 109 modules skipped ────────────────────────────────────────────────────────────────────────────────
$absent = array(
	'plan-access.php'  => array( 'gend_society_plan_view_model', 'gend_society_plan_hub_get', 'gend_society_plan_refresh_ajax' ),
	'pages/plan.php'   => array( 'gend_society_plan_page_render', 'gend_society_plan_fragments', 'gend_society_plan_assets_enqueue' ),
	'hub-embed.php'    => array( 'gend_society_hub_area_fetch', 'gend_society_hub_embed_fragment', 'gend_society_hub_embed_register' ),
);
$loaded = array();
foreach ( $absent as $file => $fns ) {
	foreach ( $fns as $fn ) {
		if ( function_exists( $fn ) ) {
			$loaded[] = "{$file}:{$fn}";
		}
	}
}
gs109_assert( ! $loaded, 'output-modules-absent', 'plan-access.php, pages/plan.php, hub-embed.php not loaded on ' . gs109_mode(), $loaded );
$hooks = array(
	has_action( 'wp_ajax_gend_society_plan_refresh' ),
	has_action( 'admin_post_gend_society_plan_checkout' ),
	has_filter( 'gend_society_plan_refresh_html' ),
	has_action( 'admin_enqueue_scripts', 'gend_society_feature_upgrade_standalone_enqueue' ),
);
gs109_assert( array( false, false, false, false ) === $hooks, 'output-hooks-absent', 'no 109 AJAX / admin-post / refresh filter / standalone upgrade enqueue hook', $hooks );
if ( gs109_is_readonly() ) {
	// admin_menu makes other plugins cache data (set_transient = a cache write on an external object cache).
	gs109_info( 'read-only: admin page registration not exercised (admin_menu side effects); the 109 page modules are absent (functions + hooks above)' );
} else {
	$pages = gs109p_registered_pages();
	// A CLI admin_menu run that throws (WooCommerce screen helpers are not loaded in CLI) leaves the admin as the
	// current user; the gate comparison below must run logged out, like nolock.
	wp_set_current_user( 0 );
	$found = array_values( array_intersect( array( 'gs-plan', 'gs-hub-groups', 'gs-hub-feature-suite', 'gs-hub-projects' ), $pages ) );
	gs109_assert( ! $found, 'output-pages-absent', 'no Plan & features or hub-embed admin page registered', $found );
}

// ── feature-gates decisions == 1.4.0 ────────────────────────────────────────────────────────────────────
if ( gs109_assert( gs109p_load_140() && function_exists( 'gend_society_features_enforce_redirect' ), 'output-gates-loaded', 'feature-gates 1.4.0 side by side' ) ) {
	if ( ! gs109_is_readonly() ) {
		gs109_snapshot_options();
	}
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			return false !== strpos( (string) $url, '/install/' ) ? new WP_Error( 'gs109_offline', 'output offline' ) : $pre;
		},
		1,
		3
	);
	$inputs    = array(
		'empty'     => array(),
		'app-write' => array( 'app', 'write' ),
		'store'     => array( 'app', 'write', 'store' ),
		'granular'  => array( 'store.sales_team', 'features.shortcodes' ),
		'hub-areas' => array( 'groups', 'feature-suite' ),
	);
	$slugs     = gs109p_admin_slugs( ! gs109_is_readonly() );
	wp_set_current_user( 0 );
	$cases     = 0;
	$redirects = 0;
	$diff      = array();
	foreach ( $inputs as $label => $areas ) {
		$off = gs109o_features_inject( array( 'plan_slug' => 'gs109-' . $label, 'allowed_areas' => $areas, 'learn_more_pages' => array( 'store.sales_team' => 'http://localhost:8919/learn/' ) ) );
		foreach ( $slugs as $slug ) {
			foreach ( array( array(), array( 'tab' => 'payouts' ) ) as $extra ) {
				$new = gs109p_gate_decision( 'gend_society_features_enforce_redirect', $slug, $extra );
				$old = gs109p_gate_decision( 'gs109old_features_enforce_redirect', $slug, $extra );
				++$cases;
				$redirects += 'none' !== $old ? 1 : 0;
				if ( $new !== $old ) {
					$diff[] = "{$label}/{$slug}: new={$new} old={$old}";
				}
			}
		}
		$off();
	}
	gs109_assert( $redirects > 0 && ! $diff, 'output-gates', 'feature-gates decisions == 1.4.0 (' . $cases . ' cases, ' . $redirects . ' redirects)', implode( ' | ', array_slice( $diff, 0, 3 ) ) );
}

// ── feature-upgrade page == 1.4.0 except D12 + LISTENER ─────────────────────────────────────────────────
$par = gs109o_upgrade_parity();
gs109_assert( $par['cases'] >= 28 && $par['D12'] > 0 && $par['LISTENER'] === $par['cases'] && ! $par['diff'], 'output-upgrade', 'feature-upgrade page == 1.4.0 except EXPECTED D12 (' . $par['D12'] . ') + LISTENER (' . $par['LISTENER'] . ') over ' . $par['cases'] . ' renders', implode( ' | ', array_slice( $par['diff'], 0, 3 ) ) );
gs109_assert( 0 === $par['iframes_new'] && 0 === $par['listener_new'], 'output-upgrade-clean', 'no <iframe>, no gs_membership_changed listener in the upgrade page' );

// ── membership panel byte-identical ─────────────────────────────────────────────────────────────────────
if ( gs109_is_readonly() ) {
	gs109_info( 'read-only: membership panel parity not rendered (its hosting / media / projects sections cache data); proven by the normal run on the clone' );
	$pan = null;
} else {
$pan = gs109o_panel_parity();
gs109_assert( 3 === $pan['cases'] && ! $pan['diff'] && $pan['bytes'] > 1000, 'output-panel', 'membership panel == 1.4.0 (' . $pan['cases'] . ' payloads, ' . $pan['bytes'] . ' bytes; relative "N ago" times normalised)', implode( ' | ', array_slice( $pan['diff'], 0, 3 ) ) );
// The panel's own frames (server-plan checkout, hosting sections; not the D12 learn-more page) are 1.4.0 output and
// unchanged by 109; reported for the 109-10 / 109-14 snapshot lists.
gs109_assert( $pan['iframes_new'] === $pan['iframes_old'], 'output-panel-frames-unchanged', 'membership panel frames unchanged vs 1.4.0 (' . $pan['iframes_new'] . ' pre-existing, out of D12 scope)' );
gs109_info( 'pre-existing frames in the 1.4.0 membership panel on ' . gs109_mode() . ': ' . $pan['iframes_old'] . ' (dashboard-remote-membership / dashboard-hosting server checkout; see 109-08 deferred items)' );
}

// ── no iframe in the D12 output (upgrade page, media popup) ────────────────────────────────────────────
$rendered = (string) $par['sample'] . (string) gend_society_media_storage_panel_inline_js();
gs109_assert( '' !== (string) $par['sample'] && false === stripos( $rendered, '<iframe' ), 'output-no-iframe', 'no <iframe> in the upgrade page (learn-more case) or the media popup script' );

// -- 109-10: md5 of every new render (read-only comparison against a value captured elsewhere) --------------------
$md5s = (array) ( $par['md5'] ?? array() );
ksort( $md5s );
$md5s['media_js'] = md5( (string) gend_society_media_storage_panel_inline_js() );
$all              = md5( (string) wp_json_encode( $md5s ) );
gs109_info( 'render md5s: ' . count( $md5s ) . ' renders, combined ' . $all . ( gs109_is_readonly() ? ' (read-only run)' : '' ) );
$want = strtolower( trim( (string) getenv( 'GS109_OUTPUT_BASELINE' ) ) );
if ( '' !== $want ) {
	gs109_assert_eq( $want, $all, 'output-render-md5-baseline', 'combined render md5 equals GS109_OUTPUT_BASELINE' );
}
