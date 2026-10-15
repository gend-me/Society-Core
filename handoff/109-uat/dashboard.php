<?php
/**
 * Section dashboard (plan 109-29, D28 / D29 panel half / D32 reuse-only / D33 / D34; PLAN-01/02/03/05/07).
 *
 * Standalone gets the SAME GenD Society wp-admin dashboard as gend.me, on by default, with a visible
 * "Switch back to standard WordPress"; hub and container output stays byte-identical.
 *
 *   standalone   part "skin"                option unset -> 'gend'; stored 'native' wins; Switch back link in the admin
 *                                           bar + skin header (valid, not double-escaped nonce href); a real web
 *                                           switch to native and back; the real-click browser result
 *                                           (gap2/plugin/tools/dash.js, copied in as wp-content/gs109-dash-browser.json);
 *                                           first-run notice naming the switch; core menus (Plugins / Settings /
 *                                           Users / Appearance) still there
 *                part "standalone-unpaired" 0 requests to the hub / gend.me (loopback page + in-process render)
 *   hub | container                         experience 'gend'; INFO "dashboard render md5" of the custom dashboard
 *                                           (tools/109-29-regress.sh compares it with the fork-point tree)
 *
 * Every part prints "dashboard <part>: ALL PASS (n)" or "dashboard <part>: FAILURES n"; the section ends with
 * "dashboard: ALL PASS (n)" when nothing failed.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_planaccess.php';

if ( ! function_exists( 'gs109d_part' ) ) {
	/** Start (no $end) or close a part; closing prints its verdict line. */
	function gs109d_part( $name, $end = false ) {
		static $at = array();
		if ( ! $end ) {
			$at[ $name ] = array( (int) $GLOBALS['gs109_uat']['pass'], (int) $GLOBALS['gs109_uat']['fail'] );
			echo "-- part {$name}\n";
			return;
		}
		$p = (int) $GLOBALS['gs109_uat']['pass'] - $at[ $name ][0];
		$f = (int) $GLOBALS['gs109_uat']['fail'] - $at[ $name ][1];
		echo 'dashboard ' . $name . ': ' . ( 0 === $f && $p > 0 ? 'ALL PASS (' . $p . ')' : 'FAILURES ' . max( 1, $f ) ) . "\n";
	}

	/** DOMXPath over a full page. */
	function gs109d_xp( $html ) {
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		return new DOMXPath( $doc );
	}

	/** DOMXPath over a fragment. */
	function gs109d_xpf( $html ) {
		return gs109d_xp( '<html><body>' . $html . '</body></html>' );
	}

	function gs109d_count( DOMXPath $xp, $q ) {
		$n = $xp->query( $q );
		return $n ? $n->length : 0;
	}

	function gs109d_text( DOMXPath $xp, $q ) {
		$n = $xp->query( $q );
		if ( ! $n || ! $n->length ) {
			return null;
		}
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $n->item( 0 )->textContent, ENT_QUOTES, 'UTF-8' ) ) );
	}

	function gs109d_attrs( DOMXPath $xp, $q, $attr ) {
		$out = array();
		$n   = $xp->query( $q );
		foreach ( $n ? $n : array() as $el ) {
			$out[] = (string) $el->getAttribute( $attr );
		}
		return $out;
	}

	/** Loopback GET of a wp-admin URL as $session; ['status','location','body']. */
	function gs109d_get( array $session, $url ) {
		return gs109p_web( 'GET', $url, $session );
	}

	/**
	 * Hosts (other than this bed) the bed's logger saw since $mark. The battery's own loopback calls to the
	 * bed are left out; the hub fixture host (localhost:8919) and anything on gend.me count.
	 */
	function gs109d_remote_hosts_since( $mark ) {
		$bed   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . ':' . (int) wp_parse_url( home_url(), PHP_URL_PORT );
		$hosts = array();
		foreach ( array_filter( explode( "\n", gs109p_httplog_since( $mark ) ) ) as $l ) {
			$j = json_decode( $l, true );
			$u = is_array( $j ) && isset( $j['url'] ) ? (string) $j['url'] : '';
			$h = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
			$p = wp_parse_url( $u, PHP_URL_PORT );
			$hp = $h . ( $p ? ':' . (int) $p : '' );
			if ( '' === $h || $hp === $bed || ( 'localhost' === $h && 8909 === (int) $p ) ) {
				continue;
			}
			$hosts[] = $hp . (string) wp_parse_url( $u, PHP_URL_PATH );
		}
		return $hosts;
	}

	/** The entries of $hosts that are gend.me or the paired / fixture hub (localhost:8919). */
	function gs109d_gend_hosts( array $hosts ) {
		return array_values(
			array_filter(
				$hosts,
				function ( $h ) {
					return 0 === strpos( $h, 'localhost:8919' ) || false !== strpos( $h, 'gend.me' );
				}
			)
		);
	}

	/** The custom dashboard rendered in this process as $uid (screen 'dashboard'). '' when the renderer is absent. */
	function gs109d_render( $uid ) {
		if ( ! function_exists( 'gend_society_render_custom_dashboard_screen' ) ) {
			return '';
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		wp_set_current_user( $uid );
		set_current_screen( 'dashboard' );
		ob_start();
		gend_society_render_custom_dashboard_screen();
		return (string) ob_get_clean();
	}

	/** Render bytes with per-run noise removed (nonces, "N seconds ago"), for the md5 comparison. */
	function gs109d_normalize( $html ) {
		$html = preg_replace( '/\b\d+ (?:second|sec|min|minute|hour|day|week|month|year)s? ago\b/', 'N ago', (string) $html );
		$html = preg_replace( '/(["\'=:])[0-9a-f]{10}(["\'&])/', '$1NONCE$2', $html );
		return $html;
	}
}

$gs109d_mode = gs109_mode();

// ======================================================================================== hub | container
if ( 'standalone' !== $gs109d_mode ) {
	gs109d_part( $gs109d_mode );
	gs109_assert_eq( 'gend', gend_society_admin_experience(), 'dash-' . $gs109d_mode . '-experience', $gs109d_mode . ': admin experience is gend' );
	$uid = gs109p_admin_id();
	if ( 'hub' === $gs109d_mode ) {
		$uid = 1;
	}
	gs109p_http_capture();
	$r1 = gs109d_render( $uid );
	$r2 = gs109d_render( $uid );
	gs109p_http( true );
	$n1 = gs109d_normalize( $r1 );
	$n2 = gs109d_normalize( $r2 );
	gs109_assert( '' !== $r1 && $n1 === $n2, 'dash-' . $gs109d_mode . '-render-stable', $gs109d_mode . ': custom dashboard renders and two renders normalise to the same bytes (' . strlen( $r1 ) . ' bytes)' );
	gs109_info( 'dashboard render md5: ' . $gs109d_mode . ' bytes ' . strlen( $n1 ) . ' combined ' . md5( $n1 ) );
	gs109d_part( $gs109d_mode, true );
	return;
}

// ======================================================================================== standalone
gs109p_setup();
gs109_snapshot_options( array( 'gend_society_admin_experience', 'gs109_fixture_scenario', 'gs109_fixture_dir', 'gs109_fixture_hub' ) );
$admin = gs109p_admin_id();
$sess  = gs109p_session( $admin );
delete_option( 'gend_society_admin_experience' );
wp_cache_delete( 'alloptions', 'options' );

// ---------------------------------------------------------------------------------------- part skin
gs109d_part( 'skin' );
gs109_assert_eq( 'gend', gend_society_admin_experience(), 'dash-default-gend', 'fresh standalone (option unset): gend_society_admin_experience() is gend (D28)' );
update_option( 'gend_society_admin_experience', 'native', true );
gs109_assert_eq( 'native', gend_society_admin_experience(), 'dash-explicit-native', 'a stored native choice is respected' );
update_option( 'gend_society_admin_experience', 'gend', true );
gs109_assert_eq( 'gend', gend_society_admin_experience(), 'dash-explicit-gend', 'a stored gend choice is respected' );
delete_option( 'gend_society_admin_experience' );

$label = 'Switch back to standard WordPress';
$page  = gs109d_get( $sess, admin_url( 'index.php' ) );
$xp    = gs109d_xp( $page['body'] );
gs109_assert( 200 === $page['status'] && false !== strpos( $page['body'], 'gs-admin-style' ), 'dash-skin-on-web', 'option unset: wp-admin/index.php answers 200 with the GenD skin assets (HTTP ' . $page['status'] . ')' );

$ab_text = gs109d_text( $xp, '//li[@id="wp-admin-bar-gend-society-switch-back"]//a' );
$ab_href = gs109d_attrs( $xp, '//li[@id="wp-admin-bar-gend-society-switch-back"]//a', 'href' );
$ab_href = $ab_href ? $ab_href[0] : '';
gs109_assert_eq( $label, (string) $ab_text, 'dash-switch-adminbar-label', 'admin bar link reads "' . $label . '"' );
parse_str( (string) wp_parse_url( $ab_href, PHP_URL_QUERY ), $abq );
gs109_assert(
	'' !== $ab_href && false === strpos( $ab_href, '&amp;' ) && 'gend_society_set_admin_experience' === ( $abq['action'] ?? '' ) && 'native' === ( $abq['experience'] ?? '' ) && preg_match( '/^[0-9a-f]{10}$/', (string) ( $abq['_wpnonce'] ?? '' ) ),
	'dash-switch-adminbar-href',
	'admin bar href: admin-post action + experience=native + a nonce, not HTML-double-escaped',
	$ab_href
);

$cfg = null;
foreach ( $xp->query( '//script' ) as $s ) {
	$t = (string) $s->textContent;
	if ( false !== strpos( $t, 'gend-society-switch-back-header' ) && preg_match( '/\}\)\((\{"href".*?\})\);/s', $t, $m ) ) {
		$cfg = json_decode( $m[1], true );
	}
}
gs109_assert( is_array( $cfg ) && $label === ( $cfg['label'] ?? '' ) && false === strpos( (string) ( $cfg['href'] ?? '' ), '&amp;' ) && false !== strpos( (string) ( $cfg['href'] ?? '' ), 'experience=native' ), 'dash-switch-header', 'skin header link: label "' . $label . '", decoded href with experience=native', wp_json_encode( $cfg ) );

// First-run notice names the switch while the default (not an explicit choice) is in force.
gs109_assert( false !== strpos( wp_strip_all_tags( $page['body'] ), 'Switch back to standard WordPress' ) && gs109d_count( $xp, '//div[contains(@class,"gend-society-skin-default-notice")]' ) === 1, 'dash-firstrun-notice', 'option unset: one first-run notice naming "Switch back to standard WordPress"' );

// Core menus stay (106 rule).
$menu_hrefs = gs109d_attrs( $xp, '//ul[@id="adminmenu"]/li/a', 'href' );
$need       = array( 'plugins.php', 'options-general.php', 'users.php', 'themes.php' );
$missing    = array();
foreach ( $need as $n ) {
	$hit = false;
	foreach ( $menu_hrefs as $h ) {
		if ( $n === basename( (string) wp_parse_url( $h, PHP_URL_PATH ) ) ) {
			$hit = true;
		}
	}
	if ( ! $hit ) {
		$missing[] = $n;
	}
}
gs109_assert( ! $missing, 'dash-core-menus', 'skin on: Plugins / Settings / Users / Appearance still in the admin menu', wp_json_encode( $missing ) );
// Every wp-admin link in the skinned menu opens a working screen (no 403 / 404 / 500; PLAN-05: gated areas go to
// the upgrade page, never to a broken one).
$broken = array();
$seen   = array();
foreach ( gs109d_attrs( $xp, '//ul[@id="adminmenu"]//a', 'href' ) as $h ) {
	$h = html_entity_decode( $h );
	if ( '' === $h || isset( $seen[ $h ] ) || 0 === strpos( $h, '#' ) ) {
		continue;
	}
	$abs = 0 === strpos( $h, 'http' ) ? $h : admin_url( $h );
	if ( 0 !== strpos( $abs, admin_url() ) || false !== strpos( $abs, 'admin-post.php' ) || false !== strpos( $abs, 'action=' ) ) {
		continue;
	}
	$seen[ $h ] = true;
	$r          = gs109d_get( $sess, $abs );
	if ( ! in_array( $r['status'], array( 200, 302 ), true ) ) {
		$broken[] = $h . ' -> ' . $r['status'];
	}
}
gs109_assert( count( $seen ) > 10 && ! $broken, 'dash-menu-no-broken', 'skin on, unpaired: all ' . count( $seen ) . ' wp-admin menu links answer 200/302', wp_json_encode( $broken ) );

// A real web request on the Switch back href (the server side of the click), then back on.
$w = gs109p_web( 'GET', html_entity_decode( $ab_href ), $sess );
wp_cache_delete( 'alloptions', 'options' );
gs109_assert( in_array( $w['status'], array( 302, 303 ), true ) && 0 === strpos( $w['location'], admin_url() ) && 'native' === gs109p_raw( 'gend_society_admin_experience' ), 'dash-switch-web-native', 'GET on the Switch back href: redirect into wp-admin, option native (HTTP ' . $w['status'] . ')', $w['location'] );
$nat = gs109d_get( $sess, admin_url( 'index.php' ) );
gs109_assert( 200 === $nat['status'] && false === strpos( $nat['body'], 'gs-admin-style' ) && false === strpos( $nat['body'], 'gend-society-switch-back' ), 'dash-native-web', 'native: index.php has no GenD skin and no Switch back link' );
$on = gs109p_web( 'GET', add_query_arg( array( 'action' => 'gend_society_set_admin_experience', 'experience' => 'gend', 'redirect_to' => rawurlencode( admin_url( 'index.php' ) ), '_wpnonce' => gs109p_nonce( $sess, 'gend_society_admin_experience' ) ), admin_url( 'admin-post.php' ) ), $sess );
wp_cache_delete( 'alloptions', 'options' );
$back = gs109d_get( $sess, admin_url( 'index.php' ) );
gs109_assert( 'gend' === gs109p_raw( 'gend_society_admin_experience' ) && false !== strpos( $back['body'], 'gs-admin-style' ), 'dash-switch-web-on-again', 'switching on again restores the skin' );
delete_option( 'gend_society_admin_experience' );
wp_cache_delete( 'alloptions', 'options' );

// Real clicks in a browser (gap2/plugin/tools/dash.js; the runner copies its result in before this section).
$bf = WP_CONTENT_DIR . '/gs109-dash-browser.json';
$bj = is_readable( $bf ) ? json_decode( (string) file_get_contents( $bf ), true ) : null;
$ax = GEND_SOCIETY_DIR . 'inc/admin-experience.php';
gs109_assert(
	is_array( $bj ) && ( time() - (int) filemtime( $bf ) ) < 3600 && md5_file( $ax ) === ( $bj['plugin_md5'] ?? '' )
		&& ! empty( $bj['header_link'] ) && ! empty( $bj['adminbar_link'] ) && ! empty( $bj['click_header_native'] ) && ! empty( $bj['on_again'] ) && ! empty( $bj['click_adminbar_native'] ),
	'dash-real-click',
	'browser (this plugin build, < 1 h old): both links visible, a real click on the header link lands on native wp-admin, Turn on restores the skin, a real click on the admin bar link lands on native again',
	is_array( $bj ) ? wp_json_encode( $bj ) : 'no ' . basename( $bf )
);
gs109d_part( 'skin', true );

// ---------------------------------------------------------------------------------------- part standalone-unpaired
gs109d_part( 'standalone-unpaired' );
gs109_assert( '' === (string) get_option( 'gend_society_install_token', '' ) && ( ! function_exists( 'gend_society_consent_given' ) || ! gend_society_consent_given() ), 'dash-unpaired-state', 'bed is unpaired and has no consent' );
$mark = gs109p_httplog_mark();
$up   = gs109d_get( $sess, admin_url( 'index.php' ) );
$rh   = gs109d_remote_hosts_since( $mark );
$gm   = gs109d_gend_hosts( $rh );
gs109_info( 'unpaired page: other outbound attempts (core, blocked by WP_HTTP_BLOCK_EXTERNAL): ' . wp_json_encode( array_values( array_diff( $rh, $gm ) ) ) );
gs109_assert( 200 === $up['status'] && ! $gm, 'dash-unpaired-0-requests', 'unpaired dashboard page: 0 requests to gend.me / the hub (http logger)', wp_json_encode( $gm ) );
gs109p_http_capture();
gs109p_http( true );
$html = gs109d_render( $admin );
$req  = gs109p_http( true );
gs109_assert( '' !== $html && ! $req, 'dash-unpaired-render-0-requests', 'in-process render of the custom dashboard: 0 requests (' . strlen( $html ) . ' bytes)', wp_json_encode( array_map( function ( $r ) { return $r['method'] . ' ' . $r['url']; }, $req ) ) );
gs109d_part( 'standalone-unpaired', true );

if ( 0 === (int) $GLOBALS['gs109_uat']['fail'] ) {
	echo 'dashboard: ALL PASS (' . (int) $GLOBALS['gs109_uat']['pass'] . ")\n";
}
