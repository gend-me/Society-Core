<?php
/**
 * Phase 110-12 battery: the plugin Start UI against the 110-11 hub routes (MIG-03 / MIG-08 / MIG-12 / MIG-14, M2, M7).
 *
 *   wp eval-file start-ui.php <section>[,<section>...]
 *   (C:/tmp/livepull/phase110/tools/110-12-run.sh drives it on the gs110 bed and the gs110lim nocron bed)
 *
 * Sections (13 here; the 14th, cta-popup-sync, is the real-Chrome driver C:/tmp/livepull/phase110/ui/cta-sync.js):
 *   render-states    .gs-mship-progress only for armed / scheduled / exporting / importing / verifying / failed; free and
 *                    live panel HTML md5 == main (the Storage CTA block set aside on free)
 *   start-step       armed: Start + the hidden inline Start step (freeze text, I understand, readiness card, Start now /
 *                    Schedule for + datetime-local, Confirm)
 *   confirm-gates    AJAX start: no freeze ack -> error, no hub call; a blocker in the fresh migration result -> error,
 *                    no hub call; valid -> one POST migration/preflight then exactly one POST migration/start with the
 *                    fresh hash
 *   schedule-nocron  DISABLE_WP_CRON (nocron bed) or hub schedule_allowed=false (gs110): Schedule radio + date input
 *                    disabled with the WP-Cron line; start with start_at -> error, no hub call
 *   cta-before       free connected payload: report 2 h old -> wait page, then one report POST and the wizard redirect;
 *                    10 min old -> no POST, immediate redirect; none -> one POST (missing = stale); the Hosting render
 *                    queues the background report
 *   cta-after        armed -> "Start migration", not the wizard URL, data-gs-start-inline="1"; scheduled / running / live
 *                    -> no CTA
 *   cancel-visibility can_cancel drives Cancel; running -> none; Cancel posts migration/cancel
 *   retry-error      failed: the hub's friendly error in the gs-mship-status-badge slot; Retry next to Cancel posts
 *                    migration/retry
 *   waiting          preparing + waiting_for_site -> "Waiting for your site to start the copy. Keep this tab open."
 *   poll             data-poll="1" only for armed / scheduled / running; the state AJAX handler GETs /membership with
 *                    If-None-Match (ETag-aware plan cache) and migration/state and returns the migration block + HTML
 *   job-receipt      a start answer with the secret stores the job (autoload no) and fires the action once; a state
 *                    answer with the secret after a hub-side start stores it once; a later poll without it changes nothing
 *   cleanup          cleanup=1 -> gend_society_migration_cleanup fired once, job option deleted
 *   no-remote-assets no script / stylesheet outside this site's origin, no iframe (panel, wait page, enqueued asset)
 *
 * Output: PASS:/FAIL:/INFO: lines; last line "ALL PASS (<n>)" or "FAILURES: <n>". gend.me is stubbed through
 * pre_http_request (only the paired base URL is answered and recorded). Options touched are restored after each section.
 * Baselines (render-states): wp-content/gs110su-baselines.txt ("<key> <md5>"); missing keys are recorded (main run).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs110su'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
	'http'    => array(),
	'acts'    => array(),
);

function gs110su_pass( $id, $t = '' ) {
	$GLOBALS['gs110su']['pass']++;
	echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110su_fail( $id, $t = '' ) {
	$GLOBALS['gs110su']['fail']++;
	echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
}
function gs110su_info( $t ) {
	echo 'INFO: ' . $t . "\n";
}
function gs110su_assert( $c, $id, $t = '', $d = '' ) {
	$c ? gs110su_pass( $id, $t ) : gs110su_fail( $id, $t . ( '' !== $d ? ' -- ' . $d : '' ) );
	return (bool) $c;
}
function gs110su_on_cleanup( callable $fn ) {
	$GLOBALS['gs110su']['cleanup'][] = $fn;
}
function gs110su_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs110su']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs110su_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs110su']['cleanup'] = array();
}
function gs110su_keep_options( array $names ) {
	$snap = array();
	foreach ( $names as $n ) {
		$snap[ $n ] = get_option( $n, '__gs110su_absent__' );
	}
	gs110su_on_cleanup(
		function () use ( $snap ) {
			foreach ( $snap as $n => $v ) {
				if ( '__gs110su_absent__' === $v ) {
					delete_option( $n );
				} else {
					update_option( $n, $v, false );
				}
			}
		}
	);
}
function gs110su_has( $fn ) {
	return function_exists( $fn );
}

const GS110SU_INSTALL_ID = '7c0a0b1e-1111-4111-8111-110000000012';
const GS110SU_TOKEN      = 'b112b112b112b112b112b112b112b112b112b112b112b112b112b112b112b112';
const GS110SU_BASE       = 'http://localhost:8919';
const GS110SU_MID        = 4243;
const GS110SU_JOB1       = '11111111-2222-4333-8444-555555555555';
const GS110SU_JOB2       = '66666666-7777-4888-9999-aaaaaaaaaaaa';
const GS110SU_JOB3       = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
const GS110SU_OPTIONS    = array(
	'gend_society_install_id',
	'gend_society_install_token',
	'gend_society_gend_base_url',
	'gend_society_consent',
	'gend_society_remote_consent',
	'gend_society_remote_membership_cache',
	'gend_society_remote_membership_cache_expires',
	'gend_society_remote_membership_cache_fetched_at',
	'gend_society_migration_job',
	'gend_society_migration_state_last',
	'gend_society_migration_preflight_answer',
	'gend_society_readiness_last_migration',
	'gend_society_migration_sizing',
	'gend_society_connection_health',
);

function gs110su_secret( $c ) {
	return str_repeat( $c, 64 );
}

// ---- baselines -----------------------------------------------------------------------------------------------
function gs110su_baseline_file() {
	return WP_CONTENT_DIR . '/gs110su-baselines.txt';
}
function gs110su_baseline_check( $key, $md5, $id, $text ) {
	$have = null;
	$f    = gs110su_baseline_file();
	if ( is_readable( $f ) ) {
		foreach ( (array) file( $f, FILE_IGNORE_NEW_LINES ) as $line ) {
			$p = explode( ' ', trim( $line ), 2 );
			if ( 2 === count( $p ) && $p[0] === $key ) {
				$have = $p[1];
			}
		}
	}
	if ( null === $have ) {
		file_put_contents( $f, $key . ' ' . $md5 . "\n", FILE_APPEND ); // phpcs:ignore
		echo 'BASELINE-RECORDED ' . $key . ' ' . $md5 . "\n";
		gs110su_pass( $id, $text . ' (baseline recorded ' . substr( $md5, 0, 8 ) . ')' );
		return;
	}
	gs110su_assert( $have === $md5, $id, $text . ' (baseline ' . substr( $have, 0, 8 ) . ')', 'now ' . $md5 );
}
function gs110su_norm( $html ) {
	$html = preg_replace( '/\b[0-9a-f]{10}\b/', 'NONCE', (string) $html );
	$html = preg_replace( '/\b\d+ (?:second|sec|min|minute|hour|day|week|month|year)s? ago\b/', 'N ago', $html );
	return $html;
}
/** The Storage card CTA (style + block) set aside. */
function gs110su_strip_cta( $html ) {
	$html = preg_replace( '#<style>\.gdc-migrate-cta\{.*?</style>#s', '', (string) $html );
	return preg_replace( '#<div class="gdc-migrate-cta" role="region".*?</a></div>#s', '[[CTA]]', $html );
}

// ---- hub stub --------------------------------------------------------------------------------------------------
/**
 * Answer requests to the paired base URL through $router( $path, $method, $body ) -> [code, body-array] (path after
 * /install/<id>/), record every request. With $self, requests to this site answer 200 (readiness self checks).
 */
function gs110su_stub( callable $router, $self = false ) {
	$GLOBALS['gs110su']['http'] = array();
	$f                          = function ( $pre, $args, $url ) use ( $router, $self ) {
		$GLOBALS['gs110su']['http'][] = array(
			'url'     => $url,
			'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => $args['body'] ?? '',
		);
		if ( 0 === strpos( $url, GS110SU_BASE . '/' ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$path = preg_replace( '#^.*/install/[^/]+/#', '', $path );
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			list( $code, $data ) = $router( $path, strtoupper( (string) ( $args['method'] ?? 'GET' ) ), is_array( $body ) ? $body : array(), $args );
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $data ),
				'response' => array(
					'code'    => $code,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if ( $self && function_exists( 'gend_society_readiness_self_hosts' ) ) {
			$h = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $h ) && in_array( strtolower( $h ), gend_society_readiness_self_hosts(), true ) ) {
				return array(
					'headers'  => array(),
					'body'     => '{"namespaces":["wp/v2"]}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $f, 1, 3 );
	gs110su_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, 1 );
		}
	);
}
/** Hub requests recorded since the stub was installed, optionally only $method + path suffix. */
function gs110su_calls( $method = '', $suffix = '' ) {
	$out = array();
	foreach ( $GLOBALS['gs110su']['http'] as $r ) {
		if ( 0 !== strpos( $r['url'], GS110SU_BASE . '/' ) ) {
			continue;
		}
		if ( '' !== $method && $r['method'] !== $method ) {
			continue;
		}
		if ( '' !== $suffix && ! preg_match( '#/install/[^/]+/' . preg_quote( $suffix, '#' ) . '(\?|$)#', $r['url'] ) ) {
			continue;
		}
		$out[] = $r;
	}
	return $out;
}
/** The default router: preflight stored, membership = the cached payload, everything else 404. */
function gs110su_router( array $over = array() ) {
	return function ( $path, $method, $body ) use ( $over ) {
		if ( isset( $over[ $path ] ) ) {
			return is_callable( $over[ $path ] ) ? call_user_func( $over[ $path ], $body, $method ) : $over[ $path ];
		}
		if ( 'migration/preflight' === $path ) {
			return array(
				200,
				array(
					'stored'        => true,
					'plan_fit'      => null,
					'rest_external' => array(
						'status'  => 'pass',
						'message' => 'ok',
					),
				),
			);
		}
		if ( 'membership' === $path ) {
			$p = get_option( 'gend_society_remote_membership_cache', array() );
			return array( 200, is_array( $p ) ? $p : array() );
		}
		return array(
			404,
			array(
				'code'    => 'rest_no_route',
				'message' => 'stub: ' . $path,
			),
		);
	};
}

// ---- fixtures --------------------------------------------------------------------------------------------------
function gs110su_pair() {
	gs110su_keep_options( GS110SU_OPTIONS );
	update_option( 'gend_society_install_id', GS110SU_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS110SU_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS110SU_BASE, false );
	update_option(
		'gend_society_consent',
		array(
			'at'      => time(),
			'user_id' => 1,
			'source'  => 'gs110su',
		),
		false
	);
	foreach ( array( 'gend_society_migration_job', 'gend_society_migration_state_last', 'gend_society_migration_preflight_answer', 'gend_society_readiness_last_migration', 'gend_society_connection_health' ) as $n ) {
		delete_option( $n );
	}
	$cron_snap = array();
	foreach ( array( 'gend_society_migration_poll', 'gend_society_migration_report_if_stale' ) as $h ) {
		$cron_snap[ $h ] = wp_next_scheduled( $h );
	}
	gs110su_on_cleanup(
		function () use ( $cron_snap ) {
			foreach ( $cron_snap as $h => $ts ) {
				wp_clear_scheduled_hook( $h );
				if ( $ts ) {
					wp_schedule_single_event( $ts, $h );
				}
			}
		}
	);
	wp_set_current_user( 1 );
}

/** Named migration blocks (the 110-11 /membership keys). */
function gs110su_mig( $name, array $over = array() ) {
	$base = array(
		'stage'        => '',
		'index'        => 0,
		'live'         => false,
		'steps'        => array( 'prepare', 'export', 'provision', 'verify', 'live' ),
		'state'        => 'idle',
		'can_start'    => false,
		'can_retry'    => false,
		'can_cancel'   => false,
		'scheduled_at' => 0,
		'error'        => '',
	);
	$named = array(
		'free'      => array(),
		'armed'     => array( 'stage' => 'prepare', 'index' => 1, 'can_start' => true, 'can_cancel' => true ),
		'scheduled' => array( 'stage' => 'prepare', 'index' => 1, 'state' => 'scheduled', 'can_cancel' => true, 'scheduled_at' => time() + 7200 ),
		'preparing' => array( 'stage' => 'export', 'index' => 2, 'state' => 'preparing' ),
		'exporting' => array( 'stage' => 'export', 'index' => 2, 'state' => 'exporting' ),
		'importing' => array( 'stage' => 'provision', 'index' => 3, 'state' => 'importing' ),
		'verifying' => array( 'stage' => 'verify', 'index' => 4, 'state' => 'verifying' ),
		'failed'    => array( 'stage' => 'export', 'index' => 2, 'state' => 'failed', 'can_retry' => true, 'can_cancel' => true, 'error' => 'Your host blocked the transfer' ),
		'live'      => array( 'stage' => 'live', 'index' => 5, 'live' => true, 'state' => 'complete' ),
	);
	return array_merge( $base, $named[ $name ], $over );
}

function gs110su_payload( $name, array $over = array() ) {
	$p = array(
		'membership_id'  => GS110SU_MID,
		'membership_url' => GS110SU_BASE . '/my-account/membership/' . GS110SU_MID . '/',
		'hub_url'        => GS110SU_BASE . '/',
		'app_url'        => home_url( '/' ),
		'app_title'      => 'gs110su bed',
		'status'         => 'active',
		'status_label'   => 'Active',
		'migration'      => gs110su_mig( $name, $over ),
		'cache_seconds'  => 60,
		'rev'            => 'gs110su-' . $name,
	);
	update_option( 'gend_society_remote_membership_cache', $p, false );
	update_option( 'gend_society_remote_membership_cache_expires', time() + 3600, false );
	return $p;
}

function gs110su_render( callable $fn ) {
	ob_start();
	try {
		$fn();
	} finally {
		$html = ob_get_clean();
	}
	return (string) $html;
}
function gs110su_panel( array $payload ) {
	wp_set_current_user( 1 );
	return gs110su_render(
		function () use ( $payload ) {
			echo gend_society_render_membership_panel( $payload ); // phpcs:ignore
		}
	);
}
function gs110su_storage() {
	wp_set_current_user( 1 );
	return gs110su_render(
		function () {
			gend_society_hosting_render_storage_resource_cards( array(), array(), array() );
		}
	);
}
/** The header progress element (first .gs-mship-progress ... up to the grid comment). */
function gs110su_progress( $html ) {
	$s = strpos( $html, '<div class="gs-mship-progress' );
	if ( false === $s ) {
		return '';
	}
	$e = strpos( $html, '<!-- ── 2-card grid', $s );
	return false === $e ? substr( $html, $s ) : substr( $html, $s, $e - $s );
}
/** The Storage card CTA button tag, '' when absent. */
function gs110su_cta_btn( $html ) {
	return preg_match( '#<a class="gdc-migrate-cta__btn"[^>]*>.*?</a>#s', $html, $m ) ? $m[0] : '';
}

/** A clean environment for a fresh pre-flight on a docker bed: public host, self checks 200, room on disk. */
function gs110su_clean_env() {
	$home = function () {
		return 'http://93.184.215.14:8910';
	};
	add_filter( 'pre_option_home', $home, 99 );
	add_filter( 'pre_option_siteurl', $home, 99 );
	$disk = function () {
		return 500 * 1073741824;
	};
	add_filter( 'gend_society_migration_disk_free', $disk, 99 );
	gs110su_on_cleanup(
		function () use ( $home, $disk ) {
			remove_filter( 'pre_option_home', $home, 99 );
			remove_filter( 'pre_option_siteurl', $home, 99 );
			remove_filter( 'gend_society_migration_disk_free', $disk, 99 );
		}
	);
}

function gs110su_count_action( $tag ) {
	$GLOBALS['gs110su']['acts'][ $tag ] = 0;
	$f                                  = function () use ( $tag ) {
		$GLOBALS['gs110su']['acts'][ $tag ]++;
	};
	add_action( $tag, $f, 10, 1 );
	gs110su_on_cleanup(
		function () use ( $tag, $f ) {
			remove_action( $tag, $f, 10 );
		}
	);
}
function gs110su_acts( $tag ) {
	return (int) ( $GLOBALS['gs110su']['acts'][ $tag ] ?? 0 );
}
function gs110su_do( $verb, array $req = array() ) {
	if ( ! function_exists( 'gend_society_migration_do' ) ) {
		return new WP_Error( 'missing', 'gend_society_migration_do() missing' );
	}
	return gend_society_migration_do( $verb, $req );
}
function gs110su_err( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : 'ok ' . wp_json_encode( $r );
}
function gs110su_ready() {
	$ok = function_exists( 'gend_society_runtime_mode' ) && 'standalone' === gend_society_runtime_mode() && function_exists( 'gend_society_dashboard_standalone_paired' ) && gend_society_dashboard_standalone_paired();
	gs110su_assert( $ok, 'paired', 'standalone bed, fixture pairing active' );
	return $ok;
}

// ---------------------------------------------------------------------------------------------------------------
$gs110su_sections = array();

// Helper sections: store / restore a fixture pairing so the paired-only modules (plan-access.php, ...) load at boot
// in the following processes. The bed's own options are snapshotted in gs110su_pair_snapshot and restored by unpair.
$gs110su_sections['pair'] = function () {
	if ( false === get_option( 'gs110su_pair_snapshot', false ) ) {
		$snap = array();
		foreach ( GS110SU_OPTIONS as $n ) {
			$snap[ $n ] = get_option( $n, '__gs110su_absent__' );
		}
		update_option( 'gs110su_pair_snapshot', $snap, false );
	}
	update_option( 'gend_society_install_id', GS110SU_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS110SU_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS110SU_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs110su' ), false );
	gs110su_pass( 'pair', 'fixture pairing stored (snapshot in gs110su_pair_snapshot)' );
};
$gs110su_sections['unpair'] = function () {
	$snap = get_option( 'gs110su_pair_snapshot', false );
	if ( ! is_array( $snap ) ) {
		gs110su_pass( 'unpair', 'nothing to restore' );
		return;
	}
	foreach ( $snap as $n => $v ) {
		if ( '__gs110su_absent__' === $v ) {
			delete_option( $n );
		} else {
			update_option( $n, $v, false );
		}
	}
	delete_option( 'gs110su_pair_snapshot' );
	wp_clear_scheduled_hook( 'gend_society_migration_poll' );
	wp_clear_scheduled_hook( 'gend_society_migration_report_if_stale' );
	gs110su_assert( GS110SU_TOKEN !== get_option( 'gend_society_install_token' ), 'unpair', 'pairing restored to its pre-battery state' );
};

$gs110su_sections['render-states'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub( gs110su_router() );
	foreach ( array( 'free', 'armed', 'scheduled', 'exporting', 'importing', 'verifying', 'failed', 'live' ) as $name ) {
		$html = gs110su_panel( gs110su_payload( $name ) );
		$want = ! in_array( $name, array( 'free', 'live' ), true );
		$has  = false !== strpos( $html, '<div class="gs-mship-progress' );
		gs110su_assert( $has === $want, "rs-progress-{$name}", ( $want ? 'header progress shown' : 'no header progress' ) . " for {$name}" );
		if ( ! $want ) {
			$md5 = md5( gs110su_norm( gs110su_strip_cta( $html ) ) );
			if ( getenv( 'GS110SU_DUMP' ) ) {
				file_put_contents( getenv( 'GS110SU_DUMP' ) . '-' . $name . '.html', gs110su_norm( gs110su_strip_cta( $html ) ) ); // phpcs:ignore
			}
			gs110su_baseline_check( 'panel-' . $name, $md5, "rs-md5-{$name}", "{$name} panel HTML equals main (CTA block set aside)" );
		}
	}
};

$gs110su_sections['start-step'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub( gs110su_router() );
	$p  = gs110su_progress( gs110su_panel( gs110su_payload( 'armed' ) ) );
	$ok = gs110su_assert( '' !== $p, 'ss-progress', 'armed: header progress present' );
	gs110su_assert( false !== strpos( $p, 'data-gs-mig-action="start-open"' ) && false !== strpos( $p, 'Start migration' ), 'ss-start-button', 'Start migration button in the header' );
	gs110su_assert( (bool) preg_match( '#data-gs-mig-start-step="1" hidden#', $p ), 'ss-step-hidden', 'inline Start step present and hidden until Start is clicked' );
	gs110su_assert( false !== strpos( $p, 'will not be copied' ), 'ss-freeze', 'content-freeze warning text' );
	gs110su_assert( false !== strpos( $p, 'name="gs_mig_freeze_ack"' ) && false !== strpos( $p, 'I understand' ), 'ss-ack', '"I understand" checkbox' );
	gs110su_assert( false !== strpos( $p, 'gend-society-readiness' ), 'ss-readiness', 'the local readiness card (gend_society_readiness_render_card, migration context)' );
	gs110su_assert( 2 === substr_count( $p, 'name="gs_mig_when"' ) && false !== strpos( $p, 'value="now" checked' ) && false !== strpos( $p, 'value="schedule"' ), 'ss-radios', 'Start now (checked) / Schedule for radios' );
	gs110su_assert( false !== strpos( $p, 'type="datetime-local"' ), 'ss-datetime', 'datetime-local input' );
	gs110su_assert( false !== strpos( $p, 'data-gs-mig-action="start"' ) && false !== strpos( $p, '>Confirm<' ), 'ss-confirm', 'Confirm button' );
	gs110su_assert( false === strpos( $p, '<form' ) || false !== strpos( $p, 'gend_society_readiness_rerun' ), 'ss-no-new-form', 'no form other than the readiness card Re-run' );
};

$gs110su_sections['confirm-gates'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	$start_body = null;
	gs110su_stub(
		gs110su_router(
			array(
				'migration/start' => function ( $b ) use ( &$start_body ) {
					$start_body = $b;
					return array(
						200,
						array(
							'state'         => 'preparing',
							'job_id'        => GS110SU_JOB1,
							'export_secret' => gs110su_secret( 'a' ),
							'ttl'           => 86400,
						),
					);
				},
			)
		),
		true
	);
	gs110su_payload( 'armed' );
	$r = gs110su_do( 'start', array( 'freeze_ack' => '' ) );
	gs110su_assert( is_wp_error( $r ) && 'gend_society_migration_freeze' === $r->get_error_code(), 'cg-no-ack', 'no freeze ack -> error', gs110su_err( $r ) );
	gs110su_assert( 0 === count( gs110su_calls() ), 'cg-no-ack-nocall', 'no gend.me call without the ack', (string) count( gs110su_calls() ) );

	gs110su_clean_env();
	$tiny = function () {
		return 1;
	};
	add_filter( 'gend_society_migration_disk_free', $tiny, 100 );
	$GLOBALS['gs110su']['http'] = array();
	$r                          = gs110su_do( 'start', array( 'freeze_ack' => '1' ) );
	remove_filter( 'gend_society_migration_disk_free', $tiny, 100 );
	gs110su_assert( is_wp_error( $r ) && 'gend_society_migration_blockers' === $r->get_error_code() && false !== stripos( $r->get_error_message(), 'free' ), 'cg-blocker', 'a blocker in the fresh pre-flight (disk) -> error naming it', gs110su_err( $r ) );
	gs110su_assert( 0 === count( gs110su_calls() ), 'cg-blocker-nocall', 'no gend.me call with a blocker', wp_json_encode( array_column( gs110su_calls(), 'url' ) ) );

	$GLOBALS['gs110su']['http'] = array();
	$r                          = gs110su_do( 'start', array( 'freeze_ack' => '1' ) );
	$pre                        = gs110su_calls( 'POST', 'migration/preflight' );
	$st                         = gs110su_calls( 'POST', 'migration/start' );
	gs110su_assert( ! is_wp_error( $r ), 'cg-valid', 'valid start succeeds', gs110su_err( $r ) );
	gs110su_assert( 1 === count( $pre ), 'cg-fresh-report', 'the fresh pre-flight is reported first (one POST migration/preflight)', (string) count( $pre ) );
	gs110su_assert( 1 === count( $st ), 'cg-one-start', 'exactly one POST migration/start', (string) count( $st ) );
	$sent = $pre ? json_decode( (string) $pre[0]['body'], true ) : array();
	gs110su_assert( is_array( $start_body ) && '' !== (string) ( $sent['hash'] ?? '' ) && ( $start_body['preflight_hash'] ?? '' ) === $sent['hash'] && 1 === (int) ( $start_body['freeze_ack'] ?? 0 ) && ! isset( $start_body['start_at'] ), 'cg-body', 'start body = {freeze_ack:1, preflight_hash: the fresh hash}, no start_at', wp_json_encode( $start_body ) );
	$last = gend_society_readiness_last( 'migration' );
	gs110su_assert( $last && (int) $last['checked_at'] >= time() - 120, 'cg-fresh-result', 'the stored migration result is the fresh one' );
};

$gs110su_sections['schedule-nocron'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub( gs110su_router(), true );
	$nocron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	gs110su_info( 'DISABLE_WP_CRON ' . ( $nocron ? 'defined (nocron bed)' : 'not defined: the hub schedule_allowed=false path' ) );
	$check = function ( $tag ) {
		$p = gs110su_progress( gs110su_panel( gs110su_payload( 'armed' ) ) );
		gs110su_assert( (bool) preg_match( '#name="gs_mig_when" value="schedule" disabled#', $p ), "sn-radio-{$tag}", "Schedule radio disabled ({$tag})" );
		gs110su_assert( (bool) preg_match( '#type="datetime-local" name="gs_mig_start_at" disabled#', $p ), "sn-input-{$tag}", "date-time input disabled ({$tag})" );
		gs110su_assert( false !== strpos( $p, 'Scheduling needs WP-Cron on your site; start now and keep this tab open' ), "sn-line-{$tag}", "the WP-Cron line ({$tag})" );
		$GLOBALS['gs110su']['http'] = array();
		$r                          = gs110su_do(
			'start',
			array(
				'freeze_ack' => '1',
				'start_at'   => time() + 7200,
			)
		);
		gs110su_assert( is_wp_error( $r ) && 'gend_society_migration_needs_cron' === $r->get_error_code(), "sn-refused-{$tag}", "start with start_at refused ({$tag})", gs110su_err( $r ) );
		gs110su_assert( 0 === count( gs110su_calls() ), "sn-nocall-{$tag}", "no gend.me call ({$tag})" );
	};
	if ( $nocron ) {
		$check( 'disable-wp-cron' );
		return;
	}
	$p = gs110su_progress( gs110su_panel( gs110su_payload( 'armed' ) ) );
	gs110su_assert( false !== strpos( $p, 'name="gs_mig_when" value="schedule">' ) && false === strpos( $p, 'Scheduling needs WP-Cron' ), 'sn-control', 'control: with WP-Cron and no hub refusal, Schedule is enabled' );
	update_option(
		'gend_society_migration_state_last',
		array(
			'at'    => time(),
			'state' => array(
				'state'            => 'idle',
				'can_start'        => true,
				'schedule_allowed' => false,
			),
		),
		false
	);
	$check( 'hub-schedule-allowed-false' );
};

$gs110su_sections['cta-before'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub( gs110su_router(), true );
	gs110su_clean_env();
	$payload = gs110su_payload( 'free' );
	$wizard  = gend_society_plan_container_wizard_url( $payload );
	gs110su_assert( '' !== $wizard, 'cb-wizard', 'wizard URL built for the free connected payload', $wizard );
	if ( ! gs110su_assert( gs110su_has( 'gend_society_migration_wizard_wait_response' ), 'cb-fn', 'wait page handler exists' ) ) {
		return;
	}
	$set = function ( $age ) {
		if ( null === $age ) {
			delete_option( 'gend_society_migration_preflight_answer' );
			return;
		}
		update_option(
			'gend_society_migration_preflight_answer',
			array(
				'at'            => time() - $age,
				'hash'          => 'x',
				'plan_fit'      => null,
				'rest_external' => null,
			),
			false
		);
	};
	foreach ( array(
		'2h'   => 7200,
		'none' => null,
	) as $tag => $age ) {
		$set( $age );
		$GLOBALS['gs110su']['http'] = array();
		$a                          = gend_society_migration_wizard_wait_response( '' );
		gs110su_assert( 'page' === $a['type'] && false !== strpos( (string) ( $a['html'] ?? '' ), 'Checking your site' ) && false !== strpos( (string) ( $a['html'] ?? '' ), 'step=run' ), "cb-{$tag}-page", "report {$tag}: the wait page shows 'Checking your site…' and reloads itself with step=run" );
		gs110su_assert( 0 === count( gs110su_calls() ), "cb-{$tag}-page-nocall", "report {$tag}: the first page sends nothing yet" );
		$b = gend_society_migration_wizard_wait_response( 'run' );
		gs110su_assert( 'redirect' === $b['type'] && $wizard === ( $b['location'] ?? '' ), "cb-{$tag}-redirect", "report {$tag}: step=run redirects to the wizard URL", wp_json_encode( $b ) );
		gs110su_assert( 1 === count( gs110su_calls( 'POST', 'migration/preflight' ) ), "cb-{$tag}-one-post", "report {$tag}: exactly one report POST", (string) count( gs110su_calls( 'POST', 'migration/preflight' ) ) );
	}
	$set( 600 );
	$GLOBALS['gs110su']['http'] = array();
	$a                          = gend_society_migration_wizard_wait_response( '' );
	gs110su_assert( 'redirect' === $a['type'] && $wizard === ( $a['location'] ?? '' ), 'cb-10min-redirect', 'report 10 min old: immediate redirect to the wizard', wp_json_encode( $a ) );
	gs110su_assert( 0 === count( gs110su_calls() ), 'cb-10min-nopost', 'report 10 min old: no POST' );

	$set( 7200 );
	wp_clear_scheduled_hook( 'gend_society_migration_report_if_stale' );
	$html = gs110su_storage();
	$btn  = gs110su_cta_btn( $html );
	gs110su_assert( false !== strpos( $btn, 'data-gs-popup' ) && false !== strpos( $btn, 'Start Migration' ), 'cb-cta', 'before payment the CTA still opens the popup (Start Migration)', $btn );
	gs110su_assert( (bool) wp_next_scheduled( 'gend_society_migration_report_if_stale' ), 'cb-queued', 'the Hosting render queued the background report (stale)' );
	$u = gend_society_migration_wizard_wait_url();
	gs110su_assert( 0 === strpos( $u, admin_url( 'admin-ajax.php' ) ) && false !== strpos( $u, 'action=gend_society_migration_wizard_wait' ) && false !== strpos( $u, '_wpnonce=' ), 'cb-wait-url', 'the popup opens the local wait page (admin-ajax, nonce)', $u );
};

$gs110su_sections['cta-after'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub( gs110su_router() );
	$wizard = gend_society_plan_container_wizard_url( gs110su_payload( 'armed' ) );
	$btn    = gs110su_cta_btn( gs110su_storage() );
	gs110su_assert( false !== strpos( $btn, 'Start migration' ), 'ca-label', 'armed: the CTA reads "Start migration"', $btn );
	gs110su_assert( '' !== $btn && false === strpos( $btn, esc_url( $wizard ) ) && false === strpos( $btn, 'gdc_open_modal' ), 'ca-href', 'armed: the CTA href is not the wizard URL' );
	gs110su_assert( false !== strpos( $btn, 'data-gs-start-inline="1"' ) && false === strpos( $btn, 'data-gs-popup' ), 'ca-inline', 'armed: data-gs-start-inline="1", no popup' );
	foreach ( array( 'scheduled', 'exporting', 'importing', 'verifying', 'live' ) as $name ) {
		gs110su_payload( $name );
		gs110su_assert( '' === gs110su_cta_btn( gs110su_storage() ), "ca-hidden-{$name}", "{$name}: no CTA" );
	}
};

$gs110su_sections['cancel-visibility'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub(
		gs110su_router(
			array(
				'migration/cancel' => array(
					200,
					array(
						'state'   => 'idle',
						'cleanup' => true,
					),
				),
			)
		)
	);
	$cases = array(
		array( 'armed', array(), true ),
		array( 'armed', array( 'can_cancel' => false ), false ),
		array( 'scheduled', array(), true ),
		array( 'failed', array(), true ),
		array( 'exporting', array( 'can_cancel' => true ), false ),
		array( 'importing', array(), false ),
		array( 'verifying', array( 'can_cancel' => true ), false ),
	);
	foreach ( $cases as $c ) {
		list( $name, $over, $want ) = $c;
		$p   = gs110su_progress( gs110su_panel( gs110su_payload( $name, $over ) ) );
		$has = false !== strpos( $p, 'data-gs-mig-action="cancel"' );
		$tag = $name . ( $over ? '-' . wp_json_encode( $over ) : '' );
		gs110su_assert( $has === $want, 'cv-' . sanitize_key( $tag ), ( $want ? 'Cancel shown' : 'no Cancel' ) . " ({$tag})" );
	}
	gs110su_payload( 'armed' );
	$GLOBALS['gs110su']['http'] = array();
	$r                          = gs110su_do( 'cancel' );
	gs110su_assert( ! is_wp_error( $r ) && 1 === count( gs110su_calls( 'POST', 'migration/cancel' ) ), 'cv-post', 'Cancel posts migration/cancel once', gs110su_err( $r ) );
};

$gs110su_sections['retry-error'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub(
		gs110su_router(
			array(
				'migration/retry' => array(
					200,
					array(
						'state'         => 'preparing',
						'job_id'        => GS110SU_JOB2,
						'export_secret' => gs110su_secret( 'b' ),
					),
				),
			)
		)
	);
	$html  = gs110su_panel( gs110su_payload( 'failed' ) );
	$title = preg_match( '#<div class="gs-mship-title">(.*?)</div>#s', $html, $m ) ? $m[1] : '';
	gs110su_assert( (bool) preg_match( '#<span class="gs-mship-status-badge"[^>]*>Your host blocked the transfer</span>#', $title ), 're-badge', "the hub's friendly error in the gs-mship-status-badge slot", substr( $title, 0, 400 ) );
	$p = gs110su_progress( $html );
	gs110su_assert( false !== strpos( $p, 'data-gs-mig-action="retry"' ) && false !== strpos( $p, 'data-gs-mig-action="cancel"' ), 're-buttons', 'Retry next to Cancel' );
	gs110su_assert( false === strpos( $p, 'data-poll="1"' ), 're-nopoll', 'failed: no polling' );
	$GLOBALS['gs110su']['http'] = array();
	$r                          = gs110su_do( 'retry' );
	gs110su_assert( ! is_wp_error( $r ) && 1 === count( gs110su_calls( 'POST', 'migration/retry' ) ), 're-post', 'Retry posts migration/retry once', gs110su_err( $r ) );
	$job = get_option( 'gend_society_migration_job' );
	gs110su_assert( is_array( $job ) && GS110SU_JOB2 === ( $job['job_id'] ?? '' ), 're-new-job', 'the retry answer stores the new job' );
	$html = gs110su_panel( gs110su_payload( 'exporting' ) );
	gs110su_assert( false === strpos( $html, 'data-gs-mig-error' ), 're-no-badge-running', 'running: no error badge' );
};

$gs110su_sections['waiting'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	$line = 'Waiting for your site to start the copy. Keep this tab open.';
	gs110su_stub(
		gs110su_router(
			array(
				'migration/state' => array(
					200,
					array(
						'state'            => 'preparing',
						'stage'            => 'export',
						'index'            => 2,
						'waiting_for_site' => true,
						'cleanup'          => false,
						'job_id'           => '',
					),
				),
			)
		)
	);
	$p = gs110su_progress( gs110su_panel( gs110su_payload( 'preparing' ) ) );
	gs110su_assert( false === strpos( $p, $line ), 'wt-none-before', 'no waiting line before a state answer says so' );
	$r = gs110su_do( 'state' );
	gs110su_assert( is_array( $r ) && false !== strpos( (string) ( $r['html'] ?? '' ), $line ), 'wt-poll', 'after the state poll (waiting_for_site) the repainted header shows the waiting line', is_array( $r ) ? substr( (string) $r['html'], 0, 200 ) : gs110su_err( $r ) );
	$p = gs110su_progress( gs110su_panel( gs110su_payload( 'preparing' ) ) );
	gs110su_assert( false !== strpos( $p, $line ), 'wt-render', 'the page render shows it too' );
	$p = gs110su_progress( gs110su_panel( gs110su_payload( 'exporting' ) ) );
	gs110su_assert( false === strpos( $p, $line ), 'wt-other-state', 'another state: no waiting line' );
};

$gs110su_sections['poll'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub(
		gs110su_router(
			array(
				'membership'      => function () {
					return array( 200, gs110su_payload_array( 'exporting' ) );
				},
				'migration/state' => array(
					200,
					array(
						'state'   => 'exporting',
						'index'   => 2,
						'cleanup' => false,
						'job_id'  => '',
					),
				),
			)
		)
	);
	foreach ( array(
		'armed'     => true,
		'scheduled' => true,
		'exporting' => true,
		'importing' => true,
		'verifying' => true,
		'failed'    => false,
	) as $name => $want ) {
		$p = gs110su_progress( gs110su_panel( gs110su_payload( $name ) ) );
		gs110su_assert( ( false !== strpos( $p, 'data-poll="1"' ) ) === $want, "pl-attr-{$name}", ( $want ? 'data-poll="1"' : 'no data-poll' ) . " ({$name})" );
	}
	gs110su_payload( 'armed' );
	$GLOBALS['gs110su']['http'] = array();
	$r                          = gs110su_do( 'state' );
	$mg                         = gs110su_calls( 'GET', 'membership' );
	gs110su_assert( 1 === count( $mg ), 'pl-membership', 'the state AJAX handler GETs /membership once', (string) count( $mg ) );
	gs110su_assert( $mg && false !== strpos( wp_json_encode( $mg[0]['headers'] ), 'If-None-Match' ), 'pl-etag', 'with If-None-Match (the ETag-aware plan cache)', $mg ? wp_json_encode( $mg[0]['headers'] ) : '' );
	gs110su_assert( 1 === count( gs110su_calls( 'GET', 'migration/state' ) ), 'pl-state', 'and GETs migration/state once' );
	gs110su_assert( is_array( $r ) && 'exporting' === ( $r['migration']['state'] ?? '' ) && 2 === (int) ( $r['migration']['index'] ?? 0 ) && ! empty( $r['poll'] ) && false !== strpos( (string) $r['html'], 'data-gs-mig-root' ), 'pl-answer', 'answer = the fresh migration block (exporting, index 2), HTML, poll on', is_array( $r ) ? wp_json_encode( $r['migration'] ) : gs110su_err( $r ) );
	$c = get_option( 'gend_society_remote_membership_cache' );
	gs110su_assert( is_array( $c ) && 'exporting' === ( $c['migration']['state'] ?? '' ), 'pl-cache', 'the option cache now holds the fresh payload' );
};

/** The payload array for $name without writing the cache (for the stubbed /membership answer). */
function gs110su_payload_array( $name ) {
	return array(
		'membership_id'  => GS110SU_MID,
		'membership_url' => GS110SU_BASE . '/my-account/membership/' . GS110SU_MID . '/',
		'hub_url'        => GS110SU_BASE . '/',
		'status'         => 'active',
		'migration'      => gs110su_mig( $name ),
		'cache_seconds'  => 60,
		'rev'            => 'gs110su-fresh-' . $name,
	);
}

$gs110su_sections['job-receipt'] = function () {
	global $wpdb;
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	$state = array(
		200,
		array(
			'state'         => 'preparing',
			'index'         => 2,
			'job_id'        => GS110SU_JOB2,
			'export_secret' => gs110su_secret( 'c' ),
			'cleanup'       => false,
		),
	);
	gs110su_stub(
		gs110su_router(
			array(
				'migration/start' => array(
					200,
					array(
						'state'         => 'preparing',
						'job_id'        => GS110SU_JOB1,
						'export_secret' => gs110su_secret( 'a' ),
						'ttl'           => 86400,
					),
				),
				'migration/state' => function () use ( &$state ) {
					return $state;
				},
			)
		),
		true
	);
	gs110su_count_action( 'gend_society_migration_job_received' );
	gs110su_clean_env();
	gs110su_payload( 'armed' );
	$r   = gs110su_do( 'start', array( 'freeze_ack' => '1' ) );
	$job = get_option( 'gend_society_migration_job' );
	gs110su_assert( ! is_wp_error( $r ) && is_array( $job ) && GS110SU_JOB1 === $job['job_id'] && gs110su_secret( 'a' ) === $job['export_secret'] && (int) $job['received_at'] > 0, 'jr-start', 'a start answer with the secret stores {job_id, export_secret, received_at}', gs110su_err( $r ) );
	gs110su_assert( 1 === gs110su_acts( 'gend_society_migration_job_received' ), 'jr-start-action', 'gend_society_migration_job_received fired once', (string) gs110su_acts( 'gend_society_migration_job_received' ) );
	$al = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'gend_society_migration_job' ) ); // phpcs:ignore
	gs110su_assert( in_array( $al, array( 'no', 'off' ), true ), 'jr-autoload', 'job option is not autoloaded', $al );
	gs110su_assert( false === strpos( wp_json_encode( get_option( 'gend_society_migration_state_last' ) ), gs110su_secret( 'a' ) ), 'jr-state-no-secret', 'the stored state never holds the secret' );

	// A hub-side / scheduled start: the first state poll delivers a new job.
	gs110su_payload( 'preparing' );
	gs110su_do( 'state' );
	$job = get_option( 'gend_society_migration_job' );
	gs110su_assert( is_array( $job ) && GS110SU_JOB2 === $job['job_id'] && gs110su_secret( 'c' ) === $job['export_secret'], 'jr-state', 'GET migration/state with export_secret stores the job' );
	gs110su_assert( 2 === gs110su_acts( 'gend_society_migration_job_received' ), 'jr-state-action', 'action fired once more', (string) gs110su_acts( 'gend_society_migration_job_received' ) );
	$before   = $job;
	$state[1] = array(
		'state'   => 'exporting',
		'index'   => 2,
		'job_id'  => GS110SU_JOB2,
		'cleanup' => false,
	);
	gs110su_do( 'state' );
	gs110su_assert( get_option( 'gend_society_migration_job' ) === $before, 'jr-second-poll', 'a later poll without the secret leaves the job unchanged' );
	gs110su_assert( 2 === gs110su_acts( 'gend_society_migration_job_received' ), 'jr-second-action', 'no new action' );
	// The same secret delivered twice (a retried request) is not a new job.
	gend_society_migration_receive(
		array(
			'state'         => 'exporting',
			'job_id'        => GS110SU_JOB2,
			'export_secret' => gs110su_secret( 'c' ),
		)
	);
	gs110su_assert( 2 === gs110su_acts( 'gend_society_migration_job_received' ), 'jr-dup', 'the same job + secret again: no second action' );
	// Junk never lands.
	gend_society_migration_receive(
		array(
			'state'         => 'exporting',
			'job_id'        => GS110SU_JOB3,
			'export_secret' => 'not-hex',
		)
	);
	gs110su_assert( GS110SU_JOB2 === ( get_option( 'gend_society_migration_job' )['job_id'] ?? '' ), 'jr-junk', 'a malformed secret is ignored' );
	// The cron poll receives it too (5-minute WP-Cron driver).
	delete_option( 'gend_society_migration_job' );
	$state[1] = array(
		'state'         => 'preparing',
		'index'         => 2,
		'job_id'        => GS110SU_JOB3,
		'export_secret' => gs110su_secret( 'd' ),
		'cleanup'       => false,
	);
	wp_clear_scheduled_hook( 'gend_society_migration_poll' );
	gend_society_migration_poll_cron();
	gs110su_assert( GS110SU_JOB3 === ( get_option( 'gend_society_migration_job' )['job_id'] ?? '' ) && (bool) wp_next_scheduled( 'gend_society_migration_poll' ), 'jr-cron', 'the WP-Cron poll stores the job and queues the next poll (running)' );
};

$gs110su_sections['cleanup'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub(
		gs110su_router(
			array(
				'migration/state' => array(
					200,
					array(
						'state'   => 'idle',
						'index'   => 1,
						'cleanup' => true,
						'job_id'  => '',
					),
				),
			)
		)
	);
	gs110su_count_action( 'gend_society_migration_cleanup' );
	add_option(
		'gend_society_migration_job',
		array(
			'job_id'        => GS110SU_JOB1,
			'export_secret' => gs110su_secret( 'e' ),
			'received_at'   => time(),
		),
		'',
		false
	);
	gs110su_payload( 'armed' );
	gs110su_do( 'state' );
	gs110su_assert( false === get_option( 'gend_society_migration_job', false ), 'cl-deleted', 'cleanup=1 deletes the job option' );
	gs110su_assert( 1 === gs110su_acts( 'gend_society_migration_cleanup' ), 'cl-action', 'gend_society_migration_cleanup fired once' );
	gs110su_do( 'state' );
	gs110su_assert( 1 === gs110su_acts( 'gend_society_migration_cleanup' ), 'cl-once', 'a second poll with cleanup=1 and no job: not fired again' );
};

$gs110su_sections['no-remote-assets'] = function () {
	gs110su_pair();
	if ( ! gs110su_ready() ) {
		return;
	}
	gs110su_stub( gs110su_router() );
	$own  = wp_parse_url( home_url(), PHP_URL_HOST );
	$html = '';
	foreach ( array( 'armed', 'scheduled', 'failed', 'exporting' ) as $name ) {
		$html .= gs110su_panel( gs110su_payload( $name ) );
	}
	gs110su_payload( 'free' );
	delete_option( 'gend_society_migration_preflight_answer' );
	if ( gs110su_has( 'gend_society_migration_wizard_wait_response' ) ) {
		$html .= (string) ( gend_society_migration_wizard_wait_response( '' )['html'] ?? '' );
	}
	$bad = array();
	if ( preg_match_all( '#<(script|link|img|iframe)\b[^>]*\b(?:src|href)=["\']([^"\']+)["\']#i', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$h = wp_parse_url( html_entity_decode( $x[2] ), PHP_URL_HOST );
			if ( in_array( strtolower( $x[1] ), array( 'script', 'link' ), true ) && $h && $h !== $own ) {
				$bad[] = $x[1] . ' ' . $x[2];
			}
		}
	}
	gs110su_assert( ! $bad, 'nr-origin', 'no script / stylesheet from another origin in the panel or the wait page', wp_json_encode( $bad ) );
	gs110su_assert( false === stripos( $html, '<iframe' ), 'nr-iframe', 'no iframe' );
	if ( function_exists( 'gend_society_dashboard_enqueue_standalone' ) ) {
		gend_society_dashboard_enqueue_standalone();
		$s = wp_scripts()->registered['gend-society-dashboard-standalone'] ?? null;
		gs110su_assert( $s && 0 === strpos( (string) $s->src, plugins_url() ), 'nr-asset', 'the header controls JS is the local gs-dashboard-standalone.js', $s ? (string) $s->src : 'none' );
		$l = (string) wp_scripts()->get_data( 'gend-society-dashboard-standalone', 'data' );
		gs110su_assert( false !== strpos( $l, 'migNonce' ) && false !== strpos( $l, 'migrationWaitUrl' ), 'nr-config', 'its config carries the migration nonce and the wait page URL' );
	} else {
		gs110su_fail( 'nr-asset', 'gend_society_dashboard_enqueue_standalone() missing' );
	}
};

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs110su_args = isset( $args ) ? (array) $args : array();
$gs110su_want = array();
foreach ( $gs110su_args as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs110su_want[] = trim( $s );
		}
	}
}
if ( ! $gs110su_want ) {
	$gs110su_want = array_keys( $gs110su_sections );
}
gs110su_info( 'start-ui on ' . home_url() . ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?' ) . ' gend-society=' . ( defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '?' ) . ' sections=' . implode( ',', $gs110su_want ) );
foreach ( $gs110su_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $gs110su_sections[ $s ] ) ) {
		gs110su_fail( $s, 'unknown section' );
		continue;
	}
	try {
		$gs110su_sections[ $s ]();
	} catch ( \Throwable $e ) {
		gs110su_fail( $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs110su_cleanup();
	}
}
$gs110su_f = (int) $GLOBALS['gs110su']['fail'];
$gs110su_p = (int) $GLOBALS['gs110su']['pass'];
echo ( 0 === $gs110su_f && $gs110su_p > 0 ) ? "ALL PASS ({$gs110su_p})\n" : "FAILURES: {$gs110su_f}\n";
