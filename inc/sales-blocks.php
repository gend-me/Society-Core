<?php
/**
 * Sales blocks: each Sales Team component (affiliate dashboard, tracking link,
 * earnings, commission tables...) as a block that can be placed, arranged and
 * styled in any page or template, instead of a shortcode.
 *
 * A block renders its component with the site's customisation (Design Studio
 * › Sales › Customise) plus its own: colours, sections and wording set in the
 * block sidebar apply to that block only. The editor shows a live preview.
 * See inc/shortcode-customizer.php and assets/sales-blocks.js.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Sales components that are on this site: block slug => shortcode tag.
 *
 * @return string[]
 */
function gend_society_sb_blocks() {
	$tags = array(
		'affiliate-dashboard'        => 'affiliate_sales_dashboard',
		'affiliate-link'             => 'affiliate_link',
		'affiliate-earnings'         => 'affiliate_earnings',
		'referral-team'              => 'referral_team',
		'referred-commissions'       => 'referred_sales_commissions',
		'team-commissions'           => 'team_sales_commissions',
		'withdraw-overview'          => 'withdraw_overview',
		'member-order-commissions'   => 'member_order_commissions',
		'member-product-commissions' => 'member_product_commissions',
	);
	return array_filter( $tags, 'shortcode_exists' );
}

add_filter(
	'block_categories_all',
	function ( $cats ) {
		if ( gend_society_sb_blocks() ) {
			$cats[] = array(
				'slug'  => 'gend-sales',
				'title' => __( 'Sales', 'gend-society' ),
				'icon'  => null,
			);
		}
		return $cats;
	}
);

add_action( 'init', 'gend_society_sb_register', 30 );
/**
 * Registers a block per Sales component.
 */
function gend_society_sb_register() {
	$blocks = gend_society_sb_blocks();
	if ( ! $blocks || ! function_exists( 'gend_society_scx_schema' ) ) {
		return;
	}
	$js = 'assets/sales-blocks.js';
	wp_register_script( 'gend-society-sales-blocks', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-compose' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_set_script_translations( 'gend-society-sales-blocks', 'gend-society' );

	$info   = function_exists( 'gend_society_studio_shortcodes' ) ? gend_society_studio_shortcodes() : array();
	$schema = gend_society_scx_schema();
	$config = array();
	foreach ( $blocks as $slug => $tag ) {
		$name  = 'gend-society/sales-' . $slug;
		$title = isset( $info[ $tag ]['label'] ) ? $info[ $tag ]['label'] : ucwords( str_replace( '_', ' ', $tag ) );
		register_block_type(
			$name,
			array(
				'api_version'     => 3,
				'title'           => $title,
				'description'     => isset( $info[ $tag ]['description'] ) ? $info[ $tag ]['description'] : '',
				'category'        => 'gend-sales',
				'editor_script'   => 'gend-society-sales-blocks',
				'attributes'      => array(
					'custom' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
				'supports'        => array(
					'align'   => array( 'wide', 'full' ),
					'anchor'  => true,
					'spacing' => array(
						'margin'  => true,
						'padding' => true,
					),
				),
				'render_callback' => function ( $attrs ) use ( $tag ) {
					return gend_society_sb_render( $tag, isset( $attrs['custom'] ) ? (array) $attrs['custom'] : array() );
				},
			)
		);
		$colors = array();
		foreach ( isset( $schema[ $tag ]['colors'] ) ? $schema[ $tag ]['colors'] : array() as $k => $c ) {
			$colors[] = array(
				'key'   => $k,
				'label' => $c[0],
			);
		}
		$sections = array();
		foreach ( isset( $schema[ $tag ]['sections'] ) ? $schema[ $tag ]['sections'] : array() as $sec ) {
			$sections[] = array(
				'key'   => $sec['key'],
				'label' => $sec['label'],
			);
		}
		$config[ $name ] = array(
			'title'    => $title,
			'tag'      => $tag,
			'colors'   => $colors,
			'sections' => $sections,
		);
	}
	wp_add_inline_script(
		'gend-society-sales-blocks',
		'window.gsSalesBlocks=' . wp_json_encode(
			array(
				'blocks'     => $config,
				'previewUrl' => home_url( '/' ),
				'studioUrl'  => admin_url( 'admin.php?page=gs-design-studio#/sales' ),
			)
		) . ';',
		'before'
	);
}

/**
 * Renders a component with the site's and the block's customisation.
 *
 * @param string $tag    Shortcode tag.
 * @param array  $custom The block's settings.
 * @param bool   $wrap   Add the block wrapper (false for the editor preview).
 * @return string
 */
function gend_society_sb_render( $tag, $custom, $wrap = true ) {
	static $n = 0;
	++$n;
	$GLOBALS['gend_society_scx_off'] = true;
	$html                            = do_shortcode( '[' . $tag . ']' );
	$GLOBALS['gend_society_scx_off'] = false;
	$settings                        = gend_society_scx_merge( $tag, gend_society_scx_sanitize( $tag, $custom ) );
	$html                            = gend_society_scx_render( $html, $tag, $settings, 'gs-scx-b' . $n );
	if ( false === strpos( $html, 'gs-scx-b' . $n ) ) {
		// Nothing customised: still give the block a scope for later styles.
		$html = '<div class="gs-scx gs-scx-' . esc_attr( sanitize_html_class( $tag ) ) . '" style="display:contents">' . $html . '</div>';
	}
	return $wrap ? '<div ' . get_block_wrapper_attributes( array( 'class' => 'gs-sales-block' ) ) . '>' . $html . '</div>' : $html;
}
