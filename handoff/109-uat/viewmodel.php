<?php
/**
 * Section viewmodel (plan 109-04; PLAN-03/05/06 data for 109-06 and 109-08): gend_society_plan_view_model()
 * turns membership + features v2 + catalogue into one array, per fixture scenario.
 *
 *   connected    plan free + lifetime; exactly groups + feature-suite included (D11); projects upgrade with
 *                unlock social-connector; every other area after_migration; all tiers checkout_allowed
 *   store-owner  next_renewal, paid; groups + feature-suite included; only social-connector purchasable
 *   inherited    source group, inherited {group_name, plan_name, plan_slug}; no tier at or below the
 *                inherited one is checkout_allowed
 *   processing   processing true (plan unchanged, last upgrade order still in flight)
 *   revoked      state revoked
 *   allowed_areas [] (or a v1 hub without areas[]) never produces an included area
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_plan_view_model' ), 'viewmodel-absent', 'no view-model on ' . gs109_mode() );
	return;
}

gs109p_setup();
$fx = gs109p_pair( 'connected' );
if ( ! $fx ) {
	return;
}
$token = $fx['token'];
wp_set_current_user( gs109p_admin_id() );

$keys_top  = array( 'state', 'plan', 'source', 'inherited', 'owner_name', 'processing', 'areas', 'tiers', 'matrix', 'after_migration', 'hub_origin', 'install_id', 'fetched_at' );
$keys_plan = array( 'id', 'slug', 'name', 'price_label', 'is_free', 'is_lifetime', 'next_renewal', 'status', 'status_label' );
$keys_area = array( 'slug', 'label', 'delivery', 'state', 'unlock_plan', 'features', 'admin_url' );
$keys_tier = array( 'id', 'slug', 'name', 'price_label', 'rank', 'is_current', 'is_inherited', 'checkout_allowed', 'launcher_url' );
$missing   = function ( array $keys, $arr ) {
	return is_array( $arr ) ? array_values( array_diff( $keys, array_keys( $arr ) ) ) : $keys;
};
$by_state  = function ( array $vm, $state ) {
	$out = array();
	foreach ( (array) $vm['areas'] as $a ) {
		if ( $state === $a['state'] ) {
			$out[] = $a['slug'];
		}
	}
	sort( $out );
	return $out;
};
$tier = function ( array $vm, $slug ) {
	foreach ( (array) $vm['tiers'] as $t ) {
		if ( $slug === $t['slug'] ) {
			return $t;
		}
	}
	return array();
};
$allowed = function ( array $vm ) {
	$out = array();
	foreach ( (array) $vm['tiers'] as $t ) {
		if ( ! empty( $t['checkout_allowed'] ) ) {
			$out[] = $t['slug'];
		}
	}
	return $out;
};

// ------------------------------------------------------------------------------------------------ connected
gs109p_scenario( 'connected' );
$vm = gend_society_plan_view_model();
gs109_assert( ! $missing( $keys_top, $vm ), 'vm-shape', 'top-level keys', implode( ',', $missing( $keys_top, $vm ) ) );
gs109_assert( ! $missing( $keys_plan, $vm['plan'] ?? null ), 'vm-shape-plan', 'plan keys', implode( ',', $missing( $keys_plan, $vm['plan'] ?? null ) ) );
$bad_area = array_filter(
	(array) ( $vm['areas'] ?? array() ),
	function ( $a ) use ( $missing, $keys_area ) {
		return (bool) $missing( $keys_area, $a );
	}
);
$bad_tier = array_filter(
	(array) ( $vm['tiers'] ?? array() ),
	function ( $t ) use ( $missing, $keys_tier ) {
		return (bool) $missing( $keys_tier, $t );
	}
);
gs109_assert( ! empty( $vm['areas'] ) && ! $bad_area && ! empty( $vm['tiers'] ) && ! $bad_tier, 'vm-shape-rows', 'area + tier rows carry every key' );
gs109_assert_eq( 'ok', $vm['state'] ?? '', 'vm-connected-state', 'connected: state ok' );
gs109_assert( 'connected' === ( $vm['plan']['slug'] ?? '' ) && 20 === (int) ( $vm['plan']['id'] ?? 0 ) && true === ( $vm['plan']['is_free'] ?? null ) && true === ( $vm['plan']['is_lifetime'] ?? null ) && '' === ( $vm['plan']['next_renewal'] ?? null ), 'vm-connected-plan', 'Connected #20, free, lifetime, no renewal', wp_json_encode( $vm['plan'] ?? null ) );
gs109_assert( 'connected' === ( $vm['source'] ?? '' ) && array_key_exists( 'inherited', $vm ) && null === $vm['inherited'] && 'GS109 Owner' === ( $vm['owner_name'] ?? '' ) && false === ( $vm['processing'] ?? null ), 'vm-connected-meta', 'source connected, not inherited, owner name, not processing' );
gs109_assert_eq( array( 'feature-suite', 'groups' ), $by_state( $vm, 'included' ), 'vm-connected-included', 'D11: exactly groups + feature-suite included' );
gs109_assert_eq( array( 'projects' ), $by_state( $vm, 'upgrade' ), 'vm-connected-upgrade', 'projects is the only upgrade area' );
$proj = array_values( array_filter( $vm['areas'], function ( $a ) { return 'projects' === $a['slug']; } ) );
gs109_assert( $proj && array( 'id' => 23, 'slug' => 'social-connector', 'name' => 'Social Connector' ) === $proj[0]['unlock_plan'] && '' === $proj[0]['admin_url'], 'vm-connected-unlock', 'projects unlock_plan {23, social-connector, Social Connector}, no admin_url', wp_json_encode( $proj ) );
gs109_assert_eq( array( 'app', 'hosting', 'reward', 'social', 'store', 'write' ), $by_state( $vm, 'after_migration' ), 'vm-connected-after', 'all container_only areas are after_migration' );
$grp = array_values( array_filter( $vm['areas'], function ( $a ) { return 'groups' === $a['slug']; } ) );
gs109_assert( $grp && 0 === strpos( $grp[0]['admin_url'], admin_url() ) && null === $grp[0]['unlock_plan'] && array( 'Group overview', 'Members', 'Recent activity' ) === $grp[0]['features'], 'vm-connected-groups', 'groups: local admin_url, no unlock plan, feature list', wp_json_encode( $grp ) );
gs109_assert_eq( array( 'content-builder', 'store-owner', 'social-connector' ), $allowed( $vm ), 'vm-connected-tiers', 'all three paid tiers checkout_allowed (rank order)' );
$t21 = $tier( $vm, 'content-builder' );
gs109_assert( 21 === (int) ( $t21['id'] ?? 0 ) && '$14.99 / month' === ( $t21['price_label'] ?? '' ) && false !== strpos( (string) ( $t21['launcher_url'] ?? '' ), 'action=gend_society_plan_checkout' ) && false !== strpos( (string) $t21['launcher_url'], 'plan=21' ), 'vm-connected-launcher', 'tier rows carry price and the launcher URL', wp_json_encode( $t21 ) );
gs109_assert( 'included' === ( $vm['matrix']['content-builder']['groups'] ?? '' ) && 'no' === ( $vm['matrix']['store-owner']['projects'] ?? '' ) && 'included' === ( $vm['matrix']['social-connector']['projects'] ?? '' ), 'vm-connected-matrix', 'matrix tier x area included|no' );
gs109_assert( 6 === count( (array) ( $vm['after_migration'] ?? array() ) ) && 'app' === ( $vm['after_migration'][0]['area'] ?? '' ) && array( 'Wireframe Generator (Leo)' ) === ( $vm['after_migration'][0]['features'] ?? null ), 'vm-connected-after-list', 'after_migration list from the catalogue (6 areas with features)' );
gs109_assert( 'http://localhost:8919' === ( $vm['hub_origin'] ?? '' ) && get_option( 'gend_society_install_id' ) === ( $vm['install_id'] ?? '' ) && (int) ( $vm['fetched_at'] ?? 0 ) > time() - 60, 'vm-connected-origin', 'hub_origin, install_id, fetched_at', wp_json_encode( array( $vm['hub_origin'] ?? null, $vm['fetched_at'] ?? null ) ) );
gs109_assert( false === strpos( wp_json_encode( $vm ), $token ), 'vm-no-token', 'view-model never holds the token' );

// ---------------------------------------------------------------------------------------------- store-owner
gs109p_scenario( 'store-owner' );
$vm = gend_society_plan_view_model();
gs109_assert( 'store-owner' === ( $vm['plan']['slug'] ?? '' ) && false === $vm['plan']['is_free'] && false === $vm['plan']['is_lifetime'] && '2026-11-12' === $vm['plan']['next_renewal'] && '$29.99 / month' === $vm['plan']['price_label'], 'vm-store-owner-plan', 'store-owner: paid, recurring, next_renewal 2026-11-12, price label', wp_json_encode( $vm['plan'] ?? null ) );
gs109_assert( 'site' === $vm['source'] && null === $vm['inherited'], 'vm-store-owner-source', 'source site' );
gs109_assert_eq( array( 'feature-suite', 'groups' ), $by_state( $vm, 'included' ), 'vm-store-owner-included', 'groups + feature-suite included' );
gs109_assert_eq( array( 'social-connector' ), $allowed( $vm ), 'vm-store-owner-tiers', 'only social-connector purchasable' );
gs109_assert( ! empty( $tier( $vm, 'store-owner' )['is_current'] ) && '' === $tier( $vm, 'store-owner' )['launcher_url'], 'vm-store-owner-current', 'store-owner tier is_current, no launcher URL' );

// ------------------------------------------------------------------------------------------------ inherited
gs109p_scenario( 'inherited' );
$vm = gend_society_plan_view_model();
gs109_assert( 'group' === ( $vm['source'] ?? '' ) && array( 'group_name' => 'GS109 Business Group', 'plan_name' => 'Social Connector', 'plan_slug' => 'social-connector' ) === ( $vm['inherited'] ?? null ), 'vm-inherited', 'source group, inherited {group_name, plan_name, plan_slug}', wp_json_encode( $vm['inherited'] ?? null ) );
gs109_assert_eq( array(), $allowed( $vm ), 'vm-inherited-tiers', 'no tier at or below the inherited Social Connector is checkout_allowed' );
gs109_assert( ! empty( $tier( $vm, 'social-connector' )['is_inherited'] ), 'vm-inherited-flag', 'social-connector row is_inherited' );
gs109_assert_eq( array( 'feature-suite', 'groups', 'projects' ), $by_state( $vm, 'included' ), 'vm-inherited-included', 'inherited Social Connector: projects included too' );
// The catalogue claiming a tier below the inherited one is purchasable is still refused by rank.
$cat = gs109_fixture_json( 'inherited', 'catalog.json' );
foreach ( $cat['tiers'] as &$t ) {
	$t['checkout_allowed'] = true;
}
unset( $t );
$mem = gs109_fixture_json( 'inherited', 'membership.json' );
$mem['checkout_allowed_plan_ids'] = array( 21, 22, 23 );
gs109p_clear_caches();
$o1 = gs109p_inject( 'catalog', 200, $cat );
$o2 = gs109p_inject( 'membership', 200, $mem );
$vm = gend_society_plan_view_model( true );
$o1();
$o2();
gs109_assert_eq( array(), $allowed( $vm ), 'vm-inherited-rank-guard', 'even if the hub flags lower tiers purchasable, nothing at or below the inherited rank is checkout_allowed' );

// ----------------------------------------------------------------------------------------------- processing
gs109p_scenario( 'processing' );
$vm = gend_society_plan_view_model();
gs109_assert( true === ( $vm['processing'] ?? null ) && 'connected' === $vm['plan']['slug'], 'vm-processing', 'processing: plan unchanged + last upgrade order in flight -> processing true' );
gs109p_scenario( 'connected' );
$vm = gend_society_plan_view_model();
gs109_assert( false === $vm['processing'], 'vm-not-processing', 'connected (no last_upgrade): processing false' );

// --------------------------------------------------------------------------------------------------- revoked
gs109p_scenario( 'revoked' );
$vm = gend_society_plan_view_model();
gs109_assert_eq( 'revoked', $vm['state'] ?? '', 'vm-revoked', 'revoked: state revoked' );
gs109_assert( array() === $by_state( $vm, 'included' ), 'vm-revoked-nothing-included', 'revoked with no cache: nothing included' );

// ------------------------------------------------------------------------------- allowed_areas [] guard
gs109p_scenario( 'connected' );
$v1 = gs109_fixture_json( 'connected', 'features.json' );
unset( $v1['areas'], $v1['rev'] );
$v1['allowed_areas'] = array();
$off = gs109p_inject( 'features', 200, $v1 );
$vm  = gend_society_plan_view_model( true );
$off();
gs109_assert( 'ok' === $vm['state'] && array() === $by_state( $vm, 'included' ), 'vm-empty-allowed-v1', 'v1 hub (no areas[]) with allowed_areas [] -> no area included', wp_json_encode( $by_state( $vm, 'included' ) ) );
$v2 = gs109_fixture_json( 'connected', 'features.json' );
unset( $v2['rev'] );
foreach ( $v2['areas'] as &$a ) {
	if ( 'store' === $a['slug'] ) {
		$a['state'] = 'included';
	}
	if ( 'projects' === $a['slug'] ) {
		$a['state'] = 'weird';
	}
}
unset( $a );
gs109p_clear_caches();
$off = gs109p_inject( 'features', 200, $v2 );
$vm  = gend_society_plan_view_model( true );
$off();
gs109_assert_eq( array( 'feature-suite', 'groups' ), $by_state( $vm, 'included' ), 'vm-container-only-never-included', 'a container_only area is never included on standalone; unknown states are not included' );

// ---------------------------------------------------------------------------------------------- unreachable
gs109p_clear_caches();
$off = gs109p_inject( 'features', new WP_Error( 'http_request_failed', 'down' ), '' );
$o2  = gs109p_inject( 'membership', new WP_Error( 'http_request_failed', 'down' ), '' );
$o3  = gs109p_inject( 'catalog', new WP_Error( 'http_request_failed', 'down' ), '' );
$vm  = gend_society_plan_view_model();
$off();
$o2();
$o3();
gs109_assert( 'unreachable' === $vm['state'] && array() === $by_state( $vm, 'included' ), 'vm-unreachable', 'hub down with no cache: state unreachable, nothing included', $vm['state'] );
