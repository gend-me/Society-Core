<?php
/**
 * Profile Styling: block-built member profile headers, tab layouts and menu,
 * the member-profile counterpart of the Group Styling system (Projects plugin).
 *
 * - gdc_profile_header: header templates. One is the site default; others can
 *   be the default for one or more member types. The first resolved template
 *   replaces the profile header. The bootstrapped site default holds a single
 *   "GenD Profile Header" block, which renders today's header unchanged.
 * - gdc_profile_tab: a layout for one profile tab (by nav slug), site default
 *   or per member type, wrapped around the "Profile tab content" block. A tab
 *   without a layout renders exactly as before.
 * - Menu: site-wide label / order / hidden per profile tab (option
 *   gend_society_profile_nav), applied to the header's tab bar and the
 *   Profile Menu block.
 *
 * The builder UI (header templates, assignments, tab layouts, menu) is the
 * Design Studio's Profile Styling page (gs-studio-profile-styling); every
 * "Edit" opens the real block editor.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_PS_NAV_OPTION  = 'gend_society_profile_nav';
const GEND_SOCIETY_PS_SEEN_OPTION = 'gend_society_profile_nav_seen';

add_action( 'init', 'gend_society_ps_register_post_types', 5 );
/**
 * Registers the header template and tab layout post types (block editor, admins only).
 */
function gend_society_ps_register_post_types() {
	$caps = array();
	foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ) as $cap ) {
		$caps[ $cap ] = 'edit_theme_options';
	}
	$common = array(
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => false,
		'show_in_rest' => true,
		'supports'     => array( 'title', 'editor', 'revisions', 'custom-fields' ),
		'capabilities' => $caps,
		'map_meta_cap' => false,
		'rewrite'      => false,
	);
	register_post_type(
		'gdc_profile_header',
		array_merge(
			$common,
			array(
				'label'  => __( 'Profile Headers', 'gend-society' ),
				'labels' => array(
					'name'          => __( 'Profile Headers', 'gend-society' ),
					'singular_name' => __( 'Profile Header', 'gend-society' ),
					'edit_item'     => __( 'Edit Profile Header', 'gend-society' ),
				),
			)
		)
	);
	register_post_type(
		'gdc_profile_tab',
		array_merge(
			$common,
			array(
				'label'  => __( 'Profile Tab Layouts', 'gend-society' ),
				'labels' => array(
					'name'          => __( 'Profile Tab Layouts', 'gend-society' ),
					'singular_name' => __( 'Profile Tab Layout', 'gend-society' ),
					'edit_item'     => __( 'Edit Profile Tab Layout', 'gend-society' ),
				),
			)
		)
	);
}

/**
 * The member types a member belongs to.
 *
 * @param int $user_id User ID.
 * @return string[]
 */
function gend_society_ps_member_types( $user_id ) {
	if ( ! $user_id || ! function_exists( 'bp_get_member_type' ) ) {
		return array();
	}
	$types = bp_get_member_type( $user_id, false );
	return is_array( $types ) ? array_values( array_filter( $types ) ) : array();
}

/**
 * All registered member types, slug => label.
 *
 * @return array<string,string>
 */
function gend_society_ps_member_type_options() {
	$out = array();
	if ( function_exists( 'bp_get_member_types' ) ) {
		foreach ( (array) bp_get_member_types( array(), 'objects' ) as $slug => $obj ) {
			$out[ $slug ] = isset( $obj->labels['singular_name'] ) ? $obj->labels['singular_name'] : $slug;
		}
	}
	return $out;
}

/**
 * Resolves the template for a member: a member-type default first, then the site default.
 *
 * @param string $post_type gdc_profile_header or gdc_profile_tab.
 * @param int    $user_id   The profile's member.
 * @param string $tab       Tab slug (gdc_profile_tab only).
 * @return WP_Post|null
 */
function gend_society_ps_resolve( $post_type, $user_id, $tab = '' ) {
	static $cache = array();
	$key          = $post_type . '|' . $user_id . '|' . $tab;
	if ( array_key_exists( $key, $cache ) ) {
		return $cache[ $key ];
	}
	$base = array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'no_found_rows'  => true,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	if ( '' !== $tab ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tiny admin-authored set, cached per request.
		$base['meta_query'] = array(
			array(
				'key'   => '_gdc_tab_slug',
				'value' => $tab,
			),
		);
	}
	$found = null;
	$posts = get_posts( $base );
	$types = gend_society_ps_member_types( $user_id );
	foreach ( $posts as $p ) {
		$assigned = array_filter( explode( ',', (string) get_post_meta( $p->ID, '_gdc_member_types', true ) ) );
		if ( $types && array_intersect( $types, $assigned ) ) {
			$found = $p;
			break;
		}
	}
	if ( ! $found ) {
		foreach ( $posts as $p ) {
			if ( get_post_meta( $p->ID, '_gdc_is_site_default', true ) ) {
				$found = $p;
				break;
			}
		}
	}
	/**
	 * Filters the template a member's profile gets (Social Pages: the viewer's page for a tab).
	 *
	 * @param WP_Post|null $found     Template.
	 * @param string       $post_type gdc_profile_header or gdc_profile_tab.
	 * @param int          $user_id   Profile member.
	 * @param string       $tab       Tab slug.
	 */
	$found         = apply_filters( 'gend_society_ps_resolve', $found, $post_type, $user_id, $tab );
	$cache[ $key ] = $found;
	return $found;
}

/**
 * The site-default header template, created on first use with today's header.
 *
 * @return int Post ID (0 when it cannot be created).
 */
function gend_society_ps_default_header_id() {
	$existing = get_posts(
		array(
			'post_type'      => 'gdc_profile_header',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one admin lookup.
			'meta_key'       => '_gdc_is_site_default',
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return 0;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'gdc_profile_header',
			'post_status'  => 'publish',
			'post_title'   => __( 'Default — Profile Header', 'gend-society' ),
			'post_content' => '<!-- wp:gend-society/profile-header-classic /-->',
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	update_post_meta( $id, '_gdc_is_site_default', '1' );
	return (int) $id;
}

/* ── Rendering ──────────────────────────────────────────────────────────── */

// The resolved template takes over the profile header; with none, today's header renders.
if ( function_exists( 'gend_society_render_profile_header' ) ) {
	remove_action( 'youzify_profile_before_header', 'gend_society_render_profile_header', 1 );
}
add_action( 'youzify_profile_before_header', 'gend_society_ps_render_header', 1 );
/**
 * Renders the member's profile header template.
 */
function gend_society_ps_render_header() {
	if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return;
	}
	$tpl = gend_society_ps_resolve( 'gdc_profile_header', (int) bp_displayed_user_id() );
	if ( $tpl ) {
		echo do_blocks( $tpl->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks of an admin-authored template.
		return;
	}
	if ( function_exists( 'gend_society_render_profile_header' ) ) {
		gend_society_render_profile_header();
	}
}

/**
 * The tab content captured for the current request's tab layout.
 *
 * @param string|null $set New content to store.
 * @return string
 */
function gend_society_ps_tab_content( $set = null ) {
	static $content = '';
	if ( null !== $set ) {
		$content = $set;
	}
	return $content;
}

add_action( 'bp_before_member_body', 'gend_society_ps_tab_open', 0 );
/**
 * Starts capturing the tab body when the tab has a layout.
 */
function gend_society_ps_tab_open() {
	if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return;
	}
	$tab = sanitize_key( (string) bp_current_component() );
	$tpl = $tab ? gend_society_ps_resolve( 'gdc_profile_tab', (int) bp_displayed_user_id(), $tab ) : null;
	if ( ! $tpl ) {
		return;
	}
	$GLOBALS['gend_society_ps_tab_tpl'] = $tpl;
	ob_start();
}

add_action( 'bp_after_member_body', 'gend_society_ps_tab_close', PHP_INT_MAX );
/**
 * Places the captured tab body into the tab layout.
 */
function gend_society_ps_tab_close() {
	if ( empty( $GLOBALS['gend_society_ps_tab_tpl'] ) ) {
		return;
	}
	$tpl = $GLOBALS['gend_society_ps_tab_tpl'];
	unset( $GLOBALS['gend_society_ps_tab_tpl'] );
	gend_society_ps_tab_content( (string) ob_get_clean() );
	$tab = sanitize_html_class( (string) get_post_meta( $tpl->ID, '_gdc_tab_slug', true ) );
	// Keep the tab's own wrapper classes, so its styles still apply to the blocks.
	/**
	 * Filters a tab layout's output (Social Pages: a sidebar beside it).
	 *
	 * @param string $html Rendered layout.
	 * @param string $tab  Tab slug.
	 */
	echo apply_filters( 'gend_society_ps_tab_output', '<div class="gdc-profile-tab-layout youzify-tab youzify-' . esc_attr( $tab ) . '">' . do_blocks( $tpl->post_content ) . '</div>', $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks of an admin-authored template.
}

/* ── Menu (label / order / hidden) ──────────────────────────────────────── */

add_filter( 'gend_society_profile_nav_items', 'gend_society_ps_apply_nav', 10, 2 );
/**
 * Applies the saved menu settings to the profile tab bar, and remembers the
 * tabs seen so the builder can list them.
 *
 * @param object[] $items   Nav items (slug, name, link).
 * @param int      $user_id Profile member.
 * @return object[]
 */
function gend_society_ps_apply_nav( $items, $user_id = 0 ) {
	unset( $user_id );
	$seen = array();
	foreach ( $items as $it ) {
		if ( isset( $it->slug ) ) {
			$seen[ $it->slug ] = wp_strip_all_tags( preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) $it->name ) );
		}
	}
	$stored = get_option( GEND_SOCIETY_PS_SEEN_OPTION, array() );
	if ( is_array( $stored ) && array_diff_assoc( $seen, $stored ) ) {
		update_option( GEND_SOCIETY_PS_SEEN_OPTION, array_merge( $stored, $seen ), false );
	}

	$config = get_option( GEND_SOCIETY_PS_NAV_OPTION, array() );
	if ( ! is_array( $config ) || ! $config ) {
		return $items;
	}
	$out = array();
	foreach ( $items as $i => $it ) {
		$c = isset( $it->slug, $config[ $it->slug ] ) ? $config[ $it->slug ] : array();
		if ( ! empty( $c['hidden'] ) ) {
			continue;
		}
		if ( ! empty( $c['label'] ) ) {
			// Keep a count badge (<span class="count">) after the new label.
			$badge    = preg_match( '#<span[^>]*>.*?</span>#s', (string) $it->name, $m ) ? ' ' . $m[0] : '';
			$it->name = esc_html( $c['label'] ) . $badge;
		}
		$it->_gs_order = isset( $c['order'] ) ? (int) $c['order'] : 1000 + $i;
		$out[]         = $it;
	}
	usort(
		$out,
		function ( $a, $b ) {
			return $a->_gs_order - $b->_gs_order;
		}
	);
	return $out;
}

/**
 * The profile tab bar items for a member (same source and order as the header).
 *
 * @return object[]
 */
function gend_society_ps_nav_items() {
	$uid = (int) ( function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : 0 );
	if ( function_exists( 'gend_society_profile_header_nav_items' ) ) {
		return gend_society_profile_header_nav_items( $uid ); // Same tabs as the GenD header (menu settings included).
	}
	$items = function_exists( 'youzify_get_profile_primary_nav' ) ? (array) youzify_get_profile_primary_nav() : array();
	return (array) apply_filters( 'gend_society_profile_nav_items', $items, $uid );
}

/* ── Blocks ─────────────────────────────────────────────────────────────── */

/**
 * The member a profile block shows: the displayed profile, or (in the editor) you.
 *
 * @return int
 */
function gend_society_ps_block_user() {
	$uid = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : 0;
	return $uid ? $uid : (int) get_current_user_id();
}

add_action( 'init', 'gend_society_ps_register_blocks', 20 );
/**
 * Registers the profile blocks (rendered on the server, edited with live previews).
 */
function gend_society_ps_register_blocks() {
	$js = 'assets/profile-styling-blocks.js';
	wp_register_script( 'gend-society-profile-blocks', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_set_script_translations( 'gend-society-profile-blocks', 'gend-society' );

	$supports = array(
		'color'      => array(
			'text'       => true,
			'background' => true,
			'link'       => true,
		),
		'typography' => array(
			'fontSize'   => true,
			'lineHeight' => true,
		),
		'spacing'    => array(
			'margin'  => true,
			'padding' => true,
		),
		'align'      => array( 'wide', 'full' ),
	);
	$blocks   = array(
		'profile-header-classic' => array(
			'title'    => __( 'GenD Profile Header', 'gend-society' ),
			'render'   => 'gend_society_ps_block_classic',
			'supports' => array( 'html' => false ),
		),
		'profile-cover'          => array(
			'title'      => __( 'Profile Cover', 'gend-society' ),
			'render'     => 'gend_society_ps_block_cover',
			'attributes' => array(
				'minHeight' => array(
					'type'    => 'number',
					'default' => 260,
				),
				'overlay'   => array(
					'type'    => 'number',
					'default' => 35,
				),
			),
		),
		'profile-avatar'         => array(
			'title'      => __( 'Profile Avatar', 'gend-society' ),
			'render'     => 'gend_society_ps_block_avatar',
			'attributes' => array(
				'size'  => array(
					'type'    => 'number',
					'default' => 120,
				),
				'round' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
		),
		'profile-name'           => array(
			'title'      => __( 'Profile Name', 'gend-society' ),
			'render'     => 'gend_society_ps_block_name',
			'attributes' => array(
				'level' => array(
					'type'    => 'number',
					'default' => 1,
				),
			),
		),
		'profile-handle'         => array(
			'title'  => __( 'Profile @Username', 'gend-society' ),
			'render' => 'gend_society_ps_block_handle',
		),
		'profile-member-type'    => array(
			'title'  => __( 'Profile Member Type', 'gend-society' ),
			'render' => 'gend_society_ps_block_member_type',
		),
		'profile-bio'            => array(
			'title'      => __( 'Profile Field', 'gend-society' ),
			'render'     => 'gend_society_ps_block_bio',
			'attributes' => array(
				'field' => array(
					'type'    => 'string',
					'default' => 'Bio',
				),
			),
		),
		'profile-actions'        => array(
			'title'  => __( 'Profile Actions', 'gend-society' ),
			'render' => 'gend_society_ps_block_actions',
		),
		'profile-nav'            => array(
			'title'  => __( 'Profile Menu', 'gend-society' ),
			'render' => 'gend_society_ps_block_nav',
		),
		'profile-tab-content'    => array(
			'title'    => __( 'Profile Tab Content', 'gend-society' ),
			'render'   => 'gend_society_ps_block_tab_content',
			'supports' => array( 'html' => false ),
		),
	);
	foreach ( $blocks as $name => $b ) {
		register_block_type(
			'gend-society/' . $name,
			array(
				'api_version'     => 3,
				'title'           => $b['title'],
				'category'        => 'theme',
				'editor_script'   => 'gend-society-profile-blocks',
				'render_callback' => $b['render'],
				'attributes'      => isset( $b['attributes'] ) ? $b['attributes'] : array(),
				'supports'        => isset( $b['supports'] ) ? $b['supports'] : $supports,
			)
		);
	}
}

/**
 * Wrapper attributes for a dynamic block (colours, spacing, typography from block supports).
 *
 * @param string $class Extra class.
 * @param array  $extra Extra attributes.
 * @return string
 */
function gend_society_ps_wrapper( $class, $extra = array() ) {
	return get_block_wrapper_attributes( array_merge( array( 'class' => $class ), $extra ) );
}

/**
 * GenD Profile Header: today's full header (identity, balances, groups, tab bar).
 *
 * @return string
 */
function gend_society_ps_block_classic() {
	if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() || ! function_exists( 'gend_society_render_profile_header' ) ) {
		return '<div class="gdc-ps-placeholder"><strong>' . esc_html__( 'GenD Profile Header', 'gend-society' ) . '</strong><span>' . esc_html__( 'The full GenD header: identity, balances, groups and the tab bar. Shown on member profiles.', 'gend-society' ) . '</span></div>';
	}
	ob_start();
	gend_society_render_profile_header();
	return (string) ob_get_clean();
}

/**
 * Profile Cover: the member's cover image as a container for other blocks.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content Inner blocks.
 * @return string
 */
function gend_society_ps_block_cover( $attrs, $content ) {
	$uid = gend_society_ps_block_user();
	$url = function_exists( 'bp_attachments_get_attachment' ) ? bp_attachments_get_attachment( 'url', array( 'object_dir' => 'members', 'item_id' => $uid ) ) : '';
	if ( ! $url && function_exists( 'youzify_option' ) ) {
		$url = youzify_option( 'youzify_default_profiles_cover', '' );
	}
	$style = sprintf(
		'min-height:%dpx;background-image:linear-gradient(rgba(0,0,0,%s),rgba(0,0,0,%s))%s;background-size:cover;background-position:center;display:flex;flex-direction:column;justify-content:flex-end;',
		max( 80, (int) $attrs['minHeight'] ),
		esc_attr( max( 0, min( 90, (int) $attrs['overlay'] ) ) / 100 ),
		esc_attr( max( 0, min( 90, (int) $attrs['overlay'] ) ) / 100 ),
		$url ? ',url(' . esc_url( $url ) . ')' : ''
	);
	return '<div ' . gend_society_ps_wrapper( 'gdc-ps-cover', array( 'style' => $style ) ) . '>' . $content . '</div>';
}

/**
 * Profile Avatar.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ps_block_avatar( $attrs ) {
	$uid  = gend_society_ps_block_user();
	$size = max( 24, min( 400, (int) $attrs['size'] ) );
	$url  = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar(
		array(
			'item_id' => $uid,
			'type'    => 'full',
			'html'    => false,
		)
	) : get_avatar_url( $uid, array( 'size' => $size * 2 ) );
	$radius = ! empty( $attrs['round'] ) ? '50%' : '16px';
	return '<div ' . gend_society_ps_wrapper( 'gdc-ps-avatar' ) . '><img src="' . esc_url( $url ) . '" alt="" width="' . esc_attr( $size ) . '" height="' . esc_attr( $size ) . '" style="width:' . esc_attr( $size ) . 'px;height:' . esc_attr( $size ) . 'px;object-fit:cover;border-radius:' . esc_attr( $radius ) . ';display:block"></div>';
}

/**
 * Profile Name.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ps_block_name( $attrs ) {
	$uid   = gend_society_ps_block_user();
	$level = max( 1, min( 6, (int) $attrs['level'] ) );
	$name  = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : get_the_author_meta( 'display_name', $uid );
	return '<h' . $level . ' ' . gend_society_ps_wrapper( 'gdc-ps-name wp-block-heading' ) . '>' . esc_html( $name ) . '</h' . $level . '>';
}

/**
 * Profile @Username.
 *
 * @return string
 */
function gend_society_ps_block_handle() {
	$user = get_userdata( gend_society_ps_block_user() );
	return $user ? '<p ' . gend_society_ps_wrapper( 'gdc-ps-handle' ) . '>@' . esc_html( $user->user_nicename ) . '</p>' : '';
}

/**
 * Profile Member Type badge.
 *
 * @return string
 */
function gend_society_ps_block_member_type() {
	$uid   = gend_society_ps_block_user();
	$label = __( 'Member', 'gend-society' );
	if ( function_exists( 'gend_society_user_is_agent' ) && gend_society_user_is_agent( $uid ) ) {
		$label = __( 'Agent', 'gend-society' );
	} else {
		$opts  = gend_society_ps_member_type_options();
		$types = gend_society_ps_member_types( $uid );
		if ( $types && isset( $opts[ $types[0] ] ) ) {
			$label = $opts[ $types[0] ];
		}
	}
	return '<p ' . gend_society_ps_wrapper( 'gdc-ps-member-type' ) . '><span>' . esc_html( $label ) . '</span></p>';
}

/**
 * Profile Field (Bio by default).
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_ps_block_bio( $attrs ) {
	$uid   = gend_society_ps_block_user();
	$value = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( (string) $attrs['field'], $uid, 'comma' ) : '';
	if ( '' === $value || null === $value ) {
		$value = get_the_author_meta( 'description', $uid );
	}
	if ( is_array( $value ) ) {
		$value = implode( ', ', $value );
	}
	if ( '' === trim( (string) $value ) ) {
		return '';
	}
	return '<div ' . gend_society_ps_wrapper( 'gdc-ps-field' ) . '>' . wp_kses_post( wpautop( (string) $value ) ) . '</div>';
}

/**
 * Profile Actions: connect / message buttons for visitors, edit for the member.
 *
 * @return string
 */
function gend_society_ps_block_actions() {
	$uid  = gend_society_ps_block_user();
	$url  = function ( $id, $chunks ) {
		if ( function_exists( 'bp_members_get_user_url' ) && function_exists( 'bp_members_get_path_chunks' ) ) {
			return bp_members_get_user_url( $id, bp_members_get_path_chunks( $chunks ) );
		}
		return function_exists( 'bp_core_get_user_domain' ) ? trailingslashit( bp_core_get_user_domain( $id ) ) . implode( '/', $chunks ) . '/' : '';
	};
	$html = '';
	if ( get_current_user_id() === $uid ) {
		$edit = $url( $uid, array( 'profile', 'edit' ) );
		if ( $edit ) {
			$html .= '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit profile', 'gend-society' ) . '</a></div>';
		}
	} else {
		if ( function_exists( 'bp_get_add_friend_button' ) ) {
			$html .= (string) bp_get_add_friend_button( $uid );
		}
		$user = get_userdata( $uid );
		if ( $user && is_user_logged_in() && function_exists( 'bp_is_active' ) && bp_is_active( 'messages' ) ) {
			$compose = $url( get_current_user_id(), array( 'messages', 'compose' ) );
			if ( $compose ) {
				$html .= '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . esc_url( add_query_arg( 'r', $user->user_nicename, $compose ) ) . '">' . esc_html__( 'Message', 'gend-society' ) . '</a></div>';
			}
		}
	}
	return '' === $html ? '' : '<div ' . gend_society_ps_wrapper( 'gdc-ps-actions wp-block-buttons' ) . '>' . $html . '</div>';
}

/**
 * Profile Menu: the profile's tabs, with the saved labels, order and hidden tabs.
 *
 * @return string
 */
function gend_society_ps_block_nav() {
	$items = function_exists( 'bp_is_user' ) && bp_is_user() ? gend_society_ps_nav_items() : array();
	if ( ! $items ) {
		$seen  = get_option( GEND_SOCIETY_PS_SEEN_OPTION, array() );
		$items = array();
		foreach ( is_array( $seen ) ? $seen : array() as $slug => $label ) {
			$items[] = (object) array(
				'slug' => $slug,
				'name' => esc_html( $label ),
				'link' => '#',
			);
		}
		$items = gend_society_ps_apply_nav( $items );
	}
	$current = function_exists( 'bp_current_component' ) ? (string) bp_current_component() : '';
	$html    = '';
	foreach ( $items as $it ) {
		$active = isset( $it->slug ) && $it->slug === $current;
		$html  .= '<a class="gdc-ps-nav-item' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( isset( $it->link ) ? $it->link : '#' ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . wp_kses( (string) $it->name, array( 'span' => array( 'class' => true ) ) ) . '</a>';
	}
	return '<nav ' . gend_society_ps_wrapper( 'gdc-ps-nav', array( 'aria-label' => __( 'Profile menu', 'gend-society' ) ) ) . '>' . $html . '</nav>';
}

/**
 * Profile Tab Content: where the tab's own content goes in a tab layout.
 *
 * @return string
 */
function gend_society_ps_block_tab_content() {
	$content = gend_society_ps_tab_content();
	if ( '' === $content ) {
		return '<div class="gdc-ps-placeholder"><strong>' . esc_html__( 'Profile tab content', 'gend-society' ) . '</strong><span>' . esc_html__( 'The tab’s own content appears here.', 'gend-society' ) . '</span></div>';
	}
	return '<div class="gdc-ps-tab-content">' . $content . '</div>';
}

add_action( 'wp_enqueue_scripts', 'gend_society_ps_front_styles' );
/**
 * Small base styles for the profile blocks (layout only; colours come from block settings).
 */
function gend_society_ps_front_styles() {
	if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return;
	}
	wp_register_style( 'gend-society-profile-blocks', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-profile-blocks' );
	wp_add_inline_style( 'gend-society-profile-blocks', gend_society_ps_block_css() );
}

add_action( 'enqueue_block_assets', 'gend_society_ps_editor_styles' );
/**
 * The same base styles inside the block editor canvas.
 */
function gend_society_ps_editor_styles() {
	if ( ! is_admin() ) {
		return;
	}
	wp_register_style( 'gend-society-profile-blocks-editor', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-profile-blocks-editor' );
	wp_add_inline_style( 'gend-society-profile-blocks-editor', gend_society_ps_block_css() );
}

/**
 * Base CSS for the profile blocks.
 *
 * @return string
 */
function gend_society_ps_block_css() {
	return '.gdc-ps-cover{position:relative;border-radius:inherit;overflow:hidden;gap:12px;align-items:flex-start}'
		. '.gdc-ps-cover>*{position:relative;margin:0!important}'
		. '.gdc-ps-name{line-height:1.12!important;margin:0}'
		. '.gdc-ps-handle,.gdc-ps-member-type,.gdc-ps-field{margin:0;line-height:1.45!important}'
		. '.gdc-ps-member-type span{display:inline-block;padding:.25em .8em;border-radius:99px;border:1px solid currentColor;font-size:.8em;letter-spacing:.08em;text-transform:uppercase}'
		. '.gdc-ps-handle{opacity:.75}'
		. '.gdc-ps-nav{display:flex;flex-wrap:wrap;gap:.4em}'
		. '.gdc-ps-nav-item{padding:.55em 1em;border-radius:99px;text-decoration:none;border:1px solid transparent}'
		. '.gdc-ps-nav-item.is-active,.gdc-ps-nav-item:hover{border-color:currentColor}'
		. '.gdc-ps-nav-item .count{opacity:.6;margin-left:.3em}'
		. '.gdc-ps-placeholder{display:flex;flex-direction:column;gap:4px;padding:24px;border:1px dashed rgba(127,127,127,.6);border-radius:12px;text-align:center;font-family:system-ui,sans-serif}'
		. '.gdc-ps-placeholder span{opacity:.7;font-size:13px}';
}

/* ── Builder API (used by the Design Studio's Profile Styling page) ──────── */

add_action( 'rest_api_init', 'gend_society_ps_register_rest' );
/**
 * REST routes for the Profile Styling builder.
 */
function gend_society_ps_register_rest() {
	$perm = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/profile-styling',
		array(
			'methods'             => 'GET',
			'callback'            => 'gend_society_ps_rest_state',
			'permission_callback' => $perm,
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/profile-styling/(?P<action>header|assign|delete|tab|nav)',
		array(
			'methods'             => 'POST',
			'callback'            => 'gend_society_ps_rest_action',
			'permission_callback' => $perm,
		)
	);
}

/**
 * Builder state: header templates, tab layouts, member types, tabs and menu.
 *
 * @return WP_REST_Response
 */
function gend_society_ps_rest_state() {
	gend_society_ps_default_header_id();
	$row = function ( $p ) {
		return array(
			'id'          => $p->ID,
			'title'       => get_the_title( $p ),
			'siteDefault' => (bool) get_post_meta( $p->ID, '_gdc_is_site_default', true ),
			'types'       => array_values( array_filter( explode( ',', (string) get_post_meta( $p->ID, '_gdc_member_types', true ) ) ) ),
			'tab'         => (string) get_post_meta( $p->ID, '_gdc_tab_slug', true ),
			'editUrl'     => admin_url( 'post.php?post=' . $p->ID . '&action=edit' ),
			'modified'    => get_post_modified_time( 'c', true, $p ),
		);
	};
	$query = function ( $type ) use ( $row ) {
		return array_map(
			$row,
			get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			)
		);
	};
	$seen = get_option( GEND_SOCIETY_PS_SEEN_OPTION, array() );
	$nav  = get_option( GEND_SOCIETY_PS_NAV_OPTION, array() );
	return rest_ensure_response(
		array(
			'headers'     => $query( 'gdc_profile_header' ),
			'tabs'        => $query( 'gdc_profile_tab' ),
			'memberTypes' => gend_society_ps_member_type_options(),
			'seenTabs'    => is_array( $seen ) ? $seen : array(),
			'nav'         => is_array( $nav ) ? (object) $nav : new stdClass(),
		)
	);
}

/**
 * Builder actions.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_ps_rest_action( WP_REST_Request $req ) {
	$action = $req['action'];
	$body   = (array) $req->get_json_params();

	if ( 'header' === $action ) {
		$from    = isset( $body['from'] ) ? sanitize_key( $body['from'] ) : 'classic';
		$content = 'classic' === $from
			? '<!-- wp:gend-society/profile-header-classic /-->'
			: '<!-- wp:gend-society/profile-cover {"minHeight":340,"style":{"spacing":{"padding":{"top":"32px","right":"32px","bottom":"32px","left":"32px"}}}} --><!-- wp:gend-society/profile-avatar {"size":112} /--><!-- wp:gend-society/profile-member-type /--><!-- wp:gend-society/profile-name /--><!-- wp:gend-society/profile-handle /--><!-- wp:gend-society/profile-actions /--><!-- /wp:gend-society/profile-cover --><!-- wp:gend-society/profile-nav {"style":{"spacing":{"padding":{"top":"16px","bottom":"16px"}}}} /-->';
		$id      = wp_insert_post(
			array(
				'post_type'    => 'gdc_profile_header',
				'post_status'  => 'publish',
				'post_title'   => isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ? sanitize_text_field( $body['title'] ) : __( 'New profile header', 'gend-society' ),
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	if ( 'tab' === $action ) {
		$slug = isset( $body['slug'] ) ? sanitize_key( $body['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'gs_ps_slug', __( 'Pick a tab.', 'gend-society' ), array( 'status' => 400 ) );
		}
		$existing = get_posts(
			array(
				'post_type'      => 'gdc_profile_tab',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one admin lookup.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_gdc_tab_slug',
						'value' => $slug,
					),
					array(
						'key'   => '_gdc_is_site_default',
						'value' => '1',
					),
				),
			)
		);
		$variant = ! empty( $body['variant'] );
		if ( $existing && ! $variant ) {
			return rest_ensure_response( array( 'id' => (int) $existing[0] ) );
		}
		$seen  = get_option( GEND_SOCIETY_PS_SEEN_OPTION, array() );
		$label = is_array( $seen ) && isset( $seen[ $slug ] ) ? $seen[ $slug ] : $slug;
		$id    = wp_insert_post(
			array(
				'post_type'    => 'gdc_profile_tab',
				'post_status'  => 'publish',
				'post_title'   => $variant
					/* translators: %s: profile tab name. */
					? sprintf( __( '%s tab — member type layout', 'gend-society' ), $label )
					/* translators: %s: profile tab name. */
					: sprintf( __( 'Default — %s tab', 'gend-society' ), $label ),
				/**
				 * Filters what a new tab layout starts with (default: the tab's own content).
				 *
				 * @param string $content Block markup.
				 * @param string $slug    Tab slug.
				 */
				'post_content' => (string) apply_filters( 'gend_society_ps_default_tab_content', '<!-- wp:gend-society/profile-tab-content /-->', $slug ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_gdc_tab_slug', $slug );
		if ( ! $variant ) {
			update_post_meta( $id, '_gdc_is_site_default', '1' );
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	if ( 'nav' === $action ) {
		$clean = array();
		foreach ( isset( $body['nav'] ) && is_array( $body['nav'] ) ? $body['nav'] : array() as $slug => $c ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || ! is_array( $c ) ) {
				continue;
			}
			$clean[ $slug ] = array(
				'label'  => isset( $c['label'] ) ? sanitize_text_field( $c['label'] ) : '',
				'order'  => isset( $c['order'] ) ? (int) $c['order'] : 0,
				'hidden' => ! empty( $c['hidden'] ),
			);
		}
		update_option( GEND_SOCIETY_PS_NAV_OPTION, $clean, false );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	$id   = isset( $body['id'] ) ? (int) $body['id'] : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || ! in_array( $post->post_type, array( 'gdc_profile_header', 'gdc_profile_tab' ), true ) ) {
		return new WP_Error( 'gs_ps_id', __( 'Template not found.', 'gend-society' ), array( 'status' => 404 ) );
	}

	if ( 'delete' === $action ) {
		wp_trash_post( $id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	// assign: site default (one per post type, per tab for layouts) and member types.
	if ( ! empty( $body['siteDefault'] ) ) {
		$tab    = (string) get_post_meta( $id, '_gdc_tab_slug', true );
		$others = get_posts(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'exclude'        => array( $id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- tiny admin set.
			)
		);
		foreach ( $others as $oid ) {
			if ( (string) get_post_meta( $oid, '_gdc_tab_slug', true ) === $tab ) {
				delete_post_meta( $oid, '_gdc_is_site_default' );
			}
		}
		update_post_meta( $id, '_gdc_is_site_default', '1' );
	}
	if ( isset( $body['types'] ) && is_array( $body['types'] ) ) {
		$valid = array_keys( gend_society_ps_member_type_options() );
		$types = array_values( array_intersect( array_map( 'sanitize_key', $body['types'] ), $valid ) );
		if ( $types ) {
			update_post_meta( $id, '_gdc_member_types', implode( ',', $types ) );
		} else {
			delete_post_meta( $id, '_gdc_member_types' );
		}
	}
	return rest_ensure_response( array( 'assigned' => true ) );
}
