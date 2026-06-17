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

	// Phase 27 wire-up: feed the calendar JS the REST base + a wp_rest nonce so it
	// can GET gs/v1/calendar/events (cookie-authed own events => X-WP-Nonce header).
	// member-calendar.js reads window.gsCalendarData.{restUrl,nonce}; absent => mock.
	wp_localize_script( 'gs-member-calendar', 'gsCalendarData', array(
		'restUrl' => esc_url_raw( rest_url( 'gs/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
	) );

	// Phase 28-03: the availability Settings panel + overlay styles. Depend on
	// gs-member-calendar so they load after the calendar controller. filemtime-
	// busted single-version idiom (file_exists-guarded so a missing asset degrades).
	$avail_css_path = GS_DIR . 'assets/availability-settings.css';
	$avail_js_path  = GS_DIR . 'assets/availability-settings.js';
	$avail_css_ver  = GS_VERSION . ( file_exists( $avail_css_path ) ? '.' . filemtime( $avail_css_path ) : '' );
	$avail_js_ver   = GS_VERSION . ( file_exists( $avail_js_path ) ? '.' . filemtime( $avail_js_path ) : '' );
	wp_enqueue_style( 'gs-availability-settings', GS_URL . 'assets/availability-settings.css', array( 'gs-member-calendar' ), $avail_css_ver );
	wp_enqueue_script( 'gs-availability-settings', GS_URL . 'assets/availability-settings.js', array( 'gs-member-calendar' ), $avail_js_ver, true );
	wp_localize_script( 'gs-availability-settings', 'gsAvailNonce', wp_create_nonce( 'wp_rest' ) );

	// Phase 29 Plan 02 — Schedule Meeting modal assets (BOOK-10 + MEET-01..04).
	// Loaded ONLY on the member's own calendar screen (bp_is_my_profile gate above
	// already enforces this). filemtime-busted single-version idiom; file_exists-
	// guarded so a missing asset on the PVC (.no-plugin-sync, Pitfall 1) degrades
	// to "no Schedule button" instead of a 404.
	$sched_js_path  = GS_DIR . 'assets/schedule-meeting.js';
	$sched_css_path = GS_DIR . 'assets/schedule-meeting.css';
	if ( file_exists( $sched_js_path ) ) {
		$sched_js_ver  = GS_VERSION . '.' . filemtime( $sched_js_path );
		$sched_css_ver = GS_VERSION . ( file_exists( $sched_css_path ) ? '.' . filemtime( $sched_css_path ) : '' );
		wp_enqueue_script(
			'gs-schedule-meeting',
			GS_URL . 'assets/schedule-meeting.js',
			array( 'gs-member-calendar' ),
			$sched_js_ver,
			true
		);
		wp_enqueue_style(
			'gs-schedule-meeting',
			GS_URL . 'assets/schedule-meeting.css',
			array( 'gs-member-calendar' ),
			$sched_css_ver
		);

		// Read named_durations from the calling user's booking_settings_json
		// (defensive — empty array if no row yet OR schema column missing).
		$gs_named_durations = array();
		if ( class_exists( 'Gend_GS_Availability_Schema' ) && function_exists( 'get_current_user_id' ) ) {
			global $wpdb;
			$gs_tbl = Gend_GS_Availability_Schema::table_availability();
			// SHOW COLUMNS gracefully degrades if booking_settings_json column
			// hasn't been installed on this blog yet (29-05 runbook adds it).
			$gs_has_col = (bool) $wpdb->get_var( $wpdb->prepare(
				"SHOW COLUMNS FROM {$gs_tbl} LIKE %s",
				'booking_settings_json'
			) );
			if ( $gs_has_col ) {
				$gs_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT booking_settings_json FROM {$gs_tbl} WHERE user_id = %d",
					get_current_user_id()
				) );
				if ( $gs_row && ! empty( $gs_row->booking_settings_json ) ) {
					$gs_settings = (array) json_decode( $gs_row->booking_settings_json, true );
					if ( ! empty( $gs_settings['named_durations'] ) && is_array( $gs_settings['named_durations'] ) ) {
						$gs_named_durations = $gs_settings['named_durations'];
					}
				}
			}
		}

		wp_localize_script( 'gs-schedule-meeting', 'gsScheduleData', array(
			'restUrl'        => esc_url_raw( rest_url( 'gs/v1/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'namedDurations' => $gs_named_durations,
		) );
	}

	// Phase 30 Plan 03 — Native gend video embed assets (VID-04 + VID-05).
	// Loaded ONLY on the member's own calendar screen (the bp_is_my_profile gate
	// above already enforces this). filemtime-busted single-version idiom;
	// file_exists-guarded so a missing asset on the PVC (.no-plugin-sync,
	// Pitfall 1) degrades to "no Join button" instead of a 404. The embed
	// controller (gs-jitsi-embed) is event-delegated on [data-gs-jitsi-join]
	// so the Phase 26 event-detail popover, Phase 29 confirmation, and the
	// wallet meeting feed can all open the glassmorphic embed by rendering a
	// button carrying that attribute.
	$gs_jitsi_js_path  = GS_DIR . 'assets/jitsi-embed.js';
	$gs_jitsi_css_path = GS_DIR . 'assets/jitsi-embed.css';
	if ( file_exists( $gs_jitsi_js_path ) && file_exists( $gs_jitsi_css_path ) ) {
		$gs_jitsi_js_ver  = GS_VERSION . '.' . filemtime( $gs_jitsi_js_path );
		$gs_jitsi_css_ver = GS_VERSION . '.' . filemtime( $gs_jitsi_css_path );
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
		// Embed controller reads window.gsJitsiData.{restUrl,nonce,domain}.
		// domain is filterable so a staging cluster can point at a different
		// meet host; defaults to meet.gend.me (Plan 30-01 ingress hostname).
		wp_localize_script( 'gs-jitsi-embed', 'gsJitsiData', array(
			'restUrl' => esc_url_raw( rest_url( 'gs/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'domain'  => apply_filters( 'gs_jitsi_domain', 'meet.gend.me' ),
		) );

		// Join-button template for the JS-rendered event-detail popover.
		// member-calendar.js builds the popover client-side; it reads this
		// template and, for meeting events where meeting_type === 'video' AND
		// meeting_meta.provider === 'gend', injects a button carrying the
		// data-gs-jitsi-join="{meeting_id}" attribute the embed controller
		// delegates on. The {{meeting_id}} token is replaced with the event's
		// numeric id at render time (the renderer casts it via parseInt).
		echo '<template id="gs-jitsi-join-tpl">'
			. '<button type="button" class="gs-jitsi-join-btn" data-gs-jitsi-join="{{meeting_id}}">'
			. esc_html__( 'Join native video meeting', 'gend-society' )
			. '</button>'
			. '</template>';
	}

	// Resolve the member's IANA timezone (Plan 28-02 helper — availability row
	// first, 60s wp_cache, site-tz fallback). Single source of truth for data-tz
	// so the renderer relabels times in the member's own zone (AVAIL-03).
	$gs_member_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
		? Gend_GS_Calendar_Events_REST::get_member_timezone( get_current_user_id() )
		: ( wp_timezone_string() ?: 'UTC' );

	// Calendar root. 26-02's JS mounts the grid into #gs-calendar-app and reads
	// data-tz (now the member's IANA tz per Plan 28-02/28-03). The grid is
	// JS-rendered — do NOT render it in PHP. The #gs-avail-settings sibling is the
	// Phase 28-03 settings-panel mount point.
	// ── On-calendar "New Meeting" action bar. Rendered in PHP OUTSIDE
	// #gs-calendar-app (the JS owns that subtree and re-renders it, which would
	// wipe a child button). The [data-gs-schedule-open] hook is handled by a
	// document-level click delegate in schedule-meeting.js, opening the same
	// modal as the profile-icon trigger. file_exists-guard the schedule asset so
	// the button only shows when the modal script is actually deployed.
	if ( file_exists( GS_DIR . 'assets/schedule-meeting.js' ) ) {
		echo '<style>
			.gs-cal-actionbar{display:flex;justify-content:flex-end;margin:0 0 14px;}
			.gs-cal-newbtn{display:inline-flex;align-items:center;gap:8px;cursor:pointer;
				padding:10px 18px;border-radius:12px;border:1px solid rgba(182,8,201,.5);
				background:linear-gradient(135deg,rgba(182,8,201,.22),rgba(182,8,201,.06));
				color:#fff;font:700 13px/1 system-ui,sans-serif;letter-spacing:.04em;
				text-transform:uppercase;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
				box-shadow:0 6px 18px -8px rgba(182,8,201,.6);transition:transform .15s ease,box-shadow .15s ease,background .15s ease;}
			.gs-cal-newbtn:hover{transform:translateY(-1px);background:linear-gradient(135deg,rgba(182,8,201,.35),rgba(182,8,201,.12));box-shadow:0 10px 26px -8px rgba(182,8,201,.8);}
			.gs-cal-newbtn span{font-size:16px;line-height:1;}
		</style>';
		echo '<div class="gs-cal-actionbar">'
			. '<button type="button" class="gs-cal-newbtn" data-gs-schedule-open="1">'
			. '<span aria-hidden="true">＋</span> New Meeting</button>'
			. '</div>';
	}

	echo '<div id="gs-calendar-app" class="gs-cal-root" data-tz="' . esc_attr( $gs_member_tz ) . '"></div>';

	// Settings/Visibility panel mount — a SIBLING of #gs-calendar-app, NOT a
	// child. The calendar JS owns #gs-calendar-app and clears/re-renders it on
	// every view change, which would destroy a child mount (this is exactly why
	// the availability + visibility panel never appeared). availability-settings.js
	// fills this node; it works regardless of placement.
	echo '<div id="gs-avail-settings" class="gs-avail-settings-mount"></div>';

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
