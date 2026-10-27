<?php
/**
 * Scroll Scene block: a full-width background that animates as the visitor
 * scrolls — one image (zoom, pan, parallax, blur-in) or several images that
 * cross-fade into each other — with any blocks on top of it.
 *
 * Dynamic block: the post stores only attributes (media ids, effects) plus
 * the inner content, so pages written by the GenD desktop app or an AI agent
 * never fail block validation, and every image stays a real media-library
 * attachment the editor can replace. The front script is a few lines of
 * vanilla JS and only loads on pages that use the block.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'gend_society_scroll_scene_register' );

/**
 * Registers the block, its editor script, front script and style.
 *
 * @return void
 */
function gend_society_scroll_scene_register() {
	$editor = 'assets/scroll-scene-editor.js';
	$view   = 'assets/scroll-scene.js';
	$style  = 'assets/scroll-scene.css';
	wp_register_script( 'gend-society-scroll-scene-editor', GEND_SOCIETY_URL . $editor, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $editor ), true );
	wp_register_script( 'gend-society-scroll-scene', GEND_SOCIETY_URL . $view, array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $view ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_register_style( 'gend-society-scroll-scene', GEND_SOCIETY_URL . $style, array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $style ) );
	wp_set_script_translations( 'gend-society-scroll-scene-editor', 'gend-society' );

	register_block_type(
		'gend-society/scroll-scene',
		array(
			'api_version'     => 3,
			'title'           => __( 'Scroll Scene', 'gend-society' ),
			'description'     => __( 'A background that animates as visitors scroll: one image that zooms, pans or drifts, or several images that fade into each other.', 'gend-society' ),
			'category'        => 'media',
			'icon'            => 'images-alt2',
			'keywords'        => array( 'scroll', 'parallax', 'animation', 'background', 'hero' ),
			'editor_script'   => 'gend-society-scroll-scene-editor',
			'view_script'     => 'gend-society-scroll-scene',
			'style'           => 'gend-society-scroll-scene',
			'render_callback' => 'gend_society_scroll_scene_render',
			'attributes'      => array(
				'layers'         => array(
					'type'    => 'array',
					'default' => array(),
				),
				'height'         => array(
					'type'    => 'number',
					'default' => 300,
				),
				'overlay'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'overlayOpacity' => array(
					'type'    => 'number',
					'default' => 35,
				),
				'contentAlign'   => array(
					'type'    => 'string',
					'default' => 'center',
				),
				'align'          => array(
					'type'    => 'string',
					'default' => 'full',
				),
			),
			'supports'        => array(
				'align'  => array( 'wide', 'full' ),
				'anchor' => true,
				'html'   => false,
			),
		)
	);
}

/**
 * Effects a layer can use.
 *
 * @return string[]
 */
function gend_society_scroll_scene_effects() {
	return array( 'zoom-in', 'zoom-out', 'pan-left', 'pan-right', 'pan-up', 'parallax', 'blur-in', 'none' );
}

/**
 * Colour value from a theme preset slug or a hex colour.
 *
 * @param string $value Slug (e.g. "contrast") or hex.
 * @return string CSS colour, or '' when unusable.
 */
function gend_society_scroll_scene_color( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$hex = sanitize_hex_color( $value );
	if ( $hex ) {
		return $hex;
	}
	if ( preg_match( '/^[a-z0-9-]{1,60}$/', $value ) ) {
		return 'var(--wp--preset--color--' . $value . ')';
	}
	return '';
}

/**
 * Renders the block.
 *
 * @param array  $attributes Block attributes.
 * @param string $content    Inner blocks (the content shown on top).
 * @return string
 */
function gend_society_scroll_scene_render( $attributes, $content ) {
	$layers = isset( $attributes['layers'] ) && is_array( $attributes['layers'] ) ? array_slice( $attributes['layers'], 0, 12 ) : array();
	if ( ! $layers ) {
		return '';
	}
	$height  = max( 100, min( 1200, (int) ( $attributes['height'] ?? 300 ) ) );
	$effects = gend_society_scroll_scene_effects();
	$out     = '';
	foreach ( $layers as $i => $layer ) {
		$id     = isset( $layer['id'] ) ? absint( $layer['id'] ) : 0;
		$url    = isset( $layer['url'] ) ? esc_url( $layer['url'] ) : '';
		$alt    = isset( $layer['alt'] ) ? (string) $layer['alt'] : '';
		$effect = isset( $layer['effect'] ) && in_array( $layer['effect'], $effects, true ) ? $layer['effect'] : 'zoom-in';
		$img    = '';
		if ( $id && wp_attachment_is_image( $id ) ) {
			$img = wp_get_attachment_image(
				$id,
				'full',
				false,
				array(
					'class'         => 'gss-img wp-image-' . $id,
					'alt'           => '' !== $alt ? $alt : (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'sizes'         => '100vw',
					'loading'       => 0 === $i ? 'eager' : 'lazy',
					'fetchpriority' => 0 === $i ? 'high' : 'auto',
					'decoding'      => 'async',
				)
			);
		}
		if ( ! $img && $url ) {
			$img = sprintf( '<img class="gss-img" src="%s" alt="%s" loading="%s" decoding="async" />', $url, esc_attr( $alt ), 0 === $i ? 'eager' : 'lazy' );
		}
		if ( ! $img ) {
			continue;
		}
		$out .= sprintf( '<div class="gss-layer" data-effect="%s">%s</div>', esc_attr( $effect ), $img );
	}
	if ( '' === $out ) {
		return '';
	}
	$tint    = gend_society_scroll_scene_color( $attributes['overlay'] ?? '' );
	$opacity = max( 0, min( 95, (int) ( $attributes['overlayOpacity'] ?? 35 ) ) ) / 100;
	if ( $tint && $opacity > 0 ) {
		$out .= sprintf( '<div class="gss-tint" style="%s" aria-hidden="true"></div>', esc_attr( 'background:' . $tint . ';opacity:' . $opacity ) );
	}
	$align = in_array( $attributes['contentAlign'] ?? 'center', array( 'top', 'center', 'bottom' ), true ) ? $attributes['contentAlign'] : 'center';
	if ( trim( wp_strip_all_tags( (string) $content, true ) ) !== '' || false !== strpos( (string) $content, '<img' ) ) {
		$out .= '<div class="gss-content gss-content--' . esc_attr( $align ) . '">' . $content . '</div>';
	}
	$wrapper = get_block_wrapper_attributes(
		array(
			'class' => 'gss',
			'style' => '--gss-h:' . $height . 'vh',
		)
	);
	return '<div ' . $wrapper . '><div class="gss-sticky">' . $out . '</div></div>';
}
