<?php
/**
 * Hosting▸Domains sub-panel renderer — Phase 72-02 Connect-a-Domain wizard.
 *
 * Sibling to dashboard-hosting.php; mounts inside the existing Hosting▸Domains
 * sub-panel (sidebar nav data-section="domains" at dashboard-hosting.php:135).
 *
 * Renders:
 *   1. Connected-domains list (from Phase 71 list_zones augmented with
 *      Phase 72-01 import_status + zone_status per row) — bottom of panel.
 *   2. Stale-pending-zone warning banner (if get_stale_pending_zones returns
 *      anything for this install).
 *   3. 4-step wizard scaffold (Step 1 visible by default; Steps 2-4 hidden
 *      until JS transitions).
 *
 * Step 1: Domain entry form (apex-only client-side regex).
 * Step 2: Import review (auto-runs import-records on entry; renders records
 *         table; "I've reviewed" button enables Step 3).
 * Step 3: Nameservers reveal (server returns 409 import_review_required if
 *         step 2 skipped — JS jumps back to Step 2).
 * Step 4: Status polling (15s recursive setTimeout; visibilitychange pause;
 *         24h timeout; auto-flips to "Domain active!" on CF active).
 *
 * @package gend-society
 * @since   v10.0 / Phase 72-02
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the Hosting▸Domains sub-panel.
 *
 * Called from dashboard-hosting.php Domains sub-panel mount-point.
 *
 * @param array $payload Membership payload (passed through from gs_render_hosting_tab).
 * @return void
 */
function gs_render_hosting_domains_panel( $payload = array() ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<p style="color: var(--gs-muted);">' . esc_html__( 'You do not have permission to manage domains.', 'gend-society' ) . '</p>';
        return;
    }

    // Enqueue wizard assets — GS_VERSION + filemtime cache-buster (one bump
    // per project_social_membership_assets convention; the plugin Version:
    // header is bumped in 72-02 Task 1 which cascades to GS_VERSION).
    $js_ver  = defined( 'GS_VERSION' ) ? GS_VERSION : '0';
    $css_ver = $js_ver;
    if ( defined( 'GS_DIR' ) && file_exists( GS_DIR . 'assets/domains-wizard.js' ) ) {
        $js_ver = $js_ver . '.' . filemtime( GS_DIR . 'assets/domains-wizard.js' );
    }
    if ( defined( 'GS_DIR' ) && file_exists( GS_DIR . 'assets/domains-wizard.css' ) ) {
        $css_ver = $css_ver . '.' . filemtime( GS_DIR . 'assets/domains-wizard.css' );
    }
    wp_enqueue_style( 'gs-domains-wizard', GS_URL . 'assets/domains-wizard.css', array(), $css_ver );
    wp_enqueue_script( 'gs-domains-wizard', GS_URL . 'assets/domains-wizard.js', array(), $js_ver, true );
    wp_localize_script(
        'gs-domains-wizard',
        'gsDomainsWizard',
        array(
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'gs_membership_action' ),
            'pollIntervalMs' => 15000,
            'pollTimeoutMs'  => 24 * 60 * 60 * 1000,
            'i18n'           => array(
                'invalidDomain'        => __( 'Enter an apex domain like example.com (no subdomains, no http://, no trailing slash).', 'gend-society' ),
                'importReviewRequired' => __( 'Please review the imported DNS records before changing nameservers. Jumping back to Step 2.', 'gend-society' ),
                'cfAuthMissing'        => __( 'Cloudflare is not configured on the hub. Contact your operator.', 'gend-society' ),
                'cfRateLimit'          => __( 'Cloudflare is rate-limiting. Retrying in {n} seconds.', 'gend-society' ),
                'cfImportFailed'       => __( 'Synchronous import is not available for this domain. You can still continue — add DNS records manually after delegation.', 'gend-society' ),
                'cfNetwork'            => __( 'Cloudflare is unreachable. Retry in a moment.', 'gend-society' ),
                'notFound'             => __( 'Zone not found. Your wizard session may have expired. Refresh and start over.', 'gend-society' ),
                'pollTimeout'          => __( 'Still waiting. DNS propagation can take up to 48 hours. See Cloudflare status if it persists.', 'gend-society' ),
                'domainActive'         => __( 'Domain is active!', 'gend-society' ),
                'copyAll'              => __( 'Copy both', 'gend-society' ),
                'copied'               => __( 'Copied!', 'gend-society' ),
            ),
        )
    );

    ?>
    <div class="gs-domains-wizard" id="gs-domains-wizard-root">

        <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Connect a Domain', 'gend-society' ); ?></h4>
        <p class="gs-hosting__section-sub"><?php esc_html_e( 'Bring your own domain. Cloudflare will manage DNS + SSL automatically. Existing records imported before nameservers change to avoid downtime.', 'gend-society' ); ?></p>

        <!-- Stale-zone warning banner (JS populates from /hosting/domains GET if any stale rows) -->
        <div class="gs-domains-wizard__stale-warning" id="gs-domains-wizard-stale" style="display:none;">
            <strong><?php esc_html_e( 'Stale wizard session detected.', 'gend-society' ); ?></strong>
            <span class="gs-domains-wizard__stale-list"></span>
            <button type="button" class="gs-domains-wizard__btn" data-action="restart"><?php esc_html_e( 'Restart wizard', 'gend-society' ); ?></button>
        </div>

        <!-- 4-step indicator -->
        <ol class="gs-domains-wizard__steps" role="tablist">
            <li class="gs-domains-wizard__step is-active" data-step-pill="1"><span class="gs-domains-wizard__step-pill">1</span> <?php esc_html_e( 'Domain', 'gend-society' ); ?></li>
            <li class="gs-domains-wizard__step" data-step-pill="2"><span class="gs-domains-wizard__step-pill">2</span> <?php esc_html_e( 'Review records', 'gend-society' ); ?></li>
            <li class="gs-domains-wizard__step" data-step-pill="3"><span class="gs-domains-wizard__step-pill">3</span> <?php esc_html_e( 'Nameservers', 'gend-society' ); ?></li>
            <li class="gs-domains-wizard__step" data-step-pill="4"><span class="gs-domains-wizard__step-pill">4</span> <?php esc_html_e( 'Status', 'gend-society' ); ?></li>
        </ol>

        <!-- Step 1: Domain entry -->
        <section class="gs-domains-wizard__panel is-active" data-step="1" role="tabpanel">
            <form class="gs-domains-wizard__form" id="gs-domains-wizard-step1-form">
                <label for="gs-domains-wizard-domain"><?php esc_html_e( 'Apex domain (no www., no subdomain)', 'gend-society' ); ?></label>
                <input type="text" id="gs-domains-wizard-domain" name="domain" placeholder="example.com" required>
                <button type="submit" class="gs-domains-wizard__btn is-primary"><?php esc_html_e( 'Connect domain', 'gend-society' ); ?></button>
            </form>
            <div class="gs-domains-wizard__error" id="gs-domains-wizard-step1-error" style="display:none;"></div>
        </section>

        <!-- Step 2: Import review -->
        <section class="gs-domains-wizard__panel" data-step="2" role="tabpanel">
            <h5><?php esc_html_e( 'Imported DNS records', 'gend-society' ); ?></h5>
            <p class="gs-hosting__section-sub"><?php esc_html_e( 'These records were copied from your existing DNS. Review them — your nameservers will be revealed once you confirm.', 'gend-society' ); ?></p>
            <div class="gs-domains-wizard__records-loading" id="gs-domains-wizard-records-loading"><?php esc_html_e( 'Importing records from Cloudflare...', 'gend-society' ); ?></div>
            <table class="gs-domains-wizard__records-table" id="gs-domains-wizard-records-table" style="display:none;">
                <thead><tr>
                    <th><?php esc_html_e( 'Type', 'gend-society' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'gend-society' ); ?></th>
                    <th><?php esc_html_e( 'Value', 'gend-society' ); ?></th>
                    <th><?php esc_html_e( 'TTL', 'gend-society' ); ?></th>
                    <th><?php esc_html_e( 'Proxied', 'gend-society' ); ?></th>
                </tr></thead>
                <tbody></tbody>
            </table>
            <button type="button" class="gs-domains-wizard__btn is-primary" id="gs-domains-wizard-step2-confirm" disabled><?php esc_html_e( "I've reviewed these records", 'gend-society' ); ?></button>
            <div class="gs-domains-wizard__error" id="gs-domains-wizard-step2-error" style="display:none;"></div>
        </section>

        <!-- Step 3: Nameservers reveal -->
        <section class="gs-domains-wizard__panel" data-step="3" role="tabpanel">
            <h5><?php esc_html_e( 'Change nameservers at your registrar', 'gend-society' ); ?></h5>
            <p class="gs-hosting__section-sub"><?php esc_html_e( 'Log into your domain registrar (GoDaddy, Namecheap, Google Domains, etc.) and replace your existing nameservers with the two below. DNS propagation typically takes 1-24 hours.', 'gend-society' ); ?></p>
            <ol class="gs-domains-wizard__ns-list" id="gs-domains-wizard-ns-list"></ol>
            <button type="button" class="gs-domains-wizard__copy-btn" id="gs-domains-wizard-copy-btn"><?php esc_html_e( 'Copy both', 'gend-society' ); ?></button>
            <details class="gs-domains-wizard__registrar-help">
                <summary><?php esc_html_e( 'Common registrar walkthroughs', 'gend-society' ); ?></summary>
                <ul>
                    <li><a href="https://www.godaddy.com/help/change-nameservers-for-my-domains-664" target="_blank" rel="noopener">GoDaddy</a></li>
                    <li><a href="https://www.namecheap.com/support/knowledgebase/article.aspx/767/10/how-to-change-dns-for-a-domain/" target="_blank" rel="noopener">Namecheap</a></li>
                    <li><a href="https://support.google.com/domains/answer/3290309" target="_blank" rel="noopener">Google Domains</a></li>
                </ul>
            </details>
            <button type="button" class="gs-domains-wizard__btn is-primary" id="gs-domains-wizard-step3-continue"><?php esc_html_e( "I've updated my nameservers — start polling", 'gend-society' ); ?></button>
            <div class="gs-domains-wizard__error" id="gs-domains-wizard-step3-error" style="display:none;"></div>
        </section>

        <!-- Step 4: Status polling -->
        <section class="gs-domains-wizard__panel" data-step="4" role="tabpanel">
            <div class="gs-domains-wizard__status-poll" id="gs-domains-wizard-status-poll">
                <div class="gs-domains-wizard__spinner"></div>
                <p id="gs-domains-wizard-status-text"><?php esc_html_e( 'Waiting for DNS propagation (typically 1-24 hours)...', 'gend-society' ); ?></p>
                <p class="gs-hosting__section-sub" id="gs-domains-wizard-status-last"></p>
            </div>
            <div class="gs-domains-wizard__success" id="gs-domains-wizard-success" style="display:none;">
                <strong><?php esc_html_e( 'Domain is active!', 'gend-society' ); ?></strong>
                <p class="gs-hosting__section-sub"><?php esc_html_e( 'DNS delegation has propagated. You can now manage records and point this domain at your app (Phase 73+).', 'gend-society' ); ?></p>
            </div>
        </section>

        <!-- Existing-domains list (DCON-05) -->
        <hr style="border-color: rgba(255,255,255,0.08); margin: 24px 0;">
        <h5 class="gs-hosting__section-title"><?php esc_html_e( 'Connected domains', 'gend-society' ); ?></h5>
        <p class="gs-hosting__section-sub"><?php esc_html_e( 'Domains already connected to this app, with their current status.', 'gend-society' ); ?></p>
        <table class="gs-domains-wizard__connected-table" id="gs-domains-wizard-connected-table">
            <thead><tr>
                <th><?php esc_html_e( 'Domain', 'gend-society' ); ?></th>
                <th><?php esc_html_e( 'Import', 'gend-society' ); ?></th>
                <th><?php esc_html_e( 'Zone', 'gend-society' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'gend-society' ); ?></th>
            </tr></thead>
            <tbody id="gs-domains-wizard-connected-tbody">
                <tr><td colspan="4" class="gs-hosting__section-sub" style="font-style:italic; padding:18px 12px;"><?php esc_html_e( 'Loading...', 'gend-society' ); ?></td></tr>
            </tbody>
        </table>

    </div>
    <?php
}
