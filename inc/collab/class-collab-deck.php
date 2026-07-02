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
	const META_OPTIN    = '_gs_collab_optin';
	const META_CATEGORY = '_gs_collab_category';
	const META_INDUSTRY = '_gs_collab_industry';
	const META_LOCATION = '_gs_collab_location';

	/**
	 * Build the swipe deck for $from_group.
	 *
	 * Deck = opted-in groups MINUS self MINUS already-decided, ranked
	 * complementary-first, optionally facet-filtered, sliced by $batch/$offset.
	 *
	 * @param int   $from_group Acting group id (swiping on its own behalf).
	 * @param array $facets     ['category'=>?, 'industry'=>?, 'location'=>?]; each
	 *                          optional. category/industry ignored unless a valid
	 *                          enum key; location is free-text (case-insensitive LIKE).
	 * @param int   $batch      Page size (default 15).
	 * @param int   $offset     Page offset into the RANKED result (default 0).
	 * @return array ['cards'=>array<int,array>, 'has_more'=>bool]. Empty deck →
	 *               ['cards'=>[], 'has_more'=>false] (SWIPE-06 rendering is the JS
	 *               layer's job; the engine returns an empty list, never loops,
	 *               never re-includes a decided card).
	 */
	public static function build_deck( int $from_group, array $facets = array(), int $batch = 15, int $offset = 0 ) : array {
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
		if ( ! is_array( $rows ) || empty( $rows ) ) {
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
			$card = self::assemble_card( $entry['group_id'], $entry['candidate'], (int) $entry['score'] );
			if ( null !== $card ) {
				$cards[] = $card;
			}
		}

		return array( 'cards' => $cards, 'has_more' => $has_more );
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
