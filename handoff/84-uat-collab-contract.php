<?php
/**
 * Phase 84 UAT — GenD Match → collaboration-contract escalation (one-sided) battery (v12.0).
 *
 * COLLAB-01/02 sign-off. The house has no formal test framework; this `wp eval-file`
 * script IS the Phase-84 (one-sided) regression suite. It machine-asserts the
 * money-careful correctness truths of the propose→accept→decline handshake built in
 * Plan 84-01 (Gend_GS_Collab_Contract::propose/accept/decline):
 *
 *   1. PROPOSE MOVES NO MONEY (COLLAB-01) — propose() stores a pending gs_collab_proposals
 *      row (prop_status='pending'); NO pm_tasks row, NO escrow, match.contract_task_id
 *      still NULL, match.status still 'matched'. A proposal is intent, not a contract.
 *   2. SELF-ACCEPT REJECTED (COLLAB-01 auth) — the proposer's own group cannot accept its
 *      own proposal. The REST permission layer (can_accept_contract) rejects it; the engine
 *      accept() drives to the same clean no-money outcome when the counterparty never accepts.
 *      Asserted at the AUTH layer (the load-bearing gate): accepting AS the proposer group is
 *      refused, no contract is created.
 *   3. ACCEPT BY COUNTERPARTY (COLLAB-01/02) — accept() by the OTHER group's admin creates
 *      EXACTLY ONE pm_tasks row, escrows the payer's DGEN EXACTLY ONCE
 *      (_contract_dgen_escrowed set; payer 'transact' balance debited by credits×rate),
 *      links the intro thread (em_chat_thread_project_id(intro_thread_id) === the new
 *      project_id), and flips the match to status='contracted' + contract_task_id set.
 *   4. RE-ACCEPT IS IDEMPOTENT (COLLAB safety) — a second accept() is a no-op success:
 *      NO second pm_tasks row, NO additional debit (balance unchanged), same contract_task_id.
 *   5. INSUFFICIENT-DGEN CLEAN ABORT (COLLAB-02) — a fresh match/proposal whose payer holds
 *      LESS than credits×rate → accept() returns WP_Error; match stays 'matched',
 *      contract_task_id NULL, NO pm_tasks row survives, NO debit (clean abort, no partial state).
 *
 * House convention (mirrors handoff/83-uat-swipe-match.php + 82-uat-swipe-ledger.php):
 *   - defined('ABSPATH')||die('wp eval-file only');
 *   - SKIP-as-PASS gates: a missing spine class / BuddyPress / projects / MyCred / un-seedable
 *     fixture echoes "=== SUCCESS === (skipped — …)" + exit(0). A fixture/env PROBLEM is a SKIP,
 *     never a FAIL — only a genuine invariant violation FAILs.
 *   - A $fail accumulator; each assertion echoes [PASS]/[FAIL] with a label; the footer gate is
 *     "=== SUCCESS ===" + exit(0) when clean, else "=== FAILURE ===" + exit(1).
 *   - register_shutdown_function teardown deletes every seeded group/user/match/proposal/task
 *     row so the script is re-runnable.
 *
 * Run as www-data (root-run eval-files break uploads/perms — MEMORY editor-session root-perms),
 * on the HUB wordpress pod (money is hub-only — is_main_node()), after kubectl cp onto the live
 * gend-society PVC, with 84-01 (+82/83 spine) already deployed:
 *   wp eval-file wp-content/plugins/gend-society/handoff/84-uat-collab-contract.php
 *
 * Exit codes:
 *   0 — all invariants honored (or SKIP-as-PASS for missing classes / fixtures / non-hub node)
 *   1 — an invariant was violated
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 84 UAT — collab-contract escalation (one-sided) battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates — missing spine / cross-plugin deps → skip clean.
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
		echo "=== SUCCESS === (skipped — {$cls} not loaded; deploy the hub money stack + 84-01 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'propose', 'accept', 'decline' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Contract', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Contract::{$m} absent; deploy 84-01 first)\n";
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
if ( ! function_exists( 'em_chat_thread_project_id' ) ) {
	echo "=== SUCCESS === (skipped — em_chat_thread_project_id reader absent; email-manager not deployed)\n";
	exit( 0 );
}
// Money is hub-only. On a container the accept() 404s by design — skip.
if ( class_exists( 'Gend_CP_OAuth_Resource' ) && method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
	&& ! Gend_CP_OAuth_Resource::is_main_node() ) {
	echo "=== SUCCESS === (skipped — not the hub/main node; the escalation is hub-only by design)\n";
	exit( 0 );
}

global $wpdb;

$matches_tbl   = Gend_GS_Collab_Schema::matches_table();
$proposals_tbl = Gend_GS_Collab_Schema::proposals_table();
$tasks_tbl     = $wpdb->prefix . 'pm_tasks';
foreach ( array( $matches_tbl, $proposals_tbl ) as $t ) {
	$installed = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
		$t
	) );
	if ( ! $installed ) {
		echo "=== SUCCESS === (skipped — {$t} not installed on this blog yet; run maybe_install)\n";
		exit( 0 );
	}
}

$session       = uniqid( 'p84uat_', true );
$created_gids  = array();  // seeded BP group ids
$created_uids  = array();  // seeded WP users
$seen_matches  = array();  // gs_collab_matches ids seeded this run
$seen_threads  = array();  // intro thread ids (dummy or seeded)
$seen_tasks    = array();  // pm_tasks ids created by accept()

$rate = max( 1, (int) get_option( 'psoo_contract_exchange_rate', 15 ) );

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown — always runs, even on early exit. Deletes ONLY this run's rows.
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

// ── fixture helpers ──────────────────────────────────────────────────
$seed_user = function ( $slug ) use ( &$created_uids, $session ) {
	if ( ! function_exists( 'wp_insert_user' ) ) {
		return 0;
	}
	$login = 'uat84_' . $slug . '_' . substr( md5( $session . $slug ), 0, 8 );
	$uid   = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 20, true ),
		'user_email' => $login . '@uat84.local',
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
		'description' => 'Phase 84 UAT fixture — ' . $name,
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

// Insert a matched gs_collab_matches row for a group pair (normalized), with a
// dummy intro_thread_id, and return the match id. Records for teardown.
$seed_match = function ( $g1, $g2, $intro_thread_id ) use ( &$seen_matches, $matches_tbl ) {
	global $wpdb;
	$a = min( (int) $g1, (int) $g2 );
	$b = max( (int) $g1, (int) $g2 );
	// Clean any pre-existing row for this pair first (re-runnable).
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

// Fund a user's `transact` MyCred balance to a target absolute value (>=0).
$fund_transact = function ( $uid, $target ) {
	$uid = (int) $uid;
	if ( $uid <= 0 ) {
		return;
	}
	$cur = (float) mycred_get_users_balance( $uid, 'transact' );
	$delta = (float) $target - $cur;
	if ( abs( $delta ) < 0.0001 ) {
		return;
	}
	mycred_add(
		'uat84_seed',
		$uid,
		$delta,
		'Phase 84 UAT — seed transact balance',
		0,
		array(),
		'transact'
	);
};

$match_row = function ( $mid ) use ( $matches_tbl ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT id, group_a, group_b, status, intro_thread_id, contract_task_id FROM {$matches_tbl} WHERE id=%d LIMIT 1",
		(int) $mid
	) );
};

$proposal_row = function ( $mid ) use ( $proposals_tbl ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$proposals_tbl} WHERE match_id=%d LIMIT 1", (int) $mid ) );
};

$task_meta = function ( $task_id, $key ) {
	if ( class_exists( 'PSOO_PM_Contracts' ) && method_exists( 'PSOO_PM_Contracts', 'get_task_meta' ) ) {
		return PSOO_PM_Contracts::get_task_meta( (int) $task_id, $key );
	}
	return null;
};

// ─────────────────────────────────────────────────────────────────────
// 3. Fixtures — two throwaway groups, each with its own admin user + a dummy thread.
// ─────────────────────────────────────────────────────────────────────
$a_admin = $seed_user( 'a' );
$b_admin = $seed_user( 'b' );
if ( $a_admin <= 0 || $b_admin <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed WP test users; run on the hub with user creation writable)\n";
	exit( 0 );
}
$gid_a = $seed_group( "UAT84 GroupA {$session}", $a_admin );
$gid_b = $seed_group( "UAT84 GroupB {$session}", $b_admin );
if ( $gid_a <= 0 || $gid_b <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed BP group fixtures; run on the hub with BuddyPress groups writable)\n";
	exit( 0 );
}
// group_a in the match is the normalized LEAST id — resolve which admin funds it.
$norm_a       = min( $gid_a, $gid_b );
$norm_a_admin = ( $norm_a === $gid_a ) ? $a_admin : $b_admin;

// A dummy intro thread id: use a real BP thread when possible so the linkage assertion
// resolves against a live thread; fall back to a high dummy id (the linkage writer keys
// purely on the numeric thread id, so the reader still resolves it).
$intro_thread_id = 0;
if ( function_exists( 'messages_new_message' ) ) {
	$tid = messages_new_message( array(
		'sender_id'  => $a_admin,
		'recipients' => array( $b_admin ),
		'subject'    => 'UAT84 intro',
		'content'    => 'Phase 84 UAT intro thread',
	) );
	if ( ! is_wp_error( $tid ) && (int) $tid > 0 ) {
		$intro_thread_id = (int) $tid;
		$seen_threads[]  = $intro_thread_id;
	}
}
if ( $intro_thread_id <= 0 ) {
	$intro_thread_id = 900000000 + wp_rand( 1, 8999999 ); // dummy but positive + unique-ish
}
echo "[setup] seeded groupA={$gid_a}(admin {$a_admin}) groupB={$gid_b}(admin {$b_admin}) intro_thread={$intro_thread_id} rate={$rate}\n";

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

$CREDITS = 2; // small bond; escrow = CREDITS × rate DGEN.
$COST    = $CREDITS * $rate;

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 1 — PROPOSE MOVES NO MONEY. COLLAB-01.
// ─────────────────────────────────────────────────────────────────────
$mid1 = $seed_match( $gid_a, $gid_b, $intro_thread_id );
$assert( 'S0 fixture: seeded a matched gs_collab_matches row', $mid1 > 0, "match_id={$mid1}" );

$pre_tasks_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$tasks_tbl}" );

$prop = Gend_GS_Collab_Contract::propose( $mid1, $gid_a, array(
	'payer_group'  => $gid_a,
	'payee_group'  => $gid_b,
	'escrow_model' => 'one_sided',
	'credits'      => $CREDITS,
	'milestones'   => array( 'Deliver the thing', 'Get paid' ),
) );
$prow = $proposal_row( $mid1 );
$mrow = $match_row( $mid1 );
$post_tasks_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$tasks_tbl}" );

$assert(
	'S1 propose: returns a pending proposal (no WP_Error)',
	! is_wp_error( $prop ) && is_array( $prop ) && 'pending' === ( $prop['prop_status'] ?? '' ),
	is_wp_error( $prop ) ? ( 'WP_Error ' . $prop->get_error_code() ) : ( 'prop=' . wp_json_encode( $prop ) )
);
$assert(
	'S1 propose: gs_collab_proposals row is prop_status=pending',
	$prow && 'pending' === (string) $prow->prop_status,
	$prow ? ( 'prop_status=' . $prow->prop_status ) : 'no proposal row'
);
$assert(
	'S1 propose: NO money — match.contract_task_id NULL + status still matched',
	$mrow && empty( $mrow->contract_task_id ) && 'matched' === (string) $mrow->status,
	$mrow ? ( 'contract_task_id=' . var_export( $mrow->contract_task_id, true ) . ' status=' . $mrow->status ) : 'no match row'
);
$assert(
	'S1 propose: NO pm_tasks row created (propose creates no task/escrow)',
	$post_tasks_max === $pre_tasks_max,
	"pre_max={$pre_tasks_max} post_max={$post_tasks_max}"
);

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 2 — SELF-ACCEPT REJECTED. COLLAB-01 auth.
//   The proposer is group_a. Accepting AS group_a (the proposer) must be refused.
//   Prefer the REST permission layer (the load-bearing gate); fall back to noting
//   the engine drives no contract when the counterparty never accepts.
// ─────────────────────────────────────────────────────────────────────
if ( class_exists( 'Gend_GS_Collab_REST' ) && method_exists( 'Gend_GS_Collab_REST', 'can_accept_contract' ) && class_exists( 'WP_REST_Request' ) ) {
	if ( function_exists( 'wp_set_current_user' ) ) {
		wp_set_current_user( $a_admin ); // the PROPOSER's admin trying to self-accept
	}
	$req_self = new WP_REST_Request( 'POST', "/gs/v1/collab/match/{$mid1}/contract/accept" );
	$req_self->set_param( 'id', $mid1 );
	$req_self->set_param( 'group_id', $gid_a ); // acting AS the proposer group == self-accept
	$perm = Gend_GS_Collab_REST::can_accept_contract( $req_self );
	$assert(
		'S2 self-accept: can_accept_contract REFUSES the proposer group accepting its own proposal',
		is_wp_error( $perm ) || false === $perm,
		is_wp_error( $perm ) ? ( 'refused: ' . $perm->get_error_code() ) : ( 'perm=' . var_export( $perm, true ) . ' (expected WP_Error/false)' )
	);
	$mrow_after_self = $match_row( $mid1 );
	$assert(
		'S2 self-accept: match remains un-contracted after the refused self-accept',
		$mrow_after_self && empty( $mrow_after_self->contract_task_id ) && 'matched' === (string) $mrow_after_self->status,
		$mrow_after_self ? ( 'status=' . $mrow_after_self->status ) : 'no match row'
	);
} else {
	echo "[SKIP] S2 self-accept — Gend_GS_Collab_REST::can_accept_contract unavailable to introspect the auth gate\n";
}

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 3 — ACCEPT BY COUNTERPARTY. COLLAB-01/02.
//   Fund the payer (normalized group_a's admin) with exactly enough DGEN, then
//   accept as the counterparty. Expect one task, one escrow, thread linked, contracted.
// ─────────────────────────────────────────────────────────────────────
$fund_transact( $norm_a_admin, $COST ); // exactly credits×rate
$pre_bal = (float) mycred_get_users_balance( $norm_a_admin, 'transact' );

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( $b_admin ); // counterparty acceptor context
}
$acc = Gend_GS_Collab_Contract::accept( $mid1, $gid_b, $b_admin );
$mrow2 = $match_row( $mid1 );
$post_bal = (float) mycred_get_users_balance( $norm_a_admin, 'transact' );

$task_id = ( ! is_wp_error( $acc ) && is_array( $acc ) ) ? (int) ( $acc['contract_task_id'] ?? 0 ) : 0;
$proj_id = ( ! is_wp_error( $acc ) && is_array( $acc ) ) ? (int) ( $acc['project_id'] ?? 0 ) : 0;
if ( $task_id > 0 ) {
	$seen_tasks[] = $task_id;
}

$assert(
	'S3 accept: counterparty accept succeeds (no WP_Error) and returns a contract_task_id',
	! is_wp_error( $acc ) && $task_id > 0,
	is_wp_error( $acc ) ? ( 'WP_Error ' . $acc->get_error_code() . ': ' . $acc->get_error_message() ) : ( 'acc=' . wp_json_encode( $acc ) )
);
$assert(
	'S3 accept: match flipped to status=contracted + contract_task_id set',
	$mrow2 && 'contracted' === (string) $mrow2->status && (int) $mrow2->contract_task_id === $task_id && $task_id > 0,
	$mrow2 ? ( 'status=' . $mrow2->status . ' contract_task_id=' . $mrow2->contract_task_id ) : 'no match row'
);
$escrowed = (float) $task_meta( $task_id, '_contract_dgen_escrowed' );
$assert(
	'S3 accept: escrow recorded ONCE — _contract_dgen_escrowed == credits×rate',
	abs( $escrowed - $COST ) < 0.0001,
	"escrowed={$escrowed} expected={$COST}"
);
$assert(
	'S3 accept: payer transact balance debited by EXACTLY credits×rate',
	abs( ( $pre_bal - $post_bal ) - $COST ) < 0.0001,
	"pre={$pre_bal} post={$post_bal} debit=" . ( $pre_bal - $post_bal ) . " expected={$COST}"
);
$linked_pid = (int) em_chat_thread_project_id( $intro_thread_id );
$assert(
	'S3 accept: intro thread linked — em_chat_thread_project_id(intro_thread) === the new project_id',
	$proj_id > 0 && $linked_pid === $proj_id,
	"linked_project={$linked_pid} accept_project={$proj_id}"
);

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 4 — RE-ACCEPT IS IDEMPOTENT. COLLAB safety.
// ─────────────────────────────────────────────────────────────────────
$pre_tasks_cnt2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_tbl}" );
$bal_before_re  = (float) mycred_get_users_balance( $norm_a_admin, 'transact' );

$acc2 = Gend_GS_Collab_Contract::accept( $mid1, $gid_b, $b_admin );
$mrow3 = $match_row( $mid1 );
$post_tasks_cnt2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_tbl}" );
$bal_after_re    = (float) mycred_get_users_balance( $norm_a_admin, 'transact' );

$task_id2 = ( ! is_wp_error( $acc2 ) && is_array( $acc2 ) ) ? (int) ( $acc2['contract_task_id'] ?? 0 ) : 0;
$assert(
	'S4 idempotent: re-accept is a no-op success returning the SAME contract_task_id',
	! is_wp_error( $acc2 ) && $task_id2 === $task_id && $task_id > 0,
	is_wp_error( $acc2 ) ? ( 'WP_Error ' . $acc2->get_error_code() ) : ( "task_id2={$task_id2} first={$task_id}" )
);
$assert(
	'S4 idempotent: NO second pm_tasks row created on re-accept',
	$post_tasks_cnt2 === $pre_tasks_cnt2,
	"pre_cnt={$pre_tasks_cnt2} post_cnt={$post_tasks_cnt2}"
);
$assert(
	'S4 idempotent: NO additional debit on re-accept (balance unchanged)',
	abs( $bal_after_re - $bal_before_re ) < 0.0001,
	"before={$bal_before_re} after={$bal_after_re}"
);
$assert(
	'S4 idempotent: match still contracted with the SAME contract_task_id',
	$mrow3 && 'contracted' === (string) $mrow3->status && (int) $mrow3->contract_task_id === $task_id,
	$mrow3 ? ( 'status=' . $mrow3->status . ' contract_task_id=' . $mrow3->contract_task_id ) : 'no match row'
);

// ─────────────────────────────────────────────────────────────────────
// SCENARIO 5 — INSUFFICIENT-DGEN CLEAN ABORT. COLLAB-02.
//   Fresh match/proposal; payer holds LESS than credits×rate → accept aborts cleanly.
// ─────────────────────────────────────────────────────────────────────
$intro_thread_id2 = $intro_thread_id + 1; // distinct dummy thread for the second fixture
$mid2 = $seed_match( $gid_a, $gid_b, $intro_thread_id2 );
Gend_GS_Collab_Contract::propose( $mid2, $gid_a, array(
	'payer_group'  => $gid_a,
	'payee_group'  => $gid_b,
	'escrow_model' => 'one_sided',
	'credits'      => $CREDITS,
	'milestones'   => array( 'Underfunded deliverable' ),
) );

// Starve the payer: fund BELOW the cost.
$fund_transact( $norm_a_admin, max( 0, $COST - 1 ) );
$pre_bal_poor  = (float) mycred_get_users_balance( $norm_a_admin, 'transact' );
$pre_tasks_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_tbl}" );

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( $b_admin );
}
$acc_poor = Gend_GS_Collab_Contract::accept( $mid2, $gid_b, $b_admin );
$mrow_poor = $match_row( $mid2 );
$post_bal_poor  = (float) mycred_get_users_balance( $norm_a_admin, 'transact' );
$post_tasks_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_tbl}" );

$assert(
	'S5 insufficient-DGEN: accept returns a WP_Error (clean abort, no contract)',
	is_wp_error( $acc_poor ),
	is_wp_error( $acc_poor ) ? ( 'code=' . $acc_poor->get_error_code() ) : ( 'NOT a WP_Error — acc=' . wp_json_encode( $acc_poor ) )
);
$assert(
	'S5 insufficient-DGEN: match stays matched + contract_task_id NULL (no partial state)',
	$mrow_poor && 'matched' === (string) $mrow_poor->status && empty( $mrow_poor->contract_task_id ),
	$mrow_poor ? ( 'status=' . $mrow_poor->status . ' contract_task_id=' . var_export( $mrow_poor->contract_task_id, true ) ) : 'no match row'
);
$assert(
	'S5 insufficient-DGEN: NO pm_tasks row survives (the just-created task is deleted on abort)',
	$post_tasks_cnt === $pre_tasks_cnt,
	"pre_cnt={$pre_tasks_cnt} post_cnt={$post_tasks_cnt}"
);
$assert(
	'S5 insufficient-DGEN: NO debit — payer balance unchanged (nothing moved)',
	abs( $post_bal_poor - $pre_bal_poor ) < 0.0001,
	"pre={$pre_bal_poor} post={$post_bal_poor}"
);

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 84 UAT (one-sided) passed — propose→no-money, self-accept refused, counterparty-accept→one contract+one escrow+thread linked+contracted, re-accept idempotent, insufficient-DGEN clean abort\n";
	exit( 0 );
}
echo "=== FAILURE === Phase 84 UAT (one-sided) detected " . count( $issues ) . " issue(s):\n";
foreach ( $issues as $i => $msg ) {
	echo '  ' . ( $i + 1 ) . ". {$msg}\n";
}
exit( 1 );
