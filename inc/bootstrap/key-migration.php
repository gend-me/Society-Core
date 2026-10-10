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
 *  - serialised: each scope runs under an atomic database lock (a row inserted
 *    with a plain INSERT on the options table's unique option_name; add_option()
 *    upserts, so it cannot serve as a lock). Concurrent requests that lose the
 *    lock do not migrate: a per-blog loser waits a few seconds for the flag, a
 *    network loser goes on at once; until the flags are set, reads of the new
 *    names fall back to the old rows (read-through below). A lock whose holder
 *    died is taken over after gend_society_km_lock_ttl() seconds;
 *  - exact: for every object the new key ends up with exactly the old key's
 *    rows (same values, same count, same order), so a single-value key has one
 *    row and a multi-value key keeps its multiset. A new key that already holds
 *    exactly those rows is left alone (re-runs after a rollback write nothing
 *    for unchanged keys and rewrite the changed ones);
 *  - WordPress APIs for every data write, so the object cache stays coherent;
 *    direct queries are limited to prepared SELECTs that read raw rows and to
 *    the lock row itself;
 *  - autoload preserved for options;
 *  - cron events are moved with their timestamp, schedule and args in one write
 *    of the cron array, then re-read and moved again until no old-hook event is
 *    left (a concurrent cron writer may have put one back).
 *    gend_society_unmigrate_cron() / gend_society_unmigrate_cron_all_blogs() are
 *    the exact inverse, run by the rollback before the reverse directory swap;
 *  - gend_society_km_dedupe_new_keys() repairs new-key rows left by an earlier
 *    unlocked run (new names only). With GEND_SOCIETY_KM_NO_AUTORUN defined this
 *    file only defines functions, so the repair can run on the old version.
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
if ( ! defined( 'GEND_SOCIETY_KM_NO_AUTORUN' )
	&& function_exists( 'gend_society_mode_tiers' ) && function_exists( 'gend_society_runtime_mode' )
	&& in_array( 'container', gend_society_mode_tiers( gend_society_runtime_mode() ), true )
	&& is_file( dirname( __DIR__ ) . '/compat-bridges.php' ) ) {
	require_once dirname( __DIR__ ) . '/compat-bridges.php';
	if ( function_exists( 'gend_society_compat_bridges' ) ) {
		gend_society_compat_bridges();
	}
}

/*
 * ---------------------------------------------------------------------------
 * Lock
 * ---------------------------------------------------------------------------
 */

/**
 * Seconds after which a migration lock counts as abandoned (its holder was
 * killed before it could release it) and may be taken over.
 *
 * @return int
 */
function gend_society_km_lock_ttl() {
	return 300;
}

/**
 * Take the migration lock of one scope: an atomic INSERT of a lock row into an
 * options table (unique option_name). Network scope: the main site's options
 * table; blog scope: the current blog's. Never a transient.
 *
 * @param bool $network Network scope (else the current blog).
 * @return array{table:string, name:string, token:string}|false Lock handle, or false while another live holder has it.
 */
function gend_society_km_lock_acquire( $network ) {
	global $wpdb;
	$table = ( $network && is_multisite() ) ? $wpdb->get_blog_prefix( get_main_site_id() ) . 'options' : $wpdb->options;
	$name  = $network ? 'gend_society_network_keys_migrating' : 'gend_society_keys_migrating';
	$token = bin2hex( random_bytes( 8 ) ) . '|' . ( time() + gend_society_km_lock_ttl() );
	$got   = false;
	$quiet = $wpdb->suppress_errors( true ); // a held lock is an expected duplicate-key error.
	for ( $try = 0; $try < 2 && ! $got; $try++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic lock row: a plain INSERT fails on the unique option_name while the row exists (add_option() upserts, so it cannot lock). The table name comes from $wpdb.
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) );
		if ( $got ) {
			break;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reads the lock row itself, uncached by design. The table name comes from $wpdb.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", $name ) );
		if ( ! is_string( $held ) ) {
			continue; // released in between: try the INSERT again.
		}
		$parts = explode( '|', $held );
		if ( (int) end( $parts ) >= time() ) {
			break; // live holder.
		}
		// Abandoned: take it over with a compare-and-swap on the old token.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic takeover of an expired lock row (compare-and-swap). The table name comes from $wpdb.
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) );
		break;
	}
	$wpdb->suppress_errors( $quiet );
	if ( ! $got ) {
		return false;
	}
	$lock = array(
		'table' => $table,
		'name'  => $name,
		'token' => $token,
	);
	if ( empty( $GLOBALS['gend_society_km_locks'] ) ) {
		$GLOBALS['gend_society_km_locks'] = array();
		register_shutdown_function( 'gend_society_km_lock_release_all' );
	}
	$GLOBALS['gend_society_km_locks'][ $table . '|' . $name ] = $lock;
	return $lock;
}

/**
 * Release a lock taken by this request (only if it still holds our token).
 *
 * @param array|false $lock Handle from gend_society_km_lock_acquire().
 */
function gend_society_km_lock_release( $lock ) {
	global $wpdb;
	if ( ! is_array( $lock ) ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- deletes our own lock row only (token match). The table name comes from $wpdb.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$lock['table']} WHERE option_name = %s AND option_value = %s", $lock['name'], $lock['token'] ) );
	unset( $GLOBALS['gend_society_km_locks'][ $lock['table'] . '|' . $lock['name'] ] );
}

/**
 * Shutdown safety net: release every lock this request still holds (fatal
 * error, early exit), so other requests do not wait for the TTL.
 */
function gend_society_km_lock_release_all() {
	foreach ( (array) ( $GLOBALS['gend_society_km_locks'] ?? array() ) as $lock ) {
		gend_society_km_lock_release( $lock );
	}
}

/**
 * Read a migration flag straight from the database (no cache), so a request
 * whose cache predates another request's migration sees the current state.
 *
 * @param bool $network Network flag (else the current blog's).
 * @return string|null
 */
function gend_society_km_raw_flag( $network ) {
	global $wpdb;
	if ( $network && is_multisite() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uncached read of the migration flag by design.
		return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d LIMIT 1", 'gend_society_network_keys_migrated', get_current_network_id() ) );
	}
	$name = $network ? 'gend_society_network_keys_migrated' : 'gend_society_keys_migrated';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uncached read of the migration flag by design.
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

/**
 * Drop this request's cached copy of the autoloaded options (cron array,
 * flags), so the next read sees what other requests wrote meanwhile.
 */
function gend_society_km_refresh_options_cache() {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'cron', 'options' );
	foreach ( array( 'gend_society_keys_migrated', 'gend_society_network_keys_migrated' ) as $name ) {
		wp_cache_delete( $name, 'options' );
	}
	if ( is_multisite() ) {
		wp_cache_delete( get_current_network_id() . ':gend_society_network_keys_migrated', 'site-options' );
		wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );
	}
}

/**
 * Take a scope's lock, waiting up to $wait seconds while another request holds
 * it. Returns 'done' as soon as the scope's flag is set (by anyone).
 *
 * @param bool  $network Network scope (else the current blog).
 * @param float $wait    Seconds to wait for a held lock.
 * @return array|string|false Lock handle, 'done', or false (still held by another request).
 */
function gend_society_km_lock_or_done( $network, $wait ) {
	$deadline = microtime( true ) + max( 0, (float) $wait );
	while ( true ) {
		$lock = gend_society_km_lock_acquire( $network );
		if ( $lock ) {
			// Re-check under the lock: another request may have finished just before.
			if ( gend_society_km_raw_flag( $network ) === GEND_SOCIETY_KEYMAP_VERSION ) {
				gend_society_km_lock_release( $lock );
				gend_society_km_refresh_options_cache();
				return 'done';
			}
			gend_society_km_refresh_options_cache();
			return $lock;
		}
		if ( gend_society_km_raw_flag( $network ) === GEND_SOCIETY_KEYMAP_VERSION ) {
			gend_society_km_refresh_options_cache();
			return 'done';
		}
		if ( microtime( true ) >= $deadline ) {
			return false;
		}
		usleep( 200000 );
	}
}

/*
 * ---------------------------------------------------------------------------
 * Raw rows and exact copies
 * ---------------------------------------------------------------------------
 */

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
 * One option row as stored (current blog).
 *
 * @param string $name Option name.
 * @return array{0:string, 1:string}|null Raw value and autoload, or null when absent.
 */
function gend_society_km_raw_option( $name ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: the stored row (value + autoload) is compared exactly, uncached.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_N );
	return is_array( $row ) ? array( (string) $row[0], (string) $row[1] ) : null;
}

/**
 * Copy one option (value + autoload) if the old row exists. Writes nothing when
 * the new row already holds the same value and autoload.
 *
 * @param string $old_key Old option name.
 * @param string $new_key New option name.
 * @return bool True when the old row exists (copied or already equal).
 */
function gend_society_km_copy_option( $old_key, $new_key ) {
	$old = gend_society_km_raw_option( $old_key );
	if ( null === $old ) {
		return false;
	}
	$autoload = gend_society_km_autoload_arg( $old[1] );
	$new      = gend_society_km_raw_option( $new_key );
	if ( null !== $new && $new[0] === $old[0] && gend_society_km_autoload_arg( $new[1] ) === $autoload ) {
		return true;
	}
	$value = maybe_unserialize( $old[0] );
	gend_society_km_writing( true );
	if ( null !== $new && $new[0] === $old[0] && function_exists( 'wp_set_option_autoload' ) && null !== $autoload ) {
		wp_set_option_autoload( $new_key, $autoload ); // same value, other autoload: update_option() would skip it.
	} else {
		if ( null !== $new && $new[0] === $old[0] ) {
			delete_option( $new_key );
		}
		wp_cache_delete( $new_key, 'options' );
		update_option( $new_key, $value, $autoload );
	}
	gend_society_km_writing( false );
	return true;
}

/**
 * Mark (or unmark) this request as writing new-name rows, so the read-through
 * filters never answer the API's own existence checks with the old value.
 *
 * @param bool $on Start (true) or end (false) a write.
 */
function gend_society_km_writing( $on ) {
	$depth                              = (int) ( $GLOBALS['gend_society_km_writing'] ?? 0 );
	$GLOBALS['gend_society_km_writing'] = max( 0, $depth + ( $on ? 1 : -1 ) );
}

/**
 * Network-level option rows as stored (sitemeta on multisite, every row:
 * sitemeta has no unique key, so duplicates are visible here).
 *
 * @param string $name Option name.
 * @return string[] Raw values in row order.
 */
function gend_society_km_raw_site_option( $name ) {
	global $wpdb;
	if ( ! is_multisite() ) {
		$row = gend_society_km_raw_option( $name );
		return null === $row ? array() : array( $row[0] );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration: every stored row of a network option, uncached.
	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d ORDER BY meta_id", $name, get_current_network_id() ) ) );
}

/**
 * Copy one network option: the new key ends up with exactly one row equal to
 * the old value (single site: the option row, autoload preserved).
 *
 * @param string $old_key Old option name.
 * @param string $new_key New option name.
 * @return bool True when the old row exists.
 */
function gend_society_km_copy_site_option( $old_key, $new_key ) {
	if ( ! is_multisite() ) {
		return gend_society_km_copy_option( $old_key, $new_key );
	}
	$old = gend_society_km_raw_site_option( $old_key );
	if ( ! $old ) {
		return false;
	}
	if ( gend_society_km_raw_site_option( $new_key ) === array( $old[0] ) ) {
		return true;
	}
	gend_society_km_replace_site_option( $new_key, $old[0] );
	return true;
}

/**
 * Replace every stored row of a network option with one row (multisite).
 *
 * @param string $name Option name (a NEW name).
 * @param string $raw  Raw (serialized) value.
 */
function gend_society_km_replace_site_option( $name, $raw ) {
	gend_society_km_writing( true );
	delete_site_option( $name );
	wp_cache_delete( get_current_network_id() . ':' . $name, 'site-options' );
	add_site_option( $name, maybe_unserialize( $raw ) );
	gend_society_km_writing( false );
}

/**
 * Write a network-level option with exactly one stored row. On single site the
 * row lives in the options table: autoloaded, so the fast path costs no query.
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 */
function gend_society_km_set_network_option( $name, $value ) {
	if ( ! is_multisite() ) {
		update_option( $name, $value, true );
		return;
	}
	if ( count( gend_society_km_raw_site_option( $name ) ) > 1 ) {
		delete_site_option( $name ); // collapse duplicate rows left by an earlier unlocked run.
	}
	update_site_option( $name, $value );
}

/**
 * Table, object column and row id column of a meta type.
 *
 * @param string $type user, post or group.
 * @return array{0:string, 1:string, 2:string}|null
 */
function gend_society_km_meta_table( $type ) {
	global $wpdb;
	if ( 'user' === $type ) {
		return array( $wpdb->usermeta, 'user_id', 'umeta_id' );
	}
	if ( 'post' === $type ) {
		return array( $wpdb->postmeta, 'post_id', 'meta_id' );
	}
	if ( 'group' === $type ) {
		$table = '';
		if ( ! empty( $wpdb->groupmeta ) ) {
			$table = $wpdb->groupmeta; // BuddyPress registers its group meta table with wpdb.
		} elseif ( function_exists( 'buddypress' ) && ! empty( buddypress()->groups->table_name_groupmeta ) ) {
			$table = buddypress()->groups->table_name_groupmeta;
		}
		return '' === $table ? null : array( $table, 'group_id', 'id' );
	}
	return null;
}

/**
 * Every stored value of one meta key for one object, in row order.
 *
 * @param string $type      user, post or group.
 * @param int    $object_id Object id.
 * @param string $key       Meta key.
 * @return string[] Raw (serialized) values.
 */
function gend_society_km_raw_meta( $type, $object_id, $key ) {
	global $wpdb;
	$t = gend_society_km_meta_table( $type );
	if ( null === $t ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration: the stored rows are compared exactly, uncached; table and column names come from $wpdb / BuddyPress.
	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$t[0]} WHERE {$t[1]} = %d AND meta_key = %s ORDER BY {$t[2]}", $object_id, $key ) ) );
}

/**
 * Replace every row of one meta key on one object with the given raw values
 * (in order). Single value: added as unique.
 *
 * @param string   $type       user, post or group.
 * @param int      $object_id  Object id.
 * @param string   $key        Meta key (a NEW name).
 * @param string[] $raw_values Raw (serialized) values.
 */
function gend_society_km_write_meta( $type, $object_id, $key, array $raw_values ) {
	if ( '' === (string) $key || $object_id <= 0 ) {
		return; // an empty key would delete every meta row of the object.
	}
	$unique = 1 === count( $raw_values );
	gend_society_km_writing( true );
	if ( 'group' === $type ) {
		groups_delete_groupmeta( $object_id, $key );
	} else {
		delete_metadata( $type, $object_id, $key );
	}
	foreach ( $raw_values as $raw ) {
		// The metadata API unslashes on write: slash so backslashes survive the copy.
		$value = wp_slash( maybe_unserialize( $raw ) );
		if ( 'group' === $type ) {
			groups_add_groupmeta( $object_id, $key, $value, $unique );
		} else {
			add_metadata( $type, $object_id, $key, $value, $unique );
		}
	}
	gend_society_km_writing( false );
}

/**
 * Copy one metadata key for one object: afterwards the new key holds exactly
 * the old key's rows (same values, count and order). Writes nothing when it
 * already does.
 *
 * @param string $type      Meta type: user, post or group.
 * @param int    $object_id Object id.
 * @param string $old_key   Old meta key.
 * @param string $new_key   New meta key.
 * @return bool True when the old key has at least one row.
 */
function gend_society_km_copy_meta( $type, $object_id, $old_key, $new_key ) {
	$old = gend_society_km_raw_meta( $type, $object_id, $old_key );
	if ( ! $old ) {
		return false;
	}
	if ( gend_society_km_raw_meta( $type, $object_id, $new_key ) !== $old ) {
		gend_society_km_write_meta( $type, $object_id, $new_key, $old );
	}
	return true;
}

/*
 * ---------------------------------------------------------------------------
 * Cron
 * ---------------------------------------------------------------------------
 */

/**
 * Move cron events from one hook name to another, keeping timestamp, schedule,
 * interval and args, in ONE write of the cron array. The hook keeps its place
 * within its timestamp, so moving back restores the array exactly. A source
 * event whose target twin (same timestamp and args) already exists is dropped.
 * Used forward (old -> new) by the migration and backward by
 * gend_society_unmigrate_cron().
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
	$changed = false;
	foreach ( $crons as $timestamp => $events ) {
		if ( ! is_array( $events ) ) {
			continue;
		}
		$hit = false;
		foreach ( $events as $hook => $instances ) {
			if ( isset( $pairs[ $hook ] ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$out = array();
		foreach ( $events as $hook => $instances ) {
			$name = isset( $pairs[ $hook ] ) ? $pairs[ $hook ] : $hook;
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = array();
			}
			foreach ( (array) $instances as $key => $event ) {
				if ( $name !== $hook ) {
					++$result['moved'];
					if ( isset( $events[ $name ][ $key ] ) || isset( $out[ $name ][ $key ] ) ) {
						continue; // target twin exists: drop the source event.
					}
				}
				$out[ $name ][ $key ] = $event;
			}
		}
		$out = array_filter( $out );
		if ( $out ) {
			$crons[ $timestamp ] = $out;
		} else {
			unset( $crons[ $timestamp ] );
		}
		$changed = true;
	}
	if ( $changed ) {
		_set_cron_array( $crons );
	}
	return $result;
}

/**
 * Move cron events, then re-read the stored array and move again until no
 * source-hook event is left (a concurrent cron writer working from an older
 * copy of the array can put moved events back). Idempotent.
 *
 * @param array<string, string> $pairs Source hook => target hook.
 * @return array{moved:int, failed:int, repass:int}
 */
function gend_society_km_move_cron_settled( array $pairs ) {
	gend_society_km_refresh_options_cache();
	$result           = gend_society_km_move_cron( $pairs );
	$result['repass'] = 0;
	if ( 0 === $result['moved'] ) {
		return $result;
	}
	for ( $pass = 0; $pass < 3; $pass++ ) {
		usleep( 250000 );
		gend_society_km_refresh_options_cache();
		$again = gend_society_km_move_cron( $pairs );
		if ( 0 === $again['moved'] ) {
			break;
		}
		$result['repass'] += $again['moved'];
	}
	return $result;
}

/*
 * ---------------------------------------------------------------------------
 * Per-blog migration
 * ---------------------------------------------------------------------------
 */

/**
 * Per-blog migration: options, dynamic-prefix options, post meta, cron.
 * Fast path: one autoloaded option read. Runs under the blog's lock; a request
 * that finds the lock held waits up to $wait seconds for the flag, then goes on
 * without migrating.
 *
 * @param float $wait Seconds to wait while another request migrates this blog.
 * @return array|null The log written, or null when nothing ran in this call.
 */
function gend_society_migrate_blog_keys( $wait = 5 ) {
	global $wpdb;
	if ( get_option( 'gend_society_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return null;
	}
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return null;
	}
	$lock = gend_society_km_lock_or_done( false, $wait );
	if ( ! is_array( $lock ) ) {
		return null;
	}
	$map = gend_society_key_map();
	$log = array(
		'option'        => 0,
		'option_prefix' => 0,
		'post_meta'     => 0,
		'cron'          => 0,
		'cron_failed'   => 0,
		'cron_repass'   => 0,
	);
	try {
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

		$cron               = gend_society_km_move_cron_settled( $map['cron'] );
		$log['cron']        = $cron['moved'];
		$log['cron_failed'] = $cron['failed'];
		$log['cron_repass'] = $cron['repass'];

		$log['at']      = time();
		$log['blog']    = get_current_blog_id();
		$log['version'] = GEND_SOCIETY_KEYMAP_VERSION;
		update_option( 'gend_society_key_migration_log', $log, false );
		update_option( 'gend_society_keys_migrated', GEND_SOCIETY_KEYMAP_VERSION, true );
	} finally {
		gend_society_km_lock_release( $lock );
	}
	return $log;
}

/*
 * ---------------------------------------------------------------------------
 * Network migration
 * ---------------------------------------------------------------------------
 */

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
 * component is inactive on the main site / a single site. The network lock is
 * held from the first stage to the last (released at the end of the final
 * stage, or at shutdown); a request that finds it held goes on at once.
 *
 * @param float $wait Seconds to wait while another request holds the network lock.
 * @return array|null Site-option counts, or null when nothing started in this call.
 */
function gend_society_migrate_network_keys( $wait = 0 ) {
	if ( ! empty( $GLOBALS['gend_society_km_network_log'] ) ) {
		return null; // this request already runs (or ran) the network migration.
	}
	if ( get_site_option( 'gend_society_network_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return null;
	}
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return null;
	}
	$lock = gend_society_km_lock_or_done( true, $wait );
	if ( ! is_array( $lock ) ) {
		return null;
	}
	$GLOBALS['gend_society_km_network_lock'] = $lock;
	$map                                     = gend_society_key_map();
	$log                                     = array(
		'site_option'      => 0,
		'user_meta'        => 0,
		'user_meta_prefix' => 0,
		'group_meta'       => 0,
		'wu_site_meta'     => 0,
	);

	foreach ( $map['site_option'] as $old => $new ) {
		if ( gend_society_km_copy_site_option( $old, $new ) ) {
			++$log['site_option'];
		}
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
	if ( empty( $GLOBALS['gend_society_km_network_log'] ) || isset( $GLOBALS['gend_society_km_network_log']['users_done'] ) || ! empty( $GLOBALS['gend_society_km_network_log']['finished'] ) ) {
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
	$map = gend_society_key_map();
	$t   = gend_society_km_meta_table( 'group' );
	if ( null === $t ) {
		$GLOBALS['gend_society_km_network_log']['group_meta'] = 'error: group meta table unknown';
		return; // groups_done stays unset: the network flag is not set, retried next request.
	}
	$table = $t[0];
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
 * Network migration, final part: write the log and the network flag, then
 * release the network lock. Without the groups component the flag is set only
 * on a single site or the main site, so a subsite that does not load
 * BuddyPress cannot skip the global group meta.
 */
function gend_society_km_network_finish() {
	if ( empty( $GLOBALS['gend_society_km_network_log'] ) || ! empty( $GLOBALS['gend_society_km_network_log']['finished'] ) ) {
		return;
	}
	try {
		if ( empty( $GLOBALS['gend_society_km_network_log']['users_done'] ) ) {
			gend_society_km_network_users();
		}
		gend_society_km_network_groups();
		if ( empty( $GLOBALS['gend_society_km_network_log']['wu_done'] ) ) {
			gend_society_km_network_wu();
			$GLOBALS['gend_society_km_network_log']['wu_done'] = true;
		}
		$GLOBALS['gend_society_km_network_log']['finished'] = true;
		$log = $GLOBALS['gend_society_km_network_log'];
		unset( $log['users_done'], $log['wu_done'], $log['finished'] );
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
		gend_society_km_set_network_option( 'gend_society_network_key_migration_log', $log );
		gend_society_km_set_network_option( 'gend_society_network_keys_migrated', GEND_SOCIETY_KEYMAP_VERSION );
	} finally {
		gend_society_km_lock_release( $GLOBALS['gend_society_km_network_lock'] ?? false );
		unset( $GLOBALS['gend_society_km_network_lock'] );
	}
}

/**
 * Deploy pass: migrate the network data and every blog, waiting (up to two
 * minutes per scope) while a web request holds a lock. Callable from wp eval:
 *   wp eval 'print_r( gend_society_migrate_all_blogs() );'
 *
 * @return array<string, mixed> Per-blog flag values after the pass.
 */
function gend_society_migrate_all_blogs() {
	gend_society_migrate_network_keys( 120 );
	$out = array();
	if ( ! is_multisite() ) {
		gend_society_migrate_blog_keys( 120 );
		$out[ get_current_blog_id() ] = gend_society_km_raw_flag( false );
	} else {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			gend_society_migrate_blog_keys( 120 );
			$out[ (int) $blog_id ] = gend_society_km_raw_flag( false );
			restore_current_blog();
		}
	}
	$out['network'] = gend_society_km_raw_flag( true );
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
	gend_society_km_refresh_options_cache();
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

/*
 * ---------------------------------------------------------------------------
 * Read-through while a migration is pending
 * ---------------------------------------------------------------------------
 */

/**
 * New name => old name lookups for the read-through (static per request).
 *
 * @param string $kind option, site_option, user, post or group.
 * @return array<string, string>
 */
function gend_society_km_reverse( $kind ) {
	static $rev = null;
	if ( null === $rev ) {
		$map = gend_society_key_map();
		$rev = array(
			'option'      => array_flip( $map['option'] ),
			'site_option' => array_flip( $map['site_option'] ),
			'user'        => array_flip( $map['user_meta'] ),
			'post'        => array_flip( $map['post_meta'] ),
			'group'       => array_flip( $map['group_meta'] ),
		);
	}
	return $rev[ $kind ] ?? array();
}

/**
 * Old meta key for a new one (explicit or allowlisted dynamic prefix), or ''.
 *
 * @param string $type     user, post or group.
 * @param string $meta_key New meta key.
 * @return string
 */
function gend_society_km_old_meta_key( $type, $meta_key ) {
	$rev = gend_society_km_reverse( $type );
	if ( isset( $rev[ $meta_key ] ) ) {
		return $rev[ $meta_key ];
	}
	if ( 'user' === $type ) {
		$map = gend_society_key_map();
		foreach ( $map['user_meta_prefix'] as $old_prefix => $new_prefix ) {
			if ( 0 === strpos( $meta_key, $new_prefix ) ) {
				return $old_prefix . substr( $meta_key, strlen( $new_prefix ) );
			}
		}
	}
	return '';
}

/**
 * While this scope's migration has not finished, a read of a NEW meta key with
 * no row yet answers from the OLD key's rows.
 *
 * @param string     $type      user, post or group.
 * @param mixed      $check     Short-circuit value from earlier filters.
 * @param int        $object_id Object id.
 * @param string|int $meta_key  Meta key.
 * @param bool       $single    Single value requested.
 * @return mixed
 */
function gend_society_km_read_through_meta( $type, $check, $object_id, $meta_key, $single ) {
	static $busy = false;
	if ( null !== $check || $busy || ! empty( $GLOBALS['gend_society_km_writing'] ) || ! is_string( $meta_key ) || '' === $meta_key ) {
		return $check;
	}
	$old = gend_society_km_old_meta_key( $type, $meta_key );
	if ( '' === $old || ( 'user' === $type && ! function_exists( 'get_user_by' ) ) ) {
		return $check;
	}
	$done = 'post' === $type ? get_option( 'gend_society_keys_migrated' ) : get_site_option( 'gend_society_network_keys_migrated' );
	if ( GEND_SOCIETY_KEYMAP_VERSION === $done ) {
		return $check;
	}
	// Raw reads: no nested metadata API call (BuddyPress swaps the group meta id
	// column through a query filter that a nested call would remove).
	$busy = true;
	$vals = gend_society_km_raw_meta( $type, (int) $object_id, $meta_key ) ? array() : gend_society_km_raw_meta( $type, (int) $object_id, $old );
	$busy = false;
	if ( ! $vals ) {
		return $check;
	}
	$vals = array_map( 'maybe_unserialize', $vals );
	return $single ? array( $vals[0] ) : $vals;
}

/**
 * Read-through for user meta (see gend_society_km_read_through_meta()).
 *
 * @param mixed      $check     Short-circuit value.
 * @param int        $object_id User id.
 * @param string|int $meta_key  Meta key.
 * @param bool       $single    Single value.
 * @return mixed
 */
function gend_society_km_read_through_user_meta( $check, $object_id, $meta_key, $single ) {
	return gend_society_km_read_through_meta( 'user', $check, $object_id, $meta_key, $single );
}

/**
 * Read-through for post meta (see gend_society_km_read_through_meta()).
 *
 * @param mixed      $check     Short-circuit value.
 * @param int        $object_id Post id.
 * @param string|int $meta_key  Meta key.
 * @param bool       $single    Single value.
 * @return mixed
 */
function gend_society_km_read_through_post_meta( $check, $object_id, $meta_key, $single ) {
	return gend_society_km_read_through_meta( 'post', $check, $object_id, $meta_key, $single );
}

/**
 * Read-through for BuddyPress group meta (see gend_society_km_read_through_meta()).
 *
 * @param mixed      $check     Short-circuit value.
 * @param int        $object_id Group id.
 * @param string|int $meta_key  Meta key.
 * @param bool       $single    Single value.
 * @return mixed
 */
function gend_society_km_read_through_group_meta( $check, $object_id, $meta_key, $single ) {
	return gend_society_km_read_through_meta( 'group', $check, $object_id, $meta_key, $single );
}

/**
 * While this blog's migration has not finished, a missing NEW option answers
 * from the OLD row (default_option_{new}).
 *
 * @param mixed  $default_value Default value.
 * @param string $option        New option name.
 * @return mixed
 */
function gend_society_km_read_through_option( $default_value, $option ) {
	$rev = gend_society_km_reverse( 'option' );
	if ( ! isset( $rev[ $option ] ) || ! empty( $GLOBALS['gend_society_km_writing'] ) || get_option( 'gend_society_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return $default_value;
	}
	return get_option( $rev[ $option ], $default_value );
}

/**
 * While the network migration has not finished, a missing NEW network option
 * answers from the OLD row (default_site_option_{new}).
 *
 * @param mixed  $default_value Default value.
 * @param string $option        New option name.
 * @return mixed
 */
function gend_society_km_read_through_site_option( $default_value, $option ) {
	$rev = gend_society_km_reverse( 'site_option' );
	if ( ! isset( $rev[ $option ] ) || ! empty( $GLOBALS['gend_society_km_writing'] ) || get_site_option( 'gend_society_network_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return $default_value;
	}
	return get_site_option( $rev[ $option ], $default_value );
}

/**
 * While this blog's migration has not finished, do not schedule a NEW-hook
 * event whose OLD-hook twin (same args) is queued: the migration moves that one
 * (otherwise a recurring event would end up twice).
 *
 * @param mixed  $pre   Short-circuit value.
 * @param object $event Event being scheduled.
 * @return mixed
 */
function gend_society_km_guard_schedule( $pre, $event ) {
	if ( null !== $pre || ! is_object( $event ) || empty( $event->hook ) ) {
		return $pre;
	}
	$map = gend_society_key_map();
	$old = array_search( $event->hook, $map['cron'], true );
	if ( false === $old || get_option( 'gend_society_keys_migrated' ) === GEND_SOCIETY_KEYMAP_VERSION ) {
		return $pre;
	}
	$key = md5( serialize( isset( $event->args ) ? (array) $event->args : array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- same key derivation as WordPress core cron.
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ $old ][ $key ] ) ) {
			return false;
		}
	}
	return $pre;
}

/**
 * Register the read-through filters for whatever is still pending after this
 * request's migration attempt. Nothing is registered once both flags are set.
 */
function gend_society_km_register_pending_bridges() {
	if ( get_option( 'gend_society_keys_migrated' ) !== GEND_SOCIETY_KEYMAP_VERSION ) {
		foreach ( array_keys( gend_society_km_reverse( 'option' ) ) as $new ) {
			add_filter( 'default_option_' . $new, 'gend_society_km_read_through_option', 10, 2 );
		}
		add_filter( 'get_post_metadata', 'gend_society_km_read_through_post_meta', 5, 4 );
		add_filter( 'pre_schedule_event', 'gend_society_km_guard_schedule', 10, 2 );
	}
	if ( get_site_option( 'gend_society_network_keys_migrated' ) !== GEND_SOCIETY_KEYMAP_VERSION ) {
		foreach ( array_keys( gend_society_km_reverse( 'site_option' ) ) as $new ) {
			add_filter( 'default_site_option_' . $new, 'gend_society_km_read_through_site_option', 10, 2 );
		}
		add_filter( 'get_user_metadata', 'gend_society_km_read_through_user_meta', 5, 4 );
		add_filter( 'get_group_metadata', 'gend_society_km_read_through_group_meta', 5, 4 );
	}
}

/*
 * ---------------------------------------------------------------------------
 * Repair: duplicate / stale rows under the NEW names
 * ---------------------------------------------------------------------------
 */

/**
 * Repair the NEW-name rows left by an earlier unlocked run: for every object,
 * a new meta key ends up with exactly the old key's rows (same values, count,
 * order); a new key without an old key keeps one row per distinct value; a
 * network option keeps one row. Touches only new names from the key map (and
 * their allowlisted dynamic prefixes): never an old key, never another
 * plugin's key, never options (unique by name). Safe to run on the old plugin
 * version (define GEND_SOCIETY_KM_NO_AUTORUN, then require this file).
 *
 * @param bool $apply False: report what would change, write nothing.
 * @return array<string, mixed> Report per new key: objects, fixed, rows_before, rows_after, value_rewrites.
 */
function gend_society_km_dedupe_new_keys( $apply = false ) {
	global $wpdb;
	$map    = gend_society_key_map();
	$report = array(
		'apply' => (bool) $apply,
		'keys'  => array(),
	);

	$fix_meta = static function ( $type, $pairs, $scope ) use ( $wpdb, $apply, &$report ) {
		$t = gend_society_km_meta_table( $type );
		if ( null === $t ) {
			return;
		}
		foreach ( $pairs as $new => $old ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- repair tool: enumerate the objects carrying a NEW key; table and column names come from $wpdb / BuddyPress.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$t[1]} FROM {$t[0]} WHERE meta_key = %s ORDER BY {$t[1]}", $new ) );
			$r   = array(
				'objects'        => 0,
				'fixed'          => 0,
				'rows_before'    => 0,
				'rows_after'     => 0,
				'value_rewrites' => 0,
			);
			foreach ( (array) $ids as $id ) {
				$id       = (int) $id;
				$have     = gend_society_km_raw_meta( $type, $id, $new );
				$old_rows = '' === $old ? array() : gend_society_km_raw_meta( $type, $id, $old );
				$want     = $old_rows ? $old_rows : array_values( array_unique( $have ) );
				++$r['objects'];
				$r['rows_before'] += count( $have );
				$r['rows_after']  += count( $want );
				if ( $have === $want ) {
					continue;
				}
				++$r['fixed'];
				$distinct_have = array_unique( $have );
				$distinct_want = array_unique( $want );
				sort( $distinct_have );
				sort( $distinct_want );
				if ( $distinct_have !== $distinct_want ) {
					++$r['value_rewrites']; // a stored value differs, not only the row count / order.
				}
				if ( $apply ) {
					gend_society_km_write_meta( $type, $id, $new, $want );
				}
			}
			if ( $r['objects'] ) {
				$report['keys'][ $scope . ':' . $new ] = $r;
			}
		}
	};

	// User meta: explicit keys plus every stored key under an allowlisted new prefix.
	$user_pairs = array_flip( $map['user_meta'] );
	foreach ( $map['user_meta_prefix'] as $old_prefix => $new_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- repair tool: enumerate the stored keys of an allowlisted new prefix.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY meta_key", $wpdb->esc_like( $new_prefix ) . '%' ) );
		foreach ( (array) $keys as $key ) {
			if ( ! isset( $user_pairs[ $key ] ) ) {
				$user_pairs[ $key ] = $old_prefix . substr( $key, strlen( $new_prefix ) );
			}
		}
	}
	$fix_meta( 'user', $user_pairs, 'user' );
	if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) && function_exists( 'groups_add_groupmeta' ) ) {
		$fix_meta( 'group', array_flip( $map['group_meta'] ), 'group' );
	}

	// Network options (sitemeta has no unique key).
	if ( is_multisite() ) {
		foreach ( $map['site_option'] as $old => $new ) {
			$have = gend_society_km_raw_site_option( $new );
			if ( ! $have ) {
				continue;
			}
			$old_rows = gend_society_km_raw_site_option( $old );
			$want     = $old_rows ? array( $old_rows[0] ) : array( $have[0] );
			$r        = array(
				'objects'        => 1,
				'fixed'          => $have === $want ? 0 : 1,
				'rows_before'    => count( $have ),
				'rows_after'     => 1,
				'value_rewrites' => in_array( $want[0], $have, true ) ? 0 : 1,
			);
			if ( $apply && $have !== $want ) {
				gend_society_km_replace_site_option( $new, $want[0] );
			}
			$report['keys'][ 'site_option:' . $new ] = $r;
		}
	}

	// Post meta on every blog.
	$blogs = is_multisite() ? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) : array( get_current_blog_id() );
	foreach ( $blogs as $blog_id ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $blog_id );
		}
		$fix_meta( 'post', array_flip( $map['post_meta'] ), 'post:' . (int) $blog_id );
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
	return $report;
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

// On load: network then current blog (both one-read fast paths once done), then
// the read-through for whatever another request is still migrating.
if ( ! defined( 'GEND_SOCIETY_KM_NO_AUTORUN' ) ) {
	gend_society_migrate_network_keys();
	gend_society_migrate_blog_keys();
	gend_society_km_register_pending_bridges();
}
