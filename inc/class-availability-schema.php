<?php
/**
 * gend-society — Availability + Meetings schema installer (Phase 28-01).
 *
 * Multisite-aware installer for the v7.0 Member Calendar — installs BOTH
 *   wp_gs_member_availability  (Plan 28-02 REST writes rows)
 *   wp_gs_member_meetings      (Phase 29 writes rows)
 * in ONE version-gated dbDelta routine. Phase 29 needs zero migration.
 *
 * Multisite triple-coverage (Pitfall 3):
 *   1. register_activation_hook → install_all_sites() loops get_sites()
 *      (handles EXISTING blogs at the activation moment).
 *   2. wp_initialize_site → install_new_site() handles future-created blogs.
 *   3. init priority 5 → maybe_install() self-heals any blog whose option is
 *      missing or stale (handles the case where the hub is live-patched
 *      without re-activation — belt-and-braces).
 *
 * Activation safety (Pitfall 2): every public entrypoint wraps the install
 * call in try/catch — if dbDelta throws, the failure is error_log'd and
 * the calling context (activation, init, wp_initialize_site) continues so
 * WordPress does NOT auto-deactivate gend-society.
 *
 * Per-blog version option (`gs_calendar_db_version`, NOT a network option) so
 * each subsite tracks its own schema version independently — matches Pitfall 3
 * self-heal requirement.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Availability_Schema {

	/**
	 * Schema version. Bump on any dbDelta change; maybe_install() will
	 * re-run dbDelta on every blog whose option is below this value.
	 */
	const DB_VERSION     = '1.0.0';
	const DB_VERSION_OPT = 'gs_calendar_db_version';

	/**
	 * Wire all hooks. Called from gend-society.php after the require_once
	 * (this file is silent at include time — all side effects land via init()).
	 */
	public static function init() : void {
		// Multisite: future-created blogs (WP 5.1+).
		add_action( 'wp_initialize_site', array( __CLASS__, 'install_new_site' ), 10, 1 );

		// Self-heal: any blog where the option is missing/stale gets dbDelta
		// on the next request. init priority 5 runs BEFORE anything that
		// might query these tables (Plan 28-02 REST routes assume the
		// availability table exists; the meetings adapter in
		// calendar-events-rest.php has its own SHOW TABLES guard but the
		// 28-02 writes do not).
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );

		// Network/per-site activation: existing blogs at the activation
		// moment. register_activation_hook is keyed on the PLUGIN ENTRYPOINT
		// path — passed in via GS_DIR since __FILE__ here resolves to the
		// include, not the main plugin file.
		register_activation_hook(
			GS_DIR . 'gend-society.php',
			array( __CLASS__, 'install_all_sites' )
		);
	}

	/**
	 * Per-blog version-gated installer. Idempotent — safe to call on every
	 * init request. Reads the PER-BLOG option (NOT a network option) so each
	 * subsite tracks its own schema version independently.
	 *
	 * Wrapped in try/catch (Pitfall 2) so dbDelta failure NEVER fatals the
	 * request — error_log only; WP does not auto-deactivate gend-society.
	 */
	public static function maybe_install() : void {
		// Version-gate: skip if already at current version.
		if ( get_option( self::DB_VERSION_OPT ) === self::DB_VERSION ) {
			return;
		}
		try {
			self::install_tables();
			update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
		} catch ( \Throwable $e ) {
			// Pitfall 2: NEVER fatal — error_log and continue.
			error_log(
				'[gs_availability_schema] maybe_install failed on blog '
				. get_current_blog_id() . ': ' . $e->getMessage()
			);
		}
	}

	/**
	 * Activation hook handler — loops every existing site and installs.
	 * Wrapped in try/catch per blog so one bad blog doesn't fatal activation
	 * for the rest (Pitfall 2 / Pitfall 3).
	 *
	 * get_sites( array( 'number' => 0 ) ): 0 = unlimited per WP source —
	 * necessary for hub clusters with many subsites; default 100 cap would
	 * silently skip blogs.
	 */
	public static function install_all_sites() : void {
		if ( ! function_exists( 'get_sites' ) || ! function_exists( 'switch_to_blog' ) ) {
			// Single-site fallback.
			try {
				self::install_tables();
				update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
			} catch ( \Throwable $e ) {
				error_log( '[gs_availability_schema] single-site activate failed: ' . $e->getMessage() );
			}
			return;
		}

		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			$blog_id = (int) ( is_object( $site ) ? $site->blog_id : ( isset( $site['blog_id'] ) ? $site['blog_id'] : 0 ) );
			if ( $blog_id <= 0 ) {
				continue;
			}
			try {
				switch_to_blog( $blog_id );
				self::install_tables();
				update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
			} catch ( \Throwable $e ) {
				error_log( '[gs_availability_schema] activate failed on blog ' . $blog_id . ': ' . $e->getMessage() );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * wp_initialize_site handler (WP 5.1+). Called when a NEW subsite is
	 * created via wp_insert_site() / wp-cli site create.
	 *
	 * @param WP_Site|object $new_site WP_Site instance for the new blog.
	 */
	public static function install_new_site( $new_site ) : void {
		$blog_id = is_object( $new_site ) ? (int) $new_site->blog_id : 0;
		if ( $blog_id <= 0 ) {
			return;
		}
		try {
			switch_to_blog( $blog_id );
			self::install_tables();
			update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
		} catch ( \Throwable $e ) {
			error_log( '[gs_availability_schema] new-site install failed on blog ' . $blog_id . ': ' . $e->getMessage() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * dbDelta both tables. NOT version-gated here — callers handle that.
	 * Public so maybe_install / install_all_sites / install_new_site can
	 * invoke it after switch_to_blog().
	 */
	public static function install_tables() : void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Table 1: per-member availability — UNIQUE user_id (one row per member).
		// share_token CHAR(43) DEFAULT '' so existing rows pre-token-generation
		// don't break; Plan 28-02 auto-generates on first PUT via
		// wp_generate_password(43, false, false) — 43 alphanumeric chars.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_member_availability (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
				working_hours_json LONGTEXT NULL,
				blocked_ranges_json LONGTEXT NULL,
				share_token CHAR(43) NOT NULL DEFAULT '',
				created_at_ts INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at_ts INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY idx_user_id (user_id),
				KEY idx_share_token (share_token)
			) {$charset_collate};"
		);

		// Table 2: per-meeting (Phase 29 writes rows). Installed here so
		// Phase 29 only writes, never migrates. cancellation_token same
		// rationale as share_token above.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_member_meetings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				member_id BIGINT UNSIGNED NOT NULL,
				guest_user_id BIGINT UNSIGNED NULL,
				guest_name VARCHAR(140) NULL,
				guest_email VARCHAR(190) NULL,
				meeting_type ENUM('in_person','phone','video') NOT NULL,
				meeting_meta_json LONGTEXT NULL,
				starts_at_utc DATETIME NOT NULL,
				ends_at_utc DATETIME NOT NULL,
				title VARCHAR(190) NOT NULL DEFAULT '',
				status ENUM('booked','cancelled','completed','no_show') NOT NULL DEFAULT 'booked',
				jitsi_room VARCHAR(64) NULL,
				cancellation_token CHAR(43) NOT NULL DEFAULT '',
				created_at_ts INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at_ts INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY idx_member_starts (member_id, starts_at_utc),
				KEY idx_cancellation_token (cancellation_token)
			) {$charset_collate};"
		);
	}

	/**
	 * Helper: fully-qualified availability table name for the current blog.
	 * Plan 28-02 REST writes use this.
	 */
	public static function table_availability() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_member_availability';
	}

	/**
	 * Helper: fully-qualified meetings table name for the current blog.
	 * Phase 29 writes use this.
	 */
	public static function table_meetings() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_member_meetings';
	}
}
