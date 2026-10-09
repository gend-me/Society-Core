<?php
/**
 * Standalone Dashboard for GenD Society
 * Completely replaces the default WordPress dashboard (index.php)
 */

if (!defined('ABSPATH')) {
    exit;
}

// 0. Handle Form Submission for App Settings
add_action('admin_post_gend_society_save_app_settings', 'gend_society_dashboard_save_app_settings');
function gend_society_dashboard_save_app_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'gend-society'));
    }

    check_admin_referer('gs_app_settings_action', 'gs_app_settings_nonce');

    if (isset($_POST['gs_app_title'])) {
        update_option('blogname', sanitize_text_field($_POST['gs_app_title']));
    }
    if (isset($_POST['gs_app_tagline'])) {
        update_option('blogdescription', sanitize_text_field($_POST['gs_app_tagline']));
    }
    if (isset($_POST['gs_app_icon'])) {
        update_option('site_icon', absint($_POST['gs_app_icon']));
    }
    if (isset($_POST['gs_site_logo'])) {
        set_theme_mod('custom_logo', absint($_POST['gs_site_logo']));
    }

    wp_safe_redirect(add_query_arg('gs_settings_saved', 'true', admin_url('index.php')));
    exit;
}

// 0.1 Handle Form Submission for Permalink Settings — moved here from the
// standalone wp-admin/options-permalink.php screen (was the App → Permalinks
// submenu). Mirrors that core screen's own sanitization/update logic
// (see wp-admin/options-permalink.php) so behavior stays identical.
add_action('admin_post_gend_society_save_permalink_settings', 'gend_society_dashboard_save_permalink_settings');
function gend_society_dashboard_save_permalink_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'gend-society'));
    }

    check_admin_referer('gs_permalink_settings_action', 'gs_permalink_settings_nonce');

    global $wp_rewrite;

    $permalink_structure = get_option('permalink_structure');
    $blog_prefix = '';
    if (
        is_multisite() && !is_subdomain_install() && is_main_site()
        && str_starts_with((string) $permalink_structure, '/blog/')
    ) {
        $blog_prefix = '/blog';
    }
    $index_php_prefix = got_url_rewrite() ? '' : '/index.php';

    if (isset($_POST['permalink_structure']) || isset($_POST['selection'])) {
        if (isset($_POST['selection']) && 'custom' !== $_POST['selection']) {
            $permalink_structure = $_POST['selection'];
        } else {
            $permalink_structure = isset($_POST['permalink_structure']) ? $_POST['permalink_structure'] : '';
        }

        if (!empty($permalink_structure)) {
            $permalink_structure = preg_replace('#/+#', '/', '/' . str_replace('#', '', $permalink_structure));

            if ($index_php_prefix && $blog_prefix) {
                $permalink_structure = $index_php_prefix . preg_replace('#^/?index\.php#', '', $permalink_structure);
            } else {
                $permalink_structure = $blog_prefix . $permalink_structure;
            }
        }

        $permalink_structure = sanitize_option('permalink_structure', $permalink_structure);
        $wp_rewrite->set_permalink_structure($permalink_structure);
    }

    if (isset($_POST['category_base'])) {
        $category_base = $_POST['category_base'];
        if (!empty($category_base)) {
            $category_base = $blog_prefix . preg_replace('#/+#', '/', '/' . str_replace('#', '', $category_base));
        }
        $wp_rewrite->set_category_base($category_base);
    }

    if (isset($_POST['tag_base'])) {
        $tag_base = $_POST['tag_base'];
        if (!empty($tag_base)) {
            $tag_base = $blog_prefix . preg_replace('#/+#', '/', '/' . str_replace('#', '', $tag_base));
        }
        $wp_rewrite->set_tag_base($tag_base);
    }

    flush_rewrite_rules();

    // gs_section reopens the Hosting → Permalinks sub-tab (see gs_render_membership_panel()).
    wp_safe_redirect(add_query_arg(array('gs_settings_saved' => 'true', 'gs_section' => 'permalinks'), admin_url('index.php')));
    exit;
}

// 0.2 Application Passwords — create/revoke handlers. The stock screen
// (wp-admin → Users → Profile → Application Passwords) is unreachable in
// the rebuilt admin, so the Settings tab hosts its own manager (rendered
// by gs_render_application_passwords_form() below). App passwords are
// what GenD Mobile and other API clients sign in with — never the real
// account password.
add_action('admin_post_gend_society_app_password_create', 'gend_society_dashboard_app_password_create');
function gend_society_dashboard_app_password_create()
{
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'gend-society'));
    }
    check_admin_referer('gs_app_pw_action', 'gs_app_pw_nonce');

    $user_id = get_current_user_id();
    $name    = isset($_POST['gs_app_pw_name']) ? sanitize_text_field(wp_unslash($_POST['gs_app_pw_name'])) : '';
    if ($name === '') {
        $name = __('GenD Mobile', 'gend-society');
    }

    $flag = 'error';
    if (class_exists('WP_Application_Passwords')) {
        $created = WP_Application_Passwords::create_new_application_password($user_id, array('name' => $name));
        if (!is_wp_error($created)) {
            // $created = [ plaintext, item ]. The plaintext exists only now —
            // stash it briefly so the dashboard can show it exactly once
            // after the redirect, then it's gone for good (core only stores
            // the hash).
            set_transient('gend_society_app_pw_new_' . $user_id, array(
                'name'     => $name,
                'password' => $created[0],
            ), 5 * MINUTE_IN_SECONDS);
            $flag = 'created';
        }
    }

    wp_safe_redirect(add_query_arg('gs_app_pw', $flag, admin_url('index.php')));
    exit;
}

add_action('admin_post_gend_society_app_password_revoke', 'gend_society_dashboard_app_password_revoke');
function gend_society_dashboard_app_password_revoke()
{
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'gend-society'));
    }
    check_admin_referer('gs_app_pw_action', 'gs_app_pw_nonce');

    $user_id = get_current_user_id();
    $uuid    = isset($_POST['gs_app_pw_uuid']) ? sanitize_text_field(wp_unslash($_POST['gs_app_pw_uuid'])) : '';

    $flag = 'error';
    if ($uuid !== '' && class_exists('WP_Application_Passwords')) {
        $deleted = WP_Application_Passwords::delete_application_password($user_id, $uuid);
        if (!is_wp_error($deleted) && $deleted) {
            $flag = 'revoked';
        }
    }

    wp_safe_redirect(add_query_arg('gs_app_pw', $flag, admin_url('index.php')));
    exit;
}

add_action('load-index.php', 'gend_society_dashboard_setup_custom_screen');
function gend_society_dashboard_setup_custom_screen()
{
    // 1. Remove default meta boxes and welcome panel
    add_action('wp_dashboard_setup', 'gend_society_dashboard_remove_default_widgets', 100);
    remove_action('welcome_panel', 'wp_welcome_panel');

    // 2. Inject our custom dashboard HTML where notices usually go (above the now-empty dashboard grid)
    add_action('all_admin_notices', 'gend_society_render_custom_dashboard_screen', 0);

    // 3. Add styles to hide leftover wpbody stuff
    add_action('admin_head-index.php', 'gend_society_dashboard_admin_head_styles');

    // 4. Remove help tabs and screen options
    add_action('current_screen', 'gend_society_dashboard_strip_screen_meta', 20);
    add_filter('screen_options_show_screen', 'gend_society_dashboard_hide_screen_options', 20);

    // 5. Enqueue Media Uploader for App Icon
    add_action('admin_enqueue_scripts', 'gend_society_dashboard_enqueue_media');
}

function gend_society_dashboard_enqueue_media() {
    wp_enqueue_media();
    // Invite New User modal (in the User Access tab) embeds wp_editor /
    // TinyMCE via gs_invite_render_panel(). Without these enqueues, the
    // editor renders as a plain textarea inside the modal.
    if ( function_exists( 'wp_enqueue_editor' ) ) {
        wp_enqueue_editor();
    }
}

function gend_society_dashboard_remove_default_widgets()
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

function gend_society_dashboard_strip_screen_meta($screen)
{
    if (!$screen || !isset($screen->id) || $screen->id !== 'dashboard') {
        return;
    }

    $screen->remove_help_tabs();
    if (method_exists($screen, 'set_screen_reader_content')) {
        $screen->set_screen_reader_content(array());
    }
}

function gend_society_dashboard_hide_screen_options($show)
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && isset($screen->id) && $screen->id === 'dashboard') {
        return false;
    }

    return $show;
}

function gend_society_dashboard_admin_head_styles()
{
    // Very specific styles to completely hide the native index.php layout
    // We already have glassmorphic styles from our overall admin-style.css, but this ensures index.php is fully overridden
    echo '<style>
        body.wp-admin.index-php #screen-meta,
        body.wp-admin.index-php #screen-meta-links { display:none !important; }
        body.wp-admin.index-php #wpbody-content > .wrap > h1,
        body.wp-admin.index-php #wpbody-content > .wrap > .welcome-panel,
        body.wp-admin.index-php #dashboard-widgets-wrap { display:none !important; }

        /* Background gif + transparent chrome live in the global admin_head
           block in inc/admin-style.php so every wp-admin page shares them.
           Dashboard-specific glass surfaces continue below. */

        .gs-dashboard-wrap {
            padding: 40px clamp(20px, 4vw, 50px) 60px;
            display: flex;
            flex-direction: column;
            gap: 32px;
            color: var(--gs-text);
            position: relative;
            z-index: 1;
        }
        /* ── Glass surfaces ────────────────────────────────────────────
           A consistent "frosted panel over the gif" look applied to every
           card on the dashboard. Backdrop blur + saturate so the gif
           behind it stays vibrant; inset highlight so the panels feel
           lifted rather than flat. Lower alpha than before so the gif
           actually reads through the glass instead of being masked by it. */
        .gs-dashboard__surface,
        body.wp-admin.index-php .gs-fa-card,
        body.wp-admin.index-php .gs-card,
        body.wp-admin.index-php .gs-admin-card,
        body.wp-admin.index-php .gs-hosting__card {
            background: linear-gradient(180deg, rgba(20, 24, 34, 0.28), rgba(11, 14, 20, 0.38)) !important;
            border: 1px solid rgba(255, 255, 255, 0.10) !important;
            border-radius: 20px;
            backdrop-filter: blur(24px) saturate(160%);
            -webkit-backdrop-filter: blur(24px) saturate(160%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.08),
                inset 0 0 0 1px rgba(255, 255, 255, 0.02),
                0 24px 60px rgba(0, 0, 0, 0.45);
        }
        .gs-dashboard__surface {
            padding: 30px;
        }
        /* Surfaces nested inside a glass surface drop their own backdrop
           so we don\'t double-blur (which makes panels look muddy).
           .gs-mship-card is always nested inside .gs-dashboard__surface on
           this page, so it gets the same treatment - a single glass layer
           instead of two stacked ones darkening/muddying each other. */
        body.wp-admin.index-php .gs-dashboard__surface .gs-admin-card,
        body.wp-admin.index-php .gs-dashboard__surface .gs-hosting__card,
        body.wp-admin.index-php .gs-dashboard__surface .gs-mship-card,
        body.wp-admin.index-php .gs-mship-card .gs-hosting__card,
        body.wp-admin.index-php .gs-mship-card .gs-card {
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            background: rgba(255, 255, 255, 0.03) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04) !important;
        }
        /* Hosting nav rail glass */
        body.wp-admin.index-php .gs-hosting__nav.is-active {
            background: linear-gradient(180deg, rgba(78, 170, 255, 0.18), rgba(78, 170, 255, 0.08)) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        /* Subtle tab strip glass */
        body.wp-admin.index-php .gs-mship-tabs {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
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
        
        /* Form Settings Grid */
        .gs-settings-form-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 20px;
            margin-bottom: 24px;
            align-items: start;
        }
        .gs-settings-form-row label {
            font-size: 0.95rem;
            color: var(--gs-muted);
            font-weight: 500;
            padding-top: 8px; /* Align with input better */
        }
        .gs-settings-input-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .gs-settings-input-group input[type="text"] {
            width: 100%;
            max-width: 500px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.2);
            color: #fff;
            font-size: 1rem;
        }
        .gs-settings-input-group input[type="text"]:focus {
            border-color: var(--gs-magenta);
            outline: none;
            box-shadow: 0 0 0 1px var(--gs-magenta);
        }
        .gs-settings-help-text {
            font-size: 0.85rem;
            color: var(--gs-muted);
            margin: 0;
            max-width: 500px;
        }
        
        /* App Icon Uploader UI */
        .gs-app-icon-preview {
            width: 320px;
            height: 100px;
            background: linear-gradient(135deg, #a7c0d8, #d4f8fb);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 12px;
            overflow: hidden;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.1);
        }
        .gs-app-icon-browser-chrome {
            position: absolute;
            background: rgba(240, 240, 240, 0.9);
            bottom: 10px;
            right: 0;
            width: 75%;
            height: 40px;
            border-radius: 8px 0 0 8px;
            display: flex;
            align-items: center;
            padding: 0 10px;
            box-shadow: -2px 2px 10px rgba(0,0,0,0.1);
        }
        .gs-app-icon-browser-dots {
            display: flex;
            gap: 4px;
            margin-right: 12px;
        }
        .gs-app-icon-browser-dots span {
            width: 8px;
            height: 8px;
            background: #999;
            border-radius: 50%;
        }
        .gs-app-icon-browser-tab {
            background: #fff;
            height: 28px;
            padding: 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            color: #333;
            font-weight: 500;
        }
        .gs-app-icon-real-preview {
            width: 60px;
            height: 60px;
            position: absolute;
            left: 20px;
            background: #fff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            padding: 4px;
        }
        .gs-app-icon-real-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }
        .gs-app-icon-real-preview .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            color: #ccc;
        }
        .gs-app-icon-tab-img {
            width: 16px;
            height: 16px;
            border-radius: 2px;
            object-fit: cover;
        }
        .gs-app-icon-actions {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 8px;
        }
        .gs-app-icon-remove {
            color: #d63638;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .gs-app-icon-remove:hover {
            color: #b32d2e;
            text-decoration: underline;
        }
    </style>';
}

// ─── Full-tools embed mode (?gdc_dash_embed=1 on index.php) ───────────────
// Activated when the front-end membership popup opens this page inside an
// iframe (vendor-app-manager's "Open Full Hosting Tools" button) so the
// customer sees only the dashboard content, not wp-admin chrome. Modeled on
// member-profile-pages.php's existing gdc_embed pattern for the same
// "wp-admin page inside our own iframe" need - separate query flag
// (gdc_dash_embed, not gdc_embed) since that one is scoped to a specific
// CPT/post_id and this one is scoped to index.php.

// Remove X-Frame-Options so the dashboard can load inside the iframe. Must
// run at priority 1, before send_frame_options_header fires at priority 10.
add_action('admin_init', 'gend_society_dashboard_allow_embed_framing', 1);
function gend_society_dashboard_allow_embed_framing()
{
    if (empty($_GET['gdc_dash_embed'])) {
        return;
    }
    remove_action('admin_init', 'send_frame_options_header');
    @header_remove('X-Frame-Options');
}

add_action('admin_head-index.php', 'gend_society_dashboard_embed_mode_css');
function gend_society_dashboard_embed_mode_css()
{
    if (empty($_GET['gdc_dash_embed'])) {
        return;
    }
    ?>
    <style id="gdc-dash-embed">
        #wpadminbar { display: none !important; height: 0 !important; overflow: hidden !important; }
        html.wp-toolbar { padding-top: 0 !important; margin-top: 0 !important; }
        body { margin-top: 0 !important; padding-top: 0 !important; }

        /* gend-society/assets/admin-script.js injects a custom 3D header
           (#main-3d-header) at the top of <body> on every wp-admin load -
           irrelevant inside this iframe, same as the editor embed in
           member-profile-pages.php. */
        #main-3d-header,
        .header-anchor-wrap { display: none !important; }
        :root, html, body { --gs-header-h: 0px !important; }
        #wpbody { padding-top: 0 !important; }

        #adminmenumain,
        #adminmenuback,
        #adminmenuwrap,
        #wpfooter { display: none !important; }
        #wpcontent, #wpbody-content { margin-left: 0 !important; }
    </style>
    <?php
}

/**
 * Get membership from WP Ultimo (Stand-in for account data)
 */
function gend_society_dashboard_get_membership()
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
function gend_society_dashboard_render_admin_users_panel()
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
function gend_society_render_custom_dashboard_screen()
{
    if (!current_user_can('read')) {
        return;
    }

    $can_manage_site = current_user_can('manage_options');
    $account_section = '';

    $membership = gend_society_dashboard_get_membership();

    // Single panel renderer used for every site type. Resolves the
    // membership payload from whichever data source is available:
    //   1. Local WP Ultimo $membership (networked subsite with an active
    //      membership) — built without a network roundtrip.
    //   2. Cached remote payload from gend.me's /install/{id}/membership
    //      (container / self-hosted / paired sites).
    //   3. Group-only, no membership — gend.me's OWN site: it's the hub,
    //      not a customer install, so it never has a membership of its
    //      own, but it is linked to its group (www-gend-me). Without this
    //      tier the Hosting / Feature Suite / Project Contracts panel
    //      never renders here and step 4 below shows the admin-users
    //      fallback instead.
    if (function_exists('gend_society_render_membership_panel')) {
        $payload = null;

        if ($membership && function_exists('gend_society_membership_payload_from_local')) {
            $payload = gend_society_membership_payload_from_local($membership);
        }
        // Tier 2 only replaces what we have if it actually carries a group — a membership found in
        // tier 1 (billing/plan/status) is worth more than an empty remote-cache miss.
        if ((!is_array($payload) || empty($payload['group']['id'])) && function_exists('gend_society_remote_membership_get_cached')) {
            $remote = gend_society_remote_membership_get_cached();
            if (is_array($remote) && (!is_array($payload) || !empty($remote['group']['id']))) {
                $payload = $remote;
            }
        }
        // Tier 3: the membership WP Ultimo resolves for the logged-in user (tiers 1/2) isn't
        // necessarily tied to THIS site — gend.me's own admin can have a membership from testing a
        // signup flow that has nothing to do with gend.me's own site↔group link, so its 'group' key
        // comes back empty even though the rest of the payload (billing/status/plan) is real and
        // worth keeping. Whenever a group is still missing at this point, patch this site's own
        // group in — replacing only that one key, not the whole payload — from the same local
        // gdc_bp_group_id lookup gend.me's own dashboard-overview and every other blog↔group link in
        // this codebase already uses. This is what actually makes gend.me's own dashboard (no
        // membership of its own) show Business Group / Project Contracts correctly.
        if ((!is_array($payload) || empty($payload['group']['id'])) && function_exists('gend_society_membership_payload_group_only')) {
            $group_only = gend_society_membership_payload_group_only();
            if (is_array($group_only) && !empty($group_only['group']['id'])) {
                if (is_array($payload)) {
                    $payload['group'] = $group_only['group'];
                } else {
                    $payload = $group_only;
                }
            }
        }
        if (is_array($payload)) {
            $account_section = gend_society_render_membership_panel($payload);
        }
    }

    // Last-ditch fallback when neither path produced a payload (dev
    // environments, broken pairing, brand-new install before first
    // OAuth login).
    if ($account_section === '' && $can_manage_site) {
        $account_section = gend_society_dashboard_render_admin_users_panel();
    } elseif ($account_section === '') {
        $account_section = '<div class="notice notice-warning"><p>No active membership found for this site.</p></div>';
    }

    echo '<div class="gs-dashboard-wrap">';

    // The unified panel includes Domain + Plan + Backups tabs
    // inline. The legacy App Management / Account Overview cards
    // are no longer rendered — the panel covers everything they did.
    if ($account_section !== '') {
        echo '<section class="gs-dashboard__surface">';
        echo $account_section; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin-built panel markup (inline <style>/<script>/forms), escaped at construction in gs_render_membership_panel() / gs_dashboard_render_admin_users_panel(); kses would strip it.
        echo '</section>';
    }

    // Notice for successful App Settings save (form posts to
    // admin-post.php which redirects back here with gs_settings_saved=true)
    if (isset($_GET['gs_settings_saved']) && $_GET['gs_settings_saved'] == 'true') {
        echo '<div style="background: rgba(0, 163, 42, 0.1); border: 1px solid #00a32a; color: #fff; padding: 12px 20px; border-radius: 8px; font-weight: 500; margin-top: 16px;">' . esc_html__('Settings saved successfully.', 'gend-society') . '</div>';
    }

    // App Settings, Feature Suite, and User Access now live inside the
    // membership card's tab strip (rendered via gs_render_membership_panel).
    // The standalone <section> blocks that used to sit below the card are
    // gone; only the dashboard wrap / header / membership panel remain here.

    echo '</div>';
}

/**
 * Renders the App Settings form (App Title, Tagline, App Icon, Site Logo,
 * Save button) plus the media-uploader/preview JS. Output is echoed.
 *
 * Used by the Settings tab in gs_render_membership_panel() — the form posts
 * to admin-post.php (gs_save_app_settings handler at the top of this file)
 * which redirects back to index.php?gs_settings_saved=true so the success
 * notice in gs_render_custom_dashboard_screen still surfaces.
 *
 * Gated on manage_options upstream; this function does NOT re-check the cap.
 */
function gend_society_render_app_settings_form()
{
    $current_title   = get_option('blogname');
    $current_tagline = get_option('blogdescription');
    $current_icon_id = get_option('site_icon');
    $current_logo_id = get_theme_mod('custom_logo');

    $icon_url = '';
    if ($current_icon_id) {
        $image_attributes = wp_get_attachment_image_src($current_icon_id, 'full');
        if ($image_attributes) {
            $icon_url = $image_attributes[0];
        }
    }

    $logo_url = '';
    if ($current_logo_id) {
        $logo_attributes = wp_get_attachment_image_src($current_logo_id, 'full');
        if ($logo_attributes) {
            $logo_url = $logo_attributes[0];
        }
    }

    echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="POST">';
    wp_nonce_field('gs_app_settings_action', 'gs_app_settings_nonce');
    echo '<input type="hidden" name="action" value="gend_society_save_app_settings">';

    // App Title
    echo '<div class="gs-settings-form-row">';
    echo '<label for="gs_app_title">' . esc_html__('App Title', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';
    echo '<input type="text" id="gs_app_title" name="gs_app_title" value="' . esc_attr($current_title) . '">';
    echo '</div>';
    echo '</div>';

    // Tagline
    echo '<div class="gs-settings-form-row">';
    echo '<label for="gs_app_tagline">' . esc_html__('Tagline', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';
    echo '<input type="text" id="gs_app_tagline" name="gs_app_tagline" value="' . esc_attr($current_tagline) . '">';
    echo '<p class="gs-settings-help-text">In a few words, explain what this app is about. Example: "Just another GEND.ME Sites app."</p>';
    echo '</div>';
    echo '</div>';

    // App Icon
    echo '<div class="gs-settings-form-row">';
    echo '<label>' . esc_html__('App Icon', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';

    echo '<div class="gs-app-icon-preview">';
    echo '<div class="gs-app-icon-real-preview" id="gs-app-icon-real-preview-div">';
    if ($icon_url) {
        echo '<img src="' . esc_url($icon_url) . '" alt="App Icon" id="gs-app-icon-img">';
    } else {
        echo '<span class="dashicons dashicons-admin-site" style="display:block;" id="gs-app-icon-dashicon"></span>';
        echo '<img src="" alt="App Icon" id="gs-app-icon-img" style="display:none;">';
    }
    echo '</div>';
    echo '<div class="gs-app-icon-browser-chrome">';
    echo '<div class="gs-app-icon-browser-dots"><span></span><span></span><span></span></div>';
    echo '<div class="gs-app-icon-browser-tab">';
    if ($icon_url) {
        echo '<img src="' . esc_url($icon_url) . '" class="gs-app-icon-tab-img" id="gs-app-icon-tab-img">';
    } else {
        echo '<span class="dashicons dashicons-admin-site" style="font-size:16px;width:16px;height:16px;color:#ccc;display:block;" id="gs-app-icon-tab-dashicon"></span>';
        echo '<img src="" class="gs-app-icon-tab-img" id="gs-app-icon-tab-img" style="display:none;">';
    }
    echo '<span id="gs-app-icon-tab-title">' . esc_html($current_title ? $current_title : 'Site Title') . '</span>';
    echo '<span style="color: #999; margin-left:8px; font-size:10px;">×</span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="gs-app-icon-actions">';
    echo '<button type="button" class="gs-btn gs-btn-secondary" id="gs-app-icon-upload-btn">' . esc_html__('Change App Icon', 'gend-society') . '</button>';
    echo '<a href="#" class="gs-app-icon-remove" id="gs-app-icon-remove-btn" ' . ($icon_url ? '' : 'style="display:none;"') . '>' . esc_html__('Remove App Icon', 'gend-society') . '</a>';
    echo '</div>';

    echo '<input type="hidden" id="gs_app_icon_id" name="gs_app_icon" value="' . esc_attr($current_icon_id) . '">';
    echo '<p class="gs-settings-help-text">The App Icon is what you see in browser tabs, bookmark bars, and within the Gend.me mobile apps. It should be square and at least 512 by 512 pixels.</p>';

    echo '</div>'; // End input group
    echo '</div>'; // End form row

    // Site Logo
    echo '<div class="gs-settings-form-row">';
    echo '<label>' . esc_html__('Site Logo', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';

    echo '<div class="gs-app-icon-preview" style="height: 120px; background: rgba(0,0,0,0.1); border: 1px dashed rgba(255,255,255,0.1);">';
    echo '<div id="gs-site-logo-preview-div" style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; padding: 10px;">';
    if ($logo_url) {
        echo '<img src="' . esc_url($logo_url) . '" alt="Site Logo" id="gs-site-logo-img" style="max-width: 100%; max-height: 100%; object-fit: contain;">';
    } else {
        echo '<span class="dashicons dashicons-format-image" style="font-size: 48px; width: 48px; height: 48px; color: rgba(255,255,255,0.2);" id="gs-site-logo-dashicon"></span>';
        echo '<img src="" alt="Site Logo" id="gs-site-logo-img" style="display:none; max-width: 100%; max-height: 100%; object-fit: contain;">';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="gs-app-icon-actions">';
    echo '<button type="button" class="gs-btn gs-btn-secondary" id="gs-site-logo-upload-btn">' . esc_html__('Change Site Logo', 'gend-society') . '</button>';
    echo '<a href="#" class="gs-app-icon-remove" id="gs-site-logo-remove-btn" ' . ($logo_url ? '' : 'style="display:none;"') . '>' . esc_html__('Remove Site Logo', 'gend-society') . '</a>';
    echo '</div>';

    echo '<input type="hidden" id="gs_site_logo_id" name="gs_site_logo" value="' . esc_attr($current_logo_id) . '">';
    echo '<p class="gs-settings-help-text">The Site Logo is typically displayed in the header of your website. Most themes work best with a landscape or square logo.</p>';

    echo '</div>'; // End input group
    echo '</div>'; // End form row

    echo '<div style="margin-top: 30px;">';
    echo '<button type="submit" class="gs-btn">' . esc_html__('Save Settings', 'gend-society') . '</button>';
    echo '</div>';

    echo '</form>';

    // Media upload JS
    ?>
    <script>
    jQuery(document).ready(function($){
        var mediaUploader;
        $('#gs-app-icon-upload-btn').click(function(e) {
            e.preventDefault();
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
            mediaUploader = wp.media.frames.file_frame = wp.media({
                title: 'Choose App Icon',
                button: { text: 'Select Icon' },
                multiple: false
            });
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#gs_app_icon_id').val(attachment.id);
                var imgUrl = attachment.sizes && attachment.sizes.full ? attachment.sizes.full.url : attachment.url;
                $('#gs-app-icon-img').attr('src', imgUrl).show();
                $('#gs-app-icon-dashicon').hide();
                $('#gs-app-icon-tab-img').attr('src', imgUrl).show();
                $('#gs-app-icon-tab-dashicon').hide();
                $('#gs-app-icon-remove-btn').show();
            });
            mediaUploader.open();
        });

        var logoUploader;
        $('#gs-site-logo-upload-btn').click(function(e) {
            e.preventDefault();
            if (logoUploader) {
                logoUploader.open();
                return;
            }
            logoUploader = wp.media.frames.file_frame = wp.media({
                title: 'Choose Site Logo',
                button: { text: 'Select Logo' },
                multiple: false
            });
            logoUploader.on('select', function() {
                var attachment = logoUploader.state().get('selection').first().toJSON();
                $('#gs_site_logo_id').val(attachment.id);
                var imgUrl = attachment.sizes && attachment.sizes.full ? attachment.sizes.full.url : attachment.url;
                $('#gs-site-logo-img').attr('src', imgUrl).show();
                $('#gs-site-logo-dashicon').hide();
                $('#gs-site-logo-remove-btn').show();
            });
            logoUploader.open();
        });

        $('#gs-app-icon-remove-btn').click(function(e){
            e.preventDefault();
            $('#gs_app_icon_id').val('');
            $('#gs-app-icon-img').attr('src', '').hide();
            $('#gs-app-icon-dashicon').show();
            $('#gs-app-icon-tab-img').attr('src', '').hide();
            $('#gs-app-icon-tab-dashicon').show();
            $(this).hide();
        });

        $('#gs-site-logo-remove-btn').click(function(e){
            e.preventDefault();
            $('#gs_site_logo_id').val('');
            $('#gs-site-logo-img').attr('src', '').hide();
            $('#gs-site-logo-dashicon').show();
            $(this).hide();
        });

        // Live update tab title
        $('#gs_app_title').on('input', function() {
            var val = $(this).val();
            $('#gs-app-icon-tab-title').text(val ? val : 'Site Title');
        });
    });
    </script>
    <?php
}

/**
 * Renders the Permalink Settings form (Common Settings radios, Custom
 * Structure field + tag buttons, Category/Tag base, Save button) — ported
 * from wp-admin/options-permalink.php so it can live at the bottom of the
 * Settings tab instead of its own standalone screen. The form posts to
 * admin-post.php (gs_save_permalink_settings handler above), which mirrors
 * that core screen's own sanitization and redirects back to index.php the
 * same way gs_render_app_settings_form()'s form does.
 *
 * The radio/tag-button interactivity (#permalink_structure, #custom_selection,
 * .available-structure-tags button) is handled by wp-admin/js/common.js,
 * which every wp-admin screen already loads — no extra JS needed here as
 * long as the field IDs/classes below match what that script selects.
 *
 * Gated on manage_options upstream; this function does NOT re-check the cap.
 */
function gend_society_render_permalink_settings_form()
{
    $permalink_structure = get_option('permalink_structure');
    $category_base       = get_option('category_base');
    $tag_base             = get_option('tag_base');

    $blog_prefix = '';
    if (
        is_multisite() && !is_subdomain_install() && is_main_site()
        && str_starts_with((string) $permalink_structure, '/blog/')
    ) {
        $blog_prefix = '/blog';
    }
    $index_php_prefix = got_url_rewrite() ? '' : '/index.php';
    $url_base = home_url($blog_prefix . $index_php_prefix);

    $default_structures = array(
        array('id' => 'plain', 'label' => __('Plain', 'gend-society'), 'value' => '', 'example' => home_url('/?p=123')),
        array('id' => 'day-name', 'label' => __('Day and name', 'gend-society'), 'value' => $index_php_prefix . '/%year%/%monthnum%/%day%/%postname%/', 'example' => $url_base . '/' . gmdate('Y/m/d') . '/sample-post/'),
        array('id' => 'month-name', 'label' => __('Month and name', 'gend-society'), 'value' => $index_php_prefix . '/%year%/%monthnum%/%postname%/', 'example' => $url_base . '/' . gmdate('Y/m') . '/sample-post/'),
        array('id' => 'numeric', 'label' => __('Numeric', 'gend-society'), 'value' => $index_php_prefix . '/archives/%post_id%', 'example' => $url_base . '/archives/123'),
        array('id' => 'post-name', 'label' => __('Post name', 'gend-society'), 'value' => $index_php_prefix . '/%postname%/', 'example' => $url_base . '/sample-post/'),
    );
    $default_structure_values = wp_list_pluck($default_structures, 'value');

    $available_tags = array(
        /* translators: %s: Permalink structure tag. */
        'year'     => __('%s (The year of the post, four digits, for example 2004.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'monthnum' => __('%s (Month of the year, for example 05.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'day'      => __('%s (Day of the month, for example 28.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'hour'     => __('%s (Hour of the day, for example 15.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'minute'   => __('%s (Minute of the hour, for example 43.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'second'   => __('%s (Second of the minute, for example 33.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'post_id'  => __('%s (The unique ID of the post, for example 423.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'postname' => __('%s (The sanitized post title (slug).)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'category' => __('%s (Category slug. Nested sub-categories appear as nested directories in the URL.)', 'gend-society'),
        /* translators: %s: Permalink structure tag. */
        'author'   => __('%s (A sanitized version of the author name.)', 'gend-society'),
    );
    $available_tags = apply_filters('available_permalink_structure_tags', $available_tags);
    /* translators: %s: Permalink structure tag. */
    $tag_added         = __('%s added to permalink structure', 'gend-society');
    /* translators: %s: Permalink structure tag. */
    $tag_removed       = __('%s removed from permalink structure', 'gend-society');
    /* translators: %s: Permalink structure tag. */
    $tag_already_used  = __('%s (already used in permalink structure)', 'gend-society');

    echo '<h2 style="margin:0 0 8px 0;color:#fff;font-size:1.1rem;">' . esc_html__('Permalinks', 'gend-society') . '</h2>';
    echo '<p style="color:var(--gs-muted);margin:0 0 24px 0;">' . esc_html__('Choose the URL structure used for posts, pages, and archives on this site.', 'gend-society') . '</p>';

    echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="POST">';
    wp_nonce_field('gs_permalink_settings_action', 'gs_permalink_settings_nonce');
    echo '<input type="hidden" name="action" value="gend_society_save_permalink_settings">';

    echo '<table class="form-table permalink-structure" role="presentation" style="width:100%;border-collapse:collapse;">';
    echo '<tbody><tr><td style="padding:0;border:0;">';
    echo '<fieldset class="structure-selection">';

    foreach ($default_structures as $input) {
        echo '<div class="row" style="margin-bottom:14px;">';
        echo '<label for="permalink-input-' . esc_attr($input['id']) . '" style="display:flex;align-items:flex-start;gap:10px;color:#e2e8f0;cursor:pointer;">';
        echo '<input id="permalink-input-' . esc_attr($input['id']) . '" name="selection" type="radio" value="' . esc_attr($input['value']) . '" style="margin-top:4px;" ' . checked($input['value'], $permalink_structure, false) . '>';
        echo '<span><strong style="color:#fff;">' . esc_html($input['label']) . '</strong><br><code style="color:#94a3b8;">' . esc_html($input['example']) . '</code></span>';
        echo '</label>';
        echo '</div>';
    }

    echo '<div class="row" style="margin-bottom:0;">';
    echo '<label for="custom_selection" style="display:flex;align-items:flex-start;gap:10px;color:#e2e8f0;cursor:pointer;">';
    echo '<input id="custom_selection" name="selection" type="radio" value="custom" style="margin-top:4px;" ' . checked(!in_array($permalink_structure, $default_structure_values, true), true, false) . '>';
    echo '<span style="flex:1;min-width:0;">';
    echo '<strong style="color:#fff;">' . esc_html__('Custom Structure', 'gend-society') . '</strong>';
    echo '<p style="margin:8px 0 0;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">';
    echo '<code id="permalink-custom" style="color:#94a3b8;">' . esc_html($url_base) . '</code>';
    echo '<input name="permalink_structure" id="permalink_structure" type="text" value="' . esc_attr($permalink_structure) . '" aria-describedby="permalink-custom" class="regular-text code" style="background:rgba(0,0,0,0.2);color:#fff;border:1px solid rgba(255,255,255,0.1);border-radius:6px;padding:6px 10px;">';
    echo '</p>';

    echo '<div class="available-structure-tags hide-if-no-js" style="margin-top:10px;">';
    echo '<div id="custom_selection_updated" aria-live="assertive" class="screen-reader-text"></div>';
    if (!empty($available_tags)) {
        echo '<fieldset><legend style="color:#94a3b8;font-size:0.8rem;margin-bottom:6px;">' . esc_html__('Available tags:', 'gend-society') . '</legend>';
        echo '<ul role="list" style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;">';
        foreach ($available_tags as $tag => $explanation) {
            echo '<li><button type="button" class="button button-secondary"'
                . ' aria-label="' . esc_attr(sprintf($explanation, $tag)) . '"'
                . ' data-added="' . esc_attr(sprintf($tag_added, $tag)) . '"'
                . ' data-removed="' . esc_attr(sprintf($tag_removed, $tag)) . '"'
                . ' data-used="' . esc_attr(sprintf($tag_already_used, $tag)) . '">'
                . '%' . esc_html($tag) . '%</button></li>';
        }
        echo '</ul></fieldset>';
    }
    echo '</div>'; // .available-structure-tags
    echo '</span>';
    echo '</label>';
    echo '</div>'; // custom row

    echo '</fieldset>';
    echo '</td></tr></tbody></table>';

    // Category / Tag base — same field layout as the App Settings form above.
    echo '<div class="gs-settings-form-row" style="margin-top:24px;">';
    echo '<label for="category_base">' . esc_html__('Category base', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';
    echo '<input type="text" id="category_base" name="category_base" value="' . esc_attr($category_base) . '">';
    echo '<p class="gs-settings-help-text">' . esc_html__('Leave blank to use the default "category" base.', 'gend-society') . '</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="gs-settings-form-row">';
    echo '<label for="tag_base">' . esc_html__('Tag base', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';
    echo '<input type="text" id="tag_base" name="tag_base" value="' . esc_attr($tag_base) . '">';
    echo '<p class="gs-settings-help-text">' . esc_html__('Leave blank to use the default "tag" base.', 'gend-society') . '</p>';
    echo '</div>';
    echo '</div>';

    echo '<div style="margin-top:30px;">';
    echo '<button type="submit" class="gs-btn">' . esc_html__('Save Permalinks', 'gend-society') . '</button>';
    echo '</div>';

    echo '</form>';
}

/**
 * Renders the Application Passwords manager — ported from the stock
 * Users → Profile → Application Passwords section, which is unreachable
 * in the rebuilt admin. Lives at the bottom of the Settings tab in
 * gs_render_membership_panel(), below the Permalinks form.
 *
 * App passwords are the sign-in credential for GenD Mobile and any other
 * REST/API client: create one per device, revoke it independently, and the
 * real account password never leaves the browser. The generated password is
 * only ever displayed ONCE — the create handler stashes the plaintext in a
 * 5-minute transient and this renderer consumes (deletes) it on display;
 * WordPress core itself stores only the hash.
 *
 * Forms post to admin-post.php (gs_app_password_create/_revoke handlers at
 * the top of this file) and redirect back to index.php?gs_app_pw=… the same
 * way the App Settings and Permalinks forms do.
 *
 * Gated on manage_options upstream; this function does NOT re-check the cap.
 */
function gend_society_render_application_passwords_form()
{
    $user_id = get_current_user_id();

    echo '<h3 style="margin: 0 0 8px 0; color: #fff; font-size: 1.1rem;">' . esc_html__('Application Passwords', 'gend-society') . '</h3>';
    echo '<p class="gs-settings-help-text" style="margin-bottom: 20px;">'
        . esc_html__('Application passwords let apps like GenD Mobile sign in to this account over the API without your real password. Create one per device and revoke it any time — revoking never affects your normal login.', 'gend-society')
        . '</p>';

    if (!class_exists('WP_Application_Passwords') || !function_exists('wp_is_application_passwords_available')) {
        echo '<p class="gs-settings-help-text">' . esc_html__('Application passwords are not supported on this WordPress version.', 'gend-society') . '</p>';
        return;
    }
    if (!wp_is_application_passwords_available() || !wp_is_application_passwords_available_for_user(wp_get_current_user())) {
        echo '<p class="gs-settings-help-text">' . esc_html__('Application passwords are unavailable on this site (they require HTTPS or are disabled by a filter).', 'gend-society') . '</p>';
        return;
    }

    // ── Status notices from the create/revoke redirects ──
    if (isset($_GET['gs_app_pw'])) {
        if ($_GET['gs_app_pw'] === 'revoked') {
            echo '<div style="background: rgba(0, 163, 42, 0.1); border: 1px solid #00a32a; color: #fff; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px;">' . esc_html__('Application password revoked.', 'gend-society') . '</div>';
        } elseif ($_GET['gs_app_pw'] === 'error') {
            echo '<div style="background: rgba(214, 54, 56, 0.1); border: 1px solid #d63638; color: #fff; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px;">' . esc_html__('Something went wrong — the application password was not changed.', 'gend-society') . '</div>';
        }
    }

    // ── One-time reveal of a freshly created password ──
    $fresh = get_transient('gend_society_app_pw_new_' . $user_id);
    if (is_array($fresh) && !empty($fresh['password'])) {
        delete_transient('gend_society_app_pw_new_' . $user_id);
        echo '<div style="background: rgba(78,170,255,0.10); border: 1px solid rgba(78,170,255,0.45); border-radius: 12px; padding: 18px 20px; margin-bottom: 20px;">';
        echo '<div style="color: #fff; font-weight: 600; margin-bottom: 6px;">'
            . sprintf(/* translators: %s: Application password name. */ esc_html__('New password for “%s” — copy it now, it will not be shown again:', 'gend-society'), esc_html($fresh['name']))
            . '</div>';
        echo '<code id="gs-app-pw-plain" style="display: inline-block; background: rgba(0,0,0,0.35); color: #4eaaff; font-size: 1.15rem; letter-spacing: 1px; padding: 10px 14px; border-radius: 8px; user-select: all;">' . esc_html($fresh['password']) . '</code>';
        echo ' <button type="button" class="gs-btn gs-btn-secondary" style="vertical-align: middle; margin-left: 8px;" onclick="navigator.clipboard.writeText(document.getElementById(\'gs-app-pw-plain\').textContent).then(function(){ var b = event.target; b.textContent = \'' . esc_js(__('Copied ✓', 'gend-society')) . '\'; });">' . esc_html__('Copy', 'gend-society') . '</button>';
        echo '<p class="gs-settings-help-text" style="margin-top: 10px;">' . esc_html__('Paste it into the app\'s "Application password" field (the spaces are optional). If you lose it, just revoke it and create a new one.', 'gend-society') . '</p>';
        echo '</div>';
    }

    // ── Existing passwords ──
    $items = WP_Application_Passwords::get_user_application_passwords($user_id);
    if (!empty($items)) {
        echo '<div style="border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; overflow: hidden; margin-bottom: 24px;">';
        foreach ($items as $item) {
            $created   = !empty($item['created']) ? date_i18n(get_option('date_format'), (int) $item['created']) : '—';
            $last_used = !empty($item['last_used']) ? date_i18n(get_option('date_format'), (int) $item['last_used']) : __('Never', 'gend-society');
            echo '<div style="display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06);">';
            echo '<span class="dashicons dashicons-smartphone" style="color: var(--gs-muted, #999);"></span>';
            echo '<div style="flex: 1; min-width: 0;">';
            echo '<div style="color: #fff; font-weight: 600;">' . esc_html($item['name']) . '</div>';
            echo '<div class="gs-settings-help-text" style="margin: 2px 0 0;">'
                . sprintf(/* translators: 1: Date created, 2: Date last used. */ esc_html__('Created %1$s · Last used %2$s', 'gend-society'), esc_html($created), esc_html($last_used))
                . (!empty($item['last_ip']) ? ' · ' . esc_html($item['last_ip']) : '')
                . '</div>';
            echo '</div>';
            echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="POST" onsubmit="return confirm(\'' . esc_js(__('Revoke this application password? Any device using it will be signed out.', 'gend-society')) . '\');" style="margin: 0;">';
            wp_nonce_field('gs_app_pw_action', 'gs_app_pw_nonce');
            echo '<input type="hidden" name="action" value="gend_society_app_password_revoke">';
            echo '<input type="hidden" name="gs_app_pw_uuid" value="' . esc_attr($item['uuid']) . '">';
            echo '<button type="submit" class="gs-btn gs-btn-secondary" style="background: rgba(214,54,56,0.12); color: #ff8085; border: 1px solid rgba(214,54,56,0.4);">' . esc_html__('Revoke', 'gend-society') . '</button>';
            echo '</form>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<p class="gs-settings-help-text" style="margin-bottom: 20px;">' . esc_html__('No application passwords yet.', 'gend-society') . '</p>';
    }

    // ── Create form ──
    echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="POST">';
    wp_nonce_field('gs_app_pw_action', 'gs_app_pw_nonce');
    echo '<input type="hidden" name="action" value="gend_society_app_password_create">';
    echo '<div class="gs-settings-form-row">';
    echo '<label for="gs_app_pw_name">' . esc_html__('New password name', 'gend-society') . '</label>';
    echo '<div class="gs-settings-input-group">';
    echo '<input type="text" id="gs_app_pw_name" name="gs_app_pw_name" value="" placeholder="' . esc_attr__('GenD Mobile', 'gend-society') . '">';
    echo '<p class="gs-settings-help-text">' . esc_html__('Name it after the device or app that will use it, e.g. "GenD Mobile" or "iPhone".', 'gend-society') . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div style="margin-top: 16px;">';
    echo '<button type="submit" class="gs-btn">' . esc_html__('Create Application Password', 'gend-society') . '</button>';
    echo '</div>';
    echo '</form>';
}