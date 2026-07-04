<?php
/**
 * gend-society — GenD Match DARK terminal-outcome recorder + 15-min cron backstop
 * (Phase 85-02, RESOLVE-03, v12.0).
 *
 * The RECORDER the future prediction market (Phase 86) will resolve off. It lands
 * BEFORE any payout code (Phase 88) so full historical terminal state is already
 * recorded when the market later turns on. Phase 85 moves NO money and pays out
 * nothing — record() writes the outcomes row, audits, chain-anchors, and STOPS.
 *
 * Subscribes to the two deterministic terminal signals on Gend_CP_Task_Contract:
 *   - gend_cp_task_contract_paid       (existing SUCCESS hook)          => record success
 *   - gend_cp_task_contract_terminated (Plan 85-01 FAIL/VOID do_action) => record fail|void
 *     ($outcome: forfeited => fail, cancelled(mutual) => void; cron expiry => fail).
 *
 * RUN DARK (LOCKED DECISION): the recorder RECORDS ALWAYS, REGARDLESS of the
 * Phase-86+ market counsel flag. That flag gates the future MARKET SURFACE, not
 * this recorder (RESEARCH Pitfall 4). The ONLY gate here is hub-only (is_main_node()).
 *
 * IDEMPOTENCY SPINE: every path funnels through record() -> INSERT IGNORE against
 * gs_collab_contract_outcomes.UNIQUE(contract_task_id). A hook firing AND the 15-min
 * cron sweep for the same contract collapse to exactly ONE row; the audit + anchor
 * fire ONLY on a genuinely-new row (rows_affected === 1) — never on the ignored
 * duplicate (RESEARCH Pitfall 3 — never duplicate anchors for one outcome).
 *
 * The generic hooks fire for EVERY task contract, not just collab ones (RESEARCH
 * Pitfall 2). record() resolves contract_task_id -> gs_collab_matches; a task with
 * NO matching contracted match is SILENTLY SKIPPED — ordinary non-collab task
 * contracts are never recorded.
 *
 * COLD-START tolerant (memory: project_hub_probe_timeouts, 10-25s): the sweep is
 * LIMIT 50 per run, meta-reads + one INSERT IGNORE (+ one anchor) per row only;
 * never scans all tasks. Any backlog drains over successive 15-min ticks.
 *
 * Every cross-plugin call (Gend_CP_Audit_Log, Gend_Chain_Validator, PSOO_PM_Contracts,
 * PSOO_PM_Tasks) is class_exists/method_exists-guarded so a partial cross-submodule
 * deploy degrades cleanly instead of fataling (memory: project_wp_fatal_auto_deactivation).
 *
 * NO market / NO LMSR / NO staking / NO payout / NO VOID-refund code — those are
 * Phases 86/88. DGEN 1:1 CAD peg untouched (nothing debited/credited here).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Resolver {

	/** Chain-anchor tx type — 'chain.'-prefixed => gas + signature exempt system tx. */
	const ANCHOR_TYPE = 'chain.collab.contract_outcome';

	/** Cron interval slug + hook name. */
	const CRON_INTERVAL = 'gs_fifteen_min';
	const CRON_HOOK     = 'gs_collab_resolve_sweep';

	/**
	 * Container-side outbox-drain hook (Phase 89-02, FED-01). Reuses the EXISTING
	 * gs_fifteen_min interval — NO new cron interval. init() (hub) drains nothing here;
	 * this is a SEPARATE hook because sweep() is hub-only (early-returns on a container).
	 */
	const OUTBOX_HOOK = 'gs_collab_outbox_drain';

	/**
	 * Wire hooks + the 15-min cron. Hub-only (is_hub()); NOT gated on the
	 * Phase-86+ market counsel flag (locked — run dark). Called from
	 * gend-society.php after the collab requires.
	 */
	public static function init() : void {
		if ( ! self::is_hub() ) {
			return;
		}

		// SUCCESS + FAIL/VOID terminal signals from Gend_CP_Task_Contract.
		add_action( 'gend_cp_task_contract_paid', array( __CLASS__, 'on_paid' ), 20, 3 );
		add_action( 'gend_cp_task_contract_terminated', array( __CLASS__, 'on_terminated' ), 20, 3 );

		// 15-min cron backstop.
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sweep' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * CONTAINER-side wiring (Phase 89-02, FED-01) — schedule the outbox drain on the
	 * EXISTING gs_fifteen_min interval (NO new interval). Self-gates on ! is_hub() (init()
	 * is hub-only and won't run here). The interval is registered by register_interval();
	 * the hub adds that filter in init(), but a container never calls init(), so we add
	 * the SAME idempotent filter here too or gs_fifteen_min would not resolve. The drain
	 * LOGIC lives in Gend_GS_Collab_Sync::drain_outbox (best-effort, non-blocking).
	 *
	 * Called from gend-society.php.
	 */
	public static function init_container() : void {
		if ( self::is_hub() ) {
			return; // the outbox lives on containers only.
		}

		// Ensure the gs_fifteen_min interval resolves on a container (idempotent filter;
		// register_interval only adds the slug if unset — reused, NOT a new interval).
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) );

		// Bind the drain + schedule it on the existing 15-min cadence.
		if ( class_exists( 'Gend_GS_Collab_Sync' ) ) {
			add_action( self::OUTBOX_HOOK, array( 'Gend_GS_Collab_Sync', 'drain_outbox' ) );
		}
		if ( ! wp_next_scheduled( self::OUTBOX_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::OUTBOX_HOOK );
		}
	}

	/**
	 * Register the 15-minute cron interval (idempotent — only if unset).
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function register_interval( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'Every 15 Minutes',
			);
		}
		return $schedules;
	}

	/**
	 * gend_cp_task_contract_paid($task_id,$awarded_to,$dgen) => record SUCCESS.
	 *
	 * @param int $task_id    Contract task id.
	 * @param int $awarded_to Unused.
	 * @param int $dgen       Unused.
	 */
	public static function on_paid( $task_id, $awarded_to = 0, $dgen = 0 ) : void {
		self::record( (int) $task_id, 'success', 'completed', 'hook' );
	}

	/**
	 * gend_cp_task_contract_terminated($task_id,$outcome,$context) => record FAIL|VOID.
	 * forfeited (or anything not 'cancelled') => fail; cancelled(mutual) => void.
	 *
	 * @param int    $task_id Contract task id.
	 * @param string $outcome 'forfeited' | 'cancelled'.
	 * @param array  $context Optional { reason, actor, match_id, ... }.
	 */
	public static function on_terminated( $task_id, $outcome, $context = array() ) : void {
		$map    = ( 'cancelled' === $outcome ) ? 'void' : 'fail';
		$reason = ( 'cancelled' === $outcome )
			? 'cancelled'
			: ( is_array( $context ) && ! empty( $context['reason'] ) ? (string) $context['reason'] : 'forfeited' );
		self::record( (int) $task_id, $map, $reason, 'hook' );
	}

	/**
	 * Idempotently record ONE terminal outcome for a CONTRACTED match, then audit
	 * + chain-anchor it (only on a genuinely-new row). NO payout / NO money.
	 *
	 * Order: resolve match -> INSERT IGNORE row FIRST -> audit -> anchor -> persist
	 * chain_tx_id back onto the row. On the ignored duplicate (rows_affected !== 1)
	 * do NOTHING (hook + cron collapsed).
	 *
	 * @param int    $task_id Contract task id (gs_collab_matches.contract_task_id).
	 * @param string $outcome 'success' | 'fail' | 'void'.
	 * @param string $reason  completed | forfeited | expired | cancelled.
	 * @param string $source  'hook' | 'cron'.
	 * @return void
	 */
	public static function record( $task_id, $outcome, $reason, $source ) : void {
		global $wpdb;

		$task_id = (int) $task_id;
		if ( $task_id <= 0 ) {
			return;
		}
		$outcome = in_array( $outcome, array( 'success', 'fail', 'void' ), true ) ? $outcome : 'fail';
		$source  = ( 'cron' === $source ) ? 'cron' : 'hook';
		$reason  = substr( (string) $reason, 0, 64 );

		$matches  = Gend_GS_Collab_Schema::matches_table();
		$outcomes = Gend_GS_Collab_Schema::contract_outcomes_table();

		// Resolve match_id from contract_task_id. Non-collab task contracts fire
		// the same generic hook — silently skip anything with no matching match
		// (RESEARCH Pitfall 2).
		$match_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$matches} WHERE contract_task_id = %d LIMIT 1",
				$task_id
			)
		);
		if ( $match_id <= 0 ) {
			return;
		}

		// IDEMPOTENCY SPINE: INSERT IGNORE against UNIQUE(contract_task_id).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$outcomes}
					(match_id, contract_task_id, outcome, reason, source, recorded_at)
				 VALUES (%d, %d, %s, %s, %s, %d)",
				$match_id,
				$task_id,
				$outcome,
				$reason,
				$source,
				time()
			)
		);

		// Only a genuinely-new row (rows_affected === 1) gets audited + anchored.
		// An ignored duplicate (hook + cron collapsed) is a silent no-op — NEVER a
		// second audit or anchor (RESEARCH Pitfall 3).
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return;
		}

		$details = array(
			'match_id'         => $match_id,
			'contract_task_id' => $task_id,
			'outcome'          => $outcome,
			'reason'           => $reason,
			'source'           => $source,
		);

		// AUDIT (guarded) — write($event_type,$subject_user_id,$details,$actor_user_id,$chain_tx_id).
		if ( class_exists( 'Gend_CP_Audit_Log' ) && method_exists( 'Gend_CP_Audit_Log', 'write' ) ) {
			Gend_CP_Audit_Log::write( 'collab.contract_outcome', 0, $details, 0, null );
		}

		// CHAIN ANCHOR (guarded) — chain.* system tx (gas + signature exempt).
		if ( class_exists( 'Gend_Chain_Validator' ) && method_exists( 'Gend_Chain_Validator', 'submit_tx' ) ) {
			$chain_tx_id = Gend_Chain_Validator::submit_tx(
				array(
					'type'         => self::ANCHOR_TYPE,
					'from_app_id'  => '',
					'from_user_id' => 0,
					'payload'      => $details,
					'ts'           => time(),
				)
			);
			if ( ! empty( $chain_tx_id ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$outcomes} SET chain_tx_id = %s WHERE contract_task_id = %d",
						substr( (string) $chain_tx_id, 0, 64 ),
						$task_id
					)
				);
			}
		}

		// GenD Match (Phase 86-04, MARKET-05): a genuinely-new terminal outcome was just
		// recorded — fire the market LOCK trigger. Gend_GS_Collab_Market::on_outcome_recorded
		// looks up the market for this match and lock()s it (idempotent CAS). This runs
		// FLAG-INDEPENDENT (the recorder already runs dark): locking a dark market is
		// harmless and keeps the FSM correct for when GS_COLLAB_MARKET_PUBLIC flips on.
		// The handler no-ops if no market exists (dark = none created).
		do_action( 'gend_gs_collab_outcome_recorded', (int) $match_id, (string) $outcome );

		// record() STOPS here — NO payout, NO market, NO money movement.
	}

	/**
	 * 15-min cron backstop (hub-only, batched). Finds CONTRACTED matches with a
	 * contract_task_id and NO recorded outcome (LEFT JOIN), reads each contract's
	 * terminal state, and records any a missed hook left unobserved — including a
	 * passed due_date -> fail(expired). LIMIT 50 per run (cold-start tolerance);
	 * backlog drains over ticks. Idempotent with the hooks via record().
	 */
	public static function sweep() : void {
		if ( ! self::is_hub() ) {
			return;
		}
		global $wpdb;

		$matches  = Gend_GS_Collab_Schema::matches_table();
		$outcomes = Gend_GS_Collab_Schema::contract_outcomes_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id AS match_id, m.contract_task_id
				   FROM {$matches} m
				   LEFT JOIN {$outcomes} o ON o.contract_task_id = m.contract_task_id
				  WHERE m.status = 'contracted'
				    AND m.contract_task_id IS NOT NULL
				    AND o.id IS NULL
				  LIMIT %d",
				50
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		$has_contracts = class_exists( 'PSOO_PM_Contracts' ) && method_exists( 'PSOO_PM_Contracts', 'get_task_meta' );
		$has_market    = class_exists( 'Gend_GS_Collab_Market' );

		foreach ( $rows as $r ) {
			$tid = (int) $r->contract_task_id;
			if ( $tid <= 0 ) {
				continue;
			}

			// GenD Match (Phase 86-04): MARKET-01 backstop-create + MARKET-05 deadline-lock.
			// Additive to the outcome-recording branches below (which are unchanged). Every
			// engine call is class_exists-guarded so a partial deploy degrades cleanly.
			if ( $has_market ) {
				$mid = (int) $r->match_id;

				// (a) BACKSTOP-CREATE (MARKET-01) — GATED on GS_COLLAB_MARKET_PUBLIC (mirrors
				// the on_contracted hook gate): if the flag is on and a contracted match has
				// NO market yet (a missed hook), create one now. create_market is idempotent
				// via UNIQUE(match_id); while dark this branch never runs (no market, no subsidy).
				if ( defined( 'GS_COLLAB_MARKET_PUBLIC' ) && GS_COLLAB_MARKET_PUBLIC && $mid > 0 ) {
					Gend_GS_Collab_Market::on_contracted( $mid, $tid );
				}

				// (b) DEADLINE-LOCK (MARKET-05) — FLAG-INDEPENDENT: lock any OPEN market whose
				// contract due_date has passed so nobody trades on a soon-known outcome. Reuses
				// the SAME due_date read as branch 3 below. lock() is an idempotent CAS.
				if ( $mid > 0 && class_exists( 'PSOO_PM_Tasks' ) && method_exists( 'PSOO_PM_Tasks', 'get' ) ) {
					$mkt = Gend_GS_Collab_Market::get_open_market_for_match( $mid );
					if ( is_object( $mkt ) ) {
						$mtask = PSOO_PM_Tasks::get( $tid );
						if ( $mtask && ! empty( $mtask->due_date ) ) {
							$mdue = strtotime( $mtask->due_date );
							if ( $mdue && $mdue < time() ) {
								Gend_GS_Collab_Market::lock( (int) $mkt->id );
							}
						}
					}
				}
			}

			// 1) Missed SUCCESS: contract already paid.
			if ( $has_contracts && '1' === (string) PSOO_PM_Contracts::get_task_meta( $tid, '_contract_dgen_paid' ) ) {
				self::record( $tid, 'success', 'completed', 'cron' );
				continue;
			}

			// 2) Missed FAIL/VOID: contract already terminated (guard meta set).
			if ( $has_contracts ) {
				$term = (string) PSOO_PM_Contracts::get_task_meta( $tid, '_contract_terminated' );
				if ( '' !== $term ) {
					$map = ( 'cancelled' === $term ) ? 'void' : 'fail';
					self::record( $tid, $map, ( 'cancelled' === $term ? 'cancelled' : 'forfeited' ), 'cron' );
					continue;
				}
			}

			// 3) Deadline expiry (cron-only — no contract-plugin branch): due_date
			// past + non-terminal => fail(expired). Record DIRECTLY (do NOT call
			// terminate() — avoids a self-trigger loop; RESEARCH Open-Q2).
			if ( class_exists( 'PSOO_PM_Tasks' ) && method_exists( 'PSOO_PM_Tasks', 'get' ) ) {
				$task = PSOO_PM_Tasks::get( $tid );
				if ( $task && ! empty( $task->due_date ) ) {
					$due = strtotime( $task->due_date );
					if ( $due && $due < time() ) {
						self::record( $tid, 'fail', 'expired', 'cron' );
					}
				}
			}
		}

		// GenD Match (Phase 88-03): settlement drain — two flag-INDEPENDENT market-level
		// branches that run ONCE per sweep (NOT per contracted-match row above — these query
		// gs_collab_markets directly, and the payout drain is over markets that DO have an
		// outcome, so they can't live inside the o.id-IS-NULL foreach). Both are
		// class_exists/method_exists-guarded so a partial deploy without the 88-02 engine
		// degrades cleanly, and both are batch-limited (LIMIT 50) so a hub 10-25s cold-start
		// is tolerated — any backlog drains over successive 15-min ticks. NO new cron, NO new
		// hook; this is the resumable payout rail the 88-02 engine was built for.
		$has_market_engine = class_exists( 'Gend_GS_Collab_Market' );

		// (c) DEADLINE-VOID (RESOLVE-04) — auto-VOID any open/locked market past its
		// resolve_by with NO recorded contract outcome. Distinct from the Phase-85 contract
		// deadline fail(expired) above (that is the CONTRACT deadline); this is the MARKET
		// deadline, the safe default (never a forced guess). resolve($id,'void') lock-then-
		// resolves the market DIRECTLY — no round-trip through the contract (avoids a
		// self-trigger loop; mirrors the fail(expired) DIRECT-record idiom above).
		if ( $has_market_engine && method_exists( 'Gend_GS_Collab_Market', 'resolve' ) ) {
			$markets_t  = Gend_GS_Collab_Schema::markets_table();
			$outcomes_t = Gend_GS_Collab_Schema::contract_outcomes_table();
			$due_void   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.id FROM {$markets_t} m
					   LEFT JOIN {$outcomes_t} o ON o.contract_task_id = m.contract_task_id
					  WHERE m.state IN ('open','locked')
					    AND m.resolve_by IS NOT NULL AND m.resolve_by < %d
					    AND o.id IS NULL
					  LIMIT %d",
					time(),
					50
				)
			);
			foreach ( (array) $due_void as $mv ) {
				// resolve() lock-then-resolves to VOID directly (idempotent CAS). RESOLVE-04.
				Gend_GS_Collab_Market::resolve( (int) $mv->id, 'void' );
			}
		}

		// (d) RESUMABLE PAYOUT-DRAIN (RESOLVE-02/04) — for every market still in state
		// 'resolved' (outcome known, not yet fully paid), drain a batch of unpaid positions.
		// pay_market_batch() claims each position (paid=1 WHERE paid=0) BEFORE the mycred_add
		// (position id = MyCred ref_id -> dedup), then flips resolved->paid + returns
		// rake/residual to the treasury once ALL positions are settled. A cold-start mid-batch
		// RESUMES on the next tick and NEVER double-pays (idx_market_paid batch scan). NO
		// transaction wrapper (88-RESEARCH anti-pattern). A market VOID'd in (c) this tick
		// becomes 'resolved' and is drained here this tick or the next — both correct (resumable).
		if ( $has_market_engine && method_exists( 'Gend_GS_Collab_Market', 'pay_market_batch' ) ) {
			$markets_t = Gend_GS_Collab_Schema::markets_table();
			$to_pay    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$markets_t} WHERE state='resolved' LIMIT %d",
					50
				)
			);
			foreach ( (array) $to_pay as $rmid ) {
				Gend_GS_Collab_Market::pay_market_batch( (int) $rmid );
			}
		}
	}

	/**
	 * Hub-only gate. Mirrors class-collab-contract.php:66-70 — true on the main
	 * node (or when the OAuth resource isn't present at all, i.e. a lone hub),
	 * false on a container.
	 *
	 * @return bool
	 */
	private static function is_hub() : bool {
		return ! class_exists( 'Gend_CP_OAuth_Resource' )
			|| ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
			|| Gend_CP_OAuth_Resource::is_main_node();
	}
}
