<?php
/**
 * gend.me Dashboard Kit (owner request 2026-11-12): every gend.me dashboard in wp-admin shares one header and one
 * tab style, so the backend reads as a single workflow.
 *
 * - One header per dashboard screen: plan + "Dashboard" kicker, title, one-line description, main actions,
 *   "About this dashboard" (its gend.me info page), "Works with" links to the dashboards that feed into or follow
 *   from it, and 2-4 live stat cards. Printed at the top of the screen on all_admin_notices.
 * - Each dashboard keeps its own tabs and content. Its old hero is hidden and its tab bar is restyled through
 *   assets/dashboard-kit.css, keyed off the body class gdk-<key>.
 * - Names, descriptions and info pages come from gend_society_get_feature_definitions() (feature-cards.php).
 * - Stats are read-only counts, cached 5 minutes per site; every source is guarded so a missing plugin or table
 *   just drops that card.
 * - The sidebar stays the only menu: the kit adds no navigation bar (owner rule, 2026-10-26).
 *
 * Manifest: tier customer, needs ['skin'] (hub + containers with the GenD admin skin).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dashboard keys (feature-definition keys, plus 'home') => admin page and screen match. */
function gend_society_dk_screens() {
	return array(
		'home'       => array( 'url' => 'index.php', 'page' => '', 'hook' => 'dashboard' ),
		'wireframe'  => array( 'url' => 'site-editor.php', 'page' => 'gs-design-studio' ),
		'blog'       => array( 'url' => 'admin.php?page=content-campaigns', 'page' => 'content-campaigns' ),
		'email'      => array( 'url' => 'admin.php?page=talk-flows', 'page' => 'talk-flows', 'alt' => array( 'email-manager' ) ),
		'store'      => array( 'url' => 'admin.php?page=gdc-store-settings', 'page' => 'gdc-store-settings' ),
		'sales'      => array( 'url' => 'admin.php?page=st_sales_team', 'page' => 'st_sales_team' ),
		'projects'   => array( 'url' => 'admin.php?page=psoo-projects', 'page' => 'psoo-projects' ),
		'social'     => array( 'url' => 'admin.php?page=gdc-social-network-settings', 'page' => 'gdc-social-network-settings' ),
		'rewards'    => array( 'url' => 'admin.php?page=gs-rewards', 'page' => 'gs-rewards' ),
		'membership' => array( 'url' => 'admin.php?page=gend-contracts-payments', 'page' => 'gend-contracts-payments' ),
	);
}

/** How the dashboards hand work to each other: key => the 2-3 dashboards it feeds or follows from. */
function gend_society_dk_works_with() {
	return array(
		'home'       => array( 'wireframe', 'blog', 'store' ),
		'wireframe'  => array( 'blog', 'store' ),
		'blog'       => array( 'email', 'sales' ),
		'email'      => array( 'blog', 'social', 'sales' ),
		'store'      => array( 'sales', 'membership', 'rewards' ),
		'sales'      => array( 'store', 'membership' ),
		'projects'   => array( 'membership', 'email' ),
		'social'     => array( 'rewards', 'email' ),
		'rewards'    => array( 'store', 'social' ),
		'membership' => array( 'store', 'sales', 'projects' ),
	);
}

/**
 * On by default (containers get it with the release). A site can hold it back with the site option
 * gend_society_dk_enabled = 0; an administrator can still preview it with ?gdk_preview=1 (sets a session cookie,
 * ?gdk_preview=0 clears it).
 */
function gend_society_dk_enabled() {
	if ( (bool) apply_filters( 'gend_society_dk_enabled', (bool) get_site_option( 'gend_society_dk_enabled', 1 ) ) ) {
		return true;
	}
	return ! empty( $_COOKIE['gdk_preview'] ) && current_user_can( 'manage_options' );
}

add_action( 'admin_init', function () {
	if ( isset( $_GET['gdk_preview'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$on = '1' === (string) $_GET['gdk_preview']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		setcookie( 'gdk_preview', $on ? '1' : '', $on ? 0 : time() - 3600, ADMIN_COOKIE_PATH, COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE['gdk_preview'] = $on ? '1' : '';
	}
}, 1 );

/** The dashboard this request is showing, or ''. */
function gend_society_dk_current() {
	static $key = null;
	if ( null !== $key ) {
		return $key;
	}
	$key = '';
	if ( ! gend_society_dk_enabled() ) {
		return $key;
	}
	if ( ! is_admin() || wp_doing_ajax() || ( isset( $_GET['gdc_tab_only'] ) || isset( $_GET['gend_cp_frame'] ) || isset( $_GET['gs_studio_embed'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $key;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	global $pagenow;
	foreach ( gend_society_dk_screens() as $k => $s ) {
		if ( '' !== $s['page'] && ( $page === $s['page'] || in_array( $page, isset( $s['alt'] ) ? $s['alt'] : array(), true ) ) ) {
			// Theme Builder (the Design Studio app) keeps its own layout: no shared header there (owner, 2026-11-12).
			$key = 'wireframe' === $k ? '' : $k;
			break;
		}
		if ( 'home' === $k && 'index.php' === $pagenow && '' === $page ) {
			$key = $k;
			break;
		}
	}
	return $key;
}

/** Name, description, info page and plan for a key. */
function gend_society_dk_info( $key ) {
	if ( 'home' === $key ) {
		return array(
			'name'        => __( 'Dashboard', 'gend-society' ),
			'description' => __( 'Your web app at a glance: plan, hosting, storage, compute and the projects behind it. Every other dashboard starts here.', 'gend-society' ),
			'link'        => 'https://gend.me/app-features/',
			'plan'        => '',
		);
	}
	$defs = function_exists( 'gend_society_get_feature_definitions' ) ? gend_society_get_feature_definitions() : array();
	return isset( $defs[ $key ] ) ? $defs[ $key ] : null;
}

function gend_society_dk_plan_label( $plan ) {
	$labels = array(
		'content-builder'  => __( 'Content Builder', 'gend-society' ),
		'store-owner'      => __( 'Store Owner', 'gend-society' ),
		'social-connector' => __( 'Social Connector', 'gend-society' ),
	);
	return isset( $labels[ $plan ] ) ? $labels[ $plan ] : __( 'Your web app', 'gend-society' );
}

/** Is that dashboard's admin page registered on this site (so a link to it works)? */
function gend_society_dk_available( $key ) {
	$screens = gend_society_dk_screens();
	if ( ! isset( $screens[ $key ] ) ) {
		return false;
	}
	$page = $screens[ $key ]['page'];
	if ( '' === $page || 'gs-design-studio' === $page ) {
		return true; // core screens (index.php, site-editor.php)
	}
	global $_registered_pages;
	foreach ( array( 'toplevel_page_', 'admin_page_' ) as $prefix ) {
		if ( isset( $_registered_pages[ $prefix . $page ] ) ) {
			return true;
		}
	}
	return '' !== (string) menu_page_url( $page, false );
}

function gend_society_dk_table_exists( $table ) {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function gend_society_dk_count_posts( $type, $status = 'publish' ) {
	if ( ! post_type_exists( $type ) ) {
		return null;
	}
	$c = wp_count_posts( $type );
	return isset( $c->$status ) ? (int) $c->$status : 0;
}

/** Live stat cards: list of [value, label, sub]. Read-only, cached 5 minutes. */
function gend_society_dk_stats( $key ) {
	$cache_key = 'gend_society_dk_stats_' . $key;
	$hit       = get_transient( $cache_key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	global $wpdb;
	$s   = array();
	$add = function ( $value, $label, $sub = '' ) use ( &$s ) {
		if ( null !== $value ) {
			$s[] = array( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value, $label, $sub );
		}
	};
	$users = function () {
		$u = count_users();
		return isset( $u['total_users'] ) ? (int) $u['total_users'] : 0;
	};
	$fulfil = function () {
		return function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( 'processing' ) + (int) wc_orders_count( 'on-hold' ) : null;
	};

	switch ( $key ) {
		case 'home':
			$add( gend_society_dk_count_posts( 'page' ), __( 'Pages', 'gend-society' ), __( 'Published', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'post' ), __( 'Posts', 'gend-society' ), __( 'Published', 'gend-society' ) );
			$add( $users(), __( 'Members', 'gend-society' ), __( 'Accounts on this site', 'gend-society' ) );
			$add( $fulfil(), __( 'Orders to fulfil', 'gend-society' ), __( 'Processing + on hold', 'gend-society' ) );
			break;
		case 'wireframe':
			$add( gend_society_dk_count_posts( 'wp_template' ), __( 'Templates', 'gend-society' ), __( 'Customised', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'wp_template_part' ), __( 'Template parts', 'gend-society' ), __( 'Header, footer and more', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'wp_font_family' ), __( 'Fonts', 'gend-society' ), __( 'Installed', 'gend-society' ) );
			break;
		case 'blog':
			$add( gend_society_dk_count_posts( 'post' ), __( 'Published posts', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'post', 'future' ), __( 'Scheduled', 'gend-society' ), __( 'Waiting to publish', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'post', 'draft' ), __( 'Drafts', 'gend-society' ) );
			break;
		case 'email':
			$add( gend_society_dk_count_posts( 'chat_form' ), __( 'Forms & chatflows', 'gend-society' ), __( 'Published', 'gend-society' ) );
			$subs = $wpdb->prefix . 'gdc_email_subscribers';
			$add( gend_society_dk_table_exists( $subs ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$subs}`" ) : null, __( 'Subscribers', 'gend-society' ), __( 'On your email lists', 'gend-society' ) ); // phpcs:ignore
			$add( gend_society_dk_count_posts( 'em_posting' ), __( 'Open postings', 'gend-society' ), __( 'Roles taking applications', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'chat_submission' ), __( 'Submissions', 'gend-society' ), __( 'Forms and applications', 'gend-society' ) );
			break;
		case 'store':
			$st = function_exists( 'gdc_hub_get_stats' ) ? gdc_hub_get_stats() : array();
			$drafts = ! empty( $st['drafts'] ) ? (int) $st['drafts'] : 0;
			/* translators: %s: number of draft products */
			$drafts_txt = $drafts ? sprintf( _n( '%s draft', '%s drafts', $drafts, 'gend-society' ), number_format_i18n( $drafts ) ) : '';
			$add( isset( $st['products'] ) ? $st['products'] : gend_society_dk_count_posts( 'product' ), __( 'Live products', 'gend-society' ), $drafts_txt );
			$add( isset( $st['fulfil'] ) ? $st['fulfil'] : $fulfil(), __( 'Orders to fulfil', 'gend-society' ), __( 'Processing + on hold', 'gend-society' ) );
			if ( isset( $st['categories'] ) ) {
				$add( (int) $st['categories'] + (int) $st['tags'], __( 'Catalog terms', 'gend-society' ), __( 'Categories and tags', 'gend-society' ) );
			}
			if ( isset( $st['vendors'] ) ) {
				$add( $st['vendors'], __( 'Vendors', 'gend-society' ), __( 'Paid automatically in DGEN', 'gend-society' ) );
			}
			break;
		case 'sales':
			$add( gend_society_dk_count_posts( 'st_proposal' ), __( 'Proposals', 'gend-society' ), __( 'Published', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'st_proposal', 'draft' ), __( 'Drafts', 'gend-society' ), __( 'Proposals in progress', 'gend-society' ) );
			$ref = function_exists( 'aas_umk' ) ? aas_umk( 'referrer_id' ) : 'referrer_id';
			$add( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' AND meta_value <> '0'", $ref ) ), __( 'Team members', 'gend-society' ), __( 'Joined through a referral', 'gend-society' ) ); // phpcs:ignore
			break;
		case 'projects':
			$pt = $wpdb->prefix . 'pm_projects';
			$tt = $wpdb->prefix . 'pm_tasks';
			$add( gend_society_dk_table_exists( $pt ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$pt}`" ) : null, __( 'Projects', 'gend-society' ) ); // phpcs:ignore
			$add( gend_society_dk_table_exists( $tt ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tt}` WHERE status = 0" ) : null, __( 'Open tasks', 'gend-society' ), __( 'Across every project', 'gend-society' ) ); // phpcs:ignore
			$add( $fulfil(), __( 'Orders to fulfil', 'gend-society' ), __( 'Services waiting to start', 'gend-society' ) );
			break;
		case 'social':
			$add( $users(), __( 'Members', 'gend-society' ) );
			$add( function_exists( 'groups_get_total_group_count' ) ? (int) groups_get_total_group_count() : null, __( 'Groups', 'gend-society' ) );
			$add( function_exists( 'bp_get_member_types' ) ? count( (array) bp_get_member_types() ) : null, __( 'Member types', 'gend-society' ) );
			break;
		case 'rewards':
			$add( function_exists( 'mycred_get_types' ) ? count( (array) mycred_get_types() ) : null, __( 'Point types', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'mycred_badge' ), __( 'Badges', 'gend-society' ) );
			$add( gend_society_dk_count_posts( 'mycred_rank' ), __( 'Ranks', 'gend-society' ) );
			break;
		case 'membership':
			$gid = ( class_exists( 'Gend_CP_Admin_Page' ) && method_exists( 'Gend_CP_Admin_Page', 'dashboard_group' ) ) ? (int) Gend_CP_Admin_Page::dashboard_group() : 0;
			if ( $gid && function_exists( 'groups_get_groupmeta' ) ) {
				$on = (bool) groups_get_groupmeta( $gid, '_psoo_group_payments_active', true );
				$add( $on ? __( 'Active', 'gend-society' ) : __( 'Off', 'gend-society' ), __( 'gend.me Payments', 'gend-society' ), $on ? __( 'Collecting through gend.me', 'gend-society' ) : __( 'Activate it on the Payments tab', 'gend-society' ) );
				$grp = function_exists( 'groups_get_group' ) ? groups_get_group( $gid ) : null;
				if ( $grp && ! empty( $grp->name ) ) {
					$add( $grp->name, __( 'Payments group', 'gend-society' ) );
				}
			}
			break;
	}
	$s = array_slice( $s, 0, 4 );
	set_transient( $cache_key, $s, 5 * MINUTE_IN_SECONDS );
	return $s;
}

/** Main actions per dashboard: [label, url, primary?]. Only core URLs that exist everywhere. */
function gend_society_dk_actions( $key ) {
	switch ( $key ) {
		case 'home':
			return array( array( __( 'View site', 'gend-society' ), home_url( '/' ), true ) );
		case 'wireframe':
			return array( array( __( 'View site', 'gend-society' ), home_url( '/' ), false ) );
		case 'blog':
			return array( array( __( 'New post', 'gend-society' ), admin_url( 'post-new.php' ), true ), array( __( 'New page', 'gend-society' ), admin_url( 'post-new.php?post_type=page' ), false ) );
		case 'store':
			$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
			return post_type_exists( 'product' ) ? array( array( __( 'Add product', 'gend-society' ), admin_url( 'post-new.php?post_type=product' ), true ), array( __( 'View store', 'gend-society' ), $shop, false ) ) : array();
	}
	return array();
}

add_filter( 'admin_body_class', function ( $classes ) {
	$key = gend_society_dk_current();
	return $key ? $classes . ' gdk-on gdk-' . $key . ' ' : $classes;
} );

add_action( 'admin_enqueue_scripts', function () {
	if ( ! gend_society_dk_current() ) {
		return;
	}
	wp_enqueue_style( 'gend-society-dashboard-kit', GEND_SOCIETY_URL . 'assets/dashboard-kit.css', array(), GEND_SOCIETY_VERSION );
}, 99 );

add_action( 'all_admin_notices', 'gend_society_dk_render', 0 );

function gend_society_dk_icon( $k ) {
	$p = array(
		'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'ext'   => '<path d="M14 4h6v6"/><path d="M10 14 20 4"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
		'link'  => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
	);
	return '<svg class="gdk-ic" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( isset( $p[ $k ] ) ? $p[ $k ] : '' ) . '</svg>';
}

function gend_society_dk_render() {
	$key  = gend_society_dk_current();
	$info = $key ? gend_society_dk_info( $key ) : null;
	if ( ! $info ) {
		return;
	}
	$stats   = gend_society_dk_stats( $key );
	$actions = gend_society_dk_actions( $key );
	$screens = gend_society_dk_screens();
	$links   = array();
	foreach ( gend_society_dk_works_with()[ $key ] ?? array() as $k ) {
		$i = gend_society_dk_info( $k );
		if ( $i && gend_society_dk_available( $k ) ) {
			$links[] = array( $i['name'], admin_url( $screens[ $k ]['url'] ) );
		}
	}
	$plan = gend_society_dk_plan_label( isset( $info['plan'] ) ? $info['plan'] : '' );
	// Each dashboard pads its own wrapper differently: the header moves inside it so both line up.
	$targets = array(
		'home'       => '#wpbody-content .wrap.gs-page',
		'blog'       => '#wpbody-content .gdc-admin-dashboard',
		'email'      => '#wpbody-content .gdc-admin-dashboard',
		'store'      => '#wpbody-content .gdc-admin-dashboard',
		'social'     => '#wpbody-content .gdc-admin-dashboard',
		'rewards'    => '#wpbody-content .gdc-admin-dashboard',
		'sales'      => '#wpbody-content .sales-page-tabs-wrap',
		'projects'   => '#wpbody-content .psoo-pm-wrap',
		'membership' => '#wpbody-content .gend-cp-dash',
	);
	?>
	<section class="gdk-head<?php echo $stats ? '' : ' gdk-head--nostats'; ?>" aria-labelledby="gdk-title">
		<div class="gdk-head__intro">
			<span class="gdk-kicker"><?php echo esc_html( $plan ); ?> <i aria-hidden="true">&middot;</i> <?php esc_html_e( 'Dashboard', 'gend-society' ); ?></span>
			<h1 class="gdk-title" id="gdk-title"><?php echo esc_html( $info['name'] ); ?></h1>
			<p class="gdk-lead"><?php echo esc_html( $info['description'] ); ?></p>
			<div class="gdk-actions">
				<?php foreach ( $actions as $a ) : ?>
					<a class="gdk-btn<?php echo $a[2] ? ' gdk-btn--primary' : ''; ?>" href="<?php echo esc_url( $a[1] ); ?>"><?php echo esc_html( $a[0] ); ?></a>
				<?php endforeach; ?>
				<?php if ( ! empty( $info['link'] ) ) : ?>
					<a class="gdk-about" href="<?php echo esc_url( $info['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'About this dashboard', 'gend-society' ); ?> <?php echo gend_society_dk_icon( 'ext' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php endif; ?>
			</div>
			<?php if ( $links ) : ?>
				<p class="gdk-works"><span><?php echo gend_society_dk_icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Works with', 'gend-society' ); ?></span>
					<?php foreach ( $links as $l ) : ?>
						<a href="<?php echo esc_url( $l[1] ); ?>"><?php echo esc_html( $l[0] ); ?> <?php echo gend_society_dk_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( $stats ) : ?>
			<div class="gdk-stats gdk-stats--<?php echo (int) count( $stats ); ?>">
				<?php foreach ( $stats as $st ) : ?>
					<div class="gdk-stat"><b><?php echo esc_html( $st[0] ); ?></b><span><?php echo esc_html( $st[1] ); ?></span><?php if ( '' !== $st[2] ) : ?><small><?php echo esc_html( $st[2] ); ?></small><?php endif; ?></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php if ( isset( $targets[ $key ] ) ) : ?>
	<script>(function () {
		var h = document.getElementById('gdk-title');
		h = h && h.closest('.gdk-head');
		function move() {
			var t = document.querySelector(<?php echo wp_json_encode( $targets[ $key ] ); ?>);
			if (!h || !t || t.contains(h)) return;
			t.insertBefore(h, t.firstChild);
			h.classList.add('is-in');
		}
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', move); else move();
	}());</script>
	<?php endif; ?>
	<?php
}
