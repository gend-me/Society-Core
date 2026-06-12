<?php
/**
 * gend-society — User-profile router.
 *
 * Per-user, per-blog context that personalises chatflows (industry,
 * project type, brand inputs, last completed step, etc.). The flow
 * engine reads it on start to fill `{{profile.industry}}` variables and
 * writes back when a step has `save_to_profile`.
 *
 * Routes:
 *   GET /gs/v1/user-profile  → { user_id, profile, last_flow, last_step }
 *   PUT /gs/v1/user-profile  → merge-update; returns { success, profile }
 *
 * Storage strategy mirrors the chatflows router:
 *
 *   • Hub (Leo_DB present)  → delegate to Leo_DB so the data stays
 *     compatible with LEO's existing React admin views and the per-blog
 *     keying in wp_leo_user_profile.
 *   • Subsite (no Leo_DB)   → use wp_usermeta with a blog-suffixed key.
 *     usermeta is multisite-global, so the blog suffix keeps each
 *     customer subsite's profile isolated from siblings under the same
 *     gend.me network.
 *
 * The subsite path is local-only by design — profiles are subsite-scoped
 * (each business has its own industry/brand/etc.) so there's no value in
 * forwarding to the hub on every read. Migration target: when Leo_DB
 * eventually moves into gend-society, the hub branch collapses into the
 * subsite branch (single storage path) and Leo_DB can be removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GS_User_Profile_Router {

    const NS       = 'gs/v1';
    const META_KEY = 'gs_chatflow_profile';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/user-profile', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'route_get' ),
                'permission_callback' => array( __CLASS__, 'require_logged_in' ),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( __CLASS__, 'route_put' ),
                'permission_callback' => array( __CLASS__, 'require_logged_in' ),
            ),
        ) );
    }

    public static function require_logged_in() {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'gs_profile_unauth', 'Sign in required.', array( 'status' => 401 ) );
    }

    public static function route_get( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $blog_id = (int) get_current_blog_id();

        if ( class_exists( 'Leo_DB' ) && method_exists( 'Leo_DB', 'get_user_profile' ) ) {
            $row = Leo_DB::get_user_profile( $user_id, $blog_id );
            return new WP_REST_Response( array(
                'user_id'   => $user_id,
                'profile'   => $row ? ( $row['profile']  ?? array() ) : array(),
                'last_flow' => $row['last_flow'] ?? null,
                'last_step' => $row['last_step'] ?? null,
            ), 200 );
        }

        $profile = self::read_local( $user_id, $blog_id );
        return new WP_REST_Response( array(
            'user_id'   => $user_id,
            'profile'   => $profile,
            'last_flow' => $profile['last_flow'] ?? null,
            'last_step' => $profile['last_step'] ?? null,
        ), 200 );
    }

    public static function route_put( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $blog_id = (int) get_current_blog_id();
        $body    = $req->get_json_params();
        if ( ! is_array( $body ) ) $body = json_decode( $req->get_body(), true );
        if ( ! is_array( $body ) ) $body = array();

        $patch = self::sanitize_profile( $body );

        if ( class_exists( 'Leo_DB' ) && method_exists( 'Leo_DB', 'save_user_profile' ) ) {
            $existing = Leo_DB::get_user_profile( $user_id, $blog_id );
            $current  = $existing ? ( $existing['profile'] ?? array() ) : array();
            $merged   = array_merge( $current, $patch );
            Leo_DB::save_user_profile( $user_id, $merged, $blog_id );
            return new WP_REST_Response( array( 'success' => true, 'profile' => $merged ), 200 );
        }

        $current = self::read_local( $user_id, $blog_id );
        $merged  = array_merge( $current, $patch );
        self::write_local( $user_id, $blog_id, $merged );
        return new WP_REST_Response( array( 'success' => true, 'profile' => $merged ), 200 );
    }

    /* ── Local subsite storage (usermeta, blog-suffixed for multisite isolation) ── */

    protected static function meta_key_for( $blog_id ) {
        return self::META_KEY . '_b' . (int) $blog_id;
    }

    protected static function read_local( $user_id, $blog_id ) {
        $raw = get_user_meta( $user_id, self::meta_key_for( $blog_id ), true );
        return is_array( $raw ) ? $raw : array();
    }

    protected static function write_local( $user_id, $blog_id, $profile ) {
        update_user_meta( $user_id, self::meta_key_for( $blog_id ), $profile );
    }

    /**
     * Same sanitization shape Leo_User_Profile_API uses — scalar values
     * are text-fielded; array values are mapped through sanitize_text_field.
     * Keys are normalized to sanitize_key form so callers can't inject
     * non-meta-safe characters into the stored blob.
     */
    protected static function sanitize_profile( array $data ) {
        $clean = array();
        foreach ( $data as $k => $v ) {
            $k = sanitize_key( $k );
            if ( ! $k ) continue;
            if ( is_array( $v ) ) {
                $clean[ $k ] = array_map( 'sanitize_text_field', array_map( 'strval', $v ) );
            } else {
                $clean[ $k ] = sanitize_text_field( (string) $v );
            }
        }
        return $clean;
    }
}

GS_User_Profile_Router::init();
