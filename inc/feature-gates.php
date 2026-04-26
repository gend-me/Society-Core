<?php
/**
 * GenD Society: pull Feature Access Plan gates from gend.me and apply
 * them to the wp-admin menu.
 *
 * Allowed-areas come from the customer's Dashboard (Feature Access) Plan
 * meta wu_gdc_allowed_areas, fetched via /install/{install_id}/features.
 *
 * If allowed_areas is empty we treat that as "allow all" (matches the
 * gend.me-side admin UI behaviour: leaving it empty allows everything).
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

const GS_FEATURES_CACHE_OPTION         = 'gs_features_cache';
const GS_FEATURES_CACHE_EXPIRES_OPTION = 'gs_features_cache_expires';
const GS_FEATURES_DEFAULT_TTL          = 5 * MINUTE_IN_SECONDS;

// Map allowed-area slug → admin menu slug prefix(es). Anything matching one
// of the prefixes when the area is allowed stays visible.
function gs_features_area_map() {
    return apply_filters('gs_features_area_map', array(
        'app'      => array('gs-app'),
        'write'    => array('gs-content', 'gs-write'),
        'store'    => array('gs-store', 'gdc-store-'),
        'social'   => array('gs-social', 'gdc-social-'),
        'reward'   => array('gs-reward', 'gdc-reward-'),
        'features' => array('gs-features'),
        'hosting'  => array('gs-hosting'),
        'projects' => array('gs-projects'),
        'groups'   => array('gs-groups'),
    ));
}

// Always-allowed top-level menu slugs (regardless of plan).
function gs_features_always_allowed() {
    return apply_filters('gs_features_always_allowed', array(
        'index.php',          // Dashboard
        'gs-users',           // Users
        'gs-portal-connect',  // Connect to gend.me page
    ));
}

add_action('admin_menu', 'gs_features_filter_menu', 999);

function gs_features_filter_menu() {
    global $menu, $submenu;

    if (empty($menu) || !is_array($menu)) {
        return;
    }

    $features = gs_features_get_cached();
    if ($features === null) {
        // Not paired or fetch failed — leave menu untouched.
        return;
    }

    $allowed_areas = isset($features['allowed_areas']) ? (array) $features['allowed_areas'] : array();
    if (empty($allowed_areas)) {
        // Empty = allow all (matches gend.me-side UX).
        return;
    }

    if (!apply_filters('gs_features_apply_to_user', current_user_can('read'), $features)) {
        return;
    }

    $area_map        = gs_features_area_map();
    $always_allowed  = gs_features_always_allowed();
    $allowed_prefixes = array();
    foreach ($allowed_areas as $area) {
        if (isset($area_map[$area])) {
            foreach ($area_map[$area] as $prefix) {
                $allowed_prefixes[] = $prefix;
            }
        }
    }

    foreach ($menu as $key => $entry) {
        if (!is_array($entry) || empty($entry[2])) {
            continue;
        }
        $slug = (string) $entry[2];
        if (in_array($slug, $always_allowed, true)) {
            continue;
        }
        if (!gs_features_slug_allowed($slug, $allowed_prefixes)) {
            unset($menu[$key]);
            if (isset($submenu[$slug])) {
                unset($submenu[$slug]);
            }
        }
    }
}

/**
 * Check if a menu slug starts with any of the allowed prefixes.
 */
function gs_features_slug_allowed($slug, array $allowed_prefixes) {
    foreach ($allowed_prefixes as $prefix) {
        if ($prefix !== '' && strncmp($slug, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Return cached feature gates, refetching if expired. Returns null when
 * the install isn't paired or the fetch errored.
 */
function gs_features_get_cached() {

    $expires = (int) get_option(GS_FEATURES_CACHE_EXPIRES_OPTION, 0);
    if ($expires > time()) {
        $cached = get_option(GS_FEATURES_CACHE_OPTION, null);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $fresh = gs_features_fetch_remote();
    if (is_array($fresh)) {
        $ttl = isset($fresh['cache_seconds']) ? max(60, (int) $fresh['cache_seconds']) : GS_FEATURES_DEFAULT_TTL;
        update_option(GS_FEATURES_CACHE_OPTION, $fresh, false);
        update_option(GS_FEATURES_CACHE_EXPIRES_OPTION, time() + $ttl, false);
        return $fresh;
    }

    // Fetch failed — return whatever we have stale rather than blanking the menu.
    $cached = get_option(GS_FEATURES_CACHE_OPTION, null);
    return is_array($cached) ? $cached : null;
}

/**
 * One-shot fetch from gend.me /install/{install_id}/features.
 */
function gs_features_fetch_remote() {

    $install_id    = (string) get_option('gs_install_id', '');
    $install_token = (string) get_option('gs_install_token', '');
    $gend_base     = (string) get_option('gs_gend_base_url', '');

    if ($install_id === '' || $install_token === '' || $gend_base === '') {
        return null;
    }

    $endpoint = trailingslashit($gend_base) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode($install_id) . '/features';

    $response = wp_remote_get($endpoint, array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $install_token,
            'Accept'        => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        return null;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($decoded) ? $decoded : null;
}
