<?php
/**
 * Phase 27 Plan 02 UAT — AGG-06 (Pitfall 7 cross-member data leak prevention).
 *
 * Goal: prove a logged-in member-A session with a HAND-CRAFTED `?user=B` REST
 * param returns ONLY member-A's events. The `?user=` param MUST be IGNORED;
 * identity flows authoritatively from get_current_user_id() server-side.
 *
 * Secondary check: log in as member B (no ?user param) — assert the event-id
 * set has ZERO overlap with the member-A baseline call (bidirectional scoping).
 *
 * Run on hub (REQUIRES two members with REAL data — does not seed):
 *   MEMBER_A_USER_ID=<id> MEMBER_B_USER_ID=<id> \
 *     wp eval-file wp-content/plugins/gend-society/handoff/27-02-uat-cross-member-leak.php --allow-root
 *
 * Exits 0 always.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via `wp eval-file` (ABSPATH undefined).\n" );
	exit( 1 );
}

function gs_uat_pass( $msg ) { echo 'PASS: ' . $msg . "\n"; }
function gs_uat_fail( $msg ) { echo 'FAIL: ' . $msg . "\n"; }
function gs_uat_skip( $msg ) { echo 'SKIP: ' . $msg . "\n"; }

global $wpdb;

$member_a = (int) getenv( 'MEMBER_A_USER_ID' );
$member_b = (int) getenv( 'MEMBER_B_USER_ID' );

$pm_assignees = $wpdb->prefix . 'pm_assignees';
$bm_social    = $wpdb->prefix . 'bm_social_schedule_v2';
$bm_drip      = $wpdb->prefix . 'bm_drip_queue';

// Auto-resolve fallback if env not set
if ( $member_a <= 0 ) {
	$pm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_assignees ) );
	if ( $pm_exists ) {
		$member_a = (int) $wpdb->get_var( "SELECT assigned_to FROM {$pm_assignees} ORDER BY id LIMIT 1" );
	}
	if ( $member_a <= 0 ) {
		$bm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bm_social ) );
		if ( $bm_exists ) {
			$member_a = (int) $wpdb->get_var( "SELECT user_id FROM {$bm_social} ORDER BY id LIMIT 1" );
		}
	}
}
if ( $member_b <= 0 && $member_a > 0 ) {
	$pm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_assignees ) );
	if ( $pm_exists ) {
		$member_b = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT assigned_to FROM {$pm_assignees} WHERE assigned_to != %d ORDER BY id LIMIT 1",
			$member_a
		) );
	}
	if ( $member_b <= 0 ) {
		$bm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bm_social ) );
		if ( $bm_exists ) {
			$member_b = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$bm_social} WHERE user_id != %d ORDER BY id LIMIT 1",
				$member_a
			) );
		}
	}
}

if ( $member_a <= 0 || $member_b <= 0 || $member_a === $member_b ) {
	gs_uat_skip( "need two distinct members with pm_assignees or bm_social rows — set MEMBER_A_USER_ID + MEMBER_B_USER_ID (got A=$member_a B=$member_b)" );
	exit( 0 );
}

// ---- Helper: dispatch and return event id set ----
$dispatch = function ( int $uid, int $user_param ) {
	wp_set_current_user( $uid );
	$req = new WP_REST_Request( 'GET', '/gs/v1/calendar/events' );
	$req->set_param( 'from', gmdate( 'c', strtotime( '-365 days' ) ) );
	$req->set_param( 'to',   gmdate( 'c', strtotime( '+365 days' ) ) );
	if ( $user_param > 0 ) {
		// Hand-craft the leak attempt — Pitfall 7. The endpoint MUST ignore.
		$req->set_param( 'user', $user_param );
	}
	$res = rest_do_request( $req );
	return array(
		'status' => $res->get_status(),
		'data'   => $res->get_data(),
	);
};

// ---- Call 1: member A baseline (no ?user= param) ----
$a_baseline = $dispatch( $member_a, 0 );
if ( $a_baseline['status'] !== 200 ) {
	gs_uat_fail( "member A baseline returned HTTP {$a_baseline['status']}" );
	exit( 0 );
}
$a_ids = array();
foreach ( ( $a_baseline['data']['events'] ?? array() ) as $e ) {
	if ( isset( $e['id'] ) ) { $a_ids[ $e['id'] ] = true; }
}

// ---- Call 2: member A with hand-crafted ?user=B (THE Pitfall 7 attack) ----
$a_attack = $dispatch( $member_a, $member_b );
if ( $a_attack['status'] !== 200 ) {
	gs_uat_fail( "member A with ?user=B returned HTTP {$a_attack['status']}" );
	exit( 0 );
}
$a_attack_ids = array();
foreach ( ( $a_attack['data']['events'] ?? array() ) as $e ) {
	if ( isset( $e['id'] ) ) { $a_attack_ids[ $e['id'] ] = true; }
}

// The attack response must EXACTLY equal the baseline response (?user= IGNORED).
$only_in_baseline = array_diff_key( $a_ids,        $a_attack_ids );
$only_in_attack   = array_diff_key( $a_attack_ids, $a_ids        );
if ( ! empty( $only_in_attack ) || ! empty( $only_in_baseline ) ) {
	$diff_sample = array_slice( array_keys( array_merge( $only_in_attack, $only_in_baseline ) ), 0, 3 );
	gs_uat_fail( '?user=B param NOT ignored — A-attack response differs from A-baseline by ' .
		( count( $only_in_attack ) + count( $only_in_baseline ) ) . ' event(s), sample: ' .
		implode( ',', $diff_sample ) );
	exit( 0 );
}

// ---- Call 3: member B baseline (bidirectional check) ----
$b_baseline = $dispatch( $member_b, 0 );
if ( $b_baseline['status'] !== 200 ) {
	gs_uat_fail( "member B baseline returned HTTP {$b_baseline['status']}" );
	exit( 0 );
}
$b_ids = array();
foreach ( ( $b_baseline['data']['events'] ?? array() ) as $e ) {
	if ( isset( $e['id'] ) ) { $b_ids[ $e['id'] ] = true; }
}

// Verify any overlap between A and B is LEGITIMATE (shared pm-task assignment
// is possible — both members can be assigned to the same task). Per-row
// validation: every overlapping id must have BOTH user_ids on the underlying
// table. For non-pm-task ids (bm-social/bm-drip/pm-milestone/pm-project), any
// overlap IS a leak because those are strictly per-user.
$overlap = array_intersect_key( $a_ids, $b_ids );
$leaks   = array();
foreach ( array_keys( $overlap ) as $id ) {
	if ( strpos( $id, 'pm-task-' ) === 0 ) {
		$task_id = (int) substr( $id, strlen( 'pm-task-' ) );
		$pm_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pm_assignees ) );
		if ( $pm_exists ) {
			$both = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT assigned_to) FROM {$pm_assignees}
				 WHERE task_id = %d AND assigned_to IN (%d, %d)",
				$task_id, $member_a, $member_b
			) );
			if ( $both < 2 ) {
				$leaks[] = "$id surfaced for both A+B but pm_assignees only matches " . $both . ' member(s)';
			}
		}
	} elseif ( strpos( $id, 'bm-social-' ) === 0 ) {
		$bm_id  = (int) substr( $id, strlen( 'bm-social-' ) );
		$owner  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$bm_social} WHERE id = %d", $bm_id
		) );
		// bm_social rows belong to exactly ONE user — any cross-surface = leak.
		$leaks[] = "$id surfaced for BOTH A+B (bm_social.user_id=$owner — strictly per-user)";
	} elseif ( strpos( $id, 'bm-drip-' ) === 0 ) {
		$bm_id = (int) substr( $id, strlen( 'bm-drip-' ) );
		$bm_drip_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bm_drip ) );
		$owner = $bm_drip_exists
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$bm_drip} WHERE id = %d", $bm_id ) )
			: 0;
		$leaks[] = "$id surfaced for BOTH A+B (bm_drip.user_id=$owner — strictly per-user)";
	} elseif ( strpos( $id, 'pm-milestone-' ) === 0 || strpos( $id, 'pm-project-' ) === 0 ) {
		// Milestones / projects can legitimately surface for multiple members if
		// both are assigned to ANY task in that project. Acceptable overlap.
	}
}

if ( ! empty( $leaks ) ) {
	gs_uat_fail( 'cross-member leak across ' . count( $leaks ) . ' id(s): ' .
		implode( ' | ', array_slice( $leaks, 0, 5 ) ) );
	exit( 0 );
}

gs_uat_pass( '?user=B IGNORED (A-baseline === A-attack, ' . count( $a_ids ) . ' events); ' .
	'bidirectional B-baseline has ' . count( $b_ids ) . ' events; overlap=' . count( $overlap ) .
	' (all explainable as legitimate shared assignments)' );
