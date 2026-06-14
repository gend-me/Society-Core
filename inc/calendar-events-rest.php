<?php
/**
 * gend-society — Calendar Events Aggregation REST (gs/v1/calendar/events).
 *
 * Read-only aggregator across:
 *   - Projects   (wp_pm_*)               → Gend_GS_Calendar_Source_Projects   [this plan]
 *   - BlogManager (wp_bm_*)              → Gend_GS_Calendar_Source_BlogManager [Plan 27-02 lands]
 *   - Meetings   (wp_gs_member_meetings) → Gend_GS_Calendar_Source_Meetings    [forward stub; Phase 28/29 implements]
 *
 * Replaces the Phase 26 frontend's buildMockEvents() data source for `pm`/`mtg`;
 * Plan 27-02 adds `bm` and the cross-cutting UATs + hub deploy runbook.
 *
 * Contract is LOCKED in Phase 26 Plan 02 SUMMARY — DO NOT rename fields.
 *
 * Pitfall mitigations baked in:
 *   - Pitfall 4 (graceful degradation): per-adapter is_available() guard + try/catch
 *     wrapper in route_events(); a missing source key is silently omitted from
 *     sources_available; one source throwing never blanks siblings.
 *   - Pitfall 5 / Pitfall A (date-only naive vs true UTC): pm_* events are emitted
 *     all_day:true with synthetic YYYY-MM-DDT00:00:00Z / T23:59:59Z bounds.
 *   - Pitfall 7 / AGG-06 (cross-member leak): user_id ALWAYS from
 *     get_current_user_id() server-side; ?user= request param IGNORED.
 *   - Pitfall 8 (N+1): per-source query budget ≤ 3; project lookups use IN(...)
 *     batch, never per-row queries.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST handler — single readable endpoint that fans out across adapters.
 */
class Gend_GS_Calendar_Events_REST {

	const NS = 'gs/v1';

	public static function register_routes() : void {
		register_rest_route( self::NS, '/calendar/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'route_events' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'args'                => array(
				'from' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'to'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/**
	 * Auth gate — identity is resolved authoritatively in route_events()
	 * via get_current_user_id() (Pitfall 7 / AGG-06).
	 */
	public static function can_read() : bool {
		return is_user_logged_in();
	}

	public static function route_events( WP_REST_Request $req ) {
		// Pitfall 7 / AGG-06: user_id ALWAYS server-side; ?user= IGNORED.
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'gs_cal_no_user', 'login required', array( 'status' => 401 ) );
		}

		$from = self::parse_iso_to_utc( (string) $req->get_param( 'from' ) );
		$to   = self::parse_iso_to_utc( (string) $req->get_param( 'to' ) );
		if ( ! $from || ! $to || $from >= $to ) {
			return new WP_Error(
				'gs_cal_bad_range',
				'from/to required, from < to (ISO 8601)',
				array( 'status' => 400 )
			);
		}

		// Adapter classes are co-located in this file (per RESEARCH §Recommended File Structure).
		// Order is purely for readability — events are globally sorted by start ISO via usort below.
		$adapters = array(
			'pm'  => new Gend_GS_Calendar_Source_Projects(),
			'bm'  => new Gend_GS_Calendar_Source_BlogManager(),   // ← Plan 27-02 Task 1
			'mtg' => new Gend_GS_Calendar_Source_Meetings(),
		);

		$events            = array();
		$sources_available = array();
		foreach ( $adapters as $key => $adapter ) {
			try {
				// Pitfall 4: silently omit missing source — never fatal.
				if ( ! $adapter->is_available() ) { continue; }
				$rows = $adapter->read_events( $user_id, $from, $to );
				if ( is_array( $rows ) && ! empty( $rows ) ) {
					$events = array_merge( $events, $rows );
				}
				$sources_available[] = $key;
			} catch ( \Throwable $e ) {
				// Pitfall 4: one source failing must NEVER blank siblings.
				error_log( '[gs_cal_events] adapter ' . $key . ' threw: ' . $e->getMessage() );
				continue;
			}
		}

		usort( $events, function ( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );

		return rest_ensure_response( array(
			'sources_available' => $sources_available,
			'events'            => $events,
			'member_tz'         => self::get_member_timezone( $user_id ), // Plan 28-02 — Phase 27 integration
		) );
	}

	/**
	 * Accept ISO-8601 (with Z or ±HH:MM) → UTC `Y-m-d H:i:s` for SQL bind. Null on bad input.
	 *
	 * Pattern matches class-bm-rest-composer.php:434 — `strtotime` handles the full ISO
	 * grammar including fractional seconds; `gmdate` is unambiguously UTC (avoids the
	 * `current_time('mysql')` + `gmt_offset` trap that burned the OAuth migration —
	 * memory: project_oauth_timezone_bug).
	 */
	private static function parse_iso_to_utc( string $iso ) : ?string {
		$iso = trim( $iso );
		if ( $iso === '' ) { return null; }
		$ts = strtotime( $iso );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	/**
	 * Resolve member's IANA timezone — Plan 28-02 integration.
	 *
	 * Reads wp_gs_member_availability.timezone first (60s wp_cache via Gend_GS_Availability_REST
	 * cache group), falls back to wp_timezone_string() if the member has no availability row.
	 *
	 * Site tz fallback preserves existing behavior for members who never opened the calendar
	 * settings UI — Pitfall 5 mitigation (never null, never invalid IANA).
	 *
	 * @param int $user_id
	 * @return string IANA timezone name (e.g. 'America/Toronto', 'UTC')
	 */
	public static function get_member_timezone( int $user_id ) : string {
		// Defensive: bad user_id → site fallback.
		if ( $user_id <= 0 ) { return wp_timezone_string() ?: 'UTC'; }

		// Cache check first — same group + key as Gend_GS_Availability_REST writes/invalidates.
		if ( class_exists( 'Gend_GS_Availability_REST' ) ) {
			$cache_key = Gend_GS_Availability_REST::tz_cache_key( $user_id );
			$cached    = wp_cache_get( $cache_key, Gend_GS_Availability_REST::CACHE_GROUP );
			if ( is_string( $cached ) && $cached !== '' ) { return $cached; }
		}

		// Table-existence guard (Pitfall 4 / graceful degradation — Plan 28-01 may not have run yet on this blog).
		global $wpdb;
		$table = $wpdb->prefix . 'gs_member_availability';
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) { return wp_timezone_string() ?: 'UTC'; }

		$tz = $wpdb->get_var( $wpdb->prepare(
			"SELECT timezone FROM {$table} WHERE user_id = %d LIMIT 1",
			$user_id
		) );
		if ( ! is_string( $tz ) || $tz === '' ) {
			$tz = wp_timezone_string() ?: 'UTC';
		}
		// Validate stored value is parseable (defensive — DB row could have been hand-edited).
		try { new DateTimeZone( $tz ); }
		catch ( \Throwable $e ) { $tz = wp_timezone_string() ?: 'UTC'; }

		if ( class_exists( 'Gend_GS_Availability_REST' ) ) {
			wp_cache_set(
				Gend_GS_Availability_REST::tz_cache_key( $user_id ),
				$tz,
				Gend_GS_Availability_REST::CACHE_GROUP,
				Gend_GS_Availability_REST::CACHE_TTL
			);
		}
		return $tz;
	}
}

/**
 * Meetings adapter — forward stub.
 *
 * Phase 28/29 will create wp_gs_member_meetings and implement read_events();
 * until then is_available() returns false and 'mtg' is omitted from
 * sources_available. The frontend (Plan 26-02) renders the source legend
 * from sources_available, so the meetings swatch simply doesn't appear yet —
 * graceful degradation per Pitfall 4.
 */
class Gend_GS_Calendar_Source_Meetings {

	public function is_available() : bool {
		global $wpdb;
		$table = $wpdb->prefix . 'gs_member_meetings';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Defensive empty return — should never be invoked while is_available()
	 * returns false. Kept for the case where a Phase 28/29 migration creates
	 * the table mid-request before this class is updated.
	 */
	public function read_events( int $user_id, string $from_utc, string $to_utc ) : array {
		return array();
	}
}

/**
 * Projects adapter — reads member-assigned tasks, milestone due dates, and
 * project est-completion dates from wp_pm_* tables.
 *
 * Member-scoping (Pitfall 8 / AGG-06): JOIN through wp_pm_assignees.assigned_to,
 * NEVER wp_pm_tasks.user_id (the latter is task-author, not assignee). Pattern
 * copied verbatim from class-pm-tasks.php:73-86.
 *
 * Timezone discipline (Pitfall A / Pitfall 5): pm_* sources are date-only naive
 * (`<input type="date">` → sanitize_text_field → MySQL timestamp storing
 * 'YYYY-MM-DD 00:00:00' with NO tz semantics). Every event emitted via
 * to_event_all_day() with synthetic UTC bounds — NEVER tz-converted.
 *
 * Query budget (Pitfall 8): ≤ 3 data queries per call:
 *   Q1 — tasks via assignees JOIN + projects JOIN (project_title free)
 *   Q2a — milestones via boards + meta IN(...) batch
 *   Q2b — project est_completion via projects IN(...) batch
 * Total with bm + mtg = ≤ 6 (mtg has 0 data queries; bm has 2 per RESEARCH).
 */
class Gend_GS_Calendar_Source_Projects {

	public function is_available() : bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pm_tasks';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function read_events( int $user_id, string $from_utc, string $to_utc ) : array {
		global $wpdb;

		$t = $wpdb->prefix . 'pm_tasks';
		$a = $wpdb->prefix . 'pm_assignees';
		$p = $wpdb->prefix . 'pm_projects';
		$b = $wpdb->prefix . 'pm_boards';
		$m = $wpdb->prefix . 'pm_meta';

		// pm_* is date-only; compare on the YYYY-MM-DD prefix.
		$from_date = substr( $from_utc, 0, 10 );
		$to_date   = substr( $to_utc,   0, 10 );

		// ---- Q1: tasks via assignee JOIN (Pitfall 8 / Pitfall B mitigation) ----
		// JOIN key MUST be wp_pm_assignees.assigned_to — pattern copied from
		// class-pm-tasks.php:73-86. project_title comes free via LEFT JOIN
		// (no separate per-row lookup → Pitfall E mitigation).
		$tasks = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.title, t.start_at, t.due_date, t.status, t.project_id,
			        p.title AS project_title
			 FROM {$t} AS t
			 INNER JOIN {$a} AS asg ON asg.task_id = t.id AND asg.assigned_to = %d
			 LEFT  JOIN {$p} AS p   ON p.id = t.project_id
			 WHERE ( DATE(t.due_date) BETWEEN %s AND %s )
			    OR ( DATE(t.start_at) BETWEEN %s AND %s )
			 ORDER BY t.due_date ASC
			 LIMIT 500",
			$user_id, $from_date, $to_date, $from_date, $to_date
		) );

		// ---- Q2a/Q2b: milestones + project est_completion (batched IN(...)) ----
		// Both queries gate on the member's actual project set (derived from Q1
		// task assignments), so a member who isn't assigned to any tasks in this
		// project also doesn't see its milestones — strict assignee-scoping.
		$project_ids         = array_unique( array_filter( wp_list_pluck( $tasks, 'project_id' ) ) );
		$milestones          = array();
		$project_completions = array();

		if ( ! empty( $project_ids ) ) {
			$ids_in = implode( ',', array_map( 'absint', $project_ids ) );

			// Q2a: milestones — boards type='milestone' + pm_meta due_date.
			$milestones = $wpdb->get_results( $wpdb->prepare(
				"SELECT b.id, b.title, b.project_id, p.title AS project_title,
				        mm.meta_value AS due_date
				 FROM {$b} AS b
				 INNER JOIN {$m} AS mm
				    ON mm.entity_id = b.id
				   AND mm.entity_type = 'milestone'
				   AND mm.meta_key = 'due_date'
				 LEFT JOIN {$p} AS p ON p.id = b.project_id
				 WHERE b.type = 'milestone'
				   AND b.project_id IN ({$ids_in})
				   AND DATE(mm.meta_value) BETWEEN %s AND %s
				 LIMIT 200",
				$from_date, $to_date
			) );

			// Q2b: project est_completion_date.
			$project_completions = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, est_completion_date
				 FROM {$p}
				 WHERE id IN ({$ids_in})
				   AND est_completion_date IS NOT NULL
				   AND DATE(est_completion_date) BETWEEN %s AND %s
				 LIMIT 200",
				$from_date, $to_date
			) );
		}

		$events = array();

		foreach ( $tasks as $row ) {
			// Skip null / zero-date sentinels (wedevs-PM can write these on partial updates).
			if ( empty( $row->due_date ) || $row->due_date === '0000-00-00 00:00:00' ) { continue; }
			$events[] = $this->to_event_all_day(
				'pm-task-' . (int) $row->id,
				'task',
				(string) $row->title . ' (' . (string) $row->project_title . ')',
				(string) $row->due_date,
				'',                          // url '' → popover hides "View source" (deferred per RESEARCH §Open Q1)
				(string) $row->status
			);
		}

		foreach ( $milestones as $row ) {
			$events[] = $this->to_event_all_day(
				'pm-milestone-' . (int) $row->id,
				'milestone',
				(string) $row->title . ' — milestone (' . (string) $row->project_title . ')',
				(string) $row->due_date,
				'',
				''
			);
		}

		foreach ( $project_completions as $row ) {
			$events[] = $this->to_event_all_day(
				'pm-project-' . (int) $row->id,
				'project',
				(string) $row->title . ' — est. completion',
				(string) $row->est_completion_date,
				'',
				''
			);
		}

		return $events;
	}

	/**
	 * Pitfall A / Pitfall 5 mitigation: pm_* sources store DATE-ONLY naive strings.
	 * Synthetic UTC bounds preserve cell-correctness across viewer tz — the frontend
	 * (Plan 26-02 `dayKeyInTz`) correctly buckets all-day events spanning a full UTC
	 * day to the same calendar cell regardless of viewer offset.
	 *
	 * Returns the LOCKED Phase 26 Plan 02 11-field event-object contract verbatim.
	 */
	private function to_event_all_day(
		string $id,
		string $type,
		string $title,
		string $date_str,
		string $url,
		string $status
	) : array {
		// Strip any '00:00:00' tail the MySQL timestamp column appends.
		$date = substr( $date_str, 0, 10 );
		return array(
			'id'      => $id,
			'source'  => 'pm',
			'type'    => $type,
			'title'   => $title,
			'start'   => $date . 'T00:00:00Z',
			'end'     => $date . 'T23:59:59Z',
			'all_day' => true,
			'color'   => '',          // frontend applies --gph-blue (pm) default
			'status'  => $status,
			'url'     => $url,
			'busy'    => true,        // Phase 28/29 subtracts from booking slots (Pitfall 14)
		);
	}
}

/**
 * BlogManager adapter — reads scheduled social posts + drip-queue items from
 * wp_bm_social_schedule_v2 and wp_bm_drip_queue.
 *
 * Member-scoping (Pitfall 7 / AGG-06): WHERE user_id = %d on BOTH tables.
 * NEVER trusts any ?user= request param — identity flows from
 * Gend_GS_Calendar_Events_REST::route_events() via get_current_user_id().
 *
 * Timezone discipline (Pitfall 5 / Pitfall 13 / AGG-07): bm_* DATETIME columns
 * store TRUE UTC (writers use gmdate(); readers use UTC_TIMESTAMP() — verified
 * in class-bm-publisher-hub-tick.php:56-75 + class-bm-rest-composer.php:240-262).
 * utc_to_z() therefore does a DIRECT string transform (replace space with T,
 * append Z) — NEVER strtotime() + gmdate(), which would interpret the source
 * string in server-tz and double-shift on any non-UTC host (the exact mistake
 * called out in RESEARCH §Common Pitfalls + memory: project_oauth_timezone_bug).
 *
 * Half-open SQL range (Pitfall D / Pitfall 12): `scheduled_at >= %s AND
 * scheduled_at < %s` (NOT BETWEEN) to prevent midnight-boundary double-count
 * when paginating across months. Pattern from class-bm-rest-calendar.php:161.
 *
 * Drip status filter: only `draft_pending_approval` + `approved` surface on
 * the calendar — `published` and `failed` are not future-planning rows.
 *
 * `busy: false` on EVERY bm_* event — REQUIREMENTS.md Out-of-Scope row:
 * "Scheduled campaign posts blocking booking slots — product decision:
 * automated posts are display-only, not attention-time." Phase 28/29
 * availability lookup ignores these.
 *
 * Query budget (Pitfall 8): ≤ 2 data queries per call (Q1 social, Q2 drip
 * — each on its own table). Combined with Projects (≤ 3) and Meetings (0) the
 * full ensemble stays at ≤ 5 data queries plus the 3 SHOW TABLES LIKE guards.
 *
 * Graceful degradation (Pitfall 4): is_available() guards on
 * wp_bm_social_schedule_v2; drip table has its OWN guard inside read_events()
 * because a site can have social-schedule without the drip extension.
 */
class Gend_GS_Calendar_Source_BlogManager {

	public function is_available() : bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_social_schedule_v2';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function read_events( int $user_id, string $from_utc, string $to_utc ) : array {
		global $wpdb;
		$events = array();

		// ---- Q1: scheduled social posts (wp_bm_social_schedule_v2) ----
		// Pitfall 7: WHERE user_id = %d (AGG-06 member scoping).
		// Pitfall D/12: half-open `>=` / `<` range (NOT BETWEEN).
		$sched_table = $wpdb->prefix . 'bm_social_schedule_v2';
		$social = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, platform, target_url, scheduled_at, status, copy_per_platform
			 FROM {$sched_table}
			 WHERE user_id = %d
			   AND scheduled_at >= %s
			   AND scheduled_at <  %s
			 ORDER BY scheduled_at ASC
			 LIMIT 500",
			$user_id, $from_utc, $to_utc
		) );

		foreach ( $social as $row ) {
			$events[] = array(
				'id'      => 'bm-social-' . (int) $row->id,
				'source'  => 'bm',
				'type'    => 'social',
				'title'   => 'Social: ' . $this->extract_first_caption(
					$row->copy_per_platform,
					(string) $row->platform
				),
				'start'   => $this->utc_to_z( (string) $row->scheduled_at ),
				'end'     => $this->utc_to_z( (string) $row->scheduled_at ),
				'all_day' => false,
				'color'   => '',                  // frontend applies --gph-magenta (bm) default
				'status'  => (string) $row->status,
				'url'     => '',                  // per-platform permalink lookup deferred (RESEARCH §Open Q1)
				'busy'    => false,               // display-only — REQUIREMENTS.md Out of Scope
			);
		}

		// ---- Q2: drip queue (wp_bm_drip_queue) ----
		// Per-table SHOW TABLES guard: sites may have social-schedule without the
		// drip extension. Pitfall 4: drip-absent must degrade silently, not fatal.
		$drip_table  = $wpdb->prefix . 'bm_drip_queue';
		$drip_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $drip_table ) );
		if ( $drip_exists ) {
			$drip = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, platform, scheduled_at, status, campaign_label, link_frozen
				 FROM {$drip_table}
				 WHERE user_id = %d
				   AND scheduled_at >= %s
				   AND scheduled_at <  %s
				   AND status IN ('draft_pending_approval','approved')
				 ORDER BY scheduled_at ASC
				 LIMIT 500",
				$user_id, $from_utc, $to_utc
			) );
			foreach ( $drip as $row ) {
				$events[] = array(
					'id'      => 'bm-drip-' . (int) $row->id,
					'source'  => 'bm',
					'type'    => 'drip',
					'title'   => 'Drip: ' . (string) $row->campaign_label . ' (' . (string) $row->platform . ')',
					'start'   => $this->utc_to_z( (string) $row->scheduled_at ),
					'end'     => $this->utc_to_z( (string) $row->scheduled_at ),
					'all_day' => false,
					'color'   => '',
					'status'  => (string) $row->status,
					'url'     => '',
					'busy'    => false,           // display-only — REQUIREMENTS.md Out of Scope
				);
			}
		}

		return $events;
	}

	/**
	 * bm_* DATETIME columns store TRUE UTC. Direct string transform from
	 * 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DDTHH:MM:SSZ'.
	 *
	 * CRITICAL: do NOT use strtotime() + gmdate() — that path interprets the
	 * source string in the server's PHP timezone, which on any non-UTC host
	 * (the hub had gmt_offset=-5 during the OAuth incident — memory:
	 * project_oauth_timezone_bug) would double-shift true-UTC storage by ±N
	 * hours. The direct string transform is correct AND fastest.
	 */
	private function utc_to_z( string $mysql_datetime ) : string {
		return str_replace( ' ', 'T', $mysql_datetime ) . 'Z';
	}

	/**
	 * Extract a human-readable caption from the per-platform JSON blob:
	 *   {"twitter":"hello","instagram":{"text":"hi"}} → "hello" (for twitter)
	 * Falls back to the first non-empty caption across platforms, then to the
	 * platform name. Truncates to 60 chars with ellipsis.
	 *
	 * Tolerant of malformed JSON, non-string blobs, nested array shapes.
	 */
	private function extract_first_caption( $copy_blob, string $platform ) : string {
		if ( ! is_string( $copy_blob ) || $copy_blob === '' ) { return $platform; }
		$decoded = json_decode( $copy_blob, true );
		if ( ! is_array( $decoded ) ) { return $platform; }
		$first = $decoded[ $platform ] ?? reset( $decoded );
		if ( is_array( $first ) ) {
			$first = $first['text'] ?? reset( $first );
		}
		$first = (string) $first;
		if ( $first === '' ) { return $platform; }
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $first ) > 60 ? mb_substr( $first, 0, 57 ) . '…' : $first;
		}
		return strlen( $first ) > 60 ? substr( $first, 0, 57 ) . '…' : $first;
	}
}
