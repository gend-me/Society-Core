<?php
/**
 * Customer-side dashboard membership panel.
 *
 * Renders the same "membership details" UI on every site type
 * (networked subsite, server-hosted, self-hosted, container) once
 * the user is OAuth-paired with gend.me. Replaces the old four
 * separate cards (App Builder Membership, Account Owner, Domain,
 * Plan Management) with the unified popup layout used on
 * gend.me's /my-account/membership/{id}/ page:
 *
 *   ┌── Header: site title • status badge • Open App • 5-stage progress
 *   ┌── 4-card grid: Membership ▪ Business Group ▪ Feature Access ▪ App Hosting
 *   └── Tabs: Orders ▪ Domain ▪ Backups (lazy-loaded inline AJAX)
 *
 * Inline actions:
 *   - Plan upgrade: opens gend.me's checkout in a popup window. On
 *     close (or post-message), the container refreshes the cached
 *     membership payload so plan/price changes appear immediately.
 *   - Domain add/verify/remove: container-side AJAX → install-token
 *     REST proxy → gend.me's /install/{id}/domains/{action}.
 *   - Backup now / restore: same proxy pattern.
 *
 * Auth model: install_token (set by oauth-login.php's auto-pair
 * handshake on the owner's first OAuth login) is the bearer for all
 * gend.me-bound traffic. Container-side AJAX is gated by the standard
 * WP nonce + manage_options capability.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const GS_REMOTE_MEMBERSHIP_CACHE_OPTION         = 'gs_remote_membership_cache';
const GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION = 'gs_remote_membership_cache_expires';
const GS_REMOTE_MEMBERSHIP_DEFAULT_TTL          = 5 * MINUTE_IN_SECONDS;

// ────────────────────────────────────────────────────────────────────────
// Remote fetch + caching
// ────────────────────────────────────────────────────────────────────────

function gs_remote_membership_get_cached() {
    $expires = (int) get_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION, 0 );
    if ( $expires > time() ) {
        $cached = get_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, null );
        if ( is_array( $cached ) ) return $cached;
    }
    $fresh = gs_remote_membership_fetch();
    if ( is_array( $fresh ) ) {
        $ttl = isset( $fresh['cache_seconds'] ) ? max( 60, (int) $fresh['cache_seconds'] ) : GS_REMOTE_MEMBERSHIP_DEFAULT_TTL;
        update_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, $fresh, false );
        update_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION, time() + $ttl, false );
        return $fresh;
    }
    $cached = get_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, null );
    return is_array( $cached ) ? $cached : null;
}

function gs_remote_membership_fetch() {
    $install_id    = (string) get_option( 'gs_install_id', '' );
    $install_token = (string) get_option( 'gs_install_token', '' );
    $gend_base     = (string) get_option( 'gs_gend_base_url', '' );
    if ( $install_id === '' || $install_token === '' || $gend_base === '' ) return null;

    $endpoint = trailingslashit( $gend_base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/membership';
    $response = wp_remote_get( $endpoint, array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $install_token,
            'Accept'        => 'application/json',
        ),
    ) );
    if ( is_wp_error( $response ) ) return null;
    if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
    $raw   = (string) wp_remote_retrieve_body( $response );
    $clean = trim( str_replace( "\xEF\xBB\xBF", '', $raw ) );
    $data  = json_decode( $clean, true );
    if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $clean, $m ) ) {
        $data = json_decode( $m[1], true );
    }
    return is_array( $data ) ? $data : null;
}

function gs_remote_membership_invalidate() {
    delete_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION );
}
add_action( 'wp_login', 'gs_remote_membership_invalidate' );
add_action( 'gs_remote_membership_invalidate', 'gs_remote_membership_invalidate' );

// ────────────────────────────────────────────────────────────────────────
// Container-side AJAX proxies → gend.me install-token REST endpoints
// ────────────────────────────────────────────────────────────────────────

/**
 * Proxy a POST or GET to gend.me with the local install_token. Used by
 * every gs_membership_* AJAX action so we don't expose the token to
 * the browser.
 *
 * @param string $path  Path under /wp-json/gdc-app-manager/v1/install/{install_id}/
 *                      e.g. "backups/now", "domains/add"
 * @param array  $body  Body params (POST). Empty for GET.
 * @param string $method "POST" or "GET"
 * @return array|\WP_Error
 */
function gs_remote_membership_call( string $path, array $body = array(), string $method = 'POST' ) {
    $install_id    = (string) get_option( 'gs_install_id', '' );
    $install_token = (string) get_option( 'gs_install_token', '' );
    $gend_base     = (string) get_option( 'gs_gend_base_url', '' );
    if ( $install_id === '' || $install_token === '' || $gend_base === '' ) {
        return new WP_Error( 'not_paired', __( 'This install is not paired with gend.me. Sign in via OAuth first.', 'gend-society' ) );
    }
    $endpoint = trailingslashit( $gend_base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/' . ltrim( $path, '/' );

    $args = array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $install_token,
            'Accept'        => 'application/json',
        ),
    );
    if ( $method === 'POST' ) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body']                    = wp_json_encode( $body );
        $resp = wp_remote_post( $endpoint, $args );
    } else {
        $resp = wp_remote_get( $endpoint, $args );
    }
    if ( is_wp_error( $resp ) ) return $resp;

    $raw   = (string) wp_remote_retrieve_body( $resp );
    $clean = trim( str_replace( "\xEF\xBB\xBF", '', $raw ) );
    $data  = json_decode( $clean, true );
    $code  = (int) wp_remote_retrieve_response_code( $resp );
    if ( $code < 200 || $code >= 300 ) {
        $msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : substr( $clean, 0, 200 );
        return new WP_Error( 'remote_' . $code, $msg, array( 'status' => $code ) );
    }
    return is_array( $data ) ? $data : array();
}

function gs_membership_ajax_authorize() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
    }
    check_ajax_referer( 'gs_membership_action', 'nonce' );
}

add_action( 'wp_ajax_gs_membership_backup_now', function () {
    gs_membership_ajax_authorize();
    $r = gs_remote_membership_call( 'backups/now' );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_backup_restore', function () {
    gs_membership_ajax_authorize();
    $bid = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
    if ( $bid <= 0 ) wp_send_json_error( array( 'message' => __( 'backup_id required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'backups/restore', array( 'backup_id' => $bid ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_add', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'domains/add', array( 'domain' => $domain ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_verify', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'domains/verify', array( 'domain' => $domain ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_gs_membership_domain_remove', function () {
    gs_membership_ajax_authorize();
    $domain = isset( $_POST['domain'] ) ? strtolower( trim( wp_unslash( (string) $_POST['domain'] ) ) ) : '';
    if ( $domain === '' ) wp_send_json_error( array( 'message' => __( 'Domain required.', 'gend-society' ) ) );
    $r = gs_remote_membership_call( 'domains/remove', array( 'domain' => $domain ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
    gs_remote_membership_invalidate();
    wp_send_json_success( $r );
} );

// Refresh-cache hook — used after the plan-upgrade popup closes so
// the new plan appears immediately without waiting out the TTL.
add_action( 'wp_ajax_gs_membership_refresh', function () {
    gs_membership_ajax_authorize();
    gs_remote_membership_invalidate();
    $fresh = gs_remote_membership_get_cached();
    wp_send_json_success( array( 'data' => $fresh ) );
} );

// ────────────────────────────────────────────────────────────────────────
// Renderer
// ────────────────────────────────────────────────────────────────────────

/**
 * Render the unified membership panel. Accepts EITHER a remote payload
 * (from /install/{id}/membership) OR null — when null and a payload is
 * cached we use that, otherwise emit a friendly "not paired" message.
 *
 * @param array|null $payload Remote membership payload, or null to use cache.
 * @return string
 */
function gs_render_membership_panel( $payload = null ) {

    if ( ! is_array( $payload ) ) {
        $payload = gs_remote_membership_get_cached();
    }
    if ( ! is_array( $payload ) ) {
        return '<div class="notice notice-warning gs-membership-not-paired" style="padding:16px; background:rgba(255,255,255,0.04); border-radius:12px; color:var(--gs-muted);"><p>' .
            esc_html__( 'Membership data unavailable. Sign in with gend.me to load your plan details.', 'gend-society' ) .
            '</p></div>';
    }

    $hub_url        = isset( $payload['hub_url'] ) ? rtrim( (string) $payload['hub_url'], '/' ) . '/' : '';
    $membership_url = isset( $payload['membership_url'] ) ? (string) $payload['membership_url'] : '';
    $app_url        = isset( $payload['app_url'] ) ? (string) $payload['app_url'] : home_url( '/' );
    $app_title      = isset( $payload['app_title'] ) && $payload['app_title'] !== '' ? (string) $payload['app_title'] : (string) get_bloginfo( 'name' );

    $status         = isset( $payload['status'] ) ? (string) $payload['status'] : '';
    $status_label   = isset( $payload['status_label'] ) ? (string) $payload['status_label'] : ucfirst( $status );

    $billing        = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
    $dates          = isset( $payload['dates'] )   && is_array( $payload['dates'] )   ? $payload['dates']   : array();
    $migration      = isset( $payload['migration'] ) && is_array( $payload['migration'] ) ? $payload['migration'] : array();

    $dash_plan      = isset( $payload['dashboard_plan'] ) && is_array( $payload['dashboard_plan'] ) ? $payload['dashboard_plan'] : null;
    $host_plan      = isset( $payload['hosting_plan'] )   && is_array( $payload['hosting_plan'] )   ? $payload['hosting_plan']   : null;
    $customer       = isset( $payload['customer'] )       && is_array( $payload['customer'] )       ? $payload['customer']       : null;
    $group          = isset( $payload['group'] )          && is_array( $payload['group'] )          ? $payload['group']          : null;

    $orders         = isset( $payload['orders'] )  && is_array( $payload['orders'] )  ? $payload['orders']  : array();
    $backups        = isset( $payload['backups'] ) && is_array( $payload['backups'] ) ? $payload['backups'] : array();
    $domains        = isset( $payload['domains'] ) && is_array( $payload['domains'] ) ? $payload['domains'] : array();

    ob_start();
    ?>
    <style>
        .gs-mship-card { background: rgba(11,14,20,0.6); border: 1px solid var(--gs-border, rgba(255,255,255,0.08)); border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); backdrop-filter: blur(20px); }
        .gs-mship-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
        .gs-mship-title { display: flex; align-items: center; gap: 16px; }
        .gs-mship-title h2 { margin: 0; font-size: 1.5rem; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.04em; }
        .gs-mship-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; background: rgba(0,180,80,0.15); border: 1px solid rgba(0,180,80,0.4); color: #4ee68a; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .gs-mship-open-app { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 999px; background: linear-gradient(135deg, #00b450, #008c3a); color: #fff !important; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; text-decoration: none; box-shadow: 0 6px 16px -4px rgba(0,180,80,0.4); }
        .gs-mship-open-app:hover { transform: translateY(-1px); }
        .gs-mship-progress { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin: 16px 0 8px; padding: 16px 8px; background: rgba(255,255,255,0.02); border: 1px solid rgba(0,180,80,0.3); border-radius: 14px; }
        .gs-mship-step { display: flex; flex-direction: column; align-items: center; gap: 8px; position: relative; }
        .gs-mship-step::before { content: ''; position: absolute; top: 8px; left: 50%; right: -50%; height: 2px; background: rgba(255,255,255,0.06); z-index: 0; }
        .gs-mship-step:last-child::before { display: none; }
        .gs-mship-step.is-done::before { background: #00b450; }
        .gs-mship-step .dot { width: 16px; height: 16px; border-radius: 50%; background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.15); position: relative; z-index: 1; }
        .gs-mship-step.is-done .dot { background: #00b450; border-color: #00b450; box-shadow: 0 0 12px rgba(0,180,80,0.5); }
        .gs-mship-step .label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gs-muted); }
        .gs-mship-step.is-done .label { color: #4ee68a; }
        .gs-mship-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 24px; }
        @media (max-width: 1100px) { .gs-mship-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px)  { .gs-mship-grid { grid-template-columns: 1fr; } }
        .gs-mship-cell { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 18px; }
        .gs-mship-cell h3 { margin: 0 0 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gs-muted); text-align: center; }
        .gs-mship-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; font-size: 0.85rem; }
        .gs-mship-row .label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gs-muted); font-weight: 600; }
        .gs-mship-row .value { color: #fff; font-weight: 500; }
        .gs-mship-row .pill { display: inline-block; padding: 2px 10px; background: rgba(255,180,0,0.15); border: 1px solid rgba(255,180,0,0.35); color: #ffd166; border-radius: 999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .gs-mship-plan-card { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 16px 8px; }
        .gs-mship-plan-img { width: 96px; height: 96px; border-radius: 16px; background: rgba(255,255,255,0.04); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .gs-mship-plan-img img { width: 100%; height: 100%; object-fit: cover; }
        .gs-mship-plan-img .dashicons { font-size: 48px; color: rgba(255,255,255,0.3); }
        .gs-mship-plan-name { color: #fff; font-weight: 700; font-size: 1rem; }
        .gs-mship-plan-price { color: #4eaaff; font-size: 0.9rem; font-weight: 600; }
        .gs-mship-tabs { margin-top: 28px; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 18px; }
        .gs-mship-tabs-nav { display: flex; gap: 4px; border-bottom: 1px solid rgba(255,255,255,0.05); margin-bottom: 16px; }
        .gs-mship-tab-btn { padding: 12px 20px; background: transparent; border: 0; border-bottom: 2px solid transparent; color: var(--gs-muted); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; cursor: pointer; }
        .gs-mship-tab-btn:hover { color: #fff; }
        .gs-mship-tab-btn.is-active { color: #4eaaff; border-bottom-color: #4eaaff; }
        .gs-mship-tab-panel { display: none; }
        .gs-mship-tab-panel.is-active { display: block; }
        .gs-mship-empty { padding: 24px; text-align: center; color: var(--gs-muted); background: rgba(255,255,255,0.02); border-radius: 12px; }
        .gs-mship-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: linear-gradient(135deg, #b608c9, #7e058a); color: #fff !important; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; border: 0; cursor: pointer; text-decoration: none; }
        .gs-mship-action-btn[disabled] { opacity: 0.5; cursor: wait; }
        .gs-mship-action-btn.is-secondary { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); }
        .gs-mship-action-btn.is-danger { background: rgba(255,40,40,0.15); border: 1px solid rgba(255,40,40,0.4); color: #ff8888 !important; }
        .gs-mship-list { list-style: none; margin: 0; padding: 0; }
        .gs-mship-list li { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; margin-bottom: 6px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); border-radius: 10px; gap: 12px; flex-wrap: wrap; }
        .gs-mship-list li .meta { font-size: 0.75rem; color: var(--gs-muted); }
        .gs-mship-form { display: flex; gap: 8px; margin-bottom: 16px; }
        .gs-mship-form input { flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.25); color: #fff; font-size: 0.9rem; }
        .gs-mship-form input:focus { border-color: #b608c9; outline: none; }
        .gs-mship-toast { position: fixed; bottom: 30px; right: 30px; padding: 14px 22px; background: rgba(11,14,20,0.95); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #fff; font-size: 0.9rem; box-shadow: 0 12px 32px -4px rgba(0,0,0,0.6); z-index: 99999; opacity: 0; transition: opacity 0.2s, transform 0.2s; transform: translateY(10px); }
        .gs-mship-toast.is-visible { opacity: 1; transform: translateY(0); }
        .gs-mship-toast.is-success { border-color: rgba(0,180,80,0.4); }
        .gs-mship-toast.is-error { border-color: rgba(255,80,80,0.4); }
    </style>

    <div class="gs-mship-card" id="gs-mship-root" data-app-title="<?php echo esc_attr( $app_title ); ?>">

        <!-- ── Header: title + status + Open App ───────────────────── -->
        <div class="gs-mship-header">
            <div class="gs-mship-title">
                <h2><?php echo esc_html( $app_title ); ?></h2>
                <?php if ( ! empty( $migration['live'] ) ) : ?>
                    <span class="gs-mship-status-badge">● <?php esc_html_e( 'Container Live', 'gend-society' ); ?></span>
                <?php elseif ( $status_label !== '' ) : ?>
                    <span class="gs-mship-status-badge" style="background:rgba(255,180,0,0.15); border-color:rgba(255,180,0,0.4); color:#ffd166;"><?php echo esc_html( $status_label ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $app_url !== '' ) : ?>
                <a class="gs-mship-open-app" href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Open App', 'gend-society' ); ?> →
                </a>
            <?php endif; ?>
        </div>

        <!-- ── 5-stage migration progress ──────────────────────────── -->
        <?php
        $steps    = array( 'prepare' => __('Prepare', 'gend-society'), 'export' => __('Export', 'gend-society'), 'provision' => __('Provision', 'gend-society'), 'verify' => __('Verify', 'gend-society'), 'live' => __('Live', 'gend-society') );
        $stage_ix = isset( $migration['index'] ) ? (int) $migration['index'] : 0;
        ?>
        <div class="gs-mship-progress">
            <?php $i = 0; foreach ( $steps as $key => $label ) : $i++; $done = $i <= max( 1, $stage_ix ); ?>
                <div class="gs-mship-step<?php echo $done ? ' is-done' : ''; ?>">
                    <span class="dot"></span>
                    <span class="label"><?php echo esc_html( $label ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── 4-card grid: Membership / Group / Feature Access / Hosting ── -->
        <div class="gs-mship-grid">

            <!-- Membership cell -->
            <div class="gs-mship-cell">
                <h3><?php esc_html_e( 'Membership', 'gend-society' ); ?></h3>
                <?php if ( $status_label !== '' ) : ?>
                    <div class="gs-mship-row"><span class="label"><?php esc_html_e( 'Status', 'gend-society' ); ?></span><span class="pill"><?php echo esc_html( $status_label ); ?></span></div>
                <?php endif; ?>
                <?php if ( ! empty( $billing['label'] ) ) : ?>
                    <div class="gs-mship-row"><span class="label"><?php esc_html_e( 'Billing', 'gend-society' ); ?></span><span class="value"><?php echo esc_html( $billing['label'] ); ?> <?php echo ! empty( $billing['unit'] ) ? esc_html( sprintf( __( 'every %s', 'gend-society' ), $billing['unit'] ) ) : ''; ?></span></div>
                <?php endif; ?>
                <?php if ( ! empty( $dates['created'] ) ) : ?>
                    <div class="gs-mship-row"><span class="label"><?php esc_html_e( 'Created', 'gend-society' ); ?></span><span class="value"><?php echo esc_html( $dates['created'] ); ?></span></div>
                <?php endif; ?>
                <?php if ( ! empty( $dates['activated'] ) ) : ?>
                    <div class="gs-mship-row"><span class="label"><?php esc_html_e( 'Activated', 'gend-society' ); ?></span><span class="value"><?php echo esc_html( $dates['activated'] ); ?></span></div>
                <?php endif; ?>
                <?php if ( ! empty( $dates['expires'] ) || ! empty( $dates['renews'] ) ) : ?>
                    <div class="gs-mship-row"><span class="label"><?php esc_html_e( 'Next Renewal / Expiration', 'gend-society' ); ?></span><span class="value"><?php echo esc_html( ! empty( $dates['expires'] ) ? $dates['expires'] : $dates['renews'] ); ?></span></div>
                <?php endif; ?>
            </div>

            <!-- Business Group cell -->
            <div class="gs-mship-cell">
                <h3><?php esc_html_e( 'Business Group', 'gend-society' ); ?></h3>
                <?php if ( $group && ! empty( $group['id'] ) ) :
                    $g_link = $hub_url !== '' ? trailingslashit( $hub_url ) . 'groups/' . sanitize_title( $group['slug'] ?? $group['id'] ) . '/' : '';
                ?>
                    <div class="gs-mship-plan-card">
                        <div class="gs-mship-plan-img">
                            <?php if ( ! empty( $group['avatar'] ) ) : ?>
                                <img src="<?php echo esc_url( $group['avatar'] ); ?>" alt="" />
                            <?php else : ?>
                                <span class="dashicons dashicons-groups"></span>
                            <?php endif; ?>
                        </div>
                        <div class="gs-mship-plan-name"><?php echo esc_html( $group['name'] ?? '' ); ?></div>
                    </div>
                    <?php if ( $g_link !== '' ) : ?>
                        <div style="display: flex; flex-direction: column; gap: 6px; margin-top: 12px;">
                            <a class="gs-mship-action-btn is-secondary" href="<?php echo esc_url( $g_link . 'proposals/' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Proposals', 'gend-society' ); ?></a>
                            <a class="gs-mship-action-btn is-secondary" href="<?php echo esc_url( $g_link . 'projects/' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Projects', 'gend-society' ); ?></a>
                            <a class="gs-mship-action-btn is-secondary" href="<?php echo esc_url( $g_link . 'payments/' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Payments', 'gend-society' ); ?></a>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="gs-mship-empty"><?php esc_html_e( 'No group linked.', 'gend-society' ); ?></div>
                <?php endif; ?>
            </div>

            <!-- Feature Access (Dashboard plan) cell -->
            <div class="gs-mship-cell">
                <h3><?php esc_html_e( 'Feature Access', 'gend-society' ); ?></h3>
                <div class="gs-mship-plan-card">
                    <div class="gs-mship-plan-img">
                        <?php if ( $dash_plan && ! empty( $dash_plan['image'] ) ) : ?>
                            <img src="<?php echo esc_url( $dash_plan['image'] ); ?>" alt="" />
                        <?php else : ?>
                            <span class="dashicons dashicons-admin-users"></span>
                        <?php endif; ?>
                    </div>
                    <div class="gs-mship-plan-name"><?php echo esc_html( $dash_plan['name'] ?? __( 'No dashboard plan', 'gend-society' ) ); ?></div>
                    <?php if ( $dash_plan && ! empty( $dash_plan['amount_label'] ) ) : ?>
                        <div class="gs-mship-plan-price"><?php echo esc_html( $dash_plan['amount_label'] ); ?> <?php echo ! empty( $dash_plan['duration_unit'] ) ? esc_html( sprintf( __( 'every %s', 'gend-society' ), $dash_plan['duration_unit'] ) ) : ''; ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="gs-mship-action-btn" data-gs-mship="upgrade-plan" data-group="dashboard" style="width: 100%; margin-top: 12px;">
                    <?php esc_html_e( 'Change Dashboard Plan', 'gend-society' ); ?>
                </button>
            </div>

            <!-- App Hosting cell -->
            <div class="gs-mship-cell">
                <h3><?php esc_html_e( 'App Hosting', 'gend-society' ); ?></h3>
                <div class="gs-mship-plan-card">
                    <div class="gs-mship-plan-img">
                        <?php if ( $host_plan && ! empty( $host_plan['image'] ) ) : ?>
                            <img src="<?php echo esc_url( $host_plan['image'] ); ?>" alt="" />
                        <?php else : ?>
                            <span class="dashicons dashicons-cloud"></span>
                        <?php endif; ?>
                    </div>
                    <div class="gs-mship-plan-name"><?php echo esc_html( $host_plan['name'] ?? __( 'No hosting plan', 'gend-society' ) ); ?></div>
                    <?php if ( $host_plan && ! empty( $host_plan['amount_label'] ) ) : ?>
                        <div class="gs-mship-plan-price"><?php echo esc_html( $host_plan['amount_label'] ); ?> <?php echo ! empty( $host_plan['duration_unit'] ) ? esc_html( sprintf( __( 'every %s', 'gend-society' ), $host_plan['duration_unit'] ) ) : ''; ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="gs-mship-action-btn" data-gs-mship="upgrade-plan" data-group="hosting" style="width: 100%; margin-top: 12px;">
                    <?php esc_html_e( 'Change Hosting Plan', 'gend-society' ); ?>
                </button>
            </div>

        </div>

        <!-- ── Tabs: Orders / Domain / Backups ─────────────────────── -->
        <div class="gs-mship-tabs">
            <div class="gs-mship-tabs-nav" role="tablist">
                <button type="button" class="gs-mship-tab-btn is-active" data-tab="orders" role="tab"><?php esc_html_e( 'Orders', 'gend-society' ); ?></button>
                <button type="button" class="gs-mship-tab-btn" data-tab="domain" role="tab"><?php esc_html_e( 'Domain', 'gend-society' ); ?></button>
                <button type="button" class="gs-mship-tab-btn" data-tab="backups" role="tab"><?php esc_html_e( 'Backups', 'gend-society' ); ?></button>
            </div>

            <!-- Orders tab (read-only, server-rendered) -->
            <div class="gs-mship-tab-panel is-active" data-panel="orders" role="tabpanel">
                <?php if ( empty( $orders ) ) : ?>
                    <div class="gs-mship-empty"><?php esc_html_e( 'No recent orders found for this group.', 'gend-society' ); ?></div>
                <?php else : ?>
                    <ul class="gs-mship-list">
                        <?php foreach ( $orders as $o ) : ?>
                            <li>
                                <div>
                                    <strong style="color:#fff;">#<?php echo (int) ( $o['id'] ?? 0 ); ?></strong>
                                    <span class="meta"><?php echo esc_html( $o['created_at'] ?? '' ); ?></span>
                                </div>
                                <div>
                                    <span class="meta"><?php echo esc_html( ucfirst( $o['status'] ?? '' ) ); ?></span>
                                    <strong style="margin-left: 12px; color: #4eaaff;"><?php echo function_exists('wc_price') && ! empty( $o['total'] ) ? wp_kses_post( wc_price( (float) $o['total'], array( 'currency' => $o['currency'] ?? '' ) ) ) : esc_html( ( $o['currency'] ?? '' ) . ' ' . number_format( (float) ( $o['total'] ?? 0 ), 2 ) ); ?></strong>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Domain tab (inline AJAX) -->
            <div class="gs-mship-tab-panel" data-panel="domain" role="tabpanel">
                <form class="gs-mship-form" data-gs-mship="add-domain">
                    <input type="text" name="domain" placeholder="<?php esc_attr_e( 'yourdomain.com', 'gend-society' ); ?>" required />
                    <button type="submit" class="gs-mship-action-btn"><?php esc_html_e( 'Add domain', 'gend-society' ); ?></button>
                </form>
                <ul class="gs-mship-list" id="gs-mship-domain-list">
                    <?php foreach ( $domains as $d ) : ?>
                        <li data-domain="<?php echo esc_attr( $d['host'] ); ?>">
                            <div>
                                <code style="background:transparent; color:#fff;"><?php echo esc_html( $d['host'] ); ?></code>
                                <span class="meta">
                                    <?php
                                    $bits = array();
                                    if ( ! empty( $d['primary'] ) ) $bits[] = __( 'Primary', 'gend-society' );
                                    if ( ! empty( $d['secure'] ) )  $bits[] = __( 'Secure', 'gend-society' );
                                    if ( ! empty( $d['stage'] ) )   $bits[] = $d['stage'];
                                    echo esc_html( implode( ' · ', $bits ) );
                                    ?>
                                </span>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="gs-mship-action-btn is-secondary" data-gs-mship="verify-domain" data-domain="<?php echo esc_attr( $d['host'] ); ?>"><?php esc_html_e( 'Verify', 'gend-society' ); ?></button>
                                <button type="button" class="gs-mship-action-btn is-danger" data-gs-mship="remove-domain" data-domain="<?php echo esc_attr( $d['host'] ); ?>"><?php esc_html_e( 'Remove', 'gend-society' ); ?></button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if ( empty( $domains ) ) : ?>
                        <li class="gs-mship-empty"><?php esc_html_e( 'No custom domains added yet.', 'gend-society' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Backups tab (inline AJAX) -->
            <div class="gs-mship-tab-panel" data-panel="backups" role="tabpanel">
                <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                    <span class="meta" style="color: var(--gs-muted); font-size: 0.85rem;"><?php esc_html_e( 'Daily automatic backups + on-demand snapshots.', 'gend-society' ); ?></span>
                    <button type="button" class="gs-mship-action-btn" data-gs-mship="backup-now"><?php esc_html_e( 'Backup now', 'gend-society' ); ?></button>
                </div>
                <ul class="gs-mship-list" id="gs-mship-backup-list">
                    <?php foreach ( $backups as $b ) : ?>
                        <li>
                            <div>
                                <strong style="color:#fff;"><?php echo esc_html( ucfirst( $b['kind'] ?? 'backup' ) ); ?></strong>
                                <span class="meta"><?php echo esc_html( $b['created_at'] ?? '' ); ?> · <?php echo esc_html( size_format( (int) ( $b['bytes'] ?? 0 ) ) ); ?></span>
                            </div>
                            <?php if ( ! empty( $b['restorable'] ) ) : ?>
                                <button type="button" class="gs-mship-action-btn is-danger" data-gs-mship="restore-backup" data-id="<?php echo (int) $b['id']; ?>"><?php esc_html_e( 'Restore', 'gend-society' ); ?></button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ( empty( $backups ) ) : ?>
                        <li class="gs-mship-empty"><?php esc_html_e( 'No backups recorded yet.', 'gend-society' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var root = document.getElementById('gs-mship-root');
        if (!root) return;
        var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ) ); ?>;
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;
        var memberUrl = <?php echo wp_json_encode( $membership_url ); ?>;

        function toast(msg, kind) {
            var el = document.createElement('div');
            el.className = 'gs-mship-toast' + (kind ? ' is-' + kind : '');
            el.textContent = msg;
            document.body.appendChild(el);
            requestAnimationFrame(function () { el.classList.add('is-visible'); });
            setTimeout(function () { el.classList.remove('is-visible'); setTimeout(function () { el.remove(); }, 300); }, 3500);
        }

        function ajaxAction(action, data, btn) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce', nonce);
            Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
            if (btn) { btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Working...'; }
            return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (btn) { btn.disabled = false; btn.textContent = orig; }
                    if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'Request failed'); }
                    return j.data;
                })
                .catch(function (e) {
                    if (btn) { btn.disabled = false; btn.textContent = orig; }
                    throw e;
                });
        }

        // Tabs
        root.querySelectorAll('.gs-mship-tab-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var tab = b.dataset.tab;
                root.querySelectorAll('.gs-mship-tab-btn').forEach(function (x) { x.classList.toggle('is-active', x === b); });
                root.querySelectorAll('.gs-mship-tab-panel').forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === tab); });
            });
        });

        // Plan upgrade — opens gend.me's checkout in a popup window. On
        // popup close, refresh the dashboard cache so plan/price changes
        // appear immediately (no full page reload required).
        root.querySelectorAll('[data-gs-mship="upgrade-plan"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!memberUrl) { toast('Membership URL unavailable.', 'error'); return; }
                var w = 920, h = 760;
                var x = (window.screen.width - w) / 2;
                var y = (window.screen.height - h) / 2;
                var popup = window.open(memberUrl + '?ui=embed&group=' + encodeURIComponent(btn.dataset.group || ''),
                    'gs_mship_upgrade', 'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
                if (!popup) { toast('Popup blocked. Allow popups and try again.', 'error'); return; }
                var watchdog = setInterval(function () {
                    if (popup.closed) {
                        clearInterval(watchdog);
                        ajaxAction('gs_membership_refresh', {}).then(function () {
                            toast('Refreshing membership...', 'success');
                            setTimeout(function () { location.reload(); }, 600);
                        }).catch(function (e) { toast(e.message, 'error'); });
                    }
                }, 600);
                // Optional: also listen for an explicit success postMessage
                window.addEventListener('message', function onMsg(ev) {
                    if (!ev.data || ev.data.type !== 'gs_membership_changed') return;
                    window.removeEventListener('message', onMsg);
                    clearInterval(watchdog);
                    try { popup.close(); } catch (_) {}
                    ajaxAction('gs_membership_refresh', {}).then(function () { location.reload(); });
                });
            });
        });

        // Add domain
        var addForm = root.querySelector('[data-gs-mship="add-domain"]');
        if (addForm) {
            addForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var input = addForm.querySelector('input[name="domain"]');
                var domain = (input.value || '').trim().toLowerCase();
                if (!domain) return;
                ajaxAction('gs_membership_domain_add', { domain: domain }, addForm.querySelector('button'))
                    .then(function () { toast('Domain added.', 'success'); setTimeout(function(){ location.reload(); }, 800); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        }

        // Verify / remove domain
        root.addEventListener('click', function (ev) {
            var b = ev.target.closest && ev.target.closest('[data-gs-mship]');
            if (!b) return;
            var act = b.dataset.gsMship;

            if (act === 'verify-domain') {
                ajaxAction('gs_membership_domain_verify', { domain: b.dataset.domain }, b)
                    .then(function (d) { toast('Verify: ' + (d.stage || 'ok'), 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            } else if (act === 'remove-domain') {
                if (!confirm('Remove ' + b.dataset.domain + '?')) return;
                ajaxAction('gs_membership_domain_remove', { domain: b.dataset.domain }, b)
                    .then(function () { var li = b.closest('li'); if (li) li.remove(); toast('Domain removed.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            } else if (act === 'backup-now') {
                ajaxAction('gs_membership_backup_now', {}, b)
                    .then(function () { toast('Backup started. Reload in ~2 minutes.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            } else if (act === 'restore-backup') {
                if (!confirm('Restore this backup? The site will rebuild from this snapshot.')) return;
                ajaxAction('gs_membership_backup_restore', { backup_id: b.dataset.id }, b)
                    .then(function () { toast('Restore started. Site will rebuild in 1-3 minutes.', 'success'); })
                    .catch(function (e) { toast(e.message, 'error'); });
            }
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

/**
 * Backwards-compat wrapper. The dashboard page used to call this
 * function with a $remote payload to render the old "Account Overview"
 * + App Management cards. It now produces the unified panel.
 *
 * @param array $remote /install/{id}/membership payload.
 * @return string
 */
function gs_get_remote_account_overview_html( array $remote ) {
    return gs_render_membership_panel( $remote );
}

// ────────────────────────────────────────────────────────────────────────
// Local payload builder for networked subsites
// ────────────────────────────────────────────────────────────────────────

/**
 * Build a /install/{id}/membership-shaped payload from a local WP
 * Ultimo membership object. Lets networked subsites (where WP Ultimo
 * is loaded and $membership is resolvable directly) render the same
 * unified panel without an HTTP roundtrip.
 *
 * Mirrors the shape of gdc_self_hosted_rest_membership's response.
 * Defensive: any unavailable field returns null/empty so the renderer
 * can fall through cleanly.
 *
 * @param mixed $membership WP_Ultimo\Models\Membership or compatible.
 * @return array|null
 */
function gs_membership_payload_from_local( $membership ) {

    if ( ! $membership || ! is_object( $membership ) || ! method_exists( $membership, 'get_id' ) ) {
        return null;
    }

    // Pick the first site associated with this membership as the
    // primary install — same logic gend.me's listener uses.
    $site = null;
    if ( method_exists( $membership, 'get_sites' ) ) {
        $sites = (array) $membership->get_sites();
        $site = ! empty( $sites ) ? array_shift( $sites ) : null;
    }

    $fmt_date = function ( $s ) {
        if ( $s === '' || $s === '0000-00-00 00:00:00' || $s === null ) return '';
        $ts = strtotime( $s );
        if ( ! $ts ) return '';
        return wp_date( get_option( 'date_format' ) . ' g:i a', $ts );
    };

    // Plans — for hosting we pick the highest-priced product as the
    // main tier (multi-product memberships have main tier + storage
    // upgrades; the main tier almost always has the higher amount).
    $dash_plan = null;
    $host_plan = null;
    $all = method_exists( $membership, 'get_all_products' ) ? (array) $membership->get_all_products() : array();
    foreach ( $all as $row ) {
        $prod = is_array( $row ) && isset( $row['product'] ) ? $row['product'] : null;
        if ( ! $prod || ! is_object( $prod ) || ! method_exists( $prod, 'get_group' ) ) continue;
        $g = (string) $prod->get_group();
        if ( $g === 'dashboard' && ! $dash_plan ) $dash_plan = $prod;
        if ( $g === 'hosting' ) {
            if ( ! $host_plan ) {
                $host_plan = $prod;
            } elseif ( method_exists( $prod, 'get_amount' ) && method_exists( $host_plan, 'get_amount' ) && (float) $prod->get_amount() > (float) $host_plan->get_amount() ) {
                $host_plan = $prod;
            }
        }
    }
    if ( ! $dash_plan && method_exists( $membership, 'get_plan' ) ) {
        $dash_plan = $membership->get_plan();
    }

    $serialize_plan = function ( $plan ) use ( $membership ) {
        if ( ! $plan || ! is_object( $plan ) ) return null;
        $amount = 0.0;
        if ( method_exists( $membership, 'get_amount_for_product' ) ) {
            try { $amount = (float) $membership->get_amount_for_product( $plan ); } catch ( \Throwable $e ) {}
        }
        if ( $amount <= 0 && method_exists( $plan, 'get_amount' ) ) {
            $amount = (float) $plan->get_amount();
        }
        return array(
            'id'           => method_exists( $plan, 'get_id' )          ? (int) $plan->get_id()          : 0,
            'name'         => method_exists( $plan, 'get_name' )        ? (string) $plan->get_name()    : '',
            'slug'         => method_exists( $plan, 'get_slug' )        ? (string) $plan->get_slug()    : '',
            'description'  => method_exists( $plan, 'get_description' ) ? wp_strip_all_tags( (string) $plan->get_description() ) : '',
            'image'        => method_exists( $plan, 'get_featured_image' ) ? (string) $plan->get_featured_image( 'thumbnail' ) : '',
            'amount'       => $amount,
            'amount_label' => $amount > 0 && function_exists( 'wu_format_currency' ) ? wu_format_currency( $amount, method_exists( $plan, 'get_currency' ) ? (string) $plan->get_currency() : '' ) : '',
            'duration_unit'=> method_exists( $plan, 'get_duration_unit' ) ? (string) $plan->get_duration_unit() : 'month',
        );
    };

    // Customer
    $customer_payload = null;
    $customer = method_exists( $membership, 'get_customer' ) ? $membership->get_customer() : null;
    if ( $customer ) {
        $user_id  = method_exists( $customer, 'get_user_id' ) ? (int) $customer->get_user_id() : 0;
        $username = method_exists( $customer, 'get_username' ) ? (string) $customer->get_username() : '';
        $customer_payload = array(
            'user_id'  => $user_id,
            'username' => $username,
            'name'     => method_exists( $customer, 'get_display_name' ) ? (string) $customer->get_display_name() : $username,
            'email'    => method_exists( $customer, 'get_email_address' ) ? (string) $customer->get_email_address() : '',
            'avatar'   => function_exists( 'get_avatar_url' ) && method_exists( $customer, 'get_email_address' ) ? (string) get_avatar_url( $customer->get_email_address(), array( 'size' => 64 ) ) : '',
        );
    }

    // Group
    $group_payload = null;
    $gid = $site && method_exists( $site, 'get_meta' ) ? (int) $site->get_meta( 'gdc_bp_group_id', 0 ) : 0;
    if ( $gid > 0 ) {
        $g_name = sprintf( __( 'Group #%d', 'gend-society' ), $gid );
        $g_slug = '';
        global $wpdb;
        $tbl = $wpdb->base_prefix . 'bp_groups';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT name, slug FROM {$tbl} WHERE id = %d", $gid ) );
        if ( $row ) {
            if ( ! empty( $row->name ) ) $g_name = (string) $row->name;
            if ( ! empty( $row->slug ) ) $g_slug = (string) $row->slug;
        }
        $group_payload = array(
            'id'             => $gid,
            'name'           => $g_name,
            'slug'           => $g_slug,
            'avatar'         => '',
            'members_count'  => 0,
            'projects_count' => 0,
            'files_count'    => 0,
            'messages_count' => 0,
        );
    }

    // Status / billing / dates
    $billing_amount   = method_exists( $membership, 'get_amount' )        ? (float) $membership->get_amount()        : 0.0;
    $billing_currency = method_exists( $membership, 'get_currency' )      ? (string) $membership->get_currency()     : '';
    $billing_unit     = method_exists( $membership, 'get_duration_unit' ) ? (string) $membership->get_duration_unit() : 'month';
    $status           = method_exists( $membership, 'get_status' )        ? (string) $membership->get_status()       : '';

    $membership_id  = (int) $membership->get_id();
    $hub_url        = trailingslashit( (string) network_home_url( '/' ) );
    $membership_url = $membership_id > 0 ? $hub_url . 'my-account/membership/' . $membership_id . '/' : $hub_url . 'my-account/memberships/';

    $app_url = '';
    $app_title = '';
    if ( $site ) {
        if ( method_exists( $site, 'get_active_site_url' ) ) {
            try { $app_url = (string) $site->get_active_site_url(); } catch ( \Throwable $e ) {}
        }
        if ( method_exists( $site, 'get_title' ) ) $app_title = (string) $site->get_title();
    }

    return array(
        'install_id'     => $site && method_exists( $site, 'get_meta' ) ? (string) $site->get_meta( 'gdc_install_id', '' ) : '',
        'membership_id'  => $membership_id,
        'membership_url' => $membership_url,
        'embed_url'      => $membership_id > 0 ? add_query_arg( 'ui', 'embed', $membership_url ) : '',
        'hub_url'        => $hub_url,
        'app_url'        => $app_url,
        'app_title'      => $app_title,
        'status'         => $status,
        'status_label'   => function_exists( 'wu_get_membership_status_label' ) ? (string) wu_get_membership_status_label( $status ) : ucfirst( $status ),
        'billing'        => array(
            'amount'   => $billing_amount,
            'currency' => $billing_currency,
            'unit'     => $billing_unit,
            'label'    => $billing_amount > 0 && function_exists( 'wu_format_currency' ) ? wu_format_currency( $billing_amount, $billing_currency ) : '',
        ),
        'dates' => array(
            'created'   => method_exists( $membership, 'get_date_created' )    ? $fmt_date( (string) $membership->get_date_created() )    : '',
            'activated' => method_exists( $membership, 'get_date_activated' )  ? $fmt_date( (string) $membership->get_date_activated() )  : '',
            'expires'   => method_exists( $membership, 'get_date_expiration' ) ? $fmt_date( (string) $membership->get_date_expiration() ) : '',
            'renews'    => method_exists( $membership, 'get_date_renewed' )    ? $fmt_date( (string) $membership->get_date_renewed() )    : '',
        ),
        'migration' => array(
            'stage' => '',
            'index' => 0,
            'live'  => false,
            'steps' => array( 'prepare', 'export', 'provision', 'verify', 'live' ),
        ),
        'dashboard_plan' => $serialize_plan( $dash_plan ),
        'hosting_plan'   => $serialize_plan( $host_plan ),
        'customer'       => $customer_payload,
        'group'          => $group_payload,
        'orders'         => array(),
        'backups'        => array(),
        'domains'        => array(),
        'cache_seconds'  => 0,
    );
}
