<?php if (!defined('ABSPATH')) {
    exit;
} ?>
<div class="gs-page">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Features', 'gend-society'); ?>
            </span></h1>
    </div>
    <div class="gs-grid gs-grid-2">
        <div class="gs-card">
            <div class="gs-card-header">
                <h3><span class="dashicons dashicons-editor-code"></span>
                    <?php esc_html_e('Shortcodes', 'gend-society'); ?>
                </h3>
            </div>
            <div class="gs-card-body">
                <p>
                    <?php esc_html_e('View, edit, and create custom shortcodes for your site.', 'gend-society'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=gs-shortcodes')); ?>"
                    class="gs-btn gs-btn-primary">
                    <?php esc_html_e('Manage Shortcodes', 'gend-society'); ?>
                </a>
            </div>
        </div>
        <div class="gs-card">
            <div class="gs-card-header">
                <h3><span class="dashicons dashicons-admin-plugins"></span>
                    <?php esc_html_e('Code Packages', 'gend-society'); ?>
                </h3>
            </div>
            <div class="gs-card-body">
                <p>
                    <?php esc_html_e('Activate, deactivate, and manage installed plugins.', 'gend-society'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="gs-btn gs-btn-primary">
                    <?php esc_html_e('Manage Plugins', 'gend-society'); ?>
                </a>
            </div>
        </div>
        <div class="gs-card">
            <div class="gs-card-header">
                <h3><span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Updates', 'gend-society'); ?>
                </h3>
            </div>
            <div class="gs-card-body">
                <p>
                    <?php esc_html_e('Keep your site, plugins, and themes up to date.', 'gend-society'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="gs-btn gs-btn-primary">
                    <?php esc_html_e('Check Updates', 'gend-society'); ?>
                </a>
            </div>
        </div>
    </div>

    <?php
    // -- Overflow: other plugin menus not owned by GenD Society
    global $menu;
    $gend_society_slugs = [
        'gs-dashboard',
        'gs-users',
        'gs-app',
        'gs-store',
        'gs-social',
        'gs-rewards',
        'gs-features',
        'index.php',
        'users.php',
        'plugins.php',
        'update-core.php',
        'edit.php',
        'upload.php',
        'edit-comments.php',
        'themes.php',
        'tools.php',
        'options-general.php',
        'separator',
        'separator1',
        'separator2'
    ];
    $gend_society_overflow = [];
    if (is_array($menu)) {
        foreach ($menu as $gend_society_item) {
            $gend_society_slug = isset($gend_society_item[2]) ? $gend_society_item[2] : '';
            if ($gend_society_slug && !in_array($gend_society_slug, $gend_society_slugs, true) && !empty($gend_society_item[0]) && current_user_can($gend_society_item[1] ?? 'manage_options')) {
                $gend_society_overflow[] = $gend_society_item;
            }
        }
    }
    if ($gend_society_overflow): ?>
        <div class="gs-card" style="margin-top:24px;">
            <div class="gs-card-header" id="gs-overflow-header" style="cursor:pointer;user-select:none;"
                onclick="document.getElementById('gs-overflow-list').classList.toggle('gs-hidden');">
                <h3><span class="dashicons dashicons-ellipsis"></span>
                    <?php esc_html_e('Other Plugin Menus', 'gend-society'); ?> <span class="gs-toggle-caret">▾</span>
                </h3>
            </div>
            <div class="gs-card-body gs-quick-links" id="gs-overflow-list">
                <?php foreach ($gend_society_overflow as $gend_society_item):
                    $gend_society_label = wp_strip_all_tags($gend_society_item[0]);
                    $gend_society_slug = $gend_society_item[2];
                    $gend_society_url = (strpos($gend_society_slug, '.php') !== false) ? admin_url($gend_society_slug) : admin_url('admin.php?page=' . $gend_society_slug);
                    ?>
                    <a href="<?php echo esc_url($gend_society_url); ?>" class="gs-quick-link">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <span>
                            <?php echo esc_html($gend_society_label); ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>