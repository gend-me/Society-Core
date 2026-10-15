<?php
/**
 * Section popup (plan 109-21, D14; PLAN-02): "Choose a plan" opens gend.me's own Change Plan page in a popup.
 * Replaces the 109-04 launcher section (the admin-post launcher is gone in 1.5.1).
 *
 *   - no admin_post_gend_society_plan_checkout hook, no launcher functions, no launcher assets
 *   - view-model change_plan_url = the hub's membership_url + ?ui=embed&plan_only=1&group=dashboard (exact)
 *   - '' (and no button) when membership_url is off the paired hub (evil.example, the bed host, javascript:,
 *     protocol-relative, credentials, another port) or membership_id is 0 / missing
 *   - no token in change_plan_url, in any href or in the rendered HTML; no change-plan POST from the plugin
 *   - the old admin-post URL no longer redirects anywhere (real loopback request)
 *   (109-30) the button checks run on the dashboard membership card's UPGRADE (a.gs-dash-sa-upgrade[data-gs-popup]);
 *   the Plan & features page they used to read is gone
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! has_action( 'admin_post_gend_society_plan_checkout' ) && ! function_exists( 'gend_society_plan_change_plan_url' ), 'popup-absent', 'no plan launcher and no Change Plan URL builder on ' . gs109_mode() );
	return;
}

if ( ! function_exists( 'gs109pu_dash' ) ) {
	/** The standalone GenD dashboard rendered in-process as $uid (screen 'dashboard'). */
	function gs109pu_dash( $uid ) {
		if ( ! function_exists( 'gend_society_render_custom_dashboard_screen' ) ) {
			return '';
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		wp_set_current_user( $uid );
		set_current_screen( 'dashboard' );
		ob_start();
		gend_society_render_custom_dashboard_screen();
		return (string) ob_get_clean();
	}
}

$fx = gs109pl_start( 'connected' );
if ( ! $fx ) {
	return;
}
$token   = $fx['token'];
$admin   = gs109p_admin_id();
$logmark = gs109p_httplog_mark();
gs109p_http_capture();

// ---------------------------------------------------------------------------------------- launcher gone
$gone = array();
foreach ( array( 'gend_society_plan_launcher_url', 'gend_society_plan_checkout_resolve', 'gend_society_plan_checkout_handle', 'gend_society_plan_launcher_error', 'gend_society_plan_launcher_die_handler_name', 'gend_society_plan_launcher_die_handler' ) as $fn ) {
	if ( function_exists( $fn ) ) {
		$gone[] = $fn;
	}
}
gs109_assert( ! $gone && ! has_action( 'admin_post_gend_society_plan_checkout' ), 'popup-no-launcher', 'no admin_post_gend_society_plan_checkout hook and no launcher function', $gone );
gs109_assert( ! file_exists( GEND_SOCIETY_DIR . 'assets/gs-plan-launcher.js' ) && ! file_exists( GEND_SOCIETY_DIR . 'assets/gs-plan-launcher.css' ), 'popup-no-launcher-assets', 'gs-plan-launcher.js / .css are not shipped' );

// ---------------------------------------------------------------------------------------- the exact URL
$mem    = gs109_fixture_json( 'connected', 'membership.json' );
$expect = (string) $mem['membership_url'] . '?ui=embed&plan_only=1&group=dashboard';
$vm     = gs109pl_vm( 'connected' );
gs109_assert_eq( $expect, $vm['change_plan_url'], 'popup-url-exact', 'change_plan_url = <hub>/my-account/membership/<id>/?ui=embed&plan_only=1&group=dashboard' );
gs109_assert( gend_society_plan_is_hub_url( $vm['change_plan_url'] ) && (int) $mem['membership_id'] > 0 && false !== strpos( $vm['change_plan_url'], '/membership/' . (int) $mem['membership_id'] . '/' ), 'popup-url-hub', 'URL is on the paired hub and names the membership id' );
parse_str( (string) wp_parse_url( $vm['change_plan_url'], PHP_URL_QUERY ), $q );
gs109_assert_eq( array( 'ui' => 'embed', 'plan_only' => '1', 'group' => 'dashboard' ), $q, 'popup-url-query', 'query is exactly ui, plan_only, group (no token, nonce or plan id)' );
gs109_assert( false === strpos( $vm['change_plan_url'], $token ), 'popup-url-no-token', 'change_plan_url carries no token' );
gs109_assert_eq( $expect, gend_society_plan_change_plan_url( $mem ), 'popup-builder', 'gend_society_plan_change_plan_url() on the fixture payload' );

// ---------------------------------------------------------------------------------------- off-hub / bad ids
$bad_urls = array(
	'https://evil.example/my-account/membership/9101/',
	'http://localhost.evil.example/my-account/membership/9101/',
	'http://evil.example@localhost:8919/my-account/membership/9101/',
	'http://localhost:8909/my-account/membership/9101/',
	'http://localhost:8920/my-account/membership/9101/',
	'javascript:alert(1)',
	'//evil.example/my-account/membership/9101/',
	'/my-account/membership/9101/',
	'',
);
$bed = home_url( '/my-account/membership/9101/' );
if ( ! in_array( $bed, $bad_urls, true ) ) {
	$bad_urls[] = $bed;
}
$leaks = array();
foreach ( $bad_urls as $u ) {
	$m                   = $mem;
	$m['membership_url'] = $u;
	if ( '' !== gend_society_plan_change_plan_url( $m ) ) {
		$leaks[] = $u;
	}
}
gs109_assert( ! $leaks, 'popup-off-hub', 'membership_url off the paired hub (evil.example, alias host, credentials, bed host, other port, javascript:, protocol-relative, relative, empty) -> no URL', $leaks );
$m                  = $mem;
$m['membership_id'] = 0;
$z0                 = gend_society_plan_change_plan_url( $m );
unset( $m['membership_id'] );
$zm                 = gend_society_plan_change_plan_url( $m );
$m                  = $mem;
$m['membership_url'] = array( 'x' );
$za                 = gend_society_plan_change_plan_url( $m );
gs109_assert( '' === $z0 && '' === $zm && '' === $za, 'popup-bad-id', 'membership_id 0 / missing or a non-string membership_url -> no URL' );

// Through the view-model and the dashboard membership card (109-30: the Plan & features page is gone): no UPGRADE.
foreach ( array( 'evil' => 'https://evil.example/my-account/membership/9101/', 'bed' => $bed, 'js' => 'javascript:alert(1)' ) as $k => $u ) {
	$m                   = $mem;
	$m['membership_url'] = $u;
	unset( $m['rev'] );
	$off  = gs109p_inject( 'membership', 200, $m );
	$v    = gs109pl_vm( 'connected' );
	$html = gs109pu_dash( $admin );
	$off();
	$xp   = gs109pl_xp( $html );
	$pops = gs109pl_attrs( $xp, '//a[@data-gs-popup]', 'href' );
	gs109_assert( '' === $v['change_plan_url'] && 0 === gs109pl_count( $xp, '//a[contains(@class,"gs-dash-sa-upgrade")]' ) && false === stripos( implode( ' ', $pops ), 'evil.example' ) && false === stripos( implode( ' ', $pops ), 'javascript:' ) && false === stripos( implode( ' ', array_merge( gs109pl_attrs( $xp, '//*[@href]', 'href' ), gs109pl_attrs( $xp, '//*[@src]', 'src' ) ) ), 'evil.example' ), 'popup-off-hub-card-' . $k, 'membership_url ' . $k . ': no UPGRADE on the dashboard membership card, the URL is in no link / src' );
}
$m                  = $mem;
$m['membership_id'] = 0;
unset( $m['rev'] );
$off = gs109p_inject( 'membership', 200, $m );
$v   = gs109pl_vm( 'connected' );
$off();
gs109_assert( '' === $v['change_plan_url'] && 0 === gs109pl_count( gs109pl_xp( gs109pu_dash( $admin ) ), '//a[contains(@class,"gs-dash-sa-upgrade")]' ), 'popup-id0-card', 'membership_id 0: no UPGRADE on the dashboard' );

// ---------------------------------------------------------------------------------------- no POST, no token
gs109p_clear_caches();
gs109p_http();
$vm   = gend_society_plan_view_model( true );
$page = array( 'output' => gs109pu_dash( $admin ) );
$reqs = gs109p_hub_requests( gs109p_http() );
$post = array_filter(
	$reqs,
	function ( $r ) {
		return 'GET' !== $r['method'] || false !== strpos( $r['url'], 'change-plan' );
	}
);
gs109_assert( ! $post, 'popup-no-change-plan-call', 'rendering the dashboard makes no change-plan call and no non-GET hub request (' . count( $reqs ) . ' GETs)', array_values( array_map( function ( $r ) { return $r['method'] . ' ' . $r['url']; }, $post ) ) );
$hrefs = gs109pl_attrs( gs109pl_xp( $page['output'] ), '//a', 'href' );
$tok   = array_filter(
	$hrefs,
	function ( $h ) use ( $token ) {
		return false !== strpos( $h, $token );
	}
);
gs109_assert( ! $tok && false === strpos( $page['output'], $token ), 'popup-no-token-html', 'no token in any href (' . count( $hrefs ) . ' links) or anywhere in the page' );
gs109_assert( false === strpos( $page['output'], 'gend_society_plan_checkout' ) && false === strpos( $page['output'], 'data-gs-plan-checkout' ), 'popup-page-no-launcher', 'dashboard has no plan launcher action and no per-tier Choose' );
$upg = gs109pl_attrs( gs109pl_xp( $page['output'] ), '//a[contains(@class,"gs-dash-sa-upgrade")][@data-gs-popup]', 'href' );
gs109_assert( 1 === count( $upg ) && $expect === html_entity_decode( $upg[0] ), 'popup-page-button', 'dashboard: one UPGRADE popup link to the exact Change Plan URL', $upg );

// ---------------------------------------------------------------------------------------- old URL is dead
$sa = gs109p_session( $admin );
$w  = gs109p_web( 'GET', add_query_arg( array( 'action' => 'gend_society_plan_checkout', 'plan' => 21, '_wpnonce' => gs109p_nonce( $sa, 'gs_plan_checkout' ) ), admin_url( 'admin-post.php' ) ), $sa );
gs109_assert( '' === $w['location'] && ! in_array( $w['status'], array( 301, 302, 303, 307, 308 ), true ) && false === strpos( $w['body'], $token ), 'popup-web-old-launcher', 'the 1.5.0 launcher URL no longer redirects (HTTP ' . $w['status'] . ', no Location, no token)', $w['location'] );

$log = gs109p_httplog_since( $logmark );
gs109_assert( false === strpos( $log, $token ), 'popup-log-no-token', 'gs109-http.log never contains the token (' . substr_count( $log, "\n" ) . ' new lines)' );
