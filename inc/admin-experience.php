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
 * - With the skin ON (109-35, D36): the core admin bar stays hidden as on
 *   gend.me, and "Switch back to standard WordPress" sits at the top of the
 *   GenD dashboard page content (gend_society_admin_experience_dashboard_top()),
 *   with the theme warning + Activate below it.
 * - Phase 109 (D28/D34): the skin is ON by default on standalone. While the
 *   default (no stored choice) is in force, a notice on every skinned screen
 *   names the switch and offers "Keep the GenD dashboard" (stores gend) or
 *   "Switch back to standard WordPress" (stores native).
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
					esc_html_e( 'Currently: OFF - you are using the standard WordPress admin.', 'gend-society' );
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
						esc_html_e( 'Switch back to standard WordPress', 'gend-society' );
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

if ( ! function_exists( 'gend_society_admin_experience_theme_warning' ) ) {
	/**
	 * Phase 109-35 (D36 (3)): the GenD Society theme is not active -> warning + Activate (full build: the 106 theme
	 * card's own nonce'd admin-post action, back to the dashboard) or the wordpress.org download panel (wporg build:
	 * the theme is not bundled). Prints nothing when the theme is active.
	 */
	function gend_society_admin_experience_theme_warning(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return;
		}
		if ( function_exists( 'gend_society_theme_is_active' ) && defined( 'GEND_SOCIETY_THEME_SLUG' ) ) {
			// The theme_roots cache written before this plugin registered its theme folder hides the bundled theme
			// (and the Activate action refuses it) until the cache expires: refresh it when that is the case.
			if ( ! wp_get_theme( GEND_SOCIETY_THEME_SLUG )->exists() && defined( 'GEND_SOCIETY_THEME_ROOT' ) && wp_get_theme( GEND_SOCIETY_THEME_SLUG, GEND_SOCIETY_THEME_ROOT )->exists() ) {
				wp_clean_themes_cache( false );
			}
			if ( gend_society_theme_is_active() || ! wp_get_theme( GEND_SOCIETY_THEME_SLUG )->exists() ) {
				return;
			}
			echo '<div class="gs-sa-top__theme" role="status">';
			echo '<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> <strong>' . esc_html__( 'The GenD Society theme is not active.', 'gend-society' ) . '</strong> ';
			echo esc_html__( 'GenD profile, group and store pages are built for it. Your current theme stays active until you switch.', 'gend-society' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="gend_society_theme_activate" />';
			echo '<input type="hidden" name="gs_return" value="dashboard" />';
			wp_nonce_field( 'gend_society_theme_activate' );
			echo '<button type="submit" class="button button-primary gs-sa-top__activate">' . esc_html__( 'Activate GenD Society theme', 'gend-society' ) . '</button>';
			echo '</form></div>';
			return;
		}
		if ( function_exists( 'gend_society_theme_download_render_panel' ) && 'gend-society-theme' !== get_stylesheet() && 'gend-society-theme' !== get_template() ) {
			echo '<div class="gs-sa-top__theme" role="status">';
			echo '<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> <strong>' . esc_html__( 'The GenD Society theme is not active.', 'gend-society' ) . '</strong></p>';
			gend_society_theme_download_render_panel();
			echo '</div>';
		}
	}
}

if ( ! function_exists( 'gend_society_admin_experience_dashboard_top' ) ) {
	/**
	 * Phase 109-35 (D36 (1)-(3)): the top of the GenD dashboard page content on a standalone install. The core admin
	 * bar is hidden (inc/admin-style.php, as on gend.me), so "Switch back to standard WordPress" lives here, not in the
	 * admin bar or the skin header. While the standalone default is in force (no stored choice) it also offers "Keep
	 * the GenD dashboard". Called from gend_society_render_custom_dashboard_screen() (standalone branch only).
	 */
	function gend_society_admin_experience_dashboard_top(): void {
		if ( ! gend_society_admin_experience_can_switch_back() ) {
			return;
		}
		$is_default = '' === (string) get_option( 'gend_society_admin_experience', '' );
		echo '<div class="gs-sa-top" id="gs-sa-top">';
		echo '<div class="gs-sa-top__switch">';
		if ( $is_default ) {
			echo '<span class="gs-sa-top__note">' . esc_html__( 'GenD Society turned on the GenD dashboard for this site.', 'gend-society' ) . '</span>';
			echo '<a class="gs-sa-top__btn is-primary" href="' . esc_url( gend_society_admin_experience_switch_url( 'gend' ) ) . '">' . esc_html__( 'Keep the GenD dashboard', 'gend-society' ) . '</a>';
		}
		echo '<a class="gs-sa-top__btn gend-society-switch-back" href="' . esc_url( gend_society_admin_experience_switch_url( 'native' ) ) . '">' . esc_html__( 'Switch back to standard WordPress', 'gend-society' ) . '</a>';
		echo '</div>';
		gend_society_admin_experience_theme_warning();
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_admin_experience_top_css' ) ) {
	/**
	 * Styles for the dashboard top bar (index.php only).
	 */
	function gend_society_admin_experience_top_css(): void {
		if ( ! gend_society_admin_experience_can_switch_back() ) {
			return;
		}
		echo '<style id="gend-society-sa-top-css">'
			. '.gs-sa-top{max-width:1200px;margin:16px auto 0;display:flex;flex-direction:column;gap:10px;}'
			. '.gs-sa-top__switch{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:10px;}'
			. '.gs-sa-top__note{color:#cbd5f5;font-size:13px;margin-right:auto;}'
			. '.gs-sa-top__btn{display:inline-block;padding:8px 16px;border-radius:999px;border:1px solid rgba(255,255,255,.25);background:rgba(11,14,20,.55);color:#fff!important;text-decoration:none;font-size:13px;font-weight:600;line-height:1.4;white-space:nowrap;}'
			. '.gs-sa-top__btn:hover,.gs-sa-top__btn:focus{background:rgba(255,255,255,.12);color:#fff;}'
			. '.gs-sa-top__btn.is-primary{background:linear-gradient(180deg,#4f46e5,#3b82f6);border-color:transparent;}'
			. '.gs-sa-top__theme{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:12px 16px;border-radius:12px;border:1px solid rgba(245,158,11,.45);background:rgba(245,158,11,.12);color:#fde68a;}'
			. '.gs-sa-top__theme p{margin:0;color:#fde68a;}.gs-sa-top__theme form{margin:0 0 0 auto;}'
			. '.gs-sa-top__theme .card{background:transparent!important;border:0!important;box-shadow:none!important;padding:0;margin:0;max-width:none;color:#fde68a;}'
			. '.gs-sa-top__theme .card h2{display:none;}.gs-sa-top__theme .card a{color:#fff;}'
			. '</style>';
	}
	add_action( 'admin_head-index.php', 'gend_society_admin_experience_top_css', 99 );
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
			? __( 'The GenD dashboard is on. Use "Switch back to standard WordPress" at the top of the dashboard at any time.', 'gend-society' )
			: __( 'You are back on the standard WordPress admin.', 'gend-society' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
	add_action( 'admin_notices', 'gend_society_admin_experience_notice' );
}

if ( ! function_exists( 'gend_society_admin_experience_default_notice' ) ) {
	/**
	 * Phase 109 (D28/D34): while the GenD dashboard is on only because of the
	 * standalone default (no stored choice yet), say so on every skinned
	 * screen and offer both choices. Either button goes through the same
	 * nonce'd admin-post handler as the Switch-back link and stores the
	 * choice, which ends the notice.
	 */
	function gend_society_admin_experience_default_notice(): void {
		if ( ! gend_society_admin_experience_can_switch_back() ) {
			return;
		}
		if ( '' !== (string) get_option( 'gend_society_admin_experience', '' ) ) {
			return;
		}
		// 109-35: the dashboard's own top bar carries both choices there.
		if ( isset( $GLOBALS['pagenow'] ) && 'index.php' === $GLOBALS['pagenow'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by the nonce-checked handler.
		if ( isset( $_GET['gend_society_experience_updated'] ) ) {
			return;
		}
		echo '<div class="notice notice-info gend-society-skin-default-notice"><p>'
			. esc_html__( 'GenD Society turned on the GenD dashboard for this site. You can switch back to the standard WordPress admin at any time from the top of the dashboard.', 'gend-society' )
			. '</p><p>'
			. '<a class="button button-primary" href="' . esc_url( gend_society_admin_experience_switch_url( 'gend' ) ) . '">' . esc_html__( 'Keep the GenD dashboard', 'gend-society' ) . '</a> '
			. '<a class="button" href="' . esc_url( gend_society_admin_experience_switch_url( 'native' ) ) . '">' . esc_html__( 'Switch back to standard WordPress', 'gend-society' ) . '</a>'
			. '</p></div>';
	}
	add_action( 'admin_notices', 'gend_society_admin_experience_default_notice' );
}
