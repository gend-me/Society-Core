<?php
/**
 * BuddyPress group tabs that surface the linked web app's management
 * controls right inside the group nav. Each tab mirrors the matching
 * wp-admin sub-tab on the customer dashboard so an admin who lands on
 * a group page on gend.me sees the same surface they'd see if they
 * logged into the underlying install.
 *
 *   Group → Feature Suite  ↔  wp-admin → dashboard → Feature Suite
 *   Group → User Access    ↔  wp-admin → dashboard → User Access
 *   Group → Hosting        ↔  wp-admin → dashboard → Hosting
 *   Group → Compute Gas    ↔  wp-admin → dashboard → Compute Gas
 *
 * Each tab is gated on group-admin OR site-admin so only people with
 * authority over the web app see them. Display callbacks reuse the
 * existing render functions (gs_render_feature_cards_widget,
 * gs_render_hosting_tab, feature-access.php) so what the user sees in
 * the group matches what they'd see in the dashboard — same widgets,
 * same AJAX endpoints, same modals.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolve the install_id linked to the current group, if any.
 *
 * Membership-system glue stores `gdc_membership_id` as group meta when
 * a customer's WP-Ultimo membership is wired up to their Business
 * Group. Use that to look up the membership's primary site, then read
 * the site's gdc_install_id meta. Returns '' on unpaired groups.
 */
function gs_group_get_linked_install_id( $group_id ) {
    $group_id = (int) $group_id;
    if ( ! $group_id || ! function_exists( 'groups_get_groupmeta' ) ) return '';
    $membership_id = (int) groups_get_groupmeta( $group_id, 'gdc_membership_id' );
    if ( ! $membership_id || ! function_exists( 'wu_get_membership' ) ) return '';
    $membership = wu_get_membership( $membership_id );
    if ( ! $membership ) return '';
    $sites = method_exists( $membership, 'get_sites' ) ? $membership->get_sites() : array();
    if ( ! is_array( $sites ) || empty( $sites ) ) return '';
    $primary = $sites[0];
    if ( ! is_object( $primary ) || ! method_exists( $primary, 'get_meta' ) ) return '';
    return (string) $primary->get_meta( 'gdc_install_id', '' );
}

/**
 * Common access gate — only group admins or site admins see these tabs.
 * Falls back to false on non-group contexts so the extension just
 * doesn't render.
 */
function gs_group_tabs_user_has_access() {
    if ( ! is_user_logged_in() ) return false;
    if ( current_user_can( 'manage_options' ) ) return true;
    if ( ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) return false;
    if ( function_exists( 'bp_group_is_admin' ) && bp_group_is_admin() ) return true;
    if ( function_exists( 'bp_group_is_mod' ) && bp_group_is_mod() ) return true;
    return false;
}

/**
 * Small chrome around every tab's content so it picks up the glass
 * treatment defined in frontend-bar.php (.gs-group-tab-panel) and
 * shows the linked install URL when one is known.
 */
function gs_group_tab_open( $title, $intro, $group_id ) {
    $install_id = gs_group_get_linked_install_id( $group_id );
    $app_link = '';
    if ( function_exists( 'wu_get_membership' ) && function_exists( 'groups_get_groupmeta' ) ) {
        $mid = (int) groups_get_groupmeta( $group_id, 'gdc_membership_id' );
        if ( $mid ) {
            $mem = wu_get_membership( $mid );
            if ( $mem && method_exists( $mem, 'get_sites' ) ) {
                $sites = $mem->get_sites();
                if ( ! empty( $sites[0] ) && method_exists( $sites[0], 'get_active_site_url' ) ) {
                    $app_link = (string) $sites[0]->get_active_site_url();
                } elseif ( ! empty( $sites[0] ) && method_exists( $sites[0], 'get_site_url' ) ) {
                    $app_link = (string) $sites[0]->get_site_url();
                }
            }
        }
    }
    ?>
    <div class="gs-group-tab-panel">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
            <div>
                <h3 style="margin:0 0 4px; color:#fff; font-size:1.15rem;"><?php echo esc_html( $title ); ?></h3>
                <p style="margin:0; color:#cbd5f5; font-size:0.88rem;"><?php echo esc_html( $intro ); ?></p>
            </div>
            <?php if ( $app_link ) : ?>
                <a href="<?php echo esc_url( trailingslashit( $app_link ) . 'wp-admin/' ); ?>" target="_blank" rel="noopener" style="background:rgba(78,170,255,0.18); border:1px solid rgba(78,170,255,0.45); color:#fff; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:600; font-size:0.82rem;">
                    Open Web App Admin →
                </a>
            <?php endif; ?>
        </div>
        <div class="gs-group-tab-body">
    <?php
}
function gs_group_tab_close() {
    echo '</div></div>';
}

if ( class_exists( 'BP_Group_Extension' ) ) :

    /**
     * Feature Suite — the plugin / feature-cards grid the customer sees
     * on wp-admin → Feature Suite.
     */
    class GS_Group_Tab_Feature_Suite extends BP_Group_Extension {
        public function __construct() {
            parent::init( array(
                'slug'              => 'feature-suite',
                'name'              => __( 'Feature Suite', 'gend-society' ),
                'nav_item_position' => 50,
                // Mirror the args shape the PSOO_*_Group_Extension classes
                // use (those tabs render fine). nav_item_name +
                // display_hook + template_file are what BP_Group_Extension
                // keys on at registration; without them the nav item gets
                // silently skipped on some BP builds.
                'show_tab'          => 'anyone',
                'nav_item_name'     => __( 'Feature Suite', 'gend-society' ),
                'display_hook'      => 'groups_custom_group_boxes',
                'template_file'     => 'groups/single/plugins',
            ) );
        }
        public function display( $group_id = null ) {
            if ( ! gs_group_tabs_user_has_access() ) return;
            $group_id = $group_id ?: bp_get_current_group_id();
            // No gs_group_tab_open() wrapper — gs_render_group_feature_suite()
            // ships its own scoped chrome (plan badge hero + filter bar) so
            // the wrapper's small title strip would only duplicate it.
            if ( function_exists( 'gs_render_group_feature_suite' ) ) {
                gs_render_group_feature_suite( $group_id );
            } elseif ( function_exists( 'gs_render_feature_cards_widget' ) ) {
                // Legacy fallback: hub-local plugin grid. Only fires if the
                // vendor-app-manager catalog helpers didn't load (defensive —
                // wouldn't ship on a healthy install).
                gs_group_tab_open(
                    __( 'Feature Suite', 'gend-society' ),
                    __( 'Plugins and features available to this web app.', 'gend-society' ),
                    $group_id
                );
                gs_render_feature_cards_widget();
                gs_group_tab_close();
            } else {
                echo '<p style="color:rgba(203,213,245,0.7);">Feature suite module is not available on this install.</p>';
            }
        }
    }

    // User Access is no longer a standalone group tab — its content
    // has been folded into the Organization (members-hub) page via the
    // `psoo_group_members_after_render` action hook. See
    // gs_group_render_user_access_below_members() at the bottom of this file.

    /**
     * Hosting — Dashboard / Domains / Logs / Tables / Containers /
     * Backups. Reuses gs_render_hosting_tab() so the sidebar nav and
     * every sub-panel render identically to the dashboard surface.
     */
    class GS_Group_Tab_Hosting extends BP_Group_Extension {
        public function __construct() {
            parent::init( array(
                'slug'              => 'hosting',
                'name'              => __( 'Hosting', 'gend-society' ),
                'nav_item_position' => 70,
                'show_tab'          => 'anyone',
                'nav_item_name'     => __( 'Hosting', 'gend-society' ),
                'display_hook'      => 'groups_custom_group_boxes',
                'template_file'     => 'groups/single/plugins',
            ) );
        }
        public function display( $group_id = null ) {
            if ( ! gs_group_tabs_user_has_access() ) return;
            $group_id = $group_id ?: bp_get_current_group_id();
            // No gs_group_tab_open() wrapper — the new suite header below
            // is the only chrome we need. (Same fix that landed on the
            // Compute Gas tab — the wrapper rendered a duplicate title.)
            gs_group_render_hosting_suite( $group_id );
        }
    }

    /**
     * Compute Gas — the value-prop explainer + current-period spend.
     * Pure presentational + AJAX-fetched stats; works the same in a
     * group context as on wp-admin.
     */
    class GS_Group_Tab_Compute_Gas extends BP_Group_Extension {
        public function __construct() {
            parent::init( array(
                'slug'              => 'compute-gas',
                'name'              => __( 'Compute Gas', 'gend-society' ),
                'nav_item_position' => 80,
                'show_tab'          => 'anyone',
                'nav_item_name'     => __( 'Compute Gas', 'gend-society' ),
                'display_hook'      => 'groups_custom_group_boxes',
                'template_file'     => 'groups/single/plugins',
            ) );
        }
        public function display( $group_id = null ) {
            if ( ! gs_group_tabs_user_has_access() ) return;
            $group_id = $group_id ?: bp_get_current_group_id();
            // No gs_group_tab_open() wrapper here — the new design's two
            // glass panels already provide their own chrome, and the
            // wrapper's title/intro strip was rendering as a duplicate of
            // the dashboard's own H2.
            $ajax_url = admin_url( 'admin-ajax.php' );
            $nonce    = wp_create_nonce( 'gs_membership_action' );
            $uid      = 'gs-cg-' . (int) $group_id;
            ?>
            <style>
                /* All styles + selectors live under [data-gs-cg-scope] so
                   Youzify's frontend rules (red buttons, white panels,
                   serif headings) can't bleed in. Class names use the
                   gs-cg-* namespace to avoid theme/plugin collisions. */
                [data-gs-cg-scope] {
                    --cg-blue:    #6ec1e4;
                    --cg-magenta: #b608c9;
                    --cg-green:   #00ff88;
                    --cg-obsidian:#0b0e14;
                    --cg-glass-bg: rgba(15, 18, 24, 0.45);
                    --cg-glass-border: rgba(255,255,255,0.08);
                    --cg-ease: cubic-bezier(0.16, 1, 0.3, 1);

                    font-family: 'Inter', system-ui, sans-serif;
                    color: #fff;
                    max-width: 1250px;
                    margin: 0 auto;
                    padding: 20px;
                    box-sizing: border-box;
                }
                [data-gs-cg-scope] * { box-sizing: border-box; }

                [data-gs-cg-scope] .gs-cg-header { text-align: left; margin-bottom: 35px; }
                [data-gs-cg-scope] .gs-cg-header h2 {
                    font-size: 2.2rem !important; font-weight: 950 !important; text-transform: uppercase !important;
                    letter-spacing: -1px !important; margin: 0 0 8px 0 !important; color: #fff !important; font-family: inherit !important;
                }
                [data-gs-cg-scope] .gs-cg-header p { font-size: 0.95rem; opacity: 0.5; margin: 0; color: #fff; }

                [data-gs-cg-scope] .gs-cg-panel {
                    background: var(--cg-glass-bg);
                    border: 1px solid var(--cg-glass-border);
                    border-radius: 32px;
                    backdrop-filter: blur(25px) saturate(160%);
                    -webkit-backdrop-filter: blur(25px) saturate(160%);
                    padding: 45px;
                    margin-bottom: 25px;
                    position: relative; overflow: hidden;
                    box-shadow: 0 20px 50px rgba(0,0,0,0.4);
                }
                [data-gs-cg-scope] .gs-cg-badge {
                    display: inline-flex; align-items: center; gap: 6px;
                    background: rgba(110,193,228,0.10);
                    border: 1px solid rgba(110,193,228,0.25);
                    color: var(--cg-blue);
                    padding: 6px 14px; border-radius: 8px;
                    font-size: 0.65rem; font-weight: 900;
                    text-transform: uppercase; letter-spacing: 2px;
                    margin-bottom: 25px;
                }
                [data-gs-cg-scope] .gs-cg-intro h3 {
                    font-size: 1.5rem !important; font-weight: 900 !important;
                    text-transform: uppercase !important; letter-spacing: -0.5px !important;
                    margin: 0 0 15px 0 !important; color: #fff !important; font-family: inherit !important;
                }
                [data-gs-cg-scope] .gs-cg-intro p {
                    font-size: 1rem; line-height: 1.6; opacity: 0.7;
                    max-width: 950px; margin: 0 0 40px 0; color: #fff;
                }
                [data-gs-cg-scope] .gs-cg-intro p strong { color: #fff; font-weight: 700; }

                [data-gs-cg-scope] .gs-cg-spec-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
                [data-gs-cg-scope] .gs-cg-spec {
                    background: rgba(255,255,255,0.02);
                    border: 1px solid var(--cg-glass-border);
                    border-radius: 20px; padding: 30px; text-align: left;
                    transition: all 0.4s var(--cg-ease);
                }
                [data-gs-cg-scope] .gs-cg-spec:hover {
                    border-color: rgba(255,255,255,0.2);
                    background: rgba(255,255,255,0.04);
                    transform: translateY(-4px);
                }
                [data-gs-cg-scope] .gs-cg-spec-icon {
                    width: 40px; height: 40px; border-radius: 10px;
                    background: rgba(255,255,255,0.03);
                    border: 1px solid var(--cg-glass-border);
                    display: flex; align-items: center; justify-content: center;
                    color: var(--cg-blue); margin-bottom: 20px;
                }
                [data-gs-cg-scope] .gs-cg-spec h4 {
                    font-size: 1.15rem !important; font-weight: 800 !important;
                    text-transform: uppercase !important; margin: 0 0 10px 0 !important;
                    color: #fff !important; font-family: inherit !important; letter-spacing: 0 !important;
                }
                [data-gs-cg-scope] .gs-cg-spec p { font-size: 0.88rem; line-height: 1.5; opacity: 0.45; margin: 0; color: #fff; }

                [data-gs-cg-scope] .gs-cg-usage-head {
                    display: flex; justify-content: space-between; align-items: flex-start;
                    margin-bottom: 35px; gap: 16px; flex-wrap: wrap;
                }
                [data-gs-cg-scope] .gs-cg-usage-head h3 {
                    font-size: 1.5rem !important; font-weight: 900 !important;
                    text-transform: uppercase !important; letter-spacing: -0.5px !important;
                    margin: 0 0 6px 0 !important; color: #fff !important; font-family: inherit !important;
                }
                [data-gs-cg-scope] .gs-cg-usage-head .lede { font-size: 0.9rem; opacity: 0.5; margin: 0; color: #fff; }
                [data-gs-cg-scope] .gs-cg-refresh-btn {
                    background: rgba(255,255,255,0.05) !important;
                    border: 1px solid var(--cg-glass-border) !important;
                    color: #fff !important;
                    padding: 10px 22px !important; border-radius: 10px !important;
                    font-size: 0.75rem !important; font-weight: 800 !important;
                    text-transform: uppercase !important; letter-spacing: 1px !important;
                    cursor: pointer !important;
                    transition: all 0.3s var(--cg-ease) !important;
                    text-shadow: none !important; box-shadow: none !important;
                    font-family: inherit !important;
                }
                [data-gs-cg-scope] .gs-cg-refresh-btn:hover { background: #fff !important; color: #000 !important; border-color: #fff !important; }
                [data-gs-cg-scope] .gs-cg-refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }

                [data-gs-cg-scope] .gs-cg-acct-grid {
                    display: grid; grid-template-columns: 1fr 1fr 1.2fr;
                    gap: 20px; margin-bottom: 35px;
                }
                [data-gs-cg-scope] .gs-cg-acct {
                    background: rgba(0,0,0,0.2);
                    border: 1px solid var(--cg-glass-border);
                    border-radius: 20px; padding: 25px 30px; text-align: left;
                }
                [data-gs-cg-scope] .gs-cg-acct.is-total {
                    border-color: rgba(110,193,228,0.25);
                    background: linear-gradient(135deg, rgba(110,193,228,0.03), rgba(182,8,201,0.03));
                }
                [data-gs-cg-scope] .gs-cg-meta {
                    font-size: 0.65rem; font-weight: 900;
                    text-transform: uppercase; letter-spacing: 1.5px;
                    opacity: 0.4; display: block; margin-bottom: 10px; color: #fff;
                }
                [data-gs-cg-scope] .gs-cg-acct.is-total .gs-cg-meta { opacity: 0.8; color: var(--cg-blue); }
                [data-gs-cg-scope] .gs-cg-price {
                    font-size: 2.2rem; font-weight: 950;
                    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    letter-spacing: -1px; line-height: 1; margin-bottom: 6px; color: #fff;
                }
                [data-gs-cg-scope] .gs-cg-acct.is-total .gs-cg-price {
                    color: var(--cg-green);
                    text-shadow: 0 0 20px rgba(0,255,136,0.2);
                }
                [data-gs-cg-scope] .gs-cg-period { font-size: 0.75rem; opacity: 0.3; color: #fff; }

                [data-gs-cg-scope] .gs-cg-runtime {
                    border-top: 1px solid var(--cg-glass-border);
                    padding-top: 25px; text-align: left;
                }
                [data-gs-cg-scope] .gs-cg-runtime p {
                    font-size: 0.95rem; font-style: italic;
                    opacity: 0.35; margin: 0;
                    display: flex; align-items: center; gap: 8px; color: #fff;
                }
                [data-gs-cg-scope] .gs-cg-runtime svg { color: var(--cg-magenta); flex-shrink: 0; }

                /* Stagger reveal — same dashboardAssemble keyframe the spec uses. */
                [data-gs-cg-scope] .gs-cg-stagger { opacity: 0; animation: gsCgAssemble 1s var(--cg-ease) forwards; }
                @keyframes gsCgAssemble {
                    0%   { opacity: 0; transform: translateY(25px) scale(0.98); filter: blur(8px); }
                    100% { opacity: 1; transform: none;                       filter: blur(0); }
                }
                [data-gs-cg-scope] .gs-cg-stagger.b1 { animation-delay: 0.05s; }
                [data-gs-cg-scope] .gs-cg-stagger.b2 { animation-delay: 0.15s; }
                [data-gs-cg-scope] .gs-cg-stagger.b3 { animation-delay: 0.25s; }
                [data-gs-cg-scope] .gs-cg-stagger.b4 { animation-delay: 0.32s; }
                [data-gs-cg-scope] .gs-cg-stagger.b5 { animation-delay: 0.39s; }
                [data-gs-cg-scope] .gs-cg-stagger.b6 { animation-delay: 0.48s; }

                @media (max-width: 900px) {
                    [data-gs-cg-scope] .gs-cg-spec-grid,
                    [data-gs-cg-scope] .gs-cg-acct-grid { grid-template-columns: 1fr; gap: 15px; }
                    [data-gs-cg-scope] .gs-cg-usage-head { flex-direction: column; gap: 15px; }
                    [data-gs-cg-scope] .gs-cg-refresh-btn { width: 100%; }
                    [data-gs-cg-scope] .gs-cg-panel { padding: 25px; }
                }
                @media (prefers-reduced-motion: reduce) {
                    [data-gs-cg-scope] .gs-cg-stagger { opacity: 1 !important; animation: none !important; }
                }
            </style>

            <div data-gs-cg-scope id="<?php echo esc_attr( $uid ); ?>">

                <?php /* Top header removed 2026-05-27 — the BP group nav
                           already names this tab "Compute Gas" and the
                           gs_group_tab_open() wrapper above was
                           rendering a second smaller title above the big
                           one. Both headers gone; the badge below now
                           introduces the section. */ ?>

                <section class="gs-cg-panel gs-cg-stagger b2">
                    <div class="gs-cg-intro">
                        <span class="gs-cg-badge">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            <?php esc_html_e( 'No Monthly Server Fees', 'gend-society' ); ?>
                        </span>
                        <h3><?php esc_html_e( 'How Compute Gas Works', 'gend-society' ); ?></h3>
                        <p>
                            <?php
                            printf(
                                /* translators: 1: bold "Blockchain Compute network", 2: bold "Gas Station Nodes" */
                                esc_html__( "Networked businesses on GenD don't pay flat monthly server or compute fees. Your storage container integrates with our %1\$s, which uses the unused compute power of servers and devices running our %2\$s across the network. That same network powers every payment, workflow, and smart contract—so what your app uses, the network earns. You only pay for the exact compute you consume.", 'gend-society' ),
                                '<strong>' . esc_html__( 'Blockchain Compute network', 'gend-society' ) . '</strong>',
                                '<strong>' . esc_html__( 'Gas Station Nodes', 'gend-society' ) . '</strong>'
                            );
                            ?>
                        </p>
                    </div>

                    <div class="gs-cg-spec-grid">
                        <div class="gs-cg-spec gs-cg-stagger b3">
                            <div class="gs-cg-spec-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                            </div>
                            <h4><?php esc_html_e( 'Payments', 'gend-society' ); ?></h4>
                            <p><?php esc_html_e( 'Every checkout settles natively on-chain, bypassing third-party clearing houses and eliminating standard merchant processor transactional deductions.', 'gend-society' ); ?></p>
                        </div>
                        <div class="gs-cg-spec gs-cg-stagger b4">
                            <div class="gs-cg-spec-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M3 12h3m12 0h3M12 3v3m0 12v3m-6.6-13.4l2.1 2.1m8.6 8.6l2.1 2.1M6.4 17.6l2.1-2.1m8.6-8.6l2.1-2.1"></path></svg>
                            </div>
                            <h4><?php esc_html_e( 'Compute', 'gend-society' ); ?></h4>
                            <p><?php esc_html_e( 'On-demand CPU and RAM allocations are fully isolated and instantly provisioned directly from unused operational storage capacity across the secure node matrix.', 'gend-society' ); ?></p>
                        </div>
                        <div class="gs-cg-spec gs-cg-stagger b5">
                            <div class="gs-cg-spec-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                            </div>
                            <h4><?php esc_html_e( 'Smart Contracts', 'gend-society' ); ?></h4>
                            <p><?php esc_html_e( 'Systemic workflows, escrow releases, and milestone payouts process via immutable logic chains. Cryptographic consensus acts as your real-time settlement receipt.', 'gend-society' ); ?></p>
                        </div>
                    </div>
                </section>

                <section class="gs-cg-panel gs-cg-stagger b6">
                    <div class="gs-cg-usage-head">
                        <div>
                            <h3><?php esc_html_e( 'Usage This Period', 'gend-society' ); ?></h3>
                            <p class="lede"><?php esc_html_e( 'Live ledger synchronization of active compute gas and private container virtualization metrics.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-cg-refresh-btn" data-gs-cg-refresh><?php esc_html_e( 'Refresh Ledger', 'gend-society' ); ?></button>
                    </div>

                    <div class="gs-cg-acct-grid">
                        <div class="gs-cg-acct">
                            <span class="gs-cg-meta"><?php esc_html_e( 'Compute Gas', 'gend-society' ); ?></span>
                            <div class="gs-cg-price" data-gs-cg-cell="gas">$0.00</div>
                            <div class="gs-cg-period" data-gs-cg-cell="gas-period"><?php esc_html_e( 'Current billing increment usage', 'gend-society' ); ?></div>
                        </div>
                        <div class="gs-cg-acct">
                            <span class="gs-cg-meta"><?php esc_html_e( 'Container Fees', 'gend-society' ); ?></span>
                            <div class="gs-cg-price" data-gs-cg-cell="container">$0.00</div>
                            <div class="gs-cg-period" data-gs-cg-cell="container-period"><?php esc_html_e( 'Current billing increment usage', 'gend-society' ); ?></div>
                        </div>
                        <div class="gs-cg-acct is-total">
                            <span class="gs-cg-meta"><?php esc_html_e( 'Consolidated Total', 'gend-society' ); ?></span>
                            <div class="gs-cg-price" data-gs-cg-cell="total">$0.00</div>
                            <div class="gs-cg-period" data-gs-cg-cell="total-period"><?php esc_html_e( 'Aggregated outstanding ecosystem ledger balance', 'gend-society' ); ?></div>
                        </div>
                    </div>

                    <div class="gs-cg-runtime">
                        <p data-gs-cg-runtime>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <span data-gs-cg-runtime-text><?php esc_html_e( 'Distributed Compute Gas telemetry streaming is pending initial structural deployment on this server frame instance.', 'gend-society' ); ?></span>
                        </p>
                    </div>
                </section>

            </div>

            <script>
            (function(){
                var root = document.getElementById('<?php echo esc_js( $uid ); ?>');
                if (!root) return;
                var ajax = <?php echo wp_json_encode( $ajax_url ); ?>;
                var nonce = <?php echo wp_json_encode( $nonce ); ?>;
                var btn = root.querySelector('[data-gs-cg-refresh]');
                var cells = {
                    gas:       root.querySelector('[data-gs-cg-cell="gas"]'),
                    container: root.querySelector('[data-gs-cg-cell="container"]'),
                    total:     root.querySelector('[data-gs-cg-cell="total"]'),
                    gasPeriod:       root.querySelector('[data-gs-cg-cell="gas-period"]'),
                    containerPeriod: root.querySelector('[data-gs-cg-cell="container-period"]'),
                    totalPeriod:     root.querySelector('[data-gs-cg-cell="total-period"]')
                };
                var runtimeText = root.querySelector('[data-gs-cg-runtime-text]');
                var defaultRuntime = runtimeText ? runtimeText.textContent : '';

                function load() {
                    if (btn) {
                        btn.disabled = true;
                        var prev = btn.textContent;
                        btn.textContent = '<?php echo esc_js( __( 'Syncing…', 'gend-society' ) ); ?>';
                        btn.dataset.prev = prev;
                    }
                    var body = new URLSearchParams({ action: 'gs_hosting_compute_gas', nonce: nonce });
                    fetch(ajax, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() })
                        .then(function(r){ return r.json(); })
                        .then(function(resp){
                            var d = (resp && resp.success && resp.data) ? resp.data : {};
                            if (cells.gas)       cells.gas.textContent       = d.gas_fees_label       || '$0.00';
                            if (cells.container) cells.container.textContent = d.container_fees_label || '$0.00';
                            if (cells.total)     cells.total.textContent     = d.total_label          || '$0.00';
                            var period = d.period || '<?php echo esc_js( __( 'Current billing increment usage', 'gend-society' ) ); ?>';
                            if (cells.gasPeriod)       cells.gasPeriod.textContent       = period;
                            if (cells.containerPeriod) cells.containerPeriod.textContent = period;
                            if (cells.totalPeriod)     cells.totalPeriod.textContent     = (d.total_period || period);
                            if (runtimeText && d.message) {
                                runtimeText.textContent = d.message;
                            } else if (runtimeText) {
                                runtimeText.textContent = defaultRuntime;
                            }
                        })
                        .catch(function(){
                            if (runtimeText) runtimeText.textContent = '<?php echo esc_js( __( 'Network error reaching the ledger — retry the refresh.', 'gend-society' ) ); ?>';
                        })
                        .finally(function(){
                            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.prev || '<?php echo esc_js( __( 'Refresh Ledger', 'gend-society' ) ); ?>'; }
                        });
                }
                if (btn) btn.addEventListener('click', function(e){ e.preventDefault(); load(); });
                load();
            })();
            </script>
            <?php
            // No gs_group_tab_close() — paired with the skipped
            // gs_group_tab_open() above.
        }
    }

endif; // class_exists( 'BP_Group_Extension' )

/**
 * Register all four tabs at bp_init so BuddyPress sees them during
 * its nav setup pass.
 */
// Priority MUST be < 11 — bp_init_group_extensions() runs at bp_init
// priority 11 ([bp-groups-functions.php:3830] in social-network's BP bundle).
// That function reads $bp->groups->group_extensions and instantiates each
// registered class; anything added after priority 11 is silently skipped
// because the iterate-and-instantiate pass already completed. Default
// priority 10 matches the projects-plugin pattern used by every
// PSOO_*_Group_Extension and is the canonical "register before init" slot.
add_action( 'bp_init', 'gs_register_group_app_tabs', 10 );
function gs_register_group_app_tabs() {
    if ( ! class_exists( 'BP_Group_Extension' ) ) return;
    if ( ! function_exists( 'bp_register_group_extension' ) ) return;

    // Only register if the viewer has admin rights somewhere — keeps
    // these tabs out of the nav for regular members. The extension
    // still loads; access() just returns false so nothing renders for
    // non-admins who hit the URL directly.
    bp_register_group_extension( 'GS_Group_Tab_Feature_Suite' );
    bp_register_group_extension( 'GS_Group_Tab_Hosting' );
    bp_register_group_extension( 'GS_Group_Tab_Compute_Gas' );
}

/**
 * Show the four web-app tabs in the group's nav for any group admin or
 * site admin. By default BP_Group_Extension hides custom tabs from
 * non-admins; this filter flips the visibility ON for users we trust.
 *
 * Hooked late (priority 99) so BP's own enable_nav_item logic runs
 * first and we override only when our gate passes.
 */
add_filter( 'bp_group_extension_nav_show_for_user', 'gs_group_app_tabs_nav_visibility', 99, 3 );
function gs_group_app_tabs_nav_visibility( $show, $slug, $group_id ) {
    if ( ! in_array( $slug, array( 'feature-suite', 'hosting', 'compute-gas' ), true ) ) {
        return $show;
    }
    return gs_group_tabs_user_has_access();
}

/**
 * Render the per-user feature-access management UI at the bottom of the
 * group's Organization page. Hooked to `psoo_group_members_after_render`
 * which fires inside psoo_render_group_members_template() in the projects
 * plugin (group-members-screen.php).
 *
 * The content is the same `inc/pages/feature-access.php` that used to be
 * a standalone group tab — wrapped here in a CSS isolation container that
 * neutralizes Youzify's aggressive widefat / wp-list-table rules that
 * were leaking into the table styling. Same Manage Access modal and AJAX
 * endpoints; only the wrapper changed.
 */
add_action( 'psoo_group_members_after_render', 'gs_group_render_user_access_below_members' );
function gs_group_render_user_access_below_members() {
    if ( ! gs_group_tabs_user_has_access() ) return;
    if ( ! defined( 'GS_DIR' ) || ! file_exists( GS_DIR . 'inc/pages/feature-access.php' ) ) return;

    // Ensure wp_editor assets are present for the invite modal.
    if ( function_exists( 'wp_enqueue_editor' ) ) {
        wp_enqueue_editor();
    }
    if ( function_exists( 'wp_enqueue_media' ) ) {
        wp_enqueue_media();
    }
    ?>
    <section class="gs-user-access-section" data-gs-user-access-scope>
        <style>
            /* ── Youzify isolation ───────────────────────────────────────
               Youzify's frontend stylesheet applies global rules to
               .widefat / .wp-list-table / .users (the WP admin-styled
               table feature-access.php uses) that bleed into the frontend
               with white backgrounds, dark text, and tight column widths.
               These overrides re-apply our dark-glass treatment ONLY
               inside [data-gs-user-access-scope] so we don't fight
               Youzify elsewhere on the page. */
            [data-gs-user-access-scope] {
                margin-top: 32px;
                padding: 28px clamp(20px, 4vw, 36px);
                background: linear-gradient(180deg, rgba(20, 24, 34, 0.55), rgba(11, 14, 20, 0.65));
                border: 1px solid rgba(255, 255, 255, 0.10);
                border-radius: 16px;
                backdrop-filter: blur(20px) saturate(160%);
                -webkit-backdrop-filter: blur(20px) saturate(160%);
                color: #e6edf7;
            }
            /* Reset all typography inside the scope to inherit the
               frontend body font + neutralize Youzify's serif headings,
               red h1/h2 backgrounds, oversized paragraph leads, and
               red-box icon decorations. */
            [data-gs-user-access-scope],
            [data-gs-user-access-scope] h1,
            [data-gs-user-access-scope] h2,
            [data-gs-user-access-scope] h3,
            [data-gs-user-access-scope] h4,
            [data-gs-user-access-scope] h5,
            [data-gs-user-access-scope] h6,
            [data-gs-user-access-scope] p,
            [data-gs-user-access-scope] span,
            [data-gs-user-access-scope] label,
            [data-gs-user-access-scope] button {
                font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            }
            [data-gs-user-access-scope] h1,
            [data-gs-user-access-scope] h2,
            [data-gs-user-access-scope] h3,
            [data-gs-user-access-scope] h4,
            [data-gs-user-access-scope] h5,
            [data-gs-user-access-scope] h6 {
                background: transparent !important;
                background-image: none !important;
                color: #fff !important;
                text-shadow: none !important;
                box-shadow: none !important;
                border: 0 !important;
                padding: 0 !important;
                line-height: 1.3 !important;
            }
            [data-gs-user-access-scope] h1::before,
            [data-gs-user-access-scope] h2::before,
            [data-gs-user-access-scope] h3::before,
            [data-gs-user-access-scope] h4::before,
            [data-gs-user-access-scope] h1::after,
            [data-gs-user-access-scope] h2::after,
            [data-gs-user-access-scope] h3::after,
            [data-gs-user-access-scope] h4::after { content: none !important; display: none !important; background: none !important; }
            [data-gs-user-access-scope] p { font-size: 0.92rem !important; line-height: 1.55 !important; color: #cbd5f5 !important; }

            [data-gs-user-access-scope] .gs-page,
            [data-gs-user-access-scope] .gs-page.wrap { background: transparent !important; padding: 0 !important; margin: 0 !important; max-width: none !important; }
            /* The standalone feature-access.php header duplicates our own
               wrapper header — hide it entirely so we don't see the giant
               serif h1, the red Youzify h1 background, the red list-icon
               ::before decoration, or the oversized lede paragraph. */
            [data-gs-user-access-scope] .gs-page-header { display: none !important; }
            [data-gs-user-access-scope] .gs-page-title,
            [data-gs-user-access-scope] .gs-gradient-text { display: none !important; }
            [data-gs-user-access-scope] .gs-card {
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin-top: 18px;
            }
            [data-gs-user-access-scope] .gs-card-header {
                background: transparent !important;
                padding: 0 0 14px !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
                margin-bottom: 14px;
            }
            [data-gs-user-access-scope] .gs-card-header h3 { color: #fff !important; font-size: 1rem !important; }
            [data-gs-user-access-scope] .gs-card-body { padding: 0 !important; background: transparent !important; }
            /* Override Youzify .widefat / .wp-list-table rules within our scope. */
            [data-gs-user-access-scope] table.widefat,
            [data-gs-user-access-scope] table.wp-list-table {
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                color: #e6edf7 !important;
            }
            [data-gs-user-access-scope] table.widefat thead,
            [data-gs-user-access-scope] table.widefat thead tr,
            [data-gs-user-access-scope] table.widefat tbody,
            [data-gs-user-access-scope] table.widefat tbody tr,
            [data-gs-user-access-scope] table.widefat tfoot,
            [data-gs-user-access-scope] table.widefat tfoot tr { background: transparent !important; }
            [data-gs-user-access-scope] table.widefat th,
            [data-gs-user-access-scope] table.widefat td,
            [data-gs-user-access-scope] table.widefat tr.alternate th,
            [data-gs-user-access-scope] table.widefat tr.alternate td {
                background: transparent !important;
                color: #e6edf7 !important;
                border-bottom-color: rgba(255, 255, 255, 0.05) !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
            [data-gs-user-access-scope] table.widefat th { color: #cbd5f5 !important; }
            [data-gs-user-access-scope] .button,
            [data-gs-user-access-scope] input[type=submit],
            [data-gs-user-access-scope] button.button {
                background: rgba(78, 170, 255, 0.12) !important;
                border: 1px solid rgba(78, 170, 255, 0.35) !important;
                color: #8ab4f8 !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
            [data-gs-user-access-scope] .button.button-primary,
            [data-gs-user-access-scope] input[type=submit].button-primary {
                background: linear-gradient(180deg, #4f46e5, #3b82f6) !important;
                border-color: transparent !important;
                color: #fff !important;
            }
            [data-gs-user-access-scope] .button:hover { background: rgba(78, 170, 255, 0.22) !important; color: #fff !important; }
            [data-gs-user-access-scope] input[type="search"],
            [data-gs-user-access-scope] input[type="text"],
            [data-gs-user-access-scope] input[type="email"] {
                background: rgba(0, 0, 0, 0.25) !important;
                border: 1px solid rgba(255, 255, 255, 0.10) !important;
                color: #fff !important;
                box-shadow: none !important;
            }
            /* The notice block feature-access.php echoes after a save */
            [data-gs-user-access-scope] .notice {
                background: rgba(78, 170, 255, 0.10) !important;
                border-left: 4px solid #4eaaff !important;
                color: #e6edf7 !important;
                padding: 10px 14px;
                border-radius: 8px;
            }
            [data-gs-user-access-scope] .notice-error { background: rgba(239, 68, 68, 0.12) !important; border-left-color: #ef4444 !important; }
            [data-gs-user-access-scope] .notice-success { background: rgba(16, 185, 129, 0.12) !important; border-left-color: #10b981 !important; }
        </style>
        <?php
        // The standalone gs-page-header inside feature-access.php duplicates
        // the section title here; suppress it via display:none above is
        // overkill, but we also surface a section header in our own voice
        // so the embed reads as a continuation of the Organization page
        // rather than a transplanted admin page.
        ?>
        <header style="margin-bottom: 18px;">
            <h2 style="margin: 0 0 6px; color: #fff; font-size: 1.25rem; font-weight: 700;"><?php esc_html_e( 'User Access', 'gend-society' ); ?></h2>
            <p style="margin: 0; color: #cbd5f5; font-size: 0.92rem;"><?php esc_html_e( 'Decide which menu items and dashboard features each user can reach.', 'gend-society' ); ?></p>
        </header>
        <?php require GS_DIR . 'inc/pages/feature-access.php'; ?>
    </section>
    <?php
}

/**
 * Render the redesigned Hosting suite — six-pane sidebar layout with
 * staggered entrance animations, glass panels, 3D-tilt metric boxes, and
 * linear progress meters. Wraps everything in [data-gs-host-scope] so
 * Youzify and theme rules can't bleed in. Wires the existing AJAX
 * endpoints (gs_hosting_*, gs_membership_domain_*, gs_membership_backup_*)
 * to the new controls so all hosting actions work the same as the
 * wp-admin surface — only the chrome changed.
 */
function gs_group_render_hosting_suite( $group_id ) {
    $group_id  = (int) $group_id;
    $ajax_url  = admin_url( 'admin-ajax.php' );
    $nonce     = wp_create_nonce( 'gs_membership_action' );
    $uid       = 'gs-host-' . $group_id;

    // Pre-fetch initial values so the panels render with real numbers on
    // first paint instead of waiting on a JS round-trip.
    $tables_data = function_exists( 'gs_hosting_collect_tables' ) ? gs_hosting_collect_tables() : array();
    $media_data  = function_exists( 'gs_hosting_collect_media' )  ? gs_hosting_collect_media()  : array();
    $resources   = function_exists( 'gs_hosting_collect_container_resources' )
        ? gs_hosting_collect_container_resources( $media_data, $tables_data )
        : array();

    // Domains / backups from the remote membership payload (best-effort).
    $domains_list = array();
    $backups_list = array();
    if ( function_exists( 'gs_remote_membership_get_cached' ) ) {
        $payload = gs_remote_membership_get_cached();
        if ( is_array( $payload ) ) {
            if ( ! empty( $payload['domains'] ) && is_array( $payload['domains'] ) ) {
                $domains_list = $payload['domains'];
            }
            if ( ! empty( $payload['backups'] ) && is_array( $payload['backups'] ) ) {
                $backups_list = $payload['backups'];
            }
        }
    }

    // Resource lookup by slug → meter percentage.
    $res_by_slug = array();
    foreach ( $resources as $r ) {
        if ( ! empty( $r['slug'] ) ) $res_by_slug[ $r['slug'] ] = $r;
    }
    $pct = function( $slug ) use ( $res_by_slug ) {
        if ( empty( $res_by_slug[ $slug ] ) ) return 0;
        $r = $res_by_slug[ $slug ];
        if ( empty( $r['cap'] ) ) return 0;
        $p = ( (int) $r['used'] / (int) $r['cap'] ) * 100;
        if ( $p < 0 ) return 0;
        if ( $p > 100 ) return 100;
        return $p;
    };
    $media_pct    = $pct( 'media' );
    $database_pct = $pct( 'database' );
    $compute_pct  = $pct( 'compute' );

    $tables_count = (int) ( $tables_data['count']       ?? 0 );
    $tables_bytes = (int) ( $tables_data['total_bytes'] ?? 0 );
    $tables_rows  = (int) ( $tables_data['total_rows']  ?? 0 );
    $largest_tbl  = (string) ( $tables_data['largest_name'] ?? '—' );
    ?>
    <style>
        /* ─────────────────────────────────────────────────────────────
           Hosting Suite — scoped to [data-gs-host-scope] so Youzify
           and theme rules can't reach in. All class names are
           gs-host-* to avoid collisions with the wp-admin renderer.
        ───────────────────────────────────────────────────────────── */
        [data-gs-host-scope] {
            --brand-blue:    #6ec1e4;
            --brand-magenta: #b608c9;
            --brand-green:   #00ff88;
            --brand-red:     #cc0000;
            --obsidian:      #0b0e14;
            --glass-bg:      rgba(15, 18, 24, 0.45);
            --glass-border:  rgba(255,255,255,0.08);
            --ease-out:      cubic-bezier(0.16, 1, 0.3, 1);

            font-family: 'Inter', system-ui, sans-serif;
            color: #fff;
            max-width: 1250px;
            margin: 0 auto;
            padding: 20px;
            box-sizing: border-box;
        }
        [data-gs-host-scope] * { box-sizing: border-box; }
        [data-gs-host-scope] button { font-family: inherit; }

        [data-gs-host-scope] .gs-host-suite-header { text-align: left; margin-bottom: 28px; }
        [data-gs-host-scope] .gs-host-suite-header h2 {
            font-size: 2.2rem !important; font-weight: 950 !important; text-transform: uppercase !important;
            letter-spacing: -1px !important; margin: 0 0 8px 0 !important; color: #fff !important; font-family: inherit !important;
        }
        [data-gs-host-scope] .gs-host-suite-header p { font-size: 0.95rem; opacity: 0.5; margin: 0; color: #fff; }

        [data-gs-host-scope] .hosting-suite-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 22px;
            align-items: start;
        }

        /* ── Sidebar / vertical pill nav ─────────────────────────── */
        [data-gs-host-scope] .suite-sidebar {
            position: sticky; top: 80px;
            display: flex; flex-direction: column; gap: 6px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 18px;
            backdrop-filter: blur(25px) saturate(160%);
            -webkit-backdrop-filter: blur(25px) saturate(160%);
        }
        [data-gs-host-scope] .tab-pill-trigger {
            display: flex; align-items: center; gap: 12px;
            background: transparent; border: 0; color: rgba(255,255,255,0.55);
            padding: 12px 14px; border-radius: 14px; cursor: pointer;
            font-size: 0.86rem; font-weight: 700; letter-spacing: 0.02em;
            text-transform: none; text-align: left; width: 100%;
            transition: all 0.25s var(--ease-out);
            text-shadow: none; box-shadow: none;
        }
        [data-gs-host-scope] .tab-pill-trigger:hover { background: rgba(255,255,255,0.04); color: #fff; }
        [data-gs-host-scope] .tab-pill-trigger.tab-active {
            background: linear-gradient(135deg, rgba(110,193,228,0.16), rgba(182,8,201,0.10));
            border: 1px solid rgba(110,193,228,0.28);
            color: #fff;
            box-shadow: inset 0 0 24px rgba(110,193,228,0.10);
        }
        [data-gs-host-scope] .tab-pill-trigger .pill-ico {
            width: 18px; height: 18px; flex: 0 0 18px;
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--brand-blue);
        }

        /* ── Main stage / panels ─────────────────────────────────── */
        [data-gs-host-scope] .suite-stage { min-width: 0; }
        [data-gs-host-scope] .suite-stage-view { display: none; }
        [data-gs-host-scope] .suite-stage-view.view-active { display: block; }

        [data-gs-host-scope] .view-title-block { margin-bottom: 18px; }
        [data-gs-host-scope] .view-title-block h3 {
            font-size: 1.4rem !important; font-weight: 900 !important; text-transform: uppercase !important;
            letter-spacing: -0.5px !important; margin: 0 0 6px 0 !important; color: #fff !important; font-family: inherit !important;
        }
        [data-gs-host-scope] .view-title-block p { font-size: 0.9rem; opacity: 0.5; margin: 0; color: #fff; }

        [data-gs-host-scope] .glass-card-panel {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            backdrop-filter: blur(25px) saturate(160%);
            -webkit-backdrop-filter: blur(25px) saturate(160%);
            padding: 28px;
            margin-bottom: 18px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }

        /* ── Action rows ─────────────────────────────────────────── */
        [data-gs-host-scope] .action-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 14px 0;
            border-top: 1px solid rgba(255,255,255,0.05);
        }
        [data-gs-host-scope] .action-row:first-child { border-top: 0; padding-top: 0; }
        [data-gs-host-scope] .action-row .ar-text { flex: 1; min-width: 200px; }
        [data-gs-host-scope] .action-row .ar-text strong {
            display: block; color: #fff; font-weight: 700; font-size: 0.95rem; margin-bottom: 4px;
        }
        [data-gs-host-scope] .action-row .ar-text span { color: rgba(255,255,255,0.55); font-size: 0.83rem; }

        [data-gs-host-scope] .btn-action-node {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 18px; border-radius: 999px; border: 1px solid var(--glass-border);
            background: rgba(255,255,255,0.03); color: #fff;
            font-size: 0.78rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em;
            cursor: pointer; transition: all 0.25s var(--ease-out);
            text-shadow: none; box-shadow: none;
        }
        [data-gs-host-scope] .btn-action-node:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.18); }
        [data-gs-host-scope] .btn-action-node.btn-blue {
            background: linear-gradient(135deg, rgba(110,193,228,0.18), rgba(110,193,228,0.06));
            border-color: rgba(110,193,228,0.42); color: var(--brand-blue);
        }
        [data-gs-host-scope] .btn-action-node.btn-blue:hover { background: rgba(110,193,228,0.25); color: #fff; }
        [data-gs-host-scope] .btn-action-node.btn-red {
            background: linear-gradient(135deg, rgba(204,0,0,0.20), rgba(204,0,0,0.06));
            border-color: rgba(204,0,0,0.45); color: #ff6b6b;
        }
        [data-gs-host-scope] .btn-action-node.btn-red:hover { background: rgba(204,0,0,0.30); color: #fff; }
        [data-gs-host-scope] .btn-action-node.btn-disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

        /* ── Form controls ───────────────────────────────────────── */
        [data-gs-host-scope] .glass-input {
            background: rgba(0,0,0,0.30) !important;
            border: 1px solid rgba(255,255,255,0.10) !important;
            color: #fff !important;
            border-radius: 12px !important;
            padding: 10px 14px !important;
            font-size: 0.88rem !important;
            min-width: 0;
            box-shadow: none !important; text-shadow: none !important;
        }
        [data-gs-host-scope] .glass-input::placeholder { color: rgba(255,255,255,0.4); }
        [data-gs-host-scope] .glass-input:focus {
            outline: 0 !important;
            border-color: rgba(110,193,228,0.55) !important;
            box-shadow: 0 0 0 2px rgba(110,193,228,0.16) !important;
        }
        [data-gs-host-scope] .inline-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        [data-gs-host-scope] .inline-form .glass-input { flex: 1; min-width: 220px; }

        /* ── Metric data grid + 3D tilt ──────────────────────────── */
        [data-gs-host-scope] .data-card-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px;
        }
        @media (max-width: 1100px) { [data-gs-host-scope] .data-card-grid { grid-template-columns: repeat(2, 1fr); } }
        [data-gs-host-scope] .metric-data-box {
            background: rgba(0,0,0,0.22);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 20px;
            transition: transform 0.16s var(--ease-out), border-color 0.25s var(--ease-out), background 0.25s var(--ease-out);
            transform-style: preserve-3d;
            will-change: transform;
        }
        [data-gs-host-scope] .metric-data-box:hover {
            border-color: rgba(255,255,255,0.18);
            background: rgba(0,0,0,0.30);
        }
        [data-gs-host-scope] .metric-meta {
            font-size: 0.62rem; font-weight: 900; letter-spacing: 1.4px;
            text-transform: uppercase; opacity: 0.45; color: #fff;
            display: block; margin-bottom: 10px;
        }
        [data-gs-host-scope] .metric-value {
            font-size: 1.8rem; font-weight: 950;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: -1px; line-height: 1; color: #fff; margin-bottom: 6px;
        }
        [data-gs-host-scope] .metric-sub { font-size: 0.74rem; opacity: 0.4; color: #fff; }

        /* ── Linear progress meters ──────────────────────────────── */
        [data-gs-host-scope] .linear-meter-container { margin-top: 12px; }
        [data-gs-host-scope] .linear-meter-head {
            display: flex; justify-content: space-between; align-items: baseline;
            margin-bottom: 8px;
        }
        [data-gs-host-scope] .linear-meter-head .lm-name { font-size: 0.82rem; font-weight: 700; color: #fff; }
        [data-gs-host-scope] .linear-meter-head .lm-readout {
            font-size: 0.74rem; opacity: 0.55; color: #fff;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        [data-gs-host-scope] .linear-meter-track {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--glass-border);
            border-radius: 999px;
            height: 8px;
            overflow: hidden;
        }
        [data-gs-host-scope] .linear-meter-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--brand-blue), var(--brand-magenta));
            border-radius: 999px;
            width: 0;
            transition: width 1.2s var(--ease-out);
        }
        [data-gs-host-scope] .linear-meter-fill.is-danger {
            background: linear-gradient(90deg, #ff7f7f, var(--brand-red));
        }
        [data-gs-host-scope] .linear-meter-hint { font-size: 0.72rem; opacity: 0.4; margin-top: 6px; color: #fff; }
        [data-gs-host-scope] .linear-meter-row + .linear-meter-row { margin-top: 18px; }

        /* ── Empty / error states ─────────────────────────────────── */
        [data-gs-host-scope] .empty-state-card {
            background: rgba(255,255,255,0.02);
            border: 1px dashed rgba(255,255,255,0.10);
            border-radius: 18px;
            padding: 28px;
            text-align: center;
        }
        [data-gs-host-scope] .empty-state-card p { margin: 0; color: rgba(255,255,255,0.55); font-size: 0.88rem; }
        [data-gs-host-scope] .error-console {
            background: rgba(204,0,0,0.08);
            border: 1px solid rgba(204,0,0,0.35);
            border-radius: 14px;
            padding: 14px 16px;
            color: #ffb3b3;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.82rem;
            display: none;
            margin-bottom: 14px;
        }
        [data-gs-host-scope] .error-console.is-visible { display: block; }

        /* ── Log console ─────────────────────────────────────────── */
        [data-gs-host-scope] .log-console {
            background: rgba(0,0,0,0.45);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 14px;
            max-height: 360px;
            overflow: auto;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.78rem;
            line-height: 1.5;
            color: rgba(255,255,255,0.75);
        }
        [data-gs-host-scope] .log-line { padding: 4px 6px; border-radius: 6px; }
        [data-gs-host-scope] .log-line + .log-line { margin-top: 2px; }
        [data-gs-host-scope] .log-line.is-error { color: #ff8a8a; background: rgba(204,0,0,0.08); }
        [data-gs-host-scope] .log-line.is-warn  { color: #ffd166; background: rgba(255,209,102,0.06); }

        /* ── Domain / backup list ─────────────────────────────────── */
        [data-gs-host-scope] .domain-list, [data-gs-host-scope] .backup-list { display: flex; flex-direction: column; gap: 0; }
        [data-gs-host-scope] .domain-item, [data-gs-host-scope] .backup-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 0; border-top: 1px solid rgba(255,255,255,0.05);
            gap: 12px; flex-wrap: wrap;
        }
        [data-gs-host-scope] .domain-item:first-child, [data-gs-host-scope] .backup-item:first-child { border-top: 0; }
        [data-gs-host-scope] .domain-item .di-host { color: #fff; font-weight: 700; font-size: 0.95rem; }
        [data-gs-host-scope] .domain-item .di-status {
            display: inline-block; padding: 3px 10px; border-radius: 999px;
            font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;
        }
        [data-gs-host-scope] .di-status.s-ok   { background: rgba(0,255,136,0.10); color: var(--brand-green); border: 1px solid rgba(0,255,136,0.30); }
        [data-gs-host-scope] .di-status.s-warn { background: rgba(255,209,102,0.10); color: #ffd166; border: 1px solid rgba(255,209,102,0.30); }
        [data-gs-host-scope] .di-status.s-err  { background: rgba(204,0,0,0.10); color: #ff8a8a; border: 1px solid rgba(204,0,0,0.30); }
        [data-gs-host-scope] .domain-actions, [data-gs-host-scope] .backup-actions { display: flex; gap: 6px; }

        /* ── Entrance animation ───────────────────────────────────── */
        @keyframes tabViewAssemble {
            0%   { opacity: 0; transform: translateY(22px) scale(0.985); filter: blur(8px); }
            100% { opacity: 1; transform: none;                          filter: blur(0); }
        }
        [data-gs-host-scope] .a-node { opacity: 0; }
        [data-gs-host-scope] .view-visible .a-node {
            animation: tabViewAssemble 0.9s var(--ease-out) forwards;
        }
        [data-gs-host-scope] .view-visible .a-node.x1 { animation-delay: 0.05s; }
        [data-gs-host-scope] .view-visible .a-node.x2 { animation-delay: 0.14s; }
        [data-gs-host-scope] .view-visible .a-node.x3 { animation-delay: 0.22s; }
        [data-gs-host-scope] .view-visible .a-node.x4 { animation-delay: 0.30s; }

        @media (max-width: 900px) {
            [data-gs-host-scope] .hosting-suite-container { grid-template-columns: 1fr; }
            [data-gs-host-scope] .suite-sidebar { position: relative; top: auto; flex-direction: row; flex-wrap: wrap; }
            [data-gs-host-scope] .tab-pill-trigger { flex: 1 1 auto; justify-content: center; }
            [data-gs-host-scope] .data-card-grid { grid-template-columns: 1fr 1fr; }
            [data-gs-host-scope] .glass-card-panel { padding: 22px; }
        }
        @media (prefers-reduced-motion: reduce) {
            [data-gs-host-scope] .a-node { opacity: 1 !important; animation: none !important; }
            [data-gs-host-scope] .linear-meter-fill { transition: none !important; }
        }
    </style>

    <div data-gs-host-scope id="<?php echo esc_attr( $uid ); ?>">

        <header class="gs-host-suite-header">
            <h2><?php esc_html_e( 'Hosting Management', 'gend-society' ); ?></h2>
            <p><?php esc_html_e( 'Caches, domains, error logs, tables, container resources, and backups — all wired to your live container.', 'gend-society' ); ?></p>
        </header>

        <div class="hosting-suite-container">
            <nav class="suite-sidebar" aria-label="<?php esc_attr_e( 'Hosting panels', 'gend-society' ); ?>">
                <?php
                $tabs = array(
                    array( 'slug' => 'dashboard',  'label' => __( 'Dashboard',  'gend-society' ), 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>' ),
                    array( 'slug' => 'domains',    'label' => __( 'Domains',    'gend-society' ), 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20M12 2a15.3 15.3 0 0 0 0 20"/></svg>' ),
                    array( 'slug' => 'logs',       'label' => __( 'Logs',       'gend-society' ), 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>' ),
                    array( 'slug' => 'tables',     'label' => __( 'Tables',     'gend-society' ), 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.66 4 3 9 3s9-1.34 9-3V5M3 11v6c0 1.66 4 3 9 3s9-1.34 9-3v-6"/></svg>' ),
                    array( 'slug' => 'containers', 'label' => __( 'Containers', 'gend-society' ), 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>' ),
                    array( 'slug' => 'backups',    'label' => __( 'Backups',    'gend-society' ), 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3"/><polyline points="21 4 21 10 15 10"/></svg>' ),
                );
                foreach ( $tabs as $i => $t ) :
                    $is_first = ( $i === 0 ); ?>
                    <button type="button" class="tab-pill-trigger <?php echo $is_first ? 'tab-active' : ''; ?>" data-gs-host-tab="<?php echo esc_attr( $t['slug'] ); ?>">
                        <span class="pill-ico"><?php echo $t['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static SVG ?></span>
                        <span><?php echo esc_html( $t['label'] ); ?></span>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div class="suite-stage">

                <!-- ── Dashboard ───────────────────────────────────────── -->
                <section class="suite-stage-view view-active view-visible" data-gs-host-view="dashboard">
                    <div class="view-title-block a-node x1">
                        <h3><?php esc_html_e( 'Performance Dashboard', 'gend-society' ); ?></h3>
                        <p><?php esc_html_e( 'Cache controls and template-resolution diagnostics for this install.', 'gend-society' ); ?></p>
                    </div>

                    <div class="glass-card-panel a-node x2">
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Page cache', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Flush rendered pages so visitors get a fresh build on the next request.', 'gend-society' ); ?></span>
                            </div>
                            <button type="button" class="btn-action-node btn-blue" data-gs-host-action="cache-page"><?php esc_html_e( 'Clear page cache', 'gend-society' ); ?></button>
                        </div>
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Object cache', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Drop the in-memory object store. Useful after a config or option flip.', 'gend-society' ); ?></span>
                            </div>
                            <button type="button" class="btn-action-node btn-blue" data-gs-host-action="cache-object"><?php esc_html_e( 'Flush object cache', 'gend-society' ); ?></button>
                        </div>
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Template cascade', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Re-resolve template files (header/footer/single) when a theme override stops being picked up.', 'gend-society' ); ?></span>
                            </div>
                            <button type="button" class="btn-action-node" data-gs-host-action="template-reset"><?php esc_html_e( 'Reset template lookup', 'gend-society' ); ?></button>
                        </div>
                    </div>
                </section>

                <!-- ── Domains ─────────────────────────────────────────── -->
                <section class="suite-stage-view" data-gs-host-view="domains">
                    <div class="view-title-block a-node x1">
                        <h3><?php esc_html_e( 'Mapped Domains', 'gend-society' ); ?></h3>
                        <p><?php esc_html_e( 'Custom domains pointed at this container. Verification triggers a fresh DNS read on the hub.', 'gend-society' ); ?></p>
                    </div>

                    <div class="error-console" data-gs-host-error="domains"></div>

                    <div class="glass-card-panel a-node x2">
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Add a domain', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Point an A record at the hub IP first, then enter the hostname here to register and verify.', 'gend-society' ); ?></span>
                            </div>
                            <form class="inline-form" data-gs-host-form="domain-add">
                                <input type="text" class="glass-input" name="domain" placeholder="example.com" autocomplete="off" required>
                                <button type="submit" class="btn-action-node btn-blue"><?php esc_html_e( 'Add', 'gend-society' ); ?></button>
                            </form>
                        </div>
                    </div>

                    <div class="glass-card-panel a-node x3">
                        <div class="view-title-block" style="margin-bottom:14px;">
                            <h3 style="font-size:1rem !important; letter-spacing:0 !important; text-transform:uppercase !important;"><?php esc_html_e( 'Registered domains', 'gend-society' ); ?></h3>
                        </div>
                        <?php if ( empty( $domains_list ) ) : ?>
                            <div class="empty-state-card"><p><?php esc_html_e( 'No custom domains registered yet — the container is reachable via its hub-issued URL.', 'gend-society' ); ?></p></div>
                        <?php else : ?>
                            <div class="domain-list" data-gs-host-domain-list>
                                <?php foreach ( $domains_list as $d ) :
                                    $hostname = (string) ( $d['domain'] ?? ( is_string( $d ) ? $d : '' ) );
                                    $status   = strtolower( (string) ( $d['status'] ?? 'pending' ) );
                                    $klass    = in_array( $status, array( 'ok', 'verified', 'active', 'live' ), true ) ? 's-ok' : ( in_array( $status, array( 'fail', 'error', 'invalid' ), true ) ? 's-err' : 's-warn' ); ?>
                                    <div class="domain-item" data-domain="<?php echo esc_attr( $hostname ); ?>">
                                        <span class="di-host"><?php echo esc_html( $hostname ); ?></span>
                                        <span class="di-status <?php echo esc_attr( $klass ); ?>"><?php echo esc_html( $status ?: 'pending' ); ?></span>
                                        <span class="domain-actions">
                                            <button type="button" class="btn-action-node" data-gs-host-action="domain-verify" data-domain="<?php echo esc_attr( $hostname ); ?>"><?php esc_html_e( 'Verify', 'gend-society' ); ?></button>
                                            <button type="button" class="btn-action-node btn-red" data-gs-host-action="domain-remove" data-domain="<?php echo esc_attr( $hostname ); ?>"><?php esc_html_e( 'Remove', 'gend-society' ); ?></button>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ── Logs ────────────────────────────────────────────── -->
                <section class="suite-stage-view" data-gs-host-view="logs">
                    <div class="view-title-block a-node x1">
                        <h3><?php esc_html_e( 'Error Logs', 'gend-society' ); ?></h3>
                        <p><?php esc_html_e( 'Most recent entries from the WordPress debug log and the PHP-FPM error log.', 'gend-society' ); ?></p>
                    </div>

                    <div class="error-console" data-gs-host-error="logs"></div>

                    <div class="glass-card-panel a-node x2">
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Tail the latest entries', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Pulls the last ~200 lines across the WP debug + PHP error logs.', 'gend-society' ); ?></span>
                            </div>
                            <button type="button" class="btn-action-node btn-blue" data-gs-host-action="logs-refresh"><?php esc_html_e( 'Refresh logs', 'gend-society' ); ?></button>
                        </div>
                    </div>

                    <div class="glass-card-panel a-node x3">
                        <div class="log-console" data-gs-host-log-out>
                            <p style="opacity:0.55; margin:0;"><?php esc_html_e( 'Press Refresh logs to pull the latest entries.', 'gend-society' ); ?></p>
                        </div>
                    </div>
                </section>

                <!-- ── Tables ──────────────────────────────────────────── -->
                <section class="suite-stage-view" data-gs-host-view="tables">
                    <div class="view-title-block a-node x1">
                        <h3><?php esc_html_e( 'Database Tables', 'gend-society' ); ?></h3>
                        <p><?php esc_html_e( 'Live MySQL footprint for this container. Run safe ad-hoc queries through the web app admin.', 'gend-society' ); ?></p>
                    </div>

                    <div class="data-card-grid">
                        <div class="metric-data-box a-node x1">
                            <span class="metric-meta"><?php esc_html_e( 'Tables', 'gend-society' ); ?></span>
                            <div class="metric-value"><?php echo esc_html( number_format_i18n( $tables_count ) ); ?></div>
                            <div class="metric-sub"><?php esc_html_e( 'distinct schema objects', 'gend-society' ); ?></div>
                        </div>
                        <div class="metric-data-box a-node x2">
                            <span class="metric-meta"><?php esc_html_e( 'Total rows', 'gend-society' ); ?></span>
                            <div class="metric-value"><?php echo esc_html( number_format_i18n( $tables_rows ) ); ?></div>
                            <div class="metric-sub"><?php esc_html_e( 'aggregate row count', 'gend-society' ); ?></div>
                        </div>
                        <div class="metric-data-box a-node x3">
                            <span class="metric-meta"><?php esc_html_e( 'On disk', 'gend-society' ); ?></span>
                            <div class="metric-value"><?php echo esc_html( size_format( $tables_bytes, 2 ) ); ?></div>
                            <div class="metric-sub"><?php esc_html_e( 'data + indexes', 'gend-society' ); ?></div>
                        </div>
                        <div class="metric-data-box a-node x4">
                            <span class="metric-meta"><?php esc_html_e( 'Largest table', 'gend-society' ); ?></span>
                            <div class="metric-value" style="font-size:1rem; font-family:'Inter', system-ui, sans-serif; letter-spacing:0;"><?php echo esc_html( $largest_tbl ?: '—' ); ?></div>
                            <div class="metric-sub"><?php esc_html_e( 'by combined size', 'gend-society' ); ?></div>
                        </div>
                    </div>

                    <div class="glass-card-panel a-node x4">
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Launch table runner', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Opens the wp-admin Tables + Queries surface in the linked web app for full control.', 'gend-society' ); ?></span>
                            </div>
                            <?php
                            $hosting_app_link = '';
                            if ( function_exists( 'wu_get_membership' ) && function_exists( 'groups_get_groupmeta' ) ) {
                                $mid = (int) groups_get_groupmeta( $group_id, 'gdc_membership_id' );
                                if ( $mid ) {
                                    $mem = wu_get_membership( $mid );
                                    if ( $mem && method_exists( $mem, 'get_sites' ) ) {
                                        $sites = $mem->get_sites();
                                        if ( ! empty( $sites[0] ) && method_exists( $sites[0], 'get_active_site_url' ) ) {
                                            $hosting_app_link = (string) $sites[0]->get_active_site_url();
                                        } elseif ( ! empty( $sites[0] ) && method_exists( $sites[0], 'get_site_url' ) ) {
                                            $hosting_app_link = (string) $sites[0]->get_site_url();
                                        }
                                    }
                                }
                            }
                            $tables_href = $hosting_app_link ? trailingslashit( $hosting_app_link ) . 'wp-admin/admin.php?page=gs-dashboard#hosting/tables' : '';
                            ?>
                            <?php if ( $tables_href ) : ?>
                                <a class="btn-action-node btn-blue" href="<?php echo esc_url( $tables_href ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open table runner →', 'gend-society' ); ?></a>
                            <?php else : ?>
                                <span class="btn-action-node btn-disabled"><?php esc_html_e( 'Container not paired', 'gend-society' ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- ── Containers ──────────────────────────────────────── -->
                <section class="suite-stage-view" data-gs-host-view="containers">
                    <div class="view-title-block a-node x1">
                        <h3><?php esc_html_e( 'Container Resources', 'gend-society' ); ?></h3>
                        <p><?php esc_html_e( 'Plan caps for storage and compute. Meters animate to the live values whenever the panel is opened.', 'gend-society' ); ?></p>
                    </div>

                    <div class="glass-card-panel a-node x2">
                        <div class="linear-meter-container">
                            <div class="linear-meter-row">
                                <div class="linear-meter-head">
                                    <span class="lm-name"><?php esc_html_e( 'Media storage', 'gend-society' ); ?></span>
                                    <span class="lm-readout">
                                        <?php
                                        $m = $res_by_slug['media'] ?? array();
                                        echo esc_html( ( $m['used_label'] ?? '0 B' ) . ' / ' . ( $m['cap_label'] ?? '—' ) );
                                        ?>
                                    </span>
                                </div>
                                <div class="linear-meter-track">
                                    <div class="linear-meter-fill <?php echo $media_pct >= 90 ? 'is-danger' : ''; ?>" data-meter-target="<?php echo esc_attr( $media_pct ); ?>"></div>
                                </div>
                                <div class="linear-meter-hint"><?php echo esc_html( $m['meta'] ?? '' ); ?></div>
                            </div>

                            <div class="linear-meter-row">
                                <div class="linear-meter-head">
                                    <span class="lm-name"><?php esc_html_e( 'Database storage', 'gend-society' ); ?></span>
                                    <span class="lm-readout">
                                        <?php
                                        $db = $res_by_slug['database'] ?? array();
                                        echo esc_html( ( $db['used_label'] ?? '0 B' ) . ' / ' . ( $db['cap_label'] ?? '—' ) );
                                        ?>
                                    </span>
                                </div>
                                <div class="linear-meter-track">
                                    <div class="linear-meter-fill <?php echo $database_pct >= 90 ? 'is-danger' : ''; ?>" data-meter-target="<?php echo esc_attr( $database_pct ); ?>"></div>
                                </div>
                                <div class="linear-meter-hint"><?php echo esc_html( $db['meta'] ?? '' ); ?></div>
                            </div>

                            <div class="linear-meter-row">
                                <div class="linear-meter-head">
                                    <span class="lm-name"><?php esc_html_e( 'Compute (CPU · RAM)', 'gend-society' ); ?></span>
                                    <span class="lm-readout">
                                        <?php
                                        $c = $res_by_slug['compute'] ?? array();
                                        echo esc_html( ( $c['used_label'] ?? '0' ) . ' / ' . ( $c['cap_label'] ?? '—' ) );
                                        ?>
                                    </span>
                                </div>
                                <div class="linear-meter-track">
                                    <div class="linear-meter-fill <?php echo $compute_pct >= 90 ? 'is-danger' : ''; ?>" data-meter-target="<?php echo esc_attr( $compute_pct ); ?>"></div>
                                </div>
                                <div class="linear-meter-hint"><?php echo esc_html( $c['meta'] ?? '' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card-panel a-node x3">
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Rescan media usage', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Force a fresh walk of the uploads PVC. Cached for one hour by default.', 'gend-society' ); ?></span>
                            </div>
                            <button type="button" class="btn-action-node" data-gs-host-action="media-rescan"><?php esc_html_e( 'Rescan now', 'gend-society' ); ?></button>
                        </div>
                    </div>
                </section>

                <!-- ── Backups ─────────────────────────────────────────── -->
                <section class="suite-stage-view" data-gs-host-view="backups">
                    <div class="view-title-block a-node x1">
                        <h3><?php esc_html_e( 'Backups', 'gend-society' ); ?></h3>
                        <p><?php esc_html_e( 'On-demand snapshots and a one-click restore for the most recent images.', 'gend-society' ); ?></p>
                    </div>

                    <div class="error-console" data-gs-host-error="backups"></div>

                    <div class="glass-card-panel a-node x2">
                        <div class="action-row">
                            <div class="ar-text">
                                <strong><?php esc_html_e( 'Take a backup now', 'gend-society' ); ?></strong>
                                <span><?php esc_html_e( 'Queues an immediate full snapshot — database + uploads — on the hub.', 'gend-society' ); ?></span>
                            </div>
                            <button type="button" class="btn-action-node btn-blue" data-gs-host-action="backup-now"><?php esc_html_e( 'Backup now', 'gend-society' ); ?></button>
                        </div>
                    </div>

                    <div class="glass-card-panel a-node x3">
                        <div class="view-title-block" style="margin-bottom:14px;">
                            <h3 style="font-size:1rem !important; letter-spacing:0 !important; text-transform:uppercase !important;"><?php esc_html_e( 'Recent snapshots', 'gend-society' ); ?></h3>
                        </div>
                        <?php if ( empty( $backups_list ) ) : ?>
                            <div class="empty-state-card"><p><?php esc_html_e( 'No backups recorded yet. Take a manual snapshot or wait for the next scheduled job.', 'gend-society' ); ?></p></div>
                        <?php else : ?>
                            <div class="backup-list">
                                <?php foreach ( $backups_list as $b ) :
                                    $bid   = (int) ( $b['id'] ?? 0 );
                                    $label = (string) ( $b['label'] ?? ( $b['created_at'] ?? '' ) );
                                    $size  = isset( $b['size_bytes'] ) ? size_format( (int) $b['size_bytes'], 1 ) : ''; ?>
                                    <div class="backup-item">
                                        <span style="color:#fff; font-weight:600;"><?php echo esc_html( $label ?: ( '#' . $bid ) ); ?></span>
                                        <?php if ( $size ) : ?><span style="color:rgba(255,255,255,0.45); font-size:0.8rem;"><?php echo esc_html( $size ); ?></span><?php endif; ?>
                                        <span class="backup-actions">
                                            <button type="button" class="btn-action-node btn-blue" data-gs-host-action="backup-restore" data-backup-id="<?php echo esc_attr( $bid ); ?>"><?php esc_html_e( 'Restore', 'gend-society' ); ?></button>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            </div><!-- /.suite-stage -->
        </div><!-- /.hosting-suite-container -->
    </div><!-- /[data-gs-host-scope] -->

    <script>
    (function(){
        var root  = document.getElementById('<?php echo esc_js( $uid ); ?>');
        if (!root) return;
        var ajax  = <?php echo wp_json_encode( $ajax_url ); ?>;
        var nonce = <?php echo wp_json_encode( $nonce ); ?>;

        // ── Tab switching ────────────────────────────────────────────
        var triggers = root.querySelectorAll('[data-gs-host-tab]');
        var views    = root.querySelectorAll('[data-gs-host-view]');
        function activate(slug) {
            triggers.forEach(function(t){ t.classList.toggle('tab-active', t.getAttribute('data-gs-host-tab') === slug); });
            views.forEach(function(v){
                var match = v.getAttribute('data-gs-host-view') === slug;
                v.classList.toggle('view-active', match);
                v.classList.remove('view-visible');
                if (match) {
                    // Force reflow so the entrance animation replays cleanly.
                    void v.offsetWidth;
                    v.classList.add('view-visible');
                    replayMeters(v);
                }
            });
        }
        triggers.forEach(function(t){
            t.addEventListener('click', function(e){
                e.preventDefault();
                activate(t.getAttribute('data-gs-host-tab'));
            });
        });

        // ── Linear meter animation ───────────────────────────────────
        function replayMeters(scope) {
            var fills = scope.querySelectorAll('.linear-meter-fill');
            fills.forEach(function(f){
                f.style.width = '0%';
                var target = parseFloat(f.getAttribute('data-meter-target') || '0');
                if (isNaN(target)) target = 0;
                requestAnimationFrame(function(){
                    requestAnimationFrame(function(){
                        f.style.width = Math.max(0, Math.min(100, target)) + '%';
                    });
                });
            });
        }
        // Initial paint of the active view's meters.
        replayMeters(root.querySelector('.suite-stage-view.view-active') || root);

        // ── 3D mouse tilt on metric boxes ────────────────────────────
        var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!prefersReducedMotion) {
            var boxes = root.querySelectorAll('.metric-data-box');
            boxes.forEach(function(box){
                box.addEventListener('mousemove', function(e){
                    var r = box.getBoundingClientRect();
                    var x = (e.clientX - r.left) / r.width  - 0.5;
                    var y = (e.clientY - r.top)  / r.height - 0.5;
                    var rx = (-y * 8).toFixed(2);
                    var ry = ( x * 8).toFixed(2);
                    box.style.transform = 'perspective(700px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) translateZ(2px)';
                });
                box.addEventListener('mouseleave', function(){
                    box.style.transform = '';
                });
            });
        }

        // ── AJAX helpers ─────────────────────────────────────────────
        function post(action, extra) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce',  nonce);
            if (extra) {
                Object.keys(extra).forEach(function(k){ body.append(k, extra[k]); });
            }
            return fetch(ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function(r){ return r.json(); });
        }
        function showError(key, msg) {
            var el = root.querySelector('[data-gs-host-error="' + key + '"]');
            if (!el) return;
            if (!msg) { el.classList.remove('is-visible'); el.textContent = ''; return; }
            el.textContent = msg;
            el.classList.add('is-visible');
        }
        function busy(btn, on) {
            if (!btn) return;
            if (on) {
                btn.dataset.prev = btn.textContent;
                btn.textContent  = '<?php echo esc_js( __( 'Working…', 'gend-society' ) ); ?>';
                btn.classList.add('btn-disabled');
            } else {
                if (btn.dataset.prev) btn.textContent = btn.dataset.prev;
                btn.classList.remove('btn-disabled');
            }
        }

        // ── Action delegation ────────────────────────────────────────
        root.addEventListener('click', function(e){
            var btn = e.target.closest('[data-gs-host-action]');
            if (!btn) return;
            e.preventDefault();
            var action = btn.getAttribute('data-gs-host-action');
            var domain = btn.getAttribute('data-domain') || '';
            var bid    = btn.getAttribute('data-backup-id') || '';

            switch (action) {
                case 'cache-page':       fire('gs_hosting_cache_page',       null, btn); break;
                case 'cache-object':     fire('gs_hosting_cache_object',     null, btn); break;
                case 'template-reset':   fire('gs_hosting_template_reset',   null, btn); break;
                case 'logs-refresh':     refreshLogs(btn); break;
                case 'media-rescan':     fire('gs_hosting_media_rescan',     null, btn); break;
                case 'backup-now':       fire('gs_membership_backup_now',    null, btn, 'backups'); break;
                case 'backup-restore':
                    if (!confirm('<?php echo esc_js( __( 'Restore this snapshot? The container will be overwritten with its contents.', 'gend-society' ) ); ?>')) return;
                    fire('gs_membership_backup_restore', { backup_id: bid }, btn, 'backups');
                    break;
                case 'domain-verify':    fire('gs_membership_domain_verify', { domain: domain }, btn, 'domains'); break;
                case 'domain-remove':
                    if (!confirm('<?php echo esc_js( __( 'Remove this domain mapping?', 'gend-society' ) ); ?>')) return;
                    fire('gs_membership_domain_remove', { domain: domain }, btn, 'domains', true);
                    break;
            }
        });

        function fire(action, extra, btn, errorKey, removeRowOnSuccess) {
            if (errorKey) showError(errorKey, '');
            busy(btn, true);
            post(action, extra).then(function(resp){
                busy(btn, false);
                if (!resp || !resp.success) {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Request failed.', 'gend-society' ) ); ?>';
                    if (errorKey) showError(errorKey, msg); else alert(msg);
                    return;
                }
                if (removeRowOnSuccess) {
                    var row = btn.closest('.domain-item, .backup-item');
                    if (row && row.parentNode) row.parentNode.removeChild(row);
                }
            }).catch(function(){
                busy(btn, false);
                if (errorKey) showError(errorKey, '<?php echo esc_js( __( 'Network error.', 'gend-society' ) ); ?>');
            });
        }

        // ── Domain add form ──────────────────────────────────────────
        var domainForm = root.querySelector('[data-gs-host-form="domain-add"]');
        if (domainForm) {
            domainForm.addEventListener('submit', function(e){
                e.preventDefault();
                showError('domains', '');
                var input = domainForm.querySelector('input[name="domain"]');
                var val = (input && input.value || '').trim().toLowerCase();
                if (!val) return;
                var btn = domainForm.querySelector('button[type="submit"]');
                busy(btn, true);
                post('gs_membership_domain_add', { domain: val }).then(function(resp){
                    busy(btn, false);
                    if (!resp || !resp.success) {
                        showError('domains', (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Could not add the domain.', 'gend-society' ) ); ?>');
                        return;
                    }
                    if (input) input.value = '';
                    // Re-render list optimistically — full hydrate is up to a page refresh.
                    var list = root.querySelector('[data-gs-host-domain-list]');
                    var html = '<div class="domain-item" data-domain="' + escapeHtml(val) + '">' +
                        '<span class="di-host">' + escapeHtml(val) + '</span>' +
                        '<span class="di-status s-warn">pending</span>' +
                        '<span class="domain-actions">' +
                            '<button type="button" class="btn-action-node" data-gs-host-action="domain-verify" data-domain="' + escapeHtml(val) + '"><?php echo esc_js( __( 'Verify', 'gend-society' ) ); ?></button>' +
                            '<button type="button" class="btn-action-node btn-red" data-gs-host-action="domain-remove" data-domain="' + escapeHtml(val) + '"><?php echo esc_js( __( 'Remove', 'gend-society' ) ); ?></button>' +
                        '</span>' +
                    '</div>';
                    if (list) {
                        list.insertAdjacentHTML('beforeend', html);
                    } else {
                        // No list yet (empty-state was showing) — drop it in.
                        var empty = root.querySelector('[data-gs-host-view="domains"] .empty-state-card');
                        if (empty && empty.parentNode) {
                            empty.outerHTML = '<div class="domain-list" data-gs-host-domain-list>' + html + '</div>';
                        }
                    }
                }).catch(function(){
                    busy(btn, false);
                    showError('domains', '<?php echo esc_js( __( 'Network error.', 'gend-society' ) ); ?>');
                });
            });
        }

        // ── Logs ─────────────────────────────────────────────────────
        function refreshLogs(btn) {
            showError('logs', '');
            busy(btn, true);
            var out = root.querySelector('[data-gs-host-log-out]');
            if (out) out.innerHTML = '<p style="opacity:0.55; margin:0;">' + '<?php echo esc_js( __( 'Loading…', 'gend-society' ) ); ?>' + '</p>';
            post('gs_hosting_logs').then(function(resp){
                busy(btn, false);
                if (!resp || !resp.success) {
                    showError('logs', (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Could not read the logs.', 'gend-society' ) ); ?>');
                    if (out) out.innerHTML = '';
                    return;
                }
                var entries = (resp.data && resp.data.entries) || [];
                if (!entries.length) { if (out) out.innerHTML = '<p style="opacity:0.55; margin:0;">' + '<?php echo esc_js( __( 'Clean — no recent errors.', 'gend-society' ) ); ?>' + '</p>'; return; }
                var lines = entries.map(function(e){
                    var sev = (e.severity || '').toLowerCase();
                    var klass = (sev.indexOf('error') >= 0 || sev.indexOf('fatal') >= 0) ? 'is-error'
                        : (sev.indexOf('warn') >= 0 || sev.indexOf('notice') >= 0) ? 'is-warn' : '';
                    return '<div class="log-line ' + klass + '">' +
                        '<span style="opacity:0.5">[' + escapeHtml(e.timestamp || '') + '] </span>' +
                        '<span style="opacity:0.7">' + escapeHtml(e.source || '') + ': </span>' +
                        escapeHtml(e.message || '') +
                    '</div>';
                }).join('');
                if (out) out.innerHTML = lines;
            }).catch(function(){
                busy(btn, false);
                showError('logs', '<?php echo esc_js( __( 'Network error reaching the log tail.', 'gend-society' ) ); ?>');
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }
    })();
    </script>
    <?php
}

/**
 * (Legacy) Youzify-isolation CSS for the prior `.gs-hosting__*` markup.
 * The new suite uses `[data-gs-host-scope]` with its own inline CSS, so
 * this function is kept only as a fallback if a future surface wants to
 * embed the wp-admin renderer directly. Left intact to avoid breaking
 * any callers that may still reach for it.
 */
function gs_group_render_hosting_scope_styles() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <style>
        /* ── Padding from the gend-society glass panel edges ─────────── */
        [data-gs-hosting-scope] { padding: 20px clamp(16px, 3vw, 28px); }

        /* ── Youzify isolation overrides ─────────────────────────────── */
        [data-gs-hosting-scope] .gs-hosting,
        [data-gs-hosting-scope] .gs-hosting__main,
        [data-gs-hosting-scope] .gs-hosting__panel { background: transparent !important; color: #e6edf7 !important; }
        [data-gs-hosting-scope] .gs-hosting__section-title,
        [data-gs-hosting-scope] .gs-hosting__panel h4,
        [data-gs-hosting-scope] .gs-hosting__card-title,
        [data-gs-hosting-scope] .gs-hosting__panel h5 {
            font-family: inherit !important;
            color: #fff !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            font-weight: 700 !important;
        }
        [data-gs-hosting-scope] .gs-hosting__section-sub,
        [data-gs-hosting-scope] .gs-hosting__card-desc { color: #cbd5f5 !important; }

        /* Tables (Youzify paints widefat th red) */
        [data-gs-hosting-scope] table.widefat,
        [data-gs-hosting-scope] table.wp-list-table,
        [data-gs-hosting-scope] .gs-hosting__table {
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            color: #e6edf7 !important;
            border-radius: 12px;
            overflow: hidden;
        }
        [data-gs-hosting-scope] table.widefat thead,
        [data-gs-hosting-scope] table.widefat thead tr,
        [data-gs-hosting-scope] .gs-hosting__table thead,
        [data-gs-hosting-scope] .gs-hosting__table thead tr { background: transparent !important; }
        [data-gs-hosting-scope] table.widefat th,
        [data-gs-hosting-scope] table.widefat td,
        [data-gs-hosting-scope] .gs-hosting__table th,
        [data-gs-hosting-scope] .gs-hosting__table td {
            background: transparent !important;
            color: #e6edf7 !important;
            border-bottom-color: rgba(255,255,255,0.06) !important;
            box-shadow: none !important;
            text-shadow: none !important;
        }
        [data-gs-hosting-scope] table.widefat th,
        [data-gs-hosting-scope] .gs-hosting__table th {
            color: #cbd5f5 !important;
            font-weight: 700 !important;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 0.72rem;
        }
        [data-gs-hosting-scope] table.widefat tr.alternate th,
        [data-gs-hosting-scope] table.widefat tr.alternate td,
        [data-gs-hosting-scope] table.widefat tbody tr:nth-child(even) td { background: transparent !important; }
        [data-gs-hosting-scope] table.widefat tbody tr:hover td { background: rgba(255,255,255,0.03) !important; }

        /* Inputs (Youzify renders these as white panels) */
        [data-gs-hosting-scope] input[type="text"],
        [data-gs-hosting-scope] input[type="search"],
        [data-gs-hosting-scope] input[type="email"],
        [data-gs-hosting-scope] input[type="url"],
        [data-gs-hosting-scope] input[type="number"],
        [data-gs-hosting-scope] input[type="password"],
        [data-gs-hosting-scope] textarea,
        [data-gs-hosting-scope] select {
            background: rgba(0,0,0,0.25) !important;
            border: 1px solid rgba(255,255,255,0.10) !important;
            color: #fff !important;
            box-shadow: none !important;
            border-radius: 8px !important;
            padding: 8px 12px !important;
        }
        [data-gs-hosting-scope] input::placeholder,
        [data-gs-hosting-scope] textarea::placeholder { color: rgba(203,213,245,0.5) !important; }
        [data-gs-hosting-scope] input:focus,
        [data-gs-hosting-scope] textarea:focus,
        [data-gs-hosting-scope] select:focus {
            border-color: rgba(78,170,255,0.55) !important;
            outline: 0 !important;
            box-shadow: 0 0 0 2px rgba(78,170,255,0.18) !important;
        }

        /* Buttons (Youzify makes .button bright red) */
        [data-gs-hosting-scope] .button,
        [data-gs-hosting-scope] button.button,
        [data-gs-hosting-scope] input[type="submit"],
        [data-gs-hosting-scope] .gs-hosting__btn {
            background: linear-gradient(180deg, #4f46e5, #3b82f6) !important;
            border: 0 !important;
            color: #fff !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            text-shadow: none !important;
            padding: 8px 16px !important;
            font-weight: 600 !important;
            cursor: pointer !important;
        }
        [data-gs-hosting-scope] .gs-hosting__btn.is-danger {
            background: linear-gradient(180deg, #dc2626, #b91c1c) !important;
        }

        /* Sidebar nav */
        [data-gs-hosting-scope] .gs-hosting__sidebar { background: transparent !important; }
        [data-gs-hosting-scope] .gs-hosting__nav {
            background: transparent !important;
            color: var(--gs-muted, #94a3b8) !important;
            border: 0 !important;
            text-shadow: none !important;
        }
        [data-gs-hosting-scope] .gs-hosting__nav:hover { background: rgba(255,255,255,0.04) !important; color: #fff !important; }
        [data-gs-hosting-scope] .gs-hosting__nav.is-active {
            background: rgba(78,170,255,0.12) !important;
            color: #4eaaff !important;
        }

        /* ── On-scroll entrance animations ───────────────────────────── */
        @keyframes gs-host-rise {
            from { opacity: 0; transform: translateY(18px); filter: blur(2px); }
            to   { opacity: 1; transform: none; filter: none; }
        }
        [data-gs-hosting-scope] .gs-anim-target {
            opacity: 0;
            transform: translateY(18px);
            will-change: opacity, transform, filter;
        }
        [data-gs-hosting-scope] .gs-anim-target.gs-anim-in {
            animation: gs-host-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        @media (prefers-reduced-motion: reduce) {
            [data-gs-hosting-scope] .gs-anim-target { opacity: 1 !important; transform: none !important; animation: none !important; }
        }
    </style>
    <script>
    (function(){
        // Tag every visible top-level item inside the hosting tab and
        // observe each with IntersectionObserver. When the item enters
        // the viewport, .gs-anim-in fires the keyframe. Per-item
        // animationDelay (clamped at 14*40ms) creates the cascade feel
        // without dragging the last items too far out on long lists.
        function gsHostingObserveOnce() {
            var scopes = document.querySelectorAll('[data-gs-hosting-scope]');
            if (!scopes.length) return;
            var selector = [
                '.gs-hosting__nav',
                '.gs-hosting__card',
                '.gs-hosting__stat',
                '.gs-hosting__resource',
                '.gs-hosting__panel > h4',
                '.gs-hosting__panel > p',
                '.gs-hosting__panel > .gs-hosting__filter-row',
                '.gs-hosting__panel > .gs-hosting__stat-grid',
                '.gs-hosting__panel > div',
                '.gs-hosting__panel > section',
                '.gs-hosting__panel > table'
            ].join(',');
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            scopes.forEach(function(scope){
                var items = scope.querySelectorAll(selector);
                if (!items.length) return;
                items.forEach(function(el, i){
                    if (el.classList.contains('gs-anim-target')) return;
                    el.classList.add('gs-anim-target');
                    el.style.animationDelay = (Math.min(i, 14) * 40) + 'ms';
                });
                if (!('IntersectionObserver' in window)) {
                    items.forEach(function(el){ el.classList.add('gs-anim-in'); });
                    return;
                }
                var io = new IntersectionObserver(function(entries){
                    entries.forEach(function(entry){
                        if (entry.isIntersecting) {
                            entry.target.classList.add('gs-anim-in');
                            io.unobserve(entry.target);
                        }
                    });
                }, { root: null, rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
                items.forEach(function(el){ if (!el.classList.contains('gs-anim-in')) io.observe(el); });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', gsHostingObserveOnce);
        } else {
            gsHostingObserveOnce();
        }
        // Re-scan on load (BP sub-tabs may inject content late).
        window.addEventListener('load', gsHostingObserveOnce);
    })();
    </script>
    <?php
}
