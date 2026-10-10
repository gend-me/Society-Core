<?php
/**
 * Module manifest: the single, ordered list of what gend-society loads.
 *
 * Read by inc/bootstrap/loader.php. ORDER MATTERS: modules are required in
 * exactly this order (it is the old gend-society.php require chain, 1:1).
 *
 * 'modules' entry keys:
 *   file   path relative to the plugin root
 *   tier   core      every runtime (hub, container, standalone)
 *          customer  every customer-facing site; also loads on the hub
 *          container gend.me-managed sites (containers + local) and the hub
 *          hub       the gend.me hub only
 *          updater   the GitHub self-updater
 *   needs  'bp' BuddyPress, 'wu' WP Ultimo, 'paired' linked to gend.me,
 *          'skin' GenD admin skin on (hub: always; container: unless the
 *          site chose native; standalone: only after opting in),
 *          'admin' wp-admin request, 'standalone' standalone runtime only.
 *          On hub and container bp/wu/paired are always met (see
 *          gend_society_module_needs_met()).
 *   after  optional callable run right after the require (hook wiring that
 *          used to follow the require in gend-society.php, verbatim)
 *   note   optional one-line reason / history
 * A 'group' entry registers one action on 'hook' at its position; when it
 * fires and 'requires_class' exists, its 'modules' are required in order.
 *
 * 'partials': inc/ files the loader never requires (another module includes
 * them, or they are deliberately unloaded). Listed so that every
 * inc/**\/*.php appears exactly once in this file.
 *
 * Every require is file_exists-guarded by the loader (hub PVC
 * .no-plugin-sync partial-deploy defence: a missing file must degrade, not
 * fatal and get the plugin auto-deactivated). Deploy new files BEFORE the
 * manifest that lists them.
 *
 * Phase 105's distribution build excludes files by THIS tier metadata, not
 * by directory: hub-tier files stay at their historic paths.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'modules'  => array(
		array(
			'file'  => 'inc/theme-bundle.php',
			'tier'  => 'core',
			'needs' => array(),
			'note'  => 'Bundled GenD Society block theme (registered before setup_theme).',
		),
		array(
			'file'  => 'inc/remote-assets.php',
			'tier'  => 'core',
			'needs' => array(),
			'note'  => 'Consent-gated gend.me image table (Phase 105).',
		),
		array(
			'file'  => 'inc/hub-url.php',
			'tier'  => 'core',
			'needs' => array(),
			'note'  => 'Pure hub-URL / OAuth-config helpers used by customer files (split out of oauth-login.php in 106).',
		),
		array(
			'file'  => 'inc/consent.php',
			'tier'  => 'core',
			'needs' => array(),
			'note'  => 'Consent record + gate for every gend.me call (Phase 106).',
		),
		array(
			'file'  => 'inc/admin-style.php',
			'tier'  => 'core',
			'needs' => array( 'skin' ),
		),
		array(
			'file'  => 'inc/admin-menu.php',
			'tier'  => 'core',
			'needs' => array( 'skin' ),
		),
		array(
			'file'  => 'inc/frontend-bar.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Container tier + bp: never loads on standalone and never ships in the wordpress.org zip (RUN-06 structural guarantee).',
		),
		array(
			'file'  => 'inc/network-referrals.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'gend.me network-wide referral program.',
		),
		array(
			'file'  => 'inc/live-view.php',
			'tier'  => 'core',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/seo-meta.php',
			'tier'  => 'core',
			'needs' => array(),
			'note'  => 'SEO title/description box + meta, Open Graph and Twitter tags.',
		),
		array(
			'file'  => 'inc/class-gend-github-updater.php',
			'tier'  => 'updater',
			'needs' => array(),
			'after' => static function () {
				new Gend_Society_GitHub_Updater( GEND_SOCIETY_FILE, 'gend-me/Society-Core' );
			},
		),
		array(
			'file'  => 'inc/dashboard-overview.php',
			'tier'  => 'customer',
			'needs' => array( 'wu' ),
			'note'  => 'Dashboard overrides (Standalone).',
		),
		array(
			'file'  => 'inc/dashboard-app-management.php',
			'tier'  => 'customer',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/dashboard-remote-membership.php',
			'tier'  => 'customer',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/feature-state-reporter.php',
			'tier'  => 'customer',
			'needs' => array( 'paired' ),
			'note'  => 'Container -> hub plugin-state reporter (no-op on hub: no install pairing).',
		),
		array(
			'file'  => 'inc/mail-relay.php',
			'tier'  => 'customer',
			'needs' => array( 'paired' ),
			'note'  => 'gend.me mail service, container side: a paired install sends mail through gend.me.',
		),
		array(
			'file'  => 'inc/dashboard-hosting.php',
			'tier'  => 'customer',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/dashboard-hosting-domains.php',
			'tier'  => 'customer',
			'needs' => array(),
			'note'  => 'Phase 72-02 Connect-a-Domain wizard; never deployed to the hub, absent-safe.',
		),
		array(
			'file'  => 'inc/dashboard-hosting-records-modal.php',
			'tier'  => 'customer',
			'needs' => array(),
			'note'  => 'Phase 73-02 DNS records-editor modal; never deployed to the hub, absent-safe.',
		),
		array(
			'file'  => 'inc/media-storage-panel.php',
			'tier'  => 'customer',
			'needs' => array(),
			'note'  => 'Loads AFTER dashboard-hosting + dashboard-remote-membership (uses their helpers).',
		),
		array(
			'file'  => 'inc/feature-cards.php',
			'tier'  => 'customer',
			'needs' => array( 'skin' ),
		),
		array(
			'file'  => 'inc/pages/dashboard.php',
			'tier'  => 'customer',
			'needs' => array( 'skin' ),
		),
		array(
			'file'  => 'inc/group-embed.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Group menu -> inline tab pages; must load after the renderers above.',
		),
		array(
			'file'  => 'inc/web-shell.php',
			'tier'  => 'hub',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/web-shell-gcloud.php',
			'tier'  => 'hub',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/web-shell-sites.php',
			'tier'  => 'hub',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/web-shell-chat.php',
			'tier'  => 'hub',
			'needs' => array(),
		),
		array(
			'group'          => 'bp_include',
			'hook'           => 'bp_include',
			'requires_class' => 'BP_Group_Extension',
			'note'           => 'BP group tabs wait for BP_Group_Extension (gend-society loads before social-network, where BP ships).',
			'modules'        => array(
				array(
					'file'  => 'inc/group-feature-suite.php',
					'tier'  => 'container',
					'needs' => array( 'bp' ),
				),
				array(
					'file'  => 'inc/group-app-tabs.php',
					'tier'  => 'container',
					'needs' => array( 'bp' ),
				),
				array(
					'file'  => 'inc/group-davinci-ai-tab.php',
					'tier'  => 'container',
					'needs' => array( 'bp' ),
					'note'  => 'Davinci Architect AI tab; after group-app-tabs (shared helpers).',
				),
				array(
					'file'  => 'inc/collab/group-tab-collab.php',
					'tier'  => 'container',
					'needs' => array( 'bp' ),
					'note'  => 'GenD Match swipe-deck group tab; after group-app-tabs (access gate).',
				),
			),
		),
		array(
			'file'  => 'inc/member-profile-pages.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Member profile pages (per-user CPT + BuddyPress embed).',
		),
		array(
			'file'  => 'inc/member-profile-header.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Member profile header (terminal-style header + nav bar).',
		),
		array(
			'file'  => 'inc/member-calendar.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Member calendar (CALENDAR primary-nav tab + glass grid).',
		),
		array(
			'file'  => 'inc/calendar-events-rest.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'CALENDAR REST events aggregation (Phase 27).',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Calendar_Events_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/class-availability-schema.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Availability + Meetings schema installer (Phase 28-01).',
			'after' => static function () {
				Gend_GS_Availability_Schema::init();
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-taxonomy.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'GenD Match rule-based taxonomy (pure static class).',
		),
		array(
			'file'  => 'inc/collab/class-collab-schema.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'GenD Match swipe-ledger schema (Phase 82-01).',
			'after' => static function () {
				Gend_GS_Collab_Schema::init();
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-deck.php',
			'tier'  => 'container',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/collab/class-collab-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Own rest_api_init binding (per-class pattern; do not fold into the calendar REST class).',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Collab_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-match.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Mutual-match engine (Phase 83); no hooks.',
		),
		array(
			'file'  => 'inc/collab/class-collab-contract.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Contract escalation engine (Phase 84); must load before rest_api_init.',
		),
		array(
			'file'  => 'inc/collab/class-collab-resolver.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Terminal-outcome recorder + cron backstop (Phase 85-02); after the contract class.',
			'after' => static function () {
				Gend_GS_Collab_Resolver::init();
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-bc-math.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Float-free bcmath LMSR money math (Phase 86-01).',
		),
		array(
			'file'  => 'inc/collab/class-collab-market.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'LMSR market engine; lock (pri 10) runs before settle (pri 20) on the outcome hook.',
			'after' => static function () {
				// MARKET-01: auto-create on the contracted flip (gated inside on_contracted).
				add_action( 'gend_gs_collab_contracted', array( 'Gend_GS_Collab_Market', 'on_contracted' ), 10, 2 );
				// MARKET-05: lock on any recorded terminal outcome (flag-independent CAS).
				add_action( 'gend_gs_collab_outcome_recorded', array( 'Gend_GS_Collab_Market', 'on_outcome_recorded' ), 10, 2 );
				// Phase 88: deterministic resolve after the pri-10 lock; hub-only, flag-independent (money-safety).
				add_action( 'gend_gs_collab_outcome_recorded', array( 'Gend_GS_Collab_Market', 'settle' ), 20, 2 );
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-market-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Self-gates on is_main_node() + GS_COLLAB_MARKET_PUBLIC: routes absent (404) when off.',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Collab_Market_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-portfolio-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Private portfolio route; self-gates like the market REST class.',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Collab_Portfolio_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/collab/member-markets.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Self-registers its dark-guarded bp_setup_nav tab.',
		),
		array(
			'file'  => 'inc/collab/member-tab-collab.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Member Match tab helpers (nav retired); uses GS_Group_Tab_Collab at runtime.',
		),
		array(
			'file'  => 'inc/collab/group-payments-match-launcher.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Business-partner match popup on group Payments pages.',
		),
		array(
			'file'  => 'inc/group-mobile-menu.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Mobile group menu (sticky folder button).',
		),
		array(
			'file'  => 'inc/collab/class-collab-sync-crypto.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'ed25519 sign/verify primitive for collab federation (Phase 89-01).',
		),
		array(
			'file'  => 'inc/collab/class-collab-sync.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Collab federation; one class self-gating hub vs container (Phase 89-02).',
			'after' => static function () {
				// HUB: receive route under the existing gend-pm-sync/v1 namespace (self-gates on is_hub()).
				add_action( 'rest_api_init', array( 'Gend_GS_Collab_Sync', 'register_receive' ) );
				// CONTAINER: push after every local swipe. No-ops on the hub. Priority 10, 4 args.
				add_action( 'gend_gs_collab_swiped', array( 'Gend_GS_Collab_Sync', 'on_local_swipe' ), 10, 4 );
				// CONTAINER: outbox drain on the existing gs_fifteen_min interval; self-gates on ! is_hub().
				if ( class_exists( 'Gend_GS_Collab_Resolver' ) && method_exists( 'Gend_GS_Collab_Resolver', 'init_container' ) ) {
					Gend_GS_Collab_Resolver::init_container();
				}
			},
		),
		array(
			'file'  => 'inc/collab/class-collab-market-mirror.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Container read-only hub market mirror (Phase 89-03).',
			'after' => static function () {
				Gend_GS_Collab_Market_Mirror::init(); // self-gates on GS_COLLAB_MARKET_PUBLIC + ! is_hub().
			},
		),
		array(
			'file'  => 'inc/class-availability-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'GET/PUT gs/v1/calendar/availability (Phase 28-02).',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Availability_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/class-booking-public-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Public booking REST (Phase 29-01).',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Booking_Public_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/class-booking-meetings-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Authed meetings REST (Phase 29-02); after 29-01.',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Booking_Meetings_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/class-jitsi-jwt.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Hand-rolled HS256 JWT minter for native gend video (static helpers).',
		),
		array(
			'file'  => 'inc/class-jitsi-rest.php',
			'tier'  => 'container',
			'needs' => array(),
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Jitsi_REST', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/jitsi-embed-assets.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Phase 30-03 jitsi-embed enqueue on the member profile (was inline in gend-society.php).',
		),
		array(
			'file'  => 'inc/class-booking-notifications.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Booking emails + reminder cron; init() at include time (hooks may precede rest_api_init).',
			'after' => static function () {
				Gend_GS_Booking_Notifications::init();
			},
		),
		array(
			'file'  => 'inc/class-booking-ics.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'ICS download + subscribable feed (Phase 29-04).',
			'after' => static function () {
				add_action( 'rest_api_init', array( 'Gend_GS_Booking_ICS', 'register_routes' ) );
			},
		),
		array(
			'file'  => 'inc/class-booking-public-page.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Public booking page /calendar-book/{token} (Phase 29-04).',
			'after' => static function () {
				Gend_GS_Booking_Public_Page::init();
			},
		),
		array(
			'file'  => 'inc/class-calendar-public-view.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Read-only shared calendar /calendar-view/{token} (Phase 31).',
			'after' => static function () {
				Gend_GS_Calendar_Public_View::init();
			},
		),
		array(
			'file'  => 'inc/profile-invite.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Connections -> Invite sub-tab (optional module).',
		),
		array(
			'file'  => 'inc/profile-invite-oauth.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
		),
		array(
			'file'  => 'inc/profile-invite-settings.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
		),
		array(
			'file'  => 'inc/profile-portfolio.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
		),
		array(
			'file'  => 'inc/profile-resume.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
		),
		array(
			'file'  => 'inc/profile-contracts.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
		),
		array(
			'file'  => 'inc/login-style.php',
			'tier'  => 'core',
			'needs' => array( 'skin' ),
			'note'  => 'Custom login styling.',
		),
		array(
			'file'  => 'inc/oauth-login.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => '"Sign in with gend.me" on every site except gend.me itself; container tier since 106: hub + containers only; never standalone, never in the wordpress.org zip.',
		),
		array(
			'file'  => 'inc/portal-connect.php',
			'tier'  => 'customer',
			'needs' => array(),
			'note'  => 'gend.me portal handshake.',
		),
		array(
			'file'  => 'inc/support-access.php',
			'tier'  => 'customer',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/agent-switch.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => '"Switch to this Agent" identity swap; container tier: no hub gate inside, caller (projects) runs on containers too.',
		),
		array(
			'file'  => 'inc/agent-provision.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Container-side agent provisioning rail.',
		),
		array(
			'file'  => 'inc/agent-chat.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Container-side AI-agent chat reply hook (Phase 35).',
		),
		array(
			'file'  => 'inc/messages-tabs.php',
			'tier'  => 'container',
			'needs' => array( 'bp' ),
			'note'  => 'Members/Agents/Projects chat tabs; after agent-chat (gs_user_is_agent).',
		),
		array(
			'file'  => 'inc/feature-gates.php',
			'tier'  => 'customer',
			'needs' => array(),
		),
		array(
			'file'  => 'inc/ai-proxy.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Server-side bridge to LEO on the hub over the C&P OAuth bearer.',
		),
		array(
			'file'  => 'inc/ai-widget.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'Serves LEO\'s frontend widget from the hub on sites without LEO.',
		),
		array(
			'file'  => 'inc/wireframe-store.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => '[aipa_wireframe] artifact store + hub mirror.',
		),
		array(
			'file'  => 'inc/chatflows-router.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'gs/v1/chatflows/<slug>: local on the hub, forwarded over OAuth elsewhere.',
		),
		array(
			'file'  => 'inc/user-profile-router.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'gs/v1/user-profile: Leo_DB on the hub, blog-suffixed user_meta elsewhere.',
		),
		array(
			'file'  => 'inc/pages/feature-upgrade.php',
			'tier'  => 'customer',
			'needs' => array(),
			'note'  => 'Feature-access upgrade prompt page.',
		),
		array(
			'file'  => 'inc/admin-experience.php',
			'tier'  => 'core',
			'needs' => array( 'standalone' ),
			'note'  => 'Standalone opt-in admin skin: toggle handler + Switch back.',
		),
		array(
			'file'  => 'inc/readiness.php',
			'tier'  => 'customer',
			'needs' => array(),
			'note'  => 'Local readiness checks; pure functions, reused by Phase 110 pre-flight; no remote call.',
		),
		array(
			'file'  => 'inc/pages/welcome.php',
			'tier'  => 'customer',
			'needs' => array( 'standalone' ),
			'note'  => 'GenD page + welcome notice.',
		),
		array(
			'file'  => 'inc/theme-download-notice.php',
			'tier'  => 'customer',
			'needs' => array( 'standalone' ),
			'note'  => 'wordpress.org build: dismissible link to the gend.me theme download; inert when the theme bundle is present.',
		),
		array(
			'file'  => 'inc/admin/fiat-gas-rates-tab.php',
			'tier'  => 'hub',
			'needs' => array( 'admin' ),
			'note'  => 'Phase 63-02 Hosting > Chain Gas Rates (super-admin); wp-admin only; never deployed to the hub, absent-safe.',
		),
		array(
			'file'  => 'inc/compat-aliases.php',
			'tier'  => 'container',
			'needs' => array(),
			'note'  => 'full build only: old names for sibling plugins (generated by bin/gen-compat.php --part=late; keep LAST).',
		),
	),
	'partials' => array(
		array(
			'file'      => 'inc/pages/feature-access.php',
			'tier'      => 'customer',
			'needs'     => array(),
			'loaded_by' => array( 'inc/admin-menu.php', 'inc/dashboard-remote-membership.php', 'inc/group-app-tabs.php', 'inc/group-embed.php' ),
		),
		array(
			'file'      => 'inc/pages/features.php',
			'tier'      => 'customer',
			'needs'     => array(),
			'loaded_by' => array( 'inc/admin-menu.php' ),
		),
		array(
			'file'      => 'inc/pages/shortcodes.php',
			'tier'      => 'customer',
			'needs'     => array(),
			'loaded_by' => array( 'inc/admin-menu.php' ),
		),
		array(
			'file'      => 'inc/pages/shortcodes-editor.php',
			'tier'      => 'container',
			'needs'     => array(),
			'loaded_by' => array( 'inc/pages/shortcodes.php' ),
			'note'      => 'Phase 105: the "New Shortcode" PHP writer (mu-plugins/gs-shortcodes.php); not in the wordpress.org build.',
		),
		array(
			'file'      => 'inc/pages/store.php',
			'tier'      => 'customer',
			'needs'     => array(),
			'loaded_by' => array( 'inc/admin-menu.php' ),
			'note'      => 'Via the gs_store_dashboard_path filter.',
		),
		array(
			'file'      => 'inc/pages/users.php',
			'tier'      => 'customer',
			'needs'     => array(),
			'loaded_by' => array(),
			'note'      => 'unloaded: no loader anywhere today.',
		),
		array(
			'file'      => 'inc/pages/app.php',
			'tier'      => 'customer',
			'needs'     => array(),
			'loaded_by' => array(),
			'note'      => 'unloaded: no loader anywhere today.',
		),
		array(
			'file'      => 'inc/templates/live-view-blank.php',
			'tier'      => 'core',
			'needs'     => array(),
			'loaded_by' => array( 'inc/live-view.php' ),
		),
		array(
			'file'      => 'inc/member-playlists.php',
			'tier'      => 'container',
			'needs'     => array( 'bp' ),
			'loaded_by' => array(),
			'note'      => 'not loaded on any runtime; kept unloaded to preserve behaviour.',
		),
		array(
			'file'      => 'inc/bootstrap/context.php',
			'tier'      => 'core',
			'needs'     => array(),
			'loaded_by' => array( 'gend-society.php' ),
			'note'      => 'Bootstrap: runtime-mode resolver (hard require in the entrypoint).',
		),
		array(
			'file'      => 'inc/bootstrap/loader.php',
			'tier'      => 'core',
			'needs'     => array(),
			'loaded_by' => array( 'gend-society.php' ),
			'note'      => 'Bootstrap: this manifest\'s loader (hard require in the entrypoint).',
		),
		array(
			'file'      => 'inc/bootstrap/key-migration.php',
			'tier'      => 'core',
			'needs'     => array(),
			'loaded_by' => array( 'gend-society.php' ),
			'note'      => 'Bootstrap: one-time copy-not-move gs_/gdc_ -> gend_society_ key migration (hard require in the entrypoint, before the loader).',
		),
		array(
			'file'      => 'inc/bootstrap/key-map.php',
			'tier'      => 'core',
			'needs'     => array(),
			'loaded_by' => array( 'inc/bootstrap/key-migration.php' ),
			'note'      => 'Generated key allowlist (bin/gen-keymap.php); required by inc/bootstrap/key-migration.php.',
		),
		array(
			'file'      => 'inc/compat-bridges.php',
			'tier'      => 'container',
			'needs'     => array(),
			'loaded_by' => array( 'inc/bootstrap/key-migration.php' ),
			'note'      => 'Generated old-key read/write bridges (bin/gen-compat.php --part=early); full build only.',
		),
		array(
			'file'      => 'inc/bootstrap/manifest.php',
			'tier'      => 'core',
			'needs'     => array(),
			'loaded_by' => array( 'inc/bootstrap/loader.php' ),
			'note'      => 'This file.',
		),
	),
);
