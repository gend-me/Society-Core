<?php
/**
 * Section disconnect (plan 108-09, CON-05 / U7): revoke-first Disconnect against the hub clone.
 * The hub request is made while the token is still stored (checked inside the request hook);
 * the old token then gets 401; install_id + keypair stay; consent goes. Hub unreachable or 5xx ->
 * nothing deleted, "Disconnect locally anyway" offered and works. Hub already revoked (401) ->
 * local deletion proceeds. Reconnecting after a disconnect reactivates the same hub record.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_pair.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_info( 'disconnect: standalone-only section; hub/container disconnect is covered by the output section' );
	gs108_assert( ! function_exists( 'gend_society_connect_pairing_prepare' ), 'disconnect-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108p_require() ) {
	return;
}

$admin = gs108c_admin_id();
gs108_http_record();
$iid     = gend_society_connect_identity()['install_id'];
$keypair = gs108c_raw( 'gend_society_keypair' );
$gone    = array( 'gend_society_install_token', 'gend_society_gend_base_url', 'gend_society_gend_pubkey', 'gend_society_connected_at', 'gend_society_connection', 'gend_society_connection_health', 'gend_society_features_cache', 'gend_society_features_cache_expires', 'gend_society_remote_membership_cache', 'gend_society_remote_membership_cache_expires', 'gend_society_consent', 'gend_society_remote_consent' );
$all_gone = function () use ( $gone ) {
	$left = array();
	foreach ( $gone as $o ) {
		if ( null !== gs108c_raw( $o ) ) {
			$left[] = $o;
		}
	}
	return $left;
};
$kept = function () use ( $iid, $keypair ) {
	return $iid === gs108c_raw( 'gend_society_install_id' ) && null !== $keypair && $keypair === gs108c_raw( 'gend_society_keypair' );
};
$disconnect = function () use ( $admin ) {
	return gs108p_op( $admin, 'disconnect', 'gs_portal_connect_disconnect', 'gs_portal_connect_nonce' );
};
$seed_caches = function () {
	update_option( 'gend_society_features_cache', array( 'x' => 1 ), false );
	update_option( 'gend_society_features_cache_expires', time() + 600, false );
	update_option( 'gend_society_remote_membership_cache', array( 'x' => 1 ), false );
	update_option( 'gend_society_remote_membership_cache_expires', time() + 600, false );
	update_option( 'gend_society_connection_health', array( 'status' => 'connected', 'checked_at' => time() ), false );
};

// --- 1. Connect (pairing), remember the hub record.
$r = gs108p_connect( $admin );
if ( ! gs108_assert( 'success' === gs108p_notice( $r )['status'], 'disconnect-setup-connected', 'connected by pairing code', wp_json_encode( $r ) ) ) {
	return;
}
$token  = (string) gs108c_opt( 'gend_society_install_token', '' );
$record = gs108p_inspect( $iid );
$mid    = (int) ( $record['membership_id'] ?? 0 );
gs108_assert( $mid > 0 && 'connected' === ( $record['state'] ?? '' ), 'disconnect-setup-hub', 'hub record connected (membership ' . $mid . ')', wp_json_encode( $record ) );
$seed_caches();

// --- 2. Revoke first.
$at_request = array();
$probe      = function ( $pre, $args, $url ) use ( &$at_request ) {
	if ( false !== strpos( (string) $url, '/disconnect' ) ) {
		$at_request[] = array(
			'url'     => (string) $url,
			'method'  => strtoupper( (string) ( $args['method'] ?? '' ) ),
			'auth'    => (string) ( $args['headers']['Authorization'] ?? '' ),
			'token'   => gs108c_raw( 'gend_society_install_token' ),
			'conn'    => gs108c_raw( 'gend_society_connection' ),
			'consent' => gs108c_raw( 'gend_society_consent' ),
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $probe, -1000, 3 );
$GLOBALS['gs108_uat']['http'] = array();
$r                            = $disconnect();
remove_filter( 'pre_http_request', $probe, -1000 );
$n = gs108p_notice( $r );

gs108_assert_eq( 1, count( $at_request ), 'disconnect-one-request', 'one hub request' );
$q = $at_request ? $at_request[0] : array();
gs108_assert_eq( gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . $iid . '/disconnect', $q['url'] ?? '', 'disconnect-endpoint', 'POST {hub}/wp-json/gdc-app-manager/v1/install/{id}/disconnect' );
gs108_assert( 'POST' === ( $q['method'] ?? '' ) && 'Bearer ' . $token === ( $q['auth'] ?? '' ), 'disconnect-bearer', 'POST with Authorization: Bearer <token>' );
gs108_assert( null !== ( $q['token'] ?? null ) && null !== ( $q['conn'] ?? null ) && null !== ( $q['consent'] ?? null ), 'disconnect-revoke-first', 'token, connection and consent still stored while the hub request was made' );
gs108_assert_eq( array( 'POST ' . gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . $iid . '/disconnect' ), gs108_http_log(), 'disconnect-only-request', 'no other request' );
gs108_assert( 'success' === $n['status'] && "Disconnected. gend.me no longer accepts this site's token." === $n['message'], 'disconnect-message', 'message "Disconnected. gend.me no longer accepts this site\'s token."', wp_json_encode( $n ) );
gs108_assert_eq( array(), $all_gone(), 'disconnect-local-deleted', 'token, base URL, pubkey, connected_at, connection, health, caches and consent deleted' );
gs108_assert( $kept(), 'disconnect-identity-kept', 'install_id and keypair kept' );
gs108_assert_eq( 401, gs108p_health( $iid, $token ), 'disconnect-old-token-401', 'hub health with the old token -> 401' );
$after = gs108p_inspect( $iid );
gs108_assert( $mid === (int) ( $after['membership_id'] ?? 0 ) && 'disconnected' === ( $after['state'] ?? '' ), 'disconnect-hub-record-kept', 'hub keeps the record, state disconnected', wp_json_encode( $after ) );
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'Connect this site to gend.me' ), 'disconnect-card', 'Connect card shown again' );

// --- 3. Reconnect via redirect: same install_id, same hub record reactivated.
$rs = gs108c_start( $admin );
list( $st, $data ) = gs108c_approve( $rs['request'], $rs['state'] );
$qq = is_array( $data ) && isset( $data['redirect'] ) ? gs108c_query( $data['redirect'] ) : array();
$rr = gs108c_return(
	$admin,
	array(
		'code'  => (string) ( $qq['code'] ?? '' ),
		'state' => (string) ( $qq['state'] ?? '' ),
	)
);
$re = gs108p_inspect( $iid );
gs108_assert( 'success' === gs108p_notice( $rr )['status'] && '' !== (string) gs108c_opt( 'gend_society_install_token', '' ), 'disconnect-reconnect', 'redirect reconnect after disconnect succeeds', wp_json_encode( array( $st, $data, $rr ) ) );
gs108_assert( $mid === (int) ( $re['membership_id'] ?? 0 ) && 'connected' === ( $re['state'] ?? '' ), 'disconnect-reconnect-same-record', 'hub membership id unchanged (' . $mid . '), state connected', wp_json_encode( $re ) );
gs108_assert( $kept(), 'disconnect-reconnect-identity', 'same install_id + keypair' );
$token2 = (string) gs108c_opt( 'gend_society_install_token', '' );
gs108_assert( $token2 !== $token && 200 === gs108p_health( $iid, $token2 ) && 401 === gs108p_health( $iid, $token ), 'disconnect-reconnect-token', 'new token works, old token still 401' );

// --- 4. Hub unreachable (dead port): nothing deleted, local-only path offered and works.
$seed_caches();
$hub_base = gs108c_opt( 'gend_society_gend_base_url' );
update_option( 'gend_society_gend_base_url', 'http://localhost:8919', false );
$snap_all = array();
foreach ( array_merge( $gone, array( 'gend_society_install_id', 'gend_society_keypair' ) ) as $o ) {
	$snap_all[ $o ] = gs108c_raw( $o );
}
$GLOBALS['gs108_uat']['http'] = array();
$r                            = $disconnect();
$n                            = gs108p_notice( $r );
$unchanged                    = true;
foreach ( $snap_all as $o => $v ) {
	$unchanged = $unchanged && $v === gs108c_raw( $o );
}
gs108_assert( 'error' === $n['status'] && 'Could not reach gend.me; the connection is still active there.' === $n['message'] && '1' === ( $n['query']['gs_disconnect_failed'] ?? '' ), 'disconnect-unreachable-error', 'error "Could not reach gend.me; the connection is still active there." + local-only flag', wp_json_encode( $n ) );
gs108_assert_eq( array( 'POST http://localhost:8919/wp-json/gdc-app-manager/v1/install/' . $iid . '/disconnect' ), gs108_http_log(), 'disconnect-unreachable-tried', 'the revoke was attempted at the stored base URL' );
gs108_assert( $unchanged, 'disconnect-unreachable-nothing-deleted', 'every option byte-identical (nothing deleted)' );
$html = gs108p_render( $admin, array( 'gs_disconnect_failed' => '1' ) );
gs108_assert( false !== strpos( $html, 'Disconnect locally anyway' ) && false !== strpos( $html, 'name="op" value="disconnect_local"' ), 'disconnect-local-offered', 'card offers "Disconnect locally anyway"' );
gs108_assert( false !== strpos( $html, esc_html( "gend.me still holds this site's record, and its token stays valid there until you reconnect (which replaces it) or a gend.me admin removes it." ) ), 'disconnect-local-explained', 'card states gend.me keeps the record and the token stays valid there' );
gs108_assert( (bool) preg_match( '/name="gend_society_disconnect_local_nonce" value="([0-9a-f]+)"/', $html, $m ) && false !== wp_verify_nonce( $m[1], 'gend_society_disconnect_local' ), 'disconnect-local-nonce', 'local-only form has its own nonce' );
$html = gs108p_render( $admin );
gs108_assert( false === strpos( $html, 'Disconnect locally anyway' ), 'disconnect-local-hidden', 'local-only button not shown without a failed disconnect' );

// 5xx: also nothing deleted.
update_option( 'gend_society_gend_base_url', $hub_base, false );
$off = gs108p_fake_hub( '/disconnect', 503, array( 'code' => 'x' ) );
$b5  = gs108p_conn_raw();
$r   = $disconnect();
$off();
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $b5 === gs108p_conn_raw() && null !== gs108c_raw( 'gend_society_consent' ), 'disconnect-5xx-nothing-deleted', 'hub 503 -> error, nothing deleted' );
$off = gs108p_fake_hub( '/disconnect', 429, array( 'code' => 'rate_limited' ) );
$r   = $disconnect();
$off();
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $b5 === gs108p_conn_raw(), 'disconnect-429-nothing-deleted', 'hub 429 -> error, nothing deleted' );

// Bad nonce on the local-only path: dies, nothing deleted.
$r = gs108p_op( $admin, 'disconnect_local', 'gs_portal_connect_disconnect', 'gend_society_disconnect_local_nonce' );
gs108_assert( 'die' === $r['kind'] && $b5 === gs108p_conn_raw(), 'disconnect-local-bad-nonce', 'local-only with the wrong nonce -> wp_die, nothing deleted' );

update_option( 'gend_society_gend_base_url', 'http://localhost:8919', false );
$GLOBALS['gs108_uat']['http'] = array();
$r                            = gs108p_op( $admin, 'disconnect_local', 'gend_society_disconnect_local', 'gend_society_disconnect_local_nonce' );
$n                            = gs108p_notice( $r );
gs108_assert( 'success' === $n['status'] && "Disconnected on this site only. gend.me still holds this site's record, and its token stays valid there until you reconnect (which replaces it) or a gend.me admin removes it." === $n['message'], 'disconnect-local-message', 'exact local-only message', $n['message'] );
gs108_assert_eq( array(), gs108_http_log(), 'disconnect-local-no-request', 'local-only path makes no request' );
gs108_assert_eq( array(), $all_gone(), 'disconnect-local-deleted', 'local-only path deletes the same options + consent' );
gs108_assert( $kept(), 'disconnect-local-identity', 'install_id + keypair kept' );
gs108_assert_eq( 200, gs108p_health( $iid, $token2 ), 'disconnect-local-token-still-valid', 'the hub still accepts the token (as the message says)' );

// --- 5. Hub already revoked (a gend.me admin removed it): 401 -> local deletion proceeds.
// Restore the local connection exactly as before the local-only disconnect, then revoke on the hub.
foreach ( $snap_all as $o => $v ) {
	if ( null === $v ) {
		delete_option( $o );
	} else {
		update_option( $o, maybe_unserialize( $v ), false );
	}
}
update_option( 'gend_society_gend_base_url', $hub_base, false );
gs108_assert_eq( 200, gs108p_hub_revoke( $iid, $token2 ), 'disconnect-401-setup', 'revoked on the hub directly' );
$GLOBALS['gs108_uat']['http'] = array();
$r                            = $disconnect();
$n                            = gs108p_notice( $r );
gs108_assert_eq( 1, count( gs108c_hub_calls( '/disconnect' ) ), 'disconnect-401-one-request', 'one revoke request (answered 401)' );
gs108_assert( 'success' === $n['status'] && array() === $all_gone() && $kept(), 'disconnect-401-proceeds', 'hub 401 -> local deletion proceeds, identity kept', wp_json_encode( $n ) );

// Not connected: Disconnect only tidies up locally (no request).
$GLOBALS['gs108_uat']['http'] = array();
$r                            = $disconnect();
gs108_assert( 'success' === gs108p_notice( $r )['status'] && array() === gs108_http_log(), 'disconnect-not-connected', 'not connected -> success, no request' );

// A subscriber cannot disconnect.
$sub = gs108c_subscriber();
wp_set_current_user( $sub );
$r = gs108c_call(
	'gend_society_portal_connect_handle_submit',
	'POST',
	array(),
	array(
		'op'                      => 'disconnect',
		'gs_portal_connect_nonce' => wp_create_nonce( 'gs_portal_connect_disconnect' ),
	)
);
gs108_assert( 'die' === $r['kind'], 'disconnect-subscriber', 'subscriber -> Forbidden' );
