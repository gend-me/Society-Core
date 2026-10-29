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
