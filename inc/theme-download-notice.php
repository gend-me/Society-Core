<?php
/**
 * wordpress.org build: dismissible link to the GenD Society theme download
 * on gend.me. Inert when the theme bundle (inc/theme-bundle.php) is present.
 *
 * The wordpress.org build ships no theme and installs nothing: this module
 * only links to the download page. It makes no remote request and loads no
 * remote asset. Manifest: tier customer, needs ['standalone'].
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION' ) ) {
	define( 'GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION', 'gend_society_dismiss_theme_download' );
}

if ( ! function_exists( 'gend_society_theme_download_url' ) ) {
	/**
	 * Theme download page on gend.me.
	 */
	function gend_society_theme_download_url(): string {
		return (string) apply_filters( 'gend_society_theme_download_url', 'https://gend.me/gend-society-theme/' );
	}
}

if ( ! function_exists( 'gend_society_theme_download_text' ) ) {
	/**
	 * Explanation shown in the notice and on the GenD page.
	 */
	function gend_society_theme_download_text(): string {
		return __( 'The GenD Society theme is a free download from gend.me. This plugin does not install themes; download the zip and upload it under Appearance > Themes > Add New.', 'gend-society' );
	}
}

if ( ! function_exists( 'gend_society_theme_download_link_html' ) ) {
	/**
	 * Button link to the download page (opens in a new tab).
	 */
	function gend_society_theme_download_link_html(): string {
		return sprintf(
			'<a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( gend_society_theme_download_url() ),
			esc_html__( 'Download the GenD Society theme', 'gend-society' )
		);
	}
}

if ( ! function_exists( 'gend_society_theme_download_render_panel' ) ) {
	/**
	 * Echo the theme download panel (GenD page, wordpress.org build).
	 * No image from gend.me: nothing remote is loaded before consent.
	 */
	function gend_society_theme_download_render_panel(): void {
		if ( function_exists( 'gend_society_theme_is_active' ) || ! current_user_can( 'switch_themes' ) ) {
			return;
		}
		echo '<div class="card gend-society-theme-download-panel" style="max-width:640px">';
		echo '<h2>' . esc_html__( 'GenD Society theme', 'gend-society' ) . '</h2>';
		echo '<p>' . esc_html( gend_society_theme_download_text() ) . '</p>';
		echo '<p>' . gend_society_theme_download_link_html() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_theme_download_dismissed' ) ) {
	/**
	 * Whether the themes-screen notice was dismissed on this site.
	 */
	function gend_society_theme_download_dismissed(): bool {
		return (bool) get_option( 'gend_society_theme_download_dismissed' );
	}
}

if ( ! function_exists( 'gend_society_theme_download_boot' ) ) {
	/**
	 * Register the notice hooks, unless the theme bundle is present (full
	 * build or source tree), which offers the theme card instead.
	 */
	function gend_society_theme_download_boot(): void {
		if ( function_exists( 'gend_society_theme_is_active' ) ) {
			return;
		}
		add_action( 'admin_notices', 'gend_society_theme_download_notice' );
		add_action( 'admin_post_' . GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION, 'gend_society_theme_download_handle_dismiss' );
		add_action( 'wp_ajax_' . GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION, 'gend_society_theme_download_ajax_dismiss' );
	}
}
add_action( 'plugins_loaded', 'gend_society_theme_download_boot' );

if ( ! function_exists( 'gend_society_theme_download_notice' ) ) {
	/**
	 * Dismissible notice, Appearance > Themes only.
	 */
	function gend_society_theme_download_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'themes' !== $screen->id || ! current_user_can( 'switch_themes' ) || gend_society_theme_download_dismissed() ) {
			return;
		}
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION ),
			GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION
		);
		printf(
			'<div class="notice notice-info is-dismissible gend-society-theme-download"><p>%s</p><p>%s <a class="button" href="%s">%s</a></p></div>',
			esc_html( gend_society_theme_download_text() ),
			gend_society_theme_download_link_html(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'gend-society' )
		);

		// Persist the core dismiss X too.
		$script = sprintf(
			'jQuery(function($){$(document).on("click",".gend-society-theme-download .notice-dismiss",function(){$.post(ajaxurl,{action:%s,_ajax_nonce:%s});});});',
			wp_json_encode( GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION ),
			wp_json_encode( wp_create_nonce( GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION ) )
		);
		wp_add_inline_script( 'common', $script );
	}
}

if ( ! function_exists( 'gend_society_theme_download_handle_dismiss' ) ) {
	/**
	 * Admin-post dismiss (no-JS path), back to the themes screen.
	 */
	function gend_society_theme_download_handle_dismiss(): void {
		check_admin_referer( GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION );
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		update_option( 'gend_society_theme_download_dismissed', time(), false );
		wp_safe_redirect( admin_url( 'themes.php' ) );
		exit;
	}
}

if ( ! function_exists( 'gend_society_theme_download_ajax_dismiss' ) ) {
	/**
	 * AJAX dismiss bound to the core notice X.
	 */
	function gend_society_theme_download_ajax_dismiss(): void {
		check_ajax_referer( GEND_SOCIETY_THEME_DOWNLOAD_DISMISS_ACTION );
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_send_json_error( null, 403 );
		}
		update_option( 'gend_society_theme_download_dismissed', time(), false );
		wp_send_json_success();
	}
}
