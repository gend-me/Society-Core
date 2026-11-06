<?php
/**
 * The community home of a web app (the Activity directory) as a designed page in the style guide: a hero with the
 * community's live numbers, and a sidebar panel (you, the newest members, groups, and the team's numbers) in place of
 * the starter widgets WordPress puts in every new sidebar. Widgets the site's owner adds themselves are kept.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

/** The starter blocks WordPress fills a new site's sidebars with. */
function gend_society_ch_is_starter_block( $widget_id ) {
	if ( 0 !== strpos( (string) $widget_id, 'block-' ) ) {
		return false;
	}
	$all     = (array) get_option( 'widget_block', array() );
	$content = (string) ( $all[ (int) substr( $widget_id, 6 ) ]['content'] ?? '' );
	return (bool) preg_match( '#^\s*(<!-- wp:search /-->|<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>(Recent Posts|Recent Comments|Archives|Categories)</h2>)#', $content );
}

add_filter( 'sidebars_widgets', 'gend_society_ch_sidebars' );
/** The activity sidebars: the starter widgets go, and the community panel leads. */
function gend_society_ch_sidebars( $sidebars ) {
	if ( is_admin() || ! function_exists( 'gend_society_sb_scoped' ) || ! gend_society_sb_scoped() ) {
		return $sidebars;
	}
	foreach ( array( 'youzify-wall-sidebar', 'youzify-wall-left-sidebar' ) as $id ) {
		if ( isset( $sidebars[ $id ] ) && is_array( $sidebars[ $id ] ) ) {
			$sidebars[ $id ] = array_values( array_filter( $sidebars[ $id ], static fn( $w ) => ! gend_society_ch_is_starter_block( $w ) ) );
		}
	}
	$right                            = (array) ( $sidebars['youzify-wall-sidebar'] ?? array() );
	$sidebars['youzify-wall-sidebar'] = array_merge( array( 'gs_community_panel-1' ), array_diff( $right, array( 'gs_community_panel-1' ) ) );
	return $sidebars;
}

add_filter( 'option_widget_gs_community_panel', 'gend_society_ch_panel_instances' );
add_filter( 'default_option_widget_gs_community_panel', 'gend_society_ch_panel_instances' );
function gend_society_ch_panel_instances( $value ) {
	$value      = is_array( $value ) ? $value : array();
	$value[1]   = $value[1] ?? array();
	$value['_multiwidget'] = 1;
	return $value;
}

add_action( 'widgets_init', 'gend_society_ch_register' );
function gend_society_ch_register() {
	if ( class_exists( 'WP_Widget' ) ) {
		register_widget( 'GEND_Society_Community_Panel' );
	}
}

if ( class_exists( 'WP_Widget' ) && ! class_exists( 'GEND_Society_Community_Panel' ) ) {
	/** You, the newest members, groups and (for the team) the community's numbers. */
	class GEND_Society_Community_Panel extends WP_Widget {
		public function __construct() {
			parent::__construct( 'gs_community_panel', __( 'Community panel', 'gend-society' ), array( 'description' => __( 'You, the newest members and groups.', 'gend-society' ) ) );
		}

		public function widget( $args, $instance ) {
			echo '<div class="gs-ch-panel">' . gend_society_ch_panel() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped below
		}
	}
}

/** This site's own groups: groups are shared across the network, and a site's carry its id. */
function gend_society_ch_groups_clause() {
	return function_exists( 'gend_society_sb_groups_clause' ) ? gend_society_sb_groups_clause() : array( array( 'key' => '_gend_site_blog', 'value' => get_current_blog_id() ) );
}

/** How many groups this site has, or -1 when groups are off. */
function gend_society_ch_group_count() {
	if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'groups' ) || ! function_exists( 'groups_get_groups' ) ) {
		return -1;
	}
	$found = groups_get_groups( array( 'per_page' => 1, 'show_hidden' => true, 'meta_query' => gend_society_ch_groups_clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return (int) ( $found['total'] ?? 0 );
}

/** A member's profile address. */
function gend_society_ch_user_url( $uid ) {
	if ( function_exists( 'bp_members_get_user_url' ) ) {
		return (string) bp_members_get_user_url( $uid );
	}
	return function_exists( 'bp_core_get_user_domain' ) ? (string) bp_core_get_user_domain( $uid ) : '';
}

function gend_society_ch_avatar( $id, $object = 'user', $size = 'thumb' ) {
	return function_exists( 'bp_core_fetch_avatar' ) ? (string) bp_core_fetch_avatar( array( 'item_id' => (int) $id, 'object' => $object, 'type' => $size, 'html' => false ) ) : '';
}

function gend_society_ch_panel() {
	$out = '';
	$uid = get_current_user_id();
	if ( $uid ) {
		$user = wp_get_current_user();
		$url  = gend_society_ch_user_url( $uid );
		$out .= '<section class="gs-ch-card gs-ch-me"><img src="' . esc_url( gend_society_ch_avatar( $uid, 'user', 'full' ) ) . '" alt="" width="64" height="64" loading="lazy">'
			. '<div><b>' . esc_html( $user->display_name ) . '</b><span>@' . esc_html( $user->user_nicename ) . '</span></div>'
			. ( $url ? '<a class="gs-ch-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Your profile', 'gend-society' ) . '</a>' : '' ) . '</section>';
	} else {
		$join = function_exists( 'wp_registration_url' ) ? wp_registration_url() : '';
		$out .= '<section class="gs-ch-card gs-ch-join"><b>' . esc_html__( 'Join the community', 'gend-society' ) . '</b><p>' . esc_html__( 'Post, follow what members share and take part.', 'gend-society' ) . '</p>'
			. '<a class="gs-ch-btn" href="' . esc_url( $join ?: wp_login_url() ) . '">' . esc_html__( 'Join', 'gend-society' ) . '</a> <a class="gs-ch-link" href="' . esc_url( wp_login_url( function_exists( 'bp_get_activity_directory_permalink' ) ? bp_get_activity_directory_permalink() : home_url( '/' ) ) ) . '">' . esc_html__( 'Log in', 'gend-society' ) . '</a></section>';
	}
	// Only this site's own people and groups: members and groups are shared across the network.
	$people = get_users( array( 'blog_id' => get_current_blog_id(), 'orderby' => 'registered', 'order' => 'DESC', 'number' => 9, 'fields' => array( 'ID', 'display_name' ) ) );
	if ( function_exists( 'bp_core_fetch_avatar' ) ) {
		if ( $people ) {
			$out .= '<section class="gs-ch-card"><h3>' . esc_html__( 'Newest members', 'gend-society' ) . '</h3><ul class="gs-ch-faces">';
			foreach ( $people as $i => $p ) {
				$id   = (int) ( $p->ID ?? $p->id ?? 0 );
				$name = (string) ( $p->display_name ?? '' );
				$out .= '<li style="--i:' . (int) $i . '"><a href="' . esc_url( gend_society_ch_user_url( $id ) ) . '" title="' . esc_attr( $name ) . '"><img src="' . esc_url( gend_society_ch_avatar( $id ) ) . '" alt="' . esc_attr( $name ) . '" width="48" height="48" loading="lazy"></a></li>';
			}
			$out .= '</ul></section>';
		}
	}
	if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) && function_exists( 'groups_get_groups' ) ) {
		$found  = groups_get_groups( array( 'type' => 'popular', 'per_page' => 5, 'meta_query' => gend_society_ch_groups_clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$groups = (array) ( $found['groups'] ?? array() );
		if ( $groups ) {
			$out .= '<section class="gs-ch-card"><h3>' . esc_html__( 'Groups', 'gend-society' ) . '</h3><ul class="gs-ch-groups">';
			foreach ( $groups as $g ) {
				$count = (int) groups_get_total_member_count( $g->id );
				/* translators: %s: number of members */
				$out .= '<li><a href="' . esc_url( bp_get_group_permalink( $g ) ) . '"><img src="' . esc_url( gend_society_ch_avatar( $g->id, 'group' ) ) . '" alt="" width="40" height="40" loading="lazy"><span><b>' . esc_html( $g->name ) . '</b><em>' . esc_html( sprintf( _n( '%s member', '%s members', $count, 'gend-society' ), number_format_i18n( $count ) ) ) . '</em></span></a></li>';
			}
			$out .= '</ul></section>';
		}
	}
	if ( shortcode_exists( 'gs_live' ) ) {
		$out .= do_shortcode( '[gs_live type="community" title="' . esc_attr__( 'Community this month', 'gend-society' ) . '"]' );
	}
	return $out;
}

/** The community's numbers anyone may see, cached a few minutes. */
function gend_society_ch_numbers() {
	$k   = 'gs_ch_numbers';
	$got = get_transient( $k );
	if ( is_array( $got ) ) {
		return $got;
	}
	global $wpdb;
	$users = new WP_User_Query( array( 'blog_id' => get_current_blog_id(), 'number' => 1, 'fields' => 'ID', 'count_total' => true ) );
	$got   = array( 'members' => (int) $users->get_total(), 'week' => 0, 'groups' => gend_society_ch_group_count() );
	if ( function_exists( 'bp_is_active' ) && bp_is_active( 'activity' ) ) {
		$t           = buddypress()->activity->table_name;
		$got['week'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE date_recorded >= %s AND is_spam = 0 AND hide_sitewide = 0", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- BuddyPress table name
	}
	set_transient( $k, $got, 5 * MINUTE_IN_SECONDS );
	return $got;
}

add_action( 'bp_before_directory_activity', 'gend_society_ch_hero', 5 );
/** The community home's hero. */
function gend_society_ch_hero() {
	if ( ! function_exists( 'gend_society_sb_scoped' ) || ! gend_society_sb_scoped() ) {
		return;
	}
	$n     = gend_society_ch_numbers();
	$name  = get_bloginfo( 'name' );
	$stats = array(
		array( $n['members'], _n( 'member', 'members', $n['members'], 'gend-society' ) ),
		array( $n['week'], __( 'posts this week', 'gend-society' ) ),
	);
	if ( $n['groups'] >= 0 ) {
		$stats[] = array( $n['groups'], _n( 'group', 'groups', $n['groups'], 'gend-society' ) );
	}
	echo '<header class="gs-ch-hero"><div class="gs-ch-hero__glow" aria-hidden="true"></div><div class="gs-ch-hero__body">'
		. '<p class="gs-ch-hero__kicker">' . esc_html__( 'Community', 'gend-society' ) . '</p>'
		/* translators: %s: site name */
		. '<h1>' . esc_html( sprintf( __( 'The %s community', 'gend-society' ), $name ) ) . '</h1>'
		. ( get_bloginfo( 'description' ) ? '<p class="gs-ch-hero__lead">' . esc_html( get_bloginfo( 'description' ) ) . '</p>' : '' )
		. '<ul class="gs-ch-hero__stats">';
	foreach ( $stats as $i => $s ) {
		echo '<li style="--i:' . (int) $i . '"><b>' . esc_html( number_format_i18n( $s[0] ) ) . '</b><span>' . esc_html( $s[1] ) . '</span></li>';
	}
	echo '</ul></div></header>';
}

add_action( 'wp_enqueue_scripts', 'gend_society_ch_css', 100 );
/** After the community brand sheet, on the community pages of a web app. */
function gend_society_ch_css() {
	if ( ! function_exists( 'gend_society_sb_scoped' ) || ! gend_society_sb_scoped() || ! function_exists( 'is_buddypress' ) || ! is_buddypress() ) {
		return;
	}
	wp_enqueue_style( 'gend-society-community-home', GEND_SOCIETY_URL . 'assets/community-home.css', array(), GEND_SOCIETY_VERSION . '.' . (int) @filemtime( GEND_SOCIETY_DIR . 'assets/community-home.css' ) );
}
