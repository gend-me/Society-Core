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

/* ── Script loading: no synchronous downloads ───────────────────────────── */

add_action( 'wp_enqueue_scripts', 'gend_society_yz_preload_libraries', 100 );
/**
 * Youzify adds some of its libraries with jQuery( '<script src>' ).appendTo(),
 * which jQuery downloads synchronously: the page freezes until the file
 * arrives (seconds on a busy server). Loaded here as normal page scripts
 * instead, before Youzify's own script; the inline filter below then skips
 * the synchronous copy jQuery would fetch.
 */
function gend_society_yz_preload_libraries() {
	if ( is_admin() || ! wp_script_is( 'youzify', 'enqueued' ) || ! function_exists( 'gend_sn_script_url' ) ) {
		return;
	}
	$ver = function_exists( 'gend_sn_version' ) ? gend_sn_version() : GEND_SOCIETY_VERSION;
	// Youzify's stylesheet (which styled these dropdowns) no longer loads; the script still builds them.
	wp_enqueue_style( 'gend-society-nice-select', GEND_SOCIETY_URL . 'assets/nice-select.css', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/nice-select.css' ) );
	$libs = array(
		'nice-select' => array( 'jquery' ),
		'autosize'    => array( 'jquery' ),
	);
	// Live notifications: only for signed-in members, when Youzify turns them on and
	// the page already runs heartbeat (Youzify skips them without it; never add polling).
	if ( is_user_logged_in() && has_filter( 'youzify_scripts_vars', 'youzify_notification_localization' ) && wp_script_is( 'heartbeat', 'enqueued' ) ) {
		$libs['live-notifications'] = array( 'jquery', 'heartbeat', 'youzify' );
	}
	$names = array();
	foreach ( $libs as $name => $deps ) {
		$handle = 'gend-society-yz-' . $name;
		wp_enqueue_script( $handle, gend_sn_script_url( $name ), $deps, $ver, true );
		$names[] = $name;
		// Youzify's script runs after the libraries it calls straight away.
		if ( 'live-notifications' !== $name ) {
			$reg = wp_scripts()->registered['youzify'];
			if ( ! in_array( $handle, $reg->deps, true ) ) {
				$reg->deps[] = $handle;
			}
		}
	}
	wp_add_inline_script(
		'jquery',
		'(function($,re){if(!$||!$.ajaxPrefilter)return;$.ajaxPrefilter("script",function(o,c,x){var m=o.url&&o.url.match(re);if(o.async===false&&m){x.abort();}});})(window.jQuery,/youzify-(' . implode( '|', array_map( 'preg_quote', $names ) ) . ')\.min\.js/);'
	);
}

add_filter( 'script_loader_tag', 'gend_society_yz_mediaelement_order', 1000, 2 );
/**
 * When something defers MediaElement's core (mediaelement-and-player), its
 * migrate add-on must be deferred too, or it runs first ("mejs is not defined").
 *
 * @param string $tag    Script tag.
 * @param string $handle Script handle.
 * @return string
 */
function gend_society_yz_mediaelement_order( $tag, $handle ) {
	static $core_deferred = false;
	if ( is_admin() ) {
		return $tag;
	}
	if ( 'mediaelement-core' === $handle ) {
		$core_deferred = (bool) preg_match( '/<script\b[^>]*\bsrc=[^>]*\bdefer\b|<script\b[^>]*\bdefer\b[^>]*\bsrc=/i', $tag );
	} elseif ( 'mediaelement-migrate' === $handle && $core_deferred && ! preg_match( '/\b(defer|async)\b/i', $tag ) ) {
		$tag = preg_replace( '/<script\b(?=[^>]*\bsrc=)/i', '<script defer', $tag, 1 );
	}
	return $tag;
}
