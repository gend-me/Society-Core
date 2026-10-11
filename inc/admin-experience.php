<?php
/**
 * Standalone opt-in admin skin: toggle handler + "Switch back".
 *
 * Manifest: tier core, needs ['standalone'] - this file only ever loads on a
 * plain (standalone) WordPress install, so nothing here re-checks the mode.
 *
 * - gend_society_admin_experience_set() stores the site option
 *   gend_society_admin_experience ('gend' | 'native'), read at plugin load by
 *   gend_society_admin_experience() in inc/bootstrap/context.php. The 'skin'
 *   need is evaluated at plugin load, so a change takes effect on the NEXT
 *   request: the handler always redirects.
 * - admin-post action gend_society_set_admin_experience (logged-in only,
 *   manage_options, nonce gend_society_admin_experience; GET for the
 *   admin-bar / header link, POST for the toggle form).
 * - gend_society_admin_experience_render_toggle(): the card on the GenD page.
 * - With the skin ON: the core admin bar is made visible again (the skin
 *   hides it), the skin header is moved below it, and "Switch back to native
 *   WordPress admin" sits in both the admin bar and the skin header.
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
	 * @return bool True when stored (or already the stored value).
	 */
	function gend_society_admin_experience_set( string $experience ): bool {
		if ( ! in_array( $experience, array( 'gend', 'native' ), true ) ) {
			return false;
		}
		if ( get_option( 'gend_society_admin_experience', '' ) !== $experience ) {
			// Autoload: read on every request at plugin load.
			update_option( 'gend_society_admin_experience', $experience, true );
		}
		// The skin regroups menus; make the cached menu structure rebuild.
		delete_transient( 'gs_admin_menu_structure_v1' );
		return get_option( 'gend_society_admin_experience', '' ) === $experience;
	}
}

if ( ! function_exists( 'gend_society_admin_experience_fallback_url' ) ) {
	/**
	 * Where to land after a switch when no usable redirect_to was given.
	 */
	function gend_society_admin_experience_fallback_url(): string {
		return function_exists( 'gend_society_welcome_page_url' ) ? gend_society_welcome_page_url() : admin_url();
	}
}

if ( ! function_exists( 'gend_society_admin_experience_current_url' ) ) {
	/**
	 * The current admin URL, safe to come back to after the switch. Screens
	 * that only exist inside the GenD skin (?page=gs-* / gdc-*) map to the
	 * GenD page instead, so switching to native never lands on a 403.
	 */
	function gend_society_admin_experience_current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || ! is_admin() ) {
			return gend_society_admin_experience_fallback_url();
		}
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( preg_match( '/(^|&)page=(gs-|gdc-)/', $query ) ) {
			return gend_society_admin_experience_fallback_url();
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$file = basename( $path );
		if ( '' === $file || 'wp-admin' === $file || 'admin-post.php' === $file || 'admin-ajax.php' === $file ) {
			return gend_society_admin_experience_fallback_url();
		}
		return admin_url( $file . ( '' !== $query ? '?' . $query : '' ) );
	}
}

if ( ! function_exists( 'gend_society_admin_experience_switch_url' ) ) {
	/**
	 * Nonce'd admin-post URL that switches the experience (GET link).
	 *
	 * @param string $experience  'gend' | 'native'.
	 * @param string $redirect_to Admin URL to come back to ('' = current).
	 */
	function gend_society_admin_experience_switch_url( string $experience, string $redirect_to = '' ): string {
		if ( '' === $redirect_to ) {
			$redirect_to = gend_society_admin_experience_current_url();
		}
		$url = add_query_arg(
			array(
				'action'      => GEND_SOCIETY_ADMIN_EXPERIENCE_ACTION,
				'experience'  => $experience,
				'redirect_to' => rawurlencode( $redirect_to ),
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, GEND_SOCIETY_ADMIN_EXPERIENCE_NONCE );
	}
}

if ( ! function_exists( 'gend_society_admin_experience_handle' ) ) {
	/**
	 * admin-post handler: switch the admin experience, then redirect.
	 */
	function gend_society_admin_experience_handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the admin experience.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		// Same value as GEND_SOCIETY_ADMIN_EXPERIENCE_NONCE; covers GET links and POST forms.
		check_admin_referer( 'gend_society_admin_experience' );

		$experience = isset( $_REQUEST['experience'] ) ? sanitize_key( wp_unslash( $_REQUEST['experience'] ) ) : '';
		if ( ! in_array( $experience, array( 'gend', 'native' ), true ) ) {
			wp_die( esc_html__( 'Unknown admin experience.', 'gend-society' ), '', array( 'response' => 400 ) );
		}
		gend_society_admin_experience_set( $experience );

		$fallback = gend_society_admin_experience_fallback_url();
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$redirect = '' !== $redirect ? wp_validate_redirect( $redirect, $fallback ) : $fallback;
		if ( 0 !== strpos( $redirect, admin_url() ) ) {
			$redirect = $fallback;
		}
		$redirect = add_query_arg( 'gend_society_experience_updated', '1', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}
	add_action( 'admin_post_' . GEND_SOCIETY_ADMIN_EXPERIENCE_ACTION, 'gend_society_admin_experience_handle' );
}

if ( ! function_exists( 'gend_society_admin_experience_render_toggle' ) ) {
	/**
	 * Echo the GenD admin / native WordPress toggle form.
	 */
	function gend_society_admin_experience_render_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$is_on  = function_exists( 'gend_society_admin_skin_enabled' ) && gend_society_admin_skin_enabled();
		$target = $is_on ? 'native' : 'gend';
		?>
		<div class="gs-welcome-card gend-society-admin-experience" id="gend-society-admin-experience">
			<h2 class="gend-society-admin-experience__title"><?php esc_html_e( 'GenD admin experience', 'gend-society' ); ?></h2>
			<p class="gend-society-admin-experience__state">
				<?php
				if ( $is_on ) {
					esc_html_e( 'Currently: ON - wp-admin uses the GenD look and menu grouping.', 'gend-society' );
				} else {
					esc_html_e( 'Currently: OFF - you are using the native WordPress admin.', 'gend-society' );
				}
				?>
			</p>
			<p class="gend-society-admin-experience__desc"><?php esc_html_e( 'Restyles wp-admin and groups menus the GenD way. Plugins, Settings, Users and Appearance always stay available.', 'gend-society' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gend-society-admin-experience__form">
				<input type="hidden" name="action" value="<?php echo esc_attr( GEND_SOCIETY_ADMIN_EXPERIENCE_ACTION ); ?>" />
				<input type="hidden" name="experience" value="<?php echo esc_attr( $target ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( gend_society_admin_experience_current_url() ); ?>" />
				<?php wp_nonce_field( GEND_SOCIETY_ADMIN_EXPERIENCE_NONCE ); ?>
				<button type="submit" class="button <?php echo $is_on ? 'button-secondary' : 'button-primary'; ?> gend-society-admin-experience__button">
					<?php
					if ( $is_on ) {
						esc_html_e( 'Switch back to native WordPress admin', 'gend-society' );
					} else {
						esc_html_e( 'Turn on the GenD admin experience', 'gend-society' );
					}
					?>
				</button>
			</form>
		</div>
		<?php
	}
}

if ( ! function_exists( 'gend_society_admin_experience_can_switch_back' ) ) {
	/**
	 * True when the Switch-back affordances should be shown.
	 */
	function gend_society_admin_experience_can_switch_back(): bool {
		return function_exists( 'gend_society_admin_skin_enabled' )
			&& gend_society_admin_skin_enabled()
			&& is_user_logged_in()
			&& current_user_can( 'manage_options' );
	}
}

if ( ! function_exists( 'gend_society_admin_experience_admin_bar' ) ) {
	/**
	 * Admin-bar node: "Switch back to native WordPress admin".
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	function gend_society_admin_experience_admin_bar( $wp_admin_bar ): void {
		if ( ! is_object( $wp_admin_bar ) || ! gend_society_admin_experience_can_switch_back() ) {
			return;
		}
		$wp_admin_bar->add_node(
			array(
				'id'    => 'gend-society-switch-back',
				'title' => esc_html__( 'Switch back to native WordPress admin', 'gend-society' ),
				'href'  => gend_society_admin_experience_switch_url( 'native' ),
				'meta'  => array( 'class' => 'gend-society-switch-back' ),
			)
		);
	}
	add_action( 'admin_bar_menu', 'gend_society_admin_experience_admin_bar', 100 );
}

if ( ! function_exists( 'gend_society_admin_experience_visibility_css' ) ) {
	/**
	 * With the skin ON, make the core admin bar visible again (admin-style.css
	 * hides it) and move the skin's fixed header below it. Priority 99: after
	 * admin-style.php's admin_head output. The bar is also stacked above the
	 * header (z-index 100001), whose 0.8 s slide-down entrance otherwise passes
	 * over the bar and covers the Switch-back link while it animates.
	 */
	function gend_society_admin_experience_visibility_css(): void {
		if ( ! gend_society_admin_experience_can_switch_back() ) {
			return;
		}
		echo '<style id="gend-society-switch-back-css">'
			. '#wpadminbar{display:block!important;z-index:100002!important;}'
			. 'html.wp-toolbar{padding-top:32px!important;}'
			. '@media screen and (max-width:782px){html.wp-toolbar{padding-top:46px!important;}}'
			. '.header-anchor-wrap{top:32px!important;}'
			. '@media screen and (max-width:782px){.header-anchor-wrap{top:46px!important;}}'
			. '.gend-society-switch-back-header{margin-left:12px;padding:6px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.25);color:#fff!important;text-decoration:none;font-size:12px;white-space:nowrap;}'
			. '.gend-society-switch-back-header:hover,.gend-society-switch-back-header:focus{background:rgba(255,255,255,.12);}'
			. '.gend-society-switch-back-header.is-floating{position:fixed;left:12px;bottom:12px;z-index:100002;margin:0;background:#1d2327;}'
			. '</style>';
	}
	add_action( 'admin_head', 'gend_society_admin_experience_visibility_css', 99 );
}

if ( ! function_exists( 'gend_society_admin_experience_header_link' ) ) {
	/**
	 * Put the same Switch-back link into the skin header (.header-anchor-wrap,
	 * injected by assets/admin-script.js). Local inline script, no remote code.
	 */
	function gend_society_admin_experience_header_link(): void {
		if ( ! gend_society_admin_experience_can_switch_back() ) {
			return;
		}
		$config = array(
			// wp_nonce_url() returns an HTML-escaped URL (&amp;); a.href is set from JS, so decode it first.
			'href'  => esc_url_raw( wp_specialchars_decode( gend_society_admin_experience_switch_url( 'native' ) ) ),
			'label' => __( 'Switch back to native WordPress admin', 'gend-society' ),
		);
		$js = '(function(c){'
			. 'var done=false;'
			. 'function make(){var a=document.createElement("a");a.className="gend-society-switch-back-header";a.href=c.href;a.textContent=c.label;return a;}'
			. 'function put(w){if(done||!w){return false;}done=true;w.appendChild(make());return true;}'
			. 'function floating(){if(done){return;}done=true;var a=make();a.className+=" is-floating";document.body.appendChild(a);}'
			. 'function start(){'
			. 'if(put(document.querySelector(".header-anchor-wrap"))){return;}'
			. 'if(!window.MutationObserver){setTimeout(function(){if(!put(document.querySelector(".header-anchor-wrap"))){floating();}},5000);return;}'
			. 'var o=new MutationObserver(function(){if(put(document.querySelector(".header-anchor-wrap"))){o.disconnect();}});'
			. 'o.observe(document.body,{childList:true,subtree:true});'
			. 'setTimeout(function(){o.disconnect();if(!put(document.querySelector(".header-anchor-wrap"))){floating();}},5000);'
			. '}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start);}else{start();}'
			. '})(' . wp_json_encode( $config ) . ');';
		wp_add_inline_script( 'common', $js );
	}
	add_action( 'admin_enqueue_scripts', 'gend_society_admin_experience_header_link', 99 );
}

if ( ! function_exists( 'gend_society_admin_experience_notice' ) ) {
	/**
	 * One-time success notice after a switch.
	 */
	function gend_society_admin_experience_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by the nonce-checked handler.
		if ( ! isset( $_GET['gend_society_experience_updated'] ) || '1' !== $_GET['gend_society_experience_updated'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$is_on   = function_exists( 'gend_society_admin_skin_enabled' ) && gend_society_admin_skin_enabled();
		$message = $is_on
			? __( 'The GenD admin experience is on. Use "Switch back to native WordPress admin" in the toolbar at any time.', 'gend-society' )
			: __( 'You are back on the native WordPress admin.', 'gend-society' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
	add_action( 'admin_notices', 'gend_society_admin_experience_notice' );
}
