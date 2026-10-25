<?php
/**
 * Design Studio: the GenD dashboard that replaces the Site Editor's landing screen.
 *
 * The bare site-editor.php URL (no route) opens this page instead. The sidebar
 * groups every front-end design surface (Branding, App, Content, and one
 * section per active GenD plugin). Branding panels edit the core settings and
 * the user's global styles directly (same data the Site Editor writes); every
 * template item opens the real block editor canvas inside the studio, so the
 * Site Editor stays the single editing engine. Deep links that carry a route
 * (site-editor.php?p=...) still open core directly, and ?gs_core=1 opens core's
 * own landing screen.
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
	return isset( $_GET['gs_studio_embed'] ) && '1' === $_GET['gs_studio_embed'];
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

add_action( 'load-site-editor.php', 'gend_society_studio_redirect_landing' );
/**
 * Sends the bare Site Editor URL to the studio. Any route or query keeps core.
 */
function gend_society_studio_redirect_landing() {
	if ( ! wp_is_block_theme() || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, nothing is changed.
	if ( ! empty( $_GET ) ) {
		return; // A deep link (p=, postType=, canvas=, gs_core=1 ...) opens core directly.
	}
	wp_safe_redirect( admin_url( 'admin.php?page=' . GEND_SOCIETY_STUDIO_PAGE ) );
	exit;
}

/**
 * The studio sections: what the sidebar shows, gated on each plugin being active.
 *
 * Item kinds:
 *   panel     a studio panel ('logo', 'favicon', 'site-name', 'palettes', 'fonts', 'type:h1' ...)
 *   template  a block template (slug), opened in the block editor canvas
 *   part      a template part (slug)
 *   templates a list of templates filtered by 'match' (page templates ...)
 *   menus     the site's navigation menus
 *   admin     an existing admin screen shown inside the studio ('url')
 *
 * A template item may name 'fallback' (create from that template when missing)
 * and 'preview' (a front-end URL to open).
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
	$yz          = function ( $page, $tab = '' ) {
		return admin_url( 'admin.php?page=' . $page . ( $tab ? '&tab=' . $tab : '' ) );
	};

	$type_items = array();
	foreach ( array(
		'h1' => __( 'H1', 'gend-society' ),
		'h2' => __( 'H2', 'gend-society' ),
		'h3' => __( 'H3', 'gend-society' ),
		'h4' => __( 'H4', 'gend-society' ),
		'h5' => __( 'H5', 'gend-society' ),
		'h6' => __( 'H6', 'gend-society' ),
		'p'  => __( 'Paragraph', 'gend-society' ),
		'a'  => __( 'Link', 'gend-society' ),
	) as $el => $label ) {
		$type_items[] = array(
			'id'    => 'type-' . $el,
			'label' => $label,
			'kind'  => 'panel',
			'panel' => 'type:' . $el,
		);
	}

	$sections = array(
		array(
			'id'     => 'branding',
			'label'  => __( 'Branding', 'gend-society' ),
			'icon'   => 'art',
			'blurb'  => __( 'Logo, colours and type that every page shares.', 'gend-society' ),
			'active' => true,
			'groups' => array(
				array(
					'items' => array(
						array( 'id' => 'logo', 'label' => __( 'Logo', 'gend-society' ), 'kind' => 'panel', 'panel' => 'logo' ),
						array( 'id' => 'favicon', 'label' => __( 'Favicon', 'gend-society' ), 'kind' => 'panel', 'panel' => 'favicon' ),
						array( 'id' => 'site-name', 'label' => __( 'Site Name', 'gend-society' ), 'kind' => 'panel', 'panel' => 'site-name' ),
						array( 'id' => 'palettes', 'label' => __( 'Colour Schemes', 'gend-society' ), 'kind' => 'panel', 'panel' => 'palettes' ),
						array( 'id' => 'fonts', 'label' => __( 'Font Types', 'gend-society' ), 'kind' => 'panel', 'panel' => 'fonts' ),
					),
				),
				array(
					'label' => __( 'Typography', 'gend-society' ),
					'items' => $type_items,
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
						array( 'id' => '404', 'label' => __( 'Page 404', 'gend-society' ), 'kind' => 'template', 'slug' => '404', 'preview' => home_url( '/gs-studio-404-preview/' ) ),
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
				array(
					'label'  => __( 'Sales', 'gend-society' ),
					'active' => $has_sales,
					'items'  => array(
						array( 'id' => 'affiliate-dashboard', 'label' => __( 'Affiliate Dashboard', 'gend-society' ), 'kind' => 'template', 'slug' => 'page-affiliate-dashboard' ),
					),
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
				array(
					'label' => __( 'Profile', 'gend-society' ),
					'items' => array(
						array( 'id' => 'members-directory', 'label' => __( "Member's Directory", 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'members-directory' ) ),
						array( 'id' => 'profile-template', 'label' => __( 'Profile Template', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-profile-settings' ) ),
						array( 'id' => 'profile-header', 'label' => __( 'Profile Header', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-profile-settings', 'header' ) ),
						array( 'id' => 'profile-menu', 'label' => __( 'Profile Menu', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-profile-settings', 'navbar' ) ),
						array( 'id' => 'profile-menu-pages', 'label' => __( 'Profile Menu Pages', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-profile-settings', 'tabs' ) ),
						array( 'id' => 'profile-layout', 'label' => __( 'Profile Layout and Sidebars', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-profile-settings', 'structure' ) ),
					),
				),
				array(
					'label' => __( 'Groups', 'gend-society' ),
					'items' => array(
						array( 'id' => 'groups-directory', 'label' => __( 'Group Directory', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'groups-directory' ) ),
						array( 'id' => 'group-templates', 'label' => __( 'Group Templates', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'groups' ) ),
						array( 'id' => 'group-header', 'label' => __( 'Group Header', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'groups' ) . '#header' ),
						array( 'id' => 'group-menu', 'label' => __( 'Group Menu', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'groups' ) . '#navbar' ),
						array( 'id' => 'group-menu-pages', 'label' => __( 'Group Menu Pages', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'groups' ) . '#tabs' ),
						array( 'id' => 'group-layout', 'label' => __( 'Group Layout and Sidebars', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'groups' ) . '#structure' ),
					),
				),
				array(
					'label' => __( 'Activity Feed', 'gend-society' ),
					'items' => array(
						array( 'id' => 'activity-styling', 'label' => __( 'Styling', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-panel', 'wall' ) ),
						array( 'id' => 'activity-reactions', 'label' => __( 'Activity Reactions', 'gend-society' ), 'kind' => 'admin', 'url' => $yz( 'youzify-extensions-settings', 'activity-reactions' ) ),
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

	// Drop inactive groups so a section only lists what its plugins provide.
	foreach ( $sections as &$section ) {
		$section['groups'] = array_values(
			array_filter(
				$section['groups'],
				function ( $group ) {
					return ! isset( $group['active'] ) || $group['active'];
				}
			)
		);
	}
	unset( $section );

	return $sections;
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
	wp_enqueue_script( 'gs-design-studio', GEND_SOCIETY_URL . $js, array( 'wp-api-fetch', 'wp-i18n', 'wp-url' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
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
	echo '<style id="gs-studio-embed-css">'
		. 'body.gs-studio-embed{--gs-header-h:0px!important}'
		. '#main-3d-header,.header-anchor-wrap,#wpadminbar,#adminmenumain,#wpfooter,aipa-widget,.gs-frontend-bar,.update-nag{display:none!important}'
		. 'html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:0!important}#wpbody-content{padding-top:0!important}'
		. 'body.gs-studio-embed .edit-site-layout__view-mode-toggle-container,body.gs-studio-embed .edit-site-site-hub__view-mode-toggle-container,body.gs-studio-embed .editor-header__back-button,body.gs-studio-embed .edit-site-layout__hub{visibility:hidden!important;pointer-events:none!important}'
		. '</style>';
}

add_action( 'admin_enqueue_scripts', 'gend_society_studio_embed_editor_script' );
/**
 * In the embedded block editor, skip core's "Edit your site" welcome popup:
 * the studio is the guided entry point. Same effect as clicking "Get started".
 */
function gend_society_studio_embed_editor_script() {
	global $pagenow;
	if ( 'site-editor.php' !== $pagenow || ! gend_society_studio_is_embed() || ! wp_script_is( 'wp-edit-site', 'enqueued' ) ) {
		return;
	}
	wp_add_inline_script(
		'wp-edit-site',
		'wp.domReady(function(){try{var p=wp.data.dispatch("core/preferences");["welcomeGuide","welcomeGuideTemplate","welcomeGuidePage"].forEach(function(k){if(wp.data.select("core/preferences").get("core/edit-site",k)!==false){p.set("core/edit-site",k,false);}});}catch(e){}});'
	);
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
 * The style-guide preview page (?gs_studio_specimen=1): the site's real header,
 * footer and global styles around a sample of every type element. Only for
 * people who can edit the site's design; everyone else gets the normal page.
 */
function gend_society_studio_specimen_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
	if ( ! isset( $_GET['gs_studio_specimen'] ) || ! current_user_can( 'edit_theme_options' ) || ! wp_is_block_theme() ) {
		return;
	}
	add_filter( 'show_admin_bar', '__return_false' );
	$sample = sprintf(
		'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} --><main class="wp-block-group">'
		. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">%1$s</h1><!-- /wp:heading -->'
		. '<!-- wp:heading --><h2 class="wp-block-heading">%2$s</h2><!-- /wp:heading -->'
		. '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">%3$s</h3><!-- /wp:heading -->'
		. '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">%4$s</h4><!-- /wp:heading -->'
		. '<!-- wp:heading {"level":5} --><h5 class="wp-block-heading">%5$s</h5><!-- /wp:heading -->'
		. '<!-- wp:heading {"level":6} --><h6 class="wp-block-heading">%6$s</h6><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>%7$s <a href="#">%8$s</a> %9$s</p><!-- /wp:paragraph -->'
		. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">%10$s</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">%11$s</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
		. '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>%12$s</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->'
		. '</main><!-- /wp:group -->',
		esc_html__( 'Heading 1: build something people love', 'gend-society' ),
		esc_html__( 'Heading 2: a section title', 'gend-society' ),
		esc_html__( 'Heading 3: a card title', 'gend-society' ),
		esc_html__( 'Heading 4: a smaller heading', 'gend-society' ),
		esc_html__( 'Heading 5: a label', 'gend-society' ),
		esc_html__( 'Heading 6: the fine print', 'gend-society' ),
		esc_html__( 'Paragraph text is what your visitors read most. Good body type is easy on the eyes, with comfortable line spacing and', 'gend-society' ),
		esc_html__( 'a link that stands out', 'gend-society' ),
		esc_html__( 'from the words around it.', 'gend-society' ),
		esc_html__( 'Primary button', 'gend-society' ),
		esc_html__( 'Outline button', 'gend-society' ),
		esc_html__( 'A quote, to show how your accent styles carry through.', 'gend-society' )
	);
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
	<?php
	$content = do_blocks( '<!-- wp:template-part {"slug":"header"} /-->' . $sample . '<!-- wp:template-part {"slug":"footer"} /-->' );
	wp_head();
	?>
</head>
<body <?php body_class( 'gs-studio-specimen' ); ?>>
<style>.gs-front-sidebar,.gs-sidebar-flyout,.gs-float-menu,.gs-float-dock,aipa-widget{display:none!important}</style>
	<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
	exit;
}
