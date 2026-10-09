<?php
/**
 * Dump inc/bootstrap/manifest.php as JSON for bin/build-dist.js (plain PHP CLI, no WordPress).
 *
 *   php bin/manifest-json.php [plugin-root]
 *
 * Prints {"modules":[{file,tier,group?}...],"partials":[{file,tier}...]} with every
 * closure ('after') and every other key stripped. Group members carry their group
 * name. Fails (exit 1) when an entry has no file or no tier, so the build never
 * guesses a tier.
 *
 * @package gend-society
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$gend_society_root = isset( $argv[1] ) && '' !== $argv[1] ? rtrim( $argv[1], '/\\' ) : dirname( __DIR__ );

// The manifest only needs ABSPATH for its direct-access guard; its closures are never called.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $gend_society_root . '/' );
}

$gend_society_file = $gend_society_root . '/inc/bootstrap/manifest.php';
if ( ! is_file( $gend_society_file ) ) {
	fwrite( STDERR, "manifest-json: {$gend_society_file} not found\n" );
	exit( 1 );
}

$gend_society_manifest = require $gend_society_file;
if ( ! is_array( $gend_society_manifest ) || ! isset( $gend_society_manifest['modules'], $gend_society_manifest['partials'] ) ) {
	fwrite( STDERR, "manifest-json: manifest.php must return array( 'modules' => [...], 'partials' => [...] )\n" );
	exit( 1 );
}

$gend_society_errors = array();

/**
 * Reduce one manifest entry to {file, tier[, group]}.
 *
 * @param mixed  $entry  Manifest entry.
 * @param string $where  Location for error messages.
 * @param string $group  Group name, or ''.
 * @param array  $errors Collected errors.
 * @return array|null
 */
function gend_society_manifest_json_entry( $entry, $where, $group, array &$errors ) {
	if ( ! is_array( $entry ) || empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
		$errors[] = "{$where}: entry without a 'file' string";
		return null;
	}
	if ( empty( $entry['tier'] ) || ! is_string( $entry['tier'] ) ) {
		$errors[] = "{$entry['file']}: no tier ({$where})";
		return null;
	}
	$out = array(
		'file' => $entry['file'],
		'tier' => $entry['tier'],
	);
	if ( '' !== $group ) {
		$out['group'] = $group;
	}
	return $out;
}

$gend_society_out = array(
	'modules'  => array(),
	'partials' => array(),
);

foreach ( $gend_society_manifest['modules'] as $gend_society_i => $gend_society_entry ) {
	if ( is_array( $gend_society_entry ) && isset( $gend_society_entry['group'] ) ) {
		$gend_society_members = isset( $gend_society_entry['modules'] ) && is_array( $gend_society_entry['modules'] ) ? $gend_society_entry['modules'] : array();
		if ( ! $gend_society_members ) {
			$gend_society_errors[] = "modules[{$gend_society_i}] group '{$gend_society_entry['group']}': no member modules";
		}
		foreach ( $gend_society_members as $gend_society_j => $gend_society_member ) {
			$gend_society_row = gend_society_manifest_json_entry( $gend_society_member, "modules[{$gend_society_i}] group member {$gend_society_j}", (string) $gend_society_entry['group'], $gend_society_errors );
			if ( $gend_society_row ) {
				$gend_society_out['modules'][] = $gend_society_row;
			}
		}
		continue;
	}
	$gend_society_row = gend_society_manifest_json_entry( $gend_society_entry, "modules[{$gend_society_i}]", '', $gend_society_errors );
	if ( $gend_society_row ) {
		$gend_society_out['modules'][] = $gend_society_row;
	}
}

foreach ( $gend_society_manifest['partials'] as $gend_society_i => $gend_society_entry ) {
	$gend_society_row = gend_society_manifest_json_entry( $gend_society_entry, "partials[{$gend_society_i}]", '', $gend_society_errors );
	if ( $gend_society_row ) {
		$gend_society_out['partials'][] = $gend_society_row;
	}
}

if ( $gend_society_errors ) {
	fwrite( STDERR, 'manifest-json: ' . implode( "\nmanifest-json: ", $gend_society_errors ) . "\n" );
	exit( 1 );
}

echo json_encode( $gend_society_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
