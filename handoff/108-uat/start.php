<?php
/**
 * Section start (plan 108-08, CON-02): the Connect click against the hub clone.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_connect.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_assert( ! function_exists( 'gend_society_connect_handle_start' ), 'start-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108c_require() ) {
	return;
}

$admin = gs108c_admin_id();
gs108_http_record();

// --- 1. Success.
delete_option( 'gend_society_keypair' );
delete_option( 'gend_society_install_id' );
$t0 = time();
$r  = gs108c_start( $admin );
$u  = wp_parse_url( $r['location'] );
$q  = gs108c_query( $r['location'] );
$p  = gs108c_opt( 'gend_society_connect_pending', array() );
$id = (string) gs108c_opt( 'gend_society_install_id', '' );
$kp = (string) gs108c_opt( 'gend_society_keypair', '' );

gs108_assert( 'redirect' === $r['kind'], 'start-redirect', 'start ends in a redirect', wp_json_encode( $r ) );
gs108_assert_eq( 'localhost:8918', ( $u['host'] ?? '' ) . ':' . ( $u['port'] ?? '' ), 'start-redirect-host', 'redirect host = hub' );
gs108_assert_eq( '/connect/', $u['path'] ?? '', 'start-redirect-path', 'redirect path = /connect/' );
gs108_assert( ! empty( $q['request'] ) && isset( $q['state'] ) && preg_match( '/^[a-f0-9]{64}$/', $q['state'] ), 'start-redirect-args', 'redirect has request + 64-hex state' );
gs108_assert( is_array( $p ) && isset( $p['state_hash'] ) && hash_equals( $p['state_hash'], hash( 'sha256', (string) ( $q['state'] ?? '' ) ) ), 'start-pending-state-hash', 'pending holds sha256(state)' );
gs108_assert( is_array( $p ) && false === strpos( wp_json_encode( $p ), (string) ( $q['state'] ?? 'x' ) ), 'start-pending-no-raw-state', 'raw state is not stored anywhere in pending' );
gs108_assert( false === strpos( (string) gs108c_raw( 'gend_society_connect_pending' ), (string) ( $q['state'] ?? 'x' ) ), 'start-db-no-raw-state', 'raw state not in the DB row' );
gs108_assert_eq( hash( 'sha256', (string) ( $q['request'] ?? '' ) ), $p['request_id'] ?? '', 'start-pending-request-id', 'request_id = sha256(request)' );
gs108_assert_eq( $admin, (int) ( $p['user_id'] ?? 0 ), 'start-pending-user', 'pending user = admin' );
gs108_assert( (int) ( $p['expires'] ?? 0 ) >= $t0 + 1800 && (int) ( $p['expires'] ?? 0 ) <= time() + 1800, 'start-pending-expires', 'expires = now + 1800' );
gs108_assert_eq( 'redirect', $p['via'] ?? '', 'start-pending-via', 'via redirect' );
gs108_assert_eq( gs108c_hub(), $p['hub'] ?? '', 'start-pending-hub', 'hub from configuration' );
gs108_assert_eq(
	array(
		'consent'        => null,
		'remote_consent' => null,
	),
	$p['prior_consent'] ?? 'missing',
	'start-pending-prior',
	'prior_consent = values before the click (none)'
);
gs108_assert( (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id ), 'start-identity-uuid', 'install id is a lowercase UUID v4' );
$kp_raw = base64_decode( $kp, true );
gs108_assert( is_string( $kp_raw ) && SODIUM_CRYPTO_SIGN_KEYPAIRBYTES === strlen( $kp_raw ), 'start-identity-keypair', 'keypair stored' );
gs108_assert_eq( '', (string) gs108c_opt( 'gend_society_install_token', '' ), 'start-no-token', 'no token after start' );

// The request token's payload carries this site's values (hub-signed, decoded without verifying here).
$parts   = explode( '.', (string) ( $q['request'] ?? '' ) );
$payload = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
gs108_assert( is_array( $payload ) && $id === ( $payload['install_id'] ?? '' ) && home_url( '/' ) === ( $payload['install_url'] ?? '' ) && admin_url() === ( $payload['admin_url'] ?? '' ), 'start-payload', 'hub request payload has install_id, install_url, admin_url' );
gs108_assert( is_array( $payload ) && base64_encode( sodium_crypto_sign_publickey( $kp_raw ) ) === ( $payload['society_pubkey'] ?? '' ), 'start-payload-pubkey', 'payload pubkey = install pubkey' );

// Identity is reused, never regenerated.
$r_again = gs108c_start( $admin );
gs108_assert( 'redirect' === $r_again['kind'] && $id === (string) gs108c_opt( 'gend_society_install_id', '' ) && $kp === (string) gs108c_opt( 'gend_society_keypair', '' ), 'start-identity-reused', 'second start reuses install id + keypair' );
gs108_assert( $r_again['state'] !== $r['state'], 'start-state-fresh', 'each start has a fresh state' );

// --- 2. Refusals before anything is sent.
gs108c_fresh();
$GLOBALS['gs108_uat']['http'] = array();
$sub = gs108c_subscriber();
$rs  = gs108c_start( $sub );
gs108_assert( 'die' === $rs['kind'] && 403 === $rs['status'], 'start-nonadmin-forbidden', 'subscriber -> 403', wp_json_encode( $rs ) );
gs108_assert( array() === gs108c_hub_calls() && null === gs108c_opt( 'gend_society_consent', null ), 'start-nonadmin-nothing', 'no request, no consent' );

wp_set_current_user( $admin );
$rn = gs108c_call( 'gend_society_connect_handle_start', 'POST', array(), array( '_wpnonce' => 'bad' ) );
gs108_assert( 'die' === $rn['kind'], 'start-bad-nonce', 'bad nonce refused', wp_json_encode( $rn ) );
gs108_assert( array() === gs108c_hub_calls() && null === gs108c_opt( 'gend_society_consent', null ), 'start-bad-nonce-nothing', 'no request, no consent' );

$rg = gs108c_call( 'gend_society_connect_handle_start', 'GET', array( '_wpnonce' => wp_create_nonce( 'gend_society_connect_start' ) ) );
gs108_assert( 'die' === $rg['kind'] && 405 === $rg['status'] && array() === gs108c_hub_calls(), 'start-get-refused', 'GET start -> 405, no request' );

// --- 3. Hub refuses: stale ts (400) and a corrupted pubkey (403). Pending gone, consent cleared.
$mutate = function ( $how ) {
	return function ( $args, $url ) use ( $how ) {
		if ( false === strpos( (string) $url, '/connect/start' ) ) {
			return $args;
		}
		$b = json_decode( (string) $args['body'], true );
		if ( 'stale' === $how ) {
			$b['ts'] = time() - 3600;
		} else {
			$b['society_pubkey'] = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		}
		$args['body'] = wp_json_encode( $b );
		return $args;
	};
};
foreach ( array( 'stale' => 400, 'pubkey' => 403 ) as $how => $want ) {
	gs108c_fresh();
	$GLOBALS['gs108_uat']['http'] = array();
	$f = $mutate( $how );
	add_filter( 'http_request_args', $f, 20, 2 );
	$rr = gs108c_start( $admin );
	remove_filter( 'http_request_args', $f, 20 );
	$m = (string) ( gs108c_query( $rr['location'] )['gs_connect_message'] ?? '' );
	gs108_assert( 'redirect' === $rr['kind'] && 0 === strpos( $rr['location'], admin_url() ) && 'error' === ( gs108c_query( $rr['location'] )['gs_connect_status'] ?? '' ), "start-hub-{$how}-error", "hub {$want}: back to the Connect screen with an error", wp_json_encode( $rr ) );
	gs108_assert( false !== strpos( rawurldecode( $m ), "HTTP {$want}" ), "start-hub-{$how}-status", "message names HTTP {$want}", rawurldecode( $m ) );
	gs108_assert( 1 === count( gs108c_hub_calls( '/connect/start' ) ), "start-hub-{$how}-sent", 'the start request was sent once' );
	gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), "start-hub-{$how}-no-pending", 'pending gone' );
	gs108_assert( null === gs108c_opt( 'gend_society_consent', null ) && null === gs108c_opt( 'gend_society_remote_consent', null ), "start-hub-{$how}-consent-cleared", 'consent cleared (not connected)' );
}

// --- 4. Hostile / inconsistent hub answers (faked): never redirected off the hub, nothing kept.
$fake = function ( $body ) {
	return function ( $pre, $args, $url ) use ( $body ) {
		if ( false === strpos( (string) $url, '/connect/start' ) ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
};
$tok   = 'eyJ2IjoxfQ.c2ln';
$cases = array(
	'evil-host'     => array(
		'request'       => $tok,
		'request_id'    => hash( 'sha256', $tok ),
		'authorize_url' => 'https://evil.example/connect/?request=' . $tok,
	),
	'evil-port'     => array(
		'request'       => $tok,
		'request_id'    => hash( 'sha256', $tok ),
		'authorize_url' => 'http://localhost:9999/connect/?request=' . $tok,
	),
	'id-mismatch'   => array(
		'request'       => $tok,
		'request_id'    => str_repeat( 'b', 64 ),
		'authorize_url' => gs108c_hub() . '/connect/?request=' . $tok,
	),
	'missing-field' => array( 'authorize_url' => gs108c_hub() . '/connect/' ),
);
foreach ( $cases as $name => $body ) {
	gs108c_fresh();
	$f = $fake( $body );
	add_filter( 'pre_http_request', $f, 5, 3 );
	$rr = gs108c_start( $admin );
	remove_filter( 'pre_http_request', $f, 5 );
	gs108_assert( 'redirect' === $rr['kind'] && 0 === strpos( $rr['location'], admin_url() ), "start-fake-{$name}-stays", 'redirect stays on this site', $rr['location'] );
	gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ) && null === gs108c_opt( 'gend_society_consent', null ), "start-fake-{$name}-aborted", 'pending gone, consent cleared' );
}

// wp_safe_redirect itself only allows the configured hub host (defence in depth).
gs108_assert_eq( admin_url(), wp_validate_redirect( 'https://evil.example/x', admin_url() ), 'start-safe-redirect-evil', 'unknown host falls back to admin_url()' );

// --- 5. Reconnect that fails at /connect/start (before pending exists): pre-click consent restored byte-for-byte.
gs108c_fresh();
update_option( 'gend_society_install_token', 'gs108-fake-token', false );
update_option(
	'gend_society_consent',
	array(
		'at'             => 1700000000,
		'user_id'        => $admin,
		'version'        => '106.1',
		'plugin_version' => '1.3.1',
		'source'         => 'pairing-code',
	),
	false
);
update_option( 'gend_society_remote_consent', 1, false );
$before_c = gs108c_raw( 'gend_society_consent' );
$before_r = gs108c_raw( 'gend_society_remote_consent' );
$f        = $mutate( 'stale' );
add_filter( 'http_request_args', $f, 20, 2 );
$rr = gs108c_start( $admin, true );
remove_filter( 'http_request_args', $f, 20 );
gs108_assert( 'redirect' === $rr['kind'] && 'error' === ( gs108c_query( $rr['location'] )['gs_connect_status'] ?? '' ), 'start-reconnect-fail-error', 'failed reconnect -> error notice' );
gs108_assert( null !== $before_c && $before_c === gs108c_raw( 'gend_society_consent' ), 'start-reconnect-fail-consent', 'consent record equals the pre-click record byte-for-byte' );
gs108_assert( $before_r === gs108c_raw( 'gend_society_remote_consent' ), 'start-reconnect-fail-remote', 'remote consent flag unchanged' );
gs108_assert( 'gs108-fake-token' === (string) gs108c_opt( 'gend_society_install_token', '' ) && array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'start-reconnect-fail-token', 'token kept, pending gone' );

// Network error during a reconnect: same restore.
$f = function ( $pre, $args, $url ) {
	return false !== strpos( (string) $url, '/connect/start' ) ? new WP_Error( 'http_request_failed', 'gs108 simulated outage' ) : $pre;
};
add_filter( 'pre_http_request', $f, 5, 3 );
$rr = gs108c_start( $admin, true );
remove_filter( 'pre_http_request', $f, 5 );
gs108_assert( $before_c === gs108c_raw( 'gend_society_consent' ) && false !== strpos( rawurldecode( (string) ( gs108c_query( $rr['location'] )['gs_connect_message'] ?? '' ) ), 'Could not reach gend.me' ), 'start-reconnect-outage', 'outage during reconnect: consent restored, readable error' );
