<?php
/**
 * Davinci AI — group admin tab giving the web-app owner a single
 * pane of glass over ALL AI spend on their connected web app.
 *
 * Four sections:
 *   1. Leo Web Content Builder — Leo (AIPA) tokens spent on the web app,
 *      the admin's current balance + one-tap top-up, and a roster of member
 *      balances / plans so the owner can manage seats.
 *   2. Agent Costs — every connected AI agent (group members of the
 *      leo_agent / ai_agent type) broken out by what each has spent.
 *   3. API Integration Costs — admin-entered line items for independent AI
 *      integrations / memberships the owner pays for off-platform. Saved to
 *      group_meta `_gs_davinci_api_costs`.
 *   4. Compute Costs — on-chain gas fees for running the web app's AI models
 *      + compute through the gend.me blockchain (reuses the Compute Gas
 *      ledger + the gs_hosting_compute_gas AJAX endpoint).
 *
 * Loaded from gend-society.php inside the bp_include block, right after
 * group-app-tabs.php so the shared helpers (gs_group_tabs_user_has_access,
 * gs_group_get_linked_install_id, gs_compute_gas_resolve_state) are defined.
 *
 * Gated on group-admin OR site-admin — mirrors the other web-app management
 * tabs. Data sources are all real:
 *   - Leo balance:   AIPA_Usage::get_balance( $uid )  (user_meta aipa_credits)
 *   - Leo usage:     {base_prefix}aipa_usage  (site_id,user_id,total_tokens,usd_cost,ts)
 *   - Top-up:        admin-post action `aipa_buy_credits`
 *   - Compute gas:   wp_ajax `gs_hosting_compute_gas`
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ───────────────────────────── helpers ───────────────────────────── */

/**
 * All WP user IDs that belong to the group (admins, mods, members) —
 * including AI-agent members. De-duplicated.
 */
function gend_society_davinci_group_member_ids( $group_id ) {
    $group_id = (int) $group_id;
    $ids = array();
    if ( $group_id && function_exists( 'groups_get_group_members' ) ) {
        $res = groups_get_group_members( array(
            'group_id'            => $group_id,
            'per_page'            => 500,
            'exclude_admins_mods' => false,
        ) );
        if ( ! empty( $res['members'] ) ) {
            foreach ( $res['members'] as $m ) {
                if ( ! empty( $m->user_id ) ) $ids[ (int) $m->user_id ] = (int) $m->user_id;
            }
        }
    }
    return array_values( $ids );
}

/**
 * Is this WP user an AI agent? Robust across both the hub Leo agent model
 * (role leo_agent / meta leo_is_agent / BP member type leo_agent) and the
 * container agent provisioning model (role ai_agent / meta _aipa_is_agent /
 * _aipa_agent_slug).
 */
function gend_society_davinci_user_is_agent( $user_id ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) return false;

    foreach ( array( 'leo_is_agent', '_aipa_is_agent' ) as $mk ) {
        if ( get_user_meta( $user_id, $mk, true ) ) return true;
    }
    if ( get_user_meta( $user_id, '_aipa_agent_slug', true ) ) return true;

    $u = get_userdata( $user_id );
    if ( $u && ! empty( $u->roles ) ) {
        foreach ( array( 'leo_agent', 'ai_agent' ) as $r ) {
            if ( in_array( $r, (array) $u->roles, true ) ) return true;
        }
    }
    if ( function_exists( 'bp_get_member_type' ) ) {
        $types = (array) bp_get_member_type( $user_id, false );
        if ( in_array( 'leo_agent', $types, true ) || in_array( 'ai_agent', $types, true ) ) return true;
    }
    return false;
}

/**
 * Aggregate Leo (AIPA) usage for a set of users since $since (DATETIME).
 * Returns map keyed by user_id => array( tokens, usd, calls ). Empty when
 * the usage table or AIPA_Usage helper isn't present.
 */
function gend_society_davinci_usage_map( array $user_ids, $since ) {
    $out = array();
    $user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
    if ( empty( $user_ids ) || ! class_exists( 'AIPA_Usage' ) ) return $out;

    global $wpdb;
    $table = AIPA_Usage::table_name();
    // Defensive: bail if the table doesn't exist on this install.
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return $out;

    $in  = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
    $sql = "SELECT user_id, COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(usd_cost),0) AS usd, COUNT(*) AS calls
            FROM {$table}
            WHERE user_id IN ($in) AND ts >= %s
            GROUP BY user_id";
    $params = array_merge( $user_ids, array( $since ) );
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
    foreach ( (array) $rows as $r ) {
        $out[ (int) $r->user_id ] = array(
            'tokens' => (int) $r->tokens,
            'usd'    => (float) $r->usd,
            'calls'  => (int) $r->calls,
        );
    }
    return $out;
}

/** Current Leo token-currency symbol/label (defaults USD). */
function gend_society_davinci_currency() {
    if ( class_exists( 'AIPA_Usage' ) && method_exists( 'AIPA_Usage', 'get_token_pricing_settings' ) ) {
        $s = AIPA_Usage::get_token_pricing_settings();
        return isset( $s['currency'] ) ? (string) $s['currency'] : 'USD';
    }
    return 'USD';
}

/** Format a USD amount as a money string. */
function gend_society_davinci_money( $amount ) {
    return '$' . number_format( (float) $amount, 2 );
}

/** Sanitize + normalize the stored API-integration cost rows. */
function gend_society_davinci_sanitize_api_costs( $raw ) {
    $out = array();
    if ( ! is_array( $raw ) ) return $out;
    foreach ( $raw as $row ) {
        if ( ! is_array( $row ) ) continue;
        $label   = isset( $row['label'] )   ? sanitize_text_field( (string) $row['label'] )   : '';
        $vendor  = isset( $row['vendor'] )  ? sanitize_text_field( (string) $row['vendor'] )  : '';
        $amount  = isset( $row['amount'] )  ? round( (float) $row['amount'], 2 )              : 0.0;
        $cadence = isset( $row['cadence'] ) && in_array( $row['cadence'], array( 'monthly', 'yearly', 'one-time' ), true )
            ? $row['cadence'] : 'monthly';
        $note    = isset( $row['note'] )    ? sanitize_text_field( (string) $row['note'] )    : '';
        if ( $label === '' && $amount <= 0 ) continue;
        $out[] = compact( 'label', 'vendor', 'amount', 'cadence', 'note' );
    }
    return $out;
}

/** Monthly-equivalent total of the API integration costs (yearly /12, one-time excluded). */
function gend_society_davinci_api_costs_monthly( array $rows ) {
    $total = 0.0;
    foreach ( $rows as $r ) {
        $amt = (float) ( $r['amount'] ?? 0 );
        switch ( $r['cadence'] ?? 'monthly' ) {
            case 'yearly':  $total += $amt / 12; break;
            case 'monthly': $total += $amt;      break;
            // one-time excluded from the recurring monthly figure
        }
    }
    return $total;
}

/* ───────────────────── AJAX: save API integration costs ───────────────────── */

add_action( 'wp_ajax_gend_society_davinci_save_api_costs', 'gend_society_davinci_save_api_costs_ajax' );
function gend_society_davinci_save_api_costs_ajax() {
    if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'auth' ), 403 );
    check_ajax_referer( 'gs_davinci_api_costs', 'nonce' );

    $group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
    if ( ! $group_id ) wp_send_json_error( array( 'message' => 'no_group' ), 400 );

    // Authorize: site admin OR admin of THIS group.
    $allowed = current_user_can( 'manage_options' )
        || ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( get_current_user_id(), $group_id ) )
        || ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( get_current_user_id(), $group_id ) );
    if ( ! $allowed ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );

    $raw = isset( $_POST['rows'] ) ? json_decode( wp_unslash( $_POST['rows'] ), true ) : array();
    $clean = gend_society_davinci_sanitize_api_costs( $raw );
    groups_update_groupmeta( $group_id, '_gend_society_davinci_api_costs', $clean );

    wp_send_json_success( array(
        'rows'          => $clean,
        'monthly_label' => gend_society_davinci_money( gend_society_davinci_api_costs_monthly( $clean ) ),
    ) );
}

/* ───────────────────────────── the tab ───────────────────────────── */

if ( class_exists( 'BP_Group_Extension' ) ) :

    /**
     * Davinci AI — AI-spend command centre for the web app owner.
     */
    class Gend_Society_Group_Tab_Davinci_AI extends BP_Group_Extension {
        public function __construct() {
            parent::init( array(
                'slug'              => 'davinci-ai',
                'name'              => __( 'Davinci AI', 'gend-society' ),
                // Sits at the very end of the group nav — after Compute Gas (80).
                'nav_item_position' => 200,
                'show_tab'          => 'anyone',
                'nav_item_name'     => __( 'Davinci AI', 'gend-society' ),
                'display_hook'      => 'groups_custom_group_boxes',
                'template_file'     => 'groups/single/plugins',
            ) );
        }
        /**
         * Phase 100-05: routes through the Group Page resolution system
         * (gdc_gs_render_endpoint()) so davinci-ai's content becomes
         * editable via Group Pages, same as the 6 original projects-owned
         * endpoints. Falls back to render_legacy_content() directly if the
         * projects plugin is somehow inactive (graceful degrade, matches
         * every other endpoint's convention).
         */
        public function display( $group_id = null ) {
            $group_id = $group_id ?: bp_get_current_group_id();
            if ( function_exists( 'gdc_gs_render_endpoint' ) ) {
                echo gdc_gs_render_endpoint( $group_id, 'davinci-ai' ); // phpcs:ignore WordPress.Security.EscapeOutput -- pre-rendered, resolved block content
            } else {
                self::render_legacy_content( $group_id );
            }
        }

        /**
         * The original display() body, extracted verbatim (pure move, not
         * a duplicate) so both the self-registered Group Page callback
         * below AND display()'s own fallback share ONE implementation.
         * MUST NEVER re-invoke display() (directly or via self::/static::)
         * -- doing so would recreate the display-calls-resolver-calls-
         * callback-calls-display infinite-recursion cycle Plan 97-02 fixed
         * for business-plan.
         */
        public static function render_legacy_content( $group_id ) {
            if ( ! function_exists( 'gend_society_group_tabs_user_has_access' ) || ! gend_society_group_tabs_user_has_access() ) return;
            gend_society_group_render_davinci_ai_suite( (int) $group_id );
        }
    }

    // Register on bp_init (BP nav must exist). Separate from the
    // group-app-tabs.php registration so this file stays self-contained.
    add_action( 'bp_init', 'gend_society_register_davinci_ai_tab', 10 );
    function gend_society_register_davinci_ai_tab() {
        if ( ! function_exists( 'bp_register_group_extension' ) ) return;
        bp_register_group_extension( 'Gend_Society_Group_Tab_Davinci_AI' );
    }

    // Show the tab in the native BP nav only for group/site admins.
    add_filter( 'bp_group_extension_nav_show_for_user', 'gend_society_davinci_ai_nav_visibility', 99, 3 );
    function gend_society_davinci_ai_nav_visibility( $show, $slug, $group_id ) {
        if ( $slug !== 'davinci-ai' ) return $show;
        return function_exists( 'gend_society_group_tabs_user_has_access' ) ? gend_society_group_tabs_user_has_access() : $show;
    }

endif; // BP_Group_Extension

add_filter( 'gdc_gs_endpoint_content_callbacks', function ( $callbacks ) {
    $callbacks['davinci-ai'] = array( 'Gend_Society_Group_Tab_Davinci_AI', 'render_legacy_content' );
    return $callbacks;
} );

add_filter( 'gdc_gs_endpoints', function ( $endpoints ) {
    $endpoints['davinci-ai'] = __( 'Davinci AI', 'gend-society' );
    return $endpoints;
} );

/**
 * Phase 100-05: gdc_gs_bootstrap_defaults() (projects plugin, init@20)
 * already loops gdc_gs_endpoints() generically and will create an EMPTY
 * default gdc_group_page for 'davinci-ai' the moment the filter above is
 * active -- this fills that empty page with the real endpoint-panel block
 * so the tab is never blank post-deploy. Idempotent: only writes when the
 * resolved default page's content is still empty, never clobbers real
 * content an admin has since edited. Mirrors
 * gdc_gs_seed_hosting_and_members_hub_defaults() in
 * projects/includes/group-members-screen.php exactly.
 */
add_action( 'init', 'gend_society_gs_davinci_ai_seed_default_page', 30 );
function gend_society_gs_davinci_ai_seed_default_page() {
    if ( ! function_exists( 'gdc_gs_get_site_default_page_id' ) ) return;
    $post_id = gdc_gs_get_site_default_page_id( 'davinci-ai' );
    if ( ! $post_id ) return; // Bootstrap hasn't created it yet -- self-heals next load.
    $post = get_post( $post_id );
    if ( ! $post || trim( (string) $post->post_content ) !== '' ) return; // Already seeded/has real content -- never clobber.
    wp_update_post( array(
        'ID'           => $post_id,
        'post_content' => '<!-- wp:gdc-blocks/endpoint-panel {"slug":"davinci-ai"} /-->',
    ) );
}

/* ──────────────────────────── renderer ──────────────────────────── */

function gend_society_group_render_davinci_ai_suite( $group_id ) {
    $group_id = (int) $group_id;
    $uid      = get_current_user_id();
    $currency = gend_society_davinci_currency();

    // ── Gather membership + usage ──────────────────────────────────
    $member_ids = gend_society_davinci_group_member_ids( $group_id );
    $agent_ids  = array();
    $human_ids  = array();
    foreach ( $member_ids as $mid ) {
        if ( gend_society_davinci_user_is_agent( $mid ) ) $agent_ids[] = $mid; else $human_ids[] = $mid;
    }
    $since   = current_time( 'Y-m-01 00:00:00' ); // start of this calendar month
    $usage   = gend_society_davinci_usage_map( $member_ids, $since );

    $leo_usd = 0.0; $leo_tokens = 0;
    foreach ( $human_ids as $hid ) { if ( isset( $usage[ $hid ] ) ) { $leo_usd += $usage[ $hid ]['usd']; $leo_tokens += $usage[ $hid ]['tokens']; } }
    $agent_usd = 0.0; $agent_tokens = 0;
    foreach ( $agent_ids as $aid ) { if ( isset( $usage[ $aid ] ) ) { $agent_usd += $usage[ $aid ]['usd']; $agent_tokens += $usage[ $aid ]['tokens']; } }

    // ── API integration costs (owner-entered) ─────────────────────
    $api_costs   = function_exists( 'groups_get_groupmeta' ) ? gend_society_davinci_sanitize_api_costs( groups_get_groupmeta( $group_id, '_gend_society_davinci_api_costs', true ) ) : array();
    $api_monthly = gend_society_davinci_api_costs_monthly( $api_costs );

    // ── Compute gas gate (reuses the Compute Gas resolver) ─────────
    $cg_state = function_exists( 'gend_society_compute_gas_resolve_state' ) ? gend_society_compute_gas_resolve_state( $group_id, $uid ) : array();
    $compute_active = ! empty( $cg_state['compute_active'] );
    $compute_plan_url = isset( $cg_state['compute_plan_url'] ) ? (string) $cg_state['compute_plan_url'] : '';

    // ── My Leo balance + top-up plumbing ──────────────────────────
    $my_balance = class_exists( 'AIPA_Usage' ) ? (float) AIPA_Usage::get_balance( $uid ) : 0.0;

    $ajax_url   = admin_url( 'admin-ajax.php' );
    $post_url   = admin_url( 'admin-post.php' );
    $cg_nonce   = wp_create_nonce( 'gs_membership_action' );        // existing compute-gas AJAX nonce
    $api_nonce  = wp_create_nonce( 'gs_davinci_api_costs' );
    $scope      = 'gs-dv-' . $group_id;

    // Running known (server-side) monthly spend — compute gas is added client-side after fetch.
    $known_monthly = $leo_usd + $agent_usd + $api_monthly;
    ?>
    <style>
        [data-gs-dv-scope] {
            --dv-blue:var(--gdc-group-accent,#6ec1e4); --dv-magenta:#b608c9; --dv-green:#00ff88; --dv-gold:#ffcc00;
            --dv-glass-bg:rgba(15,18,24,0.45); --dv-glass-border:rgba(255,255,255,0.08);
            --dv-ease:cubic-bezier(0.16,1,0.3,1);
            font-family:'Inter',system-ui,sans-serif; color:#fff; max-width:1250px; margin:0 auto; padding:20px; box-sizing:border-box;
        }
        [data-gs-dv-scope] * { box-sizing:border-box; }
        [data-gs-dv-scope] h2, [data-gs-dv-scope] h3, [data-gs-dv-scope] h4 { font-family:inherit !important; color:#fff !important; }

        [data-gs-dv-scope] .gs-dv-hero { display:flex; justify-content:space-between; align-items:flex-end; gap:24px; flex-wrap:wrap; margin-bottom:28px; }
        [data-gs-dv-scope] .gs-dv-hero h2 { font-size:2.1rem !important; font-weight:950 !important; text-transform:uppercase; letter-spacing:-1px; margin:0 0 6px !important; }
        [data-gs-dv-scope] .gs-dv-hero p { margin:0; font-size:0.92rem; opacity:0.55; max-width:640px; }
        [data-gs-dv-scope] .gs-dv-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(110,193,228,0.10); border:1px solid rgba(110,193,228,0.25); color:var(--dv-blue); padding:6px 14px; border-radius:8px; font-size:0.62rem; font-weight:900; text-transform:uppercase; letter-spacing:2px; margin-bottom:14px; }

        [data-gs-dv-scope] .gs-dv-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:30px; }
        [data-gs-dv-scope] .gs-dv-sumcard { background:var(--dv-glass-bg); border:1px solid var(--dv-glass-border); border-radius:18px; padding:20px 22px; position:relative; overflow:hidden; }
        [data-gs-dv-scope] .gs-dv-sumcard::before { content:""; position:absolute; top:0; left:0; right:0; height:3px; background:var(--accent,var(--dv-blue)); opacity:.85; }
        [data-gs-dv-scope] .gs-dv-sumcard.is-total { border-color:rgba(0,255,136,0.30); background:linear-gradient(135deg,rgba(0,255,136,0.05),rgba(0,0,0,0.20)); }
        [data-gs-dv-scope] .gs-dv-sum-label { font-size:0.62rem; font-weight:900; text-transform:uppercase; letter-spacing:1.4px; opacity:0.5; margin:0 0 8px; }
        [data-gs-dv-scope] .gs-dv-sum-value { font-size:1.7rem; font-weight:950; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; letter-spacing:-0.5px; line-height:1; }
        [data-gs-dv-scope] .gs-dv-sumcard.is-total .gs-dv-sum-value { color:var(--dv-green); text-shadow:0 0 20px rgba(0,255,136,0.2); }
        [data-gs-dv-scope] .gs-dv-sum-foot { font-size:0.68rem; opacity:0.4; margin:8px 0 0; }

        [data-gs-dv-scope] .gs-dv-panel { background:var(--dv-glass-bg); border:1px solid var(--dv-glass-border); border-radius:26px; padding:34px; margin-bottom:24px; position:relative; }
        [data-gs-dv-scope] .gs-dv-panel-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:22px; }
        [data-gs-dv-scope] .gs-dv-panel-head h3 { font-size:1.35rem !important; font-weight:900 !important; text-transform:uppercase; letter-spacing:-0.5px; margin:0 0 5px !important; }
        [data-gs-dv-scope] .gs-dv-panel-head .lede { font-size:0.85rem; opacity:0.5; margin:0; max-width:680px; }
        [data-gs-dv-scope] .gs-dv-eyebrow { display:inline-flex; align-items:center; gap:7px; font-size:0.6rem; font-weight:900; text-transform:uppercase; letter-spacing:1.8px; color:var(--accent,var(--dv-blue)); margin:0 0 10px; }
        [data-gs-dv-scope] .gs-dv-eyebrow svg { width:14px; height:14px; }

        [data-gs-dv-scope] .gs-dv-stat-row { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:22px; }
        [data-gs-dv-scope] .gs-dv-stat { background:rgba(0,0,0,0.22); border:1px solid var(--dv-glass-border); border-radius:16px; padding:18px 20px; }
        [data-gs-dv-scope] .gs-dv-stat .k { font-size:0.6rem; font-weight:900; text-transform:uppercase; letter-spacing:1.3px; opacity:0.45; margin:0 0 8px; }
        [data-gs-dv-scope] .gs-dv-stat .v { font-size:1.5rem; font-weight:950; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; line-height:1; }

        /* gs-dv-table (Leo usage / Agents / API editor) — `html body`
           prefix + !important on background/color/border so Youzify's global
           table styling (red thead, white rows) can never take these over. */
        html body [data-gs-dv-scope] table.gs-dv-table {
            width:100% !important; border-collapse:collapse !important;
            font-size:0.85rem !important; background:transparent !important;
            border:0 !important; margin:0 !important; color:#e8edf5 !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table thead,
        html body [data-gs-dv-scope] table.gs-dv-table thead tr {
            background:rgba(15,23,42,.9) !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table th {
            text-align:left !important; font-size:0.62rem !important; text-transform:uppercase;
            letter-spacing:1.1px; font-weight:800 !important; padding:10px 12px !important;
            background:rgba(15,23,42,.9) !important; color:#7dd3fc !important;
            border:0 !important; border-bottom:1px solid var(--dv-glass-border) !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table tbody tr,
        html body [data-gs-dv-scope] table.gs-dv-table tr {
            background:transparent !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table tbody tr:nth-child(even) {
            background:rgba(125,211,252,.04) !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table tbody tr:hover {
            background:rgba(34,211,238,.06) !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table td {
            padding:12px !important; vertical-align:middle;
            background:transparent !important; color:#e8edf5 !important;
            border:0 !important; border-bottom:1px solid rgba(255,255,255,0.05) !important;
        }
        html body [data-gs-dv-scope] table.gs-dv-table tr:last-child td { border-bottom:0 !important; }
        html body [data-gs-dv-scope] table.gs-dv-table td.num,
        html body [data-gs-dv-scope] table.gs-dv-table th.num { text-align:right !important; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
        [data-gs-dv-scope] .gs-dv-who { display:flex; align-items:center; gap:10px; }
        [data-gs-dv-scope] .gs-dv-who img { width:30px; height:30px; border-radius:50%; }
        [data-gs-dv-scope] .gs-dv-pill { display:inline-block; padding:3px 9px; border-radius:100px; font-size:0.62rem; font-weight:800; letter-spacing:.4px; background:rgba(110,193,228,0.14); color:var(--dv-blue); border:1px solid rgba(110,193,228,0.30); }
        [data-gs-dv-scope] .gs-dv-pill.is-agent { background:rgba(182,8,201,0.14); color:#e478f5; border-color:rgba(182,8,201,0.32); }

        [data-gs-dv-scope] .gs-dv-cta, [data-gs-dv-scope] .gs-dv-btn {
            display:inline-flex; align-items:center; justify-content:center; gap:7px; cursor:pointer;
            padding:11px 18px; border-radius:11px; font-size:0.78rem; font-weight:900; letter-spacing:.5px; text-transform:uppercase;
            text-decoration:none !important; border:0; transition:transform .18s var(--dv-ease), filter .18s var(--dv-ease);
        }
        [data-gs-dv-scope] .gs-dv-cta { background:linear-gradient(135deg,var(--dv-blue),var(--dv-magenta)); color:#0a0e1c !important; }
        [data-gs-dv-scope] .gs-dv-cta:hover { transform:translateY(-1px); filter:brightness(1.1); }
        [data-gs-dv-scope] .gs-dv-btn { background:rgba(255,255,255,0.05); color:#fff !important; border:1px solid var(--dv-glass-border); }
        [data-gs-dv-scope] .gs-dv-btn:hover { background:#fff; color:#000 !important; }
        [data-gs-dv-scope] .gs-dv-btn--sm, [data-gs-dv-scope] .gs-dv-cta--sm { padding:7px 12px; font-size:0.68rem; }

        [data-gs-dv-scope] .gs-dv-topup { display:flex; align-items:stretch; gap:10px; flex-wrap:wrap; margin-top:6px; }
        [data-gs-dv-scope] .gs-dv-topup input[type=number] { width:130px; padding:11px 14px; background:rgba(11,14,20,0.78); border:1px solid rgba(255,255,255,0.12); color:#fff; border-radius:11px; font-size:0.95rem; font-family:ui-monospace,monospace; outline:none; }
        [data-gs-dv-scope] .gs-dv-topup input[type=number]:focus { border-color:rgba(110,193,228,0.55); }

        html body [data-gs-dv-scope] input.gs-dv-in,
        html body [data-gs-dv-scope] select.gs-dv-in {
            width:100% !important; padding:9px 11px !important;
            background:rgba(11,14,20,0.78) !important;
            border:1px solid rgba(255,255,255,0.12) !important;
            color:#fff !important; border-radius:9px !important;
            font-size:0.82rem !important; outline:none; font-family:inherit !important;
            box-shadow:none !important; height:auto !important; line-height:1.4 !important;
        }
        html body [data-gs-dv-scope] select.gs-dv-in option { background:#0b1120 !important; color:#e2e8f0 !important; }
        html body [data-gs-dv-scope] input.gs-dv-in:focus,
        html body [data-gs-dv-scope] select.gs-dv-in:focus { border-color:rgba(110,193,228,0.55) !important; }
        [data-gs-dv-scope] .gs-dv-rm { background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.35); color:#fca5a5; border-radius:8px; cursor:pointer; padding:7px 10px; font-weight:800; }
        [data-gs-dv-scope] .gs-dv-rm:hover { background:rgba(248,113,113,0.25); }
        [data-gs-dv-scope] .gs-dv-save-bar { display:flex; align-items:center; gap:14px; margin-top:18px; flex-wrap:wrap; }
        [data-gs-dv-scope] .gs-dv-save-status { font-size:0.78rem; opacity:0.6; }
        [data-gs-dv-scope] .gs-dv-save-status.is-ok { color:var(--dv-green); opacity:1; }
        [data-gs-dv-scope] .gs-dv-empty { font-size:0.85rem; line-height:1.6; color:rgba(255,255,255,0.55); margin:0; }

        @media (max-width:980px){
            [data-gs-dv-scope] .gs-dv-summary { grid-template-columns:repeat(2,1fr); }
            [data-gs-dv-scope] .gs-dv-stat-row { grid-template-columns:1fr; }
            [data-gs-dv-scope] .gs-dv-panel { padding:24px; }
        }
    </style>

    <div data-gs-dv-scope id="<?php echo esc_attr( $scope ); ?>"
         data-group-id="<?php echo esc_attr( $group_id ); ?>"
         data-known-monthly="<?php echo esc_attr( $known_monthly ); ?>">

        <?php
        /* v12.1 — Top-level 3-tab bar on the Davinci AI menu page per operator
           directive. Tabs: Leo Tokens (default, wraps existing AI-spend command
           center content), Brain (deep-links to /business-plan/brain/),
           Wireframe (deep-links to /business-plan/wireframe/). The Brain +
           Wireframe nav buttons were removed from the Business Plan tab in the
           same commit; their panels stay renderable on the BP page so the
           direct sub-URLs still resolve. */
        $gs_bp_base = function_exists( 'bp_get_group_permalink' ) && function_exists( 'groups_get_group' )
            ? trailingslashit( (string) bp_get_group_permalink( groups_get_group( $group_id ) ) ) . 'business-plan/'
            : '';
        ?>
        <style>
            [data-gs-dv-scope] .gs-dv-topnav {
                display: flex; flex-wrap: wrap; gap: 8px;
                padding: 8px;
                background: linear-gradient(180deg, rgba(15,23,42,.65), rgba(15,23,42,.42));
                border: 1px solid rgba(125, 211, 252, .18);
                border-radius: 16px;
                margin: 0 0 22px;
                -webkit-backdrop-filter: blur(14px) saturate(150%);
                        backdrop-filter: blur(14px) saturate(150%);
                box-shadow: 0 22px 48px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.05);
            }
            [data-gs-dv-scope] .gs-dv-toptab {
                display: inline-flex; align-items: center; gap: 10px;
                padding: 11px 20px;
                background: transparent;
                color: rgba(203, 213, 225, .78);
                border: 1px solid transparent;
                border-radius: 12px;
                font-family: Inter, system-ui, sans-serif;
                font-weight: 700;
                font-size: .82rem;
                letter-spacing: .04em;
                text-transform: uppercase;
                cursor: pointer;
                text-decoration: none;
                min-height: 44px;
                transition: color .22s ease, background .22s ease, border-color .22s ease, box-shadow .22s ease, transform .14s ease;
            }
            [data-gs-dv-scope] .gs-dv-toptab:hover {
                color: #f1f5f9;
                background: rgba(34,211,238,.08);
                border-color: rgba(34,211,238,.20);
            }
            [data-gs-dv-scope] .gs-dv-toptab.is-active {
                color: #0b0e14;
                background: linear-gradient(135deg, #22d3ee, #7dd3fc);
                border-color: rgba(34,211,238,.55);
                box-shadow: 0 8px 24px rgba(34,211,238,.35), inset 0 1px 0 rgba(255,255,255,.35);
                transform: translateY(-1px);
            }
            [data-gs-dv-scope] .gs-dv-toptab-icon {
                width: 20px; height: 20px;
                display: inline-flex; align-items: center; justify-content: center;
            }
        </style>

        <?php /* Top tabs removed (operator directive 2026-08-25):
                 the Brain hub below replaced them — Leo Tokens is the only
                 page view now. */ ?>

        <script>
        /* Davinci top-nav AJAX loader — shared by the Davinci Architect page
           (gend-society) and the BP page's deep-linked Brain/Wireframe panels
           (projects). Tab clicks fetch the target page and graft its
           #item-body in place (inline panel scripts re-executed, missing
           stylesheets pulled in), so switching tabs never reloads the whole
           page. Guarded so re-grafts can't double-bind; any failure falls
           back to a normal navigation. */
        (function () {
            if (window.__gsDvAjaxNav) { return; }
            window.__gsDvAjaxNav = true;
            var SEL = '.gs-dv-toptab[href], .psoo-bp__dvtab[href]';
            var busy = false, didGraft = false;
            function itemBody() { return document.querySelector('#item-body'); }
            function absU(u) { try { return new URL(u, location.href).href; } catch (e) { return u; } }
            function load(url, push) {
                var body = itemBody();
                if (!body || busy) { location.href = url; return; }
                busy = true;
                body.style.transition = 'opacity .2s ease';
                body.style.opacity = '.35';
                var ctrl = ('AbortController' in window) ? new AbortController() : null;
                var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 20000) : null;
                fetch(url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
                    .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
                    .then(function (htmlText) {
                        if (timer) { clearTimeout(timer); }
                        var doc = new DOMParser().parseFromString(htmlText, 'text/html');
                        var next = doc.querySelector('#item-body');
                        if (!next) { location.href = url; return; }
                        // Stylesheets the target page has that we don't yet.
                        var haveCss = {};
                        document.querySelectorAll('link[rel="stylesheet"][href]').forEach(function (l) { haveCss[l.href] = 1; });
                        doc.querySelectorAll('link[rel="stylesheet"][href]').forEach(function (l) {
                            if (!haveCss[l.href]) {
                                var n = document.createElement('link');
                                n.rel = 'stylesheet'; n.href = l.href;
                                document.head.appendChild(n);
                            }
                        });
                        var haveJs = {};
                        document.querySelectorAll('script[src]').forEach(function (s) { haveJs[s.src] = 1; });
                        body.innerHTML = next.innerHTML;
                        // innerHTML-inserted scripts are inert — swap each for a
                        // live node so the panel's inline bootstraps run.
                        body.querySelectorAll('script').forEach(function (s) {
                            if (s.src && haveJs[s.src]) { s.remove(); return; }
                            var n = document.createElement('script');
                            if (s.src) { n.src = s.src; } else { n.textContent = s.textContent; }
                            s.parentNode.replaceChild(n, s);
                        });
                        if (doc.title) { document.title = doc.title; }
                        if (push) { try { history.pushState({ gsDv: 1 }, '', url); } catch (e) {} }
                        didGraft = true;
                        body.style.opacity = '1';
                        var y = body.getBoundingClientRect().top + window.pageYOffset - 90;
                        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
                        busy = false;
                    })
                    .catch(function () {
                        if (timer) { clearTimeout(timer); }
                        location.href = url; // graceful degrade — real navigation
                    });
            }
            document.addEventListener('click', function (e) {
                var a = e.target && e.target.closest && e.target.closest(SEL);
                if (!a) { return; }
                var href = a.getAttribute('href');
                if (!href || href.charAt(0) === '#') { return; }
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target === '_blank') { return; }
                e.preventDefault();
                load(absU(href), true);
            }, true);
            window.addEventListener('popstate', function () {
                var u = location.href;
                if (/\/(davinci-ai|business-plan\/(brain|wireframe))\/?([?#]|$)/.test(u)) { load(u, false); }
                else if (didGraft) { location.reload(); }
            });
        })();
        </script>

        <?php
        /* ───────── THE BRAIN — 4-quadrant animated hub ─────────
           The Business-Brain cinematic plate (same video the BP brain panel
           uses) with four 3D-hover glass quadrants: Branding / Plan /
           Wireframe / Growth. Clicking one hides the Leo content and grafts
           that section of the Business Plan page in place (section.psoo-bp
           extracted, inline scripts re-executed, missing assets pulled in)
           with a Back button restoring the hub. Wireframe includes the BP
           panel's generate-first-wireframe flow when none exists yet. */
        $gs_dv_brain_poster = function_exists( 'gend_society_remote_asset_url' ) ? gend_society_remote_asset_url( 'business_brain' ) : '';
        $gs_dv_brain_video  = function_exists( 'gend_society_remote_asset_url' ) ? gend_society_remote_asset_url( 'business_brain_video' ) : '';
        ?>
        <div class="gs-dv-brainhub" data-dv-brainhub
             data-rest="<?php echo esc_url( rest_url( 'psoo/v1/business-plan/brain-files' ) ); ?>"
             data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
             data-gid="<?php echo (int) $group_id; ?>">
            <div class="gs-dv-brainhub-bg" aria-hidden="true">
                <?php if ( '' !== $gs_dv_brain_video ) : ?>
                <video autoplay loop muted playsinline preload="auto"
                       poster="<?php echo esc_url( $gs_dv_brain_poster ); ?>">
                    <source src="<?php echo esc_url( $gs_dv_brain_video ); ?>" type="video/mp4">
                </video>
                <?php else : ?>
                <div style="position:absolute;inset:0;<?php echo esc_attr( function_exists( 'gend_society_remote_asset_placeholder_css' ) ? gend_society_remote_asset_placeholder_css() : '' ); ?>"></div>
                <?php endif; ?>
                <div class="gs-dv-brainhub-veil"></div>
            </div>
            <div class="gs-dv-brainhub-head">
                <span class="gs-dv-badge">🧠 <?php esc_html_e( 'Business Brain', 'gend-society' ); ?></span>
                <h2><?php esc_html_e( 'The Brain', 'gend-society' ); ?></h2>
                <p><?php esc_html_e( 'Everything Davinci knows about this business — pick a lobe to dive in.', 'gend-society' ); ?></p>
            </div>
            <div class="gs-dv-brainhub-grid">
                <button type="button" class="gs-dv-brainlobe" data-dv-brain-sec="branding"
                        data-dv-brain-url="<?php echo esc_url( $gs_bp_base . 'brain/' ); ?>">
                    <span class="gs-dv-brainlobe-ico">🎨</span>
                    <strong><?php esc_html_e( 'Branding', 'gend-society' ); ?></strong>
                    <span class="gs-dv-brainlobe-sub"><?php esc_html_e( 'Brand DNA + the living knowledge graph', 'gend-society' ); ?></span>
                </button>
                <button type="button" class="gs-dv-brainlobe" data-dv-brain-sec="plan"
                        data-dv-brain-url="<?php echo esc_url( $gs_bp_base ); ?>">
                    <span class="gs-dv-brainlobe-ico">📘</span>
                    <strong><?php esc_html_e( 'Plan', 'gend-society' ); ?></strong>
                    <span class="gs-dv-brainlobe-sub"><?php esc_html_e( 'Every business plan chapter, live', 'gend-society' ); ?></span>
                </button>
                <button type="button" class="gs-dv-brainlobe" data-dv-brain-sec="wireframe"
                        data-dv-brain-url="<?php echo esc_url( $gs_bp_base . 'wireframe/' ); ?>">
                    <span class="gs-dv-brainlobe-ico">📐</span>
                    <strong><?php esc_html_e( 'Wireframe', 'gend-society' ); ?></strong>
                    <span class="gs-dv-brainlobe-sub"><?php esc_html_e( 'The live app wireframe — or generate the first one', 'gend-society' ); ?></span>
                </button>
                <button type="button" class="gs-dv-brainlobe" data-dv-brain-sec="growth"
                        data-dv-brain-url="<?php echo esc_url( $gs_bp_base ); ?>">
                    <span class="gs-dv-brainlobe-ico">📈</span>
                    <strong><?php esc_html_e( 'Growth', 'gend-society' ); ?></strong>
                    <span class="gs-dv-brainlobe-sub"><?php esc_html_e( 'Growth strategy, marketing + analytics', 'gend-society' ); ?></span>
                </button>
            </div>
        </div>

        <div class="gs-dv-brainsec" data-dv-brainsec hidden>
            <div class="gs-dv-brainsec-bar">
                <button type="button" class="gs-dv-btn gs-dv-btn--sm" data-dv-brain-back>← <?php esc_html_e( 'Back to the Brain', 'gend-society' ); ?></button>
                <h3 data-dv-brainsec-title></h3>
            </div>
            <div class="gs-dv-brainsec-body" data-dv-brainsec-body></div>
        </div>

        <style>
            @property --gsdvbrang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
            [data-gs-dv-scope] .gs-dv-brainhub {
                position: relative; overflow: hidden;
                border-radius: 24px; margin: 0 0 26px; padding: 34px 28px 30px;
                border: 1px solid rgba(125,211,252,.2);
                box-shadow: 0 30px 70px rgba(0,0,0,.45);
                min-height: 380px;
            }
            [data-gs-dv-scope] .gs-dv-brainhub-bg { position: absolute; inset: 0; }
            [data-gs-dv-scope] .gs-dv-brainhub-bg video {
                width: 100%; height: 100%; object-fit: cover;
                filter: saturate(1.15);
                animation: gsDvBrainDrift 26s ease-in-out infinite alternate;
            }
            @keyframes gsDvBrainDrift {
                0%   { transform: scale(1.02) translate(0, 0); }
                100% { transform: scale(1.1) translate(-1.5%, -2%); }
            }
            [data-gs-dv-scope] .gs-dv-brainhub-veil {
                position: absolute; inset: 0;
                background: linear-gradient(180deg, rgba(2,6,23,.72), rgba(2,6,23,.45) 45%, rgba(2,6,23,.78));
                backdrop-filter: blur(2px);
            }
            [data-gs-dv-scope] .gs-dv-brainhub-head { position: relative; text-align: center; margin-bottom: 20px; }
            [data-gs-dv-scope] .gs-dv-brainhub-head h2 {
                margin: 10px 0 6px; color: #f8fafc; font-size: 1.8rem; font-weight: 800; letter-spacing: -.02em;
            }
            [data-gs-dv-scope] .gs-dv-brainhub-head p { margin: 0; color: rgba(226,232,240,.85); }
            [data-gs-dv-scope] .gs-dv-brainhub-grid {
                position: relative;
                display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 14px; perspective: 900px;
            }
            [data-gs-dv-scope] .gs-dv-brainlobe {
                position: relative; cursor: pointer;
                display: flex; flex-direction: column; align-items: center; gap: 6px;
                padding: 22px 18px;
                background: linear-gradient(160deg, rgba(15,23,42,.72), rgba(12,16,44,.5));
                color: #e2e8f0; border: 1px solid rgba(125,211,252,.22);
                border-radius: 18px; text-align: center;
                -webkit-backdrop-filter: blur(12px) saturate(150%);
                        backdrop-filter: blur(12px) saturate(150%);
                transform-style: preserve-3d; will-change: transform;
                transition: transform .18s ease, box-shadow .3s ease;
            }
            [data-gs-dv-scope] .gs-dv-brainlobe::before {
                content: ''; position: absolute; inset: -1px; border-radius: 19px;
                padding: 1.5px; pointer-events: none; opacity: 0;
                background: conic-gradient(from var(--gsdvbrang, 0deg), #22d3ee, #b608c9, #7dd3fc, #22d3ee);
                -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                -webkit-mask-composite: xor;
                        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                        mask-composite: exclude;
                transition: opacity .3s ease;
            }
            [data-gs-dv-scope] .gs-dv-brainlobe:hover {
                box-shadow: 0 22px 48px rgba(0,0,0,.5), 0 0 26px rgba(34,211,238,.24);
            }
            [data-gs-dv-scope] .gs-dv-brainlobe:hover::before {
                opacity: 1; animation: gsDvBrainSpin 2.6s linear infinite;
            }
            @keyframes gsDvBrainSpin { to { --gsdvbrang: 360deg; } }
            [data-gs-dv-scope] .gs-dv-brainlobe-ico { font-size: 30px; filter: drop-shadow(0 4px 10px rgba(34,211,238,.35)); }
            [data-gs-dv-scope] .gs-dv-brainlobe strong { color: #f8fafc; font-size: 1rem; letter-spacing: .02em; text-transform: uppercase; }
            [data-gs-dv-scope] .gs-dv-brainlobe-sub { color: #94a3b8; font-size: .78rem; line-height: 1.4; }
            [data-gs-dv-scope].is-brainsec-open > *:not(.gs-dv-brainsec) { display: none !important; }
            [data-gs-dv-scope] .gs-dv-brainsec-bar {
                display: flex; align-items: center; gap: 14px; margin: 0 0 16px;
            }
            [data-gs-dv-scope] .gs-dv-brainsec-bar h3 { margin: 0; color: #f8fafc; font-size: 1.2rem; }
            [data-gs-dv-scope] .gs-dv-brainsec-body { min-height: 300px; }
            [data-gs-dv-scope] .gs-dv-brainfiles {
                margin: 0 0 18px; padding: 16px;
                background: rgba(2,6,23,.5); border: 1px solid rgba(125,211,252,.16);
                border-radius: 16px;
            }
            [data-gs-dv-scope] .gs-dv-brainfiles-head {
                color: #7dd3fc; font-weight: 800; font-size: .8rem;
                text-transform: uppercase; letter-spacing: .07em; margin-bottom: 12px;
            }
            [data-gs-dv-scope] .gs-dv-brainfiles-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;
            }
            [data-gs-dv-scope] .gs-dv-brainfile {
                padding: 12px 14px; border-radius: 12px;
                background: linear-gradient(160deg, rgba(15,23,42,.75), rgba(12,16,44,.55));
                border: 1px solid rgba(125,211,252,.14); color: #94a3b8;
            }
            [data-gs-dv-scope] .gs-dv-brainfile strong { color: #f1f5f9; display: block; margin-bottom: 4px; }
            [data-gs-dv-scope] .gs-dv-brainfile p { margin: 6px 0 0; font-size: .78rem; line-height: 1.5; color: #94a3b8; }
            [data-gs-dv-scope] .gs-dv-brainfile-badge {
                display: inline-block; padding: 2px 10px; border-radius: 999px;
                font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
                background: rgba(148,163,184,.12); color: #94a3b8; border: 1px solid rgba(148,163,184,.25);
            }
            [data-gs-dv-scope] .gs-dv-brainfile.is-filled .gs-dv-brainfile-badge {
                background: rgba(74,222,128,.12); color: #4ade80; border-color: rgba(74,222,128,.35);
            }
            [data-gs-dv-scope] .gs-dv-brainsec-loading {
                display: flex; align-items: center; justify-content: center; gap: 10px;
                padding: 70px 0; color: #94a3b8;
            }
            [data-gs-dv-scope] .gs-dv-brainsec-spin {
                width: 18px; height: 18px; border-radius: 50%;
                border: 2px solid rgba(34,211,238,.25); border-top-color: #22d3ee;
                animation: gsDvBrainSpinner .8s linear infinite;
            }
            @keyframes gsDvBrainSpinner { to { transform: rotate(360deg); } }
            @media (prefers-reduced-motion: reduce) {
                [data-gs-dv-scope] .gs-dv-brainhub-bg video,
                [data-gs-dv-scope] .gs-dv-brainlobe::before { animation: none !important; }
            }
        </style>
        <script>
        (function () {
            if (window.__gsDvBrainHub) { return; }
            window.__gsDvBrainHub = true;
            var TITLES = { branding: '🎨 Branding', plan: '📘 Plan', wireframe: '📐 Wireframe', growth: '📈 Growth' };
            var cache = {};
            var filesCache = null;
            function escT(s) { var d = document.createElement('i'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
            // Branding/Growth lobes list the brain's actual FILES (the same
            // foundations the sequence builders can attach as context).
            function renderFiles(sec, body) {
                if (sec !== 'branding' && sec !== 'growth') { return; }
                var hub = document.querySelector('[data-dv-brainhub]');
                if (!hub) { return; }
                var wrap = document.createElement('div');
                wrap.className = 'gs-dv-brainfiles';
                wrap.innerHTML = '<div class="gs-dv-brainfiles-head">🧠 ' + (sec === 'branding' ? '<?php echo esc_js( __( 'Branding files', 'gend-society' ) ); ?>' : '<?php echo esc_js( __( 'Growth files', 'gend-society' ) ); ?>') + '</div><div class="gs-dv-brainfiles-grid"><span class="gs-dv-brainsec-spin"></span></div>';
                body.insertBefore(wrap, body.firstChild);
                function fill(files) {
                    var items = (files || []).filter(function (f) { return f.group === sec; });
                    var grid = wrap.querySelector('.gs-dv-brainfiles-grid');
                    if (!items.length) { grid.innerHTML = '<p class="gs-dv-empty"><?php echo esc_js( __( 'No files yet — they appear as this area of the brain fills in.', 'gend-society' ) ); ?></p>'; return; }
                    grid.innerHTML = items.map(function (f) {
                        return '<div class="gs-dv-brainfile' + (f.filled ? ' is-filled' : '') + '">'
                            + '<strong>' + escT(f.title) + '</strong>'
                            + '<span class="gs-dv-brainfile-badge">' + (f.filled ? '<?php echo esc_js( __( 'Filled', 'gend-society' ) ); ?>' : '<?php echo esc_js( __( 'Empty', 'gend-society' ) ); ?>') + '</span>'
                            + (f.excerpt ? '<p>' + escT(f.excerpt) + '</p>' : '')
                            + '</div>';
                    }).join('');
                }
                if (filesCache) { fill(filesCache); return; }
                fetch(hub.getAttribute('data-rest') + '?group_id=' + encodeURIComponent(hub.getAttribute('data-gid')), {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': hub.getAttribute('data-rest-nonce') }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { filesCache = (d && d.files) || []; fill(filesCache); })
                    .catch(function () { fill([]); });
            }
            function scopeEl() { return document.querySelector('[data-gs-dv-scope]'); }
            function grabAssets(doc) {
                var have = {};
                document.querySelectorAll('link[rel="stylesheet"][href], script[src]').forEach(function (n) {
                    have[n.href || n.src] = 1;
                });
                doc.querySelectorAll('link[rel="stylesheet"][href]').forEach(function (l) {
                    if (!have[l.href]) { var n = document.createElement('link'); n.rel = 'stylesheet'; n.href = l.href; document.head.appendChild(n); }
                });
                doc.querySelectorAll('script[src]').forEach(function (s) {
                    if (!have[s.src] && s.src.indexOf('/plugins/projects/assets/') !== -1) {
                        var n = document.createElement('script'); n.src = s.src; document.body.appendChild(n);
                    }
                });
            }
            function mount(body, node) {
                body.textContent = '';
                body.appendChild(node);
                body.querySelectorAll('script').forEach(function (s) {
                    var n = document.createElement('script');
                    if (s.type && s.type !== 'text/javascript' && s.type !== 'application/javascript') { return; }
                    if (s.src) { n.src = s.src; } else { n.textContent = s.textContent; }
                    s.parentNode.replaceChild(n, s);
                });
            }
            function openSec(sec, url) {
                var scope = scopeEl();
                if (!scope) { return; }
                var stage = scope.querySelector('[data-dv-brainsec]');
                var body = scope.querySelector('[data-dv-brainsec-body]');
                var title = scope.querySelector('[data-dv-brainsec-title]');
                if (title) { title.textContent = TITLES[sec] || sec; }
                scope.classList.add('is-brainsec-open');
                stage.removeAttribute('hidden');
                function after() {
                    if (sec === 'growth') {
                        var g = body.querySelector('#psoo-bp-section-growth-strategy');
                        if (g) { g.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                    } else {
                        window.scrollTo({ top: Math.max(0, stage.getBoundingClientRect().top + pageYOffset - 90), behavior: 'smooth' });
                    }
                }
                if (cache[sec]) { mount(body, cache[sec].cloneNode(true)); renderFiles(sec, body); after(); return; }
                body.innerHTML = '<div class="gs-dv-brainsec-loading"><span class="gs-dv-brainsec-spin"></span><?php echo esc_js( __( 'Opening…', 'gend-society' ) ); ?></div>';
                // Branding: NOT the old brain graph — the Business-Plan-style
                // scrollable workspace scoped to just the branding sections
                // (editable foundation notes), from the brand-panel fragment.
                if (sec === 'branding') {
                    var hubB = document.querySelector('[data-dv-brainhub]');
                    fetch(hubB.getAttribute('data-rest').replace('brain-files', 'brand-panel') + '?group_id=' + encodeURIComponent(hubB.getAttribute('data-gid')), {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': hubB.getAttribute('data-rest-nonce') }
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d || !d.ok || !d.html) { throw new Error('no content'); }
                            var holder = document.createElement('div');
                            holder.innerHTML = d.html;
                            var bpB = holder.querySelector('section.psoo-bp') || holder;
                            cache[sec] = bpB.cloneNode(true);
                            mount(body, bpB);
                            renderFiles(sec, body);
                            after();
                        })
                        .catch(function () {
                            body.innerHTML = '<p class="gs-dv-empty"><?php echo esc_js( __( 'Could not open Branding — try again in a moment.', 'gend-society' ) ); ?></p>';
                        });
                    return;
                }
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
                    .then(function (htmlText) {
                        var doc = new DOMParser().parseFromString(htmlText, 'text/html');
                        var bp = doc.querySelector('section.psoo-bp') || doc.querySelector('#item-body');
                        if (!bp) { throw new Error('no content'); }
                        // The BP page renders its own Leo/Brain/Wireframe
                        // deep-link nav for standalone visits — inside the
                        // Brain hub it's redundant (we have Back), strip it.
                        bp.querySelectorAll('.psoo-bp__dvnav').forEach(function (n) { n.remove(); });
                        grabAssets(doc);
                        cache[sec] = bp.cloneNode(true);
                        mount(body, bp);
                        renderFiles(sec, body);
                        after();
                    })
                    .catch(function () {
                        body.innerHTML = '<p class="gs-dv-empty"><?php echo esc_js( __( 'Could not open this section — try again in a moment.', 'gend-society' ) ); ?></p>';
                    });
            }
            document.addEventListener('click', function (e) {
                var lobe = e.target && e.target.closest && e.target.closest('[data-dv-brain-sec]');
                if (lobe) {
                    openSec(lobe.getAttribute('data-dv-brain-sec'), lobe.getAttribute('data-dv-brain-url'));
                    return;
                }
                var back = e.target && e.target.closest && e.target.closest('[data-dv-brain-back]');
                if (back) {
                    var scope = scopeEl();
                    if (scope) {
                        scope.classList.remove('is-brainsec-open');
                        var stage = scope.querySelector('[data-dv-brainsec]');
                        if (stage) { stage.setAttribute('hidden', 'hidden'); }
                        window.scrollTo({ top: Math.max(0, scope.getBoundingClientRect().top + pageYOffset - 90), behavior: 'smooth' });
                    }
                }
            }, true);
            // 3D tilt on the lobes.
            document.addEventListener('mousemove', function (e) {
                var lobe = e.target && e.target.closest && e.target.closest('.gs-dv-brainlobe');
                if (!lobe) { return; }
                var r = lobe.getBoundingClientRect();
                var x = e.clientX - r.left, y = e.clientY - r.top;
                var rx = ((y - r.height / 2) / r.height) * -9;
                var ry = ((x - r.width / 2) / r.width) * 9;
                lobe.style.transform = 'perspective(800px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) translateY(-2px)';
            }, true);
            document.addEventListener('mouseout', function (e) {
                var lobe = e.target && e.target.closest && e.target.closest('.gs-dv-brainlobe');
                if (lobe && (!e.relatedTarget || !lobe.contains(e.relatedTarget))) { lobe.style.transform = ''; }
            }, true);
        })();
        </script>

        <!-- ───────── Hero + spend summary ───────── -->
        <div class="gs-dv-hero">
            <div>
                <span class="gs-dv-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    <?php esc_html_e( 'AI Spend Command', 'gend-society' ); ?>
                </span>
                <h2><?php esc_html_e( 'Davinci AI', 'gend-society' ); ?></h2>
                <p><?php esc_html_e( 'A complete overview of every dollar of AI spend on your connected web app — Leo content tokens, autonomous agents, third-party AI integrations, and on-chain compute gas, all in one place.', 'gend-society' ); ?></p>
            </div>
            <a class="gs-dv-btn gs-dv-btn--sm" href="#gs-dv-sec-leo" style="align-self:center;"><?php esc_html_e( 'Jump to Leo Tokens', 'gend-society' ); ?></a>
        </div>

        <div class="gs-dv-summary">
            <div class="gs-dv-sumcard" style="--accent:var(--dv-blue);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'Leo Content Tokens', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value"><?php echo esc_html( gend_society_davinci_money( $leo_usd ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php echo esc_html( number_format( $leo_tokens ) . ' ' . __( 'tokens this month', 'gend-society' ) ); ?></p>
            </div>
            <div class="gs-dv-sumcard" style="--accent:var(--dv-magenta);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'Agent Costs', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value"><?php echo esc_html( gend_society_davinci_money( $agent_usd ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php echo esc_html( count( $agent_ids ) . ' ' . _n( 'connected agent', 'connected agents', count( $agent_ids ), 'gend-society' ) ); ?></p>
            </div>
            <div class="gs-dv-sumcard" style="--accent:var(--dv-gold);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'API Integrations', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value" data-gs-dv-api-monthly><?php echo esc_html( gend_society_davinci_money( $api_monthly ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php esc_html_e( 'per month, owner-entered', 'gend-society' ); ?></p>
            </div>
            <div class="gs-dv-sumcard is-total" style="--accent:var(--dv-green);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'Total AI Spend / mo', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value" data-gs-dv-total><?php echo esc_html( gend_society_davinci_money( $known_monthly ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php esc_html_e( 'incl. compute gas once synced', 'gend-society' ); ?></p>
            </div>
        </div>

        <?php
        /* ───────── Sub-tabs under the stat cards: Members / Usage & Spend ──
           Members (default): every connected-app member's Leo Token balance,
           one-click admin token purchases, and scheduled Purchase Contracts
           (role- or member-targeted, daily/weekly/monthly, billed to the
           purchasing admin's DGEN wallet at 1 CAD = 1 DGEN). Usage & Spend
           wraps the pre-existing four spend sections unchanged. */
        $gs_dv_leo_nonce = wp_create_nonce( 'gs_dv_leo' );
        ?>
        <style>
            /* ── Glassmorphic 3D sub-tabs ──────────────────────────────
               `html body` prefix + !important on the identity props beats
               Youzify_Styling's `body .x { !important }` emissions without
               load-order luck. Each tab: frosted glass card, mouse-tracked
               3D tilt (JS sets --mx/--my + transform), an animated neon
               conic border sweep on hover/active around ALL borders, and a
               cursor-following inner glow. */
            @property --gsdvang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
            html body [data-gs-dv-scope] .gs-dv-subtabs {
                display: flex !important; gap: 12px; margin: 0 0 22px; flex-wrap: wrap;
                perspective: 900px;
            }
            html body [data-gs-dv-scope] .gs-dv-subtab {
                position: relative;
                padding: 12px 24px !important;
                border-radius: 14px !important;
                cursor: pointer;
                background: linear-gradient(160deg, rgba(15,23,42,.72), rgba(12,16,44,.5)) !important;
                color: rgba(203,213,225,.78) !important;
                border: 1px solid rgba(125,211,252,.18) !important;
                font-family: Inter, system-ui, sans-serif !important;
                font-weight: 700 !important; font-size: .78rem !important;
                letter-spacing: .05em; text-transform: uppercase;
                -webkit-backdrop-filter: blur(14px) saturate(150%);
                        backdrop-filter: blur(14px) saturate(150%);
                box-shadow: 0 10px 26px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06);
                transform-style: preserve-3d;
                transition: color .22s ease, box-shadow .3s ease, transform .18s ease;
                will-change: transform;
                text-decoration: none !important;
                line-height: 1.2 !important;
            }
            /* Animated neon border — a conic sweep masked to the 1px frame,
               so the glow runs around every border edge. */
            html body [data-gs-dv-scope] .gs-dv-subtab::before {
                content: ''; position: absolute; inset: -1px; border-radius: 15px;
                padding: 1.5px; pointer-events: none;
                background: conic-gradient(from var(--gsdvang, 0deg),
                    #22d3ee, #b608c9, #7dd3fc, #22d3ee);
                -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                -webkit-mask-composite: xor;
                        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                        mask-composite: exclude;
                opacity: 0; transition: opacity .3s ease;
            }
            /* Cursor-following inner glow (JS feeds --mx/--my). */
            html body [data-gs-dv-scope] .gs-dv-subtab::after {
                content: ''; position: absolute; inset: 0; border-radius: 14px;
                pointer-events: none;
                background: radial-gradient(130px circle at var(--mx, 50%) var(--my, 50%),
                    rgba(34,211,238,.2), rgba(182,8,201,.08) 45%, transparent 70%);
                opacity: 0; transition: opacity .25s ease;
            }
            html body [data-gs-dv-scope] .gs-dv-subtab:hover {
                color: #f1f5f9 !important;
                box-shadow: 0 18px 40px rgba(0,0,0,.45), 0 0 22px rgba(34,211,238,.18), inset 0 1px 0 rgba(255,255,255,.08);
            }
            html body [data-gs-dv-scope] .gs-dv-subtab:hover::before,
            html body [data-gs-dv-scope] .gs-dv-subtab:hover::after { opacity: 1; }
            html body [data-gs-dv-scope] .gs-dv-subtab:hover::before {
                animation: gsDvBorderSpin 2.6s linear infinite;
            }
            @keyframes gsDvBorderSpin { to { --gsdvang: 360deg; } }
            html body [data-gs-dv-scope] .gs-dv-subtab.is-active {
                color: #eafcff !important;
                background: linear-gradient(160deg, rgba(34,211,238,.2), rgba(182,8,201,.14)) !important;
                box-shadow: 0 14px 34px rgba(0,0,0,.4), 0 0 26px rgba(34,211,238,.28), inset 0 1px 0 rgba(255,255,255,.1);
            }
            html body [data-gs-dv-scope] .gs-dv-subtab.is-active::before {
                opacity: 1;
                animation: gsDvBorderSpin 4s linear infinite;
            }
            @media (prefers-reduced-motion: reduce) {
                html body [data-gs-dv-scope] .gs-dv-subtab,
                html body [data-gs-dv-scope] .gs-dv-subtab::before { animation: none !important; transition: none !important; }
            }
            [data-gs-dv-scope] .gs-dv-subpanel { display:none; }
            [data-gs-dv-scope] .gs-dv-subpanel.is-active { display:block; }
            /* Members-tab surfaces — every rule `html body`-prefixed with
               !important on background/color/border so Youzify's global
               table + form styling (red thead, white rows, light selects)
               can never take these over. */
            html body [data-gs-dv-scope] .gs-dvm-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 14px; }
            html body [data-gs-dv-scope] .gs-dvm-search {
                flex:1; min-width:220px; padding:10px 14px !important; border-radius:12px !important;
                background:rgba(2,6,23,.55) !important; color:#e2e8f0 !important;
                border:1px solid rgba(125,211,252,.2) !important; font-size:.88rem !important;
                box-shadow:none !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-search::placeholder { color:#64748b !important; }
            html body [data-gs-dv-scope] .gs-dvm-wallet {
                padding:8px 16px; border-radius:999px; font-size:.78rem; font-weight:700;
                background:rgba(34,211,238,.1) !important; color:#7dd3fc !important;
                border:1px solid rgba(34,211,238,.25) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-tablewrap {
                overflow-x:auto; border-radius:14px;
                border:1px solid rgba(125,211,252,.14) !important;
                background:rgba(2,6,23,.55) !important;
            }
            html body [data-gs-dv-scope] table.gs-dvm-table {
                width:100% !important; border-collapse:collapse !important; min-width:640px;
                font-size:.86rem !important; color:#cbd5e1 !important;
                background:transparent !important; border:0 !important; margin:0 !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-table thead,
            html body [data-gs-dv-scope] .gs-dvm-table thead tr {
                background:rgba(15,23,42,.9) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-table thead th,
            html body [data-gs-dv-scope] .gs-dvm-table th {
                text-align:left !important; padding:11px 14px !important;
                font-size:.68rem !important; font-weight:700 !important;
                text-transform:uppercase; letter-spacing:.07em;
                background:rgba(15,23,42,.9) !important; color:#7dd3fc !important;
                border:0 !important; border-bottom:1px solid rgba(125,211,252,.18) !important;
                white-space:nowrap;
            }
            html body [data-gs-dv-scope] .gs-dvm-table tbody tr,
            html body [data-gs-dv-scope] .gs-dvm-table tr {
                background:transparent !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-table tbody tr:nth-child(even) {
                background:rgba(125,211,252,.04) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-table tbody tr:hover {
                background:rgba(34,211,238,.06) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-table td {
                padding:10px 14px !important; vertical-align:middle;
                background:transparent !important; color:#cbd5e1 !important;
                border:0 !important; border-bottom:1px solid rgba(125,211,252,.07) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-table tr:last-child td { border-bottom:0 !important; }
            html body [data-gs-dv-scope] .gs-dvm-table td small { color:#64748b !important; }
            html body [data-gs-dv-scope] .gs-dvm-member { display:flex; align-items:center; gap:10px; }
            html body [data-gs-dv-scope] .gs-dvm-member img {
                width:34px !important; height:34px !important; border-radius:999px !important;
                max-width:none !important; box-shadow:none !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-member strong { color:#f1f5f9 !important; }
            html body [data-gs-dv-scope] .gs-dvm-role { font-size:.72rem !important; color:#94a3b8 !important; text-transform:capitalize; }
            html body [data-gs-dv-scope] .gs-dvm-bal { font-weight:800 !important; color:#7dd3fc !important; white-space:nowrap; }
            html body [data-gs-dv-scope] .gs-dvm-buyrow { display:flex; gap:8px; align-items:center; }
            html body [data-gs-dv-scope] select.gs-dvm-sel,
            html body [data-gs-dv-scope] .gs-dvm-sel,
            html body [data-gs-dv-scope] .gs-dvm-in {
                padding:8px 28px 8px 10px !important; border-radius:10px !important;
                background-color:rgba(2,6,23,.75) !important; color:#e2e8f0 !important;
                border:1px solid rgba(125,211,252,.2) !important; font-size:.82rem !important;
                -webkit-appearance:none; appearance:none;
                background-image:url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%237dd3fc' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") !important;
                background-repeat:no-repeat !important; background-position:right 10px center !important;
                box-shadow:none !important; height:auto !important; line-height:1.4 !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-sel option { background:#0b1120 !important; color:#e2e8f0 !important; }
            html body [data-gs-dv-scope] select.gs-dvm-sel[multiple] { background-image:none !important; padding-right:10px !important; }
            /* Youzify's nice-select hijack: if it wraps our selects, restyle
               its shell too so nothing renders as a white pill. */
            html body [data-gs-dv-scope] .nice-select,
            html body [data-gs-dv-scope] .nice-select .list {
                background:rgba(2,6,23,.9) !important; color:#e2e8f0 !important;
                border:1px solid rgba(125,211,252,.2) !important; border-radius:10px !important;
            }
            html body [data-gs-dv-scope] .nice-select .option { background:transparent !important; color:#e2e8f0 !important; }
            html body [data-gs-dv-scope] .nice-select .option:hover,
            html body [data-gs-dv-scope] .nice-select .option.selected { background:rgba(34,211,238,.12) !important; }
            html body [data-gs-dv-scope] .gs-dvm-buy {
                padding:8px 16px !important; border-radius:999px !important; cursor:pointer; white-space:nowrap;
                background:linear-gradient(135deg,#22d3ee,#7dd3fc) !important; color:#0b0e14 !important;
                border:0 !important; font-weight:800 !important; font-size:.78rem !important;
                box-shadow:0 6px 18px rgba(34,211,238,.25) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-buy[disabled] { opacity:.5; cursor:default; }
            html body [data-gs-dv-scope] .gs-dvm-status { margin:10px 0 0; font-size:.8rem; color:#94a3b8 !important; min-height:1.2em; }
            html body [data-gs-dv-scope] .gs-dvm-status.is-ok { color:#4ade80 !important; }
            html body [data-gs-dv-scope] .gs-dvm-status.is-err { color:#f87171 !important; }
            html body [data-gs-dv-scope] .gs-dvm-contract {
                display:flex; gap:12px; align-items:center; flex-wrap:wrap;
                padding:12px 14px; border-radius:12px; margin-bottom:10px;
                background:rgba(2,6,23,.45) !important; border:1px solid rgba(125,211,252,.12) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-contract .meta { flex:1; min-width:240px; }
            html body [data-gs-dv-scope] .gs-dvm-contract .meta strong { color:#f1f5f9 !important; display:block; }
            html body [data-gs-dv-scope] .gs-dvm-contract .meta small { color:#94a3b8 !important; }
            html body [data-gs-dv-scope] .gs-dvm-pill {
                padding:4px 12px !important; border-radius:999px !important; font-size:.68rem !important; font-weight:700;
                text-transform:uppercase; letter-spacing:.06em; cursor:pointer;
                background:rgba(148,163,184,.12) !important; color:#94a3b8 !important;
                border:1px solid rgba(148,163,184,.25) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-pill.is-on {
                background:rgba(74,222,128,.12) !important; color:#4ade80 !important;
                border-color:rgba(74,222,128,.35) !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-cform { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-top:14px; }
            html body [data-gs-dv-scope] .gs-dvm-cform label {
                display:flex; flex-direction:column; gap:6px; font-size:.72rem !important;
                color:#94a3b8 !important; text-transform:uppercase; letter-spacing:.05em;
                margin:0 !important;
            }
            html body [data-gs-dv-scope] .gs-dvm-cmembers { min-height:92px; }
            /* Youzify's niceSelect() hijacks every non-multiple <select> in
               .youzify containers into dead lookalike divs — kill the shells
               in our scope and force the NATIVE selects back (our !important
               beats niceSelect's inline display:none). */
            html body [data-gs-dv-scope] .nice-select { display: none !important; }
            html body [data-gs-dv-scope] select.gs-dvm-sel,
            html body [data-gs-dv-scope] select.gs-dv-in {
                display: block !important;
            }
            /* .gs-dvm-cform label { display:flex } was overriding the
               [hidden] attribute — the Role wrap showed while "Specific
               members" was targeted. */
            html body [data-gs-dv-scope] .gs-dvm-cform label[hidden] { display: none !important; }
            /* ── Contract editor popup ── */
            .gs-dvm-cmodal { position: fixed; inset: 0; z-index: 100200; display: flex; align-items: center; justify-content: center; padding: 22px; }
            .gs-dvm-cmodal[hidden] { display: none; }
            .gs-dvm-cmodal-backdrop { position: absolute; inset: 0; background: rgba(2,6,23,.82); backdrop-filter: blur(6px); }
            .gs-dvm-cmodal-dialog {
                position: relative; width: min(560px, 100%); max-height: 88vh; overflow-y: auto;
                background: #0a1019; border: 1px solid rgba(125,211,252,.28); border-radius: 18px;
                box-shadow: 0 50px 140px rgba(0,0,0,.65), 0 0 30px rgba(182,8,201,.14);
                padding: 24px;
            }
            .gs-dvm-cmodal-dialog h3 { margin: 0 0 6px; color: #f8fafc; font-size: 1.15rem; }
            .gs-dvm-cmodal-sub { margin: 0 0 16px; color: #94a3b8; font-size: .82rem; line-height: 1.5; }
            .gs-dvm-cmodal-x {
                position: absolute; top: 14px; right: 14px;
                width: 30px; height: 30px; border-radius: 999px; cursor: pointer;
                background: rgba(15,23,42,.85); color: #f1f5f9; border: 1px solid rgba(148,163,184,.3);
                font-size: 18px; line-height: 1;
            }
            .gs-dvm-cmodal-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; font-size: .72rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
            .gs-dvm-cmodal-field[hidden] { display: none !important; }
            .gs-dvm-cmodal-lbl { font-size: .72rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
            .gs-dvm-cmodal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }
            .gs-dvm-chips { display: flex; flex-wrap: wrap; gap: 8px; }
            .gs-dvm-chips:empty { display: none; }
            .gs-dvm-chip {
                display: inline-flex; align-items: center; gap: 8px;
                padding: 5px 8px 5px 5px; border-radius: 999px;
                background: rgba(34,211,238,.1); color: #e2e8f0;
                border: 1px solid rgba(34,211,238,.3); font-size: .78rem; text-transform: none; letter-spacing: 0;
            }
            .gs-dvm-chip img { width: 22px; height: 22px; border-radius: 999px; }
            .gs-dvm-chip button { background: none; border: 0; color: #94a3b8; cursor: pointer; font-size: 14px; line-height: 1; padding: 0 2px; }
            .gs-dvm-chip button:hover { color: #f87171; }
            .gs-dvm-searchwrap { position: relative; }
            .gs-dvm-results {
                position: absolute; left: 0; right: 0; z-index: 30; margin-top: 6px;
                max-height: 240px; overflow-y: auto; padding: 6px;
                background: rgba(4,8,20,.97); border: 1px solid rgba(125,211,252,.25);
                border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,.55);
            }
            .gs-dvm-results[hidden] { display: none; }
            @property --gsdvcmang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
            .gs-dvm-result-row {
                position: relative; display: flex; align-items: center; gap: 10px;
                width: 100%; text-align: left; padding: 8px 10px; margin: 4px 0;
                background: linear-gradient(160deg, rgba(15,23,42,.75), rgba(12,16,44,.55));
                border: 1px solid rgba(125,211,252,.16); border-radius: 12px;
                color: #e2e8f0; cursor: pointer; font-size: .85rem;
                transform-style: preserve-3d; will-change: transform;
                transition: transform .16s ease, box-shadow .25s ease;
            }
            .gs-dvm-result-row::before {
                content: ''; position: absolute; inset: -1px; border-radius: 13px;
                padding: 1.5px; pointer-events: none; opacity: 0;
                background: conic-gradient(from var(--gsdvcmang, 0deg), #22d3ee, #b608c9, #7dd3fc, #22d3ee);
                -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                -webkit-mask-composite: xor;
                        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                        mask-composite: exclude;
                transition: opacity .3s ease;
            }
            .gs-dvm-result-row:hover { box-shadow: 0 14px 32px rgba(0,0,0,.45), 0 0 18px rgba(34,211,238,.18); }
            .gs-dvm-result-row:hover::before { opacity: 1; animation: gsDvCmSpin 2.6s linear infinite; }
            @keyframes gsDvCmSpin { to { --gsdvcmang: 360deg; } }
            .gs-dvm-result-row img { width: 30px; height: 30px; border-radius: 999px; }
            .gs-dvm-result-row strong { color: #f8fafc; }
            .gs-dvm-result-muted { color: #94a3b8; font-size: .78rem; padding: 8px 10px; }
            .gs-dvm-cmodal-hint { color: #64748b; font-size: .72rem; text-transform: none; letter-spacing: 0; }
            .gs-dvm-seg { display: flex; gap: 8px; }
            .gs-dvm-seg-btn {
                flex: 1; padding: 10px 14px; border-radius: 12px; cursor: pointer;
                background: rgba(2,6,23,.55); color: #94a3b8;
                border: 1px solid rgba(125,211,252,.18);
                font-weight: 700; font-size: .8rem; text-transform: none; letter-spacing: 0;
                transition: color .2s ease, border-color .2s ease, box-shadow .25s ease;
            }
            .gs-dvm-seg-btn:hover { color: #e2e8f0; border-color: rgba(34,211,238,.4); }
            .gs-dvm-seg-btn.is-on {
                color: #eafcff;
                background: linear-gradient(160deg, rgba(34,211,238,.18), rgba(182,8,201,.12));
                border-color: rgba(34,211,238,.55);
                box-shadow: 0 0 16px rgba(34,211,238,.2);
            }
            .gs-dvm-rolebar { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
            .gs-dvm-mini {
                padding: 3px 12px; border-radius: 999px; cursor: pointer; white-space: nowrap;
                background: rgba(125,211,252,.08); color: #7dd3fc;
                border: 1px solid rgba(125,211,252,.3); font-size: .68rem; font-weight: 700;
            }
            .gs-dvm-rolepills { display: flex; flex-wrap: wrap; gap: 8px; }
            .gs-dvm-rolepill {
                padding: 6px 14px; border-radius: 999px; cursor: pointer;
                background: rgba(2,6,23,.55); color: #94a3b8;
                border: 1px solid rgba(148,163,184,.25);
                font-size: .75rem; font-weight: 600; text-transform: capitalize; letter-spacing: 0;
                transition: color .2s ease, border-color .2s ease, background .2s ease;
            }
            .gs-dvm-rolepill:hover { color: #e2e8f0; border-color: rgba(34,211,238,.4); }
            .gs-dvm-rolepill.is-on {
                color: #eafcff;
                background: rgba(34,211,238,.14);
                border-color: rgba(34,211,238,.55);
                box-shadow: 0 0 12px rgba(34,211,238,.18);
            }
            .gs-dvm-cadcards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .gs-dvm-cadcard {
                display: flex; flex-direction: column; align-items: center; gap: 3px;
                padding: 12px 8px; border-radius: 12px; cursor: pointer;
                background: rgba(2,6,23,.55); color: #94a3b8;
                border: 1px solid rgba(125,211,252,.18);
                transition: color .2s ease, border-color .2s ease, box-shadow .25s ease, transform .15s ease;
            }
            .gs-dvm-cadcard strong { color: #e2e8f0; font-size: .85rem; text-transform: none; letter-spacing: 0; }
            .gs-dvm-cadcard span { font-size: .68rem; text-transform: none; letter-spacing: 0; }
            .gs-dvm-cadcard:hover { border-color: rgba(34,211,238,.4); transform: translateY(-1px); }
            .gs-dvm-cadcard.is-on {
                background: linear-gradient(160deg, rgba(34,211,238,.16), rgba(182,8,201,.1));
                border-color: rgba(34,211,238,.55);
                box-shadow: 0 0 16px rgba(34,211,238,.2);
            }
            .gs-dvm-cadcard.is-on strong { color: #eafcff; }
        </style>

        <div class="gs-dv-subtabs" role="tablist">
            <button type="button" class="gs-dv-subtab is-active" data-gs-dv-subtab="members" role="tab" aria-selected="true">👥 <?php esc_html_e( 'Member Balances', 'gend-society' ); ?></button>
            <button type="button" class="gs-dv-subtab" data-gs-dv-subtab="contracts" role="tab" aria-selected="false">🔁 <?php esc_html_e( 'Purchase Contracts', 'gend-society' ); ?></button>
            <button type="button" class="gs-dv-subtab" data-gs-dv-subtab="agents" role="tab" aria-selected="false">🤖 <?php esc_html_e( 'Agents', 'gend-society' ); ?></button>
            <button type="button" class="gs-dv-subtab" data-gs-dv-subtab="sequences" role="tab" aria-selected="false">⚡ <?php esc_html_e( 'Sequences', 'gend-society' ); ?></button>
            <button type="button" class="gs-dv-subtab" data-gs-dv-subtab="compute" role="tab" aria-selected="false">⛽ <?php esc_html_e( 'Compute Gas', 'gend-society' ); ?></button>
            <button type="button" class="gs-dv-subtab" data-gs-dv-subtab="api" role="tab" aria-selected="false">🔑 <?php esc_html_e( 'API Tokens', 'gend-society' ); ?></button>
        </div>

        <!-- ───────── Sub-panel: MEMBERS ───────── -->
        <div class="gs-dv-subpanel is-active" data-gs-dv-subpanel="members" data-gs-dvm data-nonce="<?php echo esc_attr( $gs_dv_leo_nonce ); ?>">
            <section class="gs-dv-panel">
                <div class="gs-dv-panel-head">
                    <div>
                        <h3><?php esc_html_e( 'Member Leo Token Balances', 'gend-society' ); ?></h3>
                        <p class="lede"><?php esc_html_e( 'Every member of the connected web app with their live Leo Token balance. Buy any member more tokens on the spot — the package price bills your DGEN wallet (1 CAD = 1 DGEN) and the tokens land on their account instantly.', 'gend-society' ); ?></p>
                    </div>
                    <span class="gs-dvm-wallet" data-dvm-wallet><?php esc_html_e( 'Your DGEN: …', 'gend-society' ); ?></span>
                </div>
                <div class="gs-dvm-toolbar">
                    <input type="search" class="gs-dvm-search" data-dvm-search placeholder="<?php esc_attr_e( 'Search members by name or email…', 'gend-society' ); ?>" />
                </div>
                <div class="gs-dvm-tablewrap">
                    <table class="gs-dvm-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Member', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'Role', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'Leo Balance', 'gend-society' ); ?></th>
                            <th><?php esc_html_e( 'Buy Tokens', 'gend-society' ); ?></th>
                        </tr></thead>
                        <tbody data-dvm-body>
                            <tr><td colspan="4" style="text-align:center;color:#64748b;"><?php esc_html_e( 'Loading members…', 'gend-society' ); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="gs-dvm-status" data-dvm-status aria-live="polite"></p>
            </section>
        </div>

        <!-- ───────── Sub-panel: PURCHASE CONTRACTS ───────── -->
        <div class="gs-dv-subpanel" data-gs-dv-subpanel="contracts">
            <section class="gs-dv-panel">
                <div class="gs-dv-panel-head">
                    <div>
                        <h3><?php esc_html_e( 'Scheduled Purchase Contracts', 'gend-society' ); ?></h3>
                        <p class="lede"><?php esc_html_e( 'Keep the team topped up automatically: pick a member role or specific members, a token package, and a cadence — the purchase repeats daily, weekly or monthly, billed to your DGEN wallet. Runs are skipped (and retried) whenever the wallet balance is short.', 'gend-society' ); ?></p>
                    </div>
                </div>
                <div data-dvm-contracts>
                    <p class="gs-dv-empty" style="margin:0 0 12px;"><?php esc_html_e( 'Loading contracts…', 'gend-society' ); ?></p>
                </div>
                <button type="button" class="gs-dvm-buy" data-dvm-cnew style="margin-top:4px;">＋ <?php esc_html_e( 'Add Purchase Contract', 'gend-society' ); ?></button>
                <p class="gs-dvm-status" data-dvm-cstatus aria-live="polite"></p>

                <!-- ── Contract editor popup ── -->
                <div class="gs-dvm-cmodal" data-dvm-cmodal hidden>
                    <div class="gs-dvm-cmodal-backdrop" data-cm-close></div>
                    <div class="gs-dvm-cmodal-dialog" role="dialog" aria-modal="true">
                        <button type="button" class="gs-dvm-cmodal-x" data-cm-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                        <h3 data-cm-title><?php esc_html_e( 'New Purchase Contract', 'gend-society' ); ?></h3>
                        <p class="gs-dvm-cmodal-sub"><?php esc_html_e( 'Automatic Leo Token purchases for this app\'s members — billed to your DGEN wallet on the chosen cadence.', 'gend-society' ); ?></p>

                        <div class="gs-dvm-cmodal-field">
                            <span class="gs-dvm-cmodal-lbl">👥 <?php esc_html_e( 'Who receives the Leo Tokens', 'gend-society' ); ?></span>
                            <div class="gs-dvm-seg" data-cm-target>
                                <button type="button" class="gs-dvm-seg-btn is-on" data-cm-target-btn="members">🧑‍🤝‍🧑 <?php esc_html_e( 'Specific members', 'gend-society' ); ?></button>
                                <button type="button" class="gs-dvm-seg-btn" data-cm-target-btn="role">🎭 <?php esc_html_e( 'Member roles', 'gend-society' ); ?></button>
                            </div>
                        </div>

                        <div class="gs-dvm-cmodal-field" data-dvm-cmembers-wrap>
                            <div class="gs-dvm-chips" data-cm-chips></div>
                            <div class="gs-dvm-searchwrap">
                                <input type="search" class="gs-dvm-search gs-dvm-in" data-cm-search autocomplete="off"
                                       placeholder="<?php esc_attr_e( 'Search members to add…', 'gend-society' ); ?>" />
                                <div class="gs-dvm-results" data-cm-results hidden></div>
                            </div>
                            <small class="gs-dvm-cmodal-hint"><?php esc_html_e( 'Every member you add gets the package on each run.', 'gend-society' ); ?></small>
                        </div>

                        <div class="gs-dvm-cmodal-field" data-dvm-crole-wrap hidden>
                            <div class="gs-dvm-rolebar">
                                <small class="gs-dvm-cmodal-hint"><?php esc_html_e( 'Pick one or more roles — everyone holding them at run time receives the package.', 'gend-society' ); ?></small>
                                <button type="button" class="gs-dvm-mini" data-cm-roles-all><?php esc_html_e( 'Select all', 'gend-society' ); ?></button>
                            </div>
                            <div class="gs-dvm-rolepills" data-cm-roles>
                                <?php
                                $gs_dvc_site = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( $group_id, 'gdc_site_id', true ) : 0;
                                if ( $gs_dvc_site > 0 && is_multisite() && $gs_dvc_site !== get_current_blog_id() ) { switch_to_blog( $gs_dvc_site ); $gs_dvc_sw = true; } else { $gs_dvc_sw = false; }
                                foreach ( wp_roles()->get_names() as $gs_dvc_rk => $gs_dvc_rn ) :
                                ?>
                                    <button type="button" class="gs-dvm-rolepill" data-cm-role="<?php echo esc_attr( $gs_dvc_rk ); ?>"><?php echo esc_html( translate_user_role( $gs_dvc_rn ) ); ?></button>
                                <?php endforeach; if ( $gs_dvc_sw ) { restore_current_blog(); } ?>
                            </div>
                        </div>

                        <label class="gs-dvm-cmodal-field">💠 <?php esc_html_e( 'Token package', 'gend-society' ); ?>
                            <select class="gs-dvm-sel" data-dvm-cpackage>
                                <?php foreach ( gend_society_dv_leo_packages() as $gs_dvc_pk ) : ?>
                                    <option value="<?php echo esc_attr( $gs_dvc_pk['id'] ); ?>"><?php echo esc_html( $gs_dvc_pk['name'] . ' — ' . number_format_i18n( $gs_dvc_pk['tokens'] ) . ' Leo · $' . number_format_i18n( $gs_dvc_pk['price'], 2 ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="gs-dvm-cmodal-field">
                            <span class="gs-dvm-cmodal-lbl">📅 <?php esc_html_e( 'Purchase schedule', 'gend-society' ); ?></span>
                            <div class="gs-dvm-cadcards" data-cm-cads>
                                <button type="button" class="gs-dvm-cadcard" data-cm-cad="daily">
                                    <strong><?php esc_html_e( 'Daily', 'gend-society' ); ?></strong>
                                    <span><?php esc_html_e( 'every day', 'gend-society' ); ?></span>
                                </button>
                                <button type="button" class="gs-dvm-cadcard" data-cm-cad="weekly">
                                    <strong><?php esc_html_e( 'Weekly', 'gend-society' ); ?></strong>
                                    <span><?php esc_html_e( 'every 7 days', 'gend-society' ); ?></span>
                                </button>
                                <button type="button" class="gs-dvm-cadcard is-on" data-cm-cad="monthly">
                                    <strong><?php esc_html_e( 'Monthly', 'gend-society' ); ?></strong>
                                    <span><?php esc_html_e( 'every 30 days', 'gend-society' ); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="gs-dvm-cmodal-actions">
                            <button type="button" class="gs-dvm-pill" data-cm-close><?php esc_html_e( 'Cancel', 'gend-society' ); ?></button>
                            <button type="button" class="gs-dvm-buy" data-cm-save><?php esc_html_e( 'Save Contract', 'gend-society' ); ?></button>
                        </div>
                        <p class="gs-dvm-status" data-cm-status aria-live="polite"></p>
                    </div>
                </div>
                <p class="gs-dvm-status" data-dvm-cstatus aria-live="polite"></p>
            </section>
        </div>

        <!-- ───────── Sub-panel: MEMBER BALANCES (cont.) — Leo usage detail ───────── -->
        <div class="gs-dv-subpanel is-active" data-gs-dv-subpanel="members">

        <!-- ───────── 1. Leo Web Content Builder ───────── -->
        <section class="gs-dv-panel" id="gs-dv-sec-leo">
            <p class="gs-dv-eyebrow" style="--accent:var(--dv-blue);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                <?php esc_html_e( 'Section 01', 'gend-society' ); ?>
            </p>
            <div class="gs-dv-panel-head">
                <div>
                    <h3><?php esc_html_e( 'Leo Web Content Builder', 'gend-society' ); ?></h3>
                    <p class="lede"><?php esc_html_e( 'Leo writes, designs, and updates your web app. Track the content tokens it has burned this month, top up your own plan, and manage every member’s balance and plan.', 'gend-society' ); ?></p>
                </div>
            </div>

            <div class="gs-dv-stat-row">
                <div class="gs-dv-stat">
                    <p class="k"><?php esc_html_e( 'Leo Spend (this month)', 'gend-society' ); ?></p>
                    <div class="v"><?php echo esc_html( gend_society_davinci_money( $leo_usd ) ); ?></div>
                </div>
                <div class="gs-dv-stat">
                    <p class="k"><?php esc_html_e( 'Tokens Consumed', 'gend-society' ); ?></p>
                    <div class="v"><?php echo esc_html( number_format( $leo_tokens ) ); ?></div>
                </div>
                <div class="gs-dv-stat">
                    <p class="k"><?php esc_html_e( 'Your Token Balance', 'gend-society' ); ?></p>
                    <div class="v"><?php echo esc_html( number_format( $my_balance ) ); ?></div>
                </div>
            </div>

            <?php if ( class_exists( 'AIPA_Usage' ) ) : ?>
            <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-end; margin-bottom:26px;">
                <form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="aipa_buy_credits">
                    <?php wp_nonce_field( 'aipa_buy_credits' ); ?>
                    <p class="k" style="font-size:0.6rem; font-weight:900; text-transform:uppercase; letter-spacing:1.3px; opacity:0.45; margin:0 0 8px;"><?php printf( esc_html__( 'Top up your Leo plan (%s)', 'gend-society' ), esc_html( $currency ) ); ?></p>
                    <div class="gs-dv-topup">
                        <input type="number" name="amount" min="5" step="5" value="25" aria-label="<?php esc_attr_e( 'Top-up amount', 'gend-society' ); ?>">
                        <button type="submit" class="gs-dv-cta"><?php esc_html_e( 'Buy Tokens', 'gend-society' ); ?></button>
                    </div>
                    <p class="gs-dv-sum-foot" style="margin-top:8px;"><?php esc_html_e( 'One-time purchase — added to your balance at checkout.', 'gend-society' ); ?></p>
                </form>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <a class="gs-dv-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=aipa-ai-members' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Manage Member Plans & Recurring Tokens', 'gend-society' ); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <h4 style="font-size:0.95rem; margin:0 0 12px; opacity:0.85;"><?php esc_html_e( 'Member Balances & Plans', 'gend-society' ); ?></h4>
            <?php if ( empty( $human_ids ) ) : ?>
                <p class="gs-dv-empty"><?php esc_html_e( 'No human members found in this group yet.', 'gend-society' ); ?></p>
            <?php else : ?>
                <div style="overflow-x:auto;">
                <table class="gs-dv-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Member', 'gend-society' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'gend-society' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Balance', 'gend-society' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Tokens / mo', 'gend-society' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Spend / mo', 'gend-society' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $shown = 0;
                    foreach ( $human_ids as $hid ) {
                        if ( $shown++ >= 100 ) break;
                        $u = get_userdata( $hid );
                        if ( ! $u ) continue;
                        $bal   = class_exists( 'AIPA_Usage' ) ? (float) AIPA_Usage::get_balance( $hid ) : 0.0;
                        $row   = isset( $usage[ $hid ] ) ? $usage[ $hid ] : array( 'tokens' => 0, 'usd' => 0.0 );
                        $is_admin_member = ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $hid, $group_id ) );
                        $role  = $is_admin_member ? __( 'Admin', 'gend-society' ) : __( 'Member', 'gend-society' );
                        ?>
                        <tr>
                            <td><div class="gs-dv-who"><?php echo get_avatar( $hid, 30 ); ?><span><?php echo esc_html( $u->display_name ); ?></span></div></td>
                            <td><span class="gs-dv-pill"><?php echo esc_html( $role ); ?></span></td>
                            <td class="num"><?php echo esc_html( number_format( $bal ) ); ?></td>
                            <td class="num"><?php echo esc_html( number_format( (int) $row['tokens'] ) ); ?></td>
                            <td class="num"><?php echo esc_html( gend_society_davinci_money( $row['usd'] ) ); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        </div><!-- /subpanel:members (usage detail) -->

        <!-- ───────── Sub-panel: AGENTS ───────── -->
        <div class="gs-dv-subpanel" data-gs-dv-subpanel="agents">
        <!-- ───────── 2. Agent Costs ───────── -->
        <section class="gs-dv-panel">
            <p class="gs-dv-eyebrow" style="--accent:var(--dv-magenta);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
                <?php esc_html_e( 'Section 02', 'gend-society' ); ?>
            </p>
            <div class="gs-dv-panel-head">
                <div>
                    <h3><?php esc_html_e( 'Agent Costs', 'gend-society' ); ?></h3>
                    <p class="lede"><?php esc_html_e( 'Every autonomous AI agent connected to this web app, broken out by what each has spent running its tasks this month.', 'gend-society' ); ?></p>
                </div>
                <div class="gs-dv-stat" style="min-width:170px;">
                    <p class="k"><?php esc_html_e( 'Agent Spend / mo', 'gend-society' ); ?></p>
                    <div class="v"><?php echo esc_html( gend_society_davinci_money( $agent_usd ) ); ?></div>
                </div>
            </div>

            <?php if ( empty( $agent_ids ) ) : ?>
                <p class="gs-dv-empty">
                    <?php esc_html_e( 'No AI agents are connected to this web app yet. Add an agent from the group’s Organization tab or the Agents view in the desktop app — once an agent runs, its spend appears here automatically.', 'gend-society' ); ?>
                </p>
            <?php else : ?>
                <div style="overflow-x:auto;">
                <table class="gs-dv-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Agent', 'gend-society' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'gend-society' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Runs / mo', 'gend-society' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Tokens / mo', 'gend-society' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Spend / mo', 'gend-society' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    foreach ( $agent_ids as $aid ) {
                        $u = get_userdata( $aid );
                        if ( ! $u ) continue;
                        $row = isset( $usage[ $aid ] ) ? $usage[ $aid ] : array( 'tokens' => 0, 'usd' => 0.0, 'calls' => 0 );
                        ?>
                        <tr>
                            <td><div class="gs-dv-who"><?php echo get_avatar( $aid, 30 ); ?><span><?php echo esc_html( $u->display_name ); ?></span></div></td>
                            <td><span class="gs-dv-pill is-agent"><?php esc_html_e( 'Agent', 'gend-society' ); ?></span></td>
                            <td class="num"><?php echo esc_html( number_format( (int) ( $row['calls'] ?? 0 ) ) ); ?></td>
                            <td class="num"><?php echo esc_html( number_format( (int) $row['tokens'] ) ); ?></td>
                            <td class="num"><?php echo esc_html( gend_society_davinci_money( $row['usd'] ) ); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        </div><!-- /subpanel:agents -->

        <!-- ───────── Sub-panel: SEQUENCES — AI run-cost of synced sequences ───────── -->
        <div class="gs-dv-subpanel" data-gs-dv-subpanel="sequences">
            <section class="gs-dv-panel">
                <div class="gs-dv-panel-head">
                    <div>
                        <h3><?php esc_html_e( 'Sequence Run Costs', 'gend-society' ); ?></h3>
                        <p class="lede"><?php esc_html_e( 'Every sequence synced to gend.me from this group, with the estimated AI cost of one full run. Steps routed to a connected device or the Claude terminal run on member hardware / personal licenses (no gend.me metering); steps on the gend.me Compute Network are estimated from each prompt at current Leo Token pricing.', 'gend-society' ); ?></p>
                    </div>
                </div>
                <?php $gs_dv_sq = gend_society_dv_sequences_cost( $group_id ); ?>
                <?php if ( empty( $gs_dv_sq['rows'] ) ) : ?>
                    <p class="gs-dv-empty"><?php esc_html_e( 'No sequences synced from the desktop app yet — build one in the desktop Sequences tab (or the chat widget) and it appears here with its run cost.', 'gend-society' ); ?></p>
                    <button type="button" class="gs-dv-btn gs-dv-btn--sm" data-gs-dv-seq-new style="margin-top:10px;"><?php esc_html_e( '＋ Add Sequence', 'gend-society' ); ?></button>
                <?php else : ?>
                    <div class="gs-dv-stat-row" style="margin-bottom:16px;">
                        <div class="gs-dv-stat">
                            <p class="k"><?php esc_html_e( 'Connected Sequences', 'gend-society' ); ?></p>
                            <div class="v"><?php echo esc_html( number_format_i18n( count( $gs_dv_sq['rows'] ) ) ); ?></div>
                        </div>
                        <div class="gs-dv-stat">
                            <p class="k"><?php esc_html_e( 'Est. Cost — Run All Once', 'gend-society' ); ?></p>
                            <div class="v"><?php echo esc_html( gend_society_davinci_money( $gs_dv_sq['totalUsd'] ) ); ?></div>
                        </div>
                        <div class="gs-dv-stat">
                            <p class="k"><?php esc_html_e( 'Est. Leo Tokens / Run All', 'gend-society' ); ?></p>
                            <div class="v"><?php echo esc_html( number_format_i18n( round( $gs_dv_sq['totalTokens'] ) ) ); ?></div>
                        </div>
                        <div class="gs-dv-stat">
                            <p class="k"><?php esc_html_e( 'Free Device Steps', 'gend-society' ); ?></p>
                            <div class="v"><?php echo esc_html( number_format_i18n( $gs_dv_sq['freeSteps'] ) ); ?></div>
                        </div>
                    </div>
                    <div class="gs-dvm-toolbar" style="justify-content:flex-end;">
                        <button type="button" class="gs-dv-btn gs-dv-btn--sm" data-gs-dv-seq-new><?php esc_html_e( '＋ Add Sequence', 'gend-society' ); ?></button>
                    </div>
                    <div class="gs-dvm-tablewrap">
                        <table class="gs-dvm-table">
                            <thead><tr>
                                <th><?php esc_html_e( 'Sequence', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'Agent', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'Steps', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'gend.me AI Steps', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'Device / Terminal Steps', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'Est. Cost / Run', 'gend-society' ); ?></th>
                                <th><?php esc_html_e( 'Est. Leo Tokens / Run', 'gend-society' ); ?></th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ( $gs_dv_sq['rows'] as $sq ) : ?>
                                <tr>
                                    <td><strong style="color:#f1f5f9;"><?php echo esc_html( $sq['name'] ); ?></strong></td>
                                    <td><span class="gs-dvm-role"><?php echo esc_html( $sq['agent'] ?: '—' ); ?></span></td>
                                    <td><?php echo esc_html( number_format_i18n( $sq['steps'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $sq['ai'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $sq['free'] ) ); ?></td>
                                    <td><span class="gs-dvm-bal"><?php echo esc_html( gend_society_davinci_money( $sq['usd'] ) ); ?></span></td>
                                    <td><?php echo esc_html( number_format_i18n( round( $sq['tokens'] ) ) ); ?></td>
                                    <td><button type="button" class="gs-dvm-pill is-on" data-gs-dv-seq-edit="<?php echo esc_attr( $sq['id'] ); ?>">✎ <?php esc_html_e( 'Edit', 'gend-society' ); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="gs-dv-sum-foot" style="margin-top:10px;"><?php esc_html_e( 'Estimates assume ~1,500 output tokens per gend.me AI step at current Leo pricing; device and Claude-terminal steps cost gend.me nothing. Actual charges are metered per real token at run time.', 'gend-society' ); ?></p>
                <?php endif; ?>
            </section>
            <script>
            /* Add/Edit hand off to the chat widget's sequence studio — the
               SAME popups as the widget's Agents → Sequences pane (＋New
               Sequence prompt + the full step-studio editor). Capture-phase
               + window guard so AJAX tab re-grafts never double-bind. */
            (function () {
                if (window.__gsDvSeqBtns) { return; }
                window.__gsDvSeqBtns = true;
                document.addEventListener('click', function (e) {
                    var nb = e.target && e.target.closest && e.target.closest('[data-gs-dv-seq-new]');
                    var eb = e.target && e.target.closest && e.target.closest('[data-gs-dv-seq-edit]');
                    if (!nb && !eb) { return; }
                    var detail = nb
                        ? { action: 'new', headless: true, groupId: <?php echo (int) $group_id; ?> }
                        : { action: 'edit', headless: true, groupId: <?php echo (int) $group_id; ?>, workspaceId: eb.getAttribute('data-gs-dv-seq-edit') };
                    if (window.emSeqStudioOpen) { window.emSeqStudioOpen(detail); }
                    else { window.alert('<?php echo esc_js( __( 'The chat widget is still loading — try again in a second.', 'gend-society' ) ); ?>'); }
                }, true);
            })();
            </script>
        </div><!-- /subpanel:sequences -->

        <!-- ───────── Sub-panel: API TOKENS ───────── -->
        <div class="gs-dv-subpanel" data-gs-dv-subpanel="api">
        <!-- ───────── 3. API Integration Costs ───────── -->
        <section class="gs-dv-panel">
            <p class="gs-dv-eyebrow" style="--accent:var(--dv-gold);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <?php esc_html_e( 'Section 03', 'gend-society' ); ?>
            </p>
            <div class="gs-dv-panel-head">
                <div>
                    <h3><?php esc_html_e( 'API Integration Costs', 'gend-society' ); ?></h3>
                    <p class="lede"><?php esc_html_e( 'Log any independent AI integrations or AI memberships you pay for off-platform (OpenAI, Anthropic, a vector DB, a transcription API…). They roll into your total AI spend so nothing is hidden.', 'gend-society' ); ?></p>
                </div>
                <div class="gs-dv-stat" style="min-width:170px;">
                    <p class="k"><?php esc_html_e( 'Logged / mo', 'gend-society' ); ?></p>
                    <div class="v" data-gs-dv-api-monthly2><?php echo esc_html( gend_society_davinci_money( $api_monthly ) ); ?></div>
                </div>
            </div>

            <div style="overflow-x:auto;">
            <table class="gs-dv-table" data-gs-dv-api-table>
                <thead><tr>
                    <th style="width:24%;"><?php esc_html_e( 'Integration', 'gend-society' ); ?></th>
                    <th style="width:18%;"><?php esc_html_e( 'Vendor', 'gend-society' ); ?></th>
                    <th style="width:14%;"><?php esc_html_e( 'Amount', 'gend-society' ); ?></th>
                    <th style="width:14%;"><?php esc_html_e( 'Cadence', 'gend-society' ); ?></th>
                    <th><?php esc_html_e( 'Note', 'gend-society' ); ?></th>
                    <th style="width:44px;"></th>
                </tr></thead>
                <tbody data-gs-dv-api-body><!-- rows injected by JS --></tbody>
            </table>
            </div>

            <div class="gs-dv-save-bar">
                <button type="button" class="gs-dv-btn gs-dv-btn--sm" data-gs-dv-api-add>+ <?php esc_html_e( 'Add Integration', 'gend-society' ); ?></button>
                <button type="button" class="gs-dv-cta gs-dv-cta--sm" data-gs-dv-api-save><?php esc_html_e( 'Save Costs', 'gend-society' ); ?></button>
                <span class="gs-dv-save-status" data-gs-dv-api-status aria-live="polite"></span>
            </div>
        </section>

        </div><!-- /subpanel:api -->

        <!-- ───────── Sub-panel: COMPUTE GAS ───────── -->
        <div class="gs-dv-subpanel" data-gs-dv-subpanel="compute">
        <!-- ───────── 4. Compute Costs ───────── -->
        <section class="gs-dv-panel">
            <p class="gs-dv-eyebrow" style="--accent:var(--dv-green);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <?php esc_html_e( 'Section 04', 'gend-society' ); ?>
            </p>
            <div class="gs-dv-panel-head">
                <div>
                    <h3><?php esc_html_e( 'Compute Costs', 'gend-society' ); ?></h3>
                    <p class="lede"><?php esc_html_e( 'When your web app runs its AI models through the gend.me blockchain, every model invocation settles on-chain. This is the gas paid for that AI compute this billing period.', 'gend-society' ); ?></p>
                </div>
                <button type="button" class="gs-dv-btn gs-dv-btn--sm" data-gs-dv-cg-refresh><?php esc_html_e( 'Refresh Ledger', 'gend-society' ); ?></button>
            </div>

            <?php if ( $compute_active ) : ?>
                <div class="gs-dv-stat-row">
                    <div class="gs-dv-stat">
                        <p class="k"><?php esc_html_e( 'AI Compute Gas', 'gend-society' ); ?></p>
                        <div class="v" data-gs-dv-cg-cell="gas">$0.00</div>
                    </div>
                    <div class="gs-dv-stat">
                        <p class="k"><?php esc_html_e( 'Container / Runtime Gas', 'gend-society' ); ?></p>
                        <div class="v" data-gs-dv-cg-cell="container">$0.00</div>
                    </div>
                    <div class="gs-dv-stat">
                        <p class="k"><?php esc_html_e( 'Total Compute Gas', 'gend-society' ); ?></p>
                        <div class="v" data-gs-dv-cg-cell="total">$0.00</div>
                    </div>
                </div>
                <p class="gs-dv-sum-foot" data-gs-dv-cg-period><?php esc_html_e( 'Current billing period', 'gend-society' ); ?></p>
            <?php else : ?>
                <p class="gs-dv-empty" style="margin-bottom:16px;">
                    <?php esc_html_e( 'This web app isn’t running its AI through the gend.me blockchain yet. Move the linked app to a Networked / Containers / Server hosting plan to meter AI model compute on-chain and pay only for what you use — no flat monthly compute fees.', 'gend-society' ); ?>
                </p>
                <?php if ( $compute_plan_url ) : ?>
                    <a class="gs-dv-cta gs-dv-cta--sm" href="<?php echo esc_url( $compute_plan_url ); ?>"><?php esc_html_e( 'Upgrade Hosting Plan', 'gend-society' ); ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        </div><!-- /subpanel:compute -->

    </div>

    <script>
    (function(){
        var root = document.getElementById('<?php echo esc_js( $scope ); ?>');
        if (!root) return;
        var ajax      = <?php echo wp_json_encode( $ajax_url ); ?>;
        var groupId   = <?php echo (int) $group_id; ?>;
        var apiNonce  = <?php echo wp_json_encode( $api_nonce ); ?>;
        var cgNonce   = <?php echo wp_json_encode( $cg_nonce ); ?>;
        var apiRows   = <?php echo wp_json_encode( array_values( $api_costs ) ); ?>;
        var knownMonthly = parseFloat(root.getAttribute('data-known-monthly')) || 0;

        function money(n){ return '$' + (Math.round((n||0)*100)/100).toFixed(2); }

        /* ── 3. API integration costs editor ── */
        var body   = root.querySelector('[data-gs-dv-api-body]');
        var addBtn = root.querySelector('[data-gs-dv-api-add]');
        var saveBtn= root.querySelector('[data-gs-dv-api-save]');
        var status = root.querySelector('[data-gs-dv-api-status]');

        function rowHtml(r){
            r = r || {};
            var cadences = [['monthly','<?php echo esc_js( __( 'Monthly', 'gend-society' ) ); ?>'],['yearly','<?php echo esc_js( __( 'Yearly', 'gend-society' ) ); ?>'],['one-time','<?php echo esc_js( __( 'One-time', 'gend-society' ) ); ?>']];
            var opts = cadences.map(function(c){ return '<option value="'+c[0]+'"'+((r.cadence||'monthly')===c[0]?' selected':'')+'>'+c[1]+'</option>'; }).join('');
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input class="gs-dv-in" data-f="label" value="'+escapeAttr(r.label)+'" placeholder="<?php echo esc_js( __( 'e.g. GPT-4 API', 'gend-society' ) ); ?>"></td>'+
                '<td><input class="gs-dv-in" data-f="vendor" value="'+escapeAttr(r.vendor)+'" placeholder="<?php echo esc_js( __( 'OpenAI', 'gend-society' ) ); ?>"></td>'+
                '<td><input class="gs-dv-in" data-f="amount" type="number" min="0" step="0.01" value="'+escapeAttr(r.amount)+'"></td>'+
                '<td><select class="gs-dv-in" data-f="cadence">'+opts+'</select></td>'+
                '<td><input class="gs-dv-in" data-f="note" value="'+escapeAttr(r.note)+'" placeholder="<?php echo esc_js( __( 'optional', 'gend-society' ) ); ?>"></td>'+
                '<td><button type="button" class="gs-dv-rm" aria-label="Remove">&times;</button></td>';
            tr.querySelector('.gs-dv-rm').addEventListener('click', function(){ tr.remove(); recalc(); });
            tr.addEventListener('input', recalc);
            return tr;
        }
        function escapeAttr(v){ return String(v==null?'':v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

        function collect(){
            var rows = [];
            body.querySelectorAll('tr').forEach(function(tr){
                var o = {};
                tr.querySelectorAll('[data-f]').forEach(function(el){ o[el.getAttribute('data-f')] = el.value; });
                o.amount = parseFloat(o.amount)||0;
                if ((o.label||'').trim()==='' && o.amount<=0) return;
                rows.push(o);
            });
            return rows;
        }
        function monthlyOf(rows){
            return rows.reduce(function(s,r){
                var a = parseFloat(r.amount)||0;
                if (r.cadence==='yearly') return s + a/12;
                if (r.cadence==='one-time') return s;
                return s + a;
            },0);
        }
        function recalc(){
            var rows = collect();
            var m = monthlyOf(rows);
            root.querySelectorAll('[data-gs-dv-api-monthly],[data-gs-dv-api-monthly2]').forEach(function(el){ el.textContent = money(m); });
            // Recompute the headline total: knownMonthly already included the
            // server-rendered API figure, so swap it out for the live one.
            var base = knownMonthly - (parseFloat(root.dataset.apiBaseline)||<?php echo json_encode( (float) $api_monthly ); ?>);
            var totalEl = root.querySelector('[data-gs-dv-total]');
            if (totalEl) {
                var cg = parseFloat(root.dataset.cgTotal)||0;
                totalEl.textContent = money(base + m + cg);
            }
        }

        if (body){
            (apiRows && apiRows.length ? apiRows : []).forEach(function(r){ body.appendChild(rowHtml(r)); });
        }
        if (addBtn){ addBtn.addEventListener('click', function(){ body.appendChild(rowHtml({cadence:'monthly'})); }); }
        if (saveBtn){
            saveBtn.addEventListener('click', function(){
                var rows = collect();
                status.textContent = '<?php echo esc_js( __( 'Saving…', 'gend-society' ) ); ?>';
                status.className = 'gs-dv-save-status';
                var b = new URLSearchParams({ action:'gend_society_davinci_save_api_costs', nonce:apiNonce, group_id:groupId, rows:JSON.stringify(rows) });
                fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()})
                    .then(function(r){return r.json();})
                    .then(function(resp){
                        if (resp && resp.success){
                            status.textContent = '<?php echo esc_js( __( 'Saved', 'gend-society' ) ); ?>';
                            status.className = 'gs-dv-save-status is-ok';
                            if (resp.data && resp.data.monthly_label){
                                root.querySelectorAll('[data-gs-dv-api-monthly],[data-gs-dv-api-monthly2]').forEach(function(el){ el.textContent = resp.data.monthly_label; });
                            }
                        } else {
                            status.textContent = '<?php echo esc_js( __( 'Save failed', 'gend-society' ) ); ?>';
                        }
                    })
                    .catch(function(){ status.textContent = '<?php echo esc_js( __( 'Save failed', 'gend-society' ) ); ?>'; });
            });
        }

        /* ── 4. Compute gas fetch (reuses gs_hosting_compute_gas) ── */
        var cgBtn = root.querySelector('[data-gs-dv-cg-refresh]');
        function loadComputeGas(){
            var cells = {
                gas:       root.querySelector('[data-gs-dv-cg-cell="gas"]'),
                container: root.querySelector('[data-gs-dv-cg-cell="container"]'),
                total:     root.querySelector('[data-gs-dv-cg-cell="total"]'),
                period:    root.querySelector('[data-gs-dv-cg-period]')
            };
            if (!cells.gas && !cells.total) return; // gated state — nothing to fetch
            if (cgBtn){ cgBtn.disabled = true; }
            var b = new URLSearchParams({ action:'gend_society_hosting_compute_gas', nonce:cgNonce });
            fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()})
                .then(function(r){return r.json();})
                .then(function(resp){
                    var d = (resp && resp.success && resp.data) ? resp.data : {};
                    if (cells.gas)       cells.gas.textContent       = d.gas_fees_label       || '$0.00';
                    if (cells.container) cells.container.textContent = d.container_fees_label  || '$0.00';
                    if (cells.total)     cells.total.textContent     = d.total_label          || d.gas_fees_label || '$0.00';
                    if (cells.period && d.period) cells.period.textContent = d.period;
                    // Fold the compute-gas total into the headline figure.
                    var t = parseFloat(String(d.total_label||d.gas_fees_label||'0').replace(/[^0-9.\-]/g,''))||0;
                    root.dataset.cgTotal = t;
                    recalc();
                })
                .catch(function(){})
                .then(function(){ if (cgBtn){ cgBtn.disabled = false; } });
        }
        if (cgBtn){ cgBtn.addEventListener('click', loadComputeGas); }
        loadComputeGas();

        /* ── Sub-tabs (Members / Usage & Spend) + the Members panel ── */
        (function(){
            var tabs = root.querySelectorAll('.gs-dv-subtab');
            var panels = root.querySelectorAll('.gs-dv-subpanel');
            tabs.forEach(function(t){
                t.addEventListener('click', function(){
                    var key = t.getAttribute('data-gs-dv-subtab');
                    tabs.forEach(function(b){ b.classList.toggle('is-active', b===t); b.setAttribute('aria-selected', b===t ? 'true':'false'); });
                    panels.forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-gs-dv-subpanel')===key); });
                });
                // 3D tilt + cursor-following glow (matches the bridge-header
                // panels): --mx/--my drive the ::after radial, the transform
                // tilts toward the cursor.
                t.addEventListener('mousemove', function(e){
                    var r = t.getBoundingClientRect();
                    var x = e.clientX - r.left, y = e.clientY - r.top;
                    t.style.setProperty('--mx', x + 'px');
                    t.style.setProperty('--my', y + 'px');
                    var rx = ((y - r.height / 2) / r.height) * -10;
                    var ry = ((x - r.width / 2) / r.width) * 10;
                    t.style.transform = 'perspective(700px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) translateY(-2px)';
                });
                t.addEventListener('mouseleave', function(){ t.style.transform = ''; });
            });

            var mp = root.querySelector('[data-gs-dvm]');
            if (!mp) return;
            var nonce = mp.getAttribute('data-nonce');
            var body = mp.querySelector('[data-dvm-body]');
            var statusEl = mp.querySelector('[data-dvm-status]');
            var walletEl = mp.querySelector('[data-dvm-wallet]');
            var searchEl = mp.querySelector('[data-dvm-search]');
            // Contract controls live in their own "Purchase Contracts"
            // sub-panel (a sibling of the members panel) — query the page
            // scope, not mp.
            var cWrap = root.querySelector('[data-dvm-contracts]');
            var cStatus = root.querySelector('[data-dvm-cstatus]');
            var cTarget = root.querySelector('[data-dvm-ctarget]');
            var cMembers = root.querySelector('[data-dvm-cmembers]');
            var cMembersWrap = root.querySelector('[data-dvm-cmembers-wrap]');
            var cRole = root.querySelector('[data-dvm-crole]');
            var cRoleWrap = root.querySelector('[data-dvm-crole-wrap]');
            var cPackage = root.querySelector('[data-dvm-cpackage]');
            var cCadence = root.querySelector('[data-dvm-ccadence]');
            var packages = [], roles = {}, members = [], contracts = [], adminDgen = 0;
            var CADENCE_LBL = { daily:'<?php echo esc_js( __( 'Daily', 'gend-society' ) ); ?>', weekly:'<?php echo esc_js( __( 'Weekly', 'gend-society' ) ); ?>', monthly:'<?php echo esc_js( __( 'Monthly', 'gend-society' ) ); ?>' };

            function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
            function fmtN(n){ return (Number(n)||0).toLocaleString(); }
            function say(el, txt, ok){ if(!el) return; el.textContent = txt||''; el.className = 'gs-dvm-status' + (txt ? (ok ? ' is-ok':' is-err'):''); }
            function pkgById(id){ return packages.filter(function(pk){ return String(pk.id)===String(id); })[0] || null; }
            function pkgOpts(sel){ return packages.map(function(pk){ return '<option value="'+esc(pk.id)+'"'+(String(sel)===String(pk.id)?' selected':'')+'>'+esc(pk.name)+' — $'+fmtN(pk.price)+'</option>'; }).join(''); }

            function renderMembers(){
                if (!body) return;
                if (!members.length){ body.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#64748b;"><?php echo esc_js( __( 'No members found.', 'gend-society' ) ); ?></td></tr>'; return; }
                body.innerHTML = members.map(function(m){
                    return '<tr data-mid="'+esc(m.id)+'">'
                        + '<td><span class="gs-dvm-member"><img src="'+esc(m.avatar)+'" alt="" /><span><strong>'+esc(m.name)+'</strong><br><small style="color:#64748b;">'+esc(m.email||'')+'</small></span></span></td>'
                        + '<td><span class="gs-dvm-role">'+esc((m.roles||[]).join(', ')||'—')+'</span></td>'
                        + '<td><span class="gs-dvm-bal" data-bal>'+fmtN(m.balance)+'</span></td>'
                        + '<td><span class="gs-dvm-buyrow"><select class="gs-dvm-sel" data-pkg>'+pkgOpts('')+'</select>'
                        + '<button type="button" class="gs-dvm-buy" data-buy><?php echo esc_js( __( 'Buy', 'gend-society' ) ); ?></button></span></td>'
                        + '</tr>';
                }).join('');
            }
            function renderWallet(){ if (walletEl) walletEl.textContent = '<?php echo esc_js( __( 'Your DGEN:', 'gend-society' ) ); ?> ' + fmtN(adminDgen); }
            function renderContractForm(){
                // Only overwrite the server-rendered options when the AJAX
                // bootstrap actually returned data — an empty/failed load
                // must never blank a working dropdown.
                if (cMembers && members.length) cMembers.innerHTML = members.map(function(m){ return '<option value="'+esc(m.id)+'">'+esc(m.name)+'</option>'; }).join('');
                if (cRole && Object.keys(roles).length) cRole.innerHTML = Object.keys(roles).map(function(k){ return '<option value="'+esc(k)+'">'+esc(roles[k])+'</option>'; }).join('');
                if (cPackage && packages.length) cPackage.innerHTML = pkgOpts('');
            }
            function contractLabel(c){
                var pk = pkgById(c.packageId);
                var whoRoles = Array.isArray(c.roles) && c.roles.length ? c.roles : (c.role ? [c.role] : []);
                var who = c.targetType==='role'
                    ? ('<?php echo esc_js( __( 'Roles:', 'gend-society' ) ); ?> ' + whoRoles.map(function(rk){ return roles[rk]||rk; }).join(', '))
                    : ((c.memberNames && c.memberNames.length ? c.memberNames.join(', ') : (c.memberIds||[]).length + ' <?php echo esc_js( __( 'members', 'gend-society' ) ); ?>'));
                return { who: who, pk: pk ? pk.name : ('#'+c.packageId) };
            }
            function renderContracts(){
                if (!cWrap) return;
                if (!contracts.length){ cWrap.innerHTML = '<p class="gs-dv-empty" style="margin:0 0 12px;"><?php echo esc_js( __( 'No purchase contracts yet — add the first one with the button below.', 'gend-society' ) ); ?></p>'; return; }
                cWrap.innerHTML = contracts.map(function(c){
                    var L = contractLabel(c);
                    return '<div class="gs-dvm-contract" data-cid="'+esc(c.id)+'">'
                        + '<span class="meta"><strong>'+esc(L.pk)+' · '+(CADENCE_LBL[c.cadence]||esc(c.cadence))+'</strong>'
                        + '<small>'+esc(L.who) + (c.lastNote ? ' · ' + esc(c.lastNote) : '') + (c.nextRunAt ? ' · <?php echo esc_js( __( 'next', 'gend-society' ) ); ?> ' + esc(new Date(c.nextRunAt*1000).toLocaleDateString()) : '') + '</small></span>'
                        + '<button type="button" class="gs-dvm-pill is-on" data-cedit>✎ <?php echo esc_js( __( 'Edit', 'gend-society' ) ); ?></button>'
                        + '<button type="button" class="gs-dvm-pill'+(c.active?' is-on':'')+'" data-ctoggle>'+(c.active?'<?php echo esc_js( __( 'Active', 'gend-society' ) ); ?>':'<?php echo esc_js( __( 'Paused', 'gend-society' ) ); ?>')+'</button>'
                        + '<button type="button" class="gs-dvm-pill" data-cdel>✕</button>'
                        + '</div>';
                }).join('');
            }
            function post(action, data){
                var b = new URLSearchParams(Object.assign({ action: action, nonce: nonce, group_id: groupId }, data||{}));
                return fetch(ajax, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:b.toString() })
                    .then(function(r){ return r.json(); });
            }
            var loadTimer = null;
            function loadMembers(q){
                post('gend_society_dv_leo_members', { q: q||'' }).then(function(resp){
                    if (!resp || !resp.success){
                        var msg = (resp && resp.data && resp.data.message) || '<?php echo esc_js( __( 'Could not load members.', 'gend-society' ) ); ?>';
                        console.error('[gs-dv] members load failed:', resp);
                        say(statusEl, msg, false);
                        say(cStatus, '<?php echo esc_js( __( 'Member list unavailable:', 'gend-society' ) ); ?> ' + msg, false);
                        return;
                    }
                    var d = resp.data || {};
                    members = d.members||[]; packages = d.packages||[]; roles = d.roles||{}; contracts = d.contracts||[]; adminDgen = Number(d.adminDgen)||0;
                    renderMembers(); renderWallet(); renderContractForm(); renderContracts(); say(statusEl, ''); say(cStatus, '');
                }).catch(function(err){
                    console.error('[gs-dv] members load error:', err);
                    say(statusEl, '<?php echo esc_js( __( 'Network error loading members.', 'gend-society' ) ); ?>', false);
                    say(cStatus, '<?php echo esc_js( __( 'Network error — member picker unavailable. Reload to retry.', 'gend-society' ) ); ?>', false);
                });
            }
            if (searchEl){ searchEl.addEventListener('input', function(){ clearTimeout(loadTimer); loadTimer = setTimeout(function(){ loadMembers(searchEl.value.trim()); }, 300); }); }
            if (cTarget){ cTarget.addEventListener('change', function(){
                var isRole = cTarget.value === 'role';
                if (cMembersWrap) cMembersWrap.hidden = isRole;
                if (cRoleWrap) cRoleWrap.hidden = !isRole;
            }); }
            if (body){ body.addEventListener('click', function(e){
                var btn = e.target && e.target.closest && e.target.closest('[data-buy]');
                if (!btn) return;
                var tr = btn.closest('tr');
                var mid = tr && tr.getAttribute('data-mid');
                var sel = tr && tr.querySelector('[data-pkg]');
                var pk = pkgById(sel && sel.value);
                var m = members.filter(function(x){ return String(x.id)===String(mid); })[0];
                if (!pk || !m) return;
                if (!window.confirm('<?php echo esc_js( __( 'Buy', 'gend-society' ) ); ?> ' + pk.name + ' <?php echo esc_js( __( 'for', 'gend-society' ) ); ?> ' + m.name + ' — ' + fmtN(pk.price) + ' DGEN <?php echo esc_js( __( 'from your wallet?', 'gend-society' ) ); ?>')) return;
                btn.disabled = true;
                post('gend_society_dv_leo_buy', { member_id: mid, package_id: pk.id }).then(function(resp){
                    btn.disabled = false;
                    if (!resp || !resp.success){ say(statusEl, (resp && resp.data && resp.data.message) || '<?php echo esc_js( __( 'Purchase failed.', 'gend-society' ) ); ?>', false); return; }
                    var d = resp.data||{};
                    m.balance = d.balance; adminDgen = Number(d.adminDgen)||adminDgen;
                    var balEl = tr.querySelector('[data-bal]');
                    if (balEl) balEl.textContent = fmtN(d.balance);
                    renderWallet();
                    say(statusEl, '✓ ' + fmtN(d.tokens) + ' <?php echo esc_js( __( 'Leo Tokens added for', 'gend-society' ) ); ?> ' + m.name, true);
                }).catch(function(){ btn.disabled = false; say(statusEl, '<?php echo esc_js( __( 'Network error.', 'gend-society' ) ); ?>', false); });
            }); }
            // ── Contract editor popup: AJAX member search-and-select,
            // create + edit (contract_id upsert). ──
            var cModal = root.querySelector('[data-dvm-cmodal]');
            var cmChips = cModal ? cModal.querySelector('[data-cm-chips]') : null;
            var cmSearch = cModal ? cModal.querySelector('[data-cm-search]') : null;
            var cmResults = cModal ? cModal.querySelector('[data-cm-results]') : null;
            var cmSave = cModal ? cModal.querySelector('[data-cm-save]') : null;
            var cmTitle = cModal ? cModal.querySelector('[data-cm-title]') : null;
            var cmStatus = cModal ? cModal.querySelector('[data-cm-status]') : null;
            var cmSelected = []; var cmEditId = ''; var cmSearchTimer = null;
            var cmTargetVal = 'members'; var cmRoles = []; var cmCad = 'monthly';
            function cmSyncControls(){
                if (!cModal) return;
                cModal.querySelectorAll('[data-cm-target-btn]').forEach(function(b){
                    b.classList.toggle('is-on', b.getAttribute('data-cm-target-btn') === cmTargetVal);
                });
                cModal.querySelectorAll('[data-cm-role]').forEach(function(b){
                    b.classList.toggle('is-on', cmRoles.indexOf(b.getAttribute('data-cm-role')) !== -1);
                });
                cModal.querySelectorAll('[data-cm-cad]').forEach(function(b){
                    b.classList.toggle('is-on', b.getAttribute('data-cm-cad') === cmCad);
                });
                if (cMembersWrap) cMembersWrap.hidden = cmTargetVal === 'role';
                if (cRoleWrap) cRoleWrap.hidden = cmTargetVal !== 'role';
            }
            function cmRenderChips(){
                if (!cmChips) return;
                cmChips.innerHTML = cmSelected.map(function(m, i){
                    return '<span class="gs-dvm-chip">'
                        + (m.avatar ? '<img src="'+esc(m.avatar)+'" alt="" />' : '')
                        + esc(m.name)
                        + '<button type="button" data-cm-chip-x="'+i+'" aria-label="Remove">×</button>'
                        + '</span>';
                }).join('');
            }
            function cmOpen(contract){
                if (!cModal) return;
                cmEditId = contract ? String(contract.id) : '';
                if (cmTitle) cmTitle.textContent = contract ? '<?php echo esc_js( __( 'Edit Purchase Contract', 'gend-society' ) ); ?>' : '<?php echo esc_js( __( 'New Purchase Contract', 'gend-society' ) ); ?>';
                cmTargetVal = (contract && contract.targetType === 'role') ? 'role' : 'members';
                cmRoles = contract
                    ? (Array.isArray(contract.roles) && contract.roles.length ? contract.roles.slice() : (contract.role ? [contract.role] : []))
                    : [];
                cmCad = (contract && contract.cadence) || 'monthly';
                if (cPackage && contract && contract.packageId != null) cPackage.value = String(contract.packageId);
                cmSelected = [];
                if (contract && Array.isArray(contract.memberIds)){
                    contract.memberIds.forEach(function(id, i){
                        var known = members.filter(function(m){ return String(m.id) === String(id); })[0];
                        cmSelected.push({
                            id: String(id),
                            name: known ? known.name : ((contract.memberNames && contract.memberNames[i]) || ('#' + id)),
                            avatar: known ? known.avatar : ''
                        });
                    });
                }
                cmRenderChips();
                if (cmSearch) cmSearch.value = '';
                if (cmResults) cmResults.setAttribute('hidden', 'hidden');
                say(cmStatus, '');
                cmSyncControls();
                cModal.removeAttribute('hidden');
                gsDvUnhijack();
            }
            function cmClose(){ if (cModal) cModal.setAttribute('hidden', 'hidden'); }
            var cNew = root.querySelector('[data-dvm-cnew]');
            if (cNew){ cNew.addEventListener('click', function(){ cmOpen(null); }); }
            if (cModal){
                cModal.addEventListener('click', function(e){
                    if (e.target.closest('[data-cm-close]')) { cmClose(); return; }
                    var tBtn = e.target.closest('[data-cm-target-btn]');
                    if (tBtn){ cmTargetVal = tBtn.getAttribute('data-cm-target-btn'); cmSyncControls(); return; }
                    var rPill = e.target.closest('[data-cm-role]');
                    if (rPill){
                        var rv = rPill.getAttribute('data-cm-role');
                        var ri = cmRoles.indexOf(rv);
                        if (ri === -1) cmRoles.push(rv); else cmRoles.splice(ri, 1);
                        cmSyncControls(); return;
                    }
                    if (e.target.closest('[data-cm-roles-all]')){
                        var allR = [];
                        cModal.querySelectorAll('[data-cm-role]').forEach(function(b){ allR.push(b.getAttribute('data-cm-role')); });
                        cmRoles = (cmRoles.length === allR.length) ? [] : allR;
                        cmSyncControls(); return;
                    }
                    var cadBtn = e.target.closest('[data-cm-cad]');
                    if (cadBtn){ cmCad = cadBtn.getAttribute('data-cm-cad'); cmSyncControls(); return; }
                    var chipX = e.target.closest('[data-cm-chip-x]');
                    if (chipX){ cmSelected.splice(parseInt(chipX.getAttribute('data-cm-chip-x'), 10), 1); cmRenderChips(); return; }
                    var row = e.target.closest('[data-cm-add]');
                    if (row){
                        var mid = row.getAttribute('data-cm-add');
                        if (!cmSelected.some(function(m){ return String(m.id) === String(mid); })){
                            cmSelected.push({ id: mid, name: row.getAttribute('data-cm-name') || ('#'+mid), avatar: row.getAttribute('data-cm-avatar') || '' });
                            cmRenderChips();
                        }
                        if (cmResults) cmResults.setAttribute('hidden', 'hidden');
                        if (cmSearch) { cmSearch.value = ''; cmSearch.focus(); }
                    }
                });
                document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && cModal && !cModal.hasAttribute('hidden')) cmClose(); });
            }
            if (cmSearch){
                cmSearch.addEventListener('input', function(){
                    clearTimeout(cmSearchTimer);
                    var q = cmSearch.value.trim();
                    cmSearchTimer = setTimeout(function(){
                        if (!cmResults) return;
                        cmResults.removeAttribute('hidden');
                        cmResults.innerHTML = '<div class="gs-dvm-result-muted"><?php echo esc_js( __( 'Searching…', 'gend-society' ) ); ?></div>';
                        post('gend_society_dv_leo_members', { q: q }).then(function(resp){
                            var rows = (resp && resp.success && resp.data && resp.data.members) || [];
                            if (!rows.length){ cmResults.innerHTML = '<div class="gs-dvm-result-muted"><?php echo esc_js( __( 'No members match.', 'gend-society' ) ); ?></div>'; return; }
                            cmResults.innerHTML = rows.slice(0, 20).map(function(m){
                                return '<button type="button" class="gs-dvm-result-row" data-cm-add="'+esc(m.id)+'" data-cm-name="'+esc(m.name)+'" data-cm-avatar="'+esc(m.avatar)+'">'
                                    + '<img src="'+esc(m.avatar)+'" alt="" /><strong>'+esc(m.name)+'</strong>'
                                    + '<span style="margin-left:auto;color:#7dd3fc;font-size:.72rem;">'+esc(String(m.balance||0))+' <?php echo esc_js( __( 'Leo', 'gend-society' ) ); ?></span>'
                                    + '</button>';
                            }).join('');
                        }).catch(function(){ cmResults.innerHTML = '<div class="gs-dvm-result-muted"><?php echo esc_js( __( 'Search failed.', 'gend-society' ) ); ?></div>'; });
                    }, 280);
                });
            }
            if (cmSave){ cmSave.addEventListener('click', function(){
                var targetType = cmTargetVal;
                var memberIds = cmSelected.map(function(m){ return m.id; });
                if (targetType === 'members' && !memberIds.length){ say(cmStatus, '<?php echo esc_js( __( 'Pick at least one member.', 'gend-society' ) ); ?>', false); return; }
                if (targetType === 'role' && !cmRoles.length){ say(cmStatus, '<?php echo esc_js( __( 'Pick at least one role.', 'gend-society' ) ); ?>', false); return; }
                cmSave.disabled = true;
                post('gend_society_dv_leo_contract', {
                    op: 'save', contract_id: cmEditId,
                    target_type: targetType,
                    role: cmRoles[0] || '',
                    roles: cmRoles.join(','),
                    member_ids: memberIds.join(','),
                    package_id: cPackage ? cPackage.value : '',
                    cadence: cmCad
                }).then(function(resp){
                    cmSave.disabled = false;
                    if (!resp || !resp.success){ say(cmStatus, (resp && resp.data && resp.data.message) || '<?php echo esc_js( __( 'Could not save the contract.', 'gend-society' ) ); ?>', false); return; }
                    contracts = (resp.data && resp.data.contracts) || contracts;
                    renderContracts();
                    cmClose();
                    say(cStatus, cmEditId ? '<?php echo esc_js( __( 'Contract updated.', 'gend-society' ) ); ?>' : '<?php echo esc_js( __( 'Contract saved — first purchase fires within the hour.', 'gend-society' ) ); ?>', true);
                }).catch(function(){ cmSave.disabled = false; say(cmStatus, '<?php echo esc_js( __( 'Network error.', 'gend-society' ) ); ?>', false); });
            }); }
            if (cWrap){ cWrap.addEventListener('click', function(e){
                var row = e.target && e.target.closest && e.target.closest('[data-cid]');
                if (!row) return;
                var cid = row.getAttribute('data-cid');
                if (e.target.closest('[data-cedit]')){
                    var editC = contracts.filter(function(c){ return String(c.id) === String(cid); })[0];
                    if (editC) cmOpen(editC);
                    return;
                }
                var op = e.target.closest('[data-cdel]') ? 'delete' : (e.target.closest('[data-ctoggle]') ? 'toggle' : '');
                if (!op) return;
                if (op==='delete' && !window.confirm('<?php echo esc_js( __( 'Delete this purchase contract?', 'gend-society' ) ); ?>')) return;
                post('gend_society_dv_leo_contract', { op: op, contract_id: cid }).then(function(resp){
                    if (!resp || !resp.success){ say(cStatus, '<?php echo esc_js( __( 'Update failed.', 'gend-society' ) ); ?>', false); return; }
                    contracts = (resp.data && resp.data.contracts) || [];
                    renderContracts(); say(cStatus, '');
                }).catch(function(){ say(cStatus, '<?php echo esc_js( __( 'Network error.', 'gend-society' ) ); ?>', false); });
            }); }
            // Youzify hijack undo — niceSelect('destroy') restores the
            // native selects; leftover shells removed. Retried because
            // Youzify inits on its own ready tick.
            function gsDvUnhijack() {
                try {
                    if (window.jQuery && jQuery.fn && jQuery.fn.niceSelect) {
                        jQuery('[data-gs-dv-scope] select').niceSelect('destroy');
                    }
                } catch (e) {}
                try {
                    document.querySelectorAll('[data-gs-dv-scope] .nice-select').forEach(function (n) { n.remove(); });
                    document.querySelectorAll('[data-gs-dv-scope] select.gs-dvm-sel, [data-gs-dv-scope] select.gs-dv-in').forEach(function (s) {
                        if (s.style.display === 'none') { s.style.display = ''; }
                    });
                } catch (e) {}
            }
            gsDvUnhijack();
            setTimeout(gsDvUnhijack, 400);
            setTimeout(gsDvUnhijack, 1500);
            loadMembers('');
        })();
    })();
    </script>
    <?php
}


// ─────────────────────────────────────────────────────────────────────────────
// Davinci Architect → Leo → MEMBERS sub-tab
// Member Leo Token balances, one-click admin purchases (billed to the acting
// admin's DGEN wallet at 1 CAD = 1 DGEN), and scheduled Purchase Contracts
// (role- or member-targeted, daily/weekly/monthly) processed by an hourly
// cron on the hub. Contracts live in ONE site option keyed by group id so
// the cron never scans groupmeta.
// ─────────────────────────────────────────────────────────────────────────────

function gend_society_dv_leo_admin_ok( $group_id ) {
    if ( ! is_user_logged_in() ) return false;
    if ( current_user_can( 'manage_options' ) || is_super_admin() ) return true; // site-admin check, not a hub signal (104 audit)
    return function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( get_current_user_id(), (int) $group_id );
}

/** Published Leo Token package products (hub main site). */
function gend_society_dv_leo_packages() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, m.meta_value AS tokens, pr.meta_value AS price
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_aipa_token_package_amount'
         LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price'
         WHERE p.post_type = 'product' AND p.post_status = 'publish'
         ORDER BY (pr.meta_value + 0) ASC LIMIT 20", ARRAY_A
    );
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[] = array(
            'id'     => (int) $r['ID'],
            'name'   => (string) $r['post_title'],
            'tokens' => (float) $r['tokens'],
            'price'  => (float) $r['price'],
        );
    }
    return $out;
}

function gend_society_dv_leo_balance( $uid ) {
    if ( class_exists( 'AIPA_Usage' ) && method_exists( 'AIPA_Usage', 'get_balance' ) ) {
        return (float) AIPA_Usage::get_balance( (int) $uid );
    }
    return (float) get_user_meta( (int) $uid, 'aipa_credits', true );
}

function gend_society_dv_leo_credit( $uid, $tokens ) {
    if ( class_exists( 'AIPA_Usage' ) && method_exists( 'AIPA_Usage', 'add_credits' ) ) {
        AIPA_Usage::add_credits( (int) $uid, (float) $tokens );
        return;
    }
    $cur = (float) get_user_meta( (int) $uid, 'aipa_credits', true );
    update_user_meta( (int) $uid, 'aipa_credits', $cur + (float) $tokens );
}

function gend_society_dv_leo_dgen_balance( $uid ) {
    return function_exists( 'mycred_get_users_balance' )
        ? (float) mycred_get_users_balance( (int) $uid, 'transact' )
        : 0.0;
}

/**
 * Charge $amount DGEN from $payer for a Leo package bought for $member.
 * Returns true on success, WP_Error otherwise. Balance-checked first so a
 * short wallet never goes negative.
 */
function gend_society_dv_leo_charge( $payer, $amount, $member_id, $pkg_name ) {
    $amount = (float) $amount;
    if ( $amount <= 0 ) return true; // free package — nothing to charge
    if ( ! function_exists( 'mycred_add' ) ) {
        return new WP_Error( 'no_wallet', __( 'The DGEN wallet system is unavailable.', 'gend-society' ) );
    }
    if ( gend_society_dv_leo_dgen_balance( $payer ) < $amount ) {
        return new WP_Error( 'insufficient', __( 'Not enough DGEN in your wallet — top up and try again.', 'gend-society' ) );
    }
    $member = get_userdata( (int) $member_id );
    $ok = mycred_add(
        'leo_token_purchase_for_member',
        (int) $payer,
        -$amount,
        sprintf( 'Leo Tokens (%s) purchased for %s', $pkg_name, $member ? $member->display_name : ( '#' . (int) $member_id ) ),
        (int) $member_id,
        array( 'package' => $pkg_name ),
        'transact'
    );
    if ( ! $ok ) return new WP_Error( 'charge_failed', __( 'The DGEN charge failed.', 'gend-society' ) );
    return true;
}

// ── AJAX: members + packages + roles + contracts bootstrap ──
add_action( 'wp_ajax_gend_society_dv_leo_members', 'gend_society_dv_leo_members_ajax' );
function gend_society_dv_leo_members_ajax() {
    check_ajax_referer( 'gs_dv_leo', 'nonce' );
    $group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
    if ( ! $group_id || ! gend_society_dv_leo_admin_ok( $group_id ) ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );

    $site_id = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( $group_id, 'gdc_site_id', true ) : 0;
    if ( $site_id <= 0 ) $site_id = get_current_blog_id();

    $q    = isset( $_POST['q'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['q'] ) ) : '';
    $args = array(
        'blog_id' => $site_id,
        'number'  => 100,
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => 'all',
    );
    if ( $q !== '' ) {
        $args['search']         = '*' . $q . '*';
        $args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
    }
    $users = get_users( $args );

    $members = array();
    foreach ( $users as $u ) {
        $members[] = array(
            'id'      => (int) $u->ID,
            'name'    => (string) $u->display_name,
            'email'   => (string) $u->user_email,
            'avatar'  => get_avatar_url( $u->ID, array( 'size' => 68 ) ),
            'roles'   => array_map( 'strval', array_values( (array) $u->roles ) ),
            'balance' => gend_society_dv_leo_balance( $u->ID ),
        );
    }

    switch_to_blog( $site_id );
    $roles = array();
    foreach ( wp_roles()->get_names() as $rk => $rn ) { $roles[ $rk ] = translate_user_role( $rn ); }
    restore_current_blog();

    wp_send_json_success( array(
        'members'   => $members,
        'roles'     => $roles,
        'packages'  => gend_society_dv_leo_packages(),
        'contracts' => gend_society_dv_leo_contracts_for_group( $group_id ),
        'adminDgen' => gend_society_dv_leo_dgen_balance( get_current_user_id() ),
    ) );
}

// ── AJAX: buy a package for one member ──
add_action( 'wp_ajax_gend_society_dv_leo_buy', 'gend_society_dv_leo_buy_ajax' );
function gend_society_dv_leo_buy_ajax() {
    check_ajax_referer( 'gs_dv_leo', 'nonce' );
    $group_id  = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
    $member_id = isset( $_POST['member_id'] ) ? (int) $_POST['member_id'] : 0;
    $pkg_id    = isset( $_POST['package_id'] ) ? (int) $_POST['package_id'] : 0;
    if ( ! $group_id || ! gend_society_dv_leo_admin_ok( $group_id ) ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
    if ( ! $member_id || ! get_userdata( $member_id ) ) wp_send_json_error( array( 'message' => __( 'Member not found.', 'gend-society' ) ), 404 );

    $pkg = null;
    foreach ( gend_society_dv_leo_packages() as $cand ) { if ( $cand['id'] === $pkg_id ) { $pkg = $cand; break; } }
    if ( ! $pkg ) wp_send_json_error( array( 'message' => __( 'Token package not found.', 'gend-society' ) ), 404 );

    $charged = gend_society_dv_leo_charge( get_current_user_id(), $pkg['price'], $member_id, $pkg['name'] );
    if ( is_wp_error( $charged ) ) wp_send_json_error( array( 'message' => $charged->get_error_message() ), 402 );

    gend_society_dv_leo_credit( $member_id, $pkg['tokens'] );

    wp_send_json_success( array(
        'tokens'    => $pkg['tokens'],
        'balance'   => gend_society_dv_leo_balance( $member_id ),
        'adminDgen' => gend_society_dv_leo_dgen_balance( get_current_user_id() ),
    ) );
}

// ── Contract store: ONE network-visible option keyed by group id ──
function gend_society_dv_leo_contracts_all() {
    $all = get_site_option( 'gend_society_leo_purchase_contracts', array() );
    return is_array( $all ) ? $all : array();
}
function gend_society_dv_leo_contracts_for_group( $group_id ) {
    $all = gend_society_dv_leo_contracts_all();
    $list = isset( $all[ (int) $group_id ] ) && is_array( $all[ (int) $group_id ] ) ? array_values( $all[ (int) $group_id ] ) : array();
    // Resolve member display names for the UI.
    foreach ( $list as $i => $c ) {
        $names = array();
        foreach ( (array) ( $c['memberIds'] ?? array() ) as $mid ) {
            $u = get_userdata( (int) $mid );
            if ( $u ) $names[] = (string) $u->display_name;
        }
        $list[ $i ]['memberNames'] = $names;
    }
    return $list;
}
function gend_society_dv_leo_contracts_save_group( $group_id, $list ) {
    $all = gend_society_dv_leo_contracts_all();
    $list = array_values( array_filter( (array) $list, 'is_array' ) );
    if ( empty( $list ) ) unset( $all[ (int) $group_id ] );
    else $all[ (int) $group_id ] = $list;
    update_site_option( 'gend_society_leo_purchase_contracts', $all );
}
function gend_society_dv_leo_cadence_seconds( $cadence ) {
    if ( 'daily' === $cadence ) return DAY_IN_SECONDS;
    if ( 'weekly' === $cadence ) return WEEK_IN_SECONDS;
    return 30 * DAY_IN_SECONDS;
}

// ── AJAX: contract save / toggle / delete ──
add_action( 'wp_ajax_gend_society_dv_leo_contract', 'gend_society_dv_leo_contract_ajax' );
function gend_society_dv_leo_contract_ajax() {
    check_ajax_referer( 'gs_dv_leo', 'nonce' );
    $group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
    if ( ! $group_id || ! gend_society_dv_leo_admin_ok( $group_id ) ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );

    $op   = isset( $_POST['op'] ) ? sanitize_key( (string) $_POST['op'] ) : '';
    $list = array();
    foreach ( gend_society_dv_leo_contracts_for_group( $group_id ) as $c ) { unset( $c['memberNames'] ); $list[] = $c; }

    if ( 'save' === $op ) {
        $target = ( isset( $_POST['target_type'] ) && 'role' === $_POST['target_type'] ) ? 'role' : 'members';
        $ids    = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['member_ids'] ?? '' ) ) ) ) );
        $role   = sanitize_key( (string) ( $_POST['role'] ?? '' ) );
        $pkg_id = (int) ( $_POST['package_id'] ?? 0 );
        $cad    = in_array( $_POST['cadence'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ? (string) $_POST['cadence'] : 'monthly';
        if ( 'members' === $target && empty( $ids ) ) wp_send_json_error( array( 'message' => __( 'Pick at least one member.', 'gend-society' ) ), 400 );
        if ( 'role' === $target && '' === $role && '' === trim( (string) ( $_POST['roles'] ?? '' ) ) ) wp_send_json_error( array( 'message' => __( 'Pick at least one role.', 'gend-society' ) ), 400 );
        $pkg = null;
        foreach ( gend_society_dv_leo_packages() as $cand ) { if ( $cand['id'] === $pkg_id ) { $pkg = $cand; break; } }
        if ( ! $pkg ) wp_send_json_error( array( 'message' => __( 'Token package not found.', 'gend-society' ) ), 404 );
        $roles_multi = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $_POST['roles'] ?? '' ) ) ) ) );
        if ( empty( $roles_multi ) && $role !== '' ) $roles_multi = array( $role );
        $patch = array(
            'targetType' => $target,
            'role'       => $roles_multi ? $roles_multi[0] : $role,
            'roles'      => $roles_multi,
            'memberIds'  => $ids,
            'packageId'  => $pkg_id,
            'cadence'    => $cad,
        );
        // contract_id present → EDIT that contract in place (schedule
        // bookkeeping — createdBy/lastRunAt/nextRunAt — untouched).
        $cid = sanitize_text_field( (string) ( $_POST['contract_id'] ?? '' ) );
        $updated = false;
        if ( $cid !== '' ) {
            foreach ( $list as $i => $c ) {
                if ( ( $c['id'] ?? '' ) === $cid ) { $list[ $i ] = array_merge( $c, $patch ); $updated = true; break; }
            }
        }
        if ( ! $updated ) {
            $list[] = array_merge( array(
                'id'         => 'lc_' . time() . '_' . wp_generate_password( 6, false, false ),
                'active'     => true,
                'createdBy'  => get_current_user_id(),
                'createdAt'  => time(),
                'lastRunAt'  => 0,
                'nextRunAt'  => time(), // first purchase fires on the next hourly tick
                'lastNote'   => '',
            ), $patch );
        }
    } elseif ( 'toggle' === $op || 'delete' === $op ) {
        $cid = sanitize_text_field( (string) ( $_POST['contract_id'] ?? '' ) );
        $next = array();
        foreach ( $list as $c ) {
            if ( ( $c['id'] ?? '' ) === $cid ) {
                if ( 'delete' === $op ) continue;
                $c['active'] = empty( $c['active'] );
            }
            $next[] = $c;
        }
        $list = $next;
    } else {
        wp_send_json_error( array( 'message' => 'bad_op' ), 400 );
    }

    gend_society_dv_leo_contracts_save_group( $group_id, $list );
    wp_send_json_success( array( 'contracts' => gend_society_dv_leo_contracts_for_group( $group_id ) ) );
}

// ── Hourly cron: process due contracts ──
add_action( 'init', function () {
    if ( ! is_main_site() ) return;
    if ( ! wp_next_scheduled( 'gend_society_dv_leo_contracts_tick' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'gend_society_dv_leo_contracts_tick' );
    }
} );

add_action( 'gend_society_dv_leo_contracts_tick', 'gend_society_dv_leo_contracts_run' );
function gend_society_dv_leo_contracts_run() {
    $all = gend_society_dv_leo_contracts_all();
    if ( empty( $all ) ) return;
    $now = time();
    $dirty = false;
    foreach ( $all as $group_id => $list ) {
        if ( ! is_array( $list ) ) continue;
        foreach ( $list as $i => $c ) {
            if ( ! is_array( $c ) || empty( $c['active'] ) ) continue;
            if ( (int) ( $c['nextRunAt'] ?? 0 ) > $now ) continue;

            $pkg = null;
            foreach ( gend_society_dv_leo_packages() as $cand ) { if ( $cand['id'] === (int) ( $c['packageId'] ?? 0 ) ) { $pkg = $cand; break; } }
            if ( ! $pkg ) {
                $all[ $group_id ][ $i ]['lastNote'] = 'package missing — paused';
                $all[ $group_id ][ $i ]['active']   = false;
                $dirty = true;
                continue;
            }

            // Resolve target members.
            $ids = array();
            if ( ( $c['targetType'] ?? '' ) === 'role' ) {
                $site_id = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( (int) $group_id, 'gdc_site_id', true ) : 0;
                if ( $site_id <= 0 ) $site_id = get_current_blog_id();
                $c_roles = isset( $c['roles'] ) && is_array( $c['roles'] ) && $c['roles']
                    ? $c['roles']
                    : array( (string) ( $c['role'] ?? '' ) );
                foreach ( get_users( array( 'blog_id' => $site_id, 'role__in' => array_filter( $c_roles ), 'number' => 200, 'fields' => 'ID' ) ) as $uid ) {
                    $ids[] = (int) $uid;
                }
                $ids = array_values( array_unique( $ids ) );
            } else {
                $ids = array_map( 'intval', (array) ( $c['memberIds'] ?? array() ) );
            }

            $payer = (int) ( $c['createdBy'] ?? 0 );
            $ok = 0; $skip = 0;
            foreach ( $ids as $mid ) {
                if ( ! get_userdata( $mid ) ) { $skip++; continue; }
                $charged = gend_society_dv_leo_charge( $payer, $pkg['price'], $mid, $pkg['name'] );
                if ( is_wp_error( $charged ) ) { $skip++; continue; }
                gend_society_dv_leo_credit( $mid, $pkg['tokens'] );
                $ok++;
            }

            $all[ $group_id ][ $i ]['lastRunAt'] = $now;
            $all[ $group_id ][ $i ]['nextRunAt'] = $now + gend_society_dv_leo_cadence_seconds( (string) ( $c['cadence'] ?? 'monthly' ) );
            $all[ $group_id ][ $i ]['lastNote']  = sprintf( '%d purchased%s', $ok, $skip ? ( ', ' . $skip . ' skipped (wallet short / missing)' ) : '' );
            $dirty = true;
        }
    }
    if ( $dirty ) update_site_option( 'gend_society_leo_purchase_contracts', $all );
}


/**
 * Estimated AI run-cost of every sequence synced to gend.me for a group
 * (`_psoo_sequences` groupmeta). Per step: device / claude-terminal targets
 * are FREE (member hardware / personal license); gend.me-metered steps are
 * estimated from the prompt length (~4 chars/token in) + ~1,500 output
 * tokens through the leo plugin's own pricing (calculate_cost +
 * usd_to_tokens), with a flash-tier fallback rate when leo is unavailable.
 */
function gend_society_dv_sequences_cost( $group_id ) {
    $out = array( 'rows' => array(), 'totalUsd' => 0.0, 'totalTokens' => 0.0, 'freeSteps' => 0, 'aiSteps' => 0 );

    // The SEQUENCES are the desktop Sequences-tab workspaces
    // (`_psoo_sequence_workspaces`, tombstoned map keyed by id) — NOT
    // `_psoo_sequences`, which holds one prompt-definition per org-chart
    // AGENT. Each workspace resolves its bound definition (by sequenceId,
    // then exact name) for per-step run targets; a standalone workspace
    // falls back to its own promptSteps costed as gend.me-metered.
    $workspaces = function_exists( 'groups_get_groupmeta' )
        ? groups_get_groupmeta( (int) $group_id, '_psoo_sequence_workspaces', true )
        : array();
    if ( ! is_array( $workspaces ) ) $workspaces = array();
    $defs = function_exists( 'groups_get_groupmeta' )
        ? groups_get_groupmeta( (int) $group_id, '_psoo_sequences', true )
        : array();
    if ( ! is_array( $defs ) ) $defs = array();

    $def_by_id = array();
    $def_by_name = array();
    foreach ( $defs as $d ) {
        if ( ! is_array( $d ) ) continue;
        if ( isset( $d['id'] ) ) $def_by_id[ (string) $d['id'] ] = $d;
        $nm = strtolower( trim( (string) ( $d['name'] ?? '' ) ) );
        if ( $nm !== '' && ! isset( $def_by_name[ $nm ] ) ) $def_by_name[ $nm ] = $d;
    }

    $cost_step = function ( $text, $model ) {
        $in    = max( 1, (int) ceil( strlen( (string) $text ) / 4 ) );
        $out_t = 1500;
        $cost  = 0.0;
        if ( class_exists( 'AIPA_Usage' ) && method_exists( 'AIPA_Usage', 'calculate_cost' ) ) {
            try { $cost = (float) AIPA_Usage::calculate_cost( $model !== '' ? $model : 'gemini-2.5-flash', $in, $out_t ); } catch ( Throwable $e ) { $cost = 0.0; }
        }
        if ( $cost <= 0 ) $cost = ( $in + $out_t ) * 0.0000006; // flash-tier fallback
        return $cost;
    };

    foreach ( $workspaces as $w ) {
        if ( ! is_array( $w ) || ! empty( $w['deleted'] ) || empty( $w['id'] ) ) continue;
        if ( ( $w['status'] ?? 'active' ) === 'archived' ) continue;

        // Resolve the bound definition for real per-step run targets.
        $def = null;
        $sid = isset( $w['sequenceId'] ) && $w['sequenceId'] !== null ? (string) $w['sequenceId'] : '';
        if ( $sid !== '' && isset( $def_by_id[ $sid ] ) ) {
            $def = $def_by_id[ $sid ];
        } else {
            $nm = strtolower( trim( (string) ( $w['name'] ?? '' ) ) );
            if ( $nm !== '' && isset( $def_by_name[ $nm ] ) ) $def = $def_by_name[ $nm ];
        }

        $ai = 0; $free = 0; $usd = 0.0; $steps = 0;
        if ( $def && isset( $def['prompts'] ) && is_array( $def['prompts'] ) && ! empty( $def['prompts'] ) ) {
            foreach ( $def['prompts'] as $pr ) {
                if ( ! is_array( $pr ) ) continue;
                $steps++;
                $target = (string) ( $pr['runTarget'] ?? 'gendme' );
                $integr = (string) ( $pr['aiIntegration'] ?? '' );
                // Device-pinned steps (incl. the Claude terminal) run on
                // member hardware — no gend.me metering. device_or_gendme is
                // costed as its worst case (the network fallback).
                if ( 'device' === $target || 'claude-terminal' === $integr ) { $free++; continue; }
                $ai++;
                $usd += $cost_step( (string) ( $pr['text'] ?? '' ), (string) ( $pr['model'] ?? '' ) );
            }
        } else {
            // Standalone workspace — cost its own step texts as metered.
            foreach ( (array) ( $w['promptSteps'] ?? array() ) as $st ) {
                if ( ! is_array( $st ) ) continue;
                $steps++;
                $ai++;
                $usd += $cost_step( (string) ( $st['text'] ?? '' ), '' );
            }
        }

        $tokens = 0.0;
        if ( $usd > 0 && class_exists( 'AIPA_Usage' ) && method_exists( 'AIPA_Usage', 'usd_to_tokens' ) ) {
            try { $tokens = (float) AIPA_Usage::usd_to_tokens( $usd ); } catch ( Throwable $e ) { $tokens = 0.0; }
        }
        $out['rows'][] = array(
            'id'     => (string) $w['id'],
            'name'   => (string) ( $w['name'] ?? __( 'Untitled sequence', 'gend-society' ) ),
            'agent'  => (string) ( $w['sequenceRole'] ?? '' ) !== '' ? (string) $w['sequenceRole']
                        : ( $def ? (string) ( $def['role'] ?? ( $def['agentSlug'] ?? '' ) ) : '' ),
            'steps'  => $steps,
            'ai'     => $ai,
            'free'   => $free,
            'usd'    => $usd,
            'tokens' => $tokens,
        );
        $out['totalUsd']    += $usd;
        $out['totalTokens'] += $tokens;
        $out['freeSteps']   += $free;
        $out['aiSteps']     += $ai;
    }

    return $out;
}
