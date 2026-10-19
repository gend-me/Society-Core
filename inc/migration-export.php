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
 *  Content   (110-14, M1) wp-content minus gend_society_migration_excludes() (host drop-ins / mu-plugins, caches,
 *            backups, staging, plugins/gend-society*, files over 2 GB, symlinks leaving wp-content), in the
 *            uploads.tar.gz slot. First a JSONL index part (resumable directory walk, committed byte offset), then tar
 *            parts of about 32 MB: GNU-format ustar entries (././@LongLink for names over 100 bytes; files 0644,
 *            dirs 0755, uid / gid 0; symlinks inside wp-content as relative type 2 entries), paths relative to
 *            wp-content, one gzip member (level 1) per batch of at most 4 MB, a file larger than a batch copied across
 *            batches and ticks with an in-entry cursor. No end-of-archive blocks except one final 1024-zero member at
 *            the end of the last part, so the parts concatenated in order are ONE .tar.gz (an entry may span parts).
 *  Manifest  manifest.json format 2 (site facts, part sizes + sha256, totals, the content-freeze watermark read at
 *            content-phase start, excluded[], alt_base); then the job phase is ready.
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
// Content (110-14): media at gzip level 1 runs at >= 19.5 MB/s on the slowest limit bed (throughput.txt), so a 4 MB
// member takes about 0.2 s; parts of about 32 MB stay small enough for a 30 s host cut with Range resume (110-03).
if ( ! defined( 'GEND_SOCIETY_MIGRATION_CONTENT_MEMBER_BYTES' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_CONTENT_MEMBER_BYTES', 4 * 1048576 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_CONTENT_PART_BYTES' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_CONTENT_PART_BYTES', 32 * 1048576 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_INDEX_STEP' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_INDEX_STEP', 2000 );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_LIST_MAX' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_LIST_MAX', 1000 );
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
					'dirs'          => 0,
					'links'         => 0,
					'excluded'      => 0,
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

/* ---- content phase (110-14) ------------------------------------------------------------------------------ */

if ( ! function_exists( 'gend_society_migration_watermark' ) ) {
	/**
	 * The content-freeze watermark (Phase 111's delta anchor), read from the live database.
	 *
	 * @return array{max_post_id:int,max_comment_id:int,max_order_id:int,posts_modified_gmt:string}
	 */
	function gend_society_migration_watermark(): array {
		global $wpdb;
		$hpos  = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order = '' !== $hpos
			? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}wc_orders" ) // phpcs:ignore WordPress.DB
			: (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_placehold')" ); // phpcs:ignore WordPress.DB
		return array(
			'max_post_id'        => (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB
			'max_comment_id'     => (int) $wpdb->get_var( "SELECT COALESCE(MAX(comment_ID), 0) FROM {$wpdb->comments}" ), // phpcs:ignore WordPress.DB
			'max_order_id'       => $order,
			'posts_modified_gmt' => (string) $wpdb->get_var( "SELECT COALESCE(MAX(post_modified_gmt), '') FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB
		);
	}
}

if ( ! function_exists( 'gend_society_migration_content_init' ) ) {
	/** Start the content phase: the index part, the cursors and the watermark (read now, at content-phase start). */
	function gend_society_migration_content_init( array &$job ): void {
		$job['content']   = array(
			'index'   => array(
				'file'   => bin2hex( random_bytes( 8 ) ) . '.idx',
				'offset' => 0,
				'lines'  => 0,
				'done'   => false,
				'stack'  => array( '' ),
				'dir'    => null,
				'pos'    => 0,
			),
			'ii'      => 0,
			'entry'   => null,
			'final'   => false,
			'changed' => array(),
		);
		$job['excluded']  = array();
		$job['watermark'] = gend_society_migration_watermark();
	}
}

if ( ! function_exists( 'gend_society_migration_content_note' ) ) {
	/** Record an excluded (or changed) path; the lists are capped, the totals are not. */
	function gend_society_migration_content_note( array &$job, string $list, string $rel, string $reason ): void {
		if ( 'excluded' === $list ) {
			$job['totals']['excluded'] = (int) ( $job['totals']['excluded'] ?? 0 ) + 1;
			if ( count( $job['excluded'] ) < GEND_SOCIETY_MIGRATION_LIST_MAX ) {
				$job['excluded'][] = array(
					'path'   => $rel,
					'reason' => $reason,
				);
			}
			return;
		}
		if ( count( $job['content']['changed'] ) < GEND_SOCIETY_MIGRATION_LIST_MAX ) {
			$job['content']['changed'][] = array(
				'path'   => $rel,
				'reason' => $reason,
			);
		}
	}
}

if ( ! function_exists( 'gend_society_migration_relpath' ) ) {
	/** Relative path from directory $from to $to (both absolute, '/' separated). */
	function gend_society_migration_relpath( string $from, string $to ): string {
		$a = explode( '/', trim( $from, '/' ) );
		$b = explode( '/', trim( $to, '/' ) );
		while ( $a && $b && $a[0] === $b[0] ) {
			array_shift( $a );
			array_shift( $b );
		}
		return implode( '/', array_merge( array_fill( 0, count( $a ), '..' ), $b ) );
	}
}

if ( ! function_exists( 'gend_society_migration_index_line' ) ) {
	/** One JSONL index line (p64 = base64 path when the name is not valid UTF-8). */
	function gend_society_migration_index_line( array $e ): string {
		if ( ! preg_match( '//u', $e['p'] ) ) {
			$e['p64'] = base64_encode( $e['p'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe file names in the index.
			unset( $e['p'] );
		}
		return (string) wp_json_encode( $e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
}

if ( ! function_exists( 'gend_society_migration_index_step' ) ) {
	/**
	 * One step of the wp-content index walk: a stack of directories still to list plus the position in the current
	 * one (a resume re-reads one listing, never the tree). Symlinks are never followed. Appends JSONL lines to the
	 * index part and commits the byte offset with the cursor.
	 *
	 * @return bool False when the index part cannot be written.
	 */
	function gend_society_migration_index_step( array &$job ): bool {
		$ix    = $job['content']['index'];
		$root  = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' );
		$real  = rtrim( wp_normalize_path( (string) realpath( $root ) ), '/' );
		$ex    = gend_society_migration_excludes();
		$max   = max( 1, (int) apply_filters( 'gend_society_migration_index_step_entries', GEND_SOCIETY_MIGRATION_INDEX_STEP ) );
		$buf   = '';
		$lines = 0;
		$seen  = 0;
		while ( empty( $ix['done'] ) && $seen < $max ) {
			if ( null === $ix['dir'] ) {
				if ( empty( $ix['stack'] ) ) {
					$ix['done'] = true;
					break;
				}
				$ix['dir'] = (string) array_pop( $ix['stack'] );
				$ix['pos'] = 0;
			}
			$rel     = (string) $ix['dir'];
			$abs     = '' === $rel ? $root : $root . '/' . $rel;
			$entries = is_dir( $abs ) ? @scandir( $abs ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is skipped.
			$entries = is_array( $entries ) ? array_values( array_diff( $entries, array( '.', '..' ) ) ) : array();
			$count   = count( $entries );
			$out     = false;
			for ( $i = (int) $ix['pos']; $i < $count; $i++ ) {
				if ( $seen >= $max ) {
					$ix['pos'] = $i;
					$out       = true;
					break;
				}
				++$seen;
				$r = '' === $rel ? $entries[ $i ] : $rel . '/' . $entries[ $i ];
				$a = $root . '/' . $r;
				if ( is_link( $a ) ) {
					$t = realpath( $a );
					$t = false === $t ? '' : wp_normalize_path( $t );
					if ( '' !== $t && 0 === strpos( $t . '/', $real . '/' ) && $t !== $real ) {
						$from  = wp_normalize_path( (string) realpath( dirname( $a ) ) );
						$buf  .= gend_society_migration_index_line(
							array(
								'p' => $r,
								't' => 'l',
								'l' => gend_society_migration_relpath( $from, $t ),
							)
						);
						++$lines;
					} else {
						gend_society_migration_content_note( $job, 'excluded', $r, 'symlink' );
					}
					continue;
				}
				if ( is_dir( $a ) ) {
					$why = gend_society_migration_excluded( $r, true );
					if ( '' !== $why ) {
						gend_society_migration_content_note( $job, 'excluded', $r, $why );
						continue;
					}
					$ix['stack'][] = $r;
					$buf          .= gend_society_migration_index_line(
						array(
							'p' => $r,
							't' => 'd',
							'm' => (int) @filemtime( $a ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						)
					);
					++$lines;
					continue;
				}
				if ( ! is_file( $a ) ) {
					continue; // sockets, fifos
				}
				$why = gend_society_migration_excluded( $r, false );
				if ( '' === $why ) {
					$size = (int) @filesize( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$why  = $size > (int) $ex['max_file_bytes'] ? 'too_large' : ( is_readable( $a ) ? '' : 'unreadable' );
				}
				if ( '' !== $why ) {
					gend_society_migration_content_note( $job, 'excluded', $r, $why );
					continue;
				}
				$buf .= gend_society_migration_index_line(
					array(
						'p' => $r,
						't' => 'f',
						's' => $size,
						'm' => (int) @filemtime( $a ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					)
				);
				++$lines;
			}
			if ( $out ) {
				break;
			}
			$ix['dir'] = null;
			$ix['pos'] = 0;
		}
		$path = $job['staging'] . '/' . $ix['file'];
		if ( '' !== $buf ) {
			$fh = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh || strlen( $buf ) !== fwrite( $fh, $buf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				if ( $fh ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
				return false;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		} elseif ( ! is_file( $path ) ) {
			$fh = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an empty index part in the staging dir.
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
		clearstatcache( true, $path );
		$ix['offset']              = (int) filesize( $path );
		$ix['lines']              += $lines;
		$job['content']['index']   = $ix;
		return true;
	}
}

if ( ! function_exists( 'gend_society_migration_index_repair' ) ) {
	/** After a killed request: cut the index part back to its committed offset. @return int Bytes cut. */
	function gend_society_migration_index_repair( array $job ): int {
		$ix = $job['content']['index'] ?? null;
		if ( ! is_array( $ix ) || empty( $job['staging'] ) ) {
			return 0;
		}
		$path = $job['staging'] . '/' . $ix['file'];
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || (int) filesize( $path ) <= (int) $ix['offset'] ) {
			return 0;
		}
		$size = (int) filesize( $path );
		$fh   = fopen( $path, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh ) {
			ftruncate( $fh, (int) $ix['offset'] );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $size - (int) $ix['offset'];
	}
}

if ( ! function_exists( 'gend_society_migration_tar_header' ) ) {
	/**
	 * GNU-format tar header(s) for one entry: a ././@LongLink ('L') entry first when the name is over 100 bytes ('K'
	 * for a long symlink target). Owner 0:0, no user / group names.
	 *
	 * @param string $name     Path relative to wp-content (directories end with '/').
	 * @param string $type     '0' file, '5' directory, '2' symlink.
	 * @param int    $size     Data size (files).
	 * @param int    $mtime    Modification time.
	 * @param string $linkname Symlink target.
	 */
	function gend_society_migration_tar_header( string $name, string $type, int $size, int $mtime, string $linkname = '' ): string {
		$block = static function ( string $n, string $t, int $mode, int $sz, int $mt, string $ln ): string {
			$h   = str_pad( substr( $n, 0, 100 ), 100, "\0" )
				. sprintf( '%07o', $mode ) . "\0" . sprintf( '%07o', 0 ) . "\0" . sprintf( '%07o', 0 ) . "\0"
				. sprintf( '%011o', $sz ) . "\0" . sprintf( '%011o', max( 0, min( 8589934591, $mt ) ) ) . "\0"
				. '        ' . $t . str_pad( substr( $ln, 0, 100 ), 100, "\0" )
				. "ustar  \0" . str_repeat( "\0", 32 + 32 + 8 + 8 + 155 + 12 );
			$sum = array_sum( unpack( 'C*', $h ) );
			return substr( $h, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $h, 156 );
		};
		$pad  = static function ( string $d ): string {
			return $d . str_repeat( "\0", ( 512 - strlen( $d ) % 512 ) % 512 );
		};
		$out  = '';
		if ( strlen( $linkname ) > 100 ) {
			$out .= $block( '././@LongLink', 'K', 0644, strlen( $linkname ) + 1, 0, '' ) . $pad( $linkname . "\0" );
		}
		if ( strlen( $name ) > 100 ) {
			$out .= $block( '././@LongLink', 'L', 0644, strlen( $name ) + 1, 0, '' ) . $pad( $name . "\0" );
		}
		$mode = '5' === $type ? 0755 : ( '2' === $type ? 0777 : 0644 );
		return $out . $block( $name, $type, $mode, '0' === $type ? $size : 0, $mtime, $linkname );
	}
}

if ( ! function_exists( 'gend_society_migration_content_step' ) ) {
	/**
	 * One unit of content work: an index step while the index is not done, else one gzip member (level 1) of tar
	 * data appended to the open content part.
	 *
	 * @return string 'more' | 'phase' | 'error'.
	 */
	function gend_society_migration_content_step( array &$job ): string {
		if ( ! isset( $job['content'] ) || ! is_array( $job['content'] ) ) {
			gend_society_migration_content_init( $job );
		}
		if ( empty( $job['content']['index']['done'] ) ) {
			if ( ! gend_society_migration_index_step( $job ) ) {
				$job['error'] = 'Writing the export failed (disk full?)';
				return 'error';
			}
			return 'more';
		}
		if ( ! empty( $job['content']['final'] ) ) {
			return 'phase';
		}
		$root = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' );
		$cur  = gend_society_migration_part_open( $job, 'content' );
		$path = $job['staging'] . '/' . $cur['file'];
		$gz   = gzopen( $path, 'ab1' );
		if ( ! $gz ) {
			$job['error'] = 'Writing the export failed (disk full?)';
			return 'error';
		}
		$c       = &$job['content'];
		$written = 0;
		$failed  = false;
		$put     = static function ( string $data ) use ( $gz, &$written, &$failed ): void {
			if ( '' !== $data && strlen( $data ) !== gzwrite( $gz, $data ) ) {
				$failed = true;
			}
			$written += strlen( $data );
		};
		$ixf = $job['staging'] . '/' . $c['index']['file'];
		$ixh = null;
		while ( $written < GEND_SOCIETY_MIGRATION_CONTENT_MEMBER_BYTES && ! $failed ) {
			if ( is_array( $c['entry'] ) ) {
				$e  = $c['entry'];
				$fh = @fopen( $root . '/' . $e['p'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( $fh && $e['done'] > 0 ) {
					fseek( $fh, (int) $e['done'] );
				}
				while ( $e['done'] < $e['size'] && $written < GEND_SOCIETY_MIGRATION_CONTENT_MEMBER_BYTES && ! $failed ) {
					$want = (int) min( 1048576, $e['size'] - $e['done'], GEND_SOCIETY_MIGRATION_CONTENT_MEMBER_BYTES - $written );
					$d    = $fh ? fread( $fh, $want ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false === $d || '' === $d ) {
						$d = str_repeat( "\0", $want ); // the file shrank or vanished while it was copied: keep the entry size
						if ( empty( $e['short'] ) ) {
							$e['short'] = true;
							gend_society_migration_content_note( $job, 'changed', $e['p'], 'shrank' );
						}
					}
					$put( $d );
					$e['done'] += strlen( $d );
				}
				if ( $fh ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
				if ( $e['done'] >= $e['size'] ) {
					$put( str_repeat( "\0", ( 512 - $e['size'] % 512 ) % 512 ) );
					$c['entry'] = null;
				} else {
					$c['entry'] = $e;
				}
				continue;
			}
			if ( null === $ixh ) {
				$ixh = fopen( $ixf, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $ixh ) {
					$failed = true;
					break;
				}
				fseek( $ixh, (int) $c['ii'] );
			}
			$line = (int) $c['ii'] < (int) $c['index']['offset'] ? fgets( $ixh ) : false;
			if ( false === $line || '' === $line ) {
				if ( $written > 0 ) {
					break; // the end-of-archive goes into a gzip member of its own (next step)
				}
				$put( str_repeat( "\0", 1024 ) ); // the one end-of-archive, at the very end of the stream
				$c['final'] = true;
				break;
			}
			$c['ii'] += strlen( $line );
			$d        = json_decode( $line, true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$p = isset( $d['p64'] ) ? (string) base64_decode( (string) $d['p64'] ) : (string) ( $d['p'] ?? '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$t = (string) ( $d['t'] ?? '' );
			if ( '' === $p ) {
				continue;
			}
			if ( 'd' === $t ) {
				$put( gend_society_migration_tar_header( $p . '/', '5', 0, (int) ( $d['m'] ?? 0 ) ) );
				$job['totals']['dirs'] = (int) ( $job['totals']['dirs'] ?? 0 ) + 1;
				continue;
			}
			if ( 'l' === $t ) {
				$put( gend_society_migration_tar_header( $p, '2', 0, time(), (string) ( $d['l'] ?? '' ) ) );
				$job['totals']['links'] = (int) ( $job['totals']['links'] ?? 0 ) + 1;
				continue;
			}
			$a = $root . '/' . $p;
			clearstatcache( true, $a );
			if ( ! is_file( $a ) || is_link( $a ) ) {
				gend_society_migration_content_note( $job, 'changed', $p, 'vanished' );
				continue;
			}
			$size = (int) filesize( $a );
			if ( $size > (int) gend_society_migration_excludes()['max_file_bytes'] ) {
				gend_society_migration_content_note( $job, 'changed', $p, 'too_large' );
				continue;
			}
			$put( gend_society_migration_tar_header( $p, '0', $size, (int) filemtime( $a ) ) );
			$job['totals']['files'] = (int) $job['totals']['files'] + 1;
			if ( $size > 0 ) {
				$c['entry'] = array(
					'p'    => $p,
					'size' => $size,
					'done' => 0,
				);
			}
		}
		if ( $ixh ) {
			fclose( $ixh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		unset( $c );
		gzclose( $gz );
		if ( $failed ) {
			$job['error'] = 'Writing the export failed (disk full?)';
			return 'error';
		}
		clearstatcache( true, $path );
		$job['cur']['offset']           = (int) filesize( $path );
		$job['cur']['raw']             += $written;
		$job['cur']['members']         += 1;
		$job['totals']['content_bytes'] = (int) $job['totals']['content_bytes'] + $written;
		if ( ! empty( $job['content']['final'] ) ) {
			gend_society_migration_part_close( $job );
			return 'phase';
		}
		if ( $job['cur']['raw'] >= GEND_SOCIETY_MIGRATION_CONTENT_PART_BYTES ) {
			gend_society_migration_part_close( $job );
		}
		return 'more';
	}
}

if ( ! function_exists( 'gend_society_migration_manifest_step' ) ) {
	/**
	 * Write manifest.json format 2 into staging (random name, mapped in the job). Never the secret or a staging path.
	 *
	 * @return string 'phase' | 'error'.
	 */
	function gend_society_migration_manifest_step( array &$job ): string {
		global $wpdb;
		$names = array_column( (array) $job['tables'], 'name' );
		$colls = array();
		if ( $names ) {
			$ph    = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
			$colls = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$ph}) ORDER BY TABLE_COLLATION", $names ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per table name, built above.
		}
		$parts = array();
		foreach ( array( 'db', 'users', 'content' ) as $k ) {
			$parts[ $k ] = array();
			foreach ( (array) $job['parts'][ $k ] as $p ) {
				$parts[ $k ][] = array(
					'n'      => (int) $p['n'],
					'size'   => (int) $p['size'],
					'sha256' => (string) $p['sha256'],
					'raw'    => (int) $p['raw'],
				);
			}
		}
		$home = untrailingslashit( home_url() );
		$m    = array(
			'format'              => 2,
			'source_kind'         => 'external',
			'job_id'              => (string) $job['job_id'],
			'source_url'          => $home,
			'home_url'            => (string) get_option( 'home' ),
			'site_url'            => (string) get_option( 'siteurl' ),
			'table_prefix'        => 'wp_',
			'source_table_prefix' => (string) $job['prefix'],
			'db_charset'          => (string) $wpdb->charset,
			'db_collations'       => array_values( array_filter( array_map( 'strval', $colls ) ) ),
			'mysql_version'       => (string) $wpdb->get_var( 'SELECT VERSION()' ), // phpcs:ignore WordPress.DB
			'php_version'         => PHP_VERSION,
			'wp_version'          => (string) get_bloginfo( 'version' ),
			'active_plugins'      => array_values( (array) get_option( 'active_plugins', array() ) ),
			'active_theme'        => (string) get_stylesheet(),
			'template'            => (string) get_option( 'template' ),
			'stylesheet'          => (string) get_option( 'stylesheet' ),
			'content_root'        => 'wp-content',
			'content_stream'      => 'The content parts concatenated in order are one tar.gz (entries may span parts).',
			'parts'               => $parts,
			'totals'              => (array) $job['totals'],
			'exported_at'         => gmdate( 'c' ),
			'watermark'           => (array) ( $job['watermark'] ?? array() ),
			'excluded'            => array_values( (array) ( $job['excluded'] ?? array() ) ),
			'changed'             => array_values( (array) ( $job['content']['changed'] ?? array() ) ),
			'alt_base'            => $home . '/?rest_route=',
		);
		$json = wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$file = bin2hex( random_bytes( 8 ) ) . '.json';
		$path = $job['staging'] . '/' . $file;
		if ( ! is_string( $json ) || strlen( $json ) !== (int) file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- the staging dir (outside the web root or wp-content), never the plugin folder.
			$job['error'] = 'Writing the export failed (disk full?)';
			return 'error';
		}
		$job['manifest'] = array(
			'file'   => $file,
			'size'   => strlen( $json ),
			'sha256' => hash( 'sha256', $json ),
		);
		return 'phase';
	}
}

if ( ! function_exists( 'gend_society_migration_export_dispatch' ) ) {
	/** One unit of work for the job's current phase. @return string 'more' | 'phase' | 'error'. */
	function gend_society_migration_export_dispatch( array &$job ): string {
		switch ( (string) $job['phase'] ) {
			case 'db':
			case 'users':
				return gend_society_migration_export_step( $job );
			case 'content':
				return gend_society_migration_content_step( $job );
			case 'manifest':
				return gend_society_migration_manifest_step( $job );
		}
		return 'phase';
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

if ( ! function_exists( 'gend_society_migration_export_running_phases' ) ) {
	/** Phases a tick works on (ready and failed are final). */
	function gend_society_migration_export_running_phases(): array {
		return array( 'db', 'users', 'content', 'manifest' );
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
		if ( ! in_array( $job['phase'], gend_society_migration_export_running_phases(), true ) ) {
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
			$icut = gend_society_migration_index_repair( $job );
			if ( $icut > 0 ) {
				$job['repairs'][] = array(
					'at'    => time(),
					'bytes' => $icut,
					'file'  => (string) $job['content']['index']['file'],
				);
			}
			++$job['ticks'];
			$job['last_tick_at'] = time();
			$next                = array(
				'db'       => 'users',
				'users'    => 'content',
				'content'  => 'manifest',
				'manifest' => 'ready',
			);
			do { // at least one unit of work per tick, then until the budget or the memory cap
				$r = gend_society_migration_export_dispatch( $job );
				if ( 'error' === $r ) {
					$job['phase'] = 'failed';
					$status       = 'failed';
					break;
				}
				if ( 'phase' === $r ) {
					$job['phase'] = $next[ $job['phase'] ] ?? 'ready';
					if ( 'content' === $job['phase'] && ! isset( $job['content'] ) ) {
						gend_society_migration_content_init( $job ); // the watermark is read at content-phase start
					}
					if ( 'ready' === $job['phase'] ) {
						$job['ready_at'] = time();
					}
				}
				gend_society_migration_export_save( $job );
				if ( 'ready' === $job['phase'] ) {
					$status = 'done';
					break;
				}
			} while ( microtime( true ) < $deadline && memory_get_usage() < $mem_cap );
			gend_society_migration_export_save( $job );
		} finally {
			gend_society_migration_unlock( $token );
		}
		if ( 'running' === $status && ! wp_next_scheduled( 'gend_society_migration_export_tick' ) ) {
			wp_schedule_single_event( time() + 5, 'gend_society_migration_export_tick' );
		} elseif ( in_array( $status, array( 'done', 'failed' ), true ) ) {
			wp_clear_scheduled_hook( 'gend_society_migration_export_tick' ); // nothing left to drive (the AJAX loop may have finished it)
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
		$c = (array) ( $job['content'] ?? array() );
		return array(
			'job_id'       => (string) $job['job_id'],
			'phase'        => (string) $job['phase'],
			'index_lines'  => (int) ( $c['index']['lines'] ?? 0 ),
			'index_done'   => ! empty( $c['index']['done'] ),
			'manifest'     => ! empty( $job['manifest'] ),
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
	/** The export still has work to do (the header's tick loop runs). */
	function gend_society_migration_export_active(): bool {
		$job = gend_society_migration_export_job();
		return is_array( $job ) && in_array( (string) ( $job['phase'] ?? '' ), gend_society_migration_export_running_phases(), true );
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
