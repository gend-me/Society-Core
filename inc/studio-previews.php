<?php
/**
 * Live previews for the Social block templates in the block editor.
 *
 * Blocks that stand for content that is already built (GenD Profile Header,
 * Profile Tab Content, Original Page Content, Original Sidebar, the Group
 * Styling "Group content" endpoint panel) show the real page in the editor,
 * through a front-end request in a "show only this part" view
 * (?gs_studio_view=..., site editors only). The editor canvas of these
 * templates also gets the live CSS and the dark page background, so the
 * blocks around them look as they will on the site.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template post types edited with live previews.
 *
 * @return string[]
 */
function gend_society_pv_post_types() {
	return array( 'gdc_profile_header', 'gdc_profile_tab', 'gdc_bp_layout', 'gdc_group_header', 'gdc_group_page' );
}

/**
 * A group to preview group templates with: the one a group page override
 * belongs to, else your most recent admin group, else any public group.
 *
 * @param int $post_id Template being edited (0 when unknown).
 * @return int
 */
function gend_society_pv_group_id( $post_id = 0 ) {
	if ( $post_id ) {
		foreach ( array( '_gdc_group_id', '_gdc_bp_group_id', '_gdc_override_group_id' ) as $key ) {
			$gid = (int) get_post_meta( $post_id, $key, true );
			if ( $gid ) {
				return $gid;
			}
		}
	}
	if ( ! class_exists( 'BP_Groups_Group' ) ) {
		return 0;
	}
	$mine = BP_Groups_Group::get(
		array(
			'user_id'     => get_current_user_id(),
			'type'        => 'active',
			'per_page'    => 1,
			'show_hidden' => true,
		)
	);
	if ( ! empty( $mine['groups'] ) ) {
		return (int) $mine['groups'][0]->id;
	}
	$any = BP_Groups_Group::get(
		array(
			'type'     => 'active',
			'per_page' => 1,
		)
	);
	return ! empty( $any['groups'] ) ? (int) $any['groups'][0]->id : 0;
}

/**
 * Whether this request is a studio preview view (and which).
 *
 * @return string
 */
function gend_society_pv_view() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for site editors.
	$view = isset( $_GET['gs_studio_view'] ) ? sanitize_key( wp_unslash( $_GET['gs_studio_view'] ) ) : '';
	return ( '' !== $view && current_user_can( 'edit_theme_options' ) ) ? $view : '';
}

add_action( 'template_redirect', 'gend_society_pv_render', 1 );
/**
 * Renders a "show only this part" preview page.
 */
function gend_society_pv_render() {
	$view = gend_society_pv_view();
	if ( '' === $view ) {
		return;
	}
	add_filter( 'show_admin_bar', '__return_false' );
	$part = null;
	if ( 'profile-header' === $view && function_exists( 'bp_is_user' ) && bp_is_user() && function_exists( 'gend_society_render_profile_header' ) ) {
		$part = function () {
			echo '<div id="youzify"><div class="youzify youzify-page youzify-profile"><div class="youzify-content">';
			gend_society_render_profile_header();
			echo '</div></div></div>';
		};
	} elseif ( 'group-endpoint' === $view && function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'gdc_gs_render_endpoint_panel_block' ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
		$slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
		$part = function () use ( $slug ) {
			echo '<div id="youzify"><div class="youzify youzify-page"><div class="youzify-content"><div class="gs-pv-pad">';
			echo gdc_gs_render_endpoint_panel_block( array( 'slug' => $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered group tab content.
			echo '</div></div></div></div>';
		};
	} elseif ( 'group-header' === $view && function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'gdc_gs_render_group_header_shell' ) ) {
		$part = function () {
			echo '<div id="youzify"><div class="youzify youzify-page"><div class="youzify-content">';
			gdc_gs_render_group_header_shell( (int) bp_get_current_group_id() );
			echo '</div></div></div>';
		};
	} elseif ( 'group-sidebar' === $view && function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'youzify_get_groups_sidebar' ) ) {
		$part = function () {
			echo '<div id="youzify"><div class="youzify youzify-page"><div class="youzify-content"><div class="youzify-sidebar-column youzify-group-sidebar youzify-sidebar"><div class="youzify-column-content">';
			youzify_get_groups_sidebar();
			echo '</div></div></div></div></div>';
		};
	}
	if ( 'block' === $view && function_exists( 'gend_society_ab_wrap' ) && function_exists( 'bp_is_activity_directory' ) && bp_is_activity_directory() ) {
		// One activity wall block, on the real activity page (gend-society/activity-* only).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
		$name  = isset( $_GET['block'] ) ? sanitize_text_field( wp_unslash( $_GET['block'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
		$attrs = isset( $_GET['attrs'] ) ? json_decode( wp_unslash( $_GET['attrs'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded, then only passed as block attributes.
		if ( preg_match( '#^gend-society/activity-[a-z-]+$#', $name ) && WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			$part = function () use ( $name, $attrs ) {
				$block = array(
					'blockName'    => $name,
					'attrs'        => is_array( $attrs ) ? $attrs : array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
				echo gend_society_ab_wrap( '<div class="gs-pv-pad">' . render_block( $block ) . '</div>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block.
			};
		}
	}
	if ( 'original' === $view ) {
		// The page as it is (no block layout), without the site's header and footer.
		add_action(
			'wp_head',
			function () {
				echo '<style id="gs-pv-original">header.wp-block-template-part,footer.wp-block-template-part,.gs-front-sidebar,.gs-float-menu,.gs-float-dock,.em-chat-widget,#gm-cc,.gm-cc-open{display:none!important}</style>';
			},
			99
		);
		return;
	}
	if ( ! $part ) {
		return;
	}
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
	<?php wp_head(); ?>
<style>html,body{margin:0;background:#0b0e14}.gs-pv-pad{padding:16px}.gs-front-sidebar,.gs-sidebar-flyout,.gs-float-menu,.gs-float-dock,aipa-widget,.em-chat-widget,.em-chat-panel,#gm-cc,.gm-cc-open{display:none!important}</style>
</head>
<body <?php body_class( 'gs-studio-preview' ); ?>>
	<?php
	$part();
	wp_footer();
	?>
</body>
</html>
	<?php
	exit;
}

add_action( 'enqueue_block_editor_assets', 'gend_society_pv_editor_assets' );
/**
 * Editor script and the URLs its live previews load.
 */
function gend_society_pv_editor_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->post_type, gend_society_pv_post_types(), true ) ) {
		return;
	}
	global $post;
	$post_id = $post instanceof WP_Post ? (int) $post->ID : 0;
	$gid     = gend_society_pv_group_id( $post_id );
	$group   = $gid && function_exists( 'groups_get_group' ) ? groups_get_group( $gid ) : null;
	$me      = get_current_user_id();
	$data    = array(
		'postType'    => $screen->post_type,
		'profileUrl'  => function_exists( 'bp_members_get_user_url' ) ? trailingslashit( bp_members_get_user_url( $me ) ) : '',
		'groupUrl'    => $group && function_exists( 'bp_get_group_url' ) ? trailingslashit( bp_get_group_url( $group ) ) : '',
		'groupName'   => $group ? $group->name : '',
		'tabSlug'     => $post_id ? (string) get_post_meta( $post_id, '_gdc_tab_slug', true ) : '',
		'layoutSlug'  => $post_id ? (string) get_post_meta( $post_id, '_gdc_layout_slug', true ) : '',
		'directories' => array(
			'members-directory' => function_exists( 'bp_get_members_directory_permalink' ) ? bp_get_members_directory_permalink() : '',
			'groups-directory'  => function_exists( 'bp_get_groups_directory_url' ) ? bp_get_groups_directory_url() : '',
			'activity'          => function_exists( 'bp_get_activity_directory_permalink' ) ? bp_get_activity_directory_permalink() : '',
		),
	);
	$js      = 'assets/studio-previews.js';
	wp_enqueue_script( 'gend-society-studio-previews', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-hooks', 'wp-i18n', 'wp-compose', 'wp-data', 'wp-api-fetch', 'wp-components', 'wp-notices', 'wp-editor' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_add_inline_script( 'gend-society-studio-previews', 'window.gsStudioPreview=' . wp_json_encode( $data ) . ';', 'before' );
}

add_action( 'enqueue_block_assets', 'gend_society_pv_canvas_styles' );
/**
 * The editor canvas of Social templates: the live page background and the
 * preview frames (the live page's stylesheets are added by studio-previews.js).
 */
function gend_society_pv_canvas_styles() {
	if ( ! is_admin() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->post_type, gend_society_pv_post_types(), true ) ) {
		return;
	}
	$css = '.editor-styles-wrapper{background:#0b0e14!important;color:#e8edf5}'
		. '.editor-styles-wrapper .editor-post-title,.editor-styles-wrapper .wp-block-post-title{color:#e8edf5}'
		. '.gs-pv-frame{display:block;width:100%;border:0;border-radius:12px;background:#0b0e14;min-height:160px}'
		. '.gs-pv-label{display:flex;justify-content:space-between;gap:8px;padding:6px 10px;font:600 11px/1.4 system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:#97e46e;background:rgba(151,228,110,.08);border:1px dashed rgba(151,228,110,.4);border-bottom:0;border-radius:12px 12px 0 0}'
		. '.gs-pv-label span{color:#8b98b0;text-transform:none;letter-spacing:0;font-weight:400}'
		. '.gs-pv-wrap{border:1px dashed rgba(151,228,110,.4);border-top:0;border-radius:0 0 12px 12px;overflow:hidden}'
		// Always show where to add a new section, whatever the live styles do.
		. '.is-root-container>.block-list-appender{display:block!important;visibility:visible!important;opacity:1!important;position:relative!important;margin:24px auto!important;max-width:none!important}'
		. '.block-editor-button-block-appender{display:flex!important;width:100%!important;min-height:56px!important;color:#97e46e!important;background:rgba(151,228,110,.06)!important;box-shadow:inset 0 0 0 1px rgba(151,228,110,.45)!important;border-radius:12px!important}'
		. '.block-editor-button-block-appender:hover{background:rgba(151,228,110,.14)!important}'
		. '.block-editor-default-block-appender__content{color:#8b98b0!important;opacity:1!important}'
		. '.gs-pv-add{display:block;width:calc(100% - 48px);max-width:900px;margin:28px auto 80px;padding:18px;border-radius:14px;border:1px dashed rgba(151,228,110,.55);background:rgba(151,228,110,.06);color:#97e46e;font:600 15px/1 system-ui,sans-serif;cursor:pointer}'
		. '.gs-pv-add:hover{background:rgba(151,228,110,.14)}'
		. '.gs-pv-add+.gs-pv-add.is-html{margin-top:-64px;border-color:rgba(110,193,228,.5);background:rgba(110,193,228,.06);color:#6ec1e4}'
		. '.gs-pv-add.is-html:hover{background:rgba(110,193,228,.14)}'
		. '.gs-lh-hint{margin:0 0 6px;padding:8px 12px;border-radius:10px;background:rgba(110,193,228,.1);color:#9fd6ee;font:500 12px/1.4 system-ui,sans-serif}'
		. '.gs-lh-loading{padding:18px;color:#8a94a6;font:500 13px system-ui,sans-serif}'
		. '.gs-lh-frame{width:100%;min-height:520px;border:0;border-radius:14px;background:#0b0e14}';
	// The live page's own stylesheets are copied into the canvas by assets/studio-previews.js.
	wp_register_style( 'gend-society-studio-canvas', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-studio-canvas' );
	wp_add_inline_style( 'gend-society-studio-canvas', str_ireplace( '</style', '', $css ) );
}
