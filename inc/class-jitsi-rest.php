<?php
/**
 * Time-gated JWT mint endpoint for native gend video meetings.
 *
 * POST /gs/v1/calendar/meetings/{id}/jitsi-jwt
 *   → 200 { jwt, room, domain, expires_at, moderator, starts_at_utc, ends_at_utc }
 *   → 401 rest_not_logged_in           — anonymous caller
 *   → 403 forbidden                    — logged in but neither host nor guest
 *   → 403 too_early                    — request before (starts_at_utc - 300 sec)
 *   → 403 too_late                     — request after (ends_at_utc + 1800 sec)
 *   → 404 not_found                    — meeting id unknown
 *   → 410 cancelled                    — meeting row status='cancelled'
 *   → 422 not_a_video_meeting          — meeting_type != 'video'
 *   → 422 not_a_gend_video_meeting     — provider != 'gend' (zoom/meet/teams/in_person/phone)
 *   → 500 jwt_secret_missing           — operator misconfigured GS_JITSI_JWT_SECRET
 *   → 500 internal                     — malformed timestamps / class-load order broken
 *
 * Pitfall mitigations:
 *   - Pitfall 7  (identity discipline): caller via get_current_user_id() ONLY; ?user= IGNORED.
 *   - Pitfall 10 (server-clock time gate): time() vs strtotime($row->starts_at_utc . ' UTC')
 *                — NEVER trust client clock; UTC suffix prevents server-tz drift (project_oauth_timezone_bug memory).
 *   - Pitfall 19 (load order): register_rest_route ONLY inside register_routes(), hooked on rest_api_init.
 *   - Pitfall 1+2 (deploy/activation): class_exists guard before Gend_GS_Jitsi_JWT::mint call so a partial
 *                deploy (Plan 01 file not yet cp'd to PVC) returns 500 internal instead of fatal+auto-deactivate.
 *
 * Phase 30 / Plan 30-02 / VID-02 + VID-03.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Gend_GS_Jitsi_REST {

    const NAMESPACE_V1   = 'gs/v1';
    const DOMAIN         = 'meet.gend.me';
    const TIME_GATE_PRE  = 300;    // sec before starts_at_utc that join unlocks (matches Gend_GS_Jitsi_JWT::NBF_LEAD_GRACE_SEC).
    const TIME_GATE_POST = 1800;   // sec after ends_at_utc that join is still allowed (matches EXP_TAIL_GRACE_SEC).

    /**
     * Per-request row cache so permission_callback + handle_mint don't double-query.
     * Keyed by meeting_id (int).
     */
    private static $row_cache = array();

    /**
     * Hooked on rest_api_init. Registers POST /meetings/{id}/jitsi-jwt.
     */
    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE_V1,
            '/calendar/meetings/(?P<id>\d+)/jitsi-jwt',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'handle_mint' ),
                'permission_callback' => array( __CLASS__, 'permission_callback' ),
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => static function ( $value ) {
                            return is_numeric( $value ) && (int) $value > 0;
                        },
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    /**
     * Permission: logged in AND caller is host (member_id) OR guest (guest_user_id) of the meeting.
     *
     * Returns:
     *   WP_Error('rest_not_logged_in', 401) — anonymous caller
     *   WP_Error('not_found', 404)          — meeting id unknown (404 here vs 403 forbidden so
     *                                         Plan 30-04 UAT can distinguish "missing" from "cross-member")
     *   WP_Error('forbidden', 403)          — logged in but neither host nor guest
     *   true                                — caller passes
     */
    public static function permission_callback( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
        }

        $caller_id  = (int) get_current_user_id();   // Pitfall 7: NEVER from $request.
        $meeting_id = (int) $request['id'];

        $row = self::load_meeting( $meeting_id );
        if ( null === $row ) {
            return new WP_Error( 'not_found', 'Meeting not found.', array( 'status' => 404 ) );
        }

        $is_host  = ( $caller_id === (int) $row->member_id );
        $is_guest = ( $caller_id === (int) $row->guest_user_id );
        if ( ! $is_host && ! $is_guest ) {
            return new WP_Error(
                'forbidden',
                'Caller is neither host nor guest of this meeting.',
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Handler: confirms gend provider, enforces server-clock time gate, mints JWT, returns envelope.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_mint( WP_REST_Request $request ) {
        $caller_id  = (int) get_current_user_id();   // Pitfall 7: server-side identity ONLY.
        $meeting_id = (int) $request['id'];

        $row = self::load_meeting( $meeting_id );
        if ( null === $row ) {
            return new WP_Error( 'not_found', 'Meeting not found.', array( 'status' => 404 ) );
        }

        if ( 'cancelled' === $row->status ) {
            return new WP_Error( 'cancelled', 'Meeting is cancelled.', array( 'status' => 410 ) );
        }
        if ( 'video' !== $row->meeting_type ) {
            return new WP_Error( 'not_a_video_meeting', 'Meeting type is not video.', array( 'status' => 422 ) );
        }

        $meta = json_decode( (string) $row->meeting_meta_json, true );
        if ( ! is_array( $meta ) || ( isset( $meta['provider'] ) ? $meta['provider'] : '' ) !== 'gend' ) {
            return new WP_Error(
                'not_a_gend_video_meeting',
                'Meeting provider is not "gend" — JWT mint is for native gend video only.',
                array( 'status' => 422 )
            );
        }

        // Resolve the room name from the DEPLOYED Phase 29 contract — meta.jitsi_room is the value
        // already emailed to guests as https://meet.gend.me/{jitsi_room}. Legacy rows fall back to gsmeet-{id}.
        // class_exists guard covers BOTH resolve_room() AND mint() below (single guard, Pitfall 1+2 safety).
        if ( ! class_exists( 'Gend_GS_Jitsi_JWT' ) ) {
            return new WP_Error(
                'internal',
                'Gend_GS_Jitsi_JWT class missing — deploy order violation (Plan 30-01 file not yet on PVC).',
                array( 'status' => 500 )
            );
        }
        $room = Gend_GS_Jitsi_JWT::resolve_room( $meta, $meeting_id );

        // Server-clock time gate — UTC explicit suffix on strtotime (Pitfall 5/10 + project_oauth_timezone_bug
        // memory: gend.me hub had gmt_offset=-5 that broke OAuth; never depend on server tz for math).
        $now    = time();
        $starts = strtotime( $row->starts_at_utc . ' UTC' );
        $ends   = strtotime( $row->ends_at_utc . ' UTC' );
        if ( false === $starts || false === $ends ) {
            return new WP_Error( 'internal', 'Meeting timestamps malformed.', array( 'status' => 500 ) );
        }

        if ( $now < ( $starts - self::TIME_GATE_PRE ) ) {
            return new WP_Error(
                'too_early',
                sprintf( 'Meeting starts at %s UTC. Join unlocks 5 minutes before start.', $row->starts_at_utc ),
                array(
                    'status'         => 403,
                    'starts_at_utc'  => $row->starts_at_utc,
                    'now_utc'        => gmdate( 'Y-m-d H:i:s', $now ),
                    'unlocks_at_utc' => gmdate( 'Y-m-d H:i:s', $starts - self::TIME_GATE_PRE ),
                )
            );
        }
        if ( $now > ( $ends + self::TIME_GATE_POST ) ) {
            return new WP_Error(
                'too_late',
                sprintf( 'Meeting ended at %s UTC. Join window closed 30 minutes after end.', $row->ends_at_utc ),
                array(
                    'status'       => 403,
                    'ends_at_utc'  => $row->ends_at_utc,
                )
            );
        }

        // Caller role → moderator iff host (member_id). Guests get moderator=false.
        $is_moderator = ( $caller_id === (int) $row->member_id );

        // Defense-in-depth nbf/exp so prosody mod_token_verification enforces the same gate.
        // The -30 backdates 30 sec to absorb clock skew between WP host and Jitsi pod (both NTP-synced but defensive).
        // max() prevents nbf > now when we're inside the lead-grace window.
        $nbf = max( $now - 30, $starts - self::TIME_GATE_PRE );
        $exp = $ends + self::TIME_GATE_POST;

        $user        = get_userdata( $caller_id );
        $display_name = ( $user && isset( $user->display_name ) && '' !== $user->display_name )
            ? (string) $user->display_name
            : ( 'user-' . $caller_id );

        $jwt = Gend_GS_Jitsi_JWT::mint( $room, $meeting_id, $caller_id, $display_name, $is_moderator, $nbf, $exp );
        if ( is_wp_error( $jwt ) ) {
            return $jwt;   // Bubbles jwt_secret_missing 500 to caller.
        }

        return new WP_REST_Response(
            array(
                'jwt'           => $jwt,
                'room'          => $room,   // RESOLVED meta.jitsi_room (fallback gsmeet-{id}) — matches JWT room claim AND emailed join link.
                'domain'        => self::DOMAIN,
                'expires_at'    => $exp,
                'moderator'     => $is_moderator,
                'starts_at_utc' => $row->starts_at_utc,
                'ends_at_utc'   => $row->ends_at_utc,
            ),
            200
        );
    }

    /**
     * Load meeting row by id via $wpdb->prepare. Returns the row object or null.
     *
     * Static-cached per-request — permission_callback and handle_mint both call this,
     * so we avoid a double query on the same meeting_id within a single request.
     *
     * @param int $meeting_id
     * @return object|null
     */
    private static function load_meeting( $meeting_id ) {
        $meeting_id = (int) $meeting_id;
        if ( array_key_exists( $meeting_id, self::$row_cache ) ) {
            return self::$row_cache[ $meeting_id ];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'gs_member_meetings';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, member_id, guest_user_id, meeting_type, meeting_meta_json, starts_at_utc, ends_at_utc, status
                 FROM {$table} WHERE id = %d LIMIT 1",
                $meeting_id
            )
        );
        self::$row_cache[ $meeting_id ] = $row;
        return $row;
    }
}
