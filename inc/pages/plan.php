<?php
/**
 * GenD > Plan & features (standalone installs, Phase 109).
 *
 * Manifest: tier customer, needs ['standalone'], loaded after inc/pages/welcome.php, so nothing here runs on
 * the hub or on gend.me containers.
 *
 * The page renders from gend_society_plan_view_model() (inc/plan-access.php, loaded only once the install is
 * paired): the membership card (plan, price, renewal, status badge), the inherited-plan banner, the Feature
 * Access card (Included / Upgrade / After migration) and the plan comparison with "Choose <plan>" buttons.
 *
 * Checkout: every Choose button is a plain link to the same-origin admin-post launcher
 * (gend_society_plan_launcher_url()), opened by assets/gs-plan.js in a popup synchronously from the click
 * (a new tab without JavaScript). The launcher redirects to the gend.me checkout server side, so the install
 * token never reaches a URL or this page. When the popup reports back (an origin-checked
 * gend_society_plan_changed message) or closes, the Refresh AJAX (gend_society_plan_refresh) drops the plan
 * caches and returns the cards again through the gend_society_plan_refresh_html filter below; the page swaps
 * them in without a reload.
 *
 * Local assets only (assets/gs-plan.css, assets/gs-plan.js), no inline script or style, no iframe, and no
 * gend.me request while the install is not connected or the owner has not consented (the data layer checks
 * both before any request).
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_plan_page_slug' ) ) {
	/**
	 * Admin page slug of Plan & features.
	 */
	function gend_society_plan_page_slug(): string {
		return 'gs-plan';
	}
}

if ( ! function_exists( 'gend_society_plan_page_url' ) ) {
	/**
	 * Admin URL of Plan & features.
	 */
	function gend_society_plan_page_url(): string {
		return admin_url( 'admin.php?page=' . gend_society_plan_page_slug() );
	}
}

if ( ! function_exists( 'gend_society_plan_page_available' ) ) {
	/**
	 * True when the install is paired and the plan data layer is loaded.
	 */
	function gend_society_plan_page_available(): bool {
		return function_exists( 'gend_society_plan_view_model' ) && '' !== (string) get_option( 'gend_society_install_token', '' );
	}
}

if ( ! function_exists( 'gend_society_plan_page_register' ) ) {
	/**
	 * Register GenD > Plan & features (after the GenD page itself).
	 */
	function gend_society_plan_page_register(): void {
		$parent = function_exists( 'gend_society_welcome_page_slug' ) ? gend_society_welcome_page_slug() : 'gend-society';
		$hook   = add_submenu_page(
			$parent,
			__( 'Plan & features', 'gend-society' ),
			__( 'Plan & features', 'gend-society' ),
			'manage_options',
			gend_society_plan_page_slug(),
			'gend_society_plan_page_render'
		);
		if ( $hook ) {
			$GLOBALS['gend_society_plan_page_hook'] = $hook;
		}
	}
}
add_action( 'admin_menu', 'gend_society_plan_page_register', 11 );

if ( ! function_exists( 'gend_society_plan_page_origin' ) ) {
	/**
	 * The hub origin exactly as a browser reports it in MessageEvent.origin (default ports dropped).
	 */
	function gend_society_plan_page_origin(): string {
		$origin = function_exists( 'gend_society_plan_hub_origin' ) ? gend_society_plan_hub_origin() : '';
		return (string) preg_replace( array( '#^(http://[^/:]+):80$#', '#^(https://[^/:]+):443$#' ), '$1', $origin );
	}
}

if ( ! function_exists( 'gend_society_plan_page_enqueue' ) ) {
	/**
	 * Local stylesheet + script on this page only. Configuration goes through wp_localize_script.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	function gend_society_plan_page_enqueue( $hook_suffix ): void {
		$page_hook = isset( $GLOBALS['gend_society_plan_page_hook'] ) ? (string) $GLOBALS['gend_society_plan_page_hook'] : '';
		if ( '' === $page_hook || $hook_suffix !== $page_hook ) {
			return;
		}
		$dir = defined( 'GEND_SOCIETY_DIR' ) ? GEND_SOCIETY_DIR : plugin_dir_path( dirname( __DIR__ ) . '/gend-society.php' );
		$url = defined( 'GEND_SOCIETY_URL' ) ? GEND_SOCIETY_URL : plugin_dir_url( dirname( __DIR__ ) . '/gend-society.php' );
		$ver = defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '1';
		$css = 'assets/gs-plan.css';
		$js  = 'assets/gs-plan.js';
		wp_enqueue_style( 'gend-society-plan', $url . $css, array(), file_exists( $dir . $css ) ? $ver . '.' . filemtime( $dir . $css ) : $ver );
		if ( ! gend_society_plan_page_available() ) {
			return;
		}
		wp_enqueue_script( 'gend-society-plan', $url . $js, array(), file_exists( $dir . $js ) ? $ver . '.' . filemtime( $dir . $js ) : $ver, true );
		wp_localize_script(
			'gend-society-plan',
			'gendSocietyPlan',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'gs_plan' ),
				'hubOrigin' => gend_society_plan_page_origin(),
				'installId' => (string) get_option( 'gend_society_install_id', '' ),
				'i18n'      => array(
					'refreshing' => __( 'Checking your plan with gend.me…', 'gend-society' ),
					'refreshed'  => __( 'Plan details updated.', 'gend-society' ),
					'failed'     => __( 'Could not refresh the plan details. Try again in a moment.', 'gend-society' ),
					'waiting'    => __( 'Finish the checkout in the window that opened. This page updates when it closes.', 'gend-society' ),
				),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'gend_society_plan_page_enqueue' );

// ─── Fragments (each returns one element with a fixed id; the Refresh AJAX returns the same HTML) ────────

if ( ! function_exists( 'gend_society_plan_format_date' ) ) {
	/**
	 * A hub date (Y-m-d or Y-m-d H:i:s) in the site's date format; '' when it does not parse.
	 *
	 * @param string $date Hub date.
	 */
	function gend_society_plan_format_date( string $date ): string {
		$ts = '' !== $date ? strtotime( $date ) : false;
		return $ts ? date_i18n( (string) get_option( 'date_format', 'F j, Y' ), $ts ) : '';
	}
}

if ( ! function_exists( 'gend_society_plan_status_text' ) ) {
	/**
	 * Badge text: the hub's status label, else a readable status.
	 *
	 * @param array $plan View-model plan.
	 */
	function gend_society_plan_status_text( array $plan ): string {
		if ( '' !== (string) $plan['status_label'] ) {
			return (string) $plan['status_label'];
		}
		$map = array(
			'active'    => __( 'Active', 'gend-society' ),
			'trialing'  => __( 'Trialing', 'gend-society' ),
			'on-hold'   => __( 'On hold', 'gend-society' ),
			'pending'   => __( 'Pending', 'gend-society' ),
			'expired'   => __( 'Expired', 'gend-society' ),
			'cancelled' => __( 'Cancelled', 'gend-society' ),
		);
		$s   = (string) $plan['status'];
		return $map[ $s ] ?? ( '' !== $s ? ucfirst( $s ) : __( 'Active', 'gend-society' ) );
	}
}

if ( ! function_exists( 'gend_society_plan_has_checkout' ) ) {
	/**
	 * True when at least one tier can be bought from this page now.
	 *
	 * @param array $vm View-model.
	 */
	function gend_society_plan_has_checkout( array $vm ): bool {
		foreach ( (array) $vm['tiers'] as $t ) {
			if ( ! empty( $t['checkout_allowed'] ) && '' !== (string) $t['launcher_url'] ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'gend_society_plan_render_connect_card' ) ) {
	/**
	 * Not connected: one card pointing at the Connect page (no remote call).
	 */
	function gend_society_plan_render_connect_card(): string {
		$connect = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?page=gs-portal-connect' );
		$h       = '<div id="gs-plan-membership" class="gend-society-card gs-plan-card gs-plan-card--connect">';
		$h      .= '<h2>' . esc_html__( 'Connect to gend.me to see plans', 'gend-society' ) . '</h2>';
		$h      .= '<p>' . esc_html__( 'Plans and hub features are shown once this site is connected to gend.me. Nothing is sent to gend.me until you connect.', 'gend-society' ) . '</p>';
		$h      .= '<p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Connect to gend.me', 'gend-society' ) . '</a></p>';
		$h      .= '</div>';
		return $h;
	}
}

if ( ! function_exists( 'gend_society_plan_render_membership_card' ) ) {
	/**
	 * Membership card: plan, price, renewal, status badge, owner line, processing and connection notes.
	 *
	 * @param array $vm View-model.
	 */
	function gend_society_plan_render_membership_card( array $vm ): string {
		if ( 'not_connected' === $vm['state'] ) {
			return gend_society_plan_render_connect_card();
		}
		$connect = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?page=gs-portal-connect' );
		$plan    = $vm['plan'];
		$classes = 'gend-society-card gs-plan-card gs-plan-membership is-' . sanitize_html_class( (string) $vm['state'] );

		$h  = '<div id="gs-plan-membership" class="' . esc_attr( $classes ) . '" data-gs-plan-state="' . esc_attr( (string) $vm['state'] ) . '" data-gs-plan-slug="' . esc_attr( (string) $plan['slug'] ) . '">';
		$h .= '<h2>' . esc_html__( 'Your plan', 'gend-society' ) . '</h2>';

		if ( 'revoked' === $vm['state'] ) {
			$h .= '<div class="gs-plan-note gs-plan-note--error" role="alert"><p>';
			$h .= esc_html__( 'gend.me no longer accepts this site\'s connection, so the plan cannot be shown. Reconnect to see your plan again.', 'gend-society' );
			$h .= '</p><p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Reconnect to gend.me', 'gend-society' ) . '</a></p></div>';
			$h .= '</div>';
			return $h;
		}
		if ( 'unreachable' === $vm['state'] || ! empty( $vm['stale'] ) ) {
			$h .= '<div class="gs-plan-note gs-plan-note--warning" role="status"><p>';
			$h .= esc_html__( 'Could not reach gend.me. The details below may be out of date.', 'gend-society' );
			$fetched = (int) $vm['fetched_at'];
			if ( $fetched > 0 ) {
				$h .= ' ' . esc_html(
					sprintf(
						/* translators: %s: date and time the plan details were last fetched. */
						__( 'Last updated %s.', 'gend-society' ),
						date_i18n( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $fetched )
					)
				);
			}
			$h .= '</p></div>';
		}
		if ( '' === (string) $plan['name'] && '' === (string) $plan['slug'] ) {
			$h .= '<p>' . esc_html__( 'No plan details are available yet.', 'gend-society' ) . '</p></div>';
			return $h;
		}

		$is_connected = 'connected' === (string) $plan['slug'] || ( ! empty( $plan['is_free'] ) && 'connected' === (string) $vm['source'] );
		$name         = $is_connected ? __( 'Connected', 'gend-society' ) : (string) $plan['name'];
		if ( 'group' === $vm['source'] && is_array( $vm['inherited'] ) ) {
			$price = __( 'Included with your group\'s plan', 'gend-society' );
		} elseif ( ! empty( $plan['is_free'] ) ) {
			$price = __( 'Free', 'gend-society' );
		} else {
			$price = (string) $plan['price_label'];
		}
		$renew_date = gend_society_plan_format_date( (string) $plan['next_renewal'] );
		if ( ! empty( $plan['is_lifetime'] ) || '' === $renew_date ) {
			$renewal = __( 'No renewal', 'gend-society' );
		} else {
			/* translators: %s: next renewal date. */
			$renewal = sprintf( __( 'Renews %s', 'gend-society' ), $renew_date );
		}
		$status = gend_society_plan_status_text( $plan );

		$h .= '<div class="gs-plan-summary">';
		$h .= '<span class="gs-plan-name" data-gs-plan-field="name">' . esc_html( $name ) . '</span>';
		$h .= '<span class="gs-plan-sep" aria-hidden="true"> - </span>';
		$h .= '<span class="gs-plan-price" data-gs-plan-field="price">' . esc_html( $price ) . '</span>';
		$h .= '<span class="gs-plan-sep" aria-hidden="true"> - </span>';
		$h .= '<span class="gs-plan-renewal" data-gs-plan-field="renewal">' . esc_html( $renewal ) . '</span>';
		$h .= '<span class="gs-plan-sep" aria-hidden="true"> - </span>';
		$h .= '<span class="gs-plan-badge is-' . esc_attr( sanitize_html_class( '' !== (string) $plan['status'] ? (string) $plan['status'] : 'active' ) ) . '" data-gs-plan-field="status">' . esc_html( $status ) . '</span>';
		$h .= '</div>';

		if ( ! empty( $vm['processing'] ) ) {
			$h .= '<div class="gs-plan-note gs-plan-note--processing" role="status" data-gs-plan-processing="1"><p>';
			$h .= esc_html__( 'Payment processing - this can take a minute.', 'gend-society' );
			$h .= ' <button type="button" class="button button-secondary" data-gs-plan-refresh="1">' . esc_html__( 'Refresh', 'gend-society' ) . '</button>';
			$h .= '</p></div>';
		}

		if ( gend_society_plan_has_checkout( $vm ) ) {
			if ( '' !== (string) $vm['owner_name'] ) {
				$h .= '<p class="gs-plan-owner" data-gs-plan-field="owner">' . esc_html(
					sprintf(
						/* translators: %s: display name of the gend.me account that owns this site's membership. */
						__( 'Plan changes are paid from %s\'s gend.me account. They need to be signed in to gend.me in the window that opens.', 'gend-society' ),
						(string) $vm['owner_name']
					)
				) . '</p>';
			}
			$h .= '<p><a class="button button-primary" href="#gs-plan-compare">' . esc_html__( 'Choose a plan', 'gend-society' ) . '</a></p>';
		}
		$h .= '</div>';
		return $h;
	}
}

if ( ! function_exists( 'gend_society_plan_render_inherit_banner' ) ) {
	/**
	 * "Your group <group> already has <plan> - you inherit it." (empty placeholder when not inherited).
	 *
	 * @param array $vm View-model.
	 */
	function gend_society_plan_render_inherit_banner( array $vm ): string {
		if ( 'ok' !== $vm['state'] && 'unreachable' !== $vm['state'] ) {
			return '<div id="gs-plan-inherit" class="gs-plan-inherit" hidden></div>';
		}
		$inh = is_array( $vm['inherited'] ) ? $vm['inherited'] : null;
		if ( ! $inh || 'group' !== $vm['source'] ) {
			return '<div id="gs-plan-inherit" class="gs-plan-inherit" hidden></div>';
		}
		$group = '' !== (string) $inh['group_name'] ? (string) $inh['group_name'] : __( 'your group', 'gend-society' );
		$plan  = '' !== (string) $inh['plan_name'] ? (string) $inh['plan_name'] : (string) $vm['plan']['name'];
		$h     = '<div id="gs-plan-inherit" class="gs-plan-inherit notice notice-info inline"><p data-gs-plan-field="inherit">';
		$h    .= esc_html(
			sprintf(
				/* translators: 1: group name, 2: plan name. */
				__( 'Your group %1$s already has %2$s - you inherit it.', 'gend-society' ),
				$group,
				$plan
			)
		);
		$h    .= '</p></div>';
		return $h;
	}
}

if ( ! function_exists( 'gend_society_plan_render_areas_card' ) ) {
	/**
	 * Feature Access: every area as Included / Upgrade (with the unlocking plan) / After migration.
	 *
	 * @param array $vm View-model.
	 */
	function gend_society_plan_render_areas_card( array $vm ): string {
		if ( 'ok' !== $vm['state'] && 'unreachable' !== $vm['state'] ) {
			return '<div id="gs-plan-areas" class="gs-plan-areas" hidden></div>';
		}
		$groups = array(
			'included'        => array(),
			'upgrade'         => array(),
			'after_migration' => array(),
		);
		foreach ( (array) $vm['areas'] as $a ) {
			$state = isset( $groups[ $a['state'] ] ) ? $a['state'] : 'after_migration';
			$groups[ $state ][] = $a;
		}
		$titles = array(
			'included'        => __( 'Included', 'gend-society' ),
			'upgrade'         => __( 'Upgrade', 'gend-society' ),
			'after_migration' => __( 'After migration', 'gend-society' ),
		);

		$h  = '<div id="gs-plan-areas" class="gend-society-card gs-plan-card gs-plan-areas">';
		$h .= '<h2>' . esc_html__( 'Feature Access', 'gend-society' ) . '</h2>';
		if ( ! $vm['areas'] ) {
			$h .= '<p>' . esc_html__( 'gend.me has not listed any features for this site yet.', 'gend-society' ) . '</p></div>';
			return $h;
		}
		foreach ( $groups as $state => $rows ) {
			if ( ! $rows ) {
				continue;
			}
			$h .= '<h3 class="gs-plan-areas__group">' . esc_html( $titles[ $state ] ) . '</h3>';
			$h .= '<ul class="gs-plan-areas__list" data-gs-plan-group="' . esc_attr( $state ) . '">';
			foreach ( $rows as $a ) {
				$h .= '<li class="gs-plan-area is-' . esc_attr( str_replace( '_', '-', $state ) ) . '" data-gs-plan-area="' . esc_attr( (string) $a['slug'] ) . '" data-gs-plan-area-state="' . esc_attr( $state ) . '">';
				$h .= '<span class="gs-plan-area__label">' . esc_html( (string) $a['label'] ) . '</span> ';
				if ( 'included' === $state ) {
					$text = __( 'Included - open it here', 'gend-society' );
					$h   .= '' !== (string) $a['admin_url']
						? '<a class="gs-plan-area__state" href="' . esc_url( (string) $a['admin_url'] ) . '">' . esc_html( $text ) . '</a>'
						: '<span class="gs-plan-area__state">' . esc_html__( 'Included', 'gend-society' ) . '</span>';
				} elseif ( 'upgrade' === $state ) {
					$unlock = is_array( $a['unlock_plan'] ) && '' !== (string) $a['unlock_plan']['name'] ? (string) $a['unlock_plan']['name'] : __( 'a higher plan', 'gend-society' );
					/* translators: %s: plan name that unlocks this area. */
					$text = sprintf( __( 'Available with %s', 'gend-society' ), $unlock );
					$h   .= '<a class="gs-plan-area__state" href="' . esc_url( admin_url( 'admin.php?page=gs-feature-upgrade&area=' . rawurlencode( (string) $a['slug'] ) ) ) . '">' . esc_html( $text ) . '</a>';
				} else {
					$h .= '<span class="gs-plan-area__state">' . esc_html__( 'Runs on your gend.me container after migration', 'gend-society' ) . '</span>';
				}
				if ( $a['features'] ) {
					$h .= '<span class="gs-plan-area__features">' . esc_html( implode( ', ', array_map( 'strval', (array) $a['features'] ) ) ) . '</span>';
				}
				$h .= '</li>';
			}
			$h .= '</ul>';
		}
		$h .= '</div>';
		return $h;
	}
}

if ( ! function_exists( 'gend_society_plan_render_compare' ) ) {
	/**
	 * Plan comparison: tiers as columns (by rank), areas as rows, a "Where it runs" column, Choose buttons
	 * for tiers that can be bought now, and the After migration list.
	 *
	 * @param array $vm View-model.
	 */
	function gend_society_plan_render_compare( array $vm ): string {
		if ( ( 'ok' !== $vm['state'] && 'unreachable' !== $vm['state'] ) || ! $vm['tiers'] ) {
			return '<div id="gs-plan-compare" class="gs-plan-compare" hidden></div>';
		}
		$tiers = (array) $vm['tiers'];
		usort(
			$tiers,
			function ( $a, $b ) {
				return (int) $a['rank'] <=> (int) $b['rank'];
			}
		);

		// Area rows: the feature list order, then any area only the matrix knows.
		$labels = array();
		$after  = array();
		foreach ( (array) $vm['areas'] as $a ) {
			$labels[ (string) $a['slug'] ] = (string) $a['label'];
			if ( 'container_only' === $a['delivery'] ) {
				$after[ (string) $a['slug'] ] = true;
			}
		}
		foreach ( (array) $vm['after_migration'] as $row ) {
			$after[ (string) $row['area'] ] = true;
			if ( ! isset( $labels[ (string) $row['area'] ] ) ) {
				$labels[ (string) $row['area'] ] = (string) $row['label'];
			}
		}
		foreach ( (array) $vm['matrix'] as $row ) {
			foreach ( array_keys( (array) $row ) as $area ) {
				if ( ! isset( $labels[ (string) $area ] ) ) {
					$labels[ (string) $area ] = (string) $area;
				}
			}
		}

		$h  = '<div id="gs-plan-compare" class="gend-society-card gs-plan-card gs-plan-compare">';
		$h .= '<h2>' . esc_html__( 'Compare plans', 'gend-society' ) . '</h2>';
		if ( 'connected' === (string) $vm['plan']['slug'] || ( ! empty( $vm['plan']['is_free'] ) && 'connected' === (string) $vm['source'] ) ) {
			$h .= '<p class="gs-plan-compare__current">' . esc_html__( 'You are on Connected (free). Groups and Feature Suite are included.', 'gend-society' ) . '</p>';
		}
		$h .= '<div class="gs-plan-compare__scroll"><table class="widefat striped gs-plan-compare__table">';
		$h .= '<thead><tr><th scope="col">' . esc_html__( 'Area', 'gend-society' ) . '</th>';
		foreach ( $tiers as $t ) {
			$cls = 'gs-plan-compare__tier';
			$tag = '';
			// A plan the group holds is also this site's effective plan: label it as inherited.
			if ( ! empty( $t['is_inherited'] ) ) {
				$cls .= ' is-inherited';
				$tag  = __( 'Inherited from your group', 'gend-society' );
			} elseif ( ! empty( $t['is_current'] ) ) {
				$cls .= ' is-current';
				$tag  = __( 'Current plan', 'gend-society' );
			}
			$h .= '<th scope="col" class="' . esc_attr( $cls ) . '" data-gs-plan-tier="' . esc_attr( (string) $t['slug'] ) . '">';
			$h .= '<span class="gs-plan-compare__name">' . esc_html( (string) $t['name'] ) . '</span>';
			if ( '' !== (string) $t['price_label'] ) {
				$h .= '<span class="gs-plan-compare__price">' . esc_html( (string) $t['price_label'] ) . '</span>';
			}
			if ( '' !== $tag ) {
				$h .= '<span class="gs-plan-compare__tag">' . esc_html( $tag ) . '</span>';
			}
			$h .= '</th>';
		}
		$h .= '<th scope="col" class="gs-plan-compare__where">' . esc_html__( 'Where it runs', 'gend-society' ) . '</th>';
		$h .= '</tr></thead><tbody>';
		foreach ( $labels as $slug => $label ) {
			$h .= '<tr data-gs-plan-row="' . esc_attr( (string) $slug ) . '"><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $tiers as $t ) {
				$cell = $vm['matrix'][ (string) $t['slug'] ][ (string) $slug ] ?? 'no';
				$cls  = 'gs-plan-compare__cell ' . ( 'included' === $cell ? 'is-included' : 'is-no' );
				if ( ! empty( $t['is_inherited'] ) ) {
					$cls .= ' is-inherited';
				} elseif ( ! empty( $t['is_current'] ) ) {
					$cls .= ' is-current';
				}
				$h .= '<td class="' . esc_attr( $cls ) . '" data-gs-plan-cell="' . esc_attr( 'included' === $cell ? 'included' : 'no' ) . '">';
				$h .= 'included' === $cell
					? '<span aria-hidden="true">&#10003;</span><span class="screen-reader-text">' . esc_html__( 'Included', 'gend-society' ) . '</span>'
					: '<span aria-hidden="true">-</span><span class="screen-reader-text">' . esc_html__( 'Not included', 'gend-society' ) . '</span>';
				$h .= '</td>';
			}
			$where = isset( $after[ (string) $slug ] ) ? __( 'After migration', 'gend-society' ) : __( 'On gend.me now', 'gend-society' );
			$h    .= '<td class="gs-plan-compare__where" data-gs-plan-where="' . esc_attr( isset( $after[ (string) $slug ] ) ? 'after_migration' : 'hub' ) . '">' . esc_html( $where ) . '</td>';
			$h    .= '</tr>';
		}
		$h .= '</tbody><tfoot><tr><td></td>';
		foreach ( $tiers as $t ) {
			$h .= '<td class="gs-plan-compare__action">';
			if ( ! empty( $t['checkout_allowed'] ) && '' !== (string) $t['launcher_url'] ) {
				$h .= '<a class="button button-primary" href="' . esc_url( (string) $t['launcher_url'] ) . '" target="_blank" rel="opener" data-gs-plan-checkout="' . esc_attr( (string) (int) $t['id'] ) . '">';
				/* translators: %s: plan name. */
				$h .= esc_html( sprintf( __( 'Choose %s', 'gend-society' ), (string) $t['name'] ) ) . '</a>';
			} elseif ( ! empty( $t['is_inherited'] ) ) {
				$h .= '<span class="gs-plan-compare__tag">' . esc_html__( 'Inherited', 'gend-society' ) . '</span>';
			} elseif ( ! empty( $t['is_current'] ) ) {
				$h .= '<span class="gs-plan-compare__tag">' . esc_html__( 'Current plan', 'gend-society' ) . '</span>';
			}
			$h .= '</td>';
		}
		$h .= '<td></td></tr></tfoot></table></div>';

		if ( $vm['after_migration'] ) {
			$h .= '<h3 class="gs-plan-compare__after-title">' . esc_html__( 'After migration', 'gend-society' ) . '</h3>';
			$h .= '<p>' . esc_html__( 'These areas run on your gend.me container once this site is migrated there. Your plan decides which ones are switched on.', 'gend-society' ) . '</p>';
			$h .= '<ul class="gs-plan-compare__after" data-gs-plan-after="1">';
			foreach ( (array) $vm['after_migration'] as $row ) {
				$h .= '<li data-gs-plan-after-area="' . esc_attr( (string) $row['area'] ) . '"><strong>' . esc_html( (string) $row['label'] ) . '</strong>';
				if ( $row['features'] ) {
					$h .= ': ' . esc_html( implode( ', ', array_map( 'strval', (array) $row['features'] ) ) );
				}
				$h .= '</li>';
			}
			$h .= '</ul>';
		}
		$h .= '</div>';
		return $h;
	}
}

if ( ! function_exists( 'gend_society_plan_fragments' ) ) {
	/**
	 * Every card of the page by element id (the page body and the Refresh AJAX use the same HTML).
	 *
	 * @param array $vm View-model.
	 * @return array<string,string>
	 */
	function gend_society_plan_fragments( array $vm ): array {
		return array(
			'gs-plan-inherit'    => gend_society_plan_render_inherit_banner( $vm ),
			'gs-plan-membership' => gend_society_plan_render_membership_card( $vm ),
			'gs-plan-areas'      => gend_society_plan_render_areas_card( $vm ),
			'gs-plan-compare'    => gend_society_plan_render_compare( $vm ),
		);
	}
}

if ( ! function_exists( 'gend_society_plan_refresh_fragments' ) ) {
	/**
	 * gend_society_plan_refresh_html filter: add this page's cards to the Refresh AJAX answer.
	 *
	 * @param mixed $html Fragments collected so far.
	 * @param mixed $vm   Fresh view-model.
	 */
	function gend_society_plan_refresh_fragments( $html, $vm ): array {
		$html = is_array( $html ) ? $html : array();
		if ( ! is_array( $vm ) || ! isset( $vm['state'], $vm['plan'] ) ) {
			return $html;
		}
		return array_merge( $html, gend_society_plan_fragments( $vm ) );
	}
}
add_filter( 'gend_society_plan_refresh_html', 'gend_society_plan_refresh_fragments', 10, 2 );

if ( ! function_exists( 'gend_society_plan_page_render' ) ) {
	/**
	 * Render GenD > Plan & features.
	 */
	function gend_society_plan_page_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap gend-society-plan">';
		echo '<h1>' . esc_html__( 'Plan & features', 'gend-society' ) . '</h1>';
		if ( ! gend_society_plan_page_available() ) {
			echo gend_society_plan_render_connect_card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
			echo '</div>';
			return;
		}
		$vm = gend_society_plan_view_model();
		if ( 'not_connected' === $vm['state'] ) {
			echo gend_society_plan_render_connect_card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
			echo '</div>';
			return;
		}
		echo '<p class="gs-plan-toolbar"><button type="button" class="button" data-gs-plan-refresh="1">' . esc_html__( 'Refresh', 'gend-society' ) . '</button> ';
		echo '<span class="gs-plan-live" role="status" aria-live="polite" data-gs-plan-live="1"></span></p>';
		echo '<div id="gs-plan-blocked" class="notice notice-warning inline gs-plan-blocked" hidden><p>';
		echo esc_html__( 'Your browser blocked the checkout window.', 'gend-society' ) . ' ';
		echo '<a href="#" target="_blank" rel="opener" data-gs-plan-blocked-link="1">' . esc_html__( 'Open checkout in a new tab', 'gend-society' ) . '</a>';
		echo '</p></div>';
		foreach ( gend_society_plan_fragments( $vm ) as $fragment ) {
			echo $fragment; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		}
		echo '</div>';
	}
}
