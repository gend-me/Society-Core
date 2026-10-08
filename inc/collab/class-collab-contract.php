<?php
/**
 * gend-society — GenD Match collaboration-contract escalation engine (Phase 84-01, v12.0).
 *
 * COLLAB-01/02: turns a Phase-83 gs_collab_matches row into a REAL, chain-anchored
 * Gend_CP_Task_Contract with DGEN escrow, attached to the SAME Phase-83 BP intro
 * thread — driven ENTIRELY from gend-society by REPLAYING the proven public
 * create+escrow sequence (projects/includes/pm/class-pm-contracts.php:759-775). NO
 * code change to contracts-and-payments or projects: every cross-plugin call is
 * class_exists/method_exists-guarded so a partial deploy degrades to a clean
 * WP_Error instead of fataling + auto-deactivating gend-society (memory:
 * project_wp_fatal_auto_deactivation).
 *
 * Two-party handshake (pure static, NO hooks — the REST class calls these):
 *   propose()  — an admin/mod of a matched group stores a PENDING proposal
 *                (milestones + escrow_model + amounts + which side funds). NO
 *                contract, NO project, NO DGEN moves. UPSERT keyed UNIQUE(match_id)
 *                so a re-propose/amend clears the row back to prop_status='pending'.
 *   accept()   — the COUNTERPARTY group's admin/mod accepts (auth enforced in the
 *                REST permission_callback). ONE-SIDED path replays the create+escrow
 *                sequence: ensure project (payer group) -> create task (milestones as
 *                description) -> Gend_CP_Task_Contract::escrow (payer's DGEN, once) ->
 *                save_contract -> write the wp_pm_meta 'pm_chat_thread' linkage
 *                (intro_thread_id -> new project_id) -> flip the match to
 *                'contracted' + store contract_task_id (WHERE contract_task_id IS NULL
 *                for race-safety) -> mark proposal accepted. Insufficient DGEN aborts
 *                cleanly (delete the just-created task, return WP_Error, no partial
 *                state). BOTH-SIDED (Plan 84-02) dispatches to accept_both_sided():
 *                TWO linked one-sided escrows with ALL-OR-NOTHING rollback — if side B
 *                fails, side A's escrow is REVERSED (reverse_escrow) so no half-funded
 *                state can exist; the match only flips 'contracted' when BOTH funded.
 *   decline()  — either side declines ('declined') / the proposer cancels
 *                ('cancelled') a pending proposal. No contract, no funds.
 *
 * Hub-only (is_main_node()): DGEN/chain/Gend_CP_Task_Contract are hub-only; a
 * container call to accept() 404s. Tier A / PUBLIC — NOT gated behind
 * GS_COLLAB_MARKET_PUBLIC (that flag is the Phase-85+ market; this is a normal
 * commercial escrow). DGEN 1:1 CAD peg + chain anchor come free from escrow().
 *
 * Idempotency (belt-and-suspenders): accept() reloads the match; if
 * contract_task_id is already set it returns the existing result as a success
 * no-op (no second contract / no double-escrow). escrow() self-guards
 * _contract_dgen_escrowed; the flip UPDATE guards `contract_task_id IS NULL`; the
 * thread-linkage INSERT is SELECT-exists guarded — a racing double-accept is a no-op.
 *
 * Robustness spine (mirror class-collab-match.php): the CONTRACT is the source of
 * truth. A thread-linkage failure is error_log'd and swallowed — it NEVER rolls
 * back the contract.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Contract {

	/**
	 * Hub gate: true when the runtime mode is hub (gend_society_is_hub(), from
	 * GEND_SOCIETY_RUNTIME), false on containers and standalone installs.
	 * Before 1.1.6 this inferred "hub" from a missing Gend_CP_OAuth_Resource
	 * class, which made every standalone install look like the hub.
	 *
	 * @return bool
	 */
	private static function is_hub() : bool {
		return gend_society_is_hub();
	}

	/**
	 * Load a match row by id, or null.
	 *
	 * @param int $match_id Match id.
	 * @return object|null
	 */
	private static function get_match( int $match_id ) {
		global $wpdb;
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) || $match_id <= 0 ) {
			return null;
		}
		$matches = Gend_GS_Collab_Schema::matches_table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, group_a, group_b, status, intro_thread_id, contract_task_id
			   FROM {$matches} WHERE id = %d LIMIT 1",
			$match_id
		) );
	}

	/**
	 * Load the LIVE (pending) proposal for a match, or null.
	 *
	 * @param int $match_id Match id.
	 * @return object|null
	 */
	private static function get_pending_proposal( int $match_id ) {
		global $wpdb;
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) || $match_id <= 0 ) {
			return null;
		}
		$proposals = Gend_GS_Collab_Schema::proposals_table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$proposals} WHERE match_id = %d AND prop_status = 'pending' LIMIT 1",
			$match_id
		) );
	}

	/**
	 * (B) PROPOSE — store a PENDING proposal. No contract, no project, no escrow.
	 *
	 * Validates the match exists and that proposer_group is one of its two groups;
	 * resolves payer/payee/escrow_model/credits/milestones from $args. UPSERTs into
	 * gs_collab_proposals keyed UNIQUE(match_id): a fresh match -> INSERT; an existing
	 * row (any prior status) -> UPDATE back to prop_status='pending' (re-propose/amend).
	 *
	 * @param int   $match_id       The match being escalated.
	 * @param int   $proposer_group The group the proposer acts for (must be a matched group).
	 * @param array $args           payer_group, payee_group, escrow_model, credits, milestones.
	 * @return array|WP_Error The stored proposal, or WP_Error.
	 */
	public static function propose( int $match_id, int $proposer_group, array $args ) {
		global $wpdb;

		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return new WP_Error( 'gs_collab_no_schema', 'collab schema unavailable', array( 'status' => 500 ) );
		}

		$match = self::get_match( $match_id );
		if ( ! $match ) {
			return new WP_Error( 'gs_collab_no_match', 'match not found', array( 'status' => 404 ) );
		}

		$group_a = (int) $match->group_a;
		$group_b = (int) $match->group_b;
		if ( $proposer_group !== $group_a && $proposer_group !== $group_b ) {
			return new WP_Error( 'gs_collab_not_matched_group', 'proposer_group is not part of this match', array( 'status' => 403 ) );
		}

		// Already escalated? A present contract_task_id = the match is contracted;
		// re-proposing on top is a 409.
		if ( ! empty( $match->contract_task_id ) ) {
			return new WP_Error( 'gs_collab_already_contracted', 'this match already has a contract', array( 'status' => 409 ) );
		}

		// Resolve the escrow model. both_sided is STORED but hard-rejected on accept
		// until Plan 84-02; storing it here keeps the field honest.
		$escrow_model = ( isset( $args['escrow_model'] ) && 'both_sided' === $args['escrow_model'] )
			? 'both_sided' : 'one_sided';

		// The other matched group is the natural counterparty default.
		$other_group = ( $proposer_group === $group_a ) ? $group_b : $group_a;

		// payer/payee: default payer = proposer_group, payee = the other group.
		// Callers may override, but both MUST be the two matched groups and distinct.
		$payer_group = isset( $args['payer_group'] ) ? (int) $args['payer_group'] : $proposer_group;
		$payee_group = isset( $args['payee_group'] ) ? (int) $args['payee_group'] : $other_group;
		if ( $payer_group <= 0 ) {
			$payer_group = $proposer_group;
		}
		if ( $payee_group <= 0 ) {
			$payee_group = $other_group;
		}
		$valid = array( $group_a, $group_b );
		if ( ! in_array( $payer_group, $valid, true ) || ! in_array( $payee_group, $valid, true ) || $payer_group === $payee_group ) {
			return new WP_Error( 'gs_collab_bad_parties', 'payer and payee must be the two distinct matched groups', array( 'status' => 400 ) );
		}

		$credits = isset( $args['credits'] ) ? max( 0, (int) $args['credits'] ) : 0;

		// Milestones: accept an array (json-encode it) or a JSON string (store as-is
		// after a decode/re-encode round-trip to normalize + reject garbage).
		$milestones_json = self::normalize_milestones( isset( $args['milestones'] ) ? $args['milestones'] : null );

		$proposer_uid = (int) get_current_user_id();
		$proposals    = Gend_GS_Collab_Schema::proposals_table();

		$data = array(
			'match_id'        => $match_id,
			'proposer_group'  => $proposer_group,
			'payer_group'     => $payer_group,
			'payee_group'     => $payee_group,
			'escrow_model'    => $escrow_model,
			'credits'         => $credits,
			'milestones_json' => $milestones_json,
			'prop_status'     => 'pending',
			'proposer_uid'    => $proposer_uid,
			'created_at'      => time(),
		);
		$formats = array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d' );

		// UPSERT keyed UNIQUE(match_id): if a row exists (any status), amend it back
		// to pending; else INSERT a fresh one.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$proposals} WHERE match_id = %d LIMIT 1", $match_id ) );

		if ( $existing ) {
			$upd = $data;
			unset( $upd['match_id'] ); // don't rewrite the unique key
			$ok = $wpdb->update(
				$proposals,
				$upd,
				array( 'match_id' => $match_id ),
				array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				return new WP_Error( 'gs_collab_propose_failed', 'could not store proposal', array( 'status' => 500 ) );
			}
			$proposal_id = (int) $existing;
		} else {
			$ok = $wpdb->insert( $proposals, $data, $formats );
			if ( false === $ok ) {
				return new WP_Error( 'gs_collab_propose_failed', 'could not store proposal', array( 'status' => 500 ) );
			}
			$proposal_id = (int) $wpdb->insert_id;
		}

		return array(
			'ok'             => true,
			'proposal_id'    => $proposal_id,
			'match_id'       => $match_id,
			'proposer_group' => $proposer_group,
			'payer_group'    => $payer_group,
			'payee_group'    => $payee_group,
			'escrow_model'   => $escrow_model,
			'credits'        => $credits,
			'prop_status'    => 'pending',
			'milestones'     => json_decode( (string) $milestones_json, true ),
		);
	}

	/**
	 * (C) ACCEPT — the escalation. The COUNTERPARTY group's admin/mod accepts a
	 * pending proposal (auth is enforced in the REST permission_callback). On the
	 * one-sided path this REPLAYS the proven create+escrow sequence, links the intro
	 * thread, and flips the match to 'contracted'.
	 *
	 * @param int $match_id      The match being escalated.
	 * @param int $acceptor_group The counterparty group the acceptor represents.
	 * @param int $acceptor_uid   The accepting user id.
	 * @return array|WP_Error
	 */
	public static function accept( int $match_id, int $acceptor_group, int $acceptor_uid ) {
		global $wpdb;

		// 1. Hub gate — money lives on the hub.
		if ( ! self::is_hub() ) {
			return new WP_Error( 'gs_collab_hub_only', 'escalation is hub-only', array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return new WP_Error( 'gs_collab_no_schema', 'collab schema unavailable', array( 'status' => 500 ) );
		}

		$match = self::get_match( $match_id );
		if ( ! $match ) {
			return new WP_Error( 'gs_collab_no_match', 'match not found', array( 'status' => 404 ) );
		}

		// 3. IDEMPOTENCY: already escalated? Return the existing result as a no-op
		//    success (no second contract / no double-escrow).
		if ( ! empty( $match->contract_task_id ) ) {
			return array(
				'ok'               => true,
				'already'          => true,
				'contract_task_id' => (int) $match->contract_task_id,
				'intro_thread_id'  => (int) $match->intro_thread_id,
			);
		}

		// 2. Load the pending proposal.
		$proposal = self::get_pending_proposal( $match_id );
		if ( ! $proposal ) {
			return new WP_Error( 'gs_collab_no_proposal', 'no pending proposal to accept', array( 'status' => 404 ) );
		}

		// 4. both_sided -> TWO linked escrows + all-or-nothing rollback (Plan 84-02).
		//    Replaces the 84-01 "coming soon" 400 stub — dispatch is now unconditional.
		if ( 'both_sided' === (string) $proposal->escrow_model ) {
			return self::accept_both_sided( $match, $proposal, $acceptor_uid );
		}

		// 5. ONE-SIDED path — replay the proven create+escrow sequence.
		//    All cross-plugin calls guarded: a partial deploy degrades to a clean
		//    WP_Error rather than fataling + auto-deactivating gend-society.
		if ( ! class_exists( 'PSOO_PM_Projects' ) || ! class_exists( 'PSOO_PM_Tasks' ) || ! class_exists( 'PSOO_PM_Contracts' ) ) {
			return new WP_Error( 'gs_collab_pm_unavailable', 'projects plugin unavailable on this node', array( 'status' => 500 ) );
		}

		$payer_group = (int) $proposal->payer_group;
		$payee_group = (int) $proposal->payee_group;
		$credits     = (int) $proposal->credits;

		// The PAYER funds — payer_user = first admin of the payer group (the DGEN is
		// debited from this user's `transact` balance). Fall back to the acceptor.
		$payer_user = self::first_group_admin( $payer_group );
		if ( $payer_user <= 0 ) {
			$payer_user = $acceptor_uid;
		}

		$name_a         = self::group_name( (int) $match->group_a );
		$name_b         = self::group_name( (int) $match->group_b );
		$milestones     = json_decode( (string) $proposal->milestones_json, true );
		$milestones_html = self::milestones_html( $milestones, $name_a, $name_b );

		// 5a. Ensure a project for the FUNDING (payer) group.
		$existing_proj = PSOO_PM_Projects::get_for_group( $payer_group );
		if ( ! empty( $existing_proj ) && isset( $existing_proj[0]->id ) ) {
			$project_id      = (int) $existing_proj[0]->id;
			$created_project = false;
		} else {
			$project_id = PSOO_PM_Projects::create( array(
				'title'    => sprintf( '%s x %s collaboration', $name_a, $name_b ),
				'group_id' => $payer_group,
			) );
			if ( is_wp_error( $project_id ) || (int) $project_id <= 0 ) {
				return is_wp_error( $project_id )
					? $project_id
					: new WP_Error( 'gs_collab_project_failed', 'could not create collaboration project', array( 'status' => 500 ) );
			}
			$project_id      = (int) $project_id;
			$created_project = true;
		}

		// 5b. Create the collaboration TASK (milestones as its description).
		$task_id = PSOO_PM_Tasks::create( array(
			'title'       => 'Collaboration deliverable',
			'project_id'  => $project_id,
			'description' => $milestones_html,
		) );
		if ( is_wp_error( $task_id ) || (int) $task_id <= 0 ) {
			return is_wp_error( $task_id )
				? $task_id
				: new WP_Error( 'gs_collab_task_failed', 'could not create collaboration task', array( 'status' => 500 ) );
		}
		$task_id = (int) $task_id;

		// 5c. ESCROW the payer's DGEN (credits × rate), once. Insufficient -> WP_Error
		//     -> CLEAN ABORT: delete the just-created task (no partial state, no DGEN
		//     moved, no contract). We do NOT delete a pre-existing shared project.
		if ( $credits > 0 && class_exists( 'Gend_CP_Task_Contract' ) && method_exists( 'Gend_CP_Task_Contract', 'escrow' ) ) {
			$escrow = Gend_CP_Task_Contract::escrow( $task_id, $project_id, $credits, $payer_user );
			if ( is_wp_error( $escrow ) ) {
				self::delete_task( $task_id );
				return $escrow; // clean abort — nothing moved, nothing linked, match untouched.
			}
		}

		// 5d. Persist the contract metadata.
		if ( method_exists( 'PSOO_PM_Contracts', 'save_contract' ) ) {
			PSOO_PM_Contracts::save_contract( $task_id, $project_id, array(
				'brief'      => $milestones_html,
				'credits'    => $credits,
				'status'     => 'open',
				'offered_by' => $payer_user,
			) );
		}

		// 6. Write the wp_pm_meta 'pm_chat_thread' linkage (intro thread -> project),
		//    idempotently. Robustness: a failure here is logged + swallowed — it NEVER
		//    rolls back the contract (the contract is the source of truth).
		$intro_thread_id = (int) $match->intro_thread_id;
		if ( $intro_thread_id > 0 ) {
			self::link_thread_to_project( $intro_thread_id, $project_id );
		}

		// 7. Flip the match: 'contracted' + store contract_task_id, ONLY WHERE
		//    contract_task_id IS NULL — a racing double-accept becomes a no-op.
		$matches = Gend_GS_Collab_Schema::matches_table();
		$flipped = $wpdb->query( $wpdb->prepare(
			"UPDATE {$matches} SET status = 'contracted', contract_task_id = %d
			  WHERE id = %d AND contract_task_id IS NULL",
			$task_id,
			$match_id
		) );

		// If we LOST the flip race (0 rows), another accept already contracted this
		// match. escrow() self-guarded against a double-debit; return the winner's id.
		if ( 0 === (int) $flipped ) {
			$fresh = self::get_match( $match_id );
			if ( $fresh && ! empty( $fresh->contract_task_id ) ) {
				// GenD Match (Phase 86-04, MARKET-01): fire the contracted signal even on
				// the already-contracted RACE branch — Gend_GS_Collab_Market::on_contracted
				// (gated on GS_COLLAB_MARKET_PUBLIC) auto-creates the market and create_market
				// is idempotent via UNIQUE(match_id), so a double-fire collapses to one market.
				// Mirrors the do_action('gend_gs_collab_escrow_reversed',...) style below.
				do_action( 'gend_gs_collab_contracted', (int) $match_id, (int) $fresh->contract_task_id );
				return array(
					'ok'               => true,
					'already'          => true,
					'contract_task_id' => (int) $fresh->contract_task_id,
					'project_id'       => $project_id,
					'intro_thread_id'  => $intro_thread_id,
				);
			}
		}

		// 8. Mark the proposal accepted.
		$proposals = Gend_GS_Collab_Schema::proposals_table();
		$wpdb->update(
			$proposals,
			array( 'prop_status' => 'accepted' ),
			array( 'match_id' => $match_id ),
			array( '%s' ),
			array( '%d' )
		);

		// GenD Match (Phase 86-04, MARKET-01): the match just flipped to 'contracted' —
		// fire the auto-create signal. Gend_GS_Collab_Market::on_contracted (gated on
		// GS_COLLAB_MARKET_PUBLIC) creates exactly one market; idempotent UNIQUE(match_id).
		do_action( 'gend_gs_collab_contracted', (int) $match_id, (int) $task_id );

		// 9. Result.
		return array(
			'ok'               => true,
			'contract_task_id' => $task_id,
			'project_id'       => $project_id,
			'intro_thread_id'  => $intro_thread_id,
		);
	}

	/**
	 * (C-both) ACCEPT_BOTH_SIDED — the commitment-bond escalation, realized as TWO
	 * linked one-sided Gend_CP_Task_Contract::escrow calls with ALL-OR-NOTHING rollback.
	 *
	 * escrow side A (group_a's admin funds task A), then side B (group_b's admin funds
	 * task B). Each side escrows an EQUAL commitment bond of `credits`. If side B fails
	 * (insufficient DGEN / any error, INCLUDING being unable to create side B's task/
	 * project), side A is REVERSED via reverse_escrow() — DGEN returned to side A's payer,
	 * chain-anchored — so NO half-funded escrow can ever exist. On a failed both-sided
	 * accept: BOTH sides end unfunded, NO contract is created/left, the match stays
	 * 'matched', and DGEN is made whole. Only when BOTH escrows succeed is the match
	 * flipped to 'contracted', the thread linked, and both task ids recorded.
	 *
	 * Called (only) from accept() when the stored escrow_model is 'both_sided'. accept()
	 * has already run the hub gate, the contract_task_id-IS-NULL idempotency short-circuit,
	 * and loaded the pending proposal — so this is invoked exactly once per live proposal.
	 *
	 * Signature mirrors the 84-01 dispatch: accept() passes the loaded ($match, $proposal)
	 * rows plus the accepting user id (context / fallback payer).
	 *
	 * @param object $match      The loaded match row (id, group_a, group_b, intro_thread_id, ...).
	 * @param object $proposal   The loaded pending proposal row (credits, escrow_model, ...).
	 * @param int    $acceptor_uid The accepting user id (counterparty group admin/mod).
	 * @return array|WP_Error
	 */
	public static function accept_both_sided( $match, $proposal, int $acceptor_uid ) {
		global $wpdb;

		if ( ! is_object( $match ) || ! is_object( $proposal ) ) {
			return new WP_Error( 'gs_collab_bad_state', 'invalid match/proposal state', array( 'status' => 500 ) );
		}

		// Cross-plugin availability — a partial deploy degrades to a clean WP_Error
		// (never a fatal that would auto-deactivate gend-society). NO DGEN has moved yet.
		if ( ! class_exists( 'PSOO_PM_Projects' ) || ! class_exists( 'PSOO_PM_Tasks' ) || ! class_exists( 'PSOO_PM_Contracts' ) ) {
			return new WP_Error( 'gs_collab_pm_unavailable', 'projects plugin unavailable on this node', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'Gend_CP_Task_Contract' ) || ! method_exists( 'Gend_CP_Task_Contract', 'escrow' ) ) {
			return new WP_Error( 'gs_collab_escrow_unavailable', 'contract escrow primitive unavailable on this node', array( 'status' => 500 ) );
		}

		$match_id = (int) $match->id;
		$group_a  = (int) $match->group_a;
		$group_b  = (int) $match->group_b;
		$credits  = (int) $proposal->credits;

		$name_a          = self::group_name( $group_a );
		$name_b          = self::group_name( $group_b );
		$milestones      = json_decode( (string) $proposal->milestones_json, true );
		$milestones_html = self::milestones_html( $milestones, $name_a, $name_b );

		// Each side's payer = first admin of that group; fall back to the acceptor if a
		// group has no resolvable admin (defensive — the bond still lands on a real user).
		$payer_a = self::first_group_admin( $group_a );
		if ( $payer_a <= 0 ) {
			$payer_a = $acceptor_uid;
		}
		$payer_b = self::first_group_admin( $group_b );
		if ( $payer_b <= 0 ) {
			$payer_b = $acceptor_uid;
		}

		// ---- SIDE A: project + task + escrow ---------------------------------------
		$proj_a = self::ensure_group_project( $group_a, $name_a, $name_b );
		if ( is_wp_error( $proj_a ) ) {
			return $proj_a; // nothing funded — clean abort.
		}
		$project_a = (int) $proj_a;

		$task_a = PSOO_PM_Tasks::create( array(
			'title'       => sprintf( 'Collaboration bond — %s', $name_a ),
			'project_id'  => $project_a,
			'description' => $milestones_html,
		) );
		if ( is_wp_error( $task_a ) || (int) $task_a <= 0 ) {
			return is_wp_error( $task_a )
				? $task_a
				: new WP_Error( 'gs_collab_task_failed', 'could not create side-A collaboration task', array( 'status' => 500 ) );
		}
		$task_a = (int) $task_a;

		$escrow_a = Gend_CP_Task_Contract::escrow( $task_a, $project_a, $credits, $payer_a );
		if ( is_wp_error( $escrow_a ) ) {
			// Side A never funded — no rollback needed; just delete the draft task.
			self::delete_task( $task_a );
			return $escrow_a; // clean abort — nothing funded, match untouched.
		}

		// ---- SIDE B: project + task + escrow (ALL-OR-NOTHING from here) -------------
		// Any failure below MUST reverse side A's escrow so no half-funded state exists.
		$proj_b = self::ensure_group_project( $group_b, $name_a, $name_b );
		if ( is_wp_error( $proj_b ) ) {
			return self::rollback_side_a( $task_a, $project_a, $payer_a, $proj_b,
				'side B project creation failed' );
		}
		$project_b = (int) $proj_b;

		$task_b = PSOO_PM_Tasks::create( array(
			'title'       => sprintf( 'Collaboration bond — %s', $name_b ),
			'project_id'  => $project_b,
			'description' => $milestones_html,
		) );
		if ( is_wp_error( $task_b ) || (int) $task_b <= 0 ) {
			$err = is_wp_error( $task_b )
				? $task_b
				: new WP_Error( 'gs_collab_task_failed', 'could not create side-B collaboration task', array( 'status' => 500 ) );
			return self::rollback_side_a( $task_a, $project_a, $payer_a, $err,
				'side B task creation failed' );
		}
		$task_b = (int) $task_b;

		$escrow_b = Gend_CP_Task_Contract::escrow( $task_b, $project_b, $credits, $payer_b );
		if ( is_wp_error( $escrow_b ) ) {
			// THE first-class rollback path: side B's escrow failed (e.g. insufficient
			// DGEN) — reverse side A, delete side B's draft task, leave the match unflipped.
			self::delete_task( $task_b );
			return self::rollback_side_a( $task_a, $project_a, $payer_a, $escrow_b,
				'side B escrow failed (insufficient DGEN or error)' );
		}

		// ---- BOTH SIDES FUNDED: commit the contract ---------------------------------
		if ( method_exists( 'PSOO_PM_Contracts', 'save_contract' ) ) {
			PSOO_PM_Contracts::save_contract( $task_a, $project_a, array(
				'brief'      => $milestones_html,
				'credits'    => $credits,
				'status'     => 'open',
				'offered_by' => $payer_a,
			) );
			PSOO_PM_Contracts::save_contract( $task_b, $project_b, array(
				'brief'      => $milestones_html,
				'credits'    => $credits,
				'status'     => 'open',
				'offered_by' => $payer_b,
			) );
		}

		// Link the intro thread ONCE, to the primary (side A) project. Logged + swallowed.
		$intro_thread_id = (int) $match->intro_thread_id;
		if ( $intro_thread_id > 0 ) {
			self::link_thread_to_project( $intro_thread_id, $project_a );
		}

		// Flip the match — race-safe WHERE contract_task_id IS NULL. contract_task_id =
		// side A's task; side B's task id is stored as match meta (planner's discretion —
		// gs_collab_matches has no second column and we avoid a DDL change for one field).
		update_option( 'gs_collab_match_' . $match_id . '_task_b', $task_b, false );

		$matches = Gend_GS_Collab_Schema::matches_table();
		$flipped = $wpdb->query( $wpdb->prepare(
			"UPDATE {$matches} SET status = 'contracted', contract_task_id = %d
			  WHERE id = %d AND contract_task_id IS NULL",
			$task_a,
			$match_id
		) );

		// Lost the flip race -> a concurrent accept already contracted this match. Both
		// escrow() calls self-guarded _contract_dgen_escrowed so no double-debit occurred;
		// return the winner's id.
		if ( 0 === (int) $flipped ) {
			$fresh = self::get_match( $match_id );
			if ( $fresh && ! empty( $fresh->contract_task_id ) ) {
				// GenD Match (Phase 86-04, MARKET-01): fire on the already-contracted RACE
				// branch too — create_market is idempotent (UNIQUE(match_id)).
				do_action( 'gend_gs_collab_contracted', (int) $match_id, (int) $fresh->contract_task_id );
				return array(
					'ok'                 => true,
					'already'            => true,
					'escrow_model'       => 'both_sided',
					'contract_task_id'   => (int) $fresh->contract_task_id,
					'contract_task_id_b' => $task_b,
					'project_id'         => $project_a,
					'intro_thread_id'    => $intro_thread_id,
				);
			}
		}

		// Mark the proposal accepted.
		$proposals = Gend_GS_Collab_Schema::proposals_table();
		$wpdb->update(
			$proposals,
			array( 'prop_status' => 'accepted' ),
			array( 'match_id' => $match_id ),
			array( '%s' ),
			array( '%d' )
		);

		// GenD Match (Phase 86-04, MARKET-01): the both-sided contract just flipped to
		// 'contracted' — fire the auto-create signal (contract_task_id = side A's task).
		// on_contracted (gated on GS_COLLAB_MARKET_PUBLIC) makes exactly one idempotent market.
		do_action( 'gend_gs_collab_contracted', (int) $match_id, (int) $task_a );

		return array(
			'ok'                 => true,
			'escrow_model'       => 'both_sided',
			'contract_task_id'   => $task_a,
			'contract_task_id_b' => $task_b,
			'project_id'         => $project_a,
			'project_id_b'       => $project_b,
			'intro_thread_id'    => $intro_thread_id,
		);
	}

	/**
	 * Ensure a project exists for a group (reuse the group's project or create one).
	 * Shared by both sides of accept_both_sided.
	 *
	 * @param int    $gid    Group id.
	 * @param string $name_a Group A display name (for the project title).
	 * @param string $name_b Group B display name.
	 * @return int|WP_Error Project id, or WP_Error.
	 */
	private static function ensure_group_project( int $gid, string $name_a, string $name_b ) {
		$existing = PSOO_PM_Projects::get_for_group( $gid );
		if ( ! empty( $existing ) && isset( $existing[0]->id ) ) {
			return (int) $existing[0]->id;
		}
		$project_id = PSOO_PM_Projects::create( array(
			'title'    => sprintf( '%s x %s collaboration', $name_a, $name_b ),
			'group_id' => $gid,
		) );
		if ( is_wp_error( $project_id ) || (int) $project_id <= 0 ) {
			return is_wp_error( $project_id )
				? $project_id
				: new WP_Error( 'gs_collab_project_failed', 'could not create collaboration project', array( 'status' => 500 ) );
		}
		return (int) $project_id;
	}

	/**
	 * ALL-OR-NOTHING rollback of side A: reverse side A's escrow (DGEN back to payer A,
	 * chain-anchored), delete side A's draft task, and return a WP_Error that makes clear
	 * side A was rolled back due to a side-B failure. NO match flip, NO thread linkage,
	 * NO proposal-accepted write — the match stays 'matched', DGEN is whole.
	 *
	 * @param int      $task_a    Side A task id (its escrow is reversed).
	 * @param int      $project_a Side A project id.
	 * @param int      $payer_a   Side A payer (gets DGEN back).
	 * @param WP_Error $cause     The side-B failure that triggered the rollback.
	 * @param string   $reason    Human-readable rollback reason (for the error + log).
	 * @return WP_Error
	 */
	private static function rollback_side_a( int $task_a, int $project_a, int $payer_a, $cause, string $reason ) {
		$reversed = self::reverse_escrow( $task_a, $project_a, $payer_a );
		if ( is_wp_error( $reversed ) ) {
			// The reversal itself could not complete (e.g. myCRED vanished) — this is a
			// genuinely stuck money state; surface it loudly, do NOT swallow.
			error_log( '[gs_collab_contract] accept_both_sided: side-A rollback FAILED for task ' . $task_a . ' (' . $reason . '): ' . $reversed->get_error_message() );
			return new WP_Error(
				'gs_collab_rollback_failed',
				sprintf( 'Both-sided accept failed (%s) AND side-A escrow reversal could not complete — manual review required.', $reason ),
				array( 'status' => 500 )
			);
		}

		self::delete_task( $task_a );

		$cause_msg  = is_wp_error( $cause ) ? $cause->get_error_message() : (string) $cause;
		$cause_code = is_wp_error( $cause ) ? $cause->get_error_code() : 'gs_collab_both_sided_failed';
		$cause_data = is_wp_error( $cause ) ? (array) $cause->get_error_data() : array();
		$status     = isset( $cause_data['status'] ) ? (int) $cause_data['status'] : 400;

		error_log( '[gs_collab_contract] accept_both_sided: rolled back side A (task ' . $task_a . ') — ' . $reason . ': ' . $cause_msg );

		return new WP_Error(
			$cause_code,
			sprintf( 'Both-sided escrow aborted (%s). Side A was rolled back — no contract created, DGEN returned.', $reason ),
			array( 'status' => $status )
		);
	}

	/**
	 * (D) DECLINE / CANCEL — set the pending proposal to 'declined' (either side) or
	 * 'cancelled' (proposer withdrew). No contract, no funds.
	 *
	 * @param int    $match_id Match id.
	 * @param string $mode     'declined' | 'cancelled'.
	 * @return array|WP_Error
	 */
	public static function decline( int $match_id, string $mode = 'declined' ) {
		global $wpdb;

		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return new WP_Error( 'gs_collab_no_schema', 'collab schema unavailable', array( 'status' => 500 ) );
		}
		$mode = ( 'cancelled' === $mode ) ? 'cancelled' : 'declined';

		$proposal = self::get_pending_proposal( $match_id );
		if ( ! $proposal ) {
			return new WP_Error( 'gs_collab_no_proposal', 'no pending proposal to decline', array( 'status' => 404 ) );
		}

		$proposals = Gend_GS_Collab_Schema::proposals_table();
		$ok = $wpdb->update(
			$proposals,
			array( 'prop_status' => $mode ),
			array( 'match_id' => $match_id, 'prop_status' => 'pending' ),
			array( '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'gs_collab_decline_failed', 'could not decline proposal', array( 'status' => 500 ) );
		}

		return array( 'ok' => true, 'match_id' => $match_id, 'prop_status' => $mode );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Write the wp_pm_meta 'pm_chat_thread' linkage row (intro thread -> project),
	 * idempotently (SELECT-exists guard then INSERT). Matches the shape the reader
	 * em_chat_thread_project_id() expects (email-manager/inc/chat-widget.php:106-124).
	 * Phase 84 is the FIRST writer of this linkage. Failure is logged + swallowed.
	 *
	 * @param int $intro_thread_id BP messages thread id.
	 * @param int $project_id      The collaboration project just created.
	 * @return void
	 */
	private static function link_thread_to_project( int $intro_thread_id, int $project_id ) : void {
		global $wpdb;

		if ( $intro_thread_id <= 0 || $project_id <= 0 ) {
			return;
		}

		try {
			$table = $wpdb->prefix . 'pm_meta';

			// Idempotent: if a linkage row for this thread already exists, no-op.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table}
				  WHERE entity_type = 'pm_chat_thread' AND meta_key = 'thread_id' AND meta_value = %s LIMIT 1",
				(string) $intro_thread_id
			) );
			if ( $exists ) {
				return;
			}

			$now = current_time( 'mysql' );
			$wpdb->insert( $table, array(
				'entity_id'   => (int) $intro_thread_id,
				'entity_type' => 'pm_chat_thread',
				'meta_key'    => 'thread_id',
				'meta_value'  => (string) $intro_thread_id, // reader matches on meta_value
				'project_id'  => (int) $project_id,
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
		} catch ( \Throwable $e ) {
			// Contract is the source of truth — NEVER roll back on a linkage failure.
			error_log( '[gs_collab_contract] thread linkage failed for thread ' . $intro_thread_id . ' -> project ' . $project_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Delete a just-created collaboration task on a clean abort. Best-effort +
	 * guarded — a failure to delete is logged, never thrown.
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	private static function delete_task( int $task_id ) : void {
		if ( $task_id <= 0 ) {
			return;
		}
		try {
			if ( class_exists( 'PSOO_PM_Tasks' ) && method_exists( 'PSOO_PM_Tasks', 'delete' ) ) {
				PSOO_PM_Tasks::delete( $task_id );
				return;
			}
			// Fallback: null out the row directly (defensive — keeps no orphan draft).
			global $wpdb;
			$wpdb->delete( $wpdb->prefix . 'pm_tasks', array( 'id' => $task_id ), array( '%d' ) );
		} catch ( \Throwable $e ) {
			error_log( '[gs_collab_contract] task rollback failed for task ' . $task_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * First admin user id of a group (the DGEN-funding payer user). 0 if none.
	 *
	 * @param int $gid Group id.
	 * @return int
	 */
	private static function first_group_admin( int $gid ) : int {
		if ( $gid <= 0 || ! function_exists( 'groups_get_group_admins' ) ) {
			return 0;
		}
		$admins = groups_get_group_admins( $gid );
		if ( is_array( $admins ) ) {
			foreach ( $admins as $a ) {
				if ( is_object( $a ) && ! empty( $a->user_id ) ) {
					return (int) $a->user_id;
				}
			}
		}
		return 0;
	}

	/**
	 * Human-readable group name, guarded. Falls back to "Group #<id>".
	 *
	 * @param int $gid Group id.
	 * @return string
	 */
	private static function group_name( int $gid ) : string {
		if ( function_exists( 'groups_get_group' ) ) {
			$g = groups_get_group( $gid );
			if ( is_object( $g ) && ! empty( $g->name ) ) {
				return (string) $g->name;
			}
		}
		return 'Group #' . $gid;
	}

	/**
	 * Normalize the incoming milestones into a JSON string for storage. Accepts an
	 * array (encode) or a JSON string (decode/re-encode to validate + normalize).
	 * Anything unparseable becomes an empty JSON array.
	 *
	 * @param mixed $milestones Array or JSON string.
	 * @return string JSON string.
	 */
	private static function normalize_milestones( $milestones ) : string {
		if ( is_array( $milestones ) ) {
			return (string) wp_json_encode( array_values( $milestones ) );
		}
		if ( is_string( $milestones ) && '' !== trim( $milestones ) ) {
			$decoded = json_decode( $milestones, true );
			if ( is_array( $decoded ) ) {
				return (string) wp_json_encode( array_values( $decoded ) );
			}
			// A plain string milestone brief — wrap as a single-item list.
			return (string) wp_json_encode( array( sanitize_text_field( $milestones ) ) );
		}
		return (string) wp_json_encode( array() );
	}

	/**
	 * DGEN per Task Credit — MIRRORS Gend_CP_Task_Contract::rate()
	 * (contracts-and-payments/includes/class-task-contract.php:44-46). Replicated
	 * (not called) because that method, while public, keys off the SAME option, and
	 * the reversal must compute the identical DGEN figure escrow() moved so the
	 * refund is exact (1:1 CAD peg preserved).
	 *
	 * @return int DGEN per credit (>=1).
	 */
	private static function dgen_rate() : int {
		return max( 1, (int) get_option( 'psoo_contract_exchange_rate', 15 ) );
	}

	/**
	 * Escrow-holder user id — MIRRORS Gend_CP_Task_Contract::escrow_user_id()
	 * (private there; class-task-contract.php:49-54). The wallet that RECEIVED the
	 * escrowed DGEN in escrow(); the reversal debits it. Configured operator, else
	 * first administrator, else 1.
	 *
	 * @return int Escrow-holder user id.
	 */
	private static function escrow_holder_uid() : int {
		$opt = (int) get_option( 'gend_cp_hub_fee_user_id', 0 );
		if ( $opt > 0 ) {
			return $opt;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * REVERSE_ESCROW — the compensating, chain-anchored, idempotent money reversal.
	 *
	 * This is the ONE genuinely-new bit of money code in Phase 84. Gend_CP_Task_Contract
	 * has NO public un-escrow primitive (verified in 84-RESEARCH — the only refund
	 * methods live in the unrelated affiliate-contract class, a different table; DO NOT
	 * call those). So the both-sided ALL-OR-NOTHING rollback (accept_both_sided, below)
	 * needs its OWN compensating move that MIRRORS escrow()'s money-move + chain-anchor
	 * discipline (class-task-contract.php:77-131,185-196), reversed:
	 *
	 *   escrow():  debit payer -$dgen 'transact', credit escrow-holder +$dgen 'transact'
	 *   reverse(): debit escrow-holder -$dgen 'transact', credit payer +$dgen 'transact'
	 *
	 * Idempotency (belt-and-suspenders, like escrow's _contract_dgen_escrowed guard):
	 * a _contract_dgen_reversed='1' meta is set FIRST — BEFORE any DGEN moves — so a
	 * mid-rollback retry can NEVER double-refund. A <=0 escrow reading (never funded /
	 * already reversed) is a clean no-op.
	 *
	 * Undoes ONE side's escrow (the both-sided rollback undoes side A when side B fails),
	 * returning the DGEN to the payer and chain-anchoring a compensating
	 * chain.contract.escrow.reversal tx. Every mycred/PSOO/chain call is
	 * class_exists/function_exists-guarded so a partial deploy degrades cleanly.
	 *
	 * @param int $task_id    The task whose escrow is being reversed.
	 * @param int $project_id The task's project (for meta writes + chain payload).
	 * @param int $payer_user The user whose DGEN was escrowed (gets it back).
	 * @return true|WP_Error true on success or clean no-op; WP_Error only on an
	 *                       unrecoverable state (e.g. myCRED absent while DGEN is held).
	 */
	public static function reverse_escrow( int $task_id, int $project_id, int $payer_user ) {
		$task_id    = (int) $task_id;
		$project_id = (int) $project_id;
		$payer_user = (int) $payer_user;

		if ( $task_id <= 0 ) {
			return true; // nothing to reverse.
		}

		// PSOO meta bridge is how escrow() recorded _contract_dgen_escrowed. Without it
		// we cannot read/zero the escrow meta — but no money can have been moved through
		// a path we can undo either, so treat as a clean no-op.
		if ( ! class_exists( 'PSOO_PM_Contracts' )
			|| ! method_exists( 'PSOO_PM_Contracts', 'get_task_meta' )
			|| ! method_exists( 'PSOO_PM_Contracts', 'set_task_meta' ) ) {
			error_log( '[gs_collab_contract] reverse_escrow: PSOO_PM_Contracts meta bridge unavailable for task ' . $task_id . ' — no-op' );
			return true;
		}

		// 1. Read what was escrowed. <=0 -> never escrowed (or already reversed/zeroed) -> no-op.
		$dgen = (float) PSOO_PM_Contracts::get_task_meta( $task_id, '_contract_dgen_escrowed' );
		if ( $dgen <= 0 ) {
			return true;
		}

		// 2. Idempotency guard — set BEFORE any money moves so a retry can't double-refund.
		if ( '1' === (string) PSOO_PM_Contracts::get_task_meta( $task_id, '_contract_dgen_reversed' ) ) {
			return true; // already reversed.
		}
		PSOO_PM_Contracts::set_task_meta( $task_id, $project_id, '_contract_dgen_reversed', '1' );

		// myCRED must be present to move DGEN back. If it's gone while DGEN is held, we
		// have set the guard but cannot complete — surface a hard error (do NOT clear the
		// guard: a later retry with myCRED present would double-refund; manual review).
		if ( ! function_exists( 'mycred_add' ) ) {
			error_log( '[gs_collab_contract] reverse_escrow: myCRED absent while ' . $dgen . ' DGEN held in escrow for task ' . $task_id . ' — manual review required' );
			return new WP_Error( 'gs_collab_reverse_no_mycred', 'myCRED unavailable — escrow reversal could not complete', array( 'status' => 500 ) );
		}

		$escrow_holder = self::escrow_holder_uid();
		$log_data      = array( 'project_id' => $project_id, 'task_id' => $task_id );

		// 3. Reverse the two escrow legs (mirror class-task-contract.php:109-114, reversed):
		//    escrow-holder -$dgen 'transact', payer +$dgen 'transact'.
		mycred_add(
			'collab_bond_reverse_debit',
			$escrow_holder,
			-$dgen,
			sprintf( 'Collaboration bond escrow reversed (rollback) — Task #%d', $task_id ),
			$task_id,
			$log_data,
			'transact'
		);
		mycred_add(
			'collab_bond_reverse_credit',
			$payer_user,
			$dgen,
			sprintf( 'Collaboration bond returned (rollback) — Task #%d', $task_id ),
			$task_id,
			$log_data,
			'transact'
		);

		// 4. Zero the escrow meta so the contract no longer reads as funded (and a later
		//    reverse_escrow is an immediate <=0 no-op).
		PSOO_PM_Contracts::set_task_meta( $task_id, $project_id, '_contract_dgen_escrowed', '0' );

		// 5. Chain-anchor the compensating reversal tx — mirror the anchor discipline in
		//    class-task-contract.php:185-196 (guarded by class_exists/method_exists).
		if ( class_exists( 'Gend_Chain_Validator' ) && method_exists( 'Gend_Chain_Validator', 'submit_tx' ) ) {
			Gend_Chain_Validator::submit_tx( array(
				'type'         => 'chain.contract.escrow.reversal',
				'from_app_id'  => '',
				'from_user_id' => $payer_user > 0 ? $payer_user : $escrow_holder,
				'payload'      => array(
					'task_id'    => $task_id,
					'project_id' => $project_id,
					'payer'      => $payer_user,
					'holder'     => $escrow_holder,
					'dgen'       => $dgen,
					'rate'       => self::dgen_rate(),
					'reason'     => 'both_sided_all_or_nothing_rollback',
				),
				'ts'           => time(),
			) );
		}

		do_action( 'gend_gs_collab_escrow_reversed', $task_id, $payer_user, $dgen );
		return true;
	}

	/**
	 * Render the agreed milestones into the contract brief / task description HTML.
	 *
	 * @param mixed  $milestones Decoded milestones (array) or null.
	 * @param string $name_a     Group A name.
	 * @param string $name_b     Group B name.
	 * @return string HTML.
	 */
	private static function milestones_html( $milestones, string $name_a, string $name_b ) : string {
		$html = sprintf(
			'<p><strong>%s</strong> collaboration deliverable.</p>',
			esc_html( sprintf( '%s x %s', $name_a, $name_b ) )
		);
		if ( is_array( $milestones ) && ! empty( $milestones ) ) {
			$html .= '<p><strong>Agreed milestones:</strong></p><ul>';
			foreach ( $milestones as $m ) {
				if ( is_array( $m ) ) {
					$label = isset( $m['title'] ) ? $m['title'] : ( isset( $m['label'] ) ? $m['label'] : wp_json_encode( $m ) );
				} else {
					$label = (string) $m;
				}
				$html .= '<li>' . esc_html( (string) $label ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}
}
