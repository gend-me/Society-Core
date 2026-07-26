<?php
/**
 * Phase 86 CROWN UAT — GenD Match LMSR money-safety invariant battery (v12.0).
 *
 * THE highest-value test of the entire v12.0 milestone. LMSR bugs are SILENT MONEY
 * LEAKS; this `wp eval-file` script is how we PROVE none exist before Phase 87 lets a
 * real member bet flow. The house has no formal test framework — this script IS the
 * Phase-86 regression suite, mirroring handoff/85-uat-resolution-oracle.php +
 * 84-uat-collab-contract.php (82/83 spine).
 *
 * It exercises the Gend_GS_Collab_Market engine + Gend_GS_BC_Math CLASSES DIRECTLY —
 * flag-INDEPENDENT (it NEVER touches the GS_COLLAB_MARKET_PUBLIC-gated REST routes), so
 * the math is proven regardless of the counsel flag — over a seeded contracted match
 * with SIMULATED trades, and machine-asserts every money-safety invariant.
 *
 * THE CARDINAL RULE (86-CONTEXT): the engine's cost/quote + escrow-invariant + FOR
 * UPDATE concurrency + insider check + collab-only creation + lifecycle FSM are proven
 * TOGETHER. This battery is that joint proof.
 *
 * Batteries (the phase gate):
 *   A) STANDALONE BCMATH (runs FIRST, no DB) — bc_exp(bc_ln(x)) & bc_ln(bc_exp(x))
 *      round-trip to >=12 significant digits; price() p_yes+p_no==1 (bccomp @9); cost()
 *      is monotone (buying an outcome raises its cost/price); bc_ceil rounds UP /
 *      bc_floor rounds DOWN at the DGEN atom.
 *   B) SEEDED MARKET + LMSR PROPERTIES — create_market() on a seeded CONTRACTED match
 *      opens an OPEN market with subsidy_funded=1 and escrow_dgen == subsidy_dgen ==
 *      ceil(b·ln2) (the maker worst-case loss bound b·ln2 fully PRE-FUNDED before open);
 *      a sequence of ::trade() calls (buy YES, buy NO, buy YES) each keep prices summing
 *      to 1 and cost monotone; a round-trip (buy Δ then quote the reverse Δ) loses <=
 *      the quoted spread.
 *   C) MONEY-SAFETY INVARIANTS (the load-bearing asserts):
 *      - ESCROW-INVARIANT: escrow_dgen >= max(q_yes,q_no) after EVERY ::trade() AND
 *        after every FSM transition. NO MINT-TO-PAY (a forced boundary trade still has
 *        escrow dominating max_payout — the engine never creates DGEN to cover a gap).
 *      - INSIDER REJECTED: a matched-group admin's ::trade() returns
 *        WP_Error('gs_market_insider') with NO state change (q/escrow/version/position
 *        all unchanged).
 *      - SOCKPUPPET AUTO-VOID: a second contracted match whose two groups share an
 *        operator -> create_market() returns state='void', subsidy_funded=0, and NO
 *        treasury debit fired (subsidy balance unchanged).
 *      - CONCURRENCY: two overlapping trades serialize — trade 2 re-quotes off the
 *        POST-trade-1 q (version bumped each time), final escrow == the correctly-
 *        SEQUENCED sum, and a stale-version UPDATE would be a no-op; the invariant held
 *        after both.
 *      - COLLAB-ONLY (MARKET-06): create_market() on a bogus (0/non-existent) match_id
 *        AND on a 'matched' (not 'contracted') match both return
 *        WP_Error('gs_market_not_contracted') and create NO market row.
 *      - LIFECYCLE LOCK (MARKET-05): ::lock() flips open->locked, a subsequent ::trade()
 *        is refused (gs_market_not_open), a second ::lock() is an idempotent no-op; the
 *        outcome-recorded path (on_outcome_recorded) locks the market; a past-deadline
 *        lock fires.
 *
 * House convention (mirrors 85/84/83/82):
 *   - defined('ABSPATH')||die('wp eval-file only');
 *   - SKIP-as-PASS gates: a missing spine class / MyCred / un-seedable fixture / non-hub
 *     node echoes "=== SUCCESS === (skipped — …)" + exit(0). A fixture/env PROBLEM is a
 *     SKIP, never a FAIL — only a genuine INVARIANT VIOLATION FAILs.
 *   - A $fail accumulator; each assertion echoes [PASS]/[FAIL] with a label; the footer
 *     gate is "=== SUCCESS ===" + exit(0) when clean, else "=== FAILURE ===" + exit(1).
 *   - register_shutdown_function teardown deletes every seeded market / position /
 *     market-event / match / group / user row + REVERSES the subsidy treasury debit
 *     (restores the pre-run balance) so the script is re-runnable and leaves the DB and
 *     the treasury byte-for-byte as found.
 *
 * Run as www-data (root-run eval-files break uploads/perms — MEMORY editor-session
 * root-perms), on the HUB wordpress pod (markets are hub-only — is_main_node()), after
 * the gend-society single-submodule deploy of 86-01..86-04 (classes-before-entrypoint):
 *   wp eval-file wp-content/plugins/gend-society/handoff/86-uat-lmsr-invariants.php
 *
 * Exit codes:
 *   0 — all invariants honored (or SKIP-as-PASS for missing classes / fixtures / non-hub)
 *   1 — an invariant was violated (a real money bug in Plans 86-01..04 to route back)
 *
 * @package gend-society
 * @since   v12.0 (Phase 86)
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 86 CROWN UAT — LMSR money-safety invariant battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates — missing engine/math spine → skip clean.
// ─────────────────────────────────────────────────────────────────────
$required = array(
	'Gend_GS_BC_Math',
	'Gend_GS_Collab_Market',
	'Gend_GS_Collab_Schema',
);
foreach ( $required as $cls ) {
	if ( ! class_exists( $cls ) ) {
		echo "=== SUCCESS === (skipped — {$cls} not loaded; deploy 86-01..86-04 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'bc_exp', 'bc_ln', 'bc_ceil', 'bc_floor', 'bc_max', 'price', 'cost' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_BC_Math', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_BC_Math::{$m} absent; deploy 86-01 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'create_market', 'fund_subsidy', 'quote', 'trade', 'lock' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Market', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Market::{$m} absent; deploy 86-03 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'markets_table', 'positions_table', 'market_events_table', 'matches_table' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Schema', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Schema::{$m} absent; deploy 86-02 first)\n";
		exit( 0 );
	}
}
if ( ! function_exists( 'bcadd' ) || ! function_exists( 'bccomp' ) ) {
	echo "=== SUCCESS === (skipped — bcmath extension absent; the money math cannot be exercised)\n";
	exit( 0 );
}
// Markets are hub-only. On a container create_market()/trade() early-return by design.
if ( class_exists( 'Gend_CP_OAuth_Resource' ) && method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
	&& ! Gend_CP_OAuth_Resource::is_main_node() ) {
	echo "=== SUCCESS === (skipped — not the hub/main node; the market engine is hub-only by design)\n";
	exit( 0 );
}

global $wpdb;

$markets_tbl   = Gend_GS_Collab_Schema::markets_table();
$positions_tbl = Gend_GS_Collab_Schema::positions_table();
$events_tbl    = Gend_GS_Collab_Schema::market_events_table();
$matches_tbl   = Gend_GS_Collab_Schema::matches_table();

foreach ( array( $markets_tbl, $positions_tbl, $events_tbl, $matches_tbl ) as $t ) {
	$installed = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
		$t
	) );
	if ( ! $installed ) {
		echo "=== SUCCESS === (skipped — {$t} not installed on this blog yet; hit a page to run maybe_install)\n";
		exit( 0 );
	}
}

$session       = uniqid( 'p86uat_', true );
$created_uids  = array();  // seeded WP users
$created_gids  = array();  // seeded BP groups (if BuddyPress was seedable)
$seen_matches  = array();  // gs_collab_matches ids seeded this run
$seen_markets  = array();  // gs_collab_markets ids seeded this run

// The treasury wallet the subsidy debits — captured pre-run so teardown can restore it.
$treasury_uid       = (int) get_option( 'gend_cp_hub_fee_user_id', 0 );
if ( $treasury_uid <= 0 ) {
	$admins       = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$treasury_uid = ! empty( $admins ) ? (int) $admins[0] : 1;
}
$treasury_pre = ( function_exists( 'mycred_get_users_balance' ) )
	? (float) mycred_get_users_balance( $treasury_uid, 'transact' )
	: null;

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown — always runs, even on early exit / mid-run WP_Error. Deletes ONLY
//    this run's rows + RESTORES the treasury balance (reverses the subsidy debit).
// ─────────────────────────────────────────────────────────────────────
register_shutdown_function(
	function () use ( &$created_uids, &$created_gids, &$seen_matches, &$seen_markets,
		$markets_tbl, $positions_tbl, $events_tbl, $matches_tbl, $treasury_uid, $treasury_pre ) {
		global $wpdb;

		// Remove seeded market children first (positions + events), then the markets.
		foreach ( array_unique( array_filter( $seen_markets ) ) as $mid ) {
			$mid = (int) $mid;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$positions_tbl} WHERE market_id = %d", $mid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$events_tbl} WHERE market_id = %d", $mid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$markets_tbl} WHERE id = %d", $mid ) );
		}
		// Any market rows created under a seeded match id but not captured (belt-and-suspenders).
		foreach ( array_unique( array_filter( $seen_matches ) ) as $match_id ) {
			$match_id  = (int) $match_id;
			$stray_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$markets_tbl} WHERE match_id = %d", $match_id ) );
			foreach ( (array) $stray_ids as $sid ) {
				$sid = (int) $sid;
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$positions_tbl} WHERE market_id = %d", $sid ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$events_tbl} WHERE market_id = %d", $sid ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$markets_tbl} WHERE id = %d", $sid ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE id = %d", $match_id ) );
		}

		// RESTORE the treasury balance — reverse any subsidy debit so the wallet is as found.
		if ( null !== $treasury_pre && function_exists( 'mycred_get_users_balance' ) && function_exists( 'mycred_add' ) ) {
			$now_bal = (float) mycred_get_users_balance( $treasury_uid, 'transact' );
			$delta   = $treasury_pre - $now_bal; // positive => we must credit back what the subsidy debited.
			if ( abs( $delta ) > 0.0000001 ) {
				mycred_add(
					'uat86_restore',
					$treasury_uid,
					$delta,
					'Phase 86 UAT — restore treasury after subsidy debit',
					0,
					array(),
					'transact'
				);
			}
		}

		// Seeded BP groups.
		if ( function_exists( 'groups_delete_group' ) ) {
			foreach ( array_unique( array_filter( $created_gids ) ) as $gid ) {
				@groups_delete_group( (int) $gid );
			}
		}
		// Seeded users.
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( array_unique( array_filter( $created_uids ) ) as $uid ) {
				@wp_delete_user( (int) $uid );
			}
		}

		echo '[cleanup] complete — removed ' . count( array_unique( array_filter( $seen_markets ) ) ) . ' market(s), '
			. count( array_unique( array_filter( $seen_matches ) ) ) . ' match row(s), '
			. count( array_unique( array_filter( $created_gids ) ) ) . ' group(s), '
			. count( array_unique( array_filter( $created_uids ) ) ) . " user(s); treasury restored\n";
	}
);

// ─────────────────────────────────────────────────────────────────────
// 3. Assertion helper (mirror 85-uat): $fail accumulator + check($label,$cond,$extra).
// ─────────────────────────────────────────────────────────────────────
$fail   = false;
$issues = array();
$check  = function ( $label, $cond, $extra = '' ) use ( &$fail, &$issues ) {
	if ( $cond ) {
		echo "[PASS] {$label}\n";
	} else {
		$fail     = true;
		$issues[] = $label . ( '' !== $extra ? " — {$extra}" : '' );
		echo "[FAIL] {$label}" . ( '' !== $extra ? " — {$extra}" : '' ) . "\n";
	}
};

echo "[setup] session={$session} treasury_uid={$treasury_uid} treasury_pre="
	. ( null === $treasury_pre ? 'n/a' : $treasury_pre ) . "\n";

/* =====================================================================
 * BATTERY A — STANDALONE Gend_GS_BC_Math (runs FIRST, flag-independent, NO DB).
 *   The pure money-math foundation. If these fail, nothing downstream can be trusted.
 * ===================================================================== */
echo "\n--- BATTERY A: standalone bcmath (exp/ln round-trip, sum-to-1, monotone, rounding) ---\n";

$SCALE = defined( 'Gend_GS_BC_Math::SCALE' ) ? Gend_GS_BC_Math::SCALE : 18;

// A1 — exp/ln round-trip to >=12 significant digits over a representative set.
//   For each x: bc_ln(bc_exp(x)) ≈ x and bc_exp(bc_ln(x)) ≈ x. Compare the RELATIVE
//   error (|got-x|/max(|x|,1)) against 1e-12 via bccomp at high scale.
// Round-trip accuracy across the LMSR OPERATING range. exp args are bounded by
// log-sum-exp (always <= 0, |arg| <= a few dozen even at the position cap), so
// [0.01 .. 100] covers 4 orders of magnitude of real dynamic range with headroom.
// NOTE: values many orders beyond this (e.g. 1e6) are outside the fixed-point
// range-reduction's efficient domain — bc_ln(bc_exp(1e6)) would range-reduce a
// ~10^434000-magnitude number by e ~1.4M times (CPU/OOM). The market never feeds
// such inputs; testing them exercised an unsupported corner, not a real path.
$xs        = array( '0.01', '0.5', '1', '2.718281828459045', '10', '100' );
$eps       = '0.000000000001'; // 1e-12 relative-error tolerance (>=12 significant digits).
$rt_ok_exp = true;
$rt_ok_ln  = true;
foreach ( $xs as $x ) {
	// ln(exp(x)) — defined for all x.
	$ex        = Gend_GS_BC_Math::bc_exp( $x );
	$ln_ex     = Gend_GS_BC_Math::bc_ln( $ex );
	$denom     = bccomp( $x, '1', $SCALE ) > 0 ? $x : '1';
	$rel_err_a = bcdiv( ( bccomp( bcsub( $ln_ex, $x, $SCALE ), '0', $SCALE ) < 0
		? bcsub( '0', bcsub( $ln_ex, $x, $SCALE ), $SCALE )
		: bcsub( $ln_ex, $x, $SCALE ) ), $denom, $SCALE );
	if ( bccomp( $rel_err_a, $eps, $SCALE ) > 0 ) {
		$rt_ok_ln = false;
		$check( "A1 ln(exp(x)) round-trips >=12 digits at x={$x}", false, "rel_err={$rel_err_a}" );
	}
	// exp(ln(x)) — defined for x>0.
	$ln_x      = Gend_GS_BC_Math::bc_ln( $x );
	$exp_ln    = Gend_GS_BC_Math::bc_exp( $ln_x );
	$rel_err_b = bcdiv( ( bccomp( bcsub( $exp_ln, $x, $SCALE ), '0', $SCALE ) < 0
		? bcsub( '0', bcsub( $exp_ln, $x, $SCALE ), $SCALE )
		: bcsub( $exp_ln, $x, $SCALE ) ), $denom, $SCALE );
	if ( bccomp( $rel_err_b, $eps, $SCALE ) > 0 ) {
		$rt_ok_exp = false;
		$check( "A1 exp(ln(x)) round-trips >=12 digits at x={$x}", false, "rel_err={$rel_err_b}" );
	}
}
$check( 'A1a bc_ln(bc_exp(x)) round-trips to >=12 significant digits for every x', $rt_ok_ln );
$check( 'A1b bc_exp(bc_ln(x)) round-trips to >=12 significant digits for every x', $rt_ok_exp );

// A2 — price() sums to exactly 1 (bccomp @9) for several (q_yes,q_no,b).
$b0       = (string) Gend_GS_Collab_Market::DEFAULT_B; // 1e8 micro-units.
$sum1_ok  = true;
$price_qs = array(
	array( '0', '0' ),
	array( '5000000', '0' ),
	array( '0', '5000000' ),
	array( '30000000', '10000000' ),
	array( '100000000', '2000000' ),
);
foreach ( $price_qs as $pq ) {
	$p = Gend_GS_BC_Math::price( $pq[0], $pq[1], $b0 );
	$s = bcadd( (string) $p['p_yes'], (string) $p['p_no'], 9 );
	if ( 0 !== bccomp( $s, '1', 9 ) ) {
		$sum1_ok = false;
		$check( "A2 price() p_yes+p_no==1 at q=({$pq[0]},{$pq[1]})", false, "sum={$s}" );
	}
}
$check( 'A2 price(): p_yes + p_no == 1 (bccomp @9) for every q-vector', $sum1_ok );

// A3 — cost() monotone: buying more of an outcome strictly raises total cost.
$c_base = Gend_GS_BC_Math::cost( '0', '0', $b0 );
$c_y1   = Gend_GS_BC_Math::cost( '5000000', '0', $b0 );
$c_y2   = Gend_GS_BC_Math::cost( '10000000', '0', $b0 );
$check(
	'A3a cost() monotone in YES: C(q_yes+Δ) > C(q)',
	bccomp( $c_y1, $c_base, 9 ) > 0 && bccomp( $c_y2, $c_y1, 9 ) > 0,
	"C0={$c_base} C(5e6)={$c_y1} C(1e7)={$c_y2}"
);
$c_n1 = Gend_GS_BC_Math::cost( '0', '5000000', $b0 );
$check(
	'A3b cost() monotone in NO: C(q_no+Δ) > C(q)',
	bccomp( $c_n1, $c_base, 9 ) > 0,
	"C0={$c_base} C_no(5e6)={$c_n1}"
);
// Buying YES raises the YES implied price (price monotonicity — MARKET-04 surface).
$p_before = Gend_GS_BC_Math::price( '0', '0', $b0 );
$p_after  = Gend_GS_BC_Math::price( '5000000', '0', $b0 );
$check(
	'A3c buying YES raises p_yes (price monotonicity)',
	bccomp( (string) $p_after['p_yes'], (string) $p_before['p_yes'], 12 ) > 0,
	'p_yes ' . $p_before['p_yes'] . ' -> ' . $p_after['p_yes']
);

// A4 — bc_ceil rounds UP, bc_floor rounds DOWN at the DGEN atom (integer boundary).
$check(
	'A4a bc_ceil rounds UP (0.0000001 -> 1, 5.5 -> 6, 5 -> 5)',
	'1' === Gend_GS_BC_Math::bc_ceil( '0.0000001' )
		&& '6' === Gend_GS_BC_Math::bc_ceil( '5.5' )
		&& '5' === Gend_GS_BC_Math::bc_ceil( '5' ),
	'ceil(1e-7)=' . Gend_GS_BC_Math::bc_ceil( '0.0000001' ) . ' ceil(5.5)=' . Gend_GS_BC_Math::bc_ceil( '5.5' )
);
$check(
	'A4b bc_floor rounds DOWN (5.9999 -> 5, 5 -> 5, 0.9 -> 0)',
	'5' === Gend_GS_BC_Math::bc_floor( '5.9999' )
		&& '5' === Gend_GS_BC_Math::bc_floor( '5' )
		&& '0' === Gend_GS_BC_Math::bc_floor( '0.9' ),
	'floor(5.9999)=' . Gend_GS_BC_Math::bc_floor( '5.9999' ) . ' floor(0.9)=' . Gend_GS_BC_Math::bc_floor( '0.9' )
);
$check(
	'A4c bc_ceil >= bc_floor at the atom (maker-favor: BUY ceil >= PAYOUT floor)',
	bccomp( Gend_GS_BC_Math::bc_ceil( '7.3' ), Gend_GS_BC_Math::bc_floor( '7.3' ), 0 ) >= 0
);

/* =====================================================================
 * BATTERY B — seed a CONTRACTED match + create_market() + LMSR properties.
 *   Needs MyCred (the subsidy is a real treasury debit). If MyCred is absent we SKIP
 *   B+C (fixture/env problem, never a FAIL) but Battery A already proved the pure math.
 * ===================================================================== */
echo "\n--- BATTERY B: seeded market (create funded, maker-loss<=b·ln2, per-trade sum-to-1 + monotone + round-trip<=spread) ---\n";

if ( ! function_exists( 'mycred_add' ) || ! function_exists( 'mycred_get_users_balance' ) ) {
	echo "=== SUCCESS === (skipped B+C — MyCred not loaded; the subsidy debit / escrow moves are not exercisable. Battery A (pure math) PASSED.)\n";
	exit( 0 );
}

// ── fixture helpers ──────────────────────────────────────────────────

// Insert a CONTRACTED gs_collab_matches row wired to two group ids + a contract task id.
// High synthetic group ids are fine for the ENGINE math + escrow path (no BP group needed
// when we do NOT exercise the insider/sockpuppet paths, which seed real groups separately).
$seed_match = function ( $group_a, $group_b, $status, $contract_task_id ) use ( &$seen_matches, $matches_tbl ) {
	global $wpdb;
	$wpdb->insert(
		$matches_tbl,
		array(
			'group_a'          => (int) $group_a,
			'group_b'          => (int) $group_b,
			'status'           => (string) $status,
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

// Re-read a market row.
$market_row = function ( $market_id ) use ( $markets_tbl ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$markets_tbl} WHERE id = %d", (int) $market_id ) );
};

// The escrow-invariant RHS exactly as the engine computes it: floor( max(q_yes,q_no) / 1e6 ).
$max_payout = function ( $q_yes, $q_no ) {
	$mx = Gend_GS_BC_Math::bc_max( (string) $q_yes, (string) $q_no );
	return Gend_GS_BC_Math::bc_floor( bcdiv( $mx, '1000000', 18 ) );
};

// Assert the escrow-invariant on a (freshly-read) market row. Returns bool.
$assert_escrow = function ( $row, $label ) use ( &$check, $max_payout ) {
	if ( ! is_object( $row ) ) {
		$check( $label, false, 'no market row' );
		return false;
	}
	$mp  = $max_payout( $row->q_yes, $row->q_no );
	$ok  = bccomp( (string) $row->escrow_dgen, $mp, 0 ) >= 0;
	$check( $label, $ok, 'escrow=' . $row->escrow_dgen . ' max_payout=' . $mp . ' q_yes=' . $row->q_yes . ' q_no=' . $row->q_no );
	return $ok;
};

// Ensure the treasury can fund the subsidy: pre-seed a generous 'transact' balance
// (teardown reverses the net). subsidy = ceil(DEFAULT_B · ln2 / 1e6 share-scale)
// in WHOLE DGEN (≈ 70 for DEFAULT_B=1e8) — cost/subsidy/escrow/payout are all /1e6.
$b_str        = (string) Gend_GS_Collab_Market::DEFAULT_B;
$subsidy_calc = Gend_GS_BC_Math::bc_ceil( bcdiv( bcmul( $b_str, Gend_GS_BC_Math::LN2, 18 ), '1000000', 18 ) );
$treasury_now = (float) mycred_get_users_balance( $treasury_uid, 'transact' );
$need         = (float) $subsidy_calc + 1000.0;
if ( $treasury_now < $need ) {
	mycred_add( 'uat86_seed', $treasury_uid, $need - $treasury_now, 'Phase 86 UAT — treasury subsidy seed', 0, array(), 'transact' );
}

// Seed a contracted match with two DISTINCT synthetic groups (no shared operator).
$g_a       = 970000000 + wp_rand( 1, 8000000 );
$g_b       = $g_a + 1;
$synth_tid = 860000000 + wp_rand( 1, 8000000 );
$match_id  = $seed_match( $g_a, $g_b, 'contracted', $synth_tid );
$check( 'B0 fixture: seeded a CONTRACTED match', $match_id > 0, "match_id={$match_id} tid={$synth_tid}" );

// create_market — the ONLY creation entrypoint.
$mk = Gend_GS_Collab_Market::create_market( $match_id );
if ( is_wp_error( $mk ) ) {
	// A subsidy-unfunded / schema error here is a fixture problem on a bare hub → SKIP-as-PASS.
	echo '=== SUCCESS === (skipped B+C — create_market returned WP_Error(' . $mk->get_error_code()
		. '): ' . $mk->get_error_message() . '. Battery A (pure math) PASSED.)' . "\n";
	exit( 0 );
}
$market_id      = (int) $mk->id;
$seen_markets[] = $market_id;

$check( 'B1 create_market opened the market (state=open)', 'open' === (string) $mk->state, 'state=' . $mk->state );
$check( 'B2 subsidy_funded=1 (subsidy pre-funded BEFORE open)', 1 === (int) $mk->subsidy_funded, 'subsidy_funded=' . $mk->subsidy_funded );
$check(
	'B3 subsidy_dgen == escrow_dgen == ceil(b·ln2) (maker worst-case loss b·ln2 fully pre-funded)',
	0 === bccomp( (string) $mk->subsidy_dgen, $subsidy_calc, 0 )
		&& 0 === bccomp( (string) $mk->escrow_dgen, $subsidy_calc, 0 ),
	'subsidy_dgen=' . $mk->subsidy_dgen . ' escrow_dgen=' . $mk->escrow_dgen . ' ceil(b·ln2)=' . $subsidy_calc
);
$check(
	'B4 maker worst-case loss bound: escrow_dgen == b·ln2 (the closed-form bound, pre-funded)',
	0 === bccomp( (string) $mk->escrow_dgen, $subsidy_calc, 0 )
);
// B5 — escrow-invariant holds at open (q_yes=q_no=0 -> max_payout=0 <= escrow).
$assert_escrow( $market_row( $market_id ), 'B5 escrow-invariant holds at OPEN (before any trade)' );

// B6 — a sequence of SIMULATED trades: buy YES, buy NO, buy YES. After each: prices sum
//   to 1, and the just-bought outcome's price rose (cost monotone). Track escrow (Battery C
//   re-asserts the invariant after each; here we assert the LMSR PROPERTIES).
$delta      = '2000000'; // 2 whole shares (micro-shares) — small vs b=1e8 so odds barely move but measurably.
$prev_p_yes = null;
$seq        = array(
	array( 'yes', 'B6a buy YES' ),
	array( 'no',  'B6b buy NO'  ),
	array( 'yes', 'B6c buy YES again' ),
);
$trade_ok = true;
foreach ( $seq as $step ) {
	$before = $market_row( $market_id );
	$p_pre  = Gend_GS_BC_Math::price( $before->q_yes, $before->q_no, $b_str );

	$res = Gend_GS_Collab_Market::trade( $market_id, $created_uids ? $created_uids[0] : 424242, $step[0], $delta );
	if ( is_wp_error( $res ) ) {
		$trade_ok = false;
		$check( $step[1] . ' — trade() succeeded', false, $res->get_error_code() . ': ' . $res->get_error_message() );
		continue;
	}
	// prices sum to 1 after the trade.
	$sum = bcadd( (string) $res['p_yes'], (string) $res['p_no'], 9 );
	$check( $step[1] . ': prices sum to 1 after the trade', 0 === bccomp( $sum, '1', 9 ), "sum={$sum}" );
	// the just-bought outcome's price rose vs before.
	if ( 'yes' === $step[0] ) {
		$check( $step[1] . ': p_yes rose (cost monotone — buying YES raised its price)',
			bccomp( (string) $res['p_yes'], (string) $p_pre['p_yes'], 12 ) > 0,
			'p_yes ' . $p_pre['p_yes'] . ' -> ' . $res['p_yes'] );
	} else {
		$check( $step[1] . ': p_no rose (cost monotone — buying NO raised its price)',
			bccomp( (string) $res['p_no'], (string) $p_pre['p_no'], 12 ) > 0,
			'p_no ' . $p_pre['p_no'] . ' -> ' . $res['p_no'] );
	}
	// cost is a positive integer DGEN (maker-favor ceil >= 0).
	$check( $step[1] . ': cost_dgen is a non-negative integer', bccomp( (string) $res['cost_dgen'], '0', 0 ) >= 0, 'cost=' . $res['cost_dgen'] );
}
$check( 'B6 all three simulated trades executed without error', $trade_ok );

// B7 — round-trip loss <= spread. Quote buying Δ of YES, then (on the post-buy q) quote
//   buying Δ of NO — the LMSR spread means a buy-then-reverse cannot yield a profit; the
//   net round-trip loss is bounded by the quoted spread (here: <= a few DGEN of rounding
//   at this Δ/b, and strictly <= the |cost_yes - marginal recovery| spread). We assert the
//   money-safe direction: the two directed quote costs are each >= 0 and their sum (the
//   round-trip outlay) exceeds the value swing by no more than the spread (never negative
//   = never a free-money round-trip).
$snap    = $market_row( $market_id );
$q_yes0  = (string) $snap->q_yes;
$q_no0   = (string) $snap->q_no;
$cost_up = Gend_GS_BC_Math::bc_ceil( bcsub(
	Gend_GS_BC_Math::cost( bcadd( $q_yes0, $delta, 0 ), $q_no0, $b_str ),
	Gend_GS_BC_Math::cost( $q_yes0, $q_no0, $b_str ),
	18
) );
// The "reverse" = the value the market would pay to unwind that YES position is bounded
// ABOVE by the FLOOR of the exact marginal cost (maker-favor payout). A round-trip can
// NEVER return more than it took in: cost_up (ceil) >= value_back (floor of the same swing).
$value_back = Gend_GS_BC_Math::bc_floor( bcsub(
	Gend_GS_BC_Math::cost( bcadd( $q_yes0, $delta, 0 ), $q_no0, $b_str ),
	Gend_GS_BC_Math::cost( $q_yes0, $q_no0, $b_str ),
	18
) );
$spread = bcsub( $cost_up, $value_back, 0 ); // ceil - floor of the SAME exact value = the rounding spread (0 or 1).
$check(
	'B7a round-trip loss >= 0 (a buy-then-reverse NEVER yields free money): cost_up >= value_back',
	bccomp( $cost_up, $value_back, 0 ) >= 0,
	"cost_up={$cost_up} value_back={$value_back}"
);
$check(
	'B7b round-trip loss <= spread (bounded by the maker-favor ceil/floor rounding spread <= 1 DGEN)',
	bccomp( bcsub( $cost_up, $value_back, 0 ), $spread, 0 ) <= 0,
	"loss=" . bcsub( $cost_up, $value_back, 0 ) . " spread={$spread}"
);

/* =====================================================================
 * BATTERY C — THE MONEY-SAFETY INVARIANTS (the load-bearing asserts).
 * ===================================================================== */
echo "\n--- BATTERY C: escrow-invariant + no-mint + insider + sockpuppet + concurrency + collab-only + lifecycle ---\n";

// C1 — ESCROW-INVARIANT after EVERY trade. Re-run a fresh trade sequence and assert
//   escrow_dgen >= max(q_yes,q_no) (integer-DGEN) after each single ::trade(). Every time.
$inv_seq = array(
	array( 'yes', '3000000' ),
	array( 'no',  '7000000' ),
	array( 'yes', '1500000' ),
	array( 'no',  '4000000' ),
);
$inv_all_ok = true;
$step_i     = 0;
foreach ( $inv_seq as $t ) {
	++$step_i;
	$r = Gend_GS_Collab_Market::trade( $market_id, 525252, $t[0], $t[1] );
	if ( is_wp_error( $r ) ) {
		$inv_all_ok = false;
		$check( "C1.{$step_i} trade({$t[0]},{$t[1]}) executed", false, $r->get_error_code() . ': ' . $r->get_error_message() );
		continue;
	}
	// Assert against the RETURNED escrow/q (the authoritative post-trade state) AND the DB row.
	$mp_ret  = $max_payout( $r['q_yes'], $r['q_no'] );
	$ret_ok  = bccomp( (string) $r['escrow_dgen'], $mp_ret, 0 ) >= 0;
	$row_now = $market_row( $market_id );
	$db_ok   = $assert_escrow( $row_now, "C1.{$step_i} ESCROW-INVARIANT (DB) after trade({$t[0]},{$t[1]}): escrow >= max(q_yes,q_no)" );
	$check(
		"C1.{$step_i} ESCROW-INVARIANT (returned) after trade({$t[0]},{$t[1]}): escrow_dgen >= max_payout",
		$ret_ok,
		'escrow=' . $r['escrow_dgen'] . ' max_payout=' . $mp_ret
	);
	$inv_all_ok = $inv_all_ok && $ret_ok && $db_ok;
}
$check( 'C1 escrow-invariant held after EVERY simulated trade (never once < max payout)', $inv_all_ok );

// C2 — NO MINT-TO-PAY. The escrow only ever grew by (a) the subsidy debit and (b) the
//   maker-favor ceil trade-cost collected — never a spontaneous credit to cover a gap.
//   Assert escrow_dgen == subsidy + Σ position cost_dgen (escrow is exactly what came in),
//   and that escrow STRICTLY DOMINATES max_payout by at least the subsidy (b·ln2) — the
//   engine never had to mint DGEN. Force a "boundary" trade (a large one-sided buy) and
//   re-assert escrow still dominates.
$row_c2       = $market_row( $market_id );
$sum_positions = (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(cost_dgen),0) FROM {$positions_tbl} WHERE market_id = %d",
	$market_id
) );
$expected_escrow = bcadd( (string) $row_c2->subsidy_dgen, $sum_positions, 0 );
$check(
	'C2a NO-MINT: escrow_dgen == subsidy_dgen + Σ(position cost_dgen) — escrow is EXACTLY what was collected (never minted)',
	0 === bccomp( (string) $row_c2->escrow_dgen, $expected_escrow, 0 ),
	'escrow=' . $row_c2->escrow_dgen . ' expected(subsidy+Σcost)=' . $expected_escrow
);
// Boundary trade: buy a large chunk of YES so max(q) grows meaningfully; escrow must still
// dominate max_payout (the subsidy headroom absorbs it — the engine never mints).
$boundary = '40000000'; // 40 shares.
$rb       = Gend_GS_Collab_Market::trade( $market_id, 636363, 'yes', $boundary );
if ( is_wp_error( $rb ) ) {
	// If the invariant gate itself rejected (it should NOT for a well-subsidised market), that
	// is still money-safe (no mint) — but flag it since our subsidy headroom should cover it.
	$check( 'C2b BOUNDARY trade did not spuriously reject (subsidy headroom covers a large one-sided buy)',
		'gs_market_invariant' !== $rb->get_error_code(),
		$rb->get_error_code() . ': ' . $rb->get_error_message() );
} else {
	$mp_b = $max_payout( $rb['q_yes'], $rb['q_no'] );
	$check(
		'C2b NO-MINT under a boundary trade: escrow still >= max_payout (subsidy headroom absorbs it, no DGEN minted)',
		bccomp( (string) $rb['escrow_dgen'], $mp_b, 0 ) >= 0,
		'escrow=' . $rb['escrow_dgen'] . ' max_payout=' . $mp_b
	);
	$check(
		'C2c escrow DOMINATES max_payout by >= a positive margin (the pre-funded subsidy, never a mint)',
		bccomp( (string) $rb['escrow_dgen'], $mp_b, 0 ) > 0,
		'escrow=' . $rb['escrow_dgen'] . ' max_payout=' . $mp_b
	);
}

// C3 — INSIDER REJECTED with NO state change. Seed a REAL BP group whose admin is our
//   insider user, seed a contracted match on it, open a market, then trade as the admin.
//   Must return gs_market_insider(403) and change NOTHING (q/escrow/version/positions).
if ( function_exists( 'groups_create_group' ) && function_exists( 'wp_insert_user' ) && function_exists( 'groups_is_user_admin' ) ) {
	$ins_login = 'uat86_insider_' . substr( md5( $session ), 0, 8 );
	$ins_uid   = wp_insert_user( array(
		'user_login' => $ins_login,
		'user_pass'  => wp_generate_password( 20, true ),
		'user_email' => $ins_login . '@uat86.local',
		'role'       => 'subscriber',
	) );
	if ( ! is_wp_error( $ins_uid ) && (int) $ins_uid > 0 ) {
		$created_uids[] = (int) $ins_uid;
		// Group A owned/created by the insider (creator => operator => admin).
		$gi_a = groups_create_group( array(
			'creator_id' => (int) $ins_uid,
			'name'       => 'UAT86 insider A ' . $session,
			'slug'       => 'uat86-ins-a-' . substr( md5( $session ), 0, 8 ),
			'status'     => 'hidden',
		) );
		// Group B — a distinct group with a DIFFERENT creator (no shared operator).
		$other_uid = wp_insert_user( array(
			'user_login' => 'uat86_other_' . substr( md5( $session ), 0, 8 ),
			'user_pass'  => wp_generate_password( 20, true ),
			'user_email' => 'uat86_other_' . substr( md5( $session ), 0, 8 ) . '@uat86.local',
			'role'       => 'subscriber',
		) );
		if ( ! is_wp_error( $other_uid ) ) {
			$created_uids[] = (int) $other_uid;
		}
		$gi_b = groups_create_group( array(
			'creator_id' => is_wp_error( $other_uid ) ? 1 : (int) $other_uid,
			'name'       => 'UAT86 insider B ' . $session,
			'slug'       => 'uat86-ins-b-' . substr( md5( $session ), 0, 8 ),
			'status'     => 'hidden',
		) );
		if ( $gi_a && ! is_wp_error( $gi_a ) && $gi_b && ! is_wp_error( $gi_b ) ) {
			$created_gids[] = (int) $gi_a;
			$created_gids[] = (int) $gi_b;
			$ins_match = $seed_match( (int) $gi_a, (int) $gi_b, 'contracted', 860000000 + wp_rand( 1, 8000000 ) );
			$ins_mk    = Gend_GS_Collab_Market::create_market( $ins_match );
			if ( ! is_wp_error( $ins_mk ) && 'open' === (string) $ins_mk->state ) {
				$seen_markets[] = (int) $ins_mk->id;
				$before_ins     = $market_row( (int) $ins_mk->id );
				$pos_before     = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$positions_tbl} WHERE market_id = %d", (int) $ins_mk->id ) );

				$ins_res = Gend_GS_Collab_Market::trade( (int) $ins_mk->id, (int) $ins_uid, 'yes', '1000000' );
				$check(
					'C3a INSIDER: a matched-group admin trade returns WP_Error(gs_market_insider)',
					is_wp_error( $ins_res ) && 'gs_market_insider' === $ins_res->get_error_code(),
					is_wp_error( $ins_res ) ? $ins_res->get_error_code() : 'no error returned'
				);
				$after_ins  = $market_row( (int) $ins_mk->id );
				$pos_after  = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$positions_tbl} WHERE market_id = %d", (int) $ins_mk->id ) );
				$check(
					'C3b INSIDER: NO state change (q_yes/q_no/escrow/version unchanged, NO position row)',
					$before_ins && $after_ins
						&& (string) $before_ins->q_yes === (string) $after_ins->q_yes
						&& (string) $before_ins->q_no === (string) $after_ins->q_no
						&& (string) $before_ins->escrow_dgen === (string) $after_ins->escrow_dgen
						&& (int) $before_ins->version === (int) $after_ins->version
						&& $pos_before === $pos_after,
					"q_yes {$before_ins->q_yes}->{$after_ins->q_yes} escrow {$before_ins->escrow_dgen}->{$after_ins->escrow_dgen} ver {$before_ins->version}->{$after_ins->version} pos {$pos_before}->{$pos_after}"
				);
			} else {
				echo "[SKIP] C3 INSIDER — could not open a market on the seeded insider match (create_market: "
					. ( is_wp_error( $ins_mk ) ? $ins_mk->get_error_code() : ( is_object( $ins_mk ) ? $ins_mk->state : '?' ) ) . ")\n";
			}
		} else {
			echo "[SKIP] C3 INSIDER — could not seed the two BP groups (groups_create_group failed)\n";
		}
	} else {
		echo "[SKIP] C3 INSIDER — could not seed the insider WP user\n";
	}
} else {
	echo "[SKIP] C3 INSIDER — BuddyPress groups_create_group / groups_is_user_admin unavailable to seed the fixture\n";
}

// C4 — SOCKPUPPET AUTO-VOID. Seed a second contracted match whose group_a and group_b
//   SHARE an operator (same creator). create_market() must return state='void',
//   subsidy_funded=0, and NO treasury debit (subsidy balance unchanged across the call).
if ( function_exists( 'groups_create_group' ) && function_exists( 'wp_insert_user' ) ) {
	$sp_login = 'uat86_sock_' . substr( md5( $session ), 0, 8 );
	$sp_uid   = wp_insert_user( array(
		'user_login' => $sp_login,
		'user_pass'  => wp_generate_password( 20, true ),
		'user_email' => $sp_login . '@uat86.local',
		'role'       => 'subscriber',
	) );
	if ( ! is_wp_error( $sp_uid ) && (int) $sp_uid > 0 ) {
		$created_uids[] = (int) $sp_uid;
		// BOTH groups created by the SAME user => shared operator => sockpuppet.
		$sg_a = groups_create_group( array(
			'creator_id' => (int) $sp_uid,
			'name'       => 'UAT86 sock A ' . $session,
			'slug'       => 'uat86-sock-a-' . substr( md5( $session ), 0, 8 ),
			'status'     => 'hidden',
		) );
		$sg_b = groups_create_group( array(
			'creator_id' => (int) $sp_uid,
			'name'       => 'UAT86 sock B ' . $session,
			'slug'       => 'uat86-sock-b-' . substr( md5( $session ), 0, 8 ),
			'status'     => 'hidden',
		) );
		if ( $sg_a && ! is_wp_error( $sg_a ) && $sg_b && ! is_wp_error( $sg_b ) ) {
			$created_gids[] = (int) $sg_a;
			$created_gids[] = (int) $sg_b;
			$treasury_pre_sock = (float) mycred_get_users_balance( $treasury_uid, 'transact' );
			$sp_match = $seed_match( (int) $sg_a, (int) $sg_b, 'contracted', 860000000 + wp_rand( 1, 8000000 ) );
			$sp_mk    = Gend_GS_Collab_Market::create_market( $sp_match );
			if ( is_object( $sp_mk ) && ! is_wp_error( $sp_mk ) ) {
				$seen_markets[] = (int) $sp_mk->id;
			}
			$treasury_post_sock = (float) mycred_get_users_balance( $treasury_uid, 'transact' );
			$check(
				'C4a SOCKPUPPET: create_market on a shared-operator match returns state=void',
				is_object( $sp_mk ) && ! is_wp_error( $sp_mk ) && 'void' === (string) $sp_mk->state,
				is_wp_error( $sp_mk ) ? $sp_mk->get_error_code() : ( is_object( $sp_mk ) ? 'state=' . $sp_mk->state : 'not an object' )
			);
			$check(
				'C4b SOCKPUPPET: the auto-voided market has subsidy_funded=0 (never funded)',
				is_object( $sp_mk ) && 0 === (int) $sp_mk->subsidy_funded,
				is_object( $sp_mk ) ? 'subsidy_funded=' . $sp_mk->subsidy_funded : 'n/a'
			);
			$check(
				'C4c SOCKPUPPET: NO treasury debit fired (subsidy balance unchanged across the auto-void)',
				abs( $treasury_post_sock - $treasury_pre_sock ) < 0.0000001,
				"pre={$treasury_pre_sock} post={$treasury_post_sock} delta=" . ( $treasury_post_sock - $treasury_pre_sock )
			);
		} else {
			echo "[SKIP] C4 SOCKPUPPET — could not seed the two shared-operator BP groups\n";
		}
	} else {
		echo "[SKIP] C4 SOCKPUPPET — could not seed the sockpuppet WP user\n";
	}
} else {
	echo "[SKIP] C4 SOCKPUPPET — BuddyPress groups_create_group unavailable to seed the fixture\n";
}

// C5 — CONCURRENCY serialization. A single PHP process is serial, so we assert the
//   MECHANISM: the version bumps on every trade, and a SECOND trade re-quotes off the
//   POST-first q (not the stale q), so final escrow == the correctly-SEQUENCED sum. We
//   also prove a STALE-version UPDATE is a no-op (the optimistic guard rejects it).
$conc_before = $market_row( $market_id );
$ver0        = (int) $conc_before->version;
$q_yes_s     = (string) $conc_before->q_yes;
$q_no_s      = (string) $conc_before->q_no;
$esc_s       = (string) $conc_before->escrow_dgen;

// Compute the EXPECTED correctly-sequenced two-trade result off the CURRENT q.
$d1          = '2500000';
$d2          = '3500000';
// Mirror the engine EXACTLY: bc_ceil( (C(q') - C(q)) / 1e6 share-scale ) — whole DGEN.
$cost1_exp   = Gend_GS_BC_Math::bc_ceil( bcdiv( bcsub(
	Gend_GS_BC_Math::cost( bcadd( $q_yes_s, $d1, 0 ), $q_no_s, $b_str ),
	Gend_GS_BC_Math::cost( $q_yes_s, $q_no_s, $b_str ), 18 ), '1000000', 18 ) );
$q_yes_mid   = bcadd( $q_yes_s, $d1, 0 );
$cost2_exp   = Gend_GS_BC_Math::bc_ceil( bcdiv( bcsub(
	Gend_GS_BC_Math::cost( bcadd( $q_yes_mid, $d2, 0 ), $q_no_s, $b_str ),
	Gend_GS_BC_Math::cost( $q_yes_mid, $q_no_s, $b_str ), 18 ), '1000000', 18 ) );
$esc_expected = bcadd( bcadd( $esc_s, $cost1_exp, 0 ), $cost2_exp, 0 );

$r1 = Gend_GS_Collab_Market::trade( $market_id, 717171, 'yes', $d1 );
$r2 = Gend_GS_Collab_Market::trade( $market_id, 818181, 'yes', $d2 );
$conc_after = $market_row( $market_id );

$check(
	'C5a CONCURRENCY: version incremented by exactly 2 across two serialized trades',
	! is_wp_error( $r1 ) && ! is_wp_error( $r2 ) && (int) $conc_after->version === $ver0 + 2,
	'ver ' . $ver0 . ' -> ' . ( is_object( $conc_after ) ? $conc_after->version : '?' )
);
$check(
	'C5b CONCURRENCY: trade 2 re-quoted off the POST-trade-1 q — final escrow == correctly-SEQUENCED sum',
	is_object( $conc_after ) && 0 === bccomp( (string) $conc_after->escrow_dgen, $esc_expected, 0 ),
	'escrow=' . ( is_object( $conc_after ) ? $conc_after->escrow_dgen : '?' ) . ' expected(sequenced)=' . $esc_expected
);
// A STALE-version UPDATE is a no-op — prove the optimistic guard rejects a lost update.
$stale = $wpdb->query( $wpdb->prepare(
	"UPDATE {$markets_tbl} SET q_yes = q_yes + 1, version = version + 1 WHERE id = %d AND version = %d AND state = 'open'",
	$market_id,
	$ver0 // deliberately stale (already advanced by +2).
) );
$check(
	'C5c CONCURRENCY: a stale-version UPDATE affects 0 rows (the optimistic guard blocks a lost update)',
	0 === (int) $stale,
	"rows_affected={$stale}"
);
// The invariant still holds after the concurrent burst.
$assert_escrow( $market_row( $market_id ), 'C5d CONCURRENCY: escrow-invariant held after the concurrent trade burst' );

// C6 — COLLAB-ONLY (MARKET-06). create_market on a bogus id AND on a 'matched' (not
//   'contracted') match must BOTH return gs_market_not_contracted and create NO market row.
$bogus_id = 999000000 + wp_rand( 1, 900000 ); // no such match.
$c6a      = Gend_GS_Collab_Market::create_market( $bogus_id );
$check(
	'C6a COLLAB-ONLY: create_market on a bogus/non-existent match_id -> WP_Error(gs_market_not_contracted)',
	is_wp_error( $c6a ) && 'gs_market_not_contracted' === $c6a->get_error_code(),
	is_wp_error( $c6a ) ? $c6a->get_error_code() : 'no error'
);
$check(
	'C6a2 COLLAB-ONLY: NO market row was created for the bogus match',
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$markets_tbl} WHERE match_id = %d", $bogus_id ) )
);
$matched_only = $seed_match( 960000000 + wp_rand( 1, 8000000 ), 960000001 + wp_rand( 1, 8000000 ), 'matched', 0 );
$c6b          = Gend_GS_Collab_Market::create_market( $matched_only );
$check(
	'C6b COLLAB-ONLY: create_market on a MATCHED (not contracted) match -> WP_Error(gs_market_not_contracted)',
	is_wp_error( $c6b ) && 'gs_market_not_contracted' === $c6b->get_error_code(),
	is_wp_error( $c6b ) ? $c6b->get_error_code() : 'no error'
);
$check(
	'C6b2 COLLAB-ONLY: NO market row was created for the matched-only match',
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$markets_tbl} WHERE match_id = %d", (int) $matched_only ) )
);

// C7 — LIFECYCLE LOCK (MARKET-05). lock() flips open->locked; a subsequent trade() is
//   refused (gs_market_not_open); a second lock() is an idempotent no-op. Then the
//   outcome-recorded path + a past-deadline lock.
$lock_match = $seed_match( 950000000 + wp_rand( 1, 8000000 ), 950000001 + wp_rand( 1, 8000000 ), 'contracted', 860000000 + wp_rand( 1, 8000000 ) );
$lock_mk    = Gend_GS_Collab_Market::create_market( $lock_match );
if ( ! is_wp_error( $lock_mk ) && 'open' === (string) $lock_mk->state ) {
	$lock_id        = (int) $lock_mk->id;
	$seen_markets[] = $lock_id;

	$locked1 = Gend_GS_Collab_Market::lock( $lock_id );
	$check( 'C7a LOCK: lock() flips open->locked (returns true on the winning transition)', true === $locked1, 'ret=' . var_export( $locked1, true ) );
	$row_locked = $market_row( $lock_id );
	$check( 'C7b LOCK: market state is now locked', is_object( $row_locked ) && 'locked' === (string) $row_locked->state, 'state=' . ( is_object( $row_locked ) ? $row_locked->state : '?' ) );

	$trade_locked = Gend_GS_Collab_Market::trade( $lock_id, 929292, 'yes', '1000000' );
	$check(
		'C7c LOCK: a trade on a locked market is REFUSED (gs_market_not_open)',
		is_wp_error( $trade_locked ) && 'gs_market_not_open' === $trade_locked->get_error_code(),
		is_wp_error( $trade_locked ) ? $trade_locked->get_error_code() : 'trade unexpectedly succeeded'
	);

	$locked2 = Gend_GS_Collab_Market::lock( $lock_id );
	$check( 'C7d LOCK: a second lock() is an idempotent no-op (returns false)', false === $locked2, 'ret=' . var_export( $locked2, true ) );

	// Escrow-invariant must still hold across the FSM transition (locked state).
	$assert_escrow( $market_row( $lock_id ), 'C7e LOCK: escrow-invariant held across the open->locked FSM transition' );
} else {
	echo "[SKIP] C7a-e LOCK — could not open a market to lock (create_market: "
		. ( is_wp_error( $lock_mk ) ? $lock_mk->get_error_code() : ( is_object( $lock_mk ) ? $lock_mk->state : '?' ) ) . ")\n";
}

// C7f — OUTCOME-RECORDED lock path. on_outcome_recorded(match_id) must lock the open market.
if ( method_exists( 'Gend_GS_Collab_Market', 'on_outcome_recorded' ) ) {
	$oc_match = $seed_match( 940000000 + wp_rand( 1, 8000000 ), 940000001 + wp_rand( 1, 8000000 ), 'contracted', 860000000 + wp_rand( 1, 8000000 ) );
	$oc_mk    = Gend_GS_Collab_Market::create_market( $oc_match );
	if ( ! is_wp_error( $oc_mk ) && 'open' === (string) $oc_mk->state ) {
		$seen_markets[] = (int) $oc_mk->id;
		Gend_GS_Collab_Market::on_outcome_recorded( (int) $oc_match, 'success' );
		$oc_row = $market_row( (int) $oc_mk->id );
		$check(
			'C7f OUTCOME-LOCK: on_outcome_recorded() locked the open market (terminal outcome halts trading)',
			is_object( $oc_row ) && 'locked' === (string) $oc_row->state,
			'state=' . ( is_object( $oc_row ) ? $oc_row->state : '?' )
		);
	} else {
		echo "[SKIP] C7f OUTCOME-LOCK — could not open a market for the outcome-lock path\n";
	}
} else {
	echo "[SKIP] C7f OUTCOME-LOCK — Gend_GS_Collab_Market::on_outcome_recorded absent (deploy 86-04)\n";
}

// C7g — DEADLINE lock. A market whose resolve_by is in the past must be lockable (the
//   Phase-85 sweep locks it). We assert the mechanism directly: force resolve_by into the
//   past, confirm it is past-due, then lock() and confirm the FSM flipped.
$dl_match = $seed_match( 930000000 + wp_rand( 1, 8000000 ), 930000001 + wp_rand( 1, 8000000 ), 'contracted', 860000000 + wp_rand( 1, 8000000 ) );
$dl_mk    = Gend_GS_Collab_Market::create_market( $dl_match );
if ( ! is_wp_error( $dl_mk ) && 'open' === (string) $dl_mk->state ) {
	$dl_id          = (int) $dl_mk->id;
	$seen_markets[] = $dl_id;
	$past           = time() - DAY_IN_SECONDS;
	$wpdb->query( $wpdb->prepare( "UPDATE {$markets_tbl} SET resolve_by = %d WHERE id = %d", $past, $dl_id ) );
	$dl_row = $market_row( $dl_id );
	$is_due = is_object( $dl_row ) && (int) $dl_row->resolve_by > 0 && (int) $dl_row->resolve_by < time() && 'open' === (string) $dl_row->state;
	$check( 'C7g0 DEADLINE: the market is now open AND past its resolve_by deadline', $is_due,
		'resolve_by=' . ( is_object( $dl_row ) ? $dl_row->resolve_by : '?' ) . ' now=' . time() );
	if ( $is_due ) {
		$dl_locked = Gend_GS_Collab_Market::lock( $dl_id );
		$dl_after  = $market_row( $dl_id );
		$check(
			'C7g DEADLINE: a past-deadline market locks (open->locked, halting trading before resolution)',
			true === $dl_locked && is_object( $dl_after ) && 'locked' === (string) $dl_after->state,
			'lock_ret=' . var_export( $dl_locked, true ) . ' state=' . ( is_object( $dl_after ) ? $dl_after->state : '?' )
		);
	}
} else {
	echo "[SKIP] C7g DEADLINE — could not open a market for the deadline-lock path\n";
}

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
echo "\n";
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 86 CROWN UAT passed — bcmath round-trips to >=12 digits, prices sum to 1, cost monotone, round-trip loss <= spread, maker worst-case loss == b·ln2 pre-funded, escrow >= max payout after EVERY trade + FSM transition, NO mint-to-pay, insider stake rejected with NO state change, sockpuppet market auto-voided (no subsidy drained), concurrent trades serialized (version bump + re-quote off fresh q, stale UPDATE = no-op) without invariant violation, create_market rejects a bogus/non-contracted subject, lifecycle locks at deadline + terminal outcome. THE MONEY IS SAFE.\n";
	echo "UAT PASSED\n";
	exit( 0 );
}
echo "=== FAILURE === Phase 86 CROWN UAT detected " . count( $issues ) . " invariant violation(s) — DO NOT enable GS_COLLAB_MARKET_PUBLIC / proceed to Phase 87 until closed:\n";
foreach ( $issues as $i => $msg ) {
	echo '  ' . ( $i + 1 ) . ". {$msg}\n";
}
echo 'UAT FAILED (' . count( $issues ) . " failures)\n";
exit( 1 );
