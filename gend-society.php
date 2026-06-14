<?php
/**
 * Plugin Name: GenD Society
 * Plugin URI:  https://gend.me
 * Description: Futuristic glassmorphic WordPress admin experience with custom menus, redesigned backend, and dynamic frontend sidebar.
 * Version:     1.0.3
 * Author:      By GenD
 * Author URI:  https://gend.me
 * Network:     true
 * Text Domain: gend-society
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_VERSION', '1.1.0');
define('GS_DIR', plugin_dir_path(__FILE__));
define('GS_URL', plugin_dir_url(__FILE__));

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
