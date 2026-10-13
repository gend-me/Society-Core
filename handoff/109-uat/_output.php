<?php
/**
 * Phase 109 plugin battery: helpers for the plan 109-08 sections (embed, upgrade-page, output).
 *
 * 1.4.0 side by side: gs109o_load_old( '<file>-1.4.0.php.txt' ) loads a baseline copy (handoff/109-uat/baseline/,
 * md5 == Society-Core 96d2adf == live 1.4.0) with every function it defines renamed gs109o_<name> and every
 * constant it defines renamed GS109O_<NAME>; top-level add_action/add_filter calls become no-ops, so no hook of
 * the old copy is registered. Calls into other files keep their real names.
 *
 * Declared EXPECTED classes (the only differences allowed against 1.4.0 output in hub and container modes):
 *   D12       feature-upgrade.php: the learn-more <iframe src="<url>?gdc_upgrade_embed=1" ...> becomes
 *             <a href="<url>" target="_blank" rel="noopener">More about this upgrade</a> (same label)
 *   LISTENER  feature-upgrade.php and media-storage-panel.php: the unchecked window message listener for
 *             gs_membership_changed is removed (popup-close watchdog kept)
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/_plan.php';

if ( ! function_exists( 'gs109o_noop' ) ) {
	function gs109o_noop() {
	}
}

/** Load a 1.4.0 baseline side by side (see file header). True when its functions exist. */
function gs109o_load_old( $baseline ) {
	static $loaded = array();
	if ( isset( $loaded[ $baseline ] ) ) {
		return $loaded[ $baseline ];
	}
	$src = (string) file_get_contents( __DIR__ . '/baseline/' . $baseline );
	if ( '' === $src ) {
		return $loaded[ $baseline ] = false;
	}
	// PHP function declarations only (token based: JavaScript "function x()" inside heredocs is not PHP).
	$fm   = array( 1 => array() );
	$toks = token_get_all( $src );
	$want = false;
	foreach ( $toks as $t ) {
		if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
			$want = true;
		} elseif ( $want && is_array( $t ) && T_WHITESPACE === $t[0] ) {
			continue;
		} elseif ( $want ) {
			if ( is_array( $t ) && T_STRING === $t[0] ) {
				$fm[1][] = $t[1];
			}
			$want = false;
		}
	}
	preg_match_all( '/^const\s+(\w+)\s*=/m', $src, $cm );
	foreach ( array_unique( $fm[1] ) as $fn ) {
		$src = preg_replace( '/\b' . preg_quote( $fn, '/' ) . '\b(?=\s*\()/', 'gs109o_' . $fn, $src );
	}
	foreach ( array_unique( $cm[1] ) as $c ) {
		$src = preg_replace( '/\b' . preg_quote( $c, '/' ) . '\b/', 'GS109O_' . $c, $src );
	}
	$src = preg_replace( '/^(add_action|add_filter)\s*\(/m', 'gs109o_noop(', $src );
	$tmp = get_temp_dir() . 'gs109o-' . md5( $baseline ) . '-' . getmypid() . '.php';
	file_put_contents( $tmp, $src );
	include $tmp;
	@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$first = isset( $fm[1][0] ) ? 'gs109o_' . $fm[1][0] : '';
	return $loaded[ $baseline ] = ( '' !== $first && function_exists( $first ) );
}

/** Output of a callable (echo + return value). */
function gs109o_capture( callable $fn, array $args = array() ) {
	ob_start();
	try {
		$ret = call_user_func_array( $fn, $args );
	} catch ( \Throwable $e ) {
		ob_end_clean();
		return 'THREW ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	$out = (string) ob_get_clean();
	return $out . ( is_string( $ret ) ? $ret : '' );
}

/**
 * Apply the declared EXPECTED classes to a 1.4.0 upgrade-page render. Returns [normalised html, counts].
 *
 * @param string $html  1.4.0 render.
 * @param string $learn Learn-more URL the render used ('' when none).
 */
function gs109o_expect_upgrade_page( $html, $learn ) {
	$counts = array(
		'D12'      => 0,
		'LISTENER' => 0,
	);
	$html = preg_replace_callback(
		'#<iframe src="[^"]*" loading="lazy" title="([^"]*)"></iframe>#',
		function ( $m ) use ( $learn, &$counts ) {
			++$counts['D12'];
			return '<a href="' . esc_url( $learn ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
		},
		$html
	);
	$html = preg_replace_callback(
		"#\n\n {16}window\.addEventListener\('message', function onMsg\(ev\) \{\n(?: {20}[^\n]*\n)+ {16}\}\);#",
		function ( $m ) use ( &$counts ) {
			if ( false === strpos( $m[0], "'gs_membership_changed'" ) ) {
				return $m[0];
			}
			++$counts['LISTENER'];
			return '';
		},
		$html
	);
	return array( $html, $counts );
}

/** The media upgrade popup script of 1.4.0 with the LISTENER class applied. Returns [js, removed count]. */
function gs109o_expect_media_js( $js ) {
	$n  = 0;
	$js = preg_replace_callback(
		"#\n\t{3}window\.addEventListener\('message', function onMsg\(ev\) \{\n(?:\t{4}[^\n]*\n)+\t{3}\}\);#",
		function ( $m ) use ( &$n ) {
			if ( false === strpos( $m[0], "'gs_membership_changed'" ) ) {
				return $m[0];
			}
			++$n;
			return '';
		},
		$js
	);
	return array( $js, $n );
}

/** PHP tokens of a source without comments and whitespace (for "equal apart from comments"). */
function gs109o_tokens( $src ) {
	$out = array();
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				continue;
			}
			$out[] = token_name( $t[0] ) . ':' . $t[1];
		} else {
			$out[] = $t;
		}
	}
	return $out;
}

/** Inputs for the upgrade page parity check: [label, $_GET, features payload]. */
function gs109o_upgrade_inputs() {
	$learn = array(
		'store.sales_team'         => 'http://localhost:8919/learn/sales-team/',
		'store.sales_team.payouts' => 'http://localhost:8919/learn/sales-team/payouts/',
		'social.membership'        => 'http://localhost:8919/learn/membership/?ref=gs',
	);
	$feats = array(
		'none'   => array(),
		'write'  => array( 'app', 'write' ),
		'store'  => array( 'app', 'write', 'store' ),
		'social' => array( 'app', 'write', 'store', 'social' ),
	);
	$gets = array(
		'plain'            => array(),
		'required-store'   => array( 'required' => 'store', 'from' => 'gs-store' ),
		'required-social'  => array( 'required' => 'social' ),
		'learn-child'      => array( 'required' => 'store', 'required_child' => 'store.sales_team', 'from' => 'gs-sales-team' ),
		'learn-child-tab'  => array( 'required' => 'store', 'required_child' => 'store.sales_team', 'tab' => 'payouts' ),
		'learn-query'      => array( 'required' => 'social', 'required_child' => 'social.membership' ),
		'learn-missing'    => array( 'required' => 'reward', 'required_child' => 'reward.points' ),
	);
	$out = array();
	foreach ( $feats as $fl => $areas ) {
		foreach ( $gets as $gl => $get ) {
			$out[] = array(
				"{$fl}/{$gl}",
				$get,
				array(
					'plan_slug'        => 'gs109-' . $fl,
					'plan_name'        => 'GS109 ' . ucfirst( $fl ),
					'allowed_areas'    => $areas,
					'learn_more_pages' => $learn,
				),
			);
		}
	}
	return $out;
}

/**
 * Hub / container: render the upgrade page (new vs 1.4.0) for every input; returns
 * ['cases', 'diff' => [...], 'D12' => n, 'LISTENER' => n, 'iframes_new' => n, 'listener_new' => n].
 */
function gs109o_upgrade_parity() {
	$res = array(
		'cases'        => 0,
		'diff'         => array(),
		'D12'          => 0,
		'LISTENER'     => 0,
		'iframes_new'  => 0,
		'listener_new' => 0,
		'sample'       => '',
	);
	if ( ! gs109o_load_old( 'feature-upgrade-1.4.0.php.txt' ) || ! function_exists( 'gend_society_render_feature_upgrade_page' ) ) {
		$res['diff'][] = 'could not load both versions';
		return $res;
	}
	gs109_snapshot_options();
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			return false !== strpos( (string) $url, '/install/' ) || false !== strpos( (string) $url, 'gend.me' ) ? new WP_Error( 'gs109_offline', 'output offline' ) : $pre;
		},
		1,
		3
	);
	wp_set_current_user( gs109p_admin_id() );
	foreach ( gs109o_upgrade_inputs() as $in ) {
		list( $label, $get, $payload ) = $in;
		update_option( 'gend_society_features_cache', $payload, false );
		update_option( 'gend_society_features_cache_expires', time() + 600, false );
		$learn = function_exists( 'gend_society_features_resolve_learn_more_url' ) ? gend_society_features_resolve_learn_more_url( (string) ( $get['required_child'] ?? '' ), (string) ( $get['tab'] ?? '' ), $payload['learn_more_pages'] ) : '';
		$_GET  = $get;
		$new   = gs109o_capture( 'gend_society_render_feature_upgrade_page' );
		$_GET  = $get;
		$old   = gs109o_capture( 'gs109o_gend_society_render_feature_upgrade_page' );
		$_GET  = array();
		list( $exp, $counts ) = gs109o_expect_upgrade_page( $old, $learn );
		++$res['cases'];
		$res['D12']      += $counts['D12'];
		$res['LISTENER'] += $counts['LISTENER'];
		if ( false !== stripos( $new, '<iframe' ) ) {
			++$res['iframes_new'];
		}
		if ( false !== strpos( $new, 'gs_membership_changed' ) ) {
			++$res['listener_new'];
		}
		if ( 1 !== $counts['LISTENER'] || ( '' !== $learn ) !== ( 1 === $counts['D12'] ) ) {
			$res['diff'][] = "{$label}: expected-class counts " . wp_json_encode( $counts ) . ' learn=' . $learn;
		}
		if ( $exp !== $new ) {
			$at            = strspn( $exp ^ $new, "\0" );
			$res['diff'][] = "{$label}: differs at byte {$at}: old=" . wp_json_encode( substr( $exp, max( 0, $at - 40 ), 120 ) ) . ' new=' . wp_json_encode( substr( $new, max( 0, $at - 40 ), 120 ) );
		}
		if ( '' === $res['sample'] && '' !== $learn ) {
			$res['sample'] = $new;
		}
	}
	return $res;
}

/** Hub / container: media popup script + file parity. Returns ['js_equal', 'removed', 'tokens_equal', 'new_has_listener']. */
function gs109o_media_parity() {
	$res = array(
		'js_equal'         => false,
		'removed'          => 0,
		'tokens_equal'     => false,
		'new_has_listener' => true,
	);
	if ( ! gs109o_load_old( 'media-storage-panel-1.4.0.php.txt' ) || ! function_exists( 'gend_society_media_storage_panel_inline_js' ) ) {
		return $res;
	}
	$new                     = (string) gend_society_media_storage_panel_inline_js();
	list( $exp, $n )         = gs109o_expect_media_js( (string) gs109o_gend_society_media_storage_panel_inline_js() );
	$res['js_equal']         = ( $exp === $new );
	$res['removed']          = $n;
	$res['new_has_listener'] = false !== strpos( $new, 'gs_membership_changed' ) || false !== strpos( $new, "addEventListener('message'" );
	// Whole file: equal to 1.4.0 apart from comments and the removed listener lines inside the heredoc.
	$old_src = (string) file_get_contents( __DIR__ . '/baseline/media-storage-panel-1.4.0.php.txt' );
	list( $old_src ) = gs109o_expect_media_js( $old_src );
	$new_src         = (string) file_get_contents( GEND_SOCIETY_DIR . 'inc/media-storage-panel.php' );
	$res['tokens_equal'] = gs109o_tokens( $old_src ) === gs109o_tokens( $new_src );
	return $res;
}

/** A stub /membership payload for the panel parity check. */
function gs109o_panel_payload( $variant ) {
	$p = array(
		'membership_id'  => 4242,
		'status'         => 'active',
		'status_label'   => 'Active',
		'hub_url'        => 'http://localhost:8919/',
		'membership_url' => 'http://localhost:8919/my-account/membership/4242/',
		'app_url'        => 'http://localhost:8909/',
		'app_title'      => 'GS109 Output',
		'billing'        => array( 'amount' => 29.99, 'label' => '$29.99' ),
		'dates'          => array( 'activated' => '2026-09-01', 'renews' => '2026-11-01', 'expires' => '' ),
		'dashboard_plan' => array( 'id' => 22, 'name' => 'Store Owner', 'amount_label' => '$29.99', 'duration_unit' => 'month', 'image' => '' ),
		'hosting_plan'   => null,
		'customer'       => array( 'name' => 'GS109 Owner', 'email' => '' ),
		'group'          => array( 'id' => 4101, 'name' => 'GS109 Business Group', 'slug' => 'gs109-business-group', 'url' => 'http://localhost:8919/groups/gs109-business-group/' ),
		'orders'         => array(),
		'backups'        => array(),
	);
	if ( 'connected' === $variant ) {
		$p['dashboard_plan'] = array( 'id' => 20, 'name' => 'Connected', 'amount_label' => '', 'image' => '' );
		$p['billing']        = array();
		$p['dates']          = array();
	} elseif ( 'no-plan' === $variant ) {
		$p['dashboard_plan'] = null;
	}
	return $p;
}

/** Hub / container: membership panel new vs 1.4.0. Returns ['cases', 'diff' => [...]]. */
function gs109o_panel_parity() {
	$res = array(
		'cases' => 0,
		'diff'  => array(),
		'bytes' => 0,
	);
	if ( ! gs109o_load_old( 'dashboard-remote-membership-1.4.0.php.txt' ) || ! function_exists( 'gend_society_render_membership_panel' ) ) {
		$res['diff'][] = 'could not load both versions';
		return $res;
	}
	// The hub panel embeds live sections (hosting, media scans, projects, profile invite): relative times, the
	// projects plugin's random psoo-ppb-<n> element id and per-render UUIDs (profile-invite wrapper / editor ids)
	// are normalised, and each copy is rendered once first so styles printed only on a page's first render do
	// not count.
	$norm = function ( $html ) {
		return preg_replace(
			array( '/\b\d+ (second|minute|hour|day)s? ago\b/', '/\bpsoo-ppb-\d+\b/', '/[0-9a-f]{8}([-_])[0-9a-f]{4}[-_][0-9a-f]{4}[-_][0-9a-f]{4}[-_][0-9a-f]{12}/i' ),
			array( 'N $1 ago', 'psoo-ppb-N', 'UUID$1' ),
			$html
		);
	};
	$res['iframes_new'] = 0;
	$res['iframes_old'] = 0;
	foreach ( array( 'paid', 'connected', 'no-plan' ) as $v ) {
		$payload = gs109o_panel_payload( $v );
		gs109o_capture( 'gend_society_render_membership_panel', array( $payload ) );
		gs109o_capture( 'gs109o_gend_society_render_membership_panel', array( $payload ) );
		$new = $norm( gs109o_capture( 'gend_society_render_membership_panel', array( $payload ) ) );
		$old = $norm( gs109o_capture( 'gs109o_gend_society_render_membership_panel', array( $payload ) ) );
		++$res['cases'];
		$res['bytes']       += strlen( $new );
		$res['iframes_new'] += substr_count( strtolower( $new ), '<iframe' );
		$res['iframes_old'] += substr_count( strtolower( $old ), '<iframe' );
		if ( $new !== $old ) {
			$at            = strspn( $old ^ $new, "\0" );
			$res['diff'][] = "{$v}: differs at byte {$at}: old=" . wp_json_encode( substr( $old, max( 0, $at - 40 ), 120 ) ) . ' new=' . wp_json_encode( substr( $new, max( 0, $at - 40 ), 120 ) );
		}
	}
	return $res;
}
