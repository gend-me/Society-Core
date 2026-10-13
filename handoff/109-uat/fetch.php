<?php
/**
 * Section fetch (plan 109-04; D10, PLAN-05): conditional hub fetches on a standalone install.
 *
 *   - not paired / no consent: zero HTTP requests (captured requests and wp-content/gs109-http.log)
 *   - first fetch: no If-None-Match; payload cached with its rev; TTL = cache_seconds (features 3600,
 *     catalogue 86400, membership 60)
 *   - fresh cache: zero requests
 *   - expired + scenario notmodified: If-None-Match "<rev>" sent, 304 keeps the payload (option not
 *     rewritten) and pushes the expiry out again
 *   - legacy reader gend_society_features_get_cached() delegates to the same conditional fetch
 *   - force: no If-None-Match
 *   - non-200 / transport error: stale payload kept, state unreachable
 *   - 401: state revoked and gend_society_connection_health_note_status(401) recorded
 *   - the raw install token never appears in gs109-http.log
 * hub / container: plan-access.php is absent; nothing else to check here (nolock proves gate parity).
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

if ( 'standalone' !== gs109_mode() ) {
	gs109_assert( ! function_exists( 'gend_society_plan_hub_get' ), 'fetch-absent', 'plan-access.php not loaded on ' . gs109_mode() );
	return;
}

gs109p_setup();
gs109_snapshot_options( array( 'gs109_fixture_scenario', 'gs109_fixture_dir', 'gs109_fixture_hub' ) );
gs109p_http_capture();
$logmark = gs109p_httplog_mark();

// ---------------------------------------------------------------------------- not paired / no consent
if ( ! gs109_assert( gs109p_require_modules(), 'fetch-module', 'inc/plan-access.php loadable' ) ) {
	return;
}
foreach ( array( 'gend_society_install_token', 'gend_society_consent', 'gend_society_remote_consent', 'gs109_fixture_scenario' ) as $o ) {
	delete_option( $o );
}
gs109p_clear_caches();
gs109p_http();
$r1 = gend_society_plan_features();
$r2 = gend_society_plan_catalog();
$r3 = gend_society_plan_membership( true );
$vm = gend_society_plan_view_model( true );
gs109_assert( 'not_connected' === $r1['state'] && 'not_connected' === $r2['state'] && 'not_connected' === $r3['state'] && 'not_connected' === $vm['state'], 'fetch-unpaired-state', 'not paired: every fetch and the view-model say not_connected', wp_json_encode( array( $r1['state'], $r2['state'], $r3['state'], $vm['state'] ) ) );
gs109_assert_eq( 0, count( gs109p_http() ), 'fetch-unpaired-zero', 'not paired: zero HTTP requests' );
gs109_assert_eq( '', trim( gs109p_httplog_since( $logmark ) ), 'fetch-unpaired-log', 'not paired: gs109-http.log has no new line' );

// ---------------------------------------------------------------------------------------------- pair
$fx = gs109p_pair( 'connected' );
if ( ! $fx ) {
	return;
}
$token = $fx['token'];

// consent withdrawn (filter) -> still zero requests
add_filter( 'gend_society_remote_allowed', '__return_false', PHP_INT_MAX );
gs109p_http();
$mark = gs109p_httplog_mark();
$r = gend_society_plan_features();
gs109_assert( 'not_connected' === $r['state'] && 0 === count( gs109p_http() ) && '' === trim( gs109p_httplog_since( $mark ) ), 'fetch-no-consent-zero', 'consent gate closed: state not_connected, zero requests, nothing logged', $r['state'] );
remove_filter( 'gend_society_remote_allowed', '__return_false', PHP_INT_MAX );

$exp_features = gs109_fixture_json( 'connected', 'features.json' );
$exp_catalog  = gs109_fixture_json( 'connected', 'catalog.json' );
gs109_fixture_log_lines();

// --- first fetch
gs109p_http();
$t0 = time();
$f1 = gend_society_plan_features();
$req = gs109p_hub_requests( gs109p_http() );
gs109_assert( 'ok' === $f1['state'] && is_array( $f1['payload'] ) && ( $f1['payload']['plan_slug'] ?? '' ) === 'connected', 'fetch-first-ok', 'first features fetch: state ok, Connected payload', $f1['state'] );
gs109_assert( 1 === count( $req ) && 'GET' === $req[0]['method'] && '/features' === substr( $req[0]['url'], -9 ), 'fetch-first-one', 'exactly one GET .../features', wp_json_encode( array_column( $req, 'url' ) ) );
gs109_assert( $req && '' === gs109p_header( $req[0]['headers'], 'If-None-Match' ), 'fetch-first-no-inm', 'first fetch carries no If-None-Match' );
gs109_assert( $req && 'Bearer ' . $token === gs109p_header( $req[0]['headers'], 'Authorization' ) && false === strpos( $req[0]['url'], $token ), 'fetch-bearer', 'token only in the Authorization header, never in the URL' );
gs109_assert( $req && isset( $req[0]['args']['redirection'] ) && 0 === (int) $req[0]['args']['redirection'] && 8 === (int) $req[0]['args']['timeout'], 'fetch-args', 'timeout 8, redirection 0', wp_json_encode( array( $req[0]['args']['timeout'] ?? null, $req[0]['args']['redirection'] ?? null ) ) );
$cached  = gs109p_raw( 'gend_society_features_cache' );
$expires = (int) gs109p_raw( 'gend_society_features_cache_expires' );
gs109_assert_eq( $exp_features['rev'], is_array( $cached ) ? ( $cached['rev'] ?? '' ) : '', 'fetch-rev-cached', 'features cache (legacy option) keeps the rev' );
gs109_assert( $expires >= $t0 + 3600 && $expires <= time() + 3600, 'fetch-ttl-3600', 'features TTL = cache_seconds 3600 (expires in ' . ( $expires - time() ) . ' s)' );

// --- fresh cache: zero requests, legacy reader agrees
gs109p_http();
$f2 = gend_society_plan_features();
$lg = gend_society_features_get_cached();
gs109_assert( 'ok' === $f2['state'] && 0 === count( gs109p_hub_requests( gs109p_http() ) ), 'fetch-fresh-zero', 'fresh cache: no request (plan_features + legacy features_get_cached)' );
gs109_assert( is_array( $lg ) && ( $lg['rev'] ?? '' ) === $exp_features['rev'], 'fetch-legacy-same', 'gend_society_features_get_cached() returns the same payload' );

// --- expired + 304
gs109_fixture_scenario( 'notmodified' );
update_option( 'gend_society_features_cache_expires', time() - 5, false );
$rewrites = 0;
$count    = function () use ( &$rewrites ) {
	++$rewrites;
};
add_action( 'update_option_gend_society_features_cache', $count );
gs109_fixture_log_lines();
gs109p_http();
$t1 = time();
$f3 = gend_society_plan_features();
$req = gs109p_hub_requests( gs109p_http() );
$ans = gs109p_fixture_answers();
remove_action( 'update_option_gend_society_features_cache', $count );
gs109_assert( $req && '"' . $exp_features['rev'] . '"' === gs109p_header( $req[0]['headers'], 'If-None-Match' ), 'fetch-inm-sent', 'expired cache: If-None-Match "<rev>" sent', $req ? gs109p_header( $req[0]['headers'], 'If-None-Match' ) : 'no request' );
gs109_assert( in_array( 'GET features -> 304', $ans, true ), 'fetch-304-answered', 'fixture answered 304', implode( ',', $ans ) );
gs109_assert( 'ok' === $f3['state'] && $f3['payload'] == $f1['payload'], 'fetch-304-payload', '304: same payload returned, state ok' ); // phpcs:ignore Universal.Operators.StrictComparisons
gs109_assert_eq( 0, $rewrites, 'fetch-304-no-rewrite', '304: the payload option is not rewritten' );
$expires = (int) gs109p_raw( 'gend_society_features_cache_expires' );
gs109_assert( $expires >= $t1 + 3600, 'fetch-304-bump', '304: expiry pushed out by cache_seconds (' . ( $expires - time() ) . ' s)' );

// --- legacy reader delegates (conditional fetch with If-None-Match) after expiry
update_option( 'gend_society_features_cache_expires', time() - 5, false );
gs109p_http();
$lg = gend_society_features_get_cached();
$req = gs109p_hub_requests( gs109p_http() );
gs109_assert( is_array( $lg ) && $req && '' !== gs109p_header( $req[0]['headers'], 'If-None-Match' ), 'fetch-legacy-conditional', 'features_get_cached() on standalone uses the conditional fetch' );

// --- force: no If-None-Match
gs109p_http();
$f4 = gend_society_plan_features( true );
$req = gs109p_hub_requests( gs109p_http() );
gs109_assert( 'ok' === $f4['state'] && $req && '' === gs109p_header( $req[0]['headers'], 'If-None-Match' ), 'fetch-force', 'force: request without If-None-Match' );

// --- catalogue: first 200 (TTL 86400, rev), then 304
delete_option( 'gend_society_plan_catalog_cache' );
gs109p_http();
$t2 = time();
$c1 = gend_society_plan_catalog();
$req = gs109p_hub_requests( gs109p_http() );
$cc  = gs109p_raw( 'gend_society_plan_catalog_cache' );
gs109_assert( 'ok' === $c1['state'] && isset( $c1['payload']['tiers'] ) && $req && '' === gs109p_header( $req[0]['headers'], 'If-None-Match' ), 'fetch-catalog-first', 'catalogue: first fetch ok, no If-None-Match' );
gs109_assert( is_array( $cc ) && ( $cc['rev'] ?? '' ) === $exp_catalog['rev'] && (int) ( $cc['expires'] ?? 0 ) >= $t2 + 86400 && (int) $cc['expires'] <= time() + 86400, 'fetch-catalog-ttl', 'catalogue cache {payload, rev, expires}: rev kept, TTL 86400', wp_json_encode( array( $cc['rev'] ?? null, isset( $cc['expires'] ) ? $cc['expires'] - time() : null ) ) );
gs109_assert( is_array( $cc ) && ! array_key_exists( 'gend_society_plan_catalog_cache', wp_load_alloptions() ), 'fetch-catalog-autoload', 'catalogue option is not autoloaded' );
$cc['expires'] = time() - 5;
update_option( 'gend_society_plan_catalog_cache', $cc, false );
gs109_fixture_log_lines();
gs109p_http();
$c2 = gend_society_plan_catalog();
$req = gs109p_hub_requests( gs109p_http() );
$ans = gs109p_fixture_answers();
$cc2 = gs109p_raw( 'gend_society_plan_catalog_cache' );
gs109_assert( $req && '"' . $exp_catalog['rev'] . '"' === gs109p_header( $req[0]['headers'], 'If-None-Match' ) && in_array( 'GET catalog -> 304', $ans, true ), 'fetch-catalog-304', 'catalogue: If-None-Match sent, 304 answered', implode( ',', $ans ) );
gs109_assert( 'ok' === $c2['state'] && $c2['payload'] == $c1['payload'] && (int) $cc2['expires'] > time() + 86000, 'fetch-catalog-304-keep', 'catalogue 304: payload kept, expiry bumped' ); // phpcs:ignore Universal.Operators.StrictComparisons

// --- membership: legacy option written, TTL = cache_seconds 60
$exp_mid = (int) ( gs109_fixture_json( 'connected', 'membership.json' )['membership_id'] ?? 0 );
gs109p_http();
$m1 = gend_society_plan_membership( true );
$mexp = (int) gs109p_raw( 'gend_society_remote_membership_cache_expires' );
gs109_assert( 'ok' === $m1['state'] && $exp_mid > 0 && $exp_mid === (int) ( $m1['payload']['membership_id'] ?? 0 ), 'fetch-membership', 'membership fetched (membership ' . $exp_mid . ')' );
gs109_assert( $mexp >= time() + 55 && $mexp <= time() + 60, 'fetch-membership-ttl', 'membership TTL = cache_seconds 60 (' . ( $mexp - time() ) . ' s)' );
gs109p_http();
$lm = gend_society_remote_membership_get_cached();
gs109_assert( is_array( $lm ) && $exp_mid === (int) ( $lm['membership_id'] ?? 0 ) && 0 === count( gs109p_hub_requests( gs109p_http() ) ), 'fetch-membership-legacy', 'gend_society_remote_membership_get_cached() reads the same cache without a request' );

// --- non-200 / transport error: stale payload kept, state unreachable
update_option( 'gend_society_features_cache_expires', time() - 5, false );
$off = gs109p_inject( 'features', 500, array( 'code' => 'boom' ) );
$u1  = gend_society_plan_features();
$off();
gs109_assert( 'unreachable' === $u1['state'] && is_array( $u1['payload'] ) && ( $u1['payload']['rev'] ?? '' ) === $exp_features['rev'], 'fetch-500-stale', 'HTTP 500: state unreachable, stale payload kept', $u1['state'] );
$off = gs109p_inject( 'features', new WP_Error( 'http_request_failed', 'cURL error 28' ), '' );
$u2  = gend_society_plan_features();
$off();
gs109_assert( 'unreachable' === $u2['state'] && is_array( $u2['payload'] ), 'fetch-error-stale', 'transport error: state unreachable, stale payload kept', $u2['state'] );
gs109_assert( is_array( gs109p_raw( 'gend_society_features_cache' ) ), 'fetch-error-keeps-option', 'the cached payload option survives errors' );

// --- 401: revoked + connection health
gs109_fixture_scenario( 'revoked' );
update_option( 'gend_society_features_cache_expires', time() - 5, false );
delete_option( 'gend_society_connection_health' );
$rv = gend_society_plan_features();
$health = function_exists( 'gend_society_connection_health_get' ) ? gend_society_connection_health_get() : array();
gs109_assert( 'revoked' === $rv['state'], 'fetch-401-revoked', '401: state revoked', $rv['state'] );
gs109_assert( 'unauthorized' === ( $health['status'] ?? '' ) && 401 === (int) ( $health['code'] ?? 0 ), 'fetch-401-health', '401 recorded through gend_society_connection_health_note_status(401)', wp_json_encode( $health ) );
$rc = gend_society_plan_catalog( true );
gs109_assert( 'revoked' === $rc['state'], 'fetch-401-catalog', 'catalogue 401: state revoked', $rc['state'] );

// --- token never logged
$log = gs109p_httplog_since( $logmark );
gs109_assert( '' !== $log && false === strpos( $log, $token ), 'fetch-log-no-token', 'gs109-http.log (' . substr_count( $log, "\n" ) . ' new lines) never contains the raw token' );
