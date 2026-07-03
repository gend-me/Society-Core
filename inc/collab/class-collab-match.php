<?php
/**
 * gend-society — GenD Match mutual-match engine (Phase 83-01, v12.0).
 *
 * Turns the Phase-82 swipe LEDGER into a MATCH engine. This is the class that
 * POPULATES wp_gs_collab_matches (Phase 82 created it schema-only). A pure static
 * logic class — NO hooks. Called thinly by Gend_GS_Collab_REST::route_swipe after a
 * successful RIGHT-swipe ledger write.
 *
 * maybe_create_match($from,$to):
 *   1. Reciprocal RIGHT-swipe lookup in gs_collab_swipes (idx_to(to_group_id,decision)).
 *   2. Normalize the pair (LEAST/GREATEST) and race-safely INSERT IGNORE it into
 *      gs_collab_matches under UNIQUE(group_a,group_b) — MIRRORS the record_swipe
 *      INSERT-IGNORE idiom (class-collab-schema.php:252-267).
 *   3. Capture $wpdb->rows_affected + $wpdb->insert_id IMMEDIATELY (Pitfall 2). Side
 *      effects (thread seed + notify) run ONLY on the rows_affected===1 winner so a
 *      simultaneous cross-swipe (A->B and B->A both firing) collapses to exactly ONE
 *      match, ONE intro thread, ONE notification per recipient (Pitfall 1).
 *
 * On the winner branch it:
 *   - seeds ONE BuddyPress private-message intro thread (messages_new_message) with the
 *     union of both groups' admins+mods and stores its thread id on the match row
 *     (intro_thread_id — an EXISTING column; NO schema change, DB_VERSION stays 1.0.0).
 *     The rail's project_id is a nullable read-time overlay, so a project-less intro
 *     thread is the native case — NO project/contract is created (Phase 84 owns that,
 *     and attaches its contract to this SAME thread id).
 *   - fires the locked "in-app + batched email" MATCH-02 notification: (a) an in-app BP
 *     bell per recipient (plus the thread's own unread badge), and (b) a
 *     function_exists('em_inbox_send_as')-guarded, per-recipient transient-debounced
 *     batched member-inbox email. The email leg is a GUARDED CROSS-PLUGIN CALL into
 *     email-manager — NOT an edit to it; if em_inbox_send_as is absent the email is
 *     silently skipped and the in-app leg still fires (graceful degradation).
 *
 * Robustness spine: the MATCH ROW is the source of truth. A missing/failed thread seed
 * or email send is error_log'd and swallowed — it NEVER throws and NEVER blocks the
 * swipe response (Pitfall 3).
 *
 * Reusable by Phase 89's hub-side match detection.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Match {

	/**
	 * Detect a reciprocal right-swipe and, race-safely, create exactly one match.
	 *
	 * Returns the new match id ONLY when THIS call created a brand-new match row
	 * (the rows_affected===1 winner). Returns 0 when there is no reciprocal right
	 * swipe yet, OR when a concurrent cross-swipe already created the row (the
	 * INSERT-IGNORE loser — no side effects, preventing a double-seed/double-notify).
	 *
	 * @param int $from Acting group id (the group whose admin/mod just right-swiped).
	 * @param int $to   Candidate group id that was right-swiped.
	 * @return int New match id on a brand-new match, else 0.
	 */
	public static function maybe_create_match( int $from, int $to ) : int {
		global $wpdb;

		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return 0;
		}

		$swipes  = Gend_GS_Collab_Schema::swipes_table();
		$matches = Gend_GS_Collab_Schema::matches_table();

		// Reciprocal RIGHT swipe? (the OTHER group already right-swiped us.)
		// Uses idx_to(to_group_id, decision) forward-fit in Phase 82 (schema.php:190).
		$reciprocal = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$swipes}
				  WHERE from_group_id = %d AND to_group_id = %d AND decision = 'right' LIMIT 1",
				$to,
				$from
			)
		);
		if ( $reciprocal <= 0 ) {
			return 0; // No mutual interest yet.
		}

		// Normalize the unordered pair so a cross-swipe (A->B and B->A) maps to the
		// SAME UNIQUE(group_a,group_b) row.
		$a = min( $from, $to );
		$b = max( $from, $to );

		// Race-safe: UNIQUE(group_a,group_b) + INSERT IGNORE. A simultaneous
		// cross-swipe yields ONE row. MIRRORS record_swipe (schema.php:252-263).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$matches}
					(group_a, group_b, status, created_at)
				 VALUES (%d, %d, 'matched', %d)",
				$a,
				$b,
				time()
			)
		);

		// Pitfall 2: capture affected-rows + insert_id on the line IMMEDIATELY after
		// the INSERT, BEFORE any other $wpdb access (including inside the side effects).
		$affected = (int) $wpdb->rows_affected;
		$match_id = (int) $wpdb->insert_id;

		// Pitfall 1: side effects run ONLY on the INSERT-IGNORE winner. affected===0
		// means the OTHER concurrent swipe already created the row — no-op here so the
		// thread/notify never double-fire.
		if ( 1 !== $affected || $match_id <= 0 ) {
			return 0;
		}

		// I created the match → seed exactly one thread + notify once.
		self::seed_intro_thread( $match_id, $a, $b );
		self::notify_match( $match_id, $a, $b );

		return $match_id;
	}

	/**
	 * Union of admins + mods of both groups, deduped. This is BOTH the intro-thread
	 * recipient set AND the notification recipient set (locked participant model).
	 *
	 * @param int $group_a Normalized lower group id.
	 * @param int $group_b Normalized higher group id.
	 * @return int[] Deduped, filtered list of user ids.
	 */
	private static function participant_uids( int $group_a, int $group_b ) : array {
		$uids = array();

		if ( function_exists( 'groups_get_group_admins' ) && function_exists( 'groups_get_group_mods' ) ) {
			$sets = array(
				groups_get_group_admins( $group_a ),
				groups_get_group_mods( $group_a ),
				groups_get_group_admins( $group_b ),
				groups_get_group_mods( $group_b ),
			);
			foreach ( $sets as $set ) {
				foreach ( (array) $set as $m ) {
					if ( is_object( $m ) && isset( $m->user_id ) ) {
						$uids[] = (int) $m->user_id;
					}
				}
			}
		}

		// array_unique dedupes an operator who is admin/mod of BOTH groups (a
		// self-adjacent match) — harmless, still one entry.
		return array_values( array_unique( array_filter( $uids ) ) );
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
	 * Seed ONE BuddyPress private-message intro thread for the match and store its
	 * thread id on the match row. Project-less by design — DO NOT pass a project_id,
	 * DO NOT create any project/contract (Phase 84 attaches its contract to this SAME
	 * thread via a wp_pm_meta linkage row later).
	 *
	 * Robustness: if messages_new_message is missing or returns a WP_Error/falsy, the
	 * match row STILL stands — error_log and RETURN, leaving intro_thread_id NULL.
	 * NEVER throws.
	 *
	 * @param int $match_id New match row id.
	 * @param int $a        Normalized lower group id.
	 * @param int $b        Normalized higher group id.
	 * @return void
	 */
	private static function seed_intro_thread( int $match_id, int $a, int $b ) : void {
		global $wpdb;

		if ( ! function_exists( 'messages_new_message' ) ) {
			error_log( '[gs_collab_match] messages_new_message unavailable — intro thread not seeded for match ' . $match_id );
			return;
		}

		$union = self::participant_uids( $a, $b );
		if ( empty( $union ) ) {
			error_log( '[gs_collab_match] no participants for match ' . $match_id . ' — intro thread not seeded' );
			return;
		}

		// The actor who completed the match is the natural sender (a member of the
		// union). If somehow not in the union, fall back to the first participant.
		$actor = (int) get_current_user_id();
		if ( $actor <= 0 || ! in_array( $actor, $union, true ) ) {
			$actor = (int) $union[0];
		}

		$recipients = array_values( array_diff( $union, array( $actor ) ) );
		if ( empty( $recipients ) ) {
			// A single-operator self-adjacent match: no one else to message.
			error_log( '[gs_collab_match] sole participant for match ' . $match_id . ' — intro thread skipped' );
			return;
		}

		$name_a  = self::group_name( $a );
		$name_b  = self::group_name( $b );
		$subject = sprintf( '%s x %s — collaboration intro', $name_a, $name_b );
		$body    = sprintf(
			"You matched! %s and %s are both interested in collaborating. Start the conversation here.",
			$name_a,
			$name_b
		);

		$thread_id = messages_new_message(
			array(
				'sender_id'  => $actor,
				'subject'    => $subject,
				'content'    => $body,
				'recipients' => $recipients,
			)
		);

		if ( is_wp_error( $thread_id ) || ! $thread_id ) {
			$msg = is_wp_error( $thread_id ) ? $thread_id->get_error_message() : 'falsy return';
			error_log( '[gs_collab_match] messages_new_message failed for match ' . $match_id . ': ' . $msg );
			return; // Match row stands; intro_thread_id stays NULL.
		}

		$wpdb->update(
			Gend_GS_Collab_Schema::matches_table(),
			array( 'intro_thread_id' => (int) $thread_id ),
			array( 'id' => $match_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Fire the locked "in-app + batched email" MATCH-02 notification. Runs ONLY in the
	 * rows_affected===1 winner branch of maybe_create_match, so it is inherently
	 * once-per-match (satisfies the storm cap). NEVER throws; any failure is logged and
	 * swallowed so the swipe response is never blocked (Pitfall 3).
	 *
	 * @param int $match_id New match row id.
	 * @param int $a        Normalized lower group id.
	 * @param int $b        Normalized higher group id.
	 * @return void
	 */
	private static function notify_match( int $match_id, int $a, int $b ) : void {
		$actor = (int) get_current_user_id();
		$union = self::participant_uids( $a, $b );
		// Recipients = union minus the actor (who just performed the swipe).
		$recipients = array_values( array_diff( $union, array( $actor ) ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$name_a = self::group_name( $a );
		$name_b = self::group_name( $b );

		// -----------------------------------------------------------------
		// (a) In-app (primary, cheap). The seeded intro thread's own unread
		//     badge is already the primary in-app signal (free). Add a
		//     minimal, storm-safe explicit BP bell — because this runs only in
		//     the rows_affected===1 branch it is inherently once-per-match, so
		//     at most one bell per recipient. Wrapped so a missing formatter
		//     can NEVER fatal.
		// -----------------------------------------------------------------
		if ( function_exists( 'bp_notifications_add_notification' ) ) {
			$now = function_exists( 'bp_core_current_time' ) ? bp_core_current_time() : current_time( 'mysql', true );
			foreach ( $recipients as $uid ) {
				try {
					bp_notifications_add_notification(
						array(
							'user_id'          => (int) $uid,
							'item_id'          => $match_id,
							'component_name'   => 'gs_collab',
							'component_action' => 'new_match',
							'date_notified'    => $now,
							'is_new'           => 1,
						)
					);
				} catch ( \Throwable $e ) {
					error_log( '[gs_collab_match] bp bell failed for uid ' . $uid . ' match ' . $match_id . ': ' . $e->getMessage() );
				}
			}
		}

		// -----------------------------------------------------------------
		// (b) Batched member-inbox email (locked leg). BEST-EFFORT: guarded by
		//     function_exists so a gend-society-only deploy (email-manager
		//     absent/older) degrades to in-app-only; per-recipient transient-
		//     debounced so a bulk-swipe session that forms several matches for
		//     the same recipient in quick succession sends at most one email per
		//     recipient per window; any WP_Error/failure is logged and swallowed;
		//     MUST NOT block or fatal the swipe response.
		// -----------------------------------------------------------------
		if ( ! function_exists( 'em_inbox_send_as' ) ) {
			return; // Email-manager absent/older — in-app leg above already covered everyone.
		}

		// Resolve a stable SENDER address: actor's inbox address → actor's
		// user_email → site admin_email. If none is a valid email, we can't send.
		$from_addr = self::inbox_address( $actor );
		if ( ! $from_addr ) {
			$admin_email = get_option( 'admin_email' );
			$from_addr   = ( is_string( $admin_email ) && is_email( $admin_email ) ) ? $admin_email : '';
		}
		if ( ! $from_addr ) {
			return; // No usable from-address; in-app leg already covered everyone.
		}

		$subject = sprintf( 'You have a new GenD Match — %s x %s', $name_a, $name_b );
		$body_plain = sprintf(
			"You have a new GenD Match!\n\n%s and %s are both interested in collaborating. Open your member inbox to start the conversation.",
			$name_a,
			$name_b
		);
		$body_html = sprintf(
			'<p><strong>You have a new GenD Match!</strong></p><p>%s and %s are both interested in collaborating. Open your member inbox to start the conversation.</p>',
			esc_html( $name_a ),
			esc_html( $name_b )
		);
		$bodies = array( 'body_plain' => $body_plain, 'body_html' => $body_html );

		foreach ( $recipients as $uid ) {
			$uid = (int) $uid;

			// Per-recipient transient debounce (storm-safety, REQUIRED). Keyed
			// per-(recipient,match) so it is exactly-once-per-match while still
			// coalescing repeated fires within the 5-minute window.
			$key = 'gs_collab_match_mail_' . $uid . '_' . $match_id;
			if ( get_transient( $key ) ) {
				continue; // Already emailed this recipient for this match recently.
			}
			// Set BEFORE sending so a concurrent match in the same window is also debounced.
			set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );

			$to_addr = self::inbox_address( $uid );
			if ( ! $to_addr ) {
				continue; // No valid address; the in-app leg already covers this recipient.
			}

			$res = em_inbox_send_as( $from_addr, $to_addr, $subject, $bodies );
			if ( is_wp_error( $res ) || ! $res ) {
				$msg = is_wp_error( $res ) ? $res->get_error_message() : 'falsy return';
				error_log( '[gs_collab_match] em_inbox_send_as failed for uid ' . $uid . ' match ' . $match_id . ': ' . $msg );
				// Swallow — the match + thread + in-app already stand. NEVER throw.
			}
		}
	}

	/**
	 * Resolve a user's member-inbox address: em_inbox_address user_meta first, then
	 * their WP user_email. Returns a valid email string or '' if neither resolves.
	 *
	 * @param int $uid User id.
	 * @return string Valid email address, or '' if none.
	 */
	private static function inbox_address( int $uid ) : string {
		if ( $uid <= 0 ) {
			return '';
		}
		$addr = get_user_meta( $uid, 'em_inbox_address', true );
		if ( is_string( $addr ) && is_email( $addr ) ) {
			return $addr;
		}
		$user = get_userdata( $uid );
		if ( $user && is_email( $user->user_email ) ) {
			return $user->user_email;
		}
		return '';
	}
}
