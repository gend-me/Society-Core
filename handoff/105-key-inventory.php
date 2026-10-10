<?php
/**
 * Phase 105 key inventory (FND-04 evidence). READ-ONLY: no option, meta or
 * cron write, and never prints a value -- only existence, autoload, counts and
 * md5 hashes of the raw stored bytes.
 *
 *   GS105_KEYMAP=/tmp/key-map.php wp --allow-root --path=/var/www/html [--url=<site>] \
 *     eval-file /tmp/105-key-inventory.php > inventory.json
 *
 * GS105_KEYMAP points at inc/bootstrap/key-map.php (needed on 1.1.x, which does
 * not ship it); on 1.2.0 the plugin's own copy is used when the env is unset.
 *
 * Per blog: every key-map option (explicit and allowlisted-prefix rows), post
 * meta and cron hook, old and new name. Network data (site options, user meta,
 * BuddyPress group meta) only when run on the main site (or a single site).
 * Rows are read with direct SELECTs, so the compat bridges (which serve old
 * names from new keys) cannot mask what is really stored.
 *
 * "counts" uses the migration log's units, so a BEFORE inventory's counts
 * equal the log written by the migration: option/option_prefix = rows,
 * post_meta/user_meta/user_meta_prefix/group_meta = (object, key) pairs,
 * site_option = rows, cron = events.
 *
 * Also used as a library by 105-uat-rename.php (define GS105_INVENTORY_LIB first).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

if ( ! function_exists( 'gs105_inv_keymap' ) ) {

	/**
	 * The key map: GS105_KEYMAP, else the plugin's inc/bootstrap/key-map.php.
	 *
	 * @return array|null
	 */
	function gs105_inv_keymap() {
		$candidates = array();
		$env        = getenv( 'GS105_KEYMAP' );
		if ( is_string( $env ) && '' !== $env ) {
			$candidates[] = $env;
		}
		$candidates[] = WP_PLUGIN_DIR . '/gend-society/inc/bootstrap/key-map.php';
		foreach ( $candidates as $path ) {
			if ( is_file( $path ) ) {
				$map = include $path;
				if ( is_array( $map ) ) {
					foreach ( array( 'option', 'site_option', 'user_meta', 'post_meta', 'group_meta', 'wu_site_meta', 'cron', 'option_prefix', 'user_meta_prefix' ) as $k ) {
						if ( ! isset( $map[ $k ] ) || ! is_array( $map[ $k ] ) ) {
							$map[ $k ] = array();
						}
					}
					$map['_path'] = $path;
					return $map;
				}
			}
		}
		return null;
	}

	/**
	 * One option row of the current blog: null when absent.
	 *
	 * @param string $name Option name.
	 * @return array|null
	 */
	function gs105_inv_option_row( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return array(
			'autoload' => (string) $row['autoload'],
			'md5'      => md5( (string) $row['option_value'] ),
		);
	}

	/**
	 * One network option row: sitemeta on multisite, options on single site.
	 *
	 * @param string $name Option name.
	 * @return array|null
	 */
	function gs105_inv_site_option_row( $name ) {
		global $wpdb;
		if ( ! is_multisite() ) {
			return gs105_inv_option_row( $name );
		}
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d ORDER BY meta_id LIMIT 1", $name, get_current_network_id() ) );
		return null === $val ? null : array( 'md5' => md5( (string) $val ) );
	}

	/**
	 * All rows of one meta key: object count, row count and an md5 of the ordered
	 * (object id, raw value) list. Null when the key has no row.
	 *
	 * @param string $table  Meta table.
	 * @param string $id_col Object id column.
	 * @param string $pk     Primary key column (row order inside an object).
	 * @param string $key    Meta key.
	 * @return array|null
	 */
	function gs105_inv_meta_rows( $table, $id_col, $pk, $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are fixed by the caller.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_col} AS oid, meta_value AS v FROM {$table} WHERE meta_key = %s ORDER BY {$id_col}, {$pk}", $key ), ARRAY_A );
		if ( ! $rows ) {
			return null;
		}
		$ids  = array();
		$flat = array();
		foreach ( $rows as $r ) {
			$ids[ $r['oid'] ] = true;
			$flat[]           = array( (string) $r['oid'], md5( (string) $r['v'] ) );
		}
		return array(
			'objects' => count( $ids ),
			'rows'    => count( $rows ),
			'md5'     => md5( wp_json_encode( $flat ) ),
		);
	}

	/**
	 * Distinct keys of a meta table / options table matching a prefix.
	 *
	 * @param string $table Table.
	 * @param string $col   Key column.
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	function gs105_inv_prefix_keys( $table, $col, $prefix ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are fixed by the caller.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$col} FROM {$table} WHERE {$col} LIKE %s ORDER BY {$col}", $wpdb->esc_like( $prefix ) . '%' ) ) );
	}

	/**
	 * Cron events of one hook on the current blog (timestamps excluded from the
	 * hash: recurring events legitimately move).
	 *
	 * @param string $hook Hook.
	 * @return array{count:int, md5:string, events:string[]}
	 */
	function gs105_inv_cron( $hook ) {
		$events = array();
		foreach ( (array) _get_cron_array() as $ts => $by_hook ) {
			if ( empty( $by_hook[ $hook ] ) ) {
				continue;
			}
			foreach ( (array) $by_hook[ $hook ] as $e ) {
				$events[] = ( empty( $e['schedule'] ) ? 'single' : $e['schedule'] . '/' . (int) $e['interval'] ) . '|' . md5( serialize( isset( $e['args'] ) ? $e['args'] : array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress keys cron args by md5(serialize()).
			}
		}
		sort( $events );
		return array(
			'count'  => count( $events ),
			'md5'    => md5( implode( "\n", $events ) ),
			'events' => $events,
		);
	}

	/**
	 * BuddyPress group meta table, or '' when it does not exist.
	 *
	 * @return string
	 */
	function gs105_inv_groupmeta_table() {
		global $wpdb;
		if ( ! empty( $wpdb->groupmeta ) ) {
			return $wpdb->groupmeta;
		}
		$t = $wpdb->base_prefix . 'bp_groups_groupmeta';
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ? $t : '';
	}

	/**
	 * Build the inventory for the current blog (+ network data on the main site).
	 *
	 * @return array
	 */
	function gs105_inventory() {
		global $wpdb;
		$map = gs105_inv_keymap();
		$out = array(
			'tool'           => '105-key-inventory',
			'site'           => home_url( '/' ),
			'blog_id'        => get_current_blog_id(),
			'multisite'      => is_multisite(),
			'network_data'   => ! is_multisite() || is_main_site(),
			'plugin_version' => defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : ( defined( 'GS_VERSION' ) ? GS_VERSION : null ),
			'keymap_version' => $map ? (string) $map['version'] : null,
			'keymap_path'    => $map ? $map['_path'] : null,
		);
		if ( ! $map ) {
			$out['error'] = 'key map not found: set GS105_KEYMAP';
			return $out;
		}
		$counts     = array_fill_keys( array( 'option', 'option_prefix', 'post_meta', 'cron', 'site_option', 'user_meta', 'user_meta_prefix', 'group_meta' ), 0 );
		$counts_new = $counts;

		$fl                  = gs105_inv_option_row( 'gend_society_keys_migrated' );
		$out['flags']        = array(
			'blog'    => $fl ? get_option( 'gend_society_keys_migrated' ) : null,
			'network' => get_site_option( 'gend_society_network_keys_migrated', null ),
		);
		$log                 = get_option( 'gend_society_key_migration_log', null );
		$nlog                = get_site_option( 'gend_society_network_key_migration_log', null );
		$out['logs']         = array(
			'blog'    => is_array( $log ) ? $log : null,
			'network' => is_array( $nlog ) ? $nlog : null,
		);

		// Options: explicit + allowlisted prefixes.
		$opts = array();
		foreach ( $map['option'] as $old => $new ) {
			$opts[ $old ] = array( 'new' => $new, 'unit' => 'option' );
		}
		foreach ( $map['option_prefix'] as $op => $np ) {
			foreach ( gs105_inv_prefix_keys( $wpdb->options, 'option_name', $op ) as $name ) {
				$opts[ $name ] = array( 'new' => $np . substr( $name, strlen( $op ) ), 'unit' => 'option_prefix', 'prefix' => $op );
			}
			foreach ( gs105_inv_prefix_keys( $wpdb->options, 'option_name', $np ) as $name ) {
				$old = $op . substr( $name, strlen( $np ) );
				if ( ! isset( $opts[ $old ] ) ) {
					$opts[ $old ] = array( 'new' => $name, 'unit' => 'option_prefix', 'prefix' => $op );
				}
			}
		}
		ksort( $opts );
		foreach ( $opts as $old => &$o ) {
			$o['old_row'] = gs105_inv_option_row( $old );
			$o['new_row'] = gs105_inv_option_row( $o['new'] );
			if ( $o['old_row'] ) {
				++$counts[ $o['unit'] ];
			}
			if ( $o['new_row'] ) {
				++$counts_new[ $o['unit'] ];
			}
		}
		unset( $o );
		$out['option'] = $opts;

		$pm = array();
		foreach ( $map['post_meta'] as $old => $new ) {
			$a           = gs105_inv_meta_rows( $wpdb->postmeta, 'post_id', 'meta_id', $old );
			$b           = gs105_inv_meta_rows( $wpdb->postmeta, 'post_id', 'meta_id', $new );
			$pm[ $old ]  = array( 'new' => $new, 'old_rows' => $a, 'new_rows' => $b );
			$counts['post_meta']     += $a ? $a['objects'] : 0;
			$counts_new['post_meta'] += $b ? $b['objects'] : 0;
		}
		$out['post_meta'] = $pm;

		$cr = array();
		foreach ( $map['cron'] as $old => $new ) {
			$a          = gs105_inv_cron( $old );
			$b          = gs105_inv_cron( $new );
			$cr[ $old ] = array( 'new' => $new, 'old' => $a, 'new_events' => $b );
			$counts['cron']     += $a['count'];
			$counts_new['cron'] += $b['count'];
		}
		$out['cron'] = $cr;

		$foreign = array(
			'option:gs_gdrive_picker_api_key' => gs105_inv_option_row( 'gs_gdrive_picker_api_key' ),
			'option:gdc_bp_group_id'          => gs105_inv_option_row( 'gdc_bp_group_id' ),
			'option:gs_gdrive_client_id'      => gs105_inv_option_row( 'gs_gdrive_client_id' ),
			'option:gs_gdrive_client_secret'  => gs105_inv_option_row( 'gs_gdrive_client_secret' ),
		);
		$pst_keys = gs105_inv_prefix_keys( $wpdb->postmeta, 'meta_key', '_gs_pst_' );
		foreach ( $pst_keys as $k ) {
			$foreign[ 'post_meta:' . $k ] = gs105_inv_meta_rows( $wpdb->postmeta, 'post_id', 'meta_id', $k );
		}
		// Kept names must never gain a renamed copy.
		$out['kept_new_names_present'] = array_values(
			array_filter(
				array( 'gend_society_gdrive_picker_api_key', 'gend_society_bp_group_id', 'gend_society_gdrive_client_id', 'gend_society_gdrive_client_secret' ),
				function ( $n ) {
					return null !== gs105_inv_option_row( $n );
				}
			)
		);

		if ( $out['network_data'] ) {
			$so = array();
			foreach ( $map['site_option'] as $old => $new ) {
				$a          = gs105_inv_site_option_row( $old );
				$b          = gs105_inv_site_option_row( $new );
				$so[ $old ] = array( 'new' => $new, 'old_row' => $a, 'new_row' => $b );
				$counts['site_option']     += $a ? 1 : 0;
				$counts_new['site_option'] += $b ? 1 : 0;
			}
			$out['site_option'] = $so;

			$um = array();
			foreach ( $map['user_meta'] as $old => $new ) {
				$um[ $old ] = array( 'new' => $new, 'unit' => 'user_meta' );
			}
			foreach ( $map['user_meta_prefix'] as $op => $np ) {
				foreach ( gs105_inv_prefix_keys( $wpdb->usermeta, 'meta_key', $op ) as $k ) {
					if ( ! isset( $um[ $k ] ) ) {
						$um[ $k ] = array( 'new' => $np . substr( $k, strlen( $op ) ), 'unit' => 'user_meta_prefix', 'prefix' => $op );
					}
				}
			}
			ksort( $um );
			foreach ( $um as $old => &$u ) {
				$u['old_rows'] = gs105_inv_meta_rows( $wpdb->usermeta, 'user_id', 'umeta_id', $old );
				$u['new_rows'] = gs105_inv_meta_rows( $wpdb->usermeta, 'user_id', 'umeta_id', $u['new'] );
				$counts[ $u['unit'] ]     += $u['old_rows'] ? $u['old_rows']['objects'] : 0;
				$counts_new[ $u['unit'] ] += $u['new_rows'] ? $u['new_rows']['objects'] : 0;
			}
			unset( $u );
			$out['user_meta'] = $um;

			$gt = gs105_inv_groupmeta_table();
			$gm = array();
			if ( '' !== $gt ) {
				foreach ( $map['group_meta'] as $old => $new ) {
					$a          = gs105_inv_meta_rows( $gt, 'group_id', 'id', $old );
					$b          = gs105_inv_meta_rows( $gt, 'group_id', 'id', $new );
					$gm[ $old ] = array( 'new' => $new, 'old_rows' => $a, 'new_rows' => $b );
					$counts['group_meta']     += $a ? $a['objects'] : 0;
					$counts_new['group_meta'] += $b ? $b['objects'] : 0;
				}
				$foreign['group_meta:gdc_site_id']       = gs105_inv_meta_rows( $gt, 'group_id', 'id', 'gdc_site_id' );
				$foreign['group_meta:gdc_membership_id'] = gs105_inv_meta_rows( $gt, 'group_id', 'id', 'gdc_membership_id' );
			}
			$out['group_meta'] = $gm;

			foreach ( array( '_gs_fund_advanced_settings', '_gs_gdrive_access_token', '_gs_gdrive_account_email', '_gs_gdrive_refresh_token', '_gs_gdrive_token_expires_at' ) as $k ) {
				$foreign[ 'user_meta:' . $k ] = gs105_inv_meta_rows( $wpdb->usermeta, 'user_id', 'umeta_id', $k );
			}
			if ( is_multisite() && ! empty( $wpdb->blogmeta ) ) {
				$foreign['blog_meta:gdc_bp_group_id'] = gs105_inv_meta_rows( $wpdb->blogmeta, 'blog_id', 'meta_id', 'gdc_bp_group_id' );
			}
		}

		foreach ( $foreign as $k => $v ) {
			$foreign[ $k ] = $v ? $v['md5'] : null;
		}
		ksort( $foreign );
		$out['foreign']    = $foreign;
		$out['counts']     = $counts;
		$out['counts_new'] = $counts_new;

		$routes = 0;
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( 0 === strpos( $route, '/gs/v1' ) ) {
				++$routes;
			}
		}
		$out['rest_gs_v1_routes'] = $routes;
		return $out;
	}
}

if ( ! defined( 'GS105_INVENTORY_LIB' ) ) {
	echo wp_json_encode( gs105_inventory(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
