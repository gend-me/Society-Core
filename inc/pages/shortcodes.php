<?php if (!defined('ABSPATH')) {
    exit;
}
// Shortcodes page — list all registered shortcodes with inline viewer
global $shortcode_tags;
?>
<div class="gs-page">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Shortcodes', 'gend-society'); ?>
            </span></h1>
        <div class="gs-header-actions">
            <button type="button" class="gs-btn gs-btn-primary"
                onclick="document.getElementById('gs-new-shortcode-form').classList.toggle('gs-hidden')">
                <span class="dashicons dashicons-plus"></span>
                <?php esc_html_e('New Shortcode', 'gend-society'); ?>
            </button>
        </div>
    </div>

    <!-- New Shortcode Form -->
    <div class="gs-card gs-hidden" id="gs-new-shortcode-form" style="margin-bottom:24px;">
        <div class="gs-card-header">
            <h3>
                <?php esc_html_e('Create New Shortcode', 'gend-society'); ?>
            </h3>
        </div>
        <div class="gs-card-body">
            <form method="post" action="">
                <?php wp_nonce_field('gs_save_shortcode', 'gs_sc_nonce'); ?>
                <div class="gs-form-row">
                    <label for="gs-sc-name">
                        <?php esc_html_e('Tag (e.g. my_shortcode)', 'gend-society'); ?>
                    </label>
                    <input type="text" id="gs-sc-name" name="gs_sc_name" class="gs-input" placeholder="my_shortcode"
                        pattern="[a-z0-9_\-]+" required>
                </div>
                <div class="gs-form-row">
                    <label for="gs-sc-code">
                        <?php esc_html_e('PHP Code (function body)', 'gend-society'); ?>
                    </label>
                    <textarea id="gs-sc-code" name="gs_sc_code" class="gs-textarea gs-code" rows="8"
                        placeholder="// $atts are your shortcode attributes&#10;ob_start();&#10;// your output here&#10;return ob_get_clean();">
</textarea>
                </div>
                <button type="submit" name="gs_save_sc" class="gs-btn gs-btn-primary">
                    <?php esc_html_e('Save Shortcode', 'gend-society'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php
    // Handle new shortcode save
    if (isset($_POST['gs_save_sc']) && check_admin_referer('gs_save_shortcode', 'gs_sc_nonce') && current_user_can('activate_plugins')) {
        $tag = preg_replace('/[^a-z0-9_\-]/', '', strtolower(sanitize_key(wp_unslash($_POST['gs_sc_name'] ?? ''))));
        $code = wp_unslash($_POST['gs_sc_code'] ?? '');
        if ($tag && $code) {
            $mu_file = WP_CONTENT_DIR . '/mu-plugins/gs-shortcodes.php';
            $existing = file_exists($mu_file) ? file_get_contents($mu_file) : "<?php\n// GenD Society Custom Shortcodes\n";
            $snippet = "\n\n// Shortcode: [{$tag}]\nadd_shortcode( '{$tag}', function( \$atts, \$content = '' ) {\n{$code}\n} );\n";
            file_put_contents($mu_file, $existing . $snippet);
            echo '<div class="notice notice-success"><p>' . esc_html__('Shortcode saved!', 'gend-society') . '</p></div>';
        }
    }

    ksort($shortcode_tags);
    ?>

    <div class="gs-card">
        <div class="gs-card-header">
            <h3>
                <?php printf(esc_html__('Registered Shortcodes (%d)', 'gend-society'), count($shortcode_tags)); ?>
            </h3>
            <input type="text" class="gs-input gs-search-input"
                placeholder="<?php esc_attr_e('Search shortcodes…', 'gend-society'); ?>"
                oninput="gsFilterShortcodes(this.value)">
        </div>
        <div class="gs-card-body" style="padding:0;">
            <table class="gs-table" id="gs-sc-table">
                <thead>
                    <tr>
                        <th>
                            <?php esc_html_e('Tag', 'gend-society'); ?>
                        </th>
                        <th>
                            <?php esc_html_e('Handler', 'gend-society'); ?>
                        </th>
                        <th>
                            <?php esc_html_e('Usage', 'gend-society'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shortcode_tags as $tag => $handler):
                        if (is_array($handler)) {
                            $cls = is_object($handler[0]) ? get_class($handler[0]) : (is_string($handler[0]) ? $handler[0] : '(object)');
                            $label = $cls . '::' . $handler[1];
                        } else {
                            $label = is_string($handler) ? $handler : '(closure)';
                        }
                        ?>
                        <tr class="gs-sc-row">
                            <td><code class="gs-code-pill">[<?php echo esc_html($tag); ?>]</code></td>
                            <td><code class="gs-muted"><?php echo esc_html($label); ?></code></td>
                            <td><button type="button" class="gs-btn gs-btn-xs gs-copy-btn"
                                    data-copy="[<?php echo esc_attr($tag); ?>]"><span
                                        class="dashicons dashicons-clipboard"></span>
                                    <?php esc_html_e('Copy', 'gend-society'); ?>
                                </button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    function gsFilterShortcodes(q) { var rows = document.querySelectorAll('#gs-sc-table .gs-sc-row'); rows.forEach(function (r) { r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none'; }); }
    document.querySelectorAll('.gs-copy-btn').forEach(function (b) { b.addEventListener('click', function () { navigator.clipboard.writeText(this.dataset.copy); this.textContent = 'Copied!'; setTimeout(() => { this.innerHTML = '<span class=\"dashicons dashicons-clipboard\"></span> Copy'; }, 1500); }); });
</script>