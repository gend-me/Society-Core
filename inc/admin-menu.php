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
    add_submenu_page('gs-users', __('Feature Access', 'gend-society'), __('Feature Access', 'gend-society'), 'list_users', 'gs-feature-access', function () {
        require GS_DIR . 'inc/pages/feature-access.php';
    });

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

    // ── CONTENT ──────────────────────────────────────────────────────────────
    add_menu_page(
        __('Content', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-edit"></span><span class="gs-menu-label">' . __('Content', 'gend-society') . '</span>',
        'manage_options',
        'gs-content',
        '__return_null',
        'none',
        5
    );
    add_submenu_page('gs-content', __('Pages', 'gend-society'), __('Pages', 'gend-society'), 'edit_pages', 'edit.php?post_type=page', '');

    // ── STORE (conditional) ───────────────────────────────────────────────────
    $has_store_apps = gs_plugin_active('online-store/online-store.php') || gs_plugin_active('sales-team/advanced-affiliate-system.php') || gs_plugin_active('projects/project-service-orders.php');
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
            6
        );

        // Mirror online-store submenus if active
        if (gs_plugin_active('online-store/online-store.php')) {
            add_submenu_page('gs-store', __('Store Settings', 'gend-society'), __('Store Settings', 'gend-society'), 'manage_options', 'gdc-store-settings', 'gdc_render_store_settings_page');
            add_submenu_page(null, __('Store Reports', 'gend-society'), __('Store Reports', 'gend-society'), 'manage_options', 'gdc-store-reports', 'gdc_render_store_reports_page');
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
            7
        );
        add_submenu_page('gs-social', __('Social Profiles', 'gend-society'), __('Social Profiles', 'gend-society'), 'manage_options', 'gdc-social-network-settings', 'sn_render_network_settings_page');

        remove_submenu_page('gs-social', 'gs-social');
    }

    if (gs_plugin_active('reward-programs/reward-programs.php')) {
        add_submenu_page(
            'gs-social',
            __('Point Bank', 'gend-society'),
            __('Point Bank', 'gend-society'),
            'manage_options',
            'gs-rewards',
            'reward_programs_proxy_member_wallets'
        );
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

    // Add Permalinks to App Menu
    add_submenu_page('gs-app', __('Permalinks', 'gend-society'), __('Permalinks', 'gend-society'), 'manage_options', 'options-permalink.php', '');

    // Prevent redundant submenus from being added inside the Dashboard rendering engine by removing them late in another hook
    remove_submenu_page('gs-app', 'gs-app');
    remove_submenu_page('gs-features', 'gs-features');
    if (gs_plugin_active('online-store/online-store.php')) {
        remove_submenu_page('gs-store', 'gs-store');
    }
    remove_submenu_page('gs-social', 'gs-social');
    remove_submenu_page('gs-social', 'youzify-panel');
    remove_submenu_page('gs-social', 'youzify-profile-settings');
    remove_submenu_page('gs-social', 'youzify-widgets-settings');
    remove_submenu_page('gs-social', 'youzify-membership-settings');
    remove_submenu_page('gs-social', 'youzify-extensions-settings');
    remove_submenu_page('gs-social', 'youzify-reports');
    remove_submenu_page('gs-rewards', 'gs-rewards');
    remove_submenu_page('gs-content', 'gs-content');
    remove_submenu_page('index.php', 'index.php');
    remove_submenu_page('index.php', 'update-core.php');
    remove_submenu_page('gs-users', 'gs-users');
}

/**
 * Register network admin menus for Multisite.
 */
add_action('network_admin_menu', 'gs_register_network_admin_menu', 5);
function gs_register_network_admin_menu()
{
    // Remove default WP menus we are replacing
    remove_menu_page('users.php');                   // Users
    remove_menu_page('plugins.php');                 // Plugins
    remove_menu_page('update-core.php');             // Updates

    // ── USERS ─────────────────────────────────────────────────────────────────
    add_menu_page(
        __('Users', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-groups"></span><span class="gs-menu-label">' . __('Users', 'gend-society') . '</span>',
        'manage_network_users',
        'gs-users',
        function () {
            require GS_DIR . 'inc/pages/users.php';
        },
        'none',
        3
    );
    add_submenu_page('gs-users', __('All Users', 'gend-society'), __('All Users', 'gend-society'), 'manage_network_users', 'users.php', '');
    add_submenu_page('gs-users', __('Add New', 'gend-society'), __('Add New', 'gend-society'), 'manage_network_users', 'user-new.php', '');
    add_submenu_page('gs-users', __('Feature Access', 'gend-society'), __('Feature Access', 'gend-society'), 'manage_network_users', 'gs-feature-access', function () {
        require GS_DIR . 'inc/pages/feature-access.php';
    });

    // ── FEATURES ──────────────────────────────────────────────────────────────
    add_menu_page(
        __('Features', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-admin-plugins"></span><span class="gs-menu-label">' . __('Features', 'gend-society') . '</span>',
        'manage_network_plugins',
        'gs-features',
        function () {
            require GS_DIR . 'inc/pages/features.php';
        },
        'none',
        8
    );
    add_submenu_page('gs-features', __('Shortcodes', 'gend-society'), __('Shortcodes', 'gend-society'), 'manage_network_plugins', 'gs-shortcodes', function () {
        require GS_DIR . 'inc/pages/shortcodes.php';
    });
    add_submenu_page('gs-features', __('Code Packages', 'gend-society'), __('Code Packages', 'gend-society'), 'manage_network_plugins', 'plugins.php', '');
    add_submenu_page('gs-features', __('Updates', 'gend-society'), __('Updates', 'gend-society'), 'manage_network_plugins', 'update-core.php', '');

    remove_submenu_page('gs-features', 'gs-features');
    remove_submenu_page('gs-users', 'gs-users');
}

/**
 * Move Blog Manager and Email Manager submenus to Content if active.
 * Must run after their registration (1100).
 */
add_action('admin_menu', 'gs_move_plugin_submenus_to_content', 1200);
function gs_move_plugin_submenus_to_content()
{
    // Blog Manager
    if (gs_plugin_active('blog-manager/blog-manager.php')) {
        remove_submenu_page('gs-app', 'blog-manager');
        add_submenu_page(
            'gs-content',
            __('Content Campaigns', 'gend-society'),
            __('Content Campaigns', 'gend-society'),
            'edit_posts',
            'blog-manager',
            'bm_render_page'
        );
    }

    // Email Manager
    if (gs_plugin_active('email-manager/email-manager.php')) {
        remove_submenu_page('gs-app', 'email-manager');
        add_submenu_page(
            'gs-content',
            __('Emails & Forms', 'gend-society'),
            __('Emails & Forms', 'gend-society'),
            'manage_options',
            'email-manager',
            'em_render_email_manager_page'
        );
    }

    // Contracts & Payments — the plugin self-registers under the Store parent
    // (gdc-store) when present, OR falls back to a top-level menu when Store
    // isn't active. When social is active, we relocate it under Social
    // regardless of which path the plugin took, so we have to clear BOTH
    // possible registration sites before re-adding it.
    if (gs_plugin_active('contracts-and-payments/contracts-and-payments.php')
        && gs_plugin_active('social-network/social-network.php')) {
        remove_submenu_page('gdc-store', 'gend-contracts-payments');
        remove_menu_page('gend-contracts-payments');
        if (class_exists('Gend_CP_Admin_Page')) {
            add_submenu_page(
                'gs-social',
                __('Contracts & Payments', 'contracts-and-payments'),
                __('Contracts & Payments', 'contracts-and-payments'),
                'manage_options',
                'gend-contracts-payments',
                ['Gend_CP_Admin_Page', 'render']
            );
        }
    }
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
        'gs-content',
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
        'bp-groups',
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

        // Feature Access Control
        // Get current user and their allowed features. Super admins bypass this.
        $current_user_id = get_current_user_id();
        if (!is_super_admin($current_user_id) && !current_user_can('manage_network')) {
            $allowed_features = get_user_meta($current_user_id, 'gs_feature_access', true);
            if (!is_array($allowed_features)) {
                $allowed_features = []; // Default: No access if never set
            }

            // Always allow basic profile access
            $allowed_features[] = 'profile.php';

            if (!in_array($slug, $allowed_features, true) && !in_array($slug, ['separator', 'separator1', 'separator2', 'separator-last'], true)) {
                remove_menu_page($slug);
                unset($menu[$pos]);
                if (isset($submenu[$slug])) {
                    unset($submenu[$slug]);
                }
                continue;
            }
        }

        // Process submenu filtering if the top-level menu survived
        if (isset($submenu[$slug]) && !is_super_admin($current_user_id) && !current_user_can('manage_network')) {
            foreach ($submenu[$slug] as $sub_pos => $sub_item) {
                $sub_slug = isset($sub_item[2]) ? $sub_item[2] : '';
                if ($sub_slug && !in_array($sub_slug, $allowed_features, true) && $sub_slug !== 'profile.php') {
                    unset($submenu[$slug][$sub_pos]);
                }
            }
        }

        // Below here is the standard cleanup for WP/Plugin defaults if bypassing feature access
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
