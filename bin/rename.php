<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); } // CLI-only repo tooling; never shipped.
/**
 * Phase 105 map-driven, token-aware renamer.
 *
 *   php bin/rename.php [--dry-run] [--root=<dir>] [--report=<json>] [--map=<file>] [--rebase]
 *
 * Reads bin/rename-map.json (next to this script unless --map is given) and
 * renames, inside the plugin tree at --root (default: the plugin root):
 *   - PHP identifiers (T_STRING / T_NAME_FULLY_QUALIFIED) of map functions,
 *     classes and constants: definitions, calls, new, ::, instanceof, hints
 *   - PHP string literals whose ENTIRE content is a map old name, resolved by
 *     call context (option API -> option entry, add_action arg 0 -> hook,
 *     arg 1 -> function, function_exists -> function, class_exists -> class,
 *     defined/define -> constant, otherwise any entry whose decision is
 *     unambiguous); 'wp_ajax_<old>' / 'admin_post_<old>' strings; 'Class::m'
 *   - file-scope variables ($gs_x) tree-wide, partial variables per file
 *   - AJAX / admin-post action names inside JS (assets/**.js, themes/**.js,
 *     inline HTML/JS and strings in PHP): quoted names and action=<old>
 * Entries with "new": null are never touched. Literals passed to never-rename
 * APIs (shortcodes, nonces, WP_Error codes, meta boxes, CPT/taxonomy, handles,
 * menu slugs, REST routes) are left alone and listed as GUARDED LITERALS; when
 * such a literal is also a renamed map name, the run fails with CONFLICT
 * unless the entry carries "keep_guarded": true.
 *
 * Never touched: handoff/ (except 103-uat-single-version.php and
 * 104-uat-runtime.php), bin/, chatflows/ (map strings found there are only
 * reported), the generated files in GS_RN_NEVER_RENAME_FILES, and any line
 * carrying the marker "// gend-society-rename: keep" (or inside a
 * keep-start / keep-end block).
 *
 * Fails (exit 2) on collisions: two olds -> one new; a new that is another
 * entry's old; a new that is already defined/used in the tree while its old
 * still has pending edits (--rebase downgrades the "already used" string
 * check to a warning); a new longer than WordPress allows. Exit 1 on CONFLICT.
 * Idempotent: a second run reports "total edits: 0".
 *
 * @package gend-society
 */

const GS_RN_NEVER_RENAME_FILES = array(
	'inc/compat-aliases.php',
	'inc/compat-bridges.php',
	'inc/bootstrap/key-map.php',
);
const GS_RN_HANDOFF_RENAMED = array(
	'handoff/103-uat-single-version.php',
	'handoff/104-uat-runtime.php',
);
const GS_RN_KEEP = 'gend-society-rename: keep';

// Never-rename API argument positions (same table as bin/inventory.php).
const GS_RN_GUARDED_CALLS = array(
	'add_shortcode' => array( 0 ), 'has_shortcode' => array( 1 ), 'shortcode_exists' => array( 0 ),
	'remove_shortcode' => array( 0 ), 'do_shortcode_tag' => array( 0 ),
	'wp_create_nonce' => array( 0 ), 'wp_nonce_field' => array( 0, 1 ), 'check_ajax_referer' => array( 0, 1 ),
	'check_admin_referer' => array( 0, 1 ), 'wp_verify_nonce' => array( 1 ), 'wp_nonce_url' => array( 1, 2 ),
	'add_meta_box' => array( 0 ), 'remove_meta_box' => array( 0 ),
	'register_post_type' => array( 0 ), 'register_taxonomy' => array( 0, 1 ), 'post_type_exists' => array( 0 ),
	'taxonomy_exists' => array( 0 ),
	'wp_enqueue_script' => array( 0 ), 'wp_enqueue_style' => array( 0 ), 'wp_register_script' => array( 0 ),
	'wp_register_style' => array( 0 ), 'wp_dequeue_script' => array( 0 ), 'wp_dequeue_style' => array( 0 ),
	'wp_deregister_script' => array( 0 ), 'wp_deregister_style' => array( 0 ), 'wp_localize_script' => array( 0, 1 ),
	'wp_add_inline_script' => array( 0 ), 'wp_add_inline_style' => array( 0 ), 'wp_script_is' => array( 0 ),
	'wp_style_is' => array( 0 ), 'wp_set_script_translations' => array( 0 ),
	'add_menu_page' => array( 3 ), 'add_submenu_page' => array( 0, 4 ), 'add_options_page' => array( 3 ),
	'add_management_page' => array( 3 ), 'add_dashboard_page' => array( 3 ), 'remove_menu_page' => array( 0 ),
	'remove_submenu_page' => array( 0, 1 ), 'menu_page_url' => array( 0 ),
	'register_rest_route' => array( 0 ), 'add_rewrite_tag' => array( 0 ), 'add_rewrite_endpoint' => array( 0 ),
	'register_block_type' => array( 0 ), 'register_block_pattern' => array( 0 ), 'register_block_pattern_category' => array( 0 ),
	'WP_Error' => array( 0 ), '->add' => array( 0 ), '->get_error_message' => array( 0 ), '->get_error_data' => array( 0 ),
);

$gs_rn_opts = getopt( '', array( 'dry-run', 'root:', 'report:', 'map:', 'rebase' ) );
$gs_rn_root = rtrim( str_replace( '\\', '/', isset( $gs_rn_opts['root'] ) ? $gs_rn_opts['root'] : dirname( __DIR__ ) ), '/' );
$gs_rn_map  = isset( $gs_rn_opts['map'] ) ? $gs_rn_opts['map'] : __DIR__ . '/rename-map.json';
$gs_rn_dry  = isset( $gs_rn_opts['dry-run'] );

exit( gs_rn_main( $gs_rn_root, $gs_rn_map, $gs_rn_dry, $gs_rn_opts['report'] ?? null, isset( $gs_rn_opts['rebase'] ) ) );

// ---------------------------------------------------------------------------

function gs_rn_main( string $root, string $map_file, bool $dry, ?string $report_file, bool $rebase ): int {
	if ( ! is_file( $map_file ) ) {
		fwrite( STDERR, "rename: map not found: $map_file\n" );
		return 2;
	}
	$map = json_decode( file_get_contents( $map_file ), true );
	if ( ! is_array( $map ) || empty( $map['entries'] ) ) {
		fwrite( STDERR, "rename: map is not valid JSON with entries\n" );
		return 2;
	}
	$idx = gs_rn_index( $map['entries'] );

	$report = array(
		'map_version' => $map['version'] ?? null,
		'root'        => $root,
		'dry_run'     => $dry,
		'files'       => array(),
		'guarded'     => array(),
		'conflicts'   => array(),
		'keep_lines'  => array(),
		'mentions'    => array(),
		'warnings'    => array(),
		'collisions'  => array(),
	);

	// Static collision checks (map only).
	$report['collisions'] = gs_rn_map_collisions( $map['entries'] );

	$php_files = array();
	$js_files  = array();
	foreach ( gs_rn_walk( $root ) as $rel ) {
		if ( in_array( $rel, GS_RN_NEVER_RENAME_FILES, true ) ) {
			$report['warnings'][] = "skipped (generated, never renamed): $rel";
			continue;
		}
		if ( 0 === strpos( $rel, 'chatflows/' ) ) {
			gs_rn_mentions( $root, $rel, $idx, $report );
			continue;
		}
		if ( 0 === strpos( $rel, 'handoff/' ) && ! in_array( $rel, GS_RN_HANDOFF_RENAMED, true ) ) {
			continue;
		}
		if ( substr( $rel, -4 ) === '.php' ) {
			$php_files[] = $rel;
		} elseif ( substr( $rel, -3 ) === '.js' && ( 0 === strpos( $rel, 'assets/' ) || 0 === strpos( $rel, 'themes/' ) ) ) {
			$js_files[] = $rel;
		}
	}

	// Pass 1: compute every edit (no writes) + definitions present in the tree.
	$results = array();
	$defined = array( 'function' => array(), 'class' => array(), 'constant' => array() );
	$literal_seen = array();
	foreach ( $php_files as $rel ) {
		$src             = file_get_contents( "$root/$rel" );
		$results[ $rel ] = gs_rn_php( $rel, $src, $idx, $report, $defined, $literal_seen );
	}
	foreach ( $js_files as $rel ) {
		$src             = file_get_contents( "$root/$rel" );
		$out             = gs_rn_js_text( $src, $idx, $count );
		$results[ $rel ] = array( 'out' => $out, 'edits' => $count, 'src' => $src, 'pending' => $count ? array( 'js' => true ) : array() );
	}

	// Dynamic collision checks (tree): only for entries that still have pending edits.
	$pending = array();
	foreach ( $results as $r ) {
		foreach ( $r['pending'] as $old => $_ ) {
			$pending[ $old ] = true;
		}
	}
	foreach ( $map['entries'] as $e ) {
		if ( null === $e['new'] || ! isset( $pending[ $e['old'] ] ) ) {
			continue;
		}
		$kind = $e['kind'];
		if ( isset( $defined[ $kind ] ) ) {
			$lk_new = 'constant' === $kind ? $e['new'] : strtolower( $e['new'] );
			$lk_old = 'constant' === $kind ? $e['old'] : strtolower( $e['old'] );
			if ( isset( $defined[ $kind ][ $lk_new ] ) && isset( $defined[ $kind ][ $lk_old ] ) ) {
				$report['collisions'][] = "$kind {$e['old']} -> {$e['new']}: the new name is already defined at " . $defined[ $kind ][ $lk_new ];
			}
		} elseif ( 'variable' !== $kind && isset( $literal_seen[ $e['new'] ] ) ) {
			$msg = "$kind {$e['old']} -> {$e['new']}: the new name is already used as a string at " . $literal_seen[ $e['new'] ];
			if ( $rebase ) {
				$report['warnings'][] = $msg . ' (--rebase)';
			} else {
				$report['collisions'][] = $msg;
			}
		}
	}

	$total = 0;
	foreach ( $results as $rel => $r ) {
		if ( $r['edits'] > 0 ) {
			$report['files'][ $rel ] = $r['edits'];
			$total                  += $r['edits'];
		}
	}
	ksort( $report['files'] );
	$report['total_edits'] = $total;

	$fail = 0;
	if ( $report['collisions'] ) {
		$fail = 2;
	} elseif ( $report['conflicts'] ) {
		$fail = 1;
	}
	if ( ! $dry && 0 === $fail ) {
		foreach ( $results as $rel => $r ) {
			if ( $r['edits'] > 0 && $r['out'] !== $r['src'] ) {
				file_put_contents( "$root/$rel", $r['out'] );
			}
		}
	}

	gs_rn_print( $report, $fail );
	if ( $report_file ) {
		file_put_contents( $report_file, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	return $fail;
}

function gs_rn_print( array $r, int $fail ): void {
	echo 'rename: map ' . $r['map_version'] . ' root ' . $r['root'] . ( $r['dry_run'] ? ' (dry run)' : '' ) . "\n";
	foreach ( $r['files'] as $f => $n ) {
		printf( "  %5d  %s\n", $n, $f );
	}
	if ( $r['guarded'] ) {
		echo "GUARDED LITERALS (never renamed):\n";
		foreach ( $r['guarded'] as $g ) {
			echo "  $g\n";
		}
	}
	if ( $r['keep_lines'] ) {
		echo "KEEP LINES (skipped by marker):\n";
		foreach ( $r['keep_lines'] as $k ) {
			echo "  $k\n";
		}
	}
	if ( $r['mentions'] ) {
		echo "MAP STRINGS OUTSIDE THE RENAMED SET (report only):\n";
		foreach ( $r['mentions'] as $m ) {
			echo "  $m\n";
		}
	}
	foreach ( $r['warnings'] as $w ) {
		echo "WARNING: $w\n";
	}
	foreach ( $r['conflicts'] as $c ) {
		echo "CONFLICT: $c\n";
	}
	foreach ( $r['collisions'] as $c ) {
		echo "COLLISION: $c\n";
	}
	echo 'total edits: ' . $r['total_edits'] . "\n";
	if ( $fail ) {
		echo 'rename: FAILED (' . ( 2 === $fail ? 'collision' : 'conflict' ) . "); nothing written\n";
	} elseif ( ! $r['dry_run'] ) {
		echo "rename: applied\n";
	}
}

// ---------------------------------------------------------------------------
// Map index + static collision checks
// ---------------------------------------------------------------------------

function gs_rn_index( array $entries ): array {
	$idx = array(
		'ident'      => array( 'function' => array(), 'class' => array(), 'constant' => array() ),
		'str'        => array(), // old => list of entries
		'var_global' => array(),
		'var_file'   => array(), // file => old => new
		'ajax'       => array(), // old => new (ajax + admin_post)
	);
	foreach ( $entries as $e ) {
		$old = $e['old'];
		if ( 'variable' === $e['kind'] ) {
			if ( null === $e['new'] ) {
				continue;
			}
			if ( 'file' === ( $e['scope'] ?? 'global' ) ) {
				foreach ( (array) ( $e['files'] ?? array() ) as $f ) {
					$idx['var_file'][ $f ][ $old ] = $e['new'];
				}
			} else {
				$idx['var_global'][ $old ] = $e['new'];
			}
			continue;
		}
		$idx['str'][ $old ][] = $e;
		if ( isset( $idx['ident'][ $e['kind'] ] ) && null !== $e['new'] ) {
			$idx['ident'][ $e['kind'] ][ $old ] = $e['new'];
		}
		if ( in_array( $e['kind'], array( 'ajax', 'admin_post' ), true ) && null !== $e['new'] ) {
			$idx['ajax'][ $old ] = $e['new'];
		}
	}
	return $idx;
}

function gs_rn_map_collisions( array $entries ): array {
	$out    = array();
	$by_new = array();
	$olds   = array();
	foreach ( $entries as $e ) {
		$olds[ $e['old'] ] = true;
	}
	$limits = array( 'option' => 191, 'site_option' => 255, 'user_meta' => 255, 'post_meta' => 255, 'group_meta' => 255, 'term_meta' => 255, 'transient' => 172 );
	foreach ( $entries as $e ) {
		if ( null === $e['new'] ) {
			continue;
		}
		$key = in_array( $e['kind'], array( 'function', 'class' ), true ) ? strtolower( $e['new'] ) : $e['new'];
		if ( 'variable' === $e['kind'] ) {
			$key = 'var:' . $e['new'] . '@' . implode( ',', (array) ( $e['files'] ?? array( 'global' ) ) );
		}
		$by_new[ $key ][ $e['old'] ] = $e['kind'];
		if ( $e['new'] !== $e['old'] && isset( $olds[ $e['new'] ] ) && 'variable' !== $e['kind'] ) {
			$out[] = "{$e['kind']} {$e['old']} -> {$e['new']}: the new name is another entry's old name (chained rename)";
		}
		if ( isset( $limits[ $e['kind'] ] ) && strlen( $e['new'] ) > $limits[ $e['kind'] ] - ( empty( $e['prefix'] ) ? 0 : 20 ) ) {
			$out[] = "{$e['kind']} {$e['new']}: longer than WordPress allows ({$limits[$e['kind']]})";
		}
		if ( ! preg_match( '/^\$?[A-Za-z_][A-Za-z0-9_\-]*$/', $e['new'] ) ) {
			$out[] = "{$e['kind']} {$e['old']} -> {$e['new']}: not a valid name";
		}
	}
	foreach ( $by_new as $new => $olds_for ) {
		if ( count( $olds_for ) > 1 ) {
			$out[] = 'two olds -> one new: ' . implode( ', ', array_keys( $olds_for ) ) . " -> $new";
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------

function gs_rn_walk( string $root ): array {
	$out = array();
	if ( is_file( "$root/gend-society.php" ) ) {
		$out[] = 'gend-society.php';
	}
	foreach ( array( 'inc', 'assets', 'themes', 'handoff', 'chatflows' ) as $dir ) {
		if ( ! is_dir( "$root/$dir" ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$rel = substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $root ) + 1 );
			if ( preg_match( '#(^|/)(vendor|node_modules)/#', $rel ) ) {
				continue;
			}
			$out[] = $rel;
		}
	}
	sort( $out );
	return $out;
}

function gs_rn_mentions( string $root, string $rel, array $idx, array &$report ): void {
	$src = @file_get_contents( "$root/$rel" );
	if ( false === $src || ! preg_match( '/\b_?(gs|gdc|gci|GS|GDC|GCI)_/', $src ) ) {
		return;
	}
	if ( preg_match_all( '/\b\$?_?(?:gs|gdc|gci|GS|GDC|GCI)_[A-Za-z0-9_]+/', $src, $m ) ) {
		foreach ( array_unique( $m[0] ) as $w ) {
			$hit = isset( $idx['str'][ $w ] ) && array_filter( $idx['str'][ $w ], fn( $e ) => null !== $e['new'] );
			if ( $hit ) {
				$report['mentions'][] = "$rel: $w";
			}
		}
	}
}

// ---------------------------------------------------------------------------
// JS: AJAX / admin-post action names only
// ---------------------------------------------------------------------------

function gs_rn_js_text( string $text, array $idx, ?int &$count ): string {
	$count = 0;
	if ( ! $idx['ajax'] || ! preg_match( '/(gs|gdc|gci)_/', $text ) ) {
		return $text;
	}
	static $re = null;
	static $re_key = null;
	$key = md5( implode( ',', array_keys( $idx['ajax'] ) ) );
	if ( $re_key !== $key ) {
		$names = array_keys( $idx['ajax'] );
		usort( $names, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		$alt    = implode( '|', array_map( fn( $n ) => preg_quote( $n, '/' ), $names ) );
		$re     = '/(?<=[\'"`])(?:(wp_ajax_(?:nopriv_)?|admin_post_(?:nopriv_)?))?(' . $alt . ')(?=\\\\?[\'"`])|(?<=action=)(' . $alt . ')(?![A-Za-z0-9_])/';
		$re_key = $key;
	}
	return preg_replace_callback(
		$re,
		function ( $m ) use ( $idx, &$count ) {
			$name = '' !== ( $m[2] ?? '' ) ? $m[2] : $m[3];
			$count++;
			return ( $m[1] ?? '' ) . $idx['ajax'][ $name ];
		},
		$text
	);
}

// ---------------------------------------------------------------------------
// PHP
// ---------------------------------------------------------------------------

function gs_rn_skip( $t ): bool {
	return is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
}

function gs_rn_unquote( string $s ): ?array {
	$q = $s[0] ?? '';
	if ( ( "'" === $q || '"' === $q ) && strlen( $s ) >= 2 && substr( $s, -1 ) === $q ) {
		$inner = substr( $s, 1, -1 );
		if ( '"' === $q && preg_match( '/[$\\\\]/', $inner ) ) {
			return null; // interpolation / escapes: not a plain name.
		}
		if ( "'" === $q && false !== strpos( $inner, '\\' ) ) {
			return null;
		}
		return array( $q, $inner );
	}
	return null;
}

/**
 * Context of every string literal: callee + argument index, from a paren stack.
 */
function gs_rn_contexts( array $toks ): array {
	$ctx   = array();
	$stack = array();
	$n     = count( $toks );
	$prev  = -1;
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $toks[ $i ];
		if ( gs_rn_skip( $t ) ) {
			continue;
		}
		$v = is_array( $t ) ? $t[1] : $t;
		if ( '(' === $v ) {
			$callee = null;
			if ( $prev >= 0 && is_array( $toks[ $prev ] ) && in_array( $toks[ $prev ][0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$callee = strtolower( ltrim( $toks[ $prev ][1], '\\' ) );
				$pp     = gs_rn_prev( $toks, $prev );
				if ( $pp >= 0 && is_array( $toks[ $pp ] ) && in_array( $toks[ $pp ][0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					$callee = '->' . $callee;
				} elseif ( $pp >= 0 && is_array( $toks[ $pp ] ) && T_NEW === $toks[ $pp ][0] ) {
					$callee = 'new:' . $callee;
				} elseif ( $pp >= 0 && is_array( $toks[ $pp ] ) && T_DOUBLE_COLON === $toks[ $pp ][0] ) {
					$callee = '::' . $callee;
				} elseif ( $pp >= 0 && is_array( $toks[ $pp ] ) && T_FUNCTION === $toks[ $pp ][0] ) {
					$callee = null; // declaration parameter list.
				}
			} elseif ( $prev >= 0 && is_array( $toks[ $prev ] ) && T_ARRAY === $toks[ $prev ][0] ) {
				$callee = 'array';
			}
			$stack[] = array( $callee, 0 );
		} elseif ( '[' === $v || '{' === $v || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			$stack[] = array( '[', 0 );
		} elseif ( ')' === $v || ']' === $v || '}' === $v ) {
			array_pop( $stack );
		} elseif ( ',' === $v && $stack ) {
			$stack[ count( $stack ) - 1 ][1]++;
		} elseif ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && $stack ) {
			$top        = $stack[ count( $stack ) - 1 ];
			$ctx[ $i ]  = $top;
		}
		$prev = $i;
	}
	return $ctx;
}

function gs_rn_prev( array $toks, int $i ): int {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		if ( ! gs_rn_skip( $toks[ $j ] ) ) {
			return $j;
		}
	}
	return -1;
}

function gs_rn_next( array $toks, int $i ): int {
	$n = count( $toks );
	for ( $j = $i + 1; $j < $n; $j++ ) {
		if ( ! gs_rn_skip( $toks[ $j ] ) ) {
			return $j;
		}
	}
	return -1;
}

/**
 * Map a (callee, arg) context to the entry kinds it selects. null = any.
 * Returns 'guard' for never-rename API positions.
 */
function gs_rn_context_kinds( ?array $c ) {
	if ( null === $c || null === $c[0] ) {
		return null;
	}
	list( $callee, $arg ) = $c;
	$gk = $callee;
	if ( 0 === strpos( $callee, 'new:' ) ) {
		$gk = 'new:wp_error' === $callee ? 'WP_Error' : null;
	}
	if ( null !== $gk && isset( GS_RN_GUARDED_CALLS[ $gk ] ) && in_array( $arg, GS_RN_GUARDED_CALLS[ $gk ], true ) ) {
		return 'guard';
	}
	if ( 0 === strpos( $callee, '->' ) || 0 === strpos( $callee, '::' ) || 0 === strpos( $callee, 'new:' ) ) {
		return null;
	}
	static $keys = null;
	if ( null === $keys ) {
		$keys = array();
		foreach ( array( 'update', 'add', 'get', 'delete' ) as $v ) {
			$keys[ "{$v}_option" ]           = array( 0, array( 'option' ) );
			$keys[ "{$v}_site_option" ]      = array( 0, array( 'site_option' ) );
			$keys[ "{$v}_network_option" ]   = array( 1, array( 'site_option' ) );
			$keys[ "{$v}_blog_option" ]      = array( 1, array( 'option' ) );
			$keys[ "{$v}_user_meta" ]        = array( 1, array( 'user_meta' ) );
			$keys[ "{$v}_user_option" ]      = array( 1, array( 'user_meta' ) );
			$keys[ "{$v}_post_meta" ]        = array( 1, array( 'post_meta' ) );
			$keys[ "{$v}_term_meta" ]        = array( 1, array( 'term_meta' ) );
			$keys[ "groups_{$v}_groupmeta" ] = array( 1, array( 'group_meta' ) );
			$keys[ "bp_{$v}_user_meta" ]     = array( 1, array( 'user_meta' ) );
		}
		foreach ( array( 'set', 'get', 'delete' ) as $v ) {
			$keys[ "{$v}_transient" ]      = array( 0, array( 'transient' ) );
			$keys[ "{$v}_site_transient" ] = array( 0, array( 'transient' ) );
		}
		$keys['register_setting']         = array( 1, array( 'option' ) );
		$keys['register_post_meta']       = array( 1, array( 'post_meta' ) );
		$keys['register_term_meta']       = array( 1, array( 'term_meta' ) );
		$keys['delete_post_meta_by_key']  = array( 0, array( 'post_meta' ) );
		$keys['wp_schedule_event']        = array( 2, array( 'cron', 'hook' ) );
		$keys['wp_schedule_single_event'] = array( 1, array( 'cron', 'hook' ) );
		$keys['wp_next_scheduled']        = array( 0, array( 'cron', 'hook' ) );
		$keys['wp_clear_scheduled_hook']  = array( 0, array( 'cron', 'hook' ) );
		$keys['wp_unschedule_hook']       = array( 0, array( 'cron', 'hook' ) );
		$keys['wp_unschedule_event']      = array( 1, array( 'cron', 'hook' ) );
		foreach ( array( 'do_action', 'do_action_ref_array', 'apply_filters', 'apply_filters_ref_array', 'do_action_deprecated', 'apply_filters_deprecated',
			'add_action', 'add_filter', 'remove_action', 'remove_filter', 'has_action', 'has_filter', 'did_action', 'remove_all_actions', 'remove_all_filters',
			'doing_action', 'doing_filter' ) as $h ) {
			$keys[ $h ] = array( 0, array( 'hook', 'cron', 'ajax', 'admin_post' ) );
		}
		foreach ( array( 'add_action', 'add_filter', 'remove_action', 'remove_filter', 'has_action', 'has_filter', 'usort', 'uasort', 'uksort', 'array_filter',
			'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook', 'add_shortcode' ) as $h ) {
			$keys[ $h . '#1' ] = array( 1, array( 'function' ) );
		}
		foreach ( array( 'function_exists', 'is_callable', 'call_user_func', 'call_user_func_array', 'array_map', 'spl_autoload_register', 'register_shutdown_function', 'set_error_handler' ) as $h ) {
			$keys[ $h ] = array( 0, array( 'function' ) );
		}
		foreach ( array( 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'get_parent_class', 'is_subclass_of#1', 'is_a#1', 'class_alias' ) as $h ) {
			$keys[ $h ] = array( 0, array( 'class' ) );
		}
		$keys['is_subclass_of#1'] = array( 1, array( 'class' ) );
		$keys['is_a#1']           = array( 1, array( 'class' ) );
		$keys['class_alias#1']    = array( 1, array( 'class' ) );
		foreach ( array( 'defined', 'constant', 'define' ) as $h ) {
			$keys[ $h ] = array( 0, array( 'constant', 'operator_config' ) );
		}
	}
	foreach ( array( $callee, $callee . '#1' ) as $k ) {
		if ( isset( $keys[ $k ] ) && $keys[ $k ][0] === $arg ) {
			return $keys[ $k ][1];
		}
	}
	if ( in_array( $callee, array( 'update_metadata', 'add_metadata', 'get_metadata', 'delete_metadata' ), true ) && 2 === $arg ) {
		return array( 'user_meta', 'post_meta', 'term_meta', 'group_meta', 'blog_meta' );
	}
	return null;
}

/**
 * Resolve one literal content against the map in a context.
 * Returns array( new|null, status ) with status 'rename' | 'none' | 'guard' | 'conflict'.
 */
function gs_rn_resolve( string $s, $kinds, array $idx ): array {
	// wp_ajax_<old> / admin_post_<old>.
	if ( preg_match( '/^(wp_ajax_(?:nopriv_)?|admin_post_(?:nopriv_)?)(.+)$/', $s, $m ) && isset( $idx['ajax'][ $m[2] ] ) ) {
		return 'guard' === $kinds ? array( null, 'guard' ) : array( $m[1] . $idx['ajax'][ $m[2] ], 'rename' );
	}
	// 'Class::method' callbacks.
	if ( preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)$/', $s, $m ) && isset( $idx['ident']['class'][ $m[1] ] ) ) {
		return array( $idx['ident']['class'][ $m[1] ] . '::' . $m[2], 'rename' );
	}
	if ( ! isset( $idx['str'][ $s ] ) ) {
		return array( null, 'none' );
	}
	$cands = $idx['str'][ $s ];
	if ( 'guard' === $kinds ) {
		foreach ( $cands as $e ) {
			if ( null !== $e['new'] && empty( $e['keep_guarded'] ) ) {
				return array( null, 'conflict' );
			}
		}
		return array( null, 'guard' );
	}
	if ( is_array( $kinds ) ) {
		$sel = array_values( array_filter( $cands, fn( $e ) => in_array( $e['kind'], $kinds, true ) ) );
		if ( $sel ) {
			$cands = $sel;
		}
	}
	$news = array_unique( array_map( fn( $e ) => null === $e['new'] ? "\0null" : $e['new'], $cands ) );
	if ( count( $news ) > 1 ) {
		return array( null, 'conflict' );
	}
	$new = reset( $news );
	return "\0null" === $new ? array( null, 'none' ) : array( $new, 'rename' );
}

function gs_rn_keep_lines( string $src ): array {
	$keep  = array();
	$block = false;
	foreach ( explode( "\n", $src ) as $n => $line ) {
		if ( false !== strpos( $line, GS_RN_KEEP . '-start' ) ) {
			$block = true;
		}
		if ( $block || false !== strpos( $line, GS_RN_KEEP ) ) {
			$keep[ $n + 1 ] = true;
		}
		if ( false !== strpos( $line, GS_RN_KEEP . '-end' ) ) {
			$block = false;
		}
	}
	return $keep;
}

function gs_rn_php( string $rel, string $src, array $idx, array &$report, array &$defined, array &$literal_seen ): array {
	$toks = token_get_all( $src );
	$ctx  = gs_rn_contexts( $toks );
	$keep = gs_rn_keep_lines( $src );
	$n    = count( $toks );
	$out  = '';
	$edits   = 0;
	$pending = array();
	$line    = 1;
	$stack   = array();
	$pending_scope = null;
	$file_vars     = $idx['var_file'][ $rel ] ?? array();
	$kept_reported = array();

	for ( $i = 0; $i < $n; $i++ ) {
		$t    = $toks[ $i ];
		$text = is_array( $t ) ? $t[1] : $t;
		$id   = is_array( $t ) ? $t[0] : -1;
		$tl   = is_array( $t ) ? $t[2] : $line;
		$span = substr_count( $text, "\n" );
		$line = $tl + $span;

		// Scope tracking (class bodies: methods / class constants are not globals).
		if ( in_array( $id, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
			$p = gs_rn_prev( $toks, $i );
			if ( $p < 0 || ! is_array( $toks[ $p ] ) || T_DOUBLE_COLON !== $toks[ $p ][0] ) {
				$pending_scope = 'class';
			}
		} elseif ( T_FUNCTION === $id && null === $pending_scope ) {
			$pending_scope = 'function';
		} elseif ( -1 === $id && '{' === $text ) {
			$stack[]       = $pending_scope ?? 'block';
			$pending_scope = null;
		} elseif ( in_array( $id, array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			$stack[] = 'block';
		} elseif ( -1 === $id && '}' === $text ) {
			array_pop( $stack );
		} elseif ( -1 === $id && ';' === $text && 'function' === $pending_scope ) {
			$pending_scope = null;
		}
		$in_class_body = $stack && 'class' === end( $stack );

		$kept = false;
		for ( $l = $tl; $l <= $tl + $span; $l++ ) {
			if ( isset( $keep[ $l ] ) ) {
				$kept = true;
			}
		}

		$new = null;
		switch ( $id ) {
			case T_STRING:
			case T_NAME_FULLY_QUALIFIED:
				$new = gs_rn_ident( $toks, $i, $idx, $in_class_body, $defined, $rel, $tl );
				break;
			case T_VARIABLE:
				if ( isset( $file_vars[ $text ] ) ) {
					$new = $file_vars[ $text ];
				} elseif ( isset( $idx['var_global'][ $text ] ) ) {
					$new = $idx['var_global'][ $text ];
				}
				break;
			case T_CONSTANT_ENCAPSED_STRING:
				$u = gs_rn_unquote( $text );
				if ( null === $u ) {
					$new = gs_rn_js_in_string( $text, $idx );
					break;
				}
				list( $q, $s ) = $u;
				if ( preg_match( '/^gend_society_|^GEND_SOCIETY_|^_gend_society_|^Gend_Society_/', $s ) && ! $kept ) {
					$literal_seen[ $s ] = $literal_seen[ $s ] ?? "$rel:$tl";
				}
				// $GLOBALS['gs_x'].
				$p = gs_rn_prev( $toks, $i );
				$pp = $p >= 0 ? gs_rn_prev( $toks, $p ) : -1;
				if ( $pp >= 0 && '[' === $toks[ $p ] && is_array( $toks[ $pp ] ) && '$GLOBALS' === $toks[ $pp ][1] && isset( $idx['var_global'][ '$' . $s ] ) ) {
					$new = $q . substr( $idx['var_global'][ '$' . $s ], 1 ) . $q;
					break;
				}
				$kinds = gs_rn_context_kinds( $ctx[ $i ] ?? null );
				list( $rn, $status ) = gs_rn_resolve( $s, $kinds, $idx );
				if ( 'rename' === $status ) {
					$new = $q . $rn . $q;
				} elseif ( 'guard' === $status ) {
					$report['guarded'][] = "$rel:$tl $s (" . ( $ctx[ $i ][0] ?? '?' ) . ' arg ' . ( $ctx[ $i ][1] ?? '?' ) . ')';
				} elseif ( 'conflict' === $status && ! $kept ) {
					$report['conflicts'][] = "$rel:$tl '$s' in context " . json_encode( $ctx[ $i ] ?? null ) . ': map decisions differ (' . implode( ', ', array_map( fn( $e ) => $e['kind'] . '=' . var_export( $e['new'], true ), $idx['str'][ $s ] ?? array() ) ) . '); resolve in the map (keep_guarded / owner) or mark the line';
				} elseif ( 'none' === $status ) {
					$new = gs_rn_js_in_string( $text, $idx );
				}
				break;
			case T_ENCAPSED_AND_WHITESPACE:
				if ( isset( $idx['str'][ $text ] ) ) {
					list( $rn, $status ) = gs_rn_resolve( $text, null, $idx );
					if ( 'rename' === $status ) {
						$new = $rn;
						break;
					}
				}
				$new = gs_rn_js_in_string( $text, $idx );
				break;
			case T_INLINE_HTML:
				$new = gs_rn_js_in_string( $text, $idx );
				break;
		}
		if ( null !== $new && $new !== $text ) {
			if ( $kept ) {
				$k = "$rel:$tl";
				if ( ! isset( $kept_reported[ $k ] ) ) {
					$report['keep_lines'][] = $k;
					$kept_reported[ $k ]    = true;
				}
				$out .= $text;
				continue;
			}
			$c = 1;
			if ( in_array( $id, array( T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
				gs_rn_js_text( $text, $idx, $jc );
				$c = max( 1, (int) $jc );
			}
			$edits += $c;
			$pending[ gs_rn_old_of( $text, $id ) ] = true;
			$out .= $new;
		} else {
			$out .= $text;
		}
	}
	return array( 'out' => $out, 'edits' => $edits, 'src' => $src, 'pending' => $pending );
}

function gs_rn_old_of( string $text, int $id ): string {
	if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
		return substr( $text, 1, -1 );
	}
	return ltrim( $text, '\\' );
}

function gs_rn_js_in_string( string $text, array $idx ): ?string {
	$out = gs_rn_js_text( $text, $idx, $c );
	return $c ? $out : null;
}

/**
 * Identifier token: function / class / constant by syntactic role.
 */
function gs_rn_ident( array $toks, int $i, array $idx, bool $in_class_body, array &$defined, string $rel, int $line ): ?string {
	$text = $toks[ $i ][1];
	$lead = '\\' === $text[0] ? '\\' : '';
	$name = ltrim( $text, '\\' );
	$p    = gs_rn_prev( $toks, $i );
	$nx   = gs_rn_next( $toks, $i );
	$pv   = $p >= 0 ? $toks[ $p ] : null;
	$nv   = $nx >= 0 ? $toks[ $nx ] : null;
	$pid  = is_array( $pv ) ? $pv[0] : ( $pv ?? '' );
	$nid  = is_array( $nv ) ? $nv[0] : ( $nv ?? '' );

	if ( in_array( $pid, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
		return null; // method, property, class constant, static member.
	}
	if ( T_FUNCTION === $pid || ( '&' === $pid && $p > 0 && is_array( $toks[ gs_rn_prev( $toks, $p ) ] ) && T_FUNCTION === $toks[ gs_rn_prev( $toks, $p ) ][0] ) ) {
		if ( $in_class_body ) {
			return null; // method declaration.
		}
		$defined['function'][ strtolower( $name ) ] = $defined['function'][ strtolower( $name ) ] ?? "$rel:$line";
		return isset( $idx['ident']['function'][ $name ] ) ? $lead . $idx['ident']['function'][ $name ] : null;
	}
	if ( T_CONST === $pid ) {
		if ( $in_class_body ) {
			return null;
		}
		$defined['constant'][ $name ] = $defined['constant'][ $name ] ?? "$rel:$line";
		return isset( $idx['ident']['constant'][ $name ] ) ? $lead . $idx['ident']['constant'][ $name ] : null;
	}
	if ( in_array( $pid, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
		$defined['class'][ strtolower( $name ) ] = $defined['class'][ strtolower( $name ) ] ?? "$rel:$line";
		return isset( $idx['ident']['class'][ $name ] ) ? $lead . $idx['ident']['class'][ $name ] : null;
	}
	if ( 'define' === strtolower( $name ) && '(' === $nid ) {
		// Record define('X', ...) as a definition.
		$a = gs_rn_next( $toks, $nx );
		if ( $a > 0 && is_array( $toks[ $a ] ) && T_CONSTANT_ENCAPSED_STRING === $toks[ $a ][0] ) {
			$u = gs_rn_unquote( $toks[ $a ][1] );
			if ( $u ) {
				$defined['constant'][ $u[1] ] = $defined['constant'][ $u[1] ] ?? "$rel:$line";
			}
		}
		return null;
	}
	// Named argument (foo(name: 1)) or goto label: not a symbol.
	if ( ':' === $nid && in_array( $pid, array( '(', ',' ), true ) ) {
		return null;
	}
	$is_class = in_array( $pid, array( T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_INSTEADOF ), true ) || T_DOUBLE_COLON === $nid
		|| ( ',' === $pid && gs_rn_in_implements( $toks, $p ) );
	if ( $is_class ) {
		return isset( $idx['ident']['class'][ $name ] ) ? $lead . $idx['ident']['class'][ $name ] : null;
	}
	if ( '(' === $nid ) {
		return isset( $idx['ident']['function'][ $name ] ) ? $lead . $idx['ident']['function'][ $name ] : null;
	}
	if ( isset( $idx['ident']['constant'][ $name ] ) ) {
		return $lead . $idx['ident']['constant'][ $name ];
	}
	if ( isset( $idx['ident']['class'][ $name ] ) ) {
		return $lead . $idx['ident']['class'][ $name ]; // type hint, catch, return type.
	}
	return null;
}

function gs_rn_in_implements( array $toks, int $i ): bool {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$t = $toks[ $j ];
		if ( gs_rn_skip( $t ) ) {
			continue;
		}
		if ( is_array( $t ) && in_array( $t[0], array( T_IMPLEMENTS, T_EXTENDS ), true ) ) {
			return true;
		}
		if ( is_array( $t ) && in_array( $t[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) || ',' === $t ) {
			continue;
		}
		return false;
	}
	return false;
}
