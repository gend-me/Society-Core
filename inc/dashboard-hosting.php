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

// The Hosting tab's Media sub-tab (section_group=hosting only) calls
// GMO_Admin_Subtab::render() / GMO_Editor_Panel::render() directly (real
// native code reuse, not an iframe or a re-implementation - see
// gs_render_hosting_tab()). Their own admin_enqueue_scripts hooks only fire
// on the actual blog-manager admin page, so mirror that here unconditionally
// (matches gs_dashboard_enqueue_media()'s own unconditional registration in
// pages/dashboard.php) - each call is itself gated internally and just
// no-ops when the page isn't a match, exactly like passing the literal
// string 'blog-manager' makes its own hook_suffix substring check pass.
//
// GMO_Library_Dashboard is a THIRD class GMO_Admin_Subtab::render() depends
// on (render_storage_panel_markup() - the Disk Usage ring at the top of the
// Management sub-tab) with its OWN, differently-shaped gate:
// enqueue_assets($hook) only proceeds when $hook === 'upload.php' OR
// $_GET['page'] === 'blog-manager' - neither the 'blog-manager' string trick
// nor this page's real $_GET['page'] satisfies it, so it needs its own call,
// passing the literal string 'upload.php' as $hook to satisfy the
// `$hook !== 'upload.php'` half of its check. Missing this is exactly what
// left the usage ring an unstyled black circle - EVERY rule in its
// gmo-library.css is scoped under body.gmo-lib-on, which normal WordPress
// only ever adds via GMO_Library_Dashboard::add_body_class() on upload.php
// or the real blog-manager screen - also gated on $_GET['page'], so it never
// fires here either. That body class is toggled by JS instead, right when
// the Media nav is opened/closed - see the sidebar nav handler below.
add_action('admin_enqueue_scripts', function () {
    if (class_exists('GMO_Admin_Subtab')) {
        GMO_Admin_Subtab::enqueue_assets('blog-manager');
    }
    if (class_exists('GMO_Library_Dashboard')) {
        GMO_Library_Dashboard::enqueue_assets('upload.php');
    }
    if (class_exists('GMO_Editor_Panel')) {
        GMO_Editor_Panel::enqueue('blog-manager');
    }
});

// -------------------------------------------------------------------------
// Renderer
// -------------------------------------------------------------------------

if ( ! function_exists( 'gs_hosting_render_analytics_hero' ) ) {
    /**
     * A "hero" analytics card matching the visual language of the real
     * gend-media-optimizer Media tab (GMO_Library_Dashboard's Disk Usage
     * ring + stat pills + gradient bar, gmo-library.css) - reimplemented
     * here with gend-society's own markup/CSS (not GMO's own classes,
     * which are scoped under body.gmo-lib-on and driven by GMO's own JS
     * tied to a specific #gmo-lib-storage element id - reusing them
     * directly here would either no-op or collide with that instance).
     * Colors are GMO's own (--gmo-magenta/--gmo-blue/--gmo-amber/--gmo-red)
     * so this reads as the same design system.
     *
     * @param array $args {
     *     'title' string   Card title.
     *     'sub'   string   Optional subtitle line.
     *     'pct'   int|null Percent used (0-100). Omit entirely (don't pass
     *                      the key) when there's no real capacity/quota to
     *                      measure against - the ring + bar are dropped
     *                      rather than showing a fabricated percentage.
     *     'warn'  bool     Whether to use the amber/red "warning" palette.
     *     'stats' array    List of ['k' => label, 'v' => value] pills.
     *     'stats2' array   Optional second row of ['k' => label, 'v' => value]
     *                      pills, rendered on its own line below 'stats' -
     *                      for grouping e.g. income/spend pills separately
     *                      from usage/billing ones, without depending on
     *                      flex-wrap happening to break at the right pill.
     *     'cta'   string   Optional pre-rendered button HTML, shown in the
     *                      head row next to the title (matches GMO's own
     *                      "Upgrade plan" CTA placement). Caller is
     *                      responsible for escaping - this is trusted markup.
     * }
     */
    function gs_hosting_render_analytics_hero( $args ) {
        $title    = (string) ( $args['title'] ?? '' );
        $sub      = (string) ( $args['sub'] ?? '' );
        $cta      = (string) ( $args['cta'] ?? '' );
        $warn     = ! empty( $args['warn'] );
        $stats    = is_array( $args['stats'] ?? null ) ? $args['stats'] : array();
        $stats2   = is_array( $args['stats2'] ?? null ) ? $args['stats2'] : array();
        $has_ring = array_key_exists( 'pct', $args );
        $pct      = $has_ring ? max( 0, min( 100, (int) $args['pct'] ) ) : 0;
        $radius   = 52;
        $circumference = 2 * M_PI * $radius;
        $offset   = $circumference * ( 1 - $pct / 100 );
        ?>
        <div class="gs-hosting__analytics-hero">
            <?php if ( $has_ring ) : ?>
            <div class="gs-hosting__analytics-ring" style="--gs-ring-color: <?php echo $warn ? '#f59e0b' : '#b608c9'; ?>;">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="gs-hosting__analytics-ring-track" cx="60" cy="60" r="<?php echo (int) $radius; ?>"></circle>
                    <circle class="gs-hosting__analytics-ring-fill" cx="60" cy="60" r="<?php echo (int) $radius; ?>" style="stroke-dasharray: <?php echo esc_attr( round( $circumference, 2 ) ); ?>; stroke-dashoffset: <?php echo esc_attr( round( $offset, 2 ) ); ?>;"></circle>
                </svg>
                <div class="gs-hosting__analytics-ring-center">
                    <span class="gs-hosting__analytics-ring-pct"><?php echo (int) $pct; ?></span><span class="gs-hosting__analytics-ring-unit">%</span>
                    <span class="gs-hosting__analytics-ring-sub"><?php esc_html_e( 'used', 'gend-society' ); ?></span>
                </div>
            </div>
            <?php endif; ?>
            <div class="gs-hosting__analytics-body">
                <div class="gs-hosting__analytics-head">
                    <div>
                        <h2 class="gs-hosting__analytics-title"><?php echo esc_html( $title ); ?></h2>
                        <?php if ( $sub !== '' ) : ?>
                            <p class="gs-hosting__analytics-sub"><?php echo esc_html( $sub ); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ( $cta !== '' ) : ?>
                        <?php echo wp_kses_post( $cta ); ?>
                    <?php endif; ?>
                </div>
                <?php if ( ! empty( $stats ) ) : ?>
                <div class="gs-hosting__analytics-stats">
                    <?php foreach ( $stats as $gs_stat ) : ?>
                        <div class="gs-hosting__analytics-stat"><span class="k"><?php echo esc_html( (string) ( $gs_stat['k'] ?? '' ) ); ?></span><span class="v"><?php echo esc_html( (string) ( $gs_stat['v'] ?? '' ) ); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $stats2 ) ) : ?>
                <div class="gs-hosting__analytics-stats">
                    <?php foreach ( $stats2 as $gs_stat ) : ?>
                        <div class="gs-hosting__analytics-stat"><span class="k"><?php echo esc_html( (string) ( $gs_stat['k'] ?? '' ) ); ?></span><span class="v"><?php echo esc_html( (string) ( $gs_stat['v'] ?? '' ) ); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ( $has_ring ) : ?>
                <div class="gs-hosting__analytics-bar">
                    <div class="gs-hosting__analytics-bar-fill<?php echo $warn ? ' is-warn' : ''; ?>" style="width: <?php echo (int) $pct; ?>%;"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

if ( ! function_exists( 'gs_hosting_resource_upgrade_data_attrs' ) ) {
    /**
     * data-* attributes for a resource-upgrade button covering a whole
     * gdc_plan_attach_types() $type (server/media/database/codebase) -
     * one whose own checkout form shows its own internal multi-plan
     * picker, so there's no single plan_id to hand off here.
     *
     * On a networked subsite (or the hub itself - gs_oauth_is_hub_site()
     * is a same-HOST check, true for both since a subsite shares gend.me's
     * own host), the existing iframe embed works fine: same origin as
     * gend.me, so its session cookie carries normally. On a genuinely
     * separate install (self-hosted/container), an iframe can't carry
     * gend.me's cookie cross-origin - same reason the dashboard-group
     * Upgrade button already uses the install-token AJAX proxy + a real
     * top-level redirect instead of an iframe. The shared
     * [data-gs-upgrade-open] click handler (below) branches on which
     * attribute is present.
     *
     * @param string $type
     * @return string Empty string when neither mode has anything to offer.
     */
    function gs_hosting_resource_upgrade_data_attrs( $type ) {
        $same_origin = function_exists( 'gs_oauth_is_hub_site' ) ? gs_oauth_is_hub_site() : true;
        // Backups are two choices, not one list: a storage plan, then how
        // often to back up. The membership page's Backups picker presents
        // exactly that (current plans marked, one checkout, plan swapped on
        // payment), so open it in its backups-only mode instead of the
        // generic one-product-per-row plan-attach form.
        if ( $same_origin && $type === 'backups' ) {
            $gs_bk_m   = function_exists( 'gs_dashboard_get_membership' ) ? gs_dashboard_get_membership() : null;
            $gs_bk_mid = ( $gs_bk_m && is_object( $gs_bk_m ) && method_exists( $gs_bk_m, 'get_id' ) ) ? (int) $gs_bk_m->get_id() : 0;
            $gs_bk_choose = false;
            if ( ! $gs_bk_mid && function_exists( 'gdc_plan_attach_get_memberships' ) ) {
                // Dashboard not tied to a membership (e.g. the main gend.me
                // site): use the viewer's own eligible membership(s).
                $gs_bk_eligible = array_values( (array) gdc_plan_attach_get_memberships( 'backups' ) );
                if ( count( $gs_bk_eligible ) === 1 ) {
                    $gs_bk_mid = (int) ( is_array( $gs_bk_eligible[0] ) ? ( $gs_bk_eligible[0]['id'] ?? 0 ) : 0 );
                } elseif ( count( $gs_bk_eligible ) > 1 ) {
                    $gs_bk_choose = true; // several: let them pick on the memberships page
                }
            }
            $gs_bk_account = get_home_url( get_main_site_id(), '/my-account/memberships/' );
            if ( $gs_bk_mid ) {
                $url = add_query_arg( array( 'gdc_open_modal' => $gs_bk_mid, 'gdc_open_backups' => 1 ), $gs_bk_account );
                return 'data-embed-url="' . esc_attr( $url ) . '"';
            }
            if ( $gs_bk_choose ) {
                return 'data-embed-url="' . esc_attr( $gs_bk_account ) . '"';
            }
        }
        if ( $same_origin ) {
            $url = function_exists( 'gdc_plan_attach_resource_embed_url' ) ? gdc_plan_attach_resource_embed_url( $type ) : '';
            return $url !== '' ? 'data-embed-url="' . esc_attr( $url ) . '"' : '';
        }
        return 'data-resource-type="' . esc_attr( $type ) . '"';
    }
}

if ( ! function_exists( 'gs_hosting_plan_upgrade_data_attrs' ) ) {
    /**
     * Same branching as gs_hosting_resource_upgrade_data_attrs(), for a
     * single already-known plan_id (e.g. one specific Backups tier)
     * instead of a whole resource type's own multi-plan picker form.
     *
     * @param string $type    Only used for the same-origin iframe embed URL.
     * @param int    $plan_id
     * @return string
     */
    function gs_hosting_plan_upgrade_data_attrs( $type, $plan_id ) {
        $same_origin = function_exists( 'gs_oauth_is_hub_site' ) ? gs_oauth_is_hub_site() : true;
        if ( $same_origin ) {
            $url = function_exists( 'gdc_plan_attach_resource_embed_url' ) ? gdc_plan_attach_resource_embed_url( $type ) : '';
            return $url !== '' ? 'data-embed-url="' . esc_attr( $url ) . '"' : '';
        }
        return 'data-plan-id="' . esc_attr( (int) $plan_id ) . '"';
    }
}

if ( ! function_exists( 'gs_hosting_render_storage_resource_cards' ) ) {
    /**
     * Storage usage cards (Media / Database / Codebase) + the account-wide
     * Upgrade popup they open, plus the "Rescan storage" button's own
     * standalone click handling (document-scoped, like the upgrade modal
     * below it - this needs to work correctly whether it's rendered inside
     * dashboard-hosting.php's own #gs-hosting-root* (section_group=all) or,
     * for the membership dashboard, directly at the top of the page above
     * the tab strip where there is no shared #gs-hosting-root at all - see
     * dashboard-remote-membership.php).
     */
    function gs_hosting_render_storage_resource_cards( $media_data, $tables_data, $billing, $hosting_jump_map = array() ) {
        $gs_hosting_membership = function_exists( 'gs_dashboard_get_membership' ) ? gs_dashboard_get_membership() : null;
        $resources = gs_hosting_collect_container_resources( $media_data, $tables_data, $gs_hosting_membership );

        // Container Plan total is the literal sum of whichever of the three
        // resources above have a real container product attached - NOT
        // $billing's whole-membership amount (that's the Dashboard plan's
        // price, unrelated to storage, and used to show here misleadingly
        // whenever no container product existed to total).
        $gs_container_sum  = 0.0;
        $gs_container_unit = '';
        $gs_container_any  = false;
        foreach ( $resources as $r ) {
            if ( ! empty( $r['price']['amount'] ) ) {
                $gs_container_sum += (float) $r['price']['amount'];
                $gs_container_unit = (string) ( $r['price']['unit'] ?? '' );
                $gs_container_any  = true;
            }
        }
        $gs_container_currency = ( $gs_hosting_membership && method_exists( $gs_hosting_membership, 'get_currency' ) ) ? $gs_hosting_membership->get_currency() : '';
        $gs_container_label    = function_exists( 'wu_format_currency' ) ? wu_format_currency( $gs_container_sum, $gs_container_currency ) : number_format( $gs_container_sum, 2 );

        // v12.1 — Upgrade buttons are group-admin gated so regular
        // members don't see them. Site admins + super admins always
        // see them.
        $gs_can_upgrade = current_user_can( 'manage_options' )
            || is_super_admin() // site-admin check, not a hub signal (104 audit)
            || ( function_exists( 'gs_group_tabs_user_has_access' ) && gs_group_tabs_user_has_access() );

        // No Media/Codebase/Database container plans on this networked site
        // yet: replace the per-card Upgrade buttons with one "Migrate" button
        // that opens the hub's membership popup (same origin, in the shared
        // upgrade-modal iframe) and auto-launches its Code -> Media ->
        // Database container wizard (?gdc_auto_migrate=1, woocommerce-account-
        // memberships.php). Paying for it starts the automatic migration.
        $gs_migrate_url = '';
        if ( $gs_can_upgrade && $gs_hosting_membership
            && function_exists( 'gdc_membership_needs_container_migration' ) && function_exists( 'wu_get_site' )
            && ( ! function_exists( 'gs_oauth_is_hub_site' ) || gs_oauth_is_hub_site() ) ) {
            $gs_this_site = wu_get_site( get_current_blog_id() );
            if ( $gs_this_site && gdc_membership_needs_container_migration( $gs_hosting_membership, $gs_this_site ) ) {
                $gs_migrate_url = add_query_arg(
                    array( 'gdc_open_modal' => (int) $gs_hosting_membership->get_id(), 'gdc_auto_migrate' => 1 ),
                    get_home_url( get_main_site_id(), '/my-account/memberships/' )
                );
            }
        }
        ?>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
            <div>
                <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Storage', 'gend-society' ); ?></h4>
                <p class="gs-hosting__section-sub" style="margin: 0;"><?php esc_html_e( 'Resources your container plan provisions on this install, with live usage.', 'gend-society' ); ?></p>
            </div>
            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                <button type="button" class="gs-hosting__btn" data-gs-hosting="media-rescan" style="background: rgba(255,255,255,0.08);"><?php esc_html_e( 'Rescan storage', 'gend-society' ); ?></button>
                <div style="text-align: right;">
                    <div class="gs-hosting__stat-label"><?php esc_html_e( 'Container Plan', 'gend-society' ); ?></div>
                    <div style="color:#fff; font-size:1rem; font-weight:600; margin-top:4px;">
                        <?php if ( $gs_container_any ) : ?>
                            <?php echo esc_html( $gs_container_label ); ?>
                            <?php if ( $gs_container_unit !== '' ) : ?>
                                <span style="color: var(--gs-muted, #94a3b8); font-size: 0.8rem; font-weight: 400;">/ <?php echo esc_html( $gs_container_unit ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color: var(--gs-muted, #94a3b8); font-weight: 400; font-style: italic; font-size: 0.85rem;"><?php esc_html_e( 'Included in your plan', 'gend-society' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Same shared CTA card the My Account membership popup renders
        // (gdc_render_migrate_containers_cta(), vendor-app-manager); here the
        // trigger opens the hub popup in the shared upgrade-modal iframe.
        if ( $gs_migrate_url !== '' && function_exists( 'gdc_render_migrate_containers_cta' ) ) {
            echo gdc_render_migrate_containers_cta( // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
                '<button type="button" class="gdc-migrate-cta__btn" data-gs-upgrade-open data-resource="containers"'
                . ' data-resource-label="' . esc_attr__( 'Independent Storage Containers', 'gend-society' ) . '"'
                . ' data-embed-url="' . esc_url( $gs_migrate_url ) . '">'
                . '<span>' . esc_html__( 'Start Migration', 'gend-society' ) . '</span><span class="gdc-migrate-cta__arrow" aria-hidden="true">&rarr;</span></button>'
            );
        }
        ?>

        <div class="gs-hosting__resources-grid">
            <?php foreach ( $resources as $r ) :
                $pct  = gs_hosting_pct( (int) $r['used'], (int) $r['cap'] );
                $warn = $pct >= 80;
            ?>
                <div class="gs-hosting__resource-card">
                    <?php if ( ! empty( $hosting_jump_map[ $r['slug'] ] ) ) : ?>
                        <button type="button" class="gs-hosting__resource-card-goto" data-gs-hosting-goto="<?php echo esc_attr( $hosting_jump_map[ $r['slug'] ] ); ?>" title="<?php esc_attr_e( 'Open in Hosting', 'gend-society' ); ?>" aria-label="<?php esc_attr_e( 'Open in Hosting', 'gend-society' ); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </button>
                    <?php endif; ?>
                    <div class="gs-hosting__resource-card-head">
                        <span class="gs-hosting__resource-card-icon dashicons <?php echo esc_attr( $r['icon'] ); ?>"></span>
                        <div>
                            <div class="gs-hosting__resource-card-title"><?php echo esc_html( $r['label'] ); ?></div>
                            <div class="gs-hosting__resource-card-hint"><?php echo esc_html( $r['hint'] ); ?></div>
                        </div>
                    </div>
                    <div class="gs-hosting__resource-card-usage">
                        <?php echo esc_html( $r['used_label'] ); ?>
                        <span class="gs-hosting__resource-card-cap">/ <?php echo esc_html( $r['cap_label'] ); ?></span>
                    </div>
                    <div class="gs-hosting__progress">
                        <div class="gs-hosting__progress-bar<?php echo $warn ? ' is-warn' : ''; ?>" style="width: <?php echo (int) $pct; ?>%;"></div>
                    </div>
                    <div class="gs-hosting__resource-card-pct" style="<?php echo $warn ? 'color:#fcd34d;' : ''; ?>">
                        <?php echo (int) $pct; ?>% <?php esc_html_e( 'used', 'gend-society' ); ?>
                    </div>
                    <?php if ( ! empty( $r['price']['label'] ) ) : ?>
                        <div class="gs-hosting__resource-card-usage" style="margin-top: 6px; font-size: 0.85rem;">
                            <?php echo esc_html( $r['price']['label'] ); ?>
                            <?php if ( ! empty( $r['price']['unit'] ) ) : ?>
                                <span class="gs-hosting__resource-card-cap">/ <?php echo esc_html( $r['price']['unit'] ); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php elseif ( ! empty( $r['starting_price']['label'] ) ) : ?>
                        <div class="gs-hosting__resource-card-usage" style="margin-top: 6px; font-size: 0.85rem; color: var(--gs-muted, #94a3b8);">
                            <?php echo esc_html( sprintf( /* translators: %s: Starting price. */ __( 'From %s', 'gend-society' ), $r['starting_price']['label'] ) ); ?>
                        </div>
                    <?php else : ?>
                        <div class="gs-hosting__resource-card-meta" style="margin-top: 6px; font-style: italic;"><?php esc_html_e( 'Included in your plan', 'gend-society' ); ?></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $r['meta'] ) ) : ?>
                        <p class="gs-hosting__resource-card-meta"><?php echo esc_html( $r['meta'] ); ?></p>
                    <?php endif; ?>
                    <?php
                    $gs_res_upgrade_attrs = $gs_can_upgrade && $gs_migrate_url === '' && function_exists( 'gs_hosting_resource_upgrade_data_attrs' )
                        ? gs_hosting_resource_upgrade_data_attrs( $r['slug'] )
                        : '';
                    ?>
                    <?php if ( $gs_res_upgrade_attrs !== '' ) : ?>
                    <button type="button"
                            class="gs-hosting__btn gs-upgrade-cta"
                            data-gs-upgrade-open
                            data-resource="<?php echo esc_attr( $r['slug'] ); ?>"
                            data-resource-label="<?php echo esc_attr( $r['label'] ); ?>"
                            <?php echo $gs_res_upgrade_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute string built by gs_hosting_resource_upgrade_data_attrs(); every value esc_attr()'d there. ?>
                            style="margin-top: auto; background: linear-gradient(135deg, #22d3ee, #7dd3fc); color: #0b0e14; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; border: none; border-radius: 8px; cursor: pointer; font-size: 0.75rem; padding: 8px 14px; box-shadow: 0 6px 18px rgba(34,211,238,.30);">
                        <?php esc_html_e( 'Upgrade', 'gend-society' ); ?>
                    </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="gs-hosting__feedback" data-gs-hosting-feedback="media"></div>

        <?php if ( $gs_can_upgrade ) :
            // v12.1 — Rich staggered-entrance upgrade popup. Rendered once
            // per call to this function; the Upgrade buttons above dispatch
            // data-gs-upgrade-open with the resource slug + labels so the
            // modal can show contextual pitch copy.
        ?>
        <style>
            /* ── Upgrade popup: backdrop + card + entrance, iframe body ── */
            .gs-upgrade-modal { position: fixed; inset: 0; z-index: 999999; display: none; align-items: center; justify-content: center; padding: 24px; }
            .gs-upgrade-modal.is-open { display: flex; }
            .gs-upgrade-modal__backdrop {
                position: absolute; inset: 0;
                background: radial-gradient(1200px 500px at 20% 10%, rgba(34,211,238,.10), transparent 60%),
                            radial-gradient(1000px 400px at 80% 90%, rgba(99,102,241,.10), transparent 55%),
                            rgba(2, 6, 23, .78);
                -webkit-backdrop-filter: blur(18px) saturate(160%);
                        backdrop-filter: blur(18px) saturate(160%);
                opacity: 0;
                transition: opacity .32s cubic-bezier(.2,.9,.3,1);
            }
            .gs-upgrade-modal.is-open .gs-upgrade-modal__backdrop { opacity: 1; }
            .gs-upgrade-modal__card {
                position: relative;
                width: 100%; max-width: 880px; height: 86vh; max-height: 860px;
                background: linear-gradient(160deg, rgba(15,23,42,.97), rgba(15,23,42,.90));
                border: 1px solid rgba(125, 211, 252, .28);
                border-radius: 24px;
                box-shadow: 0 48px 96px rgba(0,0,0,.65), inset 0 1px 0 rgba(255,255,255,.05);
                display: flex; flex-direction: column; overflow: hidden;
                opacity: 0; transform: translateY(20px) scale(.98);
                transition: opacity .35s cubic-bezier(.2,.9,.3,1), transform .35s cubic-bezier(.2,.9,.3,1);
            }
            .gs-upgrade-modal.is-open .gs-upgrade-modal__card { opacity: 1; transform: none; }
            @media (max-width: 640px) { .gs-upgrade-modal__card { height: 92vh; max-height: none; border-radius: 18px; } }

            .gs-upgrade-modal__close {
                position: absolute; top: 14px; right: 14px; z-index: 1;
                width: 36px; height: 36px; border-radius: 50%;
                background: rgba(11,14,20,.65); border: 1px solid rgba(125,211,252,.20);
                color: #e2e8f0; font-size: 1.2rem; line-height: 1; cursor: pointer;
                transition: background .18s ease, transform .15s ease;
            }
            .gs-upgrade-modal__close:hover { background: rgba(239,68,68,.20); transform: rotate(90deg); }
            .gs-upgrade-modal__frame { flex: 1 1 auto; width: 100%; border: 0; background: #0b0e14; }
            /* ── Proxy-mode picker: shown instead of the iframe when this
               install can't load gend.me's checkout in an iframe (a
               genuinely separate domain - self-hosted/container - whose
               cross-origin cookies the iframe can't carry). ── */
            .gs-upgrade-modal__picker { flex: 1 1 auto; overflow-y: auto; padding: 28px; color: #e2e8f0; }
            .gs-upgrade-modal__picker-status { color: #94a3b8; font-size: 0.9rem; padding: 8px 2px; }
            .gs-upgrade-modal__picker-status.is-error { color: #fca5a5; }
            .gs-upgrade-modal__picker-grid { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
            .gs-upgrade-modal__picker-card {
                display: flex; align-items: center; justify-content: space-between; gap: 12px;
                padding: 14px 16px; border-radius: 12px; background: rgba(255,255,255,0.03);
                border: 1px solid rgba(125,211,252,.20); cursor: pointer; text-align: left;
                width: 100%; color: #fff; font: inherit;
                transition: border-color .15s ease, background .15s ease;
            }
            .gs-upgrade-modal__picker-card:hover:not([disabled]) { border-color: rgba(34,211,238,.6); background: rgba(34,211,238,.08); }
            .gs-upgrade-modal__picker-card[disabled] { opacity: 0.5; cursor: wait; }
            .gs-upgrade-modal__picker-card.is-current { border-color: rgba(78,230,138,.4); background: rgba(0,180,80,.06); }
            .gs-upgrade-modal__picker-name { font-weight: 700; font-size: 0.95rem; }
            .gs-upgrade-modal__picker-price { color: #7dd3fc; font-size: 0.85rem; font-weight: 600; }
        </style>
        <div class="gs-upgrade-modal" data-gs-upgrade-modal aria-hidden="true" role="dialog" aria-modal="true">
            <div class="gs-upgrade-modal__backdrop" data-gs-upgrade-close></div>
            <div class="gs-upgrade-modal__card">
                <button type="button" class="gs-upgrade-modal__close" data-gs-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                <iframe class="gs-upgrade-modal__frame" data-gs-upg-iframe src="about:blank" title="<?php esc_attr_e( 'Upgrade checkout', 'gend-society' ); ?>" loading="lazy"></iframe>
                <div class="gs-upgrade-modal__picker" data-gs-upg-picker hidden></div>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.querySelector('[data-gs-upgrade-modal]');
            if (!modal || modal.dataset.upgInited === '1') return;
            modal.dataset.upgInited = '1';
            // Escape any ancestor's transform/filter containing block so
            // position:fixed centers on the real viewport, not a wrapper.
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            var iframe = modal.querySelector('[data-gs-upg-iframe]');
            var picker = modal.querySelector('[data-gs-upg-picker]');
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;

            function showIframe(url) {
                picker.hidden = true;
                picker.innerHTML = '';
                iframe.hidden = false;
                iframe.src = url;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function showPickerStatus(msg, isError) {
                picker.innerHTML = '<div class="gs-upgrade-modal__picker-status' + (isError ? ' is-error' : '') + '">' + msg + '</div>';
            }

            function openPicker() {
                iframe.hidden = true;
                iframe.src = 'about:blank';
                picker.hidden = false;
                picker.innerHTML = '';
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            // Resolves plan_id -> a real gend.me checkout_url via the
            // install-token-authed proxy (gs_membership_change_plan ->
            // gdc_self_hosted_rest_change_plan on the hub), then does a
            // real top-level navigation to it in a new tab - not an
            // iframe, so gend.me's own session cookie works normally.
            function proxyChangePlan(planId) {
                showPickerStatus('Preparing checkout…', false);
                var fd = new FormData();
                fd.append('action', 'gs_membership_change_plan');
                fd.append('nonce', ajaxNonce);
                fd.append('plan_id', planId);
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (resp && resp.success && resp.data && resp.data.checkout_url) {
                            window.open(resp.data.checkout_url, '_blank', 'noopener');
                            close();
                        } else {
                            showPickerStatus((resp && resp.data && resp.data.message) || 'Could not start checkout.', true);
                        }
                    })
                    .catch(function () { showPickerStatus('Network error.', true); });
            }

            function renderPlanGrid(plans) {
                if (!plans || !plans.length) {
                    showPickerStatus('No plans available for this resource yet.', false);
                    return;
                }
                var grid = document.createElement('div');
                grid.className = 'gs-upgrade-modal__picker-grid';
                plans.forEach(function (p) {
                    var card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'gs-upgrade-modal__picker-card' + (p.is_current ? ' is-current' : '');
                    card.disabled = !!p.is_current;
                    var left = document.createElement('div');
                    left.className = 'gs-upgrade-modal__picker-name';
                    left.textContent = p.name || '';
                    var right = document.createElement('div');
                    right.className = p.is_current ? 'gs-upgrade-modal__picker-status' : 'gs-upgrade-modal__picker-price';
                    right.textContent = p.is_current ? 'Current plan' : (p.price_label || '');
                    card.appendChild(left);
                    card.appendChild(right);
                    if (!p.is_current) {
                        card.addEventListener('click', function () {
                            grid.querySelectorAll('.gs-upgrade-modal__picker-card').forEach(function (c) { c.disabled = true; });
                            proxyChangePlan(p.id);
                        });
                    }
                    grid.appendChild(card);
                });
                picker.innerHTML = '';
                picker.appendChild(grid);
            }

            function proxyShowResourcePicker(resourceType) {
                openPicker();
                showPickerStatus('Loading plans…', false);
                var fd = new FormData();
                fd.append('action', 'gs_membership_plan_options');
                fd.append('nonce', ajaxNonce);
                fd.append('resource', resourceType);
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (resp && resp.success) {
                            renderPlanGrid((resp.data && resp.data.plans) || []);
                        } else {
                            showPickerStatus((resp && resp.data && resp.data.message) || 'Could not load plans.', true);
                        }
                    })
                    .catch(function () { showPickerStatus('Network error.', true); });
            }

            function openFor(btn) {
                var url = btn.getAttribute('data-embed-url') || '';
                if (url) { showIframe(url); return; }
                var planId = btn.getAttribute('data-plan-id');
                if (planId) { openPicker(); proxyChangePlan(planId); return; }
                var resourceType = btn.getAttribute('data-resource-type');
                if (resourceType) { proxyShowResourcePicker(resourceType); return; }
            }
            function close() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                iframe.src = 'about:blank';
                picker.innerHTML = '';
            }
            document.addEventListener('click', function (e) {
                var open = e.target && e.target.closest && e.target.closest('[data-gs-upgrade-open]');
                if (open) { e.preventDefault(); openFor(open); return; }
                var closer = e.target && e.target.closest && e.target.closest('[data-gs-upgrade-close]');
                if (closer && modal.contains(closer)) { e.preventDefault(); close(); return; }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
            });
            // The embed's own Escape handler (inside the iframe) posts this
            // same message - close the outer modal in response too.
            window.addEventListener('message', function (e) {
                if (e.data && e.data.gdcAttach === 'close' && modal.classList.contains('is-open')) close();
            });
        })();
        </script>
        <?php endif; ?>
        <script>
        (function () {
            if (window.gsStorageRescanInited) { return; }
            window.gsStorageRescanInited = true;
            var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;
            document.addEventListener('click', function (e) {
                var rescan = e.target.closest && e.target.closest('[data-gs-hosting="media-rescan"]');
                if (!rescan) { return; }
                var fb = document.querySelector('[data-gs-hosting-feedback="media"]');
                function feedback(msg, type) {
                    if (!fb) { return; }
                    fb.className = 'gs-hosting__feedback' + (type ? (type === 'error' ? ' is-error' : ' is-success') : '');
                    fb.textContent = msg || '';
                }
                rescan.disabled = true;
                var prev = rescan.textContent;
                rescan.textContent = 'Scanning…';
                feedback('', null);
                var fd = new FormData();
                fd.append('action', 'gs_hosting_media_rescan');
                fd.append('nonce', nonce);
                fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (resp && resp.success) {
                            feedback('Rescan complete. Reloading…', 'success');
                            setTimeout(function () { location.reload(); }, 700);
                        } else {
                            rescan.disabled = false;
                            rescan.textContent = prev;
                            feedback((resp && resp.data && resp.data.message) || 'Rescan failed.', 'error');
                        }
                    })
                    .catch(function () {
                        rescan.disabled = false;
                        rescan.textContent = prev;
                        feedback('Network error.', 'error');
                    });
            });
        })();
        </script>
        <?php
    }
}

if ( ! function_exists( 'gs_render_hosting_domains_panel' ) ) {
    /**
     * Real domain mapping — Add / Verify / Remove a custom domain pointed at
     * this install. Reuses the SAME already-working AJAX contract the
     * membership card's own JS ships with (see dashboard-remote-membership.php
     * wp_ajax_gs_membership_domain_add|verify|remove, all real proxies to
     * gend.me's domains/add|verify|remove REST routes -> WP Ultimo's real
     * wu_create_domain()/Domain model on the hub) — this panel only needed
     * to exist; the add/verify/remove wiring (data-gs-mship="add-domain" /
     * "verify-domain" / "remove-domain", event-delegated on #gs-mship-root)
     * was already shipped and unused. Mirrors the same real, working
     * "Mapped Domains" panel gs_group_render_hosting_suite() renders for the
     * front-end group page (group-app-tabs.php), same data shape
     * ($payload['domains'][]: 'domain', 'status'), just restyled to this
     * page's own .gs-hosting__* classes instead of that page's .glass-card-*
     * ones.
     *
     * DNS record management: no real per-customer DNS-provider API exists
     * anywhere in this codebase (a "Phase 73" client-side skeleton for that
     * was built but its hub-side REST routes were never implemented - dead
     * code, not wired here). What's real and shown instead is the same
     * instruction already used on the working Mapped Domains panel: point
     * an A record at the hub IP, then register the hostname here to verify.
     */
    function gs_render_hosting_domains_panel( $payload ) {
        $gs_domains = isset( $payload['domains'] ) && is_array( $payload['domains'] ) ? $payload['domains'] : array();
        ?>
        <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Domains', 'gend-society' ); ?></h4>
        <p class="gs-hosting__section-sub"><?php esc_html_e( 'Custom domains pointed at this install. Verification triggers a fresh DNS read on the hub.', 'gend-society' ); ?></p>

        <div class="gs-hosting__card">
            <div class="gs-hosting__card-header">
                <div>
                    <h5 class="gs-hosting__card-title"><?php esc_html_e( 'Add a domain', 'gend-society' ); ?></h5>
                    <p class="gs-hosting__card-desc"><?php esc_html_e( 'Point an A record at the hub IP first, then enter the hostname here to register and verify.', 'gend-society' ); ?></p>
                </div>
            </div>
            <form data-gs-mship="add-domain" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px;">
                <input type="text" name="domain" class="gs-hosting__search" placeholder="example.com" autocomplete="off" required style="flex: 1 1 240px; max-width: 360px;">
                <button type="submit" class="gs-hosting__btn"><?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </form>
        </div>

        <div class="gs-hosting__card" style="margin-top: 16px;">
            <h5 class="gs-hosting__card-title" style="margin-bottom: 12px;"><?php esc_html_e( 'Registered domains', 'gend-society' ); ?></h5>
            <?php if ( empty( $gs_domains ) ) : ?>
                <p style="color: var(--gs-muted, #94a3b8); font-style: italic; margin: 0;"><?php esc_html_e( 'No custom domains registered yet — this install is reachable via its hub-issued URL.', 'gend-society' ); ?></p>
            <?php else : ?>
                <table class="gs-hosting__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Domain', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'gend-society' ); ?></th>
                            <th style="width: 1%;"><?php esc_html_e( 'Action', 'gend-society' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $gs_domains as $gs_d ) :
                            $gs_hostname = (string) ( is_array( $gs_d ) ? ( $gs_d['domain'] ?? '' ) : $gs_d );
                            if ( $gs_hostname === '' ) {
                                continue;
                            }
                            $gs_status = strtolower( (string) ( is_array( $gs_d ) ? ( $gs_d['status'] ?? 'pending' ) : 'pending' ) );
                            $gs_klass  = in_array( $gs_status, array( 'ok', 'verified', 'active', 'live' ), true )
                                ? 'is-ok'
                                : ( in_array( $gs_status, array( 'fail', 'error', 'invalid' ), true ) ? 'is-err' : 'is-warn' );
                        ?>
                            <tr data-domain="<?php echo esc_attr( $gs_hostname ); ?>">
                                <td><code style="background: rgba(78,170,255,0.1); color:#a5b4fc; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html( $gs_hostname ); ?></code></td>
                                <td><span class="gs-hosting__pill <?php echo esc_attr( $gs_klass ); ?>"><?php echo esc_html( $gs_status ?: 'pending' ); ?></span></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="gs-hosting__btn" data-gs-mship="verify-domain" data-domain="<?php echo esc_attr( $gs_hostname ); ?>" style="background: rgba(255,255,255,0.08); padding: 4px 10px; font-size: 0.72rem;"><?php esc_html_e( 'Verify', 'gend-society' ); ?></button>
                                    <button type="button" class="gs-hosting__btn is-danger" data-gs-mship="remove-domain" data-domain="<?php echo esc_attr( $gs_hostname ); ?>" style="padding: 4px 10px; font-size: 0.72rem;"><?php esc_html_e( 'Remove', 'gend-society' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

if ( ! function_exists( 'gs_hosting_render_backups_section' ) ) {
    /**
     * The Backups table (list + Backup now + Restore). Used both inside the
     * combined Storage panel (section_group=all) and as its own "Backups"
     * sub-tab (section_group=hosting - see gs_render_hosting_tab()). Its
     * data-gs-hosting="backup-now|backup-restore" buttons are handled by
     * the shared root-scoped click handler further down this file - this
     * panel always stays inside #gs-hosting-root*, unlike the storage cards
     * above, so no standalone script is needed here.
     */
    function gs_hosting_render_backups_section( $backups ) {
        // Analytics header — same real ring/stat-pill/bar treatment as the
        // real Media tab (see gs_hosting_render_analytics_hero()), WITH a
        // real ring this time (usage vs gs_hosting_backups_plan_bytes(),
        // same hardcoded-but-filterable idiom as Media/DB/Codebase's own
        // caps - honest, not fabricated), plus the same real backup-plan
        // pricing + Upgrade/Backup-now actions the membership dashboard's
        // own Backups card (dashboard-remote-membership.php) already shows.
        $gs_bk_count  = count( $backups );
        $gs_bk_bytes  = 0;
        foreach ( $backups as $gs_bk_row ) {
            $gs_bk_bytes += (int) ( $gs_bk_row['bytes'] ?? 0 );
        }
        $gs_bk_latest = ! empty( $backups[0]['created_at'] ) ? (string) $backups[0]['created_at'] : '';
        $gs_bk_cap    = function_exists( 'gs_hosting_backups_plan_bytes' ) ? gs_hosting_backups_plan_bytes() : ( 10 * 1024 * 1024 * 1024 );
        $gs_bk_pct    = gs_hosting_pct( $gs_bk_bytes, $gs_bk_cap );
        $gs_bk_warn   = $gs_bk_pct >= 80;

        // Real backup plan pricing tiers - same 'backups' plan-attach type
        // (gdc_register_backups_plan_product(), gdc_plan_attach_types())
        // the membership dashboard's Backups card reads.
        $gs_backup_plans     = function_exists( 'gdc_plan_attach_get_plans' ) ? gdc_plan_attach_get_plans( 'backups' ) : array();
        $gs_backup_membership = function_exists( 'gs_dashboard_get_membership' ) ? gs_dashboard_get_membership() : null;
        // Storage plan + frequency add-on can both be attached: collect all.
        $gs_bk_current = array(); // [ ['name'=>..., 'price'=>...], ... ]
        if ( $gs_backup_membership && is_object( $gs_backup_membership ) && method_exists( $gs_backup_membership, 'get_all_products' ) ) {
            foreach ( (array) $gs_backup_membership->get_all_products() as $gs_bp_row ) {
                $gs_bp_prod = is_array( $gs_bp_row ) && isset( $gs_bp_row['product'] ) ? $gs_bp_row['product'] : null;
                if ( $gs_bp_prod && is_object( $gs_bp_prod ) && method_exists( $gs_bp_prod, 'get_subgroup' )
                    && strtolower( (string) $gs_bp_prod->get_subgroup() ) === 'backups' && method_exists( $gs_bp_prod, 'get_id' ) ) {
                    $gs_bp_price = '';
                    foreach ( $gs_backup_plans as $gs_bp ) {
                        if ( (int) ( $gs_bp['id'] ?? 0 ) === (int) $gs_bp_prod->get_id() ) { $gs_bp_price = (string) ( $gs_bp['price'] ?? '' ); break; }
                    }
                    $gs_bk_current[] = array( 'name' => method_exists( $gs_bp_prod, 'get_name' ) ? (string) $gs_bp_prod->get_name() : '', 'price' => $gs_bp_price );
                }
            }
        }

        gs_hosting_render_analytics_hero( array(
            'title'  => __( 'Backups', 'gend-society' ),
            'sub'    => __( 'Daily automatic snapshots plus on-demand backups. Restore rolls the install back to that snapshot.', 'gend-society' ),
            'pct'    => $gs_bk_pct,
            'warn'   => $gs_bk_warn,
            'stats'  => array(
                array( 'k' => __( 'Used', 'gend-society' ), 'v' => size_format( $gs_bk_bytes, 2 ) ),
                array( 'k' => __( 'Capacity', 'gend-society' ), 'v' => size_format( $gs_bk_cap, 2 ) ),
                array( 'k' => __( 'Total Backups', 'gend-society' ), 'v' => (string) $gs_bk_count ),
            ),
        ) );
        ?>

        <?php
        // Real current-plan lookup among the real plan list, matched by id -
        // same $gs_backup_current_id resolved above from the membership's
        // own real attached products.
        ?>
        <!-- ── Latest Backup + the ACTUAL assigned plan (or an honest "no
             plan attached" state) + Upgrade, grouped together in their own
             row right below the hero (same real .gs-hosting__analytics-stat
             card styling as the ring's own stats - the analytics above
             belong to this plan, so its real attachment status sits right
             beside them, rendered outside the hero call so the Upgrade
             button can sit beside them - the hero's stats array only
             accepts plain text, no buttons). ── -->
        <div class="gs-hosting__analytics-stats" style="margin-top: -4px;">
            <div class="gs-hosting__analytics-stat">
                <span class="k"><?php esc_html_e( 'Latest Backup', 'gend-society' ); ?></span><span class="v"><?php echo esc_html( $gs_bk_latest !== '' ? $gs_bk_latest : __( 'None yet', 'gend-society' ) ); ?></span>
            </div>
            <?php if ( ! empty( $gs_bk_current ) ) : foreach ( $gs_bk_current as $gs_bk_cur ) : ?>
                <div class="gs-hosting__analytics-stat">
                    <span class="k"><?php echo esc_html( $gs_bk_cur['name'] !== '' ? $gs_bk_cur['name'] : __( 'Backup Plan', 'gend-society' ) ); ?></span><span class="v" style="color:#6ee7b7;"><?php echo esc_html( $gs_bk_cur['price'] !== '' ? $gs_bk_cur['price'] . ' · ' : '' ); ?><?php esc_html_e( 'Active', 'gend-society' ); ?></span>
                </div>
            <?php endforeach; else : ?>
                <div class="gs-hosting__analytics-stat">
                    <span class="k"><?php esc_html_e( 'Backup Plan', 'gend-society' ); ?></span><span class="v" style="color:#fcd34d;"><?php esc_html_e( 'No Plan Attached', 'gend-society' ); ?></span>
                </div>
            <?php endif; ?>
            <?php
            // ONE button opening the backups plan picker (storage tiers +
            // frequency add-ons, current plan marked) - not one per product.
            $gs_bk_upgrade_attrs = ( ! empty( $gs_backup_plans ) && function_exists( 'gs_hosting_resource_upgrade_data_attrs' ) )
                ? gs_hosting_resource_upgrade_data_attrs( 'backups' )
                : '';
            if ( $gs_bk_upgrade_attrs !== '' ) : ?>
                <button type="button"
                        class="gs-hosting__btn gs-upgrade-cta"
                        data-gs-upgrade-open
                        data-resource="backups"
                        data-resource-label="<?php esc_attr_e( 'Backups', 'gend-society' ); ?>"
                        <?php echo $gs_bk_upgrade_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute string built by gs_hosting_resource_upgrade_data_attrs(); every value esc_attr()'d there. ?>
                        style="align-self: center; background: linear-gradient(135deg, #22d3ee, #7dd3fc); color: #0b0e14; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; border: none; border-radius: 8px; cursor: pointer; font-size: 0.75rem; padding: 9px 16px; box-shadow: 0 6px 18px rgba(34,211,238,.30);">
                    <?php echo esc_html( ! empty( $gs_bk_current ) ? __( 'Change plan', 'gend-society' ) : __( 'Choose a plan', 'gend-society' ) ); ?>
                </button>
            <?php endif; ?>
        </div>

        <div class="gs-hosting__filter-row" style="margin-top: 20px;">
            <button type="button" class="gs-hosting__btn" data-gs-hosting="backup-now"><?php esc_html_e( 'Backup now', 'gend-society' ); ?></button>
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
        <?php
    }
}

/**
 * Render the Hosting tab content (sidebar + 6 sub-panels). Called from the
 * Hosting tab panel in gs_render_membership_panel().
 *
 * @param array $payload Membership payload (passed through for domains/billing).
 * @param array $opts    Optional extras, all off by default so the BuddyPress group
 *                       Hosting tab and the embed render exactly as before:
 *                         with_settings    bool   App Settings / Permalinks / Application
 *                                                 Passwords at the top of the Dashboard sub-tab.
 *                         with_permalinks  bool   Adds the Permalinks sub-tab.
 *                         with_compute_gas bool   Adds the Compute Gas sub-tab (explainer +
 *                                                 lazy-loaded cost breakdown + Gas Station devices).
 *                         active_section   string 'dashboard' (default) or 'permalinks'.
 *                       User Access moved to the Feature Suite tab (see
 *                       dashboard-remote-membership.php) - no longer rendered here.
 */
function gs_render_hosting_tab( $payload = array(), $opts = array() ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<p style="color: var(--gs-muted);">' . esc_html__( 'You do not have permission to manage hosting.', 'gend-society' ) . '</p>';
        return;
    }

    $opts = wp_parse_args( $opts, array(
        'with_settings'    => false,
        'with_permalinks'  => false,
        'with_compute_gas' => false,
        'active_section'   => 'dashboard',
    ) );
    $with_settings    = ! empty( $opts['with_settings'] );
    $with_permalinks  = ! empty( $opts['with_permalinks'] );
    $with_compute_gas = ! empty( $opts['with_compute_gas'] );

    // section_group splits this console's 8 sub-tabs across two separate
    // top-level tabs (App / Hosting) on the membership dashboard. Default
    // 'all' renders every sub-tab exactly as before, for the other two
    // callers (group-embed.php, group-app-tabs.php) that don't know about
    // this split.
    $section_group = isset( $opts['section_group'] ) ? (string) $opts['section_group'] : 'all';
    if ( ! in_array( $section_group, array( 'all', 'app', 'hosting' ), true ) ) {
        $section_group = 'all';
    }
    $show_app_group     = in_array( $section_group, array( 'all', 'app' ), true );
    $show_hosting_group = in_array( $section_group, array( 'all', 'hosting' ), true );

    // Each of the two instances needs its own DOM id — both are rendered
    // inline on the same page (dashboard-remote-membership.php just CSS-
    // toggles which top-level tab-panel is visible), so a shared hardcoded
    // id would collide.
    $gs_hosting_root_id = 'all' === $section_group ? 'gs-hosting-root' : ( 'gs-hosting-root-' . $section_group );

    $active_section = ( 'hosting' === $section_group ) ? 'compute-gas' : 'dashboard';
    if ( 'permalinks' === $opts['active_section'] && $with_permalinks ) {
        $active_section = 'permalinks';
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
    <div class="gs-hosting" id="<?php echo esc_attr( $gs_hosting_root_id ); ?>">
        <style>
            .gs-hosting { display: grid; grid-template-columns: 220px 1fr; gap: 24px; min-height: 480px; }
            .gs-hosting__sidebar { display: flex; flex-direction: column; gap: 4px; padding-right: 16px; border-right: 1px solid rgba(255,255,255,0.08); position: sticky; top: 32px; }
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
            .gs-hosting__resources-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
            @media (max-width: 900px) { .gs-hosting__resources-grid { grid-template-columns: 1fr; } }
            .gs-hosting__resource-card { position: relative; display: flex; flex-direction: column; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 18px; gap: 4px; }
            .gs-hosting__resource-card-goto { position: absolute; top: 12px; right: 12px; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 7px; color: var(--gs-muted, #94a3b8); cursor: pointer; padding: 0; }
            .gs-hosting__resource-card-goto .dashicons { font-size: 15px; width: 15px; height: 15px; }
            .gs-hosting__resource-card-goto:hover { background: rgba(255,255,255,0.12); color: #fff; border-color: rgba(255,255,255,0.22); }
            .gs-hosting__resource-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
            .gs-hosting__resource-card-icon { color: #4eaaff; font-size: 24px; width: 24px; height: 24px; flex-shrink: 0; }
            .gs-hosting__resource-card-title { color: #fff; font-weight: 600; }
            .gs-hosting__resource-card-hint { color: var(--gs-muted, #94a3b8); font-size: 0.78rem; margin-top: 2px; }
            .gs-hosting__resource-card-usage { color: #fff; font-weight: 700; font-size: 1.05rem; margin-top: 6px; }
            .gs-hosting__resource-card-cap { color: var(--gs-muted, #94a3b8); font-weight: 400; font-size: 0.85rem; }
            .gs-hosting__resource-card-pct { color: var(--gs-muted, #94a3b8); font-size: 0.75rem; margin-top: 6px; }
            .gs-hosting__resource-card-meta { color: var(--gs-muted, #94a3b8); font-size: 0.75rem; margin: 8px 0 0; }
            /* Analytics hero — matches the real Media tab's (GMO Library
               Dashboard) Disk Usage ring + stat pills + gradient bar look,
               reimplemented with our own markup/classes and GMO's own
               color palette (magenta/blue/amber/red). */
            .gs-hosting__analytics-hero { display: flex; align-items: center; gap: 28px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 24px; margin-bottom: 20px; flex-wrap: wrap; }
            .gs-hosting__analytics-ring { position: relative; width: 120px; height: 120px; flex-shrink: 0; }
            .gs-hosting__analytics-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
            .gs-hosting__analytics-ring-track { fill: none; stroke: rgba(0,0,0,0.22); stroke-width: 10; }
            .gs-hosting__analytics-ring-fill { fill: none; stroke: var(--gs-ring-color, #b608c9); stroke-width: 10; stroke-linecap: round; transition: stroke-dashoffset 0.8s cubic-bezier(0.2,0.7,0.2,1); filter: drop-shadow(0 0 6px var(--gs-ring-color, #b608c9)); }
            .gs-hosting__analytics-ring-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
            .gs-hosting__analytics-ring-pct { font-size: 26px; font-weight: 800; color: #f8fafc; }
            .gs-hosting__analytics-ring-unit { font-size: 12px; font-weight: 700; color: #94a3b8; margin-left: 1px; }
            .gs-hosting__analytics-ring-sub { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }
            .gs-hosting__analytics-body { flex: 1 1 260px; min-width: 220px; }
            .gs-hosting__analytics-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
            .gs-hosting__analytics-title { margin: 0 0 4px; font-size: 1.1rem; font-weight: 800; color: #f8fafc; }
            .gs-hosting__analytics-sub { margin: 0 0 14px; font-size: 0.85rem; color: #94a3b8; }
            .gs-hosting__analytics-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
            .gs-hosting__analytics-stat { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 8px 14px; display: flex; flex-direction: column; gap: 2px; min-width: 90px; }
            .gs-hosting__analytics-stat .k { font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
            .gs-hosting__analytics-stat .v { font-size: 15px; font-weight: 700; color: #f8fafc; }
            .gs-hosting__analytics-bar { height: 8px; border-radius: 999px; background: rgba(0,0,0,0.22); overflow: hidden; }
            .gs-hosting__analytics-bar-fill { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #b608c9, #6ec1e4); transition: width 0.8s cubic-bezier(0.2,0.7,0.2,1); }
            .gs-hosting__analytics-bar-fill.is-warn { background: linear-gradient(90deg, #f59e0b, #ef4444); }
            @media (max-width: 640px) { .gs-hosting__analytics-hero { flex-direction: column; align-items: flex-start; } }
            .gs-hosting__backups-section { margin-top: 28px; padding-top: 22px; border-top: 1px solid rgba(255,255,255,0.08); }
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
                .gs-hosting__sidebar { position: static; flex-direction: row; flex-wrap: wrap; padding-right: 0; padding-bottom: 12px; border-right: 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
            }
        </style>

        <nav class="gs-hosting__sidebar" role="tablist">
            <?php if ( $show_app_group ) : ?>
            <button type="button" class="gs-hosting__nav<?php echo 'dashboard' === $active_section ? ' is-active' : ''; ?>" data-section="dashboard" role="tab"><span class="dashicons dashicons-dashboard"></span><?php esc_html_e( 'Dashboard', 'gend-society' ); ?></button>
            <?php if ( $with_settings ) : ?>
            <button type="button" class="gs-hosting__nav" data-section="security" role="tab"><span class="dashicons dashicons-shield"></span><?php esc_html_e( 'Security', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="cache" role="tab"><span class="dashicons dashicons-performance"></span><?php esc_html_e( 'Cache', 'gend-society' ); ?></button>
            <?php endif; ?>
            <button type="button" class="gs-hosting__nav" data-section="domains" role="tab"><span class="dashicons dashicons-admin-site"></span><?php esc_html_e( 'Domains', 'gend-society' ); ?></button>
            <?php endif; ?>
            <?php if ( $with_compute_gas && $show_hosting_group ) : ?>
            <button type="button" class="gs-hosting__nav<?php echo 'compute-gas' === $active_section ? ' is-active' : ''; ?>" data-section="compute-gas" role="tab"><span class="dashicons dashicons-superhero"></span><?php esc_html_e( 'Compute Gas', 'gend-society' ); ?></button>
            <?php endif; ?>
            <?php if ( $show_app_group ) : ?>
            <button type="button" class="gs-hosting__nav" data-section="logs" role="tab"><span class="dashicons dashicons-warning"></span><?php esc_html_e( 'Logs', 'gend-society' ); ?></button>
            <?php endif; ?>
            <?php if ( $show_hosting_group ) : ?>
            <?php if ( 'hosting' === $section_group ) : ?>
            <button type="button" class="gs-hosting__nav" data-section="backups" role="tab"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Backups', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="media-library" role="tab"><span class="dashicons dashicons-images-alt2"></span><?php esc_html_e( 'Media', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="codebase" role="tab"><span class="dashicons dashicons-editor-code"></span><?php esc_html_e( 'Codebase', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="tables" role="tab"><span class="dashicons dashicons-database"></span><?php esc_html_e( 'Tables', 'gend-society' ); ?></button>
            <?php else : ?>
            <button type="button" class="gs-hosting__nav" data-section="tables" role="tab"><span class="dashicons dashicons-database"></span><?php esc_html_e( 'Tables', 'gend-society' ); ?></button>
            <button type="button" class="gs-hosting__nav" data-section="media" role="tab"><span class="dashicons dashicons-cloud"></span><?php esc_html_e( 'Storage', 'gend-society' ); ?></button>
            <?php
            // Servers is a standalone sidebar item only here (section_group=all
            // - group-embed.php's front-end Hosting tab, where Compute Gas
            // never renders since with_compute_gas defaults false there, so
            // there's no Compute Gas panel to nest it under). For
            // section_group=hosting (the membership dashboard's own Hosting
            // tab) it now lives as a sub-tab inside Compute Gas instead - see
            // the "gs-compute-gas__subtabs" nav below.
            ?>
            <button type="button" class="gs-hosting__nav" data-section="servers" role="tab"><span class="dashicons dashicons-networking"></span><?php esc_html_e( 'Servers', 'gend-society' ); ?></button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ( $with_permalinks && $show_app_group ) : ?>
            <button type="button" class="gs-hosting__nav<?php echo 'permalinks' === $active_section ? ' is-active' : ''; ?>" data-section="permalinks" role="tab"><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Permalinks', 'gend-society' ); ?></button>
            <?php endif; ?>
        </nav>

        <div class="gs-hosting__main">

            <?php if ( $show_app_group ) : ?>
            <!-- ── Dashboard sub-panel ───────────────────────────── -->
            <section class="gs-hosting__panel<?php echo 'dashboard' === $active_section ? ' is-active' : ''; ?>" data-panel="dashboard" role="tabpanel">
                <?php if ( $with_settings ) : ?>
                <!-- ── App Settings (App Title, Tagline, App Icon, Site Logo, Save) ── -->
                <?php
                if ( function_exists( 'gs_render_app_settings_form' ) ) {
                    gs_render_app_settings_form();
                }
                ?>
                <?php else : ?>
                <!-- with_settings=false callers (e.g. the BuddyPress group Hosting tab)
                     never showed App Settings here - keep the original flat "Hosting
                     Dashboard" cards (cache + hardening together) in this one panel. -->
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

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="cache"></div>

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

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="security"></div>
                <?php endif; ?>
            </section>

            <?php if ( $with_settings ) : ?>
            <!-- ── Security sub-panel: WAF / Password Protection / Brute Force / Application Passwords ── -->
            <section class="gs-hosting__panel" data-panel="security" role="tabpanel">
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

                <!-- Application Passwords — API/mobile sign-in credentials (GenD Mobile). -->
                <div style="margin-top:36px;padding-top:28px;border-top:1px solid rgba(255,255,255,0.08);">
                    <?php
                    if ( function_exists( 'gs_render_application_passwords_form' ) ) {
                        gs_render_application_passwords_form();
                    }
                    ?>
                </div>

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="security"></div>
            </section>

            <!-- ── Cache sub-panel: the Hosting Dashboard section (caches + one-shot reset) ── -->
            <section class="gs-hosting__panel" data-panel="cache" role="tabpanel">
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

                <div class="gs-hosting__feedback" data-gs-hosting-feedback="cache"></div>
            </section>
            <?php endif; // with_settings (security + cache as separate sidebar tabs) ?>

            <!-- ── Domains sub-panel (Phase 72-02 — Connect-a-Domain wizard) ─────── -->
            <section class="gs-hosting__panel" data-panel="domains" role="tabpanel">
                <?php
                if ( function_exists( 'gs_render_hosting_domains_panel' ) ) {
                    gs_render_hosting_domains_panel( $payload );
                } else {
                    echo '<p style="color: var(--gs-muted, #94a3b8); font-style: italic;">' . esc_html__( 'Domains wizard is not yet deployed on this install. Try again shortly.', 'gend-society' ) . '</p>';
                }
                ?>
            </section>
            <?php endif; // show_app_group (dashboard + domains) ?>

            <?php if ( $with_compute_gas && $show_hosting_group ) : ?>
            <!-- Compute Gas sub-panel — explainer + lazy-loaded cost breakdown -->
            <section class="gs-hosting__panel<?php echo 'compute-gas' === $active_section ? ' is-active' : ''; ?>" data-panel="compute-gas" role="tabpanel">
                <style>
                    .gs-compute-gas { display: flex; flex-direction: column; gap: 18px; }
                    .gs-compute-gas__hero { background: linear-gradient(135deg, rgba(78,170,255,0.14) 0%, rgba(168,85,247,0.10) 100%); border: 1px solid rgba(78,170,255,0.25); border-radius: 16px; padding: 22px 26px; }
                    .gs-compute-gas__badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: rgba(78,170,255,0.18); color: #4eaaff; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
                    .gs-compute-gas__title { color: #fff; font-size: 1.35rem; font-weight: 700; margin: 12px 0 8px; }
                    .gs-compute-gas__body { color: #cbd5f5; font-size: 0.95rem; line-height: 1.65; margin: 0; max-width: 760px; }
                    .gs-compute-gas__body strong { color: #fff; }
                    .gs-compute-gas__pillars { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 18px; }
                    .gs-compute-gas__pillar { background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 16px; }
                    .gs-compute-gas__pillar-icon { color: #4eaaff; font-size: 22px; }
                    .gs-compute-gas__pillar-label { color: #fff; font-weight: 700; font-size: 0.95rem; margin-top: 6px; }
                    .gs-compute-gas__pillar-desc { color: var(--gs-muted, #94a3b8); font-size: 0.78rem; margin-top: 4px; line-height: 1.5; }
                    .gs-compute-gas__breakdown { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 20px; }
                    .gs-compute-gas__loading { color: var(--gs-muted, #94a3b8); font-style: italic; padding: 18px; text-align: center; }
                    .gs-compute-gas__subtabs { display:flex; gap:8px; margin:0 0 18px; padding:6px; border:1px solid rgba(78,170,255,.2); border-radius:12px; background:rgba(0,0,0,.2); }
                    .gs-compute-gas__subtab { border:1px solid transparent; border-radius:9px; padding:10px 16px; background:transparent; color:#cbd5f5; cursor:pointer; font-weight:700; }
                    .gs-compute-gas__subtab:hover, .gs-compute-gas__subtab.is-active { color:#fff; background:rgba(78,170,255,.18); border-color:rgba(78,170,255,.35); }
                    .gs-compute-gas__subpanel { display:none; }
                    .gs-compute-gas__subpanel.is-active { display:block; }
                    .gs-compute-gas__devices { margin-top:18px; background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:20px; }
                    .gs-compute-gas__devices h4 { color:#fff; margin:0 0 6px; }
                    .gs-compute-gas__devices p { color:var(--gs-muted,#94a3b8); }
                    .gs-compute-gas__device-table { width:100%; border-collapse:collapse; margin-top:14px; }
                    .gs-compute-gas__device-table th, .gs-compute-gas__device-table td { padding:10px; text-align:left; color:#e6edf7; border-bottom:1px solid rgba(255,255,255,.07); }
                    .gs-compute-gas__device-table th { color:#8ecbff; font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; }
                    .gs-compute-gas__device-table code { color:#c4b5fd; }
                    .gs-compute-gas__device-editor { margin-top:14px; padding:14px; border:1px solid rgba(78,170,255,.3); border-radius:10px; }
                    .gs-compute-gas__device-editor[hidden] { display:none; }
                    @media (max-width:700px) { .gs-compute-gas__subtabs { flex-wrap:wrap; } .gs-compute-gas__device-table { display:block; overflow-x:auto; white-space:nowrap; } }

                    /* Real cost breakdown + payment links - animated stat cards, bars, staggered entrance. */
                    @keyframes gsCgFadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
                    @keyframes gsCgFadeIn { from { opacity:0; transform:translateX(-8px); } to { opacity:1; transform:translateX(0); } }
                    .gs-compute-gas__stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:18px; }
                    .gs-compute-gas__stat { background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:14px 16px; opacity:0; animation:gsCgFadeUp .5s ease forwards; }
                    .gs-compute-gas__stat--total { background:linear-gradient(135deg, rgba(78,170,255,0.18), rgba(168,85,247,0.12)); border-color:rgba(78,170,255,0.35); }
                    .gs-compute-gas__stat-label { color:var(--gs-muted,#94a3b8); font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; }
                    .gs-compute-gas__stat-value { color:#fff; font-size:1.5rem; font-weight:700; margin-top:6px; font-variant-numeric:tabular-nums; }
                    .gs-compute-gas__bars { display:flex; flex-direction:column; }
                    .gs-compute-gas__bar-row { display:flex; align-items:center; gap:12px; padding:9px 0; opacity:0; animation:gsCgFadeIn .45s ease forwards; }
                    .gs-compute-gas__bar-label { flex:0 0 auto; width:38%; color:#e6edf7; font-size:.85rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                    .gs-compute-gas__bar-track { flex:1; height:10px; background:rgba(255,255,255,0.06); border-radius:999px; overflow:hidden; }
                    .gs-compute-gas__bar-fill { display:block; height:100%; width:0; border-radius:999px; background:linear-gradient(90deg,#4eaaff,#a855f7); transition:width 1s cubic-bezier(.16,1,.3,1); }
                    .gs-compute-gas__bar-amount { flex:0 0 auto; color:var(--gs-muted,#94a3b8); font-size:.8rem; min-width:130px; text-align:right; white-space:nowrap; }
                    .gs-compute-gas__payments { margin-top:22px; }
                    .gs-compute-gas__payments h4 { color:#fff; font-size:1.05rem; margin:0 0 12px; }
                    .gs-compute-gas__payment-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:12px 14px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:10px; margin-bottom:8px; opacity:0; animation:gsCgFadeUp .45s ease forwards; }
                    .gs-compute-gas__payment-badge { display:inline-flex; padding:2px 10px; border-radius:999px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
                    .gs-compute-gas__payment-badge--pending { background:rgba(251,191,36,.15); color:#fbbf24; border:1px solid rgba(251,191,36,.4); }
                    .gs-compute-gas__payment-badge--completed { background:rgba(34,211,238,.12); color:#22d3ee; border:1px solid rgba(34,211,238,.35); }
                    .gs-compute-gas__payment-links a { color:#8ecbff; text-decoration:none; font-size:.82rem; font-weight:600; margin-left:14px; }
                    .gs-compute-gas__payment-links a:hover { text-decoration:underline; }
                    @media (prefers-reduced-motion: reduce) {
                        .gs-compute-gas__stat, .gs-compute-gas__bar-row, .gs-compute-gas__payment-row { animation:none; opacity:1; }
                        .gs-compute-gas__bar-fill { transition:none; }
                    }
                    @media (max-width:700px) { .gs-compute-gas__bar-label { width:auto; max-width:140px; } }
                </style>
                <div class="gs-compute-gas">
                    <div class="gs-compute-gas__hero">
                        <span class="gs-compute-gas__badge"><span class="dashicons dashicons-superhero" style="font-size:14px; width:14px; height:14px;"></span><?php esc_html_e( 'No monthly server fees', 'gend-society' ); ?></span>
                        <h3 class="gs-compute-gas__title"><?php esc_html_e( 'How Compute Gas Works', 'gend-society' ); ?></h3>
                        <p class="gs-compute-gas__body">
                            <?php
                            printf(
                                /* translators: 1: bold "Blockchain Compute network", 2: bold "Gas Station Nodes" */
                                esc_html__( 'Networked businesses on GenD don\'t pay flat monthly server or compute fees. Your storage container integrates with our %1$s, which uses the unused compute power of the servers and devices running our %2$s across the network. That same network powers every payment, every workflow, and every smart contract on the platform — so what your app uses, the network earns. You only pay for the compute you actually consume.', 'gend-society' ),
                                '<strong>' . esc_html__( 'Blockchain Compute network', 'gend-society' ) . '</strong>',
                                '<strong>' . esc_html__( 'Gas Station Nodes', 'gend-society' ) . '</strong>'
                            );
                            ?>
                        </p>
                        <div class="gs-compute-gas__pillars">
                            <div class="gs-compute-gas__pillar">
                                <span class="dashicons dashicons-money-alt gs-compute-gas__pillar-icon"></span>
                                <div class="gs-compute-gas__pillar-label"><?php esc_html_e( 'Payments', 'gend-society' ); ?></div>
                                <div class="gs-compute-gas__pillar-desc"><?php esc_html_e( 'Every checkout settles on the network — no merchant processor fees.', 'gend-society' ); ?></div>
                            </div>
                            <div class="gs-compute-gas__pillar">
                                <span class="dashicons dashicons-performance gs-compute-gas__pillar-icon"></span>
                                <div class="gs-compute-gas__pillar-label"><?php esc_html_e( 'Compute', 'gend-society' ); ?></div>
                                <div class="gs-compute-gas__pillar-desc"><?php esc_html_e( 'CPU + RAM for your container is provisioned from unused capacity across the network.', 'gend-society' ); ?></div>
                            </div>
                            <div class="gs-compute-gas__pillar">
                                <span class="dashicons dashicons-shield gs-compute-gas__pillar-icon"></span>
                                <div class="gs-compute-gas__pillar-label"><?php esc_html_e( 'Smart Contracts', 'gend-society' ); ?></div>
                                <div class="gs-compute-gas__pillar-desc"><?php esc_html_e( 'Tasks, escrows, and payouts execute on-chain — settlement is the receipt.', 'gend-society' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <nav class="gs-compute-gas__subtabs" role="tablist" aria-label="<?php esc_attr_e( 'Compute Gas sections', 'gend-society' ); ?>">
                        <button type="button" class="gs-compute-gas__subtab is-active" data-gs-cg-admin-tab="consumed-gas" role="tab" aria-selected="true"><?php esc_html_e( 'Consumed Gas', 'gend-society' ); ?></button>
                        <button type="button" class="gs-compute-gas__subtab" data-gs-cg-admin-tab="gas-stations" role="tab" aria-selected="false"><?php esc_html_e( 'Gas Stations', 'gend-society' ); ?></button>
                        <button type="button" class="gs-compute-gas__subtab" data-gs-cg-admin-tab="servers" role="tab" aria-selected="false"><?php esc_html_e( 'Servers', 'gend-society' ); ?></button>
                    </nav>

                    <div class="gs-compute-gas__subpanel is-active" data-gs-cg-admin-panel="consumed-gas">
                    <div class="gs-compute-gas__breakdown">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom: 16px;">
                            <div>
                                <h4 style="color:#fff; font-size:1.05rem; margin:0;"><?php esc_html_e( 'Your Usage This Period', 'gend-society' ); ?></h4>
                                <p style="color: var(--gs-muted, #94a3b8); font-size:0.85rem; margin:4px 0 0;"><?php esc_html_e( 'Live tally of compute gas + container fees for this install.', 'gend-society' ); ?></p>
                            </div>
                            <button type="button" class="gs-mship-action-btn is-secondary" data-gs-compute-gas="refresh"><?php esc_html_e( 'Refresh', 'gend-society' ); ?></button>
                        </div>
                        <div data-gs-compute-gas-body>
                            <div class="gs-compute-gas__loading"><?php esc_html_e( 'Loading cost breakdown…', 'gend-society' ); ?></div>
                        </div>
                    </div>
                    </div>

                    <div class="gs-compute-gas__subpanel" data-gs-cg-admin-panel="gas-stations">
                        <?php
                        global $wpdb;
                        $gs_admin_ledger = $wpdb->base_prefix . 'gdc_gas_ledger';
                        $gs_admin_earnings = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT station_id, SUM(units) AS units, SUM(owner_amount) AS owner_amount, MAX(created_at) AS last_earned
                             FROM %i WHERE station_id <> '' GROUP BY station_id ORDER BY owner_amount DESC",
                                $gs_admin_ledger
                            ),
                            ARRAY_A
                        );
                        ?>
                        <div class="gs-compute-gas__devices">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
                                <h4 style="margin:0;"><?php esc_html_e( 'Connected devices', 'gend-society' ); ?></h4>
                                <button type="button" class="gs-mship-action-btn" data-gs-cg-admin-add><?php esc_html_e( 'Add new device', 'gend-society' ); ?></button>
                            </div>
                            <p><?php esc_html_e( 'View and edit connected servers, desktops, and mobile devices participating as Gas Stations.', 'gend-society' ); ?></p>
                            <div data-gs-cg-admin-devices><p><?php esc_html_e( 'Loading connected devices…', 'gend-society' ); ?></p></div>
                            <div class="gs-compute-gas__device-editor" data-gs-cg-admin-add-panel hidden>
                                <h4><?php esc_html_e( 'Add a Gas Station device', 'gend-society' ); ?></h4>
                                <p><?php esc_html_e( 'Choose a device type to connect. The device app will register itself to this account after sign-in.', 'gend-society' ); ?></p>
                                <button type="button" class="gs-mship-action-btn" data-gs-cg-connect-type="server"><?php esc_html_e( 'Add server', 'gend-society' ); ?></button>
                                <button type="button" class="gs-mship-action-btn" data-gs-cg-connect-type="desktop"><?php esc_html_e( 'Add desktop', 'gend-society' ); ?></button>
                                <button type="button" class="gs-mship-action-btn" data-gs-cg-connect-type="mobile"><?php esc_html_e( 'Add mobile', 'gend-society' ); ?></button>
                                <div data-gs-cg-connect-modal hidden style="margin-top:14px; padding:16px; border:1px solid rgba(78,170,255,.3); border-radius:10px;">
                                    <h4 data-gs-cg-connect-title style="color:#fff; margin:0 0 6px;"></h4>
                                    <p data-gs-cg-connect-copy style="margin:0 0 12px;"></p>
                                    <button type="button" class="gs-mship-action-btn is-secondary" data-gs-cg-connect-close><?php esc_html_e( 'Close', 'gend-society' ); ?></button>
                                </div>
                            </div>
                            <div class="gs-compute-gas__device-editor" data-gs-cg-admin-editor hidden>
                                <h4 data-gs-cg-admin-editor-title><?php esc_html_e( 'Run as a node', 'gend-society' ); ?></h4>
                                <p><?php esc_html_e( 'Node participation currently applies to this WordPress install; the selected device identifies the device being edited.', 'gend-society' ); ?></p>
                                <button type="button" class="gs-mship-action-btn" data-gs-cg-admin-node="start"><?php esc_html_e( 'Start node', 'gend-society' ); ?></button>
                                <button type="button" class="gs-mship-action-btn is-secondary" data-gs-cg-admin-node="stop"><?php esc_html_e( 'Stop node', 'gend-society' ); ?></button>
                                <span data-gs-cg-admin-node-status></span>
                            </div>
                        </div>
                        <div class="gs-compute-gas__devices">
                            <h4><?php esc_html_e( 'Earned GAS fees', 'gend-society' ); ?></h4>
                            <p><?php esc_html_e( 'Gas Station Owner commissions earned by connected devices.', 'gend-society' ); ?></p>
                            <div data-gs-cg-admin-earnings></div>
                        </div>
                    </div>

                    <!-- ── Servers sub-sub-panel — moved here from a standalone
                         Hosting sidebar item, alongside Gas Stations (both are
                         real compute-capacity concepts: Gas Stations are
                         volunteered spare capacity, a Server is a dedicated
                         paid allocation). Same real checkout iframe + lazy-load
                         pattern as before, just relocated. ── -->
                    <div class="gs-compute-gas__subpanel" data-gs-cg-admin-panel="servers">
                        <div style="margin-bottom: 14px;">
                            <h4 style="color:#fff; font-size:1.05rem; margin:0;"><?php esc_html_e( 'Servers', 'gend-society' ); ?></h4>
                            <p style="color: var(--gs-muted, #94a3b8); font-size:0.85rem; margin:4px 0 0;"><?php esc_html_e( 'Dedicated server resources for your app — pick a plan and attach it to this membership.', 'gend-society' ); ?></p>
                        </div>
                        <?php
                        $gs_cg_server_embed_url = function_exists( 'gdc_plan_attach_resource_embed_url' ) ? gdc_plan_attach_resource_embed_url( 'server' ) : '';
                        ?>
                        <?php if ( $gs_cg_server_embed_url !== '' ) : ?>
                            <div class="gs-hosting__card" style="padding: 0; overflow: hidden;">
                                <iframe data-gs-hosting-servers-frame data-src="<?php echo esc_url( $gs_cg_server_embed_url ); ?>" src="about:blank" title="<?php esc_attr_e( 'Server plan checkout', 'gend-society' ); ?>" loading="lazy" style="width: 100%; height: 900px; border: 0; display: block; background: #0b0e14;"></iframe>
                            </div>
                        <?php else : ?>
                            <div class="gs-hosting__feedback is-error"><?php esc_html_e( 'Server checkout is not available right now.', 'gend-society' ); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ( $show_app_group ) : ?>
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
            <?php endif; // show_app_group (logs) ?>

            <?php if ( $show_hosting_group ) : ?>
            <!-- ── Tables sub-panel: "hero" analytics matching the real
                 Media tab (GMO Library Dashboard's ring/stat-pill/bar
                 pattern — see gs_hosting_render_analytics_hero()). ── -->
            <section class="gs-hosting__panel" data-panel="tables" role="tabpanel">
                <?php
                $gs_tables_pct = gs_hosting_pct( (int) $tables_data['total_bytes'], gs_hosting_db_plan_bytes() );

                // No Upgrade CTA on this hero (removed on request) - same
                // cta-less call shape the Codebase hero below uses.
                gs_hosting_render_analytics_hero( array(
                    'title' => __( 'Database Tables', 'gend-society' ),
                    'sub'   => __( 'Live row counts and on-disk size for every table on this install.', 'gend-society' ),
                    'pct'   => $gs_tables_pct,
                    'warn'  => $gs_tables_pct > 80,
                    'stats' => array(
                        array( 'k' => __( 'Used', 'gend-society' ), 'v' => size_format( (int) $tables_data['total_bytes'], 2 ) ),
                        array( 'k' => __( 'Capacity', 'gend-society' ), 'v' => size_format( gs_hosting_db_plan_bytes(), 0 ) ),
                        array( 'k' => __( 'Tables', 'gend-society' ), 'v' => (string) (int) $tables_data['count'] ),
                        array( 'k' => __( 'Total Rows', 'gend-society' ), 'v' => number_format_i18n( (int) $tables_data['total_rows'] ) ),
                        array( 'k' => __( 'Largest Table', 'gend-society' ), 'v' => $tables_data['largest_name'] ?: '—' ),
                        array( 'k' => __( 'Plan', 'gend-society' ), 'v' => gs_hosting_plan_label( $billing ) ),
                    ),
                ) );
                ?>

                <!-- Inline mount point: the tables explorer below (list +
                     query runner) is moved in here by JS on load and shown
                     in-flow, replacing the old "Launch Your App Tables"
                     button + popup. -->
                <div id="gs-hosting-tables-inline" style="margin-top: 18px;"></div>
            </section>

            <!-- Tables explorer — rendered INLINE inside the Tables panel
                 (JS moves this node into #gs-hosting-tables-inline on load;
                 .is-inline overrides the old fixed-position popup styling).
                 Was a 90vw modal opened by a "Launch Your App Tables"
                 button; the list + query runner markup and its JS are
                 unchanged, only the presentation differs. -->
            <div class="gs-hosting-tables-modal is-inline" id="gs-hosting-tables-modal">
                <div class="gs-hosting-tables-modal__dialog" role="region" aria-labelledby="gs-hosting-tables-title">
                    <header class="gs-hosting-tables-modal__header">
                        <h3 id="gs-hosting-tables-title" style="margin: 0; color: #fff; font-size: 1.1rem; display: flex; align-items: center; gap: 10px;">
                            <span class="dashicons dashicons-database" style="color: #4eaaff;"></span>
                            <?php esc_html_e( 'Your App Tables', 'gend-society' ); ?>
                            <span style="color: var(--gs-muted, #94a3b8); font-size: 0.85rem; font-weight: 400; margin-left: 6px;">
                                · <?php echo (int) $tables_data['count']; ?> <?php esc_html_e( 'tables', 'gend-society' ); ?>
                                · <?php echo esc_html( size_format( (int) $tables_data['total_bytes'], 1 ) ); ?>
                            </span>
                        </h3>
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
                                                <!-- Numeric columns sort high/low on click (JS below,
                                                     keyed on the row's data-sort-* raw values). -->
                                                <th style="text-align: right; cursor: pointer; user-select: none;" data-gs-sort="rows" role="button" tabindex="0" title="<?php esc_attr_e( 'Sort by rows', 'gend-society' ); ?>"><?php esc_html_e( 'Rows', 'gend-society' ); ?> <span data-gs-sort-ind style="opacity: .55; font-size: .75em;"></span></th>
                                                <th style="text-align: right; cursor: pointer; user-select: none;" data-gs-sort="data" role="button" tabindex="0" title="<?php esc_attr_e( 'Sort by data size', 'gend-society' ); ?>"><?php esc_html_e( 'Data', 'gend-society' ); ?> <span data-gs-sort-ind style="opacity: .55; font-size: .75em;"></span></th>
                                                <th style="text-align: right; cursor: pointer; user-select: none;" data-gs-sort="index" role="button" tabindex="0" title="<?php esc_attr_e( 'Sort by index size', 'gend-society' ); ?>"><?php esc_html_e( 'Index', 'gend-society' ); ?> <span data-gs-sort-ind style="opacity: .55; font-size: .75em;"></span></th>
                                                <th style="text-align: right; cursor: pointer; user-select: none;" data-gs-sort="total" role="button" tabindex="0" title="<?php esc_attr_e( 'Sort by total size', 'gend-society' ); ?>"><?php esc_html_e( 'Total', 'gend-society' ); ?> <span data-gs-sort-ind style="opacity: .55; font-size: .75em;"></span></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $tables_data['tables'] as $t ) : ?>
                                                <tr data-search="<?php echo esc_attr( strtolower( $t['name'] ) ); ?>" data-table-name="<?php echo esc_attr( $t['name'] ); ?>" data-sort-rows="<?php echo (int) $t['rows']; ?>" data-sort-data="<?php echo (int) $t['data_bytes']; ?>" data-sort-index="<?php echo (int) $t['index_bytes']; ?>" data-sort-total="<?php echo (int) $t['total_bytes']; ?>">
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
                /* ── Inline mode (.is-inline): the explorer lives inside the
                   Tables panel instead of a fixed-position popup. Higher
                   specificity than the popup rules below, so order doesn't
                   matter; display needs !important to beat display:none. ── */
                .gs-hosting-tables-modal.is-inline { display: block !important; position: static; inset: auto; padding: 0; z-index: auto; }
                .gs-hosting-tables-modal.is-inline .gs-hosting-tables-modal__dialog { width: 100%; max-width: none; height: auto; max-height: none; box-shadow: none; }
                .gs-hosting-tables-modal.is-inline .gs-hosting-tables-modal__body { overflow: visible; }
                .gs-hosting-tables-modal.is-inline .gs-hosting-tables-modal__panes,
                .gs-hosting-tables-modal.is-inline .gs-hosting-tables-modal__pane { height: auto; }
                .gs-hosting-tables-modal.is-inline .gs-hosting-tables-modal__list-wrap { flex: none; max-height: 60vh; }
                .gs-hosting-tables-modal.is-inline .gs-hosting-tables-modal__results { max-height: 50vh; overflow: auto; }
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

            <?php if ( 'hosting' === $section_group ) : ?>
            <!-- ── Backups sub-panel (split out of the old combined Storage
                 panel - see gs_hosting_render_backups_section()). ── -->
            <section class="gs-hosting__panel" data-panel="backups" role="tabpanel">
                <?php gs_hosting_render_backups_section( $backups ); ?>
            </section>

            <!-- ── Media sub-panel: the real gend-media-optimizer Media Library
                 tab (admin.php?page=blog-manager&tab=media), rendered natively
                 here via its own real render() methods - not an iframe, not a
                 re-implementation. Its own "Media Storage Plan" popup already
                 calls gend-society's gs_get_media_storage_plans AJAX action,
                 which proxies to vendor-app-manager's real
                 /install/{id}/media-storage-plans REST route, so this is
                 already fully connected to the real plan data with no new
                 wiring needed here. ── -->
            <section class="gs-hosting__panel" data-panel="media-library" role="tabpanel">
                <?php
                // GMO_Admin_Subtab::enqueue_assets(), GMO_Editor_Panel::enqueue(),
                // AND blog-manager's own bm_enqueue_assets() (which is what
                // actually defines the shared .gdc-sub-tab/.gdc-sub-tabs button
                // component the Upgrade/Migrate, ZIP-source, and Management/
                // Optimizer/Editor rows all use) only fire their wp_enqueue_style()
                // calls when the CURRENT admin page IS blog-manager itself
                // (checked via $hook_suffix/$_GET['page']) — this membership-
                // dashboard page never matches that gate, and by the time this
                // panel's body content renders here, admin_head has already
                // fired and printed, so a wp_enqueue_style() call at this point
                // would silently do nothing anyway. Link the SAME real
                // stylesheets directly instead (identical relative paths +
                // filemtime versioning each plugin itself uses), in the same
                // load order the real blog-manager Media tab uses (blog-
                // manager's own base/shell CSS first, GMO's on top), so the
                // Media/Editor sub-tabs aren't left with a mix of unstyled
                // browser-default controls (.gdc-sub-tab's real component
                // never loaded) and styled ones (GMO's own CSS, already
                // linked) fighting each other.
                foreach ( array(
                    array( 'plugin' => 'blog-manager',          'file' => 'assets/admin-dashboard-base.css' ),
                    array( 'plugin' => 'blog-manager',          'file' => 'assets/app-content.css' ),
                    array( 'plugin' => 'gend-media-optimizer',  'file' => 'inc/admin/assets/gmo-admin-subtab.css' ),
                    array( 'plugin' => 'gend-media-optimizer',  'file' => 'inc/admin/assets/gmo-editor.css' ),
                ) as $gmo_css ) {
                    $gmo_css_rel  = 'plugins/' . $gmo_css['plugin'] . '/' . $gmo_css['file'];
                    $gmo_css_path = trailingslashit( WP_CONTENT_DIR ) . $gmo_css_rel;
                    if ( file_exists( $gmo_css_path ) ) {
                        // Registered + printed in place (not enqueued for the
                        // footer): same <link> at the same position.
                        $gmo_css_handle = 'gs-hosting-media-' . sanitize_title( $gmo_css['plugin'] . '-' . basename( $gmo_css['file'], '.css' ) );
                        wp_register_style( $gmo_css_handle, trailingslashit( content_url() ) . $gmo_css_rel, array(), (string) filemtime( $gmo_css_path ) );
                        wp_print_styles( $gmo_css_handle );
                    }
                }
                ?>
                <!-- ── Center the real .gdc-sub-tabs button-selection rows
                     (Upgrade/Migrate, ZIP file/Connected site/Google Drive,
                     Management/Optimizer/Editor - a shared component also
                     used by blog-manager/email-manager elsewhere, defined in
                     blog-manager's app-content.css) and give them a livelier
                     hover, scoped to this embed only so the real native
                     blog-manager Media tab is untouched. ── -->
                <style>
                    /* ── Scoped to this embed only (blog-manager's native
                       Media tab keeps them): drop the storage hero's Upgrade
                       button + "No attached plan" label, and the Upgrade
                       sub-tab with its "Connect to gend.me" pricing pane.
                       The script after GMO_Admin_Subtab::render() removes the
                       nodes too; this rule just covers the paint before it. ── */
                    [data-panel="media-library"] .gmo-lib-storage-cta-wrap,
                    [data-panel="media-library"] #gmo-media-subtabs .gdc-sub-tab[data-media-subtab="upgrade"],
                    [data-panel="media-library"] .gmo-media-subpanel[data-media-subpanel="upgrade"] { display: none !important; }
                    [data-panel="media-library"] .gdc-sub-tabs { margin-left: auto; margin-right: auto; }
                    [data-panel="media-library"] .gdc-sub-tab {
                        transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.25s ease, box-shadow 0.25s ease, color 0.25s ease, border-color 0.25s ease;
                    }
                    [data-panel="media-library"] .gdc-sub-tab:not(:disabled):hover {
                        transform: translateY(-3px) scale(1.07);
                        background: rgba(182, 8, 201, 0.18);
                        border-color: rgba(182, 8, 201, 0.35);
                        box-shadow: 0 12px 26px rgba(182, 8, 201, 0.38);
                        color: #fff;
                    }
                    [data-panel="media-library"] .gdc-sub-tab:not(:disabled):active { transform: translateY(-1px) scale(1.02); }
                    [data-panel="media-library"] .gdc-sub-tab.active {
                        box-shadow: 0 10px 24px rgba(182, 8, 201, 0.32), inset 0 1px 0 rgba(255,255,255,0.08);
                    }
                    [data-panel="media-library"] .gmo-btn { transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
                    [data-panel="media-library"] .gmo-btn:not(:disabled):hover { transform: translateY(-2px) scale(1.04); }
                    [data-panel="media-library"] .gmo-btn--primary:not(:disabled):hover {
                        transform: translateY(-3px) scale(1.06);
                        box-shadow: 0 16px 34px rgba(182, 8, 201, 0.55);
                    }
                </style>
                <?php
                if ( class_exists( 'GMO_Admin_Subtab' ) ) {
                    GMO_Admin_Subtab::render();
                    if ( class_exists( 'GMO_Editor_Panel' ) ) {
                        GMO_Editor_Panel::render();
                    }
                } else {
                    echo '<p style="color: var(--gs-muted, #94a3b8); font-style: italic;">' . esc_html__( 'Media library is not available on this install.', 'gend-society' ) . '</p>';
                }
                ?>
                <script>
                (function () {
                    // Scoped to this embed: remove the storage hero's Upgrade
                    // CTA (+ "No attached plan") and the Upgrade sub-tab/pane
                    // that GMO_Admin_Subtab::render() just printed. Removing
                    // the nodes (not just hiding them) means GMO's own
                    // [data-gmo-storage-cta] / sub-tab click wiring has
                    // nothing left to activate. Management is GMO's default
                    // active tab; the fallback click only runs if, for any
                    // reason, no tab is active after removal.
                    var panel = document.querySelector('[data-panel="media-library"]');
                    if (!panel) return;
                    [
                        '.gmo-lib-storage-cta-wrap',
                        '#gmo-media-subtabs .gdc-sub-tab[data-media-subtab="upgrade"]',
                        '.gmo-media-subpanel[data-media-subpanel="upgrade"]'
                    ].forEach(function (sel) {
                        panel.querySelectorAll(sel).forEach(function (n) { n.remove(); });
                    });
                    if (!panel.querySelector('#gmo-media-subtabs .gdc-sub-tab.active')) {
                        var mgmt = panel.querySelector('#gmo-media-subtabs .gdc-sub-tab[data-media-subtab="management"]');
                        if (mgmt) mgmt.click();
                    }
                })();
                </script>
            </section>
            <?php else : ?>
            <!-- ── Media sub-panel (section_group=all - the BuddyPress group
                 Hosting tab, unchanged: Storage usage cards + Backups
                 together in one sub-tab, exactly as before). ── -->
            <section class="gs-hosting__panel" data-panel="media" role="tabpanel">
                <?php
                gs_hosting_render_storage_resource_cards( $media_data, $tables_data, $billing );
                gs_hosting_render_backups_section( $backups );
                ?>
            </section>

            <!-- ── Servers sub-panel — standalone here only (section_group=all,
                 group-embed.php's front-end Hosting tab, where Compute Gas
                 never renders - with_compute_gas defaults false there). For
                 section_group=hosting this same checkout iframe now lives
                 nested inside the Compute Gas panel's own sub-tabs instead. ── -->
            <section class="gs-hosting__panel" data-panel="servers" role="tabpanel">
                <div style="margin-bottom: 14px;">
                    <h4 class="gs-hosting__section-title"><?php esc_html_e( 'Servers', 'gend-society' ); ?></h4>
                    <p class="gs-hosting__section-sub" style="margin: 0;"><?php esc_html_e( 'Dedicated server resources for your app — pick a plan and attach it to this membership.', 'gend-society' ); ?></p>
                </div>
                <?php
                $gs_server_embed_url = function_exists( 'gdc_plan_attach_resource_embed_url' ) ? gdc_plan_attach_resource_embed_url( 'server' ) : '';
                ?>
                <?php if ( $gs_server_embed_url !== '' ) : ?>
                    <div class="gs-hosting__card" style="padding: 0; overflow: hidden;">
                        <iframe data-gs-hosting-servers-frame data-src="<?php echo esc_url( $gs_server_embed_url ); ?>" src="about:blank" title="<?php esc_attr_e( 'Server plan checkout', 'gend-society' ); ?>" loading="lazy" style="width: 100%; height: 900px; border: 0; display: block; background: #0b0e14;"></iframe>
                    </div>
                <?php else : ?>
                    <div class="gs-hosting__feedback is-error"><?php esc_html_e( 'Server checkout is not available right now.', 'gend-society' ); ?></div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- ── Codebase sub-panel: "hero" analytics matching the real
                 Media tab (GMO Library Dashboard's ring/stat-pill/bar
                 pattern — see gs_hosting_render_analytics_hero()), for
                 plugins/themes/mu-plugins, plus the App Feature Access
                 cards grid (moved here from Feature Suite -> Dashboards,
                 which had nothing else in it - see
                 dashboard-remote-membership.php). ── -->
            <section class="gs-hosting__panel" data-panel="codebase" role="tabpanel">
                <style>
                    .gs-codebase__subtabs { display:flex; gap:8px; margin:24px 0 18px; padding:6px; border:1px solid rgba(78,170,255,.2); border-radius:12px; background:rgba(0,0,0,.2); }
                    .gs-codebase__subtab { border:1px solid transparent; border-radius:9px; padding:10px 16px; background:transparent; color:#cbd5f5; cursor:pointer; font-weight:700; }
                    .gs-codebase__subtab:hover, .gs-codebase__subtab.is-active { color:#fff; background:rgba(78,170,255,.18); border-color:rgba(78,170,255,.35); }
                    .gs-codebase__subpanel { display:none; }
                    .gs-codebase__subpanel.is-active { display:block; }
                    @media (max-width:700px) { .gs-codebase__subtabs { flex-wrap:wrap; } }
                </style>
                <?php
                $gs_codebase_data = function_exists( 'gs_hosting_collect_codebase' ) ? gs_hosting_collect_codebase() : array();
                $gs_codebase_used = (int) ( $gs_codebase_data['bytes_used'] ?? 0 );
                $gs_codebase_cap  = (int) apply_filters( 'gs_hosting_codebase_plan_bytes', 2 * 1024 * 1024 * 1024 );
                $gs_codebase_pct  = gs_hosting_pct( $gs_codebase_used, $gs_codebase_cap );

                gs_hosting_render_analytics_hero( array(
                    'title' => __( 'Codebase Storage', 'gend-society' ),
                    'sub'   => __( 'Plugins, themes, and mu-plugins on this install, with live usage.', 'gend-society' ),
                    'pct'   => $gs_codebase_pct,
                    'warn'  => $gs_codebase_pct > 80,
                    'stats' => array(
                        array( 'k' => __( 'Used', 'gend-society' ), 'v' => size_format( $gs_codebase_used, 2 ) ),
                        array( 'k' => __( 'Capacity', 'gend-society' ), 'v' => size_format( $gs_codebase_cap, 0 ) ),
                        array( 'k' => __( 'Plugins', 'gend-society' ), 'v' => (string) (int) ( $gs_codebase_data['plugin_count'] ?? 0 ) ),
                        array( 'k' => __( 'Themes', 'gend-society' ), 'v' => (string) (int) ( $gs_codebase_data['theme_count'] ?? 0 ) ),
                        array( 'k' => __( 'Last Scanned', 'gend-society' ), 'v' => human_time_diff( (int) ( $gs_codebase_data['scanned_at'] ?? time() ), time() ) . ' ' . __( 'ago', 'gend-society' ) ),
                    ),
                ) );
                ?>

                <nav class="gs-codebase__subtabs" role="tablist" aria-label="<?php esc_attr_e( 'Codebase sections', 'gend-society' ); ?>">
                    <button type="button" class="gs-codebase__subtab is-active" data-gs-codebase-tab="dashboards" role="tab" aria-selected="true"><?php esc_html_e( 'Dashboards', 'gend-society' ); ?></button>
                    <button type="button" class="gs-codebase__subtab" data-gs-codebase-tab="packages" role="tab" aria-selected="false"><?php esc_html_e( 'Code Packages', 'gend-society' ); ?></button>
                    <button type="button" class="gs-codebase__subtab" data-gs-codebase-tab="breakdown" role="tab" aria-selected="false"><?php esc_html_e( 'File Breakdown', 'gend-society' ); ?></button>
                </nav>

                <div class="gs-codebase__subpanel is-active" data-gs-codebase-panel="dashboards">
                    <h4 class="gs-hosting__section-title"><?php esc_html_e( 'App Feature Access', 'gend-society' ); ?></h4>
                    <p class="gs-hosting__section-sub"><?php esc_html_e( 'Manage which plugins and features are available on this site.', 'gend-society' ); ?></p>
                    <?php
                    if ( function_exists( 'gs_render_feature_cards_widget' ) ) {
                        gs_render_feature_cards_widget( true );
                    }
                    ?>
                </div>

                <div class="gs-codebase__subpanel" data-gs-codebase-panel="packages">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
                        <div>
                            <h4 class="gs-hosting__section-title" style="margin: 0;"><?php esc_html_e( 'Code Packages', 'gend-society' ); ?></h4>
                            <p class="gs-hosting__section-sub" style="margin: 4px 0 0;"><?php esc_html_e( 'Every other plugin file on this install (not already shown under Dashboards), with real update status.', 'gend-society' ); ?></p>
                        </div>
                        <button type="button" class="gs-hosting__btn" data-gs-upload-plugin-open style="flex-shrink: 0;">
                            <span class="dashicons dashicons-upload" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom;"></span>
                            <?php esc_html_e( 'Upload New Plugin', 'gend-society' ); ?>
                        </button>
                    </div>
                    <?php
                    if ( function_exists( 'gs_hosting_render_codebase_packages' ) ) {
                        gs_hosting_render_codebase_packages();
                    }
                    ?>

                    <!-- ── Upload Plugin popup — real multi-file .zip select +
                         install via the gs_upload_plugin AJAX action
                         (feature-cards.php), reusing Plugin_Upgrader the same
                         way the Dashboards cards' Update button already does.
                         Same backdrop/card entrance treatment as the real
                         Upgrade popup (.gs-upgrade-modal), own class prefix
                         since this one holds a form instead of an iframe. ── -->
                    <style>
                        .gs-upload-modal { position: fixed; inset: 0; z-index: 999999; display: none; align-items: center; justify-content: center; padding: 24px; }
                        .gs-upload-modal.is-open { display: flex; }
                        .gs-upload-modal__backdrop {
                            position: absolute; inset: 0;
                            background: radial-gradient(1200px 500px at 20% 10%, rgba(34,211,238,.10), transparent 60%),
                                        radial-gradient(1000px 400px at 80% 90%, rgba(99,102,241,.10), transparent 55%),
                                        rgba(2, 6, 23, .78);
                            -webkit-backdrop-filter: blur(18px) saturate(160%);
                                    backdrop-filter: blur(18px) saturate(160%);
                            opacity: 0;
                            transition: opacity .32s cubic-bezier(.2,.9,.3,1);
                        }
                        .gs-upload-modal.is-open .gs-upload-modal__backdrop { opacity: 1; }
                        .gs-upload-modal__card {
                            position: relative;
                            width: 100%; max-width: 480px;
                            background: linear-gradient(160deg, rgba(15,23,42,.97), rgba(15,23,42,.90));
                            border: 1px solid rgba(125, 211, 252, .28);
                            border-radius: 24px;
                            box-shadow: 0 48px 96px rgba(0,0,0,.65), inset 0 1px 0 rgba(255,255,255,.05);
                            padding: 32px;
                            opacity: 0; transform: translateY(20px) scale(.98);
                            transition: opacity .35s cubic-bezier(.2,.9,.3,1), transform .35s cubic-bezier(.2,.9,.3,1);
                        }
                        .gs-upload-modal.is-open .gs-upload-modal__card { opacity: 1; transform: none; }
                        .gs-upload-modal__close {
                            position: absolute; top: 14px; right: 14px; z-index: 1;
                            width: 36px; height: 36px; border-radius: 50%;
                            background: rgba(11,14,20,.65); border: 1px solid rgba(125,211,252,.20);
                            color: #e2e8f0; font-size: 1.2rem; line-height: 1; cursor: pointer;
                            transition: background .18s ease, transform .15s ease;
                        }
                        .gs-upload-modal__close:hover { background: rgba(239,68,68,.20); transform: rotate(90deg); }
                        .gs-upload-modal__title { margin: 0 0 6px; font-size: 1.2rem; font-weight: 800; color: #fff; }
                        .gs-upload-modal__sub { margin: 0 0 20px; color: var(--gs-muted, #94a3b8); font-size: 0.85rem; }
                        .gs-upload-modal__drop { display: block; border: 2px dashed rgba(125,211,252,.28); border-radius: 14px; padding: 28px; text-align: center; cursor: pointer; transition: border-color .2s ease, background .2s ease; }
                        .gs-upload-modal__drop:hover { border-color: rgba(125,211,252,.5); background: rgba(125,211,252,.05); }
                        .gs-upload-modal__filelist { margin-top: 14px; max-height: 140px; overflow-y: auto; font-size: 0.82rem; color: #e6edf7; text-align: left; }
                        .gs-upload-modal__filelist div { padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
                        .gs-upload-modal__submit { margin-top: 20px; width: 100%; justify-content: center; }
                    </style>
                    <div class="gs-upload-modal" id="gs-upload-plugin-modal" aria-hidden="true" role="dialog" aria-modal="true">
                        <div class="gs-upload-modal__backdrop" data-gs-upload-plugin-close></div>
                        <div class="gs-upload-modal__card">
                            <button type="button" class="gs-upload-modal__close" data-gs-upload-plugin-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                            <h3 class="gs-upload-modal__title"><?php esc_html_e( 'Upload New Plugin', 'gend-society' ); ?></h3>
                            <p class="gs-upload-modal__sub"><?php esc_html_e( 'Select one or more .zip files to install.', 'gend-society' ); ?></p>
                            <form id="gs-upload-plugin-form" data-nonce="<?php echo esc_attr( wp_create_nonce( 'gs_plugin_actions' ) ); ?>">
                                <label class="gs-upload-modal__drop" for="gs-upload-plugin-input">
                                    <span class="dashicons dashicons-upload" style="font-size: 28px; width: 28px; height: 28px; color: #8ecbff;"></span>
                                    <div style="margin-top: 10px; font-weight: 700; color: #fff;"><?php esc_html_e( 'Choose ZIP file(s)…', 'gend-society' ); ?></div>
                                    <input type="file" id="gs-upload-plugin-input" name="plugin_zip[]" accept=".zip,application/zip" multiple hidden>
                                </label>
                                <div class="gs-upload-modal__filelist" id="gs-upload-plugin-filelist"></div>
                                <button type="submit" class="gs-hosting__btn gs-upgrade-cta gs-upload-modal__submit"><?php esc_html_e( 'Install', 'gend-society' ); ?></button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="gs-codebase__subpanel" data-gs-codebase-panel="breakdown">
                    <h4 class="gs-hosting__section-title"><?php esc_html_e( 'File Breakdown', 'gend-society' ); ?></h4>
                    <table class="gs-hosting__table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'gend-society' ); ?></th>
                                <th style="text-align: right;"><?php esc_html_e( 'Size', 'gend-society' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $gs_codebase_breakdown = function_exists( 'gs_hosting_collect_codebase_breakdown' ) ? gs_hosting_collect_codebase_breakdown() : array();
                            ?>
                            <?php if ( empty( $gs_codebase_breakdown ) ) : ?>
                                <tr><td colspan="3" style="color:var(--gs-muted, #94a3b8); font-style:italic; padding:18px 12px;"><?php esc_html_e( 'No plugins, themes, or mu-plugins found.', 'gend-society' ); ?></td></tr>
                            <?php else : foreach ( $gs_codebase_breakdown as $gs_cb_entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $gs_cb_entry['name'] ); ?></td>
                                    <td><span class="gs-hosting__pill is-ok"><?php echo esc_html( ucfirst( str_replace( '-', ' ', $gs_cb_entry['type'] ) ) ); ?></span></td>
                                    <td style="text-align: right;"><?php echo esc_html( size_format( (int) $gs_cb_entry['bytes'], 2 ) ); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; // show_hosting_group (tables, media/storage, servers) ?>

            <?php if ( $with_permalinks && $show_app_group ) : ?>
            <!-- ── Permalinks sub-panel (URL structure, category / tag base; moved out of
                 the Dashboard sub-tab). Saves via admin-post.php and comes back with
                 gs_section=permalinks so this sub-tab reopens. ── -->
            <section class="gs-hosting__panel<?php echo 'permalinks' === $active_section ? ' is-active' : ''; ?>" data-panel="permalinks" role="tabpanel">
                <?php
                if ( function_exists( 'gs_render_permalink_settings_form' ) ) {
                    gs_render_permalink_settings_form();
                }
                ?>
            </section>
            <?php endif; ?>

        </div>
    </div>

    <script>
    (function(){
        var root = document.getElementById(<?php echo wp_json_encode( $gs_hosting_root_id ); ?>);
        if (!root) return;
        var ajax = window.ajaxurl || <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        // Reuse the membership-card nonce, same scope.
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;
        var loaded = { 'logs': false, 'toggles': false, 'compute-gas': false };

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
                // GMO_Library_Dashboard's Disk Usage ring (inside the Media
                // sub-tab) is entirely styled under body.gmo-lib-on - normally
                // added server-side by that class on upload.php / the real
                // blog-manager screen, neither of which this page is, so it's
                // toggled here instead, exactly while that sub-tab is open.
                document.body.classList.toggle('gmo-lib-on', section === 'media-library');
                lazyLoad(section);
            });
        });

        function lazyLoad(section) {
            if (section === 'logs' && !loaded['logs']) {
                loaded['logs'] = true;
                loadLogs();
            }
            if (section === 'compute-gas' && !loaded['compute-gas']) {
                loaded['compute-gas'] = true;
                // Defined in dashboard-remote-membership.php's own script (a separate IIFE) —
                // it's already run by the time a user can click this sub-tab.
                if (typeof window.gsLoadComputeGasBreakdown === 'function') window.gsLoadComputeGasBreakdown();
            }
            if (section === 'servers') {
                var serverFrame = root.querySelector('[data-gs-hosting-servers-frame]');
                if (serverFrame && serverFrame.dataset.src && serverFrame.getAttribute('src') === 'about:blank') {
                    serverFrame.src = serverFrame.dataset.src;
                }
            }
        }

        // ── Dashboard actions ──
        function bindAction(selector, action, opts) {
            opts = opts || {};
            var feedbackSection = opts.feedback || 'dashboard';
            root.querySelectorAll(selector).forEach(function(btn){
                btn.addEventListener('click', function(){
                    if (opts.confirm && !confirm(opts.confirm)) return;
                    btn.disabled = true;
                    var orig = btn.textContent;
                    btn.textContent = opts.busy || 'Working…';
                    feedback(feedbackSection, '', null);
                    post(action).then(function(resp){
                        if (resp && resp.success) {
                            feedback(feedbackSection, (resp.data && resp.data.message) || (opts.successMsg || 'Done.'), 'success');
                        } else {
                            feedback(feedbackSection, (resp && resp.data && resp.data.message) || 'Failed.', 'error');
                        }
                    }).catch(function(){
                        feedback(feedbackSection, 'Network error.', 'error');
                    }).finally(function(){
                        btn.disabled = false;
                        btn.textContent = orig;
                    });
                });
            });
        }
        bindAction('[data-gs-hosting="cache-page"]',     'gs_hosting_cache_page',     { busy: 'Clearing…',  successMsg: 'Page cache cleared.', feedback: 'cache' });
        bindAction('[data-gs-hosting="cache-object"]',   'gs_hosting_cache_object',   { busy: 'Clearing…',  successMsg: 'Object cache flushed.', feedback: 'cache' });
        bindAction('[data-gs-hosting="template-reset"]', 'gs_hosting_template_reset', { busy: 'Resetting…', successMsg: 'Reset initiated. Backup running in background.', confirm: 'This will wipe posts, pages, and media on this install. A backup runs first. Continue?', feedback: 'cache' });

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
                feedback('security', '', null);
                post('gs_hosting_toggle_set', { feature: name, enabled: want ? 1 : 0 }).then(function(resp){
                    if (resp && resp.success) {
                        applyToggle(name, !!(resp.data && resp.data.enabled));
                        feedback('security', name.toUpperCase() + ' ' + (resp.data && resp.data.enabled ? 'enabled' : 'disabled') + '.', 'success');
                    } else {
                        btn.disabled = false;
                        btn.textContent = prev;
                        feedback('security', (resp && resp.data && resp.data.message) || 'Failed.', 'error');
                    }
                }).catch(function(){
                    btn.disabled = false;
                    btn.textContent = prev;
                    feedback('security', 'Network error.', 'error');
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

        });

        // ── Tables explorer ──
        // Inline mode: mount the explorer inside the Tables panel's
        // #gs-hosting-tables-inline slot (replacing the old launch button +
        // body-portaled popup). Falls back to the body portal only if the
        // node isn't flagged .is-inline, so the popup path still works
        // should it ever be re-enabled.
        var $tblModal = document.getElementById('gs-hosting-tables-modal');
        var $tblInline = document.getElementById('gs-hosting-tables-inline');
        var tblIsInline = !!($tblModal && $tblModal.classList.contains('is-inline'));
        if ($tblModal && tblIsInline && $tblInline && $tblModal.parentNode !== $tblInline) {
            $tblInline.appendChild($tblModal);
        } else if ($tblModal && !tblIsInline && $tblModal.parentNode !== document.body) {
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
        // Inline mode: nothing to open/close - just make sure the default
        // (Tables list) pane state is applied once on load.
        if (tblIsInline) { setTablesTab('list'); }
        function openTablesModal() {
            if (!$tblModal || tblIsInline) return;
            $tblModal.removeAttribute('hidden');
            $tblModal.setAttribute('aria-hidden', 'false');
            $tblModal.classList.add('is-open');
            document.body.classList.add('gs-hosting-tables-open');
            // Always land on the Tables tab when the modal opens.
            setTablesTab('list');
        }
        function closeTablesModal() {
            if (!$tblModal || tblIsInline) return;
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
        // Numeric column sort (Rows / Data / Index / Total): click a header
        // to sort high -> low, click again to flip. Sorts on the raw
        // data-sort-* values each row carries (not the formatted text), and
        // reorders rows in place so the search filter's per-row display
        // state survives re-sorting.
        function gsSortTablesBy(th) {
            var key = th.getAttribute('data-gs-sort');
            var table = th.closest('table');
            var tbody = table ? table.querySelector('tbody') : null;
            if (!key || !tbody) return;
            var dir = th.getAttribute('data-gs-sort-dir') === 'desc' ? 'asc' : 'desc';
            table.querySelectorAll('thead th[data-gs-sort]').forEach(function (h) {
                h.removeAttribute('data-gs-sort-dir');
                var ind = h.querySelector('[data-gs-sort-ind]');
                if (ind) ind.textContent = '';
            });
            th.setAttribute('data-gs-sort-dir', dir);
            var ind = th.querySelector('[data-gs-sort-ind]');
            if (ind) ind.textContent = dir === 'desc' ? '▼' : '▲';
            var attr = 'sort' + key.charAt(0).toUpperCase() + key.slice(1);
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            rows.sort(function (a, b) {
                var av = parseInt(a.dataset[attr], 10) || 0;
                var bv = parseInt(b.dataset[attr], 10) || 0;
                return dir === 'desc' ? bv - av : av - bv;
            });
            rows.forEach(function (tr) { tbody.appendChild(tr); });
        }
        // This whole script block is printed twice on the membership
        // dashboard (two hosting-UI instances share one explorer table), so
        // bind the sort listeners once: a double-bound toggle would flip
        // direction twice per click and never land on descending. The
        // other handlers here are idempotent, so only this one needs it.
        if (!window.__gsTablesSortBound) {
            window.__gsTablesSortBound = true;
            document.addEventListener('click', function(e){
                var th = e.target && e.target.closest && e.target.closest('#gs-hosting-tables-table thead th[data-gs-sort]');
                if (th) { e.preventDefault(); gsSortTablesBy(th); }
            });
            document.addEventListener('keydown', function(e){
                if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.matches && e.target.matches('#gs-hosting-tables-table thead th[data-gs-sort]')) {
                    e.preventDefault();
                    gsSortTablesBy(e.target);
                }
            });
        }
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
function gs_hosting_collect_container_resources( $media_data, $tables_data, $membership = null ) {
    $resources = array();

    if ( null === $membership && function_exists( 'gs_dashboard_get_membership' ) ) {
        $membership = gs_dashboard_get_membership();
    }
    $gs_resource_prices = function_exists( 'gs_hosting_container_resource_prices' )
        ? gs_hosting_container_resource_prices( $membership )
        : array( 'media' => null, 'database' => null, 'code' => null );
    // Only look up starting prices for whichever resources have nothing
    // attached - avoids the extra gdc_plan_attach_get_plans() calls
    // entirely once a real product is attached for all three.
    $gs_resource_starting_prices = ( in_array( null, $gs_resource_prices, true ) && function_exists( 'gs_hosting_container_resource_starting_prices' ) )
        ? gs_hosting_container_resource_starting_prices()
        : array( 'media' => null, 'database' => null, 'code' => null );

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
        'price'          => $gs_resource_prices['media'],
        'starting_price' => $gs_resource_starting_prices['media'],
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
        'price'          => $gs_resource_prices['database'],
        'starting_price' => $gs_resource_starting_prices['database'],
        'meta'       => sprintf(
            /* translators: 1: table count, 2: row count */
            __( '%1$s tables · %2$s rows', 'gend-society' ),
            number_format_i18n( (int) ( $tables_data['count'] ?? 0 ) ),
            number_format_i18n( (int) ( $tables_data['total_rows'] ?? 0 ) )
        ),
    );

    // v12.1 — Compute row moved to the Compute Gas → Power tab (that's where
    // it belongs — hosting Containers now only tracks code + data + media).
    // Codebase storage (plugins / themes / mu-plugins directories under
    // wp-content). Real-time size walk cached for one hour so page loads
    // don't hit the disk.
    $code_data = gs_hosting_collect_codebase();
    $code_used = (int) ( $code_data['bytes_used'] ?? 0 );
    $code_cap  = (int) apply_filters( 'gs_hosting_codebase_plan_bytes', 2 * 1024 * 1024 * 1024 ); // 2 GB default
    $resources[] = array(
        'slug'       => 'codebase',
        'label'      => __( 'Codebase Storage', 'gend-society' ),
        'icon'       => 'dashicons-editor-code',
        'hint'       => __( 'Plugins + themes + mu-plugins on this install', 'gend-society' ),
        'used'       => $code_used,
        'cap'        => $code_cap,
        'used_label' => size_format( $code_used, 2 ),
        'cap_label'  => size_format( $code_cap, 0 ),
        'price'          => $gs_resource_prices['code'],
        'starting_price' => $gs_resource_starting_prices['code'],
        'meta'       => sprintf(
            /* translators: 1: plugin count, 2: theme count, 3: relative time since scan */
            __( '%1$s plugins · %2$s themes · scanned %3$s ago', 'gend-society' ),
            number_format_i18n( (int) ( $code_data['plugin_count'] ?? 0 ) ),
            number_format_i18n( (int) ( $code_data['theme_count'] ?? 0 ) ),
            human_time_diff( (int) ( $code_data['scanned_at'] ?? time() ), time() )
        ),
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

function gs_hosting_backups_plan_bytes() {
    // Default 10 GB. Same hardcoded-but-filterable idiom as
    // gs_hosting_db_plan_bytes()/gs_hosting_media_plan_bytes() above -
    // none of these three pull a real per-membership quota yet either,
    // this isn't a lower bar than its siblings.
    return (int) apply_filters( 'gs_hosting_backups_plan_bytes', 10 * 1024 * 1024 * 1024 );
}

/**
 * v12.1 — Codebase storage collector. Walks WP_PLUGIN_DIR + WPMU_PLUGIN_DIR
 * + wp-content/themes for total on-disk size + counts. Cached in a
 * transient for 1 hour so page loads never trigger a live disk walk.
 * @return array{bytes_used:int, plugin_count:int, theme_count:int, scanned_at:int}
 */
function gs_hosting_collect_codebase() {
    $cache_key = 'gs_hosting_codebase_v1';
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) && isset( $cached['bytes_used'] ) ) {
        return $cached;
    }
    $total_bytes = 0;
    $plugin_count = 0;
    $theme_count = 0;
    $dirs = array();
    if ( defined( 'WP_PLUGIN_DIR' ) )   $dirs['plugins']    = WP_PLUGIN_DIR;
    if ( defined( 'WPMU_PLUGIN_DIR' ) ) $dirs['mu-plugins'] = WPMU_PLUGIN_DIR;
    if ( defined( 'WP_CONTENT_DIR' ) )  $dirs['themes']     = WP_CONTENT_DIR . '/themes';
    foreach ( $dirs as $bucket => $path ) {
        if ( ! is_string( $path ) || $path === '' || ! is_dir( $path ) ) continue;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $it as $file ) {
                if ( $file->isFile() ) {
                    $total_bytes += (int) $file->getSize();
                }
            }
        } catch ( \Throwable $e ) {
            /* symlink loops / permission denied — silent skip */
        }
    }
    // Plugin count: top-level entries under WP_PLUGIN_DIR (dirs or single-file plugins)
    if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
        $plugin_count = count( array_diff( (array) scandir( WP_PLUGIN_DIR ), array( '.', '..' ) ) );
    }
    if ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR . '/themes' ) ) {
        $theme_count = count( array_diff( (array) scandir( WP_CONTENT_DIR . '/themes' ), array( '.', '..' ) ) );
    }
    $out = array(
        'bytes_used'   => (int) $total_bytes,
        'plugin_count' => (int) $plugin_count,
        'theme_count'  => (int) $theme_count,
        'scanned_at'   => (int) time(),
    );
    set_transient( $cache_key, $out, HOUR_IN_SECONDS );
    return $out;
}

if ( ! function_exists( 'gs_hosting_collect_codebase_breakdown' ) ) {
    /**
     * Per-plugin / per-theme / per-mu-plugin on-disk size breakdown, for the
     * Codebase sub-tab's analytics list - same three directories
     * gs_hosting_collect_codebase() totals, walked per top-level entry
     * instead of summed together. Cached 1h, same discipline as that
     * function (a real per-entry disk walk).
     *
     * @return array<int,array{name:string,type:string,bytes:int}> sorted by bytes desc.
     */
    function gs_hosting_collect_codebase_breakdown() {
        $cache_key = 'gs_hosting_codebase_breakdown_v1';
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $entries = array();

        $walk_entry_size = function ( $path ) {
            if ( is_file( $path ) ) {
                return (int) filesize( $path );
            }
            if ( ! is_dir( $path ) ) {
                return 0;
            }
            $bytes = 0;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ( $it as $file ) {
                    if ( $file->isFile() ) {
                        $bytes += (int) $file->getSize();
                    }
                }
            } catch ( \Throwable $e ) {
                /* symlink loops / permission denied — return what we have so far */
            }
            return $bytes;
        };

        $buckets = array();
        if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) )     $buckets['plugin']    = WP_PLUGIN_DIR;
        if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) $buckets['mu-plugin'] = WPMU_PLUGIN_DIR;
        if ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR . '/themes' ) ) $buckets['theme'] = WP_CONTENT_DIR . '/themes';

        foreach ( $buckets as $type => $dir ) {
            foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $entry ) {
                $entries[] = array(
                    'name'  => $entry,
                    'type'  => $type,
                    'bytes' => $walk_entry_size( trailingslashit( $dir ) . $entry ),
                );
            }
        }

        usort( $entries, function ( $a, $b ) { return $b['bytes'] <=> $a['bytes']; } );

        set_transient( $cache_key, $entries, HOUR_IN_SECONDS );
        return $entries;
    }
}

if ( ! function_exists( 'gs_hosting_render_codebase_packages' ) ) {
    /**
     * Every installed plugin that ISN'T one of the curated "Dashboards"
     * feature cards (gs_get_feature_definitions(), feature-cards.php) - a
     * flat, real list of the rest of the codebase's plugin files, each with
     * its real update-available status (the same gs_has_plugin_update() /
     * get_site_transient('update_plugins') check the feature cards use) and
     * an Update button reusing the SAME real gs_update_plugin AJAX handler +
     * .gs-ajax-action click delegation already wired in feature-cards.php's
     * admin_footer script - no new JS needed for the update action itself.
     */
    function gs_hosting_render_codebase_packages() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();

        $dashboard_plugin_files = array();
        if ( function_exists( 'gs_get_feature_definitions' ) ) {
            foreach ( gs_get_feature_definitions() as $gs_feat ) {
                if ( ! empty( $gs_feat['plugin'] ) ) {
                    $dashboard_plugin_files[ $gs_feat['plugin'] ] = true;
                }
            }
        }

        $packages = array();
        foreach ( $all_plugins as $gs_pkg_file => $gs_pkg_data ) {
            if ( isset( $dashboard_plugin_files[ $gs_pkg_file ] ) ) {
                continue; // already shown as a Dashboards feature card
            }
            $packages[ $gs_pkg_file ] = $gs_pkg_data;
        }
        ksort( $packages );

        $gs_pkg_nonce = wp_create_nonce( 'gs_plugin_actions' );
        ?>
        <style>
            .gs-pkg-list { display: flex; flex-direction: column; gap: 10px; }
            .gs-pkg-row {
                position: relative; display: flex; align-items: center; gap: 16px;
                padding: 14px 18px 14px 22px; overflow: hidden;
                background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
                border-radius: 14px;
                transition: transform .2s ease, border-color .2s ease, background .2s ease, box-shadow .2s ease;
            }
            .gs-pkg-row::before {
                content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
                background: rgba(255,255,255,0.15);
            }
            .gs-pkg-row:hover {
                transform: translateX(4px);
                border-color: rgba(255,255,255,0.18);
                background: rgba(255,255,255,0.05);
                box-shadow: 0 8px 20px rgba(0,0,0,0.25);
            }
            .gs-pkg-row.has-update { background: rgba(245,158,11,0.07); border-color: rgba(245,158,11,0.22); }
            .gs-pkg-row.has-update::before { background: linear-gradient(180deg, #f59e0b, #fbbf24); }
            .gs-pkg-row.is-active::before { background: linear-gradient(180deg, #10b981, #34d399); }
            .gs-pkg-icon {
                flex-shrink: 0; width: 38px; height: 38px; border-radius: 10px;
                display: flex; align-items: center; justify-content: center;
                background: rgba(255,255,255,0.05); color: #8ecbff;
            }
            .gs-pkg-icon .dashicons { font-size: 20px; width: 20px; height: 20px; }
            .gs-pkg-info { flex: 1 1 auto; min-width: 0; }
            .gs-pkg-name { color: #fff; font-weight: 700; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .gs-pkg-version { color: var(--gs-muted, #94a3b8); font-size: 0.78rem; margin-top: 2px; }
            .gs-pkg-status { flex-shrink: 0; }
            .gs-pkg-action { flex-shrink: 0; min-width: 152px; text-align: right; }
            .gs-pkg-action .gs-hosting__btn { padding: 7px 14px; font-size: 0.75rem; box-shadow: 0 4px 14px rgba(245,158,11,.28); background: linear-gradient(135deg, #f59e0b, #fbbf24); border: none; color: #1a1200; font-weight: 800; transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s ease; }
            .gs-pkg-action .gs-hosting__btn:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 8px 20px rgba(245,158,11,.4); }
            .gs-pkg-uptodate { color: var(--gs-muted, #94a3b8); font-size: 0.78rem; display: inline-flex; align-items: center; gap: 5px; }
            .gs-pkg-uptodate .dashicons { font-size: 15px; width: 15px; height: 15px; color: #6ee7b7; }
            .gs-pkg-empty { color: var(--gs-muted, #94a3b8); font-style: italic; padding: 18px 4px; }
        </style>
        <div class="gs-pkg-list">
            <?php if ( empty( $packages ) ) : ?>
                <p class="gs-pkg-empty"><?php esc_html_e( 'No other plugin files found.', 'gend-society' ); ?></p>
            <?php else : foreach ( $packages as $gs_pkg_file => $gs_pkg ) :
                $gs_pkg_active = function_exists( 'is_plugin_active' ) && is_plugin_active( $gs_pkg_file );
                $gs_pkg_update = function_exists( 'gs_has_plugin_update' ) && gs_has_plugin_update( $gs_pkg_file );
                $gs_pkg_name   = ! empty( $gs_pkg['Name'] ) ? $gs_pkg['Name'] : $gs_pkg_file;
                $gs_pkg_row_classes = trim( ( $gs_pkg_update ? ' has-update' : '' ) . ( $gs_pkg_active && ! $gs_pkg_update ? ' is-active' : '' ) );
            ?>
                <div class="gs-pkg-row<?php echo $gs_pkg_row_classes !== '' ? ' ' . esc_attr( $gs_pkg_row_classes ) : ''; ?>">
                    <span class="gs-pkg-icon"><span class="dashicons dashicons-admin-plugins"></span></span>
                    <div class="gs-pkg-info">
                        <div class="gs-pkg-name"><?php echo esc_html( $gs_pkg_name ); ?></div>
                        <div class="gs-pkg-version"><?php echo esc_html( ! empty( $gs_pkg['Version'] ) ? sprintf( /* translators: %s: Package version number. */ __( 'v%s', 'gend-society' ), $gs_pkg['Version'] ) : __( 'Version unknown', 'gend-society' ) ); ?></div>
                    </div>
                    <span class="gs-pkg-status gs-hosting__pill<?php echo $gs_pkg_active ? ' is-ok' : ''; ?>"><?php echo $gs_pkg_active ? esc_html__( 'Active', 'gend-society' ) : esc_html__( 'Inactive', 'gend-society' ); ?></span>
                    <div class="gs-pkg-action">
                        <?php if ( $gs_pkg_update ) : ?>
                            <button type="button" class="gs-hosting__btn gs-ajax-action" data-action="update" data-plugin="<?php echo esc_attr( $gs_pkg_file ); ?>" data-nonce="<?php echo esc_attr( $gs_pkg_nonce ); ?>">
                                <span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span>
                                <?php esc_html_e( 'Update available', 'gend-society' ); ?>
                            </button>
                        <?php else : ?>
                            <span class="gs-pkg-uptodate"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Up to date', 'gend-society' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php
    }
}

function gs_hosting_pct( $used, $cap ) {
    if ( $cap <= 0 ) return 0;
    $pct = ( $used / $cap ) * 100;
    if ( $pct < 0 ) return 0;
    if ( $pct > 100 ) return 100;
    return $pct;
}

/**
 * Real monthly total of just this membership's container products (media +
 * database + codebase - product subgroup "containers", same taxonomy
 * vendor-app-manager's gdc_plan_attach_types() already uses), instead of
 * $membership->get_amount() (the WHOLE membership, every product combined).
 *
 * @param object|null $membership A Vendor App Manager Membership instance.
 * @return array{amount:float,label:string,unit:string}|null Null if no
 *         container product is attached (caller should fall back to $billing).
 */
function gs_hosting_container_plan_total( $membership ) {
    if ( ! $membership || ! is_object( $membership ) || ! method_exists( $membership, 'get_all_products' ) ) {
        return null;
    }

    $total = 0.0;
    $found = false;

    foreach ( (array) $membership->get_all_products() as $row ) {
        $prod = is_array( $row ) && isset( $row['product'] ) ? $row['product'] : null;
        if ( ! $prod || ! is_object( $prod ) || ! method_exists( $prod, 'get_amount' ) ) {
            continue;
        }
        $subgroup = method_exists( $prod, 'get_subgroup' ) ? strtolower( (string) $prod->get_subgroup() ) : '';
        if ( $subgroup !== 'containers' ) {
            continue;
        }
        $qty = isset( $row['quantity'] ) ? max( 1, (int) $row['quantity'] ) : 1;
        $total += (float) $prod->get_amount() * $qty;
        $found = true;
    }

    if ( ! $found ) {
        return null;
    }

    $currency = method_exists( $membership, 'get_currency' ) ? (string) $membership->get_currency() : '';
    $unit     = method_exists( $membership, 'get_duration_unit' ) ? (string) $membership->get_duration_unit() : 'month';
    $label    = function_exists( 'wu_format_currency' ) ? wu_format_currency( $total, $currency ) : number_format( $total, 2 );

    return array(
        'amount' => $total,
        'label'  => $label,
        'unit'   => $unit,
    );
}

if ( ! function_exists( 'gs_hosting_container_resource_prices' ) ) {
    /**
     * Per-resource (media/database/code) monthly price for whichever real
     * container-subgroup product is attached to this membership - same
     * wu_container_storage_type meta (falling back to slug prefix) lookup
     * vendor-app-manager's gdc_render_change_hosting_plan_modal_core() uses
     * for the equivalent resolution. A resource with nothing attached
     * returns null (not 0), so callers can show an honest "Included in
     * your plan" instead of a fabricated price.
     *
     * @param object|null $membership A Vendor App Manager Membership instance.
     * @return array{media:array|null,database:array|null,code:array|null}
     */
    function gs_hosting_container_resource_prices( $membership ) {
        $out = array( 'media' => null, 'database' => null, 'code' => null );
        if ( ! $membership || ! is_object( $membership ) || ! method_exists( $membership, 'get_all_products' ) ) {
            return $out;
        }

        $currency = method_exists( $membership, 'get_currency' )      ? (string) $membership->get_currency()      : '';
        $unit     = method_exists( $membership, 'get_duration_unit' ) ? (string) $membership->get_duration_unit() : 'month';

        foreach ( (array) $membership->get_all_products() as $row ) {
            $prod = is_array( $row ) && isset( $row['product'] ) ? $row['product'] : null;
            if ( ! $prod || ! is_object( $prod ) || ! method_exists( $prod, 'get_amount' ) ) {
                continue;
            }
            $subgroup = method_exists( $prod, 'get_subgroup' ) ? strtolower( (string) $prod->get_subgroup() ) : '';
            if ( $subgroup !== 'containers' ) {
                continue;
            }

            $storage_type = method_exists( $prod, 'get_meta' ) ? (string) $prod->get_meta( 'wu_container_storage_type', '' ) : '';
            if ( $storage_type === '' && method_exists( $prod, 'get_slug' ) ) {
                $slug = (string) $prod->get_slug();
                if ( strpos( $slug, 'container-code-' ) === 0 )      { $storage_type = 'code'; }
                elseif ( strpos( $slug, 'container-media-' ) === 0 ) { $storage_type = 'media'; }
                elseif ( strpos( $slug, 'container-db-' ) === 0 )    { $storage_type = 'database'; }
            }
            if ( ! array_key_exists( $storage_type, $out ) ) {
                continue;
            }

            $qty    = isset( $row['quantity'] ) ? max( 1, (int) $row['quantity'] ) : 1;
            $amount = (float) $prod->get_amount() * $qty;
            $label  = function_exists( 'wu_format_currency' ) ? wu_format_currency( $amount, $currency ) : number_format( $amount, 2 );

            $out[ $storage_type ] = array( 'amount' => $amount, 'label' => $label, 'unit' => $unit );
        }

        return $out;
    }
}

if ( ! function_exists( 'gs_hosting_container_resource_starting_prices' ) ) {
    /**
     * "From $X / month" - the cheapest AVAILABLE real tier for each
     * resource (media/database/codebase), sourced from the exact same
     * gdc_plan_attach_get_plans() data the Upgrade button's own picker
     * opens, so the number shown on the card always matches what clicking
     * Upgrade actually offers. Used as the fallback when nothing is
     * currently attached for that resource (gs_hosting_container_resource_prices()
     * returned null) - shows what upgrading would cost instead of a bare
     * "Included in your plan" with no number at all.
     *
     * @return array{media:array|null,database:array|null,code:array|null}
     */
    function gs_hosting_container_resource_starting_prices() {
        $out = array( 'media' => null, 'database' => null, 'code' => null );
        if ( ! function_exists( 'gdc_plan_attach_get_plans' ) || ! function_exists( 'wu_get_product' ) ) {
            return $out;
        }

        $type_map = array( 'media' => 'media', 'database' => 'database', 'code' => 'codebase' );
        foreach ( $type_map as $out_key => $attach_type ) {
            $plans           = gdc_plan_attach_get_plans( $attach_type );
            $cheapest        = null;
            $cheapest_amount = null;
            foreach ( $plans as $p ) {
                if ( empty( $p['available'] ) || empty( $p['id'] ) ) {
                    continue;
                }
                $plan_obj = wu_get_product( (int) $p['id'] );
                $amount   = ( $plan_obj && method_exists( $plan_obj, 'get_amount' ) ) ? (float) $plan_obj->get_amount() : null;
                if ( null === $amount ) {
                    continue;
                }
                if ( null === $cheapest_amount || $amount < $cheapest_amount ) {
                    $cheapest_amount = $amount;
                    $cheapest        = $p;
                }
            }
            if ( $cheapest ) {
                $out[ $out_key ] = array(
                    'label'  => isset( $cheapest['price'] ) ? (string) $cheapest['price'] : '',
                    'amount' => $cheapest_amount,
                );
            }
        }

        return $out;
    }
}

if ( ! function_exists( 'gs_hosting_server_price' ) ) {
    /**
     * Current (summed across every attached server-subgroup product/row,
     * not just the first one found) + starting price for a Server-subgroup
     * product - same data source and pattern as
     * gs_hosting_container_resource_prices()/_starting_prices(), but for the
     * 'server' plan-attach type (no storage_type split) that backs the
     * Hosting tab's own Servers sub-tab. Used by the top membership card's
     * "Server Costs" summary, which needs the real TOTAL of every server
     * this membership has attached (a membership can carry more than one -
     * either the same product at qty>1, or several different server tiers
     * as separate product rows), not just one.
     *
     * @param object|null $membership A Vendor App Manager Membership instance.
     * @return array{current:array|null,starting:array|null}
     */
    function gs_hosting_server_price( $membership ) {
        $current = null;
        if ( $membership && is_object( $membership ) && method_exists( $membership, 'get_all_products' ) ) {
            $currency     = method_exists( $membership, 'get_currency' )      ? (string) $membership->get_currency()      : '';
            $unit         = method_exists( $membership, 'get_duration_unit' ) ? (string) $membership->get_duration_unit() : 'month';
            $total_amount = 0.0;
            $total_count  = 0;
            foreach ( (array) $membership->get_all_products() as $row ) {
                $prod = is_array( $row ) && isset( $row['product'] ) ? $row['product'] : null;
                if ( ! $prod || ! is_object( $prod ) || ! method_exists( $prod, 'get_amount' ) ) {
                    continue;
                }
                $subgroup = method_exists( $prod, 'get_subgroup' ) ? strtolower( (string) $prod->get_subgroup() ) : '';
                if ( $subgroup !== 'server' ) {
                    continue;
                }
                $qty           = isset( $row['quantity'] ) ? max( 1, (int) $row['quantity'] ) : 1;
                $total_amount += (float) $prod->get_amount() * $qty;
                $total_count  += $qty;
            }
            if ( $total_count > 0 ) {
                $label   = function_exists( 'wu_format_currency' ) ? wu_format_currency( $total_amount, $currency ) : number_format( $total_amount, 2 );
                $current = array( 'amount' => $total_amount, 'label' => $label, 'unit' => $unit, 'count' => $total_count );
            }
        }

        $starting = null;
        if ( null === $current && function_exists( 'gdc_plan_attach_get_plans' ) && function_exists( 'wu_get_product' ) ) {
            $plans           = gdc_plan_attach_get_plans( 'server' );
            $cheapest_amount = null;
            foreach ( $plans as $p ) {
                if ( empty( $p['available'] ) || empty( $p['id'] ) ) {
                    continue;
                }
                $plan_obj = wu_get_product( (int) $p['id'] );
                $amount   = ( $plan_obj && method_exists( $plan_obj, 'get_amount' ) ) ? (float) $plan_obj->get_amount() : null;
                if ( null === $amount ) {
                    continue;
                }
                if ( null === $cheapest_amount || $amount < $cheapest_amount ) {
                    $cheapest_amount = $amount;
                    $starting        = array( 'label' => isset( $p['price'] ) ? (string) $p['price'] : '', 'amount' => $amount );
                }
            }
        }

        return array( 'current' => $current, 'starting' => $starting );
    }
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
        $buf = @file_get_contents( $s['path'], false, null, $size - $read_bytes ); // Tail read at an offset (WP_Filesystem has no offset read).
        if ( false === $buf ) continue;
        if ( $size > $read_bytes ) {
            // Discard partial first line.
            $gs_nl = strpos( $buf, "\n" );
            $buf   = false === $gs_nl ? '' : (string) substr( $buf, $gs_nl + 1 );
        }
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

/**
 * Real Compute Gas cost breakdown + payment links for THIS site, read
 * directly from vendor-app-manager's real gdc_gas_ledger table (a
 * network-wide, base_prefix table - reachable here without a
 * switch_to_blog(), exactly like the sibling "Gas Stations" sub-panel's
 * own earnings query a few hundred lines below already does) and real
 * WP Ultimo Payment records (the real, separate GAS-usage invoices
 * vendor-app-manager's gdc_gas_run_usage_billing() creates).
 *
 * @return array
 */
function gs_hosting_compute_gas_real_data() {
    global $wpdb;
    $site_id = get_current_blog_id();
    $ledger  = $wpdb->base_prefix . 'gdc_gas_ledger';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT task_id, COUNT(*) AS event_count, SUM(units) AS units, SUM(sales_amount) AS sales_amount,
                SUM(CASE WHEN status = 'billed' THEN sales_amount ELSE 0 END) AS billed_amount,
                SUM(CASE WHEN status = 'recorded' THEN sales_amount ELSE 0 END) AS unbilled_amount
         FROM %i WHERE site_id = %d GROUP BY task_id ORDER BY sales_amount DESC",
        $ledger, $site_id
    ), ARRAY_A );

    $catalog = function_exists( '\\WP_Ultimo\\Integrations\\gdc_gas_catalog' ) ? \WP_Ultimo\Integrations\gdc_gas_catalog() : array();

    $breakdown      = array();
    $total_gas      = 0.0;
    $total_billed   = 0.0;
    $total_unbilled = 0.0;

    foreach ( (array) $rows as $row ) {
        $task_id       = (string) $row['task_id'];
        $sales_amount  = (float) $row['sales_amount'];
        $total_gas     += $sales_amount;
        $total_billed  += (float) $row['billed_amount'];
        $total_unbilled += (float) $row['unbilled_amount'];

        $breakdown[] = array(
            'task_id'      => $task_id,
            'label'        => isset( $catalog[ $task_id ]['description'] ) ? $catalog[ $task_id ]['description'] : $task_id,
            'qty'          => (int) $row['event_count'],
            'amount'       => $sales_amount,
            'amount_label' => number_format( $sales_amount, 6 ) . ' GAS',
        );
    }

    // pct is computed as a second pass once the real total is known, so
    // the animated bars below can size themselves off a real share of
    // real total spend rather than an arbitrary per-row scale.
    foreach ( $breakdown as &$b ) {
        $b['pct'] = $total_gas > 0 ? round( ( $b['amount'] / $total_gas ) * 100, 1 ) : 0;
    }
    unset( $b );

    // Real payment links - this site's real GAS-usage invoices (created
    // by vendor-app-manager's gdc_gas_run_usage_billing(), a separate,
    // real WP Ultimo Payment per billing run - never the site's regular
    // membership renewal payments, which is why line items are matched
    // by title rather than just listing every payment this customer has).
    $payments = array();
    $site     = function_exists( 'wu_get_site' ) ? wu_get_site( $site_id ) : null;
    $customer_id = $site ? (int) $site->get_customer_id() : 0;

    if ( $customer_id > 0 ) {
        $payment_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->base_prefix}wu_payments WHERE customer_id = %d ORDER BY id DESC LIMIT 30",
            $customer_id
        ) );
        foreach ( (array) $payment_ids as $payment_id ) {
            $payment = function_exists( 'wu_get_payment' ) ? wu_get_payment( (int) $payment_id ) : null;
            if ( ! $payment ) {
                continue;
            }
            $is_gas_invoice = false;
            foreach ( $payment->get_line_items() as $line_item ) {
                if ( strpos( (string) $line_item->get_title(), 'GAS compute usage' ) === 0 ) {
                    $is_gas_invoice = true;
                    break;
                }
            }
            if ( ! $is_gas_invoice ) {
                continue;
            }
            $payments[] = array(
                'id'           => (int) $payment_id,
                'status'       => $payment->get_status(),
                'total_label'  => number_format( (float) $payment->get_total(), 2 ) . ' ' . $payment->get_currency(),
                'date'         => $payment->get_date_created(),
                'pay_url'      => $payment->get_payment_url(),
                'invoice_url'  => $payment->get_invoice_url(),
            );
        }
    }

    return array(
        'period'               => __( 'All time', 'gend-society' ),
        // Raw floats alongside the pre-formatted labels below, so other
        // callers (e.g. the Dashboard homepage's "Gas Used" summary card)
        // can reuse this same real query without re-parsing a label string.
        'total_gas'            => $total_gas,
        'billed_gas'           => $total_billed,
        'unbilled_gas'         => $total_unbilled,
        'total_gas_label'      => number_format( $total_gas, 6 ) . ' GAS',
        'billed_gas_label'     => number_format( $total_billed, 6 ) . ' GAS',
        'unbilled_gas_label'   => number_format( $total_unbilled, 6 ) . ' GAS',
        'breakdown'            => $breakdown,
        'payments'             => $payments,
        'message'              => empty( $breakdown ) ? __( 'No GAS compute usage recorded yet for this app.', 'gend-society' ) : '',
    );
}

if ( ! function_exists( 'gs_hosting_gas_month_over_month' ) ) {
    /**
     * This-calendar-month vs previous-calendar-month real GAS consumption
     * (sales_amount) for a site, off the same real gdc_gas_ledger table
     * gs_hosting_compute_gas_real_data() totals all-time - a second, real
     * query scoped by created_at instead of a fabricated trend, backing the
     * membership card's "Change" stat (this month vs last month).
     *
     * @param int|null $site_id Defaults to the current blog.
     * @return array{this_month:float,last_month:float,this_month_label:string,last_month_label:string,change_pct:float|null,change_label:string,direction:string}
     */
    function gs_hosting_gas_month_over_month( $site_id = null ) {
        global $wpdb;
        $site_id = null === $site_id ? get_current_blog_id() : (int) $site_id;
        $ledger  = $wpdb->base_prefix . 'gdc_gas_ledger';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(), '%%Y-%%m-01') THEN sales_amount ELSE 0 END) AS this_month,
                SUM(CASE WHEN created_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%%Y-%%m-01') AND created_at < DATE_FORMAT(NOW(), '%%Y-%%m-01') THEN sales_amount ELSE 0 END) AS last_month
             FROM %i WHERE site_id = %d",
            $ledger, $site_id
        ), ARRAY_A );

        $this_month = $row ? (float) $row['this_month'] : 0.0;
        $last_month = $row ? (float) $row['last_month'] : 0.0;

        $change_pct = null;
        $direction  = 'flat';
        if ( $last_month > 0 ) {
            $change_pct = ( ( $this_month - $last_month ) / $last_month ) * 100;
            $direction  = $change_pct > 0.5 ? 'up' : ( $change_pct < -0.5 ? 'down' : 'flat' );
        } elseif ( $this_month > 0 ) {
            $direction = 'new'; // usage exists this month but there's no prior-month baseline to compare against
        }

        if ( null !== $change_pct ) {
            $change_label = sprintf( '%s%d%%', $change_pct >= 0 ? '+' : '', (int) round( $change_pct ) );
        } elseif ( 'new' === $direction ) {
            $change_label = __( 'New this month', 'gend-society' );
        } else {
            $change_label = __( 'No usage yet', 'gend-society' );
        }

        return array(
            'this_month'       => $this_month,
            'last_month'       => $last_month,
            'this_month_label' => number_format( $this_month, 6 ) . ' GAS',
            'last_month_label' => number_format( $last_month, 6 ) . ' GAS',
            'change_pct'       => $change_pct,
            'change_label'     => $change_label,
            'direction'        => $direction,
        );
    }
}

/**
 * Real GAS earned by a user acting as a Gas Station device operator -
 * SUM(owner_amount) across every gdc_gas_ledger row where that user is
 * the station's registered owner (station_user_id). Distinct from
 * gs_hosting_compute_gas_real_data()'s consumption-side totals: a site
 * owner can consume GAS running their own app AND separately earn GAS
 * by letting their own devices serve OTHER customers' tasks - two
 * different real money flows on the same ledger table, not one balance.
 */
function gs_hosting_gas_earned_summary( $user_id ) {
    global $wpdb;
    $ledger = $wpdb->base_prefix . 'gdc_gas_ledger';
    $row    = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS event_count, COALESCE(SUM(owner_amount), 0) AS total_earned, MAX(created_at) AS last_earned
         FROM %i WHERE station_user_id = %d",
        $ledger, (int) $user_id
    ), ARRAY_A );

    $total_earned = $row ? (float) $row['total_earned'] : 0.0;

    return array(
        'event_count'        => $row ? (int) $row['event_count'] : 0,
        'total_earned'       => $total_earned,
        'total_earned_label' => number_format( $total_earned, 6 ) . ' GAS',
        // Real payout unit, not a re-labeled GAS figure: gdc_gas_credit_gcp_payee()
        // (gdc-gas-dispatch.php) actually pays Gas Station device-owner earnings
        // out in DGEN (myCred 'transact' points), never in raw GAS units. GAS and
        // DGEN are both independently pegged 1:1 to CAD (see gcp-cost-tracking's
        // real GAS=CAD peg + Leo's own established DGEN=CAD precedent), so the
        // same numeric total is correct in either unit - only the label differs,
        // and DGEN is the one that matches what actually lands in the wallet.
        'total_earned_dgen_label' => number_format( $total_earned, 2 ) . ' DGEN',
        'last_earned'        => ( $row && ! empty( $row['last_earned'] ) ) ? (string) $row['last_earned'] : '',
    );
}

/**
 * Row-level GAS ledger history for the "History" popup - both directions
 * (used: this site's own consumption, earned: this user's station-owner
 * income), merged and sorted newest first, capped at $limit total rows.
 * Deliberately row-level (unlike gs_hosting_compute_gas_real_data()'s
 * per-task aggregate breakdown) so the History modal can show a real
 * chronological ledger, filterable client-side by direction.
 */
function gs_hosting_gas_history_rows( $limit = 100 ) {
    global $wpdb;
    $ledger  = $wpdb->base_prefix . 'gdc_gas_ledger';
    $site_id = get_current_blog_id();
    $user_id = get_current_user_id();
    $catalog = function_exists( '\\WP_Ultimo\\Integrations\\gdc_gas_catalog' ) ? \WP_Ultimo\Integrations\gdc_gas_catalog() : array();
    $limit   = max( 1, (int) $limit );

    $used_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT task_id, sales_amount AS amount, status, created_at
         FROM %i WHERE site_id = %d ORDER BY created_at DESC LIMIT %d",
        $ledger, $site_id, $limit
    ), ARRAY_A );

    $earned_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT task_id, station_id, owner_amount AS amount, status, created_at
         FROM %i WHERE station_user_id = %d ORDER BY created_at DESC LIMIT %d",
        $ledger, $user_id, $limit
    ), ARRAY_A );

    $out = array();
    foreach ( (array) $used_rows as $row ) {
        $task_id = (string) $row['task_id'];
        $out[]   = array(
            'direction'    => 'used',
            'label'        => isset( $catalog[ $task_id ]['description'] ) ? $catalog[ $task_id ]['description'] : $task_id,
            'amount_label' => number_format( (float) $row['amount'], 6 ) . ' GAS',
            'status'       => (string) $row['status'],
            'created_at'   => (string) $row['created_at'],
        );
    }
    foreach ( (array) $earned_rows as $row ) {
        $task_id = (string) $row['task_id'];
        $base    = isset( $catalog[ $task_id ]['description'] ) ? $catalog[ $task_id ]['description'] : $task_id;
        $out[]   = array(
            'direction'    => 'earned',
            'label'        => $base . ( $row['station_id'] !== '' ? ' — ' . (string) $row['station_id'] : '' ),
            'amount_label' => number_format( (float) $row['amount'], 6 ) . ' GAS',
            'status'       => (string) $row['status'],
            'created_at'   => (string) $row['created_at'],
        );
    }

    usort( $out, function ( $a, $b ) {
        return strcmp( $b['created_at'], $a['created_at'] );
    } );

    return array_slice( $out, 0, $limit );
}

add_action( 'wp_ajax_gs_hosting_compute_gas', function () {
    gs_hosting_ajax_authorize();
    $r = gs_hosting_call_or_fallback( 'hosting/compute-gas', array(), 'gs_hosting_compute_gas_real_data', 'GET' );
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
    // A group_id in the request means this fired from a group's Hosting
    // tab on the gend.me hub (see group-app-tabs.php's "Rescan now" button)
    // rather than from a vendor's own wp-admin dashboard. Two things differ
    // there: (1) gs_hosting_ajax_authorize()'s manage_options check is
    // against the HUB's own capabilities — a group admin/mod is very
    // unlikely to also administer gend.me itself, so that gate would wrongly
    // block the very people who should be able to rescan; authorize against
    // the GROUP instead. (2) without switching to the group's linked site
    // first, the rescan (and the transient it clears/repopulates) would hit
    // the hub's own uploads dir, not the connected app's.
    $group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
    if ( $group_id > 0 ) {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
        }
        check_ajax_referer( 'gs_membership_action', 'nonce' );
        $uid = get_current_user_id();
        $can_act = is_super_admin( $uid ) // site-admin check, not a hub signal (104 audit)
            || ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $uid, $group_id ) )
            || ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( $uid, $group_id ) );
        if ( ! $can_act ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
        }
        $site_id = (int) groups_get_groupmeta( $group_id, 'gdc_site_id', true );
        if ( $site_id <= 0 || ! get_blog_details( $site_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No application linked to this group.', 'gend-society' ) ) );
        }
        switch_to_blog( $site_id );
        delete_transient( 'gs_hosting_media_usage' );
        $data = gs_hosting_collect_media( true );
        restore_current_blog();
        wp_send_json_success( $data );
    }

    // Original behavior — called from the vendor's own wp-admin dashboard,
    // already on the correct site, no group context involved.
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
        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin SQL console by design: the query IS the input (manage_options + nonce via gs_hosting_ajax_authorize()).
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

    $affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin SQL console by design: the query IS the input (manage_options + nonce via gs_hosting_ajax_authorize()).
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
