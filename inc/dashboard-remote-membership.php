<?php
/**
 * Remote-membership fetch + render for container sites.
 *
 * On gend.me itself the dashboard reads $membership directly from
 * WP Ultimo. On a customer container WP Ultimo isn't loaded, so the
 * Plan Details section silently disappeared post-migration. This
 * file fills that gap by pulling the same payload from gend.me's
 * /install/{install_id}/membership endpoint (auto-paired by
 * oauth-login.php → /connect-by-install) and rendering the same UI.
 *
 * Auth: gs_install_token (set by the auto-pair handshake on the
 * owner's first OAuth login). Cached for 5 minutes to avoid hitting
 * gend.me on every dashboard render.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const GS_REMOTE_MEMBERSHIP_CACHE_OPTION         = 'gs_remote_membership_cache';
const GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION = 'gs_remote_membership_cache_expires';
const GS_REMOTE_MEMBERSHIP_DEFAULT_TTL          = 5 * MINUTE_IN_SECONDS;

/**
 * Return cached remote-membership payload, refetching when expired.
 * Returns null when not paired or fetch errored.
 */
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

    // Fetch failed — return any stale cache rather than blanking the panel.
    $cached = get_option( GS_REMOTE_MEMBERSHIP_CACHE_OPTION, null );
    return is_array( $cached ) ? $cached : null;
}

/**
 * One-shot fetch from gend.me /install/{install_id}/membership.
 * Returns array on success, null on any auth/HTTP/JSON error.
 */
function gs_remote_membership_fetch() {

    $install_id    = (string) get_option( 'gs_install_id', '' );
    $install_token = (string) get_option( 'gs_install_token', '' );
    $gend_base     = (string) get_option( 'gs_gend_base_url', '' );

    if ( $install_id === '' || $install_token === '' || $gend_base === '' ) {
        return null;
    }

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

    $raw = (string) wp_remote_retrieve_body( $response );
    $clean = trim( str_replace( "\xEF\xBB\xBF", '', $raw ) );
    $data = json_decode( $clean, true );
    if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/(\{.*\})/s', $clean, $m ) ) {
        $data = json_decode( $m[1], true );
    }
    return is_array( $data ) ? $data : null;
}

/**
 * Force-bust the remote-membership cache. Wired to a couple of common
 * "something material changed" hooks so customers see plan changes
 * within seconds instead of waiting out the 5-min TTL.
 */
function gs_remote_membership_invalidate() {
    delete_option( GS_REMOTE_MEMBERSHIP_CACHE_EXPIRES_OPTION );
}
add_action( 'wp_login', 'gs_remote_membership_invalidate' );
add_action( 'gs_remote_membership_invalidate', 'gs_remote_membership_invalidate' );

/**
 * Render the Plan Details / Account Overview section from the
 * remote-membership payload. Mirrors gs_get_account_overview_html's
 * shape so swapping in remote data on container sites is visually
 * indistinguishable from the gend.me-hub render.
 *
 * @param array $data Remote payload from /install/{install_id}/membership.
 * @return string
 */
function gs_get_remote_account_overview_html( array $data ) {

    $hub_url        = isset( $data['hub_url'] ) ? (string) $data['hub_url'] : '';
    $membership_url = isset( $data['membership_url'] ) ? (string) $data['membership_url'] : ( $hub_url ? trailingslashit( $hub_url ) . 'my-account/memberships/' : '' );

    $dash_plan = isset( $data['dashboard_plan'] ) && is_array( $data['dashboard_plan'] ) ? $data['dashboard_plan'] : null;
    $host_plan = isset( $data['hosting_plan'] )   && is_array( $data['hosting_plan'] )   ? $data['hosting_plan']   : null;
    $customer  = isset( $data['customer'] )       && is_array( $data['customer'] )       ? $data['customer']       : null;
    $group     = isset( $data['group'] )          && is_array( $data['group'] )          ? $data['group']          : null;

    ob_start();

    echo '<div class="gs-admin-grid">';

    // ── Plan column ───────────────────────────────────────────────────
    echo '<div class="gs-admin-card" style="padding: 24px;">';
    echo '<h2 style="margin-top:0; font-size: 1.25rem;">' . esc_html__( 'App Builder Membership', 'gend-society' ) . '</h2>';

    echo '<h3 style="margin-top:20px; font-size: 0.95rem; color: var(--gs-muted);">' . esc_html__( 'Dashboard Plan', 'gend-society' ) . '</h3>';
    echo '<div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">';
    $d_img = $dash_plan && ! empty( $dash_plan['image'] ) ? (string) $dash_plan['image'] : '';
    echo '<div style="width: 48px; height: 48px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2);">' .
         ( $d_img ? '<img src="' . esc_url( $d_img ) . '" style="width:100%; height:100%; object-fit: cover;" alt="" />' : '<span class="dashicons dashicons-admin-site" style="color: var(--gs-muted);"></span>' ) .
         '</div>';
    echo '<div>';
    $d_name = $dash_plan && ! empty( $dash_plan['name'] ) ? (string) $dash_plan['name'] : __( 'None', 'gend-society' );
    echo '<strong style="display: block; font-size: 1.1rem; color: #fff;">' . esc_html( $d_name ) . '</strong>';
    if ( $dash_plan && ! empty( $dash_plan['description'] ) ) {
        echo '<span style="font-size: 0.85rem; color: var(--gs-muted);">' . esc_html( wp_trim_words( (string) $dash_plan['description'], 10, '...' ) ) . '</span>';
    }
    echo '</div></div>';

    echo '<h3 style="margin-top:0px; font-size: 0.95rem; color: var(--gs-muted);">' . esc_html__( 'Hosting Plan', 'gend-society' ) . '</h3>';
    echo '<div style="display: flex; align-items: center; gap: 16px; margin-bottom: 24px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">';
    $h_img = $host_plan && ! empty( $host_plan['image'] ) ? (string) $host_plan['image'] : '';
    echo '<div style="width: 48px; height: 48px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2);">' .
         ( $h_img ? '<img src="' . esc_url( $h_img ) . '" style="width:100%; height:100%; object-fit: cover;" alt="" />' : '<span class="dashicons dashicons-cloud" style="color: var(--gs-muted);"></span>' ) .
         '</div>';
    echo '<div>';
    $h_name = $host_plan && ! empty( $host_plan['name'] ) ? (string) $host_plan['name'] : __( 'None', 'gend-society' );
    echo '<strong style="display: block; font-size: 1.1rem; color: #fff;">' . esc_html( $h_name ) . '</strong>';
    if ( $host_plan && ! empty( $host_plan['description'] ) ) {
        echo '<span style="font-size: 0.85rem; color: var(--gs-muted);">' . esc_html( wp_trim_words( (string) $host_plan['description'], 10, '...' ) ) . '</span>';
    }
    echo '</div></div>';

    if ( $membership_url !== '' ) {
        echo '<div style="text-align: right;">';
        echo '<a class="gs-btn" href="' . esc_url( $membership_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Change Membership', 'gend-society' ) . '</a>';
        echo '</div>';
    }
    echo '</div>'; // End Plan column

    // ── Owner column ──────────────────────────────────────────────────
    echo '<div class="gs-admin-card" style="padding: 24px;">';
    echo '<h2 style="margin-top:0; font-size: 1.25rem;">' . esc_html__( 'Account Owner', 'gend-society' ) . '</h2>';

    if ( $customer ) {
        $username = ! empty( $customer['username'] ) ? (string) $customer['username'] : '';
        $name     = ! empty( $customer['name'] )     ? (string) $customer['name']     : $username;
        $avatar   = ! empty( $customer['avatar'] )   ? (string) $customer['avatar']   : '';
        $profile  = $hub_url !== '' && $username !== '' ? trailingslashit( $hub_url ) . 'members/' . rawurlencode( $username ) . '/' : '';
        $msg      = $profile !== '' ? $profile . 'messages/compose/?r=' . rawurlencode( $username ) : '';

        echo '<div style="display: flex; align-items: center; gap: 16px; margin-top: 20px; padding-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.05);">';
        if ( $profile !== '' ) echo '<a href="' . esc_url( $profile ) . '" target="_blank" rel="noopener" style="border-radius: 50%; overflow: hidden; width: 64px; height: 64px; display: block;">';
        if ( $avatar !== '' ) {
            echo '<img src="' . esc_url( $avatar ) . '" alt="" style="width: 64px; height: 64px; border-radius: 50%;" />';
        } else {
            echo '<span class="dashicons dashicons-admin-users" style="font-size: 48px; color: var(--gs-muted);"></span>';
        }
        if ( $profile !== '' ) echo '</a>';
        echo '<div>';
        if ( $profile !== '' ) {
            echo '<a href="' . esc_url( $profile ) . '" target="_blank" rel="noopener" style="display: block; font-size: 1.1rem; font-weight: 600; color: #fff; text-decoration: none;">' . esc_html( $name ) . '</a>';
        } else {
            echo '<span style="display: block; font-size: 1.1rem; font-weight: 600; color: #fff;">' . esc_html( $name ) . '</span>';
        }
        if ( $msg !== '' ) {
            echo '<a class="gs-btn gs-btn-secondary" href="' . esc_url( $msg ) . '" target="_blank" rel="noopener" style="margin-top: 10px; padding: 4px 12px; font-size: 0.8rem;">' . esc_html__( 'Message', 'gend-society' ) . '</a>';
        }
        echo '</div></div>';
    } else {
        echo '<p style="color: var(--gs-muted); margin-top: 20px;">' . esc_html__( 'Customer information unavailable.', 'gend-society' ) . '</p>';
    }

    // Group block
    echo '<h3 style="margin-top: 24px; font-size: 1.05rem; color: #fff;">' . esc_html__( 'Associated Social Group', 'gend-society' ) . '</h3>';
    if ( $group && ! empty( $group['id'] ) ) {
        $g_name   = ! empty( $group['name'] ) ? (string) $group['name'] : sprintf( __( 'Group #%d', 'gend-society' ), (int) $group['id'] );
        $g_avatar = ! empty( $group['avatar'] ) ? (string) $group['avatar'] : '';
        $g_slug   = ! empty( $group['slug'] ) ? (string) $group['slug'] : (string) $group['id'];
        $g_link   = $hub_url !== '' ? trailingslashit( $hub_url ) . 'groups/' . sanitize_title( $g_slug ) . '/' : '';

        echo '<div style="display: flex; align-items: center; gap: 16px; margin-top: 16px;">';
        if ( $g_link !== '' ) echo '<a href="' . esc_url( $g_link ) . '" target="_blank" rel="noopener" style="border-radius: 8px; overflow: hidden; display: block; width: 48px; height: 48px;">';
        if ( $g_avatar !== '' ) {
            echo '<img src="' . esc_url( $g_avatar ) . '" alt="" style="width: 48px; height: 48px; border-radius: 8px;" />';
        } else {
            echo '<span class="dashicons dashicons-groups" style="font-size: 32px; color: var(--gs-muted);"></span>';
        }
        if ( $g_link !== '' ) echo '</a>';
        echo '<div>';
        if ( $g_link !== '' ) {
            echo '<a href="' . esc_url( $g_link ) . '" target="_blank" rel="noopener" style="color: #fff; font-weight: 600; text-decoration: none; display: block; margin-bottom: 8px;">' . esc_html( $g_name ) . '</a>';
            echo '<a class="gs-btn gs-btn-secondary" href="' . esc_url( $g_link ) . '" target="_blank" rel="noopener" style="padding: 4px 12px; font-size: 0.8rem;">' . esc_html__( 'Open Group', 'gend-society' ) . '</a>';
        } else {
            echo '<span style="color: #fff; font-weight: 600;">' . esc_html( $g_name ) . '</span>';
        }
        echo '</div></div>';
    } else {
        echo '<p style="color: var(--gs-muted); margin-top: 10px;">' . esc_html__( 'No group linked to this App.', 'gend-society' ) . '</p>';
    }

    echo '</div>'; // End Owner column
    echo '</div>'; // End Grid

    return (string) ob_get_clean();
}
