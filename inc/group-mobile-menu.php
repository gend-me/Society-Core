<?php
/**
 * gend-society — Mobile group menu: sticky folder button + dropdown (2026-08-17).
 *
 * On phones, BuddyPress group pages hide the bridge-header menu row
 * (div.bridge-row inside #bridge-master — Business Plan / Contracts /
 * Marketing / Payments / Organization / …) and replace it with a sticky
 * top-centre file-folder button. Tapping it drops down a glass tray listing
 * every group menu item (built by JS from the hidden row's own links, so
 * whatever tabs a group has — including per-group additions — appear
 * automatically). Desktop keeps the full menu row.
 *
 * Renders on wp_footer for ANY viewer of a group page (logged-in or not);
 * everything is display-gated to ≤720px so wider screens never see it.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'gs_group_mobile_menu_render', 12 );
function gs_group_mobile_menu_render() {
	if ( ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) {
		return;
	}
	?>
	<!-- ── Mobile group menu: sticky folder button + dropdown ─────────── -->
	<div id="gs-gm-menu" class="gs-gm-menu" hidden>
		<button type="button" id="gs-gm-btn" class="gs-gm-btn" aria-haspopup="menu" aria-expanded="false"
			aria-label="<?php esc_attr_e( 'Group menu', 'gend-society' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
			</svg>
			<span class="gs-gm-btn__caret" aria-hidden="true">&#9662;</span>
		</button>
		<div id="gs-gm-drop" class="gs-gm-drop" role="menu" hidden></div>
	</div>

	<style>
	.gs-gm-menu { display: none; }
	@media (max-width: 720px) {
		/* Hide ONLY the bridge-header MENU row on phones (the row holding
		   the group tab links — identity/action rows stay). :has() pre-hides
		   it without a flash; the JS below also tags it as a fallback. */
		.bp-bridge-header-container .bridge-row:has(a[href*="/business-plan"]) { display: none !important; }
		.bp-bridge-header-container .bridge-row.gs-gm-hidden { display: none !important; }
		/* Youzify's sticky mobile "Menu" bar duplicates group navigation on
		   phones — the folder button replaces it on single-group pages. */
		body.groups.single-item .youzify-mobile-nav,
		body.groups.single-item .youzify-inline-mobile-nav,
		body.groups.single-item #youzify-mobile-menu { display: none !important; }
		/* The scroll-triggered sticky replica of the group menu — replaced
		   by the folder button on phones too. */
		body.groups.single-item #bridge-sticky-bar { display: none !important; }

		.gs-gm-menu {
			display: block;
			position: fixed;
			top: 10px;
			left: 50%;
			transform: translateX(-50%);
			z-index: 99990;
		}
		.gs-gm-btn {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 10px 16px;
			border-radius: 999px;
			border: 1px solid rgba(182,8,201,0.45);
			background: rgba(11,14,20,0.82);
			color: #e879ff;
			cursor: pointer;
			backdrop-filter: blur(14px) saturate(150%);
			-webkit-backdrop-filter: blur(14px) saturate(150%);
			box-shadow: 0 6px 24px rgba(0,0,0,0.5), 0 0 18px rgba(182,8,201,0.25);
			transition: box-shadow 0.2s ease, transform 0.2s ease, color 0.2s ease;
		}
		.gs-gm-btn:active { transform: scale(0.95); }
		.gs-gm-btn svg { width: 20px; height: 20px; display: block; }
		.gs-gm-btn__caret { font-size: 0.7rem; transition: transform 0.25s ease; }
		.gs-gm-btn.is-open { color: #fff; box-shadow: 0 6px 24px rgba(0,0,0,0.5), 0 0 26px rgba(182,8,201,0.45); }
		.gs-gm-btn.is-open .gs-gm-btn__caret { transform: rotate(180deg); }

		.gs-gm-drop {
			position: fixed;
			top: 58px;
			left: 50%;
			transform: translateX(-50%) translateY(-8px);
			width: min(320px, calc(100vw - 24px));
			max-height: 70vh;
			overflow-y: auto;
			padding: 10px;
			border-radius: 16px;
			background: rgba(11,14,20,0.94);
			border: 1px solid rgba(255,255,255,0.12);
			backdrop-filter: blur(18px) saturate(150%);
			-webkit-backdrop-filter: blur(18px) saturate(150%);
			box-shadow: 0 20px 60px rgba(0,0,0,0.65), 0 0 30px rgba(182,8,201,0.18);
			opacity: 0;
			pointer-events: none;
			transition: opacity 0.22s ease, transform 0.22s ease;
			display: flex;
			flex-direction: column;
			gap: 6px;
		}
		.gs-gm-drop.is-open {
			opacity: 1;
			pointer-events: auto;
			transform: translateX(-50%) translateY(0);
		}
		.gs-gm-drop a {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 10px 12px;
			border-radius: 14px;
			border: 1px solid rgba(255,255,255,0.08);
			background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.015));
			color: #cbd5f5 !important;
			font-family: "Inter", sans-serif;
			font-size: 0.7rem;
			font-weight: 800;
			letter-spacing: 0.8px;
			text-transform: uppercase;
			text-align: left;
			text-decoration: none !important;
			opacity: 0;
			transform: translateY(12px) scale(0.98);
		}
		.gs-gm-drop.is-open a {
			animation: gsGmItemIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
			animation-delay: calc(var(--gm-i, 0) * 45ms + 60ms);
		}
		.gs-gm-drop a .gs-gm-ic {
			width: 34px;
			height: 34px;
			border-radius: 10px;
			flex: 0 0 auto;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: rgba(182,8,201,0.10);
			border: 1px solid rgba(182,8,201,0.3);
			color: #e879ff;
			box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
		}
		.gs-gm-drop a .gs-gm-ic svg { width: 16px; height: 16px; display: block; }
		.gs-gm-drop a .gs-gm-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.gs-gm-drop a.is-current,
		.gs-gm-drop a:active {
			background: rgba(182,8,201,0.16);
			border-color: rgba(182,8,201,0.5);
			color: #fff !important;
			box-shadow: 0 0 16px rgba(182,8,201,0.22), inset 0 0 12px rgba(182,8,201,0.08);
		}
		.gs-gm-drop a.is-current .gs-gm-ic {
			background: rgba(182,8,201,0.22);
			border-color: rgba(182,8,201,0.55);
			color: #fff;
		}
		@media (prefers-reduced-motion: reduce) {
			.gs-gm-drop a { opacity: 1; transform: none; }
			.gs-gm-drop.is-open a { animation: none; }
		}

		/* ── Business Plan chapters + slide titles → overlay dropdowns ──
		   Custom (not <select> — Youzify nice-select hijacks those and its
		   inline list gets clipped inside the scrollable sidenav). The list
		   is position:fixed at body level, so it always drops OVER the
		   content below the section. */
		.psoo-bp__sidenav-list.gs-bp-hidden { display: none !important; }
		.psoo-bp__slides button.tab-link { display: none !important; }
		/* The emptied pill-tabs container still occupies space between the
		   dropdown and the right arrow — remove the box itself, and lay the
		   control row out so the dropdown truly spans arrow-to-arrow. */
		.psoo-bp__slides .gallery-menu-tabs { display: none !important; }
		.psoo-bp__slides .psoo-bp__slide-controls {
			display: flex !important;
			align-items: center !important;
			justify-content: space-between !important;
			gap: 8px !important;
		}
		.gs-dd-btn {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			width: 100%;
			padding: 13px 16px;
			margin: 0 0 14px;
			border-radius: 14px;
			background: rgba(11,14,20,0.85);
			border: 1px solid rgba(182,8,201,0.4);
			color: #fff;
			font-family: "Inter", sans-serif;
			font-size: 0.7rem;
			font-weight: 800;
			letter-spacing: 0.8px;
			text-transform: uppercase;
			cursor: pointer;
			backdrop-filter: blur(12px);
			-webkit-backdrop-filter: blur(12px);
			box-shadow: 0 0 18px rgba(182,8,201,0.18);
			text-align: left;
		}
		.gs-dd-btn .gs-dd-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.gs-dd-btn .gs-dd-caret { color: #e879ff; font-size: 0.85rem; transition: transform 0.25s ease; flex: 0 0 auto; }
		.gs-dd-btn.is-open .gs-dd-caret { transform: rotate(180deg); }
		.gs-dd-btn--slide {
			width: auto;
			flex: 1 1 auto;
			margin: 0;
			padding: 11px 34px;
			position: relative;
			justify-content: center;
		}
		/* Optically centred title: symmetric side padding + the caret
		   pinned absolutely so it cannot push the text off-centre. */
		.gs-dd-btn--slide .gs-dd-label { flex: 0 1 auto; text-align: center; }
		.gs-dd-btn--slide .gs-dd-caret {
			position: absolute;
			right: 12px;
			top: 50%;
			transform: translateY(-50%);
		}
		.gs-dd-btn--slide.is-open .gs-dd-caret {
			transform: translateY(-50%) rotate(180deg);
		}
		/* Breathing room between the slide nav row and the bottom of the
		   glass section. */
		.psoo-bp__slides-viewer { padding-bottom: 16px !important; }
	}
	/* Overlay list — body-level, unclippable */
	.gs-dd-list {
		position: fixed;
		z-index: 999995;
		box-sizing: border-box;
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 10px;
		border-radius: 16px;
		background: rgba(11,14,20,0.95);
		border: 1px solid rgba(255,255,255,0.12);
		backdrop-filter: blur(18px) saturate(150%);
		-webkit-backdrop-filter: blur(18px) saturate(150%);
		box-shadow: 0 20px 60px rgba(0,0,0,0.65), 0 0 30px rgba(182,8,201,0.18);
		max-height: 60vh;
		overflow-y: auto;
		opacity: 0;
		pointer-events: none;
		transform: translateY(-8px);
		transition: opacity 0.2s ease, transform 0.2s ease;
	}
	.gs-dd-list.is-open { opacity: 1; pointer-events: auto; transform: none; }
	.gs-dd-item {
		display: block;
		width: 100%;
		padding: 12px 14px;
		border-radius: 12px;
		border: 1px solid rgba(255,255,255,0.08);
		background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.015));
		color: #cbd5f5;
		font-family: "Inter", sans-serif;
		font-size: 0.68rem;
		font-weight: 800;
		letter-spacing: 0.7px;
		text-transform: uppercase;
		text-align: left;
		cursor: pointer;
		opacity: 0;
		transform: translateY(10px);
	}
	.gs-dd-list.is-open .gs-dd-item {
		animation: gsGmItemIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
		animation-delay: calc(var(--dd-i, 0) * 40ms + 40ms);
	}
	.gs-dd-item.is-current {
		background: rgba(182,8,201,0.16);
		border-color: rgba(182,8,201,0.5);
		color: #fff;
	}
	.gs-dd-list[data-for*="slide"] .gs-dd-item { text-align: center; }

	/* ── Navigation loading feedback (group menu taps) ─────────────────── */
	#gs-gm-progress {
		position: fixed;
		top: 0; left: 0; right: 0;
		height: 3px;
		z-index: 2000000000;
		background: linear-gradient(90deg, transparent, #b608c9, #89C2E0, transparent);
		background-size: 50% 100%;
		background-repeat: no-repeat;
		animation: gsGmProgSlide 1s linear infinite;
		opacity: 0;
		pointer-events: none;
		transition: opacity 0.15s ease;
	}
	#gs-gm-progress.is-on { opacity: 1; }
	@keyframes gsGmProgSlide {
		from { background-position: -60% 0; }
		to   { background-position: 160% 0; }
	}
	#gs-gm-spinner {
		position: fixed;
		top: 42%; left: 50%;
		transform: translate(-50%, -50%);
		z-index: 2000000000;
		display: none;
		align-items: center;
		gap: 10px;
		padding: 12px 22px;
		border-radius: 999px;
		background: rgba(11,14,20,0.88);
		border: 1px solid rgba(182,8,201,0.45);
		color: #fff;
		font-family: "Inter", sans-serif;
		font-size: 0.68rem;
		font-weight: 800;
		letter-spacing: 1.4px;
		text-transform: uppercase;
		backdrop-filter: blur(12px);
		-webkit-backdrop-filter: blur(12px);
		box-shadow: 0 0 28px rgba(182,8,201,0.3);
		pointer-events: none;
	}
	#gs-gm-spinner.is-on { display: flex; }
	#gs-gm-spinner .dot {
		width: 15px; height: 15px;
		border-radius: 50%;
		border: 2px solid rgba(255,255,255,0.22);
		border-top-color: #b608c9;
		animation: gsGmSpin 0.7s linear infinite;
		flex: 0 0 auto;
	}
	@keyframes gsGmSpin { to { transform: rotate(360deg); } }
	@media (prefers-reduced-motion: reduce) {
		.gs-dd-item { opacity: 1; transform: none; }
		.gs-dd-list.is-open .gs-dd-item { animation: none; }
	}
	@media (min-width: 721px) {
		.gs-dd-list { display: none !important; }
	}
	@media (max-width: 720px) {

		/* ── Presentation slides: glass viewer, scaled to fit phones ──── */
		.psoo-bp__slides,
		.psoo-bp__slides-viewer {
			width: 100% !important;
			max-width: 100% !important;
			margin-left: 0 !important;
			margin-right: 0 !important;
		}
		/* Keep the whole slides block in natural flow so the editor toolbar
		   (Edit slides / Remove) sits BELOW the presentation inside this
		   section instead of painting over the next one. */
		.psoo-bp__slides {
			height: auto !important;
			max-height: none !important;
			overflow: visible !important;
			display: flex !important;
			flex-direction: column !important;
		}
		.psoo-bp__slides-tools {
			position: static !important;
			order: 99;
			display: flex !important;
			flex-wrap: wrap;
			align-items: center;
			gap: 10px;
			margin: 12px 0 0 !important;
		}
		.psoo-bp__slides-tools .psoo-bp__slides-btn { position: static !important; }
		section[id^="psoo-bp-section-"] {
			height: auto !important;
			max-height: none !important;
			overflow: visible !important;
		}
		.psoo-bp__slides-viewer {
			position: relative;
			border-radius: 18px;
			overflow: hidden;
			background: linear-gradient(160deg, rgba(255,255,255,0.06), rgba(255,255,255,0.015));
			border: 1px solid rgba(255,255,255,0.14);
			backdrop-filter: blur(16px) saturate(140%);
			-webkit-backdrop-filter: blur(16px) saturate(140%);
			box-shadow: inset 0 1px 0 rgba(255,255,255,0.07), 0 22px 50px -28px rgba(0,0,0,0.75), 0 0 30px rgba(182,8,201,0.12);
			animation: gsSlideRise 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
			transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
		}
		/* 3D jewel ring on press/hover */
		.psoo-bp__slides-viewer::after {
			content: "";
			position: absolute;
			inset: -1px;
			border-radius: inherit;
			padding: 1px;
			background: conic-gradient(from 210deg, rgba(182,8,201,0.85), rgba(137,194,224,0.6), rgba(0,255,136,0.4), rgba(182,8,201,0.85));
			-webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
			-webkit-mask-composite: xor;
			mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
			mask-composite: exclude;
			opacity: 0;
			transition: opacity 0.3s ease;
			pointer-events: none;
		}
		.psoo-bp__slides-viewer:hover,
		.psoo-bp__slides-viewer:active {
			border-color: rgba(182,8,201,0.45);
			transform: translateY(-3px);
			box-shadow: inset 0 1px 0 rgba(255,255,255,0.07), 0 30px 60px -28px rgba(0,0,0,0.85), 0 0 44px rgba(182,8,201,0.3);
		}
		.psoo-bp__slides-viewer:hover::after,
		.psoo-bp__slides-viewer:active::after { opacity: 1; }
		/* Staggered entrance for the composition inside each slide */
		.psd-slide > * {
			opacity: 0;
			animation: gsSlideChild 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards;
		}
		.psd-slide > *:nth-child(1) { animation-delay: 0.12s; }
		.psd-slide > *:nth-child(2) { animation-delay: 0.20s; }
		.psd-slide > *:nth-child(3) { animation-delay: 0.28s; }
		.psd-slide > *:nth-child(4) { animation-delay: 0.36s; }
		.psd-slide > *:nth-child(5) { animation-delay: 0.44s; }
		.psd-slide > *:nth-child(6) { animation-delay: 0.52s; }
		.psd-slide > *:nth-child(7) { animation-delay: 0.60s; }
		.psd-slide > *:nth-child(n+8) { animation-delay: 0.68s; }
		@media (prefers-reduced-motion: reduce) {
			.psoo-bp__slides-viewer { animation: none; }
			.psd-slide > * { opacity: 1; animation: none; }
		}
	}
	@keyframes gsSlideRise {
		from { opacity: 0; transform: translateY(24px) scale(0.97); filter: blur(8px); }
		to   { opacity: 1; transform: none; filter: none; }
	}
	@keyframes gsSlideChild {
		from { opacity: 0; transform: translateY(14px); }
		to   { opacity: 1; transform: none; }
	}
	@keyframes gsGmItemIn {
		to { opacity: 1; transform: none; }
	}
	</style>

	<script>
	(function () {
		if (window.__gsGroupMobileMenu) return;
		window.__gsGroupMobileMenu = true;

		// ── Loading feedback for real navigations from the group menu ───
		function showNavLoading() {
			var bar = document.getElementById('gs-gm-progress');
			if (!bar) {
				bar = document.createElement('div');
				bar.id = 'gs-gm-progress';
				document.body.appendChild(bar);
				var pill = document.createElement('div');
				pill.id = 'gs-gm-spinner';
				pill.innerHTML = '<span class="dot" aria-hidden="true"></span><span>Loading</span>';
				document.body.appendChild(pill);
			}
			bar.classList.add('is-on');
			document.getElementById('gs-gm-spinner').classList.add('is-on');
		}
		function hideNavLoading() {
			var bar = document.getElementById('gs-gm-progress');
			var pill = document.getElementById('gs-gm-spinner');
			if (bar) bar.classList.remove('is-on');
			if (pill) pill.classList.remove('is-on');
		}
		// Back/forward restores from bfcache keep the old DOM — clear it.
		window.addEventListener('pageshow', function (e) { if (e.persisted) hideNavLoading(); });

		function init() {
			var wrap = document.getElementById('gs-gm-menu');
			var btn  = document.getElementById('gs-gm-btn');
			var drop = document.getElementById('gs-gm-drop');
			if (!wrap || !btn || !drop) return;

			// Build the dropdown from the bridge MENU row — the header has
			// several .bridge-row bands (identity, actions, menu), so pick
			// the one carrying the most group tab links.
			var rows = document.querySelectorAll('.bp-bridge-header-container .bridge-row');
			var row = null, best = 0;
			rows.forEach(function (r) {
				var n = r.querySelectorAll('a[href*="/groups/"]').length;
				if (n > best) { best = n; row = r; }
			});
			if (!row || best < 3) return;
			row.classList.add('gs-gm-hidden');
			var links = Array.prototype.filter.call(
				row.querySelectorAll('a[href*="/groups/"]'),
				function (a) { return (a.textContent || '').trim().length > 0; }
			);
			if (!links.length) return;

			// Per-tab icons keyed by the group tab slug (stroke = currentColor
			// so states recolor them automatically). Unknown tabs fall back
			// to a folder.
			var ICONS = {
				'business-plan': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',
				'projects': '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>',
				'files': '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
				'payments': '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
				'members-hub': '<circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0-3-3.85"/>',
				'feature-suite': '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
				'hosting': '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><line x1="6" y1="6.5" x2="6.01" y2="6.5"/><line x1="6" y1="17.5" x2="6.01" y2="17.5"/>',
				'compute-gas': '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
				'davinci-ai': '<path d="M12 3l1.7 4.6L18 9.3l-4.3 1.7L12 15.6l-1.7-4.6L6 9.3l4.3-1.7z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
				'collab': '<path d="M11 17l-4.5-4.5a3 3 0 0 1 0-4.2v0a3 3 0 0 1 4.2 0L12 9.6l1.3-1.3a3 3 0 0 1 4.2 0v0a3 3 0 0 1 0 4.2L13 17z"/>'
			};
			var DEFAULT_ICON = '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>';
			function iconFor(href) {
				var m = String(href).match(/\/groups\/[^\/]+\/([a-z0-9_-]+)/i);
				var slug = m ? m[1].toLowerCase() : '';
				return ICONS[slug] || DEFAULT_ICON;
			}

			var here = location.pathname.replace(/\/+$/, '');
			links.forEach(function (a, idx) {
				var item = document.createElement('a');
				item.href = a.href;
				item.setAttribute('role', 'menuitem');
				item.style.setProperty('--gm-i', idx);
				item.innerHTML =
					'<span class="gs-gm-ic" aria-hidden="true">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + iconFor(a.href) + '</svg>' +
					'</span><span class="gs-gm-label"></span>';
				item.querySelector('.gs-gm-label').textContent = (a.textContent || '').trim();
				try {
					var p = new URL(a.href, location.href).pathname.replace(/\/+$/, '');
					if (p === here || (p.length > 1 && here.indexOf(p) === 0)) item.classList.add('is-current');
				} catch (e) { /* keep default */ }
				// Real navigation — light the loading screen the moment the
				// tap lands so the wait is visible.
				item.addEventListener('click', function () { showNavLoading(); });
				drop.appendChild(item);
			});
			wrap.hidden = false;
			drop.hidden = false; // visibility handled by opacity/pointer-events

			function setOpen(open) {
				drop.classList.toggle('is-open', open);
				btn.classList.toggle('is-open', open);
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			}
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				setOpen(!drop.classList.contains('is-open'));
			});
			document.addEventListener('click', function (e) {
				if (!drop.classList.contains('is-open')) return;
				if (e.target.closest('#gs-gm-menu')) return;
				setOpen(false);
			});
			document.addEventListener('keyup', function (e) {
				if (e.key === 'Escape') setOpen(false);
			});
		}
		// ── Shared overlay dropdown (body-level fixed list — unclippable,
		// drops over whatever sits below the trigger). ──────────────────
		var openDD = null;
		function closeDD() {
			if (!openDD) return;
			openDD.list.classList.remove('is-open');
			openDD.btn.classList.remove('is-open');
			openDD = null;
		}
		document.addEventListener('click', function (e) {
			if (openDD && !e.target.closest('.gs-dd-list') && !e.target.closest('.gs-dd-btn')) closeDD();
		});
		window.addEventListener('scroll', closeDD, true);
		document.addEventListener('keyup', function (e) { if (e.key === 'Escape') closeDD(); });

		function buildDD(cls, ariaLabel, current, getItems, getBounds) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'gs-dd-btn ' + (cls || '');
			btn.setAttribute('aria-haspopup', 'listbox');
			btn.setAttribute('aria-label', ariaLabel);
			btn.innerHTML = '<span class="gs-dd-label"></span><span class="gs-dd-caret" aria-hidden="true">&#9662;</span>';
			var list = document.createElement('div');
			list.className = 'gs-dd-list';
			list.setAttribute('data-for', cls || '');
			list.setAttribute('role', 'listbox');
			document.body.appendChild(list);
			function paint() { btn.querySelector('.gs-dd-label').textContent = current(); }
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				if (openDD && openDD.btn === btn) { closeDD(); return; }
				closeDD();
				list.innerHTML = '';
				getItems().forEach(function (it, i) {
					var b = document.createElement('button');
					b.type = 'button';
					b.className = 'gs-dd-item' + (it.current ? ' is-current' : '');
					b.style.setProperty('--dd-i', i);
					b.textContent = it.label;
					b.addEventListener('click', function (ev) {
						ev.stopPropagation();
						closeDD();
						it.pick();
						window.setTimeout(paint, 150);
					});
					list.appendChild(b);
				});
				var r = btn.getBoundingClientRect();
				var b = getBounds ? getBounds() : null;
				var w = b ? Math.round(b.width)
					: Math.round(Math.min(Math.max(r.width, 240), window.innerWidth - 16));
				w = Math.min(w, window.innerWidth - 16);
				var left = b ? Math.round(b.left) : Math.round(r.left);
				left = Math.max(8, Math.min(left, window.innerWidth - 8 - w));
				list.style.width = w + 'px';
				list.style.top = Math.round(r.bottom + 8) + 'px';
				list.style.left = left + 'px';
				list.classList.add('is-open');
				btn.classList.add('is-open');
				openDD = { btn: btn, list: list };
			});
			paint();
			return { btn: btn, paint: paint };
		}

		// ── Business Plan chapters → overlay dropdown on phones ────────
		// All queries are LIVE — the plan rebuilds the chapter list when a
		// chapter loads, so captured nodes go stale immediately.
		function bpChapterItems() {
			var l = document.querySelector('.psoo-bp__sidenav-list');
			if (!l) return [];
			return Array.prototype.filter.call(
				l.querySelectorAll('a, button'),
				function (it) { return (it.textContent || '').trim().length > 0; }
			);
		}
		function bpIsCurrent(it) {
			var li = it.closest('li');
			return !!((li && /active|current/.test(li.className)) || /active|current/.test(String(it.className)));
		}
		function initBpChapters() {
			if (!window.matchMedia || !window.matchMedia('(max-width: 720px)').matches) return;
			var list = document.querySelector('.psoo-bp__sidenav-list');
			if (!list || list.dataset.gsSelectized) return;
			if (bpChapterItems().length < 2) return;
			list.dataset.gsSelectized = '1';
			var stale = list.parentNode.querySelector('.gs-dd-btn--chapters');
			if (stale) stale.remove();

			function currentChapter() {
				var t = bpChapterItems();
				for (var i = 0; i < t.length; i++) {
					if (bpIsCurrent(t[i])) return (t[i].textContent || '').trim();
				}
				return t.length ? (t[0].textContent || '').trim() : '';
			}
			var dd = buildDD('gs-dd-btn--chapters', 'Select chapter', currentChapter, function () {
				return bpChapterItems().map(function (it) {
					return {
						label: (it.textContent || '').trim(),
						current: bpIsCurrent(it),
						pick: function () { it.click(); window.setTimeout(dd.paint, 600); }
					};
				});
			});
			list.parentNode.insertBefore(dd.btn, list);
			list.classList.add('gs-bp-hidden');

			// The plan may replace the whole list node on chapter switch —
			// keep the replacement hidden, the button in place, label fresh.
			var aside = list.closest('aside') || list.parentNode;
			if (window.MutationObserver) {
				new MutationObserver(function () {
					var l = document.querySelector('.psoo-bp__sidenav-list');
					if (!l) return;
					if (!l.classList.contains('gs-bp-hidden')) l.classList.add('gs-bp-hidden');
					l.dataset.gsSelectized = '1';
					if (!l.parentNode.querySelector('.gs-dd-btn--chapters')) {
						l.parentNode.insertBefore(dd.btn, l);
					}
					dd.paint();
				}).observe(aside, { childList: true, subtree: true });
			}
		}

		// ── Slide titles → overlay dropdown between the prev/next arrows ─
		function initSlideNav() {
			if (!window.matchMedia || !window.matchMedia('(max-width: 720px)').matches) return;
			var host = document.querySelector('.psoo-bp__slides');
			if (!host) return;
			var arrowL = host.querySelector('button.nav-arrow.q-prev, button.q-prev');
			if (!arrowL || arrowL.dataset.gsSlideDD) return;
			var tabs = function () {
				return Array.prototype.filter.call(
					host.querySelectorAll('button.tab-link'),
					function (b) { return (b.textContent || '').trim().length > 0; }
				);
			};
			if (tabs().length < 1) return;
			arrowL.dataset.gsSlideDD = '1';

			function currentSlide() {
				var t = tabs();
				for (var i = 0; i < t.length; i++) {
					if (/active/.test(t[i].className)) return (t[i].textContent || '').trim();
				}
				return t.length ? (t[0].textContent || '').trim() : '';
			}
			var dd = buildDD('gs-dd-btn--slide', 'Select slide', currentSlide, function () {
				return tabs().map(function (b) {
					return {
						label: (b.textContent || '').trim(),
						current: /active/.test(b.className),
						pick: function () { b.click(); }
					};
				});
			}, function () {
				// Span the overlay the full width between the ‹ › arrows.
				var l = host.querySelector('button.q-prev');
				var r2 = host.querySelector('button.q-next');
				if (!l || !r2) return null;
				var lr = l.getBoundingClientRect();
				var rr = r2.getBoundingClientRect();
				var w = rr.left - lr.right - 12;
				if (w < 160) return null;
				return { left: lr.right + 6, width: w };
			});
			arrowL.parentNode.insertBefore(dd.btn, arrowL.nextSibling);
			// Keep the label in sync when arrows change the slide too.
			host.addEventListener('click', function (e) {
				if (e.target.closest('button.nav-arrow, button.q-prev, button.q-next')) {
					window.setTimeout(dd.paint, 150);
				}
			});
		}

		// ── Presentation slides: scale the fixed-size composition to fit
		// the phone viewport (the deck ships at ~518px natural width).
		// Inverse of fitSlides(): strip every inline override it applied so a
		// resize BACK above 720px restores the desktop layout (previously the
		// phone width/scale styles stuck and the deck rendered tiny+offset).
		function unfitSlides() {
			document.querySelectorAll('.psoo-bp__slides-viewer').forEach(function (v) {
				if (!v.dataset.gsFitApplied) return;
				delete v.dataset.gsFitApplied;
				var slide = v.querySelector('.psd-slide');
				var scope = slide ? slide.parentElement : null;
				var stop = document.querySelector('.youzify-page-main-content') || document.body;
				var n = v, depth = 0;
				while (n && n !== stop && n !== document.body && depth < 12) {
					n.style.removeProperty('max-width');
					n.style.removeProperty('margin-left');
					n.style.removeProperty('margin-right');
					n.style.removeProperty('width');
					n.style.removeProperty('padding-left');
					n.style.removeProperty('padding-right');
					n = n.parentElement;
					depth++;
				}
				if (scope) {
					scope.style.removeProperty('transform');
					scope.style.removeProperty('transform-origin');
					scope.style.removeProperty('height');
				}
			});
		}
		function fitSlides() {
			if (!window.matchMedia || !window.matchMedia('(max-width: 720px)').matches) { unfitSlides(); return; }
			document.querySelectorAll('.psoo-bp__slides-viewer').forEach(function (v) {
				var slide = v.querySelector('.psd-slide');
				if (!slide) return;
				var scope = slide.parentElement;
				if (!scope) return;
				v.dataset.gsFitApplied = '1'; // unfitSlides() reverses on desktop resize
				// The plan layout can leave the whole slides column wider than
				// the phone (fixed columns + side padding) — normalise the
				// ancestor chain first so the viewer truly spans the screen.
				var stop = document.querySelector('.youzify-page-main-content') || document.body;
				var n = v, depth = 0;
				while (n && n !== stop && n !== document.body && depth < 12) {
					n.style.setProperty('max-width', '100%', 'important');
					n.style.setProperty('margin-left', '0', 'important');
					n.style.setProperty('margin-right', '0', 'important');
					if (n !== v) { n.style.setProperty('width', 'auto', 'important'); }
					if (/psoo-bp/.test(String(n.className))) {
						n.style.setProperty('padding-left', '10px', 'important');
						n.style.setProperty('padding-right', '10px', 'important');
					}
					n = n.parentElement;
					depth++;
				}
				// Pin the glass frame itself to the viewport — the broken
				// column can leave it wide AND offset, so fix both directly.
				var vw = (window.innerWidth || 390) - 24;
				v.style.setProperty('width', vw + 'px', 'important');
				v.style.setProperty('max-width', vw + 'px', 'important');
				var vr = v.getBoundingClientRect();
				if (vr.left > 12 || vr.left < 0) {
					v.style.setProperty('margin-left', (12 - vr.left) + 'px', 'important');
				}
				scope.style.transform = 'none';
				scope.style.height = '';
				var natural = slide.offsetWidth;
				var avail = v.clientWidth;
				if (!natural || !avail || avail >= natural) return;
				var s = avail / natural;
				scope.style.transformOrigin = 'top left';
				scope.style.transform = 'scale(' + s + ')';
				scope.style.height = Math.ceil(slide.offsetHeight * s) + 'px';
			});
		}
		var fitTimer = null;
		function fitSoon() {
			if (fitTimer) return;
			fitTimer = setTimeout(function () { fitTimer = null; fitSlides(); }, 120);
		}
		window.addEventListener('resize', fitSoon);
		var slidesHost = null;
		function watchSlides() {
			var host = document.querySelector('.psoo-bp__slides');
			if (!host || host === slidesHost || !window.MutationObserver) return;
			slidesHost = host;
			new MutationObserver(fitSoon).observe(host, { childList: true, subtree: true });
		}

		function boot() {
			init();
			initBpChapters();
			initSlideNav();
			fitSlides();
			watchSlides();
			// The plan surface can render late — retry a few times.
			[1200, 3000, 6000].forEach(function (d) {
				setTimeout(function () { initBpChapters(); initSlideNav(); fitSlides(); watchSlides(); }, d);
			});
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', boot);
		} else {
			boot();
		}
	}());
	</script>
	<?php
}
