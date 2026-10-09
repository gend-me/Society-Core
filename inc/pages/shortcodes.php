<?php if (!defined('ABSPATH')) {
    exit;
}
// Shortcodes page — list all registered shortcodes with inline viewer
global $shortcode_tags;
// The "New Shortcode" PHP writer is container tier (gend.me-managed sites
// only); standalone installs get the read-only list.
$gend_society_sc_editor = function_exists('gend_society_mode_tiers')
    && function_exists('gend_society_runtime_mode')
    && in_array('container', gend_society_mode_tiers(gend_society_runtime_mode()), true)
    && file_exists(__DIR__ . '/shortcodes-editor.php');
?>
<div class="gs-page">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Shortcodes', 'gend-society'); ?>
            </span></h1>
        <?php if ($gend_society_sc_editor) : ?>
        <div class="gs-header-actions">
            <button type="button" class="gs-btn gs-btn-primary"
                onclick="document.getElementById('gs-new-shortcode-form').classList.toggle('gs-hidden')">
                <span class="dashicons dashicons-plus"></span>
                <?php esc_html_e('New Shortcode', 'gend-society'); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php
    if ($gend_society_sc_editor) {
        include __DIR__ . '/shortcodes-editor.php';
    } else {
        echo '<p class="gs-muted">' . esc_html__('To add custom shortcodes, use a code-snippets plugin or your child theme.', 'gend-society') . '</p>';
    }

    ksort($shortcode_tags);
    ?>

    <div class="gs-card">
        <div class="gs-card-header">
            <h3>
                <?php
                /* translators: %d: number of registered shortcodes. */
                printf(esc_html__('Registered Shortcodes (%d)', 'gend-society'), count($shortcode_tags));
                ?>
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