<?php
/**
 * Section glue (plan 108-10; hub glue in inc/group-app-tabs.php). gs108-readonly: yes
 *
 * The 1.3.1 file (origin/main inc/group-app-tabs.php, md5 pinned below) is the baseline. Its
 * gend_society_group_get_linked_install_id() and gend_society_group_tab_open() are extracted and loaded
 * into this process as gs108old_group_*; the helpers the Hosting tab calls must be source-identical.
 *
 * READ-ONLY (GS108_READONLY=1, used on live in 108-15): has_action('gend_society_group_hosting_connect_panel'),
 * the Hosting tab body fires it (source), and for every group linked to a membership that is NOT a
 * Connected (site-less self-hosted) record — groups with site rows included — the linked install id and
 * the tab chrome are identical to 1.3.1. No provisioning, no writes.
 *
 * Normal (hub clone): additionally
 *   - GS108 Beta is linked for the run to the seeded legacy membership that HAS a site row: linked id,
 *     chrome and the whole Hosting tab output equal 1.3.1 (plus whatever the panel action prints, which
 *     must be nothing for a group with a site);
 *   - GS108 Paid: Hosting tab output byte-identical to 1.3.1;
 *   - GS108 Alpha is linked for the run to a Connected record (plan 45 membership, meta only): linked id
 *     = its gdc_install_id, chrome link = gdc_install_admin_url (fallback gdc_external_url + wp-admin/),
 *     nothing for a disconnected or non-self-hosted record; the Hosting tab fires the action once with
 *     the group id and shows VAM's "Connected: <url>" text (and the pairing button once disconnected).
 * Compute Gas tab (108-11): the panel also fires there (inside the tab suite, before the top tabs, not in
 * the embedded single-panel view), through gend_society_group_hosting_connect_panel(), which fires the action
 * at most once per page. Source: the Compute Gas class equals 1.3.1 apart from that one block (read-only too).
 * Normal mode renders the 1.3.1 Compute Gas class (loaded as gs108old_Group_Tab_Compute_Gas) next to the
 * current one: byte-identical for Paid and the site-row group, panel + 1.3.1 output for a Connected record.
 * 108-12b: when a panel prints, the helper puts the anchor <span id="gend-society-connect-panel"> right before it
 * (nothing for paid / site-row groups). The group page shows a "Connect your WordPress site" link (youzify_group_main_content,
 * priority 9) to group admins and super admins of groups whose VAM state is 'ok', without a gdc_site_id or a linked install,
 * while gdc_connect_enabled() is on (function_exists-guarded); not on the Compute Gas / Hosting tabs. Read-only: wiring, the
 * guards in the source, and no link for any linked / site-id group (and none at all while the switch is off). Normal mode: shown
 * for the unpaid-group admin and a super admin; hidden for a moderator, a member, an admin of another group, logged out, the
 * paid group, the site-row group, a Connected group and with the switch off; shown again once the record is disconnected.
 * Baseline file: env GS108_GLUE_BASELINE_FILE or /tmp/gs108-baseline/group-app-tabs-1.3.1.php.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

$mode = gs108_mode();
if ( 'hub' !== $mode ) {
	gs108_assert( 'standalone' !== $mode || ! function_exists( 'gend_society_group_get_linked_install_id' ), 'glue-not-hub', 'group-app-tabs glue is hub-only (mode ' . $mode . ')' );
	return;
}

// --- 1.3.1 baseline.
$bfile = (string) getenv( 'GS108_GLUE_BASELINE_FILE' );
if ( '' === $bfile ) {
	$bfile = '/tmp/gs108-baseline/group-app-tabs-1.3.1.php';
}
$bsrc = is_readable( $bfile ) ? (string) file_get_contents( $bfile ) : '';
if ( ! gs108_assert( '78711b319336ad01a79d84628411f15e' === md5( $bsrc ), 'glue-baseline-file', '1.3.1 group-app-tabs.php baseline present (md5 78711b31... = origin/main cdc97b9 = live)', $bfile . ' md5 ' . md5( $bsrc ) ) ) {
	return;
}
$csrc = (string) file_get_contents( GEND_SOCIETY_DIR . 'inc/group-app-tabs.php' );
$fn_src = function ( $src, $name ) {
	return preg_match( '/^function ' . preg_quote( $name, '/' ) . '\(.*?^}\n/ms', $src, $m ) ? $m[0] : '';
};
foreach ( array( 'gend_society_group_tabs_user_has_access', 'gend_society_group_render_hosting_suite', 'gend_society_group_tab_close' ) as $f ) {
	$a = $fn_src( $bsrc, $f );
	gs108_assert( '' !== $a && $a === $fn_src( $csrc, $f ), 'glue-same-' . $f, $f . '() source unchanged from 1.3.1' );
}
// The 1.3.1 Hosting tab body was: access gate, then the hosting suite.
$old_body = preg_match( '/class Gend_Society_Group_Tab_Hosting .*?public static function render_legacy_content\( \$group_id \) \{(.*?)\n        \}/s', $bsrc, $m ) ? preg_replace( '#//[^\n]*#', '', $m[1] ) : '';
gs108_assert( 'if(!gend_society_group_tabs_user_has_access())return;gend_society_group_render_hosting_suite($group_id);' === preg_replace( '/\s+/', '', $old_body ), 'glue-old-hosting-body', '1.3.1 Hosting tab body = access gate + hosting suite' );
$new_body = preg_match( '/class Gend_Society_Group_Tab_Hosting .*?public static function render_legacy_content\( \$group_id \) \{(.*?)\n        \}/s', $csrc, $m ) ? preg_replace( '#//[^\n]*#', '', $m[1] ) : '';
gs108_assert( "if(!gend_society_group_tabs_user_has_access())return;gend_society_group_hosting_connect_panel((int)\$group_id);gend_society_group_render_hosting_suite(\$group_id);" === preg_replace( '/\s+/', '', $new_body ), 'glue-new-hosting-body', 'Hosting tab body = access gate + gend_society_group_hosting_connect_panel((int) $group_id) + hosting suite' );
// 108-11: the once-per-page helper, and the Compute Gas tab (where group admins land) fires it too.
$helper = preg_replace( '#//[^\n]*#', '', $fn_src( $csrc, 'gend_society_group_hosting_connect_panel' ) );
gs108_assert( "functiongend_society_group_hosting_connect_panel(\$group_id){if(did_action('gend_society_group_hosting_connect_panel'))return;ob_start();do_action('gend_society_group_hosting_connect_panel',(int)\$group_id);\$panel=(string)ob_get_clean();if(''!==\$panel)echo'<spanid=\"gend-society-connect-panel\"class=\"gs-connect-panel-anchor\"></span>';echo\$panel;}" === preg_replace( '/\s+/', '', $helper ), 'glue-once-helper', 'gend_society_group_hosting_connect_panel() fires the action at most once per page (did_action guard); anchor span only when a panel printed', $helper );
$cg_re    = '/^    class Gend_Society_Group_Tab_Compute_Gas extends BP_Group_Extension \{\n.*?^    \}\n/ms';
$cg_old   = preg_match( $cg_re, $bsrc, $m ) ? $m[0] : '';
$cg_new   = preg_match( $cg_re, $csrc, $m ) ? $m[0] : '';
$cg_block = "                <?php\n                if ( ! \$gs_cg_hide_nav ) :\n                    // Phase 108: the Hosting nav item is hidden, so the pairing panel also\n                    // shows here, where group admins land (once per page; it prints nothing\n                    // for groups with a paid site, so their output is unchanged).\n                    gend_society_group_hosting_connect_panel( (int) \$group_id );\n                    ?>\n";
$cg_orig  = "                <?php if ( ! \$gs_cg_hide_nav ) : ?>\n";
gs108_assert( '' !== $cg_old && 1 === substr_count( $cg_new, $cg_block ) && $cg_old === str_replace( $cg_block, $cg_orig, $cg_new ), 'glue-compute-gas-source', 'Compute Gas class = 1.3.1 apart from the one panel call before the top tabs (' . strlen( $cg_old ) . ' bytes in 1.3.1)' );
gs108_assert( 1 === substr_count( $cg_new, "<div class=\"gs-cg-tabsuite\" data-gs-cg-tabsuite>\n" . $cg_block ), 'glue-compute-gas-place', 'Compute Gas fires the panel inside the tab suite, before the top tabs, only when the tab nav is shown' );
$cbs0 = apply_filters( 'gdc_gs_endpoint_content_callbacks', array() );
gs108_assert( isset( $cbs0['compute-gas'] ) && array( 'Gend_Society_Group_Tab_Compute_Gas', 'render_legacy_content' ) === $cbs0['compute-gas'], 'glue-compute-gas-callback', 'Group Page endpoint "compute-gas" renders Gend_Society_Group_Tab_Compute_Gas::render_legacy_content' );
$cbs = apply_filters( 'gdc_gs_endpoint_content_callbacks', array() );
gs108_assert( isset( $cbs['hosting'] ) && array( 'Gend_Society_Group_Tab_Hosting', 'render_legacy_content' ) === $cbs['hosting'], 'glue-endpoint-callback', 'Group Page endpoint "hosting" renders Gend_Society_Group_Tab_Hosting::render_legacy_content' );
gs108_assert( false !== has_action( 'gend_society_group_hosting_connect_panel' ), 'glue-has-action', 'gend_society_group_hosting_connect_panel has a callback (vendor-app-manager pairing panel)' );
// 108-12b: the group page link.
$gs12b_anchor = '<span id="gend-society-connect-panel" class="gs-connect-panel-anchor"></span>';
gs108_assert( 9 === has_action( 'youzify_group_main_content', 'gend_society_group_connect_link_render' ) && 10 === has_action( 'wp_enqueue_scripts', 'gend_society_group_connect_link_enqueue' ), 'glue-link-wiring', 'link renders on youzify_group_main_content (9, under the group nav); stylesheet on wp_enqueue_scripts (10)' );
$gs12b_url_src  = $fn_src( $csrc, 'gend_society_group_connect_link_url' );
$gs12b_html_src = $fn_src( $csrc, 'gend_society_group_connect_link_html' );
gs108_assert(
	false !== strpos( $gs12b_url_src, "if ( ! function_exists( 'gdc_connect_enabled' ) || ! gdc_connect_enabled() ) return '';" )
	&& false !== strpos( $gs12b_url_src, "if ( ! function_exists( 'gdc_connect_group_state' ) || ! function_exists( 'gdc_connect_user_can_use_group' ) ) return '';" )
	&& false !== strpos( $gs12b_url_src, "'ok' !== " ) && false !== strpos( $gs12b_url_src, "'gdc_site_id'" ) && false !== strpos( $gs12b_url_src, 'gend_society_group_get_linked_install_id( $group_id )' ),
	'glue-link-guards',
	'link URL: function_exists-guarded kill switch + VAM helpers, state ok only, no gdc_site_id, no linked install'
);
gs108_assert( is_readable( GEND_SOCIETY_DIR . 'assets/gs-group-connect-link.css' ) && '' !== $gs12b_html_src && false === strpos( $gs12b_html_src, '<style' ) && false === strpos( $gs12b_html_src, '<script' ) && false === strpos( $gs12b_html_src, 'style=' ), 'glue-link-static-css', 'static stylesheet assets/gs-group-connect-link.css; no inline <style>/<script>/style= in the link markup' );
$gs12b_super = 0;
foreach ( get_super_admins() as $gs12b_login ) {
	$gs12b_u = get_user_by( 'login', $gs12b_login );
	if ( $gs12b_u ) {
		$gs12b_super = (int) $gs12b_u->ID;
		break;
	}
}
global $wpdb;
$gs12b_bad = array();
$gs12b_n   = 0;
$gs12b_ids = $wpdb->get_col( "SELECT DISTINCT group_id FROM {$wpdb->base_prefix}bp_groups_groupmeta WHERE meta_key IN ('gdc_membership_id','gdc_site_id') AND meta_value <> '' AND meta_value <> '0' ORDER BY group_id LIMIT 200" );
foreach ( (array) $gs12b_ids as $gs12b_gid ) {
	$gs12b_gid = (int) $gs12b_gid;
	$gs12b_st  = function_exists( 'gdc_connect_group_state' ) ? gdc_connect_group_state( $gs12b_gid ) : array( 'state' => '' );
	$gs12b_sid = (int) groups_get_groupmeta( $gs12b_gid, 'gdc_site_id', true );
	if ( 'ok' === $gs12b_st['state'] && 0 === $gs12b_sid && '' === gend_society_group_get_linked_install_id( $gs12b_gid ) ) {
		continue; // A disconnected Connected record: the link is meant to show there.
	}
	$gs12b_n++;
	if ( '' !== gend_society_group_connect_link_url( $gs12b_gid, $gs12b_super ) ) {
		$gs12b_bad[] = $gs12b_gid . ':' . $gs12b_st['state'];
	}
}
gs108_assert( $gs12b_super > 0 && array() === $gs12b_bad, 'glue-link-linked-groups', "no link for the super admin on any of {$gs12b_n} paid / site-row / connected groups", implode( ',', $gs12b_bad ) );
if ( ! function_exists( 'gdc_connect_enabled' ) || ! gdc_connect_enabled() ) {
	$gs12b_any = array();
	foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->base_prefix}bp_groups ORDER BY id LIMIT 200" ) as $gs12b_gid ) {
		if ( '' !== gend_society_group_connect_link_url( (int) $gs12b_gid, $gs12b_super ) ) {
			$gs12b_any[] = (int) $gs12b_gid;
		}
	}
	gs108_assert( array() === $gs12b_any, 'glue-link-switch-off-here', 'Connect switch is off here: no link on any group', implode( ',', $gs12b_any ) );
} else {
	gs108_info( 'Connect switch is on here: per-group link visibility is covered by the normal-mode asserts' );
}

if ( ! function_exists( 'gs108old_group_tab_open' ) ) {
	$code = $fn_src( $bsrc, 'gend_society_group_get_linked_install_id' ) . $fn_src( $bsrc, 'gend_society_group_tab_open' );
	$code = str_replace( array( 'gend_society_group_get_linked_install_id(', 'function gend_society_group_tab_open(' ), array( 'gs108old_group_get_linked_install_id(', 'function gs108old_group_tab_open(' ), $code );
	eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test harness: the pinned 1.3.1 functions under renamed names.
}
gs108_assert( function_exists( 'gs108old_group_get_linked_install_id' ) && function_exists( 'gs108old_group_tab_open' ), 'glue-baseline-loaded', '1.3.1 linked-install-id + tab chrome loaded as gs108old_*' );

$chrome = function ( $fn, $gid ) {
	ob_start();
	call_user_func( $fn, 'Hosting', 'gs108 glue intro', $gid );
	return (string) ob_get_clean();
};
$is_connected_record = function ( $gid ) {
	$mid = (int) groups_get_groupmeta( $gid, 'gdc_membership_id' );
	$m   = $mid && function_exists( 'wu_get_membership' ) ? wu_get_membership( $mid ) : null;
	if ( ! $m ) {
		return false;
	}
	$sites = $m->get_sites();
	return empty( $sites ) && (bool) $m->get_meta( 'gdc_self_hosted', false );
};
$compare = function ( $gid, $label ) use ( $chrome ) {
	$a = gend_society_group_get_linked_install_id( $gid );
	$b = gs108old_group_get_linked_install_id( $gid );
	$x = $chrome( 'gend_society_group_tab_open', $gid );
	$y = $chrome( 'gs108old_group_tab_open', $gid );
	return array( $a === $b && $x === $y, wp_json_encode( array( $label, $a, $b, strlen( $x ), strlen( $y ) ) ), $a, $x );
};

// --- every linked group that is not a Connected record: identical to 1.3.1.
global $wpdb;
$linked = $wpdb->get_results( "SELECT group_id, meta_value FROM {$wpdb->base_prefix}bp_groups_groupmeta WHERE meta_key = 'gdc_membership_id' AND meta_value <> '' ORDER BY group_id" );
$n_same = 0;
$n_site = 0;
$bad    = array();
foreach ( (array) $linked as $row ) {
	$gid = (int) $row->group_id;
	if ( $is_connected_record( $gid ) ) {
		continue;
	}
	$mid = (int) $row->meta_value;
	$m   = wu_get_membership( $mid );
	if ( $m && $m->get_sites() ) {
		$n_site++;
	}
	list( $ok, $detail ) = $compare( $gid, 'group ' . $gid );
	if ( $ok ) {
		$n_same++;
	} else {
		$bad[] = $detail;
	}
}
foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->base_prefix}bp_groups ORDER BY id LIMIT 20" ) as $gid ) {
	if ( '' === (string) groups_get_groupmeta( (int) $gid, 'gdc_membership_id' ) ) {
		list( $ok, $detail ) = $compare( (int) $gid, 'unlinked ' . $gid );
		if ( $ok ) {
			$n_same++;
		} else {
			$bad[] = $detail;
		}
	}
}
gs108_assert( array() === $bad && $n_same > 0, 'glue-existing-groups', "linked id + tab chrome identical to 1.3.1 for {$n_same} groups ({$n_site} with site rows; Connected records excluded)", implode( ' | ', array_slice( $bad, 0, 5 ) ) );
gs108_info( 'linked groups: ' . count( (array) $linked ) . ', with site rows: ' . $n_site );

if ( gs108_is_readonly() ) {
	return;
}

// =========================================================================================== normal mode
$super = 0;
foreach ( get_super_admins() as $login ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		$super = (int) $u->ID;
		break;
	}
}
wp_set_current_user( $super );
gs108_assert( $super > 0 && current_user_can( 'manage_options' ), 'glue-super', 'rendering as super admin ' . $super );
$group_id_by_name = function ( $name ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}bp_groups WHERE name = %s", $name ) );
};
$alpha = $group_id_by_name( 'GS108 Alpha' );
$beta  = $group_id_by_name( 'GS108 Beta' );
$paid  = $group_id_by_name( 'GS108 Paid' );
if ( ! gs108_assert( $alpha && $beta && $paid && '' === (string) groups_get_groupmeta( $alpha, 'gdc_membership_id' ) && '' === (string) groups_get_groupmeta( $beta, 'gdc_membership_id' ), 'glue-fixtures', "fixture groups Alpha {$alpha} / Beta {$beta} / Paid {$paid}; Alpha + Beta unlinked" ) ) {
	return;
}
// Kill switch on in-process only (never writes the site option).
$on = function () {
	return '1';
};
add_filter( 'pre_site_option_gdc_connect_enabled', $on, PHP_INT_MAX );
gs108_on_cleanup(
	function () use ( $on ) {
		remove_filter( 'pre_site_option_gdc_connect_enabled', $on, PHP_INT_MAX );
	},
	'kill switch filter'
);
$fired = array();
$spy   = function ( $gid ) use ( &$fired ) {
	$fired[] = $gid;
};
add_action( 'gend_society_group_hosting_connect_panel', $spy, 1 );
gs108_on_cleanup(
	function () use ( $spy ) {
		remove_action( 'gend_society_group_hosting_connect_panel', $spy, 1 );
	},
	'panel spy'
);
// The hosting suite prints a relative time ("last scanned N seconds ago"); masked for byte comparisons.
$norm        = function ( $html ) {
	return (string) preg_replace( '/last scanned [^<]*? ago/', 'last scanned <T> ago', $html );
};
// Each render below is its own "page": the once-per-page guard (did_action) is reset first.
$reset_page = function () {
	unset( $GLOBALS['wp_actions']['gend_society_group_hosting_connect_panel'] );
};
if ( ! class_exists( 'gs108old_Group_Tab_Compute_Gas' ) && '' !== $cg_old ) {
	eval( str_replace( 'class Gend_Society_Group_Tab_Compute_Gas extends', 'class gs108old_Group_Tab_Compute_Gas extends', $cg_old ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test harness: the pinned 1.3.1 class under a renamed name.
}
gs108_assert( class_exists( 'gs108old_Group_Tab_Compute_Gas' ), 'glue-compute-gas-old-loaded', '1.3.1 Compute Gas class loaded as gs108old_Group_Tab_Compute_Gas' );
$cg_render = function ( $class, $gid, $forced = '', $reset = true ) use ( $norm, $reset_page ) {
	if ( $reset ) {
		$reset_page();
	}
	ob_start();
	call_user_func( array( $class, 'render_legacy_content' ), $gid, $forced );
	return $norm( (string) ob_get_clean() );
};
$cg_new_r  = function ( $gid, $forced = '', $reset = true ) use ( $cg_render ) {
	return $cg_render( 'Gend_Society_Group_Tab_Compute_Gas', $gid, $forced, $reset );
};
$cg_old_r  = function ( $gid, $forced = '' ) use ( $cg_render ) {
	return $cg_render( 'gs108old_Group_Tab_Compute_Gas', $gid, $forced );
};
$hosting_new = function ( $gid, $reset = true ) use ( $norm, $reset_page ) {
	if ( $reset ) {
		$reset_page();
	}
	ob_start();
	Gend_Society_Group_Tab_Hosting::render_legacy_content( $gid );
	return $norm( (string) ob_get_clean() );
};
$hosting_old = function ( $gid ) use ( $norm ) {
	ob_start();
	if ( gend_society_group_tabs_user_has_access() ) {
		gend_society_group_render_hosting_suite( $gid );
	}
	return $norm( (string) ob_get_clean() );
};
$panel_only = function ( $gid ) use ( $spy ) {
	remove_action( 'gend_society_group_hosting_connect_panel', $spy, 1 );
	ob_start();
	do_action( 'gend_society_group_hosting_connect_panel', (int) $gid );
	$out = (string) ob_get_clean();
	add_action( 'gend_society_group_hosting_connect_panel', $spy, 1 );
	return $out;
};
$first_diff = function ( $a, $b ) {
	$n = min( strlen( $a ), strlen( $b ) );
	for ( $i = 0; $i < $n && $a[ $i ] === $b[ $i ]; $i++ ) {
		// Advance to the first differing byte.
	}
	return 'at ' . $i . ': ' . wp_json_encode( substr( $a, max( 0, $i - 40 ), 120 ) ) . ' vs ' . wp_json_encode( substr( $b, max( 0, $i - 40 ), 120 ) );
};

// --- Paid (membership without sites, not self-hosted): Hosting tab byte-identical.
$fired = array();
$pn    = $hosting_new( $paid );
$po    = $hosting_old( $paid );
gs108_assert( $pn === $po && '' !== $po, 'glue-paid-hosting', 'GS108 Paid Hosting tab output byte-identical to 1.3.1 (' . strlen( $po ) . ' bytes)', $first_diff( $pn, $po ) );
gs108_assert( array( $paid ) === $fired, 'glue-paid-fired', 'the action still fires once with the group id (int)', wp_json_encode( $fired ) );
$fired = array();
$pcn   = $cg_new_r( $paid );
$pco   = $cg_old_r( $paid );
gs108_assert( $pcn === $pco && '' !== $pco, 'glue-paid-compute-gas', 'GS108 Paid Compute Gas tab output byte-identical to 1.3.1 (' . strlen( $pco ) . ' bytes)', $first_diff( $pcn, $pco ) );
gs108_assert( array( $paid ) === $fired, 'glue-paid-compute-gas-fired', 'Compute Gas fires the action once with the group id (int)', wp_json_encode( $fired ) );
$fired = array();
gs108_assert( $cg_new_r( $paid, 'gas-stations' ) === $cg_old_r( $paid, 'gas-stations' ) && array() === $fired, 'glue-paid-compute-gas-embed', 'embedded Gas Stations view (forced tab): identical to 1.3.1, the action does not fire', wp_json_encode( $fired ) );
gs108_assert( false === strpos( $pn . $pcn, 'gend-society-connect-panel' ), 'glue-paid-no-anchor', 'GS108 Paid: no panel anchor on either tab' );

// --- 108-12b: the "Connect your WordPress site" link (Alpha and Beta are unlinked here).
$gs12b_user = function ( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? (int) $u->ID : 0;
};
$u_admin  = $gs12b_user( 'gs108admin' );
$u_mod    = $gs12b_user( 'gs108mod' );
$u_member = $gs12b_user( 'gs108member' );
$u_other  = $gs12b_user( 'gs108other' );
$alpha_g  = groups_get_group( $alpha );
$want_url = trailingslashit( bp_get_group_permalink( $alpha_g ) ) . 'compute-gas/#gend-society-connect-panel';
$a_state  = gdc_connect_group_state( $alpha );
gs108_assert( $u_admin && $u_mod && $u_member && $u_other && groups_is_user_admin( $u_admin, $alpha ) && groups_is_user_mod( $u_mod, $alpha ) && ! groups_is_user_admin( $u_member, $alpha ) && groups_is_user_member( $u_member, $alpha ) && ! groups_is_user_member( $u_other, $alpha ) && ! is_super_admin( $u_admin ) && 'ok' === $a_state['state'], 'glue-link-fixtures', 'Alpha (state ok): gs108admin admin (not super), gs108mod moderator, gs108member member, gs108other not a member' );
gs108_assert_eq( $want_url, gend_society_group_connect_link_url( $alpha, $u_admin ), 'glue-link-unpaid-admin', 'unpaid group, group admin: link to <group>/compute-gas/#gend-society-connect-panel' );
gs108_assert_eq( $want_url, gend_society_group_connect_link_url( $alpha, $super ), 'glue-link-unpaid-super', 'unpaid group, super admin: link shown' );
gs108_assert_eq( '', gend_society_group_connect_link_url( $alpha, $u_mod ), 'glue-link-mod', 'moderator: no link (admins only)' );
gs108_assert_eq( '', gend_society_group_connect_link_url( $alpha, $u_member ), 'glue-link-member', 'member: no link' );
gs108_assert_eq( '', gend_society_group_connect_link_url( $alpha, $u_other ), 'glue-link-other-group-admin', 'admin of another group (gs108other): no link' );
gs108_assert_eq( '', gend_society_group_connect_link_url( $paid, $u_admin ), 'glue-link-paid-admin', 'paid group, its group admin: no link' );
gs108_assert_eq( '', gend_society_group_connect_link_url( $paid, $super ), 'glue-link-paid-super', 'paid group, super admin: no link' );
$site_id_groups = array_map( 'intval', (array) $wpdb->get_col( "SELECT group_id FROM {$wpdb->base_prefix}bp_groups_groupmeta WHERE meta_key = 'gdc_site_id' AND meta_value > 0 LIMIT 20" ) );
$site_id_shown  = array_filter(
	$site_id_groups,
	function ( $g ) use ( $super ) {
		return '' !== gend_society_group_connect_link_url( $g, $super );
	}
);
gs108_assert( array() !== $site_id_groups && array() === $site_id_shown, 'glue-link-site-id-groups', 'groups with a gdc_site_id site (' . implode( ',', $site_id_groups ) . '): no link, even where VAM state is ok', implode( ',', $site_id_shown ) );
$html = function () use ( $alpha ) {
	return gend_society_group_connect_link_html( $alpha );
};
wp_set_current_user( $u_admin );
$link_html = $html();
gs108_assert( '<p class="gs-group-connect-link"><a class="gs-group-connect-link__a" href="' . esc_url( $want_url ) . '">Connect your WordPress site <span aria-hidden="true">&rarr;</span></a></p>' === $link_html, 'glue-link-html', 'link markup: one <p> with the link', $link_html );
// Switch off (in-process only, the option is never written): no link.
remove_filter( 'pre_site_option_gdc_connect_enabled', $on, PHP_INT_MAX );
$off = function () {
	return '0';
};
add_filter( 'pre_site_option_gdc_connect_enabled', $off, PHP_INT_MAX );
gs108_assert( ! gdc_connect_enabled() && '' === gend_society_group_connect_link_url( $alpha, $u_admin ) && '' === gend_society_group_connect_link_url( $alpha, $super ) && '' === $html(), 'glue-link-switch-off', 'gdc_connect_enabled off: no link for the group admin or the super admin' );
remove_filter( 'pre_site_option_gdc_connect_enabled', $off, PHP_INT_MAX );
add_filter( 'pre_site_option_gdc_connect_enabled', $on, PHP_INT_MAX );
gs108_assert( gdc_connect_enabled() && $want_url === gend_society_group_connect_link_url( $alpha, $u_admin ), 'glue-link-switch-on-again', 'switch back on: link back' );
// Page-level render + stylesheet, with BuddyPress's current group / action set in-process.
$page = function ( $uid, $gid, $action ) {
	$bp    = buddypress();
	$saved = array( $bp->current_component, $bp->current_action, isset( $bp->groups->current_group ) ? $bp->groups->current_group : null, get_current_user_id() );
	wp_set_current_user( $uid );
	$bp->current_component     = $bp->groups->id;
	$bp->current_action        = $action;
	$bp->groups->current_group = groups_get_group( $gid );
	wp_dequeue_style( 'gend-society-group-connect-link' );
	gend_society_group_connect_link_enqueue();
	$css = wp_style_is( 'gend-society-group-connect-link', 'enqueued' );
	ob_start();
	gend_society_group_connect_link_render();
	$out = (string) ob_get_clean();
	wp_dequeue_style( 'gend-society-group-connect-link' );
	$bp->current_component     = $saved[0];
	$bp->current_action        = $saved[1];
	$bp->groups->current_group = $saved[2];
	wp_set_current_user( $saved[3] );
	return array( $out, $css );
};
list( $o, $css ) = $page( $u_admin, $alpha, 'business-plan' );
$css_src         = isset( wp_styles()->registered['gend-society-group-connect-link'] ) ? (string) wp_styles()->registered['gend-society-group-connect-link']->src : '';
gs108_assert( $o === $link_html && $css && false !== strpos( $css_src, 'assets/gs-group-connect-link.css' ), 'glue-link-page-admin', 'group page (Business Plan) as the unpaid-group admin: link printed, static stylesheet enqueued', $o . ' ' . $css_src );
list( $o, $css ) = $page( $u_admin, $alpha, 'compute-gas' );
gs108_assert( '' === $o && $css, 'glue-link-page-compute-gas', 'Compute Gas tab (shows the panel itself): no link; stylesheet loaded for the anchor offset' );
list( $o, $css ) = $page( $u_admin, $alpha, 'hosting' );
gs108_assert( '' === $o, 'glue-link-page-hosting', 'Hosting tab: no link' );
list( $o, $css ) = $page( $u_member, $alpha, 'business-plan' );
gs108_assert( '' === $o && ! $css, 'glue-link-page-member', 'member: no markup, no stylesheet (page unchanged)' );
list( $o, $css ) = $page( $u_mod, $alpha, 'members-hub' );
gs108_assert( '' === $o && ! $css, 'glue-link-page-mod', 'moderator: no markup, no stylesheet' );
list( $o, $css ) = $page( $u_admin, $paid, 'business-plan' );
gs108_assert( '' === $o && ! $css, 'glue-link-page-paid', 'paid group, its admin: no markup, no stylesheet (page unchanged)' );
list( $o, $css ) = $page( 0, $alpha, 'business-plan' );
gs108_assert( '' === $o && ! $css, 'glue-link-page-logged-out', 'logged out: no markup, no stylesheet' );
remove_filter( 'pre_site_option_gdc_connect_enabled', $on, PHP_INT_MAX );
add_filter( 'pre_site_option_gdc_connect_enabled', $off, PHP_INT_MAX );
list( $o, $css ) = $page( $u_admin, $alpha, 'business-plan' );
gs108_assert( '' === $o && ! $css, 'glue-link-page-switch-off', 'switch off: no markup, no stylesheet for the unpaid-group admin' );
remove_filter( 'pre_site_option_gdc_connect_enabled', $off, PHP_INT_MAX );
add_filter( 'pre_site_option_gdc_connect_enabled', $on, PHP_INT_MAX );
wp_set_current_user( $super );
// The link target: unpaid group, Compute Gas tab -> anchor right before the pairing panel.
$fired = array();
$ucg   = $cg_new_r( $alpha );
gs108_assert( 1 === substr_count( $ucg, $gs12b_anchor ) && 1 === substr_count( $ucg, $gs12b_anchor . '<div class="gdc-cp" data-gdc-cp="1"' ) && array( $alpha ) === $fired, 'glue-link-target', 'unpaid group, Compute Gas tab: <span id="gend-society-connect-panel"> once, right before the pairing panel' );

// --- Beta linked to the seeded legacy membership with a site row.
$legacy = wu_get_sites(
	array(
		'meta_query' => array( array( 'key' => 'gs108_fixture', 'value' => 'legacy-site-row' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test fixture lookup.
		'number'     => 1,
	)
);
$legacy = $legacy ? reset( $legacy ) : null;
$lmid   = $legacy ? (int) $legacy->get_membership_id() : 0;
if ( gs108_assert( $lmid > 0, 'glue-legacy-site', 'seeded legacy site row ' . ( $legacy ? $legacy->get_id() : '-' ) . ' on membership ' . $lmid ) ) {
	groups_update_groupmeta( $beta, 'gdc_membership_id', $lmid );
	gs108_on_cleanup(
		function () use ( $beta ) {
			groups_delete_groupmeta( $beta, 'gdc_membership_id' );
		},
		'beta link'
	);
	list( $ok, $detail, $lid, $lchrome ) = $compare( $beta, 'beta' );
	gs108_assert( $ok && '' !== $lid && (string) $legacy->get_meta( 'gdc_install_id', '' ) === $lid, 'glue-site-row-same', 'site-row group: linked id (' . $lid . ') + tab chrome identical to 1.3.1', $detail );
	$lurl = (string) $legacy->get_active_site_url();
	// 1.3.1 links <site>/wp-admin/ when the site row has an address and shows no link otherwise; no Connected fallback for site rows.
	gs108_assert( '' !== $lurl ? false !== strpos( $lchrome, 'href="' . esc_url( trailingslashit( $lurl ) . 'wp-admin/' ) . '"' ) : false === strpos( $lchrome, 'Open Web App Admin' ), 'glue-site-row-link', '' !== $lurl ? 'site-row chrome links <site>/wp-admin/' : 'site row without an address: no link (as 1.3.1), no record fallback', 'site url ' . (string) $legacy->get_active_site_url() . ' chrome: ' . ( preg_match( '/<a href="[^"]*"/', $lchrome, $mm ) ? $mm[0] : '(no link)' ) );
	$fired = array();
	$bn    = $hosting_new( $beta );
	$bo    = $hosting_old( $beta );
	$bp    = $panel_only( $beta );
	gs108_assert( '' === $bp && $bn === $bo, 'glue-site-row-hosting', 'site-row group: the panel action prints nothing and the Hosting tab is byte-identical to 1.3.1', 'panel ' . strlen( $bp ) . ' bytes: ' . substr( wp_strip_all_tags( $bp ), 0, 160 ) . ' / ' . $first_diff( $bn, $bp . $bo ) );
	gs108_assert( array( $beta ) === $fired, 'glue-site-row-fired', 'the action fires once with the group id', wp_json_encode( $fired ) );
	$fired = array();
	$bcn   = $cg_new_r( $beta );
	$bco   = $cg_old_r( $beta );
	gs108_assert( $bcn === $bco && array( $beta ) === $fired, 'glue-site-row-compute-gas', 'site-row group: Compute Gas tab byte-identical to 1.3.1, action fired once', $first_diff( $bcn, $bco ) . ' ' . wp_json_encode( $fired ) );
	gs108_assert( '' === gend_society_group_connect_link_url( $beta, $super ) && '' === gend_society_group_connect_link_url( $beta, $gs12b_user( 'gs108other' ) ), 'glue-link-site-row', 'site-row group: no link for its admin or the super admin' );
}

// --- Alpha linked to a Connected record.
$admin_u = get_user_by( 'login', 'gs108admin' );
$cust    = $admin_u ? wu_get_customer_by_user_id( $admin_u->ID ) : null;
$made_c  = false;
if ( $admin_u && ! $cust ) {
	$cust   = wu_create_customer( array( 'user_id' => $admin_u->ID, 'email_verification' => 'verified', 'signup_form' => 'gs108' ) );
	$made_c = ! is_wp_error( $cust );
}
$plan = function_exists( 'gdc_connect_plan_id' ) ? (int) gdc_connect_plan_id() : 0;
$mem  = ( $cust && ! is_wp_error( $cust ) && $plan ) ? wu_create_membership(
	array(
		'customer_id'     => $cust->get_id(),
		'user_id'         => $admin_u->ID,
		'plan_id'         => $plan,
		'amount'          => 0,
		'initial_amount'  => 0,
		'recurring'       => false,
		'auto_renew'      => false,
		'status'          => 'active',
		'signup_method'   => 'gs108-record',
		'date_activate'   => wu_get_current_time( 'mysql', true ),
		'date_expiration' => null,
	)
) : null;
if ( ! gs108_assert( $mem && ! is_wp_error( $mem ), 'glue-record', 'Connected record created (plan ' . $plan . ')', is_wp_error( $mem ) ? $mem->get_error_message() : '' ) ) {
	return;
}
$cmid = (int) $mem->get_id();
gs108_on_cleanup(
	function () use ( $cmid, $alpha, $made_c, $cust ) {
		groups_delete_groupmeta( $alpha, 'gdc_membership_id' );
		$x = wu_get_membership( $cmid );
		if ( $x ) {
			$x->delete();
		}
		if ( $made_c && $cust ) {
			$cust->delete();
		}
	},
	'alpha record'
);
$iid   = wp_generate_uuid4();
$ext   = 'https://glue-' . wp_rand( 100000, 999999 ) . '.gs108.test/';
$admin = $ext . 'wp-admin/';
$cat   = time() - DAY_IN_SECONDS;
foreach (
	array(
		'gdc_self_hosted'       => 1,
		'gdc_install_id'        => $iid,
		'gdc_external_url'      => $ext,
		'gdc_install_admin_url' => $admin,
		'gdc_connection_state'  => 'connected',
		'gdc_connected_at'      => $cat,
		'gdc_bp_group_id'       => $alpha,
	) as $k => $v
) {
	$mem->update_meta( $k, $v );
}
groups_update_groupmeta( $alpha, 'gdc_membership_id', $cmid );
$mem = wu_get_membership( $cmid );
gs108_assert( array() === (array) $mem->get_sites(), 'glue-record-siteless', 'the Connected record has no site rows' );

gs108_assert_eq( $iid, gend_society_group_get_linked_install_id( $alpha ), 'glue-connected-id', 'linked install id = the record gdc_install_id' );
gs108_assert_eq( '', gs108old_group_get_linked_install_id( $alpha ), 'glue-connected-id-old', '(1.3.1 saw nothing for it)' );
$c = $chrome( 'gend_society_group_tab_open', $alpha );
gs108_assert( false !== strpos( $c, 'href="' . esc_url( $admin ) . '"' ) && false !== strpos( $c, 'Open Web App Admin' ) && false === strpos( $c, 'wp-admin/wp-admin' ), 'glue-connected-link', '"Open Web App Admin" links gdc_install_admin_url' );
gs108_assert( false === strpos( $chrome( 'gs108old_group_tab_open', $alpha ), 'Open Web App Admin' ), 'glue-connected-link-old', '(1.3.1 showed no link)' );

$fired = array();
$an    = $hosting_new( $alpha );
gs108_assert( array( $alpha ) === $fired && is_int( $fired[0] ), 'glue-connected-fired', 'Hosting tab fires gend_society_group_hosting_connect_panel once with (int) group id', wp_json_encode( $fired ) );
gs108_assert( false !== strpos( $an, 'Connected:' ) && false !== strpos( $an, esc_url( $ext ) ) && false === strpos( $an, 'data-gdc-cp-mint' ), 'glue-connected-panel', 'Hosting tab shows VAM "Connected: <url>" and no pairing button', substr( wp_strip_all_tags( $panel_only( $alpha ) ), 0, 200 ) );
$ao  = $hosting_old( $alpha );
$ap  = $panel_only( $alpha );
$apx = '' !== $ap ? $gs12b_anchor . $ap : '';
gs108_assert( $an === $apx . $ao, 'glue-connected-hosting-rest', 'apart from the panel (and its anchor) the Hosting tab output is the 1.3.1 suite', $first_diff( $an, $apx . $ao ) );
// Compute Gas for the Connected record: the same panel inside the tab suite, the rest is 1.3.1.
$fired = array();
$acn   = $cg_new_r( $alpha );
$aco   = $cg_old_r( $alpha );
gs108_assert( array( $alpha ) === $fired && is_int( $fired[0] ), 'glue-connected-compute-gas-fired', 'Compute Gas fires the action once with (int) group id', wp_json_encode( $fired ) );
$p_at = '' !== $apx ? strpos( $acn, $apx ) : false;
gs108_assert( '' !== $ap && false !== $p_at && $p_at > (int) strpos( $acn, 'data-gs-cg-tabsuite>' ) && $p_at < (int) strpos( $acn, '<nav class="gs-cg-toptabs"' ), 'glue-connected-compute-gas-panel', 'Compute Gas shows the same panel ("Connected: <url>", after its anchor) inside the tab suite, before the top tabs' );
$a_rest = false !== $p_at ? substr( $acn, 0, $p_at ) . substr( $acn, $p_at + strlen( $apx ) ) : '';
gs108_assert( false !== $p_at && $a_rest === $aco, 'glue-connected-compute-gas-rest', 'apart from the panel and its anchor the Compute Gas tab output is 1.3.1', false !== $p_at ? $first_diff( $a_rest, $aco ) : 'panel not found' );
// One request rendering both tabs: the panel renders once (first caller).
$fired = array();
$reset_page();
$h1 = $hosting_new( $alpha, false );
$c1 = $cg_new_r( $alpha, '', false );
gs108_assert( array( $alpha ) === $fired && 1 === substr_count( $h1 . $c1, $ap ) && 1 === substr_count( $h1 . $c1, $gs12b_anchor ) && false !== strpos( $h1, $apx ) && $c1 === $aco, 'glue-once-per-page', 'Hosting tab + Compute Gas in one request: the panel renders once (Hosting, first), Compute Gas then equals 1.3.1', wp_json_encode( $fired ) );
$fired = array();
gs108_assert( $cg_new_r( $alpha, 'gas-stations' ) === $cg_old_r( $alpha, 'gas-stations' ) && array() === $fired, 'glue-connected-compute-gas-embed', 'embedded Gas Stations view: no panel, identical to 1.3.1', wp_json_encode( $fired ) );

gs108_assert( '' === gend_society_group_connect_link_url( $alpha, $super ) && '' === gend_society_group_connect_link_url( $alpha, $u_admin ), 'glue-link-connected', 'Connected group: no link (super admin, group admin)' );

// Admin URL missing -> external URL + wp-admin/.
$mem->delete_meta( 'gdc_install_admin_url' );
$mem = wu_get_membership( $cmid );
$c   = $chrome( 'gend_society_group_tab_open', $alpha );
gs108_assert( false !== strpos( $c, 'href="' . esc_url( $ext . 'wp-admin/' ) . '"' ), 'glue-connected-link-fallback', 'without gdc_install_admin_url: gdc_external_url + wp-admin/' );
$mem->update_meta( 'gdc_install_admin_url', $admin );

// Disconnected -> nothing linked, no link, the pairing button is back.
$mem->update_meta( 'gdc_connection_state', 'disconnected' );
$mem = wu_get_membership( $cmid );
gs108_assert_eq( '', gend_society_group_get_linked_install_id( $alpha ), 'glue-disconnected-id', 'disconnected record: no linked install id' );
gs108_assert( false === strpos( $chrome( 'gend_society_group_tab_open', $alpha ), 'Open Web App Admin' ), 'glue-disconnected-link', 'disconnected record: no "Open Web App Admin" link' );
gs108_assert( false !== strpos( $hosting_new( $alpha ), 'data-gdc-cp-mint="1"' ), 'glue-disconnected-panel', 'disconnected record: Hosting tab offers "Get pairing code" again' );
gs108_assert( false !== strpos( $cg_new_r( $alpha ), 'data-gdc-cp-mint="1"' ), 'glue-disconnected-compute-gas', 'disconnected record: Compute Gas offers "Get pairing code" too' );
gs108_assert_eq( $want_url, gend_society_group_connect_link_url( $alpha, $u_admin ), 'glue-link-disconnected', 'disconnected record: the link is back for the group admin' );

// Not self-hosted -> nothing.
$mem->update_meta( 'gdc_connection_state', 'connected' );
$mem->delete_meta( 'gdc_self_hosted' );
$mem = wu_get_membership( $cmid );
gs108_assert( '' === gend_society_group_get_linked_install_id( $alpha ) && false === strpos( $chrome( 'gend_society_group_tab_open', $alpha ), 'Open Web App Admin' ), 'glue-not-self-hosted', 'membership without gdc_self_hosted: no linked id, no link (1.3.1 behaviour)' );
$mem->update_meta( 'gdc_self_hosted', 1 );

// A member without access sees no Hosting tab body (and so no panel).
$member = get_user_by( 'login', 'gs108member' );
if ( $member ) {
	wp_set_current_user( $member->ID );
	$fired = array();
	gs108_assert( '' === $hosting_new( $alpha ) && array() === $fired, 'glue-member', 'gs108member: empty Hosting tab, action not fired' );
	gs108_assert( '' === $cg_new_r( $alpha ) && array() === $fired, 'glue-member-compute-gas', 'gs108member: empty Compute Gas tab, action not fired' );
	wp_set_current_user( $super );
}
