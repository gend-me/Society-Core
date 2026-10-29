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
		'/social-pages/(?P<scope>groups|profiles)/(?P<action>menu|tab-layout|page|page-update|page-order|page-delete|sidebar|sidebar-default|sidebar-update|sidebar-delete|header|header-default|directory|photos|card-stat|not-found|privacy|menu-icon)',
		array(
			'methods'             => 'POST',
			'callback'            => 'gend_society_sp_rest_action',
			'permission_callback' => $perm,
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/social-pages/activity',
		array(
			'methods'             => 'GET',
			'callback'            => 'gend_society_sp_activity_state',
			'permission_callback' => $perm,
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/social-pages/activity/(?P<action>feed|reaction-setting|emoji|emoji-delete|emoji-order|wall)',
		array(
			'methods'             => 'POST',
			'callback'            => 'gend_society_sp_activity_action',
			'permission_callback' => $perm,
		)
	);
}

/* ── Activity feed: layout + reactions ──────────────────────────────────── */

/**
 * Reactions are the Activity Reactions extension's own options (the same ones
 * its Youzify settings tab edits): switches and texts as Youzify options, the
 * emoji list in yz_reaction_emojis with a visible-only copy and a next-ID counter.
 *
 * @return bool
 */
function gend_society_sp_has_reactions() {
	return function_exists( 'yzar_option' );
}

/**
 * Stores a reactions setting where the extension reads it.
 *
 * @param string $key   Option.
 * @param mixed  $value Value.
 */
function gend_society_sp_reaction_update( $key, $value ) {
	if ( function_exists( 'gend_sn_update_option' ) ) {
		gend_sn_update_option( $key, $value );
	} else {
		update_option( $key, $value );
	}
}

/**
 * Saves the emoji list (and the copy of visible ones the front end uses).
 *
 * @param array $emojis Key => emoji.
 */
function gend_society_sp_save_emojis( $emojis ) {
	update_option( 'yz_reaction_emojis', $emojis );
	update_option(
		'yz_reaction_emojis_list',
		array_filter(
			$emojis,
			function ( $e ) {
				return isset( $e['visibility'] ) && 'true' === $e['visibility'];
			}
		)
	);
}

/**
 * Activity builder state.
 *
 * @return WP_REST_Response
 */
function gend_society_sp_activity_state() {
	$layout    = function_exists( 'gend_society_ds_layout' ) ? gend_society_ds_layout( 'activity' ) : null;
	$reactions = null;
	if ( gend_society_sp_has_reactions() ) {
		$emojis = get_option( 'yz_reaction_emojis', array() );
		$list   = array();
		foreach ( is_array( $emojis ) ? $emojis : array() as $key => $e ) {
			$list[] = array(
				'id'          => (string) $key,
				'title'       => isset( $e['title'] ) ? (string) $e['title'] : '',
				'description' => isset( $e['description'] ) ? (string) $e['description'] : '',
				'image'       => isset( $e['image'] ) ? (string) $e['image'] : '',
				'visible'     => isset( $e['visibility'] ) && 'true' === $e['visibility'],
			);
		}
		$first     = $list ? $list[0]['id'] : '';
		$reactions = array(
			'posts'    => 'off' !== yzar_option( 'yz_enable_posts_reaction', 'on' ),
			'comments' => 'off' !== yzar_option( 'yz_enable_comments_reaction', 'on' ),
			'title'    => (string) yzar_option( 'yz_default_like_button_title', __( 'Like', 'gend-society' ) ),
			'favorite' => (string) ( yzar_option( 'yz_favorite_reaction_id' ) ? yzar_option( 'yz_favorite_reaction_id' ) : $first ),
			'like'     => (string) yzar_option( 'yz_like_reaction_id', 'yz_emoji_1' ),
			'emojis'   => $list,
		);
	}
	return rest_ensure_response(
		array(
			'feed'      => array(
				'published' => (bool) $layout,
				'url'       => function_exists( 'bp_get_activity_directory_permalink' ) ? bp_get_activity_directory_permalink() : '',
			),
			'reactions' => $reactions,
			'wall'      => gend_society_sp_wall_state(),
		)
	);
}

/**
 * Activity builder actions.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_sp_activity_action( WP_REST_Request $req ) {
	$action = $req['action'];
	$body   = (array) $req->get_json_params();
	if ( 'wall' === $action ) {
		$saved = gend_society_sp_wall_save( isset( $body['key'] ) ? (string) $body['key'] : '', isset( $body['value'] ) ? $body['value'] : null );
		return is_wp_error( $saved ) ? $saved : gend_society_sp_activity_state();
	}
	if ( 'feed' === $action ) {
		$id = function_exists( 'gend_society_ds_layout_id' ) ? gend_society_ds_layout_id( 'activity' ) : 0;
		if ( ! $id ) {
			return new WP_Error( 'gs_sp_feed', __( 'The feed layout could not be opened.', 'gend-society' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'editUrl' => gend_society_sp_edit_url( $id ) ) );
	}
	if ( ! gend_society_sp_has_reactions() ) {
		return new WP_Error( 'gs_sp_reactions', __( 'Activity Reactions is not active.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$emojis = get_option( 'yz_reaction_emojis', array() );
	$emojis = is_array( $emojis ) ? $emojis : array();

	switch ( $action ) {
		case 'reaction-setting':
			$key = isset( $body['key'] ) ? (string) $body['key'] : '';
			$val = isset( $body['value'] ) ? $body['value'] : '';
			if ( 'posts' === $key || 'comments' === $key ) {
				gend_society_sp_reaction_update( 'posts' === $key ? 'yz_enable_posts_reaction' : 'yz_enable_comments_reaction', $val ? 'on' : 'off' );
			} elseif ( 'title' === $key ) {
				$t = sanitize_text_field( (string) $val );
				gend_society_sp_reaction_update( 'yz_default_like_button_title', '' !== $t ? $t : __( 'Like', 'gend-society' ) );
			} elseif ( 'favorite' === $key || 'like' === $key ) {
				if ( ! isset( $emojis[ (string) $val ] ) ) {
					return new WP_Error( 'gs_sp_reactions', __( 'Unknown reaction.', 'gend-society' ), array( 'status' => 400 ) );
				}
				gend_society_sp_reaction_update( 'favorite' === $key ? 'yz_favorite_reaction_id' : 'yz_like_reaction_id', (string) $val );
			} else {
				return new WP_Error( 'gs_sp_reactions', __( 'Unknown setting.', 'gend-society' ), array( 'status' => 400 ) );
			}
			break;

		case 'emoji':
			$id    = isset( $body['id'] ) ? sanitize_key( $body['id'] ) : '';
			$title = isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '';
			$image = isset( $body['image'] ) ? esc_url_raw( trim( (string) $body['image'] ) ) : '';
			if ( '' === $title || '' === $image ) {
				return new WP_Error( 'gs_sp_reactions', __( 'An emoji needs a title and an image.', 'gend-society' ), array( 'status' => 400 ) );
			}
			if ( '' === $id ) {
				$next = max( 1, (int) get_option( 'yz_next_emoji_nbr', 1 ) );
				while ( isset( $emojis[ 'yz_emoji_' . $next ] ) ) {
					++$next;
				}
				$id = 'yz_emoji_' . $next;
				update_option( 'yz_next_emoji_nbr', $next + 1 );
			} elseif ( ! isset( $emojis[ $id ] ) ) {
				return new WP_Error( 'gs_sp_reactions', __( 'Unknown reaction.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$emojis[ $id ] = array(
				'visibility'  => empty( $body['visible'] ) ? 'false' : 'true',
				'image'       => $image,
				'title'       => $title,
				'description' => isset( $body['description'] ) ? sanitize_text_field( (string) $body['description'] ) : '',
			);
			gend_society_sp_save_emojis( $emojis );
			break;

		case 'emoji-delete':
			$id = isset( $body['id'] ) ? sanitize_key( $body['id'] ) : '';
			if ( ! isset( $emojis[ $id ] ) ) {
				return new WP_Error( 'gs_sp_reactions', __( 'Unknown reaction.', 'gend-society' ), array( 'status' => 400 ) );
			}
			if ( count( $emojis ) < 2 ) {
				return new WP_Error( 'gs_sp_reactions', __( 'Keep at least one reaction.', 'gend-society' ), array( 'status' => 400 ) );
			}
			if ( yzar_option( 'yz_like_reaction_id', 'yz_emoji_1' ) === $id || yzar_option( 'yz_favorite_reaction_id' ) === $id ) {
				return new WP_Error( 'gs_sp_reactions', __( 'This reaction is used by the like button or the Favorites tab. Pick another one there first.', 'gend-society' ), array( 'status' => 400 ) );
			}
			unset( $emojis[ $id ] );
			gend_society_sp_save_emojis( $emojis );
			break;

		case 'emoji-order':
			$ids = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_map( 'sanitize_key', $body['ids'] ) : array();
			$out = array();
			foreach ( $ids as $id ) {
				if ( isset( $emojis[ $id ] ) ) {
					$out[ $id ] = $emojis[ $id ];
				}
			}
			$out += $emojis; // Anything not listed keeps its place at the end.
			gend_society_sp_save_emojis( $out );
			break;
	}
	return gend_society_sp_activity_state();
}

/* ── Activity wall settings (Member Management › Activity Wall) ─────────── */

/**
 * Youzify's admin helpers for these settings (post types, countries) load only
 * in wp-admin; the studio talks to REST, so load them here.
 */
function gend_society_sp_wall_load() {
	// social-network's community API (gend_sn_activity_post_types(),
	// gend_sn_alpha2_countries()) provides these everywhere.
	if ( function_exists( 'gend_sn_activity_post_types' ) || ! defined( 'YOUZIFY_ADMIN_CORE' ) ) {
		return;
	}
	$dir = trailingslashit( YOUZIFY_ADMIN_CORE );
	if ( ! function_exists( 'gend_sn_activity_post_types' ) && file_exists( $dir . 'functions/youzify-general-functions.php' ) ) {
		require_once $dir . 'functions/youzify-general-functions.php';
	}
	if ( ! function_exists( 'gend_sn_alpha2_countries' ) && file_exists( $dir . 'general-settings/youzify-settings-wall.php' ) ) {
		require_once $dir . 'general-settings/youzify-settings-wall.php';
	}
}

/**
 * The Activity Wall tab's settings, grouped as it groups them: Youzify
 * options, read with their defaults and saved the way its panel saves them.
 * Field types: switch (on/off), number, select, tags (list of words),
 * countries, layout, secret (a key that is never sent to the page).
 *
 * @return array[]
 */
function gend_society_sp_wall_schema() {
	$sw  = static function ( $key, $title, $desc ) {
		return array( 'key' => $key, 'type' => 'switch', 'title' => $title, 'desc' => $desc );
	};
	$num = static function ( $key, $title, $desc, $min = 0, $max = 1000 ) {
		return array( 'key' => $key, 'type' => 'number', 'title' => $title, 'desc' => $desc, 'min' => $min, 'max' => $max );
	};
	$tag = static function ( $key, $title, $desc ) {
		return array( 'key' => $key, 'type' => 'tags', 'title' => $title, 'desc' => $desc );
	};
	gend_society_sp_wall_load();
	$types = function_exists( 'gend_sn_activity_post_types' ) ? gend_sn_activity_post_types() : array();
	$posts = array();
	foreach ( $types as $type => $name ) {
		$posts[] = array( 'key' => 'post_type:' . $type, 'type' => 'switch', 'title' => wp_strip_all_tags( $name ), 'desc' => '' );
	}
	$groups = array(
		array( 'id' => 'general', 'icon' => 'admin-settings', 'title' => __( 'General', 'gend-society' ), 'desc' => __( 'How the wall loads and previews links.', 'gend-society' ), 'fields' => array(
			$sw( 'youzify_enable_wall_url_preview', __( 'Link previews', 'gend-society' ), __( 'Show a preview of links typed into the post form.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_activity_loader', __( 'Infinite loading', 'gend-society' ), __( 'Load more posts as members scroll.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_activity_effects', __( 'Loading effect', 'gend-society' ), __( 'Fade posts in as they load.', 'gend-society' ) ),
			array( 'key' => 'youzify_activity_stream_layout', 'type' => 'layout', 'title' => __( 'Sidebar side', 'gend-society' ), 'desc' => __( 'Where the sidebar sits on the activity page (when the feed layout above isn’t designed yet).', 'gend-society' ), 'opts' => array( 'youzify-wall-left-sidebar' => __( 'Left', 'gend-society' ), 'youzify-wall-right-sidebar' => __( 'Right', 'gend-society' ) ) ),
		) ),
		array( 'id' => 'form', 'icon' => 'edit', 'title' => __( 'Posting form', 'gend-society' ), 'desc' => __( 'What members can add to a post.', 'gend-society' ), 'fields' => array(
			$sw( 'youzify_activity_privacy', __( 'Privacy', 'gend-society' ), __( 'Members choose who sees each post.', 'gend-society' ) ),
			$sw( 'youzify_activity_mood', __( 'Feeling / activity', 'gend-society' ), __( 'Members add how they feel or what they’re doing.', 'gend-society' ) ),
			$sw( 'youzify_activity_tag_friends', __( 'Tag friends', 'gend-society' ), __( 'Members tag friends in posts.', 'gend-society' ) ),
			$sw( 'youzify_activity_checkin', __( 'Check in', 'gend-society' ), __( 'Members add a location.', 'gend-society' ) ),
		) ),
		array( 'id' => 'buttons', 'icon' => 'thumbs-up', 'title' => __( 'Post buttons', 'gend-society' ), 'desc' => __( 'The buttons under each post and comment.', 'gend-society' ), 'fields' => array(
			$sw( 'youzify_enable_wall_posts_likes', __( 'Likes', 'gend-society' ), __( 'Members like posts.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_posts_comments', __( 'Comments', 'gend-society' ), __( 'Members comment on posts.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_posts_reply', __( 'Replies', 'gend-society' ), __( 'Members reply to comments.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_posts_shares', __( 'Shares', 'gend-society' ), __( 'Members share posts to their own wall.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_posts_deletion', __( 'Delete button', 'gend-society' ), __( 'Members delete their own posts.', 'gend-society' ) ),
			$sw( 'youzify_wall_comments_gif', __( 'GIFs in comments', 'gend-society' ), __( 'Members add GIFs to comments.', 'gend-society' ) ),
		) ),
		array( 'id' => 'display', 'icon' => 'filter', 'title' => __( 'Filters, embeds and pinned posts', 'gend-society' ), 'desc' => __( 'Filter bars, embedded media and posts admins pin to the top.', 'gend-society' ), 'fields' => array(
			$sw( 'youzify_enable_wall_filter_bar', __( 'Profile activity filter', 'gend-society' ), __( 'Filter bar on each profile’s activity.', 'gend-society' ) ),
			$sw( 'youzify_enable_activity_directory_filter_bar', __( 'Activity page filter', 'gend-society' ), __( 'Filter bar on the activity page.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_posts_embeds', __( 'Embeds in posts', 'gend-society' ), __( 'Videos and links play inside posts.', 'gend-society' ) ),
			$sw( 'youzify_enable_wall_comments_embeds', __( 'Embeds in comments', 'gend-society' ), __( 'Videos and links play inside comments.', 'gend-society' ) ),
			$sw( 'youzify_enable_activity_sticky_posts', __( 'Pinned posts on the activity page', 'gend-society' ), __( 'Admins pin or unpin posts.', 'gend-society' ) ),
			$sw( 'youzify_enable_groups_sticky_posts', __( 'Pinned posts in groups', 'gend-society' ), __( 'Group admins pin or unpin posts.', 'gend-society' ) ),
		) ),
		array( 'id' => 'paging', 'icon' => 'list-view', 'title' => __( 'Posts per page', 'gend-society' ), 'desc' => __( 'How many posts load at a time.', 'gend-society' ), 'fields' => array(
			$num( 'youzify_activity_wall_posts_per_page', __( 'Activity page', 'gend-society' ), __( 'The site-wide activity page.', 'gend-society' ), 1, 500 ),
			$num( 'youzify_profile_wall_posts_per_page', __( 'Profiles', 'gend-society' ), __( 'Each member’s activity.', 'gend-society' ), 1, 500 ),
			$num( 'youzify_groups_wall_posts_per_page', __( 'Groups', 'gend-society' ), __( 'Each group’s activity.', 'gend-society' ), 1, 500 ),
		) ),
		array( 'id' => 'attachments', 'icon' => 'media-document', 'title' => __( 'Attachments', 'gend-society' ), 'desc' => __( 'Files members attach to posts and comments.', 'gend-society' ), 'fields' => array(
			$num( 'youzify_attachments_max_nbr', __( 'Photos per post', 'gend-society' ), __( 'Most photos in one photo or slideshow post.', 'gend-society' ), 1, 1000 ),
			$num( 'youzify_attachments_max_size', __( 'Post file size (MB)', 'gend-society' ), __( 'Largest file a post can carry.', 'gend-society' ), 1, 1024 ),
			$tag( 'youzify_atts_allowed_images_exts', __( 'Image types', 'gend-society' ), __( 'File extensions allowed for images.', 'gend-society' ) ),
			$tag( 'youzify_atts_allowed_videos_exts', __( 'Video types', 'gend-society' ), __( 'File extensions allowed for videos.', 'gend-society' ) ),
			$tag( 'youzify_atts_allowed_audios_exts', __( 'Audio types', 'gend-society' ), __( 'File extensions allowed for audio.', 'gend-society' ) ),
			$tag( 'youzify_atts_allowed_files_exts', __( 'File types', 'gend-society' ), __( 'File extensions allowed for other files.', 'gend-society' ) ),
			$sw( 'youzify_wall_comments_attachments', __( 'Attachments in comments', 'gend-society' ), __( 'Members attach files to comments.', 'gend-society' ) ),
			$num( 'youzify_wall_comments_attachments_max_size', __( 'Comment file size (MB)', 'gend-society' ), __( 'Largest file a comment can carry.', 'gend-society' ), 1, 1024 ),
			$tag( 'youzify_wall_comments_attachments_extensions', __( 'Comment file types', 'gend-society' ), __( 'File extensions allowed in comments.', 'gend-society' ) ),
		) ),
		array( 'id' => 'post-types', 'icon' => 'visibility', 'title' => __( 'Post types on the wall', 'gend-society' ), 'desc' => __( 'Which kinds of activity show up. Turning one off hides those posts.', 'gend-society' ), 'fields' => $posts ),
		array( 'id' => 'moderation', 'icon' => 'shield', 'title' => __( 'Moderation', 'gend-society' ), 'desc' => __( 'Words that can’t be used in posts or comments.', 'gend-society' ), 'fields' => array(
			$tag( 'youzify_moderation_keys', __( 'Forbidden words', 'gend-society' ), __( 'Posts and comments with these words are refused.', 'gend-society' ) ),
		) ),
		array( 'id' => 'polls', 'icon' => 'chart-bar', 'title' => __( 'Polls', 'gend-society' ), 'desc' => __( 'Poll posts: how they are made and voted on.', 'gend-society' ), 'fields' => array(
			$sw( 'yzap_poll_multi_options', __( 'Single or multiple choice', 'gend-society' ), __( 'Members choose whether a poll allows several answers.', 'gend-society' ) ),
			array( 'key' => 'yzap_poll_options_selection', 'type' => 'select', 'title' => __( 'Default voting', 'gend-society' ), 'desc' => __( 'How a new poll votes unless changed.', 'gend-society' ), 'opts' => array( 'single' => __( 'One answer', 'gend-society' ), 'multi' => __( 'Several answers', 'gend-society' ) ) ),
			$num( 'yzap_poll_options_limit', __( 'Most answers', 'gend-society' ), __( 'Answers a poll can have.', 'gend-society' ), 2, 100 ),
			$sw( 'yzap_poll_options_image_enable', __( 'Answer images', 'gend-society' ), __( 'Members add an image to each answer.', 'gend-society' ) ),
			$sw( 'yzap_poll_options_image', __( 'Require answer images', 'gend-society' ), __( 'Every answer must have an image.', 'gend-society' ) ),
			$sw( 'yzap_poll_options_result', __( 'Show results', 'gend-society' ), __( 'Members see the results.', 'gend-society' ) ),
			$sw( 'yzap_poll_revote', __( 'Change vote', 'gend-society' ), __( 'Members change their vote after voting.', 'gend-society' ) ),
			$sw( 'yzap_poll_list_voters', __( 'Show voters', 'gend-society' ), __( 'Members see who voted.', 'gend-society' ) ),
			$num( 'yzap_poll_limit_voters', __( 'Voters shown', 'gend-society' ), __( 'Most voters listed per answer.', 'gend-society' ), 1, 1000 ),
			array( 'key' => 'yzap_poll_options_redirection', 'type' => 'select', 'title' => __( 'Poll opens on', 'gend-society' ), 'desc' => __( 'What a poll post shows first.', 'gend-society' ), 'opts' => array( 'poll' => __( 'The answers', 'gend-society' ), 'result' => __( 'The results', 'gend-society' ) ) ),
		) ),
		array( 'id' => 'checkin', 'icon' => 'location', 'title' => __( 'Check in', 'gend-society' ), 'desc' => __( 'Locations members add to posts (uses Google Maps).', 'gend-society' ), 'fields' => array(
			array( 'key' => 'youzify_google_map_api_key', 'type' => 'secret', 'title' => __( 'Google Maps API key', 'gend-society' ), 'desc' => __( 'Needed for check-ins. The saved key is never shown here.', 'gend-society' ) ),
			array( 'key' => 'youzify_google_map_allowed_countries', 'type' => 'countries', 'title' => __( 'Countries', 'gend-society' ), 'desc' => __( 'Locations members can pick from. Leave empty for every country.', 'gend-society' ) ),
		) ),
		array( 'id' => 'emoji', 'icon' => 'smiley', 'title' => __( 'Emoji', 'gend-society' ), 'desc' => __( 'Where members can add emoji.', 'gend-society' ), 'fields' => array(
			$sw( 'youzify_enable_posts_emoji', __( 'In posts', 'gend-society' ), __( 'Emoji picker in the post form.', 'gend-society' ) ),
			$sw( 'youzify_enable_comments_emoji', __( 'In comments', 'gend-society' ), __( 'Emoji picker in comments.', 'gend-society' ) ),
			$sw( 'youzify_enable_messages_emoji', __( 'In messages', 'gend-society' ), __( 'Emoji picker in private messages.', 'gend-society' ) ),
		) ),
	);
	return $groups;
}

/**
 * Activity types turned off on the wall (Youzify keeps "off" ones).
 *
 * @return string[]
 */
function gend_society_sp_wall_unallowed() {
	$off = gend_sn_option( 'youzify_unallowed_activities', array() );
	$off = is_array( $off ) ? $off : array();
	if ( in_array( 'friendship_accepted,friendship_created', $off, true ) ) {
		$off[] = 'friendship_accepted';
		$off[] = 'friendship_created';
	}
	return $off;
}

/**
 * Settings with their current values (null when Youzify is not active).
 *
 * @return array|null
 */
function gend_society_sp_wall_state() {
	if ( ! function_exists( 'gend_sn_options' ) || ! function_exists( 'gend_sn_update_option' ) || ! current_user_can( 'manage_options' ) ) {
		return null;
	}
	$off    = gend_society_sp_wall_unallowed();
	$groups = gend_society_sp_wall_schema();
	foreach ( $groups as &$group ) {
		foreach ( $group['fields'] as &$f ) {
			if ( 0 === strpos( $f['key'], 'post_type:' ) ) {
				$f['value'] = ! in_array( substr( $f['key'], 10 ), $off, true );
				continue;
			}
			$v = gend_sn_options( $f['key'] );
			switch ( $f['type'] ) {
				case 'switch':
					$f['value'] = 'on' === $v;
					break;
				case 'number':
					$f['value'] = (int) $v;
					break;
				case 'tags':
				case 'countries':
					$f['value'] = array_values( array_map( 'strval', is_array( $v ) ? $v : array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) ) );
					break;
				case 'secret':
					$f['value'] = '' !== trim( (string) $v );
					break;
				default:
					$f['value'] = (string) $v;
			}
		}
		unset( $f );
	}
	unset( $group );
	return array(
		'groups'    => $groups,
		'countries' => function_exists( 'gend_sn_alpha2_countries' ) ? gend_sn_alpha2_countries() : array(),
	);
}

/**
 * Saves one wall setting where Youzify reads it.
 *
 * @param string $key   Setting key from gend_society_sp_wall_schema().
 * @param mixed  $value New value.
 * @return true|WP_Error
 */
function gend_society_sp_wall_save( $key, $value ) {
	if ( ! function_exists( 'gend_sn_options' ) || ! function_exists( 'gend_sn_update_option' ) ) {
		return new WP_Error( 'gs_sp_wall', __( 'The Social Network must be active to change these settings.', 'gend-society' ), array( 'status' => 400 ) );
	}
	// The same check as the Activity Wall tab itself.
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'gs_sp_wall', __( 'You can’t change these settings.', 'gend-society' ), array( 'status' => 403 ) );
	}
	$field = null;
	foreach ( gend_society_sp_wall_schema() as $group ) {
		foreach ( $group['fields'] as $f ) {
			if ( $f['key'] === $key ) {
				$field = $f;
			}
		}
	}
	if ( ! $field ) {
		return new WP_Error( 'gs_sp_wall', __( 'Unknown setting.', 'gend-society' ), array( 'status' => 400 ) );
	}

	// Post types: the list of the ones turned off (a status also covers plain updates).
	if ( 0 === strpos( $key, 'post_type:' ) ) {
		$type = substr( $key, 10 );
		$off  = array_values( array_diff( gend_society_sp_wall_unallowed(), array( 'friendship_accepted,friendship_created' ) ) );
		$drop = 'activity_status' === $type ? array( 'activity_status', 'activity_update' ) : array( $type );
		$off  = array_values( array_diff( $off, $drop ) );
		if ( ! $value ) {
			$off = array_merge( $off, $drop );
		}
		$off = array_values( array_unique( $off ) );
		if ( $off ) {
			gend_sn_update_option( 'youzify_unallowed_activities', $off );
		} else {
			gend_sn_delete_option( 'youzify_unallowed_activities' );
		}
		return true;
	}

	switch ( $field['type'] ) {
		case 'switch':
			$v = $value ? 'on' : 'off';
			break;
		case 'number':
			$v = (string) max( (int) $field['min'], min( (int) $field['max'], (int) $value ) );
			break;
		case 'select':
		case 'layout':
			$v = (string) $value;
			if ( ! isset( $field['opts'][ $v ] ) ) {
				return new WP_Error( 'gs_sp_wall', __( 'Unknown option.', 'gend-society' ), array( 'status' => 400 ) );
			}
			break;
		case 'tags':
			$v = array();
			foreach ( is_array( $value ) ? $value : array() as $word ) {
				$word = sanitize_text_field( (string) $word );
				if ( '' !== $word && ! in_array( $word, $v, true ) ) {
					$v[] = $word;
				}
			}
			break;
		case 'countries':
			$all = function_exists( 'gend_sn_alpha2_countries' ) ? gend_sn_alpha2_countries() : array();
			$v   = array_values( array_intersect( array_unique( array_map( 'strval', is_array( $value ) ? $value : array() ) ), array_keys( $all ) ) );
			break;
		case 'secret':
			$v = sanitize_text_field( (string) $value );
			break;
		default:
			return new WP_Error( 'gs_sp_wall', __( 'Unknown setting.', 'gend-society' ), array( 'status' => 400 ) );
	}

	// As Youzify's panel: empty or default values are removed, others stored.
	$defaults = function_exists( 'gend_sn_default_options' ) ? gend_sn_default_options() : array();
	if ( empty( $v ) || ( isset( $defaults[ $key ] ) && $v == $defaults[ $key ] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Youzify compares loosely ("5" == 5).
		gend_sn_delete_option( $key );
	} else {
		gend_sn_update_option( $key, $v, false );
	}

	// Polls also read three grouped options, rebuilt from every poll setting.
	if ( 0 === strpos( $key, 'yzap_' ) ) {
		$maps = array(
			'youzify_poll_form_options'   => array( 'yzap_poll_options_limit' => 'options_limit', 'yzap_poll_multi_options' => 'multi_options', 'yzap_poll_options_selection' => 'options_selection', 'yzap_poll_options_image' => 'options_image', 'yzap_poll_options_image_enable' => 'options_image_enable' ),
			'youzify_poll_result_options' => array( 'yzap_poll_list_voters' => 'list_voters', 'yzap_poll_limit_voters' => 'limit_voters', 'yzap_poll_options_image_enable' => 'options_image_enable' ),
			'youzify_poll_post_options'   => array( 'yzap_poll_options_result' => 'options_result', 'yzap_poll_options_redirection' => 'options_redirection', 'yzap_poll_revote' => 'poll_revote' ),
		);
		foreach ( $maps as $option => $map ) {
			$set = array();
			foreach ( $map as $from => $to ) {
				$val = gend_sn_options( $from );
				if ( ! empty( $val ) ) {
					$set[ $to ] = $val;
				}
			}
			if ( $set ) {
				update_option( $option, $set );
			} else {
				delete_option( $option );
			}
		}
	}
	return true;
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
	$icons = gend_society_sp_menu_icons( $scope );
	foreach ( $rows as $k => $row ) {
		$rows[ $k ]['icon']        = isset( $icons[ $row['slug'] ] ) ? (string) $icons[ $row['slug'] ] : '';
		$rows[ $k ]['defaultIcon'] = gend_society_sp_default_icon( $row['slug'], $scope );
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return $a['position'] - $b['position'];
		}
	);
	return $rows;
}

/* ── Profile not found page ─────────────────────────────────────────────── */

/**
 * Whether this request is a members address with no member behind it
 * (e.g. /members/<unknown>/), which the theme answers with its 404 page.
 *
 * @return bool
 */
function gend_society_sp_is_missing_profile() {
	if ( ! is_404() || ! function_exists( 'bp_get_members_slug' ) || empty( $GLOBALS['wp'] ) ) {
		return false;
	}
	$slug = bp_get_members_slug();
	$path = trim( (string) $GLOBALS['wp']->request, '/' );
	return $path !== $slug && 0 === strpos( $path . '/', $slug . '/' );
}

/**
 * The "profile not found" card, from the Profile Pages › Profile not found
 * page settings (Youzify's options), falling back to the default photos.
 *
 * @return string
 */
function gend_society_sp_missing_profile_html() {
	$nf     = gend_society_sp_not_found();
	$nf     = is_array( $nf ) ? $nf : array();
	$photos = gend_society_sp_photos( 'profiles' );
	$photos = is_array( $photos ) ? $photos : array();
	$cover  = ! empty( $nf['cover'] ) ? $nf['cover'] : ( isset( $photos['cover'] ) ? $photos['cover'] : '' );
	$photo  = ! empty( $nf['photo'] ) ? $nf['photo'] : ( isset( $photos['avatar'] ) ? $photos['avatar'] : '' );
	$desc   = ! empty( $nf['desc'] ) ? $nf['desc'] : __( 'We’re sorry, the profile you’re looking for cannot be found.', 'gend-society' );
	$button = ! empty( $nf['button'] ) ? $nf['button'] : __( 'Go Back Home', 'gend-society' );
	$dir    = function_exists( 'bp_get_members_directory_permalink' ) ? bp_get_members_directory_permalink() : '';

	$html  = '<main class="gs-nf-profile" id="main">';
	$html .= '<div class="gs-nf-card">';
	$html .= '<div class="gs-nf-cover"' . ( $cover ? ' style="background-image:url(&quot;' . esc_url( $cover ) . '&quot;)"' : '' ) . '></div>';
	$html .= '<div class="gs-nf-body">';
	$html .= '<div class="gs-nf-photo">' . ( $photo ? '<img src="' . esc_url( $photo ) . '" alt="" width="128" height="128" loading="eager" decoding="async">' : '' ) . '</div>';
	$html .= '<p class="gs-nf-code">404</p>';
	$html .= '<h1 class="gs-nf-title">' . esc_html__( 'Profile not found', 'gend-society' ) . '</h1>';
	$html .= '<p class="gs-nf-desc">' . nl2br( esc_html( $desc ) ) . '</p>';
	$html .= '<div class="gs-nf-actions"><a class="gs-nf-btn" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $button ) . '</a>';
	if ( $dir ) {
		$html .= '<a class="gs-nf-btn is-ghost" href="' . esc_url( $dir ) . '">' . esc_html__( 'Browse members', 'gend-society' ) . '</a>';
	}
	$html .= '</div></div></div></main>';
	return $html;
}

add_filter( 'template_include', 'gend_society_sp_missing_profile_template', 999 );
/**
 * A missing member gets the "profile not found" card inside the theme's own
 * 404 page (header and footer kept, 404 status kept). Runs before the site
 * sidebars (1000) so they still wrap it.
 *
 * @param string $template Template file.
 * @return string
 */
function gend_society_sp_missing_profile_template( $template ) {
	global $_wp_current_template_content;
	if ( empty( $_wp_current_template_content ) || ! gend_society_sp_is_missing_profile() ) {
		return $template;
	}
	$blocks = parse_blocks( $_wp_current_template_content );
	$is_part = function ( $b, $area ) {
		return function_exists( 'gend_society_sb_is_frame_part' ) ? gend_society_sb_is_frame_part( $b, $area ) : ( 'core/template-part' === $b['blockName'] && isset( $b['attrs']['slug'] ) && false !== strpos( $b['attrs']['slug'], $area ) );
	};
	$n     = count( $blocks );
	$start = 0;
	$end   = $n;
	while ( $start < $n && ( empty( $blocks[ $start ]['blockName'] ) ? '' === trim( $blocks[ $start ]['innerHTML'] ) : $is_part( $blocks[ $start ], 'header' ) ) ) {
		++$start;
	}
	while ( $end > $start && ( empty( $blocks[ $end - 1 ]['blockName'] ) ? '' === trim( $blocks[ $end - 1 ]['innerHTML'] ) : $is_part( $blocks[ $end - 1 ], 'footer' ) ) ) {
		--$end;
	}
	$_wp_current_template_content = serialize_blocks( array_slice( $blocks, 0, $start ) ) // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global: the block template about to render.
		. gend_society_sp_missing_profile_html()
		. serialize_blocks( array_slice( $blocks, $end ) );
	$GLOBALS['gend_society_sp_missing_profile'] = true;
	add_filter( 'document_title_parts', 'gend_society_sp_missing_profile_title' );
	return $template;
}

/**
 * Page title for a missing member.
 *
 * @param array $parts Title parts.
 * @return array
 */
function gend_society_sp_missing_profile_title( $parts ) {
	$parts['title'] = __( 'Profile not found', 'gend-society' );
	return $parts;
}

add_action( 'wp_enqueue_scripts', 'gend_society_sp_missing_profile_styles', 20 );
/**
 * Styles for the "profile not found" card (only on that page).
 */
function gend_society_sp_missing_profile_styles() {
	if ( ! gend_society_sp_is_missing_profile() ) {
		return;
	}
	wp_register_style( 'gend-society-missing-profile', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-missing-profile' );
	wp_add_inline_style(
		'gend-society-missing-profile',
		'.gs-nf-profile{box-sizing:border-box;width:100%;max-width:880px;margin:clamp(28px,6vw,72px) auto;padding:0 16px}'
		. '.gs-nf-card{overflow:hidden;border:1px solid rgba(255,255,255,.12);border-radius:22px;background:linear-gradient(180deg,rgba(18,24,38,.96),rgba(10,14,24,.98));box-shadow:0 30px 80px rgba(0,0,0,.35);color:#e8edf5;text-align:center;animation:gs-nf-in .5s cubic-bezier(.2,.8,.2,1) both}'
		. '.gs-nf-cover{height:clamp(140px,26vw,240px);background:linear-gradient(135deg,#1b2a44,#0d1524) center/cover no-repeat;position:relative}'
		. '.gs-nf-cover::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,14,24,0) 40%,rgba(10,14,24,.9))}'
		. '.gs-nf-body{position:relative;padding:0 clamp(20px,5vw,56px) clamp(28px,5vw,48px)}'
		. '.gs-nf-photo{width:128px;height:128px;margin:-64px auto 14px;border-radius:50%;border:4px solid #0f1626;background:#1b2436;overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.4);filter:grayscale(.35)}'
		. '.gs-nf-photo img{display:block;width:100%;height:100%;object-fit:cover}'
		. '.gs-nf-code{margin:0;font-size:12px;font-weight:700;letter-spacing:.3em;color:#6ec1e4}'
		. '.gs-nf-title{margin:6px 0 10px;font-size:clamp(26px,4vw,38px);line-height:1.15;color:#fff}'
		. '.gs-nf-desc{max-width:52ch;margin:0 auto 24px;font-size:16px;line-height:1.6;color:rgba(232,237,245,.78)}'
		. '.gs-nf-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:10px}'
		. '.gs-nf-btn{display:inline-flex;align-items:center;padding:12px 22px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;color:#0b1220;background:linear-gradient(135deg,#97e46e,#6ec1e4);transition:transform .2s,box-shadow .2s}'
		. '.gs-nf-btn:hover{transform:translateY(-1px);box-shadow:0 10px 26px rgba(110,193,228,.3);color:#0b1220}'
		. '.gs-nf-btn.is-ghost{color:#e8edf5;background:transparent;border:1px solid rgba(255,255,255,.22)}'
		. '.gs-nf-btn.is-ghost:hover{border-color:#6ec1e4;color:#fff;box-shadow:none}'
		. '@keyframes gs-nf-in{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}'
		. '@media (prefers-reduced-motion:reduce){.gs-nf-card{animation:none}.gs-nf-btn{transition:none}}'
	);
}

/* ── Menu icons ─────────────────────────────────────────────────────────── */

/**
 * Option holding the chosen menu icons: array( 'groups' => array( slug => key ), 'profiles' => … ).
 */
const GEND_SOCIETY_SP_MENU_ICONS = 'gend_society_sp_menu_icons';

/**
 * Icons the builder offers for menu tabs (24×24 line drawings, currentColor stroke).
 *
 * @return array Key => array( label, svg inner markup ).
 */
function gend_society_sp_icon_library() {
	return array(
		'pulse'      => array( __( 'Activity', 'gend-society' ), '<path d="M3 12h4l2.5-7 5 14 2.5-7H21"/>' ),
		'user'       => array( __( 'Person', 'gend-society' ), '<circle cx="12" cy="9" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>' ),
		'users'      => array( __( 'People', 'gend-society' ), '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>' ),
		'network'    => array( __( 'Connections', 'gend-society' ), '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><line x1="8" y1="7.5" x2="11" y2="15.5"/><line x1="16" y1="7.5" x2="13" y2="15.5"/><line x1="8.5" y1="6" x2="15.5" y2="6"/>' ),
		'invite'     => array( __( 'Invite', 'gend-society' ), '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><line x1="19" y1="6" x2="19" y2="14"/><line x1="15" y1="10" x2="23" y2="10"/>' ),
		'grid'       => array( __( 'Grid', 'gend-society' ), '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>' ),
		'cube'       => array( __( 'Apps', 'gend-society' ), '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M3 7l9 5 9-5"/><path d="M12 12v10"/>' ),
		'kanban'     => array( __( 'Projects', 'gend-society' ), '<rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="4" height="7" rx="1"/>' ),
		'monitor'    => array( __( 'Screen', 'gend-society' ), '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>' ),
		'book'       => array( __( 'Plan', 'gend-society' ), '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="15" y2="11"/>' ),
		'document'   => array( __( 'Document', 'gend-society' ), '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>' ),
		'contract'   => array( __( 'Contract', 'gend-society' ), '<rect x="5" y="3" width="14" height="18" rx="2"/><line x1="8.5" y1="8" x2="15.5" y2="8"/><line x1="8.5" y1="12" x2="15.5" y2="12"/><line x1="8.5" y1="16" x2="13" y2="16"/>' ),
		'folder'     => array( __( 'Folder', 'gend-society' ), '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>' ),
		'image'      => array( __( 'Media', 'gend-society' ), '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>' ),
		'calendar'   => array( __( 'Calendar', 'gend-society' ), '<rect x="3" y="4.5" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="8" y1="2.5" x2="8" y2="6.5"/><line x1="16" y1="2.5" x2="16" y2="6.5"/>' ),
		'clock'      => array( __( 'Clock', 'gend-society' ), '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' ),
		'chat'       => array( __( 'Messages', 'gend-society' ), '<path d="M21 12a8 8 0 0 1-12 7l-5 2 1.5-5A8 8 0 1 1 21 12z"/>' ),
		'forum'      => array( __( 'Forum', 'gend-society' ), '<path d="M21 12a4 4 0 0 1-4 4H9l-4 3V8a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4z"/><line x1="9" y1="10" x2="17" y2="10"/><line x1="9" y1="13" x2="14" y2="13"/>' ),
		'bell'       => array( __( 'Notifications', 'gend-society' ), '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.5 21a2 2 0 0 1-3 0"/>' ),
		'wallet'     => array( __( 'Wallet', 'gend-society' ), '<rect x="2.5" y="6.5" width="19" height="13" rx="2"/><path d="M2.5 10.5h19"/><rect x="15" y="14" width="4" height="3" rx="0.8"/>' ),
		'dollar'     => array( __( 'Payments', 'gend-society' ), '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>' ),
		'chart'      => array( __( 'Growth', 'gend-society' ), '<path d="M3 17l5-5 4 4 8-9"/><polyline points="14 7 21 7 21 14"/>' ),
		'bars'       => array( __( 'Stats', 'gend-society' ), '<line x1="6" y1="20" x2="6" y2="12"/><line x1="12" y1="20" x2="12" y2="5"/><line x1="18" y1="20" x2="18" y2="9"/>' ),
		'cart'       => array( __( 'Store', 'gend-society' ), '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L22 7H6"/>' ),
		'tag'        => array( __( 'Tag', 'gend-society' ), '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>' ),
		'badge'      => array( __( 'Membership', 'gend-society' ), '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11.5" r="2.2"/><path d="M6 17a3 3 0 0 1 6 0"/><line x1="14.5" y1="10" x2="19" y2="10"/><line x1="14.5" y1="13" x2="18" y2="13"/>' ),
		'share'      => array( __( 'Share', 'gend-society' ), '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><line x1="8.2" y1="10.8" x2="15.8" y2="6.2"/><line x1="8.2" y1="13.2" x2="15.8" y2="17.8"/>' ),
		'cloud'      => array( __( 'Hosting', 'gend-society' ), '<path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.78A6 6 0 0 0 4 12.5 4.5 4.5 0 0 0 6.5 19h11z"/>' ),
		'bolt'       => array( __( 'Power', 'gend-society' ), '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>' ),
		'sparkle'    => array( __( 'AI', 'gend-society' ), '<path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4L12 3z"/><path d="M19 14l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2z"/>' ),
		'code'       => array( __( 'Code', 'gend-society' ), '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>' ),
		'sliders'    => array( __( 'Settings', 'gend-society' ), '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="18" r="2"/>' ),
		'home'       => array( __( 'Home', 'gend-society' ), '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>' ),
		'star'       => array( __( 'Star', 'gend-society' ), '<polygon points="12 2 15.1 8.3 22 9.3 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.3 8.9 8.3 12 2"/>' ),
		'heart'      => array( __( 'Heart', 'gend-society' ), '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>' ),
		'shield'     => array( __( 'Shield', 'gend-society' ), '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>' ),
		'lock'       => array( __( 'Private', 'gend-society' ), '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>' ),
		'globe'      => array( __( 'Globe', 'gend-society' ), '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>' ),
		'rocket'     => array( __( 'Launch', 'gend-society' ), '<path d="M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2.1-.1-2.9a2.2 2.2 0 0 0-2.9-.1z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.9A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.6-3 2-4c1.6-1.1 5 0 5 0"/><path d="M12 15v5s3-.6 4-2c1.1-1.6 0-5 0-5"/>' ),
		'target'     => array( __( 'Target', 'gend-society' ), '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>' ),
		'briefcase'  => array( __( 'Work', 'gend-society' ), '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>' ),
		'megaphone'  => array( __( 'Marketing', 'gend-society' ), '<path d="M3 11v3a1 1 0 0 0 1 1h3l5 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M16 8a5 5 0 0 1 0 8"/><path d="M19 5a9 9 0 0 1 0 14"/>' ),
		'graduation' => array( __( 'Learning', 'gend-society' ), '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>' ),
	);
}

/**
 * A library icon as markup for the menu it is shown in.
 *
 * @param string $key   Library key.
 * @param string $scope groups|profiles.
 * @return string Empty when the key is unknown.
 */
function gend_society_sp_icon_svg( $key, $scope ) {
	$lib = gend_society_sp_icon_library();
	if ( ! isset( $lib[ $key ] ) ) {
		return '';
	}
	// Match each menu's own icons: groups draw 16px at stroke 2, profiles size by CSS at 1.75.
	$attrs = 'groups' === $scope ? 'width="16" height="16" stroke-width="2"' : 'stroke-width="1.75"';
	return '<svg ' . $attrs . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $lib[ $key ][1] . '</svg>';
}

/**
 * Chosen icon keys for a menu.
 *
 * @param string $scope groups|profiles.
 * @return array Slug => key.
 */
function gend_society_sp_menu_icons( $scope ) {
	$all = get_option( GEND_SOCIETY_SP_MENU_ICONS, array() );
	return isset( $all[ $scope ] ) && is_array( $all[ $scope ] ) ? $all[ $scope ] : array();
}

/**
 * The icon a tab shows without a choice (each menu's built-in drawing).
 *
 * @param string $slug  Tab slug.
 * @param string $scope groups|profiles.
 * @return string
 */
function gend_society_sp_default_icon( $slug, $scope ) {
	$GLOBALS['gend_society_sp_icon_bypass'] = true;
	$svg = '';
	if ( 'groups' === $scope && function_exists( 'psoo_bridge_nav_icon' ) ) {
		$svg = psoo_bridge_nav_icon( $slug );
	} elseif ( 'profiles' === $scope && function_exists( 'gend_society_get_profile_nav_icon' ) ) {
		$svg = gend_society_get_profile_nav_icon( $slug );
	}
	unset( $GLOBALS['gend_society_sp_icon_bypass'] );
	return (string) $svg;
}

add_filter( 'psoo_bridge_nav_icon', 'gend_society_sp_group_nav_icon', 10, 2 );
/**
 * Group menu: the chosen icon in place of the built-in one.
 *
 * @param string $svg  Built-in icon.
 * @param string $slug Tab slug.
 * @return string
 */
function gend_society_sp_group_nav_icon( $svg, $slug ) {
	if ( ! empty( $GLOBALS['gend_society_sp_icon_bypass'] ) ) {
		return $svg;
	}
	$icons  = gend_society_sp_menu_icons( 'groups' );
	$chosen = isset( $icons[ $slug ] ) ? gend_society_sp_icon_svg( $icons[ $slug ], 'groups' ) : '';
	return '' !== $chosen ? $chosen : $svg;
}

add_filter( 'gend_society_profile_nav_icon', 'gend_society_sp_profile_nav_icon', 10, 2 );
/**
 * Profile menu: the chosen icon in place of the built-in one.
 *
 * @param string $svg  Empty, or an earlier filter's icon.
 * @param string $slug Tab slug.
 * @return string
 */
function gend_society_sp_profile_nav_icon( $svg, $slug ) {
	if ( ! empty( $GLOBALS['gend_society_sp_icon_bypass'] ) ) {
		return $svg;
	}
	$icons = gend_society_sp_menu_icons( 'profiles' );
	return isset( $icons[ $slug ] ) ? gend_society_sp_icon_svg( $icons[ $slug ], 'profiles' ) : $svg;
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
			'photos'     => gend_society_sp_photos( $scope ),
			'cardStats'  => gend_society_sp_card_stats( $scope ),
			'notFound'   => 'profiles' === $scope ? gend_society_sp_not_found() : null,
			'privacy'    => 'profiles' === $scope ? gend_society_sp_privacy() : null,
			'icons'      => array_map(
				function ( $key ) use ( $scope ) {
					$lib = gend_society_sp_icon_library();
					return array(
						'key'   => $key,
						'label' => $lib[ $key ][0],
						'svg'   => gend_society_sp_icon_svg( $key, $scope ),
					);
				},
				array_keys( gend_society_sp_icon_library() )
			),
		)
	);
}

/**
 * Privacy & trust switches (the same settings Member Management › Settings edits).
 * Account deletion is BuddyPress's `bp-disable-account-deletion`, stored inverted.
 *
 * @return array|null Null when Youzify is not active.
 */
function gend_society_sp_privacy() {
	if ( ! function_exists( 'gend_sn_option' ) ) {
		return null;
	}
	$deletion = function_exists( 'bp_get_option' ) ? bp_get_option( 'bp-disable-account-deletion' ) : gend_sn_option( 'bp-disable-account-deletion' );
	return array(
		array(
			'key'   => 'youzify_allow_private_profiles',
			'title' => __( 'Allow private profiles', 'gend-society' ),
			'desc'  => __( 'Members can choose to make their profile private.', 'gend-society' ),
			'on'    => 'on' === gend_sn_options( 'youzify_allow_private_profiles' ),
		),
		array(
			'key'   => 'youzify_enable_account_verification',
			'title' => __( 'Verified badges', 'gend-society' ),
			'desc'  => __( 'Show a verified badge next to members you have verified.', 'gend-society' ),
			'on'    => 'on' === gend_sn_options( 'youzify_enable_account_verification' ),
		),
		array(
			'key'   => 'bp-disable-account-deletion',
			'title' => __( 'Let members delete their account', 'gend-society' ),
			'desc'  => __( 'Members can permanently delete their own account from their settings.', 'gend-society' ),
			'on'    => 0 == $deletion, // phpcs:ignore Universal.Operators.StrictComparisons -- stored as 0/1 or "0"/"1".
		),
	);
}

/**
 * "Profile not found" page fields: Youzify's own options (the same ones
 * Member Management › Settings edits). Empty text falls back to Youzify's default.
 *
 * @return array|null Null when Youzify is not active.
 */
function gend_society_sp_not_found() {
	if ( ! function_exists( 'gend_sn_options' ) ) {
		return null;
	}
	return array(
		'desc'   => (string) gend_sn_options( 'youzify_profile_404_desc' ),
		'button' => (string) gend_sn_options( 'youzify_profile_404_button' ),
		'photo'  => (string) gend_sn_option( 'youzify_profile_404_photo', '' ),
		'cover'  => (string) gend_sn_option( 'youzify_profile_404_cover', '' ),
	);
}

/**
 * Directory card statistics switches. Youzify's own on/off options (the same
 * ones its Groups / Members Directory settings edit); unset means on.
 *
 * @param string $scope groups|profiles.
 * @return array Key => [title, description].
 */
function gend_society_sp_card_stat_keys( $scope ) {
	if ( 'groups' === $scope ) {
		return array(
			'youzify_enable_gd_groups_statistics'         => array( __( 'Show statistics', 'gend-society' ), __( 'The numbers row on every group card. Off hides all of them.', 'gend-society' ) ),
			'youzify_enable_gd_group_posts_statistics'    => array( __( 'Posts', 'gend-society' ), __( 'How many posts the group has.', 'gend-society' ) ),
			'youzify_enable_gd_group_activity_statistics' => array( __( 'Activity', 'gend-society' ), __( 'When the group was last active.', 'gend-society' ) ),
			'youzify_enable_gd_group_members_statistics'  => array( __( 'Members', 'gend-society' ), __( 'How many members the group has.', 'gend-society' ) ),
		);
	}
	return array(
		'youzify_enable_md_users_statistics'          => array( __( 'Show statistics', 'gend-society' ), __( 'The numbers row on every member card. Off hides all of them.', 'gend-society' ) ),
		'youzify_enable_md_user_posts_statistics'     => array( __( 'Posts', 'gend-society' ), __( 'How many posts the member has written.', 'gend-society' ) ),
		'youzify_enable_md_user_comments_statistics'  => array( __( 'Comments', 'gend-society' ), __( 'How many comments the member has left.', 'gend-society' ) ),
		'youzify_enable_md_user_views_statistics'     => array( __( 'Profile views', 'gend-society' ), __( 'How often the profile has been viewed.', 'gend-society' ) ),
		'youzify_enable_md_user_friends_statistics'   => array( __( 'Friends', 'gend-society' ), __( 'How many friends the member has.', 'gend-society' ) ),
		'youzify_enable_md_user_followers_statistics' => array( __( 'Followers', 'gend-society' ), __( 'How many members follow them.', 'gend-society' ) ),
		'youzify_enable_md_user_following_statistics' => array( __( 'Following', 'gend-society' ), __( 'How many members they follow.', 'gend-society' ) ),
		'youzify_enable_md_user_points_statistics'    => array( __( 'Points', 'gend-society' ), __( 'The member’s points balance.', 'gend-society' ) ),
	);
}

/**
 * Directory card statistics with their current state.
 *
 * @param string $scope groups|profiles.
 * @return array|null Null when Youzify is not active.
 */
function gend_society_sp_card_stats( $scope ) {
	if ( ! function_exists( 'gend_sn_option' ) ) {
		return null;
	}
	$out = array();
	foreach ( gend_society_sp_card_stat_keys( $scope ) as $key => $text ) {
		$out[] = array(
			'key'   => $key,
			'title' => $text[0],
			'desc'  => $text[1],
			'on'    => 'off' !== gend_sn_option( $key, 'on' ),
		);
	}
	return $out;
}

/**
 * Default avatar and cover, shown until a member or group uploads their own.
 * These are Youzify's own options (the same ones Member Management › Settings
 * edits), so both screens stay in step.
 *
 * @param string $scope groups|profiles.
 * @return array|null Null when Youzify is not active.
 */
function gend_society_sp_photos( $scope ) {
	if ( ! function_exists( 'gend_sn_option' ) ) {
		return null;
	}
	$kind = 'groups' === $scope ? 'groups' : 'profiles';
	$out  = array();
	foreach ( array( 'avatar', 'cover' ) as $part ) {
		$out[ $part ] = (string) gend_sn_option( 'youzify_default_' . $kind . '_' . $part, '' );
	}
	return $out;
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

		case 'card-stat':
			$keys = gend_society_sp_card_stat_keys( $scope );
			$key  = isset( $body['key'] ) ? (string) $body['key'] : '';
			if ( ! function_exists( 'gend_sn_update_option' ) || ! isset( $keys[ $key ] ) ) {
				return new WP_Error( 'gs_sp_card_stat', __( 'Unknown setting.', 'gend-society' ), array( 'status' => 400 ) );
			}
			gend_sn_update_option( $key, empty( $body['on'] ) ? 'off' : 'on' );
			return rest_ensure_response( array( 'cardStats' => gend_society_sp_card_stats( $scope ) ) );

		case 'menu-icon':
			$icon = isset( $body['icon'] ) ? sanitize_key( $body['icon'] ) : '';
			$lib  = gend_society_sp_icon_library();
			if ( '' === $slug || ( '' !== $icon && ! isset( $lib[ $icon ] ) ) ) {
				return new WP_Error( 'gs_sp_icon', __( 'Unknown icon.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$all = get_option( GEND_SOCIETY_SP_MENU_ICONS, array() );
			$all = is_array( $all ) ? $all : array();
			if ( ! isset( $all[ $scope ] ) || ! is_array( $all[ $scope ] ) ) {
				$all[ $scope ] = array();
			}
			if ( '' === $icon ) {
				unset( $all[ $scope ][ $slug ] );
			} else {
				$all[ $scope ][ $slug ] = $icon;
			}
			update_option( GEND_SOCIETY_SP_MENU_ICONS, $all );
			return rest_ensure_response( array( 'icon' => $icon ) );

		case 'privacy':
			$key = isset( $body['key'] ) ? (string) $body['key'] : '';
			$on  = ! empty( $body['on'] );
			if ( 'profiles' !== $scope || ! function_exists( 'gend_sn_update_option' ) || ! in_array( $key, array( 'youzify_allow_private_profiles', 'youzify_enable_account_verification', 'bp-disable-account-deletion' ), true ) ) {
				return new WP_Error( 'gs_sp_privacy', __( 'Unknown setting.', 'gend-society' ), array( 'status' => 400 ) );
			}
			if ( 'bp-disable-account-deletion' === $key ) {
				gend_sn_update_option( $key, $on ? 0 : 1 );
			} else {
				gend_sn_update_option( $key, $on ? 'on' : 'off' );
			}
			return rest_ensure_response( array( 'privacy' => gend_society_sp_privacy() ) );

		case 'not-found':
			$field = isset( $body['field'] ) ? (string) $body['field'] : '';
			if ( 'profiles' !== $scope || ! function_exists( 'gend_sn_update_option' ) || ! in_array( $field, array( 'desc', 'button', 'photo', 'cover' ), true ) ) {
				return new WP_Error( 'gs_sp_not_found', __( 'Unknown setting.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$raw = isset( $body['value'] ) ? (string) $body['value'] : '';
			if ( 'desc' === $field ) {
				$val = sanitize_textarea_field( $raw );
			} elseif ( 'button' === $field ) {
				$val = sanitize_text_field( $raw );
			} else {
				$val = esc_url_raw( trim( $raw ) );
			}
			$key = 'youzify_profile_404_' . $field;
			if ( '' === trim( $val ) ) {
				gend_sn_delete_option( $key );
			} else {
				gend_sn_update_option( $key, $val );
			}
			return rest_ensure_response( array( 'notFound' => gend_society_sp_not_found() ) );

		case 'photos':
			if ( ! function_exists( 'gend_sn_update_option' ) ) {
				return new WP_Error( 'gs_sp_photos', __( 'Default photos need Youzify.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$part = isset( $body['part'] ) ? (string) $body['part'] : '';
			if ( ! in_array( $part, array( 'avatar', 'cover' ), true ) ) {
				return new WP_Error( 'gs_sp_photos', __( 'Unknown photo.', 'gend-society' ), array( 'status' => 400 ) );
			}
			$key = 'youzify_default_' . ( 'groups' === $scope ? 'groups' : 'profiles' ) . '_' . $part;
			$url = isset( $body['url'] ) ? esc_url_raw( trim( (string) $body['url'] ) ) : '';
			if ( '' === $url ) {
				gend_sn_delete_option( $key );
			} else {
				gend_sn_update_option( $key, $url );
			}
			return rest_ensure_response( array( 'photos' => gend_society_sp_photos( $scope ) ) );

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
