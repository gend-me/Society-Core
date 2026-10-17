<?php
/**
 * Phase 110-01 battery: gend-society on a SINGLE-SITE container (every migrated container is single-site).
 *
 * Run with `wp eval-file single-site.php <section>` (phase110 tools/run-bed.sh / run-hub.sh pass the section).
 * Sections:
 *   embed        single-site bed: gend_society_group_embed_menu_items() with gdc_bp_group_id 0 and 5
 *   admin-pages  single-site bed: HTTP GET of every gend-society wp-admin page (incl. gs-group-embed tabs) as admin;
 *                200, no "critical error" body, 0 new "PHP Fatal" lines in debug.log
 *   ms-md5       multisite hub clone: md5(serialize()) of the menu items for blog 1 and the first group-linked
 *                sub-site, printed as "MD5 blog=<id> <md5>" lines (the runner compares before/after the fix)
 * Last line: "ALL PASS (n)" or "FAIL (k of n)". Test-only file: never loaded by the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gs110_section = isset( $args[0] ) ? (string) $args[0] : (string) getenv( 'GS110_SECTION' );
$gs110_n       = 0;
$gs110_bad     = 0;

$gs110_check = function ( $ok, $label, $detail = '' ) use ( &$gs110_n, &$gs110_bad ) {
	$gs110_n++;
	if ( $ok ) {
		echo 'PASS: ' . $label . "\n";
		return;
	}
	$gs110_bad++;
	echo 'FAIL: ' . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
};

$gs110_fatals = function () {
	$f = WP_CONTENT_DIR . '/debug.log';
	if ( ! is_readable( $f ) ) {
		return 0;
	}
	return substr_count( (string) file_get_contents( $f ), 'PHP Fatal' );
};

$gs110_admin_id = function () {
	$u = get_user_by( 'login', 'admin' );
	if ( ! $u ) {
		$ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
		return $ids ? (int) $ids[0] : 0;
	}
	return (int) $u->ID;
};

/** Calls the menu builder, converting any Throwable into a FAIL detail. Returns [ok, value|message]. */
$gs110_call_menu = function () {
	try {
		$v = gend_society_group_embed_menu_items();
		return array( true, $v );
	} catch ( \Throwable $e ) {
		return array( false, get_class( $e ) . ': ' . $e->getMessage() );
	}
};

echo '== 110-01 single-site battery section=' . $gs110_section . ' multisite=' . ( is_multisite() ? 'yes' : 'no' )
	. ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?' )
	. ' gend-society=' . ( defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '?' ) . "\n";

switch ( $gs110_section ) {

	case 'embed':
		$gs110_check( ! is_multisite(), 'bed is single-site' );
		$gs110_check( function_exists( 'gend_society_runtime_mode' ) && 'container' === gend_society_runtime_mode(), 'runtime mode is container' );
		$gs110_check( function_exists( 'gend_society_group_embed_menu_items' ), 'group-embed.php loaded (container tier)' );
		if ( ! function_exists( 'gend_society_group_embed_menu_items' ) ) {
			break;
		}
		wp_set_current_user( $gs110_admin_id() );
		$had  = get_option( 'gdc_bp_group_id', null );
		$f0   = $gs110_fatals();

		// Case 1: no linked group.
		update_option( 'gdc_bp_group_id', 0 );
		list( $ok, $v ) = $gs110_call_menu();
		$gs110_check( $ok, 'gdc_bp_group_id=0: menu builder runs without a fatal', $ok ? '' : $v );
		$gs110_check( $ok && is_array( $v ), 'gdc_bp_group_id=0: returns an array', $ok ? gettype( $v ) : '' );

		// Case 2: group 5 linked; the bp_groups table does not exist on this bed.
		global $wpdb;
		update_option( 'gdc_bp_group_id', 5 );
		$seen = array();
		$cap  = function ( $q ) use ( &$seen ) {
			if ( false !== stripos( $q, 'bp_groups' ) ) {
				$seen[] = $q;
			}
			return $q;
		};
		add_filter( 'query', $cap );
		$sup = $wpdb->suppress_errors( true );
		list( $ok, $v ) = $gs110_call_menu();
		$wpdb->suppress_errors( $sup );
		remove_filter( 'query', $cap );
		$gs110_check( $ok, 'gdc_bp_group_id=5: menu builder runs without a fatal (bp_groups absent)', $ok ? '' : $v );
		$gs110_check( $ok && is_array( $v ), 'gdc_bp_group_id=5: returns the no-group payload (array)', $ok ? gettype( $v ) : '' );
		$want = $wpdb->base_prefix . 'bp_groups';
		$hit  = false;
		foreach ( $seen as $q ) {
			if ( false !== strpos( $q, $want ) && preg_match( '/id\s*=\s*5\b/i', $q ) ) {
				$hit = true;
			}
		}
		$gs110_check( $hit, 'gdc_bp_group_id=5 read from get_option() and queried against ' . $want, $seen ? implode( ' | ', $seen ) : 'no bp_groups query seen' );

		if ( null === $had ) {
			delete_option( 'gdc_bp_group_id' );
		} else {
			update_option( 'gdc_bp_group_id', $had );
		}
		$nf = $gs110_fatals() - $f0;
		$gs110_check( 0 === $nf, 'no new PHP Fatal lines in debug.log (embed)', $nf . ' new' );
		break;

	case 'admin-pages':
		$gs110_check( ! is_multisite(), 'bed is single-site' );
		$uid = $gs110_admin_id();
		$gs110_check( $uid > 0, 'admin user found' );
		$exp = time() + 600;
		// Bed only (never gend.me): admin auth cookies for loopback page loads.
		$cookies = array(
			AUTH_COOKIE      => wp_generate_auth_cookie( $uid, $exp, 'auth' ),
			LOGGED_IN_COOKIE => wp_generate_auth_cookie( $uid, $exp, 'logged_in' ),
		);
		$get = function ( $path ) use ( $cookies ) {
			return wp_remote_get(
				admin_url( $path ),
				array( 'cookies' => $cookies, 'timeout' => 60, 'redirection' => 0, 'sslverify' => false )
			);
		};
		$f0 = $gs110_fatals();

		// 1. The dashboard, then every gend-society menu link it renders.
		$r    = $get( 'index.php' );
		$code = is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r );
		$body = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
		$gs110_check( 200 === $code && false === stripos( $body, 'critical error' ), 'GET wp-admin/index.php -> 200 (no critical error)', (string) $code );

		$slugs = array();
		if ( preg_match_all( '#admin\.php\?page=([a-z0-9_\-]+)#i', $body, $m ) ) {
			foreach ( $m[1] as $s ) {
				if ( preg_match( '/^(gs[-_]|gend[-_]society|gend-)/i', $s ) ) {
					$slugs[ $s ] = true;
				}
			}
		}
		// Hidden pages (no menu item) that admin-style.php's header still renders on.
		foreach ( array( 'gs-group-embed' ) as $s ) {
			$slugs[ $s ] = true;
		}
		$paths = array();
		foreach ( array_keys( $slugs ) as $s ) {
			$paths[] = 'admin.php?page=' . $s;
		}
		foreach ( array( 'hosting', 'compute-gas', 'organization', 'feature-suite' ) as $t ) {
			$paths[] = 'admin.php?page=gs-group-embed&tab=' . $t;
		}
		echo 'INFO: ' . count( $paths ) . ' gend-society admin URL(s): ' . implode( ', ', $paths ) . "\n";
		$gs110_check( count( $slugs ) >= 2, 'found gend-society admin pages in the menu', count( $slugs ) . ' slug(s)' );

		foreach ( $paths as $p ) {
			$r    = $get( $p );
			$code = is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r );
			$body = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
			// A redirect (e.g. a first-run page sending the admin elsewhere) is not a fatal; 5xx or the WP fatal page is.
			$ok = in_array( $code, array( 200, 302 ), true ) && false === stripos( $body, 'critical error' );
			$gs110_check( $ok, 'GET ' . $p . ' -> ' . $code, (string) $code );
		}
		$nf = $gs110_fatals() - $f0;
		$gs110_check( 0 === $nf, 'no new PHP Fatal lines in debug.log (admin pages)', $nf . ' new' );
		break;

	case 'ms-md5':
		$gs110_check( is_multisite(), 'target is multisite (hub clone)' );
		if ( ! is_multisite() || ! function_exists( 'gend_society_group_embed_menu_items' ) ) {
			$gs110_check( false, 'group-embed.php loaded' );
			break;
		}
		$super = 0;
		foreach ( get_super_admins() as $login ) {
			$u = get_user_by( 'login', $login );
			if ( $u ) {
				$super = (int) $u->ID;
				break;
			}
		}
		$gs110_check( $super > 0, 'super admin found' );
		wp_set_current_user( $super );
		// Targets: the main site, plus a group-linked sub-site. When the clone has no group-linked sub-site, the first
		// sub-site is linked to the main site's group for the duration of this section only (restored below), so
		// the group branch also runs with get_current_blog_id() != main.
		$main    = (int) get_main_site_id();
		$targets = array( $main );
		$temp    = 0;
		$linked  = 0;
		$first   = 0;
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500, 'orderby' => 'id' ) ) as $bid ) {
			$bid = (int) $bid;
			if ( $bid === $main ) {
				continue;
			}
			$first = $first ? $first : $bid;
			if ( (int) get_blog_option( $bid, 'gdc_bp_group_id', 0 ) > 0 ) {
				$linked = $bid;
				break;
			}
		}
		$main_gid = (int) get_blog_option( $main, 'gdc_bp_group_id', 0 );
		if ( ! $linked && $first && $main_gid > 0 ) {
			$temp_had = get_blog_option( $first, 'gdc_bp_group_id', null );
			update_blog_option( $first, 'gdc_bp_group_id', $main_gid );
			$linked = $first;
			$temp   = $first;
			echo 'INFO: no group-linked sub-site on this clone; blog ' . $first . ' linked to group ' . $main_gid . " for this section only\n";
		}
		if ( $linked ) {
			$targets[] = $linked;
		}
		$gs110_check( count( $targets ) === 2, 'main site + a group-linked sub-site', 'targets=' . implode( ',', $targets ) );
		foreach ( $targets as $bid ) {
			switch_to_blog( $bid );
			list( $ok, $v ) = $gs110_call_menu();
			restore_current_blog();
			$gs110_check( $ok, 'blog ' . $bid . ': menu builder runs', $ok ? '' : $v );
			if ( $ok ) {
				echo 'MD5 blog=' . $bid . ( $bid === $temp ? '(temp-linked)' : '' ) . ' ' . md5( serialize( $v ) ) . ' items=' . count( (array) $v ) . "\n";
			}
		}
		if ( $temp ) {
			if ( null === $temp_had ) {
				delete_blog_option( $temp, 'gdc_bp_group_id' );
			} else {
				update_blog_option( $temp, 'gdc_bp_group_id', $temp_had );
			}
			$gs110_check( get_blog_option( $temp, 'gdc_bp_group_id', null ) === $temp_had, 'blog ' . $temp . ' gdc_bp_group_id restored' );
		}
		break;

	default:
		$gs110_check( false, 'known section', 'unknown section [' . $gs110_section . ']' );
}

echo ( 0 === $gs110_bad ? 'ALL PASS (' . $gs110_n . ')' : 'FAIL (' . $gs110_bad . ' of ' . $gs110_n . ')' ) . "\n";
