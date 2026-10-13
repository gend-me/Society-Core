<?php
/**
 * Section card (plan 109-06; PLAN-01, PLAN-03, PLAN-07): server render of GenD > Plan & features per fixture
 * scenario, exact copy strings.
 *
 *   connected    "Connected - Free - No renewal - Active"; Feature Access: exactly groups + feature-suite
 *                Included (D11), projects Upgrade "Available with Social Connector", the rest After migration;
 *                Choose buttons for all three tiers on the admin-post launcher (target _blank, rel opener);
 *                owner line (D3)
 *   store-owner  "<plan> - <price_label> - Renews <date> - Active" from next_renewal; only Social Connector
 *   inherited    banner "Your group <group> already has <plan> - you inherit it."; no Choose button at or
 *                below the inherited rank (fixture: rank 3 -> none; injected rank 1 -> only ranks 2-3, even when
 *                the hub flags rank 1 as purchasable)
 *   processing   "Payment processing - this can take a minute." + Refresh
 *   revoked      reconnect copy, no areas / comparison
 *   unreachable  stale data + "Could not reach gend.me"
 *   not paired   connect card, zero requests
 *   refresh      the Refresh AJAX answers the same four fragments, re-rendered after a plan change
 *   escaping     hub strings with markup are printed as text
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_plan_fragments' ) && ! function_exists( 'gend_society_plan_page_render' ), 'card-absent', 'Plan & features page not loaded on ' . gs109_mode() );
	return;
}

$fx = gs109pl_start( 'connected' );
if ( ! $fx ) {
	return;
}
$token = $fx['token'];
$ids   = array( 'gs-plan-inherit', 'gs-plan-membership', 'gs-plan-areas', 'gs-plan-compare' );
$all   = '';

// ── connected ──────────────────────────────────────────────────────────────────────────────────────────
$vm = gs109pl_vm( 'connected' );
gs109_assert_eq( 'ok', $vm['state'], 'card-connected-state', 'connected: view-model state ok' );
$frag = gend_society_plan_fragments( $vm );
gs109_assert_eq( $ids, array_keys( $frag ), 'card-fragment-ids', 'four fragments in page order' );
$html = implode( '', $frag );
$all .= $html;
$xp   = gs109pl_xp( $html );
foreach ( $ids as $id ) {
	gs109_assert( 1 === gs109pl_count( $xp, '//*[@id="' . $id . '"]' ), 'card-root-' . $id, "fragment root #{$id} present once" );
}
gs109_assert_eq( 'Connected - Free - No renewal - Active', gs109pl_text( $xp, '//div[@class="gs-plan-summary"]' ), 'card-connected-summary', 'Connected - Free - No renewal - Active' );
gs109_assert_eq( 'Active', gs109pl_text( $xp, '//*[@data-gs-plan-field="status"]' ), 'card-connected-badge', 'status badge Active' );
gs109_assert_eq( array( 'groups', 'feature-suite' ), gs109pl_area_group( $xp, 'included' ), 'card-connected-included', 'Included: exactly Groups + Feature Suite (D11)' );
gs109_assert_eq( array( 'projects' ), gs109pl_area_group( $xp, 'upgrade' ), 'card-connected-upgrade', 'Upgrade: Projects' );
gs109_assert_eq( array( 'app', 'write', 'store', 'social', 'reward', 'hosting' ), gs109pl_area_group( $xp, 'after_migration' ), 'card-connected-after', 'After migration: the six container_only areas' );
gs109_assert_eq( 'Available with Social Connector', gs109pl_text( $xp, '//li[@data-gs-plan-area="projects"]/a[@class="gs-plan-area__state"]' ), 'card-upgrade-copy', 'Projects: "Available with Social Connector"' );
$up = gs109pl_attrs( $xp, '//li[@data-gs-plan-area="projects"]/a[@class="gs-plan-area__state"]', 'href' );
gs109_assert( $up && html_entity_decode( $up[0] ) === admin_url( 'admin.php?page=gs-feature-upgrade&area=projects' ), 'card-upgrade-link', 'Upgrade row links to gs-feature-upgrade&area=projects', $up );
gs109_assert_eq( 'Included - open it here', gs109pl_text( $xp, '//li[@data-gs-plan-area="groups"]/a[@class="gs-plan-area__state"]' ), 'card-included-copy', 'Groups: "Included - open it here"' );
$inc = gs109pl_attrs( $xp, '//li[@data-gs-plan-area="groups"]/a[@class="gs-plan-area__state"]', 'href' );
gs109_assert( $inc && html_entity_decode( $inc[0] ) === admin_url( 'admin.php?page=gs-hub-groups' ), 'card-included-link', 'Included row links to its admin_url (gs-hub-groups)', $inc );
$after_ok = true;
foreach ( $xp->query( '//ul[@data-gs-plan-group="after_migration"]/li' ) as $li ) {
	$after_ok = $after_ok && false !== strpos( $li->textContent, 'Runs on your gend.me container after migration' ) && 0 === $li->getElementsByTagName( 'a' )->length;
}
gs109_assert( $after_ok, 'card-after-copy', 'After migration rows: "Runs on your gend.me container after migration", no link' );
$ch = gs109pl_choose( $xp );
gs109_assert_eq( array( 21, 22, 23 ), array_keys( $ch ), 'card-connected-choose', 'Choose buttons for plans 21, 22, 23' );
$launch_ok = true;
foreach ( $ch as $pid => $c ) {
	$launch_ok = $launch_ok && (string) $pid === gs109pl_launcher_plan( $c['href'] ) && '_blank' === $c['target'] && 'opener' === $c['rel'] && 0 === strpos( $c['text'], 'Choose ' );
}
gs109_assert( $launch_ok, 'card-choose-launcher', 'each Choose is a target=_blank rel=opener link to admin-post gend_society_plan_checkout for its plan (works without JS)', $ch );
gs109_assert_eq( 'Choose Store Owner', $ch[22]['text'] ?? '', 'card-choose-copy', '"Choose Store Owner"' );
gs109_assert_eq(
	"Plan changes are paid from GS109 Owner's gend.me account. They need to be signed in to gend.me in the window that opens.",
	gs109pl_text( $xp, '//p[@data-gs-plan-field="owner"]' ),
	'card-owner-line',
	'owner line (D3)'
);
gs109_assert( 1 === gs109pl_count( $xp, '//div[@id="gs-plan-inherit"][@hidden]' ), 'card-connected-no-banner', 'no inherit banner (hidden placeholder)' );
gs109_assert( 0 === gs109pl_count( $xp, '//*[@data-gs-plan-processing]' ), 'card-connected-no-processing', 'no processing note' );

// ── store-owner ────────────────────────────────────────────────────────────────────────────────────────
$vm   = gs109pl_vm( 'store-owner' );
$html = implode( '', gend_society_plan_fragments( $vm ) );
$all .= $html;
$xp   = gs109pl_xp( $html );
$exp  = 'Store Owner - $29.99 / month - Renews ' . gs109pl_date( '2026-11-12' ) . ' - Active';
gs109_assert_eq( $exp, gs109pl_text( $xp, '//div[@class="gs-plan-summary"]' ), 'card-store-summary', 'store-owner: plan - price - Renews <next_renewal> - Active' );
gs109_assert_eq( array( 23 ), array_keys( gs109pl_choose( $xp ) ), 'card-store-choose', 'store-owner: only Choose Social Connector' );
gs109_assert_eq( array( 'groups', 'feature-suite' ), gs109pl_area_group( $xp, 'included' ), 'card-store-included', 'store-owner: Groups + Feature Suite included' );

// ── inherited (fixture: group has Social Connector, rank 3) ─────────────────────────────────────────────
$vm   = gs109pl_vm( 'inherited' );
$html = implode( '', gend_society_plan_fragments( $vm ) );
$all .= $html;
$xp   = gs109pl_xp( $html );
gs109_assert_eq( 'Your group GS109 Business Group already has Social Connector - you inherit it.', gs109pl_text( $xp, '//div[@id="gs-plan-inherit"]' ), 'card-inherit-banner', 'inherit banner copy' );
gs109_assert( 0 === gs109pl_count( $xp, '//div[@id="gs-plan-inherit"][@hidden]' ), 'card-inherit-visible', 'banner visible' );
gs109_assert_eq( array(), array_keys( gs109pl_choose( $xp ) ), 'card-inherit-no-choose', 'inherited rank 3: no Choose button' );
gs109_assert_eq( array( 'groups', 'feature-suite', 'projects' ), gs109pl_area_group( $xp, 'included' ), 'card-inherit-included', 'inherited: Projects included too' );

// ── inherited content-builder (rank 1), hub flags every tier purchasable: local guard hides rank <= 1 ───
$mem                            = gs109_fixture_json( 'inherited', 'membership.json' );
$mem['dashboard_plan']          = array_merge( $mem['dashboard_plan'], array( 'id' => 21, 'slug' => 'content-builder', 'name' => 'Content Builder', 'amount' => 14.99 ) );
$mem['inherited_from']          = array_merge( $mem['inherited_from'], array( 'plan_id' => 21, 'plan_slug' => 'content-builder', 'plan_name' => 'Content Builder' ) );
$mem['checkout_allowed_plan_ids'] = array( 21, 22, 23 );
$cat                            = gs109_fixture_json( 'inherited', 'catalog.json' );
foreach ( $cat['tiers'] as &$t ) {
	$t['is_inherited']     = 'content-builder' === $t['slug'];
	$t['checkout_allowed'] = true;
}
unset( $t );
unset( $mem['rev'], $cat['rev'] );
$o1   = gs109p_inject( 'membership', 200, $mem );
$o2   = gs109p_inject( 'catalog', 200, $cat );
$vm   = gs109pl_vm( 'inherited' );
$xp   = gs109pl_xp( implode( '', gend_society_plan_fragments( $vm ) ) );
$o1();
$o2();
gs109_assert_eq( 'Your group GS109 Business Group already has Content Builder - you inherit it.', gs109pl_text( $xp, '//div[@id="gs-plan-inherit"]' ), 'card-inherit-low-banner', 'inherited Content Builder banner' );
gs109_assert_eq( array( 22, 23 ), array_keys( gs109pl_choose( $xp ) ), 'card-inherit-low-choose', 'inherited rank 1: Choose only for ranks 2-3 (rank 1 hidden although the hub allowed it)' );
gs109_assert( 1 === gs109pl_count( $xp, '//th[@data-gs-plan-tier="content-builder"][contains(@class,"is-inherited")]' ), 'card-inherit-low-column', 'Content Builder column marked inherited' );

// ── processing ─────────────────────────────────────────────────────────────────────────────────────────
$vm   = gs109pl_vm( 'processing' );
$html = implode( '', gend_society_plan_fragments( $vm ) );
$all .= $html;
$xp   = gs109pl_xp( $html );
gs109_assert( 0 === strpos( (string) gs109pl_text( $xp, '//*[@data-gs-plan-processing]' ), 'Payment processing - this can take a minute.' ), 'card-processing-copy', '"Payment processing - this can take a minute."', gs109pl_text( $xp, '//*[@data-gs-plan-processing]' ) );
gs109_assert( 1 === gs109pl_count( $xp, '//*[@data-gs-plan-processing]//button[@data-gs-plan-refresh]' ), 'card-processing-refresh', 'processing note carries a Refresh button' );
gs109_assert_eq( 'Connected - Free - No renewal - Active', gs109pl_text( $xp, '//div[@class="gs-plan-summary"]' ), 'card-processing-plan', 'processing: plan still Connected' );

// ── revoked ────────────────────────────────────────────────────────────────────────────────────────────
$vm   = gs109pl_vm( 'revoked' );
$html = implode( '', gend_society_plan_fragments( $vm ) );
$xp   = gs109pl_xp( $html );
gs109_assert_eq( 'revoked', $vm['state'], 'card-revoked-state', 'revoked: state revoked' );
$rl = gs109pl_attrs( $xp, '//div[@id="gs-plan-membership"]//a', 'href' );
gs109_assert( false !== strpos( $html, 'Reconnect to gend.me' ) && $rl && html_entity_decode( $rl[0] ) === gend_society_connect_url(), 'card-revoked-copy', 'revoked: reconnect copy linking the Connect page', $rl );
gs109_assert( 1 === gs109pl_count( $xp, '//div[@id="gs-plan-areas"][@hidden]' ) && 1 === gs109pl_count( $xp, '//div[@id="gs-plan-compare"][@hidden]' ) && array() === gs109pl_choose( $xp ), 'card-revoked-empty', 'revoked: no areas, no comparison, no Choose' );

// ── unreachable (stale payloads) ───────────────────────────────────────────────────────────────────────
gs109pl_vm( 'connected' );
$o1 = gs109p_inject( 'features', 500, array( 'code' => 'boom' ) );
$o2 = gs109p_inject( 'membership', 500, array( 'code' => 'boom' ) );
$vm = gend_society_plan_view_model( true );
$o1();
$o2();
$xp = gs109pl_xp( implode( '', gend_society_plan_fragments( $vm ) ) );
gs109_assert( ! empty( $vm['stale'] ) && 0 === strpos( (string) gs109pl_text( $xp, '//div[contains(@class,"gs-plan-note--warning")]' ), 'Could not reach gend.me.' ), 'card-unreachable-note', 'unreachable: "Could not reach gend.me" note', gs109pl_text( $xp, '//div[contains(@class,"gs-plan-note--warning")]' ) );
gs109_assert_eq( 'Connected - Free - No renewal - Active', gs109pl_text( $xp, '//div[@class="gs-plan-summary"]' ), 'card-unreachable-stale', 'unreachable: last known plan still shown' );

// ── refresh AJAX answers the same fragments, re-rendered ──────────────────────────────────────────────
gs109pl_vm( 'connected' );
gs109_fixture_scenario( 'store-owner' ); // caches still hold Connected
$r = gs109p_call( 'gend_society_plan_refresh_ajax', 'POST', array(), array( 'nonce' => wp_create_nonce( 'gs_plan' ) ), true );
$h = is_array( $r['json'] ) && ! empty( $r['json']['success'] ) ? (array) $r['json']['data']['html'] : array();
gs109_assert_eq( $ids, array_keys( $h ), 'card-refresh-ids', 'Refresh AJAX html: the four fragment ids' );
$xr = gs109pl_xp( (string) ( $h['gs-plan-membership'] ?? '' ) );
gs109_assert( 0 === strpos( (string) gs109pl_text( $xr, '//div[@class="gs-plan-summary"]' ), 'Store Owner - ' ), 'card-refresh-rerender', 'after the plan change the Refresh AJAX card reads Store Owner', gs109pl_text( $xr, '//div[@class="gs-plan-summary"]' ) );
$vm = gend_society_plan_view_model();
gs109_assert( ( $h['gs-plan-areas'] ?? null ) === gend_society_plan_render_areas_card( $vm ) && ( $h['gs-plan-compare'] ?? null ) === gend_society_plan_render_compare( $vm ), 'card-refresh-same', 'AJAX fragments equal the page render functions' );

// ── full page render (as admin) ────────────────────────────────────────────────────────────────────────
$p  = gs109p_call( 'gend_society_plan_page_render', 'GET' );
$xp = gs109pl_xp( $p['output'] );
gs109_assert( 1 === gs109pl_count( $xp, '//div[contains(@class,"gend-society-plan")]//button[@data-gs-plan-refresh]' ), 'card-page-refresh', 'page: Refresh button' );
gs109_assert_eq( 'Your browser blocked the checkout window. Open checkout in a new tab', gs109pl_text( $xp, '//div[@id="gs-plan-blocked"]' ), 'card-page-blocked-copy', 'blocked-popup notice copy (hidden until needed)' );
gs109_assert( 1 === gs109pl_count( $xp, '//div[@id="gs-plan-blocked"][@hidden]//a[@data-gs-plan-blocked-link][@target="_blank"][@rel="opener"]' ), 'card-page-blocked-link', 'blocked notice hidden, its link opens a new tab keeping the opener' );
gs109_assert( 0 === gs109pl_count( $xp, '//iframe' ) && false === stripos( $p['output'], '<script' ) && false === stripos( $p['output'], '<style' ), 'card-page-no-inline', 'page body: no iframe, no inline script or style' );
$all .= $p['output'];
gs109_assert( false === strpos( $all, $token ), 'card-no-token', 'install token absent from every rendered card' );

// ── subscriber cannot see it ───────────────────────────────────────────────────────────────────────────
wp_set_current_user( gs109p_user( 'subscriber' ) );
$s = gs109p_call( 'gend_society_plan_page_render', 'GET' );
gs109_assert( 'die' === $s['kind'] && 403 === $s['status'], 'card-subscriber', 'subscriber: 403', $s['kind'] . ' ' . $s['status'] );
wp_set_current_user( gs109p_admin_id() );

// ── escaping: hub strings with markup are text ────────────────────────────────────────────────────────
$mem                         = gs109_fixture_json( 'connected', 'membership.json' );
$mem['owner']['display_name'] = '<script>alert(1)</script>Eve';
$feat                        = gs109_fixture_json( 'connected', 'features.json' );
$feat['areas'][0]['label']   = '"><img src=x onerror=alert(2)>Groups';
unset( $mem['rev'], $feat['rev'] );
$o1   = gs109p_inject( 'membership', 200, $mem );
$o2   = gs109p_inject( 'features', 200, $feat );
$vm   = gs109pl_vm( 'connected' );
$html = implode( '', gend_society_plan_fragments( $vm ) );
$o1();
$o2();
gs109_assert( false === stripos( $html, '<script' ) && false === stripos( $html, '<img' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;Eve' ), 'card-escaping', 'owner name and area label with markup are escaped' );

// ── not paired: connect card, no request ───────────────────────────────────────────────────────────────
$saved = get_option( 'gend_society_install_token', '' );
update_option( 'gend_society_install_token', '', false );
gs109_http_record();
$p  = gs109p_call( 'gend_society_plan_page_render', 'GET' );
$xp = gs109pl_xp( $p['output'] );
gs109_assert( 'Connect to gend.me to see plans' === gs109pl_text( $xp, '//div[@id="gs-plan-membership"]/h2' ) && 1 === gs109pl_count( $xp, '//div[@id="gs-plan-membership"]//a[@href="' . esc_url( gend_society_connect_url() ) . '"]' ), 'card-notpaired', 'not paired: "Connect to gend.me to see plans" linking the Connect page' );
gs109_assert_eq( array(), gs109_http_log(), 'card-notpaired-nohttp', 'not paired: zero HTTP requests' );
update_option( 'gend_society_install_token', $saved, false );
