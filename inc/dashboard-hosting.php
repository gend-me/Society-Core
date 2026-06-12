<?php
/**
 * Hosting tab for the gend.me membership card on /wp-admin (index.php).
 *
 * Renders an integrated hosting console (Dashboard / Domains / Compute Gas /
 * Logs / Tables / Media) inside the membership card's tab strip. Each
 * sub-section has a sidebar nav button on the left and a content panel on
 * the right; switching is client-side, with most data loaded on demand via
 * AJAX so the dashboard render stays fast.
 *
 * Server-side actions go to gend.me's install REST surface
 * (/wp-json/gdc-app-manager/v1/install/{id}/hosting/*) via the existing
 * gs_remote_membership_call() proxy. The container side of those routes
 * may not exist yet — the AJAX endpoints below report the WP_Error from
 * the hub verbatim so the UI can surface "Not yet enabled" while still
 * having the wiring in place for when those routes land.
 *
 * Local-fallback data (table stats, media usage, error log tail) is read
 * directly so the panels are useful immediately even before the hub side
 * ships.
 */

if (!defined('ABSPATH')) {
    exit;
}

// -------------------------------------------------------------------------
// Renderer
// -------------------------------------------------------------------------

/**
 * Render the Hosting tab content (sidebar + 6 sub-panels). Called from the
 * Hosting tab panel in gs_render_membership_panel().
 *
 * @param array $payload Membership payload (passed through for domains/billing).
 */
function gs_render_hosting_tab( $payload = array() ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<p style="color: var(--gs-muted);">' . esc_html__( 'You do not have permission to manage hosting.', 'gend-society' ) . '</p>';
        return;
    }

    global $wpdb;
    $domains = isset( $payload['domains'] ) && is_array( $payload['domains'] ) ? $payload['domains'] : array();
    $billing = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
    $backups = isset( $payload['backups'] ) && is_array( $payload['backups'] ) ? $payload['backups'] : array();

    // Build initial server-rendered data for the panels that can be filled
    // synchronously (Tables, Media). Logs / Compute Gas / Dashboard toggles
    // fetch via AJAX on first activation to keep the index.php render snappy.
    $tables_data = gs_hosting_collect_tables();
    $media_data  = gs_hosting_collect_media();

    $hosting_assets_url = plugin_dir_url( __FILE__ );

    // Schema hint for the AI translator — the first 60 table names from
    // SHOW TABLE STATUS, exposed as window.gsHostingTableNames so the
    // "Find It" prompt can ground the model on real table names instead
    // of generic WP defaults.
    $gs_hosting_table_names = array();
    if ( ! empty( $tables_data['tables'] ) && is_array( $tables_data['tables'] ) ) {
        foreach ( $tables_data['tables'] as $t ) {
            if ( ! empty( $t['name'] ) ) {
                $gs_hosting_table_names[] = $t['name'];
            }
            if ( count( $gs_hosting_table_names ) >= 60 ) break;
        }
    }
    ?>
    <script>window.gsHostingTableNames = <?php echo wp_json_encode( $gs_hosting_table_names ); ?>;</script>
    <div class="gs-hosting" id="gs-hosting-root">
        <style>
            .gs-hosting { display: grid; grid-template-columns: 220px 1fr; gap: 24px; min-height: 480px; }
            .gs-hosting__sidebar { display: flex; flex-direction: column; gap: 4px; padding-right: 16px; border-right: 1px solid rgba(255,255,255,0.08); }
            .gs-hosting__nav { background: transparent; border: 0; padding: 10px 14px; text-align: left; color: var(--gs-muted, #94a3b8); border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 10px; }
            .gs-hosting__nav:hover { background: rgba(255,255,255,0.04); color: #fff; }
            .gs-hosting__nav.is-active { background: rgba(78,170,255,0.12); color: #4eaaff; }
            .gs-hosting__nav .dashicons { font-size: 18px; width: 18px; height: 18px; }
            .gs-hosting__main { min-width: 0; }
            .gs-hosting__panel { display: none; }
            .gs-hosting__panel.is-active { display: block; }
            .gs-hosting__section-title { color: #fff; font-size: 1.05rem; margin: 0 0 4px; }
            .gs-hosting__section-sub { color: var(--gs-muted, #94a3b8); font-size: 0.85rem; margin: 0 0 18px; }
            .gs-hosting__card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
            .gs-hosting__card-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
            .gs-hosting__card-title { color: #fff; font-weight: 600; margin: 0; }
            .gs-hosting__card-desc { color: var(--gs-muted, #94a3b8); font-size: 0.85rem; margin: 4px 0 0; }
            .gs-hosting__feedback { font-size: 0.85rem; color: #a5b4fc; min-height: 18px; margin-top: 8px; }
            .gs-hosting__feedback.is-error { color: #fca5a5; }
            .gs-hosting__feedback.is-success { color: #a7f3d0; }
            .gs-hosting__toggle { background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.1); color: #e6edf7; padding: 8px 16px; border-radius: 999px; cursor: pointer; font-size: 0.8rem; font-weight: 600; min-width: 120px; }
            .gs-hosting__toggle[data-enabled="1"] { background: rgba(16,185,129,0.18); border-color: rgba(16,185,129,0.45); color: #6ee7b7; }
            .gs-hosting__toggle:disabled { opacity: 0.5; cursor: not-allowed; }
            .gs-hosting__btn { background: linear-gradient(180deg, #4f46e5, #3b82f6); border: 0; color: #fff; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; }
            .gs-hosting__btn:hover { filter: brightness(1.1); }
            .gs-hosting__btn.is-danger { background: linear-gradient(180deg, #dc2626, #b91c1c); }
            .gs-hosting__btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .gs-hosting__stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
            .gs-hosting__stat { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 16px; }
            .gs-hosting__stat-label { color: var(--gs-muted, #94a3b8); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; }
            .gs-hosting__stat-value { color: #fff; font-size: 1.4rem; font-weight: 700; margin-top: 6px; }
            .gs-hosting__stat-meta { color: var(--gs-muted, #94a3b8); font-size: 0.75rem; margin-top: 4px; }
            .gs-hosting__progress { background: rgba(0,0,0,0.3); border-radius: 999px; height: 8px; margin-top: 8px; overflow: hidden; }
            .gs-hosting__progress-bar { height: 100%; background: linear-gradient(90deg, #10b981, #06b6d4); transition: width 0.4s; }
            .gs-hosting__progress-bar.is-warn { background: linear-gradient(90deg, #f59e0b, #ef4444); }
            .gs-hosting__table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
            .gs-hosting__table th, .gs-hosting__table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.06); color: #e6edf7; }
            .gs-hosting__table th { color: var(--gs-muted, #94a3b8); text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.06em; font-weight: 600; }
            .gs-hosting__table tbody tr:hover { background: rgba(255,255,255,0.02); }
            .gs-hosting__search { width: 100%; max-width: 320px; padding: 8px 12px; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #fff; font-size: 0.85rem; }
            .gs-hosting__pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
            .gs-hosting__pill.is-ok { background: rgba(16,185,129,0.18); color: #6ee7b7; }
            .gs-hosting__pill.is-err { background: rgba(239,68,68,0.18); color: #fca5a5; }
            .gs-hosting__pill.is-warn { background: rgba(245,158,11,0.18); color: #fcd34d; }
            .gs-hosting__log-list { max-height: 480px; overflow-y: auto; background: #0a0d12; border-radius: 8px; border: 1px solid rgba(255,255,255,0.06); padding: 8px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
            .gs-hosting__log-entry { padding: 6px 8px; border-bottom: 1px solid rgba(255,255,255,0.04); white-space: pre-wrap; word-break: break-all; color: #e6edf7; display: flex; gap: 10px; align-items: flex-start; }
            .gs-hosting__log-entry:last-child { border-bottom: 0; }
            .gs-hosting__log-entry.is-warn { color: #fcd34d; }
            .gs-hosting__log-entry.is-err { color: #fca5a5; }
            .gs-hosting__log-meta { color: var(--gs-muted, #94a3b8); flex-shrink: 0; font-size: 0.7rem; }
            .gs-hosting__log-text { flex: 1; min-width: 0; }
            .gs-hosting__log-copy { background: transparent; border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; padding: 2px 8px; border-radius: 6px; cursor: pointer; font-size: 0.7rem; flex-shrink: 0; }
            .gs-hosting__log-copy:hover { color: #fff; border-color: rgba(255,255,255,0.2); }
            .gs-hosting__loading { color: var(--gs-muted, #94a3b8); font-style: italic; padding: 16px; text-align: center; }
            .gs-hosting__filter-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
            .gs-hosting__select { background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.1); color: #e6edf7; padding: 7px 12px; border-radius: 8px; font-size: 0.85rem; }
            @media (max-width: 820px) {
                .gs-hosting { grid-template-columns: 1fr; }
                .gs-hosting__sidebar { flex-direction: row; flex-wrap: wrap; padding-right: 0; padding-bottom: 12px; border-right: 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
            }
        </style>

        <nav class="gs-hosting__sidebar" role="tablist">
            <button type="button" class="gs-hosting__nav is-active" data-section="dashboard" role="tab"><span class="dashicons dashicons-dashboard"></span><?php esc_html_e( 'Dashboard', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="domains" role="tab"><span class="dashicons dashicons-admin-site"></span><?php esc_html_e( 'Domains', 'gend-society' ); ?></button>
            <?php // Compute Gas promoted to a top-level membership card tab. ?>
            <button type="button" class="gs-hosting__nav" data-section="logs" role="tab"><span class="dashicons dashicons-warning"></span><?php esc_html_e( 'Logs', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="tables" role="tab"><span class="dashicons dashicons-database"></span><?php esc_html_e( 'Tables', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="media" role="tab"><span class="dashicons dashicons-cloud"></span><?php esc_html_e( 'Containers', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="backups" role="tab"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Backups', 'gend-society' ); ?></button>
        </nav>

        <div class="gs-hosting__main">

            <!-- ── Dashboard sub-panel ───────────────────────────── -->
            <section class="gs-hosting__panel is-active" data-panel="dashboard" role="tabpanel">
                <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Hosting Dashboard', 'gend-society' ); ?></h4>
                <p class="gs-hosting__section-sub"><?php esc_html_e( 'Caches, hardening, and one-shot operations for this install.', 'gend-society' ); ?></p>

                <div class="gs-hosting__card">
                    <div class="gs-hosting__card-header">
                        <div>
                            <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Static Page Cache', 'gend-society' ); ?></h5>
                            <p class="gs-hosting__card-desc"><?php esc_html_e( 'Purge edge / nginx / Varnish page cache for this install.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__btn" data-gs-hosting="cache-page"><?php esc_html_e( 'Clear page cache', 'gend-society' ); ?></button>
                    </div>
                </div>

                <div class="gs-hosting__card">
                    <div class="gs-hosting__card-header">
                        <div>
                            <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Object Cache', 'gend-society' ); ?></h5>
                            <p class="gs-hosting__card-desc"><?php esc_html_e( 'Flush Redis / memcached / wp_cache.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__btn" data-gs-hosting="cache-object"><?php esc_html_e( 'Clear object cache', 'gend-society' ); ?></button>
                    </div>
                </div>

                <div class="gs-hosting__card">
                    <div class="gs-hosting__card-header">
                        <div>
                            <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Reset to Fresh Template', 'gend-society' ); ?></h5>
                            <p class="gs-hosting__card-desc"><?php esc_html_e( 'Destructive — wipes posts, pages, and media, then re-seeds the template content. Backup runs first.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__btn is-danger" data-gs-hosting="template-reset"><?php esc_html_e( 'Reset web app', 'gend-society' ); ?></button>
                    </div>
                </div>

                <div class="gs-hosting__card" data-toggle-card="waf">
                    <div class="gs-hosting__card-header">
                        <div>
                            <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Web Application Firewall', 'gend-society' ); ?></h5>
                            <p class="gs-hosting__card-desc"><?php esc_html_e( 'ModSecurity OWASP CRS at the edge. Blocks SQLi, XSS, RCE patterns.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__toggle" data-gs-hosting="toggle-waf" data-enabled="0" disabled><?php esc_html_e( 'Loading…', 'gend-society' ); ?></button>
                    </div>
                </div>

                <div class="gs-hosting__card" data-toggle-card="password">
                    <div class="gs-hosting__card-header">
                        <div>
                            <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Password Protection', 'gend-society' ); ?></h5>
                            <p class="gs-hosting__card-desc"><?php esc_html_e( 'Edge-level basic auth so the web app is private during build-out.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__toggle" data-gs-hosting="toggle-password" data-enabled="0" disabled><?php esc_html_e( 'Loading…', 'gend-society' ); ?></button>
                    </div>
                </div>

                <div class="gs-hosting__card" data-toggle-card="bfa">
                    <div class="gs-hosting__card-header">
                        <div>
                            <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Brute Force Attack Protection', 'gend-society' ); ?></h5>
                            <p class="gs-hosting__card-desc"><?php esc_html_e( 'Throttles failed wp-login attempts at the edge, before they hit PHP.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__toggle" data-gs-hosting="toggle-bfa" data-enabled="0" disabled><?php esc_html_e( 'Loading…', 'gend-society' ); ?></button>
                    </div>
                </div>

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="dashboard"></div>
            </section>

            <!-- ── Domains sub-panel ─────────────────────────────── -->
            <section class="gs-hosting__panel" data-panel="domains" role="tabpanel">
                <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Domains', 'gend-society' ); ?></h4>
                <p class="gs-hosting__section-sub"><?php esc_html_e( 'Add, verify, and remove custom domains. DNS records / SSL provision automatically on verify.', 'gend-society' ); ?></p>

                <form class="gs-mship-form" data-gs-hosting-form="domain-add" style="max-width: 540px;">
                    <input type="text" name="domain" placeholder="<?php esc_attr_e( 'yourdomain.com', 'gend-society' ); ?>" required style="background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.1); color:#fff; padding:8px 12px; border-radius:8px; flex:1;">
                    <button type="submit" class="gs-hosting__btn"><?php esc_html_e( 'Add domain', 'gend-society' ); ?></button>
                </form>

                <table class="gs-hosting__table" id="gs-hosting-domains-table" style="margin-top: 16px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Domain', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'DNS', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'SSL', 'gend-society' ); ?></th>
                            <th style="width: 1%;"><?php esc_html_e( 'Actions', 'gend-society' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $domains ) ) : ?>
                            <tr><td colspan="4" style="color:var(--gs-muted, #94a3b8); font-style:italic; padding:18px 12px;"><?php esc_html_e( 'No custom domains added yet.', 'gend-society' ); ?></td></tr>
                        <?php else : foreach ( $domains as $d ) :
                            $host        = isset( $d['host'] ) ? (string) $d['host'] : '';
                            $is_primary  = ! empty( $d['primary'] );
                            $dns_status  = isset( $d['dns_status'] ) ? (string) $d['dns_status'] : ( ! empty( $d['verified'] ) ? 'ok' : 'pending' );
                            $ssl_status  = isset( $d['ssl_status'] ) ? (string) $d['ssl_status'] : ( ! empty( $d['ssl'] ) ? 'ok' : 'pending' );
                        ?>
                            <tr data-domain="<?php echo esc_attr( $host ); ?>">
                                <td>
                                    <strong style="color:#fff;"><?php echo esc_html( $host ); ?></strong>
                                    <?php if ( $is_primary ) : ?>
                                        <span class="gs-hosting__pill is-ok" style="margin-left:6px;"><?php esc_html_e( 'Primary', 'gend-society' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo gs_hosting_render_status_pill( $dns_status ); ?></td>
                                <td><?php echo gs_hosting_render_status_pill( $ssl_status ); ?></td>
                                <td style="white-space: nowrap;">
                                    <button type="button" class="gs-hosting__btn" data-gs-hosting="domain-verify" data-domain="<?php echo esc_attr( $host ); ?>" style="background: rgba(255,255,255,0.08); margin-right: 6px;"><?php esc_html_e( 'Verify', 'gend-society' ); ?></button>
                                    <button type="button" class="gs-hosting__btn is-danger" data-gs-hosting="domain-remove" data-domain="<?php echo esc_attr( $host ); ?>"><?php esc_html_e( 'Remove', 'gend-society' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="domains"></div>
            </section>

            <?php // Compute Gas panel relocated to the top membership tab strip
                  // (rendered by gs_render_membership_panel). Its AJAX
                  // endpoint (gs_hosting_compute_gas) is still registered
                  // below and is what the top-level panel calls. ?>

            <!-- ── Logs sub-panel ────────────────────────────────── -->
            <section class="gs-hosting__panel" data-panel="logs" role="tabpanel">
                <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Error Logs', 'gend-society' ); ?></h4>
                <p class="gs-hosting__section-sub"><?php esc_html_e( 'Tail of php-error.log + WP debug.log. Copy any entry into the Brain to diagnose.', 'gend-society' ); ?></p>

                <div class="gs-hosting__filter-row">
                    <select class="gs-hosting__select" data-gs-hosting-logs-severity>
                        <option value="all"><?php esc_html_e( 'All severities', 'gend-society' ); ?></option>
                        <option value="error"><?php esc_html_e( 'Errors / Fatals', 'gend-society' ); ?></option>
                        <option value="warning"><?php esc_html_e( 'Warnings', 'gend-society' ); ?></option>
                        <option value="notice"><?php esc_html_e( 'Notices / Deprecated', 'gend-society' ); ?></option>
                    </select>
                    <input type="search" class="gs-hosting__search" placeholder="<?php esc_attr_e( 'Filter (regex or substring)…', 'gend-society' ); ?>" data-gs-hosting-logs-search>
                    <button type="button" class="gs-hosting__btn" data-gs-hosting="logs-refresh" style="background: rgba(255,255,255,0.08);"><?php esc_html_e( 'Refresh', 'gend-society' ); ?></button>
                </div>

                <div class="gs-hosting__log-list" data-gs-hosting-panel-body="logs">
                    <div class="gs-hosting__loading"><?php esc_html_e( 'Loading log tail…', 'gend-society' ); ?></div>
                </div>
                <div class="gs-hosting__feedback" data-gs-hosting-feedback="logs"></div>
            </section>

            <!-- ── Tables sub-panel ──────────────────────────────── -->
            <section class="gs-hosting__panel" data-panel="tables" role="tabpanel">
                <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Database Tables', 'gend-society' ); ?></h4>
                <p class="gs-hosting__section-sub"><?php esc_html_e( 'Live row counts and on-disk size for every table on this install.', 'gend-society' ); ?></p>

                <div class="gs-hosting__stat-grid">
                    <div class="gs-hosting__stat">
                        <div class="gs-hosting__stat-label"><?php esc_html_e( 'Total Size', 'gend-society' ); ?></div>
                        <div class="gs-hosting__stat-value"><?php echo esc_html( size_format( (int) $tables_data['total_bytes'], 2 ) ); ?></div>
                        <div class="gs-hosting__stat-meta"><?php echo esc_html( sprintf( __( '%s of plan', 'gend-society' ), size_format( gs_hosting_db_plan_bytes(), 0 ) ) ); ?></div>
                        <div class="gs-hosting__progress">
                            <?php $pct = gs_hosting_pct( (int) $tables_data['total_bytes'], gs_hosting_db_plan_bytes() ); ?>
                            <div class="gs-hosting__progress-bar<?php echo $pct > 80 ? ' is-warn' : ''; ?>" style="width: <?php echo (int) $pct; ?>%;"></div>
                        </div>
                    </div>
                    <div class="gs-hosting__stat">
                        <div class="gs-hosting__stat-label"><?php esc_html_e( 'Tables', 'gend-society' ); ?></div>
                        <div class="gs-hosting__stat-value"><?php echo (int) $tables_data['count']; ?></div>
                        <div class="gs-hosting__stat-meta"><?php echo esc_html( sprintf( __( '%s rows total', 'gend-society' ), number_format_i18n( (int) $tables_data['total_rows'] ) ) ); ?></div>
                    </div>
                    <div class="gs-hosting__stat">
                        <div class="gs-hosting__stat-label"><?php esc_html_e( 'Largest Table', 'gend-society' ); ?></div>
                        <div class="gs-hosting__stat-value" style="font-size: 1rem;"><?php echo esc_html( $tables_data['largest_name'] ?: '—' ); ?></div>
                        <div class="gs-hosting__stat-meta"><?php echo esc_html( size_format( (int) $tables_data['largest_bytes'], 2 ) ); ?></div>
                    </div>
                    <div class="gs-hosting__stat">
                        <div class="gs-hosting__stat-label"><?php esc_html_e( 'Plan', 'gend-society' ); ?></div>
                        <div class="gs-hosting__stat-value" style="font-size: 1rem;"><?php echo esc_html( gs_hosting_plan_label( $billing ) ); ?></div>
                        <button type="button" class="gs-hosting__btn" data-gs-mship="upgrade-plan" data-group="hosting" style="margin-top: 8px; font-size: 0.75rem; padding: 6px 12px;"><?php esc_html_e( 'Upgrade', 'gend-society' ); ?></button>
                    </div>
                </div>

                <!-- Single launch button — the heavy list + query runner
                     opens in a 90vw modal so the sub-tab stays a quick
                     glance card instead of scrolling forever. -->
                <div style="margin-top: 18px; display: flex; flex-direction: column; gap: 8px;">
                    <button type="button" class="gs-hosting__btn" id="gs-hosting-tables-launch" style="align-self: flex-start; padding: 12px 22px; font-size: 0.95rem;">
                        <span class="dashicons dashicons-database" style="vertical-align: middle; margin-right: 8px;"></span>
                        <?php esc_html_e( 'Launch Your App Tables', 'gend-society' ); ?>
                    </button>
                    <p style="color: var(--gs-muted, #94a3b8); font-size: 0.8rem; margin: 0;">
                        <?php esc_html_e( 'Browse every table, search by name, and run ad-hoc SQL queries against the app database.', 'gend-society' ); ?>
                    </p>
                </div>
            </section>

            <!-- Tables explorer modal — opens via #gs-hosting-tables-launch.
                 Portaled to <body> by JS on first open so position:fixed
                 escapes the tab panel's containing block. 90vw / 90vh so
                 the table list and query runner share enough room. -->
            <div class="gs-hosting-tables-modal" id="gs-hosting-tables-modal" hidden aria-hidden="true">
                <div class="gs-hosting-tables-modal__overlay" data-gs-tables-dismiss></div>
                <div class="gs-hosting-tables-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gs-hosting-tables-title">
                    <header class="gs-hosting-tables-modal__header">
                        <h3 id="gs-hosting-tables-title" style="margin: 0; color: #fff; font-size: 1.1rem; display: flex; align-items: center; gap: 10px;">
                            <span class="dashicons dashicons-database" style="color: #4eaaff;"></span>
                            <?php esc_html_e( 'Your App Tables', 'gend-society' ); ?>
                            <span style="color: var(--gs-muted, #94a3b8); font-size: 0.85rem; font-weight: 400; margin-left: 6px;">
                                · <?php echo (int) $tables_data['count']; ?> <?php esc_html_e( 'tables', 'gend-society' ); ?>
                                · <?php echo esc_html( size_format( (int) $tables_data['total_bytes'], 1 ) ); ?>
                            </span>
                        </h3>
                        <button type="button" class="gs-hosting-tables-modal__close" data-gs-tables-dismiss aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                    </header>
                    <!-- Tabs: Tables / Queries — full-width, single panel
                         visible at a time. Default tab is Tables. -->
                    <nav class="gs-hosting-tables-modal__tabs" role="tablist">
                        <button type="button" class="gs-hosting-tables-modal__tab is-active" data-gs-tables-tab="list" role="tab" aria-selected="true">
                            <span class="dashicons dashicons-list-view" style="vertical-align: middle; margin-right: 4px;"></span>
                            <?php esc_html_e( 'Tables', 'gend-society' ); ?>
                        </button>
                        <button type="button" class="gs-hosting-tables-modal__tab" data-gs-tables-tab="query" role="tab" aria-selected="false">
                            <span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 4px;"></span>
                            <?php esc_html_e( 'Queries', 'gend-society' ); ?>
                        </button>
                    </nav>

                    <div class="gs-hosting-tables-modal__body">
                        <div class="gs-hosting-tables-modal__panes">
                            <!-- Tab 1: Tables list with search -->
                            <div class="gs-hosting-tables-modal__pane is-active" data-gs-tables-pane="list">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                    <input type="search" class="gs-hosting__search" placeholder="<?php esc_attr_e( 'Filter tables…', 'gend-society' ); ?>" data-gs-hosting-tables-search style="flex: 1;">
                                </div>
                                <div class="gs-hosting-tables-modal__list-wrap">
                                    <table class="gs-hosting__table" id="gs-hosting-tables-table">
                                        <colgroup>
                                            <col class="gs-col-table">
                                            <col class="gs-col-engine">
                                            <col class="gs-col-rows">
                                            <col class="gs-col-data">
                                            <col class="gs-col-index">
                                            <col class="gs-col-total">
                                            <col class="gs-col-action">
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e( 'Table', 'gend-society' ); ?></th>
                                                <th><?php esc_html_e( 'Engine', 'gend-society' ); ?></th>
                                                <th style="text-align: right;"><?php esc_html_e( 'Rows', 'gend-society' ); ?></th>
                                                <th style="text-align: right;"><?php esc_html_e( 'Data', 'gend-society' ); ?></th>
                                                <th style="text-align: right;"><?php esc_html_e( 'Index', 'gend-society' ); ?></th>
                                                <th style="text-align: right;"><?php esc_html_e( 'Total', 'gend-society' ); ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $tables_data['tables'] as $t ) : ?>
                                                <tr data-search="<?php echo esc_attr( strtolower( $t['name'] ) ); ?>" data-table-name="<?php echo esc_attr( $t['name'] ); ?>">
                                                    <td class="gs-col-table" title="<?php echo esc_attr( $t['name'] ); ?>"><code style="background: rgba(78,170,255,0.1); color:#a5b4fc; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html( $t['name'] ); ?></code></td>
                                                    <td class="gs-col-engine"><?php echo esc_html( $t['engine'] ); ?></td>
                                                    <td class="gs-col-rows" style="text-align: right;"><?php echo esc_html( number_format_i18n( (int) $t['rows'] ) ); ?></td>
                                                    <td class="gs-col-data" style="text-align: right;"><?php echo esc_html( size_format( (int) $t['data_bytes'], 1 ) ); ?></td>
                                                    <td class="gs-col-index" style="text-align: right;"><?php echo esc_html( size_format( (int) $t['index_bytes'], 1 ) ); ?></td>
                                                    <td class="gs-col-total" style="text-align: right; color: #fff; font-weight: 600;"><?php echo esc_html( size_format( (int) $t['total_bytes'], 1 ) ); ?></td>
                                                    <td class="gs-col-action" style="text-align: right;"><button type="button" class="gs-hosting__btn" data-gs-tables-browse="<?php echo esc_attr( $t['name'] ); ?>" style="background: rgba(255,255,255,0.08); padding: 4px 10px; font-size: 0.72rem;"><?php esc_html_e( 'Browse', 'gend-society' ); ?></button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Tab 2: natural-language query builder.
                                 Non-technical users describe what they want;
                                 a "Find It" button asks LEO to translate the
                                 ask into SQL and runs it. The raw SQL panel
                                 is collapsed behind a "Show SQL" toggle for
                                 anyone who wants to review or hand-edit. -->
                            <div class="gs-hosting-tables-modal__pane" data-gs-tables-pane="query" hidden>
                                <div style="margin-bottom: 10px;">
                                    <h4 style="color: #fff; margin: 0 0 4px; font-size: 1rem; display: flex; align-items: center; gap: 8px;">
                                        <span class="dashicons dashicons-search" style="color: #4eaaff;"></span>
                                        <?php esc_html_e( 'What are you looking for?', 'gend-society' ); ?>
                                    </h4>
                                    <p style="color: var(--gs-muted, #94a3b8); font-size: 0.78rem; margin: 0;">
                                        <?php esc_html_e( 'Type a plain-English question. LEO will figure out the database query, run it, and show you the answer.', 'gend-society' ); ?>
                                    </p>
                                </div>

                                <?php
                                // Quick-pick chips. Each carries both a plain-English
                                // "ask" (auto-fills the input) and a fallback SQL
                                // string the runner uses if the AI helper isn't
                                // available. SQL uses {$wpdb->prefix} via JS so it
                                // adapts to the local table prefix.
                                $gs_query_recipes = array(
                                    array(
                                        'label' => __( 'Recent signups', 'gend-society' ),
                                        'ask'   => __( 'Show users who registered in the last 30 days, newest first.', 'gend-society' ),
                                        'sql'   => "SELECT ID, user_login, user_email, display_name, user_registered FROM {$wpdb->prefix}users WHERE user_registered >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY user_registered DESC LIMIT 100;",
                                    ),
                                    array(
                                        'label' => __( 'Recent posts', 'gend-society' ),
                                        'ask'   => __( 'List the 20 most recently published posts with their author.', 'gend-society' ),
                                        'sql'   => "SELECT p.ID, p.post_title, p.post_date, u.display_name AS author FROM {$wpdb->prefix}posts p LEFT JOIN {$wpdb->prefix}users u ON u.ID = p.post_author WHERE p.post_status = 'publish' AND p.post_type = 'post' ORDER BY p.post_date DESC LIMIT 20;",
                                    ),
                                    array(
                                        'label' => __( 'Largest tables', 'gend-society' ),
                                        'ask'   => __( 'Which database tables are using the most space?', 'gend-society' ),
                                        'sql'   => "SELECT table_name, ROUND((data_length + index_length) / 1024 / 1024, 1) AS size_mb, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 25;",
                                    ),
                                    array(
                                        'label' => __( 'Today\'s orders', 'gend-society' ),
                                        'ask'   => __( 'Show today\'s WooCommerce orders.', 'gend-society' ),
                                        'sql'   => "SELECT ID, post_status, post_date, (SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = '_order_total' LIMIT 1) AS total FROM {$wpdb->prefix}posts p WHERE post_type = 'shop_order' AND DATE(post_date) = CURDATE() ORDER BY post_date DESC;",
                                    ),
                                    array(
                                        'label' => __( 'Active users (30d)', 'gend-society' ),
                                        'ask'   => __( 'Which users have logged in or commented in the last 30 days?', 'gend-society' ),
                                        'sql'   => "SELECT u.ID, u.user_login, u.display_name, MAX(c.comment_date) AS last_comment FROM {$wpdb->prefix}users u LEFT JOIN {$wpdb->prefix}comments c ON c.user_id = u.ID WHERE c.comment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY u.ID ORDER BY last_comment DESC LIMIT 50;",
                                    ),
                                    array(
                                        'label' => __( 'Drafts older than 7d', 'gend-society' ),
                                        'ask'   => __( 'Find draft posts older than 7 days.', 'gend-society' ),
                                        'sql'   => "SELECT ID, post_title, post_date, post_modified FROM {$wpdb->prefix}posts WHERE post_status = 'draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY post_modified ASC LIMIT 50;",
                                    ),
                                );
                                ?>
                                <div class="gs-hosting-recipes" style="display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0 10px;">
                                    <?php foreach ( $gs_query_recipes as $r ) : ?>
                                        <button type="button"
                                                class="gs-hosting-recipe"
                                                data-ask="<?php echo esc_attr( $r['ask'] ); ?>"
                                                data-sql="<?php echo esc_attr( $r['sql'] ); ?>"
                                                title="<?php echo esc_attr( $r['ask'] ); ?>">
                                            <?php echo esc_html( $r['label'] ); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <textarea id="gs-hosting-ask-input" class="gs-hosting-tables-modal__ask" placeholder="<?php esc_attr_e( 'e.g. "How many users signed up in the last 30 days?"  or  "List the 20 most recent published posts"', 'gend-society' ); ?>" spellcheck="true" rows="2"></textarea>

                                <div style="display: flex; gap: 8px; align-items: center; margin-top: 8px; flex-wrap: wrap;">
                                    <button type="button" class="gs-hosting__btn" id="gs-hosting-ask-run" style="padding: 10px 20px;">
                                        <span class="dashicons dashicons-superhero" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
                                        <?php esc_html_e( 'Find It', 'gend-society' ); ?>
                                    </button>
                                    <span id="gs-hosting-ask-meta" style="color: var(--gs-muted, #94a3b8); font-size: 0.75rem;"></span>
                                </div>

                                <details id="gs-hosting-query-details" style="margin-top: 14px;">
                                    <summary style="cursor: pointer; color: var(--gs-muted, #94a3b8); font-size: 0.78rem; padding: 6px 0; list-style: none; display: flex; align-items: center; gap: 6px;">
                                        <span class="dashicons dashicons-arrow-right" style="font-size: 14px; width: 14px; height: 14px; transition: transform 0.15s;"></span>
                                        <?php esc_html_e( 'Show SQL (advanced)', 'gend-society' ); ?>
                                    </summary>
                                    <div style="margin-top: 6px;">
                                        <textarea id="gs-hosting-query-input" class="gs-hosting-tables-modal__textarea" placeholder="<?php esc_attr_e( 'SELECT * FROM wp_users LIMIT 10;', 'gend-society' ); ?>" spellcheck="false"></textarea>
                                        <div style="display: flex; gap: 10px; align-items: center; margin-top: 8px; flex-wrap: wrap;">
                                            <button type="button" class="gs-hosting__btn" id="gs-hosting-query-run" style="background: rgba(255,255,255,0.08);">
                                                <span class="dashicons dashicons-controls-play" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;"></span>
                                                <?php esc_html_e( 'Run This SQL', 'gend-society' ); ?>
                                            </button>
                                            <span id="gs-hosting-query-meta" style="color: var(--gs-muted, #94a3b8); font-size: 0.72rem;"></span>
                                            <span style="color: #fcd34d; font-size: 0.7rem; margin-left: auto;"><span class="dashicons dashicons-warning" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> <?php esc_html_e( 'DROP / DELETE / UPDATE will ask first.', 'gend-society' ); ?></span>
                                        </div>
                                    </div>
                                </details>

                                <div id="gs-hosting-query-results" class="gs-hosting-tables-modal__results"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                /* z-index 100000 sits one below the fixed gend-society top
                   header (z-index 100001), so the header stays visible.
                   padding-top reserves space so the dialog never tucks
                   under that header — without this the dialog's title bar
                   was clipped by it. */
                .gs-hosting-tables-modal { display:none; position:fixed; top:0; left:0; right:0; bottom:0; inset:0; z-index:100000; align-items:center; justify-content:center; padding:96px 0 24px; box-sizing:border-box; }
                .gs-hosting-tables-modal.is-open { display:flex !important; }
                .gs-hosting-tables-modal[hidden] { display:none !important; }
                .gs-hosting-tables-modal.is-open[hidden] { display:flex !important; }
                .gs-hosting-tables-modal__overlay { position:absolute; inset:0; background:rgba(5,7,10,0.88); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); }
                /* Dialog height shrunk to fit inside the container padding
                   above (96 top + 24 bottom = 120px reserved). Falls back
                   to 90vh on shorter viewports where the gend header is
                   smaller / not present. */
                .gs-hosting-tables-modal__dialog { position:relative; background:linear-gradient(180deg, rgba(20,24,34,0.95), rgba(11,14,20,0.96)); border:1px solid rgba(255,255,255,0.10); border-radius:16px; width:90vw; max-width:1600px; height:100%; max-height:min(90vh, calc(100vh - 120px)); display:flex; flex-direction:column; box-shadow:0 40px 80px rgba(0,0,0,0.7); color:#e6edf7; z-index:1; }
                .gs-hosting-tables-modal__header { display:flex; align-items:center; justify-content:space-between; padding:18px 24px; border-bottom:1px solid rgba(255,255,255,0.08); flex-shrink:0; }
                .gs-hosting-tables-modal__close { background:none; border:0; color:#e6edf7; font-size:26px; cursor:pointer; padding:0 6px; line-height:1; }
                .gs-hosting-tables-modal__close:hover { color:#fff; }
                .gs-hosting-tables-modal__body { flex:1; min-height:0; padding:18px 24px 24px; overflow:hidden; }
                /* Full-width tab strip sits between the header and the body
                   so each pane (Tables / Queries) gets the entire dialog
                   width when active. */
                .gs-hosting-tables-modal__tabs { display:flex; gap:4px; padding:0 24px; border-bottom:1px solid rgba(255,255,255,0.08); flex-shrink:0; }
                .gs-hosting-tables-modal__tab {
                    background:transparent; border:0;
                    color:var(--gs-muted, #94a3b8); font-size:0.85rem; font-weight:600;
                    text-transform:uppercase; letter-spacing:0.06em;
                    padding:14px 18px; cursor:pointer;
                    border-bottom:2px solid transparent; margin-bottom:-1px;
                    transition:color 0.15s ease, border-color 0.15s ease;
                }
                .gs-hosting-tables-modal__tab:hover { color:#fff; }
                .gs-hosting-tables-modal__tab.is-active { color:#4eaaff; border-bottom-color:#4eaaff; }
                .gs-hosting-tables-modal__panes { height:100%; min-height:0; }
                .gs-hosting-tables-modal__pane { display:flex; flex-direction:column; height:100%; min-height:0; }
                .gs-hosting-tables-modal__pane[hidden] { display:none !important; }
                .gs-hosting-tables-modal__list-wrap { flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; border:1px solid rgba(255,255,255,0.06); border-radius:10px; }
                /* Lock column widths so long table names truncate instead
                   of forcing horizontal scroll; numeric cells stay single
                   line ("106.6 MB" was wrapping). */
                .gs-hosting-tables-modal__list-wrap table { width:100%; table-layout:fixed; }
                .gs-hosting-tables-modal__list-wrap thead th { position:sticky; top:0; background:rgba(11,14,20,0.95); backdrop-filter:blur(8px); z-index:2; }
                .gs-hosting-tables-modal__list-wrap thead th,
                .gs-hosting-tables-modal__list-wrap tbody td { padding:8px 10px; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-table  { width:auto; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-engine { width:70px; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-rows   { width:78px; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-data   { width:76px; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-index  { width:76px; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-total  { width:82px; }
                .gs-hosting-tables-modal__list-wrap colgroup col.gs-col-action { width:80px; }
                /* Table-name chip: truncate with ellipsis, full name in title. */
                .gs-hosting-tables-modal__list-wrap td.gs-col-table {
                    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;
                }
                .gs-hosting-tables-modal__list-wrap td.gs-col-table code {
                    display:inline-block; max-width:100%;
                    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;
                }
                /* Keep value+unit together on one line in numeric cells. */
                .gs-hosting-tables-modal__list-wrap td.gs-col-engine,
                .gs-hosting-tables-modal__list-wrap td.gs-col-rows,
                .gs-hosting-tables-modal__list-wrap td.gs-col-data,
                .gs-hosting-tables-modal__list-wrap td.gs-col-index,
                .gs-hosting-tables-modal__list-wrap td.gs-col-total { white-space:nowrap; }
                /* Queries pane gets a wider results area now that it owns
                   the full dialog width. The textarea + chips stay top, the
                   results stretch into the rest of the height. */
                .gs-hosting-tables-modal__textarea {
                    width:100%; min-height:120px; max-height:200px; resize:vertical;
                    background:#0a0d12; border:1px solid rgba(255,255,255,0.12); border-radius:10px;
                    color:#e6edf7; font-family:ui-monospace, SFMono-Regular, Menlo, monospace;
                    font-size:0.85rem; padding:12px; box-sizing:border-box; line-height:1.5;
                }
                .gs-hosting-tables-modal__textarea:focus { outline:none; border-color:rgba(78,170,255,0.45); box-shadow:0 0 0 2px rgba(78,170,255,0.18); }
                /* Plain-English "ask LEO" input — same shape as the SQL
                   textarea but in the regular font so it reads as prose,
                   and slightly more emphasized so it's the primary entry. */
                .gs-hosting-tables-modal__ask {
                    width:100%; resize:vertical; min-height:64px;
                    background:linear-gradient(180deg, rgba(78,170,255,0.06), rgba(168,85,247,0.04));
                    border:1px solid rgba(78,170,255,0.30); border-radius:12px;
                    color:#fff; font-size:0.95rem; padding:12px 14px; box-sizing:border-box; line-height:1.45;
                }
                .gs-hosting-tables-modal__ask:focus { outline:none; border-color:rgba(78,170,255,0.55); box-shadow:0 0 0 3px rgba(78,170,255,0.18); }
                .gs-hosting-tables-modal__ask::placeholder { color:rgba(203,213,245,0.55); }
                /* Quick-pick chip row — one-click recipes for common
                   questions, so non-technical users have a starting point
                   even when the AI helper is unavailable. */
                .gs-hosting-recipe {
                    background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10);
                    color:#cbd5f5; padding:5px 12px; border-radius:999px;
                    font-size:0.75rem; cursor:pointer; transition:all 0.15s ease;
                    line-height:1.2;
                }
                .gs-hosting-recipe:hover {
                    background:rgba(78,170,255,0.14); border-color:rgba(78,170,255,0.40); color:#fff;
                }
                /* Open <details> rotates the chevron, like an accordion. */
                #gs-hosting-query-details[open] > summary .dashicons-arrow-right { transform: rotate(90deg); }
                #gs-hosting-query-details > summary::-webkit-details-marker { display: none; }
                /* Friendly natural-language answer rendering for SELECT
                   results — single-value queries get an oversized number,
                   multi-row results keep the table view. */
                .gs-hosting-tables-modal__results .gs-result-single {
                    padding:24px; text-align:center;
                }
                .gs-hosting-tables-modal__results .gs-result-single .num {
                    font-size:2.4rem; font-weight:700; color:#fff; display:block; margin-bottom:6px;
                }
                .gs-hosting-tables-modal__results .gs-result-single .lbl {
                    color:var(--gs-muted, #94a3b8); font-size:0.85rem;
                }
                .gs-hosting-tables-modal__results { flex:1; min-height:0; margin-top:12px; overflow:auto; border:1px solid rgba(255,255,255,0.06); border-radius:10px; background:rgba(0,0,0,0.25); padding:10px; }
                .gs-hosting-tables-modal__results table { width:100%; border-collapse:collapse; font-size:0.78rem; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; }
                .gs-hosting-tables-modal__results th, .gs-hosting-tables-modal__results td { padding:6px 10px; border-bottom:1px solid rgba(255,255,255,0.05); text-align:left; vertical-align:top; white-space:nowrap; }
                .gs-hosting-tables-modal__results th { color:#a5b4fc; text-transform:uppercase; font-size:0.7rem; letter-spacing:0.06em; background:rgba(78,170,255,0.06); position:sticky; top:0; }
                .gs-hosting-tables-modal__results td { color:#e6edf7; max-width:320px; overflow:hidden; text-overflow:ellipsis; }
                .gs-hosting-tables-modal__results .is-err { color:#fca5a5; padding:14px; font-family:inherit; }
                .gs-hosting-tables-modal__results .is-ok { color:#a7f3d0; padding:14px; font-family:inherit; }
                .gs-hosting-tables-modal__results .is-loading { color:var(--gs-muted, #94a3b8); padding:14px; text-align:center; font-style:italic; }
                body.gs-hosting-tables-open { overflow:hidden; }
                @media (max-width: 960px) {
                    .gs-hosting-tables-modal__dialog { width:96vw; height:96vh; }
                    .gs-hosting-tables-modal__tab { padding:12px 14px; font-size:0.8rem; }
                }
            </style>

            <!-- ── Media sub-panel ───────────────────────────────── -->
            <section class="gs-hosting__panel" data-panel="media" role="tabpanel">
                <?php
                // Each resource the container plan provisions: media (uploads
                // PVC), database (mysql/mariadb), compute (CPU/RAM tier).
                // Future hub-side endpoints can replace these with real
                // plan caps from the install's billing line items.
                $resources = gs_hosting_collect_container_resources( $media_data, $tables_data );
                $plan_label = gs_hosting_plan_label( $billing );
                $plan_unit  = isset( $billing['unit'] ) ? (string) $billing['unit'] : '';
                ?>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
                    <div>
                        <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Containers', 'gend-society' ); ?></h4>
                        <p class="gs-hosting__section-sub" style="margin: 0;"><?php esc_html_e( 'Resources your container plan provisions on this install, with live usage.', 'gend-society' ); ?></p>
                    </div>
                    <div style="text-align: right;">
                        <div class="gs-hosting__stat-label"><?php esc_html_e( 'Container Plan', 'gend-society' ); ?></div>
                        <div style="color:#fff; font-size:1rem; font-weight:600; margin-top:4px;">
                            <?php echo esc_html( $plan_label ); ?>
                            <?php if ( $plan_unit !== '' ) : ?>
                                <span style="color: var(--gs-muted, #94a3b8); font-size: 0.8rem; font-weight: 400;">/ <?php echo esc_html( $plan_unit ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="gs-hosting__card" style="padding: 0; overflow: hidden;">
                    <?php foreach ( $resources as $r ) :
                        $pct  = gs_hosting_pct( (int) $r['used'], (int) $r['cap'] );
                        $warn = $pct >= 80;
                    ?>
                        <div class="gs-hosting__resource" style="padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.06);">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 10px;">
                                <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                                    <span class="dashicons <?php echo esc_attr( $r['icon'] ); ?>" style="color:#4eaaff; font-size: 22px; width: 22px; height: 22px;"></span>
                                    <div>
                                        <div style="color: #fff; font-weight: 600;"><?php echo esc_html( $r['label'] ); ?></div>
                                        <div style="color: var(--gs-muted, #94a3b8); font-size: 0.8rem;"><?php echo esc_html( $r['hint'] ); ?></div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 14px;">
                                    <div style="text-align: right; min-width: 160px;">
                                        <div style="color: #fff; font-weight: 700;">
                                            <?php echo esc_html( $r['used_label'] ); ?>
                                            <span style="color: var(--gs-muted, #94a3b8); font-weight: 400;">/ <?php echo esc_html( $r['cap_label'] ); ?></span>
                                        </div>
                                        <div style="color: <?php echo $warn ? '#fcd34d' : 'var(--gs-muted, #94a3b8)'; ?>; font-size: 0.75rem; margin-top: 2px;">
                                            <?php echo (int) $pct; ?>% <?php esc_html_e( 'used', 'gend-society' ); ?>
                                        </div>
                                    </div>
                                    <button type="button" class="gs-hosting__btn" data-gs-mship="upgrade-plan" data-group="hosting" data-resource="<?php echo esc_attr( $r['slug'] ); ?>" style="font-size: 0.75rem; padding: 7px 14px;"><?php esc_html_e( 'Upgrade', 'gend-society' ); ?></button>
                                </div>
                            </div>
                            <div class="gs-hosting__progress">
                                <div class="gs-hosting__progress-bar<?php echo $warn ? ' is-warn' : ''; ?>" style="width: <?php echo (int) $pct; ?>%;"></div>
                            </div>
                            <?php if ( ! empty( $r['meta'] ) ) : ?>
                                <p style="color: var(--gs-muted, #94a3b8); font-size: 0.75rem; margin: 8px 0 0;"><?php echo esc_html( $r['meta'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 14px; display: flex; gap: 8px;">
                    <button type="button" class="gs-hosting__btn" data-gs-hosting="media-rescan" style="background: rgba(255,255,255,0.08);"><?php esc_html_e( 'Rescan storage', 'gend-society' ); ?></button>
                    <button type="button" class="gs-hosting__btn" data-gs-mship="upgrade-plan" data-group="hosting"><?php esc_html_e( 'Upgrade container plan', 'gend-society' ); ?></button>
                </div>
                <div class="gs-hosting__feedback" data-gs-hosting-feedback="media"></div>
            </section>

            <!-- ── Backups sub-panel ─────────────────────────────── -->
            <section class="gs-hosting__panel" data-panel="backups" role="tabpanel">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 8px;">
                    <div>
                        <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Backups', 'gend-society' ); ?></h4>
                        <p class="gs-hosting__section-sub" style="margin: 0;"><?php esc_html_e( 'Daily automatic snapshots plus on-demand backups. Restore rolls the install back to that snapshot.', 'gend-society' ); ?></p>
                    </div>
                    <button type="button" class="gs-hosting__btn" data-gs-hosting="backup-now"><?php esc_html_e( 'Backup now', 'gend-society' ); ?></button>
                </div>

                <div class="gs-hosting__filter-row">
                    <input type="search" class="gs-hosting__search" placeholder="<?php esc_attr_e( 'Filter by kind, date, or note…', 'gend-society' ); ?>" data-gs-hosting-backups-search>
                    <select class="gs-hosting__select" data-gs-hosting-backups-kind>
                        <option value="all"><?php esc_html_e( 'All kinds', 'gend-society' ); ?></option>
                        <option value="auto"><?php esc_html_e( 'Automatic', 'gend-society' ); ?></option>
                        <option value="manual"><?php esc_html_e( 'Manual', 'gend-society' ); ?></option>
                        <option value="pre-restore"><?php esc_html_e( 'Pre-restore', 'gend-society' ); ?></option>
                        <option value="pre-reset"><?php esc_html_e( 'Pre-reset', 'gend-society' ); ?></option>
                    </select>
                </div>

                <table class="gs-hosting__table" id="gs-hosting-backups-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Kind', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'gend-society' ); ?></th>
                            <th style="text-align: right;"><?php esc_html_e( 'Size', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'Note', 'gend-society' ); ?></th>
                            <th style="width: 1%;"><?php esc_html_e( 'Action', 'gend-society' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $backups ) ) : ?>
                            <tr data-empty="1"><td colspan="5" style="color:var(--gs-muted, #94a3b8); font-style:italic; padding:18px 12px;"><?php esc_html_e( 'No backups recorded yet. Click "Backup now" to create the first one.', 'gend-society' ); ?></td></tr>
                        <?php else : foreach ( $backups as $b ) :
                            $bid     = (int) ( $b['id'] ?? 0 );
                            $kind    = (string) ( $b['kind'] ?? 'manual' );
                            $created = (string) ( $b['created_at'] ?? '' );
                            $bytes   = (int) ( $b['bytes'] ?? 0 );
                            $note    = (string) ( $b['note'] ?? '' );
                            $restorable = ! empty( $b['restorable'] );
                            $search_blob = strtolower( $kind . ' ' . $created . ' ' . $note );
                        ?>
                            <tr data-backup-id="<?php echo (int) $bid; ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-search="<?php echo esc_attr( $search_blob ); ?>">
                                <td><span class="gs-hosting__pill is-<?php echo $kind === 'manual' ? 'ok' : ( strpos( $kind, 'pre-' ) === 0 ? 'warn' : 'ok' ); ?>"><?php echo esc_html( ucfirst( $kind ) ); ?></span></td>
                                <td><?php echo esc_html( $created ); ?></td>
                                <td style="text-align: right;"><?php echo esc_html( size_format( $bytes, 1 ) ); ?></td>
                                <td style="color: var(--gs-muted, #94a3b8); font-size: 0.85rem;"><?php echo esc_html( $note ); ?></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ( $restorable && $bid > 0 ) : ?>
                                        <button type="button" class="gs-hosting__btn is-danger" data-gs-hosting="backup-restore" data-id="<?php echo (int) $bid; ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-created="<?php echo esc_attr( $created ); ?>" style="font-size: 0.75rem; padding: 6px 12px;"><?php esc_html_e( 'Restore', 'gend-society' ); ?></button>
                                    <?php else : ?>
                                        <span class="gs-hosting__pill is-warn"><?php esc_html_e( 'Pending', 'gend-society' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <p id="gs-hosting-backups-empty" style="display:none; color:var(--gs-muted, #94a3b8); padding:12px 0; font-style:italic; text-align:center;">
                    <?php esc_html_e( 'No backups match the current filter.', 'gend-society' ); ?>
                </p>

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="backups"></div>
            </section>

        </div>
    </div>

    <script>
    (function(){
        var root = document.getElementById('gs-hosting-root');
        if (!root) return;
        var ajax = window.ajaxurl || <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        // Reuse the membership-card nonce, same scope.
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;
        var loaded = { 'logs': false, 'toggles': false };

        function feedback(section, msg, type) {
            var el = root.querySelector('[data-gs-hosting-feedback="' + section + '"]');
            if (!el) return;
            el.className = 'gs-hosting__feedback' + (type ? (type === 'error' ? ' is-error' : ' is-success') : '');
            el.textContent = msg || '';
        }

        function post(action, data) {
            data = data || {};
            data.action = action;
            data.nonce  = nonce;
            return fetch(ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data).toString()
            }).then(function(r){ return r.json(); });
        }

        // Sidebar nav
        root.querySelectorAll('.gs-hosting__nav').forEach(function(btn){
            btn.addEventListener('click', function(){
                var section = btn.dataset.section;
                root.querySelectorAll('.gs-hosting__nav').forEach(function(b){ b.classList.toggle('is-active', b === btn); });
                root.querySelectorAll('.gs-hosting__panel').forEach(function(p){ p.classList.toggle('is-active', p.dataset.panel === section); });
                lazyLoad(section);
            });
        });

        function lazyLoad(section) {
            if (section === 'logs' && !loaded['logs']) {
                loaded['logs'] = true;
                loadLogs();
            }
        }

        // ── Dashboard actions ──
        function bindAction(selector, action, opts) {
            opts = opts || {};
            root.querySelectorAll(selector).forEach(function(btn){
                btn.addEventListener('click', function(){
                    if (opts.confirm && !confirm(opts.confirm)) return;
                    btn.disabled = true;
                    var orig = btn.textContent;
                    btn.textContent = opts.busy || 'Working…';
                    feedback('dashboard', '', null);
                    post(action).then(function(resp){
                        if (resp && resp.success) {
                            feedback('dashboard', (resp.data && resp.data.message) || (opts.successMsg || 'Done.'), 'success');
                        } else {
                            feedback('dashboard', (resp && resp.data && resp.data.message) || 'Failed.', 'error');
                        }
                    }).catch(function(){
                        feedback('dashboard', 'Network error.', 'error');
                    }).finally(function(){
                        btn.disabled = false;
                        btn.textContent = orig;
                    });
                });
            });
        }
        bindAction('[data-gs-hosting="cache-page"]',     'gs_hosting_cache_page',     { busy: 'Clearing…',  successMsg: 'Page cache cleared.' });
        bindAction('[data-gs-hosting="cache-object"]',   'gs_hosting_cache_object',   { busy: 'Clearing…',  successMsg: 'Object cache flushed.' });
        bindAction('[data-gs-hosting="template-reset"]', 'gs_hosting_template_reset', { busy: 'Resetting…', successMsg: 'Reset initiated. Backup running in background.', confirm: 'This will wipe posts, pages, and media on this install. A backup runs first. Continue?' });

        // ── Toggle initial load ──
        function refreshToggles() {
            post('gs_hosting_toggles_get').then(function(resp){
                if (!resp || !resp.success || !resp.data) {
                    setToggleErr('Could not load toggle state.');
                    return;
                }
                applyToggle('waf',      !!resp.data.waf);
                applyToggle('password', !!resp.data.password);
                applyToggle('bfa',      !!resp.data.bfa);
            }).catch(function(){
                setToggleErr('Network error loading toggles.');
            });
        }
        function applyToggle(name, on) {
            var btn = root.querySelector('[data-gs-hosting="toggle-' + name + '"]');
            if (!btn) return;
            btn.disabled = false;
            btn.dataset.enabled = on ? '1' : '0';
            btn.textContent = on ? 'Enabled' : 'Disabled';
        }
        function setToggleErr(msg) {
            root.querySelectorAll('[data-gs-hosting^="toggle-"]').forEach(function(btn){
                btn.disabled = true;
                btn.textContent = 'Unavailable';
                btn.title = msg;
            });
        }
        function bindToggle(name) {
            var btn = root.querySelector('[data-gs-hosting="toggle-' + name + '"]');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var want = btn.dataset.enabled !== '1';
                btn.disabled = true;
                var prev = btn.textContent;
                btn.textContent = 'Saving…';
                feedback('dashboard', '', null);
                post('gs_hosting_toggle_set', { feature: name, enabled: want ? 1 : 0 }).then(function(resp){
                    if (resp && resp.success) {
                        applyToggle(name, !!(resp.data && resp.data.enabled));
                        feedback('dashboard', name.toUpperCase() + ' ' + (resp.data && resp.data.enabled ? 'enabled' : 'disabled') + '.', 'success');
                    } else {
                        btn.disabled = false;
                        btn.textContent = prev;
                        feedback('dashboard', (resp && resp.data && resp.data.message) || 'Failed.', 'error');
                    }
                }).catch(function(){
                    btn.disabled = false;
                    btn.textContent = prev;
                    feedback('dashboard', 'Network error.', 'error');
                });
            });
        }
        bindToggle('waf');
        bindToggle('password');
        bindToggle('bfa');
        refreshToggles();

        // ── Domains ──
        var domainForm = root.querySelector('[data-gs-hosting-form="domain-add"]');
        if (domainForm) {
            domainForm.addEventListener('submit', function(e){
                e.preventDefault();
                var input = domainForm.querySelector('input[name="domain"]');
                var v = (input.value || '').trim().toLowerCase();
                if (!v) return;
                feedback('domains', 'Adding ' + v + '…', null);
                post('gs_membership_domain_add', { domain: v }).then(function(resp){
                    if (resp && resp.success) {
                        feedback('domains', 'Domain added. Reloading…', 'success');
                        setTimeout(function(){ location.reload(); }, 700);
                    } else {
                        feedback('domains', (resp && resp.data && resp.data.message) || 'Failed.', 'error');
                    }
                }).catch(function(){
                    feedback('domains', 'Network error.', 'error');
                });
            });
        }
        root.addEventListener('click', function(e){
            var btn = e.target.closest('[data-gs-hosting="domain-verify"], [data-gs-hosting="domain-remove"]');
            if (!btn) return;
            var action = btn.dataset.gsHosting === 'domain-verify' ? 'gs_membership_domain_verify' : 'gs_membership_domain_remove';
            var d = btn.dataset.domain;
            if (action === 'gs_membership_domain_remove' && !confirm('Remove ' + d + '?')) return;
            btn.disabled = true;
            feedback('domains', (action === 'gs_membership_domain_verify' ? 'Verifying ' : 'Removing ') + d + '…', null);
            post(action, { domain: d }).then(function(resp){
                if (resp && resp.success) {
                    feedback('domains', 'Done. Reloading…', 'success');
                    setTimeout(function(){ location.reload(); }, 700);
                } else {
                    btn.disabled = false;
                    feedback('domains', (resp && resp.data && resp.data.message) || 'Failed.', 'error');
                }
            }).catch(function(){
                btn.disabled = false;
                feedback('domains', 'Network error.', 'error');
            });
        });

        // ── Compute Gas relocated to top-level membership tab. ──

        // ── Logs ──
        var logState = { entries: [], severity: 'all', q: '' };
        function loadLogs() {
            var body = root.querySelector('[data-gs-hosting-panel-body="logs"]');
            if (!body) return;
            body.innerHTML = '<div class="gs-hosting__loading">Loading log tail…</div>';
            post('gs_hosting_logs').then(function(resp){
                if (resp && resp.success && resp.data) {
                    logState.entries = resp.data.entries || [];
                    if (resp.data.warning) {
                        feedback('logs', resp.data.warning, 'error');
                    } else {
                        feedback('logs', '', null);
                    }
                    renderLogs();
                } else {
                    body.innerHTML = '<p style="color:var(--gs-muted,#94a3b8); padding:14px;">' +
                        ((resp && resp.data && resp.data.message) || 'No log entries available.') + '</p>';
                }
            }).catch(function(){
                body.innerHTML = '<p style="color:#fca5a5; padding:14px;">Network error loading logs.</p>';
            });
        }
        function renderLogs() {
            var body = root.querySelector('[data-gs-hosting-panel-body="logs"]');
            if (!body) return;
            var q = logState.q.toLowerCase();
            var sev = logState.severity;
            var rx = null;
            try { rx = q && q.length > 2 ? new RegExp(q, 'i') : null; } catch(e){}
            var rows = logState.entries.filter(function(e){
                if (sev !== 'all' && e.severity !== sev) return false;
                if (q) {
                    if (rx) return rx.test(e.message);
                    return e.message.toLowerCase().indexOf(q) !== -1;
                }
                return true;
            });
            if (!rows.length) {
                body.innerHTML = '<p style="color:var(--gs-muted,#94a3b8); padding:14px; text-align:center;">No entries match.</p>';
                return;
            }
            body.innerHTML = rows.map(function(e){
                var cls = 'gs-hosting__log-entry';
                if (e.severity === 'error') cls += ' is-err';
                else if (e.severity === 'warning') cls += ' is-warn';
                var safeMsg = escapeHtml(e.message);
                return '<div class="' + cls + '">' +
                    '<span class="gs-hosting__log-meta">' + escapeHtml(e.timestamp || '') + ' [' + escapeHtml(e.source || '') + ']</span>' +
                    '<span class="gs-hosting__log-text">' + safeMsg + '</span>' +
                    '<button type="button" class="gs-hosting__log-copy" data-copy="' + encodeURIComponent(e.message) + '" title="Copy entry to clipboard for Brain">Copy</button>' +
                    '</div>';
            }).join('');
        }
        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }
        root.addEventListener('input', function(e){
            if (e.target.matches('[data-gs-hosting-logs-search]')) {
                logState.q = e.target.value || '';
                renderLogs();
            }
            if (e.target.matches('[data-gs-hosting-tables-search]')) {
                var v = (e.target.value || '').toLowerCase().trim();
                root.querySelectorAll('#gs-hosting-tables-table tbody tr').forEach(function(tr){
                    tr.style.display = (!v || tr.dataset.search.indexOf(v) !== -1) ? '' : 'none';
                });
            }
            if (e.target.matches('[data-gs-hosting-backups-search]')) {
                applyBackupsFilter();
            }
        });
        root.addEventListener('change', function(e){
            if (e.target.matches('[data-gs-hosting-logs-severity]')) {
                logState.severity = e.target.value;
                renderLogs();
            }
            if (e.target.matches('[data-gs-hosting-backups-kind]')) {
                applyBackupsFilter();
            }
        });

        // ── Backups filter (search + kind) ──
        function applyBackupsFilter() {
            var qEl = root.querySelector('[data-gs-hosting-backups-search]');
            var kEl = root.querySelector('[data-gs-hosting-backups-kind]');
            var q = qEl ? (qEl.value || '').toLowerCase().trim() : '';
            var kind = kEl ? kEl.value : 'all';
            var rows = root.querySelectorAll('#gs-hosting-backups-table tbody tr[data-backup-id]');
            var visible = 0;
            rows.forEach(function(tr){
                var matchKind = kind === 'all' || tr.dataset.kind === kind;
                var matchQ = !q || (tr.dataset.search || '').indexOf(q) !== -1;
                var show = matchKind && matchQ;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            // Hide the original empty-state row when filtering an existing list.
            var emptyRow = root.querySelector('#gs-hosting-backups-table tbody tr[data-empty="1"]');
            if (emptyRow) emptyRow.style.display = rows.length === 0 ? '' : 'none';
            var emptyMsg = root.querySelector('#gs-hosting-backups-empty');
            if (emptyMsg) emptyMsg.style.display = (rows.length > 0 && visible === 0) ? 'block' : 'none';
        }
        root.addEventListener('click', function(e){
            var btn = e.target.closest('[data-gs-hosting="logs-refresh"]');
            if (btn) { loadLogs(); }
            var copy = e.target.closest('.gs-hosting__log-copy');
            if (copy) {
                var txt = decodeURIComponent(copy.dataset.copy || '');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(txt).then(function(){
                        feedback('logs', 'Copied. Paste into the Brain.', 'success');
                    }).catch(function(){
                        feedback('logs', 'Copy failed — select manually.', 'error');
                    });
                } else {
                    feedback('logs', 'Clipboard unavailable in this browser.', 'error');
                }
            }
            var backupNow = e.target.closest('[data-gs-hosting="backup-now"]');
            if (backupNow) {
                backupNow.disabled = true;
                var prevBN = backupNow.textContent;
                backupNow.textContent = 'Backing up…';
                feedback('backups', '', null);
                post('gs_membership_backup_now').then(function(resp){
                    if (resp && resp.success) {
                        feedback('backups', 'Backup started. Reloading list…', 'success');
                        setTimeout(function(){ location.reload(); }, 900);
                    } else {
                        backupNow.disabled = false;
                        backupNow.textContent = prevBN;
                        feedback('backups', (resp && resp.data && resp.data.message) || 'Backup failed.', 'error');
                    }
                }).catch(function(){
                    backupNow.disabled = false;
                    backupNow.textContent = prevBN;
                    feedback('backups', 'Network error.', 'error');
                });
                return;
            }
            var restore = e.target.closest('[data-gs-hosting="backup-restore"]');
            if (restore) {
                var bid = parseInt(restore.dataset.id, 10);
                if (!bid) return;
                var msg = 'Restore from ' + (restore.dataset.kind || 'backup') +
                          ' (' + (restore.dataset.created || '#' + bid) + ')? ' +
                          'A pre-restore backup runs automatically before the rollback.';
                if (!confirm(msg)) return;
                restore.disabled = true;
                var prevRS = restore.textContent;
                restore.textContent = 'Restoring…';
                feedback('backups', '', null);
                post('gs_membership_backup_restore', { backup_id: bid }).then(function(resp){
                    if (resp && resp.success) {
                        feedback('backups', 'Restore initiated. The install will reload once complete.', 'success');
                    } else {
                        restore.disabled = false;
                        restore.textContent = prevRS;
                        feedback('backups', (resp && resp.data && resp.data.message) || 'Restore failed.', 'error');
                    }
                }).catch(function(){
                    restore.disabled = false;
                    restore.textContent = prevRS;
                    feedback('backups', 'Network error.', 'error');
                });
                return;
            }

            var rescan = e.target.closest('[data-gs-hosting="media-rescan"]');
            if (rescan) {
                rescan.disabled = true;
                var prev = rescan.textContent;
                rescan.textContent = 'Scanning…';
                feedback('media', '', null);
                post('gs_hosting_media_rescan').then(function(resp){
                    if (resp && resp.success) {
                        feedback('media', 'Rescan complete. Reloading…', 'success');
                        setTimeout(function(){ location.reload(); }, 700);
                    } else {
                        rescan.disabled = false;
                        rescan.textContent = prev;
                        feedback('media', (resp && resp.data && resp.data.message) || 'Rescan failed.', 'error');
                    }
                }).catch(function(){
                    rescan.disabled = false;
                    rescan.textContent = prev;
                    feedback('media', 'Network error.', 'error');
                });
            }
        });

        // ── Tables explorer modal ──
        var $tblModal = document.getElementById('gs-hosting-tables-modal');
        if ($tblModal && $tblModal.parentNode !== document.body) {
            document.body.appendChild($tblModal);
        }
        // Tab switching — full-width panes. Default = list.
        function setTablesTab(which) {
            if (!$tblModal) return;
            $tblModal.querySelectorAll('.gs-hosting-tables-modal__tab').forEach(function(b){
                var on = b.dataset.gsTablesTab === which;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            $tblModal.querySelectorAll('.gs-hosting-tables-modal__pane').forEach(function(p){
                var on = p.dataset.gsTablesPane === which;
                p.classList.toggle('is-active', on);
                if (on) {
                    p.removeAttribute('hidden');
                } else {
                    p.setAttribute('hidden', '');
                }
            });
        }
        function openTablesModal() {
            if (!$tblModal) return;
            $tblModal.removeAttribute('hidden');
            $tblModal.setAttribute('aria-hidden', 'false');
            $tblModal.classList.add('is-open');
            document.body.classList.add('gs-hosting-tables-open');
            // Always land on the Tables tab when the modal opens.
            setTablesTab('list');
        }
        function closeTablesModal() {
            if (!$tblModal) return;
            $tblModal.classList.remove('is-open');
            $tblModal.setAttribute('hidden', '');
            $tblModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('gs-hosting-tables-open');
        }
        document.addEventListener('click', function(e){
            if (e.target.closest('#gs-hosting-tables-launch')) {
                e.preventDefault();
                openTablesModal();
                return;
            }
            if (e.target.closest('[data-gs-tables-dismiss]')) {
                e.preventDefault();
                closeTablesModal();
                return;
            }
            // Tab strip — Tables / Queries.
            var tabBtn = e.target.closest('[data-gs-tables-tab]');
            if (tabBtn) {
                e.preventDefault();
                setTablesTab(tabBtn.dataset.gsTablesTab);
                return;
            }
            // Recipe chip click — pre-fill the natural-language input AND
            // stash the fallback SQL so "Find It" can use it without an AI
            // round-trip if needed.
            var recipe = e.target.closest('.gs-hosting-recipe');
            if (recipe) {
                e.preventDefault();
                var $ask = document.getElementById('gs-hosting-ask-input');
                var $sql = document.getElementById('gs-hosting-query-input');
                if ($ask) {
                    $ask.value = recipe.dataset.ask || '';
                    $ask.dataset.recipeSql = recipe.dataset.sql || '';
                }
                if ($sql) {
                    $sql.value = recipe.dataset.sql || '';
                }
                // Recipe chips live in the Queries pane already, but jump
                // there just in case the chip was triggered from elsewhere.
                setTablesTab('query');
                runAsk();
                return;
            }
            if (e.target.closest('#gs-hosting-ask-run')) {
                e.preventDefault();
                runAsk();
                return;
            }
            var browse = e.target.closest('[data-gs-tables-browse]');
            if (browse) {
                e.preventDefault();
                var tableName = browse.dataset.gsTablesBrowse;
                if (!tableName) return;
                var $input = document.getElementById('gs-hosting-query-input');
                if ($input) {
                    $input.value = 'SELECT * FROM `' + tableName.replace(/`/g, '') + '` LIMIT 50;';
                }
                // Switch to the Queries tab so the result lands where the
                // user can see it.
                setTablesTab('query');
                runQuery();
                return;
            }
            if (e.target.closest('#gs-hosting-query-run')) {
                e.preventDefault();
                runQuery();
                return;
            }
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && $tblModal && $tblModal.classList.contains('is-open')) {
                closeTablesModal();
            }
        });
        // Search inside the modal — bound on document since the modal lives
        // outside `root` after the portal move above.
        document.addEventListener('input', function(e){
            if (e.target.matches('[data-gs-hosting-tables-search]')) {
                var v = (e.target.value || '').toLowerCase().trim();
                document.querySelectorAll('#gs-hosting-tables-table tbody tr').forEach(function(tr){
                    tr.style.display = (!v || (tr.dataset.search || '').indexOf(v) !== -1) ? '' : 'none';
                });
            }
        });
        // Cmd/Ctrl+Enter inside the SQL textarea runs the query.
        document.addEventListener('keydown', function(e){
            if (e.target && e.target.id === 'gs-hosting-query-input' && (e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                e.preventDefault();
                runQuery();
            }
        });

        function escHtml(s) {
            if (s == null) return '';
            return String(s).replace(/[&<>"\']/g, function(c){
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }
        function runQuery() {
            var $input = document.getElementById('gs-hosting-query-input');
            var $results = document.getElementById('gs-hosting-query-results');
            var $meta = document.getElementById('gs-hosting-query-meta');
            var $btn = document.getElementById('gs-hosting-query-run');
            if (!$input || !$results) return;
            var sql = ($input.value || '').trim();
            if (!sql) { $results.innerHTML = '<div class="is-err">Enter a query first.</div>'; return; }
            // Confirm on destructive verbs (cheap heuristic — server-side
            // accepts any query for manage_options admins regardless).
            var verb = sql.replace(/^[\s;]+/, '').split(/\s+/)[0].toUpperCase();
            var destructive = ['DROP', 'DELETE', 'UPDATE', 'TRUNCATE', 'ALTER', 'RENAME'];
            if (destructive.indexOf(verb) !== -1) {
                if (!confirm(verb + ' query will modify data on the live database. Continue?')) return;
            }
            $btn.disabled = true;
            var prev = $btn.innerHTML;
            $btn.innerHTML = 'Running…';
            $results.innerHTML = '<div class="is-loading">Running…</div>';
            if ($meta) $meta.textContent = '';
            var t0 = performance.now();
            post('gs_hosting_query_run', { sql: sql }).then(function(resp){
                var elapsed = Math.round(performance.now() - t0);
                if (resp && resp.success && resp.data) {
                    renderQueryResult(resp.data, elapsed);
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Query failed.';
                    $results.innerHTML = '<div class="is-err">' + escHtml(msg) + '</div>';
                    if ($meta) $meta.textContent = elapsed + ' ms · failed';
                }
            }).catch(function(){
                $results.innerHTML = '<div class="is-err">Network error.</div>';
            }).finally(function(){
                $btn.disabled = false;
                $btn.innerHTML = prev;
            });
        }
        function renderQueryResult(data, elapsed, opts) {
            opts = opts || {};
            var $results = document.getElementById('gs-hosting-query-results');
            var $meta = document.getElementById('gs-hosting-query-meta');
            if (data.kind === 'select') {
                var rows = Array.isArray(data.rows) ? data.rows : [];
                var cols = data.columns || (rows[0] ? Object.keys(rows[0]) : []);

                // Single-row, single-column SELECT (most "count" / "how many"
                // questions) → render as a big number instead of a 1×1 table.
                if (rows.length === 1 && cols.length === 1) {
                    var val = rows[0][cols[0]];
                    var label = opts.askText || cols[0];
                    $results.innerHTML = '<div class="gs-result-single">' +
                        '<span class="num">' + escHtml(val) + '</span>' +
                        '<span class="lbl">' + escHtml(label) + '</span>' +
                        '</div>';
                } else if (rows.length === 0) {
                    $results.innerHTML = '<div class="is-ok">No results found.</div>';
                } else {
                    var html = '';
                    if (opts.askText) {
                        html += '<div style="padding:10px 12px 0; color:var(--gs-muted, #94a3b8); font-size:0.8rem;">' +
                            'Showing ' + rows.length + ' result' + (rows.length === 1 ? '' : 's') + ' for: ' +
                            '<em style="color:#cbd5f5;">' + escHtml(opts.askText) + '</em>' +
                            '</div>';
                    }
                    html += '<table><thead><tr>';
                    cols.forEach(function(c){ html += '<th>' + escHtml(c) + '</th>'; });
                    html += '</tr></thead><tbody>';
                    rows.forEach(function(r){
                        html += '<tr>';
                        cols.forEach(function(c){
                            var v = (r && r[c] != null) ? r[c] : '';
                            html += '<td title="' + escHtml(v) + '">' + escHtml(v) + '</td>';
                        });
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    $results.innerHTML = html;
                }
                if ($meta) $meta.textContent = elapsed + ' ms · ' + rows.length + ' row' + (rows.length === 1 ? '' : 's');
            } else if (data.kind === 'modify') {
                $results.innerHTML = '<div class="is-ok">Query OK — ' + (data.affected != null ? data.affected : 0) + ' row(s) affected.</div>';
                if ($meta) $meta.textContent = elapsed + ' ms · ' + (data.affected != null ? data.affected : 0) + ' affected';
            } else {
                $results.innerHTML = '<div class="is-ok">Query executed.</div>';
                if ($meta) $meta.textContent = elapsed + ' ms';
            }
        }

        // "Find It" — turn the plain-English question into a query and run
        // it. Strategy:
        //   1. If a recipe chip was just clicked, prefer its known-good SQL
        //      (instant, no AI round-trip).
        //   2. Otherwise ask LEO via POST /wp-json/gs/v1/ai/chat to generate
        //      SQL, populate the SQL textarea (so the user can review/edit),
        //      then run it.
        //   3. If the AI helper isn't reachable, the SQL textarea stays open
        //      so the advanced user can write the query directly.
        function runAsk() {
            var $ask = document.getElementById('gs-hosting-ask-input');
            var $askBtn = document.getElementById('gs-hosting-ask-run');
            var $askMeta = document.getElementById('gs-hosting-ask-meta');
            var $sql = document.getElementById('gs-hosting-query-input');
            var $results = document.getElementById('gs-hosting-query-results');
            if (!$ask || !$results) return;
            var question = ($ask.value || '').trim();
            if (!question) {
                $results.innerHTML = '<div class="is-err">Type what you\'re looking for first.</div>';
                return;
            }

            // Path 1: recipe SQL — use it as-is, no AI hop needed.
            var recipeSql = $ask.dataset.recipeSql || '';
            if (recipeSql && $sql && $sql.value && $sql.value.trim() === recipeSql.trim()) {
                $results.innerHTML = '<div class="is-loading">Looking up the answer…</div>';
                if ($askMeta) $askMeta.textContent = '';
                runSqlForAsk(recipeSql, question);
                return;
            }

            $askBtn.disabled = true;
            var prev = $askBtn.innerHTML;
            $askBtn.innerHTML = 'Asking LEO…';
            $results.innerHTML = '<div class="is-loading">Translating your question…</div>';
            if ($askMeta) $askMeta.textContent = '';

            // Path 2: ask LEO to translate. The hub's /aipa/v1/ai-proxy
            // accepts a chat-completions style payload; we send a system
            // prompt with the table-prefix hint and a strict "SQL only"
            // instruction so the response is directly executable.
            var schemaHint = (window.gsHostingTableNames && window.gsHostingTableNames.length)
                ? '\nKnown tables on this install (use these exact names, do not invent):\n' + window.gsHostingTableNames.slice(0, 60).join(', ')
                : '';
            var system = 'You are a read-only SQL assistant for a WordPress / MariaDB database. '
                + 'Generate ONE MySQL/MariaDB SELECT (or SHOW/EXPLAIN) statement that answers the user\'s question. '
                + 'Return ONLY the SQL — no markdown fences, no commentary, no leading "SQL:" prefix. '
                + 'Prefer simple, readable queries. Always include LIMIT 100 unless the user asked for a total/count.'
                + schemaHint;

            fetch((window.location.origin || '') + '/wp-json/gs/v1/ai/chat', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (window.wpApiSettings && window.wpApiSettings.nonce) || '' },
                body: JSON.stringify({
                    model: 'gemini-2.0-flash',
                    messages: [
                        { role: 'system', content: system },
                        { role: 'user', content: question }
                    ]
                })
            }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, body: j }; }); })
              .then(function(resp){
                var generated = extractSqlFromAiResponse(resp.body);
                if (!resp.ok || !generated) {
                    var fb = $ask.dataset.recipeSql || '';
                    if (fb) {
                        if ($sql) $sql.value = fb;
                        if ($askMeta) $askMeta.textContent = 'LEO unavailable — running the template instead.';
                        runSqlForAsk(fb, question);
                    } else {
                        $results.innerHTML = '<div class="is-err">LEO could not turn that into a query. Try rewording, pick a quick-pick chip, or open the SQL editor below to write it yourself.</div>';
                        var details = document.getElementById('gs-hosting-query-details');
                        if (details) details.open = true;
                    }
                    return;
                }
                if ($sql) $sql.value = generated;
                if ($askMeta) $askMeta.textContent = 'LEO wrote a query — running it…';
                runSqlForAsk(generated, question);
            }).catch(function(){
                var fb = $ask.dataset.recipeSql || '';
                if (fb) {
                    if ($sql) $sql.value = fb;
                    if ($askMeta) $askMeta.textContent = 'Network error — running the template instead.';
                    runSqlForAsk(fb, question);
                } else {
                    $results.innerHTML = '<div class="is-err">Network error reaching LEO. Try a quick-pick or open the SQL editor.</div>';
                }
            }).finally(function(){
                $askBtn.disabled = false;
                $askBtn.innerHTML = prev;
            });
        }
        function extractSqlFromAiResponse(body) {
            if (!body) return '';
            // Common shapes from the hub forwarder.
            var text = '';
            if (typeof body === 'string') text = body;
            else if (body.text)    text = body.text;
            else if (body.reply)   text = body.reply;
            else if (body.content) text = body.content;
            else if (body.choices && body.choices[0]) {
                var c = body.choices[0];
                text = (c.message && c.message.content) || c.text || '';
            }
            else if (body.message && body.message.content) text = body.message.content;
            if (!text) return '';
            // Strip markdown fences if the model included them.
            text = String(text).replace(/^```(?:sql)?\s*/i, '').replace(/```\s*$/i, '').trim();
            // Strip a "SQL:" prefix if present.
            text = text.replace(/^SQL\s*:\s*/i, '').trim();
            // Only return SELECT/SHOW/EXPLAIN/DESCRIBE/WITH for safety —
            // anything else and we surface the raw text so the user reviews
            // before running. (The runner itself will accept anything from
            // an admin, but the auto-run path is conservative.)
            return text;
        }
        function runSqlForAsk(sql, askText) {
            var $results = document.getElementById('gs-hosting-query-results');
            var $askMeta = document.getElementById('gs-hosting-ask-meta');
            $results.innerHTML = '<div class="is-loading">Looking up the answer…</div>';
            var t0 = performance.now();
            post('gs_hosting_query_run', { sql: sql }).then(function(resp){
                var elapsed = Math.round(performance.now() - t0);
                if (resp && resp.success && resp.data) {
                    renderQueryResult(resp.data, elapsed, { askText: askText });
                    if ($askMeta) $askMeta.textContent = 'Done in ' + elapsed + ' ms.';
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Query failed.';
                    $results.innerHTML = '<div class="is-err">' + escHtml(msg) + '</div>';
                    if ($askMeta) $askMeta.textContent = 'Failed.';
                }
            }).catch(function(){
                $results.innerHTML = '<div class="is-err">Network error running the query.</div>';
            });
        }
    })();
    </script>
    <?php
}

// -------------------------------------------------------------------------
// Local data collectors (used both for initial render and AJAX rescans)
// -------------------------------------------------------------------------

/**
 * SHOW TABLE STATUS — returns row count, data/index size per table plus
 * aggregate stats. Cheap on most installs (< 50ms for hundreds of tables).
 */
function gs_hosting_collect_tables() {
    global $wpdb;
    $out = array(
        'tables' => array(),
        'count' => 0,
        'total_bytes' => 0,
        'total_rows' => 0,
        'largest_name' => '',
        'largest_bytes' => 0,
    );
    $rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
    if ( ! is_array( $rows ) ) {
        return $out;
    }
    foreach ( $rows as $r ) {
        $data  = (int) ( $r['Data_length']  ?? 0 );
        $index = (int) ( $r['Index_length'] ?? 0 );
        $total = $data + $index;
        $rows_count = (int) ( $r['Rows'] ?? 0 );
        $out['tables'][] = array(
            'name'        => (string) ( $r['Name'] ?? '' ),
            'engine'      => (string) ( $r['Engine'] ?? '' ),
            'rows'        => $rows_count,
            'data_bytes'  => $data,
            'index_bytes' => $index,
            'total_bytes' => $total,
        );
        $out['count']++;
        $out['total_bytes'] += $total;
        $out['total_rows']  += $rows_count;
        if ( $total > $out['largest_bytes'] ) {
            $out['largest_bytes'] = $total;
            $out['largest_name']  = (string) ( $r['Name'] ?? '' );
        }
    }
    // Sort by total size descending.
    usort( $out['tables'], function ( $a, $b ) {
        return $b['total_bytes'] - $a['total_bytes'];
    } );
    return $out;
}

/**
 * Media (uploads/) usage. Cached in a transient because a deep recursive
 * dir scan is slow on installs with thousands of attachments. Rescan via
 * gs_hosting_media_rescan AJAX.
 */
function gs_hosting_collect_media( $force_rescan = false ) {
    $key   = 'gs_hosting_media_usage';
    $cache = $force_rescan ? false : get_transient( $key );
    if ( is_array( $cache ) ) {
        return $cache;
    }
    $uploads_dir = wp_upload_dir();
    $base = isset( $uploads_dir['basedir'] ) ? (string) $uploads_dir['basedir'] : '';
    $bytes = 0;
    $files = 0;
    if ( $base !== '' && is_dir( $base ) ) {
        try {
            $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $it as $f ) {
                if ( $f->isFile() ) {
                    $bytes += $f->getSize();
                    $files++;
                }
            }
        } catch ( \Throwable $e ) {
            // Permission or filesystem failure — fall through with whatever we counted.
        }
    }
    $data = array(
        'bytes_used' => $bytes,
        'file_count' => $files,
        'plan_bytes' => gs_hosting_media_plan_bytes(),
        'scanned_at' => time(),
    );
    set_transient( $key, $data, HOUR_IN_SECONDS );
    return $data;
}

/**
 * Build the list of container resources the Containers sub-tab renders.
 * Today this is sourced from local collectors (media dir scan + SHOW TABLE
 * STATUS); when the hub exposes real plan caps and compute usage, swap the
 * caps via the gs_hosting_*_plan_bytes filters and add compute usage here.
 *
 * @param array $media_data  Output of gs_hosting_collect_media()
 * @param array $tables_data Output of gs_hosting_collect_tables()
 * @return array<int,array{slug:string,label:string,icon:string,hint:string,used:int,cap:int,used_label:string,cap_label:string,meta:string}>
 */
function gs_hosting_collect_container_resources( $media_data, $tables_data ) {
    $resources = array();

    // Media storage (uploads PVC)
    $media_used = (int) ( $media_data['bytes_used'] ?? 0 );
    $media_cap  = (int) ( $media_data['plan_bytes'] ?? gs_hosting_media_plan_bytes() );
    $resources[] = array(
        'slug'       => 'media',
        'label'      => __( 'Media Storage', 'gend-society' ),
        'icon'       => 'dashicons-format-image',
        'hint'       => __( 'Uploads PVC — images, video, attachments', 'gend-society' ),
        'used'       => $media_used,
        'cap'        => $media_cap,
        'used_label' => size_format( $media_used, 2 ),
        'cap_label'  => size_format( $media_cap, 0 ),
        'meta'       => sprintf(
            /* translators: 1: file count, 2: relative time since last scan */
            __( '%1$s files · last scanned %2$s ago', 'gend-society' ),
            number_format_i18n( (int) ( $media_data['file_count'] ?? 0 ) ),
            human_time_diff( (int) ( $media_data['scanned_at'] ?? time() ), time() )
        ),
    );

    // Database storage
    $db_used = (int) ( $tables_data['total_bytes'] ?? 0 );
    $db_cap  = gs_hosting_db_plan_bytes();
    $resources[] = array(
        'slug'       => 'database',
        'label'      => __( 'Database Storage', 'gend-society' ),
        'icon'       => 'dashicons-database',
        'hint'       => __( 'MySQL data + indexes', 'gend-society' ),
        'used'       => $db_used,
        'cap'        => $db_cap,
        'used_label' => size_format( $db_used, 2 ),
        'cap_label'  => size_format( $db_cap, 0 ),
        'meta'       => sprintf(
            /* translators: 1: table count, 2: row count */
            __( '%1$s tables · %2$s rows', 'gend-society' ),
            number_format_i18n( (int) ( $tables_data['count'] ?? 0 ) ),
            number_format_i18n( (int) ( $tables_data['total_rows'] ?? 0 ) )
        ),
    );

    // Compute (CPU / RAM). No reliable local source — hub-side fills this
    // in when the route ships. Stub keeps the row visible with 0 / cap.
    $compute_cap = (int) apply_filters( 'gs_hosting_compute_plan_minutes', 60 * 24 * 30 ); // 30 days CPU-minutes default cap
    $resources[] = array(
        'slug'       => 'compute',
        'label'      => __( 'Compute (CPU · RAM)', 'gend-society' ),
        'icon'       => 'dashicons-performance',
        'hint'       => __( 'Container CPU-minutes consumed this billing period', 'gend-society' ),
        'used'       => 0,
        'cap'        => $compute_cap,
        'used_label' => __( 'Reporting pending', 'gend-society' ),
        'cap_label'  => sprintf( __( '%s CPU-min', 'gend-society' ), number_format_i18n( $compute_cap ) ),
        'meta'       => __( 'Live compute reporting is wired to the hub — values populate once the install\'s metrics endpoint is provisioned.', 'gend-society' ),
    );

    /**
     * Append / mutate the container resource list. Hub-side integration can
     * push real plan caps and compute usage in here.
     *
     * @param array $resources
     */
    return (array) apply_filters( 'gs_hosting_container_resources', $resources );
}

function gs_hosting_db_plan_bytes() {
    // Default 5 GB. Future: pull from membership payload `plan->db_quota_bytes`.
    return (int) apply_filters( 'gs_hosting_db_plan_bytes', 5 * 1024 * 1024 * 1024 );
}

function gs_hosting_media_plan_bytes() {
    // Default 20 GB.
    return (int) apply_filters( 'gs_hosting_media_plan_bytes', 20 * 1024 * 1024 * 1024 );
}

function gs_hosting_pct( $used, $cap ) {
    if ( $cap <= 0 ) return 0;
    $pct = ( $used / $cap ) * 100;
    if ( $pct < 0 ) return 0;
    if ( $pct > 100 ) return 100;
    return $pct;
}

function gs_hosting_plan_label( $billing ) {
    if ( ! is_array( $billing ) ) return __( 'Default', 'gend-society' );
    if ( ! empty( $billing['label'] ) ) return (string) $billing['label'];
    return __( 'Default', 'gend-society' );
}

function gs_hosting_render_status_pill( $status ) {
    $status = strtolower( (string) $status );
    if ( in_array( $status, array( 'ok', 'verified', 'active', 'live', 'true', '1' ), true ) ) {
        return '<span class="gs-hosting__pill is-ok">' . esc_html__( 'OK', 'gend-society' ) . '</span>';
    }
    if ( in_array( $status, array( 'fail', 'error', 'invalid', 'false', '0' ), true ) ) {
        return '<span class="gs-hosting__pill is-err">' . esc_html__( 'Failing', 'gend-society' ) . '</span>';
    }
    return '<span class="gs-hosting__pill is-warn">' . esc_html__( 'Pending', 'gend-society' ) . '</span>';
}

/**
 * Read the WP debug.log + the PHP-FPM error log (whichever ini_get('error_log')
 * points at) and return the last N entries, normalized to { timestamp,
 * severity, source, message }. Best-effort — returns what it can read,
 * surfaces warnings for the sources it couldn't.
 */
function gs_hosting_read_logs( $limit = 200 ) {
    $entries = array();
    $warnings = array();

    $sources = array();
    if ( defined( 'WP_CONTENT_DIR' ) ) {
        $sources[] = array( 'path' => trailingslashit( WP_CONTENT_DIR ) . 'debug.log', 'label' => 'debug.log' );
    }
    $php_log = ini_get( 'error_log' );
    if ( $php_log && $php_log !== 'syslog' && $php_log !== '' ) {
        $sources[] = array( 'path' => $php_log, 'label' => 'php' );
    }

    foreach ( $sources as $s ) {
        if ( ! is_readable( $s['path'] ) ) {
            $warnings[] = sprintf( 'Could not read %s (does not exist or unreadable).', $s['label'] );
            continue;
        }
        $size = @filesize( $s['path'] );
        if ( $size === false ) continue;
        // Tail the last ~512 KB so we don't slurp gigantic logs into memory.
        $read_bytes = min( $size, 512 * 1024 );
        $fp = @fopen( $s['path'], 'rb' );
        if ( ! $fp ) continue;
        if ( $size > $read_bytes ) {
            fseek( $fp, $size - $read_bytes );
            fgets( $fp ); // Discard partial first line.
        }
        $buf = stream_get_contents( $fp );
        fclose( $fp );
        $lines = preg_split( "/\r?\n/", (string) $buf );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $entries[] = gs_hosting_parse_log_line( $line, $s['label'] );
        }
    }

    // Sort newest first by timestamp string (lexicographic on ISO ish format
    // matches chronological for our purposes — debug.log is `[01-Jan-2026 ...]`,
    // php-fpm is `[01-Jan-2026 12:34:56]`). Keep original order on ties.
    usort( $entries, function ( $a, $b ) {
        return strcmp( $b['timestamp'], $a['timestamp'] );
    } );

    return array(
        'entries' => array_slice( $entries, 0, $limit ),
        'warning' => $warnings ? implode( ' ', $warnings ) : '',
    );
}

function gs_hosting_parse_log_line( $line, $source ) {
    $ts = '';
    $msg = $line;
    // Match leading [date time] bracketed prefix.
    if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/', $line, $m ) ) {
        $ts  = $m[1];
        $msg = $m[2];
    }
    $sev = 'notice';
    $l   = strtolower( $line );
    if ( strpos( $l, 'fatal' ) !== false || strpos( $l, 'error' ) !== false || strpos( $l, 'parse error' ) !== false || strpos( $l, 'uncaught' ) !== false ) {
        $sev = 'error';
    } elseif ( strpos( $l, 'warning' ) !== false ) {
        $sev = 'warning';
    } elseif ( strpos( $l, 'deprecated' ) !== false || strpos( $l, 'notice' ) !== false ) {
        $sev = 'notice';
    }
    return array(
        'timestamp' => $ts,
        'severity'  => $sev,
        'source'    => $source,
        'message'   => $msg,
    );
}

// -------------------------------------------------------------------------
// AJAX endpoints — proxy to gend.me's install/{id}/hosting/* with local
// fallbacks where possible.
// -------------------------------------------------------------------------

if ( ! function_exists( 'gs_hosting_ajax_authorize' ) ) {
    function gs_hosting_ajax_authorize() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
        }
        check_ajax_referer( 'gs_membership_action', 'nonce' );
    }
}

/**
 * Try a hub call. If the install isn't paired or the hub doesn't have the
 * route yet, return the local fallback (when provided) or surface the
 * error to the client.
 */
function gs_hosting_call_or_fallback( $path, $body, $local_fallback = null, $body_method = 'POST' ) {
    if ( function_exists( 'gs_remote_membership_call' ) ) {
        $r = gs_remote_membership_call( $path, $body, $body_method );
        if ( ! is_wp_error( $r ) ) {
            return $r;
        }
        // Fall through to local if available, else propagate error.
    }
    if ( is_callable( $local_fallback ) ) {
        return call_user_func( $local_fallback );
    }
    return new WP_Error( 'unavailable', __( 'This hosting action is not yet available on this install.', 'gend-society' ) );
}

add_action( 'wp_ajax_gs_hosting_cache_page', function () {
    gs_hosting_ajax_authorize();
    $r = gs_hosting_call_or_fallback( 'hosting/cache-page', array(), function () {
        // Local fallback: no real page cache on this install — at least
        // tell the truth so the user doesn't think it ran.
        return new WP_Error( 'no_page_cache', __( 'No edge page cache is bound to this install.', 'gend-society' ) );
    } );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( is_array( $r ) ? $r : array() );
} );

add_action( 'wp_ajax_gs_hosting_cache_object', function () {
    gs_hosting_ajax_authorize();
    // Local fallback is genuinely useful here: wp_cache_flush() clears
    // whatever object cache backend WP is wired to (Redis/Memcached/etc.).
    $r = gs_hosting_call_or_fallback( 'hosting/cache-object', array(), function () {
        if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
        if ( function_exists( 'wp_cache_flush_runtime' ) ) wp_cache_flush_runtime();
        return array( 'message' => __( 'Object cache flushed locally.', 'gend-society' ) );
    } );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( is_array( $r ) ? $r : array() );
} );

add_action( 'wp_ajax_gs_hosting_template_reset', function () {
    gs_hosting_ajax_authorize();
    $r = gs_hosting_call_or_fallback( 'hosting/template-reset', array(), null );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( is_array( $r ) ? $r : array() );
} );

add_action( 'wp_ajax_gs_hosting_toggles_get', function () {
    gs_hosting_ajax_authorize();
    $r = gs_hosting_call_or_fallback( 'hosting/toggles', array(), function () {
        // Local fallback: use WP options as a stand-in store so the toggles
        // are at least persistent on this side until the hub side ships.
        return array(
            'waf'      => (bool) get_option( 'gs_hosting_waf_enabled' ),
            'password' => (bool) get_option( 'gs_hosting_password_enabled' ),
            'bfa'      => (bool) get_option( 'gs_hosting_bfa_enabled' ),
        );
    }, 'GET' );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( is_array( $r ) ? $r : array() );
} );

add_action( 'wp_ajax_gs_hosting_toggle_set', function () {
    gs_hosting_ajax_authorize();
    $feature = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( $_POST['feature'] ) ) : '';
    $enabled = ! empty( $_POST['enabled'] );
    $allowed = array( 'waf', 'password', 'bfa' );
    if ( ! in_array( $feature, $allowed, true ) ) {
        wp_send_json_error( array( 'message' => __( 'Unknown feature.', 'gend-society' ) ) );
    }
    $r = gs_hosting_call_or_fallback( 'hosting/toggles/' . $feature, array( 'enabled' => $enabled ? 1 : 0 ), function () use ( $feature, $enabled ) {
        // Mirror in WP options so the panel reflects state even when the
        // hub-side route isn't there yet.
        update_option( 'gs_hosting_' . $feature . '_enabled', $enabled ? 1 : 0 );
        return array( 'enabled' => $enabled );
    } );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( is_array( $r ) ? $r : array() );
} );

add_action( 'wp_ajax_gs_hosting_compute_gas', function () {
    gs_hosting_ajax_authorize();
    $r = gs_hosting_call_or_fallback( 'hosting/compute-gas', array(), function () {
        return array(
            'period'                => __( 'This billing period', 'gend-society' ),
            'gas_fees_label'        => '$0.00',
            'container_fees_label'  => '$0.00',
            'total_label'           => '$0.00',
            'breakdown'             => array(),
            'message'               => __( 'Compute Gas reporting not yet enabled on this install.', 'gend-society' ),
        );
    }, 'GET' );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( is_array( $r ) ? $r : array() );
} );

add_action( 'wp_ajax_gs_hosting_logs', function () {
    gs_hosting_ajax_authorize();
    // Logs always read locally — the container's logs ARE the local logs.
    $r = gs_hosting_read_logs( 200 );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_hosting_media_rescan', function () {
    gs_hosting_ajax_authorize();
    delete_transient( 'gs_hosting_media_usage' );
    $data = gs_hosting_collect_media( true );
    wp_send_json_success( $data );
} );

/**
 * AJAX: run a raw SQL query against the app database from the Tables modal.
 *
 * Gated on manage_options + the gs_membership_action nonce — same trust
 * level as Tools → Database in vanilla WP, just with a nicer UI. SELECT-y
 * queries return rows + column names; INSERT/UPDATE/DELETE/etc. return
 * affected-row count. Errors are reported with $wpdb->last_error.
 *
 * Result rows cap at 500 so a `SELECT * FROM big_table` doesn't OOM the
 * pod; the SQL is also wrapped in a defensive 200kb length cap.
 */
add_action( 'wp_ajax_gs_hosting_query_run', function () {
    gs_hosting_ajax_authorize();
    global $wpdb;

    $sql = isset( $_POST['sql'] ) ? (string) wp_unslash( $_POST['sql'] ) : '';
    $sql = trim( $sql, " \t\n\r\0\x0B;" );
    if ( $sql === '' ) {
        wp_send_json_error( array( 'message' => __( 'No query provided.', 'gend-society' ) ) );
    }
    if ( strlen( $sql ) > 200 * 1024 ) {
        wp_send_json_error( array( 'message' => __( 'Query too long (200 KB limit).', 'gend-society' ) ) );
    }

    // Heuristic verb sniff to decide how to handle the result. The verb
    // before any leading paren/comment determines whether $wpdb->get_results
    // (rows) or $wpdb->query (affected count) is the right call.
    if ( ! preg_match( '/^\s*(\(|\/\*[^*]*\*\/|--[^\n]*\n|\s)*([A-Za-z]+)/', $sql, $m ) ) {
        wp_send_json_error( array( 'message' => __( 'Could not parse query verb.', 'gend-society' ) ) );
    }
    $verb = strtoupper( $m[2] );
    $is_read = in_array( $verb, array( 'SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'WITH', 'PRAGMA', 'TABLE', 'VALUES' ), true );

    // Suppress $wpdb error-print so we never leak unstyled HTML into the JSON.
    $prev_show = isset( $wpdb->show_errors ) ? $wpdb->show_errors : false;
    $wpdb->show_errors = false;
    $prev_suppress = $wpdb->suppress_errors( true );

    if ( $is_read ) {
        // Cap the result set so a runaway `SELECT *` doesn't blow up the
        // response payload. We don't rewrite the query — just slice the
        // returned rows.
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $err  = $wpdb->last_error;
        $wpdb->suppress_errors( $prev_suppress );
        $wpdb->show_errors = $prev_show;

        if ( $err ) {
            wp_send_json_error( array( 'message' => $err, 'kind' => 'select' ) );
        }
        $rows = is_array( $rows ) ? $rows : array();
        $truncated = false;
        if ( count( $rows ) > 500 ) {
            $rows = array_slice( $rows, 0, 500 );
            $truncated = true;
        }
        $columns = $rows ? array_keys( $rows[0] ) : array();

        wp_send_json_success( array(
            'kind'      => 'select',
            'columns'   => $columns,
            'rows'      => $rows,
            'truncated' => $truncated,
            'verb'      => $verb,
        ) );
    }

    $affected = $wpdb->query( $sql );
    $err = $wpdb->last_error;
    $wpdb->suppress_errors( $prev_suppress );
    $wpdb->show_errors = $prev_show;

    if ( $affected === false || $err ) {
        wp_send_json_error( array( 'message' => $err ?: __( 'Query failed.', 'gend-society' ), 'kind' => 'modify' ) );
    }

    wp_send_json_success( array(
        'kind'     => 'modify',
        'affected' => (int) $affected,
        'verb'     => $verb,
    ) );
} );
