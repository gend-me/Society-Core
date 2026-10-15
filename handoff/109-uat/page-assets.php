<?php
/**
 * Section page-assets (plan 109-06; Guideline 8): the rendered admin.php?page=gs-plan, fetched over a real
 * loopback request with an admin session (fixture hub, connected).
 *
 *   - no <iframe>
 *   - every <script src> and <link rel=stylesheet> is on the bed host (or relative)
 *   - gs-plan.css / gs-plan.js are the plugin's local files; their config comes from wp_localize_script
 *     (gend-society-plan-js-extra) and holds no token
 *   - no inline <script>/<style> inside the page body, and none on the page that a core screen of the same
 *     install (options-general.php) does not also print, apart from the localize data
 *   - the raw install token is absent from the HTML
 *   - a subscriber gets no page
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_plan_page_render' ), 'assets-absent', 'Plan & features page not loaded on ' . gs109_mode() );
	return;
}

$fx = gs109pl_start( 'connected' );
if ( ! $fx ) {
	return;
}
$token = $fx['token'];
$bed   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
$sess  = gs109p_session( gs109p_admin_id() );

$page = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-plan' ), $sess );
$core = gs109p_web( 'GET', admin_url( 'options-general.php' ), $sess );
gs109_assert( 200 === $page['status'] && false !== strpos( $page['body'], 'gend-society-plan' ), 'assets-page-200', 'admin.php?page=gs-plan: 200 with the page', $page['status'] . ' ' . $page['error'] );
gs109_assert( 200 === $core['status'], 'assets-core-200', 'options-general.php: 200 (baseline)', $core['status'] );
gs109_assert( false !== strpos( $page['body'], 'data-gs-plan-popup="1"' ) && false === strpos( $page['body'], 'data-gs-plan-checkout' ) && false === strpos( $page['body'], 'gs-plan-launcher' ), 'assets-page-cards', 'page carries the cards (one Change Plan popup button, no per-tier Choose, no launcher asset)' );

$xp = gs109pl_xp_page( $page['body'] );
gs109_assert( 0 === gs109pl_count( $xp, '//iframe' ) && false === stripos( $page['body'], '<iframe' ), 'assets-no-iframe', 'no <iframe>' );

$foreign = array();
$srcs    = array_merge( gs109pl_attrs( $xp, '//script[@src]', 'src' ), gs109pl_attrs( $xp, '//link[contains(concat(" ",normalize-space(@rel)," ")," stylesheet ")]', 'href' ) );
foreach ( $srcs as $u ) {
	$h = wp_parse_url( $u, PHP_URL_HOST );
	$p = wp_parse_url( $u, PHP_URL_PORT );
	if ( $h && strtolower( $h . ( $p ? ':' . $p : '' ) ) !== $bed ) {
		$foreign[] = $u;
	}
}
gs109_assert( ! $foreign && count( $srcs ) > 0, 'assets-local-only', 'every script src / stylesheet is on ' . $bed . ' (' . count( $srcs ) . ' checked)', $foreign );

$js  = gs109pl_attrs( $xp, '//script[@id="gend-society-plan-js"]', 'src' );
$css = gs109pl_attrs( $xp, '//link[@id="gend-society-plan-css"]', 'href' );
gs109_assert( $js && 0 === strpos( $js[0], GEND_SOCIETY_URL . 'assets/gs-plan.js' ), 'assets-js', 'gs-plan.js enqueued from the plugin', $js );
gs109_assert( $css && 0 === strpos( $css[0], GEND_SOCIETY_URL . 'assets/gs-plan.css' ), 'assets-css', 'gs-plan.css enqueued from the plugin', $css );

$extra = (string) gs109pl_text( $xp, '//script[@id="gend-society-plan-js-extra"]' );
$cfg   = preg_match( '/var gendSocietyPlan = (\{.*\});/s', $extra, $m ) ? json_decode( $m[1], true ) : null;
gs109_assert( is_array( $cfg ) && 'http://localhost:8919' === ( $cfg['hubOrigin'] ?? '' ) && $fx['install_id'] === ( $cfg['installId'] ?? '' ) && admin_url( 'admin-ajax.php' ) === ( $cfg['ajaxUrl'] ?? '' ) && '' !== ( $cfg['nonce'] ?? '' ), 'assets-localize', 'config via wp_localize_script: ajaxUrl, nonce, hubOrigin http://localhost:8919, installId', substr( $extra, 0, 300 ) );
gs109_assert( false === strpos( $extra, $token ) && false === stripos( $extra, 'token' ), 'assets-localize-no-token', 'localize data holds no token' );

// Inside the page body: nothing inline.
$wrap = $xp->query( '//div[contains(concat(" ",normalize-space(@class)," ")," gend-society-plan ")]' );
$in   = 0;
if ( $wrap && $wrap->length ) {
	$in = $xp->query( './/script|.//style', $wrap->item( 0 ) )->length;
}
gs109_assert( $wrap && 1 === $wrap->length && 0 === $in, 'assets-wrap-inline', 'page body: no <script> or <style>' );

// Inline blocks on the page vs a core screen: only the localize data is new.
$sig = function ( DOMXPath $x, $tag ) {
	$out = array();
	foreach ( $x->query( '//' . $tag . '[not(@src)]' ) as $el ) {
		$id = (string) $el->getAttribute( 'id' );
		if ( '' === $id ) {
			$id = 'noid:' . substr( preg_replace( '/\s+/', ' ', trim( $el->textContent ) ), 0, 24 );
		}
		$out[ $id ] = true;
	}
	return array_keys( $out );
};
$cx        = gs109pl_xp_page( $core['body'] );
$new_js    = array_values( array_diff( $sig( $xp, 'script' ), $sig( $cx, 'script' ) ) );
$new_style = array_values( array_diff( $sig( $xp, 'style' ), $sig( $cx, 'style' ) ) );
gs109_assert_eq( array( 'gend-society-plan-js-extra' ), $new_js, 'assets-inline-script', 'inline scripts beyond a core screen: only gend-society-plan-js-extra (localize data)' );
gs109_assert_eq( array(), $new_style, 'assets-inline-style', 'no inline <style> beyond a core screen' );

gs109_assert( false === strpos( $page['body'], $token ), 'assets-no-token', 'raw install token absent from the HTML' );
gs109_assert( 0 === gs109pl_count( $cx, '//script[@id="gend-society-plan-js"]' ), 'assets-scoped', 'gs-plan.js not enqueued on other admin screens' );

// Subscriber: no page.
$sub = gs109p_session( gs109p_user( 'subscriber' ) );
$sp  = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-plan' ), $sub );
gs109_assert( 200 !== $sp['status'] || false === strpos( $sp['body'], 'data-gs-plan-popup' ), 'assets-subscriber', 'subscriber: no Plan & features page', $sp['status'] );
