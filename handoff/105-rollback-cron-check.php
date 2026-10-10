<?php
/**
 * Phase 105 rollback (runbook 11.6 step 4): after the reverse swap and the opcache reset,
 * find cron events still on a NEW (gend_society_*) hook on every blog and clear them.
 *
 * Cached 1.2.0 code that ran between gend_society_unmigrate_cron_all_blogs() and the opcache
 * reset can re-schedule new-hook events (its "ensure scheduled" checks on init). The old
 * version has no listener for those hooks.
 *
 * Per (new hook, args):
 *  - an OLD-hook event with the same args exists (the event was moved back): the new-hook
 *    copies are cleared with wp_clear_scheduled_hook( new, args );
 *  - no old-hook twin (an event created during the window, e.g. a booking reminder): it is
 *    moved back to the old hook with the same timestamp, schedule and args, then cleared.
 *
 * Usage (main site, on the rolled-back version; read-only unless GS105_APPLY=1):
 *   GS105_MAP_FILE=<.../gend-society/inc/bootstrap/key-map.php> wp eval-file 105-rollback-cron-check.php
 *   GS105_MAP_FILE=... GS105_APPLY=1 wp eval-file 105-rollback-cron-check.php
 * Last line: "ROLLBACK CRON: OK|FOUND|FAIL ...".
 *
 * @package gend-society
 */

// phpcs:ignoreFile -- operator tool run through wp eval-file, never shipped.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gs105r_mapfile = getenv( 'GS105_MAP_FILE' );
if ( ! $gs105r_mapfile || ! is_file( $gs105r_mapfile ) ) {
	echo "ROLLBACK CRON: FAIL (GS105_MAP_FILE not found)\n";
	return;
}
$gs105r_map   = require $gs105r_mapfile;
$gs105r_pairs = isset( $gs105r_map['cron'] ) ? array_flip( $gs105r_map['cron'] ) : array(); // new => old.
$gs105r_apply = '1' === getenv( 'GS105_APPLY' );

$gs105r_scan = static function () use ( $gs105r_pairs ) {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'cron', 'options' );
	$new = array();
	$old = array();
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( (array) $hooks as $hook => $inst ) {
			foreach ( (array) $inst as $key => $e ) {
				if ( isset( $gs105r_pairs[ $hook ] ) ) {
					$new[] = array( 'ts' => (int) $ts, 'hook' => $hook, 'key' => $key, 'schedule' => $e['schedule'] ?? false, 'interval' => $e['interval'] ?? null, 'args' => $e['args'] ?? array() );
				} elseif ( in_array( $hook, $gs105r_pairs, true ) ) {
					$old[ $hook . '|' . $key ] = true;
				}
			}
		}
	}
	return array( $new, $old );
};

$gs105r_out    = array();
$gs105r_left   = 0;
$gs105r_failed = 0;
$gs105r_found  = 0;
$gs105r_blogs  = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
foreach ( $gs105r_blogs as $gs105r_b ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $gs105r_b );
	}
	list( $gs105r_new, $gs105r_old ) = $gs105r_scan();
	$gs105r_found += count( $gs105r_new );
	$gs105r_rec    = array( 'new_hook_events' => $gs105r_new, 'cleared' => array(), 'moved_back' => array() );
	if ( $gs105r_apply ) {
		$gs105r_done = array();
		foreach ( $gs105r_new as $e ) {
			$old_hook = $gs105r_pairs[ $e['hook'] ];
			if ( isset( $gs105r_old[ $old_hook . '|' . $e['key'] ] ) ) {
				if ( empty( $gs105r_done[ $e['hook'] . '|' . $e['key'] ] ) ) {
					$gs105r_done[ $e['hook'] . '|' . $e['key'] ] = true;
					$gs105r_rec['cleared'][] = array( $e['hook'], $e['args'], wp_clear_scheduled_hook( $e['hook'], $e['args'] ) );
				}
				continue;
			}
			$r = $e['schedule']
				? wp_schedule_event( $e['ts'], $e['schedule'], $old_hook, $e['args'], true )
				: wp_schedule_single_event( $e['ts'], $old_hook, $e['args'], true );
			if ( is_wp_error( $r ) || ! wp_get_scheduled_event( $old_hook, $e['args'], $e['ts'] ) ) {
				++$gs105r_failed; // keep the new-hook event: nothing is lost.
				$gs105r_rec['moved_back'][] = array( $e['hook'], $e['ts'], 'FAILED: ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'not scheduled' ) );
				continue;
			}
			wp_unschedule_event( $e['ts'], $e['hook'], $e['args'] );
			$gs105r_old[ $old_hook . '|' . $e['key'] ] = true;
			$gs105r_rec['moved_back'][]               = array( $e['hook'] . ' -> ' . $old_hook, $e['ts'], $e['args'] );
		}
		list( $gs105r_after ) = $gs105r_scan();
		$gs105r_rec['left']   = $gs105r_after;
		$gs105r_left         += count( $gs105r_after );
	}
	$gs105r_out[ (int) $gs105r_b ] = $gs105r_rec;
	if ( is_multisite() ) {
		restore_current_blog();
	}
}
echo wp_json_encode( $gs105r_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $gs105r_apply ) {
	printf( "ROLLBACK CRON: %s (%d new-hook events found, %d left, %d failed)\n", ( 0 === $gs105r_left && 0 === $gs105r_failed ) ? 'OK' : 'FAIL', $gs105r_found, $gs105r_left, $gs105r_failed );
} else {
	printf( "ROLLBACK CRON: %s (%d new-hook events; dry run, nothing written)\n", $gs105r_found ? 'FOUND' : 'OK', $gs105r_found );
}
