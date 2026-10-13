<?php
/**
 * Hub-hosted areas inside wp-admin on a connected standalone install (Phase 109, PLAN-04, D5 / D11).
 *
 * Manifest: tier customer, needs ['standalone', 'paired'], loaded after inc/plan-access.php, so nothing here runs
 * on the hub, on gend.me containers or before the install is connected.
 *
 * GenD > Groups (gs-hub-groups), GenD > Feature Suite (gs-hub-feature-suite) and GenD > Projects
 * (gs-hub-projects) are registered when the cached plan features list the area with delivery hub_embed
 * (Projects only when the hub offers it). Each page asks the hub for JSON - GET /install/{id}/hub/{area} through
 * gend_society_plan_hub_get(), bearer token server side only, cached in gend_society_hub_area_cache_<area> for
 * min(300 s, cache_seconds) - and renders it with the local templates below, local CSS and JS
 * (assets/gs-hub-embed.css / .js). No HTML is proxied, nothing is iframed, no remote script or stylesheet is
 * loaded (wordpress.org Guideline 8). Images are shown only when their URL is on the hub itself; any other host
 * gets a local placeholder.
 *
 * Hub answers: 200 data; 402 the upgrade copy naming the unlocking plan and 409 the After migration copy (both
 * from gend_society_feature_upgrade_render_standalone()); 401 Reconnect; unreachable keeps the last data with a
 * note. Refresh goes through the plan Refresh AJAX (gend_society_plan_refresh: every plan cache is dropped) and
 * this page's fragment comes back through gend_society_plan_refresh_html (id gs-hub-<area>).
 *
 * The hub alone decides what a plan includes; nothing shipped in the plugin is locked (Guideline 5).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_hub_embed_areas' ) ) {
	/**
	 * Hub-hosted areas this module can render: slug => menu label.
	 *
	 * @return array<string,string>
	 */
	function gend_society_hub_embed_areas(): array {
		return array(
			'groups'        => __( 'Groups', 'gend-society' ),
			'feature-suite' => __( 'Feature Suite', 'gend-society' ),
			'projects'      => __( 'Projects', 'gend-society' ),
		);
	}
}

if ( ! function_exists( 'gend_society_hub_embed_offered' ) ) {
	/**
	 * Areas the hub lists for this install with delivery hub_embed (from the cached features payload; one
	 * conditional fetch only when nothing is cached yet).
	 *
	 * @return string[] Known area slugs in menu order.
	 */
	function gend_society_hub_embed_offered(): array {
		if ( ! function_exists( 'gend_society_plan_cache_read' ) ) {
			return array();
		}
		$id      = gend_society_plan_identity();
		$payload = gend_society_plan_cache_read( 'gend_society_features_cache', $id['install_id'] )['payload'];
		if ( ! is_array( $payload ) ) {
			$payload = gend_society_plan_features()['payload'];
		}
		$listed = array();
		foreach ( (array) ( is_array( $payload ) ? ( $payload['areas'] ?? array() ) : array() ) as $a ) {
			if ( is_array( $a ) && 'hub_embed' === ( $a['delivery'] ?? '' ) && ! empty( $a['slug'] ) ) {
				$listed[] = sanitize_key( (string) $a['slug'] );
			}
		}
		return array_values( array_intersect( array_keys( gend_society_hub_embed_areas() ), $listed ) );
	}
}

if ( ! function_exists( 'gend_society_hub_area_cache_option' ) ) {
	/**
	 * Cache option of one hub area (gend_society_plan_invalidate_all() drops every one of them).
	 *
	 * @param string $area Area slug.
	 */
	function gend_society_hub_area_cache_option( string $area ): string {
		return 'gend_society_hub_area_cache_' . sanitize_key( $area );
	}
}

if ( ! function_exists( 'gend_society_hub_area_fetch' ) ) {
	/**
	 * GET /install/{id}/hub/{area} (conditional, cached for min(300 s, cache_seconds)).
	 *
	 * @param string $area  groups | feature-suite | projects.
	 * @param bool   $force Skip the cache and send no If-None-Match.
	 * @return array{area:string,state:string,data:?array,stale:bool,unlock_plan:mixed,status:int,source:string,fetched_at:int}
	 *               state: ok | upgrade | after_migration | unknown_area | revoked | unreachable | not_connected.
	 */
	function gend_society_hub_area_fetch( string $area, bool $force = false ): array {
		$area = sanitize_key( $area );
		$out  = array(
			'area'        => $area,
			'state'       => 'unknown_area',
			'data'        => null,
			'stale'       => false,
			'unlock_plan' => null,
			'status'      => 0,
			'source'      => 'none',
			'fetched_at'  => 0,
		);
		if ( ! isset( gend_society_hub_embed_areas()[ $area ] ) || ! function_exists( 'gend_society_plan_hub_get' ) ) {
			return $out;
		}
		$option        = gend_society_hub_area_cache_option( $area );
		$r             = gend_society_plan_hub_get( 'hub/' . $area, $option, $force );
		$out['status'] = (int) $r['status'];
		$out['source'] = (string) $r['source'];
		$valid         = function ( $p ) use ( $area ) {
			return is_array( $p ) && ( $p['area'] ?? '' ) === $area && isset( $p['data'] ) && is_array( $p['data'] );
		};

		if ( 'not_connected' === $r['state'] || 'revoked' === $r['state'] ) {
			$out['state'] = $r['state'];
			return $out;
		}
		if ( in_array( $out['status'], array( 402, 409, 404 ), true ) ) {
			// The area is not (or no longer) served to this install: forget any earlier data.
			delete_option( $option );
			$err                = is_array( $r['error'] ?? null ) ? $r['error'] : array();
			$out['state']       = 402 === $out['status'] ? 'upgrade' : ( 409 === $out['status'] ? 'after_migration' : 'unknown_area' );
			$out['unlock_plan'] = $err['unlock_plan'] ?? null;
			return $out;
		}
		if ( 'ok' === $r['state'] && $valid( $r['payload'] ) ) {
			$c = get_option( $option, null );
			if ( in_array( $r['source'], array( 'fetch', '304' ), true ) && is_array( $c ) && (int) ( $c['expires'] ?? 0 ) > time() + 300 ) {
				$c['expires'] = time() + 300;
				update_option( $option, $c, false );
			}
			$out['state']      = 'ok';
			$out['data']       = $r['payload']['data'];
			$out['fetched_at'] = is_array( $c ) ? (int) ( $c['fetched_at'] ?? 0 ) : time();
			return $out;
		}
		if ( $valid( $r['payload'] ) ) {
			$c                 = get_option( $option, null );
			$out['state']      = 'ok';
			$out['stale']      = true;
			$out['data']       = $r['payload']['data'];
			$out['fetched_at'] = is_array( $c ) ? (int) ( $c['fetched_at'] ?? 0 ) : 0;
			return $out;
		}
		$out['state'] = 'unreachable';
		return $out;
	}
}

// ─── Local templates (every value escaped; hub strings are plain text) ─────────────────────────────────

if ( ! function_exists( 'gend_society_hub_embed_img' ) ) {
	/**
	 * An image only when it is on the hub itself; otherwise a local placeholder (no request).
	 *
	 * @param mixed  $url   Hub value.
	 * @param string $class CSS class.
	 */
	function gend_society_hub_embed_img( $url, string $class ): string {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' !== $url && function_exists( 'gend_society_plan_is_hub_url' ) && gend_society_plan_is_hub_url( $url ) ) {
			return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $url ) . '" alt="" width="48" height="48" loading="lazy" decoding="async">';
		}
		return '<span class="' . esc_attr( $class ) . ' gs-hub-placeholder" aria-hidden="true" data-gs-hub-placeholder="1"></span>';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_link' ) ) {
	/**
	 * A new-tab link when the URL is on the hub, plain text otherwise.
	 *
	 * @param mixed  $url  Hub value.
	 * @param string $text Link text.
	 * @param string $class CSS class.
	 */
	function gend_society_hub_embed_link( $url, string $text, string $class = '' ): string {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' !== $url && function_exists( 'gend_society_plan_is_hub_url' ) && gend_society_plan_is_hub_url( $url ) ) {
			return '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>';
		}
		return '<span' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_str' ) ) {
	/**
	 * A hub scalar as a string ('' otherwise).
	 *
	 * @param mixed $v Hub value.
	 */
	function gend_society_hub_embed_str( $v ): string {
		return is_scalar( $v ) ? (string) $v : '';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_date' ) ) {
	/**
	 * A hub date in the site's date (and time) format; the raw value when it does not parse.
	 *
	 * @param mixed $v         Hub value.
	 * @param bool  $with_time Add the time.
	 */
	function gend_society_hub_embed_date( $v, bool $with_time = false ): string {
		$s  = gend_society_hub_embed_str( $v );
		$ts = '' !== $s ? strtotime( $s ) : false;
		if ( ! $ts ) {
			return $s;
		}
		$fmt = (string) get_option( 'date_format', 'F j, Y' ) . ( $with_time ? ' ' . (string) get_option( 'time_format', 'g:i a' ) : '' );
		return date_i18n( $fmt, $ts );
	}
}

if ( ! function_exists( 'gend_society_hub_embed_render_groups' ) ) {
	/**
	 * Groups: header (avatar, name, member count, description), members, recent activity, Open on gend.me.
	 *
	 * @param array $data Hub data.
	 */
	function gend_society_hub_embed_render_groups( array $data ): string {
		$g     = isset( $data['group'] ) && is_array( $data['group'] ) ? $data['group'] : array();
		$name  = gend_society_hub_embed_str( $g['name'] ?? '' );
		$count = (int) ( $g['member_count'] ?? 0 );
		$h     = '<div class="gend-society-card gs-hub-card gs-hub-group" data-gs-hub-group="' . esc_attr( (string) (int) ( $g['id'] ?? 0 ) ) . '">';
		$h    .= '<div class="gs-hub-group__head">' . gend_society_hub_embed_img( $g['avatar_url'] ?? '', 'gs-hub-group__avatar' );
		$h    .= '<div class="gs-hub-group__title"><h2 data-gs-hub-field="group-name">' . esc_html( '' !== $name ? $name : __( 'Your group', 'gend-society' ) ) . '</h2>';
		/* translators: %s: number of members. */
		$h    .= '<p class="gs-hub-group__count" data-gs-hub-field="member-count">' . esc_html( sprintf( _n( '%s member', '%s members', $count, 'gend-society' ), number_format_i18n( $count ) ) ) . '</p></div></div>';
		$desc  = gend_society_hub_embed_str( $g['description'] ?? '' );
		if ( '' !== $desc ) {
			$h .= '<p class="gs-hub-group__desc" data-gs-hub-field="description">' . esc_html( $desc ) . '</p>';
		}
		$open = gend_society_hub_embed_link( $g['url'] ?? '', __( 'Open on gend.me', 'gend-society' ), 'button button-secondary' );
		if ( 0 === strpos( $open, '<a' ) ) {
			$h .= '<p class="gs-hub-group__open">' . $open . '</p>';
		}
		$h .= '</div>';

		$roles   = array(
			'admin'  => __( 'Organizer', 'gend-society' ),
			'mod'    => __( 'Moderator', 'gend-society' ),
			'member' => __( 'Member', 'gend-society' ),
		);
		$members = array_slice( array_values( array_filter( (array) ( $data['members'] ?? array() ), 'is_array' ) ), 0, 20 );
		$h      .= '<div class="gend-society-card gs-hub-card gs-hub-members"><h2>' . esc_html__( 'Members', 'gend-society' ) . '</h2>';
		if ( ! $members ) {
			$h .= '<p>' . esc_html__( 'No members to show yet.', 'gend-society' ) . '</p>';
		} else {
			$h .= '<ul class="gs-hub-members__list">';
			foreach ( $members as $m ) {
				$role = gend_society_hub_embed_str( $m['role'] ?? '' );
				$h   .= '<li class="gs-hub-member" data-gs-hub-member="1">' . gend_society_hub_embed_img( $m['avatar_url'] ?? '', 'gs-hub-member__avatar' );
				$h   .= '<span class="gs-hub-member__name">' . esc_html( gend_society_hub_embed_str( $m['name'] ?? '' ) ) . '</span>';
				if ( '' !== $role ) {
					$h .= ' <span class="gs-hub-member__role">' . esc_html( $roles[ $role ] ?? ucfirst( $role ) ) . '</span>';
				}
				$h .= '</li>';
			}
			$h .= '</ul>';
		}
		$h .= '</div>';

		$activity = array_slice( array_values( array_filter( (array) ( $data['activity'] ?? array() ), 'is_array' ) ), 0, 10 );
		$h       .= '<div class="gend-society-card gs-hub-card gs-hub-activity"><h2>' . esc_html__( 'Recent activity', 'gend-society' ) . '</h2>';
		if ( ! $activity ) {
			$h .= '<p>' . esc_html__( 'No recent activity.', 'gend-society' ) . '</p>';
		} else {
			$h .= '<ul class="gs-hub-activity__list">';
			foreach ( $activity as $a ) {
				$h .= '<li class="gs-hub-activity__item" data-gs-hub-activity="1"><span class="gs-hub-activity__text">' . esc_html( gend_society_hub_embed_str( $a['text'] ?? '' ) ) . '</span>';
				$when = gend_society_hub_embed_date( $a['date'] ?? '', true );
				if ( '' !== $when ) {
					$h .= ' <span class="gs-hub-activity__date">' . gend_society_hub_embed_link( $a['url'] ?? '', $when ) . '</span>';
				}
				$h .= '</li>';
			}
			$h .= '</ul>';
		}
		return $h . '</div>';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_render_feature_suite' ) ) {
	/**
	 * Feature Suite: name / area / status badge (Included, Upgrade - <plan>, Add-on).
	 *
	 * @param array $data Hub data.
	 */
	function gend_society_hub_embed_render_feature_suite( array $data ): string {
		$rows = array_values( array_filter( (array) ( $data['features'] ?? array() ), 'is_array' ) );
		$h    = '<div class="gend-society-card gs-hub-card gs-hub-features"><h2>' . esc_html__( 'Features', 'gend-society' ) . '</h2>';
		if ( ! $rows ) {
			return $h . '<p>' . esc_html__( 'gend.me has not listed any features yet.', 'gend-society' ) . '</p></div>';
		}
		$h .= '<table class="widefat striped gs-hub-table"><thead><tr><th scope="col">' . esc_html__( 'Feature', 'gend-society' ) . '</th><th scope="col">' . esc_html__( 'Area', 'gend-society' ) . '</th><th scope="col">' . esc_html__( 'Status', 'gend-society' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $f ) {
			$status = gend_society_hub_embed_str( $f['status'] ?? '' );
			$area   = sanitize_key( gend_society_hub_embed_str( $f['area'] ?? '' ) );
			if ( 'included' === $status ) {
				$badge = __( 'Included', 'gend-society' );
				$cls   = 'included';
			} elseif ( 'addon' === $status ) {
				$badge = __( 'Add-on', 'gend-society' );
				$cls   = 'addon';
			} else {
				$plan  = is_array( $f['unlock_plan'] ?? null ) ? gend_society_hub_embed_str( $f['unlock_plan']['name'] ?? '' ) : '';
				/* translators: %s: plan name. */
				$badge = '' !== $plan ? sprintf( __( 'Upgrade - %s', 'gend-society' ), $plan ) : __( 'Upgrade', 'gend-society' );
				$cls   = 'upgrade';
			}
			$h .= '<tr data-gs-hub-feature="' . esc_attr( sanitize_key( gend_society_hub_embed_str( $f['slug'] ?? '' ) ) ) . '">';
			$h .= '<td class="gs-hub-feature__name">' . esc_html( gend_society_hub_embed_str( $f['name'] ?? '' ) ) . '</td>';
			$h .= '<td class="gs-hub-feature__area">' . esc_html( '' !== $area && function_exists( 'gend_society_feature_area_label' ) ? gend_society_feature_area_label( $area ) : $area ) . '</td>';
			$h .= '<td><span class="gs-hub-badge is-' . esc_attr( $cls ) . '" data-gs-hub-status="' . esc_attr( $cls ) . '">' . esc_html( $badge ) . '</span></td>';
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_render_projects' ) ) {
	/**
	 * Projects: title / status / due / link.
	 *
	 * @param array $data Hub data.
	 */
	function gend_society_hub_embed_render_projects( array $data ): string {
		$rows = array_slice( array_values( array_filter( (array) ( $data['projects'] ?? array() ), 'is_array' ) ), 0, 20 );
		$h    = '<div class="gend-society-card gs-hub-card gs-hub-projects"><h2>' . esc_html__( 'Projects', 'gend-society' ) . '</h2>';
		if ( ! $rows ) {
			return $h . '<p>' . esc_html__( 'No projects yet.', 'gend-society' ) . '</p></div>';
		}
		$h .= '<table class="widefat striped gs-hub-table"><thead><tr><th scope="col">' . esc_html__( 'Project', 'gend-society' ) . '</th><th scope="col">' . esc_html__( 'Status', 'gend-society' ) . '</th><th scope="col">' . esc_html__( 'Due', 'gend-society' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Link', 'gend-society' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			$status = str_replace( array( '_', '-' ), ' ', gend_society_hub_embed_str( $p['status'] ?? '' ) );
			$due    = gend_society_hub_embed_date( $p['due'] ?? '' );
			$h     .= '<tr data-gs-hub-project="1">';
			$h     .= '<td class="gs-hub-project__title">' . esc_html( gend_society_hub_embed_str( $p['title'] ?? '' ) ) . '</td>';
			$h     .= '<td class="gs-hub-project__status">' . esc_html( '' !== $status ? ucfirst( $status ) : '-' ) . '</td>';
			$h     .= '<td class="gs-hub-project__due">' . esc_html( '' !== $due ? $due : '-' ) . '</td>';
			$h     .= '<td>' . gend_society_hub_embed_link( $p['url'] ?? '', __( 'Open on gend.me', 'gend-society' ) ) . '</td>';
			$h     .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_fragment' ) ) {
	/**
	 * The page body for one area as one element with the fixed id gs-hub-<area> (page and Refresh AJAX).
	 *
	 * @param string     $area  Area slug.
	 * @param bool       $force Fetch without conditions.
	 * @param array|null $vm    Plan view-model for the upgrade copy (null: read it when needed).
	 */
	function gend_society_hub_embed_fragment( string $area, bool $force = false, ?array $vm = null ): string {
		$area    = sanitize_key( $area );
		$res     = gend_society_hub_area_fetch( $area, $force );
		$connect = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?page=gs-portal-connect' );
		$h       = '<div id="' . esc_attr( 'gs-hub-' . $area ) . '" class="gs-hub-body" data-gs-hub-area="' . esc_attr( $area ) . '" data-gs-hub-state="' . esc_attr( $res['state'] ) . '"' . ( $res['stale'] ? ' data-gs-hub-stale="1"' : '' ) . '>';

		switch ( $res['state'] ) {
			case 'ok':
				if ( $res['stale'] ) {
					$h .= '<div class="notice notice-warning inline gs-hub-note" role="status"><p>' . esc_html__( 'Could not reach gend.me. Showing what was loaded before.', 'gend-society' );
					if ( $res['fetched_at'] > 0 ) {
						/* translators: %s: date and time the data was loaded. */
						$h .= ' ' . esc_html( sprintf( __( 'Last updated %s.', 'gend-society' ), date_i18n( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $res['fetched_at'] ) ) );
					}
					$h .= '</p></div>';
				}
				$fn = 'gend_society_hub_embed_render_' . str_replace( '-', '_', $area );
				$h .= function_exists( $fn ) ? call_user_func( $fn, (array) $res['data'] ) : '';
				break;
			case 'upgrade':
			case 'after_migration':
				$hint = array( 'state' => $res['state'] );
				if ( null !== $res['unlock_plan'] ) {
					$hint['unlock_plan'] = $res['unlock_plan'];
				}
				if ( is_array( $vm ) ) {
					$hint['vm'] = $vm;
				}
				$h .= function_exists( 'gend_society_feature_upgrade_render_standalone' ) ? gend_society_feature_upgrade_render_standalone( $area, $hint ) : '';
				break;
			case 'revoked':
				$h .= '<div class="gend-society-card gs-hub-card gs-hub-revoked"><div class="gs-plan-note gs-plan-note--error" role="alert"><p>';
				$h .= esc_html__( 'gend.me no longer accepts this site\'s connection, so this page cannot be shown. Reconnect to see it again.', 'gend-society' );
				$h .= '</p><p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Reconnect to gend.me', 'gend-society' ) . '</a></p></div></div>';
				break;
			case 'not_connected':
				$h .= '<div class="gend-society-card gs-hub-card"><p>' . esc_html__( 'Connect this site to gend.me to see this page.', 'gend-society' ) . '</p>';
				$h .= '<p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Connect to gend.me', 'gend-society' ) . '</a></p></div>';
				break;
			case 'unknown_area':
				$h .= '<div class="gend-society-card gs-hub-card"><p>' . esc_html__( 'gend.me does not offer this area for this site.', 'gend-society' ) . '</p></div>';
				break;
			default:
				$h .= '<div class="notice notice-warning inline gs-hub-note" role="status"><p>' . esc_html__( 'Could not reach gend.me. Try again in a moment.', 'gend-society' ) . '</p></div>';
		}
		return $h . '</div>';
	}
}

// ─── Admin pages ───────────────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'gend_society_hub_embed_register' ) ) {
	/**
	 * GenD > Groups / Feature Suite / Projects for the hub_embed areas the hub lists.
	 */
	function gend_society_hub_embed_register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$parent = function_exists( 'gend_society_welcome_page_slug' ) ? gend_society_welcome_page_slug() : 'gend-society';
		$labels = gend_society_hub_embed_areas();
		foreach ( gend_society_hub_embed_offered() as $area ) {
			$hook = add_submenu_page( $parent, $labels[ $area ], $labels[ $area ], 'manage_options', 'gs-hub-' . $area, 'gend_society_hub_embed_page_render' );
			if ( $hook ) {
				$GLOBALS['gend_society_hub_embed_hooks'][ $hook ] = $area;
			}
		}
	}
}
add_action( 'admin_menu', 'gend_society_hub_embed_register', 12 );

if ( ! function_exists( 'gend_society_hub_embed_current_area' ) ) {
	/**
	 * Area of the current gs-hub-<area> page ('' elsewhere).
	 */
	function gend_society_hub_embed_current_area(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$area = 0 === strpos( $page, 'gs-hub-' ) ? substr( $page, 7 ) : '';
		return isset( gend_society_hub_embed_areas()[ $area ] ) ? $area : '';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_page_render' ) ) {
	/**
	 * Render a hub-embed page.
	 */
	function gend_society_hub_embed_page_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		$area = gend_society_hub_embed_current_area();
		if ( '' === $area ) {
			wp_die( esc_html__( 'This page is not available.', 'gend-society' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap gend-society-plan gend-society-hub" data-gs-hub-page="' . esc_attr( $area ) . '">';
		echo '<h1>' . esc_html( gend_society_hub_embed_areas()[ $area ] ) . '</h1>';
		echo '<p class="gs-plan-toolbar gs-hub-toolbar"><button type="button" class="button" data-gs-hub-refresh="1">' . esc_html__( 'Refresh', 'gend-society' ) . '</button> ';
		echo '<span class="gs-plan-live" role="status" aria-live="polite" data-gs-plan-live="1" data-gs-hub-live="1"></span>';
		echo ' <span class="gs-hub-source">' . esc_html__( 'Hosted on gend.me', 'gend-society' ) . '</span></p>';
		echo '<div id="gs-plan-blocked" class="notice notice-warning inline gs-plan-blocked" hidden><p>';
		echo esc_html__( 'Your browser blocked the checkout window.', 'gend-society' ) . ' ';
		echo '<a href="#" target="_blank" rel="opener" data-gs-plan-blocked-link="1">' . esc_html__( 'Open checkout in a new tab', 'gend-society' ) . '</a>';
		echo '</p></div>';
		echo gend_society_hub_embed_fragment( $area ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_hub_embed_enqueue' ) ) {
	/**
	 * Local CSS/JS on the hub-embed pages only (filemtime versions). gs-plan.js drives the Choose popup of the
	 * upgrade state; gs-hub-embed.js the Refresh button.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	function gend_society_hub_embed_enqueue( $hook_suffix ): void {
		$hooks = isset( $GLOBALS['gend_society_hub_embed_hooks'] ) ? (array) $GLOBALS['gend_society_hub_embed_hooks'] : array();
		if ( ! isset( $hooks[ $hook_suffix ] ) ) {
			return;
		}
		$area = (string) $hooks[ $hook_suffix ];
		if ( function_exists( 'gend_society_plan_assets_enqueue' ) ) {
			gend_society_plan_assets_enqueue( 'hub', $area );
		}
		$dir = defined( 'GEND_SOCIETY_DIR' ) ? GEND_SOCIETY_DIR : plugin_dir_path( dirname( __DIR__ ) . '/gend-society.php' );
		$url = defined( 'GEND_SOCIETY_URL' ) ? GEND_SOCIETY_URL : plugin_dir_url( dirname( __DIR__ ) . '/gend-society.php' );
		$ver = defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '1';
		$css = 'assets/gs-hub-embed.css';
		$js  = 'assets/gs-hub-embed.js';
		wp_enqueue_style( 'gend-society-hub-embed', $url . $css, array(), file_exists( $dir . $css ) ? $ver . '.' . filemtime( $dir . $css ) : $ver );
		wp_enqueue_script( 'gend-society-hub-embed', $url . $js, array(), file_exists( $dir . $js ) ? $ver . '.' . filemtime( $dir . $js ) : $ver, true );
		wp_localize_script(
			'gend-society-hub-embed',
			'gendSocietyHubEmbed',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gs_plan' ),
				'area'    => $area,
				'i18n'    => array(
					'refreshing' => __( 'Loading the latest from gend.me…', 'gend-society' ),
					'refreshed'  => __( 'Updated.', 'gend-society' ),
					'failed'     => __( 'Could not refresh. Try again in a moment.', 'gend-society' ),
				),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'gend_society_hub_embed_enqueue' );

if ( ! function_exists( 'gend_society_hub_embed_refresh_fragment' ) ) {
	/**
	 * gend_society_plan_refresh_html: a hub page's fragment (fetched without conditions) when the Refresh AJAX
	 * comes from that page.
	 *
	 * @param mixed $html Fragments so far.
	 * @param mixed $vm   Fresh view-model.
	 */
	function gend_society_hub_embed_refresh_fragment( $html, $vm ): array {
		$html = is_array( $html ) ? $html : array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the Refresh AJAX checked its nonce + capability.
		$view = isset( $_POST['gs_view'] ) ? sanitize_key( wp_unslash( (string) $_POST['gs_view'] ) ) : '';
		$area = isset( $_POST['gs_area'] ) ? sanitize_key( wp_unslash( (string) $_POST['gs_area'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'hub' === $view && isset( gend_society_hub_embed_areas()[ $area ] ) ) {
			$html[ 'gs-hub-' . $area ] = gend_society_hub_embed_fragment( $area, true, is_array( $vm ) ? $vm : null );
		}
		return $html;
	}
}
add_filter( 'gend_society_plan_refresh_html', 'gend_society_hub_embed_refresh_fragment', 20, 2 );
