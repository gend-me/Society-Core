<?php
/**
 * Profile → Projects → Memberships → Joined: the groups the member is in, as cards styled after each group's own
 * header (cover image, avatar, Public/Private pill, member count, name, description, last active, Connected Web App,
 * Lead Architect), with an AJAX search (wp_ajax_gend_society_joined_groups) and "Load more" paging.
 * Data comes from the same sources the group header uses (BP cover-image attachment, psoo_get_group_apps(),
 * psoo_get_group_project_manager_id()). assets/profile-joined-groups.{js,css}.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const GEND_SOCIETY_JOINED_PER_PAGE = 12;

/** One card's data. */
function gend_society_joined_group_card( $g ) {
    $gid    = (int) $g->id;
    $link   = trailingslashit( function_exists( 'bp_get_group_url' ) ? bp_get_group_url( $g ) : bp_get_group_permalink( $g ) );
    $cover  = function_exists( 'bp_attachments_get_attachment' ) ? (string) bp_attachments_get_attachment( 'url', array( 'object_dir' => 'groups', 'item_id' => $gid, 'type' => 'cover-image' ) ) : '';
    $avatar = (string) bp_core_fetch_avatar( array( 'item_id' => $gid, 'object' => 'group', 'type' => 'full', 'html' => false ) );
    $apps   = function_exists( 'psoo_get_group_apps' ) ? (array) psoo_get_group_apps( $gid ) : array();
    $app    = $apps ? $apps[0] : null;
    $pm_id  = function_exists( 'psoo_get_group_project_manager_id' ) ? (int) psoo_get_group_project_manager_id( $gid, false ) : 0;
    $pm     = $pm_id ? get_userdata( $pm_id ) : null;
    $desc   = wp_strip_all_tags( (string) $g->description );
    $last   = groups_get_groupmeta( $gid, 'last_activity' );
    return array(
        'id'       => $gid,
        'name'     => html_entity_decode( (string) $g->name, ENT_QUOTES, 'UTF-8' ),
        'link'     => $link,
        'status'   => (string) $g->status,
        'members'  => (int) groups_get_total_member_count( $gid ),
        'desc'     => wp_html_excerpt( $desc, 110, '…' ),
        'active'   => $last ? sprintf( /* translators: %s: human time diff */ __( 'active %s ago', 'gend-society' ), human_time_diff( strtotime( $last ), time() ) ) : '',
        'cover'    => $cover,
        'avatar'   => $avatar,
        'app'      => $app ? array( 'name' => (string) $app['name'], 'link' => (string) $app['link'] ) : null,
        'pm'       => $pm ? array( 'name' => $pm->display_name, 'avatar' => (string) bp_core_fetch_avatar( array( 'item_id' => $pm_id, 'object' => 'user', 'type' => 'thumb', 'html' => false ) ) ) : null,
        'is_admin' => function_exists( 'groups_is_user_admin' ) && groups_is_user_admin( get_current_user_id(), $gid ),
    );
}

/** The member's groups, optionally filtered by name. */
function gend_society_joined_groups_find( $user_id, $q = '', $page = 1 ) {
    $r = groups_get_groups( array(
        'user_id'      => (int) $user_id,
        'search_terms' => $q !== '' ? $q : false,
        'per_page'     => GEND_SOCIETY_JOINED_PER_PAGE,
        'page'         => max( 1, (int) $page ),
        'orderby'      => 'last_activity',
        'order'        => 'DESC',
        'show_hidden'  => true,
    ) );
    $cards = array();
    foreach ( (array) ( isset( $r['groups'] ) ? $r['groups'] : array() ) as $g ) {
        $cards[] = gend_society_joined_group_card( $g );
    }
    $total = isset( $r['total'] ) ? (int) $r['total'] : count( $cards );
    return array( 'cards' => $cards, 'total' => $total, 'more' => $total > (int) $page * GEND_SOCIETY_JOINED_PER_PAGE );
}

add_action( 'wp_ajax_gend_society_joined_groups', function () {
    check_ajax_referer( 'gs_joined_groups', 'nonce' );
    $q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
    $page = isset( $_POST['page'] ) ? (int) $_POST['page'] : 1;
    wp_send_json_success( gend_society_joined_groups_find( get_current_user_id(), $q, $page ) );
} );

/** Memberships → Joined panel (own profile). */
function gend_society_joined_groups_panel() {
    if ( ! is_user_logged_in() || ! function_exists( 'groups_get_groups' ) ) return '';
    $first = gend_society_joined_groups_find( get_current_user_id() );
    $css   = GEND_SOCIETY_DIR . 'assets/profile-joined-groups.css';
    $js    = GEND_SOCIETY_DIR . 'assets/profile-joined-groups.js';
    wp_enqueue_style( 'gend-society-joined-groups', GEND_SOCIETY_URL . 'assets/profile-joined-groups.css', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $css ) );
    wp_enqueue_script( 'gend-society-joined-groups', GEND_SOCIETY_URL . 'assets/profile-joined-groups.js', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $js ), true );
    $cfg = array(
        'ajax'  => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'gs_joined_groups' ),
        'first' => $first,
        'i18n'  => array(
            'public'  => __( 'Public Group', 'gend-society' ),
            'private' => __( 'Private Group', 'gend-society' ),
            'hidden'  => __( 'Hidden Group', 'gend-society' ),
            'app'     => __( 'Connected Web App', 'gend-society' ),
            'noApp'   => __( 'No App Linked', 'gend-society' ),
            'view'    => __( 'View Site', 'gend-society' ),
            'pm'      => __( 'Lead Architect', 'gend-society' ),
            'noPm'    => __( 'No PM Assigned', 'gend-society' ),
            'admin'   => __( 'Admin', 'gend-society' ),
            'open'    => __( 'Open hub', 'gend-society' ),
            'none'    => __( 'No groups match that search.', 'gend-society' ),
            'empty'   => __( 'You have not joined any groups yet.', 'gend-society' ),
            'more'    => __( 'Load more', 'gend-society' ),
        ),
    );
    ob_start();
    ?>
    <div class="gs-joined" data-gs-joined>
        <label class="gs-joined__search">
            <span class="screen-reader-text"><?php esc_html_e( 'Search your groups', 'gend-society' ); ?></span>
            <input type="search" placeholder="<?php esc_attr_e( 'Search your groups…', 'gend-society' ); ?>" autocomplete="off" data-gs-joined-q>
        </label>
        <div class="gs-joined__grid" data-gs-joined-grid></div>
        <p class="gs-joined__none" data-gs-joined-none hidden></p>
        <div class="gs-joined__more"><button type="button" class="gs-joined__more-btn" data-gs-joined-more hidden><?php esc_html_e( 'Load more', 'gend-society' ); ?></button></div>
        <script type="application/json" data-gs-joined-cfg><?php echo wp_json_encode( $cfg ); ?></script>
    </div>
    <?php
    return ob_get_clean();
}
