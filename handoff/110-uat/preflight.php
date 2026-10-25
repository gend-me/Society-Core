<?php
/**
 * Phase 110-09 battery: migration pre-flight (MIG-02 / M4), remote display mode, standalone Start Migration CTA (M7).
 *
 *   wp eval-file preflight.php <section>[,<section>...]
 *   (C:/tmp/livepull/phase110/tools/run-bed.sh | run-hub.sh C:/tmp/sc-110 handoff/110-uat/preflight.php <section> <log>)
 *
 * Sections (18): welcome-unchanged ids multisite disk limits woo conflicts cron-flag sizing planfit rest-external report
 *   consent remote-mode remote-frontend cta-standalone cta-hidden cta-hub-unchanged
 * Helper sections: pair / unpair (store / restore a fixture pairing so paired-only modules load at boot).
 *
 * Output: PASS:/FAIL:/INFO: lines; last line "ALL PASS (<n>)" or "FAILURES: <n>".
 * Baselines (welcome-unchanged, cta-hub-unchanged): wp-content/gs110pf-baselines.txt ("<key> <md5>"). A missing key is
 *   recorded (BASELINE-RECORDED line, run against main); a present key is compared. The runner keeps the master copy.
 * gend.me is stubbed through pre_http_request (only requests to the paired base URL are answered and recorded).
 * Fixtures are created and removed by each section (prefix gs110pf).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs110pf'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
	'http'    => array(),
);

function gs110pf_pass( $id, $t = '' ) {
	$GLOBALS['gs110pf']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110pf_fail( $id, $t = '' ) {
	$GLOBALS['gs110pf']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110pf_info( $t ) {
	echo 'INFO: ' . $t . "\n";
}
function gs110pf_assert( $c, $id, $t = '', $d = '' ) {
	$c ? gs110pf_pass( $id, $t ) : gs110pf_fail( $id, $t . ( '' !== $d ? ' -- ' . $d : '' ) );
	return (bool) $c;
}
function gs110pf_on_cleanup( callable $fn ) {
	$GLOBALS['gs110pf']['cleanup'][] = $fn;
}
function gs110pf_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs110pf']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs110pf_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs110pf']['cleanup'] = array();
}

/** Run $fn, restore every listed option to its exact previous state afterwards. */
function gs110pf_keep_options( array $names ) {
	$snap = array();
	foreach ( $names as $n ) {
		$snap[ $n ] = get_option( $n, '__gs110pf_absent__' );
	}
	gs110pf_on_cleanup(
		function () use ( $snap ) {
			foreach ( $snap as $n => $v ) {
				if ( '__gs110pf_absent__' === $v ) {
					delete_option( $n );
				} else {
					update_option( $n, $v, false );
				}
			}
		}
	);
}

const GS110PF_INSTALL_ID = '7c0a0b1e-1111-4111-8111-110000000009';
const GS110PF_TOKEN      = 'a110a110a110a110a110a110a110a110a110a110a110a110a110a110a110a110';
const GS110PF_BASE       = 'http://localhost:8919';
const GS110PF_MID        = 4242;
const GS110PF_MIGRATION_IDS = array( 'db_size', 'content_size', 'disk_free', 'max_execution_time', 'memory_limit', 'woocommerce', 'plugin_conflicts', 'plan_fit', 'estimate' );
const GS110PF_PAIR_OPTIONS  = array( 'gend_society_install_id', 'gend_society_install_token', 'gend_society_gend_base_url', 'gend_society_consent', 'gend_society_remote_consent', 'gend_society_remote_membership_cache', 'gend_society_remote_membership_cache_expires' );

// ---- baselines -------------------------------------------------------------------------------------------
function gs110pf_baseline_file() {
	return WP_CONTENT_DIR . '/gs110pf-baselines.txt';
}
function gs110pf_baseline_get( $key ) {
	$f = gs110pf_baseline_file();
	if ( ! is_readable( $f ) ) {
		return null;
	}
	foreach ( (array) file( $f, FILE_IGNORE_NEW_LINES ) as $line ) {
		$p = explode( ' ', trim( $line ), 2 );
		if ( 2 === count( $p ) && $p[0] === $key ) {
			return $p[1];
		}
	}
	return null;
}
/** Compare a value with its recorded baseline (record it when absent). */
function gs110pf_baseline_check( $key, $md5, $id, $text ) {
	$have = gs110pf_baseline_get( $key );
	if ( null === $have ) {
		file_put_contents( gs110pf_baseline_file(), $key . ' ' . $md5 . "\n", FILE_APPEND ); // phpcs:ignore
		echo 'BASELINE-RECORDED ' . $key . ' ' . $md5 . "\n";
		gs110pf_pass( $id, $text . ' (baseline recorded ' . substr( $md5, 0, 8 ) . ')' );
		return;
	}
	gs110pf_assert( $have === $md5, $id, $text . ' (baseline ' . substr( $have, 0, 8 ) . ')', 'now ' . $md5 );
}
/** Nonces, other per-tick tokens and relative times ("scanned 43 seconds ago") out of rendered HTML. */
function gs110pf_norm( $html ) {
	$html = preg_replace( '/\b[0-9a-f]{10}\b/', 'NONCE', (string) $html );
	$html = preg_replace( '/\b\d+ (?:second|sec|min|minute|hour|day|week|month|year)s? ago\b/', 'N ago', $html );
	return $html;
}

// ---- hub stub --------------------------------------------------------------------------------------------
/** Answer requests to the paired base URL with $answer( $url, $args ) (array [code, body-array]); record all of them. */
function gs110pf_stub( callable $answer ) {
	$GLOBALS['gs110pf']['http'] = array();
	$f                          = function ( $pre, $args, $url ) use ( $answer ) {
		$GLOBALS['gs110pf']['http'][] = array(
			'url'     => $url,
			'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => $args['body'] ?? '',
			'timeout' => $args['timeout'] ?? null,
		);
		if ( 0 !== strpos( $url, GS110PF_BASE . '/' ) ) {
			return $pre;
		}
		list( $code, $body ) = $answer( $url, $args );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $f, 1, 3 );
	gs110pf_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, 1 );
		}
	);
}
function gs110pf_hub_calls() {
	return array_values(
		array_filter(
			$GLOBALS['gs110pf']['http'],
			function ( $r ) {
				return 0 === strpos( $r['url'], GS110PF_BASE . '/' );
			}
		)
	);
}

/** Pairing options for this process (restored on cleanup). */
function gs110pf_set_paired() {
	gs110pf_keep_options( GS110PF_PAIR_OPTIONS );
	update_option( 'gend_society_install_id', GS110PF_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS110PF_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS110PF_BASE, false );
	update_option(
		'gend_society_consent',
		array(
			'at'      => time(),
			'user_id' => 1,
			'source'  => 'gs110pf',
		),
		false
	);
}

/** The cached /membership payload the dashboard reads (restored on cleanup by gs110pf_set_paired()). */
function gs110pf_set_payload( array $over = array() ) {
	$p = array_replace_recursive(
		array(
			'membership_id'  => GS110PF_MID,
			'membership_url' => GS110PF_BASE . '/my-account/membership/' . GS110PF_MID . '/',
			'hub_url'        => GS110PF_BASE . '/',
			'status'         => 'active',
			'migration'      => array(
				'stage' => '',
				'index' => 0,
				'live'  => false,
				'steps' => array(),
			),
		),
		$over
	);
	update_option( 'gend_society_remote_membership_cache', $p, false );
	update_option( 'gend_society_remote_membership_cache_expires', time() + 3600, false );
	return $p;
}

function gs110pf_ids( array $result ) {
	return array_map(
		function ( $c ) {
			return (string) ( $c['id'] ?? '' );
		},
		(array) ( $result['checks'] ?? array() )
	);
}
function gs110pf_item( array $result, $id ) {
	foreach ( (array) ( $result['checks'] ?? array() ) as $c ) {
		if ( is_array( $c ) && ( $c['id'] ?? '' ) === $id ) {
			return $c;
		}
	}
	return null;
}
function gs110pf_collect_migration() {
	return gend_society_readiness_collect( 'migration' );
}

/** Run the sizing walk to completion (fresh). Returns the last step result or null when the function is missing. */
function gs110pf_size_all( $budget = 15.0 ) {
	if ( ! function_exists( 'gend_society_migration_sizing_step' ) ) {
		return null;
	}
	delete_option( 'gend_society_migration_sizing' );
	$r = array();
	for ( $i = 0; $i < 200; $i++ ) {
		$r = gend_society_migration_sizing_step( $budget );
		if ( ! empty( $r['done'] ) ) {
			break;
		}
	}
	return $r;
}

function gs110pf_render( callable $fn ) {
	ob_start();
	try {
		$fn();
	} finally {
		$html = ob_get_clean();
	}
	return (string) $html;
}

/** A fixed migration-context result (for render tests). */
function gs110pf_fixed_migration_result() {
	$items = array(
		array( 'php', 'PHP version', 'pass', 'PHP 8.2.0', false ),
		array( 'sodium', 'Sodium extension', 'pass', 'Available', false ),
		array( 'rest', 'REST API', 'pass', 'Reachable', false ),
		array( 'loopback', 'Loopback requests', 'pass', 'Working', false ),
		array( 'basic_auth', 'Password protection', 'pass', 'No basic auth', false ),
		array( 'public_dns', 'Public address', 'pass', '203.0.113.7', false ),
		array( 'db_size', 'Database size', 'pass', '12 MB in 14 tables', false ),
		array( 'content_size', 'Files size', 'pass', '1.2 GB in 4,210 files', false ),
		array( 'disk_free', 'Free disk space', 'fail', 'Needs 1.5 GB free, 1.1 GB free', true ),
		array( 'max_execution_time', 'PHP time limit', 'warn', '10 s: the export adapts', false ),
		array( 'memory_limit', 'PHP memory limit', 'pass', '256M', false ),
		array( 'woocommerce', 'WooCommerce', 'warn', 'Orders placed after the export starts are not copied.', false ),
		array( 'plugin_conflicts', 'Host-specific code', 'warn', 'object-cache.php', false ),
		array( 'plan_fit', 'Plan fit', 'info', 'Needs about 1.2 GB', false ),
		array( 'estimate', 'Time estimate', 'info', 'About 2 minutes', false ),
	);
	$checks = array();
	foreach ( $items as $i ) {
		$checks[] = array(
			'id'      => $i[0],
			'label'   => $i[1],
			'status'  => $i[2],
			'message' => $i[3],
			'blocker' => $i[4],
		);
	}
	return array(
		'checked_at' => 1760000000,
		'context'    => 'migration',
		'checks'     => $checks,
		'totals'     => array(
			'db_bytes'         => 12000000,
			'content_bytes'    => 1200000000,
			'files'            => 4210,
			'largest'          => 52428800,
			'est_seconds'      => 120,
			'wp_cron_disabled' => false,
		),
	);
}

function gs110pf_fixed_welcome_result() {
	$r = gs110pf_fixed_migration_result();
	return array(
		'checked_at' => $r['checked_at'],
		'checks'     => array_slice( $r['checks'], 0, 6 ),
	);
}

/** Write a file under wp-content (removed on cleanup). */
function gs110pf_put( $rel, $bytes ) {
	$abs = WP_CONTENT_DIR . '/' . $rel;
	$new = array();
	$d   = dirname( $abs );
	while ( ! is_dir( $d ) ) {
		$new[] = $d;
		$d     = dirname( $d );
	}
	wp_mkdir_p( dirname( $abs ) );
	file_put_contents( $abs, is_int( $bytes ) ? str_repeat( 'x', $bytes ) : $bytes ); // phpcs:ignore
	gs110pf_on_cleanup(
		function () use ( $abs, $new ) {
			if ( is_file( $abs ) ) {
				unlink( $abs ); // phpcs:ignore
			}
			foreach ( $new as $dir ) {
				if ( is_dir( $dir ) ) {
					rmdir( $dir ); // phpcs:ignore
				}
			}
		}
	);
}

// ---- independent sizing oracle (the M1 list, written out again here) ---------------------------------------
function gs110pf_oracle_excluded( $rel, $is_dir ) {
	$parts = explode( '/', $rel );
	$top   = $parts[0];
	if ( 1 === count( $parts ) ) {
		if ( $is_dir ) {
			foreach ( array( 'cache', 'upgrade', 'backups*', 'ai1wm-backups', 'updraft', 'wflogs', 'et-cache', 'litespeed', 'w3tc-config', 'wp-rocket-config', 'gend-society-migration-*' ) as $p ) {
				if ( fnmatch( $p, $top ) ) {
					return true;
				}
			}
			return false;
		}
		return in_array( $top, array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'sunrise.php' ), true );
	}
	if ( 2 === count( $parts ) ) {
		if ( 'uploads' === $top && 'backup-guard' === $parts[1] && $is_dir ) {
			return true;
		}
		if ( 'mu-plugins' === $top ) {
			foreach ( array( 'endurance-*', 'bluehost-*', 'sso.php', 'wpengine-common*', 'mu-plugin.php', 'slt-force-strong-passwords*', 'force-strong-passwords*', 'gd-system-plugin*', 'kinsta-mu-plugins*', 'wpcomsh*', 'sg-*', 'pantheon*', 'flywheel*' ) as $p ) {
				if ( fnmatch( $p, $parts[1] ) ) {
					return true;
				}
			}
		}
		if ( 'plugins' === $top ) {
			$name = preg_replace( '/\.php$/', '', $parts[1] );
			foreach ( array( 'gend-society*', 'sg-cachepress', 'sg-security', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'wp-rocket', 'breeze', 'endurance-page-cache', 'mojo-marketplace-wp-plugin', 'bluehost-wordpress-plugin', 'wp-plugin-bluehost', 'gd-system-plugin', 'wpengine-*' ) as $p ) {
				if ( fnmatch( $p, $name ) ) {
					return true;
				}
			}
		}
	}
	return false;
}
function gs110pf_oracle() {
	$root  = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
	$files = 0;
	$bytes = 0;
	$big   = 0;
	$stack = array( '' );
	while ( $stack ) {
		$rel = array_pop( $stack );
		$abs = '' === $rel ? $root : $root . '/' . $rel;
		$dh  = opendir( $abs );
		if ( ! $dh ) {
			continue;
		}
		while ( false !== ( $e = readdir( $dh ) ) ) {
			if ( '.' === $e || '..' === $e ) {
				continue;
			}
			$r = '' === $rel ? $e : $rel . '/' . $e;
			$a = $root . '/' . $r;
			if ( is_link( $a ) ) {
				continue;
			}
			if ( is_dir( $a ) ) {
				if ( ! gs110pf_oracle_excluded( $r, true ) ) {
					$stack[] = $r;
				}
				continue;
			}
			if ( ! is_file( $a ) || gs110pf_oracle_excluded( $r, false ) ) {
				continue;
			}
			$s = filesize( $a );
			if ( $s > 2147483648 ) {
				$big++;
				continue;
			}
			$files++;
			$bytes += $s;
		}
		closedir( $dh );
	}
	return array(
		'files' => $files,
		'bytes' => $bytes,
		'big'   => $big,
	);
}
function gs110pf_oracle_db( array $other_prefixes ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, (DATA_LENGTH + INDEX_LENGTH) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A ); // phpcs:ignore
	$sum  = 0;
	foreach ( (array) $rows as $row ) {
		foreach ( $other_prefixes as $op ) {
			if ( 0 === strpos( $row['t'], $op ) ) {
				continue 2;
			}
		}
		$sum += (int) $row['b'];
	}
	return $sum;
}

// ---------------------------------------------------------------------------------------------------------
$gs110pf_sections = array();

$gs110pf_sections['pair'] = function () {
	if ( false === get_option( 'gs110pf_pair_snapshot', false ) ) {
		$snap = array();
		foreach ( GS110PF_PAIR_OPTIONS as $n ) {
			$snap[ $n ] = get_option( $n, '__gs110pf_absent__' );
		}
		update_option( 'gs110pf_pair_snapshot', $snap, false );
	}
	update_option( 'gend_society_install_id', GS110PF_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS110PF_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS110PF_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs110pf' ), false );
	gs110pf_set_payload();
	gs110pf_pass( 'pair', 'fixture pairing stored (snapshot kept in gs110pf_pair_snapshot)' );
};

$gs110pf_sections['unpair'] = function () {
	$snap = get_option( 'gs110pf_pair_snapshot', false );
	if ( ! is_array( $snap ) ) {
		gs110pf_pass( 'unpair', 'nothing to restore' );
		return;
	}
	foreach ( $snap as $n => $v ) {
		if ( '__gs110pf_absent__' === $v ) {
			delete_option( $n );
		} else {
			update_option( $n, $v, false );
		}
	}
	delete_option( 'gs110pf_pair_snapshot' );
	gs110pf_assert( '' === (string) get_option( 'gend_society_install_token', '' ) || GS110PF_TOKEN !== get_option( 'gend_society_install_token' ), 'unpair', 'pairing restored to its pre-battery state' );
};

$gs110pf_sections['welcome-unchanged'] = function () {
	$mode = function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?';
	$tag  = $mode . ( is_multisite() ? '-ms' : '' );
	// The rest / loopback / basic_auth checks request this site itself; a fixed answer makes collect() comparable
	// across runs (their code is unchanged; only the environment's live answers vary).
	$self = function ( $pre, $args, $url ) {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $h ) || ! in_array( strtolower( $h ), gend_society_readiness_self_hosts(), true ) ) {
			return $pre;
		}
		return array( 'headers' => array(), 'body' => '{"namespaces":["wp/v2"]}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $self, 0, 3 );
	gs110pf_on_cleanup(
		function () use ( $self ) {
			remove_filter( 'pre_http_request', $self, 0 );
		}
	);
	$r    = gend_society_readiness_collect();
	$sig  = array_map(
		function ( $c ) {
			return array( $c['id'], $c['status'], (bool) $c['blocker'] );
		},
		$r['checks']
	);
	gs110pf_assert( array( 'checked_at', 'checks' ) === array_keys( $r ), 'welcome-shape', 'collect() result keys = checked_at, checks' );
	gs110pf_info( 'collect() = ' . wp_json_encode( $sig ) );
	gs110pf_baseline_check( 'collect-' . $tag, md5( wp_json_encode( $sig ) ), 'welcome-collect', "collect() ids/statuses/blockers equal main ({$tag})" );
	$r2 = gend_society_readiness_collect( 'welcome' );
	gs110pf_assert( gs110pf_ids( $r2 ) === gs110pf_ids( $r ), 'welcome-explicit', "collect('welcome') = collect()" );
	$fixed = gs110pf_fixed_welcome_result();
	foreach ( array( 'admin' => 1, 'anon' => 0 ) as $who => $uid ) {
		wp_set_current_user( $uid );
		$html = gs110pf_norm( gs110pf_render( function () use ( $fixed ) {
			gend_society_readiness_render_card( $fixed );
		} ) );
		gs110pf_baseline_check( "card-{$tag}-{$who}", md5( $html ), "welcome-card-{$who}", "render_card(fixed) local HTML equals main ({$who})" );
	}
	wp_set_current_user( 1 );
	$html = gs110pf_norm( gs110pf_render( function () use ( $fixed ) {
		gend_society_readiness_render_card( $fixed, 'welcome', 'local' );
	} ) );
	gs110pf_baseline_check( "card-{$tag}-admin", md5( $html ), 'welcome-card-explicit', "render_card(fixed, 'welcome', 'local') equals main" );
	$before = md5( serialize( get_option( 'gend_society_readiness_last', null ) ) );
	if ( function_exists( 'gend_society_migration_sizing_step' ) ) {
		gs110pf_keep_options( array( 'gend_society_readiness_last_migration', 'gend_society_migration_sizing' ) );
		gend_society_readiness_run( true, 'migration' );
		gs110pf_assert( md5( serialize( get_option( 'gend_society_readiness_last', null ) ) ) === $before, 'welcome-cache-separate', 'a migration run leaves the welcome cache option untouched' );
	} else {
		gs110pf_info( 'migration module not loaded here (' . $tag . '): cache separation not checked' );
	}
};

$gs110pf_sections['ids'] = function () {
	gs110pf_keep_options( array( 'gend_society_readiness_last_migration', 'gend_society_migration_sizing' ) );
	$base = gs110pf_ids( gend_society_readiness_collect( 'welcome' ) );
	gs110pf_size_all();
	$r    = gs110pf_collect_migration();
	gs110pf_assert( array_merge( $base, GS110PF_MIGRATION_IDS, array( 'staging_exposed' ) ) === gs110pf_ids( $r ), 'ids-order', "collect('migration') ids = 106 ids + db_size..estimate in order + staging_exposed (110-15)", wp_json_encode( gs110pf_ids( $r ) ) );
	$shape = true;
	foreach ( (array) $r['checks'] as $c ) {
		$shape = $shape && is_array( $c ) && array( 'id', 'label', 'status', 'message', 'blocker' ) === array_keys( $c ) && in_array( $c['status'], array( 'pass', 'warn', 'fail', 'info' ), true ) && is_bool( $c['blocker'] );
	}
	gs110pf_assert( $shape, 'ids-item-shape', 'every item {id,label,status,message,blocker}, status pass|warn|fail|info' );
	gs110pf_assert( 'migration' === ( $r['context'] ?? '' ), 'ids-context', "result context = 'migration'" );
	$tk = array_keys( (array) ( $r['totals'] ?? array() ) );
	gs110pf_assert( array( 'db_bytes', 'content_bytes', 'files', 'largest', 'est_seconds', 'wp_cron_disabled' ) === $tk, 'ids-totals', 'totals keys db_bytes, content_bytes, files, largest, est_seconds, wp_cron_disabled', wp_json_encode( $tk ) );
	gs110pf_info( 'migration items: ' . wp_json_encode( array_slice( $r['checks'], count( $base ) ) ) );
	$run = gend_society_readiness_run( true, 'migration' );
	$opt = get_option( 'gend_society_readiness_last_migration', null );
	gs110pf_assert( is_array( $opt ) && gs110pf_ids( $opt ) === gs110pf_ids( $run ), 'ids-cache', "run(true,'migration') caches in gend_society_readiness_last_migration" );
	$again = gend_society_readiness_run( false, 'migration' );
	gs110pf_assert( (int) $again['checked_at'] === (int) $run['checked_at'], 'ids-cache-ttl', 'a second run within the TTL returns the cached result' );
	$blockers = gend_society_readiness_blockers( $r );
	gs110pf_info( 'blockers on this bed: ' . implode( ',', gs110pf_ids( array( 'checks' => $blockers ) ) ) );
};

$gs110pf_sections['multisite'] = function () {
	if ( ! gs110pf_assert( is_multisite(), 'ms-bed', 'running on the multisite bed' ) ) {
		return;
	}
	$w = gs110pf_item( gend_society_readiness_collect( 'welcome' ), 'multisite' );
	gs110pf_assert( is_array( $w ) && false === $w['blocker'] && 'warn' === $w['status'], 'ms-welcome', 'welcome: multisite = warn, blocker false (106 semantics)', wp_json_encode( $w ) );
	$m = gs110pf_item( gs110pf_collect_migration(), 'multisite' );
	gs110pf_assert( is_array( $m ) && true === $m['blocker'], 'ms-migration', 'migration: multisite blocker true', wp_json_encode( $m ) );
	gs110pf_assert( is_array( $m ) && 'fail' === $m['status'], 'ms-migration-fail', 'migration: multisite status fail' );
};

$gs110pf_sections['disk'] = function () {
	gs110pf_keep_options( array( 'gend_society_migration_sizing' ) );
	gs110pf_size_all();
	$r0 = gs110pf_collect_migration();
	$t  = (array) ( $r0['totals'] ?? array() );
	$x  = (int) ( $t['db_bytes'] ?? 0 ) + (int) ( $t['content_bytes'] ?? 0 );
	gs110pf_assert( $x > 0, 'disk-export-size', 'export size known (db + content > 0)', (string) $x );
	foreach ( array( '1.1x' => array( 1.1, 'fail', true ), '1.3x' => array( 1.3, 'pass', false ), 'false' => array( false, 'warn', false ) ) as $k => $case ) {
		$f = function () use ( $case, $x ) {
			return false === $case[0] ? false : (float) ( $case[0] * $x );
		};
		add_filter( 'gend_society_migration_disk_free', $f );
		$d = gs110pf_item( gs110pf_collect_migration(), 'disk_free' );
		remove_filter( 'gend_society_migration_disk_free', $f );
		gs110pf_assert( is_array( $d ) && $case[1] === $d['status'] && $case[2] === $d['blocker'], "disk-{$k}", "free = {$k} of the export -> {$case[1]}" . ( $case[2] ? ' + blocker' : '' ), wp_json_encode( $d ) );
	}
};

$gs110pf_sections['limits'] = function () {
	$cases = array(
		array( 'max_execution_time', '10', 'warn', false ),
		array( 'max_execution_time', '0', 'pass', false ),
		array( 'max_execution_time', '30', 'pass', false ),
		array( 'memory_limit', '48M', 'fail', true ),
		array( 'memory_limit', '128M', 'pass', false ),
		array( 'memory_limit', '-1', 'pass', false ),
	);
	foreach ( $cases as $c ) {
		$f = function ( $v, $key ) use ( $c ) {
			return $key === $c[0] ? $c[1] : $v;
		};
		add_filter( 'gend_society_migration_ini', $f, 10, 2 );
		$i = gs110pf_item( gs110pf_collect_migration(), $c[0] );
		remove_filter( 'gend_society_migration_ini', $f, 10 );
		gs110pf_assert( is_array( $i ) && $c[2] === $i['status'] && $c[3] === $i['blocker'], "limits-{$c[0]}-{$c[1]}", "{$c[0]}={$c[1]} -> {$c[2]}" . ( $c[3] ? ' + blocker' : '' ), wp_json_encode( $i ) );
	}
};

$gs110pf_sections['woo'] = function () {
	add_filter( 'gend_society_migration_woocommerce_active', '__return_true' );
	$w = gs110pf_item( gs110pf_collect_migration(), 'woocommerce' );
	remove_filter( 'gend_society_migration_woocommerce_active', '__return_true' );
	gs110pf_assert( is_array( $w ) && 'warn' === $w['status'] && false === $w['blocker'], 'woo-active-warn', 'WooCommerce active -> warn (not a blocker)', wp_json_encode( $w ) );
	gs110pf_assert( is_array( $w ) && false !== stripos( $w['message'], 'orders placed after the export start' ), 'woo-freeze-copy', 'message names orders placed after the export start (content freeze)' );
	add_filter( 'gend_society_migration_woocommerce_active', '__return_false' );
	$w = gs110pf_item( gs110pf_collect_migration(), 'woocommerce' );
	remove_filter( 'gend_society_migration_woocommerce_active', '__return_false' );
	gs110pf_assert( is_array( $w ) && 'pass' === $w['status'], 'woo-inactive-pass', 'not active -> pass' );
};

$gs110pf_sections['conflicts'] = function () {
	$c0 = gs110pf_item( gs110pf_collect_migration(), 'plugin_conflicts' );
	gs110pf_info( 'before fixtures: ' . wp_json_encode( $c0 ) );
	gs110pf_put( 'object-cache.php', "<?php\n// gs110pf test fixture (110-04 corpus name): no cache functions, WordPress falls back to its own cache.\n" );
	gs110pf_put( 'mu-plugins/endurance-page-cache.php', "<?php\n// gs110pf test fixture (110-04 corpus name only).\n" );
	$c = gs110pf_item( gs110pf_collect_migration(), 'plugin_conflicts' );
	gs110pf_assert( is_array( $c ) && 'warn' === $c['status'] && false === $c['blocker'], 'conflicts-warn', 'host drop-in + host mu-plugin present -> warn', wp_json_encode( $c ) );
	gs110pf_assert( is_array( $c ) && false !== strpos( $c['message'], 'object-cache.php' ) && false !== strpos( $c['message'], 'endurance-page-cache.php' ), 'conflicts-lists-both', 'message lists object-cache.php and mu-plugins/endurance-page-cache.php' );
	gs110pf_assert( is_array( $c ) && false === stripos( $c['message'], 'gend-society' ), 'conflicts-not-self', 'the installed gend-society is not listed as a conflict' );
	$ex = function_exists( 'gend_society_migration_excludes' ) ? gend_society_migration_excludes() : array();
	gs110pf_assert( array( 'dirs', 'dropins', 'mu_plugins', 'plugins', 'max_file_bytes' ) === array_keys( $ex ) && 2147483648 === ( $ex['max_file_bytes'] ?? 0 ), 'conflicts-excludes-shape', 'gend_society_migration_excludes() = dirs, dropins, mu_plugins, plugins, max_file_bytes (2 GB)', wp_json_encode( array_keys( $ex ) ) );
	gs110pf_assert( in_array( 'object-cache.php', (array) ( $ex['dropins'] ?? array() ), true ) && in_array( 'endurance-*', (array) ( $ex['mu_plugins'] ?? array() ), true ) && in_array( 'gend-society*', (array) ( $ex['plugins'] ?? array() ), true ) && in_array( 'uploads/backup-guard', (array) ( $ex['dirs'] ?? array() ), true ), 'conflicts-excludes-list', 'the single M1 list carries the plan entries' );
};

$gs110pf_sections['cron-flag'] = function () {
	gs110pf_keep_options( array( 'gend_society_migration_sizing' ) );
	if ( ! defined( 'DISABLE_WP_CRON' ) ) {
		define( 'DISABLE_WP_CRON', true );
		gs110pf_info( 'DISABLE_WP_CRON defined in this process' );
	}
	gs110pf_size_all();
	$r = gs110pf_collect_migration();
	gs110pf_assert( true === ( $r['totals']['wp_cron_disabled'] ?? null ), 'cron-flag', 'DISABLE_WP_CRON -> totals.wp_cron_disabled = true', wp_json_encode( $r['totals'] ?? null ) );
};

$gs110pf_sections['sizing'] = function () {
	global $wpdb;
	gs110pf_keep_options( array( 'gend_society_migration_sizing' ) );
	// fixtures: included files, excluded dirs/files, another install's tables in the same DB (Pitfall 7)
	gs110pf_put( 'uploads/gs110pf/2026/10/a.jpg', 4096 );
	gs110pf_put( 'uploads/gs110pf/' . str_repeat( 'long-folder-name-', 7 ) . '/b.bin', 1000 );
	gs110pf_put( 'themes/gs110pf-theme/style.css', "/* Theme Name: gs110pf */\n" );
	gs110pf_put( 'cache/gs110pf-c.html', 500 );
	gs110pf_put( 'upgrade/gs110pf-u.zip', 500 );
	gs110pf_put( 'backups-gs110pf/x.zip', 500 );
	gs110pf_put( 'ai1wm-backups/x.wpress', 500 );
	gs110pf_put( 'uploads/backup-guard/x.sgbp', 500 );
	gs110pf_put( 'gend-society-migration-gs110pf/part-1.gz', 500 );
	gs110pf_put( 'plugins/litespeed-cache/litespeed-cache.php', "<?php\n" );
	gs110pf_put( 'mu-plugins/sso.php', "<?php\n// gs110pf test fixture\n" );
	gs110pf_put( 'advanced-cache.php', "<?php\n// gs110pf test fixture\n" );
	$p     = $wpdb->base_prefix;
	$other = $p . 'gs110pfo_';
	foreach ( array( 'options', 'posts' ) as $t ) {
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$other}{$t}` (id INT PRIMARY KEY, v LONGTEXT) ENGINE=InnoDB" ); // phpcs:ignore
		$wpdb->query( "INSERT IGNORE INTO `{$other}{$t}` VALUES (1, REPEAT('z', 200000))" ); // phpcs:ignore
	}
	$wpdb->query( "ANALYZE TABLE `{$other}options`, `{$other}posts`" ); // phpcs:ignore
	gs110pf_on_cleanup(
		function () use ( $wpdb, $other ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$other}options`, `{$other}posts`" ); // phpcs:ignore
		}
	);
	if ( ! gs110pf_assert( function_exists( 'gend_society_migration_sizing_step' ), 'sizing-fn', 'gend_society_migration_sizing_step() exists' ) ) {
		return;
	}
	$want = gs110pf_oracle();
	// resumable: force small steps through the entry seam, then compare with one free-running walk
	$cap = function () {
		return 200;
	};
	add_filter( 'gend_society_migration_sizing_max_entries', $cap );
	delete_option( 'gend_society_migration_sizing' );
	$steps = 0;
	$slow  = 0.0;
	$r     = array();
	for ( $i = 0; $i < 2000; $i++ ) {
		$t0 = microtime( true );
		$r  = gend_society_migration_sizing_step( 15.0 );
		$slow = max( $slow, microtime( true ) - $t0 );
		$steps++;
		if ( ! empty( $r['done'] ) ) {
			break;
		}
		$c = get_option( 'gend_society_migration_sizing', null );
		if ( 1 === $steps ) {
			gs110pf_assert( is_array( $c ) && empty( $c['done'] ), 'sizing-cursor', 'an unfinished walk keeps its cursor in option gend_society_migration_sizing' );
		}
	}
	remove_filter( 'gend_society_migration_sizing_max_entries', $cap );
	gs110pf_assert( ! empty( $r['done'] ) && $steps > 1, 'sizing-resumes', "capped walk finishes over {$steps} steps (resumable)" );
	gs110pf_assert( (int) $r['files'] === $want['files'], 'sizing-files', 'files = wp-content files minus M1 excludes (oracle ' . $want['files'] . ')', 'got ' . (int) ( $r['files'] ?? -1 ) );
	gs110pf_assert( (int) $r['content_bytes'] === $want['bytes'], 'sizing-bytes', 'content_bytes = oracle (' . $want['bytes'] . ')', 'got ' . (int) ( $r['content_bytes'] ?? -1 ) );
	gs110pf_assert( $slow < 20.0, 'sizing-step-time', sprintf( 'each step < 20 s (slowest %.2f s)', $slow ) );
	$free = gs110pf_size_all( 15.0 );
	gs110pf_assert( is_array( $free ) && (int) $free['files'] === $want['files'] && (int) $free['content_bytes'] === $want['bytes'], 'sizing-uncapped', 'a free-running walk gives the same totals' );
	gs110pf_assert( (int) ( $free['largest'] ?? 0 ) >= 4096, 'sizing-largest', 'largest file bytes reported' );
	$db = gs110pf_oracle_db( array( $other ) );
	gs110pf_assert( (int) ( $free['db_bytes'] ?? -1 ) === $db, 'sizing-db-prefix', "db_bytes = exact-prefix tables, another install's {$other}* tables excluded ({$db})", 'got ' . (int) ( $free['db_bytes'] ?? -1 ) );
	$withother = gs110pf_oracle_db( array() );
	gs110pf_assert( $withother > $db, 'sizing-db-other-exists', 'the other install tables really are in the DB (' . $withother . ' > ' . $db . ')' );
	gs110pf_assert( 0 === strpos( wp_json_encode( array_keys( (array) $free ) ), '["done"' ) && isset( $free['cursor'] ), 'sizing-shape', 'step result {done, db_bytes, content_bytes, files, largest, cursor}', wp_json_encode( array_keys( (array) $free ) ) );
};

$gs110pf_sections['planfit'] = function () {
	gs110pf_set_paired();
	gs110pf_keep_options( array( 'gend_society_migration_preflight_answer', 'gend_society_readiness_last_migration', 'gend_society_migration_sizing' ) );
	delete_option( 'gend_society_migration_preflight_answer' );
	gs110pf_size_all();
	$pf = gs110pf_item( gs110pf_collect_migration(), 'plan_fit' );
	gs110pf_assert( is_array( $pf ) && 'info' === $pf['status'] && false === $pf['blocker'] && (bool) preg_match( '/\d/', $pf['message'] ), 'planfit-no-answer', 'no hub answer -> info with the needed sizes', wp_json_encode( $pf ) );
	gs110pf_stub(
		function () {
			return array( 200, array( 'stored' => true, 'plan_fit' => array( 'status' => 'fail', 'need' => array( 'code_gb' => 12, 'db_gb' => 1 ), 'have' => array( 'code_gb' => 5, 'db_gb' => 1 ), 'suggest' => array( 'plan_id' => 72, 'name' => 'Storage 25 GB' ) ), 'rest_external' => array( 'status' => 'pass', 'message' => '' ) ) );
		}
	);
	$res = function_exists( 'gend_society_migration_preflight_report' ) ? gend_society_migration_preflight_report( gs110pf_collect_migration() ) : new WP_Error( 'missing', 'gend_society_migration_preflight_report() missing' );
	gs110pf_assert( is_array( $res ), 'planfit-report', 'report answered', is_wp_error( $res ) ? $res->get_error_message() : '' );
	$pf = gs110pf_item( gs110pf_collect_migration(), 'plan_fit' );
	gs110pf_assert( is_array( $pf ) && 'fail' === $pf['status'] && true === $pf['blocker'], 'planfit-fail-blocker', 'hub plan_fit fail -> blocker', wp_json_encode( $pf ) );
	gs110pf_assert( is_array( $pf ) && false !== strpos( $pf['message'], 'Storage 25 GB' ) && false !== stripos( $pf['message'], 'Change Plan' ), 'planfit-suggest', 'message names the suggested plan and Change Plan' );
	$stored = get_option( 'gend_society_readiness_last_migration', null );
	$spf    = is_array( $stored ) ? gs110pf_item( $stored, 'plan_fit' ) : null;
	gs110pf_assert( is_array( $spf ) && 'fail' === $spf['status'], 'planfit-stored', 'the stored migration result carries the hub plan_fit' );
};

$gs110pf_sections['rest-external'] = function () {
	gs110pf_set_paired();
	gs110pf_keep_options( array( 'gend_society_migration_preflight_answer', 'gend_society_readiness_last_migration', 'gend_society_migration_sizing' ) );
	delete_option( 'gend_society_migration_preflight_answer' );
	gs110pf_size_all();
	gs110pf_assert( null === gs110pf_item( gs110pf_collect_migration(), 'rest_external' ), 'rest-ext-absent', 'no report yet -> no rest_external item' );
	foreach ( array( 'fail' => true, 'pass' => false ) as $st => $blk ) {
		gs110pf_stub(
			function () use ( $st ) {
				return array( 200, array( 'stored' => true, 'plan_fit' => array( 'status' => 'info', 'need' => array( 'code_gb' => 1, 'db_gb' => 1 ), 'have' => null, 'suggest' => null ), 'rest_external' => array( 'status' => $st, 'message' => 'fail' === $st ? 'HTTP 403 from your REST root' : '' ) ) );
			}
		);
		$res = function_exists( 'gend_society_migration_preflight_report' ) ? gend_society_migration_preflight_report( gs110pf_collect_migration() ) : null;
		gs110pf_cleanup_stub_only();
		$r   = gs110pf_collect_migration();
		$ids = gs110pf_ids( $r );
		$re  = gs110pf_item( $r, 'rest_external' );
		gs110pf_assert( is_array( $res ), "rest-ext-{$st}-report", 'report answered' );
		gs110pf_assert( is_array( $re ) && $st === $re['status'] && $blk === $re['blocker'], "rest-ext-{$st}", "hub rest_external {$st} -> item {$st}" . ( $blk ? ' + blocker' : '' ), wp_json_encode( $re ) );
		$ie = array_search( 'estimate', $ids, true );
		gs110pf_assert( false !== $ie && ( $ids[ $ie + 1 ] ?? '' ) === 'rest_external', "rest-ext-{$st}-order", 'rest_external comes right after estimate' );
	}
};

/** Remove only the pre_http_request stubs registered so far (keep other cleanups). */
function gs110pf_cleanup_stub_only() {
	remove_all_filters( 'pre_http_request', 1 );
}

$gs110pf_sections['report'] = function () {
	gs110pf_set_paired();
	gs110pf_keep_options( array( 'gend_society_migration_preflight_answer', 'gend_society_readiness_last_migration', 'gend_society_migration_sizing' ) );
	gs110pf_size_all();
	gs110pf_stub(
		function () {
			return array( 200, array( 'stored' => true, 'plan_fit' => array( 'status' => 'pass', 'need' => array( 'code_gb' => 1, 'db_gb' => 1 ), 'have' => array( 'code_gb' => 25, 'db_gb' => 5 ), 'suggest' => null ), 'rest_external' => array( 'status' => 'pass', 'message' => '' ) ) );
		}
	);
	$r   = gs110pf_collect_migration();
	$res = function_exists( 'gend_society_migration_preflight_report' ) ? gend_society_migration_preflight_report( $r ) : new WP_Error( 'missing', 'missing' );
	$hub = gs110pf_hub_calls();
	gs110pf_assert( is_array( $res ) && 1 === count( $hub ), 'report-one-post', 'connected + consent -> exactly one request to gend.me', count( $hub ) . ' ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
	$c = $hub[0] ?? array();
	gs110pf_assert( ( $c['url'] ?? '' ) === GS110PF_BASE . '/wp-json/gdc-app-manager/v1/install/' . GS110PF_INSTALL_ID . '/migration/preflight' && 'POST' === ( $c['method'] ?? '' ), 'report-url', 'POST .../install/<id>/migration/preflight', wp_json_encode( array( $c['url'] ?? '', $c['method'] ?? '' ) ) );
	$h = array_change_key_case( (array) ( $c['headers'] ?? array() ) );
	gs110pf_assert( ( $h['authorization'] ?? '' ) === 'Bearer ' . GS110PF_TOKEN && ( $h['content-type'] ?? '' ) === 'application/json' && 20 === (int) ( $c['timeout'] ?? 0 ), 'report-client', 'bearer install token, JSON, 20 s timeout (gend_society_remote_membership_call shape)' );
	$b = json_decode( (string) ( $c['body'] ?? '' ), true );
	gs110pf_assert( is_array( $b ) && array( 'format', 'context', 'checked_at', 'checks', 'totals', 'hash' ) === array_keys( $b ) && 1 === $b['format'] && 'migration' === $b['context'] && is_int( $b['checked_at'] ), 'report-body', 'body {format:1, context:migration, checked_at, checks, totals, hash}', wp_json_encode( is_array( $b ) ? array_keys( $b ) : $b ) );
	$hash = is_array( $b ) ? hash( 'sha256', wp_json_encode( array( 'checks' => $b['checks'], 'totals' => $b['totals'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) : '';
	gs110pf_assert( is_array( $b ) && hash_equals( $hash, (string) $b['hash'] ), 'report-hash', 'hash = sha256 of the canonical JSON of {checks, totals}' );
	$ok = is_array( $b );
	foreach ( is_array( $b ) ? $b['checks'] : array() as $ch ) {
		$ok = $ok && array( 'id', 'label', 'status', 'message', 'blocker' ) === array_keys( (array) $ch );
	}
	gs110pf_assert( $ok && ! in_array( 'rest_external', array_column( (array) ( $b['checks'] ?? array() ), 'id' ), true ), 'report-checks', 'checks are {id,label,status,message,blocker} rows; the hub-made rest_external is not sent back' );
	$src = '';
	if ( function_exists( 'gend_society_migration_preflight_report' ) ) {
		$rf  = new ReflectionFunction( 'gend_society_migration_preflight_report' );
		$src = implode( '', array_slice( file( $rf->getFileName() ), $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 ) );
	}
	gs110pf_assert( false !== strpos( $src, "gend_society_remote_membership_call( 'migration/preflight', \$body, 'POST' )" ), 'report-via-call', "issued through gend_society_remote_membership_call( 'migration/preflight', \$body, 'POST' )" );
	// welcome context: never reported
	$GLOBALS['gs110pf']['http'] = array();
	$w  = gend_society_readiness_collect( 'welcome' );
	$wr = function_exists( 'gend_society_migration_preflight_report' ) ? gend_society_migration_preflight_report( $w ) : null;
	gend_society_readiness_run( true );
	gs110pf_assert( is_wp_error( $wr ) && 0 === count( gs110pf_hub_calls() ), 'report-welcome-none', 'welcome context -> no request (WP_Error)' );
};

$gs110pf_sections['consent'] = function () {
	gs110pf_set_paired();
	gs110pf_keep_options( array( 'gend_society_migration_preflight_answer', 'gend_society_readiness_last_migration', 'gend_society_migration_sizing' ) );
	gs110pf_stub(
		function () {
			return array( 200, array( 'stored' => true ) );
		}
	);
	add_filter( 'gend_society_remote_allowed', '__return_false' );
	gs110pf_size_all();
	$r   = gs110pf_collect_migration();
	$res = function_exists( 'gend_society_migration_preflight_report' ) ? gend_society_migration_preflight_report( $r ) : new WP_Error( 'missing', 'missing' );
	remove_filter( 'gend_society_remote_allowed', '__return_false' );
	gs110pf_assert( function_exists( 'gend_society_migration_preflight_report' ) && is_wp_error( $res ), 'consent-refused', 'no consent -> report refused (WP_Error)' );
	gs110pf_assert( 0 === count( gs110pf_hub_calls() ), 'consent-zero', 'no consent -> 0 gend.me requests (collect + report)', wp_json_encode( gs110pf_hub_calls() ) );
	// not connected at all
	update_option( 'gend_society_install_token', '', false );
	$GLOBALS['gs110pf']['http'] = array();
	$res = function_exists( 'gend_society_migration_preflight_report' ) ? gend_society_migration_preflight_report( gs110pf_collect_migration() ) : null;
	gs110pf_assert( is_wp_error( $res ) && 0 === count( gs110pf_hub_calls() ), 'consent-unpaired', 'not connected -> refused, 0 gend.me requests' );
};

$gs110pf_sections['remote-mode'] = function () {
	wp_set_current_user( 1 );
	gs110pf_assert( current_user_can( 'manage_options' ), 'remote-admin', 'rendering as a manage_options user' );
	$r    = gs110pf_fixed_migration_result();
	$html = gs110pf_render( function () use ( $r ) {
		gend_society_readiness_render_card( $r, 'migration', 'remote' );
	} );
	$text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<style.*?</style>#s', '', $html ) ), ENT_QUOTES );
	gs110pf_assert( false === stripos( $html, '<form' ), 'remote-no-form', 'no <form' );
	gs110pf_assert( false === stripos( $html, 'admin-post.php' ), 'remote-no-admin-post', 'no admin-post.php' );
	gs110pf_assert( false === stripos( $html, 'submit' ), 'remote-no-submit', 'no submit button / submit_button() output' );
	gs110pf_assert( false !== strpos( $text, "Re-run them in your site's wp-admin" ) && false !== strpos( $text, 'Checks reported by your site on' ), 'remote-copy', "copy 'Checks reported by your site on <date>. Re-run them in your site's wp-admin.'" );
	gs110pf_assert( false === strpos( $text, 'Local checks only' ), 'remote-not-local-copy', "no 'Local checks only'" );
	gs110pf_assert( 1 === substr_count( $html, '<style' ), 'remote-style', 'one inline <style> block' );
	$css = preg_match( '#<style[^>]*>(.*?)</style>#s', $html, $m ) ? $m[1] : '';
	$sel = array_filter( array_map( 'trim', preg_split( '/\{[^}]*\}/', $css ) ) );
	$scoped = $sel && array_reduce(
		$sel,
		function ( $ok, $s ) {
			foreach ( explode( ',', $s ) as $one ) {
				$ok = $ok && 0 === strpos( trim( $one ), '.gend-society-readiness--remote' );
			}
			return $ok;
		},
		true
	);
	gs110pf_assert( $scoped, 'remote-style-scoped', 'every CSS selector is scoped to .gend-society-readiness--remote', wp_json_encode( array_values( $sel ) ) );
	foreach ( array( 'disk_free', 'estimate', 'plan_fit' ) as $id ) {
		gs110pf_assert( false !== strpos( $html, 'data-check="' . $id . '"' ), "remote-row-{$id}", "same table markup: row data-check={$id}" );
	}
	gs110pf_assert( false !== strpos( $html, 'gend-society-badge--fail' ) && false !== strpos( $html, 'gend-society-badge--info' ), 'remote-badges', 'status badges (fail, info) rendered' );
	$html2 = gs110pf_render( function () use ( $r ) {
		gend_society_readiness_render_card( $r, 'migration', 'remote' );
	} );
	gs110pf_assert( false === strpos( $html2, '<style' ) && false !== strpos( $html2, 'gend-society-readiness--remote' ), 'remote-style-once', 'the <style> block is emitted once per request' );
	gs110pf_assert( false !== strpos( $text, '1 item to fix before migrating' ), 'remote-summary', 'blocker summary (1 item to fix)' );
};

$gs110pf_sections['remote-frontend'] = function () {
	if ( ! gs110pf_assert( is_multisite() && function_exists( 'gend_society_runtime_mode' ) && 'hub' === gend_society_runtime_mode(), 'rf-hub', 'running on the hub clone' ) ) {
		return;
	}
	$mu = WP_CONTENT_DIR . '/mu-plugins/gs110pf-rc.php';
	file_put_contents( // phpcs:ignore
		$mu,
		"<?php\n// gs110pf test-only (110-09 remote-frontend, clone only): ?gs110rc=1 renders the readiness card in remote mode on the front end.\n"
		. "add_action( 'template_redirect', function () {\n"
		. "\tif ( ! isset( \$_GET['gs110rc'] ) ) { return; }\n"
		. "\tif ( ! is_super_admin() ) { status_header( 403 ); echo 'GS110RC-DENIED'; exit; }\n"
		. "\t\$r = get_site_option( 'gs110pf_rc_result' );\n"
		. "\tstatus_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' );\n"
		. "\techo '<!doctype html><html><head><title>gs110rc</title></head><body>';\n"
		. "\tgend_society_readiness_render_card( is_array( \$r ) ? \$r : null, 'migration', 'remote' );\n"
		. "\techo '<p>GS110RC-OK is_admin=' . ( is_admin() ? 1 : 0 ) . '</p></body></html>';\n"
		. "\texit;\n"
		. "}, 0 );\n"
	);
	@chown( $mu, 33 ); // phpcs:ignore
	update_site_option( 'gs110pf_rc_result', gs110pf_fixed_migration_result() );
	$admins = get_super_admins();
	$user   = $admins ? get_user_by( 'login', $admins[0] ) : null;
	$uid    = $user ? (int) $user->ID : 0;
	$exp    = time() + 600;
	$tok    = $uid ? WP_Session_Tokens::get_instance( $uid )->create( $exp ) : '';
	gs110pf_on_cleanup(
		function () use ( $mu, $uid, $tok ) {
			if ( is_file( $mu ) ) {
				unlink( $mu ); // phpcs:ignore
			}
			delete_site_option( 'gs110pf_rc_result' );
			if ( $uid && $tok ) {
				WP_Session_Tokens::get_instance( $uid )->destroy( $tok );
			}
		}
	);
	if ( ! gs110pf_assert( $uid > 0 && is_super_admin( $uid ), 'rf-super-admin', 'a super admin with a fresh session token' ) ) {
		return;
	}
	$cookie = new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'logged_in', $tok ) ) );
	$host   = wp_parse_url( network_home_url(), PHP_URL_HOST ) . ( wp_parse_url( network_home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( network_home_url(), PHP_URL_PORT ) : '' );
	$res    = wp_remote_get(
		'http://localhost/?gs110rc=1',
		array(
			'headers'     => array( 'Host' => $host ),
			'cookies'     => array( $cookie ),
			'timeout'     => 30,
			'redirection' => 0,
		)
	);
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$body = is_wp_error( $res ) ? $res->get_error_message() : (string) wp_remote_retrieve_body( $res );
	gs110pf_assert( 200 === $code && false !== strpos( $body, 'GS110RC-OK is_admin=0' ), 'rf-200', 'GET front end as super admin -> 200, page finished, outside wp-admin', $code . ' ' . substr( wp_strip_all_tags( $body ), 0, 160 ) );
	gs110pf_assert( false !== strpos( $body, 'gend-society-readiness--remote' ) && false !== strpos( $body, 'data-check="estimate"' ), 'rf-card', 'remote card rendered on the front end' );
	gs110pf_assert( false === stripos( $body, '<form' ) && false === stripos( $body, 'admin-post.php' ), 'rf-no-form', 'no <form>, no admin-post.php in the body' );
	$anon = wp_remote_get( 'http://localhost/?gs110rc=1', array( 'headers' => array( 'Host' => $host ), 'timeout' => 30, 'redirection' => 0 ) );
	gs110pf_assert( 403 === (int) wp_remote_retrieve_response_code( $anon ), 'rf-anon-403', 'the test endpoint refuses anonymous visitors (403)' );
};

/** Pull the CTA block out of rendered HTML; [ cta html, button html ] (empty strings when absent). */
function gs110pf_cta_extract( $html ) {
	$s = strpos( $html, '<style>.gdc-migrate-cta{' );
	if ( false === $s ) {
		$s = strpos( $html, '<div class="gdc-migrate-cta"' );
	}
	if ( false === $s ) {
		return array( '', '' );
	}
	$bs = strpos( $html, '<a class="gdc-migrate-cta__btn"', $s );
	if ( false === $bs ) {
		$bs = strpos( $html, '<button type="button" class="gdc-migrate-cta__btn"', $s );
	}
	if ( false === $bs ) {
		return array( '', '' );
	}
	$be  = strpos( $html, '</a>', $bs );
	$be  = false === $be ? strpos( $html, '</button>', $bs ) + 9 : $be + 4;
	$end = strpos( $html, '</div>', $be );
	if ( false === $end || $end !== $be ) {
		return array( '', '' );
	}
	return array( substr( $html, $s, $end + 6 - $s ), substr( $html, $bs, $be - $bs ) );
}

$gs110pf_sections['cta-standalone'] = function () {
	gs110pf_assert( 'standalone' === gend_society_runtime_mode() && gend_society_dashboard_standalone_paired(), 'cta-paired', 'standalone bed, paired (run section pair first)' );
	gs110pf_keep_options( array( 'gend_society_remote_membership_cache', 'gend_society_remote_membership_cache_expires' ) );
	$payload = gs110pf_set_payload();
	wp_set_current_user( 1 );
	$html = gs110pf_render( function () {
		gend_society_hosting_render_storage_resource_cards( array(), array(), array() );
	} );
	list( $cta, $btn ) = gs110pf_cta_extract( $html );
	if ( ! gs110pf_assert( '' !== $cta, 'cta-present', 'Storage card renders the .gdc-migrate-cta block' ) ) {
		return;
	}
	$ref = is_readable( WP_CONTENT_DIR . '/gs110pf-vam-cta.html' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/gs110pf-vam-cta.html' ) : ''; // phpcs:ignore
	gs110pf_assert( '' !== $ref && false !== strpos( $ref, '[[BUTTON]]' ), 'cta-reference', 'VAM gdc_render_migrate_containers_cta() reference captured on the clone' );
	$mine = str_replace( $btn, '[[BUTTON]]', $cta );
	$tre  = '#(<p class="gdc-migrate-cta__text">)(.*?)(</p>)#s';
	$mt   = preg_match( $tre, $mine, $mm ) ? $mm[2] : null;
	gs110pf_assert( esc_html( "After checkout, you start the copy when you're ready." ) === $mt, 'cta-text', "the one copy change: .gdc-migrate-cta__text = \"After checkout, you start the copy when you're ready.\"", wp_json_encode( $mt ) );
	$a = preg_replace( $tre, '$1[[TEXT]]$3', $mine );
	$b = preg_replace( $tre, '$1[[TEXT]]$3', $ref );
	$same = ( $a === $b );
	if ( ! $same ) {
		$i = 0;
		$n = min( strlen( $a ), strlen( $b ) );
		while ( $i < $n && $a[ $i ] === $b[ $i ] ) {
			$i++;
		}
		gs110pf_info( 'first difference at ' . $i . ': mine ' . wp_json_encode( substr( $a, max( 0, $i - 40 ), 120 ) ) . ' ref ' . wp_json_encode( substr( $b, max( 0, $i - 40 ), 120 ) ) );
	}
	gs110pf_assert( $same, 'cta-markup-identical', 'markup + CSS identical to the VAM CTA apart from that one string (button slot aside)' );
	gs110pf_assert( (bool) preg_match( '#^<a class="gdc-migrate-cta__btn" data-gs-popup data-gs-migrate-cta="1" href="([^"]+)" target="_blank" rel="opener">#', $btn, $bm ), 'cta-button', 'button = <a class="gdc-migrate-cta__btn" data-gs-popup data-gs-migrate-cta="1" href target=_blank rel=opener>', $btn );
	$href = isset( $bm[1] ) ? html_entity_decode( $bm[1] ) : '';
	$u    = wp_parse_url( $href );
	parse_str( (string) ( $u['query'] ?? '' ), $q );
	gs110pf_assert( 'localhost' === ( $u['host'] ?? '' ) && 8919 === (int) ( $u['port'] ?? 0 ) && '/my-account/memberships/' === ( $u['path'] ?? '' ), 'cta-href-host', 'href on the paired hub host, my-account/memberships/', $href );
	gs110pf_assert( (string) GS110PF_MID === (string) ( $q['gdc_open_modal'] ?? '' ) && '1' === (string) ( $q['gdc_auto_migrate'] ?? '' ), 'cta-href-query', 'gdc_open_modal=<membership id>&gdc_auto_migrate=1', $href );
	gs110pf_assert( function_exists( 'gend_society_plan_container_wizard_url' ) && gend_society_plan_container_wizard_url( $payload ) === $href, 'cta-url-fn', 'href = gend_society_plan_container_wizard_url( payload )' );
	gs110pf_assert( false !== strpos( $btn, 'Start Migration' ), 'cta-label', 'button label Start Migration' );
	gs110pf_assert( 1 === substr_count( $html, 'gdc-migrate-cta" role="region"' ), 'cta-once', 'exactly one CTA' );
};

$gs110pf_sections['cta-hidden'] = function () {
	gs110pf_keep_options( array( 'gend_society_remote_membership_cache', 'gend_society_remote_membership_cache_expires' ) );
	$render = function () {
		return gs110pf_render( function () {
			gend_society_hosting_render_storage_resource_cards( array(), array(), array() );
		} );
	};
	wp_set_current_user( 1 );
	gs110pf_set_payload();
	$base = $render();
	gs110pf_info( 'paired baseline has CTA: ' . ( false !== strpos( $base, 'gdc-migrate-cta' ) ? 'yes' : 'no' ) );
	$nt = function () {
		return '';
	};
	add_filter( 'pre_option_gend_society_install_token', $nt );
	$h = $render();
	remove_filter( 'pre_option_gend_society_install_token', $nt );
	gs110pf_assert( false === strpos( $h, 'gdc-migrate-cta' ), 'cta-hidden-unpaired', 'not connected -> no CTA' );
	gs110pf_set_payload( array( 'membership_url' => 'https://evil.example/my-account/membership/' . GS110PF_MID . '/' ) );
	gs110pf_assert( false === strpos( $render(), 'gdc-migrate-cta' ), 'cta-hidden-foreign-host', 'membership_url on another host -> no CTA' );
	gs110pf_set_payload( array( 'membership_id' => 0 ) );
	gs110pf_assert( false === strpos( $render(), 'gdc-migrate-cta' ), 'cta-hidden-no-mid', 'no membership id -> no CTA' );
	gs110pf_set_payload( array( 'migration' => array( 'live' => true, 'index' => 5, 'stage' => 'live' ) ) );
	gs110pf_assert( false === strpos( $render(), 'gdc-migrate-cta' ), 'cta-hidden-live', 'after Live -> no CTA' );
	gs110pf_set_payload();
	$uid = (int) wp_insert_user( array( 'user_login' => 'gs110pfsub' . wp_rand( 1000, 9999 ), 'user_email' => 'gs110pfsub' . wp_rand( 1000, 9999 ) . '@example.test', 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
	gs110pf_on_cleanup(
		function () use ( $uid ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		}
	);
	wp_set_current_user( $uid );
	gs110pf_assert( false === strpos( $render(), 'gdc-migrate-cta' ), 'cta-hidden-subscriber', 'no manage_options -> no CTA' );
	wp_set_current_user( 1 );
	gs110pf_assert( false !== strpos( $render(), 'gdc-migrate-cta' ), 'cta-shown-control', 'control: paired admin, hub URL, not Live -> CTA shown' );
};

// 110-21: the wordpress.org build ships no migration modules (inc/migration-{preflight,export,serve}.php are
// excluded until the review team has answered), so a paired admin with a container wizard URL must see NO Storage
// Start Migration CTA there: paying would arm a migration this build cannot start. Run on a bed with dist/wporg.
$gs110pf_sections['cta-wporg'] = function () {
	gs110pf_assert( defined( 'GEND_SOCIETY_BUILD' ) && 'wporg' === GEND_SOCIETY_BUILD, 'wporg-build', 'bed runs the wordpress.org build (GEND_SOCIETY_BUILD = wporg)' );
	gs110pf_assert( ! function_exists( 'gend_society_migration_cta_mode' ) && ! function_exists( 'gend_society_migration_export_tick' ) && ! function_exists( 'gend_society_migration_file_route' ), 'wporg-no-migration', 'no migration module loaded (preflight / export / serve)' );
	gs110pf_assert( false === has_action( 'rest_api_init', 'gend_society_migration_register_file_route' ), 'wporg-no-file-route', 'no signed migration file route registered' );
	gs110pf_keep_options( array( 'gend_society_remote_membership_cache', 'gend_society_remote_membership_cache_expires' ) );
	$opts = array(
		'gend_society_install_id'    => 'gs110pf-wporg-install',
		'gend_society_install_token' => str_repeat( 'a', 64 ),
		'gend_society_gend_base_url' => GS110PF_BASE,
	);
	$fs   = array();
	foreach ( $opts as $k => $v ) {
		$fs[ $k ] = function () use ( $v ) {
			return $v;
		};
		add_filter( 'pre_option_' . $k, $fs[ $k ] );
	}
	add_filter( 'gend_society_remote_allowed', '__return_true', 99 );
	wp_set_current_user( 1 );
	gs110pf_set_payload();
	// plan-access.php loads only on a paired install (manifest needs: paired); the bed is paired by filters here.
	if ( ! function_exists( 'gend_society_plan_container_wizard_url' ) && is_readable( WP_PLUGIN_DIR . '/gend-society/inc/plan-access.php' ) ) {
		require_once WP_PLUGIN_DIR . '/gend-society/inc/plan-access.php';
	}
	$paired = gend_society_dashboard_standalone_paired();
	$wiz    = function_exists( 'gend_society_plan_container_wizard_url' ) ? gend_society_plan_container_wizard_url( (array) gend_society_remote_membership_get_cached() ) : '';
	$html   = gs110pf_render(
		function () {
			gend_society_hosting_render_storage_resource_cards( array(), array(), array() );
		}
	);
	remove_filter( 'gend_society_remote_allowed', '__return_true', 99 );
	foreach ( $fs as $k => $cb ) {
		remove_filter( 'pre_option_' . $k, $cb );
	}
	gs110pf_assert( $paired && '' !== $wiz, 'wporg-control', 'control: paired admin and the container wizard URL builds', wp_json_encode( array( $paired, $wiz ) ) );
	gs110pf_assert( false !== strpos( $html, 'gs-hosting__resources-grid' ), 'wporg-cards-render', 'the Storage cards still render' );
	gs110pf_assert( false === strpos( $html, 'gdc-migrate-cta' ) && false === strpos( $html, 'data-gs-migrate-cta' ), 'wporg-no-cta', 'wordpress.org build: no Start Migration CTA on the Storage card' );
};

$gs110pf_sections['cta-hub-unchanged'] = function () {
	$mode = gend_society_runtime_mode();
	gs110pf_assert( in_array( $mode, array( 'hub', 'container' ), true ), 'hubcta-mode', "running on hub or container ({$mode})" );
	wp_set_current_user( 1 );
	// The card shows live usage (sizes, file counts, "scanned N ago"): numbers are normalised, markup and copy are not.
	$html = preg_replace( '/\d+(?:[.,]\d+)*/', '#', gs110pf_norm( gs110pf_render( function () {
		gend_society_hosting_render_storage_resource_cards( array(), array(), array() );
	} ) ) );
	gs110pf_info( 'storage card bytes ' . strlen( $html ) . ' (numbers normalised) cta=' . ( false !== strpos( $html, 'gdc-migrate-cta' ) ? 'yes' : 'no' ) );
	gs110pf_baseline_check( 'storage-' . $mode, md5( $html ), 'hubcta-md5', "Storage card HTML md5 equals main ({$mode})" );
	if ( function_exists( 'gdc_render_migrate_containers_cta' ) && '1' === (string) getenv( 'GS110PF_CAPTURE' ) ) {
		echo 'VAMCTA-BEGIN' . gdc_render_migrate_containers_cta( '[[BUTTON]]' ) . "VAMCTA-END\n";
	}
};

// ---------------------------------------------------------------------------------------------------------
$gs110pf_req = isset( $args ) && $args ? (string) $args[0] : (string) getenv( 'GS110_SECTION' );
$gs110pf_run = array_values( array_filter( explode( ',', $gs110pf_req ) ) );
gs110pf_info( 'site=' . home_url() . ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?' ) . ' multisite=' . ( is_multisite() ? 1 : 0 ) . ' gend-society=' . ( defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '?' ) . ' sections=' . implode( ',', $gs110pf_run ) );
if ( ! $gs110pf_run ) {
	gs110pf_fail( 'dispatcher', 'no section given' );
}
foreach ( $gs110pf_run as $gs110pf_s ) {
	if ( ! isset( $gs110pf_sections[ $gs110pf_s ] ) ) {
		gs110pf_fail( $gs110pf_s, 'unknown section' );
		continue;
	}
	echo "== section {$gs110pf_s}\n";
	try {
		$gs110pf_sections[ $gs110pf_s ]();
	} catch ( \Throwable $e ) {
		gs110pf_fail( $gs110pf_s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs110pf_cleanup();
	}
}
$gs110pf_f = (int) $GLOBALS['gs110pf']['fail'];
$gs110pf_p = (int) $GLOBALS['gs110pf']['pass'];
echo ( 0 === $gs110pf_f && $gs110pf_p > 0 ) ? "ALL PASS ({$gs110pf_p})\n" : 'FAILURES: ' . max( 1, $gs110pf_f ) . "\n";
