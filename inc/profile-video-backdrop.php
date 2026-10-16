<?php
/**
 * Profile tab video backdrops: a sticky, scroll-scrubbed video behind a tab's content, with the content's panels
 * turned into glass layered over it.
 *
 *   Contracts (component invest) → uploads/gend-society-backdrops/contracts-vines/f001…f120.webp
 *   Projects  (component groups) → uploads/gend-society-backdrops/projects-backdrop/f001…f120.webp
 *
 * The frames are every 2nd frame of the originals (Media Library: gemini_generated_video_066d699c / _13295b6e) at
 * 1280px, drawn on a canvas with cross-fades (seeking a <video> per scroll step is jumpy). Scrolling grows the scene at
 * a steady rate; long tabs shrink it back at the end. assets/profile-video-backdrop.{js,css}.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** [bp component => ['frames' => folder url of f001.webp…, 'count' => frames, 'host' => CSS selector of the tab's content wrapper]] */
function gend_society_profile_backdrops() {
    $base = content_url( 'uploads/gend-society-backdrops/' );
    return (array) apply_filters( 'gend_society_profile_backdrops', array(
        'invest' => array( 'frames' => $base . 'contracts-vines/', 'count' => 120, 'host' => '.gend-invest-screen' ),
        'groups' => array( 'frames' => $base . 'projects-backdrop/', 'count' => 120, 'host' => '.gs-member-groups-wrap' ),
    ) );
}

function gend_society_profile_backdrop_current() {
    if ( ! empty( $_GET['gdc_tab_only'] ) || ! function_exists( 'bp_is_user' ) || ! bp_is_user() || ! function_exists( 'bp_current_component' ) ) return null;
    $map = gend_society_profile_backdrops();
    $c = (string) bp_current_component();
    return isset( $map[ $c ] ) ? $map[ $c ] : null;
}

add_action( 'wp_enqueue_scripts', function () {
    $b = gend_society_profile_backdrop_current();
    if ( ! $b ) return;
    $css = GEND_SOCIETY_DIR . 'assets/profile-video-backdrop.css';
    $js  = GEND_SOCIETY_DIR . 'assets/profile-video-backdrop.js';
    wp_enqueue_style( 'gend-society-video-backdrop', GEND_SOCIETY_URL . 'assets/profile-video-backdrop.css', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $css ) );
    wp_enqueue_script( 'gend-society-video-backdrop', GEND_SOCIETY_URL . 'assets/profile-video-backdrop.js', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $js ), true );
    wp_localize_script( 'gend-society-video-backdrop', 'GS_VIDEO_BACKDROP', array( 'frames' => esc_url_raw( $b['frames'] ), 'count' => (int) $b['count'], 'host' => (string) $b['host'] ) );
}, 30 );
