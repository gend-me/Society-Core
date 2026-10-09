<?php
/**
 * gend-society — GenD Match container READ-ONLY hub-wide market MIRROR (Phase 89-03, v12.0, FED-02).
 *
 * The container-side counterpart of the hub's gated gs/v1/markets list route
 * (class-collab-market-rest.php). On a CONTAINER (! is_hub()) it renders a READ-ONLY
 * "Collaborations" member-nav tab that fetches the hub's hub-wide market list read-only
 * (live implied odds + the member's OWN positions) and shows it. Any actual bet/sell is a
 * LINK/redirect to the HUB Markets surface — there is NO money-adjacent POST on a container
 * (no money POST, no local bet form). ALL money movement stays hub-only (is_main_node()):
 * the write route (POST /market/{id}/bet) already self-gates hub-only, so it is route-absent
 * on a container by construction. This mirror only READS and LINKS.
 *
 * DARK + CONTAINER gate (locked decision — v6.0 Pitfall 1 discipline / GATE-01, Tier B). EVERY
 * register/render entrypoint early-returns unless GS_COLLAB_MARKET_PUBLIC is defined+true AND
 * this is NOT the hub. When the flag is off the nav item is NEVER registered and the tab is
 * DOM-ABSENT (not CSS-hidden) — mirror class-collab-market-rest.php:54-65 / member-markets.php.
 * On the hub the native member-markets.php tab already renders, so the mirror no-ops there
 * (is_hub()).
 *
 * P2 SAFETY (load-bearing): the hub fetch is best-effort — a WP_Error / non-2xx / unreachable /
 * no-hub-url degrades to a graceful "no markets right now", NEVER a fatal and NEVER a
 * container-local money control. The result is cached in a short-TTL transient so a hub
 * cold-start (10-25s) never blocks the member's page render.
 *
 * NEUTRAL COPY only (no "bet/odds/wager/payout") — "Collaborations" / "markets" / "position" /
 * "implied probability" (mirrors class-collab-market-rest.php neutral-copy discipline).
 *
 * All BP interaction happens on bp_setup_nav, never at file-include time — gend-society loads
 * alphabetically before social-network (where BP ships), so touching BP at include time fatals
 * (Pitfall 19 / bp_register priority). Every BP call is function_exists-guarded.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Market_Mirror {

	/** Read-only hub market-list cache TTL (seconds). Short so odds stay fresh; long enough to
	 *  absorb a hub cold-start without re-fetching on every render. */
	const CACHE_TTL = 90;

	/** Transient key for the cached hub market list. */
	const CACHE_KEY = 'gend_society_collab_market_mirror';

	/**
	 * Wire the container mirror. Self-gates: return unless GS_COLLAB_MARKET_PUBLIC is on AND this
	 * is a container (! is_hub()). Registers the BuddyPress member-nav tab (bp_setup_nav) — the
	 * whole registration is dark+container-guarded so when the flag is off there is NO DOM anywhere.
	 *
	 * @return void
	 */
	public static function init() : void {
		// DARK: no mirror when the counsel flag is off (default) — DOM-absent, GATE-01.
		if ( ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) || ! GEND_SOCIETY_COLLAB_MARKET_PUBLIC ) {
			return;
		}
		// CONTAINER-only: on the hub, member-markets.php already renders the native tab — no mirror.
		if ( self::is_hub() ) {
			return;
		}
		if ( function_exists( 'add_action' ) ) {
			// Priority 100 so it runs after BP core/Youzify registered their primary nav (lets us
			// read the live `groups` position), mirroring member-markets.php.
			add_action( 'bp_setup_nav', array( __CLASS__, 'add_mirror_tab' ), 100 );
		}
	}

	/**
	 * Hub gate: true when the runtime mode is hub (gend_society_is_hub(), from
	 * GEND_SOCIETY_RUNTIME), false on containers and standalone installs.
	 * Before 1.1.6 this inferred "hub" from a missing Gend_CP_OAuth_Resource
	 * class, which made every standalone install look like the hub.
	 *
	 * @return bool
	 */
	private static function is_hub() : bool {
		return gend_society_is_hub();
	}

	/**
	 * Register the READ-ONLY Collaborations mirror tab on BuddyPress member profiles (container).
	 * The whole registration is dark+container-guarded (belt-and-suspenders — init() already
	 * gated) so the tab is DOM-absent when off (GATE-01).
	 *
	 * @return void
	 */
	public static function add_mirror_tab() : void {
		if ( ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) || ! GEND_SOCIETY_COLLAB_MARKET_PUBLIC ) {
			return;
		}
		if ( self::is_hub() ) {
			return;
		}
		if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
			return;
		}

		// Land right of APP PROJECTS (mirror member-markets.php positioning).
		$pos = 18;
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
			'screen_function'         => array( __CLASS__, 'screen' ),
			'position'                => $pos,
			'item_css_id'             => 'collab-markets',
			// Own-profile-only surface (GATE-02 privacy): the caller only ever sees their own
			// hub-wide market view (their own positions come from the hub keyed to their session).
			'show_for_displayed_user' => false,
		) );
	}

	/**
	 * Screen callback — loads the BP plugins template and routes its content to render().
	 *
	 * @return void
	 */
	public static function screen() : void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'bp_template_title', '__return_empty_string' );
			add_action( 'bp_template_content', array( __CLASS__, 'render_content' ) );
		}
		if ( function_exists( 'bp_core_load_template' ) ) {
			bp_core_load_template( 'members/single/plugins' );
		}
	}

	/**
	 * bp_template_content callback — echo the read-only mirror markup (GATE-02: own profile only).
	 *
	 * @return void
	 */
	public static function render_content() : void {
		// GATE-02 privacy: a member sees ONLY their own view, on their own profile.
		if ( function_exists( 'bp_is_my_profile' ) && ! bp_is_my_profile() ) {
			echo '<p>' . esc_html__( 'This view is private.', 'gend-society' ) . '</p>';
			return;
		}
		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes internally.
	}

	/**
	 * Fetch the hub's hub-wide market list READ-ONLY (authenticated member GET against the hub
	 * gs/v1/markets?scope=hub). Cached in a short-TTL transient. On WP_Error / non-2xx /
	 * unreachable / no-hub-url / malformed body => return an empty array (graceful "no markets");
	 * NEVER a fatal, NEVER a container-local money control. This is a READ ONLY — there is
	 * deliberately no money-POST (no outbound write) anywhere in this class.
	 *
	 * @return array List of market items (possibly empty).
	 */
	public static function fetch_hub_markets() : array {
		if ( ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) || ! GEND_SOCIETY_COLLAB_MARKET_PUBLIC || self::is_hub() ) {
			return array();
		}

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$hub = self::hub_url();
		if ( '' === $hub ) {
			return array(); // no hub resolvable — graceful empty.
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return array();
		}

		$url  = $hub . '/wp-json/gs/v1/markets?scope=hub';
		$resp = wp_remote_get( $url, array(
			'timeout'     => 3,
			'redirection' => 0,
			'headers'     => self::hub_auth_headers(),
		) );
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$code = (int) ( function_exists( 'wp_remote_retrieve_response_code' ) ? wp_remote_retrieve_response_code( $resp ) : 0 );
		if ( $code < 200 || $code >= 300 ) {
			return array(); // 404 (flag off on the hub) / 401 / 5xx — graceful empty.
		}
		$body = function_exists( 'wp_remote_retrieve_body' ) ? wp_remote_retrieve_body( $resp ) : '';
		$data = json_decode( (string) $body, true );
		$items = ( is_array( $data ) && isset( $data['markets'] ) && is_array( $data['markets'] ) ) ? $data['markets'] : array();

		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::CACHE_KEY, $items, self::CACHE_TTL );
		}
		return $items;
	}

	/**
	 * Render the READ-ONLY hub-wide market list: each market's implied YES/NO % (from the fetched
	 * odds) + the member's OWN position + a bet/sell LINK/redirect to the hub Markets surface. NO
	 * form, NO POST target on the container. All output escaped. Neutral labels only.
	 *
	 * Returns '' unless the flag is on AND this is a container (belt-and-suspenders — DOM-absent
	 * when off). If the hub URL is unresolvable, odds render read-only with NO actionable link
	 * (never a container-local bet control).
	 *
	 * @return string
	 */
	public static function render() : string {
		if ( ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) || ! GEND_SOCIETY_COLLAB_MARKET_PUBLIC || self::is_hub() ) {
			return '';
		}

		$markets = self::fetch_hub_markets();
		$hub     = self::hub_url();
		// The hub Markets surface: the Phase-87 dark-gated Collaborations member-nav tab.
		$hub_markets_url = ( '' !== $hub ) ? $hub . '/members/me/collab-markets/' : '';

		$out  = '<div class="gs-collab-market-mirror-root">';
		$out .= '<p class="gs-collab-mirror-intro">'
			. esc_html__( 'A read-only view of collaborations across connected apps. Positions are held on the hub.', 'gend-society' )
			. '</p>';

		if ( empty( $markets ) ) {
			$out .= '<p class="gs-collab-empty">' . esc_html__( 'No collaborations to show right now.', 'gend-society' ) . '</p>';
			$out .= '</div>';
			return $out;
		}

		$out .= '<ul class="gs-collab-mirror-list">';
		foreach ( $markets as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$mid   = isset( $m['market_id'] ) ? (int) $m['market_id'] : 0;
			$state = isset( $m['state'] ) ? (string) $m['state'] : '';
			$prob  = ( isset( $m['implied_probability'] ) && is_array( $m['implied_probability'] ) ) ? $m['implied_probability'] : array();
			$p_yes = isset( $prob['yes'] ) ? (string) $prob['yes'] : '';
			$p_no  = isset( $prob['no'] ) ? (string) $prob['no'] : '';

			$pos      = ( isset( $m['my_position'] ) && is_array( $m['my_position'] ) ) ? $m['my_position'] : null;
			$yes_sh   = ( $pos && isset( $pos['yes_shares'] ) ) ? (int) $pos['yes_shares'] : 0;
			$no_sh    = ( $pos && isset( $pos['no_shares'] ) ) ? (int) $pos['no_shares'] : 0;

			$out .= '<li class="gs-collab-mirror-item" data-market-id="' . esc_attr( (string) $mid ) . '">';
			$out .= '<span class="gs-collab-mirror-state">' . esc_html( ucfirst( $state ) ) . '</span> ';
			$out .= '<span class="gs-collab-mirror-prob">'
				. esc_html__( 'Implied probability', 'gend-society' ) . ': '
				. esc_html__( 'Yes', 'gend-society' ) . ' ' . esc_html( self::pct( $p_yes ) ) . ' / '
				. esc_html__( 'No', 'gend-society' ) . ' ' . esc_html( self::pct( $p_no ) )
				. '</span>';

			if ( $yes_sh > 0 || $no_sh > 0 ) {
				$out .= ' <span class="gs-collab-mirror-position">'
					. esc_html__( 'Your position', 'gend-society' ) . ': '
					. esc_html__( 'Yes', 'gend-society' ) . ' ' . esc_html( (string) $yes_sh ) . ', '
					. esc_html__( 'No', 'gend-society' ) . ' ' . esc_html( (string) $no_sh )
					. '</span>';
			}

			// Bet/sell is a LINK/redirect to the HUB Markets surface — NEVER a container-local
			// form/POST. If the hub URL is unresolvable, render odds read-only with NO link.
			if ( '' !== $hub_markets_url ) {
				$out .= ' <a class="gs-collab-mirror-hublink" href="' . esc_url( $hub_markets_url ) . '" rel="noopener">'
					. esc_html__( 'Manage on the hub', 'gend-society' ) . '</a>';
			}

			$out .= '</li>';
		}
		$out .= '</ul>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Format a fixed-point implied-probability string (0..1) as a rounded percent for display.
	 * Purely cosmetic; blank input renders an em-dash.
	 *
	 * @param string $p Fixed-point probability string.
	 * @return string
	 */
	private static function pct( string $p ) : string {
		if ( '' === $p || ! is_numeric( $p ) ) {
			return '—';
		}
		return (string) round( (float) $p * 100 ) . '%';
	}

	/**
	 * Authenticated read-only headers for the hub fetch. Forwards a member bearer via the
	 * AIPA/gend.me OAuth bridge when available (else the read may 401 on the hub and fetch
	 * degrades to empty — never fatals). Mirrors class-collab-deck.php:hub_auth_headers().
	 *
	 * @return array
	 */
	private static function hub_auth_headers() : array {
		$headers = array( 'Accept' => 'application/json' );
		if ( class_exists( 'AIPA_GenD_OAuth' ) && method_exists( 'AIPA_GenD_OAuth', 'member_bearer' ) ) {
			$token = (string) AIPA_GenD_OAuth::member_bearer();
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}
		return $headers;
	}

	/**
	 * The hub base URL for this container. Mirrors class-collab-sync.php / class-collab-deck.php
	 * hub_url() VERBATIM: AIPA_GenD_OAuth::hub_url() (guarded) else the filtered gend.me default.
	 *
	 * @return string Hub base (no trailing slash), or '' if unresolvable.
	 */
	private static function hub_url() : string {
		if ( class_exists( 'AIPA_GenD_OAuth' ) && method_exists( 'AIPA_GenD_OAuth', 'hub_url' ) ) {
			return rtrim( (string) AIPA_GenD_OAuth::hub_url(), '/' );
		}
		return rtrim( (string) apply_filters( 'gend_cp_pm_sync_hub_url', 'https://gend.me' ), '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- foreign hook from contracts-and-payments.
	}
}
