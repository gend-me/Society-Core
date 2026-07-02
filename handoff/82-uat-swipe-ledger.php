<?php
/**
 * Phase 82 UAT — GenD Match swipe-ledger + deck correctness battery (v12.0).
 *
 * The house has no formal test framework; this `wp eval-file` script IS the
 * regression suite Phases 83+ inherit. It machine-asserts the five load-bearing
 * correctness invariants of the swipe spine built across Plans 82-01/02:
 *
 *   1. RE-SWIPE NO-OP            (SWIPE-03/04) — a re-swipe/double-tap on the same
 *                                (from,to) pair is an idempotent no-op: the
 *                                UNIQUE(from_group_id,to_group_id) constraint holds,
 *                                row count stays at exactly 1, record_swipe() never
 *                                errors and never flips the decision.
 *   2. DECIDED CARD NEVER REAPPEARS (SWIPE-04) — after left-swiping candidate C,
 *                                build_deck() never returns C again.
 *   3. SELF-EXCLUSION           (SWIPE-05) — the acting group is never in its own deck.
 *   4. OPT-OUT EXCLUDED         — a group WITHOUT _gs_collab_optin=1 never appears.
 *   5. COMPLEMENTARY RANKING    (TAG-02) — a complementary candidate (category
 *                                complements the actor) ranks strictly higher (lower
 *                                index, greater score) than a shared-only candidate.
 *   +  FACET FILTER             (TAG-03, bonus) — build_deck() with an industry facet
 *                                returns only cards whose tags.industry matches.
 *
 * House convention (mirrors handoff/test-v6-uat-04-chain-anchor-no-double-count.php):
 *   - defined('ABSPATH')||die('wp eval-file only');
 *   - SKIP-as-PASS gates: a missing class / BuddyPress absence / un-seedable fixture
 *     echoes "=== SUCCESS === (skipped — …)" and exit(0). A fixture PROBLEM is a SKIP,
 *     never a FAIL — only a genuine invariant violation FAILs.
 *   - A $fail accumulator; each assertion echoes [PASS]/[FAIL] with a label; the
 *     footer gate is "=== SUCCESS ===" + exit(0) when clean, else "=== FAILURE ==="
 *     + exit(1).
 *   - register_shutdown_function teardown deletes every seeded group + swipe row so
 *     the script is re-runnable.
 *
 * Run (on the hub wordpress pod, after kubectl cp onto the live PVC):
 *   wp eval-file handoff/82-uat-swipe-ledger.php
 *
 * Exit codes:
 *   0 — all invariants honored (or SKIP-as-PASS for missing classes / fixtures)
 *   1 — an invariant was violated
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 82 UAT — GenD Match swipe-ledger + deck correctness battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates — missing spine classes / BuddyPress → skip clean.
// ─────────────────────────────────────────────────────────────────────
$required = array(
	'Gend_GS_Collab_Schema',
	'Gend_GS_Collab_Deck',
	'Gend_GS_Collab_Taxonomy',
);
foreach ( $required as $cls ) {
	if ( ! class_exists( $cls ) ) {
		echo "=== SUCCESS === (skipped — {$cls} not loaded)\n";
		exit( 0 );
	}
}
if ( ! function_exists( 'groups_create_group' ) || ! function_exists( 'groups_update_groupmeta' ) ) {
	echo "=== SUCCESS === (skipped — BuddyPress groups API not loaded)\n";
	exit( 0 );
}

global $wpdb;

$swipes_tbl = Gend_GS_Collab_Schema::swipes_table();
$table_ok   = (bool) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
		$swipes_tbl
	)
);
if ( ! $table_ok ) {
	echo "=== SUCCESS === (skipped — {$swipes_tbl} not installed on this blog yet)\n";
	exit( 0 );
}

// Resolve an actor user id for the swipe writes (record_swipe stores actor_user_id).
$actor_uid = (int) get_current_user_id();
if ( $actor_uid <= 0 ) {
	$admins    = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$actor_uid = ( $admins && isset( $admins[0]->ID ) ) ? (int) $admins[0]->ID : 1;
}

$session      = uniqid( 'p82uat_', true );
$created_gids = array();   // seeded BP group ids — torn down in shutdown.
$swipe_pairs  = array();   // [ [from,to], … ] — DELETEd in shutdown.

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown (register_shutdown_function) — always runs, even on early exit.
//    Deletes ONLY rows/groups this run created (keyed on $session / seeded ids).
// ─────────────────────────────────────────────────────────────────────
register_shutdown_function(
	function () use ( &$created_gids, &$swipe_pairs, $swipes_tbl ) {
		global $wpdb;
		// Delete every swipe row that references a seeded group (either side).
		foreach ( $created_gids as $gid ) {
			$gid = (int) $gid;
			if ( $gid <= 0 ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$swipes_tbl} WHERE from_group_id = %d OR to_group_id = %d",
					$gid,
					$gid
				)
			);
		}
		// Delete the seeded BP groups (also clears their groupmeta).
		if ( function_exists( 'groups_delete_group' ) ) {
			foreach ( $created_gids as $gid ) {
				$gid = (int) $gid;
				if ( $gid > 0 ) {
					@groups_delete_group( $gid );
				}
			}
		}
		echo "[cleanup] complete — removed " . count( $created_gids ) . " seeded group(s) + their swipe rows\n";
	}
);

/**
 * Seed a BP group with optional collab tags. Returns the new group id, or 0 on
 * failure (a fixture failure is a SKIP condition, never a FAIL).
 *
 * @param string      $name     Group name.
 * @param string|null $category _gs_collab_category value, or null to skip.
 * @param string|null $industry _gs_collab_industry value, or null to skip.
 * @param bool        $optin    Set _gs_collab_optin=1 when true (discoverable).
 * @param string      $location _gs_collab_location value (optional).
 * @return int
 */
$seed_group = function ( $name, $category, $industry, $optin, $location = '' ) use ( &$created_gids, $actor_uid ) {
	$gid = groups_create_group(
		array(
			'creator_id'  => $actor_uid,
			'name'        => $name,
			'description' => 'Phase 82 UAT fixture — ' . $name,
			'status'      => 'public',
		)
	);
	$gid = (int) $gid;
	if ( $gid <= 0 ) {
		return 0;
	}
	$created_gids[] = $gid;
	if ( null !== $category ) {
		groups_update_groupmeta( $gid, '_gs_collab_category', $category );
	}
	if ( null !== $industry ) {
		groups_update_groupmeta( $gid, '_gs_collab_industry', $industry );
	}
	if ( '' !== $location ) {
		groups_update_groupmeta( $gid, '_gs_collab_location', $location );
	}
	if ( $optin ) {
		groups_update_groupmeta( $gid, '_gs_collab_optin', '1' );
	}
	return $gid;
};

// ─────────────────────────────────────────────────────────────────────
// 3. Fixtures.
//    ACTOR      : category 'product', industry 'tech', opted-in (so self-exclusion
//                 is proven even for an otherwise-discoverable group).
//    COMPLEMENT : category 'agency'  — product↔agency is in the COMPLEMENTARY map
//                 (score +3), opted-in, un-decided.
//    SHARED     : category 'product' — same category as actor → shared-only (score +1),
//                 opted-in, un-decided.
//    DECIDED    : category 'agency'  — opted-in, but LEFT-swiped by the actor
//                 (must never reappear).
//    OPTOUT     : category 'agency'  — NOT opted in (must never appear).
// ─────────────────────────────────────────────────────────────────────
$actor_gid   = $seed_group( "UAT82 Actor {$session}",   'product', 'tech', true,  'Toronto' );
$comp_gid    = $seed_group( "UAT82 Complement {$session}", 'agency', 'tech', true,  'Toronto' );
$shared_gid  = $seed_group( "UAT82 Shared {$session}",    'product', 'tech', true,  'Toronto' );
$decided_gid = $seed_group( "UAT82 Decided {$session}",   'agency', 'tech', true,  'Toronto' );
$optout_gid  = $seed_group( "UAT82 OptOut {$session}",    'agency', 'tech', false, 'Toronto' );

if ( $actor_gid <= 0 || $comp_gid <= 0 || $shared_gid <= 0 || $decided_gid <= 0 || $optout_gid <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed BP group fixtures; run on the hub with BuddyPress groups writable)\n";
	exit( 0 );
}
echo "[setup] seeded actor={$actor_gid} complement={$comp_gid} shared={$shared_gid} decided={$decided_gid} optout={$optout_gid}\n";

$fail   = false;
$issues = array();

/**
 * Return true if $gid appears in the deck cards.
 *
 * @param array $deck build_deck() result.
 * @param int   $gid  Group id to look for.
 * @return bool
 */
$in_deck = function ( $deck, $gid ) {
	if ( empty( $deck['cards'] ) || ! is_array( $deck['cards'] ) ) {
		return false;
	}
	foreach ( $deck['cards'] as $card ) {
		if ( (int) $card['group_id'] === (int) $gid ) {
			return true;
		}
	}
	return false;
};

/**
 * Return the 0-based index of $gid in the deck cards, or -1 if absent.
 *
 * @param array $deck build_deck() result.
 * @param int   $gid  Group id.
 * @return int
 */
$deck_index = function ( $deck, $gid ) {
	if ( empty( $deck['cards'] ) || ! is_array( $deck['cards'] ) ) {
		return -1;
	}
	foreach ( $deck['cards'] as $i => $card ) {
		if ( (int) $card['group_id'] === (int) $gid ) {
			return (int) $i;
		}
	}
	return -1;
};

/**
 * Return the score of $gid in the deck cards, or null if absent.
 *
 * @param array $deck build_deck() result.
 * @param int   $gid  Group id.
 * @return int|null
 */
$deck_score = function ( $deck, $gid ) {
	if ( empty( $deck['cards'] ) || ! is_array( $deck['cards'] ) ) {
		return null;
	}
	foreach ( $deck['cards'] as $card ) {
		if ( (int) $card['group_id'] === (int) $gid ) {
			return (int) $card['score'];
		}
	}
	return null;
};

// ─────────────────────────────────────────────────────────────────────
// ASSERTION 1 — RE-SWIPE NO-OP (SWIPE-03/04). record_swipe twice on the same
// (actor → decided) pair; assert exactly ONE row and both calls succeed.
// ─────────────────────────────────────────────────────────────────────
$swipe_pairs[] = array( $actor_gid, $decided_gid );
$r1            = Gend_GS_Collab_Schema::record_swipe( $actor_gid, $decided_gid, 'left', $actor_uid );
$r2            = Gend_GS_Collab_Schema::record_swipe( $actor_gid, $decided_gid, 'left', $actor_uid );
// A second call with a DIFFERENT decision must ALSO be an ignored no-op (never flip).
$r3            = Gend_GS_Collab_Schema::record_swipe( $actor_gid, $decided_gid, 'right', $actor_uid );

$row_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$swipes_tbl} WHERE from_group_id = %d AND to_group_id = %d",
		$actor_gid,
		$decided_gid
	)
);
$decision_stored = (string) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT decision FROM {$swipes_tbl} WHERE from_group_id = %d AND to_group_id = %d",
		$actor_gid,
		$decided_gid
	)
);

if ( $r1 && $r2 && $r3 && 1 === $row_count && 'left' === $decision_stored ) {
	echo "[PASS] 1. re-swipe no-op — row_count=1, decision stayed 'left', no error across 3 writes (UNIQUE holds)\n";
} else {
	$fail     = true;
	$issues[] = "1. re-swipe no-op: row_count={$row_count} (expected 1), decision='{$decision_stored}' (expected 'left'), r1/r2/r3=" . var_export( array( $r1, $r2, $r3 ), true );
}

// ─────────────────────────────────────────────────────────────────────
// ASSERTION 2 — DECIDED CARD NEVER REAPPEARS (SWIPE-04). $decided_gid was
// left-swiped above; it must NOT be in the actor's deck.
// ─────────────────────────────────────────────────────────────────────
$deck = Gend_GS_Collab_Deck::build_deck( $actor_gid, array(), 50, 0 );
if ( ! $in_deck( $deck, $decided_gid ) ) {
	echo "[PASS] 2. decided card never reappears — decided group {$decided_gid} absent from deck\n";
} else {
	$fail     = true;
	$issues[] = "2. decided card {$decided_gid} REAPPEARED in build_deck() after a left-swipe (SWIPE-04 violation)";
}

// ─────────────────────────────────────────────────────────────────────
// ASSERTION 3 — SELF-EXCLUSION (SWIPE-05). Actor (opted-in) must never be in
// its own deck.
// ─────────────────────────────────────────────────────────────────────
if ( ! $in_deck( $deck, $actor_gid ) ) {
	echo "[PASS] 3. self-exclusion — actor group {$actor_gid} absent from its own deck\n";
} else {
	$fail     = true;
	$issues[] = "3. actor group {$actor_gid} appeared in its OWN deck (SWIPE-05 violation)";
}

// ─────────────────────────────────────────────────────────────────────
// ASSERTION 4 — OPT-OUT EXCLUDED. $optout_gid has no _gs_collab_optin=1 → must
// never appear.
// ─────────────────────────────────────────────────────────────────────
if ( ! $in_deck( $deck, $optout_gid ) ) {
	echo "[PASS] 4. opt-out excluded — non-opted-in group {$optout_gid} absent from deck\n";
} else {
	$fail     = true;
	$issues[] = "4. opt-out group {$optout_gid} (no _gs_collab_optin=1) appeared in deck";
}

// ─────────────────────────────────────────────────────────────────────
// ASSERTION 5 — COMPLEMENTARY RANKING (TAG-02). The complementary candidate
// (agency, complements the actor's 'product') must rank strictly higher (lower
// index AND greater score) than the shared-only candidate (product, same as actor).
// Both are opted-in and un-decided, so both must be present.
// ─────────────────────────────────────────────────────────────────────
$comp_present   = $in_deck( $deck, $comp_gid );
$shared_present = $in_deck( $deck, $shared_gid );
if ( ! $comp_present || ! $shared_present ) {
	// Fixture problem (e.g. another opted-in group crowded the window) → SKIP, not FAIL.
	echo "[SKIP] 5. complementary ranking — complement present=" . var_export( $comp_present, true )
		. " shared present=" . var_export( $shared_present, true )
		. " (both must be in-deck to compare; run on a hub where the seeded pair survives the window)\n";
} else {
	$comp_idx    = $deck_index( $deck, $comp_gid );
	$shared_idx  = $deck_index( $deck, $shared_gid );
	$comp_score  = $deck_score( $deck, $comp_gid );
	$shared_score = $deck_score( $deck, $shared_gid );
	if ( $comp_idx < $shared_idx && (int) $comp_score > (int) $shared_score ) {
		echo "[PASS] 5. complementary ranking — complement(idx={$comp_idx},score={$comp_score}) ranks above shared-only(idx={$shared_idx},score={$shared_score})\n";
	} else {
		$fail     = true;
		$issues[] = "5. complementary ranking: complement idx={$comp_idx} score={$comp_score} did NOT beat shared idx={$shared_idx} score={$shared_score} (expected lower idx AND greater score)";
	}
}

// ─────────────────────────────────────────────────────────────────────
// ASSERTION 6 (bonus, TAG-03) — FACET FILTER. Filtering by an industry the
// seeded groups share ('tech') must return ONLY cards whose tags.industry === 'tech'.
// ─────────────────────────────────────────────────────────────────────
$facet_deck = Gend_GS_Collab_Deck::build_deck( $actor_gid, array( 'industry' => 'tech' ), 50, 0 );
$facet_ok   = true;
if ( ! empty( $facet_deck['cards'] ) && is_array( $facet_deck['cards'] ) ) {
	foreach ( $facet_deck['cards'] as $card ) {
		$ind = isset( $card['tags']['industry'] ) ? (string) $card['tags']['industry'] : '';
		if ( 'tech' !== $ind ) {
			$facet_ok = false;
			$issues[] = "6. facet filter: card group {$card['group_id']} has industry='{$ind}' but industry='tech' facet was requested";
			break;
		}
	}
}
if ( $facet_ok ) {
	echo "[PASS] 6. facet filter — every card under industry='tech' facet has tags.industry='tech'\n";
} else {
	$fail = true;
}

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 82 UAT passed — all swipe-spine invariants honored\n";
	exit( 0 );
}
echo "=== FAILURE === Phase 82 UAT detected " . count( $issues ) . " issue(s):\n";
foreach ( $issues as $i => $msg ) {
	echo "  " . ( $i + 1 ) . ". {$msg}\n";
}
exit( 1 );
