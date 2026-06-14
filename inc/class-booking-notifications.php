<?php
/**
 * Phase 29 Plan 03 — Booking Notifications.
 *
 * Subscribes to the 3 booking lifecycle action hooks fired by Plan 29-01
 * (Gend_GS_Booking_Public_REST — public bookee flow) and Plan 29-02
 * (Gend_GS_Booking_Meetings_REST — authed host-create flow):
 *
 *   - gs_booking_created     ($meeting_id, $host_user_id)
 *   - gs_booking_cancelled   ($meeting_id, $host_user_id, $actor /* 'guest'|'host' *\/)
 *   - gs_booking_rescheduled ($meeting_id, $host_user_id, $old_starts_utc)
 *
 * Sends 4 branded HTML email templates (confirmation / reminder / cancellation /
 * reschedule) to BOTH host and bookee, plus schedules a single-event cron
 * reminder via wp_schedule_single_event() at starts_at_utc - 15 minutes.
 *
 * Email rail: wp_mail() — email-manager's EM_Email_SMTP class has already
 * hooked phpmailer_init at plugin bootstrap, so wp_mail() automatically
 * routes through SMTP transport when the plugin is active.
 *
 * Brand inheritance: defensively reads email-manager's get_option('em_email_templates')
 * + 'em_active_template'; if a template with a {{body}} placeholder exists,
 * wraps our inner body in that; otherwise falls back to inline HTML.
 *
 * Pitfall 2 (load-bearing): every subscriber wraps the entire body in
 * try/catch \Throwable so an email render or send failure NEVER fatals
 * the originating booking request (would auto-deactivate the plugin
 * on a clean fatal during request lifecycle).
 *
 * Pitfall 7 (identity discipline): only fired in response to action hooks
 * that carry $meeting_id explicitly. No get_current_user_id() reads — the
 * cron reminder handler is fired by WP-Cron which has no user context.
 *
 * Reminder cron hook name: 'gs_booking_send_reminder' (namespaced; not
 * 'gs_booking_reminder' which could collide with other booking systems).
 *
 * Phase 29 Plan 03 — file_exists guard on the include line in gend-society.php
 * (Pitfall 1) so the hub PVC .no-plugin-sync quirk doesn't fatal the entrypoint
 * when this class file hasn't been kubectl cp'd yet.
 *
 * @package GenD_Society
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Booking_Notifications {

	/**
	 * Cron hook name for the single-event reminder. Namespaced to avoid
	 * collision with other booking systems (e.g. 'gs_booking_reminder' is
	 * intentionally NOT used — too generic).
	 */
	const REMINDER_HOOK = 'gs_booking_send_reminder';

	/**
	 * Lead time in minutes before meeting start when the reminder fires.
	 * Default 15 min per Phase 29 CONTEXT.md (Claude's Discretion section).
	 */
	const REMINDER_LEAD_MIN = 15;

	/**
	 * Wire all 4 subscribers. Called once from gend-society.php bootstrap
	 * AFTER the file_exists guard. Subscribers are pure add_action calls
	 * (no side effects), so calling init() at include time is safe — the
	 * actual booking handlers fire LATER on the respective REST routes.
	 *
	 * 3 lifecycle subscribers + 1 cron handler:
	 *   add_action('gs_booking_created',     ...) — Plan 29-01/02 both fire
	 *   add_action('gs_booking_cancelled',   ...) — Plan 29-01 (actor=guest), 29-02 (actor=host)
	 *   add_action('gs_booking_rescheduled', ...) — Plan 29-01 only (29-02 no host-reschedule yet)
	 *   add_action('gs_booking_send_reminder', ...) — WP-Cron handler
	 */
	public static function init() : void {
		add_action( 'gs_booking_created',     array( __CLASS__, 'on_created' ),         10, 2 );
		add_action( 'gs_booking_cancelled',   array( __CLASS__, 'on_cancelled' ),       10, 3 );
		add_action( 'gs_booking_rescheduled', array( __CLASS__, 'on_rescheduled' ),     10, 3 );
		add_action( self::REMINDER_HOOK,      array( __CLASS__, 'handle_reminder_cron' ), 10, 1 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Subscribers — each wrapped in try/catch \Throwable (Pitfall 2).
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Confirmation send + reminder schedule on gs_booking_created.
	 *
	 * Delegates to render_confirmed() for the email payload + schedule_reminder()
	 * for the WP-Cron registration.
	 *
	 * @param int $meeting_id   wp_gs_member_meetings.id
	 * @param int $host_user_id wp_gs_member_meetings.member_id (host)
	 */
	public static function on_created( $meeting_id, $host_user_id ) {
		try {
			$meeting_id = (int) $meeting_id;
			$ctx        = self::load_context( $meeting_id );
			if ( ! $ctx ) {
				return;
			}
			$rendered = self::render_confirmed( $ctx );
			self::send_to_both( $ctx, $rendered );
			self::schedule_reminder( $ctx );
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_FATAL on_created mtg=' . (int) $meeting_id . ' err=' . $e->getMessage() );
		}
	}

	/**
	 * Cancellation email + unschedule pending reminder on gs_booking_cancelled.
	 *
	 * Delegates to render_cancelled() for the email payload + cancel_reminder()
	 * for the WP-Cron tear-down.
	 *
	 * @param int    $meeting_id   wp_gs_member_meetings.id
	 * @param int    $host_user_id wp_gs_member_meetings.member_id (host)
	 * @param string $actor        'guest' (Plan 29-01) | 'host' (Plan 29-02)
	 */
	public static function on_cancelled( $meeting_id, $host_user_id, $actor = 'guest' ) {
		try {
			$meeting_id = (int) $meeting_id;
			$ctx = self::load_context( $meeting_id );
			if ( ! $ctx ) {
				return;
			}
			$ctx['actor'] = ( $actor === 'host' ) ? 'host' : 'guest';
			$rendered     = self::render_cancelled( $ctx );
			self::send_to_both( $ctx, $rendered );
			self::cancel_reminder( $meeting_id );
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_FATAL on_cancelled mtg=' . (int) $meeting_id . ' err=' . $e->getMessage() );
		}
	}

	/**
	 * Reschedule email + reschedule reminder cron on gs_booking_rescheduled.
	 *
	 * Delegates to render_rescheduled() for the email payload + cancel_reminder()
	 * + schedule_reminder() to re-anchor the WP-Cron event on the new time.
	 *
	 * @param int    $meeting_id     wp_gs_member_meetings.id
	 * @param int    $host_user_id   wp_gs_member_meetings.member_id (host)
	 * @param string $old_starts_utc Previous starts_at_utc (MySQL datetime, UTC)
	 */
	public static function on_rescheduled( $meeting_id, $host_user_id, $old_starts_utc = '' ) {
		try {
			$meeting_id = (int) $meeting_id;
			$ctx = self::load_context( $meeting_id );
			if ( ! $ctx ) {
				return;
			}
			$ctx['old_starts_utc'] = (string) $old_starts_utc;
			$rendered              = self::render_rescheduled( $ctx );
			self::send_to_both( $ctx, $rendered );
			// Remove any existing reminder for the OLD time, schedule fresh for the NEW time.
			self::cancel_reminder( $meeting_id );
			self::schedule_reminder( $ctx );
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_FATAL on_rescheduled mtg=' . (int) $meeting_id . ' err=' . $e->getMessage() );
		}
	}

	/**
	 * WP-Cron handler — fires once at starts_at_utc - REMINDER_LEAD_MIN.
	 * Re-fetches the meeting (fresh row) and skips if status !== 'booked'
	 * so cancelled meetings NEVER receive a reminder even if the cron event
	 * is somehow still scheduled.
	 *
	 * Idempotent against double-fire: cron may technically run twice in
	 * pathological cases; the status check is the primary defense, and
	 * a duplicate reminder is far less harmful than a missed one, so we
	 * intentionally do NOT track a _gs_reminder_sent_at sentinel.
	 *
	 * Delegates to render_reminder() for the email payload.
	 *
	 * @param int $meeting_id wp_gs_member_meetings.id
	 */
	public static function handle_reminder_cron( $meeting_id ) {
		try {
			$meeting_id = (int) $meeting_id;
			$ctx = self::load_context( $meeting_id );
			if ( ! $ctx ) {
				return;
			}
			if ( $ctx['status'] !== 'booked' ) {
				return; // never remind cancelled / completed / no_show meetings
			}
			$rendered = self::render_reminder( $ctx );
			self::send_to_both( $ctx, $rendered );
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_FATAL reminder mtg=' . (int) $meeting_id . ' err=' . $e->getMessage() );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Context loader — single source of truth for meeting + host + meta.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Load the meeting row + resolve the host + decode meta JSON +
	 * produce a ready-to-render context array. Returns null if the
	 * meeting OR host can't be resolved — subscriber returns silently
	 * (no fatal, no email storm).
	 *
	 * @param int $meeting_id
	 * @return array|null
	 */
	private static function load_context( $meeting_id ) {
		global $wpdb;
		$tbl = Gend_GS_Availability_Schema::table_meetings();
		// Defensive: include guest_phone in projection but tolerate its absence
		// (Plan 29-05 may dbDelta-add it; older blogs may not have the column).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, member_id, guest_user_id, guest_name, guest_email,
				        starts_at_utc, ends_at_utc, meeting_type, meeting_meta_json,
				        status, cancellation_token
				   FROM {$tbl} WHERE id = %d",
				(int) $meeting_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$host_user = get_userdata( (int) $row['member_id'] );
		if ( ! $host_user ) {
			return null;
		}
		$host_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
			? Gend_GS_Calendar_Events_REST::get_member_timezone( (int) $row['member_id'] )
			: 'UTC';
		$meta_raw = (string) $row['meeting_meta_json'];
		$meta = array();
		if ( $meta_raw !== '' ) {
			$decoded = json_decode( $meta_raw, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		return array(
			'meeting_id'         => (int) $row['id'],
			'host_user_id'       => (int) $row['member_id'],
			'host_email'         => (string) $host_user->user_email,
			'host_name'          => $host_user->display_name ? $host_user->display_name : $host_user->user_login,
			'host_tz'            => $host_tz,
			'guest_name'         => (string) $row['guest_name'],
			'guest_email'        => (string) $row['guest_email'],
			'starts_at_utc'      => (string) $row['starts_at_utc'],
			'ends_at_utc'        => (string) $row['ends_at_utc'],
			'meeting_type'       => (string) $row['meeting_type'],
			'meta'               => $meta,
			'status'             => (string) $row['status'],
			'cancellation_token' => (string) $row['cancellation_token'],
			'public_cancel_url'  => self::cancel_url( (string) $row['cancellation_token'] ),
		);
	}

	/**
	 * Build the bookee-facing cancel/reschedule URL from the meeting's
	 * cancellation_token. Points at the Plan 29-01 public REST route.
	 *
	 * @param string $token 43-char alphanumeric per-meeting token
	 * @return string Absolute URL
	 */
	private static function cancel_url( $token ) {
		return rest_url( 'gs/v1/calendar/public/meeting/' . rawurlencode( (string) $token ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Time formatting — host-tz + UTC dual labels so the email is useful
	// no matter which timezone the recipient reads it in.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Format a MySQL UTC datetime in the given IANA timezone.
	 *
	 * @param string $mysql_utc 'Y-m-d H:i:s' in UTC
	 * @param string $tz        IANA timezone string
	 * @return string Pretty label like "Tue, Jun 17, 2026 9:00 AM EDT"
	 */
	private static function format_in_tz( $mysql_utc, $tz ) {
		try {
			$dt = new DateTimeImmutable( (string) $mysql_utc, new DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( new DateTimeZone( (string) $tz ) )->format( 'D, M j, Y g:i A T' );
		} catch ( \Throwable $e ) {
			return $mysql_utc . ' UTC';
		}
	}

	/**
	 * Produce a "host-tz (UTC equivalent)" dual label so both parties can
	 * read the time correctly without tz mental math. Bookee tz is auto-
	 * detected client-side in Plan 29-04's public UI; for email we render
	 * BOTH the host tz and UTC so the recipient picks.
	 *
	 * @param string $mysql_utc 'Y-m-d H:i:s' in UTC
	 * @param string $host_tz   IANA timezone string
	 * @return string Like "Tue, Jun 17, 2026 9:00 AM EDT (Tue, Jun 17, 2026 1:00 PM UTC)"
	 */
	private static function format_dual_tz( $mysql_utc, $host_tz ) {
		$host_label = self::format_in_tz( $mysql_utc, $host_tz );
		$utc_label  = self::format_in_tz( $mysql_utc, 'UTC' );
		return $host_label . ' (' . $utc_label . ')';
	}

	// ─────────────────────────────────────────────────────────────────────
	// 4 template renderers — each returns ['subject', 'body_html', 'body_text'].
	// Subjects + bodies honor host-tz formatting. Bodies wrap inner HTML
	// through the email-manager brand template when available.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Render the booking-confirmation email (sent to BOTH host and bookee
	 * on gs_booking_created).
	 *
	 * @param array $ctx load_context() output
	 * @return array { subject, body_html, body_text }
	 */
	public static function render_confirmed( $ctx ) {
		$subject = sprintf(
			'Meeting confirmed: %s on %s',
			self::type_label( $ctx['meeting_type'] ),
			self::format_in_tz( $ctx['starts_at_utc'], $ctx['host_tz'] )
		);
		$body_html = self::wrap_brand( self::html_confirmed( $ctx ) );
		$body_text = self::text_confirmed( $ctx );
		return array( 'subject' => $subject, 'body_html' => $body_html, 'body_text' => $body_text );
	}

	/**
	 * Render the reminder email (sent 15 min before via WP-Cron).
	 *
	 * @param array $ctx load_context() output
	 * @return array { subject, body_html, body_text }
	 */
	public static function render_reminder( $ctx ) {
		$subject = sprintf(
			'Reminder: meeting in %d minutes — %s',
			(int) self::REMINDER_LEAD_MIN,
			self::format_in_tz( $ctx['starts_at_utc'], $ctx['host_tz'] )
		);
		$body_html = self::wrap_brand( self::html_reminder( $ctx ) );
		$body_text = self::text_reminder( $ctx );
		return array( 'subject' => $subject, 'body_html' => $body_html, 'body_text' => $body_text );
	}

	/**
	 * Render the cancellation email (sent to BOTH parties).
	 *
	 * @param array $ctx load_context() output WITH 'actor' key
	 * @return array { subject, body_html, body_text }
	 */
	public static function render_cancelled( $ctx ) {
		$subject   = sprintf( 'Meeting cancelled: %s', self::format_in_tz( $ctx['starts_at_utc'], $ctx['host_tz'] ) );
		$body_html = self::wrap_brand( self::html_cancelled( $ctx ) );
		$body_text = self::text_cancelled( $ctx );
		return array( 'subject' => $subject, 'body_html' => $body_html, 'body_text' => $body_text );
	}

	/**
	 * Render the reschedule email (sent to BOTH parties with old + new times).
	 *
	 * @param array $ctx load_context() output WITH 'old_starts_utc' key
	 * @return array { subject, body_html, body_text }
	 */
	public static function render_rescheduled( $ctx ) {
		$subject   = sprintf( 'Meeting rescheduled to %s', self::format_in_tz( $ctx['starts_at_utc'], $ctx['host_tz'] ) );
		$body_html = self::wrap_brand( self::html_rescheduled( $ctx ) );
		$body_text = self::text_rescheduled( $ctx );
		return array( 'subject' => $subject, 'body_html' => $body_html, 'body_text' => $body_text );
	}

	/**
	 * Pretty label for the meeting type column.
	 *
	 * @param string $type 'in_person' | 'phone' | 'video'
	 * @return string
	 */
	private static function type_label( $type ) {
		switch ( (string) $type ) {
			case 'in_person': return 'in-person meeting';
			case 'phone':     return 'phone call';
			case 'video':     return 'video call';
			default:          return 'meeting';
		}
	}

	/**
	 * Per-type rendering of the meta block (address / phone / video URL).
	 * For video provider='gend' we render a meet.gend.me placeholder URL —
	 * Phase 30 mints the JWT-bound room URL at join time.
	 *
	 * @param array $ctx load_context() output
	 * @return string Inline HTML fragment (already escaped)
	 */
	private static function meta_summary( $ctx ) {
		$meta = isset( $ctx['meta'] ) && is_array( $ctx['meta'] ) ? $ctx['meta'] : array();
		switch ( (string) $ctx['meeting_type'] ) {
			case 'in_person':
				$addr = isset( $meta['address'] ) ? (string) $meta['address'] : 'TBD';
				return '<strong>Address:</strong> ' . esc_html( $addr );
			case 'phone':
				$num = isset( $meta['callee_number'] ) ? (string) $meta['callee_number'] : 'TBD';
				$dir = isset( $meta['direction'] ) && $meta['direction'] === 'host_calls_guest'
					? 'host will call you'
					: 'you will call the host';
				return '<strong>Phone:</strong> ' . esc_html( $num ) . ' (' . esc_html( $dir ) . ')';
			case 'video':
				$provider = isset( $meta['provider'] ) ? (string) $meta['provider'] : '';
				if ( $provider === 'gend' ) {
					$room = isset( $meta['jitsi_room'] ) ? (string) $meta['jitsi_room'] : '';
					$url  = 'https://meet.gend.me/' . rawurlencode( $room );
					return '<strong>Video room:</strong> <a href="' . esc_url( $url ) . '">' . esc_html( $url )
					     . '</a> (join unlocks at scheduled time)';
				}
				$url = isset( $meta['room_url'] ) ? (string) $meta['room_url'] : '';
				if ( $url === '' ) {
					return '<strong>Video link:</strong> TBD';
				}
				return '<strong>Video link:</strong> <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
			default:
				return '';
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// HTML + plain-text per-template body builders. Plain-text is just
	// wp_strip_all_tags of the HTML — keeps content parity with zero drift.
	// ─────────────────────────────────────────────────────────────────────

	private static function html_confirmed( $ctx ) {
		$when   = self::format_dual_tz( $ctx['starts_at_utc'], $ctx['host_tz'] );
		$meta   = self::meta_summary( $ctx );
		$cancel = esc_url( $ctx['public_cancel_url'] );
		return '<p>Your ' . esc_html( self::type_label( $ctx['meeting_type'] ) ) . ' is confirmed.</p>'
		     . '<p><strong>When:</strong> ' . esc_html( $when ) . '</p>'
		     . '<p><strong>Host:</strong> ' . esc_html( $ctx['host_name'] ) . '</p>'
		     . '<p><strong>Guest:</strong> ' . esc_html( $ctx['guest_name'] ) . '</p>'
		     . '<p>' . $meta . '</p>'
		     . '<p><a href="' . $cancel . '">Cancel or reschedule</a></p>';
	}

	private static function html_reminder( $ctx ) {
		return '<p>Reminder: your ' . esc_html( self::type_label( $ctx['meeting_type'] ) )
		     . ' starts in ' . (int) self::REMINDER_LEAD_MIN . ' minutes.</p>'
		     . '<p><strong>When:</strong> ' . esc_html( self::format_dual_tz( $ctx['starts_at_utc'], $ctx['host_tz'] ) ) . '</p>'
		     . '<p>' . self::meta_summary( $ctx ) . '</p>';
	}

	private static function html_cancelled( $ctx ) {
		$by = ( isset( $ctx['actor'] ) && $ctx['actor'] === 'host' ) ? 'the host' : 'the guest';
		return '<p>This meeting was cancelled by ' . esc_html( $by ) . '.</p>'
		     . '<p><strong>Was scheduled for:</strong> ' . esc_html( self::format_dual_tz( $ctx['starts_at_utc'], $ctx['host_tz'] ) ) . '</p>';
	}

	private static function html_rescheduled( $ctx ) {
		$body = '<p>This meeting has been rescheduled.</p>'
		      . '<p><strong>New time:</strong> ' . esc_html( self::format_dual_tz( $ctx['starts_at_utc'], $ctx['host_tz'] ) ) . '</p>';
		if ( isset( $ctx['old_starts_utc'] ) && (string) $ctx['old_starts_utc'] !== '' ) {
			$body .= '<p><strong>Was:</strong> ' . esc_html( self::format_dual_tz( (string) $ctx['old_starts_utc'], $ctx['host_tz'] ) ) . '</p>';
		}
		$body .= '<p>' . self::meta_summary( $ctx ) . '</p>';
		return $body;
	}

	private static function text_confirmed( $ctx )   { return wp_strip_all_tags( self::html_confirmed( $ctx ) ); }
	private static function text_reminder( $ctx )    { return wp_strip_all_tags( self::html_reminder( $ctx ) ); }
	private static function text_cancelled( $ctx )   { return wp_strip_all_tags( self::html_cancelled( $ctx ) ); }
	private static function text_rescheduled( $ctx ) { return wp_strip_all_tags( self::html_rescheduled( $ctx ) ); }

	// ─────────────────────────────────────────────────────────────────────
	// email-manager brand wrap — defensive option read. If the active
	// template option contains a {{body}} placeholder, wrap our inner
	// body in that. Otherwise fall back to inline HTML so this works on
	// containers that don't have email-manager installed.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Wrap the inner HTML body in the active email-manager template
	 * (placeholder {{body}}). Defensive fallback to inline HTML so the
	 * email still goes out even if email-manager is absent or its
	 * option is empty / missing the placeholder.
	 *
	 * @param string $inner_html
	 * @return string Full HTML document ready for wp_mail
	 */
	private static function wrap_brand( $inner_html ) {
		$em_templates = get_option( 'em_email_templates', array() );
		$active_id    = get_option( 'em_active_template', '' );
		if ( is_array( $em_templates ) && $active_id !== '' && isset( $em_templates[ $active_id ]['html'] ) ) {
			$tpl = (string) $em_templates[ $active_id ]['html'];
			if ( strpos( $tpl, '{{body}}' ) !== false ) {
				return str_replace( '{{body}}', $inner_html, $tpl );
			}
		}
		// Inline fallback wrap — kept intentionally lean (no images, no
		// JS); inlined system-font stack so spam filters don't penalize
		// remote CSS pulls.
		return '<!doctype html><html><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#222">'
		     . '<div>' . $inner_html . '</div>'
		     . '<hr style="margin:24px 0;border:0;border-top:1px solid #eee">'
		     . '<p style="font-size:12px;color:#888">Sent by gend-society Member Calendar</p>'
		     . '</body></html>';
	}

	// ─────────────────────────────────────────────────────────────────────
	// Sender — wp_mail to BOTH host and bookee with per-recipient try/catch
	// so one bad address never blocks the other party's email.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Send the rendered email to BOTH host and bookee. Per-recipient
	 * try/catch wraps each wp_mail call so a bad host address doesn't
	 * silently swallow the bookee send (and vice versa). Headers identify
	 * the host as the From + Reply-To so replies land in the host's inbox.
	 *
	 * @param array $ctx      load_context() output
	 * @param array $rendered render_*() output { subject, body_html, body_text }
	 */
	private static function send_to_both( $ctx, $rendered ) {
		$host_email  = (string) $ctx['host_email'];
		$guest_email = (string) $ctx['guest_email'];
		$headers     = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::format_from( $ctx ),
			'Reply-To: ' . self::format_reply_to( $ctx ),
		);
		$sent_host  = false;
		$sent_guest = false;
		try {
			if ( $host_email !== '' && is_email( $host_email ) ) {
				$sent_host = (bool) wp_mail( $host_email, $rendered['subject'], $rendered['body_html'], $headers );
			}
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_SEND_HOST_FAIL mtg=' . (int) $ctx['meeting_id'] . ' err=' . $e->getMessage() );
		}
		try {
			if ( $guest_email !== '' && is_email( $guest_email ) ) {
				$sent_guest = (bool) wp_mail( $guest_email, $rendered['subject'], $rendered['body_html'], $headers );
			}
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_SEND_GUEST_FAIL mtg=' . (int) $ctx['meeting_id'] . ' err=' . $e->getMessage() );
		}
		if ( ! $sent_host && ! $sent_guest ) {
			error_log( 'GS_NOTIF_BOTH_FAIL mtg=' . (int) $ctx['meeting_id'] );
		}
	}

	/**
	 * Build the From header. Identity = host's gend-society display name +
	 * site admin email (NOT host's personal email — that's the Reply-To)
	 * so SPF/DKIM alignment with the site domain is preserved.
	 *
	 * If the host has no display name / email, falls back to site defaults.
	 *
	 * @param array $ctx
	 * @return string Like 'Alice Host <admin@example.com>'
	 */
	private static function format_from( $ctx ) {
		$name = (string) $ctx['host_name'];
		// Always send From with the site's admin_email so SPF/DKIM align
		// with the sending domain; Reply-To carries the host's actual inbox.
		$email = (string) get_option( 'admin_email' );
		if ( $email === '' || ! is_email( $email ) ) {
			$email = (string) $ctx['host_email']; // last-ditch fallback
		}
		if ( $name === '' ) {
			$name = (string) get_option( 'blogname' );
		}
		return sprintf( '%s <%s>', addslashes( $name ), $email );
	}

	/**
	 * Build the Reply-To header. Always the host's personal inbox so
	 * either party hitting Reply lands in the host's mailbox.
	 *
	 * @param array $ctx
	 * @return string Like 'Alice Host <alice@gend.me>'
	 */
	private static function format_reply_to( $ctx ) {
		$name  = (string) $ctx['host_name'];
		$email = (string) $ctx['host_email'];
		if ( $email === '' || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
			$name  = $name !== '' ? $name : (string) get_option( 'blogname' );
		}
		return sprintf( '%s <%s>', addslashes( $name ), $email );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Reminder cron management — wp_schedule_single_event /
	// wp_unschedule_event / wp_next_scheduled.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Schedule a single-event reminder cron at starts_at_utc - REMINDER_LEAD_MIN.
	 * Cancels any existing scheduled reminder for the same meeting_id first
	 * so reschedule never leaves a stale event.
	 *
	 * Silently no-ops if the computed timestamp is in the past (meetings
	 * booked with less than 15 min lead simply don't get a reminder).
	 *
	 * @param array $ctx load_context() output (must have starts_at_utc + meeting_id)
	 */
	public static function schedule_reminder( $ctx ) {
		$meeting_id = (int) $ctx['meeting_id'];
		try {
			$dt = new DateTimeImmutable( (string) $ctx['starts_at_utc'], new DateTimeZone( 'UTC' ) );
			$ts = $dt->getTimestamp() - ( (int) self::REMINDER_LEAD_MIN * 60 );
			if ( $ts > time() ) {
				// Remove any existing reminder for this meeting first so a
				// reschedule cleanly replaces (not duplicates) the cron event.
				self::cancel_reminder( $meeting_id );
				wp_schedule_single_event( $ts, self::REMINDER_HOOK, array( $meeting_id ) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'GS_NOTIF_REMINDER_SCHED_FAIL mtg=' . $meeting_id . ' err=' . $e->getMessage() );
		}
	}

	/**
	 * Unschedule any pending reminder event for the given meeting_id.
	 * No-op if none is scheduled.
	 *
	 * @param int $meeting_id
	 */
	public static function cancel_reminder( $meeting_id ) {
		$meeting_id = (int) $meeting_id;
		$existing   = wp_next_scheduled( self::REMINDER_HOOK, array( $meeting_id ) );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::REMINDER_HOOK, array( $meeting_id ) );
		}
	}
}
