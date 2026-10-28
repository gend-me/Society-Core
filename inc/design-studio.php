<?php
/**
 * Design Studio: the GenD dashboard that replaces the Site Editor's landing screen.
 *
 * The bare site-editor.php URL (no route) opens this page instead. The sidebar
 * groups every front-end design surface (Branding, App, Content, and one
 * section per active GenD plugin). Branding panels edit the core settings and
 * the user's global styles directly (same data the Site Editor writes); every
 * template item opens the real block editor canvas inside the studio, so the
 * block editing engine stays core's. The Site Editor itself is never shown on
 * its own: every site-editor.php visit opens the studio (a deep link to one
 * template opens that template in the studio), and the embedded editor wears
 * the GenD skin (assets/design-studio-editor.css).
 *
 * Sections come from gend_society_studio_sections(); other plugins can add or
 * change them through the 'gend_society_studio_sections' filter.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_STUDIO_PAGE = 'gs-design-studio';

/**
 * True when the request is a studio embed (an admin screen shown inside the studio).
 *
 * @return bool
 */
function gend_society_studio_is_embed() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
	if ( isset( $_GET['gs_studio_embed'] ) && '1' === $_GET['gs_studio_embed'] ) {
		return true;
	}
	// Any admin screen opened inside a frame (an editor reached from an embedded
	// screen by a link or a script) stays chromeless too.
	$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
	return is_admin() && 'iframe' === $dest;
}

/**
 * Is a plugin active (single site or network)? Uses the menu helper when it is loaded.
 *
 * @param string $plugin Plugin basename.
 * @return bool
 */
function gend_society_studio_plugin_active( $plugin ) {
	if ( function_exists( 'gend_society_plugin_active' ) ) {
		return gend_society_plugin_active( $plugin );
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( $plugin ) || is_plugin_active_for_network( $plugin );
}

add_action( 'admin_menu', 'gend_society_studio_register_page', 20 );
/**
 * Registers the studio as a hidden admin page (reached through site-editor.php).
 */
function gend_society_studio_register_page() {
	add_submenu_page(
		'', // Hidden: the Theme Editor menu item (site-editor.php) redirects here.
		__( 'Design Studio', 'gend-society' ),
		__( 'Design Studio', 'gend-society' ),
		'edit_theme_options',
		GEND_SOCIETY_STUDIO_PAGE,
		'gend_society_studio_render_page'
	);
}

add_action( 'admin_menu', 'gend_society_studio_register_group_styling_page', 20 );
/**
 * Hidden page that shows the existing Group Styling builder (Social Profiles'
 * gdc_gs_render_group_styling_admin_panel(): header templates, group pages and
 * navigation) on its own, so the studio can show it.
 */
function gend_society_studio_register_group_styling_page() {
	if ( ! function_exists( 'gdc_gs_render_group_styling_admin_panel' ) ) {
		return;
	}
	add_submenu_page(
		'',
		__( 'Group Styling', 'gend-society' ),
		__( 'Group Styling', 'gend-society' ),
		'edit_theme_options',
		'gs-studio-group-styling',
		function () {
			echo '<div class="wrap gs-studio-panel">';
			gdc_gs_render_group_styling_admin_panel();
			gend_society_studio_group_layout_section();
			echo '</div>';
		}
	);
}

/**
 * Layout & Sidebars section under the Group Styling builder: the group sidebar
 * as a block layout (inc/directory-styling.php), with today's widgets as the start.
 */
function gend_society_studio_group_layout_section() {
	if ( ! function_exists( 'gend_society_ds_layout' ) ) {
		return;
	}
	$published = gend_society_ds_layout( 'group-sidebar' );
	$draft     = $published ? null : get_posts(
		array(
			'post_type'      => 'gdc_bp_layout',
			'post_status'    => 'draft',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one admin lookup.
			'meta_key'       => '_gdc_layout_slug',
			'meta_value'     => 'group-sidebar', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $published ) {
		$status = __( 'Block layout (published): it replaces the Youzify sidebar widgets on every group.', 'gend-society' );
	} elseif ( $draft ) {
		$status = __( 'Block layout (draft): groups still show the Youzify sidebar widgets until you publish it.', 'gend-society' );
	} else {
		$status = __( 'Youzify sidebar widgets (Group Administrators, Group Moderators).', 'gend-society' );
	}
	echo '<section id="gs-group-layout"><h3>' . esc_html__( 'Layout & Sidebars', 'gend-society' ) . '</h3>'
		. '<p class="description">' . esc_html__( 'The sidebar beside every group page, and the group Feed tab. Design them with blocks (Group Members, Group About, the activity blocks, any other block); each starts as a copy of today’s and goes live when you publish it.', 'gend-society' ) . '</p>'
		. '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Area', 'gend-society' ) . '</th><th>' . esc_html__( 'Now showing', 'gend-society' ) . '</th><th></th></tr></thead><tbody>'
		. '<tr><td><strong>' . esc_html__( 'Group sidebar', 'gend-society' ) . '</strong></td><td>' . esc_html( $status ) . '</td>'
		. '<td style="text-align:right"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=gs-studio-layout&layout=group-sidebar' ) ) . '">' . esc_html( $published || $draft ? __( 'Edit the sidebar', 'gend-society' ) : __( 'Design the sidebar', 'gend-society' ) ) . '</a></td></tr>'
		. gend_society_studio_group_feed_row() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		. '</tbody></table></section>';
}

/**
 * The Group feed row of the Layout & Sidebars table (the group's activity tab).
 *
 * @return string
 */
function gend_society_studio_group_feed_row() {
	if ( ! function_exists( 'gend_society_ab_wrap' ) ) {
		return '';
	}
	$published = gend_society_ds_layout( 'group-feed' );
	$draft     = $published ? null : get_posts(
		array(
			'post_type'      => 'gdc_bp_layout',
			'post_status'    => 'draft',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one admin lookup.
			'meta_key'       => '_gdc_layout_slug',
			'meta_value'     => 'group-feed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $published ) {
		$status = __( 'Block layout (published): every group’s Feed tab uses it.', 'gend-society' );
	} elseif ( $draft ) {
		$status = __( 'Block layout (draft): groups still show the Youzify feed until you publish it.', 'gend-society' );
	} else {
		$status = __( 'Youzify group feed (filter, posting, posts).', 'gend-society' );
	}
	return '<tr><td><strong>' . esc_html__( 'Group feed', 'gend-society' ) . '</strong></td><td>' . esc_html( $status ) . '</td>'
		. '<td style="text-align:right"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=gs-studio-layout&layout=group-feed' ) ) . '">' . esc_html( $published || $draft ? __( 'Edit the feed', 'gend-society' ) : __( 'Design the feed', 'gend-society' ) ) . '</a></td></tr>';
}

add_action( 'admin_menu', 'gend_society_studio_register_profile_styling_page', 20 );
/**
 * Hidden page for the Profile Styling builder (inc/profile-styling.php):
 * profile header templates, tab layouts and the profile menu.
 */
function gend_society_studio_register_profile_styling_page() {
	if ( ! function_exists( 'gend_society_ps_rest_state' ) ) {
		return;
	}
	add_submenu_page(
		'',
		__( 'Profile Styling', 'gend-society' ),
		__( 'Profile Styling', 'gend-society' ),
		'edit_theme_options',
		'gs-studio-profile-styling',
		function () {
			echo '<div class="wrap gs-studio-panel"><div id="gs-ps-root"><p class="description">' . esc_html__( 'Loading…', 'gend-society' ) . '</p></div></div>';
		}
	);
}

add_action( 'admin_menu', 'gend_society_studio_register_layout_page', 20 );
/**
 * Hidden page that opens a directory layout (inc/directory-styling.php) in the
 * block editor, creating it as a draft first if needed.
 */
function gend_society_studio_register_layout_page() {
	if ( ! function_exists( 'gend_society_ds_layout_id' ) ) {
		return;
	}
	$hook = add_submenu_page( '', __( 'Directory Layout', 'gend-society' ), __( 'Directory Layout', 'gend-society' ), 'edit_theme_options', 'gs-studio-layout', '__return_null' );
	add_action(
		'load-' . $hook,
		function () {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- opens (or creates as a draft) an admin-only layout.
			$slug = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( $_GET['layout'] ) ) : '';
			$id   = gend_society_ds_layout_id( $slug );
			if ( ! $id ) {
				wp_die( esc_html__( 'Unknown layout.', 'gend-society' ) );
			}
			wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
			exit;
		}
	);
}

add_action( 'admin_enqueue_scripts', 'gend_society_studio_panel_styles' );
/**
 * Dark skin for the Group / Profile Styling builders shown inside the studio,
 * and the Profile Styling builder's script.
 */
function gend_society_studio_panel_styles() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! in_array( $page, array( 'gs-studio-group-styling', 'gs-studio-profile-styling' ), true ) ) {
		return;
	}
	$css = 'assets/design-studio-panel.css';
	wp_enqueue_style( 'gs-studio-panel', GEND_SOCIETY_URL . $css, array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $css ) );
	if ( 'gs-studio-profile-styling' === $page ) {
		$js = 'assets/profile-styling-admin.js';
		wp_enqueue_script( 'gs-profile-styling-admin', GEND_SOCIETY_URL . $js, array( 'wp-api-fetch', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
		wp_set_script_translations( 'gs-profile-styling-admin', 'gend-society' );
	}
}

add_action( 'load-site-editor.php', 'gend_society_studio_redirect_landing' );
/**
 * Every Site Editor visit opens the studio; only the studio's own embed loads
 * core's editor. A deep link to one template or template part
 * (?p=/wp_template/<id>, or the older ?postType=&postId=) opens it in the studio.
 */
function gend_society_studio_redirect_landing() {
	if ( ! wp_is_block_theme() || ! current_user_can( 'edit_theme_options' ) || gend_society_studio_is_embed() ) {
		return;
	}
	$target = admin_url( 'admin.php?page=' . GEND_SOCIETY_STUDIO_PAGE );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- routing only, nothing is changed.
	$route = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';
	if ( '' === $route && isset( $_GET['postType'], $_GET['postId'] ) ) {
		$route = '/' . sanitize_key( wp_unslash( $_GET['postType'] ) ) . '/' . sanitize_text_field( wp_unslash( $_GET['postId'] ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( preg_match( '#^/(wp_template|wp_template_part)/(.+)$#', $route, $m ) ) {
		$target .= '#/edit/' . $m[1] . '/' . rawurlencode( $m[2] );
	}
	wp_safe_redirect( $target );
	exit;
}

/**
 * The studio sections: what the sidebar shows, gated on each plugin being active.
 *
 * Item kinds:
 *   panel     a studio panel ('logo', 'favicon', 'site-name', 'palettes', 'fonts')
 *   template  a block template (slug), opened in the block editor canvas
 *   part      a template part (slug)
 *   templates a list of templates filtered by 'match' (page templates ...)
 *   menus     the site's navigation menus
 *   admin     an existing admin screen shown inside the studio ('url')
 *
 * A template item may name 'fallback' (create from that template when missing).
 *
 * @return array[]
 */
function gend_society_studio_sections() {
	$has_wc      = class_exists( 'WooCommerce' ) || gend_society_studio_plugin_active( 'woocommerce/woocommerce.php' );
	$has_store   = $has_wc && gend_society_studio_plugin_active( 'online-store/online-store.php' );
	$has_sales   = gend_society_studio_plugin_active( 'sales-team/advanced-affiliate-system.php' );
	$has_proj    = gend_society_studio_plugin_active( 'projects/project-service-orders.php' );
	$has_social  = gend_society_studio_plugin_active( 'social-network/social-network.php' );
	$has_rewards = gend_society_studio_plugin_active( 'reward-programs/reward-programs.php' );
	$has_cp      = gend_society_studio_plugin_active( 'contracts-and-payments/contracts-and-payments.php' );
	$builder     = function_exists( 'gdc_gs_render_group_styling_admin_panel' ) && function_exists( 'gdc_gs_endpoints' )
		? admin_url( 'admin.php?page=gs-studio-group-styling' )
		: '';
	$header_id   = $builder && function_exists( 'gdc_gs_get_site_default_header_id' ) ? (int) gdc_gs_get_site_default_header_id() : 0;
	$profiles    = function_exists( 'gend_society_ps_rest_state' ) ? admin_url( 'admin.php?page=gs-studio-profile-styling' ) : '';
	$p_header_id = $profiles ? gend_society_ps_default_header_id() : 0;
	$layouts     = function_exists( 'gend_society_ds_layout_id' );
	$sp_ready    = function_exists( 'gend_society_sp_rest_state' ) && $layouts;
	$layout_url  = function ( $slug ) {
		return admin_url( 'admin.php?page=gs-studio-layout&layout=' . $slug );
	};
	$yz          = function ( $page, $tab = '' ) {
		return admin_url( 'admin.php?page=' . $page . ( $tab ? '&tab=' . $tab : '' ) );
	};

	$sections = array(
		array(
			'id'     => 'branding',
			'label'  => __( 'Branding', 'gend-society' ),
			'icon'   => 'art',
			'blurb'  => __( 'Logo, colours, type, styling, voice and cast: the brand every page and every AI request shares.', 'gend-society' ),
			'active' => true,
			'groups' => array(
				array(
					'items' => array(
						array( 'id' => 'logo', 'label' => __( 'Logo', 'gend-society' ), 'kind' => 'panel', 'panel' => 'logo' ),
						array( 'id' => 'favicon', 'label' => __( 'Favicon', 'gend-society' ), 'kind' => 'panel', 'panel' => 'favicon' ),
						array( 'id' => 'site-name', 'label' => __( 'Site Name', 'gend-society' ), 'kind' => 'panel', 'panel' => 'site-name' ),
						array( 'id' => 'palettes', 'label' => __( 'Colour Schemes', 'gend-society' ), 'kind' => 'panel', 'panel' => 'palettes' ),
						array( 'id' => 'fonts', 'label' => __( 'Font Types', 'gend-society' ), 'kind' => 'panel', 'panel' => 'fonts' ),
						array( 'id' => 'voice', 'label' => __( 'Voice & Tone', 'gend-society' ), 'kind' => 'panel', 'panel' => 'brand-voice' ),
						array( 'id' => 'app-styling', 'label' => __( 'App Styling', 'gend-society' ), 'kind' => 'panel', 'panel' => 'brand-style' ),
						array( 'id' => 'cast', 'label' => __( 'Cast Members', 'gend-society' ), 'kind' => 'panel', 'panel' => 'brand-cast' ),
					),
				),
			),
		),
		array(
			'id'     => 'app',
			'label'  => __( 'App', 'gend-society' ),
			'icon'   => 'layout',
			'blurb'  => __( 'The frame around every page: header, menus and footer.', 'gend-society' ),
			'active' => true,
			'groups' => array(
				array(
					'items' => array(
						array( 'id' => 'header', 'label' => __( 'Header', 'gend-society' ), 'kind' => 'part', 'slug' => 'header' ),
						array( 'id' => 'menus', 'label' => __( 'Menus', 'gend-society' ), 'kind' => 'menus' ),
						array( 'id' => 'footer', 'label' => __( 'Footer', 'gend-society' ), 'kind' => 'part', 'slug' => 'footer' ),
						array( 'id' => 'sidebars', 'label' => __( 'Sidebars', 'gend-society' ), 'kind' => 'panel', 'panel' => 'sidebars' ),
						array( 'id' => '404', 'label' => __( 'Page 404', 'gend-society' ), 'kind' => 'template', 'slug' => '404' ),
						array( 'id' => 'coming-soon', 'label' => __( 'Coming Soon', 'gend-society' ), 'kind' => 'template', 'slug' => 'coming-soon' ),
					),
				),
			),
		),
		array(
			'id'     => 'content',
			'label'  => __( 'Content', 'gend-society' ),
			'icon'   => 'media-document',
			'blurb'  => __( 'How pages, posts and archives are laid out.', 'gend-society' ),
			'active' => true,
			'groups' => array(
				array(
					'items' => array(
						array( 'id' => 'page-templates', 'label' => __( 'Page Templates', 'gend-society' ), 'kind' => 'templates', 'match' => 'page' ),
						array( 'id' => 'single', 'label' => __( 'Blog Post Templates', 'gend-society' ), 'kind' => 'template', 'slug' => 'single' ),
						array( 'id' => 'home', 'label' => __( 'Blog All', 'gend-society' ), 'kind' => 'template', 'slug' => 'home', 'fallback' => 'index' ),
						array( 'id' => 'archive', 'label' => __( 'All Archives', 'gend-society' ), 'kind' => 'template', 'slug' => 'archive', 'fallback' => 'index' ),
						array( 'id' => 'category', 'label' => __( 'Category Templates', 'gend-society' ), 'kind' => 'template', 'slug' => 'category', 'fallback' => 'archive' ),
					),
				),
			),
		),
		array(
			'id'       => 'store',
			'label'    => __( 'Store', 'gend-society' ),
			'icon'     => 'store',
			'blurb'    => __( 'Catalogue, product pages, cart and checkout.', 'gend-society' ),
			'active'   => $has_store,
			'requires' => __( 'Store Management', 'gend-society' ),
			'groups'   => array(
				array(
					'label' => __( 'Shopping', 'gend-society' ),
					'items' => array(
						array( 'id' => 'archive-product', 'label' => __( 'Product Catalog', 'gend-society' ), 'kind' => 'template', 'slug' => 'archive-product' ),
						array( 'id' => 'product-search-results', 'label' => __( 'Product Search Results', 'gend-society' ), 'kind' => 'template', 'slug' => 'product-search-results' ),
						array( 'id' => 'taxonomy-product_attribute', 'label' => __( 'Products by Attribute', 'gend-society' ), 'kind' => 'template', 'slug' => 'taxonomy-product_attribute' ),
						array( 'id' => 'page-cart', 'label' => __( 'Cart', 'gend-society' ), 'kind' => 'template', 'slug' => 'page-cart' ),
						array( 'id' => 'mini-cart', 'label' => __( 'Mini-Cart', 'gend-society' ), 'kind' => 'part', 'slug' => 'mini-cart' ),
						array( 'id' => 'page-checkout', 'label' => __( 'Checkout', 'gend-society' ), 'kind' => 'template', 'slug' => 'page-checkout' ),
						array( 'id' => 'order-confirmation', 'label' => __( 'Order Confirmation', 'gend-society' ), 'kind' => 'template', 'slug' => 'order-confirmation' ),
					),
				),
				array(
					'label' => __( 'Products', 'gend-society' ),
					'items' => array(
						array( 'id' => 'single-product', 'label' => __( 'Single Product', 'gend-society' ), 'kind' => 'template', 'slug' => 'single-product' ),
						array( 'id' => 'add-to-cart', 'label' => __( 'Add to Cart', 'gend-society' ), 'kind' => 'part', 'slug' => 'add-to-cart' ),
						array( 'id' => 'product-simple', 'label' => __( 'Simple Product', 'gend-society' ), 'kind' => 'template', 'slug' => 'single-product-type-simple' ),
						array( 'id' => 'product-external', 'label' => __( 'External Product', 'gend-society' ), 'kind' => 'template', 'slug' => 'single-product-type-external' ),
						array( 'id' => 'product-variable', 'label' => __( 'Variable Product', 'gend-society' ), 'kind' => 'template', 'slug' => 'single-product-type-variable' ),
						array( 'id' => 'product-grouped', 'label' => __( 'Grouped Product', 'gend-society' ), 'kind' => 'template', 'slug' => 'single-product-type-grouped' ),
					),
				),
			),
		),
		array(
			'id'       => 'sales',
			'label'    => __( 'Sales', 'gend-society' ),
			'icon'     => 'chart-line',
			'blurb'    => __( 'Affiliate dashboard, referral links and commissions.', 'gend-society' ),
			'active'   => $has_sales,
			'requires' => __( 'Sales Team', 'gend-society' ),
			'groups'   => array(
				array(
					'items' => gend_society_studio_shortcode_items( array( 'affiliate_sales_dashboard', 'affiliate_link', 'affiliate_earnings', 'referral_team', 'referred_sales_commissions', 'team_sales_commissions', 'withdraw_overview', 'member_order_commissions', 'member_product_commissions' ) ),
				),
			),
		),
		array(
			'id'       => 'projects',
			'label'    => __( 'Projects', 'gend-society' ),
			'icon'     => 'portfolio',
			'blurb'    => __( 'Team dashboards your members work in.', 'gend-society' ),
			'active'   => $has_proj,
			'requires' => __( 'Remote Projects', 'gend-society' ),
			'groups'   => array(
				array(
					'items' => array(
						array( 'id' => 'team-dashboard', 'label' => __( 'Team Dashboard Pages', 'gend-society' ), 'kind' => 'template', 'slug' => 'page-team-dashboard' ),
						array( 'id' => 'team-dashboards', 'label' => __( 'Custom Team Dashboards', 'gend-society' ), 'kind' => 'admin', 'url' => admin_url( 'admin.php?page=psoo-projects&tab=dashboards' ) ),
					),
				),
			),
		),
		array(
			'id'       => 'social',
			'label'    => __( 'Social', 'gend-society' ),
			'icon'     => 'groups',
			'blurb'    => __( 'Member profiles, groups and the activity feed.', 'gend-society' ),
			'active'   => $has_social,
			'requires' => __( 'Social Profiles', 'gend-society' ),
			'groups'   => array(
				// One page each: header, menu + pages by audience, sidebars, directory (inc/social-pages.php).
				// The separate settings screens only when that builder is missing. One page per entry, so no headings.
				array(
					'items' => array_merge(
						$sp_ready && $profiles
							? array( array( 'id' => 'profile-pages', 'label' => __( 'Profile Pages', 'gend-society' ), 'kind' => 'panel', 'panel' => 'social-pages', 'scope' => 'profiles' ) )
							: array(
								array( 'id' => 'members-directory', 'label' => __( "Member's Directory", 'gend-society' ), 'kind' => 'admin', 'url' => $layouts ? $layout_url( 'members-directory' ) : $yz( 'youzify-panel', 'members-directory' ) ),
								array( 'id' => 'profile-header', 'label' => __( 'Profile Header', 'gend-society' ), 'kind' => 'admin', 'url' => $p_header_id ? admin_url( 'post.php?post=' . $p_header_id . '&action=edit' ) : ( $profiles ? $profiles : $yz( 'youzify-profile-settings', 'header' ) ) ),
								array( 'id' => 'profile-pages', 'label' => __( 'Profile Pages, Menu & Layout', 'gend-society' ), 'kind' => 'admin', 'url' => $profiles ? $profiles : $yz( 'youzify-profile-settings', 'tabs' ) ),
							),
						$sp_ready && $builder
							? array( array( 'id' => 'group-pages', 'label' => __( 'Group Pages', 'gend-society' ), 'kind' => 'panel', 'panel' => 'social-pages', 'scope' => 'groups' ) )
							: array(
								array( 'id' => 'groups-directory', 'label' => __( 'Group Directory', 'gend-society' ), 'kind' => 'admin', 'url' => $layouts ? $layout_url( 'groups-directory' ) : $yz( 'youzify-panel', 'groups-directory' ) ),
								array( 'id' => 'group-header', 'label' => __( 'Group Header', 'gend-society' ), 'kind' => 'admin', 'url' => $header_id ? admin_url( 'post.php?post=' . $header_id . '&action=edit' ) : ( $builder ? $builder : $yz( 'youzify-panel', 'groups' ) . '#header' ) ),
								array( 'id' => 'group-pages', 'label' => __( 'Group Pages, Menu & Layout', 'gend-society' ), 'kind' => 'admin', 'url' => $builder ? $builder : $yz( 'youzify-panel', 'groups' ) ),
							),
						// Feed layout + reactions on one page; the old two screens only without the builder.
						$sp_ready
							? array( array( 'id' => 'activity-feed', 'label' => __( 'Activity Feed', 'gend-society' ), 'kind' => 'panel', 'panel' => 'social-pages', 'scope' => 'activity' ) )
							: array(
								array( 'id' => 'activity-styling', 'label' => __( 'Activity Feed', 'gend-society' ), 'kind' => 'admin', 'url' => $layouts ? $layout_url( 'activity' ) : $yz( 'youzify-panel', 'wall' ) ),
								array( 'id' => 'activity-reactions', 'label' => __( 'Activity Reactions', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-extensions-settings', 'activity-reactions' ) ),
							),
						function_exists( 'gend_society_yz_rest_get' )
							? array( array( 'id' => 'youzify-styles', 'label' => __( 'Youzify Styling', 'gend-society' ), 'kind' => 'panel', 'panel' => 'youzify' ) )
							: array()
					),
				),
			),
		),
		array(
			'id'       => 'bank',
			'label'    => __( 'Bank', 'gend-society' ),
			'icon'     => 'money-alt',
			'blurb'    => __( 'Wallet pages where members see their balances.', 'gend-society' ),
			'active'   => $has_rewards || $has_cp,
			'requires' => __( 'Reward Programs', 'gend-society' ),
			'groups'   => array(
				array(
					'items' => array(
						array( 'id' => 'wallet', 'label' => __( 'Wallet Pages', 'gend-society' ), 'kind' => 'template', 'slug' => 'page-wallet' ),
					),
				),
			),
		),
	);

	/**
	 * Filters the Design Studio sidebar sections.
	 *
	 * @param array[] $sections Sections (see gend_society_studio_sections()).
	 */
	$sections = apply_filters( 'gend_society_studio_sections', $sections );

	// Drop inactive or empty groups so a section only lists what its plugins provide,
	// and active sections left with nothing to show.
	foreach ( $sections as $i => &$section ) {
		$section['groups'] = array_values(
			array_filter(
				$section['groups'],
				function ( $group ) {
					return ( ! isset( $group['active'] ) || $group['active'] ) && ! empty( $group['items'] );
				}
			)
		);
		if ( $section['active'] && ! $section['groups'] ) {
			unset( $sections[ $i ] );
		}
	}
	unset( $section );

	return array_values( $sections );
}

/**
 * Renders the studio shell. The app itself is assets/design-studio.js.
 */
function gend_society_studio_render_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to customize this site.', 'gend-society' ) );
	}
	echo '<div id="gs-studio-root" class="gs-studio" aria-busy="true"><div class="gs-studio-boot"><span class="gs-studio-spinner" aria-hidden="true"></span>' . esc_html__( 'Opening the Design Studio…', 'gend-society' ) . '</div></div>';
	echo '<noscript>' . esc_html__( 'The Design Studio needs JavaScript.', 'gend-society' ) . '</noscript>';
}

/**
 * What the studio says about a shortcode (curated; plugins can add their own).
 *
 * Keys: label, description, shows (string[]), audience, template (block
 * template slug of pages that use it).
 *
 * @return array[]
 */
function gend_society_studio_shortcodes() {
	$login = __( 'Logged-in members (visitors see a log-in message).', 'gend-society' );
	return apply_filters(
		'gend_society_studio_shortcodes',
		array(
			'affiliate_sales_dashboard'  => array(
				'label'       => __( 'Affiliate Dashboard', 'gend-society' ),
				'description' => __( 'The member’s full sales dashboard: their sales, commissions, referred members, tracking links and payment history in one place.', 'gend-society' ),
				'shows'       => array(
					__( 'Monthly and lifetime orders, sales and commission', 'gend-society' ),
					__( 'Commission rates and a direct-earnings projection', 'gend-society' ),
					__( 'Referral performance pulse: sign-ups, tracking-URL visitors, conversion rate', 'gend-society' ),
					__( 'Sales Team members with search and filters', 'gend-society' ),
					__( 'Referred sales and referral payment history', 'gend-society' ),
					__( 'Tracking URLs and linked apps, with product search', 'gend-society' ),
				),
				'audience'    => $login,
				'template'    => 'page-affiliate-dashboard',
			),
			'affiliate_link'             => array(
				'label'       => __( 'Affiliate Link', 'gend-society' ),
				'description' => __( 'The member’s own referral link, ready to copy and share.', 'gend-society' ),
				'shows'       => array( __( 'Personal referral URL with a copy button', 'gend-society' ) ),
				'audience'    => $login,
			),
			'affiliate_earnings'         => array(
				'label'       => __( 'Affiliate Earnings', 'gend-society' ),
				'description' => __( 'An overview of what the member has earned from referrals.', 'gend-society' ),
				'shows'       => array( __( 'Earnings totals', 'gend-society' ) ),
				'audience'    => $login,
			),
			'referral_team'              => array(
				'label'       => __( 'Sales Team', 'gend-society' ),
				'description' => __( 'The members this member has referred (their Sales Team).', 'gend-society' ),
				'shows'       => array( __( 'Referred members list', 'gend-society' ) ),
				'audience'    => $login,
			),
			'referred_sales_commissions' => array(
				'label'       => __( 'Referred Sales', 'gend-society' ),
				'description' => __( 'Commissions from sales the member referred directly.', 'gend-society' ),
				'shows'       => array( __( 'Referred orders and their commission', 'gend-society' ) ),
				'audience'    => $login,
			),
			'team_sales_commissions'     => array(
				'label'       => __( 'Team Sales', 'gend-society' ),
				'description' => __( 'Commissions from sales made by the member’s Sales Team.', 'gend-society' ),
				'shows'       => array( __( 'Team orders and override commission', 'gend-society' ) ),
				'audience'    => $login,
			),
			'withdraw_overview'          => array(
				'label'       => __( 'Withdraw', 'gend-society' ),
				'description' => __( 'The point types the member can withdraw, each with a withdraw button.', 'gend-society' ),
				'shows'       => array( __( 'Withdrawable balances and withdraw buttons', 'gend-society' ) ),
				'audience'    => $login,
			),
			'member_order_commissions'   => array(
				'label'       => __( 'Order Commissions', 'gend-society' ),
				'description' => __( 'Commission earned on each order, for the member.', 'gend-society' ),
				'shows'       => array( __( 'Orders with their commission', 'gend-society' ) ),
				'audience'    => $login,
			),
			'member_product_commissions' => array(
				'label'       => __( 'Product Commissions', 'gend-society' ),
				'description' => __( 'What the member earns per product they promote.', 'gend-society' ),
				'shows'       => array( __( 'Products with their commission rate', 'gend-society' ) ),
				'audience'    => $login,
			),
		)
	);
}

/**
 * Studio items for the shortcodes that exist on this site.
 *
 * @param string[] $tags Shortcode tags, in order.
 * @return array[]
 */
function gend_society_studio_shortcode_items( array $tags ) {
	$info  = gend_society_studio_shortcodes();
	$items = array();
	foreach ( $tags as $tag ) {
		if ( ! shortcode_exists( $tag ) ) {
			continue;
		}
		$items[] = array(
			'id'    => 'sc-' . str_replace( '_', '-', $tag ),
			'label' => isset( $info[ $tag ]['label'] ) ? $info[ $tag ]['label'] : $tag,
			'kind'  => 'panel',
			'panel' => 'shortcode',
			'tag'   => $tag,
		);
	}
	return $items;
}

/**
 * Details read from a shortcode's code: doc comment, attributes, source.
 *
 * @param string $tag Shortcode tag.
 * @return array
 */
function gend_society_studio_shortcode_code_info( $tag ) {
	global $shortcode_tags;
	$out = array(
		'doc'        => '',
		'attributes' => array(),
		'source'     => '',
	);
	if ( empty( $shortcode_tags[ $tag ] ) ) {
		return $out;
	}
	$handler = $shortcode_tags[ $tag ];
	try {
		if ( is_array( $handler ) ) {
			$ref = new ReflectionMethod( $handler[0], $handler[1] );
		} elseif ( is_string( $handler ) && function_exists( $handler ) ) {
			$ref = new ReflectionFunction( $handler );
		} elseif ( $handler instanceof Closure ) {
			$ref = new ReflectionFunction( $handler );
		} else {
			return $out;
		}
	} catch ( ReflectionException $e ) {
		return $out;
	}
	$file = (string) $ref->getFileName();
	if ( '' === $file || ! is_readable( $file ) ) {
		return $out;
	}
	$out['source'] = ltrim( str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', wp_normalize_path( $file ) ), '/' ) . ':' . $ref->getStartLine();
	$doc           = (string) $ref->getDocComment();
	if ( '' !== $doc ) {
		$doc        = trim( preg_replace( '#^\s*(/\*\*|\*/|\*)\s?#m', '', $doc ) );
		$out['doc'] = trim( (string) preg_replace( '#\[[a-z0-9_]+\]\s*-?\s*#', '', strtok( $doc, "\n" ) ) );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin file for its shortcode attributes.
	$lines = explode( "\n", (string) file_get_contents( $file ) );
	$body  = implode( "\n", array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
	if ( preg_match( '#shortcode_atts\s*\(\s*(?:array\s*\(|\[)(.*?)(?:\)|\])\s*,#s', $body, $m ) && preg_match_all( '#[\'"]([a-z0-9_-]+)[\'"]\s*=>\s*([^,\n]+)#i', $m[1], $pairs, PREG_SET_ORDER ) ) {
		foreach ( $pairs as $pair ) {
			$out['attributes'][] = array(
				'name'    => $pair[1],
				'default' => trim( trim( $pair[2] ), '\'"' ),
			);
		}
	}
	return $out;
}

add_action( 'admin_enqueue_scripts', 'gend_society_studio_enqueue' );
/**
 * Loads the studio app on its page.
 *
 * @param string $hook Admin page hook.
 */
function gend_society_studio_enqueue( $hook ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing.
	if ( ! isset( $_GET['page'] ) || GEND_SOCIETY_STUDIO_PAGE !== $_GET['page'] ) {
		return;
	}
	unset( $hook );
	wp_enqueue_media();
	$css = 'assets/design-studio.css';
	$js  = 'assets/design-studio.js';
	wp_enqueue_style( 'gs-design-studio', GEND_SOCIETY_URL . $css, array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $css ) );
	$deps = array( 'wp-api-fetch', 'wp-i18n', 'wp-url' );
	if ( function_exists( 'gend_society_sp_rest_state' ) ) {
		// Social › Group / Profile Pages builder.
		wp_enqueue_style( 'gs-social-pages', GEND_SOCIETY_URL . 'assets/social-pages.css', array( 'gs-design-studio' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/social-pages.css' ) );
		wp_enqueue_script( 'gs-social-pages', GEND_SOCIETY_URL . 'assets/social-pages.js', array( 'wp-api-fetch', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/social-pages.js' ), true );
		wp_set_script_translations( 'gs-social-pages', 'gend-society' );
		$deps[] = 'gs-social-pages';
	}
	if ( function_exists( 'gend_society_bk_get' ) ) {
		// Branding › Voice & Tone, App Styling, Cast Members (inc/brand-kit.php).
		wp_enqueue_style( 'gs-brand-kit-demo', GEND_SOCIETY_URL . 'assets/brand-kit-demo.css', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/brand-kit-demo.css' ) );
		wp_enqueue_style( 'gs-brand-kit', GEND_SOCIETY_URL . 'assets/brand-kit.css', array( 'gs-design-studio' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/brand-kit.css' ) );
		wp_enqueue_script( 'gs-brand-kit', GEND_SOCIETY_URL . 'assets/brand-kit.js', array( 'wp-api-fetch', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/brand-kit.js' ), true );
		wp_set_script_translations( 'gs-brand-kit', 'gend-society' );
		$deps[] = 'gs-brand-kit';
	}
	wp_enqueue_script( 'gs-design-studio', GEND_SOCIETY_URL . $js, $deps, GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_set_script_translations( 'gs-design-studio', 'gend-society' );

	$theme      = wp_get_theme();
	$sections   = gend_society_studio_sections();
	$gs_post_id = class_exists( 'WP_Theme_JSON_Resolver' ) ? WP_Theme_JSON_Resolver::get_user_global_styles_post_id() : 0;

	wp_localize_script(
		'gs-design-studio',
		'gsStudio',
		array(
			'sections'       => $sections,
			'isBlockTheme'   => wp_is_block_theme(),
			'stylesheet'     => get_stylesheet(),
			'themeName'      => $theme->get( 'Name' ),
			'globalStylesId' => (int) $gs_post_id,
			'siteEditorUrl'  => admin_url( 'site-editor.php' ),
			'themesUrl'      => admin_url( 'themes.php' ),
			'dashboardUrl'   => admin_url( 'index.php' ),
			'pluginsUrl'     => admin_url( 'plugins.php' ),
			'homeUrl'        => home_url( '/' ),
			'themeUrl'       => get_stylesheet_directory_uri(),
			'specimenUrl'    => add_query_arg( 'gs_studio_specimen', '1', home_url( '/' ) ),
			'siteTitle'      => get_bloginfo( 'name' ),
			'restPreview'    => 'gend-society/v1/studio/preview-css',
		)
	);
}

add_filter( 'admin_body_class', 'gend_society_studio_body_class' );
/**
 * Body classes for the studio page and for screens embedded in it.
 *
 * @param string $classes Space-separated classes.
 * @return string
 */
function gend_society_studio_body_class( $classes ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing.
	if ( isset( $_GET['page'] ) && GEND_SOCIETY_STUDIO_PAGE === $_GET['page'] ) {
		$classes .= ' gs-studio-page';
	}
	if ( gend_society_studio_is_embed() ) {
		$classes .= ' gs-studio-embed';
	}
	return $classes;
}

add_action( 'enqueue_block_assets', 'gend_society_studio_embed_canvas_css' );
/**
 * In a studio editor, the canvas around the page or part is dark like the
 * studio (core's default is light grey, which showed around headers and footers).
 */
function gend_society_studio_embed_canvas_css() {
	if ( ! is_admin() || ! gend_society_studio_is_embed() ) {
		return;
	}
	wp_register_style( 'gend-society-studio-canvas', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-studio-canvas' );
	wp_add_inline_style( 'gend-society-studio-canvas', 'html.block-editor-iframe__html{background:#0b0e14}' );
}

add_action( 'enqueue_block_assets', 'gend_society_studio_menu_canvas_css' );
/**
 * A menu edited on its own sits straight on the site background, without the
 * header that gives it its colours there. When the site's text colour can't be
 * read on its background (dark background, default dark text), the menu shows
 * in a colour that can.
 */
function gend_society_studio_menu_canvas_css() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$p = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$type = isset( $_GET['postType'] ) ? sanitize_key( wp_unslash( $_GET['postType'] ) ) : '';
	if ( ! is_admin() || ( 0 !== strpos( $p, '/wp_navigation/' ) && 'wp_navigation' !== $type ) || ! function_exists( 'wp_get_global_styles' ) ) {
		return;
	}
	$bg   = gend_society_studio_rgb( wp_get_global_styles( array( 'color', 'background' ) ) );
	$text = gend_society_studio_rgb( wp_get_global_styles( array( 'color', 'text' ) ) );
	if ( ! $bg || ! $text || gend_society_studio_contrast( $bg, $text ) >= 3 ) {
		return;
	}
	$fix = gend_society_studio_contrast( $bg, array( 255, 255, 255 ) ) >= gend_society_studio_contrast( $bg, array( 17, 17, 17 ) ) ? '#f1f5f9' : '#111111';
	wp_register_style( 'gend-society-studio-menu-canvas', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-studio-menu-canvas' );
	wp_add_inline_style( 'gend-society-studio-menu-canvas', '.editor-styles-wrapper{color:' . $fix . '}' );
}

/**
 * A colour from global styles (hex, or a palette preset reference) as RGB.
 *
 * @param mixed $value Colour value.
 * @return int[]|null
 */
function gend_society_studio_rgb( $value ) {
	if ( ! is_string( $value ) ) {
		return null;
	}
	if ( preg_match( '/^var:preset\|color\|([a-z0-9-]+)$|var\(--wp--preset--color--([a-z0-9-]+)\)/i', $value, $m ) ) {
		$slug = '' !== $m[1] ? $m[1] : $m[2];
		$value = null;
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette' ) ) as $origin ) {
			foreach ( (array) $origin as $c ) {
				if ( isset( $c['slug'], $c['color'] ) && $c['slug'] === $slug ) {
					$value = $c['color'];
				}
			}
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
	}
	$hex = ltrim( trim( $value ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( ! preg_match( '/^[0-9a-f]{6}/i', $hex ) ) {
		return null;
	}
	return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
}

/**
 * WCAG contrast ratio of two RGB colours.
 *
 * @param int[] $a Colour.
 * @param int[] $b Colour.
 * @return float
 */
function gend_society_studio_contrast( $a, $b ) {
	$lum = function ( $c ) {
		$l = array_map(
			function ( $v ) {
				$v /= 255;
				return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
			},
			$c
		);
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	};
	$x = $lum( $a );
	$y = $lum( $b );
	return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
}

add_action( 'admin_head', 'gend_society_studio_embed_css', 99 );
/**
 * Chromeless mode for screens shown inside the studio: hides the admin chrome
 * and, in the block editor, the button that leaves the canvas for core's own
 * navigation (the studio sidebar replaces it).
 */
function gend_society_studio_embed_css() {
	if ( ! gend_society_studio_is_embed() ) {
		return;
	}
	global $pagenow;
	if ( in_array( $pagenow, array( 'site-editor.php', 'post.php', 'post-new.php' ), true ) ) {
		$css = 'assets/design-studio-editor.css';
		echo '<link rel="stylesheet" id="gs-studio-editor-css" href="' . esc_url( GEND_SOCIETY_URL . $css . '?ver=' . GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $css ) ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- printed last so it overrides the editor's own late styles.
	}
	echo '<style id="gs-studio-embed-css">'
		. 'body.gs-studio-embed{--gs-header-h:0px!important}body.gs-studio-embed::before,body.gs-studio-embed::after{display:none!important}:root{background:#0b0e14!important}'
		. '#main-3d-header,.header-anchor-wrap,#wpadminbar,#adminmenumain,#wpfooter,aipa-widget,.gs-frontend-bar,.update-nag,.em-chat-widget,.em-chat-panel,#gm-cc,.gm-cc-open{display:none!important}'
		. 'html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:0!important}#wpbody-content{padding-top:0!important}'
		. 'body.gs-studio-embed .edit-site-layout__view-mode-toggle-container,body.gs-studio-embed .edit-site-site-hub__view-mode-toggle-container,body.gs-studio-embed .editor-header__back-button,body.gs-studio-embed .edit-site-layout__hub,body.gs-studio-embed .edit-post-fullscreen-mode-close{visibility:hidden!important;pointer-events:none!important}'
		. '</style>';
}

add_action( 'admin_enqueue_scripts', 'gend_society_studio_visual_editor_script', 99 );
/**
 * Every block editor opens in the visual editor. The studio's "Edit as HTML"
 * switches to the code editor, and WordPress saves that choice for all
 * editors (no blocks, empty settings sidebar), so it never carries over.
 */
function gend_society_studio_visual_editor_script() {
	foreach ( array( 'wp-edit-site', 'wp-edit-post' ) as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			wp_add_inline_script( $handle, 'wp.domReady(function(){try{if(wp.data.select("core/preferences").get("core","editorMode")==="text"){wp.data.dispatch("core/preferences").set("core","editorMode","visual");}}catch(e){}});' );
		}
	}
}

add_action( 'admin_enqueue_scripts', 'gend_society_studio_embed_editor_script' );
/**
 * In the embedded block editor, skip core's "Edit your site" welcome popup:
 * the studio is the guided entry point. Same effect as clicking "Get started".
 */
function gend_society_studio_embed_editor_script() {
	if ( ! gend_society_studio_is_embed() ) {
		return;
	}
	$editors = array(
		'wp-edit-site' => 'core/edit-site',
		'wp-edit-post' => 'core/edit-post',
	);
	foreach ( $editors as $handle => $scope ) {
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			continue;
		}
		wp_add_inline_script(
			$handle,
			// Also turn off Spotlight mode (dims every block but the selected one, so a one-block
			// header looked faded) and distraction-free mode: the studio gives the editor its own frame.
			'wp.domReady(function(){try{var p=wp.data.dispatch("core/preferences"),g=wp.data.select("core/preferences");["welcomeGuide","welcomeGuideTemplate","welcomeGuidePage"].forEach(function(k){if(g.get("' . $scope . '",k)!==false){p.set("' . $scope . '",k,false);}});["focusMode","distractionFree"].forEach(function(k){if(g.get("core",k)){p.set("core",k,false);}});}catch(e){}});'
		);
	}
}

/**
 * Keeps the embed flag on links and redirects inside an embedded admin screen.
 */
add_filter(
	'wp_redirect',
	function ( $location ) {
		if ( gend_society_studio_is_embed() && is_string( $location ) && 0 === strpos( $location, admin_url() ) ) {
			$location = add_query_arg( 'gs_studio_embed', '1', $location );
		}
		return $location;
	}
);

add_action( 'rest_api_init', 'gend_society_studio_register_rest' );
/**
 * REST: compiles an unsaved global styles draft into CSS for the live preview.
 */
function gend_society_studio_register_rest() {
	register_rest_route(
		'gend-society/v1',
		'/studio/shortcode',
		array(
			'methods'             => 'GET',
			'callback'            => 'gend_society_studio_rest_shortcode',
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/studio/preview-css',
		array(
			'methods'             => 'POST',
			'callback'            => 'gend_society_studio_rest_preview_css',
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
		)
	);
}

/**
 * A shortcode's details for the studio: description, what it shows, who sees
 * it, attributes, source, the pages that use it and a live preview URL.
 *
 * @param WP_REST_Request $request Request with ?tag=.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_studio_rest_shortcode( WP_REST_Request $request ) {
	global $wpdb;
	$tag = sanitize_key( (string) $request->get_param( 'tag' ) );
	if ( '' === $tag || ! shortcode_exists( $tag ) ) {
		return new WP_Error( 'gs_studio_sc', __( 'That shortcode is not available on this site.', 'gend-society' ), array( 'status' => 404 ) );
	}
	$info = gend_society_studio_shortcodes();
	$info = isset( $info[ $tag ] ) ? $info[ $tag ] : array();
	$code = gend_society_studio_shortcode_code_info( $tag );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only lookup of where a shortcode is used.
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item') AND post_content LIKE %s ORDER BY post_modified DESC LIMIT 25", '%' . $wpdb->esc_like( '[' . $tag ) . '%' ) );
	$pages = array();
	foreach ( $ids as $id ) {
		$pages[] = array(
			'id'      => (int) $id,
			'title'   => get_the_title( $id ),
			'type'    => get_post_type( $id ),
			'url'     => get_permalink( $id ),
			'editUrl' => admin_url( 'post.php?post=' . (int) $id . '&action=edit' ),
		);
	}
	return rest_ensure_response(
		array(
			'tag'         => $tag,
			'label'       => isset( $info['label'] ) ? $info['label'] : $tag,
			'description' => isset( $info['description'] ) ? $info['description'] : $code['doc'],
			'shows'       => isset( $info['shows'] ) ? $info['shows'] : array(),
			'audience'    => isset( $info['audience'] ) ? $info['audience'] : '',
			'attributes'  => $code['attributes'],
			'source'      => $code['source'],
			'template'    => isset( $info['template'] ) ? $info['template'] : '',
			'pages'       => $pages,
			'previewUrl'  => add_query_arg( 'gs_studio_shortcode', $tag, home_url( '/' ) ),
		)
	);
}

add_action( 'template_redirect', 'gend_society_studio_shortcode_preview', 0 );
/**
 * Live preview of one shortcode (?gs_studio_shortcode=tag), rendered for you
 * with the site's styles. Site editors only.
 */
function gend_society_studio_shortcode_preview() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for site editors.
	$tag = isset( $_GET['gs_studio_shortcode'] ) ? sanitize_key( wp_unslash( $_GET['gs_studio_shortcode'] ) ) : '';
	if ( '' === $tag || ! current_user_can( 'edit_theme_options' ) || ! shortcode_exists( $tag ) ) {
		return;
	}
	add_filter( 'show_admin_bar', '__return_false' );
	$html = do_shortcode( '[' . $tag . ']' ); // Rendered before wp_head so the styles it enqueues print in <head>.
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
	<?php wp_head(); ?>
<style>html,body{margin:0;background:#0b0e14;color:#e8edf5}.gs-sc-preview{padding:24px}.gs-front-sidebar,.gs-sidebar-flyout,.gs-float-menu,.gs-float-dock,aipa-widget,.em-chat-widget,.em-chat-panel,#gm-cc,.gm-cc-open{display:none!important}</style>
</head>
<body <?php body_class( 'gs-studio-preview' ); ?>>
<div class="gs-sc-preview"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
	exit;
}

/**
 * Builds the global stylesheet the site would have with the draft user config.
 *
 * @param WP_REST_Request $request Request with JSON { settings, styles }.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_studio_rest_preview_css( WP_REST_Request $request ) {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! class_exists( 'WP_Theme_JSON' ) ) {
		return new WP_Error( 'gs_studio_unsupported', __( 'Live preview needs a newer WordPress.', 'gend-society' ), array( 'status' => 501 ) );
	}
	$body     = $request->get_json_params();
	$settings = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : array();
	$styles   = isset( $body['styles'] ) && is_array( $body['styles'] ) ? $body['styles'] : array();

	$tree = WP_Theme_JSON_Resolver::get_core_data();
	$tree->merge( WP_Theme_JSON_Resolver::get_block_data() );
	$tree->merge( WP_Theme_JSON_Resolver::get_theme_data() );
	$tree->merge(
		new WP_Theme_JSON(
			array(
				'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => $settings,
				'styles'                      => $styles,
			),
			'custom'
		)
	);
	$origins = array( 'default', 'theme', 'custom' );
	$css     = $tree->get_stylesheet( array( 'variables' ), $origins )
		. $tree->get_stylesheet( array( 'presets' ), $origins )
		. $tree->get_stylesheet( array( 'styles' ), $origins );

	// The CSS goes into a <style> element in a same-origin preview frame; never let it close that element.
	$css = str_ireplace( '</style', '', $css );

	return rest_ensure_response( array( 'css' => $css ) );
}

add_action( 'template_redirect', 'gend_society_studio_specimen_page', 0 );
/**
 * The brand style guide (?gs_studio_specimen=1), the studio's live preview: a
 * client-ready guidelines document built from the site's own logo, palette,
 * fonts and global styles. The guide's frame (labels, grid, numbering) has a
 * fixed neutral look; every specimen inside it is plain markup styled by the
 * site's global styles, so the studio's live preview CSS restyles it as you
 * edit. assets/design-studio-guide.js reads the computed values (hex codes,
 * font names, sizes) so the labels stay true to what is shown. Only for
 * people who can edit the site's design; everyone else gets the normal page.
 */
function gend_society_studio_specimen_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
	if ( ! isset( $_GET['gs_studio_specimen'] ) || ! current_user_can( 'edit_theme_options' ) || ! wp_is_block_theme() ) {
		return;
	}
	add_filter( 'show_admin_bar', '__return_false' );
	$css = 'assets/design-studio-guide.css';
	$js  = 'assets/design-studio-guide.js';
	wp_enqueue_style( 'gs-studio-guide', GEND_SOCIETY_URL . $css, array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $css ) );
	wp_enqueue_style( 'gs-brand-kit-demo', GEND_SOCIETY_URL . 'assets/brand-kit-demo.css', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/brand-kit-demo.css' ) );
	wp_enqueue_script( 'gs-studio-guide', GEND_SOCIETY_URL . $js, array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );

	$name    = get_bloginfo( 'name' );
	$tagline = get_bloginfo( 'description' );
	$logo_id = (int) get_option( 'site_logo' );
	$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
	$icon    = get_site_icon_url( 256 );
	$palette = wp_get_global_settings( array( 'color', 'palette' ) );
	$colors  = array();
	foreach ( array( 'theme', 'custom' ) as $origin ) {
		foreach ( isset( $palette[ $origin ] ) ? (array) $palette[ $origin ] : array() as $c ) {
			if ( ! empty( $c['slug'] ) ) {
				$colors[ $c['slug'] ] = $c;
			}
		}
	}
	$colors = array_values( $colors );
	$accent = '';
	foreach ( $colors as $c ) {
		if ( 0 === strpos( $c['slug'], 'accent' ) ) {
			$accent = $c['slug'];
			break;
		}
	}
	$date = wp_date( 'F Y' );

	// Specimens: real block markup, so block and element styles apply exactly as on the site.
	$block = function ( $markup ) {
		return do_blocks( $markup );
	};
	$buttons = $block(
		'<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">' . esc_html__( 'Primary action', 'gend-society' ) . '</a></div><!-- /wp:button -->'
		. '<!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#">' . esc_html__( 'Secondary', 'gend-society' ) . '</a></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->'
	);
	$quote   = $block( '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>' . esc_html__( 'Design is the silent ambassador of your brand.', 'gend-society' ) . '</p><!-- /wp:paragraph --><cite>' . esc_html__( 'Paul Rand', 'gend-society' ) . '</cite></blockquote><!-- /wp:quote -->' );

	$scale = array(
		'h1' => __( 'Display', 'gend-society' ),
		'h2' => __( 'Headline', 'gend-society' ),
		'h3' => __( 'Title', 'gend-society' ),
		'h4' => __( 'Subtitle', 'gend-society' ),
		'h5' => __( 'Overline', 'gend-society' ),
		'h6' => __( 'Caption heading', 'gend-society' ),
	);
	$wordmark = function ( $class ) use ( $logo, $name ) {
		if ( $logo ) {
			return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">';
		}
		return '<div class="gsg-wordmark ' . esc_attr( $class ) . '"><h2 class="wp-block-heading">' . esc_html( $name ) . '</h2></div>';
	};
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( '%s: Brand Guidelines', 'gend-society' ), $name ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'gs-studio-specimen' ); ?>>
<div class="gsg">

	<section class="gsg-cover" id="gsg-cover">
		<div class="gsg-row gsg-meta">
			<span><?php echo esc_html( $name ); ?></span>
			<span><?php esc_html_e( 'Brand Guidelines', 'gend-society' ); ?></span>
			<span><?php echo esc_html( sprintf( /* translators: %s: month and year */ __( 'Edition 01 · %s', 'gend-society' ), $date ) ); ?></span>
		</div>
		<div class="gsg-cover-main">
			<div class="gsg-cover-mark"><?php echo $wordmark( 'gsg-cover-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></div>
			<div class="gsg-cover-title"><?php esc_html_e( 'Identity', 'gend-society' ); ?><br><em><?php esc_html_e( 'System', 'gend-society' ); ?></em></div>
			<?php if ( $tagline ) : ?>
				<div class="gsg-cover-tagline"><?php echo esc_html( $tagline ); ?></div>
			<?php endif; ?>
		</div>
		<div class="gsg-row gsg-meta">
			<span><?php esc_html_e( 'Prepared for the brand team', 'gend-society' ); ?></span>
			<span class="gsg-cover-strip">
				<?php foreach ( array_slice( $colors, 0, 6 ) as $c ) : ?>
					<i style="background:var(--wp--preset--color--<?php echo esc_attr( $c['slug'] ); ?>)"></i>
				<?php endforeach; ?>
			</span>
			<span><?php esc_html_e( 'Confidential', 'gend-society' ); ?></span>
		</div>
	</section>

	<nav class="gsg-index">
		<?php
		// Written chapters (inc/brand-kit.php) sit between the components and the closing spread.
		$kit_chapters = function_exists( 'gend_society_bk_guide_chapters' ) ? gend_society_bk_guide_chapters() : array();
		$chapters     = array(
			'logo'       => __( 'Logo', 'gend-society' ),
			'colour'     => __( 'Colour', 'gend-society' ),
			'type'       => __( 'Typography', 'gend-society' ),
			'components' => __( 'Components', 'gend-society' ),
		);
		foreach ( $kit_chapters as $kid => $kc ) {
			$chapters[ $kid ] = $kc[0];
		}
		$chapters['in-use'] = __( 'In use', 'gend-society' );
		$n = 0;
		foreach ( $chapters as $id => $label ) :
			++$n;
			?>
			<a href="#gsg-<?php echo esc_attr( $id ); ?>"><b><?php echo esc_html( sprintf( '%02d', $n ) ); ?></b><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<section class="gsg-chapter" id="gsg-logo">
		<header class="gsg-chapter-head">
			<span class="gsg-num">01</span>
			<div class="gsg-chapter-title"><?php esc_html_e( 'Logo', 'gend-society' ); ?></div>
			<div class="gsg-chapter-lede"><?php esc_html_e( 'The mark is the most recognisable piece of the identity. Give it room, keep it legible and never redraw it.', 'gend-society' ); ?></div>
		</header>
		<div class="gsg-logo-grid">
			<figure class="gsg-logo-tile is-light"><?php echo $wordmark( 'gsg-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><figcaption><?php esc_html_e( 'Primary · on light', 'gend-society' ); ?></figcaption></figure>
			<figure class="gsg-logo-tile is-dark"><?php echo $wordmark( 'gsg-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><figcaption><?php esc_html_e( 'Reversed · on dark', 'gend-society' ); ?></figcaption></figure>
			<?php if ( $accent ) : ?>
				<figure class="gsg-logo-tile" style="background:var(--wp--preset--color--<?php echo esc_attr( $accent ); ?>)"><?php echo $wordmark( 'gsg-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><figcaption><?php esc_html_e( 'On brand colour', 'gend-society' ); ?></figcaption></figure>
			<?php endif; ?>
			<figure class="gsg-logo-tile is-clearspace"><div class="gsg-clear"><?php echo $wordmark( 'gsg-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></div><figcaption><?php esc_html_e( 'Clear space · keep the dashed area free', 'gend-society' ); ?></figcaption></figure>
		</div>
		<?php if ( $icon ) : ?>
			<div class="gsg-icon-row" id="gsg-icons">
				<img class="gsg-icon is-round" src="<?php echo esc_url( $icon ); ?>" alt="">
				<img class="gsg-icon is-squircle" src="<?php echo esc_url( $icon ); ?>" alt="">
				<img class="gsg-icon is-square" src="<?php echo esc_url( $icon ); ?>" alt="">
				<div class="gsg-note"><b><?php esc_html_e( 'App icon & favicon', 'gend-society' ); ?></b><?php esc_html_e( 'Used in browser tabs, bookmarks and on phone home screens.', 'gend-society' ); ?></div>
			</div>
		<?php endif; ?>
	</section>

	<section class="gsg-chapter" id="gsg-colour">
		<header class="gsg-chapter-head">
			<span class="gsg-num">02</span>
			<div class="gsg-chapter-title"><?php esc_html_e( 'Colour', 'gend-society' ); ?></div>
			<div class="gsg-chapter-lede"><?php esc_html_e( 'A considered palette carries the brand before a single word is read. Use the primaries generously and the accents with intent.', 'gend-society' ); ?></div>
		</header>
		<div class="gsg-swatches">
			<?php foreach ( $colors as $i => $c ) : ?>
				<div class="gsg-swatch<?php echo $i < 2 ? ' is-major' : ''; ?>">
					<div class="gsg-chip" style="background:var(--wp--preset--color--<?php echo esc_attr( $c['slug'] ); ?>)"></div>
					<div class="gsg-swatch-meta">
						<div class="gsg-swatch-name"><?php echo esc_html( isset( $c['name'] ) ? $c['name'] : $c['slug'] ); ?></div>
						<div class="gsg-code"><span><?php esc_html_e( 'HEX', 'gend-society' ); ?></span><b data-gsg="hex">—</b></div>
						<div class="gsg-code"><span><?php esc_html_e( 'RGB', 'gend-society' ); ?></span><b data-gsg="rgb">—</b></div>
						<div class="gsg-code"><span><?php esc_html_e( 'Token', 'gend-society' ); ?></span><b><?php echo esc_html( $c['slug'] ); ?></b></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="gsg-chapter" id="gsg-type">
		<header class="gsg-chapter-head">
			<span class="gsg-num">03</span>
			<div class="gsg-chapter-title"><?php esc_html_e( 'Typography', 'gend-society' ); ?></div>
			<div class="gsg-chapter-lede"><?php esc_html_e( 'Two voices: one to announce, one to explain. The scale below is the system every page is set in.', 'gend-society' ); ?></div>
		</header>
		<div class="gsg-faces" id="gsg-faces">
			<div class="gsg-face">
				<div class="gsg-face-role"><?php esc_html_e( 'Headings', 'gend-society' ); ?></div>
				<div class="gsg-canvas gsg-face-glyph"><h2 class="wp-block-heading" data-gsg-font="heading">Aa</h2></div>
				<div class="gsg-face-name" data-gsg-name="heading">—</div>
				<div class="gsg-canvas gsg-face-set"><h2 class="wp-block-heading">ABCDEFGHIJKLM NOPQRSTUVWXYZ abcdefghijklm nopqrstuvwxyz 0123456789 &amp;!?</h2></div>
			</div>
			<div class="gsg-face">
				<div class="gsg-face-role"><?php esc_html_e( 'Body', 'gend-society' ); ?></div>
				<div class="gsg-canvas gsg-face-glyph"><p data-gsg-font="body">Aa</p></div>
				<div class="gsg-face-name" data-gsg-name="body">—</div>
				<div class="gsg-canvas gsg-face-set"><p>ABCDEFGHIJKLM NOPQRSTUVWXYZ abcdefghijklm nopqrstuvwxyz 0123456789 &amp;!?</p></div>
			</div>
		</div>
		<div class="gsg-scale">
			<?php foreach ( $scale as $tag => $role ) : ?>
				<div class="gsg-scale-row" id="gsg-type-<?php echo esc_attr( $tag ); ?>">
					<div class="gsg-scale-meta"><b><?php echo esc_html( strtoupper( $tag ) . ' · ' . $role ); ?></b><span data-gsg-spec="<?php echo esc_attr( $tag ); ?>">—</span></div>
					<div class="gsg-canvas"><<?php echo esc_html( $tag ); ?> class="wp-block-heading" data-gsg-el="<?php echo esc_attr( $tag ); ?>"><?php esc_html_e( 'Crafted with intent', 'gend-society' ); ?></<?php echo esc_html( $tag ); ?>></div>
				</div>
			<?php endforeach; ?>
			<div class="gsg-scale-row" id="gsg-type-p">
				<div class="gsg-scale-meta"><b><?php esc_html_e( 'Body · Paragraph', 'gend-society' ); ?></b><span data-gsg-spec="p">—</span></div>
				<div class="gsg-canvas"><p data-gsg-el="p"><?php esc_html_e( 'Good body type disappears: it is calm, comfortable and easy to read at length, so the ideas come through and the type does not get in the way. Inline links', 'gend-society' ); ?> <a href="#" data-gsg-el="a"><?php esc_html_e( 'look like this', 'gend-society' ); ?></a>.</p></div>
			</div>
		</div>
	</section>

	<section class="gsg-chapter" id="gsg-components">
		<header class="gsg-chapter-head">
			<span class="gsg-num">04</span>
			<div class="gsg-chapter-title"><?php esc_html_e( 'Components', 'gend-society' ); ?></div>
			<div class="gsg-chapter-lede"><?php esc_html_e( 'The building blocks people touch: actions, links and editorial accents.', 'gend-society' ); ?></div>
		</header>
		<div class="gsg-comp-grid">
			<div class="gsg-comp" id="gsg-comp-buttons"><div class="gsg-label"><?php esc_html_e( 'Buttons', 'gend-society' ); ?></div><div class="gsg-canvas gsg-pad"><?php echo $buttons; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?></div></div>
			<div class="gsg-comp" id="gsg-comp-links"><div class="gsg-label"><?php esc_html_e( 'Links', 'gend-society' ); ?></div><div class="gsg-canvas gsg-pad"><p><?php esc_html_e( 'Read the', 'gend-society' ); ?> <a href="#"><?php esc_html_e( 'full story', 'gend-society' ); ?></a> <?php esc_html_e( 'or', 'gend-society' ); ?> <a href="#"><?php esc_html_e( 'get in touch', 'gend-society' ); ?></a>.</p></div></div>
			<div class="gsg-comp is-wide"><div class="gsg-label"><?php esc_html_e( 'Quote', 'gend-society' ); ?></div><div class="gsg-canvas gsg-pad"><?php echo $quote; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?></div></div>
		</div>
	</section>

	<?php
	$kn = 4;
	foreach ( $kit_chapters as $kid => $kc ) :
		++$kn;
		?>
	<section class="gsg-chapter" id="gsg-<?php echo esc_attr( $kid ); ?>">
		<header class="gsg-chapter-head">
			<span class="gsg-num"><?php echo esc_html( sprintf( '%02d', $kn ) ); ?></span>
			<div class="gsg-chapter-title"><?php echo esc_html( $kc[0] ); ?></div>
			<div class="gsg-chapter-lede"><?php echo esc_html( $kc[1] ); ?></div>
		</header>
		<?php if ( '' !== $kc[2] ) : ?>
			<div class="gsg-bk"><?php echo $kc[2]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in gend_society_bk_guide_chapters(). ?></div>
		<?php else : ?>
			<div class="gsg-bk-empty"><?php esc_html_e( 'Not written yet. Fill this chapter in from the Design Studio’s Branding screens.', 'gend-society' ); ?></div>
		<?php endif; ?>
	</section>
	<?php endforeach; ?>

	<section class="gsg-chapter" id="gsg-in-use">
		<header class="gsg-chapter-head">
			<span class="gsg-num"><?php echo esc_html( sprintf( '%02d', $kn + 1 ) ); ?></span>
			<div class="gsg-chapter-title"><?php esc_html_e( 'In use', 'gend-society' ); ?></div>
			<div class="gsg-chapter-lede"><?php esc_html_e( 'Everything together, as visitors will meet it.', 'gend-society' ); ?></div>
		</header>
		<div class="gsg-mock">
			<div class="gsg-mock-bar"><i></i><i></i><i></i><span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></span></div>
			<div class="gsg-canvas gsg-mock-body">
				<div class="gsg-mock-nav"><?php echo $wordmark( 'gsg-mock-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><p><a href="#"><?php esc_html_e( 'Work', 'gend-society' ); ?></a> &nbsp; <a href="#"><?php esc_html_e( 'About', 'gend-society' ); ?></a> &nbsp; <a href="#"><?php esc_html_e( 'Contact', 'gend-society' ); ?></a></p></div>
				<h1 class="wp-block-heading"><?php echo esc_html( $tagline ? $tagline : __( 'Build something people love', 'gend-society' ) ); ?></h1>
				<p><?php esc_html_e( 'A short introduction that says who this is for, what it does and why it matters, in a sentence or two.', 'gend-society' ); ?></p>
				<?php echo $buttons; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
			</div>
		</div>
	</section>

	<footer class="gsg-row gsg-meta gsg-end">
		<span><?php echo esc_html( $name ); ?></span>
		<span><?php esc_html_e( 'Brand Guidelines', 'gend-society' ); ?></span>
		<span><?php echo esc_html( $date ); ?></span>
	</footer>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
	exit;
}
