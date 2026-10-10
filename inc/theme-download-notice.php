<?php
/**
 * wordpress.org build: dismissible link to the GenD Society theme download
 * on gend.me. Inert when the theme bundle (inc/theme-bundle.php) is present.
 *
 * Filled by plan 106-05. Contract stub from 106-01: final signatures, safe
 * no-op bodies. Manifest: tier customer, needs ['standalone'].
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_theme_download_url' ) ) {
	/**
	 * Theme download page on gend.me.
	 */
	function gend_society_theme_download_url(): string {
		return (string) apply_filters( 'gend_society_theme_download_url', 'https://gend.me/gend-society-theme/' );
	}
}

if ( ! function_exists( 'gend_society_theme_download_render_panel' ) ) {
	/**
	 * Echo the theme download panel (GenD page, wordpress.org build).
	 */
	function gend_society_theme_download_render_panel(): void {
	}
}
