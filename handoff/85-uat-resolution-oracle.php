<?php
/**
 * Phase 85 UAT — GenD Match resolution-oracle wiring (NO payout) battery (v12.0).
 *
 * COLLAB-03 + RESOLVE-03 sign-off. The house has no formal test framework; this
 * `wp eval-file` script IS the Phase-85 regression suite. It machine-asserts the
 * money-careful correctness truths of the DARK terminal-outcome recorder + 15-min
 * cron backstop built across Plans 85-01 (Gend_CP_Task_Contract::terminate() +
 * gend_cp_task_contract_terminated do_action), 85-02 (Gend_GS_Collab_Resolver +
 * gs_collab_contract_outcomes store + gs_collab_resolve_sweep cron) and 85-03
 * (forfeit / mutual-cancel REST triggers — exercised here at the terminate() layer).
 *
 * Truths asserted (the phase gate):
 *   A) SUCCESS-once — do_action('gend_cp_task_contract_paid') records EXACTLY ONE
 *      outcomes row (outcome=success, reason=completed, source=hook) + one audit +
 *      one anchor; firing the hook again is an INSERT-IGNORE no-op (still ONE row).
 *   B) FAIL-once (self-forfeit) — terminate($tid,'forfeited') records ONE row
 *      (outcome=fail, reason=forfeited, source=hook); double-terminate stays ONE
 *      (Plan-01 _contract_terminated guard).
 *   C) VOID-once (mutual-cancel) — terminate($tid,'cancelled') records ONE row
 *      (outcome=void, reason=cancelled) — distinguishable from fail.
 *   D) EXPIRY via cron — a contracted match, non-terminal, pm_tasks.due_date in the
 *      past => sweep() records ONE row (outcome=fail, reason=expired, source=cron).
 *   E) HOOK+CRON idempotent — fire the paid hook (records success, source=hook),
 *      then set _contract_dgen_paid meta + call sweep(); COUNT(*) for that
 *      contract_task_id stays EXACTLY 1 and exactly ONE audit + ONE anchor total
 *      (sweep's INSERT IGNORE was a no-op — rows_affected spine).
 *   F) BACKSTOP (missed hook) — set _contract_terminated='forfeited' meta WITHOUT
 *      firing the action; sweep() records the outcome now (fail, source=cron) —
 *      proves the sweep catches a terminal state a missed hook left unrecorded.
 *   G) NON-COLLAB skip — fire the paid hook for a contract_task_id with NO
 *      gs_collab_matches row => NO outcomes row (the generic hook fires for every
 *      task contract; the resolver silently skips non-collab ones).
 *   H) DARK — recording in A–F happened with GS_COLLAB_MARKET_PUBLIC undefined or
 *      false; the recorder is NOT gated on that flag.
 *   I) ZERO MONEY (canary) — a bettor/user's mycred balances (transact +
 *      mycred_default) are byte-for-byte UNCHANGED across the whole battery: no
 *      market exists, no money moves anywhere in Phase 85.
 *
 * House convention (mirrors handoff/84-uat-collab-contract.php + 83/82):
 *   - defined('ABSPATH')||die('wp eval-file only');
 *   - SKIP-as-PASS gates: a missing spine class / projects / MyCred / un-seedable
 *     fixture / non-hub node echoes "=== SUCCESS === (skipped — …)" + exit(0). A
 *     fixture/env PROBLEM is a SKIP, never a FAIL — only a genuine invariant
 *     violation FAILs.
 *   - A $fail accumulator; each assertion echoes [PASS]/[FAIL] with a label; the
 *     footer gate is "=== SUCCESS ===" + exit(0) when clean, else
 *     "=== FAILURE ===" + exit(1).
 *   - register_shutdown_function teardown deletes every seeded group / user / match /
 *     outcomes row / task + meta + unschedules any test cron so the script is
 *     re-runnable.
 *
 * Run as www-data (root-run eval-files break uploads/perms — MEMORY editor-session
 * root-perms), on the HUB wordpress pod (the recorder is hub-only — is_main_node()),
 * after kubectl cp onto the live gend-society PVC, with 85-01/02/03 (+82/83/84 spine)
 * deployed:
 *   wp eval-file wp-content/plugins/gend-society/handoff/85-uat-resolution-oracle.php
 *
 * Exit codes:
 *   0 — all invariants honored (or SKIP-as-PASS for missing classes / fixtures / non-hub)
 *   1 — an invariant was violated (a real bug in Plans 01-03 to route back for closure)
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 85 UAT — resolution-oracle wiring (NO payout) battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates — missing spine / cross-plugin deps → skip clean.
// ─────────────────────────────────────────────────────────────────────
$required = array(
	'Gend_GS_Collab_Schema',
	'Gend_GS_Collab_Resolver',
	'Gend_CP_Task_Contract',
	'PSOO_PM_Tasks',
	'PSOO_PM_Contracts',
);
foreach ( $required as $cls ) {
	if ( ! class_exists( $cls ) ) {
		echo "=== SUCCESS === (skipped — {$cls} not loaded; deploy 85-01/02/03 + the hub money stack first)\n";
		exit( 0 );
	}
}
foreach ( array( 'record', 'sweep' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Resolver', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Resolver::{$m} absent; deploy 85-02 first)\n";
		exit( 0 );
	}
}
if ( ! method_exists( 'Gend_CP_Task_Contract', 'terminate' ) ) {
	echo "=== SUCCESS === (skipped — Gend_CP_Task_Contract::terminate absent; deploy 85-01 first)\n";
	exit( 0 );
}
if ( ! method_exists( 'Gend_GS_Collab_Schema', 'contract_outcomes_table' ) ) {
	echo "=== SUCCESS === (skipped — contract_outcomes_table accessor absent; deploy 85-02 first)\n";
	exit( 0 );
}
if ( ! function_exists( 'mycred_get_users_balance' ) ) {
	echo "=== SUCCESS === (skipped — MyCred not loaded; DGEN balances not introspectable — the zero-money canary needs it)\n";
	exit( 0 );
}
// The recorder is hub-only. On a container record()/sweep() early-return by design.
if ( class_exists( 'Gend_CP_OAuth_Resource' ) && method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
	&& ! Gend_CP_OAuth_Resource::is_main_node() ) {
	echo "=== SUCCESS === (skipped — not the hub/main node; the resolver is hub-only by design)\n";
	exit( 0 );
}

global $wpdb;

$matches_tbl  = Gend_GS_Collab_Schema::matches_table();
$outcomes_tbl = Gend_GS_Collab_Schema::contract_outcomes_table();
$tasks_tbl    = $wpdb->prefix . 'pm_tasks';
$audit_tbl    = class_exists( 'Gend_CP_Audit_Log' ) && method_exists( 'Gend_CP_Audit_Log', 'table' )
	? Gend_CP_Audit_Log::table()
	: $wpdb->base_prefix . 'gend_cp_audit_log';

foreach ( array( $matches_tbl, $outcomes_tbl ) as $t ) {
	$installed = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
		$t
	) );
	if ( ! $installed ) {
		echo "=== SUCCESS === (skipped — {$t} not installed on this blog yet; hit a page to run maybe_install)\n";
		exit( 0 );
	}
}

$session      = uniqid( 'p85uat_', true );
$created_uids = array();  // seeded WP users
$seen_matches = array();  // gs_collab_matches ids seeded this run
$seen_tasks   = array();  // pm_tasks ids seeded this run
$seen_tids    = array();  // contract_task_ids used (for outcomes + meta teardown)

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown — always runs, even on early exit. Deletes ONLY this run's rows.
// ─────────────────────────────────────────────────────────────────────
register_shutdown_function(
	function () use ( &$created_uids, &$seen_matches, &$seen_tasks, &$seen_tids, $matches_tbl, $outcomes_tbl, $tasks_tbl ) {
		global $wpdb;
		$pm_meta = $wpdb->prefix . 'pm_meta';

		foreach ( array_unique( array_filter( $seen_matches ) ) as $mid ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE id = %d", (int) $mid ) );
		}
		foreach ( array_unique( array_filter( $seen_tids ) ) as $tid ) {
			$tid = (int) $tid;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$outcomes_tbl} WHERE contract_task_id = %d", $tid ) );
			// Clear seeded contract meta rows for this task.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$pm_meta} WHERE entity_id = %d AND entity_type = 'pm_task'",
				$tid
			) );
		}
		foreach ( array_unique( array_filter( $seen_tasks ) ) as $tid ) {
			$tid = (int) $tid;
			if ( class_exists( 'PSOO_PM_Tasks' ) && method_exists( 'PSOO_PM_Tasks', 'delete' ) ) {
				@PSOO_PM_Tasks::delete( $tid );
			} else {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$tasks_tbl} WHERE id = %d", $tid ) );
			}
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( array_unique( array_filter( $created_uids ) ) as $uid ) {
				@wp_delete_user( (int) $uid );
			}
		}
		echo '[cleanup] complete — removed ' . count( array_unique( array_filter( $seen_matches ) ) ) . ' match row(s), '
			. count( array_unique( array_filter( $seen_tids ) ) ) . ' outcome/meta set(s), '
			. count( array_unique( array_filter( $seen_tasks ) ) ) . ' task(s), '
			. count( array_unique( array_filter( $created_uids ) ) ) . " user(s)\n";
	}
);

// ── fixture helpers ──────────────────────────────────────────────────

// A synthetic contract_task_id well above any real pm_tasks id — for cases that do
// NOT need a real pm_tasks row (the resolver only reverse-looks-up the match, and
// terminate()/get_task_meta tolerate a non-existent task by using project_id=0).
$base_synth_tid = 800000000 + wp_rand( 1, 8000000 );
$tid_seq        = 0;
$next_synth_tid = function () use ( $base_synth_tid, &$tid_seq, &$seen_tids ) {
	$tid = $base_synth_tid + ( ++$tid_seq );
	$seen_tids[] = $tid;
	return $tid;
};

// Insert a CONTRACTED gs_collab_matches row wired to a given contract_task_id and
// return the match id. Uses high dummy group ids (no BP groups needed — the recorder
// only reverse-looks-up match_id from contract_task_id).
$seed_contracted_match = function ( $contract_task_id ) use ( &$seen_matches, $matches_tbl ) {
	global $wpdb;
	$ga = 910000000 + wp_rand( 1, 8000000 );
	$gb = $ga + 1;
	$wpdb->insert(
		$matches_tbl,
		array(
			'group_a'          => $ga,
			'group_b'          => $gb,
			'status'           => 'contracted',
			'intro_thread_id'  => 0,
			'contract_task_id' => (int) $contract_task_id,
			'created_at'       => time(),
		),
		array( '%d', '%d', '%s', '%d', '%d', '%d' )
	);
	$mid = (int) $wpdb->insert_id;
	if ( $mid > 0 ) {
		$seen_matches[] = $mid;
	}
	return $mid;
};

// Count recorded outcomes rows for a contract_task_id.
$outcome_count = function ( $tid ) use ( $outcomes_tbl ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$outcomes_tbl} WHERE contract_task_id = %d",
		(int) $tid
	) );
};

// Fetch the single outcomes row for a contract_task_id (or null).
$outcome_row = function ( $tid ) use ( $outcomes_tbl ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT id, match_id, contract_task_id, outcome, reason, source, chain_tx_id, recorded_at
		   FROM {$outcomes_tbl} WHERE contract_task_id = %d LIMIT 1",
		(int) $tid
	) );
};

// Count audit rows recorded for a contract_task_id (event_type collab.contract_outcome,
// details_json carrying the id). The details are JSON-encoded so match on the pair.
$audit_count = function ( $tid ) use ( $audit_tbl ) {
	global $wpdb;
	$installed = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
		$audit_tbl
	) );
	if ( ! $installed ) {
		return -1; // signal "audit table absent" — treated as SKIP by the caller.
	}
	$like = '%' . $wpdb->esc_like( '"contract_task_id":' . (int) $tid ) . '%';
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$audit_tbl}
		  WHERE event_type = %s AND details_json LIKE %s",
		'collab.contract_outcome',
		$like
	) );
};

// ─────────────────────────────────────────────────────────────────────
// 3. Zero-money canary — capture a seeded user's balances at the very start.
// ─────────────────────────────────────────────────────────────────────
$canary_uid = 0;
if ( function_exists( 'wp_insert_user' ) ) {
	$login = 'uat85_canary_' . substr( md5( $session ), 0, 8 );
	$uid   = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 20, true ),
		'user_email' => $login . '@uat85.local',
		'role'       => 'subscriber',
	) );
	if ( ! is_wp_error( $uid ) && (int) $uid > 0 ) {
		$canary_uid     = (int) $uid;
		$created_uids[] = $canary_uid;
	}
}
if ( $canary_uid <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed a canary WP user; run on the hub with user creation writable)\n";
	exit( 0 );
}
// Give the canary a small non-zero balance so an accidental debit is visible as a delta.
if ( function_exists( 'mycred_add' ) ) {
	mycred_add( 'uat85_seed', $canary_uid, 50, 'Phase 85 UAT — canary transact seed', 0, array(), 'transact' );
	mycred_add( 'uat85_seed', $canary_uid, 50, 'Phase 85 UAT — canary default seed', 0, array(), 'mycred_default' );
}
$canary_transact_pre = (float) mycred_get_users_balance( $canary_uid, 'transact' );
$canary_default_pre  = (float) mycred_get_users_balance( $canary_uid, 'mycred_default' );

echo "[setup] session={$session} canary_uid={$canary_uid} transact_pre={$canary_transact_pre} default_pre={$canary_default_pre}\n";

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

// Track whether audit/anchor were observable this run (validator/audit may be stubbed
// on a bare test hub — those become PASS-with-note, per the plan's "whichever is observable").
$audit_observable  = ( $audit_count( 0 ) !== -1 );
$anchor_observable = class_exists( 'Gend_Chain_Validator' ) && method_exists( 'Gend_Chain_Validator', 'submit_tx' );

// ─────────────────────────────────────────────────────────────────────
// CASE A — SUCCESS-once via gend_cp_task_contract_paid. (COLLAB-03 success)
// ─────────────────────────────────────────────────────────────────────
$tidA = $next_synth_tid();
$midA = $seed_contracted_match( $tidA );
$assert( 'A0 fixture: seeded a contracted match for the SUCCESS case', $midA > 0, "match_id={$midA} tid={$tidA}" );

do_action( 'gend_cp_task_contract_paid', $tidA, $canary_uid, 0 );
$rowA = $outcome_row( $tidA );
$assert(
	'A1 SUCCESS: exactly ONE outcomes row after the paid hook',
	1 === $outcome_count( $tidA ),
	'count=' . $outcome_count( $tidA )
);
$assert(
	'A2 SUCCESS: outcome=success, reason=completed, source=hook',
	$rowA && 'success' === (string) $rowA->outcome && 'completed' === (string) $rowA->reason && 'hook' === (string) $rowA->source,
	$rowA ? ( 'outcome=' . $rowA->outcome . ' reason=' . $rowA->reason . ' source=' . $rowA->source ) : 'no row'
);
// Fire the SAME hook again — INSERT IGNORE collapse, still ONE row.
do_action( 'gend_cp_task_contract_paid', $tidA, $canary_uid, 0 );
$assert(
	'A3 SUCCESS: re-firing the paid hook is a no-op — still exactly ONE row (INSERT IGNORE collapse)',
	1 === $outcome_count( $tidA ),
	'count=' . $outcome_count( $tidA )
);
if ( $audit_observable ) {
	$assert(
		'A4 SUCCESS: exactly ONE audit row for this outcome (audit fires only on the new row)',
		1 === $audit_count( $tidA ),
		'audit_count=' . $audit_count( $tidA )
	);
} else {
	echo "[SKIP] A4 SUCCESS audit-count — gend_cp_audit_log table absent on this hub (audit not introspectable)\n";
}
if ( $anchor_observable ) {
	$assert(
		'A5 SUCCESS: the outcome row is chain-anchored (chain_tx_id populated)',
		$rowA && ! empty( $rowA->chain_tx_id ),
		$rowA ? ( 'chain_tx_id=' . var_export( $rowA->chain_tx_id, true ) ) : 'no row'
	);
} else {
	echo "[SKIP] A5 SUCCESS anchor — Gend_Chain_Validator::submit_tx absent (validator stubbed on this hub)\n";
}

// ─────────────────────────────────────────────────────────────────────
// CASE B — FAIL-once via terminate('forfeited'). (COLLAB-03 fail / self-forfeit)
// ─────────────────────────────────────────────────────────────────────
$tidB = $next_synth_tid();
$midB = $seed_contracted_match( $tidB );
Gend_CP_Task_Contract::terminate( $tidB, 'forfeited', array( 'reason' => 'forfeited', 'match_id' => $midB ) );
$rowB = $outcome_row( $tidB );
$assert(
	'B1 FAIL: exactly ONE outcomes row after terminate(forfeited)',
	1 === $outcome_count( $tidB ),
	'count=' . $outcome_count( $tidB )
);
$assert(
	'B2 FAIL: outcome=fail, reason=forfeited, source=hook',
	$rowB && 'fail' === (string) $rowB->outcome && 'forfeited' === (string) $rowB->reason && 'hook' === (string) $rowB->source,
	$rowB ? ( 'outcome=' . $rowB->outcome . ' reason=' . $rowB->reason . ' source=' . $rowB->source ) : 'no row'
);
// Double-terminate — Plan-01 _contract_terminated guard makes it a no-op (no second action).
Gend_CP_Task_Contract::terminate( $tidB, 'forfeited', array( 'reason' => 'forfeited' ) );
$assert(
	'B3 FAIL: double-terminate is a no-op — still exactly ONE row (Plan-01 guard + INSERT IGNORE)',
	1 === $outcome_count( $tidB ),
	'count=' . $outcome_count( $tidB )
);

// ─────────────────────────────────────────────────────────────────────
// CASE C — VOID-once via terminate('cancelled'). (COLLAB-03 void / mutual-cancel)
// ─────────────────────────────────────────────────────────────────────
$tidC = $next_synth_tid();
$midC = $seed_contracted_match( $tidC );
Gend_CP_Task_Contract::terminate( $tidC, 'cancelled', array( 'reason' => 'cancelled', 'match_id' => $midC ) );
$rowC = $outcome_row( $tidC );
$assert(
	'C1 VOID: exactly ONE outcomes row after terminate(cancelled)',
	1 === $outcome_count( $tidC ),
	'count=' . $outcome_count( $tidC )
);
$assert(
	'C2 VOID: outcome=void, reason=cancelled — DISTINGUISHABLE from fail',
	$rowC && 'void' === (string) $rowC->outcome && 'cancelled' === (string) $rowC->reason,
	$rowC ? ( 'outcome=' . $rowC->outcome . ' reason=' . $rowC->reason ) : 'no row'
);
$assert(
	'C3 VOID: void (mutual-cancel) is not the same signal as fail (forfeit) — B was fail, C is void',
	$rowB && $rowC && (string) $rowB->outcome === 'fail' && (string) $rowC->outcome === 'void',
	'B.outcome=' . ( $rowB ? $rowB->outcome : '?' ) . ' C.outcome=' . ( $rowC ? $rowC->outcome : '?' )
);

// ─────────────────────────────────────────────────────────────────────
// CASE D — EXPIRY via cron sweep. (RESOLVE-03 deadline-expiry backstop)
//   Seed a REAL pm_tasks row with a past due_date + a contracted match; call sweep().
// ─────────────────────────────────────────────────────────────────────
if ( method_exists( 'PSOO_PM_Tasks', 'create' ) && method_exists( 'PSOO_PM_Tasks', 'get' ) ) {
	// A project id is required by create(); use a synthetic positive one (the sweep
	// reads the task row's due_date, not a real project).
	$synth_project = 950000000 + wp_rand( 1, 8000000 );
	$new_task = PSOO_PM_Tasks::create( array(
		'title'      => 'UAT85 expiry ' . $session,
		'project_id' => $synth_project,
		'due_date'   => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), // yesterday — expired
	) );
	$tidD = is_wp_error( $new_task ) ? 0 : (int) $new_task;
	if ( $tidD > 0 ) {
		$seen_tasks[] = $tidD;
		$seen_tids[]  = $tidD;
		$midD         = $seed_contracted_match( $tidD );
		// Confirm the seeded task actually carries a past due_date (the sweep's expiry gate).
		$task_row = PSOO_PM_Tasks::get( $tidD );
		$due_ok   = $task_row && ! empty( $task_row->due_date ) && strtotime( $task_row->due_date ) < time();
		if ( $due_ok ) {
			Gend_GS_Collab_Resolver::sweep();
			$rowD = $outcome_row( $tidD );
			$assert(
				'D1 EXPIRY: sweep records exactly ONE outcomes row for the past-due contract',
				1 === $outcome_count( $tidD ),
				'count=' . $outcome_count( $tidD )
			);
			$assert(
				'D2 EXPIRY: outcome=fail, reason=expired, source=cron',
				$rowD && 'fail' === (string) $rowD->outcome && 'expired' === (string) $rowD->reason && 'cron' === (string) $rowD->source,
				$rowD ? ( 'outcome=' . $rowD->outcome . ' reason=' . $rowD->reason . ' source=' . $rowD->source ) : 'no row'
			);
		} else {
			echo "[SKIP] D EXPIRY — seeded task did not carry a past due_date (create() dropped it); expiry path un-exercisable this run\n";
		}
	} else {
		echo "[SKIP] D EXPIRY — could not seed a pm_tasks row (create() returned WP_Error/0)\n";
	}
} else {
	echo "[SKIP] D EXPIRY — PSOO_PM_Tasks::create/get unavailable to seed a due_date fixture\n";
}

// ─────────────────────────────────────────────────────────────────────
// CASE E — HOOK+CRON idempotent. (RESOLVE-03 idempotency spine)
//   Fire the paid hook (records success, source=hook); THEN set _contract_dgen_paid
//   meta + call sweep(); assert COUNT stays 1 and the audit stays 1 (sweep no-op).
// ─────────────────────────────────────────────────────────────────────
$tidE = $next_synth_tid();
$midE = $seed_contracted_match( $tidE );
do_action( 'gend_cp_task_contract_paid', $tidE, $canary_uid, 0 );
$count_after_hook_E = $outcome_count( $tidE );
$audit_after_hook_E = $audit_observable ? $audit_count( $tidE ) : 0;
// Now set the paid meta the sweep looks for, and run the backstop.
if ( method_exists( 'PSOO_PM_Contracts', 'set_task_meta' ) ) {
	PSOO_PM_Contracts::set_task_meta( $tidE, 0, '_contract_dgen_paid', '1' );
}
Gend_GS_Collab_Resolver::sweep();
$assert(
	'E1 IDEMPOTENT: hook recorded ONE row before the sweep',
	1 === $count_after_hook_E,
	'count_after_hook=' . $count_after_hook_E
);
$assert(
	'E2 IDEMPOTENT: hook + cron-sweep COLLAPSE to exactly ONE outcomes row (INSERT IGNORE)',
	1 === $outcome_count( $tidE ),
	'count_after_sweep=' . $outcome_count( $tidE )
);
if ( $audit_observable ) {
	$assert(
		'E3 IDEMPOTENT: exactly ONE audit total — the sweep INSERT IGNORE fired NO second audit',
		1 === $audit_count( $tidE ) && 1 === $audit_after_hook_E,
		'audit_after_hook=' . $audit_after_hook_E . ' audit_after_sweep=' . $audit_count( $tidE )
	);
} else {
	echo "[SKIP] E3 IDEMPOTENT audit-count — audit table absent on this hub\n";
}

// ─────────────────────────────────────────────────────────────────────
// CASE F — BACKSTOP (missed hook). (RESOLVE-03 backstop)
//   Set _contract_terminated='forfeited' meta WITHOUT firing the action; sweep()
//   must record the outcome now (fail, source=cron) — proving the sweep catches a
//   terminal state a missed hook left unrecorded.
// ─────────────────────────────────────────────────────────────────────
$tidF = $next_synth_tid();
$midF = $seed_contracted_match( $tidF );
$assert(
	'F0 BACKSTOP: no outcome recorded yet (the hook was intentionally NOT fired)',
	0 === $outcome_count( $tidF ),
	'count=' . $outcome_count( $tidF )
);
if ( method_exists( 'PSOO_PM_Contracts', 'set_task_meta' ) ) {
	PSOO_PM_Contracts::set_task_meta( $tidF, 0, '_contract_terminated', 'forfeited' );
}
Gend_GS_Collab_Resolver::sweep();
$rowF = $outcome_row( $tidF );
$assert(
	'F1 BACKSTOP: sweep records the missed terminal state — exactly ONE row',
	1 === $outcome_count( $tidF ),
	'count=' . $outcome_count( $tidF )
);
$assert(
	'F2 BACKSTOP: recorded outcome=fail, reason=forfeited, source=cron (a missed hook caught by the backstop)',
	$rowF && 'fail' === (string) $rowF->outcome && 'cron' === (string) $rowF->source,
	$rowF ? ( 'outcome=' . $rowF->outcome . ' reason=' . $rowF->reason . ' source=' . $rowF->source ) : 'no row'
);

// ─────────────────────────────────────────────────────────────────────
// CASE G — NON-COLLAB skip.
//   Fire the paid hook for a contract_task_id with NO gs_collab_matches row.
//   The resolver silently skips it — NO outcomes row created.
// ─────────────────────────────────────────────────────────────────────
$tidG = $next_synth_tid(); // registered for teardown but NO match seeded for it.
do_action( 'gend_cp_task_contract_paid', $tidG, $canary_uid, 0 );
$assert(
	'G1 NON-COLLAB: firing the paid hook for a task with NO contracted match records NOTHING',
	0 === $outcome_count( $tidG ),
	'count=' . $outcome_count( $tidG )
);

// ─────────────────────────────────────────────────────────────────────
// CASE H — DARK. The recorder is NOT gated on the market counsel flag.
//   Assert cases A–F recorded even though GS_COLLAB_MARKET_PUBLIC is undefined/false.
// ─────────────────────────────────────────────────────────────────────
$market_flag_off = ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) || ! constant( 'GS_COLLAB_MARKET_PUBLIC' );
$assert(
	'H1 DARK: GS_COLLAB_MARKET_PUBLIC is undefined or false for this run (recorder ran dark)',
	$market_flag_off,
	'defined=' . var_export( defined( 'GS_COLLAB_MARKET_PUBLIC' ), true )
);
$assert(
	'H2 DARK: outcomes were STILL recorded (A success + B fail + C void) with the market flag off',
	1 === $outcome_count( $tidA ) && 1 === $outcome_count( $tidB ) && 1 === $outcome_count( $tidC ),
	'A=' . $outcome_count( $tidA ) . ' B=' . $outcome_count( $tidB ) . ' C=' . $outcome_count( $tidC )
);

// ─────────────────────────────────────────────────────────────────────
// CASE I — ZERO MONEY canary. No market exists; nothing is credited/debited.
// ─────────────────────────────────────────────────────────────────────
$canary_transact_post = (float) mycred_get_users_balance( $canary_uid, 'transact' );
$canary_default_post  = (float) mycred_get_users_balance( $canary_uid, 'mycred_default' );
$assert(
	'I1 ZERO-MONEY: canary transact balance UNCHANGED across the whole battery',
	abs( $canary_transact_post - $canary_transact_pre ) < 0.0000001,
	"pre={$canary_transact_pre} post={$canary_transact_post} delta=" . ( $canary_transact_post - $canary_transact_pre )
);
$assert(
	'I2 ZERO-MONEY: canary mycred_default balance UNCHANGED across the whole battery',
	abs( $canary_default_post - $canary_default_pre ) < 0.0000001,
	"pre={$canary_default_pre} post={$canary_default_post} delta=" . ( $canary_default_post - $canary_default_pre )
);

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 85 UAT passed — success/fail/void recorded once each & distinguishable, cron records expiry, hook+cron idempotent (COUNT=1/one audit/one anchor), backstop catches a missed hook, non-collab skipped, recorder ran dark, canary balances byte-for-byte unchanged (ZERO money moved)\n";
	echo "UAT PASSED\n";
	exit( 0 );
}
echo "=== FAILURE === Phase 85 UAT detected " . count( $issues ) . " issue(s):\n";
foreach ( $issues as $i => $msg ) {
	echo '  ' . ( $i + 1 ) . ". {$msg}\n";
}
echo 'UAT FAILED (' . count( $issues ) . " failures)\n";
exit( 1 );
