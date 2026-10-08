<?php
/**
 * gend.me network referral program.
 *
 * gend.me's commission program (the sales-team plugin's default program on
 * the hub's main site) is network-wide: a gend.me affiliate link works on
 * gend.me, every sub-site and every separately hosted (container) site, and
 * whoever creates a gend.me account anywhere on the network is credited to
 * the right person. Each site's OWN sales-team program stays local (it uses
 * per-site keys — see aas_umk()/aas_ck() in sales-team/includes/logic.php).
 *
 *  - Affiliate link: ?gm_ref=<user id> on any site. On network sites that
 *    don't run their own sales-team program, gend.me's own param (?ref=)
 *    counts too. Remembered in the `gm_ref` cookie.
 *  - Source site: visiting a sub-site, or arriving on the hub from a
 *    container via a tagged link (?gm_from=<host>) or its Referer, is
 *    remembered in the `gm_from` cookie. Containers tag every link and
 *    redirect to the hub with gm_from (+ gm_ref when they hold one).
 *  - Attribution (hub network, user_register): keep a referrer gend.me's
 *    program already set; else the gend.me affiliate from gm_ref; else the
 *    owner of the connected site the visitor came from. Stored in gend.me's
 *    plain `referrer_id` / `referrer_program_id` meta, so every gend.me
 *    payout/report already counts it. gdc_referral_source records why.
 */

if (!defined('ABSPATH')) exit;

const GDC_NR_REF_PARAM  = 'gm_ref';
const GDC_NR_FROM_PARAM = 'gm_from';

/**
 * True on the gend.me hub network (any of its sites), false on containers/standalone installs.
 * Hub-ness comes from the runtime mode (104 audit); multisite + WP Ultimo stay as
 * preconditions because callers use wu_get_site()/network_home_url().
 */
function gdc_nr_on_hub_network()
{
    return gend_society_is_hub() && is_multisite() && function_exists('wu_get_site');
}

function gdc_nr_hub_url()
{
    if (gdc_nr_on_hub_network()) return untrailingslashit(network_home_url());
    $hub = (string) get_site_option('aipa_central_hub_url', '');
    return $hub !== '' ? untrailingslashit($hub) : 'https://gend.me';
}

/** gend.me's referral program (default program of the hub main site's sales-team), or null. */
function gdc_nr_program()
{
    static $program = false;
    if ($program !== false) return $program;
    $program = null;
    if (!gdc_nr_on_hub_network()) return $program;
    $switched = get_current_blog_id() !== get_main_site_id();
    if ($switched) switch_to_blog(get_main_site_id());
    $opt = get_option('aas_referral_programs');
    if (function_exists('aas_get_default_referral_program') && !$switched) {
        $program = aas_get_default_referral_program();
    } elseif (is_array($opt) && $opt) {
        // Sales-team's loaded functions read the CURRENT blog; read the
        // main site's stored programs directly when switched.
        $first = reset($opt);
        foreach ($opt as $p) {
            if (!empty($p['is_default'])) { $first = $p; break; }
        }
        $program = is_array($first) ? $first : null;
    }
    if ($switched) restore_current_blog();
    if (is_array($program)) {
        $program = array(
            'id'                 => (string) ($program['id'] ?? 'prog_default'),
            'url_param'          => (string) ($program['url_param'] ?? 'ref'),
            'tracking_condition' => (string) ($program['tracking_condition'] ?? 'registration'),
            'cookie_duration'    => (int) ($program['cookie_duration'] ?? 90),
        );
    }
    return $program;
}

function gdc_nr_cookie_days()
{
    $p = gdc_nr_program();
    return $p && $p['cookie_duration'] > 0 ? $p['cookie_duration'] : 90;
}

function gdc_nr_set_cookie($name, $value)
{
    $_COOKIE[$name] = (string) $value;
    if (headers_sent()) return;
    // Path "/" so path-based sub-sites share it with the hub.
    setcookie($name, (string) $value, time() + DAY_IN_SECONDS * gdc_nr_cookie_days(), '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
}

/** Does THIS site run its own sales-team program? */
function gdc_nr_site_has_own_program()
{
    return function_exists('aas_get_referral_programs') && is_multisite() && !is_main_site();
}

/** Normalise a gm_from value: "blog:<id>" or a host[/path]. */
function gdc_nr_clean_from($v)
{
    $v = strtolower(trim((string) $v));
    if (preg_match('/^blog:\d+$/', $v)) return $v;
    $v = preg_replace('#^https?://#', '', $v);
    $v = preg_replace('#[^a-z0-9.\-/:_]#', '', $v);
    return substr(rtrim($v, '/'), 0, 190);
}

/** Resolve a gm_from value / URL to a connected site's blog id on the hub network (0 = none). */
function gdc_nr_resolve_site($from)
{
    if (!gdc_nr_on_hub_network() || $from === '') return 0;
    if (preg_match('/^blog:(\d+)$/', $from, $m)) {
        $bid = (int) $m[1];
    } else {
        $parts = explode('/', $from, 2);
        $host  = preg_replace('/:\d+$/', '', $parts[0]);
        $path  = '/' . (isset($parts[1]) ? trim($parts[1], '/') . '/' : '');
        $bid   = (int) get_blog_id_from_url($host, $path);
        if (!$bid && $path !== '/') $bid = (int) get_blog_id_from_url($host, '/');
        if (!$bid) {
            $ids = get_sites(array('number' => 1, 'fields' => 'ids', 'meta_key' => 'gdc_container_hostname', 'meta_value' => $host));
            $bid = $ids ? (int) $ids[0] : 0;
        }
        if (!$bid && function_exists('wu_get_domain_by_domain')) {
            $d = wu_get_domain_by_domain($host);
            if ($d && method_exists($d, 'get_blog_id')) $bid = (int) $d->get_blog_id();
        }
    }
    return ($bid && $bid !== (int) get_main_site_id() && get_site($bid)) ? $bid : 0;
}

/** The user who owns a site (its WP Ultimo membership's customer), or 0. */
function gdc_nr_site_owner($blog_id)
{
    $uid = 0;
    if ($blog_id && function_exists('wu_get_site')) {
        try {
            $site = wu_get_site($blog_id);
            $membership = $site && method_exists($site, 'get_membership') ? $site->get_membership() : null;
            $customer = $membership && method_exists($membership, 'get_customer') ? $membership->get_customer() : null;
            // No membership: the site's own customer.
            if (!$customer && $site && method_exists($site, 'get_customer')) $customer = $site->get_customer();
            $uid = $customer && method_exists($customer, 'get_user_id') ? (int) $customer->get_user_id() : 0;
        } catch (\Throwable $e) {
            $uid = 0;
        }
    }
    // No WP Ultimo owner: the site's own administrator (network super
    // admins don't count — crediting gend.me's operators is no referral).
    if (!$uid && $blog_id) {
        foreach (get_users(array('blog_id' => (int) $blog_id, 'role' => 'administrator', 'number' => 20, 'orderby' => 'ID', 'fields' => array('ID'))) as $a) {
            if (!is_super_admin((int) $a->ID)) { $uid = (int) $a->ID; break; } // site-admin check, not a hub signal (104 audit)
        }
    }
    /** Filters who a sign-up from this connected site is credited to when no affiliate is tracked. */
    $uid = (int) apply_filters('gdc_nr_site_owner', $uid, (int) $blog_id);
    return ($uid && get_userdata($uid)) ? $uid : 0;
}

/** Valid gend.me affiliate from the request: cookie, then the page's (Referer) query, then the URL. */
function gdc_nr_request_ref()
{
    $candidates = array();
    if (!empty($_COOKIE['gm_ref'])) $candidates[] = $_COOKIE['gm_ref'];
    // gend.me's own sales-team cookie: plain name = the hub program's.
    if (gdc_nr_on_hub_network() && !empty($_COOKIE['aas_referrer_id'])) $candidates[] = $_COOKIE['aas_referrer_id'];
    $page = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
    if ($page !== '' && wp_parse_url($page, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
        parse_str((string) wp_parse_url($page, PHP_URL_QUERY), $q);
        if (!empty($q[GDC_NR_REF_PARAM])) $candidates[] = $q[GDC_NR_REF_PARAM];
    }
    foreach ($candidates as $c) {
        $id = (int) $c;
        if ($id > 0 && get_userdata($id)) return $id;
    }
    return 0;
}

/* ── 1. Capture (every site: hub network and containers) ─────────────── */
add_action('init', 'gdc_nr_capture', 1);
function gdc_nr_capture()
{
    if (is_admin() && !wp_doing_ajax()) return;
    $ref = 0;
    if (isset($_GET[GDC_NR_REF_PARAM])) {
        $ref = (int) $_GET[GDC_NR_REF_PARAM];
    } elseif (gdc_nr_on_hub_network() && !is_main_site() && !gdc_nr_site_has_own_program()) {
        $p = gdc_nr_program();
        if ($p && $p['url_param'] !== '' && isset($_GET[$p['url_param']])) $ref = (int) $_GET[$p['url_param']];
    }
    if ($ref > 0 && $ref !== get_current_user_id()) gdc_nr_set_cookie('gm_ref', $ref);

    if (!gdc_nr_on_hub_network() || is_user_logged_in()) return;
    if (isset($_GET[GDC_NR_FROM_PARAM])) {
        $from = gdc_nr_clean_from(wp_unslash($_GET[GDC_NR_FROM_PARAM]));
        if ($from !== '') gdc_nr_set_cookie('gm_from', $from);
    } elseif (!is_main_site()) {
        // Browsing a connected sub-site (last touch wins).
        if (($_COOKIE['gm_from'] ?? '') !== 'blog:' . get_current_blog_id()) gdc_nr_set_cookie('gm_from', 'blog:' . get_current_blog_id());
    } elseif (empty($_COOKIE['gm_from']) && !empty($_SERVER['HTTP_REFERER'])) {
        // Untagged arrival on the hub: was the previous page a connected site?
        $r = (string) wp_unslash($_SERVER['HTTP_REFERER']);
        $host = (string) wp_parse_url($r, PHP_URL_HOST);
        if ($host !== '' && $host !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            $from = gdc_nr_clean_from($host . (string) wp_parse_url($r, PHP_URL_PATH));
            if (gdc_nr_resolve_site($from)) gdc_nr_set_cookie('gm_from', $from);
        }
    }
}

/* ── 2. Containers / standalone sites: tag the way back to the hub ────── */
function gdc_nr_tag_args()
{
    $args = array(GDC_NR_FROM_PARAM => gdc_nr_clean_from(wp_parse_url(home_url(), PHP_URL_HOST) . (string) wp_parse_url(home_url(), PHP_URL_PATH)));
    $ref = !empty($_COOKIE['gm_ref']) ? (int) $_COOKIE['gm_ref'] : 0;
    if ($ref > 0) $args[GDC_NR_REF_PARAM] = $ref;
    return $args;
}

if (!is_multisite()) {
    // Server-side redirects to the hub (e.g. "Log in with gend.me").
    add_filter('wp_redirect', function ($location) {
        $hub_host = wp_parse_url(gdc_nr_hub_url(), PHP_URL_HOST);
        if ($hub_host && is_string($location) && wp_parse_url($location, PHP_URL_HOST) === $hub_host) {
            $location = add_query_arg(gdc_nr_tag_args(), $location);
        }
        return $location;
    }, 20);

    // Links to the hub in the page.
    add_action('wp_footer', 'gdc_nr_print_link_tagger', 99);
    add_action('login_footer', 'gdc_nr_print_link_tagger', 99);
}
function gdc_nr_print_link_tagger()
{
    $hub_host = wp_parse_url(gdc_nr_hub_url(), PHP_URL_HOST);
    if (!$hub_host || $hub_host === wp_parse_url(home_url(), PHP_URL_HOST)) return;
    ?>
    <script>
    (function () {
        var hubHost = <?php echo wp_json_encode($hub_host); ?>, args = <?php echo wp_json_encode(gdc_nr_tag_args()); ?>;
        function tag(a) {
            try {
                var u = new URL(a.href, location.href);
                if (u.hostname !== hubHost) { return; }
                Object.keys(args).forEach(function (k) { if (!u.searchParams.has(k)) { u.searchParams.set(k, args[k]); } });
                a.href = u.toString();
            } catch (e) {}
        }
        ['mousedown', 'touchstart', 'keydown', 'focusin'].forEach(function (ev) {
            document.addEventListener(ev, function (e) { var a = e.target && e.target.closest ? e.target.closest('a[href]') : null; if (a) { tag(a); } }, true);
        });
    })();
    </script>
    <?php
}

/* ── 3. Attribution: who referred this visitor (hub network) ─────────── */

/** The site this request's visitor came from (hub network), or 0. */
function gdc_nr_request_from_site()
{
    return !is_main_site() ? get_current_blog_id() : gdc_nr_resolve_site(gdc_nr_clean_from($_COOKIE['gm_from'] ?? ''));
}

/**
 * Give a gend.me account its lifetime referrer if it has none yet: the
 * gend.me affiliate link, else the owner of the connected site the visitor
 * came from. A referrer, once set, keeps every later sale ("sign-up
 * referrer always"). $context: 'signup' | 'checkout'.
 */
function gdc_nr_assign_user($user_id, $context = 'signup')
{
    if (!gdc_nr_on_hub_network()) return 0;
    $user_id = (int) $user_id;
    if (!$user_id) return 0;
    $existing = (int) get_user_meta($user_id, 'referrer_id', true);
    if ($existing) return $existing;
    $program = gdc_nr_program();
    if (!$program) return 0;
    if ($context === 'signup' && !in_array($program['tracking_condition'], array('registration', 'both'), true)) return 0;

    $ref       = gdc_nr_request_ref();
    $source    = 'link';
    $from_site = gdc_nr_request_from_site();
    if (!$ref || $ref === $user_id) {
        $ref    = $from_site ? gdc_nr_site_owner($from_site) : 0;
        $source = 'site_owner';
    }
    if (!$ref || $ref === $user_id) return 0;

    update_user_meta($user_id, 'referrer_id', $ref);
    update_user_meta($user_id, 'referrer_program_id', $program['id']);
    update_user_meta($user_id, 'gdc_referral_source', $context === 'checkout' ? 'checkout_' . $source : $source);
    if ($from_site) update_user_meta($user_id, 'gdc_referred_from_site', $from_site);
    return $ref;
}

// A gend.me account was created anywhere on the network.
add_action('user_register', 'gdc_nr_attribute_signup', 20);
function gdc_nr_attribute_signup($user_id)
{
    gdc_nr_assign_user($user_id, 'signup');
}

/* ── 4. gend.me checkouts: store (WooCommerce) and plans (WP Ultimo) ──── */

function gdc_nr_is_hub_main()
{
    return gdc_nr_on_hub_network() && is_main_site();
}

// gend.me store order placed: lock in the buyer's referrer; guests get a snapshot on the order.
add_action('woocommerce_checkout_order_processed', 'gdc_nr_store_checkout', 20, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'gdc_nr_store_checkout', 20, 1);
function gdc_nr_store_checkout($order)
{
    if (!gdc_nr_is_hub_main() || !function_exists('wc_get_order')) return;
    $order = is_object($order) ? $order : wc_get_order($order);
    if (!$order) return;
    $uid = (int) $order->get_customer_id();
    if ($uid) gdc_nr_assign_user($uid, 'checkout');
    $ref  = gdc_nr_request_ref();
    $from = gdc_nr_request_from_site();
    if ($ref) $order->update_meta_data('_gdc_nr_ref', $ref);
    if ($from) $order->update_meta_data('_gdc_nr_from_site', $from);
    if ($ref || $from) $order->save();
}

// gend.me plan checkout (Vendor App Manager / WP Ultimo): lock in the customer's referrer.
add_action('wu_checkout_done', 'gdc_nr_plan_checkout', 20, 3);
function gdc_nr_plan_checkout($payment, $membership = null, $customer = null)
{
    if (!gdc_nr_on_hub_network()) return;
    if (!$customer && $payment && method_exists($payment, 'get_customer')) $customer = $payment->get_customer();
    $uid = $customer && method_exists($customer, 'get_user_id') ? (int) $customer->get_user_id() : 0;
    if ($uid) gdc_nr_assign_user($uid, 'checkout');
}

/**
 * Store orders on gend.me: the customer's lifetime referrer keeps the sale;
 * only customers without one (incl. guests) fall back to the link used at
 * checkout, then the owner of the site they came from.
 */
add_filter('aas_resolve_order_referrer', 'gdc_nr_resolve_store_order', 10, 2);
function gdc_nr_resolve_store_order($resolved, $order)
{
    if (!gdc_nr_is_hub_main() || !function_exists('aas_payout_resolve_program') || !$order) return $resolved;
    $uid = (int) $order->get_user_id();
    $ref = $uid ? (int) get_user_meta($uid, 'referrer_id', true) : 0;
    if ($ref) {
        return array('ref' => $ref, 'program' => aas_payout_resolve_program(get_user_meta($uid, 'referrer_program_id', true)));
    }
    foreach (array('_gdc_nr_ref', '_aas_checkout_referrer_id') as $k) {
        $r = (int) $order->get_meta($k, true);
        if ($r && $r !== $uid && get_userdata($r)) return array('ref' => $r, 'program' => aas_payout_resolve_program(''));
    }
    $owner = gdc_nr_site_owner((int) $order->get_meta('_gdc_nr_from_site', true));
    if ($owner && $owner !== $uid) return array('ref' => $owner, 'program' => aas_payout_resolve_program(''));
    return $resolved;
}

/* ── 5. Plan payments (WP Ultimo): commission on every paid payment ───── */

const GDC_NR_WU_UNPAID = 'gdc_nr_wu_unpaid_commissions'; // site option: payment ids awaiting payout
const GDC_NR_WU_QUEUE  = 'gdc_nr_wu_commission_queue';   // site option: completed payments to log on the main site

add_action('wu_transition_payment_status', 'gdc_nr_wu_payment_status', 20, 3);
function gdc_nr_wu_payment_status($old_status, $new_status, $payment_id)
{
    if ($new_status !== 'completed' || !gdc_nr_on_hub_network()) return;
    if (gdc_nr_is_hub_main() && function_exists('aas_get_commission_rate')) {
        gdc_nr_wu_log_commission((int) $payment_id);
    } else {
        // gend.me's program runs on the main site — log it there on the next run.
        $q = (array) get_site_option(GDC_NR_WU_QUEUE, array());
        $q[] = (int) $payment_id;
        update_site_option(GDC_NR_WU_QUEUE, array_values(array_unique(array_map('intval', $q))));
    }
}

/** A WP Ultimo payment by id (filterable). */
function gdc_nr_wu_payment($payment_id)
{
    return apply_filters('gdc_nr_wu_payment', function_exists('wu_get_payment') ? wu_get_payment($payment_id) : null, (int) $payment_id);
}

/** Record the commission for one completed WP Ultimo payment (main site context). */
function gdc_nr_wu_log_commission($payment_id)
{
    $payment = gdc_nr_wu_payment($payment_id);
    if (!$payment || $payment->get_status() !== 'completed' || $payment->get_meta('_aas_commission_logged')) return;
    $amount = (float) $payment->get_total();
    $customer = $payment->get_customer();
    $uid = $customer ? (int) $customer->get_user_id() : 0;
    if ($amount <= 0 || !$uid) return;

    $ref = (int) get_user_meta($uid, 'referrer_id', true);
    if (!$ref || $ref === $uid || !get_userdata($ref)) return;
    $program = aas_payout_resolve_program(get_user_meta($uid, 'referrer_program_id', true));
    if (!$program) return;

    $commission = round($amount * aas_get_commission_rate($ref, 1, false) / 100, 2);
    $team = array();
    if (!empty($program['multi_tier_enabled']) && !empty($program['team_levels']) && function_exists('aas_get_user_team_upline')) {
        $member = $ref;
        foreach ($program['team_levels'] as $level) {
            $upline = aas_get_user_team_upline($member, $program);
            if (!$upline) break;
            $rate = aas_get_commission_rate_from_tiers($level['tiers'], $upline, 1, $member);
            $amt  = round($amount * $rate / 100, 2);
            if ($amt > 0) $team[] = array('level' => $level['level'], 'user_id' => $upline, 'amount' => $amt);
            $member = $upline;
        }
    }

    $payment->update_meta('_aas_commission_logged', 1);
    $payment->update_meta('_aas_referrer_id', $ref);
    $payment->update_meta('_aas_referrer_program_id', $program['id']);
    $payment->update_meta('_aas_commission_amount', $commission);
    $payment->update_meta('_aas_payout_time', time() + (int) $program['wait_days'] * DAY_IN_SECONDS);
    if ($team) $payment->update_meta('_aas_team_payouts_json', wp_json_encode($team));
    $payment->update_meta('_aas_paid', 'no');

    $unpaid = (array) get_site_option(GDC_NR_WU_UNPAID, array());
    $unpaid[] = (int) $payment_id;
    update_site_option(GDC_NR_WU_UNPAID, array_values(array_unique(array_map('intval', $unpaid))));
}

// Same daily run that releases store-order commissions (main site only).
add_action('aas_release_commissions_hook', 'gdc_nr_wu_release_commissions', 20);
function gdc_nr_wu_release_commissions()
{
    if (!gdc_nr_is_hub_main() || !function_exists('mycred_add')) return;

    $queue = (array) get_site_option(GDC_NR_WU_QUEUE, array());
    if ($queue) {
        delete_site_option(GDC_NR_WU_QUEUE);
        foreach ($queue as $pid) gdc_nr_wu_log_commission((int) $pid);
    }

    $remaining = array();
    foreach ((array) get_site_option(GDC_NR_WU_UNPAID, array()) as $pid) {
        $payment = gdc_nr_wu_payment((int) $pid);
        if (!$payment || $payment->get_meta('_aas_paid') === 'yes') continue;
        if (time() < (int) $payment->get_meta('_aas_payout_time')) { $remaining[] = (int) $pid; continue; }
        $program = aas_payout_resolve_program($payment->get_meta('_aas_referrer_program_id'));
        $pt   = $program ? $program['payout_point_type'] : 'mycred_default';
        $data = array('ref_type' => 'wu_payment', 'payment_id' => (int) $pid);
        $ref  = (int) $payment->get_meta('_aas_referrer_id');
        $c    = (float) $payment->get_meta('_aas_commission_amount');
        if ($ref && $c > 0) mycred_add('referral_commission', $ref, $c, 'Referral Sale (plan payment #' . (int) $pid . ')', (int) $pid, $data, $pt);
        foreach ((array) json_decode((string) $payment->get_meta('_aas_team_payouts_json'), true) as $tp) {
            $tu = (int) ($tp['user_id'] ?? 0);
            $ta = (float) ($tp['amount'] ?? 0);
            if ($tu && $ta > 0) mycred_add('team_referral_commission', $tu, $ta, 'Team Referral (Level ' . (int) ($tp['level'] ?? 0) . ', plan payment #' . (int) $pid . ')', (int) $pid, $data, $pt);
        }
        $payment->update_meta('_aas_paid', 'yes');
    }
    update_site_option(GDC_NR_WU_UNPAID, $remaining);
}
