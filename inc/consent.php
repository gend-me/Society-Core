<?php
/**
 * Consent record + gate for every call this plugin makes to gend.me.
 *
 * The one contract every remote caller uses (Phase 106, RUN-01):
 *
 *   if ( ! gend_society_remote_allowed( 'purpose' ) ) { return; }
 *
 * hub / container  always allowed (gend.me-managed sites: the customer
 *                  accepted gend.me's terms when the site was created).
 * standalone       allowed only after the owner clicked Connect (consent
 *                  record), or when the install was paired before Phase 106
 *                  (gend_society_install_token), or the Phase 105 remote-asset
 *                  consent flag is set. Nothing is sent before that.
 *
 * Functions only, no hooks. Core tier: loads on every runtime and ships in
 * both builds.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_CONSENT_VERSION' ) ) {
	/*
	 * Version of the consent text/disclosure. Bump when the disclosed items
	 * or the wording change materially.
	 */
	define( 'GEND_SOCIETY_CONSENT_VERSION', '108.1' );
}

if ( ! function_exists( 'gend_society_terms_url' ) ) {
	/**
	 * gend.me Terms of Service URL.
	 */
	function gend_society_terms_url(): string {
		return (string) apply_filters( 'gend_society_terms_url', 'https://gend.me/terms-of-service/' );
	}
}

if ( ! function_exists( 'gend_society_privacy_url' ) ) {
	/**
	 * gend.me Privacy Policy URL.
	 */
	function gend_society_privacy_url(): string {
		return (string) apply_filters( 'gend_society_privacy_url', 'https://gend.me/privacy-policy/' );
	}
}

if ( ! function_exists( 'gend_society_consent_disclosure_items' ) ) {
	/**
	 * What is sent to gend.me on Connect, in display order.
	 *
	 * @return array<string,string> key => translated label.
	 */
	function gend_society_consent_disclosure_items(): array {
		return array(
			'site_url'     => __( 'Your site address (URL)', 'gend-society' ),
			'install_id'   => __( 'A random install ID (UUID) created by this plugin', 'gend-society' ),
			'public_key'   => __( "This site's public key (used to verify signed messages)", 'gend-society' ),
			'admin_email'  => __( 'The site admin email address', 'gend-society' ),
			'gend_account' => __( 'Your gend.me account and the business group you choose are linked to this site on gend.me', 'gend-society' ),
			'owner_name'   => __( "The display name of this site's connection owner", 'gend-society' ),
		);
	}
}

if ( ! function_exists( 'gend_society_consent_text' ) ) {
	/**
	 * Consent sentence shown next to the Connect button. Plain text:
	 * renderers add the Terms / Privacy links.
	 */
	function gend_society_consent_text(): string {
		return __( "By clicking Connect you agree to gend.me's Terms of Service and Privacy Policy and allow this site to send the items listed above to gend.me. Nothing is sent before you click.", 'gend-society' );
	}
}

if ( ! function_exists( 'gend_society_consent_given' ) ) {
	/**
	 * True when this site may talk to gend.me.
	 *
	 * hub / container: always. standalone: a consent record with a non-empty
	 * 'at', or a pre-106 pairing token, or the legacy remote-asset consent.
	 */
	function gend_society_consent_given(): bool {
		$mode = gend_society_runtime_mode();
		if ( 'hub' === $mode || 'container' === $mode ) {
			return true;
		}

		$record = get_option( 'gend_society_consent', array() );
		if ( is_array( $record ) && ! empty( $record['at'] ) ) {
			return true;
		}

		// Installs paired before Phase 106 count as consented.
		if ( '' !== (string) get_option( 'gend_society_install_token', '' ) ) {
			return true;
		}

		// Phase 105 remote-asset consent flag.
		return (bool) get_option( 'gend_society_remote_consent', false );
	}
}

if ( ! function_exists( 'gend_society_consent_record' ) ) {
	/**
	 * Record the owner's consent (call only from a nonce + capability checked
	 * user action, e.g. the Connect button).
	 *
	 * @param string $source Where consent was given (e.g. 'welcome', 'connect').
	 */
	function gend_society_consent_record( string $source ): void {
		update_option(
			'gend_society_consent',
			array(
				'at'             => time(),
				'user_id'        => get_current_user_id(),
				'version'        => GEND_SOCIETY_CONSENT_VERSION,
				'plugin_version' => defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '',
				'source'         => $source,
			),
			false
		);
		// Keeps the Phase 105 remote-asset gate (inc/remote-assets.php) working unchanged.
		update_option( 'gend_society_remote_consent', 1, false );
	}
}

if ( ! function_exists( 'gend_society_consent_clear' ) ) {
	/**
	 * Withdraw consent (both the record and the remote-asset flag).
	 */
	function gend_society_consent_clear(): void {
		delete_option( 'gend_society_consent' );
		delete_option( 'gend_society_remote_consent' );
	}
}

if ( ! function_exists( 'gend_society_remote_allowed' ) ) {
	/**
	 * The gate every gend.me call checks first.
	 *
	 * @param string $purpose Short id of the call (e.g. 'pair', 'images'); passed to the filter.
	 */
	function gend_society_remote_allowed( string $purpose = '' ): bool {
		return (bool) apply_filters(
			'gend_society_remote_allowed',
			gend_society_consent_given(),
			$purpose,
			gend_society_runtime_mode()
		);
	}
}
// @build-only-begin wporg

if ( ! function_exists( 'gend_society_build_is_wporg' ) ) {
	/**
	 * True in the wordpress.org build: bin/build-dist.js writes GEND_SOCIETY_BUILD
	 * into that zip's gend-society.php. False in the full build and on gend.me.
	 */
	function gend_society_build_is_wporg(): bool {
		return defined( 'GEND_SOCIETY_BUILD' ) && 'wporg' === GEND_SOCIETY_BUILD;
	}
}

if ( ! function_exists( 'gend_society_data_sharing_items' ) ) {
	/**
	 * Optional after-connect data flows the owner turns on one by one
	 * (wordpress.org build), in display order.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	function gend_society_data_sharing_items(): array {
		return array(
			'mail_relay'  => array(
				'label'       => __( 'Send this site\'s email through the gend.me mail service', 'gend-society' ),
				'description' => __( 'WordPress email from this site is handed to gend.me for delivery (recipients, subject, message and attachments) and counted against your gend.me Emails plan. Once a day the site also reports how much inbox data it stores. When this is off, WordPress sends mail as it normally does.', 'gend-society' ),
			),
			'plugin_list' => array(
				'label'       => __( 'Share this site\'s active plugin list with gend.me', 'gend-society' ),
				'description' => __( 'The list of active plugins is sent when a plugin is activated or deactivated and once a day, so gend.me can show which features already run on this site.', 'gend-society' ),
			),
		);
	}
}

if ( ! function_exists( 'gend_society_data_sharing_allowed' ) ) {
	/**
	 * Owner opt-in for one optional data flow. wordpress.org build: off until an
	 * administrator turns it on (option gend_society_share_<item> = '1'); it is
	 * still sent only while connected and consented. Any other build: true, so
	 * gend.me-hosted sites keep their behaviour.
	 *
	 * @param string $item Key of gend_society_data_sharing_items().
	 */
	function gend_society_data_sharing_allowed( string $item ): bool {
		if ( ! gend_society_build_is_wporg() ) {
			return true;
		}
		return '1' === (string) get_option( 'gend_society_share_' . $item, '0' );
	}
}
// @build-only-end wporg
