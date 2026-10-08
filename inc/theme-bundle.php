<?php
/**
 * GenD Society: bundled block theme.
 *
 * The GenD Society theme lives in this plugin's /themes folder and is
 * updated together with the plugin. Everything GenD builds (BuddyPress /
 * Youzify profile + group pages, store, Leo pages) depends on it, so it must
 * not change under us when WordPress.org ships a Twenty Twenty-Five update
 * (2026-10-08: that update deleted the header.php/footer.php bridges and
 * broke the profile header/footer).
 *
 * - register_theme_directory() makes WordPress list it like any other theme.
 * - The theme is never checked against the WordPress.org theme directory.
 * - On multisite it is always network-allowed (no DB write needed).
 * - It is never activated automatically: single sites get a notice with an
 *   "Activate" button; the hub is switched by the migration script.
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_THEME_SLUG', 'gend-society-theme');
define('GS_THEME_ROOT', GS_DIR . 'themes');

register_theme_directory(GS_THEME_ROOT);

// Never offer a WordPress.org "update" for our slug (in case one is ever published there).
add_filter('site_transient_update_themes', function ($value) {
    if (is_object($value)) {
        if (isset($value->response[GS_THEME_SLUG])) {
            unset($value->response[GS_THEME_SLUG]);
        }
        if (isset($value->no_update[GS_THEME_SLUG])) {
            unset($value->no_update[GS_THEME_SLUG]);
        }
    }
    return $value;
});

// Leave the theme out of the update-check request sent to api.wordpress.org.
add_filter('http_request_args', function ($args, $url) {
    if (strpos($url, '://api.wordpress.org/themes/update-check/') === false || empty($args['body']['themes'])) {
        return $args;
    }
    $themes = json_decode($args['body']['themes'], true);
    if (is_array($themes) && isset($themes['themes'][GS_THEME_SLUG])) {
        unset($themes['themes'][GS_THEME_SLUG]);
        $args['body']['themes'] = wp_json_encode($themes);
    }
    return $args;
}, 10, 2);

// Multisite: always network-enabled.
add_filter('allowed_themes', function ($themes) {
    $themes[GS_THEME_SLUG] = true;
    return $themes;
});

/**
 * Whether the GenD Society theme is the active theme (or the parent of it).
 */
function gs_theme_is_active() {
    return get_stylesheet() === GS_THEME_SLUG || get_template() === GS_THEME_SLUG;
}

// Single sites (connected installs): opt-in activation notice.
add_action('admin_notices', function () {
    if (is_multisite() || gs_theme_is_active() || !current_user_can('switch_themes')) {
        return;
    }
    if (get_user_meta(get_current_user_id(), 'gs_theme_notice_dismissed', true)) {
        return;
    }
    $theme = wp_get_theme(GS_THEME_SLUG);
    if (!$theme->exists()) {
        return;
    }
    $activate = wp_nonce_url(
        admin_url('themes.php?action=activate&stylesheet=' . rawurlencode(GS_THEME_SLUG)),
        'switch-theme_' . GS_THEME_SLUG
    );
    $dismiss = wp_nonce_url(add_query_arg('gs_dismiss_theme_notice', '1'), 'gs_dismiss_theme_notice');
    printf(
        '<div class="notice notice-info"><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
        esc_html__('GenD Society includes the GenD Society theme. GenD profile, group and store pages are designed for it.', 'gend-society'),
        esc_url($activate),
        esc_html__('Activate GenD Society theme', 'gend-society'),
        esc_url($dismiss),
        esc_html__('Not now', 'gend-society')
    );
});

add_action('admin_init', function () {
    if (empty($_GET['gs_dismiss_theme_notice'])) {
        return;
    }
    check_admin_referer('gs_dismiss_theme_notice');
    update_user_meta(get_current_user_id(), 'gs_theme_notice_dismissed', 1);
    wp_safe_redirect(remove_query_arg(array('gs_dismiss_theme_notice', '_wpnonce')));
    exit;
});
