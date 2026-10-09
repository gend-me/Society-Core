<?php
/**
 * One-time key migration for the 1.2.0 prefix rename (core tier).
 *
 * Copies every allowlisted pre-1.2.0 data key (inc/bootstrap/key-map.php,
 * generated from bin/rename-map.json) to its new gend_society_ name:
 *
 *  - copy-not-move: old rows stay; they remain the rollback source and keep
 *    serving sibling plugins through the compat bridges (full build);
 *  - once per scope: the per-blog flag option gend_society_keys_migrated
 *    (options, dynamic-prefix options, post meta, cron) and the network flag
 *    site option gend_society_network_keys_migrated (site options, user meta,
 *    BuddyPress group meta, WP Ultimo site meta). Both hold the key-map version
 *    and are stored in the database, never as transients (an external object
 *    cache may evict transients);
 *  - WordPress APIs only for writes, so every object-cache group stays coherent;
 *    direct reads are limited to prepared SELECTs that enumerate rows;
 *  - idempotent: concurrent requests may both copy the same identical values,
 *    which is harmless, so there is no lock;
 *  - autoload preserved for options; every meta value copied (multi-value keys
 *    keep all their rows);
 *  - cron events are moved with their timestamp, schedule and args.
 *    gend_society_unmigrate_cron() / gend_society_unmigrate_cron_all_blogs() are
 *    the exact inverse, run by the rollback before the reverse directory swap.
 *
 * Required by gend-society.php after context.php and before the module loader,
 * so the bridges and the new keys exist before any module reads a key.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The generated allowlist (static per request).
 *
 * @return array<string, mixed>
 */
function gend_society_key_map() {
	static $map = null;
	if ( null === $map ) {
		$map = require __DIR__ . '/key-map.php';
		if ( ! is_array( $map ) ) {
			$map = array( 'version' => '0' );
		}
		foreach ( array( 'option', 'site_option', 'user_meta', 'post_meta', 'group_meta', 'wu_site_meta', 'cron', 'option_prefix', 'user_meta_prefix', 'transient_read_once' ) as $kind ) {
			if ( ! isset( $map[ $kind ] ) || ! is_array( $map[ $kind ] ) ) {
				$map[ $kind ] = array();
			}
		}
	}
	return $map;
}

if ( ! defined( 'GEND_SOCIETY_KEYMAP_VERSION' ) ) {
	$gend_society_km = gend_society_key_map();
	define( 'GEND_SOCIETY_KEYMAP_VERSION', (string) $gend_society_km['version'] );
	unset( $gend_society_km );
}

// Compat bridges (full build only): active before any module reads a key.
if ( function_exists( 'gend_society_mode_tiers' ) && function_exists( 'gend_society_runtime_mode' )
	&& in_array( 'container', gend_society_mode_tiers( gend_society_runtime_mode() ), true )
	&& is_file( dirname( __DIR__ ) . '/compat-bridges.php' ) ) {
	require_once dirname( __DIR__ ) . '/compat-bridges.php';
	if ( function_exists( 'gend_society_compat_bridges' ) ) {
		gend_society_compat_bridges();
	}
}

/**
 * Autoload argument for update_option() from a stored autoload value.
 *
 * @param string $autoload Raw options.autoload column value.
 * @return bool|null Null lets WordPress decide ('auto').
 */
function gend_society_km_autoload_arg( $autoload ) {
	if ( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ) ) {
		return false;
	}
	if ( in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ) ) {
		return true;
	}
	return null;
}

/**
 * Copy one option (value + autoload) if the old row exists.
 *
 * @param string $old_key Old option name.
 * @param string $new_key New option name.
 * @return bool True when the old row existed and was copied.
 */
function gend_society_km_copy_option( $old_key, $new_key ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: the autoload column has no API getter and must be read uncached.
	$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $old_key ) );
	if ( null === $autoload ) {
		return false;
	}
	update_option( $new_key, get_option( $old_key ), gend_society_km_autoload_arg( (string) $autoload ) );
	return true;
}

/**
 * Copy every value of one metadata key for one object (all rows, in order).
 *
 * @param string $type      Meta type: user, post or group.
 * @param int    $object_id Object id.
 * @param string $old_key   Old meta key.
 * @param string $new_key   New meta key.
 * @return bool True when the old key had at least one row.
 */
function gend_society_km_copy_meta( $type, $object_id, $old_key, $new_key ) {
	if ( 'group' === $type ) {
		$values = groups_get_groupmeta( $object_id, $old_key, false );
	} else {
		$values = get_metadata( $type, $object_id, $old_key, false );
	}
	if ( ! is_array( $values ) || ! $values ) {
		return false;
	}
	// The metadata API unslashes on write: slash so backslashes survive the copy.
	if ( 1 === count( $values ) ) {
		$value = reset( $values );
		if ( 'group' === $type ) {
			groups_update_groupmeta( $object_id, $new_key, wp_slash( $value ) );
		} else {
			update_metadata( $type, $object_id, $new_key, wp_slash( $value ) );
		}
		return true;
	}
	if ( 'group' === $type ) {
		groups_delete_groupmeta( $object_id, $new_key );
		foreach ( $values as $value ) {
			groups_add_groupmeta( $object_id, $new_key, wp_slash( $value ) );
		}
	} else {
		delete_metadata( $type, $object_id, $new_key );
		foreach ( $values as $value ) {
			add_metadata( $type, $object_id, $new_key, wp_slash( $value ) );
		}
	}
	return true;
}

/**
 * Move cron events from one hook name to another, keeping timestamp, schedule
 * and args. Used forward (old -> new) by the migration and backward by
 * gend_society_unmigrate_cron(). An event is unscheduled from the source hook
 * only after the same event exists on the target hook.
 *
 * @param array<string, string> $pairs Source hook => target hook.
 * @return array{moved:int, failed:int}
 */
function gend_society_km_move_cron( array $pairs ) {
	$result = array(
		'moved'  => 0,
		'failed' => 0,
	);
	$crons  = _get_cron_array();
	if ( ! is_array( $crons ) || ! $pairs ) {
		return $result;
	}
	// Recurring events may use a schedule a module registers later in the
	// request; register it for this call from the interval stored on the event.
	$missing = array();
	foreach ( $crons as $events ) {
		foreach ( (array) $events as $hook => $instances ) {
			if ( ! isset( $pairs[ $hook ] ) ) {
				continue;
			}
			foreach ( (array) $instances as $event ) {
				if ( ! empty( $event['schedule'] ) && ! empty( $event['interval'] ) ) {
					$missing[ $event['schedule'] ] = (int) $event['interval'];
				}
			}
		}
	}
	$schedules_filter = static function ( $schedules ) use ( $missing ) {
		foreach ( $missing as $name => $interval ) {
			if ( ! isset( $schedules[ $name ] ) ) {
				$schedules[ $name ] = array(
					'interval' => $interval,
					'display'  => $name,
				);
			}
		}
		return $schedules;
	};
	if ( $missing ) {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- re-registers, for this call only, schedules that already exist on queued events (their stored interval).
		add_filter( 'cron_schedules', $schedules_filter, PHP_INT_MAX );
	}

	foreach ( $crons as $timestamp => $events ) {
		foreach ( (array) $events as $hook => $instances ) {
			if ( ! isset( $pairs[ $hook ] ) ) {
				continue;
			}
			$target = $pairs[ $hook ];
			foreach ( (array) $instances as $event ) {
				$args     = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				$schedule = isset( $event['schedule'] ) ? $event['schedule'] : false;
				if ( ! wp_get_scheduled_event( $target, $args, (int) $timestamp ) ) {
					if ( $schedule ) {
						wp_schedule_event( (int) $timestamp, $schedule, $target, $args );
					} else {
						wp_schedule_single_event( (int) $timestamp, $target, $args );
					}
				}
				if ( wp_get_scheduled_event( $target, $args, (int) $timestamp ) ) {
					wp_unschedule_event( (int) $timestamp, $hook, $args );
					++$result['moved'];
				} else {
					++$result['failed']; // source event kept: nothing is lost.
				}
			}
		}
	}

	if ( $missing ) {
		remove_filter( 'cron_schedules', $schedules_filter, PHP_INT_MAX );
	}
	return $result;
}

/**
 * Per-blog migration: options, dynamic-prefix options, post meta, cron.
 * Fast path: one autoloaded option read.
 *
 * @return array|null The log written, or null on the fast path.
 */
function gend_society_migrate_blog_keys() {
	global $wpdb;
	if ( get_option( 'gend_society_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return null;
	}
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return null;
	}
	$map = gend_society_key_map();
	$log = array(
		'option'        => 0,
		'option_prefix' => 0,
		'post_meta'     => 0,
		'cron'          => 0,
		'cron_failed'   => 0,
	);

	foreach ( $map['option'] as $old => $new ) {
		if ( gend_society_km_copy_option( $old, $new ) ) {
			++$log['option'];
		}
	}

	foreach ( $map['option_prefix'] as $old_prefix => $new_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: enumerate the rows of an allowlisted dynamic prefix.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( $old_prefix ) . '%' ) );
		foreach ( (array) $names as $name ) {
			if ( gend_society_km_copy_option( $name, $new_prefix . substr( $name, strlen( $old_prefix ) ) ) ) {
				++$log['option_prefix'];
			}
		}
	}

	foreach ( $map['post_meta'] as $old => $new ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: enumerate the posts carrying the old key.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", $old ) );
		foreach ( (array) $ids as $id ) {
			if ( gend_society_km_copy_meta( 'post', (int) $id, $old, $new ) ) {
				++$log['post_meta'];
			}
		}
	}

	$cron               = gend_society_km_move_cron( $map['cron'] );
	$log['cron']        = $cron['moved'];
	$log['cron_failed'] = $cron['failed'];

	$log['at']      = time();
	$log['blog']    = get_current_blog_id();
	$log['version'] = GEND_SOCIETY_KEYMAP_VERSION;
	update_option( 'gend_society_key_migration_log', $log, false );
	update_option( 'gend_society_keys_migrated', GEND_SOCIETY_KEYMAP_VERSION, true );
	return $log;
}

/**
 * Write a network-level option (site option). On single site the row lives in
 * the options table: store it autoloaded so the fast path costs no query.
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 */
function gend_society_km_set_network_option( $name, $value ) {
	if ( is_multisite() ) {
		update_site_option( $name, $value );
	} else {
		update_option( $name, $value, true );
	}
}

/**
 * Network migration, in stages that each wait for what they need:
 *  - site options: at once (core API, available while plugins are included);
 *  - user meta (+ allowlisted prefixes): plugins_loaded priority 1, because the
 *    user metadata API calls the pluggable get_user_by();
 *  - BuddyPress group meta, WP Ultimo site meta, then the log and the network
 *    flag: wp_loaded priority 1 (BuddyPress registers $wpdb->groupmeta during
 *    bp_init, after its priority-1 callbacks, so bp_init is too early).
 * A stage whose hook already fired runs at once (wp eval, deploy pass). The
 * network flag is set only after the group part ran, or when the groups
 * component is inactive on the main site / a single site.
 *
 * @return array|null Site-option counts, or null on the fast path.
 */
function gend_society_migrate_network_keys() {
	static $ran = false;
	if ( $ran || get_site_option( 'gend_society_network_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return null;
	}
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return null;
	}
	$ran = true;
	$map = gend_society_key_map();
	$log = array(
		'site_option'      => 0,
		'user_meta'        => 0,
		'user_meta_prefix' => 0,
		'group_meta'       => 0,
		'wu_site_meta'     => 0,
	);

	foreach ( $map['site_option'] as $old => $new ) {
		$value = get_site_option( $old, null );
		if ( null === $value ) {
			continue;
		}
		update_site_option( $new, $value );
		++$log['site_option'];
	}
	$GLOBALS['gend_society_km_network_log'] = $log;

	$stages = array(
		'plugins_loaded' => 'gend_society_km_network_users',
		'wp_loaded'      => 'gend_society_km_network_finish',
	);
	foreach ( $stages as $hook => $callback ) {
		if ( did_action( $hook ) ) {
			call_user_func( $callback );
		} else {
			add_action( $hook, $callback, 1 );
		}
	}
	return $log;
}

/**
 * Network migration, user meta part (plugins_loaded priority 1).
 */
function gend_society_km_network_users() {
	global $wpdb;
	if ( empty( $GLOBALS['gend_society_km_network_log'] ) || isset( $GLOBALS['gend_society_km_network_log']['users_done'] ) ) {
		return;
	}
	$map       = gend_society_key_map();
	$user_keys = array();
	foreach ( $map['user_meta'] as $old => $new ) {
		$user_keys[ $old ] = array( $new, 'user_meta' );
	}
	foreach ( $map['user_meta_prefix'] as $old_prefix => $new_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: enumerate the keys of an allowlisted dynamic prefix.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY meta_key", $wpdb->esc_like( $old_prefix ) . '%' ) );
		foreach ( (array) $keys as $key ) {
			if ( ! isset( $user_keys[ $key ] ) ) {
				$user_keys[ $key ] = array( $new_prefix . substr( $key, strlen( $old_prefix ) ), 'user_meta_prefix' );
			}
		}
	}
	foreach ( $user_keys as $old => $target ) {
		list( $new, $bucket ) = $target;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: enumerate the users carrying the old key.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id", $old ) );
		foreach ( (array) $ids as $id ) {
			if ( gend_society_km_copy_meta( 'user', (int) $id, $old, $new ) ) {
				++$GLOBALS['gend_society_km_network_log'][ $bucket ];
			}
		}
	}
	$GLOBALS['gend_society_km_network_log']['users_done'] = true;
}

/**
 * Network migration, WP Ultimo site meta part (run by the final stage).
 */
function gend_society_km_network_wu() {
	$map = gend_society_key_map();
	if ( ! $map['wu_site_meta'] ) {
		return;
	}
	if ( ! function_exists( 'wu_get_sites' ) ) {
		$GLOBALS['gend_society_km_network_log']['wu_site_meta'] = 'skipped: WP Ultimo inactive';
		return;
	}
	$page = 0;
	do {
		$sites = wu_get_sites(
			array(
				'number' => 100,
				'offset' => 100 * $page,
			)
		);
		foreach ( (array) $sites as $site ) {
			if ( ! is_object( $site ) || ! method_exists( $site, 'get_meta' ) || ! method_exists( $site, 'update_meta' ) ) {
				continue;
			}
			foreach ( $map['wu_site_meta'] as $old => $new ) {
				$value = $site->get_meta( $old, null );
				if ( null === $value ) {
					continue;
				}
				$site->update_meta( $new, $value );
				++$GLOBALS['gend_society_km_network_log']['wu_site_meta'];
			}
		}
		++$page;
		$full_page = is_array( $sites ) && 100 === count( $sites );
	} while ( $full_page );
}

/**
 * Network migration, BuddyPress group meta part (run by the final stage).
 */
function gend_society_km_network_groups() {
	global $wpdb;
	if ( empty( $GLOBALS['gend_society_km_network_log'] ) || isset( $GLOBALS['gend_society_km_network_log']['groups_done'] ) ) {
		return;
	}
	if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'groups' ) || ! function_exists( 'groups_get_groupmeta' ) ) {
		return;
	}
	$map   = gend_society_key_map();
	$table = '';
	if ( ! empty( $wpdb->groupmeta ) ) {
		$table = $wpdb->groupmeta; // BuddyPress registers its group meta table with wpdb.
	} elseif ( ! empty( buddypress()->groups->table_name_groupmeta ) ) {
		$table = buddypress()->groups->table_name_groupmeta;
	}
	if ( '' === $table ) {
		$GLOBALS['gend_society_km_network_log']['group_meta'] = 'error: group meta table unknown';
		return; // groups_done stays unset: the network flag is not set, retried next request.
	}
	$count = 0;
	foreach ( $map['group_meta'] as $old => $new ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time migration: enumerate the groups carrying the old key; the table name comes from BuddyPress.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT group_id FROM {$table} WHERE meta_key = %s ORDER BY group_id", $old ) );
		if ( '' !== $wpdb->last_error ) {
			$GLOBALS['gend_society_km_network_log']['group_meta'] = 'error: ' . $wpdb->last_error;
			return;
		}
		foreach ( (array) $ids as $id ) {
			if ( gend_society_km_copy_meta( 'group', (int) $id, $old, $new ) ) {
				++$count;
			}
		}
	}
	$GLOBALS['gend_society_km_network_log']['group_meta']  = $count;
	$GLOBALS['gend_society_km_network_log']['groups_done'] = 'ran';
}

/**
 * Network migration, final part: write the log and the network flag. Without the
 * groups component the flag is set only on a single site or the main site, so a
 * subsite that does not load BuddyPress cannot skip the global group meta.
 */
function gend_society_km_network_finish() {
	if ( empty( $GLOBALS['gend_society_km_network_log'] ) || ! empty( $GLOBALS['gend_society_km_network_log']['finished'] ) ) {
		return;
	}
	if ( empty( $GLOBALS['gend_society_km_network_log']['users_done'] ) ) {
		gend_society_km_network_users();
	}
	gend_society_km_network_groups();
	if ( empty( $GLOBALS['gend_society_km_network_log']['wu_done'] ) ) {
		gend_society_km_network_wu();
		$GLOBALS['gend_society_km_network_log']['wu_done'] = true;
	}
	$log = $GLOBALS['gend_society_km_network_log'];
	unset( $log['users_done'], $log['wu_done'] );
	if ( ! isset( $log['groups_done'] ) ) {
		if ( ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) || ( is_multisite() && ! is_main_site() ) ) {
			return; // groups active but not migrated (error), or a subsite without BuddyPress: retried on a later request.
		}
		$log['group_meta']  = 'skipped: BuddyPress groups inactive';
		$log['groups_done'] = 'skipped';
	}
	$log['at']      = time();
	$log['blog']    = get_current_blog_id();
	$log['version'] = GEND_SOCIETY_KEYMAP_VERSION;
	$GLOBALS['gend_society_km_network_log']['finished'] = true;
	gend_society_km_set_network_option( 'gend_society_network_key_migration_log', $log );
	gend_society_km_set_network_option( 'gend_society_network_keys_migrated', GEND_SOCIETY_KEYMAP_VERSION );
}

/**
 * Deploy pass: migrate the network data and every blog. Callable from wp eval:
 *   wp eval 'print_r( gend_society_migrate_all_blogs() );'
 *
 * @return array<string, mixed> Per-blog flag values after the pass.
 */
function gend_society_migrate_all_blogs() {
	gend_society_migrate_network_keys();
	$out = array();
	if ( ! is_multisite() ) {
		gend_society_migrate_blog_keys();
		$out[ get_current_blog_id() ] = get_option( 'gend_society_keys_migrated' );
	} else {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			gend_society_migrate_blog_keys();
			$out[ (int) $blog_id ] = get_option( 'gend_society_keys_migrated' );
			restore_current_blog();
		}
	}
	$out['network'] = get_site_option( 'gend_society_network_keys_migrated' );
	return $out;
}

/**
 * Rollback helper (current blog): move every event of a NEW cron hook back to
 * its OLD hook with the same timestamp, schedule and args. Does not touch the
 * flags or any option/meta row. Run before the reverse directory swap.
 *
 * @return array{moved:int, failed:int}
 */
function gend_society_unmigrate_cron() {
	$map = gend_society_key_map();
	return gend_society_km_move_cron( array_flip( $map['cron'] ) );
}

/**
 * Rollback helper: gend_society_unmigrate_cron() on every blog.
 *
 * @return array<int, array{moved:int, failed:int}>
 */
function gend_society_unmigrate_cron_all_blogs() {
	if ( ! is_multisite() ) {
		return array( get_current_blog_id() => gend_society_unmigrate_cron() );
	}
	$out = array();
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		$out[ (int) $blog_id ] = gend_society_unmigrate_cron();
		restore_current_blog();
	}
	return $out;
}

/**
 * Agent switch-back state with a read-once fallback to the pre-1.2.0 name, so
 * a switch that was in flight during the deploy can still be restored. The old
 * transient is moved to the new name (8 h cap, the switch TTL) on first read.
 *
 * @param string $suffix Transient suffix (the hashed switch token).
 * @return mixed Transient value or false.
 */
function gend_society_get_agent_switch_transient( $suffix ) {
	$map = gend_society_key_map();
	foreach ( $map['transient_read_once'] as $old_prefix => $new_prefix ) {
		if ( false === strpos( $new_prefix, 'agent_switch_' ) ) {
			continue;
		}
		$value = get_transient( $new_prefix . $suffix );
		if ( false !== $value ) {
			return $value;
		}
		$value = get_transient( $old_prefix . $suffix );
		if ( false !== $value ) {
			set_transient( $new_prefix . $suffix, $value, 8 * HOUR_IN_SECONDS );
			delete_transient( $old_prefix . $suffix );
		}
		return $value;
	}
	return false;
}

// On load: network then current blog (both one-read fast paths once done).
gend_society_migrate_network_keys();
gend_society_migrate_blog_keys();
