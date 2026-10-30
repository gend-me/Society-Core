<?php
/**
 * Preview marker + noindex for a migrated customer container (Phase 111-10, MIG-09, P1, P6).
 *
 * After a migration verifies, the container is in Preview: reachable on its temporary host while the customer's domain
 * still points at the original site. A Preview copy must not be indexed, and it must stop being noindex the moment the
 * site goes Live.
 *
 *   - The marker: the container's own option gend_society_container_preview (value = the time it was set). It is set
 *     the first time an admin-side membership fetch shows migration.preview (the importer image has no hook into this
 *     plugin, so this is the container's first chance to learn it). A non-migrated container never has it.
 *   - noindex (send_headers X-Robots-Tag + wp_robots) is sent when the cached payload says the request host is the
 *     preview_url host and migration.live is false, or, with no cached migration block, only while the marker is set.
 *     There is NO host-pattern fallback (a *.gend.me host alone never makes a page noindex).
 *   - Clearing: the marker goes when Live is learned, either from the verified-domains record the 5-minute WP-Cron sync
 *     (container-host-urls.php) stores (field live), or from the first admin-side membership fetch showing
 *     migration.live. Nothing here makes a network call on a front-end request: the front end only reads options.
 *   - The durable flag gend_society_container_imported records that this container was imported (set with the marker,
 *     never cleared) so the Domains panel still knows after Live that the cutover rules apply.
 *
 * Container runtime only. Excluded from the wordpress.org build (bin/dist/wporg.exclude, container tier).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_CONTAINER_PREVIEW_OPTION  = 'gend_society_container_preview';
const GEND_SOCIETY_CONTAINER_IMPORTED_OPTION = 'gend_society_container_imported';

if ( ! function_exists( 'gend_society_preview_enabled' ) ) {
	/** Container runtime only (a filter lets tests force it). */
	function gend_society_preview_enabled(): bool {
		$on = function_exists( 'gend_society_runtime_mode' ) && 'container' === gend_society_runtime_mode();
		return (bool) apply_filters( 'gend_society_preview_enabled', $on );
	}
}

if ( ! function_exists( 'gend_society_preview_marker_set' ) ) {
	/** Is the container's own Preview marker set? */
	function gend_society_preview_marker_set(): bool {
		return (bool) get_option( GEND_SOCIETY_CONTAINER_PREVIEW_OPTION, false );
	}
}

if ( ! function_exists( 'gend_society_preview_noindex_active' ) ) {
	/**
	 * Should this request be noindex? Reads options only (the stored payload ignoring its expiry), never fetches.
	 */
	function gend_society_preview_noindex_active(): bool {
		if ( ! gend_society_preview_enabled() ) {
			return false;
		}
		$marker = gend_society_preview_marker_set();
		$cache  = get_option( 'gend_society_remote_membership_cache', null );
		$mig    = is_array( $cache ) && is_array( $cache['migration'] ?? null ) ? $cache['migration'] : null;
		if ( null !== $mig && ( ! empty( $mig['live'] ) ) ) {
			return false; // Live is authoritative, even if the marker has not been cleared yet.
		}
		if ( null !== $mig && ! empty( $mig['preview_url'] ) && is_string( $mig['preview_url'] ) ) {
			$want = wp_parse_url( $mig['preview_url'], PHP_URL_HOST );
			$have = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d{1,5}$/', '', strtolower( trim( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never output.
			if ( is_string( $want ) && '' !== $want && is_string( $have ) && strtolower( $want ) === $have ) {
				return true;
			}
		}
		return $marker;
	}
}

if ( ! function_exists( 'gend_society_preview_send_headers' ) ) {
	function gend_society_preview_send_headers(): void {
		if ( is_admin() || ! gend_society_preview_noindex_active() ) {
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
}
add_action( 'send_headers', 'gend_society_preview_send_headers' );

if ( ! function_exists( 'gend_society_preview_wp_robots' ) ) {
	/**
	 * @param array $robots wp_robots directives.
	 * @return array
	 */
	function gend_society_preview_wp_robots( $robots ) {
		if ( ! is_array( $robots ) || ! gend_society_preview_noindex_active() ) {
			return $robots;
		}
		unset( $robots['index'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}
}
add_filter( 'wp_robots', 'gend_society_preview_wp_robots', 99 );

if ( ! function_exists( 'gend_society_preview_learn' ) ) {
	/**
	 * Admin-side membership fetch: set the marker on Preview, clear it on Live. Only a logged-in manager's request
	 * counts, so a visitor-triggered fetch can never flip the state.
	 *
	 * @param array $payload The fetched /membership payload.
	 */
	function gend_society_preview_learn( $payload ): void {
		if ( ! gend_society_preview_enabled() || ! is_array( $payload ) || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mig = is_array( $payload['migration'] ?? null ) ? $payload['migration'] : array();
		if ( ! empty( $mig['live'] ) ) {
			if ( gend_society_preview_marker_set() ) {
				delete_option( GEND_SOCIETY_CONTAINER_PREVIEW_OPTION );
			}
			return;
		}
		if ( ! empty( $mig['preview'] ) ) {
			if ( ! gend_society_preview_marker_set() ) {
				update_option( GEND_SOCIETY_CONTAINER_PREVIEW_OPTION, time(), true );
			}
			if ( ! get_option( GEND_SOCIETY_CONTAINER_IMPORTED_OPTION, false ) ) {
				update_option( GEND_SOCIETY_CONTAINER_IMPORTED_OPTION, 1, true );
			}
		}
	}
}
add_action( 'gend_society_remote_membership_fetched', 'gend_society_preview_learn', 10, 1 );

if ( ! function_exists( 'gend_society_preview_clear_from_sync' ) ) {
	/**
	 * After the 5-minute verified-domains sync (container-host-urls.php, priority 10) has stored the record, clear the
	 * marker when that record says Live. Reads the stored option only.
	 */
	function gend_society_preview_clear_from_sync(): void {
		if ( ! gend_society_preview_marker_set() || ! function_exists( 'gend_society_host_urls_state' ) ) {
			return;
		}
		$state = gend_society_host_urls_state();
		if ( ! empty( $state['live'] ) ) {
			delete_option( GEND_SOCIETY_CONTAINER_PREVIEW_OPTION );
		}
	}
}
add_action( 'gend_society_verified_domains_sync', 'gend_society_preview_clear_from_sync', 20 );
add_action( 'admin_init', 'gend_society_preview_clear_from_sync', 30 );
