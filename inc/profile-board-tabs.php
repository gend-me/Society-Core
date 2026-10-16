<?php
/**
 * Projects → My Projects: "Calendar" and "My Connections" tabs to the right of My Tasks (own profile). The Calendar and
 * Connections top-nav items are gone (member-profile-header.php); their pages still exist and are framed here
 * chrome-stripped (?gdc_tab_only=1&gs_embed=calendar|connections — embed modes in profile-growth-apps.php), so all of
 * their features keep working unchanged. My Connections keeps the count badge the Connections nav item had, plus the
 * pending-request count, refreshed while the page is open.
 *
 * assets/profile-board-tabs.js appends the buttons + panels to the PM board (#psoo-pm-app, projects plugin); its
 * delegated tab handler (pm-admin.js PM.initTabs) switches them like the built-in tabs.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function gend_society_board_tabs_counts( $user_id ) {
    $user_id = (int) $user_id;
    return array(
        'friends'  => function_exists( 'friends_get_total_friend_count' ) ? (int) friends_get_total_friend_count( $user_id ) : 0,
        'requests' => function_exists( 'bp_friend_get_total_requests_count' ) ? (int) bp_friend_get_total_requests_count( $user_id ) : 0,
    );
}

add_action( 'wp_ajax_gend_society_board_counts', function () {
    check_ajax_referer( 'gs_board_tabs', 'nonce' );
    wp_send_json_success( gend_society_board_tabs_counts( get_current_user_id() ) );
} );

add_action( 'wp_enqueue_scripts', function () {
    if ( ! empty( $_GET['gdc_tab_only'] ) || ! function_exists( 'bp_is_user' ) || ! bp_is_user() || ! bp_is_my_profile() ) return;
    if ( ! function_exists( 'bp_current_component' ) || 'groups' !== bp_current_component() ) return;
    $uid  = (int) bp_displayed_user_id();
    $home = trailingslashit( bp_displayed_user_domain() );
    $tabs = array();
    if ( function_exists( 'gend_society_calendar_profile_screen' ) ) {
        $tabs[] = array(
            'slug'  => 'gs-calendar',
            'label' => __( 'Calendar', 'gend-society' ),
            'icon'  => 'dashicons-calendar-alt',
            'src'   => add_query_arg( array( 'gdc_tab_only' => '1', 'gs_embed' => 'calendar' ), $home . 'member-calendar/' ),
        );
    }
    if ( function_exists( 'bp_is_active' ) && bp_is_active( 'friends' ) ) {
        $tabs[] = array(
            'slug'  => 'gs-connections',
            'label' => __( 'My Connections', 'gend-society' ),
            'icon'  => 'dashicons-groups',
            'src'   => add_query_arg( array( 'gdc_tab_only' => '1', 'gs_embed' => 'connections' ), $home . bp_get_friends_slug() . '/' ),
            'count' => true,
        );
    }
    if ( ! $tabs ) return;
    $js = GEND_SOCIETY_DIR . 'assets/profile-board-tabs.js';
    wp_enqueue_script( 'gend-society-board-tabs', GEND_SOCIETY_URL . 'assets/profile-board-tabs.js', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $js ), true );
    wp_localize_script( 'gend-society-board-tabs', 'GS_BOARD_TABS', array(
        'tabs'   => $tabs,
        'counts' => gend_society_board_tabs_counts( $uid ),
        'ajax'   => admin_url( 'admin-ajax.php' ),
        'nonce'  => wp_create_nonce( 'gs_board_tabs' ),
        'origin' => untrailingslashit( home_url() ),
        'i18n'   => array(
            'requests' => __( 'pending requests', 'gend-society' ),
            'loading'  => __( 'Loading…', 'gend-society' ),
        ),
    ) );
}, 30 );

/** Inside the Connections / Calendar embeds. */
add_action( 'wp_head', function () {
    $mode = function_exists( 'gend_society_embed_mode' ) ? gend_society_embed_mode() : '';
    if ( 'connections' !== $mode && 'calendar' !== $mode ) return;
    ?>
    <style id="gs-embed-<?php echo esc_attr( $mode ); ?>">
        <?php if ( 'connections' === $mode ) : ?>
        /* only My Connections: the Referrals / Sales Team / Invite panels live under Contracts → Commissions */
        .gs-member-friends-tabs, .gs-member-friends-panel:not([data-gs-panel="connections"]) { display: none !important; }
        .gs-member-friends-panel[data-gs-panel="connections"] { display: block !important; }
        <?php endif; ?>
        body.gdc-tab-only #buddypress { padding: 0 !important; }
    </style>
    <script>
    // Links to other pages (member profiles, groups) open in the whole window; this tab's own sub-pages
    // (Friendships / Requests / Visitors, calendar views) stay in the frame.
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target || a.hasAttribute('download')) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
        var u; try { u = new URL(a.href, location.href); } catch (err) { return; }
        var base = location.pathname.replace(/\/(requests|visitors|my-friends)\/?$/, '/');
        if (u.origin === location.origin && u.pathname.indexOf(base) === 0) {
            if (!u.searchParams.has('gdc_tab_only')) { u.searchParams.set('gdc_tab_only', '1'); u.searchParams.set('gs_embed', <?php echo wp_json_encode( $mode ); ?>); a.href = u.toString(); }
            return;
        }
        a.target = '_top';
    }, true);
    </script>
    <?php
}, 1001 );
