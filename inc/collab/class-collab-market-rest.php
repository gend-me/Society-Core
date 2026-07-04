<?php
/**
 * gend-society — GenD Match collab-market READ-ONLY REST surface (Phase 86-04, v12.0).
 *
 * The DARK, flag+hub-gated read surface over the Phase-86 LMSR engine
 * (Gend_GS_Collab_Market). TWO read-only routes under gs/v1:
 *   GET /market/{id}        -> route_state — { market_id, state, resolve_by,
 *                              implied_probability:{ yes, no } } (MARKET-04/05).
 *   GET /market/{id}/quote  -> route_quote — Gend_GS_Collab_Market::quote(id,outcome,delta)
 *                              shape { cost_dgen, p_yes, p_no }.
 *
 * GATING (locked decision — v6.0 Pitfall 1 discipline). register_routes() returns
 * EARLY when the node is NOT the hub (is_main_node) OR when GS_COLLAB_MARKET_PUBLIC
 * is not defined/true. Because the routes are then NEVER registered, a request to
 * gs/v1/market/{id} 404s (route-ABSENT), NOT 403 (route-hidden). No market REST
 * markup, no member-facing market strings, exist when the flag is off.
 *
 * SCOPE GUARD (Phase 86): READ-ONLY only. There is NO member bet / POST / write
 * route here — the real stake path (DGEN debit) is Phase 87; resolution/payout is
 * Phase 88. NO "bet/odds/wager/payout" regulator-magnet strings in any response
 * label — neutral "market" / "implied_probability" only.
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
		if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) || ! GS_COLLAB_MARKET_PUBLIC ) {
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
	}

	/**
	 * Hub-only gate. Mirrors class-collab-rest.php:244-250 / class-collab-market.php:102-106
	 * — true on the main node (or when the OAuth resource isn't present at all, i.e. a
	 * lone hub), false on a container.
	 *
	 * @return bool
	 */
	private static function is_main_node() : bool {
		return ! class_exists( 'Gend_CP_OAuth_Resource' )
			|| ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
			|| Gend_CP_OAuth_Resource::is_main_node();
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
}
