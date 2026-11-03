<?php
/**
 * Chat tools: what the gend.me chat can read and change on this site.
 *
 * One list of tools for the web, mobile and desktop chats. gend.me decides who is asking: it signs each request
 * (the same Ed25519 rail as gs/v1/user/credentials) with the person, their access to this site and their plan's areas,
 * and a tool only runs when all of these agree:
 *   1. the plan includes the tool's area;
 *   2. the person may use it: a site owner/admin or super admin may use everything, anyone else only what their
 *      Feature Access lists (the same user meta the wp-admin menu reads);
 *   3. the tool's own capability, and whatever the route it calls checks, as that person's account.
 *
 * Tools marked 'confirm' answer confirm_required until gend.me sends confirmed: only the member's own Confirm click
 * on gend.me sets that, never anything the model says or sends.
 *
 * Not tools, on purpose: DGEN withdrawal approvals and payouts, DGEN balances and rates, deleting a point type that
 * holds value, creating roles or granting admin, email server (SMTP) settings. Those stay with a person in wp-admin.
 *
 * Tool output goes to an AI model, so people appear by id and masked address, and order details leave out billing
 * and shipping. Content in the output is data, not instructions from the member.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GEND_SOCIETY_CHAT_TOOLS_MAX_OUTPUT = 40000;

// admin-ajax, not REST: most features load their code and handlers only in wp-admin, and the tools call those handlers.
add_action( 'wp_ajax_nopriv_gs_chat_tools', 'gend_society_chat_tools_entry' );
add_action( 'wp_ajax_gs_chat_tools', 'gend_society_chat_tools_entry' );

/**
 * admin-ajax.php?action=gs_chat_tools: a signed request from gend.me, op list or run.
 */
function gend_society_chat_tools_entry(): void {
	$request = new WP_REST_Request( 'POST' );
	$request->set_body( (string) file_get_contents( 'php://input' ) );
	$request->set_header( 'x_gend_signature', isset( $_SERVER['HTTP_X_GEND_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GEND_SIGNATURE'] ) ) : '' );
	$payload = gend_society_chat_tools_verify( $request );
	if ( is_wp_error( $payload ) ) {
		wp_send_json( array( 'error' => $payload->get_error_message() ), 401 );
	}
	$out = 'list' === ( $payload['op'] ?? '' ) ? gend_society_chat_tools_list_for( $payload ) : gend_society_chat_tools_run_for( $payload );
	wp_set_current_user( 0 );
	wp_send_json( $out, 200 );
}

/** Whether this site is on the gend.me network itself, sharing its users and pairing. */
function gend_society_chat_tools_on_hub(): bool {
	return is_multisite() && function_exists( 'gend_society_runtime_mode' ) && 'hub' === gend_society_runtime_mode();
}

/**
 * Verifies gend.me's signature, that it was issued for this site, and only once.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error The signed payload.
 */
function gend_society_chat_tools_verify( WP_REST_Request $request ) {
	if ( ! function_exists( 'gend_society_agent_verify_signed_request' ) ) {
		return new WP_Error( 'gs_chat_tools_unavailable', __( 'Signed requests are not available on this site.', 'gend-society' ), array( 'status' => 501 ) );
	}
	// Sites on the gend.me network share the main site's pairing.
	$on_hub_network = gend_society_chat_tools_on_hub();
	// Read before the filter goes on: get_blog_option() reads through get_option(), which would call the filter again.
	$key         = $on_hub_network && ! is_main_site() ? (string) get_blog_option( get_main_site_id(), 'gend_society_gend_pubkey', '' ) : '';
	$network_key = static function () use ( $key ) {
		return $key;
	};
	if ( $on_hub_network && ! is_main_site() ) {
		add_filter( 'pre_option_gend_society_gend_pubkey', $network_key );
	}
	$payload = gend_society_agent_verify_signed_request( $request );
	if ( $on_hub_network && ! is_main_site() ) {
		remove_filter( 'pre_option_gend_society_gend_pubkey', $network_key );
	}
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}
	$norm = static function ( $url ) {
		return untrailingslashit( strtolower( set_url_scheme( (string) $url, 'https' ) ) );
	};
	if ( empty( $payload['aud'] ) || $norm( $payload['aud'] ) !== $norm( home_url() ) ) {
		return new WP_Error( 'gs_chat_tools_wrong_site', __( 'This request was not issued for this site.', 'gend-society' ), array( 'status' => 401 ) );
	}
	$jti = isset( $payload['jti'] ) ? sanitize_key( (string) $payload['jti'] ) : '';
	if ( strlen( $jti ) < 16 ) {
		return new WP_Error( 'gs_chat_tools_bad_request', __( 'Request id required.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$seen = 'gs_chat_tools_jti_' . $jti;
	if ( get_transient( $seen ) ) {
		return new WP_Error( 'gs_chat_tools_replay', __( 'This request was already used.', 'gend-society' ), array( 'status' => 401 ) );
	}
	set_transient( $seen, 1, 10 * MINUTE_IN_SECONDS );
	return $payload;
}

/**
 * The local account a signed request acts as, or an error when the person has none here.
 *
 * Owners and admins whose gend.me account has no role on this site act as the site's first administrator; the audit
 * entry still names the gend.me person.
 *
 * @param array $payload Signed payload.
 * @return WP_User|WP_Error
 */
function gend_society_chat_tools_actor( array $payload ) {
	$hub_user_id = isset( $payload['hub_user_id'] ) ? (int) $payload['hub_user_id'] : 0;
	$full        = isset( $payload['access'] ) && 'full' === $payload['access'];
	if ( $hub_user_id <= 0 ) {
		return new WP_Error( 'gs_chat_tools_bad_user', __( 'Person required.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$user = null;
	if ( gend_society_chat_tools_on_hub() ) {
		$u = get_userdata( $hub_user_id );
		if ( $u && ( is_super_admin( $u->ID ) || ! is_multisite() || is_user_member_of_blog( $u->ID, get_current_blog_id() ) ) ) {
			$user = $u;
		}
	} elseif ( defined( 'GEND_SOCIETY_HUB_USER_META' ) ) {
		$ids = get_users( array( 'meta_key' => GEND_SOCIETY_HUB_USER_META, 'meta_value' => (string) $hub_user_id, 'fields' => 'ID', 'number' => 2 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( 1 === count( $ids ) ) {
			$user = get_userdata( (int) $ids[0] );
		}
	}
	if ( $full && ( ! $user || ! user_can( $user, 'manage_options' ) ) ) {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$user   = $admins ? $admins[0] : $user;
	}
	if ( ! $user ) {
		return new WP_Error( 'gs_chat_tools_no_account', __( 'You have no account on this site. Sign in to it once with "Sign in with gend.me", or ask the owner to add you.', 'gend-society' ), array( 'status' => 403 ) );
	}
	return $user;
}

/**
 * Whether the signed plan areas include a tool's area, for example 'store.sales_team'.
 *
 * @param array  $payload Signed payload. areas: 'all' on the main site for super admins, otherwise the plan's list.
 * @param string $area    Tool area.
 */
function gend_society_chat_tools_plan_allows( array $payload, string $area ): bool {
	$areas = $payload['areas'] ?? array();
	if ( 'all' === $areas ) {
		return true;
	}
	$areas = array_map( 'strval', (array) $areas );
	return in_array( $area, $areas, true ) || in_array( strtok( $area, '.' ), $areas, true );
}

/**
 * Whether the person may use a tool on this site, leaving aside the tool's own capability.
 *
 * @param array   $payload Signed payload.
 * @param array   $tool    Tool.
 * @param WP_User $actor   Local account.
 */
function gend_society_chat_tools_person_allows( array $payload, array $tool, WP_User $actor ): bool {
	if ( ( isset( $payload['access'] ) && 'full' === $payload['access'] ) || is_super_admin( $actor->ID ) || user_can( $actor, 'manage_options' ) ) {
		return true;
	}
	$access = get_user_meta( $actor->ID, 'gend_society_feature_access', true );
	return is_array( $access ) && (bool) array_intersect( (array) $tool['pages'], $access );
}

/** Masks an address for tool output: j***@example.com. */
function gend_society_chat_tools_mask_email( $email ): string {
	$email = (string) $email;
	$at    = strpos( $email, '@' );
	return false === $at ? '' : substr( $email, 0, 1 ) . '***' . substr( $email, $at );
}

/**
 * Calls a REST route on this site as the acting person, so its own permission check decides.
 *
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $params Parameters.
 * @return mixed Response data, or ['error' => message].
 */
function gend_society_chat_tools_rest( string $method, string $route, array $params = array() ) {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		$err = $res->as_error();
		return array( 'error' => $err->get_error_message() );
	}
	$data = $res->get_data();
	return is_object( $data ) ? (array) $data : $data;
}

/** Keeps only the listed keys of each row. */
function gend_society_chat_tools_pick( $rows, array $keys ): array {
	if ( isset( $rows['error'] ) ) {
		return $rows;
	}
	return array_map(
		static function ( $row ) use ( $keys ) {
			return array_intersect_key( (array) $row, array_flip( $keys ) );
		},
		array_values( (array) $rows )
	);
}

/** A list of posts of one type, as id, title, status and date. */
function gend_society_chat_tools_posts( string $type, array $a, array $status = array( 'publish', 'draft', 'pending', 'private' ) ): array {
	$posts = get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => $status,
			'posts_per_page' => min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 50 ),
			's'              => isset( $a['search'] ) ? sanitize_text_field( (string) $a['search'] ) : '',
		)
	);
	return array_map(
		static function ( $p ) {
			return array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'status' => $p->post_status, 'date' => $p->post_date );
		},
		$posts
	);
}

/**
 * Order details for the model: what was bought and its state, never billing or shipping details.
 *
 * @param array $o Order from wc/v3.
 */
function gend_society_chat_tools_order( array $o ): array {
	$out                = array_intersect_key( $o, array_flip( array( 'id', 'status', 'currency', 'total', 'total_tax', 'discount_total', 'date_created', 'date_paid', 'date_completed', 'customer_id', 'payment_method_title' ) ) );
	$out['items']       = array_map(
		static function ( $i ) {
			return array_intersect_key( (array) $i, array_flip( array( 'product_id', 'variation_id', 'name', 'quantity', 'total' ) ) );
		},
		(array) ( $o['line_items'] ?? array() )
	);
	$out['refunds']     = count( (array) ( $o['refunds'] ?? array() ) );
	$billing            = (array) ( $o['billing'] ?? array() );
	$out['buyer_email'] = gend_society_chat_tools_mask_email( $billing['email'] ?? '' );
	return $out;
}

/**
 * Every tool. name, description, parameters, area (plan), pages (Feature Access), risk (read|write|confirm),
 * cap (the tool's own capability, for tools that call plain functions), run.
 *
 * @return array<string, array>
 */
function gend_society_chat_tools(): array {
	static $tools = null;
	if ( null !== $tools ) {
		return $tools;
	}
	$search = array( 'search' => array( 'type' => 'string' ), 'per_page' => array( 'type' => 'number', 'description' => 'Default 20, max 50' ) );
	$id     = array( 'id' => array( 'type' => 'number' ) );
	$theme  = array( 'site-editor.php', 'gs-design-studio' );
	$cc     = array( 'content-campaigns', 'blog-manager' );
	$talk   = array( 'talk-flows' );
	$store  = array( 'gdc-store-settings' );
	$sales  = array( 'st_sales_team' );
	$proj   = array( 'psoo-projects' );
	$social = array( 'gdc-social-network-settings' );
	$bank   = array( 'gdc-reward-point-bank' );

	$list = array(
		// App: theme builder.
		array( 'app_brand_kit', 'Read the brand kit: voice, style and cast.', array(), 'app.theme_builder', $theme, 'read', '', static function () {
			return gend_society_chat_tools_rest( 'GET', '/gend-society/v1/brand-kit' );
		} ),
		array( 'app_sidebars', 'Read the sidebar settings.', array(), 'app.theme_builder', $theme, 'read', '', static function () {
			return gend_society_chat_tools_rest( 'GET', '/gend-society/v1/sidebars' );
		} ),
		array( 'app_template_assignments', 'Read which templates are assigned to product taxonomies and products.', array(), 'app.theme_builder', $theme, 'read', '', static function () {
			return array(
				'taxonomy' => gend_society_chat_tools_rest( 'GET', '/gend-society/v1/studio/tax-templates' ),
				'product'  => gend_society_chat_tools_rest( 'GET', '/gend-society/v1/studio/product-templates' ),
			);
		} ),
		array( 'app_social_pages', 'Read the profile and group page settings, or the activity feed settings.', array( 'scope' => array( 'type' => 'string', 'enum' => array( 'profiles', 'groups', 'activity' ) ) ), 'app.theme_builder', $theme, 'read', '', static function ( $a ) {
			$scope = in_array( $a['scope'] ?? '', array( 'groups', 'activity' ), true ) ? $a['scope'] : 'profiles';
			return gend_society_chat_tools_rest( 'GET', '/gend-society/v1/social-pages/' . $scope );
		} ),

		// App: Content Campaigns.
		array( 'campaigns_analytics', 'Traffic overview for the last 30 days.', array(), 'app.content_campaigns', $cc, 'read', 'manage_options', static function () {
			return class_exists( 'BM_Analytics_Dashboard' ) ? BM_Analytics_Dashboard::get_payload() : array( 'error' => 'Analytics is not available on this site.' );
		} ),
		array( 'campaigns_media_storage', 'Media library storage and optimisation figures.', array(), 'app.content_campaigns', $cc, 'read', 'upload_files', static function () {
			return class_exists( 'GMO_Library_Dashboard' ) ? GMO_Library_Dashboard::get_storage_stats() : array( 'error' => 'The media optimizer is not available on this site.' );
		} ),
		array( 'campaigns_social_calendar', 'Scheduled social posts for the person, between two dates. Both dates are required.', array( 'view_start' => array( 'type' => 'string', 'description' => 'ISO-8601 UTC, for example 2026-11-01T00:00:00Z' ), 'view_end' => array( 'type' => 'string', 'description' => 'ISO-8601 UTC' ), 'platform' => array( 'type' => 'string' ) ), 'app.content_campaigns', $cc, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_rest( 'GET', '/gend-cc/v1/social/calendar', array_intersect_key( $a, array_flip( array( 'view_start', 'view_end', 'platform' ) ) ) );
		} ),
		array( 'campaigns_ads_list', 'Ad campaigns stored on this web app, with how each performed.', array(), 'app.content_campaigns', $cc, 'read', 'manage_options', static function () {
			if ( ! class_exists( 'BM_Ads_DB' ) ) {
				return array( 'error' => 'Ads are not available on this site.' );
			}
			return array_map(
				static function ( $c ) {
					return array( 'campaign' => $c, 'performance' => BM_Ads_DB::variant_performance( $c->id ) );
				},
				(array) BM_Ads_DB::list_campaigns( '', 0 )
			);
		} ),
		array( 'campaigns_ads_performance', 'Performance snapshot of a connected ad account.', array( 'platform' => array( 'type' => 'string', 'description' => 'Required, for example facebook or google' ) ), 'app.content_campaigns', $cc, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_rest( 'GET', '/gend-cc/v1/ads/snapshot', array( 'platform' => sanitize_key( (string) ( $a['platform'] ?? '' ) ) ) );
		} ),

		// App: Talk Flows.
		array( 'talk_email_lists', 'Email lists.', array(), 'app.talk_flows', $talk, 'read', '', static function () {
			return gend_society_chat_tools_rest( 'GET', '/em/v1/email-lists' );
		} ),
		array( 'talk_forms', 'Forms.', $search, 'app.talk_flows', $talk, 'read', 'manage_options', static function ( $a ) {
			return gend_society_chat_tools_posts( 'basic_form', $a );
		} ),
		array( 'talk_form_submissions', 'Recent form and chatflow submissions: when and which form, without the answers.', $search, 'app.talk_flows', $talk, 'read', 'manage_options', static function ( $a ) {
			return gend_society_chat_tools_posts( 'chat_submission', $a, array( 'publish', 'draft' ) );
		} ),
		array( 'talk_chatflows', 'Chatflows.', array(), 'app.talk_flows', $talk, 'read', '', static function () {
			return gend_society_chat_tools_rest( 'GET', '/em/v1/chatflows' );
		} ),
		array( 'talk_chatflow', 'One chatflow with its questions.', $id, 'app.talk_flows', $talk, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_clean_flow( gend_society_chat_tools_rest( 'GET', '/em/v1/chatflows/' . (int) ( $a['id'] ?? 0 ) ) );
		} ),
		array( 'talk_applications', 'Application postings.', array(), 'app.talk_flows', $talk, 'read', 'manage_options', static function () {
			return class_exists( 'EM_Postings' ) ? EM_Postings::get_postings() : array( 'error' => 'Applications are not available on this site.' );
		} ),
		array( 'talk_application_analytics', 'How one application posting is performing.', $id, 'app.talk_flows', $talk, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_rest( 'GET', '/em/v1/postings/' . (int) ( $a['id'] ?? 0 ) . '/analytics' );
		} ),

		// Store: Store Manager.
		array( 'store_products', 'Products with prices and stock.', $search, 'store.store_management', $store, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_pick(
				gend_society_chat_tools_rest( 'GET', '/wc/v3/products', array( 'search' => (string) ( $a['search'] ?? '' ), 'per_page' => min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 50 ) ) ),
				array( 'id', 'name', 'type', 'status', 'sku', 'price', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'total_sales', 'categories' )
			);
		} ),
		array( 'store_product_variations', 'A variable product\'s variations with prices and stock.', $id, 'store.store_management', $store, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_pick(
				gend_society_chat_tools_rest( 'GET', '/wc/v3/products/' . (int) ( $a['id'] ?? 0 ) . '/variations', array( 'per_page' => 50 ) ),
				array( 'id', 'sku', 'status', 'price', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'attributes' )
			);
		} ),
		array( 'store_categories', 'Product categories.', $search, 'store.store_management', $store, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_pick(
				gend_society_chat_tools_rest( 'GET', '/wc/v3/products/categories', array( 'search' => (string) ( $a['search'] ?? '' ), 'per_page' => 50 ) ),
				array( 'id', 'name', 'slug', 'parent', 'count' )
			);
		} ),
		array( 'store_orders', 'Recent orders, without billing or shipping details.', array( 'status' => array( 'type' => 'string' ), 'per_page' => $search['per_page'] ), 'store.store_management', $store, 'read', '', static function ( $a ) {
			$p = array( 'per_page' => min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 50 ) );
			if ( ! empty( $a['status'] ) ) {
				$p['status'] = sanitize_key( (string) $a['status'] );
			}
			$rows = gend_society_chat_tools_rest( 'GET', '/wc/v3/orders', $p );
			return isset( $rows['error'] ) ? $rows : array_map( 'gend_society_chat_tools_order', array_map( 'gend_society_chat_tools_to_array', (array) $rows ) );
		} ),
		array( 'store_order', 'One order, without billing or shipping details.', $id, 'store.store_management', $store, 'read', '', static function ( $a ) {
			$o = gend_society_chat_tools_rest( 'GET', '/wc/v3/orders/' . (int) ( $a['id'] ?? 0 ) );
			return isset( $o['error'] ) ? $o : gend_society_chat_tools_order( (array) $o );
		} ),
		array( 'store_emails', 'The store\'s customer emails and whether each is on.', array(), 'store.store_management', $store, 'read', 'manage_woocommerce', static function () {
			if ( ! function_exists( 'WC' ) ) {
				return array( 'error' => 'The store is not available on this site.' );
			}
			$out = array();
			foreach ( WC()->mailer()->get_emails() as $e ) {
				$out[] = array( 'id' => $e->id, 'title' => $e->get_title(), 'enabled' => $e->is_enabled(), 'to_customer' => $e->is_customer_email(), 'subject' => $e->get_option( 'subject' ) );
			}
			return $out;
		} ),

		// Store: Sales Team.
		array( 'sales_proposals', 'Proposals the person can edit, with their stage.', $search, 'store.sales_team', $sales, 'read', '', static function ( $a ) {
			$out = array();
			foreach ( gend_society_chat_tools_posts( 'st_proposal', $a ) as $row ) {
				if ( current_user_can( 'edit_post', $row['id'] ) ) {
					$row['stage'] = (string) get_post_meta( $row['id'], '_stp_assigned_stage', true ) ?: (string) get_post_meta( $row['id'], '_stp_template_stage', true );
					$out[]        = $row;
				}
			}
			return $out;
		} ),

		// Store: Projects. Each route checks the person against the project or group.
		array( 'projects_list', 'The person\'s projects.', array(), 'store.project_services', $proj, 'read', '', static function () {
			return gend_society_chat_tools_rest( 'GET', '/psoo-pm/v1/me/project-cards' );
		} ),
		array( 'projects_project', 'One project.', $id, 'store.project_services', $proj, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_rest( 'GET', '/psoo-pm/v1/projects/' . (int) ( $a['id'] ?? 0 ) );
		} ),
		array( 'projects_tasks', 'A project\'s tasks.', $id, 'store.project_services', $proj, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_rest( 'GET', '/psoo-pm/v1/projects/' . (int) ( $a['id'] ?? 0 ) . '/tasks' );
		} ),
		array( 'projects_milestones', 'A project\'s milestones.', $id, 'store.project_services', $proj, 'read', '', static function ( $a ) {
			return gend_society_chat_tools_rest( 'GET', '/psoo-pm/v1/projects/' . (int) ( $a['id'] ?? 0 ) . '/milestones' );
		} ),

		// Social: member management.
		array( 'members_search', 'Find members of this web app. Addresses are masked.', $search, 'social.social_profiles', $social, 'read', 'list_users', static function ( $a ) {
			$term  = isset( $a['search'] ) ? sanitize_text_field( (string) $a['search'] ) : '';
			$users = get_users(
				array(
					'blog_id'        => get_current_blog_id(),
					'number'         => min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 50 ),
					'search'         => '' !== $term ? '*' . $term . '*' : '',
					'search_columns' => array( 'user_login', 'display_name' ),
				)
			);
			return array_map(
				static function ( $u ) {
					return array( 'id' => $u->ID, 'name' => $u->display_name, 'roles' => array_values( $u->roles ), 'email' => gend_society_chat_tools_mask_email( $u->user_email ), 'registered' => $u->user_registered );
				},
				$users
			);
		} ),
		array( 'members_types', 'Member types.', array(), 'social.social_profiles', $social, 'read', 'list_users', static function () {
			gend_society_chat_tools_load_members();
			return function_exists( 'mm_get_member_types_items' ) ? mm_get_member_types_items() : array( 'error' => 'Member types are not available on this site.' );
		} ),
		array( 'members_profile_fields', 'Profile fields and groups.', array(), 'social.social_profiles', $social, 'read', 'bp_moderate', static function () {
			gend_society_chat_tools_load_members();
			return function_exists( 'mm_pf_data' ) ? mm_pf_data() : array( 'error' => 'Profile fields are not available on this site.' );
		} ),

		// Social: Bank.
		array( 'bank_point_types', 'Point types.', array(), 'social.point_bank', $bank, 'read', 'manage_options', static function () {
			return function_exists( 'rp_point_bank_get_types_data' ) ? rp_point_bank_get_types_data() : array( 'error' => 'The Point Bank is not available on this site.' );
		} ),
		array( 'bank_point_type', 'One point type\'s settings and figures.', array( 'key' => array( 'type' => 'string' ) ), 'social.point_bank', $bank, 'read', 'manage_options', static function ( $a ) {
			if ( ! function_exists( 'rp_point_bank_build_type_summary' ) ) {
				return array( 'error' => 'The Point Bank is not available on this site.' );
			}
			$s = (array) rp_point_bank_build_type_summary( sanitize_key( (string) ( $a['key'] ?? '' ) ) );
			// The withdrawal queue holds names and payment addresses: people read it in wp-admin.
			if ( isset( $s['withdraw']['chain'] ) ) {
				unset( $s['withdraw']['chain']['queue'] );
			}
			return $s;
		} ),
		array( 'bank_circulation', 'Points in circulation by type.', array(), 'social.point_bank', $bank, 'read', 'manage_options', static function () {
			return function_exists( 'mycred_get_circulation_data' ) ? mycred_get_circulation_data() : array( 'error' => 'Point statistics are not on for this site.' );
		} ),
		array( 'bank_badges', 'Badges.', $search, 'social.point_bank', $bank, 'read', 'manage_options', static function ( $a ) {
			return gend_society_chat_tools_posts( 'mycred_badge', $a );
		} ),
		array( 'bank_ranks', 'Ranks.', $search, 'social.point_bank', $bank, 'read', 'manage_options', static function ( $a ) {
			return gend_society_chat_tools_posts( 'mycred_rank', $a );
		} ),
		array( 'bank_notifications', 'Automatic member email notices.', $search, 'social.point_bank', $bank, 'read', 'manage_options', static function ( $a ) {
			return gend_society_chat_tools_posts( 'mycred_email_notice', $a );
		} ),
	);

	$tools = array();
	foreach ( $list as $t ) {
		$tools[ $t[0] ] = array(
			'name'        => $t[0],
			'description' => $t[1],
			'parameters'  => $t[2],
			'area'        => $t[3],
			'pages'       => $t[4],
			'risk'        => $t[5],
			'cap'         => $t[6],
			'run'         => $t[7],
		);
	}
	/**
	 * Filters the chat tools. Each tool also passes the plan, person and capability checks.
	 *
	 * @param array $tools Tools by name.
	 */
	$tools = (array) apply_filters( 'gend_society_chat_tools', $tools );
	foreach ( $tools as $n => $t ) {
		// Tools built from the list above run with one argument.
		$tools[ $n ]['risk_for'] = $t['risk_for'] ?? null;
	}
	return $tools;
}

/** Member management only loads in wp-admin, so its helpers load here when a tool needs them. */
function gend_society_chat_tools_load_members(): void {
	if ( ! function_exists( 'mm_pf_data' ) && defined( 'SOCIAL_NETWORK_PLUGIN_DIR' ) && file_exists( SOCIAL_NETWORK_PLUGIN_DIR . 'inc/member-management-helpers.php' ) ) {
		require_once SOCIAL_NETWORK_PLUGIN_DIR . 'inc/member-management-helpers.php';
	}
}

/** Decodes objects from REST data into arrays. */
function gend_society_chat_tools_to_array( $row ): array {
	return (array) $row;
}

/**
 * The tools for a verified request, without their code.
 *
 * @param array $payload Signed payload.
 */
function gend_society_chat_tools_list_for( array $payload ): array {
	$actor = gend_society_chat_tools_actor( $payload );
	if ( is_wp_error( $actor ) ) {
		return array( 'tools' => array(), 'note' => $actor->get_error_message() );
	}
	wp_set_current_user( $actor->ID );
	$out = array();
	foreach ( gend_society_chat_tools() as $t ) {
		if ( gend_society_chat_tools_plan_allows( $payload, $t['area'] )
			&& gend_society_chat_tools_person_allows( $payload, $t, $actor )
			&& ( '' === $t['cap'] || current_user_can( $t['cap'] ) ) ) {
			$out[] = array_intersect_key( $t, array_flip( array( 'name', 'description', 'parameters', 'risk' ) ) );
		}
	}
	return array( 'tools' => $out );
}

/**
 * Runs one tool for a verified request. Always answers an array: result, confirm_required or error.
 *
 * @param array $payload Signed payload: tool, args, confirmed, hub_user_id, access, areas.
 */
function gend_society_chat_tools_run_for( array $payload ): array {
	$name = isset( $payload['tool'] ) ? (string) $payload['tool'] : '';
	$tool = gend_society_chat_tools()[ $name ] ?? null;
	if ( ! $tool ) {
		return array( 'error' => 'There is no such tool on this web app.' );
	}
	if ( ! gend_society_chat_tools_plan_allows( $payload, $tool['area'] ) ) {
		return array( 'error' => 'This web app\'s plan does not include that feature.' );
	}
	$actor = gend_society_chat_tools_actor( $payload );
	if ( is_wp_error( $actor ) ) {
		return array( 'error' => $actor->get_error_message() );
	}
	wp_set_current_user( $actor->ID );
	if ( ! gend_society_chat_tools_person_allows( $payload, $tool, $actor ) || ( '' !== $tool['cap'] && ! current_user_can( $tool['cap'] ) ) ) {
		return array( 'error' => 'Your account does not have access to that feature.' );
	}
	$args = isset( $payload['args'] ) && is_array( $payload['args'] ) ? $payload['args'] : array();
	$risk = ! empty( $tool['risk_for'] ) ? (string) call_user_func( $tool['risk_for'], $args ) : $tool['risk'];
	if ( 'confirm' === $risk && empty( $payload['confirmed'] ) ) {
		return array( 'confirm_required' => true, 'tool' => $name );
	}
	$context = array( 'group_id' => (int) ( $payload['group_id'] ?? 0 ) );
	try {
		$result = call_user_func( $tool['run'], $args, $context );
	} catch ( \Throwable $e ) {
		return array( 'error' => 'That could not be completed on this web app.' );
	}
	if ( 'read' !== $risk && class_exists( 'Gend_Compliance' ) ) {
		Gend_Compliance::audit( 'chat_tool', 'tool', 0, array( 'tool' => $name, 'hub_user_id' => (int) ( $payload['hub_user_id'] ?? 0 ) ) );
	}
	$json = wp_json_encode( $result );
	if ( false === $json ) {
		return array( 'error' => 'That result could not be read.' );
	}
	if ( strlen( $json ) > GEND_SOCIETY_CHAT_TOOLS_MAX_OUTPUT ) {
		return array( 'truncated' => substr( $json, 0, GEND_SOCIETY_CHAT_TOOLS_MAX_OUTPUT ) . '…[truncated: ask for fewer rows]' );
	}
	return array( 'result' => $result );
}
