<?php
/**
 * Key-migration allowlist: old => new names that inc/bootstrap/key-migration.php
 * copies once per scope (copy-not-move). Keys owned by other plugins are never
 * listed here.
 *
 * Generated from bin/rename-map.json by bin/gen-keymap.php; do not edit.
 * Regenerate: php bin/gen-keymap.php > inc/bootstrap/key-map.php
 * Map version: 105.1
 *
 * @package gend-society
 */

// gend-society-rename: keep-start (generated allowlist: the renamer must not rewrite these old names).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'version'             => '105.1',
	'option'              => array(
		'gs_calendar_db_version'               => 'gend_society_calendar_db_version',
		'gs_calendar_view_rewrite_ver'         => 'gend_society_calendar_view_rewrite_ver',
		'gs_collab_db_version'                 => 'gend_society_collab_db_version',
		'gs_connected_at'                      => 'gend_society_connected_at',
		'gs_features_cache'                    => 'gend_society_features_cache',
		'gs_features_cache_expires'            => 'gend_society_features_cache_expires',
		'gs_gend_base_url'                     => 'gend_society_gend_base_url',
		'gs_gend_pubkey'                       => 'gend_society_gend_pubkey',
		'gs_install_id'                        => 'gend_society_install_id',
		'gs_install_token'                     => 'gend_society_install_token',
		'gs_invite_oauth_credentials'          => 'gend_society_invite_oauth_credentials',
		'gs_invite_pending'                    => 'gend_society_invite_pending',
		'gs_keypair'                           => 'gend_society_keypair',
		'gs_mail_relay_state'                  => 'gend_society_mail_relay_state',
		'gs_mail_service_state'                => 'gend_society_mail_service_state',
		'gs_media_storage_plans_cache'         => 'gend_society_media_storage_plans_cache',
		'gs_media_storage_plans_cache_expires' => 'gend_society_media_storage_plans_cache_expires',
		'gs_remote_membership_cache'           => 'gend_society_remote_membership_cache',
		'gs_remote_membership_cache_expires'   => 'gend_society_remote_membership_cache_expires',
		'gs_support_grant_expires_at'          => 'gend_society_support_grant_expires_at',
		'gs_support_grant_issued_by'           => 'gend_society_support_grant_issued_by',
		'gs_support_grant_token_hash'          => 'gend_society_support_grant_token_hash',
		'gs_web_shell_rewrite_flushed'         => 'gend_society_web_shell_rewrite_flushed',
	),
	'site_option'         => array(
		'gdc_nr_wu_commission_queue'   => 'gend_society_nr_wu_commission_queue',
		'gdc_nr_wu_unpaid_commissions' => 'gend_society_nr_wu_unpaid_commissions',
		'gs_desktop_release'           => 'gend_society_desktop_release',
		'gs_leo_purchase_contracts'    => 'gend_society_leo_purchase_contracts',
		'gs_mobile_waitlist'           => 'gend_society_mobile_waitlist',
		'gs_ws_site_restart_queue'     => 'gend_society_ws_site_restart_queue',
	),
	'user_meta'           => array(
		'_gdc_profile_page_id'      => '_gend_society_profile_page_id',
		'_gdc_resume'               => '_gend_society_resume',
		'_gs_chat_anthropic_key'    => '_gend_society_chat_anthropic_key',
		'_gs_chat_model'            => '_gend_society_chat_model',
		'_gs_gcloud_creds'          => '_gend_society_gcloud_creds',
		'_gs_gcloud_email'          => '_gend_society_gcloud_email',
		'_gs_gdrive_access'         => '_gend_society_gdrive_access',
		'_gs_gdrive_exp'            => '_gend_society_gdrive_exp',
		'_gs_gdrive_refresh'        => '_gend_society_gdrive_refresh',
		'_gs_mobile_waitlist'       => '_gend_society_mobile_waitlist',
		'gdc_referral_source'       => 'gend_society_referral_source',
		'gdc_referred_from_site'    => 'gend_society_referred_from_site',
		'gs_feature_access'         => 'gend_society_feature_access',
		'gs_invite_log'             => 'gend_society_invite_log',
		'gs_invite_template'        => 'gend_society_invite_template',
		'gs_theme_notice_dismissed' => 'gend_society_theme_notice_dismissed',
	),
	'post_meta'           => array(
		'_gdc_playlist_items'      => '_gend_society_playlist_items',
		'_gdc_playlist_visibility' => '_gend_society_playlist_visibility',
		'_gs_seo_description'      => '_gend_society_seo_description',
		'_gs_seo_title'            => '_gend_society_seo_title',
	),
	'group_meta'          => array(
		'_gs_collab_category'   => '_gend_society_collab_category',
		'_gs_collab_industry'   => '_gend_society_collab_industry',
		'_gs_collab_location'   => '_gend_society_collab_location',
		'_gs_collab_optin'      => '_gend_society_collab_optin',
		'_gs_davinci_api_costs' => '_gend_society_davinci_api_costs',
	),
	'wu_site_meta'        => array(),
	'cron'                => array(
		'gs_booking_send_reminder' => 'gend_society_booking_send_reminder',
		'gs_collab_outbox_drain'   => 'gend_society_collab_outbox_drain',
		'gs_collab_resolve_sweep'  => 'gend_society_collab_resolve_sweep',
		'gs_dv_leo_contracts_tick' => 'gend_society_dv_leo_contracts_tick',
		'gs_feature_state_daily'   => 'gend_society_feature_state_daily',
		'gs_mail_storage_report'   => 'gend_society_mail_storage_report',
	),
	'option_prefix'       => array(
		'gs_collab_cancel_intent_' => 'gend_society_collab_cancel_intent_',
		'gs_collab_match_'         => 'gend_society_collab_match_',
		'gs_hosting_'              => 'gend_society_hosting_',
	),
	'user_meta_prefix'    => array(
		'gdc_pl_progress_'    => 'gend_society_pl_progress_',
		'gs_chatflow_profile' => 'gend_society_chatflow_profile',
		'gs_invite_oauth_'    => 'gend_society_invite_oauth_',
	),
	'transient_read_once' => array(
		'gs_agent_switch_' => 'gend_society_agent_switch_',
	),
);
// gend-society-rename: keep-end.
