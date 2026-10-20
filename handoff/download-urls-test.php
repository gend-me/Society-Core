<?php
/**
 * RED/GREEN: gend-society download link defaults point at the gend.me store products.
 * Usage: php handoff/download-urls-test.php .   (plugin root; plain PHP 8.1+, no WordPress needed)
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'ABSPATH', '/tmp/' );
$GLOBALS['gdl_filters'] = array();
function apply_filters( $h, $v ) { return isset( $GLOBALS['gdl_filters'][ $h ] ) ? $GLOBALS['gdl_filters'][ $h ]( $v ) : $v; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function add_action() {}
function add_filter() {}
$GLOBALS['gdl_identity'] = array( 'base' => '' );
function gend_society_plan_identity() { return $GLOBALS['gdl_identity']; }
function gend_society_plan_is_hub_url( $u ) { return in_array( untrailingslashit( $u ), array( 'https://gend.me', 'https://hub.example.test' ), true ); }

$root = rtrim( $argv[1] ?? '.', '/' );
require $root . '/inc/theme-download-notice.php';
require $root . '/inc/standalone-menu.php';

$fail = 0;
function check( $name, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) { $fail++; }
	printf( "%s %s\n     got  %s\n     want %s\n", $ok ? 'PASS' : 'FAIL', $name, $got, $want );
}

check( 'theme download default', gend_society_theme_download_url(), 'https://gend.me/grow/downloads/gend-society-theme/' );
check( 'full build default (unpaired)', gend_society_sa_menu_full_build_url(), 'https://gend.me/grow/downloads/digital-society-plugin/' );
$GLOBALS['gdl_identity'] = array( 'base' => 'https://evil.example/' );
check( 'full build ignores a non-hub base', gend_society_sa_menu_full_build_url(), 'https://gend.me/grow/downloads/digital-society-plugin/' );
$GLOBALS['gdl_identity'] = array( 'base' => 'https://hub.example.test/' );
check( 'full build follows the paired hub', gend_society_sa_menu_full_build_url(), 'https://hub.example.test/grow/downloads/digital-society-plugin/' );
$GLOBALS['gdl_filters']['gend_society_full_build_url'] = function () { return 'https://x.test/a/'; };
$GLOBALS['gdl_filters']['gend_society_theme_download_url'] = function () { return 'https://x.test/t/'; };
check( 'full build filter still wins', gend_society_sa_menu_full_build_url(), 'https://x.test/a/' );
check( 'theme filter still wins', gend_society_theme_download_url(), 'https://x.test/t/' );

echo $fail ? "RESULT FAIL ($fail)\n" : "RESULT PASS\n";
exit( $fail ? 1 : 0 );
