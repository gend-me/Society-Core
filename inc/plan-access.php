<?php
/**
 * Plan and feature data layer for a standalone install connected to gend.me (Phase 109).
 *
 * Loaded only on standalone installs that are paired (manifest needs: standalone, paired), so hub and
 * container output never changes.
 *
 *   - gend_society_plan_hub_get(): token-authenticated GET of an /install/{id}/<route> on the hub with
 *     If-None-Match (ETag "<rev>"). A 304 extends the cache without rewriting the payload, a 401 marks the
 *     connection revoked (and feeds the connection health notice), any other failure keeps the stale payload.
 *     The token only ever travels in the Authorization header, server to server.
 *   - gend_society_plan_features() / _membership() / _catalog(): the three hub reads. Features and membership
 *     keep using the existing option caches so older readers stay consistent; the catalogue has its own
 *     option. TTL = the payload's cache_seconds (hub: features 3600, catalogue 86400).
 *   - gend_society_plan_invalidate_all(): drops every plan cache (wp_login, the Refresh AJAX, checkout
 *     completion through the Refresh AJAX); the next fetch is unconditional.
 *   - gend_society_plan_view_model(): one array every Phase 109 template renders from. Its change_plan_url
 *     is gend.me's own Change Plan page for this site's membership (membership_url from the hub +
 *     ?ui=embed&plan_only=1&group=dashboard), built only when that URL is on the paired hub; the templates
 *     open it in a popup. No token or nonce is ever part of it.
 *
 * Nothing here locks code shipped in the plugin (wordpress.org Guideline 5): the hub alone enforces what a
 * plan includes, on its own routes.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_plan_identity' ) ) {
	/**
	 * The pairing this install holds: install id, token and hub base URL ('' when missing).
	 *
	 * @return array{install_id:string,token:string,base:string}
	 */
	function gend_society_plan_identity(): array {
		return array(
			'install_id' => (string) get_option( 'gend_society_install_id', '' ),
			'token'      => (string) get_option( 'gend_society_install_token', '' ),
			'base'       => untrailingslashit( (string) get_option( 'gend_society_gend_base_url', '' ) ),
		);
	}
}

if ( ! function_exists( 'gend_society_plan_legacy_caches' ) ) {
	/**
	 * Cache options that keep their pre-109 layout (payload option + "<option>_expires").
	 */
	function gend_society_plan_legacy_caches(): array {
		return array( 'gend_society_features_cache', 'gend_society_remote_membership_cache' );
	}
}

if ( ! function_exists( 'gend_society_plan_cache_read' ) ) {
	/**
	 * Read a plan cache.
	 *
	 * @param string $option     Cache option name.
	 * @param string $install_id Current install id (a cache written for another install is ignored).
	 * @return array{payload:?array,rev:string,expires:int,fetched_at:int}
	 */
	function gend_society_plan_cache_read( string $option, string $install_id = '' ): array {
		$out = array(
			'payload'    => null,
			'rev'        => '',
			'expires'    => 0,
			'fetched_at' => 0,
		);
		if ( in_array( $option, gend_society_plan_legacy_caches(), true ) ) {
			$payload = get_option( $option, null );
			if ( is_array( $payload ) ) {
				$out['payload'] = $payload;
				$out['rev']     = isset( $payload['rev'] ) && is_string( $payload['rev'] ) ? $payload['rev'] : '';
			}
			$out['expires']    = (int) get_option( $option . '_expires', 0 );
			$out['fetched_at'] = (int) get_option( $option . '_fetched_at', 0 );
			return $out;
		}
		$c = get_option( $option, null );
		if ( ! is_array( $c ) || ! isset( $c['payload'] ) || ! is_array( $c['payload'] ) ) {
			return $out;
		}
		if ( '' !== $install_id && isset( $c['install_id'] ) && (string) $c['install_id'] !== $install_id ) {
			return $out;
		}
		$out['payload']    = $c['payload'];
		$out['rev']        = isset( $c['rev'] ) ? (string) $c['rev'] : '';
		$out['expires']    = isset( $c['expires'] ) ? (int) $c['expires'] : 0;
		$out['fetched_at'] = isset( $c['fetched_at'] ) ? (int) $c['fetched_at'] : 0;
		return $out;
	}
}

if ( ! function_exists( 'gend_society_plan_cache_write' ) ) {
	/**
	 * Write a plan cache. With $payload null only the expiry changes (the 304 path): legacy payload options
	 * are left untouched.
	 *
	 * @param string     $option     Cache option name.
	 * @param array|null $payload    New payload, or null to keep the cached one.
	 * @param string     $rev        Payload revision (ETag value without quotes).
	 * @param int        $expires    Unix time the cache goes stale.
	 * @param string     $install_id Install id the payload belongs to.
	 */
	function gend_society_plan_cache_write( string $option, ?array $payload, string $rev, int $expires, string $install_id = '' ): void {
		if ( in_array( $option, gend_society_plan_legacy_caches(), true ) ) {
			if ( null !== $payload ) {
				if ( '' !== $rev ) {
					$payload['rev'] = $rev;
				}
				update_option( $option, $payload, false );
				update_option( $option . '_fetched_at', time(), false );
			}
			update_option( $option . '_expires', $expires, false );
			return;
		}
		$c = get_option( $option, null );
		$c = is_array( $c ) ? $c : array();
		if ( null !== $payload ) {
			$c['payload']    = $payload;
			$c['rev']        = $rev;
			$c['fetched_at'] = time();
			$c['install_id'] = $install_id;
		}
		$c['expires'] = $expires;
		update_option( $option, $c, false );
	}
}

if ( ! function_exists( 'gend_society_plan_cache_expire' ) ) {
	/**
	 * Mark a cache stale (payload kept as the fallback).
	 *
	 * @param string $option   Cache option name.
	 * @param bool   $drop_rev Also forget the revision, so the next fetch is unconditional.
	 */
	function gend_society_plan_cache_expire( string $option, bool $drop_rev = false ): void {
		if ( in_array( $option, gend_society_plan_legacy_caches(), true ) ) {
			delete_option( $option . '_expires' );
			if ( $drop_rev ) {
				$payload = get_option( $option, null );
				if ( is_array( $payload ) && array_key_exists( 'rev', $payload ) ) {
					unset( $payload['rev'] );
					update_option( $option, $payload, false );
				}
			}
			return;
		}
		$c = get_option( $option, null );
		if ( is_array( $c ) ) {
			$c['expires'] = 0;
			if ( $drop_rev ) {
				$c['rev'] = '';
			}
			update_option( $option, $c, false );
		}
	}
}

if ( ! function_exists( 'gend_society_plan_hub_get' ) ) {
	/**
	 * Conditional, token-authenticated GET of /install/{id}/<route> on the hub, cached in $option.
	 *
	 * No request at all while the install is not paired or the owner has not consented.
	 *
	 * @param string $route  Route after /install/{id}/ (e.g. 'features', 'catalog').
	 * @param string $option Cache option name.
	 * @param bool   $force  Skip the cache and send no If-None-Match.
	 * @return array{state:string,payload:?array,status:int,source:string}
	 *               state: ok | not_connected | revoked | unreachable;
	 *               source: cache | fetch | 304 | stale | none;
	 *               + 'error' (decoded body or null) on any other non-200 answer.
	 */
	function gend_society_plan_hub_get( string $route, string $option, bool $force = false ): array {
		$out = array(
			'state'   => 'not_connected',
			'payload' => null,
			'status'  => 0,
			'source'  => 'none',
		);
		$id = gend_society_plan_identity();
		if ( '' === $id['install_id'] || '' === $id['token'] || '' === $id['base'] ) {
			return $out;
		}
		// RUN-01: no gend.me request before the owner consented.
		if ( function_exists( 'gend_society_remote_allowed' ) && ! gend_society_remote_allowed( 'plan' ) ) {
			return $out;
		}

		$route          = trim( $route, '/' );
		$cache          = gend_society_plan_cache_read( $option, $id['install_id'] );
		$out['payload'] = $cache['payload'];
		if ( ! $force && is_array( $cache['payload'] ) && $cache['expires'] > time() ) {
			$out['state']  = 'ok';
			$out['source'] = 'cache';
			return $out;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $id['token'],
			'Accept'        => 'application/json',
		);
		if ( ! $force && is_array( $cache['payload'] ) && '' !== $cache['rev'] ) {
			$headers['If-None-Match'] = '"' . $cache['rev'] . '"';
		}
		$endpoint = $id['base'] . '/wp-json/gdc-app-manager/v1/install/' . rawurlencode( $id['install_id'] ) . '/' . $route;
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);
		$stale = is_array( $cache['payload'] ) ? 'stale' : 'none';
		if ( is_wp_error( $response ) ) {
			$out['state']  = 'unreachable';
			$out['source'] = $stale;
			return $out;
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$out['status'] = $code;
		// Phase 108 (CON-06): 401 shows the Reconnect notice, 200 clears it.
		if ( function_exists( 'gend_society_connection_health_note_status' ) ) {
			gend_society_connection_health_note_status( $code );
		}

		if ( 304 === $code && is_array( $cache['payload'] ) ) {
			gend_society_plan_cache_write( $option, null, $cache['rev'], time() + gend_society_plan_ttl( $cache['payload'] ), $id['install_id'] );
			$out['state']  = 'ok';
			$out['source'] = '304';
			return $out;
		}
		if ( 401 === $code ) {
			gend_society_plan_cache_expire( $option );
			$out['state']  = 'revoked';
			$out['source'] = $stale;
			return $out;
		}
		if ( 200 !== $code ) {
			$out['state']  = 'unreachable';
			$out['source'] = $stale;
			// The decoded error body (e.g. a hub area's 402 {code, state, unlock_plan}); never cached.
			$err          = json_decode( trim( (string) wp_remote_retrieve_body( $response ) ), true );
			$out['error'] = is_array( $err ) ? $err : null;
			return $out;
		}

		$raw  = trim( str_replace( "\xEF\xBB\xBF", '', (string) wp_remote_retrieve_body( $response ) ) );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$out['state']  = 'unreachable';
			$out['source'] = $stale;
			return $out;
		}
		$rev = isset( $data['rev'] ) && is_string( $data['rev'] ) ? $data['rev'] : '';
		if ( '' === $rev ) {
			$etag = (string) wp_remote_retrieve_header( $response, 'etag' );
			$rev  = trim( preg_replace( '/^W\//', '', trim( $etag ) ), '"' );
		}
		gend_society_plan_cache_write( $option, $data, $rev, time() + gend_society_plan_ttl( $data ), $id['install_id'] );
		$out['state']   = 'ok';
		$out['payload'] = in_array( $option, gend_society_plan_legacy_caches(), true ) && '' !== $rev ? array_merge( $data, array( 'rev' => $rev ) ) : $data;
		$out['source']  = 'fetch';
		return $out;
	}
}

if ( ! function_exists( 'gend_society_plan_ttl' ) ) {
	/**
	 * Cache lifetime of a payload: its cache_seconds (at least 60), 300 when absent.
	 *
	 * @param array $payload Hub payload.
	 */
	function gend_society_plan_ttl( array $payload ): int {
		return isset( $payload['cache_seconds'] ) ? max( 60, (int) $payload['cache_seconds'] ) : 5 * MINUTE_IN_SECONDS;
	}
}

if ( ! function_exists( 'gend_society_plan_features' ) ) {
	/**
	 * GET /install/{id}/features through the existing features cache (gend_society_features_cache).
	 *
	 * @param bool $force Skip the cache, no If-None-Match.
	 */
	function gend_society_plan_features( bool $force = false ): array {
		return gend_society_plan_hub_get( 'features', 'gend_society_features_cache', $force );
	}
}

if ( ! function_exists( 'gend_society_plan_membership' ) ) {
	/**
	 * GET /install/{id}/membership through the existing membership cache (gend_society_remote_membership_cache).
	 *
	 * @param bool $force Invalidate first, then fetch without conditions.
	 */
	function gend_society_plan_membership( bool $force = false ): array {
		if ( $force && function_exists( 'gend_society_remote_membership_invalidate' ) ) {
			gend_society_remote_membership_invalidate();
		}
		return gend_society_plan_hub_get( 'membership', 'gend_society_remote_membership_cache', $force );
	}
}

if ( ! function_exists( 'gend_society_plan_catalog' ) ) {
	/**
	 * GET /install/{id}/catalog (plan tiers, area matrix, after-migration list), cached in
	 * gend_society_plan_catalog_cache {payload, rev, expires, fetched_at, install_id}.
	 *
	 * @param bool $force Skip the cache, no If-None-Match.
	 */
	function gend_society_plan_catalog( bool $force = false ): array {
		return gend_society_plan_hub_get( 'catalog', 'gend_society_plan_catalog_cache', $force );
	}
}

if ( ! function_exists( 'gend_society_plan_invalidate_all' ) ) {
	/**
	 * Drop the features, membership, catalogue and hub-area caches. Payloads stay as the offline fallback,
	 * but their revisions go, so the next fetch is unconditional.
	 */
	function gend_society_plan_invalidate_all(): void {
		if ( function_exists( 'gend_society_features_invalidate' ) ) {
			gend_society_features_invalidate();
		}
		if ( function_exists( 'gend_society_remote_membership_invalidate' ) ) {
			gend_society_remote_membership_invalidate();
		}
		gend_society_plan_cache_expire( 'gend_society_features_cache', true );
		gend_society_plan_cache_expire( 'gend_society_remote_membership_cache', true );
		gend_society_plan_cache_expire( 'gend_society_plan_catalog_cache', true );
		// 1.5.x hub-area caches (the gs-hub-* pages were removed in 109-30): still deleted, so stale data never lingers.
		$areas = array( 'groups', 'feature-suite', 'projects' );
		foreach ( $areas as $area ) {
			delete_option( 'gend_society_hub_area_cache_' . sanitize_key( $area ) );
		}
		do_action( 'gend_society_plan_invalidated' );
	}
}
add_action( 'wp_login', 'gend_society_plan_invalidate_all' );
add_action( 'gend_society_plan_invalidate', 'gend_society_plan_invalidate_all' );

if ( ! function_exists( 'gend_society_plan_hub_origin' ) ) {
	/**
	 * scheme://host[:port] of the hub this install is paired with ('' when not paired).
	 */
	function gend_society_plan_hub_origin(): string {
		$p = wp_parse_url( gend_society_plan_identity()['base'] );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
	}
}

if ( ! function_exists( 'gend_society_plan_is_hub_url' ) ) {
	/**
	 * True when $url is an absolute URL on the paired hub's host and port, over https (or the scheme the hub
	 * base itself uses, for local test hubs), without credentials.
	 *
	 * @param string $url Candidate URL.
	 */
	function gend_society_plan_is_hub_url( string $url ): bool {
		if ( '' === $url || preg_match( '/[\s\x00-\x1f\\\\]/', $url ) ) {
			return false;
		}
		$base = wp_parse_url( gend_society_plan_identity()['base'] );
		$u    = wp_parse_url( $url );
		if ( ! is_array( $base ) || ! is_array( $u ) || empty( $base['host'] ) || empty( $u['host'] ) || empty( $u['scheme'] ) ) {
			return false;
		}
		if ( isset( $u['user'] ) || isset( $u['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $u['scheme'] );
		if ( 'https' !== $scheme && strtolower( (string) ( $base['scheme'] ?? '' ) ) !== $scheme ) {
			return false;
		}
		if ( 0 !== stripos( $url, $scheme . '://' ) ) {
			return false;
		}
		$defaults = array(
			'http'  => 80,
			'https' => 443,
		);
		$bport    = isset( $base['port'] ) ? (int) $base['port'] : ( $defaults[ strtolower( (string) ( $base['scheme'] ?? 'https' ) ) ] ?? 443 );
		$uport    = isset( $u['port'] ) ? (int) $u['port'] : ( $defaults[ $scheme ] ?? 0 );
		// An https URL on the default port is the same hub as an http base on its default port only in name;
		// require the exact port unless both sides use their scheme's default.
		$port_ok = ( $bport === $uport ) || ( ! isset( $base['port'] ) && ! isset( $u['port'] ) );
		return strtolower( $u['host'] ) === strtolower( $base['host'] ) && $port_ok;
	}
}

if ( ! function_exists( 'gend_society_plan_change_plan_url' ) ) {
	/**
	 * gend.me's own Change Plan page for this site's membership ('' when it cannot be built safely).
	 *
	 * Built from the hub's membership_url (GET /install/{id}/membership) + ?ui=embed&plan_only=1&group=dashboard,
	 * the page media-storage-panel.php already opens for hosting plans. Only a URL on the paired hub host is
	 * accepted, and only for a real membership id.
	 *
	 * @param array $membership /membership payload.
	 */
	function gend_society_plan_change_plan_url( array $membership ): string {
		$mid = (int) ( $membership['membership_id'] ?? 0 );
		$url = isset( $membership['membership_url'] ) && is_string( $membership['membership_url'] ) ? trim( $membership['membership_url'] ) : '';
		if ( $mid <= 0 || '' === $url || ! gend_society_plan_is_hub_url( $url ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'ui'        => 'embed',
				'plan_only' => '1',
				'group'     => 'dashboard',
			),
			$url
		);
	}
}

if ( ! function_exists( 'gend_society_plan_unlock' ) ) {
	/**
	 * Normalise an unlock_plan value to null or {id, slug, name}. A bare slug is looked up in the tiers.
	 *
	 * @param mixed $value Hub value.
	 * @param array $tiers Catalogue tiers.
	 */
	function gend_society_plan_unlock( $value, array $tiers ): ?array {
		if ( is_array( $value ) && ( ! empty( $value['id'] ) || ! empty( $value['slug'] ) ) ) {
			return array(
				'id'   => (int) ( $value['id'] ?? 0 ),
				'slug' => (string) ( $value['slug'] ?? '' ),
				'name' => (string) ( $value['name'] ?? '' ),
			);
		}
		if ( is_string( $value ) && '' !== $value ) {
			foreach ( $tiers as $t ) {
				if ( is_array( $t ) && isset( $t['slug'] ) && $value === $t['slug'] ) {
					return array(
						'id'   => (int) ( $t['id'] ?? 0 ),
						'slug' => (string) $t['slug'],
						'name' => (string) ( $t['name'] ?? '' ),
					);
				}
			}
		}
		return null;
	}
}

if ( ! function_exists( 'gend_society_plan_strings' ) ) {
	/**
	 * A list of non-empty strings from a hub value.
	 *
	 * @param mixed $list Hub value.
	 */
	function gend_society_plan_strings( $list ): array {
		$out = array();
		foreach ( (array) $list as $s ) {
			if ( is_scalar( $s ) && '' !== (string) $s ) {
				$out[] = (string) $s;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_plan_view_model' ) ) {
	/**
	 * Everything the Phase 109 templates render, from membership + features v2 + catalogue.
	 *
	 * Areas come only from the v2 areas[] list: the legacy allowed_areas [] ("allow all" on containers) is
	 * never read as an entitlement here, and a container_only area is never included on a standalone site.
	 *
	 * @param bool $force Refetch without conditions (after invalidation, e.g. the Refresh AJAX).
	 * @return array See the Phase 109 interface: state, plan, source, inherited, owner_name, processing,
	 *               areas, tiers, matrix, after_migration, change_plan_url, hub_origin, install_id,
	 *               fetched_at (+ stale).
	 */
	function gend_society_plan_view_model( bool $force = false ): array {
		$vm = array(
			'state'           => 'not_connected',
			'plan'            => array(
				'id'           => 0,
				'slug'         => '',
				'name'         => '',
				'price_label'  => '',
				'is_free'      => true,
				'is_lifetime'  => true,
				'next_renewal' => '',
				'status'       => '',
				'status_label' => '',
			),
			'source'          => 'connected',
			'inherited'       => null,
			'owner_name'      => '',
			'processing'      => false,
			'areas'           => array(),
			'tiers'           => array(),
			'matrix'          => array(),
			'after_migration' => array(),
			'change_plan_url' => '',
			'hub_origin'      => gend_society_plan_hub_origin(),
			'install_id'      => gend_society_plan_identity()['install_id'],
			'fetched_at'      => 0,
			'stale'           => false,
		);

		$f = gend_society_plan_features( $force );
		if ( 'not_connected' === $f['state'] ) {
			return $vm;
		}
		$m = gend_society_plan_membership( $force );
		$c = gend_society_plan_catalog( $force );

		$states = array( $f['state'], $m['state'], $c['state'] );
		if ( in_array( 'not_connected', $states, true ) ) {
			$vm['state'] = 'not_connected';
		} elseif ( in_array( 'revoked', $states, true ) ) {
			$vm['state'] = 'revoked';
		} elseif ( ! is_array( $f['payload'] ) || ! is_array( $m['payload'] ) ) {
			$vm['state'] = 'unreachable';
		} else {
			$vm['state'] = 'ok';
		}
		$vm['stale'] = in_array( 'unreachable', $states, true );

		$feat  = is_array( $f['payload'] ) ? $f['payload'] : array();
		$mem   = is_array( $m['payload'] ) ? $m['payload'] : array();
		$cat   = is_array( $c['payload'] ) ? $c['payload'] : array();
		$tiers = isset( $cat['tiers'] ) && is_array( $cat['tiers'] ) ? array_values( array_filter( $cat['tiers'], 'is_array' ) ) : array();

		// --- plan
		$dp     = isset( $mem['dashboard_plan'] ) && is_array( $mem['dashboard_plan'] ) ? $mem['dashboard_plan'] : array();
		$pid    = (int) ( $dp['id'] ?? ( $feat['plan_id'] ?? 0 ) );
		$pslug  = (string) ( $dp['slug'] ?? ( $feat['plan_slug'] ?? '' ) );
		$pname  = (string) ( $dp['name'] ?? ( $feat['plan_name'] ?? '' ) );
		$amount = (float) ( $dp['amount'] ?? ( $mem['billing']['amount'] ?? 0 ) );
		$dates  = isset( $mem['dates'] ) && is_array( $mem['dates'] ) ? $mem['dates'] : array();
		$life   = array_key_exists( 'is_lifetime', $mem ) ? (bool) $mem['is_lifetime'] : ( '' === (string) ( $dates['expires'] ?? '' ) );
		$price  = '';
		foreach ( $tiers as $t ) {
			if ( ( $pid && (int) ( $t['id'] ?? 0 ) === $pid ) || ( '' !== $pslug && ( $t['slug'] ?? '' ) === $pslug ) ) {
				$price = (string) ( $t['price_label'] ?? '' );
			}
		}
		if ( '' === $price ) {
			$price = (string) ( $dp['amount_label'] ?? ( $mem['billing']['label'] ?? '' ) );
		}
		$vm['plan'] = array(
			'id'           => $pid,
			'slug'         => $pslug,
			'name'         => $pname,
			'price_label'  => $amount <= 0 ? __( 'Free', 'gend-society' ) : $price,
			'is_free'      => $amount <= 0,
			'is_lifetime'  => $life,
			'next_renewal' => $life ? '' : (string) ( $dates['next_renewal'] ?? ( $dates['expires'] ?? '' ) ),
			'status'       => (string) ( $mem['status'] ?? '' ),
			'status_label' => (string) ( $mem['status_label'] ?? '' ),
		);

		// --- source, inheritance, owner, processing
		$source       = (string) ( $mem['plan_source'] ?? ( $feat['plan_source'] ?? 'connected' ) );
		$vm['source'] = in_array( $source, array( 'site', 'group', 'connected', 'override' ), true ) ? $source : 'connected';
		$inh          = $mem['inherited_from'] ?? ( $feat['inherited_from'] ?? null );
		if ( is_array( $inh ) && 'group' === $vm['source'] ) {
			$vm['inherited'] = array(
				'group_name' => (string) ( $inh['group_name'] ?? '' ),
				'plan_name'  => (string) ( $inh['plan_name'] ?? '' ),
				'plan_slug'  => (string) ( $inh['plan_slug'] ?? '' ),
			);
		}
		$vm['owner_name'] = (string) ( $mem['owner']['display_name'] ?? ( $mem['customer']['name'] ?? '' ) );
		$lu               = isset( $mem['last_upgrade'] ) && is_array( $mem['last_upgrade'] ) ? $mem['last_upgrade'] : null;
		if ( $lu ) {
			$status           = preg_replace( '/^wc-/', '', strtolower( (string) ( $lu['order_status'] ?? '' ) ) );
			// In flight = not paid yet. The hub swaps the plan in the request that marks the order paid (WC
			// "processing"), so a paid order for another plan is an older upgrade, not a pending one.
			$vm['processing'] = in_array( $status, array( 'pending', 'on-hold' ), true ) && (int) ( $lu['plan_id'] ?? 0 ) !== $pid;
		}

		// --- areas (v2 only)
		foreach ( (array) ( $feat['areas'] ?? array() ) as $a ) {
			if ( ! is_array( $a ) || empty( $a['slug'] ) ) {
				continue;
			}
			$slug     = sanitize_key( (string) $a['slug'] );
			$delivery = 'hub_embed' === ( $a['delivery'] ?? '' ) ? 'hub_embed' : 'container_only';
			$state    = (string) ( $a['state'] ?? '' );
			if ( 'container_only' === $delivery ) {
				$state = 'after_migration';
			} elseif ( ! in_array( $state, array( 'included', 'upgrade', 'after_migration' ), true ) ) {
				$state = 'upgrade';
			}
			$vm['areas'][] = array(
				'slug'        => $slug,
				'label'       => (string) ( $a['label'] ?? $slug ),
				'delivery'    => $delivery,
				'state'       => $state,
				'unlock_plan' => 'included' === $state ? null : gend_society_plan_unlock( $a['unlock_plan'] ?? null, $tiers ),
				'features'    => gend_society_plan_strings( $a['features'] ?? array() ),
				// 109-30 (D29): the gs-hub-<area> pages are gone; included hub areas open on gend.me (Integration Hub card).
				'admin_url'   => '',
			);
		}

		// --- tiers
		$allowed_ids  = isset( $mem['checkout_allowed_plan_ids'] ) && is_array( $mem['checkout_allowed_plan_ids'] ) ? array_map( 'intval', $mem['checkout_allowed_plan_ids'] ) : null;
		$inherit_rank = 0;
		foreach ( $tiers as $t ) {
			if ( ! empty( $t['is_inherited'] ) || ( $vm['inherited'] && '' !== $vm['inherited']['plan_slug'] && ( $t['slug'] ?? '' ) === $vm['inherited']['plan_slug'] ) ) {
				$inherit_rank = max( $inherit_rank, (int) ( $t['rank'] ?? 0 ) );
			}
		}
		foreach ( $tiers as $t ) {
			$tid     = (int) ( $t['id'] ?? 0 );
			$rank    = (int) ( $t['rank'] ?? 0 );
			$current = ! empty( $t['is_current'] ) || ( $tid && $tid === $pid );
			$ok      = $tid > 0 && ! empty( $t['checkout_allowed'] ) && ! $current
				&& ( null === $allowed_ids || in_array( $tid, $allowed_ids, true ) )
				&& ( 0 === $inherit_rank || $rank > $inherit_rank );
			$vm['tiers'][] = array(
				'id'               => $tid,
				'slug'             => (string) ( $t['slug'] ?? '' ),
				'name'             => (string) ( $t['name'] ?? '' ),
				'price_label'      => (string) ( $t['price_label'] ?? '' ),
				'rank'             => $rank,
				'is_current'       => $current,
				'is_inherited'     => ! empty( $t['is_inherited'] ),
				'checkout_allowed' => $ok,
			);
		}
		usort(
			$vm['tiers'],
			function ( $a, $b ) {
				return $a['rank'] <=> $b['rank'];
			}
		);

		// --- comparison matrix + after-migration list
		foreach ( (array) ( $cat['matrix'] ?? array() ) as $tier_slug => $row ) {
			foreach ( (array) $row as $area => $cell ) {
				$vm['matrix'][ (string) $tier_slug ][ (string) $area ] = 'included' === $cell ? 'included' : 'no';
			}
		}
		foreach ( (array) ( $cat['after_migration'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['area'] ) ) {
				$vm['after_migration'][] = array(
					'area'     => (string) $row['area'],
					'label'    => (string) ( $row['label'] ?? $row['area'] ),
					'features' => gend_society_plan_strings( $row['features'] ?? array() ),
				);
			}
		}
		if ( ! $vm['after_migration'] ) {
			foreach ( $vm['areas'] as $a ) {
				if ( 'after_migration' === $a['state'] ) {
					$vm['after_migration'][] = array(
						'area'     => $a['slug'],
						'label'    => $a['label'],
						'features' => $a['features'],
					);
				}
			}
		}

		// --- gend.me's own Change Plan page (opened in a popup by the templates)
		$vm['change_plan_url'] = gend_society_plan_change_plan_url( $mem );

		$fetched          = (int) get_option( 'gend_society_features_cache_fetched_at', 0 );
		$vm['fetched_at'] = $fetched > 0 ? $fetched : time();
		return $vm;
	}
}

if ( ! function_exists( 'gend_society_plan_disconnect_billing' ) ) {
	/**
	 * The paid plan that keeps billing on gend.me after a Disconnect (109 D23), read from the cached
	 * membership only: no request is made, so the Connect screen renders offline as before.
	 *
	 * Returns null for a free / Connected membership, a plan inherited from the business group (the group
	 * pays for it, not this site's membership), an unpaired install, or no cached membership. Otherwise {plan_name, membership_url}; membership_url is the hub's own membership page,
	 * only when it is on the paired hub host ('' otherwise). No token or nonce is ever part of it.
	 *
	 * @return array{plan_name:string,membership_url:string}|null
	 */
	function gend_society_plan_disconnect_billing(): ?array {
		$id = gend_society_plan_identity();
		if ( '' === $id['install_id'] ) {
			return null;
		}
		$mem = gend_society_plan_cache_read( 'gend_society_remote_membership_cache', $id['install_id'] )['payload'];
		if ( ! is_array( $mem ) ) {
			return null;
		}
		if ( 'group' === (string) ( $mem['plan_source'] ?? '' ) ) {
			return null;
		}
		$dp     = isset( $mem['dashboard_plan'] ) && is_array( $mem['dashboard_plan'] ) ? $mem['dashboard_plan'] : array();
		$amount = isset( $mem['billing']['amount'] ) ? (float) $mem['billing']['amount'] : (float) ( $dp['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return null;
		}
		$name = trim( (string) ( $dp['name'] ?? '' ) );
		$url  = isset( $mem['membership_url'] ) && is_string( $mem['membership_url'] ) ? trim( $mem['membership_url'] ) : '';
		return array(
			'plan_name'      => '' !== $name ? $name : __( 'paid', 'gend-society' ),
			'membership_url' => '' !== $url && gend_society_plan_is_hub_url( $url ) ? $url : '',
		);
	}
}

// ─── Refresh AJAX (Refresh button, popup close / checkout completion) ──────────────────────────────────

if ( ! function_exists( 'gend_society_plan_refresh_ajax' ) ) {
	/**
	 * AJAX gend_society_plan_refresh: invalidate every plan cache and return the fresh view-model plus HTML
	 * fragments other modules add through the gend_society_plan_refresh_html filter.
	 */
	function gend_society_plan_refresh_ajax(): void {
		if ( ! check_ajax_referer( 'gs_plan', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'bad_nonce',
					'message' => __( 'This page has expired. Reload it and try again.', 'gend-society' ),
				),
				403
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You are not allowed to manage this site\'s plan.', 'gend-society' ),
				),
				403
			);
		}
		gend_society_plan_invalidate_all();
		$vm   = gend_society_plan_view_model( true );
		$html = apply_filters( 'gend_society_plan_refresh_html', array(), $vm );
		wp_send_json_success(
			array(
				'view_model' => $vm,
				'html'       => (object) ( is_array( $html ) ? $html : array() ),
			)
		);
	}
}
add_action( 'wp_ajax_gend_society_plan_refresh', 'gend_society_plan_refresh_ajax' );
