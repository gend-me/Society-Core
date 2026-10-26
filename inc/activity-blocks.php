<?php
/**
 * Activity wall blocks: the activity feed page split into parts that a
 * Directory Layout ("Activity feed") arranges freely.
 *
 *   Activity Filter Bar   the tabs (All Members, My Friends, My Groups …), filter and search
 *   Activity Post Form    "What's new?" with photos, polls, moods … (signed-in members)
 *   Activity Feed         the posts, with comments, reactions and Load More
 *   Activity Sidebar      the wall's widget areas
 *
 * Each part outputs the same markup the wall uses today, so posting,
 * comments, reactions, filters and Load More keep working with their
 * existing scripts. The layout gets the wall's page wrapper (see
 * gend_society_ab_wrap()).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The activity blocks: name => title, render callback, attributes.
 *
 * @return array[]
 */
function gend_society_ab_blocks() {
	return array(
		'activity-tabs'      => array(
			'title'  => __( 'Activity Filter Bar', 'gend-society' ),
			'render' => 'gend_society_ab_block_tabs',
			'attrs'  => array(
				'showFilter' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showSearch' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
		),
		'activity-post-form' => array(
			'title'  => __( 'Activity Post Form', 'gend-society' ),
			'render' => 'gend_society_ab_block_post_form',
			'attrs'  => array(
				'loginPrompt' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		),
		'activity-feed'      => array(
			'title'  => __( 'Activity Feed', 'gend-society' ),
			'render' => 'gend_society_ab_block_feed',
			'attrs'  => array(),
		),
		'activity-sidebar'   => array(
			'title'  => __( 'Activity Sidebar', 'gend-society' ),
			'render' => 'gend_society_ab_block_sidebar',
			'attrs'  => array(
				'side' => array(
					'type'    => 'string',
					'default' => 'right',
				),
			),
		),
	);
}

add_action( 'init', 'gend_society_ab_register', 21 );
/**
 * Registers the activity blocks.
 */
function gend_society_ab_register() {
	$js = 'assets/activity-blocks.js';
	wp_register_script( 'gend-society-activity-blocks', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_set_script_translations( 'gend-society-activity-blocks', 'gend-society' );
	$supports = array(
		'color'   => array(
			'text'       => true,
			'background' => true,
		),
		'spacing' => array(
			'margin'  => true,
			'padding' => true,
		),
		'align'   => array( 'wide', 'full' ),
	);
	foreach ( gend_society_ab_blocks() as $name => $b ) {
		register_block_type(
			'gend-society/' . $name,
			array(
				'api_version'     => 3,
				'title'           => $b['title'],
				'category'        => 'widgets',
				'editor_script'   => 'gend-society-activity-blocks',
				'render_callback' => $b['render'],
				'attributes'      => $b['attrs'],
				'supports'        => $supports,
			)
		);
	}
}

/**
 * Whether the wall's own templates and functions are here.
 *
 * @return bool
 */
function gend_society_ab_ready() {
	return function_exists( 'bp_is_active' ) && bp_is_active( 'activity' ) && function_exists( 'bp_get_template_part' );
}

/**
 * Placeholder shown where a part can't render (no activity component).
 *
 * @param string $title Part.
 * @return string
 */
function gend_society_ab_placeholder( $title ) {
	return '<div class="gdc-ps-placeholder"><strong>' . esc_html( $title ) . '</strong><span>' . esc_html__( 'Shown on the activity feed page.', 'gend-society' ) . '</span></div>';
}

/**
 * The wall's page wrapper around a whole activity layout (its scripts and styles look for it).
 *
 * @param string $html Rendered layout.
 * @return string
 */
function gend_society_ab_wrap( $html ) {
	$class = function_exists( 'youzify_get_activity_page_class' ) ? youzify_get_activity_page_class() : '';
	return '<div id="youzify"><div id="youzify-bp" class="youzify youzify-page noLightbox gdc-activity-wall ' . esc_attr( $class ) . '"><div class="youzify-content"><main class="youzify-page-main-content">' . $html . '</main></div></div></div>';
}

// The parts fire the wall's own BuddyPress / Youzify hooks, where the wall fires them.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

/**
 * Activity Filter Bar.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ab_block_tabs( $attrs ) {
	if ( ! gend_society_ab_ready() ) {
		return gend_society_ab_placeholder( __( 'Activity Filter Bar', 'gend-society' ) );
	}
	$me   = (int) bp_loggedin_user_id();
	$base = $me ? trailingslashit( bp_loggedin_user_domain() ) . bp_get_activity_slug() . '/' : '';
	$tab  = static function ( $id, $url, $label, $count, $selected = false ) {
		return '<li id="activity-' . esc_attr( $id ) . '"' . ( $selected ? ' class="selected"' : '' ) . '><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ( '' !== $count ? ' <span>' . esc_html( $count ) . '</span>' : '' ) . '</a></li>';
	};
	ob_start();
	echo '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-activity-tabs' ) ) . '>';
	// Small screens: the wall shows these buttons instead of the tabs, filter and search.
	echo '<div class="youzify-mobile-nav"><div class="youzify-mobile-nav-item youzify-show-activity-menu"><div class="youzify-mobile-nav-container"><i class="fas fa-bars"></i><a>' . esc_html__( 'Menu', 'gend-society' ) . '</a></div></div>';
	if ( ! empty( $attrs['showSearch'] ) ) {
		echo '<div class="youzify-mobile-nav-item youzify-show-activity-search"><div class="youzify-mobile-nav-container"><i class="fas fa-search"></i><a>' . esc_html__( 'Search', 'gend-society' ) . '</a></div></div>';
	}
	if ( ! empty( $attrs['showFilter'] ) ) {
		echo '<div class="youzify-mobile-nav-item youzify-show-activity-filter"><div class="youzify-mobile-nav-container"><i class="fas fa-sliders-h"></i><a>' . esc_html__( 'Filter', 'gend-society' ) . '</a></div></div>';
	}
	echo '</div><div id="youzify-wall-nav">';
	echo '<div class="item-list-tabs activity-type-tabs" aria-label="' . esc_attr__( 'Sitewide activities navigation', 'gend-society' ) . '" role="navigation"><ul>';
	do_action( 'bp_before_activity_type_tab_all' );
	echo $tab( 'all', bp_get_activity_directory_permalink(), __( 'All Members', 'gend-society' ), number_format_i18n( bp_get_total_member_count() ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $tab.
	if ( $me ) {
		do_action( 'bp_before_activity_type_tab_friends' );
		if ( bp_is_active( 'friends' ) && bp_get_total_friend_count( $me ) ) {
			echo $tab( 'friends', $base . bp_get_friends_slug() . '/', __( 'My Friends', 'gend-society' ), number_format_i18n( bp_get_total_friend_count( $me ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $tab.
		}
		do_action( 'bp_before_activity_type_tab_groups' );
		if ( bp_is_active( 'groups' ) && bp_get_total_group_count_for_user( $me ) ) {
			echo $tab( 'groups', $base . bp_get_groups_slug() . '/', __( 'My Groups', 'gend-society' ), number_format_i18n( bp_get_total_group_count_for_user( $me ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $tab.
		}
		do_action( 'bp_before_activity_type_tab_favorites' );
		if ( bp_get_total_favorite_count_for_user( $me ) ) {
			echo $tab( 'favorites', $base . 'favorites/', __( 'My Favorites', 'gend-society' ), number_format_i18n( bp_get_total_favorite_count_for_user( $me ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $tab.
		}
		if ( bp_activity_do_mentions() ) {
			do_action( 'bp_before_activity_type_tab_mentions' );
			$new = (int) bp_get_total_mention_count_for_user( $me );
			/* translators: %s: number of new mentions */
			echo $tab( 'mentions', $base . 'mentions/', __( 'Mentions', 'gend-society' ), $new ? sprintf( __( '%s new', 'gend-society' ), number_format_i18n( $new ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $tab.
		}
	}
	do_action( 'bp_activity_type_tabs' );
	echo '</ul></div>';
	if ( ! empty( $attrs['showFilter'] ) || ! empty( $attrs['showSearch'] ) ) {
		echo '<div class="item-list-tabs activity-type-tabs-subnav no-ajax" id="subnav" aria-label="' . esc_attr__( 'Activity secondary navigation', 'gend-society' ) . '" role="navigation"><ul>';
		do_action( 'bp_activity_syndication_options' );
		if ( ! empty( $attrs['showFilter'] ) ) {
			echo '<li id="activity-filter-select" class="last"><div class="youzify-activity-show-filter"><i class="fas fa-sliders-h"></i></div><div class="youzify-dropdown-area"><label for="activity-filter-by">' . esc_html__( 'Filter Activities By :', 'gend-society' ) . '</label><select id="activity-filter-by" class="youzify-bar-select"><option value="-1">' . esc_html__( '— Everything —', 'gend-society' ) . '</option>';
			bp_activity_show_filters();
			do_action( 'bp_activity_filter_options' );
			echo '</select></div></li>';
		}
		if ( ! empty( $attrs['showSearch'] ) ) {
			echo '<li class="youzify-activity-show-search"><div class="youzify-activity-show-search-form"><i class="fas fa-search"></i></div><div class="youzify-dropdown-area"><div class="youzify-activity-search"><form action="' . esc_url( bp_get_activity_directory_permalink() ) . '"><input type="text" name="s" placeholder="' . esc_attr__( 'Search Activities ...', 'gend-society' ) . '"><button aria-label="' . esc_attr__( 'Search', 'gend-society' ) . '"><i class="fas fa-search"></i></button></form></div></div></li>';
		}
		echo '</ul></div>';
	}
	echo '</div>';
	do_action( 'youzify_after_activity_stream_tabs' );
	echo '</div>';
	return (string) ob_get_clean();
}

/**
 * Activity Post Form.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ab_block_post_form( $attrs ) {
	if ( ! gend_society_ab_ready() ) {
		return gend_society_ab_placeholder( __( 'Activity Post Form', 'gend-society' ) );
	}
	if ( ! is_user_logged_in() ) {
		if ( empty( $attrs['loginPrompt'] ) ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-activity-login' ) ) . '><p>' . esc_html__( 'Sign in to post to the feed.', 'gend-society' ) . '</p><a class="wp-element-button" href="' . esc_url( wp_login_url( bp_get_activity_directory_permalink() ) ) . '">' . esc_html__( 'Sign in', 'gend-society' ) . '</a></div>';
	}
	ob_start();
	bp_get_template_part( 'activity/post-form' );
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-activity-post-form youzify-column-content' ) ) . '>' . ob_get_clean() . '</div>';
}

/**
 * Activity Feed.
 *
 * @return string
 */
function gend_society_ab_block_feed() {
	if ( ! gend_society_ab_ready() ) {
		return gend_society_ab_placeholder( __( 'Activity Feed', 'gend-society' ) );
	}
	ob_start();
	echo '<div id="template-notices" role="alert" aria-atomic="true">';
	do_action( 'template_notices' );
	echo '</div>';
	do_action( 'bp_before_directory_activity_list' );
	echo '<div class="activity" aria-live="polite" aria-atomic="true" aria-relevant="all">';
	bp_get_template_part( 'activity/activity-loop' );
	echo '</div>';
	do_action( 'bp_after_directory_activity_list' );
	do_action( 'bp_directory_activity_content' );
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-activity-feed youzify-column-content' ) ) . '>' . ob_get_clean() . '</div>';
}

/**
 * Activity Sidebar: the wall's widget area (Appearance › Widgets).
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ab_block_sidebar( $attrs ) {
	$left = isset( $attrs['side'] ) && 'left' === $attrs['side'];
	$area = $left ? 'youzify-wall-left-sidebar' : 'youzify-wall-sidebar';
	if ( ! is_active_sidebar( $area ) ) {
		$preview = function_exists( 'gend_society_pv_view' ) && 'block' === gend_society_pv_view();
		return $preview ? '<div class="gdc-ps-placeholder"><strong>' . esc_html__( 'Activity Sidebar', 'gend-society' ) . '</strong><span>' . esc_html__( 'No widgets in this area yet: add them in Appearance › Widgets.', 'gend-society' ) . '</span></div>' : '';
	}
	ob_start();
	do_action( $left ? 'youzify_before_wall_left_sidebar' : 'youzify_before_wall_right_sidebar' );
	dynamic_sidebar( $area );
	do_action( $left ? 'youzify_after_wall_left_sidebar' : 'youzify_after_wall_right_sidebar' );
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-activity-sidebar youzify-sidebar ' . ( $left ? 'youzify-left-sidebar' : 'youzify-right-sidebar' ) ) ) . '><div class="youzify-column-content">' . ob_get_clean() . '</div></div>';
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

/**
 * The activity layout a new "Activity feed" layout starts with: the wall in
 * two columns, feed on the left, widgets on the right.
 *
 * @return string
 */
function gend_society_ab_default_layout() {
	return '<!-- wp:columns {"className":"gdc-activity-columns"} --><div class="wp-block-columns gdc-activity-columns">'
		. '<!-- wp:column {"width":"68%"} --><div class="wp-block-column" style="flex-basis:68%">'
		. '<!-- wp:gend-society/activity-tabs /--><!-- wp:gend-society/activity-post-form /--><!-- wp:gend-society/activity-feed /-->'
		. '</div><!-- /wp:column -->'
		. '<!-- wp:column {"width":"32%"} --><div class="wp-block-column" style="flex-basis:32%">'
		. '<!-- wp:gend-society/activity-sidebar /-->'
		. '</div><!-- /wp:column --></div><!-- /wp:columns -->';
}

add_action( 'wp_enqueue_scripts', 'gend_society_ab_styles', 20 );
/**
 * Layout styles for the wall when it's built from blocks.
 */
function gend_society_ab_styles() {
	if ( ! function_exists( 'bp_is_activity_directory' ) || ! bp_is_activity_directory() ) {
		return;
	}
	wp_register_style( 'gend-society-activity-blocks', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-activity-blocks' );
	wp_add_inline_style( 'gend-society-activity-blocks', '.gdc-activity-wall .gdc-ps-placeholder{display:flex;flex-direction:column;gap:4px;padding:16px;border:1px dashed rgba(255,255,255,.2);border-radius:12px;color:#c6cfdf;font-size:13px;line-height:1.4}.gdc-activity-wall .gdc-activity-columns{gap:24px;align-items:flex-start}.gdc-activity-wall .gdc-activity-columns>.wp-block-column{min-width:0}.gdc-activity-wall .youzify-column-content{width:auto}.gdc-activity-wall .gdc-activity-sidebar{width:auto;float:none}.gdc-activity-login{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)}.gdc-activity-login p{margin:0}@media (max-width:781px){.gdc-activity-wall .gdc-activity-columns{flex-wrap:wrap}}' );
}
