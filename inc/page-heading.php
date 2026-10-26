<?php
/**
 * Page basics every front-end page should have:
 *
 * 1. One H1. Designed pages (shop, cart, blog, directories) often show their
 *    title as artwork or a hero, with no H1 element. When a page renders
 *    without one, a visually hidden H1 with the page's title is added right
 *    after the site header, for screen readers and search engines. Pages
 *    that already have an H1 are left as they are.
 * 2. The permalink base itself (e.g. /society/ when posts live at
 *    /society/post-name/) goes to the blog page instead of a 404.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this request is a normal front-end page view.
 *
 * @return bool
 */
function gend_society_ph_is_page_view() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_robots() || is_favicon() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flags only.
	foreach ( array( 'gs_studio_embed', 'gs_studio_view', 'gs_studio_specimen', 'gs_studio_shortcode', 'gdc_tab_only', 'gs_embed', 'gdc_embed', 'gdc_wc_embed' ) as $flag ) {
		if ( isset( $_GET[ $flag ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flags only.
			return false;
		}
	}
	return true;
}

add_action( 'template_redirect', 'gend_society_ph_base_redirect', 5 );
/**
 * /<permalink base>/ (a 404 on its own) goes to the blog page.
 */
function gend_society_ph_base_redirect() {
	if ( ! is_404() || ! gend_society_ph_is_page_view() ) {
		return;
	}
	global $wp_rewrite;
	$front = trim( (string) $wp_rewrite->front, '/' );
	if ( '' === $front || false !== strpos( $front, '%' ) ) {
		return;
	}
	$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH ), '/' );
	$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
		$path = substr( $path, strlen( $home ) + 1 );
	}
	if ( $path !== $front ) {
		return;
	}
	$blog = (int) get_option( 'page_for_posts' );
	$to   = $blog && 'publish' === get_post_status( $blog ) ? get_permalink( $blog ) : home_url( '/' );
	if ( $to ) {
		wp_safe_redirect( $to, 301, 'gend-society' );
		exit;
	}
}

add_action( 'template_redirect', 'gend_society_ph_buffer', 2 );
/**
 * Watches the page output for a missing H1.
 */
function gend_society_ph_buffer() {
	if ( ! gend_society_ph_is_page_view() || is_404() ) {
		return;
	}
	ob_start( 'gend_society_ph_add_heading' );
}

/**
 * The title an H1 should carry on this page.
 *
 * @return string
 */
function gend_society_ph_title() {
	if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'woocommerce_page_title' ) ) {
		return (string) woocommerce_page_title( false );
	}
	if ( is_home() ) {
		$blog = (int) get_option( 'page_for_posts' );
		return $blog ? get_the_title( $blog ) : __( 'Blog', 'gend-society' );
	}
	if ( is_search() ) {
		/* translators: %s: search terms */
		return sprintf( __( 'Search results for “%s”', 'gend-society' ), get_search_query() );
	}
	if ( is_archive() ) {
		return wp_strip_all_tags( get_the_archive_title() );
	}
	if ( is_singular() || is_page() ) {
		$obj = get_queried_object();
		if ( $obj instanceof WP_Post ) {
			return get_the_title( $obj );
		}
	}
	return get_bloginfo( 'name' );
}

/**
 * Adds a visually hidden H1 after the site header when the page has none.
 *
 * @param string $html Page output.
 * @return string
 */
function gend_society_ph_add_heading( $html ) {
	if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<body' ) ) {
		return $html;
	}
	foreach ( headers_list() as $h ) {
		if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'html' ) ) {
			return $html;
		}
	}
	$body = substr( $html, stripos( $html, '<body' ) );
	$body = preg_replace( '#<(script|style|template|noscript)\b.*?</\1>#is', '', $body );
	if ( preg_match( '#<h1[\s>]#i', $body ) ) {
		return $html;
	}
	$title = trim( (string) gend_society_ph_title() );
	if ( '' === $title ) {
		return $html;
	}
	$h1 = '<h1 class="gs-page-title screen-reader-text" style="position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">' . esc_html( $title ) . '</h1>';
	// Right after the site header (the first </header> after <body>), else at the start of the body.
	$start = stripos( $html, '<body' );
	$end   = stripos( $html, '</header>', $start );
	if ( false !== $end ) {
		return substr_replace( $html, $h1, $end + 9, 0 );
	}
	$open = strpos( $html, '>', $start );
	return false === $open ? $html : substr_replace( $html, $h1, $open + 1, 0 );
}
