<?php
if (!defined('ABSPATH')) {
  exit;
}

// Enqueue frontend assets for logged-in users with at least 'edit_posts' cap
add_action('wp_enqueue_scripts', 'gs_enqueue_frontend_assets');
function gs_enqueue_frontend_assets()
{
  if (!is_user_logged_in() || !current_user_can('edit_posts')) {
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

// Enqueue animation utilities for ALL visitors (not just admins)
add_action('wp_enqueue_scripts', 'gs_enqueue_animation_css');
function gs_enqueue_animation_css()
{
  wp_enqueue_style('gs-animation-utilities', GS_URL . 'assets/animation-utilities.css', [], GS_VERSION . '.' . filemtime(GS_DIR . 'assets/animation-utilities.css'));
}

// Localize data for the admin script
add_action('admin_enqueue_scripts', 'gs_localize_admin_data');
function gs_localize_admin_data()
{
  $user = wp_get_current_user();
  wp_add_inline_script(
    'gs-admin-script',
    'window.gsAdminData=' . wp_json_encode([
      'userName' => $user->display_name,
      'adminUrl' => admin_url(),
      'profileUrl' => admin_url('profile.php'),
      'logoutUrl' => wp_logout_url(home_url()),
      'siteUrl' => home_url(),
    ]) . ';',
    'before'
  );
}

// Inject the sidebar into wp_footer for logged-in users
add_action('wp_footer', 'gs_render_frontend_bar', 5);
function gs_render_frontend_bar()
{
  if (!is_user_logged_in() || !current_user_can('edit_posts')) {
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

  // Build the nav items mirroring admin menu
  $nav_items = gs_build_frontend_nav();
  ?>
  <div class="gs-front-sidebar" role="navigation" aria-label="<?php esc_attr_e('Admin Sidebar', 'gend-society'); ?>">

    <!-- Brand -->
    <a href="<?php echo esc_url(admin_url('index.php')); ?>" class="gs-sidebar-brand">
      <div class="gs-sidebar-brand-icon">G</div>
      <span class="gs-sidebar-brand-label">GenD Society</span>
    </a>

    <div class="gs-sidebar-divider"></div>

    <!-- Edit Action -->
    <?php if ($edit_url):
      $workflow = 'content_writer';
      if ($post_type === 'product') {
        $workflow = 'content_writer';
      } elseif ($post_type === 'page' || $post_type === 'post') {
        $workflow = 'blog_architect';
      }
      ?>
      <div class="gs-sidebar-actions">
        <a href="#" class="gs-sidebar-action-btn gs-open-template-modal" data-page-id="<?php echo esc_attr($post_id); ?>"
          data-page-title="<?php echo esc_attr(get_the_title($post_id)); ?>"
          data-edit-url="<?php echo esc_url($edit_url); ?>" data-post-type="<?php echo esc_attr($post_type); ?>"
          data-workflow="<?php echo esc_attr($workflow); ?>"
          aria-label="<?php esc_attr_e('Edit this page', 'gend-society'); ?>">
          <span class="gs-sidebar-icon dashicons dashicons-edit"></span>
          <span class="gs-sidebar-label"><?php esc_html_e('Edit Page', 'gend-society'); ?></span>
        </a>
      </div>
      <div class="gs-sidebar-divider"></div>
    <?php endif; ?>

    <!-- Nav -->
    <nav class="gs-sidebar-nav">
      <?php foreach ($nav_items as $item):
        $has_sub = !empty($item['children']);
        $target = !empty($item['external']) ? ' target="_blank" rel="noopener"' : '';
        ?>
        <div class="gs-sidebar-item<?php echo $has_sub ? '" data-has-sub="1' : ''; ?>">
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

    <!-- Footer / User -->
    <div class="gs-sidebar-footer">
      <a href="<?php echo esc_url(admin_url('profile.php')); ?>" class="gs-sidebar-user">
        <div class="gs-sidebar-avatar">
          <?php if ($avatar): ?>
            <img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
          <?php else: ?>
            <?php echo esc_html($avatar_init); ?>
          <?php endif; ?>
        </div>
        <div class="gs-sidebar-user-info">
          <span class="gs-sidebar-username"><?php echo esc_html($user->display_name); ?></span>
          <span class="gs-sidebar-role"><?php echo esc_html($role); ?></span>
        </div>
      </a>
    </div>
  </div>
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

  $users_children = [
    ['label' => __('All Users', 'gend-society'), 'url' => admin_url('users.php')]
  ];
  if (current_user_can('create_users')) {
    $users_children[] = ['label' => __('Add New', 'gend-society'), 'url' => admin_url('user-new.php')];
  }
  $users_children[] = ['label' => __('Feature Access', 'gend-society'), 'url' => admin_url('admin.php?page=gs-feature-access')];
  $users_children[] = ['label' => __('Your Profile', 'gend-society'), 'url' => admin_url('profile.php')];

  $items[] = [
    'label' => __('Users', 'gend-society'),
    'url' => admin_url('admin.php?page=gs-users'),
    'icon' => 'dashicons-groups',
    'cap' => 'list_users',
    'children' => $users_children,
  ];

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
    $content_children[] = ['label' => __('Email Manager', 'gend-society'), 'url' => admin_url('admin.php?page=email-manager')];
  }

  $items[] = [
    'label' => __('Content', 'gend-society'),
    'url' => admin_url('admin.php?page=gs-content'),
    'icon' => 'dashicons-edit',
    'cap' => 'manage_options',
    'children' => $content_children,
  ];

  // Conditionally add Store
  $has_store_apps = gs_plugin_active('online-store/online-store.php') || gs_plugin_active('sales-team/sales-team.php') || gs_plugin_active('projects/projects.php');
  if ($has_store_apps) {
    $store_children = [];

    // Add Online Store submenus if active
    if (gs_plugin_active('online-store/online-store.php') && current_user_can('manage_woocommerce')) {
      $store_children[] = ['label' => __('Store Settings', 'gend-society'), 'url' => admin_url('admin.php?page=gdc-store-settings')];
    }

    if (gs_plugin_active('sales-team/sales-team.php') && current_user_can('manage_options')) {
      $store_children[] = ['label' => __('Sales Team', 'gend-society'), 'url' => admin_url('admin.php?page=st_sales_team')];
    }
    if (gs_plugin_active('projects/projects.php') && current_user_can('manage_options')) {
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
      ['label' => __('Network Settings', 'gend-society'), 'url' => admin_url('admin.php?page=gdc-social-network-settings')],
      ['label' => __('Membership System', 'gend-society'), 'url' => admin_url('admin.php?page=gdc-social-membership-system')],
    ];
    if (gs_plugin_active('reward-programs/reward-programs.php')) {
      $social_children[] = ['label' => __('Rewards', 'gend-society'), 'url' => admin_url('admin.php?page=gs-rewards')];
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
    'Users' => 'gs-users',
    'App' => 'gs-app',
    'Content' => 'gs-content',
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
