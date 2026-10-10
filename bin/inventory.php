<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); } // CLI-only repo tooling; never shipped.
/**
 * Phase 105 inventory of every non-compliant global DEFINED (or owned) by
 * gend-society, plus the map builder that turns it into bin/rename-map.json.
 *
 * Modes (plain PHP CLI, no WordPress):
 *
 *   php bin/inventory.php [--root=<dir>]                       inventory JSON on stdout
 *   php bin/inventory.php --map [--root=<dir>]
 *        [--callers=<label>=<grep-output>[,...]]               rename map JSON on stdout
 *        [--previous=bin/rename-map.json]                      keep hand-reviewed columns
 *   php bin/inventory.php --names=bin/rename-map.json          caller-audit word list
 *
 * Scope: gend-society.php, inc/**, themes/** (*.php). handoff/, bin/,
 * vendor/, node_modules/ are never inventoried. Each file is tagged with its
 * tier from inc/bootstrap/manifest.php (themes/** -> "theme").
 *
 * The map is GENERATED: re-run it on a fresh main before applying the rename.
 * Hand-reviewed columns (owner, external, note, new, keep_guarded) survive a
 * re-run through --previous. Locked policy (105-CONTEXT.md) is encoded below
 * in the GS_INV_* constants, not in the JSON.
 *
 * @package gend-society
 */

const GS_INV_PREFIX_RE = '/^_?(gs|gdc|gci|gdc_nr|gdc_playlist)_[A-Za-z0-9_]+$/';

// Operator configuration: read (defined()/getenv()), never defined by the plugin. Never renamed.
const GS_INV_OPERATOR_CONFIG = array(
	'GS_SHELL_JWT_SECRET', 'GS_SHELL_PTY_URL', 'GS_SHELL_PTY_WSS', 'GS_SHELL_HUB_URL',
	'GS_JITSI_JWT_SECRET', 'GS_CHAIN_GAS_PUBLIC', 'GS_MARKETING_FUND_POOL_PUBLIC',
	'GS_MOBILE_APP_PUBLIC', 'GS_COLLAB_POSITION_CAP_DGEN',
);

// Hooks defined (fired or owned) by another plugin. Never renamed.
const GS_INV_FOREIGN_HOOKS = array(
	'gend_cp_pm_sync_hub_url'                    => 'contracts-and-payments / gend-media-optimizer',
	'aipa_widget_config'                         => 'leo',
	'aas_task_checkout_extra_credit_product_id'  => 'sales-team',
	'gdc_gs_endpoint_content_callbacks'          => 'projects',
);

// Data keys owned by another plugin (or with no owner in gend-society). Never migrated, never renamed.
const GS_INV_FOREIGN_KEYS = array(
	'_gs_fund_advanced_settings'    => 'contracts-and-payments / projects',
	'_gs_gdrive_access_token'       => 'gend-media-optimizer',
	'_gs_gdrive_account_email'      => 'gend-media-optimizer',
	'_gs_gdrive_refresh_token'      => 'gend-media-optimizer',
	'_gs_gdrive_token_expires_at'   => 'gend-media-optimizer',
	'gs_gdrive_picker_api_key'      => 'no code owner (live hub option)',
	'gdc_site_id'                   => 'vendor-app-manager',
	'gdc_bp_group_id'               => 'vendor-app-manager',
	'gdc_membership_id'             => 'vendor-app-manager',
	'gdc_society_pubkey'            => 'vendor-app-manager (WU site meta)',
	'gdc_container_install_id'      => 'vendor-app-manager / container composition',
);
// Foreign key prefixes (any key starting with these is never touched).
const GS_INV_FOREIGN_KEY_PREFIXES = array(
	'_gs_pst_'  => 'projects',
	'gs_pst_'   => 'projects',
	'gend_oauth_token' => 'contracts-and-payments',
	'gend_chain_' => 'contracts-and-payments',
	'aipa_'     => 'leo',
	'em_'       => 'email-manager',
	'_gend_cp_app_id' => 'contracts-and-payments',
);

// Hand review of the 105-01 caller audit (repo + live hub, 2026-10-09). "kind\0old" => columns.
// 'new' => 'auto' applies the prefix rule; null keeps the old name.
const GS_INV_REVIEW = array(
	// Operator-set knobs: read by gend-society, written by nobody in code (wp option update by hand).
	"option\0gs_collab_market_default_b"   => array( 'new' => null, 'owner' => 'operator', 'note' => 'operator-set option (never written in code); keep the name like the GS_* operator constants' ),
	"option\0gs_collab_market_default_ttl" => array( 'new' => null, 'owner' => 'operator', 'note' => 'operator-set option (never written in code); keep the name' ),
	"option\0gs_collab_market_rake_bps"    => array( 'new' => null, 'owner' => 'operator', 'note' => 'operator-set option (never written in code); keep the name' ),
	"option\0gs_collab_position_cap_dgen"  => array( 'new' => null, 'owner' => 'operator', 'note' => 'operator-set option paired with the GS_COLLAB_POSITION_CAP_DGEN operator constant; keep the name' ),
	"option\0gs_gdrive_redirect_uri"       => array( 'new' => null, 'owner' => 'operator', 'note' => 'operator-set option (never written in code); keep the name' ),
	"option\0gs_gdrive_client_id"          => array( 'new' => null, 'owner' => 'operator (shared with gend-media-optimizer)', 'note' => 'operator-set; read by gend-society AND gend-media-optimizer; keep the name (no bridge needed)' ),
	"option\0gs_gdrive_client_secret"      => array( 'new' => null, 'owner' => 'operator (shared with gend-media-optimizer)', 'note' => 'operator-set; read by gend-society AND gend-media-optimizer; keep the name (no bridge needed)' ),
	"site_option\0gs_chat_default_model"   => array( 'new' => null, 'owner' => 'operator', 'note' => 'operator-set network option ("bumped via update_site_option"); keep the name. Same string as the function gs_chat_default_model(): bin/rename.php resolves by call context' ),
	// Shared cache key: email-manager reads this transient by name (postings.php MENU_CACHE_KEY).
	"transient\0gs_admin_menu_structure_v1" => array( 'new' => null, 'owner' => 'gend-society (shared read: email-manager)', 'note' => 'email-manager reads the transient by name for non-network admins; keep the name' ),
	// Read-only keys with no writer anywhere (repo or live): legacy defaults, keep.
	"user_meta\0_gdc_linked_app_name"      => array( 'new' => null, 'owner' => 'none found (legacy read)', 'note' => 'read-only default in member-profile-header; no writer in repo or live; keep' ),
	"user_meta\0_gdc_member_id"            => array( 'new' => null, 'owner' => 'none found (legacy read)', 'note' => 'read-only default in member-profile-header; no writer in repo or live; keep' ),
	"option\0gdc_container_local_marker"   => array( 'new' => null, 'owner' => 'container image / desktop local site', 'note' => 'written outside the plugin (no PHP writer); keep' ),
	// Invalidation hooks: gend-society only listens; anyone (ops, other code) may fire them. Function of the same name IS renamed.
	"hook\0gs_features_invalidate"         => array( 'new' => null, 'owner' => 'public invalidation hook', 'note' => 'listened to by gend-society; fired by nobody in repo or live, but public by design; keep the hook name (the same-named function is renamed; bin/rename.php resolves by call context)' ),
	"hook\0gs_remote_membership_invalidate" => array( 'new' => null, 'owner' => 'public invalidation hook', 'note' => 'listened to by gend-society; public by design; keep the hook name (the same-named function is renamed)' ),
	// Same string used in a never-rename API position: the guarded occurrence keeps the old string on purpose.
	"admin_post\0gs_portal_connect_submit" => array( 'new' => 'auto', 'owner' => 'gend-society', 'keep_guarded' => true, 'note' => 'nonce action of the same name (wp_nonce_field / check_admin_referer in portal-connect.php) stays; both nonce sites are guarded so they keep matching' ),
	"user_meta\0gs_invite_template"        => array( 'new' => 'auto', 'owner' => 'gend-society', 'keep_guarded' => true, 'note' => 'a WP_Error code of the same name (profile-invite.php) stays' ),
	"function\0gs_gcloud_encrypt"          => array( 'new' => 'auto', 'owner' => 'gend-society', 'keep_guarded' => true, 'note' => 'a WP_Error code of the same name (web-shell-gcloud.php) stays' ),
	"function\0gs_gcloud_decrypt"          => array( 'new' => 'auto', 'owner' => 'gend-society', 'keep_guarded' => true, 'note' => 'a WP_Error code of the same name (web-shell-gcloud.php) stays' ),
	// Live-only listener (2026-10-09): contracts-and-payments class-dgen-topup.php hooks this filter at priority 99
	// to blank the DGEN top-up card while gend_dgen_topup_enabled is off. Rename it, but bridge new -> old listeners.
	"hook\0gdc_profile_header_balances"   => array( 'new' => 'auto', 'owner' => 'gend-society', 'external_manual' => array( 'contracts-and-payments' ), 'external_roles' => array( 'listen' => array( 'contracts-and-payments' ) ), 'note' => 'C&P listens (priority 99) to hide the DGEN top-up while purchases are disabled; compat must apply old-name listeners when the new hook fires' ),
	"hook\0gs_install_paired"              => array( 'new' => null, 'owner' => 'public pairing hook', 'note' => 'listened to by gend-society; fired by nobody in repo or live; keep' ),
);

// Never-rename APIs: literals passed in these argument positions are names that live
// outside PHP symbols (stored shortcodes, nonces, error codes, handles, slugs, routes).
// function => list of 0-based argument indexes. Shared with bin/rename.php (copied there).
const GS_INV_GUARDED_CALLS = array(
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
	'wp_send_json_error' => array(), 'WP_Error' => array( 0 ), '->add' => array( 0 ), '->get_error_message' => array( 0 ),
	'is_wp_error_code' => array( 0 ),
);

$gs_inv_args = getopt( '', array( 'root:', 'map', 'callers:', 'previous:', 'names:' ) );
$gs_inv_root = rtrim( str_replace( '\\', '/', isset( $gs_inv_args['root'] ) ? $gs_inv_args['root'] : dirname( __DIR__ ) ), '/' );

if ( isset( $gs_inv_args['names'] ) ) {
	gs_inv_print_names( $gs_inv_args['names'] );
	exit( 0 );
}

$gs_inv = gs_inv_build( $gs_inv_root );
if ( isset( $gs_inv_args['map'] ) ) {
	$callers = array();
	if ( ! empty( $gs_inv_args['callers'] ) ) {
		foreach ( explode( ',', $gs_inv_args['callers'] ) as $spec ) {
			list( $label, $path ) = array_pad( explode( '=', $spec, 2 ), 2, '' );
			$callers[ $label ] = $path;
		}
	}
	$prev = ! empty( $gs_inv_args['previous'] ) && is_file( $gs_inv_args['previous'] )
		? json_decode( file_get_contents( $gs_inv_args['previous'] ), true ) : null;
	echo json_encode( gs_inv_make_map( $gs_inv, $callers, $prev ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}
echo json_encode( $gs_inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );

// ---------------------------------------------------------------------------
// File set + tiers
// ---------------------------------------------------------------------------

function gs_inv_files( string $root ): array {
	$out = array( 'gend-society.php' );
	foreach ( array( 'inc', 'themes' ) as $dir ) {
		if ( ! is_dir( "$root/$dir" ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$rel = substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $root ) + 1 );
			if ( preg_match( '#(^|/)(vendor|node_modules)/#', $rel ) || substr( $rel, -4 ) !== '.php' ) {
				continue;
			}
			$out[] = $rel;
		}
	}
	sort( $out );
	return $out;
}

function gs_inv_tiers( string $root ): array {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	$m     = require $root . '/inc/bootstrap/manifest.php';
	$tiers = array( 'gend-society.php' => 'core' );
	$walk  = function ( $list ) use ( &$walk, &$tiers ) {
		foreach ( $list as $e ) {
			if ( isset( $e['group'] ) ) {
				$walk( $e['modules'] );
				continue;
			}
			$tiers[ $e['file'] ] = $e['tier'];
		}
	};
	$walk( $m['modules'] );
	$walk( $m['partials'] );
	$GLOBALS['gs_inv_partials'] = array();
	foreach ( $m['partials'] as $p ) {
		$GLOBALS['gs_inv_partials'][ $p['file'] ] = isset( $p['loaded_by'] ) ? (array) $p['loaded_by'] : array();
	}
	return $tiers;
}

// ---------------------------------------------------------------------------
// Tokenizer helpers (same conventions as bin/rename.php)
// ---------------------------------------------------------------------------

function gs_inv_tokens( string $src ): array {
	$out  = array();
	$line = 1;
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) ) {
			$out[] = array( $t[0], $t[1], $t[2] );
			$line  = $t[2] + substr_count( $t[1], "\n" );
		} else {
			$out[] = array( -1, $t, $line );
		}
	}
	return $out;
}

function gs_inv_skip( array $t ): bool {
	return in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
}

function gs_inv_next( array $toks, int $i ): int {
	$n = count( $toks );
	for ( $j = $i + 1; $j < $n; $j++ ) {
		if ( ! gs_inv_skip( $toks[ $j ] ) ) {
			return $j;
		}
	}
	return -1;
}

function gs_inv_prev( array $toks, int $i ): int {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		if ( ! gs_inv_skip( $toks[ $j ] ) ) {
			return $j;
		}
	}
	return -1;
}

function gs_inv_unquote( string $s ): ?string {
	$q = $s[0] ?? '';
	if ( ( "'" === $q || '"' === $q ) && substr( $s, -1 ) === $q && strlen( $s ) >= 2 ) {
		$inner = substr( $s, 1, -1 );
		if ( '"' === $q && preg_match( '/[$\\\\]/', $inner ) ) {
			return null;
		}
		return "'" === $q ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner ) : $inner;
	}
	return null;
}

/**
 * Split call arguments starting at the '(' index. Returns list of token-index lists and the ')' index.
 */
function gs_inv_args( array $toks, int $open ): array {
	$args  = array();
	$cur   = array();
	$depth = 0;
	$n     = count( $toks );
	for ( $j = $open + 1; $j < $n; $j++ ) {
		$v = $toks[ $j ][1];
		if ( -1 === $toks[ $j ][0] || T_CURLY_OPEN === $toks[ $j ][0] || T_DOLLAR_OPEN_CURLY_BRACES === $toks[ $j ][0] ) {
			if ( in_array( $v, array( '(', '[', '{', '${' ), true ) ) {
				$depth++;
			} elseif ( in_array( $v, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					$args[] = $cur;
					return array( $args, $j );
				}
				$depth--;
			} elseif ( ',' === $v && 0 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
		}
		if ( ! gs_inv_skip( $toks[ $j ] ) ) {
			$cur[] = $j;
		}
	}
	return array( $args, $n - 1 );
}

/**
 * Token indexes of the expression after $i, up to the ';' (or ')' / ',') that ends it.
 */
function gs_inv_expr( array $toks, int $i ): array {
	$out   = array();
	$depth = 0;
	$n     = count( $toks );
	for ( $j = $i + 1; $j < $n; $j++ ) {
		$v = $toks[ $j ][1];
		if ( -1 === $toks[ $j ][0] ) {
			if ( in_array( $v, array( '(', '[', '{' ), true ) ) {
				$depth++;
			} elseif ( in_array( $v, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					break;
				}
				$depth--;
			} elseif ( ( ';' === $v || ',' === $v ) && 0 === $depth ) {
				break;
			}
		}
		if ( ! gs_inv_skip( $toks[ $j ] ) ) {
			$out[] = $j;
		}
	}
	return $out;
}

/**
 * Describe one argument: literal string, dynamic prefix, constant reference.
 */
function gs_inv_arg_value( array $toks, array $idx, string $class, array $defines, array $class_consts ): array {
	if ( ! $idx ) {
		return array( 'type' => 'none' );
	}
	$first = $toks[ $idx[0] ];
	// $var holding a key assigned earlier in the file ($cache_key = 'gs_x_' . $id).
	if ( 1 === count( $idx ) && T_VARIABLE === $first[0] && isset( $GLOBALS['gs_inv_var_vals'][ $first[1] ] ) ) {
		return $GLOBALS['gs_inv_var_vals'][ $first[1] ] + array( 'via' => $first[1] );
	}
	// Key-builder call: fn(...), self::fn(...), Class::fn(...) whose body returns 'gs_x_' . ...
	$fi = 0;
	if ( count( $idx ) >= 3 && T_DOUBLE_COLON === $toks[ $idx[1] ][0] ) {
		$fi = 2;
	}
	if ( isset( $idx[ $fi + 1 ] ) && T_STRING === $toks[ $idx[ $fi ] ][0] && '(' === $toks[ $idx[ $fi + 1 ] ][1]
		&& isset( $GLOBALS['gs_inv_returns'][ strtolower( $toks[ $idx[ $fi ] ][1] ) ] ) ) {
		$r = $GLOBALS['gs_inv_returns'][ strtolower( $toks[ $idx[ $fi ] ][1] ) ];
		return array( 'type' => 'prefix', 'value' => $r['value'], 'via' => $toks[ $idx[ $fi ] ][1] . '()' );
	}
	if ( 1 === count( $idx ) && T_CONSTANT_ENCAPSED_STRING === $first[0] ) {
		$v = gs_inv_unquote( $first[1] );
		return null === $v ? array( 'type' => 'expr' ) : array( 'type' => 'literal', 'value' => $v );
	}
	if ( T_CONSTANT_ENCAPSED_STRING === $first[0] && isset( $idx[1] ) && '.' === $toks[ $idx[1] ][1] ) {
		$v = gs_inv_unquote( $first[1] );
		return null === $v ? array( 'type' => 'expr' ) : array( 'type' => 'prefix', 'value' => $v );
	}
	if ( -1 === $first[0] && '"' === $first[1] && isset( $idx[1] ) && T_ENCAPSED_AND_WHITESPACE === $toks[ $idx[1] ][0] ) {
		return array( 'type' => 'prefix', 'value' => $toks[ $idx[1] ][1] );
	}
	// CONST or Class::CONST (optionally followed by concatenation -> prefix).
	$is_prefix = false;
	$name      = null;
	if ( T_STRING === $first[0] && ( 1 === count( $idx ) || '.' === $toks[ $idx[1] ][1] ) ) {
		$name      = $first[1];
		$is_prefix = count( $idx ) > 1;
		$v         = $defines[ $name ] ?? null;
		if ( null !== $v ) {
			return array( 'type' => $is_prefix ? 'prefix' : 'literal', 'value' => $v, 'via' => $name );
		}
		return array( 'type' => 'const', 'name' => $name );
	}
	if ( count( $idx ) >= 3 && T_DOUBLE_COLON === $toks[ $idx[1] ][0] && T_STRING === $toks[ $idx[2] ][0] ) {
		$cls = strtolower( $first[1] );
		if ( 'self' === $cls || 'static' === $cls ) {
			$cls = strtolower( $class );
		}
		$key = $cls . '::' . $toks[ $idx[2] ][1];
		if ( isset( $class_consts[ $key ] ) ) {
			$is_prefix = count( $idx ) > 3 && '.' === $toks[ $idx[3] ][1];
			return array( 'type' => $is_prefix ? 'prefix' : 'literal', 'value' => $class_consts[ $key ], 'via' => $first[1] . '::' . $toks[ $idx[2] ][1] );
		}
	}
	return array( 'type' => 'expr' );
}

// ---------------------------------------------------------------------------
// Inventory
// ---------------------------------------------------------------------------

function gs_inv_key_calls(): array {
	// function => [kind, key arg index, op]
	$c = array();
	foreach ( array( 'update' => 'write', 'add' => 'write', 'get' => 'read', 'delete' => 'delete' ) as $verb => $op ) {
		$c[ "{$verb}_option" ]          = array( 'option', 0, $op );
		$c[ "{$verb}_site_option" ]     = array( 'site_option', 0, $op );
		$c[ "{$verb}_network_option" ]  = array( 'site_option', 1, $op );
		$c[ "{$verb}_blog_option" ]     = array( 'option', 1, $op );
		$c[ "{$verb}_user_meta" ]       = array( 'user_meta', 1, $op );
		$c[ "{$verb}_user_option" ]     = array( 'user_meta', 1, $op );
		$c[ "{$verb}_post_meta" ]       = array( 'post_meta', 1, $op );
		$c[ "{$verb}_term_meta" ]       = array( 'term_meta', 1, $op );
		$c[ "groups_{$verb}_groupmeta" ] = array( 'group_meta', 1, $op );
		$c[ "bp_{$verb}_user_meta" ]    = array( 'user_meta', 1, $op );
	}
	$c['set_transient']            = array( 'transient', 0, 'write' );
	$c['get_transient']            = array( 'transient', 0, 'read' );
	$c['delete_transient']         = array( 'transient', 0, 'delete' );
	$c['set_site_transient']       = array( 'site_transient', 0, 'write' );
	$c['get_site_transient']       = array( 'site_transient', 0, 'read' );
	$c['delete_site_transient']    = array( 'site_transient', 0, 'delete' );
	$c['wp_schedule_event']        = array( 'cron', 2, 'write' );
	$c['wp_schedule_single_event'] = array( 'cron', 1, 'write' );
	$c['wp_next_scheduled']        = array( 'cron', 0, 'read' );
	$c['wp_clear_scheduled_hook']  = array( 'cron', 0, 'delete' );
	$c['wp_unschedule_hook']       = array( 'cron', 0, 'delete' );
	$c['wp_unschedule_event']      = array( 'cron', 1, 'delete' );
	$c['delete_post_meta_by_key']  = array( 'post_meta', 0, 'delete' );
	$c['register_setting']         = array( 'option', 1, 'write' );
	$c['register_meta']            = array( 'meta', 1, 'write' );
	$c['register_post_meta']       = array( 'post_meta', 1, 'write' );
	$c['register_term_meta']       = array( 'term_meta', 1, 'write' );
	return $c;
}

function gs_inv_build( string $root ): array {
	$files  = gs_inv_files( $root );
	$tiers  = gs_inv_tiers( $root );
	$parsed = array();
	foreach ( $files as $f ) {
		$parsed[ $f ] = gs_inv_tokens( file_get_contents( "$root/$f" ) );
	}

	// Pass 1: define()/const values and class constant values (for key resolution).
	$defines      = array();
	$class_consts = array();
	foreach ( $parsed as $f => $toks ) {
		$class = '';
		$n     = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $toks[ $i ];
			if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
				$j = gs_inv_next( $toks, $i );
				if ( $j > 0 && T_STRING === $toks[ $j ][0] ) {
					$class = $toks[ $j ][1];
				}
			}
			if ( T_STRING === $t[0] && 'define' === strtolower( $t[1] ) ) {
				$o = gs_inv_next( $toks, $i );
				if ( $o > 0 && '(' === $toks[ $o ][1] ) {
					list( $args ) = gs_inv_args( $toks, $o );
					if ( isset( $args[0], $args[1] ) && 1 === count( $args[0] ) && 1 === count( $args[1] )
						&& T_CONSTANT_ENCAPSED_STRING === $toks[ $args[0][0] ][0] && T_CONSTANT_ENCAPSED_STRING === $toks[ $args[1][0] ][0] ) {
						$defines[ gs_inv_unquote( $toks[ $args[0][0] ][1] ) ] = gs_inv_unquote( $toks[ $args[1][0] ][1] );
					}
				}
			}
			if ( T_CONST === $t[0] ) {
				$j = gs_inv_next( $toks, $i );
				$e = $j > 0 ? gs_inv_next( $toks, $j ) : -1;
				$v = $e > 0 ? gs_inv_next( $toks, $e ) : -1;
				$z = $v > 0 ? gs_inv_next( $toks, $v ) : -1;
				if ( $z > 0 && T_STRING === $toks[ $j ][0] && '=' === $toks[ $e ][1] && T_CONSTANT_ENCAPSED_STRING === $toks[ $v ][0]
					&& in_array( $toks[ $z ][1], array( ';', ',' ), true ) ) {
					$val = gs_inv_unquote( $toks[ $v ][1] );
					if ( '' !== $class ) {
						$class_consts[ strtolower( $class ) . '::' . $toks[ $j ][1] ] = $val;
					} else {
						$defines[ $toks[ $j ][1] ] = $val;
					}
				}
			}
		}
	}

	// Pass 1b: key-builder functions/methods that return a prefixed literal or prefix.
	$GLOBALS['gs_inv_returns']  = array();
	$GLOBALS['gs_inv_var_vals'] = array();
	foreach ( $parsed as $f => $toks ) {
		$fn    = '';
		$class = '';
		$n     = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( in_array( $toks[ $i ][0], array( T_CLASS, T_TRAIT ), true ) ) {
				$j = gs_inv_next( $toks, $i );
				if ( $j > 0 && T_STRING === $toks[ $j ][0] ) {
					$class = $toks[ $j ][1];
				}
			}
			if ( T_FUNCTION === $toks[ $i ][0] ) {
				$j  = gs_inv_next( $toks, $i );
				$fn = ( $j > 0 && T_STRING === $toks[ $j ][0] ) ? $toks[ $j ][1] : '';
			}
			if ( T_RETURN === $toks[ $i ][0] && '' !== $fn && ! isset( $GLOBALS['gs_inv_returns'][ strtolower( $fn ) ] ) ) {
				$idx = gs_inv_expr( $toks, $i );
				$val = gs_inv_arg_value( $toks, $idx, $class, $defines, $class_consts );
				if ( in_array( $val['type'], array( 'literal', 'prefix' ), true ) && preg_match( '/^_?(gs|gdc|gci)_/', $val['value'] ) ) {
					$GLOBALS['gs_inv_returns'][ strtolower( $fn ) ] = $val;
				}
			}
		}
	}

	$inv = array(
		'files'        => array(),
		'definitions'  => array(),
		'calls'        => array(),
		'key_uses'     => array(),
		'hooks'        => array(),
		'guarded'      => array(),
		'literals'     => array(),
		'const_reads'  => array(),
		'class_consts' => $class_consts,
		'partials'     => $GLOBALS['gs_inv_partials'],
		'file_vars'    => array(),
	);
	$key_calls  = gs_inv_key_calls();
	$hook_calls = array(
		'do_action' => 'fire', 'do_action_ref_array' => 'fire', 'apply_filters' => 'fire',
		'apply_filters_ref_array' => 'fire', 'do_action_deprecated' => 'fire', 'apply_filters_deprecated' => 'fire',
		'add_action' => 'listen', 'add_filter' => 'listen', 'remove_action' => 'remove', 'remove_filter' => 'remove',
		'has_action' => 'listen', 'has_filter' => 'listen', 'did_action' => 'listen', 'remove_all_actions' => 'remove',
		'remove_all_filters' => 'remove', 'doing_action' => 'listen', 'doing_filter' => 'listen',
	);
	$superglobals = array( '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE', '$_FILES', '$_ENV', '$_SESSION', '$this' );

	foreach ( $parsed as $f => $toks ) {
		$tier               = isset( $tiers[ $f ] ) ? $tiers[ $f ] : ( 0 === strpos( $f, 'themes/' ) ? 'theme' : 'unlisted' );
		$inv['files'][ $f ] = $tier;
		$stack              = array(); // 'class' | 'function' | 'block'
		$pending            = null;
		$class_name         = '';
		$class_names        = array();
		$in_foreach_as      = false;
		$seen_vars          = array();
		$GLOBALS['gs_inv_var_vals'] = array();
		$n                  = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			$t  = $toks[ $i ];
			$id = $t[0];
			$v  = $t[1];
			$ln = $t[2];
			$at = "$f:$ln";

			// Scope tracking.
			if ( in_array( $id, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
				$p = gs_inv_prev( $toks, $i );
				if ( $p < 0 || T_DOUBLE_COLON !== $toks[ $p ][0] ) {
					$pending = 'class';
					$j       = gs_inv_next( $toks, $i );
					$cn      = ( $j > 0 && T_STRING === $toks[ $j ][0] ) ? $toks[ $j ][1] : '';
					if ( '' !== $cn ) {
						$inv['definitions'][] = array( 'name' => $cn, 'kind' => 'class', 'at' => $at, 'tier' => $tier );
					}
					$class_names[] = $cn;
				}
			} elseif ( T_FUNCTION === $id || T_FN === $id ) {
				if ( T_FUNCTION === $id ) {
					$pending = 'function';
				}
				$j = gs_inv_next( $toks, $i );
				if ( $j > 0 && '&' === $toks[ $j ][1] ) {
					$j = gs_inv_next( $toks, $j );
				}
				if ( T_FUNCTION === $id && $j > 0 && T_STRING === $toks[ $j ][0] ) {
					$kind = 'function';
					for ( $s = count( $stack ) - 1; $s >= 0; $s-- ) {
						if ( 'class' === $stack[ $s ] ) {
							$kind = 'method';
							break;
						}
						if ( 'function' === $stack[ $s ] ) {
							break;
						}
					}
					if ( 'function' === $kind ) {
						$inv['definitions'][] = array( 'name' => $toks[ $j ][1], 'kind' => 'function', 'at' => $at, 'tier' => $tier, 'nested' => in_array( 'function', $stack, true ) );
					}
				}
			} elseif ( '{' === $v || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				$stack[] = ( -1 === $id && null !== $pending ) ? $pending : 'block';
				if ( -1 === $id ) {
					$pending = null;
				}
			} elseif ( '}' === $v ) {
				array_pop( $stack );
			} elseif ( ';' === $v && 'function' === $pending ) {
				$pending = null; // abstract / interface method.
			}
			$in_class = in_array( 'class', $stack, true );
			$in_func  = in_array( 'function', $stack, true );

			// File-scope variables (assigned outside any function/class body).
			if ( T_AS === $id ) {
				$in_foreach_as = true;
			} elseif ( ')' === $v ) {
				$in_foreach_as = false;
			}
			if ( T_VARIABLE === $id && ! $in_func && ! $in_class && 'function' !== $pending && ! in_array( $v, $superglobals, true ) ) {
				$j = gs_inv_next( $toks, $i );
				$assign = $j > 0 && ( '=' === $toks[ $j ][1] || in_array( $toks[ $j ][0], array( T_PLUS_EQUAL, T_MINUS_EQUAL, T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_MUL_EQUAL, T_OR_EQUAL, T_AND_EQUAL ), true ) );
				if ( $assign || $in_foreach_as ) {
					$inv['definitions'][] = array( 'name' => $v, 'kind' => 'variable', 'at' => $at, 'tier' => $tier, 'read_before_assign' => isset( $seen_vars[ $v ] ) && 'read' === $seen_vars[ $v ] );
					$seen_vars[ $v ] = $seen_vars[ $v ] ?? 'assign';
				}
			}
			if ( T_VARIABLE === $id ) {
				$j = gs_inv_next( $toks, $i );
				if ( $j > 0 && '=' === $toks[ $j ][1] ) {
					$val = gs_inv_arg_value( $toks, gs_inv_expr( $toks, $j ), $in_class ? (string) end( $class_names ) : '', $defines, $class_consts );
					if ( in_array( $val['type'], array( 'literal', 'prefix' ), true ) && preg_match( '/^_?(gs|gdc|gci)_/', $val['value'] ) ) {
						unset( $val['via'] );
						$GLOBALS['gs_inv_var_vals'][ $v ] = $val;
					} else {
						unset( $GLOBALS['gs_inv_var_vals'][ $v ] );
					}
				}
				$inv['file_vars'][ $f ][ $v ] = true;
				if ( ! isset( $seen_vars[ $v ] ) ) {
					$seen_vars[ $v ] = 'read';
				}
			}
			// $GLOBALS['x'] assignments anywhere.
			if ( T_VARIABLE === $id && '$GLOBALS' === $v ) {
				$a = gs_inv_next( $toks, $i );
				$b = $a > 0 ? gs_inv_next( $toks, $a ) : -1;
				if ( $b > 0 && '[' === $toks[ $a ][1] && T_CONSTANT_ENCAPSED_STRING === $toks[ $b ][0] ) {
					$inv['definitions'][] = array( 'name' => '$' . gs_inv_unquote( $toks[ $b ][1] ), 'kind' => 'variable', 'at' => $at, 'tier' => $tier, 'via' => '$GLOBALS' );
				}
			}

			// const NAME = ... at file scope.
			if ( T_CONST === $id && ! $in_class ) {
				$j = gs_inv_next( $toks, $i );
				if ( $j > 0 && T_STRING === $toks[ $j ][0] ) {
					$inv['definitions'][] = array( 'name' => $toks[ $j ][1], 'kind' => 'constant', 'at' => $at, 'tier' => $tier );
				}
			}

			// String literals with an old prefix (classification safety net).
			if ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id ) {
				$s = T_CONSTANT_ENCAPSED_STRING === $id ? gs_inv_unquote( $v ) : $v;
				if ( null !== $s && preg_match( '/^_?(gs|gdc|gci|GS|GDC|GCI)_[A-Za-z0-9_]*$/', $s ) ) {
					$inv['literals'][ $s ][] = $at;
				}
			}

			// Calls.
			$fname = null;
			if ( T_STRING === $id || T_NAME_FULLY_QUALIFIED === $id ) {
				$fname = ltrim( $v, '\\' );
			}
			$is_new = false;
			if ( null === $fname ) {
				continue;
			}
			$p      = gs_inv_prev( $toks, $i );
			$pv     = $p >= 0 ? $toks[ $p ] : array( 0, '', 0 );
			$method = in_array( $pv[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true );
			if ( T_DOUBLE_COLON === $pv[0] || T_FUNCTION === $pv[0] || T_CONST === $pv[0] || in_array( $pv[1], array( 'class', 'interface', 'trait' ), true ) ) {
				continue;
			}
			if ( T_NEW === $pv[0] ) {
				$is_new = true;
			}
			$o = gs_inv_next( $toks, $i );
			if ( $o < 0 ) {
				continue;
			}
			if ( '(' !== $toks[ $o ][1] ) {
				// Bare identifier: constant read or class reference.
				if ( ! $method && T_DOUBLE_COLON !== $toks[ $o ][0] && preg_match( '/^(GS|GDC|GCI)_[A-Z0-9_]+$/', $fname ) ) {
					$inv['const_reads'][ $fname ][] = $at;
				}
				if ( T_DOUBLE_COLON === $toks[ $o ][0] || T_INSTANCEOF === $pv[0] ) {
					$inv['calls'][] = array( 'name' => $fname, 'kind' => 'class_ref', 'at' => $at );
				}
				continue;
			}
			list( $args ) = gs_inv_args( $toks, $o );
			$lname        = strtolower( $fname );
			$cur_class    = $in_class ? (string) end( $class_names ) : '';
			if ( $method ) {
				$lname = '->' . $lname;
			} elseif ( $is_new ) {
				$inv['calls'][] = array( 'name' => $fname, 'kind' => 'class_ref', 'at' => $at );
				if ( 'wp_error' === $lname ) {
					$lname = 'WP_Error';
				}
			} else {
				$inv['calls'][] = array( 'name' => $fname, 'kind' => 'call', 'at' => $at );
			}
			$argv = function ( $k ) use ( $args, $toks, $cur_class, $defines, $class_consts ) {
				return isset( $args[ $k ] ) ? gs_inv_arg_value( $toks, $args[ $k ], $cur_class, $defines, $class_consts ) : array( 'type' => 'none' );
			};

			if ( 'define' === $lname ) {
				$a0 = $argv( 0 );
				if ( 'literal' === $a0['type'] ) {
					$inv['definitions'][] = array( 'name' => $a0['value'], 'kind' => 'constant', 'at' => $at, 'tier' => $tier );
				}
			} elseif ( in_array( $lname, array( 'defined', 'constant' ), true ) ) {
				$a0 = $argv( 0 );
				if ( 'literal' === $a0['type'] ) {
					$inv['const_reads'][ $a0['value'] ][] = $at;
				}
			} elseif ( 'getenv' === $lname ) {
				$a0 = $argv( 0 );
				if ( 'literal' === $a0['type'] ) {
					$inv['const_reads'][ $a0['value'] ][] = $at . ' (getenv)';
				}
			} elseif ( in_array( $lname, array( 'function_exists', 'is_callable', 'call_user_func', 'call_user_func_array' ), true ) ) {
				$a0 = $argv( 0 );
				if ( 'literal' === $a0['type'] ) {
					$inv['calls'][] = array( 'name' => $a0['value'], 'kind' => 'callback', 'at' => $at );
				}
			} elseif ( 'class_exists' === $lname ) {
				$a0 = $argv( 0 );
				if ( 'literal' === $a0['type'] ) {
					$inv['calls'][] = array( 'name' => $a0['value'], 'kind' => 'class_ref', 'at' => $at );
				}
			} elseif ( isset( $hook_calls[ $lname ] ) ) {
				$a0 = $argv( 0 );
				if ( in_array( $a0['type'], array( 'literal', 'prefix' ), true ) ) {
					$h = $a0['value'];
					$k = 'hook';
					if ( preg_match( '/^wp_ajax_(nopriv_)?(.+)$/', $h, $mm ) ) {
						$k = 'ajax';
						$h = $mm[2];
					} elseif ( preg_match( '/^admin_post_(nopriv_)?(.+)$/', $h, $mm ) ) {
						$k = 'admin_post';
						$h = $mm[2];
					}
					$inv['hooks'][] = array( 'name' => $h, 'kind' => $k, 'op' => $hook_calls[ $lname ], 'prefix' => 'prefix' === $a0['type'], 'nopriv' => ! empty( $mm[1] ), 'at' => $at, 'tier' => $tier );
				}
				$a1 = $argv( 1 );
				if ( 'literal' === $a1['type'] ) {
					$inv['calls'][] = array( 'name' => $a1['value'], 'kind' => 'callback', 'at' => $at, 'hook_op' => $hook_calls[ $lname ] );
				}
			} elseif ( isset( $key_calls[ $lname ] ) || in_array( $lname, array( 'update_metadata', 'add_metadata', 'get_metadata', 'delete_metadata' ), true ) ) {
				if ( isset( $key_calls[ $lname ] ) ) {
					list( $kind, $k, $op ) = $key_calls[ $lname ];
				} else {
					$t0   = $argv( 0 );
					$kind = ( 'literal' === $t0['type'] ? $t0['value'] : 'any' ) . '_meta';
					$kind = 'group_meta' === $kind ? 'group_meta' : $kind;
					$k    = 2;
					$op   = 0 === strpos( $lname, 'get' ) ? 'read' : ( 0 === strpos( $lname, 'delete' ) ? 'delete' : 'write' );
				}
				$a = $argv( $k );
				if ( in_array( $a['type'], array( 'literal', 'prefix' ), true ) ) {
					$inv['key_uses'][] = array( 'key' => $a['value'], 'kind' => $kind, 'op' => $op, 'prefix' => 'prefix' === $a['type'], 'at' => $at, 'tier' => $tier, 'via' => $a['via'] ?? null );
				} else {
					$inv['key_uses'][] = array( 'key' => null, 'kind' => $kind, 'op' => $op, 'expr' => true, 'at' => $at, 'tier' => $tier, 'arg' => $a );
				}
			}
			$gk = isset( GS_INV_GUARDED_CALLS[ $lname ] ) ? $lname : ( isset( GS_INV_GUARDED_CALLS[ $fname ] ) ? $fname : null );
			if ( null !== $gk ) {
				foreach ( GS_INV_GUARDED_CALLS[ $gk ] as $k ) {
					$a = $argv( $k );
					if ( 'literal' === $a['type'] && preg_match( '/^_?(gs|gdc|gci)_/i', $a['value'] ) ) {
						$inv['guarded'][] = array( 'value' => $a['value'], 'api' => $gk, 'arg' => $k, 'at' => $at );
					}
				}
			}
		}
	}
	ksort( $inv['literals'] );
	ksort( $inv['const_reads'] );
	return $inv;
}

// ---------------------------------------------------------------------------
// Map builder
// ---------------------------------------------------------------------------

function gs_inv_new_name( string $old, string $kind ): ?string {
	if ( 'variable' === $kind ) {
		$r = gs_inv_new_name( substr( $old, 1 ), 'function' );
		return null === $r ? null : '$' . $r;
	}
	if ( 'class' === $kind ) {
		if ( 'GenD_GitHub_Updater' === $old ) {
			return 'Gend_Society_GitHub_Updater';
		}
		if ( preg_match( '/^GS_(.+)$/', $old, $m ) ) {
			return 'Gend_Society_' . $m[1];
		}
		return null;
	}
	if ( preg_match( '/^(GS|GDC|GCI)_([A-Z0-9_]+)$/', $old, $m ) ) {
		return 'GEND_SOCIETY_' . $m[2];
	}
	$lead = '';
	if ( '_' === $old[0] ) {
		$lead = '_';
		$old  = substr( $old, 1 );
	}
	if ( preg_match( '/^gdc_gs_(.+)$/', $old, $m ) ) {
		return $lead . 'gend_society_gs_' . $m[1];
	}
	if ( preg_match( '/^(gs|gdc|gci)_(.+)$/', $old, $m ) ) {
		return $lead . 'gend_society_' . $m[2];
	}
	return null;
}

function gs_inv_prefixed( string $name ): bool {
	return (bool) preg_match( '/^\$?_?(gs|gdc|gci)_/i', $name ) || 'GenD_GitHub_Updater' === $name;
}

function gs_inv_foreign_key( string $key ): ?string {
	if ( isset( GS_INV_FOREIGN_KEYS[ $key ] ) ) {
		return GS_INV_FOREIGN_KEYS[ $key ];
	}
	foreach ( GS_INV_FOREIGN_KEY_PREFIXES as $p => $owner ) {
		if ( 0 === strpos( $key, $p ) ) {
			return $owner;
		}
	}
	return null;
}

/**
 * Parse grep -rn output into name => [labels] using kind-aware context checks.
 */
function gs_inv_parse_callers( array $files, array $names_by_kind ): array {
	$ext = array();
	foreach ( $files as $source => $path ) {
		if ( ! is_file( $path ) ) {
			fwrite( STDERR, "callers file missing: $path\n" );
			continue;
		}
		foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( ! preg_match( '#^(.+?):(\d+):(.*)$#', $line, $m ) ) {
				continue;
			}
			list( , $file, , $text ) = $m;
			$label = gs_inv_label( $file );
			if ( null === $label ) {
				continue;
			}
			$trim = ltrim( $text );
			if ( '' === $trim || preg_match( '#^(//|\*|/\*|\#)#', $trim ) ) {
				continue; // comment line: not a caller.
			}
			if ( ! preg_match_all( '/(\$|->|::|\\\\)?\b([A-Za-z_][A-Za-z0-9_]*)\b/', $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $mm as $hit ) {
				$word = $hit[2][0];
				$lead = $hit[1][0] ?? '';
				foreach ( array( $word, preg_replace( '/^(wp_ajax_nopriv_|wp_ajax_|admin_post_nopriv_|admin_post_)/', '', $word ) ) as $cand ) {
					if ( ! isset( $names_by_kind[ $cand ] ) ) {
						continue;
					}
					foreach ( $names_by_kind[ $cand ] as $kind ) {
						if ( '$' === $lead && 'variable' !== $kind ) {
							continue;
						}
						if ( '->' === $lead ) {
							continue;
						}
						if ( '::' === $lead && 'constant' !== $kind ) {
							continue;
						}
						if ( 'variable' === $kind ) {
							continue; // file-scope partial variables are never read by other plugins.
						}
						if ( $cand !== $word && ! in_array( $kind, array( 'ajax', 'admin_post' ), true ) ) {
							continue;
						}
						$role = gs_inv_caller_role( $kind, $cand, $text );
						if ( null === $role ) {
							continue; // the word is there, but not in a context of this kind.
						}
						$short = preg_replace( '#^.*?/?(plugins|mu-plugins|themes)/#', '$1/', str_replace( '\\', '/', $file ) );
						$ext[ $kind . "\0" . $cand ][ $label ][] = $source . ':' . $short . ':' . $m[2] . ( '' !== $role ? ' [' . $role . ']' : '' );
					}
				}
			}
		}
	}
	return $ext;
}

/**
 * Is this caller line a use of NAME as KIND? null = no; '' = yes; 'fire'/'listen'/'remove' for hooks.
 */
function gs_inv_caller_role( string $kind, string $name, string $text ): ?string {
	$q = preg_quote( $name, '/' );
	switch ( $kind ) {
		case 'function':
			if ( preg_match( '/\bremove_(action|filter)\s*\(.*[\'"]' . $q . '[\'"]/', $text ) ) {
				return 'remove_cb';
			}
			return preg_match( '/(?<![\w$>:])' . $q . '\s*\(|[\'"]' . $q . '[\'"]/', $text ) ? '' : null;
		case 'hook':
			if ( preg_match( '/\b(add_action|add_filter|has_action|has_filter)\s*\(\s*[\'"]' . $q . '[\'"]/', $text ) ) {
				return 'listen';
			}
			if ( preg_match( '/\b(remove_action|remove_filter|remove_all_actions|remove_all_filters)\s*\(\s*[\'"]' . $q . '[\'"]/', $text ) ) {
				return 'remove';
			}
			if ( preg_match( '/\b(do_action|apply_filters)\w*\s*\(\s*[\'"]' . $q . '[\'"]/', $text ) ) {
				return 'fire';
			}
			return null;
		case 'ajax':
		case 'admin_post':
			return preg_match( '/[\'"](wp_ajax_(nopriv_)?|admin_post_(nopriv_)?)?' . $q . '[\'"&]|action=' . $q . '\b|action\s*:\s*[\'"]' . $q . '/', $text ) ? '' : null;
		case 'class':
		case 'constant':
		case 'operator_config':
			return '';
		default: // data keys: quoted, or a class/define constant value.
			return preg_match( '/[\'"]' . $q . '[\'"]/', $text ) ? '' : null;
	}
}

function gs_inv_label( string $file ): ?string {
	$file = str_replace( '\\', '/', $file );
	if ( preg_match( '#(?:^|/)plugins/([^/]+)/#', $file, $m ) ) {
		if ( 'gend-society' === $m[1] ) {
			return null;
		}
		return $m[1];
	}
	if ( preg_match( '#(?:^|/)mu-plugins/(.+)$#', $file, $m ) ) {
		return 'mu:' . $m[1];
	}
	if ( preg_match( '#(?:^|/)themes/([^/]+)/#', $file, $m ) ) {
		return 'theme:' . $m[1];
	}
	if ( preg_match( '#(?:^|/)electron/#', $file ) ) {
		return 'electron-e2e';
	}
	if ( preg_match( '#^(?:\./)?(src|scripts|k8s|python-services|mobile)/#', $file, $m ) ) {
		return 'repo:' . $m[1];
	}
	return 'other:' . $file;
}

function gs_inv_make_map( array $inv, array $callers, ?array $prev ): array {
	$entries = array(); // key "kind\0old" => entry
	$add     = function ( string $old, string $kind, array $extra ) use ( &$entries ) {
		$k = $kind . "\0" . $old;
		if ( ! isset( $entries[ $k ] ) ) {
			$entries[ $k ] = array_merge( array( 'old' => $old, 'new' => null, 'kind' => $kind ), $extra, array( 'at' => array() ) );
		}
		return $k;
	};

	$defined_funcs = array();
	foreach ( $inv['definitions'] as $d ) {
		if ( 'function' === $d['kind'] ) {
			$defined_funcs[ $d['name'] ] = true;
		}
	}
	$defined_consts = array();

	// File-scope variables. Real globals (non-partial files) rename tree-wide; partial
	// variables (inc/pages/* included inside a function) rename only inside their file,
	// unless the includer could hand them in or read them back (then: review, no rename).
	foreach ( $inv['definitions'] as $d ) {
		if ( 'variable' !== $d['kind'] || preg_match( '/^\$(gend_society|gend_gs)_/i', $d['name'] ) ) {
			continue;
		}
		$file    = explode( ':', $d['at'] )[0];
		$partial = isset( $inv['partials'][ $file ] );
		if ( ! $partial && ! gs_inv_prefixed( $d['name'] ) ) {
			continue;
		}
		$k = $add( $d['name'] . ( $partial ? '@' . $file : '' ), 'variable', array( 'owner' => 'gend-society', 'tier' => $d['tier'] ) );
		$entries[ $k ]['old'] = $d['name'];
		$base = substr( $d['name'], 1 );
		$new  = gs_inv_prefixed( $d['name'] ) ? gs_inv_new_name( $d['name'], 'variable' ) : '$gend_society_' . $base;
		if ( $partial ) {
			$entries[ $k ]['scope'] = 'file';
			$entries[ $k ]['files'] = array( $file );
			$clash = array();
			foreach ( $inv['partials'][ $file ] as $inc ) {
				if ( isset( $inv['file_vars'][ $inc ][ $d['name'] ] ) ) {
					$clash[] = $inc;
				}
			}
			if ( ! empty( $d['read_before_assign'] ) ) {
				$entries[ $k ]['note'] = 'read before its first file-scope assignment (may come from the includer); review';
				$new = null;
			} elseif ( $clash ) {
				$entries[ $k ]['note'] = 'includer ' . implode( ', ', $clash ) . ' uses the same variable name; renamed inside the partial only (the partial assigns it before use)';
			}
		} else {
			$entries[ $k ]['scope'] = 'global';
		}
		$entries[ $k ]['new']  = $new;
		$entries[ $k ]['at'][] = $d['at'];
	}

	// Symbols.
	foreach ( $inv['definitions'] as $d ) {
		$name = $d['name'];
		if ( 'variable' === $d['kind'] ) {
			continue;
		}
		if ( ! gs_inv_prefixed( $name ) ) {
			continue;
		}
		if ( 'constant' === $d['kind'] ) {
			$defined_consts[ $name ] = true;
		}
		$k = $add( $name, $d['kind'], array( 'owner' => 'gend-society', 'tier' => $d['tier'] ) );
		$entries[ $k ]['new']  = gs_inv_new_name( $name, $d['kind'] );
		$entries[ $k ]['at'][] = $d['at'];
	}
	// Prefixed functions called but defined elsewhere.
	foreach ( $inv['calls'] as $c ) {
		if ( 'call' !== $c['kind'] && 'callback' !== $c['kind'] ) {
			continue;
		}
		if ( preg_match( '/^(gs|gdc|gci)_[a-z0-9_]+$/', $c['name'] ) && ! isset( $defined_funcs[ $c['name'] ] ) ) {
			$k = $add( $c['name'], 'function', array( 'owner' => 'external', 'tier' => null, 'note' => 'defined outside gend-society; never renamed' ) );
			$entries[ $k ]['at'][] = $c['at'];
		}
	}
	// Operator configuration (read, never defined).
	foreach ( $inv['const_reads'] as $name => $ats ) {
		if ( preg_match( '/^(GS|GDC|GCI)_[A-Z0-9_]+$/', $name ) && ! isset( $defined_consts[ $name ] ) ) {
			$k = $add( $name, 'operator_config', array( 'owner' => 'operator', 'tier' => null, 'note' => 'operator configuration read via defined()/getenv(); never defined by the plugin; never renamed' ) );
			$entries[ $k ]['at'] = array_merge( $entries[ $k ]['at'], array_slice( $ats, 0, 3 ) );
		}
	}
	foreach ( GS_INV_OPERATOR_CONFIG as $name ) {
		$add( $name, 'operator_config', array( 'owner' => 'operator', 'tier' => null, 'note' => 'operator configuration (105-CONTEXT); never renamed' ) );
	}

	// Hooks / AJAX / admin-post.
	$fired = array();
	foreach ( $inv['hooks'] as $h ) {
		if ( 'fire' === $h['op'] || 'hook' !== $h['kind'] ) {
			$fired[ $h['kind'] . "\0" . $h['name'] ] = true;
		}
	}
	$cron_hooks = array();
	foreach ( $inv['key_uses'] as $u ) {
		if ( 'cron' === $u['kind'] && null !== $u['key'] && 'write' === $u['op'] ) {
			$cron_hooks[ $u['key'] ] = true; // WP-Cron fires it; gend-society schedules it.
		}
	}
	foreach ( $inv['hooks'] as $h ) {
		$name = $h['name'];
		if ( isset( GS_INV_FOREIGN_HOOKS[ $name ] ) ) {
			$k = $add( $name, 'hook', array( 'owner' => GS_INV_FOREIGN_HOOKS[ $name ], 'tier' => null, 'note' => 'foreign hook; never renamed' ) );
			$entries[ $k ]['at'][] = $h['at'];
			continue;
		}
		if ( ! preg_match( '/^(gs|gdc|gci)_/', $name ) ) {
			continue;
		}
		$is_own = isset( $fired[ $h['kind'] . "\0" . $name ] ) || ( 'hook' === $h['kind'] && isset( $cron_hooks[ $name ] ) );
		$extra  = array( 'owner' => $is_own ? 'gend-society' : 'external', 'tier' => $h['tier'] );
		if ( $h['prefix'] ) {
			$extra['prefix'] = true;
		}
		if ( ! empty( $h['nopriv'] ) ) {
			$extra['nopriv'] = true;
		}
		$k = $add( $name, $h['kind'], $extra );
		if ( ! empty( $h['nopriv'] ) ) {
			$entries[ $k ]['nopriv'] = true;
		}
		$entries[ $k ]['new']  = $is_own ? gs_inv_new_name( $name, $h['kind'] ) : null;
		if ( ! $is_own ) {
			$entries[ $k ]['note'] = 'listened to by gend-society but fired elsewhere; never renamed';
		}
		$entries[ $k ]['at'][] = $h['at'];
		if ( 'remove' === $h['op'] ) {
			$entries[ $k ]['note'] = trim( ( $entries[ $k ]['note'] ?? '' ) . ' remove_* by name at ' . $h['at'] );
		}
	}

	// Data keys.
	$by_key = array();
	foreach ( $inv['key_uses'] as $u ) {
		if ( null === $u['key'] || ! preg_match( '/^_?(gs|gdc|gci)_/', $u['key'] ) ) {
			continue;
		}
		$kind = in_array( $u['kind'], array( 'site_transient' ), true ) ? 'transient' : $u['kind'];
		$by_key[ $kind . "\0" . $u['key'] ][] = $u;
	}
	foreach ( $by_key as $kk => $uses ) {
		list( $kind, $key ) = explode( "\0", $kk, 2 );
		// A key held in a gend-society-defined constant is owned even when the write goes through a loop variable.
		$writes  = array_filter( $uses, fn( $u ) => 'write' === $u['op'] || ! empty( $u['via'] ) );
		$foreign = gs_inv_foreign_key( $key );
		$extra   = array(
			'owner' => null !== $foreign ? $foreign : ( $writes ? 'gend-society' : 'unknown (read-only in gend-society)' ),
			'tier'  => implode( ',', array_values( array_unique( array_column( $uses, 'tier' ) ) ) ),
		);
		if ( in_array( $kind, array( 'option', 'site_option', 'user_meta', 'post_meta', 'group_meta', 'term_meta' ), true ) ) {
			$extra['scope'] = array( 'option' => 'blog', 'site_option' => 'network', 'user_meta' => 'user', 'post_meta' => 'post', 'group_meta' => 'group', 'term_meta' => 'term' )[ $kind ];
		}
		if ( 'transient' === $kind ) {
			$extra['scope'] = in_array( 'site_transient', array_column( $uses, 'kind' ), true ) ? 'network' : 'blog';
		}
		if ( array_filter( $uses, fn( $u ) => $u['prefix'] ) ) {
			$extra['prefix'] = true;
		}
		$vias = array_values( array_unique( array_filter( array_column( $uses, 'via' ) ) ) );
		if ( $vias ) {
			$extra['via'] = $vias;
		}
		$k = $add( $key, $kind, $extra );
		$entries[ $k ]['new'] = ( null === $foreign && $writes ) ? gs_inv_new_name( $key, $kind ) : null;
		if ( null !== $foreign ) {
			$entries[ $k ]['note'] = 'owned by ' . $foreign . '; never migrated or renamed';
		} elseif ( ! $writes ) {
			$entries[ $k ]['note'] = 'not written by gend-society; never renamed (review)';
		}
		foreach ( $uses as $u ) {
			$entries[ $k ]['at'][] = $u['at'] . ' ' . $u['op'];
		}
	}
	// Read-only keys written through an owned dynamic prefix (e.g. 'gs_hosting_' . $k).
	$fam = function ( $kind ) {
		return in_array( $kind, array( 'option', 'site_option' ), true ) ? 'option' : $kind;
	};
	foreach ( $entries as $pk => $pe ) {
		if ( empty( $pe['prefix'] ) || null === $pe['new'] || in_array( $pe['kind'], array( 'hook', 'ajax', 'admin_post' ), true ) ) {
			continue;
		}
		foreach ( $entries as $k => $e ) {
			if ( null === $e['new'] && $k !== $pk && $fam( $e['kind'] ) === $fam( $pe['kind'] ) && 0 === strpos( $e['old'], $pe['old'] )
				&& null === gs_inv_foreign_key( $e['old'] ) ) {
				$entries[ $k ]['new']   = gs_inv_new_name( $e['old'], $e['kind'] );
				$entries[ $k ]['owner'] = 'gend-society';
				$entries[ $k ]['note']  = 'written through the owned dynamic prefix ' . $pe['old'];
			}
		}
	}

	// Explicit foreign keys even when gend-society does not touch them (renamer refuses them).
	foreach ( GS_INV_FOREIGN_KEYS as $key => $owner ) {
		$found = false;
		foreach ( $entries as $e ) {
			if ( $e['old'] === $key ) {
				$found = true;
			}
		}
		if ( ! $found ) {
			$add( $key, '_gs' === substr( $key, 0, 3 ) ? 'user_meta' : 'option', array( 'owner' => $owner, 'tier' => null, 'note' => 'owned by ' . $owner . '; never migrated or renamed' ) );
		}
	}

	// External callers.
	$names_by_kind = array();
	foreach ( $entries as $e ) {
		$o = 'variable' === $e['kind'] ? substr( $e['old'], 1 ) : $e['old'];
		$names_by_kind[ $o ][] = $e['kind'];
	}
	$ext = $callers ? gs_inv_parse_callers( $callers, $names_by_kind ) : array();

	// Previous (hand-reviewed) columns.
	$prev_idx = array();
	if ( $prev ) {
		foreach ( $prev['entries'] as $pe ) {
			$prev_idx[ $pe['kind'] . "\0" . $pe['old'] ] = $pe;
		}
	}

	$out = array();
	foreach ( $entries as $k => $e ) {
		$o        = 'variable' === $e['kind'] ? substr( $e['old'], 1 ) : $e['old'];
		$hits     = $ext[ $e['kind'] . "\0" . $o ] ?? array();
		$external = array_keys( $hits );
		sort( $external );
		$e['external'] = $external;
		if ( $hits ) {
			$ev = array();
			foreach ( $hits as $label => $locs ) {
				$ev[ $label ] = array_slice( array_values( array_unique( $locs ) ), 0, 4 );
			}
			$e['external_at'] = $ev;
		}
		if ( $hits ) {
			$roles = array();
			foreach ( $hits as $label => $locs ) {
				foreach ( $locs as $loc ) {
					if ( preg_match( '/\[(\w+)\]$/', $loc, $rm ) ) {
						$roles[ $rm[1] ][ $label ] = true;
					}
				}
			}
			if ( 'hook' === $e['kind'] && $roles ) {
				$e['external_roles'] = array_map( 'array_keys', $roles );
			}
			if ( isset( $roles['remove_cb'] ) ) {
				$e['note'] = trim( ( $e['note'] ?? '' ) . ' remove_action/remove_filter BY CALLBACK NAME in ' . implode( ', ', array_keys( $roles['remove_cb'] ) )
					. ': a compat alias cannot carry this; the caller must remove both names before the swap.' );
			}
		}
		if ( isset( GS_INV_REVIEW[ $k ] ) ) {
			$r = GS_INV_REVIEW[ $k ];
			if ( array_key_exists( 'new', $r ) ) {
				$e['new'] = 'auto' === $r['new'] ? gs_inv_new_name( $e['old'], $e['kind'] ) : $r['new'];
			}
			foreach ( array( 'owner', 'note', 'keep_guarded', 'external_manual', 'external_roles' ) as $col ) {
				if ( isset( $r[ $col ] ) ) {
					$e[ $col ] = $r[ $col ];
				}
			}
			$e['reviewed'] = true;
		}
		if ( isset( $prev_idx[ $k ] ) ) {
			$p = $prev_idx[ $k ];
			foreach ( array( 'owner', 'note', 'keep_guarded', 'reviewed', 'autoload', 'by_ref' ) as $col ) {
				if ( array_key_exists( $col, $p ) && ! empty( $p['reviewed'] ) ) {
					$e[ $col ] = $p[ $col ];
				}
			}
			if ( ! empty( $p['reviewed'] ) && array_key_exists( 'new', $p ) ) {
				$e['new'] = $p['new'];
			}
			if ( ! empty( $p['external_manual'] ) ) {
				$e['external_manual'] = $p['external_manual'];
			}
		}
		if ( ! empty( $e['external_manual'] ) ) {
			$e['external'] = array_values( array_unique( array_merge( $e['external'], $e['external_manual'] ) ) );
			sort( $e['external'] );
		}
		$e['at'] = array_slice( array_values( array_unique( $e['at'] ) ), 0, 6 );
		$out[]   = $e;
	}
	usort(
		$out,
		function ( $a, $b ) {
			return strcmp( $a['kind'], $b['kind'] ) ?: strcmp( $a['old'], $b['old'] );
		}
	);
	return array(
		'version'   => '105.1',
		'generated' => 'bin/inventory.php --map; owner/external columns hand-reviewed (reviewed:true entries keep their columns on re-run)',
		'rules'     => array(
			'gs_x -> gend_society_x', 'GS_X -> GEND_SOCIETY_X', 'GS_Foo -> Gend_Society_Foo',
			'gdc_gs_x -> gend_society_gs_x', 'gdc_x / gdc_nr_x / gdc_playlist_x / gci_x -> gend_society_<rest>',
			'_gs_x / _gdc_x meta -> _gend_society_x', 'new:null = never renamed (foreign, operator config, guarded)',
		),
		'entries'   => $out,
	);
}

function gs_inv_print_names( string $map_file ): void {
	$m     = json_decode( file_get_contents( $map_file ), true );
	$names = array();
	foreach ( $m['entries'] as $e ) {
		if ( 'variable' === $e['kind'] ) {
			continue;
		}
		$names[ $e['old'] ] = true;
		if ( in_array( $e['kind'], array( 'ajax' ), true ) ) {
			$names[ 'wp_ajax_' . $e['old'] ]        = true;
			$names[ 'wp_ajax_nopriv_' . $e['old'] ] = true;
		}
		if ( 'admin_post' === $e['kind'] ) {
			$names[ 'admin_post_' . $e['old'] ]        = true;
			$names[ 'admin_post_nopriv_' . $e['old'] ] = true;
		}
	}
	ksort( $names );
	echo implode( "\n", array_keys( $names ) ) . "\n";
}
