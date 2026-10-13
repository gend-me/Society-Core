<?php
/**
 * Section launcher (plan 109-04; PLAN-02): the same-origin checkout launcher.
 *
 *   admin-post.php?action=gend_society_plan_checkout&plan=<id>&_wpnonce=<gs_plan_checkout>
 *   - valid nonce + manage_options + plan checkout_allowed -> POST membership/change-plan server-side, 302 to
 *     the hub host only (wp_safe_redirect with a one-shot allowed_redirect_hosts for the hub host)
 *   - the raw install token is absent from Location, the response body and gs109-http.log
 *   - invalid nonce -> 403; subscriber -> 403
 *   - plan not checkout_allowed (store-owner: 21; inherited: anything) -> local error page, no redirect
 *   - change-plan answering a non-hub host (or a 409) -> refused, local error page
 * In-process (gend_society_plan_checkout_resolve() + the handler with captured redirect/die) and as real
 * loopback requests to the bed with a session cookie.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! has_action( 'admin_post_gend_society_plan_checkout' ), 'launcher-absent', 'no checkout launcher on ' . gs109_mode() );
	return;
}

gs109p_setup();
$fx = gs109p_pair( 'connected' );
if ( ! $fx ) {
	return;
}
$token   = $fx['token'];
$admin   = gs109p_admin_id();
$expect  = (string) gs109_fixture_json( 'connected', 'change-plan.json' )['checkout_url'];
$hubhost = 'localhost';
$logmark = gs109p_httplog_mark();
gs109p_http_capture();

$handler = function () {
	do_action( 'admin_post_gend_society_plan_checkout' );
};

// ---------------------------------------------------------------------------------------- launcher URL
wp_set_current_user( $admin );
$lurl = gend_society_plan_launcher_url( 21 );
$q    = array();
parse_str( (string) wp_parse_url( $lurl, PHP_URL_QUERY ), $q );
gs109_assert( 0 === strpos( $lurl, admin_url( 'admin-post.php' ) ) && 'gend_society_plan_checkout' === ( $q['action'] ?? '' ) && '21' === ( $q['plan'] ?? '' ) && false !== wp_verify_nonce( $q['_wpnonce'] ?? '', 'gs_plan_checkout' ), 'launcher-url', 'launcher URL = admin-post.php?action=gend_society_plan_checkout&plan=21&_wpnonce=<gs_plan_checkout>', $lurl );
gs109_assert( false === strpos( $lurl, $token ), 'launcher-url-no-token', 'launcher URL carries no token' );

// ---------------------------------------------------------------------------------------- resolve
$res = gend_society_plan_checkout_resolve( 21 );
gs109_assert( is_array( $res ) && ( $res['url'] ?? '' ) === $expect, 'launcher-resolve-ok', 'connected, plan 21: change-plan checkout_url resolved', is_wp_error( $res ) ? $res->get_error_code() : wp_json_encode( $res ) );
$posted = array_values(
	array_filter(
		gs109p_hub_requests( gs109p_http() ),
		function ( $r ) {
			return 'POST' === $r['method'] && '/membership/change-plan' === substr( $r['url'], -23 );
		}
	)
);
gs109_assert( 1 === count( $posted ) && 21 === (int) ( json_decode( (string) ( $posted[0]['args']['body'] ?? '' ), true )['plan_id'] ?? 0 ) && 'Bearer ' . $token === gs109p_header( $posted[0]['headers'], 'Authorization' ), 'launcher-change-plan-post', 'one server-side POST membership/change-plan {plan_id 21} with the bearer header' );

foreach ( array( 'https://evil.example/checkout/', 'http://localhost.evil.example/', 'http://evil.example@localhost.evil.example:8919/', 'javascript:alert(1)', '//evil.example/x', 'http://localhost:8920/', '' ) as $bad ) {
	$off = gs109p_inject( 'membership/change-plan', 200, array( 'checkout_url' => $bad ) );
	$r   = gend_society_plan_checkout_resolve( 21 );
	$off();
	gs109_assert( is_wp_error( $r ), 'launcher-bad-host-' . md5( $bad ), 'change-plan answering "' . $bad . '" is refused', is_wp_error( $r ) ? '' : wp_json_encode( $r ) );
}
$off = gs109p_inject( 'membership/change-plan', 409, array( 'code' => 'plan_not_allowed' ) );
$r   = gend_society_plan_checkout_resolve( 21 );
$off();
gs109_assert( is_wp_error( $r ), 'launcher-hub-409', 'change-plan 409 is an error (no redirect)' );
$r = gend_society_plan_checkout_resolve( 999 );
gs109_assert( is_wp_error( $r ), 'launcher-unknown-plan', 'unknown plan id 999 refused locally' );

// ---------------------------------------------------------------------------------------- handler in-process
wp_set_current_user( $admin );
$r = gs109p_call( $handler, 'GET', array( 'action' => 'gend_society_plan_checkout', 'plan' => '21', '_wpnonce' => wp_create_nonce( 'gs_plan_checkout' ) ) );
gs109_assert( 'redirect' === $r['kind'] && $expect === $r['location'], 'launcher-handler-redirect', 'handler: wp_safe_redirect to the hub checkout URL (allowed_redirect_hosts = hub host)', wp_json_encode( $r ) );
gs109_assert( $hubhost === wp_parse_url( $r['location'], PHP_URL_HOST ) && false === strpos( $r['location'], $token ), 'launcher-handler-host', 'Location host is the hub host and holds no token' );

// A hub on another host than the site: wp_safe_redirect only reaches it through the hub-host allow-list.
$prev_base = get_option( 'gend_society_gend_base_url' );
update_option( 'gs109_fixture_hub', 'http://localhost:8919,http://hub.gs109.test', false );
update_option( 'gend_society_gend_base_url', 'http://hub.gs109.test', false );
gs109p_clear_caches();
$alias = 'integration' === gs109_hub_mode() ? gs109p_alias_host( 'http://hub.gs109.test', 'http://localhost:8919' ) : null;
$off = gs109p_inject( 'membership/change-plan', 200, array( 'checkout_url' => 'http://hub.gs109.test/checkout/?gdc_wc=1' ) );
$r   = gs109p_call( $handler, 'GET', array( 'action' => 'gend_society_plan_checkout', 'plan' => '21', '_wpnonce' => wp_create_nonce( 'gs_plan_checkout' ) ) );
$off();
gs109_assert( 'redirect' === $r['kind'] && 'http://hub.gs109.test/checkout/?gdc_wc=1' === $r['location'], 'launcher-allowed-hosts', 'hub on a foreign host: wp_safe_redirect reaches it (allowed_redirect_hosts = hub host)', wp_json_encode( $r ) );
gs109_assert( ! in_array( 'hub.gs109.test', (array) apply_filters( 'allowed_redirect_hosts', array(), 'hub.gs109.test' ), true ), 'launcher-allowed-hosts-one-shot', 'the hub host is not left in allowed_redirect_hosts afterwards' );
$off = gs109p_inject( 'membership/change-plan', 200, array( 'checkout_url' => 'http://localhost:8919/checkout/' ) );
$r   = gs109p_call( $handler, 'GET', array( 'action' => 'gend_society_plan_checkout', 'plan' => '21', '_wpnonce' => wp_create_nonce( 'gs_plan_checkout' ) ) );
$off();
gs109_assert( 'redirect' !== $r['kind'], 'launcher-other-host-refused', 'checkout URL on the site host (not the hub host) is refused', wp_json_encode( array( $r['kind'], $r['location'] ) ) );
update_option( 'gend_society_gend_base_url', $prev_base, false );
update_option( 'gs109_fixture_hub', 'http://localhost:8919', false );
if ( $alias ) {
	$alias();
}
gs109p_clear_caches();

$r = gs109p_call( $handler, 'GET', array( 'action' => 'gend_society_plan_checkout', 'plan' => '21', '_wpnonce' => 'bad' ) );
gs109_assert( 'die' === $r['kind'] && 403 === $r['status'], 'launcher-handler-bad-nonce', 'invalid nonce: wp_die 403', wp_json_encode( $r ) );
$sub = gs109p_user( 'subscriber' );
wp_set_current_user( $sub );
$r = gs109p_call( $handler, 'GET', array( 'action' => 'gend_society_plan_checkout', 'plan' => '21', '_wpnonce' => wp_create_nonce( 'gs_plan_checkout' ) ) );
gs109_assert( 'die' === $r['kind'] && 403 === $r['status'], 'launcher-handler-subscriber', 'subscriber: 403', wp_json_encode( $r ) );
wp_set_current_user( $admin );

// --------------------------------------------------------------------------------- scenarios not allowed
gs109p_scenario( 'store-owner' );
$expect23 = (string) gs109_fixture_json( 'store-owner', 'change-plan.json' )['checkout_url'];
$r = gend_society_plan_checkout_resolve( 21 );
gs109_assert( is_wp_error( $r ) && 'plan_not_allowed' === $r->get_error_code(), 'launcher-store-owner-21', 'store-owner: plan 21 (below the current plan) is not checkout_allowed', is_wp_error( $r ) ? $r->get_error_code() : 'resolved' );
$r = gend_society_plan_checkout_resolve( 23 );
gs109_assert( is_array( $r ) && $expect23 === ( $r['url'] ?? '' ), 'launcher-store-owner-23', 'store-owner: plan 23 allowed', is_wp_error( $r ) ? $r->get_error_code() : (string) ( $r['url'] ?? '' ) . ' vs ' . $expect23 );
gs109p_scenario( 'inherited' );
gs109p_http();
$r = gend_society_plan_checkout_resolve( 23 );
$posted = array_filter(
	gs109p_hub_requests( gs109p_http() ),
	function ( $x ) {
		return 'POST' === $x['method'];
	}
);
gs109_assert( is_wp_error( $r ) && ! $posted, 'launcher-inherited', 'inherited: plan 23 refused locally, change-plan never called', is_wp_error( $r ) ? $r->get_error_code() : 'resolved' );

// ---------------------------------------------------------------------------------------- loopback
gs109p_scenario( 'connected' );
$base = admin_url( 'admin-post.php' );
$sa   = gs109p_session( $admin );
$url  = add_query_arg( array( 'action' => 'gend_society_plan_checkout', 'plan' => 21, '_wpnonce' => gs109p_nonce( $sa, 'gs_plan_checkout' ) ), $base );
$w    = gs109p_web( 'GET', $url, $sa );
gs109_assert( in_array( $w['status'], array( 302, 303 ), true ) && $expect === $w['location'], 'launcher-web-302', 'admin-post launcher: 302 to the hub checkout URL', $w['status'] . ' ' . $w['location'] . ' ' . $w['error'] . ' ' . substr( $w['body'], 0, 160 ) );
gs109_assert( $hubhost === wp_parse_url( $w['location'], PHP_URL_HOST ) && 8919 === (int) wp_parse_url( $w['location'], PHP_URL_PORT ), 'launcher-web-host', 'Location host:port is the hub (localhost:8919)' );
gs109_assert( false === strpos( $w['location'] . $w['body'], $token ), 'launcher-web-no-token', 'token absent from Location and body' );

$w = gs109p_web( 'GET', add_query_arg( array( 'action' => 'gend_society_plan_checkout', 'plan' => 21, '_wpnonce' => 'bad' ), $base ), $sa );
gs109_assert( 403 === $w['status'] && '' === $w['location'], 'launcher-web-bad-nonce', 'invalid nonce: 403, no redirect', $w['status'] . ' ' . $w['location'] );
$ss = gs109p_session( $sub );
$w  = gs109p_web( 'GET', add_query_arg( array( 'action' => 'gend_society_plan_checkout', 'plan' => 21, '_wpnonce' => gs109p_nonce( $ss, 'gs_plan_checkout' ) ), $base ), $ss );
gs109_assert( 403 === $w['status'] && '' === $w['location'], 'launcher-web-subscriber', 'subscriber: 403, no redirect', $w['status'] . ' ' . $w['location'] );

gs109_fixture_scenario( 'store-owner' );
gs109p_clear_caches();
$w = gs109p_web( 'GET', add_query_arg( array( 'action' => 'gend_society_plan_checkout', 'plan' => 21, '_wpnonce' => gs109p_nonce( $sa, 'gs_plan_checkout' ) ), $base ), $sa );
gs109_assert( $w['status'] >= 400 && $w['status'] < 500 && '' === $w['location'], 'launcher-web-not-allowed', 'store-owner plan 21: local error page (HTTP ' . $w['status'] . '), no redirect', $w['location'] );
gs109_assert( false !== strpos( $w['body'], 'Close window' ) && false !== strpos( $w['body'], 'gs-plan-launcher.css' ) && ! preg_match( '#<style|<script(?![^>]*\bsrc=)#i', $w['body'] ), 'launcher-web-error-page', 'error page: "Close window", local CSS file, no inline <style>/<script>' );
gs109_assert( false === strpos( $w['body'], $token ), 'launcher-web-error-no-token', 'error page holds no token' );
if ( preg_match_all( '#(?:href|src)=["\']([^"\']+)#i', $w['body'], $m ) ) {
	$remote = array_filter(
		$m[1],
		function ( $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			return $h && strtolower( (string) $h ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
	);
	gs109_assert( ! $remote, 'launcher-web-error-local-assets', 'error page loads only local assets', implode( ' ', $remote ) );
}

$log = gs109p_httplog_since( $logmark );
gs109_assert( '' !== $log && false === strpos( $log, $token ), 'launcher-log-no-token', 'gs109-http.log never contains the token (' . substr_count( $log, "\n" ) . ' new lines)' );
