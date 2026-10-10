<?php
/**
 * Runtime context: which kind of install this plugin is running on.
 *
 * Three runtimes share one codebase:
 *   hub        the gend.me network itself (multisite, operator tooling)
 *   container  a gend.me-managed customer site (GKE container or a local
 *              desktop-app site)
 *   standalone any other WordPress install
 *
 * This file is loaded by gend-society.php BEFORE any module, so it must stay
 * dependency-free: no gs_* function, no other inc/ file, WordPress core only.
 *
 * Resolution order (positive evidence only, never "a class is missing"):
 *   1. GEND_SOCIETY_RUNTIME constant (wp-config.php)
 *   2. GEND_SOCIETY_RUNTIME environment variable
 *   3. hub fail-safe: multisite whose DOMAIN_CURRENT_SITE is gend.me
 *   4. GDC_CONTAINER_INSTALL_ID (constant, then env): 'hub' => hub, else container
 *   5. WP_CONTENT_DIR/.gend-local.json (desktop-app local site) => container
 *   6. standalone
 *
 * The resolved mode is not filterable: it decides which modules load, so it
 * must not be changeable by other plugins at runtime.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_BP_PLUGIN_BASENAMES' ) ) {
	/*
	 * BuddyPress basenames checked in standalone mode. On gend.me installs
	 * BuddyPress ships inside the social-network plugin (its includes/buddypress
	 * folder is loaded by social-network/social-network.php); a stock install
	 * activates buddypress/bp-loader.php.
	 */
	define(
		'GEND_SOCIETY_BP_PLUGIN_BASENAMES',
		array(
			'social-network/social-network.php',
			'buddypress/bp-loader.php',
		)
	);
}

if ( ! defined( 'GEND_SOCIETY_WU_PLUGIN_BASENAMES' ) ) {
	/*
	 * WP Ultimo / Ultimate Multisite basenames checked in standalone mode.
	 * Neither ships in the gend.me plugin template (the hub installs it
	 * network-wide); these are the upstream plugin folder/file names.
	 */
	define(
		'GEND_SOCIETY_WU_PLUGIN_BASENAMES',
		array(
			'wp-ultimo/wp-ultimo.php',
			'ultimate-multisite/ultimate-multisite.php',
		)
	);
}

if ( ! function_exists( 'gend_society_normalize_runtime_mode' ) ) {
	/**
	 * Normalise a runtime-mode value. Internal helper.
	 *
	 * @param mixed $value Raw value (constant / env).
	 * @return string 'hub' | 'container' | 'standalone', or '' when invalid.
	 */
	function gend_society_normalize_runtime_mode( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );
		return in_array( $value, array( 'hub', 'container', 'standalone' ), true ) ? $value : '';
	}
}

if ( ! function_exists( 'gend_society_runtime_resolve' ) ) {
	/**
	 * Resolve mode + source once per request. Internal helper.
	 *
	 * @return array{0:string,1:string} [ mode, source ].
	 */
	function gend_society_runtime_resolve(): array {
		static $resolved = null;
		if ( null !== $resolved ) {
			return $resolved;
		}

		$mode   = '';
		$source = '';

		// 1. Constant.
		if ( defined( 'GEND_SOCIETY_RUNTIME' ) ) {
			$mode = gend_society_normalize_runtime_mode( constant( 'GEND_SOCIETY_RUNTIME' ) );
			if ( '' !== $mode ) {
				$source = 'constant';
			} else {
				error_log( '[gend-society] ignoring invalid GEND_SOCIETY_RUNTIME constant (expected hub|container|standalone)' );
			}
		}

		// 2. Environment variable.
		if ( '' === $mode ) {
			$env = getenv( 'GEND_SOCIETY_RUNTIME' );
			if ( false !== $env && '' !== $env ) {
				$mode = gend_society_normalize_runtime_mode( $env );
				if ( '' !== $mode ) {
					$source = 'env';
				} else {
					error_log( '[gend-society] ignoring invalid GEND_SOCIETY_RUNTIME env value (expected hub|container|standalone)' );
				}
			}
		}

		// 3. Hub fail-safe: the gend.me network itself.
		if ( '' === $mode
			&& function_exists( 'is_multisite' ) && is_multisite()
			&& defined( 'DOMAIN_CURRENT_SITE' )
			&& 'gend.me' === strtolower( (string) DOMAIN_CURRENT_SITE )
		) {
			$mode   = 'hub';
			$source = 'fallback-hub-domain';
		}

		// 4. Managed-install id (constant first, then env; never the option).
		if ( '' === $mode ) {
			$install_id = '';
			if ( defined( 'GDC_CONTAINER_INSTALL_ID' ) ) {
				$install_id = trim( (string) constant( 'GDC_CONTAINER_INSTALL_ID' ) );
			}
			if ( '' === $install_id ) {
				$env_id     = getenv( 'GDC_CONTAINER_INSTALL_ID' );
				$install_id = ( false === $env_id ) ? '' : trim( (string) $env_id );
			}
			if ( '' !== $install_id ) {
				if ( 'hub' === strtolower( $install_id ) ) {
					$mode   = 'hub';
					$source = 'fallback-install-id-hub';
				} else {
					$mode   = 'container';
					$source = 'fallback-install-id';
				}
			}
		}

		// 5. Desktop-app local site marker.
		if ( '' === $mode && defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/.gend-local.json' ) ) {
			$mode   = 'container';
			$source = 'fallback-local-marker';
		}

		// 6. Default.
		if ( '' === $mode ) {
			$mode   = 'standalone';
			$source = 'default';
		}

		// Hub reached only by a fail-safe: tell the operator (max once per 6h).
		// Site option, not a transient: the hub's external object cache can
		// evict transients. The constant/env path never logs.
		if ( 'hub' === $mode && 0 === strpos( $source, 'fallback-' ) && function_exists( 'get_site_option' ) ) {
			$last = (int) get_site_option( 'gend_society_runtime_fallback_warned', 0 );
			if ( time() - $last > 6 * HOUR_IN_SECONDS ) {
				update_site_option( 'gend_society_runtime_fallback_warned', time() );
				error_log( '[gend-society] runtime mode resolved to hub by fallback (' . $source . '); set GEND_SOCIETY_RUNTIME=hub in the hub Deployment env' );
			}
		}

		if ( ! defined( 'GEND_SOCIETY_RUNTIME_MODE' ) ) {
			define( 'GEND_SOCIETY_RUNTIME_MODE', $mode );
		}

		$resolved = array( $mode, $source );
		return $resolved;
	}
}

if ( ! function_exists( 'gend_society_runtime_mode' ) ) {
	/**
	 * Runtime mode, resolved once per request.
	 *
	 * @return string 'hub' | 'container' | 'standalone'.
	 */
	function gend_society_runtime_mode(): string {
		$resolved = gend_society_runtime_resolve();
		return $resolved[0];
	}
}

if ( ! function_exists( 'gend_society_runtime_mode_source' ) ) {
	/**
	 * How the runtime mode was resolved.
	 *
	 * @return string 'constant' | 'env' | 'fallback-hub-domain' | 'fallback-install-id-hub'
	 *                | 'fallback-install-id' | 'fallback-local-marker' | 'default'.
	 */
	function gend_society_runtime_mode_source(): string {
		$resolved = gend_society_runtime_resolve();
		return $resolved[1];
	}
}

if ( ! function_exists( 'gend_society_is_hub' ) ) {
	/**
	 * True on the gend.me hub. The only hub test this plugin may use.
	 */
	function gend_society_is_hub(): bool {
		return 'hub' === gend_society_runtime_mode();
	}
}

if ( ! function_exists( 'gend_society_is_hub_operator' ) ) {
	/**
	 * gend.me network operator: a super admin on the hub. Always false
	 * elsewhere (a container or standalone super admin is a customer).
	 *
	 * @param int|null $user_id User id; null = current user.
	 */
	function gend_society_is_hub_operator( $user_id = null ): bool {
		if ( ! gend_society_is_hub() ) {
			return false;
		}
		return null === $user_id ? (bool) is_super_admin() : (bool) is_super_admin( (int) $user_id );
	}
}

if ( ! function_exists( 'gend_society_mode_tiers' ) ) {
	/**
	 * Module tiers allowed in a runtime mode, intersected with
	 * GEND_SOCIETY_BUILD_TIERS when that constant is defined (array or
	 * comma-separated string; undefined = all tiers shipped).
	 *
	 * @param string $mode Runtime mode.
	 * @return string[]
	 */
	function gend_society_mode_tiers( string $mode ): array {
		$map = array(
			'hub'        => array( 'core', 'customer', 'container', 'hub', 'updater' ),
			'container'  => array( 'core', 'customer', 'container', 'updater' ),
			'standalone' => array( 'core', 'customer', 'updater' ),
		);
		$tiers = isset( $map[ $mode ] ) ? $map[ $mode ] : $map['standalone'];

		if ( defined( 'GEND_SOCIETY_BUILD_TIERS' ) ) {
			$built = constant( 'GEND_SOCIETY_BUILD_TIERS' );
			if ( is_string( $built ) ) {
				$built = array_map( 'trim', explode( ',', $built ) );
			}
			if ( is_array( $built ) ) {
				$tiers = array_values( array_intersect( $tiers, $built ) );
			}
		}

		return $tiers;
	}
}

if ( ! function_exists( 'gend_society_plugin_basename_active' ) ) {
	/**
	 * True when any of the given plugin basenames is active on this site or
	 * network-activated. Reads the options directly (is_plugin_active() is
	 * not loaded on the front end at plugin-load time). Internal helper.
	 *
	 * @param string[] $basenames Plugin basenames.
	 */
	function gend_society_plugin_basename_active( array $basenames ): bool {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return (bool) array_intersect( $basenames, $active );
	}
}

if ( ! function_exists( 'gend_society_module_needs_met' ) ) {
	/**
	 * True when every need is met. Vocabulary: 'bp', 'wu', 'paired', 'skin', 'admin'.
	 * On hub and container bp/wu/paired/skin are always met (both run the full
	 * suite); only standalone checks them. An unknown need is never met.
	 *
	 * @param string[] $needs Needs list from the manifest.
	 */
	function gend_society_module_needs_met( array $needs ): bool {
		$mode    = gend_society_runtime_mode();
		$managed = ( 'hub' === $mode || 'container' === $mode );

		foreach ( $needs as $need ) {
			switch ( $need ) {
				case 'admin':
					$met = is_admin();
					break;
				case 'bp':
					$met = $managed || gend_society_plugin_basename_active( GEND_SOCIETY_BP_PLUGIN_BASENAMES );
					break;
				case 'wu':
					$met = $managed || gend_society_plugin_basename_active( GEND_SOCIETY_WU_PLUGIN_BASENAMES );
					break;
				case 'paired':
					$met = $managed || '' !== (string) get_option( 'gend_society_install_token', '' );
					break;
				case 'skin':
					$met = true; // Phase 106 makes the admin skin opt-in on standalone.
					break;
				default:
					$met = false;
			}
			if ( ! $met ) {
				return false;
			}
		}
		return true;
	}
}
