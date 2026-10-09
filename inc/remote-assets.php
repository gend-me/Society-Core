<?php
/**
 * Remote (gend.me-hosted) image table + consent gate.
 *
 * Every gend.me-hosted image or video the plugin shows is listed here, once,
 * and is read through gend_society_remote_asset_url( $key ). No other file
 * may contain a gend.me upload URL (the dist build asserts this).
 *
 * Loading rule:
 *   - hub and container: always allowed (gend.me's own sites).
 *   - standalone: allowed only after the site is paired with gend.me or the
 *     admin has given consent. Before that every slot renders a neutral CSS
 *     placeholder and no request goes to gend.me.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_remote_asset_urls' ) ) {
	/**
	 * Key => URL table of every gend.me-hosted asset the plugin uses.
	 *
	 * Plan 105-05 replaces these URLs with the re-encoded (WebP / short
	 * loop) uploads; until then they are the original files, so the hub
	 * renders exactly as before.
	 *
	 * @return array<string,string>
	 */
	function gend_society_remote_asset_urls(): array {
		return array(
			// Admin + groups background (inc/admin-style.php, inc/frontend-bar.php).
			'account_background'         => 'https://gend.me/wp-content/uploads/2026/03/account-background.gif',
			// wp-admin header nav pills (assets/admin-script.js via gsAdminData).
			'nav_pill_digital_business'  => 'https://gend.me/wp-content/uploads/2025/12/Web-App-Building-Waiting.gif',
			'nav_pill_build_with_leo'    => 'https://gend.me/wp-content/uploads/2026/03/Untitleddesign1-ezgif.com-video-to-gif-converter.gif',
			'nav_pill_contract_wallet'   => 'https://gend.me/wp-content/uploads/2025/11/20251113_1637_New-Video_simple_compose_01k9zjcc05e6tbycty113spf54.gif',
			// AI widget (inc/ai-widget.php).
			'ai_widget_icon'             => 'https://gend.me/wp-content/uploads/2025/12/Futuristic_Logo_Animation_Generation-ezgif.com-crop-1.gif',
			'ai_widget_leo_avatar'       => 'https://gend.me/wp-content/uploads/2025/12/Animated_Profile_Picture_At_Desk-ezgif.com-optimize.gif',
			// Feature cards (inc/feature-cards.php).
			'feature_card_wireframe'     => 'https://gend.me/wp-content/uploads/2026/02/Wireframe-Generation.png',
			'feature_card_blog'          => 'https://gend.me/wp-content/uploads/2026/02/Social-Blogs.png',
			'feature_card_email'         => 'https://gend.me/wp-content/uploads/2026/02/Email-Nurturing.png',
			'feature_card_store'         => 'https://gend.me/wp-content/uploads/2026/02/Store-Management.png',
			'feature_card_sales'         => 'https://gend.me/wp-content/uploads/2026/02/Sales-Team.png',
			'feature_card_projects'      => 'https://gend.me/wp-content/uploads/2026/02/Remote-Projects.png',
			'feature_card_social'        => 'https://gend.me/wp-content/uploads/2026/02/Social-Profiles.png',
			'feature_card_membership'    => 'https://gend.me/wp-content/uploads/2026/02/Membership-Management.png',
			'feature_card_rewards'       => 'https://gend.me/wp-content/uploads/2026/02/Member-Rewards.png',
			// Davinci "Business Brain" hub (inc/group-davinci-ai-tab.php).
			'business_brain'             => 'https://gend.me/wp-content/uploads/2026/06/Resized-Business-Brain.png',
			'business_brain_video'       => 'https://gend.me/wp-content/uploads/2026/06/animate_this_ina_looping_anima.mp4',
		);
	}
}

if ( ! function_exists( 'gend_society_remote_assets_allowed' ) ) {
	/**
	 * Whether gend.me-hosted assets may be loaded on this site.
	 *
	 * Hub and container: always. Standalone: only once paired
	 * (gs_install_token) or consent recorded (gend_society_remote_consent,
	 * reserved for the Connect flow in Phases 106/108; not created here).
	 *
	 * @return bool
	 */
	function gend_society_remote_assets_allowed(): bool {
		$mode = function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : 'standalone';

		if ( 'hub' === $mode || 'container' === $mode ) {
			$allowed = true;
		} else {
			$allowed = '' !== (string) get_option( 'gs_install_token', '' )
				|| (bool) get_option( 'gend_society_remote_consent', false );
		}

		/**
		 * Filters whether gend.me-hosted images may be loaded.
		 *
		 * @param bool   $allowed Computed result.
		 * @param string $mode    Runtime mode (hub|container|standalone).
		 */
		return (bool) apply_filters( 'gend_society_remote_assets_allowed', $allowed, $mode );
	}
}

if ( ! function_exists( 'gend_society_remote_asset_url' ) ) {
	/**
	 * URL of a gend.me-hosted asset, or '' when loading is not allowed or
	 * the key is unknown (callers then render the placeholder).
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	function gend_society_remote_asset_url( string $key ): string {
		if ( ! gend_society_remote_assets_allowed() ) {
			return '';
		}
		$urls = gend_society_remote_asset_urls();
		return isset( $urls[ $key ] ) ? $urls[ $key ] : '';
	}
}

if ( ! function_exists( 'gend_society_remote_asset_placeholder_css' ) ) {
	/**
	 * Neutral placeholder painted where a remote image would be: CSS
	 * declarations (no selector), safe inside a style attribute or rule.
	 *
	 * @return string
	 */
	function gend_society_remote_asset_placeholder_css(): string {
		return 'background-color:#0b0e14;background-image:linear-gradient(135deg,#1b2233 0%,#0b0e14 55%,#151b2a 100%);';
	}
}
