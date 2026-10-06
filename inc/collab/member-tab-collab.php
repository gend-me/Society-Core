<?php
/**
 * gend-society — GenD Match member-profile "Match" tab (Phase 102-01, v12.0).
 *
 * Registers the NEW member-profile entry point for GenD Match — the primary,
 * additive relocation target this phase exists to build (102-RESEARCH.md).
 * Resolves "which of the viewing member's own businesses (BuddyPress groups)
 * they administer or moderate," offers a picker when 2+, auto-selects when
 * exactly 1, shows a clear empty state when 0, and delegates ALL actual
 * rendering (asset enqueue + deck/matches markup) to the already-existing,
 * now-reusable GS_Group_Tab_Collab::render_panel( $group_id ) static method
 * (extracted in group-tab-collab.php, this same plan).
 *
 * Two house pitfalls this file threads (102-RESEARCH.md, cited by name):
 *
 * Pitfall 1 — gs_group_tabs_user_has_access() (group-app-tabs.php) hard-
 * requires bp_is_group() and is therefore ALWAYS false on a member-profile
 * page. It is NOT reused here. This file's access gate is a small, plain,
 * context-free helper, gs_collab_profile_can_act_for_group(), replicating
 * class-collab-rest.php's own can_act_for_group() predicate verbatim
 * (super-admin OR group-admin OR group-mod, both two-arg / context-free) —
 * the SAME rule the REST layer already enforces for every deck/match/
 * contract call, so the PHP-side picker/gate can never disagree with it.
 *
 * Pitfall 2 — a member profile has no implicit group_id the way a group page
 * has bp_get_current_group_id(). Every downstream REST route requires one.
 * This file resolves "which group(s) can this member act as" (0 / 1 / 2+)
 * and persists the 2+ case's selection via a `?business=` query param AND a
 * localStorage fallback, so a plain revisit (no query param) restores the
 * member's last choice instead of silently resetting to the first business.
 *
 * All BP interaction happens on bp_setup_nav (priority 100), never at
 * file-include time — gend-society loads alphabetically before
 * social-network (where BP ships), so touching BP at include time fatals
 * (house Pitfall 19). Every BP call below is function_exists-guarded.
 *
 * The `collab` slug registered here lives in BP's MEMBER primary-nav
 * namespace (/members/{user}/collab/) — a completely separate URL/
 * registration namespace from the group tab's BP_Group_Extension slug,
 * also `collab` (/groups/{group}/collab/, group-tab-collab.php). No
 * collision: BP keys member nav items and group-extension nav items in
 * different registries.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the "Match" primary-nav tab on BuddyPress member profiles.
 *
 * Own-profile-only (show_for_displayed_user = false), matching
 * member-markets.php's privacy model — only the viewing member's own
 * businesses should swipe/manage matches from their profile. Hooked at
 * bp_setup_nav priority 100 so it runs after BP core/Youzify have registered
 * their primary nav items (lets us read the live `groups` position).
 */
// RETIRED (2026-08-16) -- Match moved again: from the member profile to a
// per-group popup launcher on each group's Payments tab
// (inc/collab/group-payments-match-launcher.php). The profile nav item is no
// longer registered, which also retires the /members/{user}/collab/ URL and
// its "Acting as" business picker -- the group context is now implicit (the
// payments page's own group). The functions below are kept only because
// gs_collab_profile_can_act_for_group() is reused by the launcher's gate.
// add_action( 'bp_setup_nav', 'gs_add_collab_profile_tab', 100 );
function gs_add_collab_profile_tab() {
	if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
		return;
	}

	// Compute the position relative to the live `groups` nav item — do NOT
	// hardcode. member-calendar.php uses groups+1, member-markets.php uses
	// groups+2; +3 avoids colliding with either.
	$pos = 19; // safe default = BP groups(16) + 3
	if ( function_exists( 'buddypress' ) && isset( buddypress()->members->nav ) ) {
		foreach ( buddypress()->members->nav->get_primary() as $item ) {
			if ( ( $item['slug'] ?? '' ) === 'groups' ) {
				$pos = (int) $item['position'] + 3;
				break;
			}
		}
	}

	bp_core_new_nav_item( array(
		'name'                    => __( 'Match', 'gend-society' ),
		'slug'                    => 'collab',
		'screen_function'         => 'gs_collab_profile_screen',
		'position'                => $pos,
		'item_css_id'             => 'collab-profile',
		// Own-profile-only surface: only the viewing member's own businesses
		// should swipe/manage matches from their profile (mirrors
		// member-markets.php's privacy model).
		'show_for_displayed_user' => false,
	) );
}

/**
 * Screen callback for the "Match" profile tab — loads the BP plugins
 * template and routes its content to our handler. Mirrors
 * gs_markets_profile_screen() exactly.
 */
function gs_collab_profile_screen() {
	if ( function_exists( 'add_action' ) ) {
		// render_panel() already emits its own "Match" <h3> — don't double it.
		add_action( 'bp_template_title', '__return_empty_string' );
		add_action( 'bp_template_content', 'gs_collab_profile_screen_content' );
	}
	if ( function_exists( 'bp_core_load_template' ) ) {
		bp_core_load_template( 'members/single/plugins' );
	}
}

/**
 * Context-free admin/mod/super-admin gate — replicates
 * class-collab-rest.php's can_act_for_group() predicate verbatim (the SAME
 * rule the REST layer already enforces for every deck/match/contract call).
 * Not a REST permission_callback; a small plain PHP helper for this screen's
 * own eligible-groups resolution and picker gate.
 *
 * @param int $uid Member (user) id.
 * @param int $gid Group id being checked.
 * @return bool
 */
function gs_collab_profile_can_act_for_group( $uid, $gid ) {
	$uid = (int) $uid;
	$gid = (int) $gid;
	if ( $uid <= 0 || $gid <= 0 ) {
		return false;
	}
	if ( function_exists( 'is_super_admin' ) && is_super_admin( $uid ) ) {
		return true;
	}
	return ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $uid, $gid ) )
		|| ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( $uid, $gid ) );
}

/**
 * Render the "Match" profile pane — resolves the viewing member's eligible
 * businesses (groups they administer or moderate), handles the 0/1/2+
 * eligible-group cases, resolves+persists the selected business, then
 * delegates ALL actual deck/matches rendering to
 * GS_Group_Tab_Collab::render_panel( $selected_group_id ).
 */
function gs_collab_profile_screen_content() {
	// Privacy: own-profile-only (defense-in-depth — show_for_displayed_user
	// => false already prevents BP from linking this tab on other profiles).
	if ( function_exists( 'bp_is_my_profile' ) && ! bp_is_my_profile() ) {
		echo '<p style="color:rgba(203,213,245,0.75);">' . esc_html__( 'This view is private.', 'gend-society' ) . '</p>';
		return;
	}

	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

	// Resolve eligible groups: admin OR mod OR super-admin (mirrors
	// can_act_for_group() — Pitfall 1 discipline).
	$eligible = array(); // group_id => group_name
	$gids     = array();
	if ( function_exists( 'groups_get_user_groups' ) && $uid > 0 ) {
		$ug = groups_get_user_groups( $uid );
		if ( is_array( $ug ) && ! empty( $ug['groups'] ) && is_array( $ug['groups'] ) ) {
			$gids = array_map( 'intval', $ug['groups'] );
		}
	}
	foreach ( array_unique( $gids ) as $gid ) {
		if ( ! gs_collab_profile_can_act_for_group( $uid, $gid ) ) {
			continue;
		}
		$name = '';
		if ( function_exists( 'groups_get_group' ) ) {
			$g = groups_get_group( $gid );
			if ( $g && function_exists( 'bp_get_group_name' ) ) {
				$name = bp_get_group_name( $g );
			} elseif ( $g && isset( $g->name ) ) {
				$name = $g->name;
			}
		}
		$eligible[ $gid ] = ( '' !== $name ) ? $name : sprintf( __( 'Business #%d', 'gend-society' ), $gid );
	}

	// 0 eligible groups: clear empty state, no deck mount, no REST calls,
	// no PHP warnings.
	if ( empty( $eligible ) ) {
		echo '<div class="gs-collab-panel gs-collab-empty-profile" style="padding:18px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08);">'
			. '<p style="margin:0; color:#fff; font-weight:600;">' . esc_html__( 'Match', 'gend-society' ) . '</p>'
			. '<p style="margin:8px 0 0; color:#cbd5f5; font-size:0.9rem;">' . esc_html__( "You don't administer or moderate any businesses yet. Join or create a group to use Match.", 'gend-society' ) . '</p>'
			. '</div>';
		return;
	}

	// Resolve $selected_group_id from ?business=, validated against
	// $eligible (this validation IS the access gate for the selected value
	// — never trust the raw query param). Fall back to the first eligible
	// group if absent/invalid.
	$eligible_ids       = array_keys( $eligible );
	$requested          = isset( $_GET['business'] ) ? absint( wp_unslash( $_GET['business'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$had_business_param = isset( $_GET['business'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$selected_group_id  = ( $requested > 0 && in_array( $requested, $eligible_ids, true ) ) ? $requested : (int) $eligible_ids[0];

	if ( 1 === count( $eligible ) ) {
		// Exactly 1 eligible group: no picker UI, small "Acting as" label
		// (the group-tab version had implicit context from being inside
		// that group's own nav; the profile tab does not).
		echo '<p class="gs-collab-acting-as" style="margin:0 0 10px; color:#9fb6e6; font-size:0.85rem;">'
			/* translators: %s is the business (group) name the member is acting as. */
			. sprintf( esc_html__( 'Acting as: %s', 'gend-society' ), '<strong style="color:#fff;">' . esc_html( $eligible[ $selected_group_id ] ) . '</strong>' )
			. '</p>';
	} else {
		// 2+ eligible groups: picker + persisted selection (query param +
		// localStorage fallback — Pitfall 2 discipline: "persist so refresh
		// doesn't reset it").
		echo '<div class="gs-collab-business-picker-wrap" style="margin:0 0 14px; display:flex; align-items:center; gap:8px;">'
			. '<label for="gs-collab-business-picker" style="color:#9fb6e6; font-size:0.85rem;">' . esc_html__( 'Acting as:', 'gend-society' ) . '</label>'
			. '<select id="gs-collab-business-picker" style="background:rgba(255,255,255,0.06); color:#fff; border:1px solid rgba(255,255,255,0.15); border-radius:6px; padding:6px 10px;">';
		foreach ( $eligible as $gid => $name ) {
			echo '<option value="' . esc_attr( $gid ) . '" ' . selected( $selected_group_id, $gid, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></div>';

		$had_param_js = $had_business_param ? 'true' : 'false';
		?>
		<script>
		( function () {
			var STORAGE_KEY = 'gs_collab_last_business';
			var hadParam    = <?php echo $had_param_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal true/false only ?>;
			var picker      = document.getElementById( 'gs-collab-business-picker' );

			// On load: if no ?business= param was present, check localStorage
			// for a remembered choice and redirect ONCE (guarded by hadParam
			// so this never loops — after the redirect, the param IS present
			// and this branch is skipped, avoiding a redirect loop).
			if ( ! hadParam ) {
				try {
					var remembered = window.localStorage ? window.localStorage.getItem( STORAGE_KEY ) : null;
					if ( remembered ) {
						var initParams = new URLSearchParams( window.location.search );
						initParams.set( 'business', remembered );
						window.location.search = initParams.toString();
						return;
					}
				} catch ( e ) {
					// localStorage unavailable — ignore, first eligible business already selected server-side.
				}
			}

			if ( picker ) {
				picker.addEventListener( 'change', function () {
					var val = picker.value;
					try {
						if ( window.localStorage ) {
							window.localStorage.setItem( STORAGE_KEY, val );
						}
					} catch ( e ) {
						// ignore — query param still drives the reload below.
					}
					var changeParams = new URLSearchParams( window.location.search );
					changeParams.set( 'business', val );
					window.location.search = changeParams.toString();
				} );
			}
		} )();
		</script>
		<?php
	}

	// Delegate ALL actual rendering (asset enqueue + deck/matches markup) to
	// the shared, already-authorized-for-this-group_id render body. Guarded
	// fallback for a partial/out-of-order deploy.
	if ( class_exists( 'GS_Group_Tab_Collab' ) && method_exists( 'GS_Group_Tab_Collab', 'render_panel' ) ) {
		GS_Group_Tab_Collab::render_panel( $selected_group_id );
	} else {
		echo '<p style="color:rgba(203,213,245,0.75);">' . esc_html__( 'GenD Match is temporarily unavailable.', 'gend-society' ) . '</p>';
	}
}
