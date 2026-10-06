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
 * Granular submenu-level gating. Each entry maps a real wp-admin page
 * slug to the coarse area it lives under (for the "whole area unlocked"
 * check) and a specific child key that can be allowed independently of
 * the rest of the area (e.g. a plan can grant `store.sales_team`
 * without granting `store.store_management`).
 *
 * Selecting the bare area slug (e.g. `store`) still grants every child
 * beneath it — this table only adds a finer-grained option on top of
 * the existing coarse one, it never narrows it. Existing plans that
 * only ever stored bare area slugs keep working unchanged.
 */
function gs_features_granular_slug_map() {
    return apply_filters( 'gs_features_granular_slug_map', array(
        'site-editor.php'             => array( 'area' => 'app',      'child' => 'app.theme_builder' ),
        'blog-manager'                => array( 'area' => 'app',      'child' => 'app.content_campaigns' ),
        'talk-flows'                  => array( 'area' => 'app',      'child' => 'app.talk_flows' ),
        'gdc-store-settings'          => array( 'area' => 'store',    'child' => 'store.store_management' ),
        'st_sales_team'               => array( 'area' => 'store',    'child' => 'store.sales_team' ),
        'psoo-projects'               => array( 'area' => 'store',    'child' => 'store.project_services' ),
        'gdc-social-network-settings' => array( 'area' => 'social',   'child' => 'social.social_profiles' ),
        // Real page slug is 'gdc-reward-point-bank' (confirmed by reading
        // reward-programs.php's own add_submenu_page() call) - the old key
        // 'gs-rewards' here never matched a real $_GET['page'] value, so
        // Point Bank was silently ungated regardless of plan until this fix.
        'gdc-reward-point-bank'       => array( 'area' => 'social',   'child' => 'social.point_bank' ),
        'gend-contracts-payments'     => array( 'area' => 'social',   'child' => 'social.contracts_payments' ),
        'gs-shortcodes'               => array( 'area' => 'features', 'child' => 'features.shortcodes' ),
        'plugins.php'                 => array( 'area' => 'features', 'child' => 'features.code_packages' ),
        'update-core.php'             => array( 'area' => 'features', 'child' => 'features.updates' ),
    ) );
}

/**
 * The 4 restructured "hub" pages (App/Store/Social/Features landing
 * pages). A customer who only unlocked one specific child under an
 * area still needs to be able to open the hub page itself to reach
 * it, so hub access is granted when ANY child of the area is allowed,
 * not just the bare area slug.
 */
function gs_features_hub_area_map() {
    return apply_filters( 'gs_features_hub_area_map', array(
        'gs-content'  => 'app',
        'gs-store'    => 'store',
        'gs-social'   => 'social',
        'gs-features' => 'features',
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
 * Captures a real, server-detectable tab signal from the blocked
 * request, if one exists. Different plugins on this network use
 * different query param names for their own internal tab navigation
 * (tab, subtab, gdc_tab, section, plugin_status) - checked in that
 * order, first match wins. site-editor.php has no tab/section-style
 * param at all; its only 2 server-inspectable states are the postType/
 * path pair core WP itself reads on load (everything else is 100%
 * client-side React routing the server never sees).
 *
 * Only catches query-string-based tabs - a tab implemented as client-
 * side hash routing or as Vue-only state (this plugin's own admin pages
 * use a `section`/`display_all` reactive var, never reflected in the
 * URL) never reaches PHP and can't be forwarded this way.
 *
 * @since 2.4.0
 * @param string $page The resolved page slug (gs_features_enforce_redirect()'s $page).
 * @return string
 */
function gs_features_capture_tab_signal( string $page ): string {

    foreach ( array( 'tab', 'section', 'subtab', 'gdc_tab', 'plugin_status' ) as $candidate ) {
        if ( isset( $_GET[ $candidate ] ) && $_GET[ $candidate ] !== '' ) {
            return sanitize_key( (string) wp_unslash( $_GET[ $candidate ] ) );
        }
    }

    if ( $page === 'site-editor.php' ) {
        $post_type = isset( $_GET['postType'] ) ? sanitize_key( (string) wp_unslash( $_GET['postType'] ) ) : '';
        $path      = isset( $_GET['path'] )     ? sanitize_key( (string) wp_unslash( $_GET['path'] ) )     : '';
        if ( $post_type === 'wp_template_part' ) return 'wp_template_part';
        if ( $post_type === 'wp_block' || strpos( $path, 'pattern' ) !== false ) return 'patterns';
    }

    return '';
}

/**
 * Resolves which "Upgrade Destination Pages" URL (if any) applies to
 * the current blocked visit, most-specific-first: destination+tab,
 * then bare destination, then no match. Kept as a small pure function
 * (not inlined into the render) so it's directly unit-testable without
 * a browser.
 *
 * @since 2.4.0
 * @param string $required_child Dotted area.destination key, e.g. "store.sales_team". May be empty.
 * @param string $tab            Tab signal captured via gs_features_capture_tab_signal(). May be empty.
 * @param array  $learn_more_pages The `learn_more_pages` field from the cached /install/{id}/features payload.
 * @return string Absolute URL, or '' if nothing matches.
 */
function gs_features_resolve_learn_more_url( string $required_child, string $tab, array $learn_more_pages ): string {

    if ( $required_child === '' ) {
        return '';
    }

    if ( $tab !== '' && isset( $learn_more_pages[ "{$required_child}.{$tab}" ] ) ) {
        return (string) $learn_more_pages[ "{$required_child}.{$tab}" ];
    }

    if ( isset( $learn_more_pages[ $required_child ] ) ) {
        return (string) $learn_more_pages[ $required_child ];
    }

    return '';
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

    // Granular slugs (the 12 restructured submenu items) mostly live
    // under real plugin page slugs that were never part of the coarse
    // prefix map, so `$required_area` alone can't be used to decide
    // "not gated" — a page only escapes gating when NEITHER lookup
    // recognizes it.
    $granular_map   = gs_features_granular_slug_map();
    $granular_entry = $granular_map[ $page ] ?? null;
    $required_area  = gs_features_area_for_slug( $page );

    if ( $required_area === '' && $granular_entry === null ) return; // not a gated area at all

    if ( $required_area !== '' && in_array( $required_area, $allowed_areas, true ) ) return; // whole area already unlocked (coarse prefix match)

    // Whole-area unlock via the granular map's own area field — several
    // restructured submenu pages (site-editor.php, plugins.php, ...) never
    // matched a coarse prefix to begin with, so `$required_area` above is
    // often empty for them; this is the "app"/"store"/etc. bare-area grant
    // for those specific slugs.
    if ( $granular_entry !== null && in_array( $granular_entry['area'], $allowed_areas, true ) ) {
        return;
    }

    // Granular child check — this specific submenu item unlocked on its own.
    if ( $granular_entry !== null && in_array( $granular_entry['child'], $allowed_areas, true ) ) {
        return;
    }

    // Hub page bypass — allow opening the area's landing page if ANY
    // of its children were individually unlocked, so the customer has
    // somewhere to click through to reach what they do have.
    $hub_map = gs_features_hub_area_map();
    if ( isset( $hub_map[ $page ] ) ) {
        foreach ( $granular_map as $entry ) {
            if ( $entry['area'] === $hub_map[ $page ] && in_array( $entry['child'], $allowed_areas, true ) ) {
                return;
            }
        }
    }

    // Not allowed — redirect to the upgrade prompt. `required` always
    // carries the bare coarse area (unchanged - feature-upgrade.php's own
    // tier-highlighting logic matches against bare area arrays and would
    // break if this became a dotted value). The specific submenu child,
    // when this slug has one, goes in a SEPARATE `required_child` param -
    // sanitize_key() strips dots, so it can't safely hold `store.sales_team`
    // itself. Also forward a tab signal, if the blocked request carried
    // one, so an "Upgrade Destination Pages" assignment authored on
    // gend.me can target it.
    $redirect_area = $required_area !== '' ? $required_area : $granular_entry['area'];
    $required_child = $granular_entry !== null ? $granular_entry['child'] : '';
    $tab_param = gs_features_capture_tab_signal( $page );
    $upgrade_url = add_query_arg( array_filter( array(
        'page'            => 'gs-feature-upgrade',
        'required'        => $redirect_area,
        'required_child'  => $required_child,
        'from'            => $page,
        'tab'             => $tab_param,
    ) ), admin_url( 'admin.php' ) );
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
