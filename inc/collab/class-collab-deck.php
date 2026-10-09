<?php
/**
 * gend-society — GenD Match deck query engine (Phase 82-02, v12.0).
 *
 * Builds the swipe deck the Match UI consumes: the set of OTHER business groups
 * an acting group has NOT yet decided on, ranked complementary-first and
 * optionally facet-filtered, with each candidate assembled into a BP-native card.
 *
 * The three load-bearing correctness facts (all proven at query time here):
 *   SWIPE-05  self-exclusion   — WHERE g.id != :from_group.
 *   SWIPE-04  never re-show     — g.id NOT IN (SELECT to_group_id FROM the ledger
 *                                WHERE from_group_id = :from_group). The ledger
 *                                (Plan 82-01) is authoritative; a decided card
 *                                (right OR left) can never re-enter the deck.
 *   opt-in only               — INNER JOIN the groupmeta row _gs_collab_optin='1';
 *                                a group is discoverable ONLY after its admin flips
 *                                the "open to collaboration" switch (locked decision).
 *
 * TAG-02 (complementary ranking) is computed in PHP — Gend_GS_Collab_Taxonomy::
 * complementary_score() — NOT in SQL, so the pairing map stays in one editable PHP
 * array (per RESEARCH; avoids a gnarly SQL CASE). We over-fetch a candidate window,
 * rank in PHP with usort (score DESC, stable tiebreak on group id DESC — mirrors
 * calendar-events-rest.php:101), then paginate the RANKED result by $batch/$offset.
 *
 * TAG-03 facets (category / industry / location) are optional WHERE clauses applied
 * ONLY when the facet param is non-empty; category/industry are validated against the
 * taxonomy enum (an invalid facet is ignored, never an error); location is a
 * case-insensitive free-text LIKE.
 *
 * This class is SILENT at include time — pure static methods, no hooks, no side
 * effects. It has NO REST binding of its own; Plan 82-02's REST class loads it and
 * calls build_deck(). It never writes anything (no swipe row, and — Tier A — never
 * touches gs_collab_matches; that is Phase 83).
 *
 * Consumers:
 *   - Gend_GS_Collab_REST::route_deck  → build_deck()
 *   - Gend_GS_Collab_REST::route_get_tags / ranking → actor_tags()
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Deck {

	/**
	 * Upper bound on the candidate window we over-fetch for PHP-side ranking.
	 * TAG-02 ranking is computed in PHP (not SQL), so we pull up to this many
	 * eligible ids, rank them all, THEN paginate. Fine at v1 scale (0–1k groups);
	 * see RESEARCH Pitfall 5 for the scaling story if the pool grows.
	 */
	const RANK_WINDOW = 200;

	/**
	 * Groupmeta keys — the rule-based tag store TAG-01 seeds. Kept here as the
	 * single source of truth for the deck query + card assembly + actor_tags().
	 */
	const META_OPTIN    = '_gend_society_collab_optin';
	const META_CATEGORY = '_gend_society_collab_category';
	const META_INDUSTRY = '_gend_society_collab_industry';
	const META_LOCATION = '_gend_society_collab_location';

	/**
	 * Build the swipe deck for $from_group — the FED-01 (Phase 89) federation-aware
	 * dispatcher.
	 *
	 *   HUB (is_hub()): the deck is the FULL cross-app pool — local opted-in BP groups
	 *     UNION opted-in federated container businesses (gs_collab_federated_business,
	 *     optin=1). No fetch needed (the hub IS the source of truth).
	 *   CONTAINER (! is_hub()): fetch the hub's cross-app deck (cached, short TTL) and
	 *     GRACEFULLY FALL BACK to the LOCAL Phase-82 deck (build_deck_local) on any hub
	 *     failure — so swiping ALWAYS works, hub up or down (P2, load-bearing).
	 *
	 * The Phase-82 behaviour is preserved verbatim in build_deck_local() (the standalone
	 * local query is ALSO the container fallback). Callers are unchanged.
	 *
	 * @param int   $from_group Acting group id (swiping on its own behalf).
	 * @param array $facets     ['category'=>?, 'industry'=>?, 'location'=>?]; each
	 *                          optional. category/industry ignored unless a valid
	 *                          enum key; location is free-text (case-insensitive LIKE).
	 * @param int   $batch      Page size (default 15).
	 * @param int   $offset     Page offset into the RANKED result (default 0).
	 * @return array ['cards'=>array<int,array>, 'has_more'=>bool].
	 */
	public static function build_deck( int $from_group, array $facets = array(), int $batch = 15, int $offset = 0 ) : array {
		if ( self::is_hub() ) {
			return self::build_deck_hub_pool( $from_group, $facets, $batch, $offset );
		}
		// Container: cross-app deck from the hub, with a local fallback baked in.
		return self::fetch_cross_app_deck( $from_group, $facets, $batch, $offset );
	}

	/**
	 * The hub-side FULL-POOL deck: the standalone local query PLUS the federated
	 * container businesses (optin=1) merged in BEFORE the complementary usort so
	 * never-re-show / self-exclusion / rank apply uniformly. On a lone hub with no
	 * federated rows this is byte-for-byte the Phase-82 deck.
	 *
	 * @param int   $from_group Acting group id.
	 * @param array $facets     Facet filters.
	 * @param int   $batch      Page size.
	 * @param int   $offset     Page offset.
	 * @return array ['cards'=>array,'has_more'=>bool]
	 */
	private static function build_deck_hub_pool( int $from_group, array $facets, int $batch, int $offset ) : array {
		return self::build_deck_local( $from_group, $facets, $batch, $offset, true );
	}

	/**
	 * The Phase-82 STANDALONE deck query — opted-in BP groups MINUS self MINUS
	 * already-decided, complementary-ranked, facet-filtered, paginated. This is ALSO
	 * the container's local fallback (it needs only the local swipes table + local BP
	 * groups). When $with_federated is true (hub only) opted-in federated container
	 * businesses are merged into the scored candidate set before the usort.
	 *
	 * @param int   $from_group     Acting group id (swiping on its own behalf).
	 * @param array $facets         Facet filters.
	 * @param int   $batch          Page size (default 15).
	 * @param int   $offset         Page offset into the RANKED result (default 0).
	 * @param bool  $with_federated Merge gs_collab_federated_business (hub only).
	 * @return array ['cards'=>array<int,array>, 'has_more'=>bool]. Empty deck →
	 *               ['cards'=>[], 'has_more'=>false] (SWIPE-06 rendering is the JS
	 *               layer's job; the engine returns an empty list, never loops,
	 *               never re-includes a decided card).
	 */
	public static function build_deck_local( int $from_group, array $facets = array(), int $batch = 15, int $offset = 0, bool $with_federated = false ) : array {
		global $wpdb;

		$from_group = (int) $from_group;
		$batch      = $batch > 0 ? (int) $batch : 15;
		$offset     = $offset > 0 ? (int) $offset : 0;
		if ( $from_group <= 0 ) {
			return array( 'cards' => array(), 'has_more' => false );
		}

		// BP raw table names — schema-name-safe (Open Question 1). NEVER hardcode
		// 'bp_groups'; on some installs the prefix/table differs.
		$bp = function_exists( 'buddypress' ) ? buddypress() : null;
		if ( ! $bp || empty( $bp->groups ) || empty( $bp->groups->table_name ) || empty( $bp->groups->table_name_groupmeta ) ) {
			return array( 'cards' => array(), 'has_more' => false );
		}
		$groups_tbl    = $bp->groups->table_name;
		$groupmeta_tbl = $bp->groups->table_name_groupmeta;
		$swipes_tbl    = Gend_GS_Collab_Schema::swipes_table();

		// Sanitize / validate facets. An invalid category/industry is IGNORED
		// (dropped), never an error (locked decision / TAG-03).
		$f_category = isset( $facets['category'] ) ? (string) $facets['category'] : '';
		$f_industry = isset( $facets['industry'] ) ? (string) $facets['industry'] : '';
		$f_location = isset( $facets['location'] ) ? trim( (string) $facets['location'] ) : '';
		if ( '' !== $f_category && ! Gend_GS_Collab_Taxonomy::is_valid_category( $f_category ) ) {
			$f_category = '';
		}
		if ( '' !== $f_industry && ! Gend_GS_Collab_Taxonomy::is_valid_industry( $f_industry ) ) {
			$f_industry = '';
		}

		// Build the eligibility query. INNER JOIN opt-in (only opted-in groups are
		// discoverable); LEFT JOIN the three tag metas (facet-filter + returned tags).
		// All params bound via $wpdb->prepare.
		$where  = array();
		$params = array();

		// SWIPE-05 self-exclusion.
		$where[]  = 'g.id != %d';
		$params[] = $from_group;

		// SWIPE-04 never re-show — the ledger is authoritative. A decided (from,to)
		// pair (right OR left) is excluded via the NOT IN subquery.
		$where[]  = "g.id NOT IN ( SELECT s.to_group_id FROM {$swipes_tbl} s WHERE s.from_group_id = %d )";
		$params[] = $from_group;

		// TAG-03 facets — applied ONLY when the param is non-empty.
		if ( '' !== $f_category ) {
			$where[]  = 'cat.meta_value = %s';
			$params[] = $f_category;
		}
		if ( '' !== $f_industry ) {
			$where[]  = 'ind.meta_value = %s';
			$params[] = $f_industry;
		}
		if ( '' !== $f_location ) {
			$where[]  = 'loc.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $f_location ) . '%';
		}

		$where_sql = implode( "\n  AND ", $where );

		// Over-fetch the ranking window; ORDER BY g.id DESC only (final order is the
		// PHP complementary rank below). LIMIT keeps the window bounded at v1 scale.
		$sql = "
			SELECT g.id AS group_id,
			       cat.meta_value AS cat,
			       ind.meta_value AS ind,
			       loc.meta_value AS loc
			FROM {$groups_tbl} g
			INNER JOIN {$groupmeta_tbl} optin
			        ON optin.group_id = g.id
			       AND optin.meta_key = %s
			       AND optin.meta_value = %s
			LEFT JOIN {$groupmeta_tbl} cat
			       ON cat.group_id = g.id AND cat.meta_key = %s
			LEFT JOIN {$groupmeta_tbl} ind
			       ON ind.group_id = g.id AND ind.meta_key = %s
			LEFT JOIN {$groupmeta_tbl} loc
			       ON loc.group_id = g.id AND loc.meta_key = %s
			WHERE {$where_sql}
			ORDER BY g.id DESC
			LIMIT %d
		";

		// Prepend the JOIN-clause params (opt-in key/value + the 3 meta keys),
		// then the WHERE params, then the LIMIT window.
		$join_params = array(
			self::META_OPTIN, '1',
			self::META_CATEGORY,
			self::META_INDUSTRY,
			self::META_LOCATION,
		);
		$all_params = array_merge( $join_params, $params, array( self::RANK_WINDOW ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		// Do NOT early-return on an empty NATIVE result when the hub federated arm is
		// active — opted-in federated businesses (merged below) may still supply cards
		// even when the viewer has no local complementary BP groups. Only short-circuit
		// when there is genuinely nothing to add (container / native-only path).
		if ( empty( $rows ) && ! $with_federated ) {
			return array( 'cards' => array(), 'has_more' => false );
		}

		// TAG-02 ranking (PHP). Load the actor's tags once, score each candidate.
		$actor = self::actor_tags( $from_group );

		$scored = array();
		foreach ( $rows as $row ) {
			$gid = (int) $row['group_id'];
			if ( $gid <= 0 ) {
				continue;
			}
			$candidate = array(
				'category' => (string) ( $row['cat'] ?? '' ),
				'industry' => (string) ( $row['ind'] ?? '' ),
				'location' => (string) ( $row['loc'] ?? '' ),
			);
			$scored[] = array(
				'group_id'  => $gid,
				'score'     => (int) Gend_GS_Collab_Taxonomy::complementary_score( $actor, $candidate ),
				'candidate' => $candidate,
			);
		}

		// FED-01 (hub only): merge opted-in federated container businesses into the
		// scored set BEFORE the usort so never-re-show / self-exclusion / complementary
		// rank apply uniformly across the cross-app pool.
		if ( $with_federated ) {
			$scored = self::merge_federated_candidates( $from_group, $scored, $actor );
		}

		// Sort score DESC, stable tiebreak on group id DESC (mirror
		// calendar-events-rest.php:101 usort idiom).
		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				return $b['group_id'] <=> $a['group_id'];
			}
		);

		$total    = count( $scored );
		$has_more = ( $offset + $batch ) < $total;
		$page     = array_slice( $scored, $offset, $batch );

		$cards = array();
		foreach ( $page as $entry ) {
			// FED-01: a federated candidate carries its OWN card fields (its name/tagline
			// live on the shadow row, not on a hub BP group) but still swipes to its
			// hub_group_id. Native candidates assemble from the BP group as before.
			if ( ! empty( $entry['federated'] ) && is_array( $entry['federated'] ) ) {
				$cards[] = self::assemble_federated_card( (int) $entry['group_id'], $entry['federated'], $entry['candidate'], (int) $entry['score'] );
				continue;
			}
			$card = self::assemble_card( $entry['group_id'], $entry['candidate'], (int) $entry['score'] );
			if ( null !== $card ) {
				$cards[] = $card;
			}
		}

		return array( 'cards' => $cards, 'has_more' => $has_more );
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
	 * FED-01 (hub only) — merge opted-in federated container businesses into the scored
	 * candidate set. Each gs_collab_federated_business row (optin=1) is mapped to its
	 * hub_group_id as the matchable id but carries its OWN name/tagline/tags for the
	 * card. Skipped if (a) the hub_group_id is already present in $scored (dedupe against
	 * a native BP group) OR (b) the (from_group, hub_group_id) pair is already decided in
	 * the ledger (the SAME never-re-show guard the native query uses). Scored with the
	 * SAME complementary_score so ranking is uniform.
	 *
	 * @param int   $from_group Acting group id.
	 * @param array $scored     The native scored set (mutated + returned).
	 * @param array $actor      The actor's tags (for complementary scoring).
	 * @return array The merged scored set.
	 */
	private static function merge_federated_candidates( int $from_group, array $scored, array $actor ) : array {
		global $wpdb;

		if ( ! class_exists( 'Gend_GS_Collab_Schema' )
			|| ! method_exists( 'Gend_GS_Collab_Schema', 'federated_business_table' )
			|| ! method_exists( 'Gend_GS_Collab_Schema', 'swipes_table' ) ) {
			return $scored;
		}

		$fb_tbl     = Gend_GS_Collab_Schema::federated_business_table();
		$swipes_tbl = Gend_GS_Collab_Schema::swipes_table();

		// Never-re-show: exclude any hub_group_id already decided by this actor (same
		// guard as the native query at :126). Self-exclusion via hub_group_id != from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hub_group_id, name, tagline, avatar_url, category, industry, location
				 FROM {$fb_tbl}
				 WHERE optin = 1
				   AND hub_group_id > 0
				   AND hub_group_id != %d
				   AND hub_group_id NOT IN ( SELECT s.to_group_id FROM {$swipes_tbl} s WHERE s.from_group_id = %d )",
				$from_group,
				$from_group
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $scored;
		}

		// Dedupe against native BP-group candidates already in $scored.
		$seen = array();
		foreach ( $scored as $entry ) {
			$seen[ (int) $entry['group_id'] ] = true;
		}

		foreach ( $rows as $row ) {
			$gid = (int) $row['hub_group_id'];
			if ( $gid <= 0 || isset( $seen[ $gid ] ) ) {
				continue; // already in the pool as a native group (dedupe).
			}
			$seen[ $gid ] = true;
			$candidate = array(
				'category' => (string) ( $row['category'] ?? '' ),
				'industry' => (string) ( $row['industry'] ?? '' ),
				'location' => (string) ( $row['location'] ?? '' ),
			);
			$scored[] = array(
				'group_id'  => $gid,
				'score'     => (int) Gend_GS_Collab_Taxonomy::complementary_score( $actor, $candidate ),
				'candidate' => $candidate,
				'federated' => array(
					'name'       => (string) ( $row['name'] ?? '' ),
					'tagline'    => (string) ( $row['tagline'] ?? '' ),
					'avatar_url' => (string) ( $row['avatar_url'] ?? '' ),
				),
			);
		}

		return $scored;
	}

	/**
	 * Assemble a swipe card for a FEDERATED container business — its display fields come
	 * from the shadow row (not a hub BP group), but group_id is the matchable
	 * hub_group_id so a right-swipe resolves through the normal ledger/match path.
	 *
	 * @param int   $hub_group_id The matchable hub group id.
	 * @param array $fed          ['name','tagline','avatar_url'] from the shadow row.
	 * @param array $tags         ['category','industry','location'].
	 * @param int   $score        Complementary score.
	 * @return array Card array.
	 */
	private static function assemble_federated_card( int $hub_group_id, array $fed, array $tags, int $score ) : array {
		return array(
			'group_id'  => (int) $hub_group_id,
			'name'      => (string) ( $fed['name'] ?? '' ),
			'tagline'   => wp_trim_words( (string) ( $fed['tagline'] ?? '' ), 24 ),
			'avatar'    => (string) ( $fed['avatar_url'] ?? '' ),
			'permalink' => '',
			'tags'      => array(
				'category' => (string) ( $tags['category'] ?? '' ),
				'industry' => (string) ( $tags['industry'] ?? '' ),
				'location' => (string) ( $tags['location'] ?? '' ),
			),
			'score'     => (int) $score,
			'federated' => true,
		);
	}

	/**
	 * FED-01 (container only) — fetch the hub's cross-app deck (read-only, cached with a
	 * short TTL) and GRACEFULLY FALL BACK to the LOCAL Phase-82 deck on ANY hub failure
	 * (WP_Error / non-2xx / no hub URL / unreachable). P2, load-bearing: swiping works
	 * hub up OR down. The fallback calls build_deck_local() directly (never build_deck)
	 * so there is NO recursion.
	 *
	 * Uses a bounded (~3s) authenticated member read against the hub deck route (member-
	 * scoped, per 89-RESEARCH Open-Q2: an authed gs/v1 read, NOT the machine rail).
	 *
	 * @param int   $from_group Acting group id.
	 * @param array $facets     Facet filters.
	 * @param int   $batch      Page size.
	 * @param int   $offset     Page offset.
	 * @return array ['cards'=>array,'has_more'=>bool]
	 */
	public static function fetch_cross_app_deck( int $from_group, array $facets = array(), int $batch = 15, int $offset = 0 ) : array {
		$hub = self::hub_url();
		if ( '' === $hub ) {
			return self::build_deck_local( $from_group, $facets, $batch, $offset ); // no hub -> local.
		}

		$cache_key = 'gend_society_collab_xdeck_' . (int) $from_group . '_' . md5( wp_json_encode( $facets ) . '|' . (int) $batch . '|' . (int) $offset );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$args = array(
			'category' => isset( $facets['category'] ) ? (string) $facets['category'] : '',
			'industry' => isset( $facets['industry'] ) ? (string) $facets['industry'] : '',
			'location' => isset( $facets['location'] ) ? (string) $facets['location'] : '',
			'group_id' => (int) $from_group,
			'batch'    => (int) $batch,
			'offset'   => (int) $offset,
		);
		$url = add_query_arg( array_filter( $args, static function ( $v ) { return '' !== $v && 0 !== $v; } ), $hub . '/wp-json/gs/v1/collab/deck' );

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'   => 3,
				'headers'   => self::hub_auth_headers(),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return self::build_deck_local( $from_group, $facets, $batch, $offset ); // unreachable -> local.
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return self::build_deck_local( $from_group, $facets, $batch, $offset ); // non-2xx -> local.
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['cards'] ) || ! is_array( $decoded['cards'] ) ) {
			return self::build_deck_local( $from_group, $facets, $batch, $offset ); // malformed -> local.
		}

		$deck = array(
			'cards'    => $decoded['cards'],
			'has_more' => ! empty( $decoded['has_more'] ),
		);
		set_transient( $cache_key, $deck, 90 ); // short TTL; a fresh swipe removes the card client-side.
		return $deck;
	}

	/**
	 * Best-effort auth headers for the container->hub member deck read. If the container
	 * holds a member bearer via the AIPA/gend.me OAuth bridge, forward it; otherwise an
	 * unauthenticated read may 401 on the hub and fetch_cross_app_deck falls back to the
	 * LOCAL deck — swiping still works. Never fatals.
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
	 * The hub base URL for this container (mirrors class-pm-sync-push.php:262-267).
	 *
	 * @return string Hub base (no trailing slash), or '' if unresolvable.
	 */
	private static function hub_url() : string {
		if ( class_exists( 'AIPA_GenD_OAuth' ) && method_exists( 'AIPA_GenD_OAuth', 'hub_url' ) ) {
			return rtrim( (string) AIPA_GenD_OAuth::hub_url(), '/' );
		}
		return rtrim( (string) apply_filters( 'gend_cp_pm_sync_hub_url', 'https://gend.me' ), '/' );
	}

	/**
	 * Assemble one BP-native swipe card (SWIPE-02): name, avatar, tagline, tags.
	 *
	 * @param int   $gid        Candidate group id.
	 * @param array $tags       ['category'=>,'industry'=>,'location'=>] already read
	 *                          from the deck query row (avoids a re-read).
	 * @param int   $score      The candidate's complementary score (debug/ordering aid).
	 * @return array|null Card array, or null if the group can't be loaded.
	 */
	private static function assemble_card( int $gid, array $tags, int $score ) : ?array {
		if ( ! function_exists( 'groups_get_group' ) ) {
			return null;
		}
		$group = groups_get_group( $gid );
		if ( empty( $group ) || empty( $group->id ) ) {
			return null;
		}

		$avatar = function_exists( 'bp_core_fetch_avatar' )
			? bp_core_fetch_avatar( array(
				'item_id' => $gid,
				'object'  => 'group',
				'type'    => 'full',
				'html'    => false,
			) )
			: '';

		return array(
			'group_id'  => (int) $gid,
			'name'      => function_exists( 'bp_get_group_name' ) ? bp_get_group_name( $group ) : '',
			'tagline'   => function_exists( 'bp_get_group_description' )
				? wp_trim_words( bp_get_group_description( $group ), 24 )
				: '',
			'avatar'    => $avatar,
			'permalink' => function_exists( 'bp_get_group_permalink' ) ? bp_get_group_permalink( $group ) : '',
			'tags'      => array(
				'category' => (string) ( $tags['category'] ?? '' ),
				'industry' => (string) ( $tags['industry'] ?? '' ),
				'location' => (string) ( $tags['location'] ?? '' ),
			),
			'score'     => (int) $score,
		);
	}

	/**
	 * The acting group's own tags, read from groupmeta. Reused by build_deck()'s
	 * ranking and by the REST tags GET route.
	 *
	 * @param int $gid Group id.
	 * @return array ['category'=>string,'industry'=>string,'location'=>string]
	 */
	public static function actor_tags( int $gid ) : array {
		$gid = (int) $gid;
		if ( $gid <= 0 || ! function_exists( 'groups_get_groupmeta' ) ) {
			return array( 'category' => '', 'industry' => '', 'location' => '' );
		}
		return array(
			'category' => (string) groups_get_groupmeta( $gid, self::META_CATEGORY, true ),
			'industry' => (string) groups_get_groupmeta( $gid, self::META_INDUSTRY, true ),
			'location' => (string) groups_get_groupmeta( $gid, self::META_LOCATION, true ),
		);
	}
}
