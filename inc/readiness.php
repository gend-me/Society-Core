<?php
/**
 * Local readiness checks: can this site be connected and later migrated?
 *
 * Plan 106-04 (RUN-07). Manifest: tier customer, needs [] — functions only on
 * every runtime; the one hook (the "Re-run checks" admin-post handler) is
 * registered on standalone installs only. Reused by the Phase 110 migration
 * pre-flight: the check ids below are fixed.
 *
 * Plan 110-09 (MIG-02): a $context argument. 'welcome' (default) is the 106
 * card, unchanged. 'migration' appends, in this order, db_size, content_size,
 * disk_free, max_execution_time, memory_limit, woocommerce, plugin_conflicts,
 * plan_fit and estimate (from inc/migration-preflight.php when it is loaded),
 * then rest_external once gend.me has answered a report; multisite becomes a
 * blocker. A migration result also carries 'context' and 'totals' and is cached
 * in its own option for 1 hour. render_card() has a read-only 'remote' mode for
 * gend.me's front end (no form, inline CSS). This file still makes no remote
 * call: the Re-run handler fires gend_society_readiness_rerun_done and the
 * migration module reports from there.
 *
 * Every HTTP request targets this site's own host (asserted before sending),
 * uses a 5 s timeout and never follows redirects. Nothing is sent to gend.me
 * or any third party. The loopback check reuses core Site Health.
 *
 * Result shape:
 *   array(
 *     'checked_at' => int,
 *     'checks'     => array(
 *       array(
 *         'id'      => string,
 *         'label'   => string,
 *         'status'  => 'pass' | 'warn' | 'fail',
 *         'message' => string,
 *         'blocker' => bool,
 *       ),
 *       ...
 *     ),
 *   )
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_READINESS_OPTION' ) ) {
	define( 'GEND_SOCIETY_READINESS_OPTION', 'gend_society_readiness_last' );
}

if ( ! defined( 'GEND_SOCIETY_READINESS_TTL' ) ) {
	// Cached result lifetime: 12 hours.
	define( 'GEND_SOCIETY_READINESS_TTL', 12 * 3600 );
}

if ( ! defined( 'GEND_SOCIETY_READINESS_MIGRATION_OPTION' ) ) {
	define( 'GEND_SOCIETY_READINESS_MIGRATION_OPTION', 'gend_society_readiness_last_migration' );
}

if ( ! defined( 'GEND_SOCIETY_READINESS_MIGRATION_TTL' ) ) {
	// Migration-context result lifetime: 1 hour.
	define( 'GEND_SOCIETY_READINESS_MIGRATION_TTL', 3600 );
}

if ( ! function_exists( 'gend_society_readiness_item' ) ) {
	/**
	 * Build one check row.
	 *
	 * @param string $id      Fixed check id.
	 * @param string $label   Translated label.
	 * @param string $status  pass | warn | fail | info (info: migration rows that only report a value).
	 * @param string $message Translated message.
	 * @param bool   $blocker True when this must be fixed before migrating.
	 * @return array{id:string,label:string,status:string,message:string,blocker:bool}
	 */
	function gend_society_readiness_item( string $id, string $label, string $status, string $message, bool $blocker = false ): array {
		if ( ! in_array( $status, array( 'pass', 'warn', 'fail', 'info' ), true ) ) {
			$status = 'warn';
		}
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'blocker' => $blocker,
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_self_hosts' ) ) {
	/**
	 * Hosts this site answers on (home + site URL, lower-case).
	 *
	 * @return string[]
	 */
	function gend_society_readiness_self_hosts(): array {
		$hosts = array();
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		return array_values( array_unique( $hosts ) );
	}
}

if ( ! function_exists( 'gend_society_readiness_self_get' ) ) {
	/**
	 * GET a URL on this site only. Refuses any other host, never follows
	 * redirects, sends no cookies, 5 s timeout.
	 *
	 * @param string $url URL on this site.
	 * @return array|WP_Error wp_remote_get() response or an error.
	 */
	function gend_society_readiness_self_get( string $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! in_array( strtolower( $host ), gend_society_readiness_self_hosts(), true ) ) {
			return new WP_Error( 'gend_society_readiness_foreign_host', __( 'Refused: readiness checks only contact this site.', 'gend-society' ) );
		}
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'cookies'     => array(),
			)
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_check_php' ) ) {
	/**
	 * PHP 8.1 or newer.
	 */
	function gend_society_readiness_check_php(): array {
		$label = __( 'PHP version', 'gend-society' );
		if ( version_compare( PHP_VERSION, '8.1', '>=' ) ) {
			/* translators: %s: PHP version. */
			return gend_society_readiness_item( 'php', $label, 'pass', sprintf( __( 'PHP %s', 'gend-society' ), PHP_VERSION ) );
		}
		return gend_society_readiness_item(
			'php',
			$label,
			'fail',
			/* translators: %s: PHP version. */
			sprintf( __( 'PHP %s is too old. GenD needs PHP 8.1 or newer; ask your host to upgrade.', 'gend-society' ), PHP_VERSION ),
			true
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_check_sodium' ) ) {
	/**
	 * Sodium (pairing keys are Ed25519).
	 */
	function gend_society_readiness_check_sodium(): array {
		$label = __( 'Sodium extension', 'gend-society' );
		if ( function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return gend_society_readiness_item( 'sodium', $label, 'pass', __( 'Available', 'gend-society' ) );
		}
		return gend_society_readiness_item(
			'sodium',
			$label,
			'fail',
			__( 'The PHP sodium extension is missing. It is needed to create this site\'s connection keys; ask your host to enable it.', 'gend-society' ),
			true
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_check_rest' ) ) {
	/**
	 * The REST API answers on this site.
	 */
	function gend_society_readiness_check_rest(): array {
		$label    = __( 'REST API', 'gend-society' );
		$response = gend_society_readiness_self_get( rest_url() );
		if ( is_wp_error( $response ) ) {
			return gend_society_readiness_item(
				'rest',
				$label,
				'warn',
				/* translators: %s: error message. */
				sprintf( __( 'Could not reach the REST API: %s', 'gend-society' ), $response->get_error_message() )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && isset( $body['namespaces'] ) ) {
				return gend_society_readiness_item( 'rest', $label, 'pass', __( 'Reachable', 'gend-society' ) );
			}
			return gend_society_readiness_item( 'rest', $label, 'warn', __( 'The REST API answered, but not with the expected data. A security or caching plugin may be changing it.', 'gend-society' ) );
		}
		if ( 401 === $code || 403 === $code ) {
			return gend_society_readiness_item( 'rest', $label, 'fail', __( 'REST API is blocked. Allow it in your security plugin or server settings.', 'gend-society' ), true );
		}
		return gend_society_readiness_item(
			'rest',
			$label,
			'warn',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'The REST API returned HTTP %d.', 'gend-society' ), $code )
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_check_loopback' ) ) {
	/**
	 * Loopback requests (core Site Health test, reused as-is).
	 */
	function gend_society_readiness_check_loopback(): array {
		$label = __( 'Loopback requests', 'gend-society' );
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			$file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
		if ( ! class_exists( 'WP_Site_Health' ) || ! method_exists( 'WP_Site_Health', 'get_instance' ) ) {
			return gend_society_readiness_item( 'loopback', $label, 'warn', __( 'Could not run the WordPress loopback test.', 'gend-society' ) );
		}
		$result  = WP_Site_Health::get_instance()->can_perform_loopback();
		$status  = is_object( $result ) && isset( $result->status ) ? (string) $result->status : '';
		$message = is_object( $result ) && isset( $result->message ) ? wp_strip_all_tags( (string) $result->message ) : '';
		if ( 'good' === $status ) {
			return gend_society_readiness_item( 'loopback', $label, 'pass', '' !== $message ? $message : __( 'Working', 'gend-society' ) );
		}
		if ( 'critical' === $status ) {
			return gend_society_readiness_item( 'loopback', $label, 'fail', '' !== $message ? $message : __( 'This site cannot make requests to itself.', 'gend-society' ), true );
		}
		return gend_society_readiness_item( 'loopback', $label, 'warn', '' !== $message ? $message : __( 'The loopback test was inconclusive.', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_readiness_check_basic_auth' ) ) {
	/**
	 * No HTTP basic auth in front of the site.
	 */
	function gend_society_readiness_check_basic_auth(): array {
		$label    = __( 'Password protection', 'gend-society' );
		$response = gend_society_readiness_self_get( home_url( '/' ) );
		if ( ! is_wp_error( $response ) && 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$header = wp_remote_retrieve_header( $response, 'www-authenticate' );
			$header = is_array( $header ) ? implode( ' ', $header ) : (string) $header;
			if ( false !== stripos( $header, 'basic' ) ) {
				return gend_society_readiness_item(
					'basic_auth',
					$label,
					'fail',
					__( 'Password protection (basic auth) blocks the migration copy; remove it before migrating.', 'gend-society' ),
					true
				);
			}
		}
		return gend_society_readiness_item( 'basic_auth', $label, 'pass', __( 'No basic auth', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_readiness_ip_is_public' ) ) {
	/**
	 * True for a public (non-private, non-reserved) IP address.
	 *
	 * @param string $ip IPv4 or IPv6 literal.
	 */
	function gend_society_readiness_ip_is_public( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}

if ( ! function_exists( 'gend_society_readiness_check_public_dns' ) ) {
	/**
	 * The site's host name resolves to a public address (gend.me must be
	 * able to reach it to copy it). Local DNS lookups only.
	 */
	function gend_society_readiness_check_public_dns(): array {
		$label = __( 'Public address', 'gend-society' );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = is_string( $host ) ? strtolower( trim( $host, '[]' ) ) : '';

		$local_msg = __( 'This site is on a local or private address. gend.me cannot reach it to migrate it; put it on a public domain first.', 'gend-society' );

		if ( '' === $host || 'localhost' === $host ) {
			return gend_society_readiness_item( 'public_dns', $label, 'fail', $local_msg, true );
		}
		foreach ( array( '.local', '.test', '.localhost' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return gend_society_readiness_item( 'public_dns', $label, 'fail', $local_msg, true );
			}
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( gend_society_readiness_ip_is_public( $host ) ) {
				return gend_society_readiness_item( 'public_dns', $label, 'pass', $host );
			}
			return gend_society_readiness_item( 'public_dns', $label, 'fail', $local_msg, true );
		}

		$addresses = array();
		$ipv4      = gethostbynamel( $host );
		if ( is_array( $ipv4 ) ) {
			$addresses = $ipv4;
		}
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			// dns_get_record() warns on lookup failure; a failed lookup is handled below.
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$addresses[] = (string) $record['ipv6'];
					}
				}
			}
		}
		$addresses = array_values( array_unique( $addresses ) );

		if ( empty( $addresses ) ) {
			return gend_society_readiness_item(
				'public_dns',
				$label,
				'fail',
				/* translators: %s: host name. */
				sprintf( __( '%s does not resolve in DNS. gend.me needs a public domain to reach this site.', 'gend-society' ), $host ),
				true
			);
		}

		$public = array_filter( $addresses, 'gend_society_readiness_ip_is_public' );
		if ( count( $public ) === count( $addresses ) ) {
			return gend_society_readiness_item( 'public_dns', $label, 'pass', implode( ', ', $addresses ) );
		}
		if ( empty( $public ) ) {
			return gend_society_readiness_item(
				'public_dns',
				$label,
				'warn',
				/* translators: %s: host name. */
				sprintf( __( '%s resolves only to private addresses from this server. Check that it is reachable from the internet.', 'gend-society' ), $host )
			);
		}
		return gend_society_readiness_item(
			'public_dns',
			$label,
			'warn',
			/* translators: %s: host name. */
			sprintf( __( '%s resolves to a mix of public and private addresses.', 'gend-society' ), $host )
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_check_multisite' ) ) {
	/**
	 * Info row: multisite networks cannot be moved. Null when not multisite.
	 * In the migration context it is a blocker (110-09).
	 *
	 * @param string $context 'welcome' | 'migration'.
	 */
	function gend_society_readiness_check_multisite( string $context = 'welcome' ): ?array {
		if ( ! is_multisite() ) {
			return null;
		}
		if ( 'migration' === $context ) {
			return gend_society_readiness_item( 'multisite', __( 'Multisite', 'gend-society' ), 'fail', __( 'Moving a multisite network is not supported. Only a single site can be migrated.', 'gend-society' ), true );
		}
		return gend_society_readiness_item( 'multisite', __( 'Multisite', 'gend-society' ), 'warn', __( 'Moving a multisite network is not supported.', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_readiness_collect' ) ) {
	/**
	 * Run every check now (no cache).
	 *
	 * @param string $context 'welcome' (the 106 checks) | 'migration' (plus the pre-flight rows, context, totals).
	 * @return array{checked_at:int,checks:array<int,array>}
	 */
	function gend_society_readiness_collect( string $context = 'welcome' ): array {
		$context = 'migration' === $context ? 'migration' : 'welcome';
		$checks  = array(
			gend_society_readiness_check_php(),
			gend_society_readiness_check_sodium(),
			gend_society_readiness_check_rest(),
			gend_society_readiness_check_loopback(),
			gend_society_readiness_check_basic_auth(),
			gend_society_readiness_check_public_dns(),
		);
		$multisite = gend_society_readiness_check_multisite( $context );
		if ( null !== $multisite ) {
			$checks[] = $multisite;
		}
		$result = array(
			'checked_at' => time(),
			'checks'     => $checks,
		);
		if ( 'migration' === $context ) {
			$result['context'] = 'migration';
			$result['totals']  = array();
			if ( function_exists( 'gend_society_migration_readiness_items' ) ) {
				$extra            = gend_society_migration_readiness_items();
				$result['checks'] = array_merge( $checks, (array) $extra['checks'] );
				$result['totals'] = (array) $extra['totals'];
			}
		}
		return $result;
	}
}

if ( ! function_exists( 'gend_society_readiness_last' ) ) {
	/**
	 * Last stored readiness result, or null when never run.
	 *
	 * @param string $context 'welcome' | 'migration' (separate options).
	 * @return array|null
	 */
	function gend_society_readiness_last( string $context = 'welcome' ): ?array {
		$stored = get_option( 'migration' === $context ? GEND_SOCIETY_READINESS_MIGRATION_OPTION : GEND_SOCIETY_READINESS_OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['checked_at'], $stored['checks'] ) || ! is_array( $stored['checks'] ) ) {
			return null;
		}
		return $stored;
	}
}

if ( ! function_exists( 'gend_society_readiness_run' ) ) {
	/**
	 * Run (or return the cached) readiness checks.
	 *
	 * @param bool   $force   Re-run even when a recent result is stored.
	 * @param string $context 'welcome' (option gend_society_readiness_last, 12 h) | 'migration'
	 *                        (option gend_society_readiness_last_migration, 1 h).
	 * @return array{checked_at:int,checks:array<int,array{id:string,label:string,status:string,message:string,blocker:bool}>}
	 */
	function gend_society_readiness_run( bool $force = false, string $context = 'welcome' ): array {
		$context = 'migration' === $context ? 'migration' : 'welcome';
		$ttl     = 'migration' === $context ? GEND_SOCIETY_READINESS_MIGRATION_TTL : GEND_SOCIETY_READINESS_TTL;
		if ( ! $force ) {
			$last = gend_society_readiness_last( $context );
			if ( null !== $last && ( time() - (int) $last['checked_at'] ) < $ttl ) {
				return $last;
			}
		}
		$result = gend_society_readiness_collect( $context );
		update_option( 'migration' === $context ? GEND_SOCIETY_READINESS_MIGRATION_OPTION : GEND_SOCIETY_READINESS_OPTION, $result, false );
		return $result;
	}
}

if ( ! function_exists( 'gend_society_readiness_blockers' ) ) {
	/**
	 * Checks in a result that block migration.
	 *
	 * @param array $result Readiness result.
	 * @return array<int,array>
	 */
	function gend_society_readiness_blockers( array $result ): array {
		$checks = isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array();
		return array_values(
			array_filter(
				$checks,
				static function ( $check ) {
					return is_array( $check ) && ! empty( $check['blocker'] );
				}
			)
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_render_card' ) ) {
	/**
	 * Echo the readiness card.
	 *
	 * @param array|null $result  Result from gend_society_readiness_run(); null = last stored for $context.
	 * @param string     $context 'welcome' | 'migration'.
	 * @param string     $mode    'local' (wp-admin, with the Re-run form) | 'remote' (read-only display for gend.me's
	 *                            front end: no form, no admin-only functions, CSS inline).
	 */
	function gend_society_readiness_render_card( ?array $result = null, string $context = 'welcome', string $mode = 'local' ): void {
		$migration = 'migration' === $context;
		if ( null === $result ) {
			$result = gend_society_readiness_last( $migration ? 'migration' : 'welcome' );
		}
		if ( 'remote' === $mode ) {
			gend_society_readiness_render_card_remote( $result );
			return;
		}
		$status_labels = array(
			'pass' => __( 'OK', 'gend-society' ),
			'warn' => __( 'Check', 'gend-society' ),
			'fail' => __( 'Fix', 'gend-society' ),
			'info' => __( 'Info', 'gend-society' ),
		);
		?>
		<div class="gend-society-card gend-society-readiness">
			<h2><?php esc_html_e( 'Site readiness', 'gend-society' ); ?></h2>
<?php if ( $migration ) : ?>
			<p class="description"><?php esc_html_e( 'These checks run on this server. When this site is connected, the results are shared with gend.me so it can check that your plans fit.', 'gend-society' ); ?></p>
<?php else : ?>
			<p class="description"><?php esc_html_e( 'Local checks only. These tests run on this server and talk to nobody but this site.', 'gend-society' ); ?></p>
<?php endif; ?>
			<?php if ( null === $result ) : ?>
				<p><?php esc_html_e( 'The checks have not run yet.', 'gend-society' ); ?></p>
			<?php else : ?>
				<?php
				$blockers = count( gend_society_readiness_blockers( $result ) );
				?>
				<p class="gend-society-readiness-summary <?php echo $blockers ? 'has-blockers' : 'no-blockers'; ?>">
					<strong>
					<?php
					if ( 0 === $blockers ) {
						esc_html_e( 'No blockers found', 'gend-society' );
					} else {
						echo esc_html(
							sprintf(
								/* translators: %d: number of blocking items. */
								_n( '%d item to fix before migrating', '%d items to fix before migrating', $blockers, 'gend-society' ),
								$blockers
							)
						);
					}
					?>
					</strong>
				</p>
				<table class="widefat striped gend-society-readiness-table">
					<tbody>
					<?php foreach ( (array) $result['checks'] as $check ) : ?>
						<?php
						if ( ! is_array( $check ) ) {
							continue;
						}
						$status = isset( $check['status'], $status_labels[ $check['status'] ] ) ? (string) $check['status'] : 'warn';
						?>
						<tr data-check="<?php echo esc_attr( (string) ( $check['id'] ?? '' ) ); ?>">
							<td class="gend-society-readiness-label"><?php echo esc_html( (string) ( $check['label'] ?? '' ) ); ?></td>
							<td class="gend-society-readiness-status"><span class="gend-society-badge gend-society-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_labels[ $status ] ); ?></span></td>
							<td class="gend-society-readiness-message"><?php echo esc_html( (string) ( $check['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date and time. */
							__( 'Last checked: %s', 'gend-society' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['checked_at'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="gend_society_readiness_rerun" />
<?php if ( $migration ) : ?>
					<input type="hidden" name="context" value="migration" />
<?php endif; ?>
					<?php wp_nonce_field( 'gend_society_readiness_rerun' ); ?>
					<?php submit_button( __( 'Re-run checks', 'gend-society' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'gend_society_readiness_render_card_remote' ) ) {
	/**
	 * Read-only card for gend.me's front end (the hub shows what the site reported). Same table markup as the
	 * local card; no form and no admin-only functions; one scoped <style> block per request.
	 *
	 * @param array|null $result Reported result.
	 */
	function gend_society_readiness_render_card_remote( ?array $result ): void {
		static $styled = false;
		$status_labels = array(
			'pass' => __( 'OK', 'gend-society' ),
			'warn' => __( 'Check', 'gend-society' ),
			'fail' => __( 'Fix', 'gend-society' ),
			'info' => __( 'Info', 'gend-society' ),
		);
		if ( ! $styled ) {
			$styled = true;
			echo '<style>'
				. '.gend-society-readiness--remote{box-sizing:border-box;max-width:100%;margin:0 0 16px;padding:16px 18px;border-radius:14px;background:rgba(15,23,42,.6);border:1px solid rgba(148,163,184,.25);color:#e2e8f0;}'
				. '.gend-society-readiness--remote h2{margin:0 0 6px;font-size:1rem;font-weight:700;color:#f8fafc;}'
				. '.gend-society-readiness--remote .description{margin:0 0 10px;font-size:.85rem;color:#94a3b8;}'
				. '.gend-society-readiness--remote .gend-society-readiness-summary{margin:0 0 10px;font-size:.9rem;}'
				. '.gend-society-readiness--remote .gend-society-readiness-summary.has-blockers{color:#fca5a5;}'
				. '.gend-society-readiness--remote .gend-society-readiness-summary.no-blockers{color:#86efac;}'
				. '.gend-society-readiness--remote .gend-society-readiness-table{width:100%;border-collapse:collapse;font-size:.85rem;}'
				. '.gend-society-readiness--remote .gend-society-readiness-table td{padding:7px 8px;border-top:1px solid rgba(148,163,184,.15);vertical-align:top;}'
				. '.gend-society-readiness--remote .gend-society-readiness-label{font-weight:600;white-space:nowrap;}'
				. '.gend-society-readiness--remote .gend-society-badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:700;}'
				. '.gend-society-readiness--remote .gend-society-badge--pass{background:rgba(34,197,94,.18);color:#86efac;}'
				. '.gend-society-readiness--remote .gend-society-badge--warn{background:rgba(234,179,8,.18);color:#fde047;}'
				. '.gend-society-readiness--remote .gend-society-badge--fail{background:rgba(239,68,68,.2);color:#fca5a5;}'
				. '.gend-society-readiness--remote .gend-society-badge--info{background:rgba(56,189,248,.16);color:#7dd3fc;}'
				. '</style>';
		}
		?>
		<div class="gend-society-card gend-society-readiness gend-society-readiness--remote">
			<h2><?php esc_html_e( 'Site readiness', 'gend-society' ); ?></h2>
			<?php if ( null === $result ) : ?>
				<p class="description"><?php esc_html_e( 'Your site has not reported its checks yet. Run them in your site\'s wp-admin.', 'gend-society' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date and time. */
							__( 'Checks reported by your site on %s. Re-run them in your site\'s wp-admin.', 'gend-society' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $result['checked_at'] ?? 0 ) )
						)
					);
					?>
				</p>
				<?php
				$blockers = count( gend_society_readiness_blockers( $result ) );
				?>
				<p class="gend-society-readiness-summary <?php echo $blockers ? 'has-blockers' : 'no-blockers'; ?>">
					<strong>
					<?php
					if ( 0 === $blockers ) {
						esc_html_e( 'No blockers found', 'gend-society' );
					} else {
						echo esc_html(
							sprintf(
								/* translators: %d: number of blocking items. */
								_n( '%d item to fix before migrating', '%d items to fix before migrating', $blockers, 'gend-society' ),
								$blockers
							)
						);
					}
					?>
					</strong>
				</p>
				<table class="widefat striped gend-society-readiness-table">
					<tbody>
					<?php foreach ( (array) ( $result['checks'] ?? array() ) as $check ) : ?>
						<?php
						if ( ! is_array( $check ) ) {
							continue;
						}
						$status = isset( $check['status'], $status_labels[ $check['status'] ] ) ? (string) $check['status'] : 'warn';
						?>
						<tr data-check="<?php echo esc_attr( (string) ( $check['id'] ?? '' ) ); ?>">
							<td class="gend-society-readiness-label"><?php echo esc_html( (string) ( $check['label'] ?? '' ) ); ?></td>
							<td class="gend-society-readiness-status"><span class="gend-society-badge gend-society-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_labels[ $status ] ); ?></span></td>
							<td class="gend-society-readiness-message"><?php echo esc_html( (string) ( $check['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'gend_society_readiness_handle_rerun' ) ) {
	/**
	 * Admin-post handler for "Re-run checks". With context=migration (110-09) it finishes the size walk within this
	 * request's budget, runs the migration checks and fires gend_society_readiness_rerun_done (the migration module
	 * reports to gend.me from there).
	 */
	function gend_society_readiness_handle_rerun(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gend_society_readiness_rerun' );
		$context = ( isset( $_POST['context'] ) && 'migration' === sanitize_key( wp_unslash( $_POST['context'] ) ) ) ? 'migration' : 'welcome';
		if ( 'migration' === $context && function_exists( 'gend_society_migration_sizing_step' ) ) {
			gend_society_migration_sizing_reset();
			$met      = (int) ini_get( 'max_execution_time' );
			$deadline = microtime( true ) + ( $met > 0 ? max( 2, min( 20, $met - 8 ) ) : 20 );
			do {
				$step = gend_society_migration_sizing_step( max( 1.0, min( 15.0, $deadline - microtime( true ) ) ) );
			} while ( empty( $step['done'] ) && microtime( true ) < $deadline );
		}
		$result = gend_society_readiness_run( true, $context );
		do_action( 'gend_society_readiness_rerun_done', $context, $result );
		$back = function_exists( 'gend_society_welcome_page_url' ) ? gend_society_welcome_page_url() : admin_url();
		$ref  = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : $back );
		exit;
	}
}

if ( function_exists( 'gend_society_runtime_mode' ) && 'standalone' === gend_society_runtime_mode() ) {
	add_action( 'admin_post_gend_society_readiness_rerun', 'gend_society_readiness_handle_rerun' );
}
