<?php
/**
 * Brand font roles.
 *
 * Global Styles name a font per element (headings, body, buttons, the Code
 * block) but print no variable a page's own CSS can follow. This prints the
 * four roles as variables, from the site's Global Styles (Design Studio ›
 * Font Types), so hand-written CSS in custom HTML blocks can use
 * var(--gs-font-heading) and change with the rest of the site:
 *   --gs-font-heading  All headings
 *   --gs-font-body     Paragraph (the site's text)
 *   --gs-font-button   Buttons
 *   --gs-font-mono     Labels & code (the Code block)
 *
 * @package GendSociety
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a theme.json value ("var:preset|font-family|x") into CSS.
 *
 * @param mixed $value Raw value.
 * @return string CSS value, or '' when unset.
 */
function gend_society_bt_css_value( $value ) {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return '';
	}
	$value = trim( $value );
	if ( 0 === strpos( $value, 'var:' ) ) {
		$value = 'var(--wp--' . str_replace( '|', '--', substr( $value, 4 ) ) . ')';
	}
	// One declaration value: never let it close the rule or the <style> element.
	return preg_replace( '/[;{}<>]/', '', $value );
}

/**
 * The font roles' CSS for a theme.json tree.
 *
 * @param array $styles Raw "styles" of the merged theme.json data.
 * @return string
 */
function gend_society_bt_role_css( $styles ) {
	$get = function ( $path ) use ( $styles ) {
		$v = $styles;
		foreach ( $path as $k ) {
			if ( ! is_array( $v ) || ! isset( $v[ $k ] ) ) {
				return '';
			}
			$v = $v[ $k ];
		}
		return gend_society_bt_css_value( $v );
	};
	$body    = $get( array( 'typography', 'fontFamily' ) );
	$heading = $get( array( 'elements', 'heading', 'typography', 'fontFamily' ) );
	$button  = $get( array( 'elements', 'button', 'typography', 'fontFamily' ) );
	$mono    = $get( array( 'blocks', 'core/code', 'typography', 'fontFamily' ) );
	$body    = $body ? $body : 'inherit';
	$vars    = array(
		'--gs-font-body'    => $body,
		'--gs-font-heading' => $heading ? $heading : $body,
		'--gs-font-button'  => $button ? $button : $body,
		'--gs-font-mono'    => $mono ? $mono : 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
	);
	$css = '';
	foreach ( $vars as $k => $v ) {
		$css .= $k . ':' . $v . ';';
	}
	return ':root{' . $css . '}';
}

/**
 * The roles for the site as saved.
 *
 * @return string
 */
function gend_society_bt_site_css() {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return '';
	}
	$raw = WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data();
	return gend_society_bt_role_css( isset( $raw['styles'] ) ? $raw['styles'] : array() );
}

add_action( 'wp_enqueue_scripts', 'gend_society_bt_enqueue', 20 );
/**
 * Front end: after the global styles.
 */
function gend_society_bt_enqueue() {
	$css = gend_society_bt_site_css();
	if ( '' === $css ) {
		return;
	}
	if ( wp_style_is( 'global-styles', 'enqueued' ) ) {
		wp_add_inline_style( 'global-styles', $css );
		return;
	}
	wp_register_style( 'gend-society-brand-tokens', false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
	wp_enqueue_style( 'gend-society-brand-tokens' );
	wp_add_inline_style( 'gend-society-brand-tokens', $css );
}

add_filter( 'block_editor_settings_all', 'gend_society_bt_editor_styles', 20 );
/**
 * Block editors (canvas styles), so custom HTML previews use the same fonts.
 *
 * @param array $settings Editor settings.
 * @return array
 */
function gend_society_bt_editor_styles( $settings ) {
	$css = gend_society_bt_site_css();
	if ( '' !== $css ) {
		$settings['styles']   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
		$settings['styles'][] = array( 'css' => $css );
	}
	return $settings;
}

add_filter( 'the_generator', '__return_empty_string' );

add_action( 'wp_enqueue_scripts', 'gend_society_bt_built_pages_css', 21 );
/**
 * The look of pages built through the chat (one HTML block of plain sections). A stylesheet rather than a style tag,
 * which WordPress strips from a sub-site admin's content.
 */
function gend_society_bt_built_pages_css() {
	$post = is_singular() ? get_post() : null;
	if ( ! $post || false === strpos( (string) $post->post_content, 'gs-page' ) ) {
		return;
	}
	gend_society_bt_enqueue_built_pages();
	// A scene behind a section is plain markup, so WordPress doesn't know to bring the block's files.
	if ( false !== strpos( (string) $post->post_content, 'gss--section' ) ) {
		wp_enqueue_style( 'gend-society-scroll-scene' );
		wp_enqueue_script( 'gend-society-scroll-scene' );
	}
}

function gend_society_bt_clean_style_tokens( array $tokens ) {
	$rules = array(
		'radius'       => '/^\d{1,3}px$/',
		'buttonRadius' => '/^\d{1,3}px$/',
		'space'        => '/^\d?\.?\d{1,2}$/',
		'entrance'     => '/^(gs-rise|gs-fade|none)$/',
		'blur'         => '/^\d{1,2}px$/',
		'glass'        => '/^\d{1,3}%$/',
		'tilt'         => '/^\d{1,2}deg$/',
		'headerPosition' => '/^(sticky|static)$/',
	);
	$clean = array();
	foreach ( $rules as $key => $rule ) {
		if ( isset( $tokens[ $key ] ) && is_string( $tokens[ $key ] ) && preg_match( $rule, $tokens[ $key ] ) ) {
			$clean[ $key ] = $tokens[ $key ];
		}
	}
	return $clean;
}

function gend_society_bt_style_tokens() {
	return gend_society_bt_clean_style_tokens( (array) get_option( 'gend_society_style_tokens', array() ) );
}

/**
 * A site's own styling, written through the chat, or '' when it isn't acceptable. No way out of the style tag and nothing
 * that loads from elsewhere: a sub-site admin can't normally add CSS at all, so this stays narrower than a stylesheet.
 */
function gend_society_bt_clean_css( $css ) {
	$css = trim( (string) preg_replace( '~/\*.*?\*/~s', '', (string) $css ) );
	if ( '' === $css || strlen( $css ) > 8000 || substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
		return '';
	}
	return preg_match( '~url\s*\(|@import|@charset|@namespace|@font-face|expression\s*\(|javascript:|behavior\s*:|-moz-binding|image-set|<|\\\\~i', $css ) ? '' : $css;
}

function gend_society_bt_enqueue_built_pages() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_enqueue_style( 'gend-society-built-pages', GEND_SOCIETY_URL . 'assets/built-pages.css', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/built-pages.css' ) );
	wp_enqueue_script( 'gend-society-built-pages', GEND_SOCIETY_URL . 'assets/built-pages.js', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/built-pages.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	$vars = '';
	foreach ( gend_society_bt_style_tokens() as $key => $value ) {
		$vars .= '--wp--custom--gs--' . _wp_to_kebab_case( $key ) . ':' . $value . ';';
	}
	if ( '' !== $vars ) {
		wp_add_inline_style( 'gend-society-built-pages', ':root{' . $vars . '}' );
	}
	foreach ( (array) get_option( 'gend_society_site_css', array() ) as $css ) {
		$css = gend_society_bt_clean_css( $css );
		if ( '' !== $css ) {
			wp_add_inline_style( 'gend-society-built-pages', $css );
		}
	}
}

add_filter( 'render_block_core/group', 'gend_society_bt_built_chrome_css', 10, 2 );
/** A built header or footer shows on every page, built or not. A block theme renders its template before wp_head, so this is in time. */
function gend_society_bt_built_chrome_css( $html, $block ) {
	$class = (string) ( $block['attrs']['className'] ?? '' );
	if ( false !== strpos( $class, 'gs-site-header' ) || false !== strpos( $class, 'gs-site-footer' ) ) {
		gend_society_bt_enqueue_built_pages();
	}
	return $html;
}
