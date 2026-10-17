<?php
/**
 * Section connect-modal (plan 109-30; D29 connect half, D23, D32 reuse-only; PLAN-01..07 via the dashboard).
 *
 * The Connect / Connected UI is a popup on the dashboard's Integration Hub card. The 108 standalone pages are gone and
 * their URLs redirect to the dashboard (popup open).
 *
 *   standalone  part "markup"     unpaired: the popup is printed through gend_society_integration_hub_card_slot inside the
 *                                 Integration Hub card, hidden, role=dialog aria-modal, gs-gas-modal pattern, no
 *                                 iframe / inline script / inline style; 106 consent list + Terms/Privacy; "Agree and connect
 *                                 to gend.me" posts to admin-post gend_society_connect_start with its nonce; pairing form
 *                                 (op connect, gs_portal_connect_submit nonce) prefilled from ?gend_society_code only when
 *                                 the code is valid; auto-open flag on gs_connect=1 / a valid code / a status; status notice
 *                                 inside the popup; 0 requests while rendering
 *               part "menu"       SUBMENU-SCAN: no gs-portal-connect / gs-plan / gs-hub-* in $menu / $submenu /
 *                                 $_registered_pages after admin_menu; the page renderers are gone
 *               part "redirects"  in-process + real loopback: old URLs 302 to the dashboard (#gs-connect for the connect
 *                                 slug) keeping a valid gend_society_code and the 108 status args; subscriber: no redirect
 *               part "web"        real index.php: popup present, gs-connect-modal.js/.css local (filemtime ver), no iframe,
 *                                 0 hub requests (unpaired); native skin: the same popup in the footer
 *               part "connected"  fixture hub: every 108 Connected field, Disconnect (revoke-first op), D23 warning for the
 *                                 paid store-owner only (link = hub membership_url, before the button, no request),
 *                                 "Disconnect locally anyway" after a hub failure, claim / transfer owner per the 108 rules,
 *                                 Reconnect on health unauthorized (card + dashboard buttons)
 *               part "handlers"   the SAME 108 ops (in-process, fake hub): pairing (consent first, 0 requests without
 *                                 it), revoke-first Disconnect (token still stored while the hub is asked), failure keeps
 *                                 everything + gs_disconnect_failed, disconnect_local, transfer; every landing = dashboard
 *                                 popup; connect-flow return landing; health notice target + suppression
 *               part "browser"    real clicks (gap2/plugin/tools/30/modal.js, result copied in as
 *                                 wp-content/gs109-modal-browser.json): open / Esc / close, prefilled pairing reaches the
 *                                 fixture /connect and ends connected in the popup, revoke-first Disconnect, transfer owner
 *   hub | container                no popup hooks, no connect page renderer, no gs-portal-connect menu entry, the 1.3.1
 *                                 handler still registered, old URL -> dashboard
 *
 * Every part prints "connect-modal <part>: ALL PASS (n)" or "... FAILURES n"; the section ends with
 * "connect-modal: ALL PASS (n)" when nothing failed.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( ! function_exists( 'gs109m_part' ) ) {
	function gs109m_part( $name, $end = false ) {
		static $at = array();
		if ( ! $end ) {
			$at[ $name ] = array( (int) $GLOBALS['gs109_uat']['pass'], (int) $GLOBALS['gs109_uat']['fail'] );
			echo "-- part {$name}\n";
			return;
		}
		$p = (int) $GLOBALS['gs109_uat']['pass'] - $at[ $name ][0];
		$f = (int) $GLOBALS['gs109_uat']['fail'] - $at[ $name ][1];
		echo 'connect-modal ' . $name . ': ' . ( 0 === $f && $p > 0 ? 'ALL PASS (' . $p . ')' : 'FAILURES ' . max( 1, $f ) ) . "\n";
	}

	/** The popup HTML as the dashboard slot prints it (string; '' when the module is absent). */
	function gs109m_modal( array $get = array(), $context = 'slot' ) {
		if ( ! function_exists( 'gend_society_portal_connect_modal_html' ) ) {
			return '';
		}
		$_GET     = wp_slash( $get );
		$_REQUEST = $_GET;
		try {
			return (string) gend_society_portal_connect_modal_html( $context );
		} finally {
			$_GET     = array();
			$_REQUEST = array();
		}
	}

	/** The custom dashboard rendered in-process as $uid with $get (screen 'dashboard'). */
	function gs109m_dashboard( $uid, array $get = array() ) {
		if ( ! function_exists( 'gend_society_render_custom_dashboard_screen' ) ) {
			return '';
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		wp_set_current_user( $uid );
		set_current_screen( 'dashboard' );
		$_GET     = wp_slash( $get );
		$_REQUEST = $_GET;
		unset( $GLOBALS['gend_society_connect_modal_printed'] );
		ob_start();
		try {
			gend_society_render_custom_dashboard_screen();
		} finally {
			$html     = (string) ob_get_clean();
			$_GET     = array();
			$_REQUEST = array();
		}
		return $html;
	}

	/** True when $nonce verifies for $action as the current user. */
	function gs109m_nonce_ok( $nonce, $action ) {
		return false !== wp_verify_nonce( (string) $nonce, $action );
	}

	/** Field value of $name inside the form whose hidden "action" (and "op") match. */
	function gs109m_form_field( DOMXPath $xp, $action, $op, $name ) {
		$q = '//form[.//input[@name="action"][@value="' . $action . '"]' . ( null !== $op ? ' and .//input[@name="op"][@value="' . $op . '"]' : '' ) . ']//input[@name="' . $name . '"]';
		$a = gs109pl_attrs( $xp, $q, 'value' );
		return $a ? $a[0] : null;
	}

	/** Query args + fragment of a redirect Location. */
	function gs109m_loc( $location ) {
		$q = array();
		parse_str( (string) wp_parse_url( (string) $location, PHP_URL_QUERY ), $q );
		return array(
			'path' => (string) wp_parse_url( (string) $location, PHP_URL_PATH ),
			'q'    => $q,
			'frag' => (string) wp_parse_url( (string) $location, PHP_URL_FRAGMENT ),
		);
	}

	/** Every removed slug found in the admin menu globals after admin_menu ran as $uid. */
	function gs109m_menu_scan( $uid ) {
		global $menu, $submenu, $_registered_pages, $admin_page_hooks;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$was     = get_current_user_id();
		wp_set_current_user( $uid );
		ob_start();
		do_action( 'admin_menu', '' );
		ob_end_clean();
		wp_set_current_user( $was );
		$removed = '/(^|[?&=])(gs-portal-connect|gs-plan|gs-hub-[a-z-]+)$/';
		$found   = array();
		$n       = 0;
		foreach ( (array) $menu as $m ) {
			$n++;
			if ( is_array( $m ) && isset( $m[2] ) && preg_match( $removed, (string) $m[2] ) ) {
				$found[] = 'menu:' . $m[2];
			}
		}
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $m ) {
				$n++;
				if ( is_array( $m ) && isset( $m[2] ) && preg_match( $removed, (string) $m[2] ) ) {
					$found[] = 'submenu[' . $parent . ']:' . $m[2];
				}
			}
		}
		foreach ( array_keys( (array) $_registered_pages ) as $hook ) {
			if ( preg_match( '/_page_(gs-portal-connect|gs-plan|gs-hub-[a-z-]+)$/', (string) $hook ) ) {
				$found[] = 'registered:' . $hook;
			}
		}
		foreach ( array_keys( (array) $admin_page_hooks ) as $slug ) {
			if ( preg_match( $removed, (string) $slug ) ) {
				$found[] = 'hooks:' . $slug;
			}
		}
		return array( $found, $n );
	}

	/** Call the old-slug redirect handler in-process for wp-admin/$script?$get as the current user. */
	function gs109m_old( $script, array $get ) {
		$GLOBALS['pagenow'] = $script; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$fn                 = function_exists( 'gend_society_portal_connect_legacy_redirect' ) ? 'gend_society_portal_connect_legacy_redirect' : '__return_null';
		$r                  = gs109p_call( $fn, 'GET', $get );
		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		return $r;
	}
}

$gs109m_mode = gs109_mode();

// ======================================================================================== hub | container
if ( 'standalone' !== $gs109m_mode ) {
	gs109m_part( $gs109m_mode );
	gs109p_setup();
	$uid = gs109p_admin_id();
	if ( is_multisite() ) {
		$sa = get_user_by( 'login', (string) ( get_super_admins()[0] ?? '' ) );
		$uid = $sa ? (int) $sa->ID : $uid;
	}
	gs109_assert( ! function_exists( 'gend_society_portal_connect_render_page' ) && ! function_exists( 'gend_society_portal_connect_register_menu' ), 'cm-' . $gs109m_mode . '-no-page', $gs109m_mode . ': the Connect page renderer and its menu registration are gone' );
	gs109_assert( false !== has_action( 'admin_post_gend_society_portal_connect_submit', 'gend_society_portal_connect_handle_submit' ), 'cm-' . $gs109m_mode . '-handler', $gs109m_mode . ': the 1.3.1 admin-post handler is still registered (handlers unchanged)' );
	gs109_assert( ! function_exists( 'gend_society_portal_connect_standalone_ready' ) || ! gend_society_portal_connect_standalone_ready(), 'cm-' . $gs109m_mode . '-not-standalone', $gs109m_mode . ': standalone branch inactive' );
	$slot = function_exists( 'gend_society_portal_connect_modal_slot' ) ? has_action( 'gend_society_integration_hub_card_slot', 'gend_society_portal_connect_modal_slot' ) : false;
	gs109_assert( false === $slot && false === has_action( 'admin_footer', 'gend_society_portal_connect_modal_footer' ), 'cm-' . $gs109m_mode . '-no-popup', $gs109m_mode . ': no popup hooked (slot or footer)' );
	wp_set_current_user( $uid );
	if ( function_exists( 'gend_society_portal_connect_enqueue' ) ) {
		gend_society_portal_connect_enqueue( 'index.php' );
	}
	gs109_assert( ! wp_script_is( 'gend-society-connect-modal', 'enqueued' ) && ! wp_style_is( 'gend-society-connect-modal', 'enqueued' ), 'cm-' . $gs109m_mode . '-no-assets', $gs109m_mode . ': popup assets not enqueued on index.php' );
	list( $found, $n ) = gs109m_menu_scan( $uid );
	if ( ! $found ) {
		echo 'SUBMENU-SCAN: no removed slug (' . $gs109m_mode . ', ' . $n . " menu entries)\n";
	}
	gs109_assert( ! $found && $n > 0, 'cm-' . $gs109m_mode . '-menu', $gs109m_mode . ': no gs-portal-connect / gs-plan / gs-hub-* entry after admin_menu (' . $n . ' entries)', wp_json_encode( $found ) );
	wp_set_current_user( $uid );
	$r = gs109m_old( 'index.php', array( 'page' => 'gs-portal-connect' ) );
	gs109_assert( 'redirect' === $r['kind'] && 0 === strpos( $r['location'], admin_url( 'index.php' ) ) && false === strpos( $r['location'], 'page=gs-portal-connect' ), 'cm-' . $gs109m_mode . '-old-url', $gs109m_mode . ': index.php?page=gs-portal-connect redirects to the dashboard', $r['kind'] . ' ' . $r['location'] );
	$r = gs109m_old( 'admin.php', array( 'page' => 'gs-plan' ) );
	gs109_assert( 'return' === $r['kind'], 'cm-' . $gs109m_mode . '-plan-slug-untouched', $gs109m_mode . ': page=gs-plan is not redirected here (never existed on ' . $gs109m_mode . ')', $r['kind'] . ' ' . $r['location'] );
	gs109m_part( $gs109m_mode, true );
	gs109_cleanup();
	if ( 0 === (int) $GLOBALS['gs109_uat']['fail'] ) {
		echo 'connect-modal: ALL PASS (' . (int) $GLOBALS['gs109_uat']['pass'] . ")\n";
	}
	return;
}

// ======================================================================================== standalone
gs109p_setup();
gs109_snapshot_options( array( 'gend_society_admin_experience', 'gs109_fixture_scenario', 'gs109_fixture_dir', 'gs109_fixture_hub' ) );
$admin = gs109p_admin_id();
wp_set_current_user( $admin );
delete_option( 'gend_society_admin_experience' );
wp_cache_delete( 'alloptions', 'options' );
$unpaired = '' === (string) get_option( 'gend_society_install_token', '' );

// ---------------------------------------------------------------------------------------- part markup
gs109m_part( 'markup' );
gs109_assert( $unpaired, 'cm-unpaired-start', 'bed starts unpaired' );
gs109_assert(
	function_exists( 'gend_society_portal_connect_modal_html' ) && function_exists( 'gend_society_portal_connect_modal_slot' )
		&& 10 === has_action( 'gend_society_integration_hub_card_slot', 'gend_society_portal_connect_modal_slot' ),
	'cm-hooked',
	'the popup is hooked to 109-29\'s gend_society_integration_hub_card_slot'
);
gs109_http_record();
$dash = gs109m_dashboard( $admin );
$req  = gs109_http_log();
$dx   = gs109pl_xp( $dash );
$card = '//div[contains(@class,"gs-mship-cell--group")]';
gs109_assert( '' !== $dash && ! $req, 'cm-render-0-requests', 'unpaired dashboard render with the popup: 0 requests', wp_json_encode( $req ) );
gs109_assert( 1 === gs109pl_count( $dx, '//*[@data-gs-connect-modal]' ) && 1 === gs109pl_count( $dx, $card . '//*[@data-gs-connect-modal]' ), 'cm-in-card', 'exactly one popup, printed inside the Integration Hub card (slot)' );
gs109_assert( 1 === gs109pl_count( $dx, $card . '//button[@data-gs-connect-open]' ) && 'Connect to gend.me' === gs109pl_text( $dx, $card . '//button[@data-gs-connect-open]' ), 'cm-launcher', 'the card keeps its one button[data-gs-connect-open] "Connect to gend.me" (109-29)' );
$m  = gs109m_modal();
$mx = gs109pl_xp( $m );
gs109_assert( 1 === gs109pl_count( $mx, '//div[@id="gs-connect-modal"][@hidden][@aria-hidden="true"][@data-gs-connect-modal][contains(@class,"gs-gas-modal")]' ), 'cm-hidden', 'popup container hidden (hidden + aria-hidden) with the dashboard\'s gs-gas-modal pattern' );
gs109_assert( 1 === gs109pl_count( $mx, '//div[contains(@class,"gs-gas-modal__dialog")][@role="dialog"][@aria-modal="true"][@aria-labelledby="gs-connect-modal-title"]' ) && 'Connect to gend.me' === gs109pl_text( $mx, '//*[@id="gs-connect-modal-title"]' ), 'cm-dialog', 'role=dialog aria-modal=true labelled "Connect to gend.me"' );
gs109_assert( 1 === gs109pl_count( $mx, '//button[@type="button"][@data-gs-connect-close][@aria-label]' ) && 1 === gs109pl_count( $mx, '//div[contains(@class,"gs-gas-modal__overlay")][@data-gs-connect-close]' ), 'cm-close', 'close button (aria-label) + overlay close' );
gs109_assert( false === stripos( $m, '<iframe' ) && false === stripos( $m, '<script' ) && false === stripos( $m, '<style' ) && false === stripos( $m, ' style=' ) && false === stripos( $m, 'onclick' ), 'cm-no-inline', 'popup: no iframe, no inline script / style / style attribute / handler' );
$items = function_exists( 'gend_society_consent_disclosure_items' ) ? array_values( gend_society_consent_disclosure_items() ) : array();
$li    = array();
foreach ( $mx->query( '//ul[contains(@class,"gend-society-connect-disclosure")]/li' ) as $n ) {
	$li[] = trim( $n->textContent );
}
gs109_assert( $items && $li === array_map( 'strval', $items ), 'cm-consent-list', 'the 106 disclosure list, item by item (' . count( $items ) . ')', wp_json_encode( $li ) );
$links = gs109pl_attrs( $mx, '//p[contains(@class,"gend-society-connect-links")]/a', 'href' );
gs109_assert( 2 === count( $links ) && in_array( gend_society_terms_url(), array_map( 'html_entity_decode', $links ), true ) && in_array( gend_society_privacy_url(), array_map( 'html_entity_decode', $links ), true ), 'cm-legal', 'Terms of Service + Privacy Policy links', wp_json_encode( $links ) );
$start_nonce = gs109m_form_field( $mx, 'gend_society_connect_start', null, '_wpnonce' );
$start_btn   = gs109pl_text( $mx, '//form[.//input[@name="action"][@value="gend_society_connect_start"]]//button[@type="submit"]' );
$start_act   = gs109pl_attrs( $mx, '//form[.//input[@name="action"][@value="gend_society_connect_start"]]', 'action' );
gs109_assert( 'Agree and connect to gend.me' === $start_btn && $start_act && admin_url( 'admin-post.php' ) === html_entity_decode( $start_act[0] ) && gs109m_nonce_ok( $start_nonce, 'gend_society_connect_start' ), 'cm-start-form', '"Agree and connect to gend.me" posts to admin-post gend_society_connect_start with its 108 nonce' );
$pair_nonce = gs109m_form_field( $mx, 'gend_society_portal_connect_submit', 'connect', 'gs_portal_connect_nonce' );
gs109_assert( gs109m_nonce_ok( $pair_nonce, 'gs_portal_connect_submit' ) && 1 === gs109pl_count( $mx, '//input[@id="gend_society_consent"][@type="checkbox"][@required]' ) && '' === ( gs109pl_attrs( $mx, '//input[@name="pairing_code"]', 'value' )[0] ?? 'x' ), 'cm-pair-form', 'pairing form: op connect, gs_portal_connect_submit nonce, required consent box, empty code field' );
gs109_assert_eq( '0', gs109pl_attrs( $mx, '//*[@data-gs-connect-modal]', 'data-gs-connect-autoopen' )[0] ?? '', 'cm-autoopen-off', 'plain dashboard: popup does not auto-open' );
$p  = gs109m_modal( array( 'gend_society_code' => 'ABC234' ) );
$px = gs109pl_xp( $p );
gs109_assert( 'ABC234' === ( gs109pl_attrs( $px, '//input[@name="pairing_code"]', 'value' )[0] ?? '' ) && 1 === gs109pl_count( $px, '//details[contains(@class,"gend-society-connect-pairing")][@open]' ) && '1' === ( gs109pl_attrs( $px, '//*[@data-gs-connect-modal]', 'data-gs-connect-autoopen' )[0] ?? '' ), 'cm-prefill', '?gend_society_code=ABC234: field prefilled, pairing details open, popup auto-opens' );
$bad = array();
foreach ( array( 'ABC1O0', 'abc234', 'ABC2345', 'AB"><b', 'ABCIL2' ) as $c ) {
	$v = gs109pl_attrs( gs109pl_xp( gs109m_modal( array( 'gend_society_code' => $c ) ) ), '//input[@name="pairing_code"]', 'value' )[0] ?? 'x';
	if ( '' !== $v ) {
		$bad[] = $c . '=>' . $v;
	}
}
gs109_assert( ! $bad, 'cm-prefill-invalid', 'invalid codes (O/0/I/1/L, lower case, 7 chars, markup) are not prefilled', wp_json_encode( $bad ) );
gs109_assert_eq( '1', gs109pl_attrs( gs109pl_xp( gs109m_modal( array( 'gs_connect' => '1' ) ) ), '//*[@data-gs-connect-modal]', 'data-gs-connect-autoopen' )[0] ?? '', 'cm-autoopen-flag', 'gs_connect=1 (the connect URL): popup auto-opens' );
$nx = gs109pl_xp( gs109m_modal( array( 'gs_connect' => '1', 'gs_connect_status' => 'error', 'gs_connect_message' => 'Please confirm you agree before connecting.' ) ) );
gs109_assert( 'Please confirm you agree before connecting.' === gs109pl_text( $nx, '//div[contains(@class,"gs-gas-modal__body")]//div[contains(@class,"notice-error")]/p' ), 'cm-notice', 'handler status notice is shown inside the popup' );
$fx = gs109pl_xp( gs109m_modal( array(), 'footer' ) );
gs109_assert( 1 === gs109pl_count( $fx, '//div[@id="gs-connect-modal"][@data-gs-connect-modal][not(contains(@class,"gs-gas-modal"))]' ) && 1 === gs109pl_count( $fx, '//form[.//input[@name="action"][@value="gend_society_connect_start"]]' ), 'cm-footer-variant', 'native-skin variant: same popup content without the dark gs-gas-modal look' );
wp_set_current_user( gs109p_user( 'editor' ) );
gs109_assert( '' === gs109m_modal(), 'cm-editor-none', 'a non-administrator gets no popup' );
wp_set_current_user( $admin );
gs109m_part( 'markup', true );

// ---------------------------------------------------------------------------------------- part menu
gs109m_part( 'menu' );
list( $found, $n ) = gs109m_menu_scan( $admin );
if ( ! $found && $n > 0 ) {
	echo 'SUBMENU-SCAN: no removed slug (standalone, ' . $n . " menu entries)\n";
}
gs109_assert( ! $found && $n > 0, 'cm-menu-scan', 'after admin_menu: no gs-portal-connect / gs-plan / gs-hub-* in $menu, $submenu, $_registered_pages, $admin_page_hooks (' . $n . ' entries)', wp_json_encode( $found ) );
gs109_assert(
	! function_exists( 'gend_society_portal_connect_render_page' ) && ! function_exists( 'gend_society_portal_connect_render_standalone' ) && ! function_exists( 'gend_society_plan_page_render' ) && ! function_exists( 'gend_society_plan_page_register' ) && ! function_exists( 'gend_society_hub_embed_register' ) && ! file_exists( GEND_SOCIETY_DIR . 'inc/hub-embed.php' ),
	'cm-renderers-gone',
	'page renderers / registrations gone (portal-connect page, gs-plan, hub-embed.php)'
);
gs109_assert( ! file_exists( GEND_SOCIETY_DIR . 'assets/gs-hub-embed.js' ) && ! file_exists( GEND_SOCIETY_DIR . 'assets/gs-hub-embed.css' ) && ! file_exists( GEND_SOCIETY_DIR . 'assets/gs-connect.css' ) && file_exists( GEND_SOCIETY_DIR . 'assets/gs-connect-modal.js' ) && file_exists( GEND_SOCIETY_DIR . 'assets/gs-connect-modal.css' ), 'cm-assets-files', 'gs-hub-embed.* and gs-connect.css removed; gs-connect-modal.js/.css shipped' );
gs109_assert( function_exists( 'gend_society_portal_connect_render_connect' ) && function_exists( 'gend_society_portal_connect_render_connected' ) && function_exists( 'gend_society_plan_assets_enqueue' ), 'cm-kept', 'kept: the 108 Connect / Connected card renderers (popup body) and the gs-plan asset helper (upgrade page)' );
gs109m_part( 'menu', true );

// ---------------------------------------------------------------------------------------- part redirects
gs109m_part( 'redirects' );
$connect = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : '';
gs109_assert_eq( admin_url( 'index.php?gs_connect=1' ) . '#gs-connect', $connect, 'cm-connect-url', 'gend_society_connect_url() = dashboard + gs_connect=1 + #gs-connect' );
$cases = array(
	array( 'admin.php', array( 'page' => 'gs-portal-connect' ), true, array() ),
	array( 'index.php', array( 'page' => 'gs-portal-connect' ), true, array() ),
	array( 'index.php', array( 'page' => 'gs-portal-connect', 'gend_society_code' => 'ABC234' ), true, array( 'gend_society_code' => 'ABC234' ) ),
	array( 'index.php', array( 'page' => 'gs-portal-connect', 'gend_society_code' => 'abc1o0' ), true, array() ),
	array( 'index.php', array( 'page' => 'gs-portal-connect', 'gs_connect_status' => 'success', 'gs_connect_message' => 'Disconnected.', 'gs_disconnect_failed' => '1' ), true, array( 'gs_connect_status' => 'success', 'gs_connect_message' => 'Disconnected.', 'gs_disconnect_failed' => '1' ) ),
	array( 'admin.php', array( 'page' => 'gs-plan' ), false, array() ),
	array( 'admin.php', array( 'page' => 'gs-hub-groups' ), false, array() ),
	array( 'admin.php', array( 'page' => 'gs-hub-feature-suite' ), false, array() ),
	array( 'admin.php', array( 'page' => 'gs-hub-projects', 'gend_society_code' => 'ABC234' ), false, array( 'gend_society_code' => 'ABC234' ) ),
);
foreach ( $cases as $i => $c ) {
	$r  = gs109m_old( $c[0], $c[1] );
	$l  = gs109m_loc( $r['location'] );
	$ok = 'redirect' === $r['kind'] && 302 === $r['status'] && 0 === strpos( $r['location'], admin_url( 'index.php' ) ) && ! isset( $l['q']['page'] );
	if ( $c[2] ) {
		$ok = $ok && '1' === ( $l['q']['gs_connect'] ?? '' ) && 'gs-connect' === $l['frag'];
	}
	foreach ( array( 'gend_society_code', 'gs_connect_status', 'gs_connect_message', 'gs_disconnect_failed' ) as $k ) {
		$ok = $ok && ( $c[3][ $k ] ?? null ) === ( $l['q'][ $k ] ?? null );
	}
	gs109_assert( $ok, 'cm-old-' . $i, $c[0] . '?' . http_build_query( $c[1] ) . ' -> 302 ' . ( $c[2] ? 'dashboard popup' : 'dashboard' ) . ( $c[3] ? ' keeping ' . implode( ',', array_keys( $c[3] ) ) : '' ), $r['kind'] . ' ' . $r['status'] . ' ' . $r['location'] );
}
$r = gs109m_old( 'admin-post.php', array( 'page' => 'gs-portal-connect' ) );
gs109_assert( 'return' === $r['kind'], 'cm-old-other-script', 'admin-post.php / other screens are not touched', $r['kind'] );
wp_set_current_user( gs109p_user( 'subscriber' ) );
$r = gs109m_old( 'index.php', array( 'page' => 'gs-portal-connect' ) );
gs109_assert( 'return' === $r['kind'], 'cm-old-subscriber', 'subscriber: no redirect (manage_options only)', $r['kind'] );
wp_set_current_user( $admin );
$sess = gs109p_session( $admin );
$w1   = gs109p_web( 'GET', admin_url( 'index.php?page=gs-portal-connect&gend_society_code=ABC234' ), $sess );
$w2   = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-plan' ), $sess );
$w3   = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-hub-groups' ), $sess );
$l1   = gs109m_loc( $w1['location'] );
gs109_assert( 302 === $w1['status'] && 'ABC234' === ( $l1['q']['gend_society_code'] ?? '' ) && '1' === ( $l1['q']['gs_connect'] ?? '' ) && 'gs-connect' === $l1['frag'], 'cm-web-pair-link', 'real request: the gend.me /pair short-link target 302s to the dashboard popup with the code', $w1['status'] . ' ' . $w1['location'] );
gs109_assert( 302 === $w2['status'] && admin_url( 'index.php' ) === $w2['location'] && 302 === $w3['status'] && admin_url( 'index.php' ) === $w3['location'], 'cm-web-plan-hub', 'real requests: page=gs-plan and page=gs-hub-groups 302 to the dashboard', $w2['status'] . ' ' . $w2['location'] . ' | ' . $w3['status'] . ' ' . $w3['location'] );
$su  = gs109p_session( gs109p_user( 'subscriber' ) );
$w4  = gs109p_web( 'GET', admin_url( 'admin.php?page=gs-portal-connect' ), $su );
gs109_assert( false === strpos( $w4['location'], 'gs_connect' ) && in_array( $w4['status'], array( 403, 500 ), true ), 'cm-web-subscriber', 'real request as subscriber: no popup redirect (HTTP ' . $w4['status'] . ')', $w4['location'] );
gs109m_part( 'redirects', true );

// ---------------------------------------------------------------------------------------- part web
gs109m_part( 'web' );
$mark = gs109p_httplog_mark();
$pg   = gs109p_web( 'GET', admin_url( 'index.php?gs_connect=1&gend_society_code=ABC234' ), $sess );
$hosts = array();
foreach ( array_filter( explode( "\n", gs109p_httplog_since( $mark ) ) ) as $l ) {
	$j = json_decode( $l, true );
	$u = is_array( $j ) ? (string) ( $j['url'] ?? '' ) : '';
	if ( false !== strpos( $u, 'localhost:8919' ) || false !== strpos( $u, 'gend.me' ) ) {
		$hosts[] = $u;
	}
}
$wx = gs109pl_xp_page( $pg['body'] );
gs109_assert( 200 === $pg['status'] && ! $hosts, 'cm-web-0-requests', 'unpaired dashboard with the popup open + a code: 200 and 0 requests to the hub / gend.me', wp_json_encode( $hosts ) );
gs109_assert( 1 === gs109pl_count( $wx, '//*[@data-gs-connect-modal][@data-gs-connect-autoopen="1"]' ) && 'ABC234' === ( gs109pl_attrs( $wx, '//*[@data-gs-connect-modal]//input[@name="pairing_code"]', 'value' )[0] ?? '' ), 'cm-web-popup', 'page: one popup, auto-open, code prefilled' );
$js = gs109pl_attrs( $wx, '//script[contains(@src,"assets/gs-connect-modal.js")]', 'src' );
$cs = gs109pl_attrs( $wx, '//link[contains(@href,"assets/gs-connect-modal.css")]', 'href' );
gs109_assert( 1 === count( $js ) && 1 === count( $cs ) && 0 === strpos( html_entity_decode( $js[0] ), GEND_SOCIETY_URL ) && preg_match( '/ver=[0-9.]+\.\d{9,}/', html_entity_decode( $js[0] . ' ' . $cs[0] ) ), 'cm-web-assets', 'gs-connect-modal.js + .css from the plugin, filemtime versions', wp_json_encode( array( $js, $cs ) ) );
gs109_assert( 0 === gs109pl_count( $wx, '//iframe' ) && 0 === gs109pl_count( $wx, '//link[contains(@href,"gs-connect.css")]' ), 'cm-web-no-frame', 'page: no iframe; the old gs-connect.css is not loaded' );
update_option( 'gend_society_admin_experience', 'native', true );
wp_cache_delete( 'alloptions', 'options' );
$nat = gs109p_web( 'GET', admin_url( 'index.php?gs_connect=1' ), $sess );
$nx  = gs109pl_xp_page( $nat['body'] );
gs109_assert( 200 === $nat['status'] && false === strpos( $nat['body'], 'gs-admin-style' ) && 1 === gs109pl_count( $nx, '//*[@data-gs-connect-modal][@data-gs-connect-autoopen="1"][not(contains(@class,"gs-gas-modal"))]' ) && 1 === count( gs109pl_attrs( $nx, '//script[contains(@src,"assets/gs-connect-modal.js")]', 'src' ) ), 'cm-web-native', 'native skin: the dashboard still offers the same popup (footer), auto-open, local script' );
delete_option( 'gend_society_admin_experience' );
wp_cache_delete( 'alloptions', 'options' );
gs109m_part( 'web', true );

// ---------------------------------------------------------------------------------------- part connected
gs109m_part( 'connected' );
$fxp = gs109p_pair( 'connected' );
if ( ! $fxp ) {
	gs109m_part( 'connected', true );
	gs109_cleanup();
	return;
}
$token = $fxp['token'];
wp_set_current_user( $admin );
$conn                  = get_option( 'gend_society_connection', array() );
$conn['group']['avatar'] = 'http://localhost:8919/wp-content/uploads/group-avatars/3/avatar.png';
$conn['owner_user_id'] = $admin;
update_option( 'gend_society_connection', $conn, false );
gs109pl_vm( 'connected' );
gs109_http_record();
$m  = gs109m_modal();
$mx = gs109pl_xp( $m );
$req = gs109_http_log();
$rows = array();
foreach ( $mx->query( '//table[contains(@class,"gend-society-connect-details")]//tr' ) as $tr ) {
	$th = $tr->getElementsByTagName( 'th' );
	$td = $tr->getElementsByTagName( 'td' );
	if ( $th->length && $td->length ) {
		$rows[ trim( $th->item( 0 )->textContent ) ] = trim( preg_replace( '/\s+/', ' ', $td->item( 0 )->textContent ) );
	}
}
$admin_user = get_userdata( $admin );
gs109_assert( ! $req && 'Connected to gend.me' === gs109pl_text( $mx, '//div[contains(@class,"gend-society-connect-card")][contains(@class,"is-connected")]/h2' ), 'cm-c-card', 'connected: the 108 Connected card inside the popup, rendered with 0 requests' );
gs109_assert(
	'http://localhost:8919' === ( $rows['gend.me'] ?? '' ) && false !== strpos( (string) ( $rows['Business group'] ?? '' ), (string) $conn['group']['name'] ) && $fxp['install_id'] === ( $rows['Install ID'] ?? '' )
		&& '' !== ( $rows['Connected'] ?? '' ) && $admin_user->display_name === ( $rows['Connection owner'] ?? '' ) && 'Approval on gend.me' === ( $rows['Connected via'] ?? '' ) && 'GS109 Owner' === ( $rows['Approved on gend.me by'] ?? '' ),
	'cm-c-fields',
	'fields: gend.me hub, business group, install id, connected, owner, via, approver',
	wp_json_encode( $rows )
);
gs109_assert( 1 === gs109pl_count( $mx, '//td[contains(@class,"gend-society-connect-group")]/img[@width="48"]' ) && 1 === gs109pl_count( $mx, '//td[contains(@class,"gend-society-connect-group")]/a[@target="_blank"][@rel="noopener"]' ), 'cm-c-group', 'group avatar + name link (new tab)' );
$dn = gs109m_form_field( $mx, 'gend_society_portal_connect_submit', 'disconnect', 'gs_portal_connect_nonce' );
gs109_assert( gs109m_nonce_ok( $dn, 'gs_portal_connect_disconnect' ) && 0 === gs109pl_count( $mx, '//div[@data-gs-disconnect-billing]' ) && false === strpos( $m, 'Disconnect locally anyway' ), 'cm-c-disconnect', 'Disconnect form (op disconnect, gs_portal_connect_disconnect nonce); free plan: no D23 warning; no local fallback before a failure' );
gs109_assert( false === strpos( $m, $token ) && false === stripos( $m, '<script' ) && false === stripos( $m, ' style=' ), 'cm-c-no-token', 'no install token, no inline script/style in the connected popup' );
gs109_assert( 0 === gs109pl_count( $mx, '//form[.//input[@name="op"][@value="claim_owner"]]' ) && 0 === gs109pl_count( $mx, '//form[.//input[@name="op"][@value="transfer_owner"]]' ) && false !== strpos( $m, 'There is no other administrator to transfer the connection to.' ), 'cm-c-owner-alone', 'owner is the only administrator: no claim, no transfer select, the 108 note' );
$other = gs109p_user( 'administrator' );
$m2    = gs109m_modal();
$m2x   = gs109pl_xp( $m2 );
$tn    = gs109m_form_field( $m2x, 'gend_society_portal_connect_submit', 'transfer_owner', 'gend_society_owner_nonce' );
gs109_assert( gs109m_nonce_ok( $tn, 'gend_society_transfer_owner' ) && in_array( (string) $other, gs109pl_attrs( $m2x, '//select[@name="new_owner"]/option', 'value' ), true ), 'cm-c-transfer-form', 'owner sees "Transfer ownership" (gend_society_transfer_owner nonce) listing the other administrator' );
wp_set_current_user( $other );
gs109_assert( 0 === gs109pl_count( gs109pl_xp( gs109m_modal() ), '//form[.//input[@name="op"][@value="transfer_owner" or @value="claim_owner"]]' ), 'cm-c-not-owner', 'another administrator (owner valid): no transfer / claim forms (108 rule)' );
$conn['owner_user_id'] = 0;
update_option( 'gend_society_connection', $conn, false );
$cx = gs109pl_xp( gs109m_modal() );
gs109_assert( gs109m_nonce_ok( gs109m_form_field( $cx, 'gend_society_portal_connect_submit', 'claim_owner', 'gend_society_owner_nonce' ), 'gend_society_claim_owner' ), 'cm-c-claim', 'no owner set: any administrator gets "Make me the owner" (gend_society_claim_owner nonce)' );
$conn['owner_user_id'] = $admin;
update_option( 'gend_society_connection', $conn, false );
wp_set_current_user( $admin );
$lx = gs109pl_xp( gs109m_modal( array( 'gs_connect' => '1', 'gs_disconnect_failed' => '1', 'gs_connect_status' => 'error', 'gs_connect_message' => 'Could not reach gend.me; the connection is still active there.' ) ) );
gs109_assert( gs109m_nonce_ok( gs109m_form_field( $lx, 'gend_society_portal_connect_submit', 'disconnect_local', 'gend_society_disconnect_local_nonce' ), 'gend_society_disconnect_local' ) && 'Could not reach gend.me; the connection is still active there.' === gs109pl_text( $lx, '//div[contains(@class,"notice-error")]/p' ), 'cm-c-local', 'after a hub failure: the error notice + "Disconnect locally anyway" (gend_society_disconnect_local nonce)' );
// D23 (moved from section card): paid store-owner warns, free / inherited do not; cached membership only.
$warn = '//div[@data-gs-disconnect-billing]';
gs109pl_vm( 'store-owner' );
gs109_http_record();
$so  = gs109m_modal();
$sox = gs109pl_xp( $so );
$mu  = (string) ( gs109_fixture_json( 'store-owner', 'membership.json' )['membership_url'] ?? '' );
$lk  = gs109pl_attrs( $sox, $warn . '//a', 'href' );
gs109_assert_eq( 'Your Store Owner plan on gend.me keeps billing after you disconnect. Manage or cancel it on gend.me.', gs109pl_text( $sox, $warn ), 'cm-d23-copy', 'store-owner (paid): D23 billing warning copy' );
gs109_assert( 1 === count( $lk ) && html_entity_decode( $lk[0] ) === $mu && 1 === gs109pl_count( $sox, $warn . '//a[@target="_blank"][@rel="noopener"]' ), 'cm-d23-link', 'link = the hub membership_url, new tab', $lk );
gs109_assert( 1 === $sox->query( $warn . '/following::input[@name="op"][@value="disconnect"]' )->length && array() === gs109_http_log(), 'cm-d23-order', 'warning before the Disconnect button; no request while rendering' );
gs109pl_vm( 'inherited' );
gs109_assert( 0 === gs109pl_count( gs109pl_xp( gs109m_modal() ), $warn ), 'cm-d23-inherited', 'inherited (group pays): no warning' );
gs109pl_vm( 'connected' );
// Reconnect when gend.me no longer recognises the token.
update_option( 'gend_society_connection_health', array( 'status' => 'unauthorized', 'checked_at' => time(), 'code' => 401 ), false );
$ux = gs109pl_xp( gs109m_modal() );
gs109_assert( '1' === gs109m_form_field( $ux, 'gend_society_connect_start', null, 'reconnect' ) && gs109m_nonce_ok( gs109m_form_field( $ux, 'gend_society_connect_start', null, '_wpnonce' ), 'gend_society_connect_start' ) && 'Reconnect to gend.me' === gs109pl_text( $ux, '//form[.//input[@name="reconnect"]]//button' ), 'cm-c-reconnect', 'health unauthorized: "Reconnect to gend.me" (gend_society_connect_start, reconnect=1) in the popup' );
$rd = gs109pl_xp( gs109m_dashboard( $admin ) );
gs109_assert( 'Reconnect' === gs109pl_text( $rd, $card . '//button[@data-gs-connect-open]' ) && 1 === gs109pl_count( $rd, '//div[contains(@class,"gs-mship-plan-col")]//button[@data-gs-connect-open]' ) && 1 === gs109pl_count( $rd, '//*[@data-gs-connect-modal]' ), 'cm-c-reconnect-dash', 'dashboard: the card button reads Reconnect, the plan column Reconnect button uses the same data-gs-connect-open, one popup' );
delete_option( 'gend_society_connection_health' );
gs109m_part( 'connected', true );

// ---------------------------------------------------------------------------------------- part handlers
gs109m_part( 'handlers' );
$landing = function ( $r, $status ) {
	$l = gs109m_loc( $r['location'] );
	return 'redirect' === $r['kind'] && 0 === strpos( $r['location'], admin_url( 'index.php' ) ) && '1' === ( $l['q']['gs_connect'] ?? '' ) && 'gs-connect' === $l['frag'] && $status === ( $l['q']['gs_connect_status'] ?? '' );
};
$nu = function_exists( 'gend_society_connect_notice_url' ) ? gend_society_connect_notice_url( 'success', 'Connected to gend.me.' ) : '';
$nl = gs109m_loc( $nu );
gs109_assert( 0 === strpos( $nu, admin_url( 'index.php' ) ) && '1' === ( $nl['q']['gs_connect'] ?? '' ) && 'gs-connect' === $nl['frag'] && 'success' === ( $nl['q']['gs_connect_status'] ?? '' ), 'cm-h-return-landing', 'connect-flow return / handler landing (gend_society_connect_notice_url) = dashboard popup with the status', $nu );
// Revoke-first Disconnect: the hub is asked while the token is still stored, then the options go.
$order = array();
$hookf = function ( $pre, $args, $url ) use ( &$order ) {
	if ( false !== strpos( (string) $url, '/disconnect' ) ) {
		$order[] = 'hub:' . ( '' !== (string) get_option( 'gend_society_install_token', '' ) ? 'token-present' : 'token-gone' );
		return array( 'headers' => array(), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
};
add_filter( 'pre_http_request', $hookf, 1, 3 );
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'disconnect', 'gs_portal_connect_nonce' => wp_create_nonce( 'gs_portal_connect_disconnect' ) ) );
remove_filter( 'pre_http_request', $hookf, 1 );
$order[] = 'after:' . ( '' === (string) gs109p_raw( 'gend_society_install_token' ) ? 'token-gone' : 'token-present' );
gs109_assert( array( 'hub:token-present', 'after:token-gone' ) === $order && $landing( $r, 'success' ), 'cm-h-disconnect', 'Disconnect: POST /disconnect while the token is stored, then removed; lands on the dashboard popup', wp_json_encode( array( $order, $r['location'] ) ) );
// Failure keeps everything.
$fxp = gs109p_pair( 'connected' );
wp_set_current_user( $admin );
$tok_before = (string) gs109p_raw( 'gend_society_install_token' );
$failf      = function ( $pre, $args, $url ) {
	return false !== strpos( (string) $url, '/disconnect' ) ? new WP_Error( 'http_request_failed', 'cURL error 7 (gs109 fake)' ) : $pre;
};
add_filter( 'pre_http_request', $failf, 1, 3 );
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'disconnect', 'gs_portal_connect_nonce' => wp_create_nonce( 'gs_portal_connect_disconnect' ) ) );
remove_filter( 'pre_http_request', $failf, 1 );
$l = gs109m_loc( $r['location'] );
gs109_assert( $landing( $r, 'error' ) && '1' === ( $l['q']['gs_disconnect_failed'] ?? '' ) && $tok_before === (string) gs109p_raw( 'gend_society_install_token' ) && '' !== $tok_before, 'cm-h-disconnect-fail', 'hub unreachable: nothing deleted, lands on the popup with gs_disconnect_failed=1', $r['location'] );
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'disconnect_local', 'gend_society_disconnect_local_nonce' => wp_create_nonce( 'gend_society_disconnect_local' ) ) );
gs109_assert( $landing( $r, 'success' ) && '' === (string) gs109p_raw( 'gend_society_install_token' ), 'cm-h-disconnect-local', '"Disconnect locally anyway": local connection removed, lands on the popup' );
// Transfer owner (best-effort hub notice).
$fxp = gs109p_pair( 'connected' );
wp_set_current_user( $admin );
$conn                  = get_option( 'gend_society_connection', array() );
$conn['owner_user_id'] = $admin;
update_option( 'gend_society_connection', $conn, false );
$ownf = function ( $pre, $args, $url ) {
	return false !== strpos( (string) $url, '/owner' ) ? array( 'headers' => array(), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ) : $pre;
};
add_filter( 'pre_http_request', $ownf, 1, 3 );
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'transfer_owner', 'new_owner' => (string) $other, 'gend_society_owner_nonce' => wp_create_nonce( 'gend_society_transfer_owner' ) ) );
remove_filter( 'pre_http_request', $ownf, 1 );
$c2 = gs109p_raw( 'gend_society_connection' );
gs109_assert( $landing( $r, 'success' ) && (int) $other === (int) ( $c2['owner_user_id'] ?? 0 ), 'cm-h-transfer', 'transfer_owner: owner = the other administrator, lands on the popup', $r['location'] );
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'transfer_owner', 'new_owner' => (string) $admin, 'gend_society_owner_nonce' => wp_create_nonce( 'gend_society_transfer_owner' ) ) );
gs109_assert( $landing( $r, 'error' ), 'cm-h-transfer-not-owner', 'the former owner can no longer transfer (108 rule), error lands on the popup' );
// Pairing by code: consent first; without it nothing is sent.
foreach ( array( 'gend_society_install_token', 'gend_society_gend_base_url', 'gend_society_connection', 'gend_society_consent', 'gend_society_remote_consent' ) as $o ) {
	delete_option( $o );
}
wp_cache_delete( 'alloptions', 'options' );
gs109_http_record();
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'connect', 'gs_portal_connect_nonce' => wp_create_nonce( 'gs_portal_connect_submit' ), 'gend_base_url' => 'http://localhost:8919', 'pairing_code' => 'ABC234' ) );
gs109_assert( $landing( $r, 'error' ) && array() === gs109_http_log(), 'cm-h-pair-noconsent', 'pairing without the consent box: error on the popup, 0 requests' );
$seen = array();
$pf   = function ( $pre, $args, $url ) use ( &$seen ) {
	if ( false === strpos( (string) $url, '/wp-json/gdc-app-manager/v1/connect' ) ) {
		return $pre;
	}
	$b      = json_decode( (string) $args['body'], true );
	$seen[] = array( 'consent' => function_exists( 'gend_society_consent_given' ) && gend_society_consent_given(), 'code' => $b['pairing_code'] ?? '' );
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array( 'install_token' => 'gs109-modal-paired-' . wp_generate_password( 8, false, false ), 'install_id' => $b['install_id'] ?? '', 'group' => array( 'id' => 3, 'name' => 'GS109 Alpha', 'slug' => 'gs109-alpha' ), 'approved_by' => array( 'id' => 84, 'display_name' => 'gs109admin' ) ) ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $pf, 1, 3 );
$r = gs109p_call( 'gend_society_portal_connect_handle_submit', 'POST', array(), array( 'op' => 'connect', 'gs_portal_connect_nonce' => wp_create_nonce( 'gs_portal_connect_submit' ), 'gend_base_url' => 'http://localhost:8919', 'pairing_code' => 'abc234', 'gend_society_consent' => '1' ) );
remove_filter( 'pre_http_request', $pf, 1 );
$l = gs109m_loc( $r['location'] );
gs109_assert( $landing( $r, 'success' ) && 'Connected to gend.me as part of GS109 Alpha.' === rawurldecode( (string) ( $l['q']['gs_connect_message'] ?? '' ) ) && 1 === count( $seen ) && true === $seen[0]['consent'] && 'ABC234' === $seen[0]['code'] && '' !== (string) gs109p_raw( 'gend_society_install_token' ), 'cm-h-pair', 'pairing: consent recorded before the one /connect request, code normalised, connected, success notice on the popup', wp_json_encode( array( $seen, $r['location'] ) ) );
// Health notice: Reconnect opens the popup; not printed while the popup is open with its own warning.
if ( function_exists( 'gend_society_connection_health_notice_html' ) || is_readable( GEND_SOCIETY_DIR . 'inc/connection-health.php' ) ) {
	require_once GEND_SOCIETY_DIR . 'inc/connection-health.php';
	update_option( 'gend_society_connection_health', array( 'status' => 'unauthorized', 'checked_at' => time(), 'code' => 401 ), false );
	delete_user_meta( $admin, 'gend_society_health_dismissed' );
	$nh = gend_society_connection_health_notice_html( gend_society_connection_health_get() );
	gs109_assert( false !== strpos( $nh, 'href="' . esc_url( gend_society_connect_url() ) . '"' ), 'cm-h-health-link', 'health notice Reconnect link = the dashboard popup', $nh );
	set_current_screen( 'dashboard' );
	$a = gs109p_call( 'gend_society_connection_health_render_notice', 'GET', array( 'gs_connect' => '1' ) );
	$b = gs109p_call( 'gend_society_connection_health_render_notice', 'GET', array() );
	gs109_assert( '' === trim( $a['output'] ) && false !== strpos( $b['output'], 'gs-connection-health-notice' ), 'cm-h-health-suppressed', 'health notice: hidden while the popup is opened by the request (own warning), shown on the plain dashboard' );
	delete_option( 'gend_society_connection_health' );
}
gs109m_part( 'handlers', true );

// ---------------------------------------------------------------------------------------- part browser
gs109m_part( 'browser' );
$bf = WP_CONTENT_DIR . '/gs109-modal-browser.json';
$bj = is_readable( $bf ) ? json_decode( (string) file_get_contents( $bf ), true ) : null;
$js = GEND_SOCIETY_DIR . 'assets/gs-connect-modal.js';
gs109_assert(
	is_array( $bj ) && ( time() - (int) filemtime( $bf ) ) < 3600 && file_exists( $js ) && md5_file( $js ) === ( $bj['js_md5'] ?? '' ),
	'cm-b-fresh',
	'browser result for this gs-connect-modal.js (< 1 h old)',
	is_array( $bj ) ? '' : 'no ' . basename( $bf )
);
foreach (
	array(
		'open_click'      => 'a real click on the Integration Hub button opens the popup (visible, focus inside)',
		'esc_closes'      => 'Esc closes it and focus returns to the button',
		'close_button'    => 'the close button closes it',
		'overlay_closes'  => 'a click on the overlay closes it',
		'focus_trap'      => 'Tab / Shift+Tab stay inside the dialog',
		'autoopen_code'   => '/pair target (old URL + code) lands on the dashboard with the popup open and the code prefilled',
		'pair_connected'  => 'submitting the prefilled code (consent ticked) reaches the fixture /connect and comes back connected, popup open on the Connected card',
		'disconnect_order' => 'Disconnect click: the fixture hub saw /disconnect while the token was stored; popup shows Disconnected',
		'transfer_owner'  => 'Transfer ownership click: owner changed, popup shows the success notice',
		'no_external'     => 'nothing left localhost before the consent click (core gravatar avatars excepted); after it only the Phase 105 consent-gated gend.me upload images',
	) as $k => $text
) {
	gs109_assert( is_array( $bj ) && ! empty( $bj[ $k ] ), 'cm-b-' . str_replace( '_', '-', $k ), $text, is_array( $bj ) ? wp_json_encode( $bj[ $k . '_detail' ] ?? null ) : '' );
}
gs109m_part( 'browser', true );

gs109_cleanup();
if ( 0 === (int) $GLOBALS['gs109_uat']['fail'] ) {
	echo 'connect-modal: ALL PASS (' . (int) $GLOBALS['gs109_uat']['pass'] . ")\n";
}
