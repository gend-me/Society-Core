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

/**
 * [bp component => ['frames' => folder url of f001.webp…, 'count' => frames, 'host' => CSS selector of the tab's content
 * wrapper, 'anchor' => 'right bottom' (default) | 'center top' | 'center center' — the scene corner pinned to the
 * viewport, 'start' => fraction of the clip the scrub starts at (default 0.12)]]
 */
function gend_society_profile_backdrops() {
    $base = content_url( 'uploads/gend-society-backdrops/' );
    return (array) apply_filters( 'gend_society_profile_backdrops', array(
        'invest' => array( 'frames' => $base . 'contracts-vines/', 'count' => 120, 'host' => '.gend-invest-screen' ),
        'groups' => array( 'frames' => $base . 'projects-backdrop/', 'count' => 120, 'host' => '.gs-member-groups-wrap' ),
        // Wallet: the drop falls from the top and fills the tank, so the scene is pinned top-centre.
        'member-wallet' => array( 'frames' => $base . 'wallet-flow/', 'count' => 120, 'host' => '#gend-wallet', 'anchor' => 'center top', 'start' => 0.02 ),
    ) );
}

/**
 * Group menu pages (bp_current_action => entry). Host is #youzify-group-body — the one wrapper every group tab renders into (there is no #item-body here) —
 * and 'clear' lists the tab roots that paint a solid backdrop of their own, which go transparent so the video is the
 * only background (their cards still become glass).
 *   business-plan → biz-hands (the video the Payments activation landing page used: Smooth_transition_hands_202604051334.mp4)
 *   members-hub   → org-city (the animated city grid behind the Payments landing — the site-wide groups background)
 *   files         → contracts-vines (profile Contracts)
 *   projects      → projects-backdrop (profile Projects)
 *   payments      → wallet-flow (profile Wallet) — both the activation landing (its own .psoo-pl-bg video is hidden while
 *                   the backdrop runs, so the landing matches the activated page) and the activated dashboard
 */
function gend_society_group_backdrops() {
    $base  = content_url( 'uploads/gend-society-backdrops/' );
    $clear = '.psoo-bp, .psoo-brain-section, .members-header-section, .psoo-gm, .psoo-project-manager-tab, .workspace-header-section, .psoo-payments-tab, .psoo-payments-screen, .psoo-pay-activation, .psoo-pay-dashboard';
    return (array) apply_filters( 'gend_society_group_backdrops', array(
        'business-plan' => array( 'frames' => $base . 'biz-hands/', 'count' => 96, 'host' => '#youzify-group-body', 'anchor' => 'center center', 'start' => 0, 'clear' => $clear ),
        // Organization: a semi-transparent black filter over the (bright) city scene.
        'members-hub'   => array( 'frames' => $base . 'org-city/', 'count' => 80, 'host' => '#youzify-group-body', 'anchor' => 'center center', 'start' => 0, 'clear' => $clear, 'dim' => 0.5 ),
        'files'         => array( 'frames' => $base . 'contracts-vines/', 'count' => 120, 'host' => '#youzify-group-body', 'clear' => $clear ),
        'projects'      => array( 'frames' => $base . 'projects-backdrop/', 'count' => 120, 'host' => '#youzify-group-body', 'clear' => $clear ),
        'payments'      => array( 'frames' => $base . 'wallet-flow/', 'count' => 120, 'host' => '#youzify-group-body', 'anchor' => 'center top', 'start' => 0.02, 'clear' => $clear ),
    ) );
}

function gend_society_profile_backdrop_current() {
    if ( ! empty( $_GET['gdc_tab_only'] ) || ! function_exists( 'bp_current_component' ) ) return null;
    if ( function_exists( 'bp_is_group' ) && bp_is_group() ) {
        $map = gend_society_group_backdrops();
        $a   = (string) bp_current_action();
        return isset( $map[ $a ] ) ? $map[ $a ] : null;
    }
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) return null;
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
    wp_localize_script( 'gend-society-video-backdrop', 'GS_VIDEO_BACKDROP', array(
        'frames' => esc_url_raw( $b['frames'] ),
        'count'  => (int) $b['count'],
        'host'   => (string) $b['host'],
        'anchor' => isset( $b['anchor'] ) ? (string) $b['anchor'] : 'right bottom',
        'start'  => isset( $b['start'] ) ? (float) $b['start'] : 0.12,
        'clear'  => isset( $b['clear'] ) ? (string) $b['clear'] : '',
        'dim'    => isset( $b['dim'] ) ? max( 0, min( 0.9, (float) $b['dim'] ) ) : 0,   // black filter opacity over the scene
    ) );
}, 30 );
