<?php
/**
 * Section consent (plan 108-08, CON-07): nothing reaches the hub before Connect; the Connect click
 * records consent 108.1 (source redirect / reconnect) BEFORE the first hub request; disclosure has
 * the two new items.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_connect.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_info( 'consent: standalone-only section; checking the module is absent' );
	gs108_assert( ! function_exists( 'gend_society_connect_handle_start' ), 'consent-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108c_require() ) {
	return;
}

$admin = gs108c_admin_id();

// --- 1. Version + disclosure.
gs108_assert_eq( '108.1', GEND_SOCIETY_CONSENT_VERSION, 'consent-version', 'consent text version' );
$items = gend_society_consent_disclosure_items();
gs108_assert_eq( array( 'site_url', 'install_id', 'public_key', 'admin_email', 'gend_account', 'owner_name' ), array_keys( $items ), 'consent-items-order', 'existing items keep their order, two new items at the end' );
gs108_assert( false !== strpos( (string) ( $items['gend_account'] ?? '' ), 'business group you choose are linked to this site on gend.me' ), 'consent-item-account', 'gend.me account + business group item' );
gs108_assert( false !== strpos( (string) ( $items['owner_name'] ?? '' ), "display name of this site's connection owner" ), 'consent-item-owner', 'connection owner display name item' );

// --- 2. Nothing goes to the hub before Connect.
gs108_http_record();
$log_file  = WP_CONTENT_DIR . '/gs108-http.log';
$log_lines = is_readable( $log_file ) ? count( file( $log_file ) ) : 0;
wp_set_current_user( $admin );
gend_society_connect_expire_pending();
if ( function_exists( 'gend_society_portal_connect_modal_html' ) ) { // 109-30: the Connect UI is the dashboard popup
	gend_society_portal_connect_modal_html( 'slot' );
}
$req = new WP_REST_Request( 'POST', '/gs/v1/connect/challenge' );
$req->set_body_params(
	array(
		'request_id' => str_repeat( 'a', 64 ),
		'challenge'  => base64_encode( random_bytes( 32 ) ),
	)
);
$res = rest_do_request( $req );
gs108_assert_eq( 404, $res->get_status(), 'consent-challenge-idle', 'challenge without a pending connect -> 404' );
gs108_assert_eq( array(), gs108c_hub_calls(), 'consent-no-hub-before', '0 requests to the hub before Connect' );
gs108_assert( null === gs108c_opt( 'gend_society_consent', null ), 'consent-none-before', 'no consent record before Connect' );
gs108_assert( ! gend_society_remote_allowed( 'features' ), 'consent-gate-closed', 'remote gate closed before Connect' );

// --- 3. Connect: consent written before the first hub request.
$GLOBALS['gs108_uat']['http'] = array();
$marker = function () {
	$GLOBALS['gs108_uat']['http'][] = 'CONSENT-WRITTEN';
};
add_action( 'add_option_gend_society_consent', $marker );
add_action( 'update_option_gend_society_consent', $marker );
$t0 = time();
$r  = gs108c_start( $admin );
remove_action( 'add_option_gend_society_consent', $marker );
remove_action( 'update_option_gend_society_consent', $marker );

$seq       = gs108_http_log();
$first_hub = null;
foreach ( $seq as $i => $l ) {
	if ( false !== strpos( $l, gs108c_hub() ) ) {
		$first_hub = $i;
		break;
	}
}
$consent_i = array_search( 'CONSENT-WRITTEN', $seq, true );
gs108_assert( 'redirect' === $r['kind'] && 0 === strpos( $r['location'], gs108c_hub() . '/connect/' ), 'consent-start-redirects', 'Connect redirects to the hub authorize page', wp_json_encode( $r ) );
gs108_assert( false !== $consent_i && null !== $first_hub && $consent_i < $first_hub, 'consent-before-request', 'consent written (#' . wp_json_encode( $consent_i ) . ') before the first hub request (#' . wp_json_encode( $first_hub ) . ')', implode( ' | ', $seq ) );
gs108_assert( 1 === count( gs108c_hub_calls() ) && false !== strpos( gs108c_hub_calls()[0], 'POST ' . gs108c_hub() . '/wp-json/gdc-app-manager/v1/connect/start' ), 'consent-one-start-call', 'exactly one hub call: POST /connect/start', implode( ' | ', gs108c_hub_calls() ) );

$c = gs108c_opt( 'gend_society_consent', array() );
gs108_assert_eq( '108.1', is_array( $c ) ? ( $c['version'] ?? '' ) : '', 'consent-record-version', 'record version 108.1' );
gs108_assert_eq( 'redirect', is_array( $c ) ? ( $c['source'] ?? '' ) : '', 'consent-record-source', 'record source redirect' );
gs108_assert_eq( $admin, is_array( $c ) ? (int) ( $c['user_id'] ?? 0 ) : 0, 'consent-record-user', 'record user = admin' );
gs108_assert( '1' === (string) gs108c_opt( 'gend_society_remote_consent', '' ), 'consent-remote-flag', 'remote consent flag set' );

// File log (gs108-http-logger): the first hub line after this point is not earlier than the consent time.
$lines    = is_readable( $log_file ) ? array_slice( file( $log_file ), $log_lines ) : array();
$first_ts = null;
foreach ( $lines as $line ) {
	$j = json_decode( $line, true );
	if ( is_array( $j ) && ! empty( $j['hub'] ) ) {
		$first_ts = (float) $j['t'];
		break;
	}
}
gs108_assert( null !== $first_ts && is_array( $c ) && (int) $c['at'] <= (int) floor( $first_ts ) && (int) $c['at'] >= $t0, 'consent-at-vs-log', 'consent at=' . ( is_array( $c ) ? $c['at'] : '?' ) . ' <= first logged hub request t=' . wp_json_encode( $first_ts ) );

// --- 4. Reconnect click records source reconnect.
update_option( 'gend_society_install_token', 'gs108-fake-token', false );
$r2 = gs108c_start( $admin, true );
$c2 = gs108c_opt( 'gend_society_consent', array() );
gs108_assert_eq( 'reconnect', is_array( $c2 ) ? ( $c2['source'] ?? '' ) : '', 'consent-reconnect-source', 'Reconnect click records source reconnect' );
gs108_assert( 'redirect' === $r2['kind'] && '' !== $r2['state'], 'consent-reconnect-redirects', 'reconnect start redirects to the hub' );
