<?php
/**
 * Feature-state reporter — container → hub push.
 *
 * Once the container is paired with gend.me (gs_install_id + gs_install_token
 * are set), every plugin (de)activation and a once-daily cron job POST the
 * container's active-plugin list to:
 *
 *   /wp-json/gdc-app-manager/v1/install/{install_id}/features-state
 *
 * The hub stores the list as site meta on the Site row + mirrors it into a
 * site transient that the BP-group Feature Suite tab on gend.me reads via
 * gdc_get_container_active_plugins(). That tab can then show an "Active on
 * linked web app" sub-status per card without needing hub→container
 * reachability.
 *
 * No-op on the hub (gend.me) itself, which has no install pairing options.
 *
 * @package GenD_Society
 */

defined('ABSPATH') || exit;

/**
 * Collect this container's active plugin list. Includes both site-level
 * and (where present) network-active plugins.
 *
 * @return array<int, string>
 */
function gend_society_feature_state_collect_active_plugins() {
    $list = (array) get_option('active_plugins', array());

    if (is_multisite() && function_exists('wp_get_active_network_plugins')) {
        $network = (array) wp_get_active_network_plugins();
        if (defined('WPMU_PLUGIN_DIR')) {
            $base = trailingslashit(WP_PLUGIN_DIR);
            foreach ($network as $abs) {
                $rel = str_replace($base, '', $abs);
                if ($rel !== $abs) $list[] = $rel;
            }
        }
    }

    $list = array_filter(array_map('strval', $list), 'strlen');
    $list = array_values(array_unique($list));
    sort($list);
    return $list;
}

/**
 * The address this report is sent for (111-12, MIG-11): the hub refuses a report for a site that has moved unless it
 * comes from the new site. On the ORIGIN it is home_url(). In a CONTAINER (a Host-keyed home_url() answers whichever
 * host the request came in on) it is the stable canonical host written by the importer (option
 * gend_society_canonical_host), falling back to the host of the raw stored home option.
 *
 * @return string An http(s) URL, or '' when none can be determined.
 */
function gend_society_feature_state_site_url() {
    $container = function_exists('gend_society_runtime_mode') && 'container' === gend_society_runtime_mode();
    if (!apply_filters('gend_society_feature_state_is_container', $container)) { // the filter lets tests force it
        return home_url();
    }
    $host = strtolower(trim((string) get_option('gend_society_canonical_host', '')));
    if ('' !== $host && preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host)) {
        return 'https://' . $host;
    }
    // The raw stored value: the Host-keyed pre_option filter (111-06) is bypassed on purpose.
    if (defined('WP_HOME') && is_string(WP_HOME) && '' !== WP_HOME) {
        $raw = WP_HOME;
    } else {
        global $wpdb;
        $raw = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'home')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
    $parts = wp_parse_url($raw);
    return is_array($parts) && !empty($parts['host']) ? 'https://' . strtolower((string) $parts['host']) : '';
}

/**
 * Push the current active-plugin list to the hub. Best-effort: failures
 * are logged via error_log but never surfaced — the reporter is a
 * background optimisation, not a critical path.
 *
 * Returns true on a 2xx response, false otherwise.
 */
function gend_society_feature_state_report_now() {
    if (!function_exists('gend_society_remote_membership_call')) {
        return false;
    }
    // Skip on installs that aren't paired yet — gs_remote_membership_call
    // already short-circuits in that case, but checking here lets us
    // avoid logging a "not_paired" warning every plugin (de)activate.
    if ((string) get_option('gend_society_install_token', '') === '') {
        return false;
    }
    // 111-12 (MIG-11): after Live the origin must not overwrite the container's list (the hub also refuses it).
    if (function_exists('gend_society_origin_moved') && gend_society_origin_moved()['moved']) {
        return false;
    }
    // @build-only-begin wporg
    // wordpress.org build: the plugin list is sent only after the owner opts in.
    if (function_exists('gend_society_data_sharing_allowed') && !gend_society_data_sharing_allowed('plugin_list')) {
        return false;
    }
    // @build-only-end wporg

    $body = array(
        'plugins'     => gend_society_feature_state_collect_active_plugins(),
        'reported_at' => time(),
    );
    $site_url = gend_society_feature_state_site_url();
    if ('' !== $site_url) {
        $body['site_url'] = $site_url;
    }
    $resp = gend_society_remote_membership_call('features-state', $body, 'POST');
    if (is_wp_error($resp)) {
        if (function_exists('error_log')) {
            error_log('[gend-society] features-state report failed: ' . $resp->get_error_message());
        }
        return false;
    }
    return true;
}

/**
 * Plugin (de)activation hooks. Both fire AFTER WordPress updates the
 * active_plugins option, so the collector sees the new state.
 *
 * We use a tick-batched dispatch — multiple (de)activations in a single
 * request (e.g. an "Activate selected" bulk action) only fire one POST
 * at shutdown rather than N back-to-back ones.
 */
function gend_society_feature_state_schedule_dispatch() {
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;
    add_action('shutdown', 'gend_society_feature_state_report_now', 99);
}
add_action('activated_plugin',   'gend_society_feature_state_schedule_dispatch', 99);
add_action('deactivated_plugin', 'gend_society_feature_state_schedule_dispatch', 99);

/**
 * Daily heartbeat — covers manual edits to active_plugins that bypass
 * the activated/deactivated hooks (rare, but possible via direct DB
 * tweaks or WP-CLI shim plugins).
 */
add_action('gend_society_feature_state_daily', 'gend_society_feature_state_report_now');
add_action('init', function () {
    // @build-only-begin wporg
    if (function_exists('gend_society_data_sharing_allowed') && !gend_society_data_sharing_allowed('plugin_list')) { // wordpress.org build: no daily report without the opt-in.
        if (wp_next_scheduled('gend_society_feature_state_daily')) wp_clear_scheduled_hook('gend_society_feature_state_daily');
        return;
    }
    // @build-only-end wporg
    if (!wp_next_scheduled('gend_society_feature_state_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gend_society_feature_state_daily');
    }
});

/**
 * Pairing-completion hook: as soon as a container finishes auto-pair
 * via gdc_self_hosted_rest_connect_by_install, do one immediate report
 * so the hub has the plugin list available the first time someone
 * opens the Feature Suite tab.
 *
 * The action name matches the one fired in oauth-login.php after a
 * successful pairing. Falling back to a one-shot scheduled event keeps
 * this safe if the hook isn't present yet.
 */
add_action('gs_install_paired', 'gend_society_feature_state_report_now');
