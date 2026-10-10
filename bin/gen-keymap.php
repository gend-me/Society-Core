<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); } // CLI-only repo tooling; never shipped.
/**
 * Phase 105 key-map generator: writes inc/bootstrap/key-map.php, the shipped
 * allowlist that inc/bootstrap/key-migration.php copies from old to new names.
 *
 *   php bin/gen-keymap.php [--map=<file>] > inc/bootstrap/key-map.php
 *
 * Only data entries whose owner is gend-society (or "shared:vendor-app-manager")
 * and whose "new" is not null are emitted. Keys owned by any other plugin, by the
 * operator, or by nobody never appear in the output, so the migration cannot
 * touch them. Explicit keys already covered by an allowlisted prefix of the same
 * scope are folded into the prefix (one copy, one count).
 *
 * All generated code sits inside a "gend-society-rename: keep" block and
 * is on bin/rename.php's GS_RN_NEVER_RENAME_FILES list: the renamer must never
 * rewrite the old names it carries. Output is deterministic.
 *
 * @package gend-society
 */

$gs_gk_opts = getopt( '', array( 'map:' ) );
$gs_gk_map  = isset( $gs_gk_opts['map'] ) ? $gs_gk_opts['map'] : __DIR__ . '/rename-map.json';
$gs_gk_m    = json_decode( (string) @file_get_contents( $gs_gk_map ), true );
if ( ! is_array( $gs_gk_m ) || empty( $gs_gk_m['entries'] ) || empty( $gs_gk_m['version'] ) ) {
	fwrite( STDERR, "gen-keymap: map not found or invalid: $gs_gk_map\n" );
	exit( 2 );
}

/**
 * True when the entry belongs in the migration allowlist.
 */
function gs_gk_owned( array $e ): bool {
	if ( ! isset( $e['new'] ) || null === $e['new'] || '' === $e['new'] ) {
		return false;
	}
	$owner = isset( $e['owner'] ) ? (string) $e['owner'] : '';
	return 'gend-society' === $owner || 0 === strpos( $owner, 'shared:vendor-app-manager' );
}

$gs_gk_out = array(
	'option'              => array(),
	'site_option'         => array(),
	'user_meta'           => array(),
	'post_meta'           => array(),
	'group_meta'          => array(),
	'wu_site_meta'        => array(),
	'cron'                => array(),
	'option_prefix'       => array(),
	'user_meta_prefix'    => array(),
	'transient_read_once' => array(),
);
$gs_gk_skipped = array();

foreach ( $gs_gk_m['entries'] as $e ) {
	$kind = (string) $e['kind'];
	if ( ! in_array( $kind, array( 'option', 'site_option', 'user_meta', 'post_meta', 'group_meta', 'cron', 'transient', 'wu_site_meta' ), true ) ) {
		continue;
	}
	if ( ! gs_gk_owned( $e ) ) {
		if ( null !== ( $e['new'] ?? null ) ) {
			$gs_gk_skipped[] = $e['old'] . ' (' . ( $e['owner'] ?? '?' ) . ')';
		}
		continue;
	}
	$old    = (string) $e['old'];
	$new    = (string) $e['new'];
	$prefix = ! empty( $e['prefix'] );
	$scope  = isset( $e['scope'] ) ? (string) $e['scope'] : '';

	if ( 'wu_site_meta' === $kind || 'wu_site_meta' === $scope ) {
		$gs_gk_out['wu_site_meta'][ $old ] = $new;
	} elseif ( 'transient' === $kind ) {
		// Transients are renamed without migration; only the agent switch-back
		// state is read once from the old name (research 5.6).
		if ( $prefix && 'gs_agent_switch_' === $old ) {
			$gs_gk_out['transient_read_once'][ $old ] = $new;
		}
	} elseif ( 'option' === $kind ) {
		$gs_gk_out[ $prefix ? 'option_prefix' : 'option' ][ $old ] = $new;
	} elseif ( 'user_meta' === $kind ) {
		$gs_gk_out[ $prefix ? 'user_meta_prefix' : 'user_meta' ][ $old ] = $new;
	} elseif ( $prefix ) {
		fwrite( STDERR, "gen-keymap: prefix entry of kind $kind is not supported: $old\n" );
		exit( 2 );
	} else {
		$gs_gk_out[ $kind ][ $old ] = $new;
	}
}

// Fold explicit keys that an allowlisted prefix of the same scope already covers.
foreach ( array( 'option' => 'option_prefix', 'user_meta' => 'user_meta_prefix' ) as $gs_gk_kind => $gs_gk_pk ) {
	foreach ( array_keys( $gs_gk_out[ $gs_gk_kind ] ) as $old ) {
		foreach ( $gs_gk_out[ $gs_gk_pk ] as $po => $pn ) {
			if ( 0 === strpos( $old, $po ) ) {
				if ( $pn . substr( $old, strlen( $po ) ) !== $gs_gk_out[ $gs_gk_kind ][ $old ] ) {
					fwrite( STDERR, "gen-keymap: $old disagrees with prefix $po -> $pn\n" );
					exit( 2 );
				}
				unset( $gs_gk_out[ $gs_gk_kind ][ $old ] );
				break;
			}
		}
	}
}

foreach ( $gs_gk_out as &$gs_gk_list ) {
	ksort( $gs_gk_list, SORT_STRING );
}
unset( $gs_gk_list );

$gs_gk_k = '// gend-society-rename: keep';
$o       = "<?php\n";
$o      .= "/**\n"
	. " * Key-migration allowlist: old => new names that inc/bootstrap/key-migration.php\n"
	. " * copies once per scope (copy-not-move). Keys owned by other plugins are never\n"
	. " * listed here.\n"
	. " *\n"
	. " * Generated from bin/rename-map.json by bin/gen-keymap.php; do not edit.\n"
	. " * Regenerate: php bin/gen-keymap.php > inc/bootstrap/key-map.php\n"
	. ' * Map version: ' . $gs_gk_m['version'] . "\n"
	. " *\n"
	. " * @package gend-society\n"
	. " */\n\n";
$o      .= "$gs_gk_k-start (generated allowlist: the renamer must not rewrite these old names).\n";
$o      .= "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n";
$o      .= "return array(\n";
$o      .= "\t'version'             => " . var_export( (string) $gs_gk_m['version'], true ) . ",\n";
foreach ( $gs_gk_out as $gs_gk_kind => $gs_gk_list ) {
	$o .= "\t" . str_pad( var_export( $gs_gk_kind, true ), 21 ) . ' => array(';
	if ( ! $gs_gk_list ) {
		$o .= "),\n";
		continue;
	}
	$o    .= "\n";
	$width = max( array_map( fn( $k ) => strlen( var_export( (string) $k, true ) ), array_keys( $gs_gk_list ) ) );
	foreach ( $gs_gk_list as $old => $new ) {
		$o .= "\t\t" . str_pad( var_export( (string) $old, true ), $width ) . ' => ' . var_export( (string) $new, true ) . ",\n";
	}
	$o .= "\t),\n";
}
$o .= ");\n";
$o .= "$gs_gk_k-end.\n";

echo $o;
if ( $gs_gk_skipped ) {
	fwrite( STDERR, 'gen-keymap: not owned, excluded: ' . implode( ', ', $gs_gk_skipped ) . "\n" );
}
exit( 0 );
