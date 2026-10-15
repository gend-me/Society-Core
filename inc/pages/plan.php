<?php
/**
 * Plan asset helpers (standalone installs, Phase 109).
 *
 * Manifest: tier customer, needs ['standalone'], loaded after inc/pages/welcome.php, so nothing here runs on
 * the hub or on gend.me containers.
 *
 * Phase 109-30 (D29, D32): the GenD > Plan & features page that lived here is gone. The plan is the existing
 * dashboard's membership card, whose UPGRADE opens gend.me's own Change Plan page in a popup (109-29), and
 * plans are compared and changed there. Its old URL (admin.php?page=gs-plan) redirects to the dashboard
 * (inc/portal-connect.php, gend_society_portal_connect_legacy_redirect()).
 *
 * What stays is what the feature-access upgrade page (inc/pages/feature-upgrade.php, PLAN-05) still uses:
 * the local gs-plan.css / gs-plan.js (popup opener + Refresh) with their wp_localize_script config, and
 * gend_society_plan_page_url(), which now points at the dashboard. Local assets only, no inline script or
 * style, no iframe, and no gend.me request while the install is not connected or the owner has not consented.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_plan_page_url' ) ) {
	/**
	 * Where "your plan" lives: the dashboard (membership card with UPGRADE).
	 */
	function gend_society_plan_page_url(): string {
		return admin_url( 'index.php' );
	}
}

if ( ! function_exists( 'gend_society_plan_page_available' ) ) {
	/**
	 * True when the install is paired and the plan data layer is loaded.
	 */
	function gend_society_plan_page_available(): bool {
		return function_exists( 'gend_society_plan_view_model' ) && '' !== (string) get_option( 'gend_society_install_token', '' );
	}
}

if ( ! function_exists( 'gend_society_plan_page_origin' ) ) {
	/**
	 * The hub origin exactly as a browser reports it in MessageEvent.origin (default ports dropped).
	 */
	function gend_society_plan_page_origin(): string {
		$origin = function_exists( 'gend_society_plan_hub_origin' ) ? gend_society_plan_hub_origin() : '';
		return (string) preg_replace( array( '#^(http://[^/:]+):80$#', '#^(https://[^/:]+):443$#' ), '$1', $origin );
	}
}

if ( ! function_exists( 'gend_society_plan_assets_enqueue' ) ) {
	/**
	 * Enqueue gs-plan.css and (when paired) gs-plan.js with its wp_localize_script config. Used by the standalone
	 * upgrade page (inc/pages/feature-upgrade.php).
	 *
	 * @param string $view Refresh context sent with the Refresh AJAX ('upgrade').
	 * @param string $area Area slug for that context.
	 */
	function gend_society_plan_assets_enqueue( string $view = '', string $area = '' ): void {
		$dir = defined( 'GEND_SOCIETY_DIR' ) ? GEND_SOCIETY_DIR : plugin_dir_path( dirname( __DIR__ ) . '/gend-society.php' );
		$url = defined( 'GEND_SOCIETY_URL' ) ? GEND_SOCIETY_URL : plugin_dir_url( dirname( __DIR__ ) . '/gend-society.php' );
		$ver = defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '1';
		$css = 'assets/gs-plan.css';
		$js  = 'assets/gs-plan.js';
		wp_enqueue_style( 'gend-society-plan', $url . $css, array(), file_exists( $dir . $css ) ? $ver . '.' . filemtime( $dir . $css ) : $ver );
		if ( ! gend_society_plan_page_available() ) {
			return;
		}
		wp_enqueue_script( 'gend-society-plan', $url . $js, array(), file_exists( $dir . $js ) ? $ver . '.' . filemtime( $dir . $js ) : $ver, true );
		$cfg = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'gs_plan' ),
			'hubOrigin' => gend_society_plan_page_origin(),
			'installId' => (string) get_option( 'gend_society_install_id', '' ),
			'i18n'      => array(
				'refreshing' => __( 'Checking your plan with gend.me…', 'gend-society' ),
				'refreshed'  => __( 'Plan details updated.', 'gend-society' ),
				'failed'     => __( 'Could not refresh the plan details. Try again in a moment.', 'gend-society' ),
				'waiting'    => __( 'Finish the checkout in the window that opened. This page updates when it closes.', 'gend-society' ),
			),
		);
		if ( '' !== $view ) {
			$cfg['refreshView'] = $view;
			$cfg['refreshArea'] = $area;
		}
		wp_localize_script( 'gend-society-plan', 'gendSocietyPlan', $cfg );
	}
}
