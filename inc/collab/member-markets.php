<?php
/**
 * gend-society — GenD Match dark-gated Collaborations member-nav tab (Phase 87-03, v12.0, STAKE-02/05).
 *
 * Registers a "Collaborations" primary-nav tab in the BuddyPress member profile — the single
 * member surface that ties together the Phase-86 read routes, the 87-02 bet WRITE route, and the
 * 87-03 PRIVATE portfolio route. On the member's OWN profile it renders: the open markets with
 * live odds, inline buy/sell controls (POST to the 87-02 route), the member's inline position, the
 * private portfolio + a Chart.js P/L, and the rake disclosure. Mirrors the proven member-calendar.php
 * bp_core_new_nav_item mount idiom.
 *
 * DARK + HUB gate (locked decision — v6.0 Pitfall 1 discipline / GATE-01). The ENTIRE bp_setup_nav
 * registration early-returns when GS_COLLAB_MARKET_PUBLIC is off OR when this is not the hub
 * (is_main_node) — so when the flag is off the nav item is NEVER registered and the tab is
 * DOM-ABSENT (not CSS-hidden). Nothing member-facing renders.
 *
 * PRIVACY (GATE-02). The screen content gates on bp_is_my_profile() — a member can only ever see
 * their OWN Collaborations/portfolio on their own profile. The tab is registered
 * show_for_displayed_user=false (own-profile-only surface). The private portfolio route
 * (GET gs/v1/portfolio) itself only ever returns get_current_user_id() rows.
 *
 * NEUTRAL COPY only (no "bet/odds/wager/payout") — "Collaborations" / "markets" / "position" /
 * "implied probability" (mirrors class-collab-market-rest.php neutral-copy discipline).
 *
 * All BP interaction happens on bp_setup_nav (priority 100), never at file-include time —
 * gend-society loads alphabetically before social-network (where BP ships), so touching BP at
 * include time fatals (Pitfall 19). Every BP call is function_exists-guarded.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hub gate: true when the runtime mode is hub (gend_society_is_hub(), from
 * GEND_SOCIETY_RUNTIME), false on containers and standalone installs.
 * Before 1.1.6 this inferred "hub" from a missing Gend_CP_OAuth_Resource
 * class, which made every standalone install look like the hub.
 *
 * @return bool
 */
function gs_markets_is_main_node() {
	return gend_society_is_hub();
}

/**
 * Register the Collaborations primary-nav tab on BuddyPress member profiles.
 *
 * The WHOLE registration is wrapped in the DARK + HUB early-return: when GS_COLLAB_MARKET_PUBLIC
 * is off OR this is not the hub, the tab is NEVER registered (DOM-absent, GATE-01) — not
 * CSS-hidden. Hooked at bp_setup_nav priority 100 so it runs after BP core/Youzify have
 * registered their primary nav items (lets us read the live `groups` position).
 */
add_action( 'bp_setup_nav', 'gs_add_markets_profile_tab', 100 );
function gs_add_markets_profile_tab() {
	// DARK: no tab when the counsel flag is off (default). The registration below never runs, so
	// there is NO Collaborations DOM anywhere (route + tab both absent) — GATE-01 discipline.
	if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) || ! GS_COLLAB_MARKET_PUBLIC ) {
		return;
	}
	// HUB-only: the LMSR engine + DGEN + positions live on the hub; on a container the tab is absent.
	if ( ! gs_markets_is_main_node() ) {
		return;
	}
	if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
		return;
	}

	// Compute the position — land immediately right of APP PROJECTS (slug stays 'groups' even
	// though it's display-renamed). Do NOT hardcode: the live groups position varies by site.
	$pos = 18; // safe default = BP groups(16) + 2 (past the calendar tab at groups+1)
	if ( function_exists( 'buddypress' ) && isset( buddypress()->members->nav ) ) {
		foreach ( buddypress()->members->nav->get_primary() as $item ) {
			if ( ( $item['slug'] ?? '' ) === 'groups' ) {
				$pos = (int) $item['position'] + 2;
				break;
			}
		}
	}

	bp_core_new_nav_item( array(
		'name'                    => __( 'Collaborations', 'gend-society' ),
		'slug'                    => 'collab-markets',
		'screen_function'         => 'gs_markets_profile_screen',
		'position'                => $pos,
		'item_css_id'             => 'collab-markets',
		// Own-profile-only surface (GATE-02 privacy): the tab shows only on the viewer's own
		// profile; the screen content also gates on bp_is_my_profile().
		'show_for_displayed_user' => false,
	) );
}

/**
 * Screen callback for the Collaborations tab — loads the BP plugins template and routes its
 * content to our handler.
 */
function gs_markets_profile_screen() {
	if ( function_exists( 'add_action' ) ) {
		add_action( 'bp_template_title', '__return_empty_string' );
		add_action( 'bp_template_content', 'gs_markets_profile_screen_content' );
	}
	if ( function_exists( 'bp_core_load_template' ) ) {
		bp_core_load_template( 'members/single/plugins' );
	}
}

/**
 * Render the Collaborations pane — the member's OWN private surface only (GATE-02).
 *
 * Emits the root containers the 87-03 JS mounts into (a markets list, a portfolio panel, and a
 * canvas for the Chart.js P/L) + the rake-disclosure host, then enqueues the vanilla-JS controller,
 * the glassmorphic CSS, and the shared Chart.js handle (enqueue-if-absent so gend-society does not
 * depend on contracts-and-payments being on the page). Localizes the REST root + a wp_rest nonce.
 */
function gs_markets_profile_screen_content() {
	// GATE-02 privacy: a member sees ONLY their own Collaborations/portfolio, on their own profile.
	if ( function_exists( 'bp_is_my_profile' ) && ! bp_is_my_profile() ) {
		echo '<p>' . esc_html__( 'This view is private.', 'gend-society' ) . '</p>';
		return;
	}

	// Root containers the JS mounts into. Neutral copy only.
	echo '<div class="gs-collab-markets-root">'
		. '<div id="gs-collab-markets" class="gs-collab-markets-list" data-loading="1">'
		. '<p class="gs-collab-empty">' . esc_html__( 'Loading collaborations…', 'gend-society' ) . '</p>'
		. '</div>'
		. '<div id="gs-collab-portfolio" class="gs-collab-portfolio-panel">'
		. '<canvas id="gs-collab-pl-chart" class="gs-collab-pl-chart" height="120"></canvas>'
		. '</div>'
		. '</div>';

	// Chart.js: reuse the shared 'chartjs' handle (contracts-and-payments class-ydgen-return-display.php).
	// Enqueue-if-absent (register the same CDN v4.4.1 only when neither registered nor enqueued) so
	// this surface does not depend on c-and-p being on the page. Do NOT add a second charting lib.
	if ( function_exists( 'wp_script_is' ) && function_exists( 'wp_enqueue_script' ) ) {
		if ( ! wp_script_is( 'chartjs', 'registered' ) && ! wp_script_is( 'chartjs', 'enqueued' ) ) {
			if ( function_exists( 'wp_register_script' ) ) {
				wp_register_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
			}
		}
		wp_enqueue_script( 'chartjs' );
	}

	// Per-tab asset enqueue (only on this screen). filemtime-busted single-version idiom
	// (admin-style.php); file_exists-guard the filemtime so a missing asset degrades to no warning.
	$css_path = GS_DIR . 'assets/collab-markets.css';
	$js_path  = GS_DIR . 'assets/collab-markets.js';
	$css_ver  = GS_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
	$js_ver   = GS_VERSION . ( file_exists( $js_path ) ? '.' . filemtime( $js_path ) : '' );

	if ( function_exists( 'wp_enqueue_style' ) ) {
		wp_enqueue_style( 'gs-collab-markets', GS_URL . 'assets/collab-markets.css', array(), $css_ver );
	}
	if ( function_exists( 'wp_enqueue_script' ) ) {
		// Depend on chartjs so the controller can render the P/L chart via window.Chart.
		wp_enqueue_script( 'gs-collab-markets', GS_URL . 'assets/collab-markets.js', array( 'chartjs' ), $js_ver, true );
	}

	// Feed the JS the REST base + a wp_rest nonce (cookie-authed own portfolio/bet => X-WP-Nonce).
	if ( function_exists( 'wp_localize_script' ) ) {
		wp_localize_script( 'gs-collab-markets', 'GSCollabMarkets', array(
			'root'  => esc_url_raw( rest_url( 'gs/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'uid'   => (int) get_current_user_id(),
		) );
	}
}
