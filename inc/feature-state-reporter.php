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
function gs_feature_state_collect_active_plugins() {
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
 * Push the current active-plugin list to the hub. Best-effort: failures
 * are logged via error_log but never surfaced — the reporter is a
 * background optimisation, not a critical path.
 *
 * Returns true on a 2xx response, false otherwise.
 */
function gs_feature_state_report_now() {
    if (!function_exists('gs_remote_membership_call')) {
        return false;
    }
    // Skip on installs that aren't paired yet — gs_remote_membership_call
    // already short-circuits in that case, but checking here lets us
    // avoid logging a "not_paired" warning every plugin (de)activate.
    if ((string) get_option('gs_install_token', '') === '') {
        return false;
    }

    $body = array(
        'plugins'     => gs_feature_state_collect_active_plugins(),
        'reported_at' => time(),
    );
    $resp = gs_remote_membership_call('features-state', $body, 'POST');
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
function gs_feature_state_schedule_dispatch() {
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;
    add_action('shutdown', 'gs_feature_state_report_now', 99);
}
add_action('activated_plugin',   'gs_feature_state_schedule_dispatch', 99);
add_action('deactivated_plugin', 'gs_feature_state_schedule_dispatch', 99);

/**
 * Daily heartbeat — covers manual edits to active_plugins that bypass
 * the activated/deactivated hooks (rare, but possible via direct DB
 * tweaks or WP-CLI shim plugins).
 */
add_action('gs_feature_state_daily', 'gs_feature_state_report_now');
add_action('init', function () {
    if (!wp_next_scheduled('gs_feature_state_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gs_feature_state_daily');
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
add_action('gs_install_paired', 'gs_feature_state_report_now');
