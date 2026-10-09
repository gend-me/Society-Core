<?php
/**
 * gend-society — GenD Match collab-market REST surface (Phase 86-04 read + 87-02 write, v12.0).
 *
 * The DARK, flag+hub-gated surface over the Phase-86/87 LMSR engine
 * (Gend_GS_Collab_Market). TWO read-only routes + ONE write route under gs/v1:
 *   GET  /market/{id}        -> route_state — { market_id, state, resolve_by,
 *                               implied_probability:{ yes, no } } (MARKET-04/05).
 *   GET  /market/{id}/quote  -> route_quote — Gend_GS_Collab_Market::quote(id,outcome,delta)
 *                               shape { cost_dgen, p_yes, p_no }.
 *   POST /market/{id}/bet    -> route_bet — Gend_GS_Collab_Market::place_bet(...) (87-02,
 *                               STAKE-04/05). Buy AND sell-back to the AMM. The bettor is
 *                               derived from get_current_user_id() ONLY (no recipient body
 *                               param — no route can move a position/value to another user;
 *                               the AMM is the sole counterparty). The response DISCLOSES
 *                               rake_bps + the losing-pool-at-resolution string; nothing is
 *                               skimmed here (the skim is Phase 88 payout).
 *
 * GATING (locked decision — v6.0 Pitfall 1 discipline). register_routes() returns
 * EARLY when the node is NOT the hub (is_main_node) OR when GS_COLLAB_MARKET_PUBLIC
 * is not defined/true. Because the routes are then NEVER registered, a request to
 * gs/v1/market/{id} (read OR bet) 404s (route-ABSENT), NOT 403 (route-hidden). No market
 * REST markup, no member-facing market strings, exist when the flag is off.
 *
 * SCOPE GUARD (Phase 87): the write surface is the bet/sell-back path ONLY. There is NO
 * resolve/payout/VOID/rake-SKIM route (Phase 88) and NO transfer route (STAKE-04). NO
 * "bet/odds/wager/payout" regulator-magnet strings in any response label — neutral
 * "market" / "stake" / "position" / "implied_probability" only.
 *
 * Mirrors class-collab-rest.php: `const NS = 'gs/v1'` + a static register_routes()
 * bound by ONE add_action('rest_api_init', ...) in the entrypoint (per-class binding).
 * Every engine call is class_exists/method_exists-guarded (partial-deploy safe;
 * memory: project_wp_fatal_auto_deactivation).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Market_REST {

	const NS = 'gs/v1';

	/**
	 * Register the two READ-ONLY market routes — ONLY when this is the hub AND
	 * GS_COLLAB_MARKET_PUBLIC is on. When off, the routes are never registered
	 * (404 route-absent, NOT 403). Bound via one add_action('rest_api_init', ...)
	 * in the entrypoint; the class self-gates here.
	 *
	 * @return void
	 */
	public static function register_routes() : void {
		// Hub-only: the LMSR engine + DGEN + chain live on the hub. On a container the
		// routes are absent. Mirrors class-collab-rest.php:244-250.
		if ( ! self::is_main_node() ) {
			return;
		}

		// COUNSEL FLAG: routes are registered ONLY when the market surface is public.
		// Off (default) => routes never bound => 404 route-absent (never 403).
		if ( ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) || ! GEND_SOCIETY_COLLAB_MARKET_PUBLIC ) {
			return;
		}

		// GET /market/{id} — read-only state + implied probability.
		register_rest_route( self::NS, '/market/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'route_state' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		// GET /market/{id}/quote — read-only advisory quote for a hypothetical delta.
		register_rest_route( self::NS, '/market/(?P<id>\d+)/quote', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'route_quote' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'id'      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'outcome' => array( 'sanitize_callback' => 'sanitize_key' ),
				'delta'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /market/{id}/bet — the ONE dark+hub-gated WRITE route (87-02, STAKE-04/05).
		// Buy AND sell-back-to-the-AMM via a single `direction` param (RESEARCH Open Q3).
		// Because register_routes() self-gated on is_main_node() AND GS_COLLAB_MARKET_PUBLIC
		// ABOVE (before any register_rest_route call), this route is NEVER registered when the
		// flag is off => POST gs/v1/market/{id}/bet 404s route-ABSENT (GATE-01 discipline,
		// proven end-to-end in Phase 90). The bettor is derived from auth inside route_bet —
		// there is deliberately NO 'user_id'/'recipient'/'from' arg here (STAKE-04).
		register_rest_route( self::NS, '/market/(?P<id>\d+)/bet', array(
			'methods'             => WP_REST_Server::CREATABLE, // POST
			'callback'            => array( __CLASS__, 'route_bet' ),
			'permission_callback' => array( __CLASS__, 'can_bet' ),
			'args'                => array(
				'id'              => array( 'required' => true,  'sanitize_callback' => 'absint' ),
				'outcome'         => array( 'required' => true,  'sanitize_callback' => 'sanitize_key' ),        // yes|no
				'direction'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => 'buy' ), // buy|sell
				'amount'          => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ), // micro-shares, positive int string
				'idempotency_key' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ), // client UUID; derived if absent
			),
		) );

		// GET /markets (?scope=hub) — the NEW hub-wide market LIST route (89-03, FED-02). Because
		// register_routes() already self-gated on is_main_node() AND GS_COLLAB_MARKET_PUBLIC ABOVE
		// (before any register_rest_route call), this READ-ONLY route is NEVER registered when the
		// flag is off OR this is not the hub => GET gs/v1/markets 404s route-ABSENT (never 403). It
		// lists open+locked markets with state_snapshot()-shaped implied odds PLUS the authenticated
		// caller's OWN positions ONLY (GATE-02: no user_id arg, no public leaderboard). READ-ONLY:
		// WP_REST_Server::READABLE — there is deliberately NO write route added here (money stays on
		// the hub-only /market/{id}/bet above). The container market MIRROR (class-collab-market-
		// mirror.php) fetches THIS route read-only and links bet/sell back to the hub.
		register_rest_route( self::NS, '/markets', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'route_market_list' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'scope' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => 'hub' ),
			),
		) );
	}

	/**
	 * Hub gate: true when the runtime mode is hub (gend_society_is_hub(), from
	 * GEND_SOCIETY_RUNTIME), false on containers and standalone installs.
	 * Before 1.1.6 this inferred "hub" from a missing Gend_CP_OAuth_Resource
	 * class, which made every standalone install look like the hub.
	 *
	 * @return bool
	 */
	private static function is_main_node() : bool {
		return gend_society_is_hub();
	}

	/**
	 * permission_callback — a logged-in member. The market read surface is member-facing
	 * (not group-scoped like the collab action routes), so a plain authenticated check is
	 * correct; a logged-out call 401s. No oauth allow-list entry needed.
	 *
	 * @return bool
	 */
	public static function can_read() : bool {
		return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : ( get_current_user_id() > 0 );
	}

	/**
	 * permission_callback for the write route — a logged-in member (same shape as can_read).
	 * A logged-out call 401s. No oauth allow-list entry needed. Do NOT group-scope here: the
	 * matched-insider gate (STAKE-03) lives inside place_bet(), and the position cap / balance
	 * checks run inside the Phase-86 FOR UPDATE lock — a plain authenticated check is correct.
	 *
	 * @return bool
	 */
	public static function can_bet() : bool {
		return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : ( get_current_user_id() > 0 );
	}

	/**
	 * GET /market/{id} — the read-only market snapshot: state, resolve_by, implied
	 * probability. Neutral labels only (no bet/odds/wager/payout).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_state( WP_REST_Request $req ) {
		if ( ! class_exists( 'Gend_GS_Collab_Market' )
			|| ! method_exists( 'Gend_GS_Collab_Market', 'state_snapshot' ) ) {
			return new WP_Error( 'gs_market_no_engine', 'Market engine unavailable.', array( 'status' => 500 ) );
		}
		$snapshot = Gend_GS_Collab_Market::state_snapshot( (int) $req->get_param( 'id' ) );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return rest_ensure_response( $snapshot );
	}

	/**
	 * GET /market/{id}/quote — advisory read-only quote for a hypothetical delta of
	 * $outcome (yes|no). Returns Gend_GS_Collab_Market::quote(...) shape
	 * { cost_dgen, p_yes, p_no }. READ-ONLY — nothing is staked/mutated (Phase 87 adds
	 * the real stake path).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_quote( WP_REST_Request $req ) {
		if ( ! class_exists( 'Gend_GS_Collab_Market' )
			|| ! method_exists( 'Gend_GS_Collab_Market', 'quote' ) ) {
			return new WP_Error( 'gs_market_no_engine', 'Market engine unavailable.', array( 'status' => 500 ) );
		}
		$outcome = ( 'no' === strtolower( (string) $req->get_param( 'outcome' ) ) ) ? 'no' : 'yes';
		$delta   = (string) $req->get_param( 'delta' );
		if ( '' === $delta || ! ctype_digit( $delta ) || '0' === $delta ) {
			return new WP_Error( 'gs_market_bad_delta', 'delta must be a positive integer.', array( 'status' => 400 ) );
		}
		$quote = Gend_GS_Collab_Market::quote( (int) $req->get_param( 'id' ), $outcome, $delta );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		return rest_ensure_response( $quote );
	}

	/**
	 * POST /market/{id}/bet — the real member stake path (87-02, STAKE-04/05). Drives
	 * Gend_GS_Collab_Market::place_bet() inside the Phase-86 FOR UPDATE lock: a BUY debits the
	 * member's DGEN + escrows it; a SELL-back refunds C(q)−C(q') and reduces the position. The
	 * AMM maker is the sole counterparty.
	 *
	 * STAKE-04: the bettor/beneficiary is derived from auth (get_current_user_id) ONLY — there
	 * is NO 'user_id'/'recipient'/'from' arg and this method MUST NOT read one from the body,
	 * so no route can move a position or its value to another user.
	 *
	 * STAKE-05: the response DISCLOSES rake_bps + the plain-language losing-pool-at-resolution
	 * string that place_bet() returns — returned UNCHANGED. NOTHING is skimmed here (the skim is
	 * Phase 88 payout); Plan 87-03's UI renders the disclosure.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_bet( WP_REST_Request $req ) {
		// Partial-deploy safe: never fatal if the engine class/method isn't on this node yet
		// (memory: project_wp_fatal_auto_deactivation).
		if ( ! class_exists( 'Gend_GS_Collab_Market' )
			|| ! method_exists( 'Gend_GS_Collab_Market', 'place_bet' ) ) {
			return new WP_Error( 'gs_market_engine_unavailable', 'Market engine unavailable.', array( 'status' => 500 ) );
		}

		$market_id = (int) $req->get_param( 'id' );
		$outcome   = ( 'no' === strtolower( (string) $req->get_param( 'outcome' ) ) ) ? 'no' : 'yes';
		$direction = ( 'sell' === strtolower( (string) $req->get_param( 'direction' ) ) ) ? 'sell' : 'buy';
		$amount    = (string) $req->get_param( 'amount' );

		// Positive integer micro-shares only (bcmath-safe compare — no float).
		if ( '' === $amount || ! ctype_digit( $amount ) || bccomp( $amount, '0', 0 ) <= 0 ) {
			return new WP_Error( 'gs_market_bad_delta', 'amount must be a positive integer.', array( 'status' => 400 ) );
		}

		// STAKE-04: the bettor/beneficiary is derived from auth only — no route moves a position
		// or its value to another user; the AMM is the sole counterparty.
		$user_id = (int) get_current_user_id();

		// Prefer a client-supplied idempotency key so a retry REPLAYS the prior result (never a
		// double-debit); derive a sha256 fallback only when the client omits it.
		$client_key = (string) $req->get_param( 'idempotency_key' );
		$idem_key   = ( '' !== $client_key )
			? $client_key
			: hash( 'sha256', $market_id . '|' . $user_id . '|' . $outcome . '|' . $direction . '|' . $amount . '|' . microtime( true ) );

		$result = Gend_GS_Collab_Market::place_bet( $market_id, $user_id, $outcome, $direction, $amount, $idem_key );
		if ( is_wp_error( $result ) ) {
			// place_bet's WP_Error carries ['status'] (403 insider / 402 insufficient / 422 cap /
			// 409 not-open/conflict/invariant / 500 debit-failed); WP REST renders it as that code.
			return $result;
		}

		// 200 — payload already carries cost_dgen|refund_dgen, q_yes/q_no, escrow_dgen,
		// p_yes/p_no, position{...}, rake_bps + the disclosure string. Returned UNCHANGED.
		return rest_ensure_response( $result );
	}

	/**
	 * GET /markets (?scope=hub) — the READ-ONLY hub-wide market LIST (89-03, FED-02). Lists the
	 * open+locked markets with state_snapshot()-shaped implied odds, and for the AUTHENTICATED
	 * caller attaches THEIR OWN position on each market (GATE-02 discipline: own-user-only, no
	 * user_id request arg, no other members' positions — no public leaderboard). Neutral labels
	 * only (no bet/odds/wager/payout regulator-magnet strings). This is the hub source the
	 * container market MIRROR fetches read-only; any actual bet/sell happens on the hub (money
	 * stays hub-only). NO write path here.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_market_list( WP_REST_Request $req ) {
		global $wpdb;

		// Partial-deploy safe: never fatal if the schema/engine isn't on this node yet
		// (memory: project_wp_fatal_auto_deactivation).
		if ( ! class_exists( 'Gend_GS_Collab_Schema' )
			|| ! method_exists( 'Gend_GS_Collab_Schema', 'markets_table' )
			|| ! method_exists( 'Gend_GS_Collab_Schema', 'positions_table' ) ) {
			return new WP_Error( 'gs_market_no_schema', 'Market store unavailable.', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'Gend_GS_Collab_Market' )
			|| ! method_exists( 'Gend_GS_Collab_Market', 'state_snapshot' ) ) {
			return new WP_Error( 'gs_market_no_engine', 'Market engine unavailable.', array( 'status' => 500 ) );
		}

		$markets_table   = Gend_GS_Collab_Schema::markets_table();
		$positions_table = Gend_GS_Collab_Schema::positions_table();

		// The tradable list: open + locked markets (a locked market still surfaces its final implied
		// probability, mirroring state_snapshot's read-any-state contract). Bounded LIMIT 100 — this
		// is a read-only overview, not a paginated feed. Ordered newest-first.
		$market_rows = $wpdb->get_col(
			"SELECT id FROM {$markets_table}
			 WHERE state IN ( 'open', 'locked' )
			 ORDER BY id DESC
			 LIMIT 100"
		);
		if ( ! is_array( $market_rows ) || empty( $market_rows ) ) {
			return rest_ensure_response( array( 'markets' => array(), 'scope' => 'hub' ) );
		}
		$market_ids = array_map( 'intval', $market_rows );

		// GATE-02: the caller's OWN positions ONLY — the WHERE user_id = get_current_user_id() clause
		// is the privacy boundary. There is NO user_id request arg; no other member's position is
		// ever read. Batched IN (...) over the bounded market list so it's ONE query, not N.
		$uid          = (int) get_current_user_id();
		$own_by_market = array();
		if ( $uid > 0 ) {
			$placeholders = implode( ',', array_fill( 0, count( $market_ids ), '%d' ) );
			$params       = array_merge( $market_ids, array( $uid ) );
			$pos_rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT market_id, outcome, shares
					 FROM {$positions_table}
					 WHERE market_id IN ( {$placeholders} ) AND user_id = %d AND shares > 0",
					$params
				)
			);
			if ( is_array( $pos_rows ) ) {
				foreach ( $pos_rows as $pr ) {
					$mid = (int) $pr->market_id;
					if ( ! isset( $own_by_market[ $mid ] ) ) {
						$own_by_market[ $mid ] = array( 'yes_shares' => 0, 'no_shares' => 0 );
					}
					if ( 'no' === (string) $pr->outcome ) {
						$own_by_market[ $mid ]['no_shares'] = (int) $pr->shares;
					} else {
						$own_by_market[ $mid ]['yes_shares'] = (int) $pr->shares;
					}
				}
			}
		}

		$items = array();
		foreach ( $market_ids as $mid ) {
			$snapshot = Gend_GS_Collab_Market::state_snapshot( $mid );
			if ( is_wp_error( $snapshot ) || ! is_array( $snapshot ) ) {
				continue; // skip a not-found/errored row — never a fatal.
			}
			$items[] = array(
				'market_id'           => (int) $snapshot['market_id'],
				'state'               => (string) $snapshot['state'],
				'resolve_by'          => isset( $snapshot['resolve_by'] ) ? (int) $snapshot['resolve_by'] : 0,
				'implied_probability' => isset( $snapshot['implied_probability'] ) ? $snapshot['implied_probability'] : array( 'yes' => null, 'no' => null ),
				// GATE-02: the caller's own position only, or null if they hold none.
				'my_position'         => isset( $own_by_market[ $mid ] ) ? $own_by_market[ $mid ] : null,
			);
		}

		return rest_ensure_response( array( 'markets' => $items, 'scope' => 'hub' ) );
	}
}
