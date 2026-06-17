<?php
/**
 * Phase 31-frontend — Gend_GS_Calendar_Public_View
 *
 * The read-only public shared-calendar view at /calendar-view/{share_token}/.
 *
 * Mirrors Gend_GS_Booking_Public_Page (Phase 29-04):
 *   - Hooks template_redirect (priority 5 so we intercept BEFORE WP's 404
 *     handler fires).
 *   - Matches REQUEST_URI against /calendar-view/{43-char-alphanumeric}/?
 *     via preg_match — but ALSO registers a real rewrite rule + flushes on
 *     version-bump so the route is canonical (the template_redirect match is the
 *     load-bearing interceptor; the rewrite is belt-and-suspenders so the URL
 *     is a "real" WP route that survives plugins poking at query vars).
 *   - Streams a STANDALONE glassmorphic HTML document (NOT a theme template) so
 *     the page is independent of the multisite theme. The page is a thin shell;
 *     the privacy decision + all data fetching happens client-side against the
 *     token-gated /gs/v1/calendar/public/{token}/{info,events} REST endpoints
 *     (which sit PAST the REST auth gate — logged-out callers reach them).
 *
 * Pitfall 1: include guard handled by gend-society.php's file_exists block.
 *
 * Pitfall 7: this page does NOT resolve identity from the current user — it
 * passes ONLY the URL-path share_token to the client; the REST layer enforces
 * privacy + identity filtering. We don't even look the token up here (the page
 * renders even for unknown tokens; the JS shows "Calendar not found" off the
 * 404). This keeps the shell dumb and the auth decision entirely server-side.
 *
 * Pitfall 2: try/catch \Throwable around the render path so nothing can fatal
 * the request into WP's plugin auto-deactivation trap.
 *
 * @package Gend_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public read-only shared-calendar view handler.
 */
class Gend_GS_Calendar_Public_View {

	const PATH_PREFIX = '/calendar-view/';

	/** Option key tracking the rewrite-rules version we last flushed for. */
	const REWRITE_VERSION_OPT = 'gs_calendar_view_rewrite_ver';

	/** Wire up rewrite rule + template_redirect interceptor. */
	public static function init() : void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_template_redirect' ), 5 );
	}

	/**
	 * Register the /calendar-view/{token}/ rewrite rule and flush ONCE per
	 * version bump (never on every request — that's expensive). The
	 * template_redirect interceptor below is what actually serves the page, so
	 * the route works even if the flush hasn't happened yet; this rule just makes
	 * the URL a first-class WP route.
	 */
	public static function register_rewrite() : void {
		add_rewrite_rule(
			'^calendar-view/([A-Za-z0-9]{43})/?$',
			'index.php?gs_calendar_view_token=$matches[1]',
			'top'
		);

		$want = defined( 'GS_VERSION' ) ? GS_VERSION : '1.0.0';
		$have = get_option( self::REWRITE_VERSION_OPT );
		if ( $have !== $want ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_VERSION_OPT, $want );
		}
	}

	/** Whitelist our query var so WP keeps it on the parsed request. */
	public static function add_query_var( array $vars ) : array {
		$vars[] = 'gs_calendar_view_token';
		return $vars;
	}

	/**
	 * template_redirect handler. Serves the view when the path matches
	 * /calendar-view/{43-char token}. Uses BOTH the query var (when the rewrite
	 * rule resolved) and a direct REQUEST_URI preg_match fallback (so the route
	 * works immediately even before a rewrite flush propagates).
	 */
	public static function handle_template_redirect() : void {
		try {
			$token = '';

			$qv = get_query_var( 'gs_calendar_view_token' );
			if ( is_string( $qv ) && preg_match( '/^[A-Za-z0-9]{43}$/', $qv ) ) {
				$token = $qv;
			}

			if ( $token === '' ) {
				$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
				$path = parse_url( $uri, PHP_URL_PATH );
				if ( ! is_string( $path ) || $path === '' ) {
					return;
				}
				$pattern = '#^' . preg_quote( self::PATH_PREFIX, '#' ) . '([A-Za-z0-9]{43})/?$#';
				if ( ! preg_match( $pattern, $path, $m ) ) {
					return;
				}
				$token = $m[1];
			}

			self::render_view_page( $token );
			// render_view_page exits.
		} catch ( \Throwable $e ) {
			error_log( 'GS_CAL_VIEW_FAIL :: ' . $e->getMessage() );
			// Fall through silently — let WP serve its native 404 rather than
			// fataling (which would auto-deactivate the plugin).
		}
	}

	/**
	 * Render and stream the standalone read-only shared-calendar HTML document.
	 *
	 * The shell is deliberately privacy-agnostic: it does NOT resolve the host or
	 * read visibility settings. It enqueues calendar-public-view.{js,css} and
	 * inlines { restBase, token }. The JS calls the token-gated REST endpoints
	 * which enforce privacy (403 gs_cal_private), unknown-token (404), and
	 * identity/source/detail filtering — and renders the appropriate card.
	 *
	 * @param string $share_token 43-char alphanumeric token.
	 */
	public static function render_view_page( string $share_token ) : void {
		try {
			$payload = array(
				'restBase' => esc_url_raw( rest_url( 'gs/v1/' ) ),
				'token'    => $share_token,
			);

			$js_url  = plugins_url( 'assets/calendar-public-view.js', GS_DIR . 'gend-society.php' );
			$css_url = plugins_url( 'assets/calendar-public-view.css', GS_DIR . 'gend-society.php' );
			$ver     = defined( 'GS_VERSION' ) ? GS_VERSION : '1.0.0';

			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );

			echo '<!doctype html><html lang="en"><head>';
			echo '<meta charset="utf-8">';
			echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
			echo '<title>' . esc_html__( 'Shared calendar', 'gend-society' ) . '</title>';
			echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '?v=' . esc_attr( $ver ) . '">';
			echo '</head><body>';
			echo '<div id="gs-calview-root" class="gs-calview-root gs-cal-root">';
			echo '<div class="gs-calview-loading">' . esc_html__( 'Loading calendar…', 'gend-society' ) . '</div>';
			echo '<noscript><p style="color:#fff;text-align:center;padding:40px">This shared calendar requires JavaScript.</p></noscript>';
			echo '</div>';
			echo '<script>window.gsCalViewData=' . wp_json_encode( $payload ) . ';</script>';
			echo '<script src="' . esc_url( $js_url ) . '?v=' . esc_attr( $ver ) . '" defer></script>';
			echo '</body></html>';

			// We own the entire response — exit so WP doesn't render a theme on top.
			exit;
		} catch ( \Throwable $e ) {
			error_log( 'GS_CAL_VIEW_RENDER_FAIL token=' . substr( $share_token, 0, 8 ) . ' :: ' . $e->getMessage() );
			status_header( 500 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Calendar unavailable</title>'
				. '<style>html,body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
				. 'font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0a0518;color:#fff}'
				. 'h1{font-size:20px;font-weight:500}</style></head><body><h1>Calendar unavailable</h1></body></html>';
			exit;
		}
	}
}
