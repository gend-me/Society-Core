<?php
/**
 * App wording: rename the words a live app section shows.
 *
 * Group content (gdc-blocks/endpoint-panel) is a live app: its markup comes
 * from the app on every request, so it can't become editable HTML. Instead
 * the block keeps a list of "shown text => your text" (attribute gsTexts),
 * edited in the block's Wording panel, and every text node or placeholder
 * whose whole text matches is swapped when the block renders. The app keeps
 * working; text the app adds later with JavaScript is not covered.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block names whose wording can be changed.
 *
 * @return string[]
 */
function gend_society_aw_blocks() {
	return (array) apply_filters( 'gend_society_app_wording_blocks', array( 'gdc-blocks/endpoint-panel' ) );
}

/**
 * Cleans a wording map.
 *
 * @param mixed $texts Raw map.
 * @return array<string,string>
 */
function gend_society_aw_clean( $texts ) {
	$out = array();
	foreach ( is_array( $texts ) ? array_slice( $texts, 0, 200, true ) : array() as $from => $to ) {
		$from = trim( sanitize_text_field( (string) $from ) );
		$to   = sanitize_text_field( (string) $to );
		if ( '' !== $from && '' !== trim( $to ) && $from !== $to ) {
			$out[ $from ] = mb_substr( $to, 0, 200 );
		}
	}
	return $out;
}

/**
 * Swaps whole text nodes and placeholders (scripts and styles untouched).
 *
 * @param string $html  Markup.
 * @param array  $texts Shown text => new text.
 * @return string
 */
function gend_society_aw_apply( $html, $texts ) {
	$texts = gend_society_aw_clean( $texts );
	if ( ! $texts || '' === $html ) {
		return $html;
	}
	$parts = preg_split( '#(<(script|style|textarea)\b.*?</\2>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out   = '';
	for ( $i = 0, $n = count( $parts ); $i < $n; $i++ ) {
		$part = $parts[ $i ];
		if ( preg_match( '#^<(script|style|textarea)\b#i', $part ) ) {
			$out .= $part;
			++$i; // Skip the captured tag name.
			continue;
		}
		$part = preg_replace_callback(
			'#>([^<]+)<#',
			static function ( $m ) use ( $texts ) {
				$text = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $text || ! isset( $texts[ $text ] ) ) {
					return $m[0];
				}
				preg_match( '#^(\s*).*?(\s*)$#s', $m[1], $ws );
				return '>' . $ws[1] . esc_html( $texts[ $text ] ) . $ws[2] . '<';
			},
			$part
		);
		$part = preg_replace_callback(
			'#\splaceholder="([^"]*)"#',
			static function ( $m ) use ( $texts ) {
				$text = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				return isset( $texts[ $text ] ) ? ' placeholder="' . esc_attr( $texts[ $text ] ) . '"' : $m[0];
			},
			$part
		);
		$out .= $part;
	}
	return $out;
}

add_filter( 'register_block_type_args', 'gend_society_aw_block_args', 10, 2 );
/**
 * Adds the gsTexts attribute.
 *
 * @param array  $args Block type args.
 * @param string $name Block name.
 * @return array
 */
function gend_society_aw_block_args( $args, $name ) {
	if ( in_array( $name, gend_society_aw_blocks(), true ) ) {
		$args['attributes']            = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();
		$args['attributes']['gsTexts'] = array(
			'type'    => 'object',
			'default' => array(),
		);
	}
	return $args;
}

add_filter( 'render_block', 'gend_society_aw_render', 20, 2 );
/**
 * Applies a block's wording.
 *
 * @param string $html  Rendered block.
 * @param array  $block Parsed block.
 * @return string
 */
function gend_society_aw_render( $html, $block ) {
	if ( empty( $block['attrs']['gsTexts'] ) || ! in_array( $block['blockName'], gend_society_aw_blocks(), true ) ) {
		return $html;
	}
	return gend_society_aw_apply( $html, $block['attrs']['gsTexts'] );
}

/**
 * The wording a studio preview asks for (?gs_texts=JSON, editors only).
 *
 * @return array
 */
function gend_society_aw_preview_texts() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for site editors.
	if ( empty( $_GET['gs_texts'] ) || ! current_user_can( 'edit_theme_options' ) ) {
		return array();
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, cleaned below.
	return gend_society_aw_clean( json_decode( wp_unslash( $_GET['gs_texts'] ), true ) );
}
