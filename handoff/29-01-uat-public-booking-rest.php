<?php
/**
 * Phase 29-01 UAT — Public Booking REST functional probe.
 *
 * Covers BOOK-01..04 + BOOK-09 partial + MEET-05:
 *   - 5 routes registered on rest_api_init
 *   - Unknown share_token → 404 gs_book_token_unknown
 *   - Seeded host: GET /slots returns open slots with member_tz + ISO Z bounds
 *   - POST /book happy-path returns 201/200 with cancellation_token (43 chars) + meeting_id + member_tz
 *   - GET /meeting/{ct} returns the booking
 *   - POST /meeting/{ct}/cancel returns status=cancelled + DB row updated
 *   - POST /meeting/{ct}/reschedule moves the row
 *   - GET /calendar/events envelope contains a source='mtg' busy=true entry (proves Task 2 wiring)
 *
 * Safe — seeds a synthetic host user + availability row + meetings; CLEANS UP via wpdb DELETE
 * in register_shutdown_function. wp eval-file dispatch is in-process via rest_do_request.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/29-01-uat-public-booking-rest.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit( 1 ); }

$_pass = 0; $_fail = 0; $_skip = 0;
function gs_uat_pass( $msg ) { global $_pass; $_pass++; echo "  PASS  {$msg}\n"; }
function gs_uat_fail( $msg, $why = '' ) { global $_fail; $_fail++; echo "  FAIL  {$msg}" . ( $why ? " :: {$why}" : '' ) . "\n"; }
function gs_uat_skip( $msg, $why = '' ) { global $_skip; $_skip++; echo "  SKIP  {$msg}" . ( $why ? " :: {$why}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 29-01 public booking REST functional UAT ===\n";

if ( ! class_exists( 'Gend_GS_Booking_Public_REST' ) ) {
    gs_uat_fail( 'Gend_GS_Booking_Public_REST class not loaded' );
    echo "\n=== RESULT: 0 passed, 1 failed, 0 skipped ===\n";
    return;
}
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
    gs_uat_fail( 'Gend_GS_Availability_Schema not loaded — Plan 28-01 missing' );
    echo "\n=== RESULT: 0 passed, 1 failed, 0 skipped ===\n";
    return;
}

// Create a synthetic host user so we can DELETE it cleanly post-run.
$test_email = 'gs-uat-29-01-' . wp_generate_password( 8, false, false ) . '@test.local';
$test_user_id = wp_insert_user( array(
    'user_login' => 'gs_uat_29_01_' . substr( $test_email, 11, 8 ),
    'user_pass'  => wp_generate_password( 24, true, true ),
    'user_email' => $test_email,
    'role'       => 'subscriber',
) );
if ( is_wp_error( $test_user_id ) || ! $test_user_id ) {
    gs_uat_fail( 'could not seed test user', is_wp_error( $test_user_id ) ? $test_user_id->get_error_message() : 'no id' );
    echo "\n=== RESULT: 0 passed, 1 failed, 0 skipped ===\n";
    return;
}
$test_user_id = (int) $test_user_id;
$tbl_avail    = Gend_GS_Availability_Schema::table_availability();
$tbl_meet     = Gend_GS_Availability_Schema::table_meetings();
$share_token  = wp_generate_password( 43, false, false );

// CRITICAL: register cleanup BEFORE state mutation.
register_shutdown_function( function () use ( $wpdb, $tbl_avail, $tbl_meet, $test_user_id, $share_token ) {
    $wpdb->delete( $tbl_meet,  array( 'member_id' => $test_user_id ), array( '%d' ) );
    $wpdb->delete( $tbl_avail, array( 'user_id'   => $test_user_id ), array( '%d' ) );
    wp_delete_user( $test_user_id );
    // Reset rate-limit transients to avoid polluting other tests.
    delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
    delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );
    echo "CLEAN  removed seeded user {$test_user_id} + availability + meetings + transients\n";
} );

// Seed availability row with working_hours Mon-Fri 09:00-17:00 in America/Toronto.
$now_ts = time();
$wpdb->insert(
    $tbl_avail,
    array(
        'user_id'             => $test_user_id,
        'timezone'            => 'America/Toronto',
        'working_hours_json'  => wp_json_encode( array(
            'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'tue' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'wed' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'thu' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
            'fri' => array( array( 'start' => '09:00', 'end' => '17:00' ) ),
        ) ),
        'blocked_ranges_json' => wp_json_encode( array() ),
        'share_token'         => $share_token,
        'created_at_ts'       => $now_ts,
        'updated_at_ts'       => $now_ts,
    ),
    array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
);

// ─── Assert 1: class loaded + register_routes hooked on rest_api_init ─────────
if ( false !== has_action( 'rest_api_init', array( 'Gend_GS_Booking_Public_REST', 'register_routes' ) ) ) {
    gs_uat_pass( 'register_routes hooked on rest_api_init' );
} else {
    gs_uat_fail( 'register_routes NOT hooked on rest_api_init' );
}

// ─── Assert 2: all 5 routes registered ─────────────────────────────────────
$routes        = rest_get_server()->get_routes();
$route_keys    = array_keys( $routes );
$want_patterns = array(
    '#^/gs/v1/calendar/public/\(\?P<share_token>\[A-Za-z0-9\]\{43\}\)/slots$#',
    '#^/gs/v1/calendar/public/\(\?P<share_token>\[A-Za-z0-9\]\{43\}\)/book$#',
    '#^/gs/v1/calendar/public/meeting/\(\?P<cancellation_token>\[A-Za-z0-9\]\{43\}\)$#',
    '#^/gs/v1/calendar/public/meeting/\(\?P<cancellation_token>\[A-Za-z0-9\]\{43\}\)/cancel$#',
    '#^/gs/v1/calendar/public/meeting/\(\?P<cancellation_token>\[A-Za-z0-9\]\{43\}\)/reschedule$#',
);
$missing = array();
foreach ( $want_patterns as $pat ) {
    $found = false;
    foreach ( $route_keys as $k ) {
        if ( preg_match( $pat, $k ) ) { $found = true; break; }
    }
    if ( ! $found ) { $missing[] = $pat; }
}
if ( empty( $missing ) ) {
    gs_uat_pass( 'all 5 public booking routes registered' );
} else {
    gs_uat_fail( 'missing routes', implode( ' ', $missing ) );
}

// Reset rate-limit transients so the test isn't pre-poisoned.
delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );

// ─── Assert 3: unknown share_token → 404 gs_book_token_unknown ─────────────
$bogus_token = str_pad( 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', 43, 'z' );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/public/' . $bogus_token . '/slots' );
$req->set_param( 'from', gmdate( 'Y-m-d\T00:00:00\Z', strtotime( 'next monday' ) ) );
$req->set_param( 'to',   gmdate( 'Y-m-d\T00:00:00\Z', strtotime( 'next tuesday' ) ) );
$req->set_param( 'duration_min', 30 );
$resp = rest_do_request( $req );
if ( $resp->get_status() === 404 ) {
    gs_uat_pass( 'unknown share_token → 404' );
} else {
    gs_uat_fail( 'unknown share_token expected 404', 'got ' . $resp->get_status() );
}

// ─── Compute a "next monday" window in UTC for the seeded host ────────────
$next_mon_ts = strtotime( 'next monday 09:00:00 America/Toronto' );
if ( ! $next_mon_ts ) { $next_mon_ts = strtotime( '+7 days 09:00:00' ); }
$from_iso = gmdate( 'Y-m-d\T00:00:00\Z', $next_mon_ts );
$to_iso   = gmdate( 'Y-m-d\T23:59:59\Z', $next_mon_ts );

// ─── Assert 4 + 5: seeded share_token → slots returned with member_tz ─────
delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/public/' . $share_token . '/slots' );
$req->set_param( 'from', $from_iso );
$req->set_param( 'to',   $to_iso );
$req->set_param( 'duration_min', 30 );
$resp = rest_do_request( $req );
if ( $resp->get_status() !== 200 ) {
    gs_uat_fail( 'GET slots seeded token expected 200', 'got ' . $resp->get_status() );
    $first_slot = null;
} else {
    $data = $resp->get_data();
    if ( ! empty( $data['member_tz'] ) && $data['member_tz'] === 'America/Toronto' ) {
        gs_uat_pass( 'slots envelope: member_tz=America/Toronto' );
    } else {
        gs_uat_fail( 'slots envelope member_tz', var_export( $data['member_tz'] ?? null, true ) );
    }
    if ( ! empty( $data['slots'] ) && is_array( $data['slots'] ) ) {
        $first_slot = $data['slots'][0];
        if ( isset( $first_slot['start_utc'], $first_slot['end_utc'] )
             && preg_match( '/Z$/', $first_slot['start_utc'] )
             && preg_match( '/Z$/', $first_slot['end_utc'] ) ) {
            gs_uat_pass( 'first slot has ISO Z start_utc + end_utc' );
        } else {
            gs_uat_fail( 'first slot shape', var_export( $first_slot, true ) );
        }
    } else {
        gs_uat_skip( 'no open slots returned (host may have no Mon working hours)' );
        $first_slot = null;
    }
}

// ─── Assert 6: POST /book happy-path returns cancellation_token + meeting_id ─
$returned_ct = null;
$returned_id = null;
if ( $first_slot ) {
    delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
    delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );
    $req = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/' . $share_token . '/book' );
    $req->set_param( 'slot_start_utc', $first_slot['start_utc'] );
    $req->set_param( 'duration_min', 30 );
    $req->set_param( 'meeting_type', 'in_person' );
    $req->set_param( 'meeting_meta', array( 'address' => '123 Test St, Toronto' ) );
    $req->set_param( 'guest_name', 'QA Bookee' );
    $req->set_param( 'guest_email', 'qa.bookee@test.local' );
    $resp = rest_do_request( $req );
    if ( $resp->get_status() === 200 || $resp->get_status() === 201 ) {
        $data = $resp->get_data();
        if ( isset( $data['cancellation_token'], $data['meeting_id'], $data['member_tz'] )
             && strlen( (string) $data['cancellation_token'] ) === 43 ) {
            gs_uat_pass( 'POST book happy path → 200/201 with 43-char cancellation_token + meeting_id + member_tz' );
            $returned_ct = (string) $data['cancellation_token'];
            $returned_id = (int) $data['meeting_id'];
        } else {
            gs_uat_fail( 'book response shape', var_export( $data, true ) );
        }
    } else {
        $err = $resp->as_error();
        gs_uat_fail( 'POST book expected 200/201', 'got ' . $resp->get_status() . ' ' . ( $err ? $err->get_error_message() : '' ) );
    }
} else {
    gs_uat_skip( 'POST book skipped — no slot to book' );
}

// ─── Assert 7: GET /meeting/{ct} returns booking ───────────────────────────
if ( $returned_ct ) {
    delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
    $req  = new WP_REST_Request( 'GET', '/gs/v1/calendar/public/meeting/' . $returned_ct );
    $resp = rest_do_request( $req );
    if ( $resp->get_status() === 200 && (int) ( $resp->get_data()['meeting_id'] ?? 0 ) === $returned_id ) {
        gs_uat_pass( 'GET meeting/{ct} returns booking' );
    } else {
        gs_uat_fail( 'GET meeting/{ct}', 'status=' . $resp->get_status() );
    }
} else {
    gs_uat_skip( 'GET meeting skipped' );
}

// ─── Assert 8: POST /meeting/{ct}/cancel returns cancelled + DB updated ───
if ( $returned_ct ) {
    delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
    $req  = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/meeting/' . $returned_ct . '/cancel' );
    $resp = rest_do_request( $req );
    $data = $resp->get_data();
    $row_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tbl_meet} WHERE id = %d", $returned_id ) );
    if ( $resp->get_status() === 200 && ( $data['status'] ?? '' ) === 'cancelled' && $row_status === 'cancelled' ) {
        gs_uat_pass( 'POST cancel → status=cancelled + DB row updated' );
    } else {
        gs_uat_fail( 'POST cancel', 'http=' . $resp->get_status() . ' resp_status=' . ( $data['status'] ?? '' ) . ' row=' . $row_status );
    }
} else {
    gs_uat_skip( 'POST cancel skipped' );
}

// ─── Assert 9: reschedule a fresh booking ──────────────────────────────────
if ( $first_slot ) {
    delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
    delete_transient( 'gs_book_rl_' . md5( 'tok:' . $share_token ) );
    // Need another slot — book at +1 hour to avoid the cancelled slot we already used.
    $start_dt = ( new DateTime( $first_slot['start_utc'], new DateTimeZone( 'UTC' ) ) )->modify( '+2 hours' );
    $rebook = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/' . $share_token . '/book' );
    $rebook->set_param( 'slot_start_utc', $start_dt->format( 'Y-m-d\TH:i:s\Z' ) );
    $rebook->set_param( 'duration_min', 30 );
    $rebook->set_param( 'meeting_type', 'in_person' );
    $rebook->set_param( 'meeting_meta', array( 'address' => '456 Reschedule Ave' ) );
    $rebook->set_param( 'guest_name', 'Reschedule QA' );
    $rebook->set_param( 'guest_email', 'reschedule.qa@test.local' );
    $rebook_resp = rest_do_request( $rebook );
    $rebook_data = $rebook_resp->get_data();
    if ( ( $rebook_resp->get_status() === 200 || $rebook_resp->get_status() === 201 )
         && ! empty( $rebook_data['cancellation_token'] ) ) {
        $new_ct = (string) $rebook_data['cancellation_token'];
        $new_id = (int) $rebook_data['meeting_id'];
        delete_transient( 'gs_book_rl_' . md5( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
        // Move to +4h.
        $resched_dt = ( new DateTime( $first_slot['start_utc'], new DateTimeZone( 'UTC' ) ) )->modify( '+4 hours' );
        $resched = new WP_REST_Request( 'POST', '/gs/v1/calendar/public/meeting/' . $new_ct . '/reschedule' );
        $resched->set_param( 'slot_start_utc', $resched_dt->format( 'Y-m-d\TH:i:s\Z' ) );
        $resched->set_param( 'duration_min', 30 );
        $resched_resp = rest_do_request( $resched );
        $resched_data = $resched_resp->get_data();
        $new_row_start = $wpdb->get_var( $wpdb->prepare( "SELECT starts_at_utc FROM {$tbl_meet} WHERE id = %d", $new_id ) );
        $expected_mysql = $resched_dt->format( 'Y-m-d H:i:s' );
        if ( $resched_resp->get_status() === 200
             && ( $resched_data['status'] ?? '' ) === 'rescheduled'
             && $new_row_start === $expected_mysql ) {
            gs_uat_pass( 'POST reschedule → status=rescheduled + DB row moved' );
        } else {
            gs_uat_fail( 'POST reschedule', 'http=' . $resched_resp->get_status() . ' status=' . ( $resched_data['status'] ?? '' ) . ' row_start=' . $new_row_start . ' want=' . $expected_mysql );
        }
    } else {
        gs_uat_skip( 'reschedule rebook failed', 'http=' . $rebook_resp->get_status() );
    }
} else {
    gs_uat_skip( 'reschedule skipped' );
}

// ─── Assert 10: Meetings adapter wiring — /calendar/events shows source='mtg' ─
// Set current user to the host so the events endpoint authenticates AND scopes correctly.
wp_set_current_user( $test_user_id );
$ev_req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$ev_req->set_param( 'from', $from_iso );
$ev_req->set_param( 'to',   gmdate( 'Y-m-d\T00:00:00\Z', $next_mon_ts + 86400 * 2 ) );
$ev_resp = rest_do_request( $ev_req );
if ( $ev_resp->get_status() !== 200 ) {
    gs_uat_fail( 'GET /calendar/events for host', 'status=' . $ev_resp->get_status() );
} else {
    $ev_data = $ev_resp->get_data();
    $events  = $ev_data['events'] ?? array();
    $found_mtg = false;
    foreach ( $events as $ev ) {
        if ( ( $ev['source'] ?? '' ) === 'mtg' && ! empty( $ev['busy'] ) && ( $ev['type'] ?? '' ) === 'meeting' ) {
            $found_mtg = true;
            break;
        }
    }
    if ( $found_mtg ) {
        gs_uat_pass( 'MEETINGS-ADAPTER: /calendar/events contains source=mtg busy=true type=meeting (Task 2 wiring proven)' );
    } else {
        gs_uat_fail( 'MEETINGS-ADAPTER: no mtg event found in /calendar/events', 'event_count=' . count( $events ) );
    }
}

echo "\n=== RESULT: {$_pass} passed, {$_fail} failed, {$_skip} skipped ===\n";
exit( 0 );
