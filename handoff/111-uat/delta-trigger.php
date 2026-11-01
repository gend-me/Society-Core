<?php
/**
 * Phase 111-11 battery: the Catch up control, the delta job hand-off and its status line (MIG-13, P3/P4/P10).
 *
 *   wp eval-file delta-trigger.php <section>[,<section>...]    (C:/tmp/livepull/phase111/tools/sc-uat-111.sh bed delta-trigger <sections>)
 *
 * Sections:
 *   button-states          hidden outside Preview / for non-admins / unpaired, disabled when the hub says not available, enabled otherwise
 *   start-ajax             nonce + manage_options enforced; delta/start then delta/state; receipt {job_id, mode delta, secret} in the job option; no secret in the answer
 *   job-mode-delta         the receipt starts the exporter in mode delta from the stored base
 *   ready-report           the ready URLs go to migration/delta/ready (not migration/progress); 409 stops the job; the secret is never sent or logged
 *   state-poll             the status line per hub state, polling stops on done / failed, secret repair while queued, local export failure shown
 *   refuse-outside-preview not_preview and no hub call when live / idle / unpaired
 *   full-path-unchanged    a normal receipt still builds a full job that reports through migration/progress
 *
 * gend.me is stubbed through pre_http_request (only the fixture base URL is answered and recorded with its body).
 * Options, jobs, staging folders and posts made by a section are removed afterwards. Last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs111dt'] = array( 'pass' => 0, 'fail' => 0, 'cleanup' => array(), 'http' => array() );

class GS111DT_Die extends \Exception {
	public $args_in = array();
}

const GS111DT_INSTALL_ID = '7c0a0b1e-1111-4111-8111-111000000011';
const GS111DT_TOKEN      = 'c111c111c111c111c111c111c111c111c111c111c111c111c111c111c111c112';
const GS111DT_BASE       = 'http://localhost:8919';
const GS111DT_PREVIEW    = 'https://abc-gs111.gend.test/';
const GS111DT_JOB        = '0e111e11-aaaa-4bbb-8ccc-dddddddddd11';
const GS111DT_JOB2       = '0e111e11-aaaa-4bbb-8ccc-dddddddddd12';
const GS111DT_BASEJOB    = '0e111e11-aaaa-4bbb-8ccc-dddddddddd00';
const GS111DT_SECRET     = '5ec2e75ec2e75ec2e75ec2e75ec2e75ec2e75ec2e75ec2e75ec2e75ec2e75ec2';
const GS111DT_OPTIONS    = array(
	'gend_society_install_id',
	'gend_society_install_token',
	'gend_society_gend_base_url',
	'gend_society_consent',
	'gend_society_remote_consent',
	'gend_society_remote_membership_cache',
	'gend_society_remote_membership_cache_expires',
	'gend_society_remote_membership_cache_fetched_at',
	'gend_society_migration_job',
	'gend_society_migration_base',
	'gend_society_migration_lock',
	'gend_society_migration_state_last',
);

function gs111dt_assert( $c, $id, $t = '', $d = '' ) {
	if ( $c ) {
		$GLOBALS['gs111dt']['pass']++;
		echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
	} else {
		$GLOBALS['gs111dt']['fail']++;
		echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . ( '' !== $d ? ' -- ' . $d : '' ) . "\n";
	}
	return (bool) $c;
}
function gs111dt_on_cleanup( callable $fn ) {
	$GLOBALS['gs111dt']['cleanup'][] = $fn;
}
function gs111dt_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs111dt']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo 'INFO: cleanup failed: ' . $e->getMessage() . "\n";
		}
	}
	$GLOBALS['gs111dt']['cleanup'] = array();
	wp_set_current_user( 0 );
}
function gs111dt_wipe_job() {
	$job = get_option( 'gend_society_migration_job', null );
	if ( is_array( $job ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $job );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}
function gs111dt_keep_options() {
	$snap = array();
	foreach ( GS111DT_OPTIONS as $n ) {
		$snap[ $n ] = get_option( $n, '__gs111dt_absent__' );
	}
	gs111dt_on_cleanup(
		function () use ( $snap ) {
			gs111dt_wipe_job();
			foreach ( $snap as $n => $v ) {
				if ( '__gs111dt_absent__' === $v ) {
					delete_option( $n );
				} else {
					update_option( $n, $v, false );
				}
			}
		}
	);
}
/** Pair the fixture install and stub the hub: $router( path, method, body ) -> array( code, data ). */
function gs111dt_pair( callable $router = null ) {
	gs111dt_keep_options();
	update_option( 'gend_society_install_id', GS111DT_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS111DT_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS111DT_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs111dt' ), false );
	$GLOBALS['gs111dt']['http'] = array();
	$f                          = function ( $pre, $args, $url ) use ( $router ) {
		if ( 0 === strpos( $url, GS111DT_BASE . '/' ) ) {
			$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			$body   = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '';
			$path   = preg_replace( '#^.*/install/[^/]+/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$GLOBALS['gs111dt']['http'][] = array( 'url' => $url, 'path' => $path, 'method' => $method, 'body' => $body );
			list( $code, $data ) = $router ? $router( $path, $method, json_decode( $body, true ) ) : array( 404, array( 'message' => 'stub' ) );
			return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $f, 1, 3 );
	gs111dt_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, 1 );
		}
	);
}
function gs111dt_calls( $method = '', $path = '' ) {
	$out = array();
	foreach ( $GLOBALS['gs111dt']['http'] as $r ) {
		if ( ( '' !== $method && $r['method'] !== $method ) || ( '' !== $path && $r['path'] !== $path ) ) {
			continue;
		}
		$out[] = $r;
	}
	return $out;
}
function gs111dt_mig( array $over = array() ) {
	return array_merge(
		array(
			'stage'          => 'verify',
			'index'          => 4,
			'live'           => false,
			'steps'          => array( 'prepare', 'export', 'provision', 'verify', 'live' ),
			'state'          => 'preview',
			'can_start'      => false,
			'can_retry'      => false,
			'can_cancel'     => false,
			'scheduled_at'   => 0,
			'error'          => '',
			'preview'        => true,
			'preview_url'    => GS111DT_PREVIEW,
			'migrated_to'    => '',
			'migrated_at'    => 0,
			'domain_cutover' => true,
		),
		$over
	);
}
function gs111dt_payload( array $mig = array() ) {
	return array(
		'membership_id'  => 4244,
		'membership_url' => GS111DT_BASE . '/my-account/membership/4244/',
		'hub_url'        => GS111DT_BASE . '/',
		'app_url'        => home_url( '/' ),
		'app_title'      => 'gs111dt bed',
		'status'         => 'active',
		'status_label'   => 'Active',
		'migration'      => gs111dt_mig( $mig ),
		'domains'        => array(),
		'cache_seconds'  => 60,
		'rev'            => 'gs111dt',
	);
}
function gs111dt_store( array $p ) {
	update_option( 'gend_society_remote_membership_cache', $p, false );
	update_option( 'gend_society_remote_membership_cache_expires', time() + 3600, false );
}
function gs111dt_panel( array $payload, $uid = 1 ) {
	wp_set_current_user( $uid );
	ob_start();
	try {
		echo gend_society_render_membership_panel( $payload ); // phpcs:ignore
	} finally {
		$html = ob_get_clean();
	}
	return (string) $html;
}
function gs111dt_note( $html ) {
	$s = strpos( $html, 'data-gs-preview-note="1"' );
	if ( false === $s ) {
		return '';
	}
	$e = strpos( $html, '<!-- ── 2-card grid', $s );
	return false === $e ? substr( $html, $s ) : substr( $html, $s, $e - $s );
}
function gs111dt_subscriber() {
	$u = get_user_by( 'login', 'gs111dt_sub' );
	if ( ! $u ) {
		$id = wp_insert_user( array( 'user_login' => 'gs111dt_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'gs111dt_sub@example.test', 'role' => 'subscriber' ) );
		$u  = get_user_by( 'id', $id );
		gs111dt_on_cleanup(
			function () use ( $id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $id );
			}
		);
	}
	return (int) $u->ID;
}
function gs111dt_nonce( $uid = 1 ) {
	wp_set_current_user( $uid );
	return wp_create_nonce( 'gs_membership_action' );
}
/** Call the admin-ajax handler of a verb the way WordPress does and catch its output / death. */
function gs111dt_ajax( $verb, $uid, $nonce ) {
	wp_set_current_user( $uid );
	$_REQUEST['nonce'] = $nonce;
	$_POST['nonce']    = $nonce;
	$dg                = function () {
		return function ( $message, $title = '', $args = array() ) {
			$e          = new GS111DT_Die( is_scalar( $message ) ? (string) $message : '' );
			$e->args_in = is_array( $args ) ? $args : array();
			throw $e;
		};
	};
	add_filter( 'wp_die_ajax_handler', $dg );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$died = null;
	ob_start();
	try {
		gend_society_migration_ajax( $verb );
	} catch ( GS111DT_Die $e ) {
		$died = $e;
	} finally {
		$out = ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $dg );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_REQUEST['nonce'], $_POST['nonce'] );
	}
	$body = json_decode( (string) $out, true );
	return array( 'body' => is_array( $body ) ? $body : null, 'died' => null === $died ? '' : $died->getMessage(), 'raw' => $out );
}
/** A full export's base record, so a delta job can start. $taken = unix time of the base start. */
function gs111dt_base( $taken ) {
	$wm                 = gend_society_migration_watermark();
	$wm['taken_at_gmt'] = gmdate( 'Y-m-d H:i:s', $taken );
	update_option( 'gend_society_migration_base', array( 'job_id' => GS111DT_BASEJOB, 'watermark' => $wm, 'at' => $taken ), false );
}
/** The hub as 111-08 answers it. $st['state'] drives delta/state; $st['ready'] = http code for delta/ready. */
function gs111dt_hub( array &$st ) {
	return function ( $path, $method, $body ) use ( &$st ) {
		if ( 'membership' === $path ) {
			return array( 200, gs111dt_payload( array( 'state' => 'preview' ) ) );
		}
		if ( 'migration/delta/start' === $path && 'POST' === $method ) {
			if ( ! empty( $st['start_error'] ) ) {
				return array( 409, array( 'code' => 'delta_disabled', 'message' => 'Catching up is not available yet.' ) );
			}
			$st['state'] = 'queued';
			return array( 200, array( 'job_id' => $st['job'], 'mode' => 'delta', 'ttl' => 86400 ) );
		}
		if ( 'migration/delta/state' === $path && 'GET' === $method ) {
			$out = array(
				'state'   => $st['state'] ?? 'idle',
				'job_id'  => $st['job'],
				'counts'  => $st['counts'] ?? array(),
				'empty'   => ! empty( $st['empty'] ),
				'error'   => $st['error'] ?? '',
				'last_at' => $st['last_at'] ?? 0,
				'enabled' => true,
			);
			if ( 'queued' === $out['state'] && empty( $st['no_secret'] ) ) {
				$out['export_secret'] = GS111DT_SECRET;
			}
			return array( 200, $out );
		}
		if ( 'migration/delta/ready' === $path && 'POST' === $method ) {
			$code = (int) ( $st['ready'] ?? 200 );
			if ( 200 === $code ) {
				$st['state'] = 'exporting';
				return array( 200, array( 'ok' => true, 'state' => 'exporting' ) );
			}
			return array( $code, array( 'code' => 'transfer_refused', 'message' => 'The transfer was refused.' ) );
		}
		if ( 'migration/progress' === $path && 'POST' === $method ) {
			return array( 200, array( 'ok' => true ) );
		}
		return array( 404, array( 'message' => 'stub: ' . $path ) );
	};
}
function gs111dt_run( $n = 4000 ) {
	$r = array( 'status' => 'none' );
	for ( $i = 0; $i < $n; $i++ ) {
		$r = gend_society_migration_export_tick( 'cli' );
		if ( in_array( $r['status'], array( 'done', 'failed', 'none', 'idle' ), true ) ) {
			break;
		}
	}
	return $r;
}
function gs111dt_debug_log_has( $needle ) {
	$f = WP_CONTENT_DIR . '/debug.log';
	return is_readable( $f ) && false !== strpos( (string) file_get_contents( $f ), $needle );
}

$S = array();

$S['button-states'] = function () {
	gs111dt_pair();
	$warn = 0;
	set_error_handler(
		function () use ( &$warn ) {
			$warn++;
			return true;
		}
	);
	// No delta key at all in the payload (an old hub): the button is disabled and nothing warns.
	$none = gs111dt_note( gs111dt_panel( gs111dt_payload() ) );
	restore_error_handler();
	gs111dt_assert( 0 === $warn, 'bs-no-warn', 'a payload without migration.delta renders without a PHP warning', (string) $warn );
	gs111dt_assert( (bool) preg_match( '#<button type="button" class="gs-mship-action-btn is-secondary" data-gs-delta="1" disabled>Catch up changes</button>#', $none ), 'bs-missing-disabled', 'no delta key: the button is disabled' );
	$off = gs111dt_note( gs111dt_panel( gs111dt_payload( array( 'delta' => array( 'available' => false, 'state' => 'idle' ) ) ) ) );
	gs111dt_assert( (bool) preg_match( '#data-gs-delta="1" disabled>Catch up changes</button>#', $off ), 'bs-unavailable', 'available false: disabled' );
	$on = gs111dt_note( gs111dt_panel( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) ) );
	gs111dt_assert( (bool) preg_match( '#<button type="button" class="gs-mship-action-btn is-secondary" data-gs-delta="1">Catch up changes</button>#', $on ), 'bs-enabled', 'available true and idle: enabled' );
	gs111dt_assert( false !== strpos( $on, 'data-gs-delta-msg="1" data-gs-delta-state="idle"' ), 'bs-msg-slot', 'the one status line slot is in the same note' );
	$busy = gs111dt_note( gs111dt_panel( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'applying' ) ) ) ) );
	gs111dt_assert( false !== strpos( $busy, 'data-gs-delta="1" disabled' ) && false !== strpos( $busy, 'data-gs-delta-state="applying"' ), 'bs-busy', 'a running catch-up keeps the button disabled and restores the state on load' );
	$done = gs111dt_note( gs111dt_panel( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'done' ) ) ) ) );
	gs111dt_assert( false === strpos( $done, 'data-gs-delta="1" disabled' ) && false !== strpos( $done, 'data-gs-delta-state="done"' ), 'bs-done', 'after done the button is enabled again' );
	$junk = gs111dt_note( gs111dt_panel( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => '"><script>x</script>' ) ) ) ) );
	gs111dt_assert( false === strpos( $junk, '<script>x' ), 'bs-escaped', 'a hostile state value is never printed raw' );
	// Outside Preview there is no note and no button.
	$live = gs111dt_panel( gs111dt_payload( array( 'live' => true, 'preview' => false, 'index' => 5, 'state' => 'complete', 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	gs111dt_assert( false === strpos( $live, 'data-gs-delta' ), 'bs-live-hidden', 'after Live: no Catch up button' );
	$idle = gs111dt_panel( gs111dt_payload( array( 'preview' => false, 'state' => 'idle', 'index' => 0, 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	gs111dt_assert( false === strpos( $idle, 'data-gs-delta' ), 'bs-idle-hidden', 'not in Preview: no Catch up button' );
	$pv = gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) );
	gs111dt_assert( false === strpos( gs111dt_panel( $pv, gs111dt_subscriber() ), 'data-gs-delta' ), 'bs-non-admin', 'a subscriber sees no button' );
	gs111dt_assert( false === strpos( gs111dt_panel( $pv, 0 ), 'data-gs-delta' ), 'bs-visitor', 'a logged-out visitor sees no button' );
	// The origin only: an unpaired install (no token) renders no control.
	delete_option( 'gend_society_install_token' );
	gs111dt_assert( false === strpos( gs111dt_panel( $pv ), 'data-gs-delta' ), 'bs-unpaired', 'an unpaired install shows no button' );
};

$S['start-ajax'] = function () {
	$st = array( 'job' => GS111DT_JOB, 'state' => 'idle' );
	gs111dt_pair( gs111dt_hub( $st ) );
	gs111dt_store( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	gs111dt_base( time() + 3600 );
	if ( ! gs111dt_assert( has_action( 'wp_ajax_gend_society_migration_delta' ) && has_action( 'wp_ajax_gend_society_migration_delta_state' ), 'sa-registered', 'both delta actions are registered on wp_ajax_' ) ) {
		return;
	}
	gs111dt_assert( ! has_action( 'wp_ajax_nopriv_gend_society_migration_delta' ) && ! has_action( 'wp_ajax_nopriv_gend_society_migration_delta_state' ), 'sa-no-nopriv', 'no wp_ajax_nopriv_ registration' );
	$received = array();
	$listener = function ( $job ) use ( &$received ) {
		$received[] = $job;
	};
	add_action( 'gend_society_migration_job_received', $listener, 5 );
	gs111dt_on_cleanup(
		function () use ( $listener ) {
			remove_action( 'gend_society_migration_job_received', $listener, 5 );
		}
	);
	// Nonce and capability.
	$r = gs111dt_ajax( 'delta', 1, '' );
	gs111dt_assert( '-1' === $r['died'] && ! gs111dt_calls( 'POST', 'migration/delta/start' ), 'sa-no-nonce', 'no nonce: refused, no hub call', wp_json_encode( $r ) );
	$r = gs111dt_ajax( 'delta', 1, 'deadbeef00' );
	gs111dt_assert( '-1' === $r['died'] && ! gs111dt_calls( 'POST', 'migration/delta/start' ), 'sa-bad-nonce', 'wrong nonce: refused, no hub call' );
	$sub = gs111dt_subscriber();
	wp_set_current_user( $sub );
	$r = gs111dt_ajax( 'delta', $sub, wp_create_nonce( 'gs_membership_action' ) );
	gs111dt_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'Forbidden.' === ( $r['body']['data']['message'] ?? '' ) && ! gs111dt_calls( 'POST', 'migration/delta/start' ), 'sa-no-cap', 'subscriber with a valid nonce: Forbidden, no hub call' );
	$r = gs111dt_ajax( 'delta', 0, wp_create_nonce( 'gs_membership_action' ) );
	gs111dt_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'Forbidden.' === ( $r['body']['data']['message'] ?? '' ) && ! gs111dt_calls( 'POST', 'migration/delta/start' ), 'sa-logged-out', 'logged out: Forbidden, no hub call' );
	gs111dt_assert( ! $received && false === get_option( 'gend_society_migration_job', false ), 'sa-nothing-stored', 'the refusals stored nothing and started nothing' );
	// The hub refuses (capability off).
	wp_set_current_user( 1 );
	$nonce          = gs111dt_nonce();
	$st['start_error'] = true;
	$r              = gs111dt_ajax( 'delta', 1, $nonce );
	gs111dt_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && false !== strpos( (string) ( $r['body']['data']['message'] ?? '' ), 'not available yet' ) && ! gs111dt_calls( 'GET', 'migration/delta/state' ) && false === get_option( 'gend_society_migration_job', false ), 'sa-hub-refuses', 'a hub 409 surfaces its message; no state call, no job stored', wp_json_encode( $r ) );
	$st['start_error'] = false;
	// The state answer carries no secret: nothing is started.
	$st['no_secret']      = true;
	$GLOBALS['gs111dt']['http'] = array();
	$r = gs111dt_ajax( 'delta', 1, $nonce );
	gs111dt_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && false === get_option( 'gend_society_migration_job', false ) && ! $received, 'sa-no-secret', 'no secret from the hub: an error and no job', wp_json_encode( $r ) );
	$st['no_secret'] = false;
	$st['state']     = 'idle';
	// Success.
	$GLOBALS['gs111dt']['http'] = array();
	$before                     = get_option( 'gend_society_migration_state_last', null );
	$r                          = gs111dt_ajax( 'delta', 1, $nonce );
	gs111dt_assert( is_array( $r['body'] ) && ! empty( $r['body']['success'] ), 'sa-ok', 'a valid admin request succeeds', wp_json_encode( $r ) );
	gs111dt_assert( false === strpos( (string) $r['raw'], GS111DT_SECRET ), 'sa-no-secret-out', 'the answer to the browser never carries the export secret' );
	$paths = array_map( function ( $c ) { return $c['method'] . ' ' . $c['path']; }, array_values( array_filter( $GLOBALS['gs111dt']['http'], function ( $c ) { return 0 === strpos( $c['path'], 'migration/' ); } ) ) );
	gs111dt_assert( array( 'POST migration/delta/start', 'GET migration/delta/state' ) === $paths, 'sa-calls', 'exactly delta/start then delta/state (the full migration/state is not used)', wp_json_encode( $paths ) );
	$start = gs111dt_calls( 'POST', 'migration/delta/start' );
	gs111dt_assert( 1 === count( $start ) && false === strpos( $start[0]['body'], GS111DT_SECRET ), 'sa-start-body', 'one POST start, its body carries no secret' );
	$job = get_option( 'gend_society_migration_job', null );
	gs111dt_assert( is_array( $job ) && GS111DT_JOB === ( $job['job_id'] ?? '' ) && 'delta' === ( $job['mode'] ?? '' ) && GS111DT_SECRET === ( $job['export_secret'] ?? '' ) && (int) ( $job['received_at'] ?? 0 ) > 0, 'sa-receipt', 'receipt {job_id, mode delta, export_secret from delta/state, received_at} in the job option' );
	gs111dt_assert( 1 === count( $received ) && 'delta' === ( $received[0]['mode'] ?? '' ), 'sa-event', 'gend_society_migration_job_received fired once with mode delta' );
	gs111dt_assert( get_option( 'gend_society_migration_state_last', null ) === $before, 'sa-state-last', 'the delta state did not overwrite the full migration state record' );
	gs111dt_assert( false === gs111dt_debug_log_has( GS111DT_SECRET ), 'sa-not-logged', 'the secret is not in debug.log' );
	// A repeated receipt of the same job and secret is idempotent.
	$received = array();
	gend_society_migration_receive( array( 'job_id' => GS111DT_JOB, 'export_secret' => GS111DT_SECRET, 'mode' => 'delta' ) );
	gs111dt_assert( ! $received, 'sa-idempotent', 'receiving the same job and secret again does not restart the exporter' );
};

$S['job-mode-delta'] = function () {
	$st = array( 'job' => GS111DT_JOB, 'state' => 'idle' );
	gs111dt_pair( gs111dt_hub( $st ) );
	gs111dt_store( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	gs111dt_base( time() - 3600 );
	$r = gs111dt_ajax( 'delta', 1, gs111dt_nonce() );
	gs111dt_assert( ! empty( $r['body']['success'] ), 'jm-started', 'the catch-up started', wp_json_encode( $r ) );
	$job = gend_society_migration_export_job();
	gs111dt_assert( is_array( $job ) && 'delta' === ( $job['mode'] ?? '' ), 'jm-mode', 'the exporter job runs in mode delta' );
	gs111dt_assert( 'db' === ( $job['phase'] ?? '' ), 'jm-phase', 'it starts in the db phase', (string) ( $job['phase'] ?? '' ) );
	gs111dt_assert( GS111DT_BASEJOB === ( $job['base']['job_id'] ?? '' ) && ! empty( $job['base']['watermark']['taken_at_gmt'] ), 'jm-base', 'the anchor is the stored base export' );
	gs111dt_assert( GS111DT_JOB === ( $job['job_id'] ?? '' ) && GS111DT_SECRET === ( $job['export_secret'] ?? '' ), 'jm-ids', 'the hub job id and secret are kept in the job' );
	gs111dt_assert( (bool) wp_next_scheduled( 'gend_society_migration_export_tick' ), 'jm-tick', 'the export tick is scheduled' );
	gs111dt_assert( function_exists( 'gend_society_migration_export_active' ) && gend_society_migration_export_active(), 'jm-active', 'the export is active (the open tab drives it)' );
	// A job id that is not the hub's uuid shape or a short secret is not accepted by the receipt.
	gs111dt_wipe_job();
	$did = gend_society_migration_receive( array( 'job_id' => 'not-a-job', 'export_secret' => GS111DT_SECRET, 'mode' => 'delta' ) );
	gs111dt_assert( '' === $did && false === get_option( 'gend_society_migration_job', false ), 'jm-bad-id', 'a malformed job id is ignored' );
	// No base export: the catch-up fails at init with a friendly message and the status line shows it.
	delete_option( 'gend_society_migration_base' );
	$st['state'] = 'queued';
	gend_society_migration_receive( array( 'job_id' => GS111DT_JOB, 'export_secret' => GS111DT_SECRET, 'mode' => 'delta' ) );
	$job = gend_society_migration_export_job();
	gs111dt_assert( is_array( $job ) && 'failed' === ( $job['phase'] ?? '' ) && false !== strpos( (string) ( $job['error'] ?? '' ), 'no earlier full export' ), 'jm-no-base', 'without a base export the job fails with the friendly message' );
};

$S['ready-report'] = function () {
	global $wpdb;
	$st = array( 'job' => GS111DT_JOB, 'state' => 'idle', 'ready' => 200 );
	gs111dt_pair( gs111dt_hub( $st ) );
	gs111dt_store( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	$nonce = gs111dt_nonce();
	// 1. Nothing changed since the base: empty flag true.
	gs111dt_base( time() + 3600 );
	$r = gs111dt_ajax( 'delta', 1, $nonce );
	gs111dt_assert( ! empty( $r['body']['success'] ), 'rr-started', 'catch-up started', wp_json_encode( $r ) );
	$GLOBALS['gs111dt']['http'] = array();
	$res                        = gs111dt_run();
	gs111dt_assert( 'done' === $res['status'], 'rr-export-done', 'the delta export reaches ready', wp_json_encode( $res ) );
	$ready = gs111dt_calls( 'POST', 'migration/delta/ready' );
	gs111dt_assert( 1 === count( $ready ), 'rr-one-ready', 'exactly one POST to migration/delta/ready', (string) count( $ready ) );
	gs111dt_assert( ! gs111dt_calls( '', 'migration/progress' ), 'rr-no-progress', 'a delta never uses migration/progress' );
	$b = $ready ? json_decode( $ready[0]['body'], true ) : array();
	gs111dt_assert( GS111DT_JOB === ( $b['job_id'] ?? '' ) && true === ( $b['ready'] ?? null ) && array_key_exists( 'empty', $b ) && true === $b['empty'], 'rr-body', 'body: job_id, ready, empty true for nothing changed', wp_json_encode( array_diff_key( (array) $b, array( 'urls' => 1, 'alt_urls' => 1 ) ) ) );
	$urls_ok = is_array( $b['urls'] ?? null ) && 4 === count( $b['urls'] ) && is_array( $b['alt_urls'] ?? null ) && 4 === count( $b['alt_urls'] );
	foreach ( array_merge( (array) ( $b['urls'] ?? array() ), (array) ( $b['alt_urls'] ?? array() ) ) as $u ) {
		$urls_ok = $urls_ok && is_string( $u ) && 0 === strpos( $u, 'http' ) && false === strpos( $u, GS111DT_SECRET );
	}
	gs111dt_assert( $urls_ok && array( 'db', 'users', 'uploads', 'manifest' ) === array_keys( (array) ( $b['urls'] ?? array() ) ), 'rr-urls', 'four signed URLs and four alternates (db, users, uploads, manifest), none containing the secret' );
	gs111dt_assert( isset( $b['alt_base'] ) && false !== strpos( (string) $b['alt_base'], 'rest_route' ), 'rr-alt-base', 'alt_base is sent' );
	gs111dt_assert( false === strpos( $ready ? $ready[0]['body'] : '', GS111DT_SECRET ) && false === strpos( $ready ? $ready[0]['url'] : '', GS111DT_SECRET ), 'rr-secret-not-sent', 'the export secret itself is never in the report' );
	$job = gend_society_migration_export_job();
	gs111dt_assert( ! empty( $job['ready_reported_at'] ) && 'ready' === $job['phase'], 'rr-reported', 'ready_reported_at is set' );
	gend_society_migration_report_pending();
	gs111dt_assert( 1 === count( gs111dt_calls( 'POST', 'migration/delta/ready' ) ), 'rr-once', 'a repeat does not report again' );
	$man = gs111dt_manifest_of( $job );
	gs111dt_assert( 'delta' === ( $man['mode'] ?? '' ) && ! empty( $man['empty'] ), 'rr-manifest-empty', 'the delta manifest says empty' );
	gs111dt_assert( false === gs111dt_debug_log_has( GS111DT_SECRET ), 'rr-not-logged', 'the secret is not in debug.log' );
	// 2. A new post since the base: empty flag false.
	gs111dt_wipe_job();
	gs111dt_base( time() - 3600 );
	$pid = wp_insert_post( array( 'post_title' => 'gs111dt catch-up fixture', 'post_content' => 'x', 'post_status' => 'publish' ), true );
	gs111dt_on_cleanup(
		function () use ( $pid ) {
			wp_delete_post( (int) $pid, true );
		}
	);
	$st['state']                = 'idle';
	$GLOBALS['gs111dt']['http'] = array();
	$r                          = gs111dt_ajax( 'delta', 1, $nonce );
	$res                        = gs111dt_run();
	$ready                      = gs111dt_calls( 'POST', 'migration/delta/ready' );
	$b                          = $ready ? json_decode( $ready[0]['body'], true ) : array();
	gs111dt_assert( 'done' === $res['status'] && 1 === count( $ready ) && false === ( $b['empty'] ?? null ), 'rr-not-empty', 'a post added since the base: empty false', wp_json_encode( $res ) );
	// 3. gend.me refuses (409): the job stops, as for the full export.
	gs111dt_wipe_job();
	gs111dt_base( time() + 3600 );
	$st['state']                = 'idle';
	$st['ready']                = 409;
	$GLOBALS['gs111dt']['http'] = array();
	gs111dt_ajax( 'delta', 1, $nonce );
	$res = gs111dt_run();
	$job = gend_society_migration_export_job();
	gs111dt_assert( 'failed' === $res['status'] && 'failed' === ( $job['phase'] ?? '' ) && false !== strpos( (string) ( $job['error'] ?? '' ), '(409)' ), 'rr-409-stops', 'a 409 from the delta route stops the job', wp_json_encode( $res ) );
	gs111dt_assert( 1 === count( gs111dt_calls( 'POST', 'migration/delta/ready' ) ), 'rr-409-once', 'and it is not retried' );
	gs111dt_assert( false === gs111dt_debug_log_has( GS111DT_SECRET ), 'rr-409-not-logged', 'the secret is not in debug.log after a refusal either' );
};

function gs111dt_manifest_of( array $job ) {
	$m = json_decode( (string) @file_get_contents( $job['staging'] . '/' . ( $job['manifest']['file'] ?? 'none' ) ), true );
	return is_array( $m ) ? $m : array();
}

$S['state-poll'] = function () {
	$st = array( 'job' => GS111DT_JOB, 'state' => 'idle' );
	gs111dt_pair( gs111dt_hub( $st ) );
	gs111dt_store( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	gs111dt_base( time() + 3600 );
	$nonce = gs111dt_nonce();
	$last  = 1790000000;
	$when  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last );
	$line  = function ( array $over ) use ( &$st, $nonce ) {
		$st = array_merge( array( 'job' => GS111DT_JOB, 'state' => 'idle', 'counts' => array(), 'empty' => false, 'error' => '', 'last_at' => 0 ), $over );
		$r  = gs111dt_ajax( 'delta_state', 1, $nonce );
		return $r;
	};
	$r = $line( array( 'state' => 'idle' ) );
	gs111dt_assert( ! empty( $r['body']['success'] ) && '' === $r['body']['data']['line'] && false === $r['body']['data']['poll'], 'sp-idle', 'idle: no line, no polling', wp_json_encode( $r ) );
	// A queued delta whose receipt is stored locally.
	gs111dt_wipe_job();
	gend_society_migration_receive( array( 'job_id' => GS111DT_JOB, 'export_secret' => GS111DT_SECRET, 'mode' => 'delta' ) );
	$r = $line( array( 'state' => 'queued' ) );
	gs111dt_assert( 'Catch-up: exporting your changes...' === ( $r['body']['data']['line'] ?? '' ) && true === ( $r['body']['data']['poll'] ?? null ), 'sp-queued', 'queued: "exporting your changes", keeps polling' );
	gs111dt_assert( false === strpos( (string) $r['raw'], GS111DT_SECRET ) && ! array_key_exists( 'export_secret', (array) ( $r['body']['data'] ?? array() ) ), 'sp-no-secret-out', 'the poll answer never carries the secret' );
	$r = $line( array( 'state' => 'exporting' ) );
	gs111dt_assert( 'Catch-up: exporting your changes...' === ( $r['body']['data']['line'] ?? '' ) && true === ( $r['body']['data']['poll'] ?? null ), 'sp-exporting', 'exporting: same line, keeps polling' );
	$r = $line( array( 'state' => 'applying' ) );
	gs111dt_assert( 'Catch-up: applying to your preview...' === ( $r['body']['data']['line'] ?? '' ) && true === ( $r['body']['data']['poll'] ?? null ), 'sp-applying', 'applying: "applying to your preview", keeps polling' );
	$r = $line( array( 'state' => 'done', 'last_at' => $last, 'counts' => array( 'wp_posts' => 3, 'wp_comments' => 1, 'conflicts' => 0, 'uploads_new' => 2, 'uploads_renamed' => 0, 'uploads_same' => 4 ) ) );
	gs111dt_assert( 'Done: 4 items and 2 files copied at ' . $when === ( $r['body']['data']['line'] ?? '' ) && false === ( $r['body']['data']['poll'] ?? null ), 'sp-done', 'done: rows and files copied with the local time; polling stops', (string) ( $r['body']['data']['line'] ?? '' ) );
	$r = $line( array( 'state' => 'done', 'last_at' => $last, 'empty' => true, 'counts' => array( 'conflicts' => 0, 'uploads_same' => 4 ) ) );
	gs111dt_assert( 0 === strpos( (string) ( $r['body']['data']['line'] ?? '' ), 'Done: nothing new to copy' ) && false === ( $r['body']['data']['poll'] ?? null ), 'sp-done-empty', 'done with nothing changed says so' );
	$r = $line( array( 'state' => 'failed', 'error' => 'Your preview could not be updated. Nothing was changed.' ) );
	gs111dt_assert( 'Catch-up failed: Your preview could not be updated. Nothing was changed.' === ( $r['body']['data']['line'] ?? '' ) && false === ( $r['body']['data']['poll'] ?? null ), 'sp-failed', 'failed: the friendly error; polling stops' );
	$r = $line( array( 'state' => 'failed', 'error' => '' ) );
	gs111dt_assert( 'Catch-up failed. Please try again.' === ( $r['body']['data']['line'] ?? '' ), 'sp-failed-generic', 'failed without a message still reads well' );
	// The local export failed (here: no base export) while the hub still waits.
	gs111dt_wipe_job();
	delete_option( 'gend_society_migration_base' );
	gend_society_migration_receive( array( 'job_id' => GS111DT_JOB, 'export_secret' => GS111DT_SECRET, 'mode' => 'delta' ) );
	$r = $line( array( 'state' => 'queued' ) );
	gs111dt_assert( 0 === strpos( (string) ( $r['body']['data']['line'] ?? '' ), 'Catch-up failed: There is no earlier full export' ) && false === ( $r['body']['data']['poll'] ?? null ), 'sp-local-failure', 'a failed local export shows its own message and stops polling' );
	// Repair: the hub still waits (queued) and the local receipt is gone: the re-sent secret restores it.
	gs111dt_wipe_job();
	gs111dt_base( time() + 3600 );
	$GLOBALS['gs111dt']['http'] = array();
	$r                          = $line( array( 'state' => 'queued' ) );
	$job                        = get_option( 'gend_society_migration_job', null );
	gs111dt_assert( is_array( $job ) && GS111DT_JOB === ( $job['job_id'] ?? '' ) && 'delta' === ( $job['mode'] ?? '' ) && GS111DT_SECRET === ( $job['export_secret'] ?? '' ), 'sp-repair', 'a missing local receipt is rebuilt from the secret the hub re-sends while queued' );
	gs111dt_assert( 'delta' === ( gend_society_migration_export_job()['mode'] ?? '' ), 'sp-repair-mode', 'and the exporter restarts in mode delta' );
	// JS: polling stops when the answer says so; the tab drives the exporter only while it is active.
	$js = (string) file_get_contents( GEND_SOCIETY_DIR . 'assets/gs-dashboard-standalone.js' );
	gs111dt_assert( false !== strpos( $js, "migPost('gend_society_migration_delta_state'" ) && (bool) preg_match( '/else\s*\{\s*deltaStopPoll\(\);/', $js ) && false !== strpos( $js, 'window.setInterval(deltaRefresh, 15000)' ), 'sp-js-poll', 'the header polls delta/state every 15 s and stops when poll is false' );
	gs111dt_assert( false !== strpos( $js, 'deltaFreeze' ) && false !== strpos( $js, 'window.confirm(i18n.deltaFreeze' ), 'sp-js-confirm', 'the click asks the freeze warning first' );
};

$S['refuse-outside-preview'] = function () {
	$st = array( 'job' => GS111DT_JOB, 'state' => 'idle' );
	gs111dt_pair( gs111dt_hub( $st ) );
	gs111dt_base( time() + 3600 );
	$nonce = gs111dt_nonce();
	foreach (
		array(
			'live'    => array( 'live' => true, 'preview' => false, 'index' => 5, 'state' => 'complete' ),
			'idle'    => array( 'preview' => false, 'index' => 0, 'state' => 'idle' ),
			'running' => array( 'preview' => false, 'index' => 2, 'state' => 'exporting' ),
		) as $name => $over
	) {
		gs111dt_store( gs111dt_payload( array_merge( $over, array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) ) );
		$GLOBALS['gs111dt']['http'] = array();
		$r                          = gs111dt_ajax( 'delta', 1, $nonce );
		gs111dt_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'not_preview' === ( $r['body']['data']['code'] ?? '' ) && ! gs111dt_calls( 'POST', 'migration/delta/start' ), 'rp-' . $name, $name . ': not_preview, no start call', wp_json_encode( $r ) );
		gs111dt_assert( false === get_option( 'gend_society_migration_job', false ), 'rp-' . $name . '-nojob', $name . ': no job stored' );
	}
	// Preview but this install is not the paired origin (no token): refused too.
	gs111dt_store( gs111dt_payload( array( 'delta' => array( 'available' => true, 'state' => 'idle' ) ) ) );
	delete_option( 'gend_society_install_token' );
	$GLOBALS['gs111dt']['http'] = array();
	$r                          = gs111dt_ajax( 'delta', 1, $nonce );
	gs111dt_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'not_preview' === ( $r['body']['data']['code'] ?? '' ) && ! gs111dt_calls(), 'rp-unpaired', 'unpaired: not_preview and no hub call at all', wp_json_encode( $r ) );
};

$S['full-path-unchanged'] = function () {
	$st = array( 'job' => GS111DT_JOB2, 'state' => 'idle' );
	gs111dt_pair( gs111dt_hub( $st ) );
	gs111dt_wipe_job();
	delete_option( 'gend_society_migration_base' );
	$did = gend_society_migration_receive( array( 'job_id' => GS111DT_JOB2, 'export_secret' => GS111DT_SECRET, 'state' => 'exporting' ) );
	$rec = get_option( 'gend_society_migration_job', null );
	gs111dt_assert( 'received' === $did && is_array( $rec ) && array( 'job_id', 'export_secret', 'received_at' ) === array_slice( array_keys( $rec ), 0, 3 ) && 'full' === ( $rec['mode'] ?? '' ), 'fp-receipt', 'a normal receipt keeps its shape (job_id, export_secret, received_at); the exporter marks it full' );
	$job = gend_society_migration_export_job();
	gs111dt_assert( is_array( $job ) && 'full' === ( $job['mode'] ?? '' ) && 'db' === ( $job['phase'] ?? '' ) && array_key_exists( 'base', $job ) && null === $job['base'], 'fp-full-job', 'it builds a FULL job (no base) in the db phase' );
	gs111dt_assert( 'exporting' === ( gend_society_migration_state_last()['state'] ?? '' ), 'fp-state', 'and the full state record is still stored' );
	// An answer that says mode delta only counts for a catch-up: a plain answer with a stray key does not.
	$GLOBALS['gs111dt']['http'] = array();
	$res                        = gs111dt_run();
	gs111dt_assert( 'done' === $res['status'], 'fp-ready', 'the full export reaches ready', wp_json_encode( $res ) );
	$prog = gs111dt_calls( 'POST', 'migration/progress' );
	gs111dt_assert( count( $prog ) >= 1 && ! gs111dt_calls( '', 'migration/delta/ready' ), 'fp-progress-route', 'a full export reports through migration/progress (' . count( $prog ) . ' report(s)) and never through the delta route' );
	$last = $prog ? json_decode( $prog[ count( $prog ) - 1 ]['body'], true ) : array();
	gs111dt_assert( true === ( $last['ready'] ?? null ) && 4 === count( (array) ( $last['urls'] ?? array() ) ), 'fp-ready-body', 'the last report is the ready report with the four URLs' );
	$m = gs111dt_manifest_of( gend_society_migration_export_job() );
	gs111dt_assert( empty( $m['mode'] ) || 'full' === $m['mode'], 'fp-manifest', 'the manifest is the full one' );
	gs111dt_assert( is_array( get_option( 'gend_society_migration_base', null ) ), 'fp-base', 'a full export still records the base for later catch-ups' );
};

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs111dt_args = isset( $args ) ? (array) $args : array();
$gs111dt_want = array();
foreach ( $gs111dt_args as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs111dt_want[] = trim( $s );
		}
	}
}
if ( ! $gs111dt_want ) {
	$gs111dt_want = array_keys( $S );
}
echo 'INFO: delta-trigger on ' . home_url() . ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?' ) . ' sections=' . implode( ',', $gs111dt_want ) . "\n";
foreach ( $gs111dt_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs111dt_assert( false, $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs111dt_assert( false, $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs111dt_cleanup();
	}
}
$gs111dt_f = (int) $GLOBALS['gs111dt']['fail'];
$gs111dt_p = (int) $GLOBALS['gs111dt']['pass'];
echo ( 0 === $gs111dt_f && $gs111dt_p > 0 ) ? "ALL PASS ({$gs111dt_p})\n" : "FAILURES: {$gs111dt_f}\n";
