<?php
/**
 * GenD Society: install and switch on the dashboards a plan includes (Phase 114).
 *
 * Full build, standalone installs only (manifest tier `updater`: never in the wordpress.org build, never on the hub or a
 * container, which receive their plugins from the image). For every dashboard plugin (Content Campaigns, Talk Flows, Store,
 * Sales, Projects, Social, Contracts, Point Bank) that the dashboard plan includes and that is not on this site:
 *
 *   1. ask gend.me for the signed manifest (the hub refuses with 402 when the plan does not include it);
 *   2. download the zip from gend.me with the install token (a gend.me URL only);
 *   3. verify its SHA-256 and the Ed25519 signature against the keys pinned in inc/self-update.php;
 *   4. install it with WordPress's own Plugin_Upgrader, then activate it.
 *
 * Nothing is installed from anywhere else, nothing unsigned is ever installed, and a plugin is never deleted: when a plan
 * lapses, a plugin this installer put here is only deactivated.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_FEATURE_INSTALLS_OPTION = 'gend_society_feature_installs';

if ( ! function_exists( 'gend_society_feature_installer_enabled' ) ) {
	/**
	 * Is the installer allowed to run on this site? Full build, standalone, connected, not turned off.
	 */
	function gend_society_feature_installer_enabled(): bool {
		$on = function_exists( 'gend_society_dashboard_is_standalone' ) && gend_society_dashboard_is_standalone()
			&& '' !== (string) get_option( 'gend_society_install_token', '' )
			&& function_exists( 'sodium_crypto_sign_verify_detached' );
		/**
		 * Filters whether dashboards included in the plan are installed automatically.
		 *
		 * @param bool $on Default: on for a connected standalone full build.
		 */
		return (bool) apply_filters( 'gend_society_feature_installer_enabled', $on );
	}
}

if ( ! function_exists( 'gend_society_feature_install_records' ) ) {
	/** @return array<string, array{state:string,message:string,at:int,version:string,by_installer:bool,tries:int}> */
	function gend_society_feature_install_records(): array {
		$r = get_option( GEND_SOCIETY_FEATURE_INSTALLS_OPTION, array() );
		return is_array( $r ) ? $r : array();
	}
}

if ( ! function_exists( 'gend_society_feature_install_record' ) ) {
	/**
	 * Merge fields into one feature's record (autoload off).
	 *
	 * @param string $slug   Feature slug.
	 * @param array  $fields Fields to set.
	 */
	function gend_society_feature_install_record( string $slug, array $fields ): void {
		$all          = gend_society_feature_install_records();
		$cur          = isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array( 'state' => '', 'message' => '', 'at' => 0, 'version' => '', 'by_installer' => false, 'tries' => 0 );
		$all[ $slug ] = array_merge( $cur, $fields, array( 'at' => time() ) );
		update_option( GEND_SOCIETY_FEATURE_INSTALLS_OPTION, $all, false );
	}
}

if ( ! function_exists( 'gend_society_feature_verify_package' ) ) {
	/**
	 * Verify a downloaded dashboard package. The signed message is `gend-society-feature|<slug>|<version>|<sha256>`, so a
	 * signature made for one plugin or version can never vouch for another. Pure: hashes the file, deletes nothing.
	 *
	 * @param string               $file     Local zip.
	 * @param string               $slug     Feature slug the caller asked for.
	 * @param array                $manifest {slug, version, sha256, signature, key_id}.
	 * @param array<string,string> $keys     Pinned public keys by id (base64).
	 * @return true|WP_Error
	 */
	function gend_society_feature_verify_package( string $file, string $slug, array $manifest, array $keys ) {
		foreach ( array( 'slug', 'version', 'sha256', 'signature', 'key_id' ) as $f ) {
			if ( empty( $manifest[ $f ] ) || ! is_string( $manifest[ $f ] ) ) {
				return new WP_Error( 'gs_feature_manifest', __( 'The package was refused: its manifest is incomplete.', 'gend-society' ) );
			}
		}
		if ( $manifest['slug'] !== $slug || 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $manifest['version'] ) ) {
			return new WP_Error( 'gs_feature_manifest', __( 'The package was refused: its manifest does not match.', 'gend-society' ) );
		}
		$expected = strtolower( $manifest['sha256'] );
		$actual   = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $expected ) || ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return new WP_Error( 'gs_feature_sha256', __( 'The package was refused: checksum mismatch.', 'gend-society' ) );
		}
		$pk  = isset( $keys[ $manifest['key_id'] ] ) && is_string( $keys[ $manifest['key_id'] ] ) ? base64_decode( $keys[ $manifest['key_id'] ], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the pinned public key.
		$sig = base64_decode( $manifest['signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the detached signature.
		if ( ! is_string( $pk ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pk ) ) {
			return new WP_Error( 'gs_feature_key', __( 'The package was refused: unknown signing key.', 'gend-society' ) );
		}
		if ( ! is_string( $sig ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return new WP_Error( 'gs_feature_signature', __( 'The package was refused: bad signature.', 'gend-society' ) );
		}
		try {
			$ok = sodium_crypto_sign_verify_detached( $sig, 'gend-society-feature|' . $slug . '|' . $manifest['version'] . '|' . $expected, $pk );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		return true === $ok ? true : new WP_Error( 'gs_feature_signature', __( 'The package was refused: bad signature.', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_feature_install' ) ) {
	/**
	 * Install and activate one dashboard the plan includes.
	 *
	 * @param string $slug Feature slug (blog, email, store, sales, projects, social, membership, rewards).
	 * @param array  $keys Pinned keys override for tests only (null = the production keys; never filterable).
	 * @return array{plugin:string,version:string}|WP_Error
	 */
	function gend_society_feature_install( string $slug, ?array $keys = null ) {
		$slug = sanitize_key( $slug );
		$defs = function_exists( 'gend_society_get_feature_definitions' ) ? gend_society_get_feature_definitions() : array();
		if ( ! gend_society_feature_installer_enabled() || ! isset( $defs[ $slug ] ) || 'wireframe' === $slug ) {
			return new WP_Error( 'gs_feature_unavailable', __( 'This dashboard cannot be installed from here.', 'gend-society' ) );
		}
		$plugin = (string) $defs[ $slug ]['plugin'];
		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			return array( 'plugin' => $plugin, 'version' => '' ); // already here: activation is the caller's next step
		}
		// The plan decides; the hub checks it again and refuses with 402 when it does not include the feature.
		$st = function_exists( 'gend_society_feature_plan_status' ) ? gend_society_feature_plan_status( $slug ) : array( 'state' => 'unknown' );
		if ( 'included' !== $st['state'] ) {
			return new WP_Error( 'gs_feature_plan', __( 'Your dashboard plan does not include this dashboard.', 'gend-society' ) );
		}
		if ( get_transient( 'gend_society_feature_install_lock' ) ) {
			return new WP_Error( 'gs_feature_busy', __( 'Another dashboard is being installed. Try again in a minute.', 'gend-society' ) );
		}
		set_transient( 'gend_society_feature_install_lock', $slug, 600 );
		gend_society_feature_install_record( $slug, array( 'state' => 'installing', 'message' => '' ) );
		$tmp    = '';
		$result = ( function () use ( $slug, $plugin, $keys, &$tmp ) {
			$m = gend_society_remote_membership_call( 'features/' . rawurlencode( $slug ) . '/package', array(), 'GET' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			if ( ( $m['plugin'] ?? '' ) !== $plugin || empty( $m['package_url'] ) || ! is_string( $m['package_url'] ) ) {
				return new WP_Error( 'gs_feature_manifest', __( 'The package was refused: it does not match this dashboard.', 'gend-society' ) );
			}
			// Only a gend.me address, with the install token: never a URL a manifest could point somewhere else.
			if ( ! function_exists( 'gend_society_plan_is_hub_url' ) || ! gend_society_plan_is_hub_url( $m['package_url'] ) ) {
				return new WP_Error( 'gs_feature_url', __( 'The package was refused: it is not hosted on gend.me.', 'gend-society' ) );
			}
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$tmp  = wp_tempnam( 'gs-feature-' . $slug );
			$resp = wp_remote_get(
				$m['package_url'],
				array(
					'timeout'  => 600,
					'stream'   => true,
					'filename' => $tmp,
					'headers'  => array( 'Authorization' => 'Bearer ' . (string) get_option( 'gend_society_install_token', '' ) ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new WP_Error( 'gs_feature_download', __( 'gend.me could not deliver the package right now.', 'gend-society' ) );
			}
			$pinned = null === $keys ? ( function_exists( 'gend_society_update_public_keys' ) ? gend_society_update_public_keys() : array() ) : $keys;
			$ok     = gend_society_feature_verify_package( $tmp, $slug, $m, $pinned );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$ins = $up->install( $tmp );
			if ( is_wp_error( $ins ) ) {
				return $ins;
			}
			if ( ! $ins || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				return new WP_Error( 'gs_feature_install', __( 'The package installed but its plugin file was not found.', 'gend-society' ) );
			}
			$act = activate_plugin( $plugin );
			if ( is_wp_error( $act ) ) {
				return $act;
			}
			return array( 'plugin' => $plugin, 'version' => (string) $m['version'] );
		} )();
		if ( '' !== $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		delete_transient( 'gend_society_feature_install_lock' );
		if ( is_wp_error( $result ) ) {
			$all = gend_society_feature_install_records();
			gend_society_feature_install_record( $slug, array( 'state' => 'failed', 'message' => $result->get_error_message(), 'tries' => (int) ( $all[ $slug ]['tries'] ?? 0 ) + 1 ) );
			return $result;
		}
		gend_society_feature_install_record( $slug, array( 'state' => 'active', 'message' => '', 'version' => $result['version'], 'by_installer' => true, 'tries' => 0 ) );
		return $result;
	}
}

if ( ! function_exists( 'gend_society_feature_install_ajax' ) ) {
	/** admin-ajax gend_society_install_feature: the card button. */
	function gend_society_feature_install_ajax(): void {
		check_ajax_referer( 'gs_plugin_actions', 'nonce' );
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to install plugins.', 'gend-society' ) ) );
		}
		$slug = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( (string) $_POST['feature'] ) ) : '';
		$r    = gend_society_feature_install( $slug );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Installed and switched on.', 'gend-society' ) ) );
	}
}
add_action( 'wp_ajax_gend_society_install_feature', 'gend_society_feature_install_ajax' );

if ( ! function_exists( 'gend_society_feature_missing' ) ) {
	/**
	 * Dashboards the plan includes whose plugin is not on this site (or installed by us but switched off).
	 *
	 * @return string[] Feature slugs.
	 */
	function gend_society_feature_missing(): array {
		$out = array();
		if ( ! function_exists( 'gend_society_get_feature_definitions' ) || ! function_exists( 'gend_society_feature_plan_status' ) ) {
			return $out;
		}
		foreach ( gend_society_get_feature_definitions() as $slug => $def ) {
			if ( 'wireframe' === $slug || file_exists( WP_PLUGIN_DIR . '/' . $def['plugin'] ) ) {
				continue;
			}
			if ( 'included' === gend_society_feature_plan_status( (string) $slug )['state'] ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_feature_autoinstall_schedule' ) ) {
	/**
	 * After a plan change the dashboards it includes are queued: checked at most every two minutes when an admin page loads,
	 * then run by WP-Cron one at a time, so a slow download never blocks a page.
	 */
	function gend_society_feature_autoinstall_schedule(): void {
		if ( ! gend_society_feature_installer_enabled() || ! current_user_can( 'manage_options' ) || get_transient( 'gend_society_feature_autoinstall_check' ) ) {
			return;
		}
		set_transient( 'gend_society_feature_autoinstall_check', 1, 120 );
		$records = gend_society_feature_install_records();
		$todo    = array();
		foreach ( gend_society_feature_missing() as $slug ) {
			$tries = (int) ( $records[ $slug ]['tries'] ?? 0 );
			$last  = (int) ( $records[ $slug ]['at'] ?? 0 );
			if ( $tries >= 3 || ( $tries > 0 && time() - $last < 15 * MINUTE_IN_SECONDS ) ) {
				continue; // gave up (the card shows Retry) or waiting before the next attempt
			}
			$todo[] = $slug;
		}
		if ( $todo && ! wp_next_scheduled( 'gend_society_feature_autoinstall' ) ) {
			foreach ( $todo as $slug ) {
				gend_society_feature_install_record( $slug, array( 'state' => 'queued', 'message' => '' ) );
			}
			wp_schedule_single_event( time() + 10, 'gend_society_feature_autoinstall' );
		}
	}
}
add_action( 'admin_init', 'gend_society_feature_autoinstall_schedule' );

if ( ! function_exists( 'gend_society_feature_autoinstall_run' ) ) {
	/** WP-Cron: install what is queued, one dashboard per run (the next run is scheduled if more remain). */
	function gend_society_feature_autoinstall_run(): void {
		if ( ! gend_society_feature_installer_enabled() ) {
			return;
		}
		$records = gend_society_feature_install_records();
		$left    = array();
		foreach ( gend_society_feature_missing() as $slug ) {
			if ( 'queued' === ( $records[ $slug ]['state'] ?? '' ) ) {
				$left[] = $slug;
			}
		}
		if ( ! $left ) {
			return;
		}
		$slug = array_shift( $left );
		$r    = gend_society_feature_install( $slug );
		if ( ! is_wp_error( $r ) ) {
			$names  = gend_society_get_feature_definitions();
			$notice = get_option( 'gend_society_feature_install_notice', array() );
			$notice = is_array( $notice ) ? $notice : array();
			$notice[] = (string) ( $names[ $slug ]['name'] ?? $slug );
			update_option( 'gend_society_feature_install_notice', array_slice( array_unique( $notice ), -8 ), false );
		}
		if ( $left ) {
			wp_schedule_single_event( time() + 20, 'gend_society_feature_autoinstall' );
		}
	}
}
add_action( 'gend_society_feature_autoinstall', 'gend_society_feature_autoinstall_run' );

if ( ! function_exists( 'gend_society_feature_lapse_check' ) ) {
	/**
	 * A plan that no longer includes a dashboard this installer put here: deactivate it (never delete it), only on fresh,
	 * successful plan data. Dashboards installed any other way are left alone.
	 */
	function gend_society_feature_lapse_check(): void {
		if ( ! gend_society_feature_installer_enabled() || ! current_user_can( 'manage_options' ) || get_transient( 'gend_society_feature_lapse_check' ) || ! function_exists( 'gend_society_plan_view_model' ) ) {
			return;
		}
		set_transient( 'gend_society_feature_lapse_check', 1, 10 * MINUTE_IN_SECONDS );
		$vm = gend_society_plan_view_model();
		if ( 'ok' !== ( $vm['state'] ?? '' ) || ! empty( $vm['stale'] ) ) {
			return;
		}
		$defs = gend_society_get_feature_definitions();
		foreach ( gend_society_feature_install_records() as $slug => $rec ) {
			if ( empty( $rec['by_installer'] ) || ! isset( $defs[ $slug ] ) ) {
				continue;
			}
			if ( 'upgrade' === gend_society_feature_plan_status( (string) $slug )['state'] && function_exists( 'gend_society_plugin_active' ) && gend_society_plugin_active( $defs[ $slug ]['plugin'] ) ) {
				deactivate_plugins( $defs[ $slug ]['plugin'] );
				gend_society_feature_install_record( (string) $slug, array( 'state' => 'locked', 'message' => __( 'Switched off: your plan no longer includes this dashboard. It is not deleted.', 'gend-society' ) ) );
			}
		}
	}
}
add_action( 'admin_init', 'gend_society_feature_lapse_check' );

add_action(
	'admin_notices',
	static function () {
		$n = get_option( 'gend_society_feature_install_notice', array() );
		if ( ! is_array( $n ) || ! $n || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( 'gend_society_feature_install_notice' );
		/* translators: %s: dashboard names. */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Your plan includes new dashboards, now installed and switched on: %s.', 'gend-society' ), implode( ', ', array_map( 'sanitize_text_field', $n ) ) ) ) . '</p></div>';
	}
);
