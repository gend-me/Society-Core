<?php
/**
 * gend-society — GenD Match cross-app federation sync (Phase 89-02, FED-01, v12.0).
 *
 * ONE self-gating class wiring the whole FED-01 rail, both sides, over the existing
 * `gend-pm-sync/v1` ed25519 signed-REST transport (re-implemented locally in 89-01's
 * Gend_GS_Collab_Sync_Crypto — NO edit to contracts-and-payments). Which side runs is
 * decided by is_hub() (mirrors class-collab-resolver.php:403-407 VERBATIM):
 *
 *   HUB (is_hub()):
 *     - register_receive()      — POST gend-pm-sync/v1/collab/swipe (cross-plugin route
 *                                 registration under the c-and-p rail namespace, proven at
 *                                 class-pm-sync-push.php:66-83; auth is the ed25519 sig
 *                                 verified INSIDE the callback, __return_true perm).
 *     - rest_receive_swipe()    — verify_container_request (bad/replayed sig -> 401/400, no
 *                                 ingest) -> stamp origin (install_id) -> IDEMPOTENT
 *                                 record_swipe (INSERT IGNORE on UNIQUE(from,to) = stable
 *                                 event id for free; re-sync is a no-op) -> Phase-83
 *                                 maybe_create_match on the hub (cross-app mutual match).
 *
 *   CONTAINER (! is_hub()):
 *     - on_local_swipe()        — the `gend_gs_collab_swiped` subscriber, fired AFTER the
 *                                 LOCAL record_swipe in route_swipe (P2: the local ledger
 *                                 is ALWAYS written first/unconditionally; this is a
 *                                 downstream best-effort mirror). Wrapped in try/catch so a
 *                                 throw NEVER propagates to the swipe response.
 *     - push_swipe()            — sign_body + FIRE-AND-FORGET wp_remote_post (timeout 0.5,
 *                                 blocking=false — a 10-25s hub cold-start never appears in
 *                                 the swipe response; memory: project_hub_probe_timeouts);
 *                                 on can't-sign / no-hub / WP_Error -> enqueue_outbox.
 *     - enqueue_outbox()        — INSERT IGNORE into gs_collab_outbox (UNIQUE(event_id) =>
 *                                 re-enqueue idempotent).
 *     - drain_outbox()          — LIMIT 50 due rows re-signed + re-POSTed non-blocking on
 *                                 the EXISTING gs_fifteen_min cadence (scheduled from the
 *                                 resolver's init_container(); NO new cron interval). Since
 *                                 ingest is idempotent, a re-send is harmless. Backoff +
 *                                 give-up after 8 attempts.
 *
 * P2 (load-bearing, non-negotiable): federation is NEVER on the critical path of the local
 * loop. The local swipe/match keeps working with the hub down/cold/unreachable/bad-sig —
 * push is sign-or-queue + non-blocking + WP_Error->queue; the cross-app deck falls back to
 * the LOCAL Phase-82 deck (see class-collab-deck.php).
 *
 * Every cross-class call (Gend_GS_Collab_Sync_Crypto, Gend_GS_Collab_Schema,
 * Gend_GS_Collab_Match, Gend_CP_App_Identity, AIPA_GenD_OAuth) is class_exists /
 * method_exists-guarded so a partial deploy degrades cleanly — NEVER a fatal
 * (memory: project_wp_fatal_auto_deactivation).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gend_GS_Collab_Sync {

	/** The signed-REST namespace + path (mirrors class-pm-sync-push.php:34 HUB_PATH). */
	const REST_NS   = 'gend-pm-sync/v1';
	const SWIPE_EP  = '/collab/swipe';

	/** Outbox retry cap — after this many failed drains a row is dropped. */
	const MAX_ATTEMPTS = 8;

	/**
	 * Hub-vs-container gate. Copied VERBATIM from class-collab-resolver.php:403-407 /
	 * market-rest.php — true on the main node (or a lone hub with no OAuth resource),
	 * false on a container. RECEIVE + hub match run under is_hub(); PUSH + outbox drain
	 * run under ! is_hub().
	 *
	 * @return bool
	 */
	private static function is_hub() : bool {
		return ! class_exists( 'Gend_CP_OAuth_Resource' )
			|| ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
			|| Gend_CP_OAuth_Resource::is_main_node();
	}

	/* ───────────────────────────── HUB: receive ───────────────────────────── */

	/**
	 * Register the hub RECEIVE route under the existing gend-pm-sync/v1 namespace
	 * (cross-plugin registration is proven at class-pm-sync-push.php:66-83). Self-gates
	 * on is_hub() — a container never exposes the receive route. Auth is the ed25519 sig
	 * verified INSIDE the callback (mirrors class-pm-sync.php's __return_true + in-body
	 * verify), so permission_callback is __return_true.
	 *
	 * Bound from gend-society.php on rest_api_init.
	 */
	public static function register_receive() : void {
		if ( ! self::is_hub() ) {
			return;
		}
		register_rest_route(
			self::REST_NS,
			self::SWIPE_EP,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_receive_swipe' ),
				'permission_callback' => '__return_true', // auth = ed25519 sig, verified in-callback.
			)
		);
	}

	/**
	 * POST gend-pm-sync/v1/collab/swipe — the hub receive leg. Verifies the ed25519
	 * signature (600s replay via the 89-01 crypto helper), resolves the container's
	 * matchable hub group, idempotently records the swipe, and runs the Phase-83
	 * cross-app mutual match on the hub.
	 *
	 * @param WP_REST_Request $req Signed inbound request.
	 * @return WP_REST_Response
	 */
	public static function rest_receive_swipe( WP_REST_Request $req ) {
		if ( ! self::is_hub() ) {
			return new WP_REST_Response( array( 'error' => 'not a hub' ), 404 );
		}
		if ( ! class_exists( 'Gend_GS_Collab_Sync_Crypto' ) ) {
			return new WP_REST_Response( array( 'error' => 'crypto helper unavailable' ), 500 );
		}

		// Verify: bad/replayed/malformed sig -> the crypto helper's 400/401/500 ladder.
		// NO swipe/match is created on a rejected request.
		$v = Gend_GS_Collab_Sync_Crypto::verify_container_request( $req );
		if ( $v instanceof WP_REST_Response ) {
			return $v;
		}

		$p          = isset( $v['payload'] ) && is_array( $v['payload'] ) ? $v['payload'] : array();
		$install_id = isset( $v['install_id'] ) ? (string) $v['install_id'] : '';

		// Resolve the matchable HUB group for this container swipe (origin stamped via
		// install_id). A bogus resolution (<=0) 400s — never a bad match.
		$from = self::hub_group_for_install( $install_id, (int) ( $p['from_group'] ?? 0 ) );
		$to   = (int) ( $p['to_group'] ?? 0 );
		$dec  = ( isset( $p['decision'] ) && 'right' === $p['decision'] ) ? 'right' : 'left';

		if ( $from <= 0 || $to <= 0 || $from === $to ) {
			return new WP_REST_Response( array( 'error' => 'unresolved or invalid group pair' ), 400 );
		}
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) ) {
			return new WP_REST_Response( array( 'error' => 'collab schema unavailable' ), 500 );
		}

		// IDEMPOTENT INGEST — INSERT IGNORE on UNIQUE(from,to); a re-synced pair is a no-op
		// (the (from,to) pair IS the stable event id). Never a new row, never a flipped
		// decision (class-collab-schema.php:532).
		Gend_GS_Collab_Schema::record_swipe( $from, $to, $dec, (int) ( $p['actor'] ?? 0 ) );

		// Cross-app mutual match on the hub (Phase-83 spine, doc-flagged reusable by 89).
		// INSERT IGNORE on UNIQUE(group_a,group_b); side-effects fire only on the winner.
		$matched = false;
		if ( 'right' === $dec && class_exists( 'Gend_GS_Collab_Match' ) ) {
			$match_id = Gend_GS_Collab_Match::maybe_create_match( $from, $to );
			$matched  = ( (int) $match_id > 0 );
		}

		return rest_ensure_response( array( 'ok' => true, 'matched' => $matched ) );
	}

	/**
	 * Resolve the matchable HUB group id for a verified container swipe. Prefer a
	 * federated-business shadow row (install_id + remote_group_id -> its hub_group_id);
	 * else fall back to the container's linked hub group via the WP-Ultimo self-hosted
	 * site row. Return 0 -> the caller 400s (never a bogus match). All lookups guarded.
	 *
	 * @param string $install_id   The container's federation identity.
	 * @param int    $remote_group The business group id on the container (payload from_group).
	 * @return int Matchable hub group id, or 0 if unresolvable.
	 */
	public static function hub_group_for_install( string $install_id, int $remote_group ) : int {
		global $wpdb;

		$install_id   = trim( (string) $install_id );
		$remote_group = (int) $remote_group;
		if ( '' === $install_id ) {
			return 0;
		}

		// 1) Federated-business shadow row (contribute-up) -> its bound hub group.
		if ( $remote_group > 0 && class_exists( 'Gend_GS_Collab_Schema' )
			&& method_exists( 'Gend_GS_Collab_Schema', 'federated_business_table' ) ) {
			$fb   = Gend_GS_Collab_Schema::federated_business_table();
			$hub_group = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT hub_group_id FROM {$fb} WHERE install_id = %s AND remote_group_id = %d LIMIT 1",
					$install_id,
					$remote_group
				)
			);
			if ( $hub_group > 0 ) {
				return $hub_group;
			}
		}

		// 2) Fall back to the container's linked hub group via the self-hosted site row.
		$helper_class = class_exists( '\WP_Ultimo\Helpers\Self_Hosted_Helper' )
			? '\WP_Ultimo\Helpers\Self_Hosted_Helper'
			: ( class_exists( 'Self_Hosted_Helper' ) ? 'Self_Hosted_Helper' : null );
		if ( $helper_class && method_exists( $helper_class, 'find_site_by_install_id' ) ) {
			$site = $helper_class::find_site_by_install_id( $install_id );
			if ( $site && method_exists( $site, 'get_meta' ) ) {
				$bound = (int) $site->get_meta( 'gdc_bp_group_id', 0 );
				if ( $bound > 0 ) {
					return $bound;
				}
			}
		}

		return 0;
	}

	/* ─────────────────────────── CONTAINER: push ─────────────────────────── */

	/**
	 * Subscriber for `gend_gs_collab_swiped` — fired AFTER the LOCAL record_swipe in
	 * Gend_GS_Collab_REST::route_swipe (P2: the local ledger is ALWAYS written first,
	 * unconditionally; this only ever mirrors it upstream). No-ops on the hub (the hub
	 * doesn't push to itself). Wrapped in try/catch so NOTHING here can propagate to the
	 * swipe HTTP response.
	 *
	 * @param int    $from     Local acting group id.
	 * @param int    $to       Target group id.
	 * @param string $decision 'right' | 'left'.
	 * @param int    $actor    Acting user id.
	 */
	public static function on_local_swipe( int $from, int $to, string $decision, int $actor ) : void {
		if ( self::is_hub() ) {
			return; // the hub records locally; it never pushes to itself.
		}
		try {
			if ( ! class_exists( 'Gend_GS_Collab_Sync_Crypto' ) ) {
				return;
			}
			$install_id = Gend_GS_Collab_Sync_Crypto::install_id();
			if ( '' === $install_id ) {
				return; // unprovisioned container — nothing to push as.
			}
			$payload = array(
				'install_id' => $install_id,
				'from_group' => (int) $from, // the container's LOCAL business group id.
				'to_group'   => (int) $to,
				'decision'   => ( 'right' === $decision ) ? 'right' : 'left',
				'actor'      => (int) $actor,
				'event_id'   => $install_id . ':' . (int) $from . ':' . (int) $to, // stable.
				'ts'         => time(),
			);
			self::push_swipe( $payload );
		} catch ( \Throwable $e ) {
			// P2: a federation failure can NEVER surface on the local swipe. Swallow.
			return;
		}
	}

	/**
	 * Fire-and-forget push of one signed swipe to the hub. Mirrors the rail transport
	 * shape VERBATIM (class-pm-sync-push.php:508-516: timeout 0.5, blocking=false,
	 * X-Gend-Sig). Can't-sign / no-hub / WP_Error -> enqueue to the outbox (drained on
	 * gs_fifteen_min). Non-blocking means a late failure can't be reported here — the
	 * drain re-sends any stale row; idempotent ingest makes a double-send harmless.
	 *
	 * @param array $payload The swipe payload (already carries install_id + event_id + ts).
	 */
	public static function push_swipe( array $payload ) : void {
		if ( self::is_hub() || ! class_exists( 'Gend_GS_Collab_Sync_Crypto' ) ) {
			return;
		}
		$body = wp_json_encode( $payload );
		$sig  = Gend_GS_Collab_Sync_Crypto::sign_body( (string) $body );
		$hub  = self::hub_url();
		if ( null === $sig || '' === $hub ) {
			// Can't sign yet or no hub configured — queue for the drain.
			self::enqueue_outbox( self::SWIPE_EP, $payload );
			return;
		}

		$r = wp_remote_post(
			$hub . '/wp-json/' . self::REST_NS . self::SWIPE_EP,
			array(
				'timeout'     => 0.5,
				'blocking'    => false,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'                          => 'application/json',
					Gend_GS_Collab_Sync_Crypto::SIG_HEADER => $sig,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $r ) ) {
			// blocking=false can't report a late failure — a synchronous WP_Error still can.
			self::enqueue_outbox( self::SWIPE_EP, $payload );
		}
	}

	/**
	 * Enqueue a failed/undeliverable push to gs_collab_outbox. INSERT IGNORE on
	 * UNIQUE(event_id) => re-enqueue is idempotent. next_try_at = now + 5min.
	 *
	 * @param string $endpoint Rail endpoint (e.g. '/collab/swipe').
	 * @param array  $payload  The exact payload to (re-)POST.
	 */
	public static function enqueue_outbox( string $endpoint, array $payload ) : void {
		global $wpdb;
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) || ! method_exists( 'Gend_GS_Collab_Schema', 'outbox_table' ) ) {
			return;
		}
		$event_id = isset( $payload['event_id'] ) ? (string) $payload['event_id'] : '';
		if ( '' === $event_id ) {
			return;
		}
		$now = time();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . Gend_GS_Collab_Schema::outbox_table()
				. " ( event_id, endpoint, payload_json, attempts, next_try_at, created_at )"
				. " VALUES ( %s, %s, %s, 0, %d, %d )",
				$event_id,
				$endpoint,
				(string) wp_json_encode( $payload ),
				$now + 300,
				$now
			)
		);
	}

	/**
	 * Drain due outbox rows — the container-side retry, scheduled on the EXISTING
	 * gs_fifteen_min cadence from Gend_GS_Collab_Resolver::init_container() (NO new cron
	 * interval). LIMIT 50 per tick (mirrors the sweep's cold-start batching,
	 * class-collab-resolver.php:260). Re-sign + re-POST non-blocking; on a synchronous
	 * WP_Error back off (next_try_at) and give up after MAX_ATTEMPTS. Since ingest is
	 * idempotent, a re-POST is harmless.
	 *
	 * Bound to gs_collab_outbox_drain by the resolver on a container.
	 */
	public static function drain_outbox() : void {
		global $wpdb;
		if ( self::is_hub() ) {
			return; // the outbox lives on containers only.
		}
		if ( ! class_exists( 'Gend_GS_Collab_Schema' ) || ! method_exists( 'Gend_GS_Collab_Schema', 'outbox_table' )
			|| ! class_exists( 'Gend_GS_Collab_Sync_Crypto' ) ) {
			return;
		}
		$tbl = Gend_GS_Collab_Schema::outbox_table();
		$now = time();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_id, endpoint, payload_json, attempts FROM {$tbl}"
				. " WHERE next_try_at <= %d ORDER BY next_try_at ASC LIMIT 50",
				$now
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$hub = self::hub_url();
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$attempts = (int) $row['attempts'];
			$payload  = json_decode( (string) $row['payload_json'], true );
			$endpoint = (string) $row['endpoint'];

			// Unparseable / give-up rows are dropped so the queue can't wedge.
			if ( ! is_array( $payload ) || $attempts >= self::MAX_ATTEMPTS ) {
				$wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
				continue;
			}

			$body = wp_json_encode( $payload );
			$sig  = Gend_GS_Collab_Sync_Crypto::sign_body( (string) $body );
			if ( null === $sig || '' === $hub ) {
				self::backoff( $tbl, $id, $attempts );
				continue;
			}

			$r = wp_remote_post(
				$hub . '/wp-json/' . self::REST_NS . $endpoint,
				array(
					'timeout'     => 0.5,
					'blocking'    => false,
					'redirection' => 0,
					'headers'     => array(
						'Content-Type'                          => 'application/json',
						Gend_GS_Collab_Sync_Crypto::SIG_HEADER => $sig,
					),
					'body'        => $body,
				)
			);
			if ( is_wp_error( $r ) ) {
				self::backoff( $tbl, $id, $attempts );
			} else {
				// Non-blocking POST dispatched — idempotent ingest makes a stray double-send
				// harmless, so clear the row optimistically.
				$wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Bump attempts + push next_try_at out with a capped exponential backoff.
	 *
	 * @param string $tbl      Outbox table name.
	 * @param int    $id       Row id.
	 * @param int    $attempts Current attempt count.
	 */
	private static function backoff( string $tbl, int $id, int $attempts ) : void {
		global $wpdb;
		$attempts = $attempts + 1;
		$delay    = min( (int) pow( 2, $attempts ), 60 ) * MINUTE_IN_SECONDS; // cap at 60 min.
		$wpdb->update(
			$tbl,
			array(
				'attempts'    => $attempts,
				'next_try_at' => time() + $delay,
			),
			array( 'id' => $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/* ─────────────────────────────── helpers ─────────────────────────────── */

	/**
	 * The container's linked HUB group id (Gend_CP_App_Identity::linked_group()['id']).
	 * Guarded — 0 if the identity plugin is absent.
	 *
	 * @return int
	 */
	public static function linked_hub_group() : int {
		if ( ! class_exists( 'Gend_CP_App_Identity' ) || ! method_exists( 'Gend_CP_App_Identity', 'linked_group' ) ) {
			return 0;
		}
		$g = Gend_CP_App_Identity::linked_group();
		return is_array( $g ) ? (int) ( $g['id'] ?? 0 ) : 0;
	}

	/**
	 * The hub base URL for this container. Mirrors class-pm-sync-push.php:262-267
	 * VERBATIM: AIPA_GenD_OAuth::hub_url() (guarded) else the filtered gend.me default.
	 *
	 * @return string Hub base (no trailing slash), or '' if unresolvable.
	 */
	public static function hub_url() : string {
		if ( class_exists( 'AIPA_GenD_OAuth' ) && method_exists( 'AIPA_GenD_OAuth', 'hub_url' ) ) {
			return rtrim( (string) AIPA_GenD_OAuth::hub_url(), '/' );
		}
		return rtrim( (string) apply_filters( 'gend_cp_pm_sync_hub_url', 'https://gend.me' ), '/' );
	}
}
