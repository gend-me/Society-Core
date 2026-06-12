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
        'siteUrl' => home_url(),
        'siteTitle' => get_bloginfo('name'),
        'gendOauth' => $is_gend_oauth,
        'gendHubUrl' => $hub_url,
        'gendAvatarUrl' => get_avatar_url($current_user->ID, ['size' => 80]),
        'gendProfileUrl' => $members_base,
        // Mirrors the gend.me frontend sidebar profile nav
        // (gs_build_frontend_profile_nav) — same items, order, and
        // dashicons — but pointed at gend.me/members/me/* so every wp-admin
        // user gets their own gend.me profile menu in the backend header.
        // Curated subset (avatar is rendered separately): the four
        // most-used profile sections.
        'gendProfileMenu' => [
            ['label' => 'App Projects', 'url' => $members_base . 'groups/',      'icon' => 'dashicons-screenoptions'],
            ['label' => 'Connections',  'url' => $members_base . 'friends/',     'icon' => 'dashicons-networking'],
            ['label' => 'Wallet',       'url' => $members_base . 'member-wallet/', 'icon' => 'dashicons-money-alt'],
            ['label' => 'Messages',     'url' => $members_base . 'messages/',    'icon' => 'dashicons-email'],
        ],
        // Connected web-app group menu — rendered across the centre of the
        // header. Each item opens admin.php?page=gs-group-embed&tab=<slug>
        // which renders that group section inline (not iframe). Capability-
        // filtered server-side by gs_group_embed_menu_items().
        'gendGroupMenu' => function_exists('gs_group_embed_menu_items') ? gs_group_embed_menu_items() : [],
        'gendGroupName' => function_exists('gs_group_embed_group_name') ? gs_group_embed_group_name() : '',
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

// Hide default WP admin bar bump + paint the gend.me gif background as
// a fixed, full-bleed layer behind every wp-admin page. The image sits on
// body::before so it stays put while the user scrolls; body::after lays a
// radial dark overlay so text remains legible regardless of the gif frame.
// #wpcontent / #wpbody / .wrap go transparent so the image shows through;
// individual surfaces (cards, panels) provide their own glass blur.
//
// The block / site / theme editors render their own full-bleed UI and
// would look broken with a gif behind them — exclude those screens.
add_action('admin_head', function () {
    global $pagenow;
    $exclude_pages = array(
        'site-editor.php',
        'theme-editor.php',
        'customize.php',
        'plugin-editor.php',
    );
    $is_block_editor = ( $pagenow === 'post.php' || $pagenow === 'post-new.php' );
    $skip_bg = in_array( $pagenow, $exclude_pages, true ) || $is_block_editor;

    echo '<style>#wpadminbar{display:none!important;}html{margin-top:0!important;padding-top:0!important;}';

    if ( ! $skip_bg ) {
        $bg_url = 'https://gend.me/wp-content/uploads/2026/03/account-background.gif';
        // body is made `position: relative` so it acts as the stacking root
        // for the negative-z pseudo-elements below. Pushing the bg + overlay
        // to z-index:-2 / -1 means no other element needs a positive z-index
        // to render above them — which avoids the chat widget (and other
        // position:fixed UI inside #wpwrap) getting trapped under an
        // accidental stacking context on wpwrap/wpcontent.
        echo '
        html { background: transparent !important; }
        body.wp-admin { background: transparent !important; position: relative; }
        body.wp-admin::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image: url("' . esc_url( $bg_url ) . '");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: -2;
            pointer-events: none;
        }
        body.wp-admin::after {
            content: "";
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse at top, rgba(11, 14, 20, 0.55) 0%, rgba(5, 7, 10, 0.85) 80%);
            z-index: -1;
            pointer-events: none;
        }
        body.wp-admin #wpwrap,
        body.wp-admin #wpcontent,
        body.wp-admin #wpbody,
        body.wp-admin #wpbody-content,
        body.wp-admin .wrap { background: transparent !important; }
        body.wp-admin #wpfooter { display: none !important; }

        /* Generic glass treatment for standard WP admin surfaces so
           non-dashboard pages also feel cohesive against the gif. Pages
           that already style their own panels (the gend.me dashboard)
           override these further. */
        body.wp-admin .card,
        body.wp-admin .postbox,
        body.wp-admin .stuffbox {
            background: linear-gradient(180deg, rgba(20, 24, 34, 0.55), rgba(11, 14, 20, 0.65)) !important;
            border: 1px solid rgba(255, 255, 255, 0.10) !important;
            border-radius: 14px !important;
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.06),
                0 16px 40px rgba(0, 0, 0, 0.35) !important;
            color: #e6edf7;
        }
        body.wp-admin .postbox > .postbox-header,
        body.wp-admin .postbox h2.hndle {
            background: transparent !important;
            border-bottom-color: rgba(255, 255, 255, 0.06) !important;
            color: #fff !important;
        }
        body.wp-admin .wp-list-table {
            background: rgba(11, 14, 20, 0.45) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 12px !important;
            backdrop-filter: blur(16px) saturate(150%);
            -webkit-backdrop-filter: blur(16px) saturate(150%);
            overflow: hidden;
        }
        body.wp-admin .wp-list-table thead,
        body.wp-admin .wp-list-table tfoot { background: transparent !important; }
        body.wp-admin .wp-list-table tr { background: transparent !important; }
        body.wp-admin .wp-list-table tr:hover { background: rgba(255, 255, 255, 0.03) !important; }
        ';
    }

    echo '</style>';
});

// Hide WP-admin footer text and version
add_filter('admin_footer_text', '__return_empty_string', 9999);
add_filter('update_footer', '__return_empty_string', 9999);
