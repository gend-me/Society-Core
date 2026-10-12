<?php
/**
 * Phase 108 plugin battery: helpers for the Connect screen sections of plan 108-09
 * (pairing, disconnect, owner, reconnect-button, output). Builds on _connect.php.
 *
 * Hub test routes on the clone (secret in wp-content/gs108-approve.key, copied in by the runner):
 *   gs108-test/v1/mint     group-bound pairing code for GS108 Alpha (group 3) as gs108admin
 *   gs108-test/v1/inspect  the hub record for an install id (read only)
 *   gs108-test/v1/cleanup  removes what approve/mint left behind
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_connect.php';

/** POST a secret-gated test route on the hub clone. Returns [status, data]. */
function gs108p_hub_test( $route, array $body ) {
	$res = wp_remote_post(
		gs108c_hub() . '/wp-json/gs108-test/v1/' . $route,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-GS108-Secret' => gs108c_secret(),
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 0, $res->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $res ), json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
}

/** Mint a group-bound pairing code on the hub (GS108 Alpha by default). Returns the code or ''. */
function gs108p_mint( $install_id, $group_id = 3, $login = 'gs108admin' ) {
	list( $st, $data ) = gs108p_hub_test(
		'mint',
		array(
			'install_id' => $install_id,
			'group_id'   => $group_id,
			'user_login' => $login,
		)
	);
	return 200 === $st && is_array( $data ) && ! empty( $data['code'] ) ? (string) $data['code'] : '';
}

/** The hub record for an install id. */
function gs108p_inspect( $install_id ) {
	list( $st, $data ) = gs108p_hub_test( 'inspect', array( 'install_id' => $install_id ) );
	return 200 === $st && is_array( $data ) ? $data : array( 'found' => false, 'http' => $st );
}

/** Submit the pairing form as $uid. */
function gs108p_pair( $uid, $code, $consent = true, $base = null ) {
	wp_set_current_user( $uid );
	$post = array(
		'action'                  => 'gend_society_portal_connect_submit',
		'op'                      => 'connect',
		'gs_portal_connect_nonce' => wp_create_nonce( 'gs_portal_connect_submit' ),
		'gend_base_url'           => null === $base ? gs108c_hub() : $base,
		'pairing_code'            => $code,
	);
	if ( $consent ) {
		$post['gend_society_consent'] = '1';
	}
	return gs108c_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), $post );
}

/** Submit an op of the Connect screen form as $uid with the given nonce. */
function gs108p_op( $uid, $op, $nonce_action, $nonce_field, array $extra = array() ) {
	wp_set_current_user( $uid );
	$post = array_merge(
		array(
			'action'     => 'gend_society_portal_connect_submit',
			'op'         => $op,
			$nonce_field => wp_create_nonce( $nonce_action ),
		),
		$extra
	);
	return gs108c_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), $post );
}

/** Connect by pairing code (mint on the hub, submit with consent). Returns the submit result + code. */
function gs108p_connect( $uid ) {
	$iid  = gend_society_connect_identity()['install_id'];
	$code = gs108p_mint( $iid );
	$r    = '' !== $code ? gs108p_pair( $uid, $code ) : array( 'kind' => 'none', 'location' => '', 'status' => 0, 'message' => 'mint failed' );
	$r['code'] = $code;
	return $r;
}

/** Render the Connect screen as $uid with $get; returns the HTML. */
function gs108p_render( $uid, array $get = array() ) {
	wp_set_current_user( $uid );
	$_GET     = wp_slash( array_merge( array( 'page' => 'gs-portal-connect' ), $get ) );
	$_REQUEST = $_GET;
	ob_start();
	try {
		gend_society_portal_connect_render_page();
	} finally {
		$html     = (string) ob_get_clean();
		$_GET     = array();
		$_REQUEST = array();
	}
	return $html;
}

/** Notice status + decoded message of a redirect result. */
function gs108p_notice( array $r ) {
	$q = gs108c_query( $r['location'] );
	return array(
		'status'  => isset( $q['gs_connect_status'] ) ? (string) $q['gs_connect_status'] : '',
		'message' => isset( $q['gs_connect_message'] ) ? rawurldecode( (string) $q['gs_connect_message'] ) : '',
		'query'   => $q,
	);
}

/** GET /install/{id}/health with a token, straight to the hub. Returns the HTTP status. */
function gs108p_health( $install_id, $token ) {
	$res = wp_remote_get(
		gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/health',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'X-Forwarded-For' => '198.51.100.' . wp_rand( 1, 254 ) . ', 10.0.0.1',
			),
		)
	);
	return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
}

/** Revoke on the hub directly (as if a gend.me admin removed the install). Returns the HTTP status. */
function gs108p_hub_revoke( $install_id, $token ) {
	$res = wp_remote_post(
		gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . rawurlencode( $install_id ) . '/disconnect',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'Content-Type'    => 'application/json',
				'X-Forwarded-For' => '198.51.100.' . wp_rand( 1, 254 ) . ', 10.0.0.1',
			),
			'body'    => '{}',
		)
	);
	return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
}

/** Answer hub requests whose URL contains $needle with a fake response (until the returned remover is called). */
function gs108p_fake_hub( $needle, $status, $body = array() ) {
	$f = function ( $pre, $args, $url ) use ( $needle, $status, $body ) {
		if ( false === strpos( (string) $url, $needle ) ) {
			return $pre;
		}
		if ( 0 === $status ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect (gs108 fake)' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 'gs108 fake',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $f, 50, 3 );
	$off = function () use ( $f ) {
		remove_filter( 'pre_http_request', $f, 50 );
	};
	gs108_on_cleanup( $off, 'fake hub ' . $needle );
	return $off;
}

/** Raw values of the connection options (byte comparisons). */
function gs108p_conn_raw() {
	$out = array();
	foreach ( array( 'gend_society_install_id', 'gend_society_keypair', 'gend_society_install_token', 'gend_society_gend_base_url', 'gend_society_gend_pubkey', 'gend_society_connected_at', 'gend_society_connection' ) as $o ) {
		$out[ $o ] = gs108c_raw( $o );
	}
	return $out;
}

/** Raw consent values. */
function gs108p_consent_raw() {
	return array(
		'consent'        => gs108c_raw( 'gend_society_consent' ),
		'remote_consent' => gs108c_raw( 'gend_society_remote_consent' ),
	);
}

/** Section preconditions for the standalone Connect screen sections. */
function gs108p_require() {
	if ( ! gs108c_require() ) {
		return false;
	}
	if ( ! gs108_assert( function_exists( 'gend_society_portal_connect_standalone_ready' ) && gend_society_portal_connect_standalone_ready(), 'screen-standalone', 'portal-connect standalone branch active' ) ) {
		return false;
	}
	if ( ! gs108_assert( '' !== gs108c_secret(), 'approve-secret', 'wp-content/gs108-approve.key present (runner copies it from hub/fixtures.txt)' ) ) {
		return false;
	}
	$iid = gend_society_connect_identity()['install_id'];
	gs108_on_cleanup(
		function () use ( $iid ) {
			gs108_info( 'hub cleanup ' . $iid . ': ' . gs108c_hub_cleanup( $iid ) );
		},
		'hub cleanup'
	);
	return true;
}
