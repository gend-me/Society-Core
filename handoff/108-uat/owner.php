<?php
/**
 * Section owner (plan 108-09, CON-09 / U8): the connecting admin owns the connection; only the owner
 * transfers (or any admin once the owner lost manage_options or none is set); the target must be
 * another manage_options user; the hub is told the new owner's display name (gdc_install_local_owner).
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_pair.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_assert( ! function_exists( 'gend_society_connect_pairing_prepare' ), 'owner-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108p_require() ) {
	return;
}

$admin  = gs108c_admin_id();
$admin2 = gs108c_other_admin();
$sub    = gs108c_subscriber();
gs108_http_record();
$iid = gend_society_connect_identity()['install_id'];
wp_update_user( array( 'ID' => $admin2, 'display_name' => 'GS108 Second Admin' ) );

$owner    = function () {
	$c = gs108c_opt( 'gend_society_connection', array() );
	return is_array( $c ) ? (int) ( $c['owner_user_id'] ?? 0 ) : -1;
};
$transfer = function ( $as, $to ) {
	return gs108p_op( $as, 'transfer_owner', 'gend_society_transfer_owner', 'gend_society_owner_nonce', array( 'new_owner' => (string) $to ) );
};
$claim    = function ( $as ) {
	return gs108p_op( $as, 'claim_owner', 'gend_society_claim_owner', 'gend_society_owner_nonce' );
};
$hub_owner = function () use ( $iid ) {
	$r = gs108p_inspect( $iid );
	return (string) ( $r['local_owner'] ?? '' );
};

$r = gs108p_connect( $admin );
if ( ! gs108_assert( 'success' === gs108p_notice( $r )['status'], 'owner-setup-connected', 'connected by pairing code as admin', wp_json_encode( $r ) ) ) {
	return;
}
gs108_assert_eq( $admin, $owner(), 'owner-initial', 'connecting admin is the owner' );

// Card: the owner sees the transfer form listing the other admin (not the subscriber, not themself).
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'name="op" value="transfer_owner"' ) && false !== strpos( $html, '<option value="' . $admin2 . '">' ) && false === strpos( $html, '<option value="' . $sub . '">' ) && false === strpos( $html, '<option value="' . $admin . '">' ), 'owner-card-owner', 'owner sees Transfer ownership with only the other admin listed' );
gs108_assert( false === strpos( $html, 'Make me the owner' ), 'owner-card-no-claim', 'no "Make me the owner" while the owner is valid' );
$html2 = gs108p_render( $admin2 );
gs108_assert( false === strpos( $html2, 'name="op" value="transfer_owner"' ) && false !== strpos( $html2, 'name="op" value="disconnect"' ), 'owner-card-non-owner', 'another admin sees Disconnect but no transfer form' );

// Refusals while the owner is valid.
$GLOBALS['gs108_uat']['http'] = array();
$r                            = $transfer( $admin2, $admin2 );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $admin === $owner() && array() === gs108_http_log(), 'owner-non-owner-refused', 'non-owner admin cannot transfer (owner unchanged, no hub call)' );
$r = $claim( $admin2 );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $admin === $owner(), 'owner-claim-refused', 'claim refused while the owner is valid' );
$r = $transfer( $admin, $sub );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && false !== strpos( gs108p_notice( $r )['message'], 'administrator' ) && $admin === $owner(), 'owner-target-no-cap', 'target without manage_options refused' );
$r = $transfer( $admin, 999999 );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $admin === $owner(), 'owner-target-missing', 'unknown target refused' );
$r = $transfer( $admin, $admin );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $admin === $owner(), 'owner-target-self', 'target = current owner refused' );
$r = gs108p_op( $admin, 'transfer_owner', 'gend_society_claim_owner', 'gend_society_owner_nonce', array( 'new_owner' => (string) $admin2 ) );
gs108_assert( 'die' === $r['kind'] && $admin === $owner(), 'owner-bad-nonce', 'wrong nonce -> wp_die, owner unchanged' );
gs108_assert_eq( array(), gs108_http_log(), 'owner-refusals-no-request', 'no hub request for any refusal' );

// Owner transfers to the second admin: local + hub.
$sent = array();
$spy  = function ( $pre, $args, $url ) use ( &$sent ) {
	if ( false !== strpos( (string) $url, '/owner' ) ) {
		$sent[] = array( (string) $url, json_decode( (string) ( $args['body'] ?? '' ), true ), (string) ( $args['headers']['Authorization'] ?? '' ) );
	}
	return $pre;
};
add_filter( 'pre_http_request', $spy, -1000, 3 );
$r = $transfer( $admin, $admin2 );
remove_filter( 'pre_http_request', $spy, -1000 );
$n = gs108p_notice( $r );
gs108_assert( 'success' === $n['status'] && $admin2 === $owner() && false !== strpos( $n['message'], 'GS108 Second Admin now owns the connection' ) && false === strpos( $n['message'], 'could not' ), 'owner-transfer', 'owner -> second admin, success notice', wp_json_encode( $n ) );
gs108_assert( 1 === count( $sent ) && gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . $iid . '/owner' === $sent[0][0] && array( 'owner_display' => 'GS108 Second Admin' ) === $sent[0][1] && 'Bearer ' . gs108c_opt( 'gend_society_install_token' ) === $sent[0][2], 'owner-hub-request', 'POST /install/{id}/owner {owner_display} with the bearer token' );
gs108_assert_eq( 'GS108 Second Admin', $hub_owner(), 'owner-hub-stored', 'hub gdc_install_local_owner = new display name' );
$r = $transfer( $admin, $admin );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $admin2 === $owner(), 'owner-old-owner-refused', 'the former owner can no longer transfer' );
$html = gs108p_render( $admin );
gs108_assert( false === strpos( $html, 'name="op" value="transfer_owner"' ) && false !== strpos( $html, 'GS108 Second Admin' ), 'owner-card-after', 'card shows the new owner; former owner has no transfer form' );

// Owner demoted: any admin may transfer.
$u2 = new WP_User( $admin2 );
$u2->set_role( 'subscriber' );
clean_user_cache( $admin2 );
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'name="op" value="transfer_owner"' ) && false !== strpos( $html, 'Make me the owner' ), 'owner-demoted-card', 'owner lost manage_options -> other admin sees transfer + claim' );
$third = gs108c_other_admin();
$r     = $transfer( $admin, $third );
gs108_assert( 'success' === gs108p_notice( $r )['status'] && $third === $owner(), 'owner-demoted-transfer', 'another admin transfers once the owner lost manage_options' );
gs108_assert_eq( get_userdata( $third )->display_name, $hub_owner(), 'owner-demoted-hub', 'hub told the new owner name' );

// Legacy install (no owner recorded): "Not set", claim works, then the claimer is the owner.
$c = gs108c_opt( 'gend_society_connection', array() );
unset( $c['owner_user_id'] );
update_option( 'gend_society_connection', $c, false );
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'Not set' ) && false !== strpos( $html, 'name="op" value="claim_owner"' ), 'owner-legacy-card', 'no owner -> "Not set" + "Make me the owner"' );
$r = $claim( $admin );
gs108_assert( 'success' === gs108p_notice( $r )['status'] && $admin === $owner(), 'owner-legacy-claim', 'claim sets the owner' );
gs108_assert_eq( get_userdata( $admin )->display_name, $hub_owner(), 'owner-legacy-hub', 'hub told the claimer\'s name' );
$r = $claim( $third );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $admin === $owner(), 'owner-legacy-claim-once', 'a second claim is refused (owner valid now)' );

// Installs connected before 1.4.0 have no connection option at all: claim creates it.
delete_option( 'gend_society_connection' );
$r = $claim( $admin );
$c = gs108c_opt( 'gend_society_connection', array() );
gs108_assert( 'success' === gs108p_notice( $r )['status'] && $admin === (int) ( $c['owner_user_id'] ?? 0 ) && gs108c_hub() === ( $c['hub'] ?? '' ), 'owner-pre-140-claim', 'pre-1.4.0 install: claim creates the connection record with the owner' );

// Hub /owner failure: local transfer still done, notice says gend.me was not told.
$off = gs108p_fake_hub( '/owner', 500, array( 'code' => 'x' ) );
$r   = $transfer( $admin, $third );
$off();
$n = gs108p_notice( $r );
gs108_assert( 'success' === $n['status'] && $third === $owner() && false !== strpos( $n['message'], "gend.me could not be told the new owner's name (HTTP 500)" ), 'owner-hub-failure', 'hub /owner 500 -> local transfer kept, notice mentions it', $n['message'] );

// Not connected: refused.
$token = gs108c_opt( 'gend_society_install_token' );
delete_option( 'gend_society_install_token' );
$r = $transfer( $third, $admin );
gs108_assert( 'error' === gs108p_notice( $r )['status'] && $third === $owner(), 'owner-not-connected', 'transfer refused when not connected' );
update_option( 'gend_society_install_token', $token, false );
