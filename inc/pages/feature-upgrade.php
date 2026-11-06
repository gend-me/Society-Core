<?php
/**
 * Feature Access upgrade prompt page.
 *
 * Customers hit this page when they try to access a wp-admin area
 * their current Dashboard plan doesn't include (Content/Store/Social/
 * Reward/etc.). The page explains the gap, lists the available
 * upgrade tiers, and lets them complete the upgrade in a popup
 * window pointed at gend.me's checkout (?ui=embed). When the popup
 * closes, we invalidate the feature cache + reload back to whichever
 * page they originally tried to reach.
 *
 * Phase 109: the "learn more" page is an outbound link (new tab), never
 * an iframe, in every mode (D12), and the page no longer listens for the
 * unchecked legacy membership-changed message (popup close is the signal).
 * Standalone installs get gend_society_feature_upgrade_page_standalone()
 * instead: copy from the plan view-model (Upgrade vs After migration),
 * gend.me's own Change Plan page in a popup (assets/gs-plan.js) and Refresh.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register the upgrade page as a hidden submenu under Dashboard.
 * Hidden = doesn't appear in the menu, but admin.php?page=… still
 * routes to it.
 */
add_action( 'admin_menu', function () {
    add_submenu_page(
        '',                    // empty parent = hidden
        __( 'Upgrade Feature Access', 'gend-society' ),
        __( 'Upgrade Feature Access', 'gend-society' ),
        'read',
        'gs-feature-upgrade',
        'gend_society_render_feature_upgrade_page'
    );
}, 60 );

/**
 * Pretty area name for the prompt copy.
 */
function gend_society_feature_area_label( string $area ): string {
    $map = array(
        'app'      => __( 'App', 'gend-society' ),
        'write'    => __( 'Content', 'gend-society' ),
        'store'    => __( 'Store', 'gend-society' ),
        'social'   => __( 'Social', 'gend-society' ),
        'reward'   => __( 'Rewards', 'gend-society' ),
        'features' => __( 'Features', 'gend-society' ),
        'hosting'  => __( 'Hosting', 'gend-society' ),
        'projects' => __( 'Projects', 'gend-society' ),
        'groups'   => __( 'Groups', 'gend-society' ),
    );
    return $map[ $area ] ?? ucfirst( $area );
}

/**
 * Built-in tier definitions. Each tier lists its name, the areas
 * it unlocks, and the dashboard-plan slug on gend.me. The
 * fetched feature payload already contains the customer's CURRENT
 * tier; we use this list to show what's available.
 */
function gend_society_feature_upgrade_tiers() {
    return apply_filters( 'gend_society_feature_upgrade_tiers', array(
        array(
            'slug'     => 'content-builder',
            'name'     => __( 'Content Builder', 'gend-society' ),
            'tagline'  => __( 'Run a content-driven site with editorial tools.', 'gend-society' ),
            'areas'    => array( 'app', 'write' ),
            'menu_label' => __( 'Dashboard, Users, App, Content', 'gend-society' ),
        ),
        array(
            'slug'     => 'store-owner',
            'name'     => __( 'Store Owner', 'gend-society' ),
            'tagline'  => __( 'Sell products and digital goods directly from your app.', 'gend-society' ),
            'areas'    => array( 'app', 'write', 'store' ),
            'menu_label' => __( 'Dashboard, Users, App, Content, Store', 'gend-society' ),
        ),
        array(
            'slug'     => 'social-connector',
            'name'     => __( 'Social Connector', 'gend-society' ),
            'tagline'  => __( 'Build community + memberships on top of everything else.', 'gend-society' ),
            'areas'    => array( 'app', 'write', 'store', 'social' ),
            'menu_label' => __( 'Dashboard, Users, App, Content, Store, Social', 'gend-society' ),
        ),
    ) );
}

function gend_society_render_feature_upgrade_page() {

    // Phase 109 (standalone installs): one upgrade page rendered from the plan view-model (Upgrade vs After
    // migration copy, popup checkout through gs-plan.js, Refresh). Hub and container output continues below.
    if ( function_exists( 'gend_society_runtime_mode' ) && 'standalone' === gend_society_runtime_mode() ) {
        gend_society_feature_upgrade_page_standalone();
        return;
    }

    if ( ! current_user_can( 'read' ) ) wp_die( esc_html__( 'Sign in required.', 'gend-society' ) );

    $required = isset( $_GET['required'] ) ? sanitize_key( (string) $_GET['required'] ) : '';
    $from     = isset( $_GET['from'] )     ? sanitize_key( (string) $_GET['from'] )     : '';
    $tab      = isset( $_GET['tab'] )      ? sanitize_key( (string) $_GET['tab'] )      : '';
    // sanitize_key() strips dots, so it can't be used on required_child
    // (dotted area.child form, e.g. "store.sales_team") without corrupting
    // it - matches the character set gdc_admin_menu_hierarchy() keys use.
    $required_child = isset( $_GET['required_child'] )
        ? preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) wp_unslash( $_GET['required_child'] ) ) )
        : '';
    $features = function_exists( 'gend_society_features_get_cached' ) ? gend_society_features_get_cached() : null;
    $current_areas = is_array( $features ) && isset( $features['allowed_areas'] ) ? (array) $features['allowed_areas'] : array();
    $current_plan  = is_array( $features ) && ! empty( $features['plan_name'] ) ? (string) $features['plan_name'] : '';
    $learn_more_pages = is_array( $features ) && isset( $features['learn_more_pages'] ) ? (array) $features['learn_more_pages'] : array();
    $learn_more_url = function_exists( 'gend_society_features_resolve_learn_more_url' )
        ? gend_society_features_resolve_learn_more_url( $required_child, $tab, $learn_more_pages )
        : '';

    // Membership URL on gend.me — the popup target. ?ui=embed strips
    // the theme chrome so the popup looks like a focused modal.
    $hub        = function_exists( 'gend_society_oauth_hub_url' ) ? gend_society_oauth_hub_url() : (string) get_option( 'gend_society_gend_base_url', 'https://gend.me' );
    $remote_mid = 0;
    if ( function_exists( 'gend_society_remote_membership_get_cached' ) ) {
        $rm = gend_society_remote_membership_get_cached();
        if ( is_array( $rm ) && ! empty( $rm['membership_id'] ) ) $remote_mid = (int) $rm['membership_id'];
    }
    $membership_url = $remote_mid > 0
        ? rtrim( $hub, '/' ) . '/my-account/membership/' . $remote_mid . '/?ui=embed&group=dashboard'
        : rtrim( $hub, '/' ) . '/my-account/memberships/';

    $tiers = gend_society_feature_upgrade_tiers();

    $back_url = $from !== ''
        ? add_query_arg( 'page', $from, admin_url( 'admin.php' ) )
        : admin_url();
    ?>
    <div class="wrap">

        <style>
            .gs-up-shell { max-width: 1100px; margin: 24px 0; padding: 36px; background: rgba(11,14,20,0.6); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; backdrop-filter: blur(20px); color: #fff; }
            .gs-up-shell h1 { color: #fff; font-size: 1.8rem; font-weight: 800; margin: 0 0 8px; }
            .gs-up-shell p.lead { color: rgba(255,255,255,0.7); font-size: 1rem; margin: 0 0 24px; max-width: 720px; }
            .gs-up-current { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 999px; background: rgba(255,180,0,0.12); border: 1px solid rgba(255,180,0,0.35); color: #ffd166; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }
            .gs-up-learn-more-embed { margin-bottom: 24px; border-radius: 14px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); }
            .gs-up-learn-more-embed iframe { display: block; width: 100%; height: 480px; border: 0; background: #0b0e14; }
            .gs-up-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-top: 18px; }
            .gs-up-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 24px; display: flex; flex-direction: column; gap: 12px; transition: transform 0.18s, border-color 0.18s; }
            .gs-up-card.is-recommended { border-color: rgba(78,170,255,0.5); box-shadow: 0 0 30px -8px rgba(78,170,255,0.4); }
            .gs-up-card.is-current { opacity: 0.6; }
            .gs-up-card:hover { transform: translateY(-2px); border-color: rgba(78,170,255,0.5); }
            .gs-up-card h2 { color: #fff; margin: 0; font-size: 1.2rem; font-weight: 800; }
            .gs-up-tagline { color: rgba(255,255,255,0.6); font-size: 0.85rem; margin: 0; }
            .gs-up-areas { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
            .gs-up-areas span { padding: 4px 10px; border-radius: 999px; background: rgba(78,170,255,0.12); border: 1px solid rgba(78,170,255,0.3); color: #4eaaff; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
            .gs-up-areas span.is-locked { background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1); color: rgba(255,255,255,0.5); }
            .gs-up-cta { margin-top: auto; padding: 12px 18px; border-radius: 10px; background: linear-gradient(135deg, #b608c9, #7e058a); color: #fff !important; font-size: 0.9rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; text-decoration: none; text-align: center; border: 0; cursor: pointer; }
            .gs-up-cta.is-current-cta { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: default; }
            .gs-up-back { margin-top: 28px; }
            .gs-up-back a { color: rgba(255,255,255,0.6); text-decoration: none; }
            .gs-up-back a:hover { color: #fff; }
            .gs-up-info { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; margin: 0 0 22px; color: rgba(255,255,255,0.7); font-size: 0.9rem; }
            .gs-up-info a, .gs-up-more { color: #9fd0ff; text-decoration: none; font-weight: 700; }
            .gs-up-info a { padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(78,170,255,0.35); background: rgba(78,170,255,0.1); }
            .gs-up-info a:hover, .gs-up-info a:focus, .gs-up-more:hover, .gs-up-more:focus { color: #fff; border-color: rgba(78,170,255,0.8); }
            .gs-up-more { font-size: 0.82rem; }
            @media (max-width: 700px) {
                .gs-up-shell { margin: 12px 0; padding: 20px 16px; border-radius: 16px; }
                .gs-up-shell h1 { font-size: 1.4rem; }
                .gs-up-grid { grid-template-columns: minmax(0, 1fr); }
                .gs-up-card { padding: 18px; }
            }
        </style>

        <div class="gs-up-shell">

            <?php if ( $required !== '' ) : ?>
                <span class="gs-up-current">⨯ <?php echo esc_html( sprintf( /* translators: %s: Feature area name. */ __( '%s requires an upgrade', 'gend-society' ), gend_society_feature_area_label( $required ) ) ); ?></span>
            <?php elseif ( $current_plan !== '' ) : ?>
                <span class="gs-up-current">● <?php echo esc_html( sprintf( /* translators: %s: Current plan name. */ __( 'Current plan: %s', 'gend-society' ), $current_plan ) ); ?></span>
            <?php endif; ?>

            <?php if ( $learn_more_url !== '' ) : ?>
                <div class="gs-up-learn-more-embed">
                    <a href="<?php echo esc_url( $learn_more_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'More about this upgrade', 'gend-society' ); ?></a>
                </div>
            <?php endif; ?>

            <h1><?php esc_html_e( 'Choose your Dashboard Feature plan', 'gend-society' ); ?></h1>
            <p class="lead">
                <?php
                if ( $required !== '' ) {
                    echo esc_html( sprintf(
                        /* translators: %s: Feature area name. */
                        __( 'The %s area is locked on your current plan. Upgrade to a Dashboard tier that includes it and your wp-admin will unlock instantly when checkout completes.', 'gend-society' ),
                        gend_society_feature_area_label( $required )
                    ) );
                } else {
                    esc_html_e( 'Each tier unlocks more wp-admin areas. Upgrades take effect immediately after checkout.', 'gend-society' );
                }
                ?>
            </p>

            <?php
            $gs_up_links = function_exists( 'gend_society_feature_info_links' ) ? gend_society_feature_info_links( '' !== $required_child ? $required_child : $required ) : array();
            $gs_up_plans = function_exists( 'gend_society_plan_info_urls' ) ? gend_society_plan_info_urls() : array();
            if ( $gs_up_links ) :
                ?>
                <p class="gs-up-info">
                    <span><?php esc_html_e( 'See what you unlock:', 'gend-society' ); ?></span>
                    <?php foreach ( $gs_up_links as $gs_up_name => $gs_up_url ) : ?>
                        <a href="<?php echo esc_url( $gs_up_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $gs_up_name ); ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <div class="gs-up-grid">
                <?php
                foreach ( $tiers as $tier ) :
                    $unlocks = $tier['areas'];
                    $is_current = ! empty( array_intersect( $unlocks, $current_areas ) ) && count( array_intersect( $unlocks, $current_areas ) ) === count( $unlocks );
                    $is_recommended = ! $is_current && $required !== '' && in_array( $required, $unlocks, true );
                    $card_classes = array( 'gs-up-card' );
                    if ( $is_current )     $card_classes[] = 'is-current';
                    if ( $is_recommended ) $card_classes[] = 'is-recommended';
                    ?>
                    <div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
                        <h2><?php echo esc_html( $tier['name'] ); ?></h2>
                        <p class="gs-up-tagline"><?php echo esc_html( $tier['tagline'] ); ?></p>
                        <div class="gs-up-areas">
                            <?php foreach ( $unlocks as $a ) : ?>
                                <span><?php echo esc_html( gend_society_feature_area_label( $a ) ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( ! empty( $gs_up_plans[ $tier['slug'] ] ) ) : ?>
                            <a class="gs-up-more" href="<?php echo esc_url( $gs_up_plans[ $tier['slug'] ] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Everything in this plan', 'gend-society' ); ?> &rarr;</a>
                        <?php endif; ?>
                        <?php if ( $is_current ) : ?>
                            <span class="gs-up-cta is-current-cta">✓ <?php esc_html_e( 'Your current plan', 'gend-society' ); ?></span>
                        <?php else : ?>
                            <button type="button" class="gs-up-cta" data-gs-upgrade data-tier="<?php echo esc_attr( $tier['slug'] ); ?>">
                                <?php
                                if ( $is_recommended )      esc_html_e( 'Upgrade to this tier', 'gend-society' );
                                elseif ( ! empty( $current_areas ) ) esc_html_e( 'Switch to this tier', 'gend-society' );
                                else                         esc_html_e( 'Choose this tier', 'gend-society' );
                                ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="gs-up-back">
                <a href="<?php echo esc_url( $back_url ); ?>">← <?php esc_html_e( 'Back', 'gend-society' ); ?></a>
            </div>

        </div>
    </div>

    <script>
    (function () {
        var upgradeUrl = <?php echo wp_json_encode( $membership_url ); ?>;
        var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'gs_membership_action' ) ); ?>;

        document.querySelectorAll('[data-gs-upgrade]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var w = 920, h = 760;
                var x = (window.screen.width - w) / 2;
                var y = (window.screen.height - h) / 2;
                var url = upgradeUrl + '&tier=' + encodeURIComponent(btn.dataset.tier || '');
                var popup = window.open(url, 'gs_feature_upgrade',
                    'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y + ',toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes');
                if (!popup) { alert('Popup blocked. Allow popups and try again.'); return; }

                btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Awaiting checkout…';

                function done(ok) {
                    btn.disabled = false; btn.textContent = orig;
                    if (!ok) return;
                    // Force a fresh fetch of the feature payload, then
                    // bounce back to the original page (or Dashboard).
                    var fd = new FormData();
                    fd.append('action', 'gend_society_features_refresh');
                    fd.append('nonce', nonce);
                    fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function () {
                            var back = <?php echo wp_json_encode( $back_url ); ?>;
                            window.location.href = back;
                        });
                }

                var watchdog = setInterval(function () {
                    if (popup.closed) { clearInterval(watchdog); done(true); }
                }, 600);
            });
        });
    })();
    </script>
    <?php
}

/**
 * AJAX hook the upgrade page calls after the popup closes — busts
 * both feature + membership caches and refetches.
 */
add_action( 'wp_ajax_gend_society_features_refresh', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error();
    check_ajax_referer( 'gs_membership_action', 'nonce' );
    if ( function_exists( 'gend_society_features_invalidate' ) )         gend_society_features_invalidate();
    if ( function_exists( 'gend_society_remote_membership_invalidate' ) ) gend_society_remote_membership_invalidate();
    $f = function_exists( 'gend_society_features_get_cached' ) ? gend_society_features_get_cached() : null;
    wp_send_json_success( array( 'features' => $f ) );
} );

/* ───────────────────────────────────────────────────────────────────────────────────────────────────────
 * Phase 109 (PLAN-05): the upgrade page on standalone installs.
 *
 * One page, two kinds of copy, both from gend_society_plan_view_model():
 *   - Upgrade:          "Available with <plan>" and one "Choose a plan on gend.me" button: gend.me's own
 *                       Change Plan page (view-model change_plan_url), opened by assets/gs-plan.js in a popup;
 *   - After migration:  "Runs on your gend.me container after migration" and an outbound link (new tab).
 * Included areas link to their hub page or the dashboard; the other states link to the plan on the dashboard
 * membership card (index.php#gs-mship-root, 109-30b: the Plan & features page is gone).
 * gend_society_feature_upgrade_render_standalone() also takes a hub 402 / 409 state hint (the hub-embed pages that used it went in 109-30).
 * No iframe, no inline script or style, nothing locked (wordpress.org Guideline 5).
 * ─────────────────────────────────────────────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'gend_society_feature_upgrade_request_area' ) ) {
	/**
	 * Area slug the page was opened for (?area=, else the legacy ?required=).
	 */
	function gend_society_feature_upgrade_request_area(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page arguments.
		$area = isset( $_GET['area'] ) ? sanitize_key( wp_unslash( (string) $_GET['area'] ) ) : '';
		if ( '' === $area && isset( $_GET['required'] ) ) {
			$area = sanitize_key( wp_unslash( (string) $_GET['required'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $area;
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_container_plans_url' ) ) {
	/**
	 * Container plans on the paired hub ('' when not paired or not a hub URL).
	 */
	function gend_society_feature_upgrade_container_plans_url(): string {
		if ( ! function_exists( 'gend_society_plan_identity' ) ) {
			return '';
		}
		$base = (string) gend_society_plan_identity()['base'];
		$url  = '' !== $base ? $base . '/my-account/memberships/' : '';
		return ( '' !== $url && function_exists( 'gend_society_plan_is_hub_url' ) && gend_society_plan_is_hub_url( $url ) ) ? $url : '';
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_dashboard' ) ) {
	/**
	 * One of the nine dashboards by its card key (inc/feature-cards.php), with the plan that includes it and
	 * that plan's gend.me page. Null for an unknown key.
	 *
	 * @param string $feature Card key (email, store, sales, projects, rewards, membership, ...).
	 * @return array{key:string,name:string,description:string,link:string,plan_name:string,plan_url:string,plan_is_top:bool}|null
	 */
	function gend_society_feature_upgrade_dashboard( string $feature ): ?array {
		$feature = sanitize_key( $feature );
		$defs    = function_exists( 'gend_society_get_feature_definitions' ) ? gend_society_get_feature_definitions() : array();
		if ( '' === $feature || empty( $defs[ $feature ] ) || ! is_array( $defs[ $feature ] ) ) {
			return null;
		}
		$def       = $defs[ $feature ];
		$plan      = (string) ( $def['plan'] ?? '' );
		$plan_name = '';
		$top_slug  = '';
		foreach ( gend_society_feature_upgrade_tiers() as $tier ) {
			$top_slug = (string) $tier['slug'];
			if ( $top_slug === $plan ) {
				$plan_name = (string) $tier['name'];
			}
		}
		$plan_urls = function_exists( 'gend_society_plan_info_urls' ) ? gend_society_plan_info_urls() : array();
		return array(
			'key'         => $feature,
			'name'        => (string) ( $def['name'] ?? '' ),
			'description' => (string) ( $def['description'] ?? '' ),
			'link'        => (string) ( $def['link'] ?? '' ),
			'plan_name'   => $plan_name,
			'plan_url'    => (string) ( $plan_urls[ $plan ] ?? '' ),
			'plan_is_top' => '' !== $plan && $plan === $top_slug,
		);
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_dashboard_html' ) ) {
	/**
	 * What a dashboard does, the plan that includes it and its gend.me pages. Plain outbound links (new tab):
	 * nothing is requested from gend.me until one is clicked.
	 *
	 * @param array $dash      From gend_society_feature_upgrade_dashboard().
	 * @param bool  $plan_line Also say which plan includes it (left out where the caller already names the plan).
	 */
	function gend_society_feature_upgrade_dashboard_html( array $dash, bool $plan_line = true ): string {
		$h = '';
		if ( '' !== (string) $dash['description'] ) {
			$h .= '<p class="gs-up-pitch">' . esc_html( (string) $dash['description'] ) . '</p>';
		}
		if ( $plan_line && '' !== (string) $dash['plan_name'] ) {
			$h .= '<p class="gs-up-plan">' . esc_html(
				sprintf(
					/* translators: %s: plan name. */
					! empty( $dash['plan_is_top'] ) ? __( 'Included with %s.', 'gend-society' ) : __( 'Included with %s and higher plans.', 'gend-society' ),
					(string) $dash['plan_name']
				)
			) . '</p>';
		}
		$links = '';
		if ( '' !== (string) $dash['link'] ) {
			/* translators: %s: dashboard name. */
			$links .= '<a href="' . esc_url( (string) $dash['link'] ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( __( 'See everything in %s', 'gend-society' ), (string) $dash['name'] ) ) . '</a>';
		}
		if ( '' !== (string) $dash['plan_url'] && '' !== (string) $dash['plan_name'] ) {
			/* translators: %s: plan name. */
			$links .= ' <a href="' . esc_url( (string) $dash['plan_url'] ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( __( 'What %s includes', 'gend-society' ), (string) $dash['plan_name'] ) ) . '</a>';
		}
		if ( '' !== $links ) {
			$h .= '<p class="gs-up-info">' . $links . '</p>';
		}
		return $h;
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_render_standalone' ) ) {
	/**
	 * Upgrade / After migration / Included copy for one area (standalone). Returns one escaped element
	 * without an id; callers wrap it in their own fragment.
	 *
	 * @param string $area_slug Area slug (groups, projects, store, ...).
	 * @param array  $hint      Optional: 'vm' (view-model to use), 'state' (upgrade | after_migration, e.g. from
	 *                          a hub 402 / 409 answer), 'unlock_plan' (hub value), 'feature' (dashboard card key:
	 *                          the copy then presents that dashboard instead of the whole area).
	 */
	function gend_society_feature_upgrade_render_standalone( string $area_slug, array $hint = array() ): string {
		$area_slug = sanitize_key( $area_slug );
		$dash      = isset( $hint['feature'] ) ? gend_society_feature_upgrade_dashboard( (string) $hint['feature'] ) : null;
		$plan_url  = function_exists( 'gend_society_plan_page_url' ) ? gend_society_plan_page_url() : admin_url( 'index.php' );
		$plan_card = $plan_url . '#gs-mship-root';
		$connect   = function_exists( 'gend_society_connect_url' ) ? gend_society_connect_url() : admin_url( 'index.php?page=gs-portal-connect' );
		$refresh   = '<p class="gs-up-actions__refresh"><button type="button" class="button" data-gs-plan-refresh="1">' . esc_html__( 'Refresh', 'gend-society' ) . '</button></p>';
		$vm        = isset( $hint['vm'] ) && is_array( $hint['vm'] ) ? $hint['vm'] : null;
		if ( null === $vm && function_exists( 'gend_society_plan_view_model' ) && '' !== (string) get_option( 'gend_society_install_token', '' ) ) {
			$vm = gend_society_plan_view_model();
		}

		$area = null;
		foreach ( (array) ( $vm['areas'] ?? array() ) as $a ) {
			if ( is_array( $a ) && (string) ( $a['slug'] ?? '' ) === $area_slug ) {
				$area = $a;
			}
		}
		$label = is_array( $area ) && '' !== (string) ( $area['label'] ?? '' ) ? (string) $area['label'] : ( '' !== $area_slug ? gend_society_feature_area_label( $area_slug ) : __( 'This area', 'gend-society' ) );
		if ( is_array( $dash ) && '' !== (string) $dash['name'] ) {
			$label = (string) $dash['name'];
		}

		if ( ! is_array( $vm ) || 'not_connected' === ( $vm['state'] ?? 'not_connected' ) ) {
			$h  = '<div class="gend-society-card gs-plan-card gs-up-standalone is-not-connected" data-gs-up-area="' . esc_attr( $area_slug ) . '" data-gs-up-state="not_connected">';
			$h .= '<h2>' . esc_html( $label ) . '</h2>';
			if ( is_array( $dash ) ) {
				$h .= gend_society_feature_upgrade_dashboard_html( $dash );
				$h .= '<p>' . esc_html__( 'Connect this site to gend.me to see your plan and switch this dashboard on. Nothing is sent to gend.me until you connect.', 'gend-society' ) . '</p>';
			} else {
				$h .= '<p>' . esc_html__( 'Connect this site to gend.me to see which plan includes this area. Nothing is sent to gend.me until you connect.', 'gend-society' ) . '</p>';
			}
			$h .= '<p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Connect to gend.me', 'gend-society' ) . '</a></p>';
			return $h . '</div>';
		}
		if ( 'revoked' === $vm['state'] ) {
			$h  = '<div class="gend-society-card gs-plan-card gs-up-standalone is-revoked" data-gs-up-area="' . esc_attr( $area_slug ) . '" data-gs-up-state="revoked">';
			$h .= '<h2>' . esc_html( $label ) . '</h2>';
			$h .= '<div class="gs-plan-note gs-plan-note--error" role="alert"><p>' . esc_html__( 'gend.me no longer accepts this site\'s connection, so the plan cannot be shown. Reconnect to see your plan again.', 'gend-society' ) . '</p>';
			$h .= '<p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Reconnect to gend.me', 'gend-society' ) . '</a></p></div>';
			return $h . '</div>';
		}

		$state = isset( $hint['state'] ) ? (string) $hint['state'] : ( is_array( $area ) ? (string) $area['state'] : 'unknown' );
		if ( 'after_migration' === $state ) {
			$state = 'upgrade'; // hosting never decides access: only the dashboard plan does
		}
		if ( ! in_array( $state, array( 'included', 'upgrade' ), true ) ) {
			$state = 'unknown';
		}
		$unlock = null;
		if ( array_key_exists( 'unlock_plan', $hint ) && function_exists( 'gend_society_plan_unlock' ) ) {
			$unlock = gend_society_plan_unlock( $hint['unlock_plan'], (array) ( $vm['tiers'] ?? array() ) );
		}
		if ( null === $unlock && is_array( $area ) && is_array( $area['unlock_plan'] ?? null ) ) {
			$unlock = $area['unlock_plan'];
		}

		$h  = '<div class="gend-society-card gs-plan-card gs-up-standalone is-' . esc_attr( str_replace( '_', '-', $state ) ) . '" data-gs-up-area="' . esc_attr( $area_slug ) . '" data-gs-up-state="' . esc_attr( $state ) . '">';
		$h .= '<h2>' . esc_html( $label ) . '</h2>';
		if ( 'unreachable' === $vm['state'] || ! empty( $vm['stale'] ) ) {
			$h .= '<div class="gs-plan-note gs-plan-note--warning" role="status"><p>' . esc_html__( 'Could not reach gend.me. The details below may be out of date.', 'gend-society' ) . '</p></div>';
		}

		if ( 'upgrade' === $state ) {
			$name = is_array( $unlock ) && '' !== (string) $unlock['name'] ? (string) $unlock['name'] : __( 'a higher plan', 'gend-society' );
			/* translators: %s: plan name that unlocks this area. */
			$h   .= '<p class="gs-up-state" data-gs-up-copy="upgrade">' . esc_html( sprintf( __( 'Available with %s', 'gend-society' ), $name ) ) . '</p>';
			if ( is_array( $dash ) && '' !== (string) $dash['description'] ) {
				$h .= '<p class="gs-up-pitch">' . esc_html( (string) $dash['description'] ) . '</p>';
			}
			/* translators: 1: area name, 2: plan name. */
			$h   .= '<p>' . esc_html( sprintf( __( '%1$s comes with %2$s and the plans above it. Upgrading installs and switches it on for this site; nothing else needs to move.', 'gend-society' ), $label, $name ) ) . '</p>';
			$info = ( ! is_array( $dash ) && function_exists( 'gend_society_feature_info_links' ) ) ? gend_society_feature_info_links( $area_slug ) : array();
			if ( is_array( $dash ) ) {
				// The dashboard's own gend.me page and its plan's page (the description is printed above).
				$h .= gend_society_feature_upgrade_dashboard_html( array_merge( $dash, array( 'description' => '' ) ), false );
			}
			if ( $info ) {
				$h .= '<p class="gs-up-info">' . esc_html__( 'See what you unlock:', 'gend-society' );
				foreach ( $info as $info_name => $info_url ) {
					$h .= ' <a href="' . esc_url( $info_url ) . '" target="_blank" rel="noopener">' . esc_html( $info_name ) . '</a>';
				}
				$h .= '</p>';
			}
			$ups = function_exists( 'gend_society_plan_upgrade_tiers' ) ? gend_society_plan_upgrade_tiers( $vm, is_array( $unlock ) ? $unlock : null ) : array();
			$h  .= '<p class="gs-up-actions">';
			foreach ( $ups as $i => $u ) {
				/* translators: 1: plan name, 2: price. */
				$h .= '<button type="button" class="gs-buy' . ( 0 === $i ? '' : ' gs-buy--ghost' ) . '" data-gs-buy-plan="1" data-plan-id="' . esc_attr( (string) $u['id'] ) . '">' . esc_html( sprintf( __( 'Upgrade to %1$s - %2$s', 'gend-society' ), $u['name'], $u['price_label'] ) ) . '</button> ';
			}
			if ( ! $ups ) {
				$change = (string) ( $vm['change_plan_url'] ?? '' );
				if ( '' !== $change ) {
					$h .= '<a class="gs-buy" href="' . esc_url( $change ) . '" target="_blank" rel="noopener" data-gs-plan-popup="1">' . esc_html__( 'Choose a plan on gend.me', 'gend-society' ) . '</a> ';
				} else {
					$h .= '<span class="gs-up-note">' . esc_html__( 'A plan cannot be bought from this site right now.', 'gend-society' ) . '</span> ';
				}
			}
			$h .= '<button type="button" class="gs-buy gs-buy--ghost" data-gs-goto-dashboards="1">' . esc_html__( 'What each plan includes', 'gend-society' ) . '</button>';
			$h .= '</p>';
		} elseif ( 'included' === $state ) {
			$open = is_array( $area ) && '' !== (string) ( $area['admin_url'] ?? '' ) ? (string) $area['admin_url'] : $plan_url;
			$h   .= '<p class="gs-up-state" data-gs-up-copy="included">' . esc_html__( 'Included with your plan', 'gend-society' ) . '</p>';
			/* translators: %s: area name. */
			$h   .= '<p class="gs-up-actions"><a class="button button-primary" href="' . esc_url( $open ) . '">' . esc_html( sprintf( __( 'Open %s', 'gend-society' ), $label ) ) . '</a></p>';
		} else {
			$h .= '<p class="gs-up-state" data-gs-up-copy="unknown">' . esc_html__( 'gend.me has not listed this area for this site.', 'gend-society' ) . '</p>';
			$h .= '<p class="gs-up-actions"><a class="button" href="' . esc_url( $plan_card ) . '">' . esc_html__( 'Your plan on the dashboard', 'gend-society' ) . '</a></p>';
		}
		$h .= $refresh;
		ob_start();
		if ( function_exists( 'gend_society_plan_buy_assets' ) ) {
			gend_society_plan_buy_assets();
		}
		return ob_get_clean() . $h . '</div>';
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_fragment' ) ) {
	/**
	 * The upgrade page's refreshable fragment (#gs-feature-upgrade).
	 *
	 * @param string     $area    Area slug.
	 * @param array|null $vm      View-model (null: read it).
	 * @param string     $feature Dashboard card key when the page is one dashboard's ('' = the whole area).
	 */
	function gend_society_feature_upgrade_fragment( string $area, ?array $vm = null, string $feature = '' ): string {
		$hint = is_array( $vm ) ? array( 'vm' => $vm ) : array();
		if ( '' !== $feature ) {
			$hint['feature'] = $feature;
		}
		return '<div id="gs-feature-upgrade" class="gs-feature-upgrade">' . gend_society_feature_upgrade_render_standalone( $area, $hint ) . '</div>';
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_referer_feature' ) ) {
	/**
	 * Dashboard card key of the bundled menu page a Refresh came from (its ?page= in the referer), '' otherwise.
	 */
	function gend_society_feature_upgrade_referer_feature(): string {
		if ( ! function_exists( 'gend_society_sa_menu_gated' ) ) {
			return '';
		}
		$query = (string) wp_parse_url( (string) wp_get_raw_referer(), PHP_URL_QUERY );
		$args  = array();
		wp_parse_str( $query, $args );
		$page  = isset( $args['page'] ) && is_string( $args['page'] ) ? sanitize_key( $args['page'] ) : '';
		$gated = gend_society_sa_menu_gated();
		return isset( $gated[ $page ]['feature'] ) ? (string) $gated[ $page ]['feature'] : '';
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_page_standalone' ) ) {
	/**
	 * admin.php?page=gs-feature-upgrade on a standalone install; also the bundled GenD menu pages of features that
	 * are not on this install (109-35, inc/standalone-menu.php), which pass their area.
	 *
	 * @param string $area    Area slug ('' = from the request).
	 * @param string $feature Dashboard card key when the page is one dashboard's ('' = the whole area).
	 */
	function gend_society_feature_upgrade_page_standalone( string $area = '', string $feature = '' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'gend-society' ), '', array( 'response' => 403 ) );
		}
		$area     = '' !== $area ? sanitize_key( $area ) : gend_society_feature_upgrade_request_area();
		$plan_url = function_exists( 'gend_society_plan_page_url' ) ? gend_society_plan_page_url() : admin_url( 'index.php' );
		echo '<div class="wrap gend-society-plan gend-society-upgrade">';
		echo '<h1>' . esc_html__( 'Feature access', 'gend-society' ) . '</h1>';
		echo '<p class="gs-plan-toolbar"><span class="gs-plan-live" role="status" aria-live="polite" data-gs-plan-live="1"></span></p>';
		echo '<div id="gs-plan-blocked" class="notice notice-warning inline gs-plan-blocked" hidden><p>';
		echo esc_html__( 'Your browser blocked the checkout window.', 'gend-society' ) . ' ';
		echo '<a href="#" target="_blank" rel="opener" data-gs-plan-blocked-link="1">' . esc_html__( 'Open checkout in a new tab', 'gend-society' ) . '</a>';
		echo '</p></div>';
		echo gend_society_feature_upgrade_fragment( $area, null, sanitize_key( $feature ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '<p class="gs-up-back"><a href="' . esc_url( $plan_url ) . '">' . esc_html__( 'Back to the dashboard', 'gend-society' ) . '</a></p>';
		echo '</div>';
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_standalone_enqueue' ) ) {
	/**
	 * gs-plan.css / gs-plan.js on the standalone upgrade page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	function gend_society_feature_upgrade_standalone_enqueue( $hook_suffix ): void {
		if ( 'admin_page_gs-feature-upgrade' !== $hook_suffix || ! function_exists( 'gend_society_plan_assets_enqueue' ) ) {
			return;
		}
		gend_society_plan_assets_enqueue( 'upgrade', gend_society_feature_upgrade_request_area() );
	}
}

if ( ! function_exists( 'gend_society_feature_upgrade_refresh_fragment' ) ) {
	/**
	 * gend_society_plan_refresh_html: the upgrade page's fragment when the Refresh AJAX comes from it.
	 *
	 * @param mixed $html Fragments so far.
	 * @param mixed $vm   Fresh view-model.
	 */
	function gend_society_feature_upgrade_refresh_fragment( $html, $vm ): array {
		$html = is_array( $html ) ? $html : array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the Refresh AJAX checked its nonce + capability.
		$view = isset( $_POST['gs_view'] ) ? sanitize_key( wp_unslash( (string) $_POST['gs_view'] ) ) : '';
		$area = isset( $_POST['gs_area'] ) ? sanitize_key( wp_unslash( (string) $_POST['gs_area'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'upgrade' === $view && is_array( $vm ) && isset( $vm['state'] ) ) {
			$html['gs-feature-upgrade'] = gend_society_feature_upgrade_fragment( $area, $vm, gend_society_feature_upgrade_referer_feature() );
		}
		return $html;
	}
}

if ( function_exists( 'gend_society_runtime_mode' ) && 'standalone' === gend_society_runtime_mode() ) {
	add_action( 'admin_enqueue_scripts', 'gend_society_feature_upgrade_standalone_enqueue' );
	add_filter( 'gend_society_plan_refresh_html', 'gend_society_feature_upgrade_refresh_fragment', 20, 2 );
}
