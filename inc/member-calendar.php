<?php
/**
 * GenD Society — Member Calendar primary-nav tab.
 *
 * Registers the CALENDAR primary-nav tab in the BuddyPress member profile,
 * positioned immediately to the right of APP PROJECTS (the renamed Groups nav).
 * The tab renders full-width with the Youzify/BP sidebar collapsed — a
 * near-verbatim copy of the proven Wallet-tab pattern in member-profile-pages.php,
 * with one delta: the nav position is computed from the live `groups` nav
 * position + 1 instead of hardcoded.
 *
 * Phase 26 Plan 01 establishes the scaffold:
 *   - tab registration + screen callback + full-width CSS + asset enqueue
 * Phase 26 Plan 02 fills assets/member-calendar.{css,js} with the interactive grid.
 * Phase 27 emits the gs/v1/calendar/events REST contract the JS consumes.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the CALENDAR primary-nav tab on BuddyPress member profiles.
 *
 * Hooked at bp_setup_nav priority 100 so it runs after BP core/Youzify have
 * registered their primary nav items (lets us read the live `groups` position
 * and de-dupe foreign "Calendar" tabs). ALL BP interaction happens here, never
 * at file-include time — gend-society loads alphabetically before
 * social-network (where BP ships), so touching BP at include time fatals
 * (Pitfall 19).
 */
add_action( 'bp_setup_nav', 'gs_add_calendar_profile_tab', 100 );
function gs_add_calendar_profile_tab() {
	if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
		return;
	}

	// Compute the position — land immediately right of APP PROJECTS (slug
	// stays 'groups' even though it's display-renamed). Do NOT hardcode: the
	// live groups position varies by site (Youzify reorders), so derive it.
	$pos = 17; // safe default = BP groups(16) + 1
	if ( function_exists( 'buddypress' ) && isset( buddypress()->members->nav ) ) {
		foreach ( buddypress()->members->nav->get_primary() as $item ) {
			if ( ( $item['slug'] ?? '' ) === 'groups' ) {
				$pos = (int) $item['position'] + 1;
				break;
			}
		}
	}

	bp_core_new_nav_item( [
		'name'                    => __( 'Calendar', 'gend-society' ),
		'slug'                    => 'member-calendar',
		'screen_function'         => 'gs_calendar_profile_screen',
		'position'                => $pos,
		'item_css_id'             => 'calendar',
		// Required NOW so the Phase 29 shared public-booking view can render on
		// non-owner profiles. The screen-content gate below still keeps the grid
		// private this phase (the shared-view exception lands in Phase 29).
		'show_for_displayed_user' => true,
	] );

	// Remove any OTHER primary nav item named "Calendar" to prevent duplicates
	// (guards against another plugin's "Calendar" tab). Mirrors the Wallet loop.
	$primary_nav = buddypress()->members->nav->get_primary();
	foreach ( $primary_nav as $nav_item ) {
		if ( $nav_item['slug'] !== 'member-calendar'
			&& ( strpos( strtolower( $nav_item['name'] ), 'calendar' ) !== false ) ) {
			bp_core_remove_nav_item( $nav_item['slug'] );
		}
	}
}

/**
 * Screen callback for the CALENDAR tab — loads the BP plugins template and
 * routes its title/content to our handlers.
 */
function gs_calendar_profile_screen() {
	add_action( 'bp_template_title', '__return_empty_string' );
	add_action( 'bp_template_content', 'gs_calendar_profile_screen_content' );
	bp_core_load_template( 'members/single/plugins' );
}

/**
 * Render the full-width calendar pane.
 *
 * Gates on bp_is_my_profile() this phase (the shared-view exception arrives in
 * Phase 29). Emits the sidebar-collapse CSS scoped to `.member-calendar` (BP
 * adds that body class automatically for the component slug), the calendar root
 * container that 26-02's JS mounts into, and the per-tab asset enqueue.
 */
function gs_calendar_profile_screen_content() {
	// Only show on the current user's own profile this phase.
	if ( ! bp_is_my_profile() ) {
		echo '<p>' . esc_html__( 'This calendar is private.', 'gend-society' ) . '</p>';
		return;
	}

	// Force full width and hide the sidebar for the calendar tab.
	// Scoped to the .member-calendar body class which BP adds automatically for
	// this component slug. This collapses the Youzify two-column grid first —
	// without that, the main column's width:100% is just 100% of its 72% cell.
	echo '<style>
		/* ── Calendar tab: hide sidebar, make main column full width ─────── */
		.member-calendar .youzify-right-sidebar-layout,
		.member-calendar .youzify-left-sidebar-layout {
			display: block !important;
			grid-template-columns: 1fr !important;
			grid-gap: 0 !important;
		}
		.member-calendar .youzify-sidebar-column,
		.member-calendar .youzify-sidebar,
		.member-calendar .yz-sidebar-column,
		.member-calendar .youzify-profile-sidebar,
		.member-calendar #secondary {
			display: none !important;
		}
		.member-calendar .youzify-page-main-content {
			max-width: none !important;
			width: 100% !important;
		}

		.member-calendar .youzify-main-column,
		.member-calendar .youzify-content,
		.member-calendar .yz-main-column,
		.member-calendar #primary {
			width: 100% !important;
			flex: 0 0 100% !important;
			max-width: 100% !important;
			border: none !important;
			background: transparent !important;
			padding: 0 !important;
			margin: 0 !important;
			box-shadow: none !important;
		}

		/* Strip every Youzify wrapper so the calendar renders edge-to-edge */
		.member-calendar .youzify-main-column-inner,
		.member-calendar .youzify-widget,
		.member-calendar .yz-widget,
		.member-calendar .youzify-widget-head,
		.member-calendar .youzify-widget-content,
		.member-calendar .youzify-inner-content,
		.member-calendar .youzify-page-main-content,
		.member-calendar .youzify-profile-main-content {
			width: 100% !important;
			max-width: 100% !important;
			padding: 0 !important;
			margin: 0 !important;
			border: none !important;
			border-radius: 0 !important;
			box-shadow: none !important;
			background: transparent !important;
			overflow: visible !important;
		}

		/* Hide the Youzify/BP injected page title above the content */
		.member-calendar .youzify-page-title,
		.member-calendar .bp-page-title,
		.member-calendar .entry-title { display: none !important; }

		/* ── Strip the entire Youzify member-profile shell so the calendar tab
		      is a standalone dashboard: cover photo, avatar header, and profile
		      nav are siblings of the content (not ancestors), so the JS cannot
		      reach them — kill them here. */
		.member-calendar #youzify-profile-header,
		.member-calendar .youzify-profile-header,
		.member-calendar #youzify-header,
		.member-calendar .youzify-header,
		.member-calendar .yz-header,
		.member-calendar #item-header,
		.member-calendar #item-header-cover-image,
		.member-calendar .youzify-header-cover,
		.member-calendar .youzify-cover-image,
		.member-calendar .youzify-cover-content,
		.member-calendar .youzify-cover-area,
		.member-calendar #youzify-profile-navmenu,
		.member-calendar .youzify-profile-navmenu,
		.member-calendar .youzify-navbar,
		.member-calendar .youzify-header-nav,
		.member-calendar #object-nav,
		.member-calendar #item-nav { display: none !important; }

		/* Drop the Youzify shell background/padding on this tab too. */
		.member-calendar #youzify,
		.member-calendar #youzify-bp,
		.member-calendar .youzify,
		.member-calendar .youzify-content {
			background: transparent !important;
			box-shadow: none !important;
			border: 0 !important;
			padding: 0 !important;
			margin: 0 !important;
		}

		/* Calendar root wrapper */
		.gs-cal-root {
			width: 100% !important;
			max-width: 100% !important;
			margin: 0 !important;
			padding: 0 !important;
			box-sizing: border-box !important;
		}
	</style>';

	// Enqueue the calendar assets on the screen callback so they only load on
	// the calendar tab. filemtime-busted single-version idiom (admin-style.php:9-11);
	// file_exists-guard the filemtime so a missing asset degrades to no warning.
	$css_path = GS_DIR . 'assets/member-calendar.css';
	$js_path  = GS_DIR . 'assets/member-calendar.js';
	$css_ver  = GS_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
	$js_ver   = GS_VERSION . ( file_exists( $js_path ) ? '.' . filemtime( $js_path ) : '' );
	wp_enqueue_style( 'gs-member-calendar', GS_URL . 'assets/member-calendar.css', [], $css_ver );
	wp_enqueue_script( 'gs-member-calendar', GS_URL . 'assets/member-calendar.js', [], $js_ver, true );

	// Calendar root. 26-02's JS mounts the grid into #gs-calendar-app and reads
	// data-tz (site timezone this phase; per-member tz arrives Phase 28). The
	// grid is JS-rendered — do NOT render it in PHP.
	echo '<div id="gs-calendar-app" class="gs-cal-root" data-tz="' . esc_attr( wp_timezone_string() ) . '"></div>';

	// Guarantee the .member-calendar body class is present so the CSS above
	// applies, even if a theme/Youzify body_class path drops it. This screen
	// only renders on the calendar tab, so adding the class here is scoped.
	echo '<script>(function(){var b=document.body;if(b){b.classList.add("member-calendar");}})();</script>';
}

// Make the .member-calendar body class authoritative server-side for the
// calendar tab (belt-and-braces with the inline <script> above).
add_filter( 'body_class', 'gs_calendar_body_class' );
function gs_calendar_body_class( $classes ) {
	if ( function_exists( 'bp_is_user' ) && bp_is_user()
		&& function_exists( 'bp_current_component' ) && bp_current_component() === 'member-calendar' ) {
		$classes[] = 'member-calendar';
	}
	return $classes;
}
