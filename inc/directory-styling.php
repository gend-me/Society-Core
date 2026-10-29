<?php
/**
 * Directory Styling: block-built layouts for the Members directory, Groups
 * directory and Activity feed (stage 3 of moving Social off Youzify).
 *
 * - gdc_bp_layout posts (meta _gdc_layout_slug: members-directory,
 *   groups-directory, activity). A published layout replaces that page's
 *   content; a draft or no layout leaves the page as it is. Layouts are
 *   created as drafts from the Design Studio, so nothing changes on the site
 *   until the layout is published in the block editor.
 * - Blocks: Members Directory and Groups Directory (search, type filter,
 *   sort, card grid, pagination; no Youzify markup or styles) and Original
 *   Page Content (the page's existing output, e.g. the activity feed with
 *   its posting form and reactions).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The directory pages a layout can design: slug => [ label, default content ].
 *
 * @return array[]
 */
function gend_society_ds_layouts() {
	return array(
		'members-directory' => array(
			'label'   => __( 'Members directory', 'gend-society' ),
			'content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html__( 'Members', 'gend-society' ) . '</h1><!-- /wp:heading --><!-- wp:gend-society/members-directory /-->',
		),
		'groups-directory'  => array(
			'label'   => __( 'Groups directory', 'gend-society' ),
			'content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html__( 'Groups', 'gend-society' ) . '</h1><!-- /wp:heading --><!-- wp:gend-society/groups-directory /-->',
		),
		'activity'          => array(
			'label'   => __( 'Activity feed', 'gend-society' ),
			// The wall built from its parts (inc/activity-blocks.php), else the page as it is.
			'content' => function_exists( 'gend_society_ab_default_layout' ) ? gend_society_ab_default_layout() : '<!-- wp:gend-society/original-content /-->',
		),
		// The profile's right sidebar, built from Profile Widget blocks (today's widgets, in order).
		'profile-sidebar'   => array(
			'label'   => __( 'Profile sidebar', 'gend-society' ),
			'content' => function_exists( 'gend_society_pw_default_sidebar' ) ? gend_society_pw_default_sidebar() : '',
		),
		// The group's activity tab (Feed), built from the activity wall blocks.
		'group-feed'        => array(
			'label'   => __( 'Group feed', 'gend-society' ),
			'content' => '<!-- wp:gend-society/activity-tabs /--><!-- wp:gend-society/activity-post-form /--><!-- wp:gend-society/activity-feed /-->',
		),
		// The group sidebar, rebuilt from today's widgets (Group Administrators + Group Moderators).
		'group-sidebar'     => array(
			'label'   => __( 'Group sidebar', 'gend-society' ),
			'content' => '<!-- wp:gend-society/group-members {"role":"admins","title":"' . esc_attr__( 'Group Administrators', 'gend-society' ) . '"} /--><!-- wp:gend-society/group-members {"role":"mods","title":"' . esc_attr__( 'Group Moderators', 'gend-society' ) . '"} /-->',
		),
	);
}

add_action( 'init', 'gend_society_ds_register', 5 );
/**
 * Registers the layout post type and the directory blocks.
 */
function gend_society_ds_register() {
	$caps = array();
	foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ) as $cap ) {
		$caps[ $cap ] = 'edit_theme_options';
	}
	register_post_type(
		'gdc_bp_layout',
		array(
			'label'        => __( 'Directory Layouts', 'gend-society' ),
			'labels'       => array(
				'name'          => __( 'Directory Layouts', 'gend-society' ),
				'singular_name' => __( 'Directory Layout', 'gend-society' ),
				'edit_item'     => __( 'Edit Directory Layout', 'gend-society' ),
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'revisions', 'custom-fields' ),
			'capabilities' => $caps,
			'map_meta_cap' => false,
			'rewrite'      => false,
		)
	);

	$js = 'assets/directory-styling-blocks.js';
	wp_register_script( 'gend-society-directory-blocks', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_set_script_translations( 'gend-society-directory-blocks', 'gend-society' );

	$supports = array(
		'color'      => array(
			'text'       => true,
			'background' => true,
			'link'       => true,
		),
		'typography' => array( 'fontSize' => true ),
		'spacing'    => array(
			'margin'  => true,
			'padding' => true,
		),
		'align'      => array( 'wide', 'full' ),
	);
	$grid     = array(
		'columns'    => array(
			'type'    => 'number',
			'default' => 4,
		),
		'perPage'    => array(
			'type'    => 'number',
			'default' => 24,
		),
		'showSearch' => array(
			'type'    => 'boolean',
			'default' => true,
		),
		'showTypes'  => array(
			'type'    => 'boolean',
			'default' => true,
		),
		'showSort'   => array(
			'type'    => 'boolean',
			'default' => true,
		),
		'showMeta'   => array(
			'type'    => 'boolean',
			'default' => true,
		),
		'showButton' => array(
			'type'    => 'boolean',
			'default' => true,
		),
	);
	register_block_type(
		'gend-society/members-directory',
		array(
			'api_version'     => 3,
			'title'           => __( 'Members Directory', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-directory-blocks',
			'render_callback' => 'gend_society_ds_block_members',
			'attributes'      => $grid,
			'supports'        => $supports,
		)
	);
	register_block_type(
		'gend-society/groups-directory',
		array(
			'api_version'     => 3,
			'title'           => __( 'Groups Directory', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-directory-blocks',
			'render_callback' => 'gend_society_ds_block_groups',
			'attributes'      => array_merge(
				$grid,
				array(
					'columns' => array(
						'type'    => 'number',
						'default' => 3,
					),
				)
			),
			'supports'        => $supports,
		)
	);
	register_block_type(
		'gend-society/group-members',
		array(
			'api_version'     => 3,
			'title'           => __( 'Group Members', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-directory-blocks',
			'render_callback' => 'gend_society_ds_block_group_members',
			'attributes'      => array(
				'role'  => array(
					'type'    => 'string',
					'default' => 'admins',
				),
				'title' => array(
					'type'    => 'string',
					'default' => '',
				),
				'limit' => array(
					'type'    => 'number',
					'default' => 10,
				),
			),
			'supports'        => $supports,
		)
	);
	register_block_type(
		'gend-society/group-about',
		array(
			'api_version'     => 3,
			'title'           => __( 'Group About', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-directory-blocks',
			'render_callback' => 'gend_society_ds_block_group_about',
			'supports'        => $supports,
		)
	);
	register_block_type(
		'gend-society/original-sidebar',
		array(
			'api_version'     => 3,
			'title'           => __( 'Original Group Sidebar', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-directory-blocks',
			'render_callback' => 'gend_society_ds_block_original_sidebar',
			'supports'        => array( 'html' => false ),
		)
	);
	register_block_type(
		'gend-society/original-content',
		array(
			'api_version'     => 3,
			'title'           => __( 'Original Page Content', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-directory-blocks',
			'render_callback' => 'gend_society_ds_block_original',
			'supports'        => array( 'html' => false ),
		)
	);
}

/**
 * The published layout for a directory page, if any.
 *
 * @param string $slug Layout slug.
 * @return WP_Post|null
 */
function gend_society_ds_layout( $slug ) {
	static $cache = array();
	if ( array_key_exists( $slug, $cache ) ) {
		return $cache[ $slug ];
	}
	// Editors can see a draft layout on the real page: ?gs_layout_preview=<layout id>.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for site editors.
	$preview = isset( $_GET['gs_layout_preview'] ) ? absint( $_GET['gs_layout_preview'] ) : 0;
	if ( $preview && current_user_can( 'edit_theme_options' ) && 'gdc_bp_layout' === get_post_type( $preview ) && (string) get_post_meta( $preview, '_gdc_layout_slug', true ) === $slug ) {
		$cache[ $slug ] = get_post( $preview );
		return $cache[ $slug ];
	}
	$posts = get_posts(
		array(
			'post_type'      => 'gdc_bp_layout',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup on directory pages.
			'meta_key'       => '_gdc_layout_slug',
			'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	$cache[ $slug ] = $posts ? $posts[0] : null;
	return $cache[ $slug ];
}

/**
 * The layout post for a slug, created as a draft with its default content when missing.
 *
 * @param string $slug Layout slug.
 * @return int Post ID (0 when not allowed).
 */
function gend_society_ds_layout_id( $slug ) {
	$defs = gend_society_ds_layouts();
	if ( ! isset( $defs[ $slug ] ) || ! current_user_can( 'edit_theme_options' ) ) {
		return 0;
	}
	$existing = get_posts(
		array(
			'post_type'      => 'gdc_bp_layout',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one admin lookup.
			'meta_key'       => '_gdc_layout_slug',
			'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'gdc_bp_layout',
			'post_status'  => 'draft',
			'post_title'   => $defs[ $slug ]['label'],
			'post_content' => $defs[ $slug ]['content'],
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	update_post_meta( $id, '_gdc_layout_slug', $slug );
	return (int) $id;
}

/**
 * The directory page being shown, as a layout slug ('' when none).
 *
 * @return string
 */
function gend_society_ds_current_slug() {
	if ( function_exists( 'bp_is_members_directory' ) && bp_is_members_directory() ) {
		return 'members-directory';
	}
	if ( function_exists( 'bp_is_groups_directory' ) && bp_is_groups_directory() ) {
		return 'groups-directory';
	}
	if ( function_exists( 'bp_is_activity_directory' ) && bp_is_activity_directory() ) {
		return 'activity';
	}
	return '';
}

/**
 * The page's original content, kept for the Original Page Content block.
 *
 * @param string|null $set Content to store.
 * @return string
 */
function gend_society_ds_original( $set = null ) {
	static $content = '';
	if ( null !== $set ) {
		$content = $set;
	}
	return $content;
}

add_filter( 'the_content', 'gend_society_ds_take_over', 9999 );
/**
 * Replaces a directory page's content with its published layout.
 *
 * @param string $content Page content (the directory BuddyPress/Youzify built).
 * @return string
 */
function gend_society_ds_take_over( $content ) {
	static $running = false;
	if ( $running || is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( function_exists( 'gend_society_pv_view' ) && 'original' === gend_society_pv_view() ) {
		return $content; // Studio preview of the page as it is, without its layout.
	}
	$slug = gend_society_ds_current_slug();
	$tpl  = $slug ? gend_society_ds_layout( $slug ) : null;
	if ( ! $tpl ) {
		return $content;
	}
	$running = true;
	gend_society_ds_original( $content );
	$out     = do_blocks( $tpl->post_content );
	if ( 'activity' === $slug && function_exists( 'gend_society_ab_wrap' ) && false === strpos( $tpl->post_content, 'wp:gend-society/original-content' ) ) {
		$out = gend_society_ab_wrap( $out ); // The wall's scripts and styles look for its page wrapper.
	}
	$out     = '<div class="gdc-bp-layout gdc-bp-layout--' . esc_attr( $slug ) . '">' . $out . '</div>';
	$running = false;
	return $out;
}

/**
 * Original Page Content block.
 *
 * @return string
 */
function gend_society_ds_block_original() {
	$content = gend_society_ds_original();
	if ( '' === $content ) {
		return '<div class="gdc-ps-placeholder"><strong>' . esc_html__( 'Original page content', 'gend-society' ) . '</strong><span>' . esc_html__( 'What this page shows today (for the activity feed: posting, comments and reactions).', 'gend-society' ) . '</span></div>';
	}
	return $content;
}

/* ── Group sidebar ──────────────────────────────────────────────────────── */

add_action( 'youzify_group_sidebar', 'gend_society_ds_group_sidebar', 0 );
/**
 * A published Group sidebar layout replaces Youzify's group sidebar widgets.
 */
function gend_society_ds_group_sidebar() {
	/**
	 * Filters the group sidebar layout (Social Pages: the sidebar a page picked).
	 *
	 * @param WP_Post|null $tpl   Layout.
	 * @param string       $scope groups.
	 */
	$tpl = apply_filters( 'gend_society_sp_sidebar_layout', gend_society_ds_layout( 'group-sidebar' ), 'groups' );
	if ( ! $tpl ) {
		return;
	}
	remove_action( 'youzify_group_sidebar', 'youzify_get_groups_sidebar' );
	echo '<div class="gdc-bp-sidebar">' . do_blocks( $tpl->post_content ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks of an admin-authored layout.
}

/**
 * The group a sidebar block shows: the current group, or (in the editor) a sample group.
 *
 * @return int
 */
function gend_society_ds_block_group() {
	$gid = function_exists( 'bp_get_current_group_id' ) ? (int) bp_get_current_group_id() : 0;
	if ( ! $gid && function_exists( 'gend_society_pv_group_id' ) ) {
		$gid = gend_society_pv_group_id();
	}
	return $gid;
}

/**
 * Group Members block: administrators, moderators or members.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ds_block_group_members( $attrs ) {
	$gid = gend_society_ds_block_group();
	if ( ! $gid || ! function_exists( 'groups_get_group_admins' ) ) {
		return '';
	}
	$limit = max( 1, min( 48, (int) $attrs['limit'] ) );
	$ids   = array();
	if ( 'admins' === $attrs['role'] ) {
		$ids = wp_list_pluck( (array) groups_get_group_admins( $gid ), 'user_id' );
	} elseif ( 'mods' === $attrs['role'] ) {
		$ids = wp_list_pluck( (array) groups_get_group_mods( $gid ), 'user_id' );
	} elseif ( function_exists( 'groups_get_group_members' ) ) {
		$res = groups_get_group_members(
			array(
				'group_id' => $gid,
				'per_page' => $limit,
			)
		);
		$ids = isset( $res['members'] ) ? wp_list_pluck( $res['members'], 'ID' ) : array();
	}
	$ids = array_slice( array_map( 'intval', $ids ), 0, $limit );
	if ( ! $ids ) {
		return '';
	}
	$items = '';
	foreach ( $ids as $uid ) {
		$url    = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : '';
		$av     = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar(
			array(
				'item_id' => $uid,
				'type'    => 'thumb',
				'html'    => false,
			)
		) : get_avatar_url( $uid );
		$name   = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		$items .= '<li><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $av ) . '" alt="" loading="lazy"><span>' . esc_html( $name ) . '</span></a></li>';
	}
	$title = '' !== trim( (string) $attrs['title'] ) ? '<h3 class="gdc-gsb-title">' . esc_html( $attrs['title'] ) . '</h3>' : '';
	return '<section ' . get_block_wrapper_attributes( array( 'class' => 'gdc-gsb gdc-gsb--members' ) ) . '>' . $title . '<ul>' . $items . '</ul></section>';
}

/**
 * Group About block.
 *
 * @return string
 */
function gend_society_ds_block_group_about() {
	$gid   = gend_society_ds_block_group();
	$group = $gid && function_exists( 'groups_get_group' ) ? groups_get_group( $gid ) : null;
	if ( ! $group || empty( $group->id ) ) {
		return '';
	}
	$count  = (int) groups_get_groupmeta( $gid, 'total_member_count' );
	$status = array(
		'public'  => __( 'Public group', 'gend-society' ),
		'private' => __( 'Private group', 'gend-society' ),
		'hidden'  => __( 'Hidden group', 'gend-society' ),
	);
	$meta   = '<li>' . esc_html( isset( $status[ $group->status ] ) ? $status[ $group->status ] : $group->status ) . '</li>'
		/* translators: %s: number of members. */
		. '<li>' . esc_html( sprintf( _n( '%s member', '%s members', $count, 'gend-society' ), number_format_i18n( $count ) ) ) . '</li>'
		/* translators: %s: date the group was created. */
		. '<li>' . esc_html( sprintf( __( 'Since %s', 'gend-society' ), mysql2date( get_option( 'date_format' ), $group->date_created ) ) ) . '</li>';
	return '<section ' . get_block_wrapper_attributes( array( 'class' => 'gdc-gsb gdc-gsb--about' ) ) . '><h3 class="gdc-gsb-title">' . esc_html__( 'About', 'gend-society' ) . '</h3>'
		. ( '' !== trim( (string) $group->description ) ? '<div class="gdc-gsb-desc">' . wp_kses_post( wpautop( $group->description ) ) . '</div>' : '' )
		. '<ul class="gdc-gsb-meta">' . $meta . '</ul></section>';
}

/**
 * Original Group Sidebar block: Youzify's group sidebar widgets as they are.
 *
 * @return string
 */
function gend_society_ds_block_original_sidebar() {
	if ( ! function_exists( 'gend_sn_groups_sidebar' ) || ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) {
		return '<div class="gdc-ps-placeholder"><strong>' . esc_html__( 'Current group sidebar', 'gend-society' ) . '</strong><span>' . esc_html__( 'Youzify’s group sidebar widgets, as they are today.', 'gend-society' ) . '</span></div>';
	}
	ob_start();
	gend_sn_groups_sidebar();
	return (string) ob_get_clean();
}

/* ── Directory blocks ───────────────────────────────────────────────────── */

/**
 * A request parameter for the directory blocks (namespaced, read-only).
 *
 * @param string $key Parameter.
 * @return string
 */
function gend_society_ds_param( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only directory filters.
	return isset( $_GET[ 'gsd_' . $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'gsd_' . $key ] ) ) : '';
}

/**
 * Search box, type chips and sort select shared by both directories.
 *
 * @param array  $attrs Block attributes.
 * @param array  $types slug => label.
 * @param array  $sorts value => label.
 * @param string $placeholder Search placeholder.
 * @return string
 */
function gend_society_ds_toolbar( $attrs, $types, $sorts, $placeholder ) {
	$s    = gend_society_ds_param( 's' );
	$type = gend_society_ds_param( 'type' );
	$sort = gend_society_ds_param( 'sort' );
	$html = '<form class="gdc-dir-toolbar" method="get" role="search">';
	if ( ! empty( $attrs['showSearch'] ) ) {
		$html .= '<input type="search" name="gsd_s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr( $placeholder ) . '">';
	}
	if ( ! empty( $attrs['showSort'] ) ) {
		$html .= '<select name="gsd_sort" aria-label="' . esc_attr__( 'Sort', 'gend-society' ) . '" onchange="this.form.submit()">';
		foreach ( $sorts as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $sort, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
	}
	if ( '' !== $type ) {
		$html .= '<input type="hidden" name="gsd_type" value="' . esc_attr( $type ) . '">';
	}
	$html .= '<button type="submit" class="wp-element-button">' . esc_html__( 'Search', 'gend-society' ) . '</button></form>';
	if ( ! empty( $attrs['showTypes'] ) && $types ) {
		$base  = remove_query_arg( array( 'gsd_type', 'gsd_page' ) );
		$html .= '<nav class="gdc-dir-types" aria-label="' . esc_attr__( 'Filter by type', 'gend-society' ) . '"><a class="' . ( '' === $type ? 'is-active' : '' ) . '" href="' . esc_url( $base ) . '">' . esc_html__( 'All', 'gend-society' ) . '</a>';
		foreach ( $types as $slug => $label ) {
			$html .= '<a class="' . ( $type === $slug ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'gsd_type', $slug, $base ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</nav>';
	}
	return $html;
}

/**
 * Previous / next page links.
 *
 * @param int $page  Current page.
 * @param int $total Total items.
 * @param int $per   Per page.
 * @return string
 */
function gend_society_ds_pager( $page, $total, $per ) {
	$pages = (int) ceil( $total / max( 1, $per ) );
	if ( $pages < 2 ) {
		return '';
	}
	$html = '<nav class="gdc-dir-pager" aria-label="' . esc_attr__( 'Pages', 'gend-society' ) . '">';
	if ( $page > 1 ) {
		$html .= '<a href="' . esc_url( add_query_arg( 'gsd_page', $page - 1 ) ) . '">' . esc_html__( '← Previous', 'gend-society' ) . '</a>';
	}
	/* translators: 1: current page, 2: number of pages. */
	$html .= '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'gend-society' ), $page, $pages ) ) . '</span>';
	if ( $page < $pages ) {
		$html .= '<a href="' . esc_url( add_query_arg( 'gsd_page', $page + 1 ) ) . '">' . esc_html__( 'Next →', 'gend-society' ) . '</a>';
	}
	return $html . '</nav>';
}

/**
 * Members Directory block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ds_block_members( $attrs ) {
	if ( ! class_exists( 'BP_User_Query' ) ) {
		return '';
	}
	$per   = max( 1, min( 96, (int) $attrs['perPage'] ) );
	$page  = max( 1, (int) gend_society_ds_param( 'page' ) );
	$types = function_exists( 'gend_society_ps_member_type_options' ) ? gend_society_ps_member_type_options() : array();
	$sorts = array(
		'active'       => __( 'Recently active', 'gend-society' ),
		'newest'       => __( 'Newest', 'gend-society' ),
		'alphabetical' => __( 'A–Z', 'gend-society' ),
	);
	$sort  = gend_society_ds_param( 'sort' );
	$type  = gend_society_ds_param( 'type' );
	$args  = array(
		'type'            => isset( $sorts[ $sort ] ) ? $sort : 'active',
		'per_page'        => $per,
		'page'            => $page,
		'search_terms'    => gend_society_ds_param( 's' ),
		'populate_extras' => true,
	);
	if ( '' !== $type && isset( $types[ $type ] ) ) {
		$args['member_type'] = $type;
	}
	$q     = new BP_User_Query( $args );
	$cards = '';
	foreach ( (array) $q->results as $user ) {
		$uid   = (int) $user->ID;
		$url   = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : bp_core_get_user_domain( $uid );
		$av    = bp_core_fetch_avatar(
			array(
				'item_id' => $uid,
				'type'    => 'full',
				'html'    => false,
			)
		);
		$mtype = function_exists( 'bp_get_member_type' ) ? bp_get_member_type( $uid ) : '';
		$meta  = '';
		if ( ! empty( $attrs['showMeta'] ) ) {
			$last  = function_exists( 'bp_get_last_activity' ) ? bp_get_last_activity( $uid ) : '';
			$meta .= ( $mtype && isset( $types[ $mtype ] ) ? '<span class="gdc-dir-badge">' . esc_html( $types[ $mtype ] ) . '</span>' : '' );
			$meta .= $last ? '<span class="gdc-dir-meta">' . wp_kses_post( $last ) . '</span>' : '';
		}
		$button = ! empty( $attrs['showButton'] ) && is_user_logged_in() && get_current_user_id() !== $uid && function_exists( 'bp_get_add_friend_button' ) ? (string) bp_get_add_friend_button( $uid ) : '';
		$cards .= '<article class="gdc-dir-card"><a class="gdc-dir-avatar" href="' . esc_url( $url ) . '"><img src="' . esc_url( $av ) . '" alt="" loading="lazy"></a>'
			. '<a class="gdc-dir-name" href="' . esc_url( $url ) . '">' . esc_html( $user->display_name ) . '</a>' . $meta
			. ( $button ? '<div class="gdc-dir-action">' . $button . '</div>' : '' ) . '</article>';
	}
	if ( '' === $cards ) {
		$cards = '<p class="gdc-dir-empty">' . esc_html__( 'No members found.', 'gend-society' ) . '</p>';
	}
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-dir gdc-dir--members' ) ) . ' style="--gdc-dir-cols:' . esc_attr( max( 1, min( 6, (int) $attrs['columns'] ) ) ) . '">'
		. gend_society_ds_toolbar( $attrs, $types, $sorts, __( 'Search members…', 'gend-society' ) )
		. '<div class="gdc-dir-grid">' . $cards . '</div>'
		. gend_society_ds_pager( $page, (int) $q->total_users, $per ) . '</div>';
}

/**
 * Groups Directory block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ds_block_groups( $attrs ) {
	if ( ! class_exists( 'BP_Groups_Group' ) ) {
		return '';
	}
	$per   = max( 1, min( 96, (int) $attrs['perPage'] ) );
	$page  = max( 1, (int) gend_society_ds_param( 'page' ) );
	$types = array();
	if ( function_exists( 'bp_groups_get_group_types' ) ) {
		foreach ( (array) bp_groups_get_group_types( array( 'show_in_list' => true ), 'objects' ) as $slug => $obj ) {
			$types[ $slug ] = isset( $obj->labels['singular_name'] ) ? $obj->labels['singular_name'] : $slug;
		}
	}
	$sorts = array(
		'active'       => __( 'Recently active', 'gend-society' ),
		'popular'      => __( 'Most members', 'gend-society' ),
		'newest'       => __( 'Newest', 'gend-society' ),
		'alphabetical' => __( 'A–Z', 'gend-society' ),
	);
	$sort  = gend_society_ds_param( 'sort' );
	$type  = gend_society_ds_param( 'type' );
	$args  = array(
		'type'         => isset( $sorts[ $sort ] ) ? $sort : 'active',
		'per_page'     => $per,
		'page'         => $page,
		'search_terms' => gend_society_ds_param( 's' ),
		'show_hidden'  => false,
	);
	if ( '' !== $type && isset( $types[ $type ] ) ) {
		$args['group_type'] = $type;
	}
	$res   = BP_Groups_Group::get( $args );
	$cards = '';
	foreach ( (array) $res['groups'] as $group ) {
		$url    = function_exists( 'bp_get_group_url' ) ? bp_get_group_url( $group ) : bp_get_group_permalink( $group );
		$av     = bp_core_fetch_avatar(
			array(
				'item_id' => $group->id,
				'object'  => 'group',
				'type'    => 'full',
				'html'    => false,
			)
		);
		$count  = isset( $group->total_member_count ) ? (int) $group->total_member_count : (int) groups_get_groupmeta( $group->id, 'total_member_count' );
		$meta   = ! empty( $attrs['showMeta'] )
			/* translators: %s: number of members. */
			? '<span class="gdc-dir-meta">' . esc_html( sprintf( _n( '%s member', '%s members', $count, 'gend-society' ), number_format_i18n( $count ) ) ) . '</span><p class="gdc-dir-desc">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $group->description ), 18 ) ) . '</p>'
			: '';
		$button = ! empty( $attrs['showButton'] ) && is_user_logged_in() && function_exists( 'bp_get_group_join_button' ) ? (string) bp_get_group_join_button( $group ) : '';
		$cards .= '<article class="gdc-dir-card"><a class="gdc-dir-avatar" href="' . esc_url( $url ) . '"><img src="' . esc_url( $av ) . '" alt="" loading="lazy"></a>'
			. '<a class="gdc-dir-name" href="' . esc_url( $url ) . '">' . esc_html( $group->name ) . '</a>' . $meta
			. ( $button ? '<div class="gdc-dir-action">' . $button . '</div>' : '' ) . '</article>';
	}
	if ( '' === $cards ) {
		$cards = '<p class="gdc-dir-empty">' . esc_html__( 'No groups found.', 'gend-society' ) . '</p>';
	}
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-dir gdc-dir--groups' ) ) . ' style="--gdc-dir-cols:' . esc_attr( max( 1, min( 6, (int) $attrs['columns'] ) ) ) . '">'
		. gend_society_ds_toolbar( $attrs, $types, $sorts, __( 'Search groups…', 'gend-society' ) )
		. '<div class="gdc-dir-grid">' . $cards . '</div>'
		. gend_society_ds_pager( $page, (int) $res['total'], $per ) . '</div>';
}

add_action( 'wp_enqueue_scripts', 'gend_society_ds_styles' );
add_action( 'enqueue_block_assets', 'gend_society_ds_editor_styles' );
/**
 * Directory block styles on directory pages.
 */
function gend_society_ds_styles() {
	$slug    = gend_society_ds_current_slug();
	$sidebar = function_exists( 'bp_is_group' ) && bp_is_group() && apply_filters( 'gend_society_sp_sidebar_layout', gend_society_ds_layout( 'group-sidebar' ), 'groups' );
	$sidebar = $sidebar || ( function_exists( 'bp_is_user' ) && bp_is_user() && function_exists( 'gend_society_sp_current' ) && gend_society_sp_current() && in_array( gend_society_sp_current()['layout'], array( 'left', 'right' ), true ) );
	if ( ! $sidebar && ( '' === $slug || ! gend_society_ds_layout( $slug ) ) ) {
		return; // Only pages that use a published layout need these styles.
	}
	wp_register_style( 'gend-society-directory-blocks', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-directory-blocks' );
	wp_add_inline_style( 'gend-society-directory-blocks', gend_society_ds_css() );
}

/**
 * The same styles in the block editor.
 */
function gend_society_ds_editor_styles() {
	if ( ! is_admin() ) {
		return;
	}
	wp_register_style( 'gend-society-directory-blocks-editor', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-directory-blocks-editor' );
	wp_add_inline_style( 'gend-society-directory-blocks-editor', gend_society_ds_css() );
}

/**
 * Directory CSS: layout only, colours follow the theme (currentColor + block settings).
 *
 * @return string
 */
function gend_society_ds_css() {
	// The GenD skin paints BuddyPress pages dark: light text by default (block colour settings still win).
	return '.gdc-bp-layout{max-width:1280px;margin:0 auto;padding:40px 24px 80px;color:#e8edf5}'
		. '.gdc-bp-layout :where(h1,h2,h3,h4,h5,h6,a){color:inherit}'
		. '.gdc-dir{display:flex;flex-direction:column;gap:20px}'
		. '.gdc-dir-toolbar{display:flex;flex-wrap:wrap;gap:10px;margin:0}'
		. '.gdc-dir-toolbar input[type=search]{flex:1 1 240px;min-height:44px;padding:0 14px;border:1px solid rgba(127,127,127,.35);border-radius:10px;background:transparent;color:inherit;font:inherit}'
		. '.gdc-dir-toolbar select{min-height:44px;padding:0 12px;border:1px solid rgba(127,127,127,.35);border-radius:10px;background:transparent;color:inherit;font:inherit}'
		. '.gdc-dir-types{display:flex;flex-wrap:wrap;gap:8px}'
		. '.gdc-dir-types a{padding:6px 14px;border-radius:99px;border:1px solid rgba(127,127,127,.35);text-decoration:none;color:inherit;font-size:.9em}'
		. '.gdc-dir-types a.is-active,.gdc-dir-types a:hover{border-color:currentColor}'
		. '.gdc-dir-grid{display:grid;grid-template-columns:repeat(var(--gdc-dir-cols,4),minmax(0,1fr));gap:18px}'
		. '@media(max-width:900px){.gdc-dir-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.gdc-dir-grid{grid-template-columns:1fr}}'
		. '.gdc-dir-card{display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;padding:22px 16px;border-radius:16px;border:1px solid rgba(127,127,127,.22);background:rgba(127,127,127,.06)}'
		. '.gdc-dir-avatar img{width:88px;height:88px;border-radius:50%;object-fit:cover;display:block}'
		. '.gdc-dir--groups .gdc-dir-avatar img{border-radius:18px}'
		. '.gdc-dir-name{font-weight:700;font-size:1.05em;text-decoration:none;color:inherit}'
		. '.gdc-dir-badge{display:inline-block;padding:2px 10px;border-radius:99px;border:1px solid currentColor;font-size:.72em;letter-spacing:.08em;text-transform:uppercase}'
		. '.gdc-dir-meta{opacity:.7;font-size:.85em}'
		. '.gdc-dir-desc{margin:0;opacity:.8;font-size:.9em;line-height:1.45}'
		. '.gdc-dir-action{margin-top:auto;padding-top:6px}'
		. '.gdc-dir-action a,.gdc-dir-action button{display:inline-block;padding:8px 16px;border-radius:99px;border:1px solid currentColor;background:transparent;color:inherit;text-decoration:none;font:inherit;font-size:.85em;cursor:pointer}'
		. '.gdc-dir-pager{display:flex;justify-content:center;align-items:center;gap:16px}'
		. '.gdc-dir-pager a{text-decoration:none}'
		. '.gdc-dir-empty{opacity:.7}'
		. '.gdc-bp-sidebar{display:flex;flex-direction:column;gap:16px;color:#e8edf5}'
		. '.gdc-gsb{padding:18px;border-radius:14px;border:1px solid rgba(127,127,127,.22);background:rgba(127,127,127,.06)}'
		. '.gdc-gsb-title{margin:0 0 12px!important;font-size:1em!important;letter-spacing:.04em}'
		. '.gdc-gsb ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}'
		. '.gdc-gsb--members a{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit}'
		. '.gdc-gsb--members img{width:36px;height:36px;border-radius:50%;object-fit:cover}'
		. '.gdc-gsb-desc{opacity:.85;font-size:.92em;margin-bottom:10px}'
		. '.gdc-gsb-meta li{opacity:.7;font-size:.85em}';
}
