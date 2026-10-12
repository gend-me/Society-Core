<?php
/**
 * Section return (plan 108-08, CON-02 / CON-07): full round trip against the hub clone
 * (start -> test approve route on the clone [challenge -> provision -> code] -> return -> complete),
 * then every tamper / replay / user / expiry / cancel case, and abort-while-connected restoring
 * the pre-click consent.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_connect.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_assert( ! function_exists( 'gend_society_connect_handle_return' ), 'return-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108c_require() ) {
	return;
}
if ( ! gs108_assert( '' !== gs108c_secret(), 'return-approve-secret', 'wp-content/gs108-approve.key present (runner copies it from hub/fixtures.txt)' ) ) {
	return;
}

$admin = gs108c_admin_id();
gs108_http_record();
$iid = gend_society_connect_identity()['install_id'];
gs108_on_cleanup(
	function () use ( $iid ) {
		gs108_info( 'hub cleanup ' . $iid . ': ' . gs108c_hub_cleanup( $iid ) );
	},
	'hub cleanup'
);

/** Start + approve on the hub; returns [start result, code, state, approve status, approve data]. */
$round = function ( $reconnect = false ) use ( $admin ) {
	$r = gs108c_start( $admin, $reconnect );
	if ( 'redirect' !== $r['kind'] || '' === $r['state'] ) {
		return array( $r, '', '', 0, null );
	}
	list( $st, $data ) = gs108c_approve( $r['request'], $r['state'] );
	$q = is_array( $data ) && isset( $data['redirect'] ) ? gs108c_query( $data['redirect'] ) : array();
	return array( $r, (string) ( $q['code'] ?? '' ), (string) ( $q['state'] ?? '' ), $st, $data );
};
$no_token = function () {
	return '' === (string) gs108c_opt( 'gend_society_install_token', '' ) && null === gs108c_opt( 'gend_society_connection', null );
};
$refused = function ( $id, $text, $res ) use ( $no_token ) {
	gs108_assert( 'redirect' === $res['kind'] && 'error' === ( gs108c_query( $res['location'] )['gs_connect_status'] ?? '' ), "{$id}-error", "{$text}: error notice", wp_json_encode( $res ) );
	gs108_assert( array() === gs108c_hub_calls( '/connect/complete' ), "{$id}-no-complete", "{$text}: no /connect/complete request" );
	gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), "{$id}-pending-gone", "{$text}: pending gone" );
	gs108_assert( null === gs108c_opt( 'gend_society_consent', null ) && null === gs108c_opt( 'gend_society_remote_consent', null ), "{$id}-consent-cleared", "{$text}: consent cleared" );
	gs108_assert( $no_token(), "{$id}-no-token", "{$text}: 0 tokens stored" );
};

// --- 1. Negative cases first (not connected). Each has a fresh start and a REAL, valid code from the hub,
//        so a guard that failed open would connect.
$other = gs108c_other_admin();
$sub   = gs108c_subscriber();
$cases = array(
	'tampered-state' => function ( $code, $state ) use ( $admin ) {
		$s = $state;
		$s[0] = 'a' === $s[0] ? 'b' : 'a';
		return gs108c_return( $admin, array( 'code' => $code, 'state' => $s ) );
	},
	'missing-state'  => function ( $code, $state ) use ( $admin ) {
		return gs108c_return( $admin, array( 'code' => $code ) );
	},
	'other-admin'    => function ( $code, $state ) use ( $other ) {
		return gs108c_return( $other, array( 'code' => $code, 'state' => $state ) );
	},
	'expired'        => function ( $code, $state ) use ( $admin ) {
		$p            = gs108c_opt( 'gend_society_connect_pending', array() );
		$p['expires'] = time() - 1;
		update_option( 'gend_society_connect_pending', $p, false );
		return gs108c_return( $admin, array( 'code' => $code, 'state' => $state ) );
	},
	'bad-code'       => function ( $code, $state ) use ( $admin ) {
		return gs108c_return( $admin, array( 'code' => 'XYZ', 'state' => $state ) );
	},
	'hub-changed'    => function ( $code, $state ) use ( $admin ) {
		$p        = gs108c_opt( 'gend_society_connect_pending', array() );
		$p['hub'] = 'http://evil.example';
		update_option( 'gend_society_connect_pending', $p, false );
		return gs108c_return( $admin, array( 'code' => $code, 'state' => $state ) );
	},
);
foreach ( $cases as $name => $fn ) {
	gs108c_fresh();
	list( $r, $code, $state, $st ) = $round();
	if ( ! gs108_assert( 200 === $st && 64 === strlen( $code ) && $state === $r['state'], "return-{$name}-approved", 'hub approved (code + same state)', 'approve HTTP ' . $st ) ) {
		continue;
	}
	$GLOBALS['gs108_uat']['http'] = array();
	$refused( "return-{$name}", $name, $fn( $code, $state ) );
}

// Cancel on gend.me (valid state, error=access_denied), not connected.
gs108c_fresh();
$r                            = gs108c_start( $admin );
$GLOBALS['gs108_uat']['http'] = array();
$rc                           = gs108c_return(
	$admin,
	array(
		'error' => 'access_denied',
		'state' => $r['state'],
	)
);
$refused( 'return-cancel', 'cancel', $rc );
gs108_assert( false !== strpos( rawurldecode( (string) ( gs108c_query( $rc['location'] )['gs_connect_message'] ?? '' ) ), 'Connection cancelled on gend.me.' ), 'return-cancel-message', 'message: Connection cancelled on gend.me.' );

// A subscriber cannot touch the pending record (403, nothing changed).
gs108c_fresh();
$r      = gs108c_start( $admin );
$before = gs108c_raw( 'gend_society_connect_pending' );
$rs     = gs108c_return(
	$sub,
	array(
		'error' => 'access_denied',
		'state' => $r['state'],
	)
);
gs108_assert( 'die' === $rs['kind'] && 403 === $rs['status'], 'return-subscriber-forbidden', 'subscriber -> 403' );
gs108_assert( null !== $before && $before === gs108c_raw( 'gend_society_connect_pending' ) && null !== gs108c_opt( 'gend_society_consent', null ), 'return-subscriber-nothing', 'pending + consent untouched by a subscriber' );

// Lazy expiry on admin load (not connected): pending abandoned, consent cleared.
$p            = gs108c_opt( 'gend_society_connect_pending', array() );
$p['expires'] = time() - 1;
update_option( 'gend_society_connect_pending', $p, false );
wp_set_current_user( $admin );
gend_society_connect_expire_pending();
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ) && null === gs108c_opt( 'gend_society_consent', null ), 'return-lazy-expiry', 'expired pending cleared on admin_init, consent withdrawn' );

// --- 2. The full round trip.
gs108c_fresh();
list( $r, $code, $state, $st, $data ) = $round();
gs108_assert( 200 === $st && 64 === strlen( $code ), 'return-approve', 'hub approve (challenge to this site, provision, code) -> 200', wp_json_encode( $data ) );
$ru = wp_parse_url( is_array( $data ) ? (string) ( $data['redirect'] ?? '' ) : '' );
gs108_assert_eq( 'localhost:8908/wp-admin/admin-post.php', ( $ru['host'] ?? '' ) . ':' . ( $ru['port'] ?? '' ) . ( $ru['path'] ?? '' ), 'return-redirect-target', 'hub sends the browser to this site\'s admin-post.php' );

$GLOBALS['gs108_uat']['http'] = array();
$rok = gs108c_return(
	$admin,
	array(
		'code'  => $code,
		'state' => $state,
		'hub'   => 'http://evil.example',
	)
);
$conn = gs108c_opt( 'gend_society_connection', array() );
$tok  = (string) gs108c_opt( 'gend_society_install_token', '' );
gs108_assert( 'redirect' === $rok['kind'] && 'success' === ( gs108c_query( $rok['location'] )['gs_connect_status'] ?? '' ), 'return-success', 'success notice', wp_json_encode( $rok ) );
gs108_assert( false !== strpos( rawurldecode( (string) ( gs108c_query( $rok['location'] )['gs_connect_message'] ?? '' ) ), 'Connected to gend.me as part of' ), 'return-success-message', 'message names the group' );
$calls = gs108c_hub_calls();
gs108_assert( 1 === count( $calls ) && 0 === strpos( $calls[0], 'POST ' . gs108c_hub() . '/wp-json/gdc-app-manager/v1/connect/complete' ), 'return-complete-call', 'one POST to the configured hub /connect/complete (hub= in the query ignored)', implode( ' | ', $calls ) );
gs108_assert( 64 === strlen( $tok ), 'return-token', 'install token stored' );
gs108_assert_eq( $iid, (string) gs108c_opt( 'gend_society_install_id', '' ), 'return-install-id', 'install id kept' );
gs108_assert_eq( gs108c_hub(), (string) gs108c_opt( 'gend_society_gend_base_url', '' ), 'return-base-url', 'base url = configured hub' );
$gpk = base64_decode( (string) gs108c_opt( 'gend_society_gend_pubkey', '' ), true );
gs108_assert( is_string( $gpk ) && 32 === strlen( $gpk ), 'return-gend-pubkey', 'gend signing pubkey stored' );
gs108_assert( (int) gs108c_opt( 'gend_society_connected_at', 0 ) > 0, 'return-connected-at', 'connected_at stored' );
gs108_assert( is_array( $conn ) && false !== stripos( (string) ( $conn['group']['name'] ?? '' ), 'Alpha' ) && 3 === (int) ( $conn['group']['id'] ?? 0 ), 'return-connection-group', 'connection group = GS108 Alpha (3)', wp_json_encode( $conn ) );
gs108_assert( is_array( $conn ) && $admin === (int) ( $conn['owner_user_id'] ?? 0 ) && 'redirect' === ( $conn['via'] ?? '' ) && gs108c_hub() === ( $conn['hub'] ?? '' ), 'return-connection-owner', 'owner_user_id = admin, via redirect, hub' );
gs108_assert( is_array( $conn ) && (int) ( $conn['approved_by']['id'] ?? 0 ) > 0 && '' !== (string) ( $conn['approved_by']['display_name'] ?? '' ), 'return-connection-approver', 'approved_by stored' );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'return-pending-gone', 'pending deleted' );
$cons = gs108c_opt( 'gend_society_consent', array() );
gs108_assert( is_array( $cons ) && '108.1' === ( $cons['version'] ?? '' ), 'return-consent-kept', 'consent 108.1 kept after a successful connect' );

$h = wp_remote_get(
	gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . $iid . '/health',
	array(
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $tok ),
	)
);
$hb = is_wp_error( $h ) ? null : json_decode( (string) wp_remote_retrieve_body( $h ), true );
gs108_assert( ! is_wp_error( $h ) && 200 === (int) wp_remote_retrieve_response_code( $h ) && is_array( $hb ) && 'connected' === ( $hb['state'] ?? '' ), 'return-health', '/install/{id}/health with the stored token -> 200 connected', is_wp_error( $h ) ? $h->get_error_message() : wp_remote_retrieve_body( $h ) );

// --- 3. Connected: replayed code during a reconnect -> hub 403, everything as before, consent restored.
$snap = array(
	'token'   => gs108c_raw( 'gend_society_install_token' ),
	'conn'    => gs108c_raw( 'gend_society_connection' ),
	'consent' => gs108c_raw( 'gend_society_consent' ),
	'remote'  => gs108c_raw( 'gend_society_remote_consent' ),
);
$r2 = gs108c_start( $admin, true );
gs108_assert( 'reconnect' === ( gs108c_opt( 'gend_society_consent', array() )['source'] ?? '' ), 'return-reconnect-consent', 'reconnect click recorded consent (source reconnect)' );
$GLOBALS['gs108_uat']['http'] = array();
$rp                           = gs108c_return(
	$admin,
	array(
		'code'  => $code,
		'state' => $r2['state'],
	)
);
gs108_assert( 1 === count( gs108c_hub_calls( '/connect/complete' ) ), 'return-replay-sent', 'replayed code reached the hub' );
gs108_assert( 'redirect' === $rp['kind'] && false !== strpos( rawurldecode( (string) ( gs108c_query( $rp['location'] )['gs_connect_message'] ?? '' ) ), 'HTTP 403' ), 'return-replay-403', 'hub answered 403 (invalid_code)', wp_json_encode( $rp ) );
gs108_assert( $snap['token'] === gs108c_raw( 'gend_society_install_token' ) && $snap['conn'] === gs108c_raw( 'gend_society_connection' ), 'return-replay-unchanged', 'token + connection unchanged' );
gs108_assert( $snap['consent'] === gs108c_raw( 'gend_society_consent' ) && $snap['remote'] === gs108c_raw( 'gend_society_remote_consent' ), 'return-replay-consent', 'complete_failed while connected: consent equals the pre-click record byte-for-byte' );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'return-replay-pending', 'pending gone' );

// Connected: cancel on gend.me during a reconnect -> consent restored, never cleared.
$r3 = gs108c_start( $admin, true );
$rc = gs108c_return(
	$admin,
	array(
		'error' => 'access_denied',
		'state' => $r3['state'],
	)
);
gs108_assert( 'redirect' === $rc['kind'], 'return-reconnect-cancel', 'cancelled reconnect -> back to the Connect screen' );
gs108_assert( $snap['consent'] === gs108c_raw( 'gend_society_consent' ) && $snap['remote'] === gs108c_raw( 'gend_society_remote_consent' ), 'return-reconnect-cancel-consent', 'cancelled reconnect: consent equals the pre-click record byte-for-byte' );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ) && $snap['token'] === gs108c_raw( 'gend_society_install_token' ), 'return-reconnect-cancel-state', 'pending gone, token kept' );

// Connected: tampered state during a reconnect -> consent restored (not cleared).
$r4 = gs108c_start( $admin, true );
$s  = $r4['state'];
$s[5] = 'a' === $s[5] ? 'b' : 'a';
gs108c_return(
	$admin,
	array(
		'error' => 'access_denied',
		'state' => $s,
	)
);
gs108_assert( $snap['consent'] === gs108c_raw( 'gend_society_consent' ) && null !== gs108c_raw( 'gend_society_consent' ), 'return-reconnect-badstate-consent', 'bad state while connected: consent restored, never cleared' );

// Connected, simulated prior consent (plan case): fake prior record + complete_failed (hub down).
$fake_prior = array(
	'at'             => 1700000001,
	'user_id'        => $admin,
	'version'        => '106.1',
	'plugin_version' => '1.3.1',
	'source'         => 'pairing-code',
);
update_option( 'gend_society_consent', $fake_prior, false );
$prior_raw = gs108c_raw( 'gend_society_consent' );
$r5        = gs108c_start( $admin, true );
$down      = function ( $pre, $args, $url ) {
	return false !== strpos( (string) $url, '/connect/complete' ) ? new WP_Error( 'http_request_failed', 'gs108 simulated outage' ) : $pre;
};
add_filter( 'pre_http_request', $down, 5, 3 );
gs108c_return(
	$admin,
	array(
		'code'  => str_repeat( 'd', 64 ),
		'state' => $r5['state'],
	)
);
remove_filter( 'pre_http_request', $down, 5 );
gs108_assert( $prior_raw === gs108c_raw( 'gend_society_consent' ), 'return-connected-outage-consent', 'complete_failed (outage) while connected: prior consent restored byte-for-byte' );
gs108_assert( $snap['token'] === gs108c_raw( 'gend_society_install_token' ), 'return-connected-outage-token', 'token kept' );
