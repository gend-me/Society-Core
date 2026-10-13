<?php
/**
 * Phase 109 plugin battery: helpers for the plan 109-04 sections (nolock, fetch, refresh, launcher, viewmodel).
 *
 * Handlers run in-process: redirects are captured through the wp_redirect filter and wp_die through the
 * wp_die / wp_die_ajax / wp_die_json handlers (all throw GS109P_Stop), so `exit` is never reached.
 * Real HTTP status codes (403 from a nonce failure, the launcher's 302) are proven with loopback requests to
 * the bed itself (http://localhost:8909) carrying a real session cookie minted for a bed user.
 * The bed is standalone and paired by gs109_fixture_pair(): inc/plan-access.php (needs standalone + paired)
 * was not loaded when this process booted unpaired, so gs109p_require_modules() requires it, as the next
 * request would through the manifest.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GS109P_Stop' ) ) {
	class GS109P_Stop extends Exception {
		public $kind     = '';
		public $location = '';
		public $status   = 0;
	}
}

/** Cache options plan 109-04 owns or shares (legacy features + membership caches, catalogue, hub areas). */
function gs109p_cache_options() {
	return array(
		'gend_society_features_cache',
		'gend_society_features_cache_expires',
		'gend_society_features_cache_fetched_at',
		'gend_society_remote_membership_cache',
		'gend_society_remote_membership_cache_expires',
		'gend_society_remote_membership_cache_fetched_at',
		'gend_society_plan_catalog_cache',
	);
}

function gs109p_clear_caches() {
	global $wpdb;
	foreach ( gs109p_cache_options() as $o ) {
		delete_option( $o );
	}
	foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_hub\\_area\\_cache\\_%'" ) as $o ) {
		delete_option( $o );
	}
	wp_cache_delete( 'alloptions', 'options' );
}

/** Raw option row value (bypasses the options cache). */
function gs109p_raw( $name ) {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	return null === $v ? null : maybe_unserialize( $v );
}

/** Require the standalone + paired modules this process skipped at boot. True when plan-access.php is loaded. */
function gs109p_require_modules() {
	foreach ( array( 'inc/connection-health.php', 'inc/plan-access.php' ) as $f ) {
		$p = GEND_SOCIETY_DIR . $f;
		if ( is_readable( $p ) ) {
			require_once $p;
		}
	}
	return function_exists( 'gend_society_plan_view_model' ) && function_exists( 'gend_society_plan_hub_get' );
}

/** Pair with the fixture hub, load the modules, start from empty caches. Returns the fixture identity or array(). */
function gs109p_pair( $scenario = 'connected' ) {
	$fx = gs109_fixture_pair( $scenario );
	if ( ! $fx ) {
		return array();
	}
	if ( ! gs109_assert( gs109p_require_modules(), 'module-loaded', 'inc/plan-access.php loaded (standalone, paired)' ) ) {
		return array();
	}
	gs109p_clear_caches();
	return $fx;
}

/** Switch scenario and drop every cache, so the next call fetches from the new scenario. */
function gs109p_scenario( $scenario ) {
	gs109_fixture_scenario( $scenario );
	gs109p_clear_caches();
}

/** Record every outbound request (method, url, headers) from now on. */
function gs109p_http_capture() {
	$GLOBALS['gs109p_http'] = array();
	if ( ! empty( $GLOBALS['gs109p_http_on'] ) ) {
		return;
	}
	$GLOBALS['gs109p_http_on'] = true;
	$f = function ( $pre, $args, $url ) {
		$GLOBALS['gs109p_http'][] = array(
			'method'  => strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' ),
			'url'     => (string) $url,
			'headers' => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(),
			'args'    => $args,
		);
		return $pre;
	};
	add_filter( 'pre_http_request', $f, -2000, 3 );
	gs109_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, -2000 );
			$GLOBALS['gs109p_http_on'] = false;
		},
		'gs109p http capture'
	);
}

/** Captured requests since the last reset; $reset clears the list after reading. */
function gs109p_http( $reset = true ) {
	$out = isset( $GLOBALS['gs109p_http'] ) ? (array) $GLOBALS['gs109p_http'] : array();
	if ( $reset ) {
		$GLOBALS['gs109p_http'] = array();
	}
	return $out;
}

/** Only the requests to the hub's install routes (loopback calls to the bed are left out). */
function gs109p_hub_requests( array $list ) {
	return array_values(
		array_filter(
			$list,
			function ( $r ) {
				return false !== strpos( $r['url'], '/wp-json/gdc-app-manager/v1/install/' );
			}
		)
	);
}

function gs109p_header( array $headers, $name ) {
	foreach ( $headers as $k => $v ) {
		if ( strtolower( (string) $k ) === strtolower( $name ) ) {
			return is_array( $v ) ? (string) reset( $v ) : (string) $v;
		}
	}
	return '';
}

/** Byte offset of wp-content/gs109-http.log now (for "nothing logged since"). */
function gs109p_httplog_mark() {
	$p = WP_CONTENT_DIR . '/gs109-http.log';
	clearstatcache( true, $p );
	return is_readable( $p ) ? (int) filesize( $p ) : 0;
}

function gs109p_httplog_since( $mark ) {
	$p = WP_CONTENT_DIR . '/gs109-http.log';
	clearstatcache( true, $p );
	if ( ! is_readable( $p ) ) {
		return '';
	}
	// Request lines only: the gs109-e2e-bed mu-plugin also writes {"marker":"deleted_option"} lines (109-10).
	$lines = explode( "\n", (string) file_get_contents( $p, false, null, (int) $mark ) );
	$keep  = array();
	foreach ( $lines as $l ) {
		if ( '' !== $l && false === strpos( $l, '"marker":' ) ) {
			$keep[] = $l;
		}
	}
	return $keep ? implode( "\n", $keep ) . "\n" : '';
}

/**
 * Integration mode (109-10): answer every request to $alias (scheme://host[:port]) from $real with the same path,
 * method, headers and body, so the REAL hub can stand behind another host name. Returns a remover.
 */
function gs109p_alias_host( $alias, $real ) {
	$alias = rtrim( (string) $alias, '/' );
	$real  = rtrim( (string) $real, '/' );
	$f     = function ( $pre, $args, $url ) use ( $alias, $real ) {
		if ( false !== $pre || 0 !== strpos( (string) $url, $alias . '/' ) ) {
			return $pre;
		}
		return wp_remote_request( $real . substr( (string) $url, strlen( $alias ) ), $args );
	};
	add_filter( 'pre_http_request', $f, 6, 3 );
	$off = function () use ( $f ) {
		remove_filter( 'pre_http_request', $f, 6 );
	};
	gs109_on_cleanup( $off, 'alias ' . $alias );
	return $off;
}

/** Statuses the fixture answered since the last call (e.g. ['GET features -> 304']). */
function gs109p_fixture_answers() {
	$out = array();
	foreach ( gs109_fixture_log_lines() as $l ) {
		if ( preg_match( '/scenario=\S+ (.+)$/', $l, $m ) ) {
			$out[] = $m[1];
		}
	}
	return $out;
}

/** Answer one route with a canned response before the fixture does (priority 5 < fixture 10). Returns remover. */
function gs109p_inject( $route, $status, $body, array $headers = array() ) {
	$f = function ( $pre, $args, $url ) use ( $route, $status, $body, $headers ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '#/install/[^/]+/' . preg_quote( $route, '#' ) . '$#', $path ) ) {
			return $pre;
		}
		if ( $status instanceof WP_Error ) {
			return $status;
		}
		return array(
			'headers'  => array_merge( array( 'content-type' => 'application/json' ), $headers ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => (int) $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $f, 5, 3 );
	$off = function () use ( $f ) {
		remove_filter( 'pre_http_request', $f, 5 );
	};
	gs109_on_cleanup( $off, 'inject ' . $route );
	return $off;
}

/** Capture redirects and wp_die (incl. AJAX/JSON) for the rest of the section. */
function gs109p_setup() {
	$redir = function ( $location, $status ) {
		$e           = new GS109P_Stop( 'redirect' );
		$e->kind     = 'redirect';
		$e->location = (string) $location;
		$e->status   = (int) $status;
		throw $e;
	};
	$die_handler = function () {
		return function ( $message, $title = '', $args = array() ) {
			$text = is_string( $message ) || is_numeric( $message ) ? (string) $message : ( is_wp_error( $message ) ? $message->get_error_message() : 'die' );
			$e         = new GS109P_Stop( $text );
			$e->kind   = 'die';
			$status    = 500;
			if ( is_array( $args ) && array_key_exists( 'response', $args ) ) {
				$status = null === $args['response'] ? 200 : (int) $args['response'];
			} elseif ( is_numeric( $title ) ) {
				$status = (int) $title;
			}
			$e->status = $status;
			throw $e;
		};
	};
	$cli_handler  = 'WP_CLI\Utils\wp_redirect_handler';
	$cli_priority = has_filter( 'wp_redirect', $cli_handler );
	if ( false !== $cli_priority ) {
		remove_filter( 'wp_redirect', $cli_handler, $cli_priority );
		gs109_on_cleanup(
			function () use ( $cli_handler, $cli_priority ) {
				add_filter( 'wp_redirect', $cli_handler, $cli_priority );
			},
			'wp-cli redirect handler'
		);
	}
	add_filter( 'wp_redirect', $redir, PHP_INT_MAX, 2 );
	foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $h ) {
		add_filter( $h, $die_handler, PHP_INT_MAX );
	}
	gs109_on_cleanup(
		function () use ( $redir, $die_handler ) {
			remove_filter( 'wp_redirect', $redir, PHP_INT_MAX );
			foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $h ) {
				remove_filter( $h, $die_handler, PHP_INT_MAX );
			}
			$_GET     = array();
			$_POST    = array();
			$_REQUEST = array();
			wp_set_current_user( 0 );
		},
		'gs109p filters'
	);
}

/**
 * Call a handler with request globals. Returns ['kind' => redirect|die|return, 'location', 'status', 'message',
 * 'output' (echoed text), 'json' (decoded output or null)].
 */
function gs109p_call( callable $fn, $method, array $get = array(), array $post = array(), $ajax = false ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_GET                      = wp_slash( $get );
	$_POST                     = wp_slash( $post );
	$_REQUEST                  = array_merge( $_GET, $_POST );
	$out                       = array(
		'kind'     => 'return',
		'location' => '',
		'status'   => 0,
		'message'  => '',
	);
	if ( $ajax ) {
		add_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
	}
	$depth = isset( $GLOBALS['wp_current_filter'] ) ? count( (array) $GLOBALS['wp_current_filter'] ) : 0;
	ob_start();
	try {
		call_user_func( $fn );
	} catch ( GS109P_Stop $e ) {
		$out = array(
			'kind'     => $e->kind,
			'location' => $e->location,
			'status'   => $e->status,
			'message'  => $e->getMessage(),
		);
	} finally {
		$out['output'] = (string) ob_get_clean();
		// An action aborted by a thrown redirect/die leaves its name on the current-filter stack.
		if ( isset( $GLOBALS['wp_current_filter'] ) && count( (array) $GLOBALS['wp_current_filter'] ) > $depth ) {
			$GLOBALS['wp_current_filter'] = array_slice( (array) $GLOBALS['wp_current_filter'], 0, $depth );
		}
		if ( $ajax ) {
			remove_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
		}
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}
	$out['json'] = json_decode( trim( $out['output'] ), true );
	wp_cache_delete( 'alloptions', 'options' );
	return $out;
}

/** The bed's first administrator. */
function gs109p_admin_id() {
	$u = get_users(
		array(
			'role'    => 'administrator',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => 1,
			'fields'  => 'ID',
		)
	);
	return $u ? (int) $u[0] : 1;
}

/** A user with $role (deleted on cleanup). */
function gs109p_user( $role = 'subscriber' ) {
	$login = 'gs109p' . $role . strtolower( wp_generate_password( 4, false, false ) );
	$uid   = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $uid ) ) {
		return 0;
	}
	gs109_on_cleanup(
		function () use ( $uid ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		},
		'user ' . $role
	);
	return (int) $uid;
}

/** A real login session for $uid: cookies for loopback requests (session destroyed on cleanup). */
function gs109p_session( $uid ) {
	$exp = time() + HOUR_IN_SECONDS;
	$mgr = WP_Session_Tokens::get_instance( $uid );
	$tok = $mgr->create( $exp );
	gs109_on_cleanup(
		function () use ( $mgr, $tok ) {
			$mgr->destroy( $tok );
		},
		'session ' . $uid
	);
	$scheme = is_ssl() ? 'secure_auth' : 'auth';
	$name   = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
	return array(
		'uid'     => (int) $uid,
		'li'      => wp_generate_auth_cookie( $uid, $exp, 'logged_in', $tok ),
		'cookies' => array(
			$name            => wp_generate_auth_cookie( $uid, $exp, $scheme, $tok ),
			LOGGED_IN_COOKIE => wp_generate_auth_cookie( $uid, $exp, 'logged_in', $tok ),
		),
	);
}

/** A nonce that verifies inside a web request made with $session's cookies. */
function gs109p_nonce( array $session, $action ) {
	$had  = array_key_exists( LOGGED_IN_COOKIE, $_COOKIE );
	$prev = $had ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
	$_COOKIE[ LOGGED_IN_COOKIE ] = $session['li'];
	$was = get_current_user_id();
	wp_set_current_user( $session['uid'] );
	$n = wp_create_nonce( $action );
	wp_set_current_user( $was );
	if ( $had ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $prev;
	} else {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}
	return $n;
}

/** Loopback request to this bed. Returns ['status', 'location', 'body', 'error']. */
function gs109p_web( $method, $url, array $session = array(), array $body = array() ) {
	$args = array(
		'method'      => $method,
		'timeout'     => 30,
		'redirection' => 0,
		'cookies'     => isset( $session['cookies'] ) ? $session['cookies'] : array(),
	);
	if ( $body ) {
		$args['body'] = $body;
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return array(
			'status'   => 0,
			'location' => '',
			'body'     => '',
			'error'    => $res->get_error_message(),
		);
	}
	return array(
		'status'   => (int) wp_remote_retrieve_response_code( $res ),
		'location' => (string) wp_remote_retrieve_header( $res, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $res ),
		'error'    => '',
	);
}

/** Fake a wp-admin request for one screen: $_GET['page'] (or the core script name) + a real WP_Screen. */
function gs109p_admin_screen( $page, array $extra = array() ) {
	if ( ! class_exists( 'WP_Screen' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}
	if ( '.php' === substr( $page, -4 ) ) {
		$get                    = $extra;
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/' . $page;
	} else {
		$get                    = array_merge( array( 'page' => $page ), $extra );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';
	}
	$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' );
	return $get;
}

/**
 * Admin slugs to try: every gated prefix / granular / hub slug the gate knows, the plugin's own pages, the
 * pages registered when admin_menu runs on this install, and core screens.
 */
function gs109p_admin_slugs( $with_registered = true ) {
	$slugs = array(
		'index.php', 'plugins.php', 'update-core.php', 'options-general.php', 'users.php', 'themes.php', 'profile.php',
		'edit.php', 'upload.php', 'tools.php', 'site-editor.php', 'post-new.php', 'edit-comments.php', 'nav-menus.php',
		'gend-society', 'gs-portal-connect', 'gs-feature-upgrade', 'gs-users', 'gs-plan', 'gs-hub-groups', 'gs-hub-feature-suite',
		'gs-hub-projects', 'gs-store-x', 'gs-social-x', 'gs-reward-x', 'gs-hosting-x', 'gs-app-x', 'gs-write-x',
	);
	if ( function_exists( 'gend_society_features_area_map' ) ) {
		foreach ( gend_society_features_area_map() as $prefixes ) {
			foreach ( (array) $prefixes as $p ) {
				$slugs[] = rtrim( $p, '-' );
				$slugs[] = $p . 'settings';
			}
		}
		$slugs = array_merge( $slugs, array_keys( gend_society_features_granular_slug_map() ), array_keys( gend_society_features_hub_area_map() ) );
	}
	if ( $with_registered ) { // false under GS109_READONLY: admin_menu makes other plugins cache data
		$slugs = array_merge( $slugs, gs109p_registered_pages() );
	}
	return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
}

/** Page slugs registered by admin_menu on this install (best effort in CLI). */
function gs109p_registered_pages() {
	global $menu, $submenu, $admin_page_hooks, $_registered_pages;
	$found = array();
	try {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		if ( ! is_array( $menu ) ) {
			$menu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
		if ( ! is_array( $submenu ) ) {
			$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
		$was = get_current_user_id();
		wp_set_current_user( gs109p_admin_id() );
		ob_start();
		do_action( 'admin_menu', '' );
		ob_end_clean();
		wp_set_current_user( $was );
		foreach ( (array) $menu as $m ) {
			if ( is_array( $m ) && ! empty( $m[2] ) ) {
				$found[] = (string) $m[2];
			}
		}
		foreach ( (array) $submenu as $items ) {
			foreach ( (array) $items as $m ) {
				if ( is_array( $m ) && ! empty( $m[2] ) ) {
					$found[] = (string) $m[2];
				}
			}
		}
		$found = array_merge( $found, array_keys( (array) $admin_page_hooks ) );
	} catch ( \Throwable $e ) {
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		gs109_info( 'admin_menu in CLI threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' (static slug list still used)' );
	}
	$out = array();
	foreach ( $found as $s ) {
		// URLs like "admin.php?page=x" or "edit.php?post_type=y" -> page slug / script name
		if ( preg_match( '/[?&]page=([^&]+)/', $s, $m ) ) {
			$out[] = sanitize_key( $m[1] );
		} elseif ( false !== strpos( $s, '?' ) ) {
			$out[] = strtok( $s, '?' );
		} elseif ( 0 !== strpos( $s, 'http' ) && false === strpos( $s, '/' ) ) {
			$out[] = '.php' === substr( $s, -4 ) ? $s : sanitize_key( $s );
		}
	}
	return $out;
}

/**
 * The 1.4.0 feature-gates.php (handoff/109-uat/baseline/feature-gates-1.4.0.php.txt, == Society-Core 96d2adf ==
 * live 1.4.0) loaded side by side: every gend_society_features_*() function becomes gs109old_features_*() and
 * the constants GS109OLD_FEATURES_*, filter names stay the same, hooks are not registered.
 */
function gs109p_load_140() {
	if ( function_exists( 'gs109old_features_enforce_redirect' ) ) {
		return true;
	}
	$src = (string) file_get_contents( __DIR__ . '/baseline/feature-gates-1.4.0.php.txt' );
	if ( '' === $src ) {
		return false;
	}
	$src = preg_replace( '/\bgend_society_features_(\w+)(\s*\()/', 'gs109old_features_$1$2', $src );
	$src = str_replace( 'GEND_SOCIETY_FEATURES_', 'GS109OLD_FEATURES_', $src );
	$src = preg_replace( '/^add_action\s*\(/m', 'gs109old_noop(', $src );
	if ( ! function_exists( 'gs109old_noop' ) ) {
		function gs109old_noop() {
		}
	}
	$tmp = get_temp_dir() . 'gs109old-feature-gates-' . getmypid() . '.php';
	file_put_contents( $tmp, $src );
	include $tmp;
	@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return function_exists( 'gs109old_features_enforce_redirect' );
}

/** Run one enforce function for a screen; returns the redirect location or 'none' (or 'die:<status>'). */
function gs109p_gate_decision( $fn, $page, array $extra = array() ) {
	$get = gs109p_admin_screen( $page, $extra );
	$r   = gs109p_call( $fn, 'GET', $get );
	unset( $GLOBALS['current_screen'] );
	if ( 'redirect' === $r['kind'] ) {
		return $r['location'];
	}
	if ( 'die' === $r['kind'] ) {
		return 'die:' . $r['status'];
	}
	return 'none';
}
