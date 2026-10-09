<?php
/**
 * Native gend video embed assets (jitsi-embed.{js,css}) on the member's own
 * BuddyPress profile (Phase 30-03, VID-04 + VID-05). Moved verbatim out of
 * gend-society.php into its own module (Phase 104); loaded by the manifest at
 * the position the inline block used to occupy.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Phase 30 Plan 30-03 — Native gend video embed assets (jitsi-embed.{js,css}),
// broader BP-profile enqueue surface (VID-04 + VID-05). member-calendar.php
// already enqueues gs-jitsi-embed on the calendar tab; this block ALSO enqueues
// it across the member's own profile so the wallet meeting feed + the Phase 29
// booking confirmation page get the Join-button embed controller too. The handle
// is identical so wp_enqueue_script/style + wp_localize_script dedupe — calling
// from both sites is safe (WP keys by handle; last localize wins, both write the
// same data). file_exists-guarded — Pitfall 1 (.no-plugin-sync hub PVC quirk):
// the 2 NEW asset files MUST be kubectl cp'd to the PVC BEFORE this entrypoint
// edit. Priority 20 so it runs after the theme/Youzify base enqueues.
if ( file_exists( GEND_SOCIETY_DIR . 'assets/jitsi-embed.js' ) && file_exists( GEND_SOCIETY_DIR . 'assets/jitsi-embed.css' ) ) {
	add_action( 'wp_enqueue_scripts', static function () {
		if ( ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
			return;
		}
		$gs_jitsi_js_path  = GEND_SOCIETY_DIR . 'assets/jitsi-embed.js';
		$gs_jitsi_css_path = GEND_SOCIETY_DIR . 'assets/jitsi-embed.css';
		$gs_jitsi_js_ver   = GEND_SOCIETY_VERSION . '.' . filemtime( $gs_jitsi_js_path );
		$gs_jitsi_css_ver  = GEND_SOCIETY_VERSION . '.' . filemtime( $gs_jitsi_css_path );
		wp_enqueue_script(
			'gs-jitsi-embed',
			GEND_SOCIETY_URL . 'assets/jitsi-embed.js',
			array(),
			$gs_jitsi_js_ver,
			true
		);
		wp_enqueue_style(
			'gs-jitsi-embed',
			GEND_SOCIETY_URL . 'assets/jitsi-embed.css',
			array(),
			$gs_jitsi_css_ver
		);
		wp_localize_script( 'gs-jitsi-embed', 'gsJitsiData', array(
			'restUrl' => esc_url_raw( rest_url( 'gs/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'domain'  => apply_filters( 'gend_society_jitsi_domain', 'meet.gend.me' ),
		) );
	}, 20 );
}
