<?php
/**
 * GenD Society: dashboard access (owner 2026-11-14, Organization > Access, stage 7).
 *
 * Who can use or manage each wp-admin dashboard of this web app, without managing people one by one.
 *
 * - Dashboards: gend_society_da_dashboards(): App (Theme Builder, Content Campaigns, Talk Flows), Store (Store
 *   Management, Sales Team, Project Services), Social (Member Management, Point Bank, Contracts & Payments) and
 *   Features (plugins, updates, shortcodes).
 * - Levels: none | use | manage. Use = open the dashboard and do the day-to-day work. Manage = also its settings.
 * - Who: BuddyPress member types of this web app, individual people, and grants from gend.me service tasks (signed,
 *   per task, accepted by the buyer at checkout; stored with the task's role in user meta gend_society_member_grants,
 *   so revoking the task removes them). The highest level wins. Features can only go to individual people or task
 *   grants, never to a whole member type.
 * - Storage: option gend_society_dashboard_access = {types: {type: {key: level}}, users: {uid: {key: level}}}.
 * - Capabilities: gs_dash_<key>_use / gs_dash_<key>_manage via user_has_cap. Site administrators (manage_options)
 *   hold them all, so nothing changes for them. Each dashboard's pages and save handlers check these capabilities
 *   (dashboard by dashboard, in their own plugins); until a page is converted it stays administrator-only.
 * - Menus: people with a level see the dashboard's menu entries (merged into the per-person allowed-menu list read
 *   by admin-menu.php / frontend-bar.php) and WordPress lets them open the converted pages.
 * - Signed REST for gend.me (same Ed25519 rail as member-access.php): POST gs/v1/dashboard-access/get | save.
 *
 * @package GenD_Society
 */

if (!defined('ABSPATH')) {
    exit;
}

/** key => [label, area label, top menu slug, page slugs, extra WordPress caps at use, extra caps at manage, people only]. */
function gend_society_da_dashboards()
{
    return apply_filters('gend_society_da_dashboards', array(
        'theme_builder'      => array(__('Theme Builder', 'gend-society'), __('App', 'gend-society'), 'gs-content', array('site-editor.php', 'gs-design-studio'), array('edit_theme_options'), array('edit_theme_options'), false),
        'content_campaigns'  => array(__('Content Campaigns', 'gend-society'), __('App', 'gend-society'), 'gs-content', array('content-campaigns', 'blog-manager'), array('edit_posts', 'upload_files'), array('edit_posts', 'upload_files', 'edit_others_posts', 'publish_posts'), false),
        'talk_flows'         => array(__('Talk Flows', 'gend-society'), __('App', 'gend-society'), 'gs-content', array('talk-flows'), array(), array(), false),
        'store_management'   => array(__('Store Management', 'gend-society'), __('Store', 'gend-society'), 'gs-store', array('gdc-store-settings', 'gdc-store-reports'), array('read_product', 'edit_products', 'edit_published_products', 'edit_others_products', 'publish_products', 'read_private_products', 'assign_product_terms', 'upload_files', 'read_shop_order', 'edit_shop_orders', 'edit_others_shop_orders', 'edit_published_shop_orders', 'read_private_shop_orders', 'view_woocommerce_reports'), array('manage_woocommerce', 'manage_product_terms', 'edit_product_terms', 'delete_products', 'delete_published_products', 'delete_others_products', 'edit_shop_coupons', 'edit_others_shop_coupons', 'publish_shop_coupons', 'read_private_shop_coupons'), false),
        'sales_team'         => array(__('Sales Team', 'gend-society'), __('Store', 'gend-society'), 'gs-store', array('st_sales_team'), array(), array(), false),
        'project_services'   => array(__('Project Services', 'gend-society'), __('Store', 'gend-society'), 'gs-store', array('psoo-projects'), array(), array(), false),
        'social_profiles'    => array(__('Member Management', 'gend-society'), __('Social', 'gend-society'), 'gs-social', array('gdc-social-network-settings'), array(), array(), false),
        'point_bank'         => array(__('Point Bank', 'gend-society'), __('Social', 'gend-society'), 'gs-social', array('gs-rewards', 'gdc-reward-point-bank', 'gdc-reward-member-wallets'), array(), array(), false),
        'contracts_payments' => array(__('Contracts & Payments', 'gend-society'), __('Social', 'gend-society'), 'gs-social', array('gend-contracts-payments'), array(), array(), false),
        'features'           => array(__('Features', 'gend-society'), __('Features', 'gend-society'), 'gs-features', array('gs-features', 'gs-shortcodes', 'plugins.php', 'update-core.php'), array(), array(), true),
    ));
}

function gend_society_da_levels()
{
    return array('none' => 0, 'use' => 1, 'manage' => 2);
}

function gend_society_da_config()
{
    $c = get_option('gend_society_dashboard_access', array());
    $c = is_array($c) ? $c : array();
    return array('types' => (array) ($c['types'] ?? array()), 'users' => (array) ($c['users'] ?? array()));
}

/** Clean a {key: level} map. $people_only = whether people-only dashboards (Features) are allowed. */
function gend_society_da_clean_map($map, $people_only_ok)
{
    $out = array();
    $dash = gend_society_da_dashboards();
    $lv = gend_society_da_levels();
    foreach ((array) $map as $k => $l) {
        $k = sanitize_key($k);
        $l = sanitize_key($l);
        if (!isset($dash[$k]) || !isset($lv[$l]) || 'none' === $l) continue;
        if ($dash[$k][6] && !$people_only_ok) continue;
        $out[$k] = $l;
    }
    return $out;
}

/** Every dashboard's level for this person (key => 0|1|2). */
function gend_society_da_user_levels($uid)
{
    static $cache = array();
    $uid = (int) $uid;
    if ($uid <= 0) return array();
    if (isset($cache[$uid])) return $cache[$uid];
    $lv = gend_society_da_levels();
    $out = array();
    $take = function ($map) use (&$out, $lv) {
        foreach ((array) $map as $k => $l) {
            if (isset($lv[$l])) $out[$k] = max($out[$k] ?? 0, $lv[$l]);
        }
    };
    $c = gend_society_da_config();
    if (function_exists('bp_get_member_type')) {
        foreach ((array) bp_get_member_type($uid, false) as $t) {
            if (isset($c['types'][$t])) $take(gend_society_da_clean_map($c['types'][$t], false));
        }
    }
    if (isset($c['users'][$uid])) $take($c['users'][$uid]);
    $grants = get_user_meta($uid, 'gend_society_member_grants', true);
    foreach (is_array($grants) ? $grants : array() as $g) {
        if (!empty($g['access'])) $take($g['access']);
    }
    return $cache[$uid] = $out;
}

function gend_society_da_level($uid, $key)
{
    $l = gend_society_da_user_levels($uid);
    return (int) ($l[$key] ?? 0);
}

/* ------------------------------------------------------------------ capabilities */

add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (empty($caps) || !is_object($user) || empty($user->ID)) return $allcaps;
    $levels = null;
    foreach ((array) $caps as $cap) {
        if (0 !== strpos((string) $cap, 'gs_dash_')) continue;
        if (!empty($allcaps['manage_options'])) { $allcaps[$cap] = true; continue; }
        if (!preg_match('/^gs_dash_([a-z_]+)_(use|manage)$/', $cap, $m)) continue;
        $levels = null === $levels ? gend_society_da_user_levels($user->ID) : $levels;
        if (($levels[$m[1]] ?? 0) >= ('manage' === $m[2] ? 2 : 1)) $allcaps[$cap] = true;
    }
    // WordPress capabilities a dashboard needs to work (e.g. the Site Editor needs edit_theme_options).
    if (empty($allcaps['manage_options'])) {
        $levels = null === $levels ? gend_society_da_user_levels($user->ID) : $levels;
        if ($levels) {
            foreach (gend_society_da_dashboards() as $k => $d) {
                $l = $levels[$k] ?? 0;
                if ($l >= 1) foreach ($d[4] as $c) $allcaps[$c] = true;
                if ($l >= 2) foreach ($d[5] as $c) $allcaps[$c] = true;
            }
            $allcaps['read'] = true; // reach wp-admin
        }
    }
    return $allcaps;
}, 20, 4);

/* ------------------------------------------------------------------ menus */

/** Menu slugs this person may see because of their dashboard levels. */
function gend_society_da_allowed_slugs($uid)
{
    $levels = gend_society_da_user_levels($uid);
    if (!$levels) return array();
    $dash = gend_society_da_dashboards();
    $out = array();
    global $submenu;
    foreach ($levels as $k => $l) {
        if ($l < 1 || !isset($dash[$k])) continue;
        $out[] = $dash[$k][2];
        foreach ($dash[$k][3] as $s) $out[] = $s;
    }
    return array_values(array_unique($out));
}

/** Merge dashboard access into the per-person allowed-menu list (gend_society_hb_access_key()). */
add_filter('get_user_metadata', function ($value, $uid, $key, $single) {
    static $busy = false;
    if ($busy || !function_exists('gend_society_hb_access_key') || $key !== gend_society_hb_access_key()) return $value;
    $extra = gend_society_da_allowed_slugs($uid);
    if (!$extra) return $value;
    $busy = true;
    $stored = get_user_meta($uid, $key, true);
    $busy = false;
    $merged = array_values(array_unique(array_merge(is_array($stored) ? $stored : array(), $extra)));
    return array($merged);
}, 10, 4);

/**
 * Let people with a level open the dashboard's menus: the App / Store / Social top menus and each converted page are
 * registered for administrators; for this person, point them at the dashboard capabilities instead.
 */
add_action('admin_menu', function () {
    $uid = get_current_user_id();
    if (!$uid || current_user_can('manage_options')) return;
    $levels = gend_society_da_user_levels($uid);
    if (!$levels) return;
    global $menu, $submenu;
    $dash = gend_society_da_dashboards();
    $page_cap = array();
    $top_ok = array();
    foreach ($dash as $k => $d) {
        if (($levels[$k] ?? 0) < 1) continue;
        foreach ($d[3] as $s) {
            if (!apply_filters('gend_society_da_page_converted', false, $s, $k)) continue; // only pages whose plugin checks the dashboard capability
            $page_cap[$s] = 'gs_dash_' . $k . '_use';
            $top_ok[$d[2]] = true; // the top menu opens only when one of its pages can
        }
    }
    foreach ((array) $menu as $i => $m) {
        if (isset($m[2]) && isset($top_ok[$m[2]])) $menu[$i][1] = 'read';
    }
    foreach ((array) $submenu as $parent => $items) {
        foreach ((array) $items as $j => $it) {
            if (isset($it[2]) && isset($page_cap[$it[2]])) $submenu[$parent][$j][1] = $page_cap[$it[2]];
        }
    }
}, 9999);

/* ------------------------------------------------------------------ data for gend.me (Organization > Access) */

/** Member types, people and the current settings, for the Access popups. */
function gend_society_da_snapshot($search = '')
{
    $types = array();
    if (function_exists('bp_get_member_types')) {
        foreach ((array) bp_get_member_types(array(), 'objects') as $t) {
            $types[$t->name] = isset($t->labels['singular_name']) ? $t->labels['singular_name'] : $t->name;
        }
    }
    $args = array('number' => 300, 'orderby' => 'display_name', 'fields' => array('ID', 'display_name', 'user_email'));
    if ('' !== $search) {
        $args['search'] = '*' . $search . '*';
        $args['search_columns'] = array('display_name', 'user_email', 'user_login');
    }
    $people = array();
    foreach (get_users($args) as $u) {
        $wu = get_userdata($u->ID);
        if ($wu && user_can($wu, 'manage_options')) continue; // admins already have everything
        $people[] = array('id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email);
    }
    $dash = array();
    foreach (gend_society_da_dashboards() as $k => $d) $dash[] = array('key' => $k, 'label' => $d[0], 'area' => $d[1], 'people_only' => (bool) $d[6], 'ready' => (bool) array_filter(array_map(function ($s) use ($k) { return apply_filters('gend_society_da_page_converted', false, $s, $k); }, $d[3])));
    $grants = array();
    foreach (get_users(array('meta_key' => 'gend_society_member_grants', 'number' => 200, 'fields' => array('ID', 'display_name'))) as $u) {
        $g = get_user_meta($u->ID, 'gend_society_member_grants', true);
        foreach (is_array($g) ? $g : array() as $ref => $row) {
            if (!empty($row['access'])) $grants[] = array('user' => (int) $u->ID, 'name' => $u->display_name, 'ref' => (string) $ref, 'access' => $row['access']);
        }
    }
    return array('dashboards' => $dash, 'types' => $types, 'people' => $people, 'config' => gend_society_da_config(), 'grants' => $grants);
}

/** Save the settings (types + users). */
function gend_society_da_save($config)
{
    $config = (array) $config;
    $types = array();
    $known = function_exists('bp_get_member_types') ? array_keys((array) bp_get_member_types()) : array();
    foreach ((array) ($config['types'] ?? array()) as $t => $map) {
        $t = sanitize_key($t);
        if (!in_array($t, $known, true)) continue;
        $m = gend_society_da_clean_map($map, false);
        if ($m) $types[$t] = $m;
    }
    $users = array();
    foreach ((array) ($config['users'] ?? array()) as $uid => $map) {
        $uid = absint($uid);
        if (!$uid || !get_userdata($uid)) continue;
        $m = gend_society_da_clean_map($map, true);
        if ($m) $users[$uid] = $m;
    }
    update_option('gend_society_dashboard_access', array('types' => $types, 'users' => $users), false);
    return gend_society_da_config();
}

add_action('rest_api_init', function () {
    register_rest_route('gs/v1', '/dashboard-access/get', array('methods' => 'POST', 'callback' => 'gend_society_da_rest_get', 'permission_callback' => '__return_true')); // signed
    register_rest_route('gs/v1', '/dashboard-access/save', array('methods' => 'POST', 'callback' => 'gend_society_da_rest_save', 'permission_callback' => '__return_true')); // signed
});

function gend_society_da_verify(\WP_REST_Request $request)
{
    if (!function_exists('gend_society_support_access_verify_signed_request')) {
        return new \WP_Error('unavailable', 'Signed requests are not available on this site.', array('status' => 503));
    }
    return gend_society_support_access_verify_signed_request($request);
}

function gend_society_da_rest_get(\WP_REST_Request $request)
{
    $p = gend_society_da_verify($request);
    if (is_wp_error($p)) return $p;
    return rest_ensure_response(gend_society_da_snapshot(sanitize_text_field((string) ($p['search'] ?? ''))));
}

function gend_society_da_rest_save(\WP_REST_Request $request)
{
    $p = gend_society_da_verify($request);
    if (is_wp_error($p)) return $p;
    return rest_ensure_response(array('ok' => true, 'config' => gend_society_da_save((array) ($p['config'] ?? array()))));
}

add_filter('rest_authentication_errors', function ($result) {
    if (!is_wp_error($result)) return $result;
    $route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    return ($route !== '' && strpos($route, '/gs/v1/dashboard-access/') !== false) ? null : $result;
}, 100);

/** Pages that already check the dashboard capabilities (each plugin adds its own as it is converted). */
add_filter('gend_society_da_page_converted', function ($ok, $slug) {
    // WordPress's Site Editor only needs edit_theme_options, which a Theme Builder level grants.
    return $ok || in_array($slug, array('site-editor.php', 'content-campaigns', 'blog-manager', 'talk-flows', 'gdc-store-settings', 'gdc-store-reports', 'gs-rewards', 'gdc-reward-point-bank', 'gdc-reward-member-wallets'), true);
}, 10, 2);

/** Content Campaigns (blog-manager): a Content Campaigns level opens the workspace (BM_Access::can_admin). */
add_filter('bm_user_can_admin', function ($ok, $uid) {
    return $ok || user_can((int) $uid, 'gs_dash_content_campaigns_use');
}, 10, 2);
