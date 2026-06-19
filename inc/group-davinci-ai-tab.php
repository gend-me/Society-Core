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
function gs_davinci_group_member_ids( $group_id ) {
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
function gs_davinci_user_is_agent( $user_id ) {
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
function gs_davinci_usage_map( array $user_ids, $since ) {
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
function gs_davinci_currency() {
    if ( class_exists( 'AIPA_Usage' ) && method_exists( 'AIPA_Usage', 'get_token_pricing_settings' ) ) {
        $s = AIPA_Usage::get_token_pricing_settings();
        return isset( $s['currency'] ) ? (string) $s['currency'] : 'USD';
    }
    return 'USD';
}

/** Format a USD amount as a money string. */
function gs_davinci_money( $amount ) {
    return '$' . number_format( (float) $amount, 2 );
}

/** Sanitize + normalize the stored API-integration cost rows. */
function gs_davinci_sanitize_api_costs( $raw ) {
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
function gs_davinci_api_costs_monthly( array $rows ) {
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

add_action( 'wp_ajax_gs_davinci_save_api_costs', 'gs_davinci_save_api_costs_ajax' );
function gs_davinci_save_api_costs_ajax() {
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
    $clean = gs_davinci_sanitize_api_costs( $raw );
    groups_update_groupmeta( $group_id, '_gs_davinci_api_costs', $clean );

    wp_send_json_success( array(
        'rows'          => $clean,
        'monthly_label' => gs_davinci_money( gs_davinci_api_costs_monthly( $clean ) ),
    ) );
}

/* ───────────────────────────── the tab ───────────────────────────── */

if ( class_exists( 'BP_Group_Extension' ) ) :

    /**
     * Davinci AI — AI-spend command centre for the web app owner.
     */
    class GS_Group_Tab_Davinci_AI extends BP_Group_Extension {
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
        public function display( $group_id = null ) {
            if ( ! function_exists( 'gs_group_tabs_user_has_access' ) || ! gs_group_tabs_user_has_access() ) return;
            $group_id = $group_id ?: bp_get_current_group_id();
            gs_group_render_davinci_ai_suite( (int) $group_id );
        }
    }

    // Register on bp_init (BP nav must exist). Separate from the
    // group-app-tabs.php registration so this file stays self-contained.
    add_action( 'bp_init', 'gs_register_davinci_ai_tab', 10 );
    function gs_register_davinci_ai_tab() {
        if ( ! function_exists( 'bp_register_group_extension' ) ) return;
        bp_register_group_extension( 'GS_Group_Tab_Davinci_AI' );
    }

    // Show the tab in the native BP nav only for group/site admins.
    add_filter( 'bp_group_extension_nav_show_for_user', 'gs_davinci_ai_nav_visibility', 99, 3 );
    function gs_davinci_ai_nav_visibility( $show, $slug, $group_id ) {
        if ( $slug !== 'davinci-ai' ) return $show;
        return function_exists( 'gs_group_tabs_user_has_access' ) ? gs_group_tabs_user_has_access() : $show;
    }

endif; // BP_Group_Extension

/* ──────────────────────────── renderer ──────────────────────────── */

function gs_group_render_davinci_ai_suite( $group_id ) {
    $group_id = (int) $group_id;
    $uid      = get_current_user_id();
    $currency = gs_davinci_currency();

    // ── Gather membership + usage ──────────────────────────────────
    $member_ids = gs_davinci_group_member_ids( $group_id );
    $agent_ids  = array();
    $human_ids  = array();
    foreach ( $member_ids as $mid ) {
        if ( gs_davinci_user_is_agent( $mid ) ) $agent_ids[] = $mid; else $human_ids[] = $mid;
    }
    $since   = current_time( 'Y-m-01 00:00:00' ); // start of this calendar month
    $usage   = gs_davinci_usage_map( $member_ids, $since );

    $leo_usd = 0.0; $leo_tokens = 0;
    foreach ( $human_ids as $hid ) { if ( isset( $usage[ $hid ] ) ) { $leo_usd += $usage[ $hid ]['usd']; $leo_tokens += $usage[ $hid ]['tokens']; } }
    $agent_usd = 0.0; $agent_tokens = 0;
    foreach ( $agent_ids as $aid ) { if ( isset( $usage[ $aid ] ) ) { $agent_usd += $usage[ $aid ]['usd']; $agent_tokens += $usage[ $aid ]['tokens']; } }

    // ── API integration costs (owner-entered) ─────────────────────
    $api_costs   = function_exists( 'groups_get_groupmeta' ) ? gs_davinci_sanitize_api_costs( groups_get_groupmeta( $group_id, '_gs_davinci_api_costs', true ) ) : array();
    $api_monthly = gs_davinci_api_costs_monthly( $api_costs );

    // ── Compute gas gate (reuses the Compute Gas resolver) ─────────
    $cg_state = function_exists( 'gs_compute_gas_resolve_state' ) ? gs_compute_gas_resolve_state( $group_id, $uid ) : array();
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
            --dv-blue:#6ec1e4; --dv-magenta:#b608c9; --dv-green:#00ff88; --dv-gold:#ffcc00;
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

        [data-gs-dv-scope] table.gs-dv-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        [data-gs-dv-scope] table.gs-dv-table th { text-align:left; font-size:0.62rem; text-transform:uppercase; letter-spacing:1.1px; opacity:0.5; font-weight:800; padding:10px 12px; border-bottom:1px solid var(--dv-glass-border); }
        [data-gs-dv-scope] table.gs-dv-table td { padding:12px; border-bottom:1px solid rgba(255,255,255,0.05); color:#e8edf5; vertical-align:middle; }
        [data-gs-dv-scope] table.gs-dv-table td.num, [data-gs-dv-scope] table.gs-dv-table th.num { text-align:right; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
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

        [data-gs-dv-scope] input.gs-dv-in, [data-gs-dv-scope] select.gs-dv-in {
            width:100%; padding:9px 11px; background:rgba(11,14,20,0.78); border:1px solid rgba(255,255,255,0.12);
            color:#fff; border-radius:9px; font-size:0.82rem; outline:none; font-family:inherit;
        }
        [data-gs-dv-scope] input.gs-dv-in:focus, [data-gs-dv-scope] select.gs-dv-in:focus { border-color:rgba(110,193,228,0.55); }
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
                <div class="gs-dv-sum-value"><?php echo esc_html( gs_davinci_money( $leo_usd ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php echo esc_html( number_format( $leo_tokens ) . ' ' . __( 'tokens this month', 'gend-society' ) ); ?></p>
            </div>
            <div class="gs-dv-sumcard" style="--accent:var(--dv-magenta);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'Agent Costs', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value"><?php echo esc_html( gs_davinci_money( $agent_usd ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php echo esc_html( count( $agent_ids ) . ' ' . _n( 'connected agent', 'connected agents', count( $agent_ids ), 'gend-society' ) ); ?></p>
            </div>
            <div class="gs-dv-sumcard" style="--accent:var(--dv-gold);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'API Integrations', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value" data-gs-dv-api-monthly><?php echo esc_html( gs_davinci_money( $api_monthly ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php esc_html_e( 'per month, owner-entered', 'gend-society' ); ?></p>
            </div>
            <div class="gs-dv-sumcard is-total" style="--accent:var(--dv-green);">
                <p class="gs-dv-sum-label"><?php esc_html_e( 'Total AI Spend / mo', 'gend-society' ); ?></p>
                <div class="gs-dv-sum-value" data-gs-dv-total><?php echo esc_html( gs_davinci_money( $known_monthly ) ); ?></div>
                <p class="gs-dv-sum-foot"><?php esc_html_e( 'incl. compute gas once synced', 'gend-society' ); ?></p>
            </div>
        </div>

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
                    <div class="v"><?php echo esc_html( gs_davinci_money( $leo_usd ) ); ?></div>
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
                            <td class="num"><?php echo esc_html( gs_davinci_money( $row['usd'] ) ); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

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
                    <div class="v"><?php echo esc_html( gs_davinci_money( $agent_usd ) ); ?></div>
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
                            <td class="num"><?php echo esc_html( gs_davinci_money( $row['usd'] ) ); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

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
                    <div class="v" data-gs-dv-api-monthly2><?php echo esc_html( gs_davinci_money( $api_monthly ) ); ?></div>
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
                var b = new URLSearchParams({ action:'gs_davinci_save_api_costs', nonce:apiNonce, group_id:groupId, rows:JSON.stringify(rows) });
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
            var b = new URLSearchParams({ action:'gs_hosting_compute_gas', nonce:cgNonce });
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
    })();
    </script>
    <?php
}
