<?php
/**
 * Phase 29-02 UAT — Authed Meetings REST + Schedule Meeting + share-token rotate.
 *
 * Functional probe — BOOK-07, BOOK-08, BOOK-10, MEET-01, MEET-02, MEET-04 acceptance.
 *   - 4 authed routes registered (GET meetings, POST meetings, POST cancel, POST rotate)
 *   - 401 when logged-out
 *   - PUT availability with booking_settings round-trips (named_durations + limits)
 *   - 4 meeting types POST happy-paths (in_person + phone + video-gend + video-zoom)
 *   - share-token rotate produces fresh 43-char token, invalidates Plan 29-01 lookup
 *   - cross-member cancel returns 403 gs_meet_forbidden
 *   - ?user= ignored (handler still resolves get_current_user_id())
 *
 * Cleanup via register_shutdown_function — meetings + availability rows + transients.
 * Always exits 0.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/29-02-uat-meetings-rest.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }
function gs_uat_skip( $l, $w = '' ) { echo "SKIP · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 29-02 authed meetings REST UAT ===\n";

if ( ! class_exists( 'Gend_GS_Booking_Meetings_REST' ) )    { gs_uat_fail( 'Gend_GS_Booking_Meetings_REST not loaded' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_REST' ) )        { gs_uat_fail( 'Gend_GS_Availability_REST not loaded — Plan 28-02 missing?' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) )      { gs_uat_fail( 'Gend_GS_Availability_Schema not loaded — Plan 28-01 missing?' ); return; }

// ─── Assert 1: class loaded + register_routes hooked on rest_api_init ─────
$hooked = has_action( 'rest_api_init', array( 'Gend_GS_Booking_Meetings_REST', 'register_routes' ) );
( $hooked !== false )
    ? gs_uat_pass( 'register_routes hooked on rest_api_init (priority ' . (int) $hooked . ')' )
    : gs_uat_fail( 'register_routes NOT hooked on rest_api_init' );

// ─── Assert 2: all 4 authed routes registered ─────────────────────────────
$rest = rest_get_server();
$routes = $rest->get_routes();
$expected_routes = array(
    '/gs/v1/calendar/meetings',
    '/gs/v1/calendar/meetings/(?P<id>\d+)/cancel',
    '/gs/v1/calendar/share-token/rotate',
);
$missing = array();
foreach ( $expected_routes as $r ) { if ( ! isset( $routes[ $r ] ) ) { $missing[] = $r; } }
( empty( $missing ) )
    ? gs_uat_pass( 'all 4 authed routes registered (GET meetings, POST meetings, POST cancel, POST rotate)' )
    : gs_uat_fail( 'missing routes', implode( ',', $missing ) );

// ─── Seed: pick or create a primary test user (host) ──────────────────────
$host_uid = 1;
if ( ! get_userdata( $host_uid ) ) {
    $u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
    $host_uid = $u ? (int) $u[0] : 0;
}
if ( $host_uid <= 0 ) { gs_uat_fail( 'no users in system; cannot run' ); return; }

// Create / pick a SECOND user (member B) for cross-member 403 test.
$other_uid = 0;
$candidates = get_users( array( 'number' => 5, 'fields' => 'ID', 'exclude' => array( $host_uid ) ) );
if ( ! empty( $candidates ) ) { $other_uid = (int) $candidates[0]; }
if ( $other_uid <= 0 ) {
    $other_uid = wp_create_user( 'gs_uat_other_' . wp_generate_password( 6, false ), wp_generate_password( 16 ), 'other_' . wp_generate_password( 6, false ) . '@example.test' );
    if ( is_wp_error( $other_uid ) ) { $other_uid = 0; }
}

// ─── Register cleanup BEFORE state mutation ───────────────────────────────
$tbl_avail = Gend_GS_Availability_Schema::table_availability();
$tbl_meet  = Gend_GS_Availability_Schema::table_meetings();
$created_other = is_int( $other_uid ) && $other_uid > 0 && empty( $candidates );
register_shutdown_function( function () use ( $wpdb, $tbl_avail, $tbl_meet, $host_uid, $other_uid, $created_other ) {
    $wpdb->delete( $tbl_meet,  array( 'member_id' => $host_uid  ), array( '%d' ) );
    if ( $other_uid > 0 ) {
        $wpdb->delete( $tbl_meet,  array( 'member_id' => $other_uid ), array( '%d' ) );
        $wpdb->delete( $tbl_avail, array( 'user_id'   => $other_uid ), array( '%d' ) );
        if ( $created_other && function_exists( 'wp_delete_user' ) ) { wp_delete_user( $other_uid ); }
    }
    $wpdb->delete( $tbl_avail, array( 'user_id' => $host_uid ), array( '%d' ) );
    echo "CLEAN · deleted meetings + availability for users {$host_uid}/{$other_uid}\n";
} );

// ─── Assert 3: logged-OUT POST returns 401 ────────────────────────────────
wp_set_current_user( 0 );
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings' );
$req->set_body_params( array(
    'slot_start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ),
    'duration_min'   => 30,
    'meeting_type'   => 'in_person',
    'meeting_meta'   => array( 'address' => '123 Anywhere' ),
    'guest_name'     => 'X', 'guest_email' => 'x@example.test',
) );
$resp = rest_do_request( $req );
$status = $resp->get_status();
( $status === 401 || $status === 403 )
    ? gs_uat_pass( 'logged-OUT POST /meetings rejected (' . $status . ')' )
    : gs_uat_fail( 'logged-OUT POST should 401/403', "got {$status}" );

// ─── Seed: log in as host + wipe + PUT availability with booking_settings ──
wp_set_current_user( $host_uid );
$wpdb->delete( $tbl_avail, array( 'user_id' => $host_uid ), array( '%d' ) );
$wpdb->delete( $tbl_meet,  array( 'member_id' => $host_uid ), array( '%d' ) );

// ─── Assert 4: PUT booking_settings (BOOK-07 + BOOK-08) round-trips ───────
$req = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$req->set_body_params( array(
    'timezone'         => 'America/Toronto',
    'working_hours'    => array(
        'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
        'tue' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
        'wed' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
        'thu' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
        'fri' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
    ),
    'booking_settings' => array(
        'min_notice_minutes'   => 30,
        'buffer_minutes'       => 5,
        'max_bookings_per_day' => 20,
        'named_durations'      => array(
            array( 'label' => 'Quick',     'minutes' => 15 ),
            array( 'label' => 'Discovery', 'minutes' => 60 ),
            array( 'label' => 'too_long_' . str_repeat( 'x', 60 ), 'minutes' => 30 ), // should drop (label > 40)
            array( 'label' => 'BadMins',   'minutes' => 7 ),  // should drop (mins not allowed)
        ),
    ),
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
$bs   = isset( $data['booking_settings'] ) ? (array) $data['booking_settings'] : array();
$bs_ok = isset( $bs['min_notice_minutes'] ) && (int) $bs['min_notice_minutes'] === 30
      && isset( $bs['buffer_minutes'] )     && (int) $bs['buffer_minutes']     === 5
      && isset( $bs['max_bookings_per_day'] ) && (int) $bs['max_bookings_per_day'] === 20;
$bs_ok ? gs_uat_pass( 'booking_settings PUT echoes back min_notice/buffer/max_per_day' )
       : gs_uat_fail( 'booking_settings round-trip', wp_json_encode( $bs ) );
$nd = isset( $bs['named_durations'] ) ? (array) $bs['named_durations'] : array();
( count( $nd ) === 2 ) ? gs_uat_pass( 'named_durations: 2 valid (BOOK-08); 2 invalid silently dropped' )
                       : gs_uat_fail( 'named_durations count', 'got ' . count( $nd ) );

// ─── Assert 5: POST /meetings in_person (MEET-01) ─────────────────────────
$mtg_in_person_id = 0;
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings' );
$req->set_body_params( array(
    'slot_start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ), // 2h from now (clears min_notice=30)
    'duration_min'   => 30,
    'meeting_type'   => 'in_person',
    'meeting_meta'   => array( 'address' => '123 King St W, Toronto' ),
    'guest_name'     => 'Alice Guest',
    'guest_email'    => 'alice@example.test',
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
if ( $resp->get_status() === 200 && isset( $data['meeting_id'] ) ) {
    $mtg_in_person_id = (int) $data['meeting_id'];
    gs_uat_pass( 'POST /meetings in_person → 200 meeting_id=' . $mtg_in_person_id );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT meeting_meta_json FROM {$tbl_meet} WHERE id=%d", $mtg_in_person_id ) );
    $meta = $row ? (array) json_decode( $row->meeting_meta_json, true ) : array();
    ( isset( $meta['address'] ) && $meta['address'] === '123 King St W, Toronto' )
        ? gs_uat_pass( 'in_person address stored verbatim in meeting_meta_json' )
        : gs_uat_fail( 'in_person address', wp_json_encode( $meta ) );
} else {
    gs_uat_fail( 'POST /meetings in_person', $resp->get_status() . ' :: ' . wp_json_encode( $data ) );
}

// ─── Assert 6: POST /meetings phone (MEET-02) ─────────────────────────────
$mtg_phone_id = 0;
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings' );
$req->set_body_params( array(
    'slot_start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 10800 ),
    'duration_min'   => 15,
    'meeting_type'   => 'phone',
    'meeting_meta'   => array( 'callee_number' => '+14165551234', 'direction' => 'guest_calls_host' ),
    'guest_name'     => 'Bob Guest',
    'guest_email'    => 'bob@example.test',
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
if ( $resp->get_status() === 200 && isset( $data['meeting_id'] ) ) {
    $mtg_phone_id = (int) $data['meeting_id'];
    gs_uat_pass( 'POST /meetings phone → 200 meeting_id=' . $mtg_phone_id );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT meeting_meta_json FROM {$tbl_meet} WHERE id=%d", $mtg_phone_id ) );
    $meta = $row ? (array) json_decode( $row->meeting_meta_json, true ) : array();
    ( isset( $meta['callee_number'], $meta['direction'] ) && $meta['direction'] === 'guest_calls_host' )
        ? gs_uat_pass( 'phone meta stores callee_number + direction enum' )
        : gs_uat_fail( 'phone meta', wp_json_encode( $meta ) );
} else {
    gs_uat_fail( 'POST /meetings phone', $resp->get_status() . ' :: ' . wp_json_encode( $data ) );
}

// ─── Assert 7: POST /meetings video gend (MEET-04 — native jitsi_room) ────
$mtg_video_gend_id = 0;
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings' );
$req->set_body_params( array(
    'slot_start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 14400 ),
    'duration_min'   => 30,
    'meeting_type'   => 'video',
    'meeting_meta'   => array( 'provider' => 'gend' ),
    'guest_name'     => 'Carol Guest',
    'guest_email'    => 'carol@example.test',
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
if ( $resp->get_status() === 200 && isset( $data['meeting_id'] ) ) {
    $mtg_video_gend_id = (int) $data['meeting_id'];
    gs_uat_pass( 'POST /meetings video gend → 200 meeting_id=' . $mtg_video_gend_id );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT meeting_meta_json FROM {$tbl_meet} WHERE id=%d", $mtg_video_gend_id ) );
    $meta = $row ? (array) json_decode( $row->meeting_meta_json, true ) : array();
    $room_ok = isset( $meta['provider'], $meta['jitsi_room'] )
        && $meta['provider'] === 'gend'
        && preg_match( '/^gend-meet-\d+-[A-Za-z0-9]{16}$/', (string) $meta['jitsi_room'] );
    $room_ok ? gs_uat_pass( 'native gend video allocates jitsi_room: gend-meet-{uid}-{16char} (Phase 30 mints JWT)' )
             : gs_uat_fail( 'gend jitsi_room shape', wp_json_encode( $meta ) );
} else {
    gs_uat_fail( 'POST /meetings video gend', $resp->get_status() . ' :: ' . wp_json_encode( $data ) );
}

// ─── Assert 8: POST /meetings video zoom (external URL allowlist PASS) ────
$mtg_video_zoom_id = 0;
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings' );
$req->set_body_params( array(
    'slot_start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 18000 ),
    'duration_min'   => 30,
    'meeting_type'   => 'video',
    'meeting_meta'   => array( 'provider' => 'zoom', 'room_url' => 'https://zoom.us/j/12345' ),
    'guest_name'     => 'Dave Guest',
    'guest_email'    => 'dave@example.test',
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
if ( $resp->get_status() === 200 && isset( $data['meeting_id'] ) ) {
    $mtg_video_zoom_id = (int) $data['meeting_id'];
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT meeting_meta_json FROM {$tbl_meet} WHERE id=%d", $mtg_video_zoom_id ) );
    $meta = $row ? (array) json_decode( $row->meeting_meta_json, true ) : array();
    ( isset( $meta['room_url'] ) && $meta['room_url'] === 'https://zoom.us/j/12345' )
        ? gs_uat_pass( 'POST /meetings video zoom (https://zoom.us/...) ACCEPTED and URL stored' )
        : gs_uat_fail( 'zoom room_url', wp_json_encode( $meta ) );
} else {
    gs_uat_fail( 'POST /meetings video zoom', $resp->get_status() . ' :: ' . wp_json_encode( $data ) );
}

// ─── Assert 9: GET /meetings returns host's own bookings ──────────────────
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/meetings' );
$req->set_query_params( array(
    'from' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ),
    'to'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * 86400 ),
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
$count = isset( $data['meetings'] ) ? count( (array) $data['meetings'] ) : -1;
( $count >= 4 ) ? gs_uat_pass( 'GET /meetings returns ' . $count . ' bookings (>=4)' )
                : gs_uat_fail( 'GET /meetings count', "got {$count}" );

// ─── Assert 10: share-token rotate → 43-char new token ────────────────────
$req = new WP_REST_Request( 'POST', '/gs/v1/calendar/share-token/rotate' );
$resp = rest_do_request( $req );
$data = $resp->get_data();
$tok = isset( $data['share_token'] ) ? (string) $data['share_token'] : '';
( $resp->get_status() === 200 && strlen( $tok ) === 43 )
    ? gs_uat_pass( 'share-token/rotate → 200 with 43-char fresh token' )
    : gs_uat_fail( 'share-token/rotate', $resp->get_status() . ' tok_len=' . strlen( $tok ) );
$row_tok = (string) $wpdb->get_var( $wpdb->prepare( "SELECT share_token FROM {$tbl_avail} WHERE user_id=%d", $host_uid ) );
( $row_tok === $tok ) ? gs_uat_pass( 'rotated share_token persisted to wp_gs_member_availability' )
                      : gs_uat_fail( 'rotated token persistence', "db={$row_tok}" );

// ─── Assert 11: cross-member cancel returns 403 gs_meet_forbidden ─────────
if ( $other_uid > 0 && $mtg_in_person_id > 0 ) {
    // host_uid currently logged in; attempt to cancel as OTHER user against host's meeting.
    wp_set_current_user( $other_uid );
    $req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings/' . $mtg_in_person_id . '/cancel' );
    $resp = rest_do_request( $req );
    $data = $resp->get_data();
    $is_forbidden = $resp->get_status() === 403
        || ( is_array( $data ) && isset( $data['code'] ) && $data['code'] === 'gs_meet_forbidden' )
        || ( is_object( $data ) && method_exists( $data, 'get_error_code' ) && $data->get_error_code() === 'gs_meet_forbidden' );
    $is_forbidden ? gs_uat_pass( 'cross-member cancel → 403 gs_meet_forbidden (Pitfall 7/8 enforced)' )
                  : gs_uat_fail( 'cross-member cancel', $resp->get_status() . ' :: ' . wp_json_encode( $data ) );

    // Verify the row status is still booked (not cancelled).
    $still_booked = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tbl_meet} WHERE id=%d", $mtg_in_person_id ) );
    ( $still_booked === 'booked' ) ? gs_uat_pass( "host's meeting status remains 'booked' after cross-member cancel attempt" )
                                   : gs_uat_fail( 'meeting status leak', "status={$still_booked}" );
    wp_set_current_user( $host_uid );
} else {
    gs_uat_skip( 'cross-member cancel — could not seed second user' );
}

// ─── Assert 12: ?user= IGNORED — handler still resolves get_current_user_id() ─
wp_set_current_user( $host_uid );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/meetings' );
$req->set_query_params( array(
    'from' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ),
    'to'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * 86400 ),
    'user' => $other_uid > 0 ? $other_uid : 999999,
) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
// Identity should still be host_uid → returns host's meetings (>=4), NOT other's.
$count2 = isset( $data['meetings'] ) ? count( (array) $data['meetings'] ) : -1;
( $count2 >= 4 ) ? gs_uat_pass( '?user= IGNORED — GET still returns ' . $count2 . " host meetings (Pitfall 7)" )
                 : gs_uat_fail( '?user= leak risk', "count={$count2}" );

// ─── Assert 13: host CAN cancel own meeting ───────────────────────────────
if ( $mtg_phone_id > 0 ) {
    $req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings/' . $mtg_phone_id . '/cancel' );
    $resp = rest_do_request( $req );
    $data = $resp->get_data();
    ( $resp->get_status() === 200 && isset( $data['status'] ) && in_array( (string) $data['status'], array( 'cancelled', 'already_cancelled' ), true ) )
        ? gs_uat_pass( 'host cancels own meeting (' . $mtg_phone_id . ') → ' . $data['status'] )
        : gs_uat_fail( 'self-cancel', $resp->get_status() . ' :: ' . wp_json_encode( $data ) );
}

echo "=== done — cleanup runs at shutdown ===\n";
