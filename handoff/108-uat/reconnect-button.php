<?php
/**
 * Section reconnect-button (plan 108-09, round-2 decision): with the last health check
 * "unauthorized", the Connected card offers "Reconnect to gend.me" (admin-post connect_start,
 * reconnect=1). Posting it re-records consent (source reconnect, newer at) and starts with the same
 * install_id + pubkey. A cancelled reconnect, a failed start and an expired pending each leave the
 * connection intact and consent byte-identical to before the click. A completed reconnect reuses
 * the hub record and clears the stale health status.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_pair.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_assert( ! function_exists( 'gend_society_connect_handle_start' ), 'reconnect-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108p_require() ) {
	return;
}

$admin = gs108c_admin_id();
gs108_http_record();
$identity = gend_society_connect_identity();
$iid      = $identity['install_id'];

$r = gs108p_connect( $admin );
if ( ! gs108_assert( 'success' === gs108p_notice( $r )['status'], 'reconnect-setup-connected', 'connected by pairing code', wp_json_encode( $r ) ) ) {
	return;
}
$mid = (int) ( gs108p_inspect( $iid )['membership_id'] ?? 0 );

// Healthy: no Reconnect button.
update_option( 'gend_society_connection_health', array( 'status' => 'connected', 'checked_at' => time() ), false );
$html = gs108p_render( $admin );
gs108_assert( false === strpos( $html, 'Reconnect to gend.me' ), 'reconnect-hidden-healthy', 'no Reconnect button while health is connected' );
delete_option( 'gend_society_connection_health' );
$html = gs108p_render( $admin );
gs108_assert( false === strpos( $html, 'Reconnect to gend.me' ), 'reconnect-hidden-unknown', 'no Reconnect button without a health result' );

// Unauthorized: warning + primary Reconnect button posting connect_start with reconnect=1.
update_option( 'gend_society_connection_health', array( 'status' => 'unauthorized', 'checked_at' => time() ), false );
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'gend.me no longer recognises this site.' ), 'reconnect-warning', 'warning "gend.me no longer recognises this site."' );
$form_ok = (bool) preg_match( '#<form method="post" action="[^"]*admin-post\.php">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)" />.*?<input type="hidden" name="action" value="gend_society_connect_start" />\s*<input type="hidden" name="reconnect" value="1" />\s*<button type="submit" class="button button-primary">Reconnect to gend.me</button>#s', $html, $m );
gs108_assert( $form_ok, 'reconnect-button', 'primary "Reconnect to gend.me" form: action gend_society_connect_start, reconnect=1' );
gs108_assert( $form_ok && false !== wp_verify_nonce( $m[1], 'gend_society_connect_start' ), 'reconnect-nonce', 'form carries a valid gend_society_connect_start nonce' );
gs108_assert( false !== strpos( $html, 'name="op" value="disconnect"' ), 'reconnect-disconnect-still', 'Disconnect still offered' );

// Make the stored consent visibly older so "newer at" is measurable.
$c       = gs108c_opt( 'gend_society_consent', array() );
$c['at'] = time() - 100;
update_option( 'gend_society_consent', $c, false );
$conn_before = gs108p_conn_raw();

/** Click Reconnect, capture the /connect/start body. */
$click = function () use ( $admin ) {
	$bodies = array();
	$spy    = function ( $pre, $args, $url ) use ( &$bodies ) {
		if ( false !== strpos( (string) $url, '/connect/start' ) ) {
			$bodies[] = json_decode( (string) ( $args['body'] ?? '' ), true );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $spy, -1000, 3 );
	$r = gs108c_start( $admin, true );
	remove_filter( 'pre_http_request', $spy, -1000 );
	$r['bodies'] = $bodies;
	return $r;
};

// --- 1. Click + cancel on gend.me.
$pre = gs108p_consent_raw();
$r   = $click();
$cn  = gs108c_opt( 'gend_society_consent', array() );
gs108_assert( 'redirect' === $r['kind'] && 0 === strpos( $r['location'], gs108c_hub() . '/connect/' ) && '' !== $r['state'], 'reconnect-start-redirect', 'Reconnect redirects to the hub /connect/ with a state', $r['location'] );
gs108_assert( 'reconnect' === ( $cn['source'] ?? '' ) && (int) ( $cn['at'] ?? 0 ) > (int) $c['at'] && $admin === (int) ( $cn['user_id'] ?? $admin ), 'reconnect-consent-recorded', 'consent re-recorded: source reconnect, newer at', wp_json_encode( $cn ) );
$b = $r['bodies'] ? $r['bodies'][0] : array();
gs108_assert( 1 === count( $r['bodies'] ) && $iid === ( $b['install_id'] ?? '' ) && $identity['pubkey_b64'] === ( $b['society_pubkey'] ?? '' ), 'reconnect-same-identity', 'start request carries the same install_id + pubkey' );
gs108_assert( $iid === gs108c_raw( 'gend_society_install_id' ) && $conn_before['gend_society_keypair'] === gs108c_raw( 'gend_society_keypair' ), 'reconnect-identity-kept', 'install_id + keypair options unchanged' );
$p = gs108c_opt( 'gend_society_connect_pending', array() );
gs108_assert( is_array( $p['prior_consent'] ?? null ) && maybe_unserialize( $pre['consent'] ) === $p['prior_consent']['consent'], 'reconnect-prior-snapshot', 'pending.prior_consent holds the pre-click consent' );

$rc = gs108c_return(
	$admin,
	array(
		'error' => 'access_denied',
		'state' => $r['state'],
	)
);
gs108_assert( 'error' === gs108p_notice( $rc )['status'] && false !== strpos( gs108p_notice( $rc )['message'], 'Connection cancelled on gend.me.' ), 'reconnect-cancel-notice', 'cancel -> "Connection cancelled on gend.me."' );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'reconnect-cancel-pending', 'pending gone' );
gs108_assert( $conn_before === gs108p_conn_raw(), 'reconnect-cancel-connection', 'connection options byte-identical' );
gs108_assert( $pre === gs108p_consent_raw(), 'reconnect-cancel-consent', 'consent byte-identical to the pre-click snapshot (same at/source/version)' );

// --- 2. Failed start (hub 400).
$pre = gs108p_consent_raw();
$off = gs108p_fake_hub( '/connect/start', 400, array( 'code' => 'stale_request', 'message' => 'Request expired.' ) );
$r   = $click();
$off();
gs108_assert( 'error' === gs108p_notice( $r )['status'] && false !== strpos( gs108p_notice( $r )['message'], 'HTTP 400' ), 'reconnect-start-fail-notice', 'start 400 -> error notice' );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ) && $conn_before === gs108p_conn_raw() && $pre === gs108p_consent_raw(), 'reconnect-start-fail-restored', 'failed start: no pending, connection + consent byte-identical' );

// Network failure at start.
$off = gs108p_fake_hub( '/connect/start', 0 );
$r   = $click();
$off();
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $conn_before === gs108p_conn_raw() && $pre === gs108p_consent_raw(), 'reconnect-start-network-restored', 'start network error: connection + consent byte-identical' );

// --- 3. Expired pending, cleaned up lazily.
$r = $click();
gs108_assert( 'redirect' === $r['kind'] && 'reconnect' === ( gs108c_opt( 'gend_society_consent', array() )['source'] ?? '' ), 'reconnect-expiry-started', 'reconnect started (consent re-recorded)' );
$p            = gs108c_opt( 'gend_society_connect_pending', array() );
$p['expires'] = time() - 1;
update_option( 'gend_society_connect_pending', $p, false );
wp_set_current_user( $admin );
gend_society_connect_expire_pending();
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ) && $conn_before === gs108p_conn_raw() && $pre === gs108p_consent_raw(), 'reconnect-expiry-restored', 'expired pending removed on admin load; connection + consent byte-identical' );

// The card still offers Reconnect after all of that.
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'Reconnect to gend.me' ), 'reconnect-still-offered', 'Reconnect still offered after a cancelled / failed attempt' );

// --- 4. Completed reconnect: same hub record, new token, health status cleared, no button.
$r = $click();
list( $st, $data ) = gs108c_approve( $r['request'], $r['state'] );
$q  = is_array( $data ) && isset( $data['redirect'] ) ? gs108c_query( $data['redirect'] ) : array();
$rr = gs108c_return(
	$admin,
	array(
		'code'  => (string) ( $q['code'] ?? '' ),
		'state' => (string) ( $q['state'] ?? '' ),
	)
);
gs108_assert( 'success' === gs108p_notice( $rr )['status'], 'reconnect-complete', 'reconnect completes', wp_json_encode( array( $st, $data, $rr ) ) );
gs108_assert( $conn_before['gend_society_install_token'] !== gs108c_raw( 'gend_society_install_token' ) && $iid === gs108c_raw( 'gend_society_install_id' ) && $conn_before['gend_society_keypair'] === gs108c_raw( 'gend_society_keypair' ), 'reconnect-complete-identity', 'new token, same install_id + keypair' );
gs108_assert_eq( $mid, (int) ( gs108p_inspect( $iid )['membership_id'] ?? 0 ), 'reconnect-complete-same-record', 'hub membership id unchanged' );
gs108_assert( null === gs108c_raw( 'gend_society_connection_health' ), 'reconnect-complete-health-cleared', 'stale unauthorized health status cleared by the new connection' );
gs108_assert( 'reconnect' === ( gs108c_opt( 'gend_society_consent', array() )['source'] ?? '' ), 'reconnect-complete-consent', 'consent kept with source reconnect' );
$html = gs108p_render( $admin );
gs108_assert( false === strpos( $html, 'Reconnect to gend.me' ) && false !== strpos( $html, 'Connected to gend.me' ), 'reconnect-complete-card', 'card without the Reconnect button' );
