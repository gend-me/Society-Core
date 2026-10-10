<?php
/**
 * GenD page (wp-admin) + first-run welcome notice, standalone installs.
 *
 * Filled by plan 106-04. Contract stub from 106-01: final signatures, safe
 * no-op bodies. Manifest: tier customer, needs ['standalone'].
 *
 * Theme card on this page: call gend_society_theme_card_render() when it
 * exists (full build, inc/theme-bundle.php, filled by 106-05), else
 * gend_society_theme_download_render_panel() (inc/theme-download-notice.php,
 * the wordpress.org build).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_welcome_page_slug' ) ) {
	/**
	 * Admin page slug of the GenD page.
	 */
	function gend_society_welcome_page_slug(): string {
		return 'gend-society';
	}
}

if ( ! function_exists( 'gend_society_welcome_page_url' ) ) {
	/**
	 * Admin URL of the GenD page.
	 */
	function gend_society_welcome_page_url(): string {
		return admin_url( 'admin.php?page=' . gend_society_welcome_page_slug() );
	}
}

if ( ! function_exists( 'gend_society_connect_url' ) ) {
	/**
	 * Where the Connect button goes. Phase 108 swaps the target via the
	 * gend_society_connect_url filter.
	 */
	function gend_society_connect_url(): string {
		return (string) apply_filters( 'gend_society_connect_url', admin_url( 'index.php?page=gs-portal-connect' ) );
	}
}
