<?php
/**
 * Section upgrade-page (plan 109-08; PLAN-05, D12): the one upgrade page, admin.php?page=gs-feature-upgrade.
 *
 * standalone (fixture hub):
 *   - copy per state from the view-model: Upgrade "Available with <plan>" + one "Choose a plan on gend.me"
 *     button = gend.me's Change Plan page in a popup (109-21; no per-tier Choose, no launcher), After
 *     migration "Runs on your gend.me container after migration" + an outbound link to the hub
 *     (new tab, noopener, no Choose), Included links to the hub page; the Upgrade and After migration strings
 *     differ (the Plan & features card they were compared with is gone since 109-30; Included opens the dashboard)
 *   - revoked -> Reconnect; inherited -> Projects included; unknown area copy
 *   - (109-30b) the secondary button is "Your plan on the dashboard" (index.php#gs-mship-root, the dashboard
 *     membership card) and the page ends with "Back to the dashboard"; no Plan & features / Compare plans copy
 *   - the Refresh AJAX (gs_view=upgrade) returns #gs-feature-upgrade re-rendered after a plan change
 *   - real loopback page: no <iframe>, gs-plan.js/css local with refreshView upgrade, no inline script/style in
 *     the page body, no token; subscriber gets no page
 * hub / container: the page renders the 1.4.0 output for 28 inputs except the declared EXPECTED classes (D12
 *   iframe -> outbound link, LISTENER removal); no <iframe>, no gs_membership_changed listener in the output.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_output.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109p_setup();
	$par = gs109o_upgrade_parity();
	gs109_info( 'upgrade page renders compared: ' . $par['cases'] . ' (D12 replaced ' . $par['D12'] . ', LISTENER removed ' . $par['LISTENER'] . ')' );
	gs109_assert( $par['cases'] >= 28 && $par['D12'] > 0 && $par['LISTENER'] === $par['cases'], 'upgrade-parity-nontrivial', 'every 1.4.0 render had the listener and the learn-more inputs had the iframe' );
	gs109_assert( ! $par['diff'], 'upgrade-parity', gs109_mode() . ': upgrade page == 1.4.0 except D12 + LISTENER (' . $par['cases'] . ' renders)', implode( ' | ', array_slice( $par['diff'], 0, 3 ) ) );
	gs109_assert( 0 === $par['iframes_new'] && 0 === $par['listener_new'], 'upgrade-no-iframe', 'no <iframe> and no gs_membership_changed listener in any render' );
	$xp = gs109pl_xp( $par['sample'] );
	$a  = $xp->query( '//div[@class="gs-up-learn-more-embed"]/a' );
	gs109_assert( $a && 1 === $a->length && '_blank' === $a->item( 0 )->getAttribute( 'target' ) && 'noopener' === $a->item( 0 )->getAttribute( 'rel' ) && 'More about this upgrade' === trim( $a->item( 0 )->textContent ) && false === strpos( $a->item( 0 )->getAttribute( 'href' ), 'gdc_upgrade_embed' ), 'upgrade-learn-link', 'learn more: outbound link, new tab, noopener, same label, no embed flag' );
	gs109_assert( false === has_action( 'admin_enqueue_scripts', 'gend_society_feature_upgrade_standalone_enqueue' ) && false === has_filter( 'gend_society_plan_refresh_html', 'gend_society_feature_upgrade_refresh_fragment' ), 'upgrade-standalone-hooks-absent', 'standalone upgrade hooks not registered on ' . gs109_mode() );
	// No loopback page check here: on the single-site container bed every gend-society admin screen dies in
	// inc/group-embed.php (get_blog_option() is multisite-only; pre-existing, 109-08 deferred item). The in-process
	// renders above are the page callback's full output.
	return;
}

$fx = gs109pl_start( 'connected' );
if ( ! $fx || ! gs109_assert( function_exists( 'gend_society_feature_upgrade_render_standalone' ), 'upgrade-loaded', 'standalone upgrade renderer present' ) ) {
	return;
}
$token = $fx['token'];
$frag  = function ( $area, $vm ) {
	return gend_society_feature_upgrade_fragment( $area, $vm );
};

// ── connected ─────────────────────────────────────────────────────────────────────────────────────────
$vm   = gs109pl_vm( 'connected' );

$xp = gs109pl_xp( $frag( 'projects', $vm ) );
$up = gs109pl_text( $xp, '//*[@data-gs-up-copy="upgrade"]' );
gs109_assert_eq( 'upgrade', gs109pl_attrs( $xp, '//div[@data-gs-up-area="projects"]', 'data-gs-up-state' )[0] ?? '', 'upgrade-projects-state', 'projects: Upgrade state' );
gs109_assert_eq( 'Available with Social Connector', $up, 'upgrade-projects-copy', 'projects: "Available with Social Connector"' );
gs109_assert( gs109pl_popup_is( gs109pl_popup( $xp ), gs109pl_change_url( 'connected' ), 'Choose a plan on gend.me' ) && array() === gs109pl_choose( $xp ), 'upgrade-projects-choose', 'one "Choose a plan on gend.me" button: the Change Plan page, target _blank, rel noopener (gs-plan.js popup); no per-tier Choose', gs109pl_popup( $xp ) );
gs109_assert( false !== strpos( (string) gs109pl_text( $xp, '//div[@data-gs-up-area="projects"]' ), 'once this site is on Social Connector' ), 'upgrade-projects-names-plan', 'the copy still names the unlocking plan (Social Connector)' );
gs109_assert( 1 === gs109pl_count( $xp, '//button[@data-gs-plan-refresh]' ), 'upgrade-refresh-button', 'Refresh button (gs-plan.js)' );
// 109-30b: the plan lives on the dashboard membership card now (#gs-mship-root); no "Compare plans" / "Plan & features"
$gs109up_plan = admin_url( 'index.php' ) . '#gs-mship-root';
$gs109up_link = function ( $xp, $area ) {
	$out = array();
	foreach ( $xp->query( '//div[@data-gs-up-area="' . $area . '"]//a[contains(concat(" ",normalize-space(@class)," ")," button ") and not(@data-gs-plan-popup) and not(@data-gs-up-outbound)]' ) as $a ) {
		$out[] = trim( $a->textContent ) . ' -> ' . html_entity_decode( $a->getAttribute( 'href' ) );
	}
	return $out;
};
$gs109up_old = function ( $html ) {
	return false !== strpos( $html, 'Compare plans' ) || false !== strpos( $html, 'Plan &amp; features' ) || false !== strpos( $html, 'Plan & features' ) || false !== strpos( $html, 'gs-plan-compare' );
};
gs109_assert( array( 'Your plan on the dashboard -> ' . $gs109up_plan ) === $gs109up_link( $xp, 'projects' ) && ! $gs109up_old( $frag( 'projects', $vm ) ), 'upgrade-projects-plan-link', 'projects (Upgrade): "Your plan on the dashboard" -> the dashboard membership card; no Compare plans / #gs-plan-compare', $gs109up_link( $xp, 'projects' ) );

$xp = gs109pl_xp( $frag( 'write', $vm ) );
$am = gs109pl_text( $xp, '//*[@data-gs-up-copy="after_migration"]' );
gs109_assert_eq( 'Runs on your gend.me container after migration', $am, 'upgrade-write-copy', 'write: After migration copy' );
gs109_assert( $up !== $am, 'upgrade-copy-differs', 'Upgrade and After migration copy differ' );
$ob = $xp->query( '//a[@data-gs-up-outbound]' );
gs109_assert( 1 === $ob->length && 'http://localhost:8919/my-account/memberships/' === $ob->item( 0 )->getAttribute( 'href' ) && '_blank' === $ob->item( 0 )->getAttribute( 'target' ) && 'noopener' === $ob->item( 0 )->getAttribute( 'rel' ) && 'See container plans on gend.me' === trim( $ob->item( 0 )->textContent ), 'upgrade-write-outbound', 'After migration: "See container plans on gend.me" on the hub, new tab, noopener' );
gs109_assert( 0 === gs109pl_count( $xp, '//a[@data-gs-plan-checkout or @data-gs-plan-popup]' ), 'upgrade-write-nochoose', 'After migration: no Change Plan button (no popup)' );
gs109_assert_eq( 'Content', gs109pl_text( $xp, '//div[@data-gs-up-area="write"]/h2' ), 'upgrade-write-label', 'area label from the hub (Content)' );
gs109_assert( array( 'Your plan on the dashboard -> ' . $gs109up_plan ) === $gs109up_link( $xp, 'write' ) && ! $gs109up_old( $frag( 'write', $vm ) ), 'upgrade-write-plan-link', 'write (After migration): "Your plan on the dashboard" -> the dashboard membership card; no Plan & features', $gs109up_link( $xp, 'write' ) );

$xp = gs109pl_xp( $frag( 'groups', $vm ) );
$op = gs109pl_attrs( $xp, '//div[@data-gs-up-area="groups"]//a[contains(@class,"button-primary")]', 'href' );
gs109_assert( 'Included with your plan' === gs109pl_text( $xp, '//*[@data-gs-up-copy="included"]' ) && $op && html_entity_decode( $op[0] ) === admin_url( 'index.php' ), 'upgrade-groups-included', 'groups: Included, links to the dashboard (109-30: GenD > Groups is gone; the group is the Integration Hub card)', $op );

$xp = gs109pl_xp( $frag( 'nosuch', $vm ) );
gs109_assert_eq( 'unknown', gs109pl_attrs( $xp, '//div[@data-gs-up-area="nosuch"]', 'data-gs-up-state' )[0] ?? '', 'upgrade-unknown', 'unknown area: neutral copy' );
gs109_assert( array( 'Your plan on the dashboard -> ' . $gs109up_plan ) === $gs109up_link( $xp, 'nosuch' ) && ! $gs109up_old( $frag( 'nosuch', $vm ) ), 'upgrade-unknown-plan-link', 'unknown area: "Your plan on the dashboard" -> the dashboard membership card', $gs109up_link( $xp, 'nosuch' ) );

// ── store-owner: Social Connector still purchasable; inherited: projects included, nothing to buy ───────────
$vm = gs109pl_vm( 'store-owner' );
gs109_assert( gs109pl_popup_is( gs109pl_popup( gs109pl_xp( $frag( 'projects', $vm ) ) ), gs109pl_change_url( 'store-owner' ), 'Choose a plan on gend.me' ), 'upgrade-store-owner', 'store-owner: projects -> "Choose a plan on gend.me"' );
$vm = gs109pl_vm( 'inherited' );
$xp = gs109pl_xp( $frag( 'projects', $vm ) );
gs109_assert( 'Included with your plan' === gs109pl_text( $xp, '//*[@data-gs-up-copy="included"]' ) && 0 === gs109pl_count( $xp, '//a[@data-gs-plan-checkout or @data-gs-plan-popup]' ), 'upgrade-inherited', 'inherited: projects Included, no plan button' );
$vm = gs109pl_vm( 'revoked' );
$xp = gs109pl_xp( $frag( 'projects', $vm ) );
gs109_assert( 'revoked' === ( gs109pl_attrs( $xp, '//div[@data-gs-up-area="projects"]', 'data-gs-up-state' )[0] ?? '' ) && false !== strpos( (string) gs109pl_text( $xp, '//div[@id="gs-feature-upgrade"]' ), 'Reconnect to gend.me' ), 'upgrade-revoked', 'revoked: Reconnect' );

// ── Refresh AJAX (gs_view=upgrade) ───────────────────────────────────────────────────────────────────────
gs109_fixture_scenario( 'connected' );
gs109p_clear_caches();
gend_society_plan_view_model( true );
gs109_fixture_scenario( 'inherited' );
$r    = gs109p_call( 'gend_society_plan_refresh_ajax', 'POST', array(), array( 'nonce' => wp_create_nonce( 'gs_plan' ), 'gs_view' => 'upgrade', 'gs_area' => 'projects' ), true );
$html = is_array( $r['json'] ) ? (array) ( $r['json']['data']['html'] ?? array() ) : array();
$xp   = gs109pl_xp( (string) ( $html['gs-feature-upgrade'] ?? '' ) );
gs109_assert( ! empty( $r['json']['success'] ) && 'included' === ( gs109pl_attrs( $xp, '//div[@id="gs-feature-upgrade"]/div', 'data-gs-up-state' )[0] ?? '' ), 'upgrade-refresh', 'Refresh AJAX returns #gs-feature-upgrade re-rendered (Upgrade -> Included after the plan change)', array_keys( $html ) );
gs109_fixture_scenario( 'connected' );
gs109p_clear_caches();

// ── real page over loopback ─────────────────────────────────────────────────────────────────────────────
$sess = gs109p_session( gs109p_admin_id() );
$bed  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
foreach ( array( 'projects' => 'upgrade', 'write' => 'after_migration', 'store' => 'after_migration' ) as $area => $state ) {
	$p  = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-feature-upgrade&area=' . $area ), $sess );
	$xp = gs109pl_xp_page( $p['body'] );
	gs109_assert( 200 === $p['status'] && $state === ( gs109pl_attrs( $xp, '//div[@id="gs-feature-upgrade"]/div', 'data-gs-up-state' )[0] ?? '' ), "upgrade-page-{$area}", "admin.php?page=gs-feature-upgrade&area={$area}: 200, {$state}", $p['status'] . ' ' . $p['error'] );
	gs109_assert( false === stripos( $p['body'], '<iframe' ) && 0 === gs109pl_count( $xp, '//iframe' ), "upgrade-page-{$area}-noiframe", 'no <iframe>' );
	$back = gs109pl_attrs( $xp, '//p[contains(@class,"gs-up-back")]/a', 'href' );
	gs109_assert( 'Back to the dashboard' === gs109pl_text( $xp, '//p[contains(@class,"gs-up-back")]/a' ) && $back && html_entity_decode( $back[0] ) === admin_url( 'index.php' ) && ! $gs109up_old( $p['body'] ), "upgrade-page-{$area}-back", 'page: "Back to the dashboard" -> index.php; no Plan & features / Compare plans copy (109-30b)', $back );
	$wrap = $xp->query( '//div[contains(concat(" ",normalize-space(@class)," ")," gend-society-upgrade ")]' );
	gs109_assert( $wrap && 1 === $wrap->length && 0 === $xp->query( './/script|.//style', $wrap->item( 0 ) )->length && false === strpos( $p['body'], 'gs-up-shell' ), "upgrade-page-{$area}-noinline", 'page body: no <script>/<style>, not the 1.4.0 layout' );
	$pj   = gs109pl_attrs( $xp, '//script[@id="gend-society-plan-js"]', 'src' );
	$pex  = (string) gs109pl_text( $xp, '//script[@id="gend-society-plan-js-extra"]' );
	$pcfg = preg_match( '/var gendSocietyPlan = (\{.*\});/s', $pex, $m ) ? json_decode( $m[1], true ) : null;
	gs109_assert( $pj && 0 === strpos( $pj[0], GEND_SOCIETY_URL . 'assets/gs-plan.js' ) && is_array( $pcfg ) && 'upgrade' === ( $pcfg['refreshView'] ?? '' ) && $area === ( $pcfg['refreshArea'] ?? '' ) && 'http://localhost:8919' === ( $pcfg['hubOrigin'] ?? '' ), "upgrade-page-{$area}-assets", 'gs-plan.js from the plugin, refreshView upgrade / refreshArea ' . $area );
	$foreign = array();
	foreach ( array_merge( gs109pl_attrs( $xp, '//script[@src]', 'src' ), gs109pl_attrs( $xp, '//link[contains(concat(" ",normalize-space(@rel)," ")," stylesheet ")]', 'href' ) ) as $u ) {
		$hh = wp_parse_url( $u, PHP_URL_HOST );
		$pp = wp_parse_url( $u, PHP_URL_PORT );
		if ( $hh && strtolower( $hh . ( $pp ? ':' . $pp : '' ) ) !== $bed ) {
			$foreign[] = $u;
		}
	}
	gs109_assert( ! $foreign, "upgrade-page-{$area}-local", 'scripts / stylesheets on the bed only', $foreign );
	gs109_assert( false === strpos( $p['body'], $token ) && false === strpos( $p['body'], 'gs_membership_changed' ), "upgrade-page-{$area}-clean", 'no token, no legacy listener' );
}
$sub = gs109p_session( gs109p_user( 'subscriber' ) );
$sp  = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-feature-upgrade&area=projects' ), $sub );
gs109_assert( false === strpos( $sp['body'], 'id="gs-feature-upgrade"' ), 'upgrade-subscriber', 'subscriber: no upgrade copy / Choose button', $sp['status'] );
