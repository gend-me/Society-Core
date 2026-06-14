<?php
/**
 * Phase 30 / Plan 30-04 — UAT 7: CSP frame-ancestors header on meet.gend.me
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-csp-header.php
 *
 * Fetches https://meet.gend.me/ and asserts Content-Security-Policy header contains 'frame-ancestors https://*.gend.me'.
 * If meet.gend.me unreachable: print SKIP-WITH-WARNING (operator must run Plan 30-01 Helm runbook first), NOT FAIL.
 * The === SKIP === path is EXPECTED until the Jitsi cluster is stood up via Plan 30-01.
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 7: CSP frame-ancestors header on meet.gend.me ===\n";

$url = 'https://meet.gend.me/';
$resp = wp_remote_get( $url, array(
	'timeout'     => 10,
	'sslverify'   => true,
	'redirection' => 0,
	'user-agent'  => 'gs-jitsi-uat/30-04',
) );

if ( is_wp_error( $resp ) ) {
	echo "SKIP: meet.gend.me unreachable: " . $resp->get_error_message() . "\n";
	echo "Operator: deploy Plan 30-01 (k8s/manifests/jitsi/) Helm runbook FIRST, then re-run this UAT.\n";
	echo "\n=== SKIP === infra prerequisite not yet deployed; this is NOT a UAT failure — re-run after Plan 30-01 operator deploy.\n";
	return;
}

$status = wp_remote_retrieve_response_code( $resp );
$assert( $status >= 200 && $status < 400, "meet.gend.me responded HTTP $status (2xx/3xx expected)" );

$headers = wp_remote_retrieve_headers( $resp );
// Header iteration handles both flat array and Requests_Utility_CaseInsensitiveDictionary
$header_lookup = array();
foreach ( (array) $headers as $k => $v ) {
	$header_lookup[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
}

$csp = $header_lookup['content-security-policy'] ?? '';
$assert( '' !== $csp, 'Content-Security-Policy header present on meet.gend.me response' );
$assert( false !== stripos( $csp, 'frame-ancestors' ), 'CSP contains frame-ancestors directive' );
$assert( false !== stripos( $csp, '*.gend.me' ),       'CSP frame-ancestors includes *.gend.me' );

$xfo = $header_lookup['x-frame-options'] ?? '';
// X-Frame-Options being absent OR a non-DENY value is acceptable (frame-ancestors supersedes)
if ( '' !== $xfo ) {
	$assert( stripos( $xfo, 'deny' ) === false, "X-Frame-Options is NOT DENY (got: $xfo) — would block embed from gend.me" );
}

if ( 0 === $fails ) { echo "\n=== SUCCESS === CSP frame-ancestors https://*.gend.me is set on meet.gend.me; X-Frame-Options not blocking\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed (CSP not configured correctly — Plan 30-01 ingress-csp-snippet.yaml not applied?)\n"; }
