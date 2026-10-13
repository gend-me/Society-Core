<?php
/**
 * Section compare (plan 109-06; PLAN-06): the plan comparison renders the /catalog tiers x areas.
 *
 *   - tier columns in rank order (also when the hub lists them out of order), name + price per column
 *   - current (store-owner) and inherited (inherited) columns marked
 *   - every matrix cell equals the fixture catalogue; one row per area
 *   - "Where it runs": container_only areas read After migration, hub areas On gend.me now
 *   - the After migration list carries every after_migration area of the catalogue with its features
 *   - read-only (109-21): no Choose row / buttons / links of any kind in the table, one line "Plans are
 *     changed on gend.me." under it
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_plan_render_compare' ), 'compare-absent', 'comparison not loaded on ' . gs109_mode() );
	return;
}

if ( ! gs109pl_start( 'connected' ) ) {
	return;
}

$check = function ( $scenario, $label ) {
	$cat = gs109_fixture_json( $scenario, 'catalog.json' );
	$vm  = gs109pl_vm( $scenario );
	$xp  = gs109pl_xp( gend_society_plan_render_compare( $vm ) );
	$id  = 'compare-' . $scenario;

	$tiers = $cat['tiers'];
	usort(
		$tiers,
		function ( $a, $b ) {
			return $a['rank'] <=> $b['rank'];
		}
	);
	$want_cols = array_column( $tiers, 'slug' );
	gs109_assert_eq( $want_cols, gs109pl_attrs( $xp, '//thead/tr/th[@data-gs-plan-tier]', 'data-gs-plan-tier' ), $id . '-columns', "{$label}: tier columns by rank" );
	$names_ok = true;
	foreach ( $tiers as $t ) {
		$names_ok = $names_ok
			&& $t['name'] === gs109pl_text( $xp, '//th[@data-gs-plan-tier="' . $t['slug'] . '"]/span[@class="gs-plan-compare__name"]' )
			&& $t['price_label'] === gs109pl_text( $xp, '//th[@data-gs-plan-tier="' . $t['slug'] . '"]/span[@class="gs-plan-compare__price"]' );
	}
	gs109_assert( $names_ok, $id . '-names', "{$label}: column names and prices from the catalogue" );

	$area_slugs = array_column( $cat['areas'], 'slug' );
	$rows       = gs109pl_attrs( $xp, '//tbody/tr', 'data-gs-plan-row' );
	gs109_assert_eq( $area_slugs, $rows, $id . '-rows', "{$label}: one row per catalogue area, catalogue order" );

	$bad = array();
	foreach ( $tiers as $ti => $t ) {
		foreach ( $area_slugs as $a ) {
			$cells = $xp->query( '//tbody/tr[@data-gs-plan-row="' . $a . '"]/td[contains(@class,"gs-plan-compare__cell")]' );
			$got   = $cells && $cells->length > $ti ? $cells->item( $ti )->getAttribute( 'data-gs-plan-cell' ) : '(missing)';
			$want  = $cat['matrix'][ $t['slug'] ][ $a ] ?? 'no';
			if ( $got !== $want ) {
				$bad[] = "{$t['slug']}/{$a}: {$got} != {$want}";
			}
		}
	}
	gs109_assert( ! $bad, $id . '-matrix', "{$label}: every cell equals the catalogue matrix (" . count( $tiers ) * count( $area_slugs ) . ' cells)', $bad );

	$where_bad = array();
	foreach ( $cat['areas'] as $a ) {
		$want = 'container_only' === $a['delivery'] ? 'after_migration' : 'hub';
		$got  = gs109pl_attrs( $xp, '//tbody/tr[@data-gs-plan-row="' . $a['slug'] . '"]/td[@data-gs-plan-where]', 'data-gs-plan-where' );
		if ( array( $want ) !== $got ) {
			$where_bad[] = $a['slug'];
		}
	}
	gs109_assert( ! $where_bad, $id . '-where', "{$label}: Where it runs = After migration for container_only areas, On gend.me now for hub areas", $where_bad );

	$after = array_column( $cat['after_migration'], 'area' );
	gs109_assert_eq( $after, gs109pl_attrs( $xp, '//ul[@data-gs-plan-after]/li', 'data-gs-plan-after-area' ), $id . '-after', "{$label}: After migration list = every catalogue after_migration area" );
	$feat_ok = true;
	foreach ( $cat['after_migration'] as $row ) {
		$txt     = (string) gs109pl_text( $xp, '//ul[@data-gs-plan-after]/li[@data-gs-plan-after-area="' . $row['area'] . '"]' );
		$feat_ok = $feat_ok && $txt === $row['label'] . ': ' . implode( ', ', $row['features'] );
	}
	gs109_assert( $feat_ok, $id . '-after-features', "{$label}: After migration rows list the area's features" );
	gs109_assert( 0 === gs109pl_count( $xp, '//table//a' ) && 0 === gs109pl_count( $xp, '//tfoot' ) && 0 === gs109pl_count( $xp, '//*[@data-gs-plan-checkout or @data-gs-plan-popup]' ) && 0 === gs109pl_count( $xp, '//*[contains(@class,"gs-plan-compare__action")]' ), $id . '-readonly', "{$label}: comparison is read-only (no Choose row, no buttons or links in the table)" );
	gs109_assert_eq( 'Plans are changed on gend.me.', gs109pl_text( $xp, '//p[@class="gs-plan-compare__note"]' ), $id . '-note', "{$label}: \"Plans are changed on gend.me.\" under the table" );
	return $xp;
};

$xp = $check( 'connected', 'connected' );
gs109_assert( 0 === gs109pl_count( $xp, '//th[contains(@class,"is-current")]' ) && 'You are on Connected (free). Groups and Feature Suite are included.' === gs109pl_text( $xp, '//p[@class="gs-plan-compare__current"]' ), 'compare-connected-current', 'connected: no tier column current, Connected line shown' );

$xp = $check( 'store-owner', 'store-owner' );
gs109_assert_eq( array( 'store-owner' ), gs109pl_attrs( $xp, '//thead/tr/th[contains(@class,"is-current")]', 'data-gs-plan-tier' ), 'compare-store-current', 'store-owner: Store Owner column marked current' );
gs109_assert_eq( 'Current plan', gs109pl_text( $xp, '//th[@data-gs-plan-tier="store-owner"]/span[@class="gs-plan-compare__tag"]' ), 'compare-store-tag', 'store-owner: "Current plan" tag' );
gs109_assert( 0 === gs109pl_count( $xp, '//td[contains(@class,"is-current")][not(contains(@class,"gs-plan-compare__cell"))]' ) && 9 === gs109pl_count( $xp, '//td[contains(@class,"gs-plan-compare__cell")][contains(@class,"is-current")]' ), 'compare-store-highlight', 'store-owner: its 9 cells highlighted' );

$xp = $check( 'inherited', 'inherited' );
gs109_assert_eq( array( 'social-connector' ), gs109pl_attrs( $xp, '//thead/tr/th[contains(@class,"is-inherited")]', 'data-gs-plan-tier' ), 'compare-inherited-col', 'inherited: Social Connector column marked inherited' );
gs109_assert_eq( 'Inherited from your group', gs109pl_text( $xp, '//th[@data-gs-plan-tier="social-connector"]/span[@class="gs-plan-compare__tag"]' ), 'compare-inherited-tag', 'inherited: "Inherited from your group" tag' );

// Out-of-order catalogue: columns still by rank.
$cat          = gs109_fixture_json( 'connected', 'catalog.json' );
$cat['tiers'] = array_reverse( $cat['tiers'] );
unset( $cat['rev'] );
$off = gs109p_inject( 'catalog', 200, $cat );
$vm  = gs109pl_vm( 'connected' );
$off();
$xp = gs109pl_xp( gend_society_plan_render_compare( $vm ) );
gs109_assert_eq( array( 'content-builder', 'store-owner', 'social-connector' ), gs109pl_attrs( $xp, '//thead/tr/th[@data-gs-plan-tier]', 'data-gs-plan-tier' ), 'compare-rank-order', 'hub lists tiers in reverse: columns still by rank' );

// No catalogue at all: the comparison is a hidden placeholder (the rest of the page still renders).
$off = gs109p_inject( 'catalog', 404, array( 'code' => 'rest_no_route' ) );
$vm  = gs109pl_vm( 'connected' );
$off();
$xp = gs109pl_xp( gend_society_plan_render_compare( $vm ) );
gs109_assert( 1 === gs109pl_count( $xp, '//div[@id="gs-plan-compare"][@hidden]' ), 'compare-no-catalog', 'no catalogue: comparison hidden' );
