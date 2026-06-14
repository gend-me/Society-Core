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

// Gend_GS_Calendar_Source_Projects defined below in Task 2 (same file per
// RESEARCH §Recommended File Structure — small adapters co-locate cleanly).
