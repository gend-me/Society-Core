<?php if (!defined('ABSPATH')) {
    exit;
} ?>
<div class="gs-page">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Users', 'gend-society'); ?>
            </span></h1>
    </div>
    <div class="gs-card" style="margin-bottom:24px;">
        <div class="gs-card-header">
            <h3>
                <?php esc_html_e('User Management', 'gend-society'); ?>
            </h3>
        </div>
        <div class="gs-card-body gs-quick-links">
            <a href="<?php echo esc_url(admin_url('users.php')); ?>" class="gs-quick-link"><span
                    class="dashicons dashicons-admin-users"></span><span>
                    <?php esc_html_e('All Users', 'gend-society'); ?>
                </span></a>
            <a href="<?php echo esc_url(admin_url('user-new.php')); ?>" class="gs-quick-link"><span
                    class="dashicons dashicons-plus"></span><span>
                    <?php esc_html_e('Add New User', 'gend-society'); ?>
                </span></a>
            <a href="<?php echo esc_url(admin_url('profile.php')); ?>" class="gs-quick-link"><span
                    class="dashicons dashicons-id"></span><span>
                    <?php esc_html_e('Your Profile', 'gend-society'); ?>
                </span></a>
        </div>
    </div>
    <div class="gs-card">
        <div class="gs-card-header">
            <h3>
                <?php esc_html_e('Users by Role', 'gend-society'); ?>
            </h3>
        </div>
        <div class="gs-card-body">
            <?php
            $counts = count_users();
            echo '<div class="gs-grid gs-grid-3">';
            foreach ($counts['avail_roles'] as $role => $count) {
                if (!$count) {
                    continue;
                }
                printf(
                    '<div class="gs-card gs-card-stat"><div class="gs-card-icon gs-icon-magenta"><span class="dashicons dashicons-admin-users"></span></div><div class="gs-card-body"><div class="gs-stat-number">%d</div><div class="gs-stat-label">%s</div></div></div>',
                    intval($count),
                    esc_html(ucwords(str_replace(['_', '-'], ' ', $role)))
                );
            }
            echo '</div>';
            ?>
        </div>
    </div>
</div>