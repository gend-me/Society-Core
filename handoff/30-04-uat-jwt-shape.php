<?php
/**
 * Phase 30 / Plan 30-04 — UAT 1: JWT shape + signature roundtrip
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-jwt-shape.php
 *
 * Asserts:
 *  - Gend_GS_Jitsi_JWT::get_secret returns a non-empty string (or surface clear error if wp-config shim missing)
 *  - Gend_GS_Jitsi_JWT::mint returns a 3-segment compact JWT (header.payload.signature)
 *  - Header decodes to {alg: HS256, typ: JWT}
 *  - Payload decodes to {iss: gend-society, aud: jitsi, sub: meet.gend.me, room: <resolved meta.jitsi_room>, nbf, exp, iat, context.user.{id,name,moderator}}
 *  - resolve_room() returns meta.jitsi_room when present; falls back to gsmeet-{id} when empty
 *  - Signature verifies via Gend_GS_Jitsi_JWT::verify_signature (hash_hmac roundtrip)
 *  - Independently: PHP hash_hmac with the same secret produces the same signature
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; }
	else         { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 1: JWT shape + signature roundtrip ===\n";

// Preflight: class exists
$assert( class_exists( 'Gend_GS_Jitsi_JWT' ), 'Gend_GS_Jitsi_JWT class loaded' );
if ( ! class_exists( 'Gend_GS_Jitsi_JWT' ) ) { echo "ABORT: class missing\n"; return; }

// Secret resolution
$secret = Gend_GS_Jitsi_JWT::get_secret();
if ( is_wp_error( $secret ) ) {
	echo "FAIL: jwt_secret_missing — operator must add GS_JITSI_JWT_SECRET to wp-config.php (per Plan 30-01 runbook + project_hub_credentials_secret pattern)\n";
	echo "Error: " . $secret->get_error_message() . "\n";
	echo "\n=== FAIL === GS_JITSI_JWT_SECRET not resolvable; complete Step B of the deploy runbook first\n";
	return;
}
$assert( is_string( $secret ) && strlen( $secret ) >= 32, 'GS_JITSI_JWT_SECRET resolved (>= 32 chars)' );

// Mint
$meeting_id   = 42;
$member_id    = 7;
$display_name = 'Test User';
$is_moderator = true;
$now = time();
$nbf = $now - 30;
$exp = $now + 3600;

$room = 'gend-meet-7-AbCdEfGhIjKlMnOp';   // emulate the Phase-29-shipped meta.jitsi_room value
$jwt = Gend_GS_Jitsi_JWT::mint( $room, $meeting_id, $member_id, $display_name, $is_moderator, $nbf, $exp );
$assert( is_string( $jwt ) && '' !== $jwt, 'mint() returned non-empty string' );

$parts = explode( '.', (string) $jwt );
$assert( count( $parts ) === 3, 'JWT has exactly 3 segments (header.payload.signature)' );
if ( count( $parts ) !== 3 ) { echo "ABORT: mint() did not return a 3-segment JWT\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }

// Decode header
$header_json = base64_decode( strtr( $parts[0], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[0] ) % 4 ) % 4 ) );
$header      = json_decode( $header_json, true );
$assert( is_array( $header ) && isset( $header['alg'], $header['typ'] ), 'header decodes to JSON object with alg + typ' );
$assert( ( $header['alg'] ?? '' ) === 'HS256', 'header.alg === HS256' );
$assert( ( $header['typ'] ?? '' ) === 'JWT',   'header.typ === JWT' );

// Decode payload
$payload_json = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ) );
$payload      = json_decode( $payload_json, true );
$assert( is_array( $payload ), 'payload decodes to JSON object' );
$assert( ( $payload['iss']  ?? '' ) === 'gend-society', 'payload.iss === gend-society' );
$assert( ( $payload['aud']  ?? '' ) === 'jitsi',         'payload.aud === jitsi' );
$assert( ( $payload['sub']  ?? '' ) === 'meet.gend.me',  'payload.sub === meet.gend.me' );
$assert( ( $payload['room'] ?? '' ) === $room,           'payload.room === the resolved meta.jitsi_room passed to mint() (DEPLOYED Phase-29 contract)' );
$assert( (int) ( $payload['nbf'] ?? 0 ) === $nbf, 'payload.nbf === provided nbf' );
$assert( (int) ( $payload['exp'] ?? 0 ) === $exp, 'payload.exp === provided exp' );
$assert( isset( $payload['iat'] ), 'payload.iat present' );
$assert( isset( $payload['context']['user']['id'], $payload['context']['user']['name'], $payload['context']['user']['moderator'] ),
         'payload.context.user has id + name + moderator' );
$assert( ( $payload['context']['user']['moderator'] ?? '' ) === 'true',
         'payload.context.user.moderator === string "true" (NOT bool — prosody mod_auth_token expects string)' );
$assert( ( $payload['context']['user']['id'] ?? '' ) === (string) $member_id,
         'payload.context.user.id is string form of member_id' );

// Signature via class helper
$assert( Gend_GS_Jitsi_JWT::verify_signature( $jwt ) === true,
         'Gend_GS_Jitsi_JWT::verify_signature returns true for an unmodified JWT' );

// Signature via independent hash_hmac (proves the class isn't faking it)
$signing_input  = $parts[0] . '.' . $parts[1];
$expected_raw   = hash_hmac( 'sha256', $signing_input, $secret, true );
$expected_b64u  = rtrim( strtr( base64_encode( $expected_raw ), '+/', '-_' ), '=' );
$assert( hash_equals( $expected_b64u, $parts[2] ),
         'independent hash_hmac roundtrip matches signature segment (proves prosody-compatible)' );

// resolve_room() — DEPLOYED Phase-29 contract: returns meta.jitsi_room when present, gsmeet-{id} fallback when empty
$assert( Gend_GS_Jitsi_JWT::resolve_room( array( 'provider' => 'gend', 'jitsi_room' => 'gend-meet-7-AbCdEfGhIjKlMnOp' ), 42 ) === 'gend-meet-7-AbCdEfGhIjKlMnOp',
         'resolve_room returns meta.jitsi_room verbatim when present (the value already emailed to guests)' );
$assert( Gend_GS_Jitsi_JWT::resolve_room( array( 'provider' => 'gend' ), 42 ) === 'gsmeet-42',
         'resolve_room falls back to gsmeet-{id} when meta.jitsi_room is empty (legacy row)' );
$assert( Gend_GS_Jitsi_JWT::resolve_room( null, 42 ) === 'gsmeet-42',
         'resolve_room falls back to gsmeet-{id} when meta is null' );

// Negative: moderator=false produces string "false"
$jwt2 = Gend_GS_Jitsi_JWT::mint( 'gend-meet-9-ZyXwVuTsRqPoNmLk', 43, 9, 'Guest', false, $nbf, $exp );
$parts2 = explode( '.', (string) $jwt2 );
if ( count( $parts2 ) === 3 ) {
	$payload2_json = base64_decode( strtr( $parts2[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts2[1] ) % 4 ) % 4 ) );
	$payload2 = json_decode( $payload2_json, true );
	$assert( ( $payload2['context']['user']['moderator'] ?? '' ) === 'false',
	         'moderator=false produces string "false"' );
} else {
	$assert( false, 'second mint() returned a 3-segment JWT' );
}

if ( 0 === $fails ) { echo "\n=== SUCCESS === all JWT shape + signature roundtrip assertions PASS\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed\n"; }
