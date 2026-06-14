<?php
/**
 * Phase 30 / Plan 30-04 — UAT 6: NO recording anywhere
 * Run: kubectl exec -n wp-hub deploy/wordpress -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/30-04-uat-no-recording.php
 *
 * Scans the gend-society Phase 30 files for any jibri/recording/livestreaming reference.
 * v7.0 Out-of-Scope — Canadian two-party consent law. PR-guard: zero matches expected.
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

$fails = 0;
$assert = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
};

echo "=== Phase 30-04 UAT 6: recording PR-guard (zero jibri/recording/livestreaming) ===\n";

$plugin_dir = defined( 'GS_DIR' ) ? GS_DIR : WP_CONTENT_DIR . '/plugins/gend-society/';
$plugin_dir = rtrim( $plugin_dir, '/' ) . '/';

$scan = function ( $rel_path, $needles ) use ( $plugin_dir, $assert ) {
	$full = $plugin_dir . $rel_path;
	if ( ! file_exists( $full ) ) {
		$assert( false, "$rel_path EXISTS (file missing — deploy issue, not a recording leak)" );
		return;
	}
	$contents = file_get_contents( $full );
	foreach ( $needles as $needle ) {
		$found = ( stripos( $contents, $needle ) !== false );
		$assert( ! $found, "$rel_path does NOT contain '$needle' (case-insensitive)" );
	}
};

$forbidden = array( 'jibri', 'enableRecording', 'enable_recording', 'startRecording', "'recording'", '"recording"', 'livestreaming' );

$scan( 'inc/class-jitsi-jwt.php',  $forbidden );
$scan( 'inc/class-jitsi-rest.php', $forbidden );
$scan( 'assets/jitsi-embed.js',    $forbidden );
$scan( 'assets/jitsi-embed.css',   $forbidden );

// Additionally: assert TOOLBAR_BUTTONS in jitsi-embed.js explicitly EXCLUDES recording
$embed_js = $plugin_dir . 'assets/jitsi-embed.js';
if ( file_exists( $embed_js ) ) {
	$js = file_get_contents( $embed_js );
	$has_toolbar = stripos( $js, 'TOOLBAR_BUTTONS' ) !== false;
	$assert( $has_toolbar, 'jitsi-embed.js sets TOOLBAR_BUTTONS (explicit allowlist)' );
	$assert( stripos( $js, "'recording'" ) === false && stripos( $js, '"recording"' ) === false,
	         'TOOLBAR_BUTTONS does NOT include recording' );
	$assert( stripos( $js, "'livestreaming'" ) === false && stripos( $js, '"livestreaming"' ) === false,
	         'TOOLBAR_BUTTONS does NOT include livestreaming' );
}

if ( 0 === $fails ) { echo "\n=== SUCCESS === zero jibri/recording/livestreaming references anywhere in Phase 30 WP code\n"; }
else                { echo "\n=== FAIL === $fails assertion(s) failed (recording leak — PR-guard violated)\n"; }
