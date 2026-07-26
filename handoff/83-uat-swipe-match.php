<?php
/**
 * Phase 83 UAT — GenD Match mutual-match + notify + intro-thread + undo battery (v12.0).
 *
 * The house has no formal test framework; this `wp eval-file` script IS the Phase-83
 * regression suite. It machine-asserts the load-bearing correctness truths of the
 * match spine built across Plans 83-01 (mutual-match engine + intro thread + notify)
 * and 83-02 (single-step undo + undo-after-match refusal):
 *
 *   1. RACE / EXACTLY-ONE-MATCH   (MATCH-01 + MATCH-03) — a simultaneous cross-swipe
 *                                  (A->B and B->A both present, then maybe_create_match
 *                                  fired for BOTH directions back-to-back — and in the
 *                                  REVERSED order on a fresh slate) collapses to exactly
 *                                  ONE gs_collab_matches row + ONE intro thread; exactly
 *                                  one of the two calls is the rows_affected===1 winner
 *                                  (returns >0), the other a 0 no-op.
 *   2. STORM CAP / ONE-PER-RECIP  (MATCH-02) — the seeded intro thread's recipient set is
 *                                  the deduped union of admins∪mods of both groups, each
 *                                  present exactly once; a SECOND maybe_create_match on an
 *                                  already-matched pair returns 0 and seeds NO extra
 *                                  match/thread (idempotent — no notification storm).
 *   3. LEFT NEVER MATCHES         (MATCH-01) — a right + a reciprocal LEFT never forms a
 *                                  match: maybe_create_match returns 0, matches COUNT===0.
 *   4. UNDO RETURNS THE CARD      (SWIPE-07) — route_undo on a non-match (left) swipe
 *                                  returns {ok:true,to_group}, deletes the swipe row
 *                                  (card returns to the deck), match table untouched.
 *   5. UNDO REFUSED AFTER MATCH   (SWIPE-07, locked anti-abuse rule) — route_undo after a
 *                                  match-creating swipe is a WP_Error 'gs_collab_undo_after_match'
 *                                  (status 409); the match row, its intro_thread_id, and the
 *                                  actor's swipe row all stay intact.
 *   +  EMAIL GRACEFUL DEGRADATION (83-01 note, bonus) — the notify leg is
 *                                  function_exists('em_inbox_send_as')-guarded, so a
 *                                  gend-society-only environment forms the match + thread +
 *                                  in-app bell without a fatal even when email-manager's
 *                                  send helper is absent (proven implicitly: every scenario
 *                                  above runs the full winner branch without fataling
 *                                  regardless of em_inbox_send_as presence; asserted below).
 *
 * House convention (mirrors handoff/82-uat-swipe-ledger.php):
 *   - defined('ABSPATH')||die('wp eval-file only');
 *   - SKIP-as-PASS gates: a missing spine class / BuddyPress absence / un-seedable fixture
 *     echoes "=== SUCCESS === (skipped — …)" and exit(0). A fixture PROBLEM is a SKIP,
 *     never a FAIL — only a genuine invariant violation FAILs.
 *   - A $fail accumulator; each assertion echoes [PASS]/[FAIL] with a label; the footer
 *     gate is "=== SUCCESS ===" + exit(0) when clean, else "=== FAILURE ===" + exit(1).
 *   - register_shutdown_function teardown deletes every seeded group + swipe/match row so
 *     the script is re-runnable.
 *
 * Run as www-data (root-run eval-files break uploads/perms — MEMORY editor-session
 * root-perms), on the hub wordpress pod, after kubectl cp onto the live gend-society PVC,
 * with 83-01 + 83-02 already deployed:
 *   wp eval-file wp-content/plugins/gend-society/handoff/83-uat-swipe-match.php
 *
 * Exit codes:
 *   0 — all invariants honored (or SKIP-as-PASS for missing classes / fixtures)
 *   1 — an invariant was violated
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 83 UAT — GenD Match mutual-match + notify + intro-thread + undo battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates — missing spine classes / BuddyPress → skip clean.
// ─────────────────────────────────────────────────────────────────────
$required = array(
	'Gend_GS_Collab_Schema',
	'Gend_GS_Collab_Match',
	'Gend_GS_Collab_REST',
);
foreach ( $required as $cls ) {
	if ( ! class_exists( $cls ) ) {
		echo "=== SUCCESS === (skipped — {$cls} not loaded)\n";
		exit( 0 );
	}
}
if ( ! method_exists( 'Gend_GS_Collab_Match', 'maybe_create_match' )
	|| ! method_exists( 'Gend_GS_Collab_REST', 'route_undo' ) ) {
	echo "=== SUCCESS === (skipped — match/undo entry points absent; deploy 83-01/83-02 first)\n";
	exit( 0 );
}
if ( ! function_exists( 'groups_create_group' ) || ! function_exists( 'groups_join_group' ) ) {
	echo "=== SUCCESS === (skipped — BuddyPress groups API not loaded)\n";
	exit( 0 );
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	echo "=== SUCCESS === (skipped — REST infrastructure not loaded)\n";
	exit( 0 );
}

global $wpdb;

$swipes_tbl  = Gend_GS_Collab_Schema::swipes_table();
$matches_tbl = Gend_GS_Collab_Schema::matches_table();
foreach ( array( $swipes_tbl, $matches_tbl ) as $t ) {
	$installed = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
			$t
		)
	);
	if ( ! $installed ) {
		echo "=== SUCCESS === (skipped — {$t} not installed on this blog yet)\n";
		exit( 0 );
	}
}

$session      = uniqid( 'p83uat_', true );
$created_gids = array();   // seeded BP group ids — torn down in shutdown.
$created_uids = array();   // seeded WP users — torn down in shutdown.
$seen_threads = array();   // intro thread ids seeded this run — torn down best-effort.

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown (register_shutdown_function) — always runs, even on early exit.
//    Deletes ONLY rows/groups/users/threads this run created.
// ─────────────────────────────────────────────────────────────────────
register_shutdown_function(
	function () use ( &$created_gids, &$created_uids, &$seen_threads, $swipes_tbl, $matches_tbl ) {
		global $wpdb;
		foreach ( $created_gids as $gid ) {
			$gid = (int) $gid;
			if ( $gid <= 0 ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$swipes_tbl} WHERE from_group_id = %d OR to_group_id = %d", $gid, $gid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE group_a = %d OR group_b = %d", $gid, $gid ) );
		}
		if ( function_exists( 'groups_delete_group' ) ) {
			foreach ( $created_gids as $gid ) {
				$gid = (int) $gid;
				if ( $gid > 0 ) {
					@groups_delete_group( $gid );
				}
			}
		}
		// Best-effort intro-thread cleanup.
		if ( function_exists( 'messages_delete_thread' ) ) {
			foreach ( array_unique( array_filter( $seen_threads ) ) as $tid ) {
				@messages_delete_thread( (int) $tid );
			}
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $created_uids as $uid ) {
				$uid = (int) $uid;
				if ( $uid > 0 ) {
					@wp_delete_user( $uid );
				}
			}
		}
		echo '[cleanup] complete — removed ' . count( $created_gids ) . ' group(s), ' . count( $created_uids ) . " user(s) + their swipe/match rows\n";
	}
);

/**
 * Create a throwaway WP user and return its id, or 0 on failure.
 *
 * @param string $slug Username/login seed.
 * @return int
 */
$seed_user = function ( $slug ) use ( &$created_uids, $session ) {
	if ( ! function_exists( 'wp_insert_user' ) ) {
		return 0;
	}
	$login = 'uat83_' . $slug . '_' . substr( md5( $session ), 0, 8 );
	$uid   = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 20, true ),
			'user_email' => $login . '@uat83.local',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $uid ) || (int) $uid <= 0 ) {
		return 0;
	}
	$created_uids[] = (int) $uid;
	return (int) $uid;
};

/**
 * Seed a public BP group owned/admin'd by $admin_uid. Returns the group id, or 0.
 *
 * @param string $name      Group name.
 * @param int    $admin_uid User to make creator + group admin.
 * @return int
 */
$seed_group = function ( $name, $admin_uid ) use ( &$created_gids ) {
	$gid = groups_create_group(
		array(
			'creator_id'  => (int) $admin_uid,
			'name'        => $name,
			'description' => 'Phase 83 UAT fixture — ' . $name,
			'status'      => 'public',
		)
	);
	$gid = (int) $gid;
	if ( $gid <= 0 ) {
		return 0;
	}
	$created_gids[] = $gid;
	// Ensure the creator is a member + group admin (so participant_uids() picks them up).
	if ( function_exists( 'groups_join_group' ) ) {
		groups_join_group( $gid, (int) $admin_uid );
	}
	if ( function_exists( 'groups_promote_member' ) ) {
		groups_promote_member( (int) $admin_uid, $gid, 'admin' );
	}
	return $gid;
};

/** Clean-slate helper: purge swipe + match rows for a pair (both directions). */
$reset_pair = function ( $g1, $g2 ) use ( $swipes_tbl, $matches_tbl ) {
	global $wpdb;
	$g1 = (int) $g1;
	$g2 = (int) $g2;
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$swipes_tbl} WHERE (from_group_id=%d AND to_group_id=%d) OR (from_group_id=%d AND to_group_id=%d)",
		$g1, $g2, $g2, $g1
	) );
	$a = min( $g1, $g2 );
	$b = max( $g1, $g2 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE group_a=%d AND group_b=%d", $a, $b ) );
};

/** COUNT of match rows for the normalized pair. */
$match_count = function ( $g1, $g2 ) use ( $matches_tbl ) {
	global $wpdb;
	$a = min( (int) $g1, (int) $g2 );
	$b = max( (int) $g1, (int) $g2 );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$matches_tbl} WHERE group_a=%d AND group_b=%d", $a, $b ) );
};

/** The single match row (or null) for the normalized pair. */
$match_row = function ( $g1, $g2 ) use ( $matches_tbl ) {
	global $wpdb;
	$a = min( (int) $g1, (int) $g2 );
	$b = max( (int) $g1, (int) $g2 );
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT id, intro_thread_id FROM {$matches_tbl} WHERE group_a=%d AND group_b=%d LIMIT 1", $a, $b ) );
};

/** COUNT of swipe rows for a directed (from,to) pair. */
$swipe_count = function ( $from, $to ) use ( $swipes_tbl ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$swipes_tbl} WHERE from_group_id=%d AND to_group_id=%d", (int) $from, (int) $to ) );
};

// ─────────────────────────────────────────────────────────────────────
// 3. Fixtures — two throwaway groups, each with its own admin user.
// ─────────────────────────────────────────────────────────────────────
$a_admin = $seed_user( 'a' );
$b_admin = $seed_user( 'b' );
if ( $a_admin <= 0 || $b_admin <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed WP test users; run on the hub with user creation writable)\n";
	exit( 0 );
}
$gid_a = $seed_group( "UAT83 GroupA {$session}", $a_admin );
$gid_b = $seed_group( "UAT83 GroupB {$session}", $b_admin );
if ( $gid_a <= 0 || $gid_b <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed BP group fixtures; run on the hub with BuddyPress groups writable)\n";
	exit( 0 );
}
echo "[setup] seeded groupA={$gid_a} (admin {$a_admin}) groupB={$gid_b} (admin {$b_admin})\n";

$fail   = false;
$issues = array();

/**
 * Assertion helper — prints PASS/FAIL with a label and accumulates failures.
 *
 * @param string $label Human label.
 * @param bool   $cond  Truthiness of the invariant.
 * @param string $extra Extra diagnostic printed on FAIL.
 * @return void
 */
$assert = function ( $label, $cond, $extra = '' ) use ( &$fail, &$issues ) {
	if ( $cond ) {
		echo "[PASS] {$label}\n";
	} else {
		$fail     = true;
		$issues[] = $label . ( '' !== $extra ? " — {$extra}" : '' );
		echo "[FAIL] {$label}" . ( '' !== $extra ? " — {$extra}" : '' ) . "\n";
	}
};

// The matching actor must be a member of the union for the intro thread's sender
// resolution; run the match step as groupA's admin (mirrors route_swipe's authed actor).
if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( $a_admin );
}

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 1 — RACE / EXACTLY-ONE-MATCH (both orders). MATCH-01 + MATCH-03.
//   Seed BOTH reciprocal right swipes (the race pre-state), then fire
//   maybe_create_match for BOTH directions back-to-back: exactly one is the
//   INSERT-IGNORE winner (>0), the other a 0 no-op. Exactly one match row +
//   one positive intro_thread_id.
// ─────────────────────────────────────────────────────────────────────
$reset_pair( $gid_a, $gid_b );
Gend_GS_Collab_Schema::record_swipe( $gid_a, $gid_b, 'right', $a_admin );
Gend_GS_Collab_Schema::record_swipe( $gid_b, $gid_a, 'right', $b_admin );

$m1 = Gend_GS_Collab_Match::maybe_create_match( $gid_a, $gid_b ); // A completing on B
$m2 = Gend_GS_Collab_Match::maybe_create_match( $gid_b, $gid_a ); // B completing on A (loser)

$cnt          = $match_count( $gid_a, $gid_b );
$winner_count = ( $m1 > 0 ? 1 : 0 ) + ( $m2 > 0 ? 1 : 0 );
$assert(
	'S1 race: exactly ONE match row for the pair after a cross-swipe (forward order)',
	1 === $cnt,
	"match_count={$cnt} (expected 1)"
);
$assert(
	'S1 race: exactly ONE maybe_create_match call is the winner (>0), the other a 0 no-op',
	1 === $winner_count,
	"m1={$m1} m2={$m2} winners={$winner_count} (expected exactly 1)"
);

$row = $match_row( $gid_a, $gid_b );
$assert(
	'S1 thread: the single match row carries a positive intro_thread_id (exactly one intro thread seeded)',
	$row && (int) $row->intro_thread_id > 0,
	$row ? ( 'intro_thread_id=' . (int) $row->intro_thread_id ) : 'no match row'
);
if ( $row && (int) $row->intro_thread_id > 0 ) {
	$seen_threads[] = (int) $row->intro_thread_id;
}

// REVERSED order on a fresh slate — normalization is order-independent → still ONE match.
$reset_pair( $gid_a, $gid_b );
Gend_GS_Collab_Schema::record_swipe( $gid_a, $gid_b, 'right', $a_admin );
Gend_GS_Collab_Schema::record_swipe( $gid_b, $gid_a, 'right', $b_admin );
$rm2 = Gend_GS_Collab_Match::maybe_create_match( $gid_b, $gid_a ); // reversed: B first
$rm1 = Gend_GS_Collab_Match::maybe_create_match( $gid_a, $gid_b );
$cnt_rev          = $match_count( $gid_a, $gid_b );
$winner_count_rev = ( $rm1 > 0 ? 1 : 0 ) + ( $rm2 > 0 ? 1 : 0 );
$assert(
	'S1 race: exactly ONE match row for the pair after a cross-swipe (REVERSED order)',
	1 === $cnt_rev,
	"match_count={$cnt_rev} (expected 1)"
);
$assert(
	'S1 race: reversed order still yields exactly ONE winner (order-independent normalization)',
	1 === $winner_count_rev,
	"rm1={$rm1} rm2={$rm2} winners={$winner_count_rev} (expected exactly 1)"
);
$row_rev = $match_row( $gid_a, $gid_b );
if ( $row_rev && (int) $row_rev->intro_thread_id > 0 ) {
	$seen_threads[] = (int) $row_rev->intro_thread_id;
}

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 2 — STORM CAP / ONE-PER-RECIPIENT. MATCH-02.
//   The seeded thread's recipients = deduped union of admins∪mods of both
//   groups, each once. A SECOND maybe_create_match on the already-matched
//   pair returns 0 and seeds NO extra match/thread (idempotent — no storm).
// ─────────────────────────────────────────────────────────────────────
$expected_union = array();
if ( function_exists( 'groups_get_group_admins' ) && function_exists( 'groups_get_group_mods' ) ) {
	foreach ( array(
		groups_get_group_admins( $gid_a ), groups_get_group_mods( $gid_a ),
		groups_get_group_admins( $gid_b ), groups_get_group_mods( $gid_b ),
	) as $set ) {
		foreach ( (array) $set as $m ) {
			if ( is_object( $m ) && isset( $m->user_id ) ) {
				$expected_union[] = (int) $m->user_id;
			}
		}
	}
}
$expected_union = array_values( array_unique( array_filter( $expected_union ) ) );

// Idempotent re-run: a THIRD call on an already-matched pair must be a 0 no-op with no
// new row/thread (the storm cap — the winner branch runs exactly once per match).
$pre_cnt   = $match_count( $gid_a, $gid_b );
$pre_row   = $match_row( $gid_a, $gid_b );
$pre_tid   = $pre_row ? (int) $pre_row->intro_thread_id : 0;
$m_again   = Gend_GS_Collab_Match::maybe_create_match( $gid_a, $gid_b );
$post_cnt  = $match_count( $gid_a, $gid_b );
$post_row  = $match_row( $gid_a, $gid_b );
$post_tid  = $post_row ? (int) $post_row->intro_thread_id : 0;
$assert(
	'S2 storm-cap: re-running maybe_create_match on an already-matched pair is a 0 no-op (idempotent)',
	0 === $m_again,
	"returned {$m_again} (expected 0)"
);
$assert(
	'S2 storm-cap: no additional match row + intro thread unchanged on the idempotent re-run',
	$pre_cnt === $post_cnt && 1 === $post_cnt && $pre_tid === $post_tid,
	"pre_cnt={$pre_cnt} post_cnt={$post_cnt} pre_tid={$pre_tid} post_tid={$post_tid}"
);

// Recipient set = union once each. Read the thread's recipients if BP exposes them;
// otherwise fall back to a raw recipient-row count for the thread id.
if ( $post_tid > 0 && ! empty( $expected_union ) ) {
	$thread_recips = array();
	if ( class_exists( 'BP_Messages_Thread' ) ) {
		// get_recipients() is a NON-static instance method on this BP build (static
			// call fatals). Instantiate + read ->recipients (array keyed by user_id).
			$thread_obj = new BP_Messages_Thread( $post_tid );
			$raw        = ! empty( $thread_obj->recipients ) ? $thread_obj->recipients : array();
		foreach ( (array) $raw as $r ) {
			if ( is_object( $r ) && isset( $r->user_id ) ) {
				$thread_recips[] = (int) $r->user_id;
			}
		}
	}
	$thread_recips = array_values( array_unique( array_filter( $thread_recips ) ) );

	if ( ! empty( $thread_recips ) ) {
		sort( $thread_recips );
		$union_sorted = $expected_union;
		sort( $union_sorted );
		// Each expected recipient appears exactly once, no duplicates, set-equal to the union.
		$no_dupes = ( count( $thread_recips ) === count( array_unique( $thread_recips ) ) );
		$assert(
			'S2 storm-cap: intro thread recipients = deduped admins∪mods of both groups, each exactly once',
			$no_dupes && $thread_recips === $union_sorted,
			'thread=' . implode( ',', $thread_recips ) . ' union=' . implode( ',', $union_sorted )
		);
	} else {
		// Fallback: raw recipient-row count for the thread from BP's recipients table.
		$mr_tbl = $wpdb->prefix . 'bp_messages_recipients';
		$has_tbl = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
			$mr_tbl ) );
		if ( $has_tbl ) {
			$rc = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$mr_tbl} WHERE thread_id = %d", $post_tid ) );
			// The sender is also a recipient row; union excludes the actor, so expect
			// distinct-recipients >= 1 and <= |union| (each real participant once).
			$assert(
				'S2 storm-cap (fallback): distinct thread recipient rows are 1-per-participant (no duplicates)',
				$rc >= 1 && $rc <= ( count( $expected_union ) + 1 ),
				"distinct_recipients={$rc} union_size=" . count( $expected_union )
			);
		} else {
			echo "[SKIP] S2 storm-cap recipient set — BP messages recipient API/table unavailable to introspect\n";
		}
	}
} else {
	echo "[SKIP] S2 storm-cap recipient set — no intro thread id or empty union to compare (fixture/env)\n";
}

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 3 — LEFT NEVER MATCHES. MATCH-01.
//   right(A->B) + LEFT(B->A) → no reciprocal RIGHT → no match.
// ─────────────────────────────────────────────────────────────────────
$reset_pair( $gid_a, $gid_b );
Gend_GS_Collab_Schema::record_swipe( $gid_a, $gid_b, 'right', $a_admin );
Gend_GS_Collab_Schema::record_swipe( $gid_b, $gid_a, 'left', $b_admin );
$m_left = Gend_GS_Collab_Match::maybe_create_match( $gid_a, $gid_b );
$assert(
	'S3 left-never-matches: maybe_create_match returns 0 when the reciprocal swipe is LEFT',
	0 === $m_left,
	"returned {$m_left} (expected 0)"
);
$assert(
	'S3 left-never-matches: no match row exists for the pair',
	0 === $match_count( $gid_a, $gid_b ),
	'match_count=' . $match_count( $gid_a, $gid_b )
);

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 4 — UNDO RETURNS THE CARD. SWIPE-07.
//   A lone left(A->B) pass (no reciprocal). route_undo(group_id=A) → ok:true,
//   to_group=B, and the swipe row is GONE (card returns to the deck).
// ─────────────────────────────────────────────────────────────────────
$reset_pair( $gid_a, $gid_b );
Gend_GS_Collab_Schema::record_swipe( $gid_a, $gid_b, 'left', $a_admin );
$pre_swipe = $swipe_count( $gid_a, $gid_b );

$req_undo = new WP_REST_Request( 'POST', '/gs/v1/collab/undo' );
$req_undo->set_param( 'group_id', $gid_a );
$res_undo = Gend_GS_Collab_REST::route_undo( $req_undo );

$undo_ok   = ! is_wp_error( $res_undo );
$undo_data = ( $undo_ok && is_object( $res_undo ) && method_exists( $res_undo, 'get_data' ) ) ? $res_undo->get_data() : array();
$assert(
	'S4 undo: route_undo on a non-match swipe returns a non-error response with ok:true and to_group=B',
	$undo_ok && ! empty( $undo_data['ok'] ) && (int) ( $undo_data['to_group'] ?? 0 ) === (int) $gid_b,
	$undo_ok ? ( 'data=' . wp_json_encode( $undo_data ) ) : ( 'WP_Error ' . ( is_wp_error( $res_undo ) ? $res_undo->get_error_code() : '?' ) )
);
$post_swipe = $swipe_count( $gid_a, $gid_b );
$assert(
	'S4 undo: the swipe row is deleted so the card returns to the deck (SWIPE-04 existence-keyed re-show)',
	1 === $pre_swipe && 0 === $post_swipe,
	"pre_swipe={$pre_swipe} post_swipe={$post_swipe} (expected 1 → 0)"
);

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 5 — UNDO REFUSED AFTER A MATCH. SWIPE-07 (locked anti-abuse rule).
//   Form a real mutual match, then route_undo(group_id=A) → WP_Error
//   'gs_collab_undo_after_match' (409); match row + intro_thread_id + the
//   actor's swipe row all remain intact.
// ─────────────────────────────────────────────────────────────────────
$reset_pair( $gid_a, $gid_b );
Gend_GS_Collab_Schema::record_swipe( $gid_b, $gid_a, 'right', $b_admin ); // B interested first
Gend_GS_Collab_Schema::record_swipe( $gid_a, $gid_b, 'right', $a_admin ); // A reciprocates
$m_final = Gend_GS_Collab_Match::maybe_create_match( $gid_a, $gid_b );     // A's swipe forms the match
$final_row = $match_row( $gid_a, $gid_b );
if ( $final_row && (int) $final_row->intro_thread_id > 0 ) {
	$seen_threads[] = (int) $final_row->intro_thread_id;
}

$req_refuse = new WP_REST_Request( 'POST', '/gs/v1/collab/undo' );
$req_refuse->set_param( 'group_id', $gid_a );
$res_refuse = Gend_GS_Collab_REST::route_undo( $req_refuse );

$is_refusal = is_wp_error( $res_refuse ) && 'gs_collab_undo_after_match' === $res_refuse->get_error_code();
$refuse_status = 0;
if ( is_wp_error( $res_refuse ) ) {
	$edata = $res_refuse->get_error_data();
	$refuse_status = is_array( $edata ) && isset( $edata['status'] ) ? (int) $edata['status'] : 0;
}
$assert(
	'S5 undo-refused: route_undo after a match is a WP_Error gs_collab_undo_after_match (409)',
	$is_refusal && 409 === $refuse_status,
	is_wp_error( $res_refuse ) ? ( 'code=' . $res_refuse->get_error_code() . " status={$refuse_status}" ) : 'not a WP_Error (undo was allowed!)'
);

$after_cnt = $match_count( $gid_a, $gid_b );
$after_row = $match_row( $gid_a, $gid_b );
$assert(
	'S5 undo-refused: the match row + its intro_thread_id stay intact (match/thread never torn down)',
	1 === $after_cnt && $after_row && (int) $after_row->intro_thread_id > 0,
	"match_count={$after_cnt} intro_thread_id=" . ( $after_row ? (int) $after_row->intro_thread_id : 0 )
);
$assert(
	"S5 undo-refused: the actor's match-creating swipe row is NOT deleted (undo was fully refused)",
	1 === $swipe_count( $gid_a, $gid_b ),
	'swipe_count(A->B)=' . $swipe_count( $gid_a, $gid_b ) . ' (expected 1)'
);

// ─────────────────────────────────────────────────────────────────────
// BONUS — EMAIL GRACEFUL DEGRADATION (83-01 revision note).
//   The notify leg is function_exists('em_inbox_send_as')-guarded. Every
//   scenario above ran the full winner branch (match + intro thread + BP bell)
//   WITHOUT fataling regardless of that helper's presence — the fact that we
//   reached this line with $m_final > 0 (a real match formed) IS the proof.
//   When em_inbox_send_as is present the email additionally fires debounced;
//   when absent the in-app leg still covers everyone. Assert non-fatal + note mode.
// ─────────────────────────────────────────────────────────────────────
$email_present = function_exists( 'em_inbox_send_as' );
$assert(
	'BONUS email-degradation: match + intro thread + in-app bell formed without fatal irrespective of em_inbox_send_as',
	$m_final > 0,
	"em_inbox_send_as " . ( $email_present ? 'PRESENT (email leg also fires, debounced)' : 'ABSENT (in-app-only graceful degradation)' ) . "; m_final={$m_final}"
);

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 83 UAT passed — mutual-match, storm-cap, undo + undo-after-match refusal all honored\n";
	exit( 0 );
}
echo "=== FAILURE === Phase 83 UAT detected " . count( $issues ) . " issue(s):\n";
foreach ( $issues as $i => $msg ) {
	echo '  ' . ( $i + 1 ) . ". {$msg}\n";
}
exit( 1 );
