<?php
/**
 * Youzify styling control (stage 4 of moving Social off Youzify): stops
 * Youzify's front-end stylesheets (and the inline colour scheme attached to
 * them) on pages that no longer need them. Youzify's scripts keep loading, so
 * its features (posting, reactions, media, ...) keep working.
 *
 * Per area (members directory, groups directory, activity, profiles, groups,
 * and all other pages, where Youzify loads its CSS too) the setting is:
 *   auto  (default) off where a published block layout fully replaces
 *         Youzify's output (a directory layout without Original Page Content);
 *   off   never load Youzify styles in that area;
 *   on    always load them.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_YZ_OPTION = 'gend_society_youzify_styles';

/**
 * The areas, slug => label.
 *
 * @return array<string,string>
 */
function gend_society_yz_areas() {
	return array(
		'members-directory' => __( 'Members directory', 'gend-society' ),
		'groups-directory'  => __( 'Groups directory', 'gend-society' ),
		'activity'          => __( 'Activity feed', 'gend-society' ),
		'profiles'          => __( 'Member profiles', 'gend-society' ),
		'groups'            => __( 'Group pages', 'gend-society' ),
		'site'              => __( 'All other pages', 'gend-society' ),
	);
}

/**
 * The area of the current front-end request ('' outside Social pages).
 *
 * @return string
 */
function gend_society_yz_current_area() {
	if ( function_exists( 'bp_is_members_directory' ) && bp_is_members_directory() ) {
		return 'members-directory';
	}
	if ( function_exists( 'bp_is_groups_directory' ) && bp_is_groups_directory() ) {
		return 'groups-directory';
	}
	if ( function_exists( 'bp_is_activity_directory' ) && bp_is_activity_directory() ) {
		return 'activity';
	}
	if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
		return 'profiles';
	}
	if ( function_exists( 'bp_is_group' ) && bp_is_group() ) {
		return 'groups';
	}
	// Youzify loads its stylesheets on every page, not just Social ones.
	return function_exists( 'is_buddypress' ) && is_buddypress() ? '' : 'site';
}

/**
 * Whether a published block layout fully replaces Youzify in an area.
 *
 * @param string $area Area slug.
 * @return bool
 */
function gend_society_yz_replaced( $area ) {
	if ( ! in_array( $area, array( 'members-directory', 'groups-directory', 'activity' ), true ) || ! function_exists( 'gend_society_ds_layout' ) ) {
		return false;
	}
	$layout = gend_society_ds_layout( $area );
	// Original Page Content and the activity wall parts still use Youzify's markup and styles.
	return $layout && ! preg_match( '#wp:gend-society/(original-content|activity-)#', $layout->post_content );
}

/**
 * Whether Youzify styles load in an area.
 *
 * @param string $area Area slug.
 * @return bool
 */
function gend_society_yz_loads( $area ) {
	$settings = get_option( GEND_SOCIETY_YZ_OPTION, array() );
	$mode     = is_array( $settings ) && isset( $settings[ $area ] ) ? $settings[ $area ] : 'auto';
	if ( 'off' === $mode ) {
		return false;
	}
	if ( 'on' === $mode ) {
		return true;
	}
	return ! gend_society_yz_replaced( $area );
}

add_action( 'wp_enqueue_scripts', 'gend_society_yz_strip', PHP_INT_MAX );
add_action( 'wp_print_styles', 'gend_society_yz_strip', 1 );
add_action( 'wp_print_footer_scripts', 'gend_society_yz_strip', 1 ); // Styles enqueued while the page renders print in the footer.
/**
 * Dequeues Youzify's front-end styles where they are switched off.
 */
function gend_society_yz_strip() {
	if ( is_admin() ) {
		return;
	}
	$area = gend_society_yz_current_area();
	if ( '' === $area || gend_society_yz_loads( $area ) ) {
		return;
	}
	$styles = wp_styles();
	foreach ( (array) $styles->queue as $handle ) {
		if ( 0 === strpos( $handle, 'youzify' ) || 0 === strpos( $handle, 'klabs-' ) ) {
			wp_dequeue_style( $handle );
		}
	}
}

add_action( 'rest_api_init', 'gend_society_yz_register_rest' );
/**
 * REST: read and change the per-area settings (Design Studio panel).
 */
function gend_society_yz_register_rest() {
	register_rest_route(
		'gend-society/v1',
		'/youzify-styles',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'gend_society_yz_rest_get',
				'permission_callback' => function () {
					return current_user_can( 'edit_theme_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_yz_rest_set',
				'permission_callback' => function () {
					return current_user_can( 'edit_theme_options' );
				},
			),
		)
	);
}

/**
 * Areas with their setting, whether a layout replaces Youzify, and a page to check.
 *
 * @return WP_REST_Response
 */
function gend_society_yz_rest_get() {
	$settings = get_option( GEND_SOCIETY_YZ_OPTION, array() );
	$urls     = array(
		'members-directory' => function_exists( 'bp_get_members_directory_permalink' ) ? bp_get_members_directory_permalink() : '',
		'groups-directory'  => function_exists( 'bp_get_groups_directory_url' ) ? bp_get_groups_directory_url() : '',
		'activity'          => function_exists( 'bp_get_activity_directory_permalink' ) ? bp_get_activity_directory_permalink() : '',
		'profiles'          => function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( get_current_user_id() ) : '',
		'groups'            => '',
		'site'              => home_url( '/' ),
	);
	$out      = array();
	foreach ( gend_society_yz_areas() as $slug => $label ) {
		$out[] = array(
			'slug'     => $slug,
			'label'    => $label,
			'mode'     => is_array( $settings ) && isset( $settings[ $slug ] ) ? $settings[ $slug ] : 'auto',
			'replaced' => gend_society_yz_replaced( $slug ),
			'loads'    => gend_society_yz_loads( $slug ),
			'url'      => $urls[ $slug ],
		);
	}
	return rest_ensure_response( $out );
}

/**
 * Saves one area's setting.
 *
 * @param WP_REST_Request $req Request with { area, mode }.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_yz_rest_set( WP_REST_Request $req ) {
	$area = sanitize_key( (string) $req->get_param( 'area' ) );
	$mode = sanitize_key( (string) $req->get_param( 'mode' ) );
	if ( ! isset( gend_society_yz_areas()[ $area ] ) || ! in_array( $mode, array( 'auto', 'off', 'on' ), true ) ) {
		return new WP_Error( 'gs_yz_bad', __( 'Unknown area or setting.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$settings = get_option( GEND_SOCIETY_YZ_OPTION, array() );
	$settings = is_array( $settings ) ? $settings : array();
	if ( 'auto' === $mode ) {
		unset( $settings[ $area ] );
	} else {
		$settings[ $area ] = $mode;
	}
	update_option( GEND_SOCIETY_YZ_OPTION, $settings, false );
	return gend_society_yz_rest_get();
}
