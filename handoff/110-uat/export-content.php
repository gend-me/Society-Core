<?php
/**
 * Phase 110-14 battery: the exporter's wp-content phase (filtered tar parts, M1) + manifest.json format 2 (MIG-04 part 2).
 *
 *   wp eval-file export-content.php <section>[,<section>...]      (C:/tmp/livepull/phase110/tools/110-14-run.sh)
 *
 * Environments: the gs110 bed, the gs110lim limit beds (apache / nginx / nocron; 2 GB / 60k-file uploads incl. a 300+-char
 * path), and the 110-04 MariaDB corpus install (k7_ prefix; GS110EX_LOAD=<tree> loads the modules when the plugin is not
 * active there).
 *
 * Sections (10): index excludes tar-format longname bigfile part-size stream-compat manifest watermark ready
 *   helpers: fixtures (install the C13/C14 host drop-ins, a customer gend-society copy, cache/upgrade/backups dirs, a
 *   > 2 GB sparse file, symlinks in and out of wp-content, a 300-char path; kept until fixtures-clean), export (a full
 *   DB + users + content + manifest export, kept for the sections above and for the runner's tar checks), keep-info
 *   (STAGING / CPART / INDEXF / SRCSHA / CHANGED / EXCL lines for the runner), fixtures-clean, reset.
 *
 * Sections that read an export use the kept one (helper "export") when it exists, else run their own and drop it.
 * A PHP tar reader in this file walks the concatenated content parts (multi-member gzip) the way tar -xzf does.
 *
 * Output: PASS:/FAIL:/INFO: lines; last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs110ec'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
);
function gs110ec_pass( $id, $t = '' ) {
	$GLOBALS['gs110ec']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110ec_fail( $id, $t = '' ) {
	$GLOBALS['gs110ec']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110ec_info( $t ) {
	echo 'INFO: ' . $t . "\n";
}
function gs110ec_assert( $c, $id, $t = '', $d = '' ) {
	$c ? gs110ec_pass( $id, $t ) : gs110ec_fail( $id, $t . ( '' !== $d ? ' -- ' . $d : '' ) );
	return (bool) $c;
}
function gs110ec_on_cleanup( callable $fn ) {
	$GLOBALS['gs110ec']['cleanup'][] = $fn;
}
function gs110ec_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs110ec']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs110ec_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs110ec']['cleanup'] = array();
}

if ( ! function_exists( 'gend_society_migration_export_tick' ) && getenv( 'GS110EX_LOAD' ) ) {
	$gs110ec_tree = rtrim( (string) getenv( 'GS110EX_LOAD' ), '/' );
	foreach ( array( 'inc/migration-preflight.php', 'inc/migration-export.php' ) as $gs110ec_f ) {
		if ( is_readable( $gs110ec_tree . '/' . $gs110ec_f ) ) {
			require_once $gs110ec_tree . '/' . $gs110ec_f;
		}
	}
	gs110ec_info( 'modules loaded from ' . $gs110ec_tree . ' (plugin not active on this install)' );
}

const GS110EC_JOB  = '0e110e14-aaaa-4bbb-8ccc-dddddddddddd';
const GS110EC_KEPT = 'gs110ec_kept';      // option: the kept export's job id + source facts at start
const GS110EC_FIX  = 'gs110ec_fixtures';  // option: the fixture paths made by "fixtures"

function gs110ec_ready( $sec ) {
	$ok = function_exists( 'gend_society_migration_export_tick' ) && function_exists( 'gend_society_migration_excluded' );
	gs110ec_assert( $ok, $sec . '-module', 'exporter + exclude list loaded' );
	return $ok;
}

function gs110ec_root() {
	return rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' );
}

/** Wipe every job / staging / lock the battery made (not the kept marker). */
function gs110ec_reset_job() {
	$job = get_option( 'gend_society_migration_job', null );
	if ( is_array( $job ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $job );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}

/**
 * Start a fresh job the way 110-12 delivers it. $content_only marks the DB / users tables done so the run goes straight
 * to the content phase (sections about wp-content only).
 */
function gs110ec_start( $keep = false, $content_only = false ) {
	gs110ec_reset_job();
	delete_option( GS110EC_KEPT );
	add_option(
		'gend_society_migration_job',
		array(
			'job_id'        => GS110EC_JOB,
			'export_secret' => str_repeat( 'f', 64 ),
			'received_at'   => time(),
		),
		'',
		false
	);
	do_action( 'gend_society_migration_job_received', get_option( 'gend_society_migration_job' ) );
	if ( ! $keep ) {
		gs110ec_on_cleanup( 'gs110ec_reset_job' );
	}
	$job = gend_society_migration_export_job();
	if ( $content_only && is_array( $job ) && 'db' === ( $job['phase'] ?? '' ) ) {
		foreach ( $job['tables'] as $i => $t ) {
			$job['tables'][ $i ]['done'] = true;
		}
		$job['phase'] = 'content';
		gend_society_migration_export_save( $job );
	}
	return gend_society_migration_export_job();
}

/** Run ticks until the export is done (max $n), optional callback after every tick. */
function gs110ec_run( $n = 3000, $driver = 'cron', $after = null ) {
	$r = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$r = gend_society_migration_export_tick( $driver );
		if ( $after ) {
			$after( $r );
		}
		if ( ! in_array( $r['status'], array( 'running', 'locked' ), true ) ) {
			break;
		}
	}
	return $r;
}

/** The kept export (helper "export"), or a fresh full export dropped after the section. */
function gs110ec_job_for( $sec ) {
	$kept = get_option( GS110EC_KEPT, null );
	$job  = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
	if ( is_array( $kept ) && is_array( $job ) && GS110EC_JOB === ( $job['job_id'] ?? '' ) ) {
		gs110ec_info( $sec . ': using the kept export (staging ' . ( $job['staging'] ?? '' ) . ')' );
		return array( $job, $kept );
	}
	$facts = gs110ec_source_facts();
	gs110ec_start( false );
	$r = gs110ec_run();
	gs110ec_info( $sec . ': own export ' . wp_json_encode( $r ) );
	return array( gend_society_migration_export_job(), $facts );
}

/** Source facts recorded at export start (watermark oracle). */
function gs110ec_source_facts() {
	global $wpdb;
	$hpos  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) ); // phpcs:ignore
	$order = $hpos ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}wc_orders" ) : (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold')" ); // phpcs:ignore
	return array(
		'started_at'         => time(),
		'max_post_id'        => (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}" ), // phpcs:ignore
		'max_comment_id'     => (int) $wpdb->get_var( "SELECT COALESCE(MAX(comment_ID),0) FROM {$wpdb->comments}" ), // phpcs:ignore
		'max_order_id'       => $order,
		'posts_modified_gmt' => (string) $wpdb->get_var( "SELECT COALESCE(MAX(post_modified_gmt),'') FROM {$wpdb->posts}" ), // phpcs:ignore
	);
}

/** Independent walk of wp-content (no symlinks followed, excluded dirs pruned): rel path => type f|d|l. */
function gs110ec_oracle( $rel = '', array &$out = array(), array &$excl = array() ) {
	$root = gs110ec_root();
	$abs  = '' === $rel ? $root : $root . '/' . $rel;
	$ents = @scandir( $abs ); // phpcs:ignore
	if ( ! is_array( $ents ) ) {
		return $out;
	}
	$ex = gend_society_migration_excludes();
	foreach ( $ents as $n ) {
		if ( '.' === $n || '..' === $n ) {
			continue;
		}
		$r = '' === $rel ? $n : $rel . '/' . $n;
		$a = $root . '/' . $r;
		if ( is_link( $a ) ) {
			$t = realpath( $a );
			if ( false !== $t && 0 === strpos( wp_normalize_path( $t ) . '/', wp_normalize_path( realpath( $root ) ) . '/' ) ) {
				$out[ $r ] = 'l';
			} else {
				$excl[ $r ] = 'symlink';
			}
			continue;
		}
		if ( is_dir( $a ) ) {
			$why = gend_society_migration_excluded( $r, true );
			if ( '' !== $why ) {
				$excl[ $r ] = $why;
				continue;
			}
			$out[ $r ] = 'd';
			gs110ec_oracle( $r, $out, $excl );
			continue;
		}
		if ( ! is_file( $a ) ) {
			continue;
		}
		$why = gend_society_migration_excluded( $r, false );
		if ( '' === $why && (int) @filesize( $a ) > (int) $ex['max_file_bytes'] ) { // phpcs:ignore
			$why = 'too_large';
		}
		if ( '' !== $why ) {
			$excl[ $r ] = $why;
			continue;
		}
		$out[ $r ] = 'f';
	}
	return $out;
}

/** The index entries of a job (array of decoded lines), from the index file up to its committed offset. */
function gs110ec_index( array $job ) {
	$ix = $job['content']['index'] ?? null;
	if ( ! is_array( $ix ) || empty( $ix['file'] ) ) {
		return null;
	}
	$f = $job['staging'] . '/' . $ix['file'];
	$s = (string) @file_get_contents( $f, false, null, 0, max( 0, (int) $ix['offset'] ) ); // phpcs:ignore
	$o = array();
	foreach ( explode( "\n", $s ) as $l ) {
		if ( '' === $l ) {
			continue;
		}
		$d = json_decode( $l, true );
		if ( is_array( $d ) ) {
			if ( isset( $d['p64'] ) ) {
				$d['p'] = base64_decode( $d['p64'] ); // phpcs:ignore
			}
			$o[] = $d;
		} else {
			$o[] = array( 'bad' => $l );
		}
	}
	return $o;
}

/**
 * Read the concatenated content parts as tar (the way `cat parts | tar -xz` does). Calls $each( $entry ) per entry with
 * name, type, mode, uid, gid, size, mtime, linkname, chk_ok, magic, longlink, sha256 (files).
 *
 * @return array{ok:bool,error:string,entries:int,raw:int,tail_zero:int,after_end:int}
 */
function gs110ec_tar_read( array $job, $each = null, $want_sha = true ) {
	$parts = (array) ( $job['parts']['content'] ?? array() );
	$res   = array(
		'ok'        => false,
		'error'     => '',
		'entries'   => 0,
		'raw'       => 0,
		'tail_zero' => 0,
		'after_end' => 0,
	);
	if ( ! $parts ) {
		$res['error'] = 'no content parts';
		return $res;
	}
	$pi  = 0;
	$gz  = null;
	$buf = '';
	$eof = false;
	$fill = function ( $n ) use ( &$pi, &$gz, &$buf, &$eof, $parts, $job, &$res ) {
		while ( strlen( $buf ) < $n && ! $eof ) {
			if ( ! $gz ) {
				if ( $pi >= count( $parts ) ) {
					$eof = true;
					break;
				}
				$gz = gzopen( $job['staging'] . '/' . $parts[ $pi ]['file'], 'rb' );
				++$pi;
				if ( ! $gz ) {
					$eof = true;
					break;
				}
			}
			$c = gzread( $gz, 1048576 );
			if ( false === $c || '' === $c ) {
				gzclose( $gz );
				$gz = null;
				continue;
			}
			$buf        .= $c;
			$res['raw'] += strlen( $c );
		}
	};
	$take = function ( $n ) use ( &$buf, $fill ) {
		$fill( $n );
		$o   = substr( $buf, 0, $n );
		$buf = (string) substr( $buf, $n );
		return $o;
	};
	$oct = function ( $s ) {
		$s = trim( str_replace( "\0", '', $s ) );
		return '' === $s ? 0 : (int) octdec( $s );
	};
	$long = null;
	$llnk = null;
	while ( true ) {
		$h = $take( 512 );
		if ( strlen( $h ) < 512 ) {
			$res['error'] = 'stream ended without the end-of-archive blocks';
			return $res;
		}
		if ( str_repeat( "\0", 512 ) === $h ) {
			$h2 = $take( 512 );
			if ( str_repeat( "\0", 512 ) !== $h2 ) {
				$res['error'] = 'a zero block in the middle of the archive';
				return $res;
			}
			$res['tail_zero'] = 1024;
			while ( true ) {
				$x = $take( 1048576 );
				if ( '' === $x ) {
					break;
				}
				$res['after_end'] += strlen( $x );
			}
			$res['ok'] = true;
			return $res;
		}
		$chk = $oct( substr( $h, 148, 8 ) );
		$sum = 0;
		$hh  = substr( $h, 0, 148 ) . '        ' . substr( $h, 156 );
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $hh[ $i ] );
		}
		$type = $h[156];
		$size = $oct( substr( $h, 124, 12 ) );
		$name = rtrim( substr( $h, 0, 100 ), "\0" );
		$lnk  = rtrim( substr( $h, 157, 100 ), "\0" );
		$data_len = (int) ( ceil( $size / 512 ) * 512 );
		if ( 'L' === $type || 'K' === $type ) {
			$d = rtrim( substr( $take( $data_len ), 0, $size ), "\0" );
			if ( 'L' === $type ) {
				$long = $d;
			} else {
				$llnk = $d;
			}
			if ( $chk !== $sum ) {
				$res['error'] = 'bad LongLink header checksum';
				return $res;
			}
			continue;
		}
		$e = array(
			'name'     => null !== $long ? $long : $name,
			'type'     => $type,
			'mode'     => $oct( substr( $h, 100, 8 ) ),
			'uid'      => $oct( substr( $h, 108, 8 ) ),
			'gid'      => $oct( substr( $h, 116, 8 ) ),
			'size'     => $size,
			'mtime'    => $oct( substr( $h, 136, 12 ) ),
			'linkname' => null !== $llnk ? $llnk : $lnk,
			'chk_ok'   => $chk === $sum,
			'magic'    => substr( $h, 257, 8 ),
			'longlink' => null !== $long,
			'sha256'   => '',
		);
		$long = null;
		$llnk = null;
		$hc   = $want_sha && '0' === $type ? hash_init( 'sha256' ) : null;
		$left = $size;
		while ( $left > 0 ) {
			$c = $take( (int) min( 1048576, $left ) );
			if ( '' === $c ) {
				$res['error'] = 'stream ended inside ' . $e['name'];
				return $res;
			}
			if ( $hc ) {
				hash_update( $hc, $c );
			}
			$left -= strlen( $c );
		}
		if ( $data_len > $size ) {
			$take( $data_len - $size );
		}
		if ( $hc ) {
			$e['sha256'] = hash_final( $hc );
		}
		++$res['entries'];
		if ( $each ) {
			$each( $e );
		}
	}
}

/** Write a file of $mb MB of random bytes (1 MB blocks). */
function gs110ec_random_file( $path, $mb ) {
	wp_mkdir_p( dirname( $path ) );
	$fh = fopen( $path, 'wb' ); // phpcs:ignore
	for ( $i = 0; $i < $mb; $i++ ) {
		fwrite( $fh, random_bytes( 1048576 ) ); // phpcs:ignore
	}
	fclose( $fh ); // phpcs:ignore
	return hash_file( 'sha256', $path );
}

function gs110ec_rmrf( $p ) {
	if ( is_link( $p ) || is_file( $p ) ) {
		@unlink( $p ); // phpcs:ignore
		return;
	}
	if ( ! is_dir( $p ) ) {
		return;
	}
	foreach ( (array) scandir( $p ) as $n ) {
		if ( '.' !== $n && '..' !== $n ) {
			gs110ec_rmrf( $p . '/' . $n );
		}
	}
	@rmdir( $p ); // phpcs:ignore
}

/** The 300-char nested path (created by "fixtures"; the limit beds' uploads already hold one). */
function gs110ec_long_rel() {
	$seg = 'gs110ec-a-rather-long-directory-name-for-the-gnu-longlink-case-0123456789';
	return 'uploads/gs110ec-long/' . $seg . '/' . $seg . '/' . $seg . '/a-file-at-the-end-of-a-path-longer-than-three-hundred-characters.txt';
}

/** Fixture set (C13/C14 and friends): rel path => [kind, expected reason or '' when copied]. */
function gs110ec_fixture_list() {
	return array(
		'object-cache.php'                                   => array( 'file', 'dropin', "<?php\n/* object-cache.php drop-in stand-in (gs110 corpus) */\n" ),
		'advanced-cache.php'                                 => array( 'file', 'dropin', "<?php\n/* advanced-cache.php drop-in stand-in (gs110 corpus) */\n" ),
		'mu-plugins/endurance-page-cache.php'                => array( 'file', 'mu_plugin', "<?php\n/* Plugin Name: Endurance Page Cache (host mu-plugin stand-in, gs110 corpus) */\n" ),
		'plugins/gend-society-copy/gend-society.php'         => array( 'file', 'plugin', "<?php\n/**\n * Plugin Name: GenD Society (customer copy, gs110 corpus)\n * Version: 0.0.1\n */\n" ),
		'plugins/gs110ec-customer-plugin/gs110ec-customer-plugin.php' => array( 'file', '', "<?php\n/**\n * Plugin Name: gs110ec customer plugin\n * Version: 1.0\n */\n" ),
		'themes/gs110ec-theme/style.css'                     => array( 'file', '', "/*\nTheme Name: gs110ec theme\n*/\n" ),
		'cache/gs110ec-cached.html'                          => array( 'file', 'dir', 'cached page' ),
		'upgrade/gs110ec-upgrade.tmp'                        => array( 'file', 'dir', 'upgrade tmp' ),
		'backups-gs110ec/site.zip'                           => array( 'file', 'dir', 'backup zip' ),
		'gs110ec-fixtures/huge.bin'                          => array( 'sparse', 'too_large', '' ),
		'gs110ec-fixtures/plain.txt'                         => array( 'file', '', "plain fixture file\n" ),
		'gs110ec-fixtures/link-inside'                       => array( 'link', '', 'plain.txt' ),
		'gs110ec-fixtures/link-outside'                      => array( 'link', 'symlink', '/etc/hostname' ),
		gs110ec_long_rel()                                   => array( 'file', '', 'the long path fixture body' ),
	);
}

/** Paths that are excluded on every bed (the real gend-society plugin folder is the customer's own copy). */
function gs110ec_always_excluded() {
	return array( 'plugins/gend-society' => 'plugin' );
}

// ---------------------------------------------------------------------------------------------------------------
$S = array();

$S['fixtures'] = function () {
	$root = gs110ec_root();
	$made = array();
	foreach ( gs110ec_fixture_list() as $rel => $f ) {
		$a = $root . '/' . $rel;
		wp_mkdir_p( dirname( $a ) );
		if ( file_exists( $a ) || is_link( $a ) ) {
			continue;
		}
		if ( 'file' === $f[0] ) {
			file_put_contents( $a, $f[2] ); // phpcs:ignore
		} elseif ( 'sparse' === $f[0] ) {
			$fh = fopen( $a, 'wb' ); // phpcs:ignore
			fseek( $fh, 2147483648 + 4096 );
			fwrite( $fh, 'x' ); // phpcs:ignore
			fclose( $fh ); // phpcs:ignore
		} elseif ( 'link' === $f[0] ) {
			@symlink( $f[2], $a ); // phpcs:ignore
		}
		$made[] = $rel;
	}
	foreach ( array( 'plugins/gend-society-copy', 'plugins/gs110ec-customer-plugin', 'themes/gs110ec-theme', 'backups-gs110ec', 'gs110ec-fixtures', 'uploads/gs110ec-long', 'cache/gs110ec-cached.html', 'upgrade/gs110ec-upgrade.tmp' ) as $d ) {
		$made[] = $d;
	}
	update_option( GS110EC_FIX, array_values( array_unique( array_merge( (array) get_option( GS110EC_FIX, array() ), $made ) ) ), false );
	$ok = true;
	foreach ( gs110ec_fixture_list() as $rel => $f ) {
		$ok = $ok && ( file_exists( $root . '/' . $rel ) || is_link( $root . '/' . $rel ) );
	}
	gs110ec_assert( $ok, 'fixtures', count( gs110ec_fixture_list() ) . ' fixture paths present (C13/C14 drop-ins, gend-society copy, cache dirs, > 2 GB sparse file, symlinks, 300-char path)' );
	gs110ec_info( 'long path length ' . strlen( gs110ec_long_rel() ) );
};

$S['fixtures-clean'] = function () {
	$root = gs110ec_root();
	foreach ( array_merge( array_keys( gs110ec_fixture_list() ), (array) get_option( GS110EC_FIX, array() ) ) as $rel ) {
		if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
			continue;
		}
		$a = $root . '/' . $rel;
		if ( 0 === strpos( $rel, 'plugins/gend-society-copy' ) || 0 === strpos( $rel, 'plugins/gs110ec' ) || 0 === strpos( $rel, 'themes/gs110ec' ) || 0 === strpos( $rel, 'backups-gs110ec' ) || 0 === strpos( $rel, 'gs110ec-fixtures' ) || 0 === strpos( $rel, 'uploads/gs110ec' ) || in_array( $rel, array( 'object-cache.php', 'advanced-cache.php', 'mu-plugins/endurance-page-cache.php', 'cache/gs110ec-cached.html', 'upgrade/gs110ec-upgrade.tmp' ), true ) ) {
			gs110ec_rmrf( $a );
		}
	}
	delete_option( GS110EC_FIX );
	$left = 0;
	foreach ( array_keys( gs110ec_fixture_list() ) as $rel ) {
		$left += ( file_exists( $root . '/' . $rel ) || is_link( $root . '/' . $rel ) ) ? 1 : 0;
	}
	gs110ec_assert( 0 === $left, 'fixtures-clean', 'fixtures removed' );
};

$S['index'] = function () {
	if ( ! gs110ec_ready( 'index' ) ) {
		return;
	}
	$tiny = function () {
		return 0.05;
	};
	$few  = function () {
		return 300;
	};
	// Run 1: small budget + small index steps, a forced stop in the middle (bytes past the committed offset, like a
	// request killed during the write), then resume.
	add_filter( 'gend_society_migration_tick_budget', $tiny );
	add_filter( 'gend_society_migration_index_step_entries', $few );
	gs110ec_start( false, true );
	$torn = false;
	$ticks = 0;
	for ( $i = 0; $i < 5000; $i++ ) {
		$r = gend_society_migration_export_tick( 'cron' );
		++$ticks;
		$job = gend_society_migration_export_job();
		$ix  = $job['content']['index'] ?? null;
		if ( ! $torn && is_array( $ix ) && (int) $ix['offset'] > 0 && empty( $ix['done'] ) ) {
			file_put_contents( $job['staging'] . '/' . $ix['file'], "{\"p\":\"gs110ec-torn-entry\",\"t\":\"f\"}\n{\"p\":\"gs110ec-tor", FILE_APPEND ); // phpcs:ignore
			$torn = true;
		}
		if ( ( is_array( $ix ) && ! empty( $ix['done'] ) ) || ! in_array( $r['status'], array( 'running', 'locked' ), true ) ) {
			break;
		}
	}
	remove_filter( 'gend_society_migration_tick_budget', $tiny );
	remove_filter( 'gend_society_migration_index_step_entries', $few );
	$job  = gend_society_migration_export_job();
	$list = gs110ec_index( (array) $job );
	if ( ! gs110ec_assert( is_array( $list ) && ! empty( $job['content']['index']['done'] ), 'index-done', 'the content phase wrote a complete JSONL index part (' . $ticks . ' ticks)', wp_json_encode( $job['content']['index'] ?? null ) ) ) {
		return;
	}
	gs110ec_assert( $torn, 'index-torn', 'a forced stop left bytes past the committed index offset' );
	$bad = array_filter(
		$list,
		function ( $e ) {
			return isset( $e['bad'] ) || ! isset( $e['p'], $e['t'] );
		}
	);
	gs110ec_assert( ! $bad, 'index-jsonl', 'every index line is a JSON object with p and t (no torn line left)', wp_json_encode( array_slice( array_values( $bad ), 0, 2 ) ) );
	$paths = array_column( $list, 'p' );
	gs110ec_assert( ! in_array( 'gs110ec-torn-entry', $paths, true ), 'index-repaired', 'the torn entry was cut away on resume' );
	gs110ec_assert( count( $paths ) === count( array_unique( $paths ) ), 'index-once', 'every path appears once (' . count( $paths ) . ' entries)', (string) ( count( $paths ) - count( array_unique( $paths ) ) ) . ' duplicates' );
	$excl   = array();
	$o      = array();
	$oracle = gs110ec_oracle( '', $o, $excl );
	$have   = array();
	foreach ( $list as $e ) {
		$have[ $e['p'] ] = $e['t'];
	}
	ksort( $have );
	ksort( $oracle );
	$miss  = array_diff_key( $oracle, $have );
	$extra = array_diff_key( $have, $oracle );
	gs110ec_assert( ! $miss && ! $extra, 'index-oracle', 'index == an independent walk of wp-content minus the excludes (' . count( $oracle ) . ')', 'missing ' . wp_json_encode( array_slice( array_keys( $miss ), 0, 5 ) ) . ' extra ' . wp_json_encode( array_slice( array_keys( $extra ), 0, 5 ) ) );
	$types = true;
	foreach ( $oracle as $p => $t ) {
		$types = $types && ( ( $have[ $p ] ?? '' ) === $t );
	}
	gs110ec_assert( $types, 'index-types', 'entry types (f / d / l) match' );
	// Run 2: an uninterrupted index with the default budget gives the same list.
	gs110ec_reset_job();
	gs110ec_start( false, true );
	for ( $i = 0; $i < 500; $i++ ) {
		gend_society_migration_export_tick( 'cron' );
		$j2 = gend_society_migration_export_job();
		if ( ! empty( $j2['content']['index']['done'] ) ) {
			break;
		}
	}
	$l2 = (array) gs110ec_index( (array) gend_society_migration_export_job() );
	gs110ec_assert( array_column( $l2, 'p' ) === $paths, 'index-resume-same', 'the resumed index lists the same entries in the same order as an uninterrupted one', count( $l2 ) . ' vs ' . count( $paths ) );
};

$S['excludes'] = function () {
	if ( ! gs110ec_ready( 'excludes' ) ) {
		return;
	}
	list( $job ) = gs110ec_job_for( 'excludes' );
	$root  = gs110ec_root();
	$list  = (array) gs110ec_index( (array) $job );
	$paths = array_column( $list, 'p' );
	$exl   = array();
	foreach ( (array) ( $job['excluded'] ?? array() ) as $e ) {
		if ( is_array( $e ) && isset( $e['path'] ) ) {
			$exl[ $e['path'] ] = (string) ( $e['reason'] ?? '' );
		}
	}
	gs110ec_assert( (bool) $exl, 'excludes-list', 'the job lists excluded paths with a reason (' . count( $exl ) . ')' );
	$want = gs110ec_always_excluded();
	if ( get_option( GS110EC_FIX ) ) {
		foreach ( gs110ec_fixture_list() as $rel => $f ) {
			if ( '' !== $f[1] ) {
				// an excluded directory is listed itself, not its files
				$want[ in_array( $f[1], array( 'dir', 'plugin' ), true ) ? dirname( $rel ) : $rel ] = $f[1];
			}
		}
	} else {
		gs110ec_info( 'excludes: fixtures not installed (run section fixtures first); checking the always-excluded set only' );
	}
	foreach ( $want as $rel => $why ) {
		if ( ! file_exists( $root . '/' . $rel ) && ! is_link( $root . '/' . $rel ) ) {
			continue;
		}
		$inside = array_filter(
			$paths,
			function ( $p ) use ( $rel ) {
				return $p === $rel || 0 === strpos( $p, $rel . '/' );
			}
		);
		gs110ec_assert( ! $inside, 'excludes-absent ' . $rel, 'not in the index / stream' );
		gs110ec_assert( isset( $exl[ $rel ] ) && ( $exl[ $rel ] === $why || ( 'dir' === $why && 'dir' === $exl[ $rel ] ) ), 'excludes-listed ' . $rel, 'listed in excluded[] as ' . $why, (string) ( $exl[ $rel ] ?? 'missing' ) );
	}
	if ( get_option( GS110EC_FIX ) ) {
		foreach ( array( 'plugins/gs110ec-customer-plugin/gs110ec-customer-plugin.php', 'themes/gs110ec-theme/style.css', 'gs110ec-fixtures/plain.txt', 'gs110ec-fixtures/link-inside' ) as $rel ) {
			gs110ec_assert( in_array( $rel, $paths, true ), 'excludes-kept ' . $rel, 'customer plugin / theme / plain files and inside symlinks are carried' );
		}
	}
	$names = array();
	$rr    = gs110ec_tar_read(
		(array) $job,
		function ( $e ) use ( &$names ) {
			$names[] = rtrim( $e['name'], '/' );
		},
		false
	);
	$bad = array();
	foreach ( $names as $n ) {
		foreach ( array_keys( $want ) as $rel ) {
			if ( $n === $rel || 0 === strpos( $n, $rel . '/' ) ) {
				$bad[] = $n;
			}
		}
	}
	gs110ec_assert( $rr['ok'] && ! $bad, 'excludes-stream', 'no excluded path in the tar stream (' . count( $names ) . ' entries)', $rr['error'] . ' ' . wp_json_encode( array_slice( $bad, 0, 5 ) ) );
};

$S['tar-format'] = function () {
	if ( ! gs110ec_ready( 'tar-format' ) ) {
		return;
	}
	list( $job ) = gs110ec_job_for( 'tar-format' );
	$bad = array(
		'chk'   => 0,
		'mode'  => 0,
		'owner' => 0,
		'magic' => 0,
	);
	$seen = array();
	$data = 0;
	$rr   = gs110ec_tar_read(
		(array) $job,
		function ( $e ) use ( &$bad, &$seen, &$data ) {
			$seen[ $e['type'] ] = ( $seen[ $e['type'] ] ?? 0 ) + 1;
			$bad['chk']        += $e['chk_ok'] ? 0 : 1;
			$want               = '5' === $e['type'] ? 0755 : ( '2' === $e['type'] ? 0777 : 0644 );
			$bad['mode']       += ( $e['mode'] === $want ) ? 0 : 1;
			$bad['owner']      += ( 0 === $e['uid'] && 0 === $e['gid'] ) ? 0 : 1;
			$bad['magic']      += ( 0 === strpos( $e['magic'], 'ustar' ) ) ? 0 : 1;
			$data              += 512 + (int) ( ceil( $e['size'] / 512 ) * 512 ) + ( $e['longlink'] ? 512 + (int) ( ceil( ( strlen( $e['name'] ) + 1 ) / 512 ) * 512 ) : 0 );
		},
		false
	);
	gs110ec_assert( $rr['ok'], 'tar-format-read', 'the concatenated content parts read as one tar stream (' . $rr['entries'] . ' entries)', $rr['error'] );
	gs110ec_assert( 0 === $bad['chk'], 'tar-format-checksum', 'every header checksum is valid', (string) $bad['chk'] );
	gs110ec_assert( 0 === $bad['mode'], 'tar-format-mode', 'modes normalised: files 0644, dirs 0755 (symlinks 0777)', (string) $bad['mode'] );
	gs110ec_assert( 0 === $bad['owner'], 'tar-format-owner', 'uid / gid 0', (string) $bad['owner'] );
	gs110ec_assert( 0 === $bad['magic'], 'tar-format-magic', 'ustar magic on every header' );
	gs110ec_assert( isset( $seen['0'], $seen['5'] ), 'tar-format-types', 'regular file and directory entries present ' . wp_json_encode( $seen ) );
	gs110ec_assert( 1024 === $rr['tail_zero'] && 0 === $rr['after_end'], 'tar-format-end', 'exactly one end-of-archive (1024 zero bytes) at the very end, nothing after it', $rr['tail_zero'] . '/' . $rr['after_end'] );
	gs110ec_assert( $rr['raw'] === $data + 1024 + $rr['after_end'], 'tar-format-no-inner-end', 'no end blocks between parts (stream = entries + 1024)', $rr['raw'] . ' vs ' . ( $data + 1024 ) );
	$parts = (array) $job['parts']['content'];
	$last  = end( $parts );
	$fh    = fopen( $job['staging'] . '/' . $last['file'], 'rb' ); // phpcs:ignore
	fseek( $fh, -8, SEEK_END );
	$trailer = unpack( 'Vcrc/Visize', (string) fread( $fh, 8 ) ); // phpcs:ignore
	fclose( $fh ); // phpcs:ignore
	gs110ec_assert( 1024 === (int) $trailer['isize'] && sprintf( '%u', crc32( str_repeat( "\0", 1024 ) ) ) === sprintf( '%u', $trailer['crc'] ), 'tar-format-final-member', 'the last gzip member of the last part is 1024 zero bytes', wp_json_encode( $trailer ) );
	$lvl = true;
	foreach ( $parts as $p ) {
		$h   = (string) file_get_contents( $job['staging'] . '/' . $p['file'], false, null, 0, 10 ); // phpcs:ignore
		$lvl = $lvl && "\x1f\x8b\x08" === substr( $h, 0, 3 ) && 4 === ord( $h[8] );
	}
	gs110ec_assert( $lvl, 'tar-format-gzip-l1', 'every part is gzip members written at a fast level (gzip level 1: XFL 4)' );
};

$S['longname'] = function () {
	if ( ! gs110ec_ready( 'longname' ) ) {
		return;
	}
	list( $job ) = gs110ec_job_for( 'longname' );
	$root = gs110ec_root();
	$long = array();
	$list = (array) gs110ec_index( (array) $job );
	foreach ( $list as $e ) {
		if ( 'f' === ( $e['t'] ?? '' ) && strlen( (string) $e['p'] ) >= 300 ) {
			$long[ $e['p'] ] = hash_file( 'sha256', $root . '/' . $e['p'] );
		}
	}
	if ( ! gs110ec_assert( (bool) $long, 'longname-source', 'a file path of 300+ characters is in the index (' . count( $long ) . ')' ) ) {
		return;
	}
	$found = array();
	gs110ec_tar_read(
		(array) $job,
		function ( $e ) use ( &$found, $long ) {
			if ( isset( $long[ $e['name'] ] ) ) {
				$found[ $e['name'] ] = $e;
			}
		}
	);
	$ok = true;
	foreach ( $long as $p => $sha ) {
		$ok = $ok && isset( $found[ $p ] ) && $found[ $p ]['longlink'] && $found[ $p ]['sha256'] === $sha;
	}
	gs110ec_assert( $ok, 'longname-roundtrip', 'the 300+-char paths come back whole through a ././@LongLink entry with the right sha256', wp_json_encode( array_map( function ( $e ) { return array( strlen( $e['name'] ), $e['longlink'] ); }, $found ) ) );
	update_option( 'gs110ec_longpaths', array_keys( $long ), false );
	foreach ( array_keys( $long ) as $p ) {
		echo 'LONGPATH ' . $p . "\n";
	}
};

$S['bigfile'] = function () {
	if ( ! gs110ec_ready( 'bigfile' ) ) {
		return;
	}
	$rel = 'uploads/gs110ec-big-' . substr( md5( home_url() ), 0, 6 ) . '/big.bin';
	$abs = gs110ec_root() . '/' . $rel;
	$sha = gs110ec_random_file( $abs, 500 );
	gs110ec_on_cleanup(
		function () use ( $abs ) {
			gs110ec_rmrf( dirname( $abs ) );
		}
	);
	$one = function () {
		return 1.0;
	};
	add_filter( 'gend_society_migration_tick_budget', $one );
	gs110ec_start( false, true );
	$on   = 0;
	$runs = 0;
	gs110ec_run(
		20000,
		'cron',
		function ( $r ) use ( &$on, &$runs, $rel ) {
			++$runs;
			$job = gend_society_migration_export_job();
			$en  = $job['content']['entry'] ?? null;
			if ( is_array( $en ) && ( $en['p'] ?? '' ) === $rel ) {
				++$on;
			}
		}
	);
	remove_filter( 'gend_society_migration_tick_budget', $one );
	$job = gend_society_migration_export_job();
	gs110ec_info( "bigfile: 500 MB, {$runs} ticks, {$on} ticks ended inside the file" );
	gs110ec_assert( 'ready' === ( $job['phase'] ?? '' ), 'bigfile-ready', 'the export finished', (string) ( $job['phase'] ?? '' ) );
	gs110ec_assert( $on >= 3, 'bigfile-spans', 'the 500 MB file was copied across more than 3 ticks (an in-entry cursor)', (string) $on );
	$got = '';
	$sz  = 0;
	gs110ec_tar_read(
		(array) $job,
		function ( $e ) use ( &$got, &$sz, $rel ) {
			if ( $e['name'] === $rel ) {
				$got = $e['sha256'];
				$sz  = $e['size'];
			}
		}
	);
	gs110ec_assert( $got === $sha && 524288000 === $sz, 'bigfile-sha', 'one tar entry of 524288000 bytes with the right sha256', $sz . ' ' . substr( $got, 0, 12 ) );
	$max = 0;
	foreach ( (array) $job['parts']['content'] as $p ) {
		$max = max( $max, (int) $p['size'] );
	}
	gs110ec_assert( count( (array) $job['parts']['content'] ) >= 8 && $max <= 64 * 1048576 + 65536, 'bigfile-parts', 'the entry spans several parts of <= 64 MB (' . count( (array) $job['parts']['content'] ) . ', largest ' . round( $max / 1048576, 1 ) . ' MB)' );
};

$S['part-size'] = function () {
	if ( ! gs110ec_ready( 'part-size' ) ) {
		return;
	}
	$kept = get_option( GS110EC_KEPT, null );
	$job  = gend_society_migration_export_job();
	if ( ! ( is_array( $kept ) && is_array( $job ) && count( (array) ( $job['parts']['content'] ?? array() ) ) >= 3 ) ) {
		$dir = gs110ec_root() . '/uploads/gs110ec-ps-' . substr( md5( home_url() ), 0, 6 );
		for ( $i = 1; $i <= 3; $i++ ) {
			gs110ec_random_file( $dir . "/f{$i}.bin", 30 );
		}
		gs110ec_on_cleanup(
			function () use ( $dir ) {
				gs110ec_rmrf( $dir );
			}
		);
		gs110ec_start( false, true );
		gs110ec_run();
		$job = gend_society_migration_export_job();
	}
	$parts = (array) ( $job['parts']['content'] ?? array() );
	$info  = array_map(
		function ( $p ) {
			return round( $p['size'] / 1048576, 1 );
		},
		$parts
	);
	gs110ec_info( 'part-size: ' . count( $parts ) . ' content parts (MB): ' . implode( ', ', array_slice( $info, 0, 40 ) ) );
	gs110ec_assert( count( $parts ) >= 3, 'part-size-several', 'several content parts', (string) count( $parts ) );
	$ok = true;
	$sh = true;
	foreach ( $parts as $p ) {
		$f  = $job['staging'] . '/' . $p['file'];
		$ok = $ok && (int) $p['size'] <= 64 * 1048576 + 65536;
		$sh = $sh && filesize( $f ) === (int) $p['size'] && hash_file( 'sha256', $f ) === $p['sha256'];
	}
	gs110ec_assert( $ok, 'part-size-max', 'every content part <= 64 MB (+ header slack)' );
	gs110ec_assert( $sh, 'part-size-sha', 'recorded size + sha256 == the files' );
	gs110ec_assert( array_column( $parts, 'n' ) === range( 1, count( $parts ) ), 'part-size-numbered', 'parts numbered 1..n' );
};

$S['stream-compat'] = function () {
	if ( ! gs110ec_ready( 'stream-compat' ) ) {
		return;
	}
	list( $job, $facts ) = gs110ec_job_for( 'stream-compat' );
	$root  = gs110ec_root();
	$start = (int) ( $facts['started_at'] ?? time() );
	$tar   = array();
	$rr    = gs110ec_tar_read(
		(array) $job,
		function ( $e ) use ( &$tar ) {
			$tar[ rtrim( $e['name'], '/' ) ] = $e;
		}
	);
	if ( ! gs110ec_assert( $rr['ok'], 'stream-compat-read', 'the stream reads to its end (' . $rr['entries'] . ' entries)', $rr['error'] ) ) {
		return;
	}
	$list    = (array) gs110ec_index( (array) $job );
	$idx     = array();
	foreach ( $list as $e ) {
		$idx[ $e['p'] ] = $e['t'];
	}
	$tm = array();
	foreach ( $tar as $n => $e ) {
		$tm[ $n ] = '5' === $e['type'] ? 'd' : ( '2' === $e['type'] ? 'l' : 'f' );
	}
	ksort( $tm );
	ksort( $idx );
	$vanished = (array) ( $job['content']['changed'] ?? array() );
	$miss     = array_diff_key( $idx, $tm );
	foreach ( $vanished as $v ) {
		unset( $miss[ $v['path'] ?? '' ] );
	}
	gs110ec_assert( ! $miss && ! array_diff_key( $tm, $idx ), 'stream-compat-tree', 'tar entries == the index (' . count( $tm ) . ')', 'missing ' . wp_json_encode( array_slice( array_keys( $miss ), 0, 5 ) ) . ' extra ' . wp_json_encode( array_slice( array_keys( array_diff_key( $tm, $idx ) ), 0, 5 ) ) );
	$bad     = array();
	$changed = 0;
	$checked = 0;
	foreach ( $tar as $n => $e ) {
		if ( '0' !== $e['type'] ) {
			continue;
		}
		$a = $root . '/' . $n;
		clearstatcache( true, $a );
		if ( ! is_file( $a ) || (int) filemtime( $a ) >= $start ) {
			++$changed; // written by the live site during the export (logs); not comparable
			continue;
		}
		++$checked;
		if ( hash_file( 'sha256', $a ) !== $e['sha256'] ) {
			$bad[] = $n;
		}
	}
	gs110ec_info( "stream-compat: {$checked} files compared, {$changed} changed by the live site during the export (skipped)" );
	gs110ec_assert( $checked > 0 && ! $bad, 'stream-compat-sha', 'per-file sha256 of the stream == the source', wp_json_encode( array_slice( $bad, 0, 5 ) ) );
	gs110ec_assert( $changed <= 20, 'stream-compat-changed-few', 'only a few files changed during the export (' . $changed . ')' );
	$lk = true;
	foreach ( $tar as $n => $e ) {
		if ( '2' === $e['type'] ) {
			$target = realpath( dirname( $root . '/' . $n ) . '/' . $e['linkname'] );
			$lk     = $lk && 0 !== strpos( $e['linkname'], '/' ) && false !== $target && 0 === strpos( wp_normalize_path( $target ), wp_normalize_path( realpath( $root ) ) );
		}
	}
	gs110ec_assert( $lk, 'stream-compat-symlinks', 'symlink entries are relative and resolve inside wp-content' );
};

$S['manifest'] = function () {
	if ( ! gs110ec_ready( 'manifest' ) ) {
		return;
	}
	global $wpdb;
	list( $job ) = gs110ec_job_for( 'manifest' );
	$mf = $job['manifest'] ?? null;
	if ( ! gs110ec_assert( is_array( $mf ) && ! empty( $mf['file'] ) && is_file( $job['staging'] . '/' . $mf['file'] ), 'manifest-file', 'manifest.json written to staging', wp_json_encode( $mf ) ) ) {
		return;
	}
	$path = $job['staging'] . '/' . $mf['file'];
	gs110ec_assert( hash_file( 'sha256', $path ) === ( $mf['sha256'] ?? '' ) && filesize( $path ) === (int) ( $mf['size'] ?? -1 ), 'manifest-recorded', 'size + sha256 recorded in the job' );
	$m = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore
	if ( ! gs110ec_assert( is_array( $m ), 'manifest-json', 'valid JSON' ) ) {
		return;
	}
	$types = array(
		'format'              => 'integer',
		'source_kind'         => 'string',
		'source_url'          => 'string',
		'home_url'            => 'string',
		'site_url'            => 'string',
		'table_prefix'        => 'string',
		'source_table_prefix' => 'string',
		'db_charset'          => 'string',
		'db_collations'       => 'array',
		'mysql_version'       => 'string',
		'php_version'         => 'string',
		'wp_version'          => 'string',
		'active_plugins'      => 'array',
		'active_theme'        => 'string',
		'template'            => 'string',
		'stylesheet'          => 'string',
		'parts'               => 'array',
		'totals'              => 'array',
		'exported_at'         => 'string',
		'watermark'           => 'array',
		'excluded'            => 'array',
		'alt_base'            => 'string',
	);
	$bad = array();
	foreach ( $types as $k => $t ) {
		if ( ! array_key_exists( $k, $m ) || gettype( $m[ $k ] ) !== $t ) {
			$bad[] = $k . ':' . ( array_key_exists( $k, $m ) ? gettype( $m[ $k ] ) : 'missing' );
		}
	}
	gs110ec_assert( ! $bad, 'manifest-keys', 'every documented key present with the right type (' . count( $types ) . ')', implode( ', ', $bad ) );
	gs110ec_assert( 2 === ( $m['format'] ?? 0 ) && 'external' === ( $m['source_kind'] ?? '' ), 'manifest-format', 'format 2, source_kind external' );
	gs110ec_assert( 'wp_' === ( $m['table_prefix'] ?? '' ) && $wpdb->base_prefix === ( $m['source_table_prefix'] ?? '' ), 'manifest-prefix', 'table_prefix wp_, source_table_prefix ' . $wpdb->base_prefix, (string) ( $m['source_table_prefix'] ?? '' ) );
	gs110ec_assert( untrailingslashit( home_url() ) === untrailingslashit( (string) ( $m['source_url'] ?? '' ) ) && get_option( 'home' ) === ( $m['home_url'] ?? '' ) && get_option( 'siteurl' ) === ( $m['site_url'] ?? '' ), 'manifest-urls', 'source_url / home_url / site_url' );
	gs110ec_assert( false !== strpos( (string) ( $m['alt_base'] ?? '' ), '?rest_route=' ) && 0 === strpos( (string) ( $m['alt_base'] ?? '' ), untrailingslashit( home_url() ) ), 'manifest-alt-base', 'alt_base = the /?rest_route= form on the site address', (string) ( $m['alt_base'] ?? '' ) );
	gs110ec_assert( get_option( 'stylesheet' ) === ( $m['stylesheet'] ?? '' ) && get_option( 'template' ) === ( $m['template'] ?? '' ) && array_values( (array) get_option( 'active_plugins', array() ) ) === ( $m['active_plugins'] ?? null ), 'manifest-site', 'theme, template, active plugins' );
	$pok = true;
	foreach ( array( 'db', 'users', 'content' ) as $k ) {
		$jp = (array) ( $job['parts'][ $k ] ?? array() );
		$mp = (array) ( $m['parts'][ $k ] ?? array() );
		$pok = $pok && count( $jp ) === count( $mp ) && count( $mp ) > 0;
		foreach ( $mp as $i => $p ) {
			$f   = $job['staging'] . '/' . ( $jp[ $i ]['file'] ?? '' );
			$pok = $pok && is_file( $f ) && (int) $p['n'] === (int) $jp[ $i ]['n'] && (int) $p['size'] === filesize( $f ) && $p['sha256'] === hash_file( 'sha256', $f ) && ! isset( $p['file'] );
		}
	}
	gs110ec_assert( $pok, 'manifest-parts', 'parts {db, users, content}: n, size, sha256 == the files (no staging file names)' );
	$raw = (string) file_get_contents( $path ); // phpcs:ignore
	gs110ec_assert( false === strpos( $raw, (string) $job['export_secret'] ) && false === strpos( $raw, (string) $job['staging'] ), 'manifest-no-secret', 'no export secret and no staging path in the manifest' );
	gs110ec_assert( ! empty( $m['excluded'] ) && isset( $m['excluded'][0]['path'], $m['excluded'][0]['reason'] ), 'manifest-excluded', 'excluded[] entries {path, reason} (' . count( (array) $m['excluded'] ) . ')' );
	$t = (array) ( $m['totals'] ?? array() );
	gs110ec_assert( (int) ( $t['content_bytes'] ?? 0 ) > 0 && (int) ( $t['files'] ?? 0 ) > 0 && (int) ( $t['db_bytes'] ?? 0 ) > 0, 'manifest-totals', 'totals filled ' . wp_json_encode( $t ) );
};

$S['watermark'] = function () {
	if ( ! gs110ec_ready( 'watermark' ) ) {
		return;
	}
	global $wpdb;
	list( $job, $facts ) = gs110ec_job_for( 'watermark' );
	$w = (array) ( $job['watermark'] ?? array() );
	foreach ( array( 'max_post_id', 'max_comment_id', 'max_order_id' ) as $k ) {
		gs110ec_assert( isset( $w[ $k ] ) && (int) $w[ $k ] === (int) $facts[ $k ], 'watermark-' . str_replace( '_', '-', $k ), $k . ' == the source DB at export start (' . (int) $facts[ $k ] . ')', wp_json_encode( $w[ $k ] ?? null ) );
	}
	gs110ec_assert( (string) ( $w['posts_modified_gmt'] ?? '' ) === (string) $facts['posts_modified_gmt'], 'watermark-modified', 'posts_modified_gmt == MAX(post_modified_gmt) at export start', (string) ( $w['posts_modified_gmt'] ?? '' ) );
	$mf = $job['manifest'] ?? null;
	$m  = is_array( $mf ) ? json_decode( (string) @file_get_contents( $job['staging'] . '/' . $mf['file'] ), true ) : null; // phpcs:ignore
	gs110ec_assert( is_array( $m ) && $w === ( $m['watermark'] ?? null ), 'watermark-manifest', 'the manifest carries the same watermark' );
	// A post made after the export is above the watermark (the 111 delta anchor).
	$id = wp_insert_post( array( 'post_title' => 'gs110ec after export', 'post_status' => 'draft' ) );
	gs110ec_on_cleanup(
		function () use ( $id ) {
			wp_delete_post( $id, true );
		}
	);
	gs110ec_assert( (int) $id > (int) ( $w['max_post_id'] ?? PHP_INT_MAX ), 'watermark-anchor', 'a later post is above max_post_id' );
};

$S['ready'] = function () {
	if ( ! gs110ec_ready( 'ready' ) ) {
		return;
	}
	list( $job ) = gs110ec_job_for( 'ready' );
	gs110ec_assert( 'ready' === ( $job['phase'] ?? '' ), 'ready-phase', 'the job phase is ready', (string) ( $job['phase'] ?? '' ) );
	$t = (array) ( $job['totals'] ?? array() );
	gs110ec_assert( (int) ( $t['db_bytes'] ?? 0 ) > 0 && (int) ( $t['rows'] ?? 0 ) > 0 && (int) ( $t['users_rows'] ?? 0 ) > 0 && (int) ( $t['content_bytes'] ?? 0 ) > 0 && (int) ( $t['files'] ?? 0 ) > 0, 'ready-totals', 'totals filled ' . wp_json_encode( $t ) );
	gs110ec_assert( function_exists( 'gend_society_migration_export_active' ) && ! gend_society_migration_export_active(), 'ready-inactive', 'the header tick loop stops (export_active false)' );
	$r = gend_society_migration_export_tick( 'ajax' );
	gs110ec_assert( 'idle' === $r['status'] && 'ready' === $r['phase'], 'ready-idle', 'a further tick does nothing', wp_json_encode( $r ) );
	gs110ec_assert( ! wp_next_scheduled( 'gend_society_migration_export_tick' ), 'ready-no-cron', 'no tick queued after ready' );
	$st = gend_society_migration_export_status();
	gs110ec_assert( 'ready' === ( $st['phase'] ?? '' ) && isset( $st['parts']['content'] ) && (int) $st['parts']['content'] > 0 && ! isset( $st['export_secret'] ), 'ready-status', 'status reports ready + the part counts, never the secret', wp_json_encode( $st ) );
	gs110ec_assert( null === ( $job['cur'] ?? null ), 'ready-closed', 'no part left open' );
};

// Helpers for the runner.
$S['export'] = function () {
	if ( ! gs110ec_ready( 'export' ) ) {
		return;
	}
	$facts = gs110ec_source_facts();
	gs110ec_start( true );
	$t0 = microtime( true );
	$r  = gs110ec_run( 20000 );
	update_option( GS110EC_KEPT, $facts, false );
	gs110ec_info( 'export: ' . round( microtime( true ) - $t0, 1 ) . ' s ' . wp_json_encode( $r ) );
	gs110ec_assert( 'done' === $r['status'] && 'ready' === $r['phase'], 'export', 'full DB + users + content + manifest export kept for the runner', wp_json_encode( $r ) );
};

$S['keep-info'] = function () {
	$job = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
	if ( ! gs110ec_assert( is_array( $job ) && ! empty( $job['staging'] ), 'keep-info', 'a kept export exists' ) ) {
		return;
	}
	$root  = gs110ec_root();
	$facts = (array) get_option( GS110EC_KEPT, array() );
	$start = (int) ( $facts['started_at'] ?? 0 );
	echo 'STAGING ' . $job['staging'] . "\n";
	echo 'PHASE ' . $job['phase'] . "\n";
	foreach ( (array) ( $job['parts']['content'] ?? array() ) as $p ) {
		echo 'CPART ' . $p['n'] . ' ' . $p['file'] . ' ' . $p['size'] . ' ' . $p['sha256'] . "\n";
	}
	if ( ! empty( $job['manifest']['file'] ) ) {
		echo 'MANIFEST ' . $job['manifest']['file'] . ' ' . $job['manifest']['size'] . ' ' . $job['manifest']['sha256'] . "\n";
	}
	foreach ( (array) gs110ec_index( $job ) as $e ) {
		if ( 'f' !== ( $e['t'] ?? '' ) ) {
			echo 'ENTRY' . "\t" . $e['t'] . "\t" . $e['p'] . "\n";
			continue;
		}
		$a = $root . '/' . $e['p'];
		clearstatcache( true, $a );
		if ( ! is_file( $a ) || (int) filemtime( $a ) >= $start ) {
			echo "CHANGED\t" . $e['p'] . "\n";
		} else {
			echo "SRCSHA\t" . hash_file( 'sha256', $a ) . "\t" . $e['p'] . "\n";
		}
	}
	foreach ( (array) ( $job['excluded'] ?? array() ) as $e ) {
		echo "EXCL\t" . ( $e['reason'] ?? '' ) . "\t" . ( $e['path'] ?? '' ) . "\n";
	}
};

$S['reset'] = function () {
	if ( function_exists( 'gend_society_migration_export_wipe' ) ) {
		gs110ec_reset_job();
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( GS110EC_KEPT );
	delete_option( 'gs110ec_longpaths' );
	gs110ec_assert( false === get_option( 'gend_society_migration_job', false ), 'reset', 'no job left' );
};
$GLOBALS['gs110ec_sections'] = $S;

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs110ec_want = array();
foreach ( isset( $args ) ? (array) $args : array() as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs110ec_want[] = trim( $s );
		}
	}
}
global $wpdb;
gs110ec_info( 'export-content on ' . home_url() . ' prefix=' . $wpdb->base_prefix . ' php=' . PHP_VERSION . ' sections=' . implode( ',', $gs110ec_want ) );
foreach ( $gs110ec_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs110ec_fail( $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs110ec_fail( $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs110ec_cleanup();
	}
}
echo ( 0 === (int) $GLOBALS['gs110ec']['fail'] && (int) $GLOBALS['gs110ec']['pass'] > 0 ) ? 'ALL PASS (' . (int) $GLOBALS['gs110ec']['pass'] . ")\n" : 'FAILURES: ' . (int) $GLOBALS['gs110ec']['fail'] . "\n";
