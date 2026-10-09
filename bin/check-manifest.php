<?php
/**
 * Static manifest checker (plain PHP CLI, no WordPress).
 *
 *   php bin/check-manifest.php
 *
 * Fails (exit 1, one line per problem) when:
 *   - an inc/**\/*.php file on disk is not listed in inc/bootstrap/manifest.php,
 *     or is listed more than once (modules, group members and partials count
 *     together)
 *   - a manifest path does not exist on disk
 *   - a tier is not one of core|customer|container|hub|updater
 *   - a need is not one of bp|wu|paired|skin|admin
 *   - a partial has no 'loaded_by' array
 *   - gend-society.php does not require exactly inc/bootstrap/context.php,
 *     inc/bootstrap/key-migration.php and inc/bootstrap/loader.php (in that
 *     order), or requires/includes any other inc/ file
 *   - inc/compat-aliases.php exists but is not the last module (tier container)
 *   - any inc/ file decides hub-ness from a missing class
 *     (class_exists( 'Gend_CP_OAuth_Resource' )), or one of the two inline
 *     hub checks no longer calls gend_society_is_hub()
 *
 * Prints "manifest: OK (<n> modules, <m> partials)" on success.
 *
 * @package gend-society
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$gs_root = dirname( __DIR__ );

// The manifest only needs ABSPATH (its direct-access guard) and must not
// touch WordPress at require time; its 'after' closures are never called here.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $gs_root . '/' );
}
if ( ! defined( 'GEND_SOCIETY_DIR' ) ) {
	define( 'GEND_SOCIETY_DIR', $gs_root . '/' );
}

$gs_errors = array();
$gs_err    = function ( $msg ) use ( &$gs_errors ) {
	$gs_errors[] = $msg;
};

$gs_valid_tiers = array( 'core', 'customer', 'container', 'hub', 'updater' );
$gs_valid_needs = array( 'bp', 'wu', 'paired', 'skin', 'admin' );

$gs_manifest_file = $gs_root . '/inc/bootstrap/manifest.php';
if ( ! is_file( $gs_manifest_file ) ) {
	fwrite( STDERR, "manifest: inc/bootstrap/manifest.php not found\n" );
	exit( 1 );
}
$gs_manifest = require $gs_manifest_file;
if ( ! is_array( $gs_manifest ) || ! isset( $gs_manifest['modules'], $gs_manifest['partials'] )
	|| ! is_array( $gs_manifest['modules'] ) || ! is_array( $gs_manifest['partials'] ) ) {
	fwrite( STDERR, "manifest: manifest.php must return array( 'modules' => [...], 'partials' => [...] )\n" );
	exit( 1 );
}

// ── 1. Walk the manifest: collect paths, validate tier / needs / loaded_by. ──
$gs_listed   = array(); // path => count
$gs_n_mod    = 0;
$gs_n_part   = 0;
$gs_check_entry = function ( $entry, $where ) use ( &$gs_listed, $gs_valid_tiers, $gs_valid_needs, $gs_err ) {
	if ( ! is_array( $entry ) || ! isset( $entry['file'] ) || ! is_string( $entry['file'] ) || '' === $entry['file'] ) {
		$gs_err( "{$where}: entry without a 'file' string" );
		return;
	}
	$file = $entry['file'];
	$gs_listed[ $file ] = isset( $gs_listed[ $file ] ) ? $gs_listed[ $file ] + 1 : 1;

	$tier = isset( $entry['tier'] ) ? $entry['tier'] : null;
	if ( ! is_string( $tier ) || ! in_array( $tier, $gs_valid_tiers, true ) ) {
		$gs_err( "{$file}: invalid tier '" . ( is_scalar( $tier ) ? $tier : gettype( $tier ) ) . "' ({$where})" );
	}
	$needs = isset( $entry['needs'] ) ? $entry['needs'] : array();
	if ( ! is_array( $needs ) ) {
		$gs_err( "{$file}: 'needs' must be an array ({$where})" );
	} else {
		foreach ( $needs as $need ) {
			if ( ! is_string( $need ) || ! in_array( $need, $gs_valid_needs, true ) ) {
				$gs_err( "{$file}: invalid need '" . ( is_scalar( $need ) ? $need : gettype( $need ) ) . "' ({$where})" );
			}
		}
	}
	if ( isset( $entry['after'] ) && ! is_callable( $entry['after'] ) ) {
		$gs_err( "{$file}: 'after' is not callable ({$where})" );
	}
};

foreach ( $gs_manifest['modules'] as $i => $entry ) {
	if ( is_array( $entry ) && isset( $entry['group'] ) ) {
		foreach ( array( 'hook', 'requires_class' ) as $k ) {
			if ( empty( $entry[ $k ] ) || ! is_string( $entry[ $k ] ) ) {
				$gs_err( "modules[{$i}] group '{$entry['group']}': missing '{$k}'" );
			}
		}
		if ( empty( $entry['modules'] ) || ! is_array( $entry['modules'] ) ) {
			$gs_err( "modules[{$i}] group '{$entry['group']}': no member modules" );
			continue;
		}
		foreach ( $entry['modules'] as $j => $member ) {
			$gs_check_entry( $member, "modules[{$i}] group {$entry['group']} member {$j}" );
			$gs_n_mod++;
		}
		continue;
	}
	$gs_check_entry( $entry, "modules[{$i}]" );
	$gs_n_mod++;
}

foreach ( $gs_manifest['partials'] as $i => $entry ) {
	$gs_check_entry( $entry, "partials[{$i}]" );
	$gs_n_part++;
	if ( ! is_array( $entry ) || ! isset( $entry['loaded_by'] ) || ! is_array( $entry['loaded_by'] ) ) {
		$name = ( is_array( $entry ) && isset( $entry['file'] ) ) ? $entry['file'] : "partials[{$i}]";
		$gs_err( "{$name}: partial needs a 'loaded_by' array" );
	}
}

// ── 2. Coverage: every inc/**/*.php exactly once; every listed path exists. ──
$gs_disk = array();
$gs_it   = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $gs_root . '/inc', FilesystemIterator::SKIP_DOTS )
);
foreach ( $gs_it as $f ) {
	if ( $f->isFile() && 'php' === strtolower( $f->getExtension() ) ) {
		$rel           = 'inc/' . str_replace( '\\', '/', substr( $f->getPathname(), strlen( $gs_root . '/inc/' ) ) );
		$gs_disk[ $rel ] = true;
	}
}
ksort( $gs_disk );

foreach ( array_keys( $gs_disk ) as $rel ) {
	if ( ! isset( $gs_listed[ $rel ] ) ) {
		$gs_err( "{$rel}: on disk but missing from the manifest" );
	}
}
foreach ( $gs_listed as $rel => $count ) {
	if ( $count > 1 ) {
		$gs_err( "{$rel}: listed {$count} times in the manifest" );
	}
	if ( ! is_file( $gs_root . '/' . $rel ) ) {
		$gs_err( "{$rel}: listed in the manifest but not on disk" );
	}
}

// ── 2b. The generated compat layer loads last (every renamed name exists before its alias). ──
if ( is_file( $gs_root . '/inc/compat-aliases.php' ) ) {
	$gs_last = end( $gs_manifest['modules'] );
	if ( ! is_array( $gs_last ) || ( $gs_last['file'] ?? '' ) !== 'inc/compat-aliases.php' || ( $gs_last['tier'] ?? '' ) !== 'container' ) {
		$gs_err( "inc/compat-aliases.php: must be the LAST module, tier 'container'" );
	}
}

// ── 3. gend-society.php requires only the three bootstrap files from inc/. ──
$gs_entry_src = @file_get_contents( $gs_root . '/gend-society.php' );
if ( ! is_string( $gs_entry_src ) ) {
	$gs_err( 'gend-society.php: not readable' );
} else {
	// Strip comments so prose mentioning inc/ files is not counted.
	$code = '';
	foreach ( token_get_all( $gs_entry_src ) as $tok ) {
		if ( is_array( $tok ) ) {
			if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) {
				continue;
			}
			$code .= $tok[1];
		} else {
			$code .= $tok;
		}
	}
	preg_match_all( '/\b(require|require_once|include|include_once)\b([^;]*);/i', $code, $m, PREG_SET_ORDER );
	$bootstrap = array();
	foreach ( $m as $stmt ) {
		$arg = $stmt[2];
		if ( ! preg_match( '#inc/#', $arg ) ) {
			continue;
		}
		if ( preg_match( '#[\'"]inc/bootstrap/(context|key-migration|loader)\.php[\'"]#', $arg, $bm ) ) {
			$bootstrap[] = $bm[1];
			continue;
		}
		$gs_err( 'gend-society.php: direct ' . strtolower( $stmt[1] ) . ' of an inc/ module (' . trim( $arg ) . '); add it to the manifest instead' );
	}
	// Order matters: the key migration runs after the runtime-mode resolver and before any module loads.
	if ( array( 'context', 'key-migration', 'loader' ) !== $bootstrap ) {
		$gs_err( 'gend-society.php: must require inc/bootstrap/context.php, inc/bootstrap/key-migration.php and inc/bootstrap/loader.php exactly once each, in that order (found: ' . ( $bootstrap ? implode( ', ', $bootstrap ) : 'none' ) . ')' );
	}
}

// ── 4. No hub-from-a-missing-class checks under inc/. ──
foreach ( array_keys( $gs_disk ) as $rel ) {
	$src = @file_get_contents( $gs_root . '/' . $rel );
	if ( ! is_string( $src ) ) {
		continue;
	}
	if ( preg_match_all( '/class_exists\s*\(\s*[\'"]\\\\?Gend_CP_OAuth_Resource[\'"]\s*\)/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $hits[0] as $hit ) {
			$line = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
			$gs_err( "{$rel}:{$line}: hub decided from a missing class (class_exists( 'Gend_CP_OAuth_Resource' )); use gend_society_is_hub()" );
		}
	}
}
// The two inline hub checks (not reachable by the runtime UAT's reflection calls).
foreach ( array( 'inc/collab/class-collab-rest.php', 'inc/member-profile-header.php' ) as $rel ) {
	$src = @file_get_contents( $gs_root . '/' . $rel );
	if ( is_string( $src ) && ! preg_match( '/\$is_hub\s*=\s*gend_society_is_hub\(\s*\)\s*;/', $src ) ) {
		$gs_err( "{$rel}: inline \$is_hub must be gend_society_is_hub()" );
	}
}

if ( $gs_errors ) {
	foreach ( $gs_errors as $e ) {
		fwrite( STDERR, "manifest: FAIL {$e}\n" );
	}
	fwrite( STDERR, 'manifest: ' . count( $gs_errors ) . " problem(s)\n" );
	exit( 1 );
}

echo "manifest: OK ({$gs_n_mod} modules, {$gs_n_part} partials)\n";
exit( 0 );
