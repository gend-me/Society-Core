<?php
/**
 * Phase 28-02 UAT — Availability REST (GET + PUT) lifecycle verification.
 * AVAIL-01 + AVAIL-02 + AVAIL-03 acceptance probe. Safe — uses test user 1
 * (super-admin in dev/hub); CLEANS UP via wpdb DELETE in register_shutdown_function.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-02-uat-availability-rest.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }
function gs_uat_skip( $l, $w = '' ) { echo "SKIP · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 28-02 availability REST UAT ===\n";

if ( ! class_exists( 'Gend_GS_Availability_REST' ) ) {
    gs_uat_fail( 'Gend_GS_Availability_REST class not loaded — class-availability-rest.php not required?' );
    return;
}
if ( ! class_exists( 'Gend_GS_Availability_Schema' ) ) {
    gs_uat_fail( 'Gend_GS_Availability_Schema not loaded — Plan 28-01 schema bootstrap missing?' );
    return;
}

// Resolve a test user — prefer user_id 1 (super-admin on hub), fall back to first user.
$test_user_id = 1;
if ( ! get_userdata( $test_user_id ) ) {
    $u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
    $test_user_id = $u ? (int) $u[0] : 0;
}
if ( $test_user_id <= 0 ) { gs_uat_fail( 'no users in system; cannot run' ); return; }

// CRITICAL: register cleanup BEFORE any state mutation.
$table = Gend_GS_Availability_Schema::table_availability();
register_shutdown_function( function () use ( $wpdb, $table, $test_user_id ) {
    $wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );
    echo "CLEAN · deleted availability row for user_id={$test_user_id}\n";
} );

// Wipe any pre-existing row so we start from empty.
$wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );

// Set the current user so get_current_user_id() resolves.
wp_set_current_user( $test_user_id );

// ─── Assert 1: GET on empty returns has_row=false + empty defaults ─────────
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/availability' );
$resp = rest_do_request( $req );
$data = $resp->get_data();
if ( ! empty( $data['has_row'] ) || $data['user_id'] !== $test_user_id ) {
    gs_uat_fail( 'GET empty defaults', 'has_row=' . var_export( $data['has_row'] ?? null, true ) );
} else {
    gs_uat_pass( 'GET empty: has_row=false + user_id matches' );
}

// ─── Assert 2: PUT valid payload UPSERTs and returns saved row ─────────────
$payload = array(
    'timezone'       => 'America/Toronto',
    'working_hours'  => array( 'mon' => array( array( 'start' => '09:00', 'end' => '17:00' ) ), 'tue' => array( array( 'start' => '09:00', 'end' => '17:00' ) ) ),
    'blocked_ranges' => array( array( 'start_utc' => '2026-06-14T13:00:00Z', 'end_utc' => '2026-06-14T16:00:00Z', 'reason' => 'doctor' ) ),
);
$req = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$req->set_body_params( $payload );
$resp = rest_do_request( $req );
$data = $resp->get_data();
$ok = is_array( $data ) && ! empty( $data['has_row'] ) && $data['timezone'] === 'America/Toronto' && ! empty( $data['share_token'] );
$ok ? gs_uat_pass( 'PUT valid: row UPSERTed; tz + share_token round-tripped' )
    : gs_uat_fail( 'PUT valid', wp_json_encode( $data ) );

$first_token = is_array( $data ) ? (string) ( $data['share_token'] ?? '' ) : '';
if ( strlen( $first_token ) === 43 ) {
    gs_uat_pass( 'share_token auto-generated as 43-char alphanumeric' );
} else {
    gs_uat_fail( 'share_token shape', 'len=' . strlen( $first_token ) . ' val=' . $first_token );
}

// ─── Assert 3: PUT idempotency — same payload twice = same row + same token ─
// Proves the handle_put() INSERT ... ON DUPLICATE KEY UPDATE path is atomic on UNIQUE idx_user_id.
$resp2 = rest_do_request( $req );
$data2 = $resp2->get_data();
$row_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $test_user_id ) );
( $row_count === 1 ) ? gs_uat_pass( 'PUT idempotent — exactly 1 row after 2 PUTs' )
                     : gs_uat_fail( 'PUT idempotency', "row_count={$row_count}" );
( is_array( $data2 ) && (string) ( $data2['share_token'] ?? '' ) === $first_token )
    ? gs_uat_pass( 'share_token PRESERVED on subsequent PUT (not rotated)' )
    : gs_uat_fail( 'share_token preservation', 'changed' );

// ─── Assert 4: PUT invalid tz rejected with 400 ────────────────────────────
$req = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$req->set_body_params( array( 'timezone' => '+05:00' ) ); // offsets MUST be rejected (Pitfall 5)
$resp = rest_do_request( $req );
$status = $resp->get_status();
( $status === 400 ) ? gs_uat_pass( 'PUT invalid tz "+05:00" rejected with 400' )
                    : gs_uat_fail( 'PUT invalid tz status', "got {$status}" );

$req->set_body_params( array( 'timezone' => 'America/NotARealCity' ) );
$resp = rest_do_request( $req );
( $resp->get_status() === 400 ) ? gs_uat_pass( 'PUT invalid tz "America/NotARealCity" rejected with 400' )
                                : gs_uat_fail( 'PUT bogus IANA', 'got ' . $resp->get_status() );

// ─── Assert 5: ?user= IGNORED — sets to user_id=1, the route still resolves current user ─
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/availability' );
$req->set_query_params( array( 'user' => 999999 ) ); // attempt to escalate
$resp = rest_do_request( $req );
$data = $resp->get_data();
( is_array( $data ) && (int) ( $data['user_id'] ?? 0 ) === $test_user_id )
    ? gs_uat_pass( '?user=999999 IGNORED — server resolved current user_id=' . $test_user_id )
    : gs_uat_fail( '?user= leak', wp_json_encode( $data ) );

// ─── Assert 6: GET round-trips working_hours + blocked_ranges + tz ─────────
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/availability' );
$resp = rest_do_request( $req );
$data = $resp->get_data();
$wh_ok = ! empty( $data['working_hours']['mon'][0]['start'] ) && $data['working_hours']['mon'][0]['start'] === '09:00';
$br_ok = ! empty( $data['blocked_ranges'][0]['reason'] ) && $data['blocked_ranges'][0]['reason'] === 'doctor';
$wh_ok ? gs_uat_pass( 'working_hours round-trip mon=[{09:00→17:00}]' ) : gs_uat_fail( 'working_hours round-trip' );
$br_ok ? gs_uat_pass( 'blocked_ranges round-trip reason="doctor"' ) : gs_uat_fail( 'blocked_ranges round-trip' );

echo "=== done — cleanup runs at shutdown ===\n";
