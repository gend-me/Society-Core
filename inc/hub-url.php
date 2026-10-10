<?php
/**
 * Pure hub-URL / OAuth-config helpers.
 *
 * Split out of inc/oauth-login.php in Phase 106: oauth-login.php is container
 * tier (hub + containers only, never standalone, never in the wordpress.org
 * zip), but core/customer files (admin-style, dashboard-hosting,
 * dashboard-remote-membership, dashboard-app-management, pages/feature-upgrade)
 * call these helpers on every runtime. Core tier, no hooks, no remote calls.
 *
 * gend_society_oauth_client_secret() deliberately stays in oauth-login.php
 * (server-side secret, container/hub only).
 *
 * Every function is function_exists-guarded: oauth-login.php also
 * require_once's this file, so either load order works.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_oauth_config_value' ) ) {
	/**
	 * Read GDC_OAUTH_* configuration in priority order:
	 *   1. wp-config.php constant (definitive override)
	 *   2. environment variable (set by the K8s Composition's per-namespace
	 *      gend-oauth-credentials Secret — every fresh customer container
	 *      gets one wired in automatically)
	 *   3. legacy site option (aipa_*) the Leo plugin used to set
	 *
	 * The env-var path is what makes "no manual steps for new customer
	 * signups" work: gend.me's listener writes the OAuth client_id +
	 * secret into a per-namespace Secret on claim creation, the Deployment
	 * mounts it as envFrom, and oauth-login.php picks it up here.
	 *
	 * @param string $constant      Constant / env var name.
	 * @param string $legacy_option Legacy site option name ('' = none).
	 * @param string $default       Fallback value.
	 */
	function gend_society_oauth_config_value( string $constant, string $legacy_option, string $default = '' ): string {
		if ( defined( $constant ) && constant( $constant ) ) {
			return (string) constant( $constant );
		}
		$env = getenv( $constant );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		// $_SERVER fallback — some PHP-FPM pools set clear_env=yes which
		// strips getenv() but still passes vars through Apache/$_SERVER.
		if ( isset( $_SERVER[ $constant ] ) && '' !== $_SERVER[ $constant ] ) {
			return (string) $_SERVER[ $constant ];
		}
		if ( '' !== $legacy_option ) {
			$stored = (string) get_site_option( $legacy_option, '' );
			if ( '' !== $stored ) {
				return $stored;
			}
		}
		return $default;
	}
}

if ( ! function_exists( 'gend_society_oauth_hub_url' ) ) {
	/**
	 * Base URL of the gend.me hub (no trailing slash).
	 */
	function gend_society_oauth_hub_url(): string {
		return rtrim( gend_society_oauth_config_value( 'GDC_OAUTH_HUB_URL', 'aipa_central_hub_url', 'https://gend.me' ), '/' );
	}
}

if ( ! function_exists( 'gend_society_oauth_client_id' ) ) {
	/**
	 * OAuth client id this site uses against the hub ('' when unconfigured).
	 */
	function gend_society_oauth_client_id(): string {
		return gend_society_oauth_config_value( 'GDC_OAUTH_CLIENT_ID', 'aipa_oauth_client_id' );
	}
}

if ( ! function_exists( 'gend_society_oauth_is_hub_site' ) ) {
	/**
	 * True when this WordPress install IS the gend.me hub itself. Compared
	 * by host so that custom-domain mappings on the hub still match.
	 */
	function gend_society_oauth_is_hub_site(): bool {
		$hub_host  = (string) wp_parse_url( gend_society_oauth_hub_url(), PHP_URL_HOST );
		$self_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $hub_host || '' === $self_host ) {
			return false;
		}
		return strtolower( $hub_host ) === strtolower( $self_host );
	}
}
