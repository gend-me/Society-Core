<?php
/**
 * GenD page (wp-admin) + first-run welcome notice, standalone installs.
 *
 * Plan 106-04 (RUN-02). Manifest: tier customer, needs ['standalone'], so
 * nothing here loads on the hub or on gend.me containers.
 *
 * The page explains the four steps (Connect, plan, container, migrate),
 * discloses exactly what Connect sends, links Terms / Privacy, offers the
 * Connect button, and hosts the readiness card, the admin-experience toggle
 * and the theme section. Nothing on this page contacts gend.me; no remote
 * images, fonts or scripts.
 *
 * Theme card on this page: call gend_society_theme_card_render() when it
 * exists (full build, inc/theme-bundle.php, filled by 106-05), else
 * gend_society_theme_download_render_panel() (inc/theme-download-notice.php,
 * the wordpress.org build).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_WELCOME_DISMISSED_OPTION' ) ) {
	define( 'GEND_SOCIETY_WELCOME_DISMISSED_OPTION', 'gend_society_welcome_dismissed' );
}

if ( ! function_exists( 'gend_society_welcome_page_slug' ) ) {
	/**
	 * Admin page slug of the GenD page.
	 */
	function gend_society_welcome_page_slug(): string {
		return 'gend-society';
	}
}

if ( ! function_exists( 'gend_society_welcome_page_url' ) ) {
	/**
	 * Admin URL of the GenD page.
	 */
	function gend_society_welcome_page_url(): string {
		return admin_url( 'admin.php?page=' . gend_society_welcome_page_slug() );
	}
}

if ( ! function_exists( 'gend_society_connect_url' ) ) {
	/**
	 * Where the Connect button goes. Phase 108 swaps the target via the
	 * gend_society_connect_url filter.
	 */
	function gend_society_connect_url(): string {
		return (string) apply_filters( 'gend_society_connect_url', admin_url( 'index.php?page=gs-portal-connect' ) );
	}
}

if ( ! function_exists( 'gend_society_welcome_is_connected' ) ) {
	/**
	 * True when this install is paired with gend.me.
	 */
	function gend_society_welcome_is_connected(): bool {
		return '' !== (string) get_option( 'gend_society_install_token', '' );
	}
}

if ( ! function_exists( 'gend_society_welcome_register_page' ) ) {
	/**
	 * Register the top-level GenD menu page.
	 */
	function gend_society_welcome_register_page(): void {
		$hook = add_menu_page(
			__( 'GenD', 'gend-society' ),
			__( 'GenD', 'gend-society' ),
			'manage_options',
			gend_society_welcome_page_slug(),
			'gend_society_welcome_render_page',
			'dashicons-groups',
			3
		);
		if ( $hook ) {
			$GLOBALS['gend_society_welcome_hook'] = $hook;
		}
	}
}

if ( ! function_exists( 'gend_society_welcome_enqueue' ) ) {
	/**
	 * Load the page stylesheet on the GenD page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	function gend_society_welcome_enqueue( $hook_suffix ): void {
		$page_hook = isset( $GLOBALS['gend_society_welcome_hook'] ) ? (string) $GLOBALS['gend_society_welcome_hook'] : '';
		if ( '' === $page_hook || $hook_suffix !== $page_hook ) {
			return;
		}
		$rel = 'assets/gs-welcome.css';
		$ver = defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '1';
		if ( defined( 'GEND_SOCIETY_DIR' ) && file_exists( GEND_SOCIETY_DIR . $rel ) ) {
			$ver .= '.' . filemtime( GEND_SOCIETY_DIR . $rel );
		}
		$base = defined( 'GEND_SOCIETY_URL' ) ? GEND_SOCIETY_URL : plugin_dir_url( dirname( __DIR__ ) . '/gend-society.php' );
		wp_enqueue_style( 'gend-society-welcome', $base . 'assets/gs-welcome.css', array(), $ver );
	}
}

if ( ! function_exists( 'gend_society_welcome_render_page' ) ) {
	/**
	 * Render the GenD page.
	 */
	function gend_society_welcome_render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		$connected = gend_society_welcome_is_connected();
		$base_url  = (string) get_option( 'gend_society_gend_base_url', '' );
		if ( '' === $base_url ) {
			$base_url = 'https://gend.me';
		}
		$terms   = gend_society_terms_url();
		$privacy = gend_society_privacy_url();
		?>
		<div class="wrap gend-society-welcome">
			<h1><?php esc_html_e( 'GenD Society', 'gend-society' ); ?></h1>
			<p class="gend-society-status <?php echo $connected ? 'is-connected' : 'is-standalone'; ?>">
				<?php if ( $connected ) : ?>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: gend.me base URL, 2: connect screen URL. */
							__( 'Connected to %1$s. <a href="%2$s">Manage connection</a>', 'gend-society' ),
							esc_html( untrailingslashit( $base_url ) ),
							esc_url( gend_society_connect_url() )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Not connected. Nothing has been sent to gend.me.', 'gend-society' ); ?>
				<?php endif; ?>
			</p>

			<div class="gend-society-card gend-society-steps-card">
				<h2><?php esc_html_e( 'How it works', 'gend-society' ); ?></h2>
				<ol class="gend-society-steps">
					<li><strong><?php esc_html_e( 'Connect', 'gend-society' ); ?></strong> &mdash; <?php esc_html_e( 'Connect this site to a gend.me business group.', 'gend-society' ); ?></li>
					<li><strong><?php esc_html_e( 'Choose a plan', 'gend-society' ); ?></strong> &mdash; <?php esc_html_e( 'Choose a dashboard plan.', 'gend-society' ); ?></li>
					<li><strong><?php esc_html_e( 'Add a container', 'gend-society' ); ?></strong> &mdash; <?php esc_html_e( 'Add a gend.me container (storage + hosting).', 'gend-society' ); ?></li>
					<li><strong><?php esc_html_e( 'Migrate', 'gend-society' ); ?></strong> &mdash; <?php esc_html_e( 'Only after you have paid for a container and press Start. Nothing ever moves on install or connect.', 'gend-society' ); ?></li>
				</ol>
			</div>

			<div class="gend-society-card gend-society-disclosure-card">
				<h2><?php esc_html_e( 'What is sent when you connect', 'gend-society' ); ?></h2>
				<ul class="gend-society-disclosure">
					<?php foreach ( gend_society_consent_disclosure_items() as $key => $label ) : ?>
						<li data-item="<?php echo esc_attr( (string) $key ); ?>"><?php echo esc_html( $label ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p><?php esc_html_e( 'Nothing is sent until you press Connect.', 'gend-society' ); ?></p>
				<p class="gend-society-legal">
					<a href="<?php echo esc_url( $terms ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms of Service', 'gend-society' ); ?></a>
					&middot;
					<a href="<?php echo esc_url( $privacy ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'gend-society' ); ?></a>
				</p>
				<?php if ( ! $connected ) : ?>
					<p class="gend-society-connect">
						<a class="button button-primary button-hero" href="<?php echo esc_url( gend_society_connect_url() ); ?>"><?php esc_html_e( 'Connect', 'gend-society' ); ?></a>
					</p>
					<p class="gend-society-consent-text description"><?php echo esc_html( gend_society_consent_text() ); ?></p>
				<?php endif; ?>
			</div>

			<?php
			if ( function_exists( 'gend_society_readiness_render_card' ) && function_exists( 'gend_society_readiness_run' ) ) {
				gend_society_readiness_render_card( gend_society_readiness_run() );
			}

			if ( function_exists( 'gend_society_admin_experience_render_toggle' ) ) {
				gend_society_admin_experience_render_toggle();
			}

			if ( function_exists( 'gend_society_theme_card_render' ) ) {
				gend_society_theme_card_render();
			} elseif ( function_exists( 'gend_society_theme_download_render_panel' ) ) {
				gend_society_theme_download_render_panel();
			}
			?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'gend_society_welcome_notice_visible' ) ) {
	/**
	 * Whether the welcome notice should show on the current screen.
	 */
	function gend_society_welcome_notice_visible(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( get_option( GEND_SOCIETY_WELCOME_DISMISSED_OPTION ) ) {
			return false;
		}
		if ( gend_society_welcome_is_connected() ) {
			return false;
		}
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page_hook = isset( $GLOBALS['gend_society_welcome_hook'] ) ? (string) $GLOBALS['gend_society_welcome_hook'] : '';
		if ( $screen && '' !== $page_hook && $screen->id === $page_hook ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'gend_society_welcome_render_notice' ) ) {
	/**
	 * The one first-run notice.
	 */
	function gend_society_welcome_render_notice(): void {
		if ( ! gend_society_welcome_notice_visible() ) {
			return;
		}
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', 'gend_society_dismiss_welcome', admin_url( 'admin-post.php' ) ),
			'gend_society_dismiss_welcome'
		);
		?>
		<div class="notice notice-info is-dismissible gend-society-welcome-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( 'gend_society_dismiss_welcome' ) ); ?>">
			<p>
				<?php esc_html_e( "GenD Society is installed. Nothing has been sent anywhere yet — connect to gend.me when you're ready.", 'gend-society' ); ?>
				<a href="<?php echo esc_url( gend_society_welcome_page_url() ); ?>"><?php esc_html_e( 'Open GenD', 'gend-society' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'gend-society' ); ?></a>
			</p>
		</div>
		<?php
	}
}

if ( ! function_exists( 'gend_society_welcome_enqueue_dismiss_script' ) ) {
	/**
	 * Persist the core dismiss (X) button through admin-ajax. Inline script
	 * on the core 'common' handle; nothing remote.
	 */
	function gend_society_welcome_enqueue_dismiss_script(): void {
		if ( ! gend_society_welcome_notice_visible() ) {
			return;
		}
		$script = '(function(){'
			. 'document.addEventListener("click",function(e){'
			. 'var b=e.target&&e.target.closest?e.target.closest(".gend-society-welcome-notice .notice-dismiss"):null;'
			. 'if(!b){return;}'
			. 'var n=b.closest(".gend-society-welcome-notice"),d=new FormData();'
			. 'd.append("action","gend_society_dismiss_welcome");'
			. 'd.append("_ajax_nonce",n?n.getAttribute("data-nonce"):"");'
			. 'fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",credentials:"same-origin",body:d});'
			. '});'
			. '})();';
		wp_add_inline_script( 'common', $script );
	}
}

if ( ! function_exists( 'gend_society_welcome_dismiss_ajax' ) ) {
	/**
	 * Admin-ajax: dismiss the welcome notice for everyone on this site.
	 */
	function gend_society_welcome_dismiss_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'gend_society_dismiss_welcome' );
		update_option( GEND_SOCIETY_WELCOME_DISMISSED_OPTION, time(), false );
		wp_send_json_success();
	}
}

if ( ! function_exists( 'gend_society_welcome_dismiss_post' ) ) {
	/**
	 * Admin-post (no-JS) dismiss link.
	 */
	function gend_society_welcome_dismiss_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gend_society_dismiss_welcome' );
		update_option( GEND_SOCIETY_WELCOME_DISMISSED_OPTION, time(), false );
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : admin_url() );
		exit;
	}
}

add_action( 'admin_menu', 'gend_society_welcome_register_page' );
add_action( 'admin_enqueue_scripts', 'gend_society_welcome_enqueue' );
add_action( 'admin_enqueue_scripts', 'gend_society_welcome_enqueue_dismiss_script' );
add_action( 'admin_notices', 'gend_society_welcome_render_notice' );
add_action( 'wp_ajax_gend_society_dismiss_welcome', 'gend_society_welcome_dismiss_ajax' );
add_action( 'admin_post_gend_society_dismiss_welcome', 'gend_society_welcome_dismiss_post' );
