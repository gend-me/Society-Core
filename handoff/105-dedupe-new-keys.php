<?php
/**
 * Phase 105 gap fix: repair duplicate / stale rows under the NEW gend_society_ names
 * (left by the unlocked on-load migration of 1.2.0 builds before the lock fix).
 *
 * Runs gend_society_km_dedupe_new_keys() from the fixed inc/bootstrap/key-migration.php.
 * Works on the OLD plugin version (1.1.x): it requires the staged 1.2.0 file with
 * GEND_SOCIETY_KM_NO_AUTORUN, so nothing else of 1.2.0 runs (no migration, no bridges).
 *
 * Only new names from the key map (and their allowlisted dynamic prefixes) are written:
 * user meta, BuddyPress group meta, network options (sitemeta), post meta on every blog.
 * Old keys and other plugins' keys are never written; options are unique by name and
 * are not touched.
 *
 * Usage (main site, read-only by default):
 *   GS105_KM_FILE=<.../gend-society/inc/bootstrap/key-migration.php> wp eval-file 105-dedupe-new-keys.php
 *   GS105_KM_FILE=... GS105_APPLY=1 wp eval-file 105-dedupe-new-keys.php
 *
 * Prints one JSON document:
 *   before / after: checksum of every OLD-key row (must be equal) + checksum of all other
 *                   meta rows outside the new names (INFO: live traffic may change it);
 *   plan:           the dry-run report (per new key: objects, fixed, rows_before, rows_after,
 *                   value_rewrites);
 *   applied:        the report of the writing run (GS105_APPLY=1 only);
 *   left:           a dry run after the apply: every "fixed" must be 0;
 * and a last line "DEDUPE: OK|DRY RUN|FAIL ...".
 *
 * @package gend-society
 */

// phpcs:ignoreFile -- operator tool run once through wp eval-file, never shipped.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gs105d_file = getenv( 'GS105_KM_FILE' );
if ( ! $gs105d_file && defined( 'GEND_SOCIETY_DIR' ) ) {
	$gs105d_file = GEND_SOCIETY_DIR . 'inc/bootstrap/key-migration.php';
}
if ( ! function_exists( 'gend_society_km_dedupe_new_keys' ) ) {
	if ( ! $gs105d_file || ! is_file( $gs105d_file ) ) {
		fwrite( STDERR, "GS105_KM_FILE not found\n" );
		echo "DEDUPE: FAIL (key-migration.php not found)\n";
		return;
	}
	if ( ! defined( 'GEND_SOCIETY_KM_NO_AUTORUN' ) ) {
		define( 'GEND_SOCIETY_KM_NO_AUTORUN', true );
	}
	require_once $gs105d_file;
}
if ( ! function_exists( 'gend_society_km_dedupe_new_keys' ) ) {
	echo "DEDUPE: FAIL (gend_society_km_dedupe_new_keys() missing: not the fixed key-migration.php)\n";
	return;
}

$gs105d_apply = '1' === getenv( 'GS105_APPLY' );
$gs105d_map   = gend_society_key_map();

/**
 * Checksums of meta rows: OLD mapped keys (strict) and everything outside the new names (info).
 */
$gs105d_sums = static function () use ( $gs105d_map ) {
	global $wpdb;
	$sum = static function ( $table, $id, $obj, $where, $args ) use ( $wpdb ) {
		$h   = "MD5(CONCAT_WS(0x1f, {$id}, {$obj}, meta_key, meta_value))";
		$sql = "SELECT COUNT(*) n, COALESCE(SUM(CAST(CONV(SUBSTRING({$h},1,15),16,10) AS UNSIGNED)),0) s, COALESCE(BIT_XOR(CAST(CONV(SUBSTRING({$h},16,15),16,10) AS UNSIGNED)),0) x FROM {$table} WHERE {$where}";
		$r   = $wpdb->get_row( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		return $r ? $r['n'] . ':' . $r['s'] . ':' . $r['x'] : 'error';
	};
	$in = static function ( $keys ) use ( $wpdb ) {
		$keys = array_values( array_unique( $keys ) );
		return $keys ? array( 'meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) : array( '1=0', array() );
	};
	$likes = static function ( $prefixes ) use ( $wpdb ) {
		$w = array();
		$a = array();
		foreach ( $prefixes as $p ) {
			$w[] = 'meta_key LIKE %s';
			$a[] = $wpdb->esc_like( $p ) . '%';
		}
		return $w ? array( '(' . implode( ' OR ', $w ) . ')', $a ) : array( '1=0', array() );
	};
	$out = array();
	// User meta.
	list( $w_old, $a_old ) = $in( array_keys( $gs105d_map['user_meta'] ) );
	list( $w_new, $a_new ) = $in( array_values( $gs105d_map['user_meta'] ) );
	list( $l_old, $p_old ) = $likes( array_keys( $gs105d_map['user_meta_prefix'] ) );
	list( $l_new, $p_new ) = $likes( array_values( $gs105d_map['user_meta_prefix'] ) );
	$out['user_old']   = $sum( $wpdb->usermeta, 'umeta_id', 'user_id', "({$w_old} OR {$l_old})", array_merge( $a_old, $p_old ) );
	$out['user_other'] = $sum( $wpdb->usermeta, 'umeta_id', 'user_id', "NOT ({$w_new} OR {$l_new})", array_merge( $a_new, $p_new ) );
	// Group meta.
	$gt = $wpdb->base_prefix . 'bp_groups_groupmeta';
	if ( ! empty( $wpdb->groupmeta ) ) {
		$gt = $wpdb->groupmeta;
	}
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gt ) ) === $gt ) {
		list( $w_old, $a_old ) = $in( array_keys( $gs105d_map['group_meta'] ) );
		list( $w_new, $a_new ) = $in( array_values( $gs105d_map['group_meta'] ) );
		$out['group_old']   = $sum( $gt, 'id', 'group_id', $w_old, $a_old );
		$out['group_other'] = $sum( $gt, 'id', 'group_id', "NOT ({$w_new})", $a_new );
	}
	// Network options.
	if ( is_multisite() ) {
		list( $w_old, $a_old ) = $in( array_keys( $gs105d_map['site_option'] ) );
		list( $w_new, $a_new ) = $in( array_values( $gs105d_map['site_option'] ) );
		$out['site_old']   = $sum( $wpdb->sitemeta, 'meta_id', 'site_id', $w_old, $a_old );
		$out['site_other'] = $sum( $wpdb->sitemeta, 'meta_id', 'site_id', "NOT ({$w_new})", $a_new );
	}
	// Post meta, every blog.
	$blogs = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
	foreach ( $blogs as $b ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $b );
		}
		list( $w_old, $a_old ) = $in( array_keys( $gs105d_map['post_meta'] ) );
		list( $w_new, $a_new ) = $in( array_values( $gs105d_map['post_meta'] ) );
		$out[ "post_old:$b" ]   = $sum( $wpdb->postmeta, 'meta_id', 'post_id', $w_old, $a_old );
		$out[ "post_other:$b" ] = $sum( $wpdb->postmeta, 'meta_id', 'post_id', "NOT ({$w_new})", $a_new );
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
	return $out;
};
$gs105d_total = static function ( $report ) {
	$t = array( 'keys' => 0, 'objects' => 0, 'fixed' => 0, 'rows_before' => 0, 'rows_after' => 0, 'value_rewrites' => 0 );
	foreach ( $report['keys'] as $r ) {
		++$t['keys'];
		foreach ( array( 'objects', 'fixed', 'rows_before', 'rows_after', 'value_rewrites' ) as $k ) {
			$t[ $k ] += (int) $r[ $k ];
		}
	}
	return $t;
};

$gs105d = array(
	'host'     => home_url(),
	'time'     => gmdate( 'c' ),
	'plugin'   => defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : ( defined( 'GS_VERSION' ) ? GS_VERSION : '?' ),
	'km_file'  => $gs105d_file,
	'km_md5'   => $gs105d_file && is_file( $gs105d_file ) ? md5_file( $gs105d_file ) : null,
	'map'      => $gs105d_map['version'],
	'apply'    => $gs105d_apply,
	'before'   => $gs105d_sums(),
);
$gs105d['plan']       = gend_society_km_dedupe_new_keys( false );
$gs105d['plan_total'] = $gs105d_total( $gs105d['plan'] );
if ( $gs105d_apply ) {
	$gs105d['applied']       = gend_society_km_dedupe_new_keys( true );
	$gs105d['applied_total'] = $gs105d_total( $gs105d['applied'] );
	wp_cache_flush_runtime();
	$gs105d['left']       = gend_society_km_dedupe_new_keys( false );
	$gs105d['left_total'] = $gs105d_total( $gs105d['left'] );
	$gs105d['after']      = $gs105d_sums();
}
echo wp_json_encode( $gs105d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

$gs105d_old_equal = true;
if ( $gs105d_apply ) {
	foreach ( $gs105d['before'] as $k => $v ) {
		if ( false !== strpos( $k, '_old' ) && ( $gs105d['after'][ $k ] ?? null ) !== $v ) {
			$gs105d_old_equal = false;
		}
	}
	$gs105d_ok = $gs105d_old_equal && 0 === $gs105d['left_total']['fixed'];
	printf(
		"DEDUPE: %s (fixed %d objects in %d keys, rows %d -> %d, value rewrites %d; old-key rows %s; left to fix: %d)\n",
		$gs105d_ok ? 'OK' : 'FAIL',
		$gs105d['applied_total']['fixed'],
		$gs105d['applied_total']['keys'],
		$gs105d['applied_total']['rows_before'],
		$gs105d['applied_total']['rows_after'],
		$gs105d['applied_total']['value_rewrites'],
		$gs105d_old_equal ? 'unchanged' : 'CHANGED',
		$gs105d['left_total']['fixed']
	);
} else {
	printf(
		"DEDUPE: DRY RUN (would fix %d objects in %d keys, rows %d -> %d, value rewrites %d; nothing written)\n",
		$gs105d['plan_total']['fixed'],
		$gs105d['plan_total']['keys'],
		$gs105d['plan_total']['rows_before'],
		$gs105d['plan_total']['rows_after'],
		$gs105d['plan_total']['value_rewrites']
	);
}
