<?php
/**
 * GenD Society functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

if ( ! function_exists( 'gend_society_theme_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function gend_society_theme_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'gend_society_theme_post_format_setup' );

// Declares WooCommerce support so WC enqueues its block frontend scripts
// (mini-cart, cart, checkout) on every page.
if ( ! function_exists( 'gend_society_theme_woocommerce_support' ) ) :
	/**
	 * Adds WooCommerce theme support.
	 *
	 * @return void
	 */
	function gend_society_theme_woocommerce_support() {
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}
endif;
add_action( 'after_setup_theme', 'gend_society_theme_woocommerce_support' );

if ( ! function_exists( 'gend_society_theme_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function gend_society_theme_editor_style() {
		add_editor_style( 'assets/css/editor-style.css' );
	}
endif;
add_action( 'after_setup_theme', 'gend_society_theme_editor_style' );

if ( ! function_exists( 'gend_society_theme_enqueue_styles' ) ) :
	/**
	 * Enqueues the theme stylesheet on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function gend_society_theme_enqueue_styles() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';
		$src    = 'style' . $suffix . '.css';

		wp_enqueue_style(
			'gend-society-theme-style',
			get_parent_theme_file_uri( $src ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
		wp_style_add_data(
			'gend-society-theme-style',
			'path',
			get_parent_theme_file_path( $src )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'gend_society_theme_enqueue_styles' );

if ( ! function_exists( 'gend_society_theme_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function gend_society_theme_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'gend-society-theme' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'gend_society_theme_block_styles' );

if ( ! function_exists( 'gend_society_theme_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function gend_society_theme_pattern_categories() {

		register_block_pattern_category(
			'gend_society_theme_page',
			array(
				'label'       => __( 'Pages', 'gend-society-theme' ),
				'description' => __( 'A collection of full page layouts.', 'gend-society-theme' ),
			)
		);

		register_block_pattern_category(
			'gend_society_theme_post-format',
			array(
				'label'       => __( 'Post formats', 'gend-society-theme' ),
				'description' => __( 'A collection of post format patterns.', 'gend-society-theme' ),
			)
		);
	}
endif;
add_action( 'init', 'gend_society_theme_pattern_categories' );

if ( ! function_exists( 'gend_society_theme_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function gend_society_theme_register_block_bindings() {
		register_block_bindings_source(
			'gend-society-theme/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'gend-society-theme' ),
				'get_value_callback' => 'gend_society_theme_format_binding',
			)
		);
	}
endif;
add_action( 'init', 'gend_society_theme_register_block_bindings' );

if ( ! function_exists( 'gend_society_theme_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function gend_society_theme_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;
