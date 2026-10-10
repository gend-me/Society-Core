<?php
/**
 * Standalone opt-in admin skin: toggle handler + "Switch back".
 *
 * Filled by plan 106-03. Contract stub from 106-01: final signatures, safe
 * no-op bodies. Manifest: tier core, needs ['standalone'].
 *
 * The admin-post action and nonce action below are the contract the toggle
 * form, the GenD page and the "Switch back" link all use.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_ADMIN_EXPERIENCE_ACTION' ) ) {
	// admin-post.php?action=gend_society_set_admin_experience.
	define( 'GEND_SOCIETY_ADMIN_EXPERIENCE_ACTION', 'gend_society_set_admin_experience' );
}

if ( ! defined( 'GEND_SOCIETY_ADMIN_EXPERIENCE_NONCE' ) ) {
	// wp_nonce_field / check_admin_referer action.
	define( 'GEND_SOCIETY_ADMIN_EXPERIENCE_NONCE', 'gend_society_admin_experience' );
}

if ( ! function_exists( 'gend_society_admin_experience_set' ) ) {
	/**
	 * Persist the site's admin experience (site option
	 * gend_society_admin_experience).
	 *
	 * @param string $experience 'gend' | 'native'.
	 * @return bool True when stored.
	 */
	function gend_society_admin_experience_set( string $experience ): bool {
		unset( $experience );
		return false;
	}
}

if ( ! function_exists( 'gend_society_admin_experience_render_toggle' ) ) {
	/**
	 * Echo the GenD admin / native WordPress toggle form.
	 */
	function gend_society_admin_experience_render_toggle(): void {
	}
}
