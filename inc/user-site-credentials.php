<?php
/**
 * Feature-scoped chat editing: the desktop chat edits this site AS the person
 * using it (their own account and role), for people who are not the site
 * owner/admin and so do not get the group agent's credentials.
 *
 * Linking: a site account is linked to a gend.me user only when that person
 * signs in here with "Sign in with gend.me" (oauth-login.php) — the gend.me
 * OAuth itself proves both identities. The link is user meta
 * gend_society_hub_user_id; one site account per gend.me user. On the gend.me
 * network itself (hub sub-sites share gend.me's user table) the gend.me user
 * IS the local user, so no link is needed there.
 *
 * Credentials: POST /gs/v1/user/credentials, signed by gend.me (same rail as
 * the agent routes) for { aud, install_id, hub_user_id }. Mints a fresh
 * Application Password for that person's linked account — only if it has a
 * role on this site — so WordPress enforces exactly what they may change.
 * The password is pinned to this site: on a multisite network it does not
 * authenticate on any other site.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_HUB_USER_META = 'gend_society_hub_user_id';
const GEND_SOCIETY_USER_APP_PASSWORD = 'gend-desktop-user';

/**
 * Record which gend.me user signed in as $user_id (called from the OAuth login).
 *
 * @param int   $user_id Local user id.
 * @param array $info    gend.me userinfo response.
 */
function gend_society_record_hub_link( $user_id, $info ) {
	$user_id = (int) $user_id;
	$hub_id  = 0;
	if ( is_array( $info ) ) {
		foreach ( array( 'ID', 'id', 'user_id', 'sub' ) as $k ) {
			if ( isset( $info[ $k ] ) && is_numeric( $info[ $k ] ) && (int) $info[ $k ] > 0 ) {
				$hub_id = (int) $info[ $k ];
				break;
			}
		}
		if ( ! $hub_id && isset( $info['data']['user']['id'] ) && is_numeric( $info['data']['user']['id'] ) ) {
			$hub_id = (int) $info['data']['user']['id'];
		}
	}
	if ( $user_id <= 0 || $hub_id <= 0 ) {
		return;
	}
	// One site account per gend.me user: drop the link from any other account.
	foreach ( get_users( array( 'meta_key' => GEND_SOCIETY_HUB_USER_META, 'meta_value' => (string) $hub_id, 'fields' => 'ID' ) ) as $other ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( (int) $other !== $user_id ) {
			delete_user_meta( (int) $other, GEND_SOCIETY_HUB_USER_META );
		}
	}
	update_user_meta( $user_id, GEND_SOCIETY_HUB_USER_META, (string) $hub_id );
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'gs/v1',
			'/user/credentials',
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_user_site_credentials',
				// Authorisation is the gend.me Ed25519 signature, verified in the callback.
				'permission_callback' => '__return_true',
			)
		);
	}
);

/** https URL without trailing slash, for comparing site addresses. */
function gend_society_user_creds_norm_url( $url ) {
	return untrailingslashit( strtolower( set_url_scheme( (string) $url, 'https' ) ) );
}

/**
 * POST /gs/v1/user/credentials — see the file header.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_user_site_credentials( WP_REST_Request $request ) {
	if ( ! function_exists( 'gend_society_agent_verify_signed_request' ) ) {
		return new WP_Error( 'gs_user_creds_unavailable', __( 'Signed requests are not available on this site.', 'gend-society' ), array( 'status' => 501 ) );
	}
	$payload = gend_society_agent_verify_signed_request( $request );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}
	// The signature must be for THIS site: aud is required (the agent routes only
	// check install_id when it is present, which would let a request signed for one
	// site be replayed against another).
	$aud = isset( $payload['aud'] ) ? gend_society_user_creds_norm_url( $payload['aud'] ) : '';
	if ( '' === $aud || gend_society_user_creds_norm_url( home_url() ) !== $aud ) {
		return new WP_Error( 'gs_user_creds_wrong_site', __( 'This request was not issued for this site.', 'gend-society' ), array( 'status' => 401 ) );
	}
	$hub_user_id = isset( $payload['hub_user_id'] ) ? (int) $payload['hub_user_id'] : 0;
	if ( $hub_user_id <= 0 ) {
		return new WP_Error( 'gs_user_creds_bad_user', __( 'hub_user_id required', 'gend-society' ), array( 'status' => 400 ) );
	}

	$user = null;
	$on_hub_network = function_exists( 'gend_society_oauth_is_hub_site' ) && gend_society_oauth_is_hub_site();
	if ( $on_hub_network ) {
		$u = get_userdata( $hub_user_id );
		if ( $u && ( ! is_multisite() || is_user_member_of_blog( $u->ID, get_current_blog_id() ) ) ) {
			$user = $u;
		}
	} else {
		$ids = get_users( array( 'meta_key' => GEND_SOCIETY_HUB_USER_META, 'meta_value' => (string) $hub_user_id, 'fields' => 'ID', 'number' => 2 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( 1 === count( $ids ) ) {
			$user = get_userdata( (int) $ids[0] );
		}
	}
	if ( ! $user ) {
		return new WP_Error(
			'gs_user_creds_not_linked',
			__( 'You have no linked account on this site. Sign in to the site once with "Sign in with gend.me", or ask the site owner to give you access.', 'gend-society' ),
			array( 'status' => 403 )
		);
	}
	$roles = array_values( array_diff( (array) $user->roles, array( 'ai_agent' ) ) );
	if ( empty( $roles ) ) {
		return new WP_Error( 'gs_user_creds_no_role', __( 'Your account has no role on this site, so the AI cannot edit it for you.', 'gend-society' ), array( 'status' => 403 ) );
	}
	if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() || ! class_exists( 'WP_Application_Passwords' ) ) {
		return new WP_Error( 'gs_user_creds_unavailable', __( 'Application Passwords are not available on this site (requires HTTPS).', 'gend-society' ), array( 'status' => 501 ) );
	}

	// Pinned to this site (see the auth filter below); rotate instead of accumulating.
	$name = GEND_SOCIETY_USER_APP_PASSWORD . '@' . get_current_blog_id();
	foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $rec ) {
		if ( isset( $rec['name'], $rec['uuid'] ) && $rec['name'] === $name ) {
			WP_Application_Passwords::delete_application_password( $user->ID, $rec['uuid'] );
		}
	}
	$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );
	if ( is_wp_error( $created ) ) {
		return $created;
	}
	return rest_ensure_response(
		array(
			'username'     => $user->user_login,
			'app_password' => $created[0],
			'site_url'     => home_url(),
			'display_name' => $user->display_name,
			'roles'        => $roles,
		)
	);
}

/**
 * A desktop user credential only authenticates on the site it was minted for.
 * On a multisite network one Application Password otherwise works on every
 * site the person belongs to (including ones where they hold more power).
 */
add_action(
	'wp_authenticate_application_password_errors',
	static function ( $error, $user, $item ) {
		$name = isset( $item['name'] ) ? (string) $item['name'] : '';
		if ( 0 === strpos( $name, GEND_SOCIETY_USER_APP_PASSWORD . '@' ) && (int) substr( $name, strlen( GEND_SOCIETY_USER_APP_PASSWORD ) + 1 ) !== get_current_blog_id() ) {
			$error->add( 'gs_user_creds_other_site', __( 'This credential is only valid on the site it was issued for.', 'gend-society' ) );
		}
	},
	10,
	3
);
