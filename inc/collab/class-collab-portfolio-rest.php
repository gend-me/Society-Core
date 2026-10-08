<?php
/**
 * gend-society — GenD Match collab PRIVATE portfolio REST surface (Phase 87-03, v12.0, STAKE-02).
 *
 * The DARK, flag+hub-gated read-only portfolio route under gs/v1:
 *   GET /portfolio -> route_portfolio — the CALLING member's own positions across ALL
 *                     markets: shares (micro + whole), avg cost, current mark (implied
 *                     probability * whole shares at the market's current q), unrealized P/L,
 *                     realized P/L (realized_dgen), plus portfolio totals.
 *
 * PRIVACY (GATE-02 — no public bettor-P/L leaderboard). The route takes NO user_id arg — it
 * ALWAYS reads get_current_user_id(). There is no way to request another member's portfolio;
 * a different member/logged-out caller can never read someone else's positions. The
 * permission_callback is a plain logged-in check (logged-out -> 401); the WHERE clause is the
 * true privacy boundary (user_id = get_current_user_id()).
 *
 * GATING (locked decision — v6.0 Pitfall 1 discipline). register_routes() returns EARLY when
 * the node is NOT the hub (is_main_node) OR when GS_COLLAB_MARKET_PUBLIC is not defined/true —
 * mirrors Gend_GS_Collab_Market_REST::register_routes(). Because the route is then NEVER
 * registered, GET gs/v1/portfolio 404s (route-ABSENT, NOT 403) when the flag is off.
 *
 * SCOPE (Phase 87): READ-ONLY. No write/bet logic lives here — a stake POSTs to the 87-02
 * POST /market/{id}/bet route. No resolve/payout/VOID (Phase 88); no federation (Phase 89).
 * Neutral copy only (no "bet/odds/wager/payout" labels) — "market" / "position" /
 * "implied_probability" only.
 *
 * Every engine/schema call is class_exists/method_exists-guarded (partial-deploy safe;
 * memory: project_wp_fatal_auto_deactivation). All money math is bcmath (no native float).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Portfolio_REST {

	const NS = 'gs/v1';

	/**
	 * Register the ONE private portfolio route — ONLY when this is the hub AND
	 * GS_COLLAB_MARKET_PUBLIC is on. When off, the route is never registered
	 * (404 route-absent, NOT 403). Bound via one add_action('rest_api_init', ...) in the
	 * entrypoint; the class self-gates here (mirrors Gend_GS_Collab_Market_REST).
	 *
	 * @return void
	 */
	public static function register_routes() : void {
		// Hub-only: the LMSR engine + DGEN + positions live on the hub. On a container the
		// route is absent. Mirrors class-collab-market-rest.php:57-59.
		if ( ! self::is_main_node() ) {
			return;
		}

		// COUNSEL FLAG: the route is registered ONLY when the market surface is public.
		// Off (default) => route never bound => 404 route-absent (never 403).
		if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) || ! GS_COLLAB_MARKET_PUBLIC ) {
			return;
		}

		// GET /portfolio — the private own-user-only portfolio read. NO user_id arg (GATE-02):
		// the route ALWAYS reads get_current_user_id() — there is no way to request another
		// member's portfolio, so there is no public bettor-P/L leaderboard.
		register_rest_route( self::NS, '/portfolio', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'route_portfolio' ),
			'permission_callback' => array( __CLASS__, 'can_read_own' ),
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
	 * permission_callback — a logged-in member. GATE-02: the portfolio is private — always the
	 * calling member's own rows; there is NO user_id param and no public leaderboard. A
	 * logged-out call 401s. The WHERE user_id = get_current_user_id() clause in route_portfolio
	 * is the true privacy boundary (this callback only rejects the fully-anonymous case).
	 *
	 * @return bool
	 */
	public static function can_read_own() : bool {
		return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : ( get_current_user_id() > 0 );
	}

	/**
	 * GET /portfolio — the calling member's PRIVATE portfolio across ALL markets.
	 *
	 * GATE-02: reads ONLY get_current_user_id() rows — there is NO user_id request arg, so a
	 * member/logged-out caller can never read another member's positions. Neutral labels only.
	 *
	 * Per position: shares (micro + whole), cost_dgen, avg_cost (cost_dgen / whole shares),
	 * implied p_yes/p_no, current mark (implied prob of the held outcome * whole shares),
	 * unrealized P/L (mark - cost_dgen), realized P/L (realized_dgen), rake_bps, state. Plus
	 * portfolio totals. All money math is bcmath — no native float.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_portfolio( WP_REST_Request $req ) {
		global $wpdb;

		// Partial-deploy safe: never fatal if the schema/engine class isn't on this node yet
		// (memory: project_wp_fatal_auto_deactivation).
		if ( ! class_exists( 'Gend_GS_Collab_Schema' )
			|| ! method_exists( 'Gend_GS_Collab_Schema', 'positions_table' )
			|| ! method_exists( 'Gend_GS_Collab_Schema', 'markets_table' ) ) {
			return new WP_Error( 'gs_portfolio_no_schema', 'Portfolio store unavailable.', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'Gend_GS_Collab_Market' )
			|| ! method_exists( 'Gend_GS_Collab_Market', 'state_snapshot' ) ) {
			return new WP_Error( 'gs_portfolio_no_engine', 'Market engine unavailable.', array( 'status' => 500 ) );
		}

		// GATE-02: the beneficiary is ALWAYS the authenticated caller — no user_id arg exists.
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return new WP_Error( 'gs_portfolio_unauthorized', 'Login required.', array( 'status' => 401 ) );
		}

		$positions = Gend_GS_Collab_Schema::positions_table();
		$markets   = Gend_GS_Collab_Schema::markets_table();

		// Own rows only. Include shares=0 rows that carry realized_dgen != 0 so a fully-exited
		// (sold-back) position still surfaces its realized P/L history. OR'd, never user-scoped
		// by any request arg — the %d is always $uid.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.market_id, p.outcome, p.shares, p.cost_dgen, p.realized_dgen,
				        m.match_id, m.rake_bps, m.state
				 FROM {$positions} p
				 JOIN {$markets} m ON m.id = p.market_id
				 WHERE p.user_id = %d AND ( p.shares > 0 OR p.realized_dgen <> 0 )
				 ORDER BY p.market_id ASC, p.outcome ASC",
				$uid
			)
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Cache one implied-probability snapshot per market (avoid N re-quotes for a member who
		// holds both YES and NO of the same market). state_snapshot() returns
		// implied_probability:{yes,no} as fixed-point strings via the public engine API.
		$prob_cache = array();

		$positions_out = array();
		$tot_cost      = '0';
		$tot_mark      = '0';
		$tot_unreal    = '0';
		$tot_real      = '0';

		foreach ( $rows as $r ) {
			$market_id   = (int) $r->market_id;
			$outcome     = ( 'no' === strtolower( (string) $r->outcome ) ) ? 'no' : 'yes';
			$shares_micro = (string) $r->shares;                 // micro-shares (1e6/share)
			$cost_dgen    = (string) $r->cost_dgen;              // cumulative integer DGEN
			$realized     = (string) $r->realized_dgen;         // sell-back realized (avg-cost basis)

			// whole shares (display + mark math): micro / 1e6, 6 dp.
			$shares_whole = bcdiv( $shares_micro, '1000000', 6 );

			// avg cost = cost_dgen / whole shares (only when holding shares).
			$avg_cost = ( bccomp( $shares_whole, '0', 6 ) > 0 )
				? bcdiv( $cost_dgen, $shares_whole, 6 )
				: '0';

			// Implied probability at the market's CURRENT q (cached per market).
			if ( ! isset( $prob_cache[ $market_id ] ) ) {
				$snap = Gend_GS_Collab_Market::state_snapshot( $market_id );
				if ( is_wp_error( $snap ) || ! is_array( $snap ) || ! isset( $snap['implied_probability'] ) ) {
					// Market row unreadable — mark this position at 0 rather than failing the whole
					// portfolio (a resolved/void market can still have realized history to show).
					$prob_cache[ $market_id ] = array( 'yes' => '0', 'no' => '0' );
				} else {
					$prob_cache[ $market_id ] = array(
						'yes' => (string) ( $snap['implied_probability']['yes'] ?? '0' ),
						'no'  => (string) ( $snap['implied_probability']['no'] ?? '0' ),
					);
				}
			}
			$p_yes = $prob_cache[ $market_id ]['yes'];
			$p_no  = $prob_cache[ $market_id ]['no'];
			$p_out = ( 'no' === $outcome ) ? $p_no : $p_yes;

			// current mark (DGEN) = implied prob of the held outcome * whole shares.
			// (RESEARCH Q4 accepts implied-prob * shares as the mark; matches the odds shown.)
			$mark_dgen = bcmul( $p_out, $shares_whole, 6 );

			// unrealized P/L = mark - cost_dgen (what's still at risk / in-profit right now).
			$unrealized = bcsub( $mark_dgen, $cost_dgen, 6 );

			// realized P/L: realized_dgen already stores the avg-cost-basis realized amount on
			// sell-backs (Phase 87-01), exposed directly + clearly labelled.
			$realized_pl = $realized;

			$positions_out[] = array(
				'market_id'    => $market_id,
				'match_id'     => (int) $r->match_id,
				'outcome'      => $outcome,
				'shares_micro' => $shares_micro,
				'shares'       => $shares_whole,
				'cost_dgen'    => $cost_dgen,
				'avg_cost'     => $avg_cost,
				'p_yes'        => $p_yes,
				'p_no'         => $p_no,
				'mark_dgen'    => $mark_dgen,
				'unrealized_pl' => $unrealized,
				'realized_pl'  => $realized_pl,
				'rake_bps'     => (int) $r->rake_bps,
				'state'        => (string) $r->state,
			);

			$tot_cost   = bcadd( $tot_cost, $cost_dgen, 6 );
			$tot_mark   = bcadd( $tot_mark, $mark_dgen, 6 );
			$tot_unreal = bcadd( $tot_unreal, $unrealized, 6 );
			$tot_real   = bcadd( $tot_real, $realized_pl, 6 );
		}

		return rest_ensure_response( array(
			'positions' => $positions_out,
			'totals'    => array(
				'cost_dgen'     => $tot_cost,
				'mark_dgen'     => $tot_mark,
				'unrealized_pl' => $tot_unreal,
				'realized_pl'   => $tot_real,
			),
		) );
	}
}
