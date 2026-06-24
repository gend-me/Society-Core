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

    // ── USERS — removed from the sidebar. Feature Access lives in the
    //    dashboard membership card's User Access tab; the standalone URL
    //    (?page=gs-feature-access) is kept registered as a hidden submenu
    //    so any existing deep links continue to resolve.
    add_submenu_page(null, __('Feature Access', 'gend-society'), __('Feature Access', 'gend-society'), 'list_users', 'gs-feature-access', function () {
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

    // ── WRITE (was "Content") ─────────────────────────────────────────────────
    // Slug stays gs-content for backwards-compat with existing deep links,
    // submenu registrations from sibling plugins, and the frontend bar's
    // slug_map. Only the visible label changed.
    add_menu_page(
        __('Write', 'gend-society'),
        '<span class="gs-menu-icon dashicons dashicons-edit"></span><span class="gs-menu-label">' . __('Write', 'gend-society') . '</span>',
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
            add_submenu_page('gs-store', __('Store Management', 'gend-society'), __('Store Management', 'gend-society'), 'manage_options', 'gdc-store-settings', 'gdc_render_store_settings_page');
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

    // ── USERS — removed from network sidebar; Feature Access lives in the
    //    dashboard membership card. Standalone gs-feature-access URL stays
    //    registered (hidden) so deep links keep working.
    add_submenu_page(null, __('Feature Access', 'gend-society'), __('Feature Access', 'gend-society'), 'manage_network_users', 'gs-feature-access', function () {
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
            __('Conversations', 'gend-society'),
            __('Conversations', 'gend-society'),
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
        'gs-app',
        'gs-content',
        'gs-store',
        'gs-social',
        'gs-rewards',
        'gs-features',
        'gs-shortcodes',
        // v6.0 Phase 21: surface reward-programs' Point Bank dashboard so
        // operators can reach the Derivatives modal mounted there. The
        // visible "Point Bank" link at gs-rewards renders Member Wallets;
        // gdc-reward-point-bank is the real Point Bank dashboard. Long-term
        // reconciliation (collapse to a single surface) is a v6.0.1 phase.
        'gdc-reward',
        'gdc-reward-point-bank',
        'gdc-reward-smart-contracts',
        'gdc-reward-member-wallets',
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
        // Reward Program — gdc-reward + children REMOVED from blocklist for v6.0
        // Phase 21 (Derivatives modal lives on gdc-reward-point-bank).
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

/**
 * Render the per-user menu access checkbox grid used by the Feature Access
 * tab and the modal that opens from the user list.
 *
 * Lives here (always loaded) so the AJAX endpoints below can call it
 * without requiring the side-effecting feature-access.php page file.
 * Reads $menu/$submenu — admin-ajax.php fires admin_menu, so those globals
 * are populated by the time this runs.
 */
/**
 * Build (or fetch from cache) the normalized list of menu items used by the
 * Manage Access checkbox grid. The expensive bit — firing `admin_menu` so
 * every plugin registers its pages — runs once per cache window instead of
 * on every modal open.
 *
 * Cache lasts an hour but is busted on plugin (de)activation. If a customer
 * really needs a fresh menu list (e.g. they just deployed a new plugin),
 * they can hit the standalone /wp-admin/admin.php?page=gs-feature-access
 * page once — that path falls through to the real $menu / $submenu globals
 * directly without touching the transient.
 */
if (!function_exists('gs_get_admin_menu_structure_cached')) {
    function gs_get_admin_menu_structure_cached($force_rebuild = false) {
        $cache_key = 'gs_admin_menu_structure_v1';
        if (!$force_rebuild) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        global $menu, $submenu;
        if (empty($menu) || !is_array($menu)) {
            if (!is_array($menu)) $menu = array();
            if (!is_array($submenu)) $submenu = array();
            // admin-ajax doesn't fire admin_menu; do it here, buffered so any
            // plugin echo/notice can't break JSON responses up the stack.
            ob_start();
            try {
                do_action('_admin_menu');
                do_action('admin_menu', '');
            } catch (\Throwable $e) {
                // Best-effort: keep whatever registered successfully.
            }
            ob_end_clean();
        }

        $items = array();
        foreach ((array) $menu as $item) {
            $menu_slug = isset($item[2]) ? $item[2] : '';
            if (!$menu_slug || $menu_slug === 'separator' || strpos($menu_slug, 'separator') === 0) {
                continue;
            }
            $menu_name = wp_strip_all_tags(isset($item[0]) ? $item[0] : '');
            $sub_items = array();
            if (isset($submenu[$menu_slug]) && is_array($submenu[$menu_slug])) {
                foreach ($submenu[$menu_slug] as $sub_item) {
                    $sub_slug = isset($sub_item[2]) ? $sub_item[2] : '';
                    if (!$sub_slug) continue;
                    $sub_items[] = array(
                        'slug' => $sub_slug,
                        'name' => wp_strip_all_tags(isset($sub_item[0]) ? $sub_item[0] : ''),
                    );
                }
            }
            $items[] = array(
                'slug'    => $menu_slug,
                'name'    => $menu_name,
                'submenu' => $sub_items,
            );
        }

        set_transient($cache_key, $items, HOUR_IN_SECONDS);
        return $items;
    }
}

// Bust the menu cache whenever the install's plugin set changes.
add_action('activated_plugin',   function () { delete_transient('gs_admin_menu_structure_v1'); });
add_action('deactivated_plugin', function () { delete_transient('gs_admin_menu_structure_v1'); });
add_action('upgrader_process_complete', function () { delete_transient('gs_admin_menu_structure_v1'); });
add_action('switch_theme',       function () { delete_transient('gs_admin_menu_structure_v1'); });

if (!function_exists('gs_render_menu_access_checkboxes')) {
    function gs_render_menu_access_checkboxes($target_user_id) {
        $saved_access = get_user_meta($target_user_id, 'gs_feature_access', true);
        if (!is_array($saved_access)) {
            $saved_access = [];
        }
        // Quick lookup map so the per-item in_array() calls don't get
        // O(n²) on installs with hundreds of menu items.
        $saved_lookup = array_flip($saved_access);

        $items = gs_get_admin_menu_structure_cached();

        $html = '<div class="gs-grid gs-grid-2">';
        foreach ($items as $item) {
            $menu_slug = $item['slug'];
            $menu_name = $item['name'];
            $is_menu_checked = isset($saved_lookup[$menu_slug]) ? 'checked' : '';

            $html .= '<div class="gs-card" style="margin-bottom: 20px; padding: 15px;">';
            $html .= '<h4><label><input type="checkbox" name="gs_allowed_menus[]" value="' . esc_attr($menu_slug) . '" ' . $is_menu_checked . ' class="gs-parent-checkbox"> <strong>' . esc_html($menu_name) . '</strong></label></h4>';

            if (!empty($item['submenu'])) {
                $html .= '<ul style="margin-left: 20px;">';
                foreach ($item['submenu'] as $sub) {
                    $is_sub_checked = isset($saved_lookup[$sub['slug']]) ? 'checked' : '';
                    $html .= '<li><label><input type="checkbox" name="gs_allowed_menus[]" value="' . esc_attr($sub['slug']) . '" ' . $is_sub_checked . ' class="gs-child-checkbox"> ' . esc_html($sub['name']) . '</label></li>';
                }
                $html .= '</ul>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}

/**
 * AJAX: return the checkbox grid HTML + user label for a given user_id.
 * Powers the Manage Access modal in the User Access tab.
 */
add_action('wp_ajax_gs_feature_access_form', 'gs_ajax_feature_access_form');
function gs_ajax_feature_access_form() {
    if (!current_user_can('list_users')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'gend-society')), 403);
    }
    check_ajax_referer('gs_feature_access_modal', 'nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $user = $user_id ? get_userdata($user_id) : null;
    if (!$user || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(array('message' => __('User not found or not editable.', 'gend-society')));
    }

    // gs_render_menu_access_checkboxes() pulls the menu structure from a
    // 1-hour transient (busted on plugin (de)activation), so we no longer
    // fire admin_menu on every modal click — that's what was making the
    // popup take seconds to load on installs with many active plugins.

    $label = $user->display_name;
    if (!empty($user->user_email)) {
        $label .= ' (' . $user->user_email . ')';
    }

    wp_send_json_success(array(
        'user_id'    => $user_id,
        'user_label' => $label,
        'html'       => gs_render_menu_access_checkboxes($user_id),
    ));
}

/**
 * AJAX: save the per-user feature access selections.
 */
add_action('wp_ajax_gs_feature_access_save', 'gs_ajax_feature_access_save');
function gs_ajax_feature_access_save() {
    if (!current_user_can('list_users')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'gend-society')), 403);
    }
    check_ajax_referer('gs_feature_access_modal', 'nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(array('message' => __('You do not have permission to edit this user.', 'gend-society')));
    }

    $allowed_slugs = isset($_POST['gs_allowed_menus']) && is_array($_POST['gs_allowed_menus'])
        ? array_map('sanitize_text_field', wp_unslash($_POST['gs_allowed_menus']))
        : [];
    update_user_meta($user_id, 'gs_feature_access', $allowed_slugs);

    wp_send_json_success(array(
        'user_id' => $user_id,
        'count'   => count($allowed_slugs),
    ));
}
