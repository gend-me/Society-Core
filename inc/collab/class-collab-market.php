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

		/** Anchored on the open->locked FSM transition. */
		const TX_LOCKED = 'chain.market.locked';

		/** Anchored on the same-operator auto-void at create. */
		const TX_VOID = 'chain.market.void';

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
				$id = Gend_Chain_Validator::submit_tx( array(
					'type'         => $type,
					'from_app_id'  => '',
					'from_user_id' => $from_uid,
					'payload'      => $payload,
					'ts'           => time(),
				) );
				return is_string( $id ) ? $id : (string) $id;
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

			// Compute b + subsidy = ceil(b·ln2) BEFORE insert so the row carries them.
			$b            = self::market_b();
			$subsidy_dgen = Gend_GS_BC_Math::bc_ceil( bcmul( $b, Gend_GS_BC_Math::LN2, Gend_GS_BC_Math::SCALE ) );
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

			$cost = Gend_GS_BC_Math::bc_ceil(
				bcsub(
					Gend_GS_BC_Math::cost( $q_yes_new, $q_no_new, $b ),
					Gend_GS_BC_Math::cost( $q_yes, $q_no, $b ),
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
		 * STAKE-03). Mirrors the proven START TRANSACTION + SELECT ... FOR UPDATE primitive
		 * in contracts-and-payments/class-ydgen-ledger.php:503-515.
		 *
		 * SCOPE (Phase 86): the stake ledger is SIMULATED — the position row records the
		 * would-be holding but Phase 86 does NOT debit the real bettor's DGEN. The ONLY real
		 * DGEN move is the treasury subsidy (fund_subsidy). Phase 87 adds the real bettor
		 * debit. The concurrency + invariant + insider machinery is real and proven here.
		 *
		 * Flow:
		 *   1. ENTRY GATE (pre-transaction): insider (admin/mod of either matched group) -> reject.
		 *   2. START TRANSACTION; SELECT ... WHERE id AND state='open' FOR UPDATE.
		 *   3. RE-QUOTE off the LOCKED q (never the stale q the caller saw).
		 *   4. HARD GATE: escrow_new < max_payout(q_new) -> ROLLBACK + reject (NEVER mint).
		 *   5. Optimistic version guard: UPDATE ... version=version+1 WHERE version=%d; !=1 -> ROLLBACK.
		 *   6. Upsert the SIMULATED position; append a 'trade' event.
		 *   7. COMMIT (ROLLBACK on any Throwable).
		 *   8. AFTER commit (outside the lock): audit.
		 *
		 * @param int    $market_id    Market id.
		 * @param int    $user_id      Bettor user id.
		 * @param string $outcome      'yes' or 'no'.
		 * @param string $delta_shares Micro-shares to buy (positive integer string).
		 * @return array|WP_Error ['cost_dgen','q_yes','q_no','escrow_dgen','p_yes','p_no'] or WP_Error.
		 */
		public static function trade( int $market_id, int $user_id, string $outcome, string $delta_shares ) {
			global $wpdb;

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

			$markets   = Gend_GS_Collab_Schema::markets_table();
			$positions = Gend_GS_Collab_Schema::positions_table();
			$b         = self::market_b( $pre );

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

				// --- 3. RE-QUOTE off the LOCKED q (never the stale q shown to the caller). ---
				$q_yes = (string) $m->q_yes;
				$q_no  = (string) $m->q_no;
				$q_yes_new = ( 'yes' === $outcome ) ? bcadd( $q_yes, (string) $delta_shares, 0 ) : $q_yes;
				$q_no_new  = ( 'no' === $outcome ) ? bcadd( $q_no, (string) $delta_shares, 0 ) : $q_no;

				$cost = Gend_GS_BC_Math::bc_ceil(
					bcsub(
						Gend_GS_BC_Math::cost( $q_yes_new, $q_no_new, $b ),
						Gend_GS_BC_Math::cost( $q_yes, $q_no, $b ),
						Gend_GS_BC_Math::SCALE
					)
				);
				$escrow_new = bcadd( (string) $m->escrow_dgen, $cost, 0 );

				// --- 4. ESCROW-INVARIANT HARD GATE (RESOLVE-05). ---
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

				// --- 6. Upsert the SIMULATED position (Phase 87 adds the REAL bettor debit). ---
				$now = time();
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$positions} (market_id, user_id, outcome, shares, cost_dgen, created_at, updated_at)
					 VALUES (%d, %d, %s, %d, %s, %d, %d)
					 ON DUPLICATE KEY UPDATE shares = shares + VALUES(shares),
					                         cost_dgen = cost_dgen + VALUES(cost_dgen),
					                         updated_at = VALUES(updated_at)",
					$market_id,
					$user_id,
					$outcome,
					(string) $delta_shares,
					$cost,
					$now,
					$now
				) );

				self::market_event( $market_id, 'trade', array(
					'user_id'  => $user_id,
					'outcome'  => $outcome,
					'delta'    => (string) $delta_shares,
					'cost'     => $cost,
					'q_yes'    => $q_yes_new,
					'q_no'     => $q_no_new,
					'escrow'   => $escrow_new,
					'simulated' => true,
				) );

				// --- 7. COMMIT. ---
				$wpdb->query( 'COMMIT' );
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gs_market_trade_failed', $e->getMessage(), array( 'status' => 500 ) );
			}

			// --- 8. AFTER commit (outside the lock): audit + final read. ---
			self::audit( 'collab.market.trade', array(
				'market_id' => $market_id,
				'user_id'   => $user_id,
				'outcome'   => $outcome,
				'delta'     => (string) $delta_shares,
				'cost_dgen' => $cost,
				'simulated' => true,
			) );

			$price = Gend_GS_BC_Math::price( $q_yes_new, $q_no_new, $b );

			return array(
				'cost_dgen'   => $cost,
				'q_yes'       => $q_yes_new,
				'q_no'        => $q_no_new,
				'escrow_dgen' => $escrow_new,
				'p_yes'       => $price['p_yes'],
				'p_no'        => $price['p_no'],
			);
		}
	}
}
