<?php
/**
 * gend-society — Authed Member-Side Meetings REST (Phase 29 Plan 02).
 *
 * Host-side (authed) booking REST handler — the calling user MUST be logged in,
 * and every write path is scoped to that user's OWN meetings/availability row.
 *
 * Routes registered (all on rest_api_init, namespace 'gs/v1'):
 *   GET  /gs/v1/calendar/meetings                      → host's own booked meetings (window)
 *   POST /gs/v1/calendar/meetings                      → Schedule Meeting direct-create (atomic)
 *   POST /gs/v1/calendar/meetings/{id}/cancel          → host cancels own meeting
 *   POST /gs/v1/calendar/share-token/rotate            → revoke + regenerate share_token
 *
 * Route prefix invariant: /gs/v1/calendar (meetings + share-token/rotate)
 *
 * Security invariants (load-bearing):
 *   - Pitfall 7 (identity hardening): EVERY handler reads identity EXCLUSIVELY via
 *     get_current_user_id(); the `?user=` query param is NEVER consulted. Cross-member
 *     writes (handle_cancel) return 403 explicitly via a member_id row-check.
 *   - Pitfall 6 (atomic double-book prevention): handle_create wraps overlap re-check +
 *     INSERT in START TRANSACTION + SELECT … FOR UPDATE + COMMIT/ROLLBACK on
 *     wp_gs_member_meetings (mirrors Plan 29-01 handle_book flow).
 *   - Pitfall 16 (URL allowlist): EVERY external video URL funnels through
 *     url_host_allowed() — https only, host MUST be in VIDEO_HOST_ALLOWLIST (exact)
 *     OR end with one of VIDEO_HOST_WILDCARD_SUFFIXES (suffix-match). Rejects
 *     http://, javascript:, data:, file:, and any host outside the 4-vendor list.
 *   - Pitfall 2 (auto-deactivation safety): handler bodies wrap DB writes in
 *     try/catch \Throwable → ROLLBACK + WP_Error so a DB hiccup never fatals
 *     gend-society into WP's auto-deactivation trap.
 *
 * 4 meeting types (MEET-01..04):
 *   - in_person  → meta { address }                                    (1-300 chars)
 *   - phone      → meta { callee_number, direction }                   (enum guest_calls_host|host_calls_guest)
 *   - video ext  → meta { provider, room_url }                         (URL allowlist — zoom.us / meet.google.com / teams.microsoft.com / *.webex.com)
 *   - video gend → meta { provider:'gend', jitsi_room }                (auto-generated; Phase 30 mints JWT at join time)
 *
 * Hooks fired (consistent with Plan 29-01 do_action surface):
 *   - do_action('gs_booking_created',   $meeting_id, $host_user_id)
 *   - do_action('gs_booking_cancelled', $meeting_id, $host_user_id, 'host')
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Gend_GS_Booking_Meetings_REST {

    // Single namespace via class const: const NS = 'gs/v1';
    const NS = 'gs/v1';

    /**
     * Exact-match allowlist for external video room URLs (Pitfall 16 — load-bearing).
     * Host MUST equal one of these (case-insensitive) for a non-wildcard match.
     */
    const VIDEO_HOST_ALLOWLIST = array(
        'zoom.us',
        'meet.google.com',
        'teams.microsoft.com',
    );

    /**
     * Wildcard SUFFIX allowlist — host must END WITH one of these. Lets
     *   us02web.zoom.us              (Zoom regional shard)  → suffix '.zoom.us'  → PASS
     *   acme.webex.com               (Webex per-tenant)     → suffix '.webex.com' → PASS
     * but REJECTS startsWith traps like `zoom.us.attacker.com` (host ends with .com, not .zoom.us)
     * and `fakezoom.us` (host = 'fakezoom.us', doesn't end with '.zoom.us' which has the leading dot).
     */
    const VIDEO_HOST_WILDCARD_SUFFIXES = array(
        '.zoom.us',
        '.webex.com',
    );

    /**
     * Register all four authed routes on rest_api_init.
     * Pitfall 1: ALL register_rest_route calls land inside rest_api_init only.
     */
    public static function register_routes() : void {
        // 1. GET host's own meetings (window).
        register_rest_route( self::NS, '/calendar/meetings', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'handle_list' ),
                'permission_callback' => 'is_user_logged_in',
                'args'                => array(
                    'from' => array( 'required' => false, 'type' => 'string' ),
                    'to'   => array( 'required' => false, 'type' => 'string' ),
                ),
            ),
            // 2. POST direct-create (Schedule Meeting modal).
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'handle_create' ),
                'permission_callback' => 'is_user_logged_in',
                'args'                => array(
                    'slot_start_utc' => array( 'required' => true,  'type' => 'string' ),
                    'duration_min'   => array( 'required' => true,  'type' => 'integer' ),
                    'meeting_type'   => array( 'required' => true,  'type' => 'string' ),
                    'meeting_meta'   => array( 'required' => false, 'type' => 'object' ),
                    'guest_name'     => array( 'required' => true,  'type' => 'string' ),
                    'guest_email'    => array( 'required' => true,  'type' => 'string' ),
                    'guest_phone'    => array( 'required' => false, 'type' => 'string' ),
                    'guest_user_id'  => array( 'required' => false, 'type' => 'integer' ),
                    'notes'          => array( 'required' => false, 'type' => 'string' ),
                ),
            ),
        ) );

        // 3. POST host-cancel by meeting id (member_id-scoped).
        register_rest_route( self::NS, '/calendar/meetings/(?P<id>\d+)/cancel', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'handle_cancel' ),
            'permission_callback' => 'is_user_logged_in',
        ) );

        // 4. POST share-token rotate (revoke + regenerate).
        register_rest_route( self::NS, '/calendar/share-token/rotate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'handle_rotate_token' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
    }

    // ─── Authed handlers ────────────────────────────────────────────────────

    /**
     * GET /calendar/meetings?from=&to= — host's own booked meetings.
     *
     * Pitfall 7: identity = get_current_user_id() ONLY. ?user= IGNORED.
     */
    public static function handle_list( WP_REST_Request $req ) {
        try {
            $host_user_id = (int) get_current_user_id();
            if ( $host_user_id <= 0 ) {
                return new WP_Error( 'gs_meet_no_user', 'login required', array( 'status' => 401 ) );
            }

            // Window default: now → +30d if not provided.
            $from_iso = sanitize_text_field( (string) ( $req->get_param( 'from' ) ?? gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
            $to_iso   = sanitize_text_field( (string) ( $req->get_param( 'to' )   ?? gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * 86400 ) ) );

            // Validate ISO bounds via DateTimeImmutable.
            try {
                $dt_from = new DateTimeImmutable( $from_iso, new DateTimeZone( 'UTC' ) );
                $dt_to   = new DateTimeImmutable( $to_iso,   new DateTimeZone( 'UTC' ) );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'gs_meet_bad_range', 'Invalid from/to (ISO 8601 UTC required)', array( 'status' => 400 ) );
            }
            if ( $dt_from >= $dt_to ) {
                return new WP_Error( 'gs_meet_bad_range', 'from must be < to', array( 'status' => 400 ) );
            }

            $from_mysql = $dt_from->format( 'Y-m-d H:i:s' );
            $to_mysql   = $dt_to->format( 'Y-m-d H:i:s' );

            global $wpdb;
            $tbl  = Gend_GS_Availability_Schema::table_meetings();
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, starts_at_utc, ends_at_utc, meeting_type, meeting_meta_json, status,
                        guest_name, guest_email, guest_phone, guest_user_id, cancellation_token
                   FROM {$tbl}
                  WHERE member_id = %d
                    AND starts_at_utc >= %s
                    AND starts_at_utc <  %s
                  ORDER BY starts_at_utc ASC",
                $host_user_id, $from_mysql, $to_mysql
            ), ARRAY_A );

            $items = array();
            foreach ( (array) $rows as $r ) {
                $items[] = array(
                    'meeting_id'         => (int) $r['id'],
                    'starts_at_utc'      => self::format_iso_z( (string) $r['starts_at_utc'] ),
                    'ends_at_utc'        => self::format_iso_z( (string) $r['ends_at_utc'] ),
                    'meeting_type'       => (string) $r['meeting_type'],
                    'meeting_meta'       => (array) json_decode( (string) $r['meeting_meta_json'], true ),
                    'status'             => (string) $r['status'],
                    'guest_name'         => (string) $r['guest_name'],
                    'guest_email'        => (string) $r['guest_email'],
                    'guest_phone'        => (string) $r['guest_phone'],
                    'guest_user_id'      => isset( $r['guest_user_id'] ) ? (int) $r['guest_user_id'] : 0,
                    'cancellation_token' => (string) $r['cancellation_token'],
                );
            }

            $member_tz = class_exists( 'Gend_GS_Calendar_Events_REST' )
                ? Gend_GS_Calendar_Events_REST::get_member_timezone( $host_user_id )
                : ( wp_timezone_string() ?: 'UTC' );

            return rest_ensure_response( array(
                'meetings'  => $items,
                'count'     => count( $items ),
                'from'      => $dt_from->format( 'Y-m-d\TH:i:s\Z' ),
                'to'        => $dt_to->format( 'Y-m-d\TH:i:s\Z' ),
                'member_tz' => $member_tz,
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'GS_MEET_FATAL handle_list: ' . $e->getMessage() );
            return new WP_Error( 'gs_meet_fatal', 'List failed', array( 'status' => 500 ) );
        }
    }

    /**
     * POST /calendar/meetings — Schedule Meeting direct-create (atomic FOR UPDATE).
     *
     * Same atomic START TRANSACTION + SELECT FOR UPDATE + INSERT + COMMIT
     * flow as Plan 29-01 handle_book. Differences:
     *   - $host_user_id = get_current_user_id() (this user IS the host)
     *   - meeting_meta validated via sanitize_meeting_meta_with_allowlist (Pitfall 16)
     *   - video provider=gend → auto-generate jitsi_room name (Phase 30 mints JWT)
     *   - guest_user_id captured if provided + valid (for MEET-05 second-leg rendering)
     *   - min_notice + max_bookings_per_day apply (host can't accidentally double-book)
     *   - fires do_action('gs_booking_created', $meeting_id, $host_user_id)
     */
    public static function handle_create( WP_REST_Request $req ) {
        $host_user_id = (int) get_current_user_id();
        if ( $host_user_id <= 0 ) {
            return new WP_Error( 'gs_meet_no_user', 'login required', array( 'status' => 401 ) );
        }

        $slot_start_utc = sanitize_text_field( (string) $req->get_param( 'slot_start_utc' ) );
        $duration_min   = (int) $req->get_param( 'duration_min' );
        $meeting_type   = sanitize_text_field( (string) $req->get_param( 'meeting_type' ) );
        $meta_payload   = (array) ( $req->get_param( 'meeting_meta' ) ?? array() );
        $guest_name     = sanitize_text_field( (string) $req->get_param( 'guest_name' ) );
        $guest_email    = sanitize_email( (string) $req->get_param( 'guest_email' ) );
        $guest_phone    = sanitize_text_field( (string) ( $req->get_param( 'guest_phone' ) ?? '' ) );
        $guest_user_id  = (int) ( $req->get_param( 'guest_user_id' ) ?? 0 );
        $notes          = sanitize_textarea_field( (string) ( $req->get_param( 'notes' ) ?? '' ) );

        if ( ! in_array( $duration_min, array( 15, 30, 45, 60, 90, 120 ), true ) ) {
            return new WP_Error( 'gs_meet_bad_duration', 'Invalid duration', array( 'status' => 400 ) );
        }
        if ( ! in_array( $meeting_type, array( 'in_person', 'phone', 'video' ), true ) ) {
            return new WP_Error( 'gs_meet_bad_type', 'Invalid meeting type', array( 'status' => 400 ) );
        }
        if ( ! is_email( $guest_email ) ) {
            return new WP_Error( 'gs_meet_bad_email', 'Invalid guest email', array( 'status' => 400 ) );
        }
        if ( $guest_name === '' || strlen( $guest_name ) > 120 ) {
            return new WP_Error( 'gs_meet_bad_name', 'Guest name 1-120 chars', array( 'status' => 400 ) );
        }

        // Validate guest_user_id if provided.
        if ( $guest_user_id > 0 && ! get_userdata( $guest_user_id ) ) {
            $guest_user_id = 0; // silently drop unknown id
        }

        try {
            $dt_start = new DateTimeImmutable( $slot_start_utc, new DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'gs_meet_bad_start', 'Invalid slot_start_utc', array( 'status' => 400 ) );
        }
        $dt_end     = $dt_start->modify( '+' . $duration_min . ' minutes' );
        $starts_utc = $dt_start->format( 'Y-m-d H:i:s' );
        $ends_utc   = $dt_end->format( 'Y-m-d H:i:s' );

        // Per-type meta validation + URL allowlist enforcement (Pitfall 16).
        $meta_clean = self::sanitize_meeting_meta_with_allowlist( $meeting_type, $meta_payload, $host_user_id );
        if ( is_wp_error( $meta_clean ) ) { return $meta_clean; }

        // Host-side min-notice + per-day cap reuse same booking_settings_json as Plan 29-01.
        $settings = self::get_booking_settings( $host_user_id );
        $now_utc  = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
        $min_notice_threshold = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
            ->modify( '+' . (int) $settings['min_notice_minutes'] . ' minutes' )
            ->format( 'Y-m-d H:i:s' );
        if ( $starts_utc < $min_notice_threshold ) {
            return new WP_Error(
                'gs_meet_min_notice',
                sprintf( 'Schedule must be at least %d minutes from now', (int) $settings['min_notice_minutes'] ),
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
            return new WP_Error( 'gs_meet_day_cap', 'Daily booking limit reached', array( 'status' => 409 ) );
        }

        // ─── ATOMIC double-book prevention (Pitfall 6 — same flow as Plan 29-01 handle_book) ───
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
            // wp_generate_password(43, false, false) → 43-char URL-safe (256-bit entropy), per-meeting cancellation_token
            $cancellation_token = wp_generate_password( 43, false, false );
            $insert_data = array(
                'member_id'          => $host_user_id,
                'guest_user_id'      => $guest_user_id > 0 ? $guest_user_id : null,
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
                return new WP_Error( 'gs_meet_db', 'Database write failed', array( 'status' => 500 ) );
            }
            $meeting_id = (int) $wpdb->insert_id;
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'GS_MEET_FATAL handle_create: ' . $e->getMessage() );
            return new WP_Error( 'gs_meet_fatal', 'Schedule failed', array( 'status' => 500 ) );
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
            'meeting_meta'       => $meta_clean,
            'member_tz'          => $member_tz,
        ) );
    }

    /**
     * POST /calendar/meetings/{id}/cancel — host cancels own meeting.
     *
     * Pitfall 7 + Pitfall 8: member_id row-check; cross-member writes 403.
     */
    public static function handle_cancel( WP_REST_Request $req ) {
        try {
            $meeting_id   = (int) $req['id'];
            $host_user_id = (int) get_current_user_id();
            if ( $host_user_id <= 0 ) {
                return new WP_Error( 'gs_meet_no_user', 'login required', array( 'status' => 401 ) );
            }
            if ( $meeting_id <= 0 ) {
                return new WP_Error( 'gs_meet_bad_id', 'Invalid meeting id', array( 'status' => 400 ) );
            }

            global $wpdb;
            $tbl = Gend_GS_Availability_Schema::table_meetings();
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, member_id, status FROM {$tbl} WHERE id = %d LIMIT 1",
                $meeting_id
            ) );
            if ( ! $row ) {
                return new WP_Error( 'gs_meet_not_found', 'Meeting not found', array( 'status' => 404 ) );
            }
            // Member-id check — host can ONLY cancel own meetings (Pitfall 7 + Pitfall 8).
            if ( (int) $row->member_id !== $host_user_id ) {
                return new WP_Error( 'gs_meet_forbidden', 'Not your meeting', array( 'status' => 403 ) );
            }
            if ( $row->status === 'cancelled' ) {
                return rest_ensure_response( array(
                    'status'     => 'already_cancelled',
                    'meeting_id' => $meeting_id,
                ) );
            }

            $now_utc = gmdate( 'Y-m-d H:i:s' );
            $upd = $wpdb->update(
                $tbl,
                array( 'status' => 'cancelled', 'updated_at_utc' => $now_utc ),
                array( 'id' => $meeting_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            if ( $upd === false ) {
                return new WP_Error( 'gs_meet_db', 'Cancel update failed', array( 'status' => 500 ) );
            }

            do_action( 'gs_booking_cancelled', $meeting_id, $host_user_id, 'host' );

            return rest_ensure_response( array(
                'status'     => 'cancelled',
                'meeting_id' => $meeting_id,
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'GS_MEET_FATAL handle_cancel: ' . $e->getMessage() );
            return new WP_Error( 'gs_meet_fatal', 'Cancel failed', array( 'status' => 500 ) );
        }
    }

    /**
     * POST /calendar/share-token/rotate — revoke + regenerate share_token.
     *
     * Writes new 43-char wp_generate_password value into wp_gs_member_availability.share_token.
     * Existing booked meetings REMAIN ACCESSIBLE via their per-meeting cancellation_token
     * (which is NOT derived from share_token — they're independent secrets).
     *
     * UPSERT pattern: if no availability row exists, create a minimal one with UTC tz +
     * the new token (so a member who has never set availability can still rotate to publish).
     */
    public static function handle_rotate_token( WP_REST_Request $req ) {
        try {
            $host_user_id = (int) get_current_user_id();
            if ( $host_user_id <= 0 ) {
                return new WP_Error( 'gs_meet_no_user', 'login required', array( 'status' => 401 ) );
            }

            // wp_generate_password(43, false, false) → 43-char URL-safe (256-bit entropy)
            $new_token = wp_generate_password( 43, false, false );
            $now_ts    = time();
            $now_utc   = gmdate( 'Y-m-d H:i:s', $now_ts );

            global $wpdb;
            $tbl = Gend_GS_Availability_Schema::table_availability();
            // UPSERT — if no row exists, create minimal with the new token + UTC tz.
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$tbl}
                     (user_id, timezone, share_token, created_at_ts, updated_at_ts)
                 VALUES (%d, 'UTC', %s, %d, %d)
                 ON DUPLICATE KEY UPDATE
                     share_token   = VALUES(share_token),
                     updated_at_ts = VALUES(updated_at_ts)",
                $host_user_id, $new_token, $now_ts, $now_ts
            ) );

            // Cache-bust the member tz key (Phase 27/28 cross-class cache contract).
            if ( class_exists( 'Gend_GS_Availability_REST' ) ) {
                wp_cache_delete( Gend_GS_Availability_REST::tz_cache_key( $host_user_id ), 'gs_avail' );
            }

            return rest_ensure_response( array(
                'status'      => 'rotated',
                'share_token' => $new_token,
                'rotated_at'  => self::format_iso_z( $now_utc ),
                'note'        => 'Existing booked meetings remain accessible via their cancellation_token (per-meeting, not derived from share_token).',
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'GS_MEET_FATAL handle_rotate_token: ' . $e->getMessage() );
            return new WP_Error( 'gs_meet_fatal', 'Rotate failed', array( 'status' => 500 ) );
        }
    }

    // ─── Sanitizers + helpers ───────────────────────────────────────────────

    /**
     * Per-type meeting_meta validator with URL allowlist for external video (Pitfall 16).
     *
     * Returns sanitized array on success, WP_Error 422 on validation failure. Public so
     * Gend_GS_Availability_REST::sanitize_booking_settings() (Plan 29-02 Task 2) can
     * funnel `default_video_url` through the SAME allowlist as host-side meeting creates.
     *
     * @param string $meeting_type  'in_person' | 'phone' | 'video'
     * @param array  $payload       raw meta payload from request
     * @param int    $host_user_id  for native gend jitsi_room name generation
     */
    public static function sanitize_meeting_meta_with_allowlist( string $meeting_type, array $payload, int $host_user_id ) {
        switch ( $meeting_type ) {

            case 'in_person':
                $address = sanitize_text_field( (string) ( $payload['address'] ?? '' ) );
                if ( $address === '' || strlen( $address ) > 300 ) {
                    return new WP_Error( 'gs_meet_bad_address', 'Address 1-300 chars', array( 'status' => 422 ) );
                }
                return array( 'address' => $address );

            case 'phone':
                $callee = sanitize_text_field( (string) ( $payload['callee_number'] ?? '' ) );
                $dir    = sanitize_text_field( (string) ( $payload['direction'] ?? '' ) );
                if ( ! preg_match( '/^\+?[0-9\- ()]{7,30}$/', $callee ) ) {
                    return new WP_Error( 'gs_meet_bad_phone', 'Invalid phone number', array( 'status' => 422 ) );
                }
                if ( ! in_array( $dir, array( 'guest_calls_host', 'host_calls_guest' ), true ) ) {
                    return new WP_Error( 'gs_meet_bad_direction', 'Invalid direction', array( 'status' => 422 ) );
                }
                return array( 'callee_number' => $callee, 'direction' => $dir );

            case 'video':
                $provider = sanitize_text_field( (string) ( $payload['provider'] ?? '' ) );
                if ( ! in_array( $provider, array( 'zoom', 'meet', 'teams', 'webex', 'gend' ), true ) ) {
                    return new WP_Error( 'gs_meet_bad_provider', 'Invalid video provider', array( 'status' => 422 ) );
                }
                if ( $provider === 'gend' ) {
                    // Native gend video — Phase 30 wires JWT minting at join time.
                    // We allocate the room NAME here; Phase 30 will resolve the URL.
                    return array(
                        'provider'   => 'gend',
                        'jitsi_room' => self::generate_jitsi_room( $host_user_id ),
                    );
                }
                // External video: URL allowlist enforcement (Pitfall 16 — load-bearing).
                $url = isset( $payload['room_url'] ) ? esc_url_raw( (string) $payload['room_url'] ) : '';
                if ( $url === '' ) {
                    return new WP_Error( 'gs_meet_video_no_url', 'room_url required for external video', array( 'status' => 422 ) );
                }
                if ( ! self::url_host_allowed( $url ) ) {
                    return new WP_Error(
                        'gs_meet_bad_video_url',
                        'External video URL must be on zoom.us, meet.google.com, teams.microsoft.com, or *.webex.com (https only)',
                        array( 'status' => 422 )
                    );
                }
                return array( 'provider' => $provider, 'room_url' => $url );

            default:
                return new WP_Error( 'gs_meet_bad_type', 'Unknown meeting type', array( 'status' => 422 ) );
        }
    }

    /**
     * URL allowlist check (Pitfall 16 — load-bearing security).
     *
     * Rules:
     *   1. Scheme MUST be exactly 'https' (rejects http://, javascript:, data:, file:, etc.)
     *   2. Host MUST equal one of VIDEO_HOST_ALLOWLIST (exact, case-insensitive)
     *      OR end with one of VIDEO_HOST_WILDCARD_SUFFIXES (suffix-match).
     *
     * Important: wildcard suffixes have a LEADING dot ('.zoom.us', '.webex.com') so
     * `fakezoom.us` is REJECTED (doesn't end with '.zoom.us') and `zoom.us.attacker.com`
     * is REJECTED (ends with '.com', not '.zoom.us').
     */
    private static function url_host_allowed( string $url ) : bool {
        if ( $url === '' ) { return false; }
        $scheme = parse_url( $url, PHP_URL_SCHEME );
        $host   = parse_url( $url, PHP_URL_HOST );
        if ( $scheme !== 'https' || ! is_string( $host ) || $host === '' ) {
            return false;
        }
        $host = strtolower( $host );
        // Exact match.
        if ( in_array( $host, self::VIDEO_HOST_ALLOWLIST, true ) ) {
            return true;
        }
        // Wildcard suffix match (e.g. 'us02web.zoom.us' ends with '.zoom.us').
        foreach ( self::VIDEO_HOST_WILDCARD_SUFFIXES as $suffix ) {
            $sl = strlen( $suffix );
            if ( $sl > 0 && strlen( $host ) > $sl && substr( $host, -$sl ) === $suffix ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate a per-host, per-meeting Jitsi room NAME for native gend video.
     *
     * Pattern: gend-meet-{host_user_id}-{16-char wp_generate_password}
     * Phase 30 mints the JWT for this room name at join time.
     */
    private static function generate_jitsi_room( int $host_user_id ) : string {
        // wp_generate_password(16, false, false) → 16-char alphanumeric room suffix (Phase 30 mints JWT for this room)
        return 'gend-meet-' . $host_user_id . '-' . wp_generate_password( 16, false, false );
    }

    /**
     * Read host's booking_settings_json with defensive defaults.
     *
     * Mirrors Plan 29-01 Gend_GS_Booking_Public_REST::get_booking_settings (private
     * there; replicated here so this class is self-contained without leaning on the
     * other class's private surface).
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
            'min_notice_minutes'   => isset( $json['min_notice_minutes'] )   ? (int) $json['min_notice_minutes']   : 60,
            'buffer_minutes'       => isset( $json['buffer_minutes'] )       ? (int) $json['buffer_minutes']       : 0,
            'max_bookings_per_day' => isset( $json['max_bookings_per_day'] ) ? (int) $json['max_bookings_per_day'] : 8,
        );
    }

    /**
     * Format a MySQL DATETIME (UTC) string as ISO-8601 Z (no offset translation —
     * the value is already UTC). Same pattern as Plan 29-01.
     */
    private static function format_iso_z( string $mysql_dt_utc ) : string {
        return str_replace( ' ', 'T', $mysql_dt_utc ) . 'Z';
    }
}
