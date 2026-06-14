<?php
/**
 * Phase 30 / Plan 30-04 — UAT 3: room name honors the DEPLOYED Phase-29 meta.jitsi_room contract
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-room-deterministic.php
 *
 * Inserts 3 video+gend meetings WITH meta.jitsi_room set (the gend-meet-{host}-{16char} value
 * already emailed to guests as https://meet.gend.me/{jitsi_room}); asserts each /jitsi-jwt
 * response has room === meta.jitsi_room AND the JWT room claim agrees.
 * PLUS 1 legacy row with EMPTY meta.jitsi_room; asserts response.room === 'gsmeet-' + meeting.id (fallback).
 * Cleans up all seeded rows at the end.
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }
global $wpdb;
$table = $wpdb->prefix . 'gs_member_meetings';

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 3: room determinism (meta.jitsi_room contract + gsmeet-{id} fallback) ===\n";

$assert( class_exists( 'Gend_GS_Jitsi_REST' ), 'Gend_GS_Jitsi_REST class loaded' );
if ( ! class_exists( 'Gend_GS_Jitsi_REST' ) ) { echo "ABORT: REST class missing\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }

$caller_id = 1;
if ( ! get_userdata( $caller_id ) ) { echo "ABORT: user_id=1 missing\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }
wp_set_current_user( $caller_id );

$now = time();

// --- 3 rows WITH meta.jitsi_room (the Phase-29-shipped contract) ---
$expected = array();   // meeting_id => expected room name
for ( $i = 0; $i < 3; $i++ ) {
	$room = 'gend-meet-' . $caller_id . '-RoomDet' . sprintf( '%02d', $i ) . wp_generate_password( 8, false, false );
	$wpdb->insert( $table, array(
		'member_id'         => $caller_id,
		'guest_user_id'     => 0,
		'meeting_type'      => 'video',
		'meeting_meta_json' => wp_json_encode( array( 'provider' => 'gend', 'jitsi_room' => $room ) ),
		'starts_at_utc'     => gmdate( 'Y-m-d H:i:s', $now - 60 ),
		'ends_at_utc'       => gmdate( 'Y-m-d H:i:s', $now + 1800 ),
		'status'            => 'booked',
	) );
	$expected[ (int) $wpdb->insert_id ] = $room;
}

foreach ( $expected as $id => $room ) {
	$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings/' . $id . '/jitsi-jwt' );
	$req->set_url_params( array( 'id' => $id ) );
	$resp = rest_do_request( $req );
	$data = $resp->get_data();
	$assert( 200 === $resp->get_status(), "meeting $id status 200" );
	$assert( ( $data['room'] ?? '' ) === $room,
	         "meeting $id response.room === meta.jitsi_room ($room) -- matches the emailed join link" );
	// The JWT room claim MUST equal the envelope room (else guest's emailed link mismatches the JWT)
	$jwt   = (string) ( $data['jwt'] ?? '' );
	$parts = explode( '.', $jwt );
	if ( count( $parts ) === 3 ) {
		$pj = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ) );
		$p  = json_decode( $pj, true );
		$assert( ( $p['room'] ?? '' ) === $room,
		         "meeting $id JWT room claim === meta.jitsi_room (JWT agrees with envelope + emailed link)" );
	} else {
		$assert( false, "meeting $id JWT decodable (3 segments)" );
	}
}

// --- 1 legacy row with EMPTY meta.jitsi_room -> fallback gsmeet-{id} ---
$wpdb->insert( $table, array(
	'member_id'         => $caller_id,
	'guest_user_id'     => 0,
	'meeting_type'      => 'video',
	'meeting_meta_json' => wp_json_encode( array( 'provider' => 'gend' ) ),   // NO jitsi_room -- legacy row
	'starts_at_utc'     => gmdate( 'Y-m-d H:i:s', $now - 60 ),
	'ends_at_utc'       => gmdate( 'Y-m-d H:i:s', $now + 1800 ),
	'status'            => 'booked',
) );
$legacy_id = (int) $wpdb->insert_id;
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings/' . $legacy_id . '/jitsi-jwt' );
$req->set_url_params( array( 'id' => $legacy_id ) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
$assert( 200 === $resp->get_status(), "legacy meeting $legacy_id status 200" );
$assert( ( $data['room'] ?? '' ) === "gsmeet-$legacy_id",
         "legacy row (empty meta.jitsi_room) response.room === gsmeet-$legacy_id (fallback)" );

// Cleanup
foreach ( array_keys( $expected ) as $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); }
$wpdb->delete( $table, array( 'id' => $legacy_id ) );

if ( 0 === $fails ) { echo "\n=== SUCCESS === response.room === meta.jitsi_room (DEPLOYED contract) + gsmeet-{id} legacy fallback\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed\n"; }
