<?php
/**
 * Member Playlists
 *
 * Per-user playlists of mixed consumable content (audio / video / article).
 * Items are stored as an ordered JSON array in post_meta `_gdc_playlist_items`
 * — see project_currency_hold_accelerator note style; one CPT row per playlist,
 * cheap to reorder, expensive to query "which playlists contain post X" (out of
 * scope for v1).
 *
 * Per-user resume position lives in user_meta `gdc_pl_progress_{playlist_id}`
 * so a viewer's progress on someone else's public playlist doesn't bloat the
 * owner's row.
 *
 * REST surface: gs/v1/playlists (mirrors invite endpoints in profile-invite-oauth.php).
 * Profile tab: "Library" via bp_setup_nav @ 100 (mirrors gs_add_wallet_profile_tab).
 *
 * Player UI is intentionally not in this file — scaffold lands data + API + tab
 * shell; player ships in a follow-up pass.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const GDC_PLAYLIST_CPT          = 'gdc_playlist';
const GDC_PLAYLIST_ITEMS_META   = '_gdc_playlist_items';
const GDC_PLAYLIST_VIS_META     = '_gdc_playlist_visibility';
const GDC_PLAYLIST_MAX_ITEMS    = 500;
const GDC_PLAYLIST_ALLOWED_TYPES   = [ 'audio', 'video', 'article' ];
const GDC_PLAYLIST_ALLOWED_SOURCES = [ 'internal', 'youtube', 'vimeo', 'spotify', 'url' ];
const GDC_PLAYLIST_ALLOWED_VIS     = [ 'private', 'unlisted', 'members', 'public' ];

// ─── CPT Registration ─────────────────────────────────────────────────────────

add_action( 'init', 'gdc_register_playlist_cpt' );
function gdc_register_playlist_cpt() {
    register_post_type( GDC_PLAYLIST_CPT, [
        'label'              => 'Playlists',
        'labels'             => [
            'name'          => 'Playlists',
            'singular_name' => 'Playlist',
            'edit_item'     => 'Edit Playlist',
            'view_item'     => 'View Playlist',
            'search_items'  => 'Search Playlists',
            'not_found'     => 'No playlists found.',
        ],
        'description'        => 'Per-user ordered list of consumable content (audio/video/article).',
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => false,        // user-facing only; no wp-admin list
        'show_in_menu'       => false,
        'show_in_rest'       => false,        // bespoke REST under gs/v1 instead
        'supports'           => [ 'title', 'editor', 'thumbnail', 'author' ],
        'capability_type'    => GDC_PLAYLIST_CPT,
        'map_meta_cap'       => true,
        'rewrite'            => false,
    ] );

    // Register the items + visibility meta keys so wp_insert_post()'s meta_input
    // and update_post_meta() both behave consistently with sanitization.
    register_post_meta( GDC_PLAYLIST_CPT, GDC_PLAYLIST_ITEMS_META, [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'gdc_playlist_sanitize_items_meta',
    ] );
    register_post_meta( GDC_PLAYLIST_CPT, GDC_PLAYLIST_VIS_META, [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'gdc_playlist_sanitize_visibility',
    ] );
}

// ─── Capability filter ────────────────────────────────────────────────────────
// Owner gets full CPT caps for their own playlist posts. Mirrors the pattern
// in member-profile-pages.php but generalized to many posts per user.

add_filter( 'user_has_cap', 'gdc_playlist_user_caps', 10, 4 );
function gdc_playlist_user_caps( $allcaps, $caps, $args, $user ) {
    if ( ! ( $user instanceof WP_User ) || ! $user->ID ) return $allcaps;

    $needs_playlist_cap = false;
    foreach ( $caps as $cap ) {
        if ( is_string( $cap ) && strpos( $cap, GDC_PLAYLIST_CPT ) !== false ) {
            $needs_playlist_cap = true;
            break;
        }
    }
    if ( ! $needs_playlist_cap ) return $allcaps;

    $post_id = isset( $args[2] ) ? (int) $args[2] : 0;

    // Specific post: only the author gets the cap.
    if ( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || (int) $post->post_author !== (int) $user->ID ) {
            return $allcaps;
        }
    }
    // No specific post (REST collection checks etc.) — grant; per-post checks
    // above handle the row-level enforcement on writes.

    foreach ( $caps as $cap ) {
        if ( is_string( $cap ) ) $allcaps[ $cap ] = true;
    }
    return $allcaps;
}

// ─── Sanitization helpers ─────────────────────────────────────────────────────

function gdc_playlist_sanitize_visibility( $value ) {
    return in_array( $value, GDC_PLAYLIST_ALLOWED_VIS, true ) ? $value : 'private';
}

/**
 * Accepts either a JSON string (from update_post_meta with our normalized blob)
 * or an array (from a direct meta_input use). Always returns a JSON string of
 * the cleaned-up items list, capped at GDC_PLAYLIST_MAX_ITEMS.
 */
function gdc_playlist_sanitize_items_meta( $value ) {
    if ( is_string( $value ) ) {
        $decoded = json_decode( $value, true );
        $value   = is_array( $decoded ) ? $decoded : [];
    }
    if ( ! is_array( $value ) ) $value = [];

    return wp_json_encode( gdc_playlist_normalize_items( $value ) );
}

/**
 * Server-side normalization: drop anything we don't recognize, cap length,
 * cache title/duration so the list view doesn't N+1 on each render.
 */
function gdc_playlist_normalize_items( array $items ) {
    $clean = [];
    foreach ( $items as $raw ) {
        if ( ! is_array( $raw ) ) continue;

        $type   = isset( $raw['type'] )   ? (string) $raw['type']   : '';
        $source = isset( $raw['source'] ) ? (string) $raw['source'] : '';
        if ( ! in_array( $type,   GDC_PLAYLIST_ALLOWED_TYPES,   true ) ) continue;
        if ( ! in_array( $source, GDC_PLAYLIST_ALLOWED_SOURCES, true ) ) continue;

        // ref: positive int (internal post_id) when source=internal, else URL.
        $ref = $raw['ref'] ?? null;
        if ( $source === 'internal' ) {
            $ref = (int) $ref;
            if ( $ref <= 0 ) continue;
        } else {
            $ref = is_string( $ref ) ? esc_url_raw( $ref ) : '';
            if ( ! $ref ) continue;
        }

        $clean[] = [
            'type'          => $type,
            'source'        => $source,
            'ref'           => $ref,
            'title'         => isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '',
            'thumbnail_url' => isset( $raw['thumbnail_url'] ) ? esc_url_raw( (string) $raw['thumbnail_url'] ) : '',
            'duration_sec' => isset( $raw['duration_sec'] ) ? max( 0, (int) $raw['duration_sec'] ) : 0,
            'excerpt'       => isset( $raw['excerpt'] ) ? sanitize_text_field( mb_substr( (string) $raw['excerpt'], 0, 280 ) ) : '',
        ];

        if ( count( $clean ) >= GDC_PLAYLIST_MAX_ITEMS ) break;
    }
    return $clean;
}

// ─── Read helpers ─────────────────────────────────────────────────────────────

function gdc_playlist_get_items( $playlist_id ) {
    $raw = get_post_meta( (int) $playlist_id, GDC_PLAYLIST_ITEMS_META, true );
    if ( ! is_string( $raw ) || $raw === '' ) return [];
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function gdc_playlist_get_visibility( $playlist_id ) {
    $v = (string) get_post_meta( (int) $playlist_id, GDC_PLAYLIST_VIS_META, true );
    return in_array( $v, GDC_PLAYLIST_ALLOWED_VIS, true ) ? $v : 'private';
}

/**
 * Can the current user (viewer) see this playlist? Owner always can.
 */
function gdc_playlist_viewer_can_read( $post, $viewer_id ) {
    if ( ! $post instanceof WP_Post ) return false;
    if ( $post->post_type !== GDC_PLAYLIST_CPT ) return false;

    $viewer_id = (int) $viewer_id;
    if ( $viewer_id && $viewer_id === (int) $post->post_author ) return true;

    $vis = gdc_playlist_get_visibility( $post->ID );
    switch ( $vis ) {
        case 'public':   return true;
        case 'members':  return $viewer_id > 0;
        case 'unlisted': return true; // link-only — caller must already hold the id
        case 'private':
        default:         return false;
    }
}

function gdc_playlist_to_array( WP_Post $post, $include_items = true ) {
    $out = [
        'id'          => (int) $post->ID,
        'author'      => (int) $post->post_author,
        'title'       => $post->post_title,
        'description' => $post->post_content,
        'visibility'  => gdc_playlist_get_visibility( $post->ID ),
        'created'     => mysql_to_rfc3339( $post->post_date_gmt ),
        'modified'    => mysql_to_rfc3339( $post->post_modified_gmt ),
        'item_count'  => count( gdc_playlist_get_items( $post->ID ) ),
    ];
    if ( $include_items ) $out['items'] = gdc_playlist_get_items( $post->ID );
    return $out;
}

// ─── REST: gs/v1/playlists ────────────────────────────────────────────────────

add_action( 'rest_api_init', 'gdc_playlist_register_routes' );
function gdc_playlist_register_routes() {
    $require_login = function () {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'gs_pl_auth', 'Login required.', [ 'status' => 401 ] );
    };

    register_rest_route( 'gs/v1', '/playlists', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'gdc_playlist_rest_list',
            'permission_callback' => '__return_true', // visibility checked per-row
            'args'                => [
                'user_id'       => [ 'type' => 'integer' ],
                'include_items' => [ 'type' => 'boolean', 'default' => false ],
            ],
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'gdc_playlist_rest_create',
            'permission_callback' => $require_login,
        ],
    ] );

    register_rest_route( 'gs/v1', '/playlists/(?P<id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'gdc_playlist_rest_get',
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => [ 'PATCH', 'PUT' ],
            'callback'            => 'gdc_playlist_rest_update',
            'permission_callback' => $require_login,
        ],
        [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'gdc_playlist_rest_delete',
            'permission_callback' => $require_login,
        ],
    ] );

    register_rest_route( 'gs/v1', '/playlists/(?P<id>\d+)/progress', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'gdc_playlist_rest_get_progress',
            'permission_callback' => $require_login,
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'gdc_playlist_rest_save_progress',
            'permission_callback' => $require_login,
        ],
    ] );
}

function gdc_playlist_rest_list( WP_REST_Request $req ) {
    $viewer  = (int) get_current_user_id();
    $user_id = (int) $req->get_param( 'user_id' );
    if ( ! $user_id ) {
        if ( ! $viewer ) return new WP_Error( 'gs_pl_auth', 'Login required to list own playlists.', [ 'status' => 401 ] );
        $user_id = $viewer;
    }

    $posts = get_posts( [
        'post_type'      => GDC_PLAYLIST_CPT,
        'author'         => $user_id,
        'posts_per_page' => 200,
        'post_status'    => 'publish',
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ] );

    $include = (bool) $req->get_param( 'include_items' );
    $out = [];
    foreach ( $posts as $p ) {
        if ( ! gdc_playlist_viewer_can_read( $p, $viewer ) ) continue;
        $out[] = gdc_playlist_to_array( $p, $include );
    }
    return rest_ensure_response( $out );
}

function gdc_playlist_rest_get( WP_REST_Request $req ) {
    $id   = (int) $req['id'];
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== GDC_PLAYLIST_CPT ) {
        return new WP_Error( 'gs_pl_not_found', 'Playlist not found.', [ 'status' => 404 ] );
    }
    if ( ! gdc_playlist_viewer_can_read( $post, get_current_user_id() ) ) {
        return new WP_Error( 'gs_pl_forbidden', 'Not allowed.', [ 'status' => 403 ] );
    }
    return rest_ensure_response( gdc_playlist_to_array( $post, true ) );
}

function gdc_playlist_rest_create( WP_REST_Request $req ) {
    $user_id = (int) get_current_user_id();
    $body    = $req->get_json_params() ?: $req->get_params();

    $title = isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '';
    if ( $title === '' ) $title = 'Untitled Playlist';

    $post_id = wp_insert_post( [
        'post_type'    => GDC_PLAYLIST_CPT,
        'post_status'  => 'publish',
        'post_author'  => $user_id,
        'post_title'   => $title,
        'post_content' => isset( $body['description'] ) ? wp_kses_post( (string) $body['description'] ) : '',
    ], true );

    if ( is_wp_error( $post_id ) ) return $post_id;

    $vis = isset( $body['visibility'] )
        ? gdc_playlist_sanitize_visibility( $body['visibility'] )
        : 'private';
    update_post_meta( $post_id, GDC_PLAYLIST_VIS_META, $vis );

    if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
        update_post_meta( $post_id, GDC_PLAYLIST_ITEMS_META,
            wp_json_encode( gdc_playlist_normalize_items( $body['items'] ) ) );
    }

    return rest_ensure_response( gdc_playlist_to_array( get_post( $post_id ), true ) );
}

function gdc_playlist_rest_update( WP_REST_Request $req ) {
    $id   = (int) $req['id'];
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== GDC_PLAYLIST_CPT ) {
        return new WP_Error( 'gs_pl_not_found', 'Playlist not found.', [ 'status' => 404 ] );
    }
    if ( (int) $post->post_author !== (int) get_current_user_id() ) {
        return new WP_Error( 'gs_pl_forbidden', 'Not allowed.', [ 'status' => 403 ] );
    }

    $body = $req->get_json_params() ?: $req->get_params();

    $update = [ 'ID' => $id ];
    if ( array_key_exists( 'title', $body ) ) {
        $update['post_title'] = sanitize_text_field( (string) $body['title'] );
    }
    if ( array_key_exists( 'description', $body ) ) {
        $update['post_content'] = wp_kses_post( (string) $body['description'] );
    }
    if ( count( $update ) > 1 ) wp_update_post( $update );

    if ( array_key_exists( 'visibility', $body ) ) {
        update_post_meta( $id, GDC_PLAYLIST_VIS_META,
            gdc_playlist_sanitize_visibility( $body['visibility'] ) );
    }
    if ( array_key_exists( 'items', $body ) && is_array( $body['items'] ) ) {
        update_post_meta( $id, GDC_PLAYLIST_ITEMS_META,
            wp_json_encode( gdc_playlist_normalize_items( $body['items'] ) ) );
    }

    return rest_ensure_response( gdc_playlist_to_array( get_post( $id ), true ) );
}

function gdc_playlist_rest_delete( WP_REST_Request $req ) {
    $id   = (int) $req['id'];
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== GDC_PLAYLIST_CPT ) {
        return new WP_Error( 'gs_pl_not_found', 'Playlist not found.', [ 'status' => 404 ] );
    }
    if ( (int) $post->post_author !== (int) get_current_user_id() ) {
        return new WP_Error( 'gs_pl_forbidden', 'Not allowed.', [ 'status' => 403 ] );
    }
    wp_delete_post( $id, true );
    return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
}

// ─── Progress (per-viewer resume state) ───────────────────────────────────────

function gdc_playlist_progress_meta_key( $playlist_id ) {
    return 'gdc_pl_progress_' . (int) $playlist_id;
}

function gdc_playlist_rest_get_progress( WP_REST_Request $req ) {
    $id   = (int) $req['id'];
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== GDC_PLAYLIST_CPT ) {
        return new WP_Error( 'gs_pl_not_found', 'Playlist not found.', [ 'status' => 404 ] );
    }
    if ( ! gdc_playlist_viewer_can_read( $post, get_current_user_id() ) ) {
        return new WP_Error( 'gs_pl_forbidden', 'Not allowed.', [ 'status' => 403 ] );
    }
    $raw = get_user_meta( get_current_user_id(), gdc_playlist_progress_meta_key( $id ), true );
    return rest_ensure_response( is_array( $raw ) ? $raw : (object) [] );
}

function gdc_playlist_rest_save_progress( WP_REST_Request $req ) {
    $id   = (int) $req['id'];
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== GDC_PLAYLIST_CPT ) {
        return new WP_Error( 'gs_pl_not_found', 'Playlist not found.', [ 'status' => 404 ] );
    }
    if ( ! gdc_playlist_viewer_can_read( $post, get_current_user_id() ) ) {
        return new WP_Error( 'gs_pl_forbidden', 'Not allowed.', [ 'status' => 403 ] );
    }
    $body = $req->get_json_params() ?: $req->get_params();

    $progress = [
        'idx'         => max( 0, (int) ( $body['idx'] ?? 0 ) ),
        'pos_sec'     => max( 0, (int) ( $body['pos_sec'] ?? 0 ) ),
        'completed'   => [],
        'last_played' => time(),
    ];
    if ( isset( $body['completed'] ) && is_array( $body['completed'] ) ) {
        foreach ( $body['completed'] as $i ) {
            $i = (int) $i;
            if ( $i >= 0 ) $progress['completed'][] = $i;
        }
        $progress['completed'] = array_values( array_unique( $progress['completed'] ) );
    }

    update_user_meta( get_current_user_id(), gdc_playlist_progress_meta_key( $id ), $progress );
    return rest_ensure_response( [ 'ok' => true, 'progress' => $progress ] );
}

// ─── BuddyPress profile tab ───────────────────────────────────────────────────
// Mirrors gs_add_wallet_profile_tab in member-profile-pages.php — registers a
// "Library" tab so visitors land on the displayed user's playlists.

add_action( 'bp_setup_nav', 'gs_add_library_profile_tab', 100 );
function gs_add_library_profile_tab() {
    if ( ! function_exists( 'bp_core_new_nav_item' ) ) return;

    bp_core_new_nav_item( [
        'name'                    => __( 'Library', 'gend-society' ),
        'slug'                    => 'library',
        'screen_function'         => 'gs_library_profile_screen',
        'position'                => 40,
        'item_css_id'             => 'library',
        'show_for_displayed_user' => true,
    ] );
}

function gs_library_profile_screen() {
    add_action( 'bp_template_title', '__return_empty_string' );
    add_action( 'bp_template_content', 'gs_library_profile_screen_content' );
    bp_core_load_template( 'members/single/plugins' );
}

/**
 * Scaffold content: lists the displayed user's visible playlists and exposes
 * a "New Playlist" CTA for the owner. The interactive player + add-item UI
 * land in a follow-up file (member-playlists-ui.php) — keeping this PR
 * focused on data + API + tab shell.
 */
function gs_library_profile_screen_content() {
    if ( ! function_exists( 'bp_displayed_user_id' ) ) return;
    $displayed_user = (int) bp_displayed_user_id();
    $viewer         = (int) get_current_user_id();
    $is_own         = ( $viewer && $viewer === $displayed_user );

    $posts = get_posts( [
        'post_type'      => GDC_PLAYLIST_CPT,
        'author'         => $displayed_user,
        'posts_per_page' => 200,
        'post_status'    => 'publish',
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ] );

    $visible = array_filter( $posts, function ( $p ) use ( $viewer ) {
        return gdc_playlist_viewer_can_read( $p, $viewer );
    } );
    ?>
    <section class="gs-library-tab" data-user-id="<?php echo esc_attr( $displayed_user ); ?>" data-is-own="<?php echo $is_own ? '1' : '0'; ?>">
        <header class="gs-library-header">
            <h2><?php echo esc_html( $is_own ? __( 'My Library', 'gend-society' ) : __( 'Library', 'gend-society' ) ); ?></h2>
            <?php if ( $is_own ) : ?>
                <button type="button" class="gs-library-new" data-action="new-playlist">
                    <?php echo esc_html__( 'New Playlist', 'gend-society' ); ?>
                </button>
            <?php endif; ?>
        </header>

        <?php if ( empty( $visible ) ) : ?>
            <p class="gs-library-empty">
                <?php echo esc_html( $is_own
                    ? __( 'No playlists yet. Create one to start collecting audio, video, and articles to consume later.', 'gend-society' )
                    : __( 'Nothing here yet.', 'gend-society' ) ); ?>
            </p>
        <?php else : ?>
            <ul class="gs-library-list">
                <?php foreach ( $visible as $p ) :
                    $items = gdc_playlist_get_items( $p->ID );
                    $vis   = gdc_playlist_get_visibility( $p->ID );
                ?>
                    <li class="gs-library-item" data-playlist-id="<?php echo esc_attr( $p->ID ); ?>">
                        <span class="gs-library-item-title"><?php echo esc_html( $p->post_title ); ?></span>
                        <span class="gs-library-item-meta">
                            <?php echo esc_html( sprintf( _n( '%d item', '%d items', count( $items ), 'gend-society' ), count( $items ) ) ); ?>
                            · <?php echo esc_html( $vis ); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
}
