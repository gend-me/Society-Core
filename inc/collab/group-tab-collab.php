<?php
/**
 * gend-society — GenD Match swipe-deck group tab (Phase 82-03, v12.0).
 *
 * Mounts the collab swipe deck as a "Match" tab inside every business
 * group's BuddyPress nav, gated to the group's admins/mods (or super-admin),
 * and renders — inline at the top of the tab — the opt-in switch + the
 * category/industry/location tag editor (TAG-01). The tab also emits the
 * deck-mount container and enqueues the vanilla-JS Pointer-Events deck
 * assets (assets/collab-swipe.{js,css} — BUILT IN PLAN 82-04), localizing
 * the REST root + nonce + group id via wp_localize_script('gsCollabData').
 *
 * House pitfall this file threads: BP_Group_Extension MUST be registered on
 * bp_init at priority < 11 — bp_init_group_extensions() runs at bp_init
 * priority 11 and instantiates the already-registered classes; anything
 * registered at >= 11 is SILENTLY skipped and the tab never appears (no
 * error). This file registers at priority 10 (see the bottom of the file).
 *
 * The display() gate reuses gs_group_tabs_user_has_access() from
 * group-app-tabs.php (loaded BEFORE this file in the bp_include block), so
 * NO role SQL is hand-rolled here — the gate + the gs/v1 REST
 * permission_callback (Plan 82-02) own authorization.
 *
 * Phase 102-01 note: the asset-enqueue + markup body that used to live inline
 * inside display() has been extracted to a reusable public static method,
 * render_panel( $group_id ), which takes an ALREADY-resolved,
 * ALREADY-authorized group id and does no gating of its own. display() is now
 * just the group-tab-specific access gate + $group_id resolution, ending in
 * self::render_panel( $group_id ) — this is why display() is short. The new
 * member-profile "Match" tab (inc/collab/member-tab-collab.php) calls
 * render_panel() directly with its own resolved+authorized group id, reusing
 * 100% of this markup without going through display()'s group-tab-only gate.
 *
 * Consumed by Plan 82-04's collab-swipe.js — the stable DOM contract is:
 *   #gs-collab-deck[data-group-id]          the card-stack mount
 *   #gs-collab-tag-form                       the inline tag/opt-in editor
 *     [data-gs-collab-category]  <select>
 *     [data-gs-collab-industry]  <select>
 *     [data-gs-collab-location]  <input>
 *     [data-gs-collab-optin]     <input type=checkbox>
 *     [data-gs-collab-save]      <button>
 *   [data-gs-collab-pass] / [data-gs-collab-like]   on-screen swipe buttons
 *   .gs-collab-empty[hidden]                 SWIPE-06 empty-state node
 * The localized object is window.gsCollabData = { restUrl, nonce, groupId }.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BP_Group_Extension' ) ) :

	/**
	 * GenD Match — the swipe-deck group tab (TAG-01 editor + deck mount).
	 */
	class GS_Group_Tab_Collab extends BP_Group_Extension {

		public function __construct() {
			parent::init( array(
				'slug'              => 'collab',
				'name'              => __( 'Match', 'gend-society' ),
				// After Compute Gas (80) / before Davinci AI (200) — mirrors
				// the group-app-tabs.php nav-position convention. nav_item_name
				// + display_hook + template_file are what BP_Group_Extension
				// keys on; without them the nav item is silently skipped on
				// some BP builds (same note as GS_Group_Tab_Feature_Suite).
				'nav_item_position' => 60,
				'show_tab'          => 'anyone',
				'nav_item_name'     => __( 'Match', 'gend-society' ),
				'display_hook'      => 'groups_custom_group_boxes',
				'template_file'     => 'groups/single/plugins',
			) );
		}

		/**
		 * Render the Match tab: access gate → resolve $group_id → delegate
		 * to render_panel() for the actual assets + markup (Phase 102-01
		 * extract-method refactor — see the file docblock).
		 *
		 * @param int|null $group_id Current group id (BP passes it).
		 */
		public function display( $group_id = null ) {
			// Only the group's admins/mods (or super-admin) swipe on the
			// group's behalf — locked decision. Reuse the existing gate.
			if ( ! function_exists( 'gs_group_tabs_user_has_access' ) || ! gs_group_tabs_user_has_access() ) {
				echo '<p style="color:rgba(203,213,245,0.75);">' . esc_html__( 'Only group admins can use Match.', 'gend-society' ) . '</p>';
				return;
			}

			$group_id = $group_id ? (int) $group_id : (int) bp_get_current_group_id();

			// 2026-08-16 -- Match now lives in the "Get Matched With Business
			// Partners" popup on this group's Payments tab
			// (inc/collab/group-payments-match-launcher.php). This URL/tab
			// stays fully functional as a direct-URL safety net (mid-flight
			// contract/proposal state) but remains hard-hidden from the group
			// nav (see the bp_group_extension_nav_show_for_user filter below).
			$gs_collab_payments_url = function_exists( 'bp_get_group_permalink' ) && function_exists( 'groups_get_current_group' ) && groups_get_current_group()
				? trailingslashit( bp_get_group_permalink( groups_get_current_group() ) ) . 'payments/'
				: '';
			echo '<div class="gs-collab-moved-notice" style="margin-bottom:16px; padding:12px 16px; border-radius:8px; background:rgba(78,170,255,0.12); border:1px solid rgba(78,170,255,0.35); color:#fff; font-size:0.88rem;">'
				. esc_html__( 'GenD Match has moved — find it via the "Get Matched With Business Partners" button at the top of this group\'s Payments page.', 'gend-society' )
				. ( $gs_collab_payments_url ? ' <a href="' . esc_url( $gs_collab_payments_url ) . '" style="color:#9fd0ff;">' . esc_html__( 'Open Payments', 'gend-society' ) . '</a>' : '' )
				. '</div>';

			self::render_panel( $group_id );
		}

		/**
		 * Reusable render body — asset enqueue + full panel markup — for an
		 * ALREADY-resolved, ALREADY-authorized $group_id. Callers own
		 * access-gating and $group_id resolution; this method does neither
		 * (it does not call gs_group_tabs_user_has_access() and does not call
		 * bp_get_current_group_id()), so it is safe to call from any context
		 * that has already authorized the caller for this specific group,
		 * including a member-profile page with no ambient group context
		 * (Phase 102-01, inc/collab/member-tab-collab.php).
		 *
		 * @param int $group_id Already-resolved, already-authorized group id.
		 */
		public static function render_panel( $group_id ) {
			$group_id = (int) $group_id;

			// Enqueue the deck assets with the GS_VERSION.'.'.filemtime()
			// idiom (defeats the stale-asset pitfall). filemtime() is
			// file_exists-guarded so a not-yet-deployed Plan-82-04 asset can
			// never fatal display() — the tab still renders (empty deck).
			$js_path  = GS_DIR . 'assets/collab-swipe.js';
			$css_path = GS_DIR . 'assets/collab-swipe.css';
			$js_ver   = file_exists( $js_path ) ? GS_VERSION . '.' . filemtime( $js_path ) : GS_VERSION;
			$css_ver  = file_exists( $css_path ) ? GS_VERSION . '.' . filemtime( $css_path ) : GS_VERSION;

			wp_enqueue_script( 'gs-collab-swipe', GS_URL . 'assets/collab-swipe.js', array(), $js_ver, true );
			wp_enqueue_style( 'gs-collab-swipe', GS_URL . 'assets/collab-swipe.css', array(), $css_ver );
			wp_localize_script( 'gs-collab-swipe', 'gsCollabData', array(
				'restUrl' => esc_url_raw( rest_url( 'gs/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'groupId' => (int) $group_id,
			) );

			// Matches-management controller (list matches + conversation + contract
			// propose/accept/manage). Depends on gs-collab-swipe so window.gsCollabData
			// (localized above) is available. file_exists-guarded ver like the deck.
			$m_js_path = GS_DIR . 'assets/collab-matches.js';
			$m_js_ver  = file_exists( $m_js_path ) ? GS_VERSION . '.' . filemtime( $m_js_path ) : GS_VERSION;
			wp_enqueue_script( 'gs-collab-matches', GS_URL . 'assets/collab-matches.js', array( 'gs-collab-swipe' ), $m_js_ver, true );

			// Server-side pre-population (best-effort). The REST route
			// (Plan 82-02) is the source of truth for validation + the
			// opt-in gate; these values just avoid an empty-form flash
			// before the Plan-82-04 JS hydrates from GET /collab/tags.
			$cur_category = '';
			$cur_industry = '';
			$cur_location = '';
			$cur_optin    = false;
			if ( function_exists( 'groups_get_groupmeta' ) ) {
				$cur_category = (string) groups_get_groupmeta( $group_id, '_gs_collab_category', true );
				$cur_industry = (string) groups_get_groupmeta( $group_id, '_gs_collab_industry', true );
				$cur_location = (string) groups_get_groupmeta( $group_id, '_gs_collab_location', true );
				$cur_optin    = ( '1' === (string) groups_get_groupmeta( $group_id, '_gs_collab_optin', true ) );
			}

			$categories = class_exists( 'Gend_GS_Collab_Taxonomy' ) ? Gend_GS_Collab_Taxonomy::CATEGORIES : array();
			$industries = class_exists( 'Gend_GS_Collab_Taxonomy' ) ? Gend_GS_Collab_Taxonomy::INDUSTRIES : array();
			?>
			<div class="gs-group-tab-panel gs-collab-panel">

				<div class="gs-collab-header" style="margin-bottom:16px;">
					<h3 style="margin:0 0 4px; color:#fff; font-size:1.15rem;"><?php echo esc_html__( 'Match', 'gend-society' ); ?></h3>
					<p style="margin:0; color:#cbd5f5; font-size:0.88rem;"><?php echo esc_html__( 'Set your collaboration tags, open your business to collaboration, then swipe through complementary businesses.', 'gend-society' ); ?></p>
				</div>

				<!-- TAG-01: inline opt-in + tag editor. Plan-82-04 JS hydrates
				     current values from GET gs/v1/collab/tags and POSTs on Save;
				     these fields are pre-populated best-effort from groupmeta. -->
				<form id="gs-collab-tag-form" class="gs-collab-tag-form" onsubmit="return false;" style="margin-bottom:20px;">
					<div class="gs-collab-tag-grid" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
						<label style="display:flex; flex-direction:column; gap:4px; color:#cbd5f5; font-size:0.82rem;">
							<span><?php echo esc_html__( 'Category', 'gend-society' ); ?></span>
							<select data-gs-collab-category style="min-width:180px;">
								<option value=""><?php echo esc_html__( '— Select category —', 'gend-society' ); ?></option>
								<?php foreach ( $categories as $c_key => $c_label ) : ?>
									<option value="<?php echo esc_attr( $c_key ); ?>" <?php selected( $cur_category, $c_key ); ?>><?php echo esc_html( $c_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label style="display:flex; flex-direction:column; gap:4px; color:#cbd5f5; font-size:0.82rem;">
							<span><?php echo esc_html__( 'Industry', 'gend-society' ); ?></span>
							<select data-gs-collab-industry style="min-width:180px;">
								<option value=""><?php echo esc_html__( '— Select industry —', 'gend-society' ); ?></option>
								<?php foreach ( $industries as $i_key => $i_label ) : ?>
									<option value="<?php echo esc_attr( $i_key ); ?>" <?php selected( $cur_industry, $i_key ); ?>><?php echo esc_html( $i_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label style="display:flex; flex-direction:column; gap:4px; color:#cbd5f5; font-size:0.82rem;">
							<span><?php echo esc_html__( 'Location', 'gend-society' ); ?></span>
							<input type="text" data-gs-collab-location value="<?php echo esc_attr( $cur_location ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Toronto', 'gend-society' ); ?>" style="min-width:180px;">
						</label>
					</div>

					<label class="gs-collab-optin" style="display:flex; align-items:center; gap:8px; color:#fff; font-size:0.9rem; margin-top:14px;">
						<input type="checkbox" data-gs-collab-optin <?php checked( $cur_optin ); ?>>
						<span><?php echo esc_html__( 'Open to collaboration (discoverable in other businesses’ decks). Requires a category and industry.', 'gend-society' ); ?></span>
					</label>

					<div style="margin-top:14px;">
						<button type="submit" data-gs-collab-save class="gs-collab-save" style="background:rgba(78,170,255,0.18); border:1px solid rgba(78,170,255,0.45); color:#fff; padding:8px 18px; border-radius:8px; font-weight:600; font-size:0.85rem; cursor:pointer;"><?php echo esc_html__( 'Save tags', 'gend-society' ); ?></button>
						<span class="gs-collab-tag-status" data-gs-collab-status role="status" aria-live="polite" style="margin-left:10px; color:#9fb6e6; font-size:0.82rem;"></span>
					</div>
				</form>

				<!-- View toggle: Discover (swipe deck) | Matches (manage matches + contracts). -->
				<div class="gs-collab-viewnav" role="tablist">
					<button type="button" class="gs-collab-viewbtn is-active" data-gs-view="deck" role="tab" aria-selected="true"><?php echo esc_html__( 'Discover', 'gend-society' ); ?></button>
					<button type="button" class="gs-collab-viewbtn" data-gs-view="matches" role="tab" aria-selected="false"><?php echo esc_html__( 'Matches', 'gend-society' ); ?> <span class="gs-collab-match-count" data-gs-collab-match-count hidden></span></button>
				</div>

				<!-- DECK view. Plan-82-04 JS renders cards into #gs-collab-deck. -->
				<div class="gs-collab-view" data-gs-view-panel="deck">
					<div class="gs-collab-deck-wrap">
						<div id="gs-collab-deck" data-group-id="<?php echo esc_attr( $group_id ); ?>"></div>

						<div class="gs-collab-empty" hidden>
							<p style="color:#fff; text-align:center; margin:6px 0 8px; font-weight:600;"><?php echo esc_html__( 'No businesses to review yet.', 'gend-society' ); ?></p>
							<p style="color:#cbd5f5; text-align:center; margin:0; font-size:0.88rem; line-height:1.5;"><?php echo esc_html__( 'A business appears here once it sets its collaboration tags and opens to collaboration (the switch above). As more businesses join, they’ll show up in your deck — check back soon.', 'gend-society' ); ?></p>
						</div>

						<div class="gs-collab-controls" style="display:flex; justify-content:center; gap:18px; margin-top:16px;">
							<button type="button" data-gs-collab-pass class="gs-collab-btn gs-collab-pass" aria-label="<?php echo esc_attr__( 'Pass', 'gend-society' ); ?>" style="padding:10px 22px; border-radius:10px; border:1px solid rgba(255,120,120,0.5); background:rgba(255,90,90,0.14); color:#fff; font-weight:600; cursor:pointer;"><?php echo esc_html__( 'Pass', 'gend-society' ); ?></button>
							<button type="button" data-gs-collab-like class="gs-collab-btn gs-collab-like" aria-label="<?php echo esc_attr__( 'Interested', 'gend-society' ); ?>" style="padding:10px 22px; border-radius:10px; border:1px solid rgba(120,255,170,0.5); background:rgba(90,220,150,0.16); color:#fff; font-weight:600; cursor:pointer;"><?php echo esc_html__( 'Interested', 'gend-society' ); ?></button>
							<button type="button" data-gs-collab-undo class="gs-collab-btn gs-collab-undo" disabled aria-label="<?php echo esc_attr__( 'Undo last swipe', 'gend-society' ); ?>" style="padding:10px 22px; border-radius:10px; border:1px solid rgba(160,170,255,0.45); background:rgba(120,130,255,0.14); color:#fff; font-weight:600; cursor:pointer;"><?php echo esc_html__( 'Undo', 'gend-society' ); ?></button>
						</div>
					</div>
				</div>

				<!-- MATCHES view. collab-matches.js fills #gs-collab-matches from GET /collab/matches. -->
				<div class="gs-collab-view" data-gs-view-panel="matches" hidden>
					<div id="gs-collab-matches" data-group-id="<?php echo esc_attr( $group_id ); ?>"></div>
				</div>

			</div>
			<?php
		}
	}

endif; // class_exists( 'BP_Group_Extension' )

/**
 * Register the Match tab at bp_init priority 10.
 *
 * Priority MUST be < 11 — bp_init_group_extensions() runs at bp_init
 * priority 11 and iterates the already-registered extensions; a class
 * registered at >= 11 is SILENTLY skipped (the tab never appears, no error).
 * This mirrors gs_register_group_app_tabs (group-app-tabs.php:1043) and every
 * PSOO_*_Group_Extension. Kept self-contained (own add_action) so this file
 * is independent of the group-app-tabs.php registration.
 */
add_action( 'bp_init', function () {
	if ( ! class_exists( 'BP_Group_Extension' ) ) {
		return;
	}
	if ( ! function_exists( 'bp_register_group_extension' ) ) {
		return;
	}
	bp_register_group_extension( 'GS_Group_Tab_Collab' );
}, 10 );

/**
 * Show the Match tab in the native BP nav only for group/site admins —
 * BP hides custom tabs from non-admins by default; flip it ON only when
 * our access gate passes. Priority 99 so BP's own logic runs first (mirrors
 * gs_group_app_tabs_nav_visibility).
 */
add_filter( 'bp_group_extension_nav_show_for_user', function ( $show, $slug, $group_id ) {
	if ( 'collab' !== $slug ) {
		return $show;
	}
	// Phase 102 -- Match relocated to the member profile (member-tab-collab.php,
	// Plan 102-01). The group-tab BP_Group_Extension registration stays in place
	// as a safety net (direct-URL reachability for any mid-flight contract/
	// proposal state -- 102-RESEARCH.md Open Question 1) but is now ALWAYS
	// hidden from the group nav, regardless of any admin's prior Group Styling
	// override -- confirmed via a live pre-check (Plan 102-02) that no group had
	// an active override at retirement time. No group can re-surface it going
	// forward (removed from gdc_gs_full_nav_manageable_slugs() below).
	return false;
}, 99, 3 );
