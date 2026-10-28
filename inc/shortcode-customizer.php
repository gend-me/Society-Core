<?php
/**
 * Shortcode customiser: lets a site restyle and reword plugin components that
 * are shortcodes (the Sales Team dashboard, links, earnings, commissions...)
 * without touching their plugin.
 *
 * Per shortcode the Design Studio saves colours (the component's CSS
 * variables), sections to hide, replacement text for headings, tabs and
 * buttons, and extra CSS. They are applied to the shortcode's output on every
 * page (do_shortcode_tag). Other plugins describe their components with the
 * gend_society_shortcode_customizer filter.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_SCX_OPTION = 'gend_society_shortcode_custom';

/**
 * What can be customised, per shortcode tag.
 *
 * Keys: colors (var => [ label, CSS variables ]), sections ([ key, label,
 * selectors ]).
 *
 * @return array[]
 */
function gend_society_scx_schema() {
	$aas_colors = array(
		'accent'    => array( __( 'Accent', 'gend-society' ), array( '--aas-accent', '--aas-brand-magenta' ) ),
		'highlight' => array( __( 'Highlight', 'gend-society' ), array( '--aas-vault-blue', '--aas-brand-cyan', '--aas-electric-blue' ) ),
		'text'      => array( __( 'Text', 'gend-society' ), array( '--aas-ink' ) ),
		'muted'     => array( __( 'Muted text', 'gend-society' ), array( '--aas-ink-muted' ) ),
		'surface'   => array( __( 'Card background', 'gend-society' ), array( '--aas-surface', '--aas-surface-strong' ) ),
		'border'    => array( __( 'Borders', 'gend-society' ), array( '--aas-border', '--aas-border-soft' ) ),
	);
	$tab   = function ( $key, $label ) {
		return array(
			'key'       => 'tab-' . $key,
			'label'     => $label,
			'selectors' => array( '[data-aas-tab="' . $key . '"]', '[data-aas-panel="' . $key . '"]' ),
		);
	};
	$sub   = function ( $key, $label ) {
		return array(
			'key'       => 'sub-' . $key,
			'label'     => $label,
			'selectors' => array( '[data-aas-subtab="' . $key . '"]', '[data-aas-subpanel="' . $key . '"]' ),
		);
	};
	$cls   = function ( $key, $label, $class ) {
		return array(
			'key'       => $key,
			'label'     => $label,
			'selectors' => array( '.' . $class ),
		);
	};
	$schema = array(
		'affiliate_sales_dashboard'  => array(
			'colors'   => $aas_colors,
			'sections' => array(
				$tab( 'referral-sales', __( 'Referrals tab', 'gend-society' ) ),
				$sub( 'referral-tracking', __( 'Referrals › Tracking URLs', 'gend-society' ) ),
				$sub( 'referral-commissions', __( 'Referrals › Commission Rates', 'gend-society' ) ),
				$sub( 'referral-apps', __( 'Referrals › Linked Apps', 'gend-society' ) ),
				$tab( 'sales-team', __( 'Sales Team tab', 'gend-society' ) ),
				$cls( 'team-members', __( 'Sales Team › Members', 'gend-society' ), 'aas-team-section--members' ),
				$cls( 'team-rates', __( 'Sales Team › Commission rates', 'gend-society' ), 'aas-team-section--rates' ),
				$tab( 'payments', __( 'Payment tab', 'gend-society' ) ),
				$cls( 'pay-wallet', __( 'Payment › Wallet & history', 'gend-society' ), 'aas-pay-section--wallet' ),
				$cls( 'pay-tracking', __( 'Payment › Tracking analytics', 'gend-society' ), 'aas-pay-section--tracking' ),
				$cls( 'pay-products', __( 'Payment › Products', 'gend-society' ), 'aas-pay-section--products' ),
				$cls( 'pay-projection', __( 'Payment › Earnings projection', 'gend-society' ), 'aas-pay-section--projection' ),
				$cls( 'headers', __( 'Decorative section headers', 'gend-society' ), 'aas-panel-header-section' ),
			),
		),
		'withdraw_overview'          => array(
			'colors'   => $aas_colors,
			'sections' => array(
				$cls( 'wallet-title', __( 'Balances heading', 'gend-society' ), 'aas-wallet-title' ),
			),
		),
		'referred_sales_commissions' => array( 'colors' => $aas_colors ),
		'team_sales_commissions'     => array( 'colors' => $aas_colors ),
		'affiliate_link'             => array( 'colors' => $aas_colors ),
		'affiliate_earnings'         => array( 'colors' => $aas_colors ),
		'referral_team'              => array( 'colors' => $aas_colors ),
		'member_order_commissions'   => array( 'colors' => $aas_colors ),
		'member_product_commissions' => array( 'colors' => $aas_colors ),
	);

	/**
	 * Filters the customisable shortcodes.
	 *
	 * @param array[] $schema tag => [ colors, sections ].
	 */
	return apply_filters( 'gend_society_shortcode_customizer', $schema );
}

/**
 * One shortcode's saved settings.
 *
 * @param string $tag Shortcode tag.
 * @return array [ colors, hide, texts, css ].
 */
function gend_society_scx_get( $tag ) {
	$all = get_option( GEND_SOCIETY_SCX_OPTION, array() );
	$s   = isset( $all[ $tag ] ) && is_array( $all[ $tag ] ) ? $all[ $tag ] : array();
	return wp_parse_args(
		$s,
		array(
			'colors' => array(),
			'hide'   => array(),
			'texts'  => array(),
			'css'    => '',
		)
	);
}

/**
 * Cleans settings sent from the studio.
 *
 * @param string $tag Shortcode tag.
 * @param array  $in  Raw settings.
 * @return array
 */
function gend_society_scx_sanitize( $tag, $in ) {
	$schema = gend_society_scx_schema();
	$def    = isset( $schema[ $tag ] ) ? $schema[ $tag ] : array();
	$out    = array(
		'colors' => array(),
		'hide'   => array(),
		'texts'  => array(),
		'css'    => '',
	);
	foreach ( (array) ( $in['colors'] ?? array() ) as $k => $v ) {
		$v = sanitize_hex_color( (string) $v );
		if ( $v && isset( $def['colors'][ $k ] ) ) {
			$out['colors'][ $k ] = $v;
		}
	}
	$keys = wp_list_pluck( isset( $def['sections'] ) ? $def['sections'] : array(), 'key' );
	foreach ( (array) ( $in['hide'] ?? array() ) as $k ) {
		if ( in_array( $k, $keys, true ) ) {
			$out['hide'][] = $k;
		}
	}
	foreach ( (array) ( $in['texts'] ?? array() ) as $from => $to ) {
		$from = trim( wp_strip_all_tags( (string) $from ) );
		$to   = trim( wp_strip_all_tags( (string) $to ) );
		if ( '' !== $from && '' !== $to && $from !== $to && count( $out['texts'] ) < 200 ) {
			$out['texts'][ mb_substr( $from, 0, 200 ) ] = mb_substr( $to, 0, 200 );
		}
	}
	// Plain CSS only: no closing style tag, no markup.
	$out['css'] = mb_substr( str_ireplace( array( '</style', '<' ), '', wp_strip_all_tags( (string) ( $in['css'] ?? '' ) ) ), 0, 20000 );
	return $out;
}

// Headings, buttons and dashboard tab labels whose text is plain.
const GEND_SOCIETY_SCX_TEXT_RE = '#<(h[1-6]|button|span)\b([^>]*)>([^<]*)</\1>#';

/**
 * Applies a shortcode's customisation to its output.
 *
 * @param string $output Shortcode output.
 * @param string $tag    Shortcode tag.
 * @return string
 */
function gend_society_scx_apply( $output, $tag ) {
	if ( ! is_string( $output ) || '' === $output || ! empty( $GLOBALS['gend_society_scx_off'] ) ) {
		return $output;
	}
	// Runs for every shortcode: only customised ones go further.
	$all = get_option( GEND_SOCIETY_SCX_OPTION, array() );
	if ( empty( $all[ $tag ] ) ) {
		return $output;
	}
	$schema = gend_society_scx_schema();
	if ( ! isset( $schema[ $tag ] ) ) {
		return $output;
	}
	$s = gend_society_scx_get( $tag );
	if ( ! $s['colors'] && ! $s['hide'] && ! $s['texts'] && '' === $s['css'] ) {
		return $output;
	}
	if ( $s['texts'] ) {
		$texts  = $s['texts'];
		$output = preg_replace_callback(
			GEND_SOCIETY_SCX_TEXT_RE,
			function ( $m ) use ( $texts ) {
				if ( 'span' === $m[1] && false === strpos( $m[2], 'aas-tab-label' ) ) {
					return $m[0];
				}
				$text = trim( html_entity_decode( $m[3], ENT_QUOTES ) );
				if ( ! isset( $texts[ $text ] ) ) {
					return $m[0];
				}
				return '<' . $m[1] . $m[2] . ' data-gs-scx-orig="' . esc_attr( $text ) . '">' . esc_html( $texts[ $text ] ) . '</' . $m[1] . '>';
			},
			$output
		);
	}
	$scope = '.gs-scx-' . sanitize_html_class( $tag );
	$css   = '';
	if ( $s['colors'] ) {
		$css .= $scope . '{';
		foreach ( $s['colors'] as $k => $v ) {
			foreach ( $schema[ $tag ]['colors'][ $k ][1] as $var ) {
				$css .= $var . ':' . $v . ';';
			}
		}
		$css .= '}';
	}
	$sels = array();
	foreach ( isset( $schema[ $tag ]['sections'] ) ? $schema[ $tag ]['sections'] : array() as $sec ) {
		if ( in_array( $sec['key'], $s['hide'], true ) ) {
			foreach ( $sec['selectors'] as $sel ) {
				$sels[] = $scope . ' ' . $sel;
			}
		}
	}
	if ( $sels ) {
		$css .= implode( ',', $sels ) . '{display:none!important}';
	}
	$css .= $s['css'];
	$html = '<div class="gs-scx ' . esc_attr( substr( $scope, 1 ) ) . '" style="display:contents">' . $output . '</div>';
	if ( '' !== $css ) {
		$html .= '<style>' . $css . '</style>'; // phpcs:ignore -- sanitised in gend_society_scx_sanitize().
	}
	if ( $sels ) {
		// A hidden tab may have been the open one: open the first one left.
		$html .= '<script>(function(){var w=document.currentScript.previousElementSibling;while(w&&!w.classList.contains("gs-scx")){w=w.previousElementSibling;}if(!w){return;}w.querySelectorAll("[role=tablist]").forEach(function(l){var b=[].slice.call(l.querySelectorAll("button"));var a=l.querySelector("button.is-active");if(a&&a.offsetParent===null){var n=b.filter(function(x){return x.offsetParent!==null;})[0];if(n){n.click();}}});})();</script>';
	}
	return $html;
}
add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		return gend_society_scx_apply( $output, $tag );
	},
	20,
	2
);

/**
 * The texts a shortcode shows that can be replaced, from its output.
 *
 * @param string $output Shortcode output (uncustomised).
 * @return string[]
 */
function gend_society_scx_texts( $output ) {
	$output = preg_replace( '#<(script|style)\b.*?</\1>#is', '', (string) $output );
	$out    = array();
	if ( preg_match_all( GEND_SOCIETY_SCX_TEXT_RE, $output, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			if ( 'span' === $x[1] && false === strpos( $x[2], 'aas-tab-label' ) ) {
				continue;
			}
			$t = trim( preg_replace( '/\s+/', ' ', html_entity_decode( $x[3], ENT_QUOTES ) ) );
			if ( '' !== $t && mb_strlen( $t ) <= 120 && ! preg_match( '/[{}+]|^[\W\d]+$/u', $t ) ) {
				$out[ $t ] = true;
			}
		}
	}
	return array_keys( $out );
}

add_action( 'rest_api_init', 'gend_society_scx_register_rest' );
/**
 * REST: a shortcode's customisation options and settings; saving them.
 */
function gend_society_scx_register_rest() {
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/studio/shortcode-custom',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'gend_society_scx_rest_get',
				'permission_callback' => $can,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_scx_rest_save',
				'permission_callback' => $can,
			),
		)
	);
}

/**
 * Options and settings for ?tag=.
 *
 * @param WP_REST_Request $r Request.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_scx_rest_get( WP_REST_Request $r ) {
	$tag    = sanitize_key( (string) $r->get_param( 'tag' ) );
	$schema = gend_society_scx_schema();
	if ( ! isset( $schema[ $tag ] ) || ! shortcode_exists( $tag ) ) {
		return new WP_Error( 'gs_scx_tag', __( 'This component cannot be customised.', 'gend-society' ), array( 'status' => 404 ) );
	}
	// The texts as the plugin writes them (customisation off while rendering).
	$GLOBALS['gend_society_scx_off'] = true;
	$raw                             = do_shortcode( '[' . $tag . ']' );
	$GLOBALS['gend_society_scx_off'] = false;
	$colors = array();
	foreach ( isset( $schema[ $tag ]['colors'] ) ? $schema[ $tag ]['colors'] : array() as $k => $c ) {
		$colors[] = array(
			'key'   => $k,
			'label' => $c[0],
			'vars'  => $c[1],
		);
	}
	$sections = array();
	foreach ( isset( $schema[ $tag ]['sections'] ) ? $schema[ $tag ]['sections'] : array() as $sec ) {
		$sections[] = array(
			'key'       => $sec['key'],
			'label'     => $sec['label'],
			'selectors' => $sec['selectors'],
		);
	}
	return rest_ensure_response(
		array(
			'tag'      => $tag,
			'colors'   => $colors,
			'sections' => $sections,
			'texts'    => gend_society_scx_texts( $raw ),
			'settings' => gend_society_scx_get( $tag ),
		)
	);
}

/**
 * Saves ?tag='s settings ({} resets them).
 *
 * @param WP_REST_Request $r Request: tag, settings.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_scx_rest_save( WP_REST_Request $r ) {
	$tag    = sanitize_key( (string) $r->get_param( 'tag' ) );
	$schema = gend_society_scx_schema();
	if ( ! isset( $schema[ $tag ] ) ) {
		return new WP_Error( 'gs_scx_tag', __( 'This component cannot be customised.', 'gend-society' ), array( 'status' => 404 ) );
	}
	$all         = (array) get_option( GEND_SOCIETY_SCX_OPTION, array() );
	$s           = gend_society_scx_sanitize( $tag, (array) $r->get_param( 'settings' ) );
	$all[ $tag ] = $s;
	if ( ! $s['colors'] && ! $s['hide'] && ! $s['texts'] && '' === $s['css'] ) {
		unset( $all[ $tag ] );
	}
	update_option( GEND_SOCIETY_SCX_OPTION, $all, true );
	return rest_ensure_response( array( 'settings' => gend_society_scx_get( $tag ) ) );
}
