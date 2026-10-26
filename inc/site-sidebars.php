<?php
/**
 * Site sidebars: an optional sidebar beside the page content, per area of
 * the site (pages, blog, shop, profiles, groups, community pages).
 *
 * Every area is full width until a site turns its sidebar on (Design Studio
 * > App > Sidebars: left or right, width, sticky). Each area's sidebar is a
 * block template part (gs-sidebar-<area>) edited like the header and footer,
 * created with starter blocks the first time it is opened.
 *
 * The theme's templates stay as they are: when a page renders, the block
 * template about to be used (the theme's or a saved one) gets everything
 * between its header and footer parts in a main column, with the sidebar beside it.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_SIDEBARS_OPTION = 'gend_society_sidebars';

/**
 * The areas a sidebar can be turned on for: slug => label (only areas whose plugins are active).
 *
 * @return array
 */
function gend_society_sb_areas() {
	$areas = array(
		'pages' => __( 'Pages', 'gend-society' ),
		'blog'  => __( 'Blog posts & archives', 'gend-society' ),
	);
	if ( class_exists( 'WooCommerce' ) ) {
		$areas['shop'] = __( 'Shop, cart & account', 'gend-society' );
	}
	if ( function_exists( 'buddypress' ) ) {
		$areas['profiles']  = __( 'Member profiles', 'gend-society' );
		$areas['groups']    = __( 'Groups', 'gend-society' );
		$areas['community'] = __( 'Directories & activity', 'gend-society' );
	}
	return $areas;
}

/**
 * Saved settings for an area.
 *
 * @param string $area Area slug.
 * @return array { position: none|left|right, width: int, sticky: bool }
 */
function gend_society_sb_settings( $area ) {
	$all = get_option( GEND_SOCIETY_SIDEBARS_OPTION, array() );
	$s   = is_array( $all ) && isset( $all[ $area ] ) && is_array( $all[ $area ] ) ? $all[ $area ] : array();
	return array(
		'position' => isset( $s['position'] ) && in_array( $s['position'], array( 'left', 'right' ), true ) ? $s['position'] : 'none',
		'width'    => isset( $s['width'] ) ? max( 200, min( 480, (int) $s['width'] ) ) : 320,
		'sticky'   => ! empty( $s['sticky'] ),
	);
}

/**
 * The area the current front-end page belongs to ('' when none).
 *
 * @return string
 */
function gend_society_sb_current_area() {
	if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
		return 'profiles';
	}
	if ( function_exists( 'bp_is_group' ) && bp_is_group() ) {
		return 'groups';
	}
	if ( function_exists( 'bp_is_directory' ) && bp_is_directory() ) {
		return 'community';
	}
	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
		return 'shop';
	}
	if ( is_singular( 'post' ) || is_home() || is_archive() || is_search() ) {
		return 'blog';
	}
	if ( is_page() || is_front_page() ) {
		return 'pages';
	}
	return '';
}

/**
 * The template part slug holding an area's sidebar.
 *
 * @param string $area Area slug.
 * @return string
 */
function gend_society_sb_part_slug( $area ) {
	return 'gs-sidebar-' . sanitize_key( $area );
}

/**
 * The sidebar position for this page: saved, or (site editors previewing) ?gs_sb=left|right|none.
 *
 * @param string $area Area slug.
 * @return array Settings.
 */
function gend_society_sb_for_request( $area ) {
	$s = gend_society_sb_settings( $area );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for site editors.
	$try = isset( $_GET['gs_sb'] ) ? sanitize_key( wp_unslash( $_GET['gs_sb'] ) ) : '';
	if ( '' !== $try && in_array( $try, array( 'none', 'left', 'right' ), true ) && current_user_can( 'edit_theme_options' ) ) {
		$s['position'] = $try;
	}
	return $s;
}

/**
 * Whether a top-level template block is the site header or footer part.
 *
 * @param array  $block Parsed block.
 * @param string $kind  'header' or 'footer'.
 * @return bool
 */
function gend_society_sb_is_frame_part( $block, $kind ) {
	if ( empty( $block['blockName'] ) || 'core/template-part' !== $block['blockName'] ) {
		return false;
	}
	$a = isset( $block['attrs'] ) ? $block['attrs'] : array();
	return ( isset( $a['tagName'] ) && $kind === $a['tagName'] ) || ( isset( $a['area'] ) && $kind === $a['area'] ) || ( isset( $a['slug'] ) && false !== strpos( (string) $a['slug'], $kind ) );
}

/**
 * Adds the area's sidebar to a block template: everything between the
 * header and footer parts goes in the main column, the sidebar part beside it.
 *
 * @param string $content Block template content.
 * @param string $area    Area slug.
 * @param array  $s       Sidebar settings.
 * @return string Content ('' when the template has nothing between its header and footer).
 */
function gend_society_sb_wrap_template( $content, $area, $s ) {
	$blocks = parse_blocks( $content );
	$n      = count( $blocks );
	$start  = 0;
	$end    = $n;
	// Leading header part(s) (and whitespace between blocks) stay above the columns.
	while ( $start < $n && ( empty( $blocks[ $start ]['blockName'] ) ? '' === trim( $blocks[ $start ]['innerHTML'] ) : gend_society_sb_is_frame_part( $blocks[ $start ], 'header' ) ) ) {
		++$start;
	}
	// Trailing footer part(s) stay below.
	while ( $end > $start && ( empty( $blocks[ $end - 1 ]['blockName'] ) ? '' === trim( $blocks[ $end - 1 ]['innerHTML'] ) : gend_society_sb_is_frame_part( $blocks[ $end - 1 ], 'footer' ) ) ) {
		--$end;
	}
	if ( $end <= $start ) {
		return '';
	}
	$aside = '<aside class="gs-site-sidebar' . ( $s['sticky'] ? ' is-sticky' : '' ) . '" aria-label="' . esc_attr__( 'Sidebar', 'gend-society' ) . '">'
		. '<!-- wp:template-part ' . wp_json_encode( array( 'slug' => gend_society_sb_part_slug( $area ), 'tagName' => 'div', 'className' => 'gs-site-sidebar__inner' ) ) . ' /-->'
		. '</aside>';
	$main  = '<div class="gs-has-sidebar__main">' . serialize_blocks( array_slice( $blocks, $start, $end - $start ) ) . '</div>';
	return serialize_blocks( array_slice( $blocks, 0, $start ) )
		. '<div class="gs-has-sidebar is-' . esc_attr( $s['position'] ) . ' gs-sidebar-area-' . esc_attr( $area ) . '" style="--gs-sidebar-w:' . (int) $s['width'] . 'px">'
		. ( 'left' === $s['position'] ? $aside . $main : $main . $aside )
		. '</div>'
		. serialize_blocks( array_slice( $blocks, $end ) );
}

add_filter( 'template_include', 'gend_society_sb_apply', 1000 );
/**
 * Adds the sidebar to the block template about to render (saved or theme
 * template alike), when the page's area has one turned on.
 *
 * @param string $template Template file.
 * @return string
 */
function gend_society_sb_apply( $template ) {
	global $_wp_current_template_content;
	if ( empty( $_wp_current_template_content ) || is_admin() || is_embed() ) {
		return $template;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- embeds and previews never get a sidebar.
	if ( isset( $_GET['gs_studio_embed'] ) || isset( $_GET['gdc_tab_only'] ) || isset( $_GET['gs_embed'] ) || isset( $_GET['gs_studio_view'] ) ) {
		return $template;
	}
	$area = gend_society_sb_current_area();
	if ( '' === $area ) {
		return $template;
	}
	$s = gend_society_sb_for_request( $area );
	if ( 'none' === $s['position'] ) {
		return $template;
	}
	$wrapped = gend_society_sb_wrap_template( $_wp_current_template_content, $area, $s );
	if ( '' !== $wrapped ) {
		$_wp_current_template_content = $wrapped; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the block template about to render, with the sidebar added.
	}
	return $template;
}

add_action( 'wp_enqueue_scripts', 'gend_society_sb_styles' );
/**
 * Layout styles for pages with a sidebar.
 */
function gend_society_sb_styles() {
	wp_register_style( 'gend-society-sidebars', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-sidebars' );
	wp_add_inline_style(
		'gend-society-sidebars',
		'.gs-has-sidebar{display:grid;grid-template-columns:minmax(0,1fr) var(--gs-sidebar-w,320px);gap:clamp(20px,3vw,40px);align-items:start;max-width:var(--wp--style--global--wide-size,1340px);margin-inline:auto;padding-inline:var(--wp--style--root--padding-left,16px) var(--wp--style--root--padding-right,16px);box-sizing:border-box}'
		. '.gs-has-sidebar.is-left{grid-template-columns:var(--gs-sidebar-w,320px) minmax(0,1fr)}'
		. '.gs-has-sidebar__main{min-width:0}'
		. '.gs-has-sidebar__main>main{margin-inline:0}'
		. '.gs-site-sidebar{min-width:0;margin-top:var(--wp--preset--spacing--60,32px);display:flex;flex-direction:column;gap:20px}'
		. '.gs-site-sidebar.is-sticky{position:sticky;top:calc(var(--gs-header-h,0px) + 24px)}'
		// Readable on any site background; block settings (zero-specificity :where) still win.
		. ':where(.gs-site-sidebar){color:#e8edf5;font-size:15px;line-height:1.5}'
		. ':where(.gs-site-sidebar) :where(.wp-block-group.has-border-color){background:var(--gs-sidebar-surface,rgba(13,13,26,.94));box-shadow:0 12px 32px rgba(0,0,0,.28)}'
		. ':where(.gs-site-sidebar) :where(h1,h2,h3,h4,h5,h6){margin:0 0 12px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#fff}'
		. ':where(.gs-site-sidebar) :where(.wp-block-latest-posts,.wp-block-categories,.wp-block-page-list){list-style:none;margin:0;padding:0;display:grid;gap:12px}'
		. ':where(.gs-site-sidebar) :where(.wp-block-latest-posts a,.wp-block-categories a){color:inherit;text-decoration:none;font-size:15px;line-height:1.4}'
		. ':where(.gs-site-sidebar) :where(.wp-block-latest-posts a,.wp-block-categories a):hover{color:var(--gs-accent,#97e46e)}'
		. ':where(.gs-site-sidebar) :where(.wp-block-latest-posts__post-date){display:block;margin-top:2px;font-size:12px;opacity:.6}'
		. ':where(.gs-site-sidebar) :where(.wp-block-search__input){min-width:0;padding:10px 16px;border:1px solid rgba(255,255,255,.14);border-radius:999px;background:rgba(255,255,255,.06);color:#fff}'
		. ':where(.gs-site-sidebar) :where(.wp-block-search__inside-wrapper){gap:8px}'
		. '@media (max-width:900px){.gs-has-sidebar,.gs-has-sidebar.is-left{grid-template-columns:minmax(0,1fr)}.gs-has-sidebar.is-left>.gs-site-sidebar{order:2}.gs-site-sidebar.is-sticky{position:static}}'
	);
}

/**
 * Starter blocks for a new sidebar.
 *
 * @param string $area Area slug.
 * @return string
 */
function gend_society_sb_starter( $area ) {
	$card  = static function ( $inner ) {
		return '<!-- wp:group {"style":{"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}},"border":{"radius":"16px","width":"1px","color":"#ffffff1a"}},"layout":{"type":"constrained"}} --><div class="wp-block-group has-border-color" style="border-color:#ffffff1a;border-width:1px;border-radius:16px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">' . $inner . '</div><!-- /wp:group -->';
	};
	$title = static function ( $text ) {
		return '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">' . esc_html( $text ) . '</h4><!-- /wp:heading -->';
	};
	$search = $card( '<!-- wp:search {"label":"' . esc_attr__( 'Search', 'gend-society' ) . '","showLabel":false,"buttonText":"' . esc_attr__( 'Search', 'gend-society' ) . '","buttonUseIcon":true} /-->' );
	switch ( $area ) {
		case 'blog':
			return $search
				. $card( $title( __( 'Latest posts', 'gend-society' ) ) . '<!-- wp:latest-posts {"postsToShow":5,"displayPostDate":true} /-->' )
				. $card( $title( __( 'Categories', 'gend-society' ) ) . '<!-- wp:categories {"showPostCounts":true} /-->' );
		case 'shop':
			return $card( '<!-- wp:search {"label":"' . esc_attr__( 'Search products', 'gend-society' ) . '","showLabel":false,"buttonUseIcon":true,"query":{"post_type":"product"}} /-->' )
				. $card( $title( __( 'Shop by category', 'gend-society' ) ) . '<!-- wp:categories {"taxonomy":"product_cat","showPostCounts":true} /-->' );
		case 'profiles':
		case 'groups':
		case 'community':
			return $search
				. $card( $title( __( 'Latest from the blog', 'gend-society' ) ) . '<!-- wp:latest-posts {"postsToShow":3} /-->' );
	}
	return $search . $card( $title( __( 'About', 'gend-society' ) ) . '<!-- wp:paragraph --><p>' . esc_html__( 'Tell visitors what this site is about.', 'gend-society' ) . '</p><!-- /wp:paragraph -->' );
}

/**
 * Creates an area's sidebar template part when it doesn't exist yet.
 *
 * @param string $area Area slug.
 * @return string|WP_Error Template part id (theme//slug).
 */
function gend_society_sb_ensure_part( $area ) {
	$slug = gend_society_sb_part_slug( $area );
	$id   = get_stylesheet() . '//' . $slug;
	if ( get_block_template( $id, 'wp_template_part' ) ) {
		return $id;
	}
	$areas = gend_society_sb_areas();
	$req   = new WP_REST_Request( 'POST', '/wp/v2/template-parts' );
	$req->set_body_params(
		array(
			'slug'    => $slug,
			/* translators: %s: site area, e.g. "Blog posts & archives" */
			'title'   => sprintf( __( 'Sidebar: %s', 'gend-society' ), isset( $areas[ $area ] ) ? $areas[ $area ] : $area ),
			'content' => gend_society_sb_starter( $area ),
			'area'    => WP_TEMPLATE_PART_AREA_UNCATEGORIZED,
		)
	);
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	return $id;
}

add_action( 'rest_api_init', 'gend_society_sb_rest' );
/**
 * REST: read and save sidebar settings; create an area's sidebar.
 */
function gend_society_sb_rest() {
	$can = static function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/sidebars',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'gend_society_sb_rest_state',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function ( WP_REST_Request $req ) {
					$area = sanitize_key( (string) $req->get_param( 'area' ) );
					if ( ! isset( gend_society_sb_areas()[ $area ] ) ) {
						return new WP_Error( 'gs_sb_area', __( 'Unknown area.', 'gend-society' ), array( 'status' => 400 ) );
					}
					$all = get_option( GEND_SOCIETY_SIDEBARS_OPTION, array() );
					$all = is_array( $all ) ? $all : array();
					$cur = gend_society_sb_settings( $area );
					foreach ( array( 'position', 'width', 'sticky' ) as $key ) {
						if ( null !== $req->get_param( $key ) ) {
							$cur[ $key ] = $req->get_param( $key );
						}
					}
					$all[ $area ] = array(
						'position' => in_array( $cur['position'], array( 'left', 'right' ), true ) ? $cur['position'] : 'none',
						'width'    => max( 200, min( 480, (int) $cur['width'] ) ),
						'sticky'   => (bool) $cur['sticky'],
					);
					update_option( GEND_SOCIETY_SIDEBARS_OPTION, $all, false );
					if ( 'none' !== $all[ $area ]['position'] ) {
						$made = gend_society_sb_ensure_part( $area );
						if ( is_wp_error( $made ) ) {
							return $made;
						}
					}
					return gend_society_sb_rest_state();
				},
			),
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/sidebars/part',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => static function ( WP_REST_Request $req ) {
				$area = sanitize_key( (string) $req->get_param( 'area' ) );
				if ( ! isset( gend_society_sb_areas()[ $area ] ) ) {
					return new WP_Error( 'gs_sb_area', __( 'Unknown area.', 'gend-society' ), array( 'status' => 400 ) );
				}
				$id = gend_society_sb_ensure_part( $area );
				return is_wp_error( $id ) ? $id : rest_ensure_response( array( 'id' => $id ) );
			},
		)
	);
}

/**
 * Areas with their settings, a page to preview and whether the sidebar exists.
 *
 * @return WP_REST_Response
 */
function gend_society_sb_rest_state() {
	$out = array();
	foreach ( gend_society_sb_areas() as $slug => $label ) {
		$out[] = array_merge(
			gend_society_sb_settings( $slug ),
			array(
				'slug'    => $slug,
				'label'   => $label,
				'part'    => get_stylesheet() . '//' . gend_society_sb_part_slug( $slug ),
				'hasPart' => (bool) get_block_template( get_stylesheet() . '//' . gend_society_sb_part_slug( $slug ), 'wp_template_part' ),
				'url'     => gend_society_sb_sample_url( $slug ),
			)
		);
	}
	return rest_ensure_response( $out );
}

/**
 * A page of the area to preview the sidebar on.
 *
 * @param string $area Area slug.
 * @return string
 */
function gend_society_sb_sample_url( $area ) {
	switch ( $area ) {
		case 'blog':
			$post = get_posts(
				array(
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			return $post ? (string) get_permalink( $post[0] ) : home_url( '/' );
		case 'shop':
			return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		case 'profiles':
			return function_exists( 'bp_members_get_user_url' ) ? (string) bp_members_get_user_url( get_current_user_id() ) : '';
		case 'groups':
			if ( function_exists( 'gend_society_pv_group_id' ) && function_exists( 'bp_get_group_url' ) ) {
				$gid = gend_society_pv_group_id();
				return $gid ? (string) bp_get_group_url( groups_get_group( $gid ) ) : '';
			}
			return '';
		case 'community':
			return function_exists( 'bp_get_members_directory_permalink' ) ? (string) bp_get_members_directory_permalink() : '';
	}
	// The static front page when there is one, else the latest page.
	$front = (int) get_option( 'page_on_front' );
	if ( $front && 'publish' === get_post_status( $front ) ) {
		return (string) get_permalink( $front );
	}
	$page = get_posts(
		array(
			'post_type'   => 'page',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	return $page ? (string) get_permalink( $page[0] ) : home_url( '/' );
}
