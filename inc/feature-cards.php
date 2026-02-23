<?php
/**
 * Standalone Feature Cards Widget for GenD Society
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns hardcoded feature definitions for the GenD Society dashboard.
 */
function gs_get_feature_definitions()
{
    return array(
        'wireframe' => array(
            'name' => __('Wireframe Generator (Leo)', 'gend-society'),
            'description' => __('Generate high-fidelity UI maps and backend login flows instantly using LEO’s generation engine.', 'gend-society'),
            'plugin' => 'leo/leo.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Wireframe-Generation.png',
            'link' => 'https://gend.me/wireframe-generation/'
        ),
        'blog' => array(
            'name' => __('Social Blogs', 'gend-society'),
            'description' => __('Turn your content into a community hub with integrated social sharing and engagement tools.', 'gend-society'),
            'plugin' => 'blog-manager/blog-manager.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Social-Blogs.png',
            'link' => 'https://gend.me/social-blogs/'
        ),
        'email' => array(
            'name' => __('Community Emails', 'gend-society'),
            'description' => __('Automate high-touch communication and keep your users engaged with targeted community updates.', 'gend-society'),
            'plugin' => 'email-manager/email-manager.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Email-Nurturing.png',
            'link' => 'https://gend.me/community-emails/'
        ),
        'store' => array(
            'name' => __('Store Management', 'gend-society'),
            'description' => __('Centralize your inventory, orders, and fulfillment in one intuitive dashboard.', 'gend-society'),
            'plugin' => 'online-store/online-store.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Store-Management.png',
            'link' => 'https://gend.me/store-management/'
        ),
        'sales' => array(
            'name' => __('Sales Management', 'gend-society'),
            'description' => __('Empower your sales team with real-time tracking, lead management, and performance analytics.', 'gend-society'),
            'plugin' => 'sales-team/advanced-affiliate-system.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Sales-Team.png',
            'link' => 'https://gend.me/sales-team/'
        ),
        'projects' => array(
            'name' => __('Remote Projects', 'gend-society'),
            'description' => __('Coordinate global teams and track deliverables with integrated project management for store owners.', 'gend-society'),
            'plugin' => 'projects/project-service-orders.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Remote-Projects.png',
            'link' => 'https://gend.me/remote-projects/'
        ),
        'social' => array(
            'name' => __('Social Profiles', 'gend-society'),
            'description' => __('Allow users to create rich, customizable profiles that drive identity and connection.', 'gend-society'),
            'plugin' => 'social-network/social-network.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Social-Profiles.png',
            'link' => 'https://gend.me/social-profiles/'
        ),
        'membership' => array(
            'name' => __('Membership Management', 'gend-society'),
            'description' => __('Total control over tiers, permissions, and access for your exclusive community.', 'gend-society'),
            'plugin' => 'Member Management/member-management.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Membership-Management.png',
            'link' => 'https://gend.me/membership-management/'
        ),
        'rewards' => array(
            'name' => __('Member Rewards', 'gend-society'),
            'description' => __('Incentivize loyalty and engagement with automated points, badges, and perks.', 'gend-society'),
            'plugin' => 'reward-programs/reward-programs.php',
            'image' => 'https://gend.me/wp-content/uploads/2026/02/Member-Rewards.png',
            'link' => 'https://gend.me/member-rewards/'
        )
    );
}

/**
 * Check if a specific plugin has an update available
 */
function gs_has_plugin_update($plugin_file)
{
    $current = get_site_transient('update_plugins');
    if (isset($current->response[$plugin_file])) {
        return true;
    }
    return false;
}

/**
 * Render the feature cards widget
 */
function gs_render_feature_cards_widget()
{
    $features = gs_get_feature_definitions();

    echo '<style>
        .gs-feature-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
        .gs-fc { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; transition: all 0.3s ease; position: relative; }
        .gs-fc:hover { border-color: rgba(255,255,255,0.15); transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .gs-fc-img { width: 100%; height: 160px; object-fit: cover; object-position: top center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .gs-fc-body { padding: 24px; flex: 1; display: flex; flex-direction: column; }
        .gs-fc-title { margin: 0 0 10px 0; color: #fff; font-size: 1.25rem; font-weight: 700; }
        .gs-fc-desc { margin: 0 0 24px 0; color: var(--gs-muted); font-size: 0.9rem; line-height: 1.5; flex: 1; }
        .gs-fc-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; }
        .gs-fc-btn { flex: 1; text-align: center; justify-content: center; }
        .gs-fc-btn-icon { width: 36px; height: 36px; padding: 0; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .gs-fc-update { position: relative; }
        .gs-fc-badge { position: absolute; top: -5px; right: -5px; background: var(--gs-red); color: #fff; font-size: 10px; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .gs-fc-status { position: absolute; top: 16px; right: 16px; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 10; backdrop-filter: blur(10px); }
        .gs-fc-status.active { background: rgba(16, 185, 129, 0.8); color: #fff; box-shadow: 0 2px 10px rgba(16, 185, 129, 0.4); }
        .gs-fc-status.inactive { background: rgba(20, 24, 34, 0.8); color: var(--gs-muted); border: 1px solid rgba(255,255,255,0.1); }
    </style>';

    echo '<div class="gs-feature-grid">';

    foreach ($features as $slug => $feature) {

        // WP Ultimo has a special check since it is a mu-plugin or network managed usually
        if (isset($feature['is_ultimo']) && $feature['is_ultimo']) {
            $is_active = function_exists('WP_Ultimo');
        } else {
            $is_active = gs_plugin_active($feature['plugin']);
        }

        $has_update = gs_has_plugin_update($feature['plugin']);

        echo '<div class="gs-fc">';

        // Status Badge overlay on image
        if ($is_active) {
            echo '<div class="gs-fc-status active">' . esc_html__('Active', 'gend-society') . '</div>';
        } else {
            echo '<div class="gs-fc-status inactive">' . esc_html__('Inactive', 'gend-society') . '</div>';
        }

        // Image Header
        echo '<img src="' . esc_url($feature['image']) . '" alt="' . esc_attr($feature['name']) . '" class="gs-fc-img" loading="lazy" />';

        // Body
        echo '<div class="gs-fc-body">';
        echo '<h3 class="gs-fc-title">' . esc_html($feature['name']) . '</h3>';
        echo '<p class="gs-fc-desc">' . esc_html($feature['description']) . '</p>';

        // Actions
        echo '<div class="gs-fc-actions">';

        // Main Action Button (Manage or Activate)
        if ($is_active) {
            echo '<a href="' . admin_url('plugins.php') . '" class="gs-btn gs-btn-secondary gs-fc-btn">' . esc_html__('Manage Plugin', 'gend-society') . '</a>';
        } else {
            echo '<a href="' . admin_url('plugins.php') . '" class="gs-btn gs-fc-btn">' . esc_html__('Activate', 'gend-society') . '</a>';
        }

        // Info Button
        echo '<a href="' . esc_url($feature['link']) . '" target="_blank" class="gs-btn gs-btn-secondary gs-fc-btn-icon" title="More Info">';
        echo '<span class="dashicons dashicons-info"></span>';
        echo '</a>';

        // Update Button
        echo '<a href="' . admin_url('update-core.php') . '" class="gs-btn gs-btn-secondary gs-fc-btn-icon gs-fc-update" title="Check Updates">';
        echo '<span class="dashicons dashicons-update"></span>';
        if ($has_update) {
            echo '<span class="gs-fc-badge">!</span>';
        }
        echo '</a>';

        echo '</div>'; // End Actions
        echo '</div>'; // End Body
        echo '</div>'; // End Card
    }

    echo '</div>'; // End Grid
}
