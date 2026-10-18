<?php
/**
 * Migration pre-flight: size walk, the single exclude list, migration readiness items and the report to gend.me.
 *
 * Plan 110-09 (MIG-02 / M1 / M4). Manifest: tier customer, needs [ 'standalone' ]. Functions only, plus one hook
 * (the readiness Re-run handler's done action) that reports a migration-context result to gend.me. The report is
 * the only remote call here and goes through gend_society_remote_membership_call(), so it is consent-gated and only
 * runs on a connected install.
 *
 * Exclude list (M1): one place, gend_society_migration_excludes(). The exporter (Phase 110-13/14) reuses it.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_MIGRATION_SIZING_OPTION' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_SIZING_OPTION', 'gend_society_migration_sizing' );
}

if ( ! defined( 'GEND_SOCIETY_MIGRATION_ANSWER_OPTION' ) ) {
	// Last answer of POST /install/{id}/migration/preflight: { at, plan_fit, rest_external }.
	define( 'GEND_SOCIETY_MIGRATION_ANSWER_OPTION', 'gend_society_migration_preflight_answer' );
}

// Export throughput used for the time estimate: the MINIMUM of the three Phase 110-03 shared-host limit beds
// (C:/tmp/livepull/phase110/w0/limits/throughput.txt: gz_mb_s=70.7, gz_media_l1_mb_s=19.5, walk_entries_s=77171),
// divided by 4 because those beds ran on local disk, an upper bound for a shared host.
if ( ! defined( 'GEND_SOCIETY_MIGRATION_RATE_DB_BPS' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_RATE_DB_BPS', (int) ( 70.7 * 1048576 / 4 ) );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_RATE_FILES_BPS' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_RATE_FILES_BPS', (int) ( 19.5 * 1048576 / 4 ) );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_RATE_ENTRIES_PS' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_RATE_ENTRIES_PS', (int) ( 77171 / 4 ) );
}

if ( ! function_exists( 'gend_society_migration_excludes' ) ) {
	/**
	 * What a migration never copies from wp-content (M1). Patterns are shell wildcards (fnmatch).
	 *
	 * - dirs: directories, relative to wp-content (top level, or the one nested path listed).
	 * - dropins: files directly in wp-content.
	 * - mu_plugins: files or folders in wp-content/mu-plugins.
	 * - plugins: plugin folders (or single-file plugins without .php) in wp-content/plugins. The container image
	 *   ships its own gend-society, so the customer's copy is never carried over.
	 * - max_file_bytes: larger files are skipped (and reported).
	 *
	 * @return array{dirs:string[],dropins:string[],mu_plugins:string[],plugins:string[],max_file_bytes:int}
	 */
	function gend_society_migration_excludes(): array {
		return array(
			'dirs'           => array( 'cache', 'upgrade', 'backups*', 'ai1wm-backups', 'updraft', 'wflogs', 'et-cache', 'litespeed', 'w3tc-config', 'wp-rocket-config', 'gend-society-migration-*', 'uploads/backup-guard' ),
			'dropins'        => array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'sunrise.php' ),
			'mu_plugins'     => array( 'endurance-*', 'bluehost-*', 'sso.php', 'wpengine-common*', 'mu-plugin.php', 'slt-force-strong-passwords*', 'force-strong-passwords*', 'gd-system-plugin*', 'kinsta-mu-plugins*', 'wpcomsh*', 'sg-*', 'pantheon*', 'flywheel*' ),
			'plugins'        => array( 'gend-society*', 'sg-cachepress', 'sg-security', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'wp-rocket', 'breeze', 'endurance-page-cache', 'mojo-marketplace-wp-plugin', 'bluehost-wordpress-plugin', 'wp-plugin-bluehost', 'gd-system-plugin', 'wpengine-*' ),
			'max_file_bytes' => 2147483648,
		);
	}
}

if ( ! function_exists( 'gend_society_migration_match' ) ) {
	/**
	 * Shell wildcard match (fnmatch() when available).
	 *
	 * @param string $pattern Pattern.
	 * @param string $name    Name.
	 */
	function gend_society_migration_match( string $pattern, string $name ): bool {
		if ( function_exists( 'fnmatch' ) ) {
			return fnmatch( $pattern, $name );
		}
		$re = '/^' . str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), preg_quote( $pattern, '/' ) ) . '$/';
		return (bool) preg_match( $re, $name );
	}
}

if ( ! function_exists( 'gend_society_migration_excluded' ) ) {
	/**
	 * Why a wp-content entry is excluded ('' when it is copied).
	 *
	 * @param string $rel    Path relative to wp-content, '/' separated.
	 * @param bool   $is_dir True for a directory.
	 * @return string 'dir' | 'dropin' | 'mu_plugin' | 'plugin' | ''.
	 */
	function gend_society_migration_excluded( string $rel, bool $is_dir ): string {
		$ex    = gend_society_migration_excludes();
		$parts = explode( '/', trim( $rel, '/' ) );
		$n     = count( $parts );
		if ( $is_dir ) {
			foreach ( $ex['dirs'] as $p ) {
				$pp = explode( '/', $p );
				if ( count( $pp ) === $n ) {
					$all = true;
					foreach ( $pp as $i => $seg ) {
						$all = $all && gend_society_migration_match( $seg, $parts[ $i ] );
					}
					if ( $all ) {
						return 'dir';
					}
				}
			}
		} elseif ( 1 === $n && in_array( $parts[0], $ex['dropins'], true ) ) {
			return 'dropin';
		}
		if ( 2 === $n && 'mu-plugins' === $parts[0] ) {
			foreach ( $ex['mu_plugins'] as $p ) {
				if ( gend_society_migration_match( $p, $parts[1] ) ) {
					return 'mu_plugin';
				}
			}
		}
		if ( 2 === $n && 'plugins' === $parts[0] ) {
			$name = $is_dir ? $parts[1] : preg_replace( '/\.php$/', '', $parts[1] );
			foreach ( $ex['plugins'] as $p ) {
				if ( gend_society_migration_match( $p, (string) $name ) ) {
					return 'plugin';
				}
			}
		}
		return '';
	}
}

if ( ! function_exists( 'gend_society_migration_ini' ) ) {
	/**
	 * A PHP ini value as the migration checks see it (filter gend_society_migration_ini for tests).
	 *
	 * @param string $key Ini key.
	 */
	function gend_society_migration_ini( string $key ): string {
		return (string) apply_filters( 'gend_society_migration_ini', (string) ini_get( $key ), $key );
	}
}

if ( ! function_exists( 'gend_society_migration_db_size' ) ) {
	/**
	 * Size of this install's tables: exact prefix only. Another install in the same database whose prefix starts
	 * with ours (for example wp_blog2_ next to wp_) is recognised by its own options + posts tables and left out.
	 *
	 * @return array{bytes:int,tables:int,other_prefixes:string[]}
	 */
	function gend_society_migration_db_size(): array {
		global $wpdb;
		$prefix = (string) $wpdb->base_prefix;
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema, read once per check.
			$wpdb->prepare(
				'SELECT TABLE_NAME AS t, (DATA_LENGTH + INDEX_LENGTH) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		$names = array();
		foreach ( (array) $rows as $row ) {
			$names[ (string) $row['t'] ] = (int) $row['b'];
		}
		$others = array();
		$quoted = preg_quote( $prefix, '/' );
		foreach ( array_keys( $names ) as $t ) {
			if ( preg_match( '/^' . $quoted . '(.+_)options$/', $t, $m ) && isset( $names[ $prefix . $m[1] . 'posts' ] ) ) {
				if ( is_multisite() && preg_match( '/^\d+_$/', $m[1] ) ) {
					continue; // a blog of this network, not another install.
				}
				$others[] = $prefix . $m[1];
			}
		}
		$bytes  = 0;
		$tables = 0;
		foreach ( $names as $t => $b ) {
			foreach ( $others as $o ) {
				if ( 0 === strpos( $t, $o ) ) {
					continue 2;
				}
			}
			$bytes += $b;
			++$tables;
		}
		return array(
			'bytes'          => $bytes,
			'tables'         => $tables,
			'other_prefixes' => $others,
		);
	}
}

if ( ! function_exists( 'gend_society_migration_sizing_reset' ) ) {
	/**
	 * Forget the size walk (the next step starts over).
	 */
	function gend_society_migration_sizing_reset(): void {
		delete_option( GEND_SOCIETY_MIGRATION_SIZING_OPTION );
	}
}

if ( ! function_exists( 'gend_society_migration_sizing_state' ) ) {
	/**
	 * Stored walk state, or null.
	 *
	 * @return array|null
	 */
	function gend_society_migration_sizing_state(): ?array {
		$s = get_option( GEND_SOCIETY_MIGRATION_SIZING_OPTION, null );
		return ( is_array( $s ) && 1 === (int) ( $s['v'] ?? 0 ) ) ? $s : null;
	}
}

if ( ! function_exists( 'gend_society_migration_bytes' ) ) {
	/**
	 * A memory_limit style value in bytes (-1 / '' = unlimited = 0).
	 *
	 * @param string $v Ini value.
	 */
	function gend_society_migration_bytes( string $v ): int {
		$v = trim( $v );
		if ( '' === $v || '-1' === $v ) {
			return 0;
		}
		$n    = (int) $v;
		$unit = strtolower( substr( $v, -1 ) );
		$mult = array(
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
		);
		return isset( $mult[ $unit ] ) ? $n * $mult[ $unit ] : $n;
	}
}

if ( ! function_exists( 'gend_society_migration_sizing_step' ) ) {
	/**
	 * One budgeted step of the wp-content size walk (resumable; the cursor lives in option
	 * gend_society_migration_sizing).
	 *
	 * The walk keeps a stack of directories still to read plus the position inside the current one, so a resume
	 * re-reads one directory listing at most (never the whole tree). Symlinks are not followed. Excluded entries
	 * (gend_society_migration_excludes()) and files above max_file_bytes are not counted. The budget is
	 * min( $budget_s, max_execution_time - 8 ) seconds of wall time; the step also stops at 60% of memory_limit.
	 *
	 * @param float $budget_s Wall-clock budget in seconds.
	 * @return array{done:bool,db_bytes:int,content_bytes:int,files:int,largest:int,cursor:array}
	 */
	function gend_society_migration_sizing_step( float $budget_s = 15.0 ): array {
		$t0  = microtime( true );
		$met = (int) gend_society_migration_ini( 'max_execution_time' );
		if ( $met > 0 ) {
			$budget_s = min( $budget_s, max( 1.0, (float) $met - 8.0 ) );
		}
		$mem_limit = gend_society_migration_bytes( gend_society_migration_ini( 'memory_limit' ) );
		$max_n     = (int) apply_filters( 'gend_society_migration_sizing_max_entries', 0 );
		$ex        = gend_society_migration_excludes();
		$root      = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );

		$s = gend_society_migration_sizing_state();
		if ( null === $s ) {
			$db = gend_society_migration_db_size();
			$s  = array(
				'v'             => 1,
				'started_at'    => time(),
				'done'          => false,
				'stack'         => array( '' ),
				'dir'           => null,
				'offset'        => 0,
				'files'         => 0,
				'content_bytes' => 0,
				'largest'       => 0,
				'skipped'       => 0,
				'too_large'     => 0,
				'db_bytes'      => (int) $db['bytes'],
				'db_tables'     => (int) $db['tables'],
				'steps'         => 0,
			);
		}

		$seen = 0;
		while ( empty( $s['done'] ) ) {
			if ( null === $s['dir'] ) {
				if ( empty( $s['stack'] ) ) {
					$s['done'] = true;
					break;
				}
				$s['dir']    = (string) array_pop( $s['stack'] );
				$s['offset'] = 0;
			}
			$rel     = (string) $s['dir'];
			$abs     = '' === $rel ? $root : $root . '/' . $rel;
			$entries = is_dir( $abs ) ? @scandir( $abs ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is skipped.
			$entries = is_array( $entries ) ? array_values( array_diff( $entries, array( '.', '..' ) ) ) : array();
			$count   = count( $entries );
			$out     = false;
			for ( $i = (int) $s['offset']; $i < $count; $i++ ) {
				if ( ( $max_n > 0 && $seen >= $max_n ) || ( 0 === $seen % 64 && $seen > 0 && ( microtime( true ) - $t0 >= $budget_s || ( $mem_limit > 0 && memory_get_usage() > 0.6 * $mem_limit ) ) ) ) {
					$s['offset'] = $i;
					$out         = true;
					break;
				}
				++$seen;
				$name = $entries[ $i ];
				$r    = '' === $rel ? $name : $rel . '/' . $name;
				$a    = $root . '/' . $r;
				if ( is_link( $a ) ) {
					continue;
				}
				if ( is_dir( $a ) ) {
					if ( '' !== gend_society_migration_excluded( $r, true ) ) {
						++$s['skipped'];
					} else {
						$s['stack'][] = $r;
					}
					continue;
				}
				if ( ! is_file( $a ) ) {
					continue;
				}
				if ( '' !== gend_society_migration_excluded( $r, false ) ) {
					++$s['skipped'];
					continue;
				}
				$size = (int) @filesize( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- vanished files count as 0.
				if ( $size > (int) $ex['max_file_bytes'] ) {
					++$s['too_large'];
					continue;
				}
				++$s['files'];
				$s['content_bytes'] += $size;
				if ( $size > (int) $s['largest'] ) {
					$s['largest'] = $size;
				}
			}
			if ( $out ) {
				break;
			}
			$s['dir']    = null;
			$s['offset'] = 0;
		}
		++$s['steps'];
		update_option( GEND_SOCIETY_MIGRATION_SIZING_OPTION, $s, false );

		return array(
			'done'          => ! empty( $s['done'] ),
			'db_bytes'      => (int) $s['db_bytes'],
			'content_bytes' => (int) $s['content_bytes'],
			'files'         => (int) $s['files'],
			'largest'       => (int) $s['largest'],
			'cursor'        => array(
				'dir'    => $s['dir'],
				'offset' => (int) $s['offset'],
				'stack'  => count( (array) $s['stack'] ),
				'steps'  => (int) $s['steps'],
			),
		);
	}
}

if ( ! function_exists( 'gend_society_migration_size_label' ) ) {
	/**
	 * Human size.
	 *
	 * @param int $bytes Bytes.
	 */
	function gend_society_migration_size_label( int $bytes ): string {
		return size_format( max( 0, $bytes ), $bytes >= 1073741824 ? 1 : 0 );
	}
}

if ( ! function_exists( 'gend_society_migration_conflicts' ) ) {
	/**
	 * Host-specific code present on this site that a migration leaves behind (M1 list; the installed gend-society
	 * is expected and not listed).
	 *
	 * @return string[] Paths relative to wp-content.
	 */
	function gend_society_migration_conflicts(): array {
		$ex    = gend_society_migration_excludes();
		$found = array();
		foreach ( $ex['dropins'] as $f ) {
			if ( file_exists( WP_CONTENT_DIR . '/' . $f ) ) {
				$found[] = $f;
			}
		}
		foreach ( array( 'mu-plugins', 'plugins' ) as $dir ) {
			$list = @scandir( WP_CONTENT_DIR . '/' . $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing folder = none.
			foreach ( is_array( $list ) ? $list : array() as $name ) {
				if ( '.' === $name || '..' === $name || ( 'plugins' === $dir && gend_society_migration_match( 'gend-society*', $name ) ) ) {
					continue;
				}
				$rel = $dir . '/' . $name;
				if ( '' !== gend_society_migration_excluded( $rel, is_dir( WP_CONTENT_DIR . '/' . $rel ) ) ) {
					$found[] = $rel;
				}
			}
		}
		$prepend = (string) ini_get( 'auto_prepend_file' );
		if ( '' !== $prepend ) {
			$found[] = 'auto_prepend_file ' . basename( $prepend );
		}
		return $found;
	}
}

if ( ! function_exists( 'gend_society_migration_answer' ) ) {
	/**
	 * The hub's last pre-flight answer, or null.
	 *
	 * @return array|null
	 */
	function gend_society_migration_answer(): ?array {
		$a = get_option( GEND_SOCIETY_MIGRATION_ANSWER_OPTION, null );
		return is_array( $a ) ? $a : null;
	}
}

if ( ! function_exists( 'gend_society_migration_readiness_items' ) ) {
	/**
	 * The migration readiness rows (fixed ids, fixed order) and totals, for gend_society_readiness_collect( 'migration' ).
	 * Reads the size walk's state; never walks or calls gend.me itself.
	 *
	 * @return array{checks:array<int,array>,totals:array}
	 */
	function gend_society_migration_readiness_items(): array {
		$s     = gend_society_migration_sizing_state();
		$done  = is_array( $s ) && ! empty( $s['done'] );
		$db    = is_array( $s ) ? array(
			'bytes'  => (int) $s['db_bytes'],
			'tables' => (int) $s['db_tables'],
		) : gend_society_migration_db_size();
		$bytes = is_array( $s ) ? (int) $s['content_bytes'] : 0;
		$files = is_array( $s ) ? (int) $s['files'] : 0;
		$need  = (int) ceil( 1.2 * ( (int) $db['bytes'] + $bytes ) );
		$items = array();

		$items[] = gend_society_readiness_item(
			'db_size',
			__( 'Database size', 'gend-society' ),
			'pass',
			/* translators: 1: size, 2: number of tables. */
			sprintf( _n( '%1$s in %2$d table', '%1$s in %2$d tables', (int) $db['tables'], 'gend-society' ), gend_society_migration_size_label( (int) $db['bytes'] ), (int) $db['tables'] )
		);

		if ( $done ) {
			/* translators: 1: size, 2: number of files. */
			$msg = sprintf( _n( '%1$s in %2$s file', '%1$s in %2$s files', $files, 'gend-society' ), gend_society_migration_size_label( $bytes ), number_format_i18n( $files ) );
			if ( ! empty( $s['too_large'] ) ) {
				/* translators: %d: number of files. */
				$msg .= ' ' . sprintf( _n( '%d file over 2 GB is not copied.', '%d files over 2 GB are not copied.', (int) $s['too_large'], 'gend-society' ), (int) $s['too_large'] );
			}
			$items[] = gend_society_readiness_item( 'content_size', __( 'Files size', 'gend-society' ), empty( $s['too_large'] ) ? 'pass' : 'warn', $msg );
		} else {
			$items[] = gend_society_readiness_item(
				'content_size',
				__( 'Files size', 'gend-society' ),
				'warn',
				/* translators: %s: number of files. */
				sprintf( __( 'Still measuring (%s files so far). Re-run the checks to finish.', 'gend-society' ), number_format_i18n( $files ) )
			);
		}

		$free_raw = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on shared hosts.
		$free     = apply_filters( 'gend_society_migration_disk_free', $free_raw, (int) $db['bytes'] + $bytes );
		$label    = __( 'Free disk space', 'gend-society' );
		if ( false === $free || null === $free || ! is_numeric( $free ) ) {
			$items[] = gend_society_readiness_item( 'disk_free', $label, 'warn', __( 'This host does not report free disk space. The export needs room for a copy of the site; check your hosting panel.', 'gend-society' ) );
		} elseif ( ! $done ) {
			$items[] = gend_society_readiness_item( 'disk_free', $label, 'warn', __( 'Waiting for the size measurement.', 'gend-society' ) );
		} elseif ( (float) $free >= $need ) {
			/* translators: 1: free space, 2: needed space. */
			$items[] = gend_society_readiness_item( 'disk_free', $label, 'pass', sprintf( __( '%1$s free (the export needs about %2$s)', 'gend-society' ), gend_society_migration_size_label( (int) $free ), gend_society_migration_size_label( $need ) ) );
		} else {
			/* translators: 1: needed space, 2: free space. */
			$items[] = gend_society_readiness_item( 'disk_free', $label, 'fail', sprintf( __( 'The export needs about %1$s free, but only %2$s is free. Free up space or ask your host for more.', 'gend-society' ), gend_society_migration_size_label( $need ), gend_society_migration_size_label( (int) $free ) ), true );
		}

		$met     = (int) gend_society_migration_ini( 'max_execution_time' );
		$items[] = ( $met > 0 && $met < 20 )
			/* translators: %d: seconds. */
			? gend_society_readiness_item( 'max_execution_time', __( 'PHP time limit', 'gend-society' ), 'warn', sprintf( __( '%d seconds. The export works in shorter steps, so it takes longer.', 'gend-society' ), $met ) )
			/* translators: %s: seconds or "No limit". */
			: gend_society_readiness_item( 'max_execution_time', __( 'PHP time limit', 'gend-society' ), 'pass', $met > 0 ? sprintf( __( '%d seconds', 'gend-society' ), $met ) : __( 'No limit', 'gend-society' ) );

		$mem_raw = gend_society_migration_ini( 'memory_limit' );
		$mem     = gend_society_migration_bytes( $mem_raw );
		$items[] = ( $mem > 0 && $mem < 64 * 1048576 )
			/* translators: %s: memory limit. */
			? gend_society_readiness_item( 'memory_limit', __( 'PHP memory limit', 'gend-society' ), 'fail', sprintf( __( '%s is too low. The export needs at least 64M; ask your host to raise memory_limit.', 'gend-society' ), $mem_raw ), true )
			: gend_society_readiness_item( 'memory_limit', __( 'PHP memory limit', 'gend-society' ), 'pass', $mem > 0 ? $mem_raw : __( 'No limit', 'gend-society' ) );

		$woo     = (bool) apply_filters( 'gend_society_migration_woocommerce_active', class_exists( 'WooCommerce', false ) || defined( 'WC_VERSION' ) );
		$items[] = $woo
			? gend_society_readiness_item( 'woocommerce', __( 'WooCommerce', 'gend-society' ), 'warn', __( 'WooCommerce is active. Orders placed after the export starts are not copied (the content freeze), so start the copy at a quiet time.', 'gend-society' ) )
			: gend_society_readiness_item( 'woocommerce', __( 'WooCommerce', 'gend-society' ), 'pass', __( 'Not active', 'gend-society' ) );

		$conf    = gend_society_migration_conflicts();
		$items[] = $conf
			/* translators: %s: list of files. */
			? gend_society_readiness_item( 'plugin_conflicts', __( 'Host-specific code', 'gend-society' ), 'warn', sprintf( __( 'Left behind by the migration (it only works on your current host): %s', 'gend-society' ), implode( ', ', $conf ) ) )
			: gend_society_readiness_item( 'plugin_conflicts', __( 'Host-specific code', 'gend-society' ), 'pass', __( 'None found', 'gend-society' ) );

		$items[] = gend_society_migration_plan_fit_item( (int) $db['bytes'], $bytes );

		$est = (int) ceil( (int) $db['bytes'] / GEND_SOCIETY_MIGRATION_RATE_DB_BPS + $bytes / GEND_SOCIETY_MIGRATION_RATE_FILES_BPS + $files / GEND_SOCIETY_MIGRATION_RATE_ENTRIES_PS );
		if ( $done ) {
			/* translators: 1: duration, 2: total size. */
			$items[] = gend_society_readiness_item( 'estimate', __( 'Time estimate', 'gend-society' ), 'info', sprintf( __( 'About %1$s to export %2$s', 'gend-society' ), human_time_diff( 0, max( 60, $est ) ), gend_society_migration_size_label( (int) $db['bytes'] + $bytes ) ) );
		} else {
			$items[] = gend_society_readiness_item( 'estimate', __( 'Time estimate', 'gend-society' ), 'warn', __( 'Available once the size measurement has finished.', 'gend-society' ) );
		}

		$answer = gend_society_migration_answer();
		if ( is_array( $answer ) && is_array( $answer['rest_external'] ?? null ) ) {
			$ok      = 'pass' === ( $answer['rest_external']['status'] ?? '' );
			$hub_msg = sanitize_text_field( (string) ( $answer['rest_external']['message'] ?? '' ) );
			$items[] = $ok
				? gend_society_readiness_item( 'rest_external', __( 'Reachable from gend.me', 'gend-society' ), 'pass', __( 'gend.me reached this site\'s REST API.', 'gend-society' ) )
				: gend_society_readiness_item( 'rest_external', __( 'Reachable from gend.me', 'gend-society' ), 'fail', '' !== $hub_msg ? $hub_msg : __( 'gend.me could not reach this site\'s REST API from the internet. Allow it in your firewall or security plugin.', 'gend-society' ), true );
		}

		return array(
			'checks' => $items,
			'totals' => array(
				'db_bytes'         => (int) $db['bytes'],
				'content_bytes'    => $bytes,
				'files'            => $files,
				'largest'          => is_array( $s ) ? (int) $s['largest'] : 0,
				'est_seconds'      => $done ? $est : 0,
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
		);
	}
}

if ( ! function_exists( 'gend_society_migration_plan_fit_item' ) ) {
	/**
	 * plan_fit row: the hub's answer when there is one, else the sizes this site needs.
	 *
	 * @param int $db_bytes      Database bytes.
	 * @param int $content_bytes Files bytes.
	 */
	function gend_society_migration_plan_fit_item( int $db_bytes, int $content_bytes ): array {
		$label  = __( 'Plan fit', 'gend-society' );
		$answer = gend_society_migration_answer();
		$fit    = is_array( $answer ) && is_array( $answer['plan_fit'] ?? null ) ? $answer['plan_fit'] : null;
		$needs  = sprintf(
			/* translators: 1: files size, 2: database size. */
			__( 'This site needs about %1$s of file storage and %2$s of database.', 'gend-society' ),
			gend_society_migration_size_label( $content_bytes ),
			gend_society_migration_size_label( $db_bytes )
		);
		$status = is_array( $fit ) ? (string) ( $fit['status'] ?? '' ) : '';
		if ( 'pass' === $status ) {
			return gend_society_readiness_item( 'plan_fit', $label, 'pass', __( 'Your plans have room for this site.', 'gend-society' ) . ' ' . $needs );
		}
		if ( 'fail' === $status ) {
			$name = sanitize_text_field( (string) ( $fit['suggest']['name'] ?? '' ) );
			$msg  = '' !== $name
				/* translators: %s: plan name. */
				? sprintf( __( 'Your plan is too small for this site. Choose %s in Change Plan.', 'gend-society' ), $name )
				: __( 'Your plan is too small for this site. Choose a larger plan in Change Plan.', 'gend-society' );
			return gend_society_readiness_item( 'plan_fit', $label, 'fail', $msg . ' ' . $needs, true );
		}
		return gend_society_readiness_item( 'plan_fit', $label, 'info', $needs . ' ' . __( 'gend.me checks it against your plans.', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_migration_preflight_body' ) ) {
	/**
	 * The report body: { format, context, checked_at, checks, totals, hash }. hash = sha256 of the JSON of
	 * { checks, totals } (wp_json_encode, unescaped slashes and unicode). The hub-made rest_external row is not sent.
	 *
	 * @param array $result Migration-context readiness result.
	 */
	function gend_society_migration_preflight_body( array $result ): array {
		$checks = array();
		foreach ( (array) ( $result['checks'] ?? array() ) as $c ) {
			if ( ! is_array( $c ) || 'rest_external' === ( $c['id'] ?? '' ) ) {
				continue;
			}
			$checks[] = array(
				'id'      => (string) ( $c['id'] ?? '' ),
				'label'   => (string) ( $c['label'] ?? '' ),
				'status'  => (string) ( $c['status'] ?? '' ),
				'message' => (string) ( $c['message'] ?? '' ),
				'blocker' => ! empty( $c['blocker'] ),
			);
		}
		$t      = (array) ( $result['totals'] ?? array() );
		$totals = array(
			'db_bytes'         => (int) ( $t['db_bytes'] ?? 0 ),
			'content_bytes'    => (int) ( $t['content_bytes'] ?? 0 ),
			'files'            => (int) ( $t['files'] ?? 0 ),
			'largest'          => (int) ( $t['largest'] ?? 0 ),
			'est_seconds'      => (int) ( $t['est_seconds'] ?? 0 ),
			'wp_cron_disabled' => ! empty( $t['wp_cron_disabled'] ),
		);
		return array(
			'format'     => 1,
			'context'    => 'migration',
			'checked_at' => (int) ( $result['checked_at'] ?? time() ),
			'checks'     => $checks,
			'totals'     => $totals,
			'hash'       => hash(
				'sha256',
				(string) wp_json_encode(
					array(
						'checks' => $checks,
						'totals' => $totals,
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			),
		);
	}
}

if ( ! function_exists( 'gend_society_migration_preflight_report' ) ) {
	/**
	 * Send a migration-context result to gend.me (POST /install/{id}/migration/preflight) and merge the hub's
	 * plan_fit and rest_external answers into the stored migration result. Only on a connected install with consent,
	 * never for the welcome context.
	 *
	 * @param array $result Result of gend_society_readiness_collect( 'migration' ).
	 * @return array|WP_Error The hub answer.
	 */
	function gend_society_migration_preflight_report( array $result ) {
		if ( 'migration' !== ( $result['context'] ?? '' ) ) {
			return new WP_Error( 'gend_society_preflight_context', __( 'Only migration checks are reported.', 'gend-society' ) );
		}
		$connected = function_exists( 'gend_society_connect_is_connected' ) ? gend_society_connect_is_connected() : '' !== (string) get_option( 'gend_society_install_token', '' );
		if ( ! $connected || ! function_exists( 'gend_society_remote_membership_call' ) ) {
			return new WP_Error( 'gend_society_preflight_not_connected', __( 'Connect this site to gend.me first.', 'gend-society' ) );
		}
		if ( function_exists( 'gend_society_remote_allowed' ) && ! gend_society_remote_allowed( 'migration' ) ) {
			return new WP_Error( 'gend_society_preflight_no_consent', __( 'This site has not agreed to talk to gend.me.', 'gend-society' ) );
		}
		$body   = gend_society_migration_preflight_body( $result );
		$answer = gend_society_remote_membership_call( 'migration/preflight', $body, 'POST' );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$keep = array(
			'at'            => time(),
			'hash'          => $body['hash'],
			'plan_fit'      => is_array( $answer['plan_fit'] ?? null ) ? $answer['plan_fit'] : null,
			'rest_external' => is_array( $answer['rest_external'] ?? null ) ? $answer['rest_external'] : null,
		);
		update_option( GEND_SOCIETY_MIGRATION_ANSWER_OPTION, $keep, false );
		if ( function_exists( 'gend_society_readiness_collect' ) && defined( 'GEND_SOCIETY_READINESS_MIGRATION_OPTION' ) ) {
			$merged               = gend_society_readiness_collect( 'migration' );
			$merged['checked_at'] = (int) ( $result['checked_at'] ?? time() );
			update_option( GEND_SOCIETY_READINESS_MIGRATION_OPTION, $merged, false );
		}
		return $answer;
	}
}

if ( ! function_exists( 'gend_society_migration_on_rerun' ) ) {
	/**
	 * After "Re-run checks" in the migration context: report the result when the install is connected.
	 *
	 * @param string $context Readiness context.
	 * @param array  $result  Result.
	 */
	function gend_society_migration_on_rerun( string $context, array $result ): void {
		if ( 'migration' === $context ) {
			gend_society_migration_preflight_report( $result );
		}
	}
}

add_action( 'gend_society_readiness_rerun_done', 'gend_society_migration_on_rerun', 10, 2 );
