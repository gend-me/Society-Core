<?php
/**
 * Local readiness checks: can this site be connected and later migrated?
 *
 * Plan 106-04 (RUN-07). Manifest: tier customer, needs [] — functions only on
 * every runtime; the one hook (the "Re-run checks" admin-post handler) is
 * registered on standalone installs only. Reused by the Phase 110 migration
 * pre-flight: the check ids below are fixed.
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

if ( ! function_exists( 'gend_society_readiness_item' ) ) {
	/**
	 * Build one check row.
	 *
	 * @param string $id      Fixed check id.
	 * @param string $label   Translated label.
	 * @param string $status  pass | warn | fail.
	 * @param string $message Translated message.
	 * @param bool   $blocker True when this must be fixed before migrating.
	 * @return array{id:string,label:string,status:string,message:string,blocker:bool}
	 */
	function gend_society_readiness_item( string $id, string $label, string $status, string $message, bool $blocker = false ): array {
		if ( ! in_array( $status, array( 'pass', 'warn', 'fail' ), true ) ) {
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
	 */
	function gend_society_readiness_check_multisite(): ?array {
		if ( ! is_multisite() ) {
			return null;
		}
		return gend_society_readiness_item( 'multisite', __( 'Multisite', 'gend-society' ), 'warn', __( 'Moving a multisite network is not supported.', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_readiness_collect' ) ) {
	/**
	 * Run every check now (no cache).
	 *
	 * @return array{checked_at:int,checks:array<int,array>}
	 */
	function gend_society_readiness_collect(): array {
		$checks = array(
			gend_society_readiness_check_php(),
			gend_society_readiness_check_sodium(),
			gend_society_readiness_check_rest(),
			gend_society_readiness_check_loopback(),
			gend_society_readiness_check_basic_auth(),
			gend_society_readiness_check_public_dns(),
		);
		$multisite = gend_society_readiness_check_multisite();
		if ( null !== $multisite ) {
			$checks[] = $multisite;
		}
		return array(
			'checked_at' => time(),
			'checks'     => $checks,
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_last' ) ) {
	/**
	 * Last stored readiness result, or null when never run.
	 *
	 * @return array|null
	 */
	function gend_society_readiness_last(): ?array {
		$stored = get_option( GEND_SOCIETY_READINESS_OPTION, null );
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
	 * @param bool $force Re-run even when a recent result is stored.
	 * @return array{checked_at:int,checks:array<int,array{id:string,label:string,status:string,message:string,blocker:bool}>}
	 */
	function gend_society_readiness_run( bool $force = false ): array {
		if ( ! $force ) {
			$last = gend_society_readiness_last();
			if ( null !== $last && ( time() - (int) $last['checked_at'] ) < GEND_SOCIETY_READINESS_TTL ) {
				return $last;
			}
		}
		$result = gend_society_readiness_collect();
		update_option( GEND_SOCIETY_READINESS_OPTION, $result, false );
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
	 * @param array|null $result Result from gend_society_readiness_run(); null = last stored.
	 */
	function gend_society_readiness_render_card( ?array $result = null ): void {
		if ( null === $result ) {
			$result = gend_society_readiness_last();
		}
		$status_labels = array(
			'pass' => __( 'OK', 'gend-society' ),
			'warn' => __( 'Check', 'gend-society' ),
			'fail' => __( 'Fix', 'gend-society' ),
		);
		?>
		<div class="gend-society-card gend-society-readiness">
			<h2><?php esc_html_e( 'Site readiness', 'gend-society' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Local checks only. These tests run on this server and talk to nobody but this site.', 'gend-society' ); ?></p>
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
					<?php wp_nonce_field( 'gend_society_readiness_rerun' ); ?>
					<?php submit_button( __( 'Re-run checks', 'gend-society' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'gend_society_readiness_handle_rerun' ) ) {
	/**
	 * Admin-post handler for "Re-run checks".
	 */
	function gend_society_readiness_handle_rerun(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gend_society_readiness_rerun' );
		gend_society_readiness_run( true );
		$back = function_exists( 'gend_society_welcome_page_url' ) ? gend_society_welcome_page_url() : admin_url();
		$ref  = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : $back );
		exit;
	}
}

if ( function_exists( 'gend_society_runtime_mode' ) && 'standalone' === gend_society_runtime_mode() ) {
	add_action( 'admin_post_gend_society_readiness_rerun', 'gend_society_readiness_handle_rerun' );
}
