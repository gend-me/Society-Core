<?php
/**
 * Phase 30 / Plan 30-04 — UAT 4: moderator claim is true iff caller is host
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-moderator-claim.php
 *
 * Setup:
 *  - Use 2 existing WP users (admin user_id=1 as host, look up or create a second user as guest)
 *  - Insert meeting: member_id=host, guest_user_id=guest, video+gend, in-window
 *  - Switch context to HOST; call /jitsi-jwt; decode JWT; assert moderator === 'true'
 *  - Switch context to GUEST; call /jitsi-jwt; decode JWT; assert moderator === 'false'
 *  - Switch context to a third user (neither host nor guest); call /jitsi-jwt; assert 403 forbidden
 * Cleans up seeded row + any test users created.
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }
global $wpdb;
$table = $wpdb->prefix . 'gs_member_meetings';

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 4: moderator claim (host=true, guest=false, third=403) ===\n";

$assert( class_exists( 'Gend_GS_Jitsi_REST' ), 'Gend_GS_Jitsi_REST class loaded' );
if ( ! class_exists( 'Gend_GS_Jitsi_REST' ) ) { echo "ABORT: REST class missing\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }

$host_id = 1;
if ( ! get_userdata( $host_id ) ) { echo "ABORT: host user_id=1 missing\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }

// Find or create a guest user
$guest = get_users( array( 'role__in' => array( 'subscriber', 'editor', 'author' ), 'number' => 1, 'fields' => array( 'ID' ), 'exclude' => array( $host_id ) ) );
if ( empty( $guest ) ) {
	$guest_id = wp_insert_user( array(
		'user_login' => 'gs_jitsi_uat_guest_' . wp_generate_password( 6, false, false ),
		'user_email' => 'gs_jitsi_uat_guest_' . time() . '@example.invalid',
		'user_pass'  => wp_generate_password( 12, true, true ),
		'role'       => 'subscriber',
	) );
	if ( is_wp_error( $guest_id ) ) { echo "ABORT: cannot create guest: " . $guest_id->get_error_message() . "\n"; echo "\n=== FAIL === cannot seed guest\n"; return; }
	$created_guest = true;
} else {
	$guest_id = (int) $guest[0]->ID;
	$created_guest = false;
}

// Find or create a third party
$third = get_users( array( 'role__in' => array( 'subscriber', 'editor', 'author' ), 'number' => 1, 'fields' => array( 'ID' ), 'exclude' => array( $host_id, $guest_id ) ) );
if ( empty( $third ) ) {
	$third_id = wp_insert_user( array(
		'user_login' => 'gs_jitsi_uat_third_' . wp_generate_password( 6, false, false ),
		'user_email' => 'gs_jitsi_uat_third_' . time() . '@example.invalid',
		'user_pass'  => wp_generate_password( 12, true, true ),
		'role'       => 'subscriber',
	) );
	if ( is_wp_error( $third_id ) ) { echo "ABORT: cannot create third: " . $third_id->get_error_message() . "\n"; if ( $created_guest ) { wp_delete_user( $guest_id ); } echo "\n=== FAIL === cannot seed third\n"; return; }
	$created_third = true;
} else {
	$third_id = (int) $third[0]->ID;
	$created_third = false;
}

$now = time();
$wpdb->insert( $table, array(
	'member_id'         => $host_id,
	'guest_user_id'     => $guest_id,
	'meeting_type'      => 'video',
	'meeting_meta_json' => wp_json_encode( array( 'provider' => 'gend' ) ),
	'starts_at_utc'     => gmdate( 'Y-m-d H:i:s', $now - 60 ),
	'ends_at_utc'       => gmdate( 'Y-m-d H:i:s', $now + 1800 ),
	'status'            => 'booked',
) );
$meeting_id = (int) $wpdb->insert_id;

$decode_moderator = function ( $jwt ) {
	$parts = explode( '.', (string) $jwt );
	if ( count( $parts ) !== 3 ) { return null; }
	$p_json = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ) );
	$p = json_decode( $p_json, true );
	return $p['context']['user']['moderator'] ?? null;
};

$call_as = function ( $user_id ) use ( $meeting_id ) {
	wp_set_current_user( $user_id );
	$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings/' . $meeting_id . '/jitsi-jwt' );
	$req->set_url_params( array( 'id' => $meeting_id ) );
	$resp = rest_do_request( $req );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};

// Host call -> moderator=true
$rHost = $call_as( $host_id );
$assert( 200 === $rHost['status'], 'Host (member_id) -> 200' );
$assert( $decode_moderator( $rHost['data']['jwt'] ?? '' ) === 'true', 'Host JWT -> moderator === "true"' );
$assert( ( $rHost['data']['moderator'] ?? null ) === true, 'Host response -> moderator === true (boolean)' );

// Guest call -> moderator=false
$rGuest = $call_as( $guest_id );
$assert( 200 === $rGuest['status'], 'Guest (guest_user_id) -> 200' );
$assert( $decode_moderator( $rGuest['data']['jwt'] ?? '' ) === 'false', 'Guest JWT -> moderator === "false"' );
$assert( ( $rGuest['data']['moderator'] ?? null ) === false, 'Guest response -> moderator === false (boolean)' );

// Third party call -> 403 forbidden
$rThird = $call_as( $third_id );
$assert( 403 === $rThird['status'], 'Third party (neither host nor guest) -> 403' );
$assert( ( $rThird['data']['code'] ?? '' ) === 'forbidden', 'Third party -> code=forbidden' );

// Cleanup
$wpdb->delete( $table, array( 'id' => $meeting_id ) );
if ( $created_guest ) { wp_delete_user( $guest_id ); }
if ( $created_third ) { wp_delete_user( $third_id ); }

if ( 0 === $fails ) { echo "\n=== SUCCESS === moderator claim only for host; guests + third parties handled correctly\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed\n"; }
