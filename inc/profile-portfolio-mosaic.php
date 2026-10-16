<?php
/**
 * Overview › Portfolio (formerly Media): one mosaic of the member's media — Youzify photos / videos / audio / files,
 * blog posts and playlists — with filters (All · Photos · Videos · Audio · Files · Blog Posts · Playlists). The Blog
 * Posts and Playlists filters show the former Posts and Playlists sub-tabs (gend_society_portfolio_render_posts(),
 * blog-manager's bm_render_library_section()).
 *
 * Owners get "Add new": choose a type, then its own flow —
 *   Photo / Video / Audio / File → upload (gend_society_portfolio_upload): a media-library attachment authored by the
 *     member, registered in Youzify's media table (component user) so it also shows in their Youzify gallery
 *   Blog post → a short composer (gend_society_portfolio_post): published when the member can publish posts,
 *     otherwise submitted for review
 *   Playlist → the Playlists filter's own New Playlist flow
 *
 * Assets: assets/profile-portfolio.css + .js.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Upload rules per media type. */
function gend_society_portfolio_types() {
    return array(
        'image' => array( 'label' => __( 'Photo', 'gend-society' ), 'accept' => 'image/jpeg,image/png,image/gif,image/webp',
            'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ), 'max' => 10 ),
        'video' => array( 'label' => __( 'Video', 'gend-society' ), 'accept' => 'video/mp4,video/webm,video/quicktime',
            'mimes' => array( 'mp4|m4v' => 'video/mp4', 'webm' => 'video/webm', 'mov|qt' => 'video/quicktime' ), 'max' => 200 ),
        'audio' => array( 'label' => __( 'Audio', 'gend-society' ), 'accept' => 'audio/mpeg,audio/mp4,audio/wav,audio/ogg',
            'mimes' => array( 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg|oga' => 'audio/ogg' ), 'max' => 50 ),
        'file'  => array( 'label' => __( 'File', 'gend-society' ), 'accept' => '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv',
            'mimes' => array( 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt' => 'text/plain', 'csv' => 'text/csv' ), 'max' => 25 ),
    );
}

/** Youzify media rows for a member: their own uploads + media on activity they posted. */
function gend_society_portfolio_media_rows( $user_id, $limit = 120 ) {
    global $wpdb;
    $t = $wpdb->prefix . 'youzify_media';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return array();
    $act = $wpdb->base_prefix . 'bp_activity';
    $sql = "SELECT m.id, m.media_id, m.type, m.component, m.time FROM {$t} m
            LEFT JOIN {$act} a ON m.component = 'activity' AND a.id = m.item_id
            WHERE m.privacy IN ('public', '') AND (( m.component <> 'activity' AND m.user_id = %d ) OR ( m.component = 'activity' AND a.user_id = %d ))
            ORDER BY m.time DESC LIMIT %d";
    return (array) $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $user_id, $limit ) );
}

/** Build one mosaic item (array) from a Youzify media row. */
function gend_society_portfolio_media_item( $row, $is_own ) {
    $aid = (int) $row->media_id;
    if ( ! $aid || ! get_post( $aid ) ) return null;
    $type = in_array( $row->type, array( 'image', 'video', 'audio', 'file' ), true ) ? $row->type : 'file';
    $url  = (string) wp_get_attachment_url( $aid );
    if ( $url === '' ) return null;
    return array(
        'kind'   => $type,
        'id'     => (int) $row->id,
        // Upload filenames (e.g. 1785251727-bpfull) aren't titles — show none.
        'title'  => preg_match( '/^\d{6,}|bp(full|thumb)|^(img|dsc|image|screenshot)[-_ ]?\d/i', get_the_title( $aid ) ) ? '' : get_the_title( $aid ),
        'thumb'  => $type === 'image' ? (string) wp_get_attachment_image_url( $aid, 'medium_large' ) : '',
        'full'   => $url,
        'date'   => mysql2date( 'M j, Y', $row->time ),
        'ext'    => strtoupper( (string) pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ),
        'mine'   => $is_own && (int) get_post_field( 'post_author', $aid ) === get_current_user_id() && $row->component !== 'activity',
    );
}

/** All mosaic items for a member. */
function gend_society_portfolio_items( $user_id, $is_own ) {
    $items = array();
    foreach ( gend_society_portfolio_media_rows( $user_id ) as $row ) {
        $it = gend_society_portfolio_media_item( $row, $is_own );
        if ( $it ) $items[] = $it + array( 'ts' => strtotime( $row->time ) );
    }
    $posts = get_posts( array( 'post_type' => 'post', 'author' => $user_id, 'post_status' => $is_own ? array( 'publish', 'pending', 'draft' ) : 'publish', 'posts_per_page' => 40 ) );
    foreach ( $posts as $p ) {
        $items[] = array(
            'kind' => 'post', 'id' => (int) $p->ID, 'title' => get_the_title( $p ), 'thumb' => (string) get_the_post_thumbnail_url( $p, 'medium_large' ),
            'full' => (string) get_permalink( $p ), 'date' => get_the_date( 'M j, Y', $p ), 'excerpt' => wp_trim_words( wp_strip_all_tags( get_the_excerpt( $p ) ), 18, '…' ),
            'status' => $p->post_status, 'ts' => (int) get_post_time( 'U', true, $p ), 'mine' => false,
        );
    }
    if ( defined( 'BM_PLAYLIST_CPT' ) ) {
        $viewer = get_current_user_id();
        foreach ( get_posts( array( 'post_type' => BM_PLAYLIST_CPT, 'author' => $user_id, 'post_status' => 'publish', 'posts_per_page' => 40 ) ) as $p ) {
            if ( function_exists( 'bm_playlist_viewer_can_read' ) && ! bm_playlist_viewer_can_read( $p, $viewer ) ) continue;
            $items[] = array(
                'kind' => 'playlist', 'id' => (int) $p->ID, 'title' => get_the_title( $p ), 'thumb' => (string) get_the_post_thumbnail_url( $p, 'medium_large' ),
                'full' => (string) get_permalink( $p ), 'date' => get_the_modified_date( 'M j, Y', $p ), 'ts' => (int) get_post_modified_time( 'U', true, $p ), 'mine' => false,
            );
        }
    }
    usort( $items, function ( $a, $b ) { return $b['ts'] <=> $a['ts']; } );
    return $items;
}

/** One mosaic tile. */
function gend_society_portfolio_tile( $it ) {
    $kind = $it['kind'];
    $badge = array( 'image' => __( 'Photo', 'gend-society' ), 'video' => __( 'Video', 'gend-society' ), 'audio' => __( 'Audio', 'gend-society' ),
        'file' => __( 'File', 'gend-society' ), 'post' => __( 'Blog post', 'gend-society' ), 'playlist' => __( 'Playlist', 'gend-society' ) )[ $kind ];
    $is_link = in_array( $kind, array( 'post', 'playlist', 'file' ), true );
    ob_start();
    ?>
    <figure class="gs-pf-tile gs-pf-tile--<?php echo esc_attr( $kind ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-id="<?php echo esc_attr( $it['id'] ); ?>">
        <?php if ( $kind === 'image' ) : ?>
            <button type="button" class="gs-pf-tile__media" data-gs-pf-zoom="<?php echo esc_url( $it['full'] ); ?>"><img src="<?php echo esc_url( $it['thumb'] ?: $it['full'] ); ?>" alt="<?php echo esc_attr( $it['title'] ); ?>" loading="lazy"></button>
        <?php elseif ( $kind === 'video' ) : ?>
            <div class="gs-pf-tile__media"><video src="<?php echo esc_url( $it['full'] ); ?>" controls preload="metadata" playsinline></video></div>
        <?php elseif ( $kind === 'audio' ) : ?>
            <div class="gs-pf-tile__media gs-pf-tile__art"><span class="gs-pf-glyph" aria-hidden="true">♪</span><audio src="<?php echo esc_url( $it['full'] ); ?>" controls preload="none"></audio></div>
        <?php elseif ( $it['thumb'] ) : ?>
            <a class="gs-pf-tile__media" href="<?php echo esc_url( $it['full'] ); ?>"<?php echo $kind === 'file' ? ' target="_blank" rel="noopener"' : ''; ?>><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy"></a>
        <?php else : ?>
            <a class="gs-pf-tile__media gs-pf-tile__art" href="<?php echo esc_url( $it['full'] ); ?>"<?php echo $kind === 'file' ? ' target="_blank" rel="noopener"' : ''; ?>>
                <span class="gs-pf-glyph" aria-hidden="true"><?php echo $kind === 'file' ? esc_html( $it['ext'] ?: 'FILE' ) : ( $kind === 'playlist' ? '▶' : '✎' ); ?></span>
            </a>
        <?php endif; ?>
        <figcaption>
            <span class="gs-pf-badge"><?php echo esc_html( $badge ); ?></span>
            <?php if ( ( $it['status'] ?? '' ) === 'pending' ) : ?><span class="gs-pf-badge is-review"><?php esc_html_e( 'In review', 'gend-society' ); ?></span><?php endif; ?>
            <?php if ( ( $it['status'] ?? '' ) === 'draft' ) : ?><span class="gs-pf-badge is-review"><?php esc_html_e( 'Draft', 'gend-society' ); ?></span><?php endif; ?>
            <?php if ( $is_link || $kind === 'post' ) : ?>
                <a class="gs-pf-title" href="<?php echo esc_url( $it['full'] ); ?>"<?php echo $kind === 'file' ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $it['title'] ); ?></a>
            <?php elseif ( $it['title'] !== '' ) : ?>
                <span class="gs-pf-title"><?php echo esc_html( $it['title'] ); ?></span>
            <?php endif; ?>
            <?php if ( ! empty( $it['excerpt'] ) ) : ?><span class="gs-pf-excerpt"><?php echo esc_html( $it['excerpt'] ); ?></span><?php endif; ?>
            <span class="gs-pf-date"><?php echo esc_html( $it['date'] ); ?></span>
        </figcaption>
        <?php if ( ! empty( $it['mine'] ) ) : ?><button type="button" class="gs-pf-remove" data-gs-pf-remove="<?php echo esc_attr( $it['id'] ); ?>" aria-label="<?php esc_attr_e( 'Remove', 'gend-society' ); ?>">&times;</button><?php endif; ?>
    </figure>
    <?php
    return ob_get_clean();
}

/** The Portfolio panel. */
function gend_society_render_portfolio_panel( $user_id, $is_own ) {
    $user_id = (int) $user_id;
    $items = gend_society_portfolio_items( $user_id, $is_own );
    $counts = array( 'image' => 0, 'video' => 0, 'audio' => 0, 'file' => 0, 'post' => 0, 'playlist' => 0 );
    foreach ( $items as $it ) $counts[ $it['kind'] ]++;
    $has_playlists = function_exists( 'bm_render_library_section' );

    $css = GEND_SOCIETY_DIR . 'assets/profile-portfolio.css';
    echo '<link rel="stylesheet" id="gend-society-portfolio-css" href="' . esc_url( GEND_SOCIETY_URL . 'assets/profile-portfolio.css?ver=' . GEND_SOCIETY_VERSION . '.' . @filemtime( $css ) ) . '" media="all" />';
    $js = GEND_SOCIETY_DIR . 'assets/profile-portfolio.js';
    wp_enqueue_script( 'gend-society-portfolio', GEND_SOCIETY_URL . 'assets/profile-portfolio.js', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $js ), true );

    $filters = array(
        'all'      => __( 'All', 'gend-society' ),
        'image'    => __( 'Photos', 'gend-society' ),
        'video'    => __( 'Videos', 'gend-society' ),
        'audio'    => __( 'Audio', 'gend-society' ),
        'file'     => __( 'Files', 'gend-society' ),
        'post'     => __( 'Blog Posts', 'gend-society' ),
    );
    if ( $has_playlists ) $filters['playlist'] = __( 'Playlists', 'gend-society' );
    $types = gend_society_portfolio_types();
    ?>
    <div class="gs-pf" data-gs-pf data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $is_own ? wp_create_nonce( 'gs_portfolio' ) : '' ); ?>">
        <div class="gs-pf-bar">
            <div class="gs-pf-filters" role="tablist">
                <?php foreach ( $filters as $k => $l ) : $n = $k === 'all' ? count( $items ) : $counts[ $k ]; ?>
                <button type="button" class="gs-pf-filter<?php echo $k === 'all' ? ' is-active' : ''; ?>" data-gs-pf-filter="<?php echo esc_attr( $k ); ?>" role="tab" aria-selected="<?php echo $k === 'all' ? 'true' : 'false'; ?>">
                    <?php echo esc_html( $l ); ?><?php if ( $n ) : ?> <span><?php echo (int) $n; ?></span><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if ( $is_own ) : ?>
            <div class="gs-pf-add">
                <button type="button" class="gs-pf-add__btn" data-gs-pf-add aria-haspopup="true" aria-expanded="false">+ <?php esc_html_e( 'Add new', 'gend-society' ); ?></button>
                <div class="gs-pf-add__menu" data-gs-pf-add-menu hidden>
                    <?php foreach ( $types as $k => $t ) : ?>
                    <label class="gs-pf-add__item"><input type="file" accept="<?php echo esc_attr( $t['accept'] ); ?>" data-gs-pf-upload="<?php echo esc_attr( $k ); ?>" hidden><span><?php echo esc_html( $t['label'] ); ?></span><small><?php echo esc_html( sprintf( __( 'Upload · up to %d MB', 'gend-society' ), min( $t['max'], (int) floor( wp_max_upload_size() / MB_IN_BYTES ) ) ) ); ?></small></label>
                    <?php endforeach; ?>
                    <button type="button" class="gs-pf-add__item" data-gs-pf-new-post><span><?php esc_html_e( 'Blog post', 'gend-society' ); ?></span><small><?php echo current_user_can( 'publish_posts' ) ? esc_html__( 'Write and publish', 'gend-society' ) : esc_html__( 'Write and submit for review', 'gend-society' ); ?></small></button>
                    <?php if ( $has_playlists ) : ?>
                    <button type="button" class="gs-pf-add__item" data-gs-pf-new-playlist><span><?php esc_html_e( 'Playlist', 'gend-society' ); ?></span><small><?php esc_html_e( 'Create a playlist', 'gend-society' ); ?></small></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <p class="gs-pf-status" data-gs-pf-status aria-live="polite"></p>

        <div class="gs-pf-pane is-active" data-gs-pf-pane="mosaic">
            <div class="gs-pf-mosaic" data-gs-pf-mosaic>
                <?php foreach ( $items as $it ) echo gend_society_portfolio_tile( $it ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <p class="gs-pf-empty" data-gs-pf-empty<?php echo $items ? ' hidden' : ''; ?>><?php echo $is_own ? esc_html__( 'Nothing here yet — use Add new to share photos, videos, audio, files, blog posts and playlists.', 'gend-society' ) : esc_html__( 'This member hasn’t shared anything here yet.', 'gend-society' ); ?></p>
        </div>
        <div class="gs-pf-pane" data-gs-pf-pane="post">
            <?php if ( function_exists( 'gend_society_portfolio_render_posts' ) ) gend_society_portfolio_render_posts(); ?>
        </div>
        <?php if ( $has_playlists ) : ?>
        <div class="gs-pf-pane" data-gs-pf-pane="playlist">
            <?php bm_render_library_section(); ?>
        </div>
        <?php endif; ?>

        <div class="gs-pf-zoom" data-gs-pf-zoom-box hidden><button type="button" aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button><img alt=""></div>

        <?php if ( $is_own ) : ?>
        <div class="gs-pf-modal" data-gs-pf-post-modal hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'New blog post', 'gend-society' ); ?>">
            <form class="gs-pf-modal__box" data-gs-pf-post-form>
                <button type="button" class="gs-pf-modal__x" data-gs-pf-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
                <h3><?php esc_html_e( 'New blog post', 'gend-society' ); ?></h3>
                <label><?php esc_html_e( 'Title', 'gend-society' ); ?><input type="text" name="title" maxlength="200" required></label>
                <label><?php esc_html_e( 'Featured image (optional)', 'gend-society' ); ?><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></label>
                <label><?php esc_html_e( 'Post', 'gend-society' ); ?><textarea name="content" rows="10" required placeholder="<?php esc_attr_e( 'Write your post… blank lines start new paragraphs.', 'gend-society' ); ?>"></textarea></label>
                <p class="gs-pf-modal__note"><?php echo current_user_can( 'publish_posts' ) ? esc_html__( 'Your post is published on gend.me straight away.', 'gend-society' ) : esc_html__( 'Your post is sent to the gend.me team for review and appears publicly once approved.', 'gend-society' ); ?></p>
                <div class="gs-pf-modal__actions"><button type="submit" class="gs-pf-add__btn"><?php echo current_user_can( 'publish_posts' ) ? esc_html__( 'Publish', 'gend-society' ) : esc_html__( 'Submit for review', 'gend-society' ); ?></button></div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ── AJAX (profile owner) ────────────────────────────────────────────────────────────────────────────────────────
function gend_society_portfolio_require_owner() {
    check_ajax_referer( 'gs_portfolio', 'nonce' );
    $uid = get_current_user_id();
    if ( ! $uid ) wp_send_json_error( array( 'message' => __( 'Please log in again.', 'gend-society' ) ), 403 );
    return $uid;
}

/** Store an uploaded $_FILES[$field] as the member's attachment, restricted to $mimes / $max MB. */
function gend_society_portfolio_store_upload( $field, $mimes, $max_mb, $uid, $parent = 0 ) {
    if ( empty( $_FILES[ $field ] ) || ! empty( $_FILES[ $field ]['error'] ) ) return new WP_Error( 'gs_pf', __( 'No file received.', 'gend-society' ) );
    if ( (int) $_FILES[ $field ]['size'] > $max_mb * MB_IN_BYTES ) return new WP_Error( 'gs_pf', sprintf( __( 'That file is larger than %d MB.', 'gend-society' ), $max_mb ) );
    $check = wp_check_filetype_and_ext( $_FILES[ $field ]['tmp_name'], $_FILES[ $field ]['name'], $mimes );
    if ( empty( $check['type'] ) || ! in_array( $check['type'], $mimes, true ) ) return new WP_Error( 'gs_pf', __( 'That file type isn’t allowed here.', 'gend-society' ) );
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    return media_handle_upload( $field, $parent, array( 'post_author' => $uid ), array( 'test_form' => false, 'mimes' => $mimes ) );
}

add_action( 'wp_ajax_gend_society_portfolio_upload', function () {
    global $wpdb;
    $uid = gend_society_portfolio_require_owner();
    $types = gend_society_portfolio_types();
    $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
    if ( ! isset( $types[ $type ] ) ) wp_send_json_error( array( 'message' => __( 'Choose what you’re adding.', 'gend-society' ) ), 400 );
    $max = min( $types[ $type ]['max'], (int) floor( wp_max_upload_size() / MB_IN_BYTES ) );
    $id = gend_society_portfolio_store_upload( 'file', $types[ $type ]['mimes'], $max, $uid );
    if ( is_wp_error( $id ) ) wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
    $t = $wpdb->prefix . 'youzify_media';
    $row_id = 0;
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
        $wpdb->insert( $t, array( 'media_id' => $id, 'user_id' => $uid, 'data' => '0', 'item_id' => 0, 'privacy' => 'public',
            'source' => 'album_' . $type, 'type' => $type, 'component' => 'user', 'time' => current_time( 'mysql' ) ) );
        $row_id = (int) $wpdb->insert_id;
    }
    $row = (object) array( 'id' => $row_id, 'media_id' => $id, 'type' => $type, 'component' => 'user', 'time' => current_time( 'mysql' ) );
    $it = gend_society_portfolio_media_item( $row, true );
    wp_send_json_success( array( 'html' => $it ? gend_society_portfolio_tile( $it ) : '', 'kind' => $type ) );
} );

add_action( 'wp_ajax_gend_society_portfolio_remove', function () {
    global $wpdb;
    $uid = gend_society_portfolio_require_owner();
    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $t = $wpdb->prefix . 'youzify_media';
    $row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND user_id = %d AND component = 'user'", $id, $uid ) ) : null;
    if ( ! $row ) wp_send_json_error( array( 'message' => __( 'That item can’t be removed here.', 'gend-society' ) ), 404 );
    $wpdb->delete( $t, array( 'id' => $id ) );
    if ( (int) get_post_field( 'post_author', (int) $row->media_id ) === $uid ) wp_delete_attachment( (int) $row->media_id, true );
    wp_send_json_success();
} );

add_action( 'wp_ajax_gend_society_portfolio_post', function () {
    $uid = gend_society_portfolio_require_owner();
    $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
    $body  = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
    if ( $title === '' || trim( wp_strip_all_tags( $body ) ) === '' ) wp_send_json_error( array( 'message' => __( 'Add a title and some text.', 'gend-society' ) ), 400 );
    $status = user_can( $uid, 'publish_posts' ) ? 'publish' : 'pending';
    $pid = wp_insert_post( array( 'post_type' => 'post', 'post_status' => $status, 'post_title' => $title, 'post_content' => wpautop( $body ), 'post_author' => $uid ), true );
    if ( is_wp_error( $pid ) ) wp_send_json_error( array( 'message' => $pid->get_error_message() ), 400 );
    if ( ! empty( $_FILES['image'] ) && empty( $_FILES['image']['error'] ) ) {
        $img = gend_society_portfolio_store_upload( 'image', gend_society_portfolio_types()['image']['mimes'], 10, $uid, $pid );
        if ( ! is_wp_error( $img ) ) set_post_thumbnail( $pid, $img );
    }
    $p = get_post( $pid );
    $it = array( 'kind' => 'post', 'id' => $pid, 'title' => get_the_title( $p ), 'thumb' => (string) get_the_post_thumbnail_url( $p, 'medium_large' ), 'full' => (string) get_permalink( $p ),
        'date' => get_the_date( 'M j, Y', $p ), 'excerpt' => wp_trim_words( wp_strip_all_tags( $p->post_content ), 18, '…' ), 'status' => $status, 'mine' => false );
    wp_send_json_success( array( 'html' => gend_society_portfolio_tile( $it ), 'status' => $status,
        'message' => $status === 'publish' ? __( 'Post published.', 'gend-society' ) : __( 'Post submitted for review.', 'gend-society' ) ) );
} );
