<?php
/**
 * Social Pages: one builder for group and profile pages (Design Studio ›
 * Social › Group Pages / Profile Pages).
 *
 * - Every menu tab can have several pages. Each page says who sees it
 *   (logged-out visitors, members, group roles, member types, group types,
 *   plan features); the first page that matches the viewer is shown, then
 *   the tab's default page.
 * - Every page (and every tab without pages) picks its layout: the theme's
 *   own, full width, or a left / right sidebar chosen from the scope's
 *   sidebars (gdc_bp_layout posts: group-sidebar*, profile-sidebar*).
 *
 * Groups: gdc_group_page posts (projects plugin) keyed by _gdc_endpoint_slug;
 * the per-viewer page reaches the group through the gdc_gs_page_variant
 * filter. Profiles: gdc_profile_tab posts (inc/profile-styling.php).
 *
 * Page meta: _gs_audience (JSON), _gs_layout ('', full, left, right),
 * _gs_sidebar (gdc_bp_layout id, 0 = the scope's default sidebar).
 * Order among a tab's pages: menu_order.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_SP_TAB_LAYOUTS = 'gend_society_sp_tab_layouts';

/**
 * Per-scope settings.
 *
 * @param string $scope groups|profiles.
 * @return array
 */
function gend_society_sp_scope( $scope ) {
	if ( 'groups' === $scope ) {
		return array(
			'page_type'   => 'gdc_group_page',
			'slug_key'    => '_gdc_endpoint_slug',
			'header_type' => 'gdc_group_header',
			'sidebar'     => 'group-sidebar',
			'directory'   => 'groups-directory',
		);
	}
	return array(
		'page_type'   => 'gdc_profile_tab',
		'slug_key'    => '_gdc_tab_slug',
		'header_type' => 'gdc_profile_header',
		'sidebar'     => 'profile-sidebar',
		'directory'   => 'members-directory',
	);
}

/* ── Audience ───────────────────────────────────────────────────────────── */

/**
 * A clean audience rule.
 *
 * @param mixed $raw Rule (array or JSON).
 * @return array { who: all|out|in, roles: [], memberTypes: [], profileTypes: [], groupTypes: [], features: [] }
 */
function gend_society_sp_clean_audience( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = json_decode( $raw, true );
	}
	$raw  = is_array( $raw ) ? $raw : array();
	$list = function ( $k ) use ( $raw ) {
		return isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $raw[ $k ] ) ) ) ) : array();
	};
	$who = isset( $raw['who'] ) && in_array( $raw['who'], array( 'all', 'out', 'in' ), true ) ? $raw['who'] : 'all';
	return array(
		'who'          => $who,
		'roles'        => $list( 'roles' ),
		'memberTypes'  => $list( 'memberTypes' ),
		'profileTypes' => $list( 'profileTypes' ),
		'groupTypes'   => $list( 'groupTypes' ),
		'features'     => $list( 'features' ),
	);
}

/**
 * The viewer's role on a group or profile.
 *
 * @param string $scope groups|profiles.
 * @param int    $id    Group ID or profile user ID.
 * @return string admin|mod|member|non-member|owner|friend|visitor|out
 */
function gend_society_sp_viewer_role( $scope, $id ) {
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return 'out';
	}
	if ( 'groups' === $scope ) {
		if ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $uid, $id ) ) {
			return 'admin';
		}
		if ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( $uid, $id ) ) {
			return 'mod';
		}
		if ( function_exists( 'groups_is_user_member' ) && groups_is_user_member( $uid, $id ) ) {
			return 'member';
		}
		return 'non-member';
	}
	if ( (int) $id === $uid ) {
		return 'owner';
	}
	if ( function_exists( 'friends_check_friendship' ) && friends_check_friendship( $uid, (int) $id ) ) {
		return 'friend';
	}
	return 'visitor';
}

/**
 * Plan features a group's membership includes (VAM plan map).
 *
 * @param int $group_id Group ID.
 * @return string[]
 */
function gend_society_sp_group_features( $group_id ) {
	static $cache = array();
	if ( isset( $cache[ $group_id ] ) ) {
		return $cache[ $group_id ];
	}
	$out = array();
	$mid = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( $group_id, 'gdc_membership_id', true ) : 0;
	if ( $mid && function_exists( 'wu_get_membership' ) && function_exists( 'gdc_resolve_membership_dashboard_plan' ) && function_exists( 'gdc_get_plan_feature_map' ) ) {
		$membership = wu_get_membership( $mid );
		$plan       = $membership ? gdc_resolve_membership_dashboard_plan( $membership ) : '';
		$map        = gdc_get_plan_feature_map();
		$plan       = is_array( $plan ) ? ( isset( $plan['slug'] ) ? $plan['slug'] : '' ) : (string) $plan;
		if ( $plan && isset( $map[ $plan ] ) && is_array( $map[ $plan ] ) ) {
			$out = array_values( $map[ $plan ] );
		}
	}
	/**
	 * Filters the features a group has, for "feature access" page rules.
	 *
	 * @param string[] $out      Feature slugs.
	 * @param int      $group_id Group ID.
	 */
	$cache[ $group_id ] = (array) apply_filters( 'gend_society_sp_group_features', $out, $group_id );
	return $cache[ $group_id ];
}

/**
 * Whether a page's audience includes the current viewer.
 *
 * @param array  $aud   Clean audience.
 * @param string $scope groups|profiles.
 * @param int    $id    Group ID or profile user ID.
 * @return bool
 */
function gend_society_sp_matches( $aud, $scope, $id ) {
	$uid = get_current_user_id();
	if ( 'out' === $aud['who'] ) {
		return ! $uid;
	}
	if ( 'in' === $aud['who'] && ! $uid ) {
		return false;
	}
	if ( $aud['roles'] && ! in_array( gend_society_sp_viewer_role( $scope, $id ), $aud['roles'], true ) ) {
		return false;
	}
	if ( $aud['memberTypes'] && ! array_intersect( $aud['memberTypes'], gend_society_sp_member_types( $uid ) ) ) {
		return false;
	}
	if ( 'profiles' === $scope && $aud['profileTypes'] && ! array_intersect( $aud['profileTypes'], gend_society_sp_member_types( (int) $id ) ) ) {
		return false;
	}
	if ( 'groups' === $scope && $aud['groupTypes'] ) {
		$types = function_exists( 'bp_groups_get_group_type' ) ? (array) bp_groups_get_group_type( $id, false ) : array();
		if ( ! array_intersect( $aud['groupTypes'], $types ) ) {
			return false;
		}
	}
	if ( 'groups' === $scope && $aud['features'] && array_diff( $aud['features'], gend_society_sp_group_features( $id ) ) ) {
		return false;
	}
	return true;
}

/**
 * A member's member types.
 *
 * @param int $user_id User ID.
 * @return string[]
 */
function gend_society_sp_member_types( $user_id ) {
	if ( ! $user_id || ! function_exists( 'bp_get_member_type' ) ) {
		return array();
	}
	$types = bp_get_member_type( $user_id, false );
	return is_array( $types ) ? $types : array();
}

/**
 * The audience pages of a tab, in order (the default page is not one of them).
 *
 * @param string $scope groups|profiles.
 * @param string $slug  Tab slug.
 * @return WP_Post[]
 */
function gend_society_sp_audience_pages( $scope, $slug ) {
	static $cache = array();
	$key          = $scope . '|' . $slug;
	if ( ! isset( $cache[ $key ] ) ) {
		$sc            = gend_society_sp_scope( $scope );
		$cache[ $key ] = get_posts(
			array(
				'post_type'      => $sc['page_type'],
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tiny admin-authored set, cached per request.
				'meta_query'     => array(
					array(
						'key'   => $sc['slug_key'],
						'value' => $slug,
					),
					array(
						'key'     => '_gs_audience',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}
	return $cache[ $key ];
}

/**
 * The first audience page of a tab that the viewer matches.
 *
 * @param string $scope groups|profiles.
 * @param string $slug  Tab slug.
 * @param int    $id    Group ID or profile user ID.
 * @return WP_Post|null
 */
function gend_society_sp_pick( $scope, $slug, $id ) {
	// Editors preview one page on the real site: ?gs_sp_preview=<page id>.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for site editors.
	$preview = isset( $_GET['gs_sp_preview'] ) ? absint( $_GET['gs_sp_preview'] ) : 0;
	$sc      = gend_society_sp_scope( $scope );
	if ( $preview && current_user_can( 'edit_theme_options' ) && get_post_type( $preview ) === $sc['page_type'] && (string) get_post_meta( $preview, $sc['slug_key'], true ) === $slug ) {
		return get_post( $preview );
	}
	foreach ( gend_society_sp_audience_pages( $scope, $slug ) as $p ) {
		if ( gend_society_sp_matches( gend_society_sp_clean_audience( get_post_meta( $p->ID, '_gs_audience', true ) ), $scope, $id ) ) {
			return $p;
		}
	}
	return null;
}

add_filter( 'gdc_gs_page_variant', 'gend_society_sp_group_variant', 10, 3 );
/**
 * The group page this viewer gets for a tab (projects plugin resolver).
 *
 * @param int    $page_id  Page so far (0).
 * @param int    $group_id Group ID.
 * @param string $slug     Tab slug.
 * @return int
 */
function gend_society_sp_group_variant( $page_id, $group_id, $slug ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $page_id;
	}
	$p = gend_society_sp_pick( 'groups', (string) $slug, (int) $group_id );
	return $p ? (int) $p->ID : $page_id;
}

add_filter( 'gend_society_ps_resolve', 'gend_society_sp_profile_variant', 10, 4 );
/**
 * The profile tab layout this viewer gets (inc/profile-styling.php resolver).
 *
 * @param WP_Post|null $found     Layout so far.
 * @param string       $post_type gdc_profile_header or gdc_profile_tab.
 * @param int          $user_id   Profile member.
 * @param string       $tab       Tab slug.
 * @return WP_Post|null
 */
function gend_society_sp_profile_variant( $found, $post_type, $user_id, $tab ) {
	if ( 'gdc_profile_tab' !== $post_type || '' === $tab ) {
		return $found;
	}
	$p = gend_society_sp_pick( 'profiles', $tab, (int) $user_id );
	return $p ? $p : $found;
}

/* ── Layout (full width / sidebar side / which sidebar) ─────────────────── */

/**
 * Layout of a page post, or of a tab with no pages.
 *
 * @param string       $scope groups|profiles.
 * @param string       $slug  Tab slug.
 * @param WP_Post|null $page  The page shown.
 * @return array { layout: ''|full|left|right, sidebar: int }
 */
function gend_society_sp_layout_of( $scope, $slug, $page ) {
	if ( $page ) {
		$layout  = (string) get_post_meta( $page->ID, '_gs_layout', true );
		$sidebar = (int) get_post_meta( $page->ID, '_gs_sidebar', true );
	} else {
		$all     = get_option( GEND_SOCIETY_SP_TAB_LAYOUTS, array() );
		$c       = isset( $all[ $scope ][ $slug ] ) && is_array( $all[ $scope ][ $slug ] ) ? $all[ $scope ][ $slug ] : array();
		$layout  = isset( $c['layout'] ) ? (string) $c['layout'] : '';
		$sidebar = isset( $c['sidebar'] ) ? (int) $c['sidebar'] : 0;
	}
	return array(
		'layout'  => in_array( $layout, array( 'full', 'left', 'right' ), true ) ? $layout : '',
		'sidebar' => $sidebar,
	);
}

/**
 * The layout of the group or profile page being viewed (null elsewhere).
 *
 * @return array|null { scope, slug, layout, sidebar }
 */
function gend_society_sp_current() {
	static $cur = false;
	if ( false !== $cur ) {
		return $cur;
	}
	$cur = null;
	if ( function_exists( 'bp_is_group' ) && bp_is_group() ) {
		$gid  = (int) bp_get_current_group_id();
		$slug = sanitize_key( (string) bp_current_action() );
		if ( '' === $slug || 'home' === $slug ) {
			$slug = function_exists( 'gdc_gs_get_protected_landing_slug' ) ? (string) gdc_gs_get_protected_landing_slug() : 'home';
		}
		$page = null;
		if ( function_exists( 'gdc_gs_resolve_page_post' ) ) {
			$pid  = (int) gdc_gs_resolve_page_post( $gid, $slug );
			$page = $pid ? get_post( $pid ) : null;
		}
		$cur = array_merge( array( 'scope' => 'groups', 'slug' => $slug ), gend_society_sp_layout_of( 'groups', $slug, $page ) );
	} elseif ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
		$slug = sanitize_key( (string) bp_current_component() );
		$page = $slug && function_exists( 'gend_society_ps_resolve' ) ? gend_society_ps_resolve( 'gdc_profile_tab', (int) bp_displayed_user_id(), $slug ) : null;
		$cur  = array_merge( array( 'scope' => 'profiles', 'slug' => $slug ), gend_society_sp_layout_of( 'profiles', $slug, $page ) );
	}
	return $cur;
}

/**
 * A sidebar layout post of a scope, if published.
 *
 * @param string $scope groups|profiles.
 * @param int    $id    gdc_bp_layout ID.
 * @return WP_Post|null
 */
function gend_society_sp_sidebar_post( $scope, $id ) {
	$p    = $id ? get_post( $id ) : null;
	$base = gend_society_sp_scope( $scope )['sidebar'];
	if ( ! $p || 'gdc_bp_layout' !== $p->post_type || 'publish' !== $p->post_status || 0 !== strpos( (string) get_post_meta( $p->ID, '_gdc_layout_slug', true ), $base ) ) {
		return null;
	}
	return $p;
}

add_filter( 'gend_society_sp_sidebar_layout', 'gend_society_sp_chosen_sidebar', 10, 2 );
/**
 * The sidebar a page picked, in place of the scope's default sidebar.
 *
 * @param WP_Post|null $tpl   Default sidebar layout.
 * @param string       $scope groups|profiles.
 * @return WP_Post|null
 */
function gend_society_sp_chosen_sidebar( $tpl, $scope ) {
	$cur = gend_society_sp_current();
	if ( ! $cur || $cur['scope'] !== $scope || ! $cur['sidebar'] ) {
		return $tpl;
	}
	$chosen = gend_society_sp_sidebar_post( $scope, $cur['sidebar'] );
	return $chosen ? $chosen : $tpl;
}

add_action( 'youzify_group_sidebar', 'gend_society_sp_group_sidebar', -1 );
/**
 * Full width group pages print no sidebar (a chosen sidebar is printed by
 * gend_society_ds_group_sidebar() through gend_society_sp_sidebar_layout).
 */
function gend_society_sp_group_sidebar() {
	$cur = gend_society_sp_current();
	if ( $cur && 'groups' === $cur['scope'] && 'full' === $cur['layout'] ) {
		remove_action( 'youzify_group_sidebar', 'youzify_get_groups_sidebar' );
		remove_action( 'youzify_group_sidebar', 'gend_society_ds_group_sidebar', 0 );
	}
}

add_filter( 'gend_society_ps_tab_output', 'gend_society_sp_profile_columns', 10, 2 );
/**
 * Profile tabs with a left / right layout get the sidebar beside them
 * (tabs that already have Youzify's sidebar column only move it, by class).
 *
 * @param string $html Tab output.
 * @param string $slug Tab slug.
 * @return string
 */
function gend_society_sp_profile_columns( $html, $slug ) {
	$cur = gend_society_sp_current();
	if ( ! $cur || 'profiles' !== $cur['scope'] || $cur['slug'] !== $slug || ! in_array( $cur['layout'], array( 'left', 'right' ), true ) || false !== strpos( $html, 'youzify-sidebar-column' ) ) {
		return $html;
	}
	$tpl = $cur['sidebar'] ? gend_society_sp_sidebar_post( 'profiles', $cur['sidebar'] ) : null;
	if ( ! $tpl && function_exists( 'gend_society_ds_layout' ) ) {
		$tpl = gend_society_ds_layout( 'profile-sidebar' );
	}
	if ( ! $tpl ) {
		return $html;
	}
	return '<div class="gs-sp-cols is-' . esc_attr( $cur['layout'] ) . '"><div class="gs-sp-main">' . $html . '</div><aside class="gs-sp-aside gdc-bp-sidebar">' . do_blocks( $tpl->post_content ) . '</aside></div>';
}

add_filter( 'body_class', 'gend_society_sp_body_class' );
/**
 * Body class for the page's layout.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function gend_society_sp_body_class( $classes ) {
	$cur = gend_society_sp_current();
	if ( $cur && $cur['layout'] ) {
		$classes[] = 'gs-sp-' . $cur['layout'];
	}
	return $classes;
}

add_action( 'wp_enqueue_scripts', 'gend_society_sp_front_css', 30 );
/**
 * Layout styles (they do not rely on Youzify's own stylesheet being loaded).
 */
function gend_society_sp_front_css() {
	$cur = gend_society_sp_current();
	if ( ! $cur || ! $cur['layout'] ) {
		return;
	}
	$css = 'body.gs-sp-full .youzify-right-sidebar-layout,body.gs-sp-full .youzify-left-sidebar-layout{display:block}'
		. 'body.gs-sp-full .youzify-sidebar-column{display:none!important}'
		. 'body.gs-sp-left .youzify-right-sidebar-layout,body.gs-sp-left .youzify-left-sidebar-layout{display:grid;gap:35px;grid-template-columns:28% minmax(0,1fr)}'
		. 'body.gs-sp-right .youzify-right-sidebar-layout,body.gs-sp-right .youzify-left-sidebar-layout{display:grid;gap:35px;grid-template-columns:minmax(0,1fr) 28%}'
		. 'body.gs-sp-left .youzify-main-column{order:2}body.gs-sp-left .youzify-sidebar-column{order:1}'
		. 'body.gs-sp-right .youzify-main-column{order:1}body.gs-sp-right .youzify-sidebar-column{order:2}'
		. '.gs-sp-cols{display:grid;gap:32px;grid-template-columns:minmax(0,1fr) minmax(220px,28%);align-items:start}'
		. '.gs-sp-cols.is-left{grid-template-columns:minmax(220px,28%) minmax(0,1fr)}.gs-sp-cols.is-left .gs-sp-aside{order:-1}'
		. '.gs-sp-aside{display:flex;flex-direction:column;gap:16px;min-width:0}'
		. '@media (max-width:1024px){body.gs-sp-left .youzify-right-sidebar-layout,body.gs-sp-right .youzify-right-sidebar-layout,body.gs-sp-left .youzify-left-sidebar-layout,body.gs-sp-right .youzify-left-sidebar-layout,.gs-sp-cols,.gs-sp-cols.is-left{grid-template-columns:minmax(0,1fr)}.gs-sp-cols.is-left .gs-sp-aside{order:2}}';
	wp_register_style( 'gend-society-social-pages', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-social-pages' );
	wp_add_inline_style( 'gend-society-social-pages', $css );
}

/* ── Builder REST API ───────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'gend_society_sp_register_rest' );
/**
 * Routes: GET /social-pages/<scope>, POST /social-pages/<scope>/<action>.
 */
function gend_society_sp_register_rest() {
	$perm = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/social-pages/(?P<scope>groups|profiles)',
		array(
			'methods'             => 'GET',
			'callback'            => 'gend_society_sp_rest_state',
			'permission_callback' => $perm,
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/social-pages/(?P<scope>groups|profiles)/(?P<action>menu|tab-layout|page|page-update|page-order|page-delete|sidebar|sidebar-default|sidebar-update|sidebar-delete|header|header-default|directory)',
		array(
			'methods'             => 'POST',
			'callback'            => 'gend_society_sp_rest_action',
			'permission_callback' => $perm,
		)
	);
}

/**
 * Edit link for a post in the studio's slide-down editor.
 *
 * @param int $id Post ID.
 * @return string
 */
function gend_society_sp_edit_url( $id ) {
	return admin_url( 'post.php?post=' . (int) $id . '&action=edit&gs_studio_embed=1' );
}

/**
 * Menu rows for a scope, in menu order.
 *
 * @param string $scope groups|profiles.
 * @return array[]
 */
function gend_society_sp_menu( $scope ) {
	$rows = array();
	if ( 'groups' === $scope ) {
		if ( ! function_exists( 'gdc_gs_full_nav_manageable_slugs' ) || ! function_exists( 'gdc_gs_resolve_nav_config_for_scope' ) ) {
			return array();
		}
		$config    = gdc_gs_resolve_nav_config_for_scope( '_default' );
		$content   = function_exists( 'gdc_gs_endpoints' ) ? gdc_gs_endpoints() : array();
		$protected = function_exists( 'gdc_gs_get_protected_landing_slug' ) ? gdc_gs_get_protected_landing_slug() : '';
		$defaults  = array(
			'business-plan' => 10,
			'members-hub'   => 20,
			'files'         => 30,
			'projects'      => 40,
			'payments'      => 50,
			'activity'      => 55,
			'feature-suite' => 60,
			'hosting'       => 65,
			'compute-gas'   => 70,
			'davinci-ai'    => 999,
		);
		$customs   = array();
		foreach ( $config['custom'] as $c ) {
			$customs[ $c['slug'] ] = $c;
		}
		$i = 0;
		foreach ( gdc_gs_full_nav_manageable_slugs() as $slug => $label ) {
			if ( isset( $customs[ $slug ] ) ) {
				continue;
			}
			$c      = isset( $config['built_in'][ $slug ] ) ? $config['built_in'][ $slug ] : array();
			$rows[] = array(
				'slug'         => $slug,
				'label'        => isset( $c['label'] ) && null !== $c['label'] ? (string) $c['label'] : '',
				'defaultLabel' => wp_strip_all_tags( (string) $label ),
				'hidden'       => ! empty( $c['hidden'] ),
				'position'     => isset( $c['position'] ) && null !== $c['position'] ? (int) $c['position'] : ( isset( $defaults[ $slug ] ) ? $defaults[ $slug ] : 100 + $i ),
				'custom'       => false,
				'locked'       => $slug === $protected,
				'hasPages'     => isset( $content[ $slug ] ),
			);
			++$i;
		}
		foreach ( $customs as $slug => $c ) {
			$rows[] = array(
				'slug'         => $slug,
				'label'        => (string) $c['label'],
				'defaultLabel' => (string) $c['label'],
				'hidden'       => ! empty( $c['hidden'] ),
				'position'     => (int) $c['position'],
				'custom'       => true,
				'locked'       => false,
				'hasPages'     => true,
			);
		}
	} else {
		$seen = get_option( defined( 'GEND_SOCIETY_PS_SEEN_OPTION' ) ? GEND_SOCIETY_PS_SEEN_OPTION : 'gend_society_profile_nav_seen', array() );
		$nav  = get_option( defined( 'GEND_SOCIETY_PS_NAV_OPTION' ) ? GEND_SOCIETY_PS_NAV_OPTION : 'gend_society_profile_nav', array() );
		$i    = 0;
		foreach ( is_array( $seen ) ? $seen : array() as $slug => $label ) {
			$c      = is_array( $nav ) && isset( $nav[ $slug ] ) ? $nav[ $slug ] : array();
			$rows[] = array(
				'slug'         => (string) $slug,
				'label'        => isset( $c['label'] ) ? (string) $c['label'] : '',
				'defaultLabel' => (string) $label,
				'hidden'       => ! empty( $c['hidden'] ),
				'position'     => isset( $c['order'] ) ? (int) $c['order'] : 1000 + $i,
				'custom'       => false,
				'locked'       => false,
				'hasPages'     => true,
			);
			++$i;
		}
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return $a['position'] - $b['position'];
		}
	);
	return $rows;
}

/**
 * Builder row for a page post.
 *
 * @param WP_Post $p     Page.
 * @param string  $scope groups|profiles.
 * @return array
 */
function gend_society_sp_page_row( $p, $scope ) {
	$aud = get_post_meta( $p->ID, '_gs_audience', true );
	$row = array(
		'id'        => $p->ID,
		'title'     => get_the_title( $p ),
		'isDefault' => (bool) get_post_meta( $p->ID, '_gdc_is_site_default', true ),
		'audience'  => '' === $aud ? null : gend_society_sp_clean_audience( $aud ),
		'editUrl'   => gend_society_sp_edit_url( $p->ID ),
		'modified'  => get_post_modified_time( 'c', true, $p ),
	);
	// Member-type layouts made before audiences existed count as "profile member type" pages.
	if ( 'profiles' === $scope && null === $row['audience'] && ! $row['isDefault'] ) {
		$types = array_values( array_filter( explode( ',', (string) get_post_meta( $p->ID, '_gdc_member_types', true ) ) ) );
		if ( $types ) {
			$row['audience']   = gend_society_sp_clean_audience( array( 'profileTypes' => $types ) );
			$row['legacyType'] = true;
		}
	}
	return array_merge( $row, gend_society_sp_layout_of( $scope, '', $p ) );
}

/**
 * Builder state for a scope.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function gend_society_sp_rest_state( WP_REST_Request $req ) {
	$scope = $req['scope'];
	$sc    = gend_society_sp_scope( $scope );
	if ( 'profiles' === $scope && function_exists( 'gend_society_ps_default_header_id' ) ) {
		gend_society_ps_default_header_id();
	}

	$headers = array();
	foreach ( get_posts(
		array(
			'post_type'      => $sc['header_type'],
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	) as $p ) {
		$types     = 'groups' === $scope
			? ( get_post_meta( $p->ID, '_gdc_is_default_for_type', true ) ? array_filter( array( (string) get_post_meta( $p->ID, '_gdc_bp_group_type', true ) ) ) : array() )
			: array_filter( explode( ',', (string) get_post_meta( $p->ID, '_gdc_member_types', true ) ) );
		$headers[] = array(
			'id'          => $p->ID,
			'title'       => get_the_title( $p ),
			'siteDefault' => (bool) get_post_meta( $p->ID, '_gdc_is_site_default', true ),
			'types'       => array_values( $types ),
			'editUrl'     => gend_society_sp_edit_url( $p->ID ),
		);
	}

	$pages = array();
	foreach ( get_posts(
		array(
			'post_type'      => $sc['page_type'],
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			),
		)
	) as $p ) {
		$slug = (string) get_post_meta( $p->ID, $sc['slug_key'], true );
		if ( '' === $slug ) {
			continue;
		}
		$pages[ $slug ][] = gend_society_sp_page_row( $p, $scope );
	}
	$layouts = get_option( GEND_SOCIETY_SP_TAB_LAYOUTS, array() );

	$sidebars = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'gdc_bp_layout',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	) as $p ) {
		$lslug = (string) get_post_meta( $p->ID, '_gdc_layout_slug', true );
		if ( $lslug !== $sc['sidebar'] && 0 !== strpos( $lslug, $sc['sidebar'] . '-' ) ) {
			continue;
		}
		$sidebars[] = array(
			'id'        => $p->ID,
			'title'     => get_the_title( $p ),
			'isDefault' => $lslug === $sc['sidebar'],
			'status'    => $p->post_status,
			'editUrl'   => gend_society_sp_edit_url( $p->ID ),
		);
	}
	usort(
		$sidebars,
		function ( $a, $b ) {
			return (int) $b['isDefault'] - (int) $a['isDefault'];
		}
	);
	if ( ! $sidebars || ! $sidebars[0]['isDefault'] ) {
		// Not built yet: the default sidebar is today's widgets until it is.
		array_unshift(
			$sidebars,
			array(
				'id'        => 0,
				'title'     => __( 'Default sidebar', 'gend-society' ),
				'isDefault' => true,
				'status'    => 'none',
				'editUrl'   => '',
			)
		);
	}

	$dir    = function_exists( 'gend_society_ds_layout' ) ? gend_society_ds_layout( $sc['directory'] ) : null;
	$member = function_exists( 'gend_society_ps_member_type_options' ) ? gend_society_ps_member_type_options() : array();
	$gtypes = array();
	if ( 'groups' === $scope && function_exists( 'bp_groups_get_group_types' ) ) {
		foreach ( (array) bp_groups_get_group_types( array(), 'objects' ) as $slug => $obj ) {
			$gtypes[ $slug ] = isset( $obj->labels['singular_name'] ) ? $obj->labels['singular_name'] : $slug;
		}
	}
	$features = array();
	if ( 'groups' === $scope && function_exists( 'gdc_get_feature_catalog' ) ) {
		foreach ( (array) gdc_get_feature_catalog() as $k => $f ) {
			$slug              = is_array( $f ) && isset( $f['slug'] ) ? $f['slug'] : $k;
			$features[ $slug ] = is_array( $f ) && isset( $f['name'] ) ? wp_strip_all_tags( (string) $f['name'] ) : (string) $slug;
		}
	}
	$sample = '';
	if ( 'groups' === $scope && function_exists( 'groups_get_groups' ) ) {
		$g = groups_get_groups(
			array(
				'per_page'    => 1,
				'type'        => 'active',
				'show_hidden' => false,
			)
		);
		if ( ! empty( $g['groups'][0] ) && function_exists( 'bp_get_group_url' ) ) {
			$sample = bp_get_group_url( $g['groups'][0] );
		} elseif ( ! empty( $g['groups'][0] ) && function_exists( 'bp_get_group_permalink' ) ) {
			$sample = bp_get_group_permalink( $g['groups'][0] );
		}
	} elseif ( function_exists( 'bp_members_get_user_url' ) ) {
		$sample = bp_members_get_user_url( get_current_user_id() );
	} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
		$sample = bp_core_get_user_domain( get_current_user_id() );
	}

	return rest_ensure_response(
		array(
			'menu'       => gend_society_sp_menu( $scope ),
			'pages'      => (object) $pages,
			'tabLayouts' => isset( $layouts[ $scope ] ) && is_array( $layouts[ $scope ] ) ? (object) $layouts[ $scope ] : new stdClass(),
			'headers'    => $headers,
			'sidebars'   => $sidebars,
			'directory'  => array(
				'published' => (bool) $dir,
				'editUrl'   => admin_url( 'admin.php?page=gs-studio-layout&layout=' . $sc['directory'] . '&gs_studio_embed=1' ),
				'url'       => function_exists( 'bp_get_' . ( 'groups' === $scope ? 'groups' : 'members' ) . '_directory_permalink' ) ? call_user_func( 'bp_get_' . ( 'groups' === $scope ? 'groups' : 'members' ) . '_directory_permalink' ) : '',
			),
			'options'    => array(
				'roles'       => 'groups' === $scope
					? array(
						'admin'      => __( 'Group organizers', 'gend-society' ),
						'mod'        => __( 'Group moderators', 'gend-society' ),
						'member'     => __( 'Group members', 'gend-society' ),
						'non-member' => __( 'Signed-in non-members', 'gend-society' ),
					)
					: array(
						'owner'   => __( 'The profile’s owner', 'gend-society' ),
						'friend'  => __( 'Friends', 'gend-society' ),
						'visitor' => __( 'Other members', 'gend-society' ),
					),
				'memberTypes' => (object) $member,
				'groupTypes'  => (object) $gtypes,
				'features'    => (object) $features,
			),
			'sampleUrl'  => $sample,
		)
	);
}

/**
 * Builder actions.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_sp_rest_action( WP_REST_Request $req ) {
	$scope  = $req['scope'];
	$action = $req['action'];
	$body   = (array) $req->get_json_params();
	$sc     = gend_society_sp_scope( $scope );
	$slug   = isset( $body['slug'] ) ? sanitize_key( $body['slug'] ) : '';
	$id     = isset( $body['id'] ) ? (int) $body['id'] : 0;

	switch ( $action ) {
		case 'menu':
			return gend_society_sp_save_menu( $scope, isset( $body['menu'] ) && is_array( $body['menu'] ) ? $body['menu'] : array() );

		case 'tab-layout':
			$all = get_option( GEND_SOCIETY_SP_TAB_LAYOUTS, array() );
			$all = is_array( $all ) ? $all : array();
			if ( '' === $slug ) {
				return new WP_Error( 'gs_sp_slug', __( 'Pick a menu tab.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$all[ $scope ][ $slug ] = gend_society_sp_clean_layout( $body );
			update_option( GEND_SOCIETY_SP_TAB_LAYOUTS, $all, false );
			return rest_ensure_response( array( 'saved' => true ) );

		case 'page':
			if ( '' === $slug ) {
				return new WP_Error( 'gs_sp_slug', __( 'Pick a menu tab.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$default = gend_society_sp_default_page( $scope, $slug );
			$isdef   = ! empty( $body['asDefault'] ) && ! $default;
			$content = $default ? $default->post_content : ( 'profiles' === $scope ? (string) apply_filters( 'gend_society_ps_default_tab_content', '<!-- wp:gend-society/profile-tab-content /-->', $slug ) : '' );
			$new     = wp_insert_post(
				array(
					'post_type'    => $sc['page_type'],
					'post_status'  => 'publish',
					'post_title'   => isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ? sanitize_text_field( $body['title'] ) : $slug,
					'post_content' => wp_slash( $content ),
					'menu_order'   => count( gend_society_sp_audience_pages( $scope, $slug ) ) + 1,
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			update_post_meta( $new, $sc['slug_key'], $slug );
			if ( $isdef ) {
				update_post_meta( $new, '_gdc_is_site_default', '1' );
			} else {
				update_post_meta( $new, '_gs_audience', wp_slash( wp_json_encode( gend_society_sp_clean_audience( isset( $body['audience'] ) ? $body['audience'] : array() ) ) ) );
			}
			if ( $default ) {
				foreach ( array( '_gs_layout', '_gs_sidebar' ) as $k ) {
					$v = get_post_meta( $default->ID, $k, true );
					if ( '' !== $v ) {
						update_post_meta( $new, $k, $v );
					}
				}
			}
			return rest_ensure_response( gend_society_sp_page_row( get_post( $new ), $scope ) );

		case 'page-update':
			$p = gend_society_sp_owned( $id, $sc['page_type'] );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => sanitize_text_field( $body['title'] ),
					)
				);
			}
			if ( array_key_exists( 'audience', $body ) && ! get_post_meta( $id, '_gdc_is_site_default', true ) ) {
				update_post_meta( $id, '_gs_audience', wp_slash( wp_json_encode( gend_society_sp_clean_audience( $body['audience'] ) ) ) );
				delete_post_meta( $id, '_gdc_member_types' ); // Now part of the audience (profileTypes).
			}
			if ( array_key_exists( 'layout', $body ) || array_key_exists( 'sidebar', $body ) ) {
				$l = gend_society_sp_clean_layout( array_merge( gend_society_sp_layout_of( $scope, '', $p ), $body ) );
				update_post_meta( $id, '_gs_layout', $l['layout'] );
				update_post_meta( $id, '_gs_sidebar', $l['sidebar'] );
			}
			return rest_ensure_response( gend_society_sp_page_row( get_post( $id ), $scope ) );

		case 'page-order':
			$n = 1;
			foreach ( isset( $body['ids'] ) && is_array( $body['ids'] ) ? $body['ids'] : array() as $pid ) {
				$p = gend_society_sp_owned( (int) $pid, $sc['page_type'] );
				if ( ! is_wp_error( $p ) ) {
					wp_update_post(
						array(
							'ID'         => $p->ID,
							'menu_order' => $n++,
						)
					);
				}
			}
			return rest_ensure_response( array( 'saved' => true ) );

		case 'page-delete':
			$p = gend_society_sp_owned( $id, $sc['page_type'] );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( get_post_meta( $id, '_gdc_is_site_default', true ) ) {
				return new WP_Error( 'gs_sp_default', __( 'The default page of a tab cannot be deleted.', 'gend-society' ), array( 'status' => 400 ) );
			}
			wp_trash_post( $id );
			return rest_ensure_response( array( 'deleted' => true ) );

		case 'sidebar':
			$from    = function_exists( 'gend_society_ds_layout' ) ? gend_society_ds_layout( $sc['sidebar'] ) : null;
			$defs    = ! $from && function_exists( 'gend_society_ds_layouts' ) ? gend_society_ds_layouts() : array();
			$content = $from ? $from->post_content : ( isset( $defs[ $sc['sidebar'] ]['content'] ) ? (string) $defs[ $sc['sidebar'] ]['content'] : '' );
			$new     = wp_insert_post(
				array(
					'post_type'    => 'gdc_bp_layout',
					'post_status'  => 'publish',
					'post_title'   => isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ? sanitize_text_field( $body['title'] ) : __( 'New sidebar', 'gend-society' ),
					'post_content' => wp_slash( $content ),
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			update_post_meta( $new, '_gdc_layout_slug', $sc['sidebar'] . '-' . $new );
			return rest_ensure_response(
				array(
					'id'      => $new,
					'editUrl' => gend_society_sp_edit_url( $new ),
				)
			);

		case 'sidebar-default':
		case 'directory':
			$lid = function_exists( 'gend_society_ds_layout_id' ) ? gend_society_ds_layout_id( 'directory' === $action ? $sc['directory'] : $sc['sidebar'] ) : 0;
			if ( ! $lid ) {
				return new WP_Error( 'gs_sp_layout', __( 'This layout is not available.', 'gend-society' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response(
				array(
					'id'      => $lid,
					'editUrl' => gend_society_sp_edit_url( $lid ),
				)
			);

		case 'sidebar-update':
		case 'sidebar-delete':
			$p = get_post( $id );
			if ( ! $p || 'gdc_bp_layout' !== $p->post_type || 0 !== strpos( (string) get_post_meta( $id, '_gdc_layout_slug', true ), $sc['sidebar'] ) ) {
				return new WP_Error( 'gs_sp_id', __( 'Sidebar not found.', 'gend-society' ), array( 'status' => 404 ) );
			}
			if ( 'sidebar-delete' === $action ) {
				if ( get_post_meta( $id, '_gdc_layout_slug', true ) === $sc['sidebar'] ) {
					return new WP_Error( 'gs_sp_default', __( 'The default sidebar cannot be deleted.', 'gend-society' ), array( 'status' => 400 ) );
				}
				wp_trash_post( $id );
				return rest_ensure_response( array( 'deleted' => true ) );
			}
			$up = array( 'ID' => $id );
			if ( isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ) {
				$up['post_title'] = sanitize_text_field( $body['title'] );
			}
			if ( ! empty( $body['publish'] ) ) {
				$up['post_status'] = 'publish';
			}
			wp_update_post( $up );
			return rest_ensure_response( array( 'saved' => true ) );

		case 'header':
			if ( 'profiles' === $scope ) {
				$r = new WP_REST_Request( 'POST', '/gend-society/v1/profile-styling/header' );
				$r->set_header( 'content-type', 'application/json' );
				$r->set_body( wp_json_encode( array( 'title' => isset( $body['title'] ) ? $body['title'] : '' ) ) );
				$res = rest_do_request( $r );
				return $res->is_error() ? $res->as_error() : rest_ensure_response( $res->get_data() );
			}
			$default = function_exists( 'gdc_gs_get_site_default_header_id' ) ? (int) gdc_gs_get_site_default_header_id() : 0;
			$new     = wp_insert_post(
				array(
					'post_type'    => 'gdc_group_header',
					'post_status'  => 'publish',
					'post_title'   => isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ? sanitize_text_field( $body['title'] ) : __( 'New group header', 'gend-society' ),
					'post_content' => $default ? wp_slash( get_post_field( 'post_content', $default ) ) : '',
				),
				true
			);
			return is_wp_error( $new ) ? $new : rest_ensure_response( array( 'id' => $new ) );

		case 'header-default':
			$p = gend_society_sp_owned( $id, $sc['header_type'] );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			foreach ( get_posts(
				array(
					'post_type'      => $sc['header_type'],
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			) as $oid ) {
				if ( (int) $oid !== $id ) {
					delete_post_meta( $oid, '_gdc_is_site_default' );
				}
			}
			update_post_meta( $id, '_gdc_is_site_default', '1' );
			return rest_ensure_response( array( 'saved' => true ) );

	}
	return new WP_Error( 'gs_sp_action', __( 'Unknown action.', 'gend-society' ), array( 'status' => 400 ) );
}

/**
 * A post of the expected type.
 *
 * @param int    $id   Post ID.
 * @param string $type Post type.
 * @return WP_Post|WP_Error
 */
function gend_society_sp_owned( $id, $type ) {
	$p = $id ? get_post( $id ) : null;
	if ( ! $p || $p->post_type !== $type || 'trash' === $p->post_status ) {
		return new WP_Error( 'gs_sp_id', __( 'Page not found.', 'gend-society' ), array( 'status' => 404 ) );
	}
	return $p;
}

/**
 * Clean layout settings.
 *
 * @param array $in Layout + sidebar.
 * @return array
 */
function gend_society_sp_clean_layout( $in ) {
	$layout = isset( $in['layout'] ) ? (string) $in['layout'] : '';
	return array(
		'layout'  => in_array( $layout, array( 'full', 'left', 'right' ), true ) ? $layout : '',
		'sidebar' => isset( $in['sidebar'] ) ? absint( $in['sidebar'] ) : 0,
	);
}

/**
 * A tab's default page.
 *
 * @param string $scope groups|profiles.
 * @param string $slug  Tab slug.
 * @return WP_Post|null
 */
function gend_society_sp_default_page( $scope, $slug ) {
	$sc  = gend_society_sp_scope( $scope );
	$ids = get_posts(
		array(
			'post_type'      => $sc['page_type'],
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one admin lookup.
			'meta_query'     => array(
				array(
					'key'   => $sc['slug_key'],
					'value' => $slug,
				),
				array(
					'key'   => '_gdc_is_site_default',
					'value' => '1',
				),
			),
		)
	);
	return $ids ? get_post( (int) $ids[0] ) : null;
}

/**
 * Saves the menu (labels, order, hidden; custom group tabs with their slugs).
 *
 * @param string $scope groups|profiles.
 * @param array  $menu  Rows: slug, label, hidden, custom, oldSlug.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_sp_save_menu( $scope, $menu ) {
	if ( 'profiles' === $scope ) {
		$clean = array();
		$n     = 10;
		foreach ( $menu as $row ) {
			$slug = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$clean[ $slug ] = array(
				'label'  => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'order'  => $n,
				'hidden' => ! empty( $row['hidden'] ),
			);
			$n             += 10;
		}
		update_option( defined( 'GEND_SOCIETY_PS_NAV_OPTION' ) ? GEND_SOCIETY_PS_NAV_OPTION : 'gend_society_profile_nav', $clean, false );
		return rest_ensure_response( array( 'menu' => gend_society_sp_menu( $scope ) ) );
	}

	if ( ! function_exists( 'gdc_gs_save_nav_config_scope' ) || ! function_exists( 'gdc_gs_validate_custom_tab_slug' ) ) {
		return new WP_Error( 'gs_sp_nav', __( 'Group menus need the Projects app.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$config  = array(
		'built_in' => array(),
		'custom'   => array(),
	);
	$renames = array();
	$n       = 10;
	foreach ( $menu as $row ) {
		$slug = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
		if ( '' === $slug ) {
			continue;
		}
		$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
		if ( ! empty( $row['custom'] ) ) {
			$valid = gdc_gs_validate_custom_tab_slug( $slug, '_default', $config['custom'] );
			$old   = isset( $row['oldSlug'] ) ? sanitize_key( $row['oldSlug'] ) : '';
			// A slug that stays the same is its own tab (already an endpoint), so skip the collision check for it.
			if ( true !== $valid && $old !== $slug ) {
				/* translators: 1: tab slug, 2: why it cannot be used. */
				return new WP_Error( 'gs_sp_slug', sprintf( __( '“%1$s”: %2$s', 'gend-society' ), $slug, $valid ), array( 'status' => 400 ) );
			}
			if ( $old && $old !== $slug ) {
				$renames[ $old ] = $slug;
			}
			$config['custom'][] = array(
				'slug'     => $slug,
				'label'    => '' !== $label ? $label : $slug,
				'position' => $n,
				'hidden'   => ! empty( $row['hidden'] ),
			);
		} else {
			$config['built_in'][ $slug ] = array(
				'hidden'   => ! empty( $row['hidden'] ),
				'label'    => '' !== $label ? $label : null,
				'position' => $n,
			);
		}
		$n += 10;
	}
	gdc_gs_save_nav_config_scope( '_default', $config );
	// Pages follow a renamed custom tab.
	foreach ( $renames as $old => $new ) {
		foreach ( get_posts(
			array(
				'post_type'      => 'gdc_group_page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin rename, rare.
				'meta_key'       => '_gdc_endpoint_slug',
				'meta_value'     => $old, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $pid ) {
			update_post_meta( $pid, '_gdc_endpoint_slug', $new );
		}
	}
	// A new custom tab gets its default page, like the built-in tabs.
	foreach ( $config['custom'] as $c ) {
		if ( ! gend_society_sp_default_page( 'groups', $c['slug'] ) ) {
			$pid = wp_insert_post(
				array(
					'post_type'   => 'gdc_group_page',
					'post_status' => 'publish',
					/* translators: %s: tab name. */
					'post_title'  => sprintf( __( 'Default — %s', 'gend-society' ), $c['label'] ),
				),
				true
			);
			if ( ! is_wp_error( $pid ) ) {
				update_post_meta( $pid, '_gdc_endpoint_slug', $c['slug'] );
				update_post_meta( $pid, '_gdc_is_site_default', '1' );
			}
		}
	}
	return rest_ensure_response( array( 'menu' => gend_society_sp_menu( $scope ) ) );
}
