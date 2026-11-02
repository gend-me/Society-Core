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
			// Already on disk (an earlier run, or put there by hand): just make sure it is switched on.
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! is_plugin_active( $plugin ) ) {
				$act = activate_plugin( $plugin );
				if ( is_wp_error( $act ) ) {
					gend_society_feature_install_record( $slug, array( 'state' => 'failed', 'step' => 'failed', 'message' => $act->get_error_message() ) );
					return $act;
				}
			}
			gend_society_feature_install_record( $slug, array( 'state' => 'active', 'step' => 'done', 'message' => '' ) );
			return array( 'plugin' => $plugin, 'version' => '' );
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
		gend_society_feature_install_record( $slug, array( 'state' => 'installing', 'step' => 'contacting', 'message' => '' ) );
		$tmp    = '';
		$result = ( function () use ( $slug, $plugin, $keys, &$tmp ) {
			$step = static function ( string $name ) use ( $slug ) {
				gend_society_feature_install_record( $slug, array( 'state' => 'installing', 'step' => $name ) );
			};
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
			$step( 'downloading' );
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
			$step( 'verifying' );
			$pinned = null === $keys ? ( function_exists( 'gend_society_update_public_keys' ) ? gend_society_update_public_keys() : array() ) : $keys;
			$ok     = gend_society_feature_verify_package( $tmp, $slug, $m, $pinned );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$step( 'installing' );
			$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$ins = $up->install( $tmp );
			if ( is_wp_error( $ins ) ) {
				return $ins;
			}
			if ( ! $ins || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				return new WP_Error( 'gs_feature_install', __( 'The package installed but its plugin file was not found.', 'gend-society' ) );
			}
			$step( 'activating' );
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
			gend_society_feature_install_record( $slug, array( 'state' => 'failed', 'step' => 'failed', 'message' => $result->get_error_message(), 'tries' => (int) ( $all[ $slug ]['tries'] ?? 0 ) + 1 ) );
			return $result;
		}
		gend_society_feature_install_record( $slug, array( 'state' => 'active', 'step' => 'done', 'message' => '', 'version' => $result['version'], 'by_installer' => true, 'tries' => 0 ) );
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
			wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ) );
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

if ( ! function_exists( 'gend_society_feature_setup_status' ) ) {
	/**
	 * What the setup panel shows: every dashboard the plan includes, with where its install is.
	 *
	 * @return array{enabled:bool,features:array<int,array<string,mixed>>,missing:string[],busy:bool}
	 */
	function gend_society_feature_setup_status(): array {
		$out = array( 'enabled' => gend_society_feature_installer_enabled(), 'features' => array(), 'missing' => array(), 'busy' => (bool) get_transient( 'gend_society_feature_install_lock' ) );
		if ( ! $out['enabled'] || ! function_exists( 'gend_society_get_feature_definitions' ) ) {
			return $out;
		}
		$recs   = gend_society_feature_install_records();
		$labels = array(
			'queued'      => array( __( 'Waiting to start', 'gend-society' ), 5 ),
			'contacting'  => array( __( 'Asking gend.me for the package', 'gend-society' ), 15 ),
			'downloading' => array( __( 'Downloading', 'gend-society' ), 40 ),
			'verifying'   => array( __( 'Checking the signature', 'gend-society' ), 62 ),
			'installing'  => array( __( 'Installing', 'gend-society' ), 82 ),
			'activating'  => array( __( 'Switching it on', 'gend-society' ), 94 ),
			'done'        => array( __( 'Installed and switched on', 'gend-society' ), 100 ),
			'failed'      => array( __( 'Could not install', 'gend-society' ), 0 ),
		);
		foreach ( gend_society_get_feature_definitions() as $slug => $def ) {
			if ( 'wireframe' === $slug ) {
				continue;
			}
			$st        = function_exists( 'gend_society_feature_plan_status' ) ? gend_society_feature_plan_status( (string) $slug ) : array( 'state' => 'unknown' );
			$installed = file_exists( WP_PLUGIN_DIR . '/' . $def['plugin'] );
			$active    = function_exists( 'gend_society_plugin_active' ) && gend_society_plugin_active( $def['plugin'] );
			if ( 'included' !== $st['state'] ) {
				continue;
			}
			$rec  = isset( $recs[ $slug ] ) ? $recs[ $slug ] : array();
			$step = $installed ? ( $active ? 'done' : 'activating' ) : (string) ( $rec['step'] ?? ( ( $rec['state'] ?? '' ) === 'failed' ? 'failed' : 'queued' ) );
			if ( ! isset( $labels[ $step ] ) ) {
				$step = 'queued';
			}
			$out['features'][] = array(
				'slug'    => (string) $slug,
				'name'    => (string) $def['name'],
				'step'    => $step,
				'label'   => $labels[ $step ][0],
				'pct'     => $labels[ $step ][1],
				'failed'  => 'failed' === $step,
				'done'    => 'done' === $step,
				'message' => 'failed' === $step ? (string) ( $rec['message'] ?? '' ) : '',
				'url'     => $installed ? admin_url( 'plugins.php' ) : '',
			);
			if ( ! $installed ) {
				$out['missing'][] = (string) $slug;
			}
		}
		return $out;
	}
}

add_action(
	'wp_ajax_gend_society_feature_install_status',
	static function () {
		check_ajax_referer( 'gs_plugin_actions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
		}
		wp_send_json_success( gend_society_feature_setup_status() );
	}
);

if ( ! function_exists( 'gend_society_feature_setup_panel_html' ) ) {
	/**
	 * The "Setting up your dashboards" panel: one row per dashboard the plan includes that is not finished, with the step it is
	 * on and a progress bar. The page itself drives the installs one at a time (so progress is visible and a slow background
	 * job is never needed) and polls the status; WP-Cron remains the fallback.
	 *
	 * @param string[] $only Limit to these feature slugs (a feature page shows only its own).
	 * @param bool     $compact Smaller variant for a feature page.
	 */
	function gend_society_feature_setup_panel_html( array $only = array(), bool $compact = false ): string {
		$st = gend_society_feature_setup_status();
		if ( ! $st['enabled'] ) {
			return '';
		}
		$rows = array_values( array_filter( $st['features'], static function ( $r ) use ( $only ) {
			return ! $only || in_array( $r['slug'], $only, true );
		} ) );
		$pending = array_filter( $rows, static function ( $r ) { return ! $r['done']; } );
		if ( ! $pending ) {
			return '';
		}
		$nonce = wp_create_nonce( 'gs_plugin_actions' );
		ob_start();
		?>
<style>
.gs-setup { margin: 0 0 24px; padding: 20px 24px; border-radius: 20px; border: 1px solid rgba(122,167,255,.35); background: linear-gradient(165deg, rgba(24,34,60,.88), rgba(10,15,28,.82)); box-shadow: 0 30px 70px -36px rgba(60,100,255,.5), inset 0 1px 0 rgba(255,255,255,.07); color: #e8eefb; }
.gs-setup__head { display: flex; align-items: center; gap: 12px; margin: 0 0 4px; }
.gs-setup__spin { width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(122,167,255,.3); border-top-color: #7aa7ff; animation: gssetupspin .9s linear infinite; flex: 0 0 18px; }
.gs-setup.is-idle .gs-setup__spin, .gs-setup.is-done .gs-setup__spin { display: none; }
@keyframes gssetupspin { to { transform: rotate(360deg); } }
.gs-setup__title { margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff; letter-spacing: -.01em; }
.gs-setup__sub { margin: 0 0 14px; font-size: .88rem; color: #9fb0cf; }
.gs-setup__row { display: grid; grid-template-columns: minmax(130px, 200px) 1fr auto; gap: 8px 16px; align-items: center; padding: 10px 0; border-top: 1px solid rgba(148,163,184,.14); }
.gs-setup__name { font-weight: 700; color: #fff; }
.gs-setup__bar { height: 8px; border-radius: 999px; background: rgba(148,163,184,.18); overflow: hidden; }
.gs-setup__bar > span { display: block; height: 100%; width: 0; border-radius: inherit; background: linear-gradient(90deg, #6d7cff, #a86bff); transition: width .6s ease; }
.gs-setup__row.is-done .gs-setup__bar > span { background: linear-gradient(90deg, #22c55e, #86efac); }
.gs-setup__row.is-failed .gs-setup__bar > span { background: #f43f5e; width: 100% !important; }
.gs-setup__label { font-size: .82rem; color: #c4d6ff; min-width: 190px; text-align: right; }
.gs-setup__row.is-failed .gs-setup__label { color: #fca5a5; }
.gs-setup__row.is-done .gs-setup__label { color: #86efac; }
.gs-setup__msg { grid-column: 1 / -1; margin: 0; font-size: .8rem; color: #fca5a5; }
.gs-setup__actions { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; }
.gs-setup--compact { margin: 12px 0 0; padding: 16px 18px; }
@media (max-width: 720px) { .gs-setup__row { grid-template-columns: 1fr; } .gs-setup__label { text-align: left; min-width: 0; } }
</style>
<div class="gs-setup<?php echo $compact ? ' gs-setup--compact' : ''; ?>" data-gs-setup="1" data-gs-setup-only="<?php echo esc_attr( implode( ',', $only ) ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" role="status" aria-live="polite">
	<div class="gs-setup__head"><span class="gs-setup__spin" aria-hidden="true"></span><h3 class="gs-setup__title"><?php esc_html_e( 'Setting up your dashboards', 'gend-society' ); ?></h3></div>
	<p class="gs-setup__sub" data-gs-setup-sub="1"><?php esc_html_e( 'Your plan includes these dashboards. They are being downloaded from gend.me, checked and switched on for you. You can keep working: this page updates as each one finishes.', 'gend-society' ); ?></p>
	<div data-gs-setup-rows="1">
		<?php foreach ( $rows as $r ) : ?>
		<div class="gs-setup__row<?php echo $r['done'] ? ' is-done' : ( $r['failed'] ? ' is-failed' : '' ); ?>" data-gs-setup-row="<?php echo esc_attr( $r['slug'] ); ?>">
			<span class="gs-setup__name"><?php echo esc_html( $r['name'] ); ?></span>
			<span class="gs-setup__bar"><span style="width:<?php echo (int) $r['pct']; ?>%"></span></span>
			<span class="gs-setup__label"><?php echo esc_html( $r['label'] ); ?></span>
			<?php if ( $r['failed'] && '' !== $r['message'] ) : ?><p class="gs-setup__msg"><?php echo esc_html( $r['message'] ); ?></p><?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
	<div class="gs-setup__actions" data-gs-setup-actions="1" hidden></div>
</div>
<script>
(function () {
    var box = document.querySelector('[data-gs-setup]');
    if (!box || box.getAttribute('data-gs-bound')) { return; }
    box.setAttribute('data-gs-bound', '1');
    var AJAX = box.getAttribute('data-ajax'), NONCE = box.getAttribute('data-nonce');
    var ONLY = (box.getAttribute('data-gs-setup-only') || '').split(',').filter(Boolean);
    var rowsEl = box.querySelector('[data-gs-setup-rows]'), subEl = box.querySelector('[data-gs-setup-sub]'), actEl = box.querySelector('[data-gs-setup-actions]');
    var running = false, installedAny = false, timer = null;
    function post(action, extra) {
        var fd = new FormData(); fd.append('action', action); fd.append('nonce', NONCE);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function render(data) {
        var rows = (data.features || []).filter(function (r) { return ONLY.length === 0 || ONLY.indexOf(r.slug) > -1; });
        var html = '';
        rows.forEach(function (r) {
            html += '<div class="gs-setup__row' + (r.done ? ' is-done' : (r.failed ? ' is-failed' : '')) + '" data-gs-setup-row="' + esc(r.slug) + '">' +
                '<span class="gs-setup__name">' + esc(r.name) + '</span><span class="gs-setup__bar"><span style="width:' + (r.pct || 0) + '%"></span></span>' +
                '<span class="gs-setup__label">' + esc(r.label) + '</span>' + (r.failed ? (r.message ? '<p class="gs-setup__msg">' + esc(r.message) + '</p>' : '') : '') + '</div>';
            document.querySelectorAll('[data-gs-setup-line="' + r.slug + '"]').forEach(function (el) { el.textContent = r.done ? 'Installed and switched on.' : r.label + (r.failed ? '' : '...'); });
        });
        rowsEl.innerHTML = html;
        return rows;
    }
    function status() { return post('gend_society_feature_install_status').then(function (j) { if (j.success) { render(j.data); } return j; }); }
    function finish(failedCount) {
        running = false; if (timer) { clearInterval(timer); timer = null; }
        box.classList.add(failedCount ? 'is-idle' : 'is-done');
        box.querySelector('.gs-setup__title').textContent = failedCount ? 'Some dashboards need attention' : 'Your dashboards are ready';
        subEl.textContent = failedCount ? 'The rest are installed. A dashboard that could not be installed can be retried.' : 'Everything your plan includes is installed and switched on. Reloading so the menus and cards update...';
        if (failedCount) {
            actEl.hidden = false; actEl.innerHTML = '<button type="button" class="gs-buy" data-gs-setup-retry="1">Retry</button>';
        } else if (installedAny) { setTimeout(function () { window.location.reload(); }, 2200); }
    }
    function runAll() {
        if (running) { return; }
        running = true; actEl.hidden = true; box.classList.remove('is-idle', 'is-done');
        timer = setInterval(status, 1500);
        status().then(function (j) {
            var todo = (j.data ? (j.data.missing || []) : []).filter(function (s) { return ONLY.length === 0 || ONLY.indexOf(s) > -1; });
            var failed = 0;
            var next = function (i) {
                if (i >= todo.length) { return status().then(function () { finish(failed); }); }
                var attempt = function (n) {
                    return post('gend_society_install_feature', { feature: todo[i] }).then(function (r) {
                        if (r.success) { installedAny = true; return; }
                        var busy = r.data ? r.data.code === 'gs_feature_busy' : false;
                        if (busy) { if (n < 40) { return new Promise(function (res) { setTimeout(res, 3000); }).then(function () { return attempt(n + 1); }); } }
                        failed++;
                    }).catch(function () { failed++; });
                };
                return attempt(0).then(function () { return status(); }).then(function () { return next(i + 1); });
            };
            if (!todo.length) { finish(0); return; }
            next(0);
        }).catch(function () { finish(1); });
    }
    box.addEventListener('click', function (e) { if (e.target.closest('[data-gs-setup-retry]')) { e.preventDefault(); runAll(); } });
    runAll();
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
}
