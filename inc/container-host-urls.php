<?php
/**
 * Host-keyed home / siteurl for a customer container (Phase 111-06, P7, MIG-10).
 *
 * A migrated container is first reached on its temporary gend.me host (the Preview) and later on the customer's own
 * domain. This file lets the container answer with whichever verified host the request came in on, with no database URL
 * rewrite at Live: the preview host keeps working until the cutover and the custom host is served without a canonical
 * redirect back to the temporary host. The stored home / siteurl options are never written.
 *
 *   - Which hosts count: the durable option gend_society_verified_domains = { domains: [...], live, canonical_host,
 *     synced_at }, written from the hub route GET /install/{id}/domains/verified.
 *   - Front-end rule: a front-end request ONLY reads that option. It never makes a network call, so arbitrary Host
 *     headers cannot trigger outbound requests, and there is no synchronous fetch and no failure lock.
 *   - Refresh happens from WP-Cron (every 5 minutes) and from an admin-side fetch (a logged-in manager in wp-admin,
 *     at most once a minute). The hub base URL and install token come from the stored install record
 *     (gend_society_gend_base_url / gend_society_install_token), never from the request. Consequence: a brand-new
 *     verified domain can take up to 5 minutes to start working in the container.
 *
 * Container runtime only. Excluded from the wordpress.org build (bin/dist/wporg.exclude).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_VERIFIED_DOMAINS_OPTION = 'gend_society_verified_domains';
const GEND_SOCIETY_VERIFIED_DOMAINS_HOOK   = 'gend_society_verified_domains_sync';

if ( ! function_exists( 'gend_society_host_urls_enabled' ) ) {
	/**
	 * Is the Host-keyed URL filter active? Container runtime only (the filter lets tests force it).
	 */
	function gend_society_host_urls_enabled(): bool {
		$on = function_exists( 'gend_society_runtime_mode' ) && 'container' === gend_society_runtime_mode();
		return (bool) apply_filters( 'gend_society_host_urls_enabled', $on );
	}
}

if ( ! function_exists( 'gend_society_host_urls_valid_host' ) ) {
	/**
	 * A lower-case DNS host name without port, or '' when the value is not one.
	 *
	 * @param mixed $value Raw host.
	 */
	function gend_society_host_urls_valid_host( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$host = strtolower( trim( $value ) );
		$host = preg_replace( '/:\d{1,5}$/', '', $host );
		if ( ! is_string( $host ) || '' === $host || strlen( $host ) > 253 ) {
			return '';
		}
		return (bool) preg_match( '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ? $host : '';
	}
}

if ( ! function_exists( 'gend_society_host_urls_state' ) ) {
	/**
	 * The stored verified-domains record (durable option, read only).
	 *
	 * @return array{domains:string[],live:bool,canonical_host:string,synced_at:int}
	 */
	function gend_society_host_urls_state(): array {
		$raw   = get_option( GEND_SOCIETY_VERIFIED_DOMAINS_OPTION, array() );
		$raw   = is_array( $raw ) ? $raw : array();
		$hosts = array();
		foreach ( (array) ( $raw['domains'] ?? array() ) as $h ) {
			$h = gend_society_host_urls_valid_host( $h );
			if ( '' !== $h ) {
				$hosts[] = $h;
			}
		}
		return array(
			'domains'        => array_values( array_unique( $hosts ) ),
			'live'           => ! empty( $raw['live'] ),
			'canonical_host' => gend_society_host_urls_valid_host( $raw['canonical_host'] ?? '' ),
			'synced_at'      => (int) ( $raw['synced_at'] ?? 0 ),
		);
	}
}

if ( ! function_exists( 'gend_society_host_urls_request_host' ) ) {
	/** The host the request came in on, validated ('' for CLI / cron / invalid values). */
	function gend_society_host_urls_request_host(): string {
		if ( empty( $_SERVER['HTTP_HOST'] ) || ! is_string( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		return gend_society_host_urls_valid_host( wp_unslash( $_SERVER['HTTP_HOST'] ) );
	}
}

if ( ! function_exists( 'gend_society_host_urls_filter' ) ) {
	/**
	 * pre_option_home / pre_option_siteurl. Returns https://<request host><stored path> when the request host is a
	 * verified customer host that differs from the stored address; every other request is left alone ($pre untouched).
	 * The stored option is read through a re-entrancy flag so this filter does not recurse into itself.
	 *
	 * @param mixed  $pre    Short-circuit value (false = none).
	 * @param string $option Option name (passed as the 3rd argument by WordPress >= 4.4 / 6.x).
	 */
	function gend_society_host_urls_filter( $pre, $option = '' ) {
		static $busy = false;
		if ( $busy ) {
			return $pre;
		}
		if ( false !== $pre && '' === (string) $option ) {
			return $pre;
		}
		$host = gend_society_host_urls_request_host();
		if ( '' === $host || ! gend_society_host_urls_enabled() ) {
			return $pre;
		}
		$name = '' !== (string) $option ? (string) $option : current_filter();
		$name = 0 === strpos( $name, 'pre_option_' ) ? substr( $name, 11 ) : $name;
		if ( 'home' !== $name && 'siteurl' !== $name ) {
			return $pre;
		}
		$state = gend_society_host_urls_state();
		if ( ! in_array( $host, $state['domains'], true ) ) {
			return $pre;
		}
		$busy = true;
		try {
			$stored = get_option( $name );
		} finally {
			$busy = false;
		}
		$stored = is_string( $stored ) ? $stored : '';
		$parts  = wp_parse_url( $stored );
		if ( is_array( $parts ) && isset( $parts['host'] ) && strtolower( (string) $parts['host'] ) === $host ) {
			return $pre; // already the stored address
		}
		$path = is_array( $parts ) && isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		return 'https://' . $host . $path;
	}
}
// Priority 20: after WordPress' own WP_HOME / WP_SITEURL constant filter (priority 10), so a constant is the "stored" value.
add_filter( 'pre_option_home', 'gend_society_host_urls_filter', 20, 3 );
add_filter( 'pre_option_siteurl', 'gend_society_host_urls_filter', 20, 3 );

if ( ! function_exists( 'gend_society_host_urls_sync' ) ) {
	/**
	 * Fetch the verified domains from the hub and store them. NEVER called from a front-end request: only WP-Cron and
	 * the admin-side hook below call it. The base URL, install id and install token are the stored install record.
	 * On any failure the stored record is kept as it is (no lock, the next run simply tries again).
	 *
	 * @return bool True when the record was refreshed.
	 */
	function gend_society_host_urls_sync(): bool {
		if ( ! gend_society_host_urls_enabled() ) {
			return false;
		}
		if ( function_exists( 'gend_society_remote_allowed' ) && ! gend_society_remote_allowed( 'membership' ) ) {
			return false;
		}
		$install_id = (string) get_option( 'gend_society_install_id', '' );
		$token      = (string) get_option( 'gend_society_install_token', '' );
		$base       = (string) get_option( 'gend_society_gend_base_url', '' );
		if ( '' === $install_id || '' === $token || '' === $base ) {
			return false;
		}
		$url = trailingslashit( $base ) . 'wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/domains/verified';
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || ! isset( $data['domains'] ) || ! is_array( $data['domains'] ) ) {
			return false;
		}
		$hosts = array();
		foreach ( array_slice( $data['domains'], 0, 50 ) as $h ) {
			$h = gend_society_host_urls_valid_host( $h );
			if ( '' !== $h ) {
				$hosts[] = $h;
			}
		}
		update_option(
			GEND_SOCIETY_VERIFIED_DOMAINS_OPTION,
			array(
				'domains'        => array_values( array_unique( $hosts ) ),
				'live'           => ! empty( $data['live'] ),
				'canonical_host' => gend_society_host_urls_valid_host( $data['canonical_host'] ?? '' ),
				'synced_at'      => time(),
			),
			true
		);
		return true;
	}
}
add_action( GEND_SOCIETY_VERIFIED_DOMAINS_HOOK, 'gend_society_host_urls_sync' );

if ( ! function_exists( 'gend_society_host_urls_cron_schedules' ) ) {
	function gend_society_host_urls_cron_schedules( $schedules ) {
		$schedules['gend_society_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Every five minutes',
		);
		return $schedules;
	}
}
add_filter( 'cron_schedules', 'gend_society_host_urls_cron_schedules' );

if ( ! function_exists( 'gend_society_host_urls_schedule' ) ) {
	/** Make sure the 5-minute sync is scheduled (a cron-table read; no network). */
	function gend_society_host_urls_schedule(): void {
		if ( gend_society_host_urls_enabled() && ! wp_next_scheduled( GEND_SOCIETY_VERIFIED_DOMAINS_HOOK ) ) {
			wp_schedule_event( time() + 60, 'gend_society_five_minutes', GEND_SOCIETY_VERIFIED_DOMAINS_HOOK );
		}
	}
}
add_action( 'init', 'gend_society_host_urls_schedule' );

if ( ! function_exists( 'gend_society_host_urls_admin_sync' ) ) {
	/**
	 * Admin-side refresh: a logged-in manager loading wp-admin, at most once a minute. admin-ajax requests from visitors
	 * are is_admin() too, so the capability check comes first.
	 */
	function gend_society_host_urls_admin_sync(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = gend_society_host_urls_state();
		if ( time() - $state['synced_at'] < 60 ) {
			return;
		}
		gend_society_host_urls_sync();
	}
}
add_action( 'admin_init', 'gend_society_host_urls_admin_sync' );
