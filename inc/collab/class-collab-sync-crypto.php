<?php
/**
 * gend-society — GenD Match collab cross-app sync crypto helper (Phase 89-01, v12.0).
 *
 * Standalone ed25519 sign/verify primitive for the FED-01 cross-app federation
 * rail. Phase 89 reuses the existing `gend-pm-sync/v1` ed25519 signed-REST
 * transport (contracts-and-payments) — but that plugin's verify/sign are BOTH
 * `private static` (Gend_CP_PM_Sync::verify_request, Gend_CP_PM_Sync_Push::send),
 * so gend-society CANNOT call them. gend-society ALREADY owns every key the rail
 * uses, though (the container `gs_keypair`, the hub's `gs_gend_pubkey`, and — on
 * the hub — the container's `gdc_society_pubkey` site-meta via the WP-Ultimo
 * Self_Hosted_Helper). So this class RE-IMPLEMENTS the ~40-line rail LOCALLY,
 * mirroring the rail's exact shape (X-Gend-Sig header, 600s replay window,
 * find_site_by_install_id → gdc_society_pubkey lookup, the 400/401/500 status
 * ladder). There is NO call into the private c-and-p verify/sign and NO edit to
 * contracts-and-payments — this is a pure gend-society re-implementation.
 *
 * PURE HELPER — this file wires NO hooks (no add_action). 89-02 requires it and
 * consumes sign_body() (container push leg) + verify_container_request() (hub
 * receive leg). Every sodium/helper call is function_exists/class_exists-guarded
 * so a missing libsodium or a missing WP-Ultimo helper degrades to a CLEAN error
 * / WP_REST_Response (null sign, 500 receive) — NEVER a fatal (Pitfall 3: a lone
 * / mis-provisioned hub must not fatal on an absent helper; the container outbox
 * retries).
 *
 * Rail shape mirrored VERBATIM in logic:
 *   verify — contracts-and-payments/includes/class-pm-sync.php:457-510
 *   sign   — contracts-and-payments/includes/class-pm-sync-push.php:501-517
 *   ids    — class-pm-sync-push.php:255-260 (install_id), :269-272 (gs_keypair)
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Sync_Crypto {

	/**
	 * The signature header on the rail (mirrors Gend_CP_PM_Sync::SIG_HEADER).
	 */
	const SIG_HEADER = 'X-Gend-Sig';

	/**
	 * Replay window in seconds (mirrors Gend_CP_PM_Sync::REPLAY_WINDOW). A payload
	 * whose `ts` is more than this far from now is rejected 401.
	 */
	const REPLAY_WINDOW = 600;

	/**
	 * This container's federation identity (= the CONTEXT's origin_app_id).
	 * Mirrors Gend_CP_PM_Sync_Push::install_id() (class-pm-sync-push.php:255-260):
	 * the GDC_CONTAINER_INSTALL_ID const first, else the gdc_container_install_id
	 * option.
	 *
	 * @return string Install id, or '' if unknown.
	 */
	public static function install_id() : string {
		if ( defined( 'GDC_CONTAINER_INSTALL_ID' ) && GDC_CONTAINER_INSTALL_ID ) {
			return (string) GDC_CONTAINER_INSTALL_ID;
		}
		return (string) get_option( 'gdc_container_install_id', '' );
	}

	/**
	 * This container's ed25519 keypair (base64 SODIUM_CRYPTO_SIGN_KEYPAIRBYTES blob
	 * in the gs_keypair option — set by gend-society autopair). Mirrors
	 * Gend_CP_PM_Sync_Push::keypair() (class-pm-sync-push.php:269-272).
	 *
	 * @return string|null The raw keypair, or null if absent/malformed.
	 */
	public static function keypair() : ?string {
		$raw = base64_decode( (string) get_option( 'gs_keypair', '' ), true );
		if ( ! is_string( $raw ) || strlen( $raw ) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Sign a JSON body with this container's secret key — the container PUSH leg.
	 * Mirrors Gend_CP_PM_Sync_Push::send() sign step (class-pm-sync-push.php:506-507):
	 *   base64( sodium_crypto_sign_detached( $body, secretkey( gs_keypair ) ) ).
	 *
	 * @param string $body The exact request body to sign (as it will be POSTed).
	 * @return string|null Base64 detached signature, or null if the keypair or
	 *                     libsodium is unavailable (caller enqueues to the outbox).
	 */
	public static function sign_body( string $body ) : ?string {
		if ( ! function_exists( 'sodium_crypto_sign_detached' )
			|| ! function_exists( 'sodium_crypto_sign_secretkey' ) ) {
			return null;
		}
		$kp = self::keypair();
		if ( null === $kp ) {
			return null;
		}
		$sk = sodium_crypto_sign_secretkey( $kp );
		return base64_encode( sodium_crypto_sign_detached( $body, $sk ) );
	}

	/**
	 * Verify an inbound signed container request — the hub RECEIVE leg. Re-implements
	 * Gend_CP_PM_Sync::verify_request() (class-pm-sync.php:457-510) VERBATIM in logic:
	 * body → JSON → install_id → ts replay-window → X-Gend-Sig → find_site_by_install_id
	 * → gdc_society_pubkey → sodium_crypto_sign_verify_detached. The status ladder
	 * matches the rail EXACTLY:
	 *   400 — empty body / invalid JSON / missing install_id.
	 *   401 — stale-or-missing ts / missing sig / malformed sig / unknown install /
	 *         install has no pubkey / signature did not verify.
	 *   500 — self-hosted helper unavailable / stored pubkey malformed / libsodium
	 *         absent (clean error, NEVER a fatal — Pitfall 3).
	 *
	 * @param WP_REST_Request $req The inbound signed request.
	 * @return array|WP_REST_Response On success:
	 *   [ 'payload' => array, 'install_id' => string, 'site' => object ];
	 *   on failure a WP_REST_Response with the status above.
	 */
	public static function verify_container_request( WP_REST_Request $req ) {
		$body = $req->get_body();
		if ( ! $body ) {
			return new WP_REST_Response( array( 'error' => 'empty body' ), 400 );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid JSON' ), 400 );
		}
		$install_id = isset( $payload['install_id'] ) ? sanitize_text_field( (string) $payload['install_id'] ) : '';
		if ( '' === $install_id ) {
			return new WP_REST_Response( array( 'error' => 'missing install_id' ), 400 );
		}
		$ts = isset( $payload['ts'] ) ? (int) $payload['ts'] : 0;
		if ( 0 === $ts || abs( time() - $ts ) > self::REPLAY_WINDOW ) {
			return new WP_REST_Response( array( 'error' => 'stale or missing ts' ), 401 );
		}

		// libsodium must be present to verify — clean 500 (not fatal) if absent.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new WP_REST_Response( array( 'error' => 'libsodium unavailable' ), 500 );
		}

		$sig_b64 = (string) $req->get_header( self::SIG_HEADER );
		if ( '' === $sig_b64 ) {
			return new WP_REST_Response( array( 'error' => 'missing signature' ), 401 );
		}
		$sig = base64_decode( $sig_b64, true );
		if ( ! is_string( $sig ) || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			return new WP_REST_Response( array( 'error' => 'malformed signature' ), 401 );
		}

		// Resolve the container's pubkey off the WP-Ultimo self-hosted site row —
		// class_exists-guarded exactly as the rail does (class-pm-sync.php:483-488):
		// namespaced helper first, bare helper second, else a clean 500.
		$helper_class = class_exists( '\WP_Ultimo\Helpers\Self_Hosted_Helper' )
			? '\WP_Ultimo\Helpers\Self_Hosted_Helper'
			: ( class_exists( 'Self_Hosted_Helper' ) ? 'Self_Hosted_Helper' : null );
		if ( ! $helper_class ) {
			return new WP_REST_Response( array( 'error' => 'self-hosted helper unavailable' ), 500 );
		}
		$site = $helper_class::find_site_by_install_id( $install_id );
		if ( ! $site ) {
			return new WP_REST_Response( array( 'error' => 'unknown install' ), 401 );
		}
		$pub_b64 = (string) $site->get_meta( 'gdc_society_pubkey', '' );
		if ( '' === $pub_b64 ) {
			return new WP_REST_Response( array( 'error' => 'install has no pubkey on file' ), 401 );
		}
		$pub = base64_decode( $pub_b64, true );
		if ( ! is_string( $pub ) || strlen( $pub ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
			return new WP_REST_Response( array( 'error' => 'stored pubkey malformed' ), 500 );
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, $body, $pub ) ) {
			return new WP_REST_Response( array( 'error' => 'signature did not verify' ), 401 );
		}

		// Verified payload — return the shape the rail returns.
		return array(
			'payload'    => $payload,
			'install_id' => $install_id,
			'site'       => $site,
		);
	}
}
