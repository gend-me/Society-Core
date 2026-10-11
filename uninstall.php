<?php
/**
 * Uninstall cleanup for GenD Society.
 *
 * Runs when an administrator deletes the plugin from the Plugins screen. On a
 * standalone (self-hosted) install it removes the plugin's own data:
 *   - options named gend_society_* and the legacy gs_* names in the key map
 *     (this includes the Ed25519 keypair and the install token),
 *   - its transients,
 *   - its user meta (key map names, old and new, plus gend_society_* keys),
 *   - its scheduled cron hooks,
 *   - on multisite: the same on every site, then its network options.
 *
 * It never deletes posts, pages, comments, terms, media, post meta, menus,
 * theme files, theme mods or the active-theme options, and never touches keys
 * owned by other plugins (the key map lists gend-society-owned keys only).
 *
 * On the gend.me hub and on gend.me containers this file does nothing: their
 * pairing (install id, token, keypair) is provisioned by the platform, and
 * removing it here would cut the site off from gend.me.
 *
 * @package gend-society
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Dependency-free (WordPress core only), so it is safe without the plugin loaded.
require_once __DIR__ . '/inc/bootstrap/context.php';

if ( 'standalone' !== gend_society_runtime_mode() ) {
	return;
}

if ( ! function_exists( 'gend_society_uninstall_key_map' ) ) {
	/**
	 * The generated key map (legacy gs_* => gend_society_* names).
	 *
	 * @return array<string,mixed>
	 */
	function gend_society_uninstall_key_map(): array {
		static $gend_society_map = null;
		if ( null === $gend_society_map ) {
			$gend_society_file = __DIR__ . '/inc/bootstrap/key-map.php';
			$gend_society_map  = is_readable( $gend_society_file ) ? require $gend_society_file : array();
			if ( ! is_array( $gend_society_map ) ) {
				$gend_society_map = array();
			}
		}
		return $gend_society_map;
	}
}

if ( ! function_exists( 'gend_society_uninstall_names' ) ) {
	/**
	 * Old and new names from one key-map section.
	 *
	 * @param string $section Key-map section (option, user_meta, cron, ...).
	 * @return string[]
	 */
	function gend_society_uninstall_names( string $section ): array {
		$gend_society_map = gend_society_uninstall_key_map();
		if ( empty( $gend_society_map[ $section ] ) || ! is_array( $gend_society_map[ $section ] ) ) {
			return array();
		}
		$gend_society_names = array();
		foreach ( $gend_society_map[ $section ] as $gend_society_old => $gend_society_new ) {
			$gend_society_names[] = (string) $gend_society_old;
			$gend_society_names[] = (string) $gend_society_new;
		}
		return array_values( array_unique( array_filter( $gend_society_names, 'strlen' ) ) );
	}
}

if ( ! function_exists( 'gend_society_uninstall_like' ) ) {
	/**
	 * Names in one table column that start with a prefix.
	 *
	 * @param string $table  Table name (a $wpdb property value).
	 * @param string $column Column holding the name.
	 * @param string $prefix Literal prefix.
	 * @return string[]
	 */
	function gend_society_uninstall_like( string $table, string $column, string $prefix ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall: enumerate this plugin's prefixed keys; table/column are fixed $wpdb names, the prefix is prepared.
		$gend_society_rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		return is_array( $gend_society_rows ) ? array_map( 'strval', $gend_society_rows ) : array();
	}
}

if ( ! function_exists( 'gend_society_uninstall_site' ) ) {
	/**
	 * Remove this plugin's options, transients and cron hooks on the current site.
	 */
	function gend_society_uninstall_site(): void {
		global $wpdb;

		// Option prefixes: the plugin's own prefix plus key-map option_prefix entries (old and new).
		$gend_society_prefixes = array_merge( array( 'gend_society_' ), gend_society_uninstall_names( 'option_prefix' ) );
		$gend_society_options  = gend_society_uninstall_names( 'option' );
		foreach ( $gend_society_prefixes as $gend_society_prefix ) {
			$gend_society_options = array_merge( $gend_society_options, gend_society_uninstall_like( $wpdb->options, 'option_name', $gend_society_prefix ) );
		}
		foreach ( array_unique( $gend_society_options ) as $gend_society_option ) {
			delete_option( $gend_society_option );
		}

		// Transients: gend_society_* plus key-map transient prefixes (old and new).
		$gend_society_transient_prefixes = array_merge( array( 'gend_society_' ), gend_society_uninstall_names( 'transient_read_once' ) );
		foreach ( $gend_society_transient_prefixes as $gend_society_prefix ) {
			foreach ( gend_society_uninstall_like( $wpdb->options, 'option_name', '_transient_' . $gend_society_prefix ) as $gend_society_row ) {
				delete_transient( substr( $gend_society_row, strlen( '_transient_' ) ) );
			}
			foreach ( gend_society_uninstall_like( $wpdb->options, 'option_name', '_transient_timeout_' . $gend_society_prefix ) as $gend_society_row ) {
				delete_transient( substr( $gend_society_row, strlen( '_transient_timeout_' ) ) );
			}
		}

		// Cron: every event of each hook, whatever its arguments.
		$gend_society_hooks = array_merge(
			array( 'gend_society_feature_state_daily', 'gend_society_mail_storage_report' ),
			gend_society_uninstall_names( 'cron' )
		);
		foreach ( array_unique( $gend_society_hooks ) as $gend_society_hook ) {
			wp_unschedule_hook( $gend_society_hook );
		}
	}
}

if ( ! function_exists( 'gend_society_uninstall_users' ) ) {
	/**
	 * Remove this plugin's user meta (users are network-wide, so once).
	 */
	function gend_society_uninstall_users(): void {
		global $wpdb;

		$gend_society_keys = array_merge(
			gend_society_uninstall_names( 'user_meta' ),
			array( 'gend_society_theme_notice_dismissed' )
		);
		$gend_society_prefixes = array_merge(
			array( 'gend_society_', '_gend_society_' ),
			gend_society_uninstall_names( 'user_meta_prefix' )
		);
		foreach ( $gend_society_prefixes as $gend_society_prefix ) {
			$gend_society_keys = array_merge( $gend_society_keys, gend_society_uninstall_like( $wpdb->usermeta, 'meta_key', $gend_society_prefix ) );
		}
		foreach ( array_unique( $gend_society_keys ) as $gend_society_key ) {
			delete_metadata( 'user', 0, $gend_society_key, '', true );
		}
	}
}

if ( ! function_exists( 'gend_society_uninstall_network' ) ) {
	/**
	 * Remove this plugin's network (site) options and site transients.
	 */
	function gend_society_uninstall_network(): void {
		global $wpdb;

		$gend_society_names = gend_society_uninstall_names( 'site_option' );
		$gend_society_names = array_merge( $gend_society_names, gend_society_uninstall_like( $wpdb->sitemeta, 'meta_key', 'gend_society_' ) );
		foreach ( array_unique( $gend_society_names ) as $gend_society_name ) {
			delete_site_option( $gend_society_name );
		}
		foreach ( gend_society_uninstall_like( $wpdb->sitemeta, 'meta_key', '_site_transient_gend_society_' ) as $gend_society_row ) {
			delete_site_transient( substr( $gend_society_row, strlen( '_site_transient_' ) ) );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $gend_society_blog_id ) {
		switch_to_blog( (int) $gend_society_blog_id );
		gend_society_uninstall_site();
		restore_current_blog();
	}
	gend_society_uninstall_network();
} else {
	gend_society_uninstall_site();
}
gend_society_uninstall_users();
