<?php if (!defined('ABSPATH')) {
    exit;
}

// Ensure the current user has permission to manage users
if (!current_user_can('list_users')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'gend-society'));
}

// Handle form submission to save feature access
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gs_feature_access_nonce']) && wp_verify_nonce($_POST['gs_feature_access_nonce'], 'gs_save_feature_access')) {
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
    
    if ($target_user_id && current_user_can('edit_user', $target_user_id)) {
        // Sanitize and save the array of allowed menu slugs
        $allowed_slugs = isset($_POST['gs_allowed_menus']) && is_array($_POST['gs_allowed_menus']) ? array_map('sanitize_text_field', wp_unslash($_POST['gs_allowed_menus'])) : [];
        update_user_meta($target_user_id, 'gs_feature_access', $allowed_slugs);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Feature access updated successfully.', 'gend-society') . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('You do not have permission to edit this user.', 'gend-society') . '</p></div>';
    }
}

// Fetch all users that can access the backend (at least edit_posts, adjust as needed)
$args = array(
    'role__not_in' => array('subscriber', 'customer'), // Exclude purely frontend roles if desired, or allow all depending on use case.
    'orderby'      => 'display_name',
    'order'        => 'ASC',
);
$users = get_users($args);

// Helper function to render the menu checkboxes
function gs_render_menu_access_checkboxes($target_user_id) {
    global $menu, $submenu;
    $saved_access = get_user_meta($target_user_id, 'gs_feature_access', true);
    if (!is_array($saved_access)) {
        $saved_access = [];
    }

    $html = '<div class="gs-grid gs-grid-2">';
    
    foreach ($menu as $item) {
        $menu_slug = isset($item[2]) ? $item[2] : '';
        if (!$menu_slug || $menu_slug === 'separator' || strpos($menu_slug, 'separator') === 0) continue;
        
        $menu_name = wp_strip_all_tags($item[0]);
        $is_menu_checked = in_array($menu_slug, $saved_access) ? 'checked' : '';

        $html .= '<div class="gs-card" style="margin-bottom: 20px; padding: 15px;">';
        $html .= '<h4><label><input type="checkbox" name="gs_allowed_menus[]" value="' . esc_attr($menu_slug) . '" ' . $is_menu_checked . ' class="gs-parent-checkbox"> <strong>' . esc_html($menu_name) . '</strong></label></h4>';
        
        if (isset($submenu[$menu_slug]) && is_array($submenu[$menu_slug])) {
            $html .= '<ul style="margin-left: 20px;">';
            foreach ($submenu[$menu_slug] as $sub_item) {
                $sub_slug = isset($sub_item[2]) ? $sub_item[2] : '';
                if (!$sub_slug) continue;
                
                $sub_name = wp_strip_all_tags($sub_item[0]);
                $is_sub_checked = in_array($sub_slug, $saved_access) ? 'checked' : '';
                $html .= '<li><label><input type="checkbox" name="gs_allowed_menus[]" value="' . esc_attr($sub_slug) . '" ' . $is_sub_checked . ' class="gs-child-checkbox"> ' . esc_html($sub_name) . '</label></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';

    // Simple script to handle parent/child checkbox selection logic if desired.
    $html .= '<script>
        jQuery(document).ready(function($) {
            $(".gs-parent-checkbox").change(function() {
                $(this).closest(".gs-card").find(".gs-child-checkbox").prop("checked", $(this).prop("checked"));
            });
            $(".gs-child-checkbox").change(function() {
                if ($(this).prop("checked")) {
                    $(this).closest(".gs-card").find(".gs-parent-checkbox").prop("checked", true);
                }
            });
        });
    </script>';

    return $html;
}
?>

<div class="gs-page wrap">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Feature Access', 'gend-society'); ?>
            </span></h1>
        <p><?php esc_html_e('Manage which menu items and features are available to each user on the backend and frontend admin bar.', 'gend-society'); ?></p>
    </div>

    <?php 
    // Determine if we are editing a specific user or listing all users
    $edit_user_id = isset($_GET['edit_user']) ? intval($_GET['edit_user']) : 0;
    
    if ($edit_user_id && current_user_can('edit_user', $edit_user_id)) {
        $edit_user_obj = get_userdata($edit_user_id);
        if ($edit_user_obj) {
            ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3><?php printf(esc_html__('Editing Access for: %s', 'gend-society'), esc_html($edit_user_obj->display_name)); ?></h3>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=gs-feature-access')); ?>" class="button"><?php esc_html_e('&larr; Back to List', 'gend-society'); ?></a>
                </div>
                <div class="gs-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field('gs_save_feature_access', 'gs_feature_access_nonce'); ?>
                        <input type="hidden" name="target_user_id" value="<?php echo esc_attr($edit_user_id); ?>">
                        
                        <?php echo gs_render_menu_access_checkboxes($edit_user_id); ?>
                        
                        <p class="submit">
                            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Feature Access', 'gend-society'); ?>">
                        </p>
                    </form>
                </div>
            </div>
            <?php
        } else {
             echo '<div class="notice notice-error"><p>' . esc_html__('User not found.', 'gend-society') . '</p></div>';
        }
    } else {
    ?>
    
    <div class="gs-card">
        <div class="gs-card-header">
            <h3><?php esc_html_e('User List', 'gend-society'); ?></h3>
        </div>
        <div class="gs-card-body">
            <table class="wp-list-table widefat fixed striped users">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-username"><?php esc_html_e('Username', 'gend-society'); ?></th>
                        <th scope="col" class="manage-column column-name"><?php esc_html_e('Name', 'gend-society'); ?></th>
                        <th scope="col" class="manage-column column-role"><?php esc_html_e('Roles', 'gend-society'); ?></th>
                        <th scope="col" class="manage-column column-action"><?php esc_html_e('Action', 'gend-society'); ?></th>
                    </tr>
                </thead>
                <tbody id="the-list">
                    <?php foreach ($users as $user) : 
                        $roles = implode(', ', array_map('ucfirst', $user->roles));
                        $edit_link = add_query_arg(array('page' => 'gs-feature-access', 'edit_user' => $user->ID), admin_url('admin.php'));
                    ?>
                        <tr id="user-<?php echo esc_attr($user->ID); ?>">
                            <td class="username column-username" data-colname="<?php esc_attr_e('Username', 'gend-society'); ?>">
                                <strong><a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($user->user_login); ?></a></strong>
                            </td>
                            <td class="name column-name" data-colname="<?php esc_attr_e('Name', 'gend-society'); ?>">
                                <?php echo esc_html($user->display_name); ?>
                            </td>
                            <td class="role column-role" data-colname="<?php esc_attr_e('Roles', 'gend-society'); ?>">
                                <?php echo esc_html($roles); ?>
                            </td>
                            <td class="action column-action" data-colname="<?php esc_attr_e('Action', 'gend-society'); ?>">
                                <a href="<?php echo esc_url($edit_link); ?>" class="button button-small"><?php esc_html_e('Manage Access', 'gend-society'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)) : ?>
                        <tr><td colspan="4"><?php esc_html_e('No eligible users found.', 'gend-society'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php } ?>
</div>
