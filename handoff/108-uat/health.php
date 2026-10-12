<?php
/**
 * Section health (plan 108-10, CON-06; modes standalone, container, hub).
 *
 * standalone (bed connected to the hub clone by a real pairing code):
 *   - module wiring: inc/connection-health.php needs standalone + paired (absent before connect), hooks,
 *     the enqueue (manage_options + connected only, footer, GEND_SOCIETY_VERSION, localized due flag that
 *     follows the interval filter), the script waits for window load;
 *   - a real wp-admin page load over HTTP with the interval filtered to 0 by a test mu-plugin: script
 *     printed with due=true, the page itself makes no /health request (the ping is after load, from JS);
 *   - AJAX check: nonce + capability; 200 -> ok; the hub revokes the token -> unauthorized + notice_html;
 *     the next admin page (HTTP and admin_notices) shows the notice; options are never deleted on 401;
 *   - dismiss -> hidden; another 401 check (new checked_at) -> shown again; fetcher 401s while already
 *     unauthorized do not undo a dismissal;
 *   - the Connected card shows "Reconnect to gend.me"; using it (start in-process + hub approve + return)
 *     reconnects the SAME hub membership with a new consent record (source reconnect); the next check is
 *     ok and the notice + button are gone;
 *   - features / remote-membership fetch 401 -> unauthorized, 200 -> ok, other codes leave it alone;
 *   - no cron event: _get_cron_array() before/after has no new hook with "health" or "gend_society_connection".
 * container / hub: the module is not loaded (note_status undefined, no AJAX hooks); the fetchers return
 *   exactly what they returned before (null on 401, the decoded body on 200) and write no health option.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_pair.php';

$gs108h_cron_hooks = function () {
	$out = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( (array) $events as $hook => $unused ) {
			$out[ $hook ] = true;
		}
	}
	return array_keys( $out );
};

// In-process admin-ajax call: wp_doing_ajax on, wp_die_ajax_handler throws, output captured.
$gs108h_ajax = function ( $uid, $fn, array $post ) {
	wp_set_current_user( $uid );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = wp_slash( $post );
	$_REQUEST                  = $_POST;
	$doing                     = function () {
		return true;
	};
	$handler = function () {
		return function ( $message = '', $title = '', $args = array() ) {
			$e         = new GS108C_Stop( is_scalar( $message ) ? (string) $message : 'die' );
			$e->kind   = 'die';
			$e->status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : ( is_int( $title ) ? $title : 0 );
			throw $e;
		};
	};
	add_filter( 'wp_doing_ajax', $doing, PHP_INT_MAX );
	add_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
	ob_start();
	$msg = '';
	try {
		call_user_func( $fn );
	} catch ( GS108C_Stop $e ) {
		$msg = $e->getMessage();
	} finally {
		$body = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $doing, PHP_INT_MAX );
		remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		$_POST    = array();
		$_REQUEST = array();
	}
	wp_cache_delete( 'alloptions', 'options' );
	return array(
		'body' => $body,
		'json' => json_decode( $body, true ),
		'msg'  => $msg,
	);
};

// ---------------------------------------------------------------------------------------------------------
if ( 'standalone' !== gs108_mode() ) {
	gs108_assert( ! function_exists( 'gend_society_connection_health_note_status' ) && ! function_exists( 'gend_society_connection_health_check' ), 'health-absent', 'connection-health.php not loaded on ' . gs108_mode() . ' (note_status undefined)' );
	gs108_assert( false === has_action( 'wp_ajax_gend_society_connection_health' ) && false === has_action( 'admin_notices', 'gend_society_connection_health_render_notice' ), 'health-no-hooks', 'no health AJAX / notice hooks on ' . gs108_mode() );
	gs108_assert( function_exists( 'gend_society_module_needs_met' ) && ! gend_society_module_needs_met( array( 'standalone', 'paired' ) ), 'health-needs', 'manifest needs standalone+paired not met on ' . gs108_mode() );
	if ( 'container' !== gs108_mode() ) {
		return;
	}
	// Container bed: the fetchers keep their 1.3.1 results and write no health option.
	gs108_snapshot_options();
	gs108c_setup();
	update_option( 'gend_society_install_id', '11111111-2222-4333-8444-555555555555', false );
	update_option( 'gend_society_install_token', str_repeat( 'a', 64 ), false );
	update_option( 'gend_society_gend_base_url', gs108c_hub(), false );
	delete_option( 'gend_society_connection_health' );
	$cases = array(
		array( 401, array( 'code' => 'unauthorized' ), null ),
		array( 500, array( 'x' => 1 ), null ),
		array( 200, array( 'gs108' => 'ok', 'n' => 2 ), array( 'gs108' => 'ok', 'n' => 2 ) ),
	);
	foreach ( array( 'features' => 'gend_society_features_fetch_remote', 'membership' => 'gend_society_remote_membership_fetch' ) as $what => $fn ) {
		foreach ( $cases as $c ) {
			$off = gs108p_fake_hub( '/' . $what, $c[0], $c[1] );
			$got = call_user_func( $fn );
			$off();
			gs108_assert_eq( $c[2], $got, "health-container-{$what}-{$c[0]}", "{$fn}() on HTTP {$c[0]} returns the 1.3.1 value" );
		}
	}
	gs108_assert( null === gs108c_raw( 'gend_society_connection_health' ), 'health-container-no-option', 'no gend_society_connection_health option written on a container' );
	return;
}

// --------------------------------------------------------------------------------------------- standalone
$cron0 = $gs108h_cron_hooks();
if ( ! gs108p_require() ) {
	return;
}
$admin = gs108c_admin_id();
$sub   = gs108c_subscriber();
gs108_http_record();
$identity = gend_society_connect_identity();
$iid      = $identity['install_id'];
$file     = GEND_SOCIETY_DIR . 'inc/connection-health.php';

gs108_assert( ! gend_society_module_needs_met( array( 'standalone', 'paired' ) ), 'health-needs-unpaired', 'needs standalone+paired not met while not connected' );

$r = gs108p_connect( $admin );
if ( ! gs108_assert( 'success' === gs108p_notice( $r )['status'], 'health-setup-connected', 'connected by pairing code', wp_json_encode( $r ) ) ) {
	return;
}
$mid = (int) ( gs108p_inspect( $iid )['membership_id'] ?? 0 );
gs108_assert( $mid > 0, 'health-setup-record', 'hub record membership ' . $mid );
gs108_assert( gend_society_module_needs_met( array( 'standalone', 'paired' ) ), 'health-needs-paired', 'needs standalone+paired met once connected' );
if ( ! function_exists( 'gend_society_connection_health_check' ) ) {
	// This process booted before the connect; the next request loads the module through the manifest.
	require_once $file;
}
gs108_assert( function_exists( 'gend_society_connection_health_check' ) && function_exists( 'gend_society_connection_health_note_status' ), 'health-module', 'connection-health.php loaded' );
gs108_assert(
	false !== has_action( 'admin_enqueue_scripts', 'gend_society_connection_health_enqueue' ) && false !== has_action( 'admin_notices', 'gend_society_connection_health_render_notice' )
	&& false !== has_action( 'wp_ajax_gend_society_connection_health', 'gend_society_connection_health_ajax' ) && false !== has_action( 'wp_ajax_gend_society_connection_health_dismiss', 'gend_society_connection_health_ajax_dismiss' )
	&& false === has_action( 'wp_ajax_nopriv_gend_society_connection_health' ),
	'health-hooks',
	'enqueue, admin_notices, logged-in-only AJAX check + dismiss'
);
$src = (string) file_get_contents( $file );
gs108_assert( ! preg_match( '/wp_schedule_(single_)?event|wp_next_scheduled|delete_option\(/', $src ), 'health-src-no-cron-no-delete', 'module source has no cron scheduling and no delete_option' );
$js = (string) file_get_contents( GEND_SOCIETY_DIR . 'assets/gs-connection-health.js' );
gs108_assert( false !== strpos( $js, "window.addEventListener('load'" ) && false !== strpos( $js, 'if (!cfg.due)' ) && false === strpos( $js, 'setInterval' ) && false === strpos( $js, 'setTimeout' ), 'health-js-after-load', 'script pings only after window load, only when due, no timers' );

// --- enqueue + due flag.
$enqueue = function ( $uid ) {
	wp_dequeue_script( 'gend-society-connection-health' );
	wp_deregister_script( 'gend-society-connection-health' );
	wp_set_current_user( $uid );
	gend_society_connection_health_enqueue( 'index.php' );
	$s = wp_scripts()->query( 'gend-society-connection-health', 'registered' );
	$d = $s ? (string) wp_scripts()->get_data( 'gend-society-connection-health', 'data' ) : '';
	$c = array();
	if ( preg_match( '/var gendSocietyConnectionHealth = (\{.*\});/s', $d, $m ) ) {
		$c = (array) json_decode( $m[1], true );
	}
	return array(
		'enqueued' => wp_script_is( 'gend-society-connection-health', 'enqueued' ),
		'script'   => $s,
		'cfg'      => $c,
	);
};
delete_option( 'gend_society_connection_health' );
$e = $enqueue( $admin );
gs108_assert(
	$e['enqueued'] && $e['script'] && false !== strpos( (string) $e['script']->src, 'assets/gs-connection-health.js' ) && GEND_SOCIETY_VERSION === $e['script']->ver && 1 === (int) wp_scripts()->get_data( 'gend-society-connection-health', 'group' ),
	'health-enqueue',
	'admin: gs-connection-health.js enqueued in the footer with ver ' . GEND_SOCIETY_VERSION
);
gs108_assert( '1' === ( $e['cfg']['due'] ?? null ) && admin_url( 'admin-ajax.php' ) === ( $e['cfg']['ajaxUrl'] ?? '' ) && false !== wp_verify_nonce( (string) ( $e['cfg']['nonce'] ?? '' ), 'gend_society_connection_health' ), 'health-due-first', 'never checked -> due="1" (wp_localize_script strings), ajaxUrl + valid nonce localized' );
update_option( 'gend_society_connection_health', array( 'status' => 'ok', 'code' => 200, 'checked_at' => time() - 100 ), false );
$e = $enqueue( $admin );
gs108_assert( '' === ( $e['cfg']['due'] ?? null ), 'health-due-throttled', 'checked 100 s ago, interval 300 -> due=false' );
$f0 = function () {
	return 0;
};
add_filter( 'gend_society_connection_health_interval', $f0 );
$e = $enqueue( $admin );
remove_filter( 'gend_society_connection_health_interval', $f0 );
gs108_assert( '1' === ( $e['cfg']['due'] ?? null ), 'health-due-filter', 'interval filtered to 0 -> due=true' );
update_option( 'gend_society_connection_health', array( 'status' => 'ok', 'code' => 200, 'checked_at' => time() - 301 ), false );
$e = $enqueue( $admin );
gs108_assert( '1' === ( $e['cfg']['due'] ?? null ), 'health-due-expired', 'checked 301 s ago -> due=true' );
$e = $enqueue( $sub );
gs108_assert( ! $e['enqueued'], 'health-enqueue-subscriber', 'subscriber: nothing enqueued' );
$nf = function () {
	return false;
};
add_filter( 'gend_society_remote_allowed', $nf );
$e = $enqueue( $admin );
gs108_assert( $e['enqueued'] && '' === ( $e['cfg']['due'] ?? null ), 'health-due-no-consent', 'remote calls not allowed -> due=false' );
$x = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax', array( 'nonce' => wp_create_nonce( 'gend_society_connection_health' ) ) );
remove_filter( 'gend_society_remote_allowed', $nf );
gs108_assert( 'skipped' === ( $x['json']['status'] ?? '' ), 'health-ajax-skipped', 'remote calls not allowed -> {status: skipped}', $x['body'] );
wp_dequeue_script( 'gend-society-connection-health' );
wp_deregister_script( 'gend-society-connection-health' );

// --- AJAX refusals.
$x = $gs108h_ajax( $sub, 'gend_society_connection_health_ajax', array( 'nonce' => wp_create_nonce( 'gend_society_connection_health' ) ) );
gs108_assert( 'forbidden' === ( $x['json']['status'] ?? '' ), 'health-ajax-subscriber', 'subscriber -> forbidden', $x['body'] );
$n0 = count( gs108c_hub_calls( '/health' ) );
$x  = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax', array( 'nonce' => 'bad' ) );
gs108_assert( '-1' === $x['msg'] && $n0 === count( gs108c_hub_calls( '/health' ) ), 'health-ajax-nonce', 'bad nonce -> -1, no hub request' );

// --- 200 -> ok.
$nonce = function () use ( $admin ) {
	wp_set_current_user( $admin );
	return wp_create_nonce( 'gend_society_connection_health' );
};
$x = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax', array( 'nonce' => $nonce() ) );
$h = gend_society_connection_health_get();
gs108_assert( array( 'status' => 'ok' ) === $x['json'] && 'ok' === $h['status'] && 200 === $h['code'] && abs( time() - $h['checked_at'] ) < 30, 'health-ok', 'healthy token: {status: ok}, option ok/200/now', $x['body'] );
$hc = gs108c_hub_calls( '/health' );
gs108_assert( $hc && false !== strpos( (string) end( $hc ), 'GET ' . gs108c_hub() . '/wp-json/gdc-app-manager/v1/install/' . $iid . '/health' ), 'health-request', 'GET {hub}/wp-json/gdc-app-manager/v1/install/{id}/health', (string) end( $hc ) );
wp_set_current_user( $admin );
ob_start();
gend_society_connection_health_render_notice();
gs108_assert( '' === ob_get_clean(), 'health-ok-no-notice', 'no notice while ok' );

// --- a real admin page load over HTTP (test mu-plugin sets the interval to 0).
$mu = WP_CONTENT_DIR . '/mu-plugins/gs108-health-throttle.php';
file_put_contents( $mu, "<?php\n// gs108 test only (108-10 health section): check on every admin load.\nadd_filter( 'gend_society_connection_health_interval', '__return_zero' );\n" );
gs108_on_cleanup(
	function () use ( $mu ) {
		@unlink( $mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
	},
	'throttle mu-plugin'
);
$page = function ( $path = 'index.php' ) use ( $admin ) {
	$exp   = time() + HOUR_IN_SECONDS;
	$mgr   = WP_Session_Tokens::get_instance( $admin );
	$token = $mgr->create( $exp );
	$res   = wp_remote_get(
		gs108c_site() . '/wp-admin/' . $path,
		array(
			'timeout'     => 30,
			'redirection' => 0,
			'cookies'     => array(
				AUTH_COOKIE      => wp_generate_auth_cookie( $admin, $exp, 'auth', $token ),
				LOGGED_IN_COOKIE => wp_generate_auth_cookie( $admin, $exp, 'logged_in', $token ),
			),
		)
	);
	$mgr->destroy( $token );
	return is_wp_error( $res ) ? 'HTTP ERROR ' . $res->get_error_message() : (int) wp_remote_retrieve_response_code( $res ) . "\n" . wp_remote_retrieve_body( $res );
};
$log_count = function () {
	$f = WP_CONTENT_DIR . '/gs108-http.log';
	return is_readable( $f ) ? count( file( $f ) ) : 0;
};
$log_since = function ( $n ) {
	$f = WP_CONTENT_DIR . '/gs108-http.log';
	return is_readable( $f ) ? array_slice( file( $f ), $n ) : array();
};
update_option( 'gend_society_connection_health', array( 'status' => 'ok', 'code' => 200, 'checked_at' => time() ), false );
$l0   = $log_count();
$html = $page();
$new  = implode( '', $log_since( $l0 ) );
gs108_assert( 0 === strpos( $html, '200' ) && false !== strpos( $html, 'id="adminmenu"' ), 'health-http-page', 'wp-admin/index.php renders for the admin over HTTP', substr( $html, 0, 120 ) );
gs108_assert( (bool) preg_match( '#<script[^>]+src="[^"]*assets/gs-connection-health\.js\?ver=' . preg_quote( GEND_SOCIETY_VERSION, '#' ) . '"#', $html ) && (bool) preg_match( '/var gendSocietyConnectionHealth = \{[^;]*"due":"1"/', $html ), 'health-http-script', 'page prints the script with due=true (interval 0 via the test mu-plugin, checked just now)' );
gs108_assert( false === strpos( $new, '/health' ), 'health-http-no-sync-ping', 'the page load itself makes no /health request (the ping runs from JS after load)' );
gs108_assert( false === strpos( $html, 'gs-connection-health-notice' ), 'health-http-no-notice-ok', 'no Reconnect notice while ok' );

// --- the hub revokes the token -> unauthorized.
$conn_before    = gs108p_conn_raw();
$consent_before = gs108p_consent_raw();
$opts_before    = array();
global $wpdb;
foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'gend\\_society\\_%' AND option_name <> 'gend_society_connection_health'" ) as $o ) {
	$opts_before[ $o ] = gs108c_raw( $o );
}
$token = (string) get_option( 'gend_society_install_token', '' );
gs108_assert_eq( 200, gs108p_hub_revoke( $iid, $token ), 'health-hub-revoke', 'hub revokes the install (POST /install/{id}/disconnect)' );
gs108_assert_eq( 401, gs108p_health( $iid, $token ), 'health-hub-401', 'hub now answers 401 for the token' );
$x = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax', array( 'nonce' => $nonce() ) );
$h = gend_society_connection_health_get();
gs108_assert( 'unauthorized' === ( $x['json']['status'] ?? '' ) && 'unauthorized' === $h['status'] && 401 === $h['code'], 'health-unauthorized', '401 -> {status: unauthorized}, option unauthorized/401', $x['body'] );
$nh = (string) ( $x['json']['notice_html'] ?? '' );
gs108_assert(
	false !== strpos( $nh, 'class="notice notice-warning is-dismissible gs-connection-health-notice"' ) && false !== strpos( $nh, 'data-gs-health-checked="' . $h['checked_at'] . '"' )
	&& false !== strpos( $nh, 'gend.me no longer recognises this site&#039;s connection.' ) && false !== strpos( $nh, '<a href="' . esc_url( gend_society_connect_url() ) . '">Reconnect</a>' ),
	'health-notice-html',
	'notice_html: dismissible warning keyed to checked_at with a Reconnect link to the Connect screen',
	$nh
);
$after = array();
foreach ( array_keys( $opts_before ) as $o ) {
	$after[ $o ] = gs108c_raw( $o );
}
gs108_assert( $opts_before === $after && $conn_before === gs108p_conn_raw() && $consent_before === gs108p_consent_raw(), 'health-401-options-intact', 'after the 401 every gend_society_* option (token, connection, consent, ...) is byte-identical' );

// The next admin load shows the notice (admin_notices + real HTTP page).
wp_set_current_user( $admin );
ob_start();
gend_society_connection_health_render_notice();
$notice = (string) ob_get_clean();
gs108_assert( $nh === $notice, 'health-notice-render', 'admin_notices renders the same notice' );
ob_start();
wp_set_current_user( $sub );
gend_society_connection_health_render_notice();
gs108_assert( '' === ob_get_clean(), 'health-notice-subscriber', 'subscriber: no notice' );
$html = $page();
gs108_assert( false !== strpos( $html, 'gs-connection-health-notice' ) && false !== strpos( $html, 'no longer recognises this site&#039;s connection.' ), 'health-http-notice', 'next wp-admin page load (HTTP) shows the Reconnect notice' );
$html = $page( 'index.php?page=gs-portal-connect' );
gs108_assert( false === strpos( $html, 'gs-connection-health-notice' ) && false !== strpos( $html, 'Reconnect to gend.me' ), 'health-http-connect-screen', 'Connect screen: no duplicate notice, the card shows its Reconnect button' );

// --- dismiss.
$h1 = gend_society_connection_health_get();
$x  = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax_dismiss', array( 'nonce' => $nonce(), 'checked_at' => (string) $h1['checked_at'] ) );
gs108_assert( 'dismissed' === ( $x['json']['status'] ?? '' ) && (string) $h1['checked_at'] === (string) get_user_meta( $admin, 'gend_society_health_dismissed', true ), 'health-dismiss', 'dismiss stores user meta = checked_at', $x['body'] );
gs108_on_cleanup(
	function () use ( $admin ) {
		delete_user_meta( $admin, 'gend_society_health_dismissed' );
	},
	'dismiss meta'
);
$x = $gs108h_ajax( $sub, 'gend_society_connection_health_ajax_dismiss', array( 'nonce' => wp_create_nonce( 'gend_society_connection_health' ) ) );
gs108_assert( 'forbidden' === ( $x['json']['status'] ?? '' ), 'health-dismiss-subscriber', 'subscriber cannot dismiss' );
wp_set_current_user( $admin );
ob_start();
gend_society_connection_health_render_notice();
gs108_assert( '' === ob_get_clean(), 'health-dismissed-hidden', 'dismissed: notice no longer rendered' );
$html = $page();
gs108_assert( false === strpos( $html, 'gs-connection-health-notice' ), 'health-http-dismissed', 'dismissed: next HTTP admin load has no notice (fetchers seeing 401 do not undo it)' );
gs108_assert_eq( $h1['checked_at'], gend_society_connection_health_get()['checked_at'], 'health-fetch-401-keeps-check', 'a fetcher 401 while already unauthorized keeps checked_at' );

// Another 401 check (new checked_at) -> shown again.
$h1['checked_at'] -= 10;
update_option( 'gend_society_connection_health', $h1, false );
update_user_meta( $admin, 'gend_society_health_dismissed', (string) $h1['checked_at'] );
$x  = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax', array( 'nonce' => $nonce() ) );
$h2 = gend_society_connection_health_get();
wp_set_current_user( $admin );
ob_start();
gend_society_connection_health_render_notice();
$notice = (string) ob_get_clean();
gs108_assert( 'unauthorized' === ( $x['json']['status'] ?? '' ) && $h2['checked_at'] !== $h1['checked_at'] && false !== strpos( $notice, 'data-gs-health-checked="' . $h2['checked_at'] . '"' ), 'health-new-check-shown', 'a later 401 check has a new checked_at and the notice is back' );
$x = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax_dismiss', array( 'nonce' => $nonce() ) );
gs108_assert( (string) $h2['checked_at'] === (string) get_user_meta( $admin, 'gend_society_health_dismissed', true ), 'health-dismiss-default', 'dismiss without checked_at keys to the stored check' );

// --- the Connected card's Reconnect button reconnects the same hub record.
$card = gs108p_render( $admin );
gs108_assert( false !== strpos( $card, '<button type="submit" class="button button-primary">Reconnect to gend.me</button>' ), 'health-card-button', 'while unauthorized the Connected card shows the primary "Reconnect to gend.me" button' );
$c0 = gs108c_opt( 'gend_society_consent', array() );
$c0['at'] = time() - 100;
update_option( 'gend_society_consent', $c0, false );
$st = gs108c_start( $admin, true );
list( $ast, $adata ) = gs108c_approve( $st['request'], $st['state'] );
$q  = is_array( $adata ) && isset( $adata['redirect'] ) ? gs108c_query( $adata['redirect'] ) : array();
$rr = gs108c_return(
	$admin,
	array(
		'code'  => (string) ( $q['code'] ?? '' ),
		'state' => (string) ( $q['state'] ?? '' ),
	)
);
gs108_assert( 'redirect' === $st['kind'] && 'success' === gs108p_notice( $rr )['status'], 'health-reconnect', 'Reconnect: start -> hub approve -> return succeeds', wp_json_encode( array( $st['location'], $ast, $rr['location'] ) ) );
$c1 = gs108c_opt( 'gend_society_consent', array() );
gs108_assert( 'reconnect' === ( $c1['source'] ?? '' ) && (int) ( $c1['at'] ?? 0 ) > (int) $c0['at'], 'health-reconnect-consent', 'a new consent record: source reconnect, newer at', wp_json_encode( $c1 ) );
$insp = gs108p_inspect( $iid );
gs108_assert( $mid === (int) ( $insp['membership_id'] ?? 0 ), 'health-reconnect-same-record', 'the SAME hub membership ' . $mid . ' is reconnected', wp_json_encode( $insp ) );
gs108_assert( $token !== (string) get_option( 'gend_society_install_token', '' ) && $iid === (string) get_option( 'gend_society_install_id', '' ), 'health-reconnect-token', 'new token, same install id' );
gs108_assert( null === gs108c_raw( 'gend_society_connection_health' ), 'health-reconnect-cleared', 'connect_store cleared the health status' );
$x = $gs108h_ajax( $admin, 'gend_society_connection_health_ajax', array( 'nonce' => $nonce() ) );
gs108_assert( array( 'status' => 'ok' ) === $x['json'] && 'ok' === gend_society_connection_health_get()['status'], 'health-reconnect-ok', 'next check: 200 -> ok', $x['body'] );
wp_set_current_user( $admin );
ob_start();
gend_society_connection_health_render_notice();
gs108_assert( '' === ob_get_clean(), 'health-reconnect-no-notice', 'notice gone' );
$card = gs108p_render( $admin );
gs108_assert( false === strpos( $card, 'Reconnect to gend.me' ) && false !== strpos( $card, 'Connected to gend.me' ), 'health-reconnect-no-button', 'Connected card without the Reconnect button' );
$html = $page();
gs108_assert( false === strpos( $html, 'gs-connection-health-notice' ), 'health-http-reconnected', 'HTTP admin load: no notice after the reconnect' );

// --- fetchers report their status.
foreach ( array( 'features' => 'gend_society_features_fetch_remote', 'membership' => 'gend_society_remote_membership_fetch' ) as $what => $fn ) {
	update_option( 'gend_society_connection_health', array( 'status' => 'ok', 'code' => 200, 'checked_at' => time() - 50 ), false );
	$off = gs108p_fake_hub( '/' . $what, 401, array( 'code' => 'unauthorized' ) );
	$got = call_user_func( $fn );
	$off();
	$h = gend_society_connection_health_get();
	gs108_assert( null === $got && 'unauthorized' === $h['status'] && 401 === $h['code'] && abs( time() - $h['checked_at'] ) < 30, "health-fetch-{$what}-401", "{$fn}() 401 -> returns null, status unauthorized" );
	$off = gs108p_fake_hub( '/' . $what, 500, array( 'x' => 1 ) );
	$got = call_user_func( $fn );
	$off();
	gs108_assert( null === $got && 'unauthorized' === gend_society_connection_health_get()['status'], "health-fetch-{$what}-500", "{$fn}() 500 -> null, status unchanged" );
	$off = gs108p_fake_hub( '/' . $what, 200, array( 'gs108' => $what ) );
	$got = call_user_func( $fn );
	$off();
	gs108_assert( array( 'gs108' => $what ) === $got && 'ok' === gend_society_connection_health_get()['status'], "health-fetch-{$what}-200", "{$fn}() 200 -> decoded body, status ok" );
}

// --- no cron.
$cron1 = $gs108h_cron_hooks();
$added = array_values( array_diff( $cron1, $cron0 ) );
$bad   = array_values(
	array_filter(
		$cron1,
		function ( $hook ) use ( $added ) {
			// New hooks mentioning health, and any gend-society connection/health hook at all
			// (core's own wp_site_health_scheduled_check predates the run and is not ours).
			return ( in_array( $hook, $added, true ) && false !== stripos( $hook, 'health' ) )
				|| false !== stripos( $hook, 'gend_society_connection' )
				|| ( false !== stripos( $hook, 'gend_society' ) && false !== stripos( $hook, 'health' ) );
		}
	)
);
gs108_assert( array() === $bad, 'health-no-cron', 'no new cron hook containing "health" and none named gend_society_connection* / gend_society*health after a full cycle (new hooks: ' . wp_json_encode( $added ) . ')', wp_json_encode( $bad ) );
