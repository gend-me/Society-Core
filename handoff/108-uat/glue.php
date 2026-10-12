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
gs108_assert( "functiongend_society_group_hosting_connect_panel(\$group_id){if(did_action('gend_society_group_hosting_connect_panel'))return;do_action('gend_society_group_hosting_connect_panel',(int)\$group_id);}" === preg_replace( '/\s+/', '', $helper ), 'glue-once-helper', 'gend_society_group_hosting_connect_panel() fires the action at most once per page (did_action guard)', $helper );
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
$ao = $hosting_old( $alpha );
$ap = $panel_only( $alpha );
gs108_assert( $an === $ap . $ao, 'glue-connected-hosting-rest', 'apart from the panel the Hosting tab output is the 1.3.1 suite', $first_diff( $an, $ap . $ao ) );
// Compute Gas for the Connected record: the same panel inside the tab suite, the rest is 1.3.1.
$fired = array();
$acn   = $cg_new_r( $alpha );
$aco   = $cg_old_r( $alpha );
gs108_assert( array( $alpha ) === $fired && is_int( $fired[0] ), 'glue-connected-compute-gas-fired', 'Compute Gas fires the action once with (int) group id', wp_json_encode( $fired ) );
$p_at = '' !== $ap ? strpos( $acn, $ap ) : false;
gs108_assert( '' !== $ap && false !== $p_at && $p_at > (int) strpos( $acn, 'data-gs-cg-tabsuite>' ) && $p_at < (int) strpos( $acn, '<nav class="gs-cg-toptabs"' ), 'glue-connected-compute-gas-panel', 'Compute Gas shows the same panel ("Connected: <url>") inside the tab suite, before the top tabs' );
$a_rest = false !== $p_at ? substr( $acn, 0, $p_at ) . substr( $acn, $p_at + strlen( $ap ) ) : '';
gs108_assert( false !== $p_at && $a_rest === $aco, 'glue-connected-compute-gas-rest', 'apart from the panel the Compute Gas tab output is 1.3.1', false !== $p_at ? $first_diff( $a_rest, $aco ) : 'panel not found' );
// One request rendering both tabs: the panel renders once (first caller).
$fired = array();
$reset_page();
$h1 = $hosting_new( $alpha, false );
$c1 = $cg_new_r( $alpha, '', false );
gs108_assert( array( $alpha ) === $fired && 1 === substr_count( $h1 . $c1, $ap ) && false !== strpos( $h1, $ap ) && $c1 === $aco, 'glue-once-per-page', 'Hosting tab + Compute Gas in one request: the panel renders once (Hosting, first), Compute Gas then equals 1.3.1', wp_json_encode( $fired ) );
$fired = array();
gs108_assert( $cg_new_r( $alpha, 'gas-stations' ) === $cg_old_r( $alpha, 'gas-stations' ) && array() === $fired, 'glue-connected-compute-gas-embed', 'embedded Gas Stations view: no panel, identical to 1.3.1', wp_json_encode( $fired ) );

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
