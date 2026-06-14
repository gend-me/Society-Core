<?php
/**
 * gend-society — Member Availability REST (gs/v1/calendar/availability).
 *
 * Per-member availability storage (AVAIL-01 working hours, AVAIL-02 blocked ranges,
 * AVAIL-03 timezone) backed by wp_gs_member_availability (Plan 28-01 schema).
 *
 * Routes:
 *   GET  /wp-json/gs/v1/calendar/availability      → current user's row (or empty defaults)
 *   PUT  /wp-json/gs/v1/calendar/availability      → UPSERT {timezone, working_hours, blocked_ranges}
 *
 * Mirrors the GS_User_Profile_Router GET/PUT shape (inc/user-profile-router.php)
 * and the Gend_GS_Calendar_Events_REST gs/v1 namespace + is_user_logged_in() gate.
 *
 * Pitfall mitigations:
 *   - Pitfall 5: timezone validated via `new DateTimeZone($tz)` (IANA only — offsets rejected).
 *   - Pitfall 7 / AGG-06: identity ALWAYS via get_current_user_id() — `?user=` IGNORED.
 *   - Pitfall 6 (TOCTOU): UPSERT via INSERT … ON DUPLICATE KEY UPDATE on UNIQUE idx_user_id.
 *   - WP_REST_Request defaults coalesce defensively in handle_put() entry (Phase 19 lesson).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Gend_GS_Availability_REST {

    const NS = 'gs/v1';
    const CACHE_GROUP = 'gs_avail';
    const CACHE_TTL   = 60; // 60s wp_cache on member tz read path (Phase 27 integration)

    public static function register_routes() : void {
        register_rest_route( self::NS, '/calendar/availability', array(
            array(
                'methods'             => WP_REST_Server::READABLE,           // GET
                'callback'            => array( __CLASS__, 'handle_get' ),
                'permission_callback' => array( __CLASS__, 'permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,           // PUT (and PATCH/POST per WP convention)
                'callback'            => array( __CLASS__, 'handle_put' ),
                'permission_callback' => array( __CLASS__, 'permission_check' ),
                'args'                => array(
                    'timezone'         => array( 'required' => false, 'type' => 'string', 'default' => 'UTC' ),
                    'working_hours'    => array( 'required' => false, 'type' => 'object' ),
                    'blocked_ranges'   => array( 'required' => false, 'type' => 'array' ),
                    // Plan 29-02: booking_settings_json — limits + named durations + per-type defaults.
                    'booking_settings' => array( 'required' => false, 'type' => 'object' ),
                ),
            ),
        ) );

        // Plan 28-03: expanded overlays for a date range (working hours + blocked ranges in UTC).
        register_rest_route( self::NS, '/calendar/overlays', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'handle_overlays' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
            'args'                => array(
                'from' => array( 'required' => true, 'type' => 'string' ),
                'to'   => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );
    }

    public static function permission_check() : bool {
        return is_user_logged_in();
    }

    /**
     * GET handler — returns the current user's availability row or empty defaults.
     */
    public static function handle_get( WP_REST_Request $req ) {
        // Pitfall 7: identity ALWAYS server-side; ?user= IGNORED.
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'gs_avail_no_user', 'login required', array( 'status' => 401 ) );
        }
        $row = self::read_row( $user_id );
        if ( ! $row ) {
            // Empty defaults — no row yet for this member.
            return rest_ensure_response( array(
                'user_id'        => $user_id,
                'timezone'       => '',
                'working_hours'  => (object) array(), // emit as JSON object, not [] (frontend differentiates "not set" from "Sunday empty")
                'blocked_ranges' => array(),
                'share_token'    => '',
                'has_row'        => false,
            ) );
        }
        // Plan 29-02: decode booking_settings_json if column present (graceful — older
        // rows pre-29-02 won't have the column, so guard via array_key_exists).
        $booking_settings = array();
        if ( array_key_exists( 'booking_settings_json', $row ) ) {
            $bs_raw = json_decode( (string) $row['booking_settings_json'], true );
            if ( is_array( $bs_raw ) ) { $booking_settings = $bs_raw; }
        }

        return rest_ensure_response( array(
            'user_id'          => (int) $row['user_id'],
            'timezone'         => (string) $row['timezone'],
            'working_hours'    => self::decode_object_or_default( $row['working_hours_json'] ),
            'blocked_ranges'   => self::decode_array_or_default( $row['blocked_ranges_json'] ),
            'share_token'      => (string) $row['share_token'],
            'booking_settings' => $booking_settings,
            'has_row'          => true,
        ) );
    }

    /**
     * PUT handler — UPSERT the current user's availability row.
     */
    public static function handle_put( WP_REST_Request $req ) {
        // Pitfall 7: identity ALWAYS server-side; ?user= IGNORED.
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'gs_avail_no_user', 'login required', array( 'status' => 401 ) );
        }

        // WP_REST_Request defaults coalesce defensively (Phase 19 lesson — null vs missing vs default).
        $tz_raw            = $req->get_param( 'timezone' );
        $working_hours_raw = $req->get_param( 'working_hours' );
        $blocked_raw       = $req->get_param( 'blocked_ranges' );
        // Plan 29-02: booking_settings_json — accepts limits + named_durations + per-type defaults.
        $booking_settings_raw = $req->get_param( 'booking_settings' );

        // Coalesce nulls to safe defaults — never trust REST schema to do it.
        $tz             = is_string( $tz_raw ) && $tz_raw !== '' ? trim( $tz_raw ) : 'UTC';
        $working_hours  = is_array( $working_hours_raw ) || is_object( $working_hours_raw ) ? (array) $working_hours_raw : array();
        $blocked_ranges = is_array( $blocked_raw ) ? $blocked_raw : array();
        $booking_settings_payload = is_array( $booking_settings_raw ) || is_object( $booking_settings_raw )
            ? (array) $booking_settings_raw
            : array();

        // Pitfall 5: validate IANA tz via new DateTimeZone() — throws on invalid (including offsets like "+05:00").
        $tz_err = self::validate_timezone( $tz );
        if ( $tz_err ) { return $tz_err; }

        // Sanitize working_hours + blocked_ranges (defensive — never trust client JSON shape).
        $working_hours_clean   = self::sanitize_working_hours( $working_hours );
        $blocked_ranges_clean  = self::sanitize_blocked_ranges( $blocked_ranges );
        // Plan 29-02: sanitize booking_settings (5 fields + named_durations array, URL allowlist for default_video_url).
        $booking_settings_clean = self::sanitize_booking_settings( $booking_settings_payload );
        $booking_settings_json  = wp_json_encode( $booking_settings_clean );

        // Read existing row to preserve share_token on subsequent saves.
        $existing = self::read_row( $user_id );
        $now      = time();
        if ( $existing ) {
            $share_token = (string) $existing['share_token'];
            if ( $share_token === '' ) { $share_token = self::generate_share_token(); }
            $created_at  = (int) $existing['created_at_ts'];
        } else {
            $share_token = self::generate_share_token(); // first-save auto-generate
            $created_at  = $now;
        }

        // UPSERT via INSERT … ON DUPLICATE KEY UPDATE — atomic on UNIQUE idx_user_id (Plan 28-01).
        // Plan 29-02 adds booking_settings_json to INSERT cols + ON DUPLICATE KEY UPDATE clause.
        global $wpdb;
        $table = Gend_GS_Availability_Schema::table_availability();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table}
                 (user_id, timezone, working_hours_json, blocked_ranges_json, booking_settings_json, share_token, created_at_ts, updated_at_ts)
             VALUES (%d, %s, %s, %s, %s, %s, %d, %d)
             ON DUPLICATE KEY UPDATE
                 timezone              = VALUES(timezone),
                 working_hours_json    = VALUES(working_hours_json),
                 blocked_ranges_json   = VALUES(blocked_ranges_json),
                 booking_settings_json = VALUES(booking_settings_json),
                 updated_at_ts         = VALUES(updated_at_ts)",
            $user_id,
            $tz,
            wp_json_encode( $working_hours_clean ),
            wp_json_encode( $blocked_ranges_clean ),
            $booking_settings_json,
            $share_token,
            $created_at,
            $now
        ) );

        // Cache-bust the member tz (Phase 27 integration — Plan 28-02 §key_links).
        wp_cache_delete( self::tz_cache_key( $user_id ), self::CACHE_GROUP );

        // Canonical read after write — handle_get echoes booking_settings back to caller.
        return self::handle_get( $req );
    }

    /**
     * Plan 29-02 — sanitize booking_settings_json payload.
     *
     * Validated fields:
     *   - min_notice_minutes   int 0..10080  (7 days max)
     *   - buffer_minutes       int 0..240    (4 hours max)
     *   - max_bookings_per_day int 1..50
     *   - default_video_provider enum zoom|meet|teams|webex|gend
     *   - default_video_url    funneled through Gend_GS_Booking_Meetings_REST::sanitize_meeting_meta_with_allowlist
     *                          (Pitfall 16 — same allowlist as host-side meeting creates)
     *   - named_durations      array of {label string 1-40, minutes in [15,30,45,60,90,120]}, cap 12 items
     *
     * Unknown keys silently dropped. Invalid values silently dropped (silent-drop policy
     * keeps the PUT useful even when one field is bad — UI surfaces a "video URL invalid"
     * hint elsewhere when default_video_url comes back missing).
     */
    private static function sanitize_booking_settings( array $payload ) : array {
        $clean = array();

        if ( isset( $payload['min_notice_minutes'] ) ) {
            $n = (int) $payload['min_notice_minutes'];
            $clean['min_notice_minutes'] = max( 0, min( 10080, $n ) );
        }

        if ( isset( $payload['buffer_minutes'] ) ) {
            $n = (int) $payload['buffer_minutes'];
            $clean['buffer_minutes'] = max( 0, min( 240, $n ) );
        }

        if ( isset( $payload['max_bookings_per_day'] ) ) {
            $n = (int) $payload['max_bookings_per_day'];
            $clean['max_bookings_per_day'] = max( 1, min( 50, $n ) );
        }

        if ( isset( $payload['default_video_provider'] ) ) {
            $p = sanitize_text_field( (string) $payload['default_video_provider'] );
            if ( in_array( $p, array( 'zoom', 'meet', 'teams', 'webex', 'gend' ), true ) ) {
                $clean['default_video_provider'] = $p;
            }
        }

        if ( isset( $payload['default_video_url'] ) && $payload['default_video_url'] !== '' ) {
            // Funnel through the SAME URL allowlist as host-side meeting creates (Pitfall 16).
            // Defensive lazy require — class_exists guard so PUT still works during partial deploys.
            if ( class_exists( 'Gend_GS_Booking_Meetings_REST' ) ) {
                $url = esc_url_raw( (string) $payload['default_video_url'] );
                $provider_for_check = isset( $clean['default_video_provider'] ) ? $clean['default_video_provider'] : 'zoom';
                if ( $provider_for_check === 'gend' ) { $provider_for_check = 'zoom'; } // URL allowlist only for external
                $meta = Gend_GS_Booking_Meetings_REST::sanitize_meeting_meta_with_allowlist(
                    'video',
                    array( 'provider' => $provider_for_check, 'room_url' => $url ),
                    (int) get_current_user_id()
                );
                if ( ! is_wp_error( $meta ) && isset( $meta['room_url'] ) ) {
                    $clean['default_video_url'] = $meta['room_url'];
                }
                // Silently drop disallowed URLs — UI surfaces an "invalid URL" hint via missing key.
            }
        }

        if ( isset( $payload['named_durations'] ) && is_array( $payload['named_durations'] ) ) {
            $dur_clean       = array();
            $allowed_minutes = array( 15, 30, 45, 60, 90, 120 );
            foreach ( $payload['named_durations'] as $d ) {
                if ( ! is_array( $d ) ) { continue; }
                $label = isset( $d['label'] )   ? sanitize_text_field( (string) $d['label'] ) : '';
                $mins  = isset( $d['minutes'] ) ? (int) $d['minutes'] : 0;
                if ( $label === '' || strlen( $label ) > 40 ) { continue; }
                if ( ! in_array( $mins, $allowed_minutes, true ) ) { continue; }
                $dur_clean[] = array( 'label' => $label, 'minutes' => $mins );
                if ( count( $dur_clean ) >= 12 ) { break; } // cap UI noise
            }
            $clean['named_durations'] = $dur_clean;
        }

        return $clean;
    }

    /** Pitfall 5 — IANA only; offsets/abbreviations rejected. */
    public static function validate_timezone( string $tz ) {
        // PHP's new DateTimeZone() ACCEPTS numeric offsets ("+05:00", "-0800") and
        // some abbreviations ("EST") — but storing an offset breaks DST-safe overlay
        // expansion (Pitfall 5/12). Require a true IANA identifier: must be in the
        // canonical timezone_identifiers_list(). 'UTC' is allowed explicitly (it is
        // in the list, but guard belt-and-braces).
        $tz = trim( $tz );
        if ( $tz === '' ) {
            return new WP_Error( 'gs_avail_bad_tz', 'empty timezone', array( 'status' => 400, 'rejected' => $tz ) );
        }
        $iana = timezone_identifiers_list();
        if ( $tz !== 'UTC' && ! in_array( $tz, $iana, true ) ) {
            return new WP_Error(
                'gs_avail_bad_tz',
                sprintf( 'invalid IANA timezone: %s', $tz ),
                array( 'status' => 400, 'rejected' => $tz )
            );
        }
        // Final constructor check (defensive — should always pass for list members).
        try {
            new DateTimeZone( $tz );
            return null; // valid
        } catch ( \Throwable $e ) {
            return new WP_Error(
                'gs_avail_bad_tz',
                sprintf( 'invalid IANA timezone: %s', $tz ),
                array( 'status' => 400, 'rejected' => $tz )
            );
        }
    }

    /**
     * Working hours shape — { mon: [{start:'HH:MM',end:'HH:MM'}, ...], tue: [...], ..., sun: [...] }
     * Defensive: drop unknown weekday keys; clamp ranges; coerce strings.
     */
    public static function sanitize_working_hours( $raw ) : array {
        $allowed_days = array( 'mon','tue','wed','thu','fri','sat','sun' );
        $out = array();
        foreach ( $allowed_days as $day ) {
            $out[ $day ] = array();
            if ( empty( $raw[ $day ] ) || ! is_array( $raw[ $day ] ) ) { continue; }
            foreach ( $raw[ $day ] as $range ) {
                if ( ! is_array( $range ) ) { continue; }
                $s = isset( $range['start'] ) ? (string) $range['start'] : '';
                $e = isset( $range['end'] )   ? (string) $range['end']   : '';
                if ( ! preg_match( '/^\d{2}:\d{2}$/', $s ) || ! preg_match( '/^\d{2}:\d{2}$/', $e ) ) { continue; }
                if ( strcmp( $s, $e ) >= 0 ) { continue; } // start must be < end
                $out[ $day ][] = array( 'start' => $s, 'end' => $e );
            }
        }
        return $out;
    }

    /**
     * Blocked ranges shape — [ {start_utc:'YYYY-MM-DDTHH:MM:SSZ', end_utc:'...', reason:'free-form'}, ... ]
     * Validates UTC ISO format; drops bad rows; caps reason at 140 chars.
     */
    public static function sanitize_blocked_ranges( array $raw ) : array {
        $out = array();
        foreach ( $raw as $r ) {
            if ( ! is_array( $r ) ) { continue; }
            $s = isset( $r['start_utc'] ) ? (string) $r['start_utc'] : '';
            $e = isset( $r['end_utc'] )   ? (string) $r['end_utc']   : '';
            $reason = isset( $r['reason'] ) ? (string) $r['reason'] : '';
            // Strict ISO-8601 UTC: must end in Z and be parseable.
            if ( substr( $s, -1 ) !== 'Z' || substr( $e, -1 ) !== 'Z' ) { continue; }
            $ts_s = strtotime( $s ); $ts_e = strtotime( $e );
            if ( ! $ts_s || ! $ts_e || $ts_s >= $ts_e ) { continue; }
            $out[] = array(
                'start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $ts_s ),
                'end_utc'   => gmdate( 'Y-m-d\TH:i:s\Z', $ts_e ),
                'reason'    => mb_substr( sanitize_text_field( $reason ), 0, 140 ),
            );
        }
        return $out;
    }

    public static function generate_share_token() : string {
        // wp_generate_password(43, false, false) = 43-char alphanumeric (URL-safe; Phase 29 booking URL secret).
        return wp_generate_password( 43, false, false );
    }

    private static function read_row( int $user_id ) : ?array {
        global $wpdb;
        $table = Gend_GS_Availability_Schema::table_availability();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private static function decode_object_or_default( $json ) {
        if ( ! is_string( $json ) || $json === '' ) { return (object) array(); }
        $d = json_decode( $json, true );
        return is_array( $d ) ? $d : (object) array();
    }

    private static function decode_array_or_default( $json ) : array {
        if ( ! is_string( $json ) || $json === '' ) { return array(); }
        $d = json_decode( $json, true );
        return is_array( $d ) ? $d : array();
    }

    public static function tz_cache_key( int $user_id ) : string {
        return 'tz_' . $user_id;
    }

    /**
     * GET /calendar/overlays?from=&to=
     * Returns expanded overlays for the date range in UTC ISO format.
     *
     * Response:
     * {
     *   "member_tz": "America/Toronto",
     *   "working_hours": [
     *     {"start_utc": "2026-06-15T13:00:00Z", "end_utc": "2026-06-15T21:00:00Z", "kind": "working"}
     *   ],
     *   "blocked_ranges": [
     *     {"start_utc": "2026-06-14T13:00:00Z", "end_utc": "2026-06-14T16:00:00Z", "kind": "blocked", "reason": "doctor"}
     *   ]
     * }
     */
    public static function handle_overlays( WP_REST_Request $req ) {
        // Pitfall 7: identity ALWAYS server-side; ?user= IGNORED.
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'gs_avail_no_user', 'login required', array( 'status' => 401 ) );
        }

        $from_iso = trim( (string) $req->get_param( 'from' ) );
        $to_iso   = trim( (string) $req->get_param( 'to' ) );
        if ( $from_iso === '' || $to_iso === '' ) {
            return new WP_Error( 'gs_avail_bad_range', 'from and to required (ISO 8601)', array( 'status' => 400 ) );
        }

        $ts_from = strtotime( $from_iso );
        $ts_to   = strtotime( $to_iso );
        if ( ! $ts_from || ! $ts_to || $ts_from >= $ts_to ) {
            return new WP_Error( 'gs_avail_bad_range', 'from must be < to', array( 'status' => 400 ) );
        }

        // Cap range to 90 days to keep payloads bounded (Pitfall 22 — unbounded expansion).
        if ( ( $ts_to - $ts_from ) > ( 90 * 86400 ) ) {
            return new WP_Error( 'gs_avail_range_too_large', 'range > 90 days', array( 'status' => 400 ) );
        }

        $member_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
            ? Gend_GS_Calendar_Events_REST::get_member_timezone( $user_id )
            : ( wp_timezone_string() ?: 'UTC' );

        $result = self::expand_overlays( $user_id, $from_iso, $to_iso, $member_tz );
        $result['member_tz'] = $member_tz;
        return rest_ensure_response( $result );
    }

    /**
     * DST-safe overlay expansion (Pitfall 5 + Pitfall 12).
     *
     * Working hours expansion:
     *   - Iterate dates from $from to $to (half-open [from, to)) in the MEMBER tz
     *   - For each date, look up weekday key (mon..sun) in working_hours_json
     *   - For each {start: 'HH:MM', end: 'HH:MM'} window on that weekday:
     *     - Build the LOCAL datetime via DateTime::createFromFormat('Y-m-d H:i', ..., new DateTimeZone($member_tz))
     *       — this is the DST-correct path: 9 AM stays 9 AM across DST flips
     *     - Convert to UTC via setTimezone(new DateTimeZone('UTC')) + format('Y-m-d\TH:i:s\Z')
     *
     * Blocked ranges expansion:
     *   - blocked_ranges_json already stores UTC ISO; just filter by intersection with [from, to)
     *
     * @return array{working_hours: array, blocked_ranges: array}
     */
    public static function expand_overlays( int $user_id, string $from_iso, string $to_iso, string $member_tz ) : array {
        global $wpdb;
        $table = Gend_GS_Availability_Schema::table_availability();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ), ARRAY_A );

        $out = array( 'working_hours' => array(), 'blocked_ranges' => array() );
        if ( ! $row ) { return $out; }

        // Validate tz once (defensive — Plan 28-02 validates on PUT, but DB rows could be hand-edited).
        try { $tz = new DateTimeZone( $member_tz ); }
        catch ( \Throwable $e ) { $tz = new DateTimeZone( 'UTC' ); }
        $utc = new DateTimeZone( 'UTC' );

        // ─── Working hours: expand weekly recurring windows in MEMBER tz ─────
        $wh = json_decode( (string) $row['working_hours_json'], true );
        if ( is_array( $wh ) ) {
            // Iterate days in member's tz, NOT UTC — half-open [from, to)
            $cursor = new DateTime( $from_iso, $utc );
            $cursor->setTimezone( $tz );
            $end_cursor = new DateTime( $to_iso, $utc );
            $end_cursor->setTimezone( $tz );

            // Snap cursor back to 00:00 of its local date so we don't miss the start day.
            $cursor->setTime( 0, 0, 0 );

            $weekday_map = array( 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun' );
            $safety_iters = 0;
            while ( $cursor < $end_cursor && $safety_iters < 365 ) { // 1 year hard safety cap on top of 90d input cap
                $safety_iters++;
                $weekday_num = (int) $cursor->format( 'N' ); // ISO-8601: 1=Mon..7=Sun
                $weekday_key = $weekday_map[ $weekday_num ] ?? null;
                $date_str    = $cursor->format( 'Y-m-d' );
                if ( $weekday_key && ! empty( $wh[ $weekday_key ] ) && is_array( $wh[ $weekday_key ] ) ) {
                    foreach ( $wh[ $weekday_key ] as $range ) {
                        $s = isset( $range['start'] ) ? (string) $range['start'] : '';
                        $e = isset( $range['end'] )   ? (string) $range['end']   : '';
                        if ( ! preg_match( '/^\d{2}:\d{2}$/', $s ) || ! preg_match( '/^\d{2}:\d{2}$/', $e ) ) { continue; }

                        // DST-safe: build the LOCAL datetime in member tz, then convert to UTC.
                        // DateTime::createFromFormat with explicit tz argument honors DST for that date.
                        $start_local = DateTime::createFromFormat( 'Y-m-d H:i', $date_str . ' ' . $s, $tz );
                        $end_local   = DateTime::createFromFormat( 'Y-m-d H:i', $date_str . ' ' . $e, $tz );
                        if ( ! $start_local || ! $end_local ) { continue; }
                        $start_local->setTimezone( $utc );
                        $end_local->setTimezone( $utc );
                        $out['working_hours'][] = array(
                            'start_utc' => $start_local->format( 'Y-m-d\TH:i:s\Z' ),
                            'end_utc'   => $end_local->format( 'Y-m-d\TH:i:s\Z' ),
                            'kind'      => 'working',
                        );
                    }
                }
                $cursor->modify( '+1 day' );
            }
        }

        // ─── Blocked ranges: filter by intersection (no expansion — stored as UTC) ─
        $br = json_decode( (string) $row['blocked_ranges_json'], true );
        if ( is_array( $br ) ) {
            $ts_from = strtotime( $from_iso );
            $ts_to   = strtotime( $to_iso );
            foreach ( $br as $r ) {
                if ( ! is_array( $r ) ) { continue; }
                $s = (string) ( $r['start_utc'] ?? '' );
                $e = (string) ( $r['end_utc'] ?? '' );
                $ts_s = strtotime( $s ); $ts_e = strtotime( $e );
                if ( ! $ts_s || ! $ts_e || $ts_s >= $ts_e ) { continue; }
                // Intersect half-open [from, to)
                if ( $ts_e <= $ts_from || $ts_s >= $ts_to ) { continue; }
                $out['blocked_ranges'][] = array(
                    'start_utc' => $s,
                    'end_utc'   => $e,
                    'kind'      => 'blocked',
                    'reason'    => (string) ( $r['reason'] ?? '' ),
                );
            }
        }

        return $out;
    }
}
