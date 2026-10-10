<?php
/**
 * Compat bridges: other plugins still read and write some gend-society keys under their pre-1.2.0 names.
 *
 * Full build only (container tier); generated from bin/rename-map.json; do not edit.
 * Regenerate: php bin/gen-compat.php --part=early > inc/compat-bridges.php
 * Map version: 105.1
 *
 * @package gend-society
 */

// phpcs:ignoreFile -- generated compatibility layer: it exists to keep the pre-1.2.0 names working for other plugins.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Semantics (copy-not-move migration, see inc/bootstrap/key-migration.php):
 *  - Before this blog's flag option gend_society_keys_migrated (network data:
 *    site option gend_society_network_keys_migrated) equals the map version, the
 *    old rows are authoritative and every bridge is a no-op for reads.
 *  - Once the flag is set, a read of an OLD name is served from the NEW key
 *    (falling back to the old row when the new one is absent).
 *  - Writes are write-through while old rows exist: a sibling writing an OLD name
 *    also writes the NEW key, and the old write still happens, so the old row keeps
 *    receiving sibling writes and stays a valid rollback source.
 *  - 1.2.0's own writes to NEW keys are NOT mirrored back to the old names; they are
 *    lost on a rollback to 1.1.x (accepted: rollback re-reads the old rows).
 */

if ( ! defined( 'GEND_SOCIETY_KEYMAP_VERSION' ) ) {
	define( 'GEND_SOCIETY_KEYMAP_VERSION', '105.1' );
}

/**
 * Old => new key pairs that other plugins use (from the caller audit).
 *
 * @return array<string, array<string, string>>
 */
function gend_society_compat_bridge_keys() {
	return array(
		'option'      => array(
			'gs_gend_base_url' => 'gend_society_gend_base_url',
			'gs_gend_pubkey' => 'gend_society_gend_pubkey',
			'gs_install_id' => 'gend_society_install_id',
			'gs_install_token' => 'gend_society_install_token',
			'gs_keypair' => 'gend_society_keypair',
			'gs_mail_relay_state' => 'gend_society_mail_relay_state',
		),
		'site_option' => array(
		),
		'user_meta'   => array(
			'_gdc_profile_page_id' => '_gend_society_profile_page_id',
			'gs_feature_access' => 'gend_society_feature_access',
		),
		'post_meta'   => array(
			'_gs_seo_description' => '_gend_society_seo_description',
			'_gs_seo_title' => '_gend_society_seo_title',
		),
		'group_meta'  => array(
			'_gs_collab_category' => '_gend_society_collab_category',
			'_gs_collab_industry' => '_gend_society_collab_industry',
			'_gs_collab_location' => '_gend_society_collab_location',
			'_gs_collab_optin' => '_gend_society_collab_optin',
		),
		'term_meta'   => array(
		),
	);
}

/**
 * True once this blog's (or, for network data, the network's) key migration ran.
 *
 * @param bool $network Network-level data (site options, user meta, group meta).
 */
function gend_society_compat_migrated( $network ) {
	if ( $network ) {
		return GEND_SOCIETY_KEYMAP_VERSION === get_site_option( 'gend_society_network_keys_migrated' );
	}
	return GEND_SOCIETY_KEYMAP_VERSION === get_option( 'gend_society_keys_migrated' );
}

/**
 * Register the option, site-option and metadata bridges. Called once by
 * inc/bootstrap/key-migration.php before any module loads.
 */
function gend_society_compat_bridges() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$keys = gend_society_compat_bridge_keys();
	$busy = array();

	foreach ( $keys['option'] as $old => $new ) {
		add_filter(
			'pre_option_' . $old,
			static function ( $pre ) use ( $new ) {
				if ( false !== $pre || ! gend_society_compat_migrated( false ) ) {
					return $pre;
				}
				$val = get_option( $new, null );
				return null === $val ? $pre : $val;
			}
		);
		add_filter(
			'pre_update_option_' . $old,
			static function ( $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ $new ] ) ) {
					$busy[ $new ] = true;
					update_option( $new, $value );
					unset( $busy[ $new ] );
				}
				return $value; // the old row keeps receiving the write (rollback source).
			}
		);
		add_action(
			'add_option_' . $old,
			static function ( $option, $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ $new ] ) ) {
					$busy[ $new ] = true;
					update_option( $new, $value );
					unset( $busy[ $new ] );
				}
			},
			10,
			2
		);
		add_action(
			'delete_option_' . $old,
			static function () use ( $new ) {
				delete_option( $new );
			}
		);
	}

	foreach ( $keys['site_option'] as $old => $new ) {
		add_filter(
			'pre_site_option_' . $old,
			static function ( $pre ) use ( $new ) {
				if ( false !== $pre || ! gend_society_compat_migrated( true ) ) {
					return $pre;
				}
				$val = get_site_option( $new, null );
				return null === $val ? $pre : $val;
			}
		);
		add_filter(
			'pre_update_site_option_' . $old,
			static function ( $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ 'site:' . $new ] ) ) {
					$busy[ 'site:' . $new ] = true;
					update_site_option( $new, $value );
					unset( $busy[ 'site:' . $new ] );
				}
				return $value;
			}
		);
		add_action(
			'add_site_option_' . $old,
			static function ( $option, $value ) use ( $new, &$busy ) {
				if ( empty( $busy[ 'site:' . $new ] ) ) {
					$busy[ 'site:' . $new ] = true;
					update_site_option( $new, $value );
					unset( $busy[ 'site:' . $new ] );
				}
			},
			10,
			2
		);
		add_action(
			'delete_site_option_' . $old,
			static function () use ( $new ) {
				delete_site_option( $new );
			}
		);
	}

	foreach ( array( 'user', 'post', 'group', 'term' ) as $type ) {
		$map = $keys[ $type . '_meta' ];
		if ( ! $map ) {
			continue;
		}
		$network = in_array( $type, array( 'user', 'group' ), true );
		add_filter(
			"get_{$type}_metadata",
			static function ( $check, $object_id, $meta_key, $single ) use ( $map, $type, $network ) {
				if ( null !== $check || ! is_string( $meta_key ) || ! isset( $map[ $meta_key ] ) || ! gend_society_compat_migrated( $network ) ) {
					return $check;
				}
				$vals = get_metadata( $type, $object_id, $map[ $meta_key ], false );
				if ( ! is_array( $vals ) || ! $vals ) {
					return $check; // no new row yet: the old row answers.
				}
				return $single ? array( $vals[0] ) : $vals;
			},
			10,
			4
		);
		foreach ( array( 'update', 'add' ) as $verb ) {
			add_filter(
				"{$verb}_{$type}_metadata",
				static function ( $check, $object_id, $meta_key, $meta_value ) use ( $map, $type, &$busy ) {
					if ( null !== $check || ! is_string( $meta_key ) || ! isset( $map[ $meta_key ] ) ) {
						return $check;
					}
					$b = $type . ':' . $map[ $meta_key ] . ':' . $object_id;
					if ( empty( $busy[ $b ] ) ) {
						$busy[ $b ] = true;
						update_metadata( $type, $object_id, $map[ $meta_key ], $meta_value );
						unset( $busy[ $b ] );
					}
					return $check; // null: the old write proceeds too.
				},
				10,
				4
			);
		}
		add_filter(
			"delete_{$type}_metadata",
			static function ( $check, $object_id, $meta_key ) use ( $map, $type, &$busy ) {
				if ( null !== $check || ! is_string( $meta_key ) || ! isset( $map[ $meta_key ] ) ) {
					return $check;
				}
				$b = 'del:' . $type . ':' . $map[ $meta_key ] . ':' . $object_id;
				if ( empty( $busy[ $b ] ) ) {
					$busy[ $b ] = true;
					delete_metadata( $type, $object_id, $map[ $meta_key ] );
					unset( $busy[ $b ] );
				}
				return $check;
			},
			10,
			3
		);
	}
}
