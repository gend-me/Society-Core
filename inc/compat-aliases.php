<?php
/**
 * Compat aliases: pre-1.2.0 function, class, constant and AJAX names that other plugins still use.
 *
 * Full build only (container tier); generated from bin/rename-map.json; do not edit.
 * Regenerate: php bin/gen-compat.php --part=late > inc/compat-aliases.php
 * Map version: 105.1
 *
 * @package gend-society
 */

// phpcs:ignoreFile -- generated compatibility layer: it exists to keep the pre-1.2.0 names working for other plugins.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Loaded LAST (manifest tier container). Every name is guarded: an old cached file that
// still declares gs_x() unguarded loads first and wins instead of fataling.

// Constants probed by other plugins (GS_VERSION: menu parent slugs, feature detection).
if ( ! defined( 'GS_DIR' ) && defined( 'GEND_SOCIETY_DIR' ) ) {
	define( 'GS_DIR', GEND_SOCIETY_DIR );
}
if ( ! defined( 'GS_URL' ) && defined( 'GEND_SOCIETY_URL' ) ) {
	define( 'GS_URL', GEND_SOCIETY_URL );
}
if ( ! defined( 'GS_VERSION' ) && defined( 'GEND_SOCIETY_VERSION' ) ) {
	define( 'GS_VERSION', GEND_SOCIETY_VERSION );
}
// GS_COLLAB_MARKET_PUBLIC: wp-config may still define the old name; gend-society.php honours it
// (see bin/gen-compat.php --part=honour). Readers of the old name get the effective value.
if ( ! defined( 'GS_COLLAB_MARKET_PUBLIC' ) && defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) ) {
	define( 'GS_COLLAB_MARKET_PUBLIC', GEND_SOCIETY_COLLAB_MARKET_PUBLIC );
}

// Functions called by other plugins (caller audit: repo + live hub).
/* sales-team */
if ( ! function_exists( 'gs_agent_chat_generate_reply' ) && function_exists( 'gend_society_agent_chat_generate_reply' ) ) {
	function gs_agent_chat_generate_reply( ...$args ) {
		return gend_society_agent_chat_generate_reply( ...$args );
	}
}
/* projects */
if ( ! function_exists( 'gs_agent_switch_activate' ) && function_exists( 'gend_society_agent_switch_activate' ) ) {
	function gs_agent_switch_activate( ...$args ) {
		return gend_society_agent_switch_activate( ...$args );
	}
}
/* gend-media-optimizer */
if ( ! function_exists( 'gs_dashboard_get_membership' ) && function_exists( 'gend_society_dashboard_get_membership' ) ) {
	function gs_dashboard_get_membership( ...$args ) {
		return gend_society_dashboard_get_membership( ...$args );
	}
}
/* mu:gdc-iframe-embed.php */
if ( ! function_exists( 'gs_enqueue_frontend_assets' ) && function_exists( 'gend_society_enqueue_frontend_assets' ) ) {
	function gs_enqueue_frontend_assets( ...$args ) {
		return gend_society_enqueue_frontend_assets( ...$args );
	}
}
/* projects */
if ( ! function_exists( 'gs_gdrive_fetch_text' ) && function_exists( 'gend_society_gdrive_fetch_text' ) ) {
	function gs_gdrive_fetch_text( ...$args ) {
		return gend_society_gdrive_fetch_text( ...$args );
	}
}
/* email-manager, mu:gdc-local-content-endpoint.php */
if ( ! function_exists( 'gs_get_admin_menu_structure_cached' ) && function_exists( 'gend_society_get_admin_menu_structure_cached' ) ) {
	function gs_get_admin_menu_structure_cached( ...$args ) {
		return gend_society_get_admin_menu_structure_cached( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_collect_codebase' ) && function_exists( 'gend_society_hosting_collect_codebase' ) ) {
	function gs_hosting_collect_codebase( ...$args ) {
		return gend_society_hosting_collect_codebase( ...$args );
	}
}
/* projects */
if ( ! function_exists( 'gs_hosting_collect_container_resources' ) && function_exists( 'gend_society_hosting_collect_container_resources' ) ) {
	function gs_hosting_collect_container_resources( ...$args ) {
		return gend_society_hosting_collect_container_resources( ...$args );
	}
}
/* gend-media-optimizer, projects, vendor-app-manager */
if ( ! function_exists( 'gs_hosting_collect_media' ) && function_exists( 'gend_society_hosting_collect_media' ) ) {
	function gs_hosting_collect_media( ...$args ) {
		return gend_society_hosting_collect_media( ...$args );
	}
}
/* projects, vendor-app-manager */
if ( ! function_exists( 'gs_hosting_collect_tables' ) && function_exists( 'gend_society_hosting_collect_tables' ) ) {
	function gs_hosting_collect_tables( ...$args ) {
		return gend_society_hosting_collect_tables( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_compute_gas_real_data' ) && function_exists( 'gend_society_hosting_compute_gas_real_data' ) ) {
	function gs_hosting_compute_gas_real_data( ...$args ) {
		return gend_society_hosting_compute_gas_real_data( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_db_plan_bytes' ) && function_exists( 'gend_society_hosting_db_plan_bytes' ) ) {
	function gs_hosting_db_plan_bytes( ...$args ) {
		return gend_society_hosting_db_plan_bytes( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_gas_earned_summary' ) && function_exists( 'gend_society_hosting_gas_earned_summary' ) ) {
	function gs_hosting_gas_earned_summary( ...$args ) {
		return gend_society_hosting_gas_earned_summary( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_gas_month_over_month' ) && function_exists( 'gend_society_hosting_gas_month_over_month' ) ) {
	function gs_hosting_gas_month_over_month( ...$args ) {
		return gend_society_hosting_gas_month_over_month( ...$args );
	}
}
/* gend-media-optimizer, vendor-app-manager */
if ( ! function_exists( 'gs_hosting_media_plan_bytes' ) && function_exists( 'gend_society_hosting_media_plan_bytes' ) ) {
	function gs_hosting_media_plan_bytes( ...$args ) {
		return gend_society_hosting_media_plan_bytes( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_pct' ) && function_exists( 'gend_society_hosting_pct' ) ) {
	function gs_hosting_pct( ...$args ) {
		return gend_society_hosting_pct( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_render_analytics_hero' ) && function_exists( 'gend_society_hosting_render_analytics_hero' ) ) {
	function gs_hosting_render_analytics_hero( ...$args ) {
		return gend_society_hosting_render_analytics_hero( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_hosting_server_price' ) && function_exists( 'gend_society_hosting_server_price' ) ) {
	function gs_hosting_server_price( ...$args ) {
		return gend_society_hosting_server_price( ...$args );
	}
}
/* projects */
if ( ! function_exists( 'gs_inject_mini_cart' ) && function_exists( 'gend_society_inject_mini_cart' ) ) {
	function gs_inject_mini_cart( ...$args ) {
		return gend_society_inject_mini_cart( ...$args );
	}
}
/* social-network */
if ( ! function_exists( 'gs_invite_settings_render_inline' ) && function_exists( 'gend_society_invite_settings_render_inline' ) ) {
	function gs_invite_settings_render_inline( ...$args ) {
		return gend_society_invite_settings_render_inline( ...$args );
	}
}
/* projects */
if ( ! function_exists( 'gs_is_embed_request' ) && function_exists( 'gend_society_is_embed_request' ) ) {
	function gs_is_embed_request( ...$args ) {
		return gend_society_is_embed_request( ...$args );
	}
}
/* electron-e2e */
if ( ! function_exists( 'gs_mail_relay_active' ) && function_exists( 'gend_society_mail_relay_active' ) ) {
	function gs_mail_relay_active( ...$args ) {
		return gend_society_mail_relay_active( ...$args );
	}
}
/* mu:zzz-gend-local-native-login.php */
if ( ! function_exists( 'gs_oauth_render_login_page' ) && function_exists( 'gend_society_oauth_render_login_page' ) ) {
	function gs_oauth_render_login_page( ...$args ) {
		return gend_society_oauth_render_login_page( ...$args );
	}
}
/* leo, online-store, vendor-app-manager */
if ( ! function_exists( 'gs_register_admin_menu' ) && function_exists( 'gend_society_register_admin_menu' ) ) {
	function gs_register_admin_menu( ...$args ) {
		return gend_society_register_admin_menu( ...$args );
	}
}
/* gend-media-optimizer */
if ( ! function_exists( 'gs_remote_membership_get_cached' ) && function_exists( 'gend_society_remote_membership_get_cached' ) ) {
	function gs_remote_membership_get_cached( ...$args ) {
		return gend_society_remote_membership_get_cached( ...$args );
	}
}
/* mu:gdc-iframe-embed.php, projects */
if ( ! function_exists( 'gs_render_frontend_bar' ) && function_exists( 'gend_society_render_frontend_bar' ) ) {
	function gs_render_frontend_bar( ...$args ) {
		return gend_society_render_frontend_bar( ...$args );
	}
}
/* vendor-app-manager */
if ( ! function_exists( 'gs_render_membership_panel' ) && function_exists( 'gend_society_render_membership_panel' ) ) {
	function gs_render_membership_panel( ...$args ) {
		return gend_society_render_membership_panel( ...$args );
	}
}
/* blog-manager */
if ( ! function_exists( 'gs_seo_current' ) && function_exists( 'gend_society_seo_current' ) ) {
	function gs_seo_current( ...$args ) {
		return gend_society_seo_current( ...$args );
	}
}
/* blog-manager */
if ( ! function_exists( 'gs_seo_post_types' ) && function_exists( 'gend_society_seo_post_types' ) ) {
	function gs_seo_post_types( ...$args ) {
		return gend_society_seo_post_types( ...$args );
	}
}
/* blog-manager */
if ( ! function_exists( 'gs_seo_trim' ) && function_exists( 'gend_society_seo_trim' ) ) {
	function gs_seo_trim( ...$args ) {
		return gend_society_seo_trim( ...$args );
	}
}
/* email-manager */
if ( ! function_exists( 'gs_user_is_agent' ) && function_exists( 'gend_society_user_is_agent' ) ) {
	function gs_user_is_agent( ...$args ) {
		return gend_society_user_is_agent( ...$args );
	}
}

// Classes used by other plugins.
/* projects, sales-team */
if ( class_exists( 'Gend_Society_AI_Proxy', false ) && ! class_exists( 'GS_AI_Proxy', false ) ) {
	class_alias( 'Gend_Society_AI_Proxy', 'GS_AI_Proxy' );
}
/* email-manager */
if ( class_exists( 'Gend_Society_Wireframe_Store', false ) && ! class_exists( 'GS_Wireframe_Store', false ) ) {
	class_alias( 'Gend_Society_Wireframe_Store', 'GS_Wireframe_Store' );
}
/* blog-manager, email-manager, leo, member-management, online-store, projects, reward-programs, sales-team, social-network */
if ( class_exists( 'Gend_Society_GitHub_Updater', false ) && ! class_exists( 'GenD_GitHub_Updater', false ) ) {
	class_alias( 'Gend_Society_GitHub_Updater', 'GenD_GitHub_Updater' );
}

// Definitions loaded by the 'bp_include' group run at priority 10; alias them right after.
add_action(
	'bp_include',
	static function () {
		/* projects */
		if ( ! function_exists( 'gs_davinci_currency' ) && function_exists( 'gend_society_davinci_currency' ) ) {
			function gs_davinci_currency( ...$args ) {
				return gend_society_davinci_currency( ...$args );
			}
		}
		/* projects */
		if ( ! function_exists( 'gs_davinci_group_member_ids' ) && function_exists( 'gend_society_davinci_group_member_ids' ) ) {
			function gs_davinci_group_member_ids( ...$args ) {
				return gend_society_davinci_group_member_ids( ...$args );
			}
		}
		/* projects */
		if ( ! function_exists( 'gs_davinci_usage_map' ) && function_exists( 'gend_society_davinci_usage_map' ) ) {
			function gs_davinci_usage_map( ...$args ) {
				return gend_society_davinci_usage_map( ...$args );
			}
		}
		/* projects */
		if ( ! function_exists( 'gs_group_render_davinci_ai_suite' ) && function_exists( 'gend_society_group_render_davinci_ai_suite' ) ) {
			function gs_group_render_davinci_ai_suite( ...$args ) {
				return gend_society_group_render_davinci_ai_suite( ...$args );
			}
		}
		/* projects */
		if ( ! function_exists( 'gs_group_tabs_user_has_access' ) && function_exists( 'gend_society_group_tabs_user_has_access' ) ) {
			function gs_group_tabs_user_has_access( ...$args ) {
				return gend_society_group_tabs_user_has_access( ...$args );
			}
		}
		/* projects */
		if ( ! function_exists( 'gs_render_group_feature_suite' ) && function_exists( 'gend_society_render_group_feature_suite' ) ) {
			function gs_render_group_feature_suite( ...$args ) {
				return gend_society_render_group_feature_suite( ...$args );
			}
		}
		/* mu:gdc-local-content-endpoint.php */
		if ( class_exists( 'Gend_Society_Group_Tab_Collab', false ) && ! class_exists( 'GS_Group_Tab_Collab', false ) ) {
			class_alias( 'Gend_Society_Group_Tab_Collab', 'GS_Group_Tab_Collab' );
		}
		/* projects */
		if ( class_exists( 'Gend_Society_Group_Tab_Compute_Gas', false ) && ! class_exists( 'GS_Group_Tab_Compute_Gas', false ) ) {
			class_alias( 'Gend_Society_Group_Tab_Compute_Gas', 'GS_Group_Tab_Compute_Gas' );
		}
	},
	11
);

/**
 * Old AJAX / admin-post action names (every renamed action, for one release).
 *
 * @return array<string, string> old => new.
 */
function gend_society_compat_action_names() {
	return array(
		'gci_currency_hold_toggle' => 'gend_society_currency_hold_toggle', // ajax
		'gdc_save_resume' => 'gend_society_save_resume', // ajax
		'gs_activate_plugin' => 'gend_society_activate_plugin', // ajax
		'gs_app_password_create' => 'gend_society_app_password_create', // admin_post
		'gs_app_password_revoke' => 'gend_society_app_password_revoke', // admin_post
		'gs_cg_mobile_waitlist' => 'gend_society_cg_mobile_waitlist', // ajax
		'gs_compute_gas_devices' => 'gend_society_compute_gas_devices', // ajax
		'gs_davinci_save_api_costs' => 'gend_society_davinci_save_api_costs', // ajax
		'gs_dv_leo_buy' => 'gend_society_dv_leo_buy', // ajax
		'gs_dv_leo_contract' => 'gend_society_dv_leo_contract', // ajax
		'gs_dv_leo_members' => 'gend_society_dv_leo_members', // ajax
		'gs_feature_access_form' => 'gend_society_feature_access_form', // ajax
		'gs_feature_access_save' => 'gend_society_feature_access_save', // ajax
		'gs_features_refresh' => 'gend_society_features_refresh', // ajax
		'gs_fiat_gas_rate_deactivate' => 'gend_society_fiat_gas_rate_deactivate', // admin_post
		'gs_fiat_gas_rate_save' => 'gend_society_fiat_gas_rate_save', // admin_post
		'gs_get_media_storage_plans' => 'gend_society_get_media_storage_plans', // ajax
		'gs_hosting_cache_object' => 'gend_society_hosting_cache_object', // ajax
		'gs_hosting_cache_page' => 'gend_society_hosting_cache_page', // ajax
		'gs_hosting_compute_gas' => 'gend_society_hosting_compute_gas', // ajax
		'gs_hosting_logs' => 'gend_society_hosting_logs', // ajax
		'gs_hosting_media_rescan' => 'gend_society_hosting_media_rescan', // ajax
		'gs_hosting_query_run' => 'gend_society_hosting_query_run', // ajax
		'gs_hosting_template_reset' => 'gend_society_hosting_template_reset', // ajax
		'gs_hosting_toggle_set' => 'gend_society_hosting_toggle_set', // ajax
		'gs_hosting_toggles_get' => 'gend_society_hosting_toggles_get', // ajax
		'gs_invite_panel_fragment' => 'gend_society_invite_panel_fragment', // ajax
		'gs_membership_backup_now' => 'gend_society_membership_backup_now', // ajax
		'gs_membership_backup_restore' => 'gend_society_membership_backup_restore', // ajax
		'gs_membership_change_plan' => 'gend_society_membership_change_plan', // ajax
		'gs_membership_domain_add' => 'gend_society_membership_domain_add', // ajax
		'gs_membership_domain_connect' => 'gend_society_membership_domain_connect', // ajax
		'gs_membership_domain_email_preset_apply' => 'gend_society_membership_domain_email_preset_apply', // ajax
		'gs_membership_domain_email_preset_list' => 'gend_society_membership_domain_email_preset_list', // ajax
		'gs_membership_domain_get_nameservers' => 'gend_society_membership_domain_get_nameservers', // ajax
		'gs_membership_domain_get_status' => 'gend_society_membership_domain_get_status', // ajax
		'gs_membership_domain_import_records' => 'gend_society_membership_domain_import_records', // ajax
		'gs_membership_domain_list' => 'gend_society_membership_domain_list', // ajax
		'gs_membership_domain_point_to_app' => 'gend_society_membership_domain_point_to_app', // ajax
		'gs_membership_domain_record_create' => 'gend_society_membership_domain_record_create', // ajax
		'gs_membership_domain_record_delete' => 'gend_society_membership_domain_record_delete', // ajax
		'gs_membership_domain_record_undo' => 'gend_society_membership_domain_record_undo', // ajax
		'gs_membership_domain_record_update' => 'gend_society_membership_domain_record_update', // ajax
		'gs_membership_domain_records' => 'gend_society_membership_domain_records', // ajax
		'gs_membership_domain_remove' => 'gend_society_membership_domain_remove', // ajax
		'gs_membership_domain_ssl_mode_set' => 'gend_society_membership_domain_ssl_mode_set', // ajax
		'gs_membership_domain_ssl_status' => 'gend_society_membership_domain_ssl_status', // ajax
		'gs_membership_domain_verify' => 'gend_society_membership_domain_verify', // ajax
		'gs_membership_plan_options' => 'gend_society_membership_plan_options', // ajax
		'gs_membership_refresh' => 'gend_society_membership_refresh', // ajax
		'gs_portal_connect_submit' => 'gend_society_portal_connect_submit', // admin_post
		'gs_profile_activity_page' => 'gend_society_profile_activity_page', // ajax
		'gs_save_app_settings' => 'gend_society_save_app_settings', // admin_post
		'gs_save_permalink_settings' => 'gend_society_save_permalink_settings', // admin_post
		'gs_update_plugin' => 'gend_society_update_plugin', // ajax
		'gs_upload_plugin' => 'gend_society_upload_plugin', // ajax
	);
}

/**
 * Mirror every handler of a renamed action onto its old name, at the same priority.
 * Runs on admin_init (admin-ajax.php and admin-post.php dispatch after it), once.
 */
function gend_society_compat_mirror_actions() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	global $wp_filter;
	foreach ( gend_society_compat_action_names() as $old => $new ) {
		foreach ( array( 'wp_ajax_', 'wp_ajax_nopriv_', 'admin_post_', 'admin_post_nopriv_' ) as $p ) {
			if ( empty( $wp_filter[ $p . $new ] ) || ! ( $wp_filter[ $p . $new ] instanceof WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_filter[ $p . $new ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( false === has_action( $p . $old, $cb['function'] ) ) {
						add_action( $p . $old, $cb['function'], $priority, $cb['accepted_args'] );
					}
				}
			}
		}
	}
}
add_action( 'admin_init', 'gend_society_compat_mirror_actions', PHP_INT_MAX );
// Hooks other plugins fire (old name -> new listeners) or listen to (new fire -> old listeners).
/* listened to by contracts-and-payments, mu:gend-privacy-controls.php */
add_filter(
	'gend_society_profile_header_balances',
	static function ( $value = null, ...$args ) {
		static $busy = false;
		if ( $busy ) {
			return $value;
		}
		$busy  = true;
		$value = apply_filters_deprecated( 'gdc_profile_header_balances', array_merge( array( $value ), $args ), '1.2.0', 'gend_society_profile_header_balances' );
		$busy  = false;
		return $value;
	},
	10,
	99
);
/* fired by vendor-app-manager */
add_filter(
	'gs_hosting_codebase_plan_bytes',
	static function ( $value = null, ...$args ) {
		static $busy = false;
		if ( $busy ) {
			return $value;
		}
		$busy  = true;
		$value = apply_filters( 'gend_society_hosting_codebase_plan_bytes', $value, ...$args );
		$busy  = false;
		return $value;
	},
	10,
	99
);
