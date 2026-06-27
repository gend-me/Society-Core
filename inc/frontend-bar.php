<?php
if (!defined('ABSPATH')) {
  exit;
}

// Cached admin-mirror nav for the current request. Used by both the gate and
// the renderer so we don't rebuild it three times per page.
function gs_frontend_bar_admin_nav()
{
  static $cache = null;
  if ($cache === null) {
    $cache = is_user_logged_in() ? gs_build_frontend_nav() : [];
  }
  return $cache;
}

// True when the current request is an iframed embed of a BP group
// (rendered by [psoo_group] from the projects plugin). In that case the
// parent host page is already painting the gend-society chrome — we
// must not duplicate it inside the iframe.
function gs_is_embed_request()
{
  static $cached = null;
  if ($cached !== null) return $cached;
  $cached = isset($_GET['gend_embed']) && (string) $_GET['gend_embed'] === '1';
  return $cached;
}

// The sidebar shows when the user is logged in AND (admin-mirror nav has
// items OR social plugin is active so we can use the profile-mirror nav).
// Suppressed entirely on iframed embed requests so the parent page's
// chrome isn't duplicated inside the iframe.
function gs_frontend_bar_should_show()
{
  if (gs_is_embed_request()) {
    return false;
  }
  if (!is_user_logged_in()) {
    return false;
  }
  if (!empty(gs_frontend_bar_admin_nav())) {
    return true;
  }
  return gs_plugin_active('social-network/social-network.php');
}

// Profile-mirror mode kicks in whenever the admin-mirror nav is empty AND
// the social plugin is active — covers BOTH "user lacks edit_posts" and
// "user has edit_posts but no gs_feature_access entries" (the symptom is the
// same: a logged-in user with no accessible WP-admin menus).
function gs_frontend_bar_is_profile_mode()
{
  if (!is_user_logged_in()) {
    return false;
  }
  if (!gs_plugin_active('social-network/social-network.php')) {
    return false;
  }
  return empty(gs_frontend_bar_admin_nav());
}

// Enqueue frontend assets for the sidebar.
add_action('wp_enqueue_scripts', 'gs_enqueue_frontend_assets');
function gs_enqueue_frontend_assets()
{
  if (!gs_frontend_bar_should_show()) {
    return;
  }
  $v = GS_VERSION . '.' . filemtime(GS_DIR . 'assets/frontend-bar.css');
  wp_enqueue_style('gs-frontend-bar', GS_URL . 'assets/frontend-bar.css', ['dashicons'], $v);
  wp_enqueue_script('gs-frontend-bar-js', GS_URL . 'assets/frontend-bar.js', [], $v, true);

  // Enqueue chat modal
  wp_enqueue_script('gs-template-modal', GS_URL . 'assets/gs-template-modal.js', ['jquery'], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/gs-template-modal.js'), true);
  wp_localize_script('gs-template-modal', 'GS_TEMPLATE_MODAL', [
    'rest_url' => esc_url_raw(rest_url()),
    'nonce' => wp_create_nonce('wp_rest')
  ]);

  // Hide WP admin bar on frontend
  remove_action('wp_head', '_admin_bar_bump_cb');
  add_action('wp_head', function () {
    echo '<style>#wpadminbar{display:none!important;}html{margin-top:0!important;}</style>';
  });
}

/**
 * Paint the gend.me gif background on BuddyPress groups pages so the
 * frontend groups area matches the wp-admin look. Same fixed pseudo-element
 * pattern as inc/admin-style.php — gif on body::before, dark radial
 * overlay on body::after, theme wrappers go transparent so it shows
 * through. BuddyPress group "item" cards get a light glass treatment so
 * they sit on the gif rather than floating raw.
 *
 * Scoped to any /groups/* URL (directory, single group, user's groups tab)
 * by bp_is_groups_component(). All other frontend pages render normally.
 */
add_action('wp_head', 'gs_groups_frontend_background', 99);
function gs_groups_frontend_background() {
    if ( is_admin() ) {
        return;
    }
    if ( ! function_exists( 'bp_is_groups_component' ) || ! bp_is_groups_component() ) {
        return;
    }
    $bg_url = 'https://gend.me/wp-content/uploads/2026/03/account-background.gif';
    ?>
    <style id="gs-groups-bg">
        html { background: transparent !important; }
        body { background: transparent !important; position: relative; }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image: url("<?php echo esc_url( $bg_url ); ?>");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: -2;
            pointer-events: none;
        }
        body::after {
            content: "";
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse at top, rgba(11, 14, 20, 0.55) 0%, rgba(5, 7, 10, 0.85) 80%);
            z-index: -1;
            pointer-events: none;
        }
        /* Theme content wrappers — broad selector so this works whether the
           hub theme is Astra, Hello, Elementor canvas, or block themes. Any
           wrapper that paints its own solid background gets cleared so the
           gif shows through. */
        body #page,
        body .site,
        body #content,
        body #primary,
        body .content-area,
        body .site-content,
        body .site-main,
        body .elementor-section-wrap,
        body .entry-content { background: transparent !important; }

        /* Group cards — frosted glass so each tile reads on the gif. */
        body #buddypress .item-list li.item,
        body #buddypress .bp-list li.item,
        body #buddypress #groups-dir-list .item,
        body #buddypress .group-card,
        body #buddypress .members-group-loop .item,
        body #groups-list .item {
            background: linear-gradient(180deg, rgba(20, 24, 34, 0.55), rgba(11, 14, 20, 0.65)) !important;
            border: 1px solid rgba(255, 255, 255, 0.10) !important;
            border-radius: 14px !important;
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.06),
                0 16px 40px rgba(0, 0, 0, 0.35);
            color: #e6edf7 !important;
            padding: 18px !important;
            margin-bottom: 14px !important;
        }
        body #buddypress .item-list li.item a,
        body #buddypress .bp-list li.item a,
        body #buddypress #groups-dir-list .item a { color: #c7d2fe !important; }
        body #buddypress .item-list li.item a:hover,
        body #buddypress .bp-list li.item a:hover { color: #fff !important; }

        /* Single group header / nav so it doesn't look like a white slab
           pasted onto the gif. */
        body #buddypress.single-item,
        body #buddypress #item-header,
        body #buddypress #item-nav,
        body #buddypress #item-body,
        body #buddypress .item-list-tabs,
        body #buddypress .item-list-tabs ul {
            background: transparent !important;
            color: #e6edf7;
        }
        body #buddypress #item-header,
        body #buddypress #item-body {
            background: linear-gradient(180deg, rgba(20, 24, 34, 0.55), rgba(11, 14, 20, 0.65)) !important;
            border: 1px solid rgba(255, 255, 255, 0.10) !important;
            border-radius: 16px !important;
            padding: 20px !important;
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.06),
                0 16px 40px rgba(0, 0, 0, 0.35);
            margin-bottom: 16px;
        }
        body #buddypress #item-header h2,
        body #buddypress #item-header h1,
        body #buddypress #item-body h1,
        body #buddypress #item-body h2,
        body #buddypress #item-body h3 { color: #fff !important; }

        /* Directory subnav pills */
        body #buddypress .item-list-tabs li a,
        body #buddypress div.item-list-tabs#subnav ul li a {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            color: #cbd5f5;
            padding: 6px 12px;
        }
        body #buddypress .item-list-tabs li.selected a,
        body #buddypress div.item-list-tabs#subnav ul li.selected a {
            background: rgba(78, 170, 255, 0.18) !important;
            border-color: rgba(78, 170, 255, 0.45) !important;
            color: #fff !important;
        }

        /* ── Group-page section blocks ─────────────────────────────────
           Every "panel" you can land on inside a group (home, members,
           activity, invites, manage, send-invites, custom tabs) gets the
           same frosted-glass treatment so the gif background reads
           consistently across the whole group experience. */
        body #buddypress .home-tabs,
        body #buddypress .group-home,
        body #buddypress #activity-stream,
        body #buddypress .activity,
        body #buddypress .activity-list .activity-item,
        body #buddypress #subnav,
        body #buddypress .subnav,
        body #buddypress .group-members,
        body #buddypress #members-group-list,
        body #buddypress .members .item-list,
        body #buddypress .group-invites,
        body #buddypress #invitation-list,
        body #buddypress .group-settings-section,
        body #buddypress .group-create-body,
        body #buddypress .standard-form,
        body #buddypress .dir-form,
        body #buddypress .bp-feedback,
        body #buddypress .bp-messages,
        body #buddypress .info-group,
        body #buddypress .gs-group-tab-panel {
            background: linear-gradient(180deg, rgba(20, 24, 34, 0.55), rgba(11, 14, 20, 0.65)) !important;
            border: 1px solid rgba(255, 255, 255, 0.10) !important;
            border-radius: 14px !important;
            backdrop-filter: blur(18px) saturate(160%);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.06),
                0 14px 32px rgba(0, 0, 0, 0.32);
            color: #e6edf7 !important;
            padding: 18px !important;
            margin-bottom: 14px !important;
        }
        /* Nested panels inside an already-glassed wrapper drop the second
           backdrop blur so things don't fog up. */
        body #buddypress #item-body .home-tabs,
        body #buddypress #item-body .group-home,
        body #buddypress #item-body .activity-list .activity-item,
        body #buddypress #item-body .standard-form,
        body #buddypress #item-body .group-members,
        body #buddypress #item-body #members-group-list,
        body #buddypress #item-body .gs-group-tab-panel {
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            background: rgba(255, 255, 255, 0.03) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04) !important;
        }
        /* Common BP text + meta legibility on the dark glass */
        body #buddypress p,
        body #buddypress .activity-content,
        body #buddypress .activity-header,
        body #buddypress .activity-meta,
        body #buddypress .item-meta,
        body #buddypress .item-desc,
        body #buddypress .info-group p { color: #e6edf7 !important; }
        body #buddypress .activity-header strong a,
        body #buddypress .item-title a,
        body #buddypress h2 a,
        body #buddypress h3 a { color: #fff !important; }
        body #buddypress .item-meta,
        body #buddypress .activity-meta a,
        body #buddypress small,
        body #buddypress time { color: rgba(203, 213, 245, 0.65) !important; }
        body #buddypress input[type="text"],
        body #buddypress input[type="search"],
        body #buddypress input[type="email"],
        body #buddypress textarea {
            background: rgba(0, 0, 0, 0.25) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: #fff !important;
            border-radius: 8px !important;
        }
        body #buddypress button,
        body #buddypress .button,
        body #buddypress input[type="submit"] {
            background: rgba(78, 170, 255, 0.18) !important;
            border: 1px solid rgba(78, 170, 255, 0.40) !important;
            color: #fff !important;
            border-radius: 8px !important;
        }
        body #buddypress button:hover,
        body #buddypress .button:hover,
        body #buddypress input[type="submit"]:hover {
            background: rgba(78, 170, 255, 0.30) !important;
        }
    </style>
    <?php
}

// Enqueue global site branding and animation utilities for ALL visitors
add_action('wp_enqueue_scripts', 'gs_enqueue_global_frontend_assets');
function gs_enqueue_global_frontend_assets()
{
    // Animations
    wp_enqueue_style('gs-animation-utilities', GS_URL . 'assets/animation-utilities.css', [], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/animation-utilities.css'));

    // Site Header & Footer Branding
    wp_enqueue_style('gs-site-header-footer', GS_URL . 'assets/site-header-footer.css', [], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/site-header-footer.css'));
}


// NOTE: admin gsAdminData is localized by admin-style.php
// (gs_enqueue_admin_assets) with the FULL data set — userName, logout,
// gendOauth, gendProfileMenu, etc. A second, minimal localize used to
// live here and clobbered window.gsAdminData (it ran on the same
// admin_enqueue_scripts hook and overwrote the rich object with one that
// had no gendOauth), which made the backend header fall back to the old
// image-button layout. Removed — admin-style.php is the single source.

// Inject the sidebar into wp_footer for logged-in users
add_action('wp_footer', 'gs_render_frontend_bar', 5);
function gs_render_frontend_bar()
{
  if (!gs_frontend_bar_should_show()) {
    return;
  }

  $user = wp_get_current_user();
  $avatar = get_avatar_url($user->ID, ['size' => 60]);
  $avatar_init = strtoupper(substr($user->display_name, 0, 1));
  $role = implode(', ', array_map('ucfirst', $user->roles));

  // Determine edit link for current page
  $post_id = get_queried_object_id();
  $post_type = $post_id ? get_post_type($post_id) : '';
  $edit_url = '';

  // Check if we are on a singular post/page/product and user has permission
  if (is_singular() && $post_id) {
    $post_type_object = get_post_type_object($post_type);
    if ($post_type_object && current_user_can($post_type_object->cap->edit_post, $post_id)) {
      $edit_url = get_edit_post_link($post_id, 'raw');
    }
  } elseif (function_exists('is_shop') && is_shop()) {
    // Special case for WooCommerce Shop page which is an archive but backed by a page ID
    $shop_page_id = get_option('woocommerce_shop_page_id');
    if ($shop_page_id && current_user_can('edit_page', $shop_page_id)) {
      $edit_url = get_edit_post_link($shop_page_id, 'raw');
    }
  } elseif (is_home() && get_option('page_for_posts')) {
    // Special case for the Blog Posts page which is an archive but backed by a page ID
    $blog_page_id = get_option('page_for_posts');
    if ($blog_page_id && current_user_can('edit_page', $blog_page_id)) {
      $edit_url = get_edit_post_link($blog_page_id, 'raw');
    }
  }

  // Build the nav items.
  // - If the admin-mirror nav has items → render those (existing behaviour).
  // - Otherwise (no accessible WP-admin menus) and social plugin active →
  //   render the profile-mirror nav.
  $nav_items = gs_frontend_bar_admin_nav();
  if (empty($nav_items) && gs_frontend_bar_is_profile_mode()) {
    $nav_items = gs_build_frontend_profile_nav();
  }
  $cart_count = 0;
  if (function_exists('WC') && WC() && WC()->cart) {
    $cart_count = (int) WC()->cart->get_cart_contents_count();
  }
  ?>
  <!-- Top-right floating menu toggle: reveals the slide-in sidebar. -->
  <button type="button" class="gs-float-menu" id="gs-float-menu"
          aria-label="<?php esc_attr_e('Open menu', 'gend-society'); ?>"
          aria-expanded="false" aria-controls="gs-front-sidebar">
    <span class="gs-float-menu-glow" aria-hidden="true"></span>
    <span class="gs-float-menu-icon" aria-hidden="true">
      <span></span><span></span><span></span>
    </span>
  </button>

  <div class="gs-front-sidebar" id="gs-front-sidebar" role="navigation" aria-label="<?php esc_attr_e('Admin Sidebar', 'gend-society'); ?>" aria-hidden="true">

    <!-- Brand -->
    <a href="<?php echo esc_url(admin_url('index.php')); ?>" class="gs-sidebar-brand gs-delay-1" data-gs-animate style="min-height: 56px;">
    </a>

    <div class="gs-sidebar-divider gs-delay-2" data-gs-animate></div>

    <!-- Edit Action -->
    <?php if ($edit_url): ?>
      <div class="gs-sidebar-actions gs-delay-3" data-gs-animate>
        <a href="<?php echo esc_url($edit_url); ?>" class="gs-sidebar-action-btn"
          aria-label="<?php esc_attr_e('Edit this page', 'gend-society'); ?>">
          <span class="gs-sidebar-icon dashicons dashicons-edit"></span>
          <span class="gs-sidebar-label"><?php esc_html_e('Edit Page', 'gend-society'); ?></span>
        </a>
      </div>
      <div class="gs-sidebar-divider gs-delay-4" data-gs-animate></div>
    <?php endif; ?>

    <!-- Nav -->
    <nav class="gs-sidebar-nav">
      <?php
      $idx = 5;
      foreach ($nav_items as $item):
        $has_sub = !empty($item['children']);
        $target = !empty($item['external']) ? ' target="_blank" rel="noopener"' : '';
        ?>
        <div class="gs-sidebar-item<?php echo $has_sub ? '" data-has-sub="1' : ''; ?> gs-delay-<?php echo $idx++; ?>" data-gs-animate>
          <a href="<?php echo esc_url($item['url']); ?>" class="gs-sidebar-link" <?php echo $target; ?>>
            <span class="gs-sidebar-icon dashicons <?php echo esc_attr($item['icon']); ?>"></span>
            <span class="gs-sidebar-label"><?php echo esc_html($item['label']); ?></span>
          </a>
          <?php if ($has_sub): ?>
            <div class="gs-sidebar-flyout">
              <div class="gs-sidebar-flyout-title"><?php echo esc_html($item['label']); ?></div>
              <?php foreach ($item['children'] as $child): ?>
                <a href="<?php echo esc_url($child['url']); ?>"><?php echo esc_html($child['label']); ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </nav>
  </div>

  <!-- Bottom-left floating dock: profile + cart, both glassmorphic. -->
  <div class="gs-float-dock" role="group" aria-label="<?php esc_attr_e('Quick actions', 'gend-society'); ?>">
    <a href="/members/me" class="gs-float-btn gs-float-profile"
       aria-label="<?php echo esc_attr(sprintf(__('Open profile for %s', 'gend-society'), $user->display_name)); ?>">
      <span class="gs-float-btn-ring" aria-hidden="true"></span>
      <span class="gs-float-avatar">
        <?php if ($avatar): ?>
          <img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
        <?php else: ?>
          <?php echo esc_html($avatar_init); ?>
        <?php endif; ?>
      </span>
      <span class="gs-float-tooltip"><?php echo esc_html($user->display_name); ?></span>
    </a>

    <?php if (function_exists('WC')): ?>
      <button type="button" class="gs-float-btn gs-float-cart" id="gs-float-cart"
              aria-label="<?php esc_attr_e('Open shopping cart', 'gend-society'); ?>">
        <span class="gs-float-btn-ring" aria-hidden="true"></span>
        <svg class="gs-float-cart-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        <span class="gs-float-cart-count"<?php echo $cart_count > 0 ? '' : ' hidden'; ?>><?php echo (int) $cart_count; ?></span>
        <span class="gs-float-tooltip"><?php esc_html_e('Cart', 'gend-society'); ?></span>
      </button>
    <?php endif; ?>
  </div>

  <?php if (function_exists('WC')): ?>
  <script>
    (function () {
      // Wire the floating cart button to #gs-mini-cart-overlay (injected by
      // gs_inject_mini_cart) and keep the badge synced with WC fragments.
      function gsBindFloatCart () {
        var trigger = document.getElementById('gs-float-cart');
        var overlay = document.getElementById('gs-mini-cart-overlay');
        if (!trigger || !overlay || trigger.__gsBound) return;
        trigger.__gsBound = true;
        trigger.addEventListener('click', function (e) {
          e.preventDefault();
          overlay.classList.toggle('is-open');
          overlay.setAttribute('aria-hidden', overlay.classList.contains('is-open') ? 'false' : 'true');
        });
        document.body.addEventListener('wc_fragments_refreshed', function () {
          var countEl = trigger.querySelector('.gs-float-cart-count');
          if (!countEl) return;
          var items = document.querySelectorAll('#gs-mini-cart-overlay .mini_cart_item');
          var total = items.length;
          if (total > 0) {
            countEl.textContent = total;
            countEl.hidden = false;
          } else {
            countEl.hidden = true;
          }
        });
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', gsBindFloatCart);
      } else {
        gsBindFloatCart();
      }
      // The header-injected .gs-mini-cart-btn is redundant for logged-in
      // users — hide it so we don't show two cart triggers.
      var hide = document.createElement('style');
      hide.textContent = '.gs-mini-cart-btn{display:none!important;}';
      document.head.appendChild(hide);
    })();
  </script>
  <?php endif; ?>
  <?php
}

/**
 * Build frontend nav from the current user's accessible admin menu.
 */
function gs_build_frontend_nav()
{
  $items = [];

  // Our top-level nav structure (capability-checked)
  $items[] = [
    'label' => __('Dashboard', 'gend-society'),
    'url' => admin_url('index.php'),
    'icon' => 'dashicons-dashboard',
    'cap' => 'read',
    'children' => [],
  ];

  // Users menu removed from the sidebar — User Access lives in the
  // dashboard membership card's User Access tab. Your Profile is still
  // reachable via the admin bar avatar dropdown.

  // Build the App menu children dynamically
  $app_children = [
    ['label' => __('Theme Editor', 'gend-society'), 'url' => admin_url('site-editor.php')],
  ];

  if (current_user_can('upload_files')) {
    $app_children[] = ['label' => __('Media', 'gend-society'), 'url' => admin_url('upload.php')];
  }

  if (current_user_can('manage_options')) {
    $app_children[] = ['label' => __('Permalinks', 'gend-society'), 'url' => admin_url('options-permalink.php')];
  }

  $items[] = [
    'label' => __('App', 'gend-society'),
    'url' => admin_url('admin.php?page=gs-app'),
    'icon' => 'dashicons-admin-appearance',
    'cap' => 'edit_theme_options',
    'children' => $app_children,
  ];

  // Build the Content menu
  $content_children = [
    ['label' => __('Pages', 'gend-society'), 'url' => admin_url('edit.php?post_type=page')],
  ];
  if (gs_plugin_active('blog-manager/blog-manager.php') && current_user_can('edit_posts')) {
    $content_children[] = ['label' => __('Blog Manager', 'gend-society'), 'url' => admin_url('admin.php?page=blog-manager')];
  }
  if (gs_plugin_active('email-manager/email-manager.php') && current_user_can('manage_options')) {
    $content_children[] = ['label' => __('Conversations', 'gend-society'), 'url' => admin_url('admin.php?page=email-manager')];
  }

  $items[] = [
    'label' => __('Write', 'gend-society'),
    'url' => admin_url('admin.php?page=gs-content'),
    'icon' => 'dashicons-edit',
    'cap' => 'manage_options',
    'children' => $content_children,
  ];

  // Conditionally add Store
  $has_store_apps = gs_plugin_active('online-store/online-store.php') || gs_plugin_active('sales-team/advanced-affiliate-system.php') || gs_plugin_active('projects/project-service-orders.php');
  if ($has_store_apps) {
    $store_children = [];

    // Add Online Store submenus if active
    if (gs_plugin_active('online-store/online-store.php') && current_user_can('manage_woocommerce')) {
      $store_children[] = ['label' => __('Store Settings', 'gend-society'), 'url' => admin_url('admin.php?page=gdc-store-settings')];
    }

    if (gs_plugin_active('sales-team/advanced-affiliate-system.php') && current_user_can('manage_options')) {
      $store_children[] = ['label' => __('Sales Team', 'gend-society'), 'url' => admin_url('admin.php?page=st_sales_team')];
    }
    if (gs_plugin_active('projects/project-service-orders.php') && current_user_can('manage_options')) {
      $store_children[] = ['label' => __('Projects', 'gend-society'), 'url' => admin_url('admin.php?page=psoo-projects')];
    }

    $items[] = [
      'label' => __('Store', 'gend-society'),
      'url' => admin_url('admin.php?page=gs-store'),
      'icon' => 'dashicons-store',
      'cap' => 'manage_options', // lower capability to show menu to managers even if WC is inactive
      'children' => $store_children,
    ];
  }

  // Conditionally add Social
  if (gs_plugin_active('social-network/social-network.php') && current_user_can('manage_options')) {
    $social_children = [
      ['label' => __('Social Profiles', 'gend-society'), 'url' => admin_url('admin.php?page=gdc-social-network-settings')],
    ];
    if (gs_plugin_active('reward-programs/reward-programs.php')) {
      $social_children[] = ['label' => __('Point Bank', 'gend-society'), 'url' => admin_url('admin.php?page=gs-rewards')];
    }
    if (gs_plugin_active('contracts-and-payments/contracts-and-payments.php')) {
      $social_children[] = ['label' => __('Contracts & Payments', 'gend-society'), 'url' => admin_url('admin.php?page=gend-contracts-payments')];
    }
    $items[] = [
      'label' => __('Social', 'gend-society'),
      'url' => admin_url('admin.php?page=gs-social'),
      'icon' => 'dashicons-share',
      'cap' => 'manage_options',
      'children' => $social_children,
    ];
  }

  // Note: Rewards is listed under Social children above, not as a top-level item.

  $items[] = [
    'label' => __('Features', 'gend-society'),
    'url' => admin_url('admin.php?page=gs-features'),
    'icon' => 'dashicons-admin-plugins',
    'cap' => 'activate_plugins',
    'children' => [
      ['label' => __('Shortcodes', 'gend-society'), 'url' => admin_url('admin.php?page=gs-shortcodes')],
      ['label' => __('Code Packages', 'gend-society'), 'url' => admin_url('plugins.php')],
      ['label' => __('Updates', 'gend-society'), 'url' => admin_url('update-core.php')],
    ],
  ];

  // Filter by capability and feature access
  $final_items = [];
  $current_user_id = get_current_user_id();
  $is_super = is_super_admin($current_user_id) || current_user_can('manage_network');
  $allowed_features = get_user_meta($current_user_id, 'gs_feature_access', true);
  if (!is_array($allowed_features)) {
    $allowed_features = [];
  }

  // Pre-process items: if the parent slug isn't strictly known, we might need a mapping.
  // We'll rely on the top-level slugs defined in admin-menu.php where possible.
  $slug_map = [
    'Dashboard' => 'index.php',
    'App' => 'gs-app',
    'Write' => 'gs-content',
    'Store' => 'gs-store',
    'Social' => 'gs-social',
    'Features' => 'gs-features'
  ];

  foreach ($items as $item) {
    if (!current_user_can($item['cap'])) {
      continue;
    }

    if (!$is_super) {
      $mapped_slug = isset($slug_map[$item['label']]) ? $slug_map[$item['label']] : '';
      if ($mapped_slug && !in_array($mapped_slug, $allowed_features, true)) {
        continue; // Top level menu not allowed
      }
    }

    // Filter children
    if (!empty($item['children']) && !$is_super) {
      $filtered_children = [];
      foreach ($item['children'] as $child) {
        // Extract the slug from the URL. e.g., admin.php?page=gdc-store -> gdc-store, or edit.php -> edit.php
        $parsed_url = wp_parse_url($child['url']);
        $child_slug = '';
        if (isset($parsed_url['query'])) {
          parse_str($parsed_url['query'], $query_args);
          if (isset($query_args['page'])) {
            $child_slug = $query_args['page'];
          } else if (strpos($parsed_url['path'], 'edit.php') !== false) {
            $child_slug = $query_args['post_type'] ? 'edit.php?post_type=' . $query_args['post_type'] : 'edit.php';
          }
        } else if (isset($parsed_url['path'])) {
          $child_slug = basename($parsed_url['path']);
        }

        if ($child_slug === 'profile.php' || in_array($child_slug, $allowed_features, true)) {
          $filtered_children[] = $child;
        }
      }
      $item['children'] = $filtered_children;
    }

    $final_items[] = $item;
  }

  return $final_items;
}

/**
 * Build the frontend sidebar nav for non-backend users when the social plugin
 * is active. Mirrors the member profile primary tabs and links each item to
 * the LOGGED-IN user's own profile section, so the sidebar acts as the user's
 * personal navigation regardless of which page they're on.
 *
 * A "Profile" item is prepended only when the viewer is on another member's
 * profile — hidden on their own profile (redundant) and on non-profile pages
 * (per the explicit "shown only when viewing other profiles" rule).
 */
function gs_build_frontend_profile_nav()
{
  $base = function_exists('bp_loggedin_user_domain') ? bp_loggedin_user_domain() : '';
  if (!$base) {
    return [];
  }
  $base = trailingslashit($base);

  $bp_active = function_exists('bp_is_active');

  $items = [];

  // Overview (Youzify default landing tab)
  $items[] = ['label' => __('Overview', 'gend-society'), 'url' => $base, 'icon' => 'dashicons-id'];

  // App Projects (BP Groups → renamed to App Projects)
  if ($bp_active && bp_is_active('groups')) {
    $items[] = ['label' => __('App Projects', 'gend-society'), 'url' => $base . 'groups/', 'icon' => 'dashicons-screenoptions'];
  }

  // Activity
  if ($bp_active && bp_is_active('activity')) {
    $items[] = ['label' => __('Activity', 'gend-society'), 'url' => $base . 'activity/', 'icon' => 'dashicons-megaphone'];
  }

  // Connections (BP Friends)
  if ($bp_active && bp_is_active('friends')) {
    $items[] = ['label' => __('Connections', 'gend-society'), 'url' => $base . 'friends/', 'icon' => 'dashicons-networking'];
  }

  // Invest (gend-society custom BP tab — slug 'invest', the wallet Fund content).
  $items[] = ['label' => __('Invest', 'gend-society'), 'url' => $base . 'invest/', 'icon' => 'dashicons-chart-line'];

  // Wallet (gend-society custom BP tab — registered with slug 'member-wallet'
  // in member-profile-pages.php via bp_core_new_nav_item, so URL is
  // /members/<user>/member-wallet/, NOT /wallet/ which 404s).
  $items[] = ['label' => __('Wallet', 'gend-society'), 'url' => $base . 'member-wallet/', 'icon' => 'dashicons-money-alt'];

  // Visitors (Youzify "Who Viewed My Profile" addon)
  $items[] = ['label' => __('Visitors', 'gend-society'), 'url' => $base . 'visitors/', 'icon' => 'dashicons-visibility'];

  // Messages
  if ($bp_active && bp_is_active('messages')) {
    $items[] = ['label' => __('Messages', 'gend-society'), 'url' => $base . 'messages/', 'icon' => 'dashicons-email'];
  }

  // Portfolio (Youzify Media tab — renamed to Portfolio).
  // Lives at /members/<user>/media/ (the Files subnav under Media defaults
  // to the Media landing page, which is the Portfolio tab).
  $items[] = ['label' => __('Portfolio', 'gend-society'), 'url' => $base . 'media/', 'icon' => 'dashicons-portfolio'];

  // Settings
  if ($bp_active && bp_is_active('settings')) {
    $items[] = ['label' => __('Settings', 'gend-society'), 'url' => $base . 'settings/', 'icon' => 'dashicons-admin-generic'];
  }

  // "Profile" — only visible when viewing another member's profile.
  if (function_exists('bp_is_user') && bp_is_user()
      && function_exists('bp_is_my_profile') && !bp_is_my_profile()) {
    array_unshift($items, [
      'label' => __('Profile', 'gend-society'),
      'url'   => $base,
      'icon'  => 'dashicons-admin-users',
    ]);
  }

  // Match the shape used by gs_build_frontend_nav (renderer expects 'children').
  foreach ($items as &$it) {
    $it['children'] = [];
  }
  unset($it);

  return $items;
}





// End of file


/**
 * Inject WooCommerce Mini-Cart into the Elementor header.
 *
 * The site uses Elementor's header-footer canvas template which completely
 * bypasses the block template system. This hook fires on every page (regardless
 * of template) and injects the mini-cart button + drawer into .nav-actions-right
 * via JavaScript.
 */
add_action( 'wp_footer', 'gs_inject_mini_cart', 20 );
function gs_inject_mini_cart() {
    // Skip on iframed embeds — the host page already renders one.
    if ( gs_is_embed_request() ) {
        return;
    }
    // Only run if WooCommerce is active
    if ( ! function_exists( 'WC' ) ) {
        return;
    }

    // Item count & total for the button label
    $cart        = WC()->cart;
    $count       = $cart ? $cart->get_cart_contents_count() : 0;
    $total       = $cart ? WC()->cart->get_cart_total() : '';
    $cart_url    = wc_get_cart_url();
    $badge_style = $count > 0 ? '' : ' style="display:none"';

    ?>
    <!-- GS Mini-Cart -->
    <div id="gs-mini-cart-overlay" class="gs-mini-cart-overlay" aria-hidden="true">
        <div class="gs-mini-cart-drawer" role="dialog" aria-label="<?php esc_attr_e( 'Shopping Cart', 'woocommerce' ); ?>">
            <div class="gs-mini-cart-header">
                <h2 class="gs-mini-cart-title"><?php esc_html_e( 'Your Cart', 'woocommerce' ); ?></h2>
                <button class="gs-mini-cart-close" id="gs-mini-cart-close-btn" aria-label="<?php esc_attr_e( 'Close cart', 'woocommerce' ); ?>">&#x2715;</button>
            </div>
            <div class="gs-mini-cart-body">
                <?php if ( $cart && ! $cart->is_empty() ) : ?>
                    <div class="woocommerce">
                        <?php woocommerce_mini_cart(); ?>
                    </div>
                <?php else : ?>
                    <div class="gs-mini-cart-empty">
                        <p><?php esc_html_e( 'Your cart is currently empty.', 'woocommerce' ); ?></p>
                        <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="btn-pilot"><?php esc_html_e( 'Browse Products', 'woocommerce' ); ?></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        // Build the cart button HTML
        var count = <?php echo (int) $count; ?>;
        var btn = document.createElement('a');
        btn.href = '#';
        btn.id = 'gs-mini-cart-btn';
        btn.className = 'gs-mini-cart-btn';
        btn.setAttribute('aria-label', 'Shopping cart, ' + count + ' items');
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>'
            + '<span id="gs-mini-cart-count" class="gs-mini-cart-count"' + (count > 0 ? '' : ' style="display:none"') + '>' + count + '</span>';

        // Inject the button into .nav-actions-right (Elementor header) or fallback positions
        var targets = [
            document.querySelector('.nav-actions-right'),
            document.querySelector('.header-anchor-wrap'),
            document.querySelector('header'),
        ];
        var inserted = false;
        for (var i = 0; i < targets.length; i++) {
            if (targets[i]) {
                targets[i].appendChild(btn);
                inserted = true;
                break;
            }
        }

        // Open/close logic
        var overlay = document.getElementById('gs-mini-cart-overlay');
        var closeBtn = document.getElementById('gs-mini-cart-close-btn');

        if (btn && overlay) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                overlay.classList.toggle('is-open');
                overlay.setAttribute('aria-hidden', overlay.classList.contains('is-open') ? 'false' : 'true');
            });
        }
        if (closeBtn && overlay) {
            closeBtn.addEventListener('click', function() {
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.classList.remove('is-open');
                    overlay.setAttribute('aria-hidden', 'true');
                }
            });
        }

        // Update cart count on WC AJAX events
        document.body.addEventListener('wc_fragments_refreshed', function() {
            var countEl = document.getElementById('gs-mini-cart-count');
            var cartCount = document.querySelector('.woocommerce-mini-cart__total .amount') ? 1 : 0;
            var items = document.querySelectorAll('.gs-mini-cart-body .mini_cart_item');
            var total = items.length;
            if (countEl) {
                if (total > 0) {
                    countEl.textContent = total;
                    countEl.style.display = '';
                } else {
                    countEl.style.display = 'none';
                }
            }
        });
    })();
    </script>
    <?php
}

/**
 * Enqueue mini-cart styles for the GS injected cart.
 */
add_action( 'wp_enqueue_scripts', 'gs_enqueue_mini_cart_styles' );
function gs_enqueue_mini_cart_styles() {
    if ( ! function_exists( 'WC' ) ) {
        return;
    }
    // Ensure WC fragment scripts are loaded (for AJAX cart updates)
    wp_enqueue_script( 'wc-cart-fragments' );
    wp_add_inline_style( 'gs-site-header-footer', '
/* GS Mini-Cart Button */
.gs-mini-cart-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #e8edf5 !important;
    text-decoration: none !important;
    padding: 6px 8px;
    border-radius: 8px;
    transition: background .2s, color .2s;
    vertical-align: middle;
}
.gs-mini-cart-btn:hover {
    background: rgba(182,8,201,.15);
    color: #fff !important;
}
.gs-mini-cart-count {
    position: absolute;
    top: -4px;
    right: -4px;
    background: var(--gs-magenta, #b608c9);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    border-radius: 50%;
    width: 17px;
    height: 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

/* GS Mini-Cart Overlay */
.gs-mini-cart-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: rgba(0,0,0,.55);
    opacity: 0;
    pointer-events: none;
    transition: opacity .25s ease;
}
.gs-mini-cart-overlay.is-open {
    opacity: 1;
    pointer-events: all;
}
.gs-mini-cart-drawer {
    position: absolute;
    top: 0;
    right: 0;
    width: 380px;
    max-width: 100vw;
    height: 100%;
    background: #0f1117;
    border-left: 1px solid rgba(255,255,255,.1);
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform .3s ease;
    overflow-y: auto;
}
.gs-mini-cart-overlay.is-open .gs-mini-cart-drawer {
    transform: translateX(0);
}
.gs-mini-cart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.gs-mini-cart-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #fff;
}
.gs-mini-cart-close {
    background: none;
    border: none;
    color: #8b98b0;
    font-size: 1.2rem;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: color .2s, background .2s;
}
.gs-mini-cart-close:hover { color: #fff; background: rgba(255,255,255,.08); }
.gs-mini-cart-body {
    flex: 1;
    padding: 20px 24px;
    overflow-y: auto;
}
.gs-mini-cart-empty {
    text-align: center;
    padding: 40px 0;
    color: #8b98b0;
}
.gs-mini-cart-empty p { margin-bottom: 20px; }
/* WC mini-cart colours inside dark drawer */
.gs-mini-cart-body .woocommerce { color: #e8edf5; }
.gs-mini-cart-body .woocommerce a { color: #97e46e !important; }
.gs-mini-cart-body .mini_cart_item { border-bottom: 1px solid rgba(255,255,255,.07); padding: 12px 0; }
.gs-mini-cart-body .woocommerce-mini-cart__total { padding: 16px 0; font-weight: 700; color: #fff; }
.gs-mini-cart-body .woocommerce-mini-cart__buttons a { display: block; text-align: center; padding: 12px; border-radius: 10px; margin-top: 8px; text-decoration: none !important; font-weight: 700; font-size: .85rem; text-transform: uppercase; letter-spacing: 1px; }
.gs-mini-cart-body .woocommerce-mini-cart__buttons .button.checkout { background: var(--gs-magenta, #b608c9); color: #fff !important; }
.gs-mini-cart-body .woocommerce-mini-cart__buttons .button { background: rgba(255,255,255,.08); color: #e8edf5 !important; }
    ');
}



