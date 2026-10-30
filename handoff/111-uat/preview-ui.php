<?php
/**
 * Phase 111-10 battery: Preview in the existing header, the Domains cutover copy, Make live, noindex (MIG-09, MIG-10, P1, P6, N-B3).
 *
 *   wp eval-file preview-ui.php <section>[,<section>...]     (C:/tmp/livepull/phase111/tools/sc-uat-111.sh bed preview-ui <sections>)
 *
 * Sections:
 *   badge-preview    Preview badge (not Container Live), steps 1-4 done and Live pending
 *   note-copy        the note: preview URL as a safe link, the three sentences, disabled Catch up button; hidden for non-admins and after Live
 *   badge-live       Live payload: Container Live, no Preview badge, no note, no header progress
 *   domains-copy     armed Domains panel: DNS instructions from the payload, TTL tip, MX/SPF/DKIM/DMARC, CAA, 5 minutes; no hub-IP wording; non-armed panel unchanged
 *   cutover-off      domain_cutover false: the exact sentence, no add / verify / remove / Make live now controls, records as "what you will need later"
 *   ssl-stage        every domains[].stage renders its wording; done-without-ssl shows the retry wording and button
 *   make-live-ajax   nonce + manage_options enforced; refuses when not Preview; one POST migration/go-live; hub errors surface
 *   noindex          header + robots noindex only for the preview host with live false, or while the container's own marker is set
 *   no-new-surfaces  no new menu page, popup, remote asset or iframe
 *
 * gend.me is stubbed through pre_http_request (only the fixture base URL is answered and recorded). Options touched are
 * restored after each section. Last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs111pv'] = array( 'pass' => 0, 'fail' => 0, 'cleanup' => array(), 'http' => array() );

class GS111PV_Die extends \Exception {
	public $args_in = array();
}

const GS111PV_INSTALL_ID = '7c0a0b1e-1111-4111-8111-111000000010';
const GS111PV_TOKEN      = 'c111c111c111c111c111c111c111c111c111c111c111c111c111c111c111c111';
const GS111PV_BASE       = 'http://localhost:8919';
const GS111PV_PREVIEW    = 'https://abc-gs111.gend.test/';
const GS111PV_OPTIONS    = array(
	'gend_society_install_id',
	'gend_society_install_token',
	'gend_society_gend_base_url',
	'gend_society_consent',
	'gend_society_remote_consent',
	'gend_society_remote_membership_cache',
	'gend_society_remote_membership_cache_expires',
	'gend_society_remote_membership_cache_fetched_at',
	'gend_society_container_preview',
	'gend_society_container_imported',
	'gend_society_verified_domains',
);

function gs111pv_assert( $c, $id, $t = '', $d = '' ) {
	if ( $c ) {
		$GLOBALS['gs111pv']['pass']++;
		echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
	} else {
		$GLOBALS['gs111pv']['fail']++;
		echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . ( '' !== $d ? ' -- ' . $d : '' ) . "\n";
	}
	return (bool) $c;
}
function gs111pv_on_cleanup( callable $fn ) {
	$GLOBALS['gs111pv']['cleanup'][] = $fn;
}
function gs111pv_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs111pv']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo 'INFO: cleanup failed: ' . $e->getMessage() . "\n";
		}
	}
	$GLOBALS['gs111pv']['cleanup'] = array();
	wp_set_current_user( 0 );
}
function gs111pv_keep_options() {
	$snap = array();
	foreach ( GS111PV_OPTIONS as $n ) {
		$snap[ $n ] = get_option( $n, '__gs111pv_absent__' );
	}
	gs111pv_on_cleanup(
		function () use ( $snap ) {
			foreach ( $snap as $n => $v ) {
				if ( '__gs111pv_absent__' === $v ) {
					delete_option( $n );
				} else {
					update_option( $n, $v, false );
				}
			}
		}
	);
}
function gs111pv_load_modules() {
	if ( ! function_exists( 'gend_society_preview_noindex_active' ) ) {
		require_once GEND_SOCIETY_DIR . 'inc/migration-preview.php';
	}
	if ( ! function_exists( 'gend_society_host_urls_sync' ) ) {
		require_once GEND_SOCIETY_DIR . 'inc/container-host-urls.php';
	}
}
/** Pair the fixture install (so the token proxy and the consent gate pass) and stub the hub. */
function gs111pv_pair( callable $router = null ) {
	gs111pv_keep_options();
	update_option( 'gend_society_install_id', GS111PV_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS111PV_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS111PV_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs111pv' ), false );
	$GLOBALS['gs111pv']['http'] = array();
	$f                          = function ( $pre, $args, $url ) use ( $router ) {
		$GLOBALS['gs111pv']['http'][] = array( 'url' => $url, 'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ) );
		if ( 0 === strpos( $url, GS111PV_BASE . '/' ) ) {
			$path = preg_replace( '#^.*/install/[^/]+/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
			list( $code, $data ) = $router ? $router( $path, strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) : array( 404, array( 'message' => 'stub' ) );
			return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $f, 1, 3 );
	gs111pv_on_cleanup(
		function () use ( $f ) {
			remove_filter( 'pre_http_request', $f, 1 );
		}
	);
}
function gs111pv_calls( $method = '', $suffix = '' ) {
	$out = array();
	foreach ( $GLOBALS['gs111pv']['http'] as $r ) {
		if ( 0 !== strpos( $r['url'], GS111PV_BASE . '/' ) ) {
			continue;
		}
		if ( '' !== $method && $r['method'] !== $method ) {
			continue;
		}
		if ( '' !== $suffix && ! preg_match( '#/install/[^/]+/' . preg_quote( $suffix, '#' ) . '(\?|$)#', $r['url'] ) ) {
			continue;
		}
		$out[] = $r;
	}
	return $out;
}

/** Named migration blocks as the hub's /membership emits them (111-02, 111-06). */
function gs111pv_mig( $name, array $over = array() ) {
	$base  = array(
		'stage'          => '',
		'index'          => 0,
		'live'           => false,
		'steps'          => array( 'prepare', 'export', 'provision', 'verify', 'live' ),
		'state'          => 'idle',
		'can_start'      => false,
		'can_retry'      => false,
		'can_cancel'     => false,
		'scheduled_at'   => 0,
		'error'          => '',
		'preview'        => false,
		'preview_url'    => '',
		'migrated_to'    => '',
		'migrated_at'    => 0,
		'domain_cutover' => true,
	);
	$named = array(
		'preview'     => array( 'stage' => 'verify', 'index' => 4, 'state' => 'preview', 'preview' => true, 'preview_url' => GS111PV_PREVIEW ),
		'preview-off' => array( 'stage' => 'verify', 'index' => 4, 'state' => 'preview', 'preview' => true, 'preview_url' => GS111PV_PREVIEW, 'domain_cutover' => false ),
		'live'        => array( 'stage' => 'live', 'index' => 5, 'live' => true, 'state' => 'complete', 'migrated_to' => 'abc-gs111.gend.test', 'migrated_at' => 1790000000 ),
		'hub-site'    => array( 'stage' => 'live', 'index' => 5, 'live' => true, 'state' => 'idle' ),
	);
	return array_merge( $base, $named[ $name ], $over );
}
function gs111pv_domain( $host, $stage, $with_dns = true ) {
	$d = array(
		'host'    => $host,
		'stage'   => $stage,
		'primary' => false,
		'secure'  => 'done' === $stage,
	);
	if ( $with_dns ) {
		$d['dns']  = array(
			'a_record'   => array( 'host' => $host, 'type' => 'A', 'value' => '203.0.113.77', 'ttl' => 300 ),
			'txt_record' => array( 'host' => '_gend-verify.' . $host, 'type' => 'TXT', 'value' => 'gs111-token-abc123', 'ttl' => 300 ),
		);
		$d['note'] = 'Verification passes once the A record points at the app; certificates are issued 1-2 minutes after.';
	}
	return $d;
}
function gs111pv_payload( $name, array $over = array(), array $domains = array() ) {
	$p = array(
		'membership_id'  => 4243,
		'membership_url' => GS111PV_BASE . '/my-account/membership/4243/',
		'hub_url'        => GS111PV_BASE . '/',
		'app_url'        => home_url( '/' ),
		'app_title'      => 'gs111pv bed',
		'status'         => 'active',
		'status_label'   => 'Active',
		'migration'      => gs111pv_mig( $name, $over ),
		'domains'        => $domains,
		'cache_seconds'  => 60,
		'rev'            => 'gs111pv-' . $name,
	);
	return $p;
}
function gs111pv_store( array $p ) {
	update_option( 'gend_society_remote_membership_cache', $p, false );
	update_option( 'gend_society_remote_membership_cache_expires', time() + 3600, false );
}
function gs111pv_render( callable $fn ) {
	ob_start();
	try {
		$fn();
	} finally {
		$html = ob_get_clean();
	}
	return (string) $html;
}
function gs111pv_panel( array $payload, $uid = 1 ) {
	wp_set_current_user( $uid );
	return gs111pv_render(
		function () use ( $payload ) {
			echo gend_society_render_membership_panel( $payload ); // phpcs:ignore
		}
	);
}
function gs111pv_domains( array $payload, $uid = 1 ) {
	wp_set_current_user( $uid );
	return gs111pv_render(
		function () use ( $payload ) {
			gend_society_render_hosting_domains_panel( $payload );
		}
	);
}
/** The header block: from the first progress element to the grid comment. */
function gs111pv_header( $html ) {
	$s = strpos( $html, '<div class="gs-mship-header">' );
	if ( false === $s ) {
		return '';
	}
	$e = strpos( $html, '<!-- ── 2-card grid', $s );
	return false === $e ? substr( $html, $s ) : substr( $html, $s, $e - $s );
}
function gs111pv_subscriber() {
	$u = get_user_by( 'login', 'gs111pv_sub' );
	if ( ! $u ) {
		$id = wp_insert_user( array( 'user_login' => 'gs111pv_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'gs111pv_sub@example.test', 'role' => 'subscriber' ) );
		$u  = get_user_by( 'id', $id );
		gs111pv_on_cleanup(
			function () use ( $id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $id );
			}
		);
	}
	return (int) $u->ID;
}

$S = array();

$S['badge-preview'] = function () {
	gs111pv_pair();
	$html = gs111pv_panel( gs111pv_payload( 'preview' ) );
	$head = gs111pv_header( $html );
	gs111pv_assert( '' !== $head, 'bp-header', 'the existing .gs-mship-header renders' );
	gs111pv_assert( false !== strpos( $head, 'gs-mship-status-badge' ) && false !== strpos( $head, '● Preview' ), 'bp-badge', 'the badge reads Preview' );
	gs111pv_assert( false === strpos( $html, 'Container Live' ), 'bp-not-live', 'Container Live is not shown in Preview' );
	$done = preg_match_all( '#<div class="gs-mship-step is-done"><span class="dot"></span><span class="label">([^<]+)</span>#', $head, $m ) ? $m[1] : array();
	gs111pv_assert( array( 'Prepare', 'Export', 'Provision', 'Verify' ) === $done, 'bp-steps-done', 'steps 1-4 are done', wp_json_encode( $done ) );
	gs111pv_assert( (bool) preg_match( '#<div class="gs-mship-step"><span class="dot"></span><span class="label">Live</span>#', $head ), 'bp-live-pending', 'step 5 Live is pending' );
	gs111pv_assert( false === strpos( $head, 'data-poll="1"' ), 'bp-no-poll', 'no 15 s repaint poll in Preview' );
};

$S['note-copy'] = function () {
	gs111pv_pair();
	$p    = gs111pv_payload( 'preview' );
	$html = gs111pv_panel( $p );
	$head = gs111pv_header( $html );
	gs111pv_assert( false !== strpos( $head, 'data-gs-preview-note="1"' ), 'nc-note', 'the note renders for an admin inside the header block' );
	gs111pv_assert( false !== strpos( $head, '<a href="' . GS111PV_PREVIEW . '" target="_blank" rel="noopener noreferrer">' . GS111PV_PREVIEW . '</a>' ), 'nc-link', 'the preview URL is a link with target _blank and rel noopener noreferrer' );
	gs111pv_assert( false !== strpos( $head, 'Your domain still points at your original site.' ), 'nc-s1', 'sentence 1' );
	gs111pv_assert( false !== strpos( $head, 'You can edit the preview, but the catch-up before you go live overwrites changes made to the same posts.' ), 'nc-s2', 'sentence 2' );
	gs111pv_assert( false !== strpos( $head, 'Nothing is switched until you add your domain or press Make live.' ), 'nc-s3', 'sentence 3' );
	gs111pv_assert( (bool) preg_match( '#<button type="button" class="gs-mship-action-btn is-secondary" data-gs-delta="1" disabled>Catch up changes</button>#', $head ), 'nc-delta', 'a DISABLED Catch up changes button with data-gs-delta' );
	// A hostile preview URL is not linked.
	$bad  = gs111pv_payload( 'preview', array( 'preview_url' => 'javascript:alert(1)' ) );
	$hb   = gs111pv_header( gs111pv_panel( $bad ) );
	gs111pv_assert( false === strpos( $hb, 'javascript:' ) && false === strpos( $hb, '<a href=' ), 'nc-bad-url', 'a non-http(s) preview URL is never linked or printed' );
	// Non-admins: no note, no preview URL anywhere.
	$sub  = gs111pv_subscriber();
	$hs   = gs111pv_panel( $p, $sub );
	gs111pv_assert( false === strpos( $hs, 'data-gs-preview-note' ) && false === strpos( $hs, 'abc-gs111.gend.test' ), 'nc-non-admin', 'a subscriber sees neither the note nor the preview URL' );
	$hv   = gs111pv_panel( $p, 0 );
	gs111pv_assert( false === strpos( $hv, 'data-gs-preview-note' ) && false === strpos( $hv, 'abc-gs111.gend.test' ), 'nc-visitor', 'a logged-out visitor sees neither' );
	$hl = gs111pv_panel( gs111pv_payload( 'live' ) );
	gs111pv_assert( false === strpos( $hl, 'data-gs-preview-note' ), 'nc-gone-live', 'after Live the note is gone' );
};

$S['badge-live'] = function () {
	gs111pv_pair();
	$html = gs111pv_panel( gs111pv_payload( 'live' ) );
	$head = gs111pv_header( $html );
	gs111pv_assert( false !== strpos( $head, '● Container Live' ), 'bl-badge', 'Live payload: the badge reads Container Live' );
	gs111pv_assert( false === strpos( $html, 'gs-mship-status-badge--preview' ) && false === strpos( $html, '● Preview' ), 'bl-no-preview', 'no Preview badge after Live' );
	gs111pv_assert( false === strpos( $html, 'data-gs-preview-note' ), 'bl-no-note', 'no Preview note' );
	gs111pv_assert( false === strpos( $head, 'class="gs-mship-progress' ), 'bl-no-progress', 'the header progress is hidden after Live (as in Phase 110)' );
	$plain = gs111pv_panel( gs111pv_payload( 'hub-site' ) );
	gs111pv_assert( false === strpos( $plain, 'data-gs-preview-note' ) && false === strpos( $plain, 'gs-mship-status-badge--preview' ), 'bl-hub-site', 'a payload without preview renders no Preview markup' );
};

$S['domains-copy'] = function () {
	gs111pv_pair();
	$doms = array( gs111pv_domain( 'shop-gs111.example.test', 'checking-dns' ) );
	$html = gs111pv_domains( gs111pv_payload( 'preview', array(), $doms ) );
	gs111pv_assert( false === stripos( $html, 'hub IP' ), 'dc-no-hub-ip', 'no "hub IP" wording on an armed panel' );
	gs111pv_assert( false !== strpos( $html, 'Add domain' ) && false !== strpos( $html, 'data-gs-mship="add-domain"' ), 'dc-add-button', 'the Add domain button and form render when the switch is on' );
	foreach ( array(
		'dc-ttl'      => 'TTL 300 seconds (5 minutes). Lower the TTL of your current A record to 300 a few hours before you switch',
		'dc-txt'      => '_gend-verify.your-domain',
		'dc-verify'   => 'Verification passes after the A record is switched to the app address. A certificate is issued 1-2 minutes after that.',
		'dc-mail'     => 'Keep your MX records and your email TXT records (SPF, DKIM, DMARC) as they are',
		'dc-caa'      => 'If your DNS has CAA records, allow letsencrypt.org.',
		'dc-5min'     => 'A newly verified domain can take up to 5 minutes to start working on your new site.',
	) as $id => $needle ) {
		gs111pv_assert( false !== strpos( $html, $needle ), $id, $needle );
	}
	gs111pv_assert( false !== strpos( $html, 'A record: shop-gs111.example.test -&gt; 203.0.113.77 (TTL 300)' ) || false !== strpos( $html, 'A record: shop-gs111.example.test -> 203.0.113.77 (TTL 300)' ), 'dc-row-a', 'the domain row lists the payload A record' );
	gs111pv_assert( false !== strpos( $html, 'TXT record: _gend-verify.shop-gs111.example.test' ) && false !== strpos( $html, 'gs111-token-abc123' ), 'dc-row-txt', 'the domain row lists the payload TXT name and token' );
	gs111pv_assert( false !== strpos( $html, 'data-gs-make-live="1"' ) && false !== strpos( $html, 'Make live now' ), 'dc-make-live', 'Make live now renders in Preview' );
	gs111pv_assert( false !== strpos( $html, 'This tells gend.me your site now lives at the app; switch your DNS first or at the same time.' ), 'dc-confirm', 'the confirm sentence' );
	gs111pv_assert( false !== strpos( $html, 'data-gs-mship="verify-domain"' ) && false !== strpos( $html, 'data-gs-mship="remove-domain"' ), 'dc-handlers', 'the existing verify / remove handler hooks are kept (regression)' );
	// Live after an import: instructions stay, Make live now is gone.
	update_option( 'gend_society_container_imported', 1, false );
	$live = gs111pv_domains( gs111pv_payload( 'live', array(), $doms ) );
	gs111pv_assert( false !== strpos( $live, 'data-gs-domains-armed="1"' ) && false !== strpos( $live, 'Keep your MX records' ), 'dc-live-armed', 'a Live imported container keeps the instructions' );
	gs111pv_assert( false === strpos( $live, 'data-gs-make-live' ), 'dc-live-no-button', 'no Make live now after Live' );
	delete_option( 'gend_society_container_imported' );
	// A hub-created site (no preview, not imported) keeps the old panel.
	$old = gs111pv_domains( gs111pv_payload( 'hub-site', array(), array( gs111pv_domain( 'plain-gs111.example.test', 'done', false ) ) ) );
	gs111pv_assert( false !== strpos( $old, 'Point an A record at the hub IP first, then enter the hostname here to register and verify.' ), 'dc-old-copy', 'a hub-created site keeps its original copy' );
	gs111pv_assert( false === strpos( $old, 'data-gs-domains-armed' ) && false === strpos( $old, 'data-gs-make-live' ) && false === strpos( $old, '>SSL<' ), 'dc-old-plain', 'no armed markup, no Make live, no SSL column for it' );
	gs111pv_assert( false !== strpos( $old, 'data-gs-mship="add-domain"' ) && false !== strpos( $old, 'data-gs-mship="verify-domain"' ) && false !== strpos( $old, 'data-gs-mship="remove-domain"' ), 'dc-old-handlers', 'add / verify / remove still render for it' );
};

$S['cutover-off'] = function () {
	gs111pv_pair();
	$doms = array( gs111pv_domain( 'shop-gs111.example.test', 'checking-dns' ) );
	$html = gs111pv_domains( gs111pv_payload( 'preview-off', array(), $doms ) );
	gs111pv_assert( false !== strpos( $html, 'Adding and verifying your domain is not available yet. Do not change your DNS until this panel shows the Add domain button.' ), 'co-sentence', 'the exact not-available sentence' );
	gs111pv_assert( false === strpos( $html, 'data-gs-mship="add-domain"' ) && false === strpos( $html, '<form' ), 'co-no-form', 'no add form' );
	gs111pv_assert( false === strpos( $html, 'data-gs-mship="verify-domain"' ) && false === strpos( $html, 'data-gs-mship="remove-domain"' ), 'co-no-verify-remove', 'no Verify / Remove buttons' );
	gs111pv_assert( false === strpos( $html, 'data-gs-make-live' ) && false === strpos( $html, 'Make live now' ), 'co-no-make-live', 'no Make live now' );
	gs111pv_assert( false !== strpos( $html, 'What you will need later' ) && false !== strpos( $html, 'Keep your MX records' ) && false !== strpos( $html, '_gend-verify.your-domain' ), 'co-later', 'the DNS instructions stay visible as "what you will need later"' );
	gs111pv_assert( false === strpos( $html, 'DNS records for your domain' ), 'co-not-now-heading', 'no "do this now" heading' );
	gs111pv_assert( false === strpos( $html, 'up to 5 minutes to start working' ) && false === strpos( $html, 'Enter your domain' ), 'co-no-now-copy', 'no instruction to add a domain now, no 5-minute timing (it only applies once on)' );
	gs111pv_assert( false === strpos( $html, '<script>' ), 'co-no-script', 'no Make live script' );
	$on = gs111pv_domains( gs111pv_payload( 'preview', array(), $doms ) );
	gs111pv_assert( false === strpos( $on, 'is not available yet' ) && false !== strpos( $on, 'up to 5 minutes' ), 'co-on', 'with the switch on the copy flips' );
	// Live armed + switch off behaves the same way.
	update_option( 'gend_society_container_imported', 1, false );
	$lo = gs111pv_domains( gs111pv_payload( 'live', array( 'domain_cutover' => false ), array() ) );
	delete_option( 'gend_society_container_imported' );
	gs111pv_assert( false !== strpos( $lo, 'is not available yet' ) && false === strpos( $lo, 'data-gs-mship="add-domain"' ), 'co-live-off', 'Live armed with the switch off: still no controls' );
};

$S['ssl-stage'] = function () {
	gs111pv_pair();
	$want = array(
		'checking-dns'      => 'Waiting for your DNS records',
		'checking-ssl-cert' => 'Issuing the certificate (1-2 minutes)',
		'done'              => 'Secure (certificate issued)',
		'done-without-ssl'  => 'Working, but the certificate was not issued yet. Press Retry certificate.',
		'failed'            => 'Failed: check the records and press Verify',
	);
	$doms = array();
	$i    = 0;
	foreach ( array_keys( $want ) as $stage ) {
		$doms[] = gs111pv_domain( 'd' . ( ++$i ) . '-gs111.example.test', $stage );
	}
	$html = gs111pv_domains( gs111pv_payload( 'preview', array(), $doms ) );
	$i    = 0;
	foreach ( $want as $stage => $text ) {
		$i++;
		$row = preg_match( '#<tr data-domain="d' . $i . '-gs111\.example\.test">.*?</tr>#s', $html, $m ) ? $m[0] : '';
		gs111pv_assert( '' !== $row && false !== strpos( $row, 'data-gs-domain-stage="' . $stage . '"' ) && false !== strpos( $row, esc_html( $text ) ), 'ss-' . $stage, $stage . ' -> ' . $text );
		if ( 'done-without-ssl' === $stage ) {
			gs111pv_assert( false !== strpos( $row, 'Retry certificate' ), 'ss-retry-button', 'done-without-ssl offers Retry certificate (the same verify action)' );
		}
	}
	gs111pv_assert( false !== strpos( $html, '<th>SSL</th>' ), 'ss-column', 'an SSL column heads the armed table' );
};

/** Run the Make live handler in-process: returns array( 'status' => int, 'body' => array|null, 'died' => string ). */
function gs111pv_ajax( $uid, $nonce ) {
	wp_set_current_user( $uid );
	$_REQUEST['nonce'] = $nonce;
	$_POST['nonce']    = $nonce;
	$status            = 200;
	$sh                = function ( $h, $code ) use ( &$status ) {
		$status = (int) $code;
		return $h;
	};
	add_filter( 'status_header', $sh, 10, 2 );
	$dg = function () {
		return function ( $message, $title = '', $args = array() ) {
			$e          = new GS111PV_Die( is_scalar( $message ) ? (string) $message : '' );
			$e->args_in = is_array( $args ) ? $args : array();
			throw $e;
		};
	};
	add_filter( 'wp_die_ajax_handler', $dg );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$died = null;
	ob_start();
	try {
		gend_society_migration_go_live_ajax();
	} catch ( GS111PV_Die $e ) {
		$died = $e;
	} finally {
		$out = ob_get_clean();
		remove_filter( 'status_header', $sh, 10 );
		remove_filter( 'wp_die_ajax_handler', $dg );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_REQUEST['nonce'], $_POST['nonce'] );
	}
	$body = json_decode( (string) $out, true );
	if ( null !== $died && isset( $died->args_in['response'] ) && is_int( $died->args_in['response'] ) ) {
		$status = $died->args_in['response'];
	}
	return array( 'status' => $status, 'body' => is_array( $body ) ? $body : null, 'died' => null === $died ? '' : $died->getMessage(), 'raw' => $out );
}

$S['make-live-ajax'] = function () {
	$state = array( 'live' => false );
	gs111pv_pair(
		function ( $path, $method ) use ( &$state ) {
			if ( 'membership' === $path ) {
				return array( 200, $state['live'] ? gs111pv_payload( 'live' ) : gs111pv_payload( 'preview' ) );
			}
			if ( 'migration/go-live' === $path && 'POST' === $method ) {
				if ( ! empty( $state['refuse'] ) ) {
					return array( 409, array( 'code' => 'cutover_disabled', 'message' => 'Adding and verifying your domain is not available yet.' ) );
				}
				$state['live'] = true;
				return array( 200, array( 'ok' => true, 'state' => 'complete', 'already' => false ) );
			}
			return array( 404, array( 'message' => 'stub: ' . $path ) );
		}
	);
	if ( ! gs111pv_assert( function_exists( 'gend_society_migration_go_live_ajax' ), 'ml-handler', 'gend_society_migration_go_live_ajax() exists' ) ) {
		return;
	}
	gs111pv_assert( has_action( 'wp_ajax_gend_society_migration_go_live', 'gend_society_migration_go_live_ajax' ), 'ml-registered', 'registered on wp_ajax_ only (no nopriv)' );
	gs111pv_assert( ! has_action( 'wp_ajax_nopriv_gend_society_migration_go_live' ), 'ml-no-nopriv', 'no wp_ajax_nopriv_ registration' );
	$nonce = wp_create_nonce( 'gs_membership_action' );
	// No nonce.
	$r = gs111pv_ajax( 1, '' );
	gs111pv_assert( '-1' === $r['died'] && 0 === count( gs111pv_calls( 'POST', 'migration/go-live' ) ), 'ml-no-nonce', 'no nonce: refused, no hub call', wp_json_encode( $r ) );
	// Wrong nonce.
	$r = gs111pv_ajax( 1, 'deadbeef00' );
	gs111pv_assert( '-1' === $r['died'] && 0 === count( gs111pv_calls( 'POST', 'migration/go-live' ) ), 'ml-bad-nonce', 'wrong nonce: refused, no hub call' );
	// Subscriber with a valid nonce (nonce made for that user).
	$sub   = gs111pv_subscriber();
	wp_set_current_user( $sub );
	$snon  = wp_create_nonce( 'gs_membership_action' );
	$r     = gs111pv_ajax( $sub, $snon );
	gs111pv_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'Forbidden.' === ( $r['body']['data']['message'] ?? '' ) && 0 === count( gs111pv_calls( 'POST', 'migration/go-live' ) ), 'ml-no-cap', 'subscriber with a valid nonce: Forbidden, no hub call', wp_json_encode( $r ) );
	// Logged out.
	$r = gs111pv_ajax( 0, $nonce );
	gs111pv_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'Forbidden.' === ( $r['body']['data']['message'] ?? '' ) && 0 === count( gs111pv_calls( 'POST', 'migration/go-live' ) ), 'ml-logged-out', 'logged out: Forbidden, no hub call' );
	// Not in Preview (the hub says live).
	$state['live'] = true;
	wp_set_current_user( 1 );
	$nonce = wp_create_nonce( 'gs_membership_action' );
	$r     = gs111pv_ajax( 1, $nonce );
	gs111pv_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'not_preview' === ( $r['body']['data']['code'] ?? '' ) && 0 === count( gs111pv_calls( 'POST', 'migration/go-live' ) ), 'ml-not-preview', 'not Preview: refused (not_preview), no POST to the hub', wp_json_encode( $r ) );
	// Hub refuses (switch off).
	$state['live']   = false;
	$state['refuse'] = true;
	$r               = gs111pv_ajax( 1, $nonce );
	gs111pv_assert( is_array( $r['body'] ) && empty( $r['body']['success'] ) && 'remote_409' === ( $r['body']['data']['code'] ?? '' ) && false !== strpos( (string) ( $r['body']['data']['message'] ?? '' ), 'not available yet' ), 'ml-hub-error', 'a hub 409 surfaces its friendly message', wp_json_encode( $r ) );
	// Success.
	$state['refuse'] = false;
	$before          = count( gs111pv_calls( 'POST', 'migration/go-live' ) );
	$r               = gs111pv_ajax( 1, $nonce );
	$posts           = count( gs111pv_calls( 'POST', 'migration/go-live' ) ) - $before;
	gs111pv_assert( is_array( $r['body'] ) && ! empty( $r['body']['success'] ) && true === ( $r['body']['data']['ok'] ?? null ) && 'complete' === ( $r['body']['data']['state'] ?? '' ), 'ml-ok', 'Preview + admin + nonce: {ok,state:complete}', wp_json_encode( $r ) );
	gs111pv_assert( 1 === $posts, 'ml-one-post', 'exactly one POST /install/{id}/migration/go-live', (string) $posts );
	$cached = get_option( 'gend_society_remote_membership_cache', array() );
	gs111pv_assert( ! empty( $cached['migration']['live'] ), 'ml-refetched', 'the payload was refetched so the reload paints Live' );
	$post = gs111pv_calls( 'POST', 'migration/go-live' );
	gs111pv_assert( $post && false === strpos( $post[0]['url'], GS111PV_TOKEN ), 'ml-no-token-in-url', 'the install token is not in the URL' );
};

$S['noindex'] = function () {
	gs111pv_load_modules();
	gs111pv_keep_options();
	$on = function () {
		return true;
	};
	add_filter( 'gend_society_preview_enabled', $on );
	add_filter( 'gend_society_host_urls_enabled', $on );
	$old_host = $_SERVER['HTTP_HOST'] ?? null;
	gs111pv_on_cleanup(
		function () use ( $on, $old_host ) {
			remove_filter( 'gend_society_preview_enabled', $on );
			remove_filter( 'gend_society_host_urls_enabled', $on );
			if ( null === $old_host ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $old_host;
			}
		}
	);
	// A spy: the front-end decision must make no network call at all.
	$spy = function ( $pre, $args, $url ) {
		$GLOBALS['gs111pv']['http'][] = array( 'url' => $url, 'method' => 'GET' );
		return $pre; // only records; the answers below sit at a later priority
	};
	$GLOBALS['gs111pv']['http'] = array();
	add_filter( 'pre_http_request', $spy, 1, 3 );
	gs111pv_on_cleanup(
		function () use ( $spy ) {
			remove_filter( 'pre_http_request', $spy, 1 );
		}
	);
	$decide = function ( $host ) {
		$_SERVER['HTTP_HOST'] = $host;
		$h = apply_filters( 'wp_headers', array() );
		$r = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
		return array(
			'active' => gend_society_preview_noindex_active(),
			'header' => $h['X-Robots-Tag'] ?? '',
			'robots' => ! empty( $r['noindex'] ) && ! empty( $r['nofollow'] ),
		);
	};
	$cache_preview = function ( $live ) {
		$p = gs111pv_payload( 'preview', $live ? array( 'live' => true, 'preview' => false ) : array() );
		update_option( 'gend_society_remote_membership_cache', $p, false );
		update_option( 'gend_society_remote_membership_cache_expires', time() - 3600, false ); // expired: the front end still reads it, never fetches.
	};
	delete_option( 'gend_society_container_preview' );

	$cache_preview( false );
	$d = $decide( 'abc-gs111.gend.test' );
	gs111pv_assert( $d['active'] && 'noindex, nofollow' === $d['header'] && $d['robots'], 'ni-preview-host', 'preview host + live false (even with an expired cache): X-Robots-Tag and robots noindex, nofollow', wp_json_encode( $d ) );
	$d = $decide( 'ABC-gs111.gend.test:443' );
	gs111pv_assert( $d['active'], 'ni-host-case-port', 'host compare ignores case and port' );
	$d = $decide( 'www.example.test' );
	gs111pv_assert( ! $d['active'] && '' === $d['header'] && ! $d['robots'], 'ni-other-host', 'another host, no marker: indexable (no header, no robots change)', wp_json_encode( $d ) );
	$d = $decide( 'someone-else.gend.me' );
	gs111pv_assert( ! $d['active'], 'ni-no-pattern', 'a *.gend.me host that is not the preview host: indexable (no host-pattern fallback)' );
	gs111pv_assert( 0 === count( $GLOBALS['gs111pv']['http'] ), 'ni-no-network', 'deciding made zero outbound requests' );

	$cache_preview( true );
	$d = $decide( 'abc-gs111.gend.test' );
	gs111pv_assert( ! $d['active'] && '' === $d['header'] && ! $d['robots'], 'ni-live', 'live true: no header, no robots change', wp_json_encode( $d ) );

	// Empty cache.
	delete_option( 'gend_society_remote_membership_cache' );
	delete_option( 'gend_society_remote_membership_cache_expires' );
	$d = $decide( 'abc-gs111.gend.test' );
	gs111pv_assert( ! $d['active'], 'ni-empty-no-marker', 'empty cache, no marker (a non-migrated container): indexable' );
	update_option( 'gend_society_container_preview', time(), true );
	$d = $decide( 'abc-gs111.gend.test' );
	gs111pv_assert( $d['active'] && 'noindex, nofollow' === $d['header'] && $d['robots'], 'ni-empty-marker', 'empty cache + the container marker: noindex', wp_json_encode( $d ) );

	// The marker is cleared when Live is learned from the verified route (cron sync), then an empty cache is indexable.
	$vr = function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, '/domains/verified' ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'domains' => array( 'shop-gs111.example.test' ), 'live' => true, 'canonical_host' => 'abc-gs111.gend.test' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $vr, 2, 3 );
	gs111pv_on_cleanup(
		function () use ( $vr ) {
			remove_filter( 'pre_http_request', $vr, 2 );
		}
	);
	update_option( 'gend_society_install_id', GS111PV_INSTALL_ID, false );
	update_option( 'gend_society_install_token', GS111PV_TOKEN, false );
	update_option( 'gend_society_gend_base_url', GS111PV_BASE, false );
	update_option( 'gend_society_consent', array( 'at' => time(), 'user_id' => 1, 'source' => 'gs111pv' ), false );
	gs111pv_assert( has_action( GEND_SOCIETY_VERIFIED_DOMAINS_HOOK, 'gend_society_preview_clear_from_sync' ), 'ni-cron-hook', 'the 5-minute sync hook also runs the marker check' );
	do_action( GEND_SOCIETY_VERIFIED_DOMAINS_HOOK );
	gs111pv_assert( ! get_option( 'gend_society_container_preview', false ), 'ni-cleared-by-route', 'verified route live:true cleared the marker' );
	$d = $decide( 'abc-gs111.gend.test' );
	gs111pv_assert( ! $d['active'] && '' === $d['header'], 'ni-live-container', 'Live container with an empty cache: indexable' );

	// Verified route says live:false -> the marker stays.
	update_option( 'gend_society_container_preview', time(), true );
	remove_filter( 'pre_http_request', $vr, 2 );
	$vf = function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, '/domains/verified' ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'domains' => array(), 'live' => false, 'canonical_host' => 'abc-gs111.gend.test' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $vf, 2, 3 );
	gs111pv_on_cleanup(
		function () use ( $vf ) {
			remove_filter( 'pre_http_request', $vf, 2 );
		}
	);
	do_action( GEND_SOCIETY_VERIFIED_DOMAINS_HOOK );
	gs111pv_assert( (bool) get_option( 'gend_society_container_preview', false ), 'ni-stays-while-preview', 'live:false from the route keeps the marker' );
	remove_filter( 'pre_http_request', $vf, 2 );

	// The first admin membership fetch sets / clears the marker (the importer image has no hook into the plugin).
	delete_option( 'gend_society_container_preview' );
	delete_option( 'gend_society_container_imported' );
	set_current_screen( 'dashboard' );
	wp_set_current_user( gs111pv_subscriber() );
	do_action( 'gend_society_remote_membership_fetched', gs111pv_payload( 'preview' ) );
	gs111pv_assert( ! get_option( 'gend_society_container_preview', false ), 'ni-learn-non-admin', 'a non-admin request never sets the marker' );
	wp_set_current_user( 1 );
	do_action( 'gend_society_remote_membership_fetched', gs111pv_payload( 'preview' ) );
	gs111pv_assert( (bool) get_option( 'gend_society_container_preview', false ) && (bool) get_option( 'gend_society_container_imported', false ), 'ni-learn-preview', 'first admin fetch showing preview sets the marker and the imported flag' );
	do_action( 'gend_society_remote_membership_fetched', gs111pv_payload( 'live' ) );
	gs111pv_assert( ! get_option( 'gend_society_container_preview', false ) && (bool) get_option( 'gend_society_container_imported', false ), 'ni-learn-live', 'first admin fetch showing live clears the marker (imported flag stays)' );
	do_action( 'gend_society_remote_membership_fetched', gs111pv_payload( 'hub-site' ) );
	gs111pv_assert( ! get_option( 'gend_society_container_preview', false ), 'ni-never-migrated', 'a payload with neither preview nor live-after-preview leaves a non-migrated container without a marker' );
	set_current_screen( 'front' );

	// Disabled outside the container runtime.
	remove_filter( 'gend_society_preview_enabled', $on );
	update_option( 'gend_society_container_preview', time(), true );
	$off = function () {
		return false;
	};
	add_filter( 'gend_society_preview_enabled', $off );
	$d = $decide( 'abc-gs111.gend.test' );
	remove_filter( 'gend_society_preview_enabled', $off );
	gs111pv_assert( ! $d['active'] && '' === $d['header'], 'ni-mode-off', 'outside the container runtime nothing is changed' );
};

$S['no-new-surfaces'] = function () {
	gs111pv_pair();
	$own  = wp_parse_url( home_url(), PHP_URL_HOST );
	$doms = array( gs111pv_domain( 'shop-gs111.example.test', 'done-without-ssl' ) );
	$html = gs111pv_panel( gs111pv_payload( 'preview', array(), $doms ) ) . gs111pv_domains( gs111pv_payload( 'preview', array(), $doms ) );
	$bad  = array();
	if ( preg_match_all( '#<(script|link|img|iframe)\b[^>]*\b(?:src|href)=["\']([^"\']+)["\']#i', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$h = wp_parse_url( html_entity_decode( $x[2] ), PHP_URL_HOST );
			if ( in_array( strtolower( $x[1] ), array( 'script', 'link' ), true ) && $h && $h !== $own ) {
				$bad[] = $x[1] . ' ' . $x[2];
			}
		}
	}
	gs111pv_assert( ! $bad, 'ns-origin', 'no script / stylesheet from another origin in the Preview panel or the Domains panel', wp_json_encode( $bad ) );
	gs111pv_assert( false === stripos( $html, '<iframe' ), 'ns-iframe', 'no iframe' );
	$note = gs111pv_header( gs111pv_panel( gs111pv_payload( 'preview' ) ) );
	gs111pv_assert( false === stripos( $note, 'role="dialog"' ) && false === stripos( $note, 'modal' ), 'ns-popup', 'no popup / dialog markup in the Preview note' );
	$dom = (string) file_get_contents( GEND_SOCIETY_DIR . 'inc/dashboard-hosting-domains.php' );
	$cut = strpos( $dom, 'function gend_society_migration_go_live_ajax' );
	$src = (string) file_get_contents( GEND_SOCIETY_DIR . 'inc/migration-preview.php' ) . ( false === $cut ? '' : substr( $dom, $cut ) );
	gs111pv_assert( ! preg_match( '/add_menu_page|add_submenu_page|add_options_page|add_management_page|add_dashboard_page|register_post_type|wp_enqueue_(script|style)\s*\(/', $src ), 'ns-source', 'the new code adds no menu page, post type or enqueued asset' );
	gs111pv_assert( 1 === substr_count( $html, 'data-gs-preview-note="1"' ), 'ns-one-note', 'one note, inside the existing membership card header' );
	gs111pv_assert( 1 === substr_count( gs111pv_domains( gs111pv_payload( 'preview', array(), $doms ) ), '<button type="button" class="gs-hosting__btn" data-gs-make-live="1"' ), 'ns-one-button', 'one Make live now button, inside the existing Domains panel' );
};

// ---- dispatch ----------------------------------------------------------------------------------------------------
$gs111pv_args = isset( $args ) ? (array) $args : array();
$gs111pv_want = array();
foreach ( $gs111pv_args as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs111pv_want[] = trim( $s );
		}
	}
}
if ( ! $gs111pv_want ) {
	$gs111pv_want = array_keys( $S );
}
echo 'INFO: preview-ui on ' . home_url() . ' runtime=' . ( function_exists( 'gend_society_runtime_mode' ) ? gend_society_runtime_mode() : '?' ) . ' sections=' . implode( ',', $gs111pv_want ) . "\n";
foreach ( $gs111pv_want as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs111pv_assert( false, $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs111pv_assert( false, $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs111pv_cleanup();
	}
}
$gs111pv_f = (int) $GLOBALS['gs111pv']['fail'];
$gs111pv_p = (int) $GLOBALS['gs111pv']['pass'];
echo ( 0 === $gs111pv_f && $gs111pv_p > 0 ) ? "ALL PASS ({$gs111pv_p})\n" : "FAILURES: {$gs111pv_f}\n";
