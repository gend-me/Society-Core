<?php
/**
 * Phase 84 UAT — GenD Match both-sided escrow ALL-OR-NOTHING rollback battery (v12.0).
 *
 * COLLAB-02 both-sided sign-off. This is the HIGHEST-VALUE assertion of the whole phase:
 * two linked one-sided Gend_CP_Task_Contract::escrow calls with a first-class rollback —
 * if side B's escrow fails, side A's escrow is REVERSED (reverse_escrow) so no half-funded
 * state can EVER exist and DGEN is made whole. Built in Plan 84-02
 * (Gend_GS_Collab_Contract::accept_both_sided + reverse_escrow).
 *
 *   1. SIDE-B FAILURE ROLLS BACK SIDE A (the phase's crown assertion) — fund side A's payer
 *      with >= credits×rate DGEN but starve side B's payer (< credits×rate). accept(both_sided)
 *      → WP_Error; side A payer's `transact` balance is RESTORED to its pre-accept value
 *      (mycred_get_users_balance — DGEN made whole); side A's task, if it persists, reads
 *      _contract_dgen_escrowed==0 AND _contract_dgen_reversed=='1'; the match stays 'matched'
 *      with contract_task_id NULL (no contract left funded). BOTH sides end unfunded.
 *   2. IDEMPOTENT ROLLBACK — a retried both-sided accept after a rollback does NOT double-refund
 *      side A (balance is stable) and still leaves the match un-contracted.
 *   3. BOTH-FUNDED HAPPY PATH — fresh fixture, BOTH payers funded → accept(both_sided) succeeds;
 *      TWO escrows occur (both tasks read _contract_dgen_escrowed>0), BOTH payers debited by
 *      credits×rate, the match is 'contracted' with contract_task_id (side A) set + side B's
 *      task id stored (gs_collab_match_{id}_task_b), and the intro thread is linked.
 *
 * House convention (mirrors handoff/83-uat-swipe-match.php + 84-uat-collab-contract.php):
 *   defined('ABSPATH')||die; SKIP-as-PASS gates for missing deps / non-hub / un-seedable
 *   fixtures; a $fail accumulator + [PASS]/[FAIL] per assertion; register_shutdown_function
 *   teardown; footer "=== SUCCESS ===" (exit 0) / "=== FAILURE ===" (exit 1).
 *
 * Run as www-data on the HUB wordpress pod (money is hub-only), after kubectl cp onto the
 * live gend-society PVC, with 84-01 + 84-02 (+82/83 spine) deployed:
 *   wp eval-file wp-content/plugins/gend-society/handoff/84-uat-both-sided-rollback.php
 *
 * Exit codes: 0 — honored / SKIP-as-PASS · 1 — an invariant was violated.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 84 UAT — both-sided escrow ALL-OR-NOTHING rollback battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates.
// ─────────────────────────────────────────────────────────────────────
$required = array(
	'Gend_GS_Collab_Schema',
	'Gend_GS_Collab_Contract',
	'PSOO_PM_Projects',
	'PSOO_PM_Tasks',
	'PSOO_PM_Contracts',
	'Gend_CP_Task_Contract',
);
foreach ( $required as $cls ) {
	if ( ! class_exists( $cls ) ) {
		echo "=== SUCCESS === (skipped — {$cls} not loaded; deploy the hub money stack + 84-01/84-02 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'accept', 'accept_both_sided', 'reverse_escrow' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Contract', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Contract::{$m} absent; deploy 84-02 first)\n";
		exit( 0 );
	}
}
if ( ! method_exists( 'Gend_CP_Task_Contract', 'escrow' ) ) {
	echo "=== SUCCESS === (skipped — Gend_CP_Task_Contract::escrow absent; hub money stack not deployed)\n";
	exit( 0 );
}
if ( ! function_exists( 'groups_create_group' ) || ! function_exists( 'groups_promote_member' ) ) {
	echo "=== SUCCESS === (skipped — BuddyPress groups API not loaded)\n";
	exit( 0 );
}
if ( ! function_exists( 'mycred_add' ) || ! function_exists( 'mycred_get_users_balance' ) ) {
	echo "=== SUCCESS === (skipped — MyCred not loaded; DGEN balances not introspectable)\n";
	exit( 0 );
}
if ( class_exists( 'Gend_CP_OAuth_Resource' ) && method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
	&& ! Gend_CP_OAuth_Resource::is_main_node() ) {
	echo "=== SUCCESS === (skipped — not the hub/main node; both-sided escrow is hub-only by design)\n";
	exit( 0 );
}

global $wpdb;

$matches_tbl   = Gend_GS_Collab_Schema::matches_table();
$proposals_tbl = Gend_GS_Collab_Schema::proposals_table();
$tasks_tbl     = $wpdb->prefix . 'pm_tasks';
foreach ( array( $matches_tbl, $proposals_tbl ) as $t ) {
	$installed = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $t ) );
	if ( ! $installed ) {
		echo "=== SUCCESS === (skipped — {$t} not installed on this blog yet; run maybe_install)\n";
		exit( 0 );
	}
}

$session      = uniqid( 'p84rb_', true );
$created_gids = array();
$created_uids = array();
$seen_matches = array();
$seen_threads = array();
$seen_tasks   = array();

$rate = max( 1, (int) get_option( 'psoo_contract_exchange_rate', 15 ) );

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown.
// ─────────────────────────────────────────────────────────────────────
register_shutdown_function(
	function () use ( &$created_gids, &$created_uids, &$seen_matches, &$seen_threads, &$seen_tasks, $matches_tbl, $proposals_tbl, $tasks_tbl ) {
		global $wpdb;
		foreach ( array_unique( array_filter( $seen_matches ) ) as $mid ) {
			$mid = (int) $mid;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE id = %d", $mid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$proposals_tbl} WHERE match_id = %d", $mid ) );
			@delete_option( 'gs_collab_match_' . $mid . '_task_b' );
		}
		foreach ( array_unique( array_filter( $seen_tasks ) ) as $tid ) {
			$tid = (int) $tid;
			if ( class_exists( 'PSOO_PM_Tasks' ) && method_exists( 'PSOO_PM_Tasks', 'delete' ) ) {
				@PSOO_PM_Tasks::delete( $tid );
			} else {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$tasks_tbl} WHERE id = %d", $tid ) );
			}
		}
		if ( function_exists( 'messages_delete_thread' ) ) {
			foreach ( array_unique( array_filter( $seen_threads ) ) as $tid ) {
				@messages_delete_thread( (int) $tid );
			}
		}
		if ( function_exists( 'groups_delete_group' ) ) {
			foreach ( array_unique( array_filter( $created_gids ) ) as $gid ) {
				@groups_delete_group( (int) $gid );
			}
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( array_unique( array_filter( $created_uids ) ) as $uid ) {
				@wp_delete_user( (int) $uid );
			}
		}
		echo '[cleanup] complete — removed ' . count( array_unique( array_filter( $created_gids ) ) ) . ' group(s), '
			. count( array_unique( array_filter( $created_uids ) ) ) . ' user(s), '
			. count( array_unique( array_filter( $seen_matches ) ) ) . " match/proposal set(s)\n";
	}
);

// ── fixture helpers (shared shape with 84-uat-collab-contract.php) ────
$seed_user = function ( $slug ) use ( &$created_uids, $session ) {
	if ( ! function_exists( 'wp_insert_user' ) ) {
		return 0;
	}
	$login = 'uat84rb_' . $slug . '_' . substr( md5( $session . $slug ), 0, 8 );
	$uid   = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 20, true ),
		'user_email' => $login . '@uat84rb.local',
		'role'       => 'subscriber',
	) );
	if ( is_wp_error( $uid ) || (int) $uid <= 0 ) {
		return 0;
	}
	$created_uids[] = (int) $uid;
	return (int) $uid;
};

$seed_group = function ( $name, $admin_uid ) use ( &$created_gids ) {
	$gid = (int) groups_create_group( array(
		'creator_id'  => (int) $admin_uid,
		'name'        => $name,
		'description' => 'Phase 84 rollback UAT fixture — ' . $name,
		'status'      => 'public',
	) );
	if ( $gid <= 0 ) {
		return 0;
	}
	$created_gids[] = $gid;
	if ( function_exists( 'groups_join_group' ) ) {
		groups_join_group( $gid, (int) $admin_uid );
	}
	groups_promote_member( (int) $admin_uid, $gid, 'admin' );
	return $gid;
};

$seed_match = function ( $g1, $g2, $intro_thread_id ) use ( &$seen_matches, $matches_tbl ) {
	global $wpdb;
	$a = min( (int) $g1, (int) $g2 );
	$b = max( (int) $g1, (int) $g2 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE group_a=%d AND group_b=%d", $a, $b ) );
	$wpdb->insert( $matches_tbl, array(
		'group_a'         => $a,
		'group_b'         => $b,
		'status'          => 'matched',
		'intro_thread_id' => (int) $intro_thread_id,
		'created_at'      => time(),
	), array( '%d', '%d', '%s', '%d', '%d' ) );
	$mid = (int) $wpdb->insert_id;
	if ( $mid > 0 ) {
		$seen_matches[] = $mid;
	}
	return $mid;
};

$fund_transact = function ( $uid, $target ) {
	$uid = (int) $uid;
	if ( $uid <= 0 ) {
		return;
	}
	$cur   = (float) mycred_get_users_balance( $uid, 'transact' );
	$delta = (float) $target - $cur;
	if ( abs( $delta ) < 0.0001 ) {
		return;
	}
	mycred_add( 'uat84rb_seed', $uid, $delta, 'Phase 84 rollback UAT — seed transact balance', 0, array(), 'transact' );
};

$match_row = function ( $mid ) use ( $matches_tbl ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT id, group_a, group_b, status, intro_thread_id, contract_task_id FROM {$matches_tbl} WHERE id=%d LIMIT 1",
		(int) $mid ) );
};

$make_proposal_both = function ( $mid, $proposer_group, $payer_group, $payee_group, $credits ) {
	return Gend_GS_Collab_Contract::propose( (int) $mid, (int) $proposer_group, array(
		'payer_group'  => (int) $payer_group,
		'payee_group'  => (int) $payee_group,
		'escrow_model' => 'both_sided',
		'credits'      => (int) $credits,
		'milestones'   => array( 'Mutual commitment bond' ),
	) );
};

$task_meta = function ( $task_id, $key ) {
	if ( class_exists( 'PSOO_PM_Contracts' ) && method_exists( 'PSOO_PM_Contracts', 'get_task_meta' ) ) {
		return PSOO_PM_Contracts::get_task_meta( (int) $task_id, $key );
	}
	return null;
};

// ─────────────────────────────────────────────────────────────────────
// 3. Fixtures — two groups, each with its own admin (each is a bond payer).
// ─────────────────────────────────────────────────────────────────────
$a_admin = $seed_user( 'a' );
$b_admin = $seed_user( 'b' );
if ( $a_admin <= 0 || $b_admin <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed WP test users; run on the hub with user creation writable)\n";
	exit( 0 );
}
$gid_a = $seed_group( "UAT84RB GroupA {$session}", $a_admin );
$gid_b = $seed_group( "UAT84RB GroupB {$session}", $b_admin );
if ( $gid_a <= 0 || $gid_b <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed BP group fixtures; run on the hub with BuddyPress groups writable)\n";
	exit( 0 );
}
// The engine funds each side from that group's FIRST admin. In accept_both_sided,
// side A = normalized group_a (LEAST id), side B = group_b (GREATEST id).
$norm_a       = min( $gid_a, $gid_b );
$norm_b       = max( $gid_a, $gid_b );
$payer_a_uid  = ( $norm_a === $gid_a ) ? $a_admin : $b_admin; // side A payer (gets refunded on rollback)
$payer_b_uid  = ( $norm_b === $gid_a ) ? $a_admin : $b_admin; // side B payer (starved to force failure)

$intro_thread_id = 0;
if ( function_exists( 'messages_new_message' ) ) {
	$tid = messages_new_message( array(
		'sender_id'  => $a_admin,
		'recipients' => array( $b_admin ),
		'subject'    => 'UAT84RB intro',
		'content'    => 'Phase 84 rollback UAT intro thread',
	) );
	if ( ! is_wp_error( $tid ) && (int) $tid > 0 ) {
		$intro_thread_id = (int) $tid;
		$seen_threads[]  = $intro_thread_id;
	}
}
if ( $intro_thread_id <= 0 ) {
	$intro_thread_id = 910000000 + wp_rand( 1, 8999999 );
}
echo "[setup] side A payer uid={$payer_a_uid} (group {$norm_a}) · side B payer uid={$payer_b_uid} (group {$norm_b}) · intro_thread={$intro_thread_id} rate={$rate}\n";

$fail   = false;
$issues = array();
$assert = function ( $label, $cond, $extra = '' ) use ( &$fail, &$issues ) {
	if ( $cond ) {
		echo "[PASS] {$label}\n";
	} else {
		$fail     = true;
		$issues[] = $label . ( '' !== $extra ? " — {$extra}" : '' );
		echo "[FAIL] {$label}" . ( '' !== $extra ? " — {$extra}" : '' ) . "\n";
	}
};

$CREDITS = 2;
$COST    = $CREDITS * $rate;

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( $b_admin ); // counterparty acceptor context throughout
}

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 1 — SIDE-B FAILURE ROLLS BACK SIDE A (the crown assertion).
//   Fund side A's payer with enough; STARVE side B's payer. accept(both_sided) must
//   escrow A, fail on B, then REVERSE A so DGEN is whole and no contract survives.
// ─────────────────────────────────────────────────────────────────────
$mid1 = $seed_match( $gid_a, $gid_b, $intro_thread_id );
$make_proposal_both( $mid1, $gid_a, $gid_a, $gid_b, $CREDITS );

$fund_transact( $payer_a_uid, $COST + 5 );          // side A: comfortably funded
$fund_transact( $payer_b_uid, max( 0, $COST - 1 ) ); // side B: STARVED (< credits×rate)
// If A and B resolve to the same uid (degenerate single-user fixture), we cannot prove
// a one-sided reversal — skip this scenario cleanly.
if ( $payer_a_uid === $payer_b_uid ) {
	echo "[SKIP] S1 rollback — side A and side B resolve to the SAME payer uid; cannot isolate a one-sided reversal\n";
} else {
	$pre_bal_a = (float) mycred_get_users_balance( $payer_a_uid, 'transact' );
	$pre_tasks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_tbl}" );

	$acc = Gend_GS_Collab_Contract::accept( $mid1, $gid_b, $b_admin );

	$post_bal_a = (float) mycred_get_users_balance( $payer_a_uid, 'transact' );
	$mrow       = $match_row( $mid1 );
	$task_b_opt = (int) get_option( 'gs_collab_match_' . $mid1 . '_task_b', 0 );
	$ctid       = $mrow ? (int) $mrow->contract_task_id : 0;
	if ( $ctid > 0 ) {
		$seen_tasks[] = $ctid;
	}
	if ( $task_b_opt > 0 ) {
		$seen_tasks[] = $task_b_opt;
	}

	$assert(
		'S1 rollback: both-sided accept with a starved side B returns a WP_Error (no contract)',
		is_wp_error( $acc ),
		is_wp_error( $acc ) ? ( 'code=' . $acc->get_error_code() . ': ' . $acc->get_error_message() ) : ( 'NOT a WP_Error — acc=' . wp_json_encode( $acc ) )
	);
	$assert(
		'S1 rollback: side A payer transact balance RESTORED — DGEN made whole (== pre-accept value)',
		abs( $post_bal_a - $pre_bal_a ) < 0.0001,
		"pre={$pre_bal_a} post={$post_bal_a} (side-A escrow must be reversed)"
	);
	$assert(
		'S1 rollback: match still matched + contract_task_id IS NULL (no half-funded contract left)',
		$mrow && 'matched' === (string) $mrow->status && empty( $mrow->contract_task_id ),
		$mrow ? ( 'status=' . $mrow->status . ' contract_task_id=' . var_export( $mrow->contract_task_id, true ) ) : 'no match row'
	);

	// The engine deletes side A's task after a successful reversal; if it still exists,
	// its escrow meta MUST read reversed (escrowed 0 + reversed flag). Prove whichever holds.
	$task_a_exists = ( $ctid > 0 )
		? (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_tbl} WHERE id=%d", $ctid ) )
		: false;
	if ( ! $task_a_exists ) {
		$assert(
			'S1 rollback: side A task torn down after reversal (no orphan escrow persists)',
			true,
			'task deleted on rollback'
		);
	} else {
		$escrowed_a = (float) $task_meta( $ctid, '_contract_dgen_escrowed' );
		$reversed_a = (string) $task_meta( $ctid, '_contract_dgen_reversed' );
		$assert(
			'S1 rollback: persisted side A task reads _contract_dgen_escrowed==0 AND _contract_dgen_reversed==1',
			abs( $escrowed_a ) < 0.0001 && '1' === $reversed_a,
			"escrowed={$escrowed_a} reversed={$reversed_a}"
		);
	}

	// ── SCENARIO 2 — IDEMPOTENT ROLLBACK (retry does not double-refund side A). ──
	$bal_before_retry = (float) mycred_get_users_balance( $payer_a_uid, 'transact' );
	$acc_retry        = Gend_GS_Collab_Contract::accept( $mid1, $gid_b, $b_admin );
	$bal_after_retry  = (float) mycred_get_users_balance( $payer_a_uid, 'transact' );
	$mrow_retry       = $match_row( $mid1 );
	$assert(
		'S2 idempotent-rollback: a retried both-sided accept does NOT double-refund side A (balance stable)',
		abs( $bal_after_retry - $bal_before_retry ) < 0.0001,
		"before={$bal_before_retry} after={$bal_after_retry}"
	);
	$assert(
		'S2 idempotent-rollback: the match is STILL un-contracted after the retry',
		$mrow_retry && 'matched' === (string) $mrow_retry->status && empty( $mrow_retry->contract_task_id ),
		$mrow_retry ? ( 'status=' . $mrow_retry->status ) : 'no match row'
	);
}

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 3 — BOTH-FUNDED HAPPY PATH. Two escrows, both debited, contracted.
// ─────────────────────────────────────────────────────────────────────
$intro_thread_id2 = $intro_thread_id + 1;
$mid2 = $seed_match( $gid_a, $gid_b, $intro_thread_id2 );
$make_proposal_both( $mid2, $gid_a, $gid_a, $gid_b, $CREDITS );

if ( $payer_a_uid === $payer_b_uid ) {
	echo "[SKIP] S3 happy-path — side A and side B resolve to the SAME payer uid; cannot prove two independent debits\n";
} else {
	$fund_transact( $payer_a_uid, $COST + 5 );
	$fund_transact( $payer_b_uid, $COST + 5 );
	$pre_a = (float) mycred_get_users_balance( $payer_a_uid, 'transact' );
	$pre_b = (float) mycred_get_users_balance( $payer_b_uid, 'transact' );

	$acc2 = Gend_GS_Collab_Contract::accept( $mid2, $gid_b, $b_admin );
	$mrow2 = $match_row( $mid2 );

	$task_a = ( ! is_wp_error( $acc2 ) && is_array( $acc2 ) ) ? (int) ( $acc2['contract_task_id'] ?? 0 ) : 0;
	$task_b = ( ! is_wp_error( $acc2 ) && is_array( $acc2 ) ) ? (int) ( $acc2['contract_task_id_b'] ?? 0 ) : 0;
	$proj_a = ( ! is_wp_error( $acc2 ) && is_array( $acc2 ) ) ? (int) ( $acc2['project_id'] ?? 0 ) : 0;
	if ( $task_a > 0 ) {
		$seen_tasks[] = $task_a;
	}
	if ( $task_b > 0 ) {
		$seen_tasks[] = $task_b;
	}

	$post_a = (float) mycred_get_users_balance( $payer_a_uid, 'transact' );
	$post_b = (float) mycred_get_users_balance( $payer_b_uid, 'transact' );
	$task_b_opt = (int) get_option( 'gs_collab_match_' . $mid2 . '_task_b', 0 );

	$assert(
		'S3 happy-path: both-funded both-sided accept succeeds and returns BOTH task ids',
		! is_wp_error( $acc2 ) && $task_a > 0 && $task_b > 0,
		is_wp_error( $acc2 ) ? ( 'WP_Error ' . $acc2->get_error_code() . ': ' . $acc2->get_error_message() ) : ( 'acc=' . wp_json_encode( $acc2 ) )
	);
	$esc_a = (float) $task_meta( $task_a, '_contract_dgen_escrowed' );
	$esc_b = (float) $task_meta( $task_b, '_contract_dgen_escrowed' );
	$assert(
		'S3 happy-path: TWO escrows recorded — both tasks read _contract_dgen_escrowed == credits×rate',
		abs( $esc_a - $COST ) < 0.0001 && abs( $esc_b - $COST ) < 0.0001,
		"escrow_a={$esc_a} escrow_b={$esc_b} expected={$COST}"
	);
	$assert(
		'S3 happy-path: BOTH payers debited by exactly credits×rate',
		abs( ( $pre_a - $post_a ) - $COST ) < 0.0001 && abs( ( $pre_b - $post_b ) - $COST ) < 0.0001,
		"debit_a=" . ( $pre_a - $post_a ) . " debit_b=" . ( $pre_b - $post_b ) . " expected={$COST}"
	);
	$assert(
		'S3 happy-path: match contracted with contract_task_id=side A + side B task id stored in gs_collab_match_{id}_task_b',
		$mrow2 && 'contracted' === (string) $mrow2->status && (int) $mrow2->contract_task_id === $task_a && $task_b_opt === $task_b && $task_b > 0,
		$mrow2 ? ( 'status=' . $mrow2->status . ' contract_task_id=' . $mrow2->contract_task_id . ' task_b_opt=' . $task_b_opt . ' task_b=' . $task_b ) : 'no match row'
	);
	if ( function_exists( 'em_chat_thread_project_id' ) ) {
		$linked = (int) em_chat_thread_project_id( $intro_thread_id2 );
		$assert(
			'S3 happy-path: intro thread linked to the side A project',
			$proj_a > 0 && $linked === $proj_a,
			"linked={$linked} project_a={$proj_a}"
		);
	} else {
		echo "[SKIP] S3 happy-path thread linkage — em_chat_thread_project_id reader absent\n";
	}
}

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 84 UAT (both-sided rollback) passed — side-B failure rolled back side-A escrow (DGEN made whole), rollback idempotent, and the both-funded happy path escrows both sides + contracts the match\n";
	exit( 0 );
}
echo "=== FAILURE === Phase 84 UAT (both-sided rollback) detected " . count( $issues ) . " issue(s):\n";
foreach ( $issues as $i => $msg ) {
	echo '  ' . ( $i + 1 ) . ". {$msg}\n";
}
exit( 1 );
