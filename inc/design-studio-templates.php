<?php
/**
 * Design Studio templates: real block templates for GenD design surfaces that
 * core and WooCommerce do not provide (product types, plugin dashboard pages,
 * wallet pages, coming soon).
 *
 * Each template is registered with register_block_template() (WordPress 6.7+)
 * so it shows in the Site Editor and the Design Studio, starting from a copy of
 * the template the page uses today. It joins the template hierarchy only once
 * it has been customised (saved), so an untouched site renders exactly as
 * before.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The studio's own templates.
 *
 * Keys: slug => [ title, description, base (template the default content is
 * copied from), when (callable: is this template's plugin active), post_types ].
 *
 * @return array[]
 */
function gend_society_studio_template_defs() {
	$is_active = function ( $plugin ) {
		return function () use ( $plugin ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			return is_plugin_active( $plugin ) || is_plugin_active_for_network( $plugin );
		};
	};
	$has_wc    = function () {
		return class_exists( 'WooCommerce' );
	};

	$defs = array(
		'coming-soon'              => array(
			'title'       => __( 'Coming Soon', 'gend-society' ),
			'description' => __( 'A holding page you can assign to any page while it is being built.', 'gend-society' ),
			'base'        => 'page',
			'when'        => function () {
				return ! class_exists( 'WooCommerce' ); // WooCommerce ships its own Coming Soon template.
			},
			'post_types'  => array( 'page' ),
		),
		'page-affiliate-dashboard' => array(
			'title'       => __( 'Affiliate Dashboard', 'gend-society' ),
			'description' => __( 'Pages that show the affiliate sales dashboard.', 'gend-society' ),
			'base'        => 'page',
			'when'        => $is_active( 'sales-team/advanced-affiliate-system.php' ),
			'post_types'  => array( 'page' ),
			'shortcode'   => 'affiliate_sales_dashboard',
		),
		'page-team-dashboard'      => array(
			'title'       => __( 'Team Dashboard', 'gend-society' ),
			'description' => __( 'Pages that show a custom team dashboard.', 'gend-society' ),
			'base'        => 'page',
			'when'        => $is_active( 'projects/project-service-orders.php' ),
			'post_types'  => array( 'page' ),
			'shortcode'   => 'psoo_dashboard',
		),
		'page-wallet'              => array(
			'title'       => __( 'Wallet', 'gend-society' ),
			'description' => __( 'Pages that show a member wallet.', 'gend-society' ),
			'base'        => 'page',
			'when'        => $is_active( 'reward-programs/reward-programs.php' ),
			'post_types'  => array( 'page' ),
			'shortcode'   => 'gend_wallet',
		),
	);

	foreach ( array(
		'simple'   => __( 'Simple Product', 'gend-society' ),
		'variable' => __( 'Variable Product', 'gend-society' ),
		'grouped'  => __( 'Grouped Product', 'gend-society' ),
		'external' => __( 'External Product', 'gend-society' ),
	) as $type => $title ) {
		$defs[ 'single-product-type-' . $type ] = array(
			'title'        => $title,
			/* translators: %s: product type, e.g. "Variable Product". */
			'description'  => sprintf( __( 'Product pages for every %s.', 'gend-society' ), $title ),
			'base'         => 'single-product',
			'when'         => $has_wc,
			'post_types'   => array(),
			'product_type' => $type,
		);
	}

	return $defs;
}

add_action( 'init', 'gend_society_studio_register_templates', 20 );
/**
 * Registers the studio templates whose plugin is active.
 */
function gend_society_studio_register_templates() {
	if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
		return;
	}
	// Default content is only needed where templates are listed or edited.
	$needs_content = is_admin() || gend_society_studio_is_rest_request();

	foreach ( gend_society_studio_template_defs() as $slug => $def ) {
		if ( ! call_user_func( $def['when'] ) ) {
			continue;
		}
		register_block_template(
			'gend-society//' . $slug,
			array(
				'title'       => $def['title'],
				'description' => $def['description'],
				'content'     => $needs_content ? gend_society_studio_template_default_content( $def ) : '',
				'post_types'  => $def['post_types'],
			)
		);
	}
}

/**
 * Is this a REST request? REST_REQUEST is not defined yet at init.
 *
 * @return bool
 */
function gend_society_studio_is_rest_request() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request routing only.
	return false !== strpos( $uri, $prefix ) || isset( $_GET['rest_route'] );
}

/**
 * Default content: a copy of the template the page uses today (customised or not).
 *
 * @param array $def Template definition.
 * @return string
 */
function gend_society_studio_template_default_content( $def ) {
	$content = gend_society_studio_base_content( $def['base'] );

	// Product types share the Add to Cart template part once it exists.
	if ( ! empty( $def['product_type'] ) && gend_society_studio_customised_slugs( 'wp_template_part', array( 'add-to-cart' ) ) ) {
		$content = preg_replace(
			'#<!-- wp:woocommerce/add-to-cart-form(?:\s+\{[^}]*\})?\s*/-->#',
			'<!-- wp:template-part {"slug":"add-to-cart"} /-->',
			$content,
			1
		);
	}
	return $content;
}

/**
 * The current content of a base template: the saved copy, the theme file, or
 * WooCommerce's own file. Never calls get_block_templates() (that would recurse
 * into the registry while it is being filled).
 *
 * @param string $slug Template slug.
 * @return string
 */
function gend_society_studio_base_content( $slug ) {
	$saved = get_posts(
		array(
			'post_type'      => 'wp_template',
			'name'           => $slug,
			'post_status'    => array( 'publish' ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term lookup on an admin request.
			'tax_query'      => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				),
			),
		)
	);
	if ( $saved ) {
		return $saved[0]->post_content;
	}

	$files = array(
		get_stylesheet_directory() . '/templates/' . $slug . '.html',
		get_template_directory() . '/templates/' . $slug . '.html',
	);
	if ( defined( 'WC_ABSPATH' ) ) {
		$files[] = WC_ABSPATH . 'templates/templates/' . $slug . '.html';
	}
	foreach ( $files as $file ) {
		if ( is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template file.
			return (string) file_get_contents( $file );
		}
	}

	return '<!-- wp:template-part {"slug":"header"} /-->'
		. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
		. '<!-- wp:post-title {"level":1} /--><!-- wp:post-content {"layout":{"type":"constrained"}} /-->'
		. '</main><!-- /wp:group -->'
		. '<!-- wp:template-part {"slug":"footer"} /-->';
}

/**
 * Which of these slugs have a saved (customised) copy for the active theme.
 *
 * @param string   $post_type wp_template or wp_template_part.
 * @param string[] $slugs     Slugs to look for.
 * @return string[] The slugs that exist.
 */
function gend_society_studio_customised_slugs( $post_type, array $slugs ) {
	static $cache = array();
	$key          = $post_type . ':' . get_stylesheet();
	if ( ! isset( $cache[ $key ] ) ) {
		$all = array();
		foreach ( gend_society_studio_template_defs() as $slug => $def ) {
			$all[] = $slug;
		}
		$all[]  = 'add-to-cart';
		$posts  = get_posts(
			array(
				'post_type'      => $post_type,
				'post_name__in'  => $all,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => count( $all ),
				'no_found_rows'  => true,
				'fields'         => 'all',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term lookup, cached per request.
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
		$cache[ $key ] = wp_list_pluck( $posts, 'post_name' );
	}
	return array_values( array_intersect( $slugs, $cache[ $key ] ) );
}

add_filter( 'single_template_hierarchy', 'gend_society_studio_product_type_hierarchy' );
/**
 * Product pages use their type's template once it has been customised.
 *
 * @param string[] $templates Candidate template slugs/files.
 * @return string[]
 */
function gend_society_studio_product_type_hierarchy( $templates ) {
	if ( ! wp_is_block_theme() || ! function_exists( 'wc_get_product' ) || ! is_singular( 'product' ) ) {
		return $templates;
	}
	// Once Product Templates has been saved, its assignments decide (inc/product-templates.php).
	if ( defined( 'GEND_SOCIETY_PT_OPTION' ) && false !== get_option( GEND_SOCIETY_PT_OPTION, false ) ) {
		return $templates;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product ) {
		return $templates;
	}
	$slug = 'single-product-type-' . sanitize_key( $product->get_type() );
	if ( ! gend_society_studio_customised_slugs( 'wp_template', array( $slug ) ) ) {
		return $templates;
	}
	// After a product-specific template (single-product-{slug}), before the generic ones.
	$name  = 'single-product-' . get_post_field( 'post_name', get_queried_object_id() );
	$index = 0;
	foreach ( $templates as $i => $tpl ) {
		if ( preg_replace( '/\.php$/', '', $tpl ) === $name ) {
			$index = $i + 1;
			break;
		}
	}
	array_splice( $templates, $index, 0, array( $slug ) );
	return $templates;
}

add_filter( 'page_template_hierarchy', 'gend_society_studio_page_hierarchy' );
/**
 * Pages that show a plugin dashboard use its template once it has been
 * customised. A template picked for the page itself still wins.
 *
 * @param string[] $templates Candidate template slugs/files.
 * @return string[]
 */
function gend_society_studio_page_hierarchy( $templates ) {
	if ( ! wp_is_block_theme() ) {
		return $templates;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' === $post->post_content ) {
		return $templates;
	}
	foreach ( gend_society_studio_template_defs() as $slug => $def ) {
		// Not has_shortcode(): it only matches registered tags, and the check must not depend on load order.
		if ( empty( $def['shortcode'] ) || ! preg_match( '/' . get_shortcode_regex( array( $def['shortcode'] ) ) . '/', $post->post_content ) ) {
			continue;
		}
		if ( ! call_user_func( $def['when'] ) || ! gend_society_studio_customised_slugs( 'wp_template', array( $slug ) ) ) {
			continue;
		}
		$assigned = get_page_template_slug( $post );
		$first    = isset( $templates[0] ) ? preg_replace( '/\.php$/', '', $templates[0] ) : '';
		$index    = ( $assigned && preg_replace( '/\.php$/', '', $assigned ) === $first ) ? 1 : 0;
		array_splice( $templates, $index, 0, array( $slug ) );
		break;
	}
	return $templates;
}

/**
 * Where each dashboard template's content comes from, for the editor preview:
 * the newest published page with its shortcode, else the shortcode alone.
 *
 * @return array slug => [ url, label ].
 */
function gend_society_studio_template_previews() {
	global $wpdb;
	$out = array();
	foreach ( gend_society_studio_template_defs() as $slug => $def ) {
		if ( empty( $def['shortcode'] ) || ! call_user_func( $def['when'] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- editor-only lookup of a page that uses the shortcode.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY post_modified DESC LIMIT 1", '%' . $wpdb->esc_like( '[' . $def['shortcode'] ) . '%' ) );
		$out[ $slug ] = $id
			? array(
				'url'   => add_query_arg( 'gs_studio_page_preview', $id, home_url( '/' ) ),
				/* translators: %s: page title. */
				'label' => sprintf( __( 'Live preview: %s', 'gend-society' ), get_the_title( $id ) ),
			)
			: array(
				'url'   => add_query_arg( 'gs_studio_shortcode', $def['shortcode'], home_url( '/' ) ),
				/* translators: %s: shortcode. */
				'label' => sprintf( __( 'Live preview: [%s]', 'gend-society' ), $def['shortcode'] ),
			);
		// A team dashboard needs an id: show the first one.
		if ( ! $id && 'psoo_dashboard' === $def['shortcode'] && function_exists( 'psoo_dash_get_all' ) ) {
			$all  = psoo_dash_get_all();
			$dash = reset( $all );
			if ( $dash ) {
				$out[ $slug ]['url'] = add_query_arg( 'gs_studio_sc_id', $dash['id'], $out[ $slug ]['url'] );
				/* translators: %s: dashboard name. */
				$out[ $slug ]['label'] = sprintf( __( 'Live preview: %s dashboard', 'gend-society' ), $dash['title'] );
			}
		}
	}
	return $out;
}

add_action( 'enqueue_block_editor_assets', 'gend_society_studio_template_preview_script' );
/**
 * In the template editor, a dashboard template's Content block shows the
 * page it renders (the dashboard) instead of an empty placeholder.
 */
function gend_society_studio_template_preview_script() {
	if ( ! is_admin() || ! current_user_can( 'edit_theme_options' ) || ! wp_is_block_theme() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'site-editor' !== $screen->id ) {
		return;
	}
	$previews = gend_society_studio_template_previews();
	if ( ! $previews ) {
		return;
	}
	$js = 'assets/template-content-preview.js';
	wp_enqueue_script( 'gend-society-template-preview', GEND_SOCIETY_URL . $js, array( 'wp-hooks', 'wp-element', 'wp-data', 'wp-compose', 'wp-block-editor' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_add_inline_script( 'gend-society-template-preview', 'window.gsTemplatePreviews=' . wp_json_encode( $previews ) . ';', 'before' );
}
