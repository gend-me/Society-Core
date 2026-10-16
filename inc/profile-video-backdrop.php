<?php
/**
 * Profile tab video backdrops: a sticky, scroll-scrubbed video behind a tab's content, with the content's panels
 * turned into glass layered over it.
 *
 *   Contracts (component invest) → uploads/gend-society-backdrops/contracts-vines.mp4
 *   Projects  (component groups) → uploads/gend-society-backdrops/projects-backdrop.mp4
 *
 * The videos are re-encoded copies of the originals (Media Library: gemini_generated_video_066d699c / _13295b6e) with a
 * keyframe every 4 frames and no audio, so scrubbing stays smooth. Scrolling down the tab plays the video forward then
 * back (the vines grow, then shrink); scrolling up reverses it. assets/profile-video-backdrop.{js,css}.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** [bp component => ['video' => url, 'host' => CSS selector of the tab's content wrapper]] */
function gend_society_profile_backdrops() {
    $base = content_url( 'uploads/gend-society-backdrops/' );
    return (array) apply_filters( 'gend_society_profile_backdrops', array(
        'invest' => array( 'video' => $base . 'contracts-vines.mp4', 'host' => '.gend-invest-screen' ),
        'groups' => array( 'video' => $base . 'projects-backdrop.mp4', 'host' => '.gs-member-groups-wrap' ),
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
    wp_localize_script( 'gend-society-video-backdrop', 'GS_VIDEO_BACKDROP', array( 'video' => esc_url_raw( $b['video'] ), 'host' => (string) $b['host'] ) );
}, 30 );
