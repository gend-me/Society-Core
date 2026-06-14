<?php
/**
 * Phase 30 / Plan 30-04 — UAT 5: tampered token rejected
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-tampered-token.php
 *
 * Mints a valid JWT, flips one char of each segment, asserts verify_signature() returns false for each tamper.
 * This is what prosody mod_token_verification does at the meet.gend.me layer when a tampered token is presented.
 * Token-less = no signature = rejected; wrong-room = different room claim = different signature = rejected.
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 5: tampered token rejection ===\n";

$assert( class_exists( 'Gend_GS_Jitsi_JWT' ), 'Gend_GS_Jitsi_JWT class loaded' );
if ( ! class_exists( 'Gend_GS_Jitsi_JWT' ) ) { echo "ABORT: class missing\n"; echo "\n=== FAIL === $fails assertion(s) failed\n"; return; }

$secret = Gend_GS_Jitsi_JWT::get_secret();
if ( is_wp_error( $secret ) ) {
	echo "FAIL: jwt_secret_missing — operator must add GS_JITSI_JWT_SECRET to wp-config.php (Step B of the deploy runbook)\n";
	echo "Error: " . $secret->get_error_message() . "\n";
	echo "\n=== FAIL === GS_JITSI_JWT_SECRET not resolvable\n";
	return;
}

$now = time();
$valid = Gend_GS_Jitsi_JWT::mint( 'gend-meet-1-TamperTestRoom00', 100, 1, 'Test', false, $now - 30, $now + 3600 );
$assert( is_string( $valid ) && '' !== $valid, 'valid JWT minted' );
$assert( Gend_GS_Jitsi_JWT::verify_signature( $valid ) === true, 'valid JWT passes verify_signature' );

$parts = explode( '.', (string) $valid );
if ( count( $parts ) !== 3 ) { echo "ABORT: not a 3-segment JWT\n"; echo "\n=== FAIL === mint did not produce a 3-segment JWT\n"; return; }

// Tamper signature: flip first char
$tamper_sig = ( $parts[2][0] === 'A' ? 'B' : 'A' ) . substr( $parts[2], 1 );
$tampered_sig = $parts[0] . '.' . $parts[1] . '.' . $tamper_sig;
$assert( Gend_GS_Jitsi_JWT::verify_signature( $tampered_sig ) === false,
         'tampered-signature JWT REJECTED by verify_signature (hash_equals constant-time)' );

// Tamper payload: flip first char (forces re-encode; sig no longer matches the modified payload)
$tamper_pay = ( $parts[1][0] === 'a' ? 'b' : 'a' ) . substr( $parts[1], 1 );
$tampered_pay = $parts[0] . '.' . $tamper_pay . '.' . $parts[2];
$assert( Gend_GS_Jitsi_JWT::verify_signature( $tampered_pay ) === false,
         'tampered-payload JWT REJECTED by verify_signature' );

// Tamper header
$tamper_hdr = ( $parts[0][0] === 'e' ? 'f' : 'e' ) . substr( $parts[0], 1 );
$tampered_hdr = $tamper_hdr . '.' . $parts[1] . '.' . $parts[2];
$assert( Gend_GS_Jitsi_JWT::verify_signature( $tampered_hdr ) === false,
         'tampered-header JWT REJECTED by verify_signature' );

// Wrong-segment-count
$assert( Gend_GS_Jitsi_JWT::verify_signature( $parts[0] . '.' . $parts[1] ) === false,
         '2-segment JWT (missing sig) REJECTED' );
$assert( Gend_GS_Jitsi_JWT::verify_signature( 'not.a.jwt.at.all' ) === false,
         '5-segment garbage REJECTED' );
$assert( Gend_GS_Jitsi_JWT::verify_signature( '' ) === false,
         'empty string REJECTED' );

if ( 0 === $fails ) { echo "\n=== SUCCESS === tampered token rejection works (signature + payload + header + segment-count)\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed\n"; }
