<?php
/**
 * Phase 103 UAT (FND-05): single version source on a deployed gend-society.
 *
 * Run on the hub (read-only):
 *   wp --allow-root --path=/var/www/html eval-file /tmp/103-uat-single-version.php [expected-version]
 *
 * Expected version defaults to 1.1.5. Prints PASS/FAIL per assert, then
 * ALL PASS, or exits 1. The cache-buster scan mirrors `bin/stamp-version.sh
 * check` (rules 1, 1b, 1c, 2, 3, 4, multi-line joining, GS-derived variable
 * tracking, same 3-entry allowlist).
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$gs_uat_expected = ( isset( $args[0] ) && '' !== $args[0] ) ? (string) $args[0] : '1.1.5';
$gs_uat_fail     = 0;

$gs_uat = function ( $ok, $label, $detail = '' ) use ( &$gs_uat_fail ) {
	if ( $ok ) {
		echo "PASS {$label}\n";
	} else {
		$gs_uat_fail++;
		echo "FAIL {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
	}
};

// 1. Constant + plugin header.
$gs_uat( defined( 'GEND_SOCIETY_VERSION' ), 'GS_VERSION defined' );
$gs_ver = defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$gs_plugin_dir  = WP_PLUGIN_DIR . '/gend-society';
$gs_plugin_data = get_plugin_data( $gs_plugin_dir . '/gend-society.php', false, false );
$gs_uat(
	isset( $gs_plugin_data['Version'] ) && $gs_plugin_data['Version'] === $gs_ver,
	'plugin header Version === GS_VERSION',
	'header=' . ( $gs_plugin_data['Version'] ?? '(none)' ) . ' GS_VERSION=' . $gs_ver
);

// 2. Bundled theme.
$gs_theme = wp_get_theme( 'gend-society-theme' );
$gs_uat( $gs_theme->exists(), 'theme gend-society-theme exists' );
$gs_uat(
	$gs_theme->exists() && $gs_theme->get( 'Version' ) === $gs_ver,
	'theme Version === GS_VERSION',
	'theme=' . ( $gs_theme->exists() ? $gs_theme->get( 'Version' ) : '(missing)' )
);

// 3. Plugin readme.
$gs_readme = @file_get_contents( $gs_plugin_dir . '/readme.txt' );
$gs_uat(
	is_string( $gs_readme ) && (bool) preg_match( '/^Stable tag:\s*' . preg_quote( $gs_ver, '/' ) . '\s*$/m', $gs_readme ),
	'readme.txt Stable tag === GS_VERSION',
	is_string( $gs_readme ) ? 'Stable tag line differs' : 'readme.txt missing'
);

// 4. Cache-buster scan (port of bin/stamp-version.sh check).
$gs_allow = array(
	array( 'inc/ai-widget.php', "'2.1.0'" ),                                         // hub-hosted leo assets.
	array( 'inc/dashboard-hosting.php', "'?ver=' . filemtime( \$gmo_css_path )" ),   // foreign GMO/blog-manager CSS.
	array( 'inc/dashboard-remote-membership.php', '$gs_bp_ver = PSOO_VER' ),         // projects plugin PSOO_VER.
);
$gs_semq = '/[\'"][0-9]+\.[0-9]+(?:\.[0-9]+)?[^\'"]*[\'"]/';
$gs_bad  = array();
$gs_ok   = 0;

$gs_it = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $gs_plugin_dir, FilesystemIterator::SKIP_DOTS ),
		function ( $f ) use ( $gs_plugin_dir ) {
			$rel = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $gs_plugin_dir ) ) ), '/' );
			return ! preg_match( '#^(handoff|themes|\.git)(/|$)|(^|/)node_modules(/|$)#', $rel );
		}
	)
);

foreach ( $gs_it as $gs_file ) {
	$gs_path = $gs_file->getPathname();
	if ( ! preg_match( '/\.(php|js)$/', $gs_path ) ) {
		continue;
	}
	$rel = ltrim( str_replace( '\\', '/', substr( $gs_path, strlen( $gs_plugin_dir ) ) ), '/' );
	$src = str_replace( "\r\n", "\n", (string) file_get_contents( $gs_path ) );
	// Blank whole-line comments, keeping offsets.
	$src = preg_replace_callback(
		'~^([ \t]*)((?://|#|/\*|\*).*)$~m',
		function ( $m ) {
			return $m[1] . str_repeat( ' ', strlen( $m[2] ) );
		},
		$src
	);

	$ev  = array(); // [offset, kind, text, extra].
	$off = 0;
	foreach ( explode( "\n", $src ) as $line ) {
		if ( preg_match( '/\?v(?:er)?=[\'"]?[0-9]/', $line ) ) {
			$ev[] = array( $off, 'rule1 literal ?ver=', $line, null );
		}
		if ( preg_match( '/\?ver=[\'"]?\s*\.\s*@?filemtime\(/', $line ) ) {
			$ev[] = array( $off, 'rule1b ?ver= from bare filemtime', $line, null );
		} elseif ( preg_match( '/\?v(?:er)?=/', $line ) && false !== strpos( $line, 'filemtime(' ) && false === strpos( $line, 'GEND_SOCIETY_VERSION' ) ) {
			$ev[] = array( $off, 'rule1c echoed ?v= from filemtime without GS_VERSION', $line, null );
		}
		$off += strlen( $line ) + 1;
	}
	if ( '.php' === substr( $rel, -4 ) ) {
		$pos = 0;
		while ( preg_match( '/\$([A-Za-z_0-9]*ver[A-Za-z_0-9]*)\s*=(?![=>])\s*([^;]*);/', $src, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$ev[] = array( $m[0][1], 'assign', $m[0][0], array( $m[1][0], $m[2][0] ) );
			$pos  = $m[0][1] + 1;
		}
		if ( preg_match_all( '/\bwp_(?:enqueue|register)_(?:style|script)\s*\((.*?)\)\s*;/s', $src, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm as $m ) {
				$ev[] = array( $m[0][1], 'enqueue', $m[0][0], $m[1][0] );
			}
		}
	}
	usort(
		$ev,
		function ( $a, $b ) {
			return $a[0] <=> $b[0];
		}
	);

	$gs_vars = array();
	$alt     = function () use ( &$gs_vars ) {
		$a = array( 'GEND_SOCIETY_VERSION' );
		foreach ( array_keys( $gs_vars ) as $v ) {
			$a[] = '\$' . preg_quote( $v, '/' );
		}
		return implode( '|', $a );
	};
	$fm_ok   = function ( $t ) use ( $alt ) {
		$re = '/(?:' . $alt() . ')\s*\.\s*(?:[\'"]\.[\'"]\s*\.\s*)?$/';
		if ( preg_match_all( '/@?filemtime\(/', $t, $fm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $fm[0] as $f ) {
				if ( ! preg_match( $re, substr( $t, 0, $f[1] ) ) ) {
					return false;
				}
			}
		}
		return true;
	};

	foreach ( $ev as $e ) {
		list( $o, $kind, $text, $x ) = $e;
		$hits = array();
		if ( 'assign' === $kind ) {
			$name = $x[0];
			$rhs  = trim( $x[1] );
			$a    = $alt();
			if ( preg_match( $gs_semq, $rhs ) ) {
				$hits[] = "rule2 quoted semver in \${$name} assignment";
			}
			if ( false !== strpos( $rhs, 'filemtime(' ) && ! preg_match( '/^(?:' . $a . ')\b/', $rhs ) && ! $fm_ok( $rhs ) ) {
				$hits[] = "rule4 \${$name} from filemtime without GS_VERSION";
			}
			if ( false !== strpos( $rhs, 'GEND_SOCIETY_VERSION' ) || preg_match( '/^(?:' . $a . ')\b/', $rhs ) ) {
				$gs_vars[ $name ] = true;
			} else {
				unset( $gs_vars[ $name ] );
			}
		} elseif ( 'enqueue' === $kind ) {
			if ( preg_match( $gs_semq, $x ) && ! preg_match( '#https?://#', $x ) ) {
				$hits[] = 'rule3 enqueue with quoted semver literal';
			}
			if ( false !== strpos( $x, 'filemtime(' ) && ! $fm_ok( $x ) ) {
				$hits[] = 'rule4 enqueue version from bare filemtime';
			}
		} else {
			$hits[] = $kind;
		}
		if ( ! $hits ) {
			continue;
		}
		$ln      = 1 + substr_count( substr( $src, 0, $o ), "\n" );
		$norm    = preg_replace( '/\s+/', ' ', $text );
		$allowed = false;
		foreach ( $gs_allow as $al ) {
			if ( $al[0] === $rel && false !== strpos( $norm, preg_replace( '/\s+/', ' ', $al[1] ) ) ) {
				$allowed = true;
				break;
			}
		}
		foreach ( $hits as $h ) {
			if ( $allowed ) {
				$gs_ok++;
				echo "  ALLOWED {$rel}:{$ln} {$h}\n";
			} else {
				$gs_bad[] = "{$rel}:{$ln}: {$h}";
			}
		}
	}
}
$gs_uat( ! $gs_bad, "no hard-coded cache-busters ({$gs_ok} allowlisted)", implode( '; ', $gs_bad ) );

// 5. Expected deployed version.
$gs_uat( $gs_ver === $gs_uat_expected, "GS_VERSION === {$gs_uat_expected}", 'GS_VERSION=' . $gs_ver );

if ( $gs_uat_fail ) {
	echo "{$gs_uat_fail} FAILED\n";
	exit( 1 );
}
echo "ALL PASS\n";
