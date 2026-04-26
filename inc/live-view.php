<?php
/**
 * Block Editor Live View
 * Enqueues assets to provide a "Live View" feature within the Gutenberg editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('enqueue_block_editor_assets', 'gs_enqueue_live_view_assets');

function gs_enqueue_live_view_assets()
{
    $ver = GS_VERSION . '.' . filemtime(GS_DIR . 'assets/gs-live-view.js');

    // Enqueue JS
    wp_enqueue_script(
        'gs-live-view',
        GS_URL . 'assets/gs-live-view.js',
        [
            'wp-plugins',
            'wp-edit-post',
            'wp-element',
            'wp-data',
            'wp-components',
            'wp-i18n'
        ],
        $ver,
        true
    );

    // Enqueue CSS
    wp_enqueue_style(
        'gs-live-view',
        GS_URL . 'assets/gs-live-view.css',
        [],
        GS_VERSION . '.' . filemtime(GS_DIR . 'assets/gs-live-view.css')
    );

    // Pass data to JS
    wp_localize_script('gs-live-view', 'gsLiveViewData', [
        'siteUrl' => get_site_url(),
        'restUrl' => esc_url_raw(rest_url()),
        'nonce' => wp_create_nonce('wp_rest')
    ]);
}

/**
 * Aggressive Asset stripping for "Turbo" Live View
 * This removes non-essential scripts and styles from the Live View iframe
 */
add_action('wp_enqueue_scripts', function () {
    if (isset($_GET['gs_live_view']) && $_GET['gs_live_view'] === '1') {
        // Disable admin bar for this request
        show_admin_bar(false);

        // Define essential assets we MUST keep (WordPress core block styles)
        $keep_styles = [
            'wp-block-library',
            'wp-block-library-theme',
            'global-styles',
            'wp-emoji',
            'gs-live-view'
        ];

        global $wp_styles, $wp_scripts;

        // Deregister styles
        if (isset($wp_styles->queue)) {
            foreach ($wp_styles->queue as $handle) {
                if (!in_array($handle, $keep_styles) && strpos($handle, 'wp-block') === false) {
                    wp_dequeue_style($handle);
                }
            }
        }

        // Deregister scripts (except core essentials)
        $keep_scripts = ['wp-emoji'];
        if (isset($wp_scripts->queue)) {
            foreach ($wp_scripts->queue as $handle) {
                if (!in_array($handle, $keep_scripts)) {
                    wp_dequeue_script($handle);
                }
            }
        }
    }
}, 9999);

/**
 * Filter the template to use a blank minimal template for Live View requests
 */
add_filter('template_include', function ($template) {
    if (isset($_GET['gs_live_view']) && $_GET['gs_live_view'] === '1') {
        $blank_template = GS_DIR . 'inc/templates/live-view-blank.php';
        if (file_exists($blank_template)) {
            return $blank_template;
        }
    }
    return $template;
});
