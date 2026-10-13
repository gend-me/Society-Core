<?php
/**
 * Section refresh (plan 109-04; PLAN-05): login, the Refresh AJAX and checkout completion invalidate the
 * features, membership, catalogue and hub-area caches; the next fetch carries no If-None-Match.
 *
 *   - wp_login: all four caches invalidated (rev dropped, expiry gone, hub-area options deleted)
 *   - next fetch (scenario notmodified) has no If-None-Match and gets a 200
 *   - AJAX gend_society_plan_refresh (nonce gs_plan, manage_options): invalidate + view_model(true);
 *     JSON {view_model, html:{}} with fragments from the gend_society_plan_refresh_html filter
 *   - bad nonce / subscriber: JSON error, and a real 403 through admin-ajax.php (loopback with a session cookie)
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! has_action( 'wp_ajax_gend_society_plan_refresh' ), 'refresh-absent', 'no gend_society_plan_refresh AJAX on ' . gs109_mode() );
	return;
}

gs109p_setup();
$fx = gs109p_pair( 'connected' );
if ( ! $fx ) {
	return;
}
$token = $fx['token'];
$admin = gs109p_admin_id();
gs109p_http_capture();

$populate = function () {
	gend_society_plan_features();
	gend_society_plan_catalog();
	gend_society_plan_membership();
	update_option( 'gend_society_hub_area_cache_groups', array( 'payload' => array( 'x' => 1 ), 'expires' => time() + 300 ), false );
	update_option( 'gend_society_hub_area_cache_feature-suite', array( 'payload' => array( 'x' => 1 ), 'expires' => time() + 300 ), false );
};
$is_invalid = function () {
	global $wpdb;
	$f   = gs109p_raw( 'gend_society_features_cache' );
	$fe  = gs109p_raw( 'gend_society_features_cache_expires' );
	$c   = gs109p_raw( 'gend_society_plan_catalog_cache' );
	$me  = gs109p_raw( 'gend_society_remote_membership_cache_expires' );
	$hub = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_hub\\_area\\_cache\\_%'" );
	$why = array();
	if ( is_array( $f ) && ! empty( $f['rev'] ) ) {
		$why[] = 'features rev kept';
	}
	if ( null !== $fe && (int) $fe > time() ) {
		$why[] = 'features expiry';
	}
	if ( is_array( $c ) && ( ! empty( $c['rev'] ) || (int) ( $c['expires'] ?? 0 ) > time() ) ) {
		$why[] = 'catalogue rev/expiry';
	}
	if ( null !== $me && (int) $me > time() ) {
		$why[] = 'membership expiry';
	}
	if ( $hub ) {
		$why[] = $hub . ' hub-area cache options left';
	}
	return $why;
};

// ------------------------------------------------------------------------------------------------- wp_login
$populate();
gs109_assert( array() !== $is_invalid(), 'refresh-populated', 'caches populated before login: ' . implode( ', ', $is_invalid() ) );
gs109_assert( false !== has_action( 'wp_login', 'gend_society_plan_invalidate_all' ), 'refresh-login-hook', 'gend_society_plan_invalidate_all hooked on wp_login' );
do_action( 'wp_login', 'admin', get_user_by( 'id', $admin ) );
$why = $is_invalid();
gs109_assert( ! $why, 'refresh-login-invalidates', 'wp_login invalidates features, membership, catalogue and hub-area caches', implode( ', ', $why ) );
gs109_assert( is_array( gs109p_raw( 'gend_society_features_cache' ) ), 'refresh-login-keeps-stale', 'the features payload itself stays (stale fallback), only rev/expiry dropped' );

gs109_fixture_scenario( 'notmodified' );
gs109_fixture_log_lines();
gs109p_http();
gend_society_plan_features();
gend_society_plan_catalog();
$req = gs109p_hub_requests( gs109p_http() );
$inm = array_filter(
	array_map(
		function ( $r ) {
			return gs109p_header( $r['headers'], 'If-None-Match' );
		},
		$req
	)
);
$ans = gs109p_fixture_answers();
gs109_assert( 2 === count( $req ) && ! $inm, 'refresh-login-next-no-inm', 'after login: next features + catalogue fetches carry no If-None-Match', wp_json_encode( $inm ) );
gs109_assert( in_array( 'GET features -> 200', $ans, true ) && in_array( 'GET catalog -> 200', $ans, true ), 'refresh-login-next-200', 'and get a full 200 (not 304)', implode( ',', $ans ) );

// ---------------------------------------------------------------------------------------- AJAX in-process
$ajax_refresh = function () {
	do_action( 'wp_ajax_gend_society_plan_refresh' );
};
$populate();
$frag = function ( $html, $vm ) {
	$html['gs109'] = '<p>fragment ' . esc_html( (string) ( $vm['plan']['slug'] ?? '' ) ) . '</p>';
	return $html;
};
add_filter( 'gend_society_plan_refresh_html', $frag, 10, 2 );
wp_set_current_user( $admin );
$nonce = wp_create_nonce( 'gs_plan' );
gs109p_http();
$r = gs109p_call( $ajax_refresh, 'POST', array(), array( 'action' => 'gend_society_plan_refresh', 'nonce' => $nonce ), true );
$req = gs109p_hub_requests( gs109p_http() );
remove_filter( 'gend_society_plan_refresh_html', $frag, 10 );
$j = is_array( $r['json'] ) ? $r['json'] : array();
gs109_assert( ! empty( $j['success'] ) && 'ok' === ( $j['data']['view_model']['state'] ?? '' ), 'refresh-ajax-ok', 'AJAX refresh: success, view_model.state ok', substr( $r['output'], 0, 200 ) );
gs109_assert( isset( $j['data']['html'] ) && is_array( $j['data']['html'] ) && false !== strpos( (string) ( $j['data']['html']['gs109'] ?? '' ), 'fragment connected' ), 'refresh-ajax-html', 'html fragments come from gend_society_plan_refresh_html', wp_json_encode( $j['data']['html'] ?? null ) );
$routes = array_map(
	function ( $x ) {
		return preg_replace( '#^.*/install/[^/]+/#', '', $x['url'] );
	},
	$req
);
$inm = array_filter(
	array_map(
		function ( $x ) {
			return gs109p_header( $x['headers'], 'If-None-Match' );
		},
		$req
	)
);
gs109_assert( in_array( 'features', $routes, true ) && in_array( 'catalog', $routes, true ) && in_array( 'membership', $routes, true ) && ! $inm, 'refresh-ajax-refetch', 'AJAX refresh refetches features, membership, catalogue without If-None-Match', wp_json_encode( $routes ) );
gs109_assert( 0 === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'gend\\_society\\_hub\\_area\\_cache\\_%'" ), 'refresh-ajax-hub-areas', 'AJAX refresh deletes the hub-area caches' );
gs109_assert( false === strpos( $r['output'], $token ), 'refresh-ajax-no-token', 'AJAX response never contains the token' );

$r = gs109p_call( $ajax_refresh, 'POST', array(), array( 'action' => 'gend_society_plan_refresh', 'nonce' => 'bad' ), true );
gs109_assert( is_array( $r['json'] ) && false === $r['json']['success'], 'refresh-ajax-bad-nonce', 'bad nonce: JSON error', substr( $r['output'], 0, 120 ) );
$sub = gs109p_user( 'subscriber' );
wp_set_current_user( $sub );
$r = gs109p_call( $ajax_refresh, 'POST', array(), array( 'action' => 'gend_society_plan_refresh', 'nonce' => wp_create_nonce( 'gs_plan' ) ), true );
gs109_assert( is_array( $r['json'] ) && false === $r['json']['success'], 'refresh-ajax-subscriber', 'subscriber: JSON error', substr( $r['output'], 0, 120 ) );
wp_set_current_user( 0 );

// ------------------------------------------------------------------------- loopback (real status codes)
$url = admin_url( 'admin-ajax.php' );
$sa  = gs109p_session( $admin );
$w   = gs109p_web( 'POST', $url, $sa, array( 'action' => 'gend_society_plan_refresh', 'nonce' => gs109p_nonce( $sa, 'gs_plan' ) ) );
$wj  = json_decode( $w['body'], true );
gs109_assert( 200 === $w['status'] && ! empty( $wj['success'] ) && 'ok' === ( $wj['data']['view_model']['state'] ?? '' ), 'refresh-web-ok', 'admin-ajax.php refresh as admin: 200 + view_model', $w['status'] . ' ' . substr( $w['body'], 0, 160 ) . $w['error'] );
gs109_assert( false === strpos( $w['body'], $token ), 'refresh-web-no-token', 'response body never contains the token' );
$w = gs109p_web( 'POST', $url, $sa, array( 'action' => 'gend_society_plan_refresh', 'nonce' => 'bad' ) );
gs109_assert( 403 === $w['status'] && false === ( json_decode( $w['body'], true )['success'] ?? null ), 'refresh-web-bad-nonce-403', 'bad nonce: HTTP 403 JSON', $w['status'] . ' ' . substr( $w['body'], 0, 120 ) );
$ss = gs109p_session( $sub );
$w  = gs109p_web( 'POST', $url, $ss, array( 'action' => 'gend_society_plan_refresh', 'nonce' => gs109p_nonce( $ss, 'gs_plan' ) ) );
gs109_assert( 403 === $w['status'] && false === ( json_decode( $w['body'], true )['success'] ?? null ), 'refresh-web-subscriber-403', 'subscriber: HTTP 403 JSON', $w['status'] . ' ' . substr( $w['body'], 0, 120 ) );
$w = gs109p_web( 'POST', $url, array(), array( 'action' => 'gend_society_plan_refresh', 'nonce' => 'x' ) );
gs109_assert( 200 !== $w['status'] || false === strpos( $w['body'], 'view_model' ), 'refresh-web-anon', 'logged out: no view-model (no nopriv handler)', $w['status'] . ' ' . substr( $w['body'], 0, 80 ) );
