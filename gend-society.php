<?php
/**
 * Plugin Name: GenD Society
 * Plugin URI:  https://gend.me
 * Description: Futuristic glassmorphic WordPress admin experience with custom menus, redesigned backend, and dynamic frontend sidebar.
 * Version:     1.0.7
 * Author:      By GenD
 * Author URI:  https://gend.me
 * Network:     true
 * Text Domain: gend-society
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_VERSION', '1.1.4');
define('GS_DIR', plugin_dir_path(__FILE__));
define('GS_URL', plugin_dir_url(__FILE__));

// GenD Match v12.0 Phase 86 — Tier B counsel gate (default false, DOM-absent +
// route-404 when off). Market auto-creation + subsidy funding are gated on this;
// the engine METHODS and the outcome recorder run flag-independent. Guarded so an
// operator can pre-define it truthy in wp-config without being clobbered, and so a
// re-define never fires. Defined BEFORE the collab requires so every collab class
// sees it.
if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) ) {
    define( 'GS_COLLAB_MARKET_PUBLIC', false );
}

// Core includes
require_once GS_DIR . 'inc/admin-style.php';
require_once GS_DIR . 'inc/admin-menu.php';
require_once GS_DIR . 'inc/frontend-bar.php';
require_once GS_DIR . 'inc/live-view.php';

// GitHub Updater
require_once GS_DIR . 'inc/class-gend-github-updater.php';
new GenD_GitHub_Updater(__FILE__, 'gend-me/Society-Core');

// Dashboard overrides (Standalone)
require_once GS_DIR . 'inc/dashboard-overview.php';
require_once GS_DIR . 'inc/dashboard-app-management.php';
require_once GS_DIR . 'inc/dashboard-remote-membership.php';
// Container → hub plugin-state reporter (no-op on hub since it has no
// install pairing). Powers the "Active on linked web app" sub-status
// on the BP-group Feature Suite tab via gdc_get_container_active_plugins.
require_once GS_DIR . 'inc/feature-state-reporter.php';
require_once GS_DIR . 'inc/dashboard-hosting.php';
// Phase 72-02 (v10.0): adds dashboard-hosting-domains.php for Connect-a-Domain wizard.
// file_exists guard mirrors Phase 71-02 partial-deploy defense (hub PVC .no-plugin-sync quirk).
if ( file_exists( GS_DIR . 'inc/dashboard-hosting-domains.php' ) ) {
    require_once GS_DIR . 'inc/dashboard-hosting-domains.php';
}
// Phase 73-02 (v10.0) — DNS records-editor modal renderer (sibling to dashboard-hosting-domains.php).
// Same file_exists guard for the hub PVC .no-plugin-sync partial-deploy defense (Phase 71-02 pattern).
if ( file_exists( GS_DIR . 'inc/dashboard-hosting-records-modal.php' ) ) {
    require_once GS_DIR . 'inc/dashboard-hosting-records-modal.php';
}
// Media-storage plan panel for the blog-manager Media tab (Phase 33). Loads
// AFTER dashboard-hosting.php + dashboard-remote-membership.php so its helper
// functions + the gs_membership_refresh AJAX action are already defined.
require_once GS_DIR . 'inc/media-storage-panel.php';
require_once GS_DIR . 'inc/feature-cards.php';
require_once GS_DIR . 'inc/pages/dashboard.php';
// Connected web-app group menu → embedded (inline, not iframe) tab pages.
// Reuses the container-local renderers above (feature cards / hosting /
// compute-gas / feature-access) so the header's group menu opens each
// section as a real wp-admin page. Must load after those renderers.
require_once GS_DIR . 'inc/group-embed.php';
require_once GS_DIR . 'inc/web-shell.php';
require_once GS_DIR . 'inc/web-shell-gcloud.php';
require_once GS_DIR . 'inc/web-shell-sites.php';
require_once GS_DIR . 'inc/web-shell-chat.php';

// BP group tabs (Feature Suite / User Access / Hosting / Compute Gas)
// have to wait for BuddyPress to define BP_Group_Extension. Loaded via
// `bp_include` mirrors the projects-plugin pattern. gend-society sits
// alphabetically before social-network (where BP ships), so a plain
// top-level require would no-op the extension classes via the
// class_exists guard inside the file.
add_action( 'bp_include', function () {
    if ( ! class_exists( 'BP_Group_Extension' ) ) return;
    require_once GS_DIR . 'inc/group-feature-suite.php';
    require_once GS_DIR . 'inc/group-app-tabs.php';
    // Davinci Architect AI — AI-spend overview tab. Loaded AFTER
    // group-app-tabs.php so its shared helpers (gs_group_tabs_user_has_access,
    // gs_compute_gas_resolve_state) are defined. file_exists-guarded per the
    // hub PVC .no-plugin-sync quirk (kubectl cp the file before this edit).
    if ( file_exists( GS_DIR . 'inc/group-davinci-ai-tab.php' ) ) {
        require_once GS_DIR . 'inc/group-davinci-ai-tab.php';
    }
    // GenD Match (v12.0 Phase 82-03) — swipe-deck group tab. Loaded AFTER
    // group-app-tabs.php so its gs_group_tabs_user_has_access gate is defined
    // (same reasoning as the group-davinci-ai-tab require). file_exists-guarded
    // per the hub PVC .no-plugin-sync quirk (kubectl cp the file before this
    // edit, or every request fatals + WP auto-deactivates the plugin).
    if ( file_exists( GS_DIR . 'inc/collab/group-tab-collab.php' ) ) {
        require_once GS_DIR . 'inc/collab/group-tab-collab.php';
    }
} );

// Member profile pages (per-user CPT + BuddyPress embed)
require_once GS_DIR . 'inc/member-profile-pages.php';

// Member profile header (terminal-style header + nav bar)
require_once GS_DIR . 'inc/member-profile-header.php';

// Member calendar (CALENDAR primary-nav tab + glass grid). file_exists-guarded:
// the hub PVC .no-plugin-sync quirk means the include file must be kubectl cp'd
// to the PVC BEFORE this entrypoint edit, or every request fatals. See 26-03 runbook.
if ( file_exists( GS_DIR . 'inc/member-calendar.php' ) ) {
	require_once GS_DIR . 'inc/member-calendar.php';
}

// CALENDAR REST events aggregation (Phase 27). file_exists-guarded — hub PVC
// .no-plugin-sync means the include file must be kubectl cp'd to the PVC
// BEFORE this entrypoint edit, or every request fatals. See 27-02 runbook.
if ( file_exists( GS_DIR . 'inc/calendar-events-rest.php' ) ) {
	require_once GS_DIR . 'inc/calendar-events-rest.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Calendar_Events_REST', 'register_routes' ) );
}

// Member calendar — Availability + Meetings schema installer (Phase 28-01).
// Installs BOTH wp_gs_member_availability + wp_gs_member_meetings via ONE
// version-gated dbDelta routine, multisite-aware (existing blogs via activation
// site-loop + future blogs via wp_initialize_site + self-heal via init pri 5).
// file_exists-guarded — the hub PVC .no-plugin-sync quirk means the include
// file MUST be kubectl cp'd to the PVC BEFORE this entrypoint edit, or every
// request fatals. See 28-03 runbook (inherits from 27-02 runbook).
if ( file_exists( GS_DIR . 'inc/class-availability-schema.php' ) ) {
	require_once GS_DIR . 'inc/class-availability-schema.php';
	Gend_GS_Availability_Schema::init();
}

// GenD Match (v12.0 Phase 82-01) — collab swipe-ledger schema + rule-based taxonomy.
// gs_collab_swipes (never-re-show ledger, SWIPE-04 UNIQUE invariant) + gs_collab_matches
// (schema only, Phase 83 populates) install via ONE version-gated dbDelta routine.
// The taxonomy is a pure static class (no hooks) but REST + deck (Plan 82-02) require it,
// so it is required here too so it is always loaded.
// file_exists-guarded per the hub PVC .no-plugin-sync quirk: the include files MUST be
// kubectl cp'd to the PVC BEFORE this entrypoint edit, or every request fatals + WP
// auto-deactivates the plugin. Classes-before-entrypoint (82 deploy note).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-taxonomy.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-taxonomy.php';
}
if ( file_exists( GS_DIR . 'inc/collab/class-collab-schema.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-schema.php';
	Gend_GS_Collab_Schema::init();
}

// GenD Match (v12.0 Phase 82-02) — collab deck engine + gs/v1 REST routes.
// The deck class has no hooks but the REST class calls it, so require both; the
// REST class is bound by its OWN single rest_api_init add_action (per-class binding
// pattern — do NOT fold these routes into the calendar REST class). Routes:
//   GET  gs/v1/collab/deck  · POST gs/v1/collab/swipe · GET/POST gs/v1/collab/tags.
// file_exists-guarded (hub PVC .no-plugin-sync quirk; classes-before-entrypoint).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-deck.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-deck.php';
}
if ( file_exists( GS_DIR . 'inc/collab/class-collab-rest.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-rest.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Collab_REST', 'register_routes' ) );
}

// GenD Match (v12.0 Phase 83) — mutual-match engine. Populates gs_collab_matches
// on a reciprocal right swipe (called thinly from Gend_GS_Collab_REST::route_swipe).
// Seeds the BP intro thread + fires in-app + a guarded/debounced batched member-inbox
// email (em_inbox_send_as is called guarded — NO email-manager edit; gend-society-only).
// No hooks — a pure static logic class; require is enough. file_exists-guarded
// (hub PVC .no-plugin-sync quirk; classes-before-entrypoint deploy discipline).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-match.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-match.php';
}

// GenD Match (v12.0 Phase 84) — collaboration-contract escalation engine. Pure
// static class (propose/accept/decline) called by Gend_GS_Collab_REST's contract
// routes; must load BEFORE rest_api_init fires so the routes' callbacks resolve.
// Turns a Phase-83 match into a real Gend_CP_Task_Contract with one-sided DGEN
// escrow by replaying the projects/C&P public API (no edit to those plugins — every
// cross-plugin call is class_exists-guarded). No hooks — require is enough.
// file_exists-guarded (hub PVC .no-plugin-sync quirk; classes-before-entrypoint).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-contract.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-contract.php';
}

// GenD Match (v12.0 Phase 85-02) — DARK terminal-outcome recorder + 15-min cron
// backstop (RESOLVE-03). Subscribes to gend_cp_task_contract_paid (success) and the
// Plan-01 gend_cp_task_contract_terminated (fail|void) actions, records every
// CONTRACTED match's terminal contract state exactly once to gs_collab_contract_outcomes
// (audit + chain-anchor), and a gs_collab_resolve_sweep cron backfills any a missed
// hook left unrecorded (incl. deadline expiry). Runs DARK (records regardless of the
// Phase-86+ market flag), hub-only, moves NO money. init() wires the hooks + cron.
// Loaded AFTER class-collab-contract.php so the match/contract helpers exist first.
// file_exists-guarded (hub PVC .no-plugin-sync quirk; classes-before-entrypoint).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-resolver.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-resolver.php';
	Gend_GS_Collab_Resolver::init();
}

// GenD Match (v12.0 Phase 86) — LMSR collaboration prediction-market ENGINE.
//   - class-collab-bc-math.php (86-01): Gend_GS_BC_Math — the float-free bcmath
//     exp/ln/price/cost money math every quote/cost/subsidy routes through.
//   - class-collab-market.php (86-03): Gend_GS_Collab_Market — the indivisible engine
//     (create_market/fund_subsidy/quote/trade/lock + on_contracted/on_outcome_recorded
//     lifecycle subscribers). Loaded AFTER the resolver so match/contract/resolver
//     helpers exist first.
//   - class-collab-market-rest.php (86-04 read + 87-02 write): Gend_GS_Collab_Market_REST —
//     the DARK, flag+hub-gated market REST surface. Two GET read routes (86-04) PLUS the
//     87-02 POST /market/{id}/bet WRITE route (buy/sell driving place_bet(); STAKE-04 bettor
//     from get_current_user_id() only — no recipient param; STAKE-05 rake disclosed, not
//     skimmed). Its register_routes() self-gates on is_main_node() AND GS_COLLAB_MARKET_PUBLIC
//     BEFORE any register_rest_route call, so ALL routes (read AND bet) are ABSENT (404) when
//     off. The write route lives in the SAME class — no extra require/add_action needed.
// Wiring (86-04): the two lifecycle do_actions from contract.php/resolver.php drive the
// engine's on_* subscribers — on_contracted is GATED on GS_COLLAB_MARKET_PUBLIC (auto-create
// only when public), on_outcome_recorded is flag-INDEPENDENT (lock a dark market harmlessly).
// GS_COLLAB_MARKET_PUBLIC is defined default-false at the top of this file (86-02).
// file_exists-guarded (hub PVC .no-plugin-sync quirk; classes-before-entrypoint deploy).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-bc-math.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-bc-math.php';
}
if ( file_exists( GS_DIR . 'inc/collab/class-collab-market.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-market.php';
	// MARKET-01: auto-create on the contracted flip (gated inside on_contracted).
	add_action( 'gend_gs_collab_contracted', array( 'Gend_GS_Collab_Market', 'on_contracted' ), 10, 2 );
	// MARKET-05: lock on any recorded terminal outcome (flag-independent CAS).
	add_action( 'gend_gs_collab_outcome_recorded', array( 'Gend_GS_Collab_Market', 'on_outcome_recorded' ), 10, 2 );
	// Phase 88 (RESOLVE-01/02/04): deterministic resolve on the SAME recorded-outcome hook,
	// at priority 20 so the pri-10 lock CAS runs FIRST (lock-then-resolve — 88-RESEARCH Open-Q2):
	// by the time settle()->resolve() runs the market is already `locked`. settle() is hub-only
	// but FLAG-INDEPENDENT — a bettor's DGEN must settle even with GS_COLLAB_MARKET_PUBLIC off
	// (money-safety); the flag gates only the member SURFACE, never existing-stake settlement.
	// Lives inside this SAME require block that guards the market class, so a partial deploy
	// without class-collab-market.php cannot fatal (class_exists implied by the file_exists gate).
	add_action( 'gend_gs_collab_outcome_recorded', array( 'Gend_GS_Collab_Market', 'settle' ), 20, 2 );
}
if ( file_exists( GS_DIR . 'inc/collab/class-collab-market-rest.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-market-rest.php';
	// The class self-gates on is_main_node() AND GS_COLLAB_MARKET_PUBLIC — routes are
	// NEVER registered (404 route-absent, not 403) when the counsel flag is off.
	add_action( 'rest_api_init', array( 'Gend_GS_Collab_Market_REST', 'register_routes' ) );
}

// GenD Match (v12.0 Phase 87-03, STAKE-02) — the member READ surface.
//   - class-collab-portfolio-rest.php: Gend_GS_Collab_Portfolio_REST — the DARK+hub-gated
//     PRIVATE portfolio route GET gs/v1/portfolio. GATE-02: reads ONLY get_current_user_id()
//     rows (no user_id arg, no public P/L leaderboard). register_routes() self-gates on
//     is_main_node() AND GS_COLLAB_MARKET_PUBLIC -> route ABSENT (404) when off.
//   - member-markets.php: the dark+hub-gated "Collaborations" BuddyPress member-nav tab
//     (open markets + live odds + inline buy/sell POSTing the 87-02 bet route + inline
//     position + the private portfolio + Chart.js P/L + the rake disclosure). It
//     self-registers its own bp_setup_nav add_action at include time (the whole
//     registration is dark-guarded inside), so it needs the require ONLY — no extra add_action.
// file_exists-guarded (hub PVC .no-plugin-sync quirk; classes-before-entrypoint deploy;
// memory: project_hub_plugin_sync_gotcha).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-portfolio-rest.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-portfolio-rest.php';
	// Self-gates on is_main_node() AND GS_COLLAB_MARKET_PUBLIC -> route absent (404) when off.
	add_action( 'rest_api_init', array( 'Gend_GS_Collab_Portfolio_REST', 'register_routes' ) );
}
if ( file_exists( GS_DIR . 'inc/collab/member-markets.php' ) ) {
	require_once GS_DIR . 'inc/collab/member-markets.php'; // self-registers its dark-guarded bp_setup_nav tab.
}

// GenD Match (v12.0 Phase 89-02, FED-01) — cross-app federation of the swipe/match pool.
//   - class-collab-sync-crypto.php (89-01): Gend_GS_Collab_Sync_Crypto — the standalone
//     ed25519 sign/verify primitive (local re-impl of the gend-pm-sync rail; NO c-and-p edit).
//     Pure helper, no hooks — require only.
//   - class-collab-sync.php (89-02): Gend_GS_Collab_Sync — ONE self-gating class (is_hub()).
//     HUB registers the receive route under the existing gend-pm-sync/v1 namespace (cross-plugin
//     registration proven at contracts-and-payments class-pm-sync-push.php:66-83); auth is the
//     ed25519 sig verified in-callback. CONTAINER pushes each local swipe fire-and-forget
//     (blocking=false, timeout 0.5) AFTER the local record_swipe, enqueues to gs_collab_outbox
//     on failure, and drains it on the existing gs_fifteen_min cadence.
// P2 (load-bearing): register_receive self-gates on is_hub(); on_local_swipe no-ops on the hub;
// init_container no-ops on the hub — the SAME entrypoint runs correctly on BOTH sides. A hub
// hiccup/cold-start/bad-sig can NEVER block or error the local swipe (the push is a downstream
// best-effort mirror, the deck falls back to local).
// file_exists-guarded (hub PVC .no-plugin-sync quirk; classes-before-entrypoint deploy;
// memory: project_hub_plugin_sync_gotcha).
if ( file_exists( GS_DIR . 'inc/collab/class-collab-sync-crypto.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-sync-crypto.php';
}
if ( file_exists( GS_DIR . 'inc/collab/class-collab-sync.php' ) ) {
	require_once GS_DIR . 'inc/collab/class-collab-sync.php';
	// HUB: receive route under the existing gend-pm-sync/v1 namespace (self-gates on is_hub()).
	add_action( 'rest_api_init', array( 'Gend_GS_Collab_Sync', 'register_receive' ) );
	// CONTAINER: push after every local swipe — fires AFTER the unconditional local record_swipe
	// in route_swipe (P2). No-ops on the hub. Priority 10, 4 args.
	add_action( 'gend_gs_collab_swiped', array( 'Gend_GS_Collab_Sync', 'on_local_swipe' ), 10, 4 );
	// CONTAINER: schedule the outbox drain on the EXISTING gs_fifteen_min interval (NO new
	// interval). init_container() self-gates on ! is_hub() — harmless on the hub.
	if ( class_exists( 'Gend_GS_Collab_Resolver' ) && method_exists( 'Gend_GS_Collab_Resolver', 'init_container' ) ) {
		Gend_GS_Collab_Resolver::init_container();
	}
}

// Member calendar — Availability REST handler (Phase 28-02).
// Routes: GET/PUT /wp-json/gs/v1/calendar/availability — AVAIL-01/02/03.
// Reads/writes wp_gs_member_availability (installed by Plan 28-01 schema).
// file_exists-guarded — Pitfall 1 (.no-plugin-sync hub PVC quirk).
if ( file_exists( GS_DIR . 'inc/class-availability-rest.php' ) ) {
	require_once GS_DIR . 'inc/class-availability-rest.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Availability_REST', 'register_routes' ) );
}

// Phase 29 Plan 01 — public booking REST (Gend_GS_Booking_Public_REST, file_exists guard per Pitfall 1).
// Registers gs/v1/calendar/public/{share_token}/slots + /book + meeting cancel/reschedule
// routes for the public booking flow (bookee may be logged-out). Atomic FOR UPDATE
// double-book prevention + per-IP/per-token rate limit + honeypot hardening live here.
if ( file_exists( GS_DIR . 'inc/class-booking-public-rest.php' ) ) {
	require_once GS_DIR . 'inc/class-booking-public-rest.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Booking_Public_REST', 'register_routes' ) );
}

// Phase 29 Plan 02 — authed meetings REST (Gend_GS_Booking_Meetings_REST, file_exists guard per Pitfall 1).
// Registers 4 authed routes on gs/v1: GET /calendar/meetings, POST /calendar/meetings
// (Schedule Meeting modal), POST /calendar/meetings/{id}/cancel (host-cancel),
// POST /calendar/share-token/rotate. URL allowlist enforcement for external video
// providers (Pitfall 16) lives here. Loaded AFTER 29-01 so the Plan 29-02
// availability PUT can lazy-call Gend_GS_Booking_Meetings_REST::sanitize_meeting_meta_with_allowlist.
if ( file_exists( GS_DIR . 'inc/class-booking-meetings-rest.php' ) ) {
	require_once GS_DIR . 'inc/class-booking-meetings-rest.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Booking_Meetings_REST', 'register_routes' ) );
}

// Phase 30 Plan 30-02 — Native gend video JWT minter + time-gated REST.
// Cross-phase invariants: hand-rolled HS256 (NO third-party JWT lib), secret via
// getenv()/wp-config.php define shim (NEVER from the options table), server-clock
// time gate via time() vs strtotime($utc.' UTC'), register_rest_route ONLY on
// rest_api_init. The JWT class is purely static helpers (no hook needed); only
// the REST class binds to rest_api_init.
// file_exists-guarded — Pitfall 1 (.no-plugin-sync hub PVC quirk): the 2 NEW
// class files MUST be kubectl cp'd to the PVC BEFORE this entrypoint edit, or
// every request fatals. Plan 30-04 runbook documents the deploy order.
if ( file_exists( GS_DIR . 'inc/class-jitsi-jwt.php' ) ) {
	require_once GS_DIR . 'inc/class-jitsi-jwt.php';
}
if ( file_exists( GS_DIR . 'inc/class-jitsi-rest.php' ) ) {
	require_once GS_DIR . 'inc/class-jitsi-rest.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Jitsi_REST', 'register_routes' ) );
}

// Phase 30 Plan 30-03 — Native gend video embed assets (jitsi-embed.{js,css}),
// broader BP-profile enqueue surface (VID-04 + VID-05). member-calendar.php
// already enqueues gs-jitsi-embed on the calendar tab; this block ALSO enqueues
// it across the member's own profile so the wallet meeting feed + the Phase 29
// booking confirmation page get the Join-button embed controller too. The handle
// is identical so wp_enqueue_script/style + wp_localize_script dedupe — calling
// from both sites is safe (WP keys by handle; last localize wins, both write the
// same data). file_exists-guarded — Pitfall 1 (.no-plugin-sync hub PVC quirk):
// the 2 NEW asset files MUST be kubectl cp'd to the PVC BEFORE this entrypoint
// edit. Priority 20 so it runs after the theme/Youzify base enqueues.
if ( file_exists( GS_DIR . 'assets/jitsi-embed.js' ) && file_exists( GS_DIR . 'assets/jitsi-embed.css' ) ) {
	add_action( 'wp_enqueue_scripts', static function () {
		if ( ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
			return;
		}
		$gs_jitsi_js_path  = GS_DIR . 'assets/jitsi-embed.js';
		$gs_jitsi_css_path = GS_DIR . 'assets/jitsi-embed.css';
		$gs_jitsi_js_ver   = GS_VERSION . '.' . filemtime( $gs_jitsi_js_path );
		$gs_jitsi_css_ver  = GS_VERSION . '.' . filemtime( $gs_jitsi_css_path );
		wp_enqueue_script(
			'gs-jitsi-embed',
			GS_URL . 'assets/jitsi-embed.js',
			array(),
			$gs_jitsi_js_ver,
			true
		);
		wp_enqueue_style(
			'gs-jitsi-embed',
			GS_URL . 'assets/jitsi-embed.css',
			array(),
			$gs_jitsi_css_ver
		);
		wp_localize_script( 'gs-jitsi-embed', 'gsJitsiData', array(
			'restUrl' => esc_url_raw( rest_url( 'gs/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'domain'  => apply_filters( 'gs_jitsi_domain', 'meet.gend.me' ),
		) );
	}, 20 );
}

// Phase 29 Plan 03 — booking notifications (Gend_GS_Booking_Notifications, file_exists guard per Pitfall 1).
// Subscribes to the 3 gs_booking_* action hooks fired by Plan 29-01 (public bookee
// flow) + Plan 29-02 (host-create flow), sends 4 branded HTML emails (confirmed /
// reminder / cancelled / rescheduled) via wp_mail (auto-routed through email-manager's
// EM_Email_SMTP when active), and schedules a single-event WP-Cron reminder 15 min
// before each meeting via wp_schedule_single_event. init() is called at include
// time (NOT hooked to rest_api_init) because the subscribers fire on lifecycle
// hooks that may precede rest_api_init; init() only registers add_action callbacks
// so calling it eagerly is side-effect-free until the hooks themselves fire.
if ( file_exists( GS_DIR . 'inc/class-booking-notifications.php' ) ) {
	require_once GS_DIR . 'inc/class-booking-notifications.php';
	Gend_GS_Booking_Notifications::init();
}

// Phase 29 Plan 04 — ICS download + subscribable feed (Gend_GS_Booking_ICS,
// file_exists guard per Pitfall 1). Registers TWO REST routes on rest_api_init:
// GET /gs/v1/calendar/meetings/{id}/ics — authed single-meeting download
// (host or member-guest); GET /gs/v1/calendar/ics/{share_token} — public
// read-only subscribable feed for external calendar apps (Google Calendar,
// Apple Calendar, Outlook). RFC 5545 compliant output (CRLF + 75-octet line
// folding + TEXT escaping + VERSION/PRODID/METHOD/STATUS headers). NOTIF-03
// + NOTIF-04 + MEET-05 download surface.
if ( file_exists( GS_DIR . 'inc/class-booking-ics.php' ) ) {
	require_once GS_DIR . 'inc/class-booking-ics.php';
	add_action( 'rest_api_init', array( 'Gend_GS_Booking_ICS', 'register_routes' ) );
}

// Phase 29 Plan 04 — public booking page at /calendar-book/{share_token}
// (Gend_GS_Booking_Public_Page, file_exists guard per Pitfall 1). Hooks
// template_redirect at priority 5 so we intercept the URL BEFORE WP's native
// 404 handler fires. Matches a 43-char alphanumeric share_token via preg_match
// on REQUEST_URI (not WP rewrite rules — avoids flush_rules requirement). On
// match: SELECTs the host via Gend_GS_Availability_Schema, renders a
// standalone glassmorphic HTML page (NOT a theme template) that enqueues
// booking-public.{js,css} + inlines window.gsBookingData payload, exits. init()
// only registers an add_action so calling it eagerly here is side-effect-free.
if ( file_exists( GS_DIR . 'inc/class-booking-public-page.php' ) ) {
	require_once GS_DIR . 'inc/class-booking-public-page.php';
	Gend_GS_Booking_Public_Page::init();
}

// Phase 31-frontend — read-only public shared-calendar view at
// /calendar-view/{share_token} (Gend_GS_Calendar_Public_View, file_exists guard
// per Pitfall 1). Registers a rewrite rule (flushed once per version bump) AND a
// template_redirect interceptor (priority 5) so the URL works even before a
// flush propagates. Renders a STANDALONE glassmorphic shell that enqueues
// calendar-public-view.{js,css} + inlines { restBase, token }; the JS calls the
// token-gated /gs/v1/calendar/public/{token}/{info,events} endpoints which
// enforce privacy server-side. init() only adds hooks — side-effect-free here.
if ( file_exists( GS_DIR . 'inc/class-calendar-public-view.php' ) ) {
	require_once GS_DIR . 'inc/class-calendar-public-view.php';
	Gend_GS_Calendar_Public_View::init();
}

// Connections → Invite sub-tab (email/CSV → invite emails with affiliate URL).
// Optional — file_exists guard so the plugin still activates when these
// modules aren't shipped on this branch (caught in production where
// gend-society.php hard-required them but they weren't in the build,
// fataling activation cluster-wide).
foreach ( array(
    'inc/profile-invite.php',
    'inc/profile-invite-oauth.php',
    'inc/profile-invite-settings.php',
    'inc/profile-portfolio.php',
    'inc/profile-resume.php',
    'inc/profile-contracts.php',
) as $gs_optional_file ) {
    if ( file_exists( GS_DIR . $gs_optional_file ) ) {
        require_once GS_DIR . $gs_optional_file;
    }
}

// Custom Login Styling
require_once GS_DIR . 'inc/login-style.php';

// OAuth login replacement — replaces wp-login.php with a "Sign in with
// gend.me" flow on every site that has gend-society active EXCEPT
// gend.me itself. Plays nice with login-style.php (CSS + animations
// still apply on action=lostpassword/register where we fall through
// to the native form).
require_once GS_DIR . 'inc/oauth-login.php';

// gend.me portal handshake + support access + feature gating
require_once GS_DIR . 'inc/portal-connect.php';
require_once GS_DIR . 'inc/support-access.php';
// Container-side agent provisioning rail (file_exists-guarded: the new include
// may not have synced to the live PVC before this entrypoint — guard prevents a
// require_once fatal that WP would punish with plugin auto-deactivation).
if ( file_exists( GS_DIR . 'inc/agent-provision.php' ) ) {
    require_once GS_DIR . 'inc/agent-provision.php';
}
// Container-side AI-agent chat reply hook (Phase 35: messages_message_sent ->
// persona reply via hub LEO -> messages_new_message AS the agent). Same
// file_exists guard rationale as agent-provision above: the live container
// runs an OLDER gend-society and the include may not have synced yet — a bare
// require_once would fatal and WP would auto-deactivate the plugin.
if ( file_exists( GS_DIR . 'inc/agent-chat.php' ) ) {
    require_once GS_DIR . 'inc/agent-chat.php';
}
// v8.2 Phase 42 — Members/Agents/Projects chat tabs (server-side thread prune).
// Loaded AFTER agent-chat.php because it reuses gs_user_is_agent(). file_exists-
// guarded: hub PVC .no-plugin-sync quirk means a missing file must degrade, not
// fatal (a bare require_once would fatal and WP would auto-deactivate the plugin).
if ( file_exists( GS_DIR . 'inc/messages-tabs.php' ) ) {
    require_once GS_DIR . 'inc/messages-tabs.php';
}
require_once GS_DIR . 'inc/feature-gates.php';

// AI proxy — server-side bridge to the LEO backend on the gend.me hub.
// Lets subsites use AI features (chat, wireframe, content blocks, etc.)
// without LEO installed locally; auth + central AI-token balance ride the
// contracts-and-payments OAuth bearer.
require_once GS_DIR . 'inc/ai-proxy.php';

// AI chat widget loader — serves LEO's frontend widget from the hub on
// subsites without LEO. Dormant while LEO is active locally.
require_once GS_DIR . 'inc/ai-widget.php';

// Wireframe artifact store — persists the [aipa_wireframe] output so the
// shortcode shows the saved version on subsequent loads, and mirrors the
// HTML to the hub's linked group for the Business Plan → Wireframe sub-tab.
require_once GS_DIR . 'inc/wireframe-store.php';

// Chatflow router — gs/v1/chatflows/<slug> endpoint that serves the flow
// definition locally on the hub (via Leo_Chatflow) or forwards to the hub
// over OAuth on customer subsites. Lets the flow engine target one stable
// gend-society-namespaced endpoint regardless of where it's running.
require_once GS_DIR . 'inc/chatflows-router.php';

// User-profile router — gs/v1/user-profile (GET/PUT). Hub delegates to
// Leo_DB; subsites use blog-suffixed user_meta so each customer's
// per-user chatflow profile (industry, brand inputs, etc.) stays isolated.
require_once GS_DIR . 'inc/user-profile-router.php';

// Feature-access upgrade prompt page — shown when a customer hits a
// wp-admin area their current Dashboard plan doesn't include.
require_once GS_DIR . 'inc/pages/feature-upgrade.php';

// Phase 63-02 — Hosting > Chain Gas Rates admin sub-tab (super-admin
// only). Loads ONLY in wp-admin (perf microoptim) and only when the
// Plan 63-01 charger class file is present (file_exists guard tolerates
// pre-Plan-63-01 PVCs and rollback windows per project_hub_plugin_sync_gotcha).
if ( is_admin() && file_exists( GS_DIR . 'inc/admin/fiat-gas-rates-tab.php' ) ) {
	require_once GS_DIR . 'inc/admin/fiat-gas-rates-tab.php';
}
