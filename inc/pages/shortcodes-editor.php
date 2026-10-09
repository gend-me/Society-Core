<?php
/**
 * Shortcodes page: "New Shortcode" form + its save handler (container tier).
 *
 * Writes the submitted PHP body into wp-content/mu-plugins/gs-shortcodes.php.
 * Only shipped to gend.me-managed sites (hub + containers); included by
 * inc/pages/shortcodes.php inside its render scope. Not in the
 * wordpress.org build.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
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
        $gend_society_tag = preg_replace('/[^a-z0-9_\-]/', '', strtolower(sanitize_key(wp_unslash($_POST['gs_sc_name'] ?? ''))));
        $gend_society_code = wp_unslash($_POST['gs_sc_code'] ?? '');
        if ($gend_society_tag && $gend_society_code) {
            $gend_society_mu_file = WP_CONTENT_DIR . '/mu-plugins/gs-shortcodes.php';
            $gend_society_existing = file_exists($gend_society_mu_file) ? file_get_contents($gend_society_mu_file) : "<?php\n// GenD Society Custom Shortcodes\n";
            $gend_society_snippet = "\n\n// Shortcode: [{$gend_society_tag}]\nadd_shortcode( '{$gend_society_tag}', function( \$atts, \$content = '' ) {\n{$gend_society_code}\n} );\n";
            file_put_contents($gend_society_mu_file, $gend_society_existing . $gend_society_snippet);
            echo '<div class="notice notice-success"><p>' . esc_html__('Shortcode saved!', 'gend-society') . '</p></div>';
        }
    }
    ?>
