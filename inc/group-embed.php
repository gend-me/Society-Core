<?php
/**
 * Connected web-app group menu → embedded (inline, NOT iframe) tab pages.
 *
 * The wp-admin header (admin-script.js) renders the connected gend.me
 * Business Group's menu across the centre of the bar. Each item links to
 *
 *     admin.php?page=gs-group-embed&tab=<slug>
 *
 * which this file renders as a real wp-admin page whose body IS the group
 * tab's content — rendered inline by reusing the always-loaded,
 * container-local renderers (the exact same ones gs_render_membership_panel()
 * already embeds):
 *
 *   feature-suite → gs_render_feature_cards_widget()
 *   hosting       → gs_render_hosting_tab()
 *   compute-gas   → self-contained explainer + gs_hosting_compute_gas AJAX
 *   organization  → inc/pages/feature-access.php
 *
 * No iframe, no BuddyPress/group_id dependency — the data is the install's
 * own, so it renders identically on the hub and on every customer install.
 * The connected group's name (from the cached remote-membership payload) is
 * shown as a subtitle so the surface reads as a continuation of the group.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tab registry for the connected-group embed page.
 *
 * @return array<string,array{label:string,icon:string,cap:string,intro:string}>
 */
function gs_group_embed_tabs() {
	return array(
		'feature-suite' => array(
			'label' => __( 'Feature Suite', 'gend-society' ),
			'icon'  => 'dashicons-screenoptions',
			'cap'   => 'manage_options',
			'intro' => __( 'Plugins and features available to this web app.', 'gend-society' ),
		),
		'hosting' => array(
			'label' => __( 'Hosting', 'gend-society' ),
			'icon'  => 'dashicons-cloud',
			'cap'   => 'manage_options',
			'intro' => __( 'Storage, domains, database, backups and containers for this app.', 'gend-society' ),
		),
		'compute-gas' => array(
			'label' => __( 'Compute Gas', 'gend-society' ),
			'icon'  => 'dashicons-superhero',
			'cap'   => 'manage_options',
			'intro' => __( 'How compute is metered across the network — and your usage this period.', 'gend-society' ),
		),
		'organization' => array(
			'label' => __( 'Organization', 'gend-society' ),
			'icon'  => 'dashicons-groups',
			'cap'   => 'list_users',
			'intro' => __( 'People with access to this web app and their feature permissions.', 'gend-society' ),
		),
	);
}

/**
 * The connected web-app group menu, capability-filtered for the current
 * user. Consumed by admin-style.php's gsAdminData localize so the header
 * (admin-script.js) renders the centre nav.
 *
 * @return array<int,array{label:string,icon:string,url:string,slug:string}>
 */
function gs_group_embed_menu_items() {
	$items = array();
	foreach ( gs_group_embed_tabs() as $slug => $t ) {
		if ( ! current_user_can( $t['cap'] ) ) {
			continue;
		}
		$items[] = array(
			'label' => $t['label'],
			'icon'  => $t['icon'],
			'url'   => admin_url( 'admin.php?page=gs-group-embed&tab=' . $slug ),
			'slug'  => $slug,
		);
	}
	return $items;
}

/**
 * Connected group name (best-effort, no network round-trip). Reads the
 * cached remote-membership payload only — never forces a fetch on a page
 * render. Empty string on the hub / unpaired installs.
 */
function gs_group_embed_group_name() {
	$cache = get_option( 'gs_remote_membership_cache', null );
	if ( is_array( $cache ) && ! empty( $cache['group']['name'] ) ) {
		return (string) $cache['group']['name'];
	}
	return '';
}

/**
 * Register the embed page as a hidden submenu (parent_slug = null) so it's
 * reachable by URL but never adds a left-menu item — the header is its only
 * entry point.
 */
add_action( 'admin_menu', function () {
	add_submenu_page(
		null,
		__( 'Connected App', 'gend-society' ),
		__( 'Connected App', 'gend-society' ),
		'read',
		'gs-group-embed',
		'gs_group_embed_render_page'
	);
}, 20 );

/**
 * Page callback — renders the requested tab inline inside a glass panel with
 * a horizontal tab switcher (links to the other accessible tabs).
 */
function gs_group_embed_render_page() {
	$tabs = gs_group_embed_tabs();
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'feature-suite';
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'feature-suite';
	}
	$meta = $tabs[ $tab ];

	if ( ! current_user_can( $meta['cap'] ) ) {
		// Fall through to the first tab the user CAN see, else hard-stop.
		$fallback = '';
		foreach ( $tabs as $slug => $t ) {
			if ( current_user_can( $t['cap'] ) ) { $fallback = $slug; break; }
		}
		if ( $fallback === '' ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gend-society' ) );
		}
		$tab  = $fallback;
		$meta = $tabs[ $tab ];
	}

	$group_name = gs_group_embed_group_name();
	?>
	<div class="wrap gs-embed-wrap">
		<div class="gs-embed-shell">

			<header class="gs-embed-head">
				<div class="gs-embed-titles">
					<span class="gs-embed-eyebrow">
						<span class="dashicons dashicons-networking" aria-hidden="true"></span>
						<?php echo $group_name !== ''
							? esc_html( sprintf( __( '%s · Connected Web App', 'gend-society' ), $group_name ) )
							: esc_html__( 'Connected Web App', 'gend-society' ); ?>
					</span>
					<h1 class="gs-embed-title"><?php echo esc_html( $meta['label'] ); ?></h1>
					<p class="gs-embed-intro"><?php echo esc_html( $meta['intro'] ); ?></p>
				</div>
			</header>

			<nav class="gs-embed-tabnav" aria-label="<?php esc_attr_e( 'Connected group sections', 'gend-society' ); ?>">
				<?php foreach ( $tabs as $slug => $t ) :
					if ( ! current_user_can( $t['cap'] ) ) continue;
					$url = admin_url( 'admin.php?page=gs-group-embed&tab=' . $slug );
					$is  = $slug === $tab;
				?>
					<a class="gs-embed-tab<?php echo $is ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $is ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $t['icon'] ); ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( $t['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="gs-embed-body" data-gs-embed-tab="<?php echo esc_attr( $tab ); ?>">
				<?php gs_group_embed_render_tab( $tab ); ?>
			</div>

		</div>
	</div>

	<style>
		.gs-embed-wrap { margin: 18px 20px 40px 2px; max-width: 1280px; }
		.gs-embed-shell { opacity: 0; animation: gsEmbedRise 0.6s cubic-bezier(0.2, 0.9, 0.3, 1) 0.04s forwards; }
		.gs-embed-head { margin-bottom: 18px; }
		.gs-embed-eyebrow {
			display: inline-flex; align-items: center; gap: 7px;
			padding: 5px 12px; border-radius: 999px;
			background: rgba(182, 8, 201, 0.10);
			border: 1px solid rgba(182, 8, 201, 0.30);
			color: #d68bf0; font-size: 0.66rem; font-weight: 900;
			text-transform: uppercase; letter-spacing: 1.6px;
		}
		.gs-embed-eyebrow .dashicons { font-size: 14px; width: 14px; height: 14px; }
		.gs-embed-title {
			margin: 12px 0 4px !important; padding: 0 !important;
			color: #fff !important; font-size: 2rem !important; font-weight: 950 !important;
			letter-spacing: -0.5px !important; line-height: 1.05 !important;
		}
		.gs-embed-intro { margin: 0 !important; color: rgba(203, 213, 245, 0.65); font-size: 0.95rem; }

		.gs-embed-tabnav {
			display: flex; flex-wrap: wrap; gap: 6px;
			padding: 6px; margin-bottom: 22px;
			background: rgba(11, 14, 20, 0.45);
			border: 1px solid rgba(255, 255, 255, 0.10);
			border-radius: 14px;
			backdrop-filter: blur(20px) saturate(160%);
			-webkit-backdrop-filter: blur(20px) saturate(160%);
		}
		.gs-embed-tab {
			display: inline-flex; align-items: center; gap: 8px;
			padding: 9px 16px; border-radius: 10px;
			color: rgba(203, 213, 245, 0.7) !important; text-decoration: none;
			font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
			border: 1px solid transparent;
			transition: color 0.2s ease, background 0.2s ease, border-color 0.2s ease;
		}
		.gs-embed-tab .dashicons { font-size: 16px; width: 16px; height: 16px; }
		.gs-embed-tab:hover { color: #fff !important; background: rgba(255, 255, 255, 0.05); }
		.gs-embed-tab.is-active {
			color: #fff !important;
			background: rgba(182, 8, 201, 0.16);
			border-color: rgba(182, 8, 201, 0.45);
			box-shadow: 0 0 16px rgba(182, 8, 201, 0.18);
		}

		.gs-embed-body { opacity: 0; animation: gsEmbedRise 0.55s cubic-bezier(0.2, 0.9, 0.3, 1) 0.18s forwards; }

		@keyframes gsEmbedRise {
			from { opacity: 0; transform: translateY(14px); filter: blur(6px); }
			to   { opacity: 1; transform: none; filter: none; }
		}
		@media (prefers-reduced-motion: reduce) {
			.gs-embed-shell, .gs-embed-body { opacity: 1 !important; animation: none !important; }
		}
		@media (max-width: 782px) {
			.gs-embed-wrap { margin: 14px 12px 40px; }
			.gs-embed-title { font-size: 1.5rem !important; }
		}
	</style>
	<?php
}

/**
 * Dispatch to the matching container-local renderer. Each renderer is
 * always loaded (dashboard-hosting.php / feature-cards.php / feature-access.php)
 * so this works without BuddyPress on every install type.
 */
function gs_group_embed_render_tab( $tab ) {
	switch ( $tab ) {

		case 'hosting':
			if ( function_exists( 'gs_render_hosting_tab' ) ) {
				$payload = function_exists( 'gs_remote_membership_get_cached' ) ? gs_remote_membership_get_cached() : array();
				gs_render_hosting_tab( is_array( $payload ) ? $payload : array() );
				return;
			}
			break;

		case 'compute-gas':
			gs_group_embed_render_compute_gas();
			return;

		case 'organization':
			if ( defined( 'GS_DIR' ) && file_exists( GS_DIR . 'inc/pages/feature-access.php' ) ) {
				require GS_DIR . 'inc/pages/feature-access.php';
				return;
			}
			break;

		case 'feature-suite':
		default:
			if ( function_exists( 'gs_render_feature_cards_widget' ) ) {
				gs_render_feature_cards_widget();
				return;
			}
			break;
	}

	echo '<p style="color:rgba(203,213,245,0.7);">' .
		esc_html__( 'This section is not available on this install yet.', 'gend-society' ) .
		'</p>';
}

/**
 * Self-contained Compute Gas surface — the "how it works" explainer plus a
 * live usage breakdown lazy-loaded from the existing gs_hosting_compute_gas
 * AJAX action (registered in dashboard-hosting.php). Mirrors the compute-gas
 * panel embedded in gs_render_membership_panel() but stands alone so the
 * embed page carries no dependency on the membership panel's markup.
 */
function gs_group_embed_render_compute_gas() {
	$nonce = wp_create_nonce( 'gs_membership_action' );
	$ajax  = admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' );
	?>
	<div class="gs-compute-gas" id="gs-embed-compute-gas">
		<style>
			#gs-embed-compute-gas { display: flex; flex-direction: column; gap: 18px; }
			#gs-embed-compute-gas .gs-cgx-hero { background: linear-gradient(135deg, rgba(78,170,255,0.14) 0%, rgba(168,85,247,0.10) 100%); border: 1px solid rgba(78,170,255,0.25); border-radius: 16px; padding: 24px 28px; }
			#gs-embed-compute-gas .gs-cgx-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: rgba(78,170,255,0.18); color: #4eaaff; border-radius: 999px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; }
			#gs-embed-compute-gas .gs-cgx-badge .dashicons { font-size: 14px; width: 14px; height: 14px; }
			#gs-embed-compute-gas .gs-cgx-title { color: #fff; font-size: 1.35rem; font-weight: 800; margin: 12px 0 8px; }
			#gs-embed-compute-gas .gs-cgx-body { color: #cbd5f5; font-size: 0.95rem; line-height: 1.65; margin: 0; max-width: 780px; }
			#gs-embed-compute-gas .gs-cgx-body strong { color: #fff; }
			#gs-embed-compute-gas .gs-cgx-pillars { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-top: 18px; }
			#gs-embed-compute-gas .gs-cgx-pillar { background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px 16px; }
			#gs-embed-compute-gas .gs-cgx-pillar .dashicons { color: #4eaaff; font-size: 22px; width: 22px; height: 22px; }
			#gs-embed-compute-gas .gs-cgx-pillar-label { color: #fff; font-weight: 700; font-size: 0.95rem; margin-top: 6px; }
			#gs-embed-compute-gas .gs-cgx-pillar-desc { color: #94a3b8; font-size: 0.78rem; margin-top: 4px; line-height: 1.5; }
			#gs-embed-compute-gas .gs-cgx-breakdown { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 22px; }
			#gs-embed-compute-gas .gs-cgx-refresh { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); color: #fff; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; cursor: pointer; }
			#gs-embed-compute-gas .gs-cgx-refresh:hover { background: #fff; color: #000; }
		</style>

		<div class="gs-cgx-hero">
			<span class="gs-cgx-badge"><span class="dashicons dashicons-superhero"></span><?php esc_html_e( 'No monthly server fees', 'gend-society' ); ?></span>
			<h3 class="gs-cgx-title"><?php esc_html_e( 'How Compute Gas Works', 'gend-society' ); ?></h3>
			<p class="gs-cgx-body">
				<?php
				printf(
					/* translators: 1: bold "Blockchain Compute network", 2: bold "Gas Station Nodes" */
					esc_html__( "Networked businesses on GenD don't pay flat monthly server or compute fees. Your storage container integrates with our %1\$s, which uses the unused compute power of the servers and devices running our %2\$s across the network. That same network powers every payment, workflow, and smart contract — so what your app uses, the network earns. You only pay for the compute you actually consume.", 'gend-society' ),
					'<strong>' . esc_html__( 'Blockchain Compute network', 'gend-society' ) . '</strong>',
					'<strong>' . esc_html__( 'Gas Station Nodes', 'gend-society' ) . '</strong>'
				);
				?>
			</p>
			<div class="gs-cgx-pillars">
				<div class="gs-cgx-pillar">
					<span class="dashicons dashicons-money-alt"></span>
					<div class="gs-cgx-pillar-label"><?php esc_html_e( 'Payments', 'gend-society' ); ?></div>
					<div class="gs-cgx-pillar-desc"><?php esc_html_e( 'Every checkout settles on the network — no merchant processor fees.', 'gend-society' ); ?></div>
				</div>
				<div class="gs-cgx-pillar">
					<span class="dashicons dashicons-performance"></span>
					<div class="gs-cgx-pillar-label"><?php esc_html_e( 'Compute', 'gend-society' ); ?></div>
					<div class="gs-cgx-pillar-desc"><?php esc_html_e( 'CPU + RAM for your container is provisioned from unused network capacity.', 'gend-society' ); ?></div>
				</div>
				<div class="gs-cgx-pillar">
					<span class="dashicons dashicons-shield"></span>
					<div class="gs-cgx-pillar-label"><?php esc_html_e( 'Smart Contracts', 'gend-society' ); ?></div>
					<div class="gs-cgx-pillar-desc"><?php esc_html_e( 'Tasks, escrows, and payouts execute on-chain — settlement is the receipt.', 'gend-society' ); ?></div>
				</div>
			</div>
		</div>

		<div class="gs-cgx-breakdown">
			<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
				<div>
					<h4 style="color:#fff; font-size:1.05rem; margin:0;"><?php esc_html_e( 'Your Usage This Period', 'gend-society' ); ?></h4>
					<p style="color:#94a3b8; font-size:0.85rem; margin:4px 0 0;"><?php esc_html_e( 'Live tally of compute gas + container fees for this install.', 'gend-society' ); ?></p>
				</div>
				<button type="button" class="gs-cgx-refresh" data-gs-cgx-refresh><?php esc_html_e( 'Refresh', 'gend-society' ); ?></button>
			</div>
			<div data-gs-cgx-body>
				<div style="color:#94a3b8; font-style:italic; padding:18px; text-align:center;"><?php esc_html_e( 'Loading cost breakdown…', 'gend-society' ); ?></div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var root = document.getElementById('gs-embed-compute-gas');
		if (!root) return;
		var ajax  = <?php echo wp_json_encode( $ajax ); ?>;
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var body  = root.querySelector('[data-gs-cgx-body]');

		function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }

		function render(d) {
			var period = esc(d.period || 'This period');
			var card = function (label, value, accent) {
				return '<div style="background:' + (accent ? 'linear-gradient(135deg, rgba(78,170,255,0.18), rgba(168,85,247,0.12))' : 'rgba(0,0,0,0.25)') +
					'; border:1px solid ' + (accent ? 'rgba(78,170,255,0.35)' : 'rgba(255,255,255,0.08)') + '; border-radius:12px; padding:14px 16px;">' +
					'<div style="color:' + (accent ? '#a5b4fc' : '#94a3b8') + '; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; font-weight:' + (accent ? '700' : '400') + ';">' + label + '</div>' +
					'<div style="color:#fff; font-size:1.5rem; font-weight:700; margin-top:6px;">' + value + '</div>' +
					'<div style="color:' + (accent ? '#a5b4fc' : '#94a3b8') + '; font-size:0.75rem; margin-top:4px;">' + period + '</div></div>';
			};
			var html = '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:18px;">' +
				card('Compute Gas', esc(d.gas_fees_label || '$0.00'), false) +
				card('Container Fees', esc(d.container_fees_label || '$0.00'), false) +
				card('Total', esc(d.total_label || '$0.00'), true) +
				'</div>';
			if (Array.isArray(d.breakdown) && d.breakdown.length) {
				html += '<table style="width:100%; border-collapse:collapse; font-size:0.85rem;"><thead><tr>' +
					['Item','Qty','Amount'].map(function(h, i){ return '<th style="text-align:' + (i===2?'right':'left') + '; padding:8px 12px; color:#94a3b8; text-transform:uppercase; font-size:0.7rem; letter-spacing:0.06em; border-bottom:1px solid rgba(255,255,255,0.06);">' + h + '</th>'; }).join('') +
					'</tr></thead><tbody>';
				d.breakdown.forEach(function (b) {
					html += '<tr>' +
						'<td style="padding:10px 12px; color:#e6edf7; border-bottom:1px solid rgba(255,255,255,0.04);">' + esc(b.label || '') + '</td>' +
						'<td style="padding:10px 12px; color:#e6edf7; border-bottom:1px solid rgba(255,255,255,0.04);">' + esc(b.qty || '') + '</td>' +
						'<td style="padding:10px 12px; color:#e6edf7; text-align:right; border-bottom:1px solid rgba(255,255,255,0.04);">' + esc(b.amount_label || '') + '</td>' +
						'</tr>';
				});
				html += '</tbody></table>';
			} else {
				html += '<p style="color:#94a3b8; font-style:italic; padding:14px 0; margin:0;">' +
					(d.message ? esc(d.message) : 'No itemized breakdown available yet for this billing period.') + '</p>';
			}
			body.innerHTML = html;
		}

		function load() {
			if (!body) return;
			body.innerHTML = '<div style="color:#94a3b8; font-style:italic; padding:18px; text-align:center;">Loading cost breakdown…</div>';
			fetch(ajax, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({ action: 'gs_hosting_compute_gas', nonce: nonce }).toString()
			})
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (resp && resp.success && resp.data) { render(resp.data); }
					else { body.innerHTML = '<p style="color:#94a3b8; padding:14px;">' + ((resp && resp.data && resp.data.message) || 'Compute Gas data unavailable.') + '</p>'; }
				})
				.catch(function () { body.innerHTML = '<p style="color:#fca5a5; padding:14px;">Network error loading Compute Gas.</p>'; });
		}

		root.addEventListener('click', function (e) {
			if (e.target.closest('[data-gs-cgx-refresh]')) { load(); }
		});
		load();
	})();
	</script>
	<?php
}
