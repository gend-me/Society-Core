<?php
/**
 * Standalone Dashboard for GenD Society
 * Completely replaces the default WordPress dashboard (index.php)
 */

if (!defined('ABSPATH')) {
    exit;
}

// 0. Handle Form Submission for App Settings
add_action('admin_post_gs_save_app_settings', 'gs_dashboard_save_app_settings');
function gs_dashboard_save_app_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action.'));
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

    wp_safe_redirect(add_query_arg('gs_settings_saved', 'true', admin_url('index.php')));
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

    // 5. Enqueue Media Uploader for App Icon
    add_action('admin_enqueue_scripts', 'gs_dashboard_enqueue_media');
}

function gs_dashboard_enqueue_media() {
    wp_enqueue_media();
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

    // Notice for successful save
    if (isset($_GET['gs_settings_saved']) && $_GET['gs_settings_saved'] == 'true') {
        echo '<div style="background: rgba(0, 163, 42, 0.1); border: 1px solid #00a32a; color: #fff; padding: 12px 20px; border-radius: 8px; font-weight: 500;">' . esc_html__('Settings saved successfully.', 'gend-society') . '</div>';
    }

    // App Settings Section
    if (current_user_can('manage_options')) {
        echo '<section class="gs-dashboard__surface" style="margin-top: 32px;">';
        
        $current_title = get_option('blogname');
        $current_tagline = get_option('blogdescription');
        $current_icon_id = get_option('site_icon');
        
        $icon_url = '';
        if ($current_icon_id) {
            $image_attributes = wp_get_attachment_image_src($current_icon_id, 'full');
            if ($image_attributes) {
                $icon_url = $image_attributes[0];
            }
        }

        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="POST">';
        wp_nonce_field('gs_app_settings_action', 'gs_app_settings_nonce');
        echo '<input type="hidden" name="action" value="gs_save_app_settings">';
        
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
        
        // The Preview Box mimicking the screenshot
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
        echo '<button type="button" class="gs-btn gs-btn-secondary" id="gs-app-icon-upload-btn" style="background:#fff; color:#0073aa; border:none; border-radius:2px; font-weight:normal;">' . esc_html__('Change App Icon', 'gend-society') . '</button>';
        echo '<a href="#" class="gs-app-icon-remove" id="gs-app-icon-remove-btn" ' . ($icon_url ? '' : 'style="display:none;"') . '>' . esc_html__('Remove App Icon', 'gend-society') . '</a>';
        echo '</div>';
        
        echo '<input type="hidden" id="gs_app_icon_id" name="gs_app_icon" value="' . esc_attr($current_icon_id) . '">';
        echo '<p class="gs-settings-help-text">The App Icon is what you see in browser tabs, bookmark bars, and within the Gend.me mobile apps. It should be square and at least 512 by 512 pixels.</p>';
        
        echo '</div>'; // End input group
        echo '</div>'; // End form row
        
        echo '<div style="margin-top: 30px;">';
        echo '<button type="submit" class="gs-btn">' . esc_html__('Save Settings', 'gend-society') . '</button>';
        echo '</div>';
        
        echo '</form>';
        echo '</section>';
        
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
                    button: {
                        text: 'Select Icon'
                    },
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
            
            $('#gs-app-icon-remove-btn').click(function(e){
                e.preventDefault();
                $('#gs_app_icon_id').val('');
                
                $('#gs-app-icon-img').attr('src', '').hide();
                $('#gs-app-icon-dashicon').show();
                
                $('#gs-app-icon-tab-img').attr('src', '').hide();
                $('#gs-app-icon-tab-dashicon').show();
                
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