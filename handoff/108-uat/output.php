<?php
/**
 * Section output (plan 108-09; modes hub and container). gs108-readonly: yes
 *
 * The Connect screen and its submit handler must stay byte-identical to 1.3.1 on the gend.me hub
 * and on containers. The 1.3.1 file (origin/main inc/portal-connect.php, md5 pinned below) is loaded
 * into this process under renamed functions (gend_society_portal_connect_* -> gs108old_portal_connect_*,
 * hooks stripped) and both versions run against identical state:
 *   - renders: current state, not connected, connected, notices, a /pair code and the 108 query args;
 *   - handlers (not READ-ONLY): disconnect, pairing success / hub error / network error / missing code,
 *     the 108 ops posted to a 1.3.1-mode install, bad nonce. Redirect, every gend_society_* option
 *     afterwards, the option names written and the outbound requests must be identical.
 * READ-ONLY (GS108_READONLY=1, used on live in 108-15): renders the current state only, compares it with
 * the 1.3.1 render in-process and, when env GS108_OUTPUT_BASELINE is set, with that md5 of the
 * normalised render (nonce + referer values masked); never calls a handler, never writes.
 * Baseline file: env GS108_OUTPUT_BASELINE_FILE or /tmp/gs108-baseline/portal-connect-1.3.1.php
 * (the runner copies it there from `git show origin/main:inc/portal-connect.php`).
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_connect.php';

$gs108o_mode = gs108_mode();
if ( 'standalone' === $gs108o_mode ) {
	gs108_assert( function_exists( 'gend_society_portal_connect_standalone_ready' ) && gend_society_portal_connect_standalone_ready(), 'output-standalone', 'standalone uses the 108 screen (output parity applies to hub/container only)' );
	return;
}

// --- Load the 1.3.1 baseline under renamed functions.
$gs108o_file = (string) getenv( 'GS108_OUTPUT_BASELINE_FILE' );
if ( '' === $gs108o_file ) {
	$gs108o_file = '/tmp/gs108-baseline/portal-connect-1.3.1.php';
}
$gs108o_src = is_readable( $gs108o_file ) ? (string) file_get_contents( $gs108o_file ) : '';
if ( ! gs108_assert( 'efbe9766d00dda8f87a38e90dc432d41' === md5( $gs108o_src ), 'output-baseline-file', '1.3.1 portal-connect.php baseline present (md5 efbe9766... = origin/main cdc97b9)', $gs108o_file . ' md5 ' . md5( $gs108o_src ) ) ) {
	return;
}
if ( ! function_exists( 'gs108old_portal_connect_render_page' ) ) {
	$gs108o_code = preg_replace( '/^<\?php/', '', $gs108o_src );
	$gs108o_code = preg_replace( '/^add_action\(.*\);[ \t]*$/m', '', $gs108o_code );
	// Only function names (definitions and calls) are renamed; the admin-post action value in the HTML stays.
	$gs108o_code = preg_replace( '/\bgend_society_portal_connect_(\w+)(\s*\()/', 'gs108old_portal_connect_$1$2', $gs108o_code );
	eval( $gs108o_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test harness: the pinned 1.3.1 file under renamed functions.
}
gs108_assert( function_exists( 'gs108old_portal_connect_render_page' ) && function_exists( 'gs108old_portal_connect_handle_submit' ), 'output-baseline-loaded', '1.3.1 render + handler loaded as gs108old_*' );
gs108_assert( ! gend_society_portal_connect_standalone_ready() && ! function_exists( 'gend_society_connect_handle_start' ), 'output-not-standalone', 'standalone branch inactive, connect-flow not loaded (' . $gs108o_mode . ')' );

// Admin to render as.
$gs108o_uid = 0;
if ( is_multisite() ) {
	foreach ( get_super_admins() as $gs108o_login ) {
		$gs108o_u = get_user_by( 'login', $gs108o_login );
		if ( $gs108o_u ) {
			$gs108o_uid = (int) $gs108o_u->ID;
			break;
		}
	}
} else {
	$gs108o_uid = gs108c_admin_id();
}
wp_set_current_user( $gs108o_uid );
gs108_assert( current_user_can( 'manage_options' ), 'output-admin', 'rendering as user ' . $gs108o_uid . ' with manage_options' );
gs108c_setup();
gs108_http_record();

$gs108o_render = function ( $fn, array $get ) {
	$_GET     = wp_slash( array_merge( array( 'page' => 'gs-portal-connect' ), $get ) );
	$_REQUEST = $_GET;
	ob_start();
	try {
		call_user_func( $fn );
	} catch ( \Throwable $e ) {
		echo 'THREW ' . get_class( $e ) . ': ' . $e->getMessage(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test output.
	} finally {
		$html     = (string) ob_get_clean();
		$_GET     = array();
		$_REQUEST = array();
	}
	return $html;
};
$gs108o_norm = function ( $html ) {
	$html = preg_replace( '/(name="(?:_wpnonce|gs_portal_connect_nonce)" value=")[0-9a-f]+/', '$1NONCE', $html );
	return preg_replace( '/(name="_wp_http_referer" value=")[^"]*/', '$1REFERER', $html );
};
$gs108o_compare = function ( $id, array $get ) use ( $gs108o_render ) {
	$old = $gs108o_render( 'gs108old_portal_connect_render_page', $get );
	$new = $gs108o_render( 'gend_society_portal_connect_render_page', $get );
	$at = 0;
	while ( $at < strlen( $old ) && $at < strlen( $new ) && $old[ $at ] === $new[ $at ] ) {
		++$at;
	}
	gs108_assert( '' !== $old && $old === $new, $id, 'render byte-identical to 1.3.1 (' . strlen( $new ) . ' bytes, md5 ' . md5( $new ) . ')', 'old md5 ' . md5( $old ) . ' new md5 ' . md5( $new ) . ' first difference at byte ' . $at . ': old ' . wp_json_encode( substr( $old, max( 0, $at - 60 ), 120 ) ) . ' new ' . wp_json_encode( substr( $new, max( 0, $at - 60 ), 120 ) ) );
	return $new;
};

// Enqueue: the 108 card CSS never loads here.
gend_society_portal_connect_enqueue( 'dashboard_page_gs-portal-connect' );
gs108_assert( ! wp_style_is( 'gend-society-connect', 'enqueued' ) && ! wp_style_is( 'gend-society-connect', 'registered' ), 'output-no-css', 'gs-connect.css not enqueued on ' . $gs108o_mode );

// --- Render of the current state (READ-ONLY safe).
$gs108o_now = $gs108o_compare( 'output-render-current', array() );
$gs108o_md5 = md5( $gs108o_norm( $gs108o_now ) );
gs108_info( 'normalised md5 of the current render: ' . $gs108o_md5 );
$gs108o_env = (string) getenv( 'GS108_OUTPUT_BASELINE' );
if ( '' !== $gs108o_env ) {
	gs108_assert_eq( strtolower( trim( $gs108o_env ) ), $gs108o_md5, 'output-render-env-baseline', 'normalised render md5 equals GS108_OUTPUT_BASELINE' );
}
$gs108o_compare(
	'output-render-current-108-args',
	array(
		'gend_society_code'    => 'ABCDEF',
		'gs_disconnect_failed' => '1',
		'gs_connect_status'    => 'error',
		'gs_connect_message'   => 'x',
	)
);

if ( gs108_is_readonly() ) {
	gs108_assert_eq( array(), gs108_http_log(), 'output-readonly-no-request', 'no outbound request' );
	return;
}

// --- Full variant: identical states for both versions.
gs108_snapshot_options();
$gs108o_names = array( 'gend_society_install_id', 'gend_society_install_token', 'gend_society_gend_base_url', 'gend_society_gend_pubkey', 'gend_society_keypair', 'gend_society_connected_at', 'gend_society_features_cache', 'gend_society_features_cache_expires', 'gend_society_connection', 'gend_society_connection_health', 'gend_society_consent', 'gend_society_remote_consent', 'gend_society_connect_pending' );
$gs108o_kp     = base64_encode( sodium_crypto_sign_seed_keypair( str_repeat( "\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixed test key.
$gs108o_states = array(
	'not-connected' => array(
		'gend_society_install_id' => '0b9f8a3e-1c2d-4e5f-8a6b-7c8d9e0f1a2b',
		'gend_society_keypair'    => $gs108o_kp,
	),
	'connected'     => array(
		'gend_society_install_id'             => '0b9f8a3e-1c2d-4e5f-8a6b-7c8d9e0f1a2b',
		'gend_society_install_token'          => str_repeat( 'ab', 32 ),
		'gend_society_gend_base_url'          => 'https://gend.me',
		'gend_society_gend_pubkey'            => base64_encode( str_repeat( "\x01", 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test value.
		'gend_society_keypair'                => $gs108o_kp,
		'gend_society_connected_at'           => 1760000000,
		'gend_society_features_cache'         => array( 'x' => 1 ),
		'gend_society_features_cache_expires' => 1760000600,
		'gend_society_connection'             => array( 'owner_user_id' => 1, 'via' => 'pairing' ),
		'gend_society_connection_health'      => array( 'status' => 'unauthorized' ),
	),
);
$gs108o_set = function ( $state ) use ( $gs108o_names, $gs108o_states ) {
	foreach ( $gs108o_names as $o ) {
		delete_option( $o );
	}
	foreach ( $gs108o_states[ $state ] as $o => $v ) {
		update_option( $o, $v, false );
	}
	wp_cache_delete( 'alloptions', 'options' );
};
$gs108o_dump = function () {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%' ORDER BY option_name", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( (array) $rows as $r ) {
		// connected_at is time() at the moment of the call: mask it (asserted to be recent separately).
		$out[ $r['option_name'] ] = 'gend_society_connected_at' === $r['option_name'] ? 'T' : $r['option_value'];
	}
	return $out;
};

foreach ( array( 'not-connected', 'connected' ) as $gs108o_state ) {
	$gs108o_set( $gs108o_state );
	$gs108o_compare( "output-render-{$gs108o_state}", array() );
	$gs108o_compare( "output-render-{$gs108o_state}-success", array( 'gs_connect_status' => 'success', 'gs_connect_message' => 'Connected to gend.me.' ) );
	$gs108o_compare( "output-render-{$gs108o_state}-error", array( 'gs_connect_status' => 'error', 'gs_connect_message' => 'Bad <b>thing</b>' ) );
	$gs108o_compare(
		"output-render-{$gs108o_state}-108-args",
		array(
			'gend_society_code'    => 'ABCDEF',
			'gs_disconnect_failed' => '1',
		)
	);
}
gs108_assert_eq( array(), gs108_http_log(), 'output-render-no-request', 'no render made a request' );

/**
 * Run a handler from a state and capture everything observable.
 */
$gs108o_run = function ( $fn, $state, array $post, $fake = null ) use ( $gs108o_set, $gs108o_dump, $gs108o_uid ) {
	$gs108o_set( $state );
	wp_set_current_user( $gs108o_uid );
	$writes = array();
	$rec    = function ( $name ) use ( &$writes ) {
		if ( 0 === strpos( (string) $name, 'gend_society_' ) ) {
			$writes[] = current_action() . ':' . $name;
		}
	};
	foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $h ) {
		add_action( $h, $rec, 10, 1 );
	}
	$requests = array();
	$hub      = function ( $pre, $args, $url ) use ( &$requests, $fake ) {
		$requests[] = strtoupper( (string) ( $args['method'] ?? 'GET' ) ) . ' ' . $url . ' ' . (string) ( $args['body'] ?? '' ) . ' timeout=' . (string) ( $args['timeout'] ?? '' );
		if ( null === $fake ) {
			return new WP_Error( 'gs108_blocked', 'no request expected' );
		}
		if ( 0 === $fake[0] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: gs108 fake' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $fake[1] ),
			'response' => array(
				'code'    => $fake[0],
				'message' => 'x',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $hub, 10, 3 );
	$t0 = time();
	$r  = gs108c_call( $fn, 'POST', array(), $post );
	remove_filter( 'pre_http_request', $hub, 10 );
	foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $h ) {
		remove_action( $h, $rec, 10 );
	}
	$at = (int) get_option( 'gend_society_connected_at', 0 );
	return array(
		'result'   => $r,
		'options'  => $gs108o_dump(),
		'writes'   => $writes,
		'requests' => $requests,
		'at_ok'    => 0 === $at || 1760000000 === $at || ( $at >= $t0 && $at <= time() ),
	);
};
$gs108o_handler = function ( $id, $state, array $post, $fake = null ) use ( $gs108o_run ) {
	$old = $gs108o_run( 'gs108old_portal_connect_handle_submit', $state, $post, $fake );
	$new = $gs108o_run( 'gend_society_portal_connect_handle_submit', $state, $post, $fake );
	gs108_assert( $old['result'] === $new['result'], "{$id}-result", 'same redirect/die (' . $new['result']['kind'] . ' ' . substr( $new['result']['location'] . $new['result']['message'], 0, 90 ) . ')', wp_json_encode( array( $old['result'], $new['result'] ) ) );
	gs108_assert( $old['options'] === $new['options'], "{$id}-options", 'every gend_society_* option identical afterwards (' . count( $new['options'] ) . ' options)', wp_json_encode( array_keys( array_diff_assoc( $old['options'], $new['options'] ) + array_diff_assoc( $new['options'], $old['options'] ) ) ) );
	gs108_assert( $old['writes'] === $new['writes'], "{$id}-writes", 'same option writes in the same order (' . count( $new['writes'] ) . '), no new option touched', wp_json_encode( array( $old['writes'], $new['writes'] ) ) );
	gs108_assert( $old['requests'] === $new['requests'], "{$id}-requests", 'same outbound requests (' . count( $new['requests'] ) . ')', wp_json_encode( array( $old['requests'], $new['requests'] ) ) );
	gs108_assert( $new['at_ok'], "{$id}-connected-at", 'connected_at unchanged or set to now' );
	return $new;
};

$gs108o_nonce_d = wp_create_nonce( 'gs_portal_connect_disconnect' );
$gs108o_nonce_s = wp_create_nonce( 'gs_portal_connect_submit' );
$gs108o_pair    = array(
	'op'                      => 'connect',
	'gs_portal_connect_nonce' => $gs108o_nonce_s,
	'gend_base_url'           => 'https://gend.me',
	'pairing_code'            => 'ABC-DEF',
);

$gs108o_r = $gs108o_handler( 'output-h-disconnect', 'connected', array( 'op' => 'disconnect', 'gs_portal_connect_nonce' => $gs108o_nonce_d ) );
gs108_assert( array() === $gs108o_r['requests'] && ! isset( $gs108o_r['options']['gend_society_install_id'] ), 'output-h-disconnect-1-3-1', 'hub/container disconnect stays local-only (no request) and deletes install_id as 1.3.1 did' );
$gs108o_handler( 'output-h-disconnect-from-idle', 'not-connected', array( 'op' => 'disconnect', 'gs_portal_connect_nonce' => $gs108o_nonce_d ) );
$gs108o_handler( 'output-h-disconnect-bad-nonce', 'connected', array( 'op' => 'disconnect', 'gs_portal_connect_nonce' => 'bad' ) );
$gs108o_r = $gs108o_handler( 'output-h-pair-ok', 'not-connected', $gs108o_pair, array( 200, array( 'install_token' => 'tok-' . str_repeat( 'c', 60 ), 'install_id' => '0b9f8a3e-1c2d-4e5f-8a6b-7c8d9e0f1a2b', 'gend_signing_pubkey' => 'cGs=', 'group' => array( 'id' => 3, 'name' => 'G' ) ) ) );
gs108_assert( 1 === count( $gs108o_r['requests'] ) && false === strpos( $gs108o_r['requests'][0], 'plugin_version' ) && false === strpos( $gs108o_r['requests'][0], 'admin_email' ) && ! isset( $gs108o_r['options']['gend_society_connection'] ), 'output-h-pair-1-3-1-body', 'hub/container pairing body is the 1.3.1 body (no admin_email/plugin_version) and no connection option' );
$gs108o_handler( 'output-h-pair-connected', 'connected', $gs108o_pair, array( 200, array( 'install_token' => 'tok2', 'gend_signing_pubkey' => 'cGs=' ) ) );
$gs108o_handler( 'output-h-pair-403', 'not-connected', $gs108o_pair, array( 403, array( 'code' => 'invalid_code', 'message' => 'Pairing code invalid or expired.' ) ) );
$gs108o_handler( 'output-h-pair-422', 'not-connected', $gs108o_pair, array( 422, array( 'code' => 'update_required', 'message' => 'Update GenD Society to 1.4.0 or later (Dashboard > Updates), then connect again.' ) ) );
$gs108o_handler( 'output-h-pair-network', 'not-connected', $gs108o_pair, array( 0, array() ) );
$gs108o_handler( 'output-h-pair-missing-code', 'not-connected', array_merge( $gs108o_pair, array( 'pairing_code' => '' ) ) );
$gs108o_handler( 'output-h-pair-bad-nonce', 'not-connected', array_merge( $gs108o_pair, array( 'gs_portal_connect_nonce' => 'bad' ) ) );
foreach ( array( 'disconnect_local', 'transfer_owner', 'claim_owner' ) as $gs108o_op ) {
	$gs108o_handler(
		"output-h-op-{$gs108o_op}",
		'connected',
		array(
			'op'                                 => $gs108o_op,
			'gs_portal_connect_nonce'            => $gs108o_nonce_s,
			'gend_society_owner_nonce'           => wp_create_nonce( 'gend_society_transfer_owner' ),
			'gend_society_disconnect_local_nonce' => wp_create_nonce( 'gend_society_disconnect_local' ),
			'new_owner'                          => '1',
		)
	);
}
