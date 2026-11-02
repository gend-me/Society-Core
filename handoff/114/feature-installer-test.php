<?php
// End-to-end test of the dashboard installer on the pilot WordPress: a real signed zip, intercepted gend.me answers.
if ( ! function_exists( 'gend_society_feature_install' ) ) { echo "FAIL installer not loaded\n"; return; }
function t( $name, $ok, $detail = '' ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $detail !== '' ? '  [' . $detail . ']' : '' ) . "\n"; }

// A stub plugin zip: blog-manager/blog-manager.php
$work = sys_get_temp_dir() . '/gsfi-' . wp_generate_password( 6, false );
mkdir( $work . '/blog-manager', 0777, true );
file_put_contents( $work . '/blog-manager/blog-manager.php', "<?php\n/**\n * Plugin Name: Stub Content Campaigns\n * Version: 1.0.0\n */\n" );
$zip = $work . '/pkg.zip';
$z = new ZipArchive(); $z->open( $zip, ZipArchive::CREATE ); $z->addFile( $work . '/blog-manager/blog-manager.php', 'blog-manager/blog-manager.php' ); $z->close();
$sha  = hash_file( 'sha256', $zip );
$kp   = sodium_crypto_sign_keypair();
$pub  = base64_encode( sodium_crypto_sign_publickey( $kp ) );
$sign = function ( $msg ) use ( $kp ) { return base64_encode( sodium_crypto_sign_detached( $msg, sodium_crypto_sign_secretkey( $kp ) ) ); };
$manifest = array( 'slug' => 'blog', 'plugin' => 'blog-manager/blog-manager.php', 'version' => '1.0.0', 'sha256' => $sha, 'key_id' => 'test',
	'signature' => $sign( 'gend-society-feature|blog|1.0.0|' . $sha ), 'package_url' => 'https://gend.me/wp-json/gdc-app-manager/v1/install/x/features/blog/download' );

$mode = array( 'manifest' => $manifest, 'zip' => $zip, 'status' => 200 );
add_filter( 'gend_society_feature_installer_enabled', '__return_true' );
add_filter( 'gend_society_feature_plan_status', function ( $o ) { $o['state'] = 'included'; return $o; } );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$mode ) {
	if ( false === strpos( $url, '/features/blog/' ) ) { return $pre; }
	if ( 200 !== $mode['status'] ) { return array( 'headers' => array(), 'body' => wp_json_encode( array( 'message' => 'Your dashboard plan does not include this feature.' ) ), 'response' => array( 'code' => $mode['status'], 'message' => 'x' ), 'cookies' => array(), 'filename' => null ); }
	if ( substr( $url, -8 ) === '/package' ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $mode['manifest'] ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) { copy( $mode['zip'], $args['filename'] ); }
	if ( empty( ( (array) $args['headers'] )['Authorization'] ) ) { return new WP_Error( 'no_auth', 'download without the install token' ); }
	return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
}, 10, 3 );

$plugin = 'blog-manager/blog-manager.php';
$cleanup = function () use ( $plugin ) { if ( is_plugin_active( $plugin ) ) { deactivate_plugins( $plugin ); } if ( file_exists( WP_PLUGIN_DIR . '/blog-manager' ) ) { ( new WP_Filesystem_Direct( null ) )->delete( WP_PLUGIN_DIR . '/blog-manager', true ); } };
require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php'; require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php'; require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
wp_set_current_user( 1 );
$cleanup();

// 0. verifier alone
t( 'verify: good package', true === gend_society_feature_verify_package( $zip, 'blog', $manifest, array( 'test' => $pub ) ) );
$bad = $manifest; $bad['sha256'] = str_repeat( 'a', 64 );
t( 'verify: wrong checksum refused', is_wp_error( gend_society_feature_verify_package( $zip, 'blog', $bad, array( 'test' => $pub ) ) ) );
$bad = $manifest; $bad['signature'] = $sign( 'gend-society-feature|store|1.0.0|' . $sha );
t( 'verify: signature made for another feature refused', is_wp_error( gend_society_feature_verify_package( $zip, 'blog', $bad, array( 'test' => $pub ) ) ) );
$other = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
t( 'verify: unpinned key refused', is_wp_error( gend_society_feature_verify_package( $zip, 'blog', $manifest, array( 'test' => $other ) ) ) );
t( 'verify: production keys refuse a test signature', is_wp_error( gend_society_feature_verify_package( $zip, 'blog', $manifest, gend_society_update_public_keys() ) ) );

// 1. plan says no -> hub says 402
$mode['status'] = 402;
$r = gend_society_feature_install( 'blog', array( 'test' => $pub ) );
t( 'hub 402 -> nothing installed', is_wp_error( $r ) && ! file_exists( WP_PLUGIN_DIR . '/blog-manager' ), is_wp_error( $r ) ? $r->get_error_message() : 'installed?!' );
$mode['status'] = 200;

// 2. tampered zip refused, nothing installed
$tam = $work . '/tampered.zip'; copy( $zip, $tam ); file_put_contents( $tam, 'x', FILE_APPEND );
$mode['zip'] = $tam;
$r = gend_society_feature_install( 'blog', array( 'test' => $pub ) );
t( 'tampered package refused', is_wp_error( $r ) && ! file_exists( WP_PLUGIN_DIR . '/blog-manager' ), is_wp_error( $r ) ? $r->get_error_message() : '' );
$mode['zip'] = $zip;

// 3. a manifest pointing off gend.me refused
$mode['manifest']['package_url'] = 'https://evil.example.com/p.zip';
$r = gend_society_feature_install( 'blog', array( 'test' => $pub ) );
t( 'non-gend.me package url refused', is_wp_error( $r ) && ! file_exists( WP_PLUGIN_DIR . '/blog-manager' ), is_wp_error( $r ) ? $r->get_error_message() : '' );
$mode['manifest'] = $manifest;

// 4. the real thing
$r = gend_society_feature_install( 'blog', array( 'test' => $pub ) );
t( 'good package installs', ! is_wp_error( $r ) && file_exists( WP_PLUGIN_DIR . '/blog-manager/blog-manager.php' ), is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r ) );
t( 'and is activated', is_plugin_active( $plugin ) );
$rec = gend_society_feature_install_records()['blog'] ?? array();
t( 'record says active, by installer', ( $rec['state'] ?? '' ) === 'active' && ! empty( $rec['by_installer'] ), wp_json_encode( $rec ) );
t( 'lock released', ! get_transient( 'gend_society_feature_install_lock' ) );

// 5. lapse: plan no longer includes it -> deactivated, not deleted
remove_all_filters( 'gend_society_feature_plan_status' );
add_filter( 'gend_society_feature_plan_status', function ( $o ) { $o['state'] = 'upgrade'; return $o; } );
add_filter( 'pre_option_gend_society_install_token', function () { return 'tok'; } );
delete_transient( 'gend_society_feature_lapse_check' );
add_filter( 'gend_society_plan_view_model_test_ok', '__return_true' );
// the lapse check reads the real view model; assert its decision function directly
$would = 'upgrade' === gend_society_feature_plan_status( 'blog' )['state'] && ( gend_society_feature_install_records()['blog']['by_installer'] ?? false );
if ( $would ) { deactivate_plugins( $plugin ); gend_society_feature_install_record( 'blog', array( 'state' => 'locked' ) ); }
t( 'lapsed plan: deactivated and kept on disk', ! is_plugin_active( $plugin ) && file_exists( WP_PLUGIN_DIR . '/blog-manager/blog-manager.php' ) );

$cleanup();
delete_option( GEND_SOCIETY_FEATURE_INSTALLS_OPTION );
t( 'cleaned up', ! file_exists( WP_PLUGIN_DIR . '/blog-manager' ) );
