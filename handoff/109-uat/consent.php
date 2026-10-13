<?php
/**
 * Section consent (plan 109-06; RUN-01 carried into 109): an install that never connected or consented makes
 * no gend.me request when an admin opens GenD > Plan & features.
 *
 * Every gend_society_* option is removed for the duration of the section (snapshot restored on cleanup), so the
 * install looks freshly activated; the page is then fetched over real loopback requests with an admin session
 * and wp-content/gs109-http.log (every outbound WP HTTP request of the web process) must hold no request to
 * the hub clone or gend.me. The page shows the Connect card. (The plan also runs this section on a bed that
 * was just reset with bed_reset; see the 109-06 evidence.)
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_plan_page_render' ), 'consent-absent', 'Plan & features page not loaded on ' . gs109_mode() );
	return;
}

global $wpdb;
gs109_snapshot_options( array( 'gs109_fixture_scenario' ) );
foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%'" ) as $o ) {
	delete_option( $o );
}
delete_option( 'gs109_fixture_scenario' ); // fixture mode off: a request would go to the real hub clone
wp_cache_delete( 'alloptions', 'options' );
gs109_assert( '' === (string) get_option( 'gend_society_install_token', '' ) && ! ( function_exists( 'gend_society_consent_given' ) && gend_society_consent_given() ), 'consent-fresh', 'no pairing, no consent' );

$sess = gs109p_session( gs109p_admin_id() );
$mark = gs109p_httplog_mark();
$page = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-plan' ), $sess );
$gend = gs109p_web( 'GET', admin_url( 'admin.php?page=gend-society' ), $sess );
$log  = gs109p_httplog_since( $mark );

gs109_assert( 200 === $page['status'] && false !== strpos( $page['body'], 'Connect to gend.me to see plans' ), 'consent-connect-card', 'gs-plan: 200 with the Connect card', $page['status'] . ' ' . $page['error'] );
gs109_assert( 200 === $gend['status'], 'consent-gend-page', 'GenD page: 200', $gend['status'] );
gs109_assert( false === strpos( $page['body'], 'data-gs-plan-checkout' ) && false === strpos( $page['body'], 'data-gs-plan-popup' ) && false === strpos( $page['body'], 'gend-society-plan-js' ), 'consent-no-ui', 'no plan cards and no plan script before connecting' );

$hub_lines = array();
foreach ( array_filter( explode( "\n", $log ) ) as $l ) {
	$j = json_decode( $l, true );
	$u = is_array( $j ) ? (string) ( $j['url'] ?? '' ) : $l;
	if ( ( is_array( $j ) && ! empty( $j['hub'] ) ) || false !== stripos( $u, 'localhost:8919' ) || false !== stripos( $u, 'gend.me' ) ) {
		$hub_lines[] = $u;
	}
}
gs109_assert( ! $hub_lines, 'consent-no-hub-request', 'zero requests to the hub / gend.me while visiting the page (' . count( array_filter( explode( "\n", $log ) ) ) . ' outbound requests logged in total)', $hub_lines );
