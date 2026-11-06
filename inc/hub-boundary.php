<?php
/**
 * Keeps gend.me's own operations on gend.me. Hub sub-sites run the same code on the same server, and "am I the hub?"
 * checks are true on them too, so a sub-site's URL could otherwise reach what belongs to the main site.
 * Only acts on the sub-sites of a multisite network. Network super admins pass everywhere.
 *
 * @package GenD_Society
 */

defined( 'ABSPATH' ) || exit;

/** Is this request on a hub sub-site? */
function gend_society_hb_on_subsite() {
	return is_multisite() && ! is_main_site();
}

/**
 * A web app owner on a sub-site: a site admin, the same as they'd be in a container, and never a network admin.
 * For screens that belong to the site, which used manage_network to mean this.
 *
 * @return bool
 */
function gend_society_hb_is_site_admin() {
	return gend_society_hb_on_subsite() && current_user_can( 'manage_options' );
}

add_filter( 'user_has_cap', 'gend_society_hb_no_network_cap', 999, 4 );
/** The administrator role has carried manage_network, which only a network super admin may hold. */
function gend_society_hb_no_network_cap( $allcaps, $caps, $args, $user ) {
	if ( ! empty( $allcaps['manage_network'] ) && gend_society_hb_on_subsite() && ! is_super_admin( (int) $user->ID ) ) {
		unset( $allcaps['manage_network'] );
	}
	return $allcaps;
}

add_filter( 'rest_pre_dispatch', 'gend_society_hb_rest', 5, 3 );
/** Hub-only routes that only ask for manage_options, which every sub-site owner holds. */
function gend_society_hb_rest( $result, $server, $request ) {
	if ( null !== $result || ! gend_society_hb_on_subsite() || is_super_admin() ) {
		return $result;
	}
	$route = (string) $request->get_route();
	if ( preg_match( '#^/gend-cp/v1/(treasury|btcpay|admin)(/|$)#', $route ) && ! preg_match( '#^/gend-cp/v1/treasury/member#', $route ) ) {
		return new WP_Error( 'gend_hub_only', __( 'This is only available on gend.me.', 'gend-society' ), array( 'status' => 403 ) );
	}
	return $result;
}

add_action( 'init', 'gend_society_hb_cron', 999 );
/** Scheduled jobs that move money or write the shared chain run once, from the main site. */
function gend_society_hb_cron() {
	if ( ! gend_society_hb_on_subsite() || ! wp_doing_cron() ) {
		return;
	}
	$jobs = array( 'gend_cp_profit_payout', 'gend_chain_produce_block', 'gend_chain_distribute_pool', 'gend_chain_mycred_reconcile', 'gdc_wd_funding_sweep', 'gend_cp_member_yield_payout_monthly', 'gend_cp_growth_payout_distribute', 'gend_cp_marketing_pool_allocate', 'gend_cp_marketing_pool_distribute', 'gend_cp_fund_allocator_rebalance', 'gend_cp_strategy_rebalance_monthly', 'gend_cp_strategy_weekly', 'gend_cp_treasury_reconcile', 'gend_cc_affiliate_confirm_cron', 'gend_cc_affiliate_expiry_cron', 'gend_cc_affiliate_reconciler_cron', 'gend_cp_payments_nightly_sweep', 'gend_cp_pst_revenue_reconciliation', 'gend_cp_merkle_anchor_roll_up', 'gend_cp_chain_anchor_retry', 'gend_cp_chain_gas_reconcile' );
	foreach ( $jobs as $job ) {
		remove_all_actions( $job );
	}
}

/**
 * Many group actions treat manage_options as "may manage any group", which on a sub-site every owner holds.
 * May this person act on this group from this site? Its own group, or one they run.
 *
 * @param int $group_id Group.
 * @param int $user_id  User.
 * @return bool
 */
function gend_society_hb_group_allowed( $group_id, $user_id ) {
	if ( ! gend_society_hb_on_subsite() || ! $group_id || ! function_exists( 'groups_get_groupmeta' ) || is_super_admin( $user_id ) ) {
		return true;
	}
	// Someone without site-admin rights was never let in by that shortcut, so each action's own check still decides.
	if ( ! user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	if ( (int) groups_get_groupmeta( $group_id, 'gdc_site_id', true ) === get_current_blog_id() || (int) groups_get_groupmeta( $group_id, '_gend_site_blog', true ) === get_current_blog_id() ) {
		return true;
	}
	return groups_is_user_admin( $user_id, $group_id ) || groups_is_user_mod( $user_id, $group_id ) || (int) groups_get_groupmeta( $group_id, '_psoo_project_manager_id', true ) === (int) $user_id;
}

add_filter( 'rest_dispatch_request', 'gend_society_hb_rest_group', 5, 4 );
/** Route parameters are known by now, so a group named in the address counts too. */
function gend_society_hb_rest_group( $result, $request, $route, $handler ) {
	if ( null !== $result || ! gend_society_hb_on_subsite() || ! preg_match( '#^/(psoo|psoo-pm|gend-projects|gend-cp|gend-pay)(/|$)#', $route ) ) {
		return $result;
	}
	$group_id = (int) $request->get_param( 'group_id' );
	if ( ! $group_id && preg_match( '#/groups/\(\?P<id>#', $route ) ) {
		$group_id = (int) $request->get_param( 'id' );
	}
	if ( ! gend_society_hb_group_allowed( $group_id, get_current_user_id() ) ) {
		return new WP_Error( 'gend_group_only', __( 'You can only manage your own web app\'s group.', 'gend-society' ), array( 'status' => 403 ) );
	}
	return $result;
}

add_action( 'admin_init', 'gend_society_hb_ajax_group', 1 );
/** The same for AJAX actions, which name the group in the request. */
function gend_society_hb_ajax_group() {
	if ( ! wp_doing_ajax() || ! gend_society_hb_on_subsite() ) {
		return;
	}
	$group_id = isset( $_REQUEST['group_id'] ) ? (int) $_REQUEST['group_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- only narrows access; each action verifies its own nonce.
	if ( ! gend_society_hb_group_allowed( $group_id, get_current_user_id() ) ) {
		wp_send_json_error( array( 'message' => __( 'You can only manage your own web app\'s group.', 'gend-society' ) ), 403 );
	}
}

/**
 * Where a team member's feature access is kept. User meta is shared across the network, so on a sub-site each site has
 * its own list: access given on one site means nothing on another.
 *
 * @return string
 */
function gend_society_hb_access_key() {
	return gend_society_hb_on_subsite() ? 'gend_society_feature_access_' . get_current_blog_id() : 'gend_society_feature_access';
}

/** gend.me's own point types. Their balances are network-wide, so a web app never manages them. */
function gend_society_hb_network_point_types() {
	return array( 'transact', 'gend', 'profit_share', 'fund_invest', 'capital_pool', 'ydgen', 'leo_token', 'tasks' );
}

add_action( 'admin_menu', 'gend_society_hb_point_menus', 9999 );
/** Takes them off the menu. */
function gend_society_hb_point_menus() {
	if ( ! gend_society_hb_on_subsite() || is_super_admin() ) {
		return;
	}
	foreach ( gend_society_hb_network_point_types() as $type ) {
		remove_menu_page( 'mycred_' . $type );
	}
}

add_action( 'admin_init', 'gend_society_hb_point_screens', 2 );
/** And refuses their screens and any action that names one, for whoever opens the address directly. */
function gend_society_hb_point_screens() {
	if ( ! gend_society_hb_on_subsite() || is_super_admin() ) {
		return;
	}
	$types = gend_society_hb_network_point_types();
	$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- only narrows access.
	foreach ( $types as $type ) {
		if ( 0 === strpos( $page, 'mycred_' . $type ) ) {
			wp_die( esc_html__( 'This point type is managed by gend.me.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
	}
	if ( wp_doing_ajax() ) {
		foreach ( array( 'ctype', 'type', 'point_type' ) as $field ) {
			$named = isset( $_REQUEST[ $field ] ) && is_string( $_REQUEST[ $field ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- only narrows access.
			$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- only narrows access.
			if ( in_array( $named, $types, true ) && 0 === strpos( $action, 'mycred' ) ) {
				wp_send_json_error( array( 'message' => __( 'This point type is managed by gend.me.', 'gend-society' ) ), 403 );
			}
		}
	}
}

add_filter( 'woocommerce_account_menu_items', 'gend_society_hb_account_items', 999 );
/**
 * A web app's customers have the store's account: gend.me's memberships and network earnings belong on gend.me
 * (the web app's own commission program has its own tab). The wallet stays only when the web app has points of its own.
 */
function gend_society_hb_account_items( $items ) {
	if ( ! gend_society_hb_on_subsite() || ! is_array( $items ) ) {
		return $items;
	}
	unset( $items['memberships'], $items['membership'], $items['earnings'] );
	if ( ! gend_society_hb_site_point_types() ) {
		unset( $items['wallet'] );
	}
	return $items;
}

/** The web app's own point types: not gend.me's, not a group's token, and not the untouched default every site starts with. */
function gend_society_hb_site_point_types() {
	$types = function_exists( 'mycred_get_types' ) ? (array) mycred_get_types() : array();
	$own   = array();
	foreach ( $types as $key => $label ) {
		if ( in_array( $key, gend_society_hb_network_point_types(), true ) || 0 === strpos( (string) $key, 'psoo_g' ) ) {
			continue;
		}
		if ( 'mycred_default' === $key && in_array( strtolower( trim( (string) $label ) ), array( 'points', 'point' ), true ) ) {
			continue;
		}
		$own[ $key ] = $label;
	}
	return $own;
}

add_action( 'init', 'gend_society_hb_store_pages', 99 );
/** gend.me's dark thank-you page and its "Back to your memberships" button stay on gend.me. The store's own styles take over. */
function gend_society_hb_store_pages() {
	if ( gend_society_hb_on_subsite() ) {
		remove_action( 'wp_head', 'gdc_order_received_restyle', 99 );
	}
}

add_action( 'woocommerce_email', 'gend_society_hb_email_no_promo' );
/** No store email advertises the software it runs on. */
function gend_society_hb_email_no_promo( $mailer ) {
	if ( isset( $mailer->emails['WC_Email_New_Order'] ) ) {
		remove_action( 'woocommerce_email_footer', array( $mailer->emails['WC_Email_New_Order'], 'mobile_messaging' ), 9 );
	}
}

add_filter( 'woocommerce_email_footer_text', 'gend_society_hb_email_footer', 99 );
/** WooCommerce's own credit line, in either its placeholder or its linked form. */
function gend_society_hb_email_footer( $text ) {
	$text = preg_replace( '/\s*(?:&mdash;|&#8212;|—|-)?\s*Built with\s*(?:\{WooCommerce\}|<a[^>]*>\s*WooCommerce\s*<\/a>|WooCommerce)/iu', '', (string) $text );
	return trim( (string) $text );
}


// ---- A web app's own community ----
// Profiles and groups on a web app show its members' community, not gend.me's member workspace: no gend.me group card,
// gas or balances in the header, no workspace tabs in the menus. Only on branded web apps (gend_society_sb_scoped()),
// so gend.me and unbranded sub-sites are untouched. The web app's own designed header, when it has one, takes over.

/** Is this a web app's own community? */
function gend_society_hb_community() {
	return function_exists( 'gend_society_sb_scoped' ) && gend_society_sb_scoped();
}

/** The community features the member chose for this web app. */
function gend_society_hb_community_features() {
	return array_map( 'strval', (array) get_option( 'gend_society_social_features', array() ) );
}

add_filter( 'gend_society_profile_nav_items', 'gend_society_hb_profile_nav', 5, 2 );
/** A member's tabs: their profile, their posts and groups when those features are on, the program tab, and the wallet when the web app has points. */
function gend_society_hb_profile_nav( $items, $user_id = 0 ) {
	unset( $user_id );
	if ( ! gend_society_hb_community() ) {
		return $items;
	}
	$features = gend_society_hb_community_features();
	$keep     = array( 'overview' => __( 'Profile', 'gend-society' ) );
	if ( in_array( 'groups', $features, true ) ) {
		$keep['groups'] = __( 'Groups', 'gend-society' );
	}
	$keep['affiliate'] = null; // The commission program, under the name the member gave it.
	if ( gend_society_hb_site_point_types() ) {
		$keep['member-wallet'] = __( 'Wallet', 'gend-society' );
	}
	$out  = array();
	$have = array();
	foreach ( (array) $items as $it ) {
		$slug = isset( $it->slug ) ? (string) $it->slug : '';
		if ( ! array_key_exists( $slug, $keep ) || isset( $have[ $slug ] ) ) {
			continue;
		}
		if ( null !== $keep[ $slug ] ) {
			// Keep a count badge after the label.
			$badge    = preg_match( '#<span[^>]*>.*?</span>#s', (string) $it->name, $m ) ? ' ' . $m[0] : '';
			$it->name = esc_html( $keep[ $slug ] ) . $badge;
		}
		$out[]           = $it;
		$have[ $slug ] = true;
	}
	// gend.me moves a member's posts elsewhere; a web app with a feed shows them as their own tab.
	if ( in_array( 'activity', $features, true ) && function_exists( 'bp_displayed_user_domain' ) ) {
		$activity = (object) array(
			'name' => esc_html__( 'Activity', 'gend-society' ),
			'slug' => 'activity',
			'link' => trailingslashit( bp_displayed_user_domain() ) . 'activity/',
		);
		array_splice( $out, min( 1, count( $out ) ), 0, array( $activity ) );
	}
	return $out;
}

add_filter( 'gend_society_profile_nav_items', 'gend_society_hb_profile_nav_order', 11, 2 );
/** The community's tabs in their order, after any saved menu settings (which may still name gend.me's tabs). */
function gend_society_hb_profile_nav_order( $items, $user_id = 0 ) {
	unset( $user_id );
	if ( ! gend_society_hb_community() ) {
		return $items;
	}
	$order = array_flip( array( 'overview', 'activity', 'groups', 'affiliate', 'member-wallet' ) );
	$items = array_values( (array) $items );
	foreach ( $items as $i => $it ) {
		$it->_gs_hb = ( $order[ $it->slug ?? '' ] ?? 50 ) * 100 + $i;
	}
	usort(
		$items,
		static function ( $a, $b ) {
			return $a->_gs_hb - $b->_gs_hb;
		}
	);
	return $items;
}

/** The community header: cover, photo, name, handle, member type, bio, actions and tabs. Part blocks, so it takes the style guide. */
function gend_society_hb_profile_header_markup() {
	return '<div class="gs-community-profile-header"><!-- wp:gend-society/profile-cover /-->'
		. '<div class="gs-cph__id"><!-- wp:gend-society/profile-avatar /--><div class="gs-cph__text"><!-- wp:gend-society/profile-name /--><!-- wp:gend-society/profile-handle /--><!-- wp:gend-society/profile-member-type /--><!-- wp:gend-society/profile-bio /--></div><!-- wp:gend-society/profile-actions /--></div>'
		. '<!-- wp:gend-society/profile-nav /--></div>';
}

add_action( 'youzify_profile_before_header', 'gend_society_hb_profile_header', 0 );
/** On a web app with no header of its own design, the community header instead of gend.me's. */
function gend_society_hb_profile_header() {
	if ( ! gend_society_hb_community() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return;
	}
	$tpl = function_exists( 'gend_society_ps_resolve' ) ? gend_society_ps_resolve( 'gdc_profile_header', (int) bp_displayed_user_id() ) : null;
	// A default made from the classic header is gend.me's own, so it doesn't count as the web app's design.
	if ( $tpl && false === strpos( (string) $tpl->post_content, 'profile-header-classic' ) ) {
		return;
	}
	remove_action( 'youzify_profile_before_header', 'gend_society_ps_render_header', 1 );
	remove_action( 'youzify_profile_before_header', 'gend_society_render_profile_header', 1 );
	echo do_blocks( gend_society_hb_profile_header_markup() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered core-registered blocks.
}

add_action( 'bp_before_member_home_content', 'gend_society_hb_profile_overview', 0 );
/** A member's Profile tab: what they shared about themselves, instead of gend.me's cover, resume and portfolio strip. */
function gend_society_hb_profile_overview() {
	if ( ! gend_society_hb_community() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() || ! bp_is_current_component( 'overview' ) ) {
		return;
	}
	remove_action( 'bp_before_member_home_content', 'gend_society_inject_profile_page_embed', 1 );
	$rows = '';
	if ( function_exists( 'bp_has_profile' ) && bp_has_profile( array( 'user_id' => bp_displayed_user_id(), 'hide_empty_fields' => true ) ) ) {
		while ( bp_profile_groups() ) {
			bp_the_profile_group();
			while ( bp_profile_fields() ) {
				bp_the_profile_field();
				if ( bp_field_has_data() ) {
					$rows .= '<dt>' . esc_html( bp_get_the_profile_field_name() ) . '</dt><dd>' . wp_kses_post( bp_get_the_profile_field_value() ) . '</dd>';
				}
			}
		}
	}
	$own   = function_exists( 'bp_is_my_profile' ) && bp_is_my_profile();
	$empty = $own
		? '<p>' . esc_html__( 'Tell members a little about yourself.', 'gend-society' ) . ' <a href="' . esc_url( trailingslashit( bp_displayed_user_domain() ) . 'profile/edit/' ) . '">' . esc_html__( 'Edit your profile', 'gend-society' ) . '</a></p>'
		: '<p>' . esc_html__( 'This member hasn\'t shared anything yet.', 'gend-society' ) . '</p>';
	echo '<section class="gs-community-about"><h2>' . esc_html__( 'About', 'gend-society' ) . '</h2>' . ( '' !== $rows ? '<dl>' . $rows . '</dl>' : $empty ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
}

/** A group's tabs on a web app: its feed and members, plus the tabs the web app added itself. */
function gend_society_hb_group_tabs() {
	$keep   = array( 'activity', 'members-hub' );
	$config = get_option( 'gdc_gs_nav_config', array() );
	foreach ( (array) ( $config['_default']['custom'] ?? array() ) as $tab ) {
		if ( ! empty( $tab['slug'] ) ) {
			$keep[] = sanitize_key( (string) $tab['slug'] );
		}
	}
	return array_values( array_unique( $keep ) );
}

add_filter( 'psoo_bridge_header_nav_whitelist', 'gend_society_hb_group_nav', 120, 2 );
/** gend.me's workspace tabs (business plan, projects, payments, hosting, gas, AI...) stay on gend.me. */
function gend_society_hb_group_nav( $list, $group = null ) {
	unset( $group );
	return gend_society_hb_community() ? gend_society_hb_group_tabs() : $list;
}

add_filter( 'gdc_gs_nav_manageable_slugs', 'gend_society_hb_group_manageable', 120 );
/** The Design Studio's group menu lists the same tabs. */
function gend_society_hb_group_manageable( $slugs ) {
	return gend_society_hb_community() ? array_values( array_intersect( (array) $slugs, gend_society_hb_group_tabs() ) ) : $slugs;
}

add_filter( 'bp_groups_default_extension', 'gend_society_hb_group_landing', 20 );
/** A web app's groups open on their feed, not gend.me's business plan. */
function gend_society_hb_group_landing( $slug ) {
	return gend_society_hb_community() ? 'activity' : $slug;
}

add_filter( 'render_block', 'gend_society_hb_group_cards', 10, 2 );
/** gend.me's connected-app and project console cards in a group header. */
function gend_society_hb_group_cards( $html, $block ) {
	if ( in_array( $block['blockName'] ?? '', array( 'gdc-blocks/group-connected-app-card', 'gdc-blocks/group-pm-console-card' ), true ) && gend_society_hb_community() ) {
		return '';
	}
	return $html;
}

add_filter( 'psoo_dash_all', 'gend_society_hb_dashboards' );
/** gend.me's consulting dashboards (Consult, Developers), seeded on every site, aren't a web app's: its own dashboards stay. */
function gend_society_hb_dashboards( $all ) {
	if ( gend_society_hb_community() ) {
		unset( $all['consult'], $all['developers'] );
	}
	return $all;
}


add_action( 'init', 'gend_society_hb_community_unhook', 20 );
/** gend.me's member board (My Projects, Memberships) around the profile's Groups tab stays on gend.me: a web app lists the member's groups. */
function gend_society_hb_community_unhook() {
	if ( ! gend_society_hb_community() ) {
		return;
	}
	remove_action( 'bp_before_member_groups_content', 'gend_society_member_groups_tabs_open', 1 );
	remove_action( 'bp_after_member_groups_content', 'gend_society_member_groups_tabs_close', 99 );
}

add_action( 'bp_setup_nav', 'gend_society_hb_activity_groups_label', 1001 );
/** gend.me calls a member's group posts "Integration Hubs"; in a web app's community they're just groups. */
function gend_society_hb_activity_groups_label() {
	if ( ! gend_society_hb_community() || ! function_exists( 'buddypress' ) || ! bp_is_active( 'activity' ) || ! bp_is_active( 'groups' ) ) {
		return;
	}
	$nav = buddypress()->members->nav ?? null;
	if ( $nav && method_exists( $nav, 'edit_nav' ) ) {
		$nav->edit_nav( array( 'name' => __( 'Groups', 'gend-society' ) ), function_exists( 'bp_get_groups_slug' ) ? bp_get_groups_slug() : 'groups', bp_get_activity_slug() );
	}
}

add_action( 'template_redirect', 'gend_society_hb_social_accounts', 5 );
/** A member's social accounts are their gend.me ones: the profile's Social panel opens on gend.me. */
function gend_society_hb_social_accounts() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks where to send the member.
	if ( empty( $_GET['bm_tab'] ) || ! gend_society_hb_community() || ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
		return;
	}
	$user = wp_get_current_user();
	$hub  = trailingslashit( get_site_url( get_main_site_id() ) ) . 'members/' . rawurlencode( $user->user_nicename ) . '/overview/';
	wp_safe_redirect( add_query_arg( 'bm_tab', sanitize_key( wp_unslash( $_GET['bm_tab'] ) ), $hub ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	exit;
}

add_action( 'wp_enqueue_scripts', 'gend_society_hb_community_nav', 100 );
/** Profile tabs open in place: only the tab's content changes, the header and the page stay. */
function gend_society_hb_community_nav() {
	if ( ! gend_society_hb_community() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return;
	}
	wp_enqueue_script( 'gend-society-community-nav', GEND_SOCIETY_URL . 'assets/community-nav.js', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/community-nav.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

/** The invite panel on a web app speaks for the web app, not gend.me. Only while it renders or sends. */
function gend_society_hb_invite_words( $translation, $text, $domain ) {
	if ( 'gend-society' !== $domain || false === strpos( $translation, 'gend.me' ) ) {
		return $translation;
	}
	$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return str_replace( array( 'https://gend.me', 'The gend.me team', 'gend.me' ), array( untrailingslashit( home_url( '/' ) ), 'The ' . $name . ' team', $name ), $translation );
}

add_action( 'wp_ajax_gend_society_invite_frame', 'gend_society_hb_invite_frame', 1 );
add_action( 'wp_ajax_gend_society_invite_panel_fragment', 'gend_society_hb_invite_frame', 1 );
/** Words and colours for the invite panel on a web app: the brand's tokens and stylesheet in its frame. */
function gend_society_hb_invite_frame() {
	if ( ! gend_society_hb_community() ) {
		return;
	}
	add_filter( 'gettext', 'gend_society_hb_invite_words', 10, 3 );
	wp_enqueue_style( 'gend-society-brand-dashboards', GEND_SOCIETY_URL . 'assets/brand-dashboards.css', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/brand-dashboards.css' ) );
	if ( function_exists( 'wp_get_global_stylesheet' ) ) {
		wp_register_style( 'gend-society-invite-tokens', false, array(), GEND_SOCIETY_VERSION );
		wp_add_inline_style( 'gend-society-invite-tokens', wp_get_global_stylesheet( array( 'variables' ) ) );
		wp_enqueue_style( 'gend-society-invite-tokens' );
	}
	// The frame's body has no theme class: mark it, so the brand rules apply.
	add_action( 'admin_print_footer_scripts', 'gend_society_hb_invite_body_class', 1 );
	add_action( 'wp_print_footer_scripts', 'gend_society_hb_invite_body_class', 1 );
}

/** Adds the brand class to the invite frame's body. */
function gend_society_hb_invite_body_class() {
	echo '<script>document.body.classList.add("gs-brand-site");</script>';
}

add_action( 'init', 'gend_society_hb_invite_send_words' );
/** Invites sent from a web app carry its name too. */
function gend_society_hb_invite_send_words() {
	// phpcs:ignore WordPress.Security.NonceVerification -- only chooses wording.
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( wp_doing_ajax() && 0 === strpos( $action, 'gend_society_invite' ) && gend_society_hb_community() ) {
		add_filter( 'gettext', 'gend_society_hb_invite_words', 10, 3 );
	}
}

add_action( 'wp_enqueue_scripts', 'gend_society_hb_no_backdrop', 100 );
/** gend.me's moving profile backdrop, which also turns light panels into dark glass, stays on gend.me. */
function gend_society_hb_no_backdrop() {
	if ( gend_society_hb_community() ) {
		wp_dequeue_style( 'gend-society-video-backdrop' );
		wp_dequeue_script( 'gend-society-video-backdrop' );
	}
}
