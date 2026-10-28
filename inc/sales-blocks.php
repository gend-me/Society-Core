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

/**
 * The Affiliate Dashboard's parts that can be placed on their own.
 *
 * Keys: label, find (text in the opening tag of the part's element), inner
 * (take its content, not the element), tab (a whole tab panel, shown open).
 *
 * @return array[]
 */
function gend_society_sb_dash_sections() {
	return array(
		'tab-referral-sales'  => array(
			'label' => __( 'Referrals tab (all of it)', 'gend-society' ),
			'find'  => 'data-aas-panel="referral-sales"',
			'tab'   => true,
		),
		'tracking-urls'       => array(
			'label' => __( 'Referrals › Tracking URLs', 'gend-society' ),
			'find'  => 'data-aas-subpanel="referral-tracking"',
			'inner' => true,
		),
		'commission-rates'    => array(
			'label' => __( 'Referrals › Commission Rates', 'gend-society' ),
			'find'  => 'data-aas-subpanel="referral-commissions"',
			'inner' => true,
		),
		'linked-apps'         => array(
			'label' => __( 'Referrals › Linked Apps', 'gend-society' ),
			'find'  => 'data-aas-subpanel="referral-apps"',
			'inner' => true,
		),
		'tab-sales-team'      => array(
			'label' => __( 'Sales Team tab (all of it)', 'gend-society' ),
			'find'  => 'data-aas-panel="sales-team"',
			'tab'   => true,
		),
		'team-members'        => array(
			'label' => __( 'Sales Team › Members', 'gend-society' ),
			'find'  => 'class="aas-team-section aas-team-section--members"',
		),
		'team-rates'          => array(
			'label' => __( 'Sales Team › Commission rates', 'gend-society' ),
			'find'  => 'class="aas-team-section aas-team-section--rates"',
		),
		'tab-payments'        => array(
			'label' => __( 'Payment tab (all of it)', 'gend-society' ),
			'find'  => 'data-aas-panel="payments"',
			'tab'   => true,
		),
		'payment-history'     => array(
			'label' => __( 'Payment › Wallet & payment history', 'gend-society' ),
			'find'  => 'class="aas-pay-section aas-pay-section--wallet"',
		),
		'tracking-analytics'  => array(
			'label' => __( 'Payment › Tracking analytics', 'gend-society' ),
			'find'  => 'class="aas-pay-section aas-pay-section--tracking"',
		),
		'products'            => array(
			'label' => __( 'Payment › Products', 'gend-society' ),
			'find'  => 'class="aas-pay-section aas-pay-section--products"',
		),
		'earnings-projection' => array(
			'label' => __( 'Payment › Earnings projection', 'gend-society' ),
			'find'  => 'class="aas-pay-section aas-pay-section--projection"',
		),
	);
}

/**
 * The element whose opening tag contains $needle, matched by counting its own
 * tag name (scripts and styles skipped).
 *
 * @param string $html   Markup.
 * @param string $needle Text inside the opening tag.
 * @return int[]|null [ start, end, inner start, inner end ].
 */
function gend_society_sb_element( $html, $needle ) {
	$at = strpos( $html, $needle );
	if ( false === $at ) {
		return null;
	}
	$start = strrpos( substr( $html, 0, $at ), '<' );
	if ( false === $start || ! preg_match( '/^<([a-z][a-z0-9]*)/i', substr( $html, $start, 20 ), $m ) ) {
		return null;
	}
	$tag   = strtolower( $m[1] );
	$open  = strpos( $html, '>', $at );
	$depth = 0;
	$pos   = $start;
	while ( preg_match( '#<(/?)(' . $tag . '|script|style)\b[^>]*>#i', $html, $mm, PREG_OFFSET_CAPTURE, $pos ) ) {
		$name = strtolower( $mm[2][0] );
		$p    = $mm[0][1];
		$pos  = $p + strlen( $mm[0][0] );
		if ( 'script' === $name || 'style' === $name ) {
			if ( '/' !== $mm[1][0] && $name !== $tag ) {
				$close = stripos( $html, '</' . $name, $pos );
				if ( false !== $close ) {
					$pos = $close + strlen( $name ) + 2;
				}
			}
			continue;
		}
		$depth += '/' === $mm[1][0] ? -1 : 1;
		if ( 0 === $depth ) {
			return array( $start, $pos, $open + 1, $p );
		}
	}
	return null;
}

/**
 * One part of the Affiliate Dashboard, as a dashboard of its own (its script
 * runs on it: tables, filters, the projection calculator).
 *
 * @param string $key         Part key (gend_society_sb_dash_sections()).
 * @param bool   $show_header Keep the part's decorative header.
 * @param array  $custom      The block's settings.
 * @param bool   $wrap        Add the block wrapper (false for the editor preview).
 * @return string
 */
function gend_society_sb_dash_section( $key, $show_header, $custom, $wrap = true ) {
	static $dash   = null;
	static $notice = false;
	static $n      = 0;
	$parts         = gend_society_sb_dash_sections();
	if ( ! isset( $parts[ $key ] ) || ! shortcode_exists( 'affiliate_sales_dashboard' ) ) {
		return '';
	}
	if ( null === $dash ) {
		// Rendered once per page, however many parts are placed.
		$GLOBALS['gend_society_scx_off'] = true;
		$dash                            = (string) do_shortcode( '[affiliate_sales_dashboard]' );
		$GLOBALS['gend_society_scx_off'] = false;
	}
	$part = $parts[ $key ];
	$el   = gend_society_sb_element( $dash, $part['find'] );
	if ( ! $el ) {
		// Not a member's dashboard (e.g. the sign-in message): show that once.
		if ( $notice || false !== strpos( $dash, 'data-aas-dashboard' ) ) {
			return '';
		}
		$notice = true;
		$html   = $dash;
	} else {
		$html = ! empty( $part['inner'] ) ? substr( $dash, $el[2], $el[3] - $el[2] ) : substr( $dash, $el[0], $el[1] - $el[0] );
		if ( ! empty( $part['tab'] ) ) {
			// A whole tab, shown open.
			$end  = strpos( $html, '>' ) + 1;
			$head = substr( $html, 0, $end );
			$head = preg_replace( '/\shidden(?=[\s>])/', '', $head );
			$head = str_replace( array( 'class="aas-dashboard-panel"', 'aria-hidden="true"' ), array( 'class="aas-dashboard-panel is-active"', 'aria-hidden="false"' ), $head );
			$html = $head . substr( $html, $end );
		}
		if ( ! $show_header ) {
			$h = gend_society_sb_element( $html, 'class="aas-panel-header-section' );
			while ( $h ) {
				$html = substr( $html, 0, $h[0] ) . substr( $html, $h[1] );
				$h    = gend_society_sb_element( $html, 'class="aas-panel-header-section' );
			}
		}
		$html = '<div class="aas-affiliate-dashboard aas-dash-part aas-dash-part--' . esc_attr( $key ) . '" data-aas-dashboard="true">' . $html . '</div>';
	}
	++$n;
	$tag              = 'affiliate_sales_dashboard';
	$settings         = gend_society_scx_merge( $tag, gend_society_scx_sanitize( $tag, $custom ) );
	$settings['hide'] = array(); // The part is chosen whole: the dashboard's section choices do not apply.
	$html             = gend_society_scx_render( $html, $tag, $settings, 'gs-scx-p' . $n );
	return $wrap ? '<div ' . get_block_wrapper_attributes( array( 'class' => 'gs-sales-block' ) ) . '>' . $html . '</div>' : $html;
}

add_action( 'init', 'gend_society_sb_register_dash_section', 31 );
/**
 * The Affiliate Dashboard Section block.
 */
function gend_society_sb_register_dash_section() {
	if ( ! shortcode_exists( 'affiliate_sales_dashboard' ) || ! function_exists( 'gend_society_scx_schema' ) || ! wp_script_is( 'gend-society-sales-blocks', 'registered' ) ) {
		return;
	}
	register_block_type(
		'gend-society/sales-dashboard-section',
		array(
			'api_version'     => 3,
			'title'           => __( 'Affiliate Dashboard Section', 'gend-society' ),
			'description'     => __( 'One part of the affiliate dashboard (a tab, the payment history, the team members, the earnings projection...), to build your own dashboard layout.', 'gend-society' ),
			'category'        => 'gend-sales',
			'editor_script'   => 'gend-society-sales-blocks',
			'attributes'      => array(
				'section'    => array(
					'type'    => 'string',
					'default' => 'payment-history',
				),
				'showHeader' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'custom'     => array(
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
			'render_callback' => function ( $attrs ) {
				return gend_society_sb_dash_section(
					isset( $attrs['section'] ) ? (string) $attrs['section'] : 'payment-history',
					! isset( $attrs['showHeader'] ) || $attrs['showHeader'],
					isset( $attrs['custom'] ) ? (array) $attrs['custom'] : array()
				);
			},
		)
	);
	$options = array();
	foreach ( gend_society_sb_dash_sections() as $k => $p ) {
		$options[] = array(
			'value' => $k,
			'label' => $p['label'],
		);
	}
	wp_add_inline_script( 'gend-society-sales-blocks', 'window.gsSalesBlocks&&(window.gsSalesBlocks.dashSections=' . wp_json_encode( $options ) . ');', 'before' );
}
