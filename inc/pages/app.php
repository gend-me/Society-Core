<?php if (!defined('ABSPATH')) {
    exit;
} ?>
<div class="gs-page">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('App', 'gend-society'); ?>
            </span></h1>
    </div>
    <div class="gs-grid gs-grid-2">
        <div class="gs-card">
            <div class="gs-card-header">
                <h3><span class="dashicons dashicons-admin-appearance"></span>
                    <?php esc_html_e('Theme Editor', 'gend-society'); ?>
                </h3>
            </div>
            <div class="gs-card-body">
                <p>
                    <?php esc_html_e('Design your site header, footer, and global templates using the WordPress block-based Site Editor.', 'gend-society'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('site-editor.php')); ?>" class="gs-btn gs-btn-primary">
                    <?php esc_html_e('Open Site Editor', 'gend-society'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('site-editor.php?path=/wp_template_part/all')); ?>"
                    class="gs-btn gs-btn-secondary" style="margin-left:8px;">
                    <?php esc_html_e('Template Parts', 'gend-society'); ?>
                </a>
            </div>
        </div>
        <?php if (gs_plugin_active('blog-manager/blog-manager.php')): ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3><span class="dashicons dashicons-admin-post"></span>
                        <?php esc_html_e('Blog Manager', 'gend-society'); ?>
                    </h3>
                </div>
                <div class="gs-card-body">
                    <p>
                        <?php esc_html_e('Manage your blog posts, categories, and publishing workflow.', 'gend-society'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=blog-manager')); ?>"
                        class="gs-btn gs-btn-primary">
                        <?php esc_html_e('Open Blog Manager', 'gend-society'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <?php if (gs_plugin_active('email-manager/email-manager.php')): ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3><span class="dashicons dashicons-email"></span>
                        <?php esc_html_e('Email Manager', 'gend-society'); ?>
                    </h3>
                </div>
                <div class="gs-card-body">
                    <p>
                        <?php esc_html_e('Manage transactional emails, campaigns, and subscriber lists.', 'gend-society'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=email-manager')); ?>"
                        class="gs-btn gs-btn-primary">
                        <?php esc_html_e('Open Email Manager', 'gend-society'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>