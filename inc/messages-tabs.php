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

/* =========================================================================
 * Plan 44-01 — Projects tab: project<->thread linkage + Group column + project
 * filter.
 *
 * The Projects tab keeps only message threads that have been ATTACHED to a
 * Project Manager project. There is no native message<->project relation, so we
 * REUSE the existing Project Manager meta table (`{$wpdb->prefix}pm_meta`,
 * normally `wp_pm_meta` on the hub) rather than create a new table:
 *
 *   - project<->group   : entity_type='pm_buddypress', meta_key='group_id',
 *                         meta_value={group_id}, project_id={project_id}  (existing)
 *   - thread<->project  : entity_type='pm_chat_thread', meta_key='thread_id',
 *                         meta_value={thread_id}, project_id={project_id}  (NEW — ours)
 *
 * Every helper is $wpdb-DIRECT (no Project Manager PHP classes are called — a
 * cross-plugin class/method call that php -l cannot see would risk an
 * undefined-symbol fatal, and WP auto-deactivates a plugin that fatals in admin,
 * silently removing the Messages UI — project_wp_fatal_auto_deactivation). Each
 * helper is request-cached via a function static and returns a safe empty value
 * on any guard failure. All user/group/project resolution is BY ID only.
 * ========================================================================= */

/**
 * Fully-qualified Project Manager table name (e.g. 'meta' -> wp_pm_meta).
 *
 * @param string $name Bare suffix after the pm_ prefix ('meta', 'projects').
 * @return string Prefixed table name, or '' if $wpdb is unavailable.
 */
function gs_chat_pm_table( $name ) {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
		return '';
	}
	return $wpdb->prefix . 'pm_' . preg_replace( '/[^a-z0-9_]/', '', (string) $name );
}

/**
 * Map a batch of thread ids to their attached project id in ONE query.
 *
 * @param array $thread_ids Thread ids (ints) to look up.
 * @return array [ (int) thread_id => (int) project_id ] — only attached threads.
 */
function gs_chat_thread_project_map( array $thread_ids ) {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return array();
	}

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $thread_ids ), function ( $v ) {
		return $v > 0;
	} ) ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$meta = gs_chat_pm_table( 'meta' );
	if ( '' === $meta ) {
		return array();
	}

	// Dynamic %d placeholder list for the IN() clause.
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql = $wpdb->prepare(
		"SELECT meta_value AS thread_id, project_id FROM {$meta}
		 WHERE entity_type = 'pm_chat_thread' AND meta_key = 'thread_id'
		   AND meta_value IN ( {$placeholders} )",
		$ids
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + %d placeholders are built safely above.

	$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$tid = (int) $row->thread_id;
			$pid = (int) $row->project_id;
			if ( $tid > 0 && $pid > 0 ) {
				$out[ $tid ] = $pid;
			}
		}
	}
	return $out;
}

/**
 * The BuddyPress group id a project is linked to (or 0).
 *
 * @param int $project_id Project id.
 * @return int Group id, 0 if unlinked / unavailable.
 */
function gs_chat_project_group_id( $project_id ) {
	global $wpdb;
	$project_id = (int) $project_id;
	if ( $project_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return 0;
	}
	$meta = gs_chat_pm_table( 'meta' );
	if ( '' === $meta ) {
		return 0;
	}
	$gid = $wpdb->get_var( $wpdb->prepare(
		"SELECT meta_value FROM {$meta}
		 WHERE entity_type = 'pm_buddypress' AND meta_key = 'group_id' AND project_id = %d
		 LIMIT 1",
		$project_id
	) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built safely above.
	return (int) $gid;
}

/**
 * A project's display title (or a 'Project #N' fallback).
 *
 * @param int $project_id Project id.
 * @return string Title.
 */
function gs_chat_project_label( $project_id ) {
	global $wpdb;
	$project_id = (int) $project_id;
	$fallback   = 'Project #' . $project_id;
	if ( $project_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return $fallback;
	}
	$projects = gs_chat_pm_table( 'projects' );
	if ( '' === $projects ) {
		return $fallback;
	}
	$title = $wpdb->get_var( $wpdb->prepare(
		"SELECT title FROM {$projects} WHERE id = %d",
		$project_id
	) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built safely above.
	$title = is_string( $title ) ? trim( $title ) : '';
	return ( '' !== $title ) ? $title : $fallback;
}

/**
 * A BuddyPress group's name (or a 'Group N' fallback). function_exists-guarded.
 *
 * @param int $gid Group id.
 * @return string Group name.
 */
function gs_chat_group_name( $gid ) {
	$gid = (int) $gid;
	if ( $gid <= 0 ) {
		return '';
	}
	if ( function_exists( 'groups_get_group' ) ) {
		$g = groups_get_group( $gid );
		if ( $g && ! empty( $g->name ) ) {
			return (string) $g->name;
		}
	}
	return 'Group ' . $gid;
}

/**
 * The viewer's attachable projects — projects in groups the viewer belongs to.
 *
 * Used to populate the project filter dropdown and the per-row attach picker.
 *
 * @param int $viewer Viewer (profile owner / current user) id.
 * @return array List of [ 'id' => int, 'title' => string ].
 */
function gs_chat_viewer_projects( $viewer ) {
	global $wpdb;
	$viewer = (int) $viewer;
	if ( $viewer <= 0 ) {
		return array();
	}

	// Group ids the viewer belongs to (guard the BP helper).
	$gids = array();
	if ( function_exists( 'groups_get_user_groups' ) ) {
		$ug = groups_get_user_groups( $viewer );
		if ( is_array( $ug ) && ! empty( $ug['groups'] ) && is_array( $ug['groups'] ) ) {
			$gids = array_values( array_unique( array_map( 'intval', $ug['groups'] ) ) );
		}
	}
	$gids = array_values( array_filter( $gids, function ( $v ) {
		return $v > 0;
	} ) );
	if ( empty( $gids ) ) {
		return array();
	}

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return array();
	}
	$meta     = gs_chat_pm_table( 'meta' );
	$projects = gs_chat_pm_table( 'projects' );
	if ( '' === $meta || '' === $projects ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $gids ), '%d' ) );
	$sql = $wpdb->prepare(
		"SELECT DISTINCT p.id, p.title FROM {$projects} p
		 INNER JOIN {$meta} gm ON gm.project_id = p.id
		   AND gm.entity_type = 'pm_buddypress' AND gm.meta_key = 'group_id'
		 WHERE gm.meta_value IN ( {$placeholders} )
		 ORDER BY p.title ASC",
		$gids
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + %d placeholders built safely above.

	$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$pid = (int) $row->id;
			if ( $pid <= 0 ) {
				continue;
			}
			$title = isset( $row->title ) ? trim( (string) $row->title ) : '';
			$out[] = array(
				'id'    => $pid,
				'title' => ( '' !== $title ) ? $title : ( 'Project #' . $pid ),
			);
		}
	}
	return $out;
}

/**
 * Module-static store for the surviving [thread_id=>project_id] map.
 *
 * The prune branch computes which threads survive the Projects tab; the
 * Group-column item hook then needs each row's project->group WITHOUT
 * re-querying. Call with an array to SET, with no argument to GET.
 *
 * @param array|null $set When an array, replaces the stored map.
 * @return array The current [thread_id=>project_id] map.
 */
function gs_chat_projects_rowmap( $set = null ) {
	static $map = array();
	if ( is_array( $set ) ) {
		$map = $set;
	}
	return $map;
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
		// Plan 44-01 — Projects tab: keep ONLY threads linked to a project via
		// the wp_pm_meta linkage rows (entity_type='pm_chat_thread'). One batch
		// query maps thread_id -> project_id; threads with no mapping drop out.
		// An optional ?gs_chat_project={id} narrows to a single project. The
		// surviving [thread_id=>project_id] map is stashed so the Group-column
		// item hook can resolve each row's project->group WITHOUT re-querying.
		$tids = array();
		foreach ( $messages_template->threads as $t ) {
			if ( is_object( $t ) && isset( $t->thread_id ) ) {
				$tids[] = (int) $t->thread_id;
			}
		}
		$map = gs_chat_thread_project_map( $tids );

		// Optional single-project filter from the dropdown (degrade-safe GET).
		$only = 0;
		if ( isset( $_GET['gs_chat_project'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filter.
			$only = (int) $_GET['gs_chat_project'];
			if ( $only < 0 ) {
				$only = 0;
			}
		}

		$kept = array();
		$messages_template->threads = array_values( array_filter(
			$messages_template->threads,
			function ( $thread ) use ( $map, $only, &$kept ) {
				if ( ! is_object( $thread ) || ! isset( $thread->thread_id ) ) {
					return false;
				}
				$tid = (int) $thread->thread_id;
				if ( ! isset( $map[ $tid ] ) ) {
					return false; // No project linkage -> not in this tab.
				}
				$pid = (int) $map[ $tid ];
				if ( $only > 0 && $pid !== $only ) {
					return false; // Narrowed to a single project.
				}
				$kept[ $tid ] = $pid; // Stash surviving thread->project for the column.
				return true;
			}
		) );

		// Hand the surviving map to the row-column hook (set/get module static).
		if ( function_exists( 'gs_chat_projects_rowmap' ) ) {
			gs_chat_projects_rowmap( $kept );
		}
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

/**
 * Plan 44-01 — Group column header (<th>), Projects tab only.
 *
 * Fires on bp_messages_inbox_list_header (messages-loop.php). Adds one header
 * cell so the per-row Group cell aligns. All output is escaped.
 */
function gs_chat_projects_col_header() {
	$esc = function_exists( 'esc_html' ) ? 'esc_html' : 'htmlspecialchars';
	echo '<th class="gs-thread-group">' . $esc( __( 'Group', 'gend-society' ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via $esc.
}

/**
 * Plan 44-01 — Group column cell (<td>), Projects tab only.
 *
 * Fires on bp_messages_inbox_list_item (messages-loop.php) inside each row.
 * Resolves the current row's thread -> project (via the stashed rowmap) ->
 * group, and renders "Group name — Project title". Always emits a <td> (empty
 * when unmapped) so column alignment is preserved. All output is escaped; all
 * resolution is by ID and $wpdb-direct (fatal-safe).
 */
function gs_chat_projects_col_item() {
	$esc = function_exists( 'esc_html' ) ? 'esc_html' : 'htmlspecialchars';

	$tid = function_exists( 'bp_get_message_thread_id' ) ? (int) bp_get_message_thread_id() : 0;
	$map = function_exists( 'gs_chat_projects_rowmap' ) ? gs_chat_projects_rowmap() : array();
	$pid = ( $tid > 0 && isset( $map[ $tid ] ) ) ? (int) $map[ $tid ] : 0;

	if ( $pid <= 0 ) {
		echo '<td class="gs-thread-group"></td>';
		return;
	}

	$gid   = gs_chat_project_group_id( $pid );
	$group = ( $gid > 0 ) ? gs_chat_group_name( $gid ) : '';
	$label = gs_chat_project_label( $pid );

	$text = ( '' !== $group ) ? ( $group . ' — ' . $label ) : $label;

	echo '<td class="gs-thread-group" data-project="' . (int) $pid . '">' . $esc( $text ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via $esc.
}

/**
 * Register the Group-column hooks — ONLY on the Chat Messages screen with the
 * Projects tab active. Mirrors the bail checks in gs_chat_prune_threads_by_tab
 * (messages component, not email/dm) so the column never leaks into other
 * contexts. Runs on bp_actions (after the request action is known).
 */
function gs_chat_projects_maybe_add_column() {
	if ( ! function_exists( 'bp_is_messages_component' ) || ! bp_is_messages_component() ) {
		return;
	}
	if ( function_exists( 'bp_current_action' ) ) {
		$action = bp_current_action();
		if ( 'email' === $action || 'dm' === $action ) {
			return;
		}
	}
	if ( ! function_exists( 'gs_chat_active_tab' ) || 'projects' !== gs_chat_active_tab() ) {
		return;
	}
	if ( ! function_exists( 'add_action' ) ) {
		return;
	}
	add_action( 'bp_messages_inbox_list_header', 'gs_chat_projects_col_header' );
	add_action( 'bp_messages_inbox_list_item', 'gs_chat_projects_col_item' );
}
if ( function_exists( 'add_action' ) ) {
	// Priority 20 on bp_actions: after BP has parsed the component/action so our
	// gs_chat_active_tab() + bp_is_messages_component() checks are reliable, but
	// well before the messages loop renders.
	add_action( 'bp_actions', 'gs_chat_projects_maybe_add_column', 20 );
}

/* =========================================================================
 * Plan 42-02 — the 3-pill Members / Agents / Projects tab NAV (the UI that
 * drives the server-side prune above).
 *
 * Cloned directly from email-manager/inc/inbox-bp-messages-tabs.php (the
 * proven Email/Chat/Direct-Messages strip): a <template> printed at
 * wp_footer + JS that relocates it BEFORE the BP messages subnav, scoped
 * strictly to Chat mode, with idempotency + a MutationObserver for Youzify's
 * late subnav render. The pills are plain <a> links carrying
 * `?gs_chat_tab=...` so they classify as 'chat' inside email-manager's
 * EXISTING classifyLink()/ajaxNavigate() swap (links stay under /messages/);
 * if that strip / its AJAX is absent the pills degrade to plain full-page
 * links and the server filter still prunes correctly.
 *
 * SELF-CONTAINED / FATAL-SAFE: every BP symbol is function_exists-guarded.
 * Our own dataset flag (gsInjected) is used for idempotency so we never
 * clobber email-manager's emInjected flag.
 * ========================================================================= */

/**
 * Print the 3-pill nav <template> at wp_footer (priority 6 — AFTER
 * email-manager's strip at 5, so our nav inserts relative to a subnav that
 * the strip's own logic has already had a pass at).
 *
 * Bails unless on the BP messages component. Render is cheap; the JS decides
 * (Chat mode only) whether to actually inject.
 */
function gs_chat_tabs_footer() {
	if ( ! function_exists( 'bp_is_messages_component' ) || ! bp_is_messages_component() ) {
		return;
	}

	// Base URL = the current Chat messages URL, query-clean. Prefer the BP
	// displayed-user messages domain; fall back to the request path made
	// absolute (still query-stripped) if BP helpers are unavailable.
	$base = '';
	if ( function_exists( 'bp_displayed_user_domain' ) && function_exists( 'bp_get_messages_slug' ) ) {
		$domain = bp_displayed_user_domain();
		if ( $domain ) {
			$base = trailingslashit( $domain . bp_get_messages_slug() );
		}
	}
	if ( '' === $base && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$path = strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), '?' );
		$base = function_exists( 'home_url' ) ? home_url( $path ) : $path;
	}

	$add_arg = function ( $value ) use ( $base ) {
		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( 'gs_chat_tab', $value, $base );
		}
		$sep = ( strpos( $base, '?' ) === false ) ? '?' : '&';
		return $base . $sep . 'gs_chat_tab=' . rawurlencode( $value );
	};

	$active = function_exists( 'gs_chat_active_tab' ) ? gs_chat_active_tab() : 'members';

	// Plan 44-01 — the viewer's attachable projects (for the filter dropdown and
	// the per-row attach picker). Computed once here, exposed to JS via GS_CHAT.
	$gs_viewer = 0;
	if ( function_exists( 'bp_displayed_user_id' ) ) {
		$gs_viewer = (int) bp_displayed_user_id();
	}
	if ( $gs_viewer <= 0 && function_exists( 'get_current_user_id' ) ) {
		$gs_viewer = (int) get_current_user_id();
	}
	$gs_projects = function_exists( 'gs_chat_viewer_projects' ) ? gs_chat_viewer_projects( $gs_viewer ) : array();

	// The current single-project filter selection (sticky in the dropdown).
	$gs_project_sel = 0;
	if ( isset( $_GET['gs_chat_project'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filter.
		$gs_project_sel = (int) $_GET['gs_chat_project'];
		if ( $gs_project_sel < 0 ) {
			$gs_project_sel = 0;
		}
	}

	$tabs = array(
		'members'  => array( '👥', __( 'Members', 'gend-society' ) ),
		'agents'   => array( '🤖', __( 'Agents', 'gend-society' ) ),
		'projects' => array( '📁', __( 'Projects', 'gend-society' ) ),
	);

	$esc_url  = function_exists( 'esc_url' ) ? 'esc_url' : 'esc_attr';
	$esc_attr = function_exists( 'esc_attr' ) ? 'esc_attr' : 'htmlspecialchars';
	$esc_html = function_exists( 'esc_html' ) ? 'esc_html' : 'htmlspecialchars';

	// Plan 43-01: REST roots + one wp_rest nonce (covers BOTH gs/v1 and psoo/v1).
	// base_url / container host is intentionally NOT exposed.
	$gs_chat_cfg = array(
		'restRoot' => function_exists( 'rest_url' ) ? esc_url_raw( rest_url() ) : '',
		'psooRoot' => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'psoo/v1' ) ) : '',
		'gsRoot'   => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'gs/v1' ) ) : '',
		'nonce'    => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
		// Plan 44-01 — viewer's attachable projects (id+title) for the attach picker.
		'projects' => array_values( $gs_projects ),
	);
	$gs_chat_cfg_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $gs_chat_cfg ) : json_encode( $gs_chat_cfg );
	?>
	<script>window.GS_CHAT = <?php echo $gs_chat_cfg_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded config. ?>;</script>
	<template id="gs-chat-tabs-template">
	  <nav class="gs-chat-tabs" aria-label="<?php echo $esc_attr( __( 'Chat section', 'gend-society' ) ); ?>">
		<?php foreach ( $tabs as $slug => $meta ) : ?>
			<?php $is_active = ( $slug === $active ) ? ' is-active' : ''; ?>
		<a class="gs-chat-tab<?php echo $is_active; ?>"
		   data-gs-tab="<?php echo $esc_attr( $slug ); ?>"
		   href="<?php echo $esc_url( $add_arg( $slug ) ); ?>"
		   aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
			<span class="gs-chat-tab-icon" aria-hidden="true"><?php echo $meta[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static emoji. ?></span>
			<?php echo $esc_html( $meta[1] ); ?>
		</a>
		<?php endforeach; ?>
		<?php /* Plan 43-01: Agents-tab-only "+ Add new Agent" button. Lives inside
			the same template/nav so it rides the existing inject/observer machinery;
			the JS reveals it (removes [hidden]) only when the active tab is 'agents'. */ ?>
		<button class="gs-agent-add" type="button" data-gs-agent-add hidden>
			<span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'Add new Agent', 'gend-society' ) ); ?>
		</button>
	  </nav>
	</template>
	<?php /* Plan 44-01: the Projects-tab project filter. A plain GET <form>
		(degrade-safe: works without JS — a full-page reload carrying
		?gs_chat_project={id} drives the server prune). gs_chat_tab=projects is
		preserved via a hidden input. The JS relocates this AFTER the .gs-chat-tabs
		nav and shows it only on the Projects tab; onchange auto-submits. */ ?>
	<template id="gs-chat-project-filter-template">
	  <form class="gs-chat-project-filter" method="get" data-gs-project-filter>
		<input type="hidden" name="gs_chat_tab" value="projects" />
		<label class="gs-chat-project-filter__label" for="gs-chat-project-select"><?php echo $esc_html( __( 'Project', 'gend-society' ) ); ?></label>
		<select id="gs-chat-project-select" name="gs_chat_project" data-gs-project-select onchange="this.form.submit()">
		  <option value="0"><?php echo $esc_html( __( 'All projects', 'gend-society' ) ); ?></option>
		  <?php foreach ( $gs_projects as $proj ) : ?>
			<?php $proj_id = isset( $proj['id'] ) ? (int) $proj['id'] : 0; ?>
			<?php if ( $proj_id <= 0 ) { continue; } ?>
			<?php $sel = ( $proj_id === $gs_project_sel ) ? ' selected' : ''; ?>
			<option value="<?php echo $proj_id; ?>"<?php echo $sel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static ' selected'. ?>>
			  <?php echo $esc_html( isset( $proj['title'] ) ? $proj['title'] : ( 'Project #' . $proj_id ) ); ?>
			</option>
		  <?php endforeach; ?>
		</select>
	  </form>
	</template>
	<?php /* Plan 43-01 (v8.2 redesign 2): the agent-create popup - a single
		scrollable, futuristic "agent builder" dashboard (NO two-step group
		picker, NO run-target multiselect pool). The agent belongs to a SINGLE
		Home org (one Web App), chosen via an AJAX search that resolves to
		{homeGroup, homeName}. That home group is REQUIRED and drives billing,
		department, reports-to + the sequence binding. Layout, top to bottom:
		  1. Home org (single Web App, AJAX search) | Billing account  - 2-col
		  2. Name *                                                    - full
		  3. Personality (cached, optional)                            - full
		  4. Role / title                                              - full
		  5. Description                                               - full
		  6. Department (select + "+ New") | Reports to                - 2-col
		  7. Prompt sequences (list + nested builder popup)            - full
		Submit POSTs psoo/v1/agents/create with run_target:'webapp' bound to the
		home group, persists each prompt sequence via
		psoo/v1/business-plan/sequences/{id}, then best-effort gs/v1/agent-welcome.
		Connected-device options for each prompt step populate from ALL the
		member's devices (psoo/v1/devices), fetched once when the popup opens. */ ?>
	<template id="gs-agent-popup-template">
	  <div class="gs-agent-popup" data-gs-agent-popup role="dialog" aria-modal="true" aria-labelledby="gs-agent-popup-title">
		<div class="gs-agent-popup__backdrop" data-gs-agent-close></div>
		<div class="gs-agent-popup__dialog">
		  <header class="gs-agent-popup__header">
			<h2 id="gs-agent-popup-title"><?php echo $esc_html( __( 'Add new Agent', 'gend-society' ) ); ?></h2>
			<button type="button" class="gs-agent-popup__close" data-gs-agent-close aria-label="<?php echo $esc_attr( __( 'Close', 'gend-society' ) ); ?>">×</button>
		  </header>
		  <div class="gs-agent-popup__body">

			<?php /* 1 - Home org (single Web App) + Billing account: a 2-col row. */ ?>
			<section class="gs-agent-card gs-agent-2col" data-gs-agent-step="form">
			  <?php /* LEFT - Home org: a single Web App selector via AJAX search. */ ?>
			  <div class="gs-agent-2col__cell">
				<div class="gs-agent-card__head">
				  <span class="gs-agent-card__num">1</span>
				  <h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Home org', 'gend-society' ) ); ?> <span class="gs-agent-req" aria-hidden="true">*</span></h3>
				</div>
				<?php /* When NO home org chosen: search input + result list. */ ?>
				<div data-gs-home-search-wrap>
				  <input type="search" class="gs-agent-search" data-gs-webapp-search placeholder="<?php echo $esc_attr( __( 'Search your Web Apps&hellip;', 'gend-society' ) ); ?>" autocomplete="off" />
				  <div class="gs-agent-webapplist" data-gs-webapp-pool>
					<p class="gs-agent-help" data-gs-webapp-poolstatus aria-live="polite"><?php echo $esc_html( __( 'Loading your Web Apps&hellip;', 'gend-society' ) ); ?></p>
				  </div>
				</div>
				<?php /* When a home org IS chosen: the chosen value + a "change" link. */ ?>
				<div class="gs-agent-home-chosen" data-gs-home-chosen hidden>
				  <img class="gs-agent-home-chosen__av" data-gs-home-avatar alt="" hidden />
				  <span class="gs-agent-home-chosen__name" data-gs-home-name></span>
				  <button type="button" class="gs-agent-home-change" data-gs-home-change><?php echo $esc_html( __( 'change', 'gend-society' ) ); ?></button>
				</div>
				<p class="gs-agent-help gs-agent-hint" data-gs-home-hint><?php echo $esc_html( __( 'Select a Home org (Web App).', 'gend-society' ) ); ?></p>
			  </div>

			  <?php /* RIGHT - Billing account. */ ?>
			  <div class="gs-agent-2col__cell">
				<div class="gs-agent-card__head">
				  <h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Billing account', 'gend-society' ) ); ?></h3>
				</div>
				<select data-agent-field="billing_account" data-gs-billing-select>
				  <option value=""><?php echo $esc_html( __( '(me) - default', 'gend-society' ) ); ?></option>
				</select>
				<p class="gs-agent-help"><?php echo $esc_html( __( 'Leo Credits + Gas Compute for gend.me runs are charged to this member.', 'gend-society' ) ); ?></p>
			  </div>
			</section>

			<?php /* 2 - Name (full width). */ ?>
			<section class="gs-agent-card">
			  <div class="gs-agent-card__head">
				<span class="gs-agent-card__num">2</span>
				<h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Name', 'gend-society' ) ); ?> <span class="gs-agent-req" aria-hidden="true">*</span></h3>
			  </div>
			  <input type="text" data-agent-field="name" placeholder="<?php echo $esc_attr( __( 'e.g. Marketing CEO', 'gend-society' ) ); ?>" autocomplete="off" />
			</section>

			<?php /* 3 - Personality (cached, optional) (full width). */ ?>
			<section class="gs-agent-card">
			  <div class="gs-agent-card__head">
				<span class="gs-agent-card__num">3</span>
				<h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Personality (cached, optional)', 'gend-society' ) ); ?></h3>
			  </div>
			  <textarea data-agent-field="system_prompt" rows="3" placeholder="<?php echo $esc_attr( __( 'System prompt / persona for offline desktop runs', 'gend-society' ) ); ?>"></textarea>
			  <p class="gs-agent-help"><?php echo $esc_html( __( 'Read live from the hub agent; this cached copy lets desktop runs apply the right persona offline.', 'gend-society' ) ); ?></p>
			</section>

			<?php /* 4 - Role / title (full width). PLAIN label, no ampersand entity. */ ?>
			<section class="gs-agent-card">
			  <div class="gs-agent-card__head">
				<span class="gs-agent-card__num">4</span>
				<h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Role / title', 'gend-society' ) ); ?></h3>
			  </div>
			  <input type="text" data-agent-field="role" placeholder="<?php echo $esc_attr( __( 'CEO', 'gend-society' ) ); ?>" autocomplete="off" />
			</section>

			<?php /* 5 - Description (full width). */ ?>
			<section class="gs-agent-card">
			  <div class="gs-agent-card__head">
				<span class="gs-agent-card__num">5</span>
				<h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Description', 'gend-society' ) ); ?></h3>
			  </div>
			  <textarea data-agent-field="description" rows="2" placeholder="<?php echo $esc_attr( __( "What this agent's job is", 'gend-society' ) ); ?>"></textarea>
			</section>

			<?php /* 6 - Department (select + "+ New") | Reports to: a 2-col row. */ ?>
			<section class="gs-agent-card gs-agent-2col">
			  <?php /* LEFT - Department: a <select> (value=dept NAME) + "+ New". */ ?>
			  <div class="gs-agent-2col__cell">
				<div class="gs-agent-card__head">
				  <span class="gs-agent-card__num">6</span>
				  <h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Department', 'gend-society' ) ); ?></h3>
				</div>
				<div class="gs-agent-dept">
				  <select data-agent-field="department" data-gs-agent-dept>
					<option value=""><?php echo $esc_html( __( '- none', 'gend-society' ) ); ?></option>
				  </select>
				  <button type="button" class="gs-agent-dept-new" data-gs-dept-new><span aria-hidden="true">&plus;</span> <?php echo $esc_html( __( 'New', 'gend-society' ) ); ?></button>
				</div>
				<p class="gs-agent-help"><?php echo $esc_html( __( 'Pick an existing department or add a new one.', 'gend-society' ) ); ?></p>
			  </div>

			  <?php /* RIGHT - Reports to. */ ?>
			  <div class="gs-agent-2col__cell">
				<div class="gs-agent-card__head">
				  <h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Reports to', 'gend-society' ) ); ?></h3>
				</div>
				<select data-agent-field="reports_to" data-gs-agent-reportsto>
				  <option value=""><?php echo $esc_html( __( '- root (no parent)', 'gend-society' ) ); ?></option>
				</select>
			  </div>
			</section>

			<?php /* 7 - Prompt sequences (BOTTOM): a LIST of sequences. Each row links
				to a nested step-builder popup. An agent can have MANY sequences. */ ?>
			<section class="gs-agent-card gs-agent-card--seq">
			  <div class="gs-agent-card__head">
				<span class="gs-agent-card__num">7</span>
				<h3 class="gs-agent-card__title"><?php echo $esc_html( __( 'Prompt sequence', 'gend-society' ) ); ?></h3>
			  </div>
			  <p class="gs-agent-help"><?php echo $esc_html( __( 'The agent runs these prompts in order. Each step can target a connected device + an AI integration.', 'gend-society' ) ); ?></p>
			  <div class="gs-agent-seqlist" data-gs-seqlist></div>
			  <button type="button" class="gs-agent-step-add" data-gs-seq-newlist><span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'Add new sequence', 'gend-society' ) ); ?></button>
			</section>

			<div class="gs-agent-actions">
			  <p class="gs-agent-status" data-gs-agent-status aria-live="polite"></p>
			  <button type="button" class="gs-agent-submit" data-gs-agent-submit><?php echo $esc_html( __( 'Create Agent', 'gend-society' ) ); ?></button>
			</div>

		  </div>
		</div>
	  </div>

	  <?php /* A single sequence row in the agent popup's sequence LIST (cloned per
		sequence). Shows name + step count + Edit/Remove. */ ?>
	  <template data-gs-seqrow-tpl>
		<div class="gs-agent-seqrow" data-gs-seqrow>
		  <div class="gs-agent-seqrow__main">
			<span class="gs-agent-seqrow__name" data-gs-seqrow-name></span>
			<span class="gs-agent-seqrow__count" data-gs-seqrow-count></span>
		  </div>
		  <div class="gs-agent-seqrow__actions">
			<button type="button" class="gs-agent-seqrow__edit" data-gs-seqrow-edit><?php echo $esc_html( __( 'Edit', 'gend-society' ) ); ?></button>
			<button type="button" class="gs-agent-seqrow__rm" data-gs-seqrow-rm title="<?php echo $esc_attr( __( 'Remove sequence', 'gend-society' ) ); ?>" aria-label="<?php echo $esc_attr( __( 'Remove sequence', 'gend-society' ) ); ?>">×</button>
		  </div>
		</div>
	  </template>
	</template>

	<?php /* The NESTED step-builder popup — a second overlay (higher z-index +
		own backdrop) that does NOT close the agent popup. Opened by "+ Add new
		sequence" / a row's "Edit"; titled "Prompt sequence". Holds a Sequence-name
		input + the per-step builder moved out of the agent popup. */ ?>
	<template id="gs-seq-popup-template">
	  <div class="gs-seq-popup" data-gs-seq-popup role="dialog" aria-modal="true" aria-labelledby="gs-seq-popup-title">
		<div class="gs-seq-popup__backdrop" data-gs-seq-close></div>
		<div class="gs-seq-popup__dialog">
		  <header class="gs-seq-popup__header">
			<h2 id="gs-seq-popup-title"><?php echo $esc_html( __( 'Prompt sequence', 'gend-society' ) ); ?></h2>
			<button type="button" class="gs-seq-popup__close" data-gs-seq-close aria-label="<?php echo $esc_attr( __( 'Close', 'gend-society' ) ); ?>">×</button>
		  </header>
		  <div class="gs-seq-popup__body">
			<div class="gs-agent-field">
			  <label for="gs-seq-name"><?php echo $esc_html( __( 'Sequence name', 'gend-society' ) ); ?></label>
			  <input type="text" id="gs-seq-name" data-gs-seq-name placeholder="<?php echo $esc_attr( __( 'e.g. Daily standup', 'gend-society' ) ); ?>" autocomplete="off" />
			</div>
			<div class="gs-agent-field">
			  <label for="gs-seq-desc"><?php echo $esc_html( __( 'Sequence description', 'gend-society' ) ); ?></label>
			  <textarea id="gs-seq-desc" data-gs-seq-desc rows="2" placeholder="<?php echo $esc_attr( __( 'What this sequence does (optional).', 'gend-society' ) ); ?>"></textarea>
			</div>
			<p class="gs-agent-help"><?php echo $esc_html( __( 'The agent runs these prompts in order. Each step can target a connected device + an AI integration.', 'gend-society' ) ); ?></p>
			<div class="gs-agent-steps" data-gs-seq-list></div>
			<button type="button" class="gs-agent-step-add" data-gs-seq-add><span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'Add step', 'gend-society' ) ); ?></button>
		  </div>
		  <footer class="gs-seq-popup__footer">
			<p class="gs-agent-status" data-gs-seq-status aria-live="polite"></p>
			<button type="button" class="gs-agent-back" data-gs-seq-cancel><?php echo $esc_html( __( 'Cancel', 'gend-society' ) ); ?></button>
			<button type="button" class="gs-agent-submit" data-gs-seq-save><?php echo $esc_html( __( 'Save sequence', 'gend-society' ) ); ?></button>
		  </footer>
		</div>

		<?php /* Per-step row template (cloned by the nested step builder). */ ?>
		<template data-gs-seq-step-tpl>
		  <div class="gs-agent-step-row" data-gs-seq-step>
			<div class="gs-agent-step-row__head">
			  <span class="gs-agent-step-row__num" data-gs-seq-num>1</span>
			  <div class="gs-agent-step-row__move">
				<button type="button" class="gs-agent-step-mv" data-gs-seq-up title="<?php echo $esc_attr( __( 'Move up', 'gend-society' ) ); ?>" aria-label="<?php echo $esc_attr( __( 'Move up', 'gend-society' ) ); ?>">▲</button>
				<button type="button" class="gs-agent-step-mv" data-gs-seq-down title="<?php echo $esc_attr( __( 'Move down', 'gend-society' ) ); ?>" aria-label="<?php echo $esc_attr( __( 'Move down', 'gend-society' ) ); ?>">▼</button>
				<button type="button" class="gs-agent-step-rm" data-gs-seq-remove title="<?php echo $esc_attr( __( 'Remove step', 'gend-society' ) ); ?>" aria-label="<?php echo $esc_attr( __( 'Remove step', 'gend-society' ) ); ?>">×</button>
			  </div>
			</div>
			<textarea class="gs-agent-step-prompt" data-gs-seq-prompt rows="2" placeholder="<?php echo $esc_attr( __( 'What should the agent do at this step?', 'gend-society' ) ); ?>"></textarea>

			<?php /* v8.3 step-studio: "Prompt Runs At" cascade — device → AI integration
				→ model → estimated cost per run. The AI + Model selects + cost line are
				revealed progressively as the prior level is chosen. */ ?>
			<div class="gs-agent-step-row__targets">
			  <label>
				<span><?php echo $esc_html( __( 'Prompt Runs At', 'gend-society' ) ); ?></span>
				<select class="gs-agent-step-device" data-gs-seq-device>
				  <option value=""><?php echo $esc_html( __( 'Gend.me (hub)', 'gend-society' ) ); ?></option>
				</select>
			  </label>
			  <label data-gs-seq-ai-wrap hidden>
				<span><?php echo $esc_html( __( 'AI integration', 'gend-society' ) ); ?></span>
				<select class="gs-agent-step-ai" data-gs-seq-ai></select>
			  </label>
			  <label data-gs-seq-model-wrap hidden>
				<span><?php echo $esc_html( __( 'Model', 'gend-society' ) ); ?></span>
				<select class="gs-agent-step-model" data-gs-seq-model></select>
			  </label>
			</div>
			<p class="gs-agent-step-cost" data-gs-seq-cost hidden></p>

			<?php /* v8.3 step-studio: Context Files — attach a brain / sequence / upload /
				gdrive doc; rendered as removable chips. */ ?>
			<div class="gs-agent-step-ctx" data-gs-seq-ctx>
			  <div class="gs-agent-step-ctx__head"><?php echo $esc_html( __( 'Context Files', 'gend-society' ) ); ?></div>
			  <div class="gs-agent-step-ctx__chips" data-gs-seq-ctx-chips></div>
			  <div class="gs-agent-step-ctx__controls">
				<button type="button" class="gs-agent-ctx-btn" data-gs-seq-ctx-brain><span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'Business brain', 'gend-society' ) ); ?></button>
				<select class="gs-agent-ctx-seq" data-gs-seq-ctx-seq>
				  <option value=""><?php echo $esc_html( __( '+ Another sequence&hellip;', 'gend-society' ) ); ?></option>
				</select>
				<button type="button" class="gs-agent-ctx-btn" data-gs-seq-ctx-upload><span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'Upload file', 'gend-society' ) ); ?></button>
				<input type="file" class="gs-agent-ctx-file" data-gs-seq-ctx-fileinput hidden />
				<?php /* v8.3 step-studio: Google Drive control — JS renders one of three
					states into [data-gs-seq-ctx-gdrive-wrap] based on gs/v1/gdrive/status
					(NOT configured → paste-link; configured+disconnected → Connect button;
					connected → search picker + paste-link fallback). The paste-link markup
					below is the DEFAULT / degrade-safe state: if status never resolves, the
					member can still paste a Drive link. Mirrors the chat-widget. */ ?>
				<div class="gs-agent-ctx-gdrive-wrap" data-gs-seq-ctx-gdrive-wrap>
				  <div class="gs-agent-ctx-gdrive">
					<input type="url" class="gs-agent-ctx-gdrive-url" data-gs-seq-ctx-gdrive placeholder="<?php echo $esc_attr( __( 'Paste a Google Drive link', 'gend-society' ) ); ?>" />
					<button type="button" class="gs-agent-ctx-btn" data-gs-seq-ctx-gdrive-add><span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'Add', 'gend-society' ) ); ?></button>
				  </div>
				</div>
			  </div>
			</div>

			<?php /* v8.3 step-studio: Response actions — what to do with this step's
				output. Each checkbox reveals its own settings sub-form. */ ?>
			<div class="gs-agent-step-out" data-gs-seq-out>
			  <div class="gs-agent-step-out__head"><?php echo $esc_html( __( 'Do this with the response', 'gend-society' ) ); ?></div>
			  <div class="gs-agent-step-out__opts">
				<label class="gs-agent-out-opt"><input type="checkbox" data-gs-seq-out-type="email" /> <?php echo $esc_html( __( 'Email', 'gend-society' ) ); ?></label>
				<label class="gs-agent-out-opt"><input type="checkbox" data-gs-seq-out-type="chat" /> <?php echo $esc_html( __( 'Send chat message', 'gend-society' ) ); ?></label>
				<label class="gs-agent-out-opt"><input type="checkbox" data-gs-seq-out-type="project" /> <?php echo $esc_html( __( 'Update Project', 'gend-society' ) ); ?></label>
				<label class="gs-agent-out-opt"><input type="checkbox" data-gs-seq-out-type="chain" /> <?php echo $esc_html( __( 'Use in next step', 'gend-society' ) ); ?></label>
				<label class="gs-agent-out-opt"><input type="checkbox" data-gs-seq-out-type="context_launch" /> <?php echo $esc_html( __( 'Launch another sequence', 'gend-society' ) ); ?></label>
			  </div>
			  <div class="gs-agent-step-out__forms" data-gs-seq-out-forms></div>
			</div>
		  </div>
		</template>

		<?php /* v8.3 step-studio: per-output settings sub-form templates, cloned by the
			step builder when a response action is checked. */ ?>
		<template data-gs-seq-out-email-tpl>
		  <div class="gs-agent-out-form" data-gs-seq-out-form="email">
			<div class="gs-agent-out-form__title"><?php echo $esc_html( __( 'Email', 'gend-society' ) ); ?></div>
			<label><span><?php echo $esc_html( __( 'To', 'gend-society' ) ); ?></span><input type="text" data-gs-seq-out-field="to" placeholder="<?php echo $esc_attr( __( 'someone@example.com', 'gend-society' ) ); ?>" /></label>
			<label><span><?php echo $esc_html( __( 'Subject', 'gend-society' ) ); ?></span><input type="text" data-gs-seq-out-field="subject" placeholder="<?php echo $esc_attr( __( 'Subject line', 'gend-society' ) ); ?>" /></label>
			<label class="gs-agent-out-email-body"><span><?php echo $esc_html( __( 'Body', 'gend-society' ) ); ?></span><textarea rows="4" data-gs-seq-out-field="body" placeholder="<?php echo $esc_attr( __( "Email body — use {{response}} to insert the agent's output (leave blank to send the output as the body)", 'gend-society' ) ); ?>"></textarea></label>
		  </div>
		</template>
		<?php /* v8.3 step-studio refinement: chat output = a member multi-select.
			settings.recipients = array of member ids. A debounced search (gs/v1/
			group-members?group_id&term) lists avatar+name results; click adds an id
			(deduped) + clears the search; selected ids render as removable chips. The
			JS owns the dynamic results/chips; this template carries the static shell
			(search input + the two JS-filled containers). */ ?>
		<template data-gs-seq-out-chat-tpl>
		  <div class="gs-agent-out-form" data-gs-seq-out-form="chat">
			<div class="gs-agent-out-form__title"><?php echo $esc_html( __( 'Send chat message', 'gend-society' ) ); ?></div>
			<div class="gs-agent-out-recip" data-gs-seq-out-recip>
			  <span class="gs-agent-out-recip__label"><?php echo $esc_html( __( 'Recipients', 'gend-society' ) ); ?></span>
			  <div class="gs-agent-out-recip__chips" data-gs-seq-out-recip-chips></div>
			  <input type="text" class="gs-agent-out-recip__search" data-gs-seq-out-recip-search placeholder="<?php echo $esc_attr( __( 'Search members&hellip;', 'gend-society' ) ); ?>" autocomplete="off" />
			  <div class="gs-agent-out-recip__results" data-gs-seq-out-recip-results></div>
			  <?php /* Hidden field so syncStepOutputsFromDom captures recipients via the generic field loop. JS keeps its value as a JSON array of ids. */ ?>
			  <input type="hidden" data-gs-seq-out-field="recipients" data-gs-seq-out-recip-store value="[]" />
			</div>
		  </div>
		</template>
		<?php /* v8.3 step-studio refinement: project output = a workspace <select>
			(gs/v1/group-projects) + "+ New workspace" + a task-title input. The agent's
			response is saved as the task description. */ ?>
		<template data-gs-seq-out-project-tpl>
		  <div class="gs-agent-out-form" data-gs-seq-out-form="project">
			<div class="gs-agent-out-form__title"><?php echo $esc_html( __( 'Update Project', 'gend-society' ) ); ?></div>
			<label><span><?php echo $esc_html( __( 'Workspace', 'gend-society' ) ); ?></span>
			  <span class="gs-agent-out-proj-row">
				<select data-gs-seq-out-field="project" data-gs-seq-out-proj-select>
				  <option value=""><?php echo $esc_html( __( '— select workspace', 'gend-society' ) ); ?></option>
				</select>
				<button type="button" class="gs-agent-out-proj-new" data-gs-seq-out-proj-new><span aria-hidden="true">＋</span> <?php echo $esc_html( __( 'New workspace', 'gend-society' ) ); ?></button>
			  </span>
			</label>
			<label><span><?php echo $esc_html( __( 'What to create/update', 'gend-society' ) ); ?></span><input type="text" data-gs-seq-out-field="task_title" placeholder="<?php echo $esc_attr( __( 'Task title', 'gend-society' ) ); ?>" /></label>
			<p class="gs-agent-help"><?php echo $esc_html( __( "The agent's response is saved as the task description.", 'gend-society' ) ); ?></p>
		  </div>
		</template>
		<template data-gs-seq-out-chain-tpl>
		  <div class="gs-agent-out-form" data-gs-seq-out-form="chain">
			<div class="gs-agent-out-form__title"><?php echo $esc_html( __( 'Use in next step', 'gend-society' ) ); ?></div>
			<p class="gs-agent-help"><?php echo $esc_html( __( 'Feeds this output into the next step as {{previous}}.', 'gend-society' ) ); ?></p>
		  </div>
		</template>
		<?php /* v8.3 step-studio refinement: launch-sequence output = TWO cascading
			selects. Agent (gs/v1/agent-list → settings.agent_slug) then Sequence,
			filtered from the fetched group sequences where agentSlug === agent_slug
			(→ settings.sequence_id; disabled until an agent is chosen). */ ?>
		<template data-gs-seq-out-context_launch-tpl>
		  <div class="gs-agent-out-form" data-gs-seq-out-form="context_launch">
			<div class="gs-agent-out-form__title"><?php echo $esc_html( __( 'Launch another sequence', 'gend-society' ) ); ?></div>
			<label><span><?php echo $esc_html( __( 'Agent', 'gend-society' ) ); ?></span>
			  <select data-gs-seq-out-field="agent_slug" data-gs-seq-out-launch-agent>
				<option value=""><?php echo $esc_html( __( '— choose an agent', 'gend-society' ) ); ?></option>
			  </select>
			</label>
			<label><span><?php echo $esc_html( __( 'Sequence to launch', 'gend-society' ) ); ?></span>
			  <select data-gs-seq-out-field="sequence_id" data-gs-seq-out-launch-seq disabled>
				<option value=""><?php echo $esc_html( __( '— pick an agent first', 'gend-society' ) ); ?></option>
			  </select>
			</label>
		  </div>
		</template>
	  </div>
	</template>
	<script>
	(function () {
		// The SAME subnav selector list as email-manager's strip
		// (inbox-bp-messages-tabs.php) so we anchor relative to the exact
		// element it already finds.
		var SUBNAV_SELECTORS = [
			'.item-list-tabs#subnav',
			'#subnav.item-list-tabs',
			'.bp-subnavs',
			'div#subnav',
			'.youzify-bp-message-nav',
			'.youzify-bp-message-options',
			'.youzify-bp-content-nav',
			'.youzify-bp-content .item-list-tabs',
			'.youzify-bp-nav.item-list-tabs',
			'ul#subnav',
			'.subnav.item-list-tabs'
		];

		function findSubnav() {
			for (var i = 0; i < SUBNAV_SELECTORS.length; i++) {
				var el = document.querySelector(SUBNAV_SELECTORS[i]);
				if (el) return el;
			}
			return null;
		}

		// Chat mode = email-manager set body.em-inbox-bp-mode-chat. If the
		// strip never ran (class absent) AND we're not on an email/dm path,
		// treat as Chat too — so our nav still appears + degrades to plain
		// full-reload links.
		function isChatMode() {
			var cl = document.body.classList;
			if (cl.contains('em-inbox-bp-mode-chat')) return true;
			if (cl.contains('em-inbox-bp-mode-email') || cl.contains('em-inbox-bp-mode-dm')) return false;
			var p = location.pathname;
			if (/\/messages\/email\/?$/.test(p) || /\/messages\/dm\/?$/.test(p)) return false;
			return /\/messages\//.test(p) || /\/messages\/?$/.test(p);
		}

		function activeTabFromUrl() {
			try {
				var v = new URLSearchParams(location.search).get('gs_chat_tab');
				if (v === 'agents' || v === 'projects' || v === 'members') return v;
			} catch (e) {}
			return 'members';
		}

		// Reflect the active pill from the (current) URL.
		function applyActive(nav) {
			if (!nav) return;
			var tab = activeTabFromUrl();
			var links = nav.querySelectorAll('.gs-chat-tab');
			for (var i = 0; i < links.length; i++) {
				var a = links[i];
				var on = (a.getAttribute('data-gs-tab') === tab);
				a.classList.toggle('is-active', on);
				a.setAttribute('aria-current', on ? 'page' : 'false');
			}
			revealAddButton(nav);
			// Plan 44-01: place + show the project filter only on the Projects tab.
			syncProjectFilter(nav);
		}

		// Plan 44-01: relocate the project-filter form AFTER the .gs-chat-tabs nav
		// (idempotent), and show it only when the active tab is 'projects'. The
		// form is a plain GET (degrade-safe); JS only positions + toggles it.
		function syncProjectFilter(nav) {
			nav = nav || document.querySelector('.gs-chat-tabs');
			if (!nav || !nav.parentNode) return;
			var form = document.querySelector('.gs-chat-project-filter');
			if (!form) {
				var ftpl = document.getElementById('gs-chat-project-filter-template');
				if (!ftpl) return;
				var ffrag = ftpl.content.cloneNode(true);
				if (nav.nextSibling) {
					nav.parentNode.insertBefore(ffrag, nav.nextSibling);
				} else {
					nav.parentNode.appendChild(ffrag);
				}
				form = document.querySelector('.gs-chat-project-filter');
			}
			if (!form) return;
			form.style.display = (activeTabFromUrl() === 'projects') ? '' : 'none';
		}

		// Plan 43-01: show the "+ Add new Agent" button ONLY on the Agents tab.
		function revealAddButton(nav) {
			var btn = (nav && nav.querySelector('[data-gs-agent-add]')) || document.querySelector('.gs-chat-tabs [data-gs-agent-add]');
			if (!btn) return;
			if (activeTabFromUrl() === 'agents') {
				btn.removeAttribute('hidden');
			} else {
				btn.setAttribute('hidden', '');
			}
		}

		// Inject the nav before the BP subnav, exactly once. Mirrors the
		// strip's dataset.emInjected pattern but uses OUR own flag.
		function tryInject() {
			if (!isChatMode()) return false;
			var tpl = document.getElementById('gs-chat-tabs-template');
			if (!tpl) return false;
			if (tpl.dataset.gsInjected) {
				applyActive(document.querySelector('.gs-chat-tabs'));
				return true;
			}
			var subnav = findSubnav();
			if (!subnav || !subnav.parentNode) return false;
			var frag = tpl.content.cloneNode(true);
			subnav.parentNode.insertBefore(frag, subnav);
			tpl.dataset.gsInjected = '1';
			applyActive(document.querySelector('.gs-chat-tabs'));
			return true;
		}

		function run() {
			tryInject();
			// Youzify renders the subnav late; watch the body and re-run.
			// Also: email-manager's AJAX swap clears ITS template's
			// emInjected after a content swap — when that happens our
			// .gs-chat-tabs node was wiped along with the container, so we
			// must clear OUR flag too and re-inject. Detect that by the nav
			// being gone while the flag is still set.
			// IMPORTANT: keep observing for the LIFETIME of the page — do NOT
			// disconnect after a few seconds. email-manager intercepts every
			// link under /messages/ (incl. OUR pills) and AJAX-swaps the
			// messages container's innerHTML (history.pushState, no popstate),
			// which WIPES our injected .gs-chat-tabs nav each time the user
			// switches Email/Chat/DM, pages, or clicks a pill. If we stop
			// observing, the nav (and the Agents-tab "+ Add new Agent" button)
			// never come back after that first swap. The callback is debounced
			// to one pass per frame so chat-poll DOM churn stays cheap.
			var gsPending = false;
			function gsResync() {
				gsPending = false;
				var tpl = document.getElementById('gs-chat-tabs-template');
				if (tpl && tpl.dataset.gsInjected && !document.querySelector('.gs-chat-tabs')) {
					tpl.dataset.gsInjected = '';
				}
				tryInject();
			}
			var obs = new MutationObserver(function () {
				if (gsPending) return;
				gsPending = true;
				(window.requestAnimationFrame || window.setTimeout)(gsResync);
			});
			obs.observe(document.body, { childList: true, subtree: true });
			// popstate (email-manager pushes URL on AJAX nav) → re-sync the
			// active pill from the new URL.
			window.addEventListener('popstate', function () {
				applyActive(document.querySelector('.gs-chat-tabs'));
			});

			// Plan 43-01: the "+ Add new Agent" popup. Delegated click (the button
			// is injected/re-injected by tryInject) so we bind once on document.
			wireAgentPopup();

			// Plan 44-01: per-row "Attach to project" control (Projects + Members
			// tabs). Injects a small button into each thread row and POSTs the
			// chosen project to gs/v1/chat-attach-project, then reloads. Fully
			// guarded — if anything is missing it no-ops, never throws.
			wireAttachControl();
		}

		/* ---- Plan 44-01: per-row "Attach to project" control ---- */
		function wireAttachControl() {
			var cfg = window.GS_CHAT || {};

			// Only Projects + Members tabs get the control.
			function tabAllowsAttach() {
				var t = activeTabFromUrl();
				return (t === 'projects' || t === 'members');
			}

			// Find each thread row's id + a sensible mount point. BP renders rows as
			// <tr> with id="m-{thread_id}" (or a data attr); degrade gracefully.
			function rowThreadId(row) {
				if (!row) return 0;
				var id = row.getAttribute('data-thread-id');
				if (id && /^\d+$/.test(id)) return parseInt(id, 10);
				var domId = row.id || '';
				var m = domId.match(/(\d+)/);
				return m ? parseInt(m[1], 10) : 0;
			}

			function injectButtons() {
				if (!tabAllowsAttach()) return;
				if (!cfg.gsRoot) return;
				var rows = document.querySelectorAll('#message-threads tr, .messages-notices tr, table.messages-notices tbody tr');
				for (var i = 0; i < rows.length; i++) {
					(function (row) {
						if (row.querySelector('[data-gs-attach]')) return;       // already added
						var tid = rowThreadId(row);
						if (!tid) return;
						// Mount into the last cell so it doesn't disturb existing columns.
						var cell = row.querySelector('td:last-child') || row.lastElementChild;
						if (!cell) return;
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'gs-thread-attach';
						btn.setAttribute('data-gs-attach', String(tid));
						btn.title = 'Attach this conversation to a project';
						btn.textContent = '📁';
						cell.appendChild(btn);
					})(rows[i]);
				}
			}

			function pickProject() {
				var list = (cfg.projects && cfg.projects.length) ? cfg.projects : [];
				if (!list.length) {
					window.alert('No projects available to attach to. Join a project group first.');
					return null;
				}
				var lines = ['Attach to which project? Enter the number:'];
				for (var i = 0; i < list.length; i++) {
					lines.push((i + 1) + '. ' + (list[i].title || ('Project #' + list[i].id)));
				}
				var raw = window.prompt(lines.join('\n'), '1');
				if (raw === null) return null;
				var idx = parseInt(raw, 10);
				if (!idx || idx < 1 || idx > list.length) return null;
				return parseInt(list[idx - 1].id, 10) || null;
			}

			function attach(threadId) {
				var pid = pickProject();
				if (!pid) return;
				if (!cfg.gsRoot) return;
				fetch(cfg.gsRoot + '/chat-attach-project', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
					body: JSON.stringify({ thread_id: threadId, project_id: pid })
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						if (data && data.ok) {
							location.reload();
						} else {
							window.alert('Could not attach the conversation to that project.');
						}
					})
					.catch(function () { window.alert('Could not attach the conversation to that project.'); });
			}

			// Delegated click — survives BP/Youzify AJAX row re-renders.
			document.addEventListener('click', function (e) {
				var btn = e.target && e.target.closest ? e.target.closest('[data-gs-attach]') : null;
				if (!btn) return;
				e.preventDefault();
				var tid = parseInt(btn.getAttribute('data-gs-attach'), 10) || 0;
				if (tid > 0) attach(tid);
			});

			// Inject now + on later DOM mutations (the same observer in run() also
			// re-runs tryInject; we piggyback a light reinjection here).
			injectButtons();
			var obs = new MutationObserver(function () { injectButtons(); });
			obs.observe(document.body, { childList: true, subtree: true });
			setTimeout(function () { obs.disconnect(); }, 4000);
		}

		/* ---- Plan 43-01 (v8.2 redesign 2): Add-new-Agent popup ----
		 * Single scrollable "agent builder" dashboard. NO two-step group picker,
		 * NO run-target multiselect pool. The agent belongs to a SINGLE Home org
		 * (one Web App), chosen via an AJAX search that resolves to {homeGroup,
		 * homeName}. That home group is REQUIRED and drives billing, department,
		 * reports-to + the sequence binding. Mirrors the redesigned chat-widget
		 * popup field-for-field, in this file's vanilla-JS + <template> style. All
		 * fetches reuse cfg.gsRoot/cfg.psooRoot + plain fetch with X-WP-Nonce,
		 * every path is guarded, nothing throws. */
		function wireAgentPopup() {
			var cfg = window.GS_CHAT || {};
			var popupEl = null;        // the live .gs-agent-popup node (injected once)

			// The chosen Home org (single Web App) — drives billing / department /
			// reports-to / create payload / sequences. homeGroup = group_id (int|null).
			var homeGroup = null;
			var homeName = '';
			var homeAvatar = '';
			// ALL the member's connected devices (psoo/v1/devices), fetched ONCE when
			// the popup opens (devices are no longer a top-level pool, but each prompt
			// step can still target one of them).
			var allDevices = null;
			var devicesLoading = false;
			// Departments for the home group (cached on home-org change).
			var departments = [];
			// Web-App search debounce timer.
			var searchTimer = null;
			// Sequences model: [{ id, name, description, prompts:[{id, text, deviceRef,
			// aiIntegration, model, contextFiles:[...], outputs:[...]}] }].
			var sequences = [];
			// v8.3 step-studio: the home group's OTHER prompt sequences (gs/v1/group-sequences),
			// fetched once per nested-popup open — drives the Context Files + "Launch another
			// sequence" pickers. [{ id, name, agentSlug }].
			var groupSequences = null;
			var groupSequencesLoading = false;
			// v8.3 step-studio refinement (mirror chat-widget): the home group's agents
			// (gs/v1/agent-list) for the "Launch another sequence" Agent→Sequence
			// cascade, and its project workspaces (gs/v1/group-projects) for the
			// "Update Project" output. Both fetched once per nested-popup open.
			var groupAgents = null;             // [{ slug, name }] | null
			var groupAgentsLoading = false;
			var groupProjects = null;           // [{ id, name }] | null
			var groupProjectsLoading = false;
			// v8.3 step-studio refinement: member name/avatar cache (id → {id,name,
			// avatar}) for the chat output's recipient multi-select. Seeded by a
			// term-less group-members fetch and augmented by every search result so
			// already-selected recipient chips can resolve name+avatar.
			var memberCache = {};
			var memberCacheLoading = false;
			var memberSearchTimers = {};        // step.id -> setTimeout handle (debounce)
			// v8.3 step-studio: Google Drive picker state (mirrors the chat-widget). Fetched
			// once per agent-popup open via gs/v1/gdrive/status. `null` = unknown (not yet
			// fetched / fetch failed → render the degrade-safe paste-link). Search has its
			// own per-step debounce timer.
			var gdriveStatus = null;          // { configured:bool, connected:bool } | null
			var gdriveStatusLoading = false;
			var gdriveSearchTimers = {};      // step.id -> setTimeout handle (debounce)
			var gdriveConnectListener = null; // the one-time window 'message' handler
			// The nested step-builder popup node (injected once) + its working state.
			var seqPopupEl = null;
			var seqEditingId = null;   // id of the sequence being edited (null = new)
			var seqSteps = [];         // working step set for the OPEN nested popup
			var seqCounterId = 0;      // monotonic id source for new sequences

			function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }
			function escHtml(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

			function injectPopup() {
				if (popupEl && document.body.contains(popupEl)) return popupEl;
				var tpl = document.getElementById('gs-agent-popup-template');
				if (!tpl) return null;
				var frag = tpl.content.cloneNode(true);
				document.body.appendChild(frag);
				popupEl = document.querySelector('.gs-agent-popup');
				bindPopup(popupEl);
				return popupEl;
			}

			function setStatus(node, msg, kind) {
				if (!node) return;
				node.textContent = msg || '';
				node.className = 'gs-agent-status' + (kind ? (' is-' + kind) : '');
			}

			function closePopup() {
				if (popupEl) popupEl.classList.remove('is-open');
			}

			function openPopup() {
				var root = injectPopup();
				if (!root) return;
				// Reset all builder state on each open.
				homeGroup = null;
				homeName = '';
				homeAvatar = '';
				departments = [];
				sequences = [];
				seqEditingId = null;
				seqSteps = [];
				// v8.3 step-studio refinement: reset the home-group-scoped caches so a
				// re-open with a different home org refetches agents/projects/members.
				groupSequences = null;
				groupAgents = null;
				groupProjects = null;
				memberCache = {};
				root.classList.add('is-open');
				setStatus(root.querySelector('[data-gs-agent-status]'), '', '');
				// Clear text fields (note: department is now a <select>, reset below).
				['name', 'role', 'description', 'system_prompt'].forEach(function (k) {
					var n = root.querySelector('[data-agent-field="' + k + '"]');
					if (n) n.value = '';
				});
				// No home org yet → show the search, hide the chosen-value chip,
				// reset every home-dependent select.
				showHomeSearch(root);
				syncHomeHint(root);
				resetBilling(root);
				resetReportsTo(root);
				resetDepartments(root);
				// Fetch ALL the member's devices ONCE (per-step device selects use them).
				allDevices = null;
				loadAllDevices(root);
				// v8.3 step-studio: fetch the Google Drive connection status ONCE per open
				// so the Context Files Drive control can render the right state (paste-link
				// / Connect / search). Guarded: on failure it stays the paste-link default.
				gdriveStatus = null;
				loadGdriveStatus();
				// Populate the Web-App search with the caller's admin Web Apps.
				loadWebApps(root, '');
				// Render the (empty) sequence list — sequences are built in the nested popup.
				renderSeqList(root);
			}

			function fieldValue(root, key) {
				var node = root.querySelector('[data-agent-field="' + key + '"]');
				return node ? (node.value || '').trim() : '';
			}

			/* ── Home org: search visibility + chosen-value display ── */
			// Show the AJAX search input (no home org chosen yet); hide the chip.
			function showHomeSearch(root) {
				var wrap = root.querySelector('[data-gs-home-search-wrap]');
				var chosen = root.querySelector('[data-gs-home-chosen]');
				if (wrap) wrap.hidden = false;
				if (chosen) chosen.hidden = true;
			}

			// Show the chosen-home chip (avatar + name + "change"); hide the search.
			function showHomeChosen(root) {
				var wrap = root.querySelector('[data-gs-home-search-wrap]');
				var chosen = root.querySelector('[data-gs-home-chosen]');
				var nameEl = root.querySelector('[data-gs-home-name]');
				var avEl = root.querySelector('[data-gs-home-avatar]');
				if (wrap) wrap.hidden = true;
				if (chosen) chosen.hidden = false;
				if (nameEl) nameEl.textContent = homeName || ('Group ' + homeGroup);
				if (avEl) {
					if (homeAvatar) { avEl.src = homeAvatar; avEl.hidden = false; }
					else { avEl.removeAttribute('src'); avEl.hidden = true; }
				}
			}

			// Pick a Web App as the SINGLE home org → reload billing + departments +
			// reports-to for that group. Also refresh any open nested step builder.
			function pickHome(root, g) {
				homeGroup = parseInt(g.group_id, 10) || null;
				homeName = g.name || ('Group ' + g.group_id);
				homeAvatar = g.avatar || '';
				showHomeChosen(root);
				syncHomeHint(root);
				if (homeGroup) {
					loadBilling(root);
					loadDepartments(root);
					loadReportsTo(root);
				} else {
					resetBilling(root);
					resetDepartments(root);
					resetReportsTo(root);
				}
			}

			// Clear the chosen home org → the search input returns; reset dependents.
			function changeHome(root) {
				homeGroup = null;
				homeName = '';
				homeAvatar = '';
				departments = [];
				showHomeSearch(root);
				syncHomeHint(root);
				resetBilling(root);
				resetDepartments(root);
				resetReportsTo(root);
				loadWebApps(root, '');
			}

			// Create disabled until a Home org is chosen + a Name is present; the hint
			// is shown only while no home org is selected.
			function syncHomeHint(root) {
				var hint = root.querySelector('[data-gs-home-hint]');
				var submit = root.querySelector('[data-gs-agent-submit]');
				var ok = !!homeGroup;
				if (hint) hint.style.display = ok ? 'none' : '';
				if (submit) submit.disabled = !ok;
			}

			/* ── Connected Devices (psoo/v1/devices) — fetched ONCE per open ── */
			// Devices are no longer a top-level pool; per-prompt step selects use them.
			function loadAllDevices(root) {
				if (Array.isArray(allDevices)) {
					if (seqPopupEl && seqPopupEl.classList.contains('is-open')) { refreshSeqDeviceSelects(seqPopupEl); }
					return;
				}
				if (devicesLoading || !cfg.psooRoot) { return; }
				devicesLoading = true;
				fetch(cfg.psooRoot + '/devices', {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						allDevices = (data && Array.isArray(data.devices)) ? data.devices : (Array.isArray(data) ? data : []);
						if (seqPopupEl && seqPopupEl.classList.contains('is-open')) { refreshSeqDeviceSelects(seqPopupEl); }
					})
					.catch(function () { allDevices = []; })
					.finally(function () { devicesLoading = false; });
			}

			function deviceById(id) {
				if (!Array.isArray(allDevices)) return null;
				for (var i = 0; i < allDevices.length; i++) {
					var d = allDevices[i];
					if (d && String(d.id != null ? d.id : d.device_id) === String(id)) return d;
				}
				return null;
			}

			function deviceLabel(id) {
				var d = deviceById(id);
				return d ? (d.label || String(id)) : String(id);
			}

			/* ── Home-org AJAX search (gs/v1/agent-admin-groups?term=) ── */
			// Renders each result as a button with avatar + name; clicking sets home.
			function loadWebApps(root, term) {
				var pool = root.querySelector('[data-gs-webapp-pool]');
				if (!pool) return;
				if (!cfg.gsRoot) { pool.innerHTML = '<p class="gs-agent-help">Configuration missing.</p>'; return; }
				pool.innerHTML = '<p class="gs-agent-help">Searching…</p>';
				fetch(cfg.gsRoot + '/agent-admin-groups?term=' + encodeURIComponent(term || ''), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var groups = (data && data.groups) || [];
						if (!groups.length) {
							pool.innerHTML = '<p class="gs-agent-help">No matching Web Apps you administer.</p>';
							return;
						}
						var html = '';
						for (var i = 0; i < groups.length; i++) {
							var g = groups[i];
							var gid = String(g.group_id);
							var name = g.name || ('Group ' + gid);
							var av = g.avatar ? '<img class="gs-agent-target-opt__avatar" src="' + escAttr(g.avatar) + '" alt="" />'
								: '<span class="gs-agent-target-opt__icon" aria-hidden="true">🌐</span>';
							html += '<button type="button" class="gs-agent-target-opt" data-gs-webapp-opt data-ref="' + escAttr(gid) + '" data-name="' + escAttr(name) + '" data-avatar="' + escAttr(g.avatar || '') + '">'
								+ av
								+ '<span class="gs-agent-target-opt__label">' + escHtml(name) + '</span>'
								+ '</button>';
						}
						pool.innerHTML = html;
						// Convenience: exactly ONE admin Web App + no search term → auto-select
						// it as the home org so billing/department/reports-to populate without
						// a manual pick (mirrors the chat-widget popup).
						if (groups.length === 1 && !homeGroup && !(term || '').trim()) {
							pickHome(root, { group_id: groups[0].group_id, name: groups[0].name || '', avatar: groups[0].avatar || '' });
						}
					})
					.catch(function () { pool.innerHTML = '<p class="gs-agent-help">Could not load your Web Apps.</p>'; });
			}

			/* ── Billing account (gs/v1/group-members?group_id=homeGroup) ── */
			function resetBilling(root) {
				var sel = root.querySelector('[data-gs-billing-select]');
				if (sel) sel.innerHTML = '<option value="">(me) - default</option>';
			}

			function loadBilling(root) {
				var sel = root.querySelector('[data-gs-billing-select]');
				if (!sel || !homeGroup || !cfg.gsRoot) return;
				var current = sel.value;
				resetBilling(root);
				fetch(cfg.gsRoot + '/group-members?group_id=' + encodeURIComponent(homeGroup), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var members = (data && data.members) || [];
						var seen = {};
						for (var i = 0; i < members.length; i++) {
							var m = members[i];
							if (!m || m.id == null) continue;
							var id = String(m.id);
							if (!id || seen[id]) continue;
							seen[id] = true;
							var opt = document.createElement('option');
							opt.value = id;
							opt.textContent = m.name || id;
							sel.appendChild(opt);
						}
						if (current) { sel.value = current; }
					})
					.catch(function () { /* leave just the (me) option */ });
			}

			/* ── Department (gs/v1/group-departments) — select + "+ New" ── */
			function resetDepartments(root) {
				departments = [];
				var sel = root.querySelector('[data-gs-agent-dept]');
				if (sel) sel.innerHTML = '<option value="">- none</option>';
			}

			// Render the department <select> from the cached `departments` (value =
			// department NAME), keeping the current selection if still present.
			function renderDepartments(root, keep) {
				var sel = root.querySelector('[data-gs-agent-dept]');
				if (!sel) return;
				var html = '<option value="">- none</option>';
				for (var i = 0; i < departments.length; i++) {
					var d = departments[i];
					if (!d || !d.name) continue;
					html += '<option value="' + escAttr(d.name) + '">' + escHtml(d.name) + '</option>';
				}
				sel.innerHTML = html;
				if (keep) { sel.value = keep; }
			}

			function loadDepartments(root) {
				var sel = root.querySelector('[data-gs-agent-dept]');
				if (!sel || !homeGroup || !cfg.gsRoot) { resetDepartments(root); return; }
				resetDepartments(root);
				fetch(cfg.gsRoot + '/group-departments?group_id=' + encodeURIComponent(homeGroup), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var depts = (data && data.departments) || [];
						departments = depts.filter(function (x) { return x && x.name; });
						renderDepartments(root);
					})
					.catch(function () { /* leave just the "- none" option */ });
			}

			// "+ New": prompt for a name, POST it, then add to the options + select it.
			function addDepartment(root) {
				if (!homeGroup || !cfg.gsRoot) { return; }
				var nm = (typeof window !== 'undefined' && window.prompt) ? window.prompt('New department name') : '';
				nm = String(nm || '').trim();
				if (!nm) { return; }
				fetch(cfg.gsRoot + '/group-departments', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
					body: JSON.stringify({ group_id: homeGroup, name: nm })
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var created = (data && data.department) ? data.department : { id: nm, name: nm };
						var nextName = created.name || nm;
						// Dedupe by name, then re-render with the new one selected.
						var exists = false;
						for (var i = 0; i < departments.length; i++) {
							if (departments[i] && departments[i].name === nextName) { exists = true; break; }
						}
						if (!exists) { departments = departments.concat([created]); }
						renderDepartments(root, nextName);
					})
					.catch(function () {
						setStatus(root.querySelector('[data-gs-agent-status]'), 'Could not add the department.', 'error');
					});
			}

			/* ── Reports to (gs/v1/agent-list?group_id=homeGroup) ── */
			function resetReportsTo(root) {
				var sel = root.querySelector('[data-gs-agent-reportsto]');
				if (sel) sel.innerHTML = '<option value="">- root (no parent)</option>';
			}

			function loadReportsTo(root) {
				var sel = root.querySelector('[data-gs-agent-reportsto]');
				if (!sel || !homeGroup || !cfg.gsRoot) return;
				resetReportsTo(root);
				fetch(cfg.gsRoot + '/agent-list?group_id=' + encodeURIComponent(homeGroup), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var agents = (data && data.agents) || [];
						for (var i = 0; i < agents.length; i++) {
							var a = agents[i];
							if (!a || !a.slug) continue;
							var opt = document.createElement('option');
							opt.value = a.slug;
							opt.textContent = a.name || a.slug;
							sel.appendChild(opt);
						}
					})
					.catch(function () { /* leave just the root option */ });
			}

			/* ── Prompt-sequence builder ── */
			var seqCounter = 0;
			function makeStep() {
				seqCounter += 1;
				return { id: 's' + Date.now() + '_' + seqCounter, prompt: '', deviceId: '', ai: '', model: '', contextFiles: [], outputs: [] };
			}

			// v8.3 step-studio: hub model lists per AI integration, the device-local
			// "no metered cost" sentinel, and the client-side cost-rate table
			// (USD per 1K combined tokens). Estimates only — the runtime phase meters
			// the real spend server-side.
			var HUB_MODELS = ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'];
			var COST_RATES = {
				'gemini-2.5-flash': 0.003,
				'gemini-1.5-flash': 0.0005,
				'gemini-1.5-pro': 0.005
			};

			// v8.3 step-studio: load the home group's other sequences for the Context
			// Files + "Launch another sequence" pickers (gs/v1/group-sequences).
			function loadGroupSequences(seqRoot) {
				if (Array.isArray(groupSequences)) { if (seqRoot) refreshSeqSequencePickers(seqRoot); return; }
				if (groupSequencesLoading || !cfg.gsRoot || !homeGroup) { return; }
				groupSequencesLoading = true;
				fetch(cfg.gsRoot + '/group-sequences?group_id=' + encodeURIComponent(homeGroup), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						groupSequences = (data && Array.isArray(data.sequences)) ? data.sequences : [];
						if (seqPopupEl && seqPopupEl.classList.contains('is-open')) { refreshSeqSequencePickers(seqPopupEl); }
					})
					.catch(function () { groupSequences = []; })
					.finally(function () { groupSequencesLoading = false; });
			}

			// Re-fill every step's "+ Another sequence" + "Launch another sequence"
			// <select> from the (now-loaded) groupSequences, keeping selections.
			function refreshSeqSequencePickers(seqRoot) {
				if (!seqRoot) return;
				var list = Array.isArray(groupSequences) ? groupSequences : [];
				var ctxOpts = '<option value="">+ Another sequence…</option>';
				for (var i = 0; i < list.length; i++) {
					ctxOpts += '<option value="' + escAttr(list[i].id) + '">' + escHtml(list[i].name || list[i].id) + '</option>';
				}
				var ctxSelects = seqRoot.querySelectorAll('[data-gs-seq-ctx-seq]');
				for (var c = 0; c < ctxSelects.length; c++) { ctxSelects[c].innerHTML = ctxOpts; }
				// "Launch another sequence" output: the sequence picker is no longer a
				// flat list - it is driven by the Agent-then-Sequence cascade.
				refreshLaunchCascades(seqRoot);
			}

			// v8.3 step-studio refinement: load the home group's agents for the
			// "Launch another sequence" Agent-then-Sequence cascade (gs/v1/agent-list).
			function loadGroupAgents(seqRoot) {
				if (Array.isArray(groupAgents)) { if (seqRoot) refreshLaunchCascades(seqRoot); return; }
				if (groupAgentsLoading || !cfg.gsRoot || !homeGroup) { return; }
				groupAgentsLoading = true;
				fetch(cfg.gsRoot + '/agent-list?group_id=' + encodeURIComponent(homeGroup), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var list = (data && Array.isArray(data.agents)) ? data.agents : [];
						groupAgents = list.filter(function (a) { return a && a.slug; });
						if (seqPopupEl && seqPopupEl.classList.contains('is-open')) { refreshLaunchCascades(seqPopupEl); }
					})
					.catch(function () { groupAgents = []; })
					.finally(function () { groupAgentsLoading = false; });
			}

			// v8.3 step-studio refinement: load the home group's project workspaces
			// for the "Update Project" output (gs/v1/group-projects).
			function loadGroupProjects(seqRoot) {
				if (Array.isArray(groupProjects)) { if (seqRoot) refreshProjectSelects(seqRoot); return; }
				if (groupProjectsLoading || !cfg.gsRoot || !homeGroup) { return; }
				groupProjectsLoading = true;
				fetch(cfg.gsRoot + '/group-projects?group_id=' + encodeURIComponent(homeGroup), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var list = (data && Array.isArray(data.projects)) ? data.projects : [];
						groupProjects = list.filter(function (p) { return p && p.id != null; });
						if (seqPopupEl && seqPopupEl.classList.contains('is-open')) { refreshProjectSelects(seqPopupEl); }
					})
					.catch(function () { groupProjects = []; })
					.finally(function () { groupProjectsLoading = false; });
			}

			// v8.3 step-studio refinement: seed the member name/avatar cache once
			// (term-less group-members fetch) so already-selected recipient chips can
			// render name+avatar even before the member searches.
			function seedMemberCache(seqRoot) {
				if (memberCacheLoading || !cfg.gsRoot || !homeGroup) { return; }
				memberCacheLoading = true;
				fetch(cfg.gsRoot + '/group-members?group_id=' + encodeURIComponent(homeGroup) + '&term=', {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						cacheMembers((data && Array.isArray(data.members)) ? data.members : []);
						if (seqPopupEl && seqPopupEl.classList.contains('is-open')) { refreshRecipChips(seqPopupEl); }
					})
					.catch(function () {})
					.finally(function () { memberCacheLoading = false; });
			}

			// Merge a member result list into the id->{id,name,avatar} cache.
			function cacheMembers(members) {
				if (!Array.isArray(members)) { return; }
				for (var i = 0; i < members.length; i++) {
					var m = members[i];
					if (m && m.id != null) {
						memberCache[String(m.id)] = { id: m.id, name: m.name || ('Member ' + m.id), avatar: m.avatar || '' };
					}
				}
			}

			// Re-fill every rendered "Launch another sequence" cascade: the Agent
			// select from groupAgents and its Sequence select filtered from
			// groupSequences where agentSlug === the chosen agent_slug.
			function refreshLaunchCascades(seqRoot) {
				if (!seqRoot) return;
				var forms = seqRoot.querySelectorAll('[data-gs-seq-out-form="context_launch"]');
				for (var i = 0; i < forms.length; i++) {
					var agentSel = forms[i].querySelector('[data-gs-seq-out-launch-agent]');
					if (agentSel) {
						var keepA = agentSel.value;
						var agents = Array.isArray(groupAgents) ? groupAgents : [];
						var aOpts = '<option value="">- choose an agent</option>';
						for (var a = 0; a < agents.length; a++) {
							aOpts += '<option value="' + escAttr(agents[a].slug) + '">' + escHtml(agents[a].name || agents[a].slug) + '</option>';
						}
						agentSel.innerHTML = aOpts;
						if (keepA) { agentSel.value = keepA; }
					}
					var seqSel = forms[i].querySelector('[data-gs-seq-out-launch-seq]');
					fillLaunchSeqSelect(forms[i], seqSel ? seqSel.value : '');
				}
			}

			// Fill ONE launch-form's Sequence select from groupSequences filtered to
			// the form's chosen agent_slug; disabled until an agent is chosen.
			function fillLaunchSeqSelect(form, keepSeq) {
				if (!form) return;
				var agentSel = form.querySelector('[data-gs-seq-out-launch-agent]');
				var seqSel = form.querySelector('[data-gs-seq-out-launch-seq]');
				if (!seqSel) return;
				var picked = agentSel ? (agentSel.value || '') : '';
				var list = Array.isArray(groupSequences) ? groupSequences : [];
				var filtered = list.filter(function (gs) { return gs && String(gs.agentSlug) === String(picked); });
				var sOpts = '<option value="">' + (picked ? '- choose a sequence' : '- pick an agent first') + '</option>';
				for (var j = 0; j < filtered.length; j++) {
					sOpts += '<option value="' + escAttr(filtered[j].id) + '">' + escHtml(filtered[j].name || filtered[j].id) + '</option>';
				}
				seqSel.innerHTML = sOpts;
				seqSel.disabled = !picked;
				if (keepSeq) { seqSel.value = keepSeq; }
			}

			// Re-fill every rendered "Update Project" workspace select from
			// groupProjects, keeping each form's current selection.
			function refreshProjectSelects(seqRoot) {
				if (!seqRoot) return;
				var selects = seqRoot.querySelectorAll('[data-gs-seq-out-proj-select]');
				var list = Array.isArray(groupProjects) ? groupProjects : [];
				for (var i = 0; i < selects.length; i++) {
					var keep = selects[i].value;
					var opts = '<option value="">- select workspace</option>';
					for (var j = 0; j < list.length; j++) {
						opts += '<option value="' + escAttr(list[j].id) + '">' + escHtml(list[j].name || ('Workspace ' + list[j].id)) + '</option>';
					}
					selects[i].innerHTML = opts;
					if (keep) { selects[i].value = keep; }
				}
			}

			// Re-render the recipient chips of every rendered chat output sub-form
			// (used after the member cache is seeded / augmented).
			function refreshRecipChips(seqRoot) {
				if (!seqRoot) return;
				var boxes = seqRoot.querySelectorAll('[data-gs-seq-out-recip]');
				for (var i = 0; i < boxes.length; i++) {
					var store = boxes[i].querySelector('[data-gs-seq-out-recip-store]');
					renderRecipChips(boxes[i], readRecipIds(store));
				}
			}

			// Parse the hidden recipients store (a JSON array of ids).
			function readRecipIds(store) {
				if (!store) return [];
				try { var v = JSON.parse(store.value || '[]'); return Array.isArray(v) ? v : []; }
				catch (e) { return []; }
			}

			// Render the recipient chips (avatar+name+x) for one chat sub-form.
			function renderRecipChips(recipBox, ids) {
				var chips = recipBox && recipBox.querySelector('[data-gs-seq-out-recip-chips]');
				if (!chips) return;
				var html = '';
				for (var i = 0; i < ids.length; i++) {
					var m = memberCache[String(ids[i])] || { id: ids[i], name: 'Member ' + ids[i], avatar: '' };
					html += '<span class="gs-agent-out-recip__chip" data-gs-recip-id="' + escAttr(ids[i]) + '">'
						+ (m.avatar ? '<img class="gs-agent-out-recip__av" src="' + escAttr(m.avatar) + '" alt="" />' : '')
						+ '<span class="gs-agent-out-recip__name">' + escHtml(m.name) + '</span>'
						+ '<button type="button" class="gs-agent-out-recip__x" data-gs-recip-rm="' + escAttr(ids[i]) + '" aria-label="Remove">x</button>'
						+ '</span>';
				}
				chips.innerHTML = html;
			}

			// v8.3 step-studio refinement (chat output): run a member search for one
			// chat sub-form and render avatar+name result buttons (clicking one adds the
			// id). Results are cached so selected chips can resolve name+avatar later.
			function runMemberSearch(recipForm, term) {
				if (!recipForm) { return; }
				var resultsBox = recipForm.querySelector('[data-gs-seq-out-recip-results]');
				var clean = String(term || '').trim();
				if (!clean) { if (resultsBox) { resultsBox.innerHTML = ''; } return; }
				if (!cfg.gsRoot || !homeGroup) { if (resultsBox) { resultsBox.innerHTML = ''; } return; }
				fetch(cfg.gsRoot + '/group-members?group_id=' + encodeURIComponent(homeGroup) + '&term=' + encodeURIComponent(clean), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var members = (data && Array.isArray(data.members)) ? data.members : [];
						cacheMembers(members);
						if (!resultsBox) { return; }
						var html = '';
						for (var i = 0; i < members.length; i++) {
							var m = members[i] || {};
							html += '<button type="button" class="gs-agent-out-recip__result" data-gs-seq-out-recip-result data-gs-recip-id="' + escAttr(m.id) + '">'
								+ (m.avatar ? '<img class="gs-agent-out-recip__av" src="' + escAttr(m.avatar) + '" alt="" />' : '')
								+ '<span class="gs-agent-out-recip__name">' + escHtml(m.name || ('Member ' + m.id)) + '</span>'
								+ '</button>';
						}
						resultsBox.innerHTML = html;
					})
					.catch(function () { if (resultsBox) { resultsBox.innerHTML = ''; } });
			}

			// v8.3 step-studio refinement (project output): create a new project
			// workspace (prompt -> POST gs/v1/group-projects), add it to groupProjects,
			// re-fill the workspace selects, then select it in THIS form + persist.
			function createWorkspace(seqStepRow, step, projForm) {
				if (!cfg.gsRoot || !homeGroup) { return; }
				var name = (window.prompt('New workspace name') || '').trim();
				if (!name) { return; }
				fetch(cfg.gsRoot + '/group-projects', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '', 'Content-Type': 'application/json' },
					body: JSON.stringify({ group_id: homeGroup, title: name })
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var p = data && data.project;
						if (!p || p.id == null) { return; }
						groupProjects = Array.isArray(groupProjects) ? groupProjects : [];
						groupProjects.push({ id: p.id, name: p.name || name });
						if (seqPopupEl) { refreshProjectSelects(seqPopupEl); }
						var projSel = projForm ? projForm.querySelector('[data-gs-seq-out-proj-select]') : null;
						if (projSel) { projSel.value = String(p.id); }
						if (seqStepRow && step) { syncStepOutputsFromDom(seqStepRow, step); }
					})
					.catch(function () {});
			}

			// Build the "Prompt Runs At" <select> options for a step: "Gend.me (hub)"
			// first, then EVERY device the member has (psoo/v1/devices). Desktop
			// devices are selectable; mobile/server are disabled (coming soon).
			function stepDeviceOptions(selectedId) {
				var html = '<option value=""' + (selectedId ? '' : ' selected') + '>Gend.me (hub)</option>';
				var list = Array.isArray(allDevices) ? allDevices : [];
				for (var i = 0; i < list.length; i++) {
					var dev = list[i];
					if (!dev) continue;
					var ref = String(dev.id != null ? dev.id : dev.device_id);
					var isDesktop = dev.type === 'desktop';
					var lbl = (dev.label || ref) + (isDesktop ? '' : ' (coming soon)');
					html += '<option value="' + escAttr(ref) + '"'
						+ (isDesktop ? '' : ' disabled')
						+ (String(selectedId) === ref && isDesktop ? ' selected' : '') + '>'
						+ escHtml(lbl) + '</option>';
				}
				return html;
			}

			// Build the AI-integration <select> options given the chosen run target:
			// hub → single "Gemini (Vertex)" (id "gemini"); device → that device's
			// ai_integrations (disabled when available===false).
			function stepAiOptions(deviceId, selectedAi) {
				if (!deviceId) {
					return '<option value="gemini"' + (String(selectedAi) === 'gemini' || !selectedAi ? ' selected' : '') + '>Davinci Architect AI</option>';
				}
				var dev = deviceById(deviceId);
				var integrations = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations : [];
				if (!integrations.length) {
					return '<option value="">No integrations available on this device</option>';
				}
				var out = '';
				for (var j = 0; j < integrations.length; j++) {
					var integ = integrations[j];
					var id = (integ && integ.id != null) ? String(integ.id) : '';
					var label = (integ && integ.displayName) ? integ.displayName : id;
					var avail = !integ || integ.available !== false;
					out += '<option value="' + escAttr(id) + '"' + (avail ? '' : ' disabled')
						+ (String(selectedAi) === id && avail ? ' selected' : '') + '>'
						+ escHtml(label + (avail ? '' : ' (offline)')) + '</option>';
				}
				return out;
			}

			// Build the Model <select> options for a step: hub gemini → HUB_MODELS;
			// device integration → its models:[]. Store as step.model.
			function stepModelOptions(deviceId, aiId, selectedModel) {
				var models = [];
				if (!deviceId) {
					if (aiId === 'gemini') { models = HUB_MODELS.slice(); }
				} else {
					var dev = deviceById(deviceId);
					var integrations = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations : [];
					for (var k = 0; k < integrations.length; k++) {
						if (integrations[k] && String(integrations[k].id) === String(aiId)) {
							models = Array.isArray(integrations[k].models) ? integrations[k].models.slice() : [];
							break;
						}
					}
				}
				if (!models.length) {
					return '<option value="">No models available</option>';
				}
				var out = '<option value="">- choose a model</option>';
				for (var m = 0; m < models.length; m++) {
					var mv = String(models[m]);
					out += '<option value="' + escAttr(mv) + '"' + (String(selectedModel) === mv ? ' selected' : '') + '>' + escHtml(mv) + '</option>';
				}
				return out;
			}

			// Client-side estimated cost per run (estimate only — runtime meters real
			// spend later). Returns a display string; '' = hide the line.
			function stepCostText(step) {
				if (!step || !step.model) { return ''; }
				if (step.deviceId) { return 'Local — no metered cost'; }
				var rate = COST_RATES[step.model];
				if (typeof rate !== 'number') { return ''; }
				var inTok = Math.ceil((String(step.prompt || '').length) / 4);
				var totalK = (inTok + 600) / 1000;
				var cost = totalK * rate;
				var shown = cost < 0.001 ? cost.toFixed(5) : cost.toFixed(4);
				return 'Est. ~$' + shown + ' / run (estimate)';
			}

			// Reflect a step's cascade visibility (AI → Model) + cost line into a row.
			function applyStepCascade(row, step) {
				if (!row || !step) return;
				var aiWrap = row.querySelector('[data-gs-seq-ai-wrap]');
				var modelWrap = row.querySelector('[data-gs-seq-model-wrap]');
				var costEl = row.querySelector('[data-gs-seq-cost]');
				var aiSel = row.querySelector('[data-gs-seq-ai]');
				var modelSel = row.querySelector('[data-gs-seq-model]');
				// A run target is always chosen (hub is deviceId === ''), so AI shows once
				// the row exists. Default the hub AI to 'gemini' if unset.
				if (!step.deviceId && !step.ai) { step.ai = 'gemini'; }
				if (aiWrap) aiWrap.hidden = false;
				if (aiSel) aiSel.innerHTML = stepAiOptions(step.deviceId, step.ai);
				if (modelWrap) modelWrap.hidden = !step.ai;
				if (modelSel) modelSel.innerHTML = stepModelOptions(step.deviceId, step.ai, step.model);
				var costText = stepCostText(step);
				if (costEl) {
					costEl.textContent = costText;
					costEl.hidden = !costText;
				}
			}

			// Render the Context Files chips for a step.
			function renderStepCtxChips(row, step) {
				var box = row && row.querySelector('[data-gs-seq-ctx-chips]');
				if (!box) return;
				var files = Array.isArray(step.contextFiles) ? step.contextFiles : [];
				var html = '';
				for (var i = 0; i < files.length; i++) {
					var f = files[i] || {};
					html += '<span class="gs-agent-ctx-chip" data-gs-seq-ctx-chip="' + i + '">'
						+ '<span class="gs-agent-ctx-chip__label">' + escHtml(f.label || f.type || '') + '</span>'
						+ '<button type="button" class="gs-agent-ctx-chip__rm" data-gs-seq-ctx-rm="' + i + '" aria-label="Remove">×</button>'
						+ '</span>';
				}
				box.innerHTML = html;
				var brainBtn = row.querySelector('[data-gs-seq-ctx-brain]');
				if (brainBtn) {
					var hasBrain = files.some(function (x) { return x && x.type === 'brain'; });
					brainBtn.classList.toggle('is-on', hasBrain);
				}
			}

			// Render the response-action checkboxes + settings sub-forms for a step.
			function renderStepOutputs(seqRoot, row, step) {
				var opts = row && row.querySelectorAll('[data-gs-seq-out-type]');
				var formsBox = row && row.querySelector('[data-gs-seq-out-forms]');
				if (!opts || !formsBox) return;
				var outputs = Array.isArray(step.outputs) ? step.outputs : [];
				formsBox.innerHTML = '';
				for (var i = 0; i < opts.length; i++) {
					var type = opts[i].getAttribute('data-gs-seq-out-type');
					var existing = null;
					for (var j = 0; j < outputs.length; j++) { if (outputs[j] && outputs[j].type === type) { existing = outputs[j]; break; } }
					opts[i].checked = !!existing;
					if (existing) {
						appendOutputForm(seqRoot, formsBox, type, existing.settings || {});
					}
				}
			}

			// Clone + fill one output settings sub-form into the forms box. Each output
				// type has its own field shape (mirrors the chat-widget):
				//   email          : to / subject / body (textarea)
				//   chat           : recipients[] (hidden JSON store + chips/search)
				//   project        : project (workspace select) + task_title
				//   chain          : (no fields)
				//   context_launch : agent_slug (select) + sequence_id (cascade select)
			function appendOutputForm(seqRoot, formsBox, type, settings) {
				var tpl = seqRoot.querySelector('[data-gs-seq-out-' + type + '-tpl]');
				if (!tpl) return;
				settings = settings || {};
				var frag = tpl.content.cloneNode(true);
				var formEl = frag.querySelector('[data-gs-seq-out-form]');
				var fields = frag.querySelectorAll('[data-gs-seq-out-field]');
				for (var i = 0; i < fields.length; i++) {
					var key = fields[i].getAttribute('data-gs-seq-out-field');
					// recipients[] lives in a hidden JSON store + a chips/search UI; the
					// generic value-set below would stringify the array, so seed it as JSON.
					if (key === 'recipients') {
						var recips = Array.isArray(settings.recipients)
							? settings.recipients
							: (settings.recipient ? [settings.recipient] : []); // legacy single-recipient migration.
						fields[i].value = JSON.stringify(recips);
						continue;
					}
					// SELECT fields (project / agent_slug / sequence_id) are filled by the
					// dedicated refreshers below — skip the generic value-set for them.
					if (settings[key] != null && fields[i].tagName !== 'SELECT') {
						fields[i].value = settings[key];
					}
				}
				formsBox.appendChild(frag);
				// Post-append wiring (the fragment is now live in the DOM as formEl).
				var live = (formEl && formsBox.contains(formEl)) ? formEl : null;
				if (!live) { return; }
				if (type === 'project') {
					// Fill the workspace <select> + restore the chosen project id.
					refreshProjectSelects(seqRoot);
					var projSel = live.querySelector('[data-gs-seq-out-proj-select]');
					if (projSel && settings.project != null) { projSel.value = String(settings.project); }
				} else if (type === 'context_launch') {
					// Restore the chosen agent, then build + restore the sequence cascade.
					refreshLaunchCascades(seqRoot);
					var agentSel = live.querySelector('[data-gs-seq-out-launch-agent]');
					if (agentSel && settings.agent_slug != null) { agentSel.value = String(settings.agent_slug); }
					fillLaunchSeqSelect(live, settings.sequence_id != null ? String(settings.sequence_id) : '');
				} else if (type === 'chat') {
					// Render the recipient chips from the (possibly cached) member info.
					var recipBox = live.querySelector('[data-gs-seq-out-recip]');
					var store = live.querySelector('[data-gs-seq-out-recip-store]');
					if (recipBox) { renderRecipChips(recipBox, readRecipIds(store)); }
				}
			}

			function renderSeq(seqRoot) {
				var list = seqRoot.querySelector('[data-gs-seq-list]');
				var stepTpl = seqRoot.querySelector('[data-gs-seq-step-tpl]');
				if (!list || !stepTpl) return;
				list.innerHTML = '';
				for (var i = 0; i < seqSteps.length; i++) {
					(function (step, idx) {
						var frag = stepTpl.content.cloneNode(true);
						var row = frag.querySelector('[data-gs-seq-step]');
						if (!row) return;
						row.setAttribute('data-step-id', step.id);
						var num = row.querySelector('[data-gs-seq-num]');
						if (num) num.textContent = String(idx + 1);
						var prompt = row.querySelector('[data-gs-seq-prompt]');
						if (prompt) prompt.value = step.prompt || '';
						var devSel = row.querySelector('[data-gs-seq-device]');
						if (devSel) devSel.innerHTML = stepDeviceOptions(step.deviceId);
						applyStepCascade(row, step);
						renderStepCtxChips(row, step);
						renderGdriveControl(row, step);
						renderStepOutputs(seqRoot, row, step);
						list.appendChild(frag);
					})(seqSteps[i], i);
				}
				// Fill the Context Files "+ Another sequence" + launch-sequence pickers.
				refreshSeqSequencePickers(seqRoot);
			}

			// When the member's device list arrives (fetched once on open), refresh
			// every step's device <select> (keeping its selection if still a known
			// device) without losing prompts.
			function refreshSeqDeviceSelects(seqRoot) {
				if (!seqRoot) return;
				var rows = seqRoot.querySelectorAll('[data-gs-seq-step]');
				for (var i = 0; i < rows.length; i++) {
					var id = rows[i].getAttribute('data-step-id');
					var step = seqStepById(id);
					if (!step) continue;
					// If the step targeted a device that is no longer known, reset to hub.
					if (step.deviceId && !deviceById(step.deviceId)) {
						step.deviceId = '';
						step.ai = '';
						step.model = '';
					}
					var devSel = rows[i].querySelector('[data-gs-seq-device]');
					if (devSel) devSel.innerHTML = stepDeviceOptions(step.deviceId);
					applyStepCascade(rows[i], step);
				}
			}

			function seqStepById(id) {
				for (var i = 0; i < seqSteps.length; i++) { if (seqSteps[i].id === id) return seqSteps[i]; }
				return null;
			}

			// Pull current DOM values back into the seqSteps model (so reorders/
			// removes/submit see the latest typed text + selections). Context Files +
			// output toggles are kept in the model on each interaction; this reconciles
			// the prompt text, cascade selects, and per-output settings fields.
			function syncSeqFromDom(seqRoot) {
				var rows = seqRoot.querySelectorAll('[data-gs-seq-step]');
				for (var i = 0; i < rows.length; i++) {
					var step = seqStepById(rows[i].getAttribute('data-step-id'));
					if (!step) continue;
					var prompt = rows[i].querySelector('[data-gs-seq-prompt]');
					var devSel = rows[i].querySelector('[data-gs-seq-device]');
					var aiSel = rows[i].querySelector('[data-gs-seq-ai]');
					var aiWrap = rows[i].querySelector('[data-gs-seq-ai-wrap]');
					var modelSel = rows[i].querySelector('[data-gs-seq-model]');
					var modelWrap = rows[i].querySelector('[data-gs-seq-model-wrap]');
					if (prompt) step.prompt = prompt.value || '';
					if (devSel) step.deviceId = devSel.value || '';
					if (aiSel && aiWrap && !aiWrap.hidden) step.ai = aiSel.value || '';
					if (modelSel && modelWrap && !modelWrap.hidden) step.model = modelSel.value || '';
					syncStepOutputsFromDom(rows[i], step);
				}
			}

			// Read each rendered output sub-form's fields back into step.outputs (only
			// the currently-checked outputs have a sub-form rendered).
			function syncStepOutputsFromDom(row, step) {
				var forms = row.querySelectorAll('[data-gs-seq-out-form]');
				var byType = {};
				var current = Array.isArray(step.outputs) ? step.outputs : [];
				for (var c = 0; c < current.length; c++) { if (current[c]) byType[current[c].type] = current[c]; }
				var next = [];
				for (var i = 0; i < forms.length; i++) {
					var type = forms[i].getAttribute('data-gs-seq-out-form');
					var out = byType[type] || { type: type, settings: {} };
					out.settings = out.settings || {};
					var fields = forms[i].querySelectorAll('[data-gs-seq-out-field]');
					for (var f = 0; f < fields.length; f++) {
						var key = fields[f].getAttribute('data-gs-seq-out-field');
						// recipients[] is persisted as an array (parsed from its hidden JSON
						// store) rather than the raw string value.
						if (key === 'recipients') {
							out.settings.recipients = readRecipIds(fields[f]);
							continue;
						}
						out.settings[key] = fields[f].value || '';
					}
					next.push(out);
				}
				step.outputs = next;
			}

			/* ── Sequence LIST (inside the agent popup) ── */
			function seqById(id) {
				for (var i = 0; i < sequences.length; i++) { if (sequences[i].id === id) return sequences[i]; }
				return null;
			}

			function renderSeqList(root) {
				var list = root.querySelector('[data-gs-seqlist]');
				var rowTpl = root.querySelector('[data-gs-seqrow-tpl]');
				if (!list || !rowTpl) return;
				list.innerHTML = '';
				if (!sequences.length) {
					list.innerHTML = '<p class="gs-agent-help gs-agent-seqlist__empty">No sequences yet — add one to define what this agent does.</p>';
					return;
				}
				for (var i = 0; i < sequences.length; i++) {
					(function (seq) {
						var frag = rowTpl.content.cloneNode(true);
						var row = frag.querySelector('[data-gs-seqrow]');
						if (!row) return;
						row.setAttribute('data-seq-id', seq.id);
						var nameEl = row.querySelector('[data-gs-seqrow-name]');
						if (nameEl) nameEl.textContent = seq.name || 'Untitled sequence';
						var countEl = row.querySelector('[data-gs-seqrow-count]');
						var n = (seq.prompts && seq.prompts.length) ? seq.prompts.length : 0;
						if (countEl) countEl.textContent = n + (n === 1 ? ' step' : ' steps');
						list.appendChild(frag);
					})(sequences[i]);
				}
			}

			/* ── Nested step-builder popup ── */
			function injectSeqPopup() {
				if (seqPopupEl && document.body.contains(seqPopupEl)) return seqPopupEl;
				var tpl = document.getElementById('gs-seq-popup-template');
				if (!tpl) return null;
				var frag = tpl.content.cloneNode(true);
				document.body.appendChild(frag);
				seqPopupEl = document.querySelector('.gs-seq-popup');
				bindSeqPopup(seqPopupEl);
				return seqPopupEl;
			}

			function closeSeqPopup() {
				if (seqPopupEl) seqPopupEl.classList.remove('is-open');
				seqEditingId = null;
			}

			// Open the nested popup to ADD a new sequence (editId null) or EDIT one.
			function openSeqPopup(editId) {
				var seqRoot = injectSeqPopup();
				if (!seqRoot) return;
				seqEditingId = editId || null;
				var nameInput = seqRoot.querySelector('[data-gs-seq-name]');
				var descInput = seqRoot.querySelector('[data-gs-seq-desc]');
				setStatus(seqRoot.querySelector('[data-gs-seq-status]'), '', '');

				// v8.3 step-studio: ensure the home group's other sequences are available
				// for the Context Files + "Launch another sequence" pickers.
				loadGroupSequences(seqRoot);
				// v8.3 step-studio refinement: also load the home group's agents (launch
				// cascade), project workspaces (project output) + seed the member cache
				// (chat recipient chips). All guarded; degrade to empty.
				loadGroupAgents(seqRoot);
				loadGroupProjects(seqRoot);
				seedMemberCache(seqRoot);

				if (seqEditingId) {
					var seq = seqById(seqEditingId);
					if (nameInput) nameInput.value = (seq && seq.name) ? seq.name : '';
					if (descInput) descInput.value = (seq && seq.description) ? seq.description : '';
					// Map the stored prompts back into the working step shape.
					seqSteps = (seq && seq.prompts && seq.prompts.length)
						? seq.prompts.map(function (p) {
							return {
								id: (p.id || makeStep().id),
								prompt: p.text || '',
								deviceId: p.deviceRef || '',
								ai: p.aiIntegration || '',
								model: p.model || '',
								contextFiles: Array.isArray(p.contextFiles) ? p.contextFiles.map(function (c) { return { type: c.type, ref: c.ref, label: c.label, url: c.url }; }) : [],
								outputs: Array.isArray(p.outputs) ? p.outputs.map(function (o) { return { type: o.type, settings: (o.settings && typeof o.settings === 'object') ? o.settings : {} }; }) : []
							};
						})
						: [makeStep()];
				} else {
					if (nameInput) nameInput.value = '';
					if (descInput) descInput.value = '';
					seqSteps = [makeStep()];
				}
				renderSeq(seqRoot);
				seqRoot.classList.add('is-open');
			}

			// Save (create or update) the open nested popup's sequence into the model.
			function saveSeqPopup() {
				var seqRoot = seqPopupEl;
				if (!seqRoot) return;
				syncSeqFromDom(seqRoot);
				var nameInput = seqRoot.querySelector('[data-gs-seq-name]');
				var descInput = seqRoot.querySelector('[data-gs-seq-desc]');
				var name = nameInput ? (nameInput.value || '').trim() : '';
				var description = descInput ? (descInput.value || '').trim() : '';

				// Map the working steps into the persisted prompt shape (per-step:
				// id, text, runTarget, targetRef, aiIntegration, model, contextFiles,
				// outputs). runTarget is 'device' when a device is chosen, else 'gendme'.
				var prompts = seqSteps.map(function (s) {
					var devRef = s.deviceId || '';
					return {
						id: s.id,
						text: s.prompt || '',
						runTarget: devRef ? 'device' : 'gendme',
						targetRef: devRef,
						deviceRef: devRef,
						aiIntegration: s.ai || '',
						model: s.model || '',
						contextFiles: Array.isArray(s.contextFiles) ? s.contextFiles : [],
						outputs: Array.isArray(s.outputs) ? s.outputs : []
					};
				});

				if (seqEditingId) {
					var existing = seqById(seqEditingId);
					if (existing) {
						existing.name = name || existing.name || ('Sequence ' + sequences.length);
						existing.description = description;
						existing.prompts = prompts;
					}
				} else {
					seqCounterId += 1;
					var newId = 'seq_' + Date.now() + '_' + seqCounterId;
					sequences.push({
						id: newId,
						name: name || ('Sequence ' + (sequences.length + 1)),
						description: description,
						prompts: prompts
					});
				}
				closeSeqPopup();
				if (popupEl) renderSeqList(popupEl);
			}

			function bindSeqPopup(seqRoot) {
				if (!seqRoot) return;

				seqRoot.addEventListener('click', function (e) {
					var t = e.target;
					if (!t || !t.closest) return;

					// Close / cancel — only this nested popup, never the agent popup.
					if (t.closest('[data-gs-seq-close]') || t.closest('[data-gs-seq-cancel]')) {
						closeSeqPopup();
						return;
					}
					// Save sequence.
					if (t.closest('[data-gs-seq-save]')) { saveSeqPopup(); return; }

					// Add step.
					if (t.closest('[data-gs-seq-add]')) {
						syncSeqFromDom(seqRoot);
						seqSteps.push(makeStep());
						renderSeq(seqRoot);
						return;
					}

					// Per-step controls (up / down / remove + context files).
					var seqStepRow = t.closest('[data-gs-seq-step]');
					if (seqStepRow) {
						var stepId = seqStepRow.getAttribute('data-step-id');
						var idx = -1;
						for (var i = 0; i < seqSteps.length; i++) { if (seqSteps[i].id === stepId) { idx = i; break; } }
						if (idx < 0) return;
						var curStep = seqSteps[idx];

						// Context Files: "+ Business brain" toggle (single; toggle removes).
						if (t.closest('[data-gs-seq-ctx-brain]')) {
							curStep.contextFiles = Array.isArray(curStep.contextFiles) ? curStep.contextFiles : [];
							var bi = -1;
							for (var b = 0; b < curStep.contextFiles.length; b++) { if (curStep.contextFiles[b] && curStep.contextFiles[b].type === 'brain') { bi = b; break; } }
							if (bi >= 0) { curStep.contextFiles.splice(bi, 1); }
							else { curStep.contextFiles.push({ type: 'brain', ref: String(homeGroup != null ? homeGroup : ''), label: 'Business brain', url: '' }); }
							renderStepCtxChips(seqStepRow, curStep);
							return;
						}
						// Context Files: "Upload file" → open the hidden file input.
						if (t.closest('[data-gs-seq-ctx-upload]')) {
							var fileInput = seqStepRow.querySelector('[data-gs-seq-ctx-fileinput]');
							if (fileInput) { fileInput.click(); }
							return;
						}
						// Context Files: Google Drive "+ Add" (paste-link fallback) → reuse
						// the shared gdrive chip path. Present in the NOT-configured and
						// connected states.
						if (t.closest('[data-gs-seq-ctx-gdrive-add]')) {
							var urlInput = seqStepRow.querySelector('[data-gs-seq-ctx-gdrive]');
							var link = urlInput ? (urlInput.value || '').trim() : '';
							if (!link) { return; }
							addGdriveContextFile(seqStepRow, curStep, '', 'Google Drive doc', link);
							if (urlInput) { urlInput.value = ''; }
							return;
						}
						// Context Files: Google Drive "Connect" → open the consent popup +
						// install the one-time connected listener.
						if (t.closest('[data-gs-seq-ctx-gdrive-connect]')) {
							startGdriveConnect();
							return;
						}
						// Context Files: a Google Drive search result clicked → add it as a
						// gdrive chip (same path as paste-link), then clear the search box +
						// its results.
						var gdrivePick = t.closest('[data-gs-seq-ctx-gdrive-pick]');
						if (gdrivePick) {
							var pickId = gdrivePick.getAttribute('data-gs-seq-ctx-gdrive-pick') || '';
							var pickName = gdrivePick.getAttribute('data-gdrive-name') || 'Google Drive doc';
							addGdriveContextFile(seqStepRow, curStep, pickId, pickName, '');
							var searchInput = seqStepRow.querySelector('[data-gs-seq-ctx-gdrive-search]');
							if (searchInput) { searchInput.value = ''; }
							var resultsBox = seqStepRow.querySelector('[data-gs-seq-ctx-gdrive-results]');
							if (resultsBox) { resultsBox.innerHTML = ''; }
							return;
						}
						// Context Files: chip "×" → remove that file by index.
						var chipRm = t.closest('[data-gs-seq-ctx-rm]');
						if (chipRm) {
							var ri = parseInt(chipRm.getAttribute('data-gs-seq-ctx-rm'), 10);
							if (!isNaN(ri) && Array.isArray(curStep.contextFiles)) {
								curStep.contextFiles.splice(ri, 1);
								renderStepCtxChips(seqStepRow, curStep);
							}
							return;
						}

						// v8.3 step-studio refinement (chat output): a member search RESULT
						// clicked → add its id to the recipients[] store (dedupe), clear the
						// search, re-render chips, and persist into the step model.
						var recipPick = t.closest('[data-gs-seq-out-recip-result]');
						if (recipPick) {
							var addId = recipPick.getAttribute('data-gs-recip-id') || '';
							var recipForm = recipPick.closest('[data-gs-seq-out-recip]');
							if (addId && recipForm) {
								var addStore = recipForm.querySelector('[data-gs-seq-out-recip-store]');
								var addIds = readRecipIds(addStore);
								if (!addIds.some(function (r) { return String(r) === String(addId); })) { addIds.push(addId); }
								if (addStore) { addStore.value = JSON.stringify(addIds); }
								var addSearch = recipForm.querySelector('[data-gs-seq-out-recip-search]');
								if (addSearch) { addSearch.value = ''; }
								var addResults = recipForm.querySelector('[data-gs-seq-out-recip-results]');
								if (addResults) { addResults.innerHTML = ''; }
								renderRecipChips(recipForm, addIds);
								syncStepOutputsFromDom(seqStepRow, curStep);
							}
							return;
						}
						// Chat output: a recipient chip "×" → remove that id, re-render, persist.
						var recipRm = t.closest('[data-gs-recip-rm]');
						if (recipRm) {
							var rmId = recipRm.getAttribute('data-gs-recip-rm') || '';
							var rmForm = recipRm.closest('[data-gs-seq-out-recip]');
							if (rmForm) {
								var rmStore = rmForm.querySelector('[data-gs-seq-out-recip-store]');
								var rmIds = readRecipIds(rmStore).filter(function (r) { return String(r) !== String(rmId); });
								if (rmStore) { rmStore.value = JSON.stringify(rmIds); }
								renderRecipChips(rmForm, rmIds);
								syncStepOutputsFromDom(seqStepRow, curStep);
							}
							return;
						}
						// v8.3 step-studio refinement (project output): "+ New workspace" →
						// prompt for a name, POST gs/v1/group-projects, then add+select it.
						if (t.closest('[data-gs-seq-out-proj-new]')) {
							var projForm = t.closest('[data-gs-seq-out-form]');
							createWorkspace(seqStepRow, curStep, projForm);
							return;
						}

						if (t.closest('[data-gs-seq-remove]')) {
							syncSeqFromDom(seqRoot);
							seqSteps.splice(idx, 1);
							if (!seqSteps.length) { seqSteps.push(makeStep()); }
							renderSeq(seqRoot);
							return;
						}
						if (t.closest('[data-gs-seq-up]') && idx > 0) {
							syncSeqFromDom(seqRoot);
							var tmpUp = seqSteps[idx - 1]; seqSteps[idx - 1] = seqSteps[idx]; seqSteps[idx] = tmpUp;
							renderSeq(seqRoot);
							return;
						}
						if (t.closest('[data-gs-seq-down]') && idx < seqSteps.length - 1) {
							syncSeqFromDom(seqRoot);
							var tmpDn = seqSteps[idx + 1]; seqSteps[idx + 1] = seqSteps[idx]; seqSteps[idx] = tmpDn;
							renderSeq(seqRoot);
							return;
						}
					}
				});

				// Per-step cascade + context + output changes.
				seqRoot.addEventListener('change', function (e) {
					var t = e.target;
					if (!t || !t.matches) return;
					var row = t.closest('[data-gs-seq-step]');

					// "Prompt Runs At" changed → reset AI/model, reveal AI, rebuild model.
					if (t.matches('[data-gs-seq-device]')) {
						if (!row) return;
						var step = seqStepById(row.getAttribute('data-step-id'));
						if (!step) return;
						step.deviceId = t.value || '';
						step.ai = '';
						step.model = '';
						applyStepCascade(row, step);
						return;
					}
					// AI integration changed → reset model, reveal/rebuild model + cost.
					if (t.matches('[data-gs-seq-ai]')) {
						if (!row) return;
						var stepA = seqStepById(row.getAttribute('data-step-id'));
						if (!stepA) return;
						stepA.ai = t.value || '';
						stepA.model = '';
						applyStepCascade(row, stepA);
						return;
					}
					// Model changed → store + refresh cost line.
					if (t.matches('[data-gs-seq-model]')) {
						if (!row) return;
						var stepM = seqStepById(row.getAttribute('data-step-id'));
						if (!stepM) return;
						stepM.model = t.value || '';
						var costEl = row.querySelector('[data-gs-seq-cost]');
						var ct = stepCostText(stepM);
						if (costEl) { costEl.textContent = ct; costEl.hidden = !ct; }
						return;
					}
					// Context Files: "+ Another sequence" select → add a sequence file.
					if (t.matches('[data-gs-seq-ctx-seq]')) {
						if (!row) return;
						var stepS = seqStepById(row.getAttribute('data-step-id'));
						if (!stepS || !t.value) return;
						var list = Array.isArray(groupSequences) ? groupSequences : [];
						var picked = null;
						for (var p = 0; p < list.length; p++) { if (String(list[p].id) === String(t.value)) { picked = list[p]; break; } }
						stepS.contextFiles = Array.isArray(stepS.contextFiles) ? stepS.contextFiles : [];
						stepS.contextFiles.push({ type: 'sequence', ref: String(t.value), label: picked ? (picked.name || String(t.value)) : String(t.value), url: '' });
						t.value = '';
						renderStepCtxChips(row, stepS);
						return;
					}
					// Context Files: file input chosen → upload, then add an upload file.
					if (t.matches('[data-gs-seq-ctx-fileinput]')) {
						if (!row || !t.files || !t.files.length) return;
						var stepU = seqStepById(row.getAttribute('data-step-id'));
						if (!stepU) return;
						uploadContextFile(row, stepU, t.files[0]);
						t.value = '';
						return;
					}
					// Response actions: a checkbox toggled → add/remove its output.
					if (t.matches('[data-gs-seq-out-type]')) {
						if (!row) return;
						var stepO = seqStepById(row.getAttribute('data-step-id'));
						if (!stepO) return;
						var type = t.getAttribute('data-gs-seq-out-type');
						stepO.outputs = Array.isArray(stepO.outputs) ? stepO.outputs : [];
						if (t.checked) {
							var has = stepO.outputs.some(function (o) { return o && o.type === type; });
							if (!has) { stepO.outputs.push({ type: type, settings: {} }); }
							// v8.3 step-studio refinement (chain): when "Use in next step" is
							// turned on for the LAST step, auto-append a follow-up step whose
							// prompt starts with "{{previous}}\n\n" so the member immediately
							// continues writing the prompt that consumes this output. Only if a
							// next step does not already exist. The chain output entry is kept.
							if (type === 'chain') {
								var stepIdx = -1;
								for (var si = 0; si < seqSteps.length; si++) { if (seqSteps[si] === stepO) { stepIdx = si; break; } }
								if (stepIdx === seqSteps.length - 1) {
									syncSeqFromDom(seqRoot);
									var follow = makeStep();
									follow.prompt = '{{previous}}\n\n';
									seqSteps.push(follow);
									renderSeq(seqRoot);
									return;
								}
							}
						} else {
							// Pull the latest settings into the model BEFORE removing the form.
							syncStepOutputsFromDom(row, stepO);
							stepO.outputs = stepO.outputs.filter(function (o) { return o && o.type !== type; });
						}
						var formsBox = row.querySelector('[data-gs-seq-out-forms]');
						if (formsBox) { renderStepOutputs(seqRoot, row, stepO); }
						return;
					}
					// v8.3 step-studio refinement (launch cascade): Agent <select> changed →
					// persist agent_slug, reset sequence_id, rebuild the filtered Sequence
					// <select> (enabled only once an agent is chosen).
					if (t.matches('[data-gs-seq-out-launch-agent]')) {
						if (!row) return;
						var stepLA = seqStepById(row.getAttribute('data-step-id'));
						if (!stepLA) return;
						var formLA = t.closest('[data-gs-seq-out-form]');
						if (formLA) {
							var seqSelLA = formLA.querySelector('[data-gs-seq-out-launch-seq]');
							if (seqSelLA) { seqSelLA.value = ''; }
							fillLaunchSeqSelect(formLA, '');
						}
						syncStepOutputsFromDom(row, stepLA);
						return;
					}
					// Launch cascade: Sequence <select> changed → persist sequence_id.
					if (t.matches('[data-gs-seq-out-launch-seq]')) {
						if (!row) return;
						var stepLS = seqStepById(row.getAttribute('data-step-id'));
						if (stepLS) { syncStepOutputsFromDom(row, stepLS); }
						return;
					}
					// "Update Project" workspace <select> changed → persist project id.
					if (t.matches('[data-gs-seq-out-proj-select]')) {
						if (!row) return;
						var stepPS = seqStepById(row.getAttribute('data-step-id'));
						if (stepPS) { syncStepOutputsFromDom(row, stepPS); }
						return;
					}
				});

				// Prompt text typed → keep step.prompt fresh + refresh the cost estimate
				// (input-token component of the estimate depends on the prompt length).
				// Google Drive search typed → debounced (~300ms) gs/v1/gdrive/files.
				seqRoot.addEventListener('input', function (e) {
					var t = e.target;
					if (!t || !t.matches) return;
					var row = t.closest('[data-gs-seq-step]');
					if (!row) return;
					var step = seqStepById(row.getAttribute('data-step-id'));
					if (!step) return;

					if (t.matches('[data-gs-seq-prompt]')) {
						step.prompt = t.value || '';
						var costEl = row.querySelector('[data-gs-seq-cost]');
						var ct = stepCostText(step);
						if (costEl) { costEl.textContent = ct; costEl.hidden = !ct; }
						return;
					}

					// v8.3 step-studio refinement (chat output): member recipient search,
					// debounced (~300ms) → gs/v1/group-members?group_id&term. Results are
					// rendered as avatar+name buttons that add the id on click.
					if (t.matches('[data-gs-seq-out-recip-search]')) {
						var recipForm = t.closest('[data-gs-seq-out-recip]');
						var term2 = t.value || '';
						var rkey = step.id;
						if (memberSearchTimers[rkey]) { clearTimeout(memberSearchTimers[rkey]); }
						memberSearchTimers[rkey] = setTimeout(function () {
							memberSearchTimers[rkey] = null;
							runMemberSearch(recipForm, term2);
						}, 300);
						return;
					}

					if (t.matches('[data-gs-seq-ctx-gdrive-search]')) {
						var term = t.value || '';
						var key = step.id;
						if (gdriveSearchTimers[key]) { clearTimeout(gdriveSearchTimers[key]); }
						gdriveSearchTimers[key] = setTimeout(function () {
							gdriveSearchTimers[key] = null;
							runGdriveSearch(row, step, term);
						}, 300);
						return;
					}
				});
			}

			// Upload a Context File for a step → POST multipart to the group media
			// endpoint, then add an {type:'upload'} file to the step's contextFiles.
			function uploadContextFile(row, step, file) {
				if (!cfg.psooRoot || !homeGroup || !file) { return; }
				var status = seqPopupEl ? seqPopupEl.querySelector('[data-gs-seq-status]') : null;
				setStatus(status, 'Uploading file…', 'info');
				var fd = new FormData();
				fd.append('file', file);
				fetch(cfg.psooRoot + '/groups/' + encodeURIComponent(homeGroup) + '/media/upload', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' },
					body: fd
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						var id = (data && data.id != null) ? String(data.id) : '';
						var url = (data && data.source_url) ? String(data.source_url) : '';
						step.contextFiles = Array.isArray(step.contextFiles) ? step.contextFiles : [];
						step.contextFiles.push({ type: 'upload', ref: id, label: file.name || 'upload', url: url });
						renderStepCtxChips(row, step);
						setStatus(status, '', '');
					})
					.catch(function () { setStatus(status, 'Could not upload the file.', 'error'); });
			}

			/* ── Context Files → Google Drive picker (mirrors the chat-widget) ── */

			// Fetch gs/v1/gdrive/status ONCE per agent-popup open into `gdriveStatus`,
			// then re-render the Drive control in every open step row. Fully guarded:
			// any failure leaves gdriveStatus null so the control degrades to the
			// paste-link default. Re-entrancy guarded via gdriveStatusLoading.
			function loadGdriveStatus() {
				if (gdriveStatus && typeof gdriveStatus === 'object') { renderAllGdriveControls(); return; }
				if (gdriveStatusLoading || !cfg.gsRoot) { renderAllGdriveControls(); return; }
				gdriveStatusLoading = true;
				fetch(cfg.gsRoot + '/gdrive/status', {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						gdriveStatus = {
							configured: !!(data && data.configured),
							connected: !!(data && data.connected)
						};
					})
					.catch(function () { gdriveStatus = null; })
					.finally(function () {
						gdriveStatusLoading = false;
						renderAllGdriveControls();
					});
			}

			// Re-render the Drive control for every step row currently in the nested popup.
			function renderAllGdriveControls() {
				if (!seqPopupEl) { return; }
				var rows = seqPopupEl.querySelectorAll('[data-gs-seq-step]');
				for (var i = 0; i < rows.length; i++) {
					var step = seqStepById(rows[i].getAttribute('data-step-id'));
					if (step) { renderGdriveControl(rows[i], step); }
				}
			}

			// Render the Drive control for ONE step row, choosing the state from
			// gdriveStatus. The control lives in [data-gs-seq-ctx-gdrive-wrap]; this
			// fully replaces its innerHTML each call (idempotent + re-render-safe).
			//   - status unknown / NOT configured → paste-link input (degrade-safe).
			//   - configured + NOT connected      → "Connect Google Drive" button.
			//   - configured + connected          → search input + results + paste fallback.
			function renderGdriveControl(row, step) {
				var wrap = row && row.querySelector('[data-gs-seq-ctx-gdrive-wrap]');
				if (!wrap) { return; }
				var st = gdriveStatus;

				// Paste-link block — reused in the NOT-configured + connected states.
				var pasteHtml = ''
					+ '<div class="gs-agent-ctx-gdrive">'
					+ '<input type="url" class="gs-agent-ctx-gdrive-url" data-gs-seq-ctx-gdrive placeholder="' + escAttr('Paste a Google Drive link') + '" />'
					+ '<button type="button" class="gs-agent-ctx-btn" data-gs-seq-ctx-gdrive-add><span aria-hidden="true">＋</span> ' + escHtml('Add') + '</button>'
					+ '</div>';

				if (!st || !st.configured) {
					// NOT configured (or unknown) → paste-link + a tiny "not set up" note.
					wrap.innerHTML = pasteHtml
						+ '<p class="gs-agent-help gs-agent-gdrive-note">' + escHtml('Google Drive (paste link) — Drive sign-in not set up') + '</p>';
					return;
				}

				if (!st.connected) {
					// Configured but NOT connected → a "Connect Google Drive" button.
					wrap.innerHTML = ''
						+ '<button type="button" class="gs-agent-ctx-btn gs-agent-gdrive-connect" data-gs-seq-ctx-gdrive-connect>'
						+ '<span aria-hidden="true">🔗</span> ' + escHtml('Connect Google Drive')
						+ '</button>';
					return;
				}

				// Connected → search picker + results list + small paste-link fallback.
				wrap.innerHTML = ''
					+ '<div class="gs-agent-ctx-gdrive-picker">'
					+ '<input type="search" class="gs-agent-ctx-gdrive-search" data-gs-seq-ctx-gdrive-search placeholder="' + escAttr('Search your Google Drive…') + '" autocomplete="off" />'
					+ '<div class="gs-agent-ctx-gdrive-results" data-gs-seq-ctx-gdrive-results aria-live="polite"></div>'
					+ '</div>'
					+ pasteHtml;
			}

			// Open the Google consent popup window and install a ONE-TIME message
			// listener: on {gsGDrive:'connected'} it re-fetches status (→ connected),
			// re-renders, then removes itself. Guarded so a blocked popup degrades
			// (the paste-link fallback in the connected/not-configured states stays).
			function startGdriveConnect() {
				if (!cfg.gsRoot) { return; }
				try {
					window.open(cfg.gsRoot + '/gdrive/connect', 'gsgdrive', 'width=520,height=640');
				} catch (e) { /* popup blocked — degrade silently. */ }
				// Install the one-time listener (replace any prior one so we never stack).
				if (gdriveConnectListener) {
					try { window.removeEventListener('message', gdriveConnectListener); } catch (e2) {}
					gdriveConnectListener = null;
				}
				gdriveConnectListener = function (ev) {
					var d = ev && ev.data;
					if (!d || d.gsGDrive !== 'connected') { return; }
					try { window.removeEventListener('message', gdriveConnectListener); } catch (e3) {}
					gdriveConnectListener = null;
					// Re-fetch status fresh (clear cache so loadGdriveStatus actually re-runs).
					gdriveStatus = null;
					loadGdriveStatus();
				};
				window.addEventListener('message', gdriveConnectListener);
			}

			// Debounced (~300ms) Drive file search for a step row → gs/v1/gdrive/files.
			// Renders the result list; clicking a result adds a gdrive context chip via
			// the SAME addContextFile path used by paste-link. Guarded throughout.
			function runGdriveSearch(row, step, term) {
				var resultsBox = row && row.querySelector('[data-gs-seq-ctx-gdrive-results]');
				if (!resultsBox) { return; }
				if (!cfg.gsRoot) { resultsBox.innerHTML = ''; return; }
				var q = String(term == null ? '' : term).trim();
				if (!q) { resultsBox.innerHTML = ''; return; }
				resultsBox.innerHTML = '<p class="gs-agent-help">' + escHtml('Searching…') + '</p>';
				fetch(cfg.gsRoot + '/gdrive/files?q=' + encodeURIComponent(q), {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce || '' }
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						// A files call can report a dropped connection — reflect + re-render.
						if (data && data.connected === false) {
							if (gdriveStatus) { gdriveStatus.connected = false; }
							renderGdriveControl(row, step);
							return;
						}
						var files = (data && Array.isArray(data.files)) ? data.files : [];
						if (!files.length) {
							resultsBox.innerHTML = '<p class="gs-agent-help">' + escHtml('No files found.') + '</p>';
							return;
						}
						var html = '';
						for (var i = 0; i < files.length; i++) {
							var f = files[i] || {};
							var id = (f.id != null) ? String(f.id) : '';
							if (!id) { continue; }
							var name = f.name || id;
							var icon = f.iconLink ? '<img class="gs-agent-ctx-gdrive-icon" src="' + escAttr(f.iconLink) + '" alt="" />' : '';
							html += '<button type="button" class="gs-agent-ctx-gdrive-result" data-gs-seq-ctx-gdrive-pick="' + escAttr(id) + '" data-gdrive-name="' + escAttr(name) + '">'
								+ icon + '<span class="gs-agent-ctx-gdrive-result__name">' + escHtml(name) + '</span></button>';
						}
						resultsBox.innerHTML = html || ('<p class="gs-agent-help">' + escHtml('No files found.') + '</p>');
					})
					.catch(function () {
						resultsBox.innerHTML = '<p class="gs-agent-help">' + escHtml('Could not search Google Drive.') + '</p>';
					});
			}

			// Add a Google Drive file to a step's Context Files as a gdrive chip —
			// the SINGLE path used by both a picked search result and the paste-link
			// "Add" button. {type:'gdrive', ref, label, url}. ref OR url may be empty.
			function addGdriveContextFile(row, step, ref, label, url) {
				if (!step) { return; }
				step.contextFiles = Array.isArray(step.contextFiles) ? step.contextFiles : [];
				step.contextFiles.push({
					type: 'gdrive',
					ref: String(ref == null ? '' : ref),
					label: String(label == null ? 'Google Drive doc' : label) || 'Google Drive doc',
					url: String(url == null ? '' : url)
				});
				renderStepCtxChips(row, step);
			}

			/* ── Create + sequence + welcome ── */
			function createAgent(root) {
				var status = root.querySelector('[data-gs-agent-status]');
				var submit = root.querySelector('[data-gs-agent-submit]');
				var name = fieldValue(root, 'name');

				if (!homeGroup) { setStatus(status, 'Select a Home org (Web App).', 'warning'); return; }
				if (!name) { setStatus(status, 'Give the agent a name first.', 'warning'); return; }
				if (!cfg.psooRoot) { setStatus(status, 'Configuration missing.', 'error'); return; }

				// The agent's single home org IS its run target (a Web App).
				var home = homeGroup;
				var homeRef = String(home);

				var billingNode = root.querySelector('[data-gs-billing-select]');
				var billingAccount = billingNode ? (billingNode.value || '').trim() : '';

				var body = {
					group_id: home,
					name: name,
					system_prompt: fieldValue(root, 'system_prompt'),
					role: fieldValue(root, 'role'),
					department: fieldValue(root, 'department'),
					description: fieldValue(root, 'description'),
					reports_to: fieldValue(root, 'reports_to'),
					billing_account: billingAccount,
					run_target: 'webapp',
					target_ref: homeRef,
					run_targets: [{ type: 'webapp', ref: homeRef, label: homeName }]
				};

				if (submit) submit.disabled = true;
				setStatus(status, 'Creating agent…', 'info');

				fetch(cfg.psooRoot + '/agents/create', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
					body: JSON.stringify(body)
				})
					.then(function (res) { if (!res.ok) { throw new Error('request_failed'); } return res.json(); })
					.then(function (data) {
						if (data && data.provision && data.provision.error) {
							setStatus(status, 'Agent created, container mailbox could not be provisioned: ' + data.provision.error, 'warning');
						} else {
							setStatus(status, 'Agent created.', 'success');
						}
						var agentId = data && data.hub_user_id ? parseInt(data.hub_user_id, 10) : 0;
						var slug = (data && data.slug) ? String(data.slug) : '';

						var done = function () {
							window.setTimeout(function () {
								closePopup();
								try {
									var u = new URL(location.href);
									u.searchParams.set('gs_chat_tab', 'agents');
									location.href = u.toString();
								} catch (e) { location.reload(); }
							}, 900);
						};

						// Chain: (1) upsert EACH prompt sequence, then (2) best-effort
						// welcome thread, then (3) redirect.
						var afterSeq = function () {
							if (agentId > 0 && cfg.gsRoot) {
								fetch(cfg.gsRoot + '/agent-welcome', {
									method: 'POST',
									credentials: 'same-origin',
									headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
									body: JSON.stringify({ agent_user_id: agentId })
								}).then(done, done);
							} else {
								done();
							}
						};

						saveAllSequences(home, slug).then(afterSeq, afterSeq);
					})
					.catch(function () { setStatus(status, 'Could not create the agent.', 'error'); })
					.finally(function () { if (submit) submit.disabled = false; });
			}

			// Upsert ONE sequence (its non-empty steps) to psoo/v1. Resolves (no-op)
			// when the sequence has no non-empty step, or config/home is missing.
			function saveOneSequence(home, slug, seq) {
				if (!seq || !cfg.psooRoot || !home) { return Promise.resolve(); }
				var nonEmpty = (seq.prompts || []).filter(function (p) { return p.text && p.text.trim() !== ''; });
				if (!nonEmpty.length) { return Promise.resolve(); }
				var seqId = seq.id;
				var prompts = nonEmpty.map(function (p) {
					var devRef = (p.targetRef != null && p.targetRef !== '') ? p.targetRef : (p.deviceRef || '');
					return {
						id: p.id,
						text: p.text,
						runTarget: p.runTarget || (devRef ? 'device' : 'gendme'),
						targetRef: devRef,
						aiIntegration: p.aiIntegration || '',
						model: p.model || '',
						contextFiles: Array.isArray(p.contextFiles) ? p.contextFiles : [],
						outputs: Array.isArray(p.outputs) ? p.outputs : []
					};
				});
				var payload = {
					group_id: home,
					id: seqId,
					name: seq.name || ('Sequence ' + seqId),
					description: seq.description || '',
					agentSlug: slug || '',
					prompts: prompts
				};
				return fetch(cfg.psooRoot + '/business-plan/sequences/' + encodeURIComponent(seqId), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
					body: JSON.stringify(payload)
				}).then(function () { /* best-effort */ }, function () { /* best-effort */ });
			}

			// Persist EVERY sequence (each with >=1 non-empty step), sequentially.
			// Always resolves (best-effort) so the welcome + redirect still run.
			function saveAllSequences(home, slug) {
				if (!sequences.length || !cfg.psooRoot || !home) { return Promise.resolve(); }
				var chain = Promise.resolve();
				sequences.forEach(function (seq) {
					chain = chain.then(function () { return saveOneSequence(home, slug, seq); }, function () { return saveOneSequence(home, slug, seq); });
				});
				return chain.then(function () {}, function () {});
			}

			function bindPopup(root) {
				if (!root) return;

				root.addEventListener('click', function (e) {
					var t = e.target;
					if (!t || !t.closest) return;

					if (t.closest('[data-gs-agent-close]')) { closePopup(); return; }
					if (t.closest('[data-gs-agent-submit]')) { createAgent(root); return; }

					// Home org: a Web App search result → set it as the SINGLE home org.
					var waOpt = t.closest('[data-gs-webapp-opt]');
					if (waOpt) {
						pickHome(root, {
							group_id: waOpt.getAttribute('data-ref'),
							name: waOpt.getAttribute('data-name') || '',
							avatar: waOpt.getAttribute('data-avatar') || ''
						});
						return;
					}

					// Home org: "change" → clear the chosen home org, return to search.
					if (t.closest('[data-gs-home-change]')) {
						changeHome(root);
						return;
					}

					// Department: "+ New" → prompt, POST, add to options + select it.
					if (t.closest('[data-gs-dept-new]')) {
						addDepartment(root);
						return;
					}

					// Sequence list: "+ Add new sequence" → open the nested builder popup.
					if (t.closest('[data-gs-seq-newlist]')) {
						openSeqPopup(null);
						return;
					}

					// Sequence list: a row's "Edit" → open the nested builder for that seq.
					var seqEdit = t.closest('[data-gs-seqrow-edit]');
					if (seqEdit) {
						var editRow = t.closest('[data-gs-seqrow]');
						if (editRow) { openSeqPopup(editRow.getAttribute('data-seq-id')); }
						return;
					}

					// Sequence list: a row's "×" → remove that sequence from the model.
					var seqRm = t.closest('[data-gs-seqrow-rm]');
					if (seqRm) {
						var rmRow = t.closest('[data-gs-seqrow]');
						if (rmRow) {
							var rmId = rmRow.getAttribute('data-seq-id');
							sequences = sequences.filter(function (s) { return s.id !== rmId; });
							renderSeqList(root);
						}
						return;
					}
				});

				// Web-App AJAX search (debounced).
				root.addEventListener('input', function (e) {
					var t = e.target;
					if (t && t.matches && t.matches('[data-gs-webapp-search]')) {
						var term = t.value || '';
						if (searchTimer) { clearTimeout(searchTimer); }
						searchTimer = setTimeout(function () { loadWebApps(root, term); }, 250);
					}
				});

			}

			// Delegated: the add button is injected/re-injected into the nav.
			document.addEventListener('click', function (e) {
				var btn = e.target && e.target.closest ? e.target.closest('[data-gs-agent-add]') : null;
				if (!btn) return;
				e.preventDefault();
				openPopup();
			});
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', run);
		} else {
			run();
		}
		// NOTE: pill clicks are intentionally NOT preventDefault'd. Each pill
		// is a /messages/?gs_chat_tab= link; email-manager's classifyLink()
		// keys on pathname (still /messages/) so it classifies as 'chat' and
		// its ajaxNavigate() swaps #message-threads, then our MutationObserver
		// re-injects the nav + re-applies the active pill. If that strip is
		// absent the link simply full-reloads — the server filter prunes
		// either way. Full-reload fallback, no extra wiring needed.
	})();
	</script>
	<?php
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_footer', 'gs_chat_tabs_footer', 6 );
}

/**
 * Enqueue assets/chat-tabs.css on the messages page only.
 * Mirrors email-manager's enqueue guard (inbox-bp-messages-tabs.php:579-582):
 * load only when on the BP messages component. filemtime() cache-bust.
 */
function gs_chat_tabs_enqueue() {
	if ( ! function_exists( 'bp_is_messages_component' ) || ! bp_is_messages_component() ) {
		return;
	}
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		return;
	}

	$rel  = 'assets/chat-tabs.css';
	$url  = defined( 'GS_URL' ) ? ( GS_URL . $rel ) : plugins_url( '../' . $rel, __FILE__ );
	$path = defined( 'GS_DIR' ) ? ( GS_DIR . $rel ) : ( dirname( __FILE__, 2 ) . '/' . $rel );
	$ver  = ( @file_exists( $path ) ) ? @filemtime( $path ) : ( defined( 'GS_VERSION' ) ? GS_VERSION : false );

	wp_enqueue_style( 'gs-chat-tabs', $url, array(), $ver );
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_enqueue_scripts', 'gs_chat_tabs_enqueue', 20 );
}

/* =========================================================================
 * Plan 43-01 — "Add new Agent" on the Agents tab.
 *
 * Two self-contained gend-society REST routes that power an in-context
 * agent-create popup on the hub member Messages page. The agent is created
 * by REUSING the already-deployed projects route POST psoo/v1/agents/create
 * over HTTP from the browser (Task 2) — there is NO cross-plugin PHP call
 * here (that would risk an undefined-symbol fatal that php -l cannot catch,
 * and WP auto-deactivates a plugin that fatals — project_wp_fatal_auto_deactivation).
 *
 *   gs/v1/agent-admin-groups (GET)  -> the caller's OWN groups where they are
 *                                      a group admin AND that are linked to a
 *                                      Web App (container). The picker uses the
 *                                      SAME predicate (groups_is_user_admin) the
 *                                      create gate uses, so picker and gate agree.
 *   gs/v1/agent-welcome      (POST) -> auto-start a BuddyPress welcome thread to
 *                                      a freshly-created agent so it appears in
 *                                      the Agents-tab conversation list (Decision D1).
 *
 * SELF-CONTAINED / FATAL-SAFE: every BuddyPress / gdc / agent symbol is
 * function_exists/is_wp_error-guarded so an absent dependency degrades
 * (empty list / ok:false) instead of fataling. All users resolved BY ID
 * (never get_user_by('email') — broken via vendor-app-manager's fix_user_query).
 * ========================================================================= */

/**
 * GET gs/v1/agent-admin-groups — the caller's group-admin Web-App groups.
 *
 * Returns ONLY the current user's own groups (permission = is_user_logged_in),
 * filtered to those where they are a GROUP ADMIN and that resolve to a linked
 * Web App container. base_url is NEVER returned (mirror projects' deliberate
 * omission — avoid leaking the container host client-side).
 *
 * @return WP_REST_Response { ok:true, groups:[ {group_id,name,is_webapp} ] }
 */
function gs_rest_agent_admin_groups( $req = null ) {
	$uid  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$rows = array();

	// Optional AJAX-search term (run-target Web App multiselect filters by name).
	$term = ( is_object( $req ) && method_exists( $req, 'get_param' ) ) ? trim( (string) $req->get_param( 'term' ) ) : '';

	if ( $uid <= 0 ) {
		return rest_ensure_response( array( 'ok' => true, 'groups' => $rows ) );
	}

	$gids = array();
	if ( function_exists( 'groups_get_user_groups' ) ) {
		$ug = groups_get_user_groups( $uid );
		if ( is_array( $ug ) && ! empty( $ug['groups'] ) && is_array( $ug['groups'] ) ) {
			$gids = array_map( 'intval', $ug['groups'] );
		}
	}

	foreach ( array_unique( $gids ) as $gid ) {
		$gid = (int) $gid;
		if ( $gid <= 0 ) {
			continue;
		}
		// GROUP ADMIN only — the SAME predicate the create route's gate uses.
		if ( ! function_exists( 'groups_is_user_admin' ) || ! groups_is_user_admin( $uid, $gid ) ) {
			continue;
		}

		// Web-App-linked? gdc_resolve_install_for_group is vendor-app-manager —
		// guard it; a WP_Error means NOT a Web App.
		$is_webapp = false;
		if ( function_exists( 'gdc_resolve_install_for_group' ) ) {
			$c         = gdc_resolve_install_for_group( $gid );
			$is_webapp = ! is_wp_error( $c );
		}

		// RECOMMENDED FILTER: only surface Web-App-linked groups — a non-Web-App
		// group has no container, so run_target=webapp would 409 on create. The
		// is_webapp flag is still computed so the empty-state can explain.
		if ( ! $is_webapp ) {
			continue;
		}

		$name = 'Group ' . $gid;
		if ( function_exists( 'groups_get_group' ) ) {
			$g = groups_get_group( $gid );
			if ( $g && ! empty( $g->name ) ) {
				$name = $g->name;
			}
		}

		// AJAX-search filter (case-insensitive substring on the group name).
		if ( $term !== '' && stripos( $name, $term ) === false ) {
			continue;
		}

		// Group profile image for the futuristic run-target multiselect.
		$avatar = '';
		if ( function_exists( 'bp_core_fetch_avatar' ) ) {
			$avatar = bp_core_fetch_avatar( array(
				'item_id' => $gid,
				'object'  => 'group',
				'type'    => 'full',
				'html'    => false,
			) );
		}

		$rows[] = array(
			'group_id'  => $gid,
			'name'      => $name,
			'is_webapp' => $is_webapp,
			'avatar'    => (string) $avatar,
		);
	}

	return rest_ensure_response( array( 'ok' => true, 'groups' => $rows ) );
}

/**
 * GET gs/v1/agent-list — list the agents already in a group.
 *
 * Powers the "Reports to" dropdown in the Add-new-Agent popup so a new agent can
 * be slotted under an existing one. Resolves the group's members BY ID, keeps
 * only those classified as agents (gs_user_is_agent when present, else the
 * _aipa_is_agent meta), and returns { ok, agents:[ { slug, name } ] }.
 *
 * FATAL-SAFE: every external symbol is guarded; any missing dependency yields
 * { ok:true, agents:[] } rather than an error. ID-only resolution throughout.
 *
 * @param WP_REST_Request $req group_id (required positive BP group id).
 * @return WP_REST_Response
 */
function gs_rest_agent_list( $req ) {
	$gid    = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'group_id' ) : 0;
	$agents = array();

	if ( $gid <= 0 ) {
		return rest_ensure_response( array( 'ok' => true, 'agents' => $agents ) );
	}

	// Resolve member IDs via BuddyPress (preferred) — guarded, ID-only.
	$member_ids = array();
	if ( function_exists( 'groups_get_group_members' ) ) {
		$res = groups_get_group_members( array(
			'group_id'            => $gid,
			'per_page'            => 0, // 0 → all members
			'exclude_admins_mods' => false,
		) );
		if ( is_array( $res ) && ! empty( $res['members'] ) && is_array( $res['members'] ) ) {
			foreach ( $res['members'] as $m ) {
				$mid = is_object( $m ) && isset( $m->ID ) ? (int) $m->ID
					: ( is_object( $m ) && isset( $m->user_id ) ? (int) $m->user_id : 0 );
				if ( $mid > 0 ) {
					$member_ids[] = $mid;
				}
			}
		}
	}

	foreach ( array_unique( $member_ids ) as $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			continue;
		}

		// Agent classifier — prefer the canonical predicate, else the meta flag.
		$is_agent = false;
		if ( function_exists( 'gs_user_is_agent' ) ) {
			$is_agent = (bool) gs_user_is_agent( $uid );
		} elseif ( function_exists( 'get_user_meta' ) ) {
			$is_agent = (bool) get_user_meta( $uid, '_aipa_is_agent', true );
		}
		if ( ! $is_agent ) {
			continue;
		}

		$slug = function_exists( 'get_user_meta' ) ? (string) get_user_meta( $uid, '_aipa_agent_slug', true ) : '';
		if ( $slug === '' ) {
			continue; // No slug → cannot be a Reports-to parent.
		}

		$name = $slug;
		if ( function_exists( 'get_userdata' ) ) {
			$u = get_userdata( $uid );
			if ( $u && ! empty( $u->display_name ) ) {
				$name = (string) $u->display_name;
			}
		}

		$agents[] = array(
			'slug' => $slug,
			'name' => $name,
		);
	}

	return rest_ensure_response( array( 'ok' => true, 'agents' => $agents ) );
}

/**
 * GET gs/v1/group-members — list a group's HUMAN members.
 *
 * Powers the "Billing account" dropdown in the Add-new-Agent popup so a new
 * agent's gend.me runs (Leo Credits + Gas Compute) can be charged to a chosen
 * member. Resolves the group's members BY ID (mirrors gs_rest_agent_list),
 * EXCLUDES agents (gs_user_is_agent when present, else the _aipa_is_agent meta),
 * and returns { ok, members:[ { id:(int), name } ] }.
 *
 * FATAL-SAFE: every external symbol is guarded; any missing dependency yields
 * { ok:true, members:[] } rather than an error. ID-only resolution throughout.
 *
 * @param WP_REST_Request $req group_id (required positive BP group id).
 * @return WP_REST_Response
 */
function gs_rest_group_members( $req ) {
	$gid     = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'group_id' ) : 0;
	$members = array();

	// Optional case-insensitive display_name filter (empty => keep everyone).
	$term = ( is_object( $req ) && method_exists( $req, 'get_param' ) ) ? (string) $req->get_param( 'term' ) : '';
	$term = trim( $term );

	if ( $gid <= 0 ) {
		return rest_ensure_response( array( 'ok' => true, 'members' => $members ) );
	}

	// Resolve member IDs via BuddyPress (preferred) — guarded, ID-only.
	$member_ids = array();
	if ( function_exists( 'groups_get_group_members' ) ) {
		$res = groups_get_group_members( array(
			'group_id'            => $gid,
			'per_page'            => 0, // 0 → all members
			'exclude_admins_mods' => false,
		) );
		if ( is_array( $res ) && ! empty( $res['members'] ) && is_array( $res['members'] ) ) {
			foreach ( $res['members'] as $m ) {
				$mid = is_object( $m ) && isset( $m->ID ) ? (int) $m->ID
					: ( is_object( $m ) && isset( $m->user_id ) ? (int) $m->user_id : 0 );
				if ( $mid > 0 ) {
					$member_ids[] = $mid;
				}
			}
		}
	}

	foreach ( array_unique( $member_ids ) as $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			continue;
		}

		// EXCLUDE agents — prefer the canonical predicate, else the meta flag.
		$is_agent = false;
		if ( function_exists( 'gs_user_is_agent' ) ) {
			$is_agent = (bool) gs_user_is_agent( $uid );
		} elseif ( function_exists( 'get_user_meta' ) ) {
			$is_agent = (bool) get_user_meta( $uid, '_aipa_is_agent', true );
		}
		if ( $is_agent ) {
			continue;
		}

		$name = '#' . $uid;
		if ( function_exists( 'get_userdata' ) ) {
			$u = get_userdata( $uid );
			if ( $u && ! empty( $u->display_name ) ) {
				$name = (string) $u->display_name;
			}
		}

		// Optional term filter — case-insensitive substring on display_name.
		if ( '' !== $term && stripos( $name, $term ) === false ) {
			continue;
		}

		// Per-member avatar (guarded) for the @-mention recipient picker.
		$avatar = '';
		if ( function_exists( 'get_avatar_url' ) ) {
			$av = get_avatar_url( $uid, array( 'size' => 64 ) );
			if ( is_string( $av ) ) {
				$avatar = $av;
			}
		}

		$members[] = array(
			'id'     => $uid,
			'name'   => $name,
			'avatar' => $avatar,
		);
	}

	return rest_ensure_response( array( 'ok' => true, 'members' => $members ) );
}

/**
 * GET gs/v1/my-billable-members — HUMAN members of the groups the current user
 * ADMINS, for the DESKTOP "New sequence" billing-account dropdown (which has no
 * per-sequence group context). Restricting to admin'd groups keeps the billable
 * set to accounts the user is authorized to charge. Agents excluded, deduped by
 * user id. Fatal-safe, ID-only; degrades to an empty list, never throws.
 *
 * @return WP_REST_Response { ok:true, members:[ {id,name} ] }
 */
function gs_rest_my_billable_members() {
	$uid     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$members = array();
	if ( $uid <= 0 ) {
		return rest_ensure_response( array( 'ok' => true, 'members' => $members ) );
	}

	// Groups the caller belongs to (same source as gs_rest_agent_admin_groups).
	$gids = array();
	if ( function_exists( 'groups_get_user_groups' ) ) {
		$ug = groups_get_user_groups( $uid );
		if ( is_array( $ug ) && ! empty( $ug['groups'] ) && is_array( $ug['groups'] ) ) {
			$gids = array_map( 'intval', $ug['groups'] );
		}
	}

	$seen = array();
	foreach ( array_unique( $gids ) as $gid ) {
		$gid = (int) $gid;
		// GROUP ADMIN only — authorized-to-bill gate (mirrors agent-admin-groups).
		if ( $gid <= 0 || ! function_exists( 'groups_is_user_admin' ) || ! groups_is_user_admin( $uid, $gid ) ) {
			continue;
		}
		if ( ! function_exists( 'groups_get_group_members' ) ) {
			continue;
		}
		$res = groups_get_group_members( array(
			'group_id'            => $gid,
			'per_page'            => 0, // all
			'exclude_admins_mods' => false,
		) );
		if ( ! is_array( $res ) || empty( $res['members'] ) || ! is_array( $res['members'] ) ) {
			continue;
		}
		foreach ( $res['members'] as $m ) {
			$mid = is_object( $m ) && isset( $m->ID ) ? (int) $m->ID
				: ( is_object( $m ) && isset( $m->user_id ) ? (int) $m->user_id : 0 );
			if ( $mid <= 0 || isset( $seen[ $mid ] ) ) {
				continue;
			}
			// EXCLUDE agents — canonical predicate, else the meta flag.
			$is_agent = false;
			if ( function_exists( 'gs_user_is_agent' ) ) {
				$is_agent = (bool) gs_user_is_agent( $mid );
			} elseif ( function_exists( 'get_user_meta' ) ) {
				$is_agent = (bool) get_user_meta( $mid, '_aipa_is_agent', true );
			}
			if ( $is_agent ) {
				continue;
			}
			$seen[ $mid ] = true;
			$name = '#' . $mid;
			if ( function_exists( 'get_userdata' ) ) {
				$u = get_userdata( $mid );
				if ( $u && ! empty( $u->display_name ) ) {
					$name = (string) $u->display_name;
				}
			}
			$members[] = array( 'id' => $mid, 'name' => $name );
		}
	}

	return rest_ensure_response( array( 'ok' => true, 'members' => $members ) );
}

/**
 * GET gs/v1/group-departments — the group's Departments (Payments → Departments,
 * groupmeta _psoo_group_departments: [{id,name,allocation,payee_id,cadence}]).
 * Returns a lean { ok, departments:[{id,name}] }. Fatal-safe; empty on anything missing.
 *
 * @param WP_REST_Request $req group_id (required positive BP group id).
 * @return WP_REST_Response
 */
function gs_rest_group_departments( $req ) {
	$gid   = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'group_id' ) : 0;
	$depts = array();
	if ( $gid > 0 && function_exists( 'groups_get_groupmeta' ) ) {
		$raw = groups_get_groupmeta( $gid, '_psoo_group_departments', true );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $d ) {
				if ( is_array( $d ) && ! empty( $d['name'] ) ) {
					$depts[] = array(
						'id'   => isset( $d['id'] ) ? (string) $d['id'] : '',
						'name' => (string) $d['name'],
					);
				}
			}
		}
	}
	return rest_ensure_response( array( 'ok' => true, 'departments' => $depts ) );
}

/**
 * POST gs/v1/group-departments — create a Department on the group (group admin
 * only). Appends to _psoo_group_departments preserving the existing shape +
 * id convention ('dept-'+random) so the Payments tab + v6.0 sync stay consistent.
 * Dedupes by case-insensitive name. Fatal-safe; never throws.
 *
 * @param WP_REST_Request $req group_id (required) + name (required).
 * @return WP_REST_Response|WP_Error
 */
function gs_rest_group_department_create( $req ) {
	$gid  = (int) $req->get_param( 'group_id' );
	$name = trim( (string) $req->get_param( 'name' ) );
	if ( $gid <= 0 || $name === '' ) {
		return new WP_Error( 'gs_dept_bad', 'group_id and name are required.', array( 'status' => 400 ) );
	}
	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $uid <= 0 || ! function_exists( 'groups_is_user_admin' ) || ! groups_is_user_admin( $uid, $gid ) ) {
		return new WP_Error( 'gs_dept_forbidden', 'Only a group admin can add a department.', array( 'status' => 403 ) );
	}
	if ( ! function_exists( 'groups_get_groupmeta' ) || ! function_exists( 'groups_update_groupmeta' ) ) {
		return new WP_Error( 'gs_dept_unavailable', 'Departments are unavailable here.', array( 'status' => 500 ) );
	}

	$raw  = groups_get_groupmeta( $gid, '_psoo_group_departments', true );
	$list = is_array( $raw ) ? $raw : array();
	// Dedupe by name (return the existing one rather than creating a duplicate).
	foreach ( $list as $d ) {
		if ( is_array( $d ) && isset( $d['name'] ) && strcasecmp( (string) $d['name'], $name ) === 0 ) {
			return rest_ensure_response( array(
				'ok'         => true,
				'existing'   => true,
				'department' => array( 'id' => (string) ( $d['id'] ?? '' ), 'name' => (string) $d['name'] ),
			) );
		}
	}
	$id   = 'dept-' . wp_generate_password( 8, false, false );
	$dept = array(
		'id'         => $id,
		'name'       => sanitize_text_field( $name ),
		'allocation' => 0,
		'payee_id'   => 0,
		'cadence'    => 'monthly',
	);
	$list[] = $dept;
	groups_update_groupmeta( $gid, '_psoo_group_departments', $list );

	return rest_ensure_response( array(
		'ok'         => true,
		'department' => array( 'id' => $id, 'name' => $dept['name'] ),
	) );
}

/**
 * GET gs/v1/group-projects — the group's Project Manager projects (id + name),
 * for the step-studio "Update Project" output picker so a step's response can be
 * filed against a real project. Reads ONLY via the projects plugin's public API
 * (PSOO_PM_Projects::get_for_group) when present — guarded class_exists +
 * method_exists so an absent dependency degrades to { ok:true, projects:[] }
 * rather than fataling. Normalizes each project to { id:(int), name:(string) }.
 *
 * @param WP_REST_Request $req group_id (required positive BP group id).
 * @return WP_REST_Response { ok:true, projects:[ {id,name} ] }
 */
function gs_rest_group_projects( $req ) {
	$gid      = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'group_id' ) : 0;
	$projects = array();

	if ( $gid <= 0 ) {
		return rest_ensure_response( array( 'ok' => true, 'projects' => $projects ) );
	}

	// Cross-plugin call is GUARDED: class + method must both exist (php -l cannot
	// catch an undefined-symbol fatal — this is the real safety net).
	if ( class_exists( 'PSOO_PM_Projects' ) && method_exists( 'PSOO_PM_Projects', 'get_for_group' ) ) {
		$raw = PSOO_PM_Projects::get_for_group( $gid );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $p ) {
				// Accept either an array or an object shape; resolve id + name defensively.
				$pid  = 0;
				$name = '';
				if ( is_array( $p ) ) {
					$pid  = isset( $p['id'] ) ? (int) $p['id'] : 0;
					if ( isset( $p['title'] ) && '' !== (string) $p['title'] ) {
						$name = (string) $p['title'];
					} elseif ( isset( $p['name'] ) ) {
						$name = (string) $p['name'];
					}
				} elseif ( is_object( $p ) ) {
					$pid  = isset( $p->id ) ? (int) $p->id : 0;
					if ( isset( $p->title ) && '' !== (string) $p->title ) {
						$name = (string) $p->title;
					} elseif ( isset( $p->name ) ) {
						$name = (string) $p->name;
					}
				}
				if ( $pid <= 0 ) {
					continue;
				}
				if ( '' === $name ) {
					$name = 'Project #' . $pid;
				}
				$projects[] = array( 'id' => $pid, 'name' => $name );
			}
		}
	}

	return rest_ensure_response( array( 'ok' => true, 'projects' => $projects ) );
}

/**
 * POST gs/v1/group-projects — create a Project Manager project and link it to the
 * group (group admin only). Creates via PSOO_PM_Projects::create (guarded) and,
 * when a project id can be resolved, links it to the group via
 * PSOO_PM_Projects::link_group (guarded). On any failure returns a WP_Error —
 * but every cross-plugin call is class/method guarded so the route never fatals.
 *
 * @param WP_REST_Request $req group_id (required) + title (required).
 * @return WP_REST_Response|WP_Error
 */
function gs_rest_group_project_create( $req ) {
	$gid   = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'group_id' ) : 0;
	$title = ( is_object( $req ) && method_exists( $req, 'get_param' ) ) ? trim( (string) $req->get_param( 'title' ) ) : '';

	if ( $gid <= 0 || '' === $title ) {
		return new WP_Error( 'gs_proj_bad', 'group_id and title are required.', array( 'status' => 400 ) );
	}

	// Group-admin gate (authorized to create a project for this group).
	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $uid <= 0 || ! function_exists( 'groups_is_user_admin' ) || ! groups_is_user_admin( $uid, $gid ) ) {
		return new WP_Error( 'gs_proj_forbidden', 'Only a group admin can add a project.', array( 'status' => 403 ) );
	}

	if ( ! class_exists( 'PSOO_PM_Projects' ) || ! method_exists( 'PSOO_PM_Projects', 'create' ) ) {
		return new WP_Error( 'gs_proj_unavailable', 'Projects are unavailable here.', array( 'status' => 500 ) );
	}

	$p = PSOO_PM_Projects::create( array( 'title' => sanitize_text_field( $title ) ) );
	if ( is_wp_error( $p ) ) {
		return $p;
	}

	// Resolve the new project id from either an array shape or a raw int.
	$pid = 0;
	if ( is_array( $p ) && isset( $p['id'] ) ) {
		$pid = (int) $p['id'];
	} elseif ( is_object( $p ) && isset( $p->id ) ) {
		$pid = (int) $p->id;
	} elseif ( is_numeric( $p ) ) {
		$pid = (int) $p;
	}
	if ( $pid <= 0 ) {
		return new WP_Error( 'gs_proj_create_failed', 'Could not resolve the new project id.', array( 'status' => 500 ) );
	}

	// Best-effort group link — guarded so an absent method never fatals.
	if ( method_exists( 'PSOO_PM_Projects', 'link_group' ) ) {
		PSOO_PM_Projects::link_group( $pid, $gid );
	}

	return rest_ensure_response( array(
		'ok'      => true,
		'project' => array( 'id' => $pid, 'name' => sanitize_text_field( $title ) ),
	) );
}

/**
 * GET gs/v1/group-sequences — the group's prompt sequences (id + name + agent),
 * for the step-studio "Context Files → other sequences" picker. Reads the
 * projects-owned groupmeta _psoo_sequences. Fatal-safe; empty on anything missing.
 *
 * @param WP_REST_Request $req group_id (required).
 * @return WP_REST_Response { ok, sequences:[{id,name,agentSlug}] }
 */
function gs_rest_group_sequences( $req ) {
	$gid  = is_object( $req ) && method_exists( $req, 'get_param' ) ? (int) $req->get_param( 'group_id' ) : 0;
	$out  = array();
	if ( $gid > 0 && function_exists( 'groups_get_groupmeta' ) ) {
		$raw = groups_get_groupmeta( $gid, '_psoo_sequences', true );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $s ) {
				if ( ! is_array( $s ) || empty( $s['id'] ) ) continue;
				$out[] = array(
					'id'        => (string) $s['id'],
					'name'      => isset( $s['name'] ) ? (string) $s['name'] : (string) $s['id'],
					'agentSlug' => isset( $s['agentSlug'] ) ? (string) $s['agentSlug'] : '',
				);
			}
		}
	}
	return rest_ensure_response( array( 'ok' => true, 'sequences' => $out ) );
}

/**
 * POST gs/v1/agent-welcome — auto-start a welcome thread to a new agent.
 *
 * Sends a BuddyPress message from the caller to the freshly-created agent so a
 * thread exists and the agent shows in the Agents-tab conversation list. The
 * Phase-35 agent-reply hook then answers. Degrades (ok:false) — never fatals —
 * when messaging is unavailable.
 *
 * @param WP_REST_Request $req agent_user_id (required positive agent user id).
 * @return WP_REST_Response
 */
function gs_rest_agent_welcome( $req ) {
	$agent_id = (int) $req->get_param( 'agent_user_id' );
	if ( $agent_id <= 0 ) {
		return new WP_Error( 'gs_agent_welcome_bad_id', 'A valid agent_user_id is required.', array( 'status' => 400 ) );
	}

	// Resolve agent BY ID only (gs_user_is_agent is ID-only). 400 if not an agent.
	if ( ! function_exists( 'gs_user_is_agent' ) || ! gs_user_is_agent( $agent_id ) ) {
		return new WP_Error( 'gs_agent_welcome_not_agent', 'That user is not an agent.', array( 'status' => 400 ) );
	}

	// Degrade (never fatal) if BuddyPress messaging is inactive.
	if ( ! function_exists( 'messages_new_message' ) ) {
		return rest_ensure_response( array( 'ok' => false, 'skipped' => 'messages_inactive' ) );
	}

	$sender = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $sender <= 0 ) {
		return new WP_Error( 'gs_agent_welcome_no_sender', 'Could not resolve the current user.', array( 'status' => 400 ) );
	}

	$tid = messages_new_message( array(
		'sender_id'  => $sender,
		'recipients' => array( $agent_id ),
		'subject'    => 'Welcome',
		'content'    => "Welcome aboard! Please introduce yourself and tell me how you can help.",
	) );

	if ( is_wp_error( $tid ) ) {
		return rest_ensure_response( array( 'ok' => false, 'error' => $tid->get_error_message() ) );
	}
	if ( ! $tid ) {
		return rest_ensure_response( array( 'ok' => false, 'error' => 'message_not_sent' ) );
	}

	return rest_ensure_response( array( 'ok' => true, 'thread_id' => (int) $tid ) );
}

/* =========================================================================
 * Plan 44-01 — Projects-tab REST: attach a thread to a project + list the
 * viewer's projects for the attach picker. Same gs/v1 namespace + registration
 * site as the Plan 43-01 routes. All $wpdb-direct, ID-only, fatal-safe (no
 * Project Manager PHP classes are called).
 * ========================================================================= */

/**
 * Can the current user attach the given thread to a project?
 *
 * Preferred: confirm the caller participates in the thread (BP_Messages_Thread
 * recipients). Fallback (if that cannot be verified safely): require the caller
 * be a member of the project's linked group. Both checks are ID-only + guarded.
 *
 * @param int $uid        Current user id.
 * @param int $thread_id  Thread id.
 * @param int $project_id Project id (for the group-membership fallback).
 * @return bool
 */
function gs_chat_can_attach( $uid, $thread_id, $project_id ) {
	$uid        = (int) $uid;
	$thread_id  = (int) $thread_id;
	$project_id = (int) $project_id;
	if ( $uid <= 0 || $thread_id <= 0 || $project_id <= 0 ) {
		return false;
	}

	// Preferred: caller is a recipient of the thread. Build the thread object by
	// ID and scan its recipients (keyed by user_id). All guarded.
	if ( class_exists( 'BP_Messages_Thread' ) ) {
		$valid = true;
		if ( method_exists( 'BP_Messages_Thread', 'is_valid' ) ) {
			$valid = (bool) BP_Messages_Thread::is_valid( $thread_id );
		}
		if ( $valid ) {
			$thread = new BP_Messages_Thread( $thread_id, 'ASC', array( 'update_meta_cache' => false ) );
			if ( isset( $thread->recipients ) && is_array( $thread->recipients ) ) {
				foreach ( $thread->recipients as $rcp_id => $rcp ) {
					$rid = ( is_object( $rcp ) && isset( $rcp->user_id ) ) ? (int) $rcp->user_id : (int) $rcp_id;
					if ( $rid === $uid ) {
						return true;
					}
				}
			}
		}
	}

	// Fallback: caller is a member of the project's linked group.
	$gid = gs_chat_project_group_id( $project_id );
	if ( $gid > 0 && function_exists( 'groups_is_user_member' ) && groups_is_user_member( $uid, $gid ) ) {
		return true;
	}

	return false;
}

/**
 * POST gs/v1/chat-attach-project — link a message thread to a project.
 *
 * UPSERTs the thread<->project linkage row in {pm_meta}
 * (entity_type='pm_chat_thread', meta_key='thread_id', meta_value={thread_id}):
 * delete any existing row for that thread, then insert with the chosen
 * project_id. $wpdb-direct, ID-only, permission-gated by gs_chat_can_attach.
 *
 * @param WP_REST_Request $req thread_id + project_id (positive ints).
 * @return WP_REST_Response|WP_Error
 */
function gs_rest_chat_attach_project( $req ) {
	global $wpdb;

	$thread_id  = (int) $req->get_param( 'thread_id' );
	$project_id = (int) $req->get_param( 'project_id' );
	if ( $thread_id <= 0 || $project_id <= 0 ) {
		return new WP_Error( 'gs_chat_attach_bad_args', 'A valid thread_id and project_id are required.', array( 'status' => 400 ) );
	}

	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $uid <= 0 ) {
		return new WP_Error( 'gs_chat_attach_no_user', 'Could not resolve the current user.', array( 'status' => 401 ) );
	}

	if ( ! gs_chat_can_attach( $uid, $thread_id, $project_id ) ) {
		return new WP_Error( 'gs_chat_attach_forbidden', 'You cannot attach this conversation to that project.', array( 'status' => 403 ) );
	}

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return rest_ensure_response( array( 'ok' => false, 'error' => 'db_unavailable' ) );
	}
	$meta = gs_chat_pm_table( 'meta' );
	if ( '' === $meta ) {
		return rest_ensure_response( array( 'ok' => false, 'error' => 'meta_table_unavailable' ) );
	}

	// UPSERT: delete any existing linkage for this thread, then insert fresh.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$meta} WHERE entity_type = 'pm_chat_thread' AND meta_key = 'thread_id' AND meta_value = %d",
		$thread_id
	) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built safely above.

	$inserted = false;
	if ( method_exists( $wpdb, 'insert' ) ) {
		$inserted = (bool) $wpdb->insert(
			$meta,
			array(
				'entity_type' => 'pm_chat_thread',
				'meta_key'    => 'thread_id',
				'meta_value'  => (string) $thread_id,
				'project_id'  => $project_id,
			),
			array( '%s', '%s', '%s', '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
	}

	if ( ! $inserted ) {
		return rest_ensure_response( array( 'ok' => false, 'error' => 'insert_failed' ) );
	}

	return rest_ensure_response( array(
		'ok'         => true,
		'thread_id'  => $thread_id,
		'project_id' => $project_id,
	) );
}

/**
 * GET gs/v1/chat-projects — the caller's attachable projects ([id,title]).
 *
 * Powers the per-row attach picker as a fallback to the inline GS_CHAT.projects
 * blob. Returns only projects in groups the caller belongs to.
 *
 * @return WP_REST_Response { ok:true, projects:[ {id,title} ] }
 */
function gs_rest_chat_projects() {
	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$projects = ( $uid > 0 && function_exists( 'gs_chat_viewer_projects' ) ) ? gs_chat_viewer_projects( $uid ) : array();
	return rest_ensure_response( array( 'ok' => true, 'projects' => array_values( $projects ) ) );
}

/* =========================================================================
 * v8.3 step-studio — Context Files → Google Drive.
 *
 * Hub-side Google Drive OAuth + Drive API backend. A SINGLE Google OAuth web
 * client (admin-provisioned via env/option) connects each member's own Drive on
 * a per-USER basis: the refresh token is stored on that user's user_meta, so the
 * step-studio "Context Files → paste a Drive link" picker can list + read the
 * member's own Drive docs at agent runtime.
 *
 * FATAL-SAFETY ABSOLUTE: every WP/HTTP symbol is function_exists-guarded, all
 * user resolution is BY ID (never get_user_by('email') — broken here via
 * vendor-app-manager's fix_user_query rewrite), nothing throws across REST (each
 * handler returns rest_ensure_response / WP_Error), and when Drive is NOT
 * configured or NOT connected every surface degrades cleanly (configured:false /
 * connected:false / empty files / '' text) instead of erroring.
 *
 * Credentials are READ, never hardcoded:
 *   client_id     : getenv('GS_GDRIVE_CLIENT_ID')     ?: option gs_gdrive_client_id
 *   client_secret : getenv('GS_GDRIVE_CLIENT_SECRET') ?: option gs_gdrive_client_secret
 *   redirect_uri  : option gs_gdrive_redirect_uri     ?: rest_url('gs/v1/gdrive/callback')
 *
 * Scope: https://www.googleapis.com/auth/drive.readonly (read-only).
 * ========================================================================= */

/**
 * Google Drive OAuth credentials (READ from env/options — never hardcoded).
 *
 * @return array { client_id:string, client_secret:string, redirect_uri:string }
 */
function gs_gdrive_creds() {
	$cid = '';
	$sec = '';
	if ( function_exists( 'getenv' ) ) {
		$env_cid = getenv( 'GS_GDRIVE_CLIENT_ID' );
		$env_sec = getenv( 'GS_GDRIVE_CLIENT_SECRET' );
		$cid = is_string( $env_cid ) ? trim( $env_cid ) : '';
		$sec = is_string( $env_sec ) ? trim( $env_sec ) : '';
	}
	if ( '' === $cid && function_exists( 'get_option' ) ) {
		$cid = trim( (string) get_option( 'gs_gdrive_client_id', '' ) );
	}
	if ( '' === $sec && function_exists( 'get_option' ) ) {
		$sec = trim( (string) get_option( 'gs_gdrive_client_secret', '' ) );
	}

	$redirect = function_exists( 'get_option' ) ? trim( (string) get_option( 'gs_gdrive_redirect_uri', '' ) ) : '';
	if ( '' === $redirect && function_exists( 'rest_url' ) ) {
		$redirect = (string) rest_url( 'gs/v1/gdrive/callback' );
	}

	return array(
		'client_id'     => $cid,
		'client_secret' => $sec,
		'redirect_uri'  => $redirect,
	);
}

/**
 * Is Google Drive OAuth configured (client id + secret both present)?
 *
 * @return bool
 */
function gs_gdrive_is_configured() {
	$c = gs_gdrive_creds();
	return ( '' !== $c['client_id'] && '' !== $c['client_secret'] );
}

/**
 * Does the given user have a stored Drive refresh token (i.e. connected)?
 *
 * @param int $uid User id.
 * @return bool
 */
function gs_gdrive_user_connected( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || ! function_exists( 'get_user_meta' ) ) {
		return false;
	}
	$refresh = (string) get_user_meta( $uid, '_gs_gdrive_refresh', true );
	return ( '' !== trim( $refresh ) );
}

/**
 * A valid Google Drive access token for the given user, or '' if none.
 *
 * Returns the stored access token if not yet expired; otherwise refreshes it
 * using the stored refresh token (rotating the refresh token if Google returns a
 * new one). Every network call is guarded; returns '' on any failure. ID-only.
 *
 * @param int $uid User id.
 * @return string Access token or ''.
 */
function gs_gdrive_access_token( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || ! function_exists( 'get_user_meta' ) ) {
		return '';
	}

	$access = (string) get_user_meta( $uid, '_gs_gdrive_access', true );
	$exp    = (int) get_user_meta( $uid, '_gs_gdrive_exp', true );

	// Still-valid cached access token.
	if ( '' !== trim( $access ) && $exp > time() ) {
		return $access;
	}

	// Need to refresh — require a refresh token + configured client.
	$refresh = (string) get_user_meta( $uid, '_gs_gdrive_refresh', true );
	if ( '' === trim( $refresh ) ) {
		return '';
	}
	if ( ! gs_gdrive_is_configured() || ! function_exists( 'wp_remote_post' ) ) {
		return '';
	}

	$creds = gs_gdrive_creds();
	$resp  = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 12,
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
			),
		)
	);
	if ( ! function_exists( 'is_wp_error' ) || is_wp_error( $resp ) ) {
		return '';
	}
	$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
	if ( 200 !== $code ) {
		return '';
	}
	$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
		return '';
	}

	$new_access  = (string) $data['access_token'];
	$expires_in  = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
	$new_exp     = time() + max( 60, $expires_in ) - 60;

	if ( function_exists( 'update_user_meta' ) ) {
		update_user_meta( $uid, '_gs_gdrive_access', $new_access );
		update_user_meta( $uid, '_gs_gdrive_exp', $new_exp );
		// Google may rotate the refresh token; only overwrite if a new one came back.
		if ( ! empty( $data['refresh_token'] ) ) {
			update_user_meta( $uid, '_gs_gdrive_refresh', (string) $data['refresh_token'] );
		}
	}

	return $new_access;
}

/**
 * Best-effort: up to ~6000 chars of text for a Drive file, for the given user.
 *
 * Google Docs are exported as text/plain; other files are fetched via alt=media
 * and kept only if they look text-ish. Fully guarded — returns '' on anything
 * missing / binary / failed. ID-only (token resolved from the uid's user_meta).
 *
 * @param string $file_id Drive file id.
 * @param int    $uid     User id whose Drive token is used.
 * @return string Text (<= ~6000 chars) or ''.
 */
function gs_gdrive_fetch_text( $file_id, $uid ) {
	$file_id = trim( (string) $file_id );
	$uid     = (int) $uid;
	if ( '' === $file_id || $uid <= 0 || ! function_exists( 'wp_remote_get' ) ) {
		return '';
	}

	$token = gs_gdrive_access_token( $uid );
	if ( '' === $token ) {
		return '';
	}

	$id_enc  = rawurlencode( $file_id );
	$headers = array( 'Authorization' => 'Bearer ' . $token );

	// 1) Resolve the mime type (best-effort; on failure fall through to alt=media).
	$mime = '';
	$meta = wp_remote_get(
		'https://www.googleapis.com/drive/v3/files/' . $id_enc . '?fields=mimeType',
		array( 'timeout' => 12, 'headers' => $headers )
	);
	if ( function_exists( 'is_wp_error' ) && ! is_wp_error( $meta )
		&& function_exists( 'wp_remote_retrieve_response_code' )
		&& 200 === (int) wp_remote_retrieve_response_code( $meta ) ) {
		$mbody = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $meta ) : '';
		$mdata = json_decode( $mbody, true );
		if ( is_array( $mdata ) && ! empty( $mdata['mimeType'] ) ) {
			$mime = (string) $mdata['mimeType'];
		}
	}

	// 2) Native Google Doc → export as plain text.
	if ( 'application/vnd.google-apps.document' === $mime ) {
		$resp = wp_remote_get(
			'https://www.googleapis.com/drive/v3/files/' . $id_enc . '/export?mimeType=text/plain',
			array( 'timeout' => 15, 'headers' => $headers )
		);
		if ( function_exists( 'is_wp_error' ) && ! is_wp_error( $resp )
			&& function_exists( 'wp_remote_retrieve_response_code' )
			&& 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
			if ( '' !== $body ) {
				return substr( $body, 0, 6000 );
			}
		}
		return '';
	}

	// 3) Other Google-apps types (Sheets, Slides, folders, etc.) are not plain
	//    media — skip rather than risk binary.
	if ( 0 === strpos( $mime, 'application/vnd.google-apps' ) ) {
		return '';
	}

	// 4) Regular file → download bytes, keep only if text-ish.
	$resp = wp_remote_get(
		'https://www.googleapis.com/drive/v3/files/' . $id_enc . '?alt=media',
		array( 'timeout' => 15, 'headers' => $headers )
	);
	if ( ! function_exists( 'is_wp_error' ) || is_wp_error( $resp ) ) {
		return '';
	}
	if ( ! function_exists( 'wp_remote_retrieve_response_code' )
		|| 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return '';
	}
	$ctype = function_exists( 'wp_remote_retrieve_header' ) ? (string) wp_remote_retrieve_header( $resp, 'content-type' ) : '';
	$body  = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
	if ( '' === $body ) {
		return '';
	}
	$looks_text = ( '' === $ctype
		|| false !== stripos( $ctype, 'text' )
		|| false !== stripos( $ctype, 'json' )
		|| false !== stripos( $ctype, 'csv' )
		|| false !== stripos( $ctype, 'xml' ) );
	// Belt-and-suspenders: reject obvious binary (a NUL byte in the head).
	if ( $looks_text && false !== strpos( substr( $body, 0, 1024 ), "\0" ) ) {
		$looks_text = false;
	}
	if ( ! $looks_text ) {
		return '';
	}
	return substr( $body, 0, 6000 );
}

/**
 * GET gs/v1/gdrive/status — is Drive configured, and is the caller connected?
 *
 * @return WP_REST_Response { ok:true, configured:bool, connected:bool }
 */
function gs_rest_gdrive_status() {
	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	return rest_ensure_response( array(
		'ok'         => true,
		'configured' => gs_gdrive_is_configured(),
		'connected'  => gs_gdrive_user_connected( $uid ),
	) );
}

/**
 * GET gs/v1/gdrive/connect — start the Google consent flow (top-level redirect).
 *
 * Opened in a popup as a full browser navigation. When NOT configured, renders a
 * tiny HTML notice instead of redirecting. The `state` is a wp_nonce'd token that
 * embeds the caller's uid; the callback verifies it to bind the grant to the uid.
 *
 * @return void (emits a redirect or an HTML page, then exits).
 */
function gs_rest_gdrive_connect() {
	$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

	if ( ! gs_gdrive_is_configured() ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			status_header( 200 );
		}
		echo '<!doctype html><meta charset="utf-8"><title>Google Drive</title>'
			. '<body style="font-family:system-ui,Arial,sans-serif;padding:24px;line-height:1.5">'
			. '<h3>Google Drive is not configured</h3>'
			. '<p>An administrator must set <code>GS_GDRIVE_CLIENT_ID</code> and '
			. '<code>GS_GDRIVE_CLIENT_SECRET</code> before Drive can be connected.</p>'
			. '<p><button type="button" onclick="window.close()">Close</button></p>'
			. '</body>';
		exit;
	}

	if ( $uid <= 0 ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			status_header( 403 );
		}
		echo '<!doctype html><meta charset="utf-8"><title>Google Drive</title>'
			. '<body style="font-family:system-ui,Arial,sans-serif;padding:24px">'
			. '<p>Please sign in before connecting Google Drive.</p></body>';
		exit;
	}

	$creds = gs_gdrive_creds();
	$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'gs_gdrive_state_' . $uid ) : '';
	// state = uid + nonce, verified on callback (binds the grant to this uid).
	$state = $uid . '|' . $nonce;

	$params = array(
		'response_type'         => 'code',
		'access_type'           => 'offline',
		'prompt'                => 'consent',
		'include_granted_scopes' => 'true',
		'client_id'             => $creds['client_id'],
		'redirect_uri'          => $creds['redirect_uri'],
		'scope'                 => 'https://www.googleapis.com/auth/drive.readonly',
		'state'                 => $state,
	);
	$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );

	if ( function_exists( 'wp_redirect' ) ) {
		wp_redirect( $auth_url );
	} elseif ( ! headers_sent() ) {
		header( 'Location: ' . $auth_url );
	}
	exit;
}

/**
 * GET gs/v1/gdrive/callback — Google's redirect back with the auth code.
 *
 * Permission is __return_true (Google redirects without our auth cookie), but the
 * `state` nonce is verified to resolve + bind the uid. Exchanges the code for
 * tokens and stores them on THAT uid's user_meta. Always renders a tiny HTML page
 * (it runs in a popup) that postMessages the opener and closes. Never throws.
 *
 * @param WP_REST_Request $req code + state.
 * @return void (emits HTML, then exits).
 */
function gs_rest_gdrive_callback( $req = null ) {
	$code  = '';
	$state = '';
	if ( is_object( $req ) && method_exists( $req, 'get_param' ) ) {
		$code  = (string) $req->get_param( 'code' );
		$state = (string) $req->get_param( 'state' );
	}

	$render = function ( $ok, $message ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			status_header( 200 );
		}
		$msg = htmlspecialchars( (string) $message, ENT_QUOTES, 'UTF-8' );
		$signal = $ok ? "try{window.opener && window.opener.postMessage({gsGDrive:'connected'},'*');}catch(e){}" : '';
		echo '<!doctype html><meta charset="utf-8"><title>Google Drive</title>'
			. '<body style="font-family:system-ui,Arial,sans-serif;padding:24px;line-height:1.5">'
			. '<p>' . $msg . '</p>'
			. '<script>' . $signal . 'try{window.close();}catch(e){}</script>'
			. '</body>';
		exit;
	};

	// Verify state -> resolve uid.
	$uid   = 0;
	$nonce = '';
	if ( false !== strpos( $state, '|' ) ) {
		$parts = explode( '|', $state, 2 );
		$uid   = (int) $parts[0];
		$nonce = isset( $parts[1] ) ? (string) $parts[1] : '';
	}
	if ( $uid <= 0 || '' === $nonce
		|| ! function_exists( 'wp_verify_nonce' )
		|| ! wp_verify_nonce( $nonce, 'gs_gdrive_state_' . $uid ) ) {
		$render( false, 'Could not verify this connection request. Please try again.' );
		return; // (defensive; $render exits)
	}

	if ( '' === trim( $code ) ) {
		$render( false, 'No authorization code was returned. Please try again.' );
		return;
	}
	if ( ! gs_gdrive_is_configured() || ! function_exists( 'wp_remote_post' ) ) {
		$render( false, 'Google Drive is not configured.' );
		return;
	}

	$creds = gs_gdrive_creds();
	$resp  = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'redirect_uri'  => $creds['redirect_uri'],
			),
		)
	);
	if ( ! function_exists( 'is_wp_error' ) || is_wp_error( $resp ) ) {
		$render( false, 'Could not reach Google to complete the connection.' );
		return;
	}
	$rcode = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
	$rbody = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
	$data  = json_decode( $rbody, true );
	if ( 200 !== $rcode || ! is_array( $data ) || empty( $data['access_token'] ) ) {
		$render( false, 'Google declined the connection. Please try again.' );
		return;
	}

	$access     = (string) $data['access_token'];
	$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
	$exp        = time() + max( 60, $expires_in ) - 60;

	if ( function_exists( 'update_user_meta' ) ) {
		update_user_meta( $uid, '_gs_gdrive_access', $access );
		update_user_meta( $uid, '_gs_gdrive_exp', $exp );
		// Store the refresh token only when returned; keep the existing one otherwise.
		if ( ! empty( $data['refresh_token'] ) ) {
			update_user_meta( $uid, '_gs_gdrive_refresh', (string) $data['refresh_token'] );
		}
	}

	$render( true, 'Connected — you can close this window.' );
}

/**
 * GET gs/v1/gdrive/files — list the caller's Drive files (optionally name-filtered).
 *
 * Calls Drive v3 files.list with the caller's token. When the caller has no token
 * (not connected / refresh failed) returns ok:true with an empty list +
 * connected:false so the picker degrades gracefully. Never throws.
 *
 * @param WP_REST_Request $req q (optional name substring).
 * @return WP_REST_Response { ok:true, files:[{id,name,mimeType,iconLink}], connected?:false }
 */
function gs_rest_gdrive_files( $req = null ) {
	$q = '';
	if ( is_object( $req ) && method_exists( $req, 'get_param' ) ) {
		$q = trim( (string) $req->get_param( 'q' ) );
	}

	$uid   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$token = ( $uid > 0 ) ? gs_gdrive_access_token( $uid ) : '';
	if ( '' === $token ) {
		return rest_ensure_response( array( 'ok' => true, 'files' => array(), 'connected' => false ) );
	}

	// Build the Drive query. Escape single quotes for the q expression.
	$query = "trashed=false";
	if ( '' !== $q ) {
		$q_esc = str_replace( "'", "\\'", $q );
		$query = "name contains '" . $q_esc . "' and trashed=false";
	}

	$params = array(
		'q'        => $query,
		'pageSize' => 20,
		'fields'   => 'files(id,name,mimeType,iconLink)',
		'orderBy'  => 'modifiedTime desc',
	);
	$url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query( $params );

	if ( ! function_exists( 'wp_remote_get' ) ) {
		return rest_ensure_response( array( 'ok' => true, 'files' => array() ) );
	}
	$resp = wp_remote_get(
		$url,
		array( 'timeout' => 12, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
	);
	if ( ! function_exists( 'is_wp_error' ) || is_wp_error( $resp ) ) {
		return rest_ensure_response( array( 'ok' => true, 'files' => array() ) );
	}
	$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
	if ( 200 !== $code ) {
		// 401/403 most likely a revoked/expired grant — surface as not-connected.
		if ( 401 === $code || 403 === $code ) {
			return rest_ensure_response( array( 'ok' => true, 'files' => array(), 'connected' => false ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'files' => array() ) );
	}
	$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
	$data = json_decode( $body, true );
	$out  = array();
	if ( is_array( $data ) && isset( $data['files'] ) && is_array( $data['files'] ) ) {
		foreach ( $data['files'] as $f ) {
			if ( ! is_array( $f ) || empty( $f['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'       => (string) $f['id'],
				'name'     => isset( $f['name'] ) ? (string) $f['name'] : '',
				'mimeType' => isset( $f['mimeType'] ) ? (string) $f['mimeType'] : '',
				'iconLink' => isset( $f['iconLink'] ) ? (string) $f['iconLink'] : '',
			);
		}
	}

	return rest_ensure_response( array( 'ok' => true, 'files' => $out ) );
}

/**
 * Register the two Plan 43-01 routes. is_user_logged_in permission — each route
 * only ever acts on the caller's OWN data (their admin groups / their own
 * outgoing welcome message), so an authed cookie/nonce request is sufficient and
 * passes the hub's pri-99 REST auth gate the same way wp/v2 authed requests do.
 */
function gs_rest_register_agent_create_routes() {
	if ( ! function_exists( 'register_rest_route' ) ) {
		return;
	}
	register_rest_route(
		'gs/v1',
		'/agent-admin-groups',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_agent_admin_groups',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
		)
	);
	register_rest_route(
		'gs/v1',
		'/agent-welcome',
		array(
			'methods'             => 'POST',
			'callback'            => 'gs_rest_agent_welcome',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
			'args'                => array(
				'agent_user_id' => array(
					'required' => true,
				),
			),
		)
	);

	// Plan 44-01 — Projects-tab routes (same gs/v1 namespace, same permission).
	register_rest_route(
		'gs/v1',
		'/chat-attach-project',
		array(
			'methods'             => 'POST',
			'callback'            => 'gs_rest_chat_attach_project',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
			'args'                => array(
				'thread_id'  => array( 'required' => true ),
				'project_id' => array( 'required' => true ),
			),
		)
	);
	register_rest_route(
		'gs/v1',
		'/chat-projects',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_chat_projects',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
		)
	);

	// Agent-create form parity — populates the "Reports to" dropdown with the
	// group's existing agents (value=slug). Logged-in only; ID-only resolution.
	register_rest_route(
		'gs/v1',
		'/agent-list',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_agent_list',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
			'args'                => array(
				'group_id' => array( 'required' => true ),
			),
		)
	);

	// Agent-create form parity — populates the "Billing account" dropdown with
	// the group's HUMAN members (agents excluded). Logged-in only; ID-only.
	register_rest_route(
		'gs/v1',
		'/group-members',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_group_members',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
			'args'                => array(
				'group_id' => array( 'required' => true ),
				'term'     => array( 'required' => false ),
			),
		)
	);

	// Desktop "New sequence" form parity — the billing-account dropdown on the
	// DESKTOP has no per-sequence group context, so it lists the HUMAN members
	// of the groups the CURRENT USER ADMINS (authorized-to-bill set). Logged-in
	// only; ID-only resolution.
	register_rest_route(
		'gs/v1',
		'/my-billable-members',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_my_billable_members',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
		)
	);

	// Agent-create form: the group's Departments (Payments → Departments,
	// groupmeta _psoo_group_departments). GET lists; POST (group admin) creates.
	register_rest_route(
		'gs/v1',
		'/group-departments',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'gs_rest_group_departments',
				'permission_callback' => function () {
					return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
				},
				'args'                => array( 'group_id' => array( 'required' => true ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gs_rest_group_department_create',
				'permission_callback' => function () {
					return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
				},
				'args'                => array( 'group_id' => array( 'required' => true ) ),
			),
		)
	);

	// Step-studio "Update Project" output picker: the group's Project Manager
	// projects. GET lists; POST (group admin) creates + links a project to the
	// group. Logged-in only; all cross-plugin calls are class/method guarded.
	register_rest_route(
		'gs/v1',
		'/group-projects',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'gs_rest_group_projects',
				'permission_callback' => function () {
					return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
				},
				'args'                => array( 'group_id' => array( 'required' => true ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gs_rest_group_project_create',
				'permission_callback' => function () {
					return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
				},
				'args'                => array( 'group_id' => array( 'required' => true ) ),
			),
		)
	);

	// Step-studio Context Files: the group's existing prompt sequences, so a step
	// can pull another (completed) sequence's output in as context.
	register_rest_route(
		'gs/v1',
		'/group-sequences',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_group_sequences',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
			'args'                => array( 'group_id' => array( 'required' => true ) ),
		)
	);

	/* ── v8.3 step-studio: Context Files → Google Drive (per-user OAuth). ── */

	// Is Drive configured + is the caller connected? Logged-in only.
	register_rest_route(
		'gs/v1',
		'/gdrive/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_gdrive_status',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
		)
	);

	// Start the Google consent flow (top-level redirect / config notice). Logged-in.
	register_rest_route(
		'gs/v1',
		'/gdrive/connect',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_gdrive_connect',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
		)
	);

	// Google's redirect back. __return_true: Google arrives without our auth
	// cookie — the `state` nonce binds + verifies the uid inside the handler.
	register_rest_route(
		'gs/v1',
		'/gdrive/callback',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_gdrive_callback',
			'permission_callback' => '__return_true',
		)
	);

	// List the caller's Drive files (optional ?q= name filter). Logged-in only.
	register_rest_route(
		'gs/v1',
		'/gdrive/files',
		array(
			'methods'             => 'GET',
			'callback'            => 'gs_rest_gdrive_files',
			'permission_callback' => function () {
				return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			},
			'args'                => array(
				'q' => array( 'required' => false ),
			),
		)
	);
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'gs_rest_register_agent_create_routes' );
}
