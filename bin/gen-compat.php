<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); } // CLI-only repo tooling; never shipped.
/**
 * Phase 105 compat generator: writes the two generated compat files from
 * bin/rename-map.json. Only map entries with a non-empty "external" list (and a
 * non-null "new") get a wrapper, alias or bridge.
 *
 *   php bin/gen-compat.php --part=early [--root=<dir>] [--map=<file>] > inc/compat-bridges.php
 *   php bin/gen-compat.php --part=late  [--root=<dir>] [--map=<file>] > inc/compat-aliases.php
 *   php bin/gen-compat.php --part=honour                               (GS_COLLAB_MARKET_PUBLIC snippet for gend-society.php)
 *
 * early = data bridges (options, site options, user/post/group/term meta) that
 *         must exist before any module reads a key; required by
 *         inc/bootstrap/key-migration.php when the runtime tiers include
 *         "container" and the file exists.
 * late  = function wrappers, class aliases, GS_* constants, old AJAX /
 *         admin-post names, hook bridges; the LAST manifest module (tier
 *         container), so an old cached file that declares gs_x unguarded wins
 *         instead of fataling with "Cannot redeclare".
 *
 * --root is the plugin tree whose function signatures are read (by-reference
 * parameters get an explicit signature instead of a variadic wrapper) and whose
 * manifest says which definitions load inside the bp_include group. Default:
 * the current directory when it holds gend-society.php, else the plugin root.
 * Output is deterministic (same map + tree => byte-identical file).
 *
 * @package gend-society
 */

$gs_gc_opts = getopt( '', array( 'part:', 'root:', 'map:' ) );
$gs_gc_part = $gs_gc_opts['part'] ?? '';
$gs_gc_root = isset( $gs_gc_opts['root'] ) ? $gs_gc_opts['root'] : ( is_file( getcwd() . '/gend-society.php' ) ? getcwd() : dirname( __DIR__ ) );
$gs_gc_root = rtrim( str_replace( '\\', '/', $gs_gc_root ), '/' );
$gs_gc_map  = isset( $gs_gc_opts['map'] ) ? $gs_gc_opts['map'] : __DIR__ . '/rename-map.json';

if ( 'honour' === $gs_gc_part ) {
	echo gs_gc_honour();
	exit( 0 );
}
if ( ! in_array( $gs_gc_part, array( 'early', 'late' ), true ) ) {
	fwrite( STDERR, "usage: php bin/gen-compat.php --part=early|late|honour [--root=<dir>] [--map=<file>]\n" );
	exit( 2 );
}
$gs_gc_m = json_decode( (string) @file_get_contents( $gs_gc_map ), true );
if ( ! is_array( $gs_gc_m ) || empty( $gs_gc_m['entries'] ) ) {
	fwrite( STDERR, "gen-compat: map not found or invalid: $gs_gc_map\n" );
	exit( 2 );
}
echo 'early' === $gs_gc_part ? gs_gc_early( $gs_gc_m ) : gs_gc_late( $gs_gc_m, $gs_gc_root );
exit( 0 );

// ---------------------------------------------------------------------------

function gs_gc_external( array $m, array $kinds ): array {
	$out = array();
	foreach ( $m['entries'] as $e ) {
		if ( in_array( $e['kind'], $kinds, true ) && null !== $e['new'] && ! empty( $e['external'] ) && empty( $e['prefix'] ) ) {
			$out[] = $e;
		}
	}
	usort( $out, fn( $a, $b ) => strcmp( $a['kind'] . $a['old'], $b['kind'] . $b['old'] ) );
	return $out;
}

function gs_gc_q( string $s ): string {
	return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $s ) . "'";
}

function gs_gc_header( array $m, string $file, string $what ): string {
	$v = (string) ( $m['version'] ?? '' );
	return "<?php\n/**\n * $what\n *\n * Full build only (container tier); generated from bin/rename-map.json; do not edit.\n"
		. " * Regenerate: php bin/gen-compat.php --part=" . ( 'inc/compat-bridges.php' === $file ? 'early' : 'late' ) . " > $file\n"
		. " * Map version: $v\n *\n * @package gend-society\n */\n\n"
		. "// phpcs:ignoreFile -- generated compatibility layer: it exists to keep the pre-1.2.0 names working for other plugins.\n\n"
		. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n";
}

// ---------------------------------------------------------------------------
// early: data bridges
// ---------------------------------------------------------------------------

function gs_gc_early( array $m ): string {
	$v       = (string) $m['version'];
	$options = gs_gc_external( $m, array( 'option' ) );
	$site    = gs_gc_external( $m, array( 'site_option' ) );
	$meta    = gs_gc_external( $m, array( 'user_meta', 'post_meta', 'group_meta', 'term_meta' ) );

	$o  = gs_gc_header( $m, 'inc/compat-bridges.php', 'Compat bridges: other plugins still read and write some gend-society keys under their pre-1.2.0 names.' );
	$o .= "/*\n"
		. " * Semantics (copy-not-move migration, see inc/bootstrap/key-migration.php):\n"
		. " *  - Before this blog's flag option gend_society_keys_migrated (network data:\n"
		. " *    site option gend_society_network_keys_migrated) equals the map version, the\n"
		. " *    old rows are authoritative and every bridge is a no-op for reads.\n"
		. " *  - Once the flag is set, a read of an OLD name is served from the NEW key\n"
		. " *    (falling back to the old row when the new one is absent).\n"
		. " *  - Writes are write-through while old rows exist: a sibling writing an OLD name\n"
		. " *    also writes the NEW key, and the old write still happens, so the old row keeps\n"
		. " *    receiving sibling writes and stays a valid rollback source.\n"
		. " *  - 1.2.0's own writes to NEW keys are NOT mirrored back to the old names; they are\n"
		. " *    lost on a rollback to 1.1.x (accepted: rollback re-reads the old rows).\n"
		. " */\n\n";
	$o .= "if ( ! defined( 'GEND_SOCIETY_KEYMAP_VERSION' ) ) {\n\tdefine( 'GEND_SOCIETY_KEYMAP_VERSION', " . gs_gc_q( $v ) . " );\n}\n\n";

	$arr = function ( array $list ) {
		$s = "array(\n";
		foreach ( $list as $e ) {
			$s .= "\t\t" . gs_gc_q( $e['old'] ) . ' => ' . gs_gc_q( $e['new'] ) . ",\n";
		}
		return $s . "\t)";
	};
	$o .= "/**\n * Old => new key pairs that other plugins use (from the caller audit).\n *\n * @return array<string, array<string, string>>\n */\n";
	$o .= "function gend_society_compat_bridge_keys() {\n\treturn array(\n";
	$o .= "\t\t'option'      => " . str_replace( "\n\t", "\n\t\t", $arr( $options ) ) . ",\n";
	$o .= "\t\t'site_option' => " . str_replace( "\n\t", "\n\t\t", $arr( $site ) ) . ",\n";
	foreach ( array( 'user', 'post', 'group', 'term' ) as $t ) {
		$list = array_values( array_filter( $meta, fn( $e ) => $e['kind'] === $t . '_meta' ) );
		$o   .= "\t\t" . str_pad( gs_gc_q( $t . '_meta' ), 13 ) . ' => ' . str_replace( "\n\t", "\n\t\t", $arr( $list ) ) . ",\n";
	}
	$o .= "\t);\n}\n\n";

	$o .= <<<'PHP'
/**
 * True once this blog's (or, for network data, the network's) key migration ran.
 *
 * @param bool $network Network-level data (site options, user meta, group meta).
 */
function gend_society_compat_migrated( $network ) {
	if ( $network ) {
		return GEND_SOCIETY_KEYMAP_VERSION === get_site_option( 'gend_society_network_keys_migrated' );
	}
	return GEND_SOCIETY_KEYMAP_VERSION === get_option( 'gend_society_keys_migrated' );
}

/**
 * Register the option, site-option and metadata bridges. Called once by
 * inc/bootstrap/key-migration.php before any module loads.
 */
function gend_society_compat_bridges() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$keys = gend_society_compat_bridge_keys();
	$busy = array();

	foreach ( $keys['option'] as $old => $new ) {
		add_filter(
			'pre_option_' . $old,
			static function ( $pre ) use ( $new ) {
				if ( false !== $pre || ! gend_society_compat_migrated( false ) ) {
					return $pre;
				}
				$val = get_option( $new, null );
				return null === $val ? $pre : $val;
			}
		);
		add_filter(
			'pre_update_option_' . $old,
			static function ( $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ $new ] ) ) {
					$busy[ $new ] = true;
					update_option( $new, $value );
					unset( $busy[ $new ] );
				}
				return $value; // the old row keeps receiving the write (rollback source).
			}
		);
		add_action(
			'add_option_' . $old,
			static function ( $option, $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ $new ] ) ) {
					$busy[ $new ] = true;
					update_option( $new, $value );
					unset( $busy[ $new ] );
				}
			},
			10,
			2
		);
		add_action(
			'delete_option_' . $old,
			static function () use ( $new ) {
				delete_option( $new );
			}
		);
	}

	foreach ( $keys['site_option'] as $old => $new ) {
		add_filter(
			'pre_site_option_' . $old,
			static function ( $pre ) use ( $new ) {
				if ( false !== $pre || ! gend_society_compat_migrated( true ) ) {
					return $pre;
				}
				$val = get_site_option( $new, null );
				return null === $val ? $pre : $val;
			}
		);
		add_filter(
			'pre_update_site_option_' . $old,
			static function ( $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ 'site:' . $new ] ) ) {
					$busy[ 'site:' . $new ] = true;
					update_site_option( $new, $value );
					unset( $busy[ 'site:' . $new ] );
				}
				return $value;
			}
		);
		add_action(
			'add_site_option_' . $old,
			static function ( $option, $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ 'site:' . $new ] ) ) {
					$busy[ 'site:' . $new ] = true;
					update_site_option( $new, $value );
					unset( $busy[ 'site:' . $new ] );
				}
			},
			10,
			2
		);
		add_action(
			'delete_site_option_' . $old,
			static function () use ( $new ) {
				delete_site_option( $new );
			}
		);
	}

	foreach ( array( 'user', 'post', 'group', 'term' ) as $type ) {
		$map = $keys[ $type . '_meta' ];
		if ( ! $map ) {
			continue;
		}
		$network = in_array( $type, array( 'user', 'group' ), true );
		add_filter(
			"get_{$type}_metadata",
			static function ( $check, $object_id, $meta_key, $single ) use ( $map, $type, $network ) {
				if ( null !== $check || ! is_string( $meta_key ) || ! isset( $map[ $meta_key ] ) || ! gend_society_compat_migrated( $network ) ) {
					return $check;
				}
				$vals = get_metadata( $type, $object_id, $map[ $meta_key ], false );
				if ( ! is_array( $vals ) || ! $vals ) {
					return $check; // no new row yet: the old row answers.
				}
				return $single ? array( $vals[0] ) : $vals;
			},
			10,
			4
		);
		foreach ( array( 'update', 'add' ) as $verb ) {
			add_filter(
				"{$verb}_{$type}_metadata",
				static function ( $check, $object_id, $meta_key, $meta_value ) use ( $map, $type, &$busy ) {
					if ( null !== $check || ! is_string( $meta_key ) || ! isset( $map[ $meta_key ] ) ) {
						return $check;
					}
					$b = $type . ':' . $map[ $meta_key ] . ':' . $object_id;
					if ( empty( $busy[ $b ] ) ) {
						$busy[ $b ] = true;
						update_metadata( $type, $object_id, $map[ $meta_key ], $meta_value );
						unset( $busy[ $b ] );
					}
					return $check; // null: the old write proceeds too.
				},
				10,
				4
			);
		}
		add_filter(
			"delete_{$type}_metadata",
			static function ( $check, $object_id, $meta_key ) use ( $map, $type, &$busy ) {
				if ( null !== $check || ! is_string( $meta_key ) || ! isset( $map[ $meta_key ] ) ) {
					return $check;
				}
				$b = 'del:' . $type . ':' . $map[ $meta_key ] . ':' . $object_id;
				if ( empty( $busy[ $b ] ) ) {
					$busy[ $b ] = true;
					delete_metadata( $type, $object_id, $map[ $meta_key ] );
					unset( $busy[ $b ] );
				}
				return $check;
			},
			10,
			3
		);
	}
}

PHP;
	return $o;
}

// ---------------------------------------------------------------------------
// late: wrappers, aliases, constants, AJAX, hooks
// ---------------------------------------------------------------------------

function gs_gc_group_files( string $root ): array {
	$files = array();
	$mf    = "$root/inc/bootstrap/manifest.php";
	if ( ! is_file( $mf ) ) {
		fwrite( STDERR, "gen-compat: no manifest at $mf; assuming no bp_include group members\n" );
		return $files;
	}
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	$m = require $mf;
	foreach ( $m['modules'] as $e ) {
		if ( isset( $e['group'] ) ) {
			foreach ( $e['modules'] as $g ) {
				$files[ $g['file'] ] = $e['hook'];
			}
		}
	}
	return $files;
}

/**
 * Find a function definition (by old or new name) and return its parameter source if it
 * has a by-reference parameter or returns by reference; '' when a variadic wrapper is safe.
 */
function gs_gc_signature( string $root, array $e, ?bool &$byref_return ): ?string {
	$byref_return = false;
	$file         = isset( $e['at'][0] ) ? explode( ':', $e['at'][0] )[0] : '';
	$candidates   = $file && is_file( "$root/$file" ) ? array( $file ) : array();
	if ( ! $candidates ) {
		return '';
	}
	$toks = token_get_all( file_get_contents( "$root/{$candidates[0]}" ) );
	$n    = count( $toks );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! is_array( $toks[ $i ] ) || T_FUNCTION !== $toks[ $i ][0] ) {
			continue;
		}
		$j   = $i + 1;
		$ref = false;
		while ( $j < $n && ( ( is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) || '&' === $toks[ $j ] || ( is_array( $toks[ $j ] ) && T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG === $toks[ $j ][0] ) ) ) {
			if ( ! is_array( $toks[ $j ] ) || T_WHITESPACE !== $toks[ $j ][0] ) {
				$ref = true;
			}
			$j++;
		}
		if ( ! is_array( $toks[ $j ] ) || T_STRING !== $toks[ $j ][0] || ! in_array( $toks[ $j ][1], array( $e['old'], $e['new'] ), true ) ) {
			continue;
		}
		// Parameter list source.
		while ( $j < $n && '(' !== $toks[ $j ] ) {
			$j++;
		}
		$depth  = 0;
		$params = '';
		$has_ref = false;
		for ( $k = $j; $k < $n; $k++ ) {
			$t = $toks[ $k ];
			$v = is_array( $t ) ? $t[1] : $t;
			if ( '(' === $v ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $v ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			}
			if ( is_array( $t ) && T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG === $t[0] ) {
				$has_ref = true;
			}
			$params .= $v;
		}
		$byref_return = $ref;
		return ( $has_ref || $ref ) ? trim( preg_replace( '/\s+/', ' ', $params ) ) : '';
	}
	return '';
}

function gs_gc_late( array $m, string $root ): string {
	$groups = gs_gc_group_files( $root );
	$o      = gs_gc_header( $m, 'inc/compat-aliases.php', 'Compat aliases: pre-1.2.0 function, class, constant and AJAX names that other plugins still use.' );
	$o     .= "// Loaded LAST (manifest tier container). Every name is guarded: an old cached file that\n"
		. "// still declares gs_x() unguarded loads first and wins instead of fataling.\n\n";

	// Constants.
	$consts = array( 'GS_VERSION' => 'GEND_SOCIETY_VERSION', 'GS_DIR' => 'GEND_SOCIETY_DIR', 'GS_URL' => 'GEND_SOCIETY_URL' );
	foreach ( gs_gc_external( $m, array( 'constant' ) ) as $e ) {
		$consts[ $e['old'] ] = $e['new'];
	}
	ksort( $consts );
	$o .= "// Constants probed by other plugins (GS_VERSION: menu parent slugs, feature detection).\n";
	foreach ( $consts as $old => $new ) {
		$o .= 'if ( ! defined( ' . gs_gc_q( $old ) . ' ) && defined( ' . gs_gc_q( $new ) . ' ) ) {' . "\n\tdefine( " . gs_gc_q( $old ) . ", $new );\n}\n";
	}
	$o .= "// GS_COLLAB_MARKET_PUBLIC: wp-config may still define the old name; gend-society.php honours it\n"
		. "// (see bin/gen-compat.php --part=honour). Readers of the old name get the effective value.\n"
		. "if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) && defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) ) {\n\tdefine( 'GS_COLLAB_MARKET_PUBLIC', GEND_SOCIETY_COLLAB_MARKET_PUBLIC );\n}\n\n";

	// Functions.
	$top   = '';
	$group = array();
	foreach ( gs_gc_external( $m, array( 'function' ) ) as $e ) {
		$sig  = gs_gc_signature( $root, $e, $byref_return );
		$file = isset( $e['at'][0] ) ? explode( ':', $e['at'][0] )[0] : '';
		$who  = implode( ', ', $e['external'] );
		$code = '/* ' . $who . " */\n";
		$code .= 'if ( ! function_exists( ' . gs_gc_q( $e['old'] ) . ' ) && function_exists( ' . gs_gc_q( $e['new'] ) . " ) ) {\n";
		if ( '' === $sig ) {
			$code .= "\tfunction {$e['old']}( ...\$args ) {\n\t\treturn {$e['new']}( ...\$args );\n\t}\n";
		} else {
			// By-reference parameter or return: explicit signature (a variadic wrapper would copy).
			preg_match_all( '/(\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)/', $sig, $pm, PREG_SET_ORDER );
			$call = implode( ', ', array_map( fn( $p ) => ( $p[1] ? '...' : '' ) . '$' . $p[2], $pm ) );
			if ( $byref_return ) {
				$code .= "\tfunction &{$e['old']}( $sig ) {\n\t\t\$r = &{$e['new']}( $call );\n\t\treturn \$r;\n\t}\n";
			} else {
				$code .= "\tfunction {$e['old']}( $sig ) {\n\t\treturn {$e['new']}( $call );\n\t}\n";
			}
		}
		$code .= "}\n";
		if ( isset( $groups[ $file ] ) ) {
			$group[ $groups[ $file ] ][] = $code;
		} else {
			$top .= $code;
		}
	}
	$o .= "// Functions called by other plugins (caller audit: repo + live hub).\n" . $top . "\n";

	// Classes.
	$ctop = '';
	foreach ( gs_gc_external( $m, array( 'class' ) ) as $e ) {
		$file = isset( $e['at'][0] ) ? explode( ':', $e['at'][0] )[0] : '';
		$code = '/* ' . implode( ', ', $e['external'] ) . " */\n"
			. 'if ( class_exists( ' . gs_gc_q( $e['new'] ) . ', false ) && ! class_exists( ' . gs_gc_q( $e['old'] ) . ", false ) ) {\n"
			. "\tclass_alias( " . gs_gc_q( $e['new'] ) . ', ' . gs_gc_q( $e['old'] ) . " );\n}\n";
		if ( isset( $groups[ $file ] ) ) {
			$group[ $groups[ $file ] ][] = $code;
		} else {
			$ctop .= $code;
		}
	}
	$o .= "// Classes used by other plugins.\n" . $ctop . "\n";

	// Group members (BP group tabs): their definitions exist only after the loader's group closure (priority 10).
	ksort( $group );
	foreach ( $group as $hook => $codes ) {
		$body = implode( '', $codes );
		$body = preg_replace( '/^/m', "\t\t", rtrim( $body ) );
		$o   .= "// Definitions loaded by the '$hook' group run at priority 10; alias them right after.\n";
		$o   .= "add_action(\n\t" . gs_gc_q( $hook ) . ",\n\tstatic function () {\n" . $body . "\n\t},\n\t11\n);\n\n";
	}

	// AJAX / admin-post: old action names call the same handlers (cached HTML/JS + sibling JS).
	$pairs = array();
	foreach ( $m['entries'] as $e ) {
		if ( in_array( $e['kind'], array( 'ajax', 'admin_post' ), true ) && null !== $e['new'] ) {
			$pairs[ $e['old'] ] = array( $e['new'], $e['kind'] );
		}
	}
	ksort( $pairs );
	$o .= "/**\n * Old AJAX / admin-post action names (every renamed action, for one release).\n *\n * @return array<string, string> old => new.\n */\n";
	$o .= "function gend_society_compat_action_names() {\n\treturn array(\n";
	foreach ( $pairs as $old => $p ) {
		$o .= "\t\t" . gs_gc_q( $old ) . ' => ' . gs_gc_q( $p[0] ) . ', // ' . $p[1] . "\n";
	}
	$o .= "\t);\n}\n\n";
	$o .= <<<'PHP'
/**
 * Mirror every handler of a renamed action onto its old name, at the same priority.
 * Runs on admin_init (admin-ajax.php and admin-post.php dispatch after it), once.
 */
function gend_society_compat_mirror_actions() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	global $wp_filter;
	foreach ( gend_society_compat_action_names() as $old => $new ) {
		foreach ( array( 'wp_ajax_', 'wp_ajax_nopriv_', 'admin_post_', 'admin_post_nopriv_' ) as $p ) {
			if ( empty( $wp_filter[ $p . $new ] ) || ! ( $wp_filter[ $p . $new ] instanceof WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_filter[ $p . $new ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( false === has_action( $p . $old, $cb['function'] ) ) {
						add_action( $p . $old, $cb['function'], $priority, $cb['accepted_args'] );
					}
				}
			}
		}
	}
}
add_action( 'admin_init', 'gend_society_compat_mirror_actions', PHP_INT_MAX );

PHP;

	// Hooks with external fire/listen.
	$hooks = gs_gc_external( $m, array( 'hook' ) );
	if ( $hooks ) {
		$o .= "// Hooks other plugins fire (old name -> new listeners) or listen to (new fire -> old listeners).\n";
		foreach ( $hooks as $e ) {
			$roles = $e['external_roles'] ?? array( 'fire' => $e['external'] );
			if ( isset( $roles['fire'] ) ) {
				$o .= '/* fired by ' . implode( ', ', $roles['fire'] ) . " */\n";
				$o .= "add_filter(\n\t" . gs_gc_q( $e['old'] ) . ",\n\tstatic function ( \$value = null, ...\$args ) {\n"
					. "\t\tstatic \$busy = false;\n\t\tif ( \$busy ) {\n\t\t\treturn \$value;\n\t\t}\n\t\t\$busy  = true;\n"
					. "\t\t\$value = apply_filters( " . gs_gc_q( $e['new'] ) . ", \$value, ...\$args );\n\t\t\$busy  = false;\n\t\treturn \$value;\n\t},\n\t10,\n\t99\n);\n";
			}
			if ( isset( $roles['listen'] ) ) {
				$o .= '/* listened to by ' . implode( ', ', $roles['listen'] ) . " */\n";
				$o .= "add_filter(\n\t" . gs_gc_q( $e['new'] ) . ",\n\tstatic function ( \$value = null, ...\$args ) {\n"
					. "\t\tstatic \$busy = false;\n\t\tif ( \$busy ) {\n\t\t\treturn \$value;\n\t\t}\n\t\t\$busy  = true;\n"
					. "\t\t\$value = apply_filters_deprecated( " . gs_gc_q( $e['old'] ) . ', array_merge( array( $value ), $args ), ' . gs_gc_q( '1.2.0' ) . ', ' . gs_gc_q( $e['new'] ) . " );\n\t\t\$busy  = false;\n\t\treturn \$value;\n\t},\n\t10,\n\t99\n);\n";
			}
		}
	}
	return $o;
}

function gs_gc_honour(): string {
	return "// Honour a wp-config pre-defined GS_COLLAB_MARKET_PUBLIC (pre-1.2.0 name). gend-society-rename: keep\n"
		. "if ( defined( 'GS_COLLAB_MARKET_PUBLIC' ) && ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) ) { // gend-society-rename: keep\n"
		. "\tdefine( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC', (bool) constant( 'GS_COLLAB_MARKET_PUBLIC' ) ); // gend-society-rename: keep\n"
		. "} // gend-society-rename: keep\n";
}
