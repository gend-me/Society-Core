<?php
/**
 * gend-society — GenD Match rule-based tag taxonomy (Phase 82-01, v12.0).
 *
 * The fixed, curated starter taxonomy for the collab swipe deck (locked
 * decision: rule-based, NO AI). Two orthogonal enumerations —
 *   CATEGORIES  (business function, e.g. Product / Agency / Logistics)
 *   INDUSTRIES  (vertical, e.g. Technology / Healthcare / Retail)
 * plus a hardcoded COMPLEMENTARY pairing map that drives TAG-02 deck ranking
 * (complementary businesses rank strictly higher than merely-shared ones).
 *
 * Stored as PHP const arrays so the taxonomy is trivially editable + unit-
 * testable. This file is SILENT at include time — pure constants + static
 * methods, no hooks, no side effects.
 *
 * Consumers:
 *   - Plan 82-02 REST tag-set route: is_valid_category()/is_valid_industry()
 *     reject values not in the enum before writing groupmeta.
 *   - Plan 82-02 deck query: complementary_score() ranks the candidate rows;
 *     is_complementary() is the symmetric pairing predicate.
 *   - Plan 82-03 tag editor UI: CATEGORIES/INDUSTRIES render labelled selects.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Taxonomy {

	/**
	 * Starter CATEGORIES (business function). key => human label.
	 * The key is what is stored in the `_gs_collab_category` groupmeta;
	 * the label is what the tag editor renders in its <select>.
	 */
	const CATEGORIES = array(
		'product'       => 'Product / SaaS',
		'services'      => 'Professional Services',
		'agency'        => 'Marketing / Creative Agency',
		'manufacturing' => 'Manufacturing / Hardware',
		'retail'        => 'Retail / E-commerce',
		'logistics'     => 'Logistics / Distribution',
		'finance'       => 'Finance / Fintech',
		'consulting'    => 'Consulting / Advisory',
		'media'         => 'Media / Content',
		'nonprofit'     => 'Nonprofit / Community',
	);

	/**
	 * Starter INDUSTRIES (vertical) — orthogonal to CATEGORIES.
	 * key => human label so the tag editor can render a labelled select.
	 */
	const INDUSTRIES = array(
		'tech'          => 'Technology',
		'health'        => 'Healthcare',
		'realestate'    => 'Real Estate',
		'food_bev'      => 'Food & Beverage',
		'education'     => 'Education',
		'construction'  => 'Construction',
		'entertainment' => 'Entertainment',
		'energy'        => 'Energy',
		'travel'        => 'Travel',
		'fashion'       => 'Fashion',
		'automotive'    => 'Automotive',
		'other'         => 'Other',
	);

	/**
	 * Complementary-pairing map (TAG-02). actorCategory => [candidate
	 * categories that COMPLEMENT it]. The map is authored with DIRECTED keys
	 * for readability but MUST be treated as SYMMETRIC at lookup time — see
	 * is_complementary().
	 */
	const COMPLEMENTARY = array(
		'product'       => array( 'agency', 'services', 'logistics' ),  // product needs marketing + delivery
		'agency'        => array( 'product', 'retail', 'media' ),       // agency needs clients with things to sell
		'manufacturing' => array( 'logistics', 'retail', 'product' ),
		'retail'        => array( 'agency', 'logistics', 'finance' ),
		'logistics'     => array( 'manufacturing', 'retail', 'product' ),
		'finance'       => array( 'services', 'consulting', 'retail' ),
		'consulting'    => array( 'product', 'manufacturing', 'nonprofit' ),
		'media'         => array( 'agency', 'product', 'retail' ),
		'services'      => array( 'product', 'finance', 'manufacturing' ),
		'nonprofit'     => array( 'consulting', 'finance', 'media' ),
	);

	/**
	 * True if $k is a known category key. The tag-set REST route uses this to
	 * reject values not in the enum before writing groupmeta.
	 *
	 * @param string $k Category key.
	 * @return bool
	 */
	public static function is_valid_category( string $k ) : bool {
		return array_key_exists( $k, self::CATEGORIES );
	}

	/**
	 * True if $k is a known industry key.
	 *
	 * @param string $k Industry key.
	 * @return bool
	 */
	public static function is_valid_industry( string $k ) : bool {
		return array_key_exists( $k, self::INDUSTRIES );
	}

	/**
	 * SYMMETRIC complementary lookup: true if COMPLEMENTARY[$a] contains $b OR
	 * COMPLEMENTARY[$b] contains $a. (The map is directed keys but is treated
	 * as symmetric — a pairing complements in both directions.)
	 *
	 * @param string $a First category key.
	 * @param string $b Second category key.
	 * @return bool
	 */
	public static function is_complementary( string $a, string $b ) : bool {
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( isset( self::COMPLEMENTARY[ $a ] ) && in_array( $b, self::COMPLEMENTARY[ $a ], true ) ) {
			return true;
		}
		if ( isset( self::COMPLEMENTARY[ $b ] ) && in_array( $a, self::COMPLEMENTARY[ $b ], true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * TAG-02 ranking rule (consumed by Plan 82-02's deck). Higher = ranks
	 * higher in the deck. Complementary beats merely-shared:
	 *
	 *   +3  complementary CATEGORY  (strongest signal)
	 *   +2  complementary INDUSTRY
	 *   +1  shared category OR shared industry (weaker positive)
	 *   +1  same locale (candidate.location non-empty AND case-insensitively
	 *       equal to actor.location)
	 *
	 * $actor / $candidate are ['category'=>, 'industry'=>, 'location'=>]
	 * arrays (missing keys treated as '').
	 *
	 * @param array $actor     Acting group's tags.
	 * @param array $candidate Candidate group's tags.
	 * @return int
	 */
	public static function complementary_score( array $actor, array $candidate ) : int {
		$a_cat = isset( $actor['category'] ) ? (string) $actor['category'] : '';
		$a_ind = isset( $actor['industry'] ) ? (string) $actor['industry'] : '';
		$a_loc = isset( $actor['location'] ) ? (string) $actor['location'] : '';
		$c_cat = isset( $candidate['category'] ) ? (string) $candidate['category'] : '';
		$c_ind = isset( $candidate['industry'] ) ? (string) $candidate['industry'] : '';
		$c_loc = isset( $candidate['location'] ) ? (string) $candidate['location'] : '';

		$score = 0;

		if ( self::is_complementary( $a_cat, $c_cat ) ) {
			$score += 3;
		}
		if ( self::is_complementary( $a_ind, $c_ind ) ) {
			$score += 2;
		}
		if ( ( '' !== $a_cat && $a_cat === $c_cat ) || ( '' !== $a_ind && $a_ind === $c_ind ) ) {
			$score += 1;
		}
		if ( '' !== $c_loc && 0 === strcasecmp( $a_loc, $c_loc ) ) {
			$score += 1;
		}

		return $score;
	}
}
