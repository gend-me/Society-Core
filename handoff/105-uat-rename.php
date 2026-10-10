<?php
/**
 * Phase 105 UAT: prefix rename + key migration + compat (hub and container).
 *
 *   GS105_SECTION=hub|container \
 *   GS105_BEFORE=/tmp/<inventory-before>.json   (optional: 105-key-inventory.php output taken on 1.1.x)
 *   GS105_MAP=/tmp/rename-map.json              (bin/rename-map.json; bin/ never ships)
 *   GS105_DISPOSABLE=1                          (container only, DISPOSABLE site only: see below)
 *   wp --allow-root --path=/var/www/html [--url=<site>] eval-file /tmp/105-uat-rename.php
 *
 * 105-key-inventory.php must sit next to this file (it is loaded as a library).
 * Prints PASS / FAIL / SKIP / INFO lines and ends with "ALL PASS" or "FAILED: <n>"
 * (exit status 1 on FAILED). A foreign row changed by its owner is INFO, not FAIL:
 * see gs105u_foreign_class().
 *
 * Writes: none, except
 *  - update_option( 'gs_gend_pubkey', <its current value> ) to prove the bridge
 *    writes through (same value: nothing changes);
 *  - with GS105_DISPOSABLE=1 (container section): a TEST public key is stored as
 *    gend_society_gend_pubkey for the signed-request round trip and the original
 *    value is restored right after. Never set GS105_DISPOSABLE on a real site.
 * Everything else (remove_action simulation, AJAX mirroring) lives only in this
 * process.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

define( 'GS105_INVENTORY_LIB', true );
require_once __DIR__ . '/105-key-inventory.php';

if ( ! function_exists( 'gs105u_foreign_class' ) ) {
	/**
	 * Classify one foreign inventory row ("<kind>:<name>") for the BEFORE/AFTER comparison.
	 *
	 * 'foreign': the rename map lists it with new:null and an owner other than gend-society,
	 * gend-society code never writes or deletes it, and the key map cannot reach it. A change
	 * to such a row was made by its owner, never by the migration.
	 * 'strict': everything else (a change is a FAIL).
	 *
	 * @param string     $fk     Inventory key, e.g. "user_meta:_gs_gdrive_access_token".
	 * @param array|null $map    Decoded bin/rename-map.json.
	 * @param array|null $keymap Loaded inc/bootstrap/key-map.php (gs105_inv_keymap()).
	 * @return array{class:string, owner:string, why:string}
	 */
	function gs105u_foreign_class( $fk, $map, $keymap ) {
		$parts = explode( ':', (string) $fk, 2 );
		if ( 2 !== count( $parts ) ) {
			return array( 'class' => 'strict', 'owner' => '', 'why' => 'malformed inventory key' );
		}
		list( $kind, $name ) = $parts;
		if ( ! is_array( $keymap ) ) {
			return array( 'class' => 'strict', 'owner' => '', 'why' => 'key map not loaded' );
		}
		// 1. Can the migration reach this name? It only writes names taken from the key map.
		foreach ( $keymap as $section => $pairs ) {
			if ( ! is_array( $pairs ) ) {
				continue;
			}
			$is_prefix = '_prefix' === substr( (string) $section, -7 );
			foreach ( $pairs as $old => $new ) {
				foreach ( array( (string) $old, (string) $new ) as $n ) {
					if ( '' === $n ) {
						continue;
					}
					if ( $n === $name || ( $is_prefix && 0 === strpos( $name, $n ) ) ) {
						return array( 'class' => 'strict', 'owner' => '', 'why' => "key map $section reaches it ($n)" );
					}
				}
			}
		}
		// 2. Who owns it? Only the rename map says.
		if ( ! is_array( $map ) || empty( $map['entries'] ) ) {
			return array( 'class' => 'strict', 'owner' => '', 'why' => 'no rename map' );
		}
		foreach ( $map['entries'] as $e ) {
			if ( ! isset( $e['old'], $e['kind'] ) || $e['old'] !== $name || $e['kind'] !== $kind ) {
				continue;
			}
			$owner = isset( $e['owner'] ) ? (string) $e['owner'] : '';
			if ( ! empty( $e['new'] ) ) {
				return array( 'class' => 'strict', 'owner' => $owner, 'why' => 'renamed by the map (new ' . $e['new'] . ')' );
			}
			if ( '' === $owner || false !== stripos( $owner, 'gend-society' ) ) {
				return array( 'class' => 'strict', 'owner' => $owner, 'why' => 'owner is gend-society or unknown' );
			}
			foreach ( (array) ( isset( $e['at'] ) ? $e['at'] : array() ) as $at ) {
				if ( preg_match( '/\s(write|delete)$/', (string) $at ) ) {
					return array( 'class' => 'strict', 'owner' => $owner, 'why' => 'gend-society code writes it (' . $at . ')' );
				}
			}
			return array( 'class' => 'foreign', 'owner' => $owner, 'why' => 'new:null in the rename map, not in the key map' );
		}
		return array( 'class' => 'strict', 'owner' => '', 'why' => "not in the rename map as $kind" );
	}
}
if ( defined( 'GS105U_CLASSIFY_ONLY' ) ) {
	return; // Library use: only gs105u_foreign_class() (read-only classification report).
}

$gs105u_fails   = 0;
$gs105u_section = (string) getenv( 'GS105_SECTION' );
$gs105u_ok      = function ( $cond, $name, $detail = '' ) use ( &$gs105u_fails ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
	if ( ! $cond ) {
		++$gs105u_fails;
	}
};
$gs105u_skip    = function ( $name, $why ) {
	echo 'SKIP ' . $name . ' (' . $why . ")\n";
};

if ( ! in_array( $gs105u_section, array( 'hub', 'container' ), true ) ) {
	echo "set GS105_SECTION=hub|container\n";
	exit( 2 );
}
echo "== 105 UAT section {$gs105u_section} on " . home_url( '/' ) . "\n";

$gs105u_before = null;
$gs105u_bpath  = (string) getenv( 'GS105_BEFORE' );
if ( '' !== $gs105u_bpath ) {
	$gs105u_before = json_decode( (string) file_get_contents( $gs105u_bpath ), true );
	$gs105u_ok( is_array( $gs105u_before ) && isset( $gs105u_before['counts'] ), 'BEFORE inventory readable', $gs105u_bpath );
	if ( ! is_array( $gs105u_before ) || ! isset( $gs105u_before['counts'] ) ) {
		$gs105u_before = null;
	}
}
$gs105u_map   = null;
$gs105u_mpath = (string) getenv( 'GS105_MAP' );
if ( '' !== $gs105u_mpath ) {
	$gs105u_map = json_decode( (string) file_get_contents( $gs105u_mpath ), true );
}
$gs105u_ok( is_array( $gs105u_map ) && ! empty( $gs105u_map['entries'] ), 'rename map readable (GS105_MAP)', $gs105u_mpath );

// ── Version + runtime ──
$gs105u_ok( defined( 'GEND_SOCIETY_VERSION' ) && '1.2.0' === GEND_SOCIETY_VERSION, 'GEND_SOCIETY_VERSION is 1.2.0', defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : 'undefined' );
$gs105u_ok( defined( 'GS_VERSION' ) && defined( 'GEND_SOCIETY_VERSION' ) && GS_VERSION === GEND_SOCIETY_VERSION, 'GS_VERSION compat constant equals GEND_SOCIETY_VERSION (full build)' );
$gs105u_mode = function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : null;
$gs105u_ok( $gs105u_section === $gs105u_mode, 'runtime mode is ' . $gs105u_section, (string) $gs105u_mode );

// ── Flags + log ──
$gs105u_ver = defined( 'GEND_SOCIETY_KEYMAP_VERSION' ) ? GEND_SOCIETY_KEYMAP_VERSION : null;
$gs105u_ok( null !== $gs105u_ver, 'GEND_SOCIETY_KEYMAP_VERSION defined', (string) $gs105u_ver );
$gs105u_ok( null !== $gs105u_ver && get_option( 'gend_society_keys_migrated' ) === $gs105u_ver, 'per-blog flag gend_society_keys_migrated = map version' );
$gs105u_ok( null !== $gs105u_ver && get_site_option( 'gend_society_network_keys_migrated' ) === $gs105u_ver, 'network flag gend_society_network_keys_migrated = map version' );
$gs105u_now = gs105_inventory();
$gs105u_ok( empty( $gs105u_now['error'] ), 'inventory built', isset( $gs105u_now['error'] ) ? $gs105u_now['error'] : '' );
$gs105u_log  = get_option( 'gend_society_key_migration_log' );
$gs105u_nlog = get_site_option( 'gend_society_network_key_migration_log' );
$gs105u_ok( is_array( $gs105u_log ) && isset( $gs105u_log['at'] ), 'per-blog migration log present' );
$gs105u_ok( is_array( $gs105u_log ) && 0 === (int) ( $gs105u_log['cron_failed'] ?? 0 ), 'no cron event failed to move' );
if ( $gs105u_before && is_array( $gs105u_log ) ) {
	foreach ( array( 'option', 'option_prefix', 'post_meta', 'cron' ) as $k ) {
		$gs105u_ok( (int) $gs105u_log[ $k ] === (int) $gs105u_before['counts'][ $k ], "log $k count equals BEFORE old-key count", $gs105u_log[ $k ] . ' vs ' . $gs105u_before['counts'][ $k ] );
	}
	if ( ! empty( $gs105u_now['network_data'] ) && is_array( $gs105u_nlog ) ) {
		foreach ( array( 'site_option', 'user_meta', 'user_meta_prefix', 'group_meta' ) as $k ) {
			$gs105u_ok( (string) $gs105u_nlog[ $k ] === (string) $gs105u_before['counts'][ $k ], "network log $k count equals BEFORE old-key count", $gs105u_nlog[ $k ] . ' vs ' . $gs105u_before['counts'][ $k ] );
		}
	}
} else {
	$gs105u_skip( 'log counts vs BEFORE', 'no GS105_BEFORE' );
}
if ( function_exists( 'gend_society_migrate_blog_keys' ) && is_array( $gs105u_log ) ) {
	$gs105u_r = gend_society_migrate_blog_keys();
	$gs105u_l = get_option( 'gend_society_key_migration_log' );
	$gs105u_ok( null === $gs105u_r && $gs105u_l['at'] === $gs105u_log['at'], 're-running gend_society_migrate_blog_keys() is a no-op (log at unchanged)' );
} else {
	$gs105u_ok( false, 'gend_society_migrate_blog_keys() available' );
}

// ── Values: new == BEFORE old, foreign unchanged, old rows kept ──
// Cache-like keys (and the plugin-version-keyed rewrite marker) are rewritten by normal
// traffic; a changed value there is INFO, not FAIL.
$gs105u_volatile = '/(_cache|_cache_expires|_connected_at|_relay_state|_service_state|_invite_pending|_support_grant_|_rewrite_ver)/';
if ( $gs105u_before ) {
	$gs105u_cmp = function ( $kind, $old, $before_md5, $now_new_md5, $now_old_present ) use ( $gs105u_ok, $gs105u_volatile ) {
		if ( null === $before_md5 ) {
			return;
		}
		if ( $before_md5 !== $now_new_md5 && preg_match( $gs105u_volatile, $old ) ) {
			echo "INFO $kind $old: new value differs from BEFORE (cache-like key, rewritten by traffic)\n";
		} else {
			$gs105u_ok( $before_md5 === $now_new_md5, "$kind $old: new key md5 equals BEFORE old md5" );
		}
		$gs105u_ok( $now_old_present, "$kind $old: old row still present (copy-not-move)" );
	};
	foreach ( (array) $gs105u_before['option'] as $old => $b ) {
		$n = isset( $gs105u_now['option'][ $old ] ) ? $gs105u_now['option'][ $old ] : null;
		$gs105u_cmp( 'option', $old, $b['old_row'] ? $b['old_row']['md5'] : null, $n && $n['new_row'] ? $n['new_row']['md5'] : null, $n && $n['old_row'] );
		if ( $b['old_row'] && $n && $n['new_row'] ) {
			$al = function ( $a ) {
				return in_array( $a, array( 'no', 'off', 'auto-off' ), true ) ? 'off' : ( in_array( $a, array( 'yes', 'on', 'auto-on' ), true ) ? 'on' : $a );
			};
			$gs105u_ok( $al( $b['old_row']['autoload'] ) === $al( $n['new_row']['autoload'] ), "option $old: autoload preserved", $b['old_row']['autoload'] . ' -> ' . $n['new_row']['autoload'] );
		}
	}
	foreach ( array( 'post_meta', 'user_meta', 'group_meta' ) as $kind ) {
		foreach ( (array) ( isset( $gs105u_before[ $kind ] ) ? $gs105u_before[ $kind ] : array() ) as $old => $b ) {
			$n = isset( $gs105u_now[ $kind ][ $old ] ) ? $gs105u_now[ $kind ][ $old ] : null;
			$gs105u_cmp( $kind, $old, $b['old_rows'] ? $b['old_rows']['md5'] : null, $n && $n['new_rows'] ? $n['new_rows']['md5'] : null, $n && $n['old_rows'] );
		}
	}
	foreach ( (array) ( isset( $gs105u_before['site_option'] ) ? $gs105u_before['site_option'] : array() ) as $old => $b ) {
		$n = isset( $gs105u_now['site_option'][ $old ] ) ? $gs105u_now['site_option'][ $old ] : null;
		$gs105u_cmp( 'site_option', $old, $b['old_row'] ? $b['old_row']['md5'] : null, $n && $n['new_row'] ? $n['new_row']['md5'] : null, $n && $n['old_row'] );
	}
	// Foreign rows. The migration's promise is that it never WRITES another plugin's rows,
	// not that their owners stop writing them (gend-media-optimizer refreshes a Drive token
	// hourly). A changed foreign row is INFO only when all of these hold:
	// - the rename map (GS105_MAP) lists it with new:null and an owner other than
	// gend-society, and no gend-society code path writes or deletes it;
	// - the loaded key map (the migration's only source of names to write) cannot reach it,
	// neither as an old/new name nor through a prefix.
	// Anything else (unmapped, gend-society-owned, written by gend-society, reachable from the
	// key map) stays a strict FAIL.
	$gs105u_km = gs105_inv_keymap();
	foreach ( (array) $gs105u_before['foreign'] as $k => $md5 ) {
		$now_md5 = array_key_exists( $k, $gs105u_now['foreign'] ) ? $gs105u_now['foreign'][ $k ] : false;
		if ( false !== $now_md5 && $now_md5 === $md5 ) {
			$gs105u_ok( true, "foreign row $k unchanged" );
			continue;
		}
		$cls = gs105u_foreign_class( $k, $gs105u_map, $gs105u_km );
		$chg = ( null === $md5 ? 'absent' : $md5 ) . ' -> ' . ( false === $now_md5 ? 'not inventoried' : ( null === $now_md5 ? 'absent' : $now_md5 ) );
		if ( 'foreign' === $cls['class'] && false !== $now_md5 ) {
			echo "INFO foreign row $k changed by its owner ({$cls['owner']}): md5 $chg; the migration cannot write it ({$cls['why']})\n";
		} else {
			$gs105u_ok( false, "foreign row $k unchanged", 'changed: ' . $chg . '; strict: ' . $cls['why'] );
		}
	}
	// Cron: same number of events and the same schedule/args set per renamed hook; no old-hook events.
	foreach ( (array) $gs105u_before['cron'] as $old => $b ) {
		$n = isset( $gs105u_now['cron'][ $old ] ) ? $gs105u_now['cron'][ $old ] : null;
		$gs105u_ok( $n && $n['new_events']['count'] === $b['old']['count'] && $n['new_events']['md5'] === $b['old']['md5'], "cron $old -> {$b['new']}: same events/args as BEFORE", $n ? $n['new_events']['count'] . ' vs ' . $b['old']['count'] : 'missing' );
	}
} else {
	$gs105u_skip( 'value / foreign / cron comparison against BEFORE', 'no GS105_BEFORE' );
}
$gs105u_ok( empty( $gs105u_now['kept_new_names_present'] ), 'kept (operator/foreign) options gained no renamed copy', implode( ',', (array) $gs105u_now['kept_new_names_present'] ) );
foreach ( (array) $gs105u_now['cron'] as $old => $c ) {
	$gs105u_ok( 0 === $c['old']['count'], "cron: no events left on old hook $old", (string) $c['old']['count'] );
}

// ── Compat ──
if ( is_array( $gs105u_map ) && ! empty( $gs105u_map['entries'] ) ) {
	if ( function_exists( 'gend_society_compat_mirror_actions' ) ) {
		gend_society_compat_mirror_actions(); // admin_init callback; admin_init does not fire under wp eval.
	}
	$gs105u_ajax_skipped = 0;
	foreach ( $gs105u_map['entries'] as $e ) {
		if ( empty( $e['new'] ) ) {
			continue;
		}
		$old = $e['old'];
		$ext = ! empty( $e['external'] );
		switch ( $e['kind'] ) {
			case 'function':
				if ( $ext ) {
					// A module that is not loaded in this context (e.g. group tabs on a subsite
					// without BuddyPress groups) defines neither name; 1.1.x did not define the
					// old name there either.
					$gs105u_ok( function_exists( $old ) || ! function_exists( $e['new'] ), "compat function $old()", function_exists( $e['new'] ) ? '' : 'new function not loaded in this context' );
				}
				break;
			case 'class':
				if ( $ext ) {
					$gs105u_ok( class_exists( $old ) || ! class_exists( $e['new'] ), "compat class $old", class_exists( $e['new'] ) ? '' : 'new class not loaded in this context' );
				}
				break;
			case 'constant':
				if ( $ext ) {
					$gs105u_ok( defined( $old ), "compat constant $old" );
				}
				break;
			case 'ajax':
			case 'admin_post':
				$pre = 'ajax' === $e['kind'] ? array( 'wp_ajax_', 'wp_ajax_nopriv_' ) : array( 'admin_post_', 'admin_post_nopriv_' );
				foreach ( $pre as $p ) {
					if ( false === has_action( $p . $e['new'] ) ) {
						continue;
					}
					$gs105u_ok( false !== has_action( $p . $old ), "compat action {$p}{$old} has callbacks" );
					continue 3;
				}
				++$gs105u_ajax_skipped; // new name not registered in a CLI request (admin-only registration).
				break;
			case 'option':
				if ( $ext && empty( $e['prefix'] ) && null !== gs105_inv_option_row( $e['new'] ) ) {
					$gs105u_ok( get_option( $old ) === get_option( $e['new'] ), "option bridge get_option( '$old' ) === get_option( '{$e['new']}' )" );
				}
				break;
		}
	}
	echo "INFO compat actions not registered in this CLI request (not checked): $gs105u_ajax_skipped\n";

	// Write-through: write the CURRENT value through the old name; both rows end equal.
	$gs105u_pk = gs105_inv_option_row( 'gend_society_gend_pubkey' );
	if ( $gs105u_pk && null !== gs105_inv_option_row( 'gs_gend_pubkey' ) ) {
		update_option( 'gs_gend_pubkey', get_option( 'gend_society_gend_pubkey' ) );
		$a = gs105_inv_option_row( 'gs_gend_pubkey' );
		$b = gs105_inv_option_row( 'gend_society_gend_pubkey' );
		$gs105u_ok( $a && $b && $a['md5'] === $b['md5'] && $b['md5'] === $gs105u_pk['md5'], "bridge write-through: update_option( 'gs_gend_pubkey', current ) leaves old and new rows equal and unchanged" );
	} else {
		$gs105u_skip( 'bridge write-through on gs_gend_pubkey', 'site has no gs_gend_pubkey / gend_society_gend_pubkey row' );
	}

	// remove_action sites (mu-plugins / projects remove these by name): removing the NEW name works.
	$gs105u_removed = array( 'gs_enqueue_frontend_assets', 'gs_render_frontend_bar', 'gs_oauth_render_login_page', 'gs_inject_mini_cart' );
	$gs105u_names   = array();
	foreach ( $gs105u_map['entries'] as $e ) {
		if ( 'function' === $e['kind'] && in_array( $e['old'], $gs105u_removed, true ) && ! empty( $e['new'] ) ) {
			$gs105u_names[ $e['old'] ] = $e['new'];
		}
	}
	global $wp_filter;
	foreach ( $gs105u_names as $old => $new ) {
		$found = false;
		foreach ( $wp_filter as $hook => $obj ) {
			$prio = has_action( $hook, $new );
			if ( false === $prio ) {
				continue;
			}
			$found    = true;
			$accepted = 1;
			foreach ( $obj->callbacks[ $prio ] as $cb ) {
				if ( $cb['function'] === $new ) {
					$accepted = $cb['accepted_args'];
				}
			}
			remove_action( $hook, $new, $prio );
			$gs105u_ok( false === has_action( $hook, $new ), "remove_action( '$hook', '$new', $prio ) removes the renamed callback" );
			add_action( $hook, $new, $prio, $accepted ); // restore (this process only).
		}
		if ( ! $found ) {
			$gs105u_skip( "remove_action simulation for $new", 'not registered in this request' );
		}
	}
} else {
	$gs105u_ok( false, 'compat checks need GS105_MAP' );
}

// ── REST ──
if ( $gs105u_before ) {
	$gs105u_ok( $gs105u_now['rest_gs_v1_routes'] === $gs105u_before['rest_gs_v1_routes'], '/gs/v1 route count equals BEFORE', $gs105u_now['rest_gs_v1_routes'] . ' vs ' . $gs105u_before['rest_gs_v1_routes'] );
} else {
	$gs105u_ok( $gs105u_now['rest_gs_v1_routes'] > 0, '/gs/v1 routes registered', (string) $gs105u_now['rest_gs_v1_routes'] );
}

// ── Container: Ed25519 round trips ──
if ( 'container' === $gs105u_section ) {
	$kp_b64 = (string) get_option( 'gend_society_keypair', '' );
	$kp     = base64_decode( $kp_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stored key format.
	if ( function_exists( 'sodium_crypto_sign_detached' ) && is_string( $kp ) && SODIUM_CRYPTO_SIGN_KEYPAIRBYTES === strlen( $kp ) ) {
		$msg = 'gend-society 105 uat fixed message';
		$sig = sodium_crypto_sign_detached( $msg, sodium_crypto_sign_secretkey( $kp ) );
		$gs105u_ok( sodium_crypto_sign_verify_detached( $sig, $msg, sodium_crypto_sign_publickey( $kp ) ), 'migrated gend_society_keypair signs and verifies (Ed25519)' );
	} elseif ( null === gs105_inv_option_row( 'gend_society_keypair' ) && null === gs105_inv_option_row( 'gs_keypair' ) ) {
		// Unpaired site (fresh desktop or container install): neither name has a row, so there is nothing to migrate or sign with.
		$gs105u_skip( 'migrated gend_society_keypair present and valid', 'unpaired site: no gend_society_keypair / gs_keypair row' );
	} else {
		$gs105u_ok( false, 'migrated gend_society_keypair present and valid (container must be paired / pre-seeded)' );
	}

	$verify = function_exists( 'gend_society_support_access_verify_signed_request' ) ? 'gend_society_support_access_verify_signed_request' : null;
	if ( ! $verify ) {
		$gs105u_ok( false, 'gend_society_support_access_verify_signed_request() exists' );
	} elseif ( '1' !== getenv( 'GS105_DISPOSABLE' ) ) {
		$gs105u_skip( 'hub-signed request round trip', 'needs GS105_DISPOSABLE=1 on a disposable site' );
	} else {
		$orig_row = gs105_inv_option_row( 'gend_society_gend_pubkey' );
		$orig     = get_option( 'gend_society_gend_pubkey', null );
		$test     = sodium_crypto_sign_keypair();
		update_option( 'gend_society_gend_pubkey', base64_encode( sodium_crypto_sign_publickey( $test ) ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stored key format.
		$body = wp_json_encode(
			array(
				'issued_at'  => time(),
				'install_id' => (string) get_option( 'gend_society_install_id', '' ),
				'purpose'    => 'gs105-uat',
			)
		);
		$req  = new WP_REST_Request( 'POST', '/gs/v1/uat' );
		$req->set_body( $body );
		$req->set_header( 'x_gend_signature', base64_encode( sodium_crypto_sign_detached( $body, sodium_crypto_sign_secretkey( $test ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire format.
		$good = call_user_func( $verify, $req );
		$req->set_header( 'x_gend_signature', base64_encode( str_repeat( "\0", SODIUM_CRYPTO_SIGN_BYTES ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire format.
		$bad = call_user_func( $verify, $req );
		if ( null === $orig_row ) {
			delete_option( 'gend_society_gend_pubkey' );
		} else {
			update_option( 'gend_society_gend_pubkey', $orig, false );
		}
		$after = gs105_inv_option_row( 'gend_society_gend_pubkey' );
		$gs105u_ok( is_array( $good ) && 'gs105-uat' === $good['purpose'], 'hub-shaped signed payload verifies against gend_society_gend_pubkey', is_wp_error( $good ) ? $good->get_error_code() : '' );
		$gs105u_ok( is_wp_error( $bad ), 'tampered signature is rejected' );
		$gs105u_ok( ( null === $orig_row && null === $after ) || ( $orig_row && $after && $orig_row['md5'] === $after['md5'] ), 'original gend_society_gend_pubkey restored' );
	}
}

echo 0 === $gs105u_fails ? "ALL PASS\n" : "FAILED: {$gs105u_fails}\n";
if ( 0 !== $gs105u_fails ) {
	exit( 1 );
}
