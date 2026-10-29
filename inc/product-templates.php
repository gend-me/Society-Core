<?php
/**
 * Product templates: which block template a product page uses, by product
 * type, product category or product attribute.
 *
 * The Design Studio's Product Templates page lists the product page templates
 * and assigns each to types, categories and attributes; each one belongs to
 * one template at a time. When a product page renders, its category wins, then
 * its attributes, then its type, then the product's own template
 * (single-product-{name}) or the default Single Product template.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_PT_OPTION = 'gend_society_product_templates';

/**
 * Product types a template can be assigned to.
 *
 * @return array type => label.
 */
function gend_society_pt_types() {
	$types = array(
		'simple'                => __( 'Simple', 'gend-society' ),
		'subscription'          => __( 'Subscription', 'gend-society' ),
		'variable'              => __( 'Variable', 'gend-society' ),
		'variable-subscription' => __( 'Variable Subscription', 'gend-society' ),
		'grouped'               => __( 'Grouped', 'gend-society' ),
		'external'              => __( 'External', 'gend-society' ),
	);
	if ( function_exists( 'wc_get_product_types' ) ) {
		foreach ( wc_get_product_types() as $type => $label ) {
			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = wp_strip_all_tags( $label );
			}
		}
	}
	return $types;
}

/**
 * Assignments: template slug => [ types, cats (term ids), attrs (pa_ taxonomies) ].
 * Until the first save, a customised product type template keeps its type.
 *
 * @return array[]
 */
function gend_society_pt_map() {
	$map = get_option( GEND_SOCIETY_PT_OPTION, false );
	if ( is_array( $map ) ) {
		return $map;
	}
	$map = array();
	if ( function_exists( 'gend_society_studio_customised_slugs' ) ) {
		$slugs = array();
		foreach ( array_keys( gend_society_pt_types() ) as $type ) {
			$slugs[ 'single-product-type-' . $type ] = $type;
		}
		foreach ( gend_society_studio_customised_slugs( 'wp_template', array_keys( $slugs ) ) as $slug ) {
			$map[ $slug ] = array(
				'types' => array( $slugs[ $slug ] ),
				'cats'  => array(),
				'attrs' => array(),
			);
		}
	}
	return $map;
}

add_action( 'rest_api_init', 'gend_society_pt_register_rest' );
/**
 * REST: what can be assigned and the assignments; saving one template's.
 */
function gend_society_pt_register_rest() {
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/studio/product-templates',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'gend_society_pt_rest_get',
				'permission_callback' => $can,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_pt_rest_save',
				'permission_callback' => $can,
			),
		)
	);
}

/**
 * Types, categories, attributes and the current assignments.
 *
 * @return WP_REST_Response
 */
function gend_society_pt_rest_get() {
	$types = array();
	foreach ( gend_society_pt_types() as $id => $label ) {
		$types[] = array(
			'id'   => $id,
			'name' => $label,
		);
	}
	$cats  = array();
	$terms = taxonomy_exists( 'product_cat' ) ? get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => 500,
			'orderby'    => 'name',
		)
	) : array();
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			$cats[] = array(
				'id'   => (int) $t->term_id,
				'name' => html_entity_decode( $t->name, ENT_QUOTES ),
			);
		}
	}
	$attrs = array();
	if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$attrs[] = array(
				'id'   => 'pa_' . $a->attribute_name,
				'name' => $a->attribute_label ? $a->attribute_label : $a->attribute_name,
			);
		}
	}
	return rest_ensure_response(
		array(
			'types' => $types,
			'cats'  => $cats,
			'attrs' => $attrs,
			'map'   => (object) gend_society_pt_map(),
		)
	);
}

/**
 * Saves one template's types, categories and attributes; each is taken from
 * whichever template had it before.
 *
 * @param WP_REST_Request $r Request: template, types[], cats[], attrs[].
 * @return WP_REST_Response|WP_Error
 */
function gend_society_pt_rest_save( WP_REST_Request $r ) {
	$slug = sanitize_title( (string) $r->get_param( 'template' ) );
	if ( '' === $slug || ! get_block_templates( array( 'slug__in' => array( $slug ) ) ) ) {
		return new WP_Error( 'gs_pt_template', __( 'That template does not exist.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$valid_types = array_keys( gend_society_pt_types() );
	$types       = array_values( array_intersect( array_map( 'sanitize_key', (array) $r->get_param( 'types' ) ), $valid_types ) );
	$cats        = array();
	foreach ( (array) $r->get_param( 'cats' ) as $id ) {
		$term = get_term( (int) $id, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$cats[] = (int) $term->term_id;
		}
	}
	$attrs = array();
	foreach ( (array) $r->get_param( 'attrs' ) as $tax ) {
		$tax = sanitize_key( (string) $tax );
		if ( 0 === strpos( $tax, 'pa_' ) && taxonomy_exists( $tax ) ) {
			$attrs[] = $tax;
		}
	}

	$map = gend_society_pt_map();
	foreach ( $map as $other => $a ) {
		$map[ $other ] = array(
			'types' => array_values( array_diff( (array) $a['types'], $types ) ),
			'cats'  => array_values( array_diff( array_map( 'intval', (array) $a['cats'] ), $cats ) ),
			'attrs' => array_values( array_diff( (array) $a['attrs'], $attrs ) ),
		);
	}
	$map[ $slug ] = array(
		'types' => $types,
		'cats'  => $cats,
		'attrs' => $attrs,
	);
	$map = array_filter(
		$map,
		function ( $a ) {
			return $a['types'] || $a['cats'] || $a['attrs'];
		}
	);
	update_option( GEND_SOCIETY_PT_OPTION, $map, false );
	return rest_ensure_response( array( 'map' => (object) $map ) );
}

add_filter( 'single_template_hierarchy', 'gend_society_pt_hierarchy', 20 );
/**
 * Puts the assigned template in a product page's hierarchy: after a
 * product-specific template, before the generic ones.
 *
 * @param string[] $templates Template hierarchy.
 * @return string[]
 */
function gend_society_pt_hierarchy( $templates ) {
	if ( ! wp_is_block_theme() || ! function_exists( 'wc_get_product' ) || ! is_singular( 'product' ) ) {
		return $templates;
	}
	$id      = get_queried_object_id();
	$product = wc_get_product( $id );
	$map     = gend_society_pt_map();
	if ( ! $product || ! $map ) {
		return $templates;
	}
	$cats  = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
	$cats  = is_wp_error( $cats ) ? array() : array_map( 'intval', $cats );
	$found = '';
	foreach ( array( 'cats', 'attrs', 'types' ) as $by ) {
		foreach ( $map as $slug => $a ) {
			$hit = false;
			if ( 'cats' === $by ) {
				$hit = (bool) array_intersect( $cats, array_map( 'intval', (array) $a['cats'] ) );
			} elseif ( 'attrs' === $by ) {
				foreach ( (array) $a['attrs'] as $tax ) {
					if ( has_term( '', $tax, $id ) ) {
						$hit = true;
						break;
					}
				}
			} else {
				$hit = in_array( $product->get_type(), (array) $a['types'], true );
			}
			if ( $hit ) {
				$found = (string) $slug;
				break 2;
			}
		}
	}
	if ( '' === $found ) {
		return $templates;
	}
	$templates = array_values(
		array_filter(
			$templates,
			function ( $tpl ) use ( $found ) {
				return preg_replace( '/\.php$/', '', $tpl ) !== $found;
			}
		)
	);
	$name  = 'single-product-' . get_post_field( 'post_name', $id );
	$index = 0;
	foreach ( $templates as $i => $tpl ) {
		if ( preg_replace( '/\.php$/', '', $tpl ) === $name ) {
			$index = $i + 1;
			break;
		}
	}
	array_splice( $templates, $index, 0, array( $found ) );
	return $templates;
}
