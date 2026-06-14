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
		// 'bm' wires in Plan 27-02.
		$adapters = array(
			'pm'  => new Gend_GS_Calendar_Source_Projects(),
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
