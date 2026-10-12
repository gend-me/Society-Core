<?php
/**
 * Phase 108 plugin battery: helpers for the connect sections (consent, start, challenge, return).
 *
 * Handlers are driven in-process: redirects are captured through the wp_redirect filter and wp_die
 * through wp_die_handler (both throw GS108C_Stop), so `exit` is never reached. Requests to the hub
 * clone carry a random X-Forwarded-For (the clone honours it behind a private REMOTE_ADDR) so the
 * hub's per-IP rate buckets never interfere across cases. The test-only approve route on the clone
 * needs the secret in wp-content/gs108-approve.key (copied in by the runner; see hub/fixtures.txt).
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GS108C_Stop' ) ) {
	class GS108C_Stop extends Exception {
		public $kind = '';
		public $location = '';
		public $status = 0;
	}
}

function gs108c_hub() {
	return 'http://localhost:8918';
}

function gs108c_site() {
	return 'http://localhost:8908';
}

function gs108c_secret() {
	$f = WP_CONTENT_DIR . '/gs108-approve.key';
	return is_readable( $f ) ? trim( (string) file_get_contents( $f ) ) : '';
}

/** Install capture filters (redirect, wp_die, XFF on hub calls) for the rest of the section. */
function gs108c_setup() {
	$redir = function ( $location, $status ) {
		$e           = new GS108C_Stop( 'redirect' );
		$e->kind     = 'redirect';
		$e->location = (string) $location;
		$e->status   = (int) $status;
		throw $e;
	};
	$die_handler = function () {
		return function ( $message, $title = '', $args = array() ) {
			$e         = new GS108C_Stop( is_string( $message ) ? $message : ( is_wp_error( $message ) ? $message->get_error_message() : 'die' ) );
			$e->kind   = 'die';
			$e->status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
			throw $e;
		};
	};
	$xff = function ( $args, $url ) {
		if ( 0 === strpos( (string) $url, gs108c_hub() ) ) {
			$args['headers']                    = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$args['headers']['X-Forwarded-For'] = '198.51.100.' . wp_rand( 1, 254 ) . ', 10.0.0.1';
		}
		return $args;
	};
	// WP-CLI logs every redirect with a backtrace; the battery captures redirects itself.
	$cli_handler  = 'WP_CLI\Utils\wp_redirect_handler';
	$cli_priority = has_filter( 'wp_redirect', $cli_handler );
	if ( false !== $cli_priority ) {
		remove_filter( 'wp_redirect', $cli_handler, $cli_priority );
		gs108_on_cleanup(
			function () use ( $cli_handler, $cli_priority ) {
				add_filter( 'wp_redirect', $cli_handler, $cli_priority );
			},
			'wp-cli redirect handler'
		);
	}
	add_filter( 'wp_redirect', $redir, PHP_INT_MAX, 2 );
	add_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
	add_filter( 'http_request_args', $xff, 10, 2 );
	gs108_on_cleanup(
		function () use ( $redir, $die_handler, $xff ) {
			remove_filter( 'wp_redirect', $redir, PHP_INT_MAX );
			remove_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
			remove_filter( 'http_request_args', $xff, 10 );
			$_GET     = array();
			$_POST    = array();
			$_REQUEST = array();
			wp_set_current_user( 0 );
		},
		'gs108c filters'
	);
}

/** Make the install "not connected and no consent" for this section (options restored on cleanup). */
function gs108c_fresh() {
	foreach ( array( 'gend_society_install_token', 'gend_society_connection', 'gend_society_connect_pending', 'gend_society_consent', 'gend_society_remote_consent', 'gend_society_gend_base_url', 'gend_society_gend_pubkey', 'gend_society_connected_at', 'gend_society_connect_rate' ) as $o ) {
		delete_option( $o );
	}
}

/**
 * Call a handler with request globals; returns ['kind' => redirect|die|return, 'location', 'status', 'message'].
 */
function gs108c_call( callable $fn, $method, array $get = array(), array $post = array() ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_GET                      = wp_slash( $get );
	$_POST                     = wp_slash( $post );
	$_REQUEST                  = array_merge( $_GET, $_POST );
	$out                       = array(
		'kind'     => 'return',
		'location' => '',
		'status'   => 0,
		'message'  => '',
	);
	try {
		call_user_func( $fn );
	} catch ( GS108C_Stop $e ) {
		$out = array(
			'kind'     => $e->kind,
			'location' => $e->location,
			'status'   => $e->status,
			'message'  => $e->getMessage(),
		);
	} finally {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}
	wp_cache_delete( 'alloptions', 'options' );
	return $out;
}

/** Query args of a URL. */
function gs108c_query( $url ) {
	$q = array();
	parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $q );
	return $q;
}

/** Click Connect as $uid (reconnect when $reconnect). Returns the gs108c_call result + state/request. */
function gs108c_start( $uid, $reconnect = false, array $extra_post = array() ) {
	wp_set_current_user( $uid );
	$post = array_merge( array( '_wpnonce' => wp_create_nonce( 'gend_society_connect_start' ) ), $reconnect ? array( 'reconnect' => '1' ) : array(), $extra_post );
	$r    = gs108c_call( 'gend_society_connect_handle_start', 'POST', array(), $post );
	$q    = gs108c_query( $r['location'] );
	$r['state']   = isset( $q['state'] ) ? (string) $q['state'] : '';
	$r['request'] = isset( $q['request'] ) ? (string) $q['request'] : '';
	return $r;
}

/** Come back from gend.me as $uid with $get. */
function gs108c_return( $uid, array $get ) {
	wp_set_current_user( $uid );
	return gs108c_call( 'gend_society_connect_handle_return', 'GET', array_merge( array( 'action' => 'gend_society_connect_return' ), $get ) );
}

/** Test-only approve on the hub clone (plan 04 sequence). Returns [status, data]. */
function gs108c_approve( $request, $state, $login = 'gs108admin', $group_id = 3 ) {
	$res = wp_remote_post(
		gs108c_hub() . '/wp-json/gs108-test/v1/approve',
		array(
			'timeout' => 40,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-GS108-Secret' => gs108c_secret(),
			),
			'body'    => wp_json_encode(
				array(
					'request'    => $request,
					'state'      => $state,
					'group_id'   => $group_id,
					'user_login' => $login,
				)
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 0, $res->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $res ), json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
}

/** Remove what the approve route created on the hub clone for $install_id. */
function gs108c_hub_cleanup( $install_id ) {
	$res = wp_remote_post(
		gs108c_hub() . '/wp-json/gs108-test/v1/cleanup',
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-GS108-Secret' => gs108c_secret(),
			),
			'body'    => wp_json_encode( array( 'install_id' => $install_id ) ),
		)
	);
	return is_wp_error( $res ) ? $res->get_error_message() : (string) wp_remote_retrieve_body( $res );
}

/** Raw serialized option value from the DB (byte-for-byte comparisons); null when absent. */
function gs108c_raw( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

/** Fresh option read (bypasses the in-process cache). */
function gs108c_opt( $name, $default = null ) {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return get_option( $name, $default );
}

/** Hub requests recorded by gs108_http_record() whose URL contains $needle. */
function gs108c_hub_calls( $needle = '' ) {
	return array_values(
		array_filter(
			gs108_http_log(),
			function ( $l ) use ( $needle ) {
				return false !== strpos( $l, gs108c_hub() ) && ( '' === $needle || false !== strpos( $l, $needle ) );
			}
		)
	);
}

/** A second administrator (deleted on cleanup). */
function gs108c_other_admin() {
	$login = 'gs108admin2' . strtolower( wp_generate_password( 4, false, false ) );
	$uid   = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $uid ) ) {
		return 0;
	}
	gs108_on_cleanup(
		function () use ( $uid ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		},
		'other admin'
	);
	return (int) $uid;
}

/** A subscriber (deleted on cleanup). */
function gs108c_subscriber() {
	$login = 'gs108sub' . strtolower( wp_generate_password( 4, false, false ) );
	$uid   = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $uid ) ) {
		return 0;
	}
	gs108_on_cleanup(
		function () use ( $uid ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		},
		'subscriber'
	);
	return (int) $uid;
}

/** The bed's first administrator. */
function gs108c_admin_id() {
	$u = get_users(
		array(
			'role'    => 'administrator',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => 1,
			'fields'  => 'ID',
		)
	);
	return $u ? (int) $u[0] : 1;
}

/** Common preconditions; returns false (with FAIL) when the section cannot run. */
function gs108c_require() {
	$ok = gs108_assert( function_exists( 'gend_society_connect_handle_start' ), 'module-loaded', 'inc/connect-flow.php is loaded (standalone)' );
	if ( ! $ok ) {
		return false;
	}
	gs108_snapshot_options();
	gs108c_setup();
	gs108c_fresh();
	return true;
}
