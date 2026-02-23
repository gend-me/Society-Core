<?php if (!defined('ABSPATH')) {
    exit;
} ?>
<div class="gs-page">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Store', 'gend-society'); ?>
            </span></h1>
    </div>
    <div class="gs-grid gs-grid-4">
        <a href="<?php echo esc_url(admin_url('admin.php?page=gdc-store-settings')); ?>" class="gs-card gs-card-link">
            <div class="gs-card-icon gs-icon-magenta"><span class="dashicons dashicons-admin-settings"></span></div>
            <div class="gs-card-body">
                <div class="gs-stat-label">
                    <?php esc_html_e('Store Settings', 'gend-society'); ?>
                </div>
            </div>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gdc-store-order-management')); ?>"
            class="gs-card gs-card-link">
            <div class="gs-card-icon gs-icon-blue"><span class="dashicons dashicons-cart"></span></div>
            <div class="gs-card-body">
                <div class="gs-stat-label">
                    <?php esc_html_e('Order Management', 'gend-society'); ?>
                </div>
            </div>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gdc-store-product-sales-funnels')); ?>"
            class="gs-card gs-card-link">
            <div class="gs-card-icon gs-icon-accent"><span class="dashicons dashicons-products"></span></div>
            <div class="gs-card-body">
                <div class="gs-stat-label">
                    <?php esc_html_e('Products & Funnels', 'gend-society'); ?>
                </div>
            </div>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gdc-store-reports')); ?>" class="gs-card gs-card-link">
            <div class="gs-card-icon gs-icon-accent"><span class="dashicons dashicons-chart-bar"></span></div>
            <div class="gs-card-body">
                <div class="gs-stat-label">
                    <?php esc_html_e('Store Reports', 'gend-society'); ?>
                </div>
            </div>
        </a>
    </div>
</div>