<?php
/**
 * Phase 109 plugin battery: helpers for the plan sections (popup, upgrade-page, consent, connect-modal; the 109-06
 * card / compare / page-assets sections were retired in 109-30 with the Plan & features page).
 *
 * Builds on _planaccess.php (pairing with the fixture hub, request capture, loopback requests with a real
 * session). Rendered HTML is inspected with DOMXPath, never with regular expressions on markup.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

/**
 * Require inc/pages/plan.php when this process did not load it (it is loaded at boot on standalone). Since 109-30 it
 * only holds the gs-plan asset helpers the upgrade page uses (the Plan & features page is gone).
 */
function gs109pl_require_page() {
	$p = GEND_SOCIETY_DIR . 'inc/pages/plan.php';
	if ( ! function_exists( 'gend_society_plan_assets_enqueue' ) && is_readable( $p ) ) {
		require_once $p;
	}
	return function_exists( 'gend_society_plan_assets_enqueue' ) && function_exists( 'gend_society_plan_page_url' );
}

/** Pair with a fixture scenario, load plan-access + the page, start from empty caches, act as the admin. */
function gs109pl_start( $scenario = 'connected' ) {
	gs109p_setup();
	$fx = gs109p_pair( $scenario );
	if ( ! $fx ) {
		return array();
	}
	if ( ! gs109_assert( gs109pl_require_page(), 'page-loaded', 'inc/pages/plan.php helpers loaded (standalone)' ) ) {
		return array();
	}
	wp_set_current_user( gs109p_admin_id() );
	return $fx;
}

/** Switch scenario, drop caches, return a fresh view-model. */
function gs109pl_vm( $scenario ) {
	gs109_fixture_scenario( $scenario );
	gs109p_clear_caches();
	return gend_society_plan_view_model( true );
}

/** DOMXPath over an HTML fragment or page (UTF-8). */
function gs109pl_xp( $html ) {
	$doc = new DOMDocument();
	$old = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $old );
	return new DOMXPath( $doc );
}

/** DOMXPath over a full HTML page. */
function gs109pl_xp_page( $html ) {
	$doc = new DOMDocument();
	$old = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $old );
	return new DOMXPath( $doc );
}

/** Whitespace-normalised text of a node (or of the first node a query matches). */
function gs109pl_text( DOMXPath $xp, $query ) {
	$n = $xp->query( $query );
	if ( ! $n || ! $n->length ) {
		return null;
	}
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $n->item( 0 )->textContent, ENT_QUOTES, 'UTF-8' ) ) );
}

/** Attribute values of every node a query matches. */
function gs109pl_attrs( DOMXPath $xp, $query, $attr ) {
	$out = array();
	$n   = $xp->query( $query );
	foreach ( $n ? $n : array() as $el ) {
		$out[] = (string) $el->getAttribute( $attr );
	}
	return $out;
}

/** Count of nodes a query matches. */
function gs109pl_count( DOMXPath $xp, $query ) {
	$n = $xp->query( $query );
	return $n ? $n->length : 0;
}

/** Date as the card prints it (site date format). */
function gs109pl_date( $ymd ) {
	return date_i18n( (string) get_option( 'date_format', 'F j, Y' ), strtotime( $ymd ) );
}

/** Area slugs of one Feature Access group (included | upgrade | after_migration). */
function gs109pl_area_group( DOMXPath $xp, $state ) {
	return gs109pl_attrs( $xp, '//ul[@data-gs-plan-group="' . $state . '"]/li', 'data-gs-plan-area' );
}

/** Legacy 1.5.0 per-tier Choose buttons (removed in 1.5.1; sections assert there are none): plan id => href. */
function gs109pl_choose( DOMXPath $xp ) {
	$out = array();
	foreach ( $xp->query( '//a[@data-gs-plan-checkout]' ) as $a ) {
		$out[ (int) $a->getAttribute( 'data-gs-plan-checkout' ) ] = array(
			'href'   => (string) $a->getAttribute( 'href' ),
			'target' => (string) $a->getAttribute( 'target' ),
			'rel'    => (string) $a->getAttribute( 'rel' ),
			'text'   => trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ),
		);
	}
	ksort( $out );
	return $out;
}

/** gend.me Change Plan popup buttons (109-21): list of {href, target, rel, text}. */
function gs109pl_popup( DOMXPath $xp ) {
	$out = array();
	foreach ( $xp->query( '//a[@data-gs-plan-popup]' ) as $a ) {
		$out[] = array(
			'href'   => (string) $a->getAttribute( 'href' ),
			'target' => (string) $a->getAttribute( 'target' ),
			'rel'    => (string) $a->getAttribute( 'rel' ),
			'text'   => trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ),
		);
	}
	return $out;
}

/** The exact Change Plan URL for a scenario: the hub's membership_url + ?ui=embed&plan_only=1&group=dashboard. */
function gs109pl_change_url( $scenario ) {
	$m = gs109_fixture_json( $scenario, 'membership.json' );
	return (string) ( $m['membership_url'] ?? '' ) . '?ui=embed&plan_only=1&group=dashboard';
}

/** True when $buttons is exactly one popup button to $url, new tab without opener (no JS), with $text. */
function gs109pl_popup_is( array $buttons, $url, $text ) {
	return 1 === count( $buttons ) && $url === $buttons[0]['href'] && '_blank' === $buttons[0]['target'] && 'noopener' === $buttons[0]['rel'] && $text === $buttons[0]['text'];
}
