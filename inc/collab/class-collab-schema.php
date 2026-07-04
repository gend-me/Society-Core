<?php
/**
 * gend-society — GenD Match collab schema installer (Phase 82-01, v12.0).
 *
 * Multisite-aware installer for the swipe-ledger correctness spine — installs BOTH
 *   wp_gs_collab_swipes  (Phase 82 populates rows: the authoritative never-re-show ledger)
 *   wp_gs_collab_matches (SCHEMA ONLY here; Phase 83 populates rows — mutual-match engine)
 * in ONE version-gated dbDelta routine. Phase 83 needs zero migration.
 *
 * Mirrors inc/class-availability-schema.php EXACTLY (verified 2026-07-02) — only the
 * DB_VERSION_OPT, the two CREATE TABLE bodies, and the table-name accessors differ.
 *
 * Multisite triple-coverage (Pitfall 3):
 *   1. register_activation_hook → install_all_sites() loops get_sites()
 *      (handles EXISTING blogs at the activation moment).
 *   2. wp_initialize_site → install_new_site() handles future-created blogs.
 *   3. init priority 5 → maybe_install() self-heals any blog whose option is
 *      missing or stale (handles the case where the hub is live-patched
 *      without re-activation — belt-and-braces).
 *
 * Activation safety (Pitfall 2): every public entrypoint wraps the install
 * call in try/catch — if dbDelta throws, the failure is error_log'd and the
 * calling context (activation, init, wp_initialize_site) continues so
 * WordPress does NOT auto-deactivate gend-society.
 *
 * Per-blog version option (`gs_collab_db_version`, a NEW key — NOT reusing
 * gs_calendar_db_version) so each subsite tracks its own schema version
 * independently — matches Pitfall 3 self-heal requirement.
 *
 * SWIPE-04 (never re-show a decided card) is enforced at the DB layer here via
 * UNIQUE(from_group_id,to_group_id) on gs_collab_swipes — THE load-bearing
 * correctness invariant of the whole GenD Match feature. record_swipe() writes
 * via INSERT IGNORE so a re-swipe / double-tap on an existing (from,to) pair is
 * an idempotent no-op (never a new row, never flips the decision).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Schema {

	/**
	 * Schema version. Bump on any dbDelta change; maybe_install() will
	 * re-run dbDelta on every blog whose option is below this value.
	 */
	// 1.3.0 (Phase 86-02): added the three LMSR market tables
	// gs_collab_markets / gs_collab_positions / gs_collab_market_events.
	// 1.4.0 (Phase 87-01): gs_collab_bet_idem per-bet idempotency guard +
	// gs_collab_positions.realized_dgen for sell-back realized P/L.
	const DB_VERSION     = '1.4.0';
	const DB_VERSION_OPT = 'gs_collab_db_version';

	/**
	 * Wire all hooks. Called from gend-society.php after the require_once
	 * (this file is silent at include time — all side effects land via init()).
	 */
	public static function init() : void {
		// Multisite: future-created blogs (WP 5.1+).
		add_action( 'wp_initialize_site', array( __CLASS__, 'install_new_site' ), 10, 1 );

		// Self-heal: any blog where the option is missing/stale gets dbDelta
		// on the next request. init priority 5 runs BEFORE anything that
		// might query these tables (Plan 82-02 REST swipe/deck routes assume
		// the swipes table exists).
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );

		// Network/per-site activation: existing blogs at the activation
		// moment. register_activation_hook is keyed on the PLUGIN ENTRYPOINT
		// path — passed in via GS_DIR since __FILE__ here resolves to the
		// include, not the main plugin file.
		register_activation_hook(
			GS_DIR . 'gend-society.php',
			array( __CLASS__, 'install_all_sites' )
		);
	}

	/**
	 * Per-blog version-gated installer. Idempotent — safe to call on every
	 * init request. Reads the PER-BLOG option (NOT a network option) so each
	 * subsite tracks its own schema version independently.
	 *
	 * Wrapped in try/catch (Pitfall 2) so dbDelta failure NEVER fatals the
	 * request — error_log only; WP does not auto-deactivate gend-society.
	 */
	public static function maybe_install() : void {
		// Version-gate: skip if already at current version.
		if ( get_option( self::DB_VERSION_OPT ) === self::DB_VERSION ) {
			return;
		}
		try {
			self::install_tables();
			update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
		} catch ( \Throwable $e ) {
			// Pitfall 2: NEVER fatal — error_log and continue.
			error_log(
				'[gs_collab_schema] maybe_install failed on blog '
				. get_current_blog_id() . ': ' . $e->getMessage()
			);
		}
	}

	/**
	 * Activation hook handler — loops every existing site and installs.
	 * Wrapped in try/catch per blog so one bad blog doesn't fatal activation
	 * for the rest (Pitfall 2 / Pitfall 3).
	 *
	 * get_sites( array( 'number' => 0 ) ): 0 = unlimited per WP source —
	 * necessary for hub clusters with many subsites; default 100 cap would
	 * silently skip blogs.
	 */
	public static function install_all_sites() : void {
		if ( ! function_exists( 'get_sites' ) || ! function_exists( 'switch_to_blog' ) ) {
			// Single-site fallback.
			try {
				self::install_tables();
				update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
			} catch ( \Throwable $e ) {
				error_log( '[gs_collab_schema] single-site activate failed: ' . $e->getMessage() );
			}
			return;
		}

		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			$blog_id = (int) ( is_object( $site ) ? $site->blog_id : ( isset( $site['blog_id'] ) ? $site['blog_id'] : 0 ) );
			if ( $blog_id <= 0 ) {
				continue;
			}
			try {
				switch_to_blog( $blog_id );
				self::install_tables();
				update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
			} catch ( \Throwable $e ) {
				error_log( '[gs_collab_schema] activate failed on blog ' . $blog_id . ': ' . $e->getMessage() );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * wp_initialize_site handler (WP 5.1+). Called when a NEW subsite is
	 * created via wp_insert_site() / wp-cli site create.
	 *
	 * @param WP_Site|object $new_site WP_Site instance for the new blog.
	 */
	public static function install_new_site( $new_site ) : void {
		$blog_id = is_object( $new_site ) ? (int) $new_site->blog_id : 0;
		if ( $blog_id <= 0 ) {
			return;
		}
		try {
			switch_to_blog( $blog_id );
			self::install_tables();
			update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
		} catch ( \Throwable $e ) {
			error_log( '[gs_collab_schema] new-site install failed on blog ' . $blog_id . ': ' . $e->getMessage() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * dbDelta both tables. NOT version-gated here — callers handle that.
	 * Public so maybe_install / install_all_sites / install_new_site can
	 * invoke it after switch_to_blog().
	 */
	public static function install_tables() : void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Table 1: gs_collab_swipes — the authoritative never-re-show ledger
		// (Phase 82 populates). UNIQUE(from_group_id,to_group_id) is THE
		// correctness invariant (SWIPE-04): a decided (from,to) pair can never
		// be re-inserted, so a passed/interested card never resurfaces.
		//   decision ENUM('right','left'): right=interested, left=pass.
		//   idx_to (to_group_id, decision) forward-fits Phase 83's
		//   "who right-swiped me" mutual-match lookup.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_swipes (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				from_group_id BIGINT UNSIGNED NOT NULL,
				to_group_id BIGINT UNSIGNED NOT NULL,
				decision ENUM('right','left') NOT NULL,
				actor_user_id BIGINT UNSIGNED NOT NULL,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_pair (from_group_id, to_group_id),
				KEY idx_from (from_group_id),
				KEY idx_to (to_group_id, decision)
			) {$charset_collate};"
		);

		// Table 2: gs_collab_matches — SCHEMA ONLY in Phase 82; Phase 83
		// populates it (mutual-match engine). Do NOT write any INSERT to this
		// table anywhere in Phase 82. group_a/group_b are the normalized
		// unordered pair (LEAST/GREATEST of the two group ids); the
		// UNIQUE(group_a,group_b) is installed now so Phase 83's atomic
		// mutual-match under a race is already enforceable at the DB layer.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_matches (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				group_a BIGINT UNSIGNED NOT NULL,
				group_b BIGINT UNSIGNED NOT NULL,
				status ENUM('matched','contracted','resolved','void') NOT NULL DEFAULT 'matched',
				intro_thread_id BIGINT UNSIGNED NULL,
				contract_task_id BIGINT UNSIGNED NULL,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_pair (group_a, group_b)
			) {$charset_collate};"
		);

		// Table 3: gs_collab_proposals — Phase 84 (COLLAB-01) PENDING-proposal
		// state for the two-party propose→accept→decline escalation handshake.
		// One LIVE proposal per match: UNIQUE(match_id) — a re-propose/amend
		// clears the same row back to prop_status='pending' (engine, Task 2),
		// never a second row. NO contract, NO project, NO DGEN is moved on
		// propose; the money move happens ONLY on accept (engine). Added here
		// (not on gs_collab_matches) to keep the match spine stable and isolate
		// proposal churn — DB_VERSION 1.1.0 self-heals via maybe_install().
		//   escrow_model ENUM('one_sided','both_sided'): both_sided is stored
		//   but hard-rejected (clean 400) by the engine until Plan 84-02 lands.
		//   prop_status ENUM('pending','accepted','declined','cancelled').
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_proposals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				match_id BIGINT UNSIGNED NOT NULL,
				proposer_group BIGINT UNSIGNED NOT NULL,
				payer_group BIGINT UNSIGNED NOT NULL,
				payee_group BIGINT UNSIGNED NOT NULL,
				escrow_model ENUM('one_sided','both_sided') NOT NULL DEFAULT 'one_sided',
				credits INT UNSIGNED NOT NULL DEFAULT 0,
				milestones_json LONGTEXT NULL,
				prop_status ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
				proposer_uid BIGINT UNSIGNED NOT NULL,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_match (match_id),
				KEY idx_status (prop_status)
			) {$charset_collate};"
		);

		// Table 4: gs_collab_contract_outcomes — Phase 85 (RESOLVE-03) DARK
		// terminal-outcome recorder store. Every CONTRACTED match's terminal
		// contract state (success|fail|void) is recorded here exactly once,
		// keyed UNIQUE(contract_task_id) — THE idempotency spine: a hook firing
		// AND the 15-min cron sweep for the same contract collapse to ONE row
		// (Gend_GS_Collab_Resolver::record() writes via INSERT IGNORE, and only
		// audits + chain-anchors when rows_affected===1). Recorded REGARDLESS of
		// GS_COLLAB_MARKET_PUBLIC (runs dark). NO money moves in Phase 85 — the
		// future market (Phase 86) resolves off these rows; payout is Phase 88.
		//   outcome ENUM('success','fail','void'): paid=>success, forfeited/
		//     expired=>fail, cancelled(mutual)=>void.
		//   reason VARCHAR: completed | forfeited | expired | cancelled.
		//   source ENUM('hook','cron'): which observer recorded it first.
		//   chain_tx_id: Gend_Chain_Validator::submit_tx id (nullable/backfillable).
		//   match_id denormalized in for cheap Phase-86/88 joins.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_contract_outcomes (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				match_id BIGINT UNSIGNED NOT NULL,
				contract_task_id BIGINT UNSIGNED NOT NULL,
				outcome ENUM('success','fail','void') NOT NULL,
				reason VARCHAR(64) NOT NULL DEFAULT '',
				source ENUM('hook','cron') NOT NULL DEFAULT 'hook',
				chain_tx_id VARCHAR(64) NULL,
				recorded_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_contract (contract_task_id),
				KEY idx_match (match_id),
				KEY idx_outcome (outcome)
			) {$charset_collate};"
		);

		// Table 5: gs_collab_markets — Phase 86 (MARKET-01) the ONE binary
		// succeed/fail LMSR market per CONTRACTED collaboration match; THE
		// lockable InnoDB row the engine `SELECT ... FOR UPDATE`s (Phase-86
		// concurrency + escrow-invariant substrate — the q-vector NEVER lives
		// in wp_options). UNIQUE(match_id) enforces one market per collaboration
		// at the DB layer (MARKET-01 spine; create_market() writes via
		// INSERT IGNORE so a hook + sweep double-fire collapses to one row).
		// All money/share columns are scaled BIGINT (never DECIMAL/float — the
		// never-float-in-the-DB money rule):
		//   q_yes/q_no    scaled-int micro-shares (1e6/share), the LMSR q-vector.
		//   lmsr_b        scaled-int operator-set liquidity depth.
		//   subsidy_dgen  = ceil(b·ln2), integer DGEN pre-funded before open.
		//   escrow_dgen   running invariant LHS = subsidy + Σ collected.
		//   rake_bps      reserved for the Phase-88 losing-pool skim; the
		//                 invariant already reserves it (0 default → global).
		//   state         open→locked→resolved→paid FSM (+ void auto-void/refund).
		//   subsidy_funded idempotency guard so a retried create never double-
		//                 debits the treasury.
		//   resolve_by    deadline; the Phase-85 sweep locks a market past this.
		//   version       optimistic secondary guard for the FOR UPDATE CAS.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_markets (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				match_id BIGINT UNSIGNED NOT NULL,
				contract_task_id BIGINT UNSIGNED NULL,
				q_yes BIGINT UNSIGNED NOT NULL DEFAULT 0,
				q_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
				lmsr_b BIGINT UNSIGNED NOT NULL,
				subsidy_dgen BIGINT UNSIGNED NOT NULL DEFAULT 0,
				escrow_dgen BIGINT UNSIGNED NOT NULL DEFAULT 0,
				rake_bps INT UNSIGNED NOT NULL DEFAULT 0,
				state ENUM('open','locked','resolved','paid','void') NOT NULL DEFAULT 'open',
				subsidy_funded TINYINT(1) NOT NULL DEFAULT 0,
				resolve_by INT UNSIGNED NULL,
				version INT UNSIGNED NOT NULL DEFAULT 0,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_match (match_id),
				KEY idx_state (state),
				KEY idx_contract (contract_task_id)
			) {$charset_collate};"
		);

		// Table 6: gs_collab_positions — per-bettor holdings. Phase 87 populates
		// rows (the real DGEN-debit bet path); Phase 86 installs the SCHEMA + the
		// engine's `trade()` upsert path only. UNIQUE(market_id,user_id,outcome)
		// makes a member's YES/NO holding a single upsertable row (never a second
		// row on a repeat stake). shares = scaled-int micro-shares; cost_dgen =
		// cumulative integer DGEN paid (avg cost = cost_dgen/shares).
		//   realized_dgen (Phase 87-01): cumulative DGEN realized on sell-backs to
		//   the AMM (avg-cost basis); portfolio realized P/L = realized_dgen −
		//   cost-basis-of-sold-shares. dbDelta ALTERs an existing 1.3.0 table
		//   additively, so this column self-heals onto a live positions table.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_positions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				market_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				outcome ENUM('yes','no') NOT NULL,
				shares BIGINT UNSIGNED NOT NULL DEFAULT 0,
				cost_dgen BIGINT UNSIGNED NOT NULL DEFAULT 0,
				realized_dgen BIGINT NOT NULL DEFAULT 0,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_pos (market_id, user_id, outcome),
				KEY idx_user (user_id)
			) {$charset_collate};"
		);

		// Table 7: gs_collab_market_events — auditable lifecycle log. One row per
		// market state transition (created/subsidy_funded/trade/locked/resolved/
		// paid/void), with the JSON detail (q before/after, cost, actor) and the
		// chain.* anchor tx id. idx_market/idx_event forward-fit the Phase-88
		// resolve/payout audit trail.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_market_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				market_id BIGINT UNSIGNED NOT NULL,
				event ENUM('created','subsidy_funded','trade','locked','resolved','paid','void') NOT NULL,
				detail LONGTEXT NULL,
				chain_tx_id VARCHAR(64) NULL,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY idx_market (market_id),
				KEY idx_event (event)
			) {$charset_collate};"
		);

		// Table 8: gs_collab_bet_idem — Phase 87 (STAKE-01) per-bet idempotency
		// guard. The checkout mycred-log dedup (ref+ref_id+user_id+ctype) is TOO
		// COARSE for repeat bets on ONE market (identical across legitimate stakes),
		// so real member bets need their own per-bet key store. UNIQUE(idem_key) is
		// THE idempotency spine: place_bet() INSERT IGNOREs the key as the FIRST
		// statement inside its FOR-UPDATE transaction; a collision (rows_affected
		// ===0) means a replay -> read the stored result_json and return it verbatim
		// (NO double-debit). result_json holds the exact prior response, stored
		// post-commit; a same-key retry that lands before the first commit is
		// impossible because both serialize on the market-row FOR UPDATE lock.
		// Mirrors the INSERT IGNORE house idiom (swipes / create_market / outcomes).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}gs_collab_bet_idem (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				idem_key VARCHAR(64) NOT NULL,
				market_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				result_json LONGTEXT NULL,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_idem (idem_key),
				KEY idx_market_user (market_id, user_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Helper: fully-qualified swipes-ledger table name for the current blog.
	 * Plan 82-02 REST swipe/deck routes use this.
	 */
	public static function swipes_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_swipes';
	}

	/**
	 * Helper: fully-qualified matches table name for the current blog.
	 * Phase 83 writes use this. (No INSERTs in Phase 82.)
	 */
	public static function matches_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_matches';
	}

	/**
	 * Helper: fully-qualified proposals table name for the current blog.
	 * Phase 84 (Gend_GS_Collab_Contract) propose/accept/decline use this.
	 * UNIQUE(match_id) means one live proposal per match.
	 */
	public static function proposals_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_proposals';
	}

	/**
	 * Helper: fully-qualified contract-outcomes table name for the current blog.
	 * Phase 85 (Gend_GS_Collab_Resolver) record()/sweep() use this.
	 * UNIQUE(contract_task_id) means one recorded terminal outcome per contract.
	 */
	public static function contract_outcomes_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_contract_outcomes';
	}

	/**
	 * Helper: fully-qualified markets table name for the current blog.
	 * Phase 86 (Gend_GS_Collab_Market) create_market()/trade()/quote()/lock()
	 * use this. UNIQUE(match_id) means one binary market per collaboration —
	 * the lockable `SELECT ... FOR UPDATE` row.
	 */
	public static function markets_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_markets';
	}

	/**
	 * Helper: fully-qualified positions table name for the current blog.
	 * Phase 86 engine upsert path + Phase 87 bet path use this.
	 * UNIQUE(market_id,user_id,outcome) means one upsertable holding per side.
	 */
	public static function positions_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_positions';
	}

	/**
	 * Helper: fully-qualified market-events (lifecycle audit log) table name
	 * for the current blog. Phase 86+ market state transitions append here.
	 */
	public static function market_events_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_market_events';
	}

	/**
	 * Helper: fully-qualified bet-idempotency guard table name for the current
	 * blog. Phase 87 (Gend_GS_Collab_Market::place_bet) INSERT IGNOREs the
	 * per-bet idem_key here as the FIRST statement inside its FOR-UPDATE
	 * transaction; a collision (rows_affected===0) replays the stored result_json.
	 * UNIQUE(idem_key) means one recorded outcome per bet key.
	 */
	public static function bet_idem_table() : string {
		global $wpdb;
		return $wpdb->prefix . 'gs_collab_bet_idem';
	}

	/**
	 * Per-member per-market position cap in whole DGEN (STAKE-01 discretion).
	 * Row→option→const precedence mirrors Gend_GS_Collab_Market::market_b() /
	 * rake_bps(). Enforced INSIDE the FOR-UPDATE lock on a BUY: reject a buy
	 * that pushes SUM(cost_dgen) over this cap (TOCTOU-safe). Selling never hits it.
	 *
	 * FAIL-SAFE: a 0/unset option means "use the default", NOT "unlimited" — a
	 * missing config must NEVER silently disable the whale-manipulation dampener.
	 *
	 * @return string Cap as a whole-DGEN decimal string.
	 */
	public static function position_cap_dgen() : string {
		$opt = (int) get_option( 'gs_collab_position_cap_dgen', 0 );
		if ( $opt > 0 ) {
			return (string) $opt;
		}
		if ( defined( 'GS_COLLAB_POSITION_CAP_DGEN' ) && (int) GS_COLLAB_POSITION_CAP_DGEN > 0 ) {
			return (string) (int) GS_COLLAB_POSITION_CAP_DGEN;
		}
		return '10000'; // finite fail-safe default (10000 DGEN = 10000 CAD) — dampens whale manipulation.
	}

	/**
	 * Idempotent swipe write (SWIPE-03 + SWIPE-04). INSERT IGNORE against the
	 * UNIQUE(from_group_id,to_group_id) constraint so a re-swipe / double-tap
	 * on an existing (from,to) pair is a no-op — never a new row, never flips
	 * the recorded decision. Returns true on success OR no-op (an already-
	 * decided pair is still "recorded"), false only on a genuine DB error.
	 *
	 * @param int    $from     Acting group id (swiping on its own behalf).
	 * @param int    $to       Candidate group id being decided on.
	 * @param string $decision 'right' (interested) or 'left' (pass).
	 * @param int    $actor    User id of the admin/mod who swiped.
	 * @return bool
	 */
	public static function record_swipe( int $from, int $to, string $decision, int $actor ) : bool {
		global $wpdb;

		$decision = ( 'right' === $decision ) ? 'right' : 'left';
		$table    = self::swipes_table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(from_group_id, to_group_id, decision, actor_user_id, created_at)
				 VALUES (%d, %d, %s, %d, %d)",
				$from,
				$to,
				$decision,
				$actor,
				time()
			)
		);

		// $wpdb->query returns int affected-rows on success (0 = ignored dupe,
		// which is a valid no-op) or false on error. Only false is a failure.
		return ( false !== $result );
	}
}
