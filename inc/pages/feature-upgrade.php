<?php
/**
 * Feature Access upgrade prompt page.
 *
 * Customers hit this page when they try to access a wp-admin area
 * their current Dashboard plan doesn't include (Content/Store/Social/
 * Reward/etc.). The page explains the gap, lists the available
 * upgrade tiers, and lets them complete the upgrade in a popup
 * window pointed at gend.me's checkout (?ui=embed). On popup close
 * or postMessage success, we invalidate the feature cache + reload
 * back to whichever page they originally tried to reach.
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
        'gs_render_feature_upgrade_page'
    );
}, 60 );

/**
 * Pretty area name for the prompt copy.
 */
function gs_feature_area_label( string $area ): string {
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
function gs_feature_upgrade_tiers() {
    return apply_filters( 'gs_feature_upgrade_tiers', array(
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

function gs_render_feature_upgrade_page() {

    if ( ! current_user_can( 'read' ) ) wp_die( esc_html__( 'Sign in required.', 'gend-society' ) );

    $required = isset( $_GET['required'] ) ? sanitize_key( (string) $_GET['required'] ) : '';
    $from     = isset( $_GET['from'] )     ? sanitize_key( (string) $_GET['from'] )     : '';
    $features = function_exists( 'gs_features_get_cached' ) ? gs_features_get_cached() : null;
    $current_areas = is_array( $features ) && isset( $features['allowed_areas'] ) ? (array) $features['allowed_areas'] : array();
    $current_plan  = is_array( $features ) && ! empty( $features['plan_name'] ) ? (string) $features['plan_name'] : '';

    // Membership URL on gend.me — the popup target. ?ui=embed strips
    // the theme chrome so the popup looks like a focused modal.
    $hub        = function_exists( 'gs_oauth_hub_url' ) ? gs_oauth_hub_url() : (string) get_option( 'gs_gend_base_url', 'https://gend.me' );
    $remote_mid = 0;
    if ( function_exists( 'gs_remote_membership_get_cached' ) ) {
        $rm = gs_remote_membership_get_cached();
        if ( is_array( $rm ) && ! empty( $rm['membership_id'] ) ) $remote_mid = (int) $rm['membership_id'];
    }
    $membership_url = $remote_mid > 0
        ? rtrim( $hub, '/' ) . '/my-account/membership/' . $remote_mid . '/?ui=embed&group=dashboard'
        : rtrim( $hub, '/' ) . '/my-account/memberships/';

    $tiers = gs_feature_upgrade_tiers();

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
        </style>

        <div class="gs-up-shell">

            <?php if ( $required !== '' ) : ?>
                <span class="gs-up-current">⨯ <?php echo esc_html( sprintf( __( '%s requires an upgrade', 'gend-society' ), gs_feature_area_label( $required ) ) ); ?></span>
            <?php elseif ( $current_plan !== '' ) : ?>
                <span class="gs-up-current">● <?php echo esc_html( sprintf( __( 'Current plan: %s', 'gend-society' ), $current_plan ) ); ?></span>
            <?php endif; ?>

            <h1><?php esc_html_e( 'Choose your Dashboard Feature plan', 'gend-society' ); ?></h1>
            <p class="lead">
                <?php
                if ( $required !== '' ) {
                    echo esc_html( sprintf(
                        __( 'The %s area is locked on your current plan. Upgrade to a Dashboard tier that includes it and your wp-admin will unlock instantly when checkout completes.', 'gend-society' ),
                        gs_feature_area_label( $required )
                    ) );
                } else {
                    esc_html_e( 'Each tier unlocks more wp-admin areas. Upgrades take effect immediately after checkout.', 'gend-society' );
                }
                ?>
            </p>

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
                                <span><?php echo esc_html( gs_feature_area_label( $a ) ); ?></span>
                            <?php endforeach; ?>
                        </div>
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
                    'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
                if (!popup) { alert('Popup blocked. Allow popups and try again.'); return; }

                btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Awaiting checkout…';

                function done(ok) {
                    btn.disabled = false; btn.textContent = orig;
                    if (!ok) return;
                    // Force a fresh fetch of the feature payload, then
                    // bounce back to the original page (or Dashboard).
                    var fd = new FormData();
                    fd.append('action', 'gs_features_refresh');
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

                window.addEventListener('message', function onMsg(ev) {
                    if (!ev.data || ev.data.type !== 'gs_membership_changed') return;
                    window.removeEventListener('message', onMsg);
                    clearInterval(watchdog);
                    try { popup.close(); } catch (_) {}
                    done(true);
                });
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
add_action( 'wp_ajax_gs_features_refresh', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error();
    check_ajax_referer( 'gs_membership_action', 'nonce' );
    if ( function_exists( 'gs_features_invalidate' ) )         gs_features_invalidate();
    if ( function_exists( 'gs_remote_membership_invalidate' ) ) gs_remote_membership_invalidate();
    $f = function_exists( 'gs_features_get_cached' ) ? gs_features_get_cached() : null;
    wp_send_json_success( array( 'features' => $f ) );
} );
