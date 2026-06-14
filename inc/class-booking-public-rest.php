<?php
/**
 * gend-society — Public Booking REST (Phase 29 Plan 01).
 *
 * Public-facing booking REST handler — NO AUTH REQUIRED (bookee may be logged-out).
 *
 * Routes registered (all on rest_api_init):
 *   GET  /gs/v1/calendar/public/{share_token}/slots            → open-slot computation (member availability MINUS aggregated busy)
 *   POST /gs/v1/calendar/public/{share_token}/book             → atomic FOR UPDATE booking write (Pitfall 6)
 *   GET  /gs/v1/calendar/public/meeting/{cancellation_token}   → bookee self-service meeting fetch
 *   POST /gs/v1/calendar/public/meeting/{cancellation_token}/cancel       → bookee self-service cancel
 *   POST /gs/v1/calendar/public/meeting/{cancellation_token}/reschedule   → bookee self-service reschedule
 *
 * Route prefix invariant: /gs/v1/calendar/public
 *
 * Security invariants (load-bearing):
 *   - Pitfall 6 (atomic double-book prevention): EVERY write wraps overlap re-check + INSERT in
 *     START TRANSACTION + SELECT … FOR UPDATE + COMMIT/ROLLBACK on wp_gs_member_meetings.
 *   - Pitfall 7 (identity hardening): host identity is resolved EXCLUSIVELY from the route
 *     param $req['share_token'] (or $req['cancellation_token']). The handler NEVER reads
 *     ?user= and NEVER calls get_current_user_id() to authorize public actions.
 *     The optional guest_user_id capture is record-keeping only, NOT authorization.
 *   - Pitfall 2 (auto-deactivation safety): handle_book wraps the transaction in
 *     try/catch \Throwable → ROLLBACK + WP_Error so a DB hiccup never fatals
 *     gend-society into WP's auto-deactivation trap.
 *
 * Hardening (BOOK-09):
 *   - Per-IP rate limit 10/min + per-share_token rate limit 20/min via wp_transient counters
 *   - Honeypot field hp_email — non-empty triggers a fake-200 confirmation (no DB write)
 *   - 43-char wp_generate_password share_token + cancellation_token (256-bit entropy)
 *   - Server-side re-validation of slot inside the atomic transaction
 *
 * Booking-limits (default-on, host-overridable via booking_settings_json — Plan 29-02):
 *   - min_notice_minutes      — default 60 (slot.start - now_utc >= limit)
 *   - buffer_minutes          — default 0  (pad busy intervals on the leading edge)
 *   - max_bookings_per_day    — default 8  (count host's booked rows on slot date)
 *
 * Hooks fired (Plan 29-03 notifications + Plan 29-04 ICS subscribe here):
 *   - do_action('gs_booking_created',     $meeting_id, $host_user_id)
 *   - do_action('gs_booking_cancelled',   $meeting_id, $host_user_id, 'guest')
 *   - do_action('gs_booking_rescheduled', $meeting_id, $host_user_id, $old_starts_utc)
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Gend_GS_Booking_Public_REST {

    // Namespace constant: const NS = 'gs/v1';
    const NS                          = 'gs/v1';
    const RATE_LIMIT_IP_PER_MIN       = 10;
    const RATE_LIMIT_TOKEN_PER_MIN    = 20;
    const DEFAULT_MIN_NOTICE_MIN      = 60;
    const DEFAULT_BUFFER_MIN          = 0;
    const DEFAULT_MAX_BOOKINGS_PER_DAY = 8;
    const SLOT_GRANULARITY_MIN        = 15;
    const MAX_RANGE_DAYS              = 31;

    /**
     * Register all six public booking routes on rest_api_init.
     *
     * Pitfall 1: ALL register_rest_route calls land inside rest_api_init only.
     */
    public static function register_routes() : void {
        $token_re = '(?P<share_token>[A-Za-z0-9]{43})';
        $cancel_re = '(?P<cancellation_token>[A-Za-z0-9]{43})';

        // 1. GET open slots for a share_token.
        register_rest_route( self::NS, '/calendar/public/' . $token_re . '/slots', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'handle_slots' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'from'         => array( 'required' => true,  'type' => 'string' ),
                'to'           => array( 'required' => true,  'type' => 'string' ),
                'duration_min' => array( 'required' => false, 'type' => 'integer', 'default' => 30 ),
            ),
        ) );

        // 2. POST atomic booking write.
        register_rest_route( self::NS, '/calendar/public/' . $token_re . '/book', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'handle_book' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'slot_start_utc' => array( 'required' => true,  'type' => 'string' ),
                'duration_min'   => array( 'required' => true,  'type' => 'integer' ),
                'meeting_type'   => array( 'required' => true,  'type' => 'string' ),
                'meeting_meta'   => array( 'required' => false, 'type' => 'object' ),
                'guest_name'     => array( 'required' => true,  'type' => 'string' ),
                'guest_email'    => array( 'required' => true,  'type' => 'string' ),
                'guest_phone'    => array( 'required' => false, 'type' => 'string' ),
                'notes'          => array( 'required' => false, 'type' => 'string' ),
                'hp_email'       => array( 'required' => false, 'type' => 'string' ),
            ),
        ) );

        // 3. GET a meeting by cancellation_token (bookee self-service fetch).
        register_rest_route( self::NS, '/calendar/public/meeting/' . $cancel_re, array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'handle_meeting_get' ),
            'permission_callback' => '__return_true',
        ) );

        // 4. POST cancel meeting via cancellation_token.
        register_rest_route( self::NS, '/calendar/public/meeting/' . $cancel_re . '/cancel', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'handle_cancel' ),
            'permission_callback' => '__return_true',
        ) );

        // 5. POST reschedule meeting via cancellation_token (re-runs atomic FOR UPDATE flow).
        register_rest_route( self::NS, '/calendar/public/meeting/' . $cancel_re . '/reschedule', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'handle_reschedule' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'slot_start_utc' => array( 'required' => true,  'type' => 'string' ),
                'duration_min'   => array( 'required' => false, 'type' => 'integer' ),
            ),
        ) );
    }

    // ─── Public handlers ────────────────────────────────────────────────────

    /**
     * GET /calendar/public/{share_token}/slots — return open slots in member-tz envelope.
     */
    public static function handle_slots( WP_REST_Request $req ) {
        try {
            $share_token = (string) $req['share_token'];

            // Rate limit BEFORE any DB work to absorb enumeration spray.
            if ( $e = self::rate_limit_check( 'ip:' . self::client_ip(),  self::RATE_LIMIT_IP_PER_MIN ) )    { return $e; }
            if ( $e = self::rate_limit_check( 'tok:' . $share_token,      self::RATE_LIMIT_TOKEN_PER_MIN ) ) { return $e; }

            $host_user_id = self::resolve_host_by_token( $share_token );
            if ( $host_user_id === null ) {
                return new WP_Error( 'gs_book_token_unknown', 'Booking link invalid or revoked', array( 'status' => 404 ) );
            }

            $from_raw     = sanitize_text_field( (string) ( $req->get_param( 'from' ) ?? '' ) );
            $to_raw       = sanitize_text_field( (string) ( $req->get_param( 'to' )   ?? '' ) );
            $duration_min = (int) ( $req->get_param( 'duration_min' ) ?? 30 );

            if ( ! in_array( $duration_min, array( 15, 30, 45, 60, 90, 120 ), true ) ) {
                return new WP_Error( 'gs_book_bad_duration', 'Invalid duration', array( 'status' => 400 ) );
            }
            try {
                $dt_from = new DateTimeImmutable( $from_raw, new DateTimeZone( 'UTC' ) );
                $dt_to   = new DateTimeImmutable( $to_raw,   new DateTimeZone( 'UTC' ) );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'gs_book_bad_range', 'Invalid from/to (ISO 8601 UTC required)', array( 'status' => 400 ) );
            }
            if ( $dt_from >= $dt_to ) {
                return new WP_Error( 'gs_book_bad_range', 'from must be < to', array( 'status' => 400 ) );
            }
            $diff_days = ( $dt_to->getTimestamp() - $dt_from->getTimestamp() ) / 86400;
            if ( $diff_days > self::MAX_RANGE_DAYS ) {
                return new WP_Error( 'gs_book_range_too_large', 'Range > 31 days', array( 'status' => 400 ) );
            }

            $from_iso_z = $dt_from->format( 'Y-m-d\TH:i:s\Z' );
            $to_iso_z   = $dt_to->format( 'Y-m-d\TH:i:s\Z' );
            $settings   = self::get_booking_settings( $host_user_id );
            $slots      = self::compute_open_slots( $host_user_id, $from_iso_z, $to_iso_z, $duration_min, $settings );

            $member_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
                ? Gend_GS_Calendar_Events_REST::get_member_timezone( $host_user_id )
                : ( wp_timezone_string() ?: 'UTC' );

            return rest_ensure_response( array(
                'member_tz'    => $member_tz,
                'duration_min' => $duration_min,
                'from'         => $from_iso_z,
                'to'           => $to_iso_z,
                'slots'        => $slots,
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'GS_BOOK_FATAL handle_slots: ' . $e->getMessage() );
            return new WP_Error( 'gs_book_fatal', 'Slot computation failed', array( 'status' => 500 ) );
        }
    }

    /**
     * POST /calendar/public/{share_token}/book — atomic booking write (Pitfall 6).
     *
     * Flow:
     *   1. honeypot_check → fake-200 if hp_email non-empty (NO DB write)
     *   2. rate_limit (IP + token)
     *   3. resolve_host_by_token → 404 if unknown
     *   4. sanitize + validate inputs (duration enum, type enum, email, name length)
     *   5. parse slot_start_utc → DateTimeImmutable (UTC)
     *   6. enforce min_notice + max_bookings_per_day
     *   7. sanitize_meeting_meta (type-specific)
     *   8. START TRANSACTION → SELECT FOR UPDATE overlap re-check → INSERT → COMMIT
     *   9. fire do_action('gs_booking_created', $meeting_id, $host_user_id)
     */
    public static function handle_book( WP_REST_Request $req ) {
        $share_token = (string) $req['share_token'];

        // Honeypot FIRST — silent reject before anything else (does NOT count against rate limit).
        if ( self::honeypot_check( $req ) ) {
            error_log( 'GS_BOOK_HP rejected ip=' . self::client_ip() );
            return rest_ensure_response( array(
                'status'             => 'confirmed',
                'cancellation_token' => self::generate_cancellation_token(),
                'starts_at'          => sanitize_text_field( (string) $req->get_param( 'slot_start_utc' ) ),
            ) );
        }

        if ( $e = self::rate_limit_check( 'ip:' . self::client_ip(),  self::RATE_LIMIT_IP_PER_MIN ) )    { return $e; }
        if ( $e = self::rate_limit_check( 'tok:' . $share_token,      self::RATE_LIMIT_TOKEN_PER_MIN ) ) { return $e; }

        $host_user_id = self::resolve_host_by_token( $share_token );
        if ( $host_user_id === null ) {
            return new WP_Error( 'gs_book_token_unknown', 'Booking link invalid or revoked', array( 'status' => 404 ) );
        }

        $slot_start_utc = sanitize_text_field( (string) $req->get_param( 'slot_start_utc' ) );
        $duration_min   = (int) $req->get_param( 'duration_min' );
        $meeting_type   = sanitize_text_field( (string) $req->get_param( 'meeting_type' ) );
        $guest_name     = sanitize_text_field( (string) $req->get_param( 'guest_name' ) );
        $guest_email    = sanitize_email( (string) $req->get_param( 'guest_email' ) );
        $guest_phone    = sanitize_text_field( (string) ( $req->get_param( 'guest_phone' ) ?? '' ) );
        $notes          = sanitize_textarea_field( (string) ( $req->get_param( 'notes' ) ?? '' ) );
        $meta_payload   = (array) ( $req->get_param( 'meeting_meta' ) ?? array() );

        if ( ! in_array( $duration_min, array( 15, 30, 45, 60, 90, 120 ), true ) ) {
            return new WP_Error( 'gs_book_bad_duration', 'Invalid duration', array( 'status' => 400 ) );
        }
        if ( ! in_array( $meeting_type, array( 'in_person', 'phone', 'video' ), true ) ) {
            return new WP_Error( 'gs_book_bad_type', 'Invalid meeting type', array( 'status' => 400 ) );
        }
        if ( ! is_email( $guest_email ) ) {
            return new WP_Error( 'gs_book_bad_email', 'Invalid email', array( 'status' => 400 ) );
        }
        if ( $guest_name === '' || strlen( $guest_name ) > 120 ) {
            return new WP_Error( 'gs_book_bad_name', 'Name 1-120 chars', array( 'status' => 400 ) );
        }

        try {
            $dt_start = new DateTimeImmutable( $slot_start_utc, new DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'gs_book_bad_start', 'Invalid slot_start_utc', array( 'status' => 400 ) );
        }
        $dt_end     = $dt_start->modify( '+' . $duration_min . ' minutes' );
        $starts_utc = $dt_start->format( 'Y-m-d H:i:s' );
        $ends_utc   = $dt_end->format( 'Y-m-d H:i:s' );

        // Booking-limits enforcement (Pitfall 15).
        $settings = self::get_booking_settings( $host_user_id );
        $now_utc  = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
        $min_notice_threshold = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
            ->modify( '+' . (int) $settings['min_notice_minutes'] . ' minutes' )
            ->format( 'Y-m-d H:i:s' );
        if ( $starts_utc < $min_notice_threshold ) {
            return new WP_Error(
                'gs_book_min_notice',
                sprintf( 'Booking must be at least %d minutes from now', (int) $settings['min_notice_minutes'] ),
                array( 'status' => 422 )
            );
        }

        global $wpdb;
        $tbl  = Gend_GS_Availability_Schema::table_meetings();
        $date = substr( $starts_utc, 0, 10 );
        $day_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE member_id = %d AND status = 'booked' AND DATE(starts_at_utc) = %s",
            $host_user_id, $date
        ) );
        if ( $day_count >= (int) $settings['max_bookings_per_day'] ) {
            return new WP_Error( 'gs_book_day_cap', 'Host has reached daily booking limit', array( 'status' => 409 ) );
        }

        $meta_clean = self::sanitize_meeting_meta( $meeting_type, $meta_payload, $settings );
        if ( is_wp_error( $meta_clean ) ) { return $meta_clean; }

        // ─── ATOMIC double-book prevention (Pitfall 6 — load-bearing) ───
        $wpdb->query( 'START TRANSACTION' );
        try {
            $overlap = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl}
                  WHERE member_id = %d
                    AND status   = 'booked'
                    AND starts_at_utc < %s
                    AND ends_at_utc   > %s
                  FOR UPDATE",
                $host_user_id, $ends_utc, $starts_utc
            ) );
            if ( $overlap > 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'slot_taken', 'Slot just taken', array( 'status' => 409 ) );
            }
            $cancellation_token = self::generate_cancellation_token();
            $insert_data = array(
                'member_id'          => $host_user_id,
                'guest_user_id'      => is_user_logged_in() ? get_current_user_id() : null,
                'guest_name'         => $guest_name,
                'guest_email'        => $guest_email,
                'guest_phone'        => $guest_phone,
                'starts_at_utc'      => $starts_utc,
                'ends_at_utc'        => $ends_utc,
                'meeting_type'       => $meeting_type,
                'meeting_meta_json'  => wp_json_encode( $meta_clean + ( strlen( $notes ) ? array( 'notes' => $notes ) : array() ) ),
                'status'             => 'booked',
                'cancellation_token' => $cancellation_token,
                'created_at_utc'     => $now_utc,
                'updated_at_utc'     => $now_utc,
            );
            $ok = $wpdb->insert( $tbl, $insert_data, array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
            if ( $ok === false ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'gs_book_db', 'Database write failed', array( 'status' => 500 ) );
            }
            $meeting_id = (int) $wpdb->insert_id;
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'GS_BOOK_FATAL handle_book: ' . $e->getMessage() );
            return new WP_Error( 'gs_book_fatal', 'Booking failed', array( 'status' => 500 ) );
        }

        do_action( 'gs_booking_created', $meeting_id, $host_user_id );

        $member_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
            ? Gend_GS_Calendar_Events_REST::get_member_timezone( $host_user_id )
            : ( wp_timezone_string() ?: 'UTC' );

        return rest_ensure_response( array(
            'status'             => 'confirmed',
            'meeting_id'         => $meeting_id,
            'cancellation_token' => $cancellation_token,
            'starts_at_utc'      => self::format_iso_z( $starts_utc ),
            'ends_at_utc'        => self::format_iso_z( $ends_utc ),
            'meeting_type'       => $meeting_type,
            'member_tz'          => $member_tz,
        ) );
    }

    /**
     * GET /calendar/public/meeting/{cancellation_token} — bookee self-service fetch.
     *
     * Filters meeting_meta_json so the host's other-bookings + private fields are NEVER echoed.
     */
    public static function handle_meeting_get( WP_REST_Request $req ) {
        try {
            $ct = (string) $req['cancellation_token'];
            if ( $e = self::rate_limit_check( 'ip:' . self::client_ip(), self::RATE_LIMIT_IP_PER_MIN ) ) { return $e; }

            global $wpdb;
            $tbl = Gend_GS_Availability_Schema::table_meetings();
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, member_id, starts_at_utc, ends_at_utc, meeting_type, meeting_meta_json, status
                   FROM {$tbl}
                  WHERE cancellation_token = %s
                  LIMIT 1",
                $ct
            ), ARRAY_A );

            if ( ! $row || $row['status'] === 'cancelled' ) {
                return new WP_Error( 'gs_book_meeting_unknown', 'Meeting not found', array( 'status' => 404 ) );
            }

            $member_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
                ? Gend_GS_Calendar_Events_REST::get_member_timezone( (int) $row['member_id'] )
                : ( wp_timezone_string() ?: 'UTC' );

            // Filter meta — strip anything that could leak host's other arrangements.
            $meta_raw   = json_decode( (string) $row['meeting_meta_json'], true );
            $meta_clean = is_array( $meta_raw ) ? self::filter_meta_for_bookee( (string) $row['meeting_type'], $meta_raw ) : array();

            return rest_ensure_response( array(
                'meeting_id'    => (int) $row['id'],
                'host_user_id'  => (int) $row['member_id'],
                'starts_at_utc' => self::format_iso_z( (string) $row['starts_at_utc'] ),
                'ends_at_utc'   => self::format_iso_z( (string) $row['ends_at_utc'] ),
                'meeting_type'  => (string) $row['meeting_type'],
                'meeting_meta'  => $meta_clean,
                'status'        => (string) $row['status'],
                'member_tz'     => $member_tz,
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'GS_BOOK_FATAL handle_meeting_get: ' . $e->getMessage() );
            return new WP_Error( 'gs_book_fatal', 'Meeting fetch failed', array( 'status' => 500 ) );
        }
    }

    /**
     * POST /calendar/public/meeting/{cancellation_token}/cancel — bookee cancel.
     */
    public static function handle_cancel( WP_REST_Request $req ) {
        try {
            $ct = (string) $req['cancellation_token'];
            if ( $e = self::rate_limit_check( 'ip:' . self::client_ip(), self::RATE_LIMIT_IP_PER_MIN ) ) { return $e; }

            global $wpdb;
            $tbl = Gend_GS_Availability_Schema::table_meetings();
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, member_id, status FROM {$tbl} WHERE cancellation_token = %s LIMIT 1",
                $ct
            ), ARRAY_A );
            if ( ! $row ) {
                return new WP_Error( 'gs_book_meeting_unknown', 'Meeting not found', array( 'status' => 404 ) );
            }
            if ( $row['status'] === 'cancelled' ) {
                return rest_ensure_response( array( 'status' => 'cancelled', 'meeting_id' => (int) $row['id'] ) );
            }

            $now_utc = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
            $upd = $wpdb->update(
                $tbl,
                array( 'status' => 'cancelled', 'updated_at_utc' => $now_utc ),
                array( 'id' => (int) $row['id'] ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            if ( $upd === false ) {
                return new WP_Error( 'gs_book_db', 'Cancel update failed', array( 'status' => 500 ) );
            }
            do_action( 'gs_booking_cancelled', (int) $row['id'], (int) $row['member_id'], 'guest' );
            return rest_ensure_response( array( 'status' => 'cancelled', 'meeting_id' => (int) $row['id'] ) );
        } catch ( \Throwable $e ) {
            error_log( 'GS_BOOK_FATAL handle_cancel: ' . $e->getMessage() );
            return new WP_Error( 'gs_book_fatal', 'Cancel failed', array( 'status' => 500 ) );
        }
    }

    /**
     * POST /calendar/public/meeting/{cancellation_token}/reschedule — bookee reschedule.
     *
     * Re-runs the atomic FOR UPDATE flow with the new slot_start_utc, EXCLUDING self
     * from the overlap check via `AND id <> %d`. On success the row's starts_at_utc +
     * ends_at_utc + updated_at_utc move.
     */
    public static function handle_reschedule( WP_REST_Request $req ) {
        $ct = (string) $req['cancellation_token'];
        if ( $e = self::rate_limit_check( 'ip:' . self::client_ip(), self::RATE_LIMIT_IP_PER_MIN ) ) { return $e; }

        global $wpdb;
        $tbl = Gend_GS_Availability_Schema::table_meetings();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, member_id, starts_at_utc, ends_at_utc, status FROM {$tbl} WHERE cancellation_token = %s LIMIT 1",
            $ct
        ), ARRAY_A );
        if ( ! $row || $row['status'] === 'cancelled' ) {
            return new WP_Error( 'gs_book_meeting_unknown', 'Meeting not found', array( 'status' => 404 ) );
        }

        $slot_start_utc = sanitize_text_field( (string) $req->get_param( 'slot_start_utc' ) );
        $given_duration = (int) ( $req->get_param( 'duration_min' ) ?? 0 );

        // Default duration → preserve the existing meeting's duration.
        if ( $given_duration <= 0 ) {
            try {
                $orig_start = new DateTimeImmutable( (string) $row['starts_at_utc'], new DateTimeZone( 'UTC' ) );
                $orig_end   = new DateTimeImmutable( (string) $row['ends_at_utc'],   new DateTimeZone( 'UTC' ) );
                $given_duration = max( 1, (int) round( ( $orig_end->getTimestamp() - $orig_start->getTimestamp() ) / 60 ) );
            } catch ( \Throwable $e ) {
                $given_duration = 30;
            }
        }
        if ( ! in_array( $given_duration, array( 15, 30, 45, 60, 90, 120 ), true ) ) {
            return new WP_Error( 'gs_book_bad_duration', 'Invalid duration', array( 'status' => 400 ) );
        }

        try {
            $dt_start = new DateTimeImmutable( $slot_start_utc, new DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'gs_book_bad_start', 'Invalid slot_start_utc', array( 'status' => 400 ) );
        }
        $dt_end     = $dt_start->modify( '+' . $given_duration . ' minutes' );
        $new_starts = $dt_start->format( 'Y-m-d H:i:s' );
        $new_ends   = $dt_end->format( 'Y-m-d H:i:s' );
        $old_starts = (string) $row['starts_at_utc'];
        $now_utc    = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );

        $settings = self::get_booking_settings( (int) $row['member_id'] );
        $min_notice_threshold = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
            ->modify( '+' . (int) $settings['min_notice_minutes'] . ' minutes' )
            ->format( 'Y-m-d H:i:s' );
        if ( $new_starts < $min_notice_threshold ) {
            return new WP_Error(
                'gs_book_min_notice',
                sprintf( 'Reschedule must be at least %d minutes from now', (int) $settings['min_notice_minutes'] ),
                array( 'status' => 422 )
            );
        }

        // ─── ATOMIC reschedule (Pitfall 6) ───
        $wpdb->query( 'START TRANSACTION' );
        try {
            $overlap = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl}
                  WHERE member_id = %d
                    AND status   = 'booked'
                    AND id <> %d
                    AND starts_at_utc < %s
                    AND ends_at_utc   > %s
                  FOR UPDATE",
                (int) $row['member_id'], (int) $row['id'], $new_ends, $new_starts
            ) );
            if ( $overlap > 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'slot_taken', 'Slot just taken', array( 'status' => 409 ) );
            }
            $upd = $wpdb->update(
                $tbl,
                array( 'starts_at_utc' => $new_starts, 'ends_at_utc' => $new_ends, 'updated_at_utc' => $now_utc ),
                array( 'id' => (int) $row['id'] ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
            if ( $upd === false ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'gs_book_db', 'Reschedule update failed', array( 'status' => 500 ) );
            }
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'GS_BOOK_FATAL handle_reschedule: ' . $e->getMessage() );
            return new WP_Error( 'gs_book_fatal', 'Reschedule failed', array( 'status' => 500 ) );
        }

        do_action( 'gs_booking_rescheduled', (int) $row['id'], (int) $row['member_id'], $old_starts );

        return rest_ensure_response( array(
            'status'        => 'rescheduled',
            'meeting_id'    => (int) $row['id'],
            'starts_at_utc' => self::format_iso_z( $new_starts ),
            'ends_at_utc'   => self::format_iso_z( $new_ends ),
        ) );
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    /**
     * Resolve a share_token to a host user_id, or null if unknown/revoked.
     */
    private static function resolve_host_by_token( string $share_token ) : ?int {
        if ( strlen( $share_token ) !== 43 ) { return null; }
        global $wpdb;
        $tbl = Gend_GS_Availability_Schema::table_availability();
        $uid = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$tbl} WHERE share_token = %s LIMIT 1",
            $share_token
        ) );
        return $uid ? (int) $uid : null;
    }

    /**
     * Per-bucket rate limit using wp_transient counters.
     *
     * Returns WP_Error 429 if limit exceeded, null otherwise.
     */
    private static function rate_limit_check( string $bucket, int $limit, int $window_sec = 60 ) : ?WP_Error {
        $key   = 'gs_book_rl_' . md5( $bucket );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
        }
        set_transient( $key, $count + 1, $window_sec );
        return null;
    }

    /**
     * Honeypot check — hp_email non-empty means bot.
     */
    private static function honeypot_check( WP_REST_Request $req ) : bool {
        return trim( (string) ( $req->get_param( 'hp_email' ) ?? '' ) ) !== '';
    }

    /**
     * Read host's booking settings from booking_settings_json with defensive defaults.
     */
    private static function get_booking_settings( int $host_user_id ) : array {
        global $wpdb;
        $tbl = Gend_GS_Availability_Schema::table_availability();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT booking_settings_json FROM {$tbl} WHERE user_id = %d LIMIT 1",
            $host_user_id
        ) );
        $json = $row ? (array) json_decode( $row->booking_settings_json ?: '{}', true ) : array();
        return array(
            'min_notice_minutes'     => isset( $json['min_notice_minutes'] )     ? (int) $json['min_notice_minutes']     : self::DEFAULT_MIN_NOTICE_MIN,
            'buffer_minutes'         => isset( $json['buffer_minutes'] )         ? (int) $json['buffer_minutes']         : self::DEFAULT_BUFFER_MIN,
            'max_bookings_per_day'   => isset( $json['max_bookings_per_day'] )   ? (int) $json['max_bookings_per_day']   : self::DEFAULT_MAX_BOOKINGS_PER_DAY,
            'default_video_url'      => isset( $json['default_video_url'] )      ? esc_url_raw( (string) $json['default_video_url'] )      : '',
            'default_video_provider' => isset( $json['default_video_provider'] ) ? sanitize_text_field( (string) $json['default_video_provider'] ) : 'gend',
        );
    }

    /**
     * Compute open slots = availability windows MINUS aggregated busy MINUS blocked ranges.
     *
     * Aggregated busy comes from calling the Phase 27 source adapters DIRECTLY (Projects + Meetings).
     * bm_* (BlogManager — social/drip) is NEVER consulted: scheduled campaign posts do not block
     * booking slots per REQUIREMENTS Out-of-Scope.
     *
     * Pitfall 5 (timezone discipline): working_hours are expanded in member tz via
     * DateTimeImmutable + DateTimeZone($member_tz) (DST-safe), then converted to UTC. All
     * interval math is done in UTC. Output is UTC ISO Z.
     */
    private static function compute_open_slots( int $host_user_id, string $from_utc_iso, string $to_utc_iso, int $duration_min, array $settings ) : array {
        global $wpdb;
        $tbl_avail = Gend_GS_Availability_Schema::table_availability();
        $avail_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT timezone, working_hours_json, blocked_ranges_json FROM {$tbl_avail} WHERE user_id = %d LIMIT 1",
            $host_user_id
        ), ARRAY_A );
        if ( ! $avail_row ) { return array(); }

        $member_tz_str = (string) ( $avail_row['timezone'] ?? '' );
        try { $member_tz = new DateTimeZone( $member_tz_str !== '' ? $member_tz_str : 'UTC' ); }
        catch ( \Throwable $e ) { $member_tz = new DateTimeZone( 'UTC' ); }
        $utc = new DateTimeZone( 'UTC' );

        $working_hours  = json_decode( (string) ( $avail_row['working_hours_json']  ?? '' ), true );
        $blocked_ranges = json_decode( (string) ( $avail_row['blocked_ranges_json'] ?? '' ), true );
        if ( ! is_array( $working_hours ) )  { $working_hours  = array(); }
        if ( ! is_array( $blocked_ranges ) ) { $blocked_ranges = array(); }

        // ─── Build aggregated busy from Phase 27 source adapters DIRECTLY ───
        $busy_intervals = array();
        if ( class_exists( 'Gend_GS_Calendar_Source_Projects' ) ) {
            try {
                $proj = new Gend_GS_Calendar_Source_Projects();
                if ( $proj->is_available() ) {
                    // Plan 27-01 adapter uses read_events($user_id, $from_utc_mysql, $to_utc_mysql)
                    $from_mysql = str_replace( array( 'T', 'Z' ), array( ' ', '' ), $from_utc_iso );
                    $to_mysql   = str_replace( array( 'T', 'Z' ), array( ' ', '' ), $to_utc_iso );
                    foreach ( (array) $proj->read_events( $host_user_id, $from_mysql, $to_mysql ) as $ev ) {
                        if ( ! empty( $ev['busy'] ) && isset( $ev['start'], $ev['end'] ) ) {
                            $busy_intervals[] = array( 'start' => $ev['start'], 'end' => $ev['end'] );
                        }
                    }
                }
            } catch ( \Throwable $e ) { /* Pitfall 4 — never fatal on adapter throw */ }
        }
        if ( class_exists( 'Gend_GS_Calendar_Source_Meetings' ) ) {
            try {
                $mtg = new Gend_GS_Calendar_Source_Meetings();
                if ( $mtg->is_available() ) {
                    foreach ( (array) $mtg->events( $host_user_id, $from_utc_iso, $to_utc_iso ) as $ev ) {
                        if ( ! empty( $ev['busy'] ) && isset( $ev['start'], $ev['end'] ) ) {
                            $busy_intervals[] = array( 'start' => $ev['start'], 'end' => $ev['end'] );
                        }
                    }
                }
            } catch ( \Throwable $e ) { /* Pitfall 4 */ }
        }
        // bm_* (BlogManager) is INTENTIONALLY OMITTED — REQUIREMENTS Out of Scope.

        // Blocked ranges (already UTC) folded into busy.
        foreach ( $blocked_ranges as $br ) {
            if ( ! is_array( $br ) ) { continue; }
            $s = (string) ( $br['start_utc'] ?? '' );
            $e = (string) ( $br['end_utc']   ?? '' );
            if ( $s === '' || $e === '' ) { continue; }
            $busy_intervals[] = array( 'start' => $s, 'end' => $e );
        }

        // Apply buffer_minutes — pad busy intervals on the leading edge (Pitfall 15).
        $buffer_min = (int) $settings['buffer_minutes'];
        if ( $buffer_min > 0 ) {
            foreach ( $busy_intervals as &$bi ) {
                try {
                    $b_start = new DateTimeImmutable( $bi['start'], $utc );
                    $bi['start'] = $b_start->modify( '-' . $buffer_min . ' minutes' )->format( 'Y-m-d\TH:i:s\Z' );
                } catch ( \Throwable $e ) { /* skip malformed */ }
            }
            unset( $bi );
        }

        $now_utc          = new DateTimeImmutable( 'now', $utc );
        $min_notice_min   = (int) $settings['min_notice_minutes'];
        $min_notice_floor = $now_utc->modify( '+' . $min_notice_min . ' minutes' );

        // ─── Expand working_hours per date in [from, to) in member tz, convert to UTC ───
        $weekday_map = array( 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun' );
        try {
            $cursor      = ( new DateTime( $from_utc_iso, $utc ) )->setTimezone( $member_tz );
            $end_cursor  = ( new DateTime( $to_utc_iso,   $utc ) )->setTimezone( $member_tz );
        } catch ( \Throwable $e ) { return array(); }
        $cursor->setTime( 0, 0, 0 );

        $open_slots = array();
        $iter_cap   = 0;
        while ( $cursor < $end_cursor && $iter_cap < 366 ) {
            $iter_cap++;
            $weekday_num = (int) $cursor->format( 'N' );
            $weekday_key = $weekday_map[ $weekday_num ] ?? null;
            $date_str    = $cursor->format( 'Y-m-d' );

            // Per-day cap check — skip date if host is at or above cap.
            $tbl_meet  = Gend_GS_Availability_Schema::table_meetings();
            $day_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_meet} WHERE member_id = %d AND status = 'booked' AND DATE(starts_at_utc) = %s",
                $host_user_id, $date_str
            ) );
            if ( $day_count >= (int) $settings['max_bookings_per_day'] ) {
                $cursor->modify( '+1 day' );
                continue;
            }

            if ( $weekday_key && ! empty( $working_hours[ $weekday_key ] ) && is_array( $working_hours[ $weekday_key ] ) ) {
                foreach ( $working_hours[ $weekday_key ] as $range ) {
                    if ( ! is_array( $range ) ) { continue; }
                    $s_local = isset( $range['start'] ) ? (string) $range['start'] : '';
                    $e_local = isset( $range['end'] )   ? (string) $range['end']   : '';
                    if ( ! preg_match( '/^\d{2}:\d{2}$/', $s_local ) || ! preg_match( '/^\d{2}:\d{2}$/', $e_local ) ) { continue; }

                    $win_start_local = DateTime::createFromFormat( 'Y-m-d H:i', $date_str . ' ' . $s_local, $member_tz );
                    $win_end_local   = DateTime::createFromFormat( 'Y-m-d H:i', $date_str . ' ' . $e_local, $member_tz );
                    if ( ! $win_start_local || ! $win_end_local ) { continue; }
                    $win_start = ( clone $win_start_local )->setTimezone( $utc );
                    $win_end   = ( clone $win_end_local   )->setTimezone( $utc );

                    // Slice this window into duration-long candidate slots stepping by SLOT_GRANULARITY_MIN.
                    $slot_cursor = $win_start;
                    while ( true ) {
                        $slot_end = ( clone $slot_cursor )->modify( '+' . $duration_min . ' minutes' );
                        if ( $slot_end > $win_end ) { break; }

                        // Bound to overall requested range.
                        if ( $slot_end->format( 'Y-m-d\TH:i:s\Z' ) <= $from_utc_iso ) { $slot_cursor->modify( '+' . self::SLOT_GRANULARITY_MIN . ' minutes' ); continue; }
                        if ( $slot_cursor->format( 'Y-m-d\TH:i:s\Z' ) >= $to_utc_iso ) { break; }

                        // Min-notice gate.
                        if ( $slot_cursor < $min_notice_floor ) { $slot_cursor->modify( '+' . self::SLOT_GRANULARITY_MIN . ' minutes' ); continue; }

                        // Conflict check vs busy intervals.
                        $conflict = false;
                        $s_iso    = $slot_cursor->format( 'Y-m-d\TH:i:s\Z' );
                        $e_iso    = $slot_end->format( 'Y-m-d\TH:i:s\Z' );
                        foreach ( $busy_intervals as $bi ) {
                            // Overlap: bi.start < slot.end AND bi.end > slot.start
                            if ( $bi['start'] < $e_iso && $bi['end'] > $s_iso ) { $conflict = true; break; }
                        }
                        if ( ! $conflict ) {
                            $open_slots[] = array(
                                'start_utc'    => $s_iso,
                                'end_utc'      => $e_iso,
                                'duration_min' => $duration_min,
                            );
                        }
                        $slot_cursor->modify( '+' . self::SLOT_GRANULARITY_MIN . ' minutes' );
                    }
                }
            }
            $cursor->modify( '+1 day' );
        }

        return $open_slots;
    }

    /**
     * Sanitize per-type meeting_meta payload. Returns clean array or WP_Error on validation failure.
     *
     * Plan 29-02 will extend with full URL allowlist for external video providers; THIS plan
     * accepts only host-configured default video URLs for the bookee path (bookees can NEVER
     * paste a URL).
     */
    private static function sanitize_meeting_meta( string $meeting_type, array $payload, array $settings ) {
        if ( $meeting_type === 'in_person' ) {
            $address = sanitize_text_field( (string) ( $payload['address'] ?? '' ) );
            if ( $address === '' || strlen( $address ) > 300 ) {
                return new WP_Error( 'gs_book_in_person_no_addr', 'Address required (1-300 chars)', array( 'status' => 422 ) );
            }
            return array( 'address' => $address );
        }
        if ( $meeting_type === 'phone' ) {
            $num = sanitize_text_field( (string) ( $payload['callee_number'] ?? '' ) );
            $dir = sanitize_text_field( (string) ( $payload['direction'] ?? '' ) );
            if ( ! preg_match( '/^\+?[0-9\- ()]{7,30}$/', $num ) ) {
                return new WP_Error( 'gs_book_phone_bad_num', 'Invalid phone number', array( 'status' => 422 ) );
            }
            if ( ! in_array( $dir, array( 'guest_calls_host', 'host_calls_guest' ), true ) ) {
                return new WP_Error( 'gs_book_phone_bad_dir', 'Invalid direction', array( 'status' => 422 ) );
            }
            return array( 'callee_number' => $num, 'direction' => $dir );
        }
        if ( $meeting_type === 'video' ) {
            $provider = sanitize_text_field( (string) ( $payload['provider'] ?? $settings['default_video_provider'] ) );
            if ( $provider === '' ) { $provider = 'gend'; }
            // For PUBLIC bookee path: bookee CANNOT paste a URL — read from host's configured default.
            $room_url = (string) $settings['default_video_url'];
            if ( $room_url === '' && $provider === 'gend' ) {
                // Phase 30 will mint a Jitsi room URL here; for now require host configuration.
                return new WP_Error( 'gs_book_video_no_url', 'Host has not configured a video room', array( 'status' => 422 ) );
            }
            if ( $room_url === '' ) {
                return new WP_Error( 'gs_book_video_no_url', 'Host has not configured a video room', array( 'status' => 422 ) );
            }
            return array( 'provider' => $provider, 'room_url' => $room_url );
        }
        return new WP_Error( 'gs_book_bad_type', 'Invalid meeting type', array( 'status' => 400 ) );
    }

    /**
     * Filter meeting_meta returned to the bookee — strip any keys not safe to echo
     * (Pitfall 8 owner-vs-shared discipline). The bookee already provided/saw this info,
     * but we re-enforce the allowlist here so a Plan 29-02 metadata extension doesn't
     * accidentally leak host-only fields.
     */
    private static function filter_meta_for_bookee( string $meeting_type, array $meta ) : array {
        if ( $meeting_type === 'in_person' ) {
            return array( 'address' => isset( $meta['address'] ) ? (string) $meta['address'] : '' );
        }
        if ( $meeting_type === 'phone' ) {
            return array(
                'callee_number' => isset( $meta['callee_number'] ) ? (string) $meta['callee_number'] : '',
                'direction'     => isset( $meta['direction'] )     ? (string) $meta['direction']     : '',
            );
        }
        if ( $meeting_type === 'video' ) {
            return array(
                'provider' => isset( $meta['provider'] ) ? (string) $meta['provider'] : '',
                'room_url' => isset( $meta['room_url'] ) ? (string) $meta['room_url'] : '',
            );
        }
        return array();
    }

    private static function client_ip() : string {
        return sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    }

    private static function generate_cancellation_token() : string {
        // 43-char alphanumeric → 256-bit entropy. Equivalent invariant: wp_generate_password(43, false, false)
        return wp_generate_password( 43, false, false );
    }

    private static function format_iso_z( string $mysql_dt_utc ) : string {
        return str_replace( ' ', 'T', $mysql_dt_utc ) . 'Z';
    }
}
