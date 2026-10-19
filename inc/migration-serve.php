<?php
/**
 * Migration file route (customer side): the export's four artifacts on HMAC-signed, expiring URLs, pulled by the
 * existing import Job (D4 pull-based, zero k8s manifest change).
 *
 * Plan 110-15 (MIG-05). Manifest: tier customer, needs [ 'standalone' ].
 *
 *  Route   GET /wp-json/gs/v1/migration/file?job=&file=&part=&exp=&sig= (also /?rest_route=/gs/v1/migration/file&...,
 *          the manifest's alt_base form). file in { db.sql.gz, users.sql.gz, uploads.tar.gz, manifest.json };
 *          sig = hash_hmac( 'sha256', 'fetch|<install_id>|<job>|<file>|<part>|<exp>', export_secret ), part '' when
 *          absent (the Migration_Transit scheme plus job and part). 403 for an expired exp, a tampered value, an
 *          unknown file, a job that is not the current one or after cleanup; 409 before the export is ready.
 *  Body    without part: every part of that artifact back to back (one valid multi-member .gz / .tar.gz); with part=N
 *          one part. Content-Length, Accept-Ranges, one Range (206 / 416), X-Accel-Buffering: no, output buffers
 *          dropped, first byte at once (the parts are pre-built, 110-13/14).
 *  Auth    no cookie needed; a site-wide "REST needs login" filter (rest_authentication_errors) is lifted for this
 *          route path only; the signature is the auth.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GEND_SOCIETY_MIGRATION_URL_TTL' ) ) {
	define( 'GEND_SOCIETY_MIGRATION_URL_TTL', DAY_IN_SECONDS );
}

if ( ! function_exists( 'gend_society_migration_files' ) ) {
	/** The artifact allow-list: file name => job parts kind ('' = the manifest). */
	function gend_society_migration_files(): array {
		return array(
			'db.sql.gz'      => 'db',
			'users.sql.gz'   => 'users',
			'uploads.tar.gz' => 'content',
			'manifest.json'  => '',
		);
	}
}

if ( ! function_exists( 'gend_society_migration_sign' ) ) {
	/**
	 * The signature of one fetch (hex HMAC-SHA256 with the job's export secret). '' without a job / install id.
	 *
	 * @param array  $job  Stored job ({ job_id, export_secret, ... }).
	 * @param string $file Artifact name.
	 * @param string $part Part number ('' = all parts).
	 * @param int    $exp  Expiry (unix).
	 */
	function gend_society_migration_sign( array $job, string $file, string $part, int $exp ): string {
		$install = (string) get_option( 'gend_society_install_id', '' );
		$secret  = (string) ( $job['export_secret'] ?? '' );
		$id      = (string) ( $job['job_id'] ?? '' );
		if ( '' === $install || '' === $secret || '' === $id ) {
			return '';
		}
		return hash_hmac( 'sha256', 'fetch|' . $install . '|' . $id . '|' . $file . '|' . $part . '|' . $exp, $secret );
	}
}

if ( ! function_exists( 'gend_society_migration_sign_url' ) ) {
	/**
	 * A signed fetch URL for the current job.
	 *
	 * @param string $file Artifact name (gend_society_migration_files()).
	 * @param string $part Part number, '' for the whole artifact.
	 * @param int    $ttl  Seconds until it expires (24 h by default).
	 * @param bool   $alt  The /?rest_route= form (hosts / WAFs that block /wp-json/).
	 * @return string '' without a current job or install id.
	 */
	function gend_society_migration_sign_url( string $file, string $part = '', int $ttl = GEND_SOCIETY_MIGRATION_URL_TTL, bool $alt = false ): string {
		$job = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
		if ( ! is_array( $job ) || ! isset( gend_society_migration_files()[ $file ] ) || ( '' !== $part && ! ctype_digit( $part ) ) ) {
			return '';
		}
		$exp = time() + $ttl;
		$sig = gend_society_migration_sign( $job, $file, $part, $exp );
		if ( '' === $sig ) {
			return '';
		}
		$args = array(
			'job'  => (string) $job['job_id'],
			'file' => $file,
		);
		if ( '' !== $part ) {
			$args['part'] = $part;
		}
		$args['exp'] = $exp;
		$args['sig'] = $sig;
		$base        = $alt ? home_url( '/?rest_route=/gs/v1/migration/file' ) : rest_url( 'gs/v1/migration/file' );
		// Appended by hand: add_query_arg() would re-encode the alt form's rest_route value (/ -> %2F).
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query( array_map( 'strval', $args ), '', '&', PHP_QUERY_RFC3986 );
	}
}

if ( ! function_exists( 'gend_society_migration_verify' ) ) {
	/**
	 * Verify one fetch: the allow-listed file, the expiry, the CURRENT job and the signature (hash_equals).
	 *
	 * @return true|WP_Error 403 forbidden (the reason stays in the error code, the body says only "Forbidden").
	 */
	function gend_society_migration_verify( string $job_id, string $file, string $part, int $exp, string $sig ) {
		$no = static function ( string $why ) {
			return new WP_Error( 'gend_society_migration_' . $why, __( 'Forbidden.', 'gend-society' ), array( 'status' => 403 ) );
		};
		if ( ! isset( gend_society_migration_files()[ $file ] ) || ( '' !== $part && ! ctype_digit( $part ) ) ) {
			return $no( 'file' );
		}
		if ( $exp < time() ) {
			return $no( 'expired' );
		}
		$job = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
		if ( ! is_array( $job ) || '' === (string) ( $job['export_secret'] ?? '' ) || ! hash_equals( (string) ( $job['job_id'] ?? '' ), $job_id ) ) {
			return $no( 'job' );
		}
		$want = gend_society_migration_sign( $job, $file, $part, $exp );
		if ( '' === $want || ! preg_match( '/^[0-9a-f]{64}$/', $sig ) || ! hash_equals( $want, $sig ) ) {
			return $no( 'sig' );
		}
		return true;
	}
}

if ( ! function_exists( 'gend_society_migration_segments' ) ) {
	/**
	 * The files that make up a request: [ [ path, size ], ... ] in stream order.
	 *
	 * @return array|WP_Error 409 not ready, 404 unknown part / file gone.
	 */
	function gend_society_migration_segments( array $job, string $file, string $part ) {
		if ( 'ready' !== (string) ( $job['phase'] ?? '' ) ) {
			return new WP_Error( 'gend_society_migration_not_ready', __( 'The export is not ready yet.', 'gend-society' ), array( 'status' => 409 ) );
		}
		$kind = gend_society_migration_files()[ $file ];
		$list = array();
		if ( '' === $kind ) {
			if ( '' !== $part || empty( $job['manifest']['file'] ) ) {
				return new WP_Error( 'gend_society_migration_gone', __( 'Not found.', 'gend-society' ), array( 'status' => 404 ) );
			}
			$list[] = (string) $job['manifest']['file'];
		} else {
			foreach ( (array) ( $job['parts'][ $kind ] ?? array() ) as $p ) {
				if ( '' === $part || (int) $part === (int) $p['n'] ) {
					$list[] = (string) $p['file'];
				}
			}
		}
		$out = array();
		foreach ( $list as $f ) {
			$path = rtrim( (string) $job['staging'], '/' ) . '/' . $f;
			if ( ! preg_match( '/^[0-9a-f]{16}\.(part|json)$/', $f ) || ! is_file( $path ) ) {
				return new WP_Error( 'gend_society_migration_gone', __( 'Not found.', 'gend-society' ), array( 'status' => 404 ) );
			}
			$out[] = array( $path, (int) filesize( $path ) );
		}
		if ( ! $out ) {
			return new WP_Error( 'gend_society_migration_gone', __( 'Not found.', 'gend-society' ), array( 'status' => 404 ) );
		}
		return $out;
	}
}

if ( ! function_exists( 'gend_society_migration_range' ) ) {
	/**
	 * One "bytes=" range against $total. Null = no (or an ignored multi-) range; false = unsatisfiable.
	 *
	 * @return array{0:int,1:int}|null|false [ first, last ] inclusive.
	 */
	function gend_society_migration_range( string $header, int $total ) {
		$header = trim( $header );
		if ( '' === $header || 0 !== strpos( $header, 'bytes=' ) || false !== strpos( $header, ',' ) ) {
			return null;
		}
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $header, $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null;
		}
		if ( '' === $m[1] ) {
			$n = (int) $m[2];
			if ( 0 === $n ) {
				return false;
			}
			return array( max( 0, $total - $n ), $total - 1 );
		}
		$first = (int) $m[1];
		$last  = '' === $m[2] ? $total - 1 : min( (int) $m[2], $total - 1 );
		if ( $first >= $total || $last < $first ) {
			return false;
		}
		return array( $first, $last );
	}
}

if ( ! function_exists( 'gend_society_migration_file_route' ) ) {
	/**
	 * GET gs/v1/migration/file: verify, then stream (never returns on success).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error
	 */
	function gend_society_migration_file_route( WP_REST_Request $request ) {
		$file = (string) $request->get_param( 'file' );
		$part = (string) $request->get_param( 'part' );
		$ok   = gend_society_migration_verify( (string) $request->get_param( 'job' ), $file, $part, (int) $request->get_param( 'exp' ), (string) $request->get_param( 'sig' ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$job  = gend_society_migration_export_job();
		$segs = gend_society_migration_segments( (array) $job, $file, $part );
		if ( is_wp_error( $segs ) ) {
			return $segs;
		}
		$total = 0;
		foreach ( $segs as $s ) {
			$total += $s[1];
		}
		$range = gend_society_migration_range( isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '', $total );
		if ( false === $range ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			status_header( 416 );
			header( 'Content-Range: bytes */' . $total );
			header( 'Content-Length: 0' );
			exit;
		}
		list( $first, $last ) = is_array( $range ) ? $range : array( 0, $total - 1 );

		// The gdc_migration_serve_file recipe: no output buffer, no proxy buffering, no time limit when allowed.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- opportunistic; parts are sized for a 30 s host cut.
		}
		nocache_headers();
		status_header( is_array( $range ) ? 206 : 200 );
		header( 'Content-Type: ' . ( 'manifest.json' === $file ? 'application/json' : 'application/gzip' ) );
		header( 'Content-Length: ' . ( $last - $first + 1 ) );
		header( 'Accept-Ranges: bytes' );
		if ( is_array( $range ) ) {
			header( 'Content-Range: bytes ' . $first . '-' . $last . '/' . $total );
		}
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' );
		if ( 'HEAD' === strtoupper( (string) $request->get_method() ) ) {
			exit;
		}
		$pos = 0;
		foreach ( $segs as $s ) {
			list( $path, $size ) = $s;
			$from                = max( $first, $pos );
			$to                  = min( $last, $pos + $size - 1 );
			if ( $from <= $to ) {
				$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $fh ) {
					exit;
				}
				fseek( $fh, $from - $pos );
				$left = $to - $from + 1;
				while ( $left > 0 && ! feof( $fh ) ) {
					$chunk = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary artifact stream.
					$left -= strlen( $chunk );
					flush();
				}
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$pos += $size;
			if ( $pos > $last ) {
				break;
			}
		}
		exit;
	}
}

if ( ! function_exists( 'gend_society_migration_register_file_route' ) ) {
	/** rest_api_init: the signed file route (the signature is the permission). */
	function gend_society_migration_register_file_route(): void {
		register_rest_route(
			'gs/v1',
			'/migration/file',
			array(
				'methods'             => 'GET',
				'callback'            => 'gend_society_migration_file_route',
				'permission_callback' => '__return_true',
				'args'                => array(
					'job'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'file' => array(
						'type'     => 'string',
						'required' => true,
					),
					'part' => array(
						'type'    => 'string',
						'default' => '',
					),
					'exp'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'sig'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}
}

if ( ! function_exists( 'gend_society_migration_rest_auth_bypass' ) ) {
	/**
	 * rest_authentication_errors (late): a site-wide "REST needs login" rule does not apply to the signed file route
	 * (security plugins on customer sites); every other route keeps its answer.
	 *
	 * @param mixed $result Current result.
	 * @return mixed
	 */
	function gend_society_migration_rest_auth_bypass( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$path  = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			$pre   = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
			$route = ( '' !== $pre && 0 === strpos( $path, untrailingslashit( $pre ) ) ) ? substr( $path, strlen( untrailingslashit( $pre ) ) ) : '';
		}
		return '/gs/v1/migration/file' === untrailingslashit( '/' . ltrim( $route, '/' ) ) ? null : $result;
	}
}

if ( ! function_exists( 'gend_society_migration_staging_exposed_check' ) ) {
	/**
	 * Readiness id staging_exposed (migration context): may a visitor download files from the export staging folder?
	 * Outside the web root = pass. In wp-content an anonymous GET of a canary must answer 403 / 404 (nginx ignores
	 * .htaccess; there the 0750 folder or a deny rule is what protects it); 200 = warn with the fix.
	 */
	function gend_society_migration_staging_exposed_check(): array {
		$label = __( 'Export folder is private', 'gend-society' );
		$job   = function_exists( 'gend_society_migration_export_job' ) ? gend_society_migration_export_job() : null;
		$dir   = is_array( $job ) && function_exists( 'gend_society_migration_staging_is_ours' ) && gend_society_migration_staging_is_ours( (string) ( $job['staging'] ?? '' ) ) ? (string) $job['staging'] : '';
		$temp  = false;
		if ( '' === $dir && function_exists( 'gend_society_migration_staging_dir' ) ) {
			$dir  = gend_society_migration_staging_dir();
			$temp = '' !== $dir;
		}
		if ( '' === $dir ) {
			return gend_society_readiness_item( 'staging_exposed', $label, 'warn', __( 'This site has no writable place for the export. Free up space or ask your host.', 'gend-society' ) );
		}
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$norm = wp_normalize_path( $dir );
		$out  = null;
		if ( 0 !== strpos( $norm . '/', $root . '/' ) && 0 !== strpos( $norm . '/', wp_normalize_path( WP_CONTENT_DIR ) . '/' ) ) {
			$out = gend_society_readiness_item( 'staging_exposed', $label, 'pass', __( 'The export is kept outside the web root.', 'gend-society' ) );
		} else {
			$name   = 'gs-canary-' . bin2hex( random_bytes( 6 ) ) . '.txt';
			$canary = $norm . '/' . $name;
			file_put_contents( $canary, 'gend-society staging canary' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- a canary in the staging dir, never the plugin folder.
			$rel  = ltrim( substr( $norm, strlen( wp_normalize_path( WP_CONTENT_DIR ) ) ), '/' );
			$url  = content_url( $rel . '/' . $name );
			$resp = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'cookies'     => array(),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			wp_delete_file( $canary );
			$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
			$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
			if ( 200 === $code && false !== strpos( $body, 'gend-society staging canary' ) ) {
				$out = gend_society_readiness_item( 'staging_exposed', $label, 'warn', __( 'Your web server lets visitors download files from the export folder in wp-content. Add a rule that denies wp-content/gend-society-migration-* (nginx: location ~ ^/wp-content/gend-society-migration- { deny all; }), or ask your host, then re-run the checks.', 'gend-society' ) );
			} elseif ( 403 === $code || 404 === $code || ( 200 === $code ) ) {
				/* translators: %d: HTTP status code. */
				$out = gend_society_readiness_item( 'staging_exposed', $label, 'pass', sprintf( __( 'Visitors cannot download the export (the folder answered %d).', 'gend-society' ), $code ) );
			} else {
				$out = gend_society_readiness_item( 'staging_exposed', $label, 'warn', __( 'The export folder could not be checked from outside. Make sure your host does not serve wp-content/gend-society-migration-* folders.', 'gend-society' ) );
			}
		}
		if ( $temp && function_exists( 'gend_society_migration_rmdir' ) ) {
			gend_society_migration_rmdir( $dir ); // the folder made for this check only
		}
		return $out;
	}
}

add_action( 'rest_api_init', 'gend_society_migration_register_file_route' );
add_filter( 'rest_authentication_errors', 'gend_society_migration_rest_auth_bypass', 999 );
