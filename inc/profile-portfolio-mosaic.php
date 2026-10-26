<?php
/**
 * Overview › Portfolio (formerly Media): one mosaic of the member's media — Youzify photos / videos / audio / files,
 * blog posts and playlists — with filters (All · Photos · Videos · Audio · Files · Blog Posts · Playlists). The Blog
 * Posts and Playlists filters show the former Posts and Playlists sub-tabs (gend_society_portfolio_render_posts(),
 * blog-manager's bm_render_library_section()).
 *
 * Owners get "Add new" — a popup of type cards, each opening its own dashboard:
 *   Photo / Video / Audio / File → drag-and-drop upload dashboard (gend_society_portfolio_upload): media-library
 *     attachments authored by the member, registered in Youzify's media table (component user)
 *   Blog post → posts dashboard + the WordPress block editor (categories, featured image, gend-society SEO panel)
 *     in the popup. Members without edit rights get a scoped editing session (gend_society_member_post_*): a signed
 *     4-hour cookie that lets them write their OWN drafts — never publish, edit published or others' posts — and
 *     only on the post editor screens, the core editor REST routes and the editor's own AJAX actions
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
    $upload_cap = (int) floor( wp_max_upload_size() / MB_IN_BYTES );
    $type_meta = array();
    $plural = array( 'image' => __( 'Photos', 'gend-society' ), 'video' => __( 'Videos', 'gend-society' ), 'audio' => __( 'Audio', 'gend-society' ), 'file' => __( 'Files', 'gend-society' ) );
    $blurb = array(
        'image' => __( 'Show your work in pictures — screenshots, designs, events and products.', 'gend-society' ),
        'video' => __( 'Demos, walkthroughs and highlight reels that play right on your profile.', 'gend-society' ),
        'audio' => __( 'Podcasts, interviews, music and voice notes for your audience.', 'gend-society' ),
        'file'  => __( 'Decks, case studies, one-pagers and documents people can open.', 'gend-society' ),
    );
    foreach ( $types as $k => $t ) {
        $type_meta[ $k ] = array( 'label' => $t['label'], 'plural' => $plural[ $k ], 'accept' => $t['accept'], 'max' => min( $t['max'], $upload_cap ),
            'blurb' => $blurb[ $k ], 'formats' => strtoupper( implode( ', ', array_map( function ( $x ) { return str_replace( '|', ' / ', $x ); }, array_keys( $t['mimes'] ) ) ) ), 'count' => $counts[ $k ] );
    }
    $own_posts = $is_own ? get_posts( array( 'post_type' => 'post', 'author' => $user_id, 'post_status' => array( 'publish', 'pending', 'draft' ), 'posts_per_page' => 40 ) ) : array();
    $post_counts = array( 'publish' => 0, 'pending' => 0, 'draft' => 0 );
    foreach ( $own_posts as $op ) $post_counts[ $op->post_status ]++;
    ?>
    <div class="gs-pf" data-gs-pf data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $is_own ? wp_create_nonce( 'gs_portfolio' ) : '' ); ?>"<?php if ( $is_own ) : ?> data-types="<?php echo esc_attr( wp_json_encode( $type_meta ) ); ?>"<?php endif; ?>>
        <div class="gs-pf-bar">
            <div class="gs-pf-filters" role="tablist">
                <?php foreach ( $filters as $k => $l ) : $n = $k === 'all' ? count( $items ) : $counts[ $k ]; ?>
                <button type="button" class="gs-pf-filter<?php echo $k === 'all' ? ' is-active' : ''; ?>" data-gs-pf-filter="<?php echo esc_attr( $k ); ?>" role="tab" aria-selected="<?php echo $k === 'all' ? 'true' : 'false'; ?>">
                    <?php echo esc_html( $l ); ?><?php if ( $n ) : ?> <span><?php echo (int) $n; ?></span><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if ( $is_own ) : ?>
            <button type="button" class="gs-pf-add__btn" data-gs-pf-open="addnew"><span aria-hidden="true">+</span> <?php esc_html_e( 'Add new', 'gend-society' ); ?></button>
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

        <?php if ( $is_own ) gend_society_portfolio_owner_popups( $type_meta, $own_posts, $post_counts, $has_playlists, $counts ); ?>
    </div>
    <?php
}

/** Inline SVG icon for a type card. */
function gend_society_portfolio_icon( $k ) {
    $paths = array(
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'video'    => '<rect x="2.5" y="5" width="14" height="14" rx="3"/><path d="m16.5 10 5-3v10l-5-3"/>',
        'audio'    => '<path d="M9 18V6l11-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>',
        'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'post'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13 7 4 4"/><path d="M14 20h6"/>',
        'playlist' => '<path d="M3 6h12M3 11h12M3 16h7"/><path d="m16 14 5 3-5 3z"/>',
    );
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $k ] . '</svg>';
}

/** Owner popups: Add new (type cards), media upload dashboard, blog post dashboard + block editor. */
function gend_society_portfolio_owner_popups( $type_meta, $own_posts, $post_counts, $has_playlists, $counts ) {
    $cards = array();
    foreach ( $type_meta as $k => $t ) $cards[ $k ] = array( 'title' => $t['label'], 'text' => $t['blurb'], 'n' => $t['count'], 'open' => 'media:' . $k );
    $cards['post'] = array( 'title' => __( 'Blog post', 'gend-society' ), 'text' => __( 'Write in the full block editor with categories, a featured image and SEO settings.', 'gend-society' ), 'n' => $counts['post'], 'open' => 'post' );
    if ( $has_playlists ) $cards['playlist'] = array( 'title' => __( 'Playlist', 'gend-society' ), 'text' => __( 'Curate videos, tracks and posts into a collection people can follow.', 'gend-society' ), 'n' => $counts['playlist'], 'open' => 'playlist' );
    $can_publish = current_user_can( 'publish_posts' );
    ?>
    <div class="gs-pf-modal" data-gs-pf-modal="addnew" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Add to your portfolio', 'gend-society' ); ?>">
        <div class="gs-pf-modal__box gs-pf-modal__box--wide">
            <button type="button" class="gs-pf-modal__x" data-gs-pf-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
            <header class="gs-pf-hero">
                <span class="gs-pf-hero__eyebrow"><?php esc_html_e( 'Portfolio studio', 'gend-society' ); ?></span>
                <h3><?php esc_html_e( 'What would you like to add?', 'gend-society' ); ?></h3>
                <p><?php esc_html_e( 'Everything you add shows in your Portfolio mosaic for visitors to explore.', 'gend-society' ); ?></p>
            </header>
            <div class="gs-pf-types">
                <?php foreach ( $cards as $k => $c ) : ?>
                <button type="button" class="gs-pf-type gs-pf-type--<?php echo esc_attr( $k ); ?>" data-gs-pf-open="<?php echo esc_attr( $c['open'] ); ?>">
                    <span class="gs-pf-type__icon"><?php echo gend_society_portfolio_icon( $k ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="gs-pf-type__title"><?php echo esc_html( $c['title'] ); ?></span>
                    <span class="gs-pf-type__text"><?php echo esc_html( $c['text'] ); ?></span>
                    <span class="gs-pf-type__foot"><span class="gs-pf-type__n"><?php echo esc_html( sprintf( _n( '%d in portfolio', '%d in portfolio', (int) $c['n'], 'gend-society' ), (int) $c['n'] ) ); ?></span><span class="gs-pf-type__go" aria-hidden="true">→</span></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="gs-pf-modal" data-gs-pf-modal="media" hidden role="dialog" aria-modal="true" aria-labelledby="gs-pf-media-title">
        <div class="gs-pf-modal__box gs-pf-modal__box--wide gs-pf-dash" data-gs-pf-media-dash>
            <button type="button" class="gs-pf-modal__x" data-gs-pf-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
            <button type="button" class="gs-pf-back" data-gs-pf-open="addnew">← <?php esc_html_e( 'All types', 'gend-society' ); ?></button>
            <header class="gs-pf-dash__head">
                <span class="gs-pf-dash__icon" data-gs-pf-media-icon></span>
                <div>
                    <h3 id="gs-pf-media-title" data-gs-pf-media-title></h3>
                    <p data-gs-pf-media-blurb></p>
                </div>
                <div class="gs-pf-dash__stats">
                    <div><strong data-gs-pf-media-count>0</strong><span><?php esc_html_e( 'in portfolio', 'gend-society' ); ?></span></div>
                    <div><strong data-gs-pf-media-session>0</strong><span><?php esc_html_e( 'added now', 'gend-society' ); ?></span></div>
                </div>
            </header>
            <label class="gs-pf-drop" data-gs-pf-drop>
                <input type="file" multiple data-gs-pf-files hidden>
                <span class="gs-pf-drop__icon" data-gs-pf-drop-icon></span>
                <strong><?php esc_html_e( 'Drag & drop files here', 'gend-society' ); ?></strong>
                <span><?php esc_html_e( 'or click to choose from your device', 'gend-society' ); ?></span>
                <small data-gs-pf-media-rules></small>
            </label>
            <ul class="gs-pf-queue" data-gs-pf-queue></ul>
            <section class="gs-pf-recent">
                <h4><?php esc_html_e( 'Recently added', 'gend-society' ); ?></h4>
                <div class="gs-pf-recent__grid" data-gs-pf-recent></div>
            </section>
        </div>
    </div>

    <div class="gs-pf-modal" data-gs-pf-modal="post" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Blog posts', 'gend-society' ); ?>">
        <div class="gs-pf-modal__box gs-pf-modal__box--wide gs-pf-dash" data-gs-pf-post-dash>
            <button type="button" class="gs-pf-modal__x" data-gs-pf-close aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
            <button type="button" class="gs-pf-back" data-gs-pf-open="addnew">← <?php esc_html_e( 'All types', 'gend-society' ); ?></button>
            <header class="gs-pf-dash__head">
                <span class="gs-pf-dash__icon gs-pf-type--post"><?php echo gend_society_portfolio_icon( 'post' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <div>
                    <h3><?php esc_html_e( 'Blog posts', 'gend-society' ); ?></h3>
                    <p><?php echo $can_publish ? esc_html__( 'Write in the WordPress block editor — set categories, a featured image and SEO, then publish.', 'gend-society' ) : esc_html__( 'Write in the WordPress block editor — set categories, a featured image and SEO, then submit for review. The gend.me team approves posts before they go public.', 'gend-society' ); ?></p>
                </div>
                <div class="gs-pf-dash__stats">
                    <div><strong><?php echo (int) $post_counts['publish']; ?></strong><span><?php esc_html_e( 'published', 'gend-society' ); ?></span></div>
                    <div><strong><?php echo (int) $post_counts['pending']; ?></strong><span><?php esc_html_e( 'in review', 'gend-society' ); ?></span></div>
                    <div><strong><?php echo (int) $post_counts['draft']; ?></strong><span><?php esc_html_e( 'drafts', 'gend-society' ); ?></span></div>
                </div>
            </header>
            <button type="button" class="gs-pf-start" data-gs-pf-editor="0">
                <span class="gs-pf-start__icon"><?php echo gend_society_portfolio_icon( 'post' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span><strong><?php esc_html_e( 'Start a new post', 'gend-society' ); ?></strong><small><?php esc_html_e( 'Opens the block editor right here', 'gend-society' ); ?></small></span>
                <span class="gs-pf-type__go" aria-hidden="true">→</span>
            </button>
            <?php if ( $own_posts ) : ?>
            <ul class="gs-pf-postlist">
                <?php foreach ( $own_posts as $op ) :
                    $st = $op->post_status;
                    $label = array( 'publish' => __( 'Published', 'gend-society' ), 'pending' => __( 'In review', 'gend-society' ), 'draft' => __( 'Draft', 'gend-society' ) )[ $st ];
                ?>
                <li>
                    <span class="gs-pf-postlist__thumb"<?php $th = get_the_post_thumbnail_url( $op, 'thumbnail' ); echo $th ? ' style="background-image:url(' . esc_url( $th ) . ')"' : ''; ?>></span>
                    <span class="gs-pf-postlist__meta"><b><?php echo esc_html( get_the_title( $op ) ?: __( '(no title)', 'gend-society' ) ); ?></b><small><?php echo esc_html( get_the_modified_date( 'M j, Y', $op ) ); ?></small></span>
                    <span class="gs-pf-badge<?php echo $st === 'publish' ? '' : ' is-review'; ?>"><?php echo esc_html( $label ); ?></span>
                    <?php if ( $st === 'publish' ) : ?>
                        <a class="gs-pf-mini" href="<?php echo esc_url( get_permalink( $op ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'gend-society' ); ?></a>
                    <?php else : ?>
                        <button type="button" class="gs-pf-mini" data-gs-pf-editor="<?php echo (int) $op->ID; ?>"><?php esc_html_e( 'Continue editing', 'gend-society' ); ?></button>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <div class="gs-pf-editor" data-gs-pf-editor-stage hidden>
            <div class="gs-pf-editor__bar">
                <button type="button" class="gs-pf-back" data-gs-pf-editor-close>← <?php esc_html_e( 'Back to my posts', 'gend-society' ); ?></button>
                <span><?php echo $can_publish ? esc_html__( 'Publish from the editor’s top bar when you’re ready.', 'gend-society' ) : esc_html__( 'Use “Submit for Review” in the editor’s top bar when you’re ready.', 'gend-society' ); ?></span>
            </div>
            <iframe class="gs-pf-editor__frame" data-gs-pf-editor-frame title="<?php esc_attr_e( 'Block editor', 'gend-society' ); ?>"></iframe>
        </div>
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

// (The short composer endpoint was replaced by the block editor — see the member editing session below.)

// ── Member editing session: blog posts in the WordPress block editor ───────────────────────────────────────────
// Members who can't already write posts get, while a signed 4-hour cookie is valid, `edit_posts` / `upload_files` /
// `delete_posts` — enough for their OWN drafts and "Submit for Review" (never publish_posts, edit_published_posts or
// edit_others_posts) — and only on the post editor screens, the core editor REST routes and the editor's AJAX actions.
const GEND_SOCIETY_MEMBER_POST_COOKIE = 'gs_member_post';

function gend_society_member_post_token( $uid, $exp, $blog_id = 0 ) {
    // Security 2026-10-18: the signature covers the blog the session was issued on (the salt is
    // network-wide and the cookie is sent to every sub-site), so it only works on that blog.
    $blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
    return $uid . '|' . $exp . '|' . hash_hmac( 'sha256', 'gs_member_post|' . $uid . '|' . $exp . '|' . $blog_id, wp_salt( 'auth' ) );
}

function gend_society_member_post_session( $uid ) {
    $raw = isset( $_COOKIE[ GEND_SOCIETY_MEMBER_POST_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ GEND_SOCIETY_MEMBER_POST_COOKIE ] ) : '';
    $parts = explode( '|', $raw );
    if ( count( $parts ) !== 3 || (int) $parts[0] !== (int) $uid || (int) $parts[1] < time() ) return false;
    return hash_equals( gend_society_member_post_token( (int) $parts[0], (int) $parts[1] ), $raw );
}

/** Is this request one the post editor makes? */
function gend_society_member_post_context() {
    global $pagenow;
    if ( wp_doing_ajax() ) {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        return in_array( $action, array( 'heartbeat', 'query-attachments', 'upload-attachment', 'save-attachment', 'save-attachment-compat', 'get-attachment',
            'send-attachment-to-editor', 'wp-remove-post-lock', 'sample-permalink', 'image-editor', 'imgedit-preview', 'crop-image', 'get-post-thumbnail-html',
            'set-post-thumbnail', 'meta-box-order', 'closed-postboxes', 'parse-embed', 'oembed-cache', 'wp-link-ajax' ), true );
    }
    if ( is_admin() ) return in_array( $pagenow, array( 'post.php', 'post-new.php', 'async-upload.php', 'media-upload.php' ), true );
    // Security 2026-10-18: only the REST routes the post editor needs: posts + media (read/write) and
    // a few read-only lookups. Previously every /wp/v2/ and /wp-block-editor/v1/ route matched.
    $uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $route = '';
    if ( isset( $_GET['rest_route'] ) ) {
        $route = (string) wp_unslash( $_GET['rest_route'] );
    } elseif ( ( $p = strpos( $uri, '/wp-json/' ) ) !== false ) {
        $route = substr( $uri, $p + 8 );
        $q     = strpos( $route, '?' );
        if ( $q !== false ) $route = substr( $route, 0, $q );
        $route = rawurldecode( $route );
    }
    if ( $route === '' ) return false;
    $route  = '/' . ltrim( $route, '/' );
    $method = isset( $_GET['_method'] ) ? (string) $_GET['_method']
        : ( isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ? (string) $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] : ( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET' ) );
    $method = strtoupper( $method );
    $match  = function ( $prefixes ) use ( $route ) {
        foreach ( $prefixes as $pre ) {
            if ( $route === $pre || strpos( $route, $pre . '/' ) === 0 ) return true;
        }
        return false;
    };
    if ( $match( array( '/wp/v2/posts', '/wp/v2/media' ) ) ) return true;
    if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) && $match( array(
        '/wp/v2/types', '/wp/v2/taxonomies', '/wp/v2/categories', '/wp/v2/tags', '/wp/v2/users/me', '/wp/v2/statuses',
        '/wp/v2/themes', '/wp/v2/block-types', '/wp/v2/block-patterns', '/wp/v2/global-styles', '/wp/v2/search', '/oembed/1.0',
    ) ) ) return true;
    return false;
}

add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
    if ( empty( $user->ID ) || ! empty( $allcaps['edit_posts'] ) || (int) $user->ID !== get_current_user_id() ) return $allcaps;
    if ( ! array_intersect( (array) $caps, array( 'read', 'edit_posts', 'upload_files', 'delete_posts' ) ) ) return $allcaps;
    if ( ! gend_society_member_post_session( (int) $user->ID ) || ! gend_society_member_post_context() ) return $allcaps;
    foreach ( array( 'read', 'edit_posts', 'upload_files', 'delete_posts' ) as $c ) $allcaps[ $c ] = true;
    return $allcaps;
}, 20, 4 );

// In a session, the media library shows only the member's own uploads.
add_filter( 'ajax_query_attachments_args', function ( $q ) {
    $uid = get_current_user_id();
    if ( $uid && gend_society_member_post_session( $uid ) && ! current_user_can( 'edit_others_posts' ) ) $q['author'] = $uid;
    return $q;
} );

// Owner: open the block editor (new post, or one of their own drafts / posts in review) — starts the session.
add_action( 'wp_ajax_gend_society_portfolio_editor', function () {
    $uid = gend_society_portfolio_require_owner();
    $pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( $pid ) {
        $p = get_post( $pid );
        if ( ! $p || $p->post_type !== 'post' || (int) $p->post_author !== $uid || ! in_array( $p->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'That post can’t be edited here.', 'gend-society' ) ), 403 );
        }
        $url = admin_url( 'post.php?post=' . $pid . '&action=edit&gdc_embed=1' );
    } else {
        $url = admin_url( 'post-new.php?post_type=post&gdc_embed=1' );
    }
    $exp = time() + 4 * HOUR_IN_SECONDS;
    setcookie( GEND_SOCIETY_MEMBER_POST_COOKIE, gend_society_member_post_token( $uid, $exp ), array(
        'expires' => $exp, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
    ) );
    wp_send_json_success( array( 'url' => $url ) );
} );

// The editor inside the popup: no wp-admin chrome, full-screen editing surface.
add_action( 'admin_head', function () {
    global $pagenow, $typenow;
    if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) || ( $typenow ?: 'post' ) !== 'post' ) return;
    $framed = ! empty( $_GET['gdc_embed'] ) || ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe' && gend_society_member_post_session( get_current_user_id() ) );
    if ( ! $framed ) return;
    ?>
    <style id="gs-member-post-embed">
        #wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter, #main-3d-header, .header-anchor-wrap, .gdc-topbar,
        .edit-post-fullscreen-mode-close, .editor-header__back, .edit-post-header__back, #wpbody-content > .notice, #wpbody-content > .error { display: none !important; }
        html.wp-toolbar { padding-top: 0 !important; }
        :root, html, body { --gs-header-h: 0px !important; }
        #wpcontent, #wpbody-content { margin-left: 0 !important; }
        #wpbody { padding-top: 0 !important; }
    </style>
    <script>document.addEventListener('DOMContentLoaded', function () { document.body.classList.add('is-fullscreen-mode'); });</script>
    <?php
} );
