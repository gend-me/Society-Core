<?php
/**
 * Section pairing (plan 108-09, CON-03 / CON-07 plugin side): the Connect card, the /pair short-link
 * pre-fill (never submitted, no request on render), consent before the first request, a real
 * group-bound code minted on the hub clone and redeemed through /connect (hub challenge back to this
 * site), the Connected card, and failures (invalid code, replay while connected, 422 pass-through).
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_pair.php';

if ( 'standalone' !== gs108_mode() ) {
	gs108_assert( ! function_exists( 'gend_society_connect_pairing_prepare' ), 'pairing-absent', 'connect-flow.php not loaded on ' . gs108_mode() );
	return;
}
if ( ! gs108p_require() ) {
	return;
}

$admin = gs108c_admin_id();
gs108_http_record();
$iid = gend_society_connect_identity()['install_id'];

// --- 1. Connect card + pre-fill (no request is made by any render).
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'Connect this site to gend.me' ), 'pairing-card-title', 'Connect card rendered' );
gs108_assert( false !== strpos( $html, 'Agree and connect to gend.me' ) && false !== strpos( $html, 'name="action" value="gend_society_connect_start"' ), 'pairing-card-primary', 'primary button "Agree and connect to gend.me" posts to admin-post gend_society_connect_start' );
gs108_assert( (bool) preg_match( '/name="_wpnonce" value="([0-9a-f]+)"/', $html, $m ) && false !== wp_verify_nonce( $m[1], 'gend_society_connect_start' ), 'pairing-card-nonce', 'primary form carries a valid gend_society_connect_start nonce' );
$items_ok = true;
foreach ( gend_society_consent_disclosure_items() as $label ) {
	$items_ok = $items_ok && false !== strpos( $html, '<li>' . esc_html( $label ) . '</li>' );
}
gs108_assert( $items_ok, 'pairing-card-disclosure', 'every disclosure item listed above the button' );
gs108_assert( false !== strpos( $html, 'You will log in to gend.me, choose a business group and approve. Nothing is sent until you click.' ), 'pairing-card-line', 'explanation line present' );
gs108_assert( false !== strpos( $html, 'Terms of Service' ) && false !== strpos( $html, 'Privacy Policy' ), 'pairing-card-links', 'Terms + Privacy links' );
gs108_assert( false !== strpos( $html, '<summary>Have a pairing code?</summary>' ) && false !== strpos( $html, '<details class="gend-society-connect-pairing">' ), 'pairing-details-closed', '"Have a pairing code?" fallback, closed without a code' );
gs108_assert( false !== strpos( $html, 'id="gs_gend_base_url" name="gend_base_url" class="regular-text" value="' . esc_attr( gend_society_oauth_hub_url() ) . '"' ), 'pairing-base-default', 'gend.me URL defaults to gend_society_oauth_hub_url() (' . gend_society_oauth_hub_url() . ')' );
gs108_assert( false !== strpos( $html, 'name="gend_society_consent" value="1" required />' ) && false === strpos( $html, 'checked' ), 'pairing-consent-box', 'consent checkbox required and not pre-ticked' );

$html = gs108p_render(
	$admin,
	array(
		'gend_society_code' => 'ABCDEF',
		'gend_base_url'     => 'http://evil.example',
		'hub'               => 'http://evil.example',
	)
);
gs108_assert( false !== strpos( $html, 'id="gs_pairing_code" name="pairing_code" class="regular-text gend-society-connect-code" value="ABCDEF"' ), 'pairing-prefill-code', 'valid ?gend_society_code pre-fills the code field' );
gs108_assert( false !== strpos( $html, '<details class="gend-society-connect-pairing" open>' ), 'pairing-prefill-open', 'details element open when pre-filled' );
gs108_assert( false === strpos( $html, 'evil.example' ), 'pairing-prefill-no-hub', 'a hub address in the query string is never used' );
gs108_assert( false === strpos( $html, 'checked' ), 'pairing-prefill-no-consent', 'consent box still unticked (no auto-submit)' );
foreach ( array( 'abcdef', 'ABCDE1', 'ABCDEFG', 'ABC<b>', 'ABCDE0', '' ) as $i => $bad ) {
	$html = gs108p_render( $admin, array( 'gend_society_code' => $bad ) );
	gs108_assert( false !== strpos( $html, 'gend-society-connect-code" value=""' ) && false !== strpos( $html, '<details class="gend-society-connect-pairing">' ), "pairing-prefill-ignored-{$i}", 'invalid code param ' . wp_json_encode( $bad ) . ' ignored' );
}
gs108_assert_eq( array(), gs108_http_log(), 'pairing-render-no-request', 'rendering the Connect screen made no HTTP request' );
gs108_assert( null === gs108c_opt( 'gend_society_consent', null ), 'pairing-render-no-consent', 'no consent recorded by rendering' );

// Card styles: only on this screen in standalone mode.
wp_dequeue_style( 'gend-society-connect' );
gend_society_portal_connect_enqueue( 'index.php' );
$other = wp_style_is( 'gend-society-connect', 'enqueued' );
gend_society_portal_connect_enqueue( 'dashboard_page_gs-portal-connect' );
gs108_assert( ! $other && wp_style_is( 'gend-society-connect', 'enqueued' ) && false !== strpos( (string) wp_styles()->registered['gend-society-connect']->src, 'assets/gs-connect.css' ), 'pairing-css', 'assets/gs-connect.css enqueued on the Connect screen only' );
gs108_assert( is_readable( dirname( __DIR__, 2 ) . '/assets/gs-connect.css' ) || is_readable( WP_PLUGIN_DIR . '/gend-society/assets/gs-connect.css' ), 'pairing-css-file', 'static CSS file shipped' );
wp_dequeue_style( 'gend-society-connect' );

// --- 2. Submit without consent: refused, 0 requests.
$GLOBALS['gs108_uat']['http'] = array();
$r                            = gs108p_pair( $admin, 'ABCDEF', false );
$n                            = gs108p_notice( $r );
gs108_assert( 'redirect' === $r['kind'] && 'error' === $n['status'] && false !== strpos( $n['message'], 'Please confirm you agree' ), 'pairing-no-consent-refused', 'submit without the consent box -> refused', wp_json_encode( $r ) );
gs108_assert_eq( array(), gs108_http_log(), 'pairing-no-consent-0-requests', 'no request without consent' );
gs108_assert( null === gs108c_opt( 'gend_society_consent', null ) && array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'pairing-no-consent-nothing', 'no consent, no pending' );

// Bad nonce: dies, nothing sent.
wp_set_current_user( $admin );
$r = gs108c_call(
	'gend_society_portal_connect_handle_submit',
	'POST',
	array(),
	array(
		'op'                      => 'connect',
		'gs_portal_connect_nonce' => 'bad',
		'gend_base_url'           => gs108c_hub(),
		'pairing_code'            => 'ABCDEF',
		'gend_society_consent'    => '1',
	)
);
gs108_assert( 'die' === $r['kind'] && array() === gs108_http_log(), 'pairing-bad-nonce', 'bad nonce -> wp_die, 0 requests' );

// --- 3. Invalid code (not connected): hub refuses, consent withdrawn, pending gone, nothing stored.
$GLOBALS['gs108_uat']['http'] = array();
$r                            = gs108p_pair( $admin, 'ZZZZZZ' );
$n                            = gs108p_notice( $r );
gs108_assert( 'error' === $n['status'] && false !== strpos( $n['message'], 'gend.me rejected the pairing (HTTP 403)' ), 'pairing-invalid-error', 'unknown code -> "gend.me rejected the pairing (HTTP 403)"', $n['message'] );
gs108_assert_eq( 1, count( gs108c_hub_calls( '/wp-json/gdc-app-manager/v1/connect' ) ), 'pairing-invalid-one-request', 'exactly one POST /connect' );
gs108_assert( null === gs108c_opt( 'gend_society_consent', null ) && array() === gs108c_opt( 'gend_society_connect_pending', array() ) && '' === (string) gs108c_opt( 'gend_society_install_token', '' ), 'pairing-invalid-clean', 'consent cleared, pending gone, no token' );

// 422 update_required text passes through (hub faked).
$off = gs108p_fake_hub( '/gdc-app-manager/v1/connect', 422, array( 'code' => 'update_required', 'message' => 'Update GenD Society to 1.4.0 or later (Dashboard > Updates), then connect again.' ) );
$r   = gs108p_pair( $admin, 'ABCDEF' );
$off();
$n = gs108p_notice( $r );
gs108_assert( false !== strpos( $n['message'], 'HTTP 422' ) && false !== strpos( $n['message'], 'Update GenD Society to 1.4.0 or later (Dashboard > Updates), then connect again.' ), 'pairing-422-message', '422 update_required message shown', $n['message'] );
gs108_assert( null === gs108c_opt( 'gend_society_consent', null ), 'pairing-422-consent', '422 -> consent withdrawn' );

// Network error: readable error, consent withdrawn.
$off = gs108p_fake_hub( '/gdc-app-manager/v1/connect', 0 );
$r   = gs108p_pair( $admin, 'ABCDEF' );
$off();
$n = gs108p_notice( $r );
gs108_assert( 'error' === $n['status'] && 0 === strpos( $n['message'], 'Connection failed:' ) && null === gs108c_opt( 'gend_society_consent', null ), 'pairing-network-error', 'network error -> "Connection failed: ..." and consent withdrawn', $n['message'] );

// --- 4. Real group code: mint on the hub, submit (lower case, with a dash: normalised like the hub does).
$code = gs108p_mint( $iid );
if ( ! gs108_assert( (bool) preg_match( '/^[A-HJ-NP-Z2-9]{6}$/', $code ), 'pairing-mint', 'hub minted a group code for GS108 Alpha', $code ) ) {
	return;
}
$typed = strtolower( substr( $code, 0, 3 ) ) . '-' . strtolower( substr( $code, 3 ) );

$seq = array();
$watch = function ( $pre, $args, $url ) use ( &$seq ) {
	if ( false !== strpos( (string) $url, '/gdc-app-manager/v1/connect' ) ) {
		$c     = gs108c_raw( 'gend_society_consent' );
		$p     = maybe_unserialize( (string) gs108c_raw( 'gend_society_connect_pending' ) );
		$seq[] = array(
			'url'        => (string) $url,
			'body'       => json_decode( (string) ( $args['body'] ?? '' ), true ),
			'consent'    => null !== $c ? maybe_unserialize( $c ) : null,
			'pending'    => is_array( $p ) ? $p : null,
			'at'         => time(),
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $watch, -1000, 3 );
$GLOBALS['gs108_uat']['http'] = array();
$t0                           = time();
$r                            = gs108p_pair( $admin, $typed );
remove_filter( 'pre_http_request', $watch, -1000 );
$n = gs108p_notice( $r );

gs108_assert( 'success' === $n['status'] && false !== strpos( $n['message'], 'Connected to gend.me as part of GS108 Alpha' ), 'pairing-connected', 'success "Connected to gend.me as part of GS108 Alpha"', wp_json_encode( $n ) );
gs108_assert_eq( 1, count( $seq ), 'pairing-one-connect', 'exactly one POST /connect' );
$s = $seq ? $seq[0] : array();
gs108_assert_eq( gs108c_hub() . '/wp-json/gdc-app-manager/v1/connect', $s['url'] ?? '', 'pairing-endpoint', 'request to the entered gend.me URL' );
$b = isset( $s['body'] ) && is_array( $s['body'] ) ? $s['body'] : array();
gs108_assert_eq( $code, $b['pairing_code'] ?? '', 'pairing-code-normalised', 'code sent uppercase, A-Z0-9 only' );
gs108_assert( ( $b['install_id'] ?? '' ) === $iid && ( $b['society_pubkey'] ?? '' ) === gend_society_connect_identity()['pubkey_b64'], 'pairing-identity', 'install_id + pubkey are this install\'s identity' );
gs108_assert( ( $b['admin_url'] ?? '' ) === admin_url() && ( $b['plugin_version'] ?? null ) === GEND_SOCIETY_VERSION && ( $b['install_url'] ?? '' ) === home_url( '/' ) && ( $b['admin_email'] ?? '' ) === get_option( 'admin_email' ), 'pairing-body-fields', 'admin_url, plugin_version, install_url, admin_email sent' );
gs108_assert_eq( array( 'admin_email', 'admin_url', 'install_id', 'install_url', 'pairing_code', 'plugin_version', 'society_pubkey' ), ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $b ) ), 'pairing-body-keys', 'body has exactly the disclosed fields' );
gs108_assert( is_array( $s['consent'] ?? null ) && 'pairing-code' === ( $s['consent']['source'] ?? '' ) && (int) ( $s['consent']['at'] ?? 0 ) >= $t0 && (int) $s['consent']['at'] <= (int) $s['at'], 'pairing-consent-first', 'consent (source pairing-code) recorded before the request', wp_json_encode( $s['consent'] ?? null ) );
gs108_assert( is_array( $s['pending'] ?? null ) && 'pairing' === $s['pending']['via'] && hash( 'sha256', 'pair|' . $code . '|' . $iid ) === $s['pending']['request_id'], 'pairing-pending-request-id', 'pending prepared with request_id sha256(pair|CODE|install_id) while in flight' );

$conn = gs108c_opt( 'gend_society_connection', array() );
gs108_assert( '' !== (string) gs108c_opt( 'gend_society_install_token', '' ) && gs108c_hub() === gs108c_opt( 'gend_society_gend_base_url' ) && 32 === strlen( (string) base64_decode( (string) gs108c_opt( 'gend_society_gend_pubkey', '' ), true ) ), 'pairing-stored', 'token, base URL and 32-byte gend pubkey stored' );
gs108_assert( is_array( $conn ) && 'pairing' === ( $conn['via'] ?? '' ) && 'GS108 Alpha' === ( $conn['group']['name'] ?? '' ) && 3 === (int) ( $conn['group']['id'] ?? 0 ) && $admin === (int) ( $conn['owner_user_id'] ?? 0 ) && '' !== ( $conn['approved_by']['display_name'] ?? '' ), 'pairing-connection', 'connection: via pairing, group GS108 Alpha (3), owner = admin, approved_by set', wp_json_encode( $conn ) );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'pairing-pending-gone', 'pending removed after success' );
gs108_assert( 'pairing-code' === ( gs108c_opt( 'gend_society_consent', array() )['source'] ?? '' ), 'pairing-consent-kept', 'consent record kept (source pairing-code)' );
$token = (string) gs108c_opt( 'gend_society_install_token', '' );
gs108_assert_eq( 200, gs108p_health( $iid, $token ), 'pairing-health', 'hub health with the new token -> 200' );
$hub = gs108p_inspect( $iid );
gs108_assert( ! empty( $hub['found'] ) && 'pairing-code' === ( $hub['via'] ?? '' ) && 3 === (int) ( $hub['group_id'] ?? 0 ) && GEND_SOCIETY_VERSION === ( $hub['plugin_version'] ?? '' ) && admin_url() === ( $hub['admin_url'] ?? '' ), 'pairing-hub-record', 'hub record: via pairing-code, group 3, plugin_version + admin_url stored', wp_json_encode( $hub ) );

// --- 5. Connected card.
$html = gs108p_render( $admin );
gs108_assert( false !== strpos( $html, 'Connected to gend.me' ) && false === strpos( $html, 'Agree and connect to gend.me' ), 'pairing-card-connected', 'Connected card replaces the Connect card' );
gs108_assert( false !== strpos( $html, '>' . esc_html( gs108c_hub() ) . '</a>' ), 'pairing-card-hub', 'hub URL shown' );
gs108_assert( false !== strpos( $html, '<code>' . esc_html( $iid ) . '</code>' ), 'pairing-card-install-id', 'install ID shown' );
gs108_assert( false !== strpos( $html, 'GS108 Alpha' ) && ( '' === (string) ( $conn['group']['avatar'] ?? '' ) || false !== strpos( $html, 'alt="GS108 Alpha" width="48" height="48"' ) ), 'pairing-card-group', 'group name (+ 48px avatar with alt = name when the hub sent one)' );
gs108_assert( false !== strpos( $html, esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) gs108c_opt( 'gend_society_connected_at', 0 ) ) ) ), 'pairing-card-date', 'connected-at in the site format' );
gs108_assert( false !== strpos( $html, esc_html( get_userdata( $admin )->display_name ) ) && false !== strpos( $html, 'Pairing code</td>' ), 'pairing-card-owner-via', 'owner display name + "Pairing code" shown' );
gs108_assert( false !== strpos( $html, 'name="op" value="disconnect"' ) && false === strpos( $html, 'Reconnect to gend.me' ) && false === strpos( $html, 'Disconnect locally anyway' ), 'pairing-card-buttons', 'Disconnect present; no Reconnect / local-only button while healthy' );

// --- 6. Replay of the used code while connected: refused, token/connection unchanged, consent restored.
$before_conn    = gs108p_conn_raw();
$before_consent = gs108p_consent_raw();
$r              = gs108p_pair( $admin, $code );
$n              = gs108p_notice( $r );
gs108_assert( 'error' === $n['status'] && false !== strpos( $n['message'], 'gend.me rejected the pairing' ), 'pairing-replay-refused', 'replayed code -> error', $n['message'] );
gs108_assert( $before_conn === gs108p_conn_raw(), 'pairing-replay-conn-same', 'token + connection byte-identical' );
gs108_assert( $before_consent === gs108p_consent_raw(), 'pairing-replay-consent-restored', 'consent byte-identical to before the click (never cleared while connected)' );
gs108_assert( array() === gs108c_opt( 'gend_society_connect_pending', array() ), 'pairing-replay-pending', 'pending gone' );
gs108_assert_eq( 200, gs108p_health( $iid, $token ), 'pairing-replay-token-valid', 'token still valid on the hub' );
