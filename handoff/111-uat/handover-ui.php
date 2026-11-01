<?php
/**
 * Phase 111-12 battery: the ORIGIN hand-over after Live (MIG-11, MIG-15, P5).
 *
 *   wp eval-file handover-ui.php <section>[,<section>...]    (C:/tmp/livepull/phase111/tools/sc-uat-111.sh bed handover-ui <sections>)
 *
 * Sections:
 *   health-store         the health check stores the hub's migration block (sanitised); old hubs and status notes change nothing
 *   moved-notice         "This site has moved to <link>", Reconnect suppressed, late injection by the health ajax
 *   dismiss              dismissed per user, shown again when migrated_to changes, hostile values never stored raw
 *   container-noop       migrated_to host equal to this site's host: nothing happens
 *   disconnect-hidden    Disconnect / Reconnect controls absent after Live and the handlers refuse
 *   reporter-stops       before Live the report carries site_url; after Live no HTTP at all
 *   reporter-canonical   a container sends its canonical host (never the Host-keyed home_url()); the origin sends home_url()
 *   cron-park-unpark     WP-Cron parked reversibly (backup, guard, unpark by function / option / CLI / hub rollback); origin data checksum unchanged
 *   cleanup-fired        gend_society_migration_cleanup fires once when the block says cleanup
 *   activation-report    "Store, Social and Projects now active" text for 3, 1 and 0 features; old reports ignored
 *   cards-read-hub       Feature Suite cards read the hub list after Live
 *   migration-not-offered a stale membership cache that still says armed does not offer Start once the stored block says Live
 *
 * gend.me is stubbed through pre_http_request (only the fixture base URL is answered and recorded with its body). Options,
 * cron, user meta and jobs a section touches are restored afterwards. Last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs111ho'] = array( 'pass' => 0, 'fail' => 0, 'cleanup' => array(), 'http' => array() );

class GS111HO_Die extends \Exception {
	public $args_in = array();
}
class GS111HO_Redirect extends \Exception {
	public $location = '';
}

const GS111HO_INSTALL_ID = '7c0a0b1e-1111-4111-8111-111000000012';
const GS111HO_TOKEN      = 'c111c111c111c111c111c111c111c111c111c111c111c111c111c111c111c113';
const GS111HO_BASE       = 'http://localhost:8919';
const GS111HO_TO         = 'https://abc-gs111.gend.test/';
const GS111HO_TO2        = 'https://def-gs111.gend.test/';
const GS111HO_AT         = 1790000000;
const GS111HO_OPTIONS    = array(
	'gend_society_install_id',
	'gend_society_install_token',
	'gend_society_gend_base_url',
	'gend_society_consent',
	'gend_society_remote_membership_cache',
	'gend_society_remote_membership_cache_expires',
	'gend_society_connection_health',
	'gend_society_origin_moved',
	'gend_society_origin_cron_backup',
	'gend_society_origin_cleanup_done',
	'gend_society_canonical_host',
	'gend_society_verified_domains',
	'gend_society_migration_job',
	'gend_society_migration_lock',
	'gend_society_migration_base',
	'gend_society_migration_state_last',
	'cron',
);

function gs111ho_assert( $c, $id, $t = '', $d = '' ) {
	if ( $c ) {
		$GLOBALS['gs111ho']['pass']++;
		echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
	} else {
		$GLOBALS['gs111ho']['fail']++;
		echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . ( '' !== $d ? ' -- ' . $d : '' ) . "\n";
	}
	return (bool) $c;
}
function gs111ho_on_cleanup( callable $fn ) {
	$GLOBALS['gs111ho']['cleanup'][] = $fn;
}
function gs111ho_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs111ho']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo 'INFO: cleanup failed: ' . $e->getMessage() . "\n";
		}
	}
	$GLOBALS['gs111ho']['cleanup'] = array();
	unset( $_REQUEST['gs_portal_connect_nonce'], $_REQUEST['gend_society_disconnect_local_nonce'], $_POST['checked_at'], $_REQUEST['checked_at'] );
	wp_set_current_user( 0 );
}
function gs111ho_wipe_job() {
	$job = get_option( 'gend_society_migration_job', null );
	if ( is_array( $job ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $job );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}
function gs111ho_keep_options() {
	$snap = array();
	foreach ( GS111HO_OPTIONS as $n ) {
		$snap[ $n ] = get_option( $n, '__gs111ho_absent__' );
	}
	$meta = get_user_meta( 1, 'gend_society_health_dismissed', true );
	gs111ho_on_cleanup(
		function () use ( $snap, $meta ) {
			gs111ho_wipe_job();
			foreach ( $snap as $n => $v ) {
				if ( '__gs111ho_absent__' === $v ) {
					delete_option( $n );
				} else {
					update_option( $n, $v, false );
				}
			}
			if ( '' === $meta ) {
				delete_user_meta( 1, 'gend_society_health_dismissed' );
			} else {
				update_user_meta( 1, 'gend_society_health_dismissed', $meta );
			}
		}
	);
}
/** Pair the fixture install and stub the hub: $router( path, method, body ) -> array( code, data ). */
function gs111ho_pair( callable $router = null ) {
	gs111ho_keep_options();
	update_option( 'gend_society_install_id', GS111HO_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS111HO_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS111HO_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs111ho' ), false );
	delete_option( 'gend_society_connection_health' );
	delete_option( 'gend_society_origin_moved' );
	delete_option( 'gend_society_origin_cron_backup' );
	delete_option( 'gend_society_origin_cleanup_done' );
	delete_option( 'gend_society_canonical_host' );
	delete_user_meta( 1, 'gend_society_health_dismissed' );
	$GLOBALS['gs111ho']['http'] = array();
	$f                          = function ( $pre, $args, $url ) use ( $router ) {
		if ( 0 === strpos( $url, GS111HO_BASE . '/' ) ) {
			$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			$body   = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '';
			$path   = preg_replace( '#^.*/install/[^/]+/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$GLOBALS['gs111ho']['http'][] = array( 'url' => $url, 'path' => $path, 'method' => $method, 'body' => $body );
			list( $code, $data ) = $router ? $router( $path, $method, json_decode( $body, true ) ) : array( 404, array( 'message' => 'stub' ) );
			return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $f, 1, 3 );
	gs111ho_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, 1 );
		}
	);
}
function gs111ho_calls( $method = '', $path = '' ) {
	$out = array();
	foreach ( $GLOBALS['gs111ho']['http'] as $r ) {
		if ( ( '' !== $method && $r['method'] !== $method ) || ( '' !== $path && $r['path'] !== $path ) ) {
			continue;
		}
		$out[] = $r;
	}
	return $out;
}
function gs111ho_block( array $over = array() ) {
	return array_merge( array( 'live' => true, 'migrated_to' => GS111HO_TO, 'migrated_at' => GS111HO_AT, 'cleanup' => false ), $over );
}
/** Store a health result with a migration block (the shape gend_society_connection_health_save writes). */
function gs111ho_set_health( $block, $status = 'ok' ) {
	$v = array( 'status' => $status, 'code' => 'ok' === $status ? 200 : 401, 'checked_at' => time() - 10 );
	if ( is_array( $block ) ) {
		$v['migration'] = $block;
	}
	update_option( 'gend_society_connection_health', $v, false );
}
function gs111ho_notice_html() {
	wp_set_current_user( 1 );
	ob_start();
	try {
		gend_society_connection_health_render_notice();
	} finally {
		$html = ob_get_clean();
	}
	return (string) $html;
}
function gs111ho_ajax( callable $fn, $action, $uid, $nonce_action = 'gend_society_connection_health' ) {
	wp_set_current_user( $uid );
	$_REQUEST['nonce'] = wp_create_nonce( $nonce_action );
	$_POST['nonce']    = $_REQUEST['nonce'];
	$dg                = function () {
		return function ( $message, $title = '', $args = array() ) {
			$e = new GS111HO_Die( is_scalar( $message ) ? (string) $message : '' );
			throw $e;
		};
	};
	add_filter( 'wp_die_ajax_handler', $dg );
	add_filter( 'wp_doing_ajax', '__return_true' );
	ob_start();
	try {
		$fn();
	} catch ( GS111HO_Die $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
	} finally {
		$out = ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $dg );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_REQUEST['nonce'], $_POST['nonce'] );
	}
	$body = json_decode( (string) $out, true );
	return is_array( $body ) ? $body : array( 'raw' => $out );
}
/** A checksum of the origin's content: posts, postmeta, comments and the uploads tree. */
function gs111ho_checksum() {
	global $wpdb;
	$parts   = array();
	$parts[] = $wpdb->get_results( "SELECT ID, post_modified_gmt, MD5(post_content) FROM {$wpdb->posts} ORDER BY ID", ARRAY_N ); // phpcs:ignore WordPress.DB
	$parts[] = $wpdb->get_results( "SELECT meta_id, MD5(CONCAT(meta_key, IFNULL(meta_value, ''))) FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_N ); // phpcs:ignore WordPress.DB
	$parts[] = $wpdb->get_results( "SELECT comment_ID, MD5(comment_content) FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_N ); // phpcs:ignore WordPress.DB
	$parts[] = $wpdb->get_results( "SELECT ID, user_login, user_pass FROM {$wpdb->users} ORDER BY ID", ARRAY_N ); // phpcs:ignore WordPress.DB
	$up      = wp_upload_dir();
	$files   = array();
	if ( is_dir( $up['basedir'] ) ) {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $up['basedir'], \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$files[] = $f->getPathname() . ':' . $f->getSize() . ':' . $f->getMTime();
			}
		}
		sort( $files );
	}
	$parts[] = $files;
	return md5( wp_json_encode( $parts ) );
}
function gs111ho_router( array &$st ) {
	return function ( $path, $method, $body ) use ( &$st ) {
		if ( 'health' === $path && 'GET' === $method ) {
			$out = array( 'ok' => true, 'state' => 'connected', 'group' => array( 'name' => 'gs111' ) );
			if ( array_key_exists( 'migration', $st ) ) {
				$out['migration'] = $st['migration'];
			}
			return array( 200, $out );
		}
		if ( 'features-state' === $path && 'POST' === $method ) {
			return array( 200, array( 'ok' => true ) );
		}
		if ( 'disconnect' === $path ) {
			return array( 200, array( 'ok' => true ) );
		}
		return array( 404, array( 'message' => 'stub: ' . $path ) );
	};
}
function gs111ho_features_payload( array $features, $reported_at = 0 ) {
	$p = array( 'membership_id' => 4245, 'features' => $features );
	if ( $reported_at ) {
		$p['features_reported_at'] = $reported_at;
	}
	return $p;
}

// The modules under test load at boot only on a paired standalone install; load them here when the process is unpaired.
foreach ( array( 'connection-health' => 'gend_society_origin_moved', 'portal-connect' => 'gend_society_portal_connect_render_connected', 'feature-state-reporter' => 'gend_society_feature_state_report_now', 'feature-cards' => 'gend_society_render_feature_cards_widget' ) as $gs111ho_mod => $gs111ho_fn ) {
	if ( ! function_exists( $gs111ho_fn ) ) {
		require_once GEND_SOCIETY_DIR . 'inc/' . $gs111ho_mod . '.php';
	}
}
unset( $gs111ho_mod, $gs111ho_fn );

$S = array();

$S['health-store'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	wp_set_current_user( 1 );
	// No block at all (an old hub): nothing stored, nothing parked.
	$cron0 = get_option( 'cron' );
	$r     = gend_society_connection_health_check();
	$raw   = get_option( 'gend_society_connection_health' );
	gs111ho_assert( 'ok' === $r['status'] && ! isset( $raw['migration'] ), 'hs-old-hub', 'a hub without the block: status ok, no migration key stored' );
	gs111ho_assert( false === get_option( 'gend_society_origin_moved', false ) && get_option( 'cron' ) === $cron0, 'hs-old-hub-quiet', 'and nothing is parked' );
	// Not live yet.
	$st['migration'] = array( 'state' => '', 'live' => false, 'migrated_to' => '', 'migrated_at' => 0, 'cleanup' => false );
	gend_society_connection_health_check();
	$m = gend_society_connection_health_migration();
	gs111ho_assert( array( 'live' => false, 'migrated_to' => '', 'migrated_at' => 0, 'cleanup' => false ) === $m, 'hs-not-live', 'the block before Live is stored as is (live false)', wp_json_encode( $m ) );
	gs111ho_assert( ! gend_society_origin_moved()['moved'], 'hs-not-moved', 'and the site is not moved' );
	// Live.
	$st['migration'] = array( 'state' => 'complete', 'live' => true, 'migrated_to' => GS111HO_TO, 'migrated_at' => GS111HO_AT, 'cleanup' => false, 'extra' => '<script>x</script>' );
	gend_society_connection_health_check();
	$raw = get_option( 'gend_society_connection_health' );
	gs111ho_assert( array( 'live' => true, 'migrated_to' => GS111HO_TO, 'migrated_at' => GS111HO_AT, 'cleanup' => false ) === ( $raw['migration'] ?? null ), 'hs-live', 'live block stored with exactly live, migrated_to, migrated_at, cleanup (unknown keys dropped)', wp_json_encode( $raw['migration'] ?? null ) );
	$mv = gend_society_origin_moved();
	gs111ho_assert( true === $mv['moved'] && GS111HO_TO === $mv['to'] && GS111HO_AT === $mv['at'], 'hs-moved', 'gend_society_origin_moved() reports {moved, to, at}', wp_json_encode( $mv ) );
	gs111ho_assert( false !== get_option( 'gend_society_origin_moved', false ), 'hs-parked', 'the check parked WP-Cron (marker option set)' );
	// An old hub answers later without the block: the stored one stays.
	unset( $st['migration'] );
	gend_society_connection_health_check();
	gs111ho_assert( true === gend_society_origin_moved()['moved'], 'hs-old-hub-keeps', 'a later answer without the block keeps the stored one' );
	// A status note from another call keeps it too.
	gend_society_connection_health_note_status( 200 );
	gs111ho_assert( true === gend_society_origin_moved()['moved'], 'hs-note-keeps', 'note_status(200) keeps the block' );
	gend_society_connection_health_note_status( 401 );
	gs111ho_assert( 'unauthorized' === gend_society_connection_health_get()['status'] && true === gend_society_origin_moved()['moved'], 'hs-note-401-keeps', 'note_status(401) keeps it too' );
	// Hostile / odd values.
	$st['migration'] = array( 'live' => true, 'migrated_to' => 'javascript:alert(1)', 'migrated_at' => -5, 'cleanup' => 'yes' );
	gend_society_connection_health_check();
	$m = gend_society_connection_health_migration();
	gs111ho_assert( '' === $m['migrated_to'] && 0 === $m['migrated_at'] && true === $m['cleanup'] && false === gend_society_origin_moved()['moved'], 'hs-hostile', 'a non-http(s) address is dropped: not moved', wp_json_encode( $m ) );
	$st['migration'] = array( 'live' => true, 'migrated_to' => 'abc-gs111.gend.test', 'migrated_at' => GS111HO_AT, 'cleanup' => false );
	gend_society_connection_health_check();
	gs111ho_assert( 'https://abc-gs111.gend.test' === gend_society_connection_health_migration()['migrated_to'], 'hs-bare-host', 'a bare host from the hub becomes an https address' );
	// Hub rollback: live false again.
	$st['migration'] = array( 'live' => false, 'migrated_to' => '', 'migrated_at' => 0, 'cleanup' => false );
	gend_society_connection_health_check();
	gs111ho_assert( false === gend_society_origin_moved()['moved'] && false === get_option( 'gend_society_origin_moved', false ), 'hs-rollback', 'live false again: not moved and unparked' );
};

$S['moved-notice'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	gs111ho_set_health( gs111ho_block(), 'ok' );
	$h = gs111ho_notice_html();
	gs111ho_assert( false !== strpos( $h, 'This site has moved to' ), 'mn-text', 'the notice says This site has moved to' );
	gs111ho_assert( false !== strpos( $h, '<a href="' . GS111HO_TO . '" target="_blank" rel="noopener noreferrer">' . GS111HO_TO . '</a>' ), 'mn-link', 'the container address is a link (new tab, noopener noreferrer)' );
	gs111ho_assert( false !== strpos( $h, 'Your original site has not been changed; to go back, switch your domain back.' ), 'mn-rollback-copy', 'the notice says the original is unchanged and how to go back' );
	gs111ho_assert( false !== strpos( $h, 'notice-info is-dismissible gs-connection-health-notice' ) && false !== strpos( $h, 'data-gs-health-checked="moved:' . GS111HO_TO . '"' ), 'mn-slot', 'same dismissible slot and class as the Reconnect notice, keyed moved:<address>' );
	gs111ho_assert( false !== strpos( $h, 'We will list your active features here once your new site has reported them.' ), 'mn-no-features', 'without a hub list: the waiting sentence' );
	// Reconnect suppressed while moved, even when the token is unrecognised.
	gs111ho_set_health( gs111ho_block(), 'unauthorized' );
	$h = gs111ho_notice_html();
	gs111ho_assert( false !== strpos( $h, 'This site has moved to' ) && false === strpos( $h, 'Reconnect' ) && 1 === substr_count( $h, 'gs-connection-health-notice' ), 'mn-no-reconnect', 'unauthorized + moved: one notice, the moved one, no Reconnect' );
	// Not moved: the Reconnect notice is back (regression).
	gs111ho_set_health( gs111ho_block( array( 'live' => false ) ), 'unauthorized' );
	$h = gs111ho_notice_html();
	gs111ho_assert( false !== strpos( $h, 'Reconnect' ) && false === strpos( $h, 'has moved' ), 'mn-reconnect-regression', 'not moved + unauthorized: the Reconnect notice as before' );
	// Not for non-admins, not when unpaired.
	gs111ho_set_health( gs111ho_block(), 'ok' );
	$u = get_user_by( 'login', 'gs111ho_sub' );
	if ( ! $u ) {
		$id = wp_insert_user( array( 'user_login' => 'gs111ho_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'gs111ho_sub@example.test', 'role' => 'subscriber' ) );
		gs111ho_on_cleanup(
			function () use ( $id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $id );
			}
		);
		$u = get_user_by( 'id', $id );
	}
	wp_set_current_user( $u->ID );
	ob_start();
	gend_society_connection_health_render_notice();
	$sub = ob_get_clean();
	gs111ho_assert( '' === $sub, 'mn-non-admin', 'a subscriber sees nothing' );
	// The health ajax injects it late, and not when it is dismissed.
	$r = gs111ho_ajax( 'gend_society_connection_health_ajax', 'x', 1 );
	gs111ho_assert( true === ( $r['moved'] ?? null ) && false !== strpos( (string) ( $r['notice_html'] ?? '' ), 'This site has moved to' ), 'mn-ajax', 'the health ajax answer carries moved:true and the notice for late injection', wp_json_encode( $r ) );
	$js = (string) file_get_contents( GEND_SOCIETY_DIR . 'assets/gs-connection-health.js' );
	gs111ho_assert( false !== strpos( $js, "r.status === 'unauthorized' || r.moved" ), 'mn-js', 'the injector accepts the moved answer' );
};

$S['dismiss'] = function () {
	$st = array( 'migration' => array( 'live' => true, 'migrated_to' => GS111HO_TO, 'migrated_at' => GS111HO_AT, 'cleanup' => false ) );
	gs111ho_pair( gs111ho_router( $st ) );
	gs111ho_set_health( gs111ho_block(), 'ok' );
	gs111ho_assert( '' !== gs111ho_notice_html(), 'dm-shown', 'shown before dismissal' );
	$_POST['checked_at']    = 'moved:' . GS111HO_TO;
	$_REQUEST['checked_at'] = $_POST['checked_at'];
	$r = gs111ho_ajax( 'gend_society_connection_health_ajax_dismiss', 'x', 1 );
	gs111ho_assert( 'dismissed' === ( $r['status'] ?? '' ), 'dm-ajax', 'the dismiss ajax answers dismissed', wp_json_encode( $r ) );
	gs111ho_assert( 'moved:' . GS111HO_TO === get_user_meta( 1, 'gend_society_health_dismissed', true ), 'dm-meta', 'stored per user as moved:<address>', (string) get_user_meta( 1, 'gend_society_health_dismissed', true ) );
	gs111ho_assert( '' === gs111ho_notice_html(), 'dm-hidden', 'hidden after dismissal' );
	$other = get_user_by( 'login', 'gs111ho_adm2' );
	if ( ! $other ) {
		$id = wp_insert_user( array( 'user_login' => 'gs111ho_adm2', 'user_pass' => wp_generate_password(), 'user_email' => 'gs111ho_adm2@example.test', 'role' => 'administrator' ) );
		gs111ho_on_cleanup(
			function () use ( $id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $id );
			}
		);
		$other = get_user_by( 'id', $id );
	}
	wp_set_current_user( $other->ID );
	ob_start();
	gend_society_connection_health_render_notice();
	$h2 = ob_get_clean();
	gs111ho_assert( false !== strpos( $h2, 'This site has moved to' ), 'dm-per-user', 'another administrator still sees it' );
	// migrated_to changes: shown again for the first user.
	gs111ho_set_health( gs111ho_block( array( 'migrated_to' => GS111HO_TO2 ) ), 'ok' );
	gs111ho_assert( false !== strpos( gs111ho_notice_html(), GS111HO_TO2 ), 'dm-reshown', 'a different migrated_to shows it again' );
	// A dismissed moved notice does not hide a later Reconnect notice (rollback, then a 401).
	gs111ho_set_health( gs111ho_block( array( 'live' => false ) ), 'unauthorized' );
	gs111ho_assert( false !== strpos( gs111ho_notice_html(), 'Reconnect' ), 'dm-reconnect-independent', 'the Reconnect notice is not hidden by an old moved dismissal' );
	// Hostile value.
	$_POST['checked_at']    = 'moved:javascript:alert(1)';
	$_REQUEST['checked_at'] = $_POST['checked_at'];
	gs111ho_ajax( 'gend_society_connection_health_ajax_dismiss', 'x', 1 );
	gs111ho_assert( 'moved:' === get_user_meta( 1, 'gend_society_health_dismissed', true ), 'dm-hostile', 'a non-http(s) value is never stored raw' );
	// The classic dismissal (a number) still works.
	$_POST['checked_at']    = '12345';
	$_REQUEST['checked_at'] = '12345';
	gs111ho_ajax( 'gend_society_connection_health_ajax_dismiss', 'x', 1 );
	gs111ho_assert( '12345' === get_user_meta( 1, 'gend_society_health_dismissed', true ), 'dm-classic', 'the Reconnect dismissal by check time is unchanged' );
};

$S['container-noop'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	$cron0 = get_option( 'cron' );
	$here  = home_url( '/' );
	gs111ho_set_health( gs111ho_block( array( 'migrated_to' => $here ) ), 'ok' );
	gs111ho_assert( false === gend_society_origin_moved()['moved'], 'cn-moved-false', 'migrated_to equal to this site: not moved (this IS the container)' );
	gs111ho_assert( '' === gs111ho_notice_html(), 'cn-no-notice', 'no notice' );
	gs111ho_assert( false === gend_society_origin_park_cron(), 'cn-no-park', 'park_cron refuses' );
	gs111ho_assert( false === get_option( 'gend_society_origin_moved', false ) && get_option( 'cron' ) === $cron0 && false === get_option( 'gend_society_origin_cron_backup', false ), 'cn-cron-untouched', 'cron and options untouched' );
	gs111ho_assert( false === gend_society_origin_cleanup_maybe(), 'cn-no-cleanup', 'no cleanup either' );
	// Case and port differences in the host do not matter.
	$parts = wp_parse_url( home_url() );
	gs111ho_set_health( gs111ho_block( array( 'migrated_to' => strtoupper( (string) $parts['scheme'] ) . '://' . strtoupper( (string) $parts['host'] ) . '/' ) ), 'ok' );
	gs111ho_assert( false === gend_society_origin_moved()['moved'], 'cn-case', 'host comparison ignores case' );
	// The container runtime itself never reads a block.
	$st['migration'] = array( 'live' => true, 'migrated_to' => GS111HO_TO, 'migrated_at' => GS111HO_AT, 'cleanup' => false );
	gs111ho_set_health( gs111ho_block(), 'ok' );
	gs111ho_assert( true === gend_society_origin_moved()['moved'], 'cn-origin-control', 'control: the same block on a different host IS moved' );
};

$S['disconnect-hidden'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	if ( ! gs111ho_assert( function_exists( 'gend_society_portal_connect_render_connected' ) && function_exists( 'gend_society_portal_connect_standalone_disconnect' ), 'dh-loaded', 'the Connect card and handlers are loaded' ) ) {
		return;
	}
	wp_set_current_user( 1 );
	$render = function () {
		ob_start();
		try {
			gend_society_portal_connect_render_connected();
		} finally {
			$h = ob_get_clean();
		}
		return (string) $h;
	};
	// Before Live: Disconnect is there, and Reconnect when unauthorized.
	gs111ho_set_health( null, 'unauthorized' );
	$h = $render();
	gs111ho_assert( false !== strpos( $h, 'name="op" value="disconnect"' ) && false !== strpos( $h, 'Reconnect to gend.me' ), 'dh-before', 'before Live: Disconnect and Reconnect are offered' );
	// After Live.
	gs111ho_set_health( gs111ho_block(), 'unauthorized' );
	$h = $render();
	gs111ho_assert( false === strpos( $h, 'value="disconnect"' ) && false === strpos( $h, 'value="disconnect_local"' ), 'dh-no-disconnect', 'after Live: no Disconnect control' );
	gs111ho_assert( false === strpos( $h, 'Reconnect to gend.me' ) && false === strpos( $h, 'name="reconnect"' ), 'dh-no-reconnect', 'after Live: no Reconnect control' );
	gs111ho_assert( false !== strpos( $h, 'data-gs-moved-connect="1"' ) && false !== strpos( $h, GS111HO_TO ), 'dh-explained', 'the card says why and points at the new site' );
	// The handlers refuse (nonce still checked first).
	$redir = function ( $location ) {
		$e           = new GS111HO_Redirect( 'redirect' );
		$e->location = (string) $location;
		throw $e;
	};
	add_filter( 'wp_redirect', $redir, 1 );
	gs111ho_on_cleanup(
		function () use ( $redir ) {
			remove_filter( 'wp_redirect', $redir, 1 );
		}
	);
	$_REQUEST['gs_portal_connect_nonce'] = wp_create_nonce( 'gs_portal_connect_disconnect' );
	$loc                                 = '';
	try {
		gend_society_portal_connect_standalone_disconnect();
	} catch ( GS111HO_Redirect $e ) {
		$loc = $e->location;
	}
	gs111ho_assert( false !== strpos( rawurldecode( $loc ), 'This site has moved; disconnecting would cancel the new site' ) && false !== strpos( $loc, 'gs_connect_status=error' ), 'dh-refuses', 'the Disconnect handler refuses with the clear message (error status)', $loc );
	gs111ho_assert( ! gs111ho_calls( '', 'disconnect' ) && '' !== (string) get_option( 'gend_society_install_token', '' ), 'dh-nothing-sent', 'no disconnect call went to gend.me and the connection is intact' );
	$_REQUEST['gend_society_disconnect_local_nonce'] = wp_create_nonce( 'gend_society_disconnect_local' );
	$loc                                             = '';
	try {
		gend_society_portal_connect_handle_standalone( 'disconnect_local' );
	} catch ( GS111HO_Redirect $e ) {
		$loc = $e->location;
	}
	gs111ho_assert( false !== strpos( rawurldecode( $loc ), 'This site has moved' ) && '' !== (string) get_option( 'gend_society_install_token', '' ), 'dh-local-refuses', 'Disconnect locally refuses too and keeps the token' );
	// A bad nonce still dies first (the guard is not an auth bypass).
	$_REQUEST['gs_portal_connect_nonce'] = 'bad';
	$died                                = false;
	$dg                                  = function () {
		return function () {
			throw new GS111HO_Die( 'died' );
		};
	};
	add_filter( 'wp_die_handler', $dg );
	try {
		gend_society_portal_connect_standalone_disconnect();
	} catch ( GS111HO_Die $e ) {
		$died = true;
	} catch ( GS111HO_Redirect $e ) {
		$died = false;
	}
	remove_filter( 'wp_die_handler', $dg );
	gs111ho_assert( $died, 'dh-nonce-first', 'a bad nonce is refused before anything else' );
};

$S['reporter-stops'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	gs111ho_set_health( null, 'ok' );
	$ok = gend_society_feature_state_report_now();
	$c  = gs111ho_calls( 'POST', 'features-state' );
	$b  = $c ? json_decode( $c[0]['body'], true ) : array();
	gs111ho_assert( true === $ok && 1 === count( $c ), 'rs-before', 'before Live the report goes out (one POST)' );
	gs111ho_assert( isset( $b['site_url'] ) && home_url() === $b['site_url'] && isset( $b['plugins'] ), 'rs-site-url', 'it carries site_url = home_url() (origin) and the plugin list', wp_json_encode( array_keys( (array) $b ) ) );
	$GLOBALS['gs111ho']['http'] = array();
	gs111ho_set_health( gs111ho_block(), 'ok' );
	$ok = gend_society_feature_state_report_now();
	gs111ho_assert( false === $ok && ! $GLOBALS['gs111ho']['http'], 'rs-after', 'after Live: no report and no HTTP request at all' );
	// Not live (rollback): reports again.
	gs111ho_set_health( gs111ho_block( array( 'live' => false ) ), 'ok' );
	gend_society_feature_state_report_now();
	gs111ho_assert( 1 === count( gs111ho_calls( 'POST', 'features-state' ) ), 'rs-rollback', 'live false again: the reporter resumes' );
};

$S['reporter-canonical'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	gs111ho_set_health( null, 'ok' );
	if ( ! function_exists( 'gend_society_host_urls_sync' ) ) {
		require_once GEND_SOCIETY_DIR . 'inc/container-host-urls.php';
	}
	// A container answering on the customer's own host: the Host-keyed home_url() follows the request host.
	$force = '__return_true';
	add_filter( 'gend_society_feature_state_is_container', $force );
	add_filter( 'gend_society_host_urls_enabled', $force );
	$old_host = $_SERVER['HTTP_HOST'] ?? null;
	gs111ho_on_cleanup(
		function () use ( $force, $old_host ) {
			remove_filter( 'gend_society_feature_state_is_container', $force );
			remove_filter( 'gend_society_host_urls_enabled', $force );
			if ( null === $old_host ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $old_host;
			}
		}
	);
	update_option( 'gend_society_verified_domains', array( 'domains' => array( 'shop-gs111.example.test' ), 'live' => true, 'canonical_host' => 'c1-gs111.gend.test', 'synced_at' => time() ), false );
	$_SERVER['HTTP_HOST'] = 'shop-gs111.example.test';
	gs111ho_assert( 'https://shop-gs111.example.test' === untrailingslashit( home_url() ), 'rc-keyed', 'control: home_url() really is Host-keyed to the custom host here', home_url() );
	update_option( 'gend_society_canonical_host', 'c1-gs111.gend.test', false );
	gs111ho_assert( 'https://c1-gs111.gend.test' === gend_society_feature_state_site_url(), 'rc-canonical', 'the container sends the canonical host from gend_society_canonical_host, not the Host-keyed home_url()', gend_society_feature_state_site_url() );
	gend_society_feature_state_report_now();
	$c = gs111ho_calls( 'POST', 'features-state' );
	$b = $c ? json_decode( $c[0]['body'], true ) : array();
	gs111ho_assert( 'https://c1-gs111.gend.test' === ( $b['site_url'] ?? '' ), 'rc-body', 'the report body carries that canonical site_url' );
	// Another request host: same value.
	$_SERVER['HTTP_HOST'] = 'c1-gs111.gend.test';
	gs111ho_assert( 'https://c1-gs111.gend.test' === gend_society_feature_state_site_url(), 'rc-stable', 'the value does not change with the request host' );
	// No canonical option: the host of the raw stored home option (not the Host-keyed one).
	delete_option( 'gend_society_canonical_host' );
	$_SERVER['HTTP_HOST'] = 'shop-gs111.example.test';
	global $wpdb;
	$raw  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'home' ) ); // phpcs:ignore WordPress.DB
	$want = 'https://' . strtolower( (string) wp_parse_url( $raw, PHP_URL_HOST ) );
	$got  = defined( 'WP_HOME' ) ? 'skip' : gend_society_feature_state_site_url();
	gs111ho_assert( 'skip' === $got || $want === $got, 'rc-fallback', 'without the option: the host of the raw stored home option', $got . ' vs ' . $want );
	gs111ho_assert( 'skip' === $got || false === strpos( $got, 'shop-gs111' ), 'rc-fallback-not-keyed', 'and never the Host-keyed address' );
	// An invalid canonical value is ignored.
	update_option( 'gend_society_canonical_host', 'bad host/../x', false );
	gs111ho_assert( 'skip' === $got || false === strpos( gend_society_feature_state_site_url(), 'bad' ), 'rc-invalid', 'an invalid canonical value is ignored' );
	// The origin sends home_url().
	remove_filter( 'gend_society_feature_state_is_container', $force );
	gs111ho_assert( home_url() === gend_society_feature_state_site_url(), 'rc-origin', 'the origin sends home_url()', gend_society_feature_state_site_url() );
};

$S['cron-park-unpark'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	wp_set_current_user( 1 );
	wp_schedule_single_event( time() + 7200, 'gs111ho_test_hook' );
	gs111ho_on_cleanup(
		function () {
			wp_clear_scheduled_hook( 'gs111ho_test_hook' );
			wp_clear_scheduled_hook( 'gs111ho_other_hook' );
		}
	);
	$orig  = get_option( 'cron' );
	$check = gs111ho_checksum();
	gs111ho_assert( false !== wp_next_scheduled( 'gs111ho_test_hook' ), 'cp-setup', 'a test event is scheduled' );
	// Not moved: park does nothing.
	gs111ho_assert( false === gend_society_origin_park_cron(), 'cp-not-moved', 'park_cron does nothing before Live' );
	gs111ho_set_health( gs111ho_block(), 'ok' );
	gs111ho_assert( true === gend_society_origin_park_cron(), 'cp-parks', 'park_cron parks after Live' );
	gs111ho_assert( get_option( 'gend_society_origin_cron_backup' ) === $orig, 'cp-backup', 'the backup equals the original cron option' );
	gs111ho_assert( array( 'version' => 2 ) === get_option( 'cron' ), 'cp-parked', 'the cron option is now array(version => 2)' );
	gs111ho_assert( GS111HO_TO === get_option( 'gend_society_origin_moved' ), 'cp-marker', 'option gend_society_origin_moved holds the container address' );
	gs111ho_assert( false === wp_next_scheduled( 'gs111ho_test_hook' ), 'cp-no-events', 'no event is due any more' );
	$res = wp_schedule_single_event( time() + 60, 'gs111ho_other_hook' );
	gs111ho_assert( false === $res && false === wp_next_scheduled( 'gs111ho_other_hook' ), 'cp-guard', 'a plugin cannot schedule a new event while parked (pre_schedule_event guard)' );
	$res = wp_schedule_event( time() + 60, 'hourly', 'gs111ho_other_hook' );
	gs111ho_assert( false === $res && array( 'version' => 2 ) === get_option( 'cron' ), 'cp-guard-recurring', 'nor a recurring one; the cron option stays parked' );
	// A request that raced the park writes an event back into the cron option: the next request empties it again, the backup is untouched.
	update_option( 'cron', array( time() + 600 => array( 'gs111ho_other_hook' => array( md5( 'x' ) => array( 'schedule' => false, 'args' => array() ) ) ), 'version' => 2 ) );
	gend_society_origin_cron_reconcile();
	gs111ho_assert( array( 'version' => 2 ) === get_option( 'cron' ) && get_option( 'gend_society_origin_cron_backup' ) === $orig, 'cp-heal', 'an event written back into the cron option after the park is removed on the next request; the backup is still the original' );
	// Idempotent: a second park never overwrites the backup with the parked value.
	gs111ho_assert( false === gend_society_origin_park_cron(), 'cp-idempotent', 'parking again does nothing' );
	gs111ho_assert( get_option( 'gend_society_origin_cron_backup' ) === $orig, 'cp-backup-kept', 'and the backup is still the ORIGINAL schedule' );
	// The notice and the handover change nothing of the origin's data.
	gs111ho_notice_html();
	gend_society_origin_handover( gs111ho_block() );
	gs111ho_assert( gs111ho_checksum() === $check, 'cp-data-unchanged-parked', 'posts, postmeta, comments, users and uploads are unchanged while parked and after the notice' );
	// Unpark by function.
	gs111ho_assert( true === gend_society_origin_unpark_cron(), 'cp-unpark', 'unpark restores' );
	gs111ho_assert( get_option( 'cron' ) === $orig && false === get_option( 'gend_society_origin_cron_backup', false ) && false === get_option( 'gend_society_origin_moved', false ), 'cp-unparked', 'cron equals the original again; both options are gone' );
	gs111ho_assert( false !== wp_next_scheduled( 'gs111ho_test_hook' ), 'cp-event-back', 'the original event is scheduled again' );
	$res = wp_schedule_single_event( time() + 60, 'gs111ho_other_hook' );
	gs111ho_assert( true === $res, 'cp-guard-off', 'the guard is off: scheduling works again' );
	wp_clear_scheduled_hook( 'gs111ho_other_hook' );
	gs111ho_assert( false === gend_society_origin_unpark_cron(), 'cp-unpark-idempotent', 'unpark when not parked is a no-op' );
	// Unpark by deleting the marker option.
	$orig = get_option( 'cron' );
	gend_society_origin_park_cron();
	gs111ho_assert( array( 'version' => 2 ) === get_option( 'cron' ), 'cp-reparked', 'parked again' );
	delete_option( 'gend_society_origin_moved' );
	gend_society_origin_cron_reconcile();
	gs111ho_assert( get_option( 'cron' ) === $orig && false === get_option( 'gend_society_origin_cron_backup', false ), 'cp-by-option', 'deleting gend_society_origin_moved unparks (restored on the next load)' );
	gs111ho_assert( has_action( 'init', 'gend_society_origin_cron_reconcile' ) !== false, 'cp-reconcile-hook', 'the reconcile runs on init' );
	// Unpark by the WP-CLI command.
	gend_society_origin_park_cron();
	gs111ho_assert( function_exists( 'gend_society_origin_cron_cli' ), 'cp-cli-exists', 'the CLI handler exists' );
	if ( function_exists( 'gend_society_origin_cron_cli' ) && class_exists( '\WP_CLI' ) ) {
		ob_start();
		gend_society_origin_cron_cli( array( 'origin-cron', 'status' ) );
		gend_society_origin_cron_cli( array( 'origin-cron', 'unpark' ) );
		ob_end_clean();
		gs111ho_assert( get_option( 'cron' ) === $orig && false === get_option( 'gend_society_origin_moved', false ), 'cp-by-cli', 'wp gend-society origin-cron unpark restores' );
	}
	// Unpark by a hub rollback (a health answer with live false).
	gend_society_origin_park_cron();
	$st['migration'] = array( 'live' => false, 'migrated_to' => '', 'migrated_at' => 0, 'cleanup' => false );
	gend_society_connection_health_check();
	gs111ho_assert( get_option( 'cron' ) === $orig && false === get_option( 'gend_society_origin_moved', false ), 'cp-by-rollback', 'a later health block with live false unparks automatically' );
	gs111ho_assert( gs111ho_checksum() === $check, 'cp-data-unchanged', 'origin data checksum identical after park, notice, unpark and rollback' );
};

$S['cleanup-fired'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	$fired    = 0;
	$listener = function () use ( &$fired ) {
		$fired++;
	};
	add_action( 'gend_society_migration_cleanup', $listener, 1 );
	gs111ho_on_cleanup(
		function () use ( $listener ) {
			remove_action( 'gend_society_migration_cleanup', $listener, 1 );
		}
	);
	// Not asked: nothing.
	gs111ho_set_health( gs111ho_block( array( 'cleanup' => false ) ), 'ok' );
	gs111ho_assert( false === gend_society_origin_cleanup_maybe() && 0 === $fired, 'cf-not-asked', 'cleanup false: nothing fires' );
	// Asked but not moved.
	gs111ho_set_health( gs111ho_block( array( 'live' => false, 'cleanup' => true ) ), 'ok' );
	gs111ho_assert( false === gend_society_origin_cleanup_maybe() && 0 === $fired, 'cf-not-live', 'cleanup true but not live: nothing fires' );
	// A staged export job exists (a full receipt), then the hub asks for cleanup.
	gs111ho_wipe_job();
	delete_option( 'gend_society_migration_base' );
	gend_society_migration_receive( array( 'job_id' => '0e111e12-aaaa-4bbb-8ccc-dddddddddd21', 'export_secret' => str_repeat( 'a', 64 ) ) );
	$job = get_option( 'gend_society_migration_job', null );
	$dir = is_array( $job ) ? (string) ( gend_society_migration_export_job()['staging'] ?? '' ) : '';
	gs111ho_assert( '' !== $dir && is_dir( $dir ), 'cf-staged', 'an export staging folder exists before the cleanup' );
	gs111ho_set_health( gs111ho_block( array( 'cleanup' => true ) ), 'ok' );
	gs111ho_assert( true === gend_society_origin_cleanup_maybe(), 'cf-runs', 'cleanup true after Live: it runs' );
	gs111ho_assert( 1 === $fired, 'cf-fired-once', 'gend_society_migration_cleanup fired once', (string) $fired );
	gs111ho_assert( false === get_option( 'gend_society_migration_job', false ) && ( '' === $dir || ! is_dir( $dir ) ), 'cf-handler', 'the existing handler removed the staging folder and the job option' );
	gs111ho_assert( false !== get_option( 'gend_society_origin_cleanup_done', false ), 'cf-recorded', 'option gend_society_origin_cleanup_done records it' );
	gs111ho_assert( false === gend_society_origin_cleanup_maybe() && 1 === $fired, 'cf-once', 'a second call does not fire again' );
	// Through the health check path as well (fresh install state, block says cleanup).
	delete_option( 'gend_society_origin_cleanup_done' );
	$fired           = 0;
	$st['migration'] = array( 'live' => true, 'migrated_to' => GS111HO_TO, 'migrated_at' => GS111HO_AT, 'cleanup' => true );
	gend_society_connection_health_check();
	gend_society_connection_health_check();
	gs111ho_assert( 1 === $fired, 'cf-via-check', 'the health check path fires it once across two checks', (string) $fired );
	gend_society_origin_unpark_cron();
};

$S['activation-report'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	$three = array( array( 'slug' => 'store', 'label' => 'Store' ), array( 'slug' => 'social', 'label' => 'Social' ), array( 'slug' => 'projects', 'label' => 'Projects' ) );
	$want3 = wp_sprintf_l( '%l', array( 'Store', 'Social', 'Projects' ) ) . ' now active';
	gs111ho_assert( $want3 === gend_society_origin_activation_text( $three ), 'ar-three', 'three features: labels joined in the site language + "now active"', gend_society_origin_activation_text( $three ) );
	gs111ho_assert( false !== strpos( gend_society_origin_activation_text( $three ), 'Store' ) && false !== strpos( gend_society_origin_activation_text( $three ), 'Social' ) && false !== strpos( gend_society_origin_activation_text( $three ), 'Projects' ) && ' now active' === substr( gend_society_origin_activation_text( $three ), -11 ), 'ar-three-parts', 'all three labels and the suffix are in the text' );
	gs111ho_assert( 'Store now active' === gend_society_origin_activation_text( array( $three[0] ) ), 'ar-one', 'one feature: "Store now active"' );
	gs111ho_assert( '' === gend_society_origin_activation_text( array() ), 'ar-none', 'no features: empty text' );
	gs111ho_assert( 'Store now active' === gend_society_origin_activation_text( array( array( 'slug' => 'store', 'label' => '<b>Store</b>' ), array( 'slug' => 'x', 'label' => '' ), array( 'slug' => 'y' ) ) ), 'ar-clean', 'markup is stripped; empty or label-less entries are skipped' );
	// In the notice: from the cached /membership payload only (no request).
	gs111ho_set_health( gs111ho_block(), 'ok' );
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( $three, GS111HO_AT + 60 ), false );
	$GLOBALS['gs111ho']['http'] = array();
	$h                          = gs111ho_notice_html();
	gs111ho_assert( false !== strpos( $h, esc_html( $want3 ) . '.' ) && false === strpos( $h, 'We will list your active features' ), 'ar-notice-three', 'the moved notice carries the activation sentence' );
	gs111ho_assert( ! $GLOBALS['gs111ho']['http'], 'ar-no-request', 'rendering it made no request (cache only)' );
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( array( $three[0] ), GS111HO_AT + 60 ), false );
	gs111ho_assert( false !== strpos( gs111ho_notice_html(), 'Store now active.' ), 'ar-notice-one', 'one feature in the notice' );
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( array(), 0 ), false );
	gs111ho_assert( false !== strpos( gs111ho_notice_html(), 'We will list your active features here once your new site has reported them.' ), 'ar-notice-none', 'none: the waiting sentence' );
	// A report older than migrated_at is the origin's own: ignored.
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( $three, GS111HO_AT - 100 ), false );
	gs111ho_assert( array() === gend_society_origin_hub_features() && false !== strpos( gs111ho_notice_html(), 'We will list your active features' ), 'ar-old-report', 'features reported before migrated_at are ignored' );
	// Not moved: no list.
	gs111ho_set_health( gs111ho_block( array( 'live' => false ) ), 'ok' );
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( $three, GS111HO_AT + 60 ), false );
	gs111ho_assert( array() === gend_society_origin_hub_features(), 'ar-not-moved', 'before Live the origin shows no hub list' );
};

$S['cards-read-hub'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	wp_set_current_user( 1 );
	if ( ! gs111ho_assert( function_exists( 'gend_society_render_feature_cards_widget' ), 'ch-loaded', 'the card renderer is loaded' ) ) {
		return;
	}
	$render = function () {
		ob_start();
		try {
			gend_society_render_feature_cards_widget();
		} finally {
			$h = ob_get_clean();
		}
		return (string) $h;
	};
	$base = $render();
	gs111ho_assert( false === strpos( $base, 'Active on your new site' ), 'ch-before', 'before Live no card says Active on your new site' );
	$act_store = 'data-action="activate" data-plugin="online-store/online-store.php"';
	$act_proj  = 'data-action="activate" data-plugin="projects/project-service-orders.php"';
	$act_soc   = 'data-action="activate" data-plugin="social-network/social-network.php"';
	gs111ho_assert( false !== strpos( $base, $act_store ) && false !== strpos( $base, $act_proj ) && false !== strpos( $base, $act_soc ), 'ch-control', 'control: the Store, Projects and Social cards offer Activate here (not active on this bed)' );
	gs111ho_set_health( gs111ho_block(), 'ok' );
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( array( array( 'slug' => 'store', 'label' => 'Store' ), array( 'slug' => 'social', 'label' => 'Social' ) ), GS111HO_AT + 60 ), false );
	$h = $render();
	gs111ho_assert( 2 === substr_count( $h, 'Active on your new site' ), 'ch-two', 'two cards read Active on your new site', (string) substr_count( $h, 'Active on your new site' ) );
	gs111ho_assert( false === strpos( $h, $act_store ) && false === strpos( $h, $act_soc ), 'ch-no-activate', 'those two have no Activate button' );
	gs111ho_assert( false !== strpos( $h, $act_proj ), 'ch-other-unchanged', 'the Projects card (not in the list) is unchanged: Activate' );
	gs111ho_assert( 2 === substr_count( $h, 'data-gs-fc-new-site="1"' ) && 2 === substr_count( $h, 'href="' . GS111HO_TO . '"' ), 'ch-link', 'each links to the new site' );
	gs111ho_assert( false === strpos( $h, 'data-action="update" data-plugin="online-store/online-store.php"' ) && false === strpos( $h, 'data-action="update" data-plugin="social-network/social-network.php"' ), 'ch-no-update', 'and has no Update button' );
	// Not asserted as Active locally: the label is the hub's, not the local state.
	update_option( 'gend_society_remote_membership_cache', gs111ho_features_payload( array(), 0 ), false );
	gs111ho_assert( false === strpos( $render(), 'Active on your new site' ), 'ch-empty', 'moved but no hub list yet: cards unchanged' );
	// No new card.
	gs111ho_assert( substr_count( $base, 'class="gs-fc"' ) === substr_count( $h, 'class="gs-fc"' ), 'ch-same-count', 'the same number of cards: none added' );
};

$S['migration-not-offered'] = function () {
	$st = array();
	gs111ho_pair( gs111ho_router( $st ) );
	wp_set_current_user( 1 );
	$armed = array(
		'membership_id' => 4246,
		'status'        => 'active',
		'status_label'  => 'Active',
		'app_url'       => home_url( '/' ),
		'hub_url'       => GS111HO_BASE . '/',
		'migration'     => array( 'stage' => 'prepare', 'index' => 1, 'live' => false, 'steps' => array( 'prepare', 'export', 'provision', 'verify', 'live' ), 'state' => 'idle', 'can_start' => true, 'can_retry' => false, 'can_cancel' => true, 'scheduled_at' => 0, 'error' => '', 'preview' => false, 'preview_url' => '', 'migrated_to' => '', 'migrated_at' => 0, 'domain_cutover' => false ),
		'domains'       => array(),
		'cache_seconds' => 60,
	);
	$panel = function () use ( $armed ) {
		ob_start();
		try {
			echo gend_society_render_membership_panel( $armed ); // phpcs:ignore
		} finally {
			$h = ob_get_clean();
		}
		return (string) $h;
	};
	// Control: before Live the stale armed payload offers Start.
	gs111ho_set_health( null, 'ok' );
	gs111ho_assert( 'start' === gend_society_migration_cta_mode( $armed ), 'mo-control-cta', 'control: an armed payload gives the CTA mode start' );
	$h = $panel();
	gs111ho_assert( false !== strpos( $h, 'data-gs-mig-start-step' ) && false !== strpos( $h, 'data-gs-mig-action="start-open"' ), 'mo-control-step', 'control: the header offers the inline Start step' );
	// The stored block says Live; the membership cache is stale (still armed).
	gs111ho_set_health( gs111ho_block(), 'ok' );
	gs111ho_assert( '' === gend_society_migration_cta_mode( $armed ), 'mo-cta-empty', 'gend_society_migration_cta_mode() returns empty', gend_society_migration_cta_mode( $armed ) );
	$h = $panel();
	gs111ho_assert( false === strpos( $h, 'data-gs-mig-start-step' ) && false === strpos( $h, 'data-gs-mig-action="start-open"' ) && false === strpos( $h, 'data-gs-mig-action="start"' ), 'mo-no-start-step', 'no inline Start step and no Start button render' );
	gs111ho_assert( false === strpos( $h, 'data-gs-mig-action="retry"' ) && false === strpos( $h, 'data-gs-mig-action="cancel"' ), 'mo-no-retry-cancel', 'no Retry or Cancel either' );
	gs111ho_assert( false === strpos( $h, 'data-gs-start-inline' ) && false === strpos( $h, 'data-gs-migrate-cta' ), 'mo-no-cta', 'no Start Migration CTA anchor' );
	// A container (host equal) keeps the normal behaviour.
	gs111ho_set_health( gs111ho_block( array( 'migrated_to' => home_url( '/' ) ) ), 'ok' );
	gs111ho_assert( 'start' === gend_society_migration_cta_mode( $armed ), 'mo-container', 'a block pointing at this very host changes nothing' );
};

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs111ho_args = isset( $args ) ? (array) $args : array();
$gs111ho_want = array();
foreach ( $gs111ho_args as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs111ho_want[] = trim( $s );
		}
	}
}
if ( ! $gs111ho_want ) {
	$gs111ho_want = array_keys( $S );
}
echo 'INFO: handover-ui on ' . home_url() . ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?' ) . ' sections=' . implode( ',', $gs111ho_want ) . "\n";
foreach ( $gs111ho_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs111ho_assert( false, $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs111ho_assert( false, $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs111ho_cleanup();
	}
}
$gs111ho_f = (int) $GLOBALS['gs111ho']['fail'];
$gs111ho_p = (int) $GLOBALS['gs111ho']['pass'];
echo ( 0 === $gs111ho_f && $gs111ho_p > 0 ) ? "ALL PASS ({$gs111ho_p})\n" : "FAILURES: {$gs111ho_f}\n";
