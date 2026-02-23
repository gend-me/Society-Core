<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper: check if a plugin is active (works on multisite too).
 */
function gs_plugin_active($slug)
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active($slug) || is_plugin_active_for_network($slug);
}

/**
 * Remove the default WP menus and register the GenD Society menu.
 */
add_action('admin_menu', 'gs_register_admin_menu', 5);
function gs_register_admin_menu()
{
    // Remove default WP top-level menus we are replacing
    remove_menu_page('index.php');                   // Dashboard
    remove_menu_page('users.php');                   // Users
    remove_menu_page('plugins.php');                 // Plugins
    remove_menu_page('update-core.php');             // Remove default WP menus
    remove_menu_page('edit.php');                    // Posts
    remove_menu_page('edit.php?post_type=page');     // Pages
    remove_menu_page('upload.php');                  // Media
    remove_menu_page('edit-comments.php');           // Comments
    remove_menu_page('themes.php');                  // Appearance
    remove_menu_page('tools.php');                   // Tools
    remove_menu_page('options-general.php');         // Settings

    // --- DASHBOARD ---
    // Use index.php so WP Ultimo's membership checks and GenD Core's native overrides work correctly
    add_menu_page(
        __('Dashboard', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-dashboard"></span><span class="gs-menu-label">' . __('Dashboard', 'gend-society') . '</span>',
        'read',
        'index.php',
        '', // Native WP dashboard callback handles this
        'none',
        2
    );

    // ── USERS ─────────────────────────────────────────────────────────────────
    add_menu_page(
        __('Users', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-groups"></span><span class="gs-menu-label">' . __('Users', 'gend-society') . '</span>',
        'list_users',
        'gs-users',
        function () {
            require GS_DIR . 'inc/pages/users.php';
        },
        'none',
        3
    );
    add_submenu_page('gs-users', __('All Users', 'gend-society'), __('All Users', 'gend-society'), 'list_users', 'users.php', '');
    add_submenu_page('gs-users', __('Add New', 'gend-society'), __('Add New', 'gend-society'), 'create_users', 'user-new.php', '');
    add_submenu_page('gs-users', __('Area Assignment', 'gend-society'), __('Area Assignment', 'gend-society'), 'list_users', 'gs-users', '');

    // ── APP ───────────────────────────────────────────────────────────────────
    add_menu_page(
        __('App', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-admin-appearance"></span><span class="gs-menu-label">' . __('App', 'gend-society') . '</span>',
        'edit_theme_options',
        'gs-app',
        function () {
            require GS_DIR . 'inc/pages/app.php';
        },
        'none',
        4
    );
    add_submenu_page('gs-app', __('Theme Editor', 'gend-society'), __('Theme Editor', 'gend-society'), 'edit_theme_options', 'site-editor.php', '');
    add_submenu_page('gs-app', __('Media', 'gend-society'), __('Media', 'gend-society'), 'upload_files', 'upload.php', '');

    // Note: Blog Manager and Email Manager register their own submenus under gs-app.

    // ── STORE (conditional) ───────────────────────────────────────────────────
    $has_store_apps = gs_plugin_active('online-store/online-store.php') || gs_plugin_active('sales-team/sales-team.php') || gs_plugin_active('projects/projects.php');
    if ($has_store_apps) {
        add_menu_page(
            __('Store', 'gend-society'),
            '<span class="gs-menu-icon dashicons dashicons-store"></span><span class="gs-menu-label">' . __('Store', 'gend-society') . '</span>',
            'manage_options', // Use lower capability so it shows up for sales/project managers even if they aren't shop managers
            'gs-store',
            function () {
                require apply_filters('gs_store_dashboard_path', GS_DIR . 'inc/pages/store.php');
            },
            'none',
            5
        );

        // Mirror online-store submenus if active
        if (gs_plugin_active('online-store/online-store.php')) {
            add_submenu_page('gs-store', __('Store Settings', 'gend-society'), __('Store Settings', 'gend-society'), 'manage_options', 'gdc-store-settings', 'gdc_render_store_settings_page');
            add_submenu_page('gs-store', __('Products & Funnels', 'gend-society'), __('Products & Funnels', 'gend-society'), 'manage_woocommerce', 'gdc-store-product-sales-funnels', 'gdc_render_product_sales_funnels_page');
            add_submenu_page('gs-store', __('Order Management', 'gend-society'), __('Order Management', 'gend-society'), 'manage_options', 'gdc-store-order-management', 'gdc_render_order_management_page');
            add_submenu_page(null, __('Store Reports', 'gend-society'), __('Store Reports', 'gend-society'), 'manage_options', 'gdc-store-reports', 'gdc_render_store_reports_page');
            add_submenu_page('gs-store', __('Advanced Product Fields', 'gend-society'), __('Advanced Product Fields', 'gend-society'), 'manage_options', 'edit.php?post_type=wapf_product', '');
        }

        // Note: Sales Team and Projects register their own submenus under gs-store, so we don't need to manually add_submenu_page for them here.
    }

    // ── SOCIAL (conditional) ──────────────────────────────────────────────────
    if (gs_plugin_active('social-network/social-network.php')) {
        add_menu_page(
            __('Social', 'gend-society'),
            '<span class="gs-menu-icon dashicons dashicons-share"></span><span class="gs-menu-label">' . __('Social', 'gend-society') . '</span>',
            'manage_options',
            'gs-social',
            '__return_null',
            'none',
            6
        );
        add_submenu_page('gs-social', __('Network Settings', 'gend-society'), __('Network Settings', 'gend-society'), 'manage_options', 'gdc-social-network-settings', '');
        add_submenu_page('gs-social', __('Profile Features', 'gend-society'), __('Profile Features', 'gend-society'), 'manage_options', 'gdc-social-profile-features', '');
        add_submenu_page('gs-social', __('Membership System', 'gend-society'), __('Membership System', 'gend-society'), 'manage_options', 'gdc-social-membership-system', '');
        remove_submenu_page('gs-social', 'gs-social');
    }

    // ── REWARDS (conditional) ─────────────────────────────────────────────────
    if (gs_plugin_active('reward-programs/reward-programs.php')) {
        add_menu_page(
            __('Rewards', 'gend-society'),
            '<span class="gs-menu-icon dashicons dashicons-awards"></span><span class="gs-menu-label">' . __('Rewards', 'gend-society') . '</span>',
            'manage_options',
            'gs-rewards',
            '__return_null',
            'none',
            7
        );
        add_submenu_page('gs-rewards', __('Points', 'gend-society'), __('Points', 'gend-society'), 'manage_options', 'gdc-reward-points', '');
        add_submenu_page('gs-rewards', __('Wallets', 'gend-society'), __('Wallets', 'gend-society'), 'manage_options', 'gdc-reward-wallets', '');
        remove_submenu_page('gs-rewards', 'gs-rewards');
    }

    // ── FEATURES ──────────────────────────────────────────────────────────────
    add_menu_page(
        __('Features', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-admin-plugins"></span><span class="gs-menu-label">' . __('Features', 'gend-society') . '</span>',
        'activate_plugins',
        'gs-features',
        function () {
            require GS_DIR . 'inc/pages/features.php';
        },
        'none',
        8
    );
    add_submenu_page('gs-features', __('Shortcodes', 'gend-society'), __('Shortcodes', 'gend-society'), 'activate_plugins', 'gs-shortcodes', function () {
        require GS_DIR . 'inc/pages/shortcodes.php';
    });
    add_submenu_page('gs-features', __('Code Packages', 'gend-society'), __('Code Packages', 'gend-society'), 'activate_plugins', 'plugins.php', '');
    add_submenu_page('gs-features', __('Updates', 'gend-society'), __('Updates', 'gend-society'), 'update_core', 'update-core.php', '');

    // Prevent redundant submenus from being added inside the Dashboard rendering engine by removing them late in another hook
    remove_submenu_page('gs-app', 'gs-app');
    remove_submenu_page('gs-features', 'gs-features');
    if (gs_plugin_active('online-store/online-store.php')) {
        remove_submenu_page('gs-store', 'gs-store');
    }
    remove_submenu_page('gs-social', 'gs-social');
    remove_submenu_page('gs-rewards', 'gs-rewards');
}

/**
 * Hook into menu_order to suppress menus AFTER all plugins (even late ones) have registered theirs
 */
add_filter('menu_order', 'gs_suppress_plugin_menus_via_filter', 99999);
function gs_suppress_plugin_menus_via_filter($menu_ord)
{
    gs_suppress_plugin_menus();
    return $menu_ord;
}


function gs_suppress_plugin_menus()
{
    global $menu;
    if (!is_array($menu)) {
        return;
    }

    // Explicitly unset the index.php submenu array so "Home" and "Updates" do not appear as flyouts
    global $submenu;
    if (isset($submenu['index.php'])) {
        unset($submenu['index.php']);
    }

    // Slugs GenD Society owns — everything else gets removed
    $gs_owned = [
        'index.php',
        'gs-users',
        'gs-app',
        'gs-store',
        'gs-social',
        'gs-rewards',
        'gs-features',
        'gs-shortcodes',
        // WP native pages that are legitimately needed (submenus/redirects)
        'separator',
        'separator1',
        'separator2',
        'separator-last',
    ];

    // Known plugin-registered menu slugs to explicitly remove (belt + braces)
    $plugin_slugs = [
        // Online Store
        'gdc-store',
        'gdc-store-orders',
        'gdc-store-settings',
        'gdc-store-product-sales-funnels',
        'gdc-store-reports',
        // Social Network
        'gdc-social',
        'gdc-social-network-settings',
        'gdc-social-profile-features',
        'gdc-social-membership-system',
        // Reward Program
        'gdc-reward',
        'gdc-reward-points',
        'gdc-reward-wallets',
        'gdc-rewards',
        // Blog Manager
        'blog-manager',
        'gdc-blog-manager',
        'gdc-blog',
        // Email Manager
        'email-manager',
        'gdc-app-email',
        'gdc-email-manager',
        // Sales Team
        'st_sales_team',
        'gdc-sales-team',
        // Projects
        'psoo-projects',
        'gdc-projects',
        'gdc-project-manager',
        // GenD Core legacy menus (if still active)
        'gdc-dashboard',
        'gdc-app',
        'gdc-users',
        'gdc-features',
        'gdc-network-settings',
    ];

    foreach ($menu as $pos => $item) {
        $slug = isset($item[2]) ? $item[2] : '';
        if (!$slug) {
            continue;
        }
        // Skip our own menu items and separators
        if (in_array($slug, $gs_owned, true)) {
            continue;
        }
        // Suppress known plugin slugs
        if (in_array($slug, $plugin_slugs, true)) {
            remove_menu_page($slug);
            // Fallback: forcefully unset it from the global array if remove_menu_page fails
            unset($menu[$pos]);
            if (isset($submenu[$slug])) {
                unset($submenu[$slug]);
            }
            continue;
        }
        // Suppress WP core pages we consciously removed
        $wp_remove = [
            'index.php',
            'users.php',
            'plugins.php',
            'update-core.php',
            'edit.php',
            'edit.php?post_type=page',
            'upload.php',
            'edit-comments.php',
            'themes.php',
            'tools.php',
            'options-general.php',
        ];
        if (in_array($slug, $wp_remove, true)) {
            remove_menu_page($slug);
        }
    }
}
