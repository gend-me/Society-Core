<?php
/**
 * Section challenge (plan 108-08, U11): POST /wp-json/gs/v1/connect/challenge over real HTTP to the bed.
 * Signs only while a matching, unexpired pending request exists; otherwise 404.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_connect.php';

if ( 'standalone' !== gs108_mode() ) {
	$res = rest_do_request( new WP_REST_Request( 'POST', '/gs/v1/connect/challenge' ) );
	gs108_assert_eq( 404, $res->get_status(), 'challenge-absent', 'no gs/v1/connect/challenge route on ' . gs108_mode() );
	return;
}
if ( ! gs108c_require() ) {
	return;
}

$admin = gs108c_admin_id();
$url   = gs108c_site() . '/wp-json/gs/v1/connect/challenge';

$post = function ( array $body ) use ( $url ) {
	$res = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 0, array( 'error' => $res->get_error_message() ) );
	}
	return array( (int) wp_remote_retrieve_response_code( $res ), json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
};
$verify = function ( $sig, $request_id, $challenge ) {
	$kp  = base64_decode( (string) get_option( 'gend_society_keypair', '' ), true );
	$raw = base64_decode( (string) $sig, true );
	if ( ! is_string( $kp ) || ! is_string( $raw ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw ) ) {
		return false;
	}
	return sodium_crypto_sign_verify_detached( $raw, 'gend-society-challenge|' . $request_id . '|' . $challenge . '|' . gs108c_site() . '/', sodium_crypto_sign_publickey( $kp ) );
};

// --- 1. Redirect pending (real start against the hub clone).
$r   = gs108c_start( $admin );
$p   = gs108c_opt( 'gend_society_connect_pending', array() );
$rid = (string) ( $p['request_id'] ?? '' );
gs108_assert( 'redirect' === $r['kind'] && 64 === strlen( $rid ), 'challenge-pending', 'start saved a pending request' );

$ch        = base64_encode( random_bytes( 32 ) );
list( $code, $data ) = $post(
	array(
		'request_id' => $rid,
		'challenge'  => $ch,
	)
);
gs108_assert_eq( 200, $code, 'challenge-ok-status', 'matching request_id -> 200' );
gs108_assert( is_array( $data ) && $verify( $data['sig'] ?? '', $rid, $ch ), 'challenge-ok-sig', 'signature verifies with the install pubkey over gend-society-challenge|request_id|challenge|' . gs108c_site() . '/' );
gs108_assert( is_array( $data ) && array( 'sig' ) === array_keys( $data ), 'challenge-ok-shape', 'answer is {sig} only' );

list( $code ) = $post(
	array(
		'request_id' => str_repeat( 'c', 64 ),
		'challenge'  => $ch,
	)
);
gs108_assert_eq( 404, $code, 'challenge-wrong-id', 'wrong request_id -> 404' );

list( $code, $data ) = $post(
	array(
		'request_id' => $rid,
		'challenge'  => 'abc|def|ghijklmnopqrstuvwxyz',
	)
);
gs108_assert_eq( 404, $code, 'challenge-bad-challenge', 'challenge with separators -> 404 (never signs arbitrary text)' );
gs108_assert_eq( 'no_pending', is_array( $data ) ? ( $data['code'] ?? '' ) : '', 'challenge-404-code', '404 body code no_pending' );

list( $code ) = $post( array( 'request_id' => $rid ) );
gs108_assert_eq( 404, $code, 'challenge-missing', 'missing challenge -> 404' );

// --- 2. Expired pending -> 404.
$p['expires'] = time() - 1;
update_option( 'gend_society_connect_pending', $p, false );
list( $code ) = $post(
	array(
		'request_id' => $rid,
		'challenge'  => $ch,
	)
);
gs108_assert_eq( 404, $code, 'challenge-expired', 'expired pending -> 404' );

// --- 3. No pending -> 404.
delete_option( 'gend_society_connect_pending' );
list( $code ) = $post(
	array(
		'request_id' => $rid,
		'challenge'  => $ch,
	)
);
gs108_assert_eq( 404, $code, 'challenge-none', 'no pending -> 404' );

// --- 4. Pairing pending (plan 09 calls gend_society_connect_pairing_prepare before its POST).
wp_set_current_user( $admin );
gend_society_connect_pairing_prepare( 'ABCD2345' );
$pp   = gs108c_opt( 'gend_society_connect_pending', array() );
$prid = hash( 'sha256', 'pair|ABCD2345|' . (string) get_option( 'gend_society_install_id' ) );
gs108_assert( $prid === ( $pp['request_id'] ?? '' ) && 'pairing' === ( $pp['via'] ?? '' ) && (int) ( $pp['expires'] ?? 0 ) <= time() + 300 && (int) ( $pp['expires'] ?? 0 ) >= time() + 290, 'challenge-pairing-pending', 'pairing pending: request_id = sha256(pair|code|install_id), +300 s' );
list( $code, $data ) = $post(
	array(
		'request_id' => $prid,
		'challenge'  => $ch,
	)
);
gs108_assert( 200 === $code && is_array( $data ) && $verify( $data['sig'] ?? '', $prid, $ch ), 'challenge-pairing-ok', 'pairing request_id answered with a valid signature' );
delete_option( 'gend_society_connect_pending' );

// --- 5. Rate limit: 20 per minute.
update_option(
	'gend_society_connect_rate',
	array(
		'window' => (int) floor( time() / 60 ),
		'count'  => 20,
	),
	false
);
list( $code ) = $post(
	array(
		'request_id' => $rid,
		'challenge'  => $ch,
	)
);
$after = gs108c_opt( 'gend_society_connect_rate', array() );
if ( (int) ( $after['count'] ?? 0 ) === 1 ) {
	gs108_info( 'minute rolled over during the rate case; skipped' );
} else {
	gs108_assert_eq( 429, $code, 'challenge-rate', '21st challenge in a minute -> 429' );
}
delete_option( 'gend_society_connect_rate' );

