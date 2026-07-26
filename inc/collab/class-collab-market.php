<?php
/**
 * gend-society — THE cohesive LMSR prediction-market engine (Phase 86-03, v12.0).
 *
 * The single most safety-critical unit of the GenD Match milestone. In ONE class,
 * indivisibly (THE CARDINAL RULE — do NOT split "quote now, settle later"):
 *
 *   - create_market( int $match_id )  — the ONLY market-creation entrypoint. Collab-only
 *       (MARKET-06: NO subject/type param; hard-rejects any non-contracted match), same-
 *       operator/sockpuppet auto-void (STAKE-03), subsidy pre-fund-then-open ordering.
 *   - fund_subsidy( object $row )      — a REAL balance-checked treasury DGEN debit
 *       (gend_cp_hub_fee_user_id) of ceil(b·ln2), chain-anchored, idempotent via the
 *       subsidy_funded flag; a market that cannot fund its subsidy cannot open (MARKET-03).
 *   - quote( id, outcome, delta )      — read-only live implied YES/NO odds + cost (MARKET-04).
 *   - trade( id, uid, outcome, delta ) — START TRANSACTION + SELECT ... FOR UPDATE re-quote
 *       off the LOCKED q, escrow-invariant HARD GATE (rollback+reject, NEVER mint), optimistic
 *       version bump, insider rejection before any state change (RESOLVE-05 + STAKE-03).
 *   - lock( int $market_id )           — idempotent CAS open->locked (MARKET-05 FSM).
 *
 * MONEY-SAFETY INVARIANTS (non-negotiable, implemented exactly per 86-CONTEXT/86-RESEARCH):
 *   - Escrow-invariant: escrow_dgen >= max(q_yes, q_no) (GROSS, rake ignored at the gate)
 *     after every trade. By LMSR construction subsidy(b·ln2) + Σ trade-cost >= max(q); the
 *     engine ENFORCES it as a hard gate — a violating post-state is ROLLED BACK and REJECTED.
 *     NO code path mints DGEN to cover a shortfall.
 *   - Concurrency: FOR UPDATE on the market row + re-quote off the locked q + optimistic
 *     version=version+1 guard (mirror contracts-and-payments/class-ydgen-ledger.php:503-515).
 *   - Insider: admin/mod of EITHER matched group is barred from that market (two-arg
 *     groups_is_user_admin/mod on group_a AND group_b), rejected BEFORE any state change.
 *   - Sockpuppet: two matched groups sharing an admin/mod/creator auto-void the market at
 *     create (no subsidy, no open).
 *
 * SCOPE (Phase 86): trade() operates on a SIMULATED stake ledger — Phase 86 does NOT debit
 * the real bettor. The ONLY real DGEN move here is the treasury subsidy (fund_subsidy). The
 * real bettor DGEN-debit bet route, portfolio, resolution/payout/VOID-refund and the ACTUAL
 * rake skim are Phase 87/88. rake_bps is stored/modeled but never skimmed here.
 *
 * NO PHP float in the money leg: every cost/subsidy/escrow computation routes through
 * Gend_GS_BC_Math (bcmath, SCALE=18) + bcmath string ops. Every cross-plugin call is
 * class_exists/function_exists/method_exists-guarded (house idiom — a partial deploy
 * degrades cleanly, never fatals; memory: project_wp_fatal_auto_deactivation).
 *
 * The engine METHODS are flag-INDEPENDENT plain statics (no counsel-gate constant guard
 * inside) so the crown UAT (86-05) can prove the math regardless of the flag; the flag gates
 * the CALLERS (auto-create hook, sweep, REST routes) in 86-04.
 *
 * @package gend-society
 * @since   v12.0 (Phase 86)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Gend_GS_Collab_Market' ) ) {

	/**
	 * The indivisible LMSR market engine (create/subsidy/quote/trade/lock + FSM).
	 */
	class Gend_GS_Collab_Market {

		/* -----------------------------------------------------------------
		 * chain.* system-tx types (all gas + signature exempt; <=80 chars,
		 * ^[a-z0-9._-]+$). NEW in Phase 86.
		 * ----------------------------------------------------------------- */

		/** Anchored when a market row is created (open or auto-void). */
		const TX_CREATED = 'chain.market.created';

		/** Anchored when the treasury subsidy is really debited into escrow. */
		const TX_SUBSIDY = 'chain.market.subsidy';

		/**
		 * Anchored (from_uid = the bettor) AFTER a real member bet commits
		 * (Phase 87 — place_bet buy debit / sell refund). Unlike TX_SUBSIDY (from
		 * the treasury), this carries the staking member as the originating uid.
		 */
		const TX_BET = 'chain.market.bet';

		/** Anchored on the open->locked FSM transition. */
		const TX_LOCKED = 'chain.market.locked';

		/** Anchored on the same-operator auto-void at create. */
		const TX_VOID = 'chain.market.void';

		/**
		 * Anchored (from_uid = the winning bettor) when a winning position is credited
		 * its bc_floor( shares × (1 − rake) ) DGEN payout at settlement (Phase 88 —
		 * pay_market_batch). One anchor per credited winner position.
		 */
		const TX_PAYOUT = 'chain.market.payout';

		/**
		 * Anchored (from_uid = 0 system) when the residual escrow (subsidy-not-lost +
		 * withheld rake + per-winner floor dust) drains to the treasury on the final
		 * resolved→paid flip (Phase 88). Carries {residual_dgen, rake_bps}.
		 */
		const TX_RESIDUAL = 'chain.market.residual';

		/**
		 * Anchored (from_uid = the bettor) when a position is VOID-refunded its
		 * cost_dgen verbatim (no rake) on a voided market — mutual-cancel outcome or a
		 * resolve_by deadline with no recorded outcome (Phase 88, RESOLVE-04).
		 */
		const TX_REFUND = 'chain.market.refund';

		/**
		 * Global default liquidity depth `b` (scaled-int micro-units, 1e6/share) used
		 * when neither the market row nor the gs_collab_market_default_b option supplies
		 * one. Higher b = deeper/steadier odds + larger subsidy (b·ln2).
		 */
		const DEFAULT_B = 100000000; // 100 shares @ 1e6 micro-shares/share.

		/**
		 * Default market time-to-live (seconds) used for resolve_by when the contract
		 * task has no due_date. ~90 days.
		 */
		const DEFAULT_TTL = 7776000; // 90 * 24 * 3600.

		/* -----------------------------------------------------------------
		 * Private helpers
		 * ----------------------------------------------------------------- */

		/**
		 * Hub-only gate. Mirrors class-collab-contract.php:66-70 /
		 * resolver.php:305-309 — true on the main node (or when the OAuth resource
		 * isn't present at all, i.e. a lone hub), false on a container.
		 *
		 * @return bool
		 */
		private static function is_hub() : bool {
			return ! class_exists( 'Gend_CP_OAuth_Resource' )
				|| ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
				|| Gend_CP_OAuth_Resource::is_main_node();
		}

		/**
		 * The platform treasury / subsidy wallet — the SAME escrow-holder MyCred user
		 * Phase-84 already uses (gend_cp_hub_fee_user_id option, else first admin, else 1).
		 * Mirrors class-collab-contract.php:865-872.
		 *
		 * @return int Treasury user id.
		 */
		private static function treasury_uid() : int {
			$opt = (int) get_option( 'gend_cp_hub_fee_user_id', 0 );
			if ( $opt > 0 ) {
				return $opt;
			}
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			return ! empty( $admins ) ? (int) $admins[0] : 1;
		}

		/**
		 * Per-market liquidity depth `b`: the market row's lmsr_b when set (>0), else the
		 * global gs_collab_market_default_b option, else DEFAULT_B. Planner's discretion is
		 * a GLOBAL default with the per-market column as the override.
		 *
		 * @param object|null $market_row Optional market row (uses ->lmsr_b if >0).
		 * @return string Scaled-int liquidity depth as a decimal string.
		 */
		private static function market_b( $market_row = null ) : string {
			if ( is_object( $market_row ) && isset( $market_row->lmsr_b ) && (int) $market_row->lmsr_b > 0 ) {
				return (string) (int) $market_row->lmsr_b;
			}
			$global = (int) get_option( 'gs_collab_market_default_b', 0 );
			if ( $global > 0 ) {
				return (string) $global;
			}
			return (string) self::DEFAULT_B;
		}

		/**
		 * Per-market rake in basis points: the market row's rake_bps when set (>0), else
		 * the global gs_collab_market_rake_bps option, else 0. STORED/MODELED only — the
		 * ACTUAL skim is Phase 88. The escrow-invariant uses the GROSS max payout so
		 * reserving rake can never cause a shortfall.
		 *
		 * @param object|null $market_row Optional market row (uses ->rake_bps if >0).
		 * @return int rake basis points.
		 */
		private static function rake_bps( $market_row = null ) : int {
			if ( is_object( $market_row ) && isset( $market_row->rake_bps ) && (int) $market_row->rake_bps > 0 ) {
				return (int) $market_row->rake_bps;
			}
			return (int) get_option( 'gs_collab_market_rake_bps', 0 );
		}

		/**
		 * Maximum possible payout of a market in integer DGEN units: max(q_yes, q_no),
		 * since a share redeems at exactly 1 DGEN and only ONE outcome wins. This is the
		 * escrow-invariant RHS. Uses the GROSS max (rake ignored) so the gate is worst-case.
		 *
		 * The stored q are micro-shares (1e6/share); a payout is 1 DGEN per WHOLE share,
		 * so max_payout = floor( max(q_yes,q_no) / 1e6 ) integer DGEN. Rounded DOWN so the
		 * gate demands escrow >= the largest integer-DGEN liability (maker-favor on the RHS).
		 *
		 * @param string $q_yes YES micro-shares.
		 * @param string $q_no  NO micro-shares.
		 * @return string Integer-DGEN max payout.
		 */
		private static function max_payout( $q_yes, $q_no ) : string {
			$max_micro = Gend_GS_BC_Math::bc_max( (string) $q_yes, (string) $q_no );
			// micro-shares -> whole-share DGEN (1 DGEN/share). floor: house owes only whole DGEN per whole share.
			return Gend_GS_BC_Math::bc_floor( bcdiv( $max_micro, '1000000', Gend_GS_BC_Math::SCALE ) );
		}

		/**
		 * The set of "operator" user ids for a group: admins ∪ mods ∪ creator_id. Guarded.
		 * Mirrors class-collab-match.php:130-152 (objects carry ->user_id).
		 *
		 * @param int $gid Group id.
		 * @return int[] Deduped operator user ids.
		 */
		private static function group_operator_ids( int $gid ) : array {
			$uids = array();
			if ( $gid > 0 && function_exists( 'groups_get_group_admins' ) ) {
				foreach ( (array) groups_get_group_admins( $gid ) as $m ) {
					if ( is_object( $m ) && isset( $m->user_id ) ) {
						$uids[] = (int) $m->user_id;
					}
				}
			}
			if ( $gid > 0 && function_exists( 'groups_get_group_mods' ) ) {
				foreach ( (array) groups_get_group_mods( $gid ) as $m ) {
					if ( is_object( $m ) && isset( $m->user_id ) ) {
						$uids[] = (int) $m->user_id;
					}
				}
			}
			if ( $gid > 0 && function_exists( 'groups_get_group' ) ) {
				$g = groups_get_group( $gid );
				if ( is_object( $g ) && ! empty( $g->creator_id ) ) {
					$uids[] = (int) $g->creator_id;
				}
			}
			return array_values( array_unique( array_filter( $uids ) ) );
		}

		/**
		 * Same-operator / sockpuppet signal (86-RESEARCH Q5 — the primary auto-void
		 * signal, zero new infra): the two matched groups share an admin/mod/creator.
		 * (IP/billing signals are advisory-only, written to the audit log, NOT an auto-void.)
		 *
		 * @param int $group_a Group A id.
		 * @param int $group_b Group B id.
		 * @return bool True when the two groups share at least one operator.
		 */
		private static function groups_share_operator( int $group_a, int $group_b ) : bool {
			$a = self::group_operator_ids( $group_a );
			$b = self::group_operator_ids( $group_b );
			return (bool) array_intersect( $a, $b );
		}

		/**
		 * Guarded audit-log write. Mirrors resolver.php:198-200 —
		 * write($event_type, $subject_user_id, $details, $actor_user_id, $chain_tx_id).
		 *
		 * @param string $event   Event slug (e.g. collab.market.subsidy_funded).
		 * @param array  $details Structured details (JSON-encoded by the logger).
		 * @param string|null $chain_tx_id Optional anchored tx id.
		 * @return void
		 */
		private static function audit( string $event, array $details, $chain_tx_id = null ) : void {
			if ( class_exists( 'Gend_CP_Audit_Log' ) && method_exists( 'Gend_CP_Audit_Log', 'write' ) ) {
				Gend_CP_Audit_Log::write( $event, 0, $details, 0, $chain_tx_id );
			}
		}

		/**
		 * Guarded chain anchor — chain.* system tx (gas + signature exempt). Mirrors
		 * class-collab-contract.php:972-988. Returns the tx id (string) or '' if the
		 * validator is unavailable.
		 *
		 * @param string $type    One of the TX_* consts.
		 * @param int    $from_uid Originating user id (0 for a system-only anchor).
		 * @param array  $payload Structured payload.
		 * @return string Chain tx id, or '' when unanchored.
		 */
		private static function anchor( string $type, int $from_uid, array $payload ) : string {
			if ( class_exists( 'Gend_Chain_Validator' ) && method_exists( 'Gend_Chain_Validator', 'submit_tx' ) ) {
				// The validator rejects unsigned txs ('gend_chain_no_user') even for
				// 'chain.'-prefixed system types. System anchors (created/locked/void/
				// residual) pass from_uid=0 — sign those with the hub treasury user.
				$signer = $from_uid > 0 ? $from_uid : self::treasury_uid();
				$id = Gend_Chain_Validator::submit_tx( array(
					'type'         => $type,
					'from_app_id'  => '',
					'from_user_id' => $signer,
					'payload'      => $payload,
					'ts'           => time(),
				) );
				// submit_tx() can return a WP_Error (validator key unset / chain
				// unavailable). A (string) cast on a WP_Error fatals — treat any
				// non-string/error as "unanchored" ('') so a chain hiccup never
				// fatals a money event (trade/bet/payout/settle).
				if ( is_string( $id ) ) {
					return $id;
				}
				return is_wp_error( $id ) ? '' : (string) $id;
			}
			return '';
		}

		/**
		 * Append a row to gs_collab_market_events (the auditable lifecycle log).
		 *
		 * @param int    $market_id Market id.
		 * @param string $event     One of created/subsidy_funded/trade/locked/resolved/paid/void.
		 * @param array  $detail    Structured detail (JSON-encoded into the LONGTEXT column).
		 * @param string $chain_tx_id Optional anchored tx id.
		 * @return void
		 */
		private static function market_event( int $market_id, string $event, array $detail = array(), string $chain_tx_id = '' ) : void {
			global $wpdb;
			if ( ! class_exists( 'Gend_GS_Collab_Schema' ) || ! method_exists( 'Gend_GS_Collab_Schema', 'market_events_table' ) ) {
				return;
			}
			$wpdb->insert(
				Gend_GS_Collab_Schema::market_events_table(),
				array(
					'market_id'   => $market_id,
					'event'       => $event,
					'detail'      => wp_json_encode( $detail ),
					'chain_tx_id' => '' !== $chain_tx_id ? substr( $chain_tx_id, 0, 64 ) : null,
					'created_at'  => time(),
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
		}

		/**
		 * Load a market row by id, or null.
		 *
		 * @param int $market_id Market id.
		 * @return object|null
		 */
		private static function get_market( int $market_id ) {
			global $wpdb;
			if ( $market_id <= 0 || ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return null;
			}
			$t = Gend_GS_Collab_Schema::markets_table();
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $market_id ) );
		}

		/**
		 * Load the market row for a match id, or null.
		 *
		 * @param int $match_id Match id.
		 * @return object|null
		 */
		private static function get_market_by_match( int $match_id ) {
			global $wpdb;
			if ( $match_id <= 0 || ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return null;
			}
			$t = Gend_GS_Collab_Schema::markets_table();
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE match_id = %d", $match_id ) );
		}

		/**
		 * Load a match row by id, or null.
		 *
		 * @param int $match_id Match id.
		 * @return object|null
		 */
		private static function get_match( int $match_id ) {
			global $wpdb;
			if ( $match_id <= 0 || ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return null;
			}
			$t = Gend_GS_Collab_Schema::matches_table();
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $match_id ) );
		}

		/**
		 * Resolve the contract task's due_date -> resolve_by unix ts. Guarded PSOO read;
		 * falls back to now + gs_collab_market_default_ttl (or DEFAULT_TTL).
		 *
		 * @param int $contract_task_id Contract task id.
		 * @return int resolve_by unix timestamp.
		 */
		private static function resolve_by_for_task( int $contract_task_id ) : int {
			$due = 0;
			if ( $contract_task_id > 0 && class_exists( 'PSOO_PM_Tasks' ) && method_exists( 'PSOO_PM_Tasks', 'get' ) ) {
				$task = PSOO_PM_Tasks::get( $contract_task_id );
				if ( is_object( $task ) && ! empty( $task->due_date ) ) {
					$ts = is_numeric( $task->due_date ) ? (int) $task->due_date : (int) strtotime( (string) $task->due_date );
					if ( $ts > 0 ) {
						$due = $ts;
					}
				} elseif ( is_array( $task ) && ! empty( $task['due_date'] ) ) {
					$ts = is_numeric( $task['due_date'] ) ? (int) $task['due_date'] : (int) strtotime( (string) $task['due_date'] );
					if ( $ts > 0 ) {
						$due = $ts;
					}
				}
			}
			if ( $due > 0 ) {
				return $due;
			}
			$ttl = (int) get_option( 'gs_collab_market_default_ttl', self::DEFAULT_TTL );
			if ( $ttl <= 0 ) {
				$ttl = self::DEFAULT_TTL;
			}
			return time() + $ttl;
		}

		/* -----------------------------------------------------------------
		 * create_market (MARKET-06 collab-only) + subsidy pre-fund + FSM
		 * ----------------------------------------------------------------- */

		/**
		 * THE ONLY market-creation entrypoint (MARKET-06 — structurally collab-only:
		 * NO $subject/$type param). Opens exactly one binary succeed/fail market for a
		 * CONTRACTED collaboration match, after pre-funding its full b·ln2 subsidy.
		 *
		 * Ordering (Pitfall "subsidy funded lazily"): the row is INSERTED non-tradeable
		 * (state='locked', subsidy_funded=0, escrow_dgen=0); the treasury subsidy is then
		 * really debited via fund_subsidy(); the market flips to 'open' ONLY on success. A
		 * market row is NEVER 'open' with subsidy_funded=0.
		 *
		 * Idempotency: UNIQUE(match_id) + INSERT IGNORE — a hook+sweep double-fire collapses
		 * to one market; a second call returns the existing row.
		 *
		 * Same-operator auto-void (STAKE-03): if the two matched groups share an admin/mod/
		 * creator, insert the row state='void' (NO subsidy, NO open) and return it.
		 *
		 * @param int $match_id A gs_collab_matches id (status must be 'contracted').
		 * @return object|WP_Error The market row, or WP_Error on a non-collab/non-contracted id.
		 */
		public static function create_market( int $match_id ) {
			global $wpdb;

			if ( ! self::is_hub() ) {
				return new WP_Error( 'gs_market_not_hub', 'Markets are hub-only.', array( 'status' => 400 ) );
			}
			if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return new WP_Error( 'gs_market_no_schema', 'Collab schema unavailable.', array( 'status' => 500 ) );
			}

			$match_id = (int) $match_id;

			// Idempotency: if a market already exists for this match, return it.
			$existing = self::get_market_by_match( $match_id );
			if ( is_object( $existing ) ) {
				return $existing;
			}

			// MARKET-06 HARD GATE: a market is creatable ONLY from a CONTRACTED collab match
			// with a contract task. A bogus / non-collab / non-contracted id can NEVER make one.
			$match = self::get_match( $match_id );
			if ( ! is_object( $match )
				|| 'contracted' !== (string) $match->status
				|| empty( $match->contract_task_id ) ) {
				return new WP_Error(
					'gs_market_not_contracted',
					'A market can only be created for a contracted collaboration match.',
					array( 'status' => 400 )
				);
			}

			$group_a          = (int) $match->group_a;
			$group_b          = (int) $match->group_b;
			$contract_task_id = (int) $match->contract_task_id;
			$now              = time();
			$markets          = Gend_GS_Collab_Schema::markets_table();

			// SAME-OPERATOR AUTO-VOID (STAKE-03): sockpuppet self-match — never open, never fund.
			if ( self::groups_share_operator( $group_a, $group_b ) ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$markets}
						(match_id, contract_task_id, q_yes, q_no, lmsr_b, subsidy_dgen, escrow_dgen, rake_bps, state, subsidy_funded, resolve_by, version, created_at)
					 VALUES (%d, %d, 0, 0, %d, 0, 0, %d, 'void', 0, %d, 0, %d)",
					$match_id,
					$contract_task_id,
					(int) self::market_b(),
					self::rake_bps(),
					self::resolve_by_for_task( $contract_task_id ),
					$now
				) );
				$void = self::get_market_by_match( $match_id );
				if ( is_object( $void ) ) {
					$tx = self::anchor( self::TX_VOID, 0, array(
						'market_id' => (int) $void->id,
						'match_id'  => $match_id,
						'reason'    => 'same_operator',
					) );
					self::audit( 'collab.market.autovoid.same_operator', array(
						'market_id' => (int) $void->id,
						'match_id'  => $match_id,
						'group_a'   => $group_a,
						'group_b'   => $group_b,
					), $tx );
					self::market_event( (int) $void->id, 'void', array( 'reason' => 'same_operator' ), $tx );
				}
				return is_object( $void ) ? $void : new WP_Error( 'gs_market_autovoid', 'Same-operator market auto-voided.', array( 'status' => 409 ) );
			}

			// Compute b + subsidy = ceil(b·ln2 / share-scale) BEFORE insert so the row
			// carries them. b·ln2 is in RAW micro-DGEN·share units (b in micro-shares);
			// divide by the 1e6 share-scale so the subsidy is WHOLE DGEN, consistent with
			// payout / max_payout (which are /1e6 — see max_payout()/pay path). ceil UP so
			// escrow pre-funds >= the exact bounded-loss reserve (maker-favor).
			$b            = self::market_b();
			$subsidy_dgen = Gend_GS_BC_Math::bc_ceil(
				bcdiv( bcmul( $b, Gend_GS_BC_Math::LN2, Gend_GS_BC_Math::SCALE ), '1000000', Gend_GS_BC_Math::SCALE )
			);
			$resolve_by   = self::resolve_by_for_task( $contract_task_id );

			// INSERT the row NON-TRADEABLE (state='locked', subsidy_funded=0, escrow_dgen=0).
			// The market is flipped to 'open' ONLY after fund_subsidy() succeeds — a row is
			// NEVER 'open' while subsidy_funded=0 (Pitfall "subsidy funded lazily").
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$markets}
					(match_id, contract_task_id, q_yes, q_no, lmsr_b, subsidy_dgen, escrow_dgen, rake_bps, state, subsidy_funded, resolve_by, version, created_at)
				 VALUES (%d, %d, 0, 0, %d, %s, 0, %d, 'locked', 0, %d, 0, %d)",
				$match_id,
				$contract_task_id,
				(int) $b,
				$subsidy_dgen,
				self::rake_bps(),
				$resolve_by,
				$now
			) );

			// Re-read (INSERT IGNORE may have collapsed a concurrent double-fire to one row).
			$market = self::get_market_by_match( $match_id );
			if ( ! is_object( $market ) ) {
				return new WP_Error( 'gs_market_insert_failed', 'Could not create the market row.', array( 'status' => 500 ) );
			}
			// If a concurrent creator already opened it, return that.
			if ( 'open' === (string) $market->state && (int) $market->subsidy_funded === 1 ) {
				return $market;
			}

			$created_tx = self::anchor( self::TX_CREATED, 0, array(
				'market_id'    => (int) $market->id,
				'match_id'     => $match_id,
				'lmsr_b'       => (int) $b,
				'subsidy_dgen' => $subsidy_dgen,
			) );
			self::market_event( (int) $market->id, 'created', array(
				'match_id'     => $match_id,
				'lmsr_b'       => (int) $b,
				'subsidy_dgen' => $subsidy_dgen,
			), $created_tx );

			// Pre-fund the subsidy (real, balance-checked, chain-anchored, idempotent).
			$funded = self::fund_subsidy( $market );
			if ( is_wp_error( $funded ) ) {
				// Leave the row NON-OPEN (still 'locked', subsidy_funded=0). Return the error;
				// the treasury could not fund the subsidy so the market cannot open.
				return $funded;
			}

			// Subsidy confirmed funded -> flip to 'open' (the ONLY path to a tradeable market).
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$markets} SET state='open' WHERE id=%d AND state='locked' AND subsidy_funded=1",
				(int) $market->id
			) );

			return self::get_market( (int) $market->id );
		}

		/**
		 * FUND_SUBSIDY (MARKET-03) — the ONE real DGEN move in Phase 86. Debits the full
		 * b·ln2 subsidy from the treasury (gend_cp_hub_fee_user_id) into the market's escrow,
		 * balance-checked (an underfunded treasury aborts the open), chain-anchored
		 * (chain.market.subsidy), and idempotent via the subsidy_funded flag (set BEFORE the
		 * move — mirror class-collab-contract.php reverse_escrow 928-932 / class-task-contract
		 * escrow balance-check).
		 *
		 * @param object $market_row The freshly-inserted market row (state='locked').
		 * @return true|WP_Error true when funded (or already funded — idempotent no-op).
		 */
		public static function fund_subsidy( $market_row ) {
			global $wpdb;

			if ( ! is_object( $market_row ) || (int) $market_row->id <= 0 ) {
				return new WP_Error( 'gs_market_bad_row', 'Invalid market row.', array( 'status' => 500 ) );
			}
			if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return new WP_Error( 'gs_market_no_schema', 'Collab schema unavailable.', array( 'status' => 500 ) );
			}

			$market_id = (int) $market_row->id;
			$markets   = Gend_GS_Collab_Schema::markets_table();

			// Idempotency: already funded -> clean no-op success.
			if ( (int) $market_row->subsidy_funded === 1 ) {
				return true;
			}

			// myCRED must be present to move DGEN.
			if ( ! function_exists( 'mycred_add' ) || ! function_exists( 'mycred_get_users_balance' ) ) {
				return new WP_Error( 'gs_market_no_mycred', 'myCRED unavailable — subsidy cannot be funded.', array( 'status' => 500 ) );
			}

			$treasury     = self::treasury_uid();
			$subsidy_dgen = (string) $market_row->subsidy_dgen;
			if ( bccomp( $subsidy_dgen, '0', 0 ) <= 0 ) {
				// A zero-subsidy market (b so small ceil(b·ln2)=0) is degenerate; treat as funded.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$markets} SET subsidy_funded=1, escrow_dgen=0 WHERE id=%d AND subsidy_funded=0",
					$market_id
				) );
				return true;
			}

			// BALANCE CHECK: an underfunded treasury cannot open the market (MARKET-03).
			// The (float) is confined to the myCRED balance API boundary; number_format
			// renders it to a plain fixed-point string (no scientific notation) so bccomp
			// stays correct for large balances. The escrow/subsidy math itself is float-free.
			$balance_str = number_format( (float) mycred_get_users_balance( $treasury, 'transact' ), 0, '.', '' );
			if ( bccomp( $balance_str, $subsidy_dgen, 0 ) < 0 ) {
				return new WP_Error(
					'gs_market_subsidy_unfunded',
					'Treasury cannot fund the market subsidy.',
					array( 'status' => 402, 'treasury' => $treasury, 'needed' => $subsidy_dgen )
				);
			}

			// IDEMPOTENCY GUARD: set subsidy_funded=1 + escrow_dgen=subsidy FIRST, atomically,
			// gated on subsidy_funded=0 — so a retried fund can NEVER double-debit the treasury.
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$markets} SET subsidy_funded=1, escrow_dgen=%s WHERE id=%d AND subsidy_funded=0",
				$subsidy_dgen,
				$market_id
			) );
			if ( 1 !== (int) $claimed ) {
				// Another caller already claimed the funding — treat as a no-op success.
				return true;
			}

			// The REAL treasury debit (guarded). escrow reservation is held on escrow_dgen.
			mycred_add(
				'collab_market_subsidy',
				$treasury,
				-1 * (float) $subsidy_dgen,
				sprintf( 'Collaboration market subsidy escrowed — Market #%d', $market_id ),
				$market_id,
				array( 'market_id' => $market_id ),
				'transact'
			);

			// Chain-anchor AFTER the move (guarded).
			$tx = self::anchor( self::TX_SUBSIDY, $treasury, array(
				'market_id'    => $market_id,
				'match_id'     => (int) $market_row->match_id,
				'lmsr_b'       => (int) $market_row->lmsr_b,
				'subsidy_dgen' => $subsidy_dgen,
			) );

			self::audit( 'collab.market.subsidy_funded', array(
				'market_id'    => $market_id,
				'treasury'     => $treasury,
				'subsidy_dgen' => $subsidy_dgen,
			), $tx );
			self::market_event( $market_id, 'subsidy_funded', array(
				'treasury'     => $treasury,
				'subsidy_dgen' => $subsidy_dgen,
			), $tx );

			return true;
		}

		/**
		 * LOCK (MARKET-05) — idempotent CAS open->locked. A second lock is a no-op. On the
		 * winning transition, audit + anchor chain.market.locked. Trading is refused once
		 * state != 'open' (enforced by trade()'s FOR UPDATE ... WHERE state='open').
		 *
		 * @param int $market_id Market id.
		 * @return bool|WP_Error true if this call locked it, false if already locked (no-op).
		 */
		public static function lock( int $market_id ) {
			global $wpdb;

			$market_id = (int) $market_id;
			if ( $market_id <= 0 || ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return new WP_Error( 'gs_market_bad_id', 'Invalid market id.', array( 'status' => 400 ) );
			}

			$markets = Gend_GS_Collab_Schema::markets_table();
			$upd     = $wpdb->query( $wpdb->prepare(
				"UPDATE {$markets} SET state='locked' WHERE id=%d AND state='open'",
				$market_id
			) );

			if ( 1 === (int) $upd ) {
				$tx = self::anchor( self::TX_LOCKED, 0, array( 'market_id' => $market_id ) );
				self::audit( 'collab.market.locked', array( 'market_id' => $market_id ), $tx );
				self::market_event( $market_id, 'locked', array(), $tx );
				return true;
			}
			// Already locked / not open -> idempotent no-op.
			return false;
		}

		/* -----------------------------------------------------------------
		 * Lifecycle subscribers (Phase 86-04) — thin statics the entrypoint
		 * wires to the collab do_actions. on_contracted is the ONLY gated
		 * caller; on_outcome_recorded runs flag-independent (locking a dark
		 * market is harmless + keeps the FSM correct for when the flag flips).
		 * ----------------------------------------------------------------- */

		/**
		 * gend_gs_collab_contracted subscriber (MARKET-01). GATED on
		 * GS_COLLAB_MARKET_PUBLIC — while dark a new contract creates NO market
		 * and drains NO treasury subsidy. create_market is idempotent
		 * (UNIQUE(match_id)) so a hook + sweep double-fire collapses to one market.
		 *
		 * @param int $match_id Contracted match id.
		 * @param int $task_id  Contract task id (unused; create_market re-reads the match).
		 * @return void
		 */
		public static function on_contracted( $match_id, $task_id = 0 ) : void {
			if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) || ! GS_COLLAB_MARKET_PUBLIC ) {
				return; // dark: no market, no subsidy.
			}
			self::create_market( (int) $match_id ); // idempotent; return value ignored.
		}

		/**
		 * gend_gs_collab_outcome_recorded subscriber (MARKET-05). FLAG-INDEPENDENT:
		 * look up the market for this match and lock() it (idempotent CAS). No-ops if
		 * no market exists (dark = none created). Trading must halt once a terminal
		 * outcome is known so nobody trades on a decided result.
		 *
		 * @param int    $match_id Match id.
		 * @param string $outcome  'success' | 'fail' | 'void' (unused — any terminal outcome locks).
		 * @return void
		 */
		public static function on_outcome_recorded( $match_id, $outcome = '' ) : void {
			$market = self::get_market_by_match( (int) $match_id );
			if ( is_object( $market ) && isset( $market->id ) ) {
				self::lock( (int) $market->id ); // CAS open->locked; no-op if already locked/void.
			}
		}

		/**
		 * Public accessor: the OPEN market row for a match, or null. Used by the
		 * Phase-85 sweep deadline-lock (get_market_by_match is private). Returns null
		 * unless a market exists AND is in state='open'.
		 *
		 * @param int $match_id Match id.
		 * @return object|null
		 */
		public static function get_open_market_for_match( int $match_id ) {
			$market = self::get_market_by_match( (int) $match_id );
			if ( is_object( $market ) && isset( $market->state ) && 'open' === (string) $market->state ) {
				return $market;
			}
			return null;
		}

		/**
		 * READ-ONLY market-state snapshot for the dark REST surface (MARKET-04/05). Unlike
		 * quote(), this reads ANY state (open OR locked) so a locked market still surfaces
		 * its final implied probability. Neutral labels only — NO bet/odds/wager/payout.
		 *
		 * @param int $market_id Market id.
		 * @return array|WP_Error ['market_id','state','resolve_by','implied_probability'=>['yes','no']] or WP_Error.
		 */
		public static function state_snapshot( int $market_id ) {
			$m = self::get_market( (int) $market_id );
			if ( ! is_object( $m ) ) {
				return new WP_Error( 'gs_market_not_found', 'Market not found.', array( 'status' => 404 ) );
			}
			$price = Gend_GS_BC_Math::price( (string) $m->q_yes, (string) $m->q_no, self::market_b( $m ) );
			return array(
				'market_id'           => (int) $m->id,
				'state'               => (string) $m->state,
				'resolve_by'          => isset( $m->resolve_by ) ? (int) $m->resolve_by : 0,
				'implied_probability' => array(
					'yes' => $price['p_yes'],
					'no'  => $price['p_no'],
				),
			);
		}

		/* -----------------------------------------------------------------
		 * quote (MARKET-04) + trade (RESOLVE-05 concurrency + escrow-invariant
		 * hard gate + STAKE-03 insider) — the indivisible money core.
		 * ----------------------------------------------------------------- */

		/**
		 * Insider check (STAKE-03): admin/mod of EITHER matched group is barred from that
		 * market (they control whether the contract completes -> the fatal self-collusion
		 * vector). Two-arg role check, function_exists-guarded, on BOTH groups. Mirrors
		 * class-collab-rest.php:291-292 / 489-490 / 651-652.
		 *
		 * @param int $uid     Bettor user id.
		 * @param int $group_a Matched group A.
		 * @param int $group_b Matched group B.
		 * @return bool True when the user is an admin/mod of either matched group.
		 */
		private static function is_matched_insider( int $uid, int $group_a, int $group_b ) : bool {
			if ( $uid <= 0 ) {
				return false;
			}
			foreach ( array( $group_a, $group_b ) as $gid ) {
				if ( $gid <= 0 ) {
					continue;
				}
				if ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( $uid, $gid ) ) {
					return true;
				}
				if ( function_exists( 'groups_is_user_mod' ) && groups_is_user_mod( $uid, $gid ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * QUOTE (MARKET-04) — READ-ONLY live implied YES/NO odds + the DGEN cost of buying
		 * $delta_shares of $outcome. No lock (nothing is mutated). Refuses a non-open market.
		 *
		 * cost = ceil( cost(q') - cost(q) ) — maker-favor UP so escrow collects >= exact.
		 * implied = price(q') -> p_yes/p_no as implied-probability fixed-point strings.
		 *
		 * NOTE: a quote is advisory; the AUTHORITATIVE price is re-computed inside trade()
		 * off the LOCKED q (a quote can go stale between read and trade — trade() never
		 * trusts the caller's q).
		 *
		 * @param int    $market_id    Market id.
		 * @param string $outcome      'yes' or 'no'.
		 * @param string $delta_shares Micro-shares to buy (positive integer string).
		 * @return array|WP_Error ['cost_dgen','p_yes','p_no'] or WP_Error.
		 */
		public static function quote( int $market_id, string $outcome, string $delta_shares ) {
			$outcome = ( 'no' === strtolower( (string) $outcome ) ) ? 'no' : 'yes';

			$m = self::get_market( (int) $market_id );
			if ( ! is_object( $m ) ) {
				return new WP_Error( 'gs_market_not_found', 'Market not found.', array( 'status' => 404 ) );
			}
			if ( 'open' !== (string) $m->state ) {
				return new WP_Error( 'gs_market_not_open', 'Market is not open for quoting.', array( 'status' => 409 ) );
			}

			$b     = self::market_b( $m );
			$q_yes = (string) $m->q_yes;
			$q_no  = (string) $m->q_no;

			$q_yes_new = ( 'yes' === $outcome ) ? bcadd( $q_yes, (string) $delta_shares, 0 ) : $q_yes;
			$q_no_new  = ( 'no' === $outcome ) ? bcadd( $q_no, (string) $delta_shares, 0 ) : $q_no;

			// C() is in RAW micro-DGEN·share units; divide by the 1e6 share-scale so the
			// quoted cost is WHOLE DGEN, consistent with payout/max_payout. ceil UP.
			$cost = Gend_GS_BC_Math::bc_ceil(
				bcdiv(
					bcsub(
						Gend_GS_BC_Math::cost( $q_yes_new, $q_no_new, $b ),
						Gend_GS_BC_Math::cost( $q_yes, $q_no, $b ),
						Gend_GS_BC_Math::SCALE
					),
					'1000000',
					Gend_GS_BC_Math::SCALE
				)
			);

			$price = Gend_GS_BC_Math::price( $q_yes_new, $q_no_new, $b );

			return array(
				'cost_dgen' => $cost,
				'p_yes'     => $price['p_yes'],
				'p_no'      => $price['p_no'],
			);
		}

		/**
		 * TRADE — the guarded, transactional, invariant-enforced money core (RESOLVE-05 +
		 * STAKE-03). SIMULATED path (Phase 86 crown-UAT surface): NO real bettor DGEN moves.
		 *
		 * SINGLE SOURCE OF TRUTH (Phase 87-01): the locked body (START TRANSACTION + FOR
		 * UPDATE + re-quote + escrow-invariant HARD GATE + version guard + position upsert +
		 * COMMIT) lives in ONE private method — do_trade_locked() — shared by BOTH this
		 * trade() (money_leg = null -> simulated, behaviour byte-identical to Phase 86 so the
		 * 86 crown UAT still passes) AND place_bet() (money_leg = real closure). There is NO
		 * second copy of the invariant/lock/insider gate that could drift.
		 *
		 * Flow:
		 *   1. ENTRY GATE (pre-transaction): insider (admin/mod of either matched group) -> reject.
		 *   2. delta > 0 (buys only, as Phase 86).
		 *   3. do_trade_locked( ..., $delta_shares, null )  [SIMULATED].
		 *   4. AFTER commit (outside the lock): audit + shape-identical return.
		 *
		 * @param int    $market_id    Market id.
		 * @param int    $user_id      Bettor user id.
		 * @param string $outcome      'yes' or 'no'.
		 * @param string $delta_shares Micro-shares to buy (positive integer string).
		 * @return array|WP_Error ['cost_dgen','q_yes','q_no','escrow_dgen','p_yes','p_no'] or WP_Error.
		 */
		public static function trade( int $market_id, int $user_id, string $outcome, string $delta_shares ) {
			$market_id = (int) $market_id;
			$user_id   = (int) $user_id;
			$outcome   = ( 'no' === strtolower( (string) $outcome ) ) ? 'no' : 'yes';

			if ( bccomp( (string) $delta_shares, '0', 0 ) <= 0 ) {
				return new WP_Error( 'gs_market_bad_delta', 'Trade size must be positive.', array( 'status' => 400 ) );
			}
			if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return new WP_Error( 'gs_market_no_schema', 'Collab schema unavailable.', array( 'status' => 500 ) );
			}

			// --- 1. ENTRY GATE (before ANY transaction): matched-group insider (STAKE-03). ---
			$insider = self::insider_gate( $market_id, $user_id );
			if ( is_wp_error( $insider ) ) {
				return $insider;
			}

			// --- 2/3. Delegate to the shared locked core (SIMULATED: money_leg = null). ---
			$res = self::do_trade_locked( $market_id, $user_id, $outcome, (string) $delta_shares, null );
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			// --- 4. AFTER commit (outside the lock): audit + shape-identical Phase-86 return. ---
			self::audit( 'collab.market.trade', array(
				'market_id' => $market_id,
				'user_id'   => $user_id,
				'outcome'   => $outcome,
				'delta'     => (string) $delta_shares,
				'cost_dgen' => $res['cost'],
				'simulated' => true,
			) );

			$b     = self::market_b( $res['market_row'] );
			$price = Gend_GS_BC_Math::price( $res['q_yes_new'], $res['q_no_new'], $b );

			return array(
				'cost_dgen'   => $res['cost'],
				'q_yes'       => $res['q_yes_new'],
				'q_no'        => $res['q_no_new'],
				'escrow_dgen' => $res['escrow_new'],
				'p_yes'       => $price['p_yes'],
				'p_no'        => $price['p_no'],
			);
		}

		/**
		 * Pre-transaction matched-group insider gate (STAKE-03), shared by trade() and
		 * place_bet() so both use IDENTICAL insider machinery (single source of truth).
		 * Returns null when clear, or a WP_Error to reject.
		 *
		 * @param int $market_id Market id.
		 * @param int $user_id   Bettor user id.
		 * @return null|WP_Error
		 */
		private static function insider_gate( int $market_id, int $user_id ) {
			$pre = self::get_market( $market_id );
			if ( ! is_object( $pre ) ) {
				return new WP_Error( 'gs_market_not_found', 'Market not found.', array( 'status' => 404 ) );
			}
			$match = self::get_match( (int) $pre->match_id );
			if ( is_object( $match ) ) {
				if ( self::is_matched_insider( $user_id, (int) $match->group_a, (int) $match->group_b ) ) {
					return new WP_Error(
						'gs_market_insider',
						'Admins and moderators of a matched group cannot stake on that collaboration market.',
						array( 'status' => 403 )
					);
				}
			}
			return null;
		}

		/**
		 * THE SINGLE SOURCE OF TRUTH for the money-safety invariant (Phase 87-01).
		 *
		 * Contains VERBATIM the Phase-86 locked body — START TRANSACTION + SELECT ... FOR
		 * UPDATE + re-quote off the locked q + escrow-invariant HARD GATE + optimistic
		 * version guard + position upsert + COMMIT — as ONE method called by BOTH the
		 * SIMULATED trade() (money_leg = null) and the REAL place_bet() (money_leg = closure).
		 * Never let two copies of the invariant/lock drift. Opens EXACTLY ONE transaction;
		 * callers must NOT wrap it in another (MySQL does not nest — Pitfall 1).
		 *
		 * Seams vs Phase 86:
		 *   - The pre-transaction insider gate stays in the public entry methods (insider_gate).
		 *   - $signed_delta is a SIGNED integer string so a SELL (negative delta) is supported.
		 *     Defence in depth: q_*_new >= 0 is asserted here (ROLLBACK gs_market_bad_delta).
		 *   - BUY (positive delta): $cost = bc_ceil( C(q') - C(q) )  (maker-favor UP).
		 *     SELL (negative delta): $refund = bc_floor( -( C(q') - C(q) ) )  (maker-favor DOWN
		 *     so escrow shrinks by <= exact, never more).
		 *   - Escrow: escrow_new = escrow + cost (buy) OR escrow - refund (sell). The SAME
		 *     `escrow_new < max_payout(q_new) -> ROLLBACK` gate runs on BOTH paths (belt+braces).
		 *   - $money_leg (if not null) is invoked INSIDE the txn AFTER the version-bumped UPDATE
		 *     and BEFORE the position upsert, receiving [is_buy, cost, refund, q_yes_new,
		 *     q_no_new, escrow_new, market_row]. If it returns a WP_Error (insufficient DGEN,
		 *     cap, idem-replay, debit-fail) do_trade_locked ROLLBACKs and returns it unchanged
		 *     (clean abort — no partial state, no debit). null money_leg -> SIMULATED (skipped).
		 *   - Position upsert: BUY shares += |delta|, cost_dgen += cost. SELL shares -= |delta|,
		 *     cost_dgen -= (avg_cost * |delta|) (bcmath floor off the row's cost_dgen/shares),
		 *     realized_dgen += refund. shares/cost_dgen never go negative (clamped).
		 *
		 * @param int      $market_id    Market id.
		 * @param int      $user_id      Bettor user id.
		 * @param string   $outcome      'yes' or 'no'.
		 * @param string   $signed_delta Signed micro-shares (negative = sell).
		 * @param callable|null $money_leg null = simulated; closure(array):null|WP_Error = real.
		 * @return array|WP_Error Locked-core result array (see keys below) or WP_Error.
		 */
		private static function do_trade_locked( int $market_id, int $user_id, string $outcome, string $signed_delta, $money_leg = null ) {
			global $wpdb;

			$markets   = Gend_GS_Collab_Schema::markets_table();
			$positions = Gend_GS_Collab_Schema::positions_table();
			$is_buy    = ( bccomp( $signed_delta, '0', 0 ) >= 0 );
			$abs_delta = ( '-' === substr( $signed_delta, 0, 1 ) ) ? substr( $signed_delta, 1 ) : $signed_delta;

			// --- 2. START TRANSACTION + FOR UPDATE on the market row. ---
			$wpdb->query( 'START TRANSACTION' );
			try {
				$m = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$markets} WHERE id = %d AND state = 'open' FOR UPDATE",
					$market_id
				) );
				if ( ! is_object( $m ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'gs_market_not_open', 'Market is not open for trading.', array( 'status' => 409 ) );
				}

				$b = self::market_b( $m );

				// --- 3. RE-QUOTE off the LOCKED q (never the stale q shown to the caller). ---
				$q_yes = (string) $m->q_yes;
				$q_no  = (string) $m->q_no;
				$q_yes_new = ( 'yes' === $outcome ) ? bcadd( $q_yes, $signed_delta, 0 ) : $q_yes;
				$q_no_new  = ( 'no' === $outcome ) ? bcadd( $q_no, $signed_delta, 0 ) : $q_no;

				// Defence in depth: a sell must never drive a q vector negative.
				if ( bccomp( $q_yes_new, '0', 0 ) < 0 || bccomp( $q_no_new, '0', 0 ) < 0 ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'gs_market_bad_delta', 'Trade would drive a share vector negative.', array( 'status' => 400 ) );
				}

				// delta_cost = C(q') - C(q). BUY: cost = ceil(delta_cost) (UP). SELL:
				// refund = floor( -delta_cost ) (DOWN — escrow shrinks by <= exact, never more).
				// C() is in RAW micro-DGEN·share units; divide by the 1e6 share-scale so
				// cost/refund land in WHOLE DGEN, consistent with payout/max_payout (/1e6).
				// BUY ceil UP / SELL-refund floor DOWN (below) keep escrow maker-favor.
				$delta_cost = bcdiv(
					bcsub(
						Gend_GS_BC_Math::cost( $q_yes_new, $q_no_new, $b ),
						Gend_GS_BC_Math::cost( $q_yes, $q_no, $b ),
						Gend_GS_BC_Math::SCALE
					),
					'1000000',
					Gend_GS_BC_Math::SCALE
				);
				$cost   = '0';
				$refund = '0';
				if ( $is_buy ) {
					$cost       = Gend_GS_BC_Math::bc_ceil( $delta_cost );
					$escrow_new = bcadd( (string) $m->escrow_dgen, $cost, 0 );
				} else {
					// -delta_cost is the positive refund magnitude; floor DOWN (maker-favor).
					$refund     = Gend_GS_BC_Math::bc_floor( bcmul( $delta_cost, '-1', Gend_GS_BC_Math::SCALE ) );
					if ( bccomp( $refund, '0', 0 ) < 0 ) {
						$refund = '0';
					}
					$escrow_new = bcsub( (string) $m->escrow_dgen, $refund, 0 );
					if ( bccomp( $escrow_new, '0', 0 ) < 0 ) {
						$wpdb->query( 'ROLLBACK' );
						return new WP_Error( 'gs_market_invariant', 'Sell would drive escrow negative. Rejected.', array( 'status' => 409 ) );
					}
				}

				// --- 4. ESCROW-INVARIANT HARD GATE (RESOLVE-05) — runs on BOTH paths. ---
				// escrow_new >= GROSS max_payout(q_new) (rake ignored -> worst case). By LMSR
				// construction this always holds; firing means a bug -> REJECT, NEVER mint.
				$max_payout = self::max_payout( $q_yes_new, $q_no_new );
				if ( bccomp( $escrow_new, $max_payout, 0 ) < 0 ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error(
						'gs_market_invariant',
						'Escrow-invariant violation: escrow would be less than the maximum possible payout. Trade rejected.',
						array( 'status' => 409, 'escrow' => $escrow_new, 'max_payout' => $max_payout )
					);
				}

				// --- 5. Optimistic version guard (secondary to FOR UPDATE). ---
				$upd = $wpdb->query( $wpdb->prepare(
					"UPDATE {$markets} SET q_yes=%d, q_no=%d, escrow_dgen=%s, version=version+1
					 WHERE id=%d AND version=%d AND state='open'",
					$q_yes_new,
					$q_no_new,
					$escrow_new,
					$market_id,
					(int) $m->version
				) );
				if ( 1 !== (int) $upd ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'gs_market_conflict', 'Concurrent update conflict; retry.', array( 'status' => 409 ) );
				}

				// --- 5b. REAL money leg (place_bet) — inside the lock, after the version bump,
				// before the position upsert. A returned WP_Error aborts cleanly (no debit,
				// no partial state). null (trade) -> SIMULATED, skipped. ---
				if ( null !== $money_leg ) {
					$leg = call_user_func( $money_leg, array(
						'is_buy'     => $is_buy,
						'cost'       => $cost,
						'refund'     => $refund,
						'q_yes_new'  => $q_yes_new,
						'q_no_new'   => $q_no_new,
						'escrow_new' => $escrow_new,
						'market_row' => $m,
					) );
					if ( is_wp_error( $leg ) ) {
						$wpdb->query( 'ROLLBACK' );
						return $leg;
					}
				}

				// --- 6. Position upsert (BUY: add; SELL: reduce + realize). ---
				$now = time();
				if ( $is_buy ) {
					$wpdb->query( $wpdb->prepare(
						"INSERT INTO {$positions} (market_id, user_id, outcome, shares, cost_dgen, realized_dgen, created_at, updated_at)
						 VALUES (%d, %d, %s, %d, %s, 0, %d, %d)
						 ON DUPLICATE KEY UPDATE shares = shares + VALUES(shares),
						                         cost_dgen = cost_dgen + VALUES(cost_dgen),
						                         updated_at = VALUES(updated_at)",
						$market_id,
						$user_id,
						$outcome,
						$abs_delta,
						$cost,
						$now,
						$now
					) );
				} else {
					// SELL: reduce shares by |delta|, drop cost basis by avg_cost*|delta|,
					// accrue realized_dgen += refund. Read the CURRENT row inside the lock.
					$pos = $wpdb->get_row( $wpdb->prepare(
						"SELECT shares, cost_dgen, realized_dgen FROM {$positions}
						 WHERE market_id=%d AND user_id=%d AND outcome=%s",
						$market_id,
						$user_id,
						$outcome
					) );
					$held      = is_object( $pos ) ? (string) $pos->shares : '0';
					$basis     = is_object( $pos ) ? (string) $pos->cost_dgen : '0';
					// avg-cost basis of the sold shares = floor( cost_dgen * |delta| / shares ).
					$cost_out  = ( bccomp( $held, '0', 0 ) > 0 )
						? Gend_GS_BC_Math::bc_floor( bcdiv( bcmul( $basis, $abs_delta, 0 ), $held, Gend_GS_BC_Math::SCALE ) )
						: '0';
					$new_shares = bcsub( $held, $abs_delta, 0 );
					if ( bccomp( $new_shares, '0', 0 ) < 0 ) {
						$new_shares = '0';
					}
					$new_basis  = bcsub( $basis, $cost_out, 0 );
					if ( bccomp( $new_basis, '0', 0 ) < 0 ) {
						$new_basis = '0';
					}
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$positions}
						 SET shares=%s, cost_dgen=%s, realized_dgen=realized_dgen+%s, updated_at=%d
						 WHERE market_id=%d AND user_id=%d AND outcome=%s",
						$new_shares,
						$new_basis,
						$refund,
						$now,
						$market_id,
						$user_id,
						$outcome
					) );
				}

				self::market_event( $market_id, 'trade', array(
					'user_id'   => $user_id,
					'outcome'   => $outcome,
					'delta'     => $signed_delta,
					'cost'      => $is_buy ? $cost : $refund,
					'is_buy'    => $is_buy,
					'q_yes'     => $q_yes_new,
					'q_no'      => $q_no_new,
					'escrow'    => $escrow_new,
					'simulated' => ( null === $money_leg ),
				) );

				// --- 7. COMMIT. ---
				$wpdb->query( 'COMMIT' );
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gs_market_trade_failed', $e->getMessage(), array( 'status' => 500 ) );
			}

			return array(
				'is_buy'     => $is_buy,
				'cost'       => $cost,
				'refund'     => $refund,
				'q_yes_new'  => $q_yes_new,
				'q_no_new'   => $q_no_new,
				'escrow_new' => $escrow_new,
				'market_row' => $m,
			);
		}

		/* -----------------------------------------------------------------
		 * place_bet (STAKE-01) — the REAL member DGEN debit/credit path.
		 * Shares do_trade_locked()'s invariant/lock/version machinery; adds
		 * the money leg (mycred_subtract buy / mycred_add sell), per-bet
		 * idempotency (gs_collab_bet_idem INSERT IGNORE), the position cap,
		 * and the chain.market.bet anchor after commit.
		 * ----------------------------------------------------------------- */

		/**
		 * PLACE_BET (STAKE-01) — a REAL member stake. Buy debits the member's DGEN via the
		 * MyCred↔chain bridge (mycred_subtract on 'transact'), adds the LMSR cost to escrow,
		 * upserts the position; sell refunds C(q)-C(q') (maker-favor DOWN) to the SAME member,
		 * reduces the position, shrinks escrow safely. ALL inside the Phase-86 FOR UPDATE lock
		 * (via do_trade_locked), after the escrow-invariant gate. Idempotent per idem_key
		 * (a cold-start retry NEVER double-debits). chain.market.bet anchored AFTER commit.
		 *
		 * STAKE-04 (non-transferable): the AMM is the SOLE counterparty. The sell credit goes
		 * to $user_id (the authenticated bettor) ONLY — there is NO recipient param anywhere.
		 * STAKE-05 (rake disclosure): the response carries rake_bps + a plain-language string;
		 * Phase 87 does NOT skim (Phase 88 does), so no invariant change.
		 *
		 * @param int    $market_id    Market id.
		 * @param int    $user_id      Authenticated bettor user id (the ONLY money endpoint).
		 * @param string $outcome      'yes' or 'no'.
		 * @param string $direction    'buy' | 'sell'.
		 * @param string $delta_shares Positive micro-shares to buy/sell (integer string).
		 * @param string $idem_key     Per-bet idempotency key (<=64 chars; derived if empty).
		 * @return array|WP_Error Bet result (cost/refund, q, escrow, prices, position, rake) or WP_Error.
		 */
		public static function place_bet( int $market_id, int $user_id, string $outcome, string $direction, string $delta_shares, string $idem_key = '' ) {
			global $wpdb;

			$market_id = (int) $market_id;
			$user_id   = (int) $user_id;
			$outcome   = ( 'no' === strtolower( (string) $outcome ) ) ? 'no' : 'yes';
			$direction = ( 'sell' === strtolower( (string) $direction ) ) ? 'sell' : 'buy';
			$is_buy    = ( 'buy' === $direction );

			// --- 1. Validate. ---
			if ( bccomp( (string) $delta_shares, '0', 0 ) <= 0 ) {
				return new WP_Error( 'gs_market_bad_delta', 'Stake size must be positive.', array( 'status' => 400 ) );
			}
			if ( ! class_exists( 'Gend_GS_Collab_Schema' ) || ! method_exists( 'Gend_GS_Collab_Schema', 'bet_idem_table' ) ) {
				return new WP_Error( 'gs_market_no_schema', 'Collab schema unavailable.', array( 'status' => 500 ) );
			}
			if ( ! function_exists( 'mycred_subtract' ) || ! function_exists( 'mycred_add' ) || ! function_exists( 'mycred_get_users_balance' ) ) {
				return new WP_Error( 'gs_market_no_mycred', 'myCRED unavailable — stake cannot be settled.', array( 'status' => 500 ) );
			}

			// Prefer a client-supplied key (so a retry reuses it); else derive one.
			$idem_key = trim( (string) $idem_key );
			if ( '' === $idem_key ) {
				$idem_key = hash( 'sha256', $market_id . '|' . $user_id . '|' . $outcome . '|' . $direction . '|' . $delta_shares . '|' . microtime( true ) );
			}
			$idem_key = substr( $idem_key, 0, 64 );

			// --- 2. Pre-transaction insider gate (SAME machinery as trade() — STAKE-03). ---
			$insider = self::insider_gate( $market_id, $user_id );
			if ( is_wp_error( $insider ) ) {
				return $insider;
			}

			$idem_table = Gend_GS_Collab_Schema::bet_idem_table();
			$positions  = Gend_GS_Collab_Schema::positions_table();

			// Replay carrier — the money-leg stashes a prior result here so place_bet can
			// return it verbatim (a replay is NOT an error).
			$replay = array( 'hit' => false, 'result' => null );

			// --- 3. Signed delta (sell = negative). ---
			$signed_delta = $is_buy ? (string) $delta_shares : ( '-' . (string) $delta_shares );

			// --- 4. Real money leg, invoked INSIDE do_trade_locked's transaction. ---
			$money_leg = function ( $ctx ) use ( $wpdb, $idem_table, $positions, $market_id, $user_id, $outcome, $direction, $idem_key, &$replay ) {
				$is_buy = (bool) $ctx['is_buy'];
				$cost   = (string) $ctx['cost'];
				$refund = (string) $ctx['refund'];

				// a. IDEMPOTENCY FIRST: INSERT IGNORE the key. rows_affected===0 -> replay.
				$now = time();
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$idem_table} (idem_key, market_id, user_id, created_at)
					 VALUES (%s, %d, %d, %d)",
					$idem_key,
					$market_id,
					$user_id,
					$now
				) );
				if ( 0 === (int) $wpdb->rows_affected ) {
					// A prior bet with this key already committed — return the stored result.
					$prior = $wpdb->get_var( $wpdb->prepare(
						"SELECT result_json FROM {$idem_table} WHERE idem_key = %s",
						$idem_key
					) );
					$replay['hit']    = true;
					$replay['result'] = is_string( $prior ) ? json_decode( $prior, true ) : null;
					return new WP_Error( 'gs_market_idem_replay', 'Idempotent replay.', array( 'status' => 200 ) );
				}

				if ( $is_buy ) {
					// b. POSITION CAP (TOCTOU-safe — inside the lock). SUM(cost_dgen) over BOTH
					// outcomes for this member on this market + this buy's cost <= cap.
					$existing = (string) $wpdb->get_var( $wpdb->prepare(
						"SELECT COALESCE(SUM(cost_dgen),0) FROM {$positions} WHERE market_id=%d AND user_id=%d",
						$market_id,
						$user_id
					) );
					$cap = Gend_GS_Collab_Schema::position_cap_dgen();
					if ( bccomp( bcadd( $existing, $cost, 0 ), $cap, 0 ) > 0 ) {
						return new WP_Error(
							'gs_market_position_cap',
							'This stake would exceed your per-market limit.',
							array( 'status' => 422, 'cap' => $cap )
						);
					}

					// c. BALANCE precheck (insufficient DGEN -> clean abort, NO debit).
					$balance_str = number_format( (float) mycred_get_users_balance( $user_id, 'transact' ), 0, '.', '' );
					if ( bccomp( $balance_str, $cost, 0 ) < 0 ) {
						return new WP_Error(
							'gs_market_insufficient_dgen',
							'Insufficient DGEN balance to place this stake.',
							array( 'status' => 402, 'needed' => $cost )
						);
					}

					// d. MONEY MOVE — real debit. FLOAT DISCIPLINE: $cost is whole-integer DGEN
					// (bc_ceil to 0dp); assert no fractional part, then (float) ONLY at the
					// myCRED boundary. All escrow/LMSR math stayed bcmath strings.
					if ( false !== strpos( $cost, '.' ) ) {
						return new WP_Error( 'gs_market_debit_failed', 'Non-integer stake cost.', array( 'status' => 500 ) );
					}
					$r = mycred_subtract(
						'gend_gs_market_bet',
						$user_id,
						(float) $cost,
						sprintf( 'Collaboration market stake — Market #%d', $market_id ),
						$market_id,
						array( 'idem' => $idem_key, 'outcome' => $outcome, 'dir' => 'buy' ),
						'transact'
					);
					if ( false === $r ) {
						return new WP_Error( 'gs_market_debit_failed', 'DGEN debit failed.', array( 'status' => 500 ) );
					}
				} else {
					// SELL: refund C(q)-C(q') to the SAME bettor (STAKE-04 — sole counterparty).
					if ( false !== strpos( $refund, '.' ) ) {
						return new WP_Error( 'gs_market_credit_failed', 'Non-integer refund.', array( 'status' => 500 ) );
					}
					if ( bccomp( $refund, '0', 0 ) > 0 ) {
						$r = mycred_add(
							'gend_gs_market_sell',
							$user_id,
							(float) $refund,
							sprintf( 'Collaboration market early exit — Market #%d', $market_id ),
							$market_id,
							array( 'idem' => $idem_key, 'outcome' => $outcome, 'dir' => 'sell' ),
							'transact'
						);
						if ( false === $r ) {
							return new WP_Error( 'gs_market_credit_failed', 'DGEN refund failed.', array( 'status' => 500 ) );
						}
					}
				}

				return null; // money leg OK — do_trade_locked proceeds to the position upsert + COMMIT.
			};

			// --- 5. Run the shared locked core with the real money leg. ---
			$res = self::do_trade_locked( $market_id, $user_id, $outcome, $signed_delta, $money_leg );

			// --- 6. Idempotent replay: return the exact prior result (NO second debit). ---
			if ( is_wp_error( $res ) ) {
				if ( 'gs_market_idem_replay' === $res->get_error_code() && $replay['hit'] ) {
					return is_array( $replay['result'] ) ? $replay['result'] : array( 'idempotent_replay' => true );
				}
				return $res; // all other WP_Errors (cap, insufficient, invariant, conflict) as-is.
			}

			// --- 7. AFTER commit: build the response, persist result_json, anchor, audit. ---
			$b      = self::market_b( $res['market_row'] );
			$price  = Gend_GS_BC_Math::price( $res['q_yes_new'], $res['q_no_new'], $b );
			$market = self::get_market( $market_id );
			$rake   = self::rake_bps( is_object( $market ) ? $market : $res['market_row'] );

			// Current position snapshot (post-trade) for the response.
			$pos = $wpdb->get_row( $wpdb->prepare(
				"SELECT shares, cost_dgen, realized_dgen FROM {$positions}
				 WHERE market_id=%d AND user_id=%d AND outcome=%s",
				$market_id,
				$user_id,
				$outcome
			) );

			$response = array(
				'direction'   => $direction,
				'outcome'     => $outcome,
				'cost_dgen'   => $is_buy ? $res['cost'] : '0',
				'refund_dgen' => $is_buy ? '0' : $res['refund'],
				'q_yes'       => $res['q_yes_new'],
				'q_no'        => $res['q_no_new'],
				'escrow_dgen' => $res['escrow_new'],
				'p_yes'       => $price['p_yes'],
				'p_no'        => $price['p_no'],
				'position'    => array(
					'shares'        => is_object( $pos ) ? (string) $pos->shares : '0',
					'cost_dgen'     => is_object( $pos ) ? (string) $pos->cost_dgen : '0',
					'realized_dgen' => is_object( $pos ) ? (string) $pos->realized_dgen : '0',
				),
				'rake_bps'    => $rake,
				'rake_disclosure' => sprintf(
					'Platform fee: %s%% — skimmed from the losing pool at resolution (not charged now).',
					rtrim( rtrim( number_format( $rake / 100, 2, '.', '' ), '0' ), '.' )
				),
			);

			// Persist result_json for future idempotent replays.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$idem_table} SET result_json=%s WHERE idem_key=%s",
				wp_json_encode( $response ),
				$idem_key
			) );

			// Chain-anchor chain.market.bet AFTER commit, outside the lock (from_uid = bettor).
			$tx = self::anchor( self::TX_BET, $user_id, array(
				'market_id' => $market_id,
				'outcome'   => $outcome,
				'dir'       => $direction,
				'delta'     => (string) $delta_shares,
				'cost'      => $is_buy ? $res['cost'] : $res['refund'],
			) );

			self::audit( 'collab.market.bet', array(
				'market_id' => $market_id,
				'user_id'   => $user_id,
				'outcome'   => $outcome,
				'dir'       => $direction,
				'delta'     => (string) $delta_shares,
				'cost'      => $is_buy ? $res['cost'] : $res['refund'],
				'simulated' => false,
			), $tx );

			$response['chain_tx_id'] = $tx;
			return $response;
		}

		/* -----------------------------------------------------------------
		 * SETTLEMENT (Phase 88) — the LAST money-moving code in the milestone.
		 * resolve() (deterministic oracle read + idempotent CAS locked->resolved
		 * + deadline-VOID) / pay_market_batch() (resumable per-position
		 * CAS-before-credit payout / VOID refund + rake+residual escrow-drain-to-0,
		 * NO-MINT) / settle() (the hook orchestration: lock-then-resolve).
		 *
		 * ALL flag-INDEPENDENT plain statics (no GS_COLLAB_MARKET_PUBLIC guard
		 * inside — see the class docblock at the top): once a market exists with
		 * real stakes, turning the flag OFF must NEVER strand a bettor's DGEN. The
		 * flag gates the CALLERS (the 88-03 hook/sweep wiring), not the engine.
		 * ----------------------------------------------------------------- */

		/**
		 * RESOLVE (RESOLVE-01 / RESOLVE-04) — deterministic, idempotent market
		 * resolution. Reads the Phase-85 recorded outcome (NO human/admin oracle
		 * path anywhere) and CASes the FSM locked->resolved. NO funds move here;
		 * per-position payout is the resumable pay_market_batch() run from the
		 * 88-03 sweep (the locked "resolve synchronously, PAY in a cron-batch"
		 * decision). Flag-INDEPENDENT.
		 *
		 * Outcome mapping (the ONLY inputs are the recorded outcome + the deadline):
		 *   - $forced_outcome === 'void'  -> VOID directly (the deadline-VOID branch,
		 *     RESOLVE-04; recorded DIRECTLY to avoid a contract self-trigger loop,
		 *     mirror resolver.php:323-325).
		 *   - else read gs_collab_contract_outcomes: success=>'yes', fail=>'no',
		 *     void=>'void'; a NULL/unknown outcome => the SAFE default 'void'.
		 *
		 * Idempotency: the CAS `... SET state='resolved' WHERE id=%d AND state='locked'`
		 * with rows_affected===1 gates ALL anchor/event/audit writes. A duplicate
		 * resolve (hook + sweep both firing) sees rows_affected===0 and is a pure
		 * no-op — NO funds move, NO re-anchor. NOT wrapped in a transaction (a simple
		 * CAS + metadata write; do_trade_locked opens its own START TRANSACTION and
		 * MySQL does not nest — 88-RESEARCH anti-pattern).
		 *
		 * @param int         $market_id      Market id.
		 * @param string|null $forced_outcome Pass 'void' to force the deadline-VOID path.
		 * @return bool true on the winning locked->resolved transition; false on a no-op
		 *              (missing market, not lockable, or already resolved).
		 */
		public static function resolve( int $market_id, $forced_outcome = null ) : bool {
			global $wpdb;

			$market_id = (int) $market_id;
			if ( $market_id <= 0 || ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return false;
			}

			// 1. Load the market row.
			$market = self::get_market( $market_id );
			if ( ! is_object( $market ) ) {
				return false;
			}
			// Already terminal (resolved/paid/void) -> idempotent no-op.
			$state = (string) $market->state;
			if ( 'resolved' === $state || 'paid' === $state || 'void' === $state ) {
				return false;
			}

			// 2. Determine the winning outcome — deterministic, no human oracle.
			if ( null !== $forced_outcome && 'void' === (string) $forced_outcome ) {
				// RESOLVE-04 deadline-VOID: recorded DIRECTLY (no contract round-trip).
				$resolved_outcome = 'void';
			} else {
				$contract_task_id = (int) $market->contract_task_id;
				$recorded         = null;
				if ( $contract_task_id > 0 && method_exists( 'Gend_GS_Collab_Schema', 'contract_outcomes_table' ) ) {
					$outcomes = Gend_GS_Collab_Schema::contract_outcomes_table();
					$recorded = $wpdb->get_var( $wpdb->prepare(
						"SELECT outcome FROM {$outcomes} WHERE contract_task_id = %d LIMIT 1",
						$contract_task_id
					) );
				}
				$map = array( 'success' => 'yes', 'fail' => 'no', 'void' => 'void' );
				// NULL/unknown -> the SAFE default VOID (RESOLVE-04, never a forced guess).
				$resolved_outcome = ( is_string( $recorded ) && isset( $map[ $recorded ] ) ) ? $map[ $recorded ] : 'void';
			}

			$markets = Gend_GS_Collab_Schema::markets_table();

			// 3. DEFENSIVE lock-first (88-RESEARCH Pitfall 5): if still 'open', run the
			//    idempotent open->locked CAS so the resolve CAS has its precondition. A
			//    market already 'locked' skips this (lock() is a no-op there).
			if ( 'open' === $state ) {
				self::lock( $market_id ); // idempotent CAS; leaves it 'locked'.
			}

			// 4. IDEMPOTENT resolve CAS (RESOLVE-01) — mirror lock() rows_affected===1.
			$won = $wpdb->query( $wpdb->prepare(
				"UPDATE {$markets} SET state='resolved', resolved_outcome=%s, resolved_at=%d
				 WHERE id=%d AND state='locked'",
				$resolved_outcome,
				time(),
				$market_id
			) );
			if ( 1 !== (int) $won ) {
				// Already resolved (hook+sweep collapse) or not lockable -> no-op. NO funds move.
				return false;
			}

			// 5. ONLY on the winning transition: anchor + event + audit (NO payout here).
			// A resolve to VOID anchors chain.market.void; a yes/no resolve anchors the
			// generic locked-family lifecycle tx (payout/residual anchor at pay time).
			$tx = self::anchor(
				'void' === $resolved_outcome ? self::TX_VOID : self::TX_LOCKED,
				0,
				array(
					'market_id'        => $market_id,
					'match_id'         => (int) $market->match_id,
					'resolved_outcome' => $resolved_outcome,
					'event'            => 'resolved',
				)
			);
			self::audit( 'collab.market.resolved', array(
				'market_id'        => $market_id,
				'match_id'         => (int) $market->match_id,
				'resolved_outcome' => $resolved_outcome,
				'forced'           => ( null !== $forced_outcome ),
			), $tx );
			self::market_event( $market_id, 'resolved', array(
				'resolved_outcome' => $resolved_outcome,
				'forced'           => ( null !== $forced_outcome ),
			), $tx );

			return true;
		}

		/**
		 * PAY_MARKET_BATCH (RESOLVE-02 / RESOLVE-04) — the resumable per-position
		 * settlement drain. For each UNPAID position on a 'resolved' market:
		 * CAS-claim `paid=1 WHERE paid=0` FIRST (rows_affected===1), THEN mycred_add
		 * (claim-before-credit — mirror fund_subsidy :587-595) so a mid-batch hub
		 * cold-start RESUMES and NEVER double-pays (the position id is the MyCred
		 * ref_id, unique per position, dedup-safe). Batch-limited. When NO unpaid
		 * positions remain: skim the residual (subsidy-not-lost + withheld rake +
		 * floor dust) to the treasury and drain escrow_dgen to exactly 0, flipping
		 * the FSM resolved->paid. Flag-INDEPENDENT.
		 *
		 * Payout math (whole DGEN, NO native float, maker-favor DOWN):
		 *   - WINNER (pos->outcome === resolved_outcome):
		 *       bc_floor( shares × (10000 − rake_bps)/10000 / 1e6 )
		 *   - VOID   (resolved_outcome === 'void', RESOLVE-04): cost_dgen verbatim, NO rake.
		 *   - LOSER: 0 (still claimed paid=1 so it is not re-scanned).
		 *
		 * NO-MINT (88-RESEARCH §The No-Mint Proof): the Phase-86 escrow-invariant
		 * escrow_dgen >= floor(max(q_yes,q_no)/1e6) = Σ winning shares guarantees
		 * escrow covers every payout (minus-rake is strictly safe). NEVER add a
		 * mint/top-up path — if escrow were somehow short, FAIL LOUDLY and bail.
		 *
		 * @param int $market_id Market id.
		 * @param int $limit     Positions paid per run (default 50, mirrors the sweep).
		 * @return array ['done'=>bool,'status'=>string,'paid'=>int,'market_id'=>int,...].
		 */
		public static function pay_market_batch( int $market_id, int $limit = 50 ) : array {
			global $wpdb;

			$market_id = (int) $market_id;
			$limit     = $limit > 0 ? (int) $limit : 50;
			if ( $market_id <= 0 || ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
				return array( 'done' => false, 'status' => 'bad_id', 'paid' => 0, 'market_id' => $market_id );
			}

			// 1. Load the market; require state='resolved'.
			$market = self::get_market( $market_id );
			if ( ! is_object( $market ) ) {
				return array( 'done' => false, 'status' => 'not_found', 'paid' => 0, 'market_id' => $market_id );
			}
			$state = (string) $market->state;
			if ( 'paid' === $state ) {
				return array( 'done' => true, 'status' => 'already_paid', 'paid' => 0, 'market_id' => $market_id );
			}
			if ( 'resolved' !== $state ) {
				return array( 'done' => false, 'status' => 'not_resolved', 'paid' => 0, 'market_id' => $market_id );
			}
			if ( ! function_exists( 'mycred_add' ) ) {
				return array( 'done' => false, 'status' => 'no_mycred', 'paid' => 0, 'market_id' => $market_id );
			}

			$resolved_outcome = (string) $market->resolved_outcome;
			$is_void          = ( 'void' === $resolved_outcome );
			$rake_bps         = self::rake_bps( $market );
			$positions        = Gend_GS_Collab_Schema::positions_table();
			$markets          = Gend_GS_Collab_Schema::markets_table();

			// 2. Select up to $limit UNPAID positions (uses idx_market_paid).
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, user_id, outcome, shares, cost_dgen FROM {$positions}
				 WHERE market_id=%d AND paid=0 LIMIT %d",
				$market_id,
				$limit
			) );

			$paid_count = 0;

			foreach ( (array) $rows as $pos ) {
				// a. Compute this position's payout (whole DGEN, bcmath — NO float).
				if ( $is_void ) {
					// RESOLVE-04: refund the net cost basis verbatim (net of Phase-87 sells). NO rake.
					$payout_dgen = (string) $pos->cost_dgen;
				} elseif ( (string) $pos->outcome === $resolved_outcome ) {
					// WINNER: shares × (10000 − rake)/10000 / 1e6, floor DOWN (maker-favor).
					$net_num      = bcmul( (string) $pos->shares, (string) ( 10000 - $rake_bps ), 0 );
					$payout_micro = bcdiv( $net_num, '10000', Gend_GS_BC_Math::SCALE );
					$payout_dgen  = Gend_GS_BC_Math::bc_floor( bcdiv( $payout_micro, '1000000', Gend_GS_BC_Math::SCALE ) );
				} else {
					// LOSER: 0 (still claimed below so it is not re-scanned).
					$payout_dgen = '0';
				}

				// b. CLAIM-BEFORE-CREDIT (RESOLVE-02/04 idempotency — mirror fund_subsidy).
				$claimed = $wpdb->query( $wpdb->prepare(
					"UPDATE {$positions} SET paid=1, paid_at=%d, payout_dgen=%s WHERE id=%d AND paid=0",
					time(),
					$payout_dgen,
					(int) $pos->id
				) );
				if ( 1 !== (int) $claimed ) {
					// Another run already paid this position — NEVER double-pay.
					continue;
				}
				$paid_count++;

				// c. CREDIT — only when payout > 0. A loser (0) is marked paid, no credit/anchor.
				if ( bccomp( $payout_dgen, '0', 0 ) <= 0 ) {
					continue;
				}
				// Whole-integer DGEN discipline: (float) ONLY at the myCRED boundary.
				if ( false !== strpos( $payout_dgen, '.' ) ) {
					// Should never happen (bc_floor / cost_dgen are integers) — bail this position.
					continue;
				}

				if ( $is_void ) {
					mycred_add(
						'gend_gs_market_refund',
						(int) $pos->user_id,
						(float) $payout_dgen,
						sprintf( 'Collaboration market voided — refund, Market #%d', $market_id ),
						(int) $pos->id, // ref_id = position id -> myCRED dedup on re-credit.
						array( 'market_id' => $market_id, 'position_id' => (int) $pos->id ),
						'transact'
					);
					$tx = self::anchor( self::TX_REFUND, (int) $pos->user_id, array(
						'market_id'   => $market_id,
						'position_id' => (int) $pos->id,
						'refund_dgen' => $payout_dgen,
					) );
					self::audit( 'collab.market.refund', array(
						'market_id'   => $market_id,
						'position_id' => (int) $pos->id,
						'user_id'     => (int) $pos->user_id,
						'refund_dgen' => $payout_dgen,
					), $tx );
				} else {
					mycred_add(
						'gend_gs_market_payout',
						(int) $pos->user_id,
						(float) $payout_dgen,
						sprintf( 'Collaboration market payout — Market #%d', $market_id ),
						(int) $pos->id, // ref_id = position id -> myCRED dedup on re-credit.
						array( 'market_id' => $market_id, 'position_id' => (int) $pos->id, 'outcome' => (string) $pos->outcome ),
						'transact'
					);
					$tx = self::anchor( self::TX_PAYOUT, (int) $pos->user_id, array(
						'market_id'   => $market_id,
						'position_id' => (int) $pos->id,
						'outcome'     => (string) $pos->outcome,
						'payout_dgen' => $payout_dgen,
					) );
					self::audit( 'collab.market.payout', array(
						'market_id'   => $market_id,
						'position_id' => (int) $pos->id,
						'user_id'     => (int) $pos->user_id,
						'outcome'     => (string) $pos->outcome,
						'payout_dgen' => $payout_dgen,
					), $tx );
				}
			}

			// 4. Any positions still unpaid? -> the next sweep run drains the rest.
			$remaining = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$positions} WHERE market_id=%d AND paid=0",
				$market_id
			) );
			if ( $remaining > 0 ) {
				return array(
					'done'      => false,
					'status'    => 'batch_paid',
					'paid'      => $paid_count,
					'remaining' => $remaining,
					'market_id' => $market_id,
				);
			}

			// 5. NONE remain — ESCROW DRAIN TO 0 + FSM resolved->paid (88-RESEARCH Pattern 3).
			//    Re-read the CURRENT escrow: the rake was WITHHELD from winner payouts so it is
			//    already inside the remaining escrow; a VOID's full subsidy is likewise the
			//    remaining escrow. Do NOT run a live per-winner `escrow = escrow - payout`
			//    (BIGINT UNSIGNED underflow, Pitfall 1) — this ONE re-read residual is the drain.
			$fresh      = self::get_market( $market_id );
			$escrow_now = is_object( $fresh ) ? (string) $fresh->escrow_dgen : '0';
			// Residual = escrow MINUS everything already paid to winners/refunds — the rake +
			// rounding leftover. Computed in ONE step from the summed per-position payout_dgen
			// (NOT a per-winner live `escrow -= payout` — that risks BIGINT UNSIGNED underflow,
			// Pitfall 1). CRITICAL: without subtracting Σpayout the winner payouts are
			// DOUBLE-COUNTED (minted) — the treasury would receive the FULL escrow while the
			// winners were ALSO paid. The escrow-invariant guarantees escrow >= Σpayout, so the
			// result is >= 0 (clamped defensively).
			$paid_sum = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(payout_dgen),0) FROM {$positions} WHERE market_id=%d AND paid=1",
				$market_id
			) );
			$residual = bcsub( $escrow_now, $paid_sum, 0 );
			if ( bccomp( $residual, '0', 0 ) < 0 ) {
				$residual = '0';
			}

			if ( bccomp( $residual, '0', 0 ) > 0 && false === strpos( $residual, '.' ) ) {
				mycred_add(
					'gend_gs_market_residual',
					self::treasury_uid(),
					(float) $residual,
					sprintf( 'Collaboration market residual + rake to treasury — Market #%d', $market_id ),
					$market_id,
					array( 'market_id' => $market_id, 'residual_dgen' => $residual, 'rake_bps' => $rake_bps ),
					'transact'
				);
				$rtx = self::anchor( self::TX_RESIDUAL, 0, array(
					'market_id'     => $market_id,
					'residual_dgen' => $residual,
					'rake_bps'      => $rake_bps,
				) );
				self::audit( 'collab.market.residual', array(
					'market_id'     => $market_id,
					'treasury'      => self::treasury_uid(),
					'residual_dgen' => $residual,
					'rake_bps'      => $rake_bps,
				), $rtx );
			}

			// FLIP resolved->paid + escrow_dgen=0 (single final UPDATE; rows_affected===1 gate).
			$flipped = $wpdb->query( $wpdb->prepare(
				"UPDATE {$markets} SET escrow_dgen=0, state='paid', paid_at=%d WHERE id=%d AND state='resolved'",
				time(),
				$market_id
			) );
			if ( 1 === (int) $flipped ) {
				self::market_event( $market_id, 'paid', array(
					'resolved_outcome' => $resolved_outcome,
					'residual_dgen'    => $residual,
				) );
			}

			return array(
				'done'      => true,
				'status'    => 'paid',
				'paid'      => $paid_count,
				'residual'  => $residual,
				'market_id' => $market_id,
			);
		}

		/**
		 * SETTLE — the flag-INDEPENDENT hook orchestration (lock-then-resolve). The
		 * 88-03 wiring binds this to gend_gs_collab_outcome_recorded at a priority
		 * AFTER the Phase-86 on_outcome_recorded lock subscriber (a SEPARATE
		 * subscriber — Phase-86 on_outcome_recorded is left UNTOUCHED). Does ONLY
		 * the synchronous deterministic resolve(); the resumable per-position PAYOUT
		 * is left to the gs_fifteen_min sweep (88-03), per the locked "resolve
		 * synchronously, PAY in a cron-batch" decision — keeps the hook cheap.
		 *
		 * Hub-only (money), but NO GS_COLLAB_MARKET_PUBLIC guard — a bettor's DGEN
		 * must settle even with the flag off (mirror the Phase-85 recorder + Phase-86
		 * on_outcome_recorded flag-independence).
		 *
		 * @param int    $match_id Match id whose terminal outcome was recorded.
		 * @param string $outcome  'success'|'fail'|'void' (unused — resolve() re-reads it).
		 * @return void
		 */
		public static function settle( $match_id, $outcome = '' ) : void {
			if ( ! self::is_hub() ) {
				return; // hub-only money.
			}
			$market = self::get_market_by_match( (int) $match_id );
			if ( ! is_object( $market ) || ! isset( $market->id ) ) {
				return; // no market ever opened for this contract.
			}
			// resolve() reads the recorded outcome itself + lock-then-resolves idempotently.
			// The authoritative per-position drain is the 88-03 sweep (keep settle() fast).
			self::resolve( (int) $market->id );
		}
	}
}
