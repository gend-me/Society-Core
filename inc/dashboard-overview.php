<?php
/**
 * Standalone Dashboard Overview for GenD Society
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('gs_get_account_overview_html')) {
    function gs_get_account_overview_html($membership)
    {
        if (!$membership) {
            return '';
        }

        ob_start();
        // Determine Dashboard/Hosting plans by scanning all products
        $dash_plan = null;
        $host_plan = null;
        $all = $membership->get_all_products(); // array of [quantity, product]
        foreach ((array) $all as $row) {
            $prod = is_array($row) && isset($row['product']) ? $row['product'] : null;
            if (!$prod || !is_object($prod) || !method_exists($prod, 'get_group')) {
                continue;
            }
            $g = (string) $prod->get_group();
            if ($g === 'dashboard' && !$dash_plan) {
                $dash_plan = $prod;
            }
            if ($g === 'hosting' && !$host_plan) {
                $host_plan = $prod;
            }
        }
        // Fallback: if no dashboard plan detected, show primary plan as Dashboard
        if (!$dash_plan) {
            $primary = $membership->get_plan();
            if ($primary) {
                $dash_plan = $primary;
            }
        }

        // Two-column header
        echo '<div class="gs-admin-grid">';

        // Plan Column
        echo '<div class="gs-admin-card" style="padding: 24px;">';
        echo '<h2 style="margin-top:0; font-size: 1.25rem;">' . esc_html__('App Builder Membership', 'gend-society') . '</h2>';

        // Dashboard Plan block
        echo '<h3 style="margin-top:20px; font-size: 0.95rem; color: var(--gs-muted);">' . esc_html__('Dashboard Plan', 'gend-society') . '</h3>';
        echo '<div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">';
        $d_img = ($dash_plan && method_exists($dash_plan, 'get_featured_image')) ? $dash_plan->get_featured_image('thumbnail') : '';
        echo '<div style="width: 48px; height: 48px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2);">' . ($d_img ? '<img src="' . esc_url($d_img) . '" style="width:100%; height:100%; object-fit: cover;" alt="" />' : '<span class="dashicons dashicons-admin-site" style="color: var(--gs-muted);"></span>') . '</div>';
        echo '<div>';
        echo '<strong style="display: block; font-size: 1.1rem; color: #fff;">' . esc_html($dash_plan ? $dash_plan->get_name() : __('None', 'gend-society')) . '</strong>';
        $d_desc = ($dash_plan && method_exists($dash_plan, 'get_description')) ? wp_strip_all_tags($dash_plan->get_description()) : '';
        if ($d_desc)
            echo '<span style="font-size: 0.85rem; color: var(--gs-muted);">' . esc_html(wp_trim_words($d_desc, 10, '...')) . '</span>';
        echo '</div>';
        echo '</div>';

        // Hosting Plan block
        echo '<h3 style="margin-top:0px; font-size: 0.95rem; color: var(--gs-muted);">' . esc_html__('Hosting Plan', 'gend-society') . '</h3>';
        echo '<div style="display: flex; align-items: center; gap: 16px; margin-bottom: 24px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">';
        $h_img = ($host_plan && method_exists($host_plan, 'get_featured_image')) ? $host_plan->get_featured_image('thumbnail') : '';
        echo '<div style="width: 48px; height: 48px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2);">' . ($h_img ? '<img src="' . esc_url($h_img) . '" style="width:100%; height:100%; object-fit: cover;" alt="" />' : '<span class="dashicons dashicons-cloud" style="color: var(--gs-muted);"></span>') . '</div>';
        echo '<div>';
        echo '<strong style="display: block; font-size: 1.1rem; color: #fff;">' . esc_html($host_plan ? $host_plan->get_name() : __('None', 'gend-society')) . '</strong>';
        $h_desc = ($host_plan && method_exists($host_plan, 'get_description')) ? wp_strip_all_tags($host_plan->get_description()) : '';
        if ($h_desc)
            echo '<span style="font-size: 0.85rem; color: var(--gs-muted);">' . esc_html(wp_trim_words($h_desc, 10, '...')) . '</span>';
        echo '</div>';
        echo '</div>';

        // Change Membership button
        $main_id = function_exists('wu_get_main_site_id') ? (int) wu_get_main_site_id() : (function_exists('get_main_site_id') ? (int) get_main_site_id() : 1);
        $main_home = function_exists('get_blog_option') ? (string) get_blog_option($main_id, 'home') : (string) get_option('home');
        if (empty($main_home)) {
            $main_home = (string) network_home_url('/');
        }
        $myacc = trailingslashit(rtrim($main_home, '/') . '/my-account');
        $mid = ($membership && method_exists($membership, 'get_id')) ? (int) $membership->get_id() : 0;

        if ($mid) {
            $membership_url = $myacc . 'membership/' . $mid . '/';
        } else {
            $membership_url = $myacc . 'memberships/';
        }

        echo '<div style="text-align: right;">';
        echo '<a class="gs-btn" href="' . esc_url($membership_url) . '" target="_blank" rel="noopener">' . esc_html__('Change Membership', 'gend-society') . '</a>';
        echo '</div>';
        echo '</div>'; // End Plan Column


        // Owner Column
        echo '<div class="gs-admin-card" style="padding: 24px;">';
        echo '<h2 style="margin-top:0; font-size: 1.25rem;">' . esc_html__('Account Owner', 'gend-society') . '</h2>';
        $customer = $membership->get_customer();

        if ($customer) {
            $username = method_exists($customer, 'get_username') ? $customer->get_username() : '';
            $name = method_exists($customer, 'get_display_name') ? $customer->get_display_name() : $username;
            $user_id = method_exists($customer, 'get_user_id') ? (int) $customer->get_user_id() : 0;

            $main_id = function_exists('wu_get_main_site_id') ? (int) wu_get_main_site_id() : (function_exists('get_main_site_id') ? (int) get_main_site_id() : 1);
            $main_home = function_exists('get_blog_option') ? (string) get_blog_option($main_id, 'home') : (string) get_option('home');
            if (empty($main_home)) {
                $main_home = (string) network_home_url('/');
            }
            $root = trailingslashit(rtrim($main_home, '/'));
            $profile = $root . 'members/' . rawurlencode($username) . '/';
            $messages_slug = function_exists('bp_get_messages_slug') ? bp_get_messages_slug() : 'messages';
            $msg = $profile . trailingslashit($messages_slug) . 'compose/?r=' . rawurlencode($username);
            $name = (function_exists('bp_core_get_user_displayname') && $user_id) ? bp_core_get_user_displayname($user_id) : $name;

            $avatar = '';
            if (function_exists('bp_core_fetch_avatar') && $user_id) {
                $bp_root_id = function_exists('bp_get_root_blog_id') ? (int) bp_get_root_blog_id() : $main_id;
                $sw = false;
                if (is_multisite() && get_current_blog_id() !== $bp_root_id) {
                    switch_to_blog($bp_root_id);
                    $sw = true;
                }
                $avatar_html = bp_core_fetch_avatar(array(
                    'item_id' => $user_id,
                    'object' => 'user',
                    'type' => 'thumb',
                    'width' => 64,
                    'height' => 64,
                    'html' => true,
                    'class' => 'avatar',
                    'force_default' => false,
                ));
                if ($sw) {
                    restore_current_blog();
                }
                if (!empty($avatar_html) && is_string($avatar_html)) {
                    $avatar = $avatar_html;
                }
            }
            if (empty($avatar)) {
                $avatar = get_avatar($customer->get_email_address(), 64, '', '', array('class' => 'avatar'));
            }

            echo '<div style="display: flex; align-items: center; gap: 16px; margin-top: 20px; padding-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.05);">';
            echo '<a href="' . esc_url($profile) . '" target="_blank" rel="noopener" style="border-radius: 50%; overflow: hidden; width: 64px; height: 64px; display: block;">' . $avatar . '</a>';
            echo '<div>';
            echo '<a href="' . esc_url($profile) . '" target="_blank" rel="noopener" style="display: block; font-size: 1.1rem; font-weight: 600; color: #fff; text-decoration: none;">' . esc_html($name) . '</a>';
            echo '<a class="gs-btn gs-btn-secondary" href="' . esc_url($msg) . '" target="_blank" rel="noopener" style="margin-top: 10px; padding: 4px 12px; font-size: 0.8rem;">' . esc_html__('Message', 'gend-society') . '</a>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<p style="color: var(--gs-muted); margin-top: 20px;">' . esc_html__('Customer information unavailable.', 'gend-society') . '</p>';
        }

        // Group Details
        echo '<h3 style="margin-top: 24px; font-size: 1.05rem; color: #fff;">' . esc_html__('Associated Social Group', 'gend-society') . '</h3>';
        $gid = 0;
        $blog_id = get_current_blog_id();

        if (function_exists('wu_get_site')) {
            try {
                $wu_site = wu_get_site($blog_id);
                if ($wu_site) {
                    $gid = (int) $wu_site->get_meta('gdc_bp_group_id', 0);
                }
            } catch (\Throwable $e) {
            }
        }
        if (!$gid) {
            $gid = (int) get_blog_option($blog_id, 'gdc_bp_group_id', 0);
        }

        if ($gid) {
            $name = sprintf(__('Group #%d', 'gend-society'), $gid);
            $link = '';
            $avatar = '';
            $g = null;
            $slug = '';

            if (function_exists('groups_get_group')) {
                try {
                    $g = groups_get_group(array('group_id' => $gid));
                } catch (\Throwable $e) {
                    $g = null;
                }
            }
            if ($g && !is_wp_error($g)) {
                if (!empty($g->name)) {
                    $name = $g->name;
                }
                $slug = !empty($g->slug) ? $g->slug : '';
            }
            if (empty($slug)) {
                global $wpdb;
                $table = $wpdb->base_prefix . 'bp_groups';
                $row = $wpdb->get_row($wpdb->prepare("SELECT id, name, slug FROM {$table} WHERE id = %d", $gid));
                if ($row) {
                    if (!empty($row->name)) {
                        $name = (string) $row->name;
                    }
                    if (empty($slug) && !empty($row->slug)) {
                        $slug = (string) $row->slug;
                    }
                }
            }

            $main_id = function_exists('wu_get_main_site_id') ? (int) wu_get_main_site_id() : (function_exists('get_main_site_id') ? (int) get_main_site_id() : 1);
            $main_home_link = function_exists('get_blog_option') ? (string) get_blog_option($main_id, 'home') : (string) get_option('home');
            if (empty($main_home_link)) {
                $main_home_link = (string) network_home_url('/');
            }
            $root = trailingslashit(rtrim($main_home_link, '/'));
            $slug_safe = $slug ? sanitize_title($slug) : (string) $gid;
            $link = $root . 'groups/' . $slug_safe . '/';

            if (function_exists('bp_core_fetch_avatar')) {
                $bp_root_id = function_exists('bp_get_root_blog_id') ? (int) bp_get_root_blog_id() : $main_id;
                $sw = false;
                if (is_multisite() && get_current_blog_id() !== $bp_root_id) {
                    switch_to_blog($bp_root_id);
                    $sw = true;
                }
                $avatar_html = bp_core_fetch_avatar(array(
                    'item_id' => $gid,
                    'object' => 'group',
                    'type' => 'thumb',
                    'width' => 48,
                    'height' => 48,
                    'html' => true,
                    'class' => 'wu-rounded-full',
                    'force_default' => false,
                ));
                if ($sw) {
                    restore_current_blog();
                }
                if (!empty($avatar_html) && is_string($avatar_html)) {
                    $avatar = $avatar_html;
                }
            }
            if (empty($avatar)) {
                $avatar = '<span class="dashicons dashicons-groups" style="font-size:32px; color: var(--gs-muted);"></span>';
            }

            $title_html = $link ? ('<a href="' . esc_url($link) . '" target="_blank" rel="noopener" style="color: #fff; font-weight: 600; text-decoration: none; display: block; margin-bottom: 8px;">' . esc_html($name) . '</a>') : esc_html($name);
            if ($link && !empty($avatar)) {
                $avatar = '<a href="' . esc_url($link) . '" target="_blank" rel="noopener" style="border-radius: 8px; overflow: hidden; display: block; width: 48px; height: 48px;">' . $avatar . '</a>';
            }

            echo '<div style="display: flex; align-items: center; gap: 16px; margin-top: 16px;">';
            echo $avatar;
            echo '<div>';
            echo $title_html;
            if ($link) {
                echo '<a class="gs-btn gs-btn-secondary" href="' . esc_url($link) . '" target="_blank" rel="noopener" style="padding: 4px 12px; font-size: 0.8rem;">' . esc_html__('Open Group', 'gend-society') . '</a>';
            }
            echo '</div>';
            echo '</div>';
        } else {
            echo '<p style="color: var(--gs-muted); margin-top: 10px;">' . esc_html__('No group linked to this App.', 'gend-society') . '</p>';
        }

        echo '</div>'; // End Owner Column

        echo '</div>'; // End Grid

        return ob_get_clean();
    }
}
