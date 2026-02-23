<?php
/**
 * Standalone Dashboard for GenD Society
 * Completely replaces the default WordPress dashboard (index.php)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('load-index.php', 'gs_dashboard_setup_custom_screen');
function gs_dashboard_setup_custom_screen()
{
    // 1. Remove default meta boxes and welcome panel
    add_action('wp_dashboard_setup', 'gs_dashboard_remove_default_widgets', 100);
    remove_action('welcome_panel', 'wp_welcome_panel');

    // 2. Inject our custom dashboard HTML where notices usually go (above the now-empty dashboard grid)
    add_action('all_admin_notices', 'gs_render_custom_dashboard_screen', 0);

    // 3. Add styles to hide leftover wpbody stuff
    add_action('admin_head-index.php', 'gs_dashboard_admin_head_styles');

    // 4. Remove help tabs and screen options
    add_action('current_screen', 'gs_dashboard_strip_screen_meta', 20);
    add_filter('screen_options_show_screen', 'gs_dashboard_hide_screen_options', 20);
}

function gs_dashboard_remove_default_widgets()
{
    $widgets = array(
        'dashboard_right_now',
        'dashboard_quick_press',
        'dashboard_activity',
        'dashboard_primary',
        'dashboard_site_health',
        'dashboard_incoming_links',
        'dashboard_plugins',
        'dashboard_recent_drafts',
        'dashboard_recent_comments'
    );

    foreach ($widgets as $widget) {
        remove_meta_box($widget, 'dashboard', 'normal');
        remove_meta_box($widget, 'dashboard', 'side');
    }
}

function gs_dashboard_strip_screen_meta($screen)
{
    if (!$screen || !isset($screen->id) || $screen->id !== 'dashboard') {
        return;
    }

    $screen->remove_help_tabs();
    if (method_exists($screen, 'set_screen_reader_content')) {
        $screen->set_screen_reader_content(array());
    }
}

function gs_dashboard_hide_screen_options($show)
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && isset($screen->id) && $screen->id === 'dashboard') {
        return false;
    }

    return $show;
}

function gs_dashboard_admin_head_styles()
{
    // Very specific styles to completely hide the native index.php layout
    // We already have glassmorphic styles from our overall admin-style.css, but this ensures index.php is fully overridden
    echo '<style>
        body.wp-admin.index-php #screen-meta,
        body.wp-admin.index-php #screen-meta-links { display:none !important; }
        body.wp-admin.index-php #wpbody-content > .wrap > h1,
        body.wp-admin.index-php #wpbody-content > .wrap > .welcome-panel,
        body.wp-admin.index-php #dashboard-widgets-wrap { display:none !important; }
        
        .gs-dashboard-wrap {
            padding: 40px clamp(20px, 4vw, 50px) 60px;
            display: flex;
            flex-direction: column;
            gap: 32px;
            color: var(--gs-text);
        }
        .gs-dashboard__header {
            margin-bottom: 10px;
        }
        .gs-dashboard__badge {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(182, 8, 201, 0.15);
            border: 1px solid rgba(182, 8, 201, 0.3);
            border-radius: 999px;
            color: #d881e6;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }
        .gs-dashboard__title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 8px 0;
            line-height: 1.2;
            padding: 5px 0;
            background: linear-gradient(90deg, #fff, #b8c7db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .gs-dashboard__intro {
            font-size: 1.1rem;
            color: var(--gs-muted);
            margin: 0;
            max-width: 700px;
        }
        
        /* Surface Cards */
        .gs-dashboard__surface {
            background: var(--gs-card-bg, rgba(11, 14, 20, 0.6));
            border: 1px solid var(--gs-border);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(20px);
        }
        .gs-dashboard__surface h2 {
            margin: 0 0 20px 0;
            color: #fff;
            font-size: 1.4rem;
        }
        
        /* Admin User Grid (Stand-in for account data) */
        .gs-admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
        }
        .gs-admin-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 20px;
        }
        .gs-admin-card h3 {
            margin: 0 0 10px 0;
            color: #fff;
            font-size: 1.1rem;
        }
        .gs-admin-card p {
            color: var(--gs-muted);
            font-size: 0.9rem;
            margin-bottom: 16px;
        }
        
        .gs-admin-user {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .gs-admin-user:last-child {
            border-bottom: none;
        }
        .gs-admin-user img.avatar {
            border-radius: 50%;
            width: 40px;
            height: 40px;
        }
        .gs-admin-user-info {
            flex: 1;
        }
        .gs-admin-user-name {
            font-weight: 600;
            color: #fff;
        }
        .gs-admin-user-role {
            font-size: 0.8rem;
            color: var(--gs-muted);
        }
        .gs-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            background: rgba(182, 8, 201, 0.2);
            color: #fff;
            border: 1px solid rgba(182, 8, 201, 0.5);
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .gs-btn:hover {
            background: var(--gs-magenta);
            border-color: var(--gs-magenta);
            color: #fff;
        }
        .gs-btn-secondary {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.1);
        }
        .gs-btn-secondary:hover {
            background: rgba(255,255,255,0.1);
        }
    </style>';
}

/**
 * Get membership from WP Ultimo (Stand-in for account data)
 */
function gs_dashboard_get_membership()
{
    if (function_exists('WP_Ultimo') && WP_Ultimo()->is_loaded()) {
        try {
            return WP_Ultimo()->currents->get_membership();
        } catch (Throwable $e) {
            return null;
        }
    }
    return null;
}

/**
 * Render the fallback local administrators panel
 */
function gs_dashboard_render_admin_users_panel()
{
    $blog_id = get_current_blog_id();
    $users = get_users(array(
        'blog_id' => $blog_id,
        'orderby' => 'display_name',
        'order' => 'ASC',
        'fields' => 'all',
    ));

    $administrators = array();
    foreach ((array) $users as $user) {
        if ($user instanceof WP_User && user_can($user, 'manage_options')) {
            $administrators[] = $user;
        }
    }

    $list = '';
    $display = array_slice($administrators, 0, 5);
    foreach ($display as $admin_user) {
        $name = $admin_user->display_name ? $admin_user->display_name : $admin_user->user_login;
        $profile_url = admin_url('user-edit.php?user_id=' . $admin_user->ID);
        $role = translate_user_role('Administrator');
        $avatar = get_avatar($admin_user->user_email, 80, '', '', array('class' => 'avatar'));

        $list .= '<div class="gs-admin-user">';
        $list .= $avatar;
        $list .= '<div class="gs-admin-user-info">';
        $list .= '<div class="gs-admin-user-name">' . esc_html($name) . '</div>';
        $list .= '<div class="gs-admin-user-role">' . esc_html($role) . '</div>';
        $list .= '</div>';
        $list .= '<a href="' . esc_url($profile_url) . '" class="gs-btn gs-btn-secondary">Edit</a>';
        $list .= '</div>';
    }

    $output = '<div class="gs-admin-grid">';

    // Admins Card
    $output .= '<div class="gs-admin-card">';
    $output .= '<h3>' . esc_html__('Site Administrators', 'gend-society') . '</h3>';
    $output .= '<p>' . esc_html__('People with full dashboard access to this site.', 'gend-society') . '</p>';
    if (empty($administrators)) {
        $output .= '<p>No administrators found.</p>';
    } else {
        $output .= '<div>' . $list . '</div>';
    }
    $output .= '<div style="margin-top: 20px; text-align: right;">';
    $output .= '<a href="' . admin_url('users.php') . '" class="gs-btn gs-btn-secondary" style="margin-right: 8px;">Manage Users</a>';
    $output .= '<a href="' . admin_url('user-new.php') . '" class="gs-btn">Add User</a>';
    $output .= '</div>';
    $output .= '</div>'; // End Admins Card

    // User Access Controls Card
    $output .= '<div class="gs-admin-card">';
    $output .= '<h3>' . esc_html__('User Access Controls', 'gend-society') . '</h3>';
    $output .= '<p>' . esc_html__('Assign dashboard roles and manage site registrations.', 'gend-society') . '</p>';
    $output .= '<div style="margin-top: 20px; text-align: right;">';
    $output .= '<a href="' . admin_url('admin.php?page=gs-users') . '" class="gs-btn">Open User Access</a>';
    $output .= '</div>';
    $output .= '</div>'; // End User Access

    $output .= '</div>'; // End Grid

    return $output;
}

/**
 * Main render function hooked into all_admin_notices
 */
function gs_render_custom_dashboard_screen()
{
    if (!current_user_can('read')) {
        return;
    }

    $can_manage_site = current_user_can('manage_options');
    $account_section = '';

    $membership = gs_dashboard_get_membership();

    if ($membership && function_exists('gs_get_account_overview_html')) {
        $account_section = gs_get_account_overview_html($membership);
    }

    // Fallback if no WP Ultimo module
    if ($account_section === '' && !$membership && $can_manage_site) {
        $account_section = gs_dashboard_render_admin_users_panel();
    } elseif ($account_section === '' && !$membership) {
        $account_section = '<div class="notice notice-warning"><p>No active membership found for this site.</p></div>';
    }

    echo '<div class="gs-dashboard-wrap">';

    echo '<div class="gs-dashboard__header">';
    echo '<span class="gs-dashboard__badge">Command Center</span>';
    echo '<h1 class="gs-dashboard__title">Dashboard</h1>';
    echo '<p class="gs-dashboard__intro">Monitor membership health, manage team access, and spot opportunities to grow your app.</p>';
    echo '</div>';

    // Output the account/membership section
    if ($account_section !== '') {
        echo '<section class="gs-dashboard__surface">';
        echo $account_section;
        echo '</section>';
    }

    // Render feature cards directly in the dashboard
    if (current_user_can('manage_options') && function_exists('gs_render_feature_cards_widget')) {
        echo '<section class="gs-dashboard__surface" style="margin-top: 32px;">';
        echo '<h2>' . esc_html__('App Feature Access', 'gend-society') . '</h2>';
        echo '<p style="color: var(--gs-muted); margin-bottom: 24px;">' . esc_html__('Manage which plugins and features are available on this site.', 'gend-society') . '</p>';
        gs_render_feature_cards_widget();
        echo '</section>';
    }

    echo '</div>';
}