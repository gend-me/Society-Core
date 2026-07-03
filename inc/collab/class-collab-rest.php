<?php
/**
 * gend-society — GenD Match gs/v1 REST surface (Phase 82-02, v12.0).
 *
 * The REST routes the swipe UI (Plans 82-03/04) call. Four routes under gs/v1:
 *   GET  /collab/deck   → route_deck    — the ranked, facet-filtered candidate deck.
 *   POST /collab/swipe  → route_swipe   — idempotent ledger write (SWIPE-03).
 *   GET  /collab/tags   → route_get_tags — the group's own tags + opt-in + enum labels.
 *   POST /collab/tags   → route_set_tags — TAG-01 write (opt-in gated on cat+ind).
 *
 * Mirrors inc/calendar-events-rest.php:36 exactly: `const NS = 'gs/v1'` + a static
 * register_routes(), bound by ONE add_action('rest_api_init', …) in the entrypoint
 * (per-class binding — the plugin's established pattern; these routes are NOT folded
 * into the calendar REST class).
 *
 * AUTHORIZATION (locked decision): only a group's admins/mods — or a super-admin —
 * may act for that group. The permission_callback (can_act_for_group) uses the
 * REST-SAFE two-arg role check groups_is_user_admin($uid,$gid) / groups_is_user_mod
 * (messages-tabs.php:3337). It DOES NOT use bp_group_is_admin() — that helper assumes
 * the current-group loop context, which is NOT set inside a REST request.
 *
 * No oauth allow-list entry is needed: every route is admin/mod-authed, so a
 * logged-out call correctly 401s (RESEARCH §oauth allow-list).
 *
 * TIER A / money-free: route_swipe records a swipe via the Plan 82-01 record_swipe()
 * (INSERT IGNORE, idempotent) and MUST NOT create any match row — gs_collab_matches
 * is populated by Phase 83, never here.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_REST {

	const NS = 'gs/v1';

	/**
	 * Register the four collab routes. Bound via one add_action('rest_api_init',
	 * [__CLASS__,'register_routes']) in the entrypoint.
	 */
	public static function register_routes() : void {
		// GET /collab/deck — ranked, facet-filtered candidate deck.
		register_rest_route( self::NS, '/collab/deck', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'route_deck' ),
			'permission_callback' => array( __CLASS__, 'can_act_for_group' ),
			'args'                => array(
				'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'category' => array( 'sanitize_callback' => 'sanitize_key' ),
				'industry' => array( 'sanitize_callback' => 'sanitize_key' ),
				'location' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'offset'   => array( 'sanitize_callback' => 'absint' ),
			),
		) );

		// POST /collab/swipe — idempotent ledger write.
		register_rest_route( self::NS, '/collab/swipe', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'route_swipe' ),
			'permission_callback' => array( __CLASS__, 'can_act_for_group' ),
			'args'                => array(
				'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'to_group' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'decision' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// POST /collab/undo — single-step undo of the actor group's last swipe (SWIPE-07).
		register_rest_route( self::NS, '/collab/undo', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'route_undo' ),
			'permission_callback' => array( __CLASS__, 'can_act_for_group' ), // reused verbatim
			'args'                => array(
				'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		// GET /collab/tags — the group's own tags + opt-in + enum labels.
		register_rest_route( self::NS, '/collab/tags', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_get_tags' ),
				'permission_callback' => array( __CLASS__, 'can_act_for_group' ),
				'args'                => array(
					'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			),
			// POST /collab/tags — TAG-01 write path (driven by Plan 03's UI; the
			// route lives here). Same permission_callback.
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_set_tags' ),
				'permission_callback' => array( __CLASS__, 'can_act_for_group' ),
				'args'                => array(
					'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'category' => array( 'sanitize_callback' => 'sanitize_key' ),
					'industry' => array( 'sanitize_callback' => 'sanitize_key' ),
					'location' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'optin'    => array( 'sanitize_callback' => 'absint' ),
				),
			),
		) );

		// ---------------------------------------------------------------
		// Phase 84 (COLLAB-01) — contract escalation. Three HUB-ONLY routes.
		// Tier A / PUBLIC (NOT GS_COLLAB_MARKET_PUBLIC-gated) but money-careful:
		// the accept path moves DGEN so hub-only is enforced in the engine
		// (is_main_node) AND in can_accept_contract. Auth model:
		//   propose/decline → can_act_for_group (act for your OWN group_id)
		//   accept          → can_accept_contract (must be admin/mod of the
		//                     COUNTERPARTY group; proposer self-accept rejected)
		// ---------------------------------------------------------------

		// POST /collab/match/{id}/contract/propose — store a PENDING proposal.
		register_rest_route( self::NS, '/collab/match/(?P<id>\d+)/contract/propose', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'route_contract_propose' ),
			'permission_callback' => array( __CLASS__, 'can_act_for_group' ),
			'args'                => array(
				'group_id'     => array( 'required' => true, 'sanitize_callback' => 'absint' ), // proposer_group
				'payer_group'  => array( 'sanitize_callback' => 'absint' ),
				'payee_group'  => array( 'sanitize_callback' => 'absint' ),
				'escrow_model' => array( 'sanitize_callback' => 'sanitize_key' ),
				'credits'      => array( 'sanitize_callback' => 'absint' ),
				'milestones'   => array( 'sanitize_callback' => array( __CLASS__, 'sanitize_milestones' ) ),
			),
		) );

		// POST /collab/match/{id}/contract/accept — counterparty accepts → contract + escrow.
		register_rest_route( self::NS, '/collab/match/(?P<id>\d+)/contract/accept', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'route_contract_accept' ),
			'permission_callback' => array( __CLASS__, 'can_accept_contract' ),
			'args'                => array(
				'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ), // acceptor's COUNTERPARTY group
			),
		) );

		// POST /collab/match/{id}/contract/decline — either side declines / proposer cancels.
		register_rest_route( self::NS, '/collab/match/(?P<id>\d+)/contract/decline', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'route_contract_decline' ),
			'permission_callback' => array( __CLASS__, 'can_act_for_group' ),
			'args'                => array(
				'group_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'mode'     => array( 'sanitize_callback' => 'sanitize_key' ), // 'declined' | 'cancelled'
			),
		) );
	}

	/**
	 * Sanitize the milestones REST arg: keep a JSON string as-is (the engine
	 * decode/re-encodes to validate); wp_kses_post any plain-text brief.
	 *
	 * @param mixed $value Raw milestones param.
	 * @return string
	 */
	public static function sanitize_milestones( $value ) : string {
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		$value = (string) $value;
		$trim  = trim( $value );
		// A JSON array/object → pass through untouched (engine validates).
		if ( '' !== $trim && ( '[' === $trim[0] || '{' === $trim[0] ) ) {
			return $value;
		}
		return wp_kses_post( $value );
	}

	/**
	 * REST-SAFE permission gate for ACCEPT: the acceptor must be an admin/mod (or
	 * super-admin) of the COUNTERPARTY group — the matched group that is NOT the
	 * proposal's proposer_group — AND must not be self-accepting. Hub-only: a
	 * container call is unauthorized (the money route never runs off the hub).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return bool
	 */
	public static function can_accept_contract( WP_REST_Request $req ) : bool {
		global $wpdb;

		$uid = (int) get_current_user_id();
		$gid = (int) $req->get_param( 'group_id' ); // the COUNTERPARTY group the acceptor represents
		if ( $uid <= 0 || $gid <= 0 ) {
			return false;
		}

		// Hub-only: DGEN/chain live on the hub; a container accept is unauthorized.
		$is_hub = ! class_exists( 'Gend_CP_OAuth_Resource' )
			|| ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
			|| Gend_CP_OAuth_Resource::is_main_node();
		if ( ! $is_hub ) {
			return false;
		}

		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return false;
		}

		$match_id = (int) $req->get_param( 'id' );
		if ( $match_id <= 0 ) {
			return false;
		}

		// Resolve the proposal's proposer_group + the match's two groups.
		$proposals      = Gend_GS_Collab_Schema::proposals_table();
		$proposer_group = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT proposer_group FROM {$proposals} WHERE match_id = %d AND prop_status = 'pending' LIMIT 1",
			$match_id
		) );
		if ( $proposer_group <= 0 ) {
			return false; // no pending proposal → nothing to accept
		}

		$matches = Gend_GS_Collab_Schema::matches_table();
		$match   = $wpdb->get_row( $wpdb->prepare(
			"SELECT group_a, group_b FROM {$matches} WHERE id = %d LIMIT 1", $match_id ) );
		if ( ! $match ) {
			return false;
		}
		$group_a = (int) $match->group_a;
		$group_b = (int) $match->group_b;

		// The acceptor's group MUST be the COUNTERPARTY (the matched group that is NOT
		// the proposer_group) — this rejects a proposer trying to self-accept.
		$counterparty = ( $proposer_group === $group_a ) ? $group_b : $group_a;
		if ( $gid !== $counterparty || $gid === $proposer_group ) {
			return false;
		}

		// Now the standard two-arg admin/mod check on the counterparty group.
		if ( function_exists( 'is_super_admin' ) && is_super_admin( $uid ) ) {
			return true;
		}
		return ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $uid, $gid ) )
			|| ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( $uid, $gid ) );
	}

	/**
	 * POST /collab/match/{id}/contract/propose — store a PENDING proposal (COLLAB-01).
	 * No contract, no project, no DGEN. Delegates to Gend_GS_Collab_Contract::propose.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_contract_propose( WP_REST_Request $req ) {
		if ( ! class_exists( 'Gend_GS_Collab_Contract' ) ) {
			return new WP_Error( 'gs_collab_no_engine', 'contract engine unavailable', array( 'status' => 500 ) );
		}
		$match_id       = (int) $req->get_param( 'id' );
		$proposer_group = (int) $req->get_param( 'group_id' );
		$args           = array(
			'payer_group'  => (int) $req->get_param( 'payer_group' ),
			'payee_group'  => (int) $req->get_param( 'payee_group' ),
			'escrow_model' => (string) $req->get_param( 'escrow_model' ),
			'credits'      => (int) $req->get_param( 'credits' ),
			'milestones'   => $req->get_param( 'milestones' ),
		);
		$res = Gend_GS_Collab_Contract::propose( $match_id, $proposer_group, $args );
		return rest_ensure_response( $res );
	}

	/**
	 * POST /collab/match/{id}/contract/accept — the COUNTERPARTY admin accepts →
	 * exactly one contract + one-sided DGEN escrow + thread linkage + match flip
	 * (COLLAB-01/02). Auth (counterparty admin/mod, no self-accept) is enforced in
	 * can_accept_contract. Delegates to Gend_GS_Collab_Contract::accept.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_contract_accept( WP_REST_Request $req ) {
		if ( ! class_exists( 'Gend_GS_Collab_Contract' ) ) {
			return new WP_Error( 'gs_collab_no_engine', 'contract engine unavailable', array( 'status' => 500 ) );
		}
		$match_id       = (int) $req->get_param( 'id' );
		$acceptor_group = (int) $req->get_param( 'group_id' );
		$res            = Gend_GS_Collab_Contract::accept( $match_id, $acceptor_group, (int) get_current_user_id() );
		return rest_ensure_response( $res );
	}

	/**
	 * POST /collab/match/{id}/contract/decline — either side declines / proposer
	 * cancels a pending proposal. No contract, no funds. Delegates to
	 * Gend_GS_Collab_Contract::decline.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_contract_decline( WP_REST_Request $req ) {
		if ( ! class_exists( 'Gend_GS_Collab_Contract' ) ) {
			return new WP_Error( 'gs_collab_no_engine', 'contract engine unavailable', array( 'status' => 500 ) );
		}
		$match_id = (int) $req->get_param( 'id' );
		$mode     = (string) $req->get_param( 'mode' );
		$res      = Gend_GS_Collab_Contract::decline( $match_id, $mode ?: 'declined' );
		return rest_ensure_response( $res );
	}

	/**
	 * REST-SAFE permission gate: only the acting group's admins/mods (or a
	 * super-admin) may act for it. Uses the two-arg groups_is_user_admin/mod —
	 * NOT bp_group_is_admin() (no current-group context in a REST request).
	 *
	 * The acting group id comes from `group_id` (or, defensively, `from_group`).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return bool
	 */
	public static function can_act_for_group( WP_REST_Request $req ) : bool {
		$uid = (int) get_current_user_id();
		$gid = (int) ( $req->get_param( 'group_id' ) ?: $req->get_param( 'from_group' ) );
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
	 * GET /collab/deck — return the ranked, facet-filtered deck for the acting group.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function route_deck( WP_REST_Request $req ) {
		$gid    = (int) $req->get_param( 'group_id' );
		$offset = (int) $req->get_param( 'offset' );
		$facets = array(
			'category' => (string) $req->get_param( 'category' ),
			'industry' => (string) $req->get_param( 'industry' ),
			'location' => (string) $req->get_param( 'location' ),
		);

		if ( ! class_exists( 'Gend_GS_Collab_Deck' ) ) {
			return rest_ensure_response( array( 'cards' => array(), 'has_more' => false ) );
		}

		$deck = Gend_GS_Collab_Deck::build_deck( $gid, $facets, 15, $offset );

		return rest_ensure_response( array(
			'cards'    => isset( $deck['cards'] ) ? $deck['cards'] : array(),
			'has_more' => ! empty( $deck['has_more'] ),
		) );
	}

	/**
	 * POST /collab/swipe — idempotent ledger write (SWIPE-03) + mutual-match step
	 * (Phase 83, MATCH-01/02/03). Records the decision via
	 * Gend_GS_Collab_Schema::record_swipe (INSERT IGNORE) — a re-swipe on the same
	 * (from,to) pair is still ok:true, no error. Then, ONLY on a 'right' swipe, calls
	 * the (class_exists-guarded) Gend_GS_Collab_Match::maybe_create_match, which
	 * race-safely creates a match on a reciprocal right swipe and — on a brand-new
	 * match — seeds the BP intro thread + fires the in-app + guarded/debounced
	 * batched-email notification. Still Tier A / money-free (no contract/market code).
	 * The response gains a `matched` flag: true ONLY when THIS swipe created a
	 * brand-new match (the JS shows an "It's a match!" toast + pre-disables Undo).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_swipe( WP_REST_Request $req ) {
		$from     = (int) $req->get_param( 'group_id' );
		$to       = (int) $req->get_param( 'to_group' );
		$decision = (string) $req->get_param( 'decision' );

		if ( $to <= 0 || $to === $from ) {
			return new WP_Error( 'gs_collab_bad_target', 'to_group must be a different group', array( 'status' => 400 ) );
		}
		if ( 'right' !== $decision && 'left' !== $decision ) {
			return new WP_Error( 'gs_collab_bad_decision', "decision must be 'right' or 'left'", array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return new WP_Error( 'gs_collab_no_schema', 'collab schema unavailable', array( 'status' => 500 ) );
		}

		$ok = Gend_GS_Collab_Schema::record_swipe( $from, $to, $decision, (int) get_current_user_id() );
		if ( false === $ok ) {
			return new WP_Error( 'gs_collab_write_failed', 'could not record swipe', array( 'status' => 500 ) );
		}

		// Mutual-match step (Phase 83). THIN call — all logic (reciprocal detection,
		// race-safe insert, intro-thread seed, in-app + batched-email notify fan-out)
		// lives in Gend_GS_Collab_Match. class_exists-guarded so a partial deploy (match
		// class not yet on the PVC) degrades to Phase-82 record-only behavior instead of
		// fataling.
		$matched = false;
		if ( 'right' === $decision && class_exists( 'Gend_GS_Collab_Match' ) ) {
			// Returns a match id ONLY when THIS swipe created a NEW match row
			// (rows_affected===1 winner). A left swipe / no-reciprocal / race-loser => 0.
			$match_id = Gend_GS_Collab_Match::maybe_create_match( $from, $to );
			$matched  = ( $match_id > 0 );
		}

		// Idempotent: a repeat swipe is still ok:true. `matched` is true only when THIS
		// swipe created a brand-new match row.
		return rest_ensure_response( array( 'ok' => true, 'matched' => $matched ) );
	}

	/**
	 * POST /collab/undo — single-step undo of the actor group's LAST swipe (SWIPE-07).
	 *
	 * Deletes exactly the most-recent gs_collab_swipes row for the acting group (by its
	 * explicit id — race-safe, never "delete latest"), which un-decides the card because
	 * SWIPE-04's never-re-show invariant is keyed on the row's EXISTENCE, so removing it
	 * re-enters the card into the deck. The JS then re-inserts the returned card at the
	 * front of the deck.
	 *
	 * REFUSED (locked anti-abuse/consistency rule) if the actor group's pair with the
	 * swipe target already has a match row: a formed match + its intro thread must never
	 * be torn down. This is a pure pair-existence check against gs_collab_matches'
	 * UNIQUE(group_a,group_b) — NO schema change, no created_by_swipe_id column needed
	 * (a match for the pair can only exist because that decision completed it).
	 *
	 * Single-step: this deletes only the one last row; the JS disables Undo until the
	 * next commit. Reuses can_act_for_group verbatim as its permission_callback (admin/
	 * mod/super-admin), so a logged-out call correctly 401s — no oauth allow-list entry.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_undo( WP_REST_Request $req ) {
		global $wpdb;

		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return new WP_Error( 'gs_collab_no_schema', 'collab schema unavailable', array( 'status' => 500 ) );
		}

		$from    = (int) $req->get_param( 'group_id' );
		$swipes  = Gend_GS_Collab_Schema::swipes_table();
		$matches = Gend_GS_Collab_Schema::matches_table();

		// 1. Find this group's most-recent swipe (highest id — monotonic AUTO_INCREMENT).
		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, to_group_id, decision FROM {$swipes}
			  WHERE from_group_id = %d ORDER BY id DESC LIMIT 1", $from ) );
		if ( ! $last ) {
			return new WP_Error( 'gs_collab_nothing_to_undo', 'No swipe to undo', array( 'status' => 409 ) );
		}

		// 2. REFUSE if this pair is already matched (locked rule). Normalized pair against
		//    UNIQUE(group_a,group_b) — pure pair-existence, no new column.
		$a       = min( $from, (int) $last->to_group_id );
		$b       = max( $from, (int) $last->to_group_id );
		$matched = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$matches} WHERE group_a = %d AND group_b = %d LIMIT 1", $a, $b ) );
		if ( $matched > 0 ) {
			return new WP_Error( 'gs_collab_undo_after_match',
				'That swipe created a match — it can’t be undone.', array( 'status' => 409 ) );
		}

		// 3. Delete by EXPLICIT id (race-safe) → the card re-enters the deck.
		$deleted = $wpdb->delete( $swipes, array( 'id' => (int) $last->id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'gs_collab_undo_failed', 'Could not undo', array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'ok'       => true,
			'to_group' => (int) $last->to_group_id, // JS re-inserts this card at the deck front
			'decision' => (string) $last->decision,
		) );
	}

	/**
	 * GET /collab/tags — the acting group's own tags + opt-in flag + the enum
	 * labels so the UI can render labelled selects.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function route_get_tags( WP_REST_Request $req ) {
		$gid = (int) $req->get_param( 'group_id' );

		$tags = class_exists( 'Gend_GS_Collab_Deck' )
			? Gend_GS_Collab_Deck::actor_tags( $gid )
			: array( 'category' => '', 'industry' => '', 'location' => '' );

		$optin = function_exists( 'groups_get_groupmeta' )
			? (bool) groups_get_groupmeta( $gid, '_gs_collab_optin', true )
			: false;

		return rest_ensure_response( array(
			'category'   => (string) ( $tags['category'] ?? '' ),
			'industry'   => (string) ( $tags['industry'] ?? '' ),
			'location'   => (string) ( $tags['location'] ?? '' ),
			'optin'      => $optin,
			'categories' => Gend_GS_Collab_Taxonomy::CATEGORIES,
			'industries' => Gend_GS_Collab_Taxonomy::INDUSTRIES,
		) );
	}

	/**
	 * POST /collab/tags — TAG-01 write. Validates category/industry against the
	 * enum (invalid → 400), writes the three tag metas, and enforces the locked
	 * opt-in gate: _gs_collab_optin=1 is set ONLY when BOTH category and industry
	 * are set; an explicit optin=0 clears it (a group can't be "open to
	 * collaboration" without at least a category + industry).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_set_tags( WP_REST_Request $req ) {
		$gid      = (int) $req->get_param( 'group_id' );
		$category = (string) $req->get_param( 'category' );
		$industry = (string) $req->get_param( 'industry' );
		$location = (string) $req->get_param( 'location' );
		$optin_in = $req->get_param( 'optin' );

		if ( ! function_exists( 'groups_update_groupmeta' ) || ! function_exists( 'groups_get_groupmeta' ) ) {
			return new WP_Error( 'gs_collab_no_bp', 'BuddyPress groups unavailable', array( 'status' => 500 ) );
		}

		// Enum validation — reject values not in the taxonomy (a non-empty,
		// non-enum value is a client error). Empty is allowed (clears the tag).
		if ( '' !== $category && ! Gend_GS_Collab_Taxonomy::is_valid_category( $category ) ) {
			return new WP_Error( 'gs_collab_bad_category', 'unknown category', array( 'status' => 400 ) );
		}
		if ( '' !== $industry && ! Gend_GS_Collab_Taxonomy::is_valid_industry( $industry ) ) {
			return new WP_Error( 'gs_collab_bad_industry', 'unknown industry', array( 'status' => 400 ) );
		}

		groups_update_groupmeta( $gid, '_gs_collab_category', sanitize_key( $category ) );
		groups_update_groupmeta( $gid, '_gs_collab_industry', sanitize_key( $industry ) );
		groups_update_groupmeta( $gid, '_gs_collab_location', sanitize_text_field( $location ) );

		// Opt-in gate (locked decision). An explicit optin=0 clears discoverability.
		// Otherwise, opt-in is granted ONLY when both category and industry are set.
		$explicit_optout = ( null !== $optin_in && 0 === (int) $optin_in );
		if ( $explicit_optout ) {
			groups_update_groupmeta( $gid, '_gs_collab_optin', 0 );
			$optin = false;
		} elseif ( '' !== $category && '' !== $industry ) {
			groups_update_groupmeta( $gid, '_gs_collab_optin', 1 );
			$optin = true;
		} else {
			// Can't be discoverable without both tags — force off.
			groups_update_groupmeta( $gid, '_gs_collab_optin', 0 );
			$optin = false;
		}

		return rest_ensure_response( array(
			'ok'       => true,
			'category' => sanitize_key( $category ),
			'industry' => sanitize_key( $industry ),
			'location' => sanitize_text_field( $location ),
			'optin'    => $optin,
		) );
	}
}
