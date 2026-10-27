<?php
/**
 * Phase 110-15 battery: the signed migration file route + the exporter's progress / ready reports (MIG-05, MIG-04).
 *
 *   wp eval-file serve.php <section>[,<section>...]      (C:/tmp/livepull/phase110/tools/110-15-run.sh)
 *
 * Sections (17): sign-verify expired tampered wrong-job filename stitched part range alt-base rest-bypass rotate
 *   progress ready-once wrong-job-409 staging-exposed sweep uninstall
 *   helpers: pair / unpair (a fixture pairing, snapshot + restore), export (a ready export kept for the route sections),
 *   urls / urls-bad (signed URL lines for the runner's curl matrix and kind pull; -bad = signed with a wrong secret),
 *   reset.
 *
 * gend.me is stubbed in-process through pre_http_request (only the paired base URL is answered and recorded). Requests
 * to this site's own address (the signed URLs) go over real HTTP to the bed's web server: in a container the address
 * http://localhost:<port> is re-sent to env GS110SV_SELF (default http://localhost), the same path and query.
 * "uninstall" runs uninstall.php for real: only on a throw-away limit bed (the runner refuses it on gs110).
 *
 * Output: PASS:/FAIL:/INFO: lines; last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs110sv'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
	'http'    => array(),
	'route'   => array(),
);
function gs110sv_pass( $id, $t = '' ) {
	$GLOBALS['gs110sv']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110sv_fail( $id, $t = '' ) {
	$GLOBALS['gs110sv']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110sv_info( $t ) {
	echo 'INFO: ' . preg_replace( '/sig=[0-9a-f]{64}/', 'sig=<sig>', $t ) . "\n";
}
function gs110sv_assert( $c, $id, $t = '', $d = '' ) {
	$d = preg_replace( '/sig=[0-9a-f]{64}/', 'sig=<sig>', (string) $d );
	$c ? gs110sv_pass( $id, $t ) : gs110sv_fail( $id, $t . ( '' !== $d ? ' -- ' . $d : '' ) );
	return (bool) $c;
}
function gs110sv_on_cleanup( callable $fn ) {
	$GLOBALS['gs110sv']['cleanup'][] = $fn;
}
function gs110sv_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs110sv']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs110sv_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs110sv']['cleanup'] = array();
}

const GS110SV_INSTALL_ID = '7c0a0b1e-1111-4111-8111-110000000015';
const GS110SV_TOKEN      = 'b115b115b115b115b115b115b115b115b115b115b115b115b115b115b115b115';
const GS110SV_BASE       = 'http://localhost:8919';
const GS110SV_JOB1       = '15151515-aaaa-4bbb-8ccc-000000000001';
const GS110SV_JOB2       = '15151515-aaaa-4bbb-8ccc-000000000002';
const GS110SV_JOB3       = '15151515-aaaa-4bbb-8ccc-000000000003';
const GS110SV_OPTIONS    = array(
	'gend_society_install_id',
	'gend_society_install_token',
	'gend_society_gend_base_url',
	'gend_society_consent',
	'gend_society_remote_consent',
	'gend_society_migration_state_last',
);

function gs110sv_secret( $c ) {
	return str_repeat( $c, 64 );
}

function gs110sv_ready( $sec ) {
	$ok = function_exists( 'gend_society_migration_sign_url' ) && function_exists( 'gend_society_migration_verify' ) && function_exists( 'gend_society_migration_export_tick' );
	gs110sv_assert( $ok, $sec . '-module', 'migration-serve.php loaded (sign_url / verify) next to the exporter' );
	return $ok;
}

// ---- pairing + hub stub -------------------------------------------------------------------------------------------
function gs110sv_pair_store() {
	if ( false === get_option( 'gs110sv_pair_snapshot', false ) ) {
		$snap = array();
		foreach ( GS110SV_OPTIONS as $n ) {
			$snap[ $n ] = get_option( $n, '__gs110sv_absent__' );
		}
		update_option( 'gs110sv_pair_snapshot', $snap, false );
	}
	update_option( 'gend_society_install_id', GS110SV_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS110SV_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS110SV_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs110sv' ), false );
}

/**
 * Answer requests to the paired base URL through $router( $path, $method, $body ) -> [code, body-array]; record them.
 * Requests to this site's own http://localhost:<port> address are re-sent to GS110SV_SELF (container loopback).
 */
function gs110sv_stub( callable $router ) {
	$GLOBALS['gs110sv']['http'] = array();
	$self                       = rtrim( (string) ( getenv( 'GS110SV_SELF' ) ? getenv( 'GS110SV_SELF' ) : 'http://localhost' ), '/' );
	$home                       = untrailingslashit( home_url() );
	$f                          = function ( $pre, $args, $url ) use ( $router, $self, $home ) {
		if ( 0 === strpos( $url, GS110SV_BASE . '/' ) ) {
			$path = preg_replace( '#^.*/install/[^/]+/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$GLOBALS['gs110sv']['http'][] = array(
				'path'   => $path,
				'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
				'body'   => is_array( $body ) ? $body : array(),
				'raw'    => (string) ( $args['body'] ?? '' ),
			);
			list( $code, $data ) = $router( $path, strtoupper( (string) ( $args['method'] ?? 'GET' ) ), is_array( $body ) ? $body : array() );
			return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		if ( $self !== $home && 0 === strpos( $url, $home . '/' ) && empty( $args['_gs110sv'] ) ) {
			$args['_gs110sv'] = 1;
			return wp_remote_request( $self . substr( $url, strlen( $home ) ), $args );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $f, 1, 3 );
	gs110sv_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, 1 );
		}
	);
}

/** The default router: progress / state 200 (overridable per path). */
function gs110sv_router( array $over = array() ) {
	return function ( $path, $method, $body ) use ( $over ) {
		if ( isset( $over[ $path ] ) ) {
			return is_callable( $over[ $path ] ) ? call_user_func( $over[ $path ], $body, $method ) : $over[ $path ];
		}
		if ( 'migration/progress' === $path ) {
			return array( 200, array( 'ok' => true, 'state' => ! empty( $body['ready'] ) ? 'importing' : 'exporting' ) );
		}
		if ( 'migration/state' === $path ) {
			return array( 200, array( 'state' => 'exporting', 'job_id' => (string) ( get_option( 'gend_society_migration_job', array() )['job_id'] ?? '' ) ) );
		}
		return array( 404, array( 'code' => 'rest_no_route', 'message' => 'stub: ' . $path ) );
	};
}

function gs110sv_calls( $path, $ready = null ) {
	return array_values(
		array_filter(
			$GLOBALS['gs110sv']['http'],
			function ( $r ) use ( $path, $ready ) {
				return $r['path'] === $path && ( null === $ready || (bool) ( $r['body']['ready'] ?? false ) === $ready );
			}
		)
	);
}

// ---- jobs ---------------------------------------------------------------------------------------------------------
function gs110sv_wipe_job() {
	$j = get_option( 'gend_society_migration_job', null );
	if ( is_array( $j ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $j );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}

/** A job delivered the 110-12 way (gend_society_migration_receive with the secret). */
function gs110sv_receive( $job_id, $secret ) {
	if ( function_exists( 'gend_society_migration_receive' ) ) {
		gend_society_migration_receive( array( 'state' => 'preparing', 'job_id' => $job_id, 'export_secret' => $secret ) );
	} else {
		add_option( 'gend_society_migration_job', array( 'job_id' => $job_id, 'export_secret' => $secret, 'received_at' => time() ), '', false );
		do_action( 'gend_society_migration_job_received', get_option( 'gend_society_migration_job' ) );
	}
	return get_option( 'gend_society_migration_job', null );
}

function gs110sv_run_ticks( $n = 3000 ) {
	$r = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$r = gend_society_migration_export_tick( 'cron' );
		if ( ! in_array( $r['status'] ?? '', array( 'running', 'locked' ), true ) ) {
			break;
		}
	}
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
	return $r;
}

/** The kept ready export (helper "export"), or null. */
function gs110sv_kept() {
	$j = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
	return ( is_array( $j ) && GS110SV_JOB1 === ( $j['job_id'] ?? '' ) && 'ready' === ( $j['phase'] ?? '' ) ) ? $j : null;
}

function gs110sv_need_kept( $sec ) {
	$j = gs110sv_kept();
	gs110sv_assert( (bool) $j, $sec . '-export', 'a ready export of job 1 is kept (helper export)' );
	return $j;
}

/** Concatenated bytes of the files of $kind ('' = manifest). */
function gs110sv_concat( array $job, $kind, $part = 0 ) {
	if ( '' === $kind ) {
		return (string) file_get_contents( $job['staging'] . '/' . $job['manifest']['file'] ); // phpcs:ignore
	}
	$out = '';
	foreach ( (array) $job['parts'][ $kind ] as $p ) {
		if ( 0 === $part || (int) $p['n'] === (int) $part ) {
			$out .= (string) file_get_contents( $job['staging'] . '/' . $p['file'] ); // phpcs:ignore
		}
	}
	return $out;
}

/** GET a (signed) URL of this site over real HTTP: [code, body, headers]. */
function gs110sv_get( $url, array $headers = array(), $method = 'GET' ) {
	$r = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 120, 'redirection' => 0, 'headers' => $headers, 'cookies' => array() ) );
	if ( is_wp_error( $r ) ) {
		return array( 0, $r->get_error_message(), array() );
	}
	$h = wp_remote_retrieve_headers( $r );
	return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), is_object( $h ) && method_exists( $h, 'getAll' ) ? $h->getAll() : (array) $h );
}

function gs110sv_q( $url ) {
	$q = array();
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}

/** Rebuild a URL from (possibly tampered) query values. */
function gs110sv_url( array $q, $alt = false ) {
	$base = $alt ? home_url( '/?rest_route=/gs/v1/migration/file' ) : rest_url( 'gs/v1/migration/file' );
	return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $q ) ), $base );
}

function gs110sv_hmac( $job, $file, $part, $exp, $secret ) {
	return hash_hmac( 'sha256', 'fetch|' . GS110SV_INSTALL_ID . '|' . $job . '|' . $file . '|' . $part . '|' . $exp, $secret );
}

function gs110sv_forbidden( $url, $id, $label ) {
	list( $c, $b ) = gs110sv_get( $url );
	return gs110sv_assert( 403 === $c && strlen( $b ) < 600 && false === strpos( $b, 'INSERT INTO' ), $id, $label . ' -> 403', $c . ' ' . substr( $b, 0, 120 ) );
}

// ---------------------------------------------------------------------------------------------------------------
$S = array();

$S['pair'] = function () {
	gs110sv_pair_store();
	gs110sv_pass( 'pair', 'fixture pairing stored (snapshot gs110sv_pair_snapshot)' );
};
$S['unpair'] = function () {
	$snap = get_option( 'gs110sv_pair_snapshot', false );
	if ( is_array( $snap ) ) {
		foreach ( $snap as $n => $v ) {
			'__gs110sv_absent__' === $v ? delete_option( $n ) : update_option( $n, $v, false );
		}
		delete_option( 'gs110sv_pair_snapshot' );
	}
	wp_clear_scheduled_hook( 'gend_society_migration_poll' );
	wp_clear_scheduled_hook( 'gend_society_migration_sweep' );
	gs110sv_assert( GS110SV_TOKEN !== get_option( 'gend_society_install_token' ), 'unpair', 'pairing restored' );
};

$S['export'] = function () {
	if ( ! gs110sv_ready( 'export' ) ) {
		return;
	}
	gs110sv_pair_store();
	gs110sv_stub( gs110sv_router() );
	gs110sv_wipe_job();
	gs110sv_receive( GS110SV_JOB1, gs110sv_secret( 'a' ) );
	$r   = gs110sv_run_ticks();
	$job = gend_society_migration_export_job();
	gs110sv_info( 'export: ' . wp_json_encode( $r ) . ' parts ' . wp_json_encode( array_map( 'count', (array) ( $job['parts'] ?? array() ) ) ) );
	gs110sv_assert( is_array( $job ) && 'ready' === ( $job['phase'] ?? '' ), 'export', 'a ready export of job 1 kept for the route sections', wp_json_encode( $r ) );
};

$S['urls'] = function () {
	$job = gs110sv_kept();
	if ( ! gs110sv_assert( (bool) $job, 'urls', 'kept export' ) ) {
		return;
	}
	foreach ( array( 'db.sql.gz', 'users.sql.gz', 'uploads.tar.gz', 'manifest.json' ) as $f ) {
		echo 'URL ' . $f . ' all ' . gend_society_migration_sign_url( $f ) . "\n";
		echo 'URL ' . $f . ' alt ' . gend_society_migration_sign_url( $f, '', DAY_IN_SECONDS, true ) . "\n";
		echo 'URL ' . $f . ' expired ' . gend_society_migration_sign_url( $f, '', -60 ) . "\n";
		$kind = gend_society_migration_files()[ $f ];
		foreach ( '' === $kind ? array() : (array) $job['parts'][ $kind ] as $p ) {
			echo 'URL ' . $f . ' part' . (int) $p['n'] . ' ' . gend_society_migration_sign_url( $f, (string) $p['n'] ) . ' ' . $p['size'] . ' ' . $p['sha256'] . "\n";
		}
	}
	$m = json_decode( (string) file_get_contents( $job['staging'] . '/' . $job['manifest']['file'] ), true ); // phpcs:ignore
	foreach ( array( 'db', 'users', 'content' ) as $k ) {
		echo 'SUM ' . $k . ' ' . hash( 'sha256', gs110sv_concat( $job, $k ) ) . ' ' . count( (array) $m['parts'][ $k ] ) . "\n";
	}
	echo 'SUM manifest ' . $job['manifest']['sha256'] . " 1\n";
};

$S['urls-bad'] = function () {
	$job = gs110sv_kept();
	if ( ! gs110sv_assert( (bool) $job, 'urls-bad', 'kept export' ) ) {
		return;
	}
	$exp = time() + 3600;
	foreach ( array( 'db.sql.gz', 'users.sql.gz', 'uploads.tar.gz', 'manifest.json' ) as $f ) {
		$sig = gs110sv_hmac( GS110SV_JOB1, $f, '', $exp, gs110sv_secret( '9' ) ); // a wrong secret
		echo 'URL ' . $f . ' bad ' . gs110sv_url( array( 'job' => GS110SV_JOB1, 'file' => $f, 'exp' => $exp, 'sig' => $sig ) ) . "\n";
	}
};

$S['sign-verify'] = function () {
	if ( ! gs110sv_ready( 'sign-verify' ) || ! ( $job = gs110sv_need_kept( 'sign-verify' ) ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$url = gend_society_migration_sign_url( 'db.sql.gz' );
	$q   = gs110sv_q( $url );
	gs110sv_assert( 0 === strpos( $url, rest_url( 'gs/v1/migration/file' ) ) && isset( $q['job'], $q['file'], $q['exp'], $q['sig'] ) && ! isset( $q['part'] ), 'sv-shape', 'URL = rest_url(gs/v1/migration/file) ?job&file&exp&sig (no part)', $url );
	gs110sv_assert( abs( (int) $q['exp'] - ( time() + DAY_IN_SECONDS ) ) < 30, 'sv-ttl', '24 h TTL' );
	gs110sv_assert( hash_equals( gs110sv_hmac( GS110SV_JOB1, 'db.sql.gz', '', (int) $q['exp'], gs110sv_secret( 'a' ) ), (string) $q['sig'] ), 'sv-hmac', 'sig = HMAC-SHA256( fetch|install|job|file|part|exp, export secret )' );
	gs110sv_assert( true === gend_society_migration_verify( $q['job'], $q['file'], '', (int) $q['exp'], $q['sig'] ), 'sv-verify', 'verify() accepts it' );
	list( $c, $b, $h ) = gs110sv_get( $url );
	$want = gs110sv_concat( $job, 'db' );
	gs110sv_assert( 200 === $c && hash( 'sha256', $b ) === hash( 'sha256', $want ), 'sv-200', 'GET over HTTP, no cookie -> 200 with the db parts (sha256)', $c . ' ' . strlen( $b ) . '/' . strlen( $want ) );
	gs110sv_assert( (string) strlen( $want ) === (string) ( $h['content-length'] ?? '' ) && 'no' === strtolower( (string) ( $h['x-accel-buffering'] ?? '' ) ) && 'bytes' === strtolower( (string) ( $h['accept-ranges'] ?? '' ) ), 'sv-headers', 'Content-Length, X-Accel-Buffering: no, Accept-Ranges: bytes', wp_json_encode( $h ) );
	gs110sv_assert( false === strpos( wp_json_encode( $h ) . $url, gs110sv_secret( 'a' ) ), 'sv-no-secret', 'the secret is in neither the URL nor the headers' );
};

$S['expired'] = function () {
	if ( ! gs110sv_ready( 'expired' ) || ! gs110sv_need_kept( 'expired' ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$url = gend_society_migration_sign_url( 'manifest.json', '', -60 );
	$q   = gs110sv_q( $url );
	$v   = gend_society_migration_verify( $q['job'], $q['file'], '', (int) $q['exp'], $q['sig'] );
	gs110sv_assert( is_wp_error( $v ) && 403 === (int) ( $v->get_error_data()['status'] ?? 0 ), 'ex-verify', 'verify() refuses an expired exp with 403' );
	gs110sv_forbidden( $url, 'ex-http', 'an expired (correctly signed) URL' );
};

$S['tampered'] = function () {
	if ( ! gs110sv_ready( 'tampered' ) || ! gs110sv_need_kept( 'tampered' ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$q   = gs110sv_q( gend_society_migration_sign_url( 'db.sql.gz' ) );
	$sig = (string) $q['sig'];
	$t   = $q;
	$t['sig'] = substr( $sig, 0, -1 ) . ( 'a' === substr( $sig, -1 ) ? 'b' : 'a' );
	gs110sv_forbidden( gs110sv_url( $t ), 'tp-sig', 'one hex digit of the sig changed' );
	$t        = $q;
	$t['job'] = GS110SV_JOB2;
	gs110sv_forbidden( gs110sv_url( $t ), 'tp-job', 'another job id' );
	$t         = $q;
	$t['file'] = 'users.sql.gz';
	gs110sv_forbidden( gs110sv_url( $t ), 'tp-file', 'the file swapped (users.sql.gz on a db.sql.gz signature)' );
	$t         = $q;
	$t['part'] = '1';
	gs110sv_forbidden( gs110sv_url( $t ), 'tp-part', 'part=1 added to a whole-artifact signature' );
	$qp        = gs110sv_q( gend_society_migration_sign_url( 'db.sql.gz', '1' ) );
	$qp['part'] = '2';
	gs110sv_forbidden( gs110sv_url( $qp ), 'tp-part-n', 'part 1 signature used for part 2' );
	$t        = $q;
	$t['exp'] = (string) ( (int) $q['exp'] + 3600 );
	gs110sv_forbidden( gs110sv_url( $t ), 'tp-exp', 'the exp pushed out' );
	$t = $q;
	unset( $t['sig'] );
	list( $c ) = gs110sv_get( gs110sv_url( $t ) );
	gs110sv_assert( in_array( $c, array( 400, 403 ), true ), 'tp-nosig', 'no sig -> refused (' . $c . ')' );
};

$S['wrong-job'] = function () {
	if ( ! gs110sv_ready( 'wrong-job' ) || ! gs110sv_need_kept( 'wrong-job' ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$exp = time() + 3600;
	$url = gs110sv_url( array( 'job' => GS110SV_JOB2, 'file' => 'db.sql.gz', 'exp' => $exp, 'sig' => gs110sv_hmac( GS110SV_JOB2, 'db.sql.gz', '', $exp, gs110sv_secret( 'a' ) ) ) );
	gs110sv_forbidden( $url, 'wj-http', 'a valid HMAC for a job that is not the current one' );
};

$S['filename'] = function () {
	if ( ! gs110sv_ready( 'filename' ) || ! gs110sv_need_kept( 'filename' ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$exp = time() + 3600;
	foreach ( array( 'wp-config.php', '../db.sql.gz', 'db.sql', 'index.idx' ) as $i => $f ) {
		$url = gs110sv_url( array( 'job' => GS110SV_JOB1, 'file' => $f, 'exp' => $exp, 'sig' => gs110sv_hmac( GS110SV_JOB1, $f, '', $exp, gs110sv_secret( 'a' ) ) ) );
		gs110sv_forbidden( $url, 'fn-' . $i, 'file "' . $f . '" (correct HMAC) outside the allow-list' );
	}
	gs110sv_assert( '' === gend_society_migration_sign_url( 'wp-config.php' ), 'fn-sign', 'sign_url() refuses a file outside the allow-list' );
};

$S['stitched'] = function () {
	if ( ! gs110sv_ready( 'stitched' ) || ! ( $job = gs110sv_need_kept( 'stitched' ) ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	foreach ( array( 'db.sql.gz' => 'db', 'users.sql.gz' => 'users', 'uploads.tar.gz' => 'content', 'manifest.json' => '' ) as $f => $k ) {
		list( $c, $b, $h ) = gs110sv_get( gend_society_migration_sign_url( $f ) );
		$want = gs110sv_concat( $job, $k );
		gs110sv_assert( 200 === $c && hash( 'sha256', $b ) === hash( 'sha256', $want ), 'st-' . $f, $f . ': every part back to back (' . ( '' === $k ? 1 : count( (array) $job['parts'][ $k ] ) ) . ' file(s), ' . strlen( $want ) . ' bytes)', $c . ' ' . strlen( $b ) );
		if ( '' !== $k ) {
			$tmp = wp_tempnam( 'gs110sv' );
			file_put_contents( $tmp, $b ); // phpcs:ignore
			$gz  = gzopen( $tmp, 'rb' );
			$len = 0;
			while ( $gz && ! gzeof( $gz ) ) {
				$len += strlen( (string) gzread( $gz, 1048576 ) );
			}
			if ( $gz ) {
				gzclose( $gz );
			}
			wp_delete_file( $tmp );
			$raw = array_sum( array_column( (array) $job['parts'][ $k ], 'raw' ) );
			gs110sv_assert( $len === (int) $raw, 'st-gz-' . $f, $f . ' reads as ONE multi-member gzip stream to its full raw length (' . $raw . ')', (string) $len );
		} else {
			gs110sv_assert( 2 === ( json_decode( $b, true )['format'] ?? 0 ) && 'application/json' === strtolower( strtok( (string) ( $h['content-type'] ?? '' ), ';' ) ), 'st-manifest-json', 'manifest.json is the format 2 JSON' );
		}
	}
};

$S['part'] = function () {
	if ( ! gs110sv_ready( 'part' ) || ! ( $job = gs110sv_need_kept( 'part' ) ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$ok = true;
	foreach ( array( 'uploads.tar.gz' => 'content', 'db.sql.gz' => 'db' ) as $f => $k ) {
		foreach ( (array) $job['parts'][ $k ] as $p ) {
			list( $c, $b, $h ) = gs110sv_get( gend_society_migration_sign_url( $f, (string) $p['n'] ) );
			$ok = $ok && 200 === $c && hash( 'sha256', $b ) === $p['sha256'] && (string) $p['size'] === (string) ( $h['content-length'] ?? '' );
			if ( ! $ok ) {
				gs110sv_info( "part {$f} {$p['n']}: {$c} " . strlen( $b ) . ' / ' . $p['size'] );
				break 2;
			}
		}
	}
	gs110sv_assert( $ok, 'pt-each', 'part=N serves exactly that part (sha256 + Content-Length) for every uploads and db part' );
	list( $c ) = gs110sv_get( gend_society_migration_sign_url( 'uploads.tar.gz', '999' ) );
	gs110sv_assert( 404 === $c, 'pt-missing', 'a part that does not exist -> 404', (string) $c );
	list( $c ) = gs110sv_get( gend_society_migration_sign_url( 'manifest.json', '1' ) );
	gs110sv_assert( 404 === $c, 'pt-manifest', 'manifest.json has no parts -> 404', (string) $c );
};

$S['range'] = function () {
	if ( ! gs110sv_ready( 'range' ) || ! ( $job = gs110sv_need_kept( 'range' ) ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$url   = gend_society_migration_sign_url( 'uploads.tar.gz' );
	$all   = gs110sv_concat( $job, 'content' );
	$total = strlen( $all );
	$p1    = (int) $job['parts']['content'][0]['size'];
	$cases = array(
		'mid'      => array( 'bytes=100-1099', 100, 1099 ),
		'boundary' => array( 'bytes=' . max( 0, $p1 - 300 ) . '-' . min( $total - 1, $p1 + 299 ), max( 0, $p1 - 300 ), min( $total - 1, $p1 + 299 ) ),
		'suffix'   => array( 'bytes=-500', $total - 500, $total - 1 ),
		'open'     => array( 'bytes=' . ( $total - 777 ) . '-', $total - 777, $total - 1 ),
	);
	gs110sv_info( 'range: uploads ' . $total . ' bytes, ' . count( (array) $job['parts']['content'] ) . ' part(s), first part ' . $p1 );
	foreach ( $cases as $id => $cs ) {
		list( $c, $b, $h ) = gs110sv_get( $url, array( 'Range' => $cs[0] ) );
		$want = substr( $all, $cs[1], $cs[2] - $cs[1] + 1 );
		gs110sv_assert( 206 === $c && $b === $want && 'bytes ' . $cs[1] . '-' . $cs[2] . '/' . $total === (string) ( $h['content-range'] ?? '' ), 'rg-' . $id, $cs[0] . ' -> 206 with exactly those bytes' . ( 'boundary' === $id ? ' (across the part boundary)' : '' ), $c . ' ' . strlen( $b ) . ' ' . ( $h['content-range'] ?? '' ) );
	}
	list( $c, $b, $h ) = gs110sv_get( $url, array( 'Range' => 'bytes=' . $total . '-' ) );
	gs110sv_assert( 416 === $c && 'bytes */' . $total === (string) ( $h['content-range'] ?? '' ), 'rg-416', 'a range past the end -> 416 bytes */total', $c . ' ' . ( $h['content-range'] ?? '' ) );
	$pu = gend_society_migration_sign_url( 'uploads.tar.gz', '1' );
	list( $c, $b ) = gs110sv_get( $pu, array( 'Range' => 'bytes=10-19' ) );
	gs110sv_assert( 206 === $c && substr( $all, 10, 10 ) === $b, 'rg-part', 'Range on a single part' );
};

$S['alt-base'] = function () {
	if ( ! gs110sv_ready( 'alt-base' ) || ! ( $job = gs110sv_need_kept( 'alt-base' ) ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$url = gend_society_migration_sign_url( 'users.sql.gz', '', DAY_IN_SECONDS, true );
	gs110sv_assert( 0 === strpos( $url, home_url( '/?rest_route=/gs/v1/migration/file' ) ), 'ab-shape', 'the alt URL is the /?rest_route= form', $url );
	list( $c, $b ) = gs110sv_get( $url );
	gs110sv_assert( 200 === $c && hash( 'sha256', $b ) === hash( 'sha256', gs110sv_concat( $job, 'users' ) ), 'ab-200', 'the same artifact on the alt form', (string) $c );
	$q = gs110sv_q( gend_society_migration_sign_url( 'users.sql.gz' ) );
	list( $c ) = gs110sv_get( gs110sv_url( $q, true ) );
	gs110sv_assert( 200 === $c, 'ab-same-sig', 'one signature works on both URL forms (the host part is not signed)' );
};

$S['rest-bypass'] = function () {
	if ( ! gs110sv_ready( 'rest-bypass' ) || ! gs110sv_need_kept( 'rest-bypass' ) ) {
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$mu = WPMU_PLUGIN_DIR . '/gs110sv-rest-lock.php';
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents( $mu, "<?php\n// gs110sv test-only: a \"REST API needs login\" rule like security plugins add (removed by the battery).\nadd_filter( 'rest_authentication_errors', function ( \$r ) { return ( ! empty( \$r ) || is_user_logged_in() ) ? \$r : new WP_Error( 'rest_login_required', 'REST API restricted to logged-in users.', array( 'status' => 401 ) ); }, 50 );\n" ); // phpcs:ignore
	gs110sv_on_cleanup(
		function () use ( $mu ) {
			wp_delete_file( $mu );
		}
	);
	list( $c0 ) = gs110sv_get( rest_url( 'wp/v2/users' ) );
	gs110sv_assert( 401 === $c0, 'rb-lock', 'the site-wide REST lock is active (/wp/v2/users -> 401)', (string) $c0 );
	list( $c, $b ) = gs110sv_get( gend_society_migration_sign_url( 'manifest.json' ) );
	gs110sv_assert( 200 === $c && 2 === ( json_decode( $b, true )['format'] ?? 0 ), 'rb-file', 'the signed file route still answers 200 (bypass for this route only)', (string) $c );
	list( $c1 ) = gs110sv_get( add_query_arg( 'context', 'view', rest_url( 'gs/v1/' ) ) );
	gs110sv_assert( 401 === $c1, 'rb-scope', 'other gs/v1 paths keep the lock', (string) $c1 );
	list( $c2 ) = gs110sv_get( gend_society_migration_sign_url( 'manifest.json', '', DAY_IN_SECONDS, true ) );
	gs110sv_assert( 200 === $c2, 'rb-alt', 'the /?rest_route= form is bypassed too', (string) $c2 );
};

$S['progress'] = function () {
	if ( ! gs110sv_ready( 'progress' ) || ! function_exists( 'gend_society_migration_report_progress' ) ) {
		gs110sv_fail( 'pg-fn', 'gend_society_migration_report_progress() missing' );
		return;
	}
	gs110sv_pair_store();
	gs110sv_stub( gs110sv_router() );
	gs110sv_wipe_job();
	gs110sv_on_cleanup( 'gs110sv_wipe_job' );
	gs110sv_receive( GS110SV_JOB3, gs110sv_secret( 'c' ) );
	$one = function () {
		return 0.0001;
	};
	add_filter( 'gend_society_migration_tick_budget', $one );
	gend_society_migration_export_tick( 'cron' );
	$calls = gs110sv_calls( 'migration/progress' );
	gs110sv_assert( 1 === count( $calls ), 'pg-tick', 'the first tick POSTs migration/progress once', (string) count( $calls ) );
	$b = $calls[0]['body'] ?? array();
	gs110sv_assert( GS110SV_JOB3 === ( $b['job_id'] ?? '' ) && in_array( $b['phase'] ?? '', array( 'db', 'users', 'content' ), true ) && is_int( $b['done_bytes'] ?? null ) && is_int( $b['total_bytes'] ?? null ) && $b['done_bytes'] <= $b['total_bytes'] && isset( $b['parts']['db'], $b['parts']['users'], $b['parts']['content'] ) && empty( $b['ready'] ), 'pg-body', '{job_id, phase, done_bytes <= total_bytes, parts{db,users,content}} without ready', wp_json_encode( $b ) );
	gs110sv_assert( false === strpos( (string) ( $calls[0]['raw'] ?? '' ), gs110sv_secret( 'c' ) ), 'pg-no-secret', 'the export secret is never sent back' );
	for ( $i = 0; $i < 5; $i++ ) {
		gend_society_migration_export_tick( 'cron' );
	}
	gs110sv_assert( 1 === count( gs110sv_calls( 'migration/progress' ) ), 'pg-throttle', 'more ticks within 30 s send nothing more (throttle)', (string) count( gs110sv_calls( 'migration/progress' ) ) );
	$job = gend_society_migration_export_job();
	$job['report']['at'] = time() - 31;
	gend_society_migration_export_save( $job );
	gend_society_migration_export_tick( 'cron' );
	remove_filter( 'gend_society_migration_tick_budget', $one );
	gs110sv_assert( 2 === count( gs110sv_calls( 'migration/progress' ) ), 'pg-30s', 'after 30 s the next tick reports again', (string) count( gs110sv_calls( 'migration/progress' ) ) );
};

$S['ready-once'] = function () {
	if ( ! gs110sv_ready( 'ready-once' ) ) {
		return;
	}
	gs110sv_pair_store();
	$fail_first = true;
	gs110sv_stub(
		gs110sv_router(
			array(
				'migration/progress' => function ( $body ) use ( &$fail_first ) {
					if ( ! empty( $body['ready'] ) && $fail_first ) {
						$fail_first = false;
						return array( 503, array( 'code' => 'busy', 'message' => 'try later' ) );
					}
					return array( 200, array( 'ok' => true, 'state' => ! empty( $body['ready'] ) ? 'importing' : 'exporting' ) );
				},
			)
		)
	);
	gs110sv_wipe_job();
	gs110sv_on_cleanup( 'gs110sv_wipe_job' );
	gs110sv_receive( GS110SV_JOB3, gs110sv_secret( 'c' ) );
	gs110sv_run_ticks();
	$job   = gend_society_migration_export_job();
	$ready = gs110sv_calls( 'migration/progress', true );
	gs110sv_assert( 'ready' === ( $job['phase'] ?? '' ) && 1 === count( $ready ) && empty( $job['ready_reported_at'] ), 'ro-fail-first', 'ready reached; the first ready POST got 503 and is not marked as reported', count( $ready ) . ' ' . wp_json_encode( $job['ready_reported_at'] ?? null ) );
	// The next state answer (the 110-12 poll / repaint) retries it.
	$job['report']['at'] = time() - 31;
	gend_society_migration_export_save( $job );
	gend_society_migration_receive( array( 'state' => 'exporting', 'job_id' => GS110SV_JOB3 ) );
	$ready = gs110sv_calls( 'migration/progress', true );
	$job   = gend_society_migration_export_job();
	gs110sv_assert( 2 === count( $ready ) && ! empty( $job['ready_reported_at'] ), 'ro-retried', 'the poll retried it; 200 -> marked reported', count( $ready ) . ' calls' );
	$b = $ready[1]['body'] ?? array();
	$u = (array) ( $b['urls'] ?? array() );
	$ok = array( 'db', 'users', 'uploads', 'manifest' ) === array_keys( $u );
	$files = array( 'db' => 'db.sql.gz', 'users' => 'users.sql.gz', 'uploads' => 'uploads.tar.gz', 'manifest' => 'manifest.json' );
	foreach ( $u as $k => $url ) {
		$q  = gs110sv_q( $url );
		$ok = $ok && ( $q['file'] ?? '' ) === $files[ $k ] && true === gend_society_migration_verify( (string) ( $q['job'] ?? '' ), (string) $q['file'], '', (int) ( $q['exp'] ?? 0 ), (string) ( $q['sig'] ?? '' ) ) && 0 === strpos( $url, rest_url( 'gs/v1/migration/file' ) );
	}
	gs110sv_assert( true === ( $b['ready'] ?? null ) && GS110SV_JOB3 === ( $b['job_id'] ?? '' ) && $ok, 'ro-urls', 'ready:true + urls{db, users, uploads, manifest}, each a valid signed URL for its file', wp_json_encode( array_keys( $u ) ) );
	$alt = (array) ( $b['alt_urls'] ?? array() );
	gs110sv_assert( 4 === count( $alt ) && 0 === strpos( (string) ( $alt['manifest'] ?? '' ), home_url( '/?rest_route=/gs/v1/migration/file' ) ) && false !== strpos( (string) ( $b['alt_base'] ?? '' ), '?rest_route=' ), 'ro-alt', 'alt_urls on the /?rest_route= form + alt_base' );
	gs110sv_assert( false === strpos( (string) $ready[1]['raw'], gs110sv_secret( 'c' ) ), 'ro-no-secret', 'no export secret in the report' );
	gend_society_migration_export_tick( 'cron' );
	$job['report']['at'] = time() - 31;
	gend_society_migration_export_save( array_merge( gend_society_migration_export_job(), array( 'report' => $job['report'] ) ) );
	gend_society_migration_receive( array( 'state' => 'importing', 'job_id' => GS110SV_JOB3 ) );
	gs110sv_assert( 2 === count( gs110sv_calls( 'migration/progress', true ) ), 'ro-once', 'after a 200 no further ready report (tick, poll)', (string) count( gs110sv_calls( 'migration/progress', true ) ) );
};

$S['wrong-job-409'] = function () {
	if ( ! gs110sv_ready( 'wrong-job-409' ) ) {
		return;
	}
	gs110sv_pair_store();
	gs110sv_stub( gs110sv_router( array( 'migration/progress' => array( 409, array( 'code' => 'wrong_job', 'message' => 'This export job is not current.', 'data' => array( 'status' => 409 ) ) ) ) ) );
	gs110sv_wipe_job();
	gs110sv_on_cleanup( 'gs110sv_wipe_job' );
	gs110sv_receive( GS110SV_JOB3, gs110sv_secret( 'c' ) );
	$dir = (string) ( gend_society_migration_export_job()['staging'] ?? '' );
	$one = function () {
		return 0.0001;
	};
	add_filter( 'gend_society_migration_tick_budget', $one );
	$r = gend_society_migration_export_tick( 'cron' );
	remove_filter( 'gend_society_migration_tick_budget', $one );
	$job = gend_society_migration_export_job();
	gs110sv_assert( 'failed' === ( $job['phase'] ?? '' ), 'w409-stopped', 'a 409 from gend.me stops the exporter (phase failed)', wp_json_encode( $r ) . ' ' . (string) ( $job['phase'] ?? 'no job' ) );
	gs110sv_assert( '' !== $dir && ! is_dir( $dir ), 'w409-wiped', 'and wipes the staging folder', $dir );
	gs110sv_assert( ! wp_next_scheduled( 'gend_society_migration_export_tick' ), 'w409-no-cron', 'no tick queued' );
	$r2 = gend_society_migration_export_tick( 'ajax' );
	gs110sv_assert( 'idle' === ( $r2['status'] ?? '' ), 'w409-idle', 'further ticks do nothing', wp_json_encode( $r2 ) );
	list( $c ) = gs110sv_get( gend_society_migration_sign_url( 'manifest.json' ) );
	gs110sv_assert( in_array( $c, array( 403, 404, 409 ), true ), 'w409-no-serve', 'nothing is served for it (' . $c . ')' );
};

$S['staging-exposed'] = function () {
	if ( ! gs110sv_ready( 'staging-exposed' ) || ! function_exists( 'gend_society_migration_staging_exposed_check' ) ) {
		gs110sv_fail( 'se-fn', 'gend_society_migration_staging_exposed_check() missing' );
		return;
	}
	gs110sv_stub( gs110sv_router() );
	$ids = array_column( (array) ( gend_society_readiness_collect( 'migration' )['checks'] ?? array() ), 'id' );
	gs110sv_assert( in_array( 'staging_exposed', $ids, true ), 'se-id', 'the migration readiness context lists staging_exposed' );
	$w = array_column( (array) gend_society_readiness_collect( 'welcome' )['checks'], 'id' );
	gs110sv_assert( ! in_array( 'staging_exposed', $w, true ), 'se-welcome', 'the welcome context does not' );
	gs110sv_wipe_job();
	$c = gend_society_migration_staging_exposed_check();
	gs110sv_info( 'default: ' . $c['status'] . ' ' . $c['message'] );
	$probe   = gend_society_migration_staging_dir();
	$outside = '' !== $probe && 0 !== strpos( wp_normalize_path( $probe ) . '/', wp_normalize_path( untrailingslashit( ABSPATH ) ) . '/' );
	if ( '' !== $probe && function_exists( 'gend_society_migration_rmdir' ) ) {
		gend_society_migration_rmdir( $probe );
	}
	gs110sv_info( 'staging would go to ' . $probe . ( $outside ? ' (outside the web root)' : ' (inside)' ) );
	if ( $outside ) {
		gs110sv_assert( 'pass' === $c['status'] && false !== stripos( $c['message'], 'outside the web root' ), 'se-outside', 'staging outside the web root = pass' );
	}
	// In wp-content (no writable place outside the web root).
	$off = function () {
		return array();
	};
	add_filter( 'gend_society_migration_staging_candidates', $off );
	$c = gend_society_migration_staging_exposed_check();
	gs110sv_info( 'wp-content staging: ' . $c['status'] . ' ' . $c['message'] );
	gs110sv_assert( 'pass' === $c['status'] && (bool) preg_match( '/answered (403|404)/', $c['message'] ), 'se-wpcontent', 'staging in wp-content: an anonymous GET of the canary answers 403 / 404 = pass' );
	$left = glob( WP_CONTENT_DIR . '/gend-society-migration-*', GLOB_ONLYDIR );
	gs110sv_assert( ! $left || ! array_filter( (array) $left, function ( $d ) { return (bool) glob( $d . '/gs-canary-*' ); } ), 'se-clean', 'the check leaves no canary behind' );
	// Exposed: a staging folder the web server serves (the deny files gone, the folder world-readable).
	gs110sv_pair_store();
	gs110sv_receive( GS110SV_JOB3, gs110sv_secret( 'c' ) );
	gs110sv_on_cleanup( 'gs110sv_wipe_job' );
	$dir = (string) ( gend_society_migration_export_job()['staging'] ?? '' );
	wp_delete_file( $dir . '/.htaccess' );
	chmod( $dir, 0755 ); // phpcs:ignore
	$c = gend_society_migration_staging_exposed_check();
	remove_filter( 'gend_society_migration_staging_candidates', $off );
	gs110sv_info( 'exposed: ' . $c['status'] . ' ' . $c['message'] );
	gs110sv_assert( 'warn' === $c['status'] && false !== strpos( $c['message'], 'deny' ), 'se-exposed', 'a served folder (200 with the canary) = warn naming the fix' );
	gs110sv_assert( false === $c['blocker'], 'se-not-blocker', 'a warning, not a blocker' );
};

$S['sweep'] = function () {
	if ( ! gs110sv_ready( 'sweep' ) ) {
		return;
	}
	gs110sv_pair_store();
	gs110sv_stub( gs110sv_router() );
	gs110sv_wipe_job();
	gs110sv_on_cleanup( 'gs110sv_wipe_job' );
	gs110sv_receive( GS110SV_JOB3, gs110sv_secret( 'c' ) );
	gs110sv_assert( (bool) wp_next_scheduled( 'gend_society_migration_sweep' ), 'sw-scheduled', 'a job schedules the daily sweep' );
	$cur  = (string) gend_society_migration_export_job()['staging'];
	$old  = gend_society_migration_staging_dir();
	$new  = gend_society_migration_staging_dir();
	foreach ( array( $old, $new ) as $d ) {
		file_put_contents( $d . '/' . bin2hex( random_bytes( 8 ) ) . '.part', 'x' ); // phpcs:ignore
	}
	$ago = time() - 49 * HOUR_IN_SECONDS;
	foreach ( (array) glob( $old . '/{,.}*', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) ) {
			touch( $f, $ago );
		}
	}
	touch( $old, $ago );
	$foreign = WP_CONTENT_DIR . '/gend-society-migration-notours';
	wp_mkdir_p( $foreign );
	touch( $foreign, $ago );
	gs110sv_on_cleanup(
		function () use ( $foreign, $new ) {
			@rmdir( $foreign ); // phpcs:ignore
			if ( function_exists( 'gend_society_migration_rmdir' ) ) {
				gend_society_migration_rmdir( $new );
			}
		}
	);
	do_action( 'gend_society_migration_sweep' );
	gs110sv_assert( ! is_dir( $old ), 'sw-old', 'a staging folder this site made, untouched for 48 h, is deleted', $old );
	gs110sv_assert( is_dir( $new ) && is_dir( $cur ), 'sw-fresh', 'a fresh one and the current job\'s are kept' );
	gs110sv_assert( is_dir( $foreign ), 'sw-foreign', 'a folder the exporter did not make is left alone' );
	// The current job ended (ready / failed) more than 48 h ago: its parts go too.
	$job                 = gend_society_migration_export_job();
	$job['phase']        = 'failed';
	$job['last_tick_at'] = $ago;
	$job['ready_at']     = $ago;
	gend_society_migration_export_save( $job );
	do_action( 'gend_society_migration_sweep' );
	gs110sv_assert( ! is_dir( $cur ), 'sw-ended', 'an ended job\'s staging older than 48 h is deleted', $cur );
};

$S['rotate'] = function () {
	if ( ! gs110sv_ready( 'rotate' ) || ! ( $job = gs110sv_need_kept( 'rotate' ) ) ) {
		return;
	}
	gs110sv_pair_store();
	gs110sv_stub( gs110sv_router() );
	$old = gend_society_migration_sign_url( 'db.sql.gz' );
	list( $c0 ) = gs110sv_get( $old );
	gs110sv_assert( 200 === $c0, 'rt-before', 'the job 1 URL works before the Retry', (string) $c0 );
	$dir = (string) $job['staging'];
	gs110sv_receive( GS110SV_JOB2, gs110sv_secret( 'b' ) ); // Retry: a new job + secret from gend.me
	gs110sv_on_cleanup( 'gs110sv_wipe_job' );
	gs110sv_forbidden( $old, 'rt-old-403', 'after a Retry the old URL' );
	gs110sv_assert( ! is_dir( $dir ), 'rt-wiped', 'the old job\'s staging is wiped' );
	$q = gs110sv_q( $old );
	gs110sv_assert( is_wp_error( gend_society_migration_verify( $q['job'], $q['file'], '', (int) $q['exp'], $q['sig'] ) ), 'rt-verify', 'verify() refuses the old signature' );
};

$S['uninstall'] = function () {
	if ( ! gs110sv_ready( 'uninstall' ) ) {
		return;
	}
	if ( ! getenv( 'GS110SV_THROWAWAY' ) ) {
		gs110sv_fail( 'un-bed', 'refused: uninstall runs only on a throw-away limit bed (GS110SV_THROWAWAY=1)' );
		return;
	}
	gs110sv_pair_store();
	gs110sv_stub( gs110sv_router() );
	gs110sv_receive( GS110SV_JOB3, gs110sv_secret( 'c' ) );
	$dir = (string) ( gend_society_migration_export_job()['staging'] ?? '' );
	gs110sv_assert( '' !== $dir && is_dir( $dir ), 'un-before', 'a job with a staging folder exists', $dir );
	$blog = get_option( 'blogname' );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'gend-society/gend-society.php' );
	}
	include WP_PLUGIN_DIR . '/gend-society/uninstall.php';
	wp_cache_flush();
	gs110sv_assert( ! is_dir( $dir ), 'un-staging', 'uninstall (standalone) deletes the staging folder', $dir );
	gs110sv_assert( false === get_option( 'gend_society_migration_job', false ), 'un-job', 'and the job option' );
	$hooks = array();
	foreach ( array( 'gend_society_migration_export_tick', 'gend_society_migration_poll', 'gend_society_migration_sweep', 'gend_society_migration_report_if_stale' ) as $h ) {
		if ( wp_next_scheduled( $h ) ) {
			$hooks[] = $h;
		}
	}
	gs110sv_assert( ! $hooks, 'un-cron', 'and the migration cron hooks', implode( ',', $hooks ) );
	gs110sv_assert( get_option( 'blogname' ) === $blog, 'un-site', 'site content options untouched' );
	delete_option( 'gs110sv_pair_snapshot' );
};

$S['reset'] = function () {
	gs110sv_wipe_job();
	gs110sv_assert( false === get_option( 'gend_society_migration_job', false ), 'reset', 'no job left' );
};
$GLOBALS['gs110sv_sections'] = $S;

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs110sv_want = array();
foreach ( isset( $args ) ? (array) $args : array() as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs110sv_want[] = trim( $s );
		}
	}
}
gs110sv_info( 'serve on ' . home_url() . ' php=' . PHP_VERSION . ' sections=' . implode( ',', $gs110sv_want ) );
foreach ( $gs110sv_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs110sv_fail( $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs110sv_fail( $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs110sv_cleanup();
	}
}
echo ( 0 === (int) $GLOBALS['gs110sv']['fail'] && (int) $GLOBALS['gs110sv']['pass'] > 0 ) ? 'ALL PASS (' . (int) $GLOBALS['gs110sv']['pass'] . ")\n" : 'FAILURES: ' . (int) $GLOBALS['gs110sv']['fail'] . "\n";
