<?php
/**
 * Profile → Contracts → Growth Investments: pick one of the apps (BP groups) the member is a Group Admin of, then work
 * in that group's own Marketing → Advertising page, embedded below the picker.
 *
 * The picker is an AJAX search (wp_ajax_gend_society_growth_apps, BP_Groups_Member::get_is_admin_of) of the member's
 * admin groups. The embed is the group's /files/growth-investments/ page with ?gdc_tab_only=1&gs_embed=advertising:
 * gdc_tab_only strips the site chrome (member-profile-pages.php) and gs_embed=advertising also hides the group nav and
 * the Marketing sub-tab bar, and reports the page height to the parent so the frame grows with its content.
 *
 * Only the profile owner (or a network admin) gets the picker; everyone else keeps the growth marketplace.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function gend_society_growth_apps_can_view( $user_id ) {
    $user_id = (int) $user_id;
    if ( ! $user_id || ! is_user_logged_in() || ! class_exists( 'BP_Groups_Member' ) ) return false;
    return get_current_user_id() === $user_id || current_user_can( 'manage_options' );
}

/** Groups $user_id is a Group Admin of, optionally filtered by name. */
function gend_society_growth_apps_find( $user_id, $q = '', $limit = 24 ) {
    $r = BP_Groups_Member::get_is_admin_of( (int) $user_id, (int) $limit, 1, (string) $q );
    $out = array();
    foreach ( ( isset( $r['groups'] ) ? (array) $r['groups'] : array() ) as $g ) {
        $g = groups_get_group( (int) $g->id );
        if ( empty( $g->id ) ) continue;
        $avatar = bp_core_fetch_avatar( array( 'item_id' => $g->id, 'object' => 'group', 'type' => 'thumb', 'html' => false ) );
        $base   = function_exists( 'bp_get_group_url' ) ? bp_get_group_url( $g ) : bp_get_group_permalink( $g );
        $url    = add_query_arg( array( 'gdc_tab_only' => '1', 'gs_embed' => 'advertising' ), trailingslashit( $base ) . 'files/growth-investments/' );
        $out[] = array(
            'id'     => (int) $g->id,
            'name'   => html_entity_decode( (string) $g->name, ENT_QUOTES, 'UTF-8' ),
            'status' => (string) $g->status,
            'avatar' => $avatar ? (string) $avatar : '',
            'embed'  => $url,
        );
    }
    return $out;
}

add_action( 'wp_ajax_gend_society_growth_apps', function () {
    check_ajax_referer( 'gs_growth_apps', 'nonce' );
    $uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( ! gend_society_growth_apps_can_view( $uid ) ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
    $q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
    wp_send_json_success( array( 'apps' => gend_society_growth_apps_find( $uid, $q ) ) );
} );

/** The Growth tab panel: picker + embedded Advertising page. */
function gend_society_growth_apps_panel( $user_id ) {
    $apps = gend_society_growth_apps_find( $user_id );
    $cfg  = array(
        'ajax'   => admin_url( 'admin-ajax.php' ),
        'nonce'  => wp_create_nonce( 'gs_growth_apps' ),
        'user'   => (int) $user_id,
        'apps'   => $apps,
        'origin' => untrailingslashit( home_url() ),
        'i18n'   => array(
            'none'    => __( 'No apps match that search.', 'gend-society' ),
            'loading' => __( 'Loading advertising…', 'gend-society' ),
            'public'  => __( 'Public', 'gend-society' ),
            'private' => __( 'Private', 'gend-society' ),
            'hidden'  => __( 'Hidden', 'gend-society' ),
        ),
    );
    ob_start();
    ?>
    <section class="gs-growth-apps" data-gs-growth-apps>
        <div class="gs-growth-apps__head">
            <div>
                <span class="gs-growth-apps__kicker"><?php esc_html_e( 'Advertising', 'gend-society' ); ?></span>
                <h3><?php esc_html_e( 'Choose an app to grow', 'gend-society' ); ?></h3>
                <p><?php esc_html_e( 'Apps you are a Group Admin of. Pick one to manage its advertising budget, channel split and campaigns.', 'gend-society' ); ?></p>
            </div>
            <label class="gs-growth-apps__search">
                <span class="screen-reader-text"><?php esc_html_e( 'Search your apps', 'gend-society' ); ?></span>
                <input type="search" placeholder="<?php esc_attr_e( 'Search your apps…', 'gend-society' ); ?>" autocomplete="off" data-gs-growth-q>
            </label>
        </div>
        <?php if ( ! $apps ) : ?>
            <p class="gs-growth-apps__empty"><?php esc_html_e( 'You are not a Group Admin of any app yet. Launch or join an app as an admin to run its advertising here.', 'gend-society' ); ?></p>
        <?php else : ?>
            <div class="gs-growth-apps__grid" role="listbox" aria-label="<?php esc_attr_e( 'Your apps', 'gend-society' ); ?>" data-gs-growth-grid></div>
            <p class="gs-growth-apps__none" hidden data-gs-growth-none></p>
            <div class="gs-growth-apps__stage" data-gs-growth-stage hidden>
                <div class="gs-growth-apps__loading" data-gs-growth-loading><span></span><?php esc_html_e( 'Loading advertising…', 'gend-society' ); ?></div>
                <iframe class="gs-growth-apps__frame" title="<?php esc_attr_e( 'App advertising', 'gend-society' ); ?>" loading="lazy" data-gs-growth-frame></iframe>
            </div>
        <?php endif; ?>
        <script type="application/json" data-gs-growth-cfg><?php echo wp_json_encode( $cfg ); ?></script>
    </section>
    <?php
    return ob_get_clean();
}

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() || ! function_exists( 'bp_current_component' ) || 'invest' !== bp_current_component() ) return;
    if ( ! gend_society_growth_apps_can_view( (int) bp_displayed_user_id() ) ) return;
    $css = GEND_SOCIETY_DIR . 'assets/profile-growth-apps.css';
    $js  = GEND_SOCIETY_DIR . 'assets/profile-growth-apps.js';
    wp_enqueue_style( 'gend-society-growth-apps', GEND_SOCIETY_URL . 'assets/profile-growth-apps.css', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $css ) );
    wp_enqueue_script( 'gend-society-growth-apps', GEND_SOCIETY_URL . 'assets/profile-growth-apps.js', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $js ), true );
}, 30 );

/** Embed modes framed into profile tabs: advertising (this file), calendar + connections (profile-board-tabs.php). */
function gend_society_embed_mode() {
    if ( empty( $_GET['gdc_tab_only'] ) || empty( $_GET['gs_embed'] ) ) return '';
    $m = sanitize_key( wp_unslash( $_GET['gs_embed'] ) );
    return in_array( $m, array( 'advertising', 'calendar', 'connections' ), true ) ? $m : '';
}

/** Inside an embed: hide the chrome (advertising: group nav + Marketing sub-tabs), report height to the parent profile. */
add_action( 'wp_head', function () {
    $mode = gend_society_embed_mode();
    if ( ! $mode ) return;
    if ( 'advertising' === $mode ) :
    ?>
    <style id="gs-embed-advertising">
        /* group pages don't carry the gdc-tab-only body class, so no body prefix (this block only prints in embed mode) */
        .bridge-row, .bridge-menu, nav.gdc-foundation-subtabs[aria-label="Brain Foundations"],
        #wpadminbar, #main-3d-header, .header-anchor-wrap, #youzify-group-header, footer.site-footer, #colophon, aipa-widget,
        #bridge-sticky-bar, #gm-cc, .gm-cc { display: none !important; }
        html { margin-top: 0 !important; padding-top: 0 !important; }
        html, body { background: transparent !important; }
    </style>
    <?php endif; ?>
    <style id="gs-embed-common">#gm-cc, .gm-cc, aipa-widget { display: none !important; } html, body { background: transparent !important; }</style>
    <script>
    (function () {
        if (window.parent === window) return;
        var last = 0;
        function send() {
            var h = Math.ceil(document.documentElement.scrollHeight);
            if (Math.abs(h - last) < 2) return;
            last = h;
            window.parent.postMessage({ gsEmbedHeight: h }, <?php echo wp_json_encode( untrailingslashit( home_url() ) ); ?>);
        }
        window.addEventListener('load', send);
        document.addEventListener('DOMContentLoaded', function () {
            send();
            if (window.ResizeObserver) new ResizeObserver(send).observe(document.body);
            document.addEventListener('click', function () { setTimeout(send, 60); setTimeout(send, 500); setTimeout(focusPopup, 280); });
        });
        // The frame is as tall as its content, so a fixed popup centres in the middle of the whole frame: tell the
        // parent where it is so it can scroll it into view.
        function focusPopup() {
            var best = null, bz = -1, all = document.body.getElementsByTagName('*');
            for (var i = 0; i < all.length; i++) {
                var el = all[i];
                if (el.offsetWidth < 200 || el.offsetHeight < 100) continue;
                var cs = getComputedStyle(el);
                if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) continue;
                var z = parseInt(cs.zIndex, 10) || 0;
                if (z > bz) { bz = z; best = el; }
            }
            if (!best) return;
            var dlg = best.querySelector('[role="dialog"], .modal, [class*="modal"], [class*="popup"], [class*="dialog"]') || best;
            var r = dlg.getBoundingClientRect();
            window.parent.postMessage({ gsEmbedFocus: Math.round(r.top + Math.min(r.height, window.innerHeight) / 2) }, <?php echo wp_json_encode( untrailingslashit( home_url() ) ); ?>);
        }
        setInterval(send, 1500);
    })();
    </script>
    <?php
}, 1000 );
