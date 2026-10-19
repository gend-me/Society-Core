<?php
/**
 * Phase 110-13 battery: the chunked exporter core + DB / users writers (MIG-04, part 1).
 *
 *   wp eval-file export-db.php <section>[,<section>...]        (C:/tmp/livepull/phase110/tools/110-13-run.sh)
 *
 * Environments (the runner picks the sections per bed):
 *   gs110 bed (WordPress on MySQL 8.0, prefix wp_) and the gs110lim limit beds (apache / nginx / nocron, 200k postmeta,
 *   30 s / 128M for web requests), and the 110-04 MariaDB 11 corpus install (/srv/maria-a in gs110src-cli-1: prefix k7_,
 *   utf8mb4_uca1400_ai_ci tables, an Aria table, a second install k7_blog2_ in the same database). Where the plugin is
 *   not active (the corpus install), env GS110EX_LOAD=<Society-Core tree> loads inc/migration-preflight.php and
 *   inc/migration-export.php directly.
 *
 * Sections (14): init staging budget lock keyset escape prefix collation shared-db users parts cron-only ajax-only wipe
 *   + helpers: export (run a full DB+users export with cron ticks, keep it), keep-info (print STAGING/PART lines for the
 *   runner's import check), canary-clean, reset (wipe everything the battery made).
 *
 * Output: PASS:/FAIL:/INFO: lines; last line "ALL PASS (<n>)" or "FAILURES: <n>". Everything a section creates is
 * removed by the section (rows, tables, jobs, staging) unless the section name says it keeps it (export).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs110ex'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
	'q'       => array(),
);
function gs110ex_pass( $id, $t = '' ) {
	$GLOBALS['gs110ex']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110ex_fail( $id, $t = '' ) {
	$GLOBALS['gs110ex']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110ex_info( $t ) {
	echo 'INFO: ' . $t . "\n";
}
function gs110ex_assert( $c, $id, $t = '', $d = '' ) {
	$c ? gs110ex_pass( $id, $t ) : gs110ex_fail( $id, $t . ( '' !== $d ? ' -- ' . $d : '' ) );
	return (bool) $c;
}
function gs110ex_on_cleanup( callable $fn ) {
	$GLOBALS['gs110ex']['cleanup'][] = $fn;
}
function gs110ex_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs110ex']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs110ex_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs110ex']['cleanup'] = array();
}

// Load the modules directly where the plugin is not active (the MariaDB corpus install).
if ( ! function_exists( 'gend_society_migration_export_tick' ) && getenv( 'GS110EX_LOAD' ) ) {
	$gs110ex_tree = rtrim( (string) getenv( 'GS110EX_LOAD' ), '/' );
	foreach ( array( 'inc/migration-preflight.php', 'inc/migration-export.php' ) as $gs110ex_f ) {
		if ( is_readable( $gs110ex_tree . '/' . $gs110ex_f ) ) {
			require_once $gs110ex_tree . '/' . $gs110ex_f;
		}
	}
	gs110ex_info( 'modules loaded from ' . $gs110ex_tree . ' (plugin not active on this install)' );
}

const GS110EX_JOB = '0e110e13-aaaa-4bbb-8ccc-dddddddddddd';

function gs110ex_ready( $sec ) {
	$ok = function_exists( 'gend_society_migration_export_tick' ) && function_exists( 'gend_society_migration_export_init' );
	gs110ex_assert( $ok, $sec . '-module', 'exporter module loaded (gend_society_migration_export_init / _tick)' );
	return $ok;
}

/** Start a fresh job the way 110-12 delivers it (option + gend_society_migration_job_received). */
function gs110ex_start( $keep = false ) {
	gs110ex_reset();
	add_option(
		'gend_society_migration_job',
		array(
			'job_id'        => GS110EX_JOB,
			'export_secret' => str_repeat( 'e', 64 ),
			'received_at'   => time(),
		),
		'',
		false
	);
	do_action( 'gend_society_migration_job_received', get_option( 'gend_society_migration_job' ) );
	if ( ! $keep ) {
		gs110ex_on_cleanup( 'gs110ex_reset' );
	}
	return gend_society_migration_export_job();
}

/** Wipe every job / staging / lock the battery made. */
function gs110ex_reset() {
	$job = get_option( 'gend_society_migration_job', null );
	if ( is_array( $job ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $job );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}

/** Run cron-driver ticks until the DB + users phases are done (max $n ticks). */
function gs110ex_run( $n = 400, $driver = 'cron' ) {
	$r = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$r = gend_society_migration_export_tick( $driver );
		if ( in_array( $r['status'], array( 'done', 'failed', 'none', 'idle' ), true ) ) {
			break;
		}
	}
	return $r;
}

/** Concatenated raw SQL of the parts of $kind (multi-member gzip read through zlib). */
function gs110ex_sql( array $job, $kind ) {
	$out = '';
	foreach ( (array) $job['parts'][ $kind ] as $p ) {
		$gz = gzopen( $job['staging'] . '/' . $p['file'], 'rb' );
		while ( $gz && ! gzeof( $gz ) ) {
			$out .= gzread( $gz, 1048576 );
		}
		if ( $gz ) {
			gzclose( $gz );
		}
	}
	return $out;
}

/** INSERT row count per destination table in $sql. */
function gs110ex_rows( $sql ) {
	$n = array();
	foreach ( explode( ";\n", $sql ) as $st ) {
		if ( preg_match( '/^INSERT INTO `([^`]+)`/', ltrim( $st ), $m ) ) {
			$n[ $m[1] ] = ( $n[ $m[1] ] ?? 0 ) + 1 + substr_count( $st, "),\n(" );
		}
	}
	return $n;
}

/** This install's tables by an independent query: exact prefix, other installs (options + posts pair) left out. */
function gs110ex_oracle_tables() {
	global $wpdb;
	$p     = $wpdb->base_prefix;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME", $wpdb->esc_like( $p ) . '%' ) ); // phpcs:ignore
	$other = array();
	foreach ( $names as $t ) {
		if ( preg_match( '/^' . preg_quote( $p, '/' ) . '(.+_)options$/', $t, $m ) && in_array( $p . $m[1] . 'posts', $names, true ) ) {
			$other[] = $p . $m[1];
		}
	}
	return array_values(
		array_filter(
			$names,
			function ( $t ) use ( $other ) {
				foreach ( $other as $o ) {
					if ( 0 === strpos( $t, $o ) ) {
						return false;
					}
				}
				return true;
			}
		)
	);
}

function gs110ex_count( $table ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore
}

/** Record every SELECT for the duration of the section. */
function gs110ex_qlog() {
	$GLOBALS['gs110ex']['q'] = array();
	$f                       = function ( $q ) {
		if ( 0 === stripos( ltrim( $q ), 'SELECT' ) ) {
			$GLOBALS['gs110ex']['q'][] = $q;
		}
		return $q;
	};
	add_filter( 'query', $f );
	gs110ex_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'query', $f );
		}
	);
}

/** A temporary fixture table set, dropped on cleanup. */
function gs110ex_temp_tables( array $sqls, array $drop ) {
	global $wpdb;
	foreach ( $sqls as $s ) {
		$wpdb->query( $s ); // phpcs:ignore
	}
	gs110ex_on_cleanup(
		function () use ( $drop ) {
			global $wpdb;
			foreach ( $drop as $t ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" ); // phpcs:ignore
			}
		}
	);
}

/** Seed $n extra postmeta rows (meta_key gs110ex_seed), removed on cleanup. */
function gs110ex_seed_postmeta( $n ) {
	global $wpdb;
	$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'gs110ex_seed'" ); // phpcs:ignore
	if ( $have < $n ) {
		$need = $n - $have;
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < {$need}) SELECT 1, 'gs110ex_seed', REPEAT(CONCAT('row ', i, ' '), 20) FROM s" ); // phpcs:ignore
	}
	gs110ex_on_cleanup(
		function () {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'gs110ex_seed'" ); // phpcs:ignore
		}
	);
}

// ---------------------------------------------------------------------------------------------------------------
$S = array();

$S['init'] = function () {
	if ( ! gs110ex_ready( 'init' ) ) {
		return;
	}
	global $wpdb;
	$job = gs110ex_start();
	gs110ex_assert( is_array( $job ) && 'db' === ( $job['phase'] ?? '' ), 'init-phase', 'gend_society_migration_job_received initialises the job (phase db)', wp_json_encode( array_slice( (array) $job, 0, 4 ) ) );
	gs110ex_assert( GS110EX_JOB === ( $job['job_id'] ?? '' ) && str_repeat( 'e', 64 ) === ( $job['export_secret'] ?? '' ), 'init-keeps-job', 'the hub job id and secret are kept' );
	$names = array_column( (array) ( $job['tables'] ?? array() ), 'name' );
	sort( $names );
	$want = gs110ex_oracle_tables();
	gs110ex_assert( $names === $want, 'init-tables', 'tables = this install\'s exact-prefix tables (' . count( $want ) . ')', wp_json_encode( array_values( array_diff( $want, $names ) ) ) . ' extra ' . wp_json_encode( array_values( array_diff( $names, $want ) ) ) );
	$kinds = array();
	foreach ( (array) $job['tables'] as $t ) {
		$kinds[ $t['name'] ] = $t['kind'];
	}
	gs110ex_assert( 'users' === ( $kinds[ $wpdb->base_prefix . 'users' ] ?? '' ) && 'users' === ( $kinds[ $wpdb->base_prefix . 'usermeta' ] ?? '' ), 'init-users-kind', 'users + usermeta go to the users parts' );
	gs110ex_assert( array( 'meta_id' ) === ( $job['tables'][ array_search( $wpdb->postmeta, $names, true ) ]['pk'] ?? null ), 'init-pk', 'postmeta keyed on meta_id' );
	$al = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'gend_society_migration_job' ) ); // phpcs:ignore
	gs110ex_assert( in_array( $al, array( 'no', 'off' ), true ), 'init-autoload', 'the job option is not autoloaded', $al );
	gs110ex_assert( (bool) wp_next_scheduled( 'gend_society_migration_export_tick' ), 'init-cron', 'the first WP-Cron tick is queued' );
};

$S['staging'] = function () {
	if ( ! gs110ex_ready( 'staging' ) ) {
		return;
	}
	$web = wp_normalize_path( untrailingslashit( ABSPATH ) );
	$d1  = gend_society_migration_staging_dir();
	gs110ex_on_cleanup(
		function () use ( $d1 ) {
			gend_society_migration_export_wipe( array( 'staging' => $d1 ) );
		}
	);
	gs110ex_assert( '' !== $d1 && 0 !== strpos( wp_normalize_path( $d1 ) . '/', $web . '/' ) && is_writable( $d1 ), 'staging-outside', 'a writable candidate outside the web root is used', $d1 );
	gs110ex_assert( (bool) preg_match( '/^gend-society-migration-[0-9a-f]{16}$/', basename( $d1 ) ), 'staging-name', 'random 16-hex directory name' );
	$off = function () {
		return array();
	};
	add_filter( 'gend_society_migration_staging_candidates', $off );
	$d2 = gend_society_migration_staging_dir();
	remove_filter( 'gend_society_migration_staging_candidates', $off );
	gs110ex_assert( 0 === strpos( wp_normalize_path( $d2 ), wp_normalize_path( WP_CONTENT_DIR ) . '/gend-society-migration-' ), 'staging-wpcontent', 'no candidate -> wp-content/gend-society-migration-<16 hex>/', $d2 );
	$ht = (string) @file_get_contents( $d2 . '/.htaccess' ); // phpcs:ignore
	gs110ex_assert( false !== strpos( $ht, 'Require all denied' ) && false !== strpos( $ht, 'Deny from all' ), 'staging-htaccess', '.htaccess denies (both Apache syntaxes)' );
	gs110ex_assert( "<?php\nexit;\n" === (string) @file_get_contents( $d2 . '/index.php' ) && false !== strpos( (string) @file_get_contents( $d2 . '/web.config' ), '<deny users="*"' ), 'staging-index-webconfig', 'index.php exits, web.config denies' ); // phpcs:ignore
	gs110ex_assert( '0750' === substr( sprintf( '%o', fileperms( $d2 ) ), -4 ), 'staging-mode', 'directory mode 0750', substr( sprintf( '%o', fileperms( $d2 ) ), -4 ) );
	// Canary for the runner's anonymous HTTP check (kept until section canary-clean).
	file_put_contents( $d2 . '/canary.txt', 'gs110ex canary' ); // phpcs:ignore
	update_option( 'gs110ex_canary', $d2, false );
	echo 'CANARY ' . substr( wp_normalize_path( $d2 ), strlen( wp_normalize_path( untrailingslashit( ABSPATH ) ) ) ) . "/canary.txt\n";
};

$S['canary-clean'] = function () {
	$d = (string) get_option( 'gs110ex_canary', '' );
	if ( $d && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( array( 'staging' => $d ) );
	}
	delete_option( 'gs110ex_canary' );
	gs110ex_assert( '' === $d || ! is_dir( $d ), 'canary-clean', 'canary staging removed' );
};

$S['budget'] = function () {
	if ( ! gs110ex_ready( 'budget' ) ) {
		return;
	}
	// In CLI the shared-host limits are set for this process (web requests on the limit beds get them from php.ini).
	@ini_set( 'max_execution_time', '30' ); // phpcs:ignore
	@ini_set( 'memory_limit', '128M' ); // phpcs:ignore
	gs110ex_seed_postmeta( 120000 );
	$job   = gs110ex_start();
	$ticks = 0;
	$worst = 0.0;
	$peak  = 0;
	for ( $i = 0; $i < 200; $i++ ) {
		$r     = gend_society_migration_export_tick( 'cron' );
		$worst = max( $worst, (float) $r['elapsed'] );
		$peak  = max( $peak, (int) $r['peak'] );
		++$ticks;
		if ( 'running' !== $r['status'] ) {
			break;
		}
	}
	gs110ex_info( "budget: {$ticks} ticks, worst " . $worst . ' s, peak ' . round( $peak / 1048576, 1 ) . ' MB, final ' . wp_json_encode( $r ) );
	gs110ex_assert( 'done' === $r['status'], 'budget-done', 'the export finishes (DB + users)' );
	gs110ex_assert( $worst < 22.0, 'budget-time', 'every tick returns in < 22 s (max_execution_time 30)', (string) $worst );
	gs110ex_assert( $peak < 77 * 1048576, 'budget-memory', 'peak < 77 MB (60 % of 128M)', (string) round( $peak / 1048576, 1 ) );
	// A short budget proves the tick stops on time with work left.
	$short = function () {
		return 1.0;
	};
	add_filter( 'gend_society_migration_tick_budget', $short );
	gs110ex_start();
	$t0 = microtime( true );
	$r  = gend_society_migration_export_tick( 'cron' );
	remove_filter( 'gend_society_migration_tick_budget', $short );
	gs110ex_assert( 'running' === $r['status'] && ( microtime( true ) - $t0 ) < 3.0, 'budget-stops', 'a 1 s budget stops the tick with work left (running) within 3 s', wp_json_encode( $r ) );
};

$S['lock'] = function () {
	if ( ! gs110ex_ready( 'lock' ) ) {
		return;
	}
	global $wpdb;
	gs110ex_start();
	$tok = gend_society_migration_lock();
	gs110ex_assert( '' !== $tok, 'lock-take', 'the lock is taken' );
	$r = gend_society_migration_export_tick( 'ajax' );
	gs110ex_assert( 'locked' === $r['status'], 'lock-second', 'a second tick while the first holds the lock returns locked at once', wp_json_encode( $r ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 1 ) . ':stale' ), array( 'option_name' => 'gend_society_migration_lock' ) ); // phpcs:ignore
	$r = gend_society_migration_export_tick( 'ajax' );
	gs110ex_assert( in_array( $r['status'], array( 'running', 'done' ), true ), 'lock-takeover', 'an expired lock (> 90 s) is taken over', wp_json_encode( $r ) );
	gs110ex_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'gend_society_migration_lock' ) ), 'lock-released', 'released after the tick' ); // phpcs:ignore
};

$S['keyset'] = function () {
	if ( ! gs110ex_ready( 'keyset' ) ) {
		return;
	}
	global $wpdb;
	gs110ex_seed_postmeta( 5000 );
	$job = gs110ex_start();
	gs110ex_qlog();
	$r   = gs110ex_run();
	$job = gend_society_migration_export_job();
	$pk_tables = array();
	foreach ( (array) $job['tables'] as $t ) {
		if ( $t['pk'] ) {
			$pk_tables[] = $t['name'];
		}
	}
	$bad = array();
	foreach ( $GLOBALS['gs110ex']['q'] as $q ) {
		if ( preg_match( '/^\s*SELECT \* FROM `([^`]+)`/', $q, $m ) && in_array( $m[1], $pk_tables, true ) && preg_match( '/\bOFFSET\b/i', $q ) ) {
			$bad[] = $q;
		}
	}
	gs110ex_assert( 'done' === $r['status'], 'keyset-done', 'export finished', wp_json_encode( $r ) );
	gs110ex_assert( ! $bad, 'keyset-no-offset', 'no OFFSET in the row SELECTs of keyed tables', substr( (string) ( $bad[0] ?? '' ), 0, 200 ) );
	$rows = gs110ex_rows( gs110ex_sql( $job, 'db' ) );
	$src  = gs110ex_count( $wpdb->postmeta );
	gs110ex_assert( $src === ( $rows['wp_postmeta'] ?? 0 ), 'keyset-rows', "postmeta exported as exactly {$src} INSERT rows", (string) ( $rows['wp_postmeta'] ?? 0 ) );
	$ks = 0;
	foreach ( $GLOBALS['gs110ex']['q'] as $q ) {
		$ks += preg_match( '/FROM `' . preg_quote( $wpdb->postmeta, '/' ) . '` WHERE \(`meta_id`\) > /', $q ) ? 1 : 0;
	}
	gs110ex_assert( $ks > 0, 'keyset-where', 'postmeta pages by WHERE (meta_id) > last', (string) $ks );
};

$S['escape'] = function () {
	if ( ! gs110ex_ready( 'escape' ) ) {
		return;
	}
	global $wpdb;
	$val = "Save 50% today: %s %d %1\$s 100%% {curly} back\\slash 'single' \"double\" line\nbreak\ttab \x00nul end";
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 1, 'meta_key' => 'gs110ex_escape', 'meta_value' => $val ) ); // phpcs:ignore
	$mid = (int) $wpdb->insert_id;
	gs110ex_on_cleanup(
		function () use ( $mid ) {
			global $wpdb;
			$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $mid ) ); // phpcs:ignore
		}
	);
	gs110ex_start();
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$sql = gs110ex_sql( $job, 'db' );
	$ph  = $wpdb->placeholder_escape();
	gs110ex_assert( false === strpos( $sql, $ph ), 'escape-no-placeholder', 'no wpdb placeholder escape string in the parts' );
	gs110ex_assert( ! preg_match( '/\{[0-9a-f]{64}\}/', $sql ), 'escape-no-hash', 'no {64-hex} placeholder hash in any part' );
	// Round trip: run the exported INSERT of that row into a scratch copy of postmeta.
	$scratch = $wpdb->base_prefix . 'gs110ex_rt';
	gs110ex_temp_tables( array( "CREATE TABLE `{$scratch}` LIKE {$wpdb->postmeta}" ), array( $scratch ) );
	$stmt = '';
	foreach ( explode( ";\n", $sql ) as $st ) {
		if ( false !== strpos( $st, "'gs110ex_escape'" ) ) {
			$stmt = $st;
		}
	}
	$line = '';
	foreach ( explode( "\n", $stmt ) as $l ) {
		if ( false !== strpos( $l, "'gs110ex_escape'" ) ) {
			$line = $l;
		}
	}
	$tuple = preg_replace( '/^.*VALUES /', '', rtrim( $line, ',' ) );
	$tuple = rtrim( $tuple, ',' );
	$wpdb->query( "INSERT INTO `{$scratch}` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES {$tuple}" ); // phpcs:ignore
	$back = $wpdb->get_var( "SELECT meta_value FROM `{$scratch}` WHERE meta_key = 'gs110ex_escape'" ); // phpcs:ignore
	gs110ex_assert( $back === $val, 'escape-roundtrip', 'the value round-trips byte-identical ( % %s %1$s, quotes, backslash, newline, NUL )', wp_json_encode( array( 'got' => $back, 'tuple' => substr( $tuple, 0, 160 ) ) ) );
};

$S['prefix'] = function () {
	if ( ! gs110ex_ready( 'prefix' ) ) {
		return;
	}
	global $wpdb;
	$p = (string) $wpdb->base_prefix;
	// The rewrite itself (any prefix).
	gs110ex_assert( 'wp_user_roles' === gend_society_migration_rename_key( 'k7_user_roles', 'k7_' ) && 'wp_capabilities' === gend_society_migration_rename_key( 'k7_capabilities', 'k7_' ) && 'other_key' === gend_society_migration_rename_key( 'other_key', 'k7_' ), 'prefix-rename-fn', 'k7_user_roles / k7_capabilities -> wp_, other keys kept' );
	gs110ex_assert( false !== strpos( gend_society_migration_sql_create( 'CREATE TABLE `k7_posts` (KEY `x` (`a`), CONSTRAINT `f` FOREIGN KEY (`a`) REFERENCES `k7_users` (`ID`))', 'k7_' ), 'CREATE TABLE `wp_posts`' ), 'prefix-create-fn', 'CREATE TABLE k7_posts -> wp_posts (and REFERENCES)' );
	gs110ex_start();
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$all = gs110ex_sql( $job, 'db' ) . gs110ex_sql( $job, 'users' );
	if ( 'wp_' === $p ) {
		gs110ex_info( 'prefix: this install already uses wp_ (the k7_ case runs on the MariaDB corpus install)' );
		gs110ex_assert( false !== strpos( $all, 'INSERT INTO `wp_options`' ), 'prefix-wp', 'wp_ install: tables keep wp_' );
		return;
	}
	gs110ex_assert( ! preg_match( '/(CREATE TABLE|INSERT INTO|DROP TABLE IF EXISTS) `' . preg_quote( $p, '/' ) . '/', $all ), 'prefix-none-left', "no CREATE / INSERT / DROP on a {$p} table" );
	gs110ex_assert( false !== strpos( $all, 'CREATE TABLE `wp_posts`' ) && false !== strpos( $all, 'INSERT INTO `wp_options`' ), 'prefix-wp', "{$p} tables exported as wp_" );
	gs110ex_assert( false !== strpos( $all, "'wp_user_roles'" ) && false === strpos( $all, "'{$p}user_roles'" ), 'prefix-user-roles', "{$p}user_roles option -> wp_user_roles" );
	gs110ex_assert( false !== strpos( $all, "'wp_capabilities'" ) && false === strpos( $all, "'{$p}capabilities'" ), 'prefix-capabilities', "{$p}capabilities usermeta -> wp_capabilities" );
};

$S['collation'] = function () {
	if ( ! gs110ex_ready( 'collation' ) ) {
		return;
	}
	global $wpdb;
	$maria = "CREATE TABLE `k7_gs110_plugin_log` (\n  `id` bigint(20) NOT NULL,\n  `msg` text COLLATE utf8mb4_uca1400_ai_ci DEFAULT NULL,\n  `m3` varchar(9) CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_as_cs\n) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci PAGE_CHECKSUM=1 TRANSACTIONAL=1";
	$out   = gend_society_migration_sql_create( $maria, 'k7_' );
	gs110ex_assert( false === stripos( $out, 'uca1400' ) && false === stripos( $out, 'Aria' ) && false === stripos( $out, 'PAGE_CHECKSUM' ) && false === stripos( $out, 'TRANSACTIONAL' ), 'collation-map-fn', 'uca1400 -> unicode_520_ci, Aria -> InnoDB, PAGE_CHECKSUM / TRANSACTIONAL stripped', $out );
	gs110ex_assert( false !== strpos( $out, 'utf8mb4_unicode_520_ci' ) && false !== strpos( $out, 'utf8mb3_unicode_520_ci' ) && false !== strpos( $out, 'ENGINE=InnoDB' ), 'collation-map-targets', 'MySQL 8.0 collations / engine' );
	gs110ex_assert( false !== strpos( gend_society_migration_sql_create( 'x COLLATE utf8mb4_0900_ai_ci', 'wp_' ), 'utf8mb4_0900_ai_ci' ), 'collation-0900-kept', 'utf8mb4_0900_* kept' );
	$server = (string) $wpdb->get_var( 'SELECT VERSION()' );
	gs110ex_start();
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$all = gs110ex_sql( $job, 'db' ) . gs110ex_sql( $job, 'users' );
	gs110ex_info( 'collation: source server ' . $server );
	gs110ex_assert( false === stripos( $all, 'uca1400' ) && ! preg_match( '/ENGINE=Aria/i', $all ) && false === stripos( $all, 'PAGE_CHECKSUM' ), 'collation-parts', 'the parts carry no uca1400, no Aria, no PAGE_CHECKSUM (' . $server . ')' );
};

$S['shared-db'] = function () {
	if ( ! gs110ex_ready( 'shared-db' ) ) {
		return;
	}
	global $wpdb;
	$p     = (string) $wpdb->base_prefix;
	$other = $p . 'blog2_';
	$made  = false;
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $other . 'options' ) ) ) {
		gs110ex_temp_tables(
			array( "CREATE TABLE `{$other}options` LIKE {$wpdb->options}", "CREATE TABLE `{$other}posts` LIKE {$wpdb->posts}", "CREATE TABLE `{$other}postmeta` LIKE {$wpdb->postmeta}" ),
			array( $other . 'options', $other . 'posts', $other . 'postmeta' )
		);
		$made = true;
	}
	gs110ex_info( 'shared-db: second install ' . $other . ( $made ? ' (fixture tables)' : ' (real, corpus)' ) );
	$job   = gs110ex_start();
	$names = array_column( (array) $job['tables'], 'name' );
	$hit   = array_values(
		array_filter(
			$names,
			function ( $t ) use ( $other ) {
				return 0 === strpos( $t, $other );
			}
		)
	);
	gs110ex_assert( ! $hit, 'shared-db-list', "no {$other} table in the export", wp_json_encode( $hit ) );
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$all = gs110ex_sql( $job, 'db' ) . gs110ex_sql( $job, 'users' );
	gs110ex_assert( false === strpos( $all, 'wp_blog2_' ) && false === strpos( $all, '`' . $other ), 'shared-db-parts', 'and none in the parts' );
};

$S['users'] = function () {
	if ( ! gs110ex_ready( 'users' ) ) {
		return;
	}
	global $wpdb;
	gs110ex_start();
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$u   = gs110ex_rows( gs110ex_sql( $job, 'users' ) );
	$d   = gs110ex_rows( gs110ex_sql( $job, 'db' ) );
	gs110ex_assert( gs110ex_count( $wpdb->users ) === ( $u['wp_users'] ?? 0 ) && gs110ex_count( $wpdb->usermeta ) === ( $u['wp_usermeta'] ?? 0 ), 'users-rows', 'users parts: users + usermeta rows equal the source', wp_json_encode( $u ) );
	gs110ex_assert( ! isset( $d['wp_users'] ) && ! isset( $d['wp_usermeta'] ), 'users-not-in-db', 'the db parts carry no users / usermeta' );
	gs110ex_assert( count( $job['parts']['users'] ) >= 1 && 'content' === $job['phase'], 'users-phase', 'users parts written, then phase content (110-14 continues)', $job['phase'] );
};

$S['parts'] = function () {
	if ( ! gs110ex_ready( 'parts' ) ) {
		return;
	}
	global $wpdb;
	if ( gs110ex_count( $wpdb->postmeta ) < 100000 ) {
		gs110ex_seed_postmeta( 120000 );
	}
	gs110ex_start();
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$db  = (array) $job['parts']['db'];
	gs110ex_info( 'parts: db ' . count( $db ) . ' (' . implode( ', ', array_map( function ( $p ) { return round( $p['raw'] / 1048576, 1 ) . ' MB raw / ' . $p['members'] . ' members'; }, $db ) ) . '), users ' . count( $job['parts']['users'] ) );
	gs110ex_assert( count( $db ) >= 2, 'parts-several', 'more than one db part (DB > 8 MB)', (string) count( $db ) );
	$sizes = true;
	foreach ( $db as $i => $p ) {
		$last = ( count( $db ) - 1 === $i );
		if ( ! $last && ( $p['raw'] < 8 * 1048576 || $p['raw'] > 16 * 1048576 ) ) {
			$sizes = false;
		}
		if ( $last && $p['raw'] > 16 * 1048576 ) {
			$sizes = false;
		}
	}
	gs110ex_assert( $sizes, 'parts-size', 'each db part 8-16 MB uncompressed except the last' );
	$ok_sha = true;
	$ok_gz  = true;
	foreach ( array_merge( $db, (array) $job['parts']['users'] ) as $p ) {
		$f = $job['staging'] . '/' . $p['file'];
		if ( hash_file( 'sha256', $f ) !== $p['sha256'] || filesize( $f ) !== $p['size'] ) {
			$ok_sha = false;
		}
		$gz  = gzopen( $f, 'rb' );
		$len = 0;
		while ( $gz && ! gzeof( $gz ) ) {
			$c = gzread( $gz, 1048576 );
			if ( false === $c ) {
				$ok_gz = false;
				break;
			}
			$len += strlen( $c );
		}
		if ( $gz ) {
			gzclose( $gz );
		}
		if ( $len !== (int) $p['raw'] ) {
			$ok_gz = false;
		}
	}
	gs110ex_assert( $ok_sha, 'parts-sha256', 'recorded sha256 + size == computed' );
	gs110ex_assert( $ok_gz, 'parts-gzip', 'every part reads to its end as gzip (all members) with the recorded raw length' );
	gs110ex_assert( 0 === strpos( gs110ex_sql( array( 'staging' => $job['staging'], 'parts' => array( 'x' => array( $db[1] ?? $db[0] ) ) ), 'x' ), "SET NAMES utf8mb4;\n" ), 'parts-standalone', 'every part starts with SET NAMES (importable alone)' );
};

$S['cron-only'] = function () {
	if ( ! gs110ex_ready( 'cron-only' ) ) {
		return;
	}
	gs110ex_start();
	$n = 0;
	for ( $i = 0; $i < 400; $i++ ) {
		$ts = wp_next_scheduled( 'gend_society_migration_export_tick' );
		if ( ! $ts ) {
			break;
		}
		wp_unschedule_event( $ts, 'gend_society_migration_export_tick' );
		do_action( 'gend_society_migration_export_tick' );
		++$n;
	}
	$job = gend_society_migration_export_job();
	gs110ex_assert( 'content' === ( $job['phase'] ?? '' ), 'cron-only-phase', "only the WP-Cron events ({$n}) drove the export to phase content (each unfinished tick queued the next)", (string) ( $job['phase'] ?? '' ) );
};

$S['ajax-only'] = function () {
	if ( ! gs110ex_ready( 'ajax-only' ) ) {
		return;
	}
	// Real admin-ajax requests through this site's web server, as an administrator (session cookies + nonce).
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$uid    = $admins ? (int) $admins[0]->ID : 0;
	if ( ! gs110ex_assert( $uid > 0, 'ajax-admin', 'an administrator' ) ) {
		return;
	}
	gs110ex_start();
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
	$exp   = time() + 1800;
	$tok   = WP_Session_Tokens::get_instance( $uid )->create( $exp );
	gs110ex_on_cleanup(
		function () use ( $uid, $tok ) {
			WP_Session_Tokens::get_instance( $uid )->destroy( $tok );
		}
	);
	$nonce = substr( wp_hash( wp_nonce_tick( 'gs_membership_action' ) . '|gs_membership_action|' . $uid . '|' . $tok, 'nonce' ), -12, 10 );
	$jar   = array(
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'logged_in', $tok ) ) ),
		new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'auth', $tok ) ) ),
	);
	$base  = (string) ( getenv( 'GS110EX_SELF' ) ? getenv( 'GS110EX_SELF' ) : admin_url( 'admin-ajax.php' ) );
	$n     = 0;
	$last  = array();
	$worst = 0.0;
	for ( $i = 0; $i < 300; $i++ ) {
		$t0 = microtime( true );
		$r  = wp_remote_post( $base, array( 'timeout' => 40, 'cookies' => $jar, 'body' => array( 'action' => 'gend_society_migration_tick', 'nonce' => $nonce ) ) );
		$worst = max( $worst, microtime( true ) - $t0 );
		$j  = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$last = is_array( $j ) ? (array) ( $j['data'] ?? array() ) : array( 'raw' => substr( is_wp_error( $r ) ? $r->get_error_message() : (string) wp_remote_retrieve_body( $r ), 0, 200 ) );
		++$n;
		wp_clear_scheduled_hook( 'gend_society_migration_export_tick' ); // the AJAX driver alone
		if ( empty( $j['success'] ) || ! in_array( $last['status'] ?? '', array( 'running', 'locked' ), true ) ) {
			break;
		}
	}
	gs110ex_info( "ajax-only: {$n} admin-ajax ticks, worst " . round( $worst, 2 ) . ' s, last ' . wp_json_encode( $last ) . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' (DISABLE_WP_CRON)' : '' ) );
	$job = gend_society_migration_export_job();
	gs110ex_assert( 'content' === ( $job['phase'] ?? '' ) && 'done' === ( $last['status'] ?? '' ), 'ajax-only-phase', 'admin-ajax ticks alone reach phase content', (string) ( $job['phase'] ?? '' ) );
};

$S['wipe'] = function () {
	if ( ! gs110ex_ready( 'wipe' ) ) {
		return;
	}
	$job = gs110ex_start( true );
	gs110ex_run();
	$job = gend_society_migration_export_job();
	$dir = (string) $job['staging'];
	gs110ex_assert( is_dir( $dir ) && count( glob( $dir . '/*.part' ) ) >= 2, 'wipe-before', 'staging holds the parts before cleanup' );
	do_action( 'gend_society_migration_cleanup', $job );
	gs110ex_assert( ! is_dir( $dir ), 'wipe-dir', 'gend_society_migration_cleanup removes the staging directory' );
	gs110ex_assert( null === gend_society_migration_export_job(), 'wipe-job', 'and the job option' );
	gs110ex_assert( ! wp_next_scheduled( 'gend_society_migration_export_tick' ), 'wipe-cron', 'and the queued tick' );
	// A foreign directory is never deleted.
	$foreign = WP_CONTENT_DIR . '/gs110ex-not-ours';
	wp_mkdir_p( $foreign );
	gend_society_migration_export_wipe( array( 'staging' => $foreign ) );
	gs110ex_assert( is_dir( $foreign ), 'wipe-foreign', 'a directory the exporter did not make is left alone' );
	@rmdir( $foreign ); // phpcs:ignore
};

// Helpers for the runner (import / kill-resume checks).
$S['export'] = function () {
	if ( ! gs110ex_ready( 'export' ) ) {
		return;
	}
	gs110ex_start( true );
	$r = gs110ex_run( 2000 );
	gs110ex_assert( 'done' === $r['status'], 'export', 'full DB + users export kept for the runner', wp_json_encode( $r ) );
	$S2 = $GLOBALS['gs110ex_sections']['keep-info'];
	$S2();
};

$S['start-keep'] = function () {
	if ( ! gs110ex_ready( 'start-keep' ) ) {
		return;
	}
	$job = gs110ex_start( true );
	gs110ex_assert( is_array( $job ) && 'db' === ( $job['phase'] ?? '' ), 'start-keep', 'a job started and kept for the runner (phase db, ' . count( (array) ( $job['tables'] ?? array() ) ) . ' tables, staging ' . ( $job['staging'] ?? '' ) . ')' );
};

$S['keep-info'] = function () {
	$job = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
	if ( ! gs110ex_assert( is_array( $job ) && ! empty( $job['staging'] ), 'keep-info', 'a kept export exists' ) ) {
		return;
	}
	echo 'STAGING ' . $job['staging'] . "\n";
	echo 'PHASE ' . $job['phase'] . "\n";
	foreach ( array( 'db', 'users' ) as $k ) {
		foreach ( (array) $job['parts'][ $k ] as $p ) {
			echo 'PART ' . $k . ' ' . $p['n'] . ' ' . $p['file'] . ' ' . $p['size'] . ' ' . $p['sha256'] . "\n";
		}
	}
	if ( is_array( $job['cur'] ) ) {
		echo 'OPEN ' . $job['cur']['kind'] . ' ' . $job['cur']['file'] . ' ' . $job['cur']['offset'] . "\n";
	}
	foreach ( (array) ( $job['repairs'] ?? array() ) as $rp ) {
		echo 'REPAIR ' . (int) $rp['at'] . ' cut ' . (int) $rp['bytes'] . ' bytes of ' . $rp['file'] . "\n";
	}
	global $wpdb;
	foreach ( (array) $job['tables'] as $t ) {
		echo 'SRC ' . 'wp_' . substr( $t['name'], strlen( $wpdb->base_prefix ) ) . ' ' . gs110ex_count( $t['name'] ) . "\n";
	}
};

$S['reset'] = function () {
	if ( function_exists( 'gend_society_migration_export_wipe' ) ) {
		gs110ex_reset();
	}
	delete_option( 'gend_society_migration_job' );
	gs110ex_assert( false === get_option( 'gend_society_migration_job', false ), 'reset', 'no job left' );
};
$GLOBALS['gs110ex_sections'] = $S;

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs110ex_want = array();
foreach ( isset( $args ) ? (array) $args : array() as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs110ex_want[] = trim( $s );
		}
	}
}
global $wpdb;
gs110ex_info( 'export-db on ' . home_url() . ' prefix=' . $wpdb->base_prefix . ' server=' . $wpdb->get_var( 'SELECT VERSION()' ) . ' php=' . PHP_VERSION . ' sections=' . implode( ',', $gs110ex_want ) );
foreach ( $gs110ex_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs110ex_fail( $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs110ex_fail( $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs110ex_cleanup();
	}
}
echo ( 0 === (int) $GLOBALS['gs110ex']['fail'] && (int) $GLOBALS['gs110ex']['pass'] > 0 ) ? 'ALL PASS (' . (int) $GLOBALS['gs110ex']['pass'] . ")\n" : 'FAILURES: ' . (int) $GLOBALS['gs110ex']['fail'] . "\n";
