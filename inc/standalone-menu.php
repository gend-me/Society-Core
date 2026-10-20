<?php
/**
 * Phase 109-35 (D36): the gend.me/wp-admin sidebar on a standalone install - menu pages only.
 *
 * Manifest: tier core, needs ['standalone', 'skin'] - loads only on a plain WordPress install with the GenD
 * dashboard on. Hub and container never load this file.
 *
 * User decision (2026-10-20): the menu links and pages / upgrade pages are bundled; the features themselves (the
 * gend.me plugins) are not. So this file:
 * - registers the same App / Store / Social menu pages and sub-menus as gend.me/wp-admin (inc/admin-menu.php
 *   builds them on the hub from the active gend.me plugins) for every gend.me feature that is NOT on this install;
 *   each of those pages shows the existing upgrade page (inc/pages/feature-upgrade.php) for its plan area, or, once
 *   the dashboard plan includes it, "Included with your plan" (wordpress.org build: ONE button to the full GenD
 *   Society build on gend.me; this copy never downloads or installs code);
 * - keeps every WordPress screen free and reachable: the core menus are hidden from the sidebar as on gend.me
 *   (inc/admin-menu.php) and each core screen gets the hub tab strip of its gend.me home:
 *     Posts, Categories, Tags, Comments -> App > Content Campaigns > Blogging
 *     Pages                             -> App > Content Campaigns > Info Pages
 *     Media                             -> App > Content Campaigns > Media
 *     Users                             -> Social > Social Profiles > Members > Management
 *     Appearance (themes.php and its sub-screens) -> App > Theme Editor / Themes
 *     Plugins, Updates                  -> Dashboard > Hosting > Codebase (links in Code Packages)
 *   Tools and Settings have no home in the dashboard design and stay in the sidebar, at the bottom;
 * - orders the sidebar like gend.me: Dashboard, App, Store, Social, then other plugins' menus with their own icons
 *   (as Cloaked Links / OAuth Server on gend.me), then Tools and Settings.
 * Direct core URLs keep working; nothing here redirects (D9).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_sa_menu_label' ) ) {
	/**
	 * Sidebar label markup, the same as inc/admin-menu.php uses on gend.me.
	 *
	 * @param string $icon  Dashicons class.
	 * @param string $label Label.
	 */
	function gend_society_sa_menu_label( string $icon, string $label ): string {
		return '<span class="gs-menu-icon dashicons ' . esc_attr( $icon ) . '"></span><span class="gs-menu-label">' . esc_html( $label ) . '</span>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_gated' ) ) {
	/**
	 * gend.me feature pages bundled as menu pages: page slug => parent, label, plan area, the gend.me plugin that
	 * provides the feature (the page is only registered here while that plugin is not active). Slugs and labels
	 * match gend.me/wp-admin.
	 *
	 * @return array<string,array{parent:string,label:string,area:string,plugin:string}>
	 */
	function gend_society_sa_menu_gated(): array {
		return array(
			'email-manager'           => array( 'parent' => 'gs-content', 'label' => __( 'Talk Flows', 'gend-society' ), 'area' => 'app', 'plugin' => 'email-manager/email-manager.php' ),
			'gdc-store-settings'      => array( 'parent' => 'gs-store', 'label' => __( 'Store Management', 'gend-society' ), 'area' => 'store', 'plugin' => 'online-store/online-store.php' ),
			'st_sales_team'           => array( 'parent' => 'gs-store', 'label' => __( 'Sales Team', 'gend-society' ), 'area' => 'store', 'plugin' => 'sales-team/advanced-affiliate-system.php' ),
			'psoo-projects'           => array( 'parent' => 'gs-store', 'label' => __( 'Project Services', 'gend-society' ), 'area' => 'store', 'plugin' => 'projects/project-service-orders.php' ),
			'gs-rewards'              => array( 'parent' => 'gs-social', 'label' => __( 'Point Bank', 'gend-society' ), 'area' => 'social', 'plugin' => 'reward-programs/reward-programs.php' ),
			'gend-contracts-payments' => array( 'parent' => 'gs-social', 'label' => __( 'Contracts & Payments', 'gend-society' ), 'area' => 'social', 'plugin' => 'contracts-and-payments/contracts-and-payments.php' ),
		);
	}
}

if ( ! function_exists( 'gend_society_sa_menu_plugin_active' ) ) {
	/**
	 * True when a gend.me feature plugin is active here (then its own menu is used, as on gend.me).
	 *
	 * @param string $plugin Plugin basename.
	 */
	function gend_society_sa_menu_plugin_active( string $plugin ): bool {
		return function_exists( 'gend_society_plugin_active' ) && gend_society_plugin_active( $plugin );
	}
}

if ( ! function_exists( 'gend_society_sa_menu_register' ) ) {
	/**
	 * Register the bundled menu pages. Priority 1300: after inc/admin-menu.php (5 and 1200).
	 */
	function gend_society_sa_menu_register(): void {
		$hooks = array();

		// App: Theme Editor (inc/admin-menu.php), Content Campaigns, Talk Flows, Themes.
		if ( ! gend_society_sa_menu_plugin_active( 'blog-manager/blog-manager.php' ) ) {
			$hooks[] = add_submenu_page( 'gs-content', __( 'Content Campaigns', 'gend-society' ), __( 'Content Campaigns', 'gend-society' ), 'edit_posts', 'content-campaigns', 'gend_society_sa_menu_render_content_campaigns' );
		}

		$gated = gend_society_sa_menu_gated();
		if ( ! empty( $gated['email-manager'] ) && ! gend_society_sa_menu_plugin_active( $gated['email-manager']['plugin'] ) ) {
			$hooks[] = add_submenu_page( 'gs-content', $gated['email-manager']['label'], $gated['email-manager']['label'], 'manage_options', 'email-manager', 'gend_society_sa_menu_render_gated' );
		}
		// Theme switching (themes.php) has no sidebar entry of its own once Appearance is hidden.
		add_submenu_page( 'gs-content', __( 'Themes', 'gend-society' ), __( 'Themes', 'gend-society' ), 'switch_themes', 'themes.php' );

		// Store and Social: the same top-level menus as gend.me when their plugins are not here.
		if ( empty( $GLOBALS['admin_page_hooks']['gs-store'] ) ) {
			add_menu_page( __( 'Store', 'gend-society' ), gend_society_sa_menu_label( 'dashicons-store', __( 'Store', 'gend-society' ) ), 'manage_options', 'gs-store', '__return_null', 'none', 6 );
		}
		if ( empty( $GLOBALS['admin_page_hooks']['gs-social'] ) ) {
			add_menu_page( __( 'Social', 'gend-society' ), gend_society_sa_menu_label( 'dashicons-share', __( 'Social', 'gend-society' ) ), 'manage_options', 'gs-social', '__return_null', 'none', 7 );
		}
		foreach ( array( 'gdc-store-settings', 'st_sales_team', 'psoo-projects' ) as $slug ) {
			if ( ! gend_society_sa_menu_plugin_active( $gated[ $slug ]['plugin'] ) ) {
				$hooks[] = add_submenu_page( 'gs-store', $gated[ $slug ]['label'], $gated[ $slug ]['label'], 'manage_options', $slug, 'gend_society_sa_menu_render_gated' );
			}
		}
		if ( ! gend_society_sa_menu_plugin_active( 'social-network/social-network.php' ) ) {
			$hooks[] = add_submenu_page( 'gs-social', __( 'Social Profiles', 'gend-society' ), __( 'Social Profiles', 'gend-society' ), 'list_users', 'gdc-social-network-settings', 'gend_society_sa_menu_render_social_profiles' );
		}
		foreach ( array( 'gs-rewards', 'gend-contracts-payments' ) as $slug ) {
			if ( ! gend_society_sa_menu_plugin_active( $gated[ $slug ]['plugin'] ) ) {
				$hooks[] = add_submenu_page( 'gs-social', $gated[ $slug ]['label'], $gated[ $slug ]['label'], 'manage_options', $slug, 'gend_society_sa_menu_render_gated' );
			}
		}
		remove_submenu_page( 'gs-store', 'gs-store' );
		remove_submenu_page( 'gs-social', 'gs-social' );

		// The GenD page (readiness, theme card) is not in the gend.me sidebar; its URL keeps working.
		remove_menu_page( 'gend-society' );

		foreach ( array_filter( $hooks ) as $hook ) {
			add_action( 'load-' . $hook, 'gend_society_sa_menu_load_page' );
		}
	}
	add_action( 'admin_menu', 'gend_society_sa_menu_register', 1300 );
}

if ( ! function_exists( 'gend_society_sa_menu_load_page' ) ) {
	/**
	 * Bundled page load: upgrade-page assets (gs-plan.css/.js: popup checkout + Refresh) for its area.
	 */
	function gend_society_sa_menu_load_page(): void {
		if ( function_exists( 'gend_society_plan_assets_enqueue' ) ) {
			$area = gend_society_sa_menu_current_area();
			add_action(
				'admin_enqueue_scripts',
				function () use ( $area ) {
					gend_society_plan_assets_enqueue( 'upgrade', $area );
				}
			);
		}
	}
}

if ( ! function_exists( 'gend_society_sa_menu_request' ) ) {
	/**
	 * A sanitized key from the query string ('' when absent).
	 *
	 * @param string $key Query arg.
	 */
	function gend_society_sa_menu_request( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation arguments.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_current_area' ) ) {
	/**
	 * Plan area of the bundled page being viewed.
	 */
	function gend_society_sa_menu_current_area(): string {
		$page  = gend_society_sa_menu_request( 'page' );
		$gated = gend_society_sa_menu_gated();
		if ( isset( $gated[ $page ] ) ) {
			return $gated[ $page ]['area'];
		}
		return 'gdc-social-network-settings' === $page ? 'social' : 'app';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_area_state' ) ) {
	/**
	 * The plan state of an area for this install: not_connected | revoked | unreachable | included | upgrade |
	 * after_migration | unknown. Read from the cached plan view-model (inc/plan-access.php).
	 *
	 * @param string $area Area slug.
	 */
	function gend_society_sa_menu_area_state( string $area ): string {
		$state = 'not_connected';
		if ( '' !== (string) get_option( 'gend_society_install_token', '' ) && function_exists( 'gend_society_plan_view_model' ) ) {
			$vm    = gend_society_plan_view_model();
			$state = (string) ( $vm['state'] ?? 'not_connected' );
			if ( 'ok' === $state ) {
				$state = 'unknown';
				foreach ( (array) ( $vm['areas'] ?? array() ) as $a ) {
					if ( is_array( $a ) && (string) ( $a['slug'] ?? '' ) === $area ) {
						$state = (string) ( $a['state'] ?? 'unknown' );
					}
				}
			}
		}
		/**
		 * Filters the plan state a bundled GenD menu page uses for its area.
		 *
		 * @param string $state State.
		 * @param string $area  Area slug.
		 */
		return (string) apply_filters( 'gend_society_standalone_menu_area_state', $state, $area );
	}
}

if ( ! function_exists( 'gend_society_sa_menu_full_build_url' ) ) {
	/**
	 * Where the wordpress.org build sends the owner for the full GenD Society build (the paired gend.me, else
	 * gend.me). A plain link: the wordpress.org build never downloads anything itself.
	 */
	function gend_society_sa_menu_full_build_url(): string {
		$base = function_exists( 'gend_society_plan_identity' ) ? (string) ( gend_society_plan_identity()['base'] ?? '' ) : '';
		if ( '' === $base || ! function_exists( 'gend_society_plan_is_hub_url' ) || ! gend_society_plan_is_hub_url( $base ) ) {
			$base = 'https://gend.me';
		}
		/**
		 * Filters the full GenD Society build download page.
		 *
		 * @param string $url Page URL.
		 */
		return (string) apply_filters( 'gend_society_full_build_url', untrailingslashit( $base ) . '/gend-society/' );
	}
}

if ( ! function_exists( 'gend_society_sa_menu_feature_html' ) ) {
	/**
	 * The body of a gend.me feature page: the existing upgrade fragment, or "Included with your plan" once the
	 * dashboard plan includes the area.
	 *
	 * @param string $label Feature name.
	 * @param string $area  Plan area.
	 */
	function gend_society_sa_menu_feature_html( string $label, string $area ): string {
		if ( 'included' !== gend_society_sa_menu_area_state( $area ) ) {
			return function_exists( 'gend_society_feature_upgrade_fragment' ) ? gend_society_feature_upgrade_fragment( $area ) : '';
		}
		$h  = '<div class="gend-society-card gs-plan-card gs-up-standalone is-included" data-gs-up-area="' . esc_attr( $area ) . '" data-gs-up-state="included">';
		$h .= '<h2>' . esc_html( $label ) . '</h2>';
		$h .= '<p class="gs-up-state" data-gs-up-copy="included">' . esc_html__( 'Included with your plan', 'gend-society' ) . '</p>';
		if ( function_exists( 'gend_society_build_variant' ) && 'wporg' === gend_society_build_variant() ) {
			/* translators: %s: feature name. */
			$h .= '<p>' . esc_html( sprintf( __( '%s comes with the full GenD Society build from gend.me. This copy of the plugin (from WordPress.org) does not download or install any code: get the full build from gend.me and install it yourself.', 'gend-society' ), $label ) ) . '</p>';
			$h .= '<p class="gs-up-actions"><a class="button button-primary" data-gs-full-build="1" href="' . esc_url( gend_society_sa_menu_full_build_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get the full GenD Society build on gend.me', 'gend-society' ) . '</a></p>';
		} else {
			/* translators: %s: feature name. */
			$h .= '<p>' . esc_html( sprintf( __( '%s is included with your plan. Its feature package is installed from gend.me by a later GenD Society update.', 'gend-society' ), $label ) ) . '</p>';
		}
		return $h . '</div>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_render_gated' ) ) {
	/**
	 * A gend.me feature page that is not on this install (Talk Flows, Store, Point Bank, Contracts & Payments).
	 */
	function gend_society_sa_menu_render_gated(): void {
		$page  = gend_society_sa_menu_request( 'page' );
		$gated = gend_society_sa_menu_gated();
		$item  = $gated[ $page ] ?? array( 'label' => __( 'This area', 'gend-society' ), 'area' => 'app' );
		if ( 'included' !== gend_society_sa_menu_area_state( $item['area'] ) && function_exists( 'gend_society_feature_upgrade_page_standalone' ) ) {
			gend_society_feature_upgrade_page_standalone( $item['area'] );
			return;
		}
		echo '<div class="wrap gend-society-plan gend-society-upgrade"><h1>' . esc_html( $item['label'] ) . '</h1>';
		echo gend_society_sa_menu_feature_html( $item['label'], $item['area'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_url' ) ) {
	/**
	 * Admin URL of a core sub-menu entry (a .php file, or a page slug under its core parent).
	 *
	 * @param string $parent Parent file.
	 * @param string $slug   Sub-menu slug.
	 */
	function gend_society_sa_menu_url( string $parent, string $slug ): string {
		if ( false !== strpos( $slug, '.php' ) ) {
			return admin_url( $slug );
		}
		return admin_url( $parent . ( false !== strpos( $parent, '?' ) ? '&' : '?' ) . 'page=' . rawurlencode( $slug ) );
	}
}

if ( ! function_exists( 'gend_society_sa_menu_core_items' ) ) {
	/**
	 * The current user's core sub-menu entries under a hidden core menu (core + anything plugins add there).
	 *
	 * @param string $parent Core parent file.
	 * @return array<int,array{label:string,url:string,slug:string}>
	 */
	function gend_society_sa_menu_core_items( string $parent ): array {
		global $submenu;
		$out = array();
		foreach ( (array) ( $submenu[ $parent ] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) || ( isset( $item[1] ) && ! current_user_can( $item[1] ) ) ) {
				continue;
			}
			$out[] = array(
				'label' => trim( wp_strip_all_tags( (string) $item[0] ) ),
				'url'   => gend_society_sa_menu_url( $parent, (string) $item[2] ),
				'slug'  => (string) $item[2],
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_sa_menu_strip' ) ) {
	/**
	 * Hub tab strip markup (.gdc-tabs / .gdc-tab, the look of gend.me's Content Campaigns / Social Profiles tabs).
	 *
	 * @param array  $tabs   Each: label, url, active (bool), icon (optional dashicons class).
	 * @param string $class  Extra class.
	 * @param string $label  Accessible name.
	 */
	function gend_society_sa_menu_strip( array $tabs, string $class, string $label ): string {
		$h = '<nav class="gdc-tabs ' . esc_attr( $class ) . '" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $tabs as $t ) {
			$icon = ! empty( $t['icon'] ) ? '<span class="dashicons ' . esc_attr( $t['icon'] ) . '" aria-hidden="true"></span>' : '';
			$h   .= '<a class="gdc-tab' . ( ! empty( $t['active'] ) ? ' active' : '' ) . '" href="' . esc_url( $t['url'] ) . '"' . ( ! empty( $t['active'] ) ? ' aria-current="page"' : '' ) . '>' . $icon . esc_html( $t['label'] ) . '</a>';
		}
		return $h . '</nav>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_hero' ) ) {
	/**
	 * Page title block in the gend.me hero style.
	 *
	 * @param string $title Title.
	 * @param string $lead  Lead text.
	 */
	function gend_society_sa_menu_hero( string $title, string $lead ): string {
		return '<div class="gs-sa-hero"><h1 class="gs-sa-hero__title">' . esc_html( $title ) . '</h1><p class="gdc-lead">' . esc_html( $lead ) . '</p></div>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_cc_tabs' ) ) {
	/**
	 * Content Campaigns tabs (gend.me order: Analytics, Info Pages, Blogging, Social, Advertisements, Media).
	 * WordPress tabs open the core screens; the others are gend.me features (plan area 'app').
	 *
	 * @param string $active Active tab slug.
	 */
	function gend_society_sa_menu_cc_tabs( string $active ): array {
		$page = admin_url( 'admin.php?page=content-campaigns' );
		$tabs = array(
			'analytics' => array( 'label' => __( 'Analytics', 'gend-society' ), 'icon' => 'dashicons-chart-area', 'url' => add_query_arg( 'tab', 'analytics', $page ), 'cap' => 'edit_posts' ),
			'pages'     => array( 'label' => __( 'Info Pages', 'gend-society' ), 'icon' => 'dashicons-admin-page', 'url' => admin_url( 'edit.php?post_type=page' ), 'cap' => 'edit_pages' ),
			'blogging'  => array( 'label' => __( 'Blogging', 'gend-society' ), 'icon' => 'dashicons-welcome-write-blog', 'url' => admin_url( 'edit.php' ), 'cap' => 'edit_posts' ),
			'social'    => array( 'label' => __( 'Social', 'gend-society' ), 'icon' => 'dashicons-share', 'url' => add_query_arg( 'tab', 'social', $page ), 'cap' => 'edit_posts' ),
			'ads'       => array( 'label' => __( 'Advertisements', 'gend-society' ), 'icon' => 'dashicons-megaphone', 'url' => add_query_arg( 'tab', 'advertisements', $page ), 'cap' => 'edit_posts' ),
			'media'     => array( 'label' => __( 'Media', 'gend-society' ), 'icon' => 'dashicons-images-alt2', 'url' => admin_url( 'upload.php' ), 'cap' => 'upload_files' ),
		);
		$out = array();
		foreach ( $tabs as $slug => $t ) {
			if ( current_user_can( $t['cap'] ) ) {
				$t['active'] = $slug === $active;
				$out[]       = $t;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_sa_menu_social_tabs' ) ) {
	/**
	 * Social Profiles tabs (gend.me order: Profiles, Members, Groups, Activity Wall). Members opens the core Users
	 * screen (Management); the others are gend.me features (plan area 'social').
	 *
	 * @param string $active Active tab slug.
	 */
	function gend_society_sa_menu_social_tabs( string $active ): array {
		$page = admin_url( 'admin.php?page=gdc-social-network-settings' );
		$tabs = array(
			'profiles' => array( 'label' => __( 'Profiles', 'gend-society' ), 'icon' => 'dashicons-admin-users', 'url' => add_query_arg( 'tab', 'profiles', $page ) ),
			'members'  => array( 'label' => __( 'Members', 'gend-society' ), 'icon' => 'dashicons-groups', 'url' => admin_url( 'users.php' ) ),
			'groups'   => array( 'label' => __( 'Groups', 'gend-society' ), 'icon' => 'dashicons-groups', 'url' => add_query_arg( 'tab', 'groups', $page ) ),
			'wall'     => array( 'label' => __( 'Activity Wall', 'gend-society' ), 'icon' => 'dashicons-buddicons-activity', 'url' => add_query_arg( 'tab', 'wall', $page ) ),
		);
		$out = array();
		foreach ( $tabs as $slug => $t ) {
			$t['active'] = $slug === $active;
			$out[]       = $t;
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_sa_menu_render_content_campaigns' ) ) {
	/**
	 * App > Content Campaigns (bundled page). Analytics / Social / Advertisements are gend.me features: the
	 * upgrade page until the dashboard plan includes them. Info Pages, Blogging and Media open the WordPress screens.
	 */
	function gend_society_sa_menu_render_content_campaigns(): void {
		$tab = gend_society_sa_menu_request( 'tab' );
		$map = array(
			'social'         => array( 'social', __( 'Social', 'gend-society' ) ),
			'advertisements' => array( 'ads', __( 'Advertisements', 'gend-society' ) ),
		);
		$key   = isset( $map[ $tab ] ) ? $map[ $tab ][0] : 'analytics';
		$label = isset( $map[ $tab ] ) ? $map[ $tab ][1] : __( 'Content Campaigns analytics', 'gend-society' );
		echo '<div class="wrap gdc-admin-dashboard gs-sa-wrap gend-society-plan gend-society-upgrade">';
		echo gend_society_sa_menu_hero( __( 'Content Campaigns', 'gend-society' ), __( 'Pages, posts, comments and media for this site. Campaign analytics, social publishing and advertising come with your gend.me plan.', 'gend-society' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo gend_society_sa_menu_strip( gend_society_sa_menu_cc_tabs( $key ), 'gs-sa-tabs', __( 'Content Campaigns sections', 'gend-society' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '<p class="gs-plan-toolbar"><span class="gs-plan-live" role="status" aria-live="polite" data-gs-plan-live="1"></span></p>';
		echo gend_society_sa_menu_feature_html( $label, 'app' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_render_social_profiles' ) ) {
	/**
	 * Social > Social Profiles (bundled page). Members opens Users (free); Profiles / Groups / Activity Wall are
	 * gend.me features.
	 */
	function gend_society_sa_menu_render_social_profiles(): void {
		$tab = gend_society_sa_menu_request( 'tab' );
		$map = array(
			'groups' => __( 'Groups', 'gend-society' ),
			'wall'   => __( 'Activity Wall', 'gend-society' ),
		);
		$key   = isset( $map[ $tab ] ) ? $tab : 'profiles';
		$label = isset( $map[ $tab ] ) ? $map[ $tab ] : __( 'Social profiles', 'gend-society' );
		echo '<div class="wrap gdc-admin-dashboard gs-sa-wrap gend-society-plan gend-society-upgrade">';
		echo gend_society_sa_menu_hero( __( 'Social Profiles', 'gend-society' ), __( 'Members of this site are managed here. Profiles, groups and the activity wall come with your gend.me plan.', 'gend-society' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo gend_society_sa_menu_strip( gend_society_sa_menu_social_tabs( $key ), 'gs-sa-tabs', __( 'Social Profiles sections', 'gend-society' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '<p class="gs-plan-toolbar"><span class="gs-plan-live" role="status" aria-live="polite" data-gs-plan-live="1"></span></p>';
		echo gend_society_sa_menu_feature_html( $label, 'social' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_context' ) ) {
	/**
	 * The gend.me home of the core screen being viewed: array(home, tab) or array() when it has none here.
	 * home = cc | social | themes | codebase.
	 */
	function gend_society_sa_menu_context(): array {
		global $pagenow, $typenow;
		$pt = (string) $typenow;
		switch ( (string) $pagenow ) {
			case 'edit.php':
			case 'edit-tags.php':
			case 'term.php':
				if ( 'page' === $pt ) {
					return array( 'cc', 'pages' );
				}
				return ( '' === $pt || 'post' === $pt ) ? array( 'cc', 'blogging' ) : array();
			case 'edit-comments.php':
				return array( 'cc', 'blogging' );
			case 'upload.php':
			case 'media-new.php':
				return array( 'cc', 'media' );
			case 'users.php':
			case 'user-new.php':
			case 'user-edit.php':
			case 'profile.php':
				return current_user_can( 'list_users' ) ? array( 'social', 'members' ) : array();
			case 'themes.php':
			case 'theme-install.php':
			case 'widgets.php':
			case 'nav-menus.php':
			case 'theme-editor.php':
				return array( 'themes', '' );
			case 'plugins.php':
			case 'plugin-install.php':
			case 'plugin-editor.php':
			case 'update-core.php':
				return array( 'codebase', '' );
		}
		return array();
	}
}

if ( ! function_exists( 'gend_society_sa_menu_is_current' ) ) {
	/**
	 * True when a core sub-menu slug is the screen being viewed.
	 *
	 * @param string $slug Sub-menu slug.
	 */
	function gend_society_sa_menu_is_current( string $slug ): bool {
		global $pagenow, $typenow;
		$file  = (string) wp_parse_url( $slug, PHP_URL_PATH );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $slug, PHP_URL_QUERY ), $query );
		if ( false === strpos( $slug, '.php' ) ) {
			return gend_society_sa_menu_request( 'page' ) === $slug;
		}
		if ( $file !== $pagenow && ! ( 'edit-tags.php' === $file && 'term.php' === $pagenow ) ) {
			return false;
		}
		if ( isset( $query['taxonomy'] ) && gend_society_sa_menu_request( 'taxonomy' ) !== $query['taxonomy'] ) {
			return false;
		}
		$pt = isset( $query['post_type'] ) ? (string) $query['post_type'] : 'post';
		return in_array( $pagenow, array( 'edit.php', 'post-new.php' ), true ) ? $pt === ( '' === (string) $typenow ? 'post' : (string) $typenow ) : true;
	}
}

if ( ! function_exists( 'gend_society_sa_menu_sub_tabs' ) ) {
	/**
	 * Core sub-menu entries as tabs.
	 *
	 * @param string $parent Core parent file.
	 * @param array  $rename Optional slug => label.
	 */
	function gend_society_sa_menu_sub_tabs( string $parent, array $rename = array() ): array {
		$tabs = array();
		foreach ( gend_society_sa_menu_core_items( $parent ) as $i ) {
			$tabs[] = array(
				'label'  => $rename[ $i['slug'] ] ?? $i['label'],
				'url'    => $i['url'],
				'active' => gend_society_sa_menu_is_current( $i['slug'] ),
			);
		}
		return $tabs;
	}
}

if ( ! function_exists( 'gend_society_sa_menu_core_strip' ) ) {
	/**
	 * all_admin_notices on a core screen: the tab strip of its gend.me home, so the screen reads as that tab and
	 * every sibling screen is one click away.
	 */
	function gend_society_sa_menu_core_strip(): void {
		$ctx = gend_society_sa_menu_context();
		if ( ! $ctx ) {
			return;
		}
		$h = '<div class="gs-sa-home gdc-admin-dashboard" data-gs-sa-home="' . esc_attr( $ctx[0] ) . '">';
		if ( 'cc' === $ctx[0] ) {
			$h .= '<p class="gs-sa-home__crumb">' . esc_html__( 'App', 'gend-society' ) . ' &rsaquo; <a href="' . esc_url( admin_url( 'admin.php?page=content-campaigns' ) ) . '">' . esc_html__( 'Content Campaigns', 'gend-society' ) . '</a></p>';
			$h .= gend_society_sa_menu_strip( gend_society_sa_menu_cc_tabs( $ctx[1] ), 'gs-sa-tabs', __( 'Content Campaigns sections', 'gend-society' ) );
			if ( 'blogging' === $ctx[1] ) {
				$sub   = gend_society_sa_menu_sub_tabs( 'edit.php' );
				$sub[] = array(
					'label'  => __( 'Comments', 'gend-society' ),
					'url'    => admin_url( 'edit-comments.php' ),
					'active' => 'edit-comments.php' === $GLOBALS['pagenow'],
				);
				$h .= current_user_can( 'moderate_comments' ) || current_user_can( 'edit_posts' ) ? gend_society_sa_menu_strip( $sub, 'gs-sa-subtabs', __( 'Blogging', 'gend-society' ) ) : '';
			} elseif ( 'pages' === $ctx[1] ) {
				$h .= gend_society_sa_menu_strip( gend_society_sa_menu_sub_tabs( 'edit.php?post_type=page' ), 'gs-sa-subtabs', __( 'Info Pages', 'gend-society' ) );
			} else {
				$h .= gend_society_sa_menu_strip( gend_society_sa_menu_sub_tabs( 'upload.php' ), 'gs-sa-subtabs', __( 'Media', 'gend-society' ) );
			}
		} elseif ( 'social' === $ctx[0] ) {
			$h .= '<p class="gs-sa-home__crumb">' . esc_html__( 'Social', 'gend-society' ) . ' &rsaquo; <a href="' . esc_url( admin_url( 'admin.php?page=gdc-social-network-settings' ) ) . '">' . esc_html__( 'Social Profiles', 'gend-society' ) . '</a></p>';
			$h .= gend_society_sa_menu_strip( gend_society_sa_menu_social_tabs( 'members' ), 'gs-sa-tabs', __( 'Social Profiles sections', 'gend-society' ) );
			$h .= gend_society_sa_menu_strip( gend_society_sa_menu_sub_tabs( 'users.php', array( 'users.php' => __( 'Management', 'gend-society' ) ) ), 'gs-sa-subtabs', __( 'Members', 'gend-society' ) );
		} elseif ( 'themes' === $ctx[0] ) {
			$tabs = array();
			if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && current_user_can( 'edit_theme_options' ) ) {
				$tabs[] = array(
					'label'  => __( 'Theme Editor', 'gend-society' ),
					'url'    => admin_url( 'site-editor.php' ),
					'active' => false,
				);
			}
			foreach ( gend_society_sa_menu_sub_tabs( 'themes.php' ) as $t ) {
				if ( admin_url( 'site-editor.php' ) !== $t['url'] ) {
					$tabs[] = $t;
				}
			}
			$h .= '<p class="gs-sa-home__crumb">' . esc_html__( 'App', 'gend-society' ) . ' &rsaquo; ' . esc_html__( 'Theme Editor', 'gend-society' ) . '</p>';
			$h .= gend_society_sa_menu_strip( $tabs, 'gs-sa-tabs', __( 'Theme sections', 'gend-society' ) );
		} else {
			$tabs   = gend_society_sa_menu_sub_tabs( 'plugins.php' );
			$tabs[] = array(
				'label'  => __( 'Updates', 'gend-society' ),
				'url'    => admin_url( 'update-core.php' ),
				'active' => 'update-core.php' === $GLOBALS['pagenow'],
			);
			$h .= '<p class="gs-sa-home__crumb"><a href="' . esc_url( admin_url( 'index.php' ) ) . '">' . esc_html__( 'Dashboard', 'gend-society' ) . '</a> &rsaquo; ' . esc_html__( 'Hosting', 'gend-society' ) . ' &rsaquo; ' . esc_html__( 'Codebase', 'gend-society' ) . '</p>';
			$h .= gend_society_sa_menu_strip( current_user_can( 'activate_plugins' ) || current_user_can( 'update_core' ) ? $tabs : array(), 'gs-sa-tabs', __( 'Codebase sections', 'gend-society' ) );
		}
		echo $h . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}
	add_action( 'all_admin_notices', 'gend_society_sa_menu_core_strip', 1 );
}

if ( ! function_exists( 'gend_society_sa_menu_codebase_links' ) ) {
	/**
	 * Links from Dashboard > Hosting > Codebase > Code Packages to the WordPress plugin + update screens. Called by
	 * gend_society_hosting_render_codebase_packages() on standalone only.
	 */
	function gend_society_sa_menu_codebase_links(): void {
		$links = array();
		if ( current_user_can( 'activate_plugins' ) ) {
			$links[] = array( admin_url( 'plugins.php' ), __( 'Manage plugins', 'gend-society' ) );
		}
		if ( current_user_can( 'install_plugins' ) ) {
			$links[] = array( admin_url( 'plugin-install.php' ), __( 'Add new plugin', 'gend-society' ) );
		}
		if ( current_user_can( 'update_core' ) || current_user_can( 'update_plugins' ) ) {
			$links[] = array( admin_url( 'update-core.php' ), __( 'Updates', 'gend-society' ) );
		}
		if ( ! $links ) {
			return;
		}
		echo '<p class="gs-sa-codebase-links" data-gs-sa-codebase-links="1">';
		foreach ( $links as $l ) {
			echo '<a class="gs-hosting__btn gs-hosting__btn--ghost" href="' . esc_url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a> ';
		}
		echo '</p>';
	}
}

if ( ! function_exists( 'gend_society_sa_menu_parent_file' ) ) {
	/**
	 * Highlight the gend.me home of a core screen in the sidebar.
	 *
	 * @param string $parent_file Parent file.
	 */
	function gend_society_sa_menu_parent_file( $parent_file ) {
		$ctx = gend_society_sa_menu_context();
		if ( ! $ctx ) {
			return $parent_file;
		}
		$map = array(
			'cc'       => 'gs-content',
			'social'   => 'gs-social',
			'themes'   => 'gs-content',
			'codebase' => 'index.php',
		);
		return $map[ $ctx[0] ];
	}
	add_filter( 'parent_file', 'gend_society_sa_menu_parent_file', 20 );
}

if ( ! function_exists( 'gend_society_sa_menu_submenu_file' ) ) {
	/**
	 * Highlight the bundled sub-menu entry of a core screen's home.
	 *
	 * @param string|null $submenu_file Sub-menu file.
	 */
	function gend_society_sa_menu_submenu_file( $submenu_file ) {
		$ctx = gend_society_sa_menu_context();
		if ( ! $ctx ) {
			return $submenu_file;
		}
		$map = array(
			'cc'       => 'content-campaigns',
			'social'   => 'gdc-social-network-settings',
			'themes'   => 'themes.php',
			'codebase' => null,
		);
		return $map[ $ctx[0] ];
	}
	add_filter( 'submenu_file', 'gend_society_sa_menu_submenu_file', 20 );
}

if ( ! function_exists( 'gend_society_sa_menu_order' ) ) {
	/**
	 * Sidebar order as on gend.me: Dashboard, App, Store, Social, other plugins' menus (own icons), then Tools and
	 * Settings (kept: no home in the dashboard design). Runs after inc/admin-menu.php's suppression (99999).
	 *
	 * @param array $order Menu slugs.
	 */
	function gend_society_sa_menu_order( $order ) {
		if ( ! is_array( $order ) ) {
			return $order;
		}
		$head = array( 'index.php', 'gs-content', 'gs-store', 'gs-social' );
		$tail = array( 'tools.php', 'options-general.php' );
		$mid  = array();
		foreach ( $order as $slug ) {
			if ( in_array( $slug, $head, true ) || in_array( $slug, $tail, true ) || 0 === strpos( (string) $slug, 'separator' ) ) {
				continue;
			}
			$mid[] = $slug;
		}
		$out = array_values( array_intersect( $head, $order ) );
		if ( in_array( 'separator1', $order, true ) ) {
			$out[] = 'separator1';
		}
		$out = array_merge( $out, $mid );
		if ( in_array( 'separator2', $order, true ) ) {
			$out[] = 'separator2';
		}
		$out = array_merge( $out, array_values( array_intersect( $tail, $order ) ) );
		if ( in_array( 'separator-last', $order, true ) ) {
			$out[] = 'separator-last';
		}
		gend_society_sa_menu_icons();
		return $out;
	}
}

if ( ! function_exists( 'gend_society_sa_menu_icons' ) ) {
	/**
	 * The skin hides WordPress's own icon column (#adminmenu .wp-menu-image, assets/admin-style.css) and draws the
	 * .gs-menu-icon span in each label, which only GenD menus carry. Give every other top-level menu (other plugins,
	 * Tools, Settings) the same span built from its registered icon, so it keeps its own icon in the GenD rail.
	 * Same place as inc/admin-menu.php's suppression (menu_order, after every admin_menu callback).
	 */
	function gend_society_sa_menu_icons(): void {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $pos => $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) || 0 === strpos( (string) $item[2], 'separator' ) || false !== strpos( (string) ( $item[0] ?? '' ), 'gs-menu-icon' ) ) {
				continue;
			}
			$icon = isset( $item[6] ) ? (string) $item[6] : '';
			if ( 0 === strpos( $icon, 'dashicons-' ) ) {
				$span = '<span class="gs-menu-icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			} elseif ( 0 === strpos( $icon, 'data:image/' ) || 0 === strpos( $icon, 'http' ) ) {
				$span = '<span class="gs-menu-icon gs-menu-icon--img" aria-hidden="true" style="background:center/18px 18px no-repeat url(&quot;' . esc_attr( $icon ) . '&quot;)"></span>';
			} else {
				$span = '<span class="gs-menu-icon dashicons dashicons-admin-generic" aria-hidden="true"></span>';
			}
			$menu[ $pos ][0] = $span . '<span class="gs-menu-label">' . $item[0] . '</span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- adds the skin's icon span to the label, as inc/admin-menu.php does for GenD menus.
		}
	}
	add_filter( 'custom_menu_order', '__return_true' );
	add_filter( 'menu_order', 'gend_society_sa_menu_order', 100000 );
}

if ( ! function_exists( 'gend_society_sa_menu_assets' ) ) {
	/**
	 * Tab strip styles (the gend.me hub tab look) on every skinned admin screen.
	 */
	function gend_society_sa_menu_assets(): void {
		$rel = 'assets/gs-standalone-menu.css';
		$ver = GEND_SOCIETY_VERSION . ( file_exists( GEND_SOCIETY_DIR . $rel ) ? '.' . filemtime( GEND_SOCIETY_DIR . $rel ) : '' );
		wp_enqueue_style( 'gend-society-standalone-menu', GEND_SOCIETY_URL . $rel, array(), $ver );
	}
	add_action( 'admin_enqueue_scripts', 'gend_society_sa_menu_assets' );
}
