<?php
/**
 * Standalone Feature Cards Widget for GenD Society
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feature-card header image (gend.me-hosted, via the consent-gated table in
 * inc/remote-assets.php). '' when remote assets are not allowed; the card
 * then renders a neutral placeholder.
 */
function gend_society_feature_card_image($slug)
{
    return function_exists('gend_society_remote_asset_url') ? gend_society_remote_asset_url('feature_card_' . $slug) : '';
}

/**
 * Returns hardcoded feature definitions for the GenD Society dashboard.
 */
function gend_society_get_feature_definitions()
{
    return array(
        'wireframe' => array(
            'name' => __('Theme Builder', 'gend-society'),
            'description' => __('Generate high-fidelity UI maps and backend login flows instantly using LEO’s generation engine.', 'gend-society'),
            'plugin' => 'gend-society/gend-society.php',
            'image' => gend_society_feature_card_image('wireframe'),
            'link' => 'https://gend.me/wireframe-generation/'
        ),
        'blog' => array(
            'name' => __('Content Campaigns', 'gend-society'),
            'description' => __('Turn your content into a community hub with integrated social sharing and engagement tools.', 'gend-society'),
            'plugin' => 'blog-manager/blog-manager.php',
            'image' => gend_society_feature_card_image('blog'),
            'link' => 'https://gend.me/social-blogs/'
        ),
        'email' => array(
            'name' => __('Talk Flows', 'gend-society'),
            'description' => __('Automate high-touch communication and keep your users engaged with targeted community updates.', 'gend-society'),
            'plugin' => 'email-manager/email-manager.php',
            'image' => gend_society_feature_card_image('email'),
            'link' => 'https://gend.me/community-emails/'
        ),
        'store' => array(
            'name' => __('Store Management', 'gend-society'),
            'description' => __('Centralize your inventory, orders, and fulfillment in one intuitive dashboard.', 'gend-society'),
            'plugin' => 'online-store/online-store.php',
            'image' => gend_society_feature_card_image('store'),
            'link' => 'https://gend.me/store-management/'
        ),
        'sales' => array(
            'name' => __('Sales Team', 'gend-society'),
            'description' => __('Empower your sales team with real-time tracking, lead management, and performance analytics.', 'gend-society'),
            'plugin' => 'sales-team/advanced-affiliate-system.php',
            'image' => gend_society_feature_card_image('sales'),
            'link' => 'https://gend.me/sales-team/'
        ),
        'projects' => array(
            'name' => __('Project Services', 'gend-society'),
            'description' => __('Coordinate global teams and track deliverables with integrated project management for store owners.', 'gend-society'),
            'plugin' => 'projects/project-service-orders.php',
            'image' => gend_society_feature_card_image('projects'),
            'link' => 'https://gend.me/remote-projects/'
        ),
        'social' => array(
            'name' => __('Social Profiles', 'gend-society'),
            'description' => __('Allow users to create rich, customizable profiles that drive identity and connection.', 'gend-society'),
            'plugin' => 'social-network/social-network.php',
            'image' => gend_society_feature_card_image('social'),
            'link' => 'https://gend.me/social-profiles/'
        ),
        'membership' => array(
            'name' => __('Contracts & Payments', 'gend-society'),
            'description' => __('Total control over tiers, permissions, and access for your exclusive community.', 'gend-society'),
            'plugin' => 'contracts-and-payments/contracts-and-payments.php',
            'image' => gend_society_feature_card_image('membership'),
            'link' => 'https://gend.me/membership-management/'
        ),
        'rewards' => array(
            'name' => __('Point Bank', 'gend-society'),
            'description' => __('Incentivize loyalty and engagement with automated points, badges, and perks.', 'gend-society'),
            'plugin' => 'reward-programs/reward-programs.php',
            'image' => gend_society_feature_card_image('rewards'),
            'link' => 'https://gend.me/member-rewards/'
        )
    );
}

/**
 * Check if a specific plugin has an update available
 */
function gend_society_has_plugin_update($plugin_file)
{
    $current = get_site_transient('update_plugins');
    if (isset($current->response[$plugin_file])) {
        return true;
    }
    return false;
}

/**
 * Render the feature cards widget
 *
 * @param bool $show_sizes When true, each card shows that plugin's real
 *                          on-disk folder size (via the same real disk-walk
 *                          gs_hosting_collect_codebase_breakdown() already
 *                          does for the Codebase tab's File Breakdown table -
 *                          reused here instead of a second directory walk).
 *                          Defaults false so every OTHER caller of this
 *                          widget (front-end group tabs, etc.) is unaffected.
 */
function gend_society_render_feature_cards_widget($show_sizes = false)
{
    $features = gend_society_get_feature_definitions();

    $gs_fc_sizes = array();
    if ($show_sizes && function_exists('gend_society_hosting_collect_codebase_breakdown')) {
        foreach (gend_society_hosting_collect_codebase_breakdown() as $gs_fc_entry) {
            if (($gs_fc_entry['type'] ?? '') === 'plugin') {
                $gs_fc_sizes[$gs_fc_entry['name']] = (int) $gs_fc_entry['bytes'];
            }
        }
    }

    echo '<style>
        .gs-feature-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
        .gs-fc { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; transition: all 0.3s ease; position: relative; }
        .gs-fc:hover { border-color: rgba(255,255,255,0.15); transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .gs-fc-media { position: relative; }
        .gs-fc-img { width: 100%; height: 160px; object-fit: cover; object-position: top center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .gs-fc-media .gs-fc-update { position: absolute; bottom: 12px; right: 12px; margin: 0; z-index: 10; background: rgba(20,24,34,0.82); border-color: rgba(255,255,255,0.18); backdrop-filter: blur(10px); }
        .gs-fc-body { padding: 24px; flex: 1; display: flex; flex-direction: column; }
        .gs-fc-title { margin: 0 0 10px 0; color: #fff; font-size: 1.25rem; font-weight: 700; }
        .gs-fc-size { margin: -6px 0 10px 0; color: var(--gs-muted); font-size: 0.78rem; display: flex; align-items: center; gap: 5px; }
        .gs-fc-size .dashicons { font-size: 14px; width: 14px; height: 14px; }
        .gs-fc-desc { margin: 0 0 24px 0; color: var(--gs-muted); font-size: 0.9rem; line-height: 1.5; flex: 1; }
        .gs-fc-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; }
        .gs-fc-btn { flex: 1; text-align: center; justify-content: center; }
        .gs-fc-btn-icon { width: 36px; height: 36px; padding: 0; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .gs-fc-update { position: relative; }
        .gs-fc-badge { position: absolute; top: -5px; right: -5px; background: var(--gs-red); color: #fff; font-size: 10px; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .gs-fc-status { position: absolute; top: 16px; right: 16px; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 10; backdrop-filter: blur(10px); }
        .gs-fc-status.active { background: rgba(16, 185, 129, 0.8); color: #fff; box-shadow: 0 2px 10px rgba(16, 185, 129, 0.4); }
        .gs-fc-status.inactive { background: rgba(20, 24, 34, 0.8); color: var(--gs-muted); border: 1px solid rgba(255,255,255,0.1); }
    </style>';

    echo '<div class="gs-feature-grid">';

    foreach ($features as $slug => $feature) {

        // WP Ultimo has a special check since it is a mu-plugin or network managed usually
        if (isset($feature['is_ultimo']) && $feature['is_ultimo']) {
            $is_active = function_exists('WP_Ultimo');
        } else {
            $is_active = gend_society_plugin_active($feature['plugin']);
        }

        $has_update = gend_society_has_plugin_update($feature['plugin']);
        $nonce = wp_create_nonce('gs_plugin_actions');

        // Folder name (plugin_basename's dirname) matches the same top-level
        // entry name gs_hosting_collect_codebase_breakdown() keys its real
        // disk-walk results by - falls back to the plugin file itself for
        // the rare single-file-plugin case (no subdirectory).
        $gs_fc_folder = (strpos($feature['plugin'], '/') !== false) ? dirname($feature['plugin']) : $feature['plugin'];
        $gs_fc_bytes = $gs_fc_sizes[$gs_fc_folder] ?? null;

        echo '<div class="gs-fc">';

        // Status Badge overlay on image
        if ($is_active) {
            echo '<div class="gs-fc-status active">' . esc_html__('Active', 'gend-society') . '</div>';
        } else {
            echo '<div class="gs-fc-status inactive">' . esc_html__('Inactive', 'gend-society') . '</div>';
        }

        // Image Header
        echo '<div class="gs-fc-media">';
        if ('' !== $feature['image']) {
            echo '<img src="' . esc_url($feature['image']) . '" alt="' . esc_attr($feature['name']) . '" class="gs-fc-img" loading="lazy" />';
        } else {
            echo '<div class="gs-fc-img" role="img" aria-label="' . esc_attr($feature['name']) . '" style="' . esc_attr(function_exists('gend_society_remote_asset_placeholder_css') ? gend_society_remote_asset_placeholder_css() : '') . '"></div>';
        }

        // Update Button - overlaid bottom-right of the image/header section.
        if ($has_update) {
            echo '<button class="gs-btn gs-btn-secondary gs-fc-btn-icon gs-fc-update gs-ajax-action" data-action="update" data-plugin="' . esc_attr($feature['plugin']) . '" data-nonce="' . esc_attr($nonce) . '" title="Install Update">';
            echo '<span class="dashicons dashicons-update"></span>';
            echo '<span class="gs-fc-badge">!</span>';
            echo '</button>';
        } else {
            echo '<a href="' . esc_url(admin_url('update-core.php')) . '" class="gs-btn gs-btn-secondary gs-fc-btn-icon gs-fc-update" title="Check Updates">';
            echo '<span class="dashicons dashicons-update"></span>';
            echo '</a>';
        }

        echo '</div>'; // End Media

        // Body
        echo '<div class="gs-fc-body">';
        echo '<h3 class="gs-fc-title">' . esc_html($feature['name']) . '</h3>';
        if ($show_sizes) {
            echo '<div class="gs-fc-size"><span class="dashicons dashicons-editor-code"></span>' . esc_html($gs_fc_bytes !== null ? size_format($gs_fc_bytes, 2) : __('Size unavailable', 'gend-society')) . '</div>';
        }
        echo '<p class="gs-fc-desc">' . esc_html($feature['description']) . '</p>';

        // Actions
        echo '<div class="gs-fc-actions">';

        // Main Action Button (Manage or Activate)
        if ($is_active) {
            echo '<a href="' . esc_url(admin_url('plugins.php')) . '" class="gs-btn gs-btn-secondary gs-fc-btn">' . esc_html__('Manage Plugin', 'gend-society') . '</a>';
        } else {
            echo '<button class="gs-btn gs-fc-btn gs-ajax-action" data-action="activate" data-plugin="' . esc_attr($feature['plugin']) . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html__('Activate', 'gend-society') . '</button>';
        }

        // Info Button
        echo '<a href="' . esc_url($feature['link']) . '" target="_blank" class="gs-btn gs-btn-secondary gs-fc-btn-icon" title="More Info">';
        echo '<span class="dashicons dashicons-info"></span>';
        echo '</a>';

        echo '</div>'; // End Actions
        echo '</div>'; // End Body
        echo '</div>'; // End Card
    }

    echo '</div>'; // End Grid
}

/**
 * AJAX Handler: Activate Plugin
 */
add_action('wp_ajax_gend_society_activate_plugin', 'gend_society_ajax_activate_plugin');
function gend_society_ajax_activate_plugin() {
    check_ajax_referer('gs_plugin_actions', 'nonce');

    if (!current_user_can('activate_plugins')) {
        wp_send_json_error(array('message' => __('You do not have permission to activate plugins.', 'gend-society')));
    }

    $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
    if (empty($plugin)) {
        wp_send_json_error(array('message' => __('No plugin specified.', 'gend-society')));
    }

    $result = activate_plugin($plugin);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => __('Plugin activated successfully.', 'gend-society')));
}

/**
 * AJAX Handler: Update Plugin
 */
add_action('wp_ajax_gend_society_update_plugin', 'gend_society_ajax_update_plugin');
function gend_society_ajax_update_plugin() {
    check_ajax_referer('gs_plugin_actions', 'nonce');

    if (!current_user_can('update_plugins')) {
        wp_send_json_error(array('message' => __('You do not have permission to update plugins.', 'gend-society')));
    }

    $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
    if (empty($plugin)) {
        wp_send_json_error(array('message' => __('No plugin specified.', 'gend-society')));
    }

    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade($plugin);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    } elseif ($result === false) {
        wp_send_json_error(array('message' => __('Update failed.', 'gend-society')));
    }

    wp_send_json_success(array('message' => __('Plugin updated successfully.', 'gend-society')));
}

/**
 * AJAX Handler: Upload + Install Plugin(s) from ZIP file(s)
 *
 * Accepts one or more files under $_FILES['plugin_zip'] (a plain single
 * file, or an array-shaped upload from a multi-select <input multiple>
 * sharing name="plugin_zip[]") and installs each via the same real
 * Plugin_Upgrader class gs_ajax_update_plugin() above already uses - just
 * install() (fresh ZIP) instead of upgrade() (existing plugin slug).
 * Every file is attempted independently; the response reports a per-file
 * result so one bad ZIP in a multi-file batch doesn't block the rest.
 */
add_action('wp_ajax_gend_society_upload_plugin', 'gend_society_ajax_upload_plugin');
function gend_society_ajax_upload_plugin() {
    check_ajax_referer('gs_plugin_actions', 'nonce');

    if (!current_user_can('install_plugins')) {
        wp_send_json_error(array('message' => __('You do not have permission to install plugins.', 'gend-society')));
    }

    if (empty($_FILES['plugin_zip']) || empty($_FILES['plugin_zip']['name'])) {
        wp_send_json_error(array('message' => __('No file uploaded.', 'gend-society')));
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    // Normalize $_FILES['plugin_zip'] to a flat list of single-file arrays,
    // whether the browser sent one file or several under the same field name.
    $files = array();
    if (is_array($_FILES['plugin_zip']['name'])) {
        $count = count($_FILES['plugin_zip']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['plugin_zip']['name'][$i] === '') {
                continue;
            }
            $files[] = array(
                'name'     => $_FILES['plugin_zip']['name'][$i],
                'type'     => $_FILES['plugin_zip']['type'][$i],
                'tmp_name' => $_FILES['plugin_zip']['tmp_name'][$i],
                'error'    => $_FILES['plugin_zip']['error'][$i],
                'size'     => $_FILES['plugin_zip']['size'][$i],
            );
        }
    } else {
        $files[] = $_FILES['plugin_zip'];
    }

    $results = array();
    foreach ($files as $file) {
        $name = sanitize_file_name($file['name']);

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $results[] = array('name' => $name, 'success' => false, 'message' => __('Upload error.', 'gend-society'));
            continue;
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            $results[] = array('name' => $name, 'success' => false, 'message' => __('Only .zip files are allowed.', 'gend-society'));
            continue;
        }

        $uploaded = wp_handle_upload($file, array(
            'test_form' => false,
            'test_type' => true,
            'mimes'     => array('zip' => 'application/zip'),
        ));

        if (!empty($uploaded['error'])) {
            $results[] = array('name' => $name, 'success' => false, 'message' => $uploaded['error']);
            continue;
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $install_result = $upgrader->install($uploaded['file']);

        // wp_handle_upload() moves the ZIP into uploads/; install() reads it
        // from there but doesn't clean it up itself.
        if (file_exists($uploaded['file'])) {
            wp_delete_file($uploaded['file']);
        }

        if (is_wp_error($install_result)) {
            $results[] = array('name' => $name, 'success' => false, 'message' => $install_result->get_error_message());
        } elseif ($install_result === false) {
            $results[] = array('name' => $name, 'success' => false, 'message' => __('Install failed.', 'gend-society'));
        } else {
            $results[] = array('name' => $name, 'success' => true, 'message' => __('Installed successfully.', 'gend-society'));
        }
    }

    wp_send_json_success(array('results' => $results));
}

/**
 * Enqueue JavaScript for AJAX plugin actions
 */
add_action('admin_footer', 'gend_society_feature_cards_ajax_script');
function gend_society_feature_cards_ajax_script() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.gs-ajax-action').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var action = $btn.data('action'); // 'activate' or 'update'
            var plugin = $btn.data('plugin');
            var nonce = $btn.data('nonce');
            var originalHtml = $btn.html();

            if ($btn.hasClass('updating') || $btn.hasClass('activating')) return;

            if (action === 'activate') {
                $btn.addClass('activating').html('<?php esc_html_e('Activating...', 'gend-society'); ?>');
            } else if (action === 'update') {
                $btn.addClass('updating').html('<span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span>');
            }

            var ajaxAction = action === 'activate' ? 'gend_society_activate_plugin' : 'gend_society_update_plugin';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: ajaxAction,
                    plugin: plugin,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php esc_html_e('An error occurred.', 'gend-society'); ?>');
                        $btn.removeClass('activating updating').html(originalHtml);
                    }
                },
                error: function() {
                    alert('<?php esc_html_e('An error occurred during the request.', 'gend-society'); ?>');
                    $btn.removeClass('activating updating').html(originalHtml);
                }
            });
        });

        // Upload Plugin popup (multi-file .zip select + real install via
        // gs_upload_plugin AJAX action above).
        $(document).on('click', '[data-gs-upload-plugin-open]', function(e) {
            e.preventDefault();
            var modal = document.getElementById('gs-upload-plugin-modal');
            // Escape any ancestor's transform/filter/backdrop-filter containing
            // block (e.g. .gs-mship-card's backdrop-filter: blur()) so this
            // position:fixed modal centers on the real viewport instead of
            // that ancestor's content box - same fix the real Upgrade popup
            // (.gs-upgrade-modal) already applies for the same reason.
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        });
        $(document).on('click', '[data-gs-upload-plugin-close]', function(e) {
            e.preventDefault();
            document.getElementById('gs-upload-plugin-modal').classList.remove('is-open');
            document.body.style.overflow = '';
        });
        $(document).on('change', '#gs-upload-plugin-input', function() {
            var files = this.files;
            var $list = $('#gs-upload-plugin-filelist');
            $list.empty();
            if (!files.length) {
                $list.text('<?php esc_html_e('No files selected.', 'gend-society'); ?>');
                return;
            }
            for (var i = 0; i < files.length; i++) {
                $('<div>').text(files[i].name).appendTo($list);
            }
        });
        $(document).on('submit', '#gs-upload-plugin-form', function(e) {
            e.preventDefault();
            var $form = $(this);
            var input = document.getElementById('gs-upload-plugin-input');
            if (!input.files.length) {
                alert('<?php esc_html_e('Choose at least one .zip file first.', 'gend-society'); ?>');
                return;
            }
            var fd = new FormData();
            fd.append('action', 'gend_society_upload_plugin');
            fd.append('nonce', $form.data('nonce'));
            for (var i = 0; i < input.files.length; i++) {
                fd.append('plugin_zip[]', input.files[i]);
            }
            var $btn = $form.find('[type="submit"]');
            var origText = $btn.text();
            $btn.prop('disabled', true).text('<?php esc_html_e('Installing…', 'gend-society'); ?>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function(response) {
                    $btn.prop('disabled', false).text(origText);
                    if (response.success && response.data && response.data.results) {
                        var lines = response.data.results.map(function(r) {
                            return (r.success ? '✓ ' : '✗ ') + r.name + ' — ' + r.message;
                        });
                        alert(lines.join('\n'));
                        location.reload();
                    } else {
                        alert((response.data && response.data.message) || '<?php esc_html_e('An error occurred.', 'gend-society'); ?>');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text(origText);
                    alert('<?php esc_html_e('An error occurred during the request.', 'gend-society'); ?>');
                }
            });
        });
    });
    </script>
    <style>
    @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
    <?php
}
