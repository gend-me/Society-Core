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

	$tabs = array(
		'members'  => array( '👥', __( 'Members', 'gend-society' ) ),
		'agents'   => array( '🤖', __( 'Agents', 'gend-society' ) ),
		'projects' => array( '📁', __( 'Projects', 'gend-society' ) ),
	);

	$esc_url  = function_exists( 'esc_url' ) ? 'esc_url' : 'esc_attr';
	$esc_attr = function_exists( 'esc_attr' ) ? 'esc_attr' : 'htmlspecialchars';
	$esc_html = function_exists( 'esc_html' ) ? 'esc_html' : 'htmlspecialchars';
	?>
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
	  </nav>
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
			var obs = new MutationObserver(function () {
				var tpl = document.getElementById('gs-chat-tabs-template');
				if (tpl && tpl.dataset.gsInjected && !document.querySelector('.gs-chat-tabs')) {
					tpl.dataset.gsInjected = '';
				}
				tryInject();
			});
			obs.observe(document.body, { childList: true, subtree: true });
			// Keep it lightweight — stop observing after the DOM settles.
			setTimeout(function () { obs.disconnect(); }, 4000);
			// popstate (email-manager pushes URL on AJAX nav) → re-sync the
			// active pill from the new URL.
			window.addEventListener('popstate', function () {
				applyActive(document.querySelector('.gs-chat-tabs'));
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
function gs_rest_agent_admin_groups() {
	$uid  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$rows = array();

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

		$rows[] = array(
			'group_id'  => $gid,
			'name'      => $name,
			'is_webapp' => $is_webapp,
		);
	}

	return rest_ensure_response( array( 'ok' => true, 'groups' => $rows ) );
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
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'gs_rest_register_agent_create_routes' );
}
