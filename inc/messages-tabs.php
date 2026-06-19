<?php
/**
 * Members / Agents / Projects chat tabs — server-side thread prune (Phase 42).
 *
 * The hub member Messages "Chat" view is the standard BuddyPress message-thread
 * loop (Youzify override). This include adds the server-side core of the
 * Members/Agents/Projects tabs: it reads the active tab from the request
 * (`?gs_chat_tab=`) and prunes the BuddyPress `$messages_template->threads`
 * list per tab BEFORE the loop iterates, classifying each thread's other
 * participant(s) via the EXISTING `gs_user_is_agent()` classifier
 * (inc/agent-chat.php — same plugin, required earlier).
 *
 *   - members  -> keep human-only conversations (agent threads excluded). DEFAULT.
 *   - agents   -> keep only conversations with >=1 agent participant.
 *   - projects -> keep none (Phase-44 seam: the project<->message linkage fills
 *                 this prune and adds a Group column via the
 *                 bp_messages_inbox_list_header / bp_messages_inbox_list_item
 *                 hooks at messages-loop.php:52,110 — left untouched here).
 *
 * Why server-side (not JS hide/show): BP pagination AND the "no messages" empty
 * branch (messages-loop.php:189) both depend on the real thread set. Pruning the
 * template object keeps BP's own counts coherent. (Trade-off: BP paginates in SQL
 * BEFORE this filter, so per-tab pagination is approximate — Research Pitfall 5 /
 * Open Q1. Escalate to a SQL predicate only if UAT shows visibly wrong pagination.)
 *
 * SELF-CONTAINED / FATAL-SAFE: every BuddyPress and cross-file symbol is
 * function_exists/property_exists-guarded so an absent dependency degrades
 * (returns the value unchanged) instead of fataling. php -l cannot catch an
 * undefined-symbol fatal — these guards are the real safety net, and WP would
 * auto-deactivate the plugin on such a fatal.
 *
 * NEVER resolve participants by email (get_user_by('email') is broken on these
 * installs via vendor-app-manager's fix_user_query rewrite) and NEVER call
 * BP_Messages_Thread::get_recipient_unread_count() (not portable; use
 * $rcp->unread_count). All participant resolution is by ID.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Active chat tab from the request — one of members|agents|projects.
 *
 * Non-breaking default is 'members': the current unfiltered list is roughly
 * members + agents, and the Members tab keeps ALL human conversations (only
 * agent threads are excluded), so defaulting here does not hide member convos.
 *
 * @return string 'members' | 'agents' | 'projects'
 */
function gs_chat_active_tab() {
	$tab = '';
	if ( isset( $_GET['gs_chat_tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$tab = function_exists( 'sanitize_key' )
			? sanitize_key( wp_unslash( $_GET['gs_chat_tab'] ) )
			: strtolower( trim( (string) $_GET['gs_chat_tab'] ) );
	}

	$allowed = array( 'members', 'agents', 'projects' );
	if ( ! in_array( $tab, $allowed, true ) ) {
		$tab = 'members';
	}
	return $tab;
}

/**
 * Does a thread involve an AI agent (any participant other than the viewer)?
 *
 * Collects the thread's "other participants" by ID and returns true iff ANY of
 * them passes gs_user_is_agent(). Resolution is ID-only.
 *
 * @param object $thread    A BP thread object (->recipients keyed by user_id,
 *                          each row ->user_id; ->last_sender_id fallback).
 * @param int    $viewer_id The profile owner (excluded from "other participants").
 * @return bool True if at least one non-viewer participant is an agent.
 */
function gs_chat_thread_is_agent( $thread, $viewer_id = 0 ) {

	// Guard the classifier — same plugin (agent-chat.php) and required earlier,
	// but ordering-safe: if absent, treat as NOT an agent (non-breaking: the
	// thread then classifies as a member conversation and stays visible).
	if ( ! function_exists( 'gs_user_is_agent' ) ) {
		return false;
	}

	if ( ! is_object( $thread ) ) {
		return false;
	}

	$viewer_id = (int) $viewer_id;
	if ( $viewer_id <= 0 && function_exists( 'bp_displayed_user_id' ) ) {
		$viewer_id = (int) bp_displayed_user_id();
	}

	// Collect other-participant IDs.
	$other_ids = array();
	if ( isset( $thread->recipients ) && is_array( $thread->recipients ) && ! empty( $thread->recipients ) ) {
		// recipients is keyed by user_id; the keys are the participant IDs.
		$other_ids = array_map( 'intval', array_keys( $thread->recipients ) );
	} elseif ( isset( $thread->last_sender_id ) ) {
		// Sentbox / single-recipient edge case.
		$other_ids = array( (int) $thread->last_sender_id );
	}

	foreach ( $other_ids as $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || $uid === $viewer_id ) {
			continue; // Skip the viewer / invalid ids.
		}
		if ( gs_user_is_agent( $uid ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Prune the BuddyPress thread list to the active chat tab.
 *
 * Hooked on `bp_has_message_threads` (bp-messages-template.php:102) at priority
 * 99 — that filter passes $messages_template BY REFERENCE, so mutating
 * ->threads / ->thread_count in place changes what the loop iterates.
 *
 * BAILS (returns $has_threads unchanged) when this is NOT the Chat messages
 * context — specifically when not on the messages component, or when the current
 * action is 'email' or 'dm' (those modes are owned by email-manager's strip / the
 * React Email SPA). Email + Direct Messages must never be touched.
 *
 * PHASE-44 ATTACH POINT: the 'projects' branch (currently prunes to empty) is
 * where the project<->message linkage will keep project-attached threads; the
 * Group column lands via bp_messages_inbox_list_header / _item (messages-loop.php).
 *
 * @param bool   $has_threads       BP's has_threads() result.
 * @param object $messages_template The box template object (by reference).
 * @param array  $r                 The parsed has-threads args.
 * @return bool  ! empty( pruned threads ) so the BP empty-state branch renders.
 */
function gs_chat_prune_threads_by_tab( $has_threads, $messages_template = null, $r = array() ) {

	// Only act on the Chat messages screen. If we cannot confirm we're on the
	// messages component, bail (defensive: don't prune unknown contexts).
	if ( ! function_exists( 'bp_is_messages_component' ) || ! bp_is_messages_component() ) {
		return $has_threads;
	}

	// Never touch Email (React SPA) or Direct Messages modes.
	if ( function_exists( 'bp_current_action' ) ) {
		$action = bp_current_action();
		if ( 'email' === $action || 'dm' === $action ) {
			return $has_threads;
		}
	}

	// Defensive: need a template object with a threads property to prune.
	if ( ! is_object( $messages_template ) || ! isset( $messages_template->threads ) || ! is_array( $messages_template->threads ) ) {
		return $has_threads;
	}

	// Viewer = profile owner. If unavailable, bail unchanged (cannot classify).
	if ( ! function_exists( 'bp_displayed_user_id' ) ) {
		return $has_threads;
	}
	$viewer = (int) bp_displayed_user_id();

	$tab = gs_chat_active_tab();

	if ( 'projects' === $tab ) {
		// Phase-44 seam: no project-attached threads yet -> empty list.
		$messages_template->threads = array();
	} elseif ( 'agents' === $tab ) {
		$messages_template->threads = array_values( array_filter(
			$messages_template->threads,
			function ( $thread ) use ( $viewer ) {
				return gs_chat_thread_is_agent( $thread, $viewer );
			}
		) );
	} else { // 'members' (default): exclude agent threads, keep all human convos.
		$messages_template->threads = array_values( array_filter(
			$messages_template->threads,
			function ( $thread ) use ( $viewer ) {
				return ! gs_chat_thread_is_agent( $thread, $viewer );
			}
		) );
	}

	// Recompute the count the loop + empty-state rely on.
	$messages_template->thread_count = count( $messages_template->threads );

	// Reset the loop cursor so it re-iterates the pruned set cleanly.
	if ( property_exists( $messages_template, 'current_thread' ) ) {
		$messages_template->current_thread = -1;
	}

	// Return the recomputed has-threads so the "Sorry, no messages were found"
	// empty branch (messages-loop.php:189) renders correctly on an empty tab.
	return ! empty( $messages_template->threads );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'bp_has_message_threads', 'gs_chat_prune_threads_by_tab', 99, 3 );
}
