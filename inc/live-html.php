<?php
/**
 * Live HTML: edit the Social templates as real HTML.
 *
 * The group header cards and the profile blocks are rendered on the server,
 * so a template made of them stores no HTML (the code editor only shows
 * block comments). "Edit as HTML" turns such a block into a Custom HTML
 * block holding its markup, with {{tokens}} where each group or member's own
 * values go. This module fills those tokens when the HTML block renders:
 *
 *   {{group.name}}                  a value (HTML-escaped)
 *   {{#if pm.id}} … {{else}} … {{/if}}, {{#unless app.connected}} … {{/unless}}
 *   <div data-gs-live-part="invest"></div>                 a live part (buttons, popups)
 *   <div data-gs-live-block="gdc-blocks/endpoint-panel"
 *        data-gs-attrs='{"slug":"workspace"}'></div>        a live block (apps, feeds)
 *
 * Live parts and live blocks are for pieces that run code (nonces, AJAX,
 * apps): they stay live and are rendered in place.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token namespaces this module fills (other {{…}} text is left alone).
 *
 * @return string[]
 */
function gend_society_lh_namespaces() {
	return array( 'group', 'app', 'pm', 'proposal', 'invest', 'member', 'site', 'user', 'can' );
}

/**
 * The group a template renders for: the current group, else the preview group.
 *
 * @param int $post_id Template being edited (previews only).
 * @return int
 */
function gend_society_lh_group_id( $post_id = 0 ) {
	$gid = function_exists( 'bp_get_current_group_id' ) ? (int) bp_get_current_group_id() : 0;
	if ( ! $gid && defined( 'REST_REQUEST' ) && REST_REQUEST && function_exists( 'gend_society_pv_group_id' ) && current_user_can( 'edit_theme_options' ) ) {
		$gid = gend_society_pv_group_id( $post_id );
	}
	return $gid;
}

/**
 * The member a template renders for: the displayed profile, else you.
 *
 * @return int
 */
function gend_society_lh_member_id() {
	$uid = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : 0;
	return $uid ? $uid : (int) get_current_user_id();
}

/**
 * Token values for a group (same rules as the Projects header cards).
 *
 * @param int $gid Group id.
 * @return array
 */
function gend_society_lh_group_context( $gid ) {
	static $cache = array();
	if ( isset( $cache[ $gid ] ) ) {
		return $cache[ $gid ];
	}
	$ctx   = array();
	$group = $gid && function_exists( 'groups_get_group' ) ? groups_get_group( $gid ) : null;
	if ( ! $group || empty( $group->id ) ) {
		$cache[ $gid ] = $ctx;
		return $ctx;
	}
	$url     = trailingslashit( function_exists( 'bp_get_group_url' ) ? bp_get_group_url( $group ) : bp_get_group_permalink( $group ) );
	$current = function_exists( 'bp_get_current_group_id' ) && (int) bp_get_current_group_id() === (int) $gid;
	$labels  = array(
		'public'  => __( 'Public', 'gend-society' ),
		'private' => __( 'Private', 'gend-society' ),
		'hidden'  => __( 'Hidden', 'gend-society' ),
	);
	$colors  = array(
		'public'  => '#22c55e',
		'private' => '#b608c9',
		'hidden'  => '#6ec1e4',
	);
	$members = $current && function_exists( 'bp_get_group_total_members' ) ? (int) bp_get_group_total_members() : (int) $group->total_member_count;
	$avatar  = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar(
		array(
			'item_id' => $gid,
			'object'  => 'group',
			'type'    => 'full',
			'html'    => false,
		)
	) : '';
	$admin   = current_user_can( 'manage_options' ) || ( function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( get_current_user_id(), $gid ) );

	$ctx['group.id']           = (string) $gid;
	$ctx['group.name']         = $group->name;
	$ctx['group.initial']      = strtoupper( mb_substr( $group->name, 0, 1 ) );
	$ctx['group.description']  = wp_strip_all_tags( (string) $group->description );
	$ctx['group.excerpt']      = $group->description ? wp_trim_words( $group->description, 14, '…' ) : '';
	$ctx['group.url']          = $url;
	$ctx['group.admin_url']    = $url . 'admin/';
	$ctx['group.avatar']       = (string) $avatar;
	$ctx['group.status']       = $group->status;
	$ctx['group.status_label'] = isset( $labels[ $group->status ] ) ? $labels[ $group->status ] : ucfirst( $group->status );
	$ctx['group.status_color'] = isset( $colors[ $group->status ] ) ? $colors[ $group->status ] : '#6ec1e4';
	$ctx['group.members']      = $members ? number_format_i18n( $members ) : '';
	$ctx['group.last_active']  = $current && function_exists( 'bp_get_group_last_active' ) ? (string) bp_get_group_last_active() : '';
	$ctx['group.is_current']   = $current ? '1' : '';
	$ctx['can.manage']         = $admin ? '1' : '';

	// Connected Web App.
	$apps     = function_exists( 'psoo_get_group_apps' ) ? (array) psoo_get_group_apps( $gid ) : array();
	$app      = ! empty( $apps ) ? $apps[0] : null;
	$external = '';
	if ( ! $app && function_exists( 'gdc_gs_group_external_app_url' ) ) {
		$external = (string) gdc_gs_group_external_app_url( $gid );
	}
	$app_name                 = $app ? (string) $app['name'] : ( $external ? (string) wp_parse_url( $external, PHP_URL_HOST ) : '' );
	$ctx['app.connected']     = ( $app || $external ) ? '1' : '';
	$ctx['app.external']      = $external ? '1' : '';
	$ctx['app.name']          = $app_name;
	$ctx['app.initial']       = $app_name ? strtoupper( mb_substr( $app_name, 0, 1 ) ) : '?';
	$ctx['app.url']           = $app ? (string) $app['link'] : $external;
	$ctx['app.connect_url']   = ( ! $ctx['app.connected'] && function_exists( 'gdc_gs_group_connect_webapp_url' ) ) ? (string) gdc_gs_group_connect_webapp_url( $gid ) : '';
	$ctx['app.show']          = ( $ctx['app.connected'] || $ctx['app.connect_url'] ) ? '1' : '';
	$ctx['__app']             = $app;

	// Project manager ("Lead Architect").
	$pm_id  = function_exists( 'psoo_get_group_project_manager_id' ) ? (int) psoo_get_group_project_manager_id( $gid, false ) : 0;
	$pm     = $pm_id ? get_userdata( $pm_id ) : null;
	$pm_id  = $pm ? $pm_id : 0;
	$pm_url = $pm_id && function_exists( 'bp_core_get_user_domain' ) ? (string) bp_core_get_user_domain( $pm_id ) : '';
	$pm_nm  = $pm ? $pm->display_name : __( 'No PM Assigned', 'gend-society' );

	$ctx['pm.id']          = $pm_id ? (string) $pm_id : '';
	$ctx['pm.name']        = $pm_nm;
	$ctx['pm.initial']     = strtoupper( mb_substr( $pm_nm, 0, 1 ) );
	$ctx['pm.url']         = $pm_url;
	$ctx['pm.avatar']      = $pm_id && function_exists( 'bp_core_fetch_avatar' ) ? (string) bp_core_fetch_avatar(
		array(
			'item_id' => $pm_id,
			'object'  => 'user',
			'type'    => 'full',
			'html'    => false,
		)
	) : '';
	$ctx['pm.message_url'] = ( $pm && $pm_url ) ? add_query_arg( 'r', $pm->user_login, trailingslashit( $pm_url ) . 'messages/compose/' ) : '';
	$ctx['pm.can_chat']    = ( $pm_id && is_user_logged_in() && get_current_user_id() !== $pm_id ) ? '1' : '';

	// Proposal and investment.
	$pid                    = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( $gid, '_psoo_group_proposal_id', true ) : 0;
	$proposal               = $pid ? get_post( $pid ) : null;
	$ctx['proposal.title']  = ( $proposal && 'trash' !== $proposal->post_status ) ? $proposal->post_title : __( 'No Proposal Selected', 'gend-society' );
	$ctx['proposal.url']    = $url . 'proposals/';
	$ctx['invest.active']   = ( class_exists( 'PSOO_Investment_Contract' ) && PSOO_Investment_Contract::is_active( $gid ) ) ? '1' : '';

	$cache[ $gid ] = $ctx;
	return $ctx;
}

/**
 * Token values for a member.
 *
 * @param int $uid User id.
 * @return array
 */
function gend_society_lh_member_context( $uid ) {
	static $cache = array();
	if ( isset( $cache[ $uid ] ) ) {
		return $cache[ $uid ];
	}
	$ctx  = array();
	$user = $uid ? get_userdata( $uid ) : null;
	if ( $user ) {
		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : $user->display_name;
		$bio  = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( 'Bio', $uid, 'comma' ) : '';
		if ( is_array( $bio ) ) {
			$bio = implode( ', ', $bio );
		}
		if ( '' === trim( (string) $bio ) ) {
			$bio = get_the_author_meta( 'description', $uid );
		}
		$type = __( 'Member', 'gend-society' );
		if ( function_exists( 'gend_society_user_is_agent' ) && gend_society_user_is_agent( $uid ) ) {
			$type = __( 'Agent', 'gend-society' );
		} elseif ( function_exists( 'gend_society_ps_member_type_options' ) && function_exists( 'gend_society_ps_member_types' ) ) {
			$opts  = gend_society_ps_member_type_options();
			$types = gend_society_ps_member_types( $uid );
			if ( $types && isset( $opts[ $types[0] ] ) ) {
				$type = $opts[ $types[0] ];
			}
		}
		$cover = function_exists( 'bp_attachments_get_attachment' ) ? bp_attachments_get_attachment(
			'url',
			array(
				'object_dir' => 'members',
				'item_id'    => $uid,
			)
		) : '';

		$ctx['member.id']       = (string) $uid;
		$ctx['member.name']     = $name;
		$ctx['member.initial']  = strtoupper( mb_substr( $name, 0, 1 ) );
		$ctx['member.username'] = $user->user_nicename;
		$ctx['member.url']      = function_exists( 'bp_members_get_user_url' ) ? trailingslashit( bp_members_get_user_url( $uid ) ) : get_author_posts_url( $uid );
		$ctx['member.avatar']   = function_exists( 'bp_core_fetch_avatar' ) ? (string) bp_core_fetch_avatar(
			array(
				'item_id' => $uid,
				'type'    => 'full',
				'html'    => false,
			)
		) : get_avatar_url( $uid );
		$ctx['member.cover']    = (string) $cover;
		$ctx['member.type']     = $type;
		$ctx['member.bio_html'] = '' === trim( (string) $bio ) ? '' : wp_kses_post( wpautop( (string) $bio ) );
		$ctx['member.is_me']    = get_current_user_id() === $uid ? '1' : '';
	}
	$cache[ $uid ] = $ctx;
	return $ctx;
}

/**
 * All token values for this render.
 *
 * @param int $post_id Template being previewed (0 on the site).
 * @return array
 */
function gend_society_lh_context( $post_id = 0 ) {
	$viewer = wp_get_current_user();
	$ctx    = array(
		'site.name'      => get_bloginfo( 'name' ),
		'site.url'       => home_url( '/' ),
		'user.logged_in' => is_user_logged_in() ? '1' : '',
		'user.name'      => $viewer->exists() ? $viewer->display_name : '',
	);
	$gid = gend_society_lh_group_id( $post_id );
	if ( $gid ) {
		$ctx = array_merge( $ctx, gend_society_lh_group_context( $gid ) );
	}
	return array_merge( $ctx, gend_society_lh_member_context( gend_society_lh_member_id() ) );
}

/**
 * Fills {{tokens}}, {{#if}} sections and live parts / blocks in some HTML.
 *
 * @param string $html    Template HTML.
 * @param array  $ctx     Token values.
 * @param bool   $preview In the editor (live blocks that are pages show a frame).
 * @return string
 */
function gend_society_lh_fill( $html, $ctx, $preview = false ) {
	$ns = implode( '|', gend_society_lh_namespaces() );

	// Sections, innermost first.
	$section = '/\{\{#(if|unless) ((?:' . $ns . ')\.[\w.]+)\}\}((?:(?!\{\{#(?:if|unless) ).)*?)\{\{\/\1\}\}/s';
	for ( $i = 0; $i < 50 && preg_match( $section, $html ); $i++ ) {
		$html = preg_replace_callback(
			$section,
			static function ( $m ) use ( $ctx ) {
				$on    = isset( $ctx[ $m[2] ] ) && '' !== (string) $ctx[ $m[2] ] && '0' !== (string) $ctx[ $m[2] ];
				$on    = 'unless' === $m[1] ? ! $on : $on;
				$parts = explode( '{{else}}', $m[3], 2 );
				return $on ? $parts[0] : ( isset( $parts[1] ) ? $parts[1] : '' );
			},
			$html
		);
	}

	// Live parts and live blocks.
	$html = preg_replace_callback(
		'/<div\s+data-gs-live-(part|block)="([\w\/-]+)"(?:\s+data-gs-attrs=(?:\'([^\']*)\'|"([^"]*)"))?\s*><\/div>/',
		static function ( $m ) use ( $ctx, $preview ) {
			if ( 'part' === $m[1] ) {
				return gend_society_lh_part( $m[2], $ctx );
			}
			$raw   = isset( $m[4] ) && '' !== $m[4] ? $m[4] : ( isset( $m[3] ) ? $m[3] : '' );
			$attrs = json_decode( html_entity_decode( $raw, ENT_QUOTES ), true );
			return gend_society_lh_live_block( $m[2], is_array( $attrs ) ? $attrs : array(), $preview );
		},
		$html
	);

	// Values: {{key}} escaped; *_html keys are already safe HTML.
	return preg_replace_callback(
		'/\{\{\s*((?:' . $ns . ')\.[\w.]+)\s*\}\}/',
		static function ( $m ) use ( $ctx ) {
			$v = isset( $ctx[ $m[1] ] ) && is_scalar( $ctx[ $m[1] ] ) ? (string) $ctx[ $m[1] ] : '';
			return '_html' === substr( $m[1], -5 ) ? $v : esc_html( $v );
		},
		$html
	);
}

/**
 * A live part: a piece of a header card that runs code.
 *
 * @param string $name Part name.
 * @param array  $ctx  Token values.
 * @return string
 */
function gend_society_lh_part( $name, $ctx ) {
	$gid = isset( $ctx['group.id'] ) ? (int) $ctx['group.id'] : 0;
	switch ( $name ) {
		case 'group-buttons':
			if ( ! $gid || empty( $ctx['group.is_current'] ) || ! function_exists( 'bp_nouveau_group_header_buttons' ) ) {
				return '';
			}
			ob_start();
			bp_nouveau_group_header_buttons();
			return (string) ob_get_clean();
		case 'app-sections':
			if ( empty( $ctx['__app'] ) || ! function_exists( 'psoo_render_group_app_subsections' ) ) {
				return '';
			}
			return (string) psoo_render_group_app_subsections( $ctx['__app'] );
		case 'pm-chat':
			if ( empty( $ctx['pm.can_chat'] ) ) {
				return '';
			}
			return gend_society_lh_pm_chat( $ctx );
		case 'invest':
			if ( ! $gid || empty( $ctx['invest.active'] ) || ! function_exists( 'gdc_gs_render_group_pm_console_card' ) ) {
				return '';
			}
			// The Projects card owns the Invest button, popup and its AJAX: take them from its own output.
			$card   = (string) gdc_gs_render_group_pm_console_card( array( 'showInvestCta' => true ) );
			$start  = strpos( $card, 'class="btn-invest-now"' );
			$modal  = strpos( $card, '<div class="psoo-invest-modal"' );
			$button = false === $start ? false : strrpos( substr( $card, 0, $start ), '<button' );
			$end    = false === $start ? false : strpos( $card, '</button>', $start );
			if ( false === $button || false === $end || false === $modal ) {
				return '';
			}
			return substr( $card, $button, $end + 9 - $button ) . substr( $card, $modal );
	}
	return '';
}

/**
 * The chat-with-your-consultant button (opens the chat widget's DM box).
 *
 * @param array $ctx Token values.
 * @return string
 */
function gend_society_lh_pm_chat( $ctx ) {
	$label = __( 'Chat with your project consultant', 'gend-society' );
	$html  = '<button type="button" class="terminal-pm-chat-btn" data-psoo-pm-chat data-user-id="' . esc_attr( $ctx['pm.id'] ) . '" data-user-name="' . esc_attr( $ctx['pm.name'] ) . '" data-user-avatar="' . esc_attr( $ctx['pm.avatar'] ) . '" data-fallback-url="' . esc_attr( $ctx['pm.message_url'] ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></button>';
	$js    = "(function(){if(window.__psooPmChatBound)return;window.__psooPmChatBound=true;document.addEventListener('click',function(ev){var b=ev.target&&ev.target.closest&&ev.target.closest('[data-psoo-pm-chat]');if(!b)return;ev.preventDefault();if(typeof window.emChatOpenUser==='function'){window.emChatOpenUser({user_id:parseInt(b.getAttribute('data-user-id'),10)||0,display_name:b.getAttribute('data-user-name')||'',avatar_url:b.getAttribute('data-user-avatar')||''});}else if(b.getAttribute('data-fallback-url')){window.location.href=b.getAttribute('data-fallback-url');}});})();";
	return $html . wp_get_inline_script_tag( $js );
}

/**
 * A live block inside HTML (apps, feeds, menus: anything that can't be static).
 *
 * @param string $name    Block name.
 * @param array  $attrs   Block attributes.
 * @param bool   $preview In the editor.
 * @return string
 */
function gend_society_lh_live_block( $name, $attrs, $preview ) {
	if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		return '';
	}
	if ( $preview && 'gdc-blocks/endpoint-panel' === $name && ! empty( $attrs['slug'] ) ) {
		$gid   = gend_society_lh_group_id( (int) get_the_ID() );
		$group = $gid && function_exists( 'groups_get_group' ) ? groups_get_group( $gid ) : null;
		$url   = $group && function_exists( 'bp_get_group_url' ) ? trailingslashit( bp_get_group_url( $group ) ) : '';
		if ( $url ) {
			$src = add_query_arg(
				array(
					'gs_studio_view' => 'group-endpoint',
					'slug'           => $attrs['slug'],
				),
				$url
			);
			return '<iframe class="gs-pv-frame gs-lh-frame" src="' . esc_url( $src ) . '" title="' . esc_attr( $attrs['slug'] ) . '" loading="lazy"></iframe>';
		}
	}
	return render_block(
		array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
}

add_filter( 'render_block_core/html', 'gend_society_lh_render_html_block', 10, 1 );
/**
 * Fills tokens when a Custom HTML block uses them.
 *
 * @param string $html Block output.
 * @return string
 */
function gend_society_lh_render_html_block( $html ) {
	if ( false === strpos( $html, '{{' ) && false === strpos( $html, 'data-gs-live-' ) ) {
		return $html;
	}
	return gend_society_lh_fill( $html, gend_society_lh_context() );
}

/* ── Converting blocks to HTML ──────────────────────────────────────────── */

/**
 * Inline SVG icons the header card templates use.
 *
 * @param string $name Icon.
 * @return string
 */
function gend_society_lh_icon( $name ) {
	$icons = array(
		'gear'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
		'members'  => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
		'clock'    => '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
		'monitor'  => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#6ec1e4" stroke-width="1.2" opacity="0.45"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
		'document' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#b608c9" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
	);
	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Accent border style a header card's "Accent colour" setting adds.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_lh_accent( $attrs ) {
	$color = isset( $attrs['accentColor'] ) ? sanitize_hex_color( $attrs['accentColor'] ) : '';
	return $color ? ' style="border-left:4px solid ' . esc_attr( $color ) . ';"' : '';
}

/**
 * HTML templates for the blocks that convert to HTML, by block name.
 *
 * @param string $name  Block name.
 * @param array  $attrs Block attributes.
 * @return string|null Null when the block has no template.
 */
function gend_society_lh_template( $name, $attrs ) {
	$attrs = is_array( $attrs ) ? $attrs : array();
	switch ( $name ) {
		case 'gdc-blocks/group-identity-card':
			$color = ! empty( $attrs['accentColor'] ) ? sanitize_hex_color( $attrs['accentColor'] ) : '';
			$color = $color ? $color : '{{group.status_color}}';
			$gear  = ! array_key_exists( 'showAdminGear', $attrs ) || ! empty( $attrs['showAdminGear'] );
			$manage = esc_attr__( 'Manage group', 'gend-society' );
			return '<div class="identity-shield bridge-panel">
	<div class="profile-wrap">
		{{#if group.avatar}}<img src="{{group.avatar}}" alt="{{group.name}}">{{else}}<div class="avatar-placeholder">{{group.initial}}</div>{{/if}}
' . ( $gear ? '		{{#if can.manage}}<a class="identity-settings-btn" href="{{group.admin_url}}" title="' . $manage . '" aria-label="' . $manage . '">' . gend_society_lh_icon( 'gear' ) . '</a>
		<style>
			.profile-wrap { position: relative; }
			.identity-settings-btn { position: absolute; bottom: -6px; right: -6px; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.92); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 50%; box-shadow: 0 6px 14px rgba(0, 0, 0, 0.45); text-decoration: none; transition: all 0.18s ease; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 3; }
			.identity-settings-btn:hover, .identity-settings-btn:focus { transform: rotate(30deg) scale(1.06); color: #fff; background: rgba(99, 102, 241, 0.85); border-color: rgba(99, 102, 241, 1); box-shadow: 0 6px 18px rgba(99, 102, 241, 0.55); outline: none; }
		</style>{{/if}}
' : '' ) . '	</div>
	<div class="identity-text">
		<div class="identity-badges">
			<span class="identity-status-badge" style="color:' . $color . ';border-color:' . $color . '20;background:' . $color . '14;">
				<span class="identity-status-dot" style="background:' . $color . ';box-shadow:0 0 6px ' . $color . ';"></span>
				{{group.status_label}} ' . esc_html__( 'Group', 'gend-society' ) . '
			</span>
			{{#if group.members}}<span class="identity-members-badge">' . gend_society_lh_icon( 'members' ) . ' {{group.members}}</span>{{/if}}
		</div>
		<h2 class="identity-name">{{group.name}}</h2>
		{{#if group.excerpt}}<p class="identity-desc">{{group.excerpt}}</p>{{/if}}
		{{#if group.last_active}}<p class="identity-active">' . gend_society_lh_icon( 'clock' ) . ' {{group.last_active}}</p>{{/if}}
	</div>
	{{#if group.is_current}}<div class="identity-actions" role="complementary"><div data-gs-live-part="group-buttons"></div></div>{{/if}}
</div>';

		case 'gdc-blocks/group-connected-app-card':
			$html = '<div class="assigned-app-module bridge-panel {{#if app.connected}}is-connected{{else}}is-empty{{/if}}"' . gend_society_lh_accent( $attrs ) . '>
	{{#unless app.connected}}<div class="app-icon-wrap app-icon-placeholder" aria-hidden="true">' . gend_society_lh_icon( 'monitor' ) . '</div>{{/unless}}
	<div class="app-text">
		<span class="app-label">' . esc_html__( 'Connected Web App', 'gend-society' ) . '</span>
		{{#if app.connected}}
		<a class="app-view-site-btn" href="{{app.url}}" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Site', 'gend-society' ) . '</a>
		{{else}}
		<strong class="app-name">' . esc_html__( 'No App Linked', 'gend-society' ) . '</strong>
		<span class="app-hint">' . esc_html__( 'Link an app via Proposals', 'gend-society' ) . '</span>
		{{#if app.connect_url}}<a class="app-connect-btn gdc-header-connect-webapp" href="{{app.connect_url}}">' . esc_html__( 'Connect your Web App', 'gend-society' ) . '</a>{{/if}}
		{{/if}}
		<div data-gs-live-part="app-sections"></div>
	</div>
</div>';
			return ! empty( $attrs['hideWhenEmpty'] ) ? '{{#if app.show}}' . $html . '{{/if}}' : $html;

		case 'gdc-blocks/group-pm-console-card':
			$invest = ! array_key_exists( 'showInvestCta', $attrs ) || ! empty( $attrs['showInvestCta'] );
			return '<div class="consultant-terminal bridge-panel"' . gend_society_lh_accent( $attrs ) . '>
	<div class="terminal-proposal">
		' . gend_society_lh_icon( 'document' ) . '
		<div>
			<span class="terminal-label">' . esc_html__( 'Active Proposal', 'gend-society' ) . '</span>
			<a href="{{proposal.url}}" class="terminal-proposal-title">{{proposal.title}}</a>
		</div>
	</div>
	<div class="consultant-top">
		{{#if pm.url}}<a href="{{pm.url}}" class="consultant-profile-link">{{/if}}
		{{#if pm.avatar}}<img src="{{pm.avatar}}" alt="{{pm.name}}" class="consultant-avatar">{{else}}<div class="consultant-avatar-placeholder">{{pm.initial}}</div>{{/if}}
		<div>
			<span class="terminal-label">' . esc_html__( 'Lead Architect', 'gend-society' ) . '</span>
			<strong class="terminal-pm-name">{{pm.name}}</strong>
		</div>
		{{#if pm.url}}</a>{{/if}}
		<div data-gs-live-part="pm-chat"></div>
	</div>
	{{#unless pm.id}}<a href="{{group.url}}admin/manage-members/" class="btn-message-consultant btn-assign-pm psoo-assign-pm-btn" data-group-id="{{group.id}}">' . esc_html__( 'Assign Project Manager', 'gend-society' ) . '</a>{{/unless}}
' . ( $invest ? '	<div data-gs-live-part="invest"></div>
' : '' ) . '</div>';
	}
	return null;
}

/**
 * Profile blocks that convert by rendering them and putting tokens back in
 * (keeps their colour, spacing and type settings), with the values to swap.
 *
 * @return array Block name => token keys.
 */
function gend_society_lh_tokenized_blocks() {
	return array(
		'gend-society/profile-avatar'      => array( 'member.avatar' ),
		'gend-society/profile-name'        => array( 'member.name' ),
		'gend-society/profile-handle'      => array( 'member.username' ),
		'gend-society/profile-member-type' => array( 'member.type' ),
	);
}

/**
 * The HTML a block converts to.
 *
 * @param string $name  Block name.
 * @param array  $attrs Block attributes.
 * @return array { html: string, live: bool } live = kept as a live block inside the HTML.
 */
function gend_society_lh_convert( $name, $attrs ) {
	$attrs = is_array( $attrs ) ? $attrs : array();
	$tpl   = gend_society_lh_template( $name, $attrs );
	if ( null !== $tpl ) {
		return array(
			'html' => $tpl,
			'live' => false,
		);
	}
	$map = gend_society_lh_tokenized_blocks();
	if ( isset( $map[ $name ] ) && WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		$ctx  = gend_society_lh_member_context( gend_society_lh_member_id() );
		$html = render_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		foreach ( $map[ $name ] as $key ) {
			$value = isset( $ctx[ $key ] ) ? (string) $ctx[ $key ] : '';
			if ( '' !== $value ) {
				$html = str_replace( 'member.avatar' === $key ? esc_url( $value ) : esc_html( $value ), '{{' . $key . '}}', $html );
			}
		}
		if ( '' !== trim( $html ) ) {
			return array(
				'html' => $html,
				'live' => false,
			);
		}
	}
	if ( 'gend-society/profile-bio' === $name && ( empty( $attrs['field'] ) || 'Bio' === $attrs['field'] ) ) {
		return array(
			'html' => '{{#if member.bio_html}}<div class="wp-block-gend-society-profile-bio gdc-ps-field">{{member.bio_html}}</div>{{/if}}',
			'live' => false,
		);
	}
	$json = wp_json_encode( (object) $attrs );
	return array(
		'html' => '<div data-gs-live-block="' . esc_attr( $name ) . '"' . ( '{}' === $json ? '' : " data-gs-attrs='" . str_replace( "'", '&#39;', $json ) . "'" ) . '></div>',
		'live' => true,
	);
}

add_action( 'rest_api_init', 'gend_society_lh_rest' );
/**
 * REST: convert blocks to HTML, and render HTML with tokens for the editor.
 */
function gend_society_lh_rest() {
	$can = static function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/live-html/convert',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => static function ( WP_REST_Request $req ) {
				$out = array();
				foreach ( (array) $req->get_param( 'blocks' ) as $b ) {
					$out[] = gend_society_lh_convert( isset( $b['name'] ) ? (string) $b['name'] : '', isset( $b['attributes'] ) ? (array) $b['attributes'] : array() );
				}
				return rest_ensure_response( $out );
			},
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/live-html/render',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => static function ( WP_REST_Request $req ) {
				$post_id = (int) $req->get_param( 'postId' );
				if ( $post_id ) {
					$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- previews resolve the template's group.
				}
				$html = (string) $req->get_param( 'html' );
				return rest_ensure_response( array( 'html' => gend_society_lh_fill( $html, gend_society_lh_context( $post_id ), true ) ) );
			},
		)
	);
}
