<?php
/**
 * gend-society — GenD Match launcher on the group Organization page
 * (2026-08-16, relocated to Organization 2026-09-16).
 *
 * Fourth (and current) home of the GenD Match entry point:
 *   Phase 82  — group nav "Match" tab (group-tab-collab.php, now nav-hidden)
 *   Phase 102 — member-profile "Match" tab with an "Acting as" business
 *               picker (member-tab-collab.php, nav registration now retired)
 *   Phase ??  — a "Get Matched With Business Partners" popup button at the
 *               top of each group's Payments page (/groups/{slug}/payments/)
 *   NOW       — moved (operator directive) to the top of each group's
 *               Organization page instead (/groups/{slug}/members-hub/,
 *               the "members-hub" gdc_group_page slug — see
 *               group-members-screen.php). The group context is implicit —
 *               the current page's own group — so there is NO business
 *               picker and NO "Acting as" section: the launcher passes
 *               bp_get_current_group_id() straight into
 *               GS_Group_Tab_Collab::render_panel(), which reuses 100% of
 *               the existing deck/matches markup + asset enqueue.
 *
 * Mechanics: the Organization tab markup belongs to the projects plugin
 * (group-members-screen.php) — this file deliberately does NOT edit it.
 * Everything renders on wp_footer at priority 9 (before
 * wp_print_footer_scripts at 20, so render_panel()'s late enqueues still
 * print), then a tiny inline script relocates the button into the top of the
 * Organization surface (.psoo-gm, right above its own "Business Group /
 * Organization / Sequences / App Feature Access" inner tab nav). If that DOM
 * is absent the button stays [hidden] and nothing shows — zero-risk no-op.
 *
 * Access gate: same predicate as every collab surface — super-admin OR group
 * admin OR group mod (gs_collab_profile_can_act_for_group(), kept alive in
 * member-tab-collab.php; inline fallback below mirrors it verbatim). The
 * gs/v1 REST layer independently enforces the same rule on every call.
 *
 * Youzify note: the launcher carries its visuals on the <button> element and
 * its ::before/::after pseudo-elements rather than relying on any ambient
 * div/span/a styling from the host tab, so it renders correctly regardless
 * of which tab's reset rules (if any) apply around it.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'gend_society_collab_payments_match_launcher', 9 );
function gend_society_collab_payments_match_launcher() {
	// Retired from the Organization page by request -- same pattern as this feature's earlier homes
	// (Phase 82's group nav tab, Phase 102's profile tab): disable rendering here rather than delete the
	// code, in case GenD Match needs relocating again later. The code below (render_panel() call, modal
	// markup, assets) is left intact; this is the only line stopping it from showing.
	return;

	if ( ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) {
		return;
	}
	if ( ! function_exists( 'bp_is_current_action' ) || ! bp_is_current_action( 'members-hub' ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}

	$group_id = function_exists( 'bp_get_current_group_id' ) ? (int) bp_get_current_group_id() : 0;
	if ( $group_id <= 0 ) {
		return;
	}

	$uid = (int) get_current_user_id();
	if ( function_exists( 'gend_society_collab_profile_can_act_for_group' ) ) {
		$can_act = gend_society_collab_profile_can_act_for_group( $uid, $group_id );
	} else {
		// Verbatim mirror of the collab access predicate (super-admin OR
		// group admin OR group mod) for a partial/out-of-order deploy.
		$can_act = ( function_exists( 'is_super_admin' ) && is_super_admin( $uid ) ) // site-admin check, not a hub signal (104 audit)
			|| ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $uid, $group_id ) )
			|| ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( $uid, $group_id ) );
	}
	if ( ! $can_act ) {
		return;
	}

	if ( ! class_exists( 'Gend_Society_Group_Tab_Collab' ) || ! method_exists( 'Gend_Society_Group_Tab_Collab', 'render_panel' ) ) {
		return;
	}
	?>
	<!-- ── GenD Match launcher (relocated into the Organization surface by JS) ── -->
	<div id="gs-match-launch" class="gs-match-launch" hidden>
		<button type="button" id="gs-match-launch-btn" class="gs-match-btn"
			aria-haspopup="dialog" aria-controls="gs-match-modal">
			<span class="gs-match-btn__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3.34a10 10 0 1 1-14.83 8.17"/><path d="M16 8l-4.5 4.5L9 10"/><circle cx="19" cy="5" r="2.6"/></svg>
			</span>
			<span class="gs-match-btn__label"><?php esc_html_e( 'Get Matched With Business Partners', 'gend-society' ); ?></span>
			<span class="gs-match-btn__arrow" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
			</span>
		</button>
	</div>

	<!-- ── GenD Match modal (stays at body level; full deck/matches panel) ── -->
	<div id="gs-match-modal" class="gs-match-modal" role="dialog" aria-modal="true"
		aria-label="<?php esc_attr_e( 'GenD Match — get matched with business partners', 'gend-society' ); ?>" hidden>
		<div class="gs-match-modal__backdrop" data-gs-match-close></div>
		<div class="gs-match-modal__panel" role="document">
			<div class="gs-match-modal__head">
				<div class="gs-match-modal__titles">
					<span class="gs-match-modal__eyebrow"><?php esc_html_e( 'GenD Match', 'gend-society' ); ?></span>
					<h2 class="gs-match-modal__title"><?php esc_html_e( 'Get Matched With Business Partners', 'gend-society' ); ?></h2>
				</div>
				<button type="button" class="gs-match-modal__close" data-gs-match-close
					aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
				</button>
			</div>
			<div class="gs-match-modal__body">
				<?php Gend_Society_Group_Tab_Collab::render_panel( $group_id ); ?>
			</div>
		</div>
	</div>

	<style>
	/* ══ Launcher button — glass couture ═════════════════════════════════ */
	@property --gsm-angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }

	.gs-match-launch {
		display: flex;
		justify-content: center;
		margin: 0 0 34px;
		position: relative;
		z-index: 6;
		perspective: 900px;
	}
	.gs-match-btn {
		--gsm-gold: #ffcc00;
		--gsm-emerald: #00ff88;
		--gsm-magenta: #b608c9;
		position: relative;
		display: inline-flex;
		align-items: center;
		gap: 14px;
		padding: 18px 34px;
		border: 0;
		border-radius: 999px;
		cursor: pointer;
		background: linear-gradient(135deg, rgba(255,255,255,0.10) 0%, rgba(255,255,255,0.03) 55%, rgba(255,204,0,0.06) 100%);
		backdrop-filter: blur(22px) saturate(170%);
		-webkit-backdrop-filter: blur(22px) saturate(170%);
		color: #fff;
		font-family: 'Inter', system-ui, sans-serif;
		font-size: 0.92rem;
		font-weight: 800;
		letter-spacing: 2.4px;
		text-transform: uppercase;
		transform-style: preserve-3d;
		transition: transform .45s cubic-bezier(.16,1,.3,1), box-shadow .45s cubic-bezier(.16,1,.3,1);
		/* Bevelled 3D body: top light-catch, bottom under-shadow, ambient drop */
		box-shadow:
			inset 0 1px 0 rgba(255,255,255,0.28),
			inset 0 -2px 6px rgba(0,0,0,0.45),
			0 14px 38px -12px rgba(0,0,0,0.75),
			0 0 34px -10px rgba(255,204,0,0.35);
		/* Couture entrance: drop out of the light, focus-pull into place */
		opacity: 0;
		animation: gsmEnter 1.05s cubic-bezier(.16,1,.3,1) .35s forwards;
	}
	/* Rotating 3D jewel border — conic ring masked to a 2px band, slow spin */
	.gs-match-btn::before {
		content: "";
		position: absolute;
		inset: -2px;
		border-radius: inherit;
		padding: 2px;
		background: conic-gradient(from var(--gsm-angle),
			var(--gsm-gold) 0deg, rgba(255,255,255,0.85) 70deg,
			var(--gsm-emerald) 140deg, rgba(255,255,255,0.15) 210deg,
			var(--gsm-magenta) 285deg, var(--gsm-gold) 360deg);
		-webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
		-webkit-mask-composite: xor;
		mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
		mask-composite: exclude;
		animation: gsmSpin 7s linear infinite;
		pointer-events: none;
	}
	/* Runway glint — a light bar sweeps the glass every few seconds */
	.gs-match-btn::after {
		content: "";
		position: absolute;
		inset: 0;
		border-radius: inherit;
		overflow: hidden;
		background: linear-gradient(115deg,
			transparent 32%, rgba(255,255,255,0.34) 47%,
			rgba(255,255,255,0.10) 52%, transparent 66%);
		background-size: 280% 100%;
		background-repeat: no-repeat;
		animation: gsmGlint 5.2s ease-in-out 1.6s infinite;
		pointer-events: none;
		mix-blend-mode: screen;
	}
	.gs-match-btn:hover {
		transform: translateY(-3px) rotateX(7deg) scale(1.015);
		box-shadow:
			inset 0 1px 0 rgba(255,255,255,0.35),
			inset 0 -2px 6px rgba(0,0,0,0.45),
			0 24px 54px -14px rgba(0,0,0,0.8),
			0 0 52px -8px rgba(255,204,0,0.5),
			0 0 34px -12px rgba(0,255,136,0.35);
	}
	.gs-match-btn:active {
		transform: translateY(0) rotateX(2deg) scale(0.985);
		transition-duration: .12s;
	}
	.gs-match-btn:focus-visible {
		outline: 2px solid var(--gsm-gold);
		outline-offset: 4px;
	}
	.gs-match-btn__icon,
	.gs-match-btn__arrow {
		display: inline-flex;
		width: 22px;
		height: 22px;
		flex: 0 0 auto;
		color: var(--gsm-gold);
		filter: drop-shadow(0 0 8px rgba(255,204,0,0.55));
	}
	.gs-match-btn__icon svg,
	.gs-match-btn__arrow svg { width: 100%; height: 100%; }
	.gs-match-btn__arrow {
		width: 17px;
		height: 17px;
		color: rgba(255,255,255,0.75);
		filter: none;
		transition: transform .35s cubic-bezier(.16,1,.3,1), color .35s ease;
	}
	.gs-match-btn:hover .gs-match-btn__arrow {
		transform: translateX(5px);
		color: var(--gsm-gold);
	}
	.gs-match-btn__label {
		background: linear-gradient(92deg, #fff 0%, #fff 55%, rgba(255,204,0,0.9) 100%);
		-webkit-background-clip: text;
		background-clip: text;
		-webkit-text-fill-color: transparent;
		white-space: nowrap;
	}

	@keyframes gsmSpin  { to { --gsm-angle: 360deg; } }
	@keyframes gsmGlint {
		0%       { background-position: 180% 0; }
		38%,100% { background-position: -120% 0; }
	}
	@keyframes gsmEnter {
		0%   { opacity: 0; transform: translateY(-22px) scale(0.94); filter: blur(12px); }
		60%  { opacity: 1; }
		100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
	}

	@media (max-width: 640px) {
		.gs-match-btn {
			padding: 15px 20px;
			font-size: 0.72rem;
			letter-spacing: 1.6px;
			gap: 10px;
		}
		.gs-match-btn__label { white-space: normal; text-align: left; line-height: 1.35; }
	}

	/* ══ Modal ═══════════════════════════════════════════════════════════ */
	.gs-match-modal {
		position: fixed;
		inset: 0;
		z-index: 999990;
		align-items: center;
		justify-content: center;
		padding: 28px 16px;
	}
	/* `display: flex` MUST be gated behind :not([hidden]) rather than set
	   unconditionally on .gs-match-modal: the class selector and the
	   browser's built-in `[hidden] { display: none }` UA rule have equal
	   specificity, and this authored stylesheet loads after the UA sheet,
	   so an unconditional `display: flex` here silently wins the cascade
	   and defeats the `hidden` attribute — leaving this position:fixed,
	   inset:0, z-index:999990 layer sitting over the ENTIRE viewport at
	   all times (invisible, since its backdrop/panel opacity is 0 until
	   .is-open), swallowing every click on the page underneath it,
	   including the Organization tabs. Scoping display to :not([hidden])
	   restores the native hidden-by-default behavior; JS toggling
	   modal.hidden = false is what reveals it. */
	.gs-match-modal:not([hidden]) {
		display: flex;
	}
	.gs-match-modal__backdrop {
		position: absolute;
		inset: 0;
		background: rgba(4, 6, 11, 0.66);
		backdrop-filter: blur(10px) saturate(120%);
		-webkit-backdrop-filter: blur(10px) saturate(120%);
		opacity: 0;
		transition: opacity .3s ease;
	}
	.gs-match-modal__panel {
		position: relative;
		width: min(1080px, 100%);
		max-height: min(92vh, 100%);
		display: flex;
		flex-direction: column;
		border-radius: 26px;
		background: linear-gradient(160deg, rgba(20, 26, 40, 0.96) 0%, rgba(11, 14, 20, 0.97) 60%);
		border: 1px solid rgba(255, 255, 255, 0.12);
		box-shadow:
			inset 0 1px 0 rgba(255,255,255,0.10),
			0 40px 120px -30px rgba(0,0,0,0.9),
			0 0 90px -30px rgba(255,204,0,0.22);
		opacity: 0;
		transform: translateY(26px) scale(0.965);
		filter: blur(8px);
		transition: opacity .38s cubic-bezier(.16,1,.3,1), transform .38s cubic-bezier(.16,1,.3,1), filter .38s cubic-bezier(.16,1,.3,1);
	}
	.gs-match-modal.is-open .gs-match-modal__backdrop { opacity: 1; }
	.gs-match-modal.is-open .gs-match-modal__panel {
		opacity: 1;
		transform: translateY(0) scale(1);
		filter: blur(0);
	}
	.gs-match-modal__head {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 16px;
		padding: 24px 28px 18px;
		border-bottom: 1px solid rgba(255,255,255,0.08);
	}
	.gs-match-modal__eyebrow {
		display: inline-block;
		padding: 4px 12px;
		border-radius: 999px;
		background: rgba(255, 204, 0, 0.10);
		border: 1px solid rgba(255, 204, 0, 0.35);
		color: #ffcc00;
		font-size: 0.64rem;
		font-weight: 900;
		letter-spacing: 2px;
		text-transform: uppercase;
	}
	.gs-match-modal__title {
		margin: 10px 0 0;
		color: #fff;
		font-size: 1.35rem;
		font-weight: 900;
		letter-spacing: -0.3px;
		line-height: 1.15;
	}
	.gs-match-modal__close {
		flex: 0 0 auto;
		width: 40px;
		height: 40px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		border-radius: 12px;
		border: 1px solid rgba(255,255,255,0.14);
		background: rgba(255,255,255,0.05);
		color: rgba(255,255,255,0.8);
		cursor: pointer;
		transition: background .2s ease, color .2s ease, transform .2s ease;
	}
	.gs-match-modal__close svg { width: 17px; height: 17px; }
	.gs-match-modal__close:hover {
		background: rgba(255,255,255,0.12);
		color: #fff;
		transform: rotate(90deg);
	}
	.gs-match-modal__body {
		padding: 22px 28px 30px;
		overflow-y: auto;
		overscroll-behavior: contain;
	}

	@media (max-width: 640px) {
		.gs-match-modal { padding: 0; }
		.gs-match-modal__panel {
			width: 100%;
			height: 100%;
			max-height: none;
			border-radius: 0;
		}
		.gs-match-modal__head { padding: 18px 16px 14px; }
		.gs-match-modal__body { padding: 16px 14px 26px; }
	}

	@media (prefers-reduced-motion: reduce) {
		.gs-match-btn { animation: none; opacity: 1; }
		.gs-match-btn::before, .gs-match-btn::after { animation: none; }
		.gs-match-modal__backdrop, .gs-match-modal__panel { transition: none; }
	}
	</style>

	<script>
	( function () {
		'use strict';
		var slot  = document.getElementById( 'gs-match-launch' );
		var btn   = document.getElementById( 'gs-match-launch-btn' );
		var modal = document.getElementById( 'gs-match-modal' );
		if ( ! slot || ! btn || ! modal ) {
			return;
		}

		// Relocate the launcher to the very top of the Organization surface
		// (above its own inner tab nav). If that DOM is not on this page
		// render, leave it hidden — the feature simply does not appear (no
		// orphan button at page bottom).
		var host = document.querySelector( '.psoo-gm' );
		if ( ! host ) {
			return;
		}
		host.insertBefore( slot, host.firstChild );
		slot.hidden = false; // reveal at final position so the entrance plays in place

		var lastFocus = null;
		function openModal() {
			lastFocus = document.activeElement;
			modal.hidden = false;
			// Two frames so the transition runs from the pre-open state.
			requestAnimationFrame( function () {
				requestAnimationFrame( function () {
					modal.classList.add( 'is-open' );
				} );
			} );
			document.body.style.overflow = 'hidden';
			var closeBtn = modal.querySelector( '.gs-match-modal__close' );
			if ( closeBtn ) { closeBtn.focus(); }
		}
		function closeModal() {
			modal.classList.remove( 'is-open' );
			document.body.style.overflow = '';
			window.setTimeout( function () { modal.hidden = true; }, 320 );
			if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
		}

		btn.addEventListener( 'click', openModal );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-gs-match-close]' ) ) { closeModal(); }
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! modal.hidden ) { closeModal(); }
		} );
	} )();
	</script>
	<?php
}
