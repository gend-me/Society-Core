<?php
/**
 * Dashboard "App Management" sections — Domain + Plan upgrade/migrate.
 *
 * Renders directly under the membership/site-logo card on the gend-society
 * Dashboard page. Designed to work in BOTH contexts:
 *
 *   • On gend.me (network / WP Ultimo): the membership object is real,
 *     so we can list mapped domains + offer "Migrate Hosting Type" and
 *     "Manage Domains" actions deep-linking into Vendor App Manager UIs.
 *
 *   • On a standalone customer container site (no WP Ultimo): there is
 *     no $membership locally — we show the current public host and a
 *     "Manage on gend.me" button that opens the membership management
 *     page on the network so the customer can act there.
 *
 * Output uses the same `gs-admin-card` / `gs-btn` classes the existing
 * overview uses so the visual language stays consistent.
 *
 * @package GenD_Society
 * @since   1.x
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('gs_get_app_management_html')) {

    /**
     * Render the App Management block (Domain + Plan upgrade/migrate).
     *
     * @param mixed $membership WP Ultimo Membership object, or null when
     *                          this dashboard is rendering on a standalone
     *                          container site.
     * @return string
     */
    function gs_get_app_management_html($membership = null) {

        // Resolve the network home URL — used for any deep link back to
        // gend.me even when we're rendering on a container site.
        $network_home = '';
        if (function_exists('wu_get_main_site_id') && function_exists('get_blog_option')) {
            $main_id = (int) wu_get_main_site_id();
            $network_home = (string) get_blog_option($main_id, 'home');
        }
        if ($network_home === '' && function_exists('network_home_url')) {
            $network_home = (string) network_home_url('/');
        }
        if ($network_home === '') {
            $network_home = (string) home_url('/');
        }
        $network_home = trailingslashit($network_home);

        // Pick the customer's container site (where applicable). We need
        // its site_id to deep-link to its Site Edit page on gend.me.
        $container_site_id = 0;
        $container_site    = null;
        $is_container_site = false;
        if ($membership && method_exists($membership, 'get_sites')) {
            $sites = (array) $membership->get_sites();
            foreach ($sites as $s) {
                if (method_exists($s, 'get_meta') && (int) $s->get_meta('gdc_container', 0) === 1) {
                    $container_site    = $s;
                    $container_site_id = method_exists($s, 'get_id') ? (int) $s->get_id() : 0;
                    break;
                }
            }
        }
        if (!$container_site && function_exists('get_option')) {
            // On a customer container itself the option-level marker is set
            // by the migration mu-plugin so we know we're in container mode.
            $is_container_site = (bool) get_option('gdc_container_local_marker', false)
                || (defined('GDC_CONTAINER_INSTALL_ID') && GDC_CONTAINER_INSTALL_ID);
        }

        // Resolve the current public hostname for the "this site" panel.
        $public_host = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $public_host = strtolower((string) $_SERVER['HTTP_HOST']);
            if (strpos($public_host, ':') !== false) {
                $public_host = explode(':', $public_host, 2)[0];
            }
        }
        if ($public_host === '') {
            $public_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        }

        // Mapped domains: if WP Ultimo's wu_get_domains() exists AND we
        // know the blog_id, list them. Otherwise just show the current
        // public host as the only known mapping.
        $domain_rows = array();
        if ($container_site && method_exists($container_site, 'get_blog_id') && function_exists('wu_get_domains')) {
            $bid = (int) $container_site->get_blog_id();
            if ($bid > 0) {
                $found = (array) wu_get_domains(array('blog_id' => $bid, 'number' => 20));
                foreach ($found as $d) {
                    if (!is_object($d) || !method_exists($d, 'get_domain')) continue;
                    $domain_rows[] = array(
                        'host'    => (string) $d->get_domain(),
                        'stage'   => method_exists($d, 'get_stage') ? (string) $d->get_stage() : '',
                        'primary' => method_exists($d, 'is_primary_domain') && $d->is_primary_domain(),
                        'secure'  => method_exists($d, 'is_secure') && $d->is_secure(),
                    );
                }
            }
        }

        // Build deep-link URLs.
        $manage_domains_url = '';
        $manage_plan_url    = '';
        $migrate_url        = '';
        if ($container_site_id > 0) {
            // Network admin Site Edit anchors for the section IDs we
            // surface in vendor-app-manager.
            $manage_domains_url = network_admin_url('admin.php?page=wp-ultimo-edit-site&id=' . $container_site_id . '#gdc_container_domains');
            $migrate_url        = network_admin_url('admin.php?page=wp-ultimo-edit-site&id=' . $container_site_id . '#gdc_migration');
        }
        if ($membership && method_exists($membership, 'get_id')) {
            $manage_plan_url = $network_home . 'my-account/membership/' . (int) $membership->get_id() . '/';
        } elseif ($membership === null) {
            $manage_plan_url = $network_home . 'my-account/memberships/';
        }

        ob_start();
        ?>

        <div class="gs-admin-grid" style="margin-top: 32px;">

            <!-- ── Domain card ─────────────────────────────────────────── -->
            <div class="gs-admin-card" style="padding: 24px;">

                <h2 style="margin-top:0; font-size: 1.25rem;">
                    <?php esc_html_e('Domain', 'gend-society'); ?>
                </h2>

                <div style="margin: 12px 0 16px; padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                    <div style="font-size: 0.85rem; color: var(--gs-muted); margin-bottom: 4px;">
                        <?php esc_html_e('Currently serving', 'gend-society'); ?>
                    </div>
                    <a href="<?php echo esc_url('https://' . $public_host . '/'); ?>" target="_blank" rel="noopener"
                       style="font-size: 1.05rem; color: #fff; font-weight: 600; word-break: break-all;">
                        <?php echo esc_html($public_host); ?>
                    </a>
                </div>

                <?php if (!empty($domain_rows)) : ?>
                    <h3 style="margin: 16px 0 8px; font-size: 0.95rem; color: var(--gs-muted);">
                        <?php esc_html_e('Mapped domains', 'gend-society'); ?>
                    </h3>
                    <ul style="list-style: none; margin: 0; padding: 0;">
                        <?php foreach ($domain_rows as $row) : ?>
                            <li style="padding: 8px 12px; margin-bottom: 6px; background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px solid rgba(255,255,255,0.04); display: flex; justify-content: space-between; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <code style="background: transparent; color: #fff; font-size: 0.9rem;"><?php echo esc_html($row['host']); ?></code>
                                <span style="font-size: 0.75rem; opacity: 0.7;">
                                    <?php
                                    echo $row['primary'] ? esc_html__('Primary', 'gend-society') . ' · ' : '';
                                    echo $row['secure']  ? esc_html__('Secure', 'gend-society')   . ' · ' : '';
                                    echo esc_html($row['stage']);
                                    ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div style="text-align: right; margin-top: 16px;">
                    <?php if ($manage_domains_url !== '') : ?>
                        <a class="gs-btn" href="<?php echo esc_url($manage_domains_url); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Manage Domains', 'gend-society'); ?>
                        </a>
                    <?php else : ?>
                        <a class="gs-btn" href="<?php echo esc_url($network_home . 'my-account/memberships/'); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Manage on gend.me', 'gend-society'); ?>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── Plan management card ────────────────────────────────── -->
            <div class="gs-admin-card" style="padding: 24px;">

                <h2 style="margin-top:0; font-size: 1.25rem;">
                    <?php esc_html_e('Plan Management', 'gend-society'); ?>
                </h2>

                <p style="margin: 8px 0 16px; font-size: 0.9rem; color: var(--gs-muted);">
                    <?php esc_html_e('Upgrade or downgrade your membership and switch hosting types (Networked → Server → Containers → Self-Hosted) without losing your content.', 'gend-society'); ?>
                </p>

                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php if ($manage_plan_url !== '') : ?>
                        <a class="gs-btn" href="<?php echo esc_url($manage_plan_url); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Change Plan / Upgrade', 'gend-society'); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($migrate_url !== '') : ?>
                        <a class="gs-btn" href="<?php echo esc_url($migrate_url); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Migrate Hosting Type', 'gend-society'); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($manage_plan_url === '' && $migrate_url === '') : ?>
                        <a class="gs-btn" href="<?php echo esc_url($network_home . 'my-account/memberships/'); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Manage on gend.me', 'gend-society'); ?>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

        </div>

        <?php
        return (string) ob_get_clean();
    }
}
