<?php
/**
 * Phase 28-02 UAT — Phase 27 aggregator member-tz integration.
 * Asserts:
 *  1. get_member_timezone($user_id) returns site fallback when no row exists.
 *  2. After PUT sets tz, get_member_timezone() returns the new tz (cache-bust verified).
 *  3. /wp-json/gs/v1/calendar/events response envelope now includes member_tz.
 *
 * Run from inside wp-hub pod:
 *   wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-02-uat-member-tz-integration.php
 */
if ( ! defined( 'ABSPATH' ) ) { echo "must run via wp eval-file\n"; exit; }

function gs_uat_pass( $l ) { echo "PASS · {$l}\n"; }
function gs_uat_fail( $l, $w = '' ) { echo "FAIL · {$l}" . ( $w ? " :: {$w}" : '' ) . "\n"; }

global $wpdb;
echo "=== Phase 28-02 member-tz integration UAT ===\n";

if ( ! class_exists( 'Gend_GS_Calendar_Events_REST' ) ) { gs_uat_fail( 'aggregator REST class missing' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_REST' )    ) { gs_uat_fail( 'availability REST class missing' ); return; }
if ( ! class_exists( 'Gend_GS_Availability_Schema' )  ) { gs_uat_fail( 'schema class missing' ); return; }

$test_user_id = 1;
if ( ! get_userdata( $test_user_id ) ) {
    $u = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
    $test_user_id = $u ? (int) $u[0] : 0;
}
if ( $test_user_id <= 0 ) { gs_uat_fail( 'no users' ); return; }

$table = Gend_GS_Availability_Schema::table_availability();
register_shutdown_function( function () use ( $wpdb, $table, $test_user_id ) {
    $wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );
    wp_cache_delete( Gend_GS_Availability_REST::tz_cache_key( $test_user_id ), Gend_GS_Availability_REST::CACHE_GROUP );
    echo "CLEAN · deleted availability row + cache for user_id={$test_user_id}\n";
} );

// Start clean.
$wpdb->delete( $table, array( 'user_id' => $test_user_id ), array( '%d' ) );
wp_cache_delete( Gend_GS_Availability_REST::tz_cache_key( $test_user_id ), Gend_GS_Availability_REST::CACHE_GROUP );
wp_set_current_user( $test_user_id );

// Assert 1: no row → falls back to site tz
$site_tz = wp_timezone_string() ?: 'UTC';
$resolved = Gend_GS_Calendar_Events_REST::get_member_timezone( $test_user_id );
( $resolved === $site_tz ) ? gs_uat_pass( "get_member_timezone() fallback = site '{$site_tz}'" )
                           : gs_uat_fail( 'site-tz fallback', "got '{$resolved}'" );

// Assert 2: PUT sets tz → get_member_timezone returns it (cache-bust)
$req = new WP_REST_Request( 'PUT', '/gs/v1/calendar/availability' );
$req->set_body_params( array( 'timezone' => 'America/Halifax' ) );
$resp = rest_do_request( $req );
if ( $resp->get_status() !== 200 ) { gs_uat_fail( 'PUT prep', 'status=' . $resp->get_status() ); return; }

$resolved = Gend_GS_Calendar_Events_REST::get_member_timezone( $test_user_id );
( $resolved === 'America/Halifax' )
    ? gs_uat_pass( 'cache-bust on PUT — get_member_timezone() returns new tz within same request' )
    : gs_uat_fail( 'cache-bust', "got '{$resolved}'" );

// Assert 3: /calendar/events envelope now includes member_tz
$from = gmdate( 'Y-m-d\T00:00:00\Z', strtotime( '-1 day' ) );
$to   = gmdate( 'Y-m-d\T23:59:59\Z', strtotime( '+7 days' ) );
$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
$req->set_query_params( array( 'from' => $from, 'to' => $to ) );
$resp = rest_do_request( $req );
$data = $resp->get_data();
( is_array( $data ) && ( $data['member_tz'] ?? '' ) === 'America/Halifax' )
    ? gs_uat_pass( 'aggregator envelope includes member_tz=America/Halifax' )
    : gs_uat_fail( 'envelope member_tz', wp_json_encode( $data ) );

echo "=== done — cleanup runs at shutdown ===\n";
