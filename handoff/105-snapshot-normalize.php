<?php
/**
 * Phase 105 rename-aware snapshot diff (plain CLI PHP, not wp-cli).
 *
 *   php 105-snapshot-normalize.php before.json after.json rename-map.json
 *
 * before.json / after.json: output of 104-snapshot.php (or both of
 * 104-admin-dump.php) taken on 1.1.x and on 1.2.0. Every identifier in
 * before.json (callback function names, Class::method class parts, hook names,
 * cron hooks, AJAX / admin-post hook names, BP group extension classes) is
 * mapped through the rename map, then each section is compared with after.json.
 *
 * EXPECTED differences (listed, not counted):
 *  - version (gs_version) 1.1.x -> 1.2.0;
 *  - gs_files gaining inc/bootstrap/key-migration.php, inc/bootstrap/key-map.php,
 *    inc/compat-bridges.php, inc/compat-aliases.php;
 *  - hooks gaining the compat registrations: old wp_ajax_ / admin_post_ names,
 *    option/meta bridge filters (pre_option_gs_*, pre_update_option_gs_*,
 *    add_option_gs_*, delete_option_gs_*, *_site_option_*, get/update/add/
 *    delete_*_metadata), gend_society_compat_mirror_actions on admin_init, the
 *    bp_include priority-11 closure (class aliases), the key-migration stages
 *    (gend_society_km_*) and hook-bridge closures;
 *  - bp_group_extensions gaining old class names that alias a renamed class.
 * Everything else must match exactly: rest_routes byte-identical, gs_files in
 * the same order once the expected files are removed, hooks/cron/menus/navs
 * identical after mapping.
 *
 * Prints EXPECTED / UNEXPECTED lines and "UNEXPECTED: <n>"; exit 0 when n == 0.
 *
 * @package gend-society
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( $argc < 4 ) {
	fwrite( STDERR, "usage: php 105-snapshot-normalize.php before.json after.json rename-map.json\n" );
	exit( 2 );
}
$gs105n_before = json_decode( (string) file_get_contents( $argv[1] ), true );
$gs105n_after  = json_decode( (string) file_get_contents( $argv[2] ), true );
$gs105n_map    = json_decode( (string) file_get_contents( $argv[3] ), true );
if ( ! is_array( $gs105n_before ) || ! is_array( $gs105n_after ) || ! is_array( $gs105n_map ) || empty( $gs105n_map['entries'] ) ) {
	fwrite( STDERR, "105-snapshot-normalize: unreadable input\n" );
	exit( 2 );
}

// old => new for identifiers that can appear in a snapshot.
$gs105n_ids       = array();
$gs105n_old_class = array();
foreach ( $gs105n_map['entries'] as $e ) {
	if ( empty( $e['new'] ) || ! empty( $e['prefix'] ) ) {
		continue;
	}
	if ( in_array( $e['kind'], array( 'function', 'class', 'hook', 'cron', 'ajax', 'admin_post', 'constant' ), true ) ) {
		$gs105n_ids[ $e['kind'] ][ $e['old'] ] = $e['new'];
	}
	if ( 'class' === $e['kind'] ) {
		// get_declared_classes() lists class_alias() names in lower case.
		$gs105n_old_class[ strtolower( $e['old'] ) ] = $e['new'];
	}
}
$gs105n_kind = function ( $kind, $name ) use ( $gs105n_ids ) {
	return isset( $gs105n_ids[ $kind ][ $name ] ) ? $gs105n_ids[ $kind ][ $name ] : $name;
};
$gs105n_hook = function ( $hook ) use ( $gs105n_ids, $gs105n_kind ) {
	foreach ( array( 'wp_ajax_nopriv_' => 'ajax', 'wp_ajax_' => 'ajax', 'admin_post_nopriv_' => 'admin_post', 'admin_post_' => 'admin_post' ) as $p => $k ) {
		if ( 0 === strpos( $hook, $p ) ) {
			return $p . $gs105n_kind( $k, substr( $hook, strlen( $p ) ) );
		}
	}
	if ( isset( $gs105n_ids['hook'][ $hook ] ) ) {
		return $gs105n_ids['hook'][ $hook ];
	}
	return $gs105n_kind( 'cron', $hook );
};
// "name/2", "Class::method/1", "closure x3".
$gs105n_cb = function ( $id ) use ( $gs105n_kind ) {
	if ( 0 === strpos( $id, 'closure x' ) ) {
		return $id;
	}
	$slash = strrpos( $id, '/' );
	$name  = false === $slash ? $id : substr( $id, 0, $slash );
	$tail  = false === $slash ? '' : substr( $id, $slash );
	if ( false !== strpos( $name, '::' ) ) {
		list( $cls, $meth ) = explode( '::', $name, 2 );
		return $gs105n_kind( 'class', $cls ) . '::' . $meth . $tail;
	}
	return $gs105n_kind( 'function', $name ) . $tail;
};

$gs105n_unexpected = array();
$gs105n_expected   = array();
$gs105n_u          = function ( $msg ) use ( &$gs105n_unexpected ) {
	$gs105n_unexpected[] = $msg;
};
$gs105n_x          = function ( $msg ) use ( &$gs105n_expected ) {
	$gs105n_expected[] = $msg;
};

$gs105n_expected_files = array( 'inc/bootstrap/key-migration.php', 'inc/bootstrap/key-map.php', 'inc/compat-bridges.php', 'inc/compat-aliases.php' );
$gs105n_expected_hook  = function ( $hook, $cb ) {
	if ( preg_match( '/^(wp_ajax_|wp_ajax_nopriv_|admin_post_|admin_post_nopriv_)gs_/', $hook ) ) {
		return true; // compat-mirrored old action names.
	}
	if ( preg_match( '/^(pre_option_|pre_update_option_|add_option_|delete_option_|pre_site_option_|pre_update_site_option_|add_site_option_|delete_site_option_)(gs_|_gs_|gdc_|_gdc_)/', $hook ) ) {
		return true; // option bridges.
	}
	if ( preg_match( '/^(get|update|add|delete)_(user|post|group|term)_metadata$/', $hook ) && 0 === strpos( $cb, 'closure x' ) ) {
		return true; // meta bridges.
	}
	if ( 'admin_init' === $hook && 0 === strpos( $cb, 'gend_society_compat_mirror_actions/' ) ) {
		return true;
	}
	if ( 0 === strpos( $cb, 'gend_society_km_' ) ) {
		return true; // key-migration stages (only on the request that migrates).
	}
	return false;
};

// ── Scalars ──
foreach ( array( 'mode', 'source', 'site', 'host', 'user', 'user_id', 'runtime_mode', 'displayed_user_id', 'group_id' ) as $k ) {
	if ( array_key_exists( $k, $gs105n_before ) || array_key_exists( $k, $gs105n_after ) ) {
		$b = $gs105n_before[ $k ] ?? null;
		$a = $gs105n_after[ $k ] ?? null;
		if ( $b !== $a ) {
			$gs105n_u( "$k: " . wp_json_encode_cli( $b ) . ' -> ' . wp_json_encode_cli( $a ) );
		}
	}
}
if ( ( $gs105n_before['gs_version'] ?? null ) !== ( $gs105n_after['gs_version'] ?? null ) ) {
	$gs105n_x( 'gs_version: ' . wp_json_encode_cli( $gs105n_before['gs_version'] ?? null ) . ' -> ' . wp_json_encode_cli( $gs105n_after['gs_version'] ?? null ) );
}

/**
 * JSON for messages (no WordPress here).
 *
 * @param mixed $v Value.
 * @return string
 */
function wp_json_encode_cli( $v ) {
	return (string) json_encode( $v, JSON_UNESCAPED_SLASHES );
}

// ── REST routes: byte-identical ──
if ( isset( $gs105n_before['rest_routes'] ) || isset( $gs105n_after['rest_routes'] ) ) {
	$b = $gs105n_before['rest_routes'] ?? array();
	$a = $gs105n_after['rest_routes'] ?? array();
	foreach ( array_diff_key( $b, $a ) as $r => $m ) {
		$gs105n_u( "rest_routes: missing $r" );
	}
	foreach ( array_diff_key( $a, $b ) as $r => $m ) {
		$gs105n_u( "rest_routes: added $r" );
	}
	foreach ( array_intersect_key( $b, $a ) as $r => $m ) {
		if ( $m !== $a[ $r ] ) {
			$gs105n_u( "rest_routes: methods changed on $r: " . implode( ',', $m ) . ' -> ' . implode( ',', $a[ $r ] ) );
		}
	}
}

// ── gs_files: same order once the expected new files are removed ──
if ( isset( $gs105n_before['gs_files'] ) || isset( $gs105n_after['gs_files'] ) ) {
	$b = $gs105n_before['gs_files'] ?? array();
	$a = array();
	foreach ( (array) ( $gs105n_after['gs_files'] ?? array() ) as $f ) {
		if ( in_array( $f, $gs105n_expected_files, true ) && ! in_array( $f, $b, true ) ) {
			$gs105n_x( "gs_files: + $f" );
			continue;
		}
		$a[] = $f;
	}
	if ( $a !== $b ) {
		foreach ( array_diff( $b, $a ) as $f ) {
			$gs105n_u( "gs_files: missing $f" );
		}
		foreach ( array_diff( $a, $b ) as $f ) {
			$gs105n_u( "gs_files: added $f" );
		}
		if ( ! array_diff( $b, $a ) && ! array_diff( $a, $b ) ) {
			$gs105n_u( 'gs_files: same files, include order changed' );
		}
	}
}

// ── Hooks ──
if ( isset( $gs105n_before['hooks'] ) || isset( $gs105n_after['hooks'] ) ) {
	$bh = array();
	foreach ( (array) ( $gs105n_before['hooks'] ?? array() ) as $hook => $prios ) {
		$nh = $gs105n_hook( $hook );
		foreach ( (array) $prios as $prio => $ids ) {
			foreach ( (array) $ids as $id ) {
				$bh[ $nh ][ (string) $prio ][] = $gs105n_cb( $id );
			}
		}
	}
	$ah = $gs105n_after['hooks'] ?? array();
	foreach ( array_unique( array_merge( array_keys( $bh ), array_keys( (array) $ah ) ) ) as $hook ) {
		$bp = $bh[ $hook ] ?? array();
		$ap = (array) ( $ah[ $hook ] ?? array() );
		foreach ( array_unique( array_merge( array_map( 'strval', array_keys( $bp ) ), array_map( 'strval', array_keys( $ap ) ) ) ) as $prio ) {
			$bl = $bp[ $prio ] ?? array();
			$al = $ap[ $prio ] ?? array();
			$bc = 0;
			$ac = 0;
			foreach ( $bl as $i => $id ) {
				if ( preg_match( '/^closure x(\d+)$/', $id, $m ) ) {
					$bc = (int) $m[1];
					unset( $bl[ $i ] );
				}
			}
			foreach ( $al as $i => $id ) {
				if ( preg_match( '/^closure x(\d+)$/', $id, $m ) ) {
					$ac = (int) $m[1];
					unset( $al[ $i ] );
				}
			}
			foreach ( array_diff( $bl, $al ) as $id ) {
				$gs105n_u( "hooks: $hook@$prio lost $id" );
			}
			foreach ( array_diff( $al, $bl ) as $id ) {
				if ( $gs105n_expected_hook( $hook, $id ) ) {
					$gs105n_x( "hooks: $hook@$prio + $id (compat / migration)" );
				} else {
					$gs105n_u( "hooks: $hook@$prio gained $id" );
				}
			}
			if ( $ac !== $bc ) {
				$closure_ok = $ac > $bc && ( ( 'bp_include' === $hook && '11' === (string) $prio ) || $gs105n_expected_hook( $hook, 'closure x' . $ac ) );
				if ( $closure_ok ) {
					$gs105n_x( "hooks: $hook@$prio closures $bc -> $ac (compat)" );
				} else {
					$gs105n_u( "hooks: $hook@$prio closures $bc -> $ac" );
				}
			}
		}
	}
}

// ── BP group extensions ──
if ( isset( $gs105n_before['bp_group_extensions'] ) || isset( $gs105n_after['bp_group_extensions'] ) ) {
	$b = array_map(
		function ( $c ) use ( $gs105n_kind ) {
			return $gs105n_kind( 'class', $c );
		},
		(array) ( $gs105n_before['bp_group_extensions'] ?? array() )
	);
	$a = (array) ( $gs105n_after['bp_group_extensions'] ?? array() );
	foreach ( array_diff( $b, $a ) as $c ) {
		$gs105n_u( "bp_group_extensions: missing $c" );
	}
	foreach ( array_diff( $a, $b ) as $c ) {
		$lc = strtolower( $c );
		if ( isset( $gs105n_old_class[ $lc ] ) && in_array( $gs105n_old_class[ $lc ], $a, true ) ) {
			$gs105n_x( "bp_group_extensions: + $c (alias of {$gs105n_old_class[ $lc ]})" );
		} else {
			$gs105n_u( "bp_group_extensions: added $c" );
		}
	}
}

// ── Cron hooks ──
if ( isset( $gs105n_before['cron_events'] ) || isset( $gs105n_after['cron_events'] ) ) {
	$b = array_map( $gs105n_hook, (array) ( $gs105n_before['cron_events'] ?? array() ) );
	sort( $b );
	$a = (array) ( $gs105n_after['cron_events'] ?? array() );
	sort( $a );
	foreach ( array_diff( $b, $a ) as $h ) {
		$gs105n_u( "cron_events: missing $h" );
	}
	foreach ( array_diff( $a, $b ) as $h ) {
		$gs105n_u( "cron_events: added $h" );
	}
}

// ── 104-admin-dump sections: identical ──
foreach ( array( 'menu', 'submenu', 'primary', 'secondary', 'group_nav' ) as $k ) {
	if ( ! array_key_exists( $k, $gs105n_before ) && ! array_key_exists( $k, $gs105n_after ) ) {
		continue;
	}
	$b = wp_json_encode_cli( $gs105n_before[ $k ] ?? null );
	$a = wp_json_encode_cli( $gs105n_after[ $k ] ?? null );
	if ( $b !== $a ) {
		$gs105n_u( "$k differs: before md5 " . md5( $b ) . ', after md5 ' . md5( $a ) );
	}
}

// ── Anything else in the JSON that this tool does not understand ──
$gs105n_known = array( 'mode', 'source', 'gs_version', 'site', 'rest_routes', 'gs_files', 'hooks', 'bp_group_extensions', 'cron_events', 'host', 'user', 'user_id', 'runtime_mode', 'displayed_user_id', 'group_id', 'menu', 'submenu', 'primary', 'secondary', 'group_nav' );
foreach ( array_diff( array_unique( array_merge( array_keys( $gs105n_before ), array_keys( $gs105n_after ) ) ), $gs105n_known ) as $k ) {
	if ( wp_json_encode_cli( $gs105n_before[ $k ] ?? null ) !== wp_json_encode_cli( $gs105n_after[ $k ] ?? null ) ) {
		$gs105n_u( "$k differs (section not normalised)" );
	}
}

foreach ( $gs105n_expected as $l ) {
	echo "EXPECTED $l\n";
}
foreach ( $gs105n_unexpected as $l ) {
	echo "UNEXPECTED $l\n";
}
echo 'EXPECTED: ' . count( $gs105n_expected ) . "\n";
echo 'UNEXPECTED: ' . count( $gs105n_unexpected ) . "\n";
exit( $gs105n_unexpected ? 1 : 0 );
