<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'gs_enqueue_admin_assets');
function gs_enqueue_admin_assets()
{
    $ver = GS_VERSION . '.' . filemtime(GS_DIR . 'assets/admin-style.css');
    wp_enqueue_style('gs-admin-style', GS_URL . 'assets/admin-style.css', ['dashicons'], $ver);
    wp_enqueue_script('gs-admin-script', GS_URL . 'assets/admin-script.js', [], $ver, true);

    $current_user = wp_get_current_user();
    wp_localize_script('gs-admin-script', 'gsAdminData', [
        'userName' => $current_user->display_name,
        'logoutUrl' => wp_logout_url(),
        'profileUrl' => admin_url('user-edit.php?user_id=' . $current_user->ID),
        'adminUrl' => admin_url(),
        'siteTitle' => get_bloginfo('name')
    ]);

    global $pagenow;
    if ($pagenow === 'site-editor.php') {
        wp_enqueue_script('gs-template-modal', GS_URL . 'assets/gs-template-modal.js', ['jquery', 'wp-data', 'wp-blocks'], GS_VERSION, true);
        wp_enqueue_script('gs-site-editor-init', GS_URL . 'assets/gs-site-editor-init.js', ['gs-template-modal'], GS_VERSION, true);

        wp_localize_script('gs-template-modal', 'GS_TEMPLATE_MODAL', [
            'rest_url' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }
}

// Enqueue supplemental assets for pages whose primary plugin (online-store) is active
// but whose page-specific extras were previously provided by gen-d-core.
add_action('admin_enqueue_scripts', function () {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    if ($page === 'gdc-store-product-sales-funnels') {
        // Email nurturing tab assets (defined in gen-d-core with an !function_exists guard;
        // call it here so gend-society owns the responsibility when gen-d-core is inactive).
        if (function_exists('gdc_enqueue_email_nurture_assets')) {
            gdc_enqueue_email_nurture_assets();
        }
    }
});

// Hide default WP admin bar bump
add_action('admin_head', function () {
    echo '<style>#wpadminbar{display:none!important;}html{margin-top:0!important;padding-top:0!important;}</style>';
});
