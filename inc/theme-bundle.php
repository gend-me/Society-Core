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

define('GEND_SOCIETY_THEME_SLUG', 'gend-society-theme');
define('GEND_SOCIETY_THEME_ROOT', GEND_SOCIETY_DIR . 'themes');

register_theme_directory(GEND_SOCIETY_THEME_ROOT);

// Never offer a WordPress.org "update" for our slug (in case one is ever published there).
add_filter('site_transient_update_themes', function ($value) {
    if (is_object($value)) {
        if (isset($value->response[GEND_SOCIETY_THEME_SLUG])) {
            unset($value->response[GEND_SOCIETY_THEME_SLUG]);
        }
        if (isset($value->no_update[GEND_SOCIETY_THEME_SLUG])) {
            unset($value->no_update[GEND_SOCIETY_THEME_SLUG]);
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
    if (is_array($themes) && isset($themes['themes'][GEND_SOCIETY_THEME_SLUG])) {
        unset($themes['themes'][GEND_SOCIETY_THEME_SLUG]);
        $args['body']['themes'] = wp_json_encode($themes);
    }
    return $args;
}, 10, 2);

// Multisite: always network-enabled.
add_filter('allowed_themes', function ($themes) {
    $themes[GEND_SOCIETY_THEME_SLUG] = true;
    return $themes;
});

/**
 * Whether the GenD Society theme is the active theme (or the parent of it).
 */
function gend_society_theme_is_active() {
    return get_stylesheet() === GEND_SOCIETY_THEME_SLUG || get_template() === GEND_SOCIETY_THEME_SLUG;
}

// Single sites (connected installs): opt-in activation notice.
add_action('admin_notices', function () {
    // Standalone installs get the opt-in card on the GenD page instead (106-05).
    if (function_exists('gend_society_runtime_mode') && 'standalone' === gend_society_runtime_mode()) {
        return;
    }
    if (is_multisite() || gend_society_theme_is_active() || !current_user_can('switch_themes')) {
        return;
    }
    if (get_user_meta(get_current_user_id(), 'gend_society_theme_notice_dismissed', true)) {
        return;
    }
    $theme = wp_get_theme(GEND_SOCIETY_THEME_SLUG);
    if (!$theme->exists()) {
        return;
    }
    $activate = wp_nonce_url(
        admin_url('themes.php?action=activate&stylesheet=' . rawurlencode(GEND_SOCIETY_THEME_SLUG)),
        'switch-theme_' . GEND_SOCIETY_THEME_SLUG
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
    update_user_meta(get_current_user_id(), 'gend_society_theme_notice_dismissed', 1);
    wp_safe_redirect(remove_query_arg(array('gs_dismiss_theme_notice', '_wpnonce')));
    exit;
});

/**
 * Where the activate/revert handlers send the user back to.
 */
function gend_society_theme_card_return_url($status = '') {
    $url = function_exists('gend_society_welcome_page_url') ? gend_society_welcome_page_url() : admin_url('themes.php');
    if ('' !== $status) {
        $url = add_query_arg('gend_society_theme', $status, $url);
    }
    return $url;
}

/**
 * The previously active theme remembered by the activate handler, when it
 * still exists. Returns array('stylesheet', 'template', 'name') or null.
 */
function gend_society_theme_previous() {
    $prev = get_option('gend_society_theme_previous');
    if (!is_array($prev) || empty($prev['stylesheet']) || !is_string($prev['stylesheet'])) {
        return null;
    }
    if (GEND_SOCIETY_THEME_SLUG === $prev['stylesheet'] || !wp_get_theme($prev['stylesheet'])->exists()) {
        return null;
    }
    if (empty($prev['name'])) {
        $prev['name'] = wp_get_theme($prev['stylesheet'])->get('Name');
    }
    return $prev;
}

/**
 * Opt-in theme card (standalone GenD page, full build). Never activates
 * anything by itself: the user presses Activate, and can revert in one click.
 */
function gend_society_theme_card_render(): void {
    if (!current_user_can('switch_themes')) {
        return;
    }
    $theme = wp_get_theme(GEND_SOCIETY_THEME_SLUG);
    if (!$theme->exists()) {
        return;
    }
    $active = gend_society_theme_is_active();
    $post   = admin_url('admin-post.php');

    echo '<div class="card gend-society-theme-card" style="max-width:640px">';
    echo '<h2>' . esc_html__('GenD Society theme', 'gend-society') . '</h2>';
    printf(
        '<p><img src="%s" alt="%s" style="max-width:100%%;height:auto;border:1px solid #dcdcde" width="600" /></p>',
        esc_url(GEND_SOCIETY_URL . 'themes/' . GEND_SOCIETY_THEME_SLUG . '/screenshot.png'),
        esc_attr__('GenD Society theme screenshot', 'gend-society')
    );
    printf(
        '<p><strong>%s</strong> %s</p>',
        esc_html($theme->get('Name')),
        esc_html(sprintf(/* translators: %s: theme version. */ __('version %s', 'gend-society'), $theme->get('Version')))
    );
    echo '<p>' . esc_html__('Optional. Your current theme stays active until you choose to switch.', 'gend-society') . '</p>';

    if (!$active) {
        echo '<form method="post" action="' . esc_url($post) . '">';
        echo '<input type="hidden" name="action" value="gend_society_theme_activate" />';
        wp_nonce_field('gend_society_theme_activate');
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Activate GenD Society theme', 'gend-society') . '</button></p>';
        echo '</form>';
    } else {
        $prev = gend_society_theme_previous();
        if ($prev) {
            echo '<p>' . esc_html__('The GenD Society theme is active.', 'gend-society') . '</p>';
            echo '<form method="post" action="' . esc_url($post) . '">';
            echo '<input type="hidden" name="action" value="gend_society_theme_revert" />';
            wp_nonce_field('gend_society_theme_revert');
            printf(
                '<p><button type="submit" class="button">%s</button></p>',
                esc_html(sprintf(/* translators: %s: previous theme name. */ __('Revert to %s', 'gend-society'), $prev['name']))
            );
            echo '</form>';
        } else {
            printf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__('The GenD Society theme is active.', 'gend-society'),
                esc_url(admin_url('themes.php')),
                esc_html__('Choose another theme', 'gend-society')
            );
        }
    }
    echo '</div>';
}

// Standalone only: activate / revert handlers and their result notice.
if (function_exists('gend_society_runtime_mode') && 'standalone' === gend_society_runtime_mode()) {
    add_action('admin_post_gend_society_theme_activate', function () {
        check_admin_referer('gend_society_theme_activate');
        if (!current_user_can('switch_themes')) {
            wp_die(esc_html__('You are not allowed to change the theme.', 'gend-society'), '', array('response' => 403));
        }
        if (!wp_get_theme(GEND_SOCIETY_THEME_SLUG)->exists()) {
            wp_safe_redirect(gend_society_theme_card_return_url('error'));
            exit;
        }
        if (!gend_society_theme_is_active()) {
            update_option(
                'gend_society_theme_previous',
                array(
                    'stylesheet' => get_stylesheet(),
                    'template'   => get_template(),
                    'name'       => wp_get_theme()->get('Name'),
                ),
                false
            );
            switch_theme(GEND_SOCIETY_THEME_SLUG);
        }
        wp_safe_redirect(gend_society_theme_card_return_url('activated'));
        exit;
    });

    add_action('admin_post_gend_society_theme_revert', function () {
        check_admin_referer('gend_society_theme_revert');
        if (!current_user_can('switch_themes')) {
            wp_die(esc_html__('You are not allowed to change the theme.', 'gend-society'), '', array('response' => 403));
        }
        $prev = gend_society_theme_previous();
        if (!$prev) {
            wp_safe_redirect(gend_society_theme_card_return_url('error'));
            exit;
        }
        switch_theme($prev['stylesheet']);
        delete_option('gend_society_theme_previous');
        wp_safe_redirect(gend_society_theme_card_return_url('reverted'));
        exit;
    });

    add_action('admin_notices', function () {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status flag.
        $page   = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status flag.
        $status = isset($_GET['gend_society_theme']) ? sanitize_key(wp_unslash($_GET['gend_society_theme'])) : '';
        if ('' === $status || !current_user_can('switch_themes')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ('gend-society' !== $page && !($screen && 'themes' === $screen->id)) {
            return;
        }
        $messages = array(
            'activated' => array('success', __('GenD Society theme activated.', 'gend-society')),
            'reverted'  => array('success', __('Previous theme restored.', 'gend-society')),
            'error'     => array('error', __('The theme could not be changed.', 'gend-society')),
        );
        if (!isset($messages[$status])) {
            return;
        }
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($messages[$status][0]),
            esc_html($messages[$status][1])
        );
    });
}
