<?php
/**
 * Migration exporter (customer side): a chunked, resumable export of this site in the Migration_Transit layout,
 * built for shared hosts (30 s wall clock, 128 MB, no exec, WP-Cron off).
 *
 * Plan 110-13 (MIG-04, part 1: core + DB / users). Manifest: tier customer, needs [ 'standalone' ]. No exec,
 * shell_exec, mysqldump, PharData or ZipArchive; PHP core + $wpdb only.
 *
 *  Job       option gend_society_migration_job (autoload no): the hub's { job_id, export_secret, received_at } (110-12)
 *            plus phase (db | users | content | manifest | ready | failed), tables[], parts{db,users,content}, cur (the
 *            open part), totals, staging, started_at, last_tick_at, ticks, error. Initialised on
 *            gend_society_migration_job_received.
 *  Tick      gend_society_migration_export_tick(): at most min(20 s, max_execution_time - 8 s) wall time and stops before
 *            60 % of memory_limit; one CAS lock (option gend_society_migration_lock, 90 s expiry) so a cron tick and an
 *            AJAX tick never run together. Every batch is one gzip member appended to the open part; after each member
 *            the job records the byte offset, so a killed request is rewound (ftruncate) to the last committed member.
 *  Drivers   a WP-Cron single event re-scheduled time()+5 at the end of every unfinished tick, and the dashboard
 *            header's tick loop (admin-ajax gend_society_migration_tick) while the tab is open; with DISABLE_WP_CRON the
 *            AJAX driver alone completes the export ("Keep this tab open").
 *  DB        exact-prefix tables only (another install in the same database is left out, Pitfall 7); CREATE from
 *            SHOW CREATE TABLE with the prefix rewritten to wp_ and the collation map (uca1400 -> unicode_520_ci,
 *            Aria -> InnoDB, PAGE_CHECKSUM / TRANSACTIONAL stripped); rows by keyset pagination on the primary key
 *            (OFFSET only for tables without one); values escaped after remove_placeholder_escape(); prefixed option
 *            names and usermeta keys rewritten to wp_. Parts db.NNNN of 8-16 MB uncompressed.
 *  Users     wp_users + wp_usermeta in their own parts (users.NNNN), same writer.
 *  Staging   outside the web root when a candidate is writable (dirname(ABSPATH), sys_get_temp_dir()), else
 *            wp-content/gend-society-migration-<16 hex>/ with .htaccess (both Apache syntaxes), index.php and web.config,
 *            0750. Part files have random names (<16 hex>.part); only the job option maps them.
 *
 * The content phase (wp-content tar parts) and the manifest are 110-14.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Budget (C:/tmp/livepull/phase110/w0/limits/throughput.txt, 110-03: rows_s=34933 at worst, gz_mb_s=70.7 SQL level 6,
// peak memory <= 6 MB per tick): a 20 s tick, one gzip member per batch of at most 4 MB SQL, a new part after 8 MB.
if ( ! defined( 'GEND_SOCIETY_MIGRATION_TICK_MAX_S' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_TICK_MAX_S', 20 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_TICK_MEM_FRACTION' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_TICK_MEM_FRACTION', 0.6 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES', 4 * 1048576 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_PART_ROTATE_BYTES' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_PART_ROTATE_BYTES', 8 * 1048576 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_LOCK_OPTION' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_LOCK_OPTION', 'gend_society_migration_lock' );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_LOCK_TTL' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_LOCK_TTL', 90 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_JOB_OPTION' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_JOB_OPTION', 'gend_society_migration_job' );
}

if ( ! function_exists( 'gend_society_migration_export_job' ) ) {
	/** The stored job, read from the database (never a stale object-cache copy). Null when none. */
	function gend_society_migration_export_job(): ?array {
		wp_cache_delete( GEND_SOCIETY_MIGRATION_JOB_OPTION, 'options' );
		$j = get_option( GEND_SOCIETY_MIGRATION_JOB_OPTION, null );
		return is_array( $j ) ? $j : null;
	}
}

if ( ! function_exists( 'gend_society_migration_export_save' ) ) {
	/** Commit the job (autoload no). */
	function gend_society_migration_export_save( array $job ): void {
		update_option( GEND_SOCIETY_MIGRATION_JOB_OPTION, $job, false );
	}
}

/* ---- lock ------------------------------------------------------------------------------------------------- */

if ( ! function_exists( 'gend_society_migration_lock' ) ) {
	/**
	 * Take the export lock (compare-and-set on one option row, 90 s expiry). An expired lock is taken over.
	 *
	 * @return string The lock token, '' when another tick holds it.
	 */
	function gend_society_migration_lock(): string {
		global $wpdb;
		$name  = GEND_SOCIETY_MIGRATION_LOCK_OPTION;
		$token = ( time() + GEND_SOCIETY_MIGRATION_LOCK_TTL ) . ':' . bin2hex( random_bytes( 8 ) );
		$ok    = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $ok ) {
			$cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( (int) $cur > time() ) {
				return '';
			}
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $cur ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 !== (int) $ok ) {
				return '';
			}
		}
		wp_cache_delete( $name, 'options' );
		return $token;
	}
}

if ( ! function_exists( 'gend_society_migration_unlock' ) ) {
	/** Release the lock (only the holder's token). */
	function gend_society_migration_unlock( string $token ): void {
		global $wpdb;
		if ( '' !== $token ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", GEND_SOCIETY_MIGRATION_LOCK_OPTION, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( GEND_SOCIETY_MIGRATION_LOCK_OPTION, 'options' );
		}
	}
}

/* ---- staging ---------------------------------------------------------------------------------------------- */

if ( ! function_exists( 'gend_society_migration_staging_dir' ) ) {
	/**
	 * Create the staging directory: outside the web root when a candidate is writable (filter
	 * gend_society_migration_staging_candidates), else a random directory in wp-content with deny files.
	 *
	 * @return string Absolute path ('' when nothing is writable).
	 */
	function gend_society_migration_staging_dir(): string {
		$name  = 'gend-society-migration-' . bin2hex( random_bytes( 8 ) );
		$cands = (array) apply_filters( 'gend_society_migration_staging_candidates', array( dirname( untrailingslashit( ABSPATH ) ), sys_get_temp_dir() ) );
		$web   = wp_normalize_path( untrailingslashit( ABSPATH ) );
		foreach ( $cands as $c ) {
			$c = wp_normalize_path( untrailingslashit( (string) $c ) );
			if ( '' === $c || 0 === strpos( $c . '/', $web . '/' ) || ! @is_dir( $c ) || ! wp_is_writable( $c ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts.
				continue;
			}
			$dir = $c . '/' . $name;
			if ( @mkdir( $dir, 0750 ) && wp_is_writable( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				return $dir;
			}
		}
		$dir = wp_normalize_path( WP_CONTENT_DIR ) . '/' . $name;
		if ( ! @mkdir( $dir, 0750 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return '';
		}
		$deny = array(
			'.htaccess'  => "# gend-society migration staging: never served\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\nexit;\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $deny as $f => $body ) {
			file_put_contents( $dir . '/' . $f, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- deny files in the staging dir (wp-content or outside the web root), never the plugin folder.
		}
		return $dir;
	}
}

if ( ! function_exists( 'gend_society_migration_staging_is_ours' ) ) {
	/** A staging path this module created (basename gend-society-migration-<16 hex>). */
	function gend_society_migration_staging_is_ours( string $dir ): bool {
		return '' !== $dir && (bool) preg_match( '/^gend-society-migration-[0-9a-f]{16}$/', basename( $dir ) ) && is_dir( $dir );
	}
}

/* ---- init ------------------------------------------------------------------------------------------------- */

if ( ! function_exists( 'gend_society_migration_export_tables' ) ) {
	/**
	 * This install's tables (exact prefix, other installs in the same database left out) with their primary key and
	 * a batch size from the average row length.
	 *
	 * @return array<int,array>
	 */
	function gend_society_migration_export_tables(): array {
		global $wpdb;
		$prefix = (string) $wpdb->base_prefix;
		$others = function_exists( 'gend_society_migration_db_size' ) ? (array) gend_society_migration_db_size()['other_prefixes'] : array();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, AVG_ROW_LENGTH AS a, TABLE_TYPE AS k FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$users  = array( $wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta' );
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$t = (string) $r['t'];
			if ( 'BASE TABLE' !== (string) $r['k'] ) {
				continue; // views are not copied
			}
			foreach ( $others as $o ) {
				if ( 0 === strpos( $t, (string) $o ) ) {
					continue 2;
				}
			}
			$pk = array();
			foreach ( (array) $wpdb->get_results( "SHOW KEYS FROM `{$t}` WHERE Key_name = 'PRIMARY'", ARRAY_A ) as $k ) { // phpcs:ignore WordPress.DB
				$pk[ (int) $k['Seq_in_index'] ] = (string) $k['Column_name'];
			}
			ksort( $pk );
			$avg   = max( 1, (int) $r['a'] );
			$out[] = array(
				'name'    => $t,
				'kind'    => in_array( $t, $users, true ) ? 'users' : 'db',
				'pk'      => array_values( $pk ),
				'cursor'  => null,
				'offset'  => 0,
				'batch'   => (int) max( 10, min( 2000, floor( GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES / ( 2 * $avg ) ) ) ),
				'created' => false,
				'done'    => false,
				'rows'    => 0,
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_migration_export_init' ) ) {
	/**
	 * gend_society_migration_job_received: start the export of a new job (the hub's job id + secret are kept).
	 *
	 * @param array $job { job_id, export_secret, received_at }.
	 */
	function gend_society_migration_export_init( $job = array() ): void {
		$stored = gend_society_migration_export_job();
		if ( ! is_array( $stored ) || ( $stored['job_id'] ?? '' ) !== ( $job['job_id'] ?? '' ) ) {
			return;
		}
		if ( ! empty( $stored['phase'] ) ) {
			return; // already initialised
		}
		global $wpdb;
		$staging = gend_society_migration_staging_dir();
		$stored  = array_merge(
			$stored,
			array(
				'phase'        => '' === $staging ? 'failed' : 'db',
				'prefix'       => (string) $wpdb->base_prefix,
				'tables'       => '' === $staging ? array() : gend_society_migration_export_tables(),
				'ti'           => 0,
				'parts'        => array(
					'db'      => array(),
					'users'   => array(),
					'content' => array(),
				),
				'cur'          => null,
				'totals'       => array(
					'db_bytes'      => 0,
					'rows'          => 0,
					'users_rows'    => 0,
					'content_bytes' => 0,
					'files'         => 0,
				),
				'staging'      => $staging,
				'started_at'   => time(),
				'last_tick_at' => 0,
				'ticks'        => 0,
				'error'        => '' === $staging ? __( 'This site has no writable place for the export. Free up space or ask your host.', 'gend-society' ) : null,
			)
		);
		gend_society_migration_export_save( $stored );
		if ( 'db' === $stored['phase'] && ! wp_next_scheduled( 'gend_society_migration_export_tick' ) ) {
			wp_schedule_single_event( time(), 'gend_society_migration_export_tick' );
		}
	}
}

/* ---- SQL helpers ------------------------------------------------------------------------------------------- */

if ( ! function_exists( 'gend_society_migration_sql_create' ) ) {
	/**
	 * Portable CREATE for MySQL 8.0: prefix -> wp_, MariaDB collations / engine options mapped.
	 *
	 * @param string $sql    SHOW CREATE TABLE output.
	 * @param string $prefix Source table prefix.
	 */
	function gend_society_migration_sql_create( string $sql, string $prefix ): string {
		if ( 'wp_' !== $prefix ) {
			$sql = preg_replace( '/`' . preg_quote( $prefix, '/' ) . '/', '`wp_', $sql );
		}
		$sql = preg_replace( '/\butf8mb4_uca1400_[a-z0-9_]+/i', 'utf8mb4_unicode_520_ci', $sql );
		$sql = preg_replace( '/\butf8mb3_uca1400_[a-z0-9_]+/i', 'utf8mb3_unicode_520_ci', $sql );
		$sql = preg_replace( '/\butf8_uca1400_[a-z0-9_]+/i', 'utf8mb3_unicode_520_ci', $sql );
		$sql = preg_replace( '/\bENGINE=Aria\b/i', 'ENGINE=InnoDB', $sql );
		$sql = preg_replace( '/\s*\b(?:PAGE_CHECKSUM|TRANSACTIONAL)=\d+/i', '', $sql );
		return (string) $sql;
	}
}

if ( ! function_exists( 'gend_society_migration_sql_value' ) ) {
	/** One escaped SQL value (no placeholder escape hashes left in the output). */
	function gend_society_migration_sql_value( $v ): string {
		global $wpdb;
		if ( null === $v ) {
			return 'NULL';
		}
		return "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $v ) ) . "'";
	}
}

if ( ! function_exists( 'gend_society_migration_rename_key' ) ) {
	/** A prefixed option name / usermeta key (wp_user_roles, wp_capabilities, ...) under the wp_ prefix. */
	function gend_society_migration_rename_key( string $key, string $prefix ): string {
		return ( 'wp_' !== $prefix && 0 === strpos( $key, $prefix ) ) ? 'wp_' . substr( $key, strlen( $prefix ) ) : $key;
	}
}

/* ---- part writer ------------------------------------------------------------------------------------------ */

if ( ! function_exists( 'gend_society_migration_part_open' ) ) {
	/** The open part of $kind (a new random file when none is open or the open one is another kind). */
	function gend_society_migration_part_open( array &$job, string $kind ): array {
		if ( is_array( $job['cur'] ) && $job['cur']['kind'] === $kind ) {
			return $job['cur'];
		}
		if ( is_array( $job['cur'] ) ) {
			gend_society_migration_part_close( $job );
		}
		$n          = count( $job['parts'][ $kind ] ) + 1;
		$job['cur'] = array(
			'kind'    => $kind,
			'n'       => $n,
			'file'    => bin2hex( random_bytes( 8 ) ) . '.part',
			'offset'  => 0,
			'raw'     => 0,
			'members' => 0,
		);
		return $job['cur'];
	}
}

if ( ! function_exists( 'gend_society_migration_part_append' ) ) {
	/**
	 * Append $sql as one gzip member to the open part, then record its new offset and raw size in the job.
	 *
	 * @return bool
	 */
	function gend_society_migration_part_append( array &$job, string $kind, string $sql ): bool {
		$cur  = gend_society_migration_part_open( $job, $kind );
		$path = $job['staging'] . '/' . $cur['file'];
		if ( 0 === (int) $cur['members'] ) {
			$sql = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . $sql;
		}
		$gz = gzopen( $path, 'ab6' );
		if ( ! $gz ) {
			return false;
		}
		$w = gzwrite( $gz, $sql );
		gzclose( $gz );
		if ( strlen( $sql ) !== $w ) {
			return false;
		}
		clearstatcache( true, $path );
		$job['cur']['offset']  = (int) filesize( $path );
		$job['cur']['raw']    += strlen( $sql );
		$job['cur']['members'] += 1;
		$job['totals']['db_bytes'] += strlen( $sql );
		if ( $job['cur']['raw'] >= GEND_SOCIETY_MIGRATION_PART_ROTATE_BYTES ) {
			gend_society_migration_part_close( $job );
		}
		return true;
	}
}

if ( ! function_exists( 'gend_society_migration_part_close' ) ) {
	/** Close the open part: size + sha256 into parts[kind]. */
	function gend_society_migration_part_close( array &$job ): void {
		$cur = $job['cur'];
		if ( ! is_array( $cur ) ) {
			return;
		}
		$path = $job['staging'] . '/' . $cur['file'];
		if ( (int) $cur['members'] > 0 && is_file( $path ) ) {
			clearstatcache( true, $path );
			$job['parts'][ $cur['kind'] ][] = array(
				'n'       => (int) $cur['n'],
				'file'    => (string) $cur['file'],
				'size'    => (int) filesize( $path ),
				'raw'     => (int) $cur['raw'],
				'members' => (int) $cur['members'],
				'sha256'  => (string) hash_file( 'sha256', $path ),
			);
		}
		$job['cur'] = null;
	}
}

if ( ! function_exists( 'gend_society_migration_part_repair' ) ) {
	/**
	 * After a killed request: cut the open part back to its last committed member (bytes past the recorded offset
	 * belong to a batch whose cursor was never saved).
	 *
	 * @return int Bytes cut.
	 */
	function gend_society_migration_part_repair( array $job ): int {
		$cur = $job['cur'] ?? null;
		if ( ! is_array( $cur ) ) {
			return 0;
		}
		$path = $job['staging'] . '/' . $cur['file'];
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return 0;
		}
		$size = (int) filesize( $path );
		if ( $size <= (int) $cur['offset'] ) {
			return 0;
		}
		$fh = fopen( $path, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh ) {
			ftruncate( $fh, (int) $cur['offset'] );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $size - (int) $cur['offset'];
	}
}

/* ---- the DB / users step ------------------------------------------------------------------------------------ */

if ( ! function_exists( 'gend_society_migration_export_step' ) ) {
	/**
	 * One unit of work for the current phase (a CREATE, or one batch of rows of one table). Mutates $job.
	 *
	 * @return string 'more' | 'phase' (the phase finished) | 'error'.
	 */
	function gend_society_migration_export_step( array &$job ): string {
		global $wpdb;
		$phase  = (string) $job['phase'];
		$prefix = (string) $job['prefix'];
		$ti     = (int) $job['ti'];
		$tables = $job['tables'];
		while ( $ti < count( $tables ) && ( $tables[ $ti ]['kind'] !== $phase || $tables[ $ti ]['done'] ) ) {
			++$ti;
		}
		$job['ti'] = $ti;
		if ( $ti >= count( $tables ) ) {
			gend_society_migration_part_close( $job );
			$job['ti'] = 0;
			return 'phase';
		}
		$t   = $tables[ $ti ];
		$src = (string) $t['name'];
		$dst = 'wp_' . substr( $src, strlen( $prefix ) );

		if ( empty( $t['created'] ) ) {
			$row = $wpdb->get_row( "SHOW CREATE TABLE `{$src}`", ARRAY_N ); // phpcs:ignore WordPress.DB
			if ( ! is_array( $row ) || empty( $row[1] ) ) {
				$job['error'] = 'SHOW CREATE TABLE ' . $src . ' failed';
				return 'error';
			}
			$sql = "DROP TABLE IF EXISTS `{$dst}`;\n" . gend_society_migration_sql_create( (string) $row[1], $prefix ) . ";\n";
			if ( ! gend_society_migration_part_append( $job, $phase, $sql ) ) {
				$job['error'] = 'Writing the export failed (disk full?)';
				return 'error';
			}
			$job['tables'][ $ti ]['created'] = true;
			return 'more';
		}

		$limit = (int) $t['batch'];
		$pk    = (array) $t['pk'];
		if ( $pk ) {
			$cols  = '`' . implode( '`, `', $pk ) . '`';
			$where = '';
			if ( is_array( $t['cursor'] ) ) {
				$ph    = implode( ', ', array_fill( 0, count( $pk ), '%s' ) );
				$where = $wpdb->prepare( ' WHERE (' . $cols . ') > (' . $ph . ')', $t['cursor'] ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per key column, built above.
			}
			$rows = $wpdb->get_results( "SELECT * FROM `{$src}`{$where} ORDER BY {$cols} LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared above; table and column names come from SHOW KEYS / information_schema.
		} else {
			$limit = min( $limit, 200 );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$src}` LIMIT %d OFFSET %d", $limit, (int) $t['offset'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		if ( '' !== (string) $wpdb->last_error ) {
			$job['error'] = 'Reading ' . $src . ' failed: ' . $wpdb->last_error;
			return 'error';
		}
		if ( ! $rows ) {
			$job['tables'][ $ti ]['done'] = true;
			++$job['ti'];
			return 'more';
		}
		$opt  = $src === $prefix . 'options';
		$um   = $src === $prefix . 'usermeta';
		$out  = '';
		$cnt  = 0;
		$head = 'INSERT INTO `' . $dst . '` (`' . implode( '`, `', array_keys( $rows[0] ) ) . '`) VALUES ';
		$vals = array();
		$written = 0;
		foreach ( $rows as $r ) {
			if ( $opt && isset( $r['option_name'] ) && 0 === strpos( (string) $r['option_name'], 'gend_society_migration_' ) ) {
				++$cnt; // this exporter's own state (job + secret, lock, staging) never travels
				continue;
			}
			if ( $opt && isset( $r['option_name'] ) ) {
				$r['option_name'] = gend_society_migration_rename_key( (string) $r['option_name'], $prefix );
			}
			if ( $um && isset( $r['meta_key'] ) ) {
				$r['meta_key'] = gend_society_migration_rename_key( (string) $r['meta_key'], $prefix ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			$vals[] = '(' . implode( ', ', array_map( 'gend_society_migration_sql_value', array_values( $r ) ) ) . ')';
			++$cnt;
			++$written;
			$len = strlen( $out ) + array_sum( array_map( 'strlen', $vals ) );
			if ( count( $vals ) >= 100 || $len >= GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES ) {
				$out .= $head . implode( ",\n", $vals ) . ";\n";
				$vals = array();
				if ( strlen( $out ) >= GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES ) {
					break;
				}
			}
		}
		if ( $vals ) {
			$out .= $head . implode( ",\n", $vals ) . ";\n";
		}
		if ( '' !== $out && ! gend_society_migration_part_append( $job, $phase, $out ) ) {
			$job['error'] = 'Writing the export failed (disk full?)';
			return 'error';
		}
		$last = $rows[ $cnt - 1 ];
		if ( $pk ) {
			$c = array();
			foreach ( $pk as $col ) {
				$c[] = (string) $last[ $col ];
			}
			$job['tables'][ $ti ]['cursor'] = $c;
		} else {
			$job['tables'][ $ti ]['offset'] = (int) $t['offset'] + $cnt;
		}
		$job['tables'][ $ti ]['rows'] += $written;
		$job['totals'][ 'users' === $phase ? 'users_rows' : 'rows' ] += $written;
		if ( $cnt < count( $rows ) || strlen( $out ) >= GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES ) {
			$job['tables'][ $ti ]['batch'] = max( 10, (int) floor( $limit * GEND_SOCIETY_MIGRATION_MEMBER_MAX_BYTES / max( 1, 2 * strlen( $out ) ) ) );
		}
		if ( count( $rows ) < $limit && $cnt === count( $rows ) ) {
			$job['tables'][ $ti ]['done'] = true;
			++$job['ti'];
		}
		return 'more';
	}
}

/* ---- tick ------------------------------------------------------------------------------------------------ */

if ( ! function_exists( 'gend_society_migration_bytes_of' ) ) {
	/** php.ini size ('128M') in bytes; -1 = unlimited. */
	function gend_society_migration_bytes_of( string $v ): int {
		$v = trim( $v );
		if ( '' === $v || '-1' === $v ) {
			return -1;
		}
		$n = (int) $v;
		switch ( strtolower( substr( $v, -1 ) ) ) {
			case 'g':
				$n *= 1024;
				// fall through.
			case 'm':
				$n *= 1024;
				// fall through.
			case 'k':
				$n *= 1024;
		}
		return $n;
	}
}

if ( ! function_exists( 'gend_society_migration_export_tick' ) ) {
	/**
	 * Run the export for one budgeted tick (cron or AJAX driver).
	 *
	 * @param string $driver 'cron' | 'ajax' | 'cli'.
	 * @return array{status:string,phase:string,ticks:int,elapsed:float,peak:int}
	 */
	function gend_society_migration_export_tick( string $driver = 'cron' ): array {
		$t0  = microtime( true );
		$job = gend_society_migration_export_job();
		if ( ! $job || empty( $job['phase'] ) ) {
			return array( 'status' => 'none', 'phase' => '', 'ticks' => 0, 'elapsed' => 0.0, 'peak' => 0 );
		}
		if ( ! in_array( $job['phase'], array( 'db', 'users' ), true ) ) {
			return array( 'status' => 'idle', 'phase' => (string) $job['phase'], 'ticks' => (int) $job['ticks'], 'elapsed' => 0.0, 'peak' => 0 );
		}
		$token = gend_society_migration_lock();
		if ( '' === $token ) {
			return array( 'status' => 'locked', 'phase' => (string) $job['phase'], 'ticks' => (int) $job['ticks'], 'elapsed' => 0.0, 'peak' => 0 );
		}
		$status = 'running';
		try {
			$job = gend_society_migration_export_job(); // fresh, under the lock
			if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- opportunistic only (often disabled on shared hosts; the tick budget does not rely on it).
			}
			$met      = (int) ini_get( 'max_execution_time' );
			$budget   = (float) apply_filters( 'gend_society_migration_tick_budget', $met > 0 ? max( 2, min( GEND_SOCIETY_MIGRATION_TICK_MAX_S, $met - 8 ) ) : GEND_SOCIETY_MIGRATION_TICK_MAX_S );
			$deadline = $t0 + $budget;
			$lim      = gend_society_migration_bytes_of( (string) ini_get( 'memory_limit' ) );
			$mem_cap  = $lim > 0 ? (int) ( $lim * GEND_SOCIETY_MIGRATION_TICK_MEM_FRACTION ) : PHP_INT_MAX;
			$cut = gend_society_migration_part_repair( $job );
			if ( $cut > 0 ) {
				$job['repairs'][] = array(
					'at'    => time(),
					'bytes' => $cut,
					'file'  => (string) $job['cur']['file'],
				);
			}
			++$job['ticks'];
			$job['last_tick_at'] = time();
			while ( microtime( true ) < $deadline && memory_get_usage() < $mem_cap ) {
				$r = gend_society_migration_export_step( $job );
				if ( 'error' === $r ) {
					$job['phase'] = 'failed';
					$status       = 'failed';
					break;
				}
				if ( 'phase' === $r ) {
					$job['phase'] = 'db' === $job['phase'] ? 'users' : 'content';
				}
				gend_society_migration_export_save( $job );
				if ( ! in_array( $job['phase'], array( 'db', 'users' ), true ) ) {
					$status = 'done';
					break;
				}
			}
			gend_society_migration_export_save( $job );
		} finally {
			gend_society_migration_unlock( $token );
		}
		if ( 'running' === $status && ! wp_next_scheduled( 'gend_society_migration_export_tick' ) ) {
			wp_schedule_single_event( time() + 5, 'gend_society_migration_export_tick' );
		}
		return array(
			'status'  => $status,
			'phase'   => (string) $job['phase'],
			'ticks'   => (int) $job['ticks'],
			'elapsed' => round( microtime( true ) - $t0, 3 ),
			'peak'    => (int) memory_get_peak_usage(),
		);
	}
}

if ( ! function_exists( 'gend_society_migration_export_status' ) ) {
	/** Export progress for the dashboard and the hub report (never the secret). */
	function gend_society_migration_export_status(): array {
		$job = gend_society_migration_export_job();
		if ( ! $job || empty( $job['phase'] ) ) {
			return array( 'phase' => '' );
		}
		$done = 0;
		foreach ( (array) $job['tables'] as $t ) {
			$done += ! empty( $t['done'] ) ? 1 : 0;
		}
		return array(
			'job_id'       => (string) $job['job_id'],
			'phase'        => (string) $job['phase'],
			'tables_done'  => $done,
			'tables_total' => count( (array) $job['tables'] ),
			'parts'        => array_map( 'count', (array) $job['parts'] ),
			'totals'       => (array) $job['totals'],
			'ticks'        => (int) $job['ticks'],
			'last_tick_at' => (int) $job['last_tick_at'],
			'error'        => $job['error'],
		);
	}
}

if ( ! function_exists( 'gend_society_migration_export_active' ) ) {
	/** The export still has DB / users work to do (the header's tick loop runs). */
	function gend_society_migration_export_active(): bool {
		$job = gend_society_migration_export_job();
		return is_array( $job ) && in_array( (string) ( $job['phase'] ?? '' ), array( 'db', 'users' ), true );
	}
}

if ( ! function_exists( 'gend_society_migration_export_wipe' ) ) {
	/**
	 * gend_society_migration_cleanup: delete the job's staging directory (only one this module made), the lock and
	 * the queued tick; the job option goes too when it is this job.
	 *
	 * @param array $job The job being cleaned up (default: the stored one).
	 */
	function gend_society_migration_export_wipe( $job = array() ): void {
		$job = is_array( $job ) && $job ? $job : (array) gend_society_migration_export_job();
		$dir = (string) ( $job['staging'] ?? '' );
		if ( gend_society_migration_staging_is_ours( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) {
				$f->isDir() && ! $f->isLink() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		delete_option( GEND_SOCIETY_MIGRATION_LOCK_OPTION );
		wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
		$stored = gend_society_migration_export_job();
		if ( $stored && ( $stored['job_id'] ?? '' ) === ( $job['job_id'] ?? '' ) ) {
			delete_option( GEND_SOCIETY_MIGRATION_JOB_OPTION );
		}
	}
}

if ( ! function_exists( 'gend_society_migration_tick_ajax' ) ) {
	/** admin-ajax gend_society_migration_tick (the dashboard header loop): manage_options + the panel nonce. */
	function gend_society_migration_tick_ajax(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
		}
		check_ajax_referer( 'gs_membership_action', 'nonce' );
		wp_send_json_success( gend_society_migration_export_tick( 'ajax' ) );
	}
}

add_action( 'gend_society_migration_job_received', 'gend_society_migration_export_init', 10, 1 );
add_action( 'gend_society_migration_cleanup', 'gend_society_migration_export_wipe', 10, 1 );
add_action(
	'gend_society_migration_export_tick',
	static function () {
		gend_society_migration_export_tick( 'cron' );
	}
);
add_action( 'wp_ajax_gend_society_migration_tick', 'gend_society_migration_tick_ajax' );
