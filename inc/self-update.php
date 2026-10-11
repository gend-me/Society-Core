<?php
/**
 * Signed self-updates from updates.gend.me.
 *
 * Full build, standalone only; hub/container are image-managed. Loaded only
 * via the manifest (tier updater, needs standalone). The wordpress.org build
 * never ships this file.
 *
 * How it works:
 * - The plugin header names updates.gend.me as its update host, so core asks
 *   the `update_plugins_updates.gend.me` filter for an offer. The offer comes
 *   from a small JSON manifest, cached in the site option
 *   `gend_society_update_cache` (12 h, 1 h back-off after a failure). No
 *   transients: gend.me runs an external object cache that can evict them.
 * - Nothing is requested before gend_society_remote_allowed( 'updates' ) is
 *   true (consent on standalone).
 * - Before core unpacks a package, `upgrader_pre_download` downloads it and
 *   checks the sha256 and the Ed25519 signature over
 *   "gend-society|<version>|<sha256>" against the keys pinned below. Any
 *   mismatch aborts the update and deletes the download.
 *
 * Sources: wordpress-develop src/wp-includes/update.php (Update URI loop),
 * src/wp-admin/includes/class-wp-upgrader.php (upgrader_pre_download,
 * upgrader_source_selection).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_UPDATE_FILE' ) ) {
	define( 'GEND_SOCIETY_UPDATE_FILE', 'gend-society/gend-society.php' );
}
if ( ! defined( 'GEND_SOCIETY_UPDATE_MANIFEST' ) ) {
	define( 'GEND_SOCIETY_UPDATE_MANIFEST', 'https://updates.gend.me/gend-society/gend-society.json' );
}
if ( ! defined( 'GEND_SOCIETY_UPDATE_PREFIX' ) ) {
	define( 'GEND_SOCIETY_UPDATE_PREFIX', 'https://updates.gend.me/gend-society/' );
}

if ( ! function_exists( 'gend_society_update_public_keys' ) ) {
	/**
	 * Pinned Ed25519 public keys (base64 of the raw 32 bytes), by key id.
	 *
	 * Deliberately not filterable and not overridable by a constant: whoever
	 * could change this list could sign their own packages. The private
	 * halves live only in the Society-Core Actions secrets
	 * GEND_UPDATE_SIGNING_KEY (gs-2026a) and GEND_UPDATE_SIGNING_KEY_BACKUP
	 * (gs-2026b). bin/sign-release.js reads this layout (one key per line).
	 *
	 * @return array<string,string>
	 */
	function gend_society_update_public_keys() {
		return array(
			'gs-2026a' => '6F/D+8itgJ+LPaP9GVWQHVLtnjYkY66kdx43IpSKmZA=', // active
			'gs-2026b' => '9enPHQi5acjA0A9O8eVt4/79gITSosfVI/j5CgJgCEs=', // backup
		);
	}
}

if ( ! function_exists( 'gend_society_update_manifest_valid' ) ) {
	/**
	 * Shape check for a manifest fetched from updates.gend.me.
	 *
	 * @param mixed $m Decoded JSON.
	 * @return bool
	 */
	function gend_society_update_manifest_valid( $m ) {
		return is_array( $m )
			&& isset( $m['version'], $m['package'], $m['sha256'], $m['signature'], $m['key_id'] )
			&& is_string( $m['version'] ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $m['version'] )
			&& is_string( $m['package'] ) && 0 === strpos( $m['package'], GEND_SOCIETY_UPDATE_PREFIX )
			&& is_string( $m['sha256'] ) && 1 === preg_match( '/^[0-9a-fA-F]{64}$/', $m['sha256'] )
			&& is_string( $m['signature'] ) && '' !== $m['signature']
			&& is_string( $m['key_id'] ) && '' !== $m['key_id'];
	}
}

if ( ! function_exists( 'gend_society_update_verify_package' ) ) {
	/**
	 * Verify a downloaded package against a signed manifest. Pure: no I/O
	 * besides hashing the file, no side effects (never deletes the file).
	 *
	 * @param string               $file     Local path of the downloaded zip.
	 * @param array                $manifest Manifest (version, sha256, signature, key_id).
	 * @param array<string,string> $keys     Pinned public keys by key id (base64).
	 * @return true|WP_Error
	 */
	function gend_society_update_verify_package( string $file, array $manifest, array $keys ) {
		foreach ( array( 'version', 'sha256', 'signature', 'key_id' ) as $field ) {
			if ( empty( $manifest[ $field ] ) || ! is_string( $manifest[ $field ] ) ) {
				return new WP_Error( 'gend_society_update_manifest', __( 'GenD Society update refused: the update manifest is incomplete.', 'gend-society' ) );
			}
		}
		$expected = strtolower( $manifest['sha256'] );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $expected ) ) {
			return new WP_Error( 'gend_society_update_manifest', __( 'GenD Society update refused: the update manifest is incomplete.', 'gend-society' ) );
		}

		$actual = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return new WP_Error( 'gend_society_update_sha256', __( 'GenD Society update refused: checksum mismatch.', 'gend-society' ) );
		}

		/*
		 * base64_decode() here only decodes the pinned public key and the
		 * detached signature from the manifest (binary values carried as
		 * text). Strict mode rejects anything that is not clean base64; the
		 * lengths are checked before use. Nothing decoded is executed.
		 */
		$key_id = $manifest['key_id'];
		$pk     = isset( $keys[ $key_id ] ) && is_string( $keys[ $key_id ] ) ? base64_decode( $keys[ $key_id ], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the pinned Ed25519 public key.
		if ( ! is_string( $pk ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pk ) ) {
			return new WP_Error( 'gend_society_update_key', __( 'GenD Society update refused: unknown signing key.', 'gend-society' ) );
		}

		$sig = base64_decode( $manifest['signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the detached Ed25519 signature.
		if ( ! is_string( $sig ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return new WP_Error( 'gend_society_update_signature', __( 'GenD Society update refused: bad signature.', 'gend-society' ) );
		}

		$message = 'gend-society|' . $manifest['version'] . '|' . $expected;
		try {
			$ok = sodium_crypto_sign_verify_detached( $sig, $message, $pk );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( true !== $ok ) {
			return new WP_Error( 'gend_society_update_signature', __( 'GenD Society update refused: bad signature.', 'gend-society' ) );
		}

		return true;
	}
}

if ( ! function_exists( 'gend_society_update_cache_save' ) ) {
	/**
	 * Store the update cache without autoloading it.
	 *
	 * Site options are not autoloaded on multisite. On a single site the
	 * first write uses add_option( ..., 'no' ); later writes keep that flag.
	 *
	 * @param array $value Cache record {checked_at, manifest, failed_at}.
	 */
	function gend_society_update_cache_save( array $value ) {
		if ( ! is_multisite() && false === get_option( 'gend_society_update_cache', false ) ) {
			add_option( 'gend_society_update_cache', $value, '', 'no' );
			return;
		}
		update_site_option( 'gend_society_update_cache', $value );
	}
}

if ( ! function_exists( 'gend_society_update_manifest' ) ) {
	/**
	 * The current update manifest from updates.gend.me, cached.
	 *
	 * @param bool $fresh Skip the cache and the back-off.
	 * @return array|null Validated manifest, or null (no consent / nothing known).
	 */
	function gend_society_update_manifest( bool $fresh = false ) {
		if ( ! gend_society_remote_allowed( 'updates' ) ) {
			return null;
		}

		$cache = get_site_option( 'gend_society_update_cache', array() );
		$cache = is_array( $cache ) ? $cache : array();
		$known = isset( $cache['manifest'] ) && gend_society_update_manifest_valid( $cache['manifest'] ) ? $cache['manifest'] : null;
		$now   = time();

		$bypass = $fresh || ( defined( 'GEND_UPDATE_BYPASS_CACHE' ) && GEND_UPDATE_BYPASS_CACHE );
		if ( ! $bypass ) {
			if ( $known && $now - (int) ( $cache['checked_at'] ?? 0 ) < 12 * HOUR_IN_SECONDS ) {
				return $known;
			}
			if ( ! empty( $cache['failed_at'] ) && $now - (int) $cache['failed_at'] < HOUR_IN_SECONDS ) {
				return $known;
			}
		}

		$response = wp_remote_get(
			GEND_SOCIETY_UPDATE_MANIFEST,
			array(
				'timeout'     => 10,
				'redirection' => 0,
			)
		);
		$manifest = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$manifest = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		}

		if ( ! gend_society_update_manifest_valid( $manifest ) ) {
			$cache['failed_at'] = $now;
			gend_society_update_cache_save( $cache );
			return $known;
		}

		gend_society_update_cache_save(
			array(
				'checked_at' => $now,
				'manifest'   => $manifest,
				'failed_at'  => 0,
			)
		);
		return $manifest;
	}
}

if ( ! function_exists( 'gend_society_update_tested' ) ) {
	/**
	 * "Tested up to" as core compares it. The manifest carries the readme value
	 * (major.minor, e.g. 7.1); core compares it with the full running version
	 * (7.1.3) and would flag every point release as "not tested". wordpress.org
	 * reports the latest point release of the tested branch, so do the same
	 * for the running version when it is on that branch.
	 *
	 * @param mixed $tested Manifest value.
	 * @return string
	 */
	function gend_society_update_tested( $tested ) {
		$tested  = is_string( $tested ) ? $tested : '';
		$running = isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? preg_replace( '/-.*$/', '', $GLOBALS['wp_version'] ) : '';
		if ( 1 === preg_match( '/^\d+\.\d+$/', $tested ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $running ) && 0 === strpos( $running, $tested . '.' ) ) {
			return $running;
		}
		return $tested;
	}
}

if ( ! function_exists( 'gend_society_update_offer' ) ) {
	/**
	 * Filter `update_plugins_updates.gend.me`: the update offer for this plugin.
	 * Core compares versions itself.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	function gend_society_update_offer( $update, $plugin_data, $plugin_file ) {
		if ( GEND_SOCIETY_UPDATE_FILE !== $plugin_file ) {
			return $update;
		}
		$m = gend_society_update_manifest();
		if ( ! $m ) {
			return $update;
		}
		return array(
			'slug'         => 'gend-society',
			'version'      => $m['version'],
			'url'          => isset( $m['homepage'] ) && is_string( $m['homepage'] ) ? $m['homepage'] : 'https://gend.me',
			'package'      => $m['package'],
			'tested'       => gend_society_update_tested( $m['tested'] ?? '' ),
			'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '',
		);
	}
}

if ( ! function_exists( 'gend_society_update_plugins_api' ) ) {
	/**
	 * Filter `plugins_api`: "View details" for gend-society. Core sanitises
	 * the sections with wp_kses.
	 *
	 * @param false|object|array $res    Result so far.
	 * @param string             $action API action.
	 * @param object             $args   API arguments.
	 * @return false|object|array
	 */
	function gend_society_update_plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || 'gend-society' !== $args->slug ) {
			return $res;
		}
		$m = gend_society_update_manifest();
		if ( ! $m ) {
			return $res;
		}
		$sections = isset( $m['sections'] ) && is_array( $m['sections'] ) ? $m['sections'] : array();
		return (object) array(
			'name'          => 'GenD Society',
			'slug'          => 'gend-society',
			'version'       => $m['version'],
			'requires'      => isset( $m['requires'] ) ? (string) $m['requires'] : '',
			'tested'        => gend_society_update_tested( $m['tested'] ?? '' ),
			'requires_php'  => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '',
			'last_updated'  => isset( $m['last_updated'] ) ? (string) $m['last_updated'] : '',
			'homepage'      => isset( $m['homepage'] ) ? (string) $m['homepage'] : 'https://gend.me',
			'download_link' => $m['package'],
			'sections'      => array_map( 'strval', $sections ),
		);
	}
}

if ( ! function_exists( 'gend_society_update_pre_download' ) ) {
	/**
	 * Filter `upgrader_pre_download`: download and verify our package before
	 * core unpacks it. Returns the verified local file, or a WP_Error.
	 *
	 * @param false|string|WP_Error $reply      Result so far.
	 * @param string                $package    Package URL.
	 * @param object                $upgrader   WP_Upgrader instance.
	 * @param array                 $hook_extra Extra arguments.
	 * @return false|string|WP_Error
	 */
	function gend_society_update_pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$ours = ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && GEND_SOCIETY_UPDATE_FILE === $hook_extra['plugin'] )
			|| ( is_string( $package ) && 0 === strpos( $package, GEND_SOCIETY_UPDATE_PREFIX ) );
		if ( false !== $reply || ! $ours ) {
			return $reply;
		}

		$m = gend_society_update_manifest();
		if ( ! $m || $m['package'] !== $package ) {
			$m = gend_society_update_manifest( true );
		}
		if ( ! $m || ! is_string( $package ) || $m['package'] !== $package || 0 !== strpos( $package, GEND_SOCIETY_UPDATE_PREFIX ) ) {
			return new WP_Error( 'gend_society_update_manifest', __( 'GenD Society update refused: no matching signed manifest.', 'gend-society' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$verified = gend_society_update_verify_package( $tmp, $m, gend_society_update_public_keys() );
		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $tmp );
			return $verified;
		}
		return $tmp;
	}
}

if ( ! function_exists( 'gend_society_update_source_selection' ) ) {
	/**
	 * Filter `upgrader_source_selection`: keep the folder name gend-society.
	 * Safety net; release zips are already rooted at gend-society/.
	 *
	 * @param string|WP_Error $source        Unpacked source folder.
	 * @param string          $remote_source Working folder.
	 * @param object          $upgrader      WP_Upgrader instance.
	 * @param array           $hook_extra    Extra arguments.
	 * @return string|WP_Error
	 */
	function gend_society_update_source_selection( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || GEND_SOCIETY_UPDATE_FILE !== $hook_extra['plugin'] ) {
			return $source;
		}
		if ( 'gend-society' === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}
		global $wp_filesystem;
		$target = trailingslashit( $remote_source ) . 'gend-society/';
		if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}
		return new WP_Error( 'gend_society_update_folder', __( 'GenD Society update refused: could not keep the gend-society folder name.', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_update_consent_notice' ) ) {
	/**
	 * Notice on Plugins and Dashboard > Updates while gend.me connections are
	 * not allowed yet: without consent no update check runs, so say why no
	 * update is offered. No HTTP request, no option write.
	 */
	function gend_society_update_consent_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network', 'update-core', 'update-core-network' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) || gend_society_remote_allowed( 'updates' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'GenD Society checks gend.me for signed updates only after you allow gend.me connections.', 'gend-society' );
		if ( function_exists( 'gend_society_connect_url' ) ) {
			echo ' <a href="' . esc_url( gend_society_connect_url() ) . '">' . esc_html__( 'Connect to gend.me', 'gend-society' ) . '</a>';
		}
		echo '</p></div>';
	}
}

add_action( 'admin_notices', 'gend_society_update_consent_notice' );
add_action( 'network_admin_notices', 'gend_society_update_consent_notice' );
add_filter( 'update_plugins_updates.gend.me', 'gend_society_update_offer', 10, 3 );
add_filter( 'plugins_api', 'gend_society_update_plugins_api', 10, 3 );
add_filter( 'upgrader_pre_download', 'gend_society_update_pre_download', 10, 4 );
add_filter( 'upgrader_source_selection', 'gend_society_update_source_selection', 10, 4 );
