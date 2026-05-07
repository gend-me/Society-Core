<?php
/**
 * Feature Access Plan gating.
 *
 * Customer's gend.me Dashboard (Feature Access) Plan defines an
 * `wu_gdc_allowed_areas` meta listing which wp-admin "areas" they
 * can use. Areas map to admin menu slug prefixes (gs_features_area_map):
 *
 *   Content Builder  → app, write
 *   Store Owner      → app, write, store
 *   Social Connector → app, write, store, social
 *
 * Empty allowed_areas = allow all (matches gend.me-side admin UX).
 *
 * Behaviour:
 *
 *   - Menu items are NOT removed for non-allowed areas. They stay
 *     visible so customers see what's possible at higher tiers.
 *     When a non-allowed page is accessed, we redirect to the
 *     upgrade prompt page (see inc/pages/feature-upgrade.php).
 *
 *   - "Always allowed" pages (Dashboard, Users, Connect-to-gend.me)
 *     bypass the redirect entirely.
 *
 *   - Network super admins bypass everything.
 *
 *   - Features menu visibility:
 *       - Container / self-hosted (paired install) → always visible
 *         so customers can see + manage their own plan.
 *       - Networked subsites → hidden (network admin manages
 *         features at the network level).
 *       - Network super admins → always visible.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const GS_FEATURES_CACHE_OPTION         = 'gs_features_cache';
const GS_FEATURES_CACHE_EXPIRES_OPTION = 'gs_features_cache_expires';
const GS_FEATURES_DEFAULT_TTL          = 5 * MINUTE_IN_SECONDS;

/**
 * Map allowed-area slug → admin menu slug prefix(es). Anything
 * matching one of the prefixes when the area is allowed stays
 * accessible.
 */
function gs_features_area_map() {
    return apply_filters( 'gs_features_area_map', array(
        'app'      => array( 'gs-app' ),
        'write'    => array( 'gs-content', 'gs-write' ),
        'store'    => array( 'gs-store', 'gdc-store-' ),
        'social'   => array( 'gs-social', 'gdc-social-' ),
        'reward'   => array( 'gs-reward', 'gdc-reward-' ),
        'features' => array( 'gs-features' ),
        'hosting'  => array( 'gs-hosting' ),
        'projects' => array( 'gs-projects' ),
        'groups'   => array( 'gs-groups' ),
    ) );
}

/**
 * Slugs that bypass feature gating entirely. Always accessible
 * regardless of plan.
 *
 * Features menu (gs-features) is conditional: shown for paired
 * installs (container / self-hosted) so customers can see what
 * tier they're on, hidden on networked subsites where the network
 * admin handles feature management.
 */
function gs_features_always_allowed() {
    $allowed = array(
        'index.php',
        'gs-users',
        'gs-portal-connect',
        'gs-feature-upgrade',
    );

    $is_paired       = (string) get_option( 'gs_install_token', '' ) !== '';
    $is_super_admin  = is_multisite() && current_user_can( 'manage_network' );
    if ( $is_paired || $is_super_admin ) {
        $allowed[] = 'gs-features';
    }

    return apply_filters( 'gs_features_always_allowed', $allowed );
}

/**
 * Return the area slug a given menu page belongs to (for the
 * upgrade-redirect to know which area the user needs unlocked).
 * Returns '' when the slug isn't gated.
 */
function gs_features_area_for_slug( string $slug ): string {
    $map = gs_features_area_map();
    foreach ( $map as $area => $prefixes ) {
        foreach ( $prefixes as $p ) {
            if ( $p !== '' && strncmp( $slug, $p, strlen( $p ) ) === 0 ) {
                return $area;
            }
        }
    }
    return '';
}

/**
 * Check if a menu slug starts with any of the allowed prefixes.
 */
function gs_features_slug_allowed( string $slug, array $allowed_prefixes ): bool {
    foreach ( $allowed_prefixes as $prefix ) {
        if ( $prefix !== '' && strncmp( $slug, $prefix, strlen( $prefix ) ) === 0 ) {
            return true;
        }
    }
    return false;
}

/**
 * Hide ONLY the Features menu when not allowed (paired-install /
 * super-admin gating, separate from the upgrade flow). Other
 * non-allowed menus stay visible so customers see what's possible.
 */
add_action( 'admin_menu', 'gs_features_filter_features_menu', 999 );

function gs_features_filter_features_menu() {
    global $menu, $submenu;
    if ( empty( $menu ) || ! is_array( $menu ) ) return;

    $always = gs_features_always_allowed();
    if ( in_array( 'gs-features', $always, true ) ) return; // visible

    foreach ( $menu as $key => $entry ) {
        if ( ! is_array( $entry ) || empty( $entry[2] ) ) continue;
        $slug = (string) $entry[2];
        if ( $slug === 'gs-features' || strncmp( $slug, 'gs-features', 11 ) === 0 ) {
            unset( $menu[ $key ] );
            if ( isset( $submenu[ $slug ] ) ) unset( $submenu[ $slug ] );
        }
    }
}

/**
 * Redirect non-allowed admin page accesses to the upgrade prompt.
 * Runs at admin_init so it fires BEFORE the page callback so
 * customers don't see a flash of locked content.
 */
add_action( 'admin_init', 'gs_features_enforce_redirect' );

function gs_features_enforce_redirect() {

    // Super admins + network admins bypass entirely.
    if ( is_multisite() && current_user_can( 'manage_network' ) ) return;

    // Only enforce on real admin page loads, not AJAX / cron / REST.
    if ( wp_doing_ajax() || wp_doing_cron() ) return;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
    if ( ! is_admin() ) return;

    $features = gs_features_get_cached();
    if ( ! is_array( $features ) ) return;
    $allowed_areas = isset( $features['allowed_areas'] ) ? (array) $features['allowed_areas'] : array();
    if ( empty( $allowed_areas ) ) return; // empty = allow all

    // Resolve current page slug.
    $page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
    if ( $page === '' ) {
        // index.php / users.php / etc. — match against the basename.
        $page = basename( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) );
    }
    if ( $page === '' ) return;

    // Always-allowed slugs (Dashboard, Users, Connect, etc.).
    if ( in_array( $page, gs_features_always_allowed(), true ) ) return;

    $required_area = gs_features_area_for_slug( $page );
    if ( $required_area === '' ) return; // not a gated area at all

    if ( in_array( $required_area, $allowed_areas, true ) ) return; // already unlocked

    // Not allowed — redirect to the upgrade prompt.
    $upgrade_url = add_query_arg( array(
        'page'     => 'gs-feature-upgrade',
        'required' => $required_area,
        'from'     => $page,
    ), admin_url( 'admin.php' ) );
    wp_safe_redirect( $upgrade_url );
    exit;
}

/**
 * Return cached feature gates, refetching when expired. Returns
 * null when not paired or fetch errored.
 */
function gs_features_get_cached() {

    $expires = (int) get_option( GS_FEATURES_CACHE_EXPIRES_OPTION, 0 );
    if ( $expires > time() ) {
        $cached = get_option( GS_FEATURES_CACHE_OPTION, null );
        if ( is_array( $cached ) ) return $cached;
    }
    $fresh = gs_features_fetch_remote();
    if ( is_array( $fresh ) ) {
        $ttl = isset( $fresh['cache_seconds'] ) ? max( 60, (int) $fresh['cache_seconds'] ) : GS_FEATURES_DEFAULT_TTL;
        update_option( GS_FEATURES_CACHE_OPTION, $fresh, false );
        update_option( GS_FEATURES_CACHE_EXPIRES_OPTION, time() + $ttl, false );
        return $fresh;
    }
    $cached = get_option( GS_FEATURES_CACHE_OPTION, null );
    return is_array( $cached ) ? $cached : null;
}

/**
 * One-shot fetch from gend.me /install/{install_id}/features.
 */
function gs_features_fetch_remote() {

    $install_id    = (string) get_option( 'gs_install_id', '' );
    $install_token = (string) get_option( 'gs_install_token', '' );
    $gend_base     = (string) get_option( 'gs_gend_base_url', '' );
    if ( $install_id === '' || $install_token === '' || $gend_base === '' ) return null;

    $endpoint = trailingslashit( $gend_base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/features';
    $response = wp_remote_get( $endpoint, array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $install_token,
            'Accept'        => 'application/json',
        ),
    ) );
    if ( is_wp_error( $response ) ) return null;
    if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
    $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
    return is_array( $decoded ) ? $decoded : null;
}

function gs_features_invalidate() {
    delete_option( GS_FEATURES_CACHE_EXPIRES_OPTION );
}
add_action( 'wp_login', 'gs_features_invalidate' );
add_action( 'gs_features_invalidate', 'gs_features_invalidate' );
