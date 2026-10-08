<?php
/**
 * Phase 104 admin/nav dump (FND-02 equivalence evidence). Plain PHP, NOT wp-cli.
 *
 *   php 104-admin-dump.php <mode> <host[/site-path]> <wp-path> <user-login> [member-slug] [group-slug]
 *
 *   mode     admin    $menu / $submenu of the site's wp-admin
 *            network  network admin $menu / $submenu
 *            member   BuddyPress member nav for /members/<member-slug>/
 *            group    BuddyPress group nav for /groups/<group-slug>/
 *   host     e.g. gend.me, or gend.me/somesite for a sub-directory site
 *   wp-path  WordPress root (directory containing wp-load.php)
 *
 * Example (hub pod, before and after a deploy, then diff):
 *   php /tmp/104-admin-dump.php admin gend.me /var/www/html someadmin > admin.json
 *
 * READ-ONLY. This script never saves anything: it does not fire admin_init,
 * does not render any screen (no editor, no list tables) and never calls
 * update_option / update_user_meta. Loading WordPress still runs the normal
 * plugins_loaded/init hooks, exactly like any wp-cli read.
 *
 * Why the determine_current_user pre-hook: the menus and navs are
 * capability-filtered, so they must be built as a real user. Instead of
 * minting auth cookies (a forged logged-in session that renders screens can
 * write the user's real preferences, e.g. editor fullscreen mode), the
 * script seeds $GLOBALS['wp_filter'] before WordPress loads. WordPress turns
 * that array into pre-initialised hooks, so a priority-99
 * determine_current_user callback resolves the given login to its user id.
 * No cookie, nonce, session token or user meta is created.
 *
 * @package gend-society
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$gs104d_usage = "usage: php 104-admin-dump.php <admin|network|member|group> <host[/site-path]> <wp-path> <user-login> [member-slug] [group-slug]\n";
$gs104d_mode  = isset( $argv[1] ) ? (string) $argv[1] : '';
$gs104d_host  = isset( $argv[2] ) ? (string) $argv[2] : '';
$gs104d_path  = isset( $argv[3] ) ? rtrim( (string) $argv[3], '/' ) : '';
$gs104d_login = isset( $argv[4] ) ? (string) $argv[4] : '';
$gs104d_mslug = isset( $argv[5] ) ? (string) $argv[5] : '';
$gs104d_gslug = isset( $argv[6] ) ? (string) $argv[6] : '';

if ( ! in_array( $gs104d_mode, array( 'admin', 'network', 'member', 'group' ), true )
	|| '' === $gs104d_host || '' === $gs104d_path || '' === $gs104d_login
	|| ! is_file( $gs104d_path . '/wp-load.php' )
	|| ( 'member' === $gs104d_mode && '' === $gs104d_mslug )
	|| ( 'group' === $gs104d_mode && '' === $gs104d_gslug ) ) {
	fwrite( STDERR, $gs104d_usage );
	exit( 2 );
}

// host[/site-path] -> HTTP_HOST + URI prefix for sub-directory sites.
$gs104d_parts  = explode( '/', trim( $gs104d_host, '/' ), 2 );
$gs104d_domain = $gs104d_parts[0];
$gs104d_prefix = isset( $gs104d_parts[1] ) && '' !== $gs104d_parts[1] ? '/' . trim( $gs104d_parts[1], '/' ) : '';

switch ( $gs104d_mode ) {
	case 'admin':
		$gs104d_uri  = $gs104d_prefix . '/wp-admin/index.php';
		break;
	case 'network':
		$gs104d_uri  = '/wp-admin/network/index.php';
		break;
	case 'member':
		$gs104d_uri  = $gs104d_prefix . '/members/' . rawurlencode( $gs104d_mslug ) . '/';
		break;
	default:
		$gs104d_uri  = $gs104d_prefix . '/groups/' . rawurlencode( $gs104d_gslug ) . '/';
}

$_SERVER['HTTP_HOST']       = $gs104d_domain;
$_SERVER['SERVER_NAME']     = $gs104d_domain;
$_SERVER['SERVER_PORT']     = '443';
$_SERVER['HTTPS']           = 'on';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = $gs104d_uri;
$_SERVER['PHP_SELF']        = ( 'admin' === $gs104d_mode || 'network' === $gs104d_mode ) ? $gs104d_uri : $gs104d_prefix . '/index.php';
$_SERVER['SCRIPT_NAME']     = $_SERVER['PHP_SELF'];
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gend-society-104-admin-dump';

// Pre-initialised hook: resolve the current user from the login, lazily.
$GLOBALS['wp_filter'] = isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ? $GLOBALS['wp_filter'] : array();
$GLOBALS['wp_filter']['determine_current_user'][99][] = array(
	'function'      => function ( $user_id ) use ( $gs104d_login ) {
		static $resolved = null;
		if ( null === $resolved ) {
			$u        = function_exists( 'get_user_by' ) ? get_user_by( 'login', $gs104d_login ) : false;
			$resolved = $u ? (int) $u->ID : 0;
		}
		return $resolved ? $resolved : $user_id;
	},
	'accepted_args' => 1,
);

if ( 'admin' === $gs104d_mode || 'network' === $gs104d_mode ) {
	define( 'WP_ADMIN', true );
	if ( 'network' === $gs104d_mode ) {
		define( 'WP_NETWORK_ADMIN', true );
	}
}

// Must run at file scope: wp-load.php and menu.php set globals.
require $gs104d_path . '/wp-load.php';

$gs104d_label = function ( $html ) {
	$html = (string) $html;
	// Drop counter bubbles (<span class="update-plugins ...">3</span>, awaiting-mod, ...).
	$html = preg_replace( '#<span\b[^>]*class="[^"]*\b(update-plugins|awaiting-mod|count-\d+|plugin-count|pending-count|menu-counter)\b[^"]*"[^>]*>.*?</span>(\s*</span>)?#is', '', $html );
	$html = preg_replace( '#<[^>]+>#', '', $html );
	$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $html ) );
};

$gs104d_out = array(
	'mode'         => $gs104d_mode,
	'host'         => $gs104d_host,
	'user'         => $gs104d_login,
	'user_id'      => get_current_user_id(),
	'runtime_mode' => function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : null,
	'gs_version'   => defined( 'GS_VERSION' ) ? GS_VERSION : null,
);

if ( 0 === $gs104d_out['user_id'] ) {
	fwrite( STDERR, "user '{$gs104d_login}' not found\n" );
	exit( 3 );
}

if ( 'admin' === $gs104d_mode || 'network' === $gs104d_mode ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	// menu.php builds $menu/$submenu and fires (network_)admin_menu; it does
	// not fire admin_init and renders nothing.
	if ( 'network' === $gs104d_mode ) {
		require ABSPATH . 'wp-admin/network/menu.php';
	} else {
		require ABSPATH . 'wp-admin/menu.php';
	}
	global $menu, $submenu;
	$gs104d_menu = array();
	foreach ( (array) $menu as $pos => $item ) {
		if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
			continue;
		}
		if ( isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' ) ) {
			$gs104d_menu[] = array( 'pos' => (string) $pos, 'slug' => '(separator)', 'title' => '' );
			continue;
		}
		$gs104d_menu[] = array(
			'pos'   => (string) $pos,
			'slug'  => (string) $item[2],
			'title' => $gs104d_label( isset( $item[0] ) ? $item[0] : '' ),
			'cap'   => isset( $item[1] ) ? (string) $item[1] : '',
		);
	}
	$gs104d_sub = array();
	foreach ( (array) $submenu as $parent => $items ) {
		$rows = array();
		foreach ( (array) $items as $pos => $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}
			$rows[] = array(
				'pos'   => (string) $pos,
				'slug'  => (string) $item[2],
				'title' => $gs104d_label( isset( $item[0] ) ? $item[0] : '' ),
				'cap'   => isset( $item[1] ) ? (string) $item[1] : '',
			);
		}
		$gs104d_sub[ (string) $parent ] = $rows;
	}
	ksort( $gs104d_sub );
	$gs104d_out['menu']    = $gs104d_menu;
	$gs104d_out['submenu'] = $gs104d_sub;
} else {
	if ( ! function_exists( 'buddypress' ) ) {
		fwrite( STDERR, "BuddyPress is not active on this site\n" );
		exit( 4 );
	}
	// Parse the request so BuddyPress resolves the displayed member / current group.
	if ( function_exists( 'wp' ) && ! did_action( 'wp' ) ) {
		wp();
	}
	if ( ! did_action( 'bp_setup_nav' ) ) {
		do_action( 'bp_setup_nav' );
	}

	$gs104d_item = function ( $nav_item ) use ( $gs104d_label ) {
		$a = (array) $nav_item;
		$row = array(
			'slug'     => isset( $a['slug'] ) ? (string) $a['slug'] : '',
			'name'     => $gs104d_label( isset( $a['name'] ) ? $a['name'] : '' ),
			'position' => isset( $a['position'] ) ? (int) $a['position'] : null,
		);
		if ( array_key_exists( 'user_has_access', $a ) ) {
			$row['user_has_access'] = (bool) $a['user_has_access'];
		}
		if ( array_key_exists( 'show_for_displayed_user', $a ) ) {
			$row['show_for_displayed_user'] = (bool) $a['show_for_displayed_user'];
		}
		return $row;
	};
	$gs104d_sort = function ( &$rows ) {
		usort( $rows, function ( $x, $y ) {
			$c = (int) $x['position'] <=> (int) $y['position'];
			return 0 !== $c ? $c : strcmp( $x['slug'], $y['slug'] );
		} );
	};

	$bp = buddypress();
	if ( 'member' === $gs104d_mode ) {
		$gs104d_out['displayed_user_id'] = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : 0;
		$nav     = isset( $bp->members->nav ) ? $bp->members->nav : null;
		$primary = array();
		$second  = array();
		if ( $nav && method_exists( $nav, 'get_primary' ) ) {
			foreach ( (array) $nav->get_primary( array(), false ) as $p ) {
				$row       = $gs104d_item( $p );
				$primary[] = $row;
				$subs      = array();
				foreach ( (array) $nav->get_secondary( array( 'parent_slug' => $row['slug'] ), false ) as $s ) {
					$subs[] = $gs104d_item( $s );
				}
				$gs104d_sort( $subs );
				$second[ $row['slug'] ] = $subs;
			}
		}
		$gs104d_sort( $primary );
		ksort( $second );
		$gs104d_out['primary']   = $primary;
		$gs104d_out['secondary'] = $second;
	} else {
		$group = function_exists( 'groups_get_current_group' ) ? groups_get_current_group() : null;
		$gs104d_out['group_id'] = $group ? (int) $group->id : 0;
		$parent = $group ? (string) $group->slug : $gs104d_gslug;
		$nav    = isset( $bp->groups->nav ) ? $bp->groups->nav : null;
		$items  = array();
		if ( $nav && method_exists( $nav, 'get_secondary' ) ) {
			foreach ( (array) $nav->get_secondary( array( 'parent_slug' => $parent ), false ) as $s ) {
				$items[] = $gs104d_item( $s );
			}
		}
		$gs104d_sort( $items );
		$gs104d_out['group_nav'] = $items;
	}
}

echo json_encode( $gs104d_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
