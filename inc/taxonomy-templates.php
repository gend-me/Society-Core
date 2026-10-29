<?php
/**
 * Category templates: which block template each blog category, product
 * category and product attribute uses.
 *
 * The Design Studio's Blog Archives and Product Categories & Attributes pages
 * pick a template per category (or attribute) and store it here; the choice
 * goes first in the template hierarchy for that archive. With no choice the
 * normal hierarchy applies (category-{slug}, then category, then archive).
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_TT_META   = 'gend_society_template';
const GEND_SOCIETY_TT_OPTION = 'gend_society_attribute_templates';

/**
 * The template groups a studio page shows.
 *
 * @param string $set 'blog' or 'store'.
 * @return array[]
 */
function gend_society_tt_groups( $set ) {
	if ( 'store' === $set ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$groups = array(
			array(
				'key'      => 'product_cat',
				'label'    => __( 'Product categories', 'gend-society' ),
				'allLabel' => __( 'All product categories', 'gend-society' ),
				'noun'     => __( 'category', 'gend-society' ),
				'base'     => 'taxonomy-product_cat',
				'fallback' => 'archive-product',
				'prefix'   => 'taxonomy-product_cat-',
				'items'    => gend_society_tt_terms( 'product_cat' ),
			),
		);
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$map   = (array) get_option( GEND_SOCIETY_TT_OPTION, array() );
			$items = array();
			foreach ( wc_get_attribute_taxonomies() as $a ) {
				$tax     = 'pa_' . $a->attribute_name;
				$items[] = array(
					'id'       => $tax,
					'name'     => $a->attribute_label ? $a->attribute_label : $a->attribute_name,
					'slug'     => $tax,
					'count'    => (int) wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ),
					'template' => isset( $map[ $tax ] ) ? (string) $map[ $tax ] : '',
				);
			}
			$groups[] = array(
				'key'      => 'attribute',
				'label'    => __( 'Product attributes', 'gend-society' ),
				'allLabel' => __( 'All product attributes', 'gend-society' ),
				'noun'     => __( 'attribute', 'gend-society' ),
				'base'     => 'taxonomy-product_attribute',
				'fallback' => 'archive-product',
				'prefix'   => 'taxonomy-',
				'items'    => $items,
			);
		}
		return $groups;
	}
	return array(
		array(
			'key'      => 'category',
			'label'    => __( 'Blog categories', 'gend-society' ),
			'allLabel' => __( 'All blog categories', 'gend-society' ),
			'noun'     => __( 'category', 'gend-society' ),
			'base'     => 'category',
			'fallback' => 'archive',
			'prefix'   => 'category-',
			'items'    => gend_society_tt_terms( 'category' ),
		),
	);
}

/**
 * A taxonomy's terms with their chosen template.
 *
 * @param string $taxonomy Taxonomy.
 * @return array[]
 */
function gend_society_tt_terms( $taxonomy ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 500,
			'orderby'    => 'name',
		)
	);
	$out = array();
	if ( is_wp_error( $terms ) ) {
		return $out;
	}
	foreach ( $terms as $t ) {
		$out[] = array(
			'id'       => (int) $t->term_id,
			'name'     => html_entity_decode( $t->name, ENT_QUOTES ),
			'slug'     => urldecode( $t->slug ),
			'count'    => (int) $t->count,
			'template' => (string) get_term_meta( $t->term_id, GEND_SOCIETY_TT_META, true ),
		);
	}
	return $out;
}

add_action( 'rest_api_init', 'gend_society_tt_register_rest' );
/**
 * REST: the groups for a studio page, and saving one category's template.
 */
function gend_society_tt_register_rest() {
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/studio/tax-templates',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => function ( WP_REST_Request $r ) {
					return rest_ensure_response( gend_society_tt_groups( 'store' === $r->get_param( 'set' ) ? 'store' : 'blog' ) );
				},
				'permission_callback' => $can,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_tt_rest_assign',
				'permission_callback' => $can,
			),
		)
	);
}

/**
 * Saves the template one category or attribute uses ('' = automatic).
 *
 * @param WP_REST_Request $r Request: group (category|product_cat|attribute), id, template.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_tt_rest_assign( WP_REST_Request $r ) {
	$group = (string) $r->get_param( 'group' );
	$slug  = sanitize_title( (string) $r->get_param( 'template' ) );
	if ( '' !== $slug && ! get_block_templates( array( 'slug__in' => array( $slug ) ) ) ) {
		return new WP_Error( 'gs_tt_template', __( 'That template does not exist.', 'gend-society' ), array( 'status' => 400 ) );
	}
	if ( 'attribute' === $group ) {
		$tax = sanitize_key( (string) $r->get_param( 'id' ) );
		if ( 0 !== strpos( $tax, 'pa_' ) || ! taxonomy_exists( $tax ) ) {
			return new WP_Error( 'gs_tt_item', __( 'That attribute does not exist.', 'gend-society' ), array( 'status' => 404 ) );
		}
		$map = (array) get_option( GEND_SOCIETY_TT_OPTION, array() );
		if ( '' === $slug ) {
			unset( $map[ $tax ] );
		} else {
			$map[ $tax ] = $slug;
		}
		update_option( GEND_SOCIETY_TT_OPTION, $map, false );
	} elseif ( in_array( $group, array( 'category', 'product_cat' ), true ) ) {
		$term = get_term( (int) $r->get_param( 'id' ), $group );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'gs_tt_item', __( 'That category does not exist.', 'gend-society' ), array( 'status' => 404 ) );
		}
		if ( '' === $slug ) {
			delete_term_meta( $term->term_id, GEND_SOCIETY_TT_META );
		} else {
			update_term_meta( $term->term_id, GEND_SOCIETY_TT_META, $slug );
		}
	} else {
		return new WP_Error( 'gs_tt_group', __( 'Unknown template group.', 'gend-society' ), array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'template' => $slug ) );
}

add_filter( 'category_template_hierarchy', 'gend_society_tt_hierarchy', 20 );
add_filter( 'taxonomy_template_hierarchy', 'gend_society_tt_hierarchy', 20 );
/**
 * Puts the chosen template first for a category or attribute archive.
 *
 * @param string[] $templates Template hierarchy.
 * @return string[]
 */
function gend_society_tt_hierarchy( $templates ) {
	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return $templates;
	}
	$slug = '';
	if ( in_array( $term->taxonomy, array( 'category', 'product_cat' ), true ) ) {
		$slug = (string) get_term_meta( $term->term_id, GEND_SOCIETY_TT_META, true );
	} elseif ( 0 === strpos( $term->taxonomy, 'pa_' ) ) {
		$map  = (array) get_option( GEND_SOCIETY_TT_OPTION, array() );
		$slug = isset( $map[ $term->taxonomy ] ) ? (string) $map[ $term->taxonomy ] : '';
	}
	if ( '' !== $slug ) {
		array_unshift( $templates, $slug . '.php' );
	}
	return $templates;
}
