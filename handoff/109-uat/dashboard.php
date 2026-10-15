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
 *                part "standalone-unpaired" 0 requests to the hub / gend.me (loopback page + in-process render); the
 *                                           same panel renders (no Site Administrators fallback): Integration Hub card
 *                                           with button[data-gs-connect-open] "Connect to gend.me" + the 109-30 slot
 *                                           fired once, "Connect to gend.me" states in the plan / gas / backups panels,
 *                                           no iframe, no remote script/style/image, no third-party QR
 *                part "standalone-paired"   fixture hub (109-fixtures, generated with the 109-28 contract incl. /gas and
 *                                           /backups-status): connected, store-owner, inherited, revoked (401), hub
 *                                           down, hosted backups; UPGRADE / Add Server / Pay / Choose a plan are
 *                                           [data-gs-popup] hub links, 0 iframes, no token in the page, no hosting/*
 *                                           route, no gdc_gas_ledger query, one GET per route then 0 within the cache;
 *                                           devices + compute-gas AJAX from /gas; the browser popup result
 *                                           (dash.js popup -> wp-content/gs109-dash-popup.json)
 *   hub | container                         experience 'gend'; no Connect button, no 109-30 slot, no standalone assets;
 *                                           INFO "dashboard render md5" of the custom dashboard (tools/109-29-regress.sh
 *                                           compares it with the fork-point tree)
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

	/**
	 * Render bytes with per-run noise removed, for the md5 comparison: nonces, "N seconds ago", random element ids
	 * (invite editor UUIDs, psoo-ppb-<n>) and live sizes (data-used bytes, "12.3 MB" labels; the clone's tables
	 * and logs grow between runs). Seen on the hub clone in 109-29 (tools/norm-check.py on dumped renders).
	 */
	function gs109d_normalize( $html ) {
		$html = preg_replace( '/\b\d+ (?:second|sec|min|minute|hour|day|week|month|year)s? ago\b/', 'N ago', (string) $html );
		$html = preg_replace( '/(["\'=:])[0-9a-f]{10}(["\'&])/', '$1NONCE$2', $html );
		$html = preg_replace( '/(?<![0-9a-f])[0-9a-f]{8}[-_][0-9a-f]{4}[-_][0-9a-f]{4}[-_][0-9a-f]{4}[-_][0-9a-f]{12}(?![0-9a-f])/', 'UUID', $html );
		$html = preg_replace( '/\bpsoo-ppb-\d+/', 'psoo-ppb-N', $html );
		$html = preg_replace( '/data-used="\d+"/', 'data-used="N"', $html );
		$html = preg_replace( '/\b\d+(?:\.\d+)? (?:B|KB|MB|GB|TB)\b/', 'N B', $html );
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
	// The FIRST render of the process is the one compared (it also prints the stylesheets the Media sub-tab
	// enqueues; later renders in the same process do not reprint them), so stability is checked on renders 2 + 3.
	$r1 = gs109d_render( $uid );
	$r2 = gs109d_render( $uid );
	$r3 = gs109d_render( $uid );
	gs109p_http( true );
	$n1 = gs109d_normalize( $r1 );
	gs109_assert( '' !== $r1 && gs109d_normalize( $r2 ) === gs109d_normalize( $r3 ), 'dash-' . $gs109d_mode . '-render-stable', $gs109d_mode . ': custom dashboard renders and repeated renders normalise to the same bytes (' . strlen( $r1 ) . ' bytes)' );
	gs109_info( 'dashboard render md5: ' . $gs109d_mode . ' bytes ' . strlen( $n1 ) . ' combined ' . md5( $n1 ) );
	@file_put_contents( WP_CONTENT_DIR . '/gs109-dash-render.html', $n1 ); // tools/109-29-regress.sh copies it out for a diff
	// The standalone-only pieces never appear here (absent functions count as absent: the fork-point tree has none).
	$slot = 0;
	$sf   = function () use ( &$slot ) {
		$slot++;
	};
	add_action( 'gend_society_integration_hub_card_slot', $sf );
	$r3 = gs109d_render( $uid );
	remove_action( 'gend_society_integration_hub_card_slot', $sf );
	gs109_assert( 0 === $slot && false === strpos( $r3, 'data-gs-connect-open' ) && false === strpos( $r3, 'data-gs-popup' ) && false === strpos( $r3, 'gs-dash-sa-' ), 'dash-' . $gs109d_mode . '-no-standalone-ui', $gs109d_mode . ': no Connect button, no 109-30 slot call, no popup links, no standalone classes' );
	if ( function_exists( 'gend_society_dashboard_enqueue_standalone' ) ) {
		gend_society_dashboard_enqueue_standalone();
	}
	gs109_assert( ! wp_script_is( 'gend-society-dashboard-standalone', 'enqueued' ) && ! wp_style_is( 'gend-society-dashboard-standalone', 'enqueued' ), 'dash-' . $gs109d_mode . '-no-standalone-assets', $gs109d_mode . ': gs-dashboard-standalone.js/.css not enqueued' );
	gs109_assert( ! function_exists( 'gend_society_dashboard_is_standalone' ) || ( ! gend_society_dashboard_is_standalone() && ! gend_society_dashboard_standalone_paired() ), 'dash-' . $gs109d_mode . '-not-standalone', $gs109d_mode . ': the standalone data helpers stay off' );
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

// The same panel, not the Site Administrators fallback (D28/D32).
$fx = gs109d_xpf( $html );
gs109_assert( 1 === gs109d_count( $fx, '//*[@id="gs-mship-root"]' ) && 0 === gs109d_count( $fx, '//*[contains(@class,"gs-admin-grid")]' ), 'dash-unpaired-panel', 'unpaired: the GenD membership panel renders (no Site Administrators fallback)' );
gs109_assert( 'Integration Hub' === gs109d_text( $fx, '//div[contains(@class,"gs-mship-cell--group")]//h3' ) && 'Not connected to gend.me yet.' === gs109d_text( $fx, '//div[contains(@class,"gs-mship-cell--group")]//div[contains(@class,"gs-mship-empty")]' ), 'dash-unpaired-hub-card', 'Integration Hub card (top-left) says "Not connected to gend.me yet."' );
$btn = $fx->query( '//div[contains(@class,"gs-mship-cell--group")]//button[@data-gs-connect-open]' );
gs109_assert( $btn && 1 === $btn->length && 'Connect to gend.me' === trim( $btn->item( 0 )->textContent ) && 'button' === $btn->item( 0 )->getAttribute( 'type' ) && '' === $btn->item( 0 )->getAttribute( 'style' ), 'dash-unpaired-connect-button', 'one button[data-gs-connect-open] type=button "Connect to gend.me" in the Integration Hub card (no inline style)' );
$slot = 0;
$sf   = function () use ( &$slot ) {
	$slot++;
	echo '<i data-gs109-slot></i>';
};
add_action( 'gend_society_integration_hub_card_slot', $sf );
$sh = gs109d_render( $admin );
remove_action( 'gend_society_integration_hub_card_slot', $sf );
$sx = gs109d_xpf( $sh );
gs109_assert( 1 === $slot && 1 === gs109d_count( $sx, '//div[contains(@class,"gs-mship-cell--group")]//i[@data-gs109-slot]' ) && 0 === (int) has_action( 'gend_society_integration_hub_card_slot' ), 'dash-unpaired-slot', 'do_action( gend_society_integration_hub_card_slot ) fires exactly once, inside the Integration Hub card; nothing is hooked to it in 109-29' );
gs109_assert( false !== strpos( (string) gs109d_text( $fx, '//div[contains(@class,"gs-mship-plan-col")]' ), 'Connect to gend.me to see your dashboard plan' ) && 0 === gs109d_count( $fx, '//a[@data-gs-popup]' ), 'dash-unpaired-plan', 'membership card: "Connect to gend.me to see your dashboard plan..." and no popup links' );
$gas_sub = (string) gs109d_text( $fx, '//div[contains(@class,"gs-bcg-hero")]//p[contains(@class,"gs-bcg-sub")]' );
gs109_assert( 0 === strpos( $gas_sub, 'Connect to gend.me' ) && 1 === gs109d_count( $fx, '//div[contains(@class,"gs-bcg-hero")]//div[contains(@class,"gs-bcg-actions") and @hidden]' ) && 2 === gs109d_count( $fx, '//div[contains(@class,"gs-bcg-hero")]//div[contains(@class,"gs-bcg-stats") and @hidden]' ), 'dash-unpaired-gas', 'Compute Gas panel: "' . $gas_sub . '", actions and stats hidden' );
gs109_assert( 'Not connected' === gs109d_text( $fx, '//div[contains(@class,"gs-bk-hero")]//span[contains(@class,"gs-bk-badge")]' ) && 0 === gs109d_count( $fx, '//div[contains(@class,"gs-bk-hero")]//*[@data-gs-mship="backup-now" or @data-gs-hosting-goto="backups" or @data-gs-upgrade-open]' ), 'dash-unpaired-backups', 'Backups panel: "Not connected", no Backup now / Manage Backups / plan buttons' );
gs109_assert( 0 === gs109d_count( $fx, '//iframe' ) && false === stripos( $html, '<iframe' ) && 0 === gs109d_count( $fx, '//*[contains(@src,"qrserver") or contains(@href,"qrserver") or contains(@data-src,"qrserver")]' ) && 1 === gs109d_count( $fx, '//div[@data-gs-qr]' ), 'dash-unpaired-no-frame-qr', 'no <iframe> and no third-party QR image (no src/href to api.qrserver.com; local QR box instead)' );
$caps = array();
foreach ( array( '20 GB', '5 GB', '2 GB', 'Included in your plan' ) as $c ) {
	if ( false !== strpos( (string) gs109d_text( $fx, '//div[contains(@class,"gs-hosting__resources-grid")]' ), $c ) ) {
		$caps[] = $c;
	}
}
gs109_assert( ! $caps && false !== strpos( (string) gs109d_text( $fx, '//div[contains(@class,"gs-hosting__resources-grid")]' ), 'Container: after migration' ), 'dash-unpaired-storage', 'storage cards: no container caps / "Included in your plan"; "Container: after migration"', wp_json_encode( $caps ) );
$pxp  = gs109d_xp( $up['body'] );
$bad  = array();
$bedh = 'localhost:8909';
foreach ( array( '//script[@src]' => 'src', '//link[@rel="stylesheet"]' => 'href', '//img[@src]' => 'src', '//iframe' => 'src' ) as $q => $attr ) {
	foreach ( gs109d_attrs( $pxp, $q, $attr ) as $u ) {
		$h = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
		$p = wp_parse_url( $u, PHP_URL_PORT );
		if ( '' === $h || ( 'localhost' === $h && 8909 === (int) $p ) || 'secure.gravatar.com' === $h ) {
			continue;
		}
		$bad[] = $q . ' ' . $u;
	}
}
gs109_assert( 0 === gs109d_count( $pxp, '//iframe' ) && ! $bad, 'dash-unpaired-no-remote-assets', 'whole page: no iframe, no remote script / stylesheet / image (core gravatar avatars excepted)', wp_json_encode( $bad ) );
$js = gs109d_attrs( $pxp, '//script[contains(@src,"gs-dashboard-standalone.js")]', 'src' );
$cs = gs109d_attrs( $pxp, '//link[contains(@href,"gs-dashboard-standalone.css")]', 'href' );
gs109_assert( 1 === count( $js ) && 1 === count( $cs ) && preg_match( '/ver=[0-9.]+\.\d{9,}/', $js[0] . ' ' . $cs[0] ), 'dash-unpaired-assets', 'local gs-dashboard-standalone.js + .css enqueued with filemtime versions', wp_json_encode( array( $js, $cs ) ) );
gs109d_part( 'standalone-unpaired', true );

// ---------------------------------------------------------------------------------------- part standalone-paired
gs109d_part( 'standalone-paired' );
if ( ! function_exists( 'gs109d_clear' ) ) {
	/** Every dashboard cache + the health status, so the next render fetches the current scenario. */
	function gs109d_clear() {
		gs109p_clear_caches();
		foreach ( array( 'gend_society_gas_cache', 'gend_society_backups_status_cache', 'gend_society_connection_health' ) as $o ) {
			delete_option( $o );
		}
		unset( $GLOBALS['gend_society_dashboard_routes'] );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/** Fixture body for a route in $scenario (generated contract, 109-fixtures). */
	function gs109d_fx( $scenario, $file ) {
		$j = gs109_fixture_json( $scenario, $file );
		return is_array( $j ) ? $j : null;
	}

	/** Install routes the hub answered since $mark (gs109-http.log), e.g. ['GET membership', 'GET gas']. */
	function gs109d_hub_routes_since( $mark ) {
		$out = array();
		foreach ( array_filter( explode( "\n", gs109p_httplog_since( $mark ) ) ) as $l ) {
			$j = json_decode( $l, true );
			if ( is_array( $j ) && preg_match( '#/wp-json/gdc-app-manager/v1/install/[^/]+/([^?]+)#', (string) ( $j['url'] ?? '' ), $m ) ) {
				$out[] = strtoupper( (string) ( $j['method'] ?? 'GET' ) ) . ' ' . trim( $m[1], '/' );
			}
		}
		return $out;
	}

	/** Stat value of the gas hero by its label. */
	function gs109d_stat( DOMXPath $xp, $label ) {
		return gs109d_text( $xp, '//div[contains(@class,"gs-bcg-hero")]//div[contains(@class,"gs-bcg-stat")][div[@class="k" and normalize-space(.)="' . $label . '"]]/div[@class="v"]' );
	}
}

$fxp = gs109p_pair( 'connected' );
if ( ! $fxp ) {
	gs109d_part( 'standalone-paired', true );
	return;
}
$token = $fxp['token'];
$hub   = $fxp['hub'];
wp_set_current_user( $admin );

// ------------------------------------------------------------------ connected (free Connected install)
gs109d_clear();
$mem   = gs109d_fx( 'connected', 'membership.json' );
$gas   = gs109d_fx( 'connected', 'gas.json' );
$mark  = gs109p_httplog_mark();
$pg    = gs109d_get( $sess, admin_url( 'index.php' ) );
$first = gs109d_hub_routes_since( $mark );
$mark2 = gs109p_httplog_mark();
$pg2   = gs109d_get( $sess, admin_url( 'index.php' ) );
$again = gs109d_hub_routes_since( $mark2 );
$xp    = gs109d_xp( $pg['body'] );
$cnt   = array_count_values( $first );
gs109_assert( 200 === $pg['status'] && 1 === ( $cnt['GET membership'] ?? 0 ) && 1 === ( $cnt['GET gas'] ?? 0 ) && 1 === ( $cnt['GET backups-status'] ?? 0 ) && ! preg_grep( '#hosting/#', $first ), 'dash-paired-requests', 'connected, first load: one GET each for membership, gas, backups-status; no hosting/* route', wp_json_encode( $first ) );
gs109_assert( ! preg_grep( '#^GET (membership|gas|backups-status)$#', $again ), 'dash-paired-cache', 'second load within cache_seconds: 0 membership / gas / backups-status requests', wp_json_encode( $again ) );
gs109_assert( 'GS109 Business Group' === gs109d_text( $xp, '//div[contains(@class,"gs-mship-cell--group")]//div[contains(@class,"gs-mship-plan-name")]' ) && in_array( $hub . '/groups/gs109-business-group/', gs109d_attrs( $xp, '//div[contains(@class,"gs-mship-cell--group")]//a[contains(@class,"gs-mship-hub-visit-btn")]', 'href' ), true ), 'dash-paired-hub-card', 'Integration Hub card: group name + Visit Hub on the paired hub' );
gs109_assert( 'Manage connection' === gs109d_text( $xp, '//div[contains(@class,"gs-mship-cell--group")]//button[@data-gs-connect-open]' ), 'dash-paired-connect-button', 'paired: the Integration Hub button reads "Manage connection"' );
$up_href = gs109d_attrs( $xp, '//a[@data-gs-popup and contains(@class,"gs-dash-sa-upgrade")]', 'href' );
$expect  = (string) $mem['membership_url'] . '?ui=embed&plan_only=1&group=dashboard';
gs109_assert( array( $expect ) === $up_href && 'Upgrade' === gs109d_text( $xp, '//a[contains(@class,"gs-dash-sa-upgrade")]' ) && '_blank' === ( gs109d_attrs( $xp, '//a[contains(@class,"gs-dash-sa-upgrade")]', 'target' )[0] ?? '' ), 'dash-paired-upgrade', 'UPGRADE = [data-gs-popup] to gend.me Change Plan (' . $expect . '), target _blank fallback', wp_json_encode( $up_href ) );
gs109_assert( 0 === gs109d_count( $xp, '//*[@id="gs-mship-root"]//*[contains(@href,"page=gs-plan")]' ) && 0 === gs109d_count( $xp, '//*[@data-gs-mship="upgrade-plan"]' ), 'dash-paired-upgrade-not-gs-plan', 'the dashboard panel has no link to the gs-plan page (109-30 removes the page) and no inline plan picker' );
$plan_txt = (string) gs109d_text( $xp, '//div[contains(@class,"gs-mship-plan-col")]' );
gs109_assert( false !== strpos( $plan_txt, 'Connected' ) && false !== strpos( $plan_txt, 'Free' ), 'dash-paired-plan-connected', 'membership card: "Connected" / "Free"', $plan_txt );
gs109_assert( 'Active' === gs109d_text( $xp, '//span[contains(@class,"gs-mship-status-badge")]' ), 'dash-paired-status', 'status badge: Active' );
$stats = array(
	'Earned'       => '0.00 DGEN',
	'Server Costs' => 'From $95.00',
	'Owed Balance' => '0.000000 GAS',
	'Next Bill'    => (string) $gas['billing']['next_run'],
	'Change'       => 'No usage yet',
);
$got = array();
foreach ( $stats as $k => $v ) {
	$got[ $k ] = (string) gs109d_stat( $xp, $k );
}
gs109_assert( $stats === $got, 'dash-paired-gas-stats', 'gas panel from /gas: Earned, Server Costs, Owed, Next Bill = gas billing date (not the membership renewal), Change', wp_json_encode( $got ) );
$add = gs109d_attrs( $xp, '//div[contains(@class,"gs-bcg-actions")]//a[@data-gs-popup]', 'href' );
gs109_assert( array( (string) $gas['servers']['add_server_url'] ) === $add && 2 === gs109d_count( $xp, '//div[contains(@class,"gs-bcg-actions")]//button[@data-gs-gas-open]' ), 'dash-paired-gas-actions', 'History + Connected Devices buttons; ADD SERVER = [data-gs-popup] to /gas servers.add_server_url', wp_json_encode( $add ) );
gs109_assert( 'After migration' === gs109d_text( $xp, '//div[contains(@class,"gs-bk-hero")]//span[contains(@class,"gs-bk-badge")]' ) && 0 === gs109d_count( $xp, '//div[contains(@class,"gs-bk-hero")]//*[@data-gs-mship="backup-now" or @data-gs-hosting-goto="backups" or @data-gs-upgrade-open or @data-gs-popup]' ) && array( (string) $mem['membership_url'] ) === gs109d_attrs( $xp, '//div[contains(@class,"gs-bk-hero")]//a[contains(.,"Learn more")]', 'href' ), 'dash-paired-backups', 'Backups (D33): "After migration", no Backup now / Manage Backups / Choose a plan, Learn more -> the membership page (new tab)' );
gs109_assert( 0 === gs109d_count( $xp, '//*[@id="gs-hosting-root-hosting"]//section[@data-panel="backups"]//button[@data-gs-hosting="backup-now"]' ) && false !== strpos( (string) gs109d_text( $xp, '//*[@id="gs-hosting-root-hosting"]//section[@data-panel="backups"]' ), 'After migration' ), 'dash-paired-hosting-backups', 'Hosting > Backups sub-tab: After migration, no Backup now' );
$caps = array();
foreach ( array( '20 GB', '5 GB', '2 GB', 'Included in your plan' ) as $c ) {
	if ( false !== strpos( (string) gs109d_text( $xp, '//div[contains(@class,"gs-mship-cell")]//div[contains(@class,"gs-hosting__resources-grid")]' ), $c ) ) {
		$caps[] = $c;
	}
}
gs109_assert( ! $caps, 'dash-paired-storage', 'storage cards: no container caps', wp_json_encode( $caps ) );
$hrefs = gs109d_attrs( $xp, '//a', 'href' );
gs109_assert( 0 === gs109d_count( $xp, '//iframe' ) && false === stripos( $pg['body'], '<iframe' ) && 0 === gs109d_count( $xp, '//*[contains(@src,"qrserver") or contains(@href,"qrserver") or contains(@data-src,"qrserver")]' ), 'dash-paired-no-iframe', 'whole page: 0 <iframe (DOM and raw HTML), nothing loads from api.qrserver.com' );
gs109_assert( false === strpos( $pg['body'], $token ) && ! preg_grep( '/' . preg_quote( $token, '/' ) . '/', $hrefs ), 'dash-paired-no-token', 'the install token is in no href and nowhere in the page (' . count( $hrefs ) . ' links)' );
$popups = gs109d_attrs( $xp, '//a[@data-gs-popup]', 'href' );
$offhub = array_values( array_filter( $popups, function ( $u ) use ( $hub ) { return 0 !== strpos( $u, $hub . '/' ); } ) );
gs109_assert( count( $popups ) >= 2 && ! $offhub, 'dash-paired-popups-hub', 'every [data-gs-popup] link (' . count( $popups ) . ') points at the paired hub', wp_json_encode( $offhub ) );
$bad = array();
foreach ( array( '//script[@src]' => 'src', '//link[@rel="stylesheet"]' => 'href' ) as $q => $attr ) {
	foreach ( gs109d_attrs( $xp, $q, $attr ) as $u ) {
		$h = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
		if ( '' !== $h && ! ( 'localhost' === $h && 8909 === (int) wp_parse_url( $u, PHP_URL_PORT ) ) ) {
			$bad[] = $u;
		}
	}
}
gs109_assert( ! $bad, 'dash-paired-no-remote-code', 'paired page: no remote script / stylesheet', wp_json_encode( $bad ) );
$cfg = null;
foreach ( $xp->query( '//script' ) as $s ) {
	if ( preg_match( '/var gendSocietyDashboard = (\{.*?\});/s', (string) $s->textContent, $m ) ) {
		$cfg = json_decode( $m[1], true );
	}
}
gs109_assert( is_array( $cfg ) && $hub === ( $cfg['hubOrigin'] ?? '' ) && (string) get_option( 'gend_society_install_id' ) === ( $cfg['installId'] ?? '' ) && '' !== ( $cfg['nonce'] ?? '' ) && false === strpos( wp_json_encode( $cfg ), $token ), 'dash-paired-js-config', 'popup script config: hub origin, install id, refresh nonce; no token', wp_json_encode( $cfg ) );
$dbg = is_readable( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
gs109_assert( false === strpos( $dbg, 'gdc_gas_ledger' ), 'dash-paired-no-ledger-errors', 'debug.log: no gdc_gas_ledger DB error' );

// AJAX: Connected Devices + Hosting > Compute Gas come from /gas (no psoo_*, no ledger, no hosting/compute-gas call).
gs109p_require_modules();
gs109p_http_capture();
gs109p_http( true );
$dev = gs109p_call( function () { do_action( 'wp_ajax_gend_society_compute_gas_devices' ); }, 'POST', array(), array( '_ajax_nonce' => wp_create_nonce( 'gs_membership_action' ) ), true );
$cg  = gs109p_call( function () { do_action( 'wp_ajax_gend_society_hosting_compute_gas' ); }, 'POST', array(), array( 'nonce' => wp_create_nonce( 'gs_membership_action' ) ), true );
$tg  = gs109p_call( function () { do_action( 'wp_ajax_gend_society_hosting_toggles_get' ); }, 'POST', array(), array( 'nonce' => wp_create_nonce( 'gs_membership_action' ) ), true );
$ar  = gs109p_hub_requests( gs109p_http( true ) );
gs109_assert( ! empty( $dev['json']['success'] ) && array() === $dev['json']['data']['devices'] && ! empty( $cg['json']['success'] ) && '0.000000 GAS' === ( $cg['json']['data']['unbilled_gas_label'] ?? '' ) && ! empty( $tg['json']['success'] ), 'dash-paired-ajax', 'devices AJAX (0 devices), compute-gas AJAX (owed 0.000000 GAS) and toggles AJAX answer from /gas / local data' );
gs109_assert( ! array_filter( $ar, function ( $r ) { return false !== strpos( $r['url'], '/hosting/' ); } ), 'dash-paired-ajax-no-hosting-route', 'AJAX: no hosting/* request (the hub has none)', wp_json_encode( array_map( function ( $r ) { return $r['method'] . ' ' . $r['url']; }, $ar ) ) );

// ------------------------------------------------------------------ store-owner (paid, gas usage, pending bill, 1 device)
gs109_fixture_scenario( 'store-owner' );
gs109d_clear();
$mem = gs109d_fx( 'store-owner', 'membership.json' );
$gas = gs109d_fx( 'store-owner', 'gas.json' );
$pg  = gs109d_get( $sess, admin_url( 'index.php' ) );
$xp  = gs109d_xp( $pg['body'] );
$plan_txt = (string) gs109d_text( $xp, '//div[contains(@class,"gs-mship-plan-col")]' );
gs109_assert( false !== strpos( $plan_txt, 'Store Owner' ) && false !== strpos( $plan_txt, '$29.99 every month' ) && false !== strpos( $plan_txt, (string) $mem['dates']['renews'] ) && false === strpos( $plan_txt, 'Free' ), 'dash-so-plan', 'store-owner: plan, price and renewal from /membership', $plan_txt );
// Expected values come from the scenario's /gas fixture (generated contract, 109-28).
$srv   = (array) ( $gas['servers'] ?? array() );
$stats = array(
	'Earned'       => (string) $gas['earned']['label'],
	'Server Costs' => ! empty( $srv['current']['label'] ) ? $srv['current']['label'] . ( ! empty( $srv['current']['unit'] ) ? ' / ' . $srv['current']['unit'] : '' ) : 'From ' . (string) ( $srv['from']['label'] ?? '' ),
	'Owed Balance' => (string) $gas['owed']['label'],
	'Next Bill'    => ! empty( $gas['billing']['pending'] ) ? 'Ready to pay' : (string) $gas['billing']['next_run'],
	'Change'       => (string) $gas['used']['change_label'],
);
$got = array();
foreach ( $stats as $k => $v ) {
	$got[ $k ] = (string) gs109d_stat( $xp, $k );
}
$owed = gs109d_text( $xp, '//div[contains(@class,"gs-bcg-stat")][div[@class="k" and normalize-space(.)="Owed Balance"]]' );
gs109_assert( ! empty( $gas['billing']['pending'] ) && $stats === $got, 'dash-so-gas-stats', 'store-owner gas from /gas: Earned, Server Costs, Owed, Next Bill "Ready to pay" (pending bill), Change', wp_json_encode( array( 'want' => $stats, 'got' => $got ) ) );
$pay  = gs109d_attrs( $xp, '//div[contains(@class,"gs-bcg-stat")]//a[@data-gs-popup]', 'href' );
$payl = 'Pay ' . (string) $gas['billing']['pending']['total_label'];
gs109_assert( array( (string) $gas['billing']['pending']['pay_url'] ) === $pay && false !== strpos( (string) $owed, $payl ), 'dash-so-pay', '"' . $payl . '" = [data-gs-popup] to the hub order-pay page', wp_json_encode( $pay ) );
gs109_assert( count( (array) $gas['history'] ) > 0 && count( (array) $gas['history'] ) === gs109d_count( $xp, '//table[@id="gs-gas-history-table"]/tbody/tr' ), 'dash-so-history', 'History popup: one row per /gas history entry (' . count( (array) $gas['history'] ) . ')' );
file_put_contents( WP_CONTENT_DIR . '/gs109-standalone-dashboard.html', $pg['body'] );
gs109_info( 'paired store-owner dashboard HTML saved: ' . strlen( $pg['body'] ) . ' bytes (wp-content/gs109-standalone-dashboard.html)' );
gs109d_clear();
$dev = gs109p_call( function () { do_action( 'wp_ajax_gend_society_compute_gas_devices' ); }, 'POST', array(), array( '_ajax_nonce' => wp_create_nonce( 'gs_membership_action' ) ), true );
$d0  = $dev['json']['data']['devices'][0] ?? array();
$g0  = (array) ( $gas['devices'][0] ?? array() );
gs109_assert( count( (array) $gas['devices'] ) === count( (array) ( $dev['json']['data']['devices'] ?? array() ) ) && '' !== (string) ( $g0['name'] ?? '' ) && $g0['name'] === ( $d0['label'] ?? '' ) && $g0['kind'] === ( $d0['type'] ?? '' ) && (bool) $g0['online'] === ( $d0['online'] ?? null ) && (int) ( $d0['last_seen'] ?? 0 ) > 0, 'dash-so-devices', 'Connected Devices AJAX: the /gas device (label, type, online, last seen)', wp_json_encode( $d0 ) );

// ------------------------------------------------------------------ inherited (group plan, PLAN-07)
gs109_fixture_scenario( 'inherited' );
gs109d_clear();
$pg = gs109d_get( $sess, admin_url( 'index.php' ) );
$xp = gs109d_xp( $pg['body'] );
gs109_assert( 'Included through GS109 Business Group' === gs109d_text( $xp, '//*[@data-gs-dash-inherit]' ) && false !== strpos( (string) gs109d_text( $xp, '//div[contains(@class,"gs-mship-plan-col")]' ), 'Social Connector' ), 'dash-inherited', 'inherited: Social Connector + "Included through GS109 Business Group"' );

// ------------------------------------------------------------------ revoked (401 on every route)
gs109_fixture_scenario( 'revoked' );
gs109d_clear();
$pg  = gs109d_get( $sess, admin_url( 'index.php' ) );
$pg  = gs109d_get( $sess, admin_url( 'index.php' ) ); // the 401 sets the health status on the first load
$xp  = gs109d_xp( $pg['body'] );
$dbg = is_readable( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
gs109_assert( 200 === $pg['status'] && 1 === gs109d_count( $xp, '//*[@id="gs-mship-root"]' ) && 1 === gs109d_count( $xp, '//*[@data-gs-dash-reconnect]//button[@data-gs-connect-open]' ) && 'Reconnect' === gs109d_text( $xp, '//div[contains(@class,"gs-mship-cell--group")]//button[@data-gs-connect-open]' ) && 0 === gs109d_count( $xp, '//a[@data-gs-popup]' ), 'dash-revoked', 'revoked (401): the panel renders with the Reconnect state, no popup links' );
gs109_assert( false === stripos( $dbg, 'PHP Fatal' ), 'dash-revoked-no-fatal', 'no PHP fatal in debug.log' );

// ------------------------------------------------------------------ hub down (timeout on every request to the hub)
gs109_fixture_scenario( 'connected' );
gs109d_clear();
$down = function ( $pre, $args, $url ) use ( $hub ) {
	return 0 === strpos( (string) $url, $hub . '/' ) ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : $pre;
};
add_filter( 'pre_http_request', $down, 1, 3 );
$html = gs109d_render( $admin );
remove_filter( 'pre_http_request', $down, 1 );
$fx = gs109d_xpf( $html );
gs109_assert( 1 === gs109d_count( $fx, '//*[@id="gs-mship-root"]' ) && false !== strpos( (string) gs109d_text( $fx, '//div[contains(@class,"gs-bcg-hero")]//p[contains(@class,"gs-bcg-sub")]' ), 'Could not reach gend.me' ) && 'Unavailable' === gs109d_text( $fx, '//div[contains(@class,"gs-bk-hero")]//span[contains(@class,"gs-bk-badge")]' ), 'dash-hub-down', 'hub down: the panel renders; gas says "Could not reach gend.me", backups "Unavailable"; no fatal' );

// ------------------------------------------------------------------ hosted record (backups-status hosted true)
gs109d_clear();
$off  = gs109p_inject( 'backups-status', 200, array( 'hosted' => true, 'plan' => null, 'lapse' => null, 'retention_days' => 30, 'choose_url' => $hub . '/my-account/memberships/?gdc_open_modal=9101&gdc_open_backups=1', 'last_backup' => null, 'actions' => array( 'backup_now' => true, 'restore' => true ), 'cache_seconds' => 300 ) );
$html = gs109d_render( $admin );
$off();
$fx = gs109d_xpf( $html );
gs109_assert( array( $hub . '/my-account/memberships/?gdc_open_modal=9101&gdc_open_backups=1' ) === gs109d_attrs( $fx, '//div[contains(@class,"gs-bk-hero")]//a[@data-gs-popup]', 'href' ) && 1 === gs109d_count( $fx, '//div[contains(@class,"gs-bk-hero")]//button[@data-gs-mship="backup-now"]' ) && 1 === gs109d_count( $fx, '//div[contains(@class,"gs-bk-hero")]//button[@data-gs-hosting-goto="backups"]' ) && 'No Active Plan' === gs109d_text( $fx, '//div[contains(@class,"gs-bk-hero")]//span[contains(@class,"gs-bk-badge")]' ), 'dash-hosted-backups', 'hosted record: Choose a plan popup (choose_url), Backup now, Manage Backups' );

// Off-hub URLs from the hub are never printed (choose_url / add_server_url on another host).
gs109d_clear();
$off1 = gs109p_inject( 'backups-status', 200, array( 'hosted' => true, 'choose_url' => 'https://evil.example/x', 'actions' => array( 'backup_now' => true ), 'cache_seconds' => 300 ) );
$g2   = gs109d_fx( 'connected', 'gas.json' );
$g2['servers']['add_server_url'] = 'javascript:alert(1)';
unset( $g2['rev'] );
$off2 = gs109p_inject( 'gas', 200, $g2 );
$html = gs109d_render( $admin );
$off1();
$off2();
gs109_assert( false === strpos( $html, 'evil.example' ) && false === stripos( $html, 'javascript:alert' ), 'dash-offhub-urls', 'choose_url / add_server_url off the paired hub are dropped' );

// Browser: UPGRADE really opens a popup on gend.me, the hub signal / closing it refreshes (dash.js popup).
$bf = WP_CONTENT_DIR . '/gs109-dash-popup.json';
$bj = is_readable( $bf ) ? json_decode( (string) file_get_contents( $bf ), true ) : null;
gs109_assert(
	is_array( $bj ) && ( time() - (int) filemtime( $bf ) ) < 3600 && md5_file( GEND_SOCIETY_DIR . 'assets/gs-dashboard-standalone.js' ) === ( $bj['plugin_md5'] ?? '' )
		&& ! empty( $bj['upgrade_popup'] ) && ! empty( $bj['signal_refresh'] ) && ! empty( $bj['foreign_ignored'] ) && ! empty( $bj['close_refresh'] ) && ! empty( $bj['blocked_link'] ) && 0 === (int) ( $bj['iframes'] ?? 1 ),
	'dash-browser-popup',
	'browser (this build, < 1 h): UPGRADE opens the Change Plan URL in a popup; the hub signal refreshes; a foreign-origin message is ignored; closing Add Server refreshes; a blocked popup shows a new-tab link; 0 iframes',
	is_array( $bj ) ? wp_json_encode( $bj ) : 'no ' . basename( $bf )
);
// Browser page renders (dash.js page; wp-content/gs109-dash-page-{unpaired,paired}.json): what a person sees.
$pu = is_readable( WP_CONTENT_DIR . '/gs109-dash-page-unpaired.json' ) ? json_decode( (string) file_get_contents( WP_CONTENT_DIR . '/gs109-dash-page-unpaired.json' ), true ) : null;
$pp = is_readable( WP_CONTENT_DIR . '/gs109-dash-page-paired.json' ) ? json_decode( (string) file_get_contents( WP_CONTENT_DIR . '/gs109-dash-page-paired.json' ), true ) : null;
$ext_ok = function ( $j, $allow_uploads ) {
	foreach ( array_keys( (array) ( $j['external'] ?? array() ) ) as $k ) {
		if ( 0 === strpos( $k, 'image secure.gravatar.com/' ) || ( $allow_uploads && 0 === strpos( $k, 'image gend.me/wp-content/uploads/' ) ) ) {
			continue;
		}
		return false;
	}
	return true;
};
$du = is_array( $pu ) ? (array) ( $pu['dom'] ?? array() ) : array();
$dp = is_array( $pp ) ? (array) ( $pp['dom'] ?? array() ) : array();
gs109_assert(
	1 === (int) ( $du['skin'] ?? 0 ) && 1 === (int) ( $du['mship'] ?? 0 ) && array( 'Connect to gend.me' ) === ( $du['connectBtn'] ?? null ) && false === stripos( (string) ( $du['gas'] ?? '' ), 'earned' ) && false !== strpos( (string) ( $du['gas'] ?? '' ), 'Connect to gend.me' )
		&& 0 === (int) ( $du['iframes'] ?? 1 ) && 0 === (int) ( $du['hostingIframes'] ?? 1 ) && array() === ( $du['scriptsRemote'] ?? null ) && array() === ( $du['stylesRemote'] ?? null ) && $ext_ok( $pu, false ),
	'dash-browser-unpaired',
	'browser, unpaired: skin + panel, "Connect to gend.me" button, gas shows only the connect text (stats/actions not visible), 0 iframes (Hosting tab opened too), no remote script/style, external requests only core gravatar',
	wp_json_encode( array( 'dom' => $du, 'external' => $pu['external'] ?? null ) )
);
$vis = array_filter(
	(array) ( $du['noticesVisible'] ?? array() ),
	function ( $t ) {
		return false !== strpos( (string) $t, 'turned on the GenD dashboard' ) && false !== strpos( (string) $t, 'Switch back to standard WordPress' );
	}
);
gs109_assert( 1 === count( $vis ), 'dash-browser-firstrun-notice-visible', 'browser, option unset: the first-run notice naming "Switch back to standard WordPress" is visible on the dashboard', wp_json_encode( $du['noticesVisible'] ?? null ) );
$want_nav = array( 'Compute Gas', 'Backups', 'Media', 'Codebase', 'Tables' );
gs109_assert(
	5 === count( (array) ( $dp['groupNav'] ?? array() ) ) && 0 === strpos( (string) ( $dp['groupNav'][0] ?? '' ), 'Business Plan -> http://localhost:8919/groups/gs109-business-group/' ) && array( home_url( '/' ) ) === ( $dp['viewSite'] ?? null )
		&& array( 'App', 'Hosting', 'Projects' ) === ( $dp['tabs'] ?? null ) && ! array_diff( $want_nav, (array) ( $dp['hostingNav'] ?? array() ) )
		&& 0 === (int) ( $dp['iframes'] ?? 1 ) && 0 === (int) ( $dp['hostingIframes'] ?? 1 ) && array() === ( $dp['scriptsRemote'] ?? null ) && array() === ( $dp['stylesRemote'] ?? null ) && $ext_ok( $pp, true ),
	'dash-browser-paired',
	'browser, paired store-owner: top nav = the gend.me group tabs, View Site = home_url, APP / HOSTING / PROJECTS, Hosting sub-nav Compute Gas / Backups / Media / Codebase / Tables, 0 iframes, no remote script/style, external requests only gravatar + consented gend.me images',
	wp_json_encode( array( 'dom' => $dp, 'external' => $pp['external'] ?? null ) )
);
gs109d_clear();
gs109d_part( 'standalone-paired', true );

if ( 0 === (int) $GLOBALS['gs109_uat']['fail'] ) {
	echo 'dashboard: ALL PASS (' . (int) $GLOBALS['gs109_uat']['pass'] . ")\n";
}
