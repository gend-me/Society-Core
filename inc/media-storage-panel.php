<?php
/**
 * Media Storage Plan panel — renders at the BOTTOM of the blog-manager
 * Media tab (hook: bm_admin_render_tab_media @ priority 30, AFTER
 * GMO_Admin_Subtab @ 10 and GMO_Editor_Panel @ 20).
 *
 * OWNED BY gend-society (NOT gend-media-optimizer): gend-society.php already
 * require_once's dashboard-hosting.php + dashboard-remote-membership.php, so
 * the install-token / membership-payload reads and the wp_ajax_gs_membership_refresh
 * action ALREADY live on this side and are available on the blog-manager admin
 * screen. Making GMO call these helpers would create fragile cross-plugin
 * coupling — so gend-society registers its OWN render callback here.
 *
 * Pure REUSE + surfacing: shows the current container media-storage plan label,
 * a used-vs-quota usage bar (used = recursive uploads scan via gs_hosting_collect_media,
 * quota = gs_hosting_media_plan_bytes), and an Upgrade button that reuses the
 * existing gend.me checkout popup + gs_membership_refresh flow. NO new wp-admin
 * menu page, NO new top tab (memory anchor: feedback_blog_manager_admin_tabs),
 * NO new billing form, NO second network path to gend.me.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'bm_admin_render_tab_media', 'gs_media_storage_panel_render', 30 );

/**
 * Render the media-storage plan section at the bottom of the Media tab.
 *
 * Graceful degradation is the single most important behavior: when the install
 * is NOT paired with a vendor container storage plan (no resolvable membership
 * payload), render a NEUTRAL empty state and never error. The whole body is
 * wrapped in try/catch( \Throwable ) so a missing helper can never fatal the
 * Media tab.
 */
function gs_media_storage_panel_render() {
	// Same capability gate as the rest of the Media tab (bm_render_blog_manager
	// checks edit_posts).
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	try {
		// ── Resolve membership payload (cache only — no second network path) ──
		$payload = function_exists( 'gs_remote_membership_get_cached' )
			? gs_remote_membership_get_cached()
			: null;

		// GRACEFUL DEGRADATION: not paired / no resolvable vendor plan → neutral
		// empty state, no upgrade button, no error.
		if ( ! is_array( $payload ) ) {
			?>
			<div class="gdc-admin-card gs-media-storage gs-media-storage--empty" style="margin-top: 16px;">
				<h3 class="gs-hosting__section-title" style="margin: 0 0 6px;">
					<?php esc_html_e( 'Media Storage Plan', 'gend-society' ); ?>
				</h3>
				<p class="gs-hosting__section-sub" style="margin: 0; color: var(--gs-muted, #94a3b8);">
					<?php esc_html_e( 'Connect this site to gend.me to see your media storage plan and manage upgrades.', 'gend-society' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// ── Plan label (prefer media tier from payload, else billing label) ──
		$hosting_plan = isset( $payload['hosting_plan'] ) && is_array( $payload['hosting_plan'] )
			? $payload['hosting_plan']
			: array();
		if ( ! empty( $hosting_plan['media_label'] ) ) {
			$plan_label = (string) $hosting_plan['media_label'];
		} elseif ( ! empty( $hosting_plan['label'] ) ) {
			$plan_label = (string) $hosting_plan['label'];
		} elseif ( function_exists( 'gs_hosting_plan_label' ) ) {
			$plan_label = (string) gs_hosting_plan_label( isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array() );
		} else {
			$plan_label = __( 'Default', 'gend-society' );
		}

		// Popup target — empty means the Upgrade button must render DISABLED.
		$member_url = (string) ( $payload['membership_url'] ?? '' );

		// ── Usage (reuse the existing recursive scan + quota helpers) ──
		$media = function_exists( 'gs_hosting_collect_media' ) ? gs_hosting_collect_media() : array();
		$used  = (int) ( $media['bytes_used'] ?? 0 );
		$cap   = (int) ( $media['plan_bytes'] ?? ( function_exists( 'gs_hosting_media_plan_bytes' ) ? gs_hosting_media_plan_bytes() : 0 ) );
		$pct   = function_exists( 'gs_hosting_pct' ) ? gs_hosting_pct( $used, $cap ) : 0;
		$warn  = $pct >= 80;

		$used_label = size_format( $used, 2 );
		$cap_label  = size_format( $cap, 0 );
		if ( false === $used_label ) {
			$used_label = '0 B';
		}
		if ( false === $cap_label ) {
			$cap_label = '0 B';
		}
		?>
		<div class="gdc-admin-card gs-media-storage" style="margin-top: 16px;">
			<div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
				<div>
					<h3 class="gs-hosting__section-title" style="margin: 0 0 4px;">
						<?php esc_html_e( 'Media Storage Plan', 'gend-society' ); ?>
					</h3>
					<p class="gs-hosting__section-sub" style="margin: 0; color: var(--gs-muted, #94a3b8);">
						<?php esc_html_e( 'Your container media-storage plan and live uploads usage. Compress uploads above to save container storage.', 'gend-society' ); ?>
					</p>
				</div>
				<div style="text-align: right;">
					<div class="gs-hosting__stat-label"><?php esc_html_e( 'Current Plan', 'gend-society' ); ?></div>
					<div style="color:#fff; font-size:1rem; font-weight:600; margin-top:4px;">
						<?php echo esc_html( $plan_label ); ?>
					</div>
				</div>
			</div>

			<div class="gs-hosting__resource" style="padding: 0;">
				<div style="display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 10px;">
					<div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
						<span class="dashicons dashicons-format-image" style="color:#4eaaff; font-size: 22px; width: 22px; height: 22px;"></span>
						<div>
							<div style="color: #fff; font-weight: 600;"><?php esc_html_e( 'Media Storage', 'gend-society' ); ?></div>
							<div style="color: var(--gs-muted, #94a3b8); font-size: 0.8rem;"><?php esc_html_e( 'Uploads PVC — images, video, attachments', 'gend-society' ); ?></div>
						</div>
					</div>
					<div style="display: flex; align-items: center; gap: 14px;">
						<div style="text-align: right; min-width: 160px;">
							<div style="color: #fff; font-weight: 700;">
								<?php echo esc_html( $used_label ); ?>
								<span style="color: var(--gs-muted, #94a3b8); font-weight: 400;">/ <?php echo esc_html( $cap_label ); ?></span>
							</div>
							<div style="color: <?php echo $warn ? '#fcd34d' : 'var(--gs-muted, #94a3b8)'; ?>; font-size: 0.75rem; margin-top: 2px;">
								<?php echo (int) $pct; ?>% <?php esc_html_e( 'used', 'gend-society' ); ?>
							</div>
						</div>
						<?php if ( '' === $member_url ) : ?>
							<button type="button" class="gs-hosting__btn" disabled
								title="<?php esc_attr_e( 'Membership URL unavailable — reconnect this site to gend.me to manage your plan.', 'gend-society' ); ?>"
								style="font-size: 0.75rem; padding: 7px 14px; opacity: 0.5; cursor: not-allowed;">
								<?php esc_html_e( 'Upgrade / Downgrade', 'gend-society' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="gs-hosting__btn"
								data-gs-mship="upgrade-plan" data-group="hosting" data-resource="media"
								data-gs-member-url="<?php echo esc_attr( $member_url ); ?>"
								style="font-size: 0.75rem; padding: 7px 14px;">
								<?php esc_html_e( 'Upgrade / Downgrade', 'gend-society' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
				<div class="gs-hosting__progress">
					<div class="gs-hosting__progress-bar<?php echo $warn ? ' is-warn' : ''; ?>" style="width: <?php echo (int) $pct; ?>%;"></div>
				</div>
			</div>
		</div>
		<?php
	} catch ( \Throwable $e ) {
		// Never fatal the Media tab. Render nothing on unexpected failure.
		return;
	}
}

add_action( 'admin_enqueue_scripts', 'gs_media_storage_panel_enqueue' );

/**
 * Enqueue a self-contained, screen-scoped (blog-manager only) popup handler.
 *
 * The live upgrade-popup JS (dashboard-remote-membership.php:830-857) is
 * closure-scoped to the dashboard render — its memberUrl/nonce/ajaxAction are
 * NOT in scope on the blog-manager admin screen, so it cannot be reused as-is.
 * This ships a tiny standalone copy of the same flow.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function gs_media_storage_panel_enqueue( $hook_suffix ) {
	// Screen gate IDENTICAL to GMO (class-gmo-admin-subtab.php:44-50).
	$is_bm = is_string( $hook_suffix )
		&& ( strpos( $hook_suffix, 'blog-manager' ) !== false
			|| ( isset( $_GET['page'] ) && $_GET['page'] === 'blog-manager' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $is_bm ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$ver = defined( 'GS_VERSION' ) ? GS_VERSION : (string) filemtime( __FILE__ );

	wp_register_script( 'gs-media-storage-panel', false, array(), $ver, true );
	wp_localize_script(
		'gs-media-storage-panel',
		'GS_MEDIA_STORAGE',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			// MUST match gs_membership_ajax_authorize()'s check_ajax_referer('gs_membership_action','nonce').
			'nonce'   => wp_create_nonce( 'gs_membership_action' ),
		)
	);
	wp_add_inline_script( 'gs-media-storage-panel', gs_media_storage_panel_inline_js() );
	wp_enqueue_script( 'gs-media-storage-panel' );
}

/**
 * Standalone vanilla-JS popup handler. Mirrors dashboard-remote-membership.php
 * :830-857 but self-contained (no dependency on the dashboard IIFE).
 *
 * @return string
 */
function gs_media_storage_panel_inline_js() {
	return <<<'JS'
(function () {
	function refreshAndReload() {
		var cfg = window.GS_MEDIA_STORAGE || {};
		if (!cfg.ajaxUrl) { setTimeout(function () { location.reload(); }, 600); return; }
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=gs_membership_refresh&nonce=' + encodeURIComponent(cfg.nonce || '')
		}).then(function () {
			setTimeout(function () { location.reload(); }, 600);
		}).catch(function () {
			setTimeout(function () { location.reload(); }, 600);
		});
	}

	function init() {
		var btn = document.querySelector('[data-gs-mship="upgrade-plan"][data-resource="media"]');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var memberUrl = btn.getAttribute('data-gs-member-url') || '';
			if (!memberUrl) { return; }
			var w = 920, h = 760;
			var x = (window.screen.width - w) / 2;
			var y = (window.screen.height - h) / 2;
			var popup = window.open(
				memberUrl + '?ui=embed&group=hosting',
				'gs_mship_upgrade',
				'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y
			);
			if (!popup) { return; }
			var watchdog = setInterval(function () {
				if (popup.closed) { clearInterval(watchdog); refreshAndReload(); }
			}, 600);
			window.addEventListener('message', function onMsg(ev) {
				if (!ev.data || ev.data.type !== 'gs_membership_changed') { return; }
				window.removeEventListener('message', onMsg);
				clearInterval(watchdog);
				try { popup.close(); } catch (e) {}
				refreshAndReload();
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
JS;
}
