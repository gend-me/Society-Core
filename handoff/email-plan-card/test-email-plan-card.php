<?php
/**
 * Email hosting plan card: view + render tests (wp eval-file, any site; never shipped: handoff/ is excluded).
 *
 *   wp eval-file handoff/email-plan-card/test-email-plan-card.php --user=<super admin>
 *
 * Prints "PASS: ..." / "FAIL: ..." lines and a final "EMAIL-PLAN-CARD: ALL PASS (n)" or "EMAIL-PLAN-CARD: FAILURES n".
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['gsem_t'] = array( 'pass' => 0, 'fail' => 0 );

function gsem_ok( $cond, $name ) {
	$GLOBALS['gsem_t'][ $cond ? 'pass' : 'fail' ]++;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $name . "\n";
}

function gsem_xp( $html ) {
	$doc = new DOMDocument();
	$old = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8"?><div id="gsem-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $old );
	return new DOMXPath( $doc );
}

function gsem_text( DOMXPath $xp, $q ) {
	$n = $xp->query( $q );
	return $n && $n->length ? trim( preg_replace( '/\s+/', ' ', $n->item( 0 )->textContent ) ) : null;
}

function gsem_count( DOMXPath $xp, $q ) {
	$n = $xp->query( $q );
	return $n ? $n->length : -1;
}

function gsem_attr( DOMXPath $xp, $q, $a ) {
	$n = $xp->query( $q );
	return $n && $n->length ? (string) $n->item( 0 )->getAttribute( $a ) : null;
}

function gsem_render( array $args ) {
	ob_start();
	gend_society_render_email_plan_card( $args );
	return (string) ob_get_clean();
}

$exists = function_exists( 'gend_society_email_plan_view' ) && function_exists( 'gend_society_render_email_plan_card' );
gsem_ok( $exists, 'api: gend_society_email_plan_view() + gend_society_render_email_plan_card() exist' );
if ( ! $exists ) {
	echo 'EMAIL-PLAN-CARD: FAILURES ' . $GLOBALS['gsem_t']['fail'] . "\n";
	return;
}

$plans = array(
	array( 'id' => 46, 'kind' => 'storage', 'name' => 'Micro Email Storage', 'n' => 5 ),
	array( 'id' => 47, 'kind' => 'storage', 'name' => 'Starter Email Storage', 'n' => 25 ),
	array( 'id' => 51, 'kind' => 'sending', 'name' => '1,000 Emails / month', 'n' => 1000 ),
);
$embed = 'http://hub.example/my-account/memberships/?gdc_open_modal=7&gdc_open_emails=1';
$base  = array(
	'mode'       => 'hub',
	'plans'      => $plans,
	'current'    => array(),
	'mail_state' => null,
	'free'       => array( 'storage_gb' => 1, 'sends' => 300 ),
	'upgrade'    => array( 'data-embed-url' => $embed ),
	'manage_url' => 'http://hub.example/wp-admin/admin.php?page=talk-flows',
);

// 1. Hub, plans configured, nothing bought yet.
$v  = gend_society_email_plan_view( $base );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
$H  = '//div[contains(concat(" ",@class," ")," gs-em-hero ")]';
gsem_ok( 1 === gsem_count( $xp, $H . '[contains(concat(" ",@class," ")," gs-bk-hero ")]' ), 'hub/no-plan: one hero, reusing the Backups gs-bk-hero class' );
gsem_ok( 'No Active Plan' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge")]' ), 'hub/no-plan: badge "No Active Plan"' );
gsem_ok( 1 === gsem_count( $xp, $H . '//span[contains(@class,"gs-bk-badge") and contains(@class,"is-warn")]' ), 'hub/no-plan: warn badge like Backups' );
gsem_ok( 'Email hosting' === gsem_text( $xp, $H . '//h2[@class="gs-bk-title"]' ), 'hub/no-plan: title "Email hosting"' );
$cta = $H . '//button[@data-gs-upgrade-open and contains(@class,"gs-bk-btn--cta")]';
gsem_ok( 'Choose a plan' === gsem_text( $xp, $cta ), 'hub/no-plan: CTA "Choose a plan"' );
gsem_ok( 'emails' === gsem_attr( $xp, $cta, 'data-resource' ) && $embed === gsem_attr( $xp, $cta, 'data-embed-url' ), 'hub/no-plan: CTA opens the shared upgrade popup on the Emails picker (gdc_open_emails=1)' );
gsem_ok( 2 === gsem_count( $xp, $H . '//button[@data-gs-upgrade-open and @data-gs-em-kind]' ), 'hub/no-plan: two selection tiles (storage + sending) that open the same popup' );
gsem_ok( 'Free (1 GB)' === gsem_text( $xp, $H . '//*[@data-gs-em-kind="storage"]//*[contains(@class,"gs-em-sel__v")]' ), 'hub/no-plan: storage tile shows the free allowance' );
gsem_ok( 'Free (300 emails a month)' === gsem_text( $xp, $H . '//*[@data-gs-em-kind="sending"]//*[contains(@class,"gs-em-sel__v")]' ), 'hub/no-plan: sending tile shows the free allowance' );
gsem_ok( 1 === gsem_count( $xp, $H . '//a[contains(@class,"gs-bk-btn") and contains(@href,"page=talk-flows")]' ), 'hub/no-plan dashboard: "Manage email" goes to Talk Flows' );
gsem_ok( 0 === gsem_count( $xp, '//*[@data-gs-em-meter]' ), 'hub/no-plan: no usage meters when the payload has no usage' );

// 2. Hub, a storage plan and a sending plan on the membership.
$v  = gend_society_email_plan_view( array_merge( $base, array( 'current' => array( 'storage' => array( 'id' => 47, 'name' => 'Starter Email Storage' ), 'sending' => array( 'id' => 51, 'name' => '1,000 Emails / month' ) ) ) ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 'Active: Starter Email Storage + 1,000 Emails / month' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge")]' ), 'hub/active: badge names both plans' );
gsem_ok( 0 === gsem_count( $xp, $H . '//span[contains(@class,"gs-bk-badge") and (contains(@class,"is-warn") or contains(@class,"is-neutral"))]' ), 'hub/active: green badge' );
gsem_ok( 'Change plan' === gsem_text( $xp, $cta ), 'hub/active: CTA "Change plan"' );
gsem_ok( 'Starter Email Storage' === gsem_text( $xp, $H . '//*[@data-gs-em-kind="storage"]//*[contains(@class,"gs-em-sel__v")]' ), 'hub/active: storage tile = current plan' );
gsem_ok( 1 === gsem_count( $xp, $H . '//*[@data-gs-em-kind="sending" and contains(@class,"is-current")]' ), 'hub/active: sending tile marked current' );

// 3. Hub, no Emails plans configured: same gating as Backups.
$v  = gend_society_email_plan_view( array_merge( $base, array( 'plans' => array() ) ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 'No Email Plans Configured' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge") and contains(@class,"is-neutral")]' ), 'hub/unconfigured: neutral "No Email Plans Configured"' );
gsem_ok( 0 === gsem_count( $xp, '//*[@data-gs-upgrade-open]' ), 'hub/unconfigured: no plan buttons' );

// 4. Usage from the payload (hub gend_mail_state shape).
$st = array( 'limit' => 300, 'used' => 120, 'storage_gb' => 1.0, 'storage_used_gb' => 0.25, 'free' => true, 'plans' => array() );
$v  = gend_society_email_plan_view( array_merge( $base, array( 'mail_state' => $st ) ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 2 === gsem_count( $xp, '//*[@data-gs-em-meter]' ), 'usage: two meters (sent this month, mailbox storage)' );
gsem_ok( '40' === gsem_attr( $xp, '//*[@data-gs-em-meter="sending"]//*[@data-gs-em-bar]', 'data-pct' ), 'usage: sending bar 120/300 = 40%' );
gsem_ok( '25' === gsem_attr( $xp, '//*[@data-gs-em-meter="storage"]//*[@data-gs-em-bar]', 'data-pct' ), 'usage: storage bar 0.25/1 GB = 25%' );
gsem_ok( 1 === gsem_count( $xp, $H . '/div[contains(@class,"gs-em-sels")]/div[@data-gs-em-col="storage"]/*[@data-gs-em-kind="storage"]/*[@data-gs-em-meter="storage"]' ) && 1 === gsem_count( $xp, $H . '/div[contains(@class,"gs-em-sels")]/div[@data-gs-em-col="sending"]/*[@data-gs-em-kind="sending"]/*[@data-gs-em-meter="sending"]' ), 'usage: each meter sits inside its own tile box (under the label + plan value)' );
gsem_ok( 0 === gsem_count( $xp, '//*[@data-gs-em-kind]//div' ) && 1 === gsem_count( $xp, '//*[@data-gs-em-kind="sending"]/*[contains(@class,"gs-em-sel__v")]/following-sibling::*[@data-gs-em-meter="sending"]' ), 'usage: meter markup inside the tile is phrasing content (spans only), after the plan value' );
gsem_ok( 2 === gsem_count( $xp, $H . '/div[contains(@class,"gs-em-sels")]/div[contains(@class,"gs-em-col")]' ) && 0 === gsem_count( $xp, $H . '/div[contains(@class,"gs-bk-main")]//*[contains(@class,"gs-em-sels")]' ), 'layout: tiles row spans the card under the header row (outside .gs-bk-main)' );
gsem_ok( '120' === gsem_attr( $xp, '//*[@data-gs-em-meter="sending"]//*[@data-gs-em-count]', 'data-value' ) && '120' === gsem_text( $xp, '//*[@data-gs-em-meter="sending"]//*[@data-gs-em-count]' ), 'usage: count-up target = real value, printed final (no-JS correct)' );
$st2 = array_merge( $st, array( 'used' => 900 ) );
$v   = gend_society_email_plan_view( array_merge( $base, array( 'mail_state' => $st2 ) ) );
$xp  = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( '100' === gsem_attr( $xp, '//*[@data-gs-em-meter="sending"]//*[@data-gs-em-bar]', 'data-pct' ) && 1 === gsem_count( $xp, '//*[@data-gs-em-meter="sending" and contains(@class,"is-over")]' ), 'usage: over the allowance caps at 100% and flags is-over' );

// 5. Standalone, not connected.
$v  = gend_society_email_plan_view( array( 'mode' => 'standalone', 'paired' => false ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 'Not connected' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge")]' ), 'standalone/unpaired: "Not connected"' );
gsem_ok( 0 === gsem_count( $xp, '//*[@data-gs-upgrade-open or @data-gs-popup]' ) && 0 === gsem_count( $xp, '//iframe' ), 'standalone/unpaired: no plan buttons, no popup, no iframe' );

// 6. Standalone, paired: "After migration" + Learn more (membership page, new tab), like Backups (D33).
$v  = gend_society_email_plan_view( array( 'mode' => 'standalone', 'paired' => true, 'learn_url' => 'https://gend.me/my-account/memberships/' ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 'After migration' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge")]' ), 'standalone/paired: "After migration"' );
gsem_ok( 'https://gend.me/my-account/memberships/' === gsem_attr( $xp, $H . '//a[contains(.,"Learn more")]', 'href' ) && '_blank' === gsem_attr( $xp, $H . '//a[contains(.,"Learn more")]', 'target' ), 'standalone/paired: Learn more opens the membership page in a new tab' );
gsem_ok( 0 === gsem_count( $xp, '//*[@data-gs-upgrade-open]' ), 'standalone/paired: no in-page popup' );

// 7. Container: hub not same-origin, no plans list locally, mail state + upgrade link stored by the relay.
$v  = gend_society_email_plan_view( array( 'mode' => 'container', 'plans' => array(), 'mail_state' => array_merge( $st, array( 'free' => false, 'plans' => array( 47 ) ) ), 'choose_url' => 'https://gend.me/my-account/memberships/?gdc_open_modal=7&gdc_open_emails=1' ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 'Active' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge")]' ), 'container: plan state from the stored mail-service payload' );
gsem_ok( 'https://gend.me/my-account/memberships/?gdc_open_modal=7&gdc_open_emails=1' === gsem_attr( $xp, $H . '//a[@data-gs-popup and contains(@class,"gs-bk-btn--cta")]', 'href' ), 'container: Change plan = the hub upgrade link from the payload (popup)' );
gsem_ok( 2 === gsem_count( $xp, '//*[@data-gs-em-meter]' ), 'container: usage meters from the payload' );
$v  = gend_society_email_plan_view( array( 'mode' => 'container', 'plans' => array(), 'mail_state' => null ) );
$xp = gsem_xp( gsem_render( array( 'context' => 'dashboard', 'view' => $v ) ) );
gsem_ok( 'No Email Plans Configured' === gsem_text( $xp, $H . '//span[contains(@class,"gs-bk-badge")]' ) && 0 === gsem_count( $xp, '//*[@data-gs-upgrade-open or @data-gs-popup]' ), 'container without payload: same as Backups (No ... Plans Configured, no buttons)' );

// 8. Talk Flows context: carries its own copy of the gs-bk CSS + the shared upgrade popup; no "Manage email".
$v    = gend_society_email_plan_view( $base );
$html = gsem_render( array( 'context' => 'talk-flows', 'view' => $v ) );
$xp   = gsem_xp( $html );
gsem_ok( false !== strpos( $html, '.gs-bk-hero {' ) && false !== strpos( $html, '.gs-bk-btn--cta:hover' ), 'talk-flows: prints the gs-bk card CSS (no dashboard stylesheet there)' );
gsem_ok( 1 === gsem_count( $xp, '//*[@data-gs-upgrade-modal]' ), 'talk-flows: renders the shared upgrade popup once' );
gsem_ok( 0 === gsem_count( $xp, '//a[contains(@href,"page=talk-flows")]' ), 'talk-flows: no "Manage email" link to itself' );
$html2 = gsem_render( array( 'context' => 'talk-flows', 'view' => $v ) );
gsem_ok( false === strpos( $html2, '.gs-bk-hero {' ) && 0 === gsem_count( gsem_xp( $html2 ), '//*[@data-gs-upgrade-modal]' ), 'talk-flows: CSS + popup printed once per page' );
$html3 = gsem_render( array( 'context' => 'dashboard', 'view' => $v ) );
gsem_ok( false === strpos( $html3, '.gs-bk-hero {' ) && 0 === gsem_count( gsem_xp( $html3 ), '//*[@data-gs-upgrade-modal]' ), 'dashboard: no CSS copy, no second popup (the dashboard has both)' );

// 9. Escaping.
$v  = gend_society_email_plan_view( array_merge( $base, array( 'current' => array( 'storage' => array( 'id' => 47, 'name' => '<script>x</script>' ) ) ) ) );
$h  = gsem_render( array( 'context' => 'dashboard', 'view' => $v ) );
gsem_ok( false === strpos( $h, '<script>x' ), 'escaping: plan names are escaped' );

$f = $GLOBALS['gsem_t']['fail'];
echo 'EMAIL-PLAN-CARD: ' . ( 0 === $f ? 'ALL PASS (' . $GLOBALS['gsem_t']['pass'] . ')' : 'FAILURES ' . $f ) . "\n";
