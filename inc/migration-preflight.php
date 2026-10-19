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

/* ---- 110-12: Start / Schedule / Cancel / Retry, progress poll, job receipt (MIG-03/08/12/14, M2, M7) ---------- */

if ( ! defined( 'GEND_SOCIETY_MIGRATION_JOB_OPTION' ) ) {
	// The export job the hub minted at Start: { job_id, export_secret, received_at } (autoload no). The exporter
	// (110-13) listens to gend_society_migration_job_received and extends this option.
	define( 'GEND_SOCIETY_MIGRATION_JOB_OPTION', 'gend_society_migration_job' );
}
if ( ! defined( 'GEND_SOCIETY_MIGRATION_STATE_OPTION' ) ) {
	// Last answer of GET /install/{id}/migration/state (or start / cancel / retry), never the secret: { at, state }.
	define( 'GEND_SOCIETY_MIGRATION_STATE_OPTION', 'gend_society_migration_state_last' );
}

if ( ! function_exists( 'gend_society_migration_texts' ) ) {
	/** The Start step copy (the hub card's wording, 110-11; "keep this tab open" for wp-admin). */
	function gend_society_migration_texts(): array {
		return array(
			'freeze'     => __( 'Changes made on your current site after the copy starts (orders, comments, posts) will not be copied. Copying late changes comes later, before you switch your domain.', 'gend-society' ),
			'waiting'    => __( 'Waiting for your site to start the copy. Keep this tab open.', 'gend-society' ),
			'needs_cron' => __( 'Scheduling needs WP-Cron on your site; start now and keep this tab open.', 'gend-society' ),
			'ack'        => __( 'Tick "I understand" first.', 'gend-society' ),
			'cancel'     => __( 'Cancel this migration? Your plans stay active and you can start again later.', 'gend-society' ),
			'measuring'  => __( 'Your site is still being measured. Try again in a minute.', 'gend-society' ),
		);
	}
}

if ( ! function_exists( 'gend_society_migration_running_states' ) ) {
	/** States in which a started migration runs to completion (M2: no Cancel). */
	function gend_society_migration_running_states(): array {
		return array( 'preparing', 'exporting', 'importing', 'verifying' );
	}
}

if ( ! function_exists( 'gend_society_migration_state_last' ) ) {
	/** The last state answer stored by a poll or a button ([] when none). */
	function gend_society_migration_state_last(): array {
		$s = get_option( GEND_SOCIETY_MIGRATION_STATE_OPTION, null );
		return is_array( $s ) && is_array( $s['state'] ?? null ) ? $s['state'] : array();
	}
}

if ( ! function_exists( 'gend_society_migration_view' ) ) {
	/**
	 * The migration block the dashboard shows: the /membership payload's migration (state, index, can_start,
	 * can_retry, can_cancel, scheduled_at, error) plus waiting_for_site and schedule_allowed from the last state
	 * answer (waiting only when it describes the same state). Running states never offer Start or Cancel (M2).
	 *
	 * @param array $payload /install/{id}/membership payload.
	 */
	function gend_society_migration_view( array $payload ): array {
		$m    = is_array( $payload['migration'] ?? null ) ? $payload['migration'] : array();
		$last = gend_society_migration_state_last();
		$v    = array(
			'state'            => (string) ( $m['state'] ?? '' ),
			'index'            => (int) ( $m['index'] ?? 0 ),
			'live'             => ! empty( $m['live'] ),
			'can_start'        => ! empty( $m['can_start'] ),
			'can_retry'        => ! empty( $m['can_retry'] ),
			'can_cancel'       => ! empty( $m['can_cancel'] ),
			'scheduled_at'     => (int) ( $m['scheduled_at'] ?? 0 ),
			'error'            => (string) ( $m['error'] ?? '' ),
			'waiting_for_site' => false,
			'schedule_allowed' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
		);
		if ( $last && (string) ( $last['state'] ?? '' ) === $v['state'] ) {
			$v['waiting_for_site'] = ! empty( $last['waiting_for_site'] );
		}
		if ( $last && array_key_exists( 'schedule_allowed', $last ) && empty( $last['schedule_allowed'] ) ) {
			$v['schedule_allowed'] = false;
		}
		if ( in_array( $v['state'], gend_society_migration_running_states(), true ) ) {
			$v['can_cancel'] = false;
			$v['can_start']  = false;
		}
		return $v;
	}
}

if ( ! function_exists( 'gend_society_migration_view_shows' ) ) {
	/** The header progress shows while armed, scheduled, running or failed; never for a free install or after Live. */
	function gend_society_migration_view_shows( array $v ): bool {
		if ( $v['live'] || $v['index'] >= 5 || 'complete' === $v['state'] ) {
			return false;
		}
		return $v['index'] > 0 || $v['can_start'] || in_array( $v['state'], array( 'scheduled', 'failed' ), true );
	}
}

if ( ! function_exists( 'gend_society_migration_view_polls' ) ) {
	/** Repaint every 15 s while armed, scheduled or running; stop when Live or failed. */
	function gend_society_migration_view_polls( array $v ): bool {
		return gend_society_migration_view_shows( $v ) && 'failed' !== $v['state'];
	}
}

if ( ! function_exists( 'gend_society_migration_receive' ) ) {
	/**
	 * Handle a start / retry / cancel / state answer from gend.me. A cleanup flag fires gend_society_migration_cleanup
	 * (with the stored job) and drops the job; an export secret for a job not stored yet stores it (option
	 * gend_society_migration_job, autoload no) and fires gend_society_migration_job_received once (a different stored
	 * job is cleaned up first). The state is kept without the secret.
	 *
	 * @param array $answer Hub answer.
	 * @return string 'received' | 'cleaned' | ''.
	 */
	function gend_society_migration_receive( array $answer ): string {
		$did = '';
		$old = get_option( GEND_SOCIETY_MIGRATION_JOB_OPTION, false );
		if ( ! empty( $answer['cleanup'] ) && false !== $old ) {
			// Listeners (the exporter) still see the job, e.g. its staging dir, then it is gone.
			do_action( 'gend_society_migration_cleanup', is_array( $old ) ? $old : array() );
			delete_option( GEND_SOCIETY_MIGRATION_JOB_OPTION );
			$did = 'cleaned';
		}
		$secret = (string) ( $answer['export_secret'] ?? '' );
		$job_id = (string) ( $answer['job_id'] ?? '' );
		if ( preg_match( '/^[0-9a-f]{64}$/', $secret ) && preg_match( '/^[0-9a-f-]{36}$/', $job_id ) ) {
			$cur = get_option( GEND_SOCIETY_MIGRATION_JOB_OPTION, null );
			if ( ! is_array( $cur ) || ( $cur['job_id'] ?? '' ) !== $job_id || ! hash_equals( (string) ( $cur['export_secret'] ?? '' ), $secret ) ) {
				$job = array(
					'job_id'        => $job_id,
					'export_secret' => $secret,
					'received_at'   => time(),
				);
				if ( is_array( $cur ) && ( $cur['job_id'] ?? '' ) !== $job_id ) {
					// A new job (Retry) replaces the old one: its staging is wiped first.
					do_action( 'gend_society_migration_cleanup', $cur );
				}
				delete_option( GEND_SOCIETY_MIGRATION_JOB_OPTION );
				add_option( GEND_SOCIETY_MIGRATION_JOB_OPTION, $job, '', false );
				do_action( 'gend_society_migration_job_received', $job );
				$did = 'received';
			}
		}
		if ( isset( $answer['state'] ) && is_string( $answer['state'] ) ) {
			unset( $answer['export_secret'] );
			update_option(
				GEND_SOCIETY_MIGRATION_STATE_OPTION,
				array(
					'at'    => time(),
					'state' => $answer,
				),
				false
			);
		}
		do_action( 'gend_society_migration_state_received', $answer ); // 110-15: a pending ready report goes out again
		return $did;
	}
}

if ( ! function_exists( 'gend_society_migration_report_is_stale' ) ) {
	/** gend.me's copy of the pre-flight is missing or older than 1 h. */
	function gend_society_migration_report_is_stale(): bool {
		$a = gend_society_migration_answer();
		return ! is_array( $a ) || (int) ( $a['at'] ?? 0 ) < time() - HOUR_IN_SECONDS;
	}
}

if ( ! function_exists( 'gend_society_migration_budget' ) ) {
	/** Seconds this request may spend on the size walk: min( $cap, max_execution_time - 8 ). */
	function gend_society_migration_budget( float $cap ): float {
		$met = (int) ini_get( 'max_execution_time' );
		return $met > 0 ? max( 2.0, min( $cap, $met - 8 ) ) : $cap;
	}
}

if ( ! function_exists( 'gend_society_migration_fresh_preflight' ) ) {
	/**
	 * A fresh migration pre-flight: the size walk from the start within $budget seconds, then the migration checks.
	 * Without blockers it is reported to gend.me, so the hash the hub stored is the one Start sends.
	 *
	 * @param float $budget Seconds for the size walk.
	 * @return array|WP_Error { result, hash, answer (null when blockers stopped the report) }
	 */
	function gend_society_migration_fresh_preflight( float $budget ) {
		gend_society_migration_sizing_reset();
		$deadline = microtime( true ) + max( 1.0, $budget );
		do {
			$step = gend_society_migration_sizing_step( max( 1.0, min( 15.0, $deadline - microtime( true ) ) ) );
		} while ( empty( $step['done'] ) && microtime( true ) < $deadline );
		if ( empty( $step['done'] ) ) {
			return new WP_Error( 'gend_society_migration_measuring', gend_society_migration_texts()['measuring'], array( 'status' => 409 ) );
		}
		$result = gend_society_readiness_run( true, 'migration' );
		$out    = array(
			'result' => $result,
			'hash'   => gend_society_migration_preflight_body( $result )['hash'],
			'answer' => null,
		);
		if ( gend_society_readiness_blockers( $result ) ) {
			return $out;
		}
		$answer = gend_society_migration_preflight_report( $result );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$out['answer'] = $answer;
		return $out;
	}
}

if ( ! function_exists( 'gend_society_migration_report_if_stale' ) ) {
	/**
	 * Keep gend.me's copy of the pre-flight current before the container wizard opens (M4/M7): measure and report
	 * again when the stored answer is missing or older than 1 h (blockers are reported too, so the wizard shows them).
	 *
	 * @param float $budget Seconds for the size walk.
	 * @return array{sent:bool,reason:string}
	 */
	function gend_society_migration_report_if_stale( float $budget = 15.0 ): array {
		if ( ! gend_society_migration_report_is_stale() ) {
			return array(
				'sent'   => false,
				'reason' => 'fresh',
			);
		}
		$r = gend_society_migration_fresh_preflight( gend_society_migration_budget( $budget ) );
		if ( is_wp_error( $r ) ) {
			return array(
				'sent'   => false,
				'reason' => $r->get_error_code(),
			);
		}
		if ( null === $r['answer'] ) {
			$a = gend_society_migration_preflight_report( $r['result'] );
			return array(
				'sent'   => ! is_wp_error( $a ),
				'reason' => 'blockers',
			);
		}
		return array(
			'sent'   => true,
			'reason' => 'stale',
		);
	}
}

if ( ! function_exists( 'gend_society_migration_do' ) ) {
	/**
	 * The admin-ajax actions behind the header buttons (callers check manage_options + nonce).
	 *
	 * @param string $verb start | cancel | retry | state | report_if_stale.
	 * @param array  $req  start: freeze_ack ('1'), start_at (UTC seconds, optional).
	 * @return array|WP_Error
	 */
	function gend_society_migration_do( string $verb, array $req = array() ) {
		$t = gend_society_migration_texts();
		switch ( $verb ) {
			case 'start':
				if ( '1' !== (string) ( $req['freeze_ack'] ?? '' ) ) {
					return new WP_Error( 'gend_society_migration_freeze', $t['ack'], array( 'status' => 422 ) );
				}
				$start_at = (int) ( $req['start_at'] ?? 0 );
				$payload  = gend_society_remote_membership_get_cached();
				$view     = gend_society_migration_view( is_array( $payload ) ? $payload : array() );
				if ( $start_at > 0 && ! $view['schedule_allowed'] ) {
					return new WP_Error( 'gend_society_migration_needs_cron', $t['needs_cron'], array( 'status' => 409 ) );
				}
				$fresh = gend_society_migration_fresh_preflight( gend_society_migration_budget( 20.0 ) );
				if ( is_wp_error( $fresh ) ) {
					return $fresh;
				}
				$blockers = gend_society_readiness_blockers( $fresh['result'] );
				if ( $blockers ) {
					$msgs = array();
					foreach ( $blockers as $c ) {
						$msgs[] = (string) ( $c['message'] ?? '' );
					}
					return new WP_Error(
						'gend_society_migration_blockers',
						/* translators: %s: blocker messages. */
						sprintf( __( 'Fix this on your site before you start: %s', 'gend-society' ), implode( ' ', $msgs ) ),
						array( 'status' => 409 )
					);
				}
				$body = array(
					'freeze_ack'     => 1,
					'preflight_hash' => $fresh['hash'],
				);
				if ( $start_at > 0 ) {
					$body['start_at'] = $start_at;
				}
				$answer = gend_society_remote_membership_call( 'migration/start', $body, 'POST' );
				break;
			case 'cancel':
			case 'retry':
				$answer = gend_society_remote_membership_call( 'migration/' . $verb, array(), 'POST' );
				break;
			case 'state':
				return gend_society_migration_poll_once( true );
			case 'report_if_stale':
				return gend_society_migration_report_if_stale();
			default:
				return new WP_Error( 'gend_society_migration_verb', __( 'Unknown action.', 'gend-society' ) );
		}
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		gend_society_migration_receive( (array) $answer );
		if ( function_exists( 'gend_society_remote_membership_invalidate' ) ) {
			gend_society_remote_membership_invalidate();
		}
		gend_society_migration_poll_schedule();
		return array( 'state' => (string) ( $answer['state'] ?? '' ) );
	}
}

if ( ! function_exists( 'gend_society_migration_poll_once' ) ) {
	/**
	 * One repaint poll: the /membership payload through the ETag-aware plan cache (If-None-Match; updates the
	 * option cache the panel reads) and, while a migration shows or a job is stored, GET migration/state (job
	 * receipt, cleanup, waiting line, scheduling).
	 *
	 * @param bool $with_html Also render the header progress block.
	 * @return array{migration:array,html:string,poll:bool}
	 */
	function gend_society_migration_poll_once( bool $with_html = false ): array {
		$payload = null;
		if ( function_exists( 'gend_society_plan_membership' ) && function_exists( 'gend_society_plan_cache_expire' ) ) {
			gend_society_plan_cache_expire( 'gend_society_remote_membership_cache' );
			$res     = gend_society_plan_membership( false );
			$payload = is_array( $res['payload'] ?? null ) ? $res['payload'] : null;
		}
		if ( ! is_array( $payload ) ) {
			$payload = gend_society_remote_membership_get_cached();
		}
		$payload = is_array( $payload ) ? $payload : array();
		$view    = gend_society_migration_view( $payload );
		if ( gend_society_migration_view_shows( $view ) || false !== get_option( GEND_SOCIETY_MIGRATION_JOB_OPTION, false ) ) {
			$state = gend_society_remote_membership_call( 'migration/state', array(), 'GET' );
			if ( is_array( $state ) ) {
				gend_society_migration_receive( $state );
				$view = gend_society_migration_view( $payload );
			}
		}
		return array(
			'migration' => $view,
			'html'      => $with_html ? gend_society_migration_progress_html( $payload ) : '',
			'poll'      => gend_society_migration_view_polls( $view ),
		);
	}
}

if ( ! function_exists( 'gend_society_migration_poll_schedule' ) ) {
	/** Keep the 5-minute WP-Cron poll queued (connected + consent only; cleared otherwise). */
	function gend_society_migration_poll_schedule(): void {
		if ( ! function_exists( 'gend_society_dashboard_standalone_paired' ) || ! gend_society_dashboard_standalone_paired() ) {
			wp_clear_scheduled_hook( 'gend_society_migration_poll' );
			return;
		}
		if ( ! wp_next_scheduled( 'gend_society_migration_poll' ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'gend_society_migration_poll' );
		}
	}
}

if ( ! function_exists( 'gend_society_migration_poll_cron' ) ) {
	/**
	 * WP-Cron poll every 5 min while armed / scheduled / running: GET migration/state receives the job after a
	 * hub-side or scheduled start, and the cleanup flag. It stops (no new event) otherwise.
	 */
	function gend_society_migration_poll_cron(): void {
		if ( ! function_exists( 'gend_society_dashboard_standalone_paired' ) || ! gend_society_dashboard_standalone_paired() ) {
			return;
		}
		$state = gend_society_remote_membership_call( 'migration/state', array(), 'GET' );
		if ( ! is_array( $state ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'gend_society_migration_poll' );
			return;
		}
		gend_society_migration_receive( $state );
		$s = (string) ( $state['state'] ?? '' );
		if ( ! empty( $state['can_start'] ) || 'scheduled' === $s || in_array( $s, gend_society_migration_running_states(), true ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'gend_society_migration_poll' );
		}
	}
}

if ( ! function_exists( 'gend_society_migration_cta_mode' ) ) {
	/**
	 * What the standalone Storage card Start Migration CTA does (M7): 'wizard' before payment (the container wizard
	 * popup), 'start' once armed ("Start migration" opens the inline Start step), '' otherwise (scheduled, running,
	 * failed, Live).
	 *
	 * @param array $payload /membership payload.
	 */
	function gend_society_migration_cta_mode( array $payload ): string {
		$v = gend_society_migration_view( $payload );
		if ( $v['live'] || $v['index'] >= 5 ) {
			return '';
		}
		if ( $v['can_start'] ) {
			return 'start';
		}
		return ( 0 === $v['index'] && in_array( $v['state'], array( '', 'idle' ), true ) ) ? 'wizard' : '';
	}
}

if ( ! function_exists( 'gend_society_migration_wizard_wait_url' ) ) {
	/** The local page the CTA popup opens first (it keeps the pre-checkout card current, then goes to the wizard). */
	function gend_society_migration_wizard_wait_url(): string {
		return add_query_arg(
			array(
				'action'   => 'gend_society_migration_wizard_wait',
				'_wpnonce' => wp_create_nonce( 'gend_society_migration_wizard_wait' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}
}

if ( ! function_exists( 'gend_society_migration_wizard_wait_response' ) ) {
	/**
	 * The wait page. A fresh report goes straight to the wizard (redirect). A stale or missing one first shows
	 * "Checking your site…" and reloads itself with step=run, which reports (max 20 s) and then redirects: the
	 * browser keeps showing the waiting page while that request runs, and the popup itself was opened by the click.
	 *
	 * @param string $step '' | 'run'.
	 * @return array{type:string,location?:string,html?:string,sent?:bool}
	 */
	function gend_society_migration_wizard_wait_response( string $step ): array {
		$payload = gend_society_remote_membership_get_cached();
		$wizard  = is_array( $payload ) && function_exists( 'gend_society_plan_container_wizard_url' ) ? gend_society_plan_container_wizard_url( $payload ) : '';
		if ( '' === $wizard ) {
			return array(
				'type' => 'page',
				'html' => '<p>' . esc_html__( 'The gend.me container wizard is not available for this site.', 'gend-society' ) . '</p>',
			);
		}
		if ( 'run' === $step ) {
			$r = gend_society_migration_report_if_stale( 20.0 );
			return array(
				'type'     => 'redirect',
				'location' => $wizard,
				'sent'     => $r['sent'],
			);
		}
		if ( ! gend_society_migration_report_is_stale() ) {
			return array(
				'type'     => 'redirect',
				'location' => $wizard,
				'sent'     => false,
			);
		}
		$next = add_query_arg(
			array(
				'action'   => 'gend_society_migration_wizard_wait',
				'step'     => 'run',
				'_wpnonce' => wp_create_nonce( 'gend_society_migration_wizard_wait' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta http-equiv="refresh" content="0;url=' . esc_attr( $next ) . '">'
			. '<title>' . esc_html__( 'Checking your site…', 'gend-society' ) . '</title></head>'
			. '<body data-gs-migration-wait="1"><p>' . esc_html__( 'Checking your site…', 'gend-society' ) . '</p>'
			. '<p>' . esc_html__( 'The gend.me container wizard opens in a moment.', 'gend-society' ) . '</p></body></html>';
		return array(
			'type' => 'page',
			'html' => $html,
		);
	}
}

if ( ! function_exists( 'gend_society_migration_progress_html' ) ) {
	/**
	 * The standalone header progress (M2): the same .gs-mship-progress steps as hub sites, with the controls inside
	 * it: Start and the inline Start step (freeze warning, I understand, the local readiness card, Start now /
	 * Schedule for, Confirm), the scheduled time, the waiting line, Cancel (armed / scheduled / failed) and Retry
	 * (failed). '' when nothing shows (Connected/free, Live).
	 *
	 * @param array $payload /membership payload.
	 */
	function gend_society_migration_progress_html( array $payload ): string {
		$v = gend_society_migration_view( $payload );
		if ( ! gend_society_migration_view_shows( $v ) ) {
			return '';
		}
		$t     = gend_society_migration_texts();
		$steps = array(
			'prepare'   => __( 'Prepare', 'gend-society' ),
			'export'    => __( 'Export', 'gend-society' ),
			'provision' => __( 'Provision', 'gend-society' ),
			'verify'    => __( 'Verify', 'gend-society' ),
			'live'      => __( 'Live', 'gend-society' ),
		);
		$ix    = max( 1, min( 4, $v['index'] ) );
		$last  = gend_society_readiness_last( 'migration' );
		$block = $last ? gend_society_readiness_blockers( $last ) : array();
		$sched = $v['schedule_allowed'];
		$state = '' !== $v['state'] ? $v['state'] : 'idle';
		$tick  = function_exists( 'gend_society_migration_export_active' ) && gend_society_migration_export_active(); // 110-13: the export runs here.
		$out   = '<div class="gs-mship-progress gs-mship-progress--migration" data-gs-mig-root="1" data-state="' . esc_attr( $state ) . '"' . ( gend_society_migration_view_polls( $v ) ? ' data-poll="1"' : '' ) . ( $tick ? ' data-tick="1"' : '' ) . '>';
		$i     = 0;
		foreach ( $steps as $label ) {
			++$i;
			$out .= '<div class="gs-mship-step' . ( $i <= $ix ? ' is-done' : '' ) . '"><span class="dot"></span><span class="label">' . esc_html( $label ) . '</span></div>';
		}
		$out .= '<div class="gs-mship-mig">';
		if ( 'scheduled' === $state && $v['scheduled_at'] > 0 ) {
			/* translators: %s: local date and time. */
			$out .= '<p class="gs-mship-mig__note" data-gs-mig-scheduled="' . (int) $v['scheduled_at'] . '">' . esc_html( sprintf( __( 'Starts %s', 'gend-society' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $v['scheduled_at'] ) ) ) . '</p>';
		}
		if ( $tick && defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$out .= '<p class="gs-mship-mig__note" data-gs-mig-keep-open="1">' . esc_html__( 'WP-Cron is off on this site: keep this tab open while your site is copied.', 'gend-society' ) . '</p>';
		}
		if ( 'preparing' === $state && $v['waiting_for_site'] ) {
			$out .= '<p class="gs-mship-mig__note" data-gs-mig-waiting="1">' . esc_html( $t['waiting'] ) . '</p>';
		}
		$btns = '';
		if ( $v['can_start'] ) {
			$btns .= '<button type="button" class="gs-mship-action-btn" data-gs-mig-action="start-open" aria-expanded="false">' . esc_html__( 'Start migration', 'gend-society' ) . '</button>';
		}
		if ( $v['can_retry'] ) {
			$btns .= '<button type="button" class="gs-mship-action-btn" data-gs-mig-action="retry">' . esc_html__( 'Retry', 'gend-society' ) . '</button>';
		}
		if ( $v['can_cancel'] ) {
			$btns .= '<button type="button" class="gs-mship-action-btn is-danger" data-gs-mig-action="cancel" data-confirm="' . esc_attr( $t['cancel'] ) . '">' . esc_html__( 'Cancel', 'gend-society' ) . '</button>';
		}
		if ( '' !== $btns ) {
			$out .= '<div class="gs-mship-mig__actions">' . $btns . '</div>';
		}
		if ( $v['can_start'] ) {
			$out .= '<div class="gs-mship-mig__start" id="gs-mig-start-step" data-gs-mig-start-step="1" hidden>';
			$out .= '<p class="gs-mship-mig__freeze">' . esc_html( $t['freeze'] ) . '</p>';
			$out .= '<label class="gs-mship-mig__check"><input type="checkbox" name="gs_mig_freeze_ack" value="1"> ' . esc_html__( 'I understand', 'gend-society' ) . '</label>';
			ob_start();
			gend_society_readiness_render_card( $last, 'migration' );
			$out .= (string) ob_get_clean();
			if ( $block ) {
				$msgs = array();
				foreach ( $block as $c ) {
					$msgs[] = (string) ( $c['message'] ?? '' );
				}
				/* translators: %s: blocker messages. */
				$out .= '<p class="gs-mship-mig__note" data-gs-mig-blockers="1">' . esc_html( sprintf( __( 'Fix this on your site before you start: %s', 'gend-society' ), implode( ' ', $msgs ) ) ) . '</p>';
			}
			$out .= '<div class="gs-mship-mig__when">';
			$out .= '<label><input type="radio" name="gs_mig_when" value="now" checked> ' . esc_html__( 'Start now', 'gend-society' ) . '</label>';
			$out .= '<label><input type="radio" name="gs_mig_when" value="schedule"' . ( $sched ? '' : ' disabled' ) . '> ' . esc_html__( 'Schedule for', 'gend-society' ) . '</label>';
			$out .= '<input type="datetime-local" name="gs_mig_start_at"' . ( $sched ? '' : ' disabled' ) . '>';
			$out .= '</div>';
			if ( ! $sched ) {
				$out .= '<p class="gs-mship-mig__note" data-gs-mig-needs-cron="1">' . esc_html( $t['needs_cron'] ) . '</p>';
			}
			$out .= '<button type="button" class="gs-mship-action-btn" data-gs-mig-action="start"' . ( $block ? ' disabled' : '' ) . '>' . esc_html__( 'Confirm', 'gend-society' ) . '</button>';
			$out .= '</div>';
		}
		$out .= '<p class="gs-mship-mig__note" data-gs-mig-msg="1" role="status"></p>';
		$out .= '</div></div>';
		return $out;
	}
}

if ( ! function_exists( 'gend_society_migration_ajax' ) ) {
	/**
	 * admin-ajax gend_society_migration_{start,cancel,retry,state,report_if_stale}: manage_options + the panel nonce.
	 *
	 * @param string $verb Action.
	 */
	function gend_society_migration_ajax( string $verb ): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'gend-society' ) ), 403 );
		}
		check_ajax_referer( 'gs_membership_action', 'nonce' );
		$req = array(
			'freeze_ack' => isset( $_POST['freeze_ack'] ) ? sanitize_text_field( wp_unslash( $_POST['freeze_ack'] ) ) : '',
			'start_at'   => isset( $_POST['start_at'] ) ? absint( wp_unslash( $_POST['start_at'] ) ) : 0,
		);
		$r = gend_society_migration_do( $verb, $req );
		if ( is_wp_error( $r ) ) {
			$d = $r->get_error_data();
			wp_send_json_error(
				array(
					'message' => $r->get_error_message(),
					'code'    => $r->get_error_code(),
				),
				is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : 400
			);
		}
		wp_send_json_success( $r );
	}
}

if ( ! function_exists( 'gend_society_migration_wizard_wait_ajax' ) ) {
	/** admin-ajax gend_society_migration_wizard_wait (the CTA popup's first page): manage_options + nonce. */
	function gend_society_migration_wizard_wait_ajax(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		check_ajax_referer( 'gend_society_migration_wizard_wait' );
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		$r    = gend_society_migration_wizard_wait_response( $step );
		if ( 'redirect' === $r['type'] && function_exists( 'gend_society_plan_is_hub_url' ) && gend_society_plan_is_hub_url( $r['location'] ) ) {
			wp_redirect( $r['location'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the paired gend.me host only (checked above).
			exit;
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $r['html'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}
}

if ( ! function_exists( 'gend_society_migration_report_if_stale_cron' ) ) {
	/** The background report the Hosting tab render queues (missing or older than 1 h). */
	function gend_society_migration_report_if_stale_cron(): void {
		if ( function_exists( 'gend_society_dashboard_standalone_paired' ) && gend_society_dashboard_standalone_paired() ) {
			gend_society_migration_report_if_stale( 15.0 );
		}
	}
}

if ( ! function_exists( 'gend_society_migration_queue_report' ) ) {
	/** Queue the background report when gend.me's copy is stale (called by the Hosting tab render). */
	function gend_society_migration_queue_report(): void {
		if ( gend_society_migration_report_is_stale() && ! wp_next_scheduled( 'gend_society_migration_report_if_stale' ) ) {
			wp_schedule_single_event( time(), 'gend_society_migration_report_if_stale' );
		}
	}
}

foreach ( array( 'start', 'cancel', 'retry', 'state', 'report_if_stale' ) as $gend_society_mig_verb ) {
	add_action(
		'wp_ajax_gend_society_migration_' . $gend_society_mig_verb,
		static function () use ( $gend_society_mig_verb ) {
			gend_society_migration_ajax( $gend_society_mig_verb );
		}
	);
}
unset( $gend_society_mig_verb );
add_action( 'wp_ajax_gend_society_migration_wizard_wait', 'gend_society_migration_wizard_wait_ajax' );
add_action( 'gend_society_migration_poll', 'gend_society_migration_poll_cron' );
add_action( 'gend_society_migration_report_if_stale', 'gend_society_migration_report_if_stale_cron' );
