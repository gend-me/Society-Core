<?php
/**
 * Section embed (plan 109-08; PLAN-04, D5, D11, Guideline 8): hub-hosted areas inside wp-admin on a connected
 * standalone install, rendered locally from GET /install/{id}/hub/{area} JSON (fixture hub).
 *
 *   pages        gs-hub-groups / gs-hub-feature-suite / gs-hub-projects registered per the features payload
 *                (hub_embed only; Projects absent when the hub does not offer it); admin_url on included areas
 *   connected    (free, D11) Groups + Feature Suite render the fixture data; Projects shows the upgrade copy
 *                naming Social Connector (402) with the "Choose a plan on gend.me" popup button (109-21)
 *   escaping     markup injected into hub strings is printed as text; non-hub image hosts become placeholders
 *   states       402 (hub body names the plan), 409 After migration, 401 Reconnect, 404, unreachable (stale
 *                data + note / no data), store-owner and inherited scenarios
 *   cache        gend_society_hub_area_cache_<area> written with TTL <= 300 (also when cache_seconds is 3600)
 *   refresh      the Refresh AJAX (gs_view=hub) returns #gs-hub-<area> re-rendered, fetched without If-None-Match
 *   page         real loopback admin page: no iframe, local scripts/styles only, images on the bed or hub host only,
 *                gs-hub-embed.js/css + gs-plan.js from the plugin, no inline script/style beyond localize data,
 *                no token in HTML / Location / gs109-http.log; subscriber gets no page
 * hub / container: the module is not loaded.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_output.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_hub_area_fetch' ) && ! has_action( 'admin_menu', 'gend_society_hub_embed_register' ), 'embed-absent', 'hub-embed.php not loaded on ' . gs109_mode() );
	return;
}

$fx = gs109pl_start( 'connected' );
if ( ! $fx ) {
	return;
}
$hp = GEND_SOCIETY_DIR . 'inc/hub-embed.php';
if ( ! function_exists( 'gend_society_hub_area_fetch' ) && is_readable( $hp ) ) {
	require_once $hp; // loaded at boot only once paired (needs standalone + paired)
}
if ( ! gs109_assert( function_exists( 'gend_society_hub_area_fetch' ) && function_exists( 'gend_society_feature_upgrade_render_standalone' ), 'embed-loaded', 'inc/hub-embed.php + standalone upgrade renderer loaded' ) ) {
	return;
}
$token   = $fx['token'];
$mark    = gs109p_httplog_mark();
$all     = '';
$frag    = function ( $area, $force = false ) use ( &$all ) {
	$h    = gend_society_hub_embed_fragment( $area, $force );
	$all .= $h;
	return $h;
};
$reset_menu = function () {
	$GLOBALS['menu']                         = array();
	$GLOBALS['submenu']                      = array();
	$GLOBALS['_registered_pages']            = array();
	$GLOBALS['admin_page_hooks']             = array();
	$GLOBALS['gend_society_hub_embed_hooks'] = array();
};
gs109p_http_capture();

// ── pages registered per the features payload ──────────────────────────────────────────────────────────
$reset_menu();
$pages = gs109p_registered_pages();
gs109_assert( in_array( 'gs-hub-groups', $pages, true ) && in_array( 'gs-hub-feature-suite', $pages, true ) && in_array( 'gs-hub-projects', $pages, true ), 'embed-pages-connected', 'connected: gs-hub-groups, gs-hub-feature-suite, gs-hub-projects registered', array_values( preg_grep( '/^gs-/', $pages ) ) );
gs109_assert_eq( array( 'groups', 'feature-suite', 'projects' ), gend_society_hub_embed_offered(), 'embed-offered', 'offered = the three hub_embed areas of the fixture' );
$parent = function_exists( 'gend_society_welcome_page_slug' ) ? gend_society_welcome_page_slug() : 'gend-society';
$subs   = array_map(
	function ( $m ) {
		return (string) $m[2];
	},
	(array) ( $GLOBALS['submenu'][ $parent ] ?? array() )
);
gs109_assert( in_array( 'gs-hub-groups', $subs, true ) && in_array( 'gs-hub-feature-suite', $subs, true ), 'embed-pages-parent', 'pages sit under the GenD page (' . $parent . ')', $subs );

// Projects not offered (container_only in the payload) -> no page; nothing cached -> nothing extra fetched
$feat  = gs109_fixture_json( 'connected', 'features.json' );
$nopro = $feat;
foreach ( $nopro['areas'] as &$a ) {
	if ( 'projects' === $a['slug'] ) {
		$a['delivery'] = 'container_only';
		$a['state']    = 'after_migration';
	}
}
unset( $a );
$nopro['rev'] = 'gs109-no-projects';
gs109p_clear_caches();
$off = gs109p_inject( 'features', 200, $nopro );
gend_society_plan_features( true );
$off();
$reset_menu();
$pages = gs109p_registered_pages();
gs109_assert( ! in_array( 'gs-hub-projects', $pages, true ) && in_array( 'gs-hub-groups', $pages, true ), 'embed-pages-no-projects', 'Projects not offered by the hub -> no gs-hub-projects page', array_values( preg_grep( '/^gs-hub-/', $pages ) ) );
gs109p_clear_caches();
$reset_menu();

// ── connected (free, D11) ──────────────────────────────────────────────────────────────────────────────
$vm = gend_society_plan_view_model( true );
$ad = array();
foreach ( $vm['areas'] as $a ) {
	$ad[ $a['slug'] ] = $a['admin_url'];
}
gs109_assert( admin_url( 'admin.php?page=gs-hub-groups' ) === ( $ad['groups'] ?? '' ) && admin_url( 'admin.php?page=gs-hub-feature-suite' ) === ( $ad['feature-suite'] ?? '' ) && '' === ( $ad['projects'] ?? 'x' ), 'embed-admin-url', 'view-model: included hub_embed areas carry admin.php?page=gs-hub-<area>; Projects (upgrade) none' );

gs109p_http();
$g  = gs109_fixture_json( 'connected', 'hub-groups.json' );
$h  = $frag( 'groups' );
$xp = gs109pl_xp( $h );
gs109_assert_eq( 'ok', gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]', 'data-gs-hub-state' )[0] ?? '', 'embed-groups-state', 'Groups: state ok, fragment #gs-hub-groups' );
gs109_assert_eq( $g['data']['group']['name'], gs109pl_text( $xp, '//*[@data-gs-hub-field="group-name"]' ), 'embed-groups-name', 'group name from the fixture' );
$mcount = (int) ( $g['data']['group']['member_count'] ?? 0 );
gs109_assert_eq( $mcount . ( 1 === $mcount ? ' member' : ' members' ), gs109pl_text( $xp, '//*[@data-gs-hub-field="member-count"]' ), 'embed-groups-count', 'member count (' . $mcount . ' from the hub data)' );
gs109_assert_eq( $g['data']['group']['description'], gs109pl_text( $xp, '//*[@data-gs-hub-field="description"]' ), 'embed-groups-desc', 'description' );
$names = array();
foreach ( $xp->query( '//li[@data-gs-hub-member]/span[@class="gs-hub-member__name"]' ) as $n ) {
	$names[] = $n->textContent;
}
gs109_assert_eq( array_column( $g['data']['members'], 'name' ), $names, 'embed-groups-members', 'member names in order' );
$acts = array();
foreach ( $xp->query( '//li[@data-gs-hub-activity]/span[@class="gs-hub-activity__text"]' ) as $n ) {
	$acts[] = $n->textContent;
}
gs109_assert_eq( array_column( $g['data']['activity'], 'text' ), $acts, 'embed-groups-activity', 'activity texts in order' );
$open = $xp->query( '//p[@class="gs-hub-group__open"]/a' );
gs109_assert( $open->length === 1 && $open->item( 0 )->getAttribute( 'href' ) === $g['data']['group']['url'] && '_blank' === $open->item( 0 )->getAttribute( 'target' ) && 'noopener' === $open->item( 0 )->getAttribute( 'rel' ) && 'Open on gend.me' === $open->item( 0 )->textContent, 'embed-groups-open', '"Open on gend.me" -> group url, new tab, noopener' );
$hub_reqs = gs109p_hub_requests( gs109p_http() );
gs109_assert( 1 === count( $hub_reqs ) && false !== strpos( $hub_reqs[0]['url'], '/install/' . rawurlencode( $fx['install_id'] ) . '/hub/groups' ) && 'Bearer ' . $token === gs109p_header( $hub_reqs[0]['headers'], 'Authorization' ), 'embed-groups-request', 'one GET /install/{id}/hub/groups with the bearer header (token in the header only)', wp_json_encode( array_column( $hub_reqs, 'url' ) ) );
gs109_assert( false === strpos( $hub_reqs[0]['url'] ?? '', $token ), 'embed-token-url', 'token not in the request URL' );

$c = gs109p_raw( 'gend_society_hub_area_cache_groups' );
$ttl = is_array( $c ) ? (int) $c['expires'] - time() : -1;
gs109_assert( is_array( $c ) && $ttl > 0 && $ttl <= 300 && $g['rev'] === ( $c['rev'] ?? '' ) && $fx['install_id'] === ( $c['install_id'] ?? '' ), 'embed-cache', 'gend_society_hub_area_cache_groups written: rev ' . substr( (string) ( $c['rev'] ?? '' ), 0, 8 ) . ', TTL ' . $ttl . ' s (<= 300)' );
gs109p_http();
$frag( 'groups' );
gs109_assert_eq( 0, count( gs109p_hub_requests( gs109p_http() ) ), 'embed-cache-hit', 'second render within the TTL: no request' );

$fs = gs109_fixture_json( 'connected', 'hub-feature-suite.json' );
$h  = $frag( 'feature-suite' );
$xp = gs109pl_xp( $h );
$rows = $xp->query( '//tr[@data-gs-hub-feature]' );
gs109_assert_eq( count( $fs['data']['features'] ), $rows->length, 'embed-fs-rows', 'Feature Suite: one row per feature (' . count( $fs['data']['features'] ) . ')' );
$bad = array();
foreach ( $fs['data']['features'] as $i => $f ) {
	$row   = $rows->item( $i );
	$want  = 'included' === $f['status'] ? 'Included' : ( 'addon' === $f['status'] ? 'Add-on' : 'Upgrade - ' . $f['unlock_plan']['name'] );
	$badge = $row ? trim( $xp->query( './/span[@data-gs-hub-status]', $row )->item( 0 )->textContent ) : '';
	$name  = $row ? $xp->query( './/td[@class="gs-hub-feature__name"]', $row )->item( 0 )->textContent : '';
	if ( $badge !== $want || $name !== $f['name'] ) {
		$bad[] = "{$f['slug']}: {$name} / {$badge}";
	}
}
gs109_assert( ! $bad, 'embed-fs-badges', 'name + status badge per feature (Included / Upgrade - <plan> / Add-on)', $bad );

$h  = $frag( 'projects' );
$xp = gs109pl_xp( $h );
gs109_assert_eq( 'upgrade', gs109pl_attrs( $xp, '//div[@id="gs-hub-projects"]', 'data-gs-hub-state' )[0] ?? '', 'embed-projects-402', 'Projects on Connected: hub 402 -> upgrade state' );
gs109_assert_eq( 'Available with Social Connector', gs109pl_text( $xp, '//*[@data-gs-up-copy="upgrade"]' ), 'embed-projects-copy', 'Projects: "Available with Social Connector"' );
gs109_assert( gs109pl_popup_is( gs109pl_popup( $xp ), gs109pl_change_url( 'connected' ), 'Choose a plan on gend.me' ) && array() === gs109pl_choose( $xp ), 'embed-projects-choose', 'Projects: one "Choose a plan on gend.me" button to the Change Plan page (no per-tier Choose, no launcher)', gs109pl_popup( $xp ) );
gs109_assert( null === gs109p_raw( 'gend_society_hub_area_cache_projects' ), 'embed-projects-nocache', '402: no hub-area cache kept' );

// ── 402 body names the plan; 409; 404; 401 ───────────────────────────────────────────────────────────────
gs109p_clear_caches();
$off = gs109p_inject( 'hub/groups', 402, array( 'code' => 'upgrade_required', 'state' => 'upgrade', 'unlock_plan' => array( 'id' => 22, 'slug' => 'store-owner', 'name' => 'Store Owner' ) ) );
$xp  = gs109pl_xp( $frag( 'groups' ) );
$off();
gs109_assert_eq( 'Available with Store Owner', gs109pl_text( $xp, '//*[@data-gs-up-copy="upgrade"]' ), 'embed-402-body', '402: the copy names the plan from the hub answer (Store Owner)' );
gs109_assert( gs109pl_popup_is( gs109pl_popup( $xp ), gs109pl_change_url( 'connected' ), 'Choose a plan on gend.me' ) && false !== strpos( (string) gs109pl_text( $xp, '//div[@data-gs-up-area="groups"]' ), 'once this site is on Store Owner' ), 'embed-402-choose', '402: copy names Store Owner, one "Choose a plan on gend.me" button' );

$off = gs109p_inject( 'hub/groups', 409, array( 'code' => 'after_migration', 'state' => 'after_migration' ) );
$h   = $frag( 'groups' );
$off();
$xp  = gs109pl_xp( $h );
gs109_assert_eq( 'Runs on your gend.me container after migration', gs109pl_text( $xp, '//*[@data-gs-up-copy="after_migration"]' ), 'embed-409-copy', '409: After migration copy' );
$ob = gs109pl_attrs( $xp, '//a[@data-gs-up-outbound]', 'href' );
gs109_assert( $ob && 'http://localhost:8919/my-account/memberships/' === $ob[0] && 0 === gs109pl_count( $xp, '//a[@data-gs-plan-checkout or @data-gs-plan-popup]' ), 'embed-409-link', '409: "See container plans on gend.me" on the hub, no plan button', $ob );

$off = gs109p_inject( 'hub/groups', 404, array( 'code' => 'unknown_area' ) );
$xp  = gs109pl_xp( $frag( 'groups' ) );
$off();
gs109_assert_eq( 'unknown_area', gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]', 'data-gs-hub-state' )[0] ?? '', 'embed-404', '404: unknown area state' );

gs109_fixture_scenario( 'revoked' );
gs109p_clear_caches();
$h  = $frag( 'groups' );
$xp = gs109pl_xp( $h );
$rc = gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]//a[contains(@class,"button-primary")]', 'href' );
$cu = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : '';
gs109_assert( 'revoked' === ( gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]', 'data-gs-hub-state' )[0] ?? '' ) && false !== strpos( (string) gs109pl_text( $xp, '//div[@id="gs-hub-groups"]' ), 'Reconnect to gend.me' ) && $rc && html_entity_decode( $rc[0] ) === $cu, 'embed-401', '401: Reconnect state linking the Connect page', $rc );
gs109_fixture_scenario( 'connected' );
gs109p_clear_caches();

// ── unreachable: stale data + note; nothing cached -> note only ─────────────────────────────────────────
$frag( 'groups' );
$c            = get_option( 'gend_society_hub_area_cache_groups' );
$c['expires'] = 0;
update_option( 'gend_society_hub_area_cache_groups', $c, false );
$off = gs109p_inject( 'hub/groups', new WP_Error( 'http_request_failed', 'gs109 down' ), '' );
$xp  = gs109pl_xp( $frag( 'groups' ) );
gs109_assert( 1 === gs109pl_count( $xp, '//div[@id="gs-hub-groups"][@data-gs-hub-stale="1"]' ) && $g['data']['group']['name'] === gs109pl_text( $xp, '//*[@data-gs-hub-field="group-name"]' ) && false !== strpos( (string) gs109pl_text( $xp, '//div[contains(@class,"gs-hub-note")]' ), 'Could not reach gend.me' ), 'embed-unreachable-stale', 'unreachable with a cache: stale data + "Could not reach gend.me"' );
gs109p_clear_caches();
$xp = gs109pl_xp( $frag( 'groups' ) );
$off();
gs109_assert( 'unreachable' === ( gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]', 'data-gs-hub-state' )[0] ?? '' ) && 0 === gs109pl_count( $xp, '//*[@data-gs-hub-field]' ), 'embed-unreachable-empty', 'unreachable without a cache: note only, no data' );

// ── cache_seconds 3600 is capped at 300 ──────────────────────────────────────────────────────────────────
gs109p_clear_caches();
$long                  = $g;
$long['cache_seconds'] = 3600;
$off = gs109p_inject( 'hub/groups', 200, $long );
$frag( 'groups' );
$off();
$c   = gs109p_raw( 'gend_society_hub_area_cache_groups' );
$ttl = is_array( $c ) ? (int) $c['expires'] - time() : -1;
gs109_assert( $ttl > 0 && $ttl <= 300, 'embed-ttl-cap', 'cache_seconds 3600 -> TTL ' . $ttl . ' s (<= 300)' );

// ── escaping + image hosts ──────────────────────────────────────────────────────────────────────────────
gs109p_clear_caches();
$evil                                    = $g;
$evil['data']['group']['name']           = '<script>alert("gs109")</script>Evil Group';
$evil['data']['group']['description']    = '<img src=x onerror=alert(1)>desc';
$evil['data']['group']['avatar_url']     = 'https://evil.example/avatar.png';
$evil['data']['members']                 = array_slice( array_pad( (array) $evil['data']['members'], 3, array( 'name' => 'GS109 Member', 'role' => 'member', 'avatar_url' => '' ) ), 0, 3 );
$evil['data']['members'][2]['avatar_url'] = 'http://localhost:8919/wp-content/uploads/avatars/4/avatar.png';
$evil['data']['members'][0]['name']      = '<b>Bold</b> & "Q"';
$evil['data']['members'][0]['avatar_url'] = 'http://localhost:8909/wp-content/uploads/bed.png';
$evil['data']['members'][1]['avatar_url'] = 'javascript:alert(1)';
$evil['data']['activity'][0]['text']     = '<a href="https://evil.example">x</a> joined';
$evil['data']['activity'][0]['url']      = 'https://evil.example/activity';
$evil['data']['group']['url']            = 'https://evil.example/group';
$off = gs109p_inject( 'hub/groups', 200, $evil );
$h   = $frag( 'groups' );
$off();
$xp  = gs109pl_xp( $h );
gs109_assert( 0 === gs109pl_count( $xp, '//div[@id="gs-hub-groups"]//script' ) && false === strpos( $h, '<script>alert' ) && '<script>alert("gs109")</script>Evil Group' === gs109pl_text( $xp, '//*[@data-gs-hub-field="group-name"]' ), 'embed-escape-script', 'injected <script> in the group name is printed as text, no script element' );
gs109_assert( 0 === gs109pl_count( $xp, '//div[@id="gs-hub-groups"]//b' ) && '<b>Bold</b> & "Q"' === $xp->query( '//span[@class="gs-hub-member__name"]' )->item( 0 )->textContent && '<img src=x onerror=alert(1)>desc' === gs109pl_text( $xp, '//*[@data-gs-hub-field="description"]' ), 'embed-escape-text', 'markup in member names / description escaped' );
$srcs = gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]//img', 'src' );
$foreign = array_values(
	array_filter(
		$srcs,
		function ( $s ) {
			return 0 !== strpos( $s, 'http://localhost:8919/' );
		}
	)
);
gs109_assert( ! $foreign && 1 === count( $srcs ) && 3 === gs109pl_count( $xp, '//*[@data-gs-hub-placeholder]' ), 'embed-img-hosts', 'images only from the hub host (1 kept); evil.example, the bed host and javascript: became 3 local placeholders', $srcs );
$links = gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]//a', 'href' );
gs109_assert( ! preg_grep( '/evil\.example/', $links ) && 0 === gs109pl_count( $xp, '//p[@class="gs-hub-group__open"]' ), 'embed-link-hosts', 'non-hub URLs are not linked (no Open on gend.me, plain activity date)', $links );

// ── store-owner / inherited ─────────────────────────────────────────────────────────────────────────────
gs109_fixture_scenario( 'inherited' );
gs109p_clear_caches();
$pj = gs109_fixture_json( 'inherited', 'hub-projects.json' );
$xp = gs109pl_xp( $frag( 'projects' ) );
$titles = array();
foreach ( $xp->query( '//td[@class="gs-hub-project__title"]' ) as $n ) {
	$titles[] = $n->textContent;
}
$plinks = gs109pl_attrs( $xp, '//tr[@data-gs-hub-project]//a', 'href' );
gs109_assert( array_column( $pj['data']['projects'], 'title' ) === $titles && array_column( $pj['data']['projects'], 'url' ) === $plinks && '-' === gs109pl_text( $xp, '(//td[@class="gs-hub-project__due"])[2]' ), 'embed-projects-inherited', 'inherited Social Connector: Projects table (title / status / due / link) from the fixture', $titles );
gs109_fixture_scenario( 'store-owner' );
gs109p_clear_caches();
$xp = gs109pl_xp( $frag( 'groups' ) );
gs109_assert_eq( 'ok', gs109pl_attrs( $xp, '//div[@id="gs-hub-groups"]', 'data-gs-hub-state' )[0] ?? '', 'embed-store-owner', 'store-owner: Groups renders' );
gs109_fixture_scenario( 'connected' );
gs109p_clear_caches();

// ── Refresh AJAX: fragment re-rendered, fetched without If-None-Match ────────────────────────────────────
$frag( 'groups' );
$renamed                          = $g;
$renamed['data']['group']['name'] = 'GS109 Renamed Group';
$renamed['rev']                   = 'gs109-renamed';
$off = gs109p_inject( 'hub/groups', 200, $renamed );
gs109p_http();
$r = gs109p_call( 'gend_society_plan_refresh_ajax', 'POST', array(), array( 'nonce' => wp_create_nonce( 'gs_plan' ), 'gs_view' => 'hub', 'gs_area' => 'groups' ), true );
$off();
$reqs  = gs109p_hub_requests( gs109p_http() );
$hubrq = array_values(
	array_filter(
		$reqs,
		function ( $q ) {
			return false !== strpos( $q['url'], '/hub/groups' );
		}
	)
);
$html  = is_array( $r['json'] ) ? (array) ( $r['json']['data']['html'] ?? array() ) : array();
$all  .= implode( '', array_map( 'strval', $html ) );
$xp    = gs109pl_xp( (string) ( $html['gs-hub-groups'] ?? '' ) );
gs109_assert( ! empty( $r['json']['success'] ) && 'GS109 Renamed Group' === gs109pl_text( $xp, '//*[@data-gs-hub-field="group-name"]' ), 'embed-refresh-fragment', 'Refresh AJAX (gs_view=hub, gs_area=groups) returns #gs-hub-groups re-rendered', array_keys( $html ) );
gs109_assert( 1 === count( $hubrq ) && '' === gs109p_header( $hubrq[0]['headers'], 'If-None-Match' ), 'embed-refresh-unconditional', 'Refresh: one hub/groups GET without If-None-Match', count( $hubrq ) );
$c = gs109p_raw( 'gend_society_hub_area_cache_groups' );
gs109_assert( is_array( $c ) && 'gs109-renamed' === ( $c['rev'] ?? '' ), 'embed-refresh-cache', 'Refresh rewrote the hub-area cache' );
$r2 = gs109p_call( 'gend_society_plan_refresh_ajax', 'POST', array(), array( 'nonce' => wp_create_nonce( 'gs_plan' ) ), true );
gs109_assert( ! empty( $r2['json']['success'] ) && ! isset( $r2['json']['data']['html']['gs-hub-groups'] ), 'embed-refresh-scoped', 'Refresh from another page (no gs_view) adds no hub fragment' );

// ── real admin pages over loopback ─────────────────────────────────────────────────────────────────────
gs109p_clear_caches();
$bed  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
$sess = gs109p_session( gs109p_admin_id() );
$core = gs109p_web( 'GET', admin_url( 'options-general.php' ), $sess );
$cx   = gs109pl_xp_page( $core['body'] );
$sig  = function ( DOMXPath $x, $tag ) {
	$out = array();
	foreach ( $x->query( '//' . $tag . '[not(@src)]' ) as $el ) {
		$id = (string) $el->getAttribute( 'id' );
		$out[ '' !== $id ? $id : 'noid:' . substr( preg_replace( '/\s+/', ' ', trim( $el->textContent ) ), 0, 24 ) ] = true;
	}
	return array_keys( $out );
};
foreach ( array( 'groups', 'feature-suite', 'projects' ) as $area ) {
	$p = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-hub-' . $area ), $sess );
	$all .= $p['body'];
	$xp   = gs109pl_xp_page( $p['body'] );
	gs109_assert( 200 === $p['status'] && '' === $p['location'] && 1 === gs109pl_count( $xp, '//div[@id="gs-hub-' . $area . '"]' ), "embed-page-{$area}", "admin.php?page=gs-hub-{$area}: 200 with #gs-hub-{$area}", $p['status'] . ' ' . $p['error'] );
	gs109_assert( 0 === gs109pl_count( $xp, '//iframe|//frame|//object|//embed' ) && false === stripos( $p['body'], '<iframe' ), "embed-page-{$area}-noframe", 'no iframe / frame / object / embed' );
	$foreign = array();
	$srcs    = array_merge( gs109pl_attrs( $xp, '//script[@src]', 'src' ), gs109pl_attrs( $xp, '//link[contains(concat(" ",normalize-space(@rel)," ")," stylesheet ")]', 'href' ) );
	foreach ( $srcs as $u ) {
		$hh = wp_parse_url( $u, PHP_URL_HOST );
		$pp = wp_parse_url( $u, PHP_URL_PORT );
		if ( $hh && strtolower( $hh . ( $pp ? ':' . $pp : '' ) ) !== $bed ) {
			$foreign[] = $u;
		}
	}
	gs109_assert( ! $foreign && count( $srcs ) > 0, "embed-page-{$area}-local", 'every script src / stylesheet on ' . $bed . ' (' . count( $srcs ) . ' checked)', $foreign );
	$imgs = array();
	foreach ( gs109pl_attrs( $xp, '//div[@id="gs-hub-' . $area . '"]//img', 'src' ) as $u ) {
		if ( 0 !== strpos( $u, 'http://localhost:8919/' ) ) {
			$imgs[] = $u;
		}
	}
	gs109_assert( ! $imgs, "embed-page-{$area}-img", 'page images from the hub host only', $imgs );
	$js = gs109pl_attrs( $xp, '//script[@id="gend-society-hub-embed-js"]', 'src' );
	$cs = gs109pl_attrs( $xp, '//link[@id="gend-society-hub-embed-css"]', 'href' );
	$pj = gs109pl_attrs( $xp, '//script[@id="gend-society-plan-js"]', 'src' );
	gs109_assert( $js && 0 === strpos( $js[0], GEND_SOCIETY_URL . 'assets/gs-hub-embed.js?ver=' ) && $cs && 0 === strpos( $cs[0], GEND_SOCIETY_URL . 'assets/gs-hub-embed.css?ver=' ) && $pj && 0 === strpos( $pj[0], GEND_SOCIETY_URL . 'assets/gs-plan.js' ), "embed-page-{$area}-assets", 'gs-hub-embed.js/.css + gs-plan.js from the plugin (filemtime versions)', array( $js, $cs, $pj ) );
	$extra = (string) gs109pl_text( $xp, '//script[@id="gend-society-hub-embed-js-extra"]' );
	$cfg   = preg_match( '/var gendSocietyHubEmbed = (\{.*\});/s', $extra, $m ) ? json_decode( $m[1], true ) : null;
	$pex   = (string) gs109pl_text( $xp, '//script[@id="gend-society-plan-js-extra"]' );
	$pcfg  = preg_match( '/var gendSocietyPlan = (\{.*\});/s', $pex, $m2 ) ? json_decode( $m2[1], true ) : null;
	gs109_assert( is_array( $cfg ) && $area === ( $cfg['area'] ?? '' ) && is_array( $pcfg ) && 'hub' === ( $pcfg['refreshView'] ?? '' ) && $area === ( $pcfg['refreshArea'] ?? '' ), "embed-page-{$area}-config", 'localize: gendSocietyHubEmbed.area + gendSocietyPlan.refreshView hub / refreshArea' );
	$wrap = $xp->query( '//div[contains(concat(" ",normalize-space(@class)," ")," gend-society-hub ")]' );
	$in   = ( $wrap && $wrap->length ) ? $xp->query( './/script|.//style', $wrap->item( 0 ) )->length : -1;
	gs109_assert( 0 === $in, "embed-page-{$area}-noinline", 'page body: no <script> or <style>' );
	$new_js    = array_values( array_diff( $sig( $xp, 'script' ), $sig( $cx, 'script' ) ) );
	$new_style = array_values( array_diff( $sig( $xp, 'style' ), $sig( $cx, 'style' ) ) );
	sort( $new_js );
	gs109_assert( array( 'gend-society-hub-embed-js-extra', 'gend-society-plan-js-extra' ) === $new_js && array() === $new_style, "embed-page-{$area}-inline", 'inline blocks beyond a core screen: only the two localize data blocks', array( $new_js, $new_style ) );
	gs109_assert( false === strpos( $p['body'], $token ) && false === stripos( $extra . $pex, 'token' ), "embed-page-{$area}-notoken", 'raw token absent from the HTML and the localize data' );
}
$sub = gs109p_session( gs109p_user( 'subscriber' ) );
$sp  = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-hub-groups' ), $sub );
gs109_assert( false === strpos( $sp['body'], 'id="gs-hub-groups"' ), 'embed-subscriber', 'subscriber: no hub page', $sp['status'] );

// ── token nowhere ──────────────────────────────────────────────────────────────────────────────────────
$log = gs109p_httplog_since( $mark );
gs109_assert( '' !== $log && false !== strpos( $log, 'hub\/groups' ) && false === strpos( $log, $token ) && false === strpos( $all, $token ), 'embed-no-token', 'raw token absent from gs109-http.log (' . substr_count( $log, 'hub\/' ) . ' hub-area lines) and from every rendered fragment / page' );
gs109_assert( false !== strpos( $log, 'Bearer [redacted]' ), 'embed-log-redacted', 'log shows the bearer header redacted' );
