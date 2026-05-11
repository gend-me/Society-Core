<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'gs_enqueue_admin_assets');
function gs_enqueue_admin_assets()
{
    $ver = GS_VERSION . '.' . filemtime(GS_DIR . 'assets/admin-style.css');
    wp_enqueue_style('gs-admin-style', GS_URL . 'assets/admin-style.css', ['dashicons'], $ver);
    wp_enqueue_style('gs-animation-utilities', GS_URL . 'assets/animation-utilities.css', [], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/animation-utilities.css'));
    wp_enqueue_script('gs-admin-script', GS_URL . 'assets/admin-script.js', [], $ver, true);

    $current_user = wp_get_current_user();

    // Always render the gend.me-profile-menu variant of the wp-admin header
    // for every logged-in user. The links target gend.me/members/me/* which
    // resolves to whichever account the viewer is signed into on gend.me
    // (or prompts them to sign in there) — so the same markup works for
    // OAuth-linked, unlinked, and hub-side users without branching.
    $is_gend_oauth = true;
    $hub_url       = function_exists('gs_oauth_hub_url') ? gs_oauth_hub_url() : 'https://gend.me';
    $hub_url       = rtrim($hub_url, '/');
    $members_base  = $hub_url . '/members/me/';

    wp_localize_script('gs-admin-script', 'gsAdminData', [
        'userName' => $current_user->display_name,
        'logoutUrl' => wp_logout_url(),
        'profileUrl' => admin_url('user-edit.php?user_id=' . $current_user->ID),
        'adminUrl' => admin_url(),
        'siteTitle' => get_bloginfo('name'),
        'gendOauth' => $is_gend_oauth,
        'gendHubUrl' => $hub_url,
        'gendAvatarUrl' => get_avatar_url($current_user->ID, ['size' => 80]),
        'gendProfileUrl' => $members_base,
        'gendProfileMenu' => [
            ['label' => 'Overview',     'url' => $members_base],
            ['label' => 'Portfolio',    'url' => $members_base . 'media/'],
            ['label' => 'App Projects', 'url' => $members_base . 'groups/'],
            ['label' => 'Activity',     'url' => $members_base . 'activity/'],
            ['label' => 'Connections',  'url' => $members_base . 'friends/'],
            ['label' => 'Wallet',       'url' => $members_base . 'member-wallet/'],
        ],
        // Inputs the header's Login-to-GenD button needs to drive the same
        // PKCE popup flow as wp-login.php (see oauth-login.php).
        'gendOauthClientId' => function_exists('gs_oauth_client_id') ? gs_oauth_client_id() : '',
        'gendOauthRestUrl'  => esc_url_raw(rest_url('gend-society/v1/oauth/login')),
    ]);

    global $pagenow;
    if ($pagenow === 'site-editor.php') {
        wp_enqueue_script('gs-template-modal', GS_URL . 'assets/gs-template-modal.js', ['jquery', 'wp-data', 'wp-blocks'], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/gs-template-modal.js'), true);
        wp_enqueue_script('gs-site-editor-init', GS_URL . 'assets/gs-site-editor-init.js', ['gs-template-modal'], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/gs-site-editor-init.js'), true);

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

// Hide WP-admin footer text and version
add_filter('admin_footer_text', '__return_empty_string', 9999);
add_filter('update_footer', '__return_empty_string', 9999);
