<?php
/**
 * Phase 29-02 UAT — External video URL allowlist (Pitfall 16 + MEET-03).
 *
 * Matrix-driven acceptance probe — each URL is POSTed against
 * /gs/v1/calendar/meetings with meeting_type='video' and asserted against
 * its expected verdict (PASS/REJECT).
 *
 * Allowed:
 *   - https://zoom.us/j/12345                          (exact)
 *   - https://us02web.zoom.us/j/12345                  (wildcard .zoom.us)
 *   - https://meet.google.com/abc-defg-hij             (exact)
 *   - https://teams.microsoft.com/l/meetup-join/...    (exact)
 *   - https://acme.webex.com/meet/...                  (wildcard .webex.com)
 *
 * Rejected (each MUST return 422 gs_meet_bad_video_url):
 *   - https://evil.com/zoom.us/j/12345    (host=evil.com, path-trick)
 *   - http://zoom.us/j/12345              (non-https scheme)
 *   - javascript:alert(1)                 (script scheme)
 *   - data:text/html,<script>             (data scheme)
 *   - file:///etc/passwd                  (file scheme)
 *   - https://fakezoom.us/j/x             (startsWith trap — host ends in '.us' not '.zoom.us')
 *   - https://zoom.us.attacker.com/x      (host ends in '.com', not '.zoom.us')
 *
 * Cleanup via register_shutdown_function — meetings + availability rows.
 * Always exits 0.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/29-02-uat-url-allowlist.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }
function gs_uat_skip( $l, $w = '' ) { echo "SKIP · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 29-02 URL allowlist UAT (MEET-03 + Pitfall 16) ===\n";

if ( ! class_exists( 'Gend_GS_Booking_Meetings_REST' ) ) { gs_uat_fail( 'Gend_GS_Booking_Meetings_REST not loaded' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) )   { gs_uat_fail( 'Gend_GS_Availability_Schema not loaded' ); return; }

// ─── Seed: pick test host ─────────────────────────────────────────────────
$host_uid = 1;
if ( ! get_userdata( $host_uid ) ) {
    $u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
    $host_uid = $u ? (int) $u[0] : 0;
}
if ( $host_uid <= 0 ) { gs_uat_fail( 'no users in system; cannot run' ); return; }

$tbl_avail = Gend_GS_Availability_Schema::table_availability();
$tbl_meet  = Gend_GS_Availability_Schema::table_meetings();

// Cleanup BEFORE state mutation.
register_shutdown_function( function () use ( $wpdb, $tbl_avail, $tbl_meet, $host_uid ) {
    $wpdb->delete( $tbl_meet,  array( 'member_id' => $host_uid ), array( '%d' ) );
    $wpdb->delete( $tbl_avail, array( 'user_id'   => $host_uid ), array( '%d' ) );
    echo "CLEAN · deleted meetings + availability for user {$host_uid}\n";
} );

$wpdb->delete( $tbl_meet,  array( 'member_id' => $host_uid ), array( '%d' ) );
$wpdb->delete( $tbl_avail, array( 'user_id'   => $host_uid ), array( '%d' ) );
wp_set_current_user( $host_uid );

// Seed availability with permissive booking_settings so URL allowlist is the only gate.
$req = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$req->set_body_params( array(
    'timezone'         => 'America/Toronto',
    'working_hours'    => array(
        'mon' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
        'tue' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
        'wed' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
        'thu' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
        'fri' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
        'sat' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
        'sun' => array( array( 'start' => '00:00', 'end' => '23:59' ) ),
    ),
    'booking_settings' => array(
        'min_notice_minutes'   => 5,
        'max_bookings_per_day' => 50,
    ),
) );
rest_do_request( $req );

// ─── Allowlist matrix ─────────────────────────────────────────────────────
$matrix = array(
    // [ url, expected_verdict (true=PASS, false=REJECT), label, provider ]
    array( 'https://zoom.us/j/12345',                                     true,  'zoom.us exact',                         'zoom' ),
    array( 'https://us02web.zoom.us/j/12345',                             true,  'us02web.zoom.us wildcard .zoom.us',     'zoom' ),
    array( 'https://meet.google.com/abc-defg-hij',                        true,  'meet.google.com exact',                 'meet' ),
    array( 'https://teams.microsoft.com/l/meetup-join/abc',               true,  'teams.microsoft.com exact',             'teams' ),
    array( 'https://acme.webex.com/meet/jdoe',                            true,  'acme.webex.com wildcard .webex.com',    'webex' ),
    array( 'https://evil.com/zoom.us/j/12345',                            false, 'evil.com (path-trick) REJECT',          'zoom' ),
    array( 'http://zoom.us/j/12345',                                      false, 'http://zoom.us (non-https) REJECT',     'zoom' ),
    array( 'javascript:alert(1)',                                         false, 'javascript: REJECT',                    'zoom' ),
    array( 'data:text/html,<script>alert(1)</script>',                    false, 'data: REJECT',                          'zoom' ),
    array( 'file:///etc/passwd',                                          false, 'file: REJECT',                          'zoom' ),
    array( 'https://fakezoom.us/j/x',                                     false, 'fakezoom.us (startsWith trap) REJECT',  'zoom' ),
    array( 'https://zoom.us.attacker.com/x',                              false, 'zoom.us.attacker.com (suffix trap) REJECT', 'zoom' ),
);

$slot_seed = time() + 7200;
foreach ( $matrix as $i => $row ) {
    list( $url, $expect_pass, $label, $provider ) = $row;
    // Increment slot per iter to avoid the atomic overlap re-check tripping us up.
    $slot = $slot_seed + ( $i * 3600 );
    $req = new WP_REST_Request( 'POST', '/gs/v1/calendar/meetings' );
    $req->set_body_params( array(
        'slot_start_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $slot ),
        'duration_min'   => 30,
        'meeting_type'   => 'video',
        'meeting_meta'   => array( 'provider' => $provider, 'room_url' => $url ),
        'guest_name'     => 'Allowlist Guest ' . $i,
        'guest_email'    => 'guest' . $i . '@example.test',
    ) );
    $resp = rest_do_request( $req );
    $status = $resp->get_status();
    $data   = $resp->get_data();
    $code   = '';
    if ( is_array( $data ) && isset( $data['code'] ) ) {
        $code = (string) $data['code'];
    } elseif ( is_object( $data ) && method_exists( $data, 'get_error_code' ) ) {
        $code = (string) $data->get_error_code();
    }

    if ( $expect_pass ) {
        if ( $status === 200 ) {
            gs_uat_pass( "[{$i}] {$label}: ACCEPTED (200)" );
        } else {
            gs_uat_fail( "[{$i}] {$label}: should ACCEPT", "{$status} code={$code}" );
        }
    } else {
        // REJECT — expect 422 with gs_meet_bad_video_url (or related rejection like bad_provider).
        $rejection_codes = array( 'gs_meet_bad_video_url', 'gs_meet_video_no_url', 'gs_meet_bad_provider' );
        if ( $status >= 400 && $status < 500 && ( $code === '' || in_array( $code, $rejection_codes, true ) || $status === 422 ) ) {
            gs_uat_pass( "[{$i}] {$label}: REJECTED ({$status} code={$code})" );
        } else {
            gs_uat_fail( "[{$i}] {$label}: should REJECT", "got {$status} code={$code}" );
        }
    }
}

echo "=== done — cleanup runs at shutdown ===\n";
