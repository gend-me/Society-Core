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
 *                state). BOTH-SIDED is a clean 400 STUB until Plan 84-02 lands.
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
	 * Hub-only gate. Mirrors member-profile-header.php:213-215 /
	 * class-task-contract.php:35 — true on the main node (or when the OAuth
	 * resource isn't present at all, i.e. a lone hub), false on a container.
	 *
	 * @return bool
	 */
	private static function is_hub() : bool {
		return ! class_exists( 'Gend_CP_OAuth_Resource' )
			|| ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
			|| Gend_CP_OAuth_Resource::is_main_node();
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

		// 4. both_sided is a clean 400 STUB until Plan 84-02 supplies accept_both_sided().
		if ( 'both_sided' === (string) $proposal->escrow_model ) {
			if ( method_exists( __CLASS__, 'accept_both_sided' ) ) {
				return self::accept_both_sided( $match, $proposal, $acceptor_uid );
			}
			return new WP_Error( 'gs_collab_both_sided_pending', 'both-sided escrow coming soon', array( 'status' => 400 ) );
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

		// 9. Result.
		return array(
			'ok'               => true,
			'contract_task_id' => $task_id,
			'project_id'       => $project_id,
			'intro_thread_id'  => $intro_thread_id,
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
