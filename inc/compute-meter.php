<?php
/**
 * Compute meter — how much CPU this site uses, hour by hour, reported to gend.me.
 *
 * Owner's model (2026-11-06/08): a web app's compute on the shared pool is billed as gas (T3, per vCPU-second) on the
 * monthly invoice and served by the main node; compute on the member's own machine — a Server plan's dedicated
 * machine, or the member's own server running a connected install — is never billed and shows as savings. The site
 * keeps two hourly counters, own and shared, and an hourly job posts each closed hour to
 * /install/{install_id}/compute/usage (install-token authed), clearing it once the hub has recorded it. The hub
 * decides for itself what counts as the member's own machine from the machines the web app really holds; the
 * figures here are the measurement.
 *
 * Measurement: in a gend.me container the kernel's own CPU accounting for the container (cgroup v2 cpu.stat
 * usage_usec, or cgroup v1 cpuacct.usage) — the web server and PHP together — and the memory it holds (memory.current),
 * sampled by each copy of the site on the requests it serves (at most every 5 minutes) and on the hourly run, and
 * spread over the hours each slice covers. A pooled web app runs several copies: each keeps its own baseline, the hour
 * counters are shared. On a connected install (the member's own server, possibly shared with
 * other sites) each request's PHP CPU time instead (getrusage delta between plugin load and shutdown). Not measured:
 * the database, static files and cached pages that never reach PHP.
 *
 * No-op on the gend.me hub: the main node's own compute is nobody's bill.
 *
 * @package GenD_Society
 */

defined('ABSPATH') || exit;

if (!defined('GEND_SOCIETY_COMPUTE_OPTION_PREFIX')) {
    define('GEND_SOCIETY_COMPUTE_OPTION_PREFIX', 'gend_society_compute_');
}

/**
 * Whether this site meters at all: a paired container or connected install, never the hub. The wordpress.org build
 * also needs the owner's compute_meter data-sharing opt-in (see gend_society_data_sharing_items()).
 */
function gend_society_compute_meter_active(): bool {
    $mode = function_exists('gend_society_runtime_mode') ? gend_society_runtime_mode() : 'standalone';
    if ('hub' === $mode) {
        return false;
    }
    if ('' === (string) get_option('gend_society_install_token', '')) {
        return false;
    }
    // @build-only-begin wporg
    if (function_exists('gend_society_data_sharing_allowed') && !gend_society_data_sharing_allowed('compute_meter')) {
        return false;
    }
    // @build-only-end wporg
    return (bool) apply_filters('gend_society_compute_meter_active', true, $mode);
}

/**
 * The Kubernetes node this pod runs on, when the pod is told (GEND_NODE_NAME from the downward API, or the constant
 * GEND_SOCIETY_NODE_NAME). '' when unknown.
 */
function gend_society_compute_node_name(): string {
    if (defined('GEND_SOCIETY_NODE_NAME') && is_string(GEND_SOCIETY_NODE_NAME)) {
        return trim(GEND_SOCIETY_NODE_NAME);
    }
    $env = getenv('GEND_NODE_NAME');
    return is_string($env) ? trim($env) : '';
}

/**
 * Where this site's compute runs right now.
 *
 * @return array{own:bool,known:bool} own: the member's own machine; known: whether the site could tell at all.
 */
function gend_society_compute_placement(): array {
    $mode = function_exists('gend_society_runtime_mode') ? gend_society_runtime_mode() : 'standalone';
    if ('container' !== $mode) {
        return array('own' => true, 'known' => true); // a connected install is the member's own server
    }
    $node = gend_society_compute_node_name();
    if ('' === $node) {
        return array('own' => false, 'known' => false);
    }
    // Dedicated machines live in the srv-<install> node pools; node names carry the pool name.
    return array('own' => false !== strpos($node, '-srv-'), 'known' => true);
}

/**
 * The container's cumulative CPU time in microseconds from the kernel's cgroup accounting, or null when this is not a
 * container or the files are not readable. Monotonic until the container restarts.
 */
function gend_society_compute_cgroup_usec() {
    $mode = function_exists('gend_society_runtime_mode') ? gend_society_runtime_mode() : 'standalone';
    if ('container' !== $mode) {
        return null;
    }
    $v2 = '/sys/fs/cgroup/cpu.stat';
    if (is_readable($v2)) {
        $stat = (string) file_get_contents($v2);
        if (preg_match('/^usage_usec\s+(\d+)/m', $stat, $m)) {
            return (float) $m[1];
        }
    }
    foreach (array('/sys/fs/cgroup/cpu,cpuacct/cpuacct.usage', '/sys/fs/cgroup/cpu/cpuacct.usage', '/sys/fs/cgroup/cpuacct/cpuacct.usage') as $v1) {
        if (is_readable($v1)) {
            $ns = trim((string) file_get_contents($v1));
            if (ctype_digit($ns)) {
                return ((float) $ns) / 1000.0;
            }
        }
    }
    return null;
}

/**
 * The container's memory in use, in bytes (cgroup v2 memory.current, or v1 memory.usage_in_bytes), or null.
 */
function gend_society_compute_cgroup_mem_bytes() {
    $mode = function_exists('gend_society_runtime_mode') ? gend_society_runtime_mode() : 'standalone';
    if ('container' !== $mode) {
        return null;
    }
    foreach (array('/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory/memory.usage_in_bytes') as $f) {
        if (is_readable($f)) {
            $v = trim((string) file_get_contents($f));
            if (ctype_digit($v)) {
                return (float) $v;
            }
        }
    }
    return null;
}

/** This copy of the site (a pooled web app runs several): its own baseline option key. */
function gend_society_compute_copy_key(): string {
    $host = function_exists('gethostname') ? (string) gethostname() : '';
    return GEND_SOCIETY_COMPUTE_OPTION_PREFIX . 'last_' . substr(md5($host), 0, 12);
}

/**
 * Spread an amount over the hours between two times, in proportion to the seconds in each hour.
 *
 * @param int   $from   Unix time the slice started.
 * @param int   $to     Unix time it ended.
 * @param float $amount What to spread.
 * @param string $kind  own | shared | mem_own | mem_shared.
 */
function gend_society_compute_spread(int $from, int $to, float $amount, string $kind): void {
    if ($amount <= 0 || $to <= $from) {
        return;
    }
    $span = $to - $from;
    for ($t = $from; $t < $to;) {
        $next = min($to, (int) (floor($t / 3600) + 1) * 3600);
        gend_society_compute_add(gmdate('YmdH', $t), $amount * ($next - $t) / $span, $kind);
        $t = $next;
    }
}

/**
 * Add CPU seconds to an hour's counter, atomically (one UPSERT, no read-modify-write), bypassing the options cache on
 * purpose: these rows are only ever read back by gend_society_compute_buckets().
 *
 * @param string $hour    YYYYMMDDHH, UTC.
 * @param float  $seconds CPU seconds to add.
 * @param bool   $own     Own machine (true) or the shared pool (false).
 */
function gend_society_compute_add(string $hour, float $seconds, $own): void {
    global $wpdb;
    if ($seconds <= 0 || !preg_match('/^\d{10}$/', $hour)) {
        return;
    }
    // $own: true/false for CPU seconds (the original signature), or a counter kind ('mem_own', 'mem_shared'...).
    $kind = is_string($own) ? $own : ($own ? 'own' : 'shared');
    if (!in_array($kind, array('own', 'shared', 'mem_own', 'mem_shared'), true)) {
        return;
    }
    $name = GEND_SOCIETY_COMPUTE_OPTION_PREFIX . $hour . '_' . $kind;
    $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
         ON DUPLICATE KEY UPDATE option_value = option_value + VALUES(option_value)",
        $name,
        (string) round($seconds, 6)
    ));
}

/**
 * Every hour counter this site holds, newest last.
 *
 * @return array<string,array{own:float,shared:float,mem_own:float,mem_shared:float}> keyed by hour (YYYYMMDDHH);
 *         own/shared in CPU seconds, mem_* in GiB-seconds.
 */
function gend_society_compute_buckets(): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
        $wpdb->esc_like(GEND_SOCIETY_COMPUTE_OPTION_PREFIX) . '%'
    ), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $row) {
        if (!preg_match('/^' . preg_quote(GEND_SOCIETY_COMPUTE_OPTION_PREFIX, '/') . '(\d{10})_(own|shared|mem_own|mem_shared)$/', (string) $row['option_name'], $m)) {
            continue;
        }
        if (!isset($out[$m[1]])) {
            $out[$m[1]] = array('own' => 0.0, 'shared' => 0.0, 'mem_own' => 0.0, 'mem_shared' => 0.0);
        }
        $out[$m[1]][$m[2]] = (float) $row['option_value'];
    }
    return $out;
}

/**
 * Container sampling, per copy: the CPU this copy's container used since its last sample, and the memory it held
 * meanwhile (the average of the two readings x the time), spread over the hours of the slice under the placement the
 * pod has now. The first sample only records the baseline. A restart is a new container, hence a new host name and a
 * new baseline: the time before its first sample is lost, never doubled.
 */
function gend_society_compute_sample(): void {
    $usec = gend_society_compute_cgroup_usec();
    if (null === $usec) {
        return;
    }
    $mem  = gend_society_compute_cgroup_mem_bytes();
    $now  = time();
    $key  = gend_society_compute_copy_key();
    $last = get_option($key, array());
    update_option($key, array('usec' => $usec, 'mem' => $mem, 'time' => $now), false);
    if (!is_array($last) || !isset($last['usec'], $last['time'])) {
        return;
    }
    $since = (int) $last['time'];
    if ($since <= 0 || $since >= $now) {
        return;
    }
    $placement = gend_society_compute_placement();
    $delta     = $usec >= (float) $last['usec'] ? $usec - (float) $last['usec'] : $usec;
    gend_society_compute_spread($since, $now, $delta / 1000000.0, $placement['own'] ? 'own' : 'shared');
    if (null !== $mem && isset($last['mem']) && is_numeric($last['mem'])) {
        $gib = (((float) $last['mem'] + $mem) / 2) / 1073741824;
        gend_society_compute_spread($since, $now, $gib * ($now - $since), $placement['own'] ? 'mem_own' : 'mem_shared');
    }
}

/** On a request: sample this copy at most every 5 minutes (its own baseline records when it last sampled). */
function gend_society_compute_sample_on_request(): void {
    if (null === gend_society_compute_cgroup_usec()) {
        return;
    }
    $last = get_option(gend_society_compute_copy_key(), array());
    if (is_array($last) && isset($last['time']) && time() - (int) $last['time'] < 5 * MINUTE_IN_SECONDS) {
        return;
    }
    gend_society_compute_sample();
}

/**
 * Per-request fallback (connected installs, or a container whose cgroup files are not readable): this request's PHP
 * CPU time, user + system, from plugin load to shutdown.
 */
function gend_society_compute_request_start(): void {
    if (!function_exists('getrusage') || null !== gend_society_compute_cgroup_usec()) {
        return;
    }
    $GLOBALS['gend_society_compute_rusage_start'] = getrusage();
    add_action('shutdown', 'gend_society_compute_request_end', PHP_INT_MAX);
}

function gend_society_compute_rusage_seconds(array $ru): float {
    return (float) ($ru['ru_utime.tv_sec'] ?? 0) + (float) ($ru['ru_utime.tv_usec'] ?? 0) / 1000000.0
         + (float) ($ru['ru_stime.tv_sec'] ?? 0) + (float) ($ru['ru_stime.tv_usec'] ?? 0) / 1000000.0;
}

function gend_society_compute_request_end(): void {
    $start = $GLOBALS['gend_society_compute_rusage_start'] ?? null;
    if (!is_array($start)) {
        return;
    }
    $seconds = gend_society_compute_rusage_seconds(getrusage()) - gend_society_compute_rusage_seconds($start);
    if ($seconds < 0.0005) {
        return;
    }
    $placement = gend_society_compute_placement();
    gend_society_compute_add(gmdate('YmdH'), $seconds, $placement['own']);
}

/**
 * Hourly: take a sample, then post every closed hour to the hub and clear it once recorded. A failed post leaves the
 * hour in place for the next run; hours older than seven days are dropped (the hub refuses them).
 */
function gend_society_compute_report(): void {
    if (!gend_society_compute_meter_active() || !function_exists('gend_society_remote_membership_call')) {
        return;
    }
    gend_society_compute_sample();
    $current   = gmdate('YmdH');
    $oldest    = gmdate('YmdH', time() - 7 * DAY_IN_SECONDS);
    $placement = gend_society_compute_placement();
    $sent      = 0;
    foreach (gend_society_compute_buckets() as $hour => $b) {
        if ($hour >= $current) {
            continue; // still open
        }
        if ($hour < $oldest) {
            gend_society_compute_forget($hour);
            continue;
        }
        if ($b['own'] < 0.001 && $b['shared'] < 0.001 && $b['mem_own'] < 0.001 && $b['mem_shared'] < 0.001) {
            gend_society_compute_forget($hour);
            continue;
        }
        if (++$sent > 48) {
            break;
        }
        $resp = gend_society_remote_membership_call('compute/usage', array(
            'hour'                => $hour,
            'runtime'             => function_exists('gend_society_runtime_mode') ? gend_society_runtime_mode() : 'standalone',
            'shared_vcpu_seconds' => round($b['shared'], 3),
            'own_vcpu_seconds'    => round($b['own'], 3),
            'shared_gib_seconds'  => round($b['mem_shared'], 3),
            'own_gib_seconds'     => round($b['mem_own'], 3),
            'node_known'          => $placement['known'],
        ), 'POST');
        if (is_wp_error($resp)) {
            error_log('[gend-society compute] ' . $hour . ': ' . $resp->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            break; // the hub is not answering; keep every remaining hour for the next run
        }
        if (is_array($resp) && isset($resp['hour'])) {
            gend_society_compute_forget($hour);
        }
    }
}

function gend_society_compute_forget(string $hour): void {
    foreach (array('own', 'shared', 'mem_own', 'mem_shared') as $kind) {
        delete_option(GEND_SOCIETY_COMPUTE_OPTION_PREFIX . $hour . '_' . $kind);
    }
}

add_action('gend_society_compute_hourly', 'gend_society_compute_report');
add_action('init', function () {
    if (!gend_society_compute_meter_active()) {
        if (wp_next_scheduled('gend_society_compute_hourly')) {
            wp_clear_scheduled_hook('gend_society_compute_hourly');
        }
        return;
    }
    if (!wp_next_scheduled('gend_society_compute_hourly')) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'gend_society_compute_hourly');
    }
    gend_society_compute_request_start();
    gend_society_compute_sample_on_request();
}, 20);
