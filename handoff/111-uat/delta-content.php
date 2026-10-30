<?php
/**
 * Phase 111-07 battery: the delta export carries new users, WooCommerce orders, new terms and changed uploads, and the
 * 111-04 importer accepts every statement (MIG-13, part 2).
 *
 *   wp eval-file delta-content.php <section>[,<section>...]     (C:/tmp/livepull/phase111/tools/sc-uat-111.sh bed delta-content <sections>)
 *
 * Sections: delta-users delta-orders delta-terms delta-uploads uploads-sha256 empty-delta importer-whitelist
 *
 * Each section builds its own scenario (a full export, controlled changes, a delta export from the stored base), checks
 * it and removes every row, table, file, option, term, job and staging folder it made. Last line "ALL PASS (<n>)" or
 * "FAILURES: <n>".
 *
 * The importer check runs the scanner / applier of k8s/migration-runner-image/migrate.sh (the embedded delta.php). A
 * snapshot of it is embedded below; when /tmp/gs111-importer-delta.php exists in the bed (the heredoc of the CURRENT
 * migrate.sh, docker cp-ed in) that file is used instead and compared with the snapshot (section importer-whitelist).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs111'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
);
function gs111_assert( $c, $id, $t = '', $d = '' ) {
	if ( $c ) {
		$GLOBALS['gs111']['pass']++;
		echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
	} else {
		$GLOBALS['gs111']['fail']++;
		echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . ( '' !== $d ? ' -- ' . $d : '' ) . "\n";
	}
	return (bool) $c;
}
function gs111_info( $t ) {
	echo 'INFO: ' . $t . "\n";
}
function gs111_on_cleanup( callable $fn ) {
	$GLOBALS['gs111']['cleanup'][] = $fn;
}
function gs111_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs111']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs111_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs111']['cleanup'] = array();
}

const GS111_FULL_JOB  = '0e111e07-aaaa-4bbb-8ccc-dddddddddd01';
const GS111_DELTA_JOB = '0e111e07-aaaa-4bbb-8ccc-dddddddddd02';

function gs111_reset_job() {
	$job = get_option( 'gend_society_migration_job', null );
	if ( is_array( $job ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $job );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}

function gs111_start( $id, $mode = null ) {
	gs111_reset_job();
	$rec = array(
		'job_id'        => $id,
		'export_secret' => str_repeat( 'e', 64 ),
		'received_at'   => time(),
	);
	if ( null !== $mode ) {
		$rec['mode'] = $mode;
	}
	add_option( 'gend_society_migration_job', $rec, '', false );
	do_action( 'gend_society_migration_job_received', get_option( 'gend_society_migration_job' ) );
	return gend_society_migration_export_job();
}

function gs111_run( $n = 3000 ) {
	$r = array( 'status' => 'none' );
	for ( $i = 0; $i < $n; $i++ ) {
		$r = gend_society_migration_export_tick( 'cron' );
		if ( in_array( $r['status'], array( 'done', 'failed', 'none', 'idle' ), true ) ) {
			break;
		}
	}
	return $r;
}

/** The decompressed SQL of every part of $kind of the job. */
function gs111_sql( array $job, $kind = 'db' ) {
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

function gs111_manifest( array $job ) {
	$m = json_decode( (string) file_get_contents( $job['staging'] . '/' . $job['manifest']['file'] ), true );
	return is_array( $m ) ? $m : array();
}

/** The tar entries of the content parts: name => [type, body]. */
function gs111_tar( array $job ) {
	$ents = array();
	if ( empty( $job['parts']['content'] ) ) {
		return $ents;
	}
	$tmp = $job['staging'] . '/gs111-cat.tmp.gz';
	$out = fopen( $tmp, 'wb' );
	foreach ( $job['parts']['content'] as $p ) {
		fwrite( $out, (string) file_get_contents( $job['staging'] . '/' . $p['file'] ) );
	}
	fclose( $out );
	$gz   = gzopen( $tmp, 'rb' );
	$data = '';
	while ( $gz && ! gzeof( $gz ) ) {
		$data .= gzread( $gz, 1048576 );
	}
	if ( $gz ) {
		gzclose( $gz );
	}
	unlink( $tmp );
	$pos  = 0;
	$name = '';
	while ( $pos + 512 <= strlen( $data ) ) {
		$h = substr( $data, $pos, 512 );
		if ( '' === trim( $h, "\0" ) ) {
			break;
		}
		$size = (int) octdec( trim( substr( $h, 124, 12 ), "\0 " ) );
		$type = $h[156];
		$nm   = rtrim( substr( $h, 0, 100 ), "\0" );
		$pos += 512;
		$body = substr( $data, $pos, $size );
		$pos += (int) ( ceil( $size / 512 ) * 512 );
		if ( 'L' === $type ) {
			$name = rtrim( $body, "\0" );
			continue;
		}
		$ents[ '' !== $name ? $name : $nm ] = array(
			'type' => $type,
			'body' => $body,
		);
		$name = '';
	}
	return $ents;
}

/* ---- the importer (111-04) ------------------------------------------------------------------------------- */

/** Source of the importer's delta.php: the bed file written by tools/extract-importer.sh, else the embedded snapshot. */
function gs111c_importer_src() {
	$snap = gs111c_snapshot();
	$file = '/tmp/gs111-importer-delta.php';
	if ( is_file( $file ) ) {
		return array( (string) file_get_contents( $file ), 'migrate.sh (live copy)', rtrim( (string) file_get_contents( $file ) ) === rtrim( $snap ) );
	}
	return array( $snap, 'embedded snapshot', true );
}

/** Path of a runnable copy of the importer's delta.php (written once per run). */
function gs111c_importer_path() {
	static $path = '';
	if ( '' === $path ) {
		list( $src ) = gs111c_importer_src();
		$path        = sys_get_temp_dir() . '/gs111-importer-run-' . getmypid() . '.php';
		file_put_contents( $path, $src );
		gs111_on_cleanup(
			function () use ( $path ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		);
	}
	return $path;
}

/** The importer's $PARENTS (pk, id / chk SQL expressions), evaluated from its own source. */
function gs111c_parents() {
	static $p = null;
	if ( null !== $p ) {
		return $p;
	}
	list( $src ) = gs111c_importer_src();
	if ( ! preg_match( '/(function hx\(.*?\n\}\n).*?(\$PARENTS = array\(.*?\n\);\n)/s', $src, $m ) ) {
		return $p = array();
	}
	if ( ! function_exists( 'hx' ) ) {
		eval( $m[1] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test bed: the importer's own hx() and $PARENTS.
	}
	eval( $m[2] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	return $p = $PARENTS; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}

function gs111c_importer_children() {
	list( $src ) = gs111c_importer_src();
	return preg_match( '/\$CHILDREN = array\((.*?)\n\);\n/s', $src, $m ) ? $m[1] : '';
}

/** Run the importer's delta.php. @return array{0:int,1:string} exit code and output. */
function gs111c_importer( $mode, array $tables, array $files ) {
	$env = getenv();
	if ( ! is_array( $env ) ) {
		$env = array();
	}
	$env['WORDPRESS_DB_HOST']     = DB_HOST;
	$env['WORDPRESS_DB_USER']     = DB_USER;
	$env['WORDPRESS_DB_PASSWORD'] = DB_PASSWORD;
	$env['WORDPRESS_DB_NAME']     = DB_NAME;
	$cmd                          = array_merge( array( PHP_BINARY, '-d', 'memory_limit=1G', gs111c_importer_path(), $mode, implode( ',', $tables ) ), $files );
	$pr                           = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $pr ) ) {
		return array( 99, 'proc_open failed' );
	}
	$o  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	$rc = proc_close( $pr );
	return array( $rc, $o );
}

/** Write the SQL parts of the job (db + users) as plain files the way migrate.sh hands them to delta.php. @return string[] */
function gs111c_part_files( array $job ) {
	$dir = sys_get_temp_dir() . '/gs111c-parts-' . getmypid() . '-' . wp_generate_password( 6, false );
	mkdir( $dir );
	$files = array();
	foreach ( array( 'db', 'users' ) as $k ) {
		foreach ( (array) $job['parts'][ $k ] as $p ) {
			$gz = gzopen( $job['staging'] . '/' . $p['file'], 'rb' );
			$d  = '';
			while ( $gz && ! gzeof( $gz ) ) {
				$d .= gzread( $gz, 1048576 );
			}
			if ( $gz ) {
				gzclose( $gz );
			}
			$d       = preg_replace( '/\{[a-f0-9]{64}\}/', '%', $d ); // migrate.sh's first sed expression
			$files[] = $dir . '/' . $k . '-' . $p['n'] . '.sql';
			file_put_contents( end( $files ), $d );
		}
	}
	gs111_on_cleanup(
		function () use ( $dir, $files ) {
			foreach ( $files as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	);
	return $files;
}

/**
 * Do the chk / id comments of the exported row $id of destination table $dst equal the importer's own SQL expressions
 * evaluated on the source row? @return string '' when they match, else why not.
 */
function gs111c_tags_ok( $sql, $dst, $id ) {
	global $wpdb;
	$P = gs111c_parents();
	if ( empty( $P[ $dst ] ) ) {
		return 'importer has no parent rule for ' . $dst;
	}
	$pk = $P[ $dst ]['pk'];
	if ( ! preg_match( '/^INSERT IGNORE INTO `' . preg_quote( $dst, '/' ) . '` \(`[^)]*\) VALUES \(\'' . (int) $id . '\',.*\); \/\* chk:([0-9a-f]+) \*\/ \/\* id:([0-9a-f]+) \*\/$/m', $sql, $m ) ) {
		return 'no tagged line for ' . $dst . ' ' . $id;
	}
	$src = $wpdb->prefix . substr( $dst, 3 );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$P[$dst]['id']} AS i, {$P[$dst]['chk']} AS c FROM `{$src}` WHERE `{$pk}` = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $row ) {
		return 'no source row ' . $src . ' ' . $id;
	}
	if ( strtolower( $row['c'] ) !== $m[1] ) {
		return 'chk differs: exporter ' . $m[1] . ' importer ' . $row['c'];
	}
	if ( strtolower( $row['i'] ) !== $m[2] ) {
		return 'id differs: exporter ' . $m[2] . ' importer ' . $row['i'];
	}
	return '';
}

function gs111c_has_row( $sql, $table, $id ) {
	return (bool) preg_match( '/^INSERT IGNORE INTO `' . preg_quote( $table, '/' ) . '` \(`[^)]*\) VALUES \(\'' . (int) $id . '\',/m', $sql );
}

/* ---- fixtures -------------------------------------------------------------------------------------------- */

function gs111c_user( $login, $registered ) {
	global $wpdb;
	$id = wp_insert_user(
		array(
			'user_login'      => $login,
			'user_pass'       => 'pw-' . $login . '-' . wp_generate_password( 8, false ),
			'user_email'      => $login . '@example.test',
			'user_registered' => $registered,
			'role'            => 'subscriber',
		)
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	gs111_on_cleanup(
		function () use ( $id, $wpdb ) {
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $id ) ); // phpcs:ignore WordPress.DB
			$wpdb->delete( $wpdb->users, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
			clean_user_cache( $id );
		}
	);
	return (int) $id;
}

function gs111c_post( $args, $modified_gmt ) {
	global $wpdb;
	$id = wp_insert_post( array_merge( array( 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'gs111c fixture', 'post_content' => 'body' ), $args ), true );
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $modified_gmt, 'post_modified' => $modified_gmt ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
	gs111_on_cleanup(
		function () use ( $id ) {
			wp_delete_post( $id, true );
		}
	);
	return (int) $id;
}

/** Make sure the HPOS tables exist (created by the battery when the bed has no WooCommerce) with a few real columns. */
function gs111c_orders_tables() {
	global $wpdb;
	$P       = $wpdb->prefix;
	$existed = array();
	$ddl     = array(
		'wc_orders'                   => "CREATE TABLE `{$P}wc_orders` (id bigint(20) unsigned NOT NULL, status varchar(20) DEFAULT NULL, currency varchar(10) DEFAULT NULL, type varchar(20) DEFAULT NULL, tax_amount decimal(26,8) DEFAULT NULL, total_amount decimal(26,8) DEFAULT NULL, customer_id bigint(20) unsigned DEFAULT NULL, billing_email varchar(320) DEFAULT NULL, date_created_gmt datetime DEFAULT NULL, date_updated_gmt datetime DEFAULT NULL, parent_order_id bigint(20) unsigned DEFAULT NULL, payment_method varchar(100) DEFAULT NULL, transaction_id varchar(100) DEFAULT NULL, customer_note text, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4",
		'wc_orders_meta'              => "CREATE TABLE `{$P}wc_orders_meta` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned DEFAULT NULL, meta_key varchar(255) DEFAULT NULL, meta_value text, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4",
		'wc_order_addresses'          => "CREATE TABLE `{$P}wc_order_addresses` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned NOT NULL, address_type varchar(20) DEFAULT NULL, first_name text, last_name text, email varchar(320) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4",
		'wc_order_operational_data'   => "CREATE TABLE `{$P}wc_order_operational_data` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned DEFAULT NULL, created_via varchar(100) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4",
	);
	// The importer's tables are wp_wc_order_items / wp_wc_order_itemmeta (the exporter reads <prefix>wc_order_items).
	$ddl['wc_order_items']    = "CREATE TABLE `{$P}wc_order_items` (order_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_item_name text NOT NULL, order_item_type varchar(200) NOT NULL DEFAULT '', order_id bigint(20) unsigned NOT NULL, PRIMARY KEY (order_item_id)) DEFAULT CHARSET=utf8mb4";
	$ddl['wc_order_itemmeta'] = "CREATE TABLE `{$P}wc_order_itemmeta` (meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_item_id bigint(20) unsigned NOT NULL, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id)) DEFAULT CHARSET=utf8mb4";
	$created = array();
	foreach ( $ddl as $t => $sql ) {
		if ( $t === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $P . $t ) ) ) ) { // phpcs:ignore WordPress.DB
			$existed[ $t ] = true;
			continue;
		}
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB
		$created[] = $t;
	}
	gs111_on_cleanup(
		function () use ( $created, $P, $wpdb ) {
			foreach ( $created as $t ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$P}{$t}`" ); // phpcs:ignore WordPress.DB
			}
		}
	);
	return $created;
}

function gs111c_order( $id, $updated, $email, $with_items ) {
	global $wpdb;
	$P = $wpdb->prefix;
	$wpdb->insert( $P . 'wc_orders', array( 'id' => $id, 'status' => 'wc-completed', 'currency' => 'CAD', 'type' => 'shop_order', 'total_amount' => '12.50000000', 'billing_email' => $email, 'date_created_gmt' => '2001-01-01 00:00:00', 'date_updated_gmt' => $updated, 'transaction_id' => 'tx-' . $id ) ); // phpcs:ignore WordPress.DB
	$wpdb->insert( $P . 'wc_orders_meta', array( 'order_id' => $id, 'meta_key' => 'gs111_ometa', 'meta_value' => "o\nmeta" ) ); // phpcs:ignore WordPress.DB
	$wpdb->insert( $P . 'wc_order_addresses', array( 'order_id' => $id, 'address_type' => 'billing', 'first_name' => 'Gs', 'last_name' => 'Eleven', 'email' => $email ) ); // phpcs:ignore WordPress.DB
	$wpdb->insert( $P . 'wc_order_operational_data', array( 'order_id' => $id, 'created_via' => 'gs111' ) ); // phpcs:ignore WordPress.DB
	$item = 0;
	if ( $with_items ) {
		$wpdb->insert( $P . 'wc_order_items', array( 'order_id' => $id, 'order_item_name' => 'gs111 item', 'order_item_type' => 'line_item' ) ); // phpcs:ignore WordPress.DB
		$item = (int) $wpdb->insert_id;
		$wpdb->insert( $P . 'wc_order_itemmeta', array( 'order_item_id' => $item, 'meta_key' => '_gs111_qty', 'meta_value' => '3' ) ); // phpcs:ignore WordPress.DB
	}
	gs111_on_cleanup(
		function () use ( $id, $item, $P, $wpdb ) {
			if ( $item ) {
				$wpdb->delete( $P . 'wc_order_itemmeta', array( 'order_item_id' => $item ) ); // phpcs:ignore WordPress.DB
			}
			foreach ( array( 'wc_order_items', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ) as $t ) {
				$wpdb->delete( $P . $t, array( 'order_id' => $id ) ); // phpcs:ignore WordPress.DB
			}
			$wpdb->delete( $P . 'wc_orders', array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		}
	);
	return $item;
}

/**
 * The scenario every section shares. A full export runs (job start T -> base option) over fixtures that exist BEFORE it;
 * changes are placed relative to T; a delta export runs from the stored base. $o: orders (create HPOS fixtures), quiet
 * (no change after the base).
 */
function gs111c_scenario( array $o = array() ) {
	global $wpdb;
	$o   += array(
		'orders' => false,
		'quiet'  => false,
	);
	$P    = $wpdb->prefix;
	$S    = array( 'orders' => $o['orders'] );
	gs111_on_cleanup( 'gs111_reset_job' );
	gs111_on_cleanup(
		function () {
			delete_option( 'gend_society_migration_base' );
		}
	);
	delete_option( 'gend_society_migration_base' );

	// Before the base export.
	$S['A']      = gs111c_user( 'gs111-existing', '2001-01-01 00:00:00' );
	$S['A_pass'] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $S['A'] ) ); // phpcs:ignore WordPress.DB
	$S['edited'] = gs111c_post( array( 'post_title' => 'gs111c edited' ), '2001-01-01 00:00:00' );
	$old         = wp_insert_term( 'gs111c-oldterm', 'category' );
	$used        = wp_insert_term( 'gs111c-usedterm', 'category' );
	foreach ( array( $old, $used ) as $t ) {
		if ( ! is_wp_error( $t ) ) {
			$tid = (int) $t['term_id'];
			gs111_on_cleanup(
				function () use ( $tid ) {
					wp_delete_term( $tid, 'category' );
				}
			);
		}
	}
	$S['old_term']  = is_wp_error( $old ) ? 0 : (int) $old['term_id'];
	$S['used_term'] = is_wp_error( $used ) ? 0 : (int) $used['term_id'];
	if ( $S['used_term'] ) {
		wp_set_object_terms( $S['edited'], array( $S['used_term'] ), 'category' );
	}
	$up   = wp_upload_dir( null, false );
	$dir  = $up['basedir'] . '/gs111-delta';
	$bg   = $up['basedir'] . '/backup-guard';
	$plug = WP_PLUGIN_DIR . '/gs111-plugin';
	wp_mkdir_p( $dir );
	wp_mkdir_p( $bg );
	wp_mkdir_p( $plug );
	$S['files'] = array(
		'untouched' => $dir . '/untouched.txt',
		'edited'    => $dir . '/edited.txt',
		'outside'   => $dir . '/outside.txt',
		'inside'    => $dir . '/inside.txt',
		'excluded'  => $bg . '/gs111-excluded.txt',
		'plugin'    => $plug . '/p.php',
	);
	foreach ( $S['files'] as $k => $f ) {
		file_put_contents( $f, 'v1 ' . $k );
	}
	if ( $o['quiet'] ) { // nothing may look changed: every fixture file is old before the base export
		foreach ( $S['files'] as $f ) {
			touch( $f, time() - 2 * 86400 );
		}
	}
	$S['link'] = '';
	if ( function_exists( 'symlink' ) && @symlink( $S['files']['untouched'], $dir . '/link.txt' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$S['link'] = 'uploads/gs111-delta/link.txt';
	}
	$rel_up = 'uploads' === substr( str_replace( wp_normalize_path( WP_CONTENT_DIR ) . '/', '', wp_normalize_path( $up['basedir'] ) ), 0, 7 ) ? str_replace( wp_normalize_path( WP_CONTENT_DIR ) . '/', '', wp_normalize_path( $up['basedir'] ) ) : 'uploads';
	$S['rel']  = $rel_up . '/gs111-delta/';
	gs111_on_cleanup(
		function () use ( $dir, $bg, $plug, $S ) {
			foreach ( $S['files'] as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $bg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $plug ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	);
	if ( $o['orders'] ) {
		$S['created_tables'] = gs111c_orders_tables();
		$S['O1']             = 900001;
		$S['O2']             = 900002;
		gs111c_order( $S['O1'], '2001-01-01 00:00:00', 'o1@example.test', false );
		gs111c_order( $S['O2'], '2001-01-01 00:00:00', 'o2@example.test', true );
	}

	$full              = gs111_start( GS111_FULL_JOB );
	$S['T']            = (string) ( $full['watermark']['taken_at_gmt'] ?? '' );
	$T                 = strtotime( $S['T'] . ' UTC' );
	$S['full_r']       = gs111_run();
	$S['base']         = get_option( 'gend_society_migration_base', array() );
	$S['max_user']     = (int) ( $S['base']['watermark']['max_user_id'] ?? 0 );
	$S['max_term']     = (int) ( $S['base']['watermark']['max_term_id'] ?? 0 );
	$S['max_order']    = (int) ( $S['base']['watermark']['max_order_id'] ?? 0 );
	$S['full_end']     = gend_society_migration_export_job();
	$S['full_manifest'] = is_array( $S['full_end'] ) && ! empty( $S['full_end']['manifest'] ) ? gs111_manifest( $S['full_end'] ) : array();
	gs111_reset_job();

	if ( ! $o['quiet'] ) {
		// After the base export.
		$S['B'] = gs111c_user( 'gs111-new', gmdate( 'Y-m-d H:i:s' ) );
		add_user_meta( $S['B'], 'session_tokens', array( 'x' => 'secret-token-gs111' ) );
		add_user_meta( $S['B'], 'gs111_um', "multi\nline 'quoted'" );
		$S['B_pass'] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $S['B'] ) ); // phpcs:ignore WordPress.DB
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $T + 10 ), 'post_modified' => gmdate( 'Y-m-d H:i:s', $T + 10 ) ), array( 'ID' => $S['edited'] ) ); // phpcs:ignore WordPress.DB
		wp_set_object_terms( $S['edited'], array( $S['used_term'], 'gs111c-newterm' ), 'category' );
		$nt = get_term_by( 'name', 'gs111c-newterm', 'category' );
		if ( $nt ) {
			$S['new_term'] = (int) $nt->term_id;
			add_term_meta( $S['new_term'], 'gs111_tm', 'term meta' );
			gs111_on_cleanup(
				function () use ( $nt ) {
					wp_delete_term( $nt->term_id, 'category' );
				}
			);
		}
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $T + 10 ), 'post_modified' => gmdate( 'Y-m-d H:i:s', $T + 10 ) ), array( 'ID' => $S['edited'] ) ); // phpcs:ignore WordPress.DB
		// Uploads.
		touch( $S['files']['untouched'], $T - 2 * 86400 );
		touch( $S['files']['outside'], $T - 400 );
		touch( $S['files']['inside'], $T - 200 );
		touch( $S['files']['edited'], $T + 10 );
		file_put_contents( $S['files']['edited'], 'v2 edited' );
		touch( $S['files']['edited'], $T + 10 );
		file_put_contents( $S['files']['excluded'], 'excluded v2' );
		touch( $S['files']['excluded'], $T + 20 );
		file_put_contents( $S['files']['plugin'], '<?php // edited after the anchor' );
		touch( $S['files']['plugin'], $T + 20 );
		$S['files']['new'] = $dir . '/new.txt';
		file_put_contents( $S['files']['new'], "new upload\n" . str_repeat( 'x', 1000 ) );
		touch( $S['files']['new'], $T + 30 );
		$S['comment'] = (int) wp_insert_comment( array( 'comment_post_ID' => $S['edited'], 'comment_content' => 'gs111c comment', 'comment_author' => 'gs111c', 'comment_author_email' => 'gs111c@example.test', 'comment_approved' => '1' ) );
		gs111_on_cleanup(
			function () use ( $S ) {
				wp_delete_comment( $S['comment'], true );
			}
		);
		if ( $o['orders'] ) {
			$wpdb->update( $P . 'wc_orders', array( 'date_updated_gmt' => gmdate( 'Y-m-d H:i:s', $T + 15 ), 'status' => 'wc-refunded' ), array( 'id' => $S['O2'] ) ); // phpcs:ignore WordPress.DB
			$S['O3'] = $S['max_order'] + 5;
			gs111c_order( $S['O3'], '2001-01-01 00:00:00', 'o3@example.test', true ); // new id, old date: the ID rule takes it
		}
	}
	$S['T_ts'] = $T;

	$delta            = gs111_start( GS111_DELTA_JOB, 'delta' );
	$S['delta_start'] = $delta;
	$S['delta_r']     = gs111_run();
	$S['job']         = gend_society_migration_export_job();
	$ok               = is_array( $S['job'] ) && ! empty( $S['job']['staging'] );
	$S['sql_db']      = $ok ? gs111_sql( $S['job'], 'db' ) : '';
	$S['sql_users']   = $ok ? gs111_sql( $S['job'], 'users' ) : '';
	$S['sql']         = $S['sql_db'] . $S['sql_users'];
	$S['m']           = $ok && ! empty( $S['job']['manifest'] ) ? gs111_manifest( $S['job'] ) : array();
	$S['tar']         = $ok ? gs111_tar( $S['job'] ) : array();
	return $S;
}

$S = array();

$S['delta-users'] = function () {
	global $wpdb;
	$S   = gs111c_scenario();
	$sql = $S['sql'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'users-done', 'delta job finished', wp_json_encode( $S['delta_r'] ) );
	gs111_assert( isset( $S['base']['watermark']['max_user_id'] ) && $S['max_user'] >= $S['A'], 'users-watermark', 'the base watermark carries max_user_id (' . $S['max_user'] . ') and max_term_id (' . $S['max_term'] . ')' );
	gs111_assert( isset( $S['full_manifest']['watermark']['max_user_id'] ), 'users-watermark-manifest', 'the full manifest watermark has max_user_id (the importer ID gap reads it)' );
	gs111_assert( $S['B'] > $S['max_user'] && gs111c_has_row( $S['sql_users'], 'wp_users', $S['B'] ), 'users-new', 'the new user (ID above the base max) is in the users part' );
	gs111_assert( ! gs111c_has_row( $sql, 'wp_users', $S['A'] ) && false === strpos( $sql, 'gs111-existing' ), 'users-existing-absent', 'the user that existed at the base export is not exported' );
	gs111_assert( false === strpos( $sql, $S['A_pass'] ), 'users-no-existing-hash', 'the existing user password hash is in no part' );
	gs111_assert( false !== strpos( $S['sql_users'], $S['B_pass'] ), 'users-new-hash', 'the new user row carries its password hash (needed to log in)' );
	$ids = array();
	preg_match_all( '/^INSERT IGNORE INTO `wp_users` \(`[^)]*\) VALUES \(\'(\d+)\'/m', $sql, $mm );
	foreach ( $mm[1] as $i ) {
		$ids[] = (int) $i;
	}
	gs111_assert( $ids && min( $ids ) > $S['max_user'], 'users-only-above-max', 'every wp_users line has an ID above the base max (' . implode( ',', $ids ) . ')' );
	gs111_assert( false === strpos( $sql, 'session_tokens' ) && false === strpos( $sql, 'secret-token-gs111' ), 'users-no-session-tokens', 'session_tokens never leave the origin' );
	gs111_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens'", $S['B'] ) ), 'users-token-fixture', 'fixture check: the new user does have a session_tokens row in the source' ); // phpcs:ignore WordPress.DB
	gs111_assert( (bool) preg_match( '/^DELETE FROM `wp_usermeta` WHERE `user_id` IN \([0-9,]*\b' . $S['B'] . '\b[0-9,]*\);$/m', $sql ), 'users-meta-delete', 'DELETE FROM wp_usermeta by parent precedes the meta rows' );
	gs111_assert( (bool) preg_match( '/^INSERT IGNORE INTO `wp_usermeta` \(`user_id`, `meta_key`, `meta_value`\) VALUES \(\'' . $S['B'] . '\', \'gs111_um\', \'multi\\\\nline \\\\\'quoted\\\\\'\'\); \/\* parent:' . $S['B'] . ' \*\/$/m', $sql ), 'users-meta-row', 'usermeta row: no umeta_id column, escaped newline and quote, parent tag' );
	gs111_assert( (bool) preg_match( '/`wp_usermeta` \(`user_id`, `meta_key`, `meta_value`\) VALUES \(\'' . $S['B'] . '\', \'wp_capabilities\'/', $sql ), 'users-caps', 'the capabilities key is under the wp_ prefix' );
	gs111_assert( '' === ( $why = gs111c_tags_ok( $sql, 'wp_users', $S['B'] ) ), 'users-tags-match-importer', 'chk / id comments equal the importer SQL expressions evaluated on the row', $why );
	gs111_assert( false === stripos( $sql, 'REPLACE INTO' ) && ! preg_match( '/^INSERT INTO/m', $sql ), 'users-no-replace', 'never REPLACE INTO / plain INSERT' );
	$n_users = preg_match_all( '/^INSERT IGNORE INTO `wp_users` /m', $sql );
	$n_um    = preg_match_all( '/^INSERT IGNORE INTO `wp_usermeta` /m', $sql );
	gs111_assert( (int) ( $S['m']['counts']['users'] ?? -1 ) === $n_users && (int) ( $S['m']['counts']['usermeta'] ?? -1 ) === $n_um && $n_users >= 1, 'users-counts', 'manifest counts.users / usermeta equal the lines (' . $n_users . '/' . $n_um . ')' );
	gs111_assert( in_array( 'wp_users', (array) ( $S['m']['tables'] ?? array() ), true ) && in_array( 'wp_usermeta', (array) $S['m']['tables'], true ), 'users-tables', 'tables[] declares wp_users and wp_usermeta' );
	gs111_assert( ! empty( $S['job']['parts']['users'] ), 'users-part', 'the users go in a users part' );
	// An older base without max_user_id: users registered at or after the anchor only.
	$base = get_option( 'gend_society_migration_base', array() );
	unset( $base['watermark']['max_user_id'] );
	update_option( 'gend_society_migration_base', $base, false );
	$d2 = gs111_start( GS111_DELTA_JOB, 'delta' );
	$r2 = gs111_run();
	$j2 = gend_society_migration_export_job();
	$s2 = gs111_sql( $j2, 'users' );
	gs111_assert( 'done' === $r2['status'] && gs111c_has_row( $s2, 'wp_users', $S['B'] ) && ! gs111c_has_row( $s2, 'wp_users', $S['A'] ), 'users-old-base', 'a base without max_user_id exports users registered since the anchor only' );
};

$S['delta-orders'] = function () {
	global $wpdb;
	// HPOS absent: skipped silently.
	$had = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) ); // phpcs:ignore WordPress.DB
	if ( '' === $had ) {
		$N = gs111c_scenario( array( 'orders' => false ) );
		gs111_assert( 'done' === ( $N['delta_r']['status'] ?? '' ), 'orders-absent-done', 'delta job finishes without HPOS tables', wp_json_encode( $N['delta_r'] ) );
		gs111_assert( false === strpos( $N['sql'], 'wc_order' ) && ! preg_grep( '/wc_order/', (array) ( $N['m']['tables'] ?? array() ) ), 'orders-absent-skipped', 'no order statement and no order table in the manifest' );
		gs111_cleanup();
	} else {
		gs111_info( 'the bed has WooCommerce tables already; the absent-HPOS check is covered by the plan-05 battery run on a bed without them' );
	}
	$S   = gs111c_scenario( array( 'orders' => true ) );
	$sql = $S['sql'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'orders-done', 'delta job finished', wp_json_encode( $S['delta_r'] ) );
	gs111_assert( gs111c_has_row( $sql, 'wp_wc_orders', $S['O2'] ), 'orders-updated', 'the order updated after the anchor is exported' );
	gs111_assert( $S['O3'] > $S['max_order'] && gs111c_has_row( $sql, 'wp_wc_orders', $S['O3'] ), 'orders-new', 'a new order (ID above the base max_order_id) is exported even with an old date' );
	gs111_assert( ! gs111c_has_row( $sql, 'wp_wc_orders', $S['O1'] ), 'orders-untouched', 'an order not updated since the anchor is absent' );
	foreach ( array( 'O2', 'O3' ) as $k ) {
		gs111_assert( '' === ( $why = gs111c_tags_ok( $sql, 'wp_wc_orders', $S[ $k ] ) ), 'orders-tags-' . $k, 'chk / id comments equal the importer SQL expressions (order ' . $S[ $k ] . ')', $why );
	}
	foreach ( array( 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'wc_order_items' ) as $kt ) {
		gs111_assert( (bool) preg_match( '/^DELETE FROM `wp_' . $kt . '` WHERE `order_id` IN \([0-9,]*\b' . $S['O3'] . '\b[0-9,]*\);$/m', $sql ), 'orders-delete-' . $kt, 'DELETE FROM wp_' . $kt . ' by order_id' );
		gs111_assert( (bool) preg_match( '/^INSERT IGNORE INTO `wp_' . $kt . '` .*VALUES \(.*\); \/\* parent:' . $S['O3'] . ' \*\/$/m', $sql ), 'orders-rows-' . $kt, 'rows of ' . $kt . ' for the new order carry the parent tag' );
	}
	gs111_assert( (bool) preg_match( '/^DELETE FROM `wp_wc_order_itemmeta` WHERE `order_item_id` IN \(\d+/m', $sql ) && (bool) preg_match( '/^INSERT IGNORE INTO `wp_wc_order_itemmeta` .*_gs111_qty.* \/\* parent:\d+ \*\/$/m', $sql ), 'orders-itemmeta', 'order item meta is replaced by delete-by-item then rows' );
	gs111_assert( ! preg_match( '/o1@example\.test/', $sql ), 'orders-o1-absent-rows', 'no row of the untouched order' );
	gs111_assert( false === stripos( $sql, 'REPLACE INTO' ) && false === strpos( $sql, 'wp_options' ), 'orders-safe', 'no REPLACE INTO, no wp_options' );
	$c = (array) ( $S['m']['counts'] ?? array() );
	gs111_assert( (int) ( $c['wc_orders'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_wc_orders` /m', $sql ) && (int) ( $c['wc_order_items'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_wc_order_items` /m', $sql ) && (int) ( $c['wc_order_itemmeta'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_wc_order_itemmeta` /m', $sql ), 'orders-counts', 'manifest counts equal the lines', wp_json_encode( $c ) );
	$t = (array) ( $S['m']['tables'] ?? array() );
	gs111_assert( ! array_diff( array( 'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data', 'wp_wc_order_items', 'wp_wc_order_itemmeta' ), $t ), 'orders-tables', 'tables[] declares the HPOS tables' );
	// The importer's rule for these tables.
	$P = gs111c_parents();
	gs111_assert( isset( $P['wp_wc_orders'] ), 'orders-importer-rule', 'the importer has a wp_wc_orders parent rule' );
};

$S['delta-terms'] = function () {
	$S   = gs111c_scenario();
	$sql = $S['sql'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'terms-done', 'delta job finished' );
	gs111_assert( ! empty( $S['new_term'] ) && $S['new_term'] > $S['max_term'] && gs111c_has_row( $S['sql_db'], 'wp_terms', $S['new_term'] ), 'terms-new', 'a brand-new term (ID above the base max_term_id) is exported' );
	gs111_assert( '' === ( $why = gs111c_tags_ok( $sql, 'wp_terms', $S['new_term'] ) ), 'terms-tags-match-importer', 'chk / id comments equal the importer SQL expressions', $why );
	gs111_assert( $S['used_term'] > 0 && $S['used_term'] <= $S['max_term'] && gs111c_has_row( $sql, 'wp_terms', $S['used_term'] ), 'terms-referenced', 'an older term that a changed post is related to is exported (insert-if-absent)' );
	gs111_assert( $S['old_term'] > 0 && ! gs111c_has_row( $sql, 'wp_terms', $S['old_term'] ), 'terms-old-unused-absent', 'an old term no changed post uses is absent' );
	$tt = get_term_by( 'id', $S['new_term'], 'category' );
	gs111_assert( $tt && (bool) preg_match( '/^INSERT IGNORE INTO `wp_term_taxonomy` \(`term_taxonomy_id`, `term_id`, `taxonomy`.*VALUES \(\'' . (int) $tt->term_taxonomy_id . '\', \'' . $S['new_term'] . '\', \'category\'.*\); \/\* parent:' . $S['new_term'] . ' \*\/$/m', $sql ), 'terms-taxonomy', 'its wp_term_taxonomy row is exported with the parent tag' );
	gs111_assert( (bool) preg_match( '/^INSERT IGNORE INTO `wp_termmeta` .*gs111_tm.* \/\* parent:' . $S['new_term'] . ' \*\/$/m', $sql ), 'terms-meta', 'its termmeta row is exported' );
	gs111_assert( $tt && (bool) preg_match( '/^INSERT IGNORE INTO `wp_term_relationships` \(`object_id`, `term_taxonomy_id`, `term_order`\) VALUES \(\'' . $S['edited'] . '\', \'' . (int) $tt->term_taxonomy_id . '\'/m', $sql ), 'terms-relationship', 'the post relationship to the new term is exported (plan 05)' );
	gs111_assert( ! preg_match( '/^DELETE FROM `wp_(terms|term_taxonomy|termmeta)`/m', $sql ), 'terms-no-delete', 'no DELETE on terms / term_taxonomy / termmeta (the importer does not allow it)' );
	gs111_assert( false === strpos( $sql, 'REPLACE INTO' ), 'terms-no-replace', 'never REPLACE INTO' );
	$c = (array) ( $S['m']['counts'] ?? array() );
	gs111_assert( (int) ( $c['terms'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_terms` /m', $sql ) && (int) ( $c['term_taxonomy'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_term_taxonomy` /m', $sql ) && (int) ( $c['termmeta'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_termmeta` /m', $sql ), 'terms-counts', 'manifest counts equal the lines', wp_json_encode( $c ) );
	gs111_assert( ! array_diff( array( 'wp_terms', 'wp_term_taxonomy', 'wp_termmeta' ), (array) ( $S['m']['tables'] ?? array() ) ), 'terms-tables', 'tables[] declares the term tables' );
};

$S['delta-uploads'] = function () {
	$S = gs111c_scenario();
	$j = $S['job'];
	$m = $S['m'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'uploads-done', 'delta job finished', wp_json_encode( $S['delta_r'] ) );
	$names = array_keys( $S['tar'] );
	$r     = $S['rel'];
	gs111_assert( isset( $S['tar'][ $r . 'edited.txt' ] ) && 'v2 edited' === $S['tar'][ $r . 'edited.txt' ]['body'], 'uploads-edited', 'a file edited after the anchor is in, with its new bytes' );
	gs111_assert( isset( $S['tar'][ $r . 'new.txt' ] ), 'uploads-new', 'a new file is in' );
	gs111_assert( isset( $S['tar'][ $r . 'inside.txt' ] ), 'uploads-overlap-inside', 'a file modified 200 s before the anchor is in (overlap)' );
	gs111_assert( ! isset( $S['tar'][ $r . 'outside.txt' ] ), 'uploads-overlap-outside', 'a file modified 400 s before the anchor is not' );
	gs111_assert( ! isset( $S['tar'][ $r . 'untouched.txt' ] ), 'uploads-untouched', 'an untouched file is out' );
	gs111_assert( ! preg_grep( '#(^|/)plugins/|gs111-plugin|(^|/)themes/|mu-plugins#', $names ), 'uploads-no-plugins', 'a plugin file edited after the anchor is NOT in (no plugins / themes / mu-plugins)', wp_json_encode( $names ) );
	gs111_assert( ! array_filter( $names, function ( $n ) {
		return 0 !== strpos( $n, 'uploads/' );
	} ), 'uploads-only-uploads', 'every entry is under uploads/', wp_json_encode( $names ) );
	gs111_assert( ! preg_grep( '#backup-guard#', $names ), 'uploads-exclusion', 'a changed file in an excluded folder (uploads/backup-guard) is out: the full-export exclusion rules apply' );
	gs111_assert( ! array_filter( $S['tar'], function ( $e ) {
		return '0' !== $e['type'];
	} ), 'uploads-files-only', 'regular files only: no directory, link or special entries' );
	if ( '' !== $S['link'] ) {
		gs111_assert( ! isset( $S['tar'][ $S['link'] ] ), 'uploads-symlink', 'a symlink in uploads is not in the archive (the importer refuses links)' );
		$ex = array_column( (array) ( $S['job']['excluded'] ?? array() ), 'reason', 'path' );
		gs111_assert( 'symlink' === ( $ex[ $S['link'] ] ?? '' ), 'uploads-symlink-noted', 'and is noted as excluded: symlink' );
	} else {
		gs111_info( 'symlink() unavailable on this bed' );
	}
	gs111_assert( (int) ( $m['counts']['uploads'] ?? -1 ) === count( $S['tar'] ) && (int) ( $m['uploads_total'] ?? -1 ) === count( $S['tar'] ), 'uploads-counts', 'manifest counts.uploads and uploads_total equal the archive entries (' . count( $S['tar'] ) . ')' );
	gs111_assert( (int) ( $j['totals']['files'] ?? -1 ) === count( $S['tar'] ), 'uploads-totals', 'job totals.files equals the archive entries' );
	gs111_assert( ! empty( $j['parts']['content'] ) && isset( $m['parts']['content'][0]['sha256'] ), 'uploads-part', 'a content part with sha256 is in the manifest' );
	gs111_assert( ! in_array( 'wp_uploads', (array) ( $m['tables'] ?? array() ), true ) && ! preg_grep( '/uploads/', (array) ( $m['tables'] ?? array() ) ), 'uploads-not-a-table', 'uploads is a count, never a table' );
};

$S['uploads-sha256'] = function () {
	$S = gs111c_scenario();
	$m = $S['m'];
	$j = $S['job'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'sha-done', 'delta job finished' );
	$list = (array) ( $m['uploads'] ?? array() );
	gs111_assert( count( $list ) >= 3, 'sha-list', 'the manifest lists the changed uploads (' . count( $list ) . ')' );
	$bad = array();
	foreach ( $list as $e ) {
		$p = (string) ( $e['p'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $e['sha256'] ?? '' ) ) || ! isset( $S['tar'][ $p ] ) || hash( 'sha256', $S['tar'][ $p ]['body'] ) !== $e['sha256'] || (int) $e['s'] !== strlen( $S['tar'][ $p ]['body'] ) ) {
			$bad[] = $p;
		}
	}
	gs111_assert( ! $bad, 'sha-every-entry', 'every upload entry carries a sha256 equal to the bytes in the archive, with its size', wp_json_encode( $bad ) );
	gs111_assert( count( $list ) === count( $S['tar'] ), 'sha-complete', 'the list covers every archived file' );
	$ix   = $j['staging'] . '/' . $j['content']['index']['file'];
	$miss = 0;
	foreach ( (array) file( $ix ) as $line ) {
		$e = json_decode( $line, true );
		if ( is_array( $e ) && 'f' === $e['t'] && ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $e['sha256'] ?? '' ) ) ) {
			++$miss;
		}
	}
	gs111_assert( 0 === $miss && is_file( $ix ), 'sha-index', 'every file line of the content index has a sha256' );
};

$S['empty-delta'] = function () {
	global $wpdb;
	$S = gs111c_scenario( array( 'quiet' => true ) );
	$j = $S['job'];
	$m = $S['m'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ) && 'ready' === ( $j['phase'] ?? '' ), 'empty-ready', 'a delta with nothing changed reaches ready, not an error', wp_json_encode( $S['delta_r'] ) );
	$c = (array) ( $m['counts'] ?? array() );
	gs111_assert( $c && 0 === array_sum( array_map( 'intval', $c ) ), 'empty-counts', 'manifest counts are all zero', wp_json_encode( $c ) );
	gs111_assert( true === ( $m['empty'] ?? null ) && true === ( $j['empty'] ?? null ), 'empty-flag', 'manifest and job say empty: true' );
	gs111_assert( empty( $j['parts']['users'] ) && empty( $j['parts']['content'] ) && array() === ( $m['parts']['users'] ?? null ) && array() === ( $m['parts']['content'] ?? null ), 'empty-parts', 'no users part and no content part' );
	gs111_assert( count( (array) $j['parts']['db'] ) <= 1 && 0 === strlen( trim( $S['sql'] ) ), 'empty-zero-statements', 'at most one (empty) db part and zero statements (' . strlen( $S['sql'] ) . ' bytes)' );
	gs111_assert( array() === ( $m['uploads'] ?? null ) && 0 === (int) ( $m['uploads_total'] ?? -1 ), 'empty-no-files', 'zero files' );
	gs111_assert( ! empty( $m['tables'] ) && 'delta' === ( $m['mode'] ?? '' ) && 2 === (int) ( $m['format'] ?? 0 ), 'empty-manifest-valid', 'the manifest is still a valid delta manifest' );
	// Importer scan of the empty part: zero statements, accepted.
	$files = gs111c_part_files( $j );
	list( $rc, $out ) = gs111c_importer( 'scan', preg_replace( '/^wp_/', 'wp_', (array) $m['tables'] ), $files );
	gs111_assert( 0 === $rc && false !== strpos( $out, 'SCAN-OK 0 statement' ), 'empty-importer', 'the importer scan accepts it: ' . trim( $out ) );
	// The ready report says empty: true (the hub call is faked at the HTTP layer; consent and pairing are set for the test).
	$restore = array();
	foreach ( array( 'gend_society_install_id' => 'gs111c-install', 'gend_society_install_token' => 'gs111c-token', 'gend_society_gend_base_url' => 'http://gend.invalid/' ) as $k => $v ) {
		$restore[ $k ] = get_option( $k, null );
		update_option( $k, $v, false );
	}
	gs111_on_cleanup(
		function () use ( $restore ) {
			foreach ( $restore as $k => $v ) {
				null === $v ? delete_option( $k ) : update_option( $k, $v, false );
			}
		}
	);
	$allow = '__return_true';
	$cap   = null;
	$http  = function ( $pre, $args, $url ) use ( &$cap ) {
		if ( false !== strpos( (string) $url, '/migration/progress' ) ) {
			$cap = json_decode( (string) ( $args['body'] ?? '' ), true );
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		}
		return $pre;
	};
	add_filter( 'gend_society_remote_allowed', $allow );
	add_filter( 'pre_http_request', $http, 10, 3 );
	$jr = $j;
	unset( $jr['ready_reported_at'] );
	$jr['report'] = array();
	$r            = gend_society_migration_report_progress( $jr, true );
	gs111_assert( is_array( $cap ) && true === ( $cap['ready'] ?? null ) && true === ( $cap['empty'] ?? null ) && ! empty( $cap['urls']['db'] ), 'empty-report', 'the ready report body carries ready: true, empty: true and the URLs (result ' . $r . ')', wp_json_encode( $cap ) );
	// A delta with changes reports empty: false.
	$cap = null;
	$jc  = $j;
	unset( $jc['ready_reported_at'] );
	$jc['report'] = array();
	$jc['empty']  = false;
	gend_society_migration_report_progress( $jc, true );
	remove_filter( 'pre_http_request', $http, 10 );
	remove_filter( 'gend_society_remote_allowed', $allow );
	gs111_assert( is_array( $cap ) && false === ( $cap['empty'] ?? null ), 'empty-report-false', 'a job that is not empty reports empty: false', wp_json_encode( $cap ) );
};

$S['importer-whitelist'] = function () {
	global $wpdb;
	$S   = gs111c_scenario( array( 'orders' => true ) );
	$sql = $S['sql'];
	list( , $which, $same ) = gs111c_importer_src();
	gs111_info( 'importer source: ' . $which );
	gs111_assert( $same, 'whitelist-source-same', 'the importer file in the bed equals the embedded snapshot (re-snapshot the battery when migrate.sh changes)' );
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ) && strlen( $sql ) > 0, 'whitelist-done', 'combined delta (posts, comments, terms, users, orders, uploads) finished: ' . strlen( $sql ) . ' bytes' );
	$tables = (array) ( $S['m']['tables'] ?? array() );
	$P      = gs111c_parents();
	$kids   = gs111c_importer_children();
	$bad    = array();
	foreach ( $tables as $t ) {
		if ( ! isset( $P[ $t ] ) && false === strpos( $kids, "'" . $t . "'" ) ) {
			$bad[] = $t;
		}
	}
	gs111_assert( ! $bad, 'whitelist-tables', 'every manifest table is a parent or child table of the importer (' . implode( ',', $tables ) . ')', wp_json_encode( $bad ) );
	// Every line the exporter wrote, line by line, through the importer's scanner.
	$files = gs111c_part_files( $S['job'] );
	gs111_assert( count( $files ) >= 2, 'whitelist-parts', count( $files ) . ' SQL parts (db and users)' );
	$stmts = 0;
	foreach ( explode( "\n", $sql ) as $l ) {
		if ( '' !== trim( $l ) ) {
			++$stmts;
		}
	}
	$rejects = array();
	foreach ( $files as $f ) {
		list( $rc, $out ) = gs111c_importer( 'scan', $tables, array( $f ) );
		if ( 0 !== $rc ) {
			$rejects[] = basename( $f ) . ': ' . trim( $out );
		}
	}
	gs111_assert( ! $rejects, 'whitelist-scan', 'the importer scan accepts every part: zero rejects', wp_json_encode( $rejects ) );
	list( $rc, $out ) = gs111c_importer( 'scan', $tables, $files );
	preg_match( '/SCAN-OK (\d+) statement/', $out, $mm );
	gs111_assert( 0 === $rc && (int) ( $mm[1] ?? -1 ) === $stmts, 'whitelist-count', 'the scanner counted every statement line (' . ( $mm[1] ?? '?' ) . ' of ' . $stmts . ')', trim( $out ) );
	// Negative controls: the scanner is live.
	$neg = sys_get_temp_dir() . '/gs111c-neg-' . getmypid() . '.sql';
	gs111_on_cleanup(
		function () use ( $neg ) {
			@unlink( $neg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	);
	foreach ( array(
		'replace'   => "REPLACE INTO `wp_users` (`ID`, `user_login`) VALUES ('7', 'x');\n",
		'options'   => "INSERT IGNORE INTO `wp_options` (`option_name`, `option_value`) VALUES ('a', 'b');\n",
		'set'       => "SET NAMES utf8mb4;\n",
		'untagged'  => "DELETE FROM `wp_users` WHERE `ID` IN (7);\n",
		'undeclared' => "INSERT IGNORE INTO `wp_wc_order_stats` (`order_id`) VALUES ('7');\n",
	) as $k => $line ) {
		file_put_contents( $neg, $line );
		list( $nrc ) = gs111c_importer( 'scan', array_diff( $tables, array( 'wp_wc_order_stats' ) ), array( $neg ) );
		gs111_assert( 0 !== $nrc, 'whitelist-negative-' . $k, 'the scanner rejects a ' . $k . ' line (so zero rejects above means something)' );
	}
	// Identity formulas: apply onto the very database the rows came from. Same origin identity everywhere, so nothing may
	// be inserted, overwritten or reported as a CONFLICT; a formula mismatch would show up as CONFLICT here.
	list( $rc, $out ) = gs111c_importer( 'apply', $tables, $files );
	preg_match( '/RESULT inserted=(\d+) updated=(\d+) unchanged=(\d+) conflicts=(\d+) kept=(\d+)/', $out, $r );
	gs111_assert( 0 === $rc && isset( $r[4] ) && 0 === (int) $r[4], 'whitelist-no-conflict', 'applying the delta onto its own source: zero CONFLICT rows (post, comment, user, order, term identities match the importer formulas)', trim( $out ) );
	gs111_assert( isset( $r[1] ) && 0 === (int) $r[1] && 0 === (int) $r[2] && (int) $r[3] >= 5, 'whitelist-unchanged', 'every parent row reads as unchanged (inserted=' . ( $r[1] ?? '?' ) . ' updated=' . ( $r[2] ?? '?' ) . ' unchanged=' . ( $r[3] ?? '?' ) . ' kept=' . ( $r[5] ?? '?' ) . ')' );
	foreach ( array( 'wp_users', 'wp_wc_orders', 'wp_terms', 'wp_posts', 'wp_comments' ) as $t ) {
		gs111_assert( false !== strpos( $sql, 'INSERT IGNORE INTO `' . $t . '`' ), 'whitelist-covers-' . $t, 'the combined delta covers ' . $t );
	}
	gs111_assert( false === strpos( $out, 'CONFLICT' ), 'whitelist-no-conflict-lines', 'no CONFLICT line in the applier output' );
};

$GLOBALS['gs111_sections'] = $S;

$gs111_want = array();
foreach ( isset( $args ) ? (array) $args : array() as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs111_want[] = trim( $s );
		}
	}
}
$gs111_ok = function_exists( 'gend_society_migration_export_tick' ) && function_exists( 'gend_society_migration_delta_users_step' );
gs111_info( 'delta-content on ' . home_url() . ' php=' . PHP_VERSION . ' sections=' . implode( ',', $gs111_want ) );
gs111_assert( $gs111_ok, 'module', 'exporter with delta users / orders / uploads loaded' );
foreach ( $gs111_ok ? $gs111_want : array() as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs111_assert( false, $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs111_assert( false, $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs111_cleanup();
	}
}
echo ( 0 === (int) $GLOBALS['gs111']['fail'] && (int) $GLOBALS['gs111']['pass'] > 0 ) ? 'ALL PASS (' . (int) $GLOBALS['gs111']['pass'] . ")\n" : 'FAILURES: ' . (int) $GLOBALS['gs111']['fail'] . "\n";

/* ---- snapshot of the importer's delta.php (k8s/migration-runner-image/migrate.sh, 111-04) ---- */
function gs111c_snapshot() {
	return <<<'GS111IMPORTER'
<?php
// GenD migration runner: delta SQL scanner + applier (Phase 111-04).
//   php delta.php scan  <tables-csv> <file.sql>...  classify every line; exit 2 on the first rejected one
//   php delta.php apply <tables-csv> <file.sql>...  ONE transaction, parents first; exit 4 on failure (rolled back)
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
$mode = isset($argv[1]) ? $argv[1] : '';
$declared = array_values(array_filter(explode(',', isset($argv[2]) ? (string) $argv[2] : '')));
$files = array_slice($argv, 3);

function hx($cols) {
    $p = array();
    foreach ($cols as $c) { $p[] = "IFNULL(`" . $c . "`,'')"; }
    return "MD5(CONCAT_WS('|'," . implode(',', $p) . "))";
}
// Parent tables: inserted only when the ID is absent. id = origin identity,
// chk = origin content (the same md5 formulas the exporter puts in the
// /* id:.. */ and /* chk:.. */ comments).
$PARENTS = array(
    'wp_posts' => array('pk' => 'ID', 'set' => 'posts',
        'id' => hx(array('post_name', 'post_date_gmt', 'post_type', 'guid')),
        'chk' => hx(array('post_title', 'post_content', 'post_modified_gmt', 'post_status'))),
    'wp_comments' => array('pk' => 'comment_ID', 'set' => 'comments',
        'id' => hx(array('comment_post_ID', 'comment_author', 'comment_author_email', 'comment_date_gmt')),
        'chk' => hx(array('comment_content', 'comment_approved', 'comment_type'))),
    'wp_users' => array('pk' => 'ID', 'set' => 'users',
        'id' => hx(array('user_login', 'user_registered')),
        'chk' => hx(array('user_pass', 'user_email', 'user_nicename', 'display_name', 'user_status', 'user_url'))),
    'wp_wc_orders' => array('pk' => 'id', 'set' => 'orders',
        'id' => hx(array('type', 'date_created_gmt', 'billing_email')),
        'chk' => hx(array('status', 'total_amount', 'date_updated_gmt', 'transaction_id'))),
    'wp_terms' => array('pk' => 'term_id', 'set' => 'terms',
        'id' => hx(array('slug')),
        'chk' => hx(array('name', 'slug', 'term_group'))),
);
// Child tables: applied only for owners in the allowed set.
$CHILDREN = array(
    'wp_postmeta' => array('owner' => 'post_id', 'set' => 'posts'),
    'wp_term_relationships' => array('owner' => 'object_id', 'set' => 'posts'),
    'wp_commentmeta' => array('owner' => 'comment_id', 'set' => 'comments'),
    'wp_usermeta' => array('owner' => 'user_id', 'set' => 'users'),
    'wp_wc_orders_meta' => array('owner' => 'order_id', 'set' => 'orders'),
    'wp_wc_order_addresses' => array('owner' => 'order_id', 'set' => 'orders'),
    'wp_wc_order_operational_data' => array('owner' => 'order_id', 'set' => 'orders'),
    'wp_wc_order_items' => array('owner' => 'order_id', 'set' => 'orders', 'reg' => 'order_item_id'),
    'wp_wc_order_itemmeta' => array('owner' => 'order_item_id', 'set' => 'items'),
    'wp_term_taxonomy' => array('owner' => 'term_id', 'set' => 'terms'),
    'wp_termmeta' => array('owner' => 'term_id', 'set' => 'terms'),
    'wp_wc_order_stats' => array('owner' => 'order_id', 'set' => 'orders'),
    'wp_wc_order_product_lookup' => array('owner' => 'order_id', 'set' => 'orders'),
);
$DELETABLE = array_flip(array('wp_postmeta', 'wp_term_relationships', 'wp_commentmeta', 'wp_usermeta', 'wp_wc_orders_meta',
    'wp_wc_order_addresses', 'wp_wc_order_items', 'wp_wc_order_itemmeta', 'wp_wc_order_operational_data'));

function reject($why) { throw new RuntimeException($why); }

// One line -> null (blank / full-line "--" comment) or a statement array.
// Throws RuntimeException(reason) for anything that is not allowed. The
// reason never contains row data.
function parse_line($line, $declared) {
    global $PARENTS, $CHILDREN, $DELETABLE;
    $s = rtrim($line, "\r\n");
    if (trim($s) === '') { return null; }
    if (preg_match('/^\s*--(\s|$)/', $s)) { return null; }
    $tags = array();
    $s = rtrim($s);
    for (;;) {
        if (preg_match('/\s*\/\*\s*(chk|id|parent):([0-9a-fA-F]{1,64})\s*\*\/$/', $s, $m)) {
            $tags[$m[1]] = strtolower($m[2]);
            $s = rtrim(substr($s, 0, -strlen($m[0])));
            continue;
        }
        if (substr($s, -1) === ';') { $s = rtrim(substr($s, 0, -1)); continue; }
        break;
    }
    // Skeleton: same length as $s, string bodies masked with "_", so every
    // keyword test below sees SQL structure only (a post that talks about
    // DROP TABLE or wp_options is data, not a statement).
    $n = strlen($s); $sk = ''; $i = 0;
    while ($i < $n) {
        $ch = $s[$i];
        if ($ch === "'" || $ch === '"') {
            $q = $ch; $sk .= $q; $i++; $closed = false;
            while ($i < $n) {
                $d = $s[$i];
                if ($d === '\\') { if ($i + 1 >= $n) { break; } $sk .= '__'; $i += 2; continue; }
                if ($d === $q) {
                    if ($i + 1 < $n && $s[$i + 1] === $q) { $sk .= '__'; $i += 2; continue; }
                    $closed = true; break;
                }
                $sk .= '_'; $i++;
            }
            if (!$closed) { reject('unterminated string'); }
            $sk .= $q; $i++;
            continue;
        }
        if ($ch === '`') {
            $j = strpos($s, '`', $i + 1);
            if ($j === false) { reject('unterminated identifier'); }
            $sk .= substr($s, $i, $j - $i + 1); $i = $j + 1;
            continue;
        }
        if ($ch === ';') { reject('more than one statement on a line'); }
        if ($ch === '#' || ($ch === '-' && $i + 1 < $n && $s[$i + 1] === '-') || ($ch === '/' && $i + 1 < $n && $s[$i + 1] === '*')) {
            reject('comment inside a statement');
        }
        $sk .= $ch; $i++;
    }
    if (preg_match('/\b(DROP|CREATE|ALTER|TRUNCATE|GRANT|LOAD_FILE|OUTFILE|DUMPFILE|REPLACE|SELECT|UNION|SLEEP|BENCHMARK|LOAD|HANDLER|CALL|SET|RENAME)\b|wp_options/i', $sk, $m)) {
        reject('forbidden keyword or table (' . strtolower($m[0]) . ')');
    }

    if (preg_match('/^\s*DELETE\s+FROM\s+`?(wp_[A-Za-z0-9_]+)`?\s+WHERE\s+`?([A-Za-z0-9_]+)`?\s*(?:=\s*(\d+)|IN\s*\(\s*(\d+(?:\s*,\s*\d+)*)\s*\))\s*$/i', $sk, $m)) {
        $t = strtolower($m[1]);
        if (!isset($DELETABLE[$t])) { reject('DELETE FROM ' . $t . ' is not allowed (child tables only)'); }
        if (!in_array($t, $declared, true)) { reject('table ' . $t . ' is not declared in the manifest'); }
        if (strtolower($m[2]) !== $CHILDREN[$t]['owner']) { reject('DELETE FROM ' . $t . ' must filter on ' . $CHILDREN[$t]['owner']); }
        $ids = (isset($m[3]) && $m[3] !== '') ? array($m[3]) : preg_split('/\s*,\s*/', trim(isset($m[4]) ? $m[4] : ''));
        return array('type' => 'delete', 'table' => $t, 'col' => $CHILDREN[$t]['owner'], 'ids' => $ids);
    }

    if (!preg_match('/^\s*INSERT\s+(IGNORE\s+)?INTO\s+`?(wp_[A-Za-z0-9_]+)`?\s*\(([^()]*)\)\s*VALUES\s*/i', $sk, $m)) {
        reject('statement not allowed (only INSERT IGNORE, INSERT .. ON DUPLICATE KEY UPDATE and DELETE FROM on child tables)');
    }
    $ignore = trim($m[1]) !== '';
    $t = strtolower($m[2]);
    $isparent = isset($PARENTS[$t]);
    if (!$isparent && !isset($CHILDREN[$t])) { reject('table ' . $t . ' is not allowed'); }
    if (!in_array($t, $declared, true)) { reject('table ' . $t . ' is not declared in the manifest'); }
    $cols = array(); $lcols = array();
    foreach (explode(',', $m[3]) as $c) {
        $c = trim($c, " `\t");
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $c)) { reject('bad column name'); }
        $cols[] = $c; $lcols[] = strtolower($c);
    }
    $pos = strlen($m[0]); $tuples = array();
    while (preg_match('/\G\s*(\([^()]*\))\s*/', $sk, $mm, PREG_OFFSET_CAPTURE, $pos)) {
        $tsk = $mm[1][0]; $tstart = $mm[1][1];
        $inner = substr($tsk, 1, -1);
        $vals = array(); $off = 0;
        foreach (explode(',', $inner) as $part) {
            $v = trim($part);
            if (!preg_match('~^(?:NULL|TRUE|FALSE|-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|0x[0-9a-fA-F]+|(?:_[a-z0-9]+\s*|[xbn])?(["\'])_*\1)$~i', $v)) {
                reject('value is not a plain literal');
            }
            $vals[] = trim(substr($s, $tstart + 1 + $off, strlen($part)));
            $off += strlen($part) + 1;
        }
        if (count($vals) !== count($cols)) { reject('column / value count mismatch'); }
        $tuples[] = array('raw' => substr($s, $tstart, strlen($tsk)), 'vals' => $vals);
        $pos = $mm[0][1] + strlen($mm[0][0]);
        if (substr($sk, $pos, 1) === ',') { $pos++; continue; }
        break;
    }
    if (!$tuples) { reject('no VALUES tuple'); }
    $suffix = trim(substr($s, $pos));
    $suffixsk = trim(substr($sk, $pos));
    if ($ignore) {
        if ($suffixsk !== '') { reject('INSERT IGNORE with a trailing clause'); }
    } else {
        if ($isparent) { reject('parent table ' . $t . ' takes INSERT IGNORE only (no overwrite by key)'); }
        if (!preg_match('/^ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(?:`?[A-Za-z0-9_]+`?\s*=\s*VALUES\(\s*`?[A-Za-z0-9_]+`?\s*\)\s*,?\s*)+$/i', $suffixsk)) {
            reject('plain INSERT is not allowed (INSERT IGNORE or ON DUPLICATE KEY UPDATE col=VALUES(col) only)');
        }
    }
    $stm = array('type' => 'insert', 'table' => $t, 'ignore' => $ignore, 'cols' => $cols, 'lcols' => $lcols,
        'tuples' => $tuples, 'suffix' => $suffix, 'chk' => isset($tags['chk']) ? $tags['chk'] : null,
        'id' => isset($tags['id']) ? $tags['id'] : null);
    $need = $isparent ? array(strtolower($PARENTS[$t]['pk'])) : array($CHILDREN[$t]['owner']);
    if (!$isparent && isset($CHILDREN[$t]['reg'])) { $need[] = $CHILDREN[$t]['reg']; }
    foreach ($need as $nc) {
        $ix = array_search($nc, $lcols, true);
        if ($ix === false) { reject($t . ' rows must carry the column ' . $nc); }
        // the exporter quotes every value ('10'): key columns are normalised to plain digits
        foreach ($tuples as $k => $tp) {
            if (!preg_match("/^'?(\\d{1,18})'?$/", $tp['vals'][$ix], $nm)) { reject($nc . ' must be a plain integer'); }
            $tuples[$k]['vals'][$ix] = $nm[1];
        }
        $stm['tuples'] = $tuples;
    }
    return $stm;
}

function each_stmt($files, $declared) {
    foreach ($files as $f) {
        $h = fopen($f, 'rb');
        if (!$h) { throw new RuntimeException('cannot read ' . basename($f)); }
        $no = 0;
        while (($line = fgets($h)) !== false) {
            $no++;
            try {
                $st = parse_line($line, $declared);
            } catch (RuntimeException $e) {
                fclose($h);
                throw new RuntimeException(basename($f) . ':' . $no . ': ' . $e->getMessage());
            }
            if ($st !== null) { yield $st; }
        }
        fclose($h);
    }
}

function colsql($cols) { return '`' . implode('`,`', $cols) . '`'; }

if ($mode === 'scan') {
    $n = 0;
    try {
        foreach (each_stmt($files, $declared) as $st) { $n++; }
    } catch (RuntimeException $e) {
        echo 'REJECT ', $e->getMessage(), "\n";
        exit(2);
    }
    echo "SCAN-OK $n statement(s)\n";
    exit(0);
}
if ($mode !== 'apply') { fwrite(STDERR, "usage: delta.php scan|apply tables files...\n"); exit(64); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$hostp = (string) getenv('WORDPRESS_DB_HOST');
$host = $hostp; $port = 3306;
if (strpos($hostp, ':') !== false) { list($host, $p) = explode(':', $hostp, 2); $port = (int) $p; }
try {
    $db = new mysqli($host, (string) getenv('WORDPRESS_DB_USER'), (string) getenv('WORDPRESS_DB_PASSWORD'), (string) getenv('WORDPRESS_DB_NAME'), $port);
    $db->set_charset('utf8mb4');
    $db->query("SET SESSION sql_mode=''");
    $db->begin_transaction();
    $before = array(); $after = array(); $missing = array();
    foreach ($declared as $t) {
        try {
            $r = $db->query('SELECT COUNT(*) FROM `' . $t . '`');
            $before[$t] = (int) $r->fetch_row()[0];
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1146) { throw $e; }
            $missing[$t] = true;
        }
    }
    $allowed = array('posts' => array(), 'comments' => array(), 'users' => array(), 'orders' => array(), 'terms' => array(), 'items' => array());
    $st = array('inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0, 'kept' => 0, 'child_skipped' => 0);
    $written = array(); $deleted = array();

    // Pass 1: parent rows.
    foreach (each_stmt($files, $declared) as $s) {
        if ($s['type'] !== 'insert' || !isset($PARENTS[$s['table']])) { continue; }
        $t = $s['table']; $sp = $PARENTS[$t]; $pk = $sp['pk'];
        if (isset($missing[$t])) { throw new RuntimeException('table ' . $t . ' does not exist in this container'); }
        $ix = array_search(strtolower($pk), $s['lcols'], true);
        $single = count($s['tuples']) === 1;
        foreach ($s['tuples'] as $tp) {
            $id = $tp['vals'][$ix];
            $ins = 'INSERT IGNORE INTO `' . $t . '` (' . colsql($s['cols']) . ') VALUES ' . $tp['raw'];
            $r = $db->query('SELECT 1 FROM `' . $t . '` WHERE `' . $pk . '` = ' . $id . ' LIMIT 1');
            if ($r->num_rows === 0) {
                $db->query($ins);
                if ($db->affected_rows === 1) { $st['inserted']++; $allowed[$sp['set']][$id] = true; $written[$t] = true; }
                else { $st['conflicts']++; echo 'CONFLICT ', $t, ' ', $id, " (key)\n"; }
                continue;
            }
            if ($single && $s['id'] !== null) {
                $row = $db->query('SELECT ' . $sp['id'] . ' AS i, ' . $sp['chk'] . ' AS c FROM `' . $t . '` WHERE `' . $pk . '` = ' . $id)->fetch_assoc();
                if (strtolower((string) $row['i']) !== $s['id']) {
                    $st['conflicts']++; echo 'CONFLICT ', $t, ' ', $id, "\n";
                    continue;
                }
                $allowed[$sp['set']][$id] = true;
                if ($s['chk'] !== null && strtolower((string) $row['c']) === $s['chk']) { $st['unchanged']++; continue; }
                $upd = array();
                foreach ($s['cols'] as $c) { if (strtolower($c) !== strtolower($pk)) { $upd[] = '`' . $c . '`=VALUES(`' . $c . '`)'; } }
                $db->query('INSERT INTO `' . $t . '` (' . colsql($s['cols']) . ') VALUES ' . $tp['raw'] . ' ON DUPLICATE KEY UPDATE ' . implode(',', $upd));
                $st['updated']++; $written[$t] = true;
                continue;
            }
            // Exists, and the statement carries no identity to compare: keep the container row.
            $st['kept']++;
        }
    }
    // Pass 2: remember which order item ids belong to allowed orders (itemmeta follows its item).
    foreach (each_stmt($files, $declared) as $s) {
        if ($s['type'] !== 'insert' || $s['table'] !== 'wp_wc_order_items') { continue; }
        $oi = array_search('order_id', $s['lcols'], true); $ii = array_search('order_item_id', $s['lcols'], true);
        foreach ($s['tuples'] as $tp) { if (isset($allowed['orders'][$tp['vals'][$oi]])) { $allowed['items'][$tp['vals'][$ii]] = true; } }
    }
    // Pass 3: child rows and child deletes, in file order.
    foreach (each_stmt($files, $declared) as $s) {
        if (isset($PARENTS[$s['table']])) { continue; }
        $t = $s['table']; $ch = $CHILDREN[$t];
        if (isset($missing[$t])) { throw new RuntimeException('table ' . $t . ' does not exist in this container'); }
        if ($s['type'] === 'delete') {
            $ids = array();
            foreach ($s['ids'] as $id) { if (isset($allowed[$ch['set']][$id])) { $ids[] = $id; } }
            if (!$ids) { $st['child_skipped']++; continue; }
            $db->query('DELETE FROM `' . $t . '` WHERE `' . $ch['owner'] . '` IN (' . implode(',', $ids) . ')');
            $deleted[$t] = (isset($deleted[$t]) ? $deleted[$t] : 0) + $db->affected_rows;
            if ($db->affected_rows > 0) { $written[$t] = true; }
            continue;
        }
        $oi = array_search($ch['owner'], $s['lcols'], true);
        $keep = array();
        foreach ($s['tuples'] as $tp) {
            if (isset($allowed[$ch['set']][$tp['vals'][$oi]])) { $keep[] = $tp['raw']; } else { $st['child_skipped']++; }
        }
        if (!$keep) { continue; }
        $db->query('INSERT ' . ($s['ignore'] ? 'IGNORE ' : '') . 'INTO `' . $t . '` (' . colsql($s['cols']) . ') VALUES ' . implode(',', $keep) . ($s['suffix'] !== '' ? ' ' . $s['suffix'] : ''));
        if ($db->affected_rows > 0) { $written[$t] = true; }
    }
    // Count monotonicity: a delta never shrinks a table. The one exception is
    // a child table that received a delete-by-parent (the origin dropped a
    // meta row since the base export).
    $lines = array();
    foreach ($before as $t => $b) {
        $r = $db->query('SELECT COUNT(*) FROM `' . $t . '`');
        $a = (int) $r->fetch_row()[0];
        $after[$t] = $a;
        echo 'COUNT ', $t, ' before=', $b, ' after=', $a, "\n";
        if ($a < $b && empty($deleted[$t])) {
            throw new RuntimeException('table ' . $t . ' would shrink (' . $b . ' -> ' . $a . '); rolled back');
        }
        $lines[] = $t . ' ' . ($a >= $b ? '+' : '-') . abs($a - $b);
    }
    $db->commit();
    echo 'RESULT inserted=', $st['inserted'], ' updated=', $st['updated'], ' unchanged=', $st['unchanged'],
        ' conflicts=', $st['conflicts'], ' kept=', $st['kept'], ' child_skipped=', $st['child_skipped'], "\n";
    echo 'WRITTEN ', implode(',', array_keys($written)), "\n";
    echo 'SUMMARY ', implode(', ', $lines), "\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof mysqli) { try { $db->rollback(); } catch (Throwable $x) { } }
    $code = ($e instanceof mysqli_sql_exception) ? (' sql-errno=' . $e->getCode()) : (' ' . $e->getMessage());
    echo 'APPLY-ERROR', $code, "\n";
    exit(4);
}
GS111IMPORTER;
}
