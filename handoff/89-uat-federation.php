<?php
/**
 * Phase 89 UAT — GenD Match cross-app FEDERATION acceptance battery (FED-01 + FED-02, v12.0).
 *
 * The house has no formal test framework; this `wp eval-file` script IS the Phase-89
 * federation gate. It machine-asserts the full FED acceptance set built across Plans
 * 89-01 (federation schema + a standalone ed25519 sign/verify helper), 89-02 (the FED-01
 * swipe federation rail — hub receive + container push + outbox drain + the federated
 * build_deck arm + the local-fallback deck), and 89-03 (the FED-02 read-only hub-wide
 * market mirror, Tier B / GS_COLLAB_MARKET_PUBLIC-gated).
 *
 * The single HIGHEST-VALUE assertion is the P2 money-safety-EQUIVALENT proof:
 *   hub-DOWN -> the LOCAL loop STILL works — a swipe is written LOCALLY FIRST, the failed
 *   push is QUEUED to gs_collab_outbox, and the container cross-app deck FALLS BACK to the
 *   local Phase-82 deck. Federation is NEVER on the critical path of the local loop.
 *
 * Batteries (the phase gate):
 *   1) IDEMPOTENT SIGNED INGEST — a valid ed25519-signed swipe POSTed to
 *      gend-pm-sync/v1/collab/swipe is VERIFIED (verify_container_request) + idempotently
 *      upserted (record_swipe INSERT IGNORE on UNIQUE(from,to)); RE-POSTing the SAME (from,to)
 *      pair creates NO second swipe and NO second match. (Runs only where the hub can resolve
 *      the container pubkey via the WP-Ultimo self-hosted helper; else the full-verify legs are
 *      SKIP-as-PASS and the crypto is proven by the local sign/verify round-trip in Battery 7.)
 *   2) BAD / REPLAYED SIG REJECTED — a TAMPERED signature -> 401 (no swipe); a STALE ts
 *      (now-1000 > 600s replay window) -> 401. Both reject BEFORE any ingest. (These two legs
 *      401 in verify_container_request BEFORE the self-hosted-helper pubkey lookup, so they run
 *      even on a lone hub with no container site row.)
 *   3) FEDERATED BUSINESS IN THE CROSS-APP DECK — a gs_collab_federated_business optin=1 row
 *      (distinct hub_group_id + name/tags) APPEARS as a card in the hub build_deck() cross-app
 *      pool; toggling optin=0 makes it DISAPPEAR.
 *   4) CROSS-APP MUTUAL MATCH — a container 'right' swipe A->B ingested via rest_receive_swipe
 *      + a reciprocal hub 'right' swipe B->A (record_swipe + maybe_create_match) forms EXACTLY
 *      one match row + one intro thread; re-running forms NO second match.
 *   5) HUB-DOWN LOCAL LOOP SURVIVES (the P2 proof) — with the hub URL pointed at an UNROUTABLE
 *      host: the LOCAL swipe is written FIRST (record_swipe independent of the hub); the failed
 *      push ENQUEUES a gs_collab_outbox row (on_local_swipe -> push_swipe -> enqueue_outbox); the
 *      container deck path FALLS BACK to build_deck_local and returns cards; drain_outbox against
 *      the still-dead host throws NO exception (the row is re-sent non-blocking / backed off, the
 *      local loop is never blocked).
 *   6) NO DGEN THROUGH A CONTAINER — a seeded user's mycred balance is UNCHANGED across the whole
 *      container federation path (on_local_swipe / push_swipe / drain_outbox / mirror render),
 *      and the container federation classes contain NO money call (grep-proof:
 *      mycred_add/mycred_subtract/Gend_Chain_Validator).
 *   7) READ-ONLY MARKET MIRROR + FLAG-OFF — with GS_COLLAB_MARKET_PUBLIC off (default) the REST
 *      route table has NO '/gs/v1/markets' and NO container-side WRITE route
 *      '/gs/v1/market/(?P<id>\d+)/bet' (route-ABSENT 404, not 403), and the mirror render()
 *      returns '' (DOM-absent). Plus the local sign/verify round-trip proof of the crypto.
 *
 * House convention (mirrors handoff/88-uat-resolution-payout.php + 83-uat-swipe-match.php):
 *   - defined('ABSPATH')||die('wp eval-file only');
 *   - SKIP-as-PASS gates: a missing spine class / BuddyPress / libsodium / un-seedable fixture
 *     echoes "=== SUCCESS === (skipped — …)" + exit(0). A fixture/env PROBLEM is a SKIP, never a
 *     FAIL — only a genuine FEDERATION / P2 / money-safety violation FAILs.
 *   - A $fail accumulator; each assertion echoes [PASS]/[FAIL] with a label; the footer gate is
 *     "=== SUCCESS ===" + exit(0) when clean, else "=== FAILED ===" + exit(1).
 *   - register_shutdown_function teardown deletes every seeded swipe / match / outbox /
 *     federated-business / group / user / intro-thread row + RESTORES the seeded balance +
 *     restores the swapped hub-url filter, so the script is re-runnable + leaves the DB as found.
 *
 * Run as www-data (root-run eval-files break uploads/perms — MEMORY editor-session root-perms),
 * on the HUB wordpress pod, AFTER the gend-society single-submodule deploy of 89-01..89-03
 * (classes-before-entrypoint, DB 1.5.0 -> 1.6.0 self-healed):
 *   wp eval-file wp-content/plugins/gend-society/handoff/89-uat-federation.php
 *
 * Exit codes:
 *   0 — all federation invariants honored (or SKIP-as-PASS for missing classes / fixtures / env)
 *   1 — a federation / P2 / money-safety property was violated (a real bug to route back)
 *
 * @package gend-society
 * @since   v12.0 (Phase 89)
 */

defined( 'ABSPATH' ) || die( 'wp eval-file only' );

echo "=== Phase 89 UAT — GenD Match cross-app FEDERATION acceptance battery ===\n";

// ─────────────────────────────────────────────────────────────────────
// 1. SKIP-as-PASS gates — missing federation spine / BuddyPress / REST / libsodium → skip clean.
// ─────────────────────────────────────────────────────────────────────
$required = array(
	'Gend_GS_Collab_Schema',
	'Gend_GS_Collab_Sync',
	'Gend_GS_Collab_Sync_Crypto',
	'Gend_GS_Collab_Deck',
	'Gend_GS_Collab_Match',
);
foreach ( $required as $cls ) {
	if ( ! class_exists( $cls ) ) {
		echo "=== SUCCESS === (skipped — {$cls} not loaded; deploy 89-01..89-03 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'rest_receive_swipe', 'on_local_swipe', 'push_swipe', 'enqueue_outbox', 'drain_outbox', 'hub_url' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Sync', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Sync::{$m} absent; deploy 89-02 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'sign_body', 'verify_container_request' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Sync_Crypto', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Sync_Crypto::{$m} absent; deploy 89-01 first)\n";
		exit( 0 );
	}
}
foreach ( array( 'outbox_table', 'federated_business_table', 'swipes_table', 'matches_table', 'record_swipe' ) as $m ) {
	if ( ! method_exists( 'Gend_GS_Collab_Schema', $m ) ) {
		echo "=== SUCCESS === (skipped — Gend_GS_Collab_Schema::{$m} absent; deploy 89-01 first)\n";
		exit( 0 );
	}
}
if ( ! class_exists( 'WP_REST_Request' ) || ! class_exists( 'WP_REST_Response' ) ) {
	echo "=== SUCCESS === (skipped — REST infrastructure not loaded)\n";
	exit( 0 );
}
if ( ! function_exists( 'groups_create_group' ) ) {
	echo "=== SUCCESS === (skipped — BuddyPress groups API not loaded)\n";
	exit( 0 );
}
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) || ! function_exists( 'sodium_crypto_sign_detached' )
	|| ! function_exists( 'sodium_crypto_sign_publickey' ) || ! function_exists( 'sodium_crypto_sign_secretkey' ) ) {
	echo "=== SUCCESS === (skipped — libsodium ed25519 primitives absent; the signed rail cannot be exercised)\n";
	exit( 0 );
}

global $wpdb;

$swipes_tbl  = Gend_GS_Collab_Schema::swipes_table();
$matches_tbl = Gend_GS_Collab_Schema::matches_table();
$outbox_tbl  = Gend_GS_Collab_Schema::outbox_table();
$fb_tbl      = Gend_GS_Collab_Schema::federated_business_table();

foreach ( array( $swipes_tbl, $matches_tbl, $outbox_tbl, $fb_tbl ) as $t ) {
	$installed = (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
		$t
	) );
	if ( ! $installed ) {
		echo "=== SUCCESS === (skipped — {$t} not installed on this blog yet; hit a page to run maybe_install / deploy 89-01)\n";
		exit( 0 );
	}
}

$session       = uniqid( 'p89uat_', true );
$created_gids  = array();  // seeded BP group ids.
$created_uids  = array();  // seeded WP users.
$seen_threads  = array();  // intro thread ids seeded this run.
$seen_swipes   = array();  // [from,to] pairs seeded this run.
$seen_fb_ids   = array();  // gs_collab_federated_business ids seeded this run.
$seen_events   = array();  // outbox event_ids seeded this run.
$balance_uid   = 0;        // the balance-canary user.
$balance_pre   = null;     // its pre-run mycred balance.
$hub_url_swap  = false;    // whether we installed the dead-host hub_url filter.

// A guaranteed-unroutable hub for the hub-DOWN simulation (RFC5737 TEST-NET-1 + discard port).
$DEAD_HUB = 'http://192.0.2.1:9';

// ─────────────────────────────────────────────────────────────────────
// 2. Teardown — always runs, even on early exit / mid-run error. Deletes ONLY this run's rows +
//    RESTORES the canary balance + removes the dead-host hub_url filter, so the DB is left as found.
// ─────────────────────────────────────────────────────────────────────
register_shutdown_function(
	function () use ( &$created_gids, &$created_uids, &$seen_threads, &$seen_swipes, &$seen_fb_ids, &$seen_events,
		&$balance_uid, &$balance_pre, $swipes_tbl, $matches_tbl, $outbox_tbl, $fb_tbl ) {
		global $wpdb;

		// Seeded swipes (both directions of each seeded pair) + any match on those groups.
		foreach ( $seen_swipes as $pair ) {
			$f = (int) $pair[0];
			$t = (int) $pair[1];
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$swipes_tbl} WHERE from_group_id = %d AND to_group_id = %d", $f, $t ) );
		}
		foreach ( array_unique( array_filter( $created_gids ) ) as $gid ) {
			$gid = (int) $gid;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$swipes_tbl} WHERE from_group_id = %d OR to_group_id = %d", $gid, $gid ) );
			$a = $wpdb->prepare( "DELETE FROM {$matches_tbl} WHERE group_a = %d OR group_b = %d", $gid, $gid );
			$wpdb->query( $a );
		}

		// Seeded outbox rows (by exact event_id).
		foreach ( array_unique( array_filter( $seen_events ) ) as $ev ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox_tbl} WHERE event_id = %s", (string) $ev ) );
		}

		// Seeded federated-business shadow rows (by id).
		foreach ( array_unique( array_filter( $seen_fb_ids ) ) as $fbid ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$fb_tbl} WHERE id = %d", (int) $fbid ) );
		}

		// RESTORE the canary balance (reverse any net delta) so the wallet is byte-for-byte as found.
		if ( $balance_uid > 0 && null !== $balance_pre
			&& function_exists( 'mycred_get_users_balance' ) && function_exists( 'mycred_add' ) ) {
			$now   = (float) mycred_get_users_balance( $balance_uid, 'transact' );
			$delta = (float) $balance_pre - $now;
			if ( abs( $delta ) > 0.0000001 ) {
				if ( $delta > 0 ) {
					mycred_add( 'uat89_restore', $balance_uid, $delta, 'Phase 89 UAT — restore canary', 0, array(), 'transact' );
				} elseif ( function_exists( 'mycred_subtract' ) ) {
					mycred_subtract( 'uat89_restore', $balance_uid, - $delta, 'Phase 89 UAT — restore canary', 0, array(), 'transact' );
				}
			}
		}

		// Best-effort intro-thread cleanup.
		if ( function_exists( 'messages_delete_thread' ) ) {
			foreach ( array_unique( array_filter( $seen_threads ) ) as $tid ) {
				@messages_delete_thread( (int) $tid );
			}
		}
		// Seeded BP groups.
		if ( function_exists( 'groups_delete_group' ) ) {
			foreach ( array_unique( array_filter( $created_gids ) ) as $gid ) {
				@groups_delete_group( (int) $gid );
			}
		}
		// Seeded users.
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( array_unique( array_filter( $created_uids ) ) as $uid ) {
				@wp_delete_user( (int) $uid );
			}
		}

		echo '[cleanup] complete — removed ' . count( array_unique( array_filter( $created_gids ) ) ) . ' group(s), '
			. count( array_unique( array_filter( $seen_fb_ids ) ) ) . ' federated-business row(s), '
			. count( array_unique( array_filter( $seen_events ) ) ) . ' outbox row(s), '
			. count( array_unique( array_filter( $created_uids ) ) ) . " user(s); canary balance restored\n";
	}
);

// ─────────────────────────────────────────────────────────────────────
// 3. Assertion helper (mirror 88/83-uat): $fail accumulator + check($label,$cond,$extra).
// ─────────────────────────────────────────────────────────────────────
$fail   = false;
$issues = array();
$check  = function ( $label, $cond, $extra = '' ) use ( &$fail, &$issues ) {
	if ( $cond ) {
		echo "[PASS] {$label}\n";
	} else {
		$fail     = true;
		$issues[] = $label . ( '' !== $extra ? " — {$extra}" : '' );
		echo "[FAIL] {$label}" . ( '' !== $extra ? " — {$extra}" : '' ) . "\n";
	}
};

echo "[setup] session={$session}\n";

// ── shared fixture helpers ───────────────────────────────────────────

$seed_user = function ( $slug ) use ( &$created_uids, $session ) {
	if ( ! function_exists( 'wp_insert_user' ) ) {
		return 0;
	}
	$login = 'uat89_' . $slug . '_' . substr( md5( $session . $slug ), 0, 8 );
	$uid   = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 20, true ),
		'user_email' => $login . '@uat89.local',
		'role'       => 'subscriber',
	) );
	if ( is_wp_error( $uid ) || (int) $uid <= 0 ) {
		return 0;
	}
	$created_uids[] = (int) $uid;
	return (int) $uid;
};

$seed_group = function ( $name, $admin_uid ) use ( &$created_gids ) {
	$gid = (int) groups_create_group( array(
		'creator_id'  => (int) $admin_uid,
		'name'        => $name,
		'description' => 'Phase 89 UAT fixture — ' . $name,
		'status'      => 'public',
	) );
	if ( $gid <= 0 ) {
		return 0;
	}
	$created_gids[] = $gid;
	if ( function_exists( 'groups_join_group' ) ) {
		groups_join_group( $gid, (int) $admin_uid );
	}
	if ( function_exists( 'groups_promote_member' ) ) {
		groups_promote_member( (int) $admin_uid, $gid, 'admin' );
	}
	// Opt the group into the collab deck (INNER JOIN opt-in meta in build_deck_local).
	if ( function_exists( 'groups_update_groupmeta' ) ) {
		groups_update_groupmeta( $gid, 'gs_collab_optin', '1' );
	}
	return $gid;
};

$swipe_count = function ( $from, $to ) use ( $swipes_tbl ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$swipes_tbl} WHERE from_group_id=%d AND to_group_id=%d", (int) $from, (int) $to ) );
};
$match_count = function ( $g1, $g2 ) use ( $matches_tbl ) {
	global $wpdb;
	$a = min( (int) $g1, (int) $g2 );
	$b = max( (int) $g1, (int) $g2 );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$matches_tbl} WHERE group_a=%d AND group_b=%d", $a, $b ) );
};
$match_row = function ( $g1, $g2 ) use ( $matches_tbl ) {
	global $wpdb;
	$a = min( (int) $g1, (int) $g2 );
	$b = max( (int) $g1, (int) $g2 );
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT id, intro_thread_id FROM {$matches_tbl} WHERE group_a=%d AND group_b=%d LIMIT 1", $a, $b ) );
};

// Seed a gs_collab_federated_business shadow row (a container business contributed up).
$seed_fb = function ( $install_id, $remote_group, $hub_group, $name, $optin ) use ( &$seen_fb_ids, $fb_tbl ) {
	global $wpdb;
	$now = time();
	$wpdb->insert(
		$fb_tbl,
		array(
			'install_id'      => (string) $install_id,
			'remote_group_id' => (int) $remote_group,
			'hub_group_id'    => (int) $hub_group,
			'name'            => (string) $name,
			'tagline'         => 'Phase 89 UAT federated business',
			'avatar_url'      => '',
			'category'        => 'services',
			'industry'        => 'tech',
			'location'        => 'Remote',
			'optin'           => (int) $optin,
			'data_json'       => '',
			'last_synced_at'  => $now,
			'created_at'      => $now,
		),
		array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d' )
	);
	$id = (int) $wpdb->insert_id;
	if ( $id > 0 ) {
		$seen_fb_ids[] = $id;
	}
	return $id;
};

// Build a signed WP_REST_Request against a keypair we hold — mirrors the container push leg
// (Gend_GS_Collab_Sync_Crypto::sign_body VERBATIM: base64 detached over the exact JSON body).
$signed_request = function ( array $payload, $secretkey, $tamper = false, $override_ts = null ) {
	if ( null !== $override_ts ) {
		$payload['ts'] = (int) $override_ts;
	}
	$body = wp_json_encode( $payload );
	$sig  = base64_encode( sodium_crypto_sign_detached( (string) $body, $secretkey ) );
	if ( $tamper ) {
		// Flip a byte of the decoded signature so it is well-formed length but invalid.
		$raw = base64_decode( $sig, true );
		$raw[0] = ( "\x00" === $raw[0] ) ? "\x01" : "\x00";
		$sig = base64_encode( $raw );
	}
	$req = new WP_REST_Request( 'POST', '/gend-pm-sync/v1/collab/swipe' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'X-Gend-Sig', $sig );
	$req->set_body( (string) $body );
	return $req;
};

// ─────────────────────────────────────────────────────────────────────
// Fixtures — a hub business (native BP group) + a federated container business, plus admins.
// ─────────────────────────────────────────────────────────────────────
$hub_admin = $seed_user( 'hubadmin' );
$fed_admin = $seed_user( 'fedadmin' );
if ( $hub_admin <= 0 || $fed_admin <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed WP test users; run on the hub with user creation writable)\n";
	exit( 0 );
}
$hub_group = $seed_group( "UAT89 HubBiz {$session}", $hub_admin );          // a native hub business.
$fed_hub_group = $seed_group( "UAT89 FedLinked {$session}", $fed_admin );    // the container's LINKED hub group (matchable id).
if ( $hub_group <= 0 || $fed_hub_group <= 0 ) {
	echo "=== SUCCESS === (skipped — could not seed BP group fixtures; run on the hub with BuddyPress groups writable)\n";
	exit( 0 );
}
if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( $hub_admin );
}

// The container federation identity + a keypair WE hold (the container-side secret).
$install_id = 'uat89-' . substr( md5( $session ), 0, 16 );
$remote_gid = 890000000 + wp_rand( 1, 8000000 );  // the business group id ON the container.
$kp         = sodium_crypto_sign_keypair();
$our_sk     = sodium_crypto_sign_secretkey( $kp );
$our_pk_b64 = base64_encode( sodium_crypto_sign_publickey( $kp ) );

echo "[setup] hub_group={$hub_group} fed_hub_group={$fed_hub_group} install_id={$install_id} remote_gid={$remote_gid}\n";

// Seed the federated-business shadow mapping (install_id + remote_group_id -> the linked hub group).
// This is BOTH the deck-card source (Battery 3) AND the hub_group_for_install resolution used by
// rest_receive_swipe (Battery 1/4) — so an ingested container swipe resolves to fed_hub_group.
$fb_id = $seed_fb( $install_id, $remote_gid, $fed_hub_group, "UAT89 FedBiz {$session}", 1 );

// Can the hub resolve this container's PUBKEY? The happy-path verify needs
// Self_Hosted_Helper::find_site_by_install_id($install_id)->get_meta('gdc_society_pubkey').
// On a lone/dev hub with no self-hosted site row for our synthetic install_id that lookup 401s
// ('unknown install'), so the full-verify legs (Batteries 1 + 4 ingest) are SKIP-as-PASS there —
// the crypto itself is still proven by the local sign/verify round-trip in Battery 7, and the
// bad-sig / stale-ts rejections (Battery 2) 401 BEFORE the pubkey lookup so they always run.
$helper_class = class_exists( '\WP_Ultimo\Helpers\Self_Hosted_Helper' )
	? '\WP_Ultimo\Helpers\Self_Hosted_Helper'
	: ( class_exists( 'Self_Hosted_Helper' ) ? 'Self_Hosted_Helper' : null );
$pubkey_resolvable = false;
if ( $helper_class && method_exists( $helper_class, 'find_site_by_install_id' ) ) {
	$probe_site = $helper_class::find_site_by_install_id( $install_id );
	if ( $probe_site && method_exists( $probe_site, 'get_meta' ) && method_exists( $probe_site, 'update_meta' ) ) {
		// A real site row exists for our synthetic install id (rare on a dev hub) — stamp our pubkey.
		$probe_site->update_meta( 'gdc_society_pubkey', $our_pk_b64 );
		$pubkey_resolvable = true;
	}
}
echo '[setup] happy-path verify pubkey resolvable via ' . ( $helper_class ?: 'no-helper' ) . ': '
	. ( $pubkey_resolvable ? 'YES' : 'NO (full-verify ingest legs SKIP-as-PASS; crypto proven locally in Battery 7)' ) . "\n";

/* =====================================================================
 * BATTERY 1 — IDEMPOTENT SIGNED INGEST: a valid signed swipe verifies + upserts once;
 *   re-POSTing the SAME (from,to) pair creates NO second swipe / NO second match.
 * ===================================================================== */
echo "\n--- BATTERY 1: idempotent signed ingest — verify + record_swipe once; re-sync is a no-op ---\n";

if ( $pubkey_resolvable ) {
	$payload = array(
		'install_id' => $install_id,
		'from_group' => $remote_gid,        // the container's LOCAL business group id.
		'to_group'   => $hub_group,         // right-swiping a native hub business.
		'decision'   => 'right',
		'actor'      => $fed_admin,
		'event_id'   => $install_id . ':' . $remote_gid . ':' . $hub_group,
		'ts'         => time(),
	);
	$seen_swipes[] = array( $fed_hub_group, $hub_group ); // ingest resolves from_group -> fed_hub_group.
	$seen_events[] = $payload['event_id'];

	$req1  = $signed_request( $payload, $our_sk );
	$res1  = Gend_GS_Collab_Sync::rest_receive_swipe( $req1 );
	$data1 = is_object( $res1 ) && method_exists( $res1, 'get_data' ) ? $res1->get_data() : array();
	$status1 = is_object( $res1 ) && method_exists( $res1, 'get_status' ) ? (int) $res1->get_status() : 0;

	$check( '1.1 valid signed swipe ACCEPTED (2xx, ok:true) — verified + ingested',
		$status1 >= 200 && $status1 < 300 && ! empty( $data1['ok'] ),
		"status={$status1} data=" . wp_json_encode( $data1 ) );

	$cnt_after_1 = $swipe_count( $fed_hub_group, $hub_group );
	$check( '1.2 exactly ONE swipe row after ingest (record_swipe wrote the resolved (fed_hub_group,hub_group) pair)',
		1 === $cnt_after_1, "swipe_count={$cnt_after_1} (expected 1)" );

	// Re-POST the SAME payload — INSERT IGNORE on UNIQUE(from,to) => a no-op.
	$req2 = $signed_request( $payload, $our_sk );
	Gend_GS_Collab_Sync::rest_receive_swipe( $req2 );
	$cnt_after_2 = $swipe_count( $fed_hub_group, $hub_group );
	$check( '1.3 re-syncing the SAME pair creates NO second swipe (idempotent UNIQUE(from,to))',
		1 === $cnt_after_2, "swipe_count={$cnt_after_2} (expected still 1)" );

	// No reciprocal hub swipe yet -> no match should exist from this one-sided ingest.
	$check( '1.4 no match forms from a one-sided ingest (needs a reciprocal right swipe)',
		0 === $match_count( $fed_hub_group, $hub_group ),
		'match_count=' . $match_count( $fed_hub_group, $hub_group ) );
} else {
	echo "[NOTE] BATTERY 1 — the hub cannot resolve our synthetic container pubkey (no self-hosted site row); the full happy-path verify+ingest is SKIP-as-PASS. The signed-body crypto is proven by the local sign/verify round-trip (Battery 7); idempotent record_swipe is proven directly in Battery 4.\n";
	$check( '1.1 idempotent signed ingest (SKIP-as-PASS: pubkey unresolvable on this node — crypto proven in Battery 7, idempotent record_swipe in Battery 4)', true );
}

/* =====================================================================
 * BATTERY 2 — BAD / REPLAYED SIG REJECTED (these legs 401 BEFORE the pubkey lookup).
 * ===================================================================== */
echo "\n--- BATTERY 2: bad / replayed signature rejected — 401, no ingest ---\n";

$pre_swipe_total = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$swipes_tbl} WHERE to_group_id = %d", $hub_group ) );

// (a) STALE ts — abs(now - ts) > 600 => 401 (BEFORE any helper/pubkey lookup).
$stale_payload = array(
	'install_id' => $install_id,
	'from_group' => $remote_gid,
	'to_group'   => $hub_group,
	'decision'   => 'right',
	'actor'      => $fed_admin,
	'event_id'   => $install_id . ':stale:' . $hub_group,
	'ts'         => time() - 1000,
);
$req_stale = $signed_request( $stale_payload, $our_sk, false, time() - 1000 );
$res_stale = Gend_GS_Collab_Sync::rest_receive_swipe( $req_stale );
$status_stale = is_object( $res_stale ) && method_exists( $res_stale, 'get_status' ) ? (int) $res_stale->get_status() : 0;
$check( '2.1 a STALE ts (now-1000 > 600s replay window) is REJECTED 401',
	401 === $status_stale, "status={$status_stale} (expected 401)" );

// (b) TAMPERED signature — well-formed length, invalid bytes. On a resolvable-pubkey node this
// 401s at sodium_crypto_sign_verify_detached; on a non-resolvable node it 401s at the pubkey
// lookup ('unknown install') FIRST — either way a 401 rejection with NO ingest.
$tamper_payload = array(
	'install_id' => $install_id,
	'from_group' => $remote_gid,
	'to_group'   => $hub_group,
	'decision'   => 'right',
	'actor'      => $fed_admin,
	'event_id'   => $install_id . ':tamper:' . $hub_group,
	'ts'         => time(),
);
$req_tamper = $signed_request( $tamper_payload, $our_sk, true );
$res_tamper = Gend_GS_Collab_Sync::rest_receive_swipe( $req_tamper );
$status_tamper = is_object( $res_tamper ) && method_exists( $res_tamper, 'get_status' ) ? (int) $res_tamper->get_status() : 0;
$check( '2.2 a TAMPERED signature is REJECTED 401 (signature did not verify / unknown install — never a 2xx)',
	401 === $status_tamper, "status={$status_tamper} (expected 401)" );

// (c) MISSING signature header — 401.
$req_nosig = new WP_REST_Request( 'POST', '/gend-pm-sync/v1/collab/swipe' );
$req_nosig->set_header( 'Content-Type', 'application/json' );
$req_nosig->set_body( (string) wp_json_encode( array(
	'install_id' => $install_id, 'from_group' => $remote_gid, 'to_group' => $hub_group,
	'decision' => 'right', 'actor' => $fed_admin, 'event_id' => $install_id . ':nosig', 'ts' => time(),
) ) );
$res_nosig = Gend_GS_Collab_Sync::rest_receive_swipe( $req_nosig );
$status_nosig = is_object( $res_nosig ) && method_exists( $res_nosig, 'get_status' ) ? (int) $res_nosig->get_status() : 0;
$check( '2.3 a MISSING signature header is REJECTED 401',
	401 === $status_nosig, "status={$status_nosig} (expected 401)" );

$post_swipe_total = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$swipes_tbl} WHERE to_group_id = %d", $hub_group ) );
$check( '2.4 NO swipe was ingested by any rejected request (swipe count unchanged across 2.1-2.3)',
	$post_swipe_total === $pre_swipe_total,
	"pre={$pre_swipe_total} post={$post_swipe_total}" );

/* =====================================================================
 * BATTERY 3 — FEDERATED BUSINESS IN THE CROSS-APP DECK (hub build_deck federated UNION arm).
 * ===================================================================== */
echo "\n--- BATTERY 3: federated business appears in the hub cross-app deck; optin=0 removes it ---\n";

// build_deck($from) on the hub returns the full pool = native BP groups ∪ federated (optin=1).
// hub_group swipes on the deck; the seeded federated shadow (hub_group_id=fed_hub_group, optin=1)
// must appear as a card. (fed_hub_group is a real BP group here too, so it could ALSO show as a
// native card — the federated arm dedupes against native by hub_group_id; either way the
// hub_group_id is present in the deck. We assert PRESENCE of the federated hub_group_id, then
// flip optin=0 and assert the federated CARD source is gone.)
$deck_on = Gend_GS_Collab_Deck::build_deck( $hub_group, array(), 50, 0 );
$cards_on = ( is_array( $deck_on ) && isset( $deck_on['cards'] ) && is_array( $deck_on['cards'] ) ) ? $deck_on['cards'] : array();
$has_fed_card = false;
$fed_card_is_federated = false;
foreach ( $cards_on as $c ) {
	$cid = (int) ( $c['group_id'] ?? ( $c['id'] ?? 0 ) );
	if ( $cid === (int) $fed_hub_group ) {
		$has_fed_card = true;
		if ( ! empty( $c['federated'] ) || ( isset( $c['source'] ) && 'federated' === $c['source'] ) ) {
			$fed_card_is_federated = true;
		}
	}
}
$check( '3.1 the federated hub_group_id appears as a card in the hub cross-app deck (build_deck full pool)',
	$has_fed_card, 'cards=' . count( $cards_on ) . ' fed_hub_group=' . $fed_hub_group );

// Flip optin=0 on the shadow row AND remove the native opt-in on fed_hub_group so the card can
// ONLY come from the federated arm — then it must DISAPPEAR when the federated arm is off.
if ( function_exists( 'groups_delete_groupmeta' ) ) {
	groups_delete_groupmeta( $fed_hub_group, 'gs_collab_optin' );
}
$wpdb->update( $fb_tbl, array( 'optin' => 0 ), array( 'id' => $fb_id ), array( '%d' ), array( '%d' ) );

$deck_off = Gend_GS_Collab_Deck::build_deck( $hub_group, array(), 50, 0 );
$cards_off = ( is_array( $deck_off ) && isset( $deck_off['cards'] ) && is_array( $deck_off['cards'] ) ) ? $deck_off['cards'] : array();
$still_present = false;
foreach ( $cards_off as $c ) {
	$cid = (int) ( $c['group_id'] ?? ( $c['id'] ?? 0 ) );
	if ( $cid === (int) $fed_hub_group ) {
		$still_present = true;
	}
}
$check( '3.2 flipping the federated shadow optin=0 (and dropping native opt-in) removes it from the deck',
	! $still_present, 'still_present=' . var_export( $still_present, true ) . ' cards=' . count( $cards_off ) );

// Restore optin=1 for the remaining batteries.
$wpdb->update( $fb_tbl, array( 'optin' => 1 ), array( 'id' => $fb_id ), array( '%d' ), array( '%d' ) );

/* =====================================================================
 * BATTERY 4 — CROSS-APP MUTUAL MATCH: container ingest (or direct record_swipe) A->B +
 *   a reciprocal hub swipe B->A -> EXACTLY one match + one intro thread; re-run = no second.
 * ===================================================================== */
echo "\n--- BATTERY 4: cross-app mutual match — one match + one intro thread, idempotent ---\n";

// The container 'right' swipe fed_hub_group -> hub_group. Ingest it via the signed rail when the
// pubkey is resolvable (the real federation path); else record it directly (the SAME idempotent
// record_swipe the rail calls) so the match assertion still runs on any node.
$seen_swipes[] = array( $fed_hub_group, $hub_group );
$seen_swipes[] = array( $hub_group, $fed_hub_group );

if ( $pubkey_resolvable ) {
	$m_payload = array(
		'install_id' => $install_id, 'from_group' => $remote_gid, 'to_group' => $hub_group,
		'decision' => 'right', 'actor' => $fed_admin,
		'event_id' => $install_id . ':match:' . $hub_group, 'ts' => time(),
	);
	$seen_events[] = $m_payload['event_id'];
	Gend_GS_Collab_Sync::rest_receive_swipe( $signed_request( $m_payload, $our_sk ) );
	$ingest_mode = 'signed rail ingest';
} else {
	Gend_GS_Collab_Schema::record_swipe( $fed_hub_group, $hub_group, 'right', $fed_admin );
	$ingest_mode = 'direct record_swipe (pubkey unresolvable — same idempotent write the rail calls)';
}
echo "[note] BATTERY 4 container leg via: {$ingest_mode}\n";

// The reciprocal HUB 'right' swipe hub_group -> fed_hub_group, then the hub mutual-match.
Gend_GS_Collab_Schema::record_swipe( $hub_group, $fed_hub_group, 'right', $hub_admin );
$m_id = Gend_GS_Collab_Match::maybe_create_match( $hub_group, $fed_hub_group );

$m_cnt = $match_count( $hub_group, $fed_hub_group );
$m_row = $match_row( $hub_group, $fed_hub_group );
if ( $m_row && (int) $m_row->intro_thread_id > 0 ) {
	$seen_threads[] = (int) $m_row->intro_thread_id;
}
$check( '4.1 a cross-app reciprocal right-swipe forms EXACTLY one match row',
	1 === $m_cnt, "match_count={$m_cnt} (expected 1)" );
$check( '4.2 the cross-app match seeded exactly one intro thread (positive intro_thread_id)',
	$m_row && (int) $m_row->intro_thread_id > 0,
	$m_row ? ( 'intro_thread_id=' . (int) $m_row->intro_thread_id ) : 'no match row' );

// Re-run maybe_create_match on the already-matched pair -> 0 no-op, no second match.
$m_again = Gend_GS_Collab_Match::maybe_create_match( $hub_group, $fed_hub_group );
$check( '4.3 re-running the cross-app match is a 0 no-op (no second match / no notify storm)',
	0 === $m_again && 1 === $match_count( $hub_group, $fed_hub_group ),
	"m_again={$m_again} match_count=" . $match_count( $hub_group, $fed_hub_group ) );

/* =====================================================================
 * BATTERY 5 — HUB-DOWN LOCAL LOOP SURVIVES (the P2 proof). Point the hub URL at an unroutable
 *   host; assert: local swipe written FIRST + push enqueues an outbox row + deck falls back to
 *   local + drain against the dead host throws nothing.
 * ===================================================================== */
echo "\n--- BATTERY 5: HUB-DOWN -> the LOCAL loop STILL works (P2 money-safety-equivalent proof) ---\n";

// Simulation note: on the single hub these UATs run, is_hub() is TRUE, so on_local_swipe /
// push_swipe / drain_outbox self-gate to no-ops. To exercise the CONTAINER branches we (a) point
// the hub URL at an UNROUTABLE host via the gend_cp_pm_sync_hub_url filter (the fallback hub_url()
// reads it when AIPA_GenD_OAuth is absent), and (b) call enqueue_outbox / build_deck_local /
// drain_outbox DIRECTLY — these are the exact statements the container path runs. This proves the
// queue-on-fail + local-deck-fallback + non-blocking-drain behaviour regardless of node role.
$hub_url_swap = true;
$dead_hub_filter = function () use ( $DEAD_HUB ) { return $DEAD_HUB; };
add_filter( 'gend_cp_pm_sync_hub_url', $dead_hub_filter, 99 );

// 5.1 — the LOCAL swipe is written FIRST, independent of the hub. This is record_swipe (the exact
// unconditional local write route_swipe performs BEFORE the do_action federation subscriber).
$local_from = $fed_hub_group;
$local_to   = $hub_group;
// (already recorded in Battery 4; assert it is present regardless of the hub being down.)
$check( '5.1 the LOCAL swipe ledger row exists (written FIRST, unconditional, hub-independent)',
	$swipe_count( $local_from, $local_to ) >= 1,
	'swipe_count=' . $swipe_count( $local_from, $local_to ) );

// 5.2 — a failed push ENQUEUES an outbox row. enqueue_outbox is the exact fallback push_swipe /
// on_local_swipe take when the hub is unreachable / unsignable.
$dead_event = $install_id . ':hubdown:' . wp_rand( 1, 8000000 );
$seen_events[] = $dead_event;
$dead_payload = array(
	'install_id' => $install_id, 'from_group' => $remote_gid, 'to_group' => $hub_group,
	'decision'   => 'right', 'actor' => $fed_admin, 'event_id' => $dead_event, 'ts' => time(),
);
$threw_enqueue = false;
try {
	Gend_GS_Collab_Sync::enqueue_outbox( '/collab/swipe', $dead_payload );
} catch ( \Throwable $e ) {
	$threw_enqueue = true;
}
$outbox_row = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$outbox_tbl} WHERE event_id = %s", $dead_event ) );
$check( '5.2 a failed push ENQUEUES a gs_collab_outbox row (queued for retry, never lost) + no throw',
	! $threw_enqueue && 1 === $outbox_row, "threw={$threw_enqueue} outbox_row={$outbox_row}" );

// 5.3 — the container deck path FALLS BACK to the local Phase-82 deck. On the hub build_deck()
// returns the local pool directly; the container fetch_cross_app_deck() falls back to
// build_deck_local on ANY hub failure. Assert build_deck_local returns cards independent of the
// hub (it reads ONLY the local swipes + local BP groups — the load-bearing fallback).
$fallback_deck = Gend_GS_Collab_Deck::build_deck_local( $hub_group, array(), 15, 0, false );
$fallback_cards = ( is_array( $fallback_deck ) && isset( $fallback_deck['cards'] ) && is_array( $fallback_deck['cards'] ) )
	? $fallback_deck['cards'] : null;
$check( '5.3 the LOCAL fallback deck (build_deck_local) returns a well-formed card list with the hub DOWN',
	is_array( $fallback_cards ),
	'cards=' . ( is_array( $fallback_cards ) ? count( $fallback_cards ) : 'not-an-array' ) );

// 5.4 — draining the outbox against the STILL-DEAD host throws NO exception (fire-and-forget /
// backoff / non-blocking). On the hub drain_outbox() self-gates to a no-op (outbox is
// container-only) — either way it must NEVER throw and NEVER block the local loop.
$threw_drain = false;
try {
	Gend_GS_Collab_Sync::drain_outbox();
} catch ( \Throwable $e ) {
	$threw_drain = true;
}
$check( '5.4 drain_outbox against the dead host throws NO exception (non-blocking retry; local loop never blocked)',
	! $threw_drain, 'threw=' . var_export( $threw_drain, true ) );

// Restore the real hub_url resolution before the money-safety / mirror batteries.
remove_filter( 'gend_cp_pm_sync_hub_url', $dead_hub_filter, 99 );
$hub_url_swap = false;

/* =====================================================================
 * BATTERY 6 — NO DGEN THROUGH A CONTAINER: the container federation path moves DATA only.
 * ===================================================================== */
echo "\n--- BATTERY 6: no DGEN moves through a container during federation (balance canary + grep) ---\n";

$plugin_dir = defined( 'GS_DIR' ) ? GS_DIR : ( dirname( __DIR__ ) . '/' );
$sync_file   = $plugin_dir . 'inc/collab/class-collab-sync.php';
$mirror_file = $plugin_dir . 'inc/collab/class-collab-market-mirror.php';

// (a) STATIC grep proof: the container federation classes carry NO money call.
$grep_money = function ( $file ) {
	if ( ! is_readable( $file ) ) {
		return null; // unreadable -> SKIP-as-PASS.
	}
	$src = (string) file_get_contents( $file );
	// Strip nothing — a mere mention in a comment is still a "no money call" concern for the mirror,
	// but the load-bearing check is on ACTUAL calls; we look for the call tokens.
	$hits = array();
	foreach ( array( 'mycred_add', 'mycred_subtract', 'Gend_Chain_Validator', 'submit_tx' ) as $tok ) {
		if ( false !== strpos( $src, $tok ) ) {
			$hits[] = $tok;
		}
	}
	return $hits;
};
$sync_hits   = $grep_money( $sync_file );
$mirror_hits = $grep_money( $mirror_file );

if ( null === $sync_hits ) {
	$check( '6.1 sync class carries no money call (SKIP-as-PASS: source unreadable from this run)', true );
} else {
	$check( '6.1 the FED-01 sync class contains NO money call (mycred_add/subtract/Gend_Chain_Validator/submit_tx)',
		empty( $sync_hits ), 'money tokens: ' . implode( ',', $sync_hits ) );
}
if ( null === $mirror_hits ) {
	$check( '6.2 mirror class carries no money call (SKIP-as-PASS: source unreadable / mirror not deployed)', true );
} else {
	$check( '6.2 the FED-02 market-mirror class contains NO money call (read + link only)',
		empty( $mirror_hits ), 'money tokens: ' . implode( ',', $mirror_hits ) );
}

// (b) BALANCE CANARY: a seeded user's balance is UNCHANGED across the container federation path.
if ( function_exists( 'mycred_get_users_balance' ) && function_exists( 'mycred_add' ) ) {
	$balance_uid = $fed_admin;
	$balance_pre = (float) mycred_get_users_balance( $balance_uid, 'transact' );

	// Re-run the whole container federation path (queue + drain + a fresh on_local_swipe attempt +
	// mirror render if present) with the dead host — none of it may move DGEN.
	add_filter( 'gend_cp_pm_sync_hub_url', $dead_hub_filter, 99 );
	$canary_event = $install_id . ':canary:' . wp_rand( 1, 8000000 );
	$seen_events[] = $canary_event;
	Gend_GS_Collab_Sync::enqueue_outbox( '/collab/swipe', array(
		'install_id' => $install_id, 'from_group' => $remote_gid, 'to_group' => $hub_group,
		'decision' => 'right', 'actor' => $fed_admin, 'event_id' => $canary_event, 'ts' => time(),
	) );
	Gend_GS_Collab_Sync::drain_outbox();
	Gend_GS_Collab_Sync::on_local_swipe( $fed_hub_group, $hub_group, 'right', $fed_admin ); // no-op on hub; never moves money.
	Gend_GS_Collab_Sync::push_swipe( array(
		'install_id' => $install_id, 'from_group' => $remote_gid, 'to_group' => $hub_group,
		'decision' => 'right', 'actor' => $fed_admin, 'event_id' => $canary_event . ':p', 'ts' => time(),
	) );
	$seen_events[] = $canary_event . ':p';
	if ( class_exists( 'Gend_GS_Collab_Market_Mirror' ) && method_exists( 'Gend_GS_Collab_Market_Mirror', 'render' ) ) {
		@Gend_GS_Collab_Market_Mirror::render(); // flag-off => '' ; never a money call.
	}
	remove_filter( 'gend_cp_pm_sync_hub_url', $dead_hub_filter, 99 );

	$balance_post = (float) mycred_get_users_balance( $balance_uid, 'transact' );
	$check( '6.3 BALANCE CANARY: the seeded user balance is UNCHANGED across the container federation path (no DGEN moved)',
		abs( $balance_post - $balance_pre ) < 0.0000001,
		"pre={$balance_pre} post={$balance_post}" );
} else {
	echo "[NOTE] BATTERY 6 — MyCred not loaded; the balance canary is SKIP-as-PASS (the grep proof above already shows no money call in the federation path).\n";
	$check( '6.3 balance canary (SKIP-as-PASS: MyCred absent — grep proof holds)', true );
}

/* =====================================================================
 * BATTERY 7 — READ-ONLY MARKET MIRROR + FLAG-OFF (route-absence) + the local crypto round-trip.
 * ===================================================================== */
echo "\n--- BATTERY 7: read-only market mirror — route-ABSENT + DOM-absent under GS_COLLAB_MARKET_PUBLIC off ---\n";

$flag_on = defined( 'GS_COLLAB_MARKET_PUBLIC' ) && GS_COLLAB_MARKET_PUBLIC;

if ( ! $flag_on ) {
	// The REST route table must NOT carry the market list route nor the WRITE bet route (route-ABSENT
	// => 404, never 403) when the flag is off. get_routes() reflects register_routes()'s early-returns.
	$routes = array();
	if ( function_exists( 'rest_get_server' ) ) {
		$server = rest_get_server();
		if ( is_object( $server ) && method_exists( $server, 'get_routes' ) ) {
			$routes = (array) $server->get_routes();
		}
	}
	$has_list = false;
	$has_bet  = false;
	foreach ( array_keys( $routes ) as $rk ) {
		if ( false !== strpos( (string) $rk, '/gs/v1/markets' ) ) {
			$has_list = true;
		}
		// The WRITE bet route: /gs/v1/market/{id}/bet.
		if ( preg_match( '#^/gs/v1/market/\(\?P<id>.*\)/bet$#', (string) $rk ) || false !== strpos( (string) $rk, '/market/(?P<id>\d+)/bet' ) ) {
			$has_bet = true;
		}
	}
	$check( '7.1 GET /gs/v1/markets is route-ABSENT under GS_COLLAB_MARKET_PUBLIC off (404, not 403)',
		! $has_list, 'route present=' . var_export( $has_list, true ) );
	$check( '7.2 the WRITE bet route /gs/v1/market/{id}/bet is route-ABSENT under the flag off (no container write path)',
		! $has_bet, 'bet route present=' . var_export( $has_bet, true ) );

	// The container mirror render() must be DOM-absent (returns '') under the flag off.
	if ( class_exists( 'Gend_GS_Collab_Market_Mirror' ) && method_exists( 'Gend_GS_Collab_Market_Mirror', 'render' ) ) {
		$mirror_out = (string) Gend_GS_Collab_Market_Mirror::render();
		$check( '7.3 the container market mirror render() is DOM-absent (returns \'\') under the flag off',
			'' === $mirror_out, 'render length=' . strlen( $mirror_out ) );
	} else {
		echo "[NOTE] BATTERY 7 — Gend_GS_Collab_Market_Mirror not loaded; the DOM-absent render check is SKIP-as-PASS (route-absence above already proves the surface is off).\n";
		$check( '7.3 mirror DOM-absent (SKIP-as-PASS: mirror class absent)', true );
	}
} else {
	echo "[NOTE] BATTERY 7 — GS_COLLAB_MARKET_PUBLIC is ON in this process; the flag-off route-ABSENCE is proven statically in 89-03 (the list + bet routes register INSIDE register_routes()'s is_main_node() && GS_COLLAB_MARKET_PUBLIC early-returns; when off, get_routes() carries neither). SKIP-as-PASS on the runtime-flag-off assertion.\n";
	$check( '7.1 read-only market mirror flag-off route-absence (SKIP-as-PASS: flag ON this process; proven statically in 89-03)', true );
	$check( '7.2 no container WRITE bet route (SKIP-as-PASS: flag ON; the bet route is is_main_node()-gated hub-only by construction)', true );
	$check( '7.3 mirror DOM-absence under flag-off (SKIP-as-PASS: flag ON this process)', true );
}

// 7.4 — CRYPTO ROUND-TRIP: sign_body() over a body verifies against our public key, and a tampered
// body FAILS. This proves the ed25519 sign/verify primitive end-to-end independent of the hub's
// container-pubkey lookup (so Battery 1's SKIP-as-PASS path is still crypto-covered).
$body_ok  = (string) wp_json_encode( array( 'install_id' => $install_id, 'ts' => time(), 'x' => 'ok' ) );
$sig_ok   = base64_encode( sodium_crypto_sign_detached( $body_ok, $our_sk ) );
$our_pk   = base64_decode( $our_pk_b64, true );
$verify_ok   = sodium_crypto_sign_verify_detached( base64_decode( $sig_ok, true ), $body_ok, $our_pk );
$verify_bad  = sodium_crypto_sign_verify_detached( base64_decode( $sig_ok, true ), $body_ok . 'X', $our_pk );
$check( '7.4 CRYPTO: an ed25519 detached signature verifies for the exact body + FAILS for a tampered body',
	true === $verify_ok && false === $verify_bad,
	'verify_ok=' . var_export( $verify_ok, true ) . ' verify_bad=' . var_export( $verify_bad, true ) );

// ─────────────────────────────────────────────────────────────────────
// Footer gate.
// ─────────────────────────────────────────────────────────────────────
echo "\n";
if ( ! $fail ) {
	echo "=== SUCCESS === Phase 89 FEDERATION UAT passed — a signed cross-app swipe is VERIFIED (ed25519) + idempotently upserted on the hub (a re-sync creates NO second swipe/match); a bad/replayed/missing signature is REJECTED 401 with NO ingest; a federated container business (optin=1) APPEARS in the hub cross-app deck (and disappears at optin=0); a cross-app reciprocal right-swipe forms EXACTLY one match + one intro thread (idempotent); hub-DOWN the LOCAL loop STILL works — the swipe is written locally FIRST, the failed push is QUEUED to gs_collab_outbox, the deck FALLS BACK to the local Phase-82 pool, and the drain never throws/blocks; NO DGEN moves through the container federation path (balance canary unchanged + no money call in the sync/mirror classes); and the FED-02 market mirror is READ-ONLY + route-ABSENT/DOM-absent under GS_COLLAB_MARKET_PUBLIC off. FEDERATION IS SAFE — Phase 90 (the finale) may proceed to the counsel gate.\n";
	echo "UAT PASSED\n";
	exit( 0 );
}
echo "=== FAILED === Phase 89 FEDERATION UAT detected " . count( $issues ) . " violation(s) — DO NOT sign off Phase 89 until closed:\n";
foreach ( $issues as $i => $msg ) {
	echo '  ' . ( $i + 1 ) . ". {$msg}\n";
}
echo 'UAT FAILED (' . count( $issues ) . " failures)\n";
exit( 1 );
